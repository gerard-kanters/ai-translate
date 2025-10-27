<?php

namespace AITranslate;

// Load the core class if it doesn't exist yet.
// Since this file is in the "includes" folder, the core class is at the same level.
if (! class_exists('AI_Translate_Core')) {
    $core_class_file = plugin_dir_path(__FILE__) . 'class-ai-translate-core.php';
    if (file_exists($core_class_file)) {
        require_once $core_class_file;
    } else {
        add_action('admin_notices', function () use ($core_class_file) {
            echo '<div class="error"><p>Core class not found. Make sure the file exists at: ' . esc_html($core_class_file) . '</p></div>';
        });
    }
}

/**
 * Handle AJAX request to clear cache for a specific language.
 *
 * Security: requires manage_options capability and a valid nonce.
 * Output is JSON via wp_send_json_*.
 */
function ajax_clear_cache_language()
{
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions to perform this action.']);
        return;
    }

    // Validate nonce
    $nonce_value = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : null;
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'clear_cache_language_action')) {
        wp_send_json_error(['message' => 'Security check failed (nonce). Refresh the page and try again.']);
        return;
    }

    // Validate language code
    if (!isset($_POST['lang_code']) || empty($_POST['lang_code'])) {
        wp_send_json_error(['message' => 'No language selected.']);
        return;
    }

    $lang_code = sanitize_text_field(wp_unslash($_POST['lang_code']));

    // Clear the cache for this language
    $translator = AI_Translate_Core::get_instance();
    try {
        $count = $translator->clear_cache_for_language($lang_code);
        wp_send_json_success([
            'message' => sprintf('Cache for language "%s" cleared. %d files removed.', $lang_code, $count),
            'count' => $count
        ]);
    } catch (\Exception $e) {
        wp_send_json_error([
            'message' => 'Error clearing cache: ' . $e->getMessage()
        ]);
    }
}

// Register the AJAX handlers
add_action('wp_ajax_ai_translate_clear_cache_language', __NAMESPACE__ . '\\ajax_clear_cache_language');

/**
 * Handle AJAX request to generate a website context suggestion.
 *
 * Security: requires manage_options capability and a valid nonce.
 * Clears prompt cache to ensure immediate use of new context.
 */
function ajax_generate_website_context()
{
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Insufficient permissions to perform this action.']);
        return;
    }

    // Validate nonce
    $nonce_value = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : null;
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'generate_website_context_nonce')) {
        wp_send_json_error(['message' => 'Security check failed (nonce). Refresh the page and try again.']);
        return;
    }

    // Generate website context suggestion
    $translator = AI_Translate_Core::get_instance();
    try {
        $context_suggestion = $translator->generate_website_context_suggestion();
        
        // Clear prompt cache to ensure new context is used immediately
        $translator->clear_prompt_cache();
        
        wp_send_json_success([
            'context' => $context_suggestion
        ]);
    } catch (\Exception $e) {
        wp_send_json_error([
            'message' => 'Error generating context: ' . $e->getMessage()
        ]);
    }
}

// Register the new AJAX handler
add_action('wp_ajax_ai_translate_generate_website_context', __NAMESPACE__ . '\\ajax_generate_website_context');

/**
 * Clear prompt cache when settings are updated (specifically when website context changes)
 */
add_action('update_option_ai_translate_settings', function ($old_value, $value) {
    // Check if website context has changed
    $old_context = isset($old_value['website_context']) ? $old_value['website_context'] : '';
    $new_context = isset($value['website_context']) ? $value['website_context'] : '';
    
    if ($old_context !== $new_context) {
        // Clear prompt cache to force regeneration with new context
        if (class_exists('AI_Translate_Core')) {
            $core = AI_Translate_Core::get_instance();
            $core->clear_prompt_cache();
        }
    }
}, 10, 2);

/**
 * AJAX handlers and admin UI bootstrap below.
 */

// Add admin menu
add_action('admin_menu', function () {
    add_menu_page(
        'AI Translate',
        'AI Translate',
        'manage_options',
        'ai-translate',
        __NAMESPACE__ . '\\render_admin_page',
        'dashicons-translation',
        100
    );
});

// Enqueue admin scripts and styles
add_action('admin_enqueue_scripts', function ($hook) {
    // Only load on our plugin's admin page
    if ($hook !== 'toplevel_page_ai-translate') {
        return;
    }

    // Enqueue CSS
    wp_enqueue_style(
        'ai-translate-admin-css',
        plugin_dir_url(__DIR__) . 'assets/admin-page.css',
        array(),
        '1.0.0'
    );

    // Enqueue main admin JavaScript
    wp_enqueue_script(
        'ai-translate-admin-js',
        plugin_dir_url(__DIR__) . 'assets/admin-page.js',
        array('jquery'),
        '1.0.0',
        true
    );

    // Localize script with data needed by JavaScript
    wp_localize_script('ai-translate-admin-js', 'aiTranslateAdmin', array(
        'adminUrl' => esc_url(admin_url('admin.php?page=ai-translate')),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'getModelsNonce' => wp_create_nonce('ai_translate_get_models_nonce'),
        'validateApiNonce' => wp_create_nonce('ai_translate_validate_api_nonce'),
        'getCustomUrlNonce' => wp_create_nonce('ai_translate_get_custom_url_nonce'), // Added nonce for fetching custom URL
        'generateContextNonce' => wp_create_nonce('generate_website_context_nonce'), // Added nonce for generating website context
        'apiKeys' => get_option('ai_translate_settings')['api_keys'] ?? [], // Voeg deze regel toe
        'models' => get_option('ai_translate_settings')['models'] ?? [], // Per-provider models
        'customModel' => get_option('ai_translate_settings')['custom_model'] ?? '', // Custom model veld
    ));
});

// Register settings (without the shortcodes section)
add_action('admin_init', function () {
    register_setting('ai_translate', 'ai_translate_settings', [
        'sanitize_callback' => function ($input) {
            // Start from existing settings to avoid accidental defaults.
            $current_settings = get_option('ai_translate_settings', []);
            $sanitized = is_array($current_settings) ? $current_settings : [];

            // Ensure expected arrays exist
            if (!isset($sanitized['api_keys']) || !is_array($sanitized['api_keys'])) {
                $sanitized['api_keys'] = [];
            }
            if (!isset($sanitized['models']) || !is_array($sanitized['models'])) {
                $sanitized['models'] = [];
            }

            // Provider
            $valid_providers = array_keys(AI_Translate_Core::get_api_providers());
            if (isset($input['api_provider'])) {
                $prov = sanitize_text_field($input['api_provider']);
                if (in_array($prov, $valid_providers, true)) {
                    $sanitized['api_provider'] = $prov;
                } else {
                    add_settings_error(
                        'ai_translate_settings',
                        'invalid_api_provider',
                        __('Invalid API provider selected.', 'ai-translate'),
                        'error'
                    );
                }
            }

            // legacy key removal
            if (isset($input['api_url'])) {
                unset($input['api_url']);
            }

            // Custom API URL
            if (isset($input['custom_api_url'])) {
                $sanitized['custom_api_url'] = esc_url_raw(trim($input['custom_api_url']));
            }
            if (($sanitized['api_provider'] ?? null) === 'custom') {
                if (empty($sanitized['custom_api_url'])) {
                    add_settings_error(
                        'ai_translate_settings',
                        'custom_url_required',
                        __('Custom API URL is required for the custom provider.', 'ai-translate'),
                        'error'
                    );
                }
            }

            // Cache expiration (days → hours), minimum 14 days
            if (isset($input['cache_expiration'])) {
                $cache_days = intval($input['cache_expiration']);
                if ($cache_days < 14) {
                    add_settings_error(
                        'ai_translate_settings',
                        'cache_duration_too_low',
                        __('Cache duration cannot be less than 14 days. It has been automatically set to 14 days.', 'ai-translate'),
                        'warning'
                    );
                    $cache_days = 14;
                }
                $sanitized['cache_expiration'] = $cache_days * 24;
            } elseif (!isset($sanitized['cache_expiration'])) {
                // Initialize if not set at all
                $sanitized['cache_expiration'] = 14 * 24;
            }

            // Auto-clear pages on menu update (checkbox)
            $sanitized['auto_clear_pages_on_menu_update'] = isset($input['auto_clear_pages_on_menu_update']) ? true : false;

            // Model selection (per provider)
            if (isset($input['selected_model'])) {
                $selected_provider = $sanitized['api_provider'] ?? ($current_settings['api_provider'] ?? null);
                $model_to_store = null;
                if ($input['selected_model'] === 'custom') {
                    if (!empty($input['custom_model'])) {
                        $model_to_store = trim($input['custom_model']);
                    } else {
                        add_settings_error(
                            'ai_translate_settings',
                            'custom_model_required',
                            __('Custom model name is required when selecting a custom model.', 'ai-translate'),
                            'error'
                        );
                    }
                } else {
                    $model_to_store = trim(sanitize_text_field($input['selected_model']));
                }
                if ($selected_provider && $model_to_store !== null) {
                    $sanitized['models'][$selected_provider] = $model_to_store;
                    $sanitized['selected_model'] = $model_to_store;
                }
            }

            // API key per provider
            if (isset($input['api_key'])) {
                $provider_for_key = $sanitized['api_provider'] ?? ($input['api_provider'] ?? ($current_settings['api_provider'] ?? null));
                if ($provider_for_key) {
                    $key_val = trim(sanitize_text_field($input['api_key']));
                    $sanitized['api_keys'][$provider_for_key] = $key_val;
                    if ($key_val === '') {
                        add_settings_error(
                            'ai_translate_settings',
                            'api_key_missing',
                            __('API key is required for the selected provider.', 'ai-translate'),
                            'error'
                        );
                    }
                }
            }

            // Homepage meta description
            if (isset($input['homepage_meta_description'])) {
                $sanitized['homepage_meta_description'] = sanitize_textarea_field($input['homepage_meta_description']);
            }

            // Website context
            if (isset($input['website_context'])) {
                $sanitized['website_context'] = sanitize_textarea_field($input['website_context']);
            }

            // Enabled languages (switcher)
            if (isset($input['enabled_languages']) && is_array($input['enabled_languages'])) {
                $sanitized['enabled_languages'] = array_values(array_unique(array_map('sanitize_text_field', $input['enabled_languages'])));
            }

            // Detectable languages (auto)
            if (isset($input['detectable_languages']) && is_array($input['detectable_languages'])) {
                $sanitized['detectable_languages'] = array_values(array_unique(array_map('sanitize_text_field', $input['detectable_languages'])));
            }

            // Multilingual search toggle
            $sanitized['enable_multilingual_search'] = isset($input['enable_multilingual_search']) ? (bool)$input['enable_multilingual_search'] : false;

            return $sanitized;
        }
    ]);

    // API Settings Section
    add_settings_section(
        'ai_translate_api',
        'API Settings',
        null,
        'ai-translate'
    );
    add_settings_field(
        'api_provider',
        'API Provider',
        function () {
            $settings = get_option('ai_translate_settings');
            $current_provider_key = isset($settings['api_provider']) ? $settings['api_provider'] : '';
            $providers = AI_Translate_Core::get_api_providers();

            echo '<select id="api_provider_select" name="ai_translate_settings[api_provider]">';
            echo '<option value="" ' . selected($current_provider_key, '', false) . ' disabled hidden>— Select provider —</option>';
            foreach ($providers as $key => $provider_details) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($current_provider_key, $key, false) . '>' . esc_html($provider_details['name']) . '</option>';
            }
            echo '</select>';
            // GPT-5 warning for OpenAI
            echo '<div id="openai_gpt5_warning" style="margin-top:10px; display:none;">';
            echo '<p class="description" style="color: #d63638;"><strong>Note:</strong> GPT-5 is blocked, since reasoning cannot be disabled and not required for translations and therefore too slow and expensive.</p>';
            echo '</div>';
            // Custom URL field
            echo '<div id="custom_api_url_div" style="margin-top:10px; display:none;">';
            echo '<input type="url" name="ai_translate_settings[custom_api_url]" value="' . esc_attr($settings['custom_api_url'] ?? '') . '" placeholder="https://your-custom-api.com/v1/" class="regular-text">';
            echo '<p class="description">Enter the endpoint URL for your custom API provider. Example: <a href="https://air.netcare.nl/v1/" target="_blank">https://air.netcare.nl/v1/</a></p>';
            echo '</div>';

        },
        'ai-translate',
        'ai_translate_api'
    );
    add_settings_field(
        'api_key',
        'API Key',
        function () {
            $settings = get_option('ai_translate_settings');
            $current_provider_key = isset($settings['api_provider']) ? $settings['api_provider'] : '';
            // Haal de API-sleutel op uit de nieuwe 'api_keys' array
            $api_keys = $settings['api_keys'] ?? [];
            $value = $api_keys[$current_provider_key] ?? ''; // Toon de sleutel voor de geselecteerde provider
            echo '<input type="password" name="ai_translate_settings[api_key]" value="' . esc_attr($value) . '" class="regular-text">';
            echo ' <span id="api-key-request-link-span" style="margin-left:10px;"></span>';
        },
        'ai-translate',
        'ai_translate_api'
    );
    add_settings_field(
        'selected_model',
        'Translation Model',
        function () {
            $settings = get_option('ai_translate_settings');
            $current_provider = isset($settings['api_provider']) ? $settings['api_provider'] : '';
            $models = isset($settings['models']) ? $settings['models'] : [];
            $selected_model = $current_provider !== '' ? ($models[$current_provider] ?? '') : '';
            $custom_model = isset($settings['custom_model']) ? $settings['custom_model'] : '';
            echo '<select name="ai_translate_settings[selected_model]" id="selected_model">';
            echo '<option value="" ' . selected($selected_model, '', false) . ' disabled hidden>— Select model —</option>';
            if ($selected_model) {
                echo '<option value="' . esc_attr($selected_model) . '" selected>' . esc_html($selected_model) . '</option>';
            }
            echo '<option value="custom">Select...</option>';
            echo '</select> ';
            echo '<div id="custom_model_div" style="margin-top:10px; display:none;">';
            echo '<input type="text" name="ai_translate_settings[custom_model]" value="' . esc_attr($custom_model) . '" placeholder="E.g.: deepseek-chat, gpt-4o, ..." class="regular-text">';
            echo '</div>';
            echo '<button type="button" class="button" id="ai-translate-validate-api">Validate API settings</button>';
            echo '<span id="ai-translate-api-status" style="margin-left:10px;"></span>';

        },
        'ai-translate',
        'ai_translate_api'
    );

    // Language Settings Section
    add_settings_section(
        'ai_translate_languages',
        'Language Settings',
        function () {
            echo '<p>' . esc_html(__('Select the default language for your site and which languages should be available in the language switcher. Detectable languages will be used if a visitor\'s browser preference matches, but won\'t show in the switcher.', 'ai-translate')) . '</p>';
        },
        'ai-translate'
    );
    add_settings_field(
        'default_language',
        'Default Language',
        function () {
            $settings = get_option('ai_translate_settings');
            $value = isset($settings['default_language']) ? $settings['default_language'] : '';
            $core = AI_Translate_Core::get_instance();
            $languages = $core->get_available_languages(); // Get all available languages
            echo '<select name="ai_translate_settings[default_language]">';
            echo '<option value="" ' . selected($value, '', false) . ' disabled hidden>— Select default language —</option>';
            // Ensure current saved language stays visible if not in list
            if ($value !== '' && !isset($languages[$value])) {
                echo '<option value="' . esc_attr($value) . '" selected>' . esc_html(ucfirst($value)) . ' (Current)</option>';
            }
            foreach ($languages as $code => $name) {
                echo '<option value="' . esc_attr($code) . '" ' . selected($value, $code, false) . '>' . esc_html($name . ' (' . $code . ')') . '</option>';
            }
            echo '</select>';
        },
        'ai-translate',
        'ai_translate_languages'
    );
    add_settings_field(
        'enabled_languages',
        'Enabled Languages (in Switcher)',
        function () {
            $settings = get_option('ai_translate_settings');
            $enabled = isset($settings['enabled_languages']) ? (array)$settings['enabled_languages'] : [];
            $core = AI_Translate_Core::get_instance();
            $languages = $core->get_available_languages(); // Use all available languages
            echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">';
            // Flags base URL
            $flags_url = plugin_dir_url(__DIR__) . 'assets/flags/';
            foreach ($languages as $code => $name) {
                // Exclude detectable languages from being selectable here? Optional.
                // For now, allow overlap but explain via description.
                echo '<label style="display:block;margin-bottom:5px;">';
                echo '<input type="checkbox" name="ai_translate_settings[enabled_languages][]" value="' . esc_attr($code) . '" ' .
                    checked(in_array($code, $enabled), true, false) . '> ' .
                    '<img src="' . esc_url($flags_url . $code . '.png') . '" alt="' . esc_attr(strtoupper($code)) . '" style="width:16px;height:12px;vertical-align:middle;margin-right:6px;">' .
                    esc_html($name . ' (' . $code . ')');
                echo '</label>';
            }
            echo '</div>';
            echo '<p class="description">' . esc_html(__('Languages selected here will appear in the language switcher.', 'ai-translate')) . '</p>';
        },
        'ai-translate',
        'ai_translate_languages'
    );
    // --- Add Detectable Languages Field ---
    add_settings_field(
        'detectable_languages',
        'Detectable Languages (Auto-Translate)',
        function () {
            $settings = get_option('ai_translate_settings');
            $detected_enabled = isset($settings['detectable_languages']) ? (array)$settings['detectable_languages'] : [];

            $core = AI_Translate_Core::get_instance();
            // Retrieve ALL available languages from the core class
            $languages = $core->get_available_languages();

            // Flags base URL
            $flags_url = plugin_dir_url(__DIR__) . 'assets/flags/';

            echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">';

            // No hardcoded default selections.

            // Loop through ALL available languages from the core class
            foreach ($languages as $code => $name) {
                echo '<label style="display:block;margin-bottom:5px;">';
                echo '<input type="checkbox" name="ai_translate_settings[detectable_languages][]" value="' . esc_attr($code) . '" ' .
                    checked(in_array($code, $detected_enabled), true, false) . '> ' .
                    '<img src="' . esc_url($flags_url . $code . '.png') . '" alt="' . esc_attr(strtoupper($code)) . '" style="width:16px;height:12px;vertical-align:middle;margin-right:6px;">' .
                    esc_html($name . ' (' . $code . ')');
                echo '</label>';
            }
            echo '</div>';
            echo '<p class="description">' . esc_html(__('If a visitor\'s browser language matches one of these, the site will be automatically translated (if enabled), but these languages won\'t show in the switcher.', 'ai-translate')) . '</p>';
        },
        'ai-translate',
        'ai_translate_languages'
    );
    // --- End Detectable Languages Field ---

    // Cache Settings Section
    add_settings_section(
        'ai_translate_cache',
        'Cache Settings',
        null,
        'ai-translate'
    );
    add_settings_field(
        'cache_expiration',
        'Cache Duration (days)',
        function () {
            $settings = get_option('ai_translate_settings');
            $value = isset($settings['cache_expiration']) ? intval($settings['cache_expiration'] / 24) : 14; // Default to 14 if it doesn't exist
            echo '<input type="number" name="ai_translate_settings[cache_expiration]" value="' . esc_attr($value) . '" class="small-text" min="14"> days';
            echo ' <em style="margin-left:10px;">(' . esc_html__('minimum 14 days', 'ai-translate') . ')</em>';
        },
        'ai-translate',
        'ai_translate_cache'
    );
    add_settings_field(
        'auto_clear_pages_on_menu_update',
        'Auto-Clear Pages on Menu Update',
        function () {
            $settings = get_option('ai_translate_settings');
            $value = isset($settings['auto_clear_pages_on_menu_update']) ? (bool) $settings['auto_clear_pages_on_menu_update'] : true;
            echo '<label>';
            echo '<input type="checkbox" name="ai_translate_settings[auto_clear_pages_on_menu_update]" value="1" ' . checked($value, true, false) . '> ';
            echo esc_html__('Automatically clear all page caches when menu items are updated', 'ai-translate');
            echo '</label>';
            echo '<p class="description">';
            echo '<strong>' . esc_html__('Note:', 'ai-translate') . '</strong> ';
            echo esc_html__('Menu translation caches (transients) are ALWAYS cleared when you update menus, regardless of this setting.', 'ai-translate');
            echo '<br>';
            echo '<strong>' . esc_html__('When enabled:', 'ai-translate') . '</strong> ';
            echo esc_html__('All translated page HTML caches will also be cleared. Menu changes are visible immediately, but requires re-translating pages on next visit (expensive for large sites with many languages).', 'ai-translate');
            echo '<br>';
            echo '<strong>' . esc_html__('When disabled:', 'ai-translate') . '</strong> ';
            echo esc_html__('Only menu caches are cleared. Page HTML caches remain. Menu changes in already-cached pages will only be visible after cache expires or manual clearing via Cache tab.', 'ai-translate');
            echo '</p>';
        },
        'ai-translate',
        'ai_translate_cache'
    );

    // Advanced Settings Section
    add_settings_section(
        'ai_translate_advanced',
        'Advanced Settings',
        null,
        'ai-translate'
    );

    // --- Add Homepage Meta Description Field ---
    add_settings_field(
        'homepage_meta_description',
        'Homepage Meta Description',
        function () {
            $settings = get_option('ai_translate_settings');
            $value = isset($settings['homepage_meta_description']) ? $settings['homepage_meta_description'] : '';
            echo '<textarea name="ai_translate_settings[homepage_meta_description]" rows="3" class="large-text">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description">' . esc_html(__('Enter the specific meta description for the homepage (in the default language). This will override the site tagline or generated excerpt on the homepage.', 'ai-translate')) . '</p>';
        },
        'ai-translate',
        'ai_translate_advanced' // Add to Advanced section
    );
    // --- End Homepage Meta Description Field ---

    // --- Add Website Context Field ---
    add_settings_field(
        'website_context',
        'Website Context',
        function () {
            $settings = get_option('ai_translate_settings');
            $value = isset($settings['website_context']) ? $settings['website_context'] : '';
            echo '<textarea name="ai_translate_settings[website_context]" id="website_context_field" rows="5" class="large-text" placeholder="Describe your website, business, or organization. For example:&#10;&#10;We are a healthcare technology company specializing in patient management systems. Our services include electronic health records, appointment scheduling, and telemedicine solutions. We serve hospitals, clinics, and healthcare providers across Europe.&#10;&#10;Or:&#10;&#10;This is a personal blog about sustainable gardening and organic farming techniques. I share tips, tutorials, and experiences from my own garden.">' . esc_textarea($value) . '</textarea>';
            echo '<p class="description">' . esc_html(__('Provide context about your website or business to help the AI generate more accurate and contextually appropriate translations. ', 'ai-translate')) . '</p>';
            echo '<button type="button" class="button" id="generate-context-btn" style="margin-top: 10px;">Generate Context from Homepage</button>';
            echo '<span id="generate-context-status" style="margin-left: 10px;"></span>';
        },
        'ai-translate',
        'ai_translate_advanced'
    );
    // --- End Website Context Field ---

    // --- Add Multilingual Search Field ---
    add_settings_field(
        'enable_multilingual_search',
        'Enable Multilingual Search',
        function () {
            $settings = get_option('ai_translate_settings');
            $enabled = isset($settings['enable_multilingual_search']) ? $settings['enable_multilingual_search'] : false;
            echo '<label style="display:block;margin-bottom:10px;">';
            echo '<input type="checkbox" name="ai_translate_settings[enable_multilingual_search]" value="1" ' . checked($enabled, true, false) . '> ';
            echo esc_html__('Enable multilingual search functionality', 'ai-translate');
            echo '</label>';
            echo '<p class="description">' . esc_html__('When enabled, users can search in their own language. The search terms will be translated to the default language to find relevant content in the database.', 'ai-translate') . '</p>';
        },
        'ai-translate',
        'ai_translate_advanced'
    );
    // --- End Multilingual Search Field ---

});

/**
 * Render the AI Translate admin page (tabs: General, Cache).
 *
 * Contains WordPress settings API sections/fields and cache management UI.
 * Uses nonces and capability checks for all mutating actions.
 */
function render_admin_page()
{
    $cache_language_message = '';
    if (
        isset($_POST['clear_cache_language']) &&
        check_admin_referer('clear_cache_language_action', 'clear_cache_language_nonce') &&
        isset($_POST['cache_language']) &&
        class_exists('AI_Translate_Core')
    ) {
        $lang_code = sanitize_text_field(wp_unslash($_POST['cache_language']));
        $core = AI_Translate_Core::get_instance();

        // Get the number of files before deletion
        $cache_stats_before = $core->get_cache_statistics();
        $before_count = isset($cache_stats_before['languages'][$lang_code]) ? $cache_stats_before['languages'][$lang_code] : 0;

        // Clear cache files (now returns an array with more information)
        $result = $core->clear_cache_for_language($lang_code);

        // Get the number of files after deletion
        $cache_stats_after = $core->get_cache_statistics();
        $after_count = isset($cache_stats_after['languages'][$lang_code]) ? $cache_stats_after['languages'][$lang_code] : 0;

        // Get the language code for display (nicer than the code)
        $languages = $core->get_available_languages();
        $lang_name = isset($languages[$lang_code]) ? $languages[$lang_code] : $lang_code;

        // Ensure a clear message
        if ($result['success']) {
            if ($result['count'] > 0) {
                $notice_class = isset($result['warning']) ? 'notice-warning' : 'notice-success';
                $cache_language_message = '<div class="notice ' . esc_attr($notice_class) . '" id="cache-cleared-message">
                    <p>Cache voor taal <strong>' . esc_html($lang_name) . ' (' . esc_html($lang_code) . ')</strong> gewist. 
                    <br>Verwijderde bestanden: ' . intval($result['count']) . ' 
                    <br>Resterende bestanden: ' . intval($after_count) . '</p>';

                if (isset($result['warning'])) {
                    $cache_language_message .= '<p class="error-message">Note: ' . esc_html($result['warning']) . '</p>';
                }

                $cache_language_message .= '</div>';
            } else {
                $cache_language_message = '<div class="notice notice-info" id="cache-cleared-message">
                    <p>Er waren geen cachebestanden voor taal <strong>' . esc_html($lang_name) . ' (' . esc_html($lang_code) . ')</strong>.</p>
                </div>';
            }
        } else {
            $error_message = isset($result['error']) ? $result['error'] : 'Unknown error';
            $cache_language_message = '<div class="notice notice-error" id="cache-cleared-message">
                <p>Error clearing cache for language <strong>' . esc_html($lang_name) . ' (' . esc_html($lang_code) . ')</strong>: ' . esc_html($error_message) . '</p>
            </div>';
        }
    }

    // Determine active tab
    $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general';
?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php settings_errors(); // Display admin notices, including those from add_settings_error ?>
        <!-- Tab navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="?page=ai-translate&tab=general" class="nav-tab <?php echo esc_attr($active_tab === 'general' ? 'nav-tab-active' : ''); ?>">General</a>
            <a href="?page=ai-translate&tab=cache" class="nav-tab <?php echo esc_attr($active_tab === 'cache' ? 'nav-tab-active' : ''); ?>">Cache</a>

        </h2>
        <div id="tab-content">
            <div id="general" class="tab-panel" style="<?php echo esc_attr($active_tab === 'general' || $active_tab === 'logs' ? 'display:block;' : 'display:none;'); ?>"> <?php // Logs tab now refers to general ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('ai_translate');
                    do_settings_sections('ai-translate');
                    submit_button();
                    ?>
                </form>
            </div>
              <div id="cache" class="tab-panel" style="<?php echo esc_attr($active_tab === 'cache' ? 'display:block;' : 'display:none;'); ?>">
                <h2>Cache Management</h2>
                <?php wp_nonce_field('clear_cache_language_action', 'clear_cache_language_nonce'); // Nonce for AJAX clear language cache ?>
                
                <!-- Clear all caches -->
                <h3>Clear all language caches</h3>
                <p>Clear all language caches. <strong>Menu and slug cache are preserved to maintain URL stability.</strong></p>
                <?php
                if (isset($_POST['clear_cache']) && check_admin_referer('clear_cache_action', 'clear_cache_nonce')) {
                    if (!class_exists('AI_Translate_Core')) {
                        require_once __DIR__ . '/class-ai-translate-core.php';
                    }
                    $core = AI_Translate_Core::get_instance();
                    // Only clear disk-based language caches; do not clear menu or slugs
                    $core->clear_language_disk_caches_only();
                    echo '<div class="notice notice-success"><p>All language caches cleared. Menu and slug cache preserved.</p></div>';
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_cache_action', 'clear_cache_nonce'); ?>
                    <?php submit_button('Clear all language caches', 'delete', 'clear_cache', false); ?>
                </form>

                <hr style="margin: 20px 0;">

                <!-- Clear menu cache (including menu translation tables) -->
                <h3>Clear menu cache</h3>
                <p>Clear menu caches including all menu-item translations. This will force fresh translations for all menu items. Languages and Slug map are not affected.</p>
                <?php
                if (isset($_POST['clear_menu_cache']) && check_admin_referer('clear_menu_cache_action', 'clear_menu_cache_nonce')) {
                    if (!current_user_can('manage_options')) {
                        echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
                    } else {
                        if (!class_exists('AI_Translate_Core')) {
                            require_once __DIR__ . '/class-ai-translate-core.php';
                        }
                        $core = AI_Translate_Core::get_instance();
                        $res = $core->clear_menu_cache();
                        $tables = isset($res['tables_cleared']) && is_array($res['tables_cleared']) ? $res['tables_cleared'] : [];
                        $transients = isset($res['transients_cleared']) ? (int) $res['transients_cleared'] : 0;
                        $msg = 'Menu cache cleared';
                        if ($transients > 0) {
                            $msg .= ' (' . $transients . ' menu-item translations removed)';
                        }
                        if (!empty($tables)) {
                            $safe = array_map('esc_html', $tables);
                            $msg .= ' and tables truncated: ' . implode(', ', $safe);
                        }
                        echo '<div class="notice notice-success"><p>' . esc_html($msg) . '.</p></div>';
                    }
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_menu_cache_action', 'clear_menu_cache_nonce'); ?>
                    <?php submit_button('Clear menu cache', 'delete', 'clear_menu_cache', false); ?>
                </form>

                <hr style="margin: 20px 0;">

                <!-- Clear slug cache (truncate slug table) -->
                <h3>Clear slug cache</h3>
                <p>Clear the slug table used for translated URLs. Language and menu caches are not affected.</p>
                <?php
                if (isset($_POST['clear_slug_cache']) && check_admin_referer('clear_slug_cache_action', 'clear_slug_cache_nonce')) {
                    if (!current_user_can('manage_options')) {
                        echo '<div class="notice notice-error"><p>Insufficient permissions.</p></div>';
                    } else {
                        if (!class_exists('AI_Translate_Core')) { require_once __DIR__ . '/class-ai-translate-core.php'; }
                        $core = AI_Translate_Core::get_instance();
                        $res = $core->clear_slug_map();
                        if (!empty($res['success'])) {
                            $num = isset($res['cleared']) ? (int) $res['cleared'] : 0;
                            echo '<div class="notice notice-success"><p>Slug cache cleared. Rows removed: ' . intval($num) . '.</p></div>';
                        } else {
                            $msg = isset($res['message']) ? $res['message'] : 'Unknown error';
                            echo '<div class="notice notice-error"><p>Failed to clear slug cache: ' . esc_html($msg) . '.</p></div>';
                        }
                    }
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_slug_cache_action', 'clear_slug_cache_nonce'); ?>
                    <?php submit_button('Clear slug cache', 'delete', 'clear_slug_cache', false); ?>
                </form>

                <hr style="margin: 20px 0;">

                
                <h3>Clear cache per language</h3>
                <?php
                if (!empty($cache_language_message)) {
                    echo wp_kses_post($cache_language_message);
                }
                $core = \AITranslate\AI_Translate_Core::get_instance();
                $languages = $core->get_available_languages();

                // Haal cache statistieken op
                $cache_stats = $core->get_cache_statistics();
                $language_counts = $cache_stats['languages'] ?? [];
                ?>
                <div class="cache-stats-section">
                    <h4>Cache overview per language</h4>

                    <?php
                    // Get detailed statistics
                    $languages_details = isset($cache_stats['languages_details']) ? $cache_stats['languages_details'] : [];
                    $total_expired = $cache_stats['expired_files'] ?? 0;

                    // Calculate total size in MB
                    $total_size_mb = isset($cache_stats['total_size']) ? number_format($cache_stats['total_size'] / (1024 * 1024), 2) : 0;

                    // Last update timestamp
                    $last_modified = isset($cache_stats['last_modified']) ? wp_date('d-m-Y H:i:s', $cache_stats['last_modified']) : 'Unknown';
                    ?>

                    <div class="cache-summary" style="margin-bottom: 15px; display: flex; gap: 20px;">
                        <div class="summary-item">
                            <strong>Total number of files:</strong> <span id="total-cache-count"><?php echo intval($cache_stats['total_files'] ?? 0); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong>Total size:</strong> <?php echo esc_html($total_size_mb); ?> MB
                        </div>
                        <div class="summary-item">
                            <strong>Expired files:</strong> <?php echo intval($total_expired); ?>
                        </div>
                        <div class="summary-item">
                            <strong>Last update:</strong> <?php echo esc_html($last_modified); ?>
                        </div>
                    </div>

                    <table class="widefat striped" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Language</th>
                                <th>Cache files</th>
                                <th>Size</th>
                                <th>Expired</th>
                                <th>Last update</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total_files = 0;
                            foreach ($languages as $code => $name):
                                $count = isset($language_counts[$code]) ? $language_counts[$code] : 0;
                                $total_files += $count;

                                // Haal gedetailleerde info op
                                $details = $languages_details[$code] ?? [];
                                $size_mb = isset($details['size']) ? number_format($details['size'] / (1024 * 1024), 2) : '0.00';
                                $expired = isset($details['expired_count']) ? $details['expired_count'] : 0;
                                $last_mod = isset($details['last_modified']) ? wp_date('d-m-Y H:i:s', $details['last_modified']) : 'N/A';
                            ?>
                                <tr id="cache-row-<?php echo esc_attr($code); ?>" class="<?php echo ($count > 0) ? 'has-cache' : 'no-cache'; ?>">
                                    <td><?php echo esc_html($name); ?> (<?php echo esc_html($code); ?>)</td>
                                    <td><span class="cache-count" data-lang="<?php echo esc_attr($code); ?>"><?php echo intval($count); ?></span> files</td>
                                    <td><?php echo esc_html($size_mb); ?> MB</td>
                                    <td><?php echo intval($expired); ?></td>
                                    <td><?php echo esc_html($last_mod); ?></td>
                                    <td>
                                        <?php if ($count > 0): ?>
                                            <button type="button" class="button button-small quick-clear-cache" data-lang="<?php echo esc_attr($code); ?>">
                                                Cache clear
                                            </button>
                                        <?php else: ?>
                                            <span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="No cache files"></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th>Total</th>
                                <th><span id="table-total-count"><?php echo intval($total_files); ?></span> files</th>
                                <th><?php echo esc_html($total_size_mb); ?> MB</th>
                                <th><?php echo intval($total_expired); ?></th>
                                <th><?php echo esc_html($last_modified); ?></th>
                                <th>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// --- AJAX handler voor dynamisch ophalen van modellen ---
add_action('wp_ajax_ai_translate_get_models', function () {
    check_ajax_referer('ai_translate_get_models_nonce', 'nonce'); // Add nonce verification
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Geen rechten']);
    }
    // Haal actuele waarden uit POST als aanwezig
    $provider_key = isset($_POST['api_provider']) ? sanitize_text_field(wp_unslash($_POST['api_provider'])) : null;
    $api_key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';

    if (!$provider_key) { // Als provider niet in POST zit, haal uit settings (zonder default)
        $settings = get_option('ai_translate_settings');
        $provider_key = $settings['api_provider'] ?? '';
        if (empty($api_key) && $provider_key !== '') { // Als API key ook niet in POST zat, haal uit settings voor de gekozen provider
            $api_keys = $settings['api_keys'] ?? [];
            $api_key = $api_keys[$provider_key] ?? '';
        }
    }
    if (empty($provider_key)) {
        wp_send_json_error(['message' => 'API Provider ontbreekt.']);
    }
    
    $api_url = AI_Translate_Core::get_api_url_for_provider($provider_key);
    // Als de provider 'custom' is, gebruik dan de custom_api_url uit POST
    if ($provider_key === 'custom') {
        if (isset($_POST['custom_api_url_value'])) {
            $api_url = esc_url_raw(trim(sanitize_text_field(wp_unslash($_POST['custom_api_url_value']))));
        } else {
            $settings = isset($settings) ? $settings : get_option('ai_translate_settings');
            if (empty($api_url)) {
                $api_url = esc_url_raw(trim((string) ($settings['custom_api_url'] ?? '')));
            }
        }
    }

    if (empty($api_url) || empty($api_key)) {
        wp_send_json_error(['message' => 'API Provider, API Key of Custom API URL ontbreekt of is ongeldig.']);
        return;
    }

    $endpoint = rtrim($api_url, '/') . '/models';
    $response = wp_remote_get($endpoint, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'timeout' => 20,
        'sslverify' => true,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($code !== 200) {
        wp_send_json_error(['message' => 'API fout: ' . $body]);
    }
    $data = json_decode($body, true);
    if (!isset($data['data']) || !is_array($data['data'])) {
        wp_send_json_error(['message' => 'Ongeldig antwoord van API']);
    }
    $models = array_map(function ($m) {
        return is_array($m) && isset($m['id']) ? $m['id'] : (is_string($m) ? $m : null);
    }, $data['data']);
    $models = array_filter($models);
    // Block GPT-5 models as they are designed for complex reasoning tasks and have 3-5x higher latency
    // GPT-5 is unsuitable for real-time website translations (use gpt-4o-mini or gpt-4.1-mini instead)
    $models = array_filter($models, function($model) {
        return !preg_match('/^(gpt-5|o1-|o3-)/i', $model);
    });
    sort($models);
    wp_send_json_success(['models' => $models]);
});

// --- AJAX handler voor dynamisch ophalen van custom URL ---
add_action('wp_ajax_ai_translate_get_custom_url', function () {
    check_ajax_referer('ai_translate_get_custom_url_nonce', 'nonce'); // Nonce verification
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Geen rechten']);
    }

    $settings = get_option('ai_translate_settings', []);
    wp_send_json_success(['settings' => $settings]);
});


// --- AJAX handler voor API validatie ---
add_action('wp_ajax_ai_translate_validate_api', function () {
    check_ajax_referer('ai_translate_validate_api_nonce', 'nonce'); // Add nonce verification
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Geen rechten']);
    }

    // Haal actuele waarden uit POST als aanwezig
    $provider_key = isset($_POST['api_provider']) ? sanitize_text_field(wp_unslash($_POST['api_provider'])) : null;
    $api_key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
    $model = isset($_POST['model']) ? trim(sanitize_text_field(wp_unslash($_POST['model']))) : '';
    $custom_api_url_value = isset($_POST['custom_api_url_value']) ? esc_url_raw(trim(sanitize_text_field(wp_unslash($_POST['custom_api_url_value'])))) : '';

    // Als provider niet in POST zit, haal uit settings
    if (!$provider_key) {
        $settings = get_option('ai_translate_settings');
        $provider_key = $settings['api_provider'] ?? '';
        if ($provider_key !== '') {
            if (empty($api_key)) {
                $api_keys = $settings['api_keys'] ?? [];
                $api_key = $api_keys[$provider_key] ?? '';
            }
            if (empty($model)) { $model = $settings['selected_model'] ?? ''; }
            if (empty($custom_api_url_value)) { $custom_api_url_value = $settings['custom_api_url'] ?? ''; }
        }
    }
    if (empty($provider_key)) {
        wp_send_json_error(['message' => 'API Provider ontbreekt.']);
    }
    if (empty($api_key)) {
        wp_send_json_error(['message' => 'API Key ontbreekt.']);
    }
    if ($provider_key === 'custom' && empty($custom_api_url_value)) {
        wp_send_json_error(['message' => 'Custom API URL ontbreekt voor provider custom.']);
    }

    $core = AI_Translate_Core::get_instance();

    try {
        // Roep de validate_api_settings functie aan in de core class
        $validation_result = $core->validate_api_settings($provider_key, $api_key, $custom_api_url_value);

        // Als validatie succesvol is, sla settings op
        if (isset($_POST['save_settings']) && $_POST['save_settings'] === '1') {
            $current_settings = get_option('ai_translate_settings', []);

            if (isset($current_settings['cache_expiration'])) {
                $current_settings['cache_expiration'] = intval($current_settings['cache_expiration']) / 24;
            }
            
            // Zorg ervoor dat 'api_keys' array bestaat
            if (!isset($current_settings['api_keys']) || !is_array($current_settings['api_keys'])) {
                $current_settings['api_keys'] = [
                    'openai' => '',
                    'deepseek' => '',
                    'custom' => '',
                ];
            }

            // Sla de gevalideerde API-sleutel op voor de geselecteerde provider
            $current_settings['api_keys'][$provider_key] = $api_key;
            $current_settings['api_provider'] = $provider_key; // Zorg dat de provider ook wordt opgeslagen
            $current_settings['selected_model'] = $model;

            // Zorg ervoor dat het model ook per provider wordt weggeschreven (runtime leest dit veld)
            if (!isset($current_settings['models']) || !is_array($current_settings['models'])) {
                $current_settings['models'] = [];
            }
            if (is_string($model) && $model !== '') {
                $current_settings['models'][$provider_key] = $model;
            }
            
            if (isset($_POST['custom_model_value'])) {
                 $current_settings['custom_model'] = trim(sanitize_text_field(wp_unslash($_POST['custom_model_value'])));
            } elseif ($model !== 'custom' && isset($current_settings['custom_model'])) {
                $current_settings['custom_model'] = '';
            }
            
            // Update custom_api_url only if the provider is custom
            if ($provider_key === 'custom') {
                $current_settings['custom_api_url'] = $custom_api_url_value;
            }
            // Note: We don't explicitly set custom_api_url to empty here
            // because the sanitize_callback handles ensuring it's saved if present.

            update_option('ai_translate_settings', $current_settings);
        }
        wp_send_json_success(['message' => 'API en model werken. API instellingen zijn opgeslagen.']);

    } catch (\Exception $e) {
        wp_send_json_error(['message' => 'API validatie mislukt: ' . $e->getMessage()]);
    }
});

add_action('update_option_ai_translate_settings', 'AITranslate\\maybe_flush_rules_on_settings_update', 20, 2);

/**
 * Flush rewrite rules when language-related settings change.
 *
 * @param array $old
 * @param array $new
 * @return void
 */
function maybe_flush_rules_on_settings_update($old, $new)
{
    $keys = ['default_language', 'enabled_languages', 'detectable_languages'];
    foreach ($keys as $k) {
        $old_v = isset($old[$k]) ? $old[$k] : null;
        $new_v = isset($new[$k]) ? $new[$k] : null;
        if ($old_v !== $new_v) {
            flush_rewrite_rules();
            return;
        }
    }
}