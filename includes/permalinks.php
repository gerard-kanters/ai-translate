<?php

/**
 * Translated permalinks (post_link, page_link, post_type_link).
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build a root-relative translated path for a post in the given language.
 * Includes CPT rewrite prefix when applicable (e.g. WooCommerce product_base).
 *
 * @param int    $post_id Post ID.
 * @param string $lang    Target language code.
 * @param string $default Default/source language code.
 * @param string $trail   Trailing slash (or empty).
 * @return string|null Path or null when slug cannot be resolved.
 */
function ai_translate_build_translated_path($post_id, $lang, $default, $trail = '/') {
    $post_id = (int) $post_id;
    $lang = sanitize_key((string) $lang);
    $default = sanitize_key((string) $default);
    $translated = \AITranslate\AI_Slugs::get_or_generate($post_id, $lang);
    if ($translated === null) {
        return null;
    }
    $cpt_prefix = '';
    $post = get_post($post_id);
    if ($post && function_exists('ai_translate_cpt_path_prefix')) {
        $cpt_prefix = ai_translate_cpt_path_prefix((string) $post->post_type);
    }
    $slug = trim((string) $translated, '/');
    if (strtolower($lang) === strtolower($default)) {
        return '/' . $cpt_prefix . $slug . $trail;
    }
    return '/' . $lang . '/' . $cpt_prefix . $slug . $trail;
}

add_filter('post_link', function ($permalink, $post, $leavename) {
    if (is_admin()) return $permalink;
    if (ai_translate_is_xml_request()) return $permalink;
    $lang = \AITranslate\AI_Lang::current();
    $default = \AITranslate\AI_Lang::default();
    if ($lang === null || $default === null || strtolower($lang) === strtolower($default)) {
        return $permalink;
    }
    $trail = substr($permalink, -1) === '/' ? '/' : '';
    $path = ai_translate_build_translated_path((int) $post->ID, $lang, $default, $trail);
    return $path === null ? $permalink : home_url($path);
}, 10, 3);

add_filter('page_link', function ($permalink, $post_id, $sample) {
    if (is_admin()) return $permalink;
    if (ai_translate_is_xml_request()) return $permalink;
    $lang = \AITranslate\AI_Lang::current();
    $default = \AITranslate\AI_Lang::default();
    if ($lang === null || $default === null || strtolower($lang) === strtolower($default)) {
        return $permalink;
    }
    $trail = substr($permalink, -1) === '/' ? '/' : '';
    $path = ai_translate_build_translated_path((int) $post_id, $lang, $default, $trail);
    return $path === null ? $permalink : home_url($path);
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
    $trail = substr($permalink, -1) === '/' ? '/' : '';
    $path = ai_translate_build_translated_path((int) $post->ID, $lang, $default, $trail);
    return $path === null ? $permalink : home_url($path);
}, 10, 4);
