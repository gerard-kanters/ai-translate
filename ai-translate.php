<?php

/**
 * Plugin Name: AI Translate
 * Description: AI based translation plugin. Adding 35 languages in a few clicks. Fast caching, SEO-friendly, and cost-effective.
 * Author: NetCare
 * Author URI: https://netcare.nl/
 * Version: 2.2.8
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
 *
 * @return void
 */
function ai_translate_load_textdomain($force_user_locale = false)
{
    // If forced to use user locale (for admin)
    if ($force_user_locale && is_user_logged_in()) {
        $locale = get_user_locale();
    } else {
        $locale = get_locale();
    }

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
            // Unload existing textdomain first if reloading
            if ($force_user_locale && is_textdomain_loaded('ai-translate')) {
                unload_textdomain('ai-translate');
            }
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

// Load textdomain for frontend with site locale
add_action('plugins_loaded', function() {
    ai_translate_load_textdomain(); // Load with site locale for frontend
}, 1);

// For admin pages, load textdomain with user locale (this overrides the frontend load)
add_action('admin_init', function() {
    if (is_user_logged_in()) {
        $user_locale = get_user_locale();
        $site_locale = get_locale();

        // Always load user locale for admin, even if same as site locale
        ai_translate_load_textdomain(true); // Force load with user locale
    }
}, 1); // High priority to override any other loads


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
require_once __DIR__ . '/includes/class-ai-sitemap.php';

// Initialize sitemap integration
\AITranslate\AI_Sitemap::init();


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

// Add original-style language-prefixed rewrite rules using 'lang' query var to ensure WP resolves pages via pagename
// Priority 999: runs AFTER custom post types are registered (default priority 10)
function ai_translate_register_rewrite_rules()
{
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : array();
    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : array();
    $default = isset($settings['default_language']) ? (string) $settings['default_language'] : '';
    $langs = array_values(array_unique(array_merge($enabled, $detectable)));
    if ($default !== '') {
        $langs = array_diff($langs, array($default));
    }
    $langs = array_filter(array_map('sanitize_key', $langs));
    if (empty($langs)) {
        return;
    }
    $regex = '(' . implode('|', array_map(function ($l) {
        return preg_quote($l, '/');
    }, $langs)) . ')';
    add_rewrite_rule('^' . $regex . '/?$', 'index.php?lang=$matches[1]', 'top');
    
    $public_post_types = get_post_types(array('public' => true, '_builtin' => false), 'objects');
    foreach ($public_post_types as $post_type) {
        if (!empty($post_type->rewrite) && isset($post_type->rewrite['slug'])) {
            $slug = $post_type->rewrite['slug'];
            $type_name = $post_type->name;
            add_rewrite_rule(
                '^' . $regex . '/(?!wp-admin|wp-login\.php)(' . preg_quote($slug, '/') . ')/([^/]+)/?$',
                'index.php?lang=$matches[1]&post_type=' . $type_name . '&name=$matches[3]',
                'top'
            );
        }
    }
    
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)(.+)$', 'index.php?ai_translate_path=$matches[2]&lang=$matches[1]', 'top');
    add_rewrite_rule('^' . $regex . '/page/([0-9]+)/?$', 'index.php?lang=$matches[1]&paged=$matches[2]', 'top');
}

add_action('init', 'ai_translate_register_rewrite_rules', 999); // Priority 999: runs AFTER custom post types are registered (default priority 10)

// Ensure slug map table exists — only run dbDelta when schema version changes
add_action('init', function () {
    $current_version = '2.2.5';
    $stored_version = get_option('ai_translate_slugs_schema_version', '');
    if ($stored_version !== $current_version) {
        \AITranslate\AI_Slugs::install_table();
        update_option('ai_translate_slugs_schema_version', $current_version);
    }
}, 1);

// Schedule cache metadata sync cron job
add_action('ai_translate_sync_cache_metadata', function () {
    \AITranslate\AI_Cache_Meta::sync_from_filesystem();
});

if (!wp_next_scheduled('ai_translate_sync_cache_metadata')) {
    wp_schedule_event(time(), 'hourly', 'ai_translate_sync_cache_metadata');
}

add_filter('query_vars', function ($vars) {
    if (!in_array('lang', $vars, true)) {
        $vars[] = 'lang';
    }
    if (!in_array('ai_translate_path', $vars, true)) {
        $vars[] = 'ai_translate_path';
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
    ai_translate_register_rewrite_rules();

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
    // Fallback: if rewrite did not set ai_translate_path (e.g. URL with %), extract path from REQUEST_URI
    if (empty($vars['ai_translate_path']) && !empty($req) && preg_match('#^/([a-z]{2})/([^?]+)#i', $req, $m)) {
        $path_from_uri = (string) parse_url($req, PHP_URL_PATH);
        if ($path_from_uri !== '' && preg_match('#^/([a-z]{2})(?:/(.*))?$#i', $path_from_uri, $path_m)) {
            $vars['lang'] = strtolower($path_m[1]);
            $vars['ai_translate_path'] = isset($path_m[2]) ? trim($path_m[2], '/') : '';
        }
    }
    // ai_translate_path: the generic rewrite rule sets ai_translate_path for /{lang}/{path}/.
    // Resolve to post before the main query so the correct page_id or p+post_type is included.
    if (!empty($vars['ai_translate_path']) && !empty($vars['lang'])) {
        $path = trim((string) $vars['ai_translate_path'], '/');
        $lang = (string) $vars['lang'];
        $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $path);
        if (!$post_id && strpos($path, '/') !== false) {
            $base = basename($path);
            $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $base);
            if (!$post_id) {
                // Generic fallback: try to find post by translated slug (prefers non-attachments)
                $post_id = \AITranslate\AI_Slugs::resolve_translated_slug_to_post($base, $lang);
            }
        }
        if ($post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                if ($post->post_type === 'page') {
                    $vars['page_id'] = (int) $post->ID;
                } else {
                    $vars['p'] = (int) $post->ID;
                    $vars['post_type'] = $post->post_type;
                }
            }
            unset($vars['ai_translate_path']);
        } else {
            // Path did not resolve to a post; try taxonomy (category, tag, custom tax).
            $term_slug = (strpos($path, '/') !== false) ? basename($path) : $path;
            $term = null;
            if ($term_slug !== '') {
                $term = get_term_by('slug', $term_slug, 'category');
                if (!$term || is_wp_error($term)) {
                    $term = get_term_by('slug', $term_slug, 'post_tag');
                }
                if (!$term || is_wp_error($term)) {
                    $parts = array_values(array_filter(explode('/', trim($path, '/'))));
                    if (count($parts) >= 2 && taxonomy_exists($parts[0])) {
                        $term = get_term_by('slug', $parts[1], $parts[0]);
                    }
                }
            }
            if ($term && !is_wp_error($term)) {
                $tax = $term->taxonomy;
                if ($tax === 'category') {
                    $vars['category_name'] = $term->slug;
                } elseif ($tax === 'post_tag') {
                    $vars['tag'] = $term->slug;
                } else {
                    $vars[$tax] = $term->slug;
                }
                unset($vars['ai_translate_path']);
            }
        }
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
            // If post_type is specified, use it to resolve slug conflicts
            $expected_post_type = !empty($vars['post_type']) ? $vars['post_type'] : null;
            $src = \AITranslate\AI_Slugs::resolve_any_to_source_slug($nm, $expected_post_type);
            if (!$src && !empty($vars['lang'])) {
                // Generic fallback: find post by translated slug with post_type filter
                if ($expected_post_type) {
                    $post_id = \AITranslate\AI_Slugs::resolve_translated_slug_to_post_by_type($nm, (string) $vars['lang'], $expected_post_type);
                } else {
                    $post_id = \AITranslate\AI_Slugs::resolve_translated_slug_to_post($nm, (string) $vars['lang']);
                }
                if ($post_id) {
                    $post = get_post($post_id);
                    if ($post && $post->post_status === 'publish') {
                        $src = $post->post_name;
                    }
                }
            }
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
// Check for _wp_old_slug redirects before other plugin logic (priority 1).
// Request path is checked first: if it matches an old slug in DB, redirect. Do not rely on is_404
// because themes/plugins may serve the homepage instead of 404 for unknown slugs.
add_action('template_redirect', function () {
    if (!isset($_SERVER['REQUEST_URI'])) {
        return;
    }
    $request_uri = (string) $_SERVER['REQUEST_URI'];
    $request_path = parse_url($request_uri, PHP_URL_PATH);
    if (!is_string($request_path) || $request_path === '' || $request_path === '/') {
        return;
    }
    // Skip if URL has language prefix (let AI Translate handle those)
    if (preg_match('#^/([a-z]{2})(?:/|$)#i', $request_path)) {
        return;
    }
    // Single-segment path (e.g. /programmeren-met-een-ai-agen/) – might be an old slug
    $path_parts = array_filter(explode('/', trim($request_path, '/')));
    if (empty($path_parts)) {
        return;
    }
    $slug = end($path_parts);
    global $wpdb;
    $post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 1",
        $slug
    ));
    if (!$post_id) {
        return;
    }
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    $permalink = get_permalink($post);
    if ($permalink) {
        wp_safe_redirect($permalink, 301);
        exit;
    }
}, 1);

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
                // Add JavaScript redirect to site root after page renders
                add_action('wp_footer', function() {
                    echo '<script>window.location.replace(' . wp_json_encode(home_url('/')) . ');</script>';
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
                    // Define character set mappings for languages with truly non-Latin scripts
                    $nonLatinLangs = ['zh', 'ja', 'ko', 'ar', 'he', 'th', 'ka', 'ru', 'uk', 'bg', 'el', 'hi', 'bn', 'ta', 'te', 'ml', 'kn', 'gu', 'pa', 'ur', 'fa', 'ps', 'sd', 'ug', 'kk', 'ky', 'uz', 'mn', 'my', 'km', 'lo', 'ne', 'si', 'dz', 'bo', 'ti', 'am', 'hy', 'az', 'be', 'mk', 'sr', 'hr', 'bs', 'sq', 'mt', 'is', 'fo', 'cy', 'ga', 'gd', 'yi'];
                    $isNonLatinLang = in_array($langLower, $nonLatinLangs, true);
                    
                    // Latin-based languages that commonly use accented characters (Latin Extended)
                    // These should NOT be blocked when the path contains Latin Extended chars
                    $latinWithAccentsLangs = ['hu', 'cs', 'pl', 'sk', 'ro', 'tr', 'hr', 'sl', 'lv', 'lt', 'et', 'fi', 'sv', 'no', 'da', 'de', 'fr', 'es', 'it', 'pt', 'nl', 'ga'];
                    $isLatinWithAccents = in_array($langLower, $latinWithAccentsLangs, true);
                    
                    // Only block if it's truly non-Latin (not just accented Latin) for Latin-based languages
                    if (!$isNonLatinLang && !$isLatinWithAccents) {
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

    // RULE 0: Strip explicit ?lang= query parameter from URL → redirect to clean path-based URL
    // The ?lang= parameter is only used internally by rewrite rules, never in actual visitor URLs.
    // Examples: /es?lang=fr → /es/  |  /?lang=es → /es/  |  /?lang={default} → /
    if (isset($_GET['lang']) && trim((string) $_GET['lang']) !== '') {
        $cleanParams = $_GET;
        unset($cleanParams['lang']);

        $targetPath = $reqPath;
        if ($langFromUrl === null) {
            // No language in path yet — use the GET parameter to build the correct path
            $getLang = strtolower(sanitize_key((string) $_GET['lang']));
            if ($getLang !== '' && $defaultLang && strtolower($getLang) !== strtolower((string) $defaultLang)) {
                $targetPath = '/' . $getLang . '/';
            }
        }

        // Ensure trailing slash (except bare root)
        if ($targetPath !== '/' && substr($targetPath, -1) !== '/') {
            $targetPath .= '/';
        }

        $targetUrl = home_url($targetPath);
        if (!empty($cleanParams)) {
            $targetUrl = add_query_arg($cleanParams, $targetUrl);
        }

        nocache_headers();
        wp_safe_redirect(esc_url_raw($targetUrl), 301);
        exit;
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

    // RULE 4: If cookie exists and on root, redirect to cookie language or serve default
    if ($reqPath === '/' && $cookieLang !== '' && !$hasSearchParam) {
        if ($defaultLang && strtolower($cookieLang) !== strtolower((string) $defaultLang)) {
            // Returning visitor with non-default language preference - redirect to their preferred URL
            nocache_headers();
            wp_safe_redirect(home_url('/' . $cookieLang . '/'), 302);
            exit;
        }
        // Cookie is set to default language - serve default language page
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

    // RULE 5: Non-root pages without language prefix - redirect to cookie language URL
    // If visitor has a non-default language preference and visits a page without a language
    // prefix, redirect them to the language-prefixed version so they see it in their language.
    if ($langFromUrl === null && $reqPath !== '/' && $cookieLang !== '' && $defaultLang &&
        strtolower($cookieLang) !== strtolower((string) $defaultLang) && !$hasSearchParam) {
        nocache_headers();
        wp_safe_redirect(home_url('/' . $cookieLang . $reqPath), 302);
        exit;
    }

    $resolvedLang = null;

    // RULE 1: First visit (no cookie) - detect browser language and redirect
    if ($cookieLang === '' && $langFromUrl === null && !$hasSearchParam) {
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
            // For root: redirect to /{lang}/; for non-root: redirect to /{lang}{path}
            $targetPath = ($reqPath === '/') ? '/' . $detected . '/' : '/' . $detected . $reqPath;
            wp_safe_redirect(home_url($targetPath), 302);
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
}, 5); // Priority 5 to run AFTER preloader plugins (e.g. Safelayout Cute Preloader at 2).
// Preloader must ob_start first so it injects into AI-Translate output, not vice versa.

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

/**
 * Return the canonical root-relative path for the current page in the original/default language.
 * Bypasses post_link/page_link filters (which prefix the current language + translated slug)
 * by reading raw WordPress slugs via get_page_uri() / post_name directly.
 * Example: on /fr/actualites/ this returns /nieuws/ so all language links use the original slug.
 *
 * @param string $fallback Path to use when canonical URL cannot be determined.
 * @return string Root-relative path ending with '/'.
 */
function ai_translate_canonical_path($fallback) {
    if ($fallback === '/' || !function_exists('get_queried_object')) {
        return $fallback;
    }
    $obj = get_queried_object();
    $path = null;
    if ($obj instanceof WP_Post && isset($obj->ID)) {
        if ($obj->post_type === 'page') {
            // get_page_uri builds parent/child slug path without any permalink filter
            $uri = get_page_uri($obj->ID);
            if ($uri) { $path = '/' . trim($uri, '/') . '/'; }
        } else {
            // Raw post slug - no filter applied
            $slug = get_post_field('post_name', $obj->ID);
            if ($slug) { $path = '/' . trim((string)$slug, '/') . '/'; }
        }
    } elseif ($obj instanceof WP_Term && isset($obj->term_id)) {
        // ai-translate has no term_link filter, safe to use
        $p = get_term_link($obj);
        if ($p && !is_wp_error($p)) {
            $tp = (string) parse_url($p, PHP_URL_PATH);
            if ($tp && $tp !== '/') { $path = trailingslashit($tp); }
        }
    } elseif ($obj instanceof WP_Post_Type && isset($obj->name)) {
        $p = get_post_type_archive_link($obj->name);
        if ($p) {
            $tp = (string) parse_url($p, PHP_URL_PATH);
            if ($tp && $tp !== '/') { $path = trailingslashit($tp); }
        }
    }
    return $path ?? $fallback;
}

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
    if ($default !== '' && !in_array($default, $enabled, true)) {
        $enabled[] = $default;
    }
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
    $switcher_html = '<li class="menu-item ai-trans-nav-container"><div class="ai-trans ai-trans-nav" data-ai-trans-skip="1">';
    // Use <a> instead of <button> so themes that style <a> elements keep the item visible.
    $switcher_html .= '<a href="#" class="ai-trans-btn" role="button" aria-haspopup="true" aria-expanded="false" aria-controls="' . esc_attr($menu_id) . '" title="' . esc_attr(strtoupper($currentLang)) . '">';
    $switcher_html .= '<img src="' . $currentFlag . '" alt="' . esc_attr($currentLang) . '"><span class="ai-trans-code">' . esc_html(strtoupper($currentLang)) . '</span>';
    $switcher_html .= '</a>';
    $switcher_html .= '<div id="' . esc_attr($menu_id) . '" class="ai-trans-menu" role="menu">';
    
    // Build path without language prefix so switcher lands on the same page in the target language
    $pathNoLang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
    if ($pathNoLang === '') {
        $pathNoLang = '/';
    }
    // Resolve translated slugs back to original-language slug (e.g. /fr/actualites/ → /nieuws/)
    $pathNoLang = ai_translate_canonical_path($pathNoLang);

    foreach ($enabled as $code) {
        $code = sanitize_key($code);
        $isDefaultLang = (strtolower($code) === strtolower((string) $default));
        $label = strtoupper($isDefaultLang ? $default : $code);
        $url = $isDefaultLang ? esc_url($pathNoLang) : esc_url('/' . $code . $pathNoLang);
        $flag = esc_url($flags_url . $code . '.png');
        $switcher_html .= '<a class="ai-trans-item" href="' . $url . '" role="menuitem" data-lang="' . esc_attr($code) . '" data-ai-trans-skip="1">';
        $switcher_html .= '<img src="' . $flag . '" alt="' . esc_attr($label) . '">';
        $switcher_html .= '</a>';
    }

    $switcher_html .= '</div></div></li>';

    return $switcher_html;
}

/**
 * Minimal server-side language switcher (no JS): renders links to current path in other languages.
 */
add_action('wp_footer', function () {
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? array_values($settings['enabled_languages']) : array();
    $default = isset($settings['default_language']) ? (string)$settings['default_language'] : '';
    if ($default !== '' && !in_array($default, $enabled, true)) {
        $enabled[] = $default;
    }
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
    $pathNoLang = ai_translate_canonical_path($pathNoLang);

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

    echo '<div id="ai-trans" class="ai-trans" data-ai-trans-skip="1">';
    // Show current language flag with code label
    echo '<button type="button" class="ai-trans-btn" aria-haspopup="true" aria-expanded="false" aria-controls="ai-trans-menu" title="' . esc_attr(strtoupper($currentLang)) . '"><img src="' . $currentFlag . '" alt="' . esc_attr($currentLang) . '"><span>' . esc_html(strtoupper($currentLang)) . '</span></button>';
    echo '<div id="ai-trans-menu" class="ai-trans-menu" role="menu">';

    foreach ($enabled as $code) {
        $code = sanitize_key($code);
        $isDefaultLang = (strtolower($code) === strtolower((string) $default));

        // Skip current language
        if ($code === $currentLang) {
            continue;
        }

        $label = strtoupper($isDefaultLang ? $default : $code);
        $url = $isDefaultLang ? esc_url($pathNoLang) : esc_url('/' . $code . $pathNoLang);
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
function cS(r){function n(t){return t?t.trim().replace(/\s+/g," "):""}var s=new Set();var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label],img[alt],.initial-greeting,.chatbot-bot-text"):[];ns.forEach(function(el){if(el.closest?el.closest("[data-ai-trans-skip]"):el.hasAttribute("data-ai-trans-skip"))return;var ph=n(el.getAttribute("placeholder"));if(ph)s.add(ph);var tl=n(el.getAttribute("title"));if(tl)s.add(tl);var al=n(el.getAttribute("aria-label"));if(al)s.add(al);var at=n(el.getAttribute("alt"));if(at)s.add(at);var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=n(el.getAttribute("value"));if(v)s.add(v);}}var tc=el.textContent;if((el.classList.contains("initial-greeting")||el.classList.contains("chatbot-bot-text"))&&tc){var tcn=n(tc);if(tcn)s.add(tcn);}});return Array.from(s);} 
 function aT(r,m){var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label],img[alt],.initial-greeting,.chatbot-bot-text"):[];ns.forEach(function(el){if(el.closest?el.closest("[data-ai-trans-skip]"):el.hasAttribute("data-ai-trans-skip"))return;var ph=el.getAttribute("placeholder");if(ph){var pht=ph.trim();if(pht&&m[pht]!=null)el.setAttribute("placeholder",m[pht]);}var tl=el.getAttribute("title");if(tl){var tlt=tl.trim();if(tlt&&m[tlt]!=null)el.setAttribute("title",m[tlt]);}var al=el.getAttribute("aria-label");if(al){var alt=al.trim();if(alt&&m[alt]!=null)el.setAttribute("aria-label",m[alt]);}var at=el.getAttribute("alt");if(at){var att=at.trim();if(att&&m[att]!=null)el.setAttribute("alt",m[att]);}var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=el.getAttribute("value");if(v){var vt=v.trim();if(vt&&m[vt]!=null)el.setAttribute("value",m[vt]);}}}var tc=el.textContent;if((el.classList.contains("initial-greeting")||el.classList.contains("chatbot-bot-text"))&&tc){var tct=tc.trim();if(tct&&m[tct]!=null)el.textContent=m[tct];}});} 
 function tA(r){if(tA.called)return;tA.called=true;var ua=(typeof navigator!=="undefined"&&navigator.userAgent)?navigator.userAgent:"";if(/googlebot|bingbot|yandexbot|baiduspider|duckduckbot|slurp|facebot|ia_archiver/i.test(ua)){tA.called=false;return;}var ss=cS(r);if(!ss.length){tA.called=false;return;}var x=new XMLHttpRequest();x.open("POST",AI_TA.u,true);x.setRequestHeader("Content-Type","application/json; charset=UTF-8");x.onreadystatechange=function(){if(x.readyState===4){tA.called=false;if(x.status===200){try{var resp=JSON.parse(x.responseText);if(resp&&resp.success&&resp.data&&resp.data.map){aT(r,resp.data.map);}}catch(e){}}}};x.send(JSON.stringify({nonce:AI_TA.n,lang:gL(),strings:ss}));}
document.addEventListener("DOMContentLoaded",function(){var checkPage=function(){if(document.readyState==="complete"){setTimeout(function(){tA(document);},1500);}else{setTimeout(checkPage,100);}};checkPage();var moT=null,sel="input,textarea,select,button,[title],[aria-label],img[alt],.initial-greeting,.chatbot-bot-text";if(typeof MutationObserver!=="undefined"&&gL()){var mo=new MutationObserver(function(muts){for(var f=false,i=0;i<muts.length&&!f;i++){var a=muts[i].addedNodes;for(var j=0;j<a.length&&!f;j++){var n=a[j];if(n.nodeType===1){if(n.matches&&n.matches(sel))f=true;else if(n.querySelector&&n.querySelector(sel))f=true;}}}if(f){clearTimeout(moT);moT=setTimeout(function(){tA(document);},500);}});mo.observe(document.body||document.documentElement,{childList:true,subtree:true});}});
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
 * REST endpoint for dynamic UI attribute translation.
 */
add_action('rest_api_init', function () {
    register_rest_route('ai-translate/v1', '/batch-strings', [
        'methods' => 'POST',
        'permission_callback' => function (\WP_REST_Request $request) {
            // Bot check first: bots get empty response anyway, no need for referer/nonce
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
            $is_bot = $ua !== '' && preg_match('/googlebot|googleother|bingbot|yandexbot|baiduspider|duckduckbot|slurp|facebot|ia_archiver/i', $ua);
            if ($is_bot) {
                $request->set_param('_ai_tr_bot_no_nonce', 1);
                return true;
            }
            // Referer check: must originate from this site
            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
            if ($referer === '' || strpos($referer, home_url()) !== 0) {
                return new \WP_Error('rest_forbidden', 'Invalid referer', ['status' => 403]);
            }
            $nonce = $request->get_header('X-WP-Nonce');
            if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
                return true;
            }
            $nonce_param = $request->get_param('nonce');
            if ($nonce_param && wp_verify_nonce($nonce_param, 'ai_translate_front_nonce')) {
                return true;
            }

            // Public frontend pages can be heavily cached, causing stale inline nonces.
            // Allow anonymous same-site requests without requiring a valid nonce.
            if (!is_user_logged_in()) {
                $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string) $_SERVER['HTTP_ORIGIN'] : '';
                if ($origin !== '' && strpos($origin, home_url()) !== 0) {
                    return new \WP_Error('rest_forbidden', 'Invalid origin', ['status' => 403]);
                }
                return true;
            }

            return new \WP_Error('rest_forbidden', 'Invalid nonce', ['status' => 403]);
        },
        'args' => [],
        'callback' => function (\WP_REST_Request $request) {
            $is_bot_request = (bool) $request->get_param('_ai_tr_bot_no_nonce');
            // Rate limiting: max 60 requests per minute per IP.
            // This endpoint is called by client-side JavaScript for UI attribute translation.
            // Warm cache does not trigger this endpoint (server-side only, no JS execution).
            // 60/min is generous enough for normal browsing (2-3 calls per page load).
            $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
            $rate_key = 'ai_tr_rate_' . md5($ip);
            $rate_count = (int) get_transient($rate_key);
            if ($rate_count >= 30) {
                return new \WP_REST_Response(['error' => 'Rate limit exceeded'], 429);
            }
            set_transient($rate_key, $rate_count + 1, 60);

            $arr = $request->get_param('strings');
            if (!is_array($arr)) {
                $arr = [];
            }
            // Input limits: max 50 strings of max 150 chars each (UI attributes are short)
            $arr = array_slice($arr, 0, 50);
            $arr = array_filter($arr, function ($s) {
                return is_string($s) && mb_strlen($s) <= 150;
            });
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
                // Normalize referrer: remove double slashes in path (e.g., /de// -> /de/)
                if ($referer !== '') {
                    $refererParsed = parse_url($referer);
                    if (isset($refererParsed['path'])) {
                        $refererParsed['path'] = preg_replace('#/+#', '/', $refererParsed['path']);
                        $referer = '';
                        if (isset($refererParsed['scheme'])) {
                            $referer .= $refererParsed['scheme'] . '://';
                        }
                        if (isset($refererParsed['host'])) {
                            $referer .= $refererParsed['host'];
                            if (isset($refererParsed['port'])) {
                                $referer .= ':' . $refererParsed['port'];
                            }
                        }
                        $referer .= $refererParsed['path'];
                        if (isset($refererParsed['query'])) {
                            $referer .= '?' . $refererParsed['query'];
                        }
                        if (isset($refererParsed['fragment'])) {
                            $referer .= '#' . $refererParsed['fragment'];
                        }
                    }
                }
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
            // Bots get cached translations but no API calls (same as stop_translations)
            $skip_api = $stop_translations || $is_bot_request;
            
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

                    // If stop_translations is enabled or bot request, always use cache (don't validate)
                    if (!$skip_api) {
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

                    if ($cacheInvalid && !$skip_api) {
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
                    if ($skip_api) {
                        // Stop translations or bot: use source text without API call
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
                if ($skip_api) {
                    // Stop translations or bot: block API calls, use source texts
                    // Use source texts for all segments that would have been translated
                    foreach ($toTranslate as $id => $origNormalized) {
                        $originalText = isset($textsOriginal[$origNormalized]) ? $textsOriginal[$origNormalized] : $origNormalized;
                        $map[$originalText] = $originalText;
                    }
                    // Clear $toTranslate to prevent API call
                    $toTranslate = [];
                } else {
                    // Validate that strings actually exist on the page (security: prevent abuse)
                    $referer = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
                    // Normalize referrer: remove double slashes in path (e.g., /de// -> /de/)
                    if ($referer !== '') {
                        $refererParsed = parse_url($referer);
                        if (isset($refererParsed['path'])) {
                            $refererParsed['path'] = preg_replace('#/+#', '/', $refererParsed['path']);
                            $referer = '';
                            if (isset($refererParsed['scheme'])) {
                                $referer .= $refererParsed['scheme'] . '://';
                            }
                            if (isset($refererParsed['host'])) {
                                $referer .= $refererParsed['host'];
                                if (isset($refererParsed['port'])) {
                                    $referer .= ':' . $refererParsed['port'];
                                }
                            }
                            $referer .= $refererParsed['path'];
                            if (isset($refererParsed['query'])) {
                                $referer .= '?' . $refererParsed['query'];
                            }
                            if (isset($refererParsed['fragment'])) {
                                $referer .= '#' . $refererParsed['fragment'];
                            }
                        }
                    }
                    if ($referer !== '' && strpos($referer, home_url()) === 0) {
                        // Get page HTML (cached using same expiration as translations)
                        $page_cache_key = 'ai_tr_page_html_' . md5($referer);
                        $page_html = get_transient($page_cache_key);
                        if ($page_html === false) {
                            // Fetch page HTML
                            $response = wp_remote_get($referer, array(
                                'timeout' => 5,
                                'sslverify' => false,
                                'headers' => array(
                                    'User-Agent' => 'AI-Translate-Validator/1.0'
                                )
                            ));
                            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                                $page_html = wp_remote_retrieve_body($response);
                                // Cache using same expiration as translations
                                $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14 * 24);
                                $expiry = max(1, $expiry_hours) * HOUR_IN_SECONDS;
                                set_transient($page_cache_key, $page_html, $expiry);
                            } else {
                                // Security: if page HTML cannot be fetched, block translation to prevent abuse
                                $toTranslate = [];
                            }
                        }
                        // Validate each string exists on page (in attributes or content)
                        if ($page_html !== '') {
                            $validated = [];
                            foreach ($toTranslate as $id => $text) {
                                // Check if string exists in HTML (escape special regex chars)
                                $text_escaped = preg_quote($text, '/');
                                // Match in attributes (placeholder, title, aria-label, alt, value) or content
                                if (preg_match('/' . $text_escaped . '/iu', $page_html)) {
                                    $validated[$id] = $text;
                                }
                            }
                            // Only allow translation of validated strings
                            $toTranslate = $validated;
                        } else {
                            // Security: if page HTML is empty, block translation to prevent abuse
                            $toTranslate = [];
                        }
                    } else {
                        // Security: if referer is invalid or missing, block translation
                        $toTranslate = [];
                    }
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
                        // Strip AI model language annotations from translations.
                        // Models sometimes append "(français)", "(NL : tapez un message...)" etc.
                        // Only strip trailing parenthetical text that wasn't in the source.
                        if (strpos($origNormalized, '(') === false && preg_match('/\s*\((?:[a-zA-Z]{2}\s*:|[^)]{2,30}ais|[^)]{2,30}isch|[^)]{2,30}ish|[^)]{2,30}ese|[^)]{2,30}ñol|[^)]{2,30}iano)[^)]*\)\s*$/u', $tr)) {
                            $tr = preg_replace('/\s*\([^)]*\)\s*$/u', '', $tr);
                            // Handle multiple trailing annotations
                            while (strpos($origNormalized, '(') === false && preg_match('/\s*\([^)]*\)\s*$/u', $tr)) {
                                $tr = preg_replace('/\s*\([^)]*\)\s*$/u', '', $tr);
                            }
                            $tr = trim($tr);
                        }
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
 * Resolve _wp_old_slug in parse_request and redirect to canonical URL before 404 is set.
 * Priority 0 so this runs before any 404 redirect plugin (which would send 404s to homepage).
 */
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax() || ai_translate_is_xml_request()) {
        return;
    }
    $reqUriRaw = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (strpos($reqUriRaw, '%25') !== false) {
        $reqUriRaw = urldecode($reqUriRaw);
    }
    $request_path = wp_parse_url($reqUriRaw, PHP_URL_PATH) ?: '/';
    if (!is_string($request_path) || $request_path === '' || $request_path === '/') {
        return;
    }
    if (preg_match('#^/([a-z]{2})(?:/|$)#i', $request_path)) {
        return;
    }
    $path_parts = array_filter(explode('/', trim($request_path, '/')));
    if (empty($path_parts)) {
        return;
    }
    $slug = end($path_parts);
    global $wpdb;
    $post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 1",
        $slug
    ));
    if (!$post_id) {
        return;
    }
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    $permalink = get_permalink($post);
    if ($permalink) {
        wp_safe_redirect($permalink, 301);
        exit;
    }
}, 0);

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
    // Decode URL-encoded path (e.g. Hungarian slugs) so slug lookup matches DB
    if (strpos($rest, '%') !== false) {
        $rest_decoded = rawurldecode($rest);
        if (mb_check_encoding($rest_decoded, 'UTF-8')) {
            $rest = $rest_decoded;
        }
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
    $detected_post_type = null;
    if (strpos($rest, '/') !== false) {
        $parts = explode('/', $rest, 2);
        if (count($parts) === 2) {
            $potential_post_type = $parts[0];
            $potential_slug = trim($parts[1], '/');
            $slug_used_for_lookup = $potential_slug;

            // Check if this is a registered post type
            if (post_type_exists($potential_post_type)) {
                $detected_post_type = $potential_post_type;
                // If slug is empty (only post type, e.g., /it/service/), redirect to language root
                if ($potential_slug === '') {
                    nocache_headers();
                    wp_redirect(home_url('/' . $lang . '/'), 301);
                    exit;
                }
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
    } else {
        // Single segment: check if it's a post type without slug (e.g., /it/service)
        if (post_type_exists($rest)) {
            $detected_post_type = $rest;
            // Post type without slug, redirect to language root
            nocache_headers();
            wp_redirect(home_url('/' . $lang . '/'), 301);
            exit;
        }
    }

    // Fallback: try the entire path as slug (for pages/posts without post type prefix)
    if (!$post_id) {
        $slug_used_for_lookup = $rest;
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

    // Fallback als er geen slug-mapregel is (pagina nog nooit vertaald): zoek op post_name (bron) via get_page_by_path.
    // Zonder dit geeft /{lang}/{bron-slug}/ 404 en faalt warm cache.
    if ($rest !== '') {
        $public_types = get_post_types(['public' => true], 'names');
        $public_types = array_diff((array) $public_types, ['attachment']);
        if (empty($public_types)) {
            $public_types = ['post', 'page'];
        }
        $public_types = array_values($public_types);

        $post = null;
        if (strpos($rest, '/') !== false) {
            $parts = explode('/', $rest, 2);
            $first = $parts[0];
            foreach (get_post_types(['public' => true, '_builtin' => false], 'objects') as $pt) {
                if (!empty($pt->rewrite['slug']) && $pt->rewrite['slug'] === $first) {
                    $post = get_page_by_path($parts[1], OBJECT, [$pt->name]);
                    break;
                }
            }
            if (!$post) {
                $post = get_page_by_path($rest, OBJECT, $public_types);
            }
        } else {
            $post = get_page_by_path($rest, OBJECT, $public_types);
        }
        if (!$post) {
            $term_slug = (strpos($rest, '/') !== false) ? basename($rest) : $rest;
            $term = null;
            if ($term_slug !== '') {
                $term = get_term_by('slug', $term_slug, 'category');
                if (!$term || is_wp_error($term)) {
                    $term = get_term_by('slug', $term_slug, 'post_tag');
                }
                if (!$term || is_wp_error($term)) {
                    $parts = array_values(array_filter(explode('/', trim($rest, '/'))));
                    if (count($parts) >= 2 && taxonomy_exists($parts[0])) {
                        $term = get_term_by('slug', $parts[1], $parts[0]);
                    }
                }
            }
            if ($term && !is_wp_error($term)) {
                $tax = $term->taxonomy;
                $wp->query_vars = array_diff_key($wp->query_vars, ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
                if ($tax === 'category') {
                    $wp->query_vars['category_name'] = $term->slug;
                } elseif ($tax === 'post_tag') {
                    $wp->query_vars['tag'] = $term->slug;
                } else {
                    $wp->query_vars[$tax] = $term->slug;
                }
                $wp->is_404 = false;
                return;
            }
        }
        if ($post && isset($post->ID) && isset($post->post_type) && $post->post_type !== 'attachment') {
            $wp->query_vars = array_diff_key($wp->query_vars, ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
            $posts_page_id = (int) get_option('page_for_posts');
            if ($post->post_type === 'page') {
                if ($posts_page_id > 0 && (int) $post->ID === $posts_page_id) {
                    $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
                    $wp->is_home = true;
                    $wp->is_page = false;
                    $wp->is_singular = false;
                } else {
                    $wp->query_vars['page_id'] = (int) $post->ID;
                    $wp->is_page = true;
                    $wp->is_singular = true;
                }
            } else {
                $wp->query_vars['p'] = (int) $post->ID;
                $wp->query_vars['post_type'] = $post->post_type;
                $wp->is_single = true;
                $wp->is_singular = true;
            }
            $wp->is_404 = false;
            return;
        }
    }

    // No resolution found for this language-prefixed URL.
    // Remove the rewrite-generated pagename (e.g. "es/bestaat-niet") to prevent
    // WordPress from falling back to the blog index instead of properly triggering a 404.
    unset($wp->query_vars['pagename']);
    $wp->query_vars['error'] = '404';
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
 * On content or title changes: clear translation cache for this post so the next
 * visit triggers a fresh translation (retranslation).
 */
add_action('post_updated', function ($post_id, $post_after, $post_before) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }
    if (!is_object($post_before) || !is_object($post_after)) {
        return;
    }
    if ($post_after->post_status !== 'publish') {
        return;
    }
    if ((string) $post_before->post_content === (string) $post_after->post_content
        && (string) $post_before->post_title === (string) $post_after->post_title) {
        return;
    }
    \AITranslate\AI_Cache_Meta::clear_post_cache($post_id);
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
    // Try slug mapping: resolve (translated) path to source pagename
    $source = \AITranslate\AI_Slugs::resolve_any_to_source_slug($rest);
    if ($source) {
        $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $source);
        $post = $post_id ? get_post($post_id) : get_page_by_path($source);
        if (!$post) {
            return $preempt;
        }
        $wp_query->query_vars = array_diff_key($wp_query->query_vars, ['name' => 1, 'pagename' => 1, 'p' => 1, 'post_type' => 1]);
        if ($post->post_type === 'page') {
            $wp_query->query_vars['pagename'] = get_page_uri($post->ID);
            $wp_query->is_page = true;
        } else {
            $wp_query->query_vars['p'] = (int) $post->ID;
            $wp_query->query_vars['post_type'] = $post->post_type;
            $wp_query->is_page = false;
        }
        $wp_query->is_404 = false;
        $wp_query->is_singular = true;
        $wp_query->is_author = false;
        $wp_query->is_date = false;
        $wp_query->is_category = false;
        $wp_query->is_tag = false;
        $wp_query->is_archive = false;
        $wp_query->posts = array($post);
        $wp_query->post = $post;
        $wp_query->post_count = 1;
        $wp_query->found_posts = 1;
        $wp_query->max_num_pages = 1;
        $wp_query->queried_object = $post;
        $wp_query->queried_object_id = (int) $post->ID;
        return true;
    }
    // Hierarchische paden: slug map slaat alleen de leaf (post_name) op, niet bv. service/cloud-management.
    // Probeer eerst het volledige pad, dan het laatste segment, daarna eventueel een slug-alias.
    if (strpos($rest, '/') !== false) {
        $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $rest);
        if (!$post_id) {
            $base = basename($rest);
            $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $base);
            if (!$post_id) {
                // Generic fallback: find post by translated slug (prefers non-attachments)
                $post_id = \AITranslate\AI_Slugs::resolve_translated_slug_to_post($base, $lang);
            }
        }
        if ($post_id) {
            $post = get_post($post_id);
            if ($post) {
                $wp_query->query_vars = array_diff_key($wp_query->query_vars, ['name' => 1, 'pagename' => 1, 'p' => 1, 'post_type' => 1]);
                if ($post->post_type === 'page') {
                    $wp_query->query_vars['pagename'] = get_page_uri($post_id);
                    $wp_query->is_page = true;
                } else {
                    $wp_query->query_vars['p'] = (int) $post->ID;
                    $wp_query->query_vars['post_type'] = $post->post_type;
                    $wp_query->is_page = false;
                }
                $wp_query->is_404 = false;
                $wp_query->is_singular = true;
                $wp_query->is_author = false;
                $wp_query->is_date = false;
                $wp_query->is_category = false;
                $wp_query->is_tag = false;
                $wp_query->is_archive = false;
                $wp_query->posts = array($post);
                $wp_query->post = $post;
                $wp_query->post_count = 1;
                $wp_query->found_posts = 1;
                $wp_query->max_num_pages = 1;
                $wp_query->queried_object = $post;
                $wp_query->queried_object_id = (int) $post->ID;
                return true;
            }
        }
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

    // Rate limiting: max 10 search queries per minute per IP (prevent abuse/cost attacks)
    // Normal users rarely exceed 1-2 searches per minute; 5 is generous but prevents abuse
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'unknown';
    $rate_key = 'ai_tr_search_rate_' . md5($ip);
    $rate_count = (int) get_transient($rate_key);
    if ($rate_count >= 10) {
        // Rate limit exceeded: skip translation, use original query
        return;
    }
    set_transient($rate_key, $rate_count + 1, 60);

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
    if ($default_language !== '' && !in_array($default_language, $enabled_languages, true)) {
        $enabled_languages[] = $default_language;
    }

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
    $path_no_lang = ai_translate_canonical_path($path_no_lang);

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
    $classes = isset($args['menu-item-classes']) ? $args['menu-item-classes'] : '';
    if (is_string($classes)) {
        $classes = explode(' ', $classes);
    }
    if (is_array($classes) && in_array('ai-language-switcher', $classes, true)) {
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
    if ($is_language_switcher !== '1') {
        $item_classes = is_array($item->classes) ? $item->classes : array();
        if (in_array('ai-language-switcher', $item_classes, true) || $item->object === 'ai_language_switcher') {
            $is_language_switcher = '1';
            update_post_meta($item_id, '_menu_item_is_language_switcher', '1');
        }
    }
    $switcher_type = get_post_meta($item_id, '_menu_item_switcher_type', true) ?: 'dropdown';
    $show_flags = get_post_meta($item_id, '_menu_item_show_flags', true) !== 'false'; // Default true
    $show_codes = get_post_meta($item_id, '_menu_item_show_codes', true) !== 'false'; // Default true
    ?>
    <?php
    if ($is_language_switcher === '1') {
        $settings = get_option('ai_translate_settings', array());
        $sw_position = isset($settings['switcher_position']) ? $settings['switcher_position'] : 'bottom-left';
        if ($sw_position !== 'none') {
            $settings_url = admin_url('admin.php?page=ai-translate');
            ?>
            <div class="ai-switcher-warning" style="background:#fef2f2;border:1px solid #dc2626;border-radius:4px;padding:8px 12px;margin:8px 0;">
                <p style="color:#dc2626;margin:0;font-weight:600;">
                    <?php printf(
                        esc_html__('This menu item is inactive. The language switcher is set to "%s" in %splugin settings%s. Set it to "Hidden" to use the menu switcher.', 'ai-translate'),
                        esc_html(ucwords(str_replace('-', ' ', $sw_position))),
                        '<a href="' . esc_url($settings_url) . '#switcher_position" style="color:#dc2626;">',
                        '</a>'
                    ); ?>
                </p>
            </div>
            <script type="text/javascript">
            (function(){
                var el = document.getElementById('menu-item-<?php echo (int) $item_id; ?>');
                if (el) {
                    var handle = el.querySelector('.menu-item-handle');
                    if (handle) { handle.style.borderLeft = '4px solid #dc2626'; }
                    var title = el.querySelector('.menu-item-title');
                    if (title) { title.style.color = '#dc2626'; }
                }
            })();
            </script>
            <?php
        }
    }
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
 * Normalize language switcher CSS classes so detection works regardless of how
 * the menu item was added (meta box sets 'ai-language-switcher', auto-add sets
 * 'menu-item-language-switcher'). Also adds 'menu-item-has-children' for themes
 * that need it for dropdown styling.
 */
add_filter('nav_menu_css_class', function($classes, $item) {
    $is_switcher = in_array('ai-language-switcher', $classes, true)
        || in_array('menu-item-language-switcher', $classes, true)
        || $item->object === 'ai_language_switcher'
        || get_post_meta($item->ID, '_menu_item_is_language_switcher', true) === '1';

    if ($is_switcher) {
        if (!in_array('ai-language-switcher', $classes, true)) {
            $classes[] = 'ai-language-switcher';
        }
        if (!in_array('menu-item-language-switcher', $classes, true)) {
            $classes[] = 'menu-item-language-switcher';
        }
        if (!in_array('menu-item-has-children', $classes, true)) {
            $classes[] = 'menu-item-has-children';
        }
    }

    return $classes;
}, 10, 2);

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
    if ($default_language !== '' && !in_array($default_language, $enabled_languages, true)) {
        $enabled_languages[] = $default_language;
    }

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
    $path_no_lang = ai_translate_canonical_path($path_no_lang);

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
                            'menu-item-title' => __('Language Switcher', 'ai-translate')
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
    if ($default_language !== '' && !in_array($default_language, $enabled_languages, true)) {
        $enabled_languages[] = $default_language;
    }

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
    $path_no_lang = ai_translate_canonical_path($path_no_lang);

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
        var replacementHtml = <?php echo json_encode($replacement_html); ?>;

        function initLanguageSwitcherReplacement() {

            // Find language switcher items by stable classes/object markers (not translated text).
            const candidates = document.querySelectorAll(
                'li.menu-item-language-switcher > a[href="#"], li.ai-language-switcher > a[href="#"], li[class*="menu-item-object-ai_language_switcher"] > a[href="#"], a.ai-menu-language-current[href="#"]'
            );

            candidates.forEach(function(link) {
                const listItem = link.closest('li');
                if (!listItem) {
                    return;
                }
                // Replace only when not already rendered with a submenu.
                if (!listItem.querySelector('.sub-menu') || !listItem.classList.contains('menu-item-language-switcher')) {
                    listItem.outerHTML = replacementHtml;
                }
            });

            // Safety net: if a language-switcher container exists without submenu, extract and
            // append the submenu from the replacement HTML rather than a second JSON blob.
            if (candidates.length === 0) {
                const switcherItems = document.querySelectorAll('li.menu-item-language-switcher');
                switcherItems.forEach(function(listItem) {
                    if (!listItem.querySelector('.sub-menu')) {
                        var tmp = document.createElement('ul');
                        tmp.innerHTML = replacementHtml;
                        var subMenu = tmp.querySelector('.sub-menu');
                        if (subMenu) {
                            listItem.appendChild(subMenu);
                        }
                        listItem.classList.add('menu-item-has-children');
                    }
                });
            }
        }

        // Run immediately, then once after a short delay for dynamically loaded content.
        initLanguageSwitcherReplacement();
        setTimeout(initLanguageSwitcherReplacement, 500);

        // Set cookie BEFORE navigating on language switch so server-side redirects
        // (RULE 4 / RULE 5) immediately see the new preference and don't bounce back.
        // Use the same domain format as PHP (.hostname) to avoid duplicate cookie conflicts
        // where the browser sends both an old PHP-set cookie and a new JS-set cookie.
        document.addEventListener('click', function(e) {
            var link = e.target.closest('a[data-lang]');
            if (link) {
                var lang = link.getAttribute('data-lang');
                if (lang) {
                    var host = window.location.hostname;
                    var cookieDomain = (host.indexOf('.') !== -1 && !/^\d+\.\d+\.\d+\.\d+$/.test(host)) ? '.' + host : '';
                    var cookieStr = 'ai_translate_lang=' + encodeURIComponent(lang) + '; path=/; max-age=2592000; SameSite=Lax; Secure';
                    if (cookieDomain) { cookieStr += '; domain=' + cookieDomain; }
                    document.cookie = cookieStr;
                    // Also clear any cookie set without domain (plain hostname) to avoid duplicates
                    document.cookie = 'ai_translate_lang=; path=/; max-age=0; SameSite=Lax; Secure';
                }
            }
        }, true);

    })();
    </script>
    <?php
});

/**
 * Save custom menu item fields.
 * Guard: only process when the menu editor custom fields were actually rendered
 * for this item. The switcher-type <select> is always submitted (even when its
 * parent div is hidden), so its presence proves the form was rendered. On the
 * initial add via the meta box, custom fields are not yet rendered, and we must
 * not overwrite the defaults set by the meta-box save handler.
 */
add_action('wp_update_nav_menu_item', function($menu_id, $menu_item_db_id) {
    if (!isset($_POST['menu-item-switcher-type'][$menu_item_db_id])) {
        return;
    }

    if (isset($_POST['menu-item-is-language-switcher'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_is_language_switcher', '1');
        update_post_meta($menu_item_db_id, '_menu_item_switcher_type',
            sanitize_text_field($_POST['menu-item-switcher-type'][$menu_item_db_id]));
        update_post_meta($menu_item_db_id, '_menu_item_show_flags',
            isset($_POST['menu-item-show-flags'][$menu_item_db_id]) ? 'true' : 'false');
        update_post_meta($menu_item_db_id, '_menu_item_show_codes',
            isset($_POST['menu-item-show-codes'][$menu_item_db_id]) ? 'true' : 'false');
    } else {
        delete_post_meta($menu_item_db_id, '_menu_item_is_language_switcher');
        delete_post_meta($menu_item_db_id, '_menu_item_switcher_type');
        delete_post_meta($menu_item_db_id, '_menu_item_show_flags');
        delete_post_meta($menu_item_db_id, '_menu_item_show_codes');
    }
}, 10, 2);

/**
 * Render language switcher via walker_nav_menu_start_el filter.
 * This fires for ANY walker (including Elementor Pro, Astra, etc.) so the
 * switcher is rendered server-side even when the custom walker is bypassed.
 * Our own AI_Translate_Menu_Walker returns early before parent::start_el(),
 * so this filter never fires for items already handled by the custom walker.
 */
add_filter('walker_nav_menu_start_el', function($item_output, $item, $depth, $args) {
    $classes = is_array($item->classes) ? $item->classes : array();
    $is_switcher = in_array('ai-language-switcher', $classes, true)
        || in_array('menu-item-language-switcher', $classes, true)
        || $item->object === 'ai_language_switcher'
        || get_post_meta($item->ID, '_menu_item_is_language_switcher', true) === '1';

    if (!$is_switcher) {
        return $item_output;
    }

    $settings       = get_option('ai_translate_settings', array());
    $enabled_langs  = isset($settings['enabled_languages']) && is_array($settings['enabled_languages'])
        ? array_values($settings['enabled_languages']) : array();
    $default_lang   = isset($settings['default_language']) ? (string) $settings['default_language'] : '';
    if ($default_lang !== '' && !in_array($default_lang, $enabled_langs, true)) {
        $enabled_langs[] = $default_lang;
    }
    if (empty($enabled_langs) || $default_lang === '') {
        return $item_output;
    }

    $req_uri      = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path         = (string) parse_url($req_uri, PHP_URL_PATH);
    if ($path === '') { $path = '/'; }
    $current_lang = $default_lang;
    if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $m)) {
        $current_lang = strtolower($m[1]);
    }
    $path_no_lang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
    if ($path_no_lang === '') { $path_no_lang = '/'; }
    if (function_exists('ai_translate_canonical_path')) {
        $path_no_lang = ai_translate_canonical_path($path_no_lang);
    }

    $flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';

    $out  = '<a href="#" class="ai-menu-language-current" data-ai-trans-skip="1">';
    $out .= '<img src="' . esc_url($flags_url . sanitize_key($current_lang) . '.png') . '" alt="' . esc_attr(strtoupper($current_lang)) . '" class="ai-menu-language-flag" />';
    $out .= '<span class="ai-menu-language-code">' . esc_html(strtoupper($current_lang)) . '</span>';
    $out .= '</a>';

    $out .= '<ul class="sub-menu children">';
    foreach ($enabled_langs as $lc) {
        $lc = sanitize_key($lc);
        if ($lc === $current_lang) { continue; }
        $lang_url = ($lc === $default_lang)
            ? esc_url($path_no_lang)
            : esc_url('/' . $lc . $path_no_lang);
        $out .= '<li class="menu-item ai-menu-language-item">';
        $out .= '<a href="' . $lang_url . '" data-lang="' . esc_attr($lc) . '" data-ai-trans-skip="1">';
        $out .= '<img src="' . esc_url($flags_url . $lc . '.png') . '" alt="' . esc_attr(strtoupper($lc)) . '" class="ai-menu-language-flag" />';
        $out .= '<span class="ai-menu-language-code">' . esc_html(strtoupper($lc)) . '</span>';
        $out .= '</a></li>';
    }
    $out .= '</ul>';

    return $out;
}, 10, 4);

/**
 * Custom menu walker to render language switcher items
 */
class AI_Translate_Menu_Walker extends Walker_Nav_Menu {
    private $skip_item_id = 0;

    private function is_switcher_item($item) {
        return $item->object === 'ai_language_switcher' ||
               get_post_meta($item->ID, '_menu_item_is_language_switcher', true) === '1';
    }

    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
        if ($this->is_switcher_item($item)) {
            $settings = get_option('ai_translate_settings', array());
            $position = isset($settings['switcher_position']) ? $settings['switcher_position'] : 'bottom-left';
            if ($position !== 'none') {
                $this->skip_item_id = $item->ID;
                return;
            }
            $this->render_language_switcher_menu_item($output, $item, $depth, $args, $id);
            return;
        }

        parent::start_el($output, $item, $depth, $args, $id);
    }

    public function end_el(&$output, $item, $depth = 0, $args = null) {
        if ($this->skip_item_id === $item->ID) {
            $this->skip_item_id = 0;
            return;
        }
        parent::end_el($output, $item, $depth, $args);
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
        if ($default_language !== '' && !in_array($default_language, $enabled_languages, true)) {
            $enabled_languages[] = $default_language;
        }

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
