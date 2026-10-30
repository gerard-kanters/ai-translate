<?php

/**
 * Attribute-translation transients (memcached plus database) and their cache keys.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get transient with database fallback for ai_tr_attr_ keys.
 * First tries memcached (via get_transient), then falls back to database.
 *
 * @param string $key Transient key
 * @return mixed|false Transient value or false if not found
 */
function ai_translate_get_attr_transient($key)
{
    // First try memcached (via standard WordPress transient API)
    $value = get_transient($key);
    if ($value !== false) {
        return $value;
    }
    
    // Fallback to database: check if transient exists in wp_options
    global $wpdb;
    $transient_key = '_transient_' . $key;
    $timeout_key = '_transient_timeout_' . $key;
    
    // Check if transient exists and hasn't expired
    $timeout = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        $timeout_key
    ));
    
    if ($timeout !== null) {
        // Check if expired
        if ((int) $timeout > time()) {
            // Not expired, get value from database
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $transient_key
            ));
            if ($value !== null) {
                // Restore to memcached for faster access next time
                $expiry = (int) $timeout - time();
                if ($expiry > 0) {
                    set_transient($key, maybe_unserialize($value), $expiry);
                }
                return maybe_unserialize($value);
            }
        } else {
            // Expired, clean up
            $wpdb->delete($wpdb->options, ['option_name' => $transient_key], ['%s']);
            $wpdb->delete($wpdb->options, ['option_name' => $timeout_key], ['%s']);
        }
    }
    
    return false;
}

/**
 * Set transient with dual-write to both memcached and database for ai_tr_attr_ keys.
 * Writes to both memcached (via set_transient) and database (direct) for persistence.
 *
 * @param string $key Transient key
 * @param mixed $value Transient value
 * @param int $expiration Expiration time in seconds
 * @return bool True on success, false on failure
 */
function ai_translate_set_attr_transient($key, $value, $expiration)
{
    // Write to memcached (via standard WordPress transient API)
    $memcached_result = set_transient($key, $value, $expiration);
    
    // Also write to database for persistence (survives memcached restart)
    global $wpdb;
    $transient_key = '_transient_' . $key;
    $timeout_key = '_transient_timeout_' . $key;
    $timeout = time() + $expiration;
    
    // Use WordPress functions to ensure proper serialization
    $value_serialized = maybe_serialize($value);
    
    // Insert or update transient value
    $wpdb->replace(
        $wpdb->options,
        [
            'option_name' => $transient_key,
            'option_value' => $value_serialized,
            'autoload' => 'no'
        ],
        ['%s', '%s', '%s']
    );
    
    // Insert or update timeout
    $wpdb->replace(
        $wpdb->options,
        [
            'option_name' => $timeout_key,
            'option_value' => (string) $timeout,
            'autoload' => 'no'
        ],
        ['%s', '%s', '%s']
    );
    
    return $memcached_result;
}

/**
 * Delete transient from both memcached and database for ai_tr_attr_ keys.
 *
 * @param string $key Transient key
 * @return bool True on success, false on failure
 */
function ai_translate_delete_attr_transient($key)
{
    // Delete from memcached
    $memcached_result = delete_transient($key);
    
    // Also delete from database
    global $wpdb;
    $transient_key = '_transient_' . $key;
    $timeout_key = '_transient_timeout_' . $key;
    
    $wpdb->delete($wpdb->options, ['option_name' => $transient_key], ['%s']);
    $wpdb->delete($wpdb->options, ['option_name' => $timeout_key], ['%s']);
    
    return $memcached_result;
}

/**
 * Cache key for client-side / attribute translations (dual-write transient).
 *
 * @param string $lang            Language code.
 * @param string $normalized_text Normalized source text.
 * @return string
 */
function ai_translate_attr_cache_key($lang, $normalized_text) {
    return 'ai_tr_attr_' . sanitize_key((string) $lang) . '_' . md5((string) $normalized_text);
}

/**
 * Segment-cache fallback key for attribute texts (ai_tr_seg_*).
 *
 * @param string $lang            Language code.
 * @param string $normalized_text Normalized source text.
 * @return string
 */
function ai_translate_seg_attr_cache_key($lang, $normalized_text) {
    $lang = sanitize_key((string) $lang);
    return 'ai_tr_seg_' . $lang . '_' . md5('attr|' . md5((string) $normalized_text));
}
