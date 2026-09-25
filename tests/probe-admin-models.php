<?php
/**
 * Probe every model the admin dropdown would show.
 *
 * Uses the saved API keys. Does not change the active model.
 * Each model goes through the same check as choosing it in the admin
 * (validate_api_settings): chat test plus temperature 0.
 *
 *   wp --path=/var/www/netcare.nl eval-file tests/probe-admin-models.php list
 *   wp --path=/var/www/netcare.nl eval-file tests/probe-admin-models.php deepseek
 *   wp --path=/var/www/netcare.nl eval-file tests/probe-admin-models.php all
 *
 * @package AITranslate
 */

if (!defined('ABSPATH')) {
    fwrite(STDERR, "Run via wp eval-file.\n");
    exit(1);
}

$mode = isset($args[0]) ? (string) $args[0] : 'list';
$settings = AITranslate\AI_Translate_Core::settings(true);
$providers = AITranslate\AI_Translate_Core::get_api_providers();
$wanted = ($mode === 'list' || $mode === 'all') ? array_keys($providers) : [$mode];

$fail = 0;
foreach ($wanted as $provider) {
    if (!isset($providers[$provider])) {
        echo "unknown provider {$provider}\n";
        $fail++;
        continue;
    }
    $key = (string) ($settings['api_keys'][$provider] ?? '');
    $base = $provider === 'custom'
        ? (string) ($settings['custom_api_url'] ?? '')
        : (string) $providers[$provider]['base_url'];
    if ($key === '' || $base === '') {
        echo "{$provider}\tskip\tno key or url\n";
        continue;
    }

    $headers = AITranslate\AI_Translate_Core::build_api_headers($key, $provider, $settings);
    $resp = wp_remote_get(rtrim($base, '/') . '/models', ['headers' => $headers, 'timeout' => 20]);
    if (is_wp_error($resp)) {
        echo "{$provider}\tlist-fail\t" . $resp->get_error_message() . "\n";
        $fail++;
        continue;
    }
    $code = (int) wp_remote_retrieve_response_code($resp);
    $data = json_decode((string) wp_remote_retrieve_body($resp), true);
    if ($code !== 200 || !is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        echo "{$provider}\tlist-fail\tHTTP {$code}\n";
        $fail++;
        continue;
    }

    $models = AITranslate\AI_Translate_Core::filter_admin_models($data['data'], $provider);
    echo "{$provider}\t" . count($models) . " models\n";
    if ($mode === 'list') {
        foreach ($models as $model) {
            echo "{$provider}\t{$model}\n";
        }
        continue;
    }

    $core = AITranslate\AI_Translate_Core::get_instance();
    $custom = $provider === 'custom' ? $base : '';
    foreach ($models as $model) {
        $t0 = microtime(true);
        try {
            $result = $core->validate_api_settings($provider, $key, $custom, $model);
            $ms = (int) round((microtime(true) - $t0) * 1000);
            if (!isset($result['temperature_supported'])) {
                $temp = 'temperature-unknown';
            } elseif ($result['temperature_supported']) {
                $temp = 'temperature-ok';
            } else {
                $temp = 'temperature-rejected';
            }
            echo "{$provider}\t{$model}\tOK\t{$ms}ms\t{$temp}\n";
        } catch (\Throwable $e) {
            $ms = (int) round((microtime(true) - $t0) * 1000);
            $msg = preg_replace('/\s+/', ' ', $e->getMessage());
            echo "{$provider}\t{$model}\tFAIL\t{$ms}ms\t" . substr($msg, 0, 220) . "\n";
            $fail++;
        }
        if (function_exists('flush')) {
            flush();
        }
    }
}

exit($fail > 0 ? 1 : 0);
