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
 * AJAX handler for clearing cache for a specific language
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
 * AJAX handler for generating website context suggestion
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
            
            // Also clear translation cache to force re-translation with new context
            $core->clear_all_cache();
        }
    }
}, 10, 2);

/**
 * AJAX handler for validating API settings
 */

function no_admin_page()
{
    if (! defined('ABSPATH') || ! is_admin()) {
        exit;
    }
}

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
        'generateContextNonce' => wp_create_nonce('generate_website_context_nonce') // Added nonce for generating website context
    ));
});

// Register settings (without the shortcodes section)
add_action('admin_init', function () {
    register_setting('ai_translate', 'ai_translate_settings', [
        'sanitize_callback' => function ($input) {
            $valid_providers = array_keys(AI_Translate_Core::get_api_providers());
            if (isset($input['api_provider']) && in_array($input['api_provider'], $valid_providers, true)) {
                $input['api_provider'] = sanitize_text_field($input['api_provider']);
            } else {
                // Fallback to a default provider if invalid or not set
                $input['api_provider'] = 'openai';
            }
            // Remove old api_url if it exists from a previous version
            unset($input['api_url']);

            // Sanitize custom_api_url if present, regardless of provider
            if (isset($input['custom_api_url'])) {
                $input['custom_api_url'] = esc_url_raw(trim($input['custom_api_url']));
            } else {
                // Ensure custom_api_url is set, even if empty
                $input['custom_api_url'] = '';
            }

            if (isset($input['cache_expiration'])) {
                $cache_days = intval($input['cache_expiration']);
                if ($cache_days < 14) {
                    add_settings_error(
                        'ai_translate_settings', // slug title of the setting
                        'cache_duration_too_low', // error code
                        __('Cache duration cannot be less than 14 days. It has been automatically set to 14 days.', 'ai-translate'), // error message
                        'warning' // type of message
                    );
                    $input['cache_expiration'] = 14; // Set to a minimum of 14 days
                }
                // Convert to hours for storage
                $input['cache_expiration'] = intval($input['cache_expiration']) * 24;
            } else {
                // Fallback if not set, set to 14 days (in hours)
                $input['cache_expiration'] = 14 * 24;
            }

            if (isset($input['selected_model'])) {
                if ($input['selected_model'] === 'custom') {
                    if (!empty($input['custom_model'])) {
                        $input['selected_model'] = trim($input['custom_model']);
                    } else {
                        $input['selected_model'] = 'gpt-4.1-mini'; // Fallback
                    }
                }
            }

            if (isset($input['api_key'])) {
                $input['api_key'] = trim($input['api_key']);
            }
            if (isset($input['custom_model'])) {
                $input['custom_model'] = trim($input['custom_model']);
            }

            if (isset($input['homepage_meta_description'])) {
                $input['homepage_meta_description'] = sanitize_textarea_field($input['homepage_meta_description']);
            } else {
                $input['homepage_meta_description'] = '';
            }

            if (isset($input['website_context'])) {
                $input['website_context'] = sanitize_textarea_field($input['website_context']);
            } else {
                $input['website_context'] = '';
            }

            if (isset($input['enabled_languages']) && is_array($input['enabled_languages'])) {
                $input['enabled_languages'] = array_unique(array_map('sanitize_text_field', $input['enabled_languages']));
            } else {
                $input['enabled_languages'] = ['en'];
            }

            if (isset($input['detectable_languages']) && is_array($input['detectable_languages'])) {
                $input['detectable_languages'] = array_unique(array_map('sanitize_text_field', $input['detectable_languages']));
            } else {
                $input['detectable_languages'] = [];
            }

            return $input;
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
        'api_provider', // Changed from 'api_url'
        'API Provider', // Changed label
        function () {
            $settings = get_option('ai_translate_settings');
            $current_provider_key = isset($settings['api_provider']) ? $settings['api_provider'] : 'openai'; // Default to openai
            $providers = AI_Translate_Core::get_api_providers();

            echo '<select id="api_provider_select" name="ai_translate_settings[api_provider]">';
            foreach ($providers as $key => $provider_details) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($current_provider_key, $key, false) . '>' . esc_html($provider_details['name']) . '</option>';
            }
            echo '</select>';
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
            $value = isset($settings['api_key']) ? $settings['api_key'] : '';
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
            $selected_model = isset($settings['selected_model']) ? $settings['selected_model'] : '';
            $custom_model = isset($settings['custom_model']) ? $settings['custom_model'] : '';
            $is_custom = $selected_model && !in_array($selected_model, ['gpt-4', 'gpt-3.5-turbo']);
            echo '<select name="ai_translate_settings[selected_model]" id="selected_model">';
            if ($selected_model && !$is_custom) {
                echo '<option value="' . esc_attr($selected_model) . '" selected>' . esc_html($selected_model) . '</option>';
            }
            if ($is_custom) {
                echo '<option value="' . esc_attr($selected_model) . '" selected>' . esc_html($selected_model) . ' (custom)</option>';
            }
            echo '<option value="custom" ' . selected($is_custom, true, false) . '>Select...</option>';
            echo '</select> ';
            echo '<div id="custom_model_div" style="margin-top:10px; display:' . ($is_custom ? 'block' : 'none') . ';">';
            echo '<input type="text" name="ai_translate_settings[custom_model]" value="' . esc_attr($is_custom ? $selected_model : $custom_model) . '" placeholder="E.g.: deepseek-chat, gpt-4o, ..." class="regular-text">';
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
            $value = isset($settings['default_language']) ? $settings['default_language'] : 'nl'; // Default 'nl'
            $core = AI_Translate_Core::get_instance();
            $languages = $core->get_available_languages(); // Get all available languages
            echo '<select name="ai_translate_settings[default_language]">';
            // Ensure default language is in the list
            if (!isset($languages[$value])) {
                echo '<option value="' . esc_attr($value) . '" selected>' . esc_html(ucfirst($value)) . ' (Current)</option>';
            }
            foreach ($languages as $code => $name) {
                echo '<option value="' . esc_attr($code) . '" ' . selected($value, $code, false) . '>' . esc_html($name) . '</option>';
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
            // Default enabled: nl, en, de, fr, es
            $enabled = isset($settings['enabled_languages']) ? (array)$settings['enabled_languages'] : ['nl', 'en', 'de', 'fr', 'es'];
            $core = AI_Translate_Core::get_instance();
            $languages = $core->get_available_languages(); // Use all available languages
            echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">';
            foreach ($languages as $code => $name) {
                // Exclude detectable languages from being selectable here? Optional.
                // For now, allow overlap but explain via description.
                echo '<label style="display:block;margin-bottom:5px;">';
                echo '<input type="checkbox" name="ai_translate_settings[enabled_languages][]" value="' . esc_attr($code) . '" ' .
                    checked(in_array($code, $enabled), true, false) . '> ' . esc_html($name);
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
            // Default detectable languages (can be empty or contain a subset)
            $default_detectable = ['ja', 'zh', 'ru', 'hi', 'ka', 'sv', 'pl', 'ar', 'tr', 'fi', 'no', 'da', 'ko', 'uk']; // Optionally retain a default selection
            $detected_enabled = isset($settings['detectable_languages']) ? (array)$settings['detectable_languages'] : $default_detectable;

            $core = AI_Translate_Core::get_instance();
            // Retrieve ALL available languages from the core class
            $languages = $core->get_available_languages();

            echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">';

            // REMOVED: The hardcoded $detectable_languages array is no longer needed.
            // $detectable_languages = [ ... ];

            // Loop through ALL available languages from the core class
            foreach ($languages as $code => $name) {
                echo '<label style="display:block;margin-bottom:5px;">';
                echo '<input type="checkbox" name="ai_translate_settings[detectable_languages][]" value="' . esc_attr($code) . '" ' .
                    checked(in_array($code, $detected_enabled), true, false) . '> ' . esc_html($name);
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

});

function render_admin_page()
{
    // Clear Cache button handling
    if (isset($_POST['clear_cache']) && check_admin_referer('clear_cache_action', 'clear_cache_nonce')) {
        if (class_exists('AI_Translate_Core')) {
            AI_Translate_Core::get_instance()->clear_all_cache();
            echo '<div id="message" class="updated notice is-dismissible"><p>Cache has been cleared.</p></div>';
        }
    }

    
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
                <h3>Clear all caches</h3>
                <p>Clear all translation caches (disk, memory, transients, menu, slugs).</p>
                <?php
                if (isset($_POST['clear_cache']) && check_admin_referer('clear_cache_action', 'clear_cache_nonce')) {
                    if (!class_exists('AI_Translate_Core')) {
                        require_once __DIR__ . '/class-ai-translate-core.php';
                    }
                    $core = AI_Translate_Core::get_instance();
                    $core->clear_all_cache();
                    echo '<div class="notice notice-success"><p>All caches successfully cleared.</p></div>';
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_cache_action', 'clear_cache_nonce'); ?>
                    <?php submit_button('Clear all caches', 'delete', 'clear_cache', false); ?>
                </form>

                <hr style="margin: 20px 0;">

                <!-- Clear memory cache -->
                <h3>Clear memory cache</h3>
                <p>Clear only memory cache and all transients (database). Disk cache will remain and be used to refill memory/transients.</p>
                <?php
                $memory_cache_message = '';
                if (isset($_POST['clear_memory_cache']) && check_admin_referer('clear_memory_cache_action', 'clear_memory_cache_nonce')) {
                    if (class_exists('AI_Translate_Core')) {
                        $core = AI_Translate_Core::get_instance();
                        $core->clear_memory_and_transients();
                        $memory_cache_message = '<div class="notice notice-success"><p>Memory cache and transients successfully cleared.</p></div>';
                    }
                }
                if (!empty($memory_cache_message)) {
                    echo wp_kses_post($memory_cache_message);
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_memory_cache_action', 'clear_memory_cache_nonce'); ?>
                    <?php submit_button('Clear memory cache', 'delete', 'clear_memory_cache', false); ?>
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

    if (!$provider_key) { // Als provider niet in POST zit, haal uit settings
        $settings = get_option('ai_translate_settings');
        $provider_key = $settings['api_provider'] ?? 'openai'; // Default
        if (empty($api_key)) { // Als API key ook niet in POST zat, haal uit settings
             $api_key = $settings['api_key'] ?? '';
        }
    }
    
    $api_url = AI_Translate_Core::get_api_url_for_provider($provider_key);
    // Als de provider 'custom' is, gebruik dan de custom_api_url uit POST
    if ($provider_key === 'custom' && isset($_POST['custom_api_url_value'])) {
        $api_url = esc_url_raw(trim(sanitize_text_field(wp_unslash($_POST['custom_api_url_value']))));
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
        $provider_key = $settings['api_provider'] ?? 'openai'; // Default
        if (empty($api_key)) { $api_key = $settings['api_key'] ?? ''; }
        if (empty($model)) { $model = $settings['selected_model'] ?? ''; }
        if (empty($custom_api_url_value)) { $custom_api_url_value = $settings['custom_api_url'] ?? ''; }
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
            
            $current_settings['api_provider'] = $provider_key;
            $current_settings['api_key'] = $api_key;
            $current_settings['selected_model'] = $model;
            
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