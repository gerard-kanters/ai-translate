<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/** Public copy of includes/default-models.json that the plugin checks for newer preferred models. */
const DEFAULT_MODELS_URL = 'https://raw.githubusercontent.com/gerard-kanters/ai-translate/master/includes/default-models.json';

/** Option with the downloaded list: ['lists' => provider => string[], 'updated' => string, 'checked' => int]. */
const DEFAULT_MODELS_OPTION = 'ai_translate_default_models';

/** The downloaded list is checked at most this often. */
const DEFAULT_MODELS_CHECK_INTERVAL = 7 * DAY_IN_SECONDS;

/**
 * Keep only plain lists of model IDs per provider; anything else is dropped.
 *
 * @param mixed $providers
 * @return array<string,string[]>
 */
function clean_default_model_lists($providers): array
{
    $lists = [];
    if (!is_array($providers)) {
        return $lists;
    }
    foreach ($providers as $provider => $ids) {
        if (!is_string($provider) || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $provider) || !is_array($ids)) {
            continue;
        }
        $clean = [];
        foreach (array_slice($ids, 0, 10) as $id) {
            if (is_string($id) && preg_match('#^[A-Za-z0-9][A-Za-z0-9._:/-]{0,127}$#', $id)) {
                $clean[] = $id;
            }
        }
        if ($clean !== []) {
            $lists[$provider] = $clean;
        }
    }
    return $lists;
}

/**
 * Read a default-models JSON document: ['lists' => ..., 'updated' => 'YYYY-MM-DD'].
 *
 * @return array{lists: array<string,string[]>, updated: string}
 */
function parse_default_models(string $json): array
{
    $data = json_decode($json, true);
    $updated = is_array($data) && isset($data['updated']) && is_string($data['updated']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['updated']) ? $data['updated'] : '';
    return [
        'lists' => is_array($data) ? clean_default_model_lists($data['providers'] ?? null) : [],
        'updated' => $updated,
    ];
}

/**
 * The bundled document of this plugin version.
 *
 * @return array{lists: array<string,string[]>, updated: string}
 */
function bundled_default_models(?string $file = null): array
{
    static $cached = null;
    if ($file === null && $cached !== null) {
        return $cached;
    }
    $path = $file ?? __DIR__ . '/default-models.json';
    $doc = parse_default_models(is_readable($path) ? (string) file_get_contents($path) : '');
    if ($file === null) {
        $cached = $doc;
    }
    return $doc;
}

/**
 * Preferred translation models per provider, best first. The list downloaded from GitHub wins per
 * provider, unless the plugin itself carries a newer list. Without a download the bundled list is used.
 *
 * @return array<string,string[]>
 */
function default_model_lists(?string $file = null): array
{
    $bundled = bundled_default_models($file);
    if ($file !== null || !function_exists('get_option')) {
        return $bundled['lists'];
    }
    $stored = get_option(DEFAULT_MODELS_OPTION, []);
    if (!is_array($stored) || !isset($stored['lists'], $stored['updated']) || !is_string($stored['updated'])
        || $stored['updated'] === '' || strcmp($stored['updated'], $bundled['updated']) < 0) {
        return $bundled['lists'];
    }
    return array_merge($bundled['lists'], clean_default_model_lists($stored['lists']));
}

/**
 * Download the preferred-model list from GitHub, at most once a week. A failed or invalid download keeps
 * whatever was there. Only a public file is requested: no site or user data is sent. The URL can be
 * changed or emptied (which switches the download off) with the ai_translate_default_models_url filter.
 */
function refresh_default_models(): void
{
    $url = (string) apply_filters('ai_translate_default_models_url', DEFAULT_MODELS_URL);
    if ($url === '' || !function_exists('wp_remote_get')) {
        return;
    }
    $stored = get_option(DEFAULT_MODELS_OPTION, []);
    $stored = is_array($stored) ? $stored : [];
    if (time() - (int) ($stored['checked'] ?? 0) < DEFAULT_MODELS_CHECK_INTERVAL) {
        return;
    }
    $stored['checked'] = time(); // Also counts failed attempts, so an unreachable GitHub is not hammered.
    $resp = wp_remote_get($url, ['timeout' => 10, 'sslverify' => true, 'limit_response_size' => 65536]);
    if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
        $doc = parse_default_models((string) wp_remote_retrieve_body($resp));
        if ($doc['lists'] !== [] && $doc['updated'] !== '') {
            $stored['lists'] = $doc['lists'];
            $stored['updated'] = $doc['updated'];
        }
    }
    update_option(DEFAULT_MODELS_OPTION, $stored, false);
}

/** Option holding the last model list each provider returned: provider => ['ids' => string[], 'ts' => int]. */
const LIVE_MODELS_OPTION = 'ai_translate_live_models';

/** A stored list older than this is no longer trusted (the refresh stopped working: assume nothing). */
const LIVE_MODELS_MAX_AGE = 7 * DAY_IN_SECONDS;

/**
 * Remember the unfiltered model IDs a provider just returned from /models.
 *
 * @param array $rawModels Rows (or IDs) from the /models response.
 */
function store_live_models(string $provider, array $rawModels): void
{
    $ids = [];
    foreach ($rawModels as $m) {
        $id = is_array($m) ? ($m['id'] ?? null) : $m;
        if (is_string($id) && $id !== '') {
            $ids[] = $id;
        }
    }
    // An empty list says nothing about which models exist: keep the previous one.
    if ($ids === []) {
        return;
    }
    $all = get_option(LIVE_MODELS_OPTION, []);
    $all = is_array($all) ? $all : [];
    $all[$provider] = ['ids' => array_values(array_unique($ids)), 'ts' => time()];
    update_option(LIVE_MODELS_OPTION, $all, false);
}

/**
 * Whether the provider's own model list (fresh, from the last refresh) no longer contains the model.
 * False when there is no usable list, so a missing or stale list never blocks a working model.
 */
function model_is_retired(string $provider, string $model): bool
{
    if ($model === '') {
        return false;
    }
    $all = get_option(LIVE_MODELS_OPTION, []);
    $entry = is_array($all) && isset($all[$provider]) && is_array($all[$provider]) ? $all[$provider] : null;
    if ($entry === null || empty($entry['ids']) || !is_array($entry['ids']) || (time() - (int) ($entry['ts'] ?? 0)) > LIVE_MODELS_MAX_AGE) {
        return false;
    }
    return !in_array($model, $entry['ids'], true);
}
