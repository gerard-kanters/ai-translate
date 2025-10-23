<?php

namespace AITranslate;

/**
 * Language detection and helpers.
 */
final class AI_Lang
{
    /** @var string|null */
    private static $current;

    /**
     * Detect current language from URL rewrite tag, cookie, or default setting.
     *
     * @return string|null
     */
    public static function detect()
    {
        if (self::$current !== null) {
            return self::$current;
        }

        $settings = get_option('ai_translate_settings', []);
        $default = isset($settings['default_language']) ? (string)$settings['default_language'] : '';

        $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? array_map('strval', $settings['enabled_languages']) : [];
        $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? array_map('strval', $settings['detectable_languages']) : [];
        // Allow any supported language in URL (not alleen enabled/detectable)
        $available = [];
        if (class_exists('AITranslate\\AI_Translate_Core')) {
            $core = \AITranslate\AI_Translate_Core::get_instance();
            $available = array_map('strval', array_keys($core->get_available_languages()));
        }
        $allowed = array_values(array_unique(array_filter(array_merge($available, $enabled, $detectable, $default !== '' ? [$default] : []))));
        // Normalize to lowercase to avoid case-mismatch (e.g., 'IT' vs 'it')
        $allowed = array_map(function ($v) { return strtolower(sanitize_key((string) $v)); }, $allowed);

        $lang = null;

        $q_lang = get_query_var('ai_lang');
        if (is_string($q_lang) && $q_lang !== '') {
            $lang = strtolower(sanitize_key($q_lang));
        } else {
            // Fallback: parse leading /xx/ from request URI if present
            $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            if ($req !== '') {
                if (preg_match('#^/([a-z]{2})(?:/|$)#i', $req, $m)) {
                    $lang = strtolower($m[1]);
                }
            }
        }
        // Important: when URL has no language prefix, prefer default language over cookie
        // This ensures routes without /{lang}/ are always the default language
        // and prevents stale cookies from forcing translation on default URLs.

        if ($lang === null || $lang === '') {
            self::$current = $default !== '' ? $default : null;
            return self::$current;
        }

        if (!empty($allowed) && !in_array(strtolower($lang), $allowed, true)) {
            // Not in allowed set; fall back to default if present.
            self::$current = $default !== '' ? $default : null;
            return self::$current;
        }

        self::$current = $lang;
        return self::$current;
    }

    /**
     * Current language (cached).
     *
     * @return string|null
     */
    public static function current()
    {
        return self::detect();
    }

    /**
     * Default language.
     *
     * @return string|null
     */
    public static function default()
    {
        $settings = get_option('ai_translate_settings', []);
        $default = isset($settings['default_language']) ? (string)$settings['default_language'] : '';
        return $default !== '' ? $default : null;
    }

    /**
     * Determine whether current request must be exempt from translation and URL rewriting.
     * Rules: skip admin/AJAX/REST/feeds and skip when current language equals default.
     *
     * @return bool
     */
    public static function is_exempt_request()
    {
        if (is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed()) {
            return true;
        }
        $lang = self::current();
        $default = self::default();
        if ($lang === null || $default === null) {
            return true;
        }
        return strtolower($lang) === strtolower($default);
    }

    /**
     * Should translate into the given language?
     *
     * @param string|null $lang
     * @return bool
     */
    public static function should_translate($lang)
    {
        $default = self::default();
        return $lang !== null && $default !== null && strtolower($lang) !== strtolower($default);
    }

    /**
     * Enabled languages for switcher.
     *
     * @return string[]
     */
    public static function enabled()
    {
        $settings = get_option('ai_translate_settings', []);
        return isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? array_values(array_unique(array_map('strval', $settings['enabled_languages']))) : [];
    }

    /**
     * Detectable languages for auto-detection.
     *
     * @return string[]
     */
    public static function detectable()
    {
        $settings = get_option('ai_translate_settings', []);
        return isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? array_values(array_unique(array_map('strval', $settings['detectable_languages']))) : [];
    }
}


