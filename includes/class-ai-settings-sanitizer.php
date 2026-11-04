<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sanitizer for the ai_translate_settings option (the sanitize_callback of register_setting).
 *
 * Input comes in three forms, which differ in how a missing key is read:
 * - the settings form (carries _form_submit=1): all fields are posted; an absent checkbox means
 *   unchecked, and the cache duration is in days;
 * - AJAX and internal updates: only the keys that change are present, everything else is kept;
 * - values the plugin writes itself (probe results).
 * The starting point is always the stored settings, so keys this class does not know survive.
 */
final class AI_Settings_Sanitizer
{
    /** Shortest and longest cache duration, in hours. */
    private const MIN_CACHE_HOURS = 14 * 24;
    private const MAX_CACHE_HOURS = 365 * 24;

    private const SWITCHER_POSITIONS = ['bottom-left', 'bottom-right', 'top-left', 'top-right', 'none'];

    /** Checkbox settings and their value when never saved. */
    private const CHECKBOXES = [
        'auto_clear_pages_on_menu_update' => true,
        'stop_translations_except_cache_invalidation' => false,
        'prewarm_on_publish' => false,
        'multi_domain_caching' => false,
        'keep_slugs_in_english' => false,
        'woocommerce_keep_product_names' => false,
    ];

    /**
     * @param mixed $input Posted or passed settings.
     * @return array The settings to store.
     */
    public static function sanitize($input): array
    {
        $input = is_array($input) ? $input : [];
        $current = AI_Translate_Core::settings();
        $current = is_array($current) ? $current : [];
        $s = $current;
        $is_form = isset($input['_form_submit']) && $input['_form_submit'] === '1';

        foreach (['api_keys', 'models'] as $list) {
            if (!isset($s[$list]) || !is_array($s[$list])) {
                $s[$list] = [];
            }
        }
        foreach (['api_keys', 'models'] as $list) {
            if (isset($input[$list]) && is_array($input[$list])) {
                foreach ($input[$list] as $provider => $value) {
                    $s[$list][$provider] = sanitize_text_field($value);
                }
            }
        }

        self::provider($s, $input);
        self::custom_api_url($s, $input);
        self::cache_expiration($s, $input, $is_form);
        foreach (self::CHECKBOXES as $key => $default) {
            self::checkbox($s, $input, $key, $is_form, $default);
        }
        if (array_key_exists('glossary', $input)) {
            $s['glossary'] = sanitize_textarea_field((string) $input['glossary']);
        } elseif (!isset($s['glossary'])) {
            $s['glossary'] = '';
        }
        self::model($s, $input, $current);
        self::api_key($s, $input, $current);
        self::texts_per_domain($s, $input);
        self::languages($s, $input, $is_form);
        self::switcher_position($s, $input);

        // Results of the model probes (temperature, reasoning parameters). The plugin writes them
        // itself when a model is activated; the settings form never posts them.
        foreach (['temperature_supported', 'reasoning_params'] as $probe_key) {
            if (isset($input[$probe_key]) && is_array($input[$probe_key])) {
                $s[$probe_key] = $input[$probe_key];
            }
        }

        return $s;
    }

    /**
     * Host of the site being administered, without port; used as key for per-domain texts.
     */
    public static function active_domain(): string
    {
        $domain = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $domain = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
            if (strpos($domain, ':') !== false) {
                $domain = (string) strtok($domain, ':');
            }
        }
        if ($domain === '' && !empty($_SERVER['SERVER_NAME'])) {
            $domain = sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME']));
        }
        if ($domain === '') {
            $domain = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        }
        return $domain !== '' ? $domain : 'default';
    }

    private static function error(string $code, string $message, string $type = 'error'): void
    {
        add_settings_error('ai_translate_settings', $code, $message, $type);
    }

    private static function provider(array &$s, array $input): void
    {
        if (!isset($input['api_provider'])) {
            return;
        }
        $provider = sanitize_text_field($input['api_provider']);
        if (in_array($provider, array_keys(AI_Translate_Core::get_api_providers()), true)) {
            $s['api_provider'] = $provider;
        } else {
            self::error('invalid_api_provider', __('Invalid API provider selected.', 'ai-translate'));
        }
    }

    private static function custom_api_url(array &$s, array $input): void
    {
        if (isset($input['custom_api_url'])) {
            $raw = esc_url_raw(trim($input['custom_api_url']));
            // Strip endpoint paths the plugin appends itself.
            $stripped = preg_replace('#/(chat/completions|responses)/?$#i', '', $raw);
            if ($stripped !== $raw) {
                self::error(
                    'custom_url_auto_corrected',
                    sprintf(
                        /* translators: %s: corrected base URL */
                        __('The custom API URL was automatically corrected to the base URL: %s (the plugin appends /chat/completions automatically).', 'ai-translate'),
                        esc_html($stripped)
                    ),
                    'updated'
                );
            }
            $s['custom_api_url'] = $stripped;
        }
        if (($s['api_provider'] ?? null) !== 'custom') {
            return;
        }
        if (empty($s['custom_api_url'])) {
            self::error('custom_url_required', __('Custom API URL is required for the custom provider.', 'ai-translate'));
        } elseif (strpos($s['custom_api_url'], 'googleapis.com') !== false) {
            self::error('custom_url_not_openai_compatible', __('Google API is not OpenAI compatible. Use Openrouter or Deepinfra for Gemini.', 'ai-translate'));
        }
    }

    /**
     * Cache duration in hours. The form posts days (minimum 14); other callers pass hours.
     * A stored value outside 14 to 365 days is reset to 14 days.
     */
    private static function cache_expiration(array &$s, array $input, bool $is_form): void
    {
        if ($is_form && isset($input['cache_expiration'])) {
            $days = intval($input['cache_expiration']);
            if ($days < 14) {
                self::error(
                    'cache_duration_too_low',
                    __('Cache duration cannot be less than 14 days. It has been automatically set to 14 days.', 'ai-translate'),
                    'warning'
                );
                $days = 14;
            }
            $s['cache_expiration'] = $days * 24;
        } elseif (array_key_exists('cache_expiration', $input)) {
            $s['cache_expiration'] = (int) $input['cache_expiration'];
        } else {
            $existing = isset($s['cache_expiration']) ? (int) $s['cache_expiration'] : null;
            if ($existing === null || $existing < self::MIN_CACHE_HOURS || $existing > self::MAX_CACHE_HOURS) {
                $s['cache_expiration'] = self::MIN_CACHE_HOURS;
            }
        }
        if (isset($s['cache_expiration']) && $s['cache_expiration'] > 50000) {
            $s['cache_expiration'] = self::MIN_CACHE_HOURS;
        }
    }

    /**
     * A checkbox: from the form, absent means off; from AJAX, only changed when present; when
     * never saved, the default.
     */
    private static function checkbox(array &$s, array $input, string $key, bool $is_form, bool $default): void
    {
        if ($is_form) {
            $s[$key] = isset($input[$key]);
        } elseif (array_key_exists($key, $input)) {
            $s[$key] = (bool) $input[$key];
        } elseif (!isset($s[$key])) {
            $s[$key] = $default;
        }
    }

    private static function model(array &$s, array $input, array $current): void
    {
        if (!isset($input['selected_model'])) {
            return;
        }
        $provider = $s['api_provider'] ?? ($current['api_provider'] ?? null);
        $model = null;
        if ($input['selected_model'] === 'custom') {
            if (!empty($input['custom_model'])) {
                $model = trim($input['custom_model']);
            } else {
                self::error('custom_model_required', __('Custom model name is required when selecting a custom model.', 'ai-translate'));
            }
        } else {
            $model = trim(sanitize_text_field($input['selected_model']));
        }
        if ($provider && $model !== null) {
            $s['models'][$provider] = $model;
            $s['selected_model'] = $model;
        }
    }

    /**
     * The key of the selected provider. The mask the browser shows in place of the key leaves the
     * stored key as it is.
     */
    private static function api_key(array &$s, array $input, array $current): void
    {
        if (!isset($input['api_key']) || trim((string) $input['api_key']) === KEY_MASK) {
            return;
        }
        $provider = $s['api_provider'] ?? ($input['api_provider'] ?? ($current['api_provider'] ?? null));
        if (!$provider) {
            return;
        }
        $key = trim(sanitize_text_field($input['api_key']));
        $s['api_keys'][$provider] = $key;
        if ($key === '') {
            self::error('api_key_missing', __('API key is required for the selected provider.', 'ai-translate'));
        }
    }

    /**
     * Homepage meta description and website context: one text, or one per domain with multi-domain
     * caching. "website_context_per_domain" is also accepted whole, from AJAX.
     */
    private static function texts_per_domain(array &$s, array $input): void
    {
        $multi_domain = !empty($s['multi_domain_caching']);

        if (isset($input['homepage_meta_description'])) {
            self::domain_text($s, $input['homepage_meta_description'], 'homepage_meta_description', $multi_domain);
        }
        if (isset($input['website_context_per_domain']) && is_array($input['website_context_per_domain'])) {
            $s['website_context_per_domain'] = [];
            foreach ($input['website_context_per_domain'] as $domain => $context) {
                $s['website_context_per_domain'][sanitize_text_field($domain)] = sanitize_textarea_field($context);
            }
        }
        if (isset($input['website_context'])) {
            self::domain_text($s, $input['website_context'], 'website_context', $multi_domain);
        }
    }

    private static function domain_text(array &$s, $value, string $key, bool $multi_domain): void
    {
        $value = sanitize_textarea_field($value);
        if (!$multi_domain) {
            $s[$key] = $value;
            return;
        }
        $per_domain = $key . '_per_domain';
        if (!isset($s[$per_domain]) || !is_array($s[$per_domain])) {
            $s[$per_domain] = [];
        }
        $s[$per_domain][self::active_domain()] = $value;
    }

    /**
     * Default, enabled and detectable languages. From the form, a missing list means none checked;
     * from AJAX, a list is only replaced when it is present.
     */
    private static function languages(array &$s, array $input, bool $is_form): void
    {
        if (isset($input['default_language'])) {
            $s['default_language'] = sanitize_text_field($input['default_language']);
        }
        foreach (['enabled_languages', 'detectable_languages'] as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $s[$key] = array_values(array_unique(array_map('sanitize_text_field', $input[$key])));
            } elseif ($is_form) {
                $s[$key] = [];
            }
        }
    }

    private static function switcher_position(array &$s, array $input): void
    {
        if (isset($input['switcher_position'])) {
            $position = sanitize_text_field($input['switcher_position']);
            $s['switcher_position'] = in_array($position, self::SWITCHER_POSITIONS, true) ? $position : 'bottom-left';
        } elseif (!isset($s['switcher_position'])) {
            $s['switcher_position'] = 'bottom-left';
        }
    }
}
