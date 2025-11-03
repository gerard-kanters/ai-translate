<?php
/**
 * Plugin Name: AI Translate
 * Description: AI based translation plugin. Adding 25 languages in a few clicks. 
 * Author: Netcare
 * Author URI: https://netcare.nl/
 * Version: 2.0.7
 * Requires PHP: 8.0
 * Text Domain: ai-translate
 */

// Do not allow direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Include core/admin and runtime classes.
require_once __DIR__ . '/includes/class-ai-translate-core.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/class-ai-lang.php';
require_once __DIR__ . '/includes/class-ai-cache.php';
require_once __DIR__ . '/includes/class-ai-dom.php';
require_once __DIR__ . '/includes/class-ai-batch.php';
require_once __DIR__ . '/includes/class-ai-seo.php';
require_once __DIR__ . '/includes/class-ai-url.php';
require_once __DIR__ . '/includes/class-ai-ob.php';
require_once __DIR__ . '/includes/class-ai-slugs.php';


// Debug logger
if (!function_exists('ai_translate_dbg')) {
    function ai_translate_dbg($message, $context = array()) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        $contextStr = !empty($context) ? ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        error_log('[AI-Translate] ' . $message . $contextStr);
    }
}

/**
 * Get cookie options with correct samesite value based on SSL.
 * samesite: 'None' requires secure: true (HTTPS), otherwise use 'Lax'.
 *
 * @return array Cookie options array
 */
if (!function_exists('ai_translate_get_cookie_options')) {
    function ai_translate_get_cookie_options($lang) {
        $secure = is_ssl();
        $options = [
            'expires' => time() + 30 * 86400,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
        ];
        // samesite: 'None' requires secure: true (HTTPS)
        $options['samesite'] = $secure ? 'None' : 'Lax';
        return $options;
    }
}

// Ensure slug map table exists early in init
add_action('init', function () {
    \AITranslate\AI_Slugs::install_table();
    
    // Ensure permalinks are enabled (required for rewrite rules to work)
    $permalink_structure = get_option('permalink_structure');
    if (empty($permalink_structure)) {
        // Use postname structure as default
        update_option('permalink_structure', '/%postname%/');
        // Flush rewrite rules to apply the new structure
        flush_rewrite_rules(false);
    }
}, 1);

 

// Add original-style language-prefixed rewrite rules using 'lang' query var to ensure WP resolves pages via pagename
add_action('init', function () {
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : array();
    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : array();
    $default = isset($settings['default_language']) ? (string) $settings['default_language'] : '';
    $langs = array_values(array_unique(array_merge($enabled, $detectable)));
    if ($default !== '') { $langs = array_diff($langs, array($default)); }
    $langs = array_filter(array_map('sanitize_key', $langs));
    if (empty($langs)) return;
    $regex = '(' . implode('|', array_map(function ($l) { return preg_quote($l, '/'); }, $langs)) . ')';
    add_rewrite_rule('^' . $regex . '/?$', 'index.php?lang=$matches[1]', 'top');
    // Explicit CPT bases similar to org implementation (keep before generic page/name rules)
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)(product)/([^/]+)/?$', 'index.php?lang=$matches[1]&post_type=product&name=$matches[3]', 'top');
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)(service)/([^/]+)/?$', 'index.php?lang=$matches[1]&post_type=service&name=$matches[3]', 'top');
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)(.+?)/?$', 'index.php?lang=$matches[1]&pagename=$matches[2]', 'top');
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)([^/]+)/?$', 'index.php?lang=$matches[1]&name=$matches[2]', 'top');
    // Pagination for posts index: /{lang}/page/{n}/ (added last so it sits on top due to 'top')
    add_rewrite_rule('^' . $regex . '/page/([0-9]+)/?$', 'index.php?lang=$matches[1]&paged=$matches[2]', 'top');
}, 2);

add_filter('query_vars', function ($vars) {
    if (!in_array('lang', $vars, true)) { $vars[] = 'lang'; }
    return $vars;
});

/**
 * Handle language switch via switcher early in init (before template_redirect)
 */
// Note: switch_lang and _lang_key handlers moved to template_redirect to run before other redirect logic

/**
 * Handle language switch and _lang_key parameters very early (before any redirects)
 * Using 'init' hook with priority 1 to ensure it runs before redirect_canonical
 */
add_action('init', function () {
    // Skip admin/AJAX/REST only (don't use is_feed() here, it requires query to be run)
    if (is_admin() || wp_doing_ajax() || wp_is_json_request()) {
        return;
    }
    
    $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $cookieValue = isset($_COOKIE['ai_translate_lang']) ? $_COOKIE['ai_translate_lang'] : 'none';
    ai_translate_dbg('init: start', [
        'reqPath' => $reqPath,
        'cookie' => $cookieValue,
        'switch_lang' => isset($_GET['switch_lang']) ? $_GET['switch_lang'] : 'none',
        '_lang_switch' => isset($_GET['_lang_switch']) ? $_GET['_lang_switch'] : 'none'
    ]);
    
    // If no switch_lang or _lang_switch parameter, ensure cookie is set correctly before any other code runs
    // This prevents AI_Lang::detect() from being called elsewhere and using browser language instead of cookie
    if (!isset($_GET['switch_lang']) && !isset($_GET['_lang_switch'])) {
        // Check if URL has language prefix - if so, sync cookie
        if ($reqPath !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $reqPath, $m)) {
            $langFromUrl = strtolower(sanitize_key($m[1]));
            if ($cookieValue !== $langFromUrl) {
                setcookie('ai_translate_lang', $langFromUrl, ai_translate_get_cookie_options($langFromUrl));
                $_COOKIE['ai_translate_lang'] = $langFromUrl;
                ai_translate_dbg('init: synced cookie to URL lang', ['lang' => $langFromUrl]);
            }
        } elseif ($cookieValue !== 'none' && $cookieValue !== '') {
            // No language in URL but cookie exists - ensure it's set correctly
            // This ensures cookie is available for any early calls to AI_Lang::detect()
            setcookie('ai_translate_lang', $cookieValue, ai_translate_get_cookie_options($cookieValue));
            ai_translate_dbg('init: ensured cookie is set', ['lang' => $cookieValue]);
        }
    }
    
    // Handle language switch via switcher or URL parameter - MUST be FIRST
    $switchLangParam = isset($_GET['switch_lang']) ? strtolower(sanitize_key((string) $_GET['switch_lang'])) : '';
    if ($switchLangParam !== '') {
        // Set cookie for new language
        setcookie('ai_translate_lang', $switchLangParam, ai_translate_get_cookie_options($switchLangParam));
        // Set cookie in $_COOKIE immediately so it's available in this request
        $_COOKIE['ai_translate_lang'] = $switchLangParam;
        
        // Store in transient for the next request (in case cookie isn't available yet)
        $transientKey = 'ai_switch_lang_' . md5((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . time());
        set_transient($transientKey, $switchLangParam, 10);
        // Also store the transient key in a session-like transient for easy lookup in template_redirect
        set_transient('ai_switch_lang_current_key', $transientKey, 10);
        set_transient('ai_switch_lang_current_lang', $switchLangParam, 10);
        
        // Force language detection with updated cookie
        \AITranslate\AI_Lang::reset();
        \AITranslate\AI_Lang::detect();
        
        // Redirect directly to clean URL (with transient key as fallback)
        $defaultLang = \AITranslate\AI_Lang::default();
        $cleanUrl = ($switchLangParam === strtolower((string) $defaultLang)) ? home_url('/') : home_url('/' . $switchLangParam . '/');
        $cleanUrl = add_query_arg('_lang_switch', urlencode($transientKey), $cleanUrl);
        wp_safe_redirect($cleanUrl, 302);
        exit;
    }
    
    // Check for transient key (from switch_lang redirect) - MUST be SECOND
    if (isset($_GET['_lang_switch'])) {
        $transientKey = sanitize_text_field((string) $_GET['_lang_switch']);
        $transientLang = get_transient($transientKey);
        if ($transientLang !== false && is_string($transientLang)) {
            $targetLang = strtolower(sanitize_key($transientLang));
            // Set cookie in $_COOKIE immediately so detect() can use it
            $_COOKIE['ai_translate_lang'] = $targetLang;
            // Make sure cookie is set correctly
            setcookie('ai_translate_lang', $targetLang, ai_translate_get_cookie_options($targetLang));
            // Store transient key and language in session-like transients for easy lookup in template_redirect
            set_transient('ai_switch_lang_current_key', $transientKey, 10);
            set_transient('ai_switch_lang_current_lang', $targetLang, 10);
            // Force language detection with updated cookie
            \AITranslate\AI_Lang::reset();
            \AITranslate\AI_Lang::detect();
            // Do NOT redirect again; keep _lang_switch in URL for this request to avoid early redirects
            // It will be removed client-side via history.replaceState in the footer.
        }
    }
    
    // Set WordPress locale based on detected language - MUST be after cookie is set
    // This filter can be called early by WordPress, so we need to ensure cookie is available
    add_filter('locale', function ($locale) {
        // Ensure cookie is set in $_COOKIE before calling AI_Lang::current()
        if (!isset($_COOKIE['ai_translate_lang']) || $_COOKIE['ai_translate_lang'] === '') {
            $cookieValue = isset($_COOKIE['ai_translate_lang']) ? $_COOKIE['ai_translate_lang'] : '';
            if ($cookieValue === '') {
                // Try to get from transient if available
                $transientLang = get_transient('ai_switch_lang_current_lang');
                if ($transientLang !== false && is_string($transientLang)) {
                    $_COOKIE['ai_translate_lang'] = strtolower(sanitize_key($transientLang));
                }
            }
        }
        $currentLang = \AITranslate\AI_Lang::current();
        if ($currentLang) {
            // WordPress locale usually uses format like 'en_US', 'nl_NL'.
            // For 2-letter codes, we'll append a default country code if not explicitly set.
            // This is a pragmatic choice; ideally, we'd have a mapping.
            return strtolower($currentLang) . '_' . strtoupper($currentLang);
        }
        return $locale;
    }, 10);
}, 1);

/**
 * Prevent WordPress from redirecting language-prefixed URLs to the canonical root.
 */
add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
    // Preserve switch_lang and _lang_switch parameters
    if (isset($_GET['switch_lang']) || isset($_GET['_lang_switch'])) {
        return false;
    }
    $req = (string) $requested_url;
    $path = (string) parse_url($req, PHP_URL_PATH);
    if ($path !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $path)) {
        return false; // keep /xx or /xx/... as requested
    }
    return $redirect_url;
}, 10, 2);

/**
 * Add a 5-star rating link to the plugin row on the Plugins screen.
 *
 * This renders a golden 5-star link that points to the plugin's WordPress.org page,
 * similar to popular plugins. Only affects the AI Translate row.
 *
 * @param string[] $links Existing meta links for the row.
 * @param string   $file  Plugin basename for the current row.
 * @return string[]
 */
add_filter('plugin_row_meta', function (array $links, $file) {
    if ($file !== plugin_basename(__FILE__)) {
        return $links;
    }

    $url = 'https://wordpress.org/plugins/ai-translate/';
    $label = __('Rate on WordPress.org', 'ai-translate');
    $stars = '★★★★★';

    $links[] = sprintf(
        '<a href="%1$s" target="_blank" rel="noopener noreferrer" aria-label="%2$s" style="color:#ffb900;text-decoration:none;">%3$s</a>',
        esc_url($url),
        esc_attr($label),
        esc_html($stars)
    );

    return $links;
}, 10, 2);

/**
 * Flush rewrite rules on activation.
 */
register_activation_hook(__FILE__, function () {
    // Enable permalinks if not already set
    $permalink_structure = get_option('permalink_structure');
    if (empty($permalink_structure)) {
        // Use postname structure as default
        update_option('permalink_structure', '/%postname%/');
    }
    // Ensure rules are registered before flushing
    do_action('init');
    flush_rewrite_rules();
});

/**
 * Map ai_path to WordPress internal query vars.
 */
add_filter('request', function ($vars) {
    // Hard skip for admin paths
    $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($req !== '' && (strpos($req, '/wp-admin/') !== false || strpos($req, 'wp-login.php') !== false)) {
        return $vars;
    }
    // Handle 'lang' query var: map translated slug back to source for both pages and singles
    if (isset($vars['lang'])) {
        // pagename can be hierarchical: map EACH segment back to its source slug
        if (!empty($vars['pagename'])) {
            $pg = (string) $vars['pagename'];
            $segments = array_values(array_filter(explode('/', trim($pg, '/'))));
            if (!empty($segments)) {
                foreach ($segments as $i => $seg) {
                    $src = \AITranslate\AI_Slugs::resolve_any_to_source_slug((string) $seg);
                    if ($src) {
                        $segments[$i] = $src;
                    }
                }
                $vars['pagename'] = implode('/', $segments);
            }
        }
        if (!empty($vars['name'])) {
            $nm = (string) $vars['name'];
            $src = \AITranslate\AI_Slugs::resolve_any_to_source_slug($nm);
                if ($src) {
                    $vars['name'] = $src;
                }
        }
    }
    return $vars;
});

/**
 * Start output buffering for front-end (skip admin, AJAX, REST and feeds).
 */
add_action('template_redirect', function () {
    // Skip only admin/AJAX/REST/feeds; allow default language to pass so SEO injection can run
    if (is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed()) {
        return;
    }
    
    // If switch_lang or _lang_switch parameter is present, skip redirect logic but continue to run detection/OB
    $ai_skip_redirects = (isset($_GET['switch_lang']) || isset($_GET['_lang_switch']));
    
    $defaultLang = \AITranslate\AI_Lang::default();
    $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $langFromUrl = null;
    
    ai_translate_dbg('template_redirect: start', [
        'reqPath' => $reqPath,
        'defaultLang' => $defaultLang,
        'cookie' => isset($_COOKIE['ai_translate_lang']) ? $_COOKIE['ai_translate_lang'] : 'none',
        'skip_redirects' => $ai_skip_redirects
    ]);
    
    // 1. Check URL for language prefix - if present, set cookie to match URL language
    if ($reqPath !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $reqPath, $m)) {
        $langFromUrl = strtolower(sanitize_key($m[1]));
        ai_translate_dbg('template_redirect: lang from URL', ['lang' => $langFromUrl]);
        // Always sync cookie to URL language when URL has language prefix
        setcookie('ai_translate_lang', $langFromUrl, ai_translate_get_cookie_options($langFromUrl));
        $_COOKIE['ai_translate_lang'] = $langFromUrl;
    }
    
    // 2. Determine target language based on priority: URL > Cookie > Transient > Browser > Default
    $targetLang = null;
    
    if ($langFromUrl !== null) {
        // URL has language prefix - use it
        $targetLang = $langFromUrl;
        ai_translate_dbg('template_redirect: using URL lang', ['targetLang' => $targetLang]);
    } else {
        // No language in URL - when visiting root URL (/), always use default language
        // If cookie exists and is not default, redirect to language-prefixed URL
        $cookieLang = isset($_COOKIE['ai_translate_lang']) ? strtolower(sanitize_key((string) $_COOKIE['ai_translate_lang'])) : '';
        
        // Check for transient first (from _lang_switch redirect) - but only if it's NOT the default language
        // If transient is default language, we want to use default language for root URL
        $transientLang = get_transient('ai_switch_lang_current_lang');
        if ($transientLang !== false && is_string($transientLang)) {
            $transientLang = strtolower(sanitize_key($transientLang));
            if (strtolower($transientLang) !== strtolower((string) $defaultLang)) {
                // Transient language is not default - redirect to language-prefixed URL
                $targetLang = $transientLang;
                ai_translate_dbg('template_redirect: using transient lang, redirecting to /' . $targetLang . '/', ['targetLang' => $targetLang]);
                // Set cookie immediately
                setcookie('ai_translate_lang', $targetLang, ai_translate_get_cookie_options($targetLang));
                $_COOKIE['ai_translate_lang'] = $targetLang;
                // Delete transient after use
                $transientKey = get_transient('ai_switch_lang_current_key');
                if ($transientKey !== false) {
                    delete_transient($transientKey);
                    delete_transient('ai_switch_lang_current_key');
                    delete_transient('ai_switch_lang_current_lang');
                }
                if (!$ai_skip_redirects) {
                    wp_safe_redirect(home_url('/' . $targetLang . '/'), 302);
                    exit;
                }
            } else {
                // Transient is default language - delete it and use default for root URL
                $transientKey = get_transient('ai_switch_lang_current_key');
                if ($transientKey !== false) {
                    delete_transient($transientKey);
                    delete_transient('ai_switch_lang_current_key');
                    delete_transient('ai_switch_lang_current_lang');
                }
                $targetLang = (string) $defaultLang;
                ai_translate_dbg('template_redirect: transient is default, using default for root URL', ['targetLang' => $targetLang]);
                // Set cookie to default language
                setcookie('ai_translate_lang', $targetLang, ai_translate_get_cookie_options($targetLang));
                $_COOKIE['ai_translate_lang'] = $targetLang;
            }
        } elseif ($cookieLang !== '' && strtolower($cookieLang) !== strtolower((string) $defaultLang)) {
            // Cookie exists and is not default - redirect to language-prefixed URL
            ai_translate_dbg('template_redirect: cookie is not default, redirecting to /' . $cookieLang . '/');
            if (!$ai_skip_redirects) {
                wp_safe_redirect(home_url('/' . $cookieLang . '/'), 302);
                exit;
            }
            $targetLang = $cookieLang;
        } else {
            // Cookie is default or doesn't exist - use default language for root URL
            $targetLang = (string) $defaultLang;
            ai_translate_dbg('template_redirect: using default lang for root URL', ['targetLang' => $targetLang]);
            // Set cookie to default language if not set or different
            if ($cookieLang === '' || strtolower($cookieLang) !== strtolower($targetLang)) {
                setcookie('ai_translate_lang', $targetLang, ai_translate_get_cookie_options($targetLang));
                $_COOKIE['ai_translate_lang'] = $targetLang;
            }
            
            // No cookie and no transient - check browser language (first-time visit)
            if ($cookieLang === '') {
                ai_translate_dbg('template_redirect: no cookie/transient, using browser detection');
                $browserLang = '';
                $browser = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
                if ($browser !== '') {
                    $settings = get_option('ai_translate_settings', []);
                    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? array_map('strval', $settings['enabled_languages']) : [];
                    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? array_map('strval', $settings['detectable_languages']) : [];
                    $available = [];
                    if (class_exists('AITranslate\\AI_Translate_Core')) {
                        $core = \AITranslate\AI_Translate_Core::get_instance();
                        $available = array_map('strval', array_keys($core->get_available_languages()));
                    }
                    $allowed = array_values(array_unique(array_filter(array_merge($available, $enabled, $detectable))));
                    $allowed = array_map(function ($v) { return strtolower(sanitize_key((string) $v)); }, $allowed);
                    
                    $parts = explode(',', $browser);
                    foreach ($parts as $p) {
                        $p = trim((string) $p);
                        if ($p === '') { continue; }
                        $code = strtolower(substr($p, 0, 2));
                        if ($code !== '' && (empty($allowed) || in_array($code, $allowed, true))) {
                            $browserLang = $code;
                            break;
                        }
                    }
                }
                
                if ($browserLang !== '' && strtolower($browserLang) !== strtolower((string) $defaultLang)) {
                    // Browser language is not default - redirect to language-prefixed URL
                    $targetLang = $browserLang;
                    ai_translate_dbg('template_redirect: browser detection result, redirecting to /' . $targetLang . '/', ['targetLang' => $targetLang, 'browserLang' => $browserLang]);
                    // Set cookie to detected language
                    setcookie('ai_translate_lang', $targetLang, ai_translate_get_cookie_options($targetLang));
                    $_COOKIE['ai_translate_lang'] = $targetLang;
                    if (!$ai_skip_redirects) {
                        wp_safe_redirect(home_url('/' . $targetLang . '/'), 302);
                        exit;
                    }
                } else {
                    // Browser language is default or not detected - use default language
                    $targetLang = (string) $defaultLang;
                    ai_translate_dbg('template_redirect: browser detection result, using default', ['targetLang' => $targetLang, 'browserLang' => $browserLang]);
                    // Set cookie to default language
                    setcookie('ai_translate_lang', $targetLang, ai_translate_get_cookie_options($targetLang));
                    $_COOKIE['ai_translate_lang'] = $targetLang;
                }
            }
        }
    }
    
    // 3. Redirect logic: if target language is not default and URL doesn't match, redirect
    if (!$ai_skip_redirects && $targetLang && $defaultLang) {
        $isDefaultLang = strtolower($targetLang) === strtolower((string) $defaultLang);
        ai_translate_dbg('template_redirect: redirect check', [
            'targetLang' => $targetLang,
            'defaultLang' => $defaultLang,
            'isDefaultLang' => $isDefaultLang,
            'langFromUrl' => $langFromUrl
        ]);
        
        if ($isDefaultLang && $langFromUrl !== null) {
            // Default language should be on root URL (/), not /nl/
            ai_translate_dbg('template_redirect: redirecting default lang from /' . $langFromUrl . '/ to /');
            wp_safe_redirect(home_url('/'), 302);
            exit;
        } elseif (!$isDefaultLang && $langFromUrl === null) {
            // Non-default language should have language prefix in URL
            ai_translate_dbg('template_redirect: redirecting to /' . $targetLang . '/');
            wp_safe_redirect(home_url('/' . $targetLang . '/'), 302);
            exit;
        }
    }
    
    // Force language detection (for other code that depends on it)
    // Reset first to ensure fresh detection with updated cookie
    // But don't overwrite the cookie we just set - use the targetLang we determined
    \AITranslate\AI_Lang::reset();
    // Set the cookie in $_COOKIE before calling detect() so it's available
    if ($targetLang !== null) {
        $_COOKIE['ai_translate_lang'] = $targetLang;
    }
    \AITranslate\AI_Lang::detect();
    
    // IMPORTANT: When visiting root URL (/), always use default language for content (no translation)
    // The cookie is kept for future navigation, but the content should always be default language
    // This ensures that / always shows default language content, even if cookie says otherwise
    if ($reqPath === '/' && $langFromUrl === null) {
        // Force default language for content translation - don't translate default language content
        $targetLang = (string) $defaultLang;
        \AITranslate\AI_Lang::reset();
        $_COOKIE['ai_translate_lang'] = (string) $defaultLang;
        \AITranslate\AI_Lang::detect();
        ai_translate_dbg('template_redirect: forcing default lang for root URL content', ['targetLang' => $targetLang]);
    }

    // Handle full_reset parameter for testing/resetting purposes
    if (isset($_GET['full_reset']) && $_GET['full_reset'] === '1') {
        $defaultLang = \AITranslate\AI_Lang::default();
        $options = ai_translate_get_cookie_options((string) $defaultLang);
        $options['expires'] = time() + 30 * DAY_IN_SECONDS;
        setcookie('ai_translate_lang', (string) $defaultLang, $options);
        $_COOKIE['ai_translate_lang'] = (string) $defaultLang;
        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    // Handle cookie mismatch with URL (user navigated manually or cookie was changed)
    if ($langFromUrl !== null && !$ai_skip_redirects) {
        // URL has language prefix - check if cookie matches
        $cookieLang = isset($_COOKIE['ai_translate_lang']) ? strtolower(sanitize_key((string) $_COOKIE['ai_translate_lang'])) : '';
        if ($cookieLang !== '' && $cookieLang !== $langFromUrl) {
            // Cookie doesn't match URL - redirect to cookie language
            $defaultLang = \AITranslate\AI_Lang::default();
            if ($cookieLang === strtolower((string) $defaultLang)) {
                wp_safe_redirect(home_url('/'), 302);
            } else {
                wp_safe_redirect(home_url('/' . $cookieLang . '/'), 302);
            }
            exit;
        }
    }

    // Ensure search requests have language-prefixed URL when current language != default
    if (function_exists('is_search') && is_search() && !$ai_skip_redirects) {
        $cur = \AITranslate\AI_Lang::current();
        $def = \AITranslate\AI_Lang::default();
        $pathNow = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($cur && $def && strtolower($cur) !== strtolower($def)) {
            if ($pathNow === '' || !preg_match('#^/([a-z]{2})(?:/|$)#i', $pathNow)) {
                $base = home_url('/' . $cur . '/');
                $target = add_query_arg($_GET, $base);
                wp_safe_redirect($target, 302);
                exit;
            }
        }
    }
    \AITranslate\AI_OB::instance()->start();
}, 1);

/**
 * Ensure that when viewing the posts index (translated posts page) with ?blogpage=N,
 * the main query uses paged=N so page 2+ actually render page 2+ instead of page 1.
 */
// Removed pagination forcing; theme handles pagination

// Never adjust admin URLs or rewrite menu items in admin or default language
add_filter('nav_menu_link_attributes', function ($atts, $item) {
    if (is_admin() || \AITranslate\AI_Lang::is_exempt_request()) {
        return $atts;
    }
    return $atts;
}, 10, 2);

// Language switcher is intentionally removed as per request
/**
 * Minimal server-side language switcher (no JS): renders links to current path in other languages.
 */
add_action('wp_footer', function () {
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? array_values($settings['enabled_languages']) : array();
    $default = isset($settings['default_language']) ? (string)$settings['default_language'] : '';
    if (empty($enabled) || $default === '') {
        return;
    }

    // Determine current path and strip any leading /xx/
    $reqUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = (string) parse_url($reqUri, PHP_URL_PATH);
    if ($path === '') { $path = '/'; }
    $pathNoLang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
    if ($pathNoLang === '') { $pathNoLang = '/'; }

    $flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';

    // Inline minimal CSS for bottom-LEFT button, popup opens UPWARDS (namespaced classes to avoid theme conflicts)
    echo '<style>.ai-trans{position:fixed;bottom:20px;left:20px;z-index:2147483000}.ai-trans .ai-trans-btn{display:inline-flex;align-items:center;justify-content:center;gap:4px;padding:8px 12px;border-radius:24px;border:none;background:#1e3a8a;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.2);cursor:pointer;font-size:13px;font-weight:600}.ai-trans .ai-trans-btn img{width:20px;height:14px;border-radius:2px}.ai-trans .ai-trans-menu{position:absolute;bottom:100%;left:0;margin-bottom:8px;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.15);padding:8px;display:none;min-width:140px}.ai-trans.ai-trans-open .ai-trans-menu{display:block}.ai-trans .ai-trans-item{display:flex;align-items:center;gap:8px;padding:8px 10px;text-decoration:none;color:#222;border-radius:6px;font-size:13px}.ai-trans .ai-trans-item:hover{background:#f3f4f6}.ai-trans .ai-trans-item img{width:20px;height:14px;border-radius:2px}</style>';

    // Current language (from URL or default)
    $currentLang = null;
    if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $m)) { $currentLang = strtolower($m[1]); }
    if (!$currentLang) { $currentLang = $default; }
    $currentFlag = esc_url($flags_url . sanitize_key($currentLang) . '.png');

    echo '<div id="ai-trans" class="ai-trans">';
    // Show current language flag with code label
    echo '<button type="button" class="ai-trans-btn" aria-haspopup="true" aria-expanded="false" aria-controls="ai-trans-menu" title="' . esc_attr(strtoupper($currentLang)) . '"><img src="' . $currentFlag . '" alt="' . esc_attr($currentLang) . '"><span>' . esc_html(strtoupper($currentLang)) . '</span></button>';
    echo '<div id="ai-trans-menu" class="ai-trans-menu" role="menu">';

    foreach ($enabled as $code) {
        $code = sanitize_key($code);
        // Always go to the language homepage on switch
        $targetPath = ($code === $default) ? '/' : ('/' . $code . '/');
        // Normalize double slashes
        $targetPath = preg_replace('#/{2,}#','/',$targetPath);
        // Build target URL without query parameters
        $url = esc_url( home_url( $targetPath ) );
        $flag = esc_url( $flags_url . $code . '.png' );
        echo '<a class="ai-trans-item" href="#" role="menuitem" data-lang="' . esc_attr($code) . '" data-target-url="' . esc_attr($url) . '" data-ai-trans-skip="1"><img src="' . $flag . '" alt="' . esc_attr(strtoupper($code)) . '"><span>' . esc_html(strtoupper($code)) . '</span></a>';
    }

    echo '</div></div>';

    // Minimal toggle script + pure JS cookie handling
    $restUrl = esc_url_raw( rest_url('ai-translate/v1/batch-strings') );
    $nonce = wp_create_nonce('ai_translate_front_nonce');
    $is_ssl = is_ssl() ? 'true' : 'false';
    echo '<script>(function(){var w=document.getElementById("ai-trans");if(!w)return;var b=w.querySelector(".ai-trans-btn");b.addEventListener("click",function(e){e.stopPropagation();var open=w.classList.toggle("ai-trans-open");b.setAttribute("aria-expanded",open?"true":"false")});document.addEventListener("click",function(e){if(!w.contains(e.target)){w.classList.remove("ai-trans-open");b.setAttribute("aria-expanded","false")}});w.addEventListener("click",function(e){var a=e.target.closest("a.ai-trans-item");if(!a)return;e.preventDefault();e.stopPropagation();var lang=a.getAttribute("data-lang");var url=a.getAttribute("data-target-url");if(lang&&url){var isSecure=' . $is_ssl . ';var expires=new Date(Date.now()+30*86400*1000).toUTCString();var cookieVal="ai_translate_lang="+encodeURIComponent(lang)+"; expires="+expires+"; path=/; SameSite=None"+(isSecure?"; Secure":"");document.cookie=cookieVal;var switchUrl=url.indexOf("?")>-1?url+"&switch_lang="+encodeURIComponent(lang):url+"?switch_lang="+encodeURIComponent(lang);window.location.href=switchUrl;}});var AI_TA={u:"' . $restUrl . '",n:"' . esc_js($nonce) . '"};
// Remove one-time _lang_switch param from URL once page is loaded to avoid repeated redirects
if(window.location.search&&/[?&]_lang_switch=/.test(window.location.search)){try{var _u=window.location.origin+window.location.pathname+window.location.hash;history.replaceState({},\'\',_u);}catch(e){}}
// Dynamic UI attribute translation (placeholder/title/aria-label/value of buttons)
function gL(){try{var m=location.pathname.match(/^\/([a-z]{2})(?:\/|$)/i);if(m){return (m[1]||"").toLowerCase();}var mc=document.cookie.match(/(?:^|; )ai_translate_lang=([^;]+)/);if(mc){return decodeURIComponent(mc[1]||"").toLowerCase();}}catch(e){}return "";}
function cS(r){var s=new Set();var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label],.initial-greeting,.chatbot-bot-text"):[];ns.forEach(function(el){if(el.hasAttribute("data-ai-trans-skip"))return;var ph=el.getAttribute("placeholder");if(ph&&ph.trim())s.add(ph.trim());var tl=el.getAttribute("title");if(tl&&tl.trim())s.add(tl.trim());var al=el.getAttribute("aria-label");if(al&&al.trim())s.add(al.trim());var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=el.getAttribute("value");if(v&&v.trim())s.add(v.trim());}}var tc=el.textContent;if((el.classList.contains("initial-greeting")||el.classList.contains("chatbot-bot-text"))&&tc&&tc.trim())s.add(tc.trim());});return Array.from(s);} 
 function aT(r,m){var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label],.initial-greeting,.chatbot-bot-text"):[];ns.forEach(function(el){if(el.hasAttribute("data-ai-trans-skip"))return;var ph=el.getAttribute("placeholder");if(ph){var pht=ph.trim();if(pht&&m[pht]!=null)el.setAttribute("placeholder",m[pht]);}var tl=el.getAttribute("title");if(tl){var tlt=tl.trim();if(tlt&&m[tlt]!=null)el.setAttribute("title",m[tlt]);}var al=el.getAttribute("aria-label");if(al){var alt=al.trim();if(alt&&m[alt]!=null)el.setAttribute("aria-label",m[alt]);}var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=el.getAttribute("value");if(v){var vt=v.trim();if(vt&&m[vt]!=null)el.setAttribute("value",m[vt]);}}}var tc=el.textContent;if((el.classList.contains("initial-greeting")||el.classList.contains("chatbot-bot-text"))&&tc){var tct=tc.trim();if(tct&&m[tct]!=null)el.textContent=m[tct];}});} 
 function tA(r){var ss=cS(r);if(!ss.length)return;var x=new XMLHttpRequest();x.open("POST",AI_TA.u,true);x.setRequestHeader("Content-Type","application/json; charset=UTF-8");x.onreadystatechange=function(){if(x.readyState===4&&x.status===200){try{var resp=JSON.parse(x.responseText);if(resp&&resp.success&&resp.data&&resp.data.map){aT(r,resp.data.map);}}catch(e){}}};x.send(JSON.stringify({nonce:AI_TA.n,lang:gL(),strings:ss}));}
document.addEventListener("DOMContentLoaded",function(){tA(document);try{var to=null;function db(f){clearTimeout(to);to=setTimeout(f,80);}var mo=new MutationObserver(function(ms){ms.forEach(function(m){if(m.type==="childList"){for(var i=0;i<m.addedNodes.length;i++){var n=m.addedNodes[i];if(n&&n.nodeType===1){tA(n);}}}else if(m.type==="attributes"){var a=m.attributeName||"";if(a==="placeholder"||a==="title"||a==="aria-label"||a==="value"){db(function(){tA(m.target&&m.target.nodeType===1?m.target:document);});}}});});mo.observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:["placeholder","title","aria-label","value"]});}catch(e){}});
})();</script>';
});

/**
 * Ensure rewrite rules contain our language prefix rules; flush once if missing.
 */
add_action('init', function () {
    if (is_admin()) {
        return;
    }
    if (get_transient('ai_translate_rules_checked')) {
        return;
    }
    $rules = get_option('rewrite_rules');
    $has_lang_rule = false;
    if (is_array($rules)) {
        foreach ($rules as $regex => $target) {
            if (is_string($target) && (strpos($target, 'ai_lang=') !== false || strpos($target, 'lang=') !== false)) {
                $has_lang_rule = true;
                break;
            }
        }
    }
    set_transient('ai_translate_rules_checked', 1, DAY_IN_SECONDS);
    if (!$has_lang_rule) {
        // Flush once to persist currently-registered rules
        flush_rewrite_rules(false);
    }
}, 99);
/**
 * REST endpoint voor dynamische UI-attribuutvertaling (geen admin-ajax).
 */
add_action('rest_api_init', function(){
    register_rest_route('ai-translate/v1', '/batch-strings', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'args' => [],
        'callback' => function(\WP_REST_Request $request){
            $arr = $request->get_param('strings');
            if (!is_array($arr)) { $arr = []; }
            $texts = array_values(array_unique(array_filter(array_map(function($s){ return trim((string) $s); }, $arr))));
            $langParam = sanitize_key((string) ($request->get_param('lang') ?? ''));
            $lang = $langParam !== '' ? $langParam : \AITranslate\AI_Lang::current();
            $default = \AITranslate\AI_Lang::default();
            if ($lang === null || $default === null) { return new \WP_REST_Response(['success'=>true,'data'=>['map'=>[]]], 200); }
            if (strtolower($lang) === strtolower($default)) {
                $map = [];
                foreach ($texts as $t) { $map[$t] = $t; }
                return new \WP_REST_Response(['success'=>true,'data'=>['map'=>$map]], 200);
            }
            $settings = get_option('ai_translate_settings', array());
            $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14*24);
            $expiry = max(1, $expiry_hours) * HOUR_IN_SECONDS;
            $map = [];
            $toTranslate = [];
            $idMap = [];
            $i = 0;
            foreach ($texts as $t) {
                $key = 'ai_tr_attr_' . $lang . '_' . md5($t);
                $cached = get_transient($key);
                if ($cached !== false) {
                    $cachedText = (string) $cached;
                    $srcLen = mb_strlen($t);
                    $cacheInvalid = false;
                    
                    // Validate cache: check if translation is exactly identical to source
                    // This catches untranslated placeholders like "Voornaam", "Email Adres" etc.
                    // Only skip very short words (≤3 chars) like "van" → "van" which can be valid
                    if ($srcLen > 3 && trim($cachedText) === trim($t)) {
                        $cacheInvalid = true;
                    }
                    
                    // For non-Latin target languages: additional check for Latin ratio
                    // Only for longer texts to avoid false positives with brand names
                    if (!$cacheInvalid && mb_strlen($cachedText) > 100) {
                        $nonLatinLangs = ['zh', 'ja', 'ko', 'ar', 'he', 'th', 'ka'];
                        if (in_array($lang, $nonLatinLangs, true)) {
                            $latinCount = preg_match_all('/[a-zA-Z]/', $cachedText);
                            $latinRatio = mb_strlen($cachedText) > 0 ? ($latinCount / mb_strlen($cachedText)) : 0;
                            if ($latinRatio > 0.4) {
                                $cacheInvalid = true;
                            }
                        }
                    }
                    
                    if ($cacheInvalid) {
                        // Cache entry is invalid - delete it and re-translate
                        delete_transient($key);
                        $id = 's' . (++$i);
                        $toTranslate[$id] = $t;
                        $idMap[$id] = $t;
                    } else {
                        $map[$t] = $cachedText;
                    }
                } else {
                    $id = 's' . (++$i);
                    $toTranslate[$id] = $t;
                    $idMap[$id] = $t;
                }
            }
            if (!empty($toTranslate)) {
                $plan = ['segments'=>[]];
                foreach ($toTranslate as $id=>$text) {
                    // Use 'node' type for longer texts (full sentences), 'meta' for short UI strings
                    // Count words by splitting on whitespace
                    $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
                    $wordCount = count($words);
                    $segmentType = ($wordCount > 4) ? 'node' : 'meta';
                    $plan['segments'][] = ['id'=>$id, 'text'=>$text, 'type'=>$segmentType];
                }
                $ctx = ['website_context' => isset($settings['website_context']) ? (string)$settings['website_context'] : ''];
                $res = \AITranslate\AI_Batch::translate_plan($plan, $default, $lang, $ctx);
                $segs = isset($res['segments']) && is_array($res['segments']) ? $res['segments'] : array();
                foreach ($toTranslate as $id=>$orig) {
                    $tr = isset($segs[$id]) ? (string) $segs[$id] : $orig;
                    $map[$orig] = $tr;
                    set_transient('ai_tr_attr_' . $lang . '_' . md5($orig), $tr, $expiry);
                }
            }
            return new \WP_REST_Response(['success'=>true,'data'=>['map'=>$map]], 200);
        }
    ]);
});

/**
 * Ensure language-only URLs (e.g., /en/, /de/) route to the site's front page.
 */
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    $request_path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
    if (!is_string($request_path)) {
        $request_path = '/';
    }
    if (preg_match('#^/([a-z]{2})/?$#i', $request_path)) {
        // Mirror org behaviour: only force front when no other query vars (except 'lang').
        // Special-case: when theme uses ?blogpage or native 'paged', route to the posts page.
        $qv = is_array($wp->query_vars) ? $wp->query_vars : array();
        $blogPaged = isset($qv['blogpage']) ? (int)$qv['blogpage'] : (isset($_GET['blogpage']) ? (int) $_GET['blogpage'] : 0);
        $paged = isset($qv['paged']) ? (int)$qv['paged'] : (isset($_GET['paged']) ? (int) $_GET['paged'] : 0);
        if ($blogPaged > 0 || $paged > 0) {
            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id > 0) {
                $posts_page = get_post($posts_page_id);
                if ($posts_page) {
                    $existing = is_array($wp->query_vars) ? $wp->query_vars : array();
                    // Route to posts index by pointing to the posts page slug and paged
                    $wp->query_vars = array_merge($existing, array(
                        'pagename' => (string) get_page_uri($posts_page_id),
                        'paged' => $paged ?: $blogPaged,
                    ));
                    return;
                }
            }
            // If no posts page configured, fall back to home with pagination
            return;
        }
        $qv_no_lang = array_diff_key($qv, ['lang' => 1]);
        if (!empty($qv_no_lang)) {
            return;
        }
        $front_id = (int) get_option('page_on_front');
        if ($front_id > 0) {
            $front_post = get_post($front_id);
            if ($front_post) {
                // Merge, do not drop existing query vars (e.g., blogpage/paged)
                $existing = is_array($wp->query_vars) ? $wp->query_vars : array();
                $wp->query_vars = array_merge($existing, array(
                    'page_id' => $front_id,
                    'pagename' => (string) $front_post->post_name,
                    'post_type' => (string) $front_post->post_type,
                ));
                $wp->is_page = true;
                $wp->is_singular = true;
                $wp->is_home = false;
                $wp->is_404 = false;
            }
        }
    }
});

/**
 * Route /{lang}/page/{n} to the posts page with proper pagination.
 */
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
    if (!is_string($path) || $path === '/') {
        return;
    }
    if (!preg_match('#^/([a-z]{2})/page/([0-9]+)/?$#i', $path, $m)) {
        return;
    }
    $n = max(1, (int) $m[2]);
    $posts_page_id = (int) get_option('page_for_posts');
    $existing = is_array($wp->query_vars) ? $wp->query_vars : array();
    $wp->query_vars = $existing;
    $wp->query_vars['paged'] = $n;
    if ($posts_page_id > 0) {
        $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
        $wp->is_home = true;
        $wp->is_page = false;
        $wp->is_singular = false;
        $wp->is_404 = false;
    } else {
        $wp->is_home = true;
        $wp->is_404 = false;
    }
});

/**
 * Ensure /{lang}/?blogpage={n} resolves to the posts index with pagination.
 * Some themes use a custom query var (?blogpage). Map this early and point to posts page slug.
 */
// Removed parse_request handler for language root pagination (?blogpage / paged)

// Map translated paths like /{lang}/{translated-slug} to the original content (page or post)
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
    if (!is_string($path) || $path === '/') {
        return;
    }
    if (!preg_match('#^/([a-z]{2})(?:/(.*))?$#i', $path, $m)) {
        return;
    }
    $lang = strtolower($m[1]);
    $rest = isset($m[2]) ? (string) $m[2] : '';
    $rest = trim($rest, '/');
    if ($rest === '') {
        return; // handled by previous block (language root)
    }
    // Let the dedicated pagination handler manage /{lang}/page/{n}
    if (preg_match('#^page/[0-9]+/?$#i', $rest)) {
        return;
    }

    // Removed handlers for translated posts index pagination

    // Try exact mapping from translated path to post ID via slug map
    $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $rest);
    if ($post_id) {
        $post = get_post((int) $post_id);
        $wp->query_vars = array_diff_key($wp->query_vars, ['name'=>1, 'pagename'=>1, 'page_id'=>1, 'p'=>1, 'post_type'=>1]);
        $posts_page_id = (int) get_option('page_for_posts');
        if ($post && $post->post_type === 'page') {
            if ($posts_page_id > 0 && (int)$post_id === $posts_page_id) {
                // Treat posts page as home (blog index) so pagination works
                $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
                $wp->is_home = true;
                $wp->is_page = false;
                $wp->is_singular = false;
            } else {
                $wp->query_vars['page_id'] = (int) $post_id;
                $wp->is_page = true;
            }
        } else {
            $wp->query_vars['p'] = (int) $post_id;
            if ($post && !empty($post->post_type)) {
                $wp->query_vars['post_type'] = $post->post_type;
            }
            $wp->is_single = true;
        }
        $wp->is_404 = false;
        $wp->is_singular = true;
        return;
    }

    // Replicate org behaviour: strip current language from path and map basename back to source
    $default = \AITranslate\AI_Lang::default();
    $clean = preg_replace('#^' . preg_quote($lang, '#') . '/#i', '', ltrim($rest, '/'));
    $clean = trim($clean, '/');
    $basename = trim(basename($clean), '/');
    if ($basename !== '') {
        $srcBase = \AITranslate\AI_Slugs::resolve_any_to_source_slug($basename);
        if ($srcBase) {
            $dir = trim((string) dirname($clean), '/');
            if ($dir === '\\' || $dir === '.') { $dir = ''; }
            $sourcePath = $dir !== '' ? ($dir . '/' . $srcBase) : $srcBase;

            // Resolve across all public post types (page, post, CPT)
            $public_types = get_post_types(['public' => true], 'names');
            $post = get_page_by_path($sourcePath, OBJECT, array_values($public_types));
            if ($post) {
                $wp->query_vars = array_diff_key($wp->query_vars, ['name'=>1, 'pagename'=>1, 'page_id'=>1, 'p'=>1]);
                $posts_page_id = (int) get_option('page_for_posts');
                if ($post->post_type === 'page') {
                    if ($posts_page_id > 0 && (int)$post->ID === $posts_page_id) {
                        $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
                        $wp->is_home = true;
                        $wp->is_page = false;
                        $wp->is_singular = false;
                    } else {
                        $wp->query_vars['page_id'] = (int) $post->ID;
                        $wp->is_page = true;
                    }
                } else {
                    $wp->query_vars['p'] = (int) $post->ID;
                    $wp->query_vars['post_type'] = $post->post_type;
                    $wp->is_single = true;
                }
                $wp->is_singular = true;
                $wp->is_404 = false;
                return;
            }

            // Fallback: let WP try resolving as page path
            $wp->query_vars['pagename'] = $sourcePath;
            // Do not force 404 state here; allow core to resolve properly
        }
    }
});

/**
 * Rewrite internal permalinks (post/page) to stable translated slugs for current language.
 */
add_filter('post_link', function ($permalink, $post, $leavename) {
    if (is_admin()) return $permalink;
    $lang = \AITranslate\AI_Lang::current();
    $default = \AITranslate\AI_Lang::default();
    if ($lang === null || $default === null || strtolower($lang) === strtolower($default)) {
        return $permalink;
    }
    $translated = \AITranslate\AI_Slugs::get_or_generate((int) $post->ID, $lang);
    if ($translated === null) return $permalink;
    // Build path /{lang}/{translated-slug}/ respecting trailing slash (support Unicode slugs)
    $trail = substr($permalink, -1) === '/' ? '/' : '';
    $path = '/' . $lang . '/' . trim($translated, '/') . $trail;
    return home_url($path);
}, 10, 3);

add_filter('page_link', function ($permalink, $post_id, $sample) {
    if (is_admin()) return $permalink;
    $lang = \AITranslate\AI_Lang::current();
    $default = \AITranslate\AI_Lang::default();
    if ($lang === null || $default === null || strtolower($lang) === strtolower($default)) {
        return $permalink;
    }
    $translated = \AITranslate\AI_Slugs::get_or_generate((int) $post_id, $lang);
    if ($translated === null) return $permalink;
    $trail = substr($permalink, -1) === '/' ? '/' : '';
    $path = '/' . $lang . '/' . trim($translated, '/') . $trail;
    return home_url($path);
}, 10, 3);

/**
 * Keep translated slugs in sync when the original permalink (post_name) changes.
 * Only regenerates when the source slug actually changed to minimize API calls.
 */
add_action('save_post', function ($post_id, $post, $update) {
    static $processing = false;
    if ($processing) return;
    if (wp_is_post_revision($post_id)) return;
    if (!is_object($post) || $post->post_status !== 'publish') return;
    $current_slug = (string) $post->post_name;
    $stored = get_post_meta($post_id, '_ai_translate_original_slug', true);
    if ($stored === $current_slug && $stored !== '') return;
    $processing = true;
    update_post_meta($post_id, '_ai_translate_original_slug', $current_slug);
    $default = \AITranslate\AI_Lang::default();
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : array();
    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : array();
    $core = \AITranslate\AI_Translate_Core::get_instance();
    $available = array_keys($core->get_available_languages());
    $langs = array_values(array_unique(array_merge($enabled, $detectable, $available)));
    foreach ($langs as $lang) {
        $lang = sanitize_key((string) $lang);
        if ($default !== null && strtolower($lang) === strtolower($default)) continue;
        \AITranslate\AI_Slugs::get_or_generate((int) $post_id, $lang);
    }
    $processing = false;
}, 10, 3);

add_action('post_updated', function ($post_id, $post_after, $post_before) {
    if (!is_object($post_before) || !is_object($post_after)) return;
    if ((string) $post_before->post_name === (string) $post_after->post_name) return;
    // Direct call instead of do_action to avoid infinite loop
    $cb = function_exists('get_post') ? get_post($post_id) : $post_after;
    do_action_ref_array('save_post', array($post_id, $cb, true));
}, 10, 3);

/**
 * One-time warmup: generate translated slugs for existing top-level pages so
 * language-prefixed URLs immediately resolve (prevents blank pages before first visit).
 * Mirrors robustness in the original implementation by pre-seeding the slug map.
 */
add_action('init', function () {
    if (get_option('ai_translate_slug_warmup_done')) {
        return;
    }
    // Only run on front-end to avoid slowing down admin
    if (is_admin()) {
        return;
    }
    $settings = get_option('ai_translate_settings', array());
    $default = \AITranslate\AI_Lang::default();
    if ($default === null) {
        update_option('ai_translate_slug_warmup_done', 1);
        return;
    }
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : array();
    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : array();
    $langs = array_values(array_unique(array_merge($enabled, $detectable)));
    if (empty($langs)) {
        update_option('ai_translate_slug_warmup_done', 1);
        return;
    }
    // Warmup all published pages and posts so reverse mapping works immediately
    $pages = get_posts(array(
        'post_type' => array('page','post'),
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'orderby' => 'date',
        'order' => 'DESC',
    ));
    if (empty($pages)) {
        update_option('ai_translate_slug_warmup_done', 1);
        return;
    }
    foreach ($pages as $pid) {
        foreach ($langs as $lang) {
            $lang = sanitize_key((string) $lang);
            if (strtolower($lang) === strtolower($default)) {
                continue;
            }
            \AITranslate\AI_Slugs::get_or_generate((int) $pid, $lang);
        }
    }
    update_option('ai_translate_slug_warmup_done', 1);
}, 99);

/**
 * Intercept imminent 404s and remap translated slugs to source before WP renders a 404.
 * This mirrors original behaviour and fixes empty pages when rewrite did not bind.
 */
add_filter('pre_handle_404', function ($preempt, $wp_query) {
    if (!is_object($wp_query) || !$wp_query->is_main_query()) {
        return $preempt;
    }
    // Skip admin and default language
    if (\AITranslate\AI_Lang::is_exempt_request()) {
        return $preempt;
    }
    // Already resolved → nothing to do
    if (!$wp_query->is_404()) {
        return $preempt;
    }
    $reqPath = isset($_SERVER['REQUEST_URI']) ? (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    if ($reqPath === '' || !preg_match('#^/([a-z]{2})(?:/(.*))?$#i', $reqPath, $m)) {
        return $preempt;
    }
    $lang = strtolower($m[1]);
    $rest = isset($m[2]) ? (string) $m[2] : '';
    $rest = trim($rest, '/');
    if ($rest === '') {
        // Language root → front page
        $front_id = (int) get_option('page_on_front');
        if ($front_id > 0) {
            $wp_query->query_vars['page_id'] = $front_id;
            $wp_query->is_page = true;
            $wp_query->is_singular = true;
            $wp_query->is_home = false;
            $wp_query->is_404 = false;
            return true; // short-circuit 404
        }
        return $preempt;
    }
    // Try slug mapping
    $source = \AITranslate\AI_Slugs::resolve_any_to_source_slug($rest);
    if ($source) {
        $wp_query->query_vars = array_diff_key($wp_query->query_vars, ['name'=>1, 'pagename'=>1]);
        $wp_query->query_vars['pagename'] = $source;
        $wp_query->is_404 = false;
        $wp_query->is_page = true;
        $wp_query->is_singular = true;
        return true;
    }
    return $preempt;
}, 10, 2);

/**
 * One-time flush of rewrite rules after deployment so language rules become active
 * without manual intervention. Safe due to option flag.
 */
add_action('init', function () {
    if (!get_option('ai_translate_rules_flushed_v1')) {
        flush_rewrite_rules(false);
        update_option('ai_translate_rules_flushed_v1', 1);
    }
}, 20);

// Ensure new rewrite rules are flushed once after deployment changes (CPT rules added)
add_action('init', function () {
    if (!get_option('ai_translate_rules_flushed_v2')) {
        flush_rewrite_rules(false);
        update_option('ai_translate_rules_flushed_v2', 1);
    }
}, 21);

/**
 * Add Settings quick link on the Plugins page.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=ai-translate');
    $settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Instellingen', 'ai-translate') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});


/**
 * Translate search query into the default language before running WP_Query,
 * and keep the displayed search term as originally entered by the user.
 */
add_action('pre_get_posts', function ($query) {
    if (!is_object($query) || !method_exists($query, 'is_main_query')) {
        return;
    }
    if (is_admin() || !$query->is_main_query() || !$query->is_search()) {
        return;
    }
    // Skip when current request is exempt (admin/default language etc.)
    if (\AITranslate\AI_Lang::is_exempt_request()) {
        return;
    }

    $s = $query->get('s');
    if (!is_string($s)) {
        return;
    }
    $s = trim((string) wp_unslash($s));
    if ($s === '') {
        return;
    }

    $original = sanitize_text_field($s);
    // Preserve original for display on results templates
    $GLOBALS['ai_translate_original_search_query'] = $original;

    $current = \AITranslate\AI_Lang::current();
    $default = \AITranslate\AI_Lang::default();
    if ($current === null || $default === null || strtolower($current) === strtolower($default)) {
        return;
    }

    $settings = get_option('ai_translate_settings', array());
    $ctx = array(
        'website_context' => isset($settings['website_context']) ? (string) $settings['website_context'] : '',
    );
    $plan = array('segments' => array(
        array('id' => 'q1', 'text' => $original, 'type' => 'meta'),
    ));
    $res = \AITranslate\AI_Batch::translate_plan($plan, $current, $default, $ctx);
    $translated = '';
    if (is_array($res) && isset($res['segments']) && is_array($res['segments'])) {
        $translated = (string) ($res['segments']['q1'] ?? '');
    }
    if ($translated !== '') {
        $query->set('s', $translated);
    }
}, 9);

/**
 * Ensure the visible search query remains the user's original input (not the translated term).
 */
add_filter('get_search_query', function ($search, $escaped = true) {
    if (is_admin()) {
        return $search;
    }
    if (isset($GLOBALS['ai_translate_original_search_query'])) {
        $orig = (string) $GLOBALS['ai_translate_original_search_query'];
        return $escaped ? esc_attr($orig) : $orig;
    }
    return $search;
}, 10, 2);

