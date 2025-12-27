<?php

namespace AITranslate;

/**
 * Cache layer for translated HTML artifacts.
 */
final class AI_Cache
{
    /**
     * Build cache key.
     * 
     * Note: content_version is deprecated and no longer used for stability.
     * The route_id is already unique per page, making content_version unnecessary.
     * Cache expiry (14+ days) ensures automatic refresh.
     *
     * @param string $lang
     * @param string $route_id
     * @param string $content_version Optional. Deprecated, kept for backward compatibility. Default: ''
     * @return string
     */
    public static function key($lang, $route_id, $content_version = '')
    {
        // Use database-based identifier instead of domain for multi-domain site support
        // This allows viool-docente.nl and vioolles.net to share the same cache
        // Use DB_NAME + table prefix as site identifier (unique per WordPress install, not per domain)
        global $wpdb;
        $db_identifier = defined('DB_NAME') ? DB_NAME : '';
        $table_prefix = isset($wpdb->prefix) ? $wpdb->prefix : 'wp_';
        $site_hash = substr(md5($db_identifier . '|' . $table_prefix), 0, 8);
        // Remove content_version from key for stability - route_id is already unique per page
        return sprintf('ait:v4:%s:%s:%s', $site_hash, $lang, $route_id);
    }

    /**
     * Get artifact from cache (filesystem fallback).
     *
     * @param string $key
     * @return string|false
     */
    public static function get($key)
    {
        $file = self::file_path($key);
        if (is_file($file)) {
            return self::validate_and_read_cache($file, $key);
        }
        
        return false;
    }
    
    /**
     * Validate cache file and read contents if valid.
     *
     * @param string $file
     * @param string $key
     * @return string|false
     */
    private static function validate_and_read_cache($file, $key)
    {
        // Revalidate based on admin setting (cache_expiration in hours)
        // Admin validation ensures minimum 14 days, so we respect the setting directly
        $settings = get_option('ai_translate_settings', []);
        $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14 * 24);
        $expiry_seconds = $expiry_hours * HOUR_IN_SECONDS;
        $mtime = @filemtime($file);
        if ($mtime) {
            $age_seconds = time() - (int) $mtime;
            if ($age_seconds > $expiry_seconds) {
                \ai_translate_dbg('Page cache expired', [
                    'key_preview' => substr($key, 0, 50),
                    'age_hours' => round($age_seconds / HOUR_IN_SECONDS, 1),
                    'expiry_hours' => $expiry_hours,
                    'expiry_days' => round($expiry_hours / 24, 1)
                ]);
                return false; // expired → force refresh via API
            }
        }
        $contents = @file_get_contents($file);
        return $contents !== false ? $contents : false;
    }
    

    /**
     * Store artifact to cache (filesystem).
     *
     * @param string $key
     * @param string $html
     * @return void
     */
    public static function set($key, $html)
    {
        $file = self::file_path($key);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        @file_put_contents($file, $html);
    }

    /**
     * Map key to file path under uploads.
     *
     * @param string $key
     * @return string
     */
    private static function file_path($key)
    {
        $uploads = wp_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
        // Extract language from key: 
        // - ait:v4:site:lang:route (v4 = database-based site identifier, no content_version)
        // - ait:v3:site:lang:route (v3 = domain-based site identifier, no content_version)
        // - ait:v2:site:lang:route:ver (v2 = old format with content_version, for backward compatibility)
        $parts = explode(':', (string) $key);
        $lang = isset($parts[3]) ? sanitize_key($parts[3]) : 'xx';
        $dir = $base . $lang . '/pages/';
        $hash = md5($key);
        return $dir . substr($hash, 0, 2) . '/' . $hash . '.html';
    }
}


