<?php

/**
 * Query and search adjustments and admin notices.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

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
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
    $rate_key = 'ai_tr_search_rate_' . md5($ip);
    $rate_count = (int) get_transient($rate_key);
    if ($rate_count >= 10) {
        // Rate limit exceeded: skip translation, use original query
        return;
    }
    set_transient($rate_key, $rate_count + 1, 60);

    $ctx = array(
        'website_context' => (string) \AITranslate\AI_Translate_Core::get_setting('website_context', ''),
    );
    // Type "node": the prompt reads "meta" as a URL slug, so a term of several words came back
    // hyphenated ("Digitale-tweelingen") and then found nothing.
    $plan = array('segments' => array(
        array('id' => 'q1', 'text' => $original, 'type' => 'node'),
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
