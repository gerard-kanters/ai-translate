<?php
/**
 * Stub classes for plugin dependencies used in unit tests.
 *
 * These provide minimal implementations so the real classes under test
 * can be loaded without requiring the full WordPress + plugin environment.
 * Test methods override behavior via Brain\Monkey or direct static setters.
 */

namespace AITranslate;

if (!class_exists('AITranslate\\AI_Translate_Core')) {
    class AI_Translate_Core
    {
        private static $settings = [];
        private static $site_cache_dir = '';
        private static $cache_hours = 336;

        public static function get_site_cache_dir()
        {
            return self::$site_cache_dir;
        }

        public static function cache_expiration_hours()
        {
            return self::$cache_hours;
        }

        public static function is_multi_domain()
        {
            return false;
        }

        public static function get_setting($key, $default = null)
        {
            return self::$settings[$key] ?? $default;
        }

        public static function get_website_context()
        {
            return '';
        }

        /** Test helpers to configure stubs */
        public static function _set_site_cache_dir($dir)
        {
            self::$site_cache_dir = $dir;
        }

        public static function _set_cache_hours($h)
        {
            self::$cache_hours = $h;
        }

        public static function _set_setting($key, $val)
        {
            self::$settings[$key] = $val;
        }

        public static function _reset()
        {
            self::$settings = [];
            self::$site_cache_dir = '';
            self::$cache_hours = 336;
        }
    }
}

if (!class_exists('AITranslate\\AI_Lang')) {
    class AI_Lang
    {
        private static $default = 'nl';

        public static function default()
        {
            return self::$default;
        }

        /** Test helper */
        public static function _setDefault($lang)
        {
            self::$default = $lang;
        }
    }
}

if (!class_exists('AITranslate\\AI_Batch')) {
    class AI_Batch
    {
        private static $translate_result = null;

        public static function translate_plan($plan, $from, $to, $ctx = [])
        {
            if (self::$translate_result !== null) {
                return self::$translate_result;
            }
            return ['segments' => []];
        }

        /** Test helper */
        public static function _setTranslateResult($result)
        {
            self::$translate_result = $result;
        }

        public static function _reset()
        {
            self::$translate_result = null;
        }
    }
}

if (!class_exists('AITranslate\\AI_Cache_Meta')) {
    class AI_Cache_Meta
    {
        public static function insert($post_id, $lang, $file, $hash)
        {
            // no-op in tests
        }
    }
}
