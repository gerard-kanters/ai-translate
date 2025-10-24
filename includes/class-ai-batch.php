<?php

namespace AITranslate;

/**
 * Batch translation abstraction (bundelt segmenten binnen één request).
 */
final class AI_Batch
{
    /**
     * Translate a plan's segments using configured provider.
     *
     * @param array $plan
     * @param string|null $source
     * @param string $target
     * @param array $context
     * @return array
     */
    public static function translate_plan(array $plan, $source, $target, array $context)
    {
        $segments = $plan['segments'] ?? [];

        $settings = get_option('ai_translate_settings', []);
        $provider = isset($settings['api_provider']) ? (string)$settings['api_provider'] : '';
        $models = isset($settings['models']) && is_array($settings['models']) ? $settings['models'] : [];
        $model = $provider !== '' ? ($models[$provider] ?? '') : '';
        $apiKeys = isset($settings['api_keys']) && is_array($settings['api_keys']) ? $settings['api_keys'] : [];
        $apiKey = $provider !== '' ? ($apiKeys[$provider] ?? '') : '';

        \ai_translate_dbg('translate_plan_begin', [
            'provider' => $provider ?: 'none',
            'model' => $model ?: 'none',
            'segment_count' => is_array($segments) ? count($segments) : 0,
        ]);
        if (empty($segments)) {
            \ai_translate_dbg('translate_plan_no_segments', [ 'provider' => $provider ?: 'none' ]);
            return ['segments' => [], 'map' => []];
        }

        if ($provider === '' || $model === '' || $apiKey === '') {
            \ai_translate_dbg('translate_plan_skipped_misconfigured', [
                'provider' => $provider ?: 'none',
                'has_model' => $model !== '',
                'has_key' => $apiKey !== '',
            ]);
            return ['segments' => [], 'map' => []];
        }

        $baseUrl = \AITranslate\AI_Translate_Core::get_api_url_for_provider($provider);
        if ($provider === 'custom') {
            $baseUrl = isset($settings['custom_api_url']) ? (string)$settings['custom_api_url'] : '';
        }
        if ($baseUrl === '') {
            \ai_translate_dbg('translate_plan_no_base_url', [ 'provider' => $provider ]);
            return ['segments' => [], 'map' => []];
        }

        // Build prompt with minimal deterministic context
        $system = self::buildSystemPrompt($source, $target, $context);
        $endpoint = rtrim($baseUrl, '/') . '/chat/completions';

        // Provider-specifieke chunking om timeouts te voorkomen
        $chunkSize = ($provider === 'deepseek') ? 40 : count($segments);
        $batches = ($chunkSize < count($segments)) ? array_chunk($segments, $chunkSize) : [ $segments ];

        $translations = [];
        foreach ($batches as $i => $batchSegs) {
            \ai_translate_dbg('translate_plan_chunk', [
                'provider' => $provider,
                'chunk_index' => $i + 1,
                'chunk_size' => count($batchSegs),
                'total' => count($segments),
            ]);

            $userPayload = self::buildUserPayload($batchSegs);
            $body = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userPayload],
                ],
                'temperature' => 0,
            ];
            if ($provider !== 'deepseek') {
                $body['response_format'] = [ 'type' => 'json_object' ];
            }

            $timeoutSeconds = ($provider === 'deepseek') ? 60 : 30;
            $response = wp_remote_post($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => $timeoutSeconds,
                'connect_timeout' => 15,
                'sslverify' => true,
                'body' => wp_json_encode($body),
            ]);

            if (is_wp_error($response)) {
                \ai_translate_dbg('translate_plan_http_error', [
                    'provider' => $provider,
                    'endpoint' => $endpoint,
                    'error' => $response->get_error_message(),
                ]);
                // Stoppen bij fout; lever tot nu toe verzamelde vertalingen terug
                return ['segments' => $translations, 'map' => []];
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $bodyText = (string) wp_remote_retrieve_body($response);
                \ai_translate_dbg('translate_plan_http_non200', [
                    'provider' => $provider,
                    'status' => $code,
                    'body_preview' => mb_substr($bodyText, 0, 400),
                ]);
                return ['segments' => $translations, 'map' => []];
            }

            $respBody = (string) wp_remote_retrieve_body($response);
            $data = json_decode($respBody, true);
            $choices = is_array($data) ? ($data['choices'] ?? []) : [];
            if (!$choices || !isset($choices[0]['message']['content'])) {
                \ai_translate_dbg('translate_plan_no_choices_or_content', [
                    'provider' => $provider,
                    'has_choices' => !empty($choices),
                ]);
                return ['segments' => $translations, 'map' => []];
            }
            $content = (string) $choices[0]['message']['content'];
            if ($provider === 'deepseek') {
                \ai_translate_dbg('translate_plan_body_preview', [
                    'provider' => $provider,
                    'content_preview' => mb_substr($content, 0, 400),
                ]);
            }
            $parsed = json_decode($content, true);
            if (!is_array($parsed) || !isset($parsed['translations']) || !is_array($parsed['translations'])) {
                $candidate = $content;
                $candidate = str_replace(["```json", "```JSON", "```"], '', $candidate);
                $start = strpos($candidate, '{');
                $end = strrpos($candidate, '}');
                if ($start !== false && $end !== false && $end >= $start) {
                    $candidate = substr($candidate, $start, $end - $start + 1);
                }
                $parsed = json_decode(trim($candidate), true);
            }
            if (!is_array($parsed) || !isset($parsed['translations']) || !is_array($parsed['translations'])) {
                \ai_translate_dbg('translate_plan_parse_failed', [
                    'provider' => $provider,
                    'content_preview' => mb_substr($content, 0, 400),
                ]);
                return ['segments' => $translations, 'map' => []];
            }
            foreach ($parsed['translations'] as $k => $v) {
                $translations[(string) $k] = (string) $v;
            }
            \ai_translate_dbg('translate_plan_success', [
                'provider' => $provider,
                'translated_count' => count($parsed['translations']),
            ]);
        }

        return ['segments' => $translations, 'map' => []];
    }

    private static function buildSystemPrompt($source, $target, array $ctx)
    {
        return \AITranslate\AI_Translate_Core::build_translation_system_prompt($source, $target, $ctx);
    }

    private static function buildUserPayload(array $segments)
    {
        $payload = [ 'segments' => [] ];
        foreach ($segments as $seg) {
            $payload['segments'][] = [
                'id' => (string) $seg['id'],
                'text' => (string) $seg['text'],
                'type' => (string) $seg['type'],
                'attr' => isset($seg['attr']) ? (string) $seg['attr'] : null,
            ];
        }
        $payload['instruction'] = 'Return JSON: {"translations": {"<id>": "<translated>"}}. No extra text.';
        return wp_json_encode($payload);
    }
}


