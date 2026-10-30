<?php

/**
 * Language-prefixed rewrite rules, query vars and canonical redirect handling.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register language-prefixed rewrite rules so WordPress can resolve translated URLs via the 'lang' query var.
 * Runs at priority 999 to ensure custom post types are registered first.
 *
 * @return void
 */
function ai_translate_register_rewrite_rules()
{
    $enabled = \AITranslate\AI_Translate_Core::enabled_languages();
    $detectable = \AITranslate\AI_Translate_Core::detectable_languages();
    $default = \AITranslate\AI_Translate_Core::default_language();
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

// Ensure slug map and history tables exist — only run dbDelta when schema version changes
add_action('init', function () {
    $current_version = '2.2.6';
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
    $reqPath = ai_translate_request_path();
    $defaultLang = \AITranslate\AI_Lang::default();
    
    $langFromUrl = ai_translate_lang_in_path($reqPath);
    if ($langFromUrl !== null) {
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
    $path = ai_translate_strip_site_path((string) wp_parse_url($req, PHP_URL_PATH));
    $langFromUrl = ai_translate_lang_in_path($path);
    if ($langFromUrl !== null) {
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
