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
        if (empty($segments)) {
            return ['segments' => [], 'map' => []];
        }

        $settings = get_option('ai_translate_settings', []);
        $provider = isset($settings['api_provider']) ? (string)$settings['api_provider'] : '';
        $models = isset($settings['models']) && is_array($settings['models']) ? $settings['models'] : [];
        $model = $provider !== '' ? ($models[$provider] ?? '') : '';
        $apiKeys = isset($settings['api_keys']) && is_array($settings['api_keys']) ? $settings['api_keys'] : [];
        $apiKey = $provider !== '' ? ($apiKeys[$provider] ?? '') : '';

        if ($provider === '' || $model === '' || $apiKey === '') {
            // Misconfiguratie: geen vertaling uitvoeren.
            return ['segments' => [], 'map' => []];
        }

        $baseUrl = \AITranslate\AI_Translate_Core::get_api_url_for_provider($provider);
        if ($provider === 'custom') {
            $baseUrl = isset($settings['custom_api_url']) ? (string)$settings['custom_api_url'] : '';
        }
        if ($baseUrl === '') {
            return ['segments' => [], 'map' => []];
        }

        // Build prompt with minimal deterministic context
        $system = self::buildSystemPrompt($source, $target, $context);
        $userPayload = self::buildUserPayload($segments);

        $endpoint = rtrim($baseUrl, '/') . '/chat/completions';
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
            'sslverify' => true,
            'body' => wp_json_encode([
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $userPayload],
                ],
                'temperature' => 0,
                'response_format' => [ 'type' => 'json_object' ],
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['segments' => [], 'map' => []];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return ['segments' => [], 'map' => []];
        }
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $choices = is_array($data) ? ($data['choices'] ?? []) : [];
        if (!$choices || !isset($choices[0]['message']['content'])) {
            return ['segments' => [], 'map' => []];
        }
        $content = (string) $choices[0]['message']['content'];
        $parsed = json_decode($content, true);
        if (!is_array($parsed) || !isset($parsed['translations']) || !is_array($parsed['translations'])) {
            return ['segments' => [], 'map' => []];
        }

        // Expect: { translations: { id => text, ... } } and PRESERVE KEYS
        $translations = [];
        foreach ($parsed['translations'] as $k => $v) {
            $translations[(string) $k] = (string) $v;
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


