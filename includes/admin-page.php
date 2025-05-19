<?php

namespace AITranslate;

// Laad de core class indien deze nog niet bestaat.
// Aangezien dit bestand in de map "includes" staat, staat de core class op hetzelfde niveau.
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
        wp_send_json_error(['message' => 'Onvoldoende rechten om deze actie uit te voeren.']);
        return;
    }

    // Validate nonce
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'clear_cache_language_action')) {
        wp_send_json_error(['message' => 'Beveiligingscontrole mislukt. Vernieuw de pagina en probeer het opnieuw.']);
        return;
    }

    // Validate language code
    if (!isset($_POST['lang_code']) || empty($_POST['lang_code'])) {
        wp_send_json_error(['message' => 'Geen taal geselecteerd.']);
        return;
    }

    $lang_code = sanitize_text_field(wp_unslash($_POST['lang_code']));

    // Clear the cache for this language
    $translator = AI_Translate_Core::get_instance();
    try {
        $count = $translator->clear_cache_for_language($lang_code);
        wp_send_json_success([
            'message' => sprintf('Cache voor taal "%s" gewist. %d bestanden verwijderd.', $lang_code, $count),
            'count' => $count
        ]);
    } catch (\Exception $e) {
        wp_send_json_error([
            'message' => 'Fout bij wissen van cache: ' . $e->getMessage()
        ]);
    }
}

// Registreer de AJAX handlers
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

// Voeg admin menu toe
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

// Instellingen registreren (zonder de shortcodes‑sectie)
add_action('admin_init', function () {
    register_setting('ai_translate', 'ai_translate_settings', [
        'sanitize_callback' => function ($input) {
            // error_log('AI Translate DEBUG: sanitize_callback aangeroepen. Input: ' . print_r($input, true));
            if (isset($input['cache_expiration'])) {
                // Convert days to hours
                $input['cache_expiration'] = intval($input['cache_expiration']) * 24;
            }

            // Handle model selection
            if (isset($input['selected_model'])) {
                if ($input['selected_model'] === 'custom') {
                    // Gebruik alleen de custom_model waarde als die niet leeg is
                    if (!empty($input['custom_model'])) {
                        $input['selected_model'] = $input['custom_model'];
                    } else {
                        $input['selected_model'] = 'gpt-4.1-mini'; // Fallback naar standaardmodel
                    }
                }
            }
            // Trim api_url, api_key en custom_model om spaties te verwijderen
            if (isset($input['api_url'])) {
                $input['api_url'] = trim($input['api_url']);
            }
            if (isset($input['api_key'])) {
                $input['api_key'] = trim($input['api_key']);
            }
            if (isset($input['custom_model'])) {
                $input['custom_model'] = trim($input['custom_model']);
            }

            // Sanitize homepage meta description
            if (isset($input['homepage_meta_description'])) {
                // Use wp_kses_post for basic HTML or sanitize_textarea_field for plain text
                $input['homepage_meta_description'] = sanitize_textarea_field($input['homepage_meta_description']);
            } else {
                $input['homepage_meta_description'] = '';
            }

            // Sanitize enabled languages
            if (isset($input['enabled_languages']) && is_array($input['enabled_languages'])) {
                $input['enabled_languages'] = array_unique(array_map('sanitize_text_field', $input['enabled_languages']));
            } else {
                $input['enabled_languages'] = ['en']; // Default to English if not set or invalid
            }

            // Sanitize detectable languages
            if (isset($input['detectable_languages']) && is_array($input['detectable_languages'])) {
                $input['detectable_languages'] = array_unique(array_map('sanitize_text_field', $input['detectable_languages']));
            } else {
                $input['detectable_languages'] = []; // Default to empty array
            }

            return $input;
        }
    ]);

    // API Instellingen Sectie
    add_settings_section(
        'ai_translate_api',
        'API Settings',
        null,
        'ai-translate'
    );
    add_settings_field(
        'api_url',
        'API URL',
        function () {
            $settings = get_option('ai_translate_settings');
            $value = isset($settings['api_url']) ? rtrim($settings['api_url'], '/') . '/' : 'https://api.openai.com/v1/';
            echo '<input type="text" name="ai_translate_settings[api_url]" value="' . esc_attr($value) . '" class="regular-text">';
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
            echo '<input type="text" name="ai_translate_settings[custom_model]" value="' . esc_attr($is_custom ? $selected_model : $custom_model) . '" placeholder="Bijv: deepseek-chat, gpt-4o, ..." class="regular-text">';
            echo '</div>';
            echo '<button type="button" class="button" id="ai-translate-validate-api">Validate API settings</button>';
            echo '<span id="ai-translate-api-status" style="margin-left:10px;"></span>';

        },
        'ai-translate',
        'ai_translate_api'
    );

    // Taal Instellingen Sectie
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
            // Default detecteerbare talen (kan leeg zijn of een subset bevatten)
            $default_detectable = ['ja', 'zh', 'ru', 'hi', 'ka', 'sv', 'pl', 'ar', 'tr', 'fi', 'no', 'da', 'ko', 'ua']; // Behoud eventueel een default selectie
            $detected_enabled = isset($settings['detectable_languages']) ? (array)$settings['detectable_languages'] : $default_detectable;

            $core = AI_Translate_Core::get_instance();
            // Haal ALLE beschikbare talen op uit de core class
            $languages = $core->get_available_languages();

            echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">';

            // VERWIJDERD: De hardgecodeerde $detectable_languages array is niet meer nodig.
            // $detectable_languages = [ ... ];

            // Loop door ALLE beschikbare talen uit de core class
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

    // Cache Settings Sectie
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
            $value = isset($settings['cache_expiration']) ? intval($settings['cache_expiration'] / 24) : 1;
            echo '<input type="number" name="ai_translate_settings[cache_expiration]" value="' . esc_attr($value) . '" class="small-text"> days';
        },
        'ai-translate',
        'ai_translate_cache'
    );

    // Advanced Settings Sectie
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

    // Clear Debug Log button handling (empty the debug.log file)
    if (isset($_POST['clear_debug']) && check_admin_referer('clear_debug_action', 'clear_debug_nonce')) {
        $debug_log_file = WP_CONTENT_DIR . '/debug.log';
        if (file_exists($debug_log_file)) {
            file_put_contents($debug_log_file, '');
            echo '<div id="message" class="updated notice is-dismissible"><p>WP Debug Log has been cleared.</p></div>';
        } else {
            echo '<div id="message" class="error notice is-dismissible"><p>WP Debug Log not found.</p></div>';
        }
    }    // Cache wissen voor specifieke taal
    $cache_language_message = '';
    if (
        isset($_POST['clear_cache_language']) &&
        check_admin_referer('clear_cache_language_action', 'clear_cache_language_nonce') &&
        isset($_POST['cache_language']) &&
        class_exists('AI_Translate_Core')
    ) {
        $lang_code = sanitize_text_field(wp_unslash($_POST['cache_language']));
        $core = AI_Translate_Core::get_instance();

        // Haal het aantal bestanden op vóór verwijdering
        $cache_stats_before = $core->get_cache_statistics();
        $before_count = isset($cache_stats_before['languages'][$lang_code]) ? $cache_stats_before['languages'][$lang_code] : 0;

        // Wis cache bestanden (krijg nu een array terug met meer informatie)
        $result = $core->clear_cache_for_language($lang_code);

        // Haal het aantal bestanden na verwijdering op
        $cache_stats_after = $core->get_cache_statistics();
        $after_count = isset($cache_stats_after['languages'][$lang_code]) ? $cache_stats_after['languages'][$lang_code] : 0;

        // Haal de taalcode op voor weergave (mooier dan de code)
        $languages = $core->get_available_languages();
        $lang_name = isset($languages[$lang_code]) ? $languages[$lang_code] : $lang_code;

        // Zorg voor een duidelijke melding
        if ($result['success']) {
            if ($result['count'] > 0) {
                $notice_class = isset($result['warning']) ? 'notice-warning' : 'notice-success';
                $cache_language_message = '<div class="notice ' . esc_attr($notice_class) . '" id="cache-cleared-message">
                    <p>Cache voor taal <strong>' . esc_html($lang_name) . ' (' . esc_html($lang_code) . ')</strong> gewist. 
                    <br>Verwijderde bestanden: ' . intval($result['count']) . ' 
                    <br>Resterende bestanden: ' . intval($after_count) . '</p>';

                if (isset($result['warning'])) {
                    $cache_language_message .= '<p class="error-message">Let op: ' . esc_html($result['warning']) . '</p>';
                }

                $cache_language_message .= '</div>';
            } else {
                $cache_language_message = '<div class="notice notice-info" id="cache-cleared-message">
                    <p>Er waren geen cachebestanden voor taal <strong>' . esc_html($lang_name) . ' (' . esc_html($lang_code) . ')</strong>.</p>
                </div>';
            }
        } else {
            $error_message = isset($result['error']) ? $result['error'] : 'Onbekende fout';
            $cache_language_message = '<div class="notice notice-error" id="cache-cleared-message">
                <p>Fout bij het wissen van cache voor taal <strong>' . esc_html($lang_name) . ' (' . esc_html($lang_code) . ')</strong>: ' . esc_html($error_message) . '</p>
            </div>';
        }
    }

    // --- NIEUW: Clear Transient Cache ---
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
    // --- EINDE NIEUW ---

    // Determine active tab
    $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general';
?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <!-- Tab navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="?page=ai-translate&tab=general" class="nav-tab <?php echo esc_attr($active_tab === 'general' ? 'nav-tab-active' : ''); ?>">General</a>
            <a href="?page=ai-translate&tab=logs" class="nav-tab <?php echo esc_attr($active_tab === 'logs' ? 'nav-tab-active' : ''); ?>">Logs</a>
            <a href="?page=ai-translate&tab=cache" class="nav-tab <?php echo esc_attr($active_tab === 'cache' ? 'nav-tab-active' : ''); ?>">Cache</a>

        </h2>
        <div id="tab-content">
            <div id="general" class="tab-panel" style="<?php echo esc_attr($active_tab === 'general' ? 'display:block;' : 'display:none;'); ?>">
                <form method="post" action="options.php">
                    <?php
                    settings_fields('ai_translate');
                    do_settings_sections('ai-translate');
                    submit_button();
                    ?>
                </form>
            </div>
            <div id="logs" class="tab-panel" style="<?php echo $active_tab === 'logs' ? 'display:block;' : 'display:none;' ?>">
                <h2>Logs</h2>
                <h3>WordPress Debug Log</h3>
                <pre style="background:#f8f9fa;padding:15px;max-height:300px;overflow-y:auto;">
<?php
    $wp_debug_log_file = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($wp_debug_log_file)) {
        echo esc_html(file_get_contents($wp_debug_log_file));
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
            <div id="cache" class="tab-panel" style="<?php echo $active_tab === 'cache' ? 'display:block;' : 'display:none;' ?>">
                <h2>Cache Management</h2>
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
        // Update tab links om de tab-parameter toe te voegen
        document.addEventListener('DOMContentLoaded', function() {
            var tabLinks = document.querySelectorAll('.nav-tab-wrapper a');
            tabLinks.forEach(function(link) {
                var tab = link.getAttribute('href').replace('#', '');
                link.href = '<?php echo esc_url(admin_url('admin.php?page=ai-translate')); ?>&tab=' + tab;
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
                    console.log('UI bijwerken voor taal:', langCode);

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
                    console.error('Fout in updateCacheUI:', error);
                }
            }

            // Cache per taal functionaliteit
            var langSelect = document.getElementById('cache_language');
            var langCountSpan = document.getElementById('selected-lang-count');

            // Snelle cache wissen knoppen in de tabel
            var quickClearButtons = document.querySelectorAll('.quick-clear-cache');
            if (quickClearButtons.length > 0) {
                quickClearButtons.forEach(function(button) {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation(); // Voorkom dat de rij-click ook getriggerd wordt

                        var langCode = button.getAttribute('data-lang');
                        if (!langCode) return;

                        // Veilig controleren of langSelect bestaat en opties heeft voordat we ze gebruiken
                        if (langSelect && langSelect.options) {
                            // Selecteer de juiste taal in de dropdown
                            for (var i = 0; i < langSelect.options.length; i++) {
                                if (langSelect.options[i].value === langCode) {
                                    langSelect.selectedIndex = i;
                                    break;
                                }
                            }
                        }// AJAX request om de cache te wissen
                        // Get the nonce from the hidden field
                        var nonce = document.querySelector('input[name="clear_cache_language_nonce"]').value;
                        if (!nonce) {
                            console.error('Nonce veld niet gevonden');
                            
                            // Toon een error bericht
                            var noticeDiv = document.createElement('div');
                            noticeDiv.className = 'notice notice-error is-dismissible';
                            noticeDiv.innerHTML = '<p>Beveiligingstoken niet gevonden. Vernieuw de pagina en probeer het opnieuw.</p>';

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
                            return;
                        }

                        // Voeg een loading indicator toe aan de knop
                        var originalText = button.textContent;
                        button.textContent = 'Bezig...';
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
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Geen rechten']);
    }
    // Haal actuele waarden uit POST als aanwezig
    $api_url = isset($_POST['api_url']) ? trim(sanitize_text_field($_POST['api_url'])) : '';
    $api_key = isset($_POST['api_key']) ? trim(sanitize_text_field($_POST['api_key'])) : '';
    if (empty($api_url) || empty($api_key)) {
        // Fallback op settings als POST leeg is
        $settings = get_option('ai_translate_settings');
        $api_url = isset($settings['api_url']) ? rtrim($settings['api_url'], '/') . '/' : 'https://api.openai.com/v1/';
        $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
    }
    $api_url = rtrim($api_url, '/') . '/';
    if (empty($api_url) || empty($api_key)) {
        wp_send_json_error(['message' => 'API URL of API Key ontbreekt']);
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
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Geen rechten']);
    }
    // Haal actuele waarden uit POST als aanwezig
    $api_url = isset($_POST['api_url']) ? trim(sanitize_text_field($_POST['api_url'])) : '';
    $api_key = isset($_POST['api_key']) ? trim(sanitize_text_field($_POST['api_key'])) : '';
    $model = isset($_POST['model']) ? trim(sanitize_text_field($_POST['model'])) : '';
    if (empty($api_url) || empty($api_key) || empty($model)) {
        // Fallback op settings als POST leeg is
        $settings = get_option('ai_translate_settings');
        $api_url = isset($settings['api_url']) ? rtrim($settings['api_url'], '/') . '/' : 'https://api.openai.com/v1/';
        $api_key = isset($settings['api_key']) ? $settings['api_key'] : '';
        $model = isset($settings['selected_model']) ? $settings['selected_model'] : '';
    }
    $api_url = rtrim($api_url, '/') . '/';
    if (empty($api_url) || empty($api_key) || empty($model)) {
        wp_send_json_error(['message' => 'API URL, Key of model ontbreekt']);
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
        // Sla settings direct op als validatie slaagt
        if (isset($_POST['save_settings']) && $_POST['save_settings'] === '1') {
            $settings = get_option('ai_translate_settings');
            $settings['api_url'] = $api_url;
            $settings['api_key'] = $api_key;
            $settings['selected_model'] = $model;
            update_option('ai_translate_settings', $settings);
        }
        wp_send_json_success(['message' => 'API en model werken']);
    } else {
        wp_send_json_error(['message' => 'API antwoord onbruikbaar']);
    }
});

// Add JavaScript for model selection and API validation
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'toplevel_page_ai-translate') return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var select = document.getElementById('selected_model');
        var customDiv = document.getElementById('custom_model_div');
        var status = document.getElementById('ai-translate-api-status');
        var validateBtn = document.getElementById('ai-translate-validate-api');

        function loadModels() {
            var apiUrl = document.querySelector('input[name="ai_translate_settings[api_url]"]').value;
            var apiKey = document.querySelector('input[name="ai_translate_settings[api_key]"]').value;

            if (!apiUrl || !apiKey) {
                status.textContent = 'Please enter API URL and Key first.';
                return;
            }

            status.textContent = 'Loading models...';

            var data = new FormData();
            data.append('action', 'ai_translate_get_models');
            data.append('api_url', apiUrl);
            data.append('api_key', apiKey);

            fetch(ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
            .then(r => r.json())
            .then(function(resp) {
                if (resp.success && resp.data && resp.data.models) {
                    var current = select.value;
                    select.innerHTML = '';
                    resp.data.models.forEach(function(model) {
                        var opt = document.createElement('option');
                        opt.value = model;
                        opt.textContent = model;
                        if (model === current) opt.selected = true;
                        select.appendChild(opt);
                    });
                    var opt = document.createElement('option');
                    opt.value = 'custom';
                    opt.textContent = 'Select...';
                    if (current === 'custom') opt.selected = true;
                    select.appendChild(opt);
                    status.textContent = 'Models loaded successfully.';
                } else {
                    status.textContent = 'No models found: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error');
                }
            })
            .catch(function(e) {
                status.textContent = 'Error loading models: ' + e.message;
            });
        }

        if (select) {
            select.addEventListener('focus', loadModels);
            select.addEventListener('change', function() {
                if (this.value === 'custom') {
                    customDiv.style.display = 'block';
                } else {
                    customDiv.style.display = 'none';
                }
            });
        }

        if (validateBtn) {
            validateBtn.addEventListener('click', function() {
                status.innerHTML = 'Validating...';
                var apiUrl = document.querySelector('input[name="ai_translate_settings[api_url]"]').value;
                var apiKey = document.querySelector('input[name="ai_translate_settings[api_key]"]').value;
                var model = select.value;
                if (model === 'custom') {
                    model = document.querySelector('input[name="ai_translate_settings[custom_model]"]').value;
                }

                if (!apiUrl || !apiKey || !model) {
                    status.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; Please enter API URL, Key and select a model</span>';
                    return;
                }

                validateBtn.disabled = true;

                var data = new FormData();
                data.append('action', 'ai_translate_validate_api');
                data.append('api_url', apiUrl);
                data.append('api_key', apiKey);
                data.append('model', model);

                fetch(ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data
                })
                .then(r => r.json())
                .then(function(resp) {
                    if (resp.success) {
                        status.innerHTML = '<span style="color:green;font-weight:bold;">&#10003; Connection and model OK</span>';
                    } else {
                        status.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; ' +
                            (resp.data && resp.data.message ? resp.data.message : 'Error') + '</span>';
                    }
                })
                .catch(function(e) {
                    status.innerHTML = '<span style="color:red;font-weight:bold;">&#10007; Validation error: ' + e.message + '</span>';
                })
                .finally(function() {
                    validateBtn.disabled = false;
                });
            });
        }
    });
    </script>
    <?php
});
