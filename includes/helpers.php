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
 * Count hits against a limit within a time window. The increment is atomic: on the cache server
 * when a persistent object cache is present, otherwise in one SQL statement on the options table.
 * Only when neither works does it fall back to a transient, which two simultaneous requests can
 * both read before either writes. A call over the limit is counted as well.
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
    $count = rate_limit_count_in_database($key, $amount, (int) $window);
    if ($count !== null) {
        return $count <= (int) $limit;
    }
    // No usable database: a transient, which two simultaneous requests can both read before either writes
    $count = (int) get_transient($key);
    if ($count + $amount > (int) $limit) {
        return false;
    }
    set_transient($key, $count + $amount, (int) $window);
    return true;
}

/**
 * Raise a counter in the options table and return its new value. The increment is one SQL statement,
 * so simultaneous requests are all counted. The row belongs to one window: its name ends in the time at
 * which the window ends, and rows of finished windows are removed now and then.
 *
 * @return int|null The counter after this call, or null when the database cannot be used.
 */
function rate_limit_count_in_database($key, $amount, $window)
{
    global $wpdb;
    if (!is_object($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'query')) {
        return null;
    }
    $now = time();
    $name = $key . '_' . ((int) floor($now / max(1, $window)) + 1) * max(1, $window);

    // LAST_INSERT_ID(expr) keeps the value this statement produced for this connection, so the count is
    // exactly the one after our own increment, not one that others have raised in the meantime.
    $done = $wpdb->query($wpdb->prepare(
        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no') ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(option_value + %d)",
        $name,
        $amount,
        $amount
    ));
    if ($done === false) {
        return null;
    }
    // MySQL reports 1 for a new row (the count is our amount) and 2 for an updated one
    $count = (int) $done === 1 ? $amount : $wpdb->get_var('SELECT LAST_INSERT_ID()');
    if ($count === null || (int) $count < 1) {
        return null;
    }

    // At most every five minutes: remove the rows of windows that have ended
    if (get_transient('ai_rl_cleanup') === false) {
        set_transient('ai_rl_cleanup', 1, 300);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_name, '_', -1) AS UNSIGNED) < %d",
            $wpdb->esc_like('ai_rl_') . '%',
            $now
        ));
    }
    return (int) $count;
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
