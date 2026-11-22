<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin notices for the OpenRouter Zero Data Retention setting.
 *
 * - An update never switches ZDR on by itself. Installs that use OpenRouter get a hint instead, which
 *   stays until the admin dismisses it or switches ZDR on.
 * - With ZDR on, a model without a ZDR endpoint makes translations fail. That is shown on every admin
 *   page until the model is changed or a request for it succeeds again.
 */

function zdr_settings_url(): string
{
    return admin_url('admin.php?page=ai-translate');
}

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) {
        return;
    }
    $settings = AI_Translate_Core::settings();

    if (!empty($settings[ZDR_SETTING])) {
        $failures = get_transient(ZDR_FAILURES_TRANSIENT);
        if (is_array($failures) && $failures !== []) {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__('AI Translate:', 'ai-translate') . '</strong> ';
            echo esc_html(sprintf(
                /* translators: %s: comma-separated model IDs */
                __('Zero Data Retention is on, but OpenRouter has no endpoint without data retention for: %s. Translations with this model are failing.', 'ai-translate'),
                implode(', ', array_map('sanitize_text_field', array_keys($failures)))
            ));
            echo ' <a href="' . esc_url(zdr_settings_url()) . '">' . esc_html__('Choose another model or switch ZDR off', 'ai-translate') . '</a>.</p></div>';
        }
        return;
    }

    if (!zdr_provider_is_openrouter($settings) || get_option(ZDR_NOTICE_DISMISSED)) {
        return;
    }
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $on_plugin_page = isset($_GET['page']) && $_GET['page'] === 'ai-translate'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only
    if (!$on_plugin_page && (!$screen || !in_array($screen->base, ['dashboard', 'plugins'], true))) {
        return;
    }
    $dismiss = wp_nonce_url(admin_url('admin-post.php?action=ai_translate_zdr_dismiss'), 'ai_translate_zdr_dismiss');
    echo '<div class="notice notice-info"><p><strong>' . esc_html__('AI Translate:', 'ai-translate') . '</strong> ';
    echo esc_html__('You can now require OpenRouter to use only providers that do not store your prompts or translations (Zero Data Retention). It is off for existing installs, so nothing changes until you switch it on. Recommended after the recent incidents at AI providers.', 'ai-translate');
    echo ' <a href="' . esc_url(zdr_settings_url()) . '">' . esc_html__('Open settings', 'ai-translate') . '</a> | ';
    echo '<a href="' . esc_url($dismiss) . '">' . esc_html__('Do not show again', 'ai-translate') . '</a></p></div>';
});

add_action('admin_post_ai_translate_zdr_dismiss', function () {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'ai-translate'), '', ['response' => 403]);
    }
    check_admin_referer('ai_translate_zdr_dismiss');
    update_option(ZDR_NOTICE_DISMISSED, 1, false);
    wp_safe_redirect(wp_get_referer() ?: zdr_settings_url());
    exit;
});

// A saved change of settings (model, provider, ZDR) starts the failure list afresh.
add_action('update_option_ai_translate_settings', function () {
    delete_transient(ZDR_FAILURES_TRANSIENT);
});
