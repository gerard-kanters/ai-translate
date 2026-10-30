<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Shown in the API key field in place of a saved key. The real key never goes to the browser:
 * the AJAX handlers swap this value for the key stored for the selected provider, and saving the
 * form with this value left in the field keeps the stored key.
 */
const KEY_MASK = '••••••••••••';

/**
 * Small helpers that guard public and admin entry points. Kept apart from the files that
 * register hooks so that they can be unit tested.
 */

/**
 * Whether a path points to something inside a base directory, after resolving ".." and symlinks.
 * False when either path does not exist.
 *
 * @param string $path Path to check (a file or directory that exists).
 * @param string $base Base directory.
 * @return bool
 */
function path_is_inside($path, $base)
{
    $real_path = realpath((string) $path);
    $real_base = realpath((string) $base);
    if ($real_path === false || $real_base === false) {
        return false;
    }
    $real_base = rtrim(wp_normalize_path($real_base), '/') . '/';
    return strpos(wp_normalize_path($real_path), $real_base) === 0;
}

/**
 * Count hits against a limit within a time window. Atomic when a persistent object cache is
 * present (increment on the cache server); otherwise it falls back to a transient, which two
 * simultaneous requests can both read before either writes.
 *
 * @param string $bucket Name of the counter (a hash of it is used as key).
 * @param int    $limit  Maximum total within the window.
 * @param int    $window Window in seconds.
 * @param int    $amount How much this call adds.
 * @return bool True while the counter stays within the limit.
 */
function rate_limit_hit($bucket, $limit, $window, $amount = 1)
{
    $key = 'ai_rl_' . md5((string) $bucket);
    $amount = max(1, (int) $amount);
    if (wp_using_ext_object_cache()) {
        // Increment first: some cache drop-ins let add() overwrite an existing key, which would reset the counter.
        $count = wp_cache_incr($key, $amount, 'ai_translate');
        if ($count === false) {
            if (wp_cache_add($key, $amount, 'ai_translate', (int) $window)) {
                return $amount <= (int) $limit;
            }
            $count = wp_cache_incr($key, $amount, 'ai_translate');
        }
        if ($count !== false) {
            return (int) $count <= (int) $limit;
        }
    }
    $count = (int) get_transient($key);
    if ($count + $amount > (int) $limit) {
        return false;
    }
    set_transient($key, $count + $amount, (int) $window);
    return true;
}

/**
 * Whether a language code is one this site offers: the default language and the languages the
 * site has enabled or detects. Language codes come from URLs and request parameters, so anything
 * that spends translation credits for a language must check this first.
 *
 * @param string $lang Language code from the request.
 * @return bool
 */
function lang_is_offered($lang)
{
    $lang = strtolower(trim((string) $lang));
    if ($lang === '') {
        return false;
    }
    $allowed = array_merge(
        [(string) AI_Lang::default()],
        (array) AI_Lang::enabled(),
        (array) AI_Lang::detectable()
    );
    foreach ($allowed as $code) {
        if ($lang === strtolower((string) $code)) {
            return true;
        }
    }
    return false;
}
