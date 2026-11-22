<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Zero Data Retention (ZDR) for OpenRouter.
 *
 * With the setting on, every request to OpenRouter carries provider.zdr = true, so OpenRouter only
 * routes it to endpoints that do not keep prompts or answers. The flag travels with the request: the
 * OpenRouter account itself is left alone, so other tools on the same key (a benchmark, say) are not
 * restricted.
 */

/** Settings key of the checkbox. */
const ZDR_SETTING = 'openrouter_zdr';

/** Transient: models for which OpenRouter had no ZDR endpoint (model => time). */
const ZDR_FAILURES_TRANSIENT = 'ai_translate_zdr_failures';

/** Option: the admin chose not to see the hint about ZDR again. */
const ZDR_NOTICE_DISMISSED = 'ai_translate_zdr_notice_dismissed';

/**
 * Whether a URL points at OpenRouter.
 */
function is_openrouter_url(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return $host === 'openrouter.ai' || substr($host, -14) === '.openrouter.ai';
}

/**
 * Whether the configured provider is OpenRouter (directly, or as the URL of a custom API).
 *
 * @param array $settings Plugin settings.
 */
function zdr_provider_is_openrouter(array $settings): bool
{
    $provider = (string) ($settings['api_provider'] ?? '');
    if ($provider === 'openrouter') {
        return true;
    }
    return $provider === 'custom' && is_openrouter_url((string) ($settings['custom_api_url'] ?? ''));
}

/**
 * Add provider.zdr to a request body for OpenRouter. Other endpoints, and an off setting, leave the
 * body as it is.
 *
 * @param string       $endpoint Full URL the request goes to.
 * @param string|array $body     Request body, as an array or as JSON.
 * @param bool         $force    Apply regardless of the stored setting (used to test the setting before it is saved).
 * @return string|array The body in the form it came in.
 */
function apply_zdr(string $endpoint, $body, bool $force = false)
{
    if (!is_openrouter_url($endpoint)) {
        return $body;
    }
    if (!$force && empty(AI_Translate_Core::settings()[ZDR_SETTING])) {
        return $body;
    }
    $is_json = is_string($body);
    $data = $is_json ? json_decode($body, true) : $body;
    if (!is_array($data)) {
        return $body;
    }
    $provider = isset($data['provider']) && is_array($data['provider']) ? $data['provider'] : [];
    $provider['zdr'] = true;
    $provider['data_collection'] = 'deny';
    $data['provider'] = $provider;
    return $is_json ? wp_json_encode($data) : $data;
}

/**
 * Whether an error body from OpenRouter says no endpoint matches the data policy.
 */
function is_zdr_error(string $text): bool
{
    return (bool) preg_match('/zero\s+data\s+retention|\bzdr\b|data\s+policy/i', $text);
}

/**
 * Remember (or forget) that OpenRouter has no ZDR endpoint for a model, from the response to a request
 * that carried the ZDR flag. The admin sees this in a notice, so translations that stop are not silent.
 *
 * @param array|\WP_Error $response Response of the request.
 * @param string          $endpoint Full URL the request went to.
 * @param string|array    $body     Request body that was sent.
 */
function note_zdr_result($response, string $endpoint, $body): void
{
    if (!is_openrouter_url($endpoint) || empty(AI_Translate_Core::settings()[ZDR_SETTING]) || is_wp_error($response)) {
        return;
    }
    $data = is_string($body) ? json_decode($body, true) : $body;
    $model = is_array($data) ? (string) ($data['model'] ?? '') : '';
    if ($model === '') {
        return;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $failures = get_transient(ZDR_FAILURES_TRANSIENT);
    $failures = is_array($failures) ? $failures : [];

    if ($code === 200) {
        if (isset($failures[$model])) {
            unset($failures[$model]);
            set_transient(ZDR_FAILURES_TRANSIENT, $failures, WEEK_IN_SECONDS);
        }
        return;
    }
    if ($code >= 400 && is_zdr_error((string) wp_remote_retrieve_body($response))) {
        $failures[$model] = time();
        set_transient(ZDR_FAILURES_TRANSIENT, $failures, WEEK_IN_SECONDS);
        error_log('AI Translate: OpenRouter has no zero-data-retention endpoint for model ' . $model . '; the request was not sent. Choose another model or switch off ZDR.');
    }
}

/**
 * Send a tiny request with the ZDR flag for the configured model, to learn whether it can be used.
 *
 * @param array $settings Settings as they would be saved.
 * @return string 'ok', 'unsupported' (OpenRouter has no ZDR endpoint for the model) or 'unverified'
 *                (no answer that says either way: network, key, balance, ...).
 */
function zdr_probe(array $settings): string
{
    $provider = (string) ($settings['api_provider'] ?? '');
    $custom = (string) ($settings['custom_api_url'] ?? '');
    $base = $provider === 'custom' ? $custom : AI_Translate_Core::get_api_url_for_provider($provider);
    $model = (string) ($settings['models'][$provider] ?? ($settings['selected_model'] ?? ''));
    $key = AI_Translate_Core::api_key_for($settings, $provider, $provider === 'custom' ? $custom : null);
    if ($base === '' || $model === '' || $key === '') {
        return 'unverified';
    }
    $model = AI_Translate_Core::effective_model($provider, $model);
    $endpoint = rtrim($base, '/') . '/chat/completions';
    $body = apply_zdr($endpoint, [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => 'Test']],
        'max_tokens' => 16,
    ], true);
    $headers = AI_Translate_Core::build_api_headers($key, $provider, ['custom_api_url' => $custom]);
    $response = AI_Translate_Core::remote_api_post($endpoint, $headers, $body, 20, false);
    if (is_wp_error($response)) {
        return 'unverified';
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code === 200) {
        return 'ok';
    }
    return $code >= 400 && is_zdr_error((string) wp_remote_retrieve_body($response)) ? 'unsupported' : 'unverified';
}
