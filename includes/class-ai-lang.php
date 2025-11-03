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
        $hasLangInUrl = false;

        $q_lang = get_query_var('ai_lang');
        if (is_string($q_lang) && $q_lang !== '') {
            $lang = strtolower(sanitize_key($q_lang));
            $hasLangInUrl = true;
        } else {
            // Fallback: parse leading /xx/ from request URI if present
            $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            if ($req !== '') {
                if (preg_match('#^/([a-z]{2})(?:/|$)#i', $req, $m)) {
                    $lang = strtolower($m[1]);
                    $hasLangInUrl = true;
                }
            }
        }
        // When URL has no language prefix, fall back to cookie and then browser language.
        if ($lang === null || $lang === '') {
            // 1) Cookie wins if valid
            $cookieLang = isset($_COOKIE['ai_translate_lang']) ? strtolower(sanitize_key((string) $_COOKIE['ai_translate_lang'])) : '';
            if ($cookieLang !== '' && (empty($allowed) || in_array($cookieLang, $allowed, true))) {
                self::$current = $cookieLang;
                return self::$current;
            }
            // 2) Browser Accept-Language (first 2-letter match)
            $browser = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
            $picked = '';
            if ($browser !== '') {
                $parts = explode(',', $browser);
                foreach ($parts as $p) {
                    $p = trim((string) $p);
                    if ($p === '') { continue; }
                    $code = strtolower(substr($p, 0, 2));
                    if ($code !== '' && (empty($allowed) || in_array($code, $allowed, true))) {
                        $picked = $code;
                        break;
                    }
                }
            }
            if ($picked !== '') {
                // Mark this request as a first-visit browser-language selection so
                // template_redirect can still redirect from root to /{lang}/ even though
                // the cookie is now present within this same request lifecycle.
                $GLOBALS['ai_translate_first_visit_lang'] = $picked;
                self::$current = $picked;
                return self::$current;
            }
            // 3) Default as last resort
            $normalizedDefault = $default !== '' ? strtolower(sanitize_key($default)) : '';
            if ($normalizedDefault !== '') {
                if (!headers_sent()) {
                    setcookie('ai_translate_lang', $normalizedDefault, time() + 30 * DAY_IN_SECONDS, '/', '', false, true);
                }
                $_COOKIE['ai_translate_lang'] = $normalizedDefault;
                self::$current = $normalizedDefault;
                return self::$current;
            }
            self::$current = null;
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
     * Reset cached language detection (force re-detection on next call).
     */
    public static function reset()
    {
        self::$current = null;
    }

    /**
     * Explicitly set the current language (bypass detection).
     *
     * @param string|null $lang
     * @return string|null
     */
    public static function set_current($lang)
    {
        self::$current = $lang !== null ? strtolower(sanitize_key((string) $lang)) : null;
        return self::$current;
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


