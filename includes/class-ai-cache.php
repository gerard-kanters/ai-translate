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
     * @param string $lang
     * @param string $route_id
     * @param string $content_version
     * @return string
     */
    public static function key($lang, $route_id, $content_version)
    {
        $site_hash = substr(md5(home_url()), 0, 8);
        return sprintf('ait:v2:%s:%s:%s:%s', $site_hash, $lang, $route_id, $content_version);
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
            // Revalidate based on admin setting (cache_expiration in hours, min 14 days)
            $settings = get_option('ai_translate_settings', []);
            $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14 * 24);
            $expiry_seconds = max(14 * 24, $expiry_hours) * HOUR_IN_SECONDS;
            $mtime = @filemtime($file);
            if ($mtime && (time() - (int) $mtime) > $expiry_seconds) {
                return false; // expired → force refresh via API
            }
            $contents = @file_get_contents($file);
            return $contents !== false ? $contents : false;
        }
        return false;
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
        // Extract language from key: ait:v2:site:lang:route:ver
        $parts = explode(':', (string) $key);
        $lang = isset($parts[3]) ? sanitize_key($parts[3]) : 'xx';
        $dir = $base . $lang . '/pages/';
        $hash = md5($key);
        return $dir . substr($hash, 0, 2) . '/' . $hash . '.html';
    }
}


