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
     * Check if cache file exists and is expired.
     *
     * @param string $key
     * @return bool True if cache exists and is expired, false otherwise
     */
    public static function is_expired(string $key): bool
    {
        $file = self::file_path($key);
        if (!is_file($file)) {
            return false; // File doesn't exist, so not expired
        }
        
        $settings = get_option('ai_translate_settings', []);
        $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14 * 24);
        $expiry_seconds = $expiry_hours * HOUR_IN_SECONDS;
        $mtime = @filemtime($file);
        if ($mtime) {
            $age_seconds = time() - (int) $mtime;
            return $age_seconds > $expiry_seconds;
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
        
        // Log cache write for warm cache debugging
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $is_warm_cache_request = (strpos($user_agent, 'AITranslateCacheWarmer') !== false);
        
        if ($is_warm_cache_request) {
            $uploads = wp_upload_dir();
            $log_dir = trailingslashit($uploads['basedir']) . 'ai-translate/logs/';
            if (!is_dir($log_dir)) {
                wp_mkdir_p($log_dir);
            }
            $log_file = $log_dir . 'warm-cache-debug.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] CACHE_SET: Writing cache file | key: {$key} | file: {$file} | dir_exists: " . (is_dir($dir) ? 'yes' : 'no') . " | dir_writable: " . (is_writable($dir) ? 'yes' : 'no') . " | html_size: " . strlen($html) . "\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
        
        $result = @file_put_contents($file, $html);
        
        if ($is_warm_cache_request) {
            $timestamp = date('Y-m-d H:i:s');
            $file_exists_after = file_exists($file);
            $log_entry = "[{$timestamp}] CACHE_SET: Write result | file: {$file} | write_result: " . ($result !== false ? 'success (' . $result . ' bytes)' : 'failed') . " | file_exists_after: " . ($file_exists_after ? 'yes' : 'no') . "\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
        
        // Track cache metadata for admin table
        // Extract post_id from route_id in cache key: ait:v4:site:lang:route_id
        $parts = explode(':', (string) $key);
        if (count($parts) >= 5) {
            // route_id itself contains ":" (e.g. "post:123" or "path:md5"), so reconstruct it
            $route_id = implode(':', array_slice($parts, 4));
            $post_id = null;
            
            // Check if route_id is in format 'post:123'
            if (strpos($route_id, 'post:') === 0) {
                $post_id = (int) substr($route_id, 5);
            }
            // Check if route_id is path-based and represents homepage (path:md5(/))
            elseif ($route_id === ('path:' . md5('/'))) {
                // Use post_id = 0 for homepage (blog listing)
                $post_id = 0;
            }
            
            if ($post_id !== null) {
                // Extract language from key
                $lang = isset($parts[3]) ? sanitize_key($parts[3]) : '';
                if ($lang !== '') {
                    // Insert metadata
                    $cache_hash = md5($key);
                    AI_Cache_Meta::insert($post_id, $lang, $file, $cache_hash);
                }
            }
        }
    }

    /**
     * Delegate to centralized get_site_cache_dir in AI_Translate_Core.
     *
     * @return string
     */
    private static function get_site_cache_dir()
    {
        return \AITranslate\AI_Translate_Core::get_site_cache_dir();
    }

    /**
     * Get file path for a cache key (public method for metadata tracking)
     *
     * @param string $key
     * @return string
     */
    public static function get_file_path($key)
    {
        return self::file_path($key);
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
        
        // Add site-specific directory if multi-domain caching is enabled
        $site_dir = self::get_site_cache_dir();
        if (!empty($site_dir)) {
            $base = trailingslashit($base) . $site_dir . '/';
        }
        
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


