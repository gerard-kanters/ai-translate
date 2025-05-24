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

            // Sanitize custom_api_url if provider is 'custom'
            if (isset($input['api_provider']) && $input['api_provider'] === 'custom' && isset($input['custom_api_url'])) {
                $input['custom_api_url'] = esc_url_raw(trim($input['custom_api_url']));
            } else {
                // Ensure custom_api_url is unset or empty if not using custom provider
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
            $default_detectable = ['ja', 'zh', 'ru', 'hi', 'ka', 'sv', 'pl', 'ar', 'tr', 'fi', 'no', 'da', 'ko', 'ua']; // Optionally retain a default selection
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

    // Clear Debug Log button handling (empty the debug.log file) - REMOVED
    // if (isset($_POST['clear_debug']) && check_admin_referer('clear_debug_action', 'clear_debug_nonce')) {
    //     $debug_log_file = WP_CONTENT_DIR . '/debug.log';
    //     if (file_exists($debug_log_file)) {
    //         file_put_contents($debug_log_file, '');
    //         echo '<div id="message" class="updated notice is-dismissible"><p>WP Debug Log has been cleared.</p></div>';
    //     } else {
    //         echo '<div id="message" class="error notice is-dismissible"><p>WP Debug Log not found.</p></div>';
    //     }
    // }
    // Clear cache for specific language
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

    // --- NEW: Clear Transient Cache ---
    $transient_cache_message = '';
    if (
        isset($_POST['clear_transient_cache']) &&
        check_admin_referer('clear_transient_cache_action', 'clear_transient_cache_nonce') &&
        class_exists('AITranslate\AI_Translate_Core')
    ) {
        $core = AI_Translate_Core::get_instance();
        $core->clear_transient_cache(); // Call the existing method
        $transient_cache_message = '<div class="notice notice-success"><p>Transient cache successfully cleared.</p></div>';
    }
    // --- END NEW ---

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
            <?php /* Logs tab content removed
            <div id="logs" class="tab-panel" style="<?php echo $active_tab === 'logs' ? 'display:block;' : 'display:none;' ?>">
                <h2>Logs</h2>
                <h3>WordPress Debug Log</h3>
                <pre style="background:#f8f9fa;padding:15px;max-height:300px;overflow-y:auto;">
<?php
    $wp_debug_log_file = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($wp_debug_log_file)) {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . '/wp-admin/includes/file.php';
            }
            WP_Filesystem();
        }

        // Try to read the file with WP_Filesystem
        $all_lines_array = [];
        if ($wp_filesystem) {
            $all_lines_array = $wp_filesystem->get_contents_array($wp_debug_log_file);
        }

        if ($all_lines_array === false || !$wp_filesystem) {
            // Fallback or error message if WP_Filesystem fails or is not initialized
            echo 'Could not read WP Debug Log using WP_Filesystem. Check file permissions and WP_Filesystem setup.';
            if (!$wp_filesystem) {
                echo ' WP_Filesystem could not be initialized.';
            }
        } else {
            $line_count = count($all_lines_array);
            // array_slice will get the last 200 elements. If fewer than 200, it gets all of them.
            $lines_to_show = array_slice($all_lines_array, -200);

            if ($line_count > 200) {
                echo esc_html('Showing last 200 lines of ' . $line_count . ' total lines:' . "\n\n");
            }
            // Implode with an empty string because get_contents_array returns lines with their original newlines.
            echo esc_html(implode('', $lines_to_show));
        }
    } else {
        echo 'No WP Debug Log found.';
    }
?>
                </pre>
                <form method="post">
                    <?php wp_nonce_field('clear_debug_action', 'clear_debug_nonce'); ?>
                    <?php submit_button('Clear Debug Log', 'delete', 'clear_debug', false); ?>
                </form>
            </div>
            */ ?>
            <div id="cache" class="tab-panel" style="<?php echo esc_attr($active_tab === 'cache' ? 'display:block;' : 'display:none;'); ?>">
                <h2>Cache Management</h2>
                <?php wp_nonce_field('clear_cache_language_action', 'clear_cache_language_nonce'); // Nonce for AJAX clear language cache ?>
                <p>Clear all translation caches (both files and transients) to force new translations.</p>
                <?php
                if (isset($_POST['clear_cache']) && check_admin_referer('clear_cache_action', 'clear_cache_nonce')) {
                    if (!class_exists('AI_Translate_Core')) {
                        require_once __DIR__ . '/class-ai-translate-core.php';
                    }
                    $core = AI_Translate_Core::get_instance();
                    $core->clear_all_cache();
                    echo '<div class="notice notice-success"><p>Cache successfully cleared.</p></div>';
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_cache_action', 'clear_cache_nonce'); ?>
                    <?php submit_button('Clear Cache', 'delete', 'clear_cache', false); ?>
                </form>

                <hr style="margin: 20px 0;">

                <!-- NIEUW: Clear Transient Cache Form -->
                <h3>Clear Transient Cache Only</h3>
                <p>Clear only the transient (database) cache. File cache will remain.</p>
                <?php
                if (!empty($transient_cache_message)) {
                    echo wp_kses_post($transient_cache_message);
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_transient_cache_action', 'clear_transient_cache_nonce'); ?>
                    <?php submit_button('Clear Transient Cache', 'delete', 'clear_transient_cache', false); ?>
                </form>
                <!-- EINDE NIEUW -->

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
                ?> <style>
                    .cache-stats-section {
                        margin-top: 20px;
                        background: #f9f9f9;
                        padding: 15px;
                        border-radius: 5px;
                        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                    }

                    .cache-stats-section h4 {
                        margin-top: 0;
                        font-size: 1.1em;
                    }

                    .cache-summary {
                        background: #fff;
                        padding: 10px 15px;
                        border-left: 4px solid #2271b1;
                        margin-bottom: 20px;
                        display: flex;
                        flex-wrap: wrap;
                        gap: 20px;
                    }

                    .summary-item {
                        margin-right: 20px;
                    }

                    .cache-stats-section table {
                        border-collapse: collapse;
                        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.07);
                    }

                    .cache-stats-section th {
                        background: #f0f0f1;
                    }

                    .cache-stats-section tr.has-cache:hover {
                        background-color: #f0f7fb;
                    }

                    .cache-stats-section tr.no-cache {
                        color: #888;
                        background-color: #fafafa;
                    }

                    .quick-clear-cache {
                        transition: all 0.2s ease;
                    }

                    .quick-clear-cache:hover {
                        background-color: #d63638;
                        color: white;
                    }
                </style>
                <div class="cache-stats-section">
                    <h4>Cache overview per language</h4>

                    <?php
                    // Get detailed statistics
                    $languages_details = isset($cache_stats['languages_details']) ? $cache_stats['languages_details'] : [];
                    $total_expired = $cache_stats['expired_files'] ?? 0;

                    // Calculate total size in MB
                    $total_size_mb = isset($cache_stats['total_size']) ? number_format($cache_stats['total_size'] / (1024 * 1024), 2) : 0;

                    // Last update timestamp
                    $last_modified = isset($cache_stats['last_modified']) ? gmdate('d-m-Y H:i:s', $cache_stats['last_modified']) : 'Unknown';
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
                                $last_mod = isset($details['last_modified']) ? gmdate('d-m-Y H:i:s', $details['last_modified']) : 'N/A';
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
    <script>
        // Update tab links to add the tab parameter
        document.addEventListener('DOMContentLoaded', function() {
            var tabLinks = document.querySelectorAll('.nav-tab-wrapper a');
            tabLinks.forEach(function(link) {
                var tab = link.getAttribute('href').split('&tab=')[1]; // Get the tab name
                if (tab) { // Ensure tab has a value
                    link.href = '<?php echo esc_url(admin_url('admin.php?page=ai-translate')); ?>&tab=' + tab;
                }
            });

            // Show/hide custom model text field based on selected value
            var selectedModel = document.getElementById('selected_model');
            var customModelDiv = document.getElementById('custom_model_div');
            if (selectedModel) {
                selectedModel.addEventListener('change', function() {
                    if (this.value === 'custom') {
                        customModelDiv.style.display = 'block';
                    } else {
                        customModelDiv.style.display = 'none';
                    }
                });
            }
            
            /**
             * Updates the UI elements after cache clearing
             * @param {string} langCode - The language code that was cleared
             */
            function updateCacheUI(langCode) {
                try {
                    console.log('Updating UI for language:', langCode);

                    // Update table row for this language
                    var row = document.getElementById('cache-row-' + langCode);
                    if (row) {
                        // Find and update the count cell
                        var countElement = row.querySelector('.cache-count[data-lang="' + langCode + '"]');
                        if (countElement) {
                            var oldCount = parseInt(countElement.textContent) || 0;
                            countElement.textContent = '0';

                            // Update totals
                            var totalElement = document.getElementById('total-cache-count');
                            var tableTotal = document.getElementById('table-total-count');

                            if (totalElement) {
                                var currentTotal = parseInt(totalElement.textContent) || 0;
                                totalElement.textContent = Math.max(0, currentTotal - oldCount);
                            }

                            if (tableTotal) {
                                var currentTableTotal = parseInt(tableTotal.textContent) || 0;
                                tableTotal.textContent = Math.max(0, currentTableTotal - oldCount);
                            }

                            // Update row classes and last column
                            row.classList.remove('has-cache');
                            row.classList.add('no-cache');

                            // Replace the button with a checkmark
                            var actionCell = row.querySelector('td:last-child');
                            if (actionCell) {
                                actionCell.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="No cache files"></span>';
                            }

                            // Highlight the row
                            row.style.backgroundColor = '#e7f7ed';
                            setTimeout(function() {
                                row.style.transition = 'background-color 1s ease-in-out';
                                row.style.backgroundColor = '';
                            }, 1500);
                        }
                    }
                } catch (error) {
                    console.error('Error in updateCacheUI:', error);
                }
            }

            // Cache per language functionality
            var langSelect = document.getElementById('cache_language');
            var langCountSpan = document.getElementById('selected-lang-count');

            // Quick cache clear buttons in the table
            var quickClearButtons = document.querySelectorAll('.quick-clear-cache');
            if (quickClearButtons.length > 0) {
                quickClearButtons.forEach(function(button) {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation(); // Prevent the row click from being triggered as well

                        var langCode = button.getAttribute('data-lang');
                        if (!langCode) return;
 
                         // Safely check if langSelect exists and has options before using them
                         if (langSelect && langSelect.options) {
                             // Select the correct language in the dropdown
                             for (var i = 0; i < langSelect.options.length; i++) {
                                 if (langSelect.options[i].value === langCode) {
                                     langSelect.selectedIndex = i;
                                     break;
                                 }
                             }
                         }// AJAX request to clear the cache
                         // Get the nonce from the hidden field
                         var nonce = document.querySelector('input[name="clear_cache_language_nonce"]').value;
                         if (!nonce) {
                             console.error('Nonce field not found');
                             
                             // Display an error message
                             var noticeDiv = document.createElement('div');
                             noticeDiv.className = 'notice notice-error is-dismissible';
                             noticeDiv.innerHTML = '<p>Security token not found. Refresh the page and try again.</p>';
 
                             // Add the notice to the top of the tab
                             var cacheTab = document.getElementById('cache');
                             if (cacheTab) {
                                 cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);
 
                                 // Automatically remove after 5 seconds
                                 setTimeout(function() {
                                     noticeDiv.style.transition = 'opacity 1s ease-out';
                                     noticeDiv.style.opacity = 0;
                                     setTimeout(function() {
                                         noticeDiv.remove();
                                     }, 1000);
                                 }, 5000);
                             }
                             return;
                         }
 
                         // Add a loading indicator to the button
                         var originalText = button.textContent;
                         button.textContent = 'Processing...';
                         button.disabled = true;

                        // AJAX request
                        var formData = new FormData();
                        formData.append('action', 'ai_translate_clear_cache_language');
                        formData.append('lang_code', langCode);
                        formData.append('nonce', nonce);

                        fetch(ajaxurl, {
                                method: 'POST',
                                credentials: 'same-origin',
                                body: formData
                            }).then(response => {
                                if (!response.ok) {
                                    throw new Error('Server response niet ok: ' + response.status);
                                }
                                return response.json();
                            })
                            .then(function(data) {
                                console.log('AJAX response:', data); // Debug output

                                if (data.success) {
                                    // Toon een succes bericht
                                    var noticeDiv = document.createElement('div');
                                    noticeDiv.className = 'notice notice-success is-dismissible';
                                    noticeDiv.innerHTML = '<p>' + data.data.message + '</p>';

                                    // Voeg de notice toe bovenaan de tab
                                    var cacheTab = document.getElementById('cache');
                                    if (cacheTab) {
                                        cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);

                                        // Na 5 seconden automatisch verwijderen
                                        setTimeout(function() {
                                            noticeDiv.style.transition = 'opacity 1s ease-out';
                                            noticeDiv.style.opacity = 0;
                                            setTimeout(function() {
                                                noticeDiv.remove();
                                            }, 1000);
                                        }, 5000);
                                    }

                                    // Update UI first, then reload after a longer delay
                                    updateCacheUI(langCode);
                                    setTimeout(function() {
                                        window.location.reload();
                                    }, 3000);
                                } else {
                                    // Toon een foutmelding
                                    var errorMsg = 'Fout bij wissen van cache';

                                    if (data.data && data.data.message) {
                                        errorMsg += ': ' + data.data.message;
                                    } else if (data.message) {
                                        errorMsg += ': ' + data.message;
                                    }

                                    // Toon een error bericht
                                    var noticeDiv = document.createElement('div');
                                    noticeDiv.className = 'notice notice-error is-dismissible';
                                    noticeDiv.innerHTML = '<p>' + errorMsg + '</p>';

                                    // Voeg de notice toe bovenaan de tab
                                    var cacheTab = document.getElementById('cache');
                                    if (cacheTab) {
                                        cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);

                                        // Na 5 seconden automatisch verwijderen
                                        setTimeout(function() {
                                            noticeDiv.style.transition = 'opacity 1s ease-out';
                                            noticeDiv.style.opacity = 0;
                                            setTimeout(function() {
                                                noticeDiv.remove();
                                            }, 1000);
                                        }, 5000);
                                    }

                                    // Reset de knop
                                    button.textContent = originalText;
                                    button.disabled = false;
                                }
                            })
                            .catch(function(error) {
                                console.error('AJAX Error:', error);
                                
                                // Toon een error bericht
                                var noticeDiv = document.createElement('div');
                                noticeDiv.className = 'notice notice-error is-dismissible';
                                noticeDiv.innerHTML = '<p>Er is een fout opgetreden bij het wissen van de cache: ' + error.message + '</p>';

                                // Voeg de notice toe bovenaan de tab
                                var cacheTab = document.getElementById('cache');
                                if (cacheTab) {
                                    cacheTab.insertBefore(noticeDiv, cacheTab.firstChild);

                                    // Na 5 seconden automatisch verwijderen
                                    setTimeout(function() {
                                        noticeDiv.style.transition = 'opacity 1s ease-out';
                                        noticeDiv.style.opacity = 0;
                                        setTimeout(function() {
                                            noticeDiv.remove();
                                        }, 1000);
                                    }, 5000);
                                }

                                // Reset de knop
                                button.textContent = originalText;
                                button.disabled = false;
                            });
                    });
                });
            }

            // Maak tabelrijen klikbaar om een taal te selecteren
            var cacheTableRows = document.querySelectorAll('tr[id^="cache-row-"]');
            cacheTableRows.forEach(function(row) {
                row.style.cursor = 'pointer';
                row.setAttribute('title', 'Klik om deze taal te selecteren');
                row.addEventListener('click', function(e) {
                    // Voorkom dat rij klikbaar is als op een knop is geklikt
                    if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                        return;
                    }                    var langCode = row.id.replace('cache-row-', '');
                    
                    // Veilig controleren of langSelect bestaat en opties heeft
                    if (langSelect && langSelect.options) {
                        // Vind en selecteer de bijbehorende optie in de dropdown
                        for (var i = 0; i < langSelect.options.length; i++) {
                            if (langSelect.options[i].value === langCode) {
                                langSelect.selectedIndex = i;
                                // Trigger een change event zodat de UI wordt bijgewerkt
                                var changeEvent = new Event('change');
                                langSelect.dispatchEvent(changeEvent);
                                break;
                            }
                        }
                    }
                });
            });            if (langSelect && langCountSpan) {
                // Update count display when dropdown changes
                langSelect.addEventListener('change', function() {
                    if (langSelect.options && langSelect.selectedIndex !== undefined && langSelect.selectedIndex >= 0) {
                        var selectedOption = langSelect.options[langSelect.selectedIndex];
                        if (selectedOption) {
                            var count = selectedOption.getAttribute('data-count');
                            langCountSpan.textContent = count + ' bestanden in cache';
                        }
                    }
                });

                // Trigger initial update
                if (langSelect.options && langSelect.selectedIndex !== undefined && langSelect.selectedIndex >= 0) {
                    var initialOption = langSelect.options[langSelect.selectedIndex];
                    if (initialOption) {
                        var initialCount = initialOption.getAttribute('data-count');
                        langCountSpan.textContent = initialCount + ' bestanden in cache';
                    }
                }
                /**
                 * Updates the UI elements after cache clearing
                 * @param {string} langCode - The language code that was cleared
                 */
                function updateCacheUI(langCode) {
                    try {
                        console.log('UI bijwerken voor taal:', langCode);

                        // Controleer of de nodige variabelen beschikbaar zijn
                        var langSelect = document.getElementById('cache_language');
                        if (!langSelect) {
                            console.warn('Language select element niet gevonden');
                            return;
                        }

                        // Update the dropdown option for this language
                        var selectedOption = null;

                        if (!langSelect.options) {
                            console.warn('Language select heeft geen opties');
                            return;
                        }

                        for (var i = 0; i < langSelect.options.length; i++) {
                            if (langSelect.options[i].value === langCode) {
                                selectedOption = langSelect.options[i];
                                break;
                            }
                        }

                        if (selectedOption) {
                            selectedOption.setAttribute('data-count', '0');
                            var langName = selectedOption.textContent.split('(')[0].trim();
                            selectedOption.textContent = langName + ' (0 bestanden)';

                            // Update table row for this language
                            var row = document.getElementById('cache-row-' + langCode);
                            if (row) {
                                // Find and update the count cell
                                var countElement = row.querySelector('.cache-count[data-lang="' + langCode + '"]');
                                if (countElement) {
                                    var oldCount = parseInt(countElement.textContent) || 0;
                                    countElement.textContent = '0';

                                    // Update totals
                                    var totalElement = document.getElementById('total-cache-count');
                                    var tableTotal = document.getElementById('table-total-count');

                                    if (totalElement) {
                                        var currentTotal = parseInt(totalElement.textContent) || 0;
                                        totalElement.textContent = Math.max(0, currentTotal - oldCount);
                                    }

                                    if (tableTotal) {
                                        var currentTableTotal = parseInt(tableTotal.textContent) || 0;
                                        tableTotal.textContent = Math.max(0, currentTableTotal - oldCount);
                                    }

                                    // Update row classes and last column
                                    row.classList.remove('has-cache');
                                    row.classList.add('no-cache');

                                    // Replace the button with a checkmark
                                    var actionCell = row.querySelector('td:last-child');
                                    if (actionCell) {
                                        actionCell.innerHTML = '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="Geen cache bestanden"></span>';
                                    } // Highlight the row
                                    row.style.backgroundColor = '#e7f7ed';
                                    setTimeout(function() {
                                        row.style.transition = 'background-color 1s ease-in-out';
                                        row.style.backgroundColor = '';
                                    }, 1500);
                                } else {
                                    console.warn('Count element niet gevonden voor taal:', langCode);
                                }
                            } else {
                                console.warn('Cache rij niet gevonden voor taal:', langCode);
                            }
                        } else {
                            console.warn('Geselecteerde optie niet gevonden voor taal:', langCode);
                        }
                    } catch (error) {
                        console.error('Fout in updateCacheUI:', error);
                    }
                }

                // Handle form submission to update count to zero
                var cacheForm = document.getElementById('clear-cache-language-form');
                if (cacheForm) {                    cacheForm.addEventListener('submit', function() {
                        // Controleer of langSelect bestaat en opties heeft
                        if (langSelect && langSelect.options && langSelect.selectedIndex !== undefined && langSelect.selectedIndex >= 0) {
                            // Haal de geselecteerde taalcode op
                            var selectedOption = langSelect.options[langSelect.selectedIndex];
                            if (selectedOption) {
                                var langCode = selectedOption.value;
                                updateCacheUI(langCode);
                            }
                        }
                    });
                }
            }

            // Validate API fields before form submission
            var generalForm = document.querySelector('#general form');
            if (generalForm) {
                generalForm.addEventListener('submit', function(e) {
                    var apiUrl = document.querySelector('input[name="ai_translate_settings[api_url]"]');
                    var apiKey = document.querySelector('input[name="ai_translate_settings[api_key]"]');
                    // Remove existing error messages
                    var existingErrors = document.querySelectorAll('.aitranslate-error');
                    existingErrors.forEach(function(error) {
                        error.remove();
                    });
                    // Versoepeld: alleen waarschuwing tonen, niet blokkeren
                    if (!apiUrl.value.trim() || !apiKey.value.trim()) {
                        var errorMsg = document.createElement('div');
                        errorMsg.className = 'error notice aitranslate-error';
                        errorMsg.innerHTML = '<p>Let op: Vul zowel API URL als API Key in om vertaalfunctionaliteit te gebruiken.</p>';
                        document.querySelector('#general').insertBefore(errorMsg, document.querySelector('#general form'));
                        // Niet meer: e.preventDefault();
                    }
                });
            }
        });
    </script>
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

    if (!$provider_key) { // Als provider niet in POST zit, haal uit settings
        $settings = get_option('ai_translate_settings');
        $provider_key = $settings['api_provider'] ?? 'openai'; // Default
        if (empty($api_key)) { $api_key = $settings['api_key'] ?? ''; }
        if (empty($model)) { $model = $settings['selected_model'] ?? ''; }
    }

    $api_url = isset($_POST['api_url']) ? esc_url_raw(trim(sanitize_text_field(wp_unslash($_POST['api_url'])))) : '';

    if (empty($api_url) || empty($api_key) || empty($model)) {
        wp_send_json_error(['message' => 'API Provider, API Key, Model of Custom API URL ontbreekt of is ongeldig.']);
        return;
    }

    $endpoint = rtrim($api_url, '/') . '/chat/completions';
    $data = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'Vertaal het woord "test" naar het Engels.'],
            ['role' => 'user', 'content' => 'test']
        ],
        'temperature' => 0.0
    ];
    $response = wp_remote_post($endpoint, [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($data),
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
    $ok = isset($data['choices'][0]['message']['content']) && !empty($data['choices'][0]['message']['content']);
    if ($ok) {
        // Sla settings direct op als validatie slaagt, maar alleen de API-gerelateerde velden.
        if (isset($_POST['save_settings']) && $_POST['save_settings'] === '1') {
            $current_settings = get_option('ai_translate_settings', []); // Haal alle huidige instellingen op

            // Converteer cache_expiration (indien aanwezig) van uren (opgeslagen waarde) naar dagen
            // zodat de sanitize_callback het correct kan verwerken.
            if (isset($current_settings['cache_expiration'])) {
                $current_settings['cache_expiration'] = intval($current_settings['cache_expiration']) / 24;
            }
            
            // Werk alleen de API-specifieke instellingen bij
            $current_settings['api_provider'] = $provider_key;
            $current_settings['api_key'] = $api_key;
            $current_settings['selected_model'] = $model;
            
            // custom_model wordt ook bijgewerkt als 'selected_model' 'custom' is,
            // of als het expliciet wordt meegestuurd. De sanitize_callback handelt dit verder af.
            if (isset($_POST['custom_model_value'])) {
                 $current_settings['custom_model'] = trim(sanitize_text_field(wp_unslash($_POST['custom_model_value'])));
            } elseif ($model !== 'custom' && isset($current_settings['custom_model'])) {
                // Als het model niet 'custom' is, maar custom_model bestond, leegmaken.
                // De sanitize_callback zou dit ook moeten doen, maar voor de duidelijkheid.
                $current_settings['custom_model'] = '';
            }
            // custom_api_url wordt ook bijgewerkt als 'api_provider' 'custom' is
            if (isset($_POST['custom_api_url_value'])) {
                $current_settings['custom_api_url'] = esc_url_raw(trim(sanitize_text_field(wp_unslash($_POST['custom_api_url_value']))));
            } elseif ($provider_key !== 'custom' && isset($current_settings['custom_api_url'])) {
                $current_settings['custom_api_url'] = '';
            }

            update_option('ai_translate_settings', $current_settings); // Sla de volledige, bijgewerkte instellingen op
        }
        wp_send_json_success(['message' => 'API en model werken. API instellingen zijn opgeslagen.']);
    } else {
        wp_send_json_error(['message' => 'API antwoord onbruikbaar: ' . esc_html(wp_remote_retrieve_body($response))]);
    }
});

// Add JavaScript for model selection and API validation
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'toplevel_page_ai-translate') return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var modelSelect = document.getElementById('selected_model');
        var getModelsNonce = '<?php echo esc_attr(wp_create_nonce('ai_translate_get_models_nonce')); ?>';
        var validateApiNonce = '<?php echo esc_attr(wp_create_nonce('ai_translate_validate_api_nonce')); ?>';
        var getModelsNonce = '<?php echo esc_attr(wp_create_nonce('ai_translate_get_models_nonce')); ?>';
        var validateApiNonce = '<?php echo esc_attr(wp_create_nonce('ai_translate_validate_api_nonce')); ?>';
        var customModelDiv = document.getElementById('custom_model_div');
        var apiStatusSpan = document.getElementById('ai-translate-api-status');
        var validateApiBtn = document.getElementById('ai-translate-validate-api');
        var apiKeyInput = document.querySelector('input[name="ai_translate_settings[api_key]"]');
        var customModelInput = document.querySelector('input[name="ai_translate_settings[custom_model]"]');
        var apiProviderSelect = document.getElementById('api_provider_select');
        var apiKeyRequestLinkSpan = document.getElementById('api-key-request-link-span');
        var customApiUrlDiv = document.getElementById('custom_api_url_div');
        var customApiUrlInput = document.querySelector('input[name="ai_translate_settings[custom_api_url]"]');

        // API Provider data (mirrors PHP for client-side use)
        const apiProvidersData = {
            'openai': {
                name: 'OpenAI',
                url: 'https://api.openai.com/v1/',
                key_link: 'https://platform.openai.com/'
            },
            'deepseek': {
                name: 'Deepseek',
                url: 'https://api.deepseek.com/v1/',
                key_link: 'https://platform.deepseek.com/'
            },
            'custom': {
                name: 'Custom URL',
                url: '', // Will be read from the custom_api_url input field
                key_link: '' // No specific key link for custom
            }
        };

        function updateApiKeyRequestLink() {
            if (apiProviderSelect && apiKeyRequestLinkSpan) {
                var selectedProviderKey = apiProviderSelect.value;
                var providerInfo = apiProvidersData[selectedProviderKey];
                if (providerInfo && providerInfo.key_link) {
                    apiKeyRequestLinkSpan.innerHTML = '<a href="' + providerInfo.key_link + '" target="_blank">Request Key</a>';
                } else {
                    apiKeyRequestLinkSpan.innerHTML = '';
                }
            }
        }

        function toggleCustomApiUrlField() {
            console.log('toggleCustomApiUrlField called');
            if (apiProviderSelect && customApiUrlDiv) {
                console.log('apiProviderSelect.value:', apiProviderSelect.value);
                if (apiProviderSelect.value === 'custom') {
                    customApiUrlDiv.style.display = 'block';
                } else {
                    customApiUrlDiv.style.display = 'none';
                }
            } else {
                console.log('apiProviderSelect or customApiUrlDiv not found');
            }
        }

        if (apiProviderSelect) {
            apiProviderSelect.addEventListener('change', updateApiKeyRequestLink);
            apiProviderSelect.addEventListener('change', toggleCustomApiUrlField);
            // Initial call to set the link and toggle field
            updateApiKeyRequestLink();
            toggleCustomApiUrlField();
        }

        function getSelectedApiUrl() {
            if (apiProviderSelect) {
                var selectedProviderKey = apiProviderSelect.value;
                if (selectedProviderKey === 'custom' && customApiUrlInput) {
                    return customApiUrlInput.value;
                }
                var providerInfo = apiProvidersData[selectedProviderKey];
                return providerInfo ? providerInfo.url : '';
            }
            return ''; // Fallback
        }

        function loadModels() {
            var apiUrl = getSelectedApiUrl();
            var apiKey = apiKeyInput ? apiKeyInput.value : '';

            if (!apiUrl || !apiKey) {
                if (apiStatusSpan) apiStatusSpan.textContent = 'Selecteer API Provider en vul API Key in.';
                return;
            }

            if (apiStatusSpan) apiStatusSpan.textContent = 'Load models ...';

            var data = new FormData();
            data.append('action', 'ai_translate_get_models');
            data.append('nonce', getModelsNonce); // Add nonce
            data.append('api_key', apiKey);
            // Pass provider key as well, for server-side logic if needed in future
            if (apiProviderSelect) {
                data.append('api_provider', apiProviderSelect.value);
                if (apiProviderSelect.value === 'custom' && customApiUrlInput) {
                    data.append('custom_api_url_value', customApiUrlInput.value);
                }
            }


            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
            .then(r => r.json())
            .then(function(resp) {
                if (modelSelect) {
                    if (resp.success && resp.data && resp.data.models) {
                        var current = modelSelect.value;
                        modelSelect.innerHTML = ''; // Clear existing options
                        resp.data.models.forEach(function(modelId) {
                            var opt = document.createElement('option');
                            opt.value = modelId;
                            opt.textContent = modelId;
                            if (modelId === current) opt.selected = true;
                            modelSelect.appendChild(opt);
                        });
                        // Add 'custom' option back
                        var customOpt = document.createElement('option');
                        customOpt.value = 'custom';
                        customOpt.textContent = 'Select...'; // Or 'Custom Model...'
                        if (current === 'custom') customOpt.selected = true;
                        modelSelect.appendChild(customOpt);
                        if (apiStatusSpan) apiStatusSpan.textContent = 'Modellen succesvol geladen.';
                    } else {
                        if (apiStatusSpan) apiStatusSpan.textContent = 'Geen modellen gevonden: ' + (resp.data && resp.data.message ? resp.data.message : 'Onbekende fout');
                    }
                }
            })
            .catch(function(e) {
                if (apiStatusSpan) apiStatusSpan.textContent = 'Fout bij laden modellen: ' + e.message;
            });
        }

        if (modelSelect) {
            modelSelect.addEventListener('focus', loadModels); // Load models when dropdown is focused
            modelSelect.addEventListener('change', function() {
                if (customModelDiv) {
                    customModelDiv.style.display = (this.value === 'custom') ? 'block' : 'none';
                }
            });
             // Trigger initial state for custom model div
            if (customModelDiv) {
                customModelDiv.style.display = (modelSelect.value === 'custom') ? 'block' : 'none';
            }
        }

        if (validateApiBtn) {
            validateApiBtn.addEventListener('click', function() {
                if (apiStatusSpan) apiStatusSpan.innerHTML = 'Valideren...';
                
                var apiUrl = getSelectedApiUrl();
                var apiKey = apiKeyInput ? apiKeyInput.value : '';
                var modelId = modelSelect ? modelSelect.value : '';

                if (modelId === 'custom') {
                    modelId = customModelInput ? customModelInput.value : '';
                }

                if (!apiUrl || !apiKey || !modelId) {
                    if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; Selecteer API Provider, vul API Key in en selecteer een model.</span>';
                    return;
                }

                validateApiBtn.disabled = true;

                var data = new FormData();
                data.append('action', 'ai_translate_validate_api');
                data.append('nonce', validateApiNonce); // Add nonce
                data.append('api_url', apiUrl); // PHP AJAX handler expects 'api_url'
                data.append('api_key', apiKey);
                data.append('model', modelId);
                // Pass provider key as well
                if (apiProviderSelect) data.append('api_provider', apiProviderSelect.value);
                // Indicate that settings should be saved upon successful validation by this button
                data.append('save_settings', '1');
                // Stuur ook de waarde van het custom model veld mee, indien relevant
                if (modelId === 'custom' && customModelInput) {
                    data.append('custom_model_value', customModelInput.value);
                }


                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data
                })
                .then(r => r.json())
                .then(function(resp) {
                    if (resp.success) {
                        if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:green;font-weight:bold;">&#10003; Connectie en model OK. API instellingen opgeslagen.</span>';
                    } else {
                        if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; ' +
                            (resp.data && resp.data.message ? resp.data.message : 'Fout') + '</span>';
                    }
                })
                .catch(function(error) {
                    if (apiStatusSpan) apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; Validatie AJAX Fout: ' + error.message + '</span>';
                })
                .finally(function() {
                    validateApiBtn.disabled = false; // Re-enable button
                })
                .catch(function(e) {
                    apiStatusSpan.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; Validation error: ' + e.message + '</span>';
                })
                .finally(function() {
                    validateApiBtn.disabled = false;
                });
            });
        }
    });
    </script>
    <?php
});