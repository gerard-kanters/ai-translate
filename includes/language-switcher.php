<?php

/**
 * Language switcher: HTML generation, shortcode, block and front-end assets.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate language switcher HTML (shared between shortcode and menu)
 */
function ai_translate_generate_switcher_html($type = 'dropdown', $show_flags = true, $show_codes = true, $class = '') {
    // Get plugin settings
    $enabled_languages = \AITranslate\AI_Translate_Core::enabled_languages();
    $default_language = \AITranslate\AI_Translate_Core::default_language();
    if ($default_language !== '' && !in_array($default_language, $enabled_languages, true)) {
        $enabled_languages[] = $default_language;
    }

    if (empty($enabled_languages) || empty($default_language)) {
        return '';
    }

    // Determine current language
    $current_lang = null;
    $req_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
    $path = (string) wp_parse_url($req_uri, PHP_URL_PATH);
    if ($path === '') {
        $path = '/';
    }
    $path = ai_translate_strip_site_path($path);

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

    $flags_url = plugin_dir_url(AI_TRANSLATE_PLUGIN_FILE) . 'assets/flags/';

    if ($type === 'inline') {
        $output = '<div class="ai-language-switcher-inline ' . esc_attr($class) . '" data-ai-trans-skip="1">';
        foreach ($enabled_languages as $lang_code) {
            $lang_code = sanitize_key($lang_code);
            if ($lang_code === $current_lang) continue;

            $lang_label = strtoupper($lang_code);
            $sub = ai_translate_site_path();
            $lang_url = $lang_code === $default_language ? esc_url($sub . $path_no_lang) : esc_url($sub . '/' . $lang_code . $path_no_lang);

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

        $output = '<div class="ai-language-switcher-dropdown ' . esc_attr($class) . '" id="' . esc_attr($unique_id) . '" data-ai-trans-skip="1">';
        $output .= '<button type="button" class="ai-language-switcher-btn" aria-haspopup="true" aria-expanded="false" aria-controls="' . esc_attr($unique_id) . '-menu">';
        if ($show_flags) $output .= '<img src="' . $current_flag . '" alt="' . esc_attr($current_lang) . '" class="ai-language-flag" />';
        if ($show_codes) $output .= '<span class="ai-language-code">' . esc_html($current_label) . '</span>';
        $output .= '<span class="ai-language-arrow" aria-hidden="true">▼</span></button>';

        $output .= '<div class="ai-language-switcher-menu" id="' . esc_attr($unique_id) . '-menu" role="menu" hidden>';
        foreach ($enabled_languages as $lang_code) {
            $lang_code = sanitize_key($lang_code);
            if ($lang_code === $current_lang) continue;

            $lang_label = strtoupper($lang_code);
            $sub = ai_translate_site_path();
            $lang_url = $lang_code === $default_language ? esc_url($sub . $path_no_lang) : esc_url($sub . '/' . $lang_code . $path_no_lang);

            $output .= '<a href="' . $lang_url . '" class="ai-language-item" role="menuitem" data-lang="' . esc_attr($lang_code) . '" data-ai-trans-skip="1">';
            if ($show_flags) $output .= '<img src="' . esc_url($flags_url . $lang_code . '.png') . '" alt="' . esc_attr($lang_label) . '" class="ai-language-flag" />';
            if ($show_codes) $output .= '<span class="ai-language-code">' . esc_html($lang_label) . '</span>';
            $output .= '</a>';
        }
        $output .= '</div></div>';
    }

    return $output;
}

/**
 * Shortcode callback for [ai_language_switcher].
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function ai_translate_language_switcher_shortcode($atts = [])
{
    $atts = shortcode_atts([
        'type' => 'dropdown',
        'show_flags' => 'true',
        'show_codes' => 'true',
        'class' => '',
    ], $atts, 'ai_language_switcher');

    $type = (isset($atts['type']) && $atts['type'] === 'inline') ? 'inline' : 'dropdown';
    $falsey = ['false', '0', 'no', 'off'];
    $show_flags = !in_array(strtolower((string) $atts['show_flags']), $falsey, true);
    $show_codes = !in_array(strtolower((string) $atts['show_codes']), $falsey, true);
    $class = sanitize_text_field((string) $atts['class']);

    return ai_translate_generate_switcher_html($type, $show_flags, $show_codes, $class);
}
add_shortcode('ai_language_switcher', 'ai_translate_language_switcher_shortcode');

/**
 * Register the AI Language Switcher block for block themes (FSE) and the block editor.
 * Block themes have no Appearance → Menus screen, so the menu meta box integration is
 * unreachable there. Server-rendered via the same shared renderer as the shortcode.
 */
add_action('init', function () {
    if (!function_exists('register_block_type')) {
        return;
    }
    wp_register_script(
        'ai-translate-block-editor',
        plugin_dir_url(AI_TRANSLATE_PLUGIN_FILE) . 'assets/ai-translate-block.js',
        ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n'],
        filemtime(plugin_dir_path(AI_TRANSLATE_PLUGIN_FILE) . 'assets/ai-translate-block.js'),
        true
    );
    register_block_type('ai-translate/language-switcher', [
        'editor_script' => 'ai-translate-block-editor',
        'attributes' => [
            'type' => ['type' => 'string', 'default' => 'dropdown'],
            'showFlags' => ['type' => 'boolean', 'default' => true],
            'showCodes' => ['type' => 'boolean', 'default' => true],
        ],
        'render_callback' => function ($attributes) {
            $type = isset($attributes['type']) && $attributes['type'] === 'inline' ? 'inline' : 'dropdown';
            $show_flags = !isset($attributes['showFlags']) || (bool) $attributes['showFlags'];
            $show_codes = !isset($attributes['showCodes']) || (bool) $attributes['showCodes'];
            return ai_translate_generate_switcher_html($type, $show_flags, $show_codes);
        },
    ]);
});

/**
 * Enqueue CSS and JavaScript for the language switcher shortcode
 */
add_action('wp_enqueue_scripts', function() {
    // Always enqueue for frontend (needed for menu integration)
    if (!is_admin()) {
        wp_enqueue_style(
            'ai-language-switcher',
            plugin_dir_url(AI_TRANSLATE_PLUGIN_FILE) . 'assets/language-switcher.css',
            array(),
            filemtime(plugin_dir_path(AI_TRANSLATE_PLUGIN_FILE) . 'assets/language-switcher.css')
        );

        wp_enqueue_script(
            'ai-language-switcher',
            plugin_dir_url(AI_TRANSLATE_PLUGIN_FILE) . 'assets/language-switcher.js',
            array(),
            filemtime(plugin_dir_path(AI_TRANSLATE_PLUGIN_FILE) . 'assets/language-switcher.js'),
            true
        );
    }
});
