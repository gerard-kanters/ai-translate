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

    /** @var array<string,string> Cache voor vertalingen binnen hetzelfde request */
    private static $translation_memory = [];

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
    const API_MAX_CONSECUTIVE_ERRORS = 5; // Increased threshold

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
            case 'mem':
                return 'mem_' . $id . '_' . $lang;
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
                // Fallback or error? For now a generic key.
                return 'unknown_' . $id . '_' . $lang;
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
            'menu_item',
            'widget_title',
            'site_title',
            'tagline',
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
            'menu_item_description',
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
        bool $use_disk_cache = true
    ): string {
        // --- Essential Checks ---
        // 0. AJAX request check as the absolute first rule
        if (wp_doing_ajax()) {
            return $text;
        }

        // 0a. Never translate style tags
        if (stripos(trim($text), '<style') === 0) {
            return $text;
        }

        // 0b. Never translate hidden fields from Contact Form 7
        $cf7_hidden_fields = ['_wpcf7', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post', '_wpcf7_version'];
        if (in_array($text, $cf7_hidden_fields, true)) {
            return $text;
        }

        // 0c. Never translate chatbot scripts
        if (strpos($text, 'kchat_settings') !== false || strpos($text, 'chatbot-chatgpt') !== false) {
            return $text;
        }

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
        $shortcodes_to_exclude = self::get_always_excluded_shortcodes();
        $extracted_shortcode_pairs = [];



        // Extract shortcode pairs and replace with placeholders
        $text_with_placeholders = $this->extract_shortcode_pairs($text, $shortcodes_to_exclude, $extracted_shortcode_pairs);

        // --- Exclude rendered HTML from shortcodes that should not be translated ---
        $text_with_placeholders = $this->exclude_rendered_shortcode_html($text_with_placeholders, $extracted_shortcode_pairs);

        $text_to_translate = $text_with_placeholders;
        $shortcodes = $extracted_shortcode_pairs; // Rename for consistency with existing code

        // Detect: text consists only of excluded shortcodes?
        $only_excluded = false;
        if (!empty($shortcodes)) {
            // Strip placeholders for this check
            $stripped = trim(wp_strip_all_tags(str_replace(array_keys($shortcodes), '', $text_to_translate)));
            if ($stripped === '') {
                $only_excluded = true;
            }
        }

        // --- Strip all shortcodes before calculating the cache-key and before translation ---
        // strip_all_shortcodes_for_cache will now see the placeholders and strip them as well.
        $text_for_cache = $this->strip_all_shortcodes_for_cache($text_to_translate);
        // Use md5 of the original text + target language as the base identifier (not stripped text)
        $cache_identifier = md5($text . $target_language);
        // Generate keys with the central function
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');

        // SHORTCODE-ONLY: only in memory cache!
        if ($only_excluded) {
            if (isset(self::$translation_memory[$memory_key])) {
                $result = self::$translation_memory[$memory_key];
                if (!empty($shortcodes)) {
                    $result = $this->restore_shortcode_pairs($result, $shortcodes); // Use new restore function
                }
                return $result;
            }
            // Use original text with excluded shortcodes restored
            $result = $text; // Start with original text
            self::$translation_memory[$memory_key] = $text; // Cache original text
            return $text;
        }

        // Normal cache flow (also for mixed content)
        if (isset(self::$translation_memory[$memory_key])) {
            $result = self::$translation_memory[$memory_key];
            if (!empty($shortcodes)) {
                $result = $this->restore_shortcode_pairs($result, $shortcodes); // Use new restore function
            }
            return $result;
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
                        if (!empty($shortcodes)) {
                            $result = $this->restore_shortcode_pairs($result, $shortcodes);
                        }
                        self::$translation_memory[$memory_key] = $result; // Update memory cache
                        // Also save to transient for faster access on subsequent requests (if object cache is active)
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
                if (!empty($shortcodes)) {
                    $result = $this->restore_shortcode_pairs($result, $shortcodes);
                }
                self::$translation_memory[$memory_key] = $result;
                // If it comes from transient, also save it to disk cache (if allowed)
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

        // Remove all placeholders of the type __AITRANSLATE_PLACEHOLDER_X__ from the text and remember their positions
        $placeholder_pattern = '/__AITRANSLATE_PLACEHOLDER_\d+__/';
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

        // --- Ensure there are now NO __AITRANSLATE_PLACEHOLDER_X__ placeholders left in the input ---
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
        // ALWAYS store the result in memory cache for this request.
        self::$translation_memory[$memory_key] = $final_translated_text;

        // Store in persistent cache (transient/disk) only if the marker is present.
        if (strpos((string)$final_translated_text, self::TRANSLATION_MARKER) !== false) {
            // Store in transient
            set_transient($transient_key, $final_translated_text, $this->expiration_hours * 3600);
            // Store in disk if enabled
            if ($use_disk_cache) {
                // save_to_cache expects the key WITHOUT .cache suffix
                $this->save_to_cache($disk_cache_key, $final_translated_text);
            }
        } else {
            // If the marker is missing (API returned original text), log this but DO NOT save to transient/disk again.
            // The memory cache has already been updated.
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
            return $text;
        }

        if (empty(trim($text))) {
            // Return empty text with marker to cache the fact that it's empty and processed
            return $text . self::TRANSLATION_MARKER;
        }

        // Generate optimized system prompt with proper validation
        $system_prompt = $this->build_translation_prompt($source_language, $target_language, $is_title, $text);

        $data = [
            'model'             => $this->settings['selected_model'],
            'messages'          => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $text]
            ],
            'temperature'       => 0.2,
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
            return $text . self::TRANSLATION_MARKER;
        }
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
        // Determine if this is a short menu item (less than 50 characters)
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
        
        // Special instructions for short menu items
        $menu_instructions = '';
        if ($is_short_menu_item) {
            $menu_instructions = "\n\nMENU ITEM TRANSLATION RULES:\n- Translate ONLY the short menu text (1-3 words maximum)\n- Do NOT include any website context in the translation\n- Do NOT add explanations or additional text\n- Keep the translation concise and direct\n- Return ONLY the translated menu text, nothing else\n- If the input is 'Contact', translate to the equivalent short word in the target language\n- If the input is 'About', translate to the equivalent short word in the target language\n- If the input is 'Home', translate to the equivalent short word in the target language\n- For navigation items, use the most common and direct translation";
        }
        
        $system_prompt = sprintf(
            'You are a professional translation engine. Translate the following text from %s to %s.%s%s\n\nTRANSLATION STYLE:\n- Make the translation sound natural and professional, as if written by a native speaker\n- Adapt phrasing slightly to make it sound more persuasive and aligned with standard language on a website\n- Avoid literal translations that sound awkward or robotic\n- Use idiomatic expressions and natural word choices appropriate for the target language\n- Maintain the original tone and intent while ensuring the text flows naturally\n\nCRITICAL REQUIREMENTS:\n1. Preserve ALL HTML tags and their exact structure. Do NOT add or remove HTML tags.\n2. If input has NO HTML tags, output must also have NO HTML tags.\n3. Placeholders must remain IDENTICAL in format:  __AITRANSLATE_PLACEHOLDER_X__ stays __AITRANSLATE_PLACEHOLDER_X__ and __AITRANSLATE_SC_PLACEHOLDER_X__ stays __AITRANSLATE_SC_PLACEHOLDER_X__.\n4. If the input contains any string matching the pattern __AITRANSLATE_PLACEHOLDER_X__ or __AITRANSLATE_SC_PLACEHOLDER_X__ where X is a number, this is a non-translatable placeholder and must remain 100%% unchanged in the output. Never translate, modify, wrap, or remove these placeholders.\n5. Keep line breaks and formatting intact.\n6. Do NOT escape quotes or special characters in HTML attributes.\n7. Return ONLY the translated text. You MUST provide the translation in the target language. NEVER return text in the source language unless the target language IS the source language. If the input is empty or cannot be translated meaningfully, you MUST still attempt a translation or return the closest possible equivalent in the target language. Do NOT provide explanations or markdown formatting.\n8. If the input text contains NO HTML tags, the output text MUST also contain NO HTML tags. NEVER wrap plain text in HTML tags like <p>, <div>, <span>, or any other tags.\n9. For single words, short phrases, or slugs (especially for URLs), translate literally and directly without applying contextual interpretation.',
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
     * Translate template part. Handles memory caching (now language-specific).
     *
     * @param string|array $content Content to translate (can be array for batch types).
     * @param string $type Type of content (e.g., 'post_title', 'post_content').
     * @return string Translated content or original if no translation needed/failed.
     */
    public function translate_template_part($content, string $type)
    {
        if (!$this->needs_translation() || is_admin() || empty($content)) {
            return is_array($content) ? array_values($content) : (string)$content;
        }

        // Batch handling for specific types
        if (in_array($type, ['post_title', 'menu_item', 'widget_title'], true) && is_array($content)) {
            $target_language_batch = $this->get_current_language();
            $translated_batch = $this->batch_translate_items($content, $this->default_language, $target_language_batch, $type);

            // Fallback: if batch fails or doesn't return an array, always return an array
            if (!is_array($translated_batch) || count($translated_batch) !== count($content)) {
                return array_values($content);
            }
            return $translated_batch;
        }

        // Ensure content is a string
        if (is_array($content)) {
            $content = (string) reset($content);
        }

        $target_language = $this->get_current_language();
        $default_language = $this->default_language ?: ($this->settings['default_language'] ?? 'nl');
        $cache_identifier = md5($content . $type . $target_language);
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');

        // --- CUSTOM Memory Cache Check ---
        // If the item is in the memory cache for this request, always return it.
        // This prevents the loop if the API returns the original text.
        if (isset(self::$translation_memory[$memory_key])) {
            return self::$translation_memory[$memory_key];
        }
        // --- END CUSTOM Memory Cache Check ---

        // Houd het type bij voor cache expiry logica
        $this->last_translate_type = $type;

        $use_disk_cache = !in_array($type, ['post_title', 'menu_item', 'widget_title', 'site_title', 'tagline'], true);
        $is_title = in_array($type, ['post_title', 'menu_item', 'widget_title'], true);

        // Call translate_text, which uses do_translate and handles persistent caching
        $translated = $this->translate_text(
            $content,
            $default_language,
            $target_language,
            $is_title,
            $use_disk_cache
        );

        // Strip HTML tags from titles to prevent <p> tags in page titles
        if ($is_title || in_array($type, ['site_title', 'tagline'], true)) {
            $translated = wp_strip_all_tags($translated);
        }

        // ALWAYS update memory cache with the result (with or without marker)
        // This ensures that on subsequent calls within this request, the cache hit above works.
        self::$translation_memory[$memory_key] = $translated;


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
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');

        if (isset(self::$translation_memory[$memory_key])) {
            return self::$translation_memory[$memory_key];
        }

        // Call translate_template_part (which uses translate_text with disk cache)
        $translated = $this->translate_template_part((string)$content, 'post_content');

        // Only set in memory cache if marker is present or target language is default
        if (strpos($translated, self::TRANSLATION_MARKER) !== false || $target_language === $this->default_language) {
            self::$translation_memory[$memory_key] = $translated;
        }

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
     * Log event.
     *
     * @param string $message
     * @param string $level The log level (error, warning, info, debug).
     */
    public function log_event(string $message, string $level = 'debug'): void
    {
        $timestamp = current_time('mysql');
        $log_entry = sprintf('[%s] [%s] %s', $timestamp, strtoupper($level), $message);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log($log_entry);
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
     * Clear alle transients die bij AI Translate horen.
     */
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
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query is necessary to get transients by pattern.
            $transients_to_delete = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM " . $wpdb->prefix . "options WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                    '_transient_ai_translate_trans_%',
                    '_transient_timeout_ai_translate_trans_%',
                    '_transient_ai_translate_slug_%',
                    '_transient_timeout_ai_translate_slug_%',
                    '_transient_ai_translate_slug_cache_%',
                    '_transient_timeout_ai_translate_slug_cache_%',
                    '_transient_ai_translate_batch_trans_%',
                    '_transient_timeout_ai_translate_batch_trans_%'
                )
            );
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query is necessary for table truncation, and table name is safely prefixed.
        $wpdb->query("TRUNCATE TABLE $table_name");
    }

    /**
     * Clear slug cache for a specific language.
     *
     * @param string $language_code The language code to clear slug cache for.
     * @return int Number of deleted records.
     */
    public function clear_slug_cache_for_language(string $language_code): int
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ai_translate_slugs';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Direct query is necessary, and table name is safely prefixed.
        $deleted = $wpdb->delete(
            $table_name,
            ['language_code' => $language_code],
            ['%s']
        );
        
        return $deleted !== false ? $deleted : 0;
    }

    /**
     * Clear zowel file cache als transients en de slug cache tabel.
     */
    public function clear_all_cache(): void
    {
        $this->clear_translation_cache();
        $this->clear_transient_cache();
        $this->clear_slug_cache_table();
        $this->clear_menu_cache(); // Also clear menu cache specifically
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
                '<button class="current-lang" title="%s">%s %s <span class="arrow">&#9662;</span></button>',
                esc_attr__('Choose language', 'ai-translate'),
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
     * Batch vertaal items (bijv. titels, menu-items).
     * Gebruikt API alleen bij cache miss en meer dan 1 item.
     * @param array $items Associative array (key => text) or simple array (text).
     * @param string $source_language
     * @param string $target_language
     * @param string $type Type (e.g., 'post_title', 'menu_item'). Used for logging and context.
     * @return array Original array structure with translated values.
     */
    public function batch_translate_items(array $items, string $source_language, string $target_language, string $type): array
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

        // Gebruik hash van items + type + target_language als identifier
        $cache_identifier = md5(json_encode($items_to_translate) . $type . $target_language); // Hash van values + taal
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'batch_mem');

        // Memory Cache Check
        if (isset(self::$translation_memory[$memory_key])) {
            $cached = self::$translation_memory[$memory_key];
            // Moet een numerieke array zijn met correct aantal items
            if (is_array($cached) && count($cached) === count($items_to_translate) && array_keys($cached) === range(0, count($cached) - 1)) {
                return array_combine($original_keys, $cached); // Combineer met originele keys
            }
            unset(self::$translation_memory[$memory_key]);
        }

        // Transient Cache Check
        $transient_key = $this->generate_cache_key($cache_identifier, $target_language, 'batch_trans');
        $cached = get_transient($transient_key);
        // Moet een numerieke array zijn met correct aantal items
        if ($cached !== false && is_array($cached) && count($cached) === count($items_to_translate) && array_keys($cached) === range(0, count($cached) - 1)) {
            self::$translation_memory[$memory_key] = $cached; // Update memory cache
            return array_combine($original_keys, $cached); // Combineer met originele keys
        }

        // --- NIEUWE LOGICA: Disk Cache Check ---
        $disk_cache_key = $this->generate_cache_key($cache_identifier, $target_language, 'disk');
        $disk_cached = $this->get_cached_content($disk_cache_key);
        if ($disk_cached !== false) {
            $decoded_disk_cached = json_decode($disk_cached, true);
            if (is_array($decoded_disk_cached) && count($decoded_disk_cached) === count($items_to_translate) && array_keys($decoded_disk_cached) === range(0, count($decoded_disk_cached) - 1)) {
                self::$translation_memory[$memory_key] = $decoded_disk_cached;
                set_transient($transient_key, $decoded_disk_cached, $this->expiration_hours * 3600); // Ook in transient zetten voor snelle toegang
                return array_combine($original_keys, $decoded_disk_cached);
            }
        }
        // --- EINDE NIEUWE LOGICA ---

        // --- Cache miss ---
        // Log alleen bij cache miss

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
                            // For menu items, enforce short translations (max 50 characters)
                            if ($is_menu_batch && strlen(trim($value)) > 50) {
                                // If translation is too long, use original or create a shorter version
                                $original = $items_to_translate[$index] ?? '';
                                $words = explode(' ', trim($value));
                                if (count($words) > 3) {
                                    // Take only first 3 words for menu items
                                    $translated_array[$index] = implode(' ', array_slice($words, 0, 3));
                                } else {
                                    // If still too long, use original
                                    $translated_array[$index] = $original;
                                }
                            }
                        }
                    }

                    // Final validation: ensure we have exactly the same number of items
                    if (count($translated_array) !== count($items_to_translate)) {
                        // If count doesn't match, return original items
                        return $valid_items;
                    }

                    set_transient($transient_key, $translated_array, $this->expiration_hours * 3600);
                    self::$translation_memory[$memory_key] = $translated_array;

                    // --- NIEUWE LOGICA: Save to Disk Cache ---
                    $this->save_to_cache($disk_cache_key, json_encode($translated_array, JSON_UNESCAPED_UNICODE));
                    // --- EINDE NIEUWE LOGICA ---

                    return array_combine($original_keys, $translated_array);
                }

                $fail_reason = "Invalid JSON or item count mismatch";
                if (is_array($decoded)) $fail_reason = "Item count mismatch (expected " . count($items_to_translate) . ", got " . count($decoded['items'] ?? $decoded) . ")";
                elseif ($decoded === null) $fail_reason = "Invalid JSON response";
                // Log full API response on error
                if ($attempt === 1) {
                    $data['messages'][0]['content'] = sprintf(
                        'You are a translation engine. Translate the following text from %s to %s. ' .
                            'Preserve HTML structure, placeholders like __AITRANSLATE_PLACEHOLDER_X__, and line breaks. ' .
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
     * Cleans an HTML string by decoding entities, removing <p> tags, and stripping the translation marker.
     * This method is designed to be used on final output strings before display.
     *
     * @param string $html_string The HTML string to clean.
     * @return string The cleaned HTML string.
     */
    public function clean_html_string(string $html_string): string
    {
        // 1. Decode HTML entities (e.g., <p> to <p>)
        $cleaned_string = html_entity_decode($html_string, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Remove <p> and </p> tags
        $cleaned_string = preg_replace('/<\/?p[^>]*>/i', '', $cleaned_string);

        // 3. Remove the translation marker
        $cleaned_string = str_replace(self::TRANSLATION_MARKER, '', $cleaned_string);

        // 4. Use wp_kses_post for safety and to re-encode valid HTML entities
        // This ensures proper HTML structure and prevents XSS, and re-encodes if necessary
        $cleaned_string = wp_kses_post($cleaned_string);

        return $cleaned_string;
    }

    /**
     * Extracts shortcode pairs (opening and closing) and replaces them with a single placeholder.
     *
     * @param string $text The text containing shortcodes.
     * @param array<string> $shortcodes_to_exclude An array of shortcode tags to exclude.
     * @param array<string, string> $extracted_shortcodes Reference to an array to store extracted shortcodes.
     * @return string The text with shortcodes replaced by placeholders.
     */
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
                    $placeholder = '__AITRANSLATE_SC_PLACEHOLDER_' . $placeholder_index . '__';
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
                'wp-admin', 'wp-login', 'wp-content', 'wp-includes',
                'feed', 'comments', 'trackback', 'xmlrpc',
                'robots.txt', 'sitemap', '.htaccess', 'favicon',
                'admin', 'login', 'register', 'lost-password',
                'cron', 'cron.php', 'wp-cron.php'
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

        // Fallback for custom post types
        global $wpdb;

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

        $sql = "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish'";
        $params = [$slug];

        if ($post_type_from_path) {
            $sql .= " AND post_type = %s";
            $params[] = $post_type_from_path;
        } else {
            // If no specific post type from path, search within all allowed public post types
            $post_type_placeholders = implode(', ', array_fill(0, count($allowed_post_types), '%s'));
            $sql .= " AND post_type IN ({$post_type_placeholders})";
            $params = array_merge($params, $allowed_post_types);
        }

        $sql .= " ORDER BY post_type = 'page' DESC, post_date DESC LIMIT 1";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Query is prepared using $wpdb->prepare(), and direct query is necessary here.
        $post = $wpdb->get_row($wpdb->prepare($sql, ...$params));

        if ($post) {
            return (int)$post->ID;
        } else {
            return null;
        }
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

        // Check memory cache first
        if (isset(self::$translation_memory[$cache_key])) {
            return self::$translation_memory[$cache_key];
        }

        // Check custom database table first - dit is nu de primaire bron
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely prefixed, and direct query is necessary here.
        $db_cached_slug = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_slug FROM {$table_name} WHERE original_slug = %s AND language_code = %s AND post_id = %d",
            $original_slug,
            $target_language,
            $post_id
        ));

        if ($db_cached_slug !== null) {
            self::$translation_memory[$cache_key] = $db_cached_slug;
            return $db_cached_slug;
        }

        // Check transient cache (alleen als fallback)
        $transient_key = $this->generate_cache_key($cache_key, $target_language, 'slug_trans');
        $cached_slug = get_transient($transient_key);
        if ($cached_slug !== false) {
            // Als er een transient is, migreer deze naar de database voor permanente opslag
            if ($post_id !== null) {
                // Gebruik INSERT IGNORE om te voorkomen dat bestaande vertalingen worden overschreven
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert is an acceptable method for database interaction and does not require caching.
                $insert_result = $wpdb->insert(
                    $table_name,
                    [
                        'post_id' => $post_id,
                        'original_slug' => $original_slug,
                        'translated_slug' => $cached_slug,
                        'language_code' => $target_language,
                    ],
                    ['%d', '%s', '%s', '%s']
                );
                
                // Als insert mislukt door duplicate key, haal dan de bestaande vertaling op
                if ($insert_result === false && $wpdb->last_error && strpos($wpdb->last_error, 'Duplicate entry') !== false) {
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
            self::$translation_memory[$cache_key] = $cached_slug;
            return $cached_slug;
        }

        // Alleen vertalen als er nog geen permanente vertaling bestaat
        // Convert slug to readable text for translation
        $readable_text = str_replace(['-', '_'], ' ', $original_slug);
        $readable_text = ucwords($readable_text);

        try {
            // Translate the readable text
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

            // Cache the result in memory
            self::$translation_memory[$cache_key] = $translated_slug;

            // Save to custom database table - gebruik INSERT om te voorkomen dat bestaande vertalingen worden overschreven
            if ($post_id !== null) {
                // Controleer nogmaals of er al een vertaling bestaat (race condition voorkomen)
                $existing_slug = $wpdb->get_var($wpdb->prepare(
                    "SELECT translated_slug FROM {$table_name} WHERE original_slug = %s AND language_code = %s AND post_id = %d",
                    $original_slug,
                    $target_language,
                    $post_id
                ));
                
                if ($existing_slug === null) {
                    // Alleen inserten als er nog geen vertaling bestaat
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $wpdb->insert is an acceptable method for database interaction and does not require caching.
                    $insert_result = $wpdb->insert(
                        $table_name,
                        [
                            'post_id' => $post_id,
                            'original_slug' => $original_slug,
                            'translated_slug' => $translated_slug,
                            'language_code' => $target_language,
                        ],
                        ['%d', '%s', '%s', '%s']
                    );
                    
                    if ($insert_result === false) {
                        $this->log_event("Failed to save translated slug to DB: {$wpdb->last_error}", 'error');
                        
                        // Als insert mislukt door duplicate key, haal dan de bestaande vertaling op
                        if ($wpdb->last_error && strpos($wpdb->last_error, 'Duplicate entry') !== false) {
                            $existing_slug = $wpdb->get_var($wpdb->prepare(
                                "SELECT translated_slug FROM {$table_name} WHERE original_slug = %s AND language_code = %s AND post_id = %d",
                                $original_slug,
                                $target_language,
                                $post_id
                            ));
                            if ($existing_slug !== null) {
                                $translated_slug = $existing_slug;
                                self::$translation_memory[$cache_key] = $translated_slug;
                            }
                        }
                    }
                } else {
                    // Gebruik de bestaande vertaling
                    $translated_slug = $existing_slug;
                    self::$translation_memory[$cache_key] = $translated_slug;
                }
            }

            // Save to transient cache als backup
            set_transient($transient_key, $translated_slug, $this->expiration_hours * 3600);

            return $translated_slug;
        } catch (\Exception $e) {
            $this->log_event("AI Translate: Error translating slug '{$original_slug}': " . $e->getMessage(), 'error');
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

        foreach ($items as $index => $item) {
            // Voeg alleen niet-lege titels toe
            if (!empty($item->title)) {
                $titles_to_translate[$index] = $item->title;
            }
            // Voeg alleen niet-lege descriptions toe
            if (!empty($item->description)) {
                $descriptions_to_translate[$index] = $item->description;
            }
            // Vertaal URL direct (dit is geen API call en kan geen loop veroorzaken)
            if (isset($item->url)) {
                $item->url = $this->translate_url($item->url, $target_language);
            }
        }

        $translated_titles = null; // Initialiseer als null om falen te detecteren
        $translated_descriptions = null; // Initialiseer als null

        if (!empty($titles_to_translate)) {
            try {
                // batch_translate_items geeft array terug met dezelfde keys als input
                $translated_titles = $this->batch_translate_items($titles_to_translate, $default_language, $target_language, 'menu_item');

                // Validatie: Zorg dat het resultaat een array is met dezelfde keys als de input
                // array_diff_key geeft keys terug die in de eerste array zitten maar niet in de tweede.
                // Als dit niet leeg is, missen er keys in het resultaat.
                if (!is_array($translated_titles) || count(array_diff_key($titles_to_translate, $translated_titles)) > 0) {
                    $translated_titles = null; // Zet terug naar null bij structuurfout
                }
            } catch (\Exception $e) {
                $translated_titles = null; // Zet naar null bij exception
            }
        }

        // Batch vertaal descriptions
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
            // Verwerk titels: als vertaling beschikbaar en niet leeg, gebruik die en voeg marker toe.
            // Anders, als de oorspronkelijke titel niet leeg was, voeg marker toe aan de oorspronkelijke titel.
            $original_title = $titles_to_translate[$index] ?? $item->title;
            $new_title = (isset($translated_titles[$index]) && !empty($translated_titles[$index])) ? (string)$translated_titles[$index] : $original_title;
            if (!empty($new_title)) {
                $item->title = $this->clean_html_string($new_title); // Apply cleaning
            } else {
                $item->title = ''; // Zorg dat het leeg is als er geen content is
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

        // Geef altijd de (mogelijk deels vertaalde) items array terug
        return $items;
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

        // Use a unique identifier for this specific title and language
        $cache_identifier = md5($title . 'widget_title' . $target_language); // Add type context and language
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');


        // Als het item in de memory cache zit voor dit request, geef het *altijd* terug.
        if (isset(self::$translation_memory[$memory_key])) {
            return self::$translation_memory[$memory_key];
        }

        // Roep translate_template_part aan, die de volledige cache- en vertaallogica bevat
        // Geef 'widget_title' mee als type voor context en correcte cache-instellingen (geen disk cache)
        // Strip HTML tags vóór vertaling (alleen tekst aanbieden aan de API)
        $plain_title = wp_strip_all_tags($title);
        $translated = $this->translate_template_part($plain_title, 'widget_title');

        return $this->clean_html_string($translated); // Apply cleaning after translation
    }

    /**
     * Lijst met shortcodes die altijd uitgesloten moeten worden van vertaling (hardcoded, niet wijzigbaar via admin).
     * @return array
     */
    /**
     * Returns shortcodes that should always be excluded from translation.
     * These shortcodes (including their content) will be preserved exactly as-is.
     * 
     * @return array<string> Array of shortcode names to exclude
     */
    public static function get_always_excluded_shortcodes(): array
    {
        return [
            'mb_btn',
            'mb_col',
            'mb_heading',
            'mb_img',
            'mb_oembed',
            'mb_row',
            'mb_section',
            'mb_space',
            'mb_text',
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
     * Get all memory cache (for debugging).
     *
     * @return array
     */
    public static function get_all_memory_cache(): array
    {
        return self::$translation_memory;
    }

    /**
     * Check if a key exists in the memory cache.
     *
     * @param string $key
     * @return bool
     */
    public static function is_in_memory_cache(string $key): bool
    {
        return isset(self::$translation_memory[$key]);
    }

    /**
     * Get a value from the memory cache.
     *
     * @param string $key
     * @return mixed|null
     */
    public static function get_from_memory_cache(string $key)
    {
        return self::$translation_memory[$key] ?? null;
    }

    /**
     * Set a value in the memory cache.
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public static function set_in_memory_cache(string $key, $value): void
    {
        self::$translation_memory[$key] = $value;
    }

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
            // Try to find the post_type for the translated_slug
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table name is safely prefixed, and direct query is necessary here.
            $post = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_type FROM {$wpdb->posts}
                 WHERE post_name = %s
                 AND post_status = 'publish'
                 ORDER BY post_type = 'page' DESC, post_date DESC
                 LIMIT 1",
                $translated_slug
            ));
            if ($post) {
                return ['slug' => $translated_slug, 'post_type' => $post->post_type];
            }
            return ['slug' => $translated_slug, 'post_type' => null]; // Fallback if post_type not found
        }
        
        $table_name_slugs = $wpdb->prefix . 'ai_translate_slugs';
        
        // Apply URL decoding for UTF-8 character handling
        $decoded_translated_slug = urldecode($translated_slug);
        
        // 1. Check custom slug translation table first (exact match)
        // Try with decoded version first (most likely to match UTF-8 in database)
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
        
        // 2. Fallback: Try to find a post with this exact slug (might be already in target language or default)
        // Try with decoded version first
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_name, post_type FROM {$wpdb->posts}
             WHERE post_name = %s
             AND post_status = 'publish'
             ORDER BY post_type = 'page' DESC, post_date DESC
             LIMIT 1",
            $decoded_translated_slug
        ));
        
        // If not found, try with original encoded version
        if (!$post) {
            $post = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_name, post_type FROM {$wpdb->posts}
                 WHERE post_name = %s
                 AND post_status = 'publish'
                 ORDER BY post_type = 'page' DESC, post_date DESC
                 LIMIT 1",
                $translated_slug
            ));
        }
        
        // If still not found, try fuzzy match with decoded version
        if (!$post) {
            $post = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_name, post_type FROM {$wpdb->posts}
                 WHERE post_name LIKE %s
                 AND post_status = 'publish'
                 ORDER BY post_type = 'page' DESC, post_date DESC, LENGTH(post_name) ASC
                 LIMIT 1",
                $wpdb->esc_like($decoded_translated_slug) . '%'
            ));
        }
        
        if ($post) {
            return ['slug' => $post->post_name, 'post_type' => $post->post_type]; // Found exact match
        }

        // 3. Try to find by generating potential translated slugs
        $posts = $wpdb->get_results(
            "SELECT ID, post_name, post_type FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_name != ''
             ORDER BY post_type = 'page' DESC, post_date DESC"
        );
        
        foreach ($posts as $post) {
            // Get what this post's slug would be when translated
            $potential_translated_slug = $this->get_translated_slug(
                $post->post_name, // Dit is de originele slug in default_language
                $post->post_type,
                $default_language, // Bron is de default taal
                $source_language,   // Doel is de taal van de inkomende slug
                (int)$post->ID // Pass post ID for database lookup in get_translated_slug
            );
            
            // If the translated version matches what we're looking for (try both versions)
            if ($potential_translated_slug === $translated_slug || $potential_translated_slug === $decoded_translated_slug) {
                return ['slug' => $post->post_name, 'post_type' => $post->post_type]; // Return the original slug
            }
        }
        
        // 4. Final fallback: try to find post in default language
        $post_in_default_lang = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_name, post_type FROM {$wpdb->posts}
             WHERE post_name = %s
             AND post_status = 'publish'
             ORDER BY post_type = 'page' DESC, post_date DESC
             LIMIT 1",
            $decoded_translated_slug
        ));
        
        if (!$post_in_default_lang) {
            $post_in_default_lang = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_name, post_type FROM {$wpdb->posts}
                 WHERE post_name = %s
                 AND post_status = 'publish'
                 ORDER BY post_type = 'page' DESC, post_date DESC
                 LIMIT 1",
                $translated_slug
            ));
        }

        if ($post_in_default_lang) {
            return ['slug' => $post_in_default_lang->post_name, 'post_type' => $post_in_default_lang->post_type];
        }

        return ['slug' => $decoded_translated_slug, 'post_type' => null]; // Gebruik de gedecodeerde versie als fallback
    }

    /**
     * Conditionally adds the filter for Fluent Form output if on the contact page and translation is needed.
     */
    public function conditionally_add_fluentform_filter(): void
    {
        // Alleen op de contact pagina en als vertaling nodig is
        if (is_page('contact') && $this->needs_translation()) {
            // Voeg de filter toe met een hogere prioriteit om ervoor te zorgen dat het wordt uitgevoerd
            add_filter('do_shortcode_tag', [$this, 'filter_fluentform_shortcode_output'], 10, 3);
        }
    }

    /**
     * Filters the output of the [fluentform] shortcode to translate it.
     * Optimized version with better caching and selective translation.
     *
     * @param string $output The shortcode output.
     * @param string $tag    The shortcode tag name.
     * @param array  $attr   The shortcode attributes.
     * @return string The (potentially) translated shortcode output.
     */
    public function filter_fluentform_shortcode_output(string $output, string $tag, array $attr): string
    {
        if ('fluentform' !== $tag) {
            return $output;
        }

        if (empty(trim($output))) {
            return $output; // Geen HTML om te vertalen
        }

        // Alleen vertalen als er geen cache is
        $form_id = isset($attr['id']) ? $attr['id'] : 'unknown';
        $cache_key = 'fluentform_' . $form_id . '_' . $this->get_current_language() . '_' . md5($output);
        $cached = $this->get_cached_content($cache_key);
        
        if ($cached !== false) {
            return $cached;
        }

        // Voor Fluent Forms, vertaal de hele output maar met betere caching
        $translated_output = $this->translate_text(
            $output,
            $this->default_language,
            $this->get_current_language(),
            false,
            true
        );
        
        // Cache het resultaat
        $this->save_to_cache($cache_key, $translated_output);
        
        return $translated_output;
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

            // Redirect naar de homepage van de huidige taal
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
            } else {
                // Voor de standaard taal, redirect naar de normale homepage
                $home_url = home_url('/');
                $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
                $home_path = wp_parse_url($home_url, PHP_URL_PATH);
                if ($request_uri !== $home_path && rtrim($request_uri, '/') !== rtrim($home_path, '/')) {
                    wp_redirect($home_url, 302);
                    exit;
                }
            }
        }
    }

    /**
     * Clear menu item cache specifically.
     * This is needed to fix issues with memcached where menu items are cached in wrong language.
     */
    public function clear_menu_cache(): void
    {
        global $wpdb;
        
        // Clear menu-related transients (both old and new format)
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                '_transient_ai_translate_batch_trans_%',
                '_transient_timeout_ai_translate_batch_trans_%',
                '_transient_ai_translate_trans_%',
                '_transient_timeout_ai_translate_trans_%'
            )
        );
        
        // Clear ALL memory cache (not just menu-related)
        self::$translation_memory = [];
        
        // Clear all disk cache files (since we changed the cache key format)
        $files = glob($this->cache_dir . '/*.cache');
        if (is_array($files)) {
            foreach ($files as $file) {
                wp_delete_file($file);
            }
        }
        
        // Also clear API error/backoff transients
        delete_transient(self::API_ERROR_COUNT_TRANSIENT);
        delete_transient(self::API_BACKOFF_TRANSIENT);
    }

    /**
     * Clear menu cache and force regeneration with new prompt logic.
     * This ensures menu items are translated without website context for short texts.
     */
    public function force_menu_cache_clear(): void
    {
        $this->clear_menu_cache();
        
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
     */
    public function clear_memory_and_transients(): void
    {
        global $wpdb;
        
        // Leeg memory cache
        self::$translation_memory = [];
        
        // Verwijder alle relevante transients
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                '_transient_ai_translate_%',
                '_transient_timeout_ai_translate_%',
                '_transient_ai_translate_batch_trans_%',
                '_transient_timeout_ai_translate_batch_trans_%',
                '_transient_ai_translate_trans_%',
                '_transient_timeout_ai_translate_trans_%',
                '_transient_ai_translate_slug_%',
                '_transient_timeout_ai_translate_slug_%',
                '_transient_ai_translate_slug_cache_%',
                '_transient_timeout_ai_translate_slug_cache_%'
            )
        );
        
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
        if (empty($this->settings['api_key'])) {
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
     * Debug function to check if website context is being used in prompts.
     * This can be called to verify the context is properly included.
     */
    public function debug_prompt_with_context(string $source_language = 'nl', string $target_language = 'en'): string
    {
        return $this->build_translation_prompt($source_language, $target_language, false);
    }

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
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query is necessary, and table name is safely prefixed.
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
        
        // Generate cache key
        $cache_key = 'plugin_' . $plugin_type . '_' . $this->get_current_language() . '_' . md5($content);
        $cached = $this->get_cached_content($cache_key);
        
        if ($cached !== false) {
            $processing_plugin_content = false;
            return $cached;
        }
        
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
        
        // Cache the result
        $this->save_to_cache($cache_key, $translated);
        
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
                true   // Use disk cache
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
     * Excludeert uitgevoerde HTML van shortcodes die niet vertaald mogen worden.
     * Dit is nodig omdat shortcodes vaak al uitgevoerd zijn voordat ze bij onze vertaling komen.
     *
     * @param string $text De tekst met shortcode placeholders
     * @param array<string, string> $extracted_shortcodes Referentie naar de extracted shortcodes array
     * @return string Tekst met uitgevoerde HTML vervangen door placeholders
     */
    private function exclude_rendered_shortcode_html(string $text, array &$extracted_shortcodes): string
    {
        $placeholder_index = count($extracted_shortcodes);
        
        // Excludeer uitgevoerde HTML van shortcodes die niet vertaald mogen worden
        $excluded_shortcodes = self::get_always_excluded_shortcodes();
        
        foreach ($excluded_shortcodes as $shortcode) {
            // Speciale behandeling voor bws_google_captcha
            if ($shortcode === 'bws_google_captcha') {
                // Zoek naar de specifieke captcha HTML
                $captcha_pattern = '/<div[^>]*class="gglcptch[^"]*"[^>]*>.*?<\/div>/is';
                $text = preg_replace_callback(
                    $captcha_pattern,
                    function ($matches) use (&$extracted_shortcodes, &$placeholder_index) {
                        $placeholder = '__AITRANSLATE_RENDERED_HTML_' . $placeholder_index . '__';
                        $extracted_shortcodes[$placeholder] = $matches[0];
                        $placeholder_index++;
                        return $placeholder;
                    },
                    $text
                );
            } elseif ($shortcode === 'chatbot') {
                // Speciale behandeling voor chatbot scripts en HTML
                // Zoek naar chatbot script tags
                $chatbot_script_pattern = '/<script[^>]*>.*?kchat_settings.*?<\/script>/is';
                $text = preg_replace_callback(
                    $chatbot_script_pattern,
                    function ($matches) use (&$extracted_shortcodes, &$placeholder_index) {
                        $placeholder = '__AITRANSLATE_RENDERED_HTML_' . $placeholder_index . '__';
                        $extracted_shortcodes[$placeholder] = $matches[0];
                        $placeholder_index++;
                        return $placeholder;
                    },
                    $text
                );
                
                // Zoek naar chatbot HTML divs
                $chatbot_html_pattern = '/<div[^>]*id="chatbot-chatgpt[^"]*"[^>]*>.*?<\/div>/is';
                $text = preg_replace_callback(
                    $chatbot_html_pattern,
                    function ($matches) use (&$extracted_shortcodes, &$placeholder_index) {
                        $placeholder = '__AITRANSLATE_RENDERED_HTML_' . $placeholder_index . '__';
                        $extracted_shortcodes[$placeholder] = $matches[0];
                        $placeholder_index++;
                        return $placeholder;
                    },
                    $text
                );
            } else {
                // Zoek naar uitgevoerde HTML van andere shortcodes
                $html_pattern = '/<div[^>]*class="[^"]*' . preg_quote($shortcode, '/') . '[^"]*"[^>]*>.*?<\/div>/is';
                $text = preg_replace_callback(
                    $html_pattern,
                    function ($matches) use (&$extracted_shortcodes, &$placeholder_index) {
                        $placeholder = '__AITRANSLATE_RENDERED_HTML_' . $placeholder_index . '__';
                        $extracted_shortcodes[$placeholder] = $matches[0];
                        $placeholder_index++;
                        return $placeholder;
                    },
                    $text
                );
            }
        }
        
        return $text;
    }


}
