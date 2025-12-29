<?php

/**
 * Plugin Name: AI Translate
 * Description: AI based translation plugin. Adding 35 languages in a few clicks. 
 * Author: Netcare
 * Author URI: https://netcare.nl/
 * Version: 2.1.7
 * Requires PHP: 8.0.0
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
    function ai_translate_dbg($message, $context = array())
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        $contextStr = !empty($context) ? ' | ' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
        error_log('[AI-Translate] ' . $message . $contextStr);
    }
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

// Ensure slug map table exists early in init
add_action('init', function () {
    \AITranslate\AI_Slugs::install_table();
}, 1);



// Add original-style language-prefixed rewrite rules using 'lang' query var to ensure WP resolves pages via pagename
add_action('init', function () {
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : array();
    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : array();
    $default = isset($settings['default_language']) ? (string) $settings['default_language'] : '';
    $langs = array_values(array_unique(array_merge($enabled, $detectable)));
    if ($default !== '') {
        $langs = array_diff($langs, array($default));
    }
    $langs = array_filter(array_map('sanitize_key', $langs));
    if (empty($langs)) return;
    $regex = '(' . implode('|', array_map(function ($l) {
        return preg_quote($l, '/');
    }, $langs)) . ')';
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
    if (!in_array('lang', $vars, true)) {
        $vars[] = 'lang';
    }
    return $vars;
});

/**
 * Handle language switch via switcher early in init (before template_redirect)
 */
add_action('init', function () {
    // Skip admin/AJAX/REST
    if (is_admin() || wp_doing_ajax() || wp_is_json_request()) {
        return;
    }

    // Only handle switch_lang parameter - nothing else
    $switchLang = isset($_GET['switch_lang']) ? strtolower(sanitize_key((string) $_GET['switch_lang'])) : '';
    if ($switchLang === '') {
        return;
    }

    // Set cookie
    $secure = is_ssl();
    $sameSite = $secure ? 'None' : 'Lax';
    setcookie('ai_translate_lang', $switchLang, [
        'expires' => time() + 30 * 86400,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $sameSite
    ]);
    $_COOKIE['ai_translate_lang'] = $switchLang;

    // Redirect to clean URL (remove switch_lang parameter)
    $defaultLang = \AITranslate\AI_Lang::default();
    $targetUrl = ($switchLang === strtolower((string) $defaultLang)) ? home_url('/') : home_url('/' . $switchLang . '/');
    
    wp_safe_redirect($targetUrl, 302);
    exit;
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
    // Ensure rules are registered before flushing
    do_action('init');

    // Automatically set permalinks to 'post-name' if they're currently 'plain'
    // since the plugin requires rewrite rules to function
    $permalink_structure = get_option('permalink_structure');
    if ($permalink_structure === '' || $permalink_structure === null) {
        // Set to 'post-name' structure which is /%postname%/
        update_option('permalink_structure', '/%postname%/');
    }

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
 * Check if current request is an XML file (sitemap, robots.txt, etc.).
 *
 * @return bool
 */
function ai_translate_is_xml_request()
{
    $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

    // Check if path ends with .xml
    if (preg_match('/\.xml$/i', $reqPath)) {
        return true;
    }

    // Check for WordPress sitemap query vars
    if (isset($_GET['sitemap']) || isset($_GET['sitemap-index'])) {
        return true;
    }

    // Check for sitemap in path (e.g., /wp-sitemap.xml, /sitemap.xml)
    if (preg_match('/sitemap/i', $reqPath)) {
        return true;
    }

    return false;
}

/**
 * Start output buffering for front-end (skip admin, AJAX, REST, feeds and XML files).
 * Handles language detection and redirection according to the 4 language switcher rules.
 */
add_action('template_redirect', function () {
    // Skip admin/AJAX/REST/feeds/XML files
    if (is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || ai_translate_is_xml_request()) {
        return;
    }

    $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $defaultLang = \AITranslate\AI_Lang::default();

    // Check if we just switched languages - switcher has HIGHEST priority!
    // Block removed because it ignored URL language. Standard logic below handles it.


    // Extract language from URL
    $langFromUrl = null;
    if ($reqPath !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $reqPath, $m)) {
        $langFromUrl = strtolower(sanitize_key($m[1]));
        
        // SECURITY: Prevent unauthorized translation by blocking non-enabled/non-detectable languages
        // If language is in URL but not enabled or detectable, return 404 to prevent API costs
        if ($langFromUrl !== null && $defaultLang && strtolower($langFromUrl) !== strtolower((string) $defaultLang)) {
            $enabled = \AITranslate\AI_Lang::enabled();
            $detectable = \AITranslate\AI_Lang::detectable();
            $langLower = strtolower($langFromUrl);
            $isEnabled = in_array($langLower, array_map('strtolower', $enabled), true);
            $isDetectable = in_array($langLower, array_map('strtolower', $detectable), true);
            
            // If language is not enabled and not detectable, return 404 to prevent unauthorized translation
            if (!$isEnabled && !$isDetectable) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
                return;
            }
            
            // SECURITY: Prevent DOS attack by blocking language/slug mismatches
            // If URL contains non-Latin characters (Korean, Chinese, Japanese, Arabic, etc.)
            // but language code is Latin-based, this is likely an attack
            $pathWithoutLang = preg_replace('#^/([a-z]{2})/#i', '/', $reqPath);
            if ($pathWithoutLang !== $reqPath) {
                // Check if path contains non-Latin characters
                $hasNonLatin = preg_match('/[\x{0080}-\x{FFFF}]/u', urldecode($pathWithoutLang));
                
                if ($hasNonLatin) {
                    // Define character set mappings for languages
                    $nonLatinLangs = ['zh', 'ja', 'ko', 'ar', 'he', 'th', 'ka', 'ru', 'uk', 'bg', 'el', 'hi', 'bn', 'ta', 'te', 'ml', 'kn', 'gu', 'pa', 'ur', 'fa', 'ps', 'sd', 'ug', 'kk', 'ky', 'uz', 'mn', 'my', 'km', 'lo', 'ne', 'si', 'dz', 'bo', 'ti', 'am', 'hy', 'az', 'be', 'mk', 'sr', 'hr', 'bs', 'sq', 'mt', 'is', 'fo', 'cy', 'ga', 'gd', 'yi', 'yi'];
                    $isNonLatinLang = in_array($langLower, $nonLatinLangs, true);
                    
                    // If path has non-Latin characters but language code is Latin-based, block it
                    if (!$isNonLatinLang) {
                        global $wp_query;
                        $wp_query->set_404();
                        status_header(404);
                        nocache_headers();
                        return;
                    }
                }
            }
        }
    }

    $cookieLang = isset($_COOKIE['ai_translate_lang']) ? strtolower(sanitize_key((string) $_COOKIE['ai_translate_lang'])) : '';

    // Ensure search requests have language-prefixed URL (before RULE 4 to prevent early return)
    // Check for search parameter 's' in query string
    $hasSearchParam = isset($_GET['s']) && trim((string) $_GET['s']) !== '';
    if ($hasSearchParam) {
        // Use cookie language if available, otherwise use URL language, otherwise default
        $searchLang = $cookieLang !== '' ? $cookieLang : ($langFromUrl !== null ? $langFromUrl : (string) $defaultLang);
        if ($searchLang !== '' && $defaultLang && strtolower($searchLang) !== strtolower((string) $defaultLang)) {
            $pathNow = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
            if ($pathNow === '' || $pathNow === '/' || !preg_match('#^/([a-z]{2})(?:/|$)#i', $pathNow)) {
                $base = home_url('/' . $searchLang . '/');
                $target = add_query_arg($_GET, $base);
                wp_safe_redirect($target, 302);
                exit;
            }
        }
    }

    // RULE 3: Canonicalize default language - /nl/ → /
    if ($langFromUrl !== null && $defaultLang && strtolower($langFromUrl) === strtolower((string) $defaultLang)) {
        if ($reqPath !== '/') {
            wp_safe_redirect(home_url('/'), 302);
            exit;
        }
    }

    // RULE 4: If cookie exists and on root, ensure language is set to default
    // Skip this rule if there's a search parameter (handled above)
    if ($reqPath === '/' && $cookieLang !== '' && !$hasSearchParam) {
        $secure = is_ssl();
        $sameSite = $secure ? 'None' : 'Lax';
        setcookie('ai_translate_lang', (string) $defaultLang, [
            'expires' => time() + 30 * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite
        ]);
        $_COOKIE['ai_translate_lang'] = (string) $defaultLang;
        // Set language and stop processing
        $finalLang = (string) $defaultLang;
        \AITranslate\AI_Lang::set_current($finalLang);
        add_filter('locale', function ($locale) {
            $currentLang = \AITranslate\AI_Lang::current();
            if ($currentLang) {
                return strtolower($currentLang) . '_' . strtoupper($currentLang);
            }
            return $locale;
        });
        \AITranslate\AI_OB::instance()->start();
        return;
    }

    $resolvedLang = null;

    // RULE 1: First visit (no cookie) on root - detect browser language and redirect
    if ($reqPath === '/' && $cookieLang === '') {
        // True first visit - detect browser language
        \AITranslate\AI_Lang::reset();
        \AITranslate\AI_Lang::detect();
        $detected = \AITranslate\AI_Lang::current();
        if (!$detected) {
            $detected = (string) $defaultLang;
        }

        $resolvedLang = $detected;

        // Set cookie
        $secure = is_ssl();
        $sameSite = $secure ? 'None' : 'Lax';
        setcookie('ai_translate_lang', $detected, [
            'expires' => time() + 30 * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite
        ]);
        $_COOKIE['ai_translate_lang'] = $detected;

        // Redirect to language URL if not default
        if ($detected && strtolower($detected) !== strtolower((string) $defaultLang)) {
            wp_safe_redirect(home_url('/' . $detected . '/'), 302);
            exit;
        }
        // If detected == default, no redirect needed, just set language below
    } elseif ($reqPath === '/' && $cookieLang !== '') {
        // Returning visitor on root: respect stored preference
        $resolvedLang = $cookieLang;

        if ($defaultLang && strtolower($cookieLang) !== strtolower((string) $defaultLang)) {
            wp_safe_redirect(home_url('/' . $cookieLang . '/'), 302);
            exit;
        }
    }

    // Sync cookie if URL has language prefix
    if ($langFromUrl !== null && ($cookieLang === '' || $cookieLang !== $langFromUrl)) {
        $secure = is_ssl();
        $sameSite = $secure ? 'None' : 'Lax';
        setcookie('ai_translate_lang', $langFromUrl, [
            'expires' => time() + 30 * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite
        ]);
        $_COOKIE['ai_translate_lang'] = $langFromUrl;
    }

    // Set language for content
    $finalLang = $resolvedLang;
    if ($finalLang === null) {
        $finalLang = isset($_COOKIE['ai_translate_lang']) ? strtolower(sanitize_key((string) $_COOKIE['ai_translate_lang'])) : (string) $defaultLang;
    }
    if ($finalLang === '') {
        $finalLang = (string) $defaultLang;
    }
    $finalLang = strtolower(sanitize_key((string) $finalLang));
    if ($finalLang !== $cookieLang && $finalLang !== '') {
        // Keep cookie aligned with the resolved language
        $secure = is_ssl();
        $sameSite = $secure ? 'None' : 'Lax';
        setcookie('ai_translate_lang', $finalLang, [
            'expires' => time() + 30 * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite
        ]);
        $_COOKIE['ai_translate_lang'] = $finalLang;
    }

    \AITranslate\AI_Lang::set_current($finalLang);

    // Set WordPress locale
    $localeSetter = function ($locale) use ($finalLang) {
        if ($finalLang) {
            return strtolower($finalLang) . '_' . strtoupper($finalLang);
        }
        return $locale;
    };
    add_filter('locale', $localeSetter);

    // Handle full_reset parameter for testing
    if (isset($_GET['full_reset']) && $_GET['full_reset'] === '1') {
        $secure = is_ssl();
        $sameSite = $secure ? 'None' : 'Lax';
        setcookie('ai_translate_lang', (string) $defaultLang, [
            'expires' => time() + 30 * DAY_IN_SECONDS,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => $sameSite
        ]);
        $_COOKIE['ai_translate_lang'] = (string) $defaultLang;
        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    // Ensure search requests have language-prefixed URL (fallback for is_search() check)
    if (function_exists('is_search') && is_search()) {
        $cur = \AITranslate\AI_Lang::current();
        $def = \AITranslate\AI_Lang::default();
        $pathNow = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($cur && $def && strtolower($cur) !== strtolower($def)) {
            if ($pathNow === '' || $pathNow === '/' || !preg_match('#^/([a-z]{2})(?:/|$)#i', $pathNow)) {
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
    if ($path === '') {
        $path = '/';
    }
    $pathNoLang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
    if ($pathNoLang === '') {
        $pathNoLang = '/';
    }

    $flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';

    // Get switcher position from settings (default: bottom-left)
    $position = isset($settings['switcher_position']) ? $settings['switcher_position'] : 'bottom-left';
    $valid_positions = array('bottom-left', 'bottom-right', 'top-left', 'top-right');
    if (!in_array($position, $valid_positions, true)) {
        $position = 'bottom-left';
    }

    // Determine CSS based on position
    $container_css = '';
    $menu_css = '';
    
    if (strpos($position, 'bottom') === 0) {
        // Bottom positions: menu opens upward
        $container_css .= 'bottom:20px;';
        if ($position === 'bottom-left') {
            $container_css .= 'left:20px;';
            $menu_css .= 'bottom:100%;left:0;margin-bottom:8px;';
        } else {
            $container_css .= 'right:20px;';
            $menu_css .= 'bottom:100%;right:0;margin-bottom:8px;';
        }
    } else {
        // Top positions: menu opens downward
        $container_css .= 'top:20px;';
        if ($position === 'top-left') {
            $container_css .= 'left:20px;';
            $menu_css .= 'top:100%;left:0;margin-top:8px;';
        } else {
            $container_css .= 'right:20px;';
            $menu_css .= 'top:100%;right:0;margin-top:8px;';
        }
    }

    // Inline minimal CSS with dynamic positioning (namespaced classes to avoid theme conflicts)
    echo '<style>.ai-trans{position:fixed;' . esc_attr($container_css) . 'z-index:2147483000}.ai-trans .ai-trans-btn{display:inline-flex;align-items:center;justify-content:center;gap:4px;padding:8px 12px;border-radius:24px;border:none;background:#1e3a8a;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.2);cursor:pointer;font-size:13px;font-weight:600}.ai-trans .ai-trans-btn img{width:20px;height:14px;border-radius:2px}.ai-trans .ai-trans-menu{position:absolute;' . esc_attr($menu_css) . 'background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.15);padding:8px;display:none;min-width:140px}.ai-trans.ai-trans-open .ai-trans-menu{display:block}.ai-trans .ai-trans-item{display:flex;align-items:center;gap:8px;padding:8px 10px;text-decoration:none;color:#222;border-radius:6px;font-size:13px}.ai-trans .ai-trans-item:hover{background:#f3f4f6}.ai-trans .ai-trans-item img{width:20px;height:14px;border-radius:2px}</style>';

    // Current language (from URL or default)
    $currentLang = null;
    if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $m)) {
        $currentLang = strtolower($m[1]);
    }
    if (!$currentLang) {
        $currentLang = $default;
    }
    $currentFlag = esc_url($flags_url . sanitize_key($currentLang) . '.png');

    echo '<div id="ai-trans" class="ai-trans">';
    // Show current language flag with code label
    echo '<button type="button" class="ai-trans-btn" aria-haspopup="true" aria-expanded="false" aria-controls="ai-trans-menu" title="' . esc_attr(strtoupper($currentLang)) . '"><img src="' . $currentFlag . '" alt="' . esc_attr($currentLang) . '"><span>' . esc_html(strtoupper($currentLang)) . '</span></button>';
    echo '<div id="ai-trans-menu" class="ai-trans-menu" role="menu">';

    foreach ($enabled as $code) {
        $code = sanitize_key($code);
        $label = strtoupper($code === $default ? $default : $code);
        // Detect current domain from HTTP_HOST to support multi-domain setup
        // Detect protocol from current request (HTTP or HTTPS)
        // Default taal: /{default}/; andere talen: /{code}/
        $currentHost = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
        if ($currentHost === '') {
            // Fallback to home_url() if HTTP_HOST is not available
            $currentHost = parse_url(home_url(), PHP_URL_HOST);
        }
        // Detect protocol from current request
        $protocol = is_ssl() ? 'https' : 'http';
        $targetPath = ($code === $default) ? ('/' . $default . '/') : ('/' . $code . '/');
        $targetPath = preg_replace('#/{2,}#', '/', $targetPath);
        $url = $protocol . '://' . $currentHost . $targetPath;
        $url = esc_url($url);
        $flag = esc_url($flags_url . $code . '.png');
        echo '<a class="ai-trans-item" href="' . $url . '" role="menuitem" data-lang="' . esc_attr($code) . '" data-ai-trans-skip="1"><img src="' . $flag . '" alt="' . esc_attr($label) . '"><span>' . esc_html($label) . '</span></a>';
    }

    echo '</div></div>';

    // Minimal toggle script + pure JS cookie handling
    $restUrl = esc_url_raw(rest_url('ai-translate/v1/batch-strings'));
    $nonce = wp_create_nonce('ai_translate_front_nonce');
    $is_ssl = is_ssl() ? 'true' : 'false';
    echo '<script>(function(){var w=document.getElementById("ai-trans");if(!w)return;var b=w.querySelector(".ai-trans-btn");b.addEventListener("click",function(e){e.stopPropagation();var open=w.classList.toggle("ai-trans-open");b.setAttribute("aria-expanded",open?"true":"false")});document.addEventListener("click",function(e){if(!w.contains(e.target)){w.classList.remove("ai-trans-open");b.setAttribute("aria-expanded","false")}});var AI_TA={u:"' . $restUrl . '",n:"' . esc_js($nonce) . '"};
// Dynamic UI attribute translation (placeholder/title/aria-label/value of buttons)
function gL(){try{var m=location.pathname.match(/^\/([a-z]{2})(?:\/|$)/i);if(m){return (m[1]||"").toLowerCase();}var mc=document.cookie.match(/(?:^|; )ai_translate_lang=([^;]+)/);if(mc){return decodeURIComponent(mc[1]||"").toLowerCase();}}catch(e){}return "";}
function cS(r){function n(t){return t?t.trim().replace(/\s+/g," "):""}var s=new Set();var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label],.initial-greeting,.chatbot-bot-text"):[];ns.forEach(function(el){if(el.hasAttribute("data-ai-trans-skip"))return;var ph=n(el.getAttribute("placeholder"));if(ph)s.add(ph);var tl=n(el.getAttribute("title"));if(tl)s.add(tl);var al=n(el.getAttribute("aria-label"));if(al)s.add(al);var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=n(el.getAttribute("value"));if(v)s.add(v);}}var tc=el.textContent;if((el.classList.contains("initial-greeting")||el.classList.contains("chatbot-bot-text"))&&tc){var tcn=n(tc);if(tcn)s.add(tcn);}});return Array.from(s);} 
 function aT(r,m){var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label],.initial-greeting,.chatbot-bot-text"):[];ns.forEach(function(el){if(el.hasAttribute("data-ai-trans-skip"))return;var ph=el.getAttribute("placeholder");if(ph){var pht=ph.trim();if(pht&&m[pht]!=null)el.setAttribute("placeholder",m[pht]);}var tl=el.getAttribute("title");if(tl){var tlt=tl.trim();if(tlt&&m[tlt]!=null)el.setAttribute("title",m[tlt]);}var al=el.getAttribute("aria-label");if(al){var alt=al.trim();if(alt&&m[alt]!=null)el.setAttribute("aria-label",m[alt]);}var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=el.getAttribute("value");if(v){var vt=v.trim();if(vt&&m[vt]!=null)el.setAttribute("value",m[vt]);}}}var tc=el.textContent;if((el.classList.contains("initial-greeting")||el.classList.contains("chatbot-bot-text"))&&tc){var tct=tc.trim();if(tct&&m[tct]!=null)el.textContent=m[tct];}});} 
 function tA(r){if(tA.called)return;tA.called=true;var ss=cS(r);if(!ss.length){tA.called=false;return;}var x=new XMLHttpRequest();x.open("POST",AI_TA.u,true);x.setRequestHeader("Content-Type","application/json; charset=UTF-8");x.onreadystatechange=function(){if(x.readyState===4){tA.called=false;if(x.status===200){try{var resp=JSON.parse(x.responseText);if(resp&&resp.success&&resp.data&&resp.data.map){aT(r,resp.data.map);}}catch(e){}}}};x.send(JSON.stringify({nonce:AI_TA.n,lang:gL(),strings:ss}));}
document.addEventListener("DOMContentLoaded",function(){var checkPage=function(){if(document.readyState==="complete"){setTimeout(function(){tA(document);},3000);}else{setTimeout(checkPage,100);}};checkPage();});
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
add_action('rest_api_init', function () {
    register_rest_route('ai-translate/v1', '/batch-strings', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'args' => [],
        'callback' => function (\WP_REST_Request $request) {
            $arr = $request->get_param('strings');
            if (!is_array($arr)) {
                $arr = [];
            }
            // Normalize texts: trim and collapse multiple whitespace to single space
            // Keep mapping between original and normalized for response
            // Multiple originals can map to same normalized (e.g., "Naam " and "Naam" both become "Naam")
            $textsNormalized = [];
            $textsOriginal = [];
            foreach ($arr as $s) {
                $original = trim((string) $s);
                if ($original === '') {
                    continue;
                }
                $normalized = preg_replace('/\s+/u', ' ', $original);
                // Store normalized text (deduplicate)
                if (!isset($textsNormalized[$normalized])) {
                    $textsNormalized[$normalized] = $normalized;
                }
                // Map normalized to original (keep first occurrence, but all originals mapping to same normalized will work)
                if (!isset($textsOriginal[$normalized])) {
                    $textsOriginal[$normalized] = $original;
                }
            }
            $texts = array_values($textsNormalized);
            $langParam = sanitize_key((string) ($request->get_param('lang') ?? ''));
            // Always use lang parameter from JavaScript if provided, as it has the correct page context
            // Only fall back to Referer/current() if lang param is missing (shouldn't happen in normal operation)
            if ($langParam !== '') {
                $lang = $langParam;
            } else {
                // Fallback: try to detect from Referer header if available (more reliable than current() for REST calls)
                $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
                if ($referer !== '' && preg_match('#/([a-z]{2})(?:/|$)#i', parse_url($referer, PHP_URL_PATH) ?: '', $m)) {
                    $lang = strtolower($m[1]);
                } else {
                    $lang = \AITranslate\AI_Lang::current();
                }
            }
            $default = \AITranslate\AI_Lang::default();
            
            // Log if lang parameter doesn't match expected page language (for debugging)
            if ($langParam !== '' && $langParam !== $lang) {
                \ai_translate_dbg('Batch-strings language mismatch', [
                    'lang_param' => $langParam,
                    'detected_lang' => $lang,
                    'referer' => isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '',
                    'uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : ''
                ]);
            }
            
            if ($lang === null || $default === null) {
                return new \WP_REST_Response(['success' => true, 'data' => ['map' => []]], 200);
            }
            if (strtolower($lang) === strtolower($default)) {
                $map = [];
                foreach ($textsNormalized as $normalized => $orig) {
                    $originalText = isset($textsOriginal[$normalized]) ? $textsOriginal[$normalized] : $normalized;
                    $map[$originalText] = $originalText;
                }
                return new \WP_REST_Response(['success' => true, 'data' => ['map' => $map]], 200);
            }
            $settings = get_option('ai_translate_settings', array());
            $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14 * 24);
            $expiry = max(1, $expiry_hours) * HOUR_IN_SECONDS;
            
            // Check if translations are stopped (except for cache invalidation)
            // For batch-strings, we block ALL new translations when stop_translations is enabled
            // This is different from page translations where we allow cache invalidation
            // Batch-strings are UI attributes that should not be translated if stop_translations is enabled
            $stop_translations = isset($settings['stop_translations_except_cache_invalidation']) ? (bool) $settings['stop_translations_except_cache_invalidation'] : false;
            
            if ($stop_translations) {
                \ai_translate_dbg('Stop translations enabled for batch-strings - blocking all new translations', [
                    'lang' => $lang,
                    'uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : ''
                ]);
            }
            
            $map = [];
            $toTranslate = [];
            $idMap = [];
            $i = 0;
            $cacheHits = 0;
            $cacheMisses = 0;
            foreach ($texts as $t) {
                // $t is already normalized from the array_map above
                $normalized = (string) $t;
                
                // Skip empty texts
                if ($normalized === '') {
                    continue;
                }
                
                // Check if text is already in target language (identical to what translation would be)
                // If so, skip API call and use source text directly
                // This prevents unnecessary API calls for texts already in target language
                $originalText = isset($textsOriginal[$normalized]) ? $textsOriginal[$normalized] : $normalized;
                
                // Simple heuristic: if source text looks like it's already in target language,
                // use it directly without API call. This works for all languages.
                // We check if the text contains characters typical of the target language
                $isAlreadyInTargetLang = false;
                if ($lang !== $default) {
                    // For non-default languages, check if text contains target language specific patterns
                    // This is a simple heuristic - if text already looks translated, use it as-is
                    // We'll rely on the fact that if text is identical after "translation", it was already correct
                    // But we need to detect this BEFORE the API call to avoid unnecessary calls
                    // For now, we'll let it go through cache/API, but we'll handle identical results after API
                }
                
                // Check both cache locations:
                // 1. ai_tr_attr_* (JavaScript batch-strings cache)
                // 2. ai_tr_seg_* (PHP translation plan cache, format: ai_tr_seg_{lang}_{md5('attr|md5(text)')})
                $cached = false;
                $attrCacheKey = 'ai_tr_attr_' . $lang . '_' . md5($normalized);
                $cached = ai_translate_get_attr_transient($attrCacheKey);
                
                // If not found in JavaScript cache, check PHP translation plan cache
                if ($cached === false) {
                    $segKey = 'attr|' . md5($normalized);
                    $segCacheKey = 'ai_tr_seg_' . $lang . '_' . md5($segKey);
                    $cached = get_transient($segCacheKey);
                }
                
                if ($cached !== false) {
                    $cachedText = (string) $cached;
                    $cachedTextNormalized = trim($cachedText);
                    $cachedTextNormalized = preg_replace('/\s+/u', ' ', $cachedTextNormalized);
                    $srcLen = mb_strlen($normalized);
                    $cacheInvalid = false;

                    // If stop_translations is enabled, always use cache (don't validate)
                    if (!$stop_translations) {
                        // Validate cache: check if translation is exactly identical to source
                        // NOTE: Identical translations are now accepted as valid (text was already in target language)
                        // This prevents unnecessary API calls for texts already in target language
                        // We only invalidate if text is very short (likely a placeholder) or if it's clearly wrong
                        // For texts > 3 chars, identical translation means text was already in target language - accept it
                        if ($srcLen > 3 && $cachedTextNormalized === $normalized) {
                            // Accept identical translations as valid - text was already in target language
                            // This works for all languages without language-specific checks
                            $cacheInvalid = false;
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
                    }

                    if ($cacheInvalid && !$stop_translations) {
                        // Cache entry is invalid - delete it and re-translate
                        ai_translate_delete_attr_transient($attrCacheKey);
                        $id = 's' . (++$i);
                        $toTranslate[$id] = $normalized;
                        $idMap[$id] = $normalized;
                        $cacheMisses++;
                    } else {
                        // Use original text as key for JavaScript compatibility
                        $originalText = isset($textsOriginal[$normalized]) ? $textsOriginal[$normalized] : $normalized;
                        $map[$originalText] = $cachedText;
                        $cacheHits++;
                    }
                } else {
                    // Text not in cache
                    if ($stop_translations) {
                        // Stop translations enabled: use source text without API call
                        $originalText = isset($textsOriginal[$normalized]) ? $textsOriginal[$normalized] : $normalized;
                        $map[$originalText] = $originalText;
                        $cacheHits++;
                    } else {
                        // Text not in cache - check if it's already in target language before API call
                        // If source text is already in target language, use it directly without API call
                        // This prevents unnecessary API calls for texts already in target language
                        $srcLen = mb_strlen($normalized);
                        $isAlreadyInTargetLang = false;
                        
                        // Simple check: if text is identical after normalization and longer than 3 chars,
                        // it might already be in target language. But we can't know for sure without API.
                        // However, if API returns identical text, we'll handle it after API call.
                        // For now, we'll send it to API but handle identical results specially.
                        
                        $id = 's' . (++$i);
                        $toTranslate[$id] = $normalized;
                        $idMap[$id] = $normalized;
                        $cacheMisses++;
                    }
                }
            }
            if (!empty($toTranslate)) {
                if ($stop_translations) {
                    // Stop translations enabled: block API calls, use source texts
                    \ai_translate_dbg('Batch-strings API call blocked: stop_translations enabled', [
                        'lang' => $lang,
                        'uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                        'num_segments_blocked' => count($toTranslate)
                    ]);
                    // Use source texts for all segments that would have been translated
                    foreach ($toTranslate as $id => $origNormalized) {
                        $originalText = isset($textsOriginal[$origNormalized]) ? $textsOriginal[$origNormalized] : $origNormalized;
                        $map[$originalText] = $originalText;
                    }
                    // Clear $toTranslate to prevent API call
                    $toTranslate = [];
                }
            }
            
            // Only make API call if there are still segments to translate and stop_translations is not enabled
            if (!empty($toTranslate) && !$stop_translations) {
                    $plan = ['segments' => []];
                    foreach ($toTranslate as $id => $text) {
                        // Use 'node' type for longer texts (full sentences), 'meta' for short UI strings
                        // Count words by splitting on whitespace
                        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
                        $wordCount = count($words);
                        $segmentType = ($wordCount > 4) ? 'node' : 'meta';
                        $plan['segments'][] = ['id' => $id, 'text' => $text, 'type' => $segmentType];
                    }
                    $ctx = ['website_context' => isset($settings['website_context']) ? (string)$settings['website_context'] : ''];
                    $res = \AITranslate\AI_Batch::translate_plan($plan, $default, $lang, $ctx);
                    $segs = isset($res['segments']) && is_array($res['segments']) ? $res['segments'] : array();
                    foreach ($toTranslate as $id => $origNormalized) {
                        $tr = isset($segs[$id]) ? (string) $segs[$id] : $origNormalized;
                        // Use original text as key for JavaScript compatibility
                        $originalText = isset($textsOriginal[$origNormalized]) ? $textsOriginal[$origNormalized] : $origNormalized;
                        $map[$originalText] = $tr;
                        // Store in cache using normalized version for consistency
                        // Even if translation is identical to source (text was already in target language),
                        // we cache it so it won't be sent to API again
                        $cacheKey = 'ai_tr_attr_' . $lang . '_' . md5($origNormalized);
                        $trNormalized = trim($tr);
                        $trNormalized = preg_replace('/\s+/u', ' ', $trNormalized);
                        $isIdentical = ($trNormalized === $origNormalized && mb_strlen($origNormalized) > 3);
                        ai_translate_set_attr_transient($cacheKey, $tr, $expiry);
                    }
            }
            return new \WP_REST_Response(['success' => true, 'data' => ['map' => $map]], 200);
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
        $wp->query_vars = array_diff_key($wp->query_vars, ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
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
            if ($dir === '\\' || $dir === '.') {
                $dir = '';
            }
            $sourcePath = $dir !== '' ? ($dir . '/' . $srcBase) : $srcBase;

            // Resolve across all public post types (page, post, CPT)
            $public_types = get_post_types(['public' => true], 'names');
            $post = get_page_by_path($sourcePath, OBJECT, array_values($public_types));
            if ($post) {
                $wp->query_vars = array_diff_key($wp->query_vars, ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1]);
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
        'post_type' => array('page', 'post'),
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
        $wp_query->query_vars = array_diff_key($wp_query->query_vars, ['name' => 1, 'pagename' => 1]);
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
 * Modify search form action URL to include language code when on a language-prefixed page.
 * This ensures search forms submit to the correct language URL.
 */
add_filter('home_url', function ($url, $path, $scheme) {
    // Only modify on front-end, skip admin/AJAX/REST
    if (is_admin() || wp_doing_ajax() || wp_is_json_request()) {
        return $url;
    }
    
    // Only modify if path is root (/) or empty - this is where search forms typically point to
    if ($path !== '/' && $path !== '') {
        return $url;
    }
    
    // Extract language from current URL if present
    $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $langFromUrl = null;
    if ($reqPath !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $reqPath, $m)) {
        $langFromUrl = strtolower(sanitize_key($m[1]));
    }
    
    // Only modify if we're currently on a language-prefixed page
    if ($langFromUrl === null) {
        return $url;
    }
    
    $defaultLang = \AITranslate\AI_Lang::default();
    
    // Only add language prefix if it's not the default language
    if ($langFromUrl !== '' && $defaultLang && strtolower($langFromUrl) !== strtolower((string) $defaultLang)) {
        // Check if URL already has language prefix
        $urlPath = parse_url($url, PHP_URL_PATH);
        if ($urlPath && !preg_match('#^/([a-z]{2})(?:/|$)#i', $urlPath)) {
            // Add language prefix to the URL
            $parsed = parse_url($url);
            $newPath = '/' . $langFromUrl . '/';
            $url = $parsed['scheme'] . '://' . $parsed['host'];
            if (isset($parsed['port'])) {
                $url .= ':' . $parsed['port'];
            }
            $url .= $newPath;
            if (isset($parsed['query'])) {
                $url .= '?' . $parsed['query'];
            }
            if (isset($parsed['fragment'])) {
                $url .= '#' . $parsed['fragment'];
            }
        }
    }
    
    return $url;
}, 10, 3);

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

/**
 * Check if permalinks are properly configured (not 'plain').
 * Display a warning if they are set to 'plain' since the plugin requires rewrite rules.
 */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Check if permalinks are set to 'plain'
    $permalink_structure = get_option('permalink_structure');
    if ($permalink_structure === '' || $permalink_structure === null) {
        // 'plain' structure - show warning
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p>';
        echo '<strong>AI Translate Warning:</strong> ';
        echo esc_html__('This plugin requires a permalink structure other than "Plain". ', 'ai-translate');
        echo 'Please set your permalinks to "Post name" or another structure. ';
        echo '<a href="' . esc_url(admin_url('options-permalink.php')) . '">';
        echo esc_html__('Configure Permalinks', 'ai-translate');
        echo '</a>';
        echo '</p>';
        echo '</div>';
    }
});
