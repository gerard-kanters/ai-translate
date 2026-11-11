<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
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
