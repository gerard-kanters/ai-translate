<?php

/**
 * Remap translated slugs before WordPress renders a 404, and home_url handling.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

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
    $reqPath = ai_translate_request_path();
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
    $reqPath = ai_translate_request_path();
    $langFromUrl = ai_translate_lang_in_path($reqPath);
    
    // Only modify if we're currently on a language-prefixed page
    if ($langFromUrl === null) {
        return $url;
    }
    
    $defaultLang = \AITranslate\AI_Lang::default();
    
    // Only add language prefix if it's not the default language
    if ($langFromUrl !== '' && $defaultLang && strtolower($langFromUrl) !== strtolower((string) $defaultLang)) {
        // Check if URL already has language prefix
        $urlPath = wp_parse_url($url, PHP_URL_PATH);
        if ($urlPath && !preg_match('#^/([a-z]{2})(?:/|$)#i', $urlPath)) {
            // Add language prefix to the URL, preserving any site subpath (e.g. '/wordpress')
            $parsed = wp_parse_url($url);
            // Relatieve URLs hebben geen scheme/host; dan niet herschrijven
            if (!isset($parsed['scheme']) || !isset($parsed['host'])) {
                return $url;
            }
            $basePath = rtrim($parsed['path'] ?? '', '/');
            $newPath = $basePath . '/' . $langFromUrl . '/';
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
