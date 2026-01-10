<?php

/**
 * Plugin Name: AI Translate
 * Description: AI based translation plugin. Adding 35 languages in a few clicks. 
 * Author: Netcare
 * Author URI: https://netcare.nl/
 * Version: 2.2.0
 * Requires at least: 5.0
 * Tested up to: 6.9
 * Requires PHP: 8.0.0
 * Text Domain: ai-translate
 * Domain Path: /languages
 */

// Do not allow direct access.
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Load plugin textdomain for translations with comprehensive locale fallback.
 *
 * WordPress supports various locale variants:
 * - Standard: nl_NL, de_DE, en_US
 * - Formal: nl_NL_formal, de_DE_formal
 * - Regional: nl_BE, de_AT, de_CH, pt_BR, es_MX, etc.
 * - Informal: (less common, but possible)
 *
 * This function implements a fallback chain:
 * 1. Try exact locale (e.g., nl_NL_formal)
 * 2. Try base locale (e.g., nl_NL)
 * 3. Try language code only (e.g., nl) - as last resort
 * 4. Fallback to standard load_plugin_textdomain()
 *
 * @return void
 */
function ai_translate_load_textdomain()
{
    $locale = get_locale();
    $plugin_dir = plugin_dir_path(__FILE__);
    $lang_dir = $plugin_dir . 'languages/';
    
    // Build fallback chain: exact -> base -> language code
    $fallback_locales = array($locale);
    
    // If locale has underscores, extract base locale (e.g., nl_NL_formal -> nl_NL)
    if (strpos($locale, '_') !== false) {
        $parts = explode('_', $locale);
        if (count($parts) >= 2) {
            $base_locale = $parts[0] . '_' . $parts[1];
            if (!in_array($base_locale, $fallback_locales, true)) {
                $fallback_locales[] = $base_locale;
            }
        }
        // Also try language code only (e.g., nl_NL -> nl)
        if (count($parts) >= 1) {
            $lang_only = $parts[0];
            if (!in_array($lang_only, $fallback_locales, true)) {
                $fallback_locales[] = $lang_only;
            }
        }
    }
    
    // Try each locale in fallback chain
    foreach ($fallback_locales as $try_locale) {
        $mofile = $lang_dir . 'ai-translate-' . $try_locale . '.mo';
        if (file_exists($mofile)) {
            load_textdomain('ai-translate', $mofile);
            if (is_textdomain_loaded('ai-translate')) {
                return;
            }
        }
    }
    
    // Final fallback to standard load_plugin_textdomain
    load_plugin_textdomain(
        'ai-translate',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
}

add_action('plugins_loaded', 'ai_translate_load_textdomain', 1);

// Include core/admin and runtime classes.
require_once __DIR__ . '/includes/class-ai-translate-core.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/class-ai-lang.php';
require_once __DIR__ . '/includes/class-ai-cache.php';
require_once __DIR__ . '/includes/class-ai-cache-meta.php';
require_once __DIR__ . '/includes/class-ai-dom.php';
require_once __DIR__ . '/includes/class-ai-batch.php';
require_once __DIR__ . '/includes/class-ai-seo.php';
require_once __DIR__ . '/includes/class-ai-url.php';
require_once __DIR__ . '/includes/class-ai-ob.php';
require_once __DIR__ . '/includes/class-ai-slugs.php';


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

// Schedule cache metadata sync cron job
add_action('ai_translate_sync_cache_metadata', function () {
    \AITranslate\AI_Cache_Meta::sync_from_filesystem();
});

if (!wp_next_scheduled('ai_translate_sync_cache_metadata')) {
    wp_schedule_event(time(), 'hourly', 'ai_translate_sync_cache_metadata');
}



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
    // Generic rule for hierarchical paths - try to resolve without language prefix first
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)(.+)$', 'index.php?ai_translate_path=$matches[2]&lang=$matches[1]', 'top');
    // Pagination for posts index: /{lang}/page/{n}/ (added last so it sits on top due to 'top')
    add_rewrite_rule('^' . $regex . '/page/([0-9]+)/?$', 'index.php?lang=$matches[1]&paged=$matches[2]', 'top');
}, 2);

add_filter('query_vars', function ($vars) {
    if (!in_array('lang', $vars, true)) {
        $vars[] = 'lang';
    }
    return $vars;
});

// Intentionally no early /{default}/ cookie mutation here.
// The default language switch must be handled deterministically via server-side redirects
// (especially for incognito), inside the switch handler and template_redirect rules.

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

    // Set cookie using centralized function
    \AITranslate\AI_Lang::set_cookie($switchLang);

    // Redirect to clean URL (remove switch_lang parameter).
    // For default language: go straight to '/' to avoid /nl/ race conditions in incognito.
    $defaultLang = \AITranslate\AI_Lang::default();
    $targetUrl = ($defaultLang && strtolower($switchLang) === strtolower((string) $defaultLang)) ? '/' : '/' . $switchLang . '/';

    // Prevent intermediaries from caching the 302, so switch_lang stays effective.
    nocache_headers();

    wp_safe_redirect(esc_url_raw($targetUrl), 302);
    exit;
}, 2);

/**
 * Block redirects for /{default}/ URLs when cookie doesn't match
 * This runs BEFORE redirect_canonical to prevent redirect before cookie is set
 */
add_filter('do_redirect_guess_404_permalink', function ($do_redirect) {
    $reqPathRaw = (string) ($_SERVER['REQUEST_URI'] ?? '');
    // URL decode only if double-encoded (contains %25 indicating double encoding)
    if (strpos($reqPathRaw, '%25') !== false) {
        $reqPathRaw = urldecode($reqPathRaw);
    }
    $reqPath = (string) parse_url($reqPathRaw, PHP_URL_PATH);
    $defaultLang = \AITranslate\AI_Lang::default();
    
    if ($reqPath !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $reqPath, $m)) {
        $langFromUrl = strtolower(sanitize_key($m[1]));
        if ($defaultLang && strtolower($langFromUrl) === strtolower((string) $defaultLang)) {
            $cookieLang = isset($_COOKIE['ai_translate_lang']) ? strtolower(sanitize_key((string) $_COOKIE['ai_translate_lang'])) : '';
            // If cookie doesn't match, block redirect so page can render and set cookie
            if ($cookieLang !== '' && strtolower($cookieLang) !== strtolower((string) $defaultLang)) {
                return false; // Block redirect
            }
        }
    }
    return $do_redirect;
}, 1);

/**
 * Prevent WordPress from redirecting language-prefixed URLs to the canonical root.
 * CRITICAL: This must run VERY early (priority 1) to prevent redirects before template_redirect
 */
add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
    // Preserve switch_lang and _lang_switch parameters
    if (isset($_GET['switch_lang']) || isset($_GET['_lang_switch'])) {
        return false;
    }
    $req = (string) $requested_url;
    $path = (string) parse_url($req, PHP_URL_PATH);
    if ($path !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $path, $m)) {
        $langFromUrl = strtolower(sanitize_key($m[1]));
        $defaultLang = \AITranslate\AI_Lang::default();

        // If this is the default language, ALWAYS block redirect to allow page to render
        // This ensures the flag cookie is set and page can render before redirect
        if ($defaultLang && strtolower($langFromUrl) === strtolower((string) $defaultLang)) {
            // ALWAYS block redirect for /nl/ to allow page to render and set cookies
            return false; // Block redirect - render page first so cookies are set
        }

        // ALWAYS block redirect for language-prefixed URLs
        // This allows template_redirect to handle the redirect logic
        return false;
    }
    return $redirect_url;
}, 0, 2); // Priority 0 to run BEFORE all other filters

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
 * Plugin activation: flush rewrite rules and create database tables.
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

    // Create cache metadata table
    \AITranslate\AI_Cache_Meta::create_table();
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
    $reqPathRaw = (string) ($_SERVER['REQUEST_URI'] ?? '');
    // URL decode only if double-encoded (contains %25 indicating double encoding)
    if (strpos($reqPathRaw, '%25') !== false) {
        $reqPathRaw = urldecode($reqPathRaw);
    }
    $reqPath = (string) parse_url($reqPathRaw, PHP_URL_PATH);

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
    // Handle ai_translate_path query var for custom URL resolution
    if (isset($_GET['ai_translate_path'])) {
        $path = sanitize_text_field(wp_unslash($_GET['ai_translate_path']));
        // Remove the query var so WordPress doesn't see it
        unset($_GET['ai_translate_path']);

        // Try to resolve the path without language prefix
        $resolved_post = \AITranslate\AI_Slugs::resolve_path_to_post('', $path);
        if ($resolved_post) {
            // Set the resolved post ID so WordPress loads the correct content
            global $wp_query;
            $wp_query->set('p', $resolved_post);
            $wp_query->set('post_type', 'any');
            // Make sure WordPress knows this is a singular post
            $wp_query->is_singular = true;
            $wp_query->is_single = true;
            $wp_query->is_archive = false;
            $wp_query->is_page = false;
        }
    }

    // Skip admin/AJAX/REST/feeds/XML files
    if (is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || ai_translate_is_xml_request()) {
        return;
    }

    $reqPathRaw = (string) ($_SERVER['REQUEST_URI'] ?? '');
    // URL decode only if double-encoded (contains %25 indicating double encoding)
    if (strpos($reqPathRaw, '%25') !== false) {
        $reqPathRaw = urldecode($reqPathRaw);
    }
    $reqPath = (string) parse_url($reqPathRaw, PHP_URL_PATH);
    $defaultLang = \AITranslate\AI_Lang::default();
    
    // CRITICAL: If we're on /{default}/ and cookie doesn't match, render page FIRST
    // This prevents redirect before cookie is set
    if ($reqPath !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $reqPath, $m)) {
        $langFromUrl = strtolower(sanitize_key($m[1]));
        if ($defaultLang && strtolower($langFromUrl) === strtolower((string) $defaultLang)) {
            $cookieLang = isset($_COOKIE['ai_translate_lang']) ? strtolower(sanitize_key((string) $_COOKIE['ai_translate_lang'])) : '';
            // If cookie doesn't match, set language and render page (don't redirect yet)
            if ($cookieLang !== '' && strtolower($cookieLang) !== strtolower((string) $defaultLang)) {
                \AITranslate\AI_Lang::set_cookie((string) $defaultLang);
                \AITranslate\AI_Lang::set_current((string) $defaultLang);
                // Add JavaScript redirect to / after page renders
                add_action('wp_footer', function() {
                    echo '<script>setTimeout(function(){window.location.href="/";},100);</script>';
                }, 999);
                // Continue to render page - Rule 3 below will be skipped
                // Set final language and start OB
                $finalLang = (string) $defaultLang;
                \AITranslate\AI_Lang::set_current($finalLang);
                add_filter('locale', function ($locale) use ($finalLang) {
                    return strtolower($finalLang) . '_' . strtoupper($finalLang);
                });
                \AITranslate\AI_OB::instance()->start();
                return; // Stop here - don't execute Rule 3 redirect
            }
        }
    }

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
            $reqUriRaw = (string) ($_SERVER['REQUEST_URI'] ?? '');
            // URL decode only if double-encoded (contains %25 indicating double encoding)
            if (strpos($reqUriRaw, '%25') !== false) {
                $reqUriRaw = urldecode($reqUriRaw);
            }
            $pathNow = (string) parse_url($reqUriRaw, PHP_URL_PATH);
            if ($pathNow === '' || $pathNow === '/' || !preg_match('#^/([a-z]{2})(?:/|$)#i', $pathNow)) {
                $base = home_url('/' . $searchLang . '/');
                $target = add_query_arg($_GET, $base);
                nocache_headers();
                wp_safe_redirect($target, 302);
                exit;
            }
        }
    }

    // RULE 3: Canonicalize default language - /{default}/ → /
    // Deterministic server-side redirect (fixes incognito race conditions).
    if ($langFromUrl !== null && $defaultLang && strtolower($langFromUrl) === strtolower((string) $defaultLang)) {
        if ($reqPath !== '/') {
            \AITranslate\AI_Lang::set_cookie((string) $defaultLang);
            \AITranslate\AI_Lang::set_current((string) $defaultLang);
            nocache_headers();
            wp_safe_redirect('/', 302);
            exit;
        }
    }

    // RULE 4: If cookie exists and on root, ensure language is set to default
    // Skip this rule if there's a search parameter (handled above)
    if ($reqPath === '/' && $cookieLang !== '' && !$hasSearchParam) {
        \AITranslate\AI_Lang::set_cookie((string) $defaultLang);
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
        \AITranslate\AI_Lang::reset();
        \AITranslate\AI_Lang::detect();
        $detected = \AITranslate\AI_Lang::current();
        if (!$detected) {
            $detected = (string) $defaultLang;
        }

        $resolvedLang = $detected;

        // Set cookie (central helper handles domains/headers)
        \AITranslate\AI_Lang::set_cookie($detected);

        // Redirect to language URL if not default
        // IMPORTANT: prevent browsers/proxies from caching this 302 (incognito can get "stuck")
        if ($detected && strtolower($detected) !== strtolower((string) $defaultLang)) {
            nocache_headers();
            wp_safe_redirect(home_url('/' . $detected . '/'), 302);
            exit;
        }
        // If detected == default, no redirect needed, just set language below
    } elseif ($reqPath === '/' && $cookieLang !== '') {
        // Returning visitor on root: respect stored preference
        $resolvedLang = $cookieLang;

        if ($defaultLang && strtolower($cookieLang) !== strtolower((string) $defaultLang)) {
            nocache_headers();
            wp_safe_redirect(home_url('/' . $cookieLang . '/'), 302);
            exit;
        }
    }

    // Sync cookie if URL has language prefix
    if ($langFromUrl !== null && ($cookieLang === '' || $cookieLang !== $langFromUrl)) {
        \AITranslate\AI_Lang::set_cookie($langFromUrl);
    }

    // Set language for content
    // IMPORTANT: If URL has a language code, use it. Otherwise, use default language.
    // Cookie should ONLY influence behavior on root /, not on other pages!
    $finalLang = $resolvedLang;
    if ($finalLang === null) {
        // If URL has language code, use it. Otherwise, use default.
        // Do NOT use cookie for pages without language code in URL!
        if ($langFromUrl !== null) {
            $finalLang = $langFromUrl;
        } else {
            $finalLang = (string) $defaultLang;
        }
    }
    if ($finalLang === '') {
        $finalLang = (string) $defaultLang;
    }
    $finalLang = strtolower(sanitize_key((string) $finalLang));
    if ($finalLang !== $cookieLang && $finalLang !== '') {
        \AITranslate\AI_Lang::set_cookie($finalLang);
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
        \AITranslate\AI_Lang::set_cookie((string) $defaultLang);
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
}, 0); // Priority 0 to run BEFORE other template_redirect hooks

/**
 * Ensure that when viewing the posts index (translated posts page) with ?blogpage=N,
 * the main query uses paged=N so page 2+ actually render page 2+ instead of page 1.
 */
// Removed pagination forcing; theme handles pagination

// Enqueue switcher CSS and JS only for nav menu integration (not for floating positions)
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) {
        return;
    }
    
    $settings = get_option('ai_translate_settings', array());
    $position = isset($settings['switcher_position']) ? $settings['switcher_position'] : 'none';
    
    // Only enqueue switcher assets for nav menu positions (not floating positions)
    if ($position === 'nav-start' || $position === 'nav-end') {
        wp_enqueue_style(
            'ai-translate-switcher',
            plugin_dir_url(__FILE__) . 'assets/switcher.css',
            array(),
            '2.1.7'
        );
        wp_enqueue_script(
            'ai-translate-switcher',
            plugin_dir_url(__FILE__) . 'assets/switcher.js',
            array(),
            '2.1.7',
            true
        );
    }
});

// Never adjust admin URLs or rewrite menu items in admin or default language
add_filter('nav_menu_link_attributes', function ($atts, $item) {
    if (is_admin() || \AITranslate\AI_Lang::is_exempt_request()) {
        return $atts;
    }
    return $atts;
}, 10, 2);

/**
 * Generate language switcher HTML for navigation menu.
 * 
 * @return string Switcher HTML or empty string if not applicable
 */
function ai_translate_get_nav_switcher_html() {
    if (is_admin()) {
        return '';
    }
    
    $settings = get_option('ai_translate_settings', array());
    $position = isset($settings['switcher_position']) ? $settings['switcher_position'] : 'none';
    
    // Only generate if nav-start or nav-end is selected
    if ($position !== 'nav-start' && $position !== 'nav-end') {
        return '';
    }
    
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? array_values($settings['enabled_languages']) : array();
    $default = isset($settings['default_language']) ? (string)$settings['default_language'] : '';
    if ($default === '' && !empty($enabled)) {
        $default = (string) $enabled[0];
    }
    if (empty($enabled) || $default === '') {
        return '';
    }
    
    // Determine current path and strip any leading /xx/
    $reqUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = (string) parse_url($reqUri, PHP_URL_PATH);
    if ($path === '') {
        $path = '/';
    }
    
    // Current language (from URL or default)
    $currentLang = null;
    if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $m)) {
        $currentLang = strtolower($m[1]);
    }
    if (!$currentLang) {
        $currentLang = $default;
    }
    
    $flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';
    $currentFlag = esc_url($flags_url . sanitize_key($currentLang) . '.png');
    
    // Generate unique ID for this menu instance
    $menu_id = 'ai-trans-menu-' . uniqid();
    
    // Build switcher HTML (compact nav version)
    $switcher_html = '<li class="menu-item ai-trans-nav-container"><div class="ai-trans ai-trans-nav">';
    // Gebruik <a> i.p.v. <button> zodat themes die op <a> stylen het item zichtbaar houden.
    $switcher_html .= '<a href="#" class="ai-trans-btn" role="button" aria-haspopup="true" aria-expanded="false" aria-controls="' . esc_attr($menu_id) . '" title="' . esc_attr(strtoupper($currentLang)) . '">';
    $switcher_html .= '<img src="' . $currentFlag . '" alt="' . esc_attr($currentLang) . '"><span class="ai-trans-code">' . esc_html(strtoupper($currentLang)) . '</span>';
    $switcher_html .= '</a>';
    $switcher_html .= '<div id="' . esc_attr($menu_id) . '" class="ai-trans-menu" role="menu">';
    
    foreach ($enabled as $code) {
        $code = sanitize_key($code);
        $label = strtoupper($code === $default ? $default : $code);
        // Use ?switch_lang= parameter to ensure cookie is set via init hook
        // Build relative URL to avoid host/home filters
        $url = '/?switch_lang=' . $code;
        $url = esc_url($url);
        $flag = esc_url($flags_url . $code . '.png');
        $switcher_html .= '<a class="ai-trans-item" href="' . $url . '" role="menuitem" data-lang="' . esc_attr($code) . '" data-ai-trans-skip="1">';
        $switcher_html .= '<img src="' . $flag . '" alt="' . esc_attr($label) . '">';
        $switcher_html .= '</a>';
    }
    
    $switcher_html .= '</div></div></li>';
    
    return $switcher_html;
}

/**
 * Inject language switcher into navigation menu when nav-start or nav-end is selected.
 */
// Note: Automatic menu injection is disabled. Users should add language switcher via Appearance > Menus
// add_filter('wp_nav_menu_items', function ($items, $args) {
//     $switcher_html = ai_translate_get_nav_switcher_html();
//     if (empty($switcher_html)) {
//         return $items;
//     }
//     if (!is_string($items)) {
//         $items = '';
//     }
//
//     $settings = get_option('ai_translate_settings', array());
//     $position = isset($settings['switcher_position']) ? $settings['switcher_position'] : 'none';
//
//     // Inject at start or end based on position
//     if ($position === 'nav-start') {
//         return $switcher_html . $items;
//     } else {
//         return $items . $switcher_html;
//     }
// }, 10, 2);

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
    $valid_positions = array('bottom-left', 'bottom-right', 'top-left', 'top-right', 'none');
    if (!in_array($position, $valid_positions, true)) {
        $position = 'bottom-left';
    }
    
    // Skip footer switcher if none is selected
    if ($position === 'none') {
        return;
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

        // Skip current language
        if ($code === $currentLang) {
            continue;
        }

        $label = strtoupper($code === $default ? $default : $code);
        // Use ?switch_lang= parameter to ensure cookie is set via init hook
        // Build relative URL to avoid host/home filters
        $url = '/?switch_lang=' . $code;
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
 * Also verifies that all configured languages are present in rewrite rules.
 */
add_action('init', function () {
    if (is_admin()) {
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
    
    // Verify that all configured languages are in rewrite rules
    // This check runs on every request (not cached) to catch language changes immediately
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : array();
    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : array();
    $default = isset($settings['default_language']) ? (string) $settings['default_language'] : '';
    $langs = array_values(array_unique(array_merge($enabled, $detectable)));
    if ($default !== '') {
        $langs = array_diff($langs, array($default));
    }
    $langs = array_filter(array_map('sanitize_key', $langs));
    
    // Check if rewrite rules contain all languages
    $rules_need_flush = false;
    if (!empty($langs) && is_array($rules)) {
        // Find a rewrite rule that should contain our languages
        foreach ($rules as $regex => $target) {
            if (is_string($target) && strpos($target, 'lang=') !== false) {
                // Extract language codes from regex pattern: ^(lang1|lang2|lang3|...)
                if (preg_match('/^\^\(([^)]+)\)/', $regex, $matches)) {
                    $rule_langs = array_map('trim', explode('|', $matches[1]));
                    // Check if any configured language is missing from rewrite rules
                    $missing = array_diff($langs, $rule_langs);
                    if (!empty($missing)) {
                        $rules_need_flush = true;
                        break;
                    }
                }
            }
        }
    }
    
    // Only check basic rule existence once per day (performance optimization)
    // But always check language completeness (catches new languages immediately)
    if ($rules_need_flush) {
        flush_rewrite_rules(false);
    } elseif (!$has_lang_rule) {
        if (!get_transient('ai_translate_rules_checked')) {
            set_transient('ai_translate_rules_checked', 1, DAY_IN_SECONDS);
            flush_rewrite_rules(false);
        }
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
    if (ai_translate_is_xml_request()) {
        return;
    }
    $reqUriRaw = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    // URL decode only if double-encoded (contains %25 indicating double encoding)
    if (strpos($reqUriRaw, '%25') !== false) {
        $reqUriRaw = urldecode($reqUriRaw);
    }
    $request_path = wp_parse_url($reqUriRaw, PHP_URL_PATH) ?: '/';
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
    if (ai_translate_is_xml_request()) {
        return;
    }
    $reqUriRaw = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    // URL decode only if double-encoded (contains %25 indicating double encoding)
    if (strpos($reqUriRaw, '%25') !== false) {
        $reqUriRaw = urldecode($reqUriRaw);
    }
    $path = wp_parse_url($reqUriRaw, PHP_URL_PATH) ?: '/';
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
    if (ai_translate_is_xml_request()) {
        return;
    }
    $reqUriRaw = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    // URL decode only if double-encoded (contains %25 indicating double encoding)
    if (strpos($reqUriRaw, '%25') !== false) {
        $reqUriRaw = urldecode($reqUriRaw);
    }
    $path = wp_parse_url($reqUriRaw, PHP_URL_PATH) ?: '/';
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
    $post_id = null;
    $expected_post_type = null;

    // Check if path contains a post type prefix (e.g., service/slug)
    $slug_used_for_lookup = null;
    if (strpos($rest, '/') !== false) {
        $parts = explode('/', $rest, 2);
        if (count($parts) === 2) {
            $potential_post_type = $parts[0];
            $potential_slug = $parts[1];
            $slug_used_for_lookup = $potential_slug;

            // Check if this is a registered post type
            if (post_type_exists($potential_post_type)) {
                $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $potential_slug);
                if ($post_id) {
                    $post = get_post((int) $post_id);
                    if ($post && $post->post_type === $potential_post_type) {
                        $expected_post_type = $potential_post_type;
                    } else {
                        // Post type mismatch, invalid result
                        $post_id = null;
                    }
                }
            }
        }
    }

    // Fallback: try the entire path as slug (for pages/posts without post type prefix)
    if (!$post_id) {
        $slug_used_for_lookup = $rest;
        $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $rest);
    }

    // Fallback: try the entire path as slug (for pages/posts without post type prefix)
    if (!$post_id) {
        $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $rest);
    }

    if ($post_id) {
        // Get the post object for further processing
        $post = get_post((int) $post_id);

        // Check if we found the post via the correct translated slug, or via fallback
        $correct_translated_slug = \AITranslate\AI_Slugs::get_or_generate($post_id, $lang);
        $found_via_correct_slug = ($correct_translated_slug && $slug_used_for_lookup === $correct_translated_slug);

        if (!$found_via_correct_slug && $correct_translated_slug) {
            // Found via fallback (source slug), redirect to correct translated URL
            if ($expected_post_type && post_type_exists($expected_post_type)) {
                $correct_url = home_url('/' . $lang . '/' . $expected_post_type . '/' . $correct_translated_slug . '/');
            } elseif ($post && $post->post_type === 'page') {
                $correct_url = home_url('/' . $lang . '/' . $correct_translated_slug . '/');
            } else {
                $correct_url = home_url('/' . $lang . '/' . $correct_translated_slug . '/');
            }

            // Only redirect if the URL is different
            $current_url = home_url($path);
            if ($correct_url !== $current_url) {
                nocache_headers();
                wp_redirect($correct_url, 301);
                exit;
            }
        }
        $wp->query_vars = array_diff_key($wp->query_vars, ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
        $posts_page_id = (int) get_option('page_for_posts');

        if ($expected_post_type && post_type_exists($expected_post_type)) {
            // Custom post type
            $wp->query_vars['post_type'] = $expected_post_type;
            $wp->query_vars['name'] = $post->post_name;
            $wp->is_singular = true;
            $wp->is_single = true;
        } elseif ($post && $post->post_type === 'page') {
            if ($posts_page_id > 0 && (int)$post_id === $posts_page_id) {
                // Treat posts page as home (blog index) so pagination works
                $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
                $wp->is_home = true;
                $wp->is_page = false;
                $wp->is_singular = false;
            } else {
                $wp->query_vars['page_id'] = (int) $post_id;
                $wp->is_page = true;
                $wp->is_singular = true;
            }
        } else {
            // Regular post
            $wp->query_vars['p'] = (int) $post_id;
            if ($post && !empty($post->post_type)) {
                $wp->query_vars['post_type'] = $post->post_type;
            }
            $wp->is_single = true;
            $wp->is_singular = true;
        }
        $wp->is_404 = false;
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
    if (ai_translate_is_xml_request()) return $permalink;
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
    if (ai_translate_is_xml_request()) return $permalink;
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
 * Rewrite internal permalinks (custom post types) to stable translated slugs for current language.
 */
add_filter('post_type_link', function ($permalink, $post, $leavename, $sample) {
    if (is_admin()) return $permalink;
    if (ai_translate_is_xml_request()) return $permalink;
    $lang = \AITranslate\AI_Lang::current();
    $default = \AITranslate\AI_Lang::default();
    if ($lang === null || $default === null || strtolower($lang) === strtolower($default)) {
        return $permalink;
    }
    $translated = \AITranslate\AI_Slugs::get_or_generate((int) $post->ID, $lang);
    if ($translated === null) return $permalink;

    // For custom post types, we need to build the path manually
    // Extract the post type from the current permalink structure
    $post_type = $post->post_type;
    $trail = substr($permalink, -1) === '/' ? '/' : '';

    // Build path: /{lang}/{post_type}/{translated-slug}/
    $path = '/' . $lang . '/' . $post_type . '/' . trim($translated, '/') . $trail;
    return home_url($path);
}, 10, 4);

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
        'posts_per_page' => 1000, // Limit to prevent memory issues
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
        'no_found_rows' => true, // Performance optimization
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
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
    $reqPathRaw = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    // URL decode only if double-encoded (contains %25 indicating double encoding)
    if (strpos($reqPathRaw, '%25') !== false) {
        $reqPathRaw = urldecode($reqPathRaw);
    }
    $reqPath = (string) parse_url($reqPathRaw, PHP_URL_PATH);
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
    
    // Skip sitemap and XML requests
    if (ai_translate_is_xml_request()) {
        return $url;
    }
    
    // SKIP language switcher URLs - they should always go to root without language prefix
    if (strpos($url, 'switch_lang=') !== false) {
        return $url;
    }
    
    // Only modify if path is root (/) or empty - this is where search forms typically point to
    if ($path !== '/' && $path !== '') {
        return $url;
    }
    
    // Extract language from current URL if present
    $reqPathRaw = (string) ($_SERVER['REQUEST_URI'] ?? '');
    // URL decode only if double-encoded (contains %25 indicating double encoding)
    if (strpos($reqPathRaw, '%25') !== false) {
        $reqPathRaw = urldecode($reqPathRaw);
    }
    $reqPath = (string) parse_url($reqPathRaw, PHP_URL_PATH);
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

// Admin notice removed per user request - the interface should be self-explanatory

/**
 * Generate language switcher HTML (shared between shortcode and menu)
 */
function ai_translate_generate_switcher_html($type = 'dropdown', $show_flags = true, $show_codes = true, $class = '') {
    // Get plugin settings
    $settings = get_option('ai_translate_settings', array());
    $enabled_languages = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ?
        array_values($settings['enabled_languages']) : array();
    $default_language = isset($settings['default_language']) ? (string)$settings['default_language'] : '';

    if (empty($enabled_languages) || empty($default_language)) {
        return '';
    }

    // Determine current language
    $current_lang = null;
    $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = (string) parse_url($req_uri, PHP_URL_PATH);
    if ($path === '') {
        $path = '/';
    }

    if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $matches)) {
        $current_lang = strtolower($matches[1]);
    }

    if (!$current_lang) {
        $current_lang = $default_language;
    }

    // Build base path (remove language prefix if present)
    $path_no_lang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
    if ($path_no_lang === '') {
        $path_no_lang = '/';
    }

    $flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';

    if ($type === 'inline') {
        $output = '<div class="ai-language-switcher-inline ' . esc_attr($class) . '">';
        foreach ($enabled_languages as $lang_code) {
            $lang_code = sanitize_key($lang_code);
            if ($lang_code === $current_lang) continue;

            $lang_label = strtoupper($lang_code);
            $lang_url = $lang_code === $default_language ? esc_url($path_no_lang) : esc_url('/' . $lang_code . $path_no_lang);

            $output .= '<a href="' . $lang_url . '" class="ai-language-item" data-lang="' . esc_attr($lang_code) . '" data-ai-trans-skip="1">';
            if ($show_flags) $output .= '<img src="' . esc_url($flags_url . $lang_code . '.png') . '" alt="' . esc_attr($lang_label) . '" class="ai-language-flag" />';
            if ($show_codes) $output .= '<span class="ai-language-code">' . esc_html($lang_label) . '</span>';
            $output .= '</a>';
        }
        $output .= '</div>';
    } else {
        $unique_id = 'ai-trans-' . wp_generate_password(8, false);
        $current_flag = esc_url($flags_url . sanitize_key($current_lang) . '.png');
        $current_label = strtoupper($current_lang);

        $output = '<div class="ai-language-switcher-dropdown ' . esc_attr($class) . '" id="' . esc_attr($unique_id) . '">';
        $output .= '<button type="button" class="ai-language-switcher-btn" aria-haspopup="true" aria-expanded="false" aria-controls="' . esc_attr($unique_id) . '-menu">';
        if ($show_flags) $output .= '<img src="' . $current_flag . '" alt="' . esc_attr($current_lang) . '" class="ai-language-flag" />';
        if ($show_codes) $output .= '<span class="ai-language-code">' . esc_html($current_label) . '</span>';
        $output .= '<span class="ai-language-arrow" aria-hidden="true">▼</span></button>';

        $output .= '<div class="ai-language-switcher-menu" id="' . esc_attr($unique_id) . '-menu" role="menu" hidden>';
        foreach ($enabled_languages as $lang_code) {
            $lang_code = sanitize_key($lang_code);
            if ($lang_code === $current_lang) continue;

            $lang_label = strtoupper($lang_code);
            $lang_url = $lang_code === $default_language ? esc_url($path_no_lang) : esc_url('/' . $lang_code . $path_no_lang);

            $output .= '<a href="' . $lang_url . '" class="ai-language-item" role="menuitem" data-lang="' . esc_attr($lang_code) . '" data-ai-trans-skip="1">';
            if ($show_flags) $output .= '<img src="' . esc_url($flags_url . $lang_code . '.png') . '" alt="' . esc_attr($lang_label) . '" class="ai-language-flag" />';
            if ($show_codes) $output .= '<span class="ai-language-code">' . esc_html($lang_label) . '</span>';
            $output .= '</a>';
        }
        $output .= '</div></div>';
    }

    return $output;
}
add_shortcode('ai_language_switcher', 'ai_translate_language_switcher_shortcode');

/**
 * Enqueue CSS and JavaScript for the language switcher shortcode
 */
add_action('wp_enqueue_scripts', function() {
    // Always enqueue for frontend (needed for menu integration)
    if (!is_admin()) {
        wp_enqueue_style(
            'ai-language-switcher',
            plugin_dir_url(__FILE__) . 'assets/language-switcher.css',
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'assets/language-switcher.css')
        );

        wp_enqueue_script(
            'ai-language-switcher',
            plugin_dir_url(__FILE__) . 'assets/language-switcher.js',
            array(),
            filemtime(plugin_dir_path(__FILE__) . 'assets/language-switcher.js'),
            true
        );
    }
});

/**
 * Add Language Switcher meta box to menu editor
 */
add_action('admin_head-nav-menus.php', function() {
    add_meta_box(
        'ai-translate-language-switcher',
        __('🌐 Language Switcher', 'ai-translate'),
        function() {
            ?>
            <div id="ai-language-switcher" class="posttypediv">
                <div class="tabs-panel tabs-panel-active">
                    <ul class="categorychecklist form-no-clear">
                        <li>
                            <label class="menu-item-title">
                                <input type="checkbox" class="menu-item-checkbox" name="menu-item[-1][menu-item-object-id]" value="-1">
                                <?php _e('🌐 Language Switcher', 'ai-translate'); ?>
                            </label>
                            <input type="hidden" class="menu-item-type" name="menu-item[-1][menu-item-type]" value="custom">
                            <input type="hidden" class="menu-item-title" name="menu-item[-1][menu-item-title]" value="<?php esc_attr_e('Language Switcher', 'ai-translate'); ?>">
                            <input type="hidden" class="menu-item-url" name="menu-item[-1][menu-item-url]" value="#">
                            <input type="hidden" class="menu-item-classes" name="menu-item[-1][menu-item-classes]" value="ai-language-switcher">
                        </li>
                    </ul>
                </div>
                <p class="button-controls">
                    <span class="add-to-menu">
                        <input type="submit" class="button-secondary submit-add-to-menu right" value="<?php esc_attr_e('Add to Menu', 'ai-translate'); ?>" name="add-ai-language-switcher-menu-item" id="submit-ai-language-switcher">
                        <span class="spinner"></span>
                    </span>
                </p>
            </div>
            <?php
        },
        'nav-menus',
        'side',
        'default'
    );
});

/**
 * Handle adding language switcher menu item via normal WordPress menu process
 */
add_action('wp_update_nav_menu', function($menu_id, $menu_data = null) {
    if (isset($_POST['menu-item'][-1]['menu-item-object-id']) && $_POST['menu-item'][-1]['menu-item-object-id'] == '-1') {
        // Language switcher was selected, it will be processed by wp_update_nav_menu_item
    }
});

add_action('wp_update_nav_menu_item', function($menu_id, $menu_item_db_id, $args) {
    // Check if this is our language switcher item
    if (isset($args['menu-item-classes']) && in_array('ai-language-switcher', $args['menu-item-classes'])) {
        // Mark it as a language switcher
        update_post_meta($menu_item_db_id, '_menu_item_is_language_switcher', '1');
        update_post_meta($menu_item_db_id, '_menu_item_switcher_type', 'dropdown');
        update_post_meta($menu_item_db_id, '_menu_item_show_flags', 'true');
        update_post_meta($menu_item_db_id, '_menu_item_show_codes', 'true');
    }
}, 10, 3);

/**
 * Add Language Switcher tab to menu editor using WordPress hooks (fallback)
 */

add_action('admin_head-nav-menus.php', function() {
    // Add Language Switcher directly to the menu item types list
    ?>
    // Using native WordPress meta box approach instead of JavaScript manipulation
    <?php
});

/**
 * Add custom fields to menu items for language switcher
 */
add_action('wp_nav_menu_item_custom_fields', function($item_id, $item, $depth, $args) {
    $is_language_switcher = get_post_meta($item_id, '_menu_item_is_language_switcher', true);
    $switcher_type = get_post_meta($item_id, '_menu_item_switcher_type', true) ?: 'dropdown';
    $show_flags = get_post_meta($item_id, '_menu_item_show_flags', true) !== 'false'; // Default true
    $show_codes = get_post_meta($item_id, '_menu_item_show_codes', true) !== 'false'; // Default true
    ?>
    <p class="field-menu-item-is-language-switcher description description-wide">
        <label for="edit-menu-item-is-language-switcher-<?php echo $item_id; ?>">
            <input type="checkbox" id="edit-menu-item-is-language-switcher-<?php echo $item_id; ?>"
                   name="menu-item-is-language-switcher[<?php echo $item_id; ?>]"
                   value="1" <?php checked($is_language_switcher, '1'); ?> />
            <?php _e('Make this a language switcher', 'ai-translate'); ?>
        </label>
    </p>

    <div class="field-menu-item-switcher-options description description-wide" style="display: <?php echo $is_language_switcher ? 'block' : 'none'; ?>;">
        <p>
            <label for="edit-menu-item-switcher-type-<?php echo $item_id; ?>">
                <?php _e('Switcher Type:', 'ai-translate'); ?>
                <select id="edit-menu-item-switcher-type-<?php echo $item_id; ?>"
                        name="menu-item-switcher-type[<?php echo $item_id; ?>]">
                    <option value="dropdown" <?php selected($switcher_type, 'dropdown'); ?>><?php _e('Dropdown', 'ai-translate'); ?></option>
                    <option value="inline" <?php selected($switcher_type, 'inline'); ?>><?php _e('Inline', 'ai-translate'); ?></option>
                </select>
            </label>
        </p>

        <p>
            <label for="edit-menu-item-show-flags-<?php echo $item_id; ?>">
                <input type="checkbox" id="edit-menu-item-show-flags-<?php echo $item_id; ?>"
                       name="menu-item-show-flags[<?php echo $item_id; ?>]"
                       value="1" <?php checked($show_flags, true); ?> />
                <?php _e('Show language flags', 'ai-translate'); ?>
            </label>
        </p>

        <p>
            <label for="edit-menu-item-show-codes-<?php echo $item_id; ?>">
                <input type="checkbox" id="edit-menu-item-show-codes-<?php echo $item_id; ?>"
                       name="menu-item-show-codes[<?php echo $item_id; ?>]"
                       value="1" <?php checked($show_codes, true); ?> />
                <?php _e('Show language codes', 'ai-translate'); ?>
            </label>
        </p>
    </div>

    <script type="text/javascript">
    (function($) {
        $('#edit-menu-item-is-language-switcher-<?php echo $item_id; ?>').on('change', function() {
            $(this).closest('.menu-item-settings').find('.field-menu-item-switcher-options').toggle($(this).is(':checked'));
        });
    })(jQuery);
    </script>
    <?php
}, 10, 4);

/**
 * Handle language switcher menu items added via JavaScript
 */
add_action('wp_ajax_add-menu-item', function() {
    // This will be called when wpNavMenu.addItemToMenu() is used
    // The item will be processed normally by WordPress
}, 1);

/**
 * Handle direct language switcher addition via GET parameter
 */
add_action('admin_init', function() {
    if (isset($_GET['ai-add-language-switcher']) && isset($_GET['menu-item-title'])) {
        // Get current menu ID from URL or POST
        $menu_id = isset($_REQUEST['menu']) ? intval($_REQUEST['menu']) : 0;

        if (!$menu_id) {
            // Try to get from referer or find the first available menu
            $menus = wp_get_nav_menus();
            if (!empty($menus)) {
                $menu_id = $menus[0]->term_id;
            }
        }

        if ($menu_id) {
            $title = sanitize_text_field($_GET['menu-item-title']);

            // Keep title simple for clean admin display - HTML rendering handled by walker in frontend

            // Create the menu item
            $menu_item_id = wp_update_nav_menu_item($menu_id, 0, array(
                'menu-item-title' => $title,
                'menu-item-url' => '#',
                'menu-item-type' => 'custom',
                'menu-item-object' => 'ai_language_switcher',
                'menu-item-status' => 'publish',
                'menu-item-classes' => 'menu-item-language-switcher menu-item-has-children'
            ));

            if (!is_wp_error($menu_item_id)) {
                // Mark this item as a language switcher
                update_post_meta($menu_item_id, '_menu_item_is_language_switcher', '1');
                update_post_meta($menu_item_id, '_menu_item_switcher_type', 'dropdown');
                update_post_meta($menu_item_id, '_menu_item_show_flags', 'true');
                update_post_meta($menu_item_id, '_menu_item_show_codes', 'true');

                // Redirect back to menu editor with success message
                $redirect_url = add_query_arg(array(
                    'menu' => $menu_id,
                    'ai-language-added' => '1'
                ), admin_url('nav-menus.php'));

                wp_redirect($redirect_url);
                exit;
            }
        }
    }
});

// Success message removed per user request - interface should be self-explanatory

/**
 * Force custom walker when menu contains language switcher items
 */
add_filter('wp_nav_menu_args', function($args) {
    // Check if this menu contains language switcher items
    if (isset($args['menu']) && is_object($args['menu'])) {
        $menu_items = wp_get_nav_menu_items($args['menu']->term_id);
    } elseif (isset($args['menu']) && is_numeric($args['menu'])) {
        $menu_items = wp_get_nav_menu_items($args['menu']);
    } elseif (isset($args['theme_location'])) {
        $menu_locations = get_nav_menu_locations();
        if (isset($menu_locations[$args['theme_location']])) {
            $menu_items = wp_get_nav_menu_items($menu_locations[$args['theme_location']]);
        }
    }

    if (isset($menu_items) && is_array($menu_items)) {
        foreach ($menu_items as $item) {
            $is_language_switcher = get_post_meta($item->ID, '_menu_item_is_language_switcher', true);
            if ($is_language_switcher === '1' || $item->object === 'ai_language_switcher') {
                // This menu contains a language switcher, force our custom walker
                $args['walker'] = new AI_Translate_Menu_Walker();
                break;
            }
        }
    }

    return $args;
});

// Add shortcode for menu language switcher
add_shortcode('ai_menu_language_switcher', function($atts) {
    $atts = shortcode_atts(array(
        'show_flags' => 'true',
        'show_codes' => 'true',
    ), $atts);

    $settings = get_option('ai_translate_settings', array());
    $enabled_languages = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ?
        array_values($settings['enabled_languages']) : array();
    $default_language = isset($settings['default_language']) ? (string)$settings['default_language'] : '';

    if (empty($enabled_languages) || empty($default_language)) {
        return '';
    }

    // Determine current language
    $current_lang = null;
    $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = (string) parse_url($req_uri, PHP_URL_PATH);
    if ($path === '') {
        $path = '/';
    }

    if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $matches)) {
        $current_lang = strtolower($matches[1]);
    }

    if (!$current_lang) {
        $current_lang = $default_language;
    }

    // Build base path (remove language prefix if present)
    $path_no_lang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
    if ($path_no_lang === '') {
        $path_no_lang = '/';
    }

    $flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';

    // Generate submenu HTML
    $submenu_html = '<ul class="sub-menu children">';
    foreach ($enabled_languages as $lang_code) {
        $lang_code = sanitize_key($lang_code);
        $is_current = ($lang_code === $current_lang);
        $lang_label = strtoupper($lang_code);

        // Build URL
        if ($lang_code === $default_language) {
            $lang_url = esc_url($path_no_lang);
        } else {
            $lang_url = esc_url('/' . $lang_code . $path_no_lang);
        }

        $item_classes = 'menu-item';
        if ($is_current) {
            $item_classes .= ' current-menu-item';
        }

        $submenu_html .= '<li class="' . esc_attr($item_classes) . '">';
        $submenu_html .= '<a href="' . $lang_url . '" data-lang="' . esc_attr($lang_code) . '" data-ai-trans-skip="1">';

        if ($atts['show_flags'] === 'true') {
            $submenu_html .= '<img src="' . esc_url($flags_url . $lang_code . '.png') . '" alt="' . esc_attr($lang_label) . '" class="ai-menu-language-flag" />';
        }
        if ($atts['show_codes'] === 'true') {
            $submenu_html .= '<span class="ai-menu-language-code">' . esc_html($lang_label) . '</span>';
        }

        $submenu_html .= '</a>';
        $submenu_html .= '</li>';
    }
    $submenu_html .= '</ul>';

    // Current language display
    $current_flag = esc_url($flags_url . sanitize_key($current_lang) . '.png');
    $current_label = strtoupper($current_lang);

    $output = '<div class="menu-item-language-switcher menu-item-has-children">';
    $output .= '<a href="#" class="ai-menu-language-current" data-ai-trans-skip="1">';
    if ($atts['show_flags'] === 'true') {
        $output .= '<img src="' . $current_flag . '" alt="' . esc_attr($current_label) . '" class="ai-menu-language-flag" />';
    }
    if ($atts['show_codes'] === 'true') {
        $output .= '<span class="ai-menu-language-code">' . esc_html($current_label) . '</span>';
    }
    $output .= '</a>';
    $output .= $submenu_html;
    $output .= '</div>';

    return $output;
});

// Direct database approach: Update menu items when they are saved

// Clean up existing menu items that have HTML in title
add_action('admin_init', function() {
    $menu_items = get_posts(array(
        'post_type' => 'nav_menu_item',
        'meta_key' => '_menu_item_object',
        'meta_value' => 'ai_language_switcher',
        'posts_per_page' => -1
    ));

    foreach ($menu_items as $menu_item) {
        $current_title = get_post_meta($menu_item->ID, '_menu_item_title', true);

        // If title contains HTML, clean it up
        if (strpos($current_title, '<img') !== false) {
            wp_update_nav_menu_item(
                get_post_meta($menu_item->ID, '_menu_item_menu_item_parent', true) ?: 0,
                $menu_item->ID,
                array(
                    'menu-item-title' => 'Language Switcher'
                )
            );
        }
    }
});

// Force our walker for menus with language switcher items
add_filter('wp_nav_menu_args', function($args) {
    // Simple check - if walker not set, try to detect language switchers
    if (!isset($args['walker'])) {
        $menu_items = array();

        if (isset($args['theme_location'])) {
            $menu_locations = get_nav_menu_locations();
            if (isset($menu_locations[$args['theme_location']])) {
                $menu_items = wp_get_nav_menu_items($menu_locations[$args['theme_location']]);
            }
        }

        foreach ($menu_items as $item) {
            if (get_post_meta($item->ID, '_menu_item_object', true) === 'ai_language_switcher') {
                $args['walker'] = new AI_Translate_Menu_Walker();
                break;
            }
        }
    }

    return $args;
}, 9999);

// Add JavaScript to replace language switcher menu items on frontend
add_action('wp_footer', function() {
    // Only run if we have language switcher menu items
    $settings = get_option('ai_translate_settings', array());
    $enabled_languages = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ?
        array_values($settings['enabled_languages']) : array();
    $default_language = isset($settings['default_language']) ? (string)$settings['default_language'] : '';

    if (empty($enabled_languages) || empty($default_language)) {
        return;
    }

    // Determine current language
    $current_lang = null;
    $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = (string) parse_url($req_uri, PHP_URL_PATH);
    if ($path === '') {
        $path = '/';
    }

    if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $matches)) {
        $current_lang = strtolower($matches[1]);
    }

    if (!$current_lang) {
        $current_lang = $default_language;
    }

    // Build base path (remove language prefix if present)
    $path_no_lang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
    if ($path_no_lang === '') {
        $path_no_lang = '/';
    }

    $flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';

    // Generate submenu HTML (exclude current language)
    $submenu_html = '<ul class="sub-menu children">';
    foreach ($enabled_languages as $lang_code) {
        $lang_code = sanitize_key($lang_code);
        $is_current = ($lang_code === $current_lang);

        // Skip current language
        if ($is_current) {
            continue;
        }

        $lang_label = strtoupper($lang_code);

        // Build URL
        if ($lang_code === $default_language) {
            $lang_url = esc_url($path_no_lang);
        } else {
            $lang_url = esc_url('/' . $lang_code . $path_no_lang);
        }

        $submenu_html .= '<li class="menu-item">';
        $submenu_html .= '<a href="' . $lang_url . '" data-lang="' . esc_attr($lang_code) . '" data-ai-trans-skip="1">';

        $submenu_html .= '<img src="' . esc_url($flags_url . $lang_code . '.png') . '" alt="' . esc_attr($lang_label) . '" class="ai-menu-language-flag" />';
        $submenu_html .= '<span class="ai-menu-language-code">' . esc_html($lang_label) . '</span>';

        $submenu_html .= '</a>';
        $submenu_html .= '</li>';
    }
    $submenu_html .= '</ul>';

    // Current language display
    $current_flag = esc_url($flags_url . sanitize_key($current_lang) . '.png');
    $current_label = strtoupper($current_lang);

    $replacement_html = '<li class="menu-item menu-item-language-switcher menu-item-has-children">';
    $replacement_html .= '<a href="#" class="ai-menu-language-current" data-ai-trans-skip="1">';
    $replacement_html .= '<img src="' . $current_flag . '" alt="' . esc_attr($current_label) . '" class="ai-menu-language-flag" />';
    $replacement_html .= '<span class="ai-menu-language-code">' . esc_html($current_label) . '</span>';
    $replacement_html .= '</a>';
    $replacement_html .= $submenu_html;
    $replacement_html .= '</li>';

    ?>
    <script>
    (function() {
        // Wait for DOM to be ready
        function initLanguageSwitcherReplacement() {

            // Find language switcher menu items (links with href="#" containing "Language Switcher")
            const languageItems = document.querySelectorAll('a[href="#"]');

            languageItems.forEach(function(link, index) {
                if (link.textContent.trim() === 'Language Switcher') {
                    const listItem = link.closest('li');
                    if (listItem) {
                        // Replace the entire menu item
                        listItem.outerHTML = <?php echo json_encode($replacement_html); ?>;
                    }
                }
            });

            // Also check for items that might have been created differently
            const allMenuItems = document.querySelectorAll('li.menu-item a[href="#"]');

            allMenuItems.forEach(function(link, index) {
                if (link.textContent.includes('NL') && link.querySelector('img')) {
                    // This looks like our language switcher, make sure it has submenu
                    const listItem = link.closest('li');
                    if (listItem && !listItem.querySelector('.sub-menu')) {
                        // Add submenu if missing
                        const submenu = <?php echo json_encode($submenu_html); ?>;
                        listItem.insertAdjacentHTML('beforeend', submenu);
                        listItem.classList.add('menu-item-has-children');
                    }
                }
            });
        }

        // Run immediately and after a delay for dynamically loaded content
        initLanguageSwitcherReplacement();
        setTimeout(initLanguageSwitcherReplacement, 1000);
        setTimeout(initLanguageSwitcherReplacement, 3000);

    })();
    </script>
    <?php
});

/**
 * Save custom menu item fields
 */
add_action('wp_update_nav_menu_item', function($menu_id, $menu_item_db_id) {
    // Save language switcher checkbox
    if (isset($_POST['menu-item-is-language-switcher'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_is_language_switcher', '1');
    } else {
        delete_post_meta($menu_item_db_id, '_menu_item_is_language_switcher');
    }

    // Save switcher type
    if (isset($_POST['menu-item-switcher-type'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_switcher_type', sanitize_text_field($_POST['menu-item-switcher-type'][$menu_item_db_id]));
    }

    // Save show flags
    if (isset($_POST['menu-item-show-flags'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_show_flags', 'true');
    } else {
        update_post_meta($menu_item_db_id, '_menu_item_show_flags', 'false');
    }

    // Save show codes
    if (isset($_POST['menu-item-show-codes'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_show_codes', 'true');
    } else {
        update_post_meta($menu_item_db_id, '_menu_item_show_codes', 'false');
    }
}, 10, 2);

/**
 * Custom menu walker to render language switcher items
 */
class AI_Translate_Menu_Walker extends Walker_Nav_Menu {
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
        // Check for language switcher menu items
        $is_language_switcher = $item->object === 'ai_language_switcher' ||
                               get_post_meta($item->ID, '_menu_item_is_language_switcher', true) === '1';

        if ($is_language_switcher) {
            $this->render_language_switcher_menu_item($output, $item, $depth, $args, $id);
            return;
        }

        parent::start_el($output, $item, $depth, $args, $id);
    }

    /**
     * Render language switcher as a menu item with submenu
     */
    private function render_language_switcher_menu_item(&$output, $item, $depth, $args, $id) {
        // Get plugin settings
        $settings = get_option('ai_translate_settings', array());
        $enabled_languages = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ?
            array_values($settings['enabled_languages']) : array();
        $default_language = isset($settings['default_language']) ? (string)$settings['default_language'] : '';

        if (empty($enabled_languages) || empty($default_language)) {
            return; // No languages configured
        }

        // Determine current language
        $current_lang = null;
        $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = (string) parse_url($req_uri, PHP_URL_PATH);
        if ($path === '') {
            $path = '/';
        }

        if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $matches)) {
            $current_lang = strtolower($matches[1]);
        }

        if (!$current_lang) {
            $current_lang = $default_language;
        }

        // Build base path (remove language prefix if present)
        $path_no_lang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
        if ($path_no_lang === '') {
            $path_no_lang = '/';
        }

        $flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';

        // Get switcher options
        $switcher_type = get_post_meta($item->ID, '_menu_item_switcher_type', true) ?: 'dropdown';
        $show_flags = get_post_meta($item->ID, '_menu_item_show_flags', true) !== 'false';
        $show_codes = get_post_meta($item->ID, '_menu_item_show_codes', true) !== 'false';

        if ($switcher_type === 'inline') {
            // Inline layout - all languages horizontal
            $classes = array('menu-item', 'menu-item-language-switcher');
            if (!empty($item->classes)) {
                $classes = array_merge($classes, $item->classes);
            }

            $class_str = 'class="' . esc_attr(implode(' ', $classes)) . '"';
            $output .= '<li id="menu-item-' . $item->ID . '" ' . $class_str . '>';
            $output .= '<div class="ai-language-switcher-inline">';

            foreach ($enabled_languages as $lang_code) {
                $lang_code = sanitize_key($lang_code);
                $is_current = ($lang_code === $current_lang);

                // Skip current language
                if ($is_current) {
                    continue;
                }

                $lang_label = strtoupper($lang_code);

                // Build URL
                if ($lang_code === $default_language) {
                    $lang_url = esc_url($path_no_lang);
                } else {
                    $lang_url = esc_url('/' . $lang_code . $path_no_lang);
                }

                $output .= '<a href="' . $lang_url . '" class="ai-language-item" data-lang="' . esc_attr($lang_code) . '" data-ai-trans-skip="1">';

                if ($show_flags) {
                    $flag_src = esc_url($flags_url . $lang_code . '.png');
                    $output .= '<img src="' . $flag_src . '" alt="' . esc_attr($lang_label) . '" class="ai-language-flag" />';
                }

                if ($show_codes) {
                    $output .= '<span class="ai-language-code">' . esc_html($lang_label) . '</span>';
                }

                $output .= '</a>';
            }

            $output .= '</div>';
            $output .= '</li>';
        } else {
            // Dropdown layout (default)
            $classes = array('menu-item', 'menu-item-language-switcher', 'menu-item-has-children');
            if (!empty($item->classes)) {
                $classes = array_merge($classes, $item->classes);
            }

            $class_str = 'class="' . esc_attr(implode(' ', $classes)) . '"';

            // Output the menu item with submenu
            $output .= '<li id="menu-item-' . $item->ID . '" ' . $class_str . '>';

            // Current language display (what shows in the menu bar)
            $current_label = strtoupper($current_lang);
            $current_flag = esc_url($flags_url . sanitize_key($current_lang) . '.png');

            $output .= '<a href="#" class="ai-menu-language-current" data-ai-trans-skip="1">';
            if ($show_flags) {
                $output .= '<img src="' . $current_flag . '" alt="' . esc_attr($current_label) . '" class="ai-menu-language-flag" />';
            }
            if ($show_codes) {
                $output .= '<span class="ai-menu-language-code">' . esc_html($current_label) . '</span>';
            }
            $output .= '</a>';

            // Submenu with all languages
            $output .= '<ul class="sub-menu children">';

            foreach ($enabled_languages as $lang_code) {
                $lang_code = sanitize_key($lang_code);
                $is_current = ($lang_code === $current_lang);

                // Skip current language
                if ($is_current) {
                    continue;
                }

                $lang_label = strtoupper($lang_code);

                // Build URL
                if ($lang_code === $default_language) {
                    $lang_url = esc_url($path_no_lang);
                } else {
                    $lang_url = esc_url('/' . $lang_code . $path_no_lang);
                }

                $output .= '<li class="menu-item ai-menu-language-item">';
                $output .= '<a href="' . $lang_url . '" data-lang="' . esc_attr($lang_code) . '" data-ai-trans-skip="1">';

                if ($show_flags) {
                    $flag_src = esc_url($flags_url . $lang_code . '.png');
                    $output .= '<img src="' . $flag_src . '" alt="' . esc_attr($lang_label) . '" class="ai-menu-language-flag" />';
                }

                if ($show_codes) {
                    $output .= '<span class="ai-menu-language-code" style="color: #000 !important;">' . esc_html($lang_label) . '</span>';
                }

                $output .= '</a>';
                $output .= '</li>';
            }

            $output .= '</ul>';
            $output .= '</li>';
        }
    }
}
