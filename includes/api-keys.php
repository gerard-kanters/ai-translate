<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/** Lowercase host of an API URL ('' when there is none). */
function api_host(string $url): string
{
    $url = trim($url);
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '' && $url !== '' && strpos($url, '://') === false) {
        $host = (string) parse_url('https://' . $url, PHP_URL_HOST);
    }
    return strtolower($host);
}

/** Where the key of a provider is stored: custom APIs get one key per host. */
function api_key_slot(string $provider, string $customUrl = ''): string
{
    if ($provider !== 'custom') {
        return $provider;
    }
    $host = api_host($customUrl);
    return $host === '' ? 'custom' : 'custom@' . $host;
}

/**
 * The saved API key for a provider. For a custom API that is the key of its own host; a key saved
 * before there was one per host is used as long as no host has its own key.
 *
 * @param array       $settings  Plugin settings.
 * @param string      $provider  Provider key.
 * @param string|null $customUrl Custom API URL (default: the saved one).
 */
function api_key_for(array $settings, string $provider, ?string $customUrl = null): string
{
    $keys = isset($settings['api_keys']) && is_array($settings['api_keys']) ? $settings['api_keys'] : [];
    if ($provider !== 'custom') {
        return (string) ($keys[$provider] ?? '');
    }
    $url = $customUrl ?? (string) ($settings['custom_api_url'] ?? '');
    $slot = api_key_slot('custom', $url);
    if ($slot !== 'custom' && isset($keys[$slot])) {
        return (string) $keys[$slot];
    }
    foreach ($keys as $name => $unused) {
        if (strpos((string) $name, 'custom@') === 0) {
            return ''; // hosts have their own keys: the old shared one belongs to no host any more
        }
    }
    return (string) ($keys['custom'] ?? '');
}

/**
 * Store a key. For a custom API the key goes to its host; a key from before there was one per host
 * moves to the host it was used with.
 *
 * @param array  $settings  Settings to change.
 * @param string $provider  Provider key.
 * @param string $key       The key.
 * @param string $customUrl Custom API URL the key belongs to.
 */
function store_api_key(array &$settings, string $provider, string $key, string $customUrl = ''): void
{
    if (!isset($settings['api_keys']) || !is_array($settings['api_keys'])) {
        $settings['api_keys'] = [];
    }
    if ($provider !== 'custom' || api_host($customUrl) === '') {
        $settings['api_keys'][$provider] = $key;
        return;
    }
    $oldHost = api_host((string) ($settings['custom_api_url'] ?? ''));
    if ($oldHost !== '' && !empty($settings['api_keys']['custom']) && !isset($settings['api_keys']['custom@' . $oldHost])) {
        $settings['api_keys']['custom@' . $oldHost] = $settings['api_keys']['custom'];
    }
    unset($settings['api_keys']['custom']);
    $settings['api_keys'][api_key_slot('custom', $customUrl)] = $key;
}
