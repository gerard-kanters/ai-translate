<?php

declare(strict_types=1);

namespace AITranslate;

if (! defined('ABSPATH')) {
    exit;
}


use function wp_mkdir_p;
use function wp_remote_request;
use function is_wp_error;
use function wp_remote_retrieve_response_code;
use function wp_remote_retrieve_body;
use function get_post;
use function get_option;
use function home_url;
use function esc_url;
use function esc_attr;
use function sanitize_text_field;
use function get_transient;
use function set_transient;
use function is_front_page;
use function is_home;

class AI_Translate_Core
{
    /** @var self|null Singleton instance */
    private static $instance = null;

    /** @var array<string,mixed>|null Plugin settings */
    private $settings = null;

    /** @var array<string,string>|null Beschikbare talen */
    private $available_languages = null;

    /** @var string API endpoint */
    private $api_endpoint;

    /** @var string API key */
    private $api_key;

    /** @var string Cache directory */
    private $cache_dir;

    /** @var int Cache expiration in uren */
    private $expiration_hours;

    /** @var array<int> Excluded post IDs */
    private $excluded_posts = [];

    /** @var string|null Cached current language */
    private $current_language = null;

    /** @var string Default language */
    private $default_language;

    // Houd het laatst gebruikte type bij voor cache expiry logica
    private $last_translate_type = null;

    /**
     * The translation marker added to prevent re-translation.
     */
    const TRANSLATION_MARKER = '<!--aitranslate:translated-->';

    /**
     * Transient name for storing consecutive API error count.
     */
    const API_ERROR_COUNT_TRANSIENT = 'ai_translate_api_error_count';

    /**
     * Transient name indicating API backoff is active.
     */
    const API_BACKOFF_TRANSIENT = 'ai_translate_api_backoff_active';

    /**
     * Maximum number of consecutive API errors before triggering backoff.
     */
    const API_MAX_CONSECUTIVE_ERRORS = 4; // Increased threshold

    /**
     * Duration in seconds for the API backoff period.
     * 1 minutes = 60 seconds
     */
    const API_BACKOFF_DURATION = 60;

    /**
     * Private constructor voor singleton pattern.
     */
    private function __construct()
    {
        $this->init();
        add_action('plugins_loaded', [$this, 'schedule_cleanup']);
        add_action('wp', [$this, 'conditionally_add_fluentform_filter']);
        add_action('wp_head', function () {
            echo '<!-- Begin AI-Translate rel-tag -->' . "\n";
        }, 9); // Prioriteit 9, vóór canonical (10)
        add_action('wp_head', [$this, 'add_alternate_hreflang_links'], 11); // Prioriteit 12, ná End tag (11)
        add_filter('post_type_link', [$this, 'filter_post_type_permalink'], 10, 2);
        add_filter('request', [$this, 'parse_translated_request']);
        add_action('template_redirect', [$this, 'handle_404_redirect'], 1); // Hook for 404 redirection, priority changed to 1
    }

    /**
     * Get singleton instance.
     *
     * @return AI_Translate_Core
     */
    public static function get_instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialiseer de core class.
     */
    private function init(): void
    {
        $this->settings = $this->get_settings();
        // Ensure api_provider is set, defaulting if necessary
        if (!isset($this->settings['api_provider'])) {
            $this->settings['api_provider'] = 'openai'; // Default provider
        }
        $this->settings = $this->validate_settings($this->settings); // Validate after ensuring provider exists

        $this->default_language = $this->settings['default_language'] ?? 'nl';
        $this->api_endpoint = self::get_api_url_for_provider($this->settings['api_provider']);
        $this->cache_dir = $this->get_cache_dir();
        $this->expiration_hours = $this->settings['cache_expiration'];
        $this->excluded_posts = $this->settings['exclude_pages'] ?? []; // Ensure it's an array

        // Initialize cache directories
        $this->initialize_cache_directories();
    }

    /**
     * Schedule periodic cache cleanup.
     * Runs on plugins_loaded action.
     */
    public function schedule_cleanup(): void
    {
        // Perform periodic cleanup (1 in 100 chance, to minimize performance impact)
        if (\wp_rand(1, 100) === 1) {
            $this->cleanup_expired_cache();
        }
    }

    /**
     * Initialiseer de cache directories.
     * Zorg ervoor dat de benodigde mappen bestaan en schrijfbaar zijn.
     */    private function initialize_cache_directories(): void
    {
        // Check if the path exists and create it if necessary
        if (!file_exists($this->cache_dir)) {
            $result = wp_mkdir_p($this->cache_dir);

            if ($result) {
                // Set correct permissions for Linux
                global $wp_filesystem;
                if (empty($wp_filesystem)) {
                    require_once(ABSPATH . '/wp-admin/includes/file.php');
                    WP_Filesystem();
                }
                $wp_filesystem->chmod($this->cache_dir, 0755);
            } else {
            }
        }

        // Check if the path is writable
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        if (!$wp_filesystem->is_writable($this->cache_dir)) {
            // Try to set permissions again
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            $wp_filesystem->chmod($this->cache_dir, 0755);

            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            if (!$wp_filesystem->is_writable($this->cache_dir)) {
            }
        }
    }

    /**
     * Generate cache key for different cache types.
     *
     * @param string $identifier The identifier for the content
     * @param string $target_language The target language
     * @param string $type The type of cache (mem, trans, disk, batch_mem, batch_trans, slug_trans)
     * @return string The generated cache key
     */
    public function generate_cache_key(string $identifier, string $target_language, string $type): string
    {
        $lang = sanitize_key($target_language); // Ensure language code is safe
        $id = sanitize_key($identifier); // Ensure identifier is safe (though often already a hash)

        switch ($type) {
            case 'trans':
                // Prefix for easy transient deletion
                return 'ai_translate_trans_' . $id . '_' . $lang;
            case 'disk':
                // Format matching existing disk cache logic ([lang]_[hash])
                return $lang . '_' . $id;
            case 'batch_mem':
                return 'batch_mem_' . $id . '_' . $lang;
            case 'batch_trans':
                // Prefix for easy batch transient deletion
                return 'ai_translate_batch_trans_' . $id . '_' . $lang;
            case 'slug_trans':
                // Prefix for slug translation transients
                return 'ai_translate_slug_cache_' . $id . '_' . $lang;
            default:
                // Fallback to disk format for unknown types
                return $lang . '_' . $id;
        }
    }

    /**
     * Get default plugin settings.
     *
     * @return array<string,mixed>
     */
    public function get_default_settings(): array
    {
        return [
            'api_provider'      => 'openai',
            'api_keys'          => [
                'openai' => '',
                'deepseek' => '',
                'custom' => '',
            ],
            'selected_model'    => 'gpt-4.1-mini',
            'default_language'  => 'nl',
            'enabled_languages' => ['en', 'de', 'nl'],
            'cache_expiration'  => 336, // hours
            'exclude_pages'     => [],
            'exclude_shortcodes' => [],
            'homepage_meta_description' => '',
            'website_context'   => '',
            'detectable_languages' => ['ja', 'zh', 'ru', 'hi', 'ka', 'sv', 'pl', 'ar', 'tr', 'fi', 'no', 'da', 'ko', 'uk'], // Default detectable
        ];
    }

    /**
     * Get plugin settings.
     *
     * @return array<string,mixed>
     */
    public function get_settings(): array
    {
        if ($this->settings === null) {
            $settings = get_option('ai_translate_settings', []);
            $this->settings = wp_parse_args($settings, $this->get_default_settings());
        }
        return $this->settings;
    }

    /**
     * Validate plugin settings.
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function validate_settings(array $settings): array
    {
        $required = ['default_language', 'api_provider'];
        foreach ($required as $key) {
            if (empty($settings[$key])) {
                // Consider logging an error or handling this more gracefully
                // For now, we assume defaults or sanitize_callback handles it.
            }
        }

        // Ensure api_provider is valid
        if (!array_key_exists($settings['api_provider'], self::get_api_providers())) {
            $settings['api_provider'] = 'openai'; // Fallback to default
        }

        // Ensure api_keys array exists and is valid
        if (!isset($settings['api_keys']) || !is_array($settings['api_keys'])) {
            $settings['api_keys'] = [
                'openai' => '',
                'deepseek' => '',
                'custom' => '',
            ];
        }

        return $settings;
    }

    /**
     * Get defined API providers with their details.
     *
     * @return array<string, array<string, string>>
     */
    public static function get_api_providers(): array
    {
        return [
            'openai' => [
                'name' => 'OpenAI',
                'url' => 'https://api.openai.com/v1/',
                'key_link' => 'https://platform.openai.com/'
            ],
            'deepseek' => [
                'name' => 'Deepseek',
                'url' => 'https://api.deepseek.com/v1/',
                'key_link' => 'https://platform.deepseek.com/'
            ],
            'custom' => [
                'name' => 'Custom URL',
                'url' => '', // This will be dynamically set from settings
                'key_link' => '' // No specific key link for custom
            ],
        ];
    }

    /**
     * Get the API URL for a given provider key.
     *
     * @param string $provider_key The key of the provider (e.g., 'openai').
     * @return string The API URL, or a default/empty string if not found.
     */
    public static function get_api_url_for_provider(string $provider_key): string
    {
        $providers = self::get_api_providers();

        if ($provider_key === 'custom') {
            // Retrieve custom URL from plugin settings
            $settings = get_option('ai_translate_settings', []);
            return $settings['custom_api_url'] ?? ''; // Return custom URL or empty string
        }

        return $providers[$provider_key]['url'] ?? 'https://api.openai.com/v1/'; // Fallback for known providers
    }

    /**
     * Get beschikbare talen.
     *
     * @return array<string,string>
     */
    public function get_available_languages(): array
    {
        if ($this->available_languages === null) {
            $this->available_languages = [
                'nl' => 'Nederlands',
                'en' => 'English',
                'de' => 'Deutsch',
                'fr' => 'Français',
                'es' => 'Español',
                'it' => 'Italiano',
                'ka' => 'ქართული',
                'pt' => 'Português',
                'pl' => 'Polski',
                'sv' => 'Svenska',
                'da' => 'Dansk',
                'no' => 'Norsk',
                'fi' => 'Suomi',
                'ru' => 'Русский',
                'zh' => '中文',
                'ja' => '日本語',
                'ar' => 'العربية',
                'hi' => 'हिन्दी',
                'ko' => '한국어',
                'tr' => 'Türkçe',
                'cs' => 'Čeština',
                'uk' => 'Українська',
                'ro' => 'Română',
                'el' => 'Ελληνικά',
            ];
        }
        return $this->available_languages;
    }

    /**
     * Get cache directory path.
     *
     * @return string
     */    public function get_cache_dir(): string
    {
        $upload_dir = wp_upload_dir();
        // Use DIRECTORY_SEPARATOR for cross-platform compatibility
        return $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'ai-translate' . DIRECTORY_SEPARATOR . 'cache';
    }

    /**
     * Get assets directory path.
     *
     * @param string $type
     * @return string
     */
    public function get_assets_dir(string $type = ''): string
    {
        $base = trailingslashit(AI_TRANSLATE_DIR) . 'assets';
        switch ($type) {
            case 'css':
                return trailingslashit($base) . 'css';
            case 'js':
                return trailingslashit($base) . 'js';
            case 'images':
                return trailingslashit($base) . 'images';
            case 'flags':
                return trailingslashit($base) . 'images/flags';
            default:
                return $base;
        }
    }

    /**
     * Get cached content.
     *
     * @param string $cache_key The full cache key (e.g., 'en_md5hash' for disk).
     * @return string|false
     */    public function get_cached_content(string $cache_key)
    {
        // Normalize the path with directory separator
        $cache_file = rtrim($this->cache_dir, '/\\') . DIRECTORY_SEPARATOR . $cache_key . '.cache';
        // Haal het type op uit de context als beschikbaar
        $type = $this->last_translate_type ?? null;
        if (file_exists($cache_file) && !$this->is_cache_expired($cache_file, $type)) {
            $cached = file_get_contents($cache_file);
            if (!empty($cached)) {
                return $cached;
            }
        }
        return false;
    }

    /**
     * Save content to cache.
     *
     * @param string $cache_key The full cache key (e.g., 'en_md5hash' for disk).
     * @param string $content
     * @return bool Success indicator
     */
    public function save_to_cache(string $cache_key, string $content): bool
    {
        $cache_file = $this->cache_dir . '/' . $cache_key . '.cache';

        try {
            // Check if the cache directory exists and is writable
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            if (!$wp_filesystem->is_writable($this->cache_dir)) {
                return false;
            }
        

            // Write to a temporary file and then move (atomic write operation)
            $temp_file = $this->cache_dir . '/tmp_' . uniqid() . '.tmp';
            $write_result = file_put_contents($temp_file, $content, LOCK_EX);

            if ($write_result === false) {
                return false;
            }

            // Set file permissions
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            $wp_filesystem->chmod($temp_file, 0644);

            // Move to final file
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            if (!$wp_filesystem->move($temp_file, $cache_file, true)) { // true to overwrite
                global $wp_filesystem;
                if (empty($wp_filesystem)) {
                    require_once(ABSPATH . '/wp-admin/includes/file.php');
                    WP_Filesystem();
                }
                $wp_filesystem->delete($temp_file); // Cleanup
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if cache is expired, met uitzondering voor permanente types.
     *
     * @param string $cache_file
     * @param string|null $type
     * @return bool
     */
    private function is_cache_expired(string $cache_file, ?string $type = null): bool
    {
        if (!file_exists($cache_file)) {
            return true;
        }

        // Types die NIET permanent zijn (dus wel verlopen):
        $non_permanent_types = [
            'post_content',
            'post_title',
            'post_excerpt',
            'search_form',
            'search_query',
            'search_page_title',
            'search_content',
            'search_placeholders',
            'search_result_title',
            'search_result_excerpt',
            'plugin_generic',
            'plugin_woocommerce',
            'plugin_contact_form_7',
            'plugin_elementor',
            'plugin_divi',
            'plugin_beaver_builder',
            'plugin_fluentform',
            'fluentform',
            'menu_item_description'
        ];

        // Als het type niet permanent is, gebruik normale expiry logica
        if ($type && in_array($type, $non_permanent_types, true)) {
            $cache_time = filemtime($cache_file);
            $age_in_seconds = time() - $cache_time;
            $expires_in_seconds = $this->expiration_hours * 3600;

            $is_expired = $age_in_seconds > $expires_in_seconds;

            return $is_expired;
        }

        // Voor permanente types (zoals post_content), cache nooit verlopen
        return false;
    }

    /**
     * Make API request to OpenAI. Handles retries for rate limits and implements backoff.
     *
     * @param string $endpoint API endpoint.
     * @param array<string,mixed> $data Request data.
     * @return array<string,mixed> API response data.
     * @throws \Exception If API request fails after retries or if backoff is active.
     */
    private function make_api_request(string $endpoint, array $data): array
    {
        // --- Backoff Check ---
        if (get_transient(self::API_BACKOFF_TRANSIENT)) {
            $error_message = "API backoff active due to repeated errors. Please wait.";
            throw new \Exception(esc_html($error_message)); // Throw exception to prevent API call
        }
        // --- End Backoff Check ---

        $url = $this->api_endpoint . $endpoint;
        // Haal de API-sleutel dynamisch op uit de settings array
        $current_api_key = $this->settings['api_keys'][$this->settings['api_provider']] ?? '';
        if (empty($current_api_key)) {
            throw new \Exception('API Key is niet geconfigureerd voor de geselecteerde provider.');
        }

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $current_api_key,
                'Content-Type'  => 'application/json'
            ],
            'body'    => json_encode($data),
            'timeout' => 45, // Increased timeout
            'sslverify' => true, // Keep SSL verification enabled
            'method'  => 'POST'
        ];
        $maxRetries = 3; // Retries specifically for 429 or temporary network issues
        $backoff = 1; // Initial backoff delay in seconds for retries
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $error = $response->get_error_message();
                // Treat WP_Error as a potentially temporary issue for retry
                sleep($backoff);
                $backoff *= 2;
                $attempt++;
                continue; // Retry on WP_Error
            }

            $status = wp_remote_retrieve_response_code($response);

            if ($status === 429) {
                sleep($backoff);
                $backoff *= 2;
                $attempt++;
                continue; // Retry on 429
            }

            if ($status >= 500) { // Retry on server errors (5xx)
                sleep($backoff);
                $backoff *= 2;
                $attempt++;
                continue; // Retry on 5xx
            }


            if ($status === 200) {
                // --- Success: Reset error count ---
                delete_transient(self::API_ERROR_COUNT_TRANSIENT);
                // --- End Success ---
                $body = wp_remote_retrieve_body($response);
                $result = json_decode($body, true);
                // Basic validation of the result structure
                if (is_array($result) && isset($result['choices'][0]['message']['content'])) {
                    return $result;
                } else {
                    // Treat as failure for backoff purposes
                    $error_message = "API error: Unexpected response format.";
                    // Fall through to error handling below
                }
            } else { // Handle non-200, non-429, non-5xx errors immediately (e.g., 400, 401, 403, 404)
                $body = wp_remote_retrieve_body($response);
                $error_data = json_decode($body, true);
                $error_message = $error_data['error']['message'] ?? "Unknown API error (status $status)";
                // No retry for these errors, proceed to increment error count and throw exception
                // Fall through to error handling below
                break; // Exit retry loop
            }
        }

        // --- Failure Handling (After retries or for immediate failures) ---
        $error_count = (int) get_transient(self::API_ERROR_COUNT_TRANSIENT);
        $error_count++;
        set_transient(self::API_ERROR_COUNT_TRANSIENT, $error_count, self::API_BACKOFF_DURATION * 2); // Store count longer than backoff

        if ($error_count >= self::API_MAX_CONSECUTIVE_ERRORS) {
            set_transient(self::API_BACKOFF_TRANSIENT, true, self::API_BACKOFF_DURATION);
            $error_message = "API backoff activated due to repeated errors."; // More specific message
        } else {
            // Use the last known error message if available
            $error_message = $error_message ?? "API request failed after $maxRetries attempts.";
        }

        throw new \Exception(esc_html($error_message));
        // --- End Failure Handling ---
    }

    /**
     * Translate text using OpenAI API with caching and placeholder handling.
     *
     * @param string $text Text to translate.
     * @param string $source_language The *expected* source language code of the input $text.
     * @param string $target_language The desired target language code.
     * @param bool $is_title Whether the text is a title (influences API prompt slightly).
     * @param bool $use_disk_cache Whether to use disk cache for this translation.
     * @return string Translated text or original text on failure/skip.
     */
    public function translate_text(
        string $text,
        string $source_language,
        string $target_language,
        bool $is_title = false,
        bool $use_disk_cache = true,
        string $context = ''
    ): string {
        // --- Essential Checks ---
        // 0. Check if already translated (absolute first check)
        if (strpos($text, '<!--aitranslate:translated-->') !== false) {
            return $text;
        }

        // 0a. AJAX request check
        if (wp_doing_ajax()) {
            return $text;
        }

        // 0a. Never translate style tags
        if (stripos(trim($text), '<style') === 0) {
            return $text;
        }

        // 0d. Never translate hidden fields from Contact Form 7
        $cf7_hidden_fields = ['_wpcf7', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post', '_wpcf7_version'];
        if (in_array($text, $cf7_hidden_fields, true)) {
            return $text;
        }

        // 0c. Never translate chatbot scripts
        if (strpos($text, 'kchat_settings') !== false || strpos($text, 'chatbot-chatgpt') !== false) {
            return $text;
        }
        
        // 0d. Never translate FluentForm content (handled by translate_fluentform_fields)
        // Only skip if the text contains actual FluentForm HTML elements, not just the word "fluentform"
        if (strpos($text, '<') !== false && (
            strpos($text, 'ff-el-form') !== false || 
            strpos($text, 'ff-el-input') !== false ||
            strpos($text, 'ff-btn') !== false ||
            strpos($text, 'ff-field_container') !== false ||
            strpos($text, 'ff-el-group') !== false ||
            strpos($text, 'ff-t-container') !== false ||
            strpos($text, 'ff-t-cell') !== false ||
            strpos($text, 'ff-el-input--label') !== false ||
            strpos($text, 'ff-el-input--content') !== false ||
            strpos($text, 'ff-el-form-control') !== false ||
            strpos($text, 'ff_form_instance') !== false ||
            strpos($text, 'fluentform') !== false
        )) {
            return $text;
        }

        // 0d. Skip content that contains shortcode placeholders
        $trimmed_text = trim($text);

        // Get the configured default language
        $default_language = $this->settings['default_language'] ?? null;
        // 1. Skip translation if the *target language* is the configured default language.
        // We assume that content in the default language does not need to be translated *to* the default language.
        if (!empty($default_language) && $target_language === $default_language) {
            return $text;
        }

        // 2. Skip translation if source and target (explicitly provided) are the same.
        // This check remains important for cases where the target language is *not* the default, but is equal to the source.
        if ($source_language === $target_language) {
            return $text;
        }

        // 3. Skip translation for empty text.
        if (empty(trim($text))) {
            return $text;
        }

        // 4. Skip translation in admin area.
        if (is_admin()) {
            return $text;
        }

        // --- End Essential Checks ---

        // --- GUARD WITH LOGGING: Prevent translation of full HTML/large scripts ---
        $trimmed_text = trim($text);
        $skip_reason = ''; // Keep track of why we skip

        if (stripos($trimmed_text, '<html') === 0) {
            $skip_reason = 'Starts with <html>';
        } elseif (stripos($trimmed_text, '<!DOCTYPE') === 0) {
            $skip_reason = 'Starts with <!DOCTYPE>';
        } elseif (stripos($trimmed_text, '<script') === 0) {
            // Check if this is the reason for skipping description
            $skip_reason = 'Starts with <script>';
        } elseif (strlen($text) > 20000) { // Length check
            $skip_reason = 'Length > 20000';
        }

        // If there's a reason to skip, log it and stop
        if (!empty($skip_reason)) {
            $context_hint = substr(preg_replace('/\s+/', ' ', $trimmed_text), 0, 100);
            return $text; // Return original text
        }

        // --- END GUARD WITH LOGGING ---

        // --- Exclude shortcodes specified in admin ---
        $shortcodes_to_exclude = self::get_truly_excluded_shortcodes();
        $extracted_shortcode_pairs = [];

        // Extract shortcode pairs and replace with placeholders
        $text_with_placeholders = $this->extract_shortcode_pairs($text, $shortcodes_to_exclude, $extracted_shortcode_pairs);

        $text_to_translate = $text_with_placeholders;
        $shortcodes = $extracted_shortcode_pairs; // Rename for consistency with existing code

        // Detect: text consists only of truly excluded shortcodes?
        $truly_excluded_shortcodes = self::get_truly_excluded_shortcodes();
        $only_excluded = $this->is_only_excluded_shortcodes($text, $truly_excluded_shortcodes);

        // --- Strip all shortcodes before calculating the cache-key and before translation ---
        // strip_all_shortcodes_for_cache will now see the placeholders and strip them as well.
        $text_for_cache = $this->strip_all_shortcodes_for_cache($text_to_translate);

        // Determine caching strategy based on context
        $caching_strategy = $this->get_caching_strategy($text_for_cache, $context);

        // Voor Subscribe2: filter IP adressen uit de cache key om unieke cache files te voorkomen
        $text_for_cache_key = $text;
        if (strpos($text, 's2formwidget') !== false) {
            // Gebruik unieke statische placeholder voor IP adressen
            $text_for_cache_key = preg_replace('/value=["\"](\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})["\"]/i', 'value="__AITRANSLATE_SC_XIP__"', $text);
            $text_for_cache_key = preg_replace('/value=[\'"](\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})[\'"]/i', 'value="__AITRANSLATE_SC_XIP__"', $text_for_cache_key);
        }
        
        // Use md5 of the original text + target language as the base identifier (not stripped text)
        $cache_identifier = md5($text_for_cache_key . $target_language);
        

        // Generate keys with the central function
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');

        // SHORTCODE-ONLY: only in memory cache!
        if ($only_excluded) {
            return $text;
        }

        // For global UI elements, use special global cache
        if ($caching_strategy === 'global_ui') {
            $global_cached = $this->get_global_ui_element($text, $target_language);
            if ($global_cached !== null) {
                if (!empty($shortcodes)) {
                    $global_cached = $this->restore_shortcode_pairs($global_cached, $shortcodes); // Use new restore function
                }
                return $global_cached;
            }
        }


        // --- Disk Cache Key ---
        $disk_cache_key = $this->generate_cache_key($cache_identifier, $target_language, 'disk');

        // Only disk cache if allowed
        $disk_cached = false;
        if ($use_disk_cache) {
            // get_cached_content expects the key WITHOUT .cache suffix
            $disk_cached = $this->get_cached_content($disk_cache_key);
            
            if ($disk_cached !== false) {
                if (strpos($disk_cached, self::TRANSLATION_MARKER) !== false) {
                    $result = $disk_cached;
                    
                    // Voor Subscribe2: vervang placeholders terug naar echte IP adressen
                    if (strpos($text, 's2formwidget') !== false) {
                        // Vervang __AITRANSLATE_SC_XIP__ placeholder terug naar het echte IP adres van de huidige gebruiker
                        $current_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                        $result = str_replace('value="__AITRANSLATE_SC_XIP__"', 'value="' . $current_ip . '"', $result);
                        $result = str_replace("value='__AITRANSLATE_SC_XIP__'", "value='" . $current_ip . "'", $result);
                    }
                    
                    if (!empty($shortcodes)) {
                        $result = $this->restore_shortcode_pairs($result, $shortcodes);
                    }
                    // Opslaan in transient voor snellere toegang
                    $transient_key = $this->generate_cache_key($cache_identifier, $target_language, 'trans');
                    set_transient($transient_key, $result, $this->expiration_hours * 3600);
                    
                    return $result;
                }
            }
        }

        // Generate transient key
        $transient_key = $this->generate_cache_key($cache_identifier, $target_language, 'trans');
        $cached = get_transient($transient_key);
        
        if ($cached !== false) {
            if (strpos($cached, self::TRANSLATION_MARKER) !== false) {
                $result = $cached;
                
                // Voor Subscribe2: vervang placeholders terug naar echte IP adressen
                if (strpos($text, 's2formwidget') !== false) {
                    // Vervang __AITRANSLATE_SC_XIP__ placeholder terug naar het echte IP adres van de huidige gebruiker
                    $current_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                    $result = str_replace('value="__AITRANSLATE_SC_XIP__"', 'value="' . $current_ip . '"', $result);
                    $result = str_replace("value='__AITRANSLATE_SC_XIP__'", "value='" . $current_ip . "'", $result);
                }
                
                if (!empty($shortcodes)) {
                    $result = $this->restore_shortcode_pairs($result, $shortcodes);
                }
                // Als het uit transient komt, ook opslaan in disk cache (indien toegestaan)
                if ($use_disk_cache) {
                    $this->save_to_cache($disk_cache_key, $result);
                }
                
                return $result;
            }
        }

        // --- Extract dynamic elements (scripts, images, shortcodes, nonces, referers, etc.) ---
        $placeholders = [];
        $placeholder_index = 0;
        $text_processed = $text_to_translate; // Start with text after excluded shortcodes
        
        // Extract nonces and tokens to prevent translation
        // Skip security token extraction for FluentForm and Subscribe2 content to prevent HTML corruption
        if (strpos($text_processed, 'fluentform') === false && 
            strpos($text_processed, 'ff-') === false && 
            strpos($text_processed, 's2formwidget') === false) {
            $text_processed = $this->extract_security_tokens($text_processed, $placeholders, $placeholder_index);
        } elseif (strpos($text_processed, 's2formwidget') !== false) {
            // Voor Subscribe2: alleen IP adressen maskeren
            $text_processed = $this->extract_security_tokens($text_processed, $placeholders, $placeholder_index, ['ip_addresses']);
        }

        // Remove all placeholders of the type __AITRANSLATE_X__, __AITRANSLATE_FF_X__, and __AITRANSLATE_SEC_X__ from the text and remember their positions
        $placeholder_pattern = '/__AITRANSLATE(?:_FF|_SEC)?_\d+__/';
        $placeholder_positions = [];
        if (preg_match_all($placeholder_pattern, $text_processed, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $placeholder = $match[0];
                $offset = $match[1];
                $placeholder_positions[] = [
                    'placeholder' => $placeholder,
                    'offset' => $offset
                ];
            }
            // Remove all placeholders from the text
            $text_processed = preg_replace($placeholder_pattern, '', $text_processed);
        }

        // --- Ensure there are now NO __AITRANSLATE_X__ or __AITRANSLATE_FF_X__ placeholders left in the input ---
        if (preg_match($placeholder_pattern, $text_processed)) {
            throw new \Exception('There is still a placeholder in the input for translation.');
        }

        // --- API Call ---
        try {
            $translated_text_with_placeholders = $this->do_translate($text_processed, $source_language, $target_language, $is_title, $use_disk_cache);
        } catch (\Exception $e) {
            // Restore original text with all placeholders if API call fails
            if (!empty($placeholders)) {
                // Sort placeholders by length in descending order to prevent partial matches
                uksort($placeholders, function ($a, $b) {
                    return strlen($b) <=> strlen($a);
                });
                foreach ($placeholders as $placeholder => $original_value) {
                    $text = str_replace($placeholder, $original_value, $text);
                }
            }
            if (!empty($shortcodes)) {
                return $this->restore_shortcode_pairs($text, $shortcodes);
            }
            return $text; // Return original text
        }

        // --- Restore placeholders at their original positions ---
        if (!empty($placeholder_positions)) {
            // Insert placeholders back at their original positions
            // Because string offsets change after replacement, work from back to front
            usort($placeholder_positions, function ($a, $b) {
                return $b['offset'] - $a['offset'];
            });
            foreach ($placeholder_positions as $info) {
                $translated_text_with_placeholders = substr_replace(
                    $translated_text_with_placeholders,
                    $info['placeholder'],
                    (int) $info['offset'],
                    0
                );
            }
        }
        $final_translated_text = $translated_text_with_placeholders;



        // --- Restore security tokens and other placeholders ---
        if (!empty($placeholders)) {
            // Sort placeholders by length in descending order to prevent partial matches
            uksort($placeholders, function ($a, $b) {
                return strlen($b) <=> strlen($a);
            });
            
            foreach ($placeholders as $placeholder => $original_value) {
                $final_translated_text = str_replace($placeholder, $original_value, $final_translated_text);
            }
        }

        // --- Restore excluded shortcodes ---
        if (!empty($shortcodes)) {
            $final_translated_text = $this->restore_shortcode_pairs($final_translated_text, $shortcodes); // Use new restore function
        }


        // --- Sanity check: If translation resulted in empty text but original wasn't, log critical error ---
        // This check remains important, but we return $text instead of $final_translated_text if the marker is missing.
        if (empty(trim(wp_strip_all_tags(str_replace(self::TRANSLATION_MARKER, '', (string)$final_translated_text)))) && !empty(trim(wp_strip_all_tags($text)))) {
            $final_translated_text = $text . self::TRANSLATION_MARKER; // Add marker to original text
        }

        // --- Cache the translated text ---
        // MEMORY CACHE VERWIJDERD

        // Store in persistent cache (transient/disk) only if the marker is present.
        if (strpos((string)$final_translated_text, self::TRANSLATION_MARKER) !== false) {
            // Voor Subscribe2: filter IP adressen uit de content die wordt opgeslagen in cache
            $content_for_cache = $final_translated_text;
            if (strpos($text, 's2formwidget') !== false) {
                // Filter IP adressen uit de content die wordt opgeslagen
                $content_for_cache = preg_replace('/value=["\"](\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})["\"]/i', 'value="__AITRANSLATE_SC_XIP__"', $final_translated_text);
                $content_for_cache = preg_replace('/value=[\'"](\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})[\'"]/i', 'value="__AITRANSLATE_SC_XIP__"', $content_for_cache);
            }
            
            // For global UI elements, use special global cache
            if ($caching_strategy === 'global_ui') {
                $this->cache_global_ui_element($text, $target_language, $content_for_cache);
            } else {
                // Store in transient
                set_transient($transient_key, $content_for_cache, $this->expiration_hours * 3600);
                // Store in disk if enabled
                if ($use_disk_cache) {
                    // save_to_cache expects the key WITHOUT .cache suffix
                    $this->save_to_cache($disk_cache_key, $content_for_cache);
                }
            }
        }

        // Return the final text (translated or original)
        return $final_translated_text;
    }

    /**
     * Internal translation method. Adds TRANSLATION_MARKER on success.
     *
     * @param string $text Text to translate (placeholders already extracted).
     * @param string $source_language Source language code.
     * @param string $target_language Target language code.
     * @param bool $is_title Whether the text is a title.
     * @param bool $use_disk_cache Whether disk cache should be used *if* this call results in a new translation.
     * @return string Translated text with marker on success, original text otherwise.
     * @throws \Exception If API call fails or returns invalid content.
     */
    private function do_translate(string $text, string $source_language, string $target_language, bool $is_title, bool $use_disk_cache): string
    {

        if (strpos($text, self::TRANSLATION_MARKER) !== false) {
            //error_log("AI-Translate: Skipping already translated text in do_translate: '" . substr(trim($text), 0, 50) . "'");
            return $text;
        }

        if (empty(trim($text))) {
            // Return empty text with marker to cache the fact that it's empty and processed
            return $text . self::TRANSLATION_MARKER;
        }

        // Generate optimized system prompt with proper validation
        $system_prompt = $this->build_translation_prompt($source_language, $target_language, $is_title, $text);

        // Use zero temperature for slug translations to ensure consistency
        $temperature = 0.0; // Zero temperature for maximum consistency

        $data = [
            'model'             => $this->settings['selected_model'],
            'messages'          => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $text]
            ],
            'temperature'       => $temperature,
            'frequency_penalty' => 0,
            'presence_penalty'  => 0
        ];

        $context_hint = substr(preg_replace('/\s+/', ' ', $text), 0, 50);

        $response = $this->make_api_request('chat/completions', $data);
        $translated = $response['choices'][0]['message']['content'] ?? null;

        // Trim spaces from beginning/end for better comparison
        $trimmed_original = trim($text);
        $trimmed_translated = trim((string)$translated);

        // Check if the API returned a non-empty string
        if (!empty($trimmed_translated)) {
            // API returned a non-empty string. Always add the marker.
            $result_with_marker = $translated . self::TRANSLATION_MARKER;

            return $result_with_marker; // Return translated or original text with marker
        } else {
            // API returned empty or invalid content.
            // Fallback: return original text with marker to prevent infinite loops
            $result = $text . self::TRANSLATION_MARKER;
        }

        return $result;
    }

    /**
     * Build optimized translation prompt with proper validation and caching.
     *
     * @param string $source_language Source language code.
     * @param string $target_language Target language code.
     * @param bool $is_title Whether the text is a title.
     * @return string The system prompt for translation.
     */
    private function build_translation_prompt(string $source_language, string $target_language, bool $is_title = false, string $text_to_translate = ''): string
    {
        // Determine if this is a menu item (less than 50 characters)
        $text_length = strlen(trim($text_to_translate));
        $is_short_menu_item = $text_length <= 50 && $is_title;

        $context_instructions = $is_title
            ? 'Focus on translating titles and headings accurately while maintaining their impact and meaning. NEVER add HTML tags to plain text titles - if the input has no HTML tags, the output should also have no HTML tags. NEVER wrap output in <p>, <span>, <div> of andere HTML-tags als de input geen HTML bevat.'
            : 'Translate the content while preserving its original meaning, tone, and structure. NEVER add HTML tags to plain text - if the input has no HTML tags, the output must also have no HTML tags. NEVER wrap output in <p>, <span>, <div> or any other HTML tags if the input does not contain HTML.';

        // Get website context if available - properly escape and handle UTF-8
        $website_context = '';
        $should_use_context = $text_length > 50 && !empty($this->settings['website_context']);

        if ($should_use_context) {
            // Ensure proper UTF-8 encoding and escape any problematic characters
            $context_text = $this->settings['website_context'];

            // Convert to UTF-8 if not already
            if (!mb_check_encoding($context_text, 'UTF-8')) {
                $context_text = mb_convert_encoding($context_text, 'UTF-8', 'auto');
            }

            // Clean the text: remove any null bytes and normalize whitespace
            $context_text = str_replace("\0", '', $context_text);
            $context_text = preg_replace('/\s+/', ' ', trim($context_text));

            // Escape any quotes that might break the prompt structure
            // Use JSON encoding for safe embedding in the prompt
            $context_json = json_encode($context_text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($context_json !== false) {
                // Remove the outer quotes from JSON encoding since we're embedding it
                $context_json = substr($context_json, 1, -1);
                $website_context = "\n\nWEBSITE CONTEXT:\n" . $context_json . "\n\nUse this context to provide more accurate and contextually appropriate translations for longer content. For single words, short phrases, or slugs, translate literally and directly without applying contextual interpretation to avoid confusion.";
            } else {
                // Fallback: use basic escaping if JSON encoding fails
                $context_text = str_replace(['"', "'"], ['\"', "\\'"], $context_text);
                $website_context = "\n\nWEBSITE CONTEXT:\n" . $context_text . "\n\nUse this context to provide more accurate and contextually appropriate translations for longer content. For single words, short phrases, or slugs, translate literally and directly without applying contextual interpretation to avoid confusion.";
            }
        }

        // Special instructions for menu items
        $menu_instructions = '';
        if ($is_short_menu_item) {
            $menu_instructions = "\n\nMENU ITEM TRANSLATION RULES:\n- Translate the menu text completely and accurately\n- Do NOT include any website context in the translation\n- Do NOT add explanations or additional text\n- Return ONLY the translated menu text, nothing else\n- If the input is 'Contact', translate to the equivalent word in the target language\n- If the input is 'About', translate to the equivalent word in the target language\n- If the input is 'Home', translate to the equivalent word in the target language\n- For navigation items, use the most common and direct translation\n- Provide the full translation - display shortening will be handled separately";
        }

        $system_prompt = sprintf(
            'You are a professional translation engine. CRITICAL: The source language is %s and the target language is %s. IGNORE the apparent language of the input text and translate as instructed.%s%s
        
        TRANSLATION STYLE:
        - Make the translation sound natural, fluent, and professional, as if written by a native speaker.
        - Adapt phrasing slightly to ensure it is persuasive and consistent with standard website language in the target language.
        - Avoid literal translations that sound awkward or robotic.
        - Use idiomatic expressions and natural vocabulary appropriate for the audience and context.
        - Maintain the original tone and intent while ensuring the text flows smoothly and is suitable for publication.
        
        CRITICAL REQUIREMENTS:
        1. Preserve ALL HTML tags and their exact structure. Do NOT add, remove, move, or modify any HTML tags in any way.
        2. If the input contains NO HTML tags, the output must also contain NO HTML tags.
        3. Any string in the format __AITRANSLATE_X__, __AITRANSLATE_SC_X__, or __AITRANSLATE_FF_X__ (where X is any number or combination of letters, numbers, or underscores) is a SYSTEM TOKEN and must remain 100%% UNCHANGED in the output. NEVER translate, move, modify, wrap, remove, or reformat these tokens in any way. They are NOT placeholders for words or phrases, but reserved technical markers for system use.
           - Example: __AITRANSLATE_42__ in input = __AITRANSLATE_42__ in output.
           - Example: __AITRANSLATE_SC_8__ in input = __AITRANSLATE_SC_8__ in output.
           - Example: __AITRANSLATE_FF_3__ in input = __AITRANSLATE_FF_3__ in output.
        4. Any string in the format __AISHORTCODE_X__ (where X is any number) is a SHORTCODE PLACEHOLDER and must remain 100%% UNCHANGED in the output. These are placeholders for WordPress shortcodes that should NOT be translated.
           - Example: __AISHORTCODE_1__ in input = __AISHORTCODE_1__ in output.
           - Example: __AISHORTCODE_5__ in input = __AISHORTCODE_5__ in output.
        5. Preserve all line breaks, paragraph breaks, and whitespace formatting as in the original. Do NOT escape or modify quotes or special characters in HTML attributes.
        6. Return ONLY the translated text in the target language. Do NOT provide explanations, comments, or markdown formatting. NEVER return source language text unless the target language IS the source language.
        7. If the input is empty, output must also be empty. If the input cannot be translated meaningfully, return the closest possible equivalent in the target language.
        8. If the input contains NO HTML tags, the output MUST also contain NO HTML tags. NEVER wrap plain text in HTML tags like <p>, <div>, <span>, or any other tags.
        9. For single words, short phrases, or slugs (especially for URLs), translate directly and literally, without contextual interpretation.
        10. SYSTEM TOKENS (__AITRANSLATE_X__, __AITRANSLATE_SC_X__, __AITRANSLATE_FF_X__, and __AISHORTCODE_X__) ARE NEVER TOUCHED, TRANSLATED, MOVED, OR MODIFIED FOR ANY REASON.
        11. CRITICAL: Do NOT detect or assume the language of the input text. Translate exactly as instructed from the specified source language to the specified target language.
        
        Begin the translation below.',
            $this->get_language_name($source_language),
            $this->get_language_name($target_language),
            $website_context,
            $menu_instructions
        );

        return $system_prompt;
    }

    /**
     * Get human-readable language name from language code.
     *
     * @param string $language_code ISO language code.
     * @return string Human-readable language name.
     */
    private function get_language_name(string $language_code): string
    {
        $languages = $this->get_available_languages();
        return $languages[$language_code] ?? ucfirst($language_code);
    }

    /**
     * Generic entry point for translating content parts (string or array).
     *
     * - For batch types (menu_item, post_title[], widget_title[]), this function delegates to batch_translate_items.
     * - For single strings, this function uses translate_text (with all cache layers).
     * - For menu_item: individual translation is explicitly skipped; only batch is allowed.
     *
     * Do NOT call this for individual menu items or FluentForm field names; always use the batch variant.
     *
     * @param string|array $content Content to translate (string or array)
     * @param string $type Type of content (e.g. 'post_title', 'menu_item', 'widget_title', etc.)
     * @return string|array Translated content (same type as input)
     */
    public function translate_template_part($content, string $type)
    {

        if (!$this->needs_translation() || is_admin() || empty($content)) {
            return is_array($content) ? array_values($content) : (string)$content;
        }

        // Batch handling for specific types
        if (in_array($type, ['post_title', 'widget_title'], true) && is_array($content)) {
            $target_language_batch = $this->get_current_language();
            $translated_batch = $this->batch_translate_items($content, $this->default_language, $target_language_batch, $type);

            // Fallback: if batch fails or doesn't return an array, always return an array
            if (!is_array($translated_batch) || count($translated_batch) !== count($content)) {
                return array_values($content);
            }
            return $translated_batch;
        }

        // Skip individual translation for menu_item - should only be handled by batch functions
        if ($type === 'menu_item') {
            return $content;
        }

        // Ensure content is a string
        if (is_array($content)) {
            $content = (string) reset($content);
        }

        $target_language = $this->get_current_language();
        $default_language = $this->default_language ?: ($this->settings['default_language'] ?? 'nl');

        // Check of content alleen uit echt uitgesloten shortcodes bestaat
        $shortcodes_to_exclude = self::get_truly_excluded_shortcodes();
        $only_excluded = $this->is_only_excluded_shortcodes($content, $shortcodes_to_exclude);

        // For widget content, use a consistent cache key regardless of type
        if (in_array($type, ['widget_title', 'widget_text'])) {
            // Strip HTML tags and normalize content for consistent caching
            $normalized_content = wp_strip_all_tags($content);
            $cache_identifier = md5($normalized_content . 'widget' . $target_language);
        } else {
            $cache_identifier = md5($content . $type . $target_language);
        }
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');

        // --- CUSTOM Memory Cache Check ---
        // MEMORY CACHE VERWIJDERD
        // --- END CUSTOM Memory Cache Check ---

        $this->last_translate_type = $type;

        $use_disk_cache = !in_array($type, ['site_title', 'tagline'], true);
        $is_title = in_array($type, ['post_title', 'menu_item', 'widget_title'], true);

        // SHORTCODE-ONLY: alleen in memory cache!
        if ($only_excluded) {
            return $content;
        }

        // Call translate_text, which uses do_translate and handles persistent caching
        $translated = $this->translate_text(
            $content,
            $default_language,
            $target_language,
            $is_title,
            $use_disk_cache,
            $type // Pass type as context for caching strategy
        );

        // Strip HTML tags from titles to prevent <p> tags in page titles
        if ($is_title || in_array($type, ['site_title', 'tagline'], true)) {
            $translated = wp_strip_all_tags($translated);
        }

        // Remove translation marker before returning (after all processing)
        $translated = self::remove_translation_marker($translated);

        return $translated;
    }

    /**
     * Translate post content (use 'raw' content to prevent filter recursion).
     *
     * @param \WP_Post|null $post
     * @return string
     */
    public function translate_post_content($post = null): string
    {
        // Get post ID and content
        $post_id = $post ? $post->ID : get_the_ID();
        if (!$post_id) {
            return ''; // No post ID found
        }
        $content = get_post_field('post_content', $post_id, 'raw');
        if ($content === null) {
            $content = ''; // Ensure it's a string
        }

        // Check if translation is needed
        if (!$this->needs_translation()) {
            return $content;
        }

        $target_language = $this->get_current_language();
        // Use 'post_' + ID as identifier for post content memory cache
        $cache_identifier = 'post_' . $post_id;
        // MEMORY CACHE VERWIJDERD

        // Call translate_template_part (which uses translate_text with disk cache)
        $translated = $this->translate_template_part((string)$content, 'post_content');

        return $translated;

        return $translated;
    }

    /**
     * Translate site title.
     *
     * @return string
     */
    public function translate_site_title(): string
    {
        return $this->translate_template_part((string)get_bloginfo('name'), 'site_title');
    }

    /**
     * Translate tagline.
     *
     * @return string
     */
    public function translate_tagline(): string
    {
        return $this->translate_template_part((string)get_bloginfo('description'), 'tagline');
    }

    /**
     * Translate terms.
     *
     * @param \WP_Term[] $terms
     * @return \WP_Term[]
     */
    public function translate_terms(array $terms): array
    {
        if (empty($terms)) {
            return [];
        }
        foreach ($terms as $term) {
            if (isset($term->name)) {
                $term->name = $this->translate_text((string)$term->name, $this->default_language, $this->get_current_language(), true, false);
            }
        }
        return $terms;
    }



    /**
     * Clear alleen de file cache.
     */
    public function clear_translation_cache(): void
    {
        $files = glob($this->cache_dir . '/*.cache');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    wp_delete_file($file);
                }
            }
        }
    }


    /**
     * Clear transient cache entries using delete_transient.
     */
    public function clear_transient_cache(): void
    {
        global $wpdb;
        $table_name = $wpdb->options;

        // Get transients to delete
        $cache_key = 'ai_translate_transients_to_delete';
        $transients_to_delete = wp_cache_get($cache_key);

        if (false === $transients_to_delete) {
            // Get transients to delete - include batch transients
            // Use WordPress functions for safer database access
            $transient_patterns = [
                '_transient_ai_translate_trans_%',
                '_transient_timeout_ai_translate_trans_%',
                '_transient_ai_translate_slug_%',
                '_transient_timeout_ai_translate_slug_%',
                '_transient_ai_translate_slug_cache_%',
                '_transient_timeout_ai_translate_slug_cache_%',
                '_transient_ai_translate_batch_trans_%',
                '_transient_timeout_ai_translate_batch_trans_%'
            ];

            $transients_to_delete = [];
            foreach ($transient_patterns as $pattern) {
                $results = $wpdb->get_col($wpdb->prepare(
                    "SELECT option_name FROM " . $wpdb->prefix . "options WHERE option_name LIKE %s",
                    $pattern
                ));
                $transients_to_delete = array_merge($transients_to_delete, $results);
            }
            wp_cache_set($cache_key, $transients_to_delete, '', 60); // Cache for 60 seconds
        }

        // Delete the transients using delete_option which handles caching
        foreach ($transients_to_delete as $transient_name) {
            delete_option($transient_name);
        }

        // Invalidate the cache after deleting transients
        wp_cache_delete($cache_key);

        if ($transients_to_delete) {
            foreach ($transients_to_delete as $transient_name) {
                // Use delete_option to clear the transient
                // Remove the _transient_ or _transient_timeout_ prefix
                $option_name = str_replace('_transient_', '', $transient_name);
                $option_name = str_replace('_transient_timeout_', '', $option_name);
                delete_option($option_name);
            }
        }
        // Also delete the API error/backoff transients
        delete_transient(self::API_ERROR_COUNT_TRANSIENT);
        delete_transient(self::API_BACKOFF_TRANSIENT);
    }

    /**
     * Clear the custom slug translation cache table.
     */
    public function clear_slug_cache_table(): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ai_translate_slugs';
        // Use WordPress delete function for safer database access
        $wpdb->delete($table_name, [], []); // Delete all records safely
    }



    /**
     * Clear zowel file cache als transients en de slug cache tabel.
     */
    public function clear_all_cache(): void
    {
        $this->clear_translation_cache();
        $this->clear_transient_cache();
        $this->clear_slug_cache_table();
        // Verwijderd: $this->clear_menu_cache(); // URL's moeten stabiel blijven
    }

    /**
     * Clear all cache except slug cache (for admin cache management)
     */
    public function clear_all_cache_except_slugs(): void
    {
        $this->clear_translation_cache();
        $this->clear_transient_cache();
        $this->clear_menu_cache(); // Menu cache mag wel geleegd worden
        // Verwijderd: $this->clear_slug_cache_table(); // Slug cache mag NOOIT leeggemaakt worden
    }

    /**
     * Clear memory and transients except slug cache (for admin cache management)
     */
    public function clear_memory_and_transients_except_slugs(): void
    {
        global $wpdb;

        // Verwijder alle relevante transients, maar NIET slug cache
        // Use WordPress functions for safer transient deletion
        $transient_patterns = [
            '_transient_ai_translate_%',
            '_transient_timeout_ai_translate_%',
            '_transient_ai_translate_batch_trans_%',
            '_transient_timeout_ai_translate_batch_trans_%',
            '_transient_ai_translate_trans_%',
            '_transient_timeout_ai_translate_trans_%'
        ];

        foreach ($transient_patterns as $pattern) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ));
        }

        // Clear API error/backoff transients
        delete_transient(self::API_ERROR_COUNT_TRANSIENT);
        delete_transient(self::API_BACKOFF_TRANSIENT);
    }


    /**
     * Geeft de ingestelde detecteerbare talen terug.
     * @return array
     */
    public function get_detectable_languages(): array
    {
        $settings = $this->get_settings();
        return isset($settings['detectable_languages']) && is_array($settings['detectable_languages'])
            ? $settings['detectable_languages']
            : [];
    }

    /**
     * Set current language.
     */
    public function set_current_language(string $lang): void
    {
        $this->current_language = $lang;
    }

    /**
     * Get the currently active language code.
     *
     * @return string Current language code.
     */
    public function get_current_language(): string
    {
        // Gebruik caching voor performance
        if ($this->current_language !== null) {
            return $this->current_language;
        }

        // Als we in de admin zijn, retourneer direct de default taal.
        // De admin-omgeving wordt niet vertaald door deze plugin.
        if (is_admin()) {
            return $this->settings['default_language'] ?? 'nl';
        }

        $default_language = $this->settings['default_language'] ?? 'nl';
        $all_languages = array_keys($this->get_available_languages());

        // 1. Check URL path voor taalcode (hoogste prioriteit)
        if (isset($_SERVER['REQUEST_URI'])) {
            $request_path = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH);
            $request_path = sanitize_text_field($request_path);
            // Normaliseer: als leeg of alleen slashes, maak er één enkele slash van
            if (!is_string($request_path) || trim($request_path, '/') === '') {
                $request_path = '/';
            } else {
                // Vervang meerdere slashes door één enkele
                $request_path = preg_replace('#/+#', '/', $request_path);
            }
            if (is_string($request_path) && preg_match('#^/([a-z]{2,3}(?:-[a-z]{2,4})?)(/|$)#i', $request_path, $matches)) {
                $lang_from_path = strtolower($matches[1]);
                if (in_array($lang_from_path, $all_languages, true)) {
                    $this->current_language = $lang_from_path;
                    // Set cookie to match URL language
                    setcookie('ai_translate_lang', $lang_from_path, time() + (DAY_IN_SECONDS * 30), COOKIEPATH, COOKIE_DOMAIN);
                    return $this->current_language;
                }
            }
        }

        // 2. Check for switcher parameter on root URL (override cookie)
        // This handles the case where the user explicitly clicks the default language on the homepage.
        if (isset($_GET['from_switcher']) && $_GET['from_switcher'] === '1') {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (empty($nonce) || !wp_verify_nonce($nonce, 'ai_translate_switcher')) {
                $lang = $this->current_language ?? ($this->settings['default_language'] ?? 'nl');
                return (string)$lang;
            }
            $request_path = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), PHP_URL_PATH);
            // Normalize path: if empty or only slashes, make it a single slash
            if (!is_string($request_path) || trim($request_path, '/') === '') {
                $request_path = '/';
            } else {
                // Replace multiple slashes with a single one
                $request_path = preg_replace('#/+#', '/', $request_path);
            }

            // If on the root URL and from switcher, force default language
            if ($request_path === '/') {
                $this->current_language = $default_language;
                // Optionally, clear the cookie here if forcing default language from switcher is the intent
                // setcookie('ai_translate_lang', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN);
                return $this->current_language;
            }
        }

        // 3. Check cookie (tweede prioriteit na URL en switcher override)
        if (isset($_COOKIE['ai_translate_lang'])) {
            $cookie_lang = sanitize_text_field(wp_unslash($_COOKIE['ai_translate_lang']));
            if (in_array($cookie_lang, $all_languages, true)) {
                $this->current_language = $cookie_lang;
                return $this->current_language;
            }
        }

        // 4. Detecteer browsertaal als geen taal in URL/cookie en detecteerbare talen zijn ingesteld
        $detectable_languages = $this->get_detectable_languages();
        if (!is_admin() && !wp_doing_ajax() && !empty($detectable_languages)) {
            $browser_langs = [];
            if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                $accept = strtolower(sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT_LANGUAGE'])));
                foreach (explode(',', $accept) as $lang) {
                    $lang = explode(';', $lang)[0];
                    $lang = substr($lang, 0, 2);
                    if ($lang && ! in_array($lang, $browser_langs)) {
                        $browser_langs[] = $lang;
                    }
                }
            }
            foreach ($browser_langs as $lang) {
                if (
                    in_array($lang, $detectable_languages, true) &&
                    $lang !== $default_language
                ) {
                    $this->current_language = $lang;
                    return $this->current_language;
                }
            }
        }

        // 5. Als URL geen taal bevat en we zijn niet in admin, gebruik default taal
        // Deze stap wordt nu bereikt als geen van de bovenstaande checks een taal heeft bepaald.
        if (!is_admin() && !wp_doing_ajax()) {
            $this->current_language = $default_language;
            return $this->current_language;
        }

        // 6. Fallback: gebruik default taal (voor alle overige gevallen, bijv. AJAX requests zonder taal in URL/cookie)
        $this->current_language = $default_language;
        return $this->current_language;
    }

    /**
     * Check of vertaling nodig is (wanneer de huidige taal afwijkt van de default).
     *
     * @return bool
     */
    public function needs_translation(): bool
    {
        $current = $this->get_current_language();
        return $current !== $this->default_language && !empty($current);
    }

    /**
     * Hook voor het weergeven van de taalvlaggen.
     */
    public function hook_display_language_switcher(): void
    {
        $this->display_language_switcher();
    }

    /**
     * Display language switcher in de footer.
     */
    public function display_language_switcher(): void
    {
        if (empty($this->settings['enabled_languages']) || count($this->settings['enabled_languages']) < 2) {
            // Don't show switcher if no languages enabled or only one
            return;
        }
        $current_lang = $this->get_current_language();
        $languages = $this->get_available_languages();
        $enabled_languages = $this->settings['enabled_languages'];

        // Haal de post_id van de huidige pagina op, indien van toepassing
        $current_post_id = get_the_ID(); // Haal altijd de ID op, kan false zijn
        if (!$current_post_id) {
            $current_post_id = null; // Zorg dat het null is als er geen geldige ID is
        }

        echo '<div class="ai-translate-switcher">';
        if (isset($languages[$current_lang])) {
            printf(
                // Geen inline onclick meer, alleen HTML
                '<button class="current-lang" title="Choose language">%s %s <span class="arrow">&#9662;</span></button>',
                // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Flags are directly linked assets, not attachments.
                sprintf('<img src="%s" alt="%s" width="20" height="15" />', esc_url(plugins_url("assets/flags/{$current_lang}.png", AI_TRANSLATE_FILE)), \esc_attr($languages[$current_lang])),
                // phpcs:enable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
                esc_html($languages[$current_lang])
            );
        }
        echo '<div class="language-popup" id="languagePopup">';
        echo '<div class="language-list">';
        foreach ($enabled_languages as $lang_code) {
            if (!isset($languages[$lang_code])) {
                continue;
            }
            $lang_name = $languages[$lang_code];
            $is_current = $lang_code === $current_lang;

            // Gebruik de permalink van de huidige pagina als basis-URL, indien beschikbaar
            $base_url = home_url(''); // Standaard homepage
            if ($current_post_id) {
                $base_url = get_permalink($current_post_id);
            }

            $url = $this->translate_url($base_url, $lang_code, $current_post_id); // Geef post_id mee

            printf(
                '<a href="%s" class="lang-option %s" data-lang="%s">%s %s</a>',
                esc_url($url),
                $is_current ? 'active' : '',
                esc_attr($lang_code),
                // phpcs:disable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage -- Flags are directly linked assets, not attachments.
                sprintf('<img src="%s" alt="%s" width="20" height="15" />', esc_url(plugins_url("assets/flags/{$lang_code}.png", AI_TRANSLATE_FILE)), \esc_attr($lang_name)),
                // phpcs:enable PluginCheck.CodeAnalysis.ImageFunctions.NonEnqueuedImage
                esc_html($lang_name)
            );
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Translate navigation menu.
     *
     * @param string $items
     * @param object $args
     * @return string
     */
    public function translate_navigation(string $items, object $args): string
    {
        if (!$this->needs_translation()) {
            return $items;
        }
        $source_lang = $this->default_language;
        $target_lang = $this->get_current_language();
        $translated = $this->translate_text($items, $source_lang, $target_lang, false, false);
        return $this->clean_html_string($translated); // Apply cleaning after translation
    }
    /**
     * Wis alle cachebestanden voor een specifieke taal.
     *
     * @param string $lang_code Taalcode (bijv. 'en', 'de'). Moet gevalideerd zijn.
     * @return int Aantal verwijderde bestanden
     */    public function clear_cache_for_language(string $lang_code): int
    {
        // No language code validation needed; codes are always from internal logic
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        if (!is_dir($this->cache_dir) || !$wp_filesystem->is_writable($this->cache_dir)) {
            $error = "Cache directory bestaat niet of is niet schrijfbaar: {$this->cache_dir}";
            throw new \RuntimeException(esc_html($error));
        }

        $count = 0;
        $pattern = $this->cache_dir . '/' . $lang_code . '_*.cache';
        $files = glob($pattern);

        if ($files === false) {
            $error = "Fout bij het zoeken naar cachebestanden met patroon: $pattern";
            throw new \RuntimeException(esc_html($error));
        }

        foreach ($files as $file) {
            // Extra check of het echt een bestand is en de naam begint met de taalcode + underscore
            if (is_file($file) && strpos(basename($file), $lang_code . '_') === 0) {
                if (wp_delete_file($file)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Batch translate an array of items (e.g. menu items, form field labels).
     *
     * This function MUST be used for all cases where multiple short strings need to be translated at once,
     * such as menu items and FluentForm field names. The cache is always stored as a JSON array, never as individual strings.
     *
     * - Do NOT call this for single strings; use translate_text or translate_template_part for that.
     * - The input array should be numerically indexed for best results.
     * - The output is always an array with the same keys as the input.
     * - Persistent cache (disk/transient) is always a JSON array, never a single string.
     *
     * @param array $items Array of strings to translate (numeric or associative keys)
     * @param string $source_language Source language code
     * @param string $target_language Target language code
     * @param string $type Type of content (e.g. 'menu_item', 'fluentform_field', 'post_title')
     * @return array Array of translated strings, same keys as input
     */
    public function batch_translate_items(array $items, string $source_language, string $target_language, string $type, array $original_items_for_cache = []): array
    {
        // Guard: niet vertalen in admin of als doeltaal de standaardtaal is of lege input
        if (is_admin() || $target_language === $this->default_language || empty($items)) {
            return $items;
        }

        // Validate input array structure
        if (!is_array($items) || count($items) === 0) {
            return $items;
        }

        // Ensure all items are strings
        $valid_items = [];
        foreach ($items as $key => $item) {
            if (is_string($item) && !empty(trim($item))) {
                $valid_items[$key] = trim($item);
            } else {
                // Keep original item if it's not a valid string
                $valid_items[$key] = is_string($item) ? $item : (string)$item;
            }
        }

        if (empty($valid_items)) {
            return $items;
        }

        // Ensure we have at least one valid item to translate
        $has_valid_items = false;
        foreach ($valid_items as $item) {
            if (!empty(trim($item))) {
                $has_valid_items = true;
                break;
            }
        }

        if (!$has_valid_items) {
            return $items;
        }

        // --- Start Batch Processing ---
        $original_keys = array_keys($valid_items); // Bewaar originele keys
        $items_to_translate = array_values($valid_items); // Werk met numerieke array voor API

        // Check of alle items alleen uit echt uitgesloten shortcodes bestaan
        $truly_excluded_shortcodes = self::get_truly_excluded_shortcodes();
        $all_items_excluded = true;
        foreach ($items_to_translate as $item) {
            if (!$this->is_only_excluded_shortcodes($item, $truly_excluded_shortcodes)) {
                $all_items_excluded = false;
                break;
            }
        }

        // Gebruik hash van items + type + target_language als identifier
        // Sort items to ensure consistent cache keys regardless of input order
        // For menu items, use original items for cache key generation if provided
        if ($type === 'menu_item' && !empty($original_items_for_cache)) {
            $sorted_items = $original_items_for_cache;
            sort($sorted_items);
        } else {
            $sorted_items = $items_to_translate;
            sort($sorted_items);
        }
        $cache_string = json_encode($sorted_items) . $type . $source_language . $target_language;
        $cache_identifier = md5($cache_string);
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'batch_mem');

        // MEMORY CACHE VERWIJDERD

        // SHORTCODE-ONLY: alleen in memory cache!
        if ($all_items_excluded) {
            return array_combine($original_keys, $items_to_translate);
        }

        // Voor menu items: gebruik globale UI cache voor individuele items
        if ($type === 'menu_item') {
            $translated_items = [];
            $all_cached = true;

            foreach ($items_to_translate as $index => $item) {
                $global_cached = $this->get_global_ui_element($item, $target_language);
                if ($global_cached !== null) {
                    $translated_items[$index] = $global_cached;
                } else {
                    $all_cached = false;
                    break; // Stop als één item niet gecached is
                }
            }

            // Als alle items gecached zijn, return ze
            if ($all_cached) {
                return array_combine($original_keys, $translated_items);
            }
        }

        // Transient Cache Check (alleen als niet alle items uitgesloten zijn)
        if (!$all_items_excluded) {
            $transient_key = $this->generate_cache_key($cache_identifier, $target_language, 'batch_trans');
            $cached = get_transient($transient_key);
            
            // Moet een numerieke array zijn met correct aantal items
            if ($cached !== false && is_array($cached) && count($cached) === count($items_to_translate) && array_keys($cached) === range(0, count($cached) - 1)) {
                
                // Voor FluentForm: vervang placeholders terug naar echte nonce waarden
                if ($type === 'fluentform') {
                    foreach ($cached as $index => $text) {
                        // Vervang __AITRANSLATE_FF_NONCE__ placeholder terug naar echte nonce waarde
                        $cached[$index] = str_replace('value="__AITRANSLATE_FF_NONCE__"', 'value="' . wp_create_nonce('fluentform') . '"', $text);
                        $cached[$index] = str_replace("value='__AITRANSLATE_FF_NONCE__'", "value='" . wp_create_nonce('fluentform') . "'", $text);
                    }
                }
                
                // If it comes from transient, also save it to disk cache only if content changed
                // Voor FluentForm: skip disk cache
                if ($type !== 'fluentform') {
                    $disk_cache_key = $this->generate_cache_key($cache_identifier, $target_language, 'disk');
                    $existing_cache = $this->get_cached_content($disk_cache_key);
                    if ($existing_cache === false || $existing_cache !== json_encode($cached)) {
                        $this->save_to_cache($disk_cache_key, json_encode($cached));
                    }
                }
                return array_combine($original_keys, $cached); // Combineer met originele keys
            }
        }

        // Disk Cache Check (alleen als niet alle items uitgesloten zijn)
        // Voor FluentForm: skip disk cache om unieke cache files te voorkomen
        if (!$all_items_excluded && $type !== 'fluentform') {
            $disk_cache_key = $this->generate_cache_key($cache_identifier, $target_language, 'disk');
            $disk_cached = $this->get_cached_content($disk_cache_key);
            if ($disk_cached !== false) {
                $decoded = json_decode($disk_cached, true);
                if (is_array($decoded) && count($decoded) === count($items_to_translate) && array_keys($decoded) === range(0, count($decoded) - 1)) {
                    // MEMORY CACHE VERWIJDERD
                    // Also save to transient for faster access on subsequent requests
                    $transient_key = $this->generate_cache_key($cache_identifier, $target_language, 'batch_trans');
                    set_transient($transient_key, $decoded, $this->expiration_hours * 3600);
                    return array_combine($original_keys, $decoded); // Combineer met originele keys
                }
            }
        }


        // --- Cache miss ---
        // Log alleen bij cache miss

        // Skip API call if all items are excluded
        if ($all_items_excluded) {
            return array_combine($original_keys, $items_to_translate);
        }
        
        // Voor FluentForm: skip API call als source en target language hetzelfde zijn
        if ($type === 'fluentform' && $source_language === $target_language) {
            return array_combine($original_keys, $items_to_translate);
        }

        // Use the same optimized prompt builder for consistency
        $is_title_batch = in_array($type, ['post_title', 'menu_item', 'widget_title'], true);

        // For batch translation, use the longest text to determine if context should be included
        $longest_text = '';
        $is_menu_batch = ($type === 'menu_item');
        foreach ($items_to_translate as $item) {
            if (strlen($item) > strlen($longest_text)) {
                $longest_text = $item;
            }
        }

        // For menu items, always use the first item to determine if it's a short menu item
        if ($is_menu_batch && !empty($items_to_translate)) {
            $first_item = $items_to_translate[0];
            $system_prompt = $this->build_translation_prompt($source_language, $target_language, $is_title_batch, $first_item);
        } else {
            $system_prompt = $this->build_translation_prompt($source_language, $target_language, $is_title_batch, $longest_text);
        }

        $data = [
            'model' => $this->settings['selected_model'],
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => json_encode(['items' => $items_to_translate], JSON_UNESCAPED_UNICODE)]
            ],
            'temperature' => 0.2,
            'max_tokens' => 3000,
            'frequency_penalty' => 0,
            'presence_penalty' => 0
        ];

        $max_attempts = 2;

        for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
            try {
                $response = $this->make_api_request('chat/completions', $data);
                $content = $response['choices'][0]['message']['content'] ?? '';

                $decoded = json_decode($content, true);
                $translated_array = null; // Moet numerieke array zijn

                // Verbeterde JSON parsing - verwacht 'items' key met numerieke array
                if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items']) && count($decoded['items']) === count($items_to_translate)) {
                    if (array_keys($decoded['items']) === range(0, count($decoded['items']) - 1)) {
                        $translated_array = $decoded['items'];
                    } else {
                        $translated_array = array_values($decoded['items']);
                        if (count($translated_array) !== count($items_to_translate)) {
                            $translated_array = null;
                        }
                    }
                } elseif (is_array($decoded) && count($decoded) === count($items_to_translate)) {
                    if (array_keys($decoded) === range(0, count($decoded) - 1)) {
                        $translated_array = $decoded;
                    } else {
                        $translated_array = array_values($decoded);
                        if (count($translated_array) !== count($items_to_translate)) {
                            $translated_array = null;
                        }
                    }
                } else {
                    if (preg_match('/\[\s*(".*?"\s*,\s*)*".*?"\s*\]/s', $content, $matches)) {
                        $try_decode = json_decode($matches[0], true);
                        if (is_array($try_decode) && count($try_decode) === count($items_to_translate) && array_keys($try_decode) === range(0, count($try_decode) - 1)) {
                            $translated_array = $try_decode;
                        }
                    }
                }

                if ($translated_array !== null && count($translated_array) === count($items_to_translate)) {
                    // Validate and clean each translated item
                    foreach ($translated_array as $index => $value) {
                        if (!is_string($value)) {
                            // If translation is not a string, use original item
                            $translated_array[$index] = $items_to_translate[$index] ?? '';
                        } else {
                            // No length restrictions for translations - we'll handle display shortening later
                            // The full translation is preserved for use in title attributes
                        }
                    }

                    // Final validation: ensure we have exactly the same number of items
                    if (count($translated_array) !== count($items_to_translate)) {
                        // If count doesn't match, return original items
                        return $valid_items;
                    }

                    // Voor FluentForm: vervang placeholders terug naar echte nonce waarden
                    if ($type === 'fluentform') {
                        foreach ($translated_array as $index => $text) {
                            // Vervang __AITRANSLATE_FF_NONCE__ placeholder terug naar echte nonce waarde
                            $translated_array[$index] = str_replace('value="__AITRANSLATE_FF_NONCE__"', 'value="' . wp_create_nonce('fluentform') . '"', $text);
                            $translated_array[$index] = str_replace("value='__AITRANSLATE_FF_NONCE__'", "value='" . wp_create_nonce('fluentform') . "'", $text);
                        }
                    }
                    
                    // Alleen transient en disk cache maken als niet alle items uitgesloten zijn
                    if (!$all_items_excluded) {
                        // Voor menu items: cache individuele items in globale UI cache
                        if ($type === 'menu_item') {
                            foreach ($translated_array as $index => $translated_item) {
                                $original_item = $items_to_translate[$index];
                                $this->cache_global_ui_element($original_item, $target_language, $translated_item);
                            }
                        } else {
                            // Voor andere types: gebruik normale batch cache
                            set_transient($transient_key, $translated_array, $this->expiration_hours * 3600);
                            // Also save to disk cache for persistence only if content changed
                            // Voor FluentForm: skip disk cache
                            if ($type !== 'fluentform') {
                                $disk_cache_key = $this->generate_cache_key($cache_identifier, $target_language, 'disk');
                                $existing_cache = $this->get_cached_content($disk_cache_key);
                                if ($existing_cache === false || $existing_cache !== json_encode($translated_array)) {
                                    $this->save_to_cache($disk_cache_key, json_encode($translated_array));
                                }
                            }
                        }
                    }


                    return array_combine($original_keys, $translated_array);
                }

                $fail_reason = "Invalid JSON or item count mismatch";
                if (is_array($decoded)) $fail_reason = "Item count mismatch (expected " . count($items_to_translate) . ", got " . count($decoded['items'] ?? $decoded) . ")";
                elseif ($decoded === null) $fail_reason = "Invalid JSON response";
                // Log full API response on error
                if ($attempt === 1) {
                    $data['messages'][0]['content'] = sprintf(
                        'You are a translation engine. Translate the following text from %s to %s. ' .
                            'Preserve HTML structure, placeholders like __AITRANSLATE_X__, and line breaks. ' .
                            'Return ONLY the translated text, without any additional explanations or markdown formatting. ' .
                            'Even if the input seems partially translated, provide the full translation in the target language %s. ' .
                            'If the input text is empty or cannot be translated meaningfully, return the original input text unchanged.',
                        ucfirst($source_language),
                        ucfirst($target_language),
                        count($items_to_translate)
                    );
                }
            } catch (\Exception $e) {
                break;
            }
        }

        return $valid_items;
    }

    /**
     * Verwijdert verlopen cache bestanden.
     * 
     * @return int Aantal verwijderde bestanden
     */
    public function cleanup_expired_cache(): int
    {
        $count = 0;
        $files = glob($this->cache_dir . '/*.cache');

        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file) && $this->is_cache_expired($file, null)) {
                    if (wp_delete_file($file)) {
                        $count++;
                    }
                }
            }
        }

        if ($count > 0) {
        }

        return $count;
    }

    /**
     * Verwijder ALLE shortcodes (ook niet-geregistreerde) uit de tekst en vervang door een vaste placeholder.
     * Dit voorkomt dat dynamische shortcodes de cache-key beïnvloeden.
     *
     * @param string $text
     * @return string
     */
    private function strip_all_shortcodes_for_cache(string $text): string
    {
        // Vervang alle shortcodes (ook niet-geregistreerde) door [SHORTCODE]
        $pattern = '/\[(\[?)([a-zA-Z0-9_\-]+)([^\]]*?)(?:((?:\\/(?!\\]))?\\])|\\](?:([^[\\[]*+(?:\\[(?!\\/\\])[^\\[]*+)*+)\\[\\/\\2\\])?)(\\]?)/s';
        return preg_replace($pattern, '[SHORTCODE]', $text);
    }



    /**
     * Removes the translation marker from a string.
     * Useful for cleaning up content before it's used in meta tags etc.
     *
     * @param string $text The text potentially containing the marker.
     * @return string The text without the marker.
     */
    public static function remove_translation_marker($text)
    {
        if (is_string($text) && strpos($text, self::TRANSLATION_MARKER) !== false) {
            $text = str_replace(self::TRANSLATION_MARKER, '', $text);
        }
        return $text;
    }

    /**
     * Cleans an HTML string by decoding entities, removing tags, and stripping the translation marker.
     * This method is designed to be used on final output strings before display.
     *
     * @param string $html_string The HTML string to clean.
     * @return string The cleaned HTML string.
     */
    public function clean_html_string(string $html_string): string
    {
        // 1. Decode HTML entities (e.g., <p> to <p>)
        $cleaned_string = html_entity_decode($html_string, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Remove problematic data attributes that confuse the LLM
        $cleaned_string = preg_replace('/\s*data-(start|end)=["\'][^"\']*["\']/', '', $cleaned_string);
        $cleaned_string = preg_replace('#/data-(start|end)/"?\d+"?#', '', $cleaned_string);
        $cleaned_string = preg_replace('#data-(start|end)/"?\d+"?#', '', $cleaned_string);

        // 3. Remove the translation marker and other placeholders
        $cleaned_string = str_replace(self::TRANSLATION_MARKER, '', $cleaned_string);
        $cleaned_string = preg_replace('/__AITRANSLATE[^_]*?[^_]*?__/', '', $cleaned_string);
        $cleaned_string = preg_replace('/__AITRANSLATE_SC_[0-9]+__/', '', $cleaned_string);

        // 4. Use wp_kses_post for safety and to re-encode valid HTML entities
        // This ensures proper HTML structure and prevents XSS, and re-encodes if necessary
        $cleaned_string = wp_kses_post($cleaned_string);


        return $cleaned_string;
    }


    /**
     * Extracts shortcode pairs from text and replaces them with placeholders.
     * This prevents shortcodes from being translated while preserving their structure.
     *
     * @param string $text The text containing shortcodes to extract
     * @param array<string> $shortcodes_to_exclude Array of shortcode names to exclude from translation
     * @param array<string, string> $extracted_shortcodes Reference to array that will store extracted shortcodes (placeholder => original_shortcode)
     * @return string Text with shortcodes replaced by placeholders
     */
    private function extract_shortcode_pairs(string $text, array $shortcodes_to_exclude, array &$extracted_shortcodes): string
    {


        $placeholder_index = 0;
        foreach ($shortcodes_to_exclude as $tagname) {
            $pattern = '/\\[(' . preg_quote($tagname, '/') . ')(?![a-zA-Z0-9_\-])([^\]]*?)(?:(\/\\])|\\](?:([^\[]*+(?:\\[(?!\/\\1\\])[^\[]*+)*+)\\[\/\\1\\]))?\\]/s';
            $text = preg_replace_callback(
                $pattern,
                function ($matches) use (&$extracted_shortcodes, &$placeholder_index) {
                    $placeholder = '__AITRANSLATE_SC_' . $placeholder_index . '__';
                    $extracted_shortcodes[$placeholder] = $matches[0];



                    $placeholder_index++;
                    return $placeholder;
                },
                $text
            );
        }



        return $text;
    }

    /**
     * Restores shortcode pairs from placeholders in the translated text.
     *
     * @param string $text The translated text with shortcode placeholders.
     * @param array<string, string> $extracted_shortcodes An array of extracted shortcodes (placeholder => original_shortcode).
     * @return string The text with original shortcodes restored.
     */
    private function restore_shortcode_pairs(string $text, array $extracted_shortcodes): string
    {


        if (empty($extracted_shortcodes)) {
            return $text;
        }

        uksort($extracted_shortcodes, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });
        foreach ($extracted_shortcodes as $placeholder => $original_shortcode) {
            $escaped_placeholder = preg_quote($placeholder, '/');
            $text = preg_replace('/' . $escaped_placeholder . '/s', $original_shortcode, $text);


        }



        return $text;
    }

    /**
     * Special handler for the 'get_bloginfo' filter.
     * Only removes the marker if the 'show' parameter is 'description'.
     *
     * @param mixed  $output The current bloginfo value.
     * @param string $show   The type of bloginfo requested.
     * @return mixed The potentially cleaned bloginfo value.
     */
    public static function remove_marker_from_bloginfo($output, $show)
    {
        if ($show === 'description' && is_string($output)) {
            return self::remove_translation_marker($output);
        }
        return $output;
    }

    /**
     * Filter callback voor Jetpack's Open Graph tags.
     * Verwijdert de marker uit og:description, og:title en og:site_name.
     * Vertaalt og:description als de marker ontbreekt.
     * Statische methode zodat deze makkelijk vanuit filters aangeroepen kan worden.
     *
     * @param array $tags Array van Open Graph tags.
     * @return array Aangepaste array van tags.
     */
    public static function remove_marker_from_jetpack_og_tags($tags)
    {
        // Haal de core instance op (nodig voor vertaling)
        $core = self::get_instance(); // Gebruik self::get_instance() in static method

        // Behandel og:description
        if (isset($tags['og:description']) && is_string($tags['og:description'])) {
            // Controleer of de marker al aanwezig is
            if (strpos($tags['og:description'], self::TRANSLATION_MARKER) !== false) {
                // Marker aanwezig: alleen verwijderen
                $tags['og:description'] = self::remove_translation_marker($tags['og:description']);
            } elseif ($core->needs_translation()) {
                // Marker NIET aanwezig EN vertaling nodig: vertaal de tekst
                $default_lang = $core->default_language;
                $current_lang = $core->get_current_language();

                // Vertaal de bestaande (onvertaalde) og:description
                // Gebruik disk cache
                $translated_description = $core->translate_text(
                    $tags['og:description'],
                    $default_lang,
                    $current_lang,
                    false, // $is_title = false
                    true   // $use_disk_cache = true
                );

                // Verwijder de marker die door translate_text is toegevoegd
                $tags['og:description'] = self::remove_translation_marker($translated_description);
            }
            // Als geen vertaling nodig is en marker niet aanwezig, doe niets (originele tekst blijft)
        }

        // Behandel og:title (alleen marker verwijderen)
        if (isset($tags['og:title']) && is_string($tags['og:title'])) {
            $tags['og:title'] = self::remove_translation_marker($tags['og:title']);
        }

        // Behandel og:site_name (alleen marker verwijderen)
        if (isset($tags['og:site_name']) && is_string($tags['og:site_name'])) {
            $tags['og:site_name'] = self::remove_translation_marker($tags['og:site_name']);
        }

        return $tags;
    }

    /**
     * Voegt de vertaalde meta description tag toe aan de <head>.
     * Gebruikt een specifieke instelling voor de homepage.
     * Draait via de wp_head action hook. Simpele versie.
     * Haalt 'raw' data op om filterconflicten te vermijden.
     * Slaat over in admin en als de huidige taal de default taal is.
     * Kort de beschrijving in tot max 200 karakters.
     * Minimale logging.
     */
    public function add_simple_meta_description(): void
    {
        // Niet uitvoeren in admin
        if (is_admin()) {
            return;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->default_language;
        $needs_translation = $this->needs_translation(); // Check only once

        $description = '';

        // --- Check for Homepage ---
        $is_homepage = (is_front_page() || is_home());
        if ($is_homepage) {
            $homepage_desc_setting = $this->settings['homepage_meta_description'] ?? '';
            if (!empty($homepage_desc_setting)) {
                $description = $homepage_desc_setting;
            }
        }
        // --- End Check for Homepage ---

        // --- Non-homepage or empty homepage setting ---
        if (empty($description)) {
            // Gebruik excerpt voor enkele posts/pagina's (maar niet als we al homepage desc hadden)
            if (is_singular() && !$is_homepage) {
                global $post;
                if ($post) {
                    $manual_excerpt = get_post_field('post_excerpt', $post->ID, 'raw');
                    if (!empty($manual_excerpt)) {
                        $description = (string) $manual_excerpt;
                    } else {
                        $content = get_post_field('post_content', $post->ID, 'raw');
                        $description = wp_trim_words(wp_strip_all_tags(strip_shortcodes($content)), 55, '');
                    }
                }
            }

            // Fallback naar site tagline (direct uit opties) als nog steeds leeg
            if (empty($description)) {
                $description = (string) get_option('blogdescription', '');
            }
        }
        // --- End Fallback ---


        if (!empty($description)) {
            $processed_description = $description;

            // Vertaal indien nodig
            if ($needs_translation) {
                $processed_description = $this->translate_text(
                    $description,
                    $default_lang,
                    $current_lang,
                    false, // $is_title = false
                    true   // $use_disk_cache = true for descriptions
                );
            }

            // Verwijder ALTIJD de marker
            $clean_description = self::remove_translation_marker($processed_description);

            // Inkorten tot max 200 karakters
            $max_length = 200;
            $suffix = '...';
            $final_description = $clean_description;

            if (mb_strlen($final_description) > $max_length) {
                $truncated = mb_substr($final_description, 0, $max_length);
                $last_space = mb_strrpos($truncated, ' ');
                if ($last_space !== false) {
                    $final_description = mb_substr($truncated, 0, $last_space) . $suffix;
                } else {
                    $final_description = mb_substr($final_description, 0, $max_length - mb_strlen($suffix)) . $suffix;
                }
            }

            // Print de meta tag
            $trimmed_description = trim($final_description);
            if (!empty($trimmed_description)) {
                echo '<meta name="description" content="' . esc_attr($trimmed_description) . '">' . "\n";
            }
        }
    }
    /**
     * Adds alternate hreflang links to the <head> section for enabled languages.
     * This helps search engines understand the language and regional targeting of your pages.
     */
    public function add_alternate_hreflang_links(): void
    {
        // Do not execute in admin area
        if (is_admin()) {
            return;
        }

        $enabled_languages = $this->settings['enabled_languages'] ?? [];
        $detectable_languages = $this->get_detectable_languages();
        // Combineer enabled en detectable talen, verwijder duplicaten en zorg voor unieke waarden
        $all_hreflang_languages = array_unique(array_merge($enabled_languages, $detectable_languages));

        $current_lang = $this->get_current_language();
        $default_lang = $this->default_language;

        // Get the current page URL. Use home_url() to ensure it's a full URL.
        // add_query_arg(null, null) preserves current query parameters.
        $current_post_id = get_the_ID();
        if (!$current_post_id) {
            $current_post_id = null; // Ensure it's null if no valid ID
        }

        $original_page_url_in_default_lang = home_url('/'); // Initialiseer met basis URL
        $default_lang_url = ''; // Initialize variable to store the default language URL

        // Als het de homepage is, moet de URL altijd de basis URL zijn.
        if (is_front_page() || is_home()) {
            $original_page_url_in_default_lang = home_url('/');
        } elseif ($current_post_id !== null) {
            // Als er een post ID is en het is geen homepage, probeer de originele permalink te bepalen
            $post = get_post($current_post_id);
            if ($post) {
                // Haal de originele slug op via reverse translation
                $original_slug_data = $this->reverse_translate_slug(
                    $post->post_name,
                    $this->get_current_language(), // Huidige taal van de post
                    $this->default_language // Doeltaal (standaard)
                );
                $original_slug = $original_slug_data['slug'] ?? $post->post_name;

                // Construct the path based on post type and original slug
                $path = '';
                if ($post->post_type === 'page') {
                    $path = get_page_uri($current_post_id);
                } elseif ($post->post_type === 'post') {
                    // For posts, use the date archive structure if applicable, otherwise just slug
                    $path = gmdate('Y/m/d', strtotime($post->post_date)) . '/' . $original_slug;
                } else {
                    // For custom post types, use the post type slug and original slug
                    $post_type_object = get_post_type_object($post->post_type);
                    $post_type_slug = $post_type_object->rewrite['slug'] ?? $post->post_type;
                    $path = $post_type_slug . '/' . $original_slug;
                }

                // Ensure path starts with a slash and has a trailing slash if it's not a file
                if ($path[0] !== '/') {
                    $path = '/' . $path;
                }
                if ($path !== '/' && !preg_match('/\\.[a-zA-Z0-9]{2,5}$/', $path)) {
                    $path = trailingslashit($path);
                }
                $original_page_url_in_default_lang = home_url($path);
            }
        }

        // Voeg de default taal toe aan de lijst als deze nog niet aanwezig is
        // Zorg ervoor dat de default taal alleen wordt toegevoegd als deze niet al in de enabled of detectable talen zit.        
        if (!in_array($default_lang, $enabled_languages) && !in_array($default_lang, $detectable_languages)) {
            $all_hreflang_languages[] = $default_lang;
        }


        foreach ($all_hreflang_languages as $lang_code) {
            // Sla de hreflang tag voor de huidige taal over, omdat deze al in de output staat
            if ($lang_code === $current_lang) {
                continue;
            }

            $hreflang_url = $this->translate_url($original_page_url_in_default_lang, $lang_code, $current_post_id);

            // Store the URL for the default language
            if ($lang_code === $default_lang) {
                $default_lang_url = $hreflang_url;
            }

            echo '<link rel="alternate" hreflang="' . esc_attr($lang_code) . '" href="' . esc_url($hreflang_url) . '" />' . "\n";
        }

        // Now, output the x-default hreflang link using the stored default language URL
        // This ensures x-default is exactly the same as the default language URL
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($default_lang_url) . '" />' . "\n";
        echo '<!-- End AI-Translate rel-tag -->' . "\n";
    }

    /**
     * Filter for 'post_type_link' to translate CPT slugs in outgoing URLs.
     *
     * @param string $permalink The post's permalink.
     * @param \WP_Post $post The post object.
     * @return string The translated permalink.
     */
    public function filter_post_type_permalink(string $permalink, \WP_Post $post): string
    {
        // Only translate if translation is needed and it's a public post type
        if (!$this->needs_translation() || !in_array($post->post_type, get_post_types(['public' => true]), true)) {
            return $permalink;
        }

        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        // Get the translated slug
        $translated_slug = $this->get_translated_slug(
            $post->post_name,
            $post->post_type,
            $default_language, // Source language for slug is always default
            $current_language,
            $post->ID
        );

        $new_permalink = preg_replace('/' . preg_quote($post->post_name, '/') . '(\/?)$/', $translated_slug . '$1', $permalink, 1);

        if ($new_permalink === $permalink) {
            $new_permalink = $this->translate_url($permalink, $current_language, $post->ID);
        } else {
            // If slug was replaced, ensure the language prefix is correct
            $new_permalink = $this->translate_url($new_permalink, $current_language, $post->ID);
        }


        return $new_permalink;
    }

    /**
     * Translate a URL to the target language, including slug translation and language prefix.
     *
     * This function generates a translated URL for a given post or page, using the default language
     * slug as the source and adding the appropriate language prefix. Used for SEO-friendly multilingual URLs.
     *
     * @param string $url The original URL.
     * @param string $language The target language code.
     * @param int|null $post_id Optional post ID for context.
     * @return string The translated URL.
     */
    public function translate_url(string $url, string $language, ?int $post_id = null): string
    {
        // Als we in de admin zijn, of als de URL een admin-pad bevat, retourneer de originele URL.
        // Dit is een extra veiligheidscheck omdat is_admin() soms te laat true wordt.
        if (is_admin() || strpos($url, '/wp-admin/') !== false || strpos($url, '/wp-login.php') !== false) {
            return $url;
        }
        $default_language = $this->default_language;
        // Gebruik ALLE beschikbare talen, niet alleen enabled
        $all_languages = array_keys($this->get_available_languages());

        // Ensure target language is valid
        if (!in_array($language, $all_languages, true)) {
            return $url; // Return original URL if target lang is invalid
        }

        $parsed_url = wp_parse_url($url);
        if (!$parsed_url) {
            return $url; // Return original URL if parsing fails
        }

        $current_path = $parsed_url['path'] ?? '/';
        $clean_path = $current_path;

        // Identify if there's an existing language prefix in the path
        $existing_lang_prefix = '';
        if (preg_match('#^/([a-z]{2,3}(?:-[a-z]{2,4})?)(/|$)#i', $current_path, $matches)) {
            $potential_lang = $matches[1];
            // Check if the matched prefix is a valid language AND not the default language
            if (
                in_array($potential_lang, $all_languages, true) &&
                $potential_lang !== $default_language
            ) {
                $existing_lang_prefix = $potential_lang;
                // Remove the existing prefix to get the base path
                $clean_path = preg_replace('#^/' . $existing_lang_prefix . '#', '', $current_path);
                // Ensure clean_path starts with a slash if it's not empty
                if (empty($clean_path)) {
                    $clean_path = '/';
                } elseif ($clean_path[0] !== '/') {
                    $clean_path = '/' . $clean_path;
                }
            }
        }

        // --- SLUG TRANSLATION LOGIC ---
        // Voor slug vertaling gebruiken we altijd de default_language als bron,
        // omdat de slugs in de database in de default taal zijn opgeslagen.
        $translated_path = $this->translate_url_slugs($clean_path, $default_language, $language, $post_id);

        $new_path = $translated_path;

        // Add the new language prefix ONLY if the target language is NOT the default language
        if ($language !== $default_language) {
            $new_path = '/' . $language . ($translated_path === '/' ? '/' : $translated_path);
        } else {
            if (empty($new_path) || $new_path[0] !== '/') {
                $new_path = '/' . ltrim($new_path, '/');
            }
        }

        // Ensure trailing slash for non-file paths
        // Check if the path is not just '/' and does not contain a file extension
        if ($new_path !== '/' && !preg_match('/\\.[a-zA-Z0-9]{2,5}$/', $new_path)) {
            $new_path = trailingslashit($new_path);
        }

        $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host = $parsed_url['host'] ?? '';
        $port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $query = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        $new_url = $scheme . $host . $port . $new_path . $query . $fragment;

        return $new_url;
    }

    /**
     * Filters the WordPress 'request' array to handle translated slugs for incoming URLs.
     * This allows WordPress to correctly identify posts based on their translated slugs.
     *
     * @param array $query_vars The array of WordPress query variables.
     * @return array The modified array of query variables.
     */
    public function parse_translated_request(array $query_vars): array
    {
        // Only proceed if translation is needed and it's not an admin request
        if (!$this->needs_translation() || is_admin() || empty($query_vars)) {
            return $query_vars;
        }

        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        // Skip if we're already on the default language
        if ($current_language === $default_language) {
            return $query_vars;
        }

        // Check if a 'name' (post slug) is present in the query vars
        if (isset($query_vars['name']) && !empty($query_vars['name'])) {
            $incoming_slug = $query_vars['name'];



            // Skip processing for common non-post URLs and system paths
            $skip_patterns = [
                'wp-admin',
                'wp-login',
                'wp-content',
                'wp-includes',
                'feed',
                'comments',
                'trackback',
                'xmlrpc',
                'robots.txt',
                'sitemap',
                '.htaccess',
                'favicon',
                'admin',
                'login',
                'register',
                'lost-password',
                'cron',
                'cron.php',
                'wp-cron.php'
            ];

            foreach ($skip_patterns as $pattern) {
                if (strpos($incoming_slug, $pattern) !== false) {
                    return $query_vars;
                }
            }

            // Skip very short or very long slugs (likely not valid posts)
            if (strlen($incoming_slug) < 2 || strlen($incoming_slug) > 200) {
                return $query_vars;
            }

            // Skip slugs that contain only numbers or special characters
            if (preg_match('/^[0-9\-_\.]+$/', $incoming_slug)) {
                return $query_vars;
            }

            // Attempt to reverse translate the slug
            // Voor een Engelse URL /en/online-violin-lessons/ willen we van Engels naar Nederlands zoeken
            // $current_language = 'en', $default_language = 'nl'
            // We zoeken naar de originele Nederlandse slug die vertaald is naar 'online-violin-lessons'

            $original_slug_data = $this->reverse_translate_slug($incoming_slug, $current_language, $default_language);

            if ($original_slug_data && isset($original_slug_data['slug'])) {
                $original_slug = $original_slug_data['slug'];
                $post_type = $original_slug_data['post_type'] ?? null;

                // If the original slug is different from the incoming slug, it means we found a translation
                if ($original_slug !== $incoming_slug) {
                    $query_vars['name'] = $original_slug; // Set the original slug for WordPress to find the post

                    // If a post type was identified during reverse translation, set it
                    if ($post_type) {
                        $query_vars['post_type'] = $post_type;
                    }

                    // Unset 'pagename' if it exists, as 'name' is more specific for posts/pages
                    if (isset($query_vars['pagename'])) {
                        unset($query_vars['pagename']);
                    }
                }
            }
        }

        return $query_vars;
    }


    /**
     * Translate URL slugs in a path.
     *
     * @param string $path The path to translate (without language prefix)
     * @param string $source_language Source language of the slugs
     * @param string $target_language Target language for translation
     * @param int|null $post_id Optional post ID for context.
     * @return string Translated path
     */
    private function translate_url_slugs(string $path, string $source_language, string $target_language, ?int $post_id = null): string
    {
        // Skip translation if same language or homepage
        if ($source_language === $target_language || $path === '/') {
            return $path;
        }

        // Try to identify content from URL if not provided
        if ($post_id === null) {
            $post_id = $this->identify_post_from_url($path);
        }

        if ($post_id !== null) {
            // Get post and translate its slug
            $post = get_post($post_id);
            if ($post) {
                $translated_slug = $this->get_translated_slug($post->post_name, $post->post_type, $source_language, $target_language, $post->ID);

                // Replace the slug in the path
                $path_parts = explode('/', trim($path, '/'));
                if (!empty($path_parts)) {
                    // Replace the last part (slug) with translated version
                    // Get the original slug from the post object, not from the URL path.
                    // This ensures we always translate from the canonical default language slug.
                    $original_post_slug = $post->post_name;
                    $translated_slug = $this->get_translated_slug($original_post_slug, $post->post_type, $source_language, $target_language, $post->ID);

                    // Replace the last part (slug) with translated version
                    $path_parts = explode('/', trim($path, '/'));
                    // Find the position of the original slug in the path parts
                    $last_segment_key = array_key_last($path_parts);
                    if ($last_segment_key !== null) {
                        $path_parts[$last_segment_key] = $translated_slug;
                    }

                    $new_path = '/' . implode('/', $path_parts);
                    return $new_path;
                }
            }
        } else {
        }

        // If no post found, try to translate individual path segments
        $translated_path = $this->translate_path_segments($path, $source_language, $target_language);
        if ($translated_path !== $path) {
        }
        return $translated_path;
    }

    /**
     * Identify post from URL path.
     *
     * @param string $path URL path
     * @return int|null Post ID if found
     */
    private function identify_post_from_url(string $path): ?int
    {
        // Try WordPress built-in function first
        $post_id = url_to_postid(home_url($path));

        if ($post_id) {
            return $post_id;
        }

        // Extract slug from path
        $path_segments = explode('/', trim($path, '/'));
        $slug = end($path_segments); // Last segment is usually the post slug
        if (empty($slug)) {
            return null;
        }

        $post_type_from_path = null;
        $public_cpts = get_post_types(['public' => true, '_builtin' => false], 'names');
        $allowed_post_types = array_merge(['post', 'page'], $public_cpts);

        // Determine potential post type from path segments
        // For "3-deep" structure like /lang/cpt-slug/post-slug/
        if (count($path_segments) >= 2) {
            $potential_post_type_slug = $path_segments[count($path_segments) - 2];
            if (in_array($potential_post_type_slug, $allowed_post_types, true)) {
                $post_type_from_path = $potential_post_type_slug;
            }
        }

        // Use WordPress get_posts() 
        $args = [
            'name' => $slug,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'post_type' => $post_type_from_path ? [$post_type_from_path] : $allowed_post_types,
            'orderby' => ['post_type' => 'DESC', 'post_date' => 'DESC'],
            'meta_query' => [],
            'suppress_filters' => false,
        ];

        // Custom ordering to prioritize pages over posts
        add_filter('posts_orderby', function ($orderby, $query) {
            global $wpdb;
            return "CASE WHEN {$wpdb->posts}.post_type = 'page' THEN 0 ELSE 1 END, {$wpdb->posts}.post_date DESC";
        }, 10, 2);

        $posts = get_posts($args);

        // Remove the filter
        remove_all_filters('posts_orderby');

        if (!empty($posts)) {
            return (int)$posts[0]->ID;
        }

        // If no direct match found, try to find the original slug using reverse translation
        $settings = $this->get_settings();
        $default_language = $settings['default_language'] ?? 'nl';
        $current_language = $this->get_current_language();

        // Only try reverse translation if we're not in the default language
        if ($current_language !== $default_language) {
            // Direct database lookup for reverse translation
            global $wpdb;
            $table_name = $wpdb->prefix . 'ai_translate_slugs';

            // Look for the translated slug in the database using WordPress functions
            $original_slug_data = $wpdb->get_row($wpdb->prepare(
                "SELECT post_id, original_slug FROM {$table_name} WHERE translated_slug = %s AND language_code = %s",
                $slug,
                $current_language
            ));

            if ($original_slug_data) {
                // Try to find a post with the original slug
                $args = [
                    'name' => $original_slug_data->original_slug,
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    'post_type' => $post_type_from_path ? [$post_type_from_path] : $allowed_post_types,
                    'orderby' => ['post_type' => 'DESC', 'post_date' => 'DESC'],
                    'meta_query' => [],
                    'suppress_filters' => false,
                ];

                $posts = get_posts($args);

                if (!empty($posts)) {
                    return (int)$posts[0]->ID;
                }
            }
        }

        return null;
    }

    /**
     * Get translated slug for a post.
     *
     * @param string $original_slug Original slug
     * @param string $post_type Post type
     * @param string $source_language Source language
     * @param string $target_language Target language
     * @param int|null $post_id Optional post ID for context.
     * @return string Translated slug
     */
    private function get_translated_slug(string $original_slug, string $post_type, string $source_language, string $target_language, ?int $post_id = null): string
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ai_translate_slugs';

        // Generate cache key for slug translation
        $cache_key = "slug_{$original_slug}_{$post_type}_{$source_language}_{$target_language}";

        // MEMORY CACHE VERWIJDERD

        // Check custom database table first - dit is nu de primaire bron
        // Use WordPress functions for safer database access
        $db_cached_slug = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_slug FROM {$table_name} WHERE original_slug = %s AND language_code = %s AND post_id = %d",
            $original_slug,
            $target_language,
            $post_id
        ));

        if ($db_cached_slug !== null) {
            // MEMORY CACHE VERWIJDERD
            return $db_cached_slug;
        }

        // Check transient cache (alleen als fallback)
        $transient_key = $this->generate_cache_key($cache_key, $target_language, 'slug_trans');
        $cached_slug = get_transient($transient_key);
        if ($cached_slug !== false) {
            // Als er een transient is, migreer deze naar de database voor permanente opslag
            if ($post_id !== null) {
                // Gebruik INSERT IGNORE om te voorkomen dat bestaande vertalingen worden overschreven
                // Gebruik INSERT IGNORE om duplicate key errors te voorkomen
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->query is necessary for INSERT IGNORE
                $insert_result = $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO {$table_name} (post_id, original_slug, translated_slug, language_code) VALUES (%d, %s, %s, %s)",
                    $post_id,
                    $original_slug,
                    $cached_slug,
                    $target_language
                ));

                // Als insert mislukt door duplicate key, haal dan de bestaande vertaling op
                if ($insert_result === false && $wpdb->last_error && strpos($wpdb->last_error, 'Duplicate entry') !== false) {
                    // Use WordPress functions for safer database access
                    $existing_slug = $wpdb->get_var($wpdb->prepare(
                        "SELECT translated_slug FROM {$table_name} WHERE original_slug = %s AND language_code = %s AND post_id = %d",
                        $original_slug,
                        $target_language,
                        $post_id
                    ));
                    if ($existing_slug !== null) {
                        $cached_slug = $existing_slug;
                    }
                }
            }
            // MEMORY CACHE VERWIJDERD
            return $cached_slug;
        }

        // Alleen vertalen als er nog geen permanente vertaling bestaat
        // Convert slug to readable text for translation
        $readable_text = str_replace(['-', '_'], ' ', $original_slug);
        $readable_text = ucwords($readable_text);

        try {
            // Translate the readable text with zero temperature for consistency
            $translated_text = $this->translate_text(
                $readable_text,
                $source_language,
                $target_language,
                true, // is_title
                false // no disk cache for slugs
            );

            // Remove translation marker
            $translated_text = self::remove_translation_marker($translated_text);

            // Convert back to slug format
            $translated_slug = $this->text_to_slug($translated_text);

            // MEMORY CACHE VERWIJDERD

            // Save to custom database table - gebruik INSERT IGNORE om te voorkomen dat bestaande vertalingen worden overschreven
            if ($post_id !== null) {
                // Controleer nogmaals of er al een vertaling bestaat (race condition voorkomen)
                // Use WordPress functions for safer database access
                $existing_slug = $wpdb->get_var($wpdb->prepare(
                    "SELECT translated_slug FROM {$table_name} WHERE original_slug = %s AND language_code = %s AND post_id = %d",
                    $original_slug,
                    $target_language,
                    $post_id
                ));

                if ($existing_slug === null) {
                    // Gebruik INSERT IGNORE om duplicate key errors te voorkomen
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->query is necessary for INSERT IGNORE
                    $insert_result = $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO {$table_name} (post_id, original_slug, translated_slug, language_code) VALUES (%d, %s, %s, %s)",
                        $post_id,
                        $original_slug,
                        $translated_slug,
                        $target_language
                    ));

                    if ($insert_result === false) {


                        // Als insert mislukt door duplicate key, haal dan de bestaande vertaling op
                        if ($wpdb->last_error && strpos($wpdb->last_error, 'Duplicate entry') !== false) {
                            // Use WordPress functions for safer database access
                            $existing_slug = $wpdb->get_var($wpdb->prepare(
                                "SELECT translated_slug FROM {$table_name} WHERE original_slug = %s AND language_code = %s AND post_id = %d",
                                $original_slug,
                                $target_language,
                                $post_id
                            ));
                            if ($existing_slug !== null) {
                                $translated_slug = $existing_slug;
                                // MEMORY CACHE VERWIJDERD
                            }
                        }
                    }
                } else {
                    // Gebruik de bestaande vertaling
                    $translated_slug = $existing_slug;
                    // MEMORY CACHE VERWIJDERD
                }
            }

            // Save to transient cache als backup met langere expiratie (30 dagen)
            set_transient($transient_key, $translated_slug, 30 * 24 * 3600);

            return $translated_slug;
        } catch (\Exception $e) {
            return $original_slug; // Return original on failure
        }
    }

    /**
     * Convert text to URL-safe slug.
     *
     * @param string $text Text to convert
     * @return string URL-safe slug
     */
    private function text_to_slug(string $text): string
    {
        // Use WordPress sanitize_title function
        return sanitize_title($text);
    }

    /**
     * Translate individual path segments.
     *
     * @param string $path URL path
     * @param string $source_language Source language
     * @param string $target_language Target language
     * @return string Path with translated segments
     */
    private function translate_path_segments(string $path, string $source_language, string $target_language): string
    {
        $segments = explode('/', trim($path, '/'));
        $translated_segments = [];

        foreach ($segments as $segment) {
            if (empty($segment)) {
                continue;
            }

            // Try to translate segment
            $readable_text = str_replace(['-', '_'], ' ', $segment);
            $readable_text = ucwords($readable_text);

            try {
                $translated_text = $this->translate_text(
                    $readable_text,
                    $source_language,
                    $target_language,
                    true,
                    false
                );

                $translated_text = self::remove_translation_marker($translated_text);
                $translated_segments[] = $this->text_to_slug($translated_text);
            } catch (\Exception $e) {
                // Keep original segment on translation failure
                $translated_segments[] = $segment;
            }
        }

        return '/' . implode('/', $translated_segments);
    }


    /**
     * Batch translate all navigation menu items to the current language with URL-based title generation.
     *
     * Flow:
     * 1. First translate URLs using translate_url()
     * 2. For non-homepage items: generate title from URL path
     *    - Roman languages: "/en/about-us/" → "About Us"
     *    - Non-Roman languages: "/ru/функционал/" → "функционал"
     * 3. For homepage items: translate title normally via API
     * 4. Translate descriptions via API
     *
     * This function is hooked to the 'wp_nav_menu_objects' filter and is the only correct way
     * to translate menu items. It collects all titles and descriptions, translates them in batch,
     * and updates the menu item objects. Never translate menu items individually; always use this batch function.
     *
     * @param array $items Array of WP_Nav_Menu_Item objects.
     * @param object|null $menu Optional menu object.
     * @return array Modified array of menu item objects with translated titles and descriptions.
     */
    /**
     * Translate menu items using batching.
     * Handles potential errors during batch translation.
     *
     * @param array $items Array of menu item objects.
     * @param ?object $menu Optional menu object (niet gebruikt in deze implementatie).
     * @return array Modified array of menu item objects.
     */
    public function translate_menu_items(array $items, ?object $menu = null): array
    {
        // Skip if no translation needed, in admin, or items array is empty
        if (!$this->needs_translation() || is_admin() || empty($items)) {
            return $items;
        }

        $target_language = $this->get_current_language();
        $default_language = $this->default_language;

        // Verzamel alle titels en descriptions om te vertalen, met behoud van originele index
        $titles_to_translate = [];
        $descriptions_to_translate = [];
        $original_titles = []; // Store original Dutch titles for cache key generation

        foreach ($items as $index => $item) {
            // Voeg alleen niet-lege descriptions toe
            if (!empty($item->description)) {
                $descriptions_to_translate[$index] = $item->description;
            }

            // Vertaal URL eerst (dit is geen API call en kan geen loop veroorzaken)
            if (isset($item->url)) {
                $item->url = $this->translate_url($item->url, $target_language);
            }

            // Check of dit een homepage item is
            $is_homepage = false;
            if (isset($item->url)) {
                $parsed_url = wp_parse_url($item->url);
                $path = $parsed_url['path'] ?? '/';
                $is_homepage = ($path === '/' || $path === '/' . $target_language . '/' || $path === '/' . $target_language);
            }

            // Voor homepage items: voeg titel toe voor normale vertaling
            if ($is_homepage && !empty($item->title)) {
                $titles_to_translate[$index] = $item->title;
                $original_titles[$index] = $item->title;
            }
            // Voor niet-homepage items: genereer titel uit URL
            else if (!empty($item->title)) {
                // Generate title from URL for non-homepage items
                $generated_title = $this->generate_title_from_url($item->url, $target_language);
                if (!empty($generated_title)) {
                    // Store full title in attr_title for tooltip
                    $item->attr_title = $generated_title;
                    // Use generated title for display (will be shortened later)
                    $item->title = $generated_title;
                }
            }
        }

        // Batch translate titles (only for homepage items)
        $translated_titles = null;
        if (!empty($titles_to_translate)) {
            try {
                $translated_titles = $this->batch_translate_items($titles_to_translate, $default_language, $target_language, 'menu_item');

                // Validatie
                if (!is_array($translated_titles) || count(array_diff_key($titles_to_translate, $translated_titles)) > 0) {
                    $translated_titles = null;
                }
            } catch (\Exception $e) {
                $translated_titles = null;
            }
        }

        // Batch translate descriptions
        $translated_descriptions = null;
        if (!empty($descriptions_to_translate)) {
            try {
                $translated_descriptions = $this->batch_translate_items($descriptions_to_translate, $default_language, $target_language, 'menu_item_description');

                // Validatie
                if (!is_array($translated_descriptions) || count(array_diff_key($descriptions_to_translate, $translated_descriptions)) > 0) {
                    $translated_descriptions = null;
                }
            } catch (\Exception $e) {
                $translated_descriptions = null;
            }
        }

        // Map de vertalingen terug naar de items, alleen als de respectievelijke batch succesvol was
        foreach ($items as $index => $item) {
            // Verwerk titels: alleen voor homepage items die via API zijn vertaald
            if (isset($titles_to_translate[$index])) {
                $original_title = $titles_to_translate[$index];
                $full_translated_title = (isset($translated_titles[$index]) && !empty($translated_titles[$index])) ? (string)$translated_titles[$index] : $original_title;

                if (!empty($full_translated_title)) {
                    // Clean the full translation
                    $full_translated_title = $this->clean_html_string($full_translated_title);

                    // Create short version for menu display (max 2 words)
                    $short_title = $this->create_short_menu_title($full_translated_title);

                    // Store full translation in title attribute and short version in visible text
                    $item->title = $short_title;
                    $item->attr_title = $full_translated_title; // Full translation for tooltip
                } else {
                    $item->title = ''; // Zorg dat het leeg is als er geen content is
                }
            }
            // Voor niet-homepage items: pas korte titel toe
            else if (!empty($item->title)) {
                // Create short version for menu display (max 2 words)
                $short_title = $this->create_short_menu_title($item->title);
                $item->title = $short_title;
            }

            // Verwerk descriptions: als vertaling beschikbaar en niet leeg, gebruik die.
            // Anders, als de oorspronkelijke description niet leeg was.
            $original_description = $descriptions_to_translate[$index] ?? $item->description;
            $new_description = (isset($translated_descriptions[$index]) && !empty($translated_descriptions[$index])) ? (string)$translated_descriptions[$index] : $original_description;
            if (!empty($new_description)) {
                $item->description = $this->clean_html_string($new_description); // Apply cleaning
            } else {
                $item->description = ''; // Zorg dat het leeg is als er geen content is
            }
        }

        return $items;
    }

    /**
     * Create a short version of a menu title for display purposes.
     * Limits to max 3 words and 50 characters for clean menu display.
     *
     * @param string $full_title The full translated title
     * @return string Short version for menu display
     */
    private function create_short_menu_title(string $full_title): string
    {
        // Split into words
        $words = explode(' ', trim($full_title));

        // Always take first 2 words maximum for menu items
        $short_words = array_slice($words, 0, 2);
        $short_title = implode(' ', $short_words);

        // If still too long, truncate to 50 characters
        if (strlen($short_title) > 50) {
            $short_title = substr($short_title, 0, 47) . '...';
        }

        return $short_title;
    }

    /**
     * Translate widget title.
     * Called individually by the 'widget_title' filter. Uses caching.
     *
     * @param string $title The widget title to translate
     * @return string Translated widget title
     */
    public function translate_widget_title(string $title): string
    {
        // Skip if no translation needed, in admin, or title is empty
        if (!$this->needs_translation() || is_admin() || empty($title)) {
            return $title;
        }

        $target_language = $this->get_current_language();
        $default_language = $this->default_language;

        // Use consistent cache identifier with widget text
        $normalized_title = wp_strip_all_tags($title);
        $cache_identifier = md5($normalized_title . 'widget' . $target_language);
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');

        // MEMORY CACHE VERWIJDERD

        // Roep translate_template_part aan, die de volledige cache- en vertaallogica bevat
        // Geef 'widget_title' mee als type voor context en correcte cache-instellingen (geen disk cache)
        // Strip HTML tags vóór vertaling (alleen tekst aanbieden aan de API)
        $plain_title = wp_strip_all_tags($title);
        $translated = $this->translate_template_part($plain_title, 'widget_title');

        return $this->clean_html_string($translated); // Apply cleaning after translation
    }



    /**
     * Lijst met shortcodes die echt uitgesloten moeten worden van vertaling (niet vertaald, geen cache).
     * @return array
     */
    public static function get_truly_excluded_shortcodes(): array
    {
        return [
            'bws_google_captcha', // Contact form captcha - must not be translated
            'chatbot', // Chatbot scripts - must not be translated
        ];
    }



    /**
     * Verzamelt statistieken over de cache bestanden.
     * 
     * @return array Statistieken over cache bestanden
     */    public function get_cache_statistics(): array
    {
        $stats = [
            'total_files' => 0,
            'total_size' => 0,
            'expired_files' => 0,
            'languages' => [],          // File count per language
            'last_modified' => time(),
            'languages_details' => []   // Detailed stats per language
        ];

        // Zorg ervoor dat de cache directory bestaat en schrijfbaar is
        $this->initialize_cache_directories();

        // Normaliseer het pad met directory separator
        $pattern = rtrim($this->cache_dir, '/\\') . DIRECTORY_SEPARATOR . '*.cache';
        $files = glob($pattern);

        if (is_array($files)) {
            $stats['total_files'] = count($files);

            foreach ($files as $file) {
                if (is_file($file)) {
                    $file_size = filesize($file);
                    $stats['total_size'] += $file_size;
                    $is_expired = $this->is_cache_expired($file, null);

                    if ($is_expired) {
                        $stats['expired_files']++;
                    }

                    // Extraheer taalcode uit bestandsnaam
                    $filename = basename($file, '.cache');
                    if (preg_match('/^([a-z]{2,3})_/', $filename, $matches)) {
                        $lang_code = $matches[1];

                        // Initialize counters if not set
                        if (!isset($stats['languages'][$lang_code])) {
                            $stats['languages'][$lang_code] = 0;
                            $stats['languages_details'][$lang_code] = [
                                'size' => 0,
                                'expired_count' => 0,
                                'last_modified' => filemtime($file)
                            ];
                        }

                        // Update statistics for this language
                        $stats['languages'][$lang_code]++;
                        $stats['languages_details'][$lang_code]['size'] += $file_size;
                        if ($is_expired) {
                            $stats['languages_details'][$lang_code]['expired_count']++;
                        }
                        // Update last_modified if this file is newer
                        $file_time = filemtime($file);
                        if ($file_time > $stats['languages_details'][$lang_code]['last_modified']) {
                            $stats['languages_details'][$lang_code]['last_modified'] = $file_time;
                        }
                    }
                }
            }
        }

        return $stats;
    }

    /**
     * Get all memory cache.
     *
     * @return array
     */
    // MEMORY CACHE FUNCTIES VERWIJDERD

    /**
     * Check if a key exists in the memory cache.
     *
     * @param string $key
     * @return bool
     */
    // MEMORY CACHE FUNCTIES VERWIJDERD

    /**
     * Validate API settings by attempting to fetch models
     *
     * @param string $provider_key The key of the provider (e.g., 'openai').
     * @param string $api_key The API key to validate.
     * @param string $custom_api_url Optional custom API URL if provider is 'custom'.
     * @return array<string,mixed> Response data including models if successful.
     * @throws \Exception If validation fails.
     */
    public function validate_api_settings(string $provider_key, string $api_key, string $custom_api_url = ''): array
    {
        if (empty($provider_key) || empty($api_key)) {
            throw new \InvalidArgumentException('Provider key of API key is leeg.');
        }

        $api_url = '';
        if ($provider_key === 'custom' && !empty($custom_api_url)) {
            $api_url = $custom_api_url;
        } else {
            $api_url = self::get_api_url_for_provider($provider_key);
        }

        if (empty($api_url)) {
            throw new \InvalidArgumentException('Kon API URL niet bepalen voor provider: ' . esc_html($provider_key));
        }

        $endpoint_url = rtrim($api_url, '/') . '/models';

        $response = wp_remote_get($endpoint_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'timeout' => 20, // Consistent with AJAX handler
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            throw new \Exception('API Verzoek WP Fout: ' . esc_html($response->get_error_message()));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $decoded_body = json_decode($response_body, true);

        if ($response_code !== 200) {
            $error_message = 'API Fout (Code: ' . $response_code . '): ';
            $error_details = isset($decoded_body['error']['message']) ? $decoded_body['error']['message'] : $response_body;
            // Truncate long error messages from API
            if (strlen($error_details) > 500) {
                $error_details = substr($error_details, 0, 500) . '... (truncated)';
            }
            $error_message .= $error_details;
            throw new \Exception(esc_html($error_message));
        }

        $models_data = null;
        if (isset($decoded_body['data']) && is_array($decoded_body['data'])) {
            $models_data = $decoded_body['data'];
        } elseif (is_array($decoded_body) && (empty($decoded_body) || isset($decoded_body[0]['id']) || (isset($decoded_body[0]) && is_string($decoded_body[0])))) {
            // Handle cases where the response is directly an array of models (objects with 'id' or strings)
            $models_data = $decoded_body;
        } else {
            throw new \Exception('Ongeldige model data structuur in API antwoord. Body: ' . esc_html(substr($response_body, 0, 200)));
        }

        $models = [];
        if (is_array($models_data)) {
            foreach ($models_data as $m) {
                if (is_array($m) && isset($m['id']) && is_string($m['id'])) {
                    $models[] = $m['id'];
                } elseif (is_string($m)) { // Handle cases where models are just an array of strings
                    $models[] = $m;
                }
            }
        }

        $models = array_filter(array_unique($models)); // Ensure unique and remove empty
        sort($models);

        return [
            'message' => 'API validatie succesvol (modellen opgehaald).',
            'models' => $models
        ];
    }

    /**
     * Reverse translate a slug to find the original slug.
     * Used for incoming URL handling to prevent 404 errors.
     *
     * @param string $translated_slug The translated slug from the URL
     * @param string $source_language Language of the translated slug
     * @param string $target_language Target language (usually default language)
     * @return string|null Original slug if found, null otherwise
     */
    public function reverse_translate_slug(string $translated_slug, string $source_language, string $target_language): ?array
    {
        global $wpdb;
        $settings = $this->get_settings();
        $default_language = $settings['default_language'] ?? 'nl';

        // Skip if same language
        if ($source_language === $target_language) {
            // Try to find the post_type for the translated_slug using WordPress functions
            $posts = get_posts([
                'name' => $translated_slug,
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'post_type' => 'any',
                'orderby' => ['post_type' => 'DESC', 'post_date' => 'DESC'],
                'suppress_filters' => false,
            ]);

            if (!empty($posts)) {
                $post = $posts[0];
                return ['slug' => $translated_slug, 'post_type' => $post->post_type];
            }
            return ['slug' => $translated_slug, 'post_type' => null]; // Fallback if post_type not found
        }

        $table_name_slugs = $wpdb->prefix . 'ai_translate_slugs';

        // Apply URL decoding for UTF-8 character handling
        $decoded_translated_slug = urldecode($translated_slug);

        // 1. Check custom slug translation table first (exact match)
        // In de database staat language_code = doeltaal (bijv. 'en' voor Engelse vertalingen)
        // Voor reverse lookup: zoek naar translated_slug = 'packages' met language_code = 'en'
        $cached_original_slug_data = $wpdb->get_row($wpdb->prepare(
            "SELECT post_id, original_slug FROM {$table_name_slugs} WHERE translated_slug = %s AND language_code = %s",
            $decoded_translated_slug,
            $source_language
        ));

        // If not found, try with original encoded version
        if (!$cached_original_slug_data) {
            $cached_original_slug_data = $wpdb->get_row($wpdb->prepare(
                "SELECT post_id, original_slug FROM {$table_name_slugs} WHERE translated_slug = %s AND language_code = %s",
                $translated_slug,
                $source_language
            ));
        }

        // If still not found, try LIKE match with decoded version
        if (!$cached_original_slug_data) {
            $cached_original_slug_data = $wpdb->get_row($wpdb->prepare(
                "SELECT post_id, original_slug FROM {$table_name_slugs} WHERE translated_slug LIKE %s AND language_code = %s ORDER BY LENGTH(translated_slug) ASC LIMIT 1",
                $wpdb->esc_like($decoded_translated_slug) . '%',
                $source_language
            ));
        }

        if ($cached_original_slug_data) {
            $post = get_post($cached_original_slug_data->post_id);
            if ($post) {
                return ['slug' => $cached_original_slug_data->original_slug, 'post_type' => $post->post_type];
            }
        }

        // Test database connection and table using WordPress functions
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name_slugs));

        // Test query using WordPress functions
        $test_result = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) as count FROM {$table_name_slugs} WHERE language_code = %s",
            $source_language
        ));

        // Test specific entry using WordPress functions
        $specific_result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name_slugs} WHERE translated_slug = %s AND language_code = %s",
            $translated_slug,
            $source_language
        ));

        // 2. Fallback: Try to find a post with this exact slug (might be already in target language or default)
        // Try with decoded version first
        $posts = get_posts([
            'name' => $decoded_translated_slug,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'post_type' => 'any',
            'orderby' => ['post_type' => 'DESC', 'post_date' => 'DESC'],
            'suppress_filters' => false,
        ]);

        $post = !empty($posts) ? $posts[0] : null;

        // If not found, try with original encoded version
        if (!$post) {
            $posts = get_posts([
                'name' => $translated_slug,
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'post_type' => 'any',
                'orderby' => ['post_type' => 'DESC', 'post_date' => 'DESC'],
                'suppress_filters' => false,
            ]);
            $post = !empty($posts) ? $posts[0] : null;
        }

        // If still not found, try fuzzy match with decoded version
        if (!$post) {
            // Use WordPress search functionality instead of LIKE query
            $search_posts = get_posts([
                's' => $decoded_translated_slug,
                'post_status' => 'publish',
                'posts_per_page' => 10,
                'post_type' => 'any',
                'orderby' => ['post_type' => 'DESC', 'post_date' => 'DESC'],
                'suppress_filters' => false,
            ]);

            // Find the best match
            foreach ($search_posts as $search_post) {
                if (strpos($search_post->post_name, $decoded_translated_slug) === 0) {
                    $post = $search_post;
                    break;
                }
            }
        }

        if ($post) {
            return ['slug' => $post->post_name, 'post_type' => $post->post_type]; // Found exact match
        }

        // 3. Try to find by generating potential translated slugs
        $posts = get_posts([
            'post_status' => 'publish',
            'posts_per_page' => -1, // Get all posts
            'post_type' => 'any',
            'orderby' => ['post_type' => 'DESC', 'post_date' => 'DESC'],
            'suppress_filters' => false,
        ]);

        // Filter out posts with empty slugs
        $posts = array_filter($posts, function ($post) {
            return !empty($post->post_name);
        });

        foreach ($posts as $post) {
            // Get what this post's slug would be when translated from default to current language
            $potential_translated_slug = $this->get_translated_slug(
                $post->post_name, // Dit is de originele slug in default_language
                $post->post_type,
                $default_language, // Bron is de default taal (nl)
                $source_language,   // Doel is de taal van de inkomende slug (en)
                (int)$post->ID // Pass post ID for database lookup in get_translated_slug
            );

            // If the translated version matches what we're looking for (try both versions)
            if ($potential_translated_slug === $translated_slug || $potential_translated_slug === $decoded_translated_slug) {
                return ['slug' => $post->post_name, 'post_type' => $post->post_type]; // Return the original slug
            }
        }

        // 4. Final fallback: try to find post in default language
        $posts_in_default_lang = get_posts([
            'name' => $decoded_translated_slug,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'post_type' => 'any',
            'orderby' => ['post_type' => 'DESC', 'post_date' => 'DESC'],
            'suppress_filters' => false,
        ]);

        $post_in_default_lang = !empty($posts_in_default_lang) ? $posts_in_default_lang[0] : null;

        if (!$post_in_default_lang) {
            $posts_in_default_lang = get_posts([
                'name' => $translated_slug,
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'post_type' => 'any',
                'orderby' => ['post_type' => 'DESC', 'post_date' => 'DESC'],
                'suppress_filters' => false,
            ]);
            $post_in_default_lang = !empty($posts_in_default_lang) ? $posts_in_default_lang[0] : null;
        }

        if ($post_in_default_lang) {
            return ['slug' => $post_in_default_lang->post_name, 'post_type' => $post_in_default_lang->post_type];
        }

        return ['slug' => $decoded_translated_slug, 'post_type' => null]; // Gebruik de gedecodeerde versie als fallback
    }

    /**
     * Conditionally adds the filter for Fluent Form output if translation is needed.
     */
    public function conditionally_add_fluentform_filter(): void
    {
        // Alleen als vertaling nodig is
        if ($this->needs_translation()) {
            // Voeg de filter toe voor het vertalen van FluentForm velden met zeer hoge prioriteit
            // Dit zorgt ervoor dat FluentForm content wordt beschermd voordat het bij translate_text komt
            add_filter('the_content', [$this, 'translate_fluentform_fields'], 9999);
        }
    }

    /**
     * Translate FluentForm fields after rendering.
     *
     * @param string $content The page content.
     * @return string Content with translated FluentForm fields.
     */
    public function translate_fluentform_fields(string $content): string
    {
        // Check if this is a FluentForm by looking for common FluentForm elements
        if (
            strpos($content, 'fluentform') === false &&
            strpos($content, 'ff-el-form') === false &&
            strpos($content, 'ff-el-input') === false &&
            strpos($content, 'ff-btn') === false &&
            strpos($content, 'ff-field_container') === false &&
            strpos($content, 'ff-el-group') === false &&
            strpos($content, 'ff-t-container') === false &&
            strpos($content, 'ff-t-cell') === false &&
            strpos($content, 'ff-el-input--label') === false &&
            strpos($content, 'ff-el-input--content') === false &&
            strpos($content, 'ff-el-form-control') === false &&
            strpos($content, 'ff_form_instance') === false
        ) {
            return $content;
        }



        // Extract translatable elements from FluentForm
        $translatable_texts = [];
        
        // Extract headings (h1, h2, h3, h4, h5, h6) that might contain form titles
        preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', $content, $heading_matches);
        if (!empty($heading_matches[1])) {
            $translatable_texts = array_merge($translatable_texts, $heading_matches[1]);
        }
        
        // Extract div elements with class that might contain form titles
        preg_match_all('/<div[^>]*class="[^"]*(?:form-title|form-header|contact-title|contact-header)[^"]*"[^>]*>(.*?)<\/div>/is', $content, $div_matches);
        if (!empty($div_matches[1])) {
            $translatable_texts = array_merge($translatable_texts, $div_matches[1]);
        }
        
        // Extract labels - multiple patterns to catch all FluentForm labels
        $label_patterns = [
            '/<label[^>]*for="[^"]*"[^>]*>(.*?)<\/label>/is',
            '/<label[^>]*class="[^"]*(?:ff-el|fluentform)[^"]*"[^>]*>(.*?)<\/label>/is',
            '/<label[^>]*>(.*?)<\/label>/is' // Catch all labels as final fallback
        ];
        
        foreach ($label_patterns as $pattern) {
            preg_match_all($pattern, $content, $label_matches);
            if (!empty($label_matches[1])) {
                $translatable_texts = array_merge($translatable_texts, $label_matches[1]);
            }
        }
        
        // Extract placeholders
        preg_match_all('/placeholder=["\']([^"\']+)["\']/i', $content, $placeholder_matches);
        if (!empty($placeholder_matches[1])) {
            $translatable_texts = array_merge($translatable_texts, $placeholder_matches[1]);
        }
        
        // Extract option texts
        preg_match_all('/<option[^>]*value=["\'][^"\']*["\'][^>]*>([^<]+)<\/option>/is', $content, $option_matches);
        if (!empty($option_matches[1])) {
            $translatable_texts = array_merge($translatable_texts, $option_matches[1]);
        }
        
        // Extract button texts
        preg_match_all('/<button[^>]*class="[^"]*ff-btn[^"]*"[^>]*>(.*?)<\/button>/is', $content, $button_matches);
        if (!empty($button_matches[1])) {
            $translatable_texts = array_merge($translatable_texts, $button_matches[1]);
        }
        
        // Remove duplicates and empty strings
        $translatable_texts = array_filter(array_unique($translatable_texts));
        
        if (empty($translatable_texts)) {
            return $content;
        }
        
        // Batch translate the texts
        $current_lang = $this->get_current_language();
        $default_lang = $this->default_language;
        
        // Voor FluentForm: filter nonce waarden uit de cache key om unieke cache files te voorkomen
        $normalized_texts = [];
        foreach ($translatable_texts as $text) {
            // Vervang nonce waarden door placeholders
            $normalized_text = preg_replace('/value=["\']([a-f0-9]{10})["\']/i', 'value="__AITRANSLATE_FF_NONCE__"', $text);
            $normalized_text = preg_replace('/value=[\'"]([a-f0-9]{10})[\'"]/i', 'value="__AITRANSLATE_FF_NONCE__"', $normalized_text);
            $normalized_texts[] = $normalized_text;
        }
        
        $translated_texts = $this->batch_translate_items(
            $normalized_texts,
            $default_lang,
            $current_lang,
            'fluentform'
        );
        
        // Replace original texts with translated versions
        $translated_content = $content;
        foreach ($translatable_texts as $index => $original_text) {
            if (isset($translated_texts[$index])) {
                // Use preg_replace to replace all occurrences, not just the first one
                // Also handle HTML entities and whitespace variations
                $original_escaped = preg_quote(trim($original_text), '/');
                $translated_content = preg_replace('/' . $original_escaped . '/', $translated_texts[$index], $translated_content);
            }
        }
        
        return $translated_content;
    }

    /**
     * Redirects to the homepage of the current language if a 404 error occurs.
     * This function is hooked to 'template_redirect'.
     */
    public function handle_404_redirect(): void
    {
        if (is_404() && !is_admin()) {
            $current_lang = $this->get_current_language();
            $default_lang = $this->default_language;

            // Alleen redirect voor niet-standaardtalen
            if ($current_lang !== $default_lang) {
                // Gebruik translate_url om de juiste homepage URL te krijgen voor de huidige taal
                $home_url = $this->translate_url(home_url('/'), $current_lang);

                // Controleer of we al op de homepage zijn (voorkom loop)
                $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
                $home_path = wp_parse_url($home_url, PHP_URL_PATH);
                if ($request_uri !== $home_path && rtrim($request_uri, '/') !== rtrim($home_path, '/')) {
                    wp_redirect($home_url, 302);
                    exit;
                }
            }
            // Voor de standaard taal: laat WordPress zijn normale 404-gedrag behouden
        }
    }

    /**
     * Clear menu item cache specifically.
     * This is needed to fix issues with memcached where menu items are cached in wrong language.
     * Slug cache wordt NIET gewist om consistentie te behouden.
     */
    public function clear_menu_cache(): void
    {
        global $wpdb;

        // Clear menu-related transients, maar NIET slug cache
        // Use WordPress functions for safer transient deletion
        global $wpdb;
        $transient_patterns = [
            '_transient_ai_translate_batch_trans_%',
            '_transient_timeout_ai_translate_batch_trans_%',
            '_transient_ai_translate_trans_%',
            '_transient_timeout_ai_translate_trans_%'
        ];

        foreach ($transient_patterns as $pattern) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ));
        }


        // Also clear API error/backoff transients
        delete_transient(self::API_ERROR_COUNT_TRANSIENT);
        delete_transient(self::API_BACKOFF_TRANSIENT);

        // Clear WordPress menu cache
        wp_cache_delete('alloptions', 'options');
        delete_transient('nav_menu_cache');

        // Clear all menu locations cache
        $menu_locations = get_nav_menu_locations();
        foreach ($menu_locations as $location => $menu_id) {
            wp_cache_delete($menu_id, 'nav_menu');
            wp_cache_delete($location, 'nav_menu_locations');
        }

        // Clear global UI cache for menu items
        $this->clear_global_ui_cache();
    }

    /**
     * Clear menu cache and force regeneration with new prompt logic.
     * This ensures menu items are translated without website context for short texts.
     * Slug cache wordt NIET gewist om consistentie te behouden.
     */
    public function force_menu_cache_clear(): void
    {
        // Verwijderd: $this->clear_menu_cache();

        // Also clear any cached menu items in WordPress object cache
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('nav_menu');
        }

        // Clear WordPress menu cache
        delete_transient('nav_menu');
        delete_transient('nav_menu_items');

        // Force regeneration of menu items
        if (function_exists('wp_get_nav_menu_items')) {
            $menus = wp_get_nav_menus();
            foreach ($menus as $menu) {
                wp_cache_delete($menu->term_id, 'nav_menu');
            }
        }
    }


    /**
     * Clear alleen memory cache en alle transients (database).
     * Disk cache blijft staan.
     * Slug cache wordt NIET gewist om consistentie te behouden.
     */
    public function clear_memory_and_transients(): void
    {
        global $wpdb;



        // Verwijder alle relevante transients, maar NIET slug cache
        // Use WordPress functions for safer transient deletion
        $transient_patterns = [
            '_transient_ai_translate_%',
            '_transient_timeout_ai_translate_%',
            '_transient_ai_translate_batch_trans_%',
            '_transient_timeout_ai_translate_batch_trans_%',
            '_transient_ai_translate_trans_%',
            '_transient_timeout_ai_translate_trans_%'
        ];

        foreach ($transient_patterns as $pattern) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ));
        }

        // Clear API error/backoff transients
        delete_transient(self::API_ERROR_COUNT_TRANSIENT);
        delete_transient(self::API_BACKOFF_TRANSIENT);
    }

    /**
     * Generate website context suggestion based on homepage content.
     * Analyzes the homepage to create a brief context description.
     *
     * @return string Generated context suggestion (max 100 words)
     * @throws \Exception If API call fails or content cannot be analyzed
     */
    public function generate_website_context_suggestion(): string
    {
        // Check if API is configured
        $current_api_key = $this->settings['api_keys'][$this->settings['api_provider']] ?? '';
        if (empty($current_api_key)) {
            throw new \Exception('API key is not configured. Please configure the API settings first.');
        }

        // Get homepage content
        $homepage_content = $this->get_homepage_content();
        if (empty($homepage_content)) {
            throw new \Exception('Could not retrieve homepage content. Please ensure your homepage has content.');
        }

        // Ensure proper UTF-8 encoding for the content
        if (!mb_check_encoding($homepage_content, 'UTF-8')) {
            $homepage_content = mb_convert_encoding($homepage_content, 'UTF-8', 'auto');
        }

        // Clean the content: remove null bytes and normalize whitespace
        $homepage_content = str_replace("\0", '', $homepage_content);
        $homepage_content = preg_replace('/\s+/', ' ', trim($homepage_content));

        // Prepare the prompt for context generation
        $system_prompt = 'You are an AI assistant that analyzes website content to generate a brief, professional context description. Your task is to create a concise description (maximum 100 words) that explains what the website or business is about. Focus on the main purpose, industry, and key services or topics. Write in a clear, professional tone suitable for translation context.';

        $user_prompt = "Analyze the following homepage content and generate a brief context description (max 100 words) that explains what this website or business is about:\n\n" . $homepage_content;

        $data = [
            'model' => $this->settings['selected_model'],
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $user_prompt]
            ],
            'temperature' => 0.3,
            'max_tokens' => 200,
            'frequency_penalty' => 0,
            'presence_penalty' => 0
        ];

        try {
            $response = $this->make_api_request('chat/completions', $data);
            $generated_context = $response['choices'][0]['message']['content'] ?? '';

            // Clean and validate the response
            $generated_context = trim($generated_context);
            if (empty($generated_context)) {
                throw new \Exception('Generated context is empty.');
            }

            // Ensure proper UTF-8 encoding for the generated context
            if (!mb_check_encoding($generated_context, 'UTF-8')) {
                $generated_context = mb_convert_encoding($generated_context, 'UTF-8', 'auto');
            }

            // Clean the generated context: remove null bytes and normalize whitespace
            $generated_context = str_replace("\0", '', $generated_context);
            $generated_context = preg_replace('/\s+/', ' ', $generated_context);

            // Limit to approximately 100 words
            $words = explode(' ', $generated_context);
            if (count($words) > 100) {
                $generated_context = implode(' ', array_slice($words, 0, 100));
                $generated_context = rtrim($generated_context, ',.!?') . '.';
            }

            return $generated_context;
        } catch (\Exception $e) {
            throw new \Exception('Failed to generate context: ' . $e->getMessage());
        }
    }

    /**
     * Get homepage content for context analysis.
     * Retrieves and cleans homepage content from various sources.
     *
     * @return string Cleaned homepage content
     */
    private function get_homepage_content(): string
    {
        $content_parts = [];

        // Get site title and tagline
        $site_title = get_bloginfo('name');
        $site_tagline = get_bloginfo('description');

        // Ensure proper UTF-8 encoding
        if (!mb_check_encoding($site_title, 'UTF-8')) {
            $site_title = mb_convert_encoding($site_title, 'UTF-8', 'auto');
        }
        if (!mb_check_encoding($site_tagline, 'UTF-8')) {
            $site_tagline = mb_convert_encoding($site_tagline, 'UTF-8', 'auto');
        }

        if (!empty($site_title)) {
            $content_parts[] = 'Site Title: ' . $site_title;
        }
        if (!empty($site_tagline)) {
            $content_parts[] = 'Site Description: ' . $site_tagline;
        }

        // Get homepage meta description if set
        $homepage_meta = $this->settings['homepage_meta_description'] ?? '';
        if (!empty($homepage_meta)) {
            // Ensure proper UTF-8 encoding
            if (!mb_check_encoding($homepage_meta, 'UTF-8')) {
                $homepage_meta = mb_convert_encoding($homepage_meta, 'UTF-8', 'auto');
            }
            $content_parts[] = 'Meta Description: ' . $homepage_meta;
        }

        // Get homepage post/page content
        $homepage_id = get_option('page_on_front') ?: (get_option('show_on_front') === 'posts' ? 0 : get_option('page_for_posts'));

        if ($homepage_id) {
            $homepage_post = get_post($homepage_id);
            if ($homepage_post) {
                // Get title
                if (!empty($homepage_post->post_title)) {
                    $title = $homepage_post->post_title;
                    // Ensure proper UTF-8 encoding
                    if (!mb_check_encoding($title, 'UTF-8')) {
                        $title = mb_convert_encoding($title, 'UTF-8', 'auto');
                    }
                    $content_parts[] = 'Page Title: ' . $title;
                }

                // Get content (first 500 characters)
                $content = wp_strip_all_tags($homepage_post->post_content);
                if (!empty($content)) {
                    // Ensure proper UTF-8 encoding
                    if (!mb_check_encoding($content, 'UTF-8')) {
                        $content = mb_convert_encoding($content, 'UTF-8', 'auto');
                    }
                    $content = substr($content, 0, 500);
                    $content_parts[] = 'Page Content: ' . $content;
                }
            }
        }

        // If no specific homepage, try to get recent posts content
        if (empty($content_parts) || count($content_parts) < 3) {
            $recent_posts = get_posts([
                'numberposts' => 3,
                'post_status' => 'publish'
            ]);

            foreach ($recent_posts as $post) {
                $content = wp_strip_all_tags($post->post_content);
                if (!empty($content)) {
                    // Ensure proper UTF-8 encoding
                    if (!mb_check_encoding($content, 'UTF-8')) {
                        $content = mb_convert_encoding($content, 'UTF-8', 'auto');
                    }
                    $content_parts[] = 'Recent Post: ' . substr($content, 0, 200);
                }
            }
        }

        $combined_content = implode("\n\n", $content_parts);

        // Final UTF-8 check and cleaning
        if (!mb_check_encoding($combined_content, 'UTF-8')) {
            $combined_content = mb_convert_encoding($combined_content, 'UTF-8', 'auto');
        }

        // Remove any null bytes and normalize whitespace
        $combined_content = str_replace("\0", '', $combined_content);
        $combined_content = preg_replace('/\s+/', ' ', trim($combined_content));

        return $combined_content;
    }

    /**
     * Clear the static prompt cache to force regeneration with new context.
     */
    public function clear_prompt_cache(): void
    {
        // Clear the static prompt cache by redefining the static variable
        static $prompt_cache = [];
        $prompt_cache = [];
    }

    /**
     * Get the current website context being used in prompts.
     */


    /**
     * Clear slug cache for a specific post.
     * Used when the original slug changes.
     *
     * @param int $post_id The post ID
     * @return int Number of deleted records
     */
    public function clear_slug_cache_for_post(int $post_id): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ai_translate_slugs';

        // Use WordPress functions for safer database access
        $deleted = $wpdb->delete(
            $table_name,
            ['post_id' => $post_id],
            ['%d']
        );

        return $deleted !== false ? $deleted : 0;
    }



    /**
     * Translate plugin-generated content safely.
     * This function handles various types of plugin content with proper caching and safety checks.
     *
     * @param string $content The content to translate.
     * @param string $plugin_type The type of plugin content (e.g., 'woocommerce', 'elementor', 'contact_form_7').
     * @param array $options Additional options for translation.
     * @return string The translated content.
     */
    public function translate_plugin_content(string $content, string $plugin_type = 'generic', array $options = []): string
    {
        // Skip if translation not needed
        if (!$this->needs_translation() || is_admin()) {
            return $content;
        }

        // Skip empty content
        if (empty(trim($content))) {
            return $content;
        }

        // Skip if already translated
        if (strpos($content, self::TRANSLATION_MARKER) !== false) {
            return $content;
        }

        // Prevent recursion
        static $processing_plugin_content = false;
        if ($processing_plugin_content) {
            return $content;
        }
        $processing_plugin_content = true;

        // Check if content contains HTML that should be preserved
        $contains_html = strpos($content, '<') !== false && strpos($content, '>') !== false;

        // For HTML content, use translate_template_part which handles HTML properly
        if ($contains_html) {
            $translated = $this->translate_template_part($content, 'plugin_' . $plugin_type);
        } else {
            // For plain text, use translate_text directly
            $translated = $this->translate_text(
                $content,
                $this->default_language,
                $this->get_current_language(),
                false,
                true
            );
        }

        $processing_plugin_content = false;
        return $translated;
    }

    /**
     * Get list of supported plugins for translation.
     *
     * @return array List of supported plugins with their detection methods.
     */
    public static function get_supported_plugins(): array
    {
        return [
            'woocommerce' => [
                'class' => 'WooCommerce',
                'filters' => [
                    'woocommerce_product_title',
                    'woocommerce_product_description',
                    'woocommerce_product_short_description',
                    'woocommerce_cart_item_name'
                ]
            ],
            'contact_form_7' => [
                'class' => 'WPCF7',
                'filters' => [
                    'wpcf7_form_elements'
                ]
            ],
            'elementor' => [
                'class' => 'Elementor\\Plugin',
                'filters' => [
                    'elementor/frontend/the_content'
                ]
            ],
            'divi' => [
                'function' => 'et_setup_theme',
                'filters' => [
                    'et_pb_all_fields_unprocessed'
                ]
            ],
            'beaver_builder' => [
                'class' => 'FLBuilder',
                'filters' => [
                    'fl_builder_render_module_content'
                ]
            ],
            'fluentform' => [
                'class' => 'FluentForm\\Framework\\Foundation\\Application',
                'filters' => [
                    'do_shortcode_tag'
                ]
            ]
        ];
    }

    /**
     * Check if a specific plugin is active and supported.
     *
     * @param string $plugin_name The plugin name to check.
     * @return bool True if plugin is active and supported.
     */
    public static function is_plugin_supported(string $plugin_name): bool
    {
        $supported_plugins = self::get_supported_plugins();

        if (!isset($supported_plugins[$plugin_name])) {
            return false;
        }

        $plugin_info = $supported_plugins[$plugin_name];

        // Check by class
        if (isset($plugin_info['class']) && class_exists($plugin_info['class'])) {
            return true;
        }

        // Check by function
        if (isset($plugin_info['function']) && function_exists($plugin_info['function'])) {
            return true;
        }

        return false;
    }

    /**
     * Enhance search query to translate search terms to default language
     *
     * @param string $search SQL search clause
     * @param \WP_Query $wp_query WordPress query object
     * @return string Modified search clause
     */
    public function enhance_search_query(string $search, \WP_Query $wp_query): string
    {
        // Only enhance search queries
        if (!$wp_query->is_search() || empty($wp_query->get('s'))) {
            return $search;
        }

        // Skip if we're already on the default language
        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        if ($current_language === $default_language) {
            return $search;
        }

        // Get the search terms
        $search_terms = $wp_query->get('s');

        // Translate search terms to default language
        $translated_search_terms = $this->translate_search_terms($search_terms, $current_language, $default_language);

        if (empty($translated_search_terms) || $translated_search_terms === $search_terms) {
            return $search;
        }

        global $wpdb;

        // Replace the search query with translated terms
        // This searches in Dutch content using translated search terms
        // Use WordPress functions for safer database access
        $escaped_terms = $wpdb->esc_like($translated_search_terms);
        $search = $wpdb->prepare(
            " AND ((p.post_title LIKE %s OR p.post_content LIKE %s))",
            '%' . $escaped_terms . '%',
            '%' . $escaped_terms . '%'
        );

        return $search;
    }

    /**
     * Translate search terms from current language to default language
     *
     * @param string $search_terms Search terms in current language
     * @param string $current_language Current language code
     * @param string $default_language Default language code
     * @return string Translated search terms
     */
    public function translate_search_terms(string $search_terms, string $current_language, string $default_language): string
    {
        if (empty($search_terms) || $current_language === $default_language) {
            return $search_terms;
        }

        try {
            // Direct API call voor zoektermen (omzeil de standaardtaal check)
            $translated_terms = $this->do_translate(
                $search_terms,
                $current_language,
                $default_language,
                false, // Not a title
                false   // Use disk cache
            );

            // Remove translation marker
            $translated_terms = self::remove_translation_marker($translated_terms);

            return $translated_terms;
        } catch (\Exception $e) {
            // If translation fails, return original terms
            return $search_terms;
        }
    }

    /**
     * Translate search result titles
     *
     * @param string $title Post title
     * @param int|null $post_id Post ID (optional)
     * @return string Translated title
     */
    public function translate_search_result_title(string $title, $post_id = null): string
    {
        // Only translate on search pages
        if (!is_search()) {
            return $title;
        }

        // Skip if we're already on the default language
        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        if ($current_language === $default_language) {
            return $title;
        }

        // Skip language switcher - check if we're in the footer context
        if (did_action('wp_footer') || doing_action('wp_footer')) {
            return $title;
        }

        // Check if this is already translated
        if (strpos($title, self::TRANSLATION_MARKER) !== false) {
            return self::remove_translation_marker($title);
        }

        // Translate the title
        $translated_title = $this->translate_template_part($title, 'post_title');
        return self::remove_translation_marker($translated_title);
    }

    /**
     * Translate search result excerpts
     *
     * @param string $excerpt Post excerpt
     * @param \WP_Post|null $post Post object (optional)
     * @return string Translated excerpt
     */
    public function translate_search_result_excerpt(string $excerpt, $post = null): string
    {
        // Only translate on search pages
        if (!is_search()) {
            return $excerpt;
        }

        // Skip if we're already on the default language
        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        if ($current_language === $default_language) {
            return $excerpt;
        }

        // Check if this is already translated
        if (strpos($excerpt, self::TRANSLATION_MARKER) !== false) {
            return self::remove_translation_marker($excerpt);
        }

        // Translate the excerpt
        $translated_excerpt = $this->translate_template_part($excerpt, 'post_excerpt');
        return self::remove_translation_marker($translated_excerpt);
    }

    /**
     * Translate search form
     *
     * @param string $form Search form HTML
     * @return string Translated search form
     */
    public function translate_search_form(string $form): string
    {
        // Skip if we're already on the default language
        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        if ($current_language === $default_language) {
            return $form;
        }

        // Check if this is already translated
        if (strpos($form, self::TRANSLATION_MARKER) !== false) {
            return self::remove_translation_marker($form);
        }

        // Translate placeholders in search form
        $translated_form = preg_replace_callback(
            '/placeholder=[\'"]([^\'"]+)[\'"]/i',
            function ($matches) {
                $placeholder = $matches[1];
                $translated_placeholder = $this->translate_template_part($placeholder, 'search_form');
                $translated_placeholder = self::remove_translation_marker($translated_placeholder);
                return 'placeholder="' . esc_attr($translated_placeholder) . '"';
            },
            $form
        );

        // Translate button text
        $translated_form = preg_replace_callback(
            '/<input[^>]*type=[\'"]submit[\'"][^>]*value=[\'"]([^\'"]+)[\'"][^>]*>/i',
            function ($matches) {
                $button_text = $matches[1];
                $translated_button = $this->translate_template_part($button_text, 'search_form');
                $translated_button = self::remove_translation_marker($translated_button);
                return str_replace('value="' . $matches[1] . '"', 'value="' . esc_attr($translated_button) . '"', $matches[0]);
            },
            $translated_form
        );

        // Translate label text
        $translated_form = preg_replace_callback(
            '/<label[^>]*>(.*?)<\/label>/i',
            function ($matches) {
                $label_text = $matches[1];
                $translated_label = $this->translate_template_part($label_text, 'search_form');
                $translated_label = self::remove_translation_marker($translated_label);
                return str_replace($matches[1], $translated_label, $matches[0]);
            },
            $translated_form
        );

        return $translated_form;
    }

    /**
     * Translate search query text
     *
     * @param string $query Search query text
     * @return string Translated search query text
     */
    public function translate_search_query(string $query): string
    {
        // Skip if we're already on the default language
        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        if ($current_language === $default_language) {
            return $query;
        }

        // Check if this is already translated
        if (strpos($query, self::TRANSLATION_MARKER) !== false) {
            return self::remove_translation_marker($query);
        }

        // Translate the search query text
        $translated_query = $this->translate_template_part($query, 'search_query');
        return self::remove_translation_marker($translated_query);
    }

    /**
     * Translate search page title
     *
     * @param string $title Page title
     * @return string Translated page title
     */
    public function translate_search_page_title(string $title): string
    {
        // Only translate on search pages
        if (!is_search()) {
            return $title;
        }

        // Skip if we're already on the default language
        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        if ($current_language === $default_language) {
            return $title;
        }

        // Check if this is already translated
        if (strpos($title, self::TRANSLATION_MARKER) !== false) {
            return self::remove_translation_marker($title);
        }

        // Translate the title using the normal translation function
        $translated_title = $this->translate_template_part($title, 'search_page_title');
        return self::remove_translation_marker($translated_title);
    }

    /**
     * Translate search content
     *
     * @param string $content Page content
     * @return string Translated content
     */
    public function translate_search_content(string $content): string
    {
        // Only translate on search pages
        if (!is_search()) {
            return $content;
        }

        // Skip if we're already on the default language
        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        if ($current_language === $default_language) {
            return $content;
        }

        // Check if this is already translated
        if (strpos($content, self::TRANSLATION_MARKER) !== false) {
            return self::remove_translation_marker($content);
        }

        // Translate the content using the normal translation function
        $translated_content = $this->translate_template_part($content, 'search_content');
        return self::remove_translation_marker($translated_content);
    }

    /**
     * Translate search form placeholders
     *
     * @param string $form Search form HTML
     * @return string Translated search form
     */
    public function translate_search_placeholders(string $form): string
    {
        // Skip if we're already on the default language
        $current_language = $this->get_current_language();
        $default_language = $this->default_language;

        if ($current_language === $default_language) {
            return $form;
        }

        // Check if this is already translated
        if (strpos($form, self::TRANSLATION_MARKER) !== false) {
            return self::remove_translation_marker($form);
        }

        // Translate placeholders in the form using the normal translation function
        $translated_form = preg_replace_callback(
            '/placeholder=[\'"]([^\'"]+)[\'"]/i',
            function ($matches) {
                $placeholder = $matches[1];
                $translated_placeholder = $this->translate_template_part($placeholder, 'search_form');
                $translated_placeholder = self::remove_translation_marker($translated_placeholder);
                return 'placeholder="' . esc_attr($translated_placeholder) . '"';
            },
            $form
        );

        // Translate value attributes
        $translated_form = preg_replace_callback(
            '/value=[\'"]([^\'"]+)[\'"]/i',
            function ($matches) {
                $value = $matches[1];
                $translated_value = $this->translate_template_part($value, 'search_form');
                $translated_value = self::remove_translation_marker($translated_value);
                return 'value="' . esc_attr($translated_value) . '"';
            },
            $translated_form
        );

        return $translated_form;
    }


    /**
     * Extract security tokens and dynamic content to prevent translation.
     * This function is called BEFORE translation to mask problematic elements.
     * 
     * @param string $text The text to process
     * @param array $placeholders Reference to array that stores extracted elements
     * @param int $placeholder_index Reference to placeholder counter
     * @param array $mask_types Array of types to mask: 'tokens', 'nonces', 'ip_addresses', 'emails', 'urls', 'timestamps', 'session_ids', 'js_events', 'data_attributes'
     * @return string Text with problematic elements replaced by placeholders
     */
    private function extract_security_tokens(string $text, array &$placeholders, int &$placeholder_index, array $mask_types = []): string
    {
        // Default mask types if none specified
        if (empty($mask_types)) {
            $mask_types = ['tokens', 'nonces', 'ip_addresses'];
        }

        // 0. Vervang data-content attribuut door een standaard placeholder
        $data_content_placeholder = '__AITRANSLATE_SC_' . $placeholder_index . '__';
        $text = preg_replace('/\sdata-content=("[^"]*"|\'[^\']*\')/i', ' ' . $data_content_placeholder, $text);
        $placeholder_index++;

        // Store the data-content placeholder for later restoration
        $placeholders[$data_content_placeholder] = 'data-content';

        // 1. Quotes normaliseren
        $text = str_replace(['&#8221;', '&#8243;', '&quot;', '&#34;'], '"', $text);

        // 2. Masker markers (__AITRANSLATE_XX__ etc.), ook kapotte/incomplete markers
        $text = preg_replace_callback(
            '/__AITRANSLATE(?:_SC|_FF|_SEC)?(_[0-9]+)?(__)?/',
            function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = '__AITRANSLATE_MASKED_' . $placeholder_index . '__';
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            },
            $text
        );

        // 3. Masker data-start / data-end attributen (ook als quotes niet kloppen)
        $text = preg_replace_callback(
            '/data-(start|end)\s*=\s*["\']?[^"\'>\s]+["\']?/i',
            function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = '__AIDATA_' . $placeholder_index . '__';
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            },
            $text
        );

        // 4. Masker alle Mighty Builder shortcodes (openend, sluitend, met/zonder params)
        $text = preg_replace_callback(
            '/\[(\/?mb_\w+)([^\]]*)\]/i',
            function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = '__AISHORTCODE_' . $placeholder_index . '__';
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            },
            $text
        );

        // 5. Mask WordPress nonces (alleen als 'nonces' in mask_types staat)
        if (in_array('nonces', $mask_types)) {
            $nonce_pattern = '/name=["\']_wpnonce["\'][^>]*value=["\']([^"\']+)["\']/i';
            $text = preg_replace_callback($nonce_pattern, function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = '__AITRANSLATE_' . $placeholder_index . '__';
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            }, $text);
        }


        // 6. Mask WordPress placeholders (zoals {{POSTTITLE}}) - alleen als 'tokens' in mask_types staat
        if (in_array('tokens', $mask_types)) {
            $wordpress_placeholder_pattern = '/\{\{[A-Z_]+\}\}/i';
            $text = preg_replace_callback($wordpress_placeholder_pattern, function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = '__AITRANSLATE_' . $placeholder_index . '__';
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            }, $text);
        }

        // 7. Mask overige bekende security/dynamic tokens - PROTECT COMPLETE INPUT TAGS
        if (in_array('tokens', $mask_types)) {
            $token_patterns = [
                // CSRF tokens
                '/<input[^>]*name=["\']_token["\'][^>]*>/i',
                '/<input[^>]*name=["\']csrf_token["\'][^>]*>/i',
                '/<input[^>]*name=["\']security["\'][^>]*>/i',

                // Contact Form 7 tokens
                '/<input[^>]*name=["\']_wpcf7["\'][^>]*>/i',
                '/<input[^>]*name=["\']_wpcf7_version["\'][^>]*>/i',
                '/<input[^>]*name=["\']_wpcf7_locale["\'][^>]*>/i',
                '/<input[^>]*name=["\']_wpcf7_unit_tag["\'][^>]*>/i',
                '/<input[^>]*name=["\']_wpcf7_container_post["\'][^>]*>/i',

                // WooCommerce tokens
                '/<input[^>]*name=["\']woocommerce-process-checkout-nonce["\'][^>]*>/i',
                '/<input[^>]*name=["\']woocommerce-login-nonce["\'][^>]*>/i',
                '/<input[^>]*name=["\']woocommerce-register-nonce["\'][^>]*>/i',
            ];

            foreach ($token_patterns as $pattern) {
                $text = preg_replace_callback($pattern, function ($matches) use (&$placeholders, &$placeholder_index) {
                    $placeholder = '__AITRANSLATE_' . $placeholder_index . '__';
                    $placeholders[$placeholder] = $matches[0];
                    $placeholder_index++;
                    return $placeholder;
                }, $text);
            }
        }

        // 8. Mask dynamische patronen (alleen als de specifieke types zijn opgegeven)
        $dynamic_patterns = [];
        
        if (in_array('ip_addresses', $mask_types)) {
            $dynamic_patterns[] = '/value=["\"](\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})["\"]/i';
            $dynamic_patterns[] = '/value=[\'"](\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})[\'"]/i';
        }
        
        if (in_array('emails', $mask_types)) {
            $dynamic_patterns[] = '/value=["\"]([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})["\"]/i';
        }
        
        if (in_array('urls', $mask_types)) {
            $dynamic_patterns[] = '/value=["\"](https?:\/\/[^\s"\'<>"]+)["\"]/i';
        }
        
        if (in_array('timestamps', $mask_types)) {
            $dynamic_patterns[] = '/value=["\"](\d{4}-\d{2}-\d{2})["\"]/i';
            $dynamic_patterns[] = '/value=["\"](\d{2}:\d{2}:\d{2})["\"]/i';
        }
        
        if (in_array('session_ids', $mask_types)) {
            $dynamic_patterns[] = '/value=["\"]([a-f0-9]{8,})["\"]/i';
        }
        
        if (in_array('js_events', $mask_types)) {
            $dynamic_patterns[] = '/on\w+=["\"][^"\"]*["\"]/i';
        }
        
        if (in_array('data_attributes', $mask_types)) {
            $dynamic_patterns[] = '/data-(?!form_id|form_instance|name|type|required|placeholder|value|form)\w+=["\"][^"\"]*["\"]/i';
        }

        foreach ($dynamic_patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = '__AITRANSLATE_' . $placeholder_index . '__';
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                

                
                return $placeholder;
            }, $text);
        }
        // 9. Data-content placeholder wordt nu automatisch teruggeplaatst via de placeholders array

        return $text;
    }


    /**
     * Check of de tekst uitsluitend bestaat uit echt uitgesloten shortcodes (en whitespace).
     * Controleert zowel shortcodes als gerenderde HTML van shortcodes.
     *
     * @param string $text
     * @param array $excluded_shortcodes
     * @return bool
     */
    private function is_only_excluded_shortcodes(string $text, array $excluded_shortcodes): bool
    {
        // Eerst shortcodes verwijderen
        foreach ($excluded_shortcodes as $shortcode) {
            $pattern = '/\[' . preg_quote($shortcode, '/') . '[^\]]*\](?:.*?\[\/' . preg_quote($shortcode, '/') . '\])?/is';
            $text = preg_replace($pattern, '', $text);
        }

        // Dan gerenderde HTML van uitgesloten shortcodes verwijderen
        foreach ($excluded_shortcodes as $shortcode) {
            // Speciale behandeling voor bws_google_captcha
            if ($shortcode === 'bws_google_captcha') {
                $captcha_pattern = '/<div[^>]*class="gglcptch[^"]*"[^>]*>.*?<\/div>/is';
                $text = preg_replace($captcha_pattern, '', $text);
            } elseif ($shortcode === 'chatbot') {
                // Chatbot scripts
                $chatbot_script_pattern = '/<script[^>]*>.*?kchat_settings.*?<\/script>/is';
                $text = preg_replace($chatbot_script_pattern, '', $text);

                // Chatbot HTML divs
                $chatbot_html_pattern = '/<div[^>]*id="chatbot-chatgpt[^"]*"[^>]*>.*?<\/div>/is';
                $text = preg_replace($chatbot_html_pattern, '', $text);
            } elseif ($shortcode === 'fluentform') {
                // FluentForm content - don't exclude completely, just extract dynamic parts
                // The form structure and labels should be translated
                $text = $text; // Keep the content for translation
            } else {
                // Algemene HTML pattern voor andere shortcodes
                $html_pattern = '/<div[^>]*class="[^"]*' . preg_quote($shortcode, '/') . '[^"]*"[^>]*>.*?<\/div>/is';
                $text = preg_replace($html_pattern, '', $text);
            }
        }

        $text = trim(strip_tags($text));
        return $text === '';
    }

    /**
     * Generate a readable title from a URL path.
     * Converts URL segments like "about-us" to "About Us".
     * Handles both Roman and non-Roman languages with URL decoding.
     *
     * @param string $url The URL to extract title from
     * @param string $language The target language
     * @return string The generated title, or empty string if it's the homepage
     */
    private function generate_title_from_url(string $url, string $language): string
    {
        // Parse URL and get the path
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$path || $path === '/') {
            return ''; // Homepage will be handled differently
        }

        // Get the last segment (e.g., "about-us" from "/en/about-us/")
        $segments = array_filter(explode('/', trim($path, '/')));
        if (empty($segments)) {
            return '';
        }

        $last_segment = end($segments);

        // Skip if it's just a language code
        if (strlen($last_segment) <= 3 && preg_match('/^[a-z]{2,3}$/i', $last_segment)) {
            return '';
        }

        // URL decode the segment to handle non-Roman characters
        $decoded_segment = urldecode($last_segment);

        // Check if the decoded segment contains non-Roman characters
        $has_non_roman = preg_match('/[^\x00-\x7F]/', $decoded_segment);

        // For non-Roman languages with non-Roman characters in URL, use the decoded text
        if ($has_non_roman) {
            // Convert URL-encoded text to readable title
            $title = str_replace(['-', '_'], ' ', $decoded_segment);
            // Don't use ucwords for non-Roman languages as it may not work correctly
            return $title;
        }

        // For Roman languages or URLs with only Roman characters
        $title = str_replace(['-', '_'], ' ', $decoded_segment);
        $title = ucwords($title);

        return $title;
    }

    /**
     * Voegt Open Graph meta tags toe aan de <head>.
     * Deze functie voegt og:title, og:description, og:image en og:url toe.
     */
    public function add_open_graph_meta_tags(): void
    {
        // Niet uitvoeren in admin
        if (is_admin()) {
            return;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->default_language;
        $needs_translation = $this->needs_translation();

        // --- og:title ---
        $og_title = '';
        if (is_singular()) {
            global $post;
            if ($post) {
                $og_title = get_the_title($post->ID);
            }
        } elseif (is_front_page() || is_home()) {
            $og_title = get_bloginfo('description');
        } elseif (is_archive()) {
            $og_title = get_the_archive_title();
        }

        // Vertaal de titel indien nodig
        if (!empty($og_title) && $needs_translation) {
            $og_title = $this->translate_text(
                $og_title,
                $default_lang,
                $current_lang,
                true, // $is_title = true
                true   // $use_disk_cache = true
            );
            $og_title = self::remove_translation_marker($og_title);
        }

        // --- og:description ---
        $og_description = '';
        if (is_singular()) {
            global $post;
            if ($post) {
                $manual_excerpt = get_post_field('post_excerpt', $post->ID, 'raw');
                if (!empty($manual_excerpt)) {
                    $og_description = (string) $manual_excerpt;
                } else {
                    $content = get_post_field('post_content', $post->ID, 'raw');
                    $og_description = wp_trim_words(wp_strip_all_tags(strip_shortcodes($content)), 55, '');
                }
            }
        } elseif (is_front_page() || is_home()) {
            $homepage_desc_setting = $this->settings['homepage_meta_description'] ?? '';
            if (!empty($homepage_desc_setting)) {
                $og_description = $homepage_desc_setting;
            } else {
                $og_description = (string) get_option('blogdescription', '');
            }
        }

        // Vertaal de beschrijving indien nodig
        if (!empty($og_description) && $needs_translation) {
            $og_description = $this->translate_text(
                $og_description,
                $default_lang,
                $current_lang,
                false, // $is_title = false
                true   // $use_disk_cache = true
            );
            $og_description = self::remove_translation_marker($og_description);
        }

        // Inkorten tot max 200 karakters
        if (!empty($og_description)) {
            $max_length = 200;
            $suffix = '...';
            if (mb_strlen($og_description) > $max_length) {
                $truncated = mb_substr($og_description, 0, $max_length);
                $last_space = mb_strrpos($truncated, ' ');
                if ($last_space !== false) {
                    $og_description = mb_substr($truncated, 0, $last_space) . $suffix;
                } else {
                    $og_description = mb_substr($og_description, 0, $max_length - mb_strlen($suffix)) . $suffix;
                }
            }
        }

        // --- og:image ---
        $og_image = '';
        if (is_singular()) {
            global $post;
            if ($post) {
                // Probeer eerst featured image
                if (has_post_thumbnail($post->ID)) {
                    $image_id = get_post_thumbnail_id($post->ID);
                    $image_url = wp_get_attachment_image_url($image_id, 'large');
                    if ($image_url) {
                        $og_image = $image_url;
                    }
                }

                // Fallback naar eerste afbeelding in content
                if (empty($og_image)) {
                    $content = get_post_field('post_content', $post->ID, 'raw');
                    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $matches)) {
                        $og_image = $matches[1];
                        // Zorg ervoor dat het een absolute URL is
                        if (!preg_match('/^https?:\/\//', $og_image)) {
                            $og_image = home_url($og_image);
                        }
                    }
                }
            }
        }

        // Fallback naar site logo of default image
        if (empty($og_image)) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $og_image = wp_get_attachment_image_url($custom_logo_id, 'large');
            }
        }

        // --- og:url ---
        $og_url = '';
        if (is_singular()) {
            global $post;
            if ($post) {
                $og_url = get_permalink($post->ID);
            }
        } elseif (is_front_page() || is_home()) {
            $og_url = home_url('/');
        } elseif (is_archive()) {
            $og_url = get_permalink();
        }

        // Vertaal de URL indien nodig
        if (!empty($og_url) && $needs_translation) {
            $og_url = $this->translate_url($og_url, $current_lang);
        }

        // Output de meta tags
        if (!empty($og_title)) {
            echo '<meta property="og:title" content="' . esc_attr(trim($og_title)) . '">' . "\n";
        }

        if (!empty($og_description)) {
            echo '<meta property="og:description" content="' . esc_attr(trim($og_description)) . '">' . "\n";
        }

        if (!empty($og_image)) {
            echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
        }

        if (!empty($og_url)) {
            echo '<meta property="og:url" content="' . esc_url($og_url) . '">' . "\n";
        }
    }

    /**
     * Update slug translations when a post is saved.
     * This function is called by the 'save_post' hook.
     *
     * @param int $post_id The post ID
     * @param \WP_Post $post The post object
     * @return void
     */
    public function update_slug_translations_on_save(int $post_id, \WP_Post $post): void
    {
        // Skip if this is an autosave or revision
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Skip if post is not published
        if ($post->post_status !== 'publish') {
            return;
        }

        // Get the current slug from the post
        $current_slug = $post->post_name;

        // Skip if slug is empty
        if (empty($current_slug)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'ai_translate_slugs';

        // Haal de oude slug uit de postmeta
        $old_slug = get_post_meta($post_id, '_ai_translate_original_slug', true);

        // STEP 1: VERWIJDER ALLE RECORDS VOOR DEZE POST_ID
        $wpdb->delete(
            $table_name,
            ['post_id' => $post_id],
            ['%d']
        );
        $deleted_count = $wpdb->rows_affected;

        // STEP 2: VERWIJDER ALLE RECORDS MET DE OUDE SLUG (als original_slug)
        if (!empty($old_slug) && $old_slug !== $current_slug) {
            $wpdb->delete(
                $table_name,
                ['original_slug' => $old_slug],
                ['%s']
            );
            $deleted_count = $wpdb->rows_affected;
        }

        // STEP 3: VERWIJDER ALLE RECORDS MET DE OUDE SLUG (als translated_slug)
        if (!empty($old_slug) && $old_slug !== $current_slug) {
            $wpdb->delete(
                $table_name,
                ['translated_slug' => $old_slug],
                ['%s']
            );
            $deleted_count = $wpdb->rows_affected;
        }

        // STEP 4: VERWIJDER ALLE RECORDS MET DE NIEUWE SLUG (als translated_slug)
        // Dit zorgt ervoor dat als we teruggaan naar een oude slug, alle records worden verwijderd
        $wpdb->delete(
            $table_name,
            ['translated_slug' => $current_slug],
            ['%s']
        );

        $deleted_count = $wpdb->rows_affected;

        // Update de postmeta met de huidige slug
        update_post_meta($post_id, '_ai_translate_original_slug', $current_slug);

        // Clear all cache for this post
        $settings = $this->get_settings();
        $all_languages = array_keys($this->get_available_languages());
        $default_language = $settings['default_language'] ?? 'nl';

        foreach ($all_languages as $language) {
            if ($language === $default_language) {
                continue;
            }

            // Clear memory cache voor nieuwe slug
            $cache_key = "slug_{$current_slug}_{$post->post_type}_{$default_language}_{$language}";
            unset(self::$translation_memory[$cache_key]);

            // Clear memory cache voor oude slug (als die bestaat)
            if (!empty($old_slug) && $old_slug !== $current_slug) {
                $old_cache_key = "slug_{$old_slug}_{$post->post_type}_{$default_language}_{$language}";
                unset(self::$translation_memory[$old_cache_key]);
            }

            // Clear slug text cache
            $readable_text = str_replace(['-', '_'], ' ', $current_slug);
            $readable_text = ucwords($readable_text);
            $slug_text_cache_key = "slug_text_{$readable_text}_{$default_language}_{$language}";
            unset(self::$translation_memory[$slug_text_cache_key]);

            // Clear transient cache
            $transient_key = "ai_translate_slug_{$current_slug}_{$post->post_type}_{$default_language}_{$language}";
            delete_transient($transient_key);
        }
    }

    /**
     * Determine the caching strategy based on text size and context.
     * This helps optimize cache usage while still preventing API calls.
     *
     * @param string $text The normalized text to check
     * @param string $context The context of the text (e.g., 'widget_title', 'menu_item')
     * @return string The caching strategy ('normal', 'memory_only', 'global_ui')
     */
    private function get_caching_strategy(string $text, string $context = ''): string
    {
        $length = strlen($text);

        // Voor UI elementen, gebruik normale cache (niet global_ui om cache problemen te voorkomen)
        if (in_array($context, ['widget_title', 'menu_item', 'search_placeholder'])) {
            return 'normal';
        }

        // Voor zeer korte teksten, alleen memory cache (per request)
        if ($length < 10) {
            return 'memory_only';
        }

        // Voor normale content, gebruik alle cache lagen
        return 'normal';
    }

    /**
     * Get global UI element from cache (menu items, search placeholders, etc.).
     * These elements are the same across all pages and should be cached globally.
     *
     * @param string $text The text to look up
     * @param string $target_language The target language
     * @return string|null The cached translation or null if not found
     */
    private function get_global_ui_element(string $text, string $target_language): ?string
    {
        $normalized_text = $this->normalize_text_for_cache($text);
        $cache_identifier = md5($normalized_text . $target_language);

        // MEMORY CACHE VERWIJDERD

        // 2. Check Transient Cache
        $transient_key = $this->generate_cache_key($cache_identifier, $target_language, 'trans');
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        // 3. Check Disk Cache
        $disk_key = $this->generate_cache_key($cache_identifier, $target_language, 'disk');
        $disk_cached = $this->get_cached_content($disk_key);
        if ($disk_cached !== false) {
            set_transient($transient_key, $disk_cached, $this->expiration_hours * 3600); // Update transient
            return $disk_cached;
        }

        return null; // Niet gevonden in cache
    }

    /**
     * Cache global UI element in all cache layers.
     * These elements are the same across all pages and should be cached globally.
     *
     * @param string $text The text to cache
     * @param string $target_language The target language
     * @param string $translation The translated text
     */
    private function cache_global_ui_element(string $text, string $target_language, string $translation): void
    {
        $normalized_text = $this->normalize_text_for_cache($text);
        $cache_identifier = md5($normalized_text . $target_language);

        // MEMORY CACHE VERWIJDERD

        // 2. Transient Cache (database)
        $transient_key = $this->generate_cache_key($cache_identifier, $target_language, 'trans');
        set_transient($transient_key, $translation, $this->expiration_hours * 3600);

        // 3. Disk Cache (bestanden)
        $disk_key = $this->generate_cache_key($cache_identifier, $target_language, 'disk');
        $this->save_to_cache($disk_key, $translation);
    }

    /**
     * Normalize text for consistent cache key generation.
     * This function ensures that the same text with different formatting
     * generates the same cache key.
     *
     * @param string $text The text to normalize
     * @return string The normalized text
     */
    private function normalize_text_for_cache(string $text): string
    {
        // HTML tags verwijderen
        $text = wp_strip_all_tags($text);

        // Trim whitespace
        $text = trim($text);

        return $text;
    }

    /**
     * Clear global UI cache for all languages.
     * This is called when slugs change to ensure menu items are updated.
     */
    public function clear_global_ui_cache(): void
    {
        global $wpdb;

        // Clear memory cache for global UI elements
        $all_languages = array_keys($this->get_available_languages());

        foreach ($all_languages as $language) {
            if ($language !== $this->default_language) {
                // MEMORY CACHE VERWIJDERD

                // Clear transient cache for global UI elements
                $transient_patterns = [
                    '_transient_ai_translate_trans_%',
                    '_transient_timeout_ai_translate_trans_%'
                ];

                foreach ($transient_patterns as $pattern) {
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                        $pattern
                    ));
                }

                // Clear disk cache for global UI elements
                $cache_dir = $this->get_cache_dir();
                if (is_dir($cache_dir)) {
                    $files = glob($cache_dir . '/*.cache');
                    foreach ($files as $file) {
                        if (strpos($file, 'ai_translate_trans_') !== false) {
                            unlink($file);
                        }
                    }
                }
            }
        }
    }

    /**
     * Normalize FluentForm HTML for consistent cache keys.
     * Removes dynamic values that change on each page load.
     */
    private function normalize_fluentform_for_cache(string $content): string
    {
        // Remove nonce values
        $content = preg_replace('/value="[a-f0-9]{10}"/', 'value="__NONCE__"', $content);
        
        // Remove post ID
        $content = preg_replace('/value=\'[0-9]+\'/', 'value=\'__POST_ID__\'', $content);
        
        // Remove form instance variations (keep base structure)
        $content = preg_replace('/ff_form_instance_[0-9_]+/', 'ff_form_instance_base', $content);
        
        // Remove any other dynamic IDs that might change
        $content = preg_replace('/id="[^"]*fluentform[^"]*"/', 'id="fluentform_base"', $content);
        
        return $content;
    }
}
