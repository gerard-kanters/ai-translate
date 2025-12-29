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
     * Get site-specific cache directory name.
     * Returns sanitized domain name for use as directory name.
     * Uses the active domain (HTTP_HOST) instead of the WordPress home URL to support multi-domain setups.
     *
     * @return string
     */
    private static function get_site_cache_dir()
    {
        $settings = get_option('ai_translate_settings', []);
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
        
        if (!$multi_domain) {
            return '';
        }
        
        // Use the active domain from HTTP_HOST (the domain the user is actually visiting)
        // This ensures each domain gets its own cache directory
        $active_domain = '';
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $active_domain = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
            // Remove port if present (e.g., "example.com:8080" -> "example.com")
            if (strpos($active_domain, ':') !== false) {
                $active_domain = strtok($active_domain, ':');
            }
        }
        
        // Fallback to SERVER_NAME if HTTP_HOST is not available
        if (empty($active_domain) && isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $active_domain = sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME']));
        }
        
        // Final fallback to home_url() host (should rarely be needed)
        if (empty($active_domain)) {
            $active_domain = parse_url(home_url(), PHP_URL_HOST);
            if (empty($active_domain)) {
                $active_domain = 'default';
            }
        }
        
        // Sanitize domain name for use as directory name
        $sanitized = sanitize_file_name($active_domain);
        if (empty($sanitized)) {
            $sanitized = 'default';
        }
        
        return $sanitized;
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


