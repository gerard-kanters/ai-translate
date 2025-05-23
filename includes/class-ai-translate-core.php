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
        //error_log('TEST: native error_log direct in constructor');
        //$this->log_event('TEST: log_event direct aangeroepen in __construct van AI_Translate_Core');
        $this->init();
        add_action('plugins_loaded', [$this, 'schedule_cleanup']);
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
        $this->api_key = $this->settings['api_key'];
        $this->cache_dir = $this->get_cache_dir();
        $this->expiration_hours = $this->settings['cache_expiration'];
        $this->excluded_posts = $this->settings['exclude_pages'] ?? []; // Ensure it's an array

        // Initialiseer cache directories
        $this->initialize_cache_directories();
    }

    /**
     * Schedule periodic cache cleanup.
     * Runs on plugins_loaded action.
     */
    public function schedule_cleanup(): void
    {
        // Voer periodieke opschoning uit (1 op 100 kans, om performance impact te minimaliseren)
        if (\wp_rand(1, 100) === 1) {
            $this->cleanup_expired_cache();
        }
    }

    /**
     * Initialiseer de cache directories.
     * Zorg ervoor dat de benodigde mappen bestaan en schrijfbaar zijn.
     */    private function initialize_cache_directories(): void
    {
        // Controleer of het pad bestaat en maak het aan indien nodig
        if (!file_exists($this->cache_dir)) {
            $result = wp_mkdir_p($this->cache_dir);

            if ($result) {
                // Stel correcte rechten in voor Linux
                global $wp_filesystem;
                if (empty($wp_filesystem)) {
                    require_once(ABSPATH . '/wp-admin/includes/file.php');
                    WP_Filesystem();
                }
                $wp_filesystem->chmod($this->cache_dir, 0755);
            } else {
            }
        }

        // Controleer of het pad schrijfbaar is
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        if (!$wp_filesystem->is_writable($this->cache_dir)) {
            // Probeer rechten nogmaals in te stellen
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
     * Genereer een consistente cache key voor vertalingen op basis van type.
     *
     * @param string $identifier Unieke identifier (vaak een hash van de content).
     * @param string $target_language Doeltaalcode.
     * @param string $type Cache type ('mem', 'trans', 'disk', 'batch_mem', 'batch_trans').
     * @return string De gegenereerde cache key.
     */
    private function generate_cache_key(string $identifier, string $target_language, string $type): string
    {
        $lang = sanitize_key($target_language); // Zorg dat taalcode veilig is
        $id = sanitize_key($identifier); // Zorg dat identifier veilig is (hoewel vaak al hash)

        switch ($type) {
            case 'mem':
                return 'mem_' . $id . '_' . $lang;
            case 'trans':
                // Prefix voor makkelijk verwijderen van transients
                return 'ai_translate_trans_' . $id . '_' . $lang;
            case 'disk':
                // Formaat dat overeenkomt met bestaande disk cache logica ([lang]_[hash])
                return $lang . '_' . $id;
            case 'batch_mem':
                return 'batch_mem_' . $id . '_' . $lang;
            case 'batch_trans':
                // Prefix voor makkelijk verwijderen van batch transients
                return 'ai_translate_batch_trans_' . $id . '_' . $lang;
            case 'slug_trans':
                // Prefix voor slug vertaling transients
                return 'ai_translate_slug_cache_' . $id . '_' . $lang;
            default:
                // Fallback of error? Voor nu een generieke key.
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
            'api_provider'      => 'openai', // New default
            'api_key'           => '',
            'selected_model'    => 'gpt-4', // Consider making this dynamic or provider-specific later
            'default_language'  => 'nl',
            'enabled_languages' => ['en'],
            'cache_expiration'  => 168, // uren
            'exclude_pages'     => [],
            'exclude_shortcodes' => [],
            'homepage_meta_description' => '',
            'detectable_languages' => ['ja', 'zh', 'ru', 'hi', 'ka', 'sv', 'pl', 'ar', 'tr', 'fi', 'no', 'da', 'ko', 'ua'], // Default detectable
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
        if (empty($this->settings['enabled_languages'])) {
            // error_log('AI Translate: Geen talen ingeschakeld in instellingen.'); // Removed debug log
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
        $required = ['api_key', 'default_language', 'api_provider'];
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
                'ka' => 'Georgian',
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
                'ua' => 'Українська',
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
        // Gebruik DIRECTORY_SEPARATOR voor cross-platform compatibiliteit
        return $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'ai-translate' . DIRECTORY_SEPARATOR . 'cache';
    }

    /**
     * Get log directory path.
     *
     * @return string
     */    public function get_log_dir(): string
    {
        $upload_dir = wp_upload_dir();
        // Gebruik DIRECTORY_SEPARATOR voor cross-platform compatibiliteit
        return $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'ai-translate' . DIRECTORY_SEPARATOR . 'logs';
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
     * @param string $cache_key De volledige cache key (bijv. 'en_md5hash' voor disk).
     * @return string|false
     */    public function get_cached_content(string $cache_key)
    {
        // Normaliseer het pad met directory separator
        $cache_file = rtrim($this->cache_dir, '/\\') . DIRECTORY_SEPARATOR . $cache_key . '.cache';
        if (file_exists($cache_file) && !$this->is_cache_expired($cache_file)) {
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
     * @param string $cache_key De volledige cache key (bijv. 'en_md5hash' voor disk).
     * @param string $content
     * @return bool Success indicator
     */
    public function save_to_cache(string $cache_key, string $content): bool
    {
        $cache_file = $this->cache_dir . '/' . $cache_key . '.cache';

        try {
            // Controleer of de cache directory bestaat en schrijfbaar is
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            if (!$wp_filesystem->is_writable($this->cache_dir)) {
                return false;
            }

            // Schrijf naar een tijdelijk bestand en verplaats daarna (atomaire schrijfactie)
            $temp_file = $this->cache_dir . '/tmp_' . uniqid() . '.tmp';
            $write_result = file_put_contents($temp_file, $content, LOCK_EX);

            if ($write_result === false) {
                return false;
            }

            // Stel bestandsrechten in
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . '/wp-admin/includes/file.php');
                WP_Filesystem();
            }
            $wp_filesystem->chmod($temp_file, 0644);

            // Verplaats naar definitief bestand
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
                $wp_filesystem->delete($temp_file); // Opruimen
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Check if cache is expired.
     *
     * @param string $cache_file
     * @return bool
     */
    private function is_cache_expired(string $cache_file): bool
    {
        if (!file_exists($cache_file)) {
            return true;
        }

        $cache_time = filemtime($cache_file);
        $age_in_seconds = time() - $cache_time;
        $expires_in_seconds = $this->expiration_hours * 3600;

        $is_expired = $age_in_seconds > $expires_in_seconds;

        if ($is_expired) {
        }

        return $is_expired;
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
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
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
        // --- Essentiële Checks ---
        // 0. AJAX-check als absolute eerste regel
        if (wp_doing_ajax()) {
            //$this->log_event('AI Translate: AJAX request gedetecteerd, vertaling overgeslagen.', 'error');
            return $text;
        }

        // 0a. Style-tags nooit vertalen
        if (stripos(trim($text), '<style') === 0) {
            //$this->log_event('AI Translate: Style tag gedetecteerd, vertaling overgeslagen: ' . substr(trim($text), 0, 70) . '...', 'warning');
            return $text;
        }

        // 0b. Hidden velden van Contact Form 7 nooit vertalen
        $cf7_hidden_fields = ['_wpcf7', '_wpcf7_locale', '_wpcf7_unit_tag', '_wpcf7_container_post', '_wpcf7_version'];
        if (in_array($text, $cf7_hidden_fields, true)) {
            //$this->log_event('AI Translate: CF7 hidden field gedetecteerd, vertaling overgeslagen: ' . $text, 'warning');
            return $text;
        }

        // Haal de ingestelde default taal op
        $default_language = $this->settings['default_language'] ?? null;

        // 1. NIEUW: Sla vertaling over als de *doeltaal* de ingestelde default taal is.
        // We gaan ervan uit dat content in de default taal niet vertaald hoeft te worden *naar* de default taal.
        if (!empty($default_language) && $target_language === $default_language) {
            return $text;
        }

        // 2. Sla vertaling over als bron en doel (expliciet meegegeven) gelijk zijn.
        // Deze check blijft belangrijk voor gevallen waar de doeltaal *niet* de default is, maar wel gelijk aan de bron.
        if ($source_language === $target_language) {
            return $text;
        }

        // 3. Sla vertaling over voor lege tekst.
        if (empty(trim($text))) {
            return $text;
        }

        // 4. Sla vertaling over in admin area.
        if (is_admin()) {
            return $text;
        }

        // --- Einde Essentiële Checks ---

        // --- GUARD MET LOGGING: Voorkom vertaling van volledige HTML/grote scripts ---
        $trimmed_text = trim($text);
        $skip_reason = ''; // Houd bij waarom we overslaan

        if (stripos($trimmed_text, '<html') === 0) {
            $skip_reason = 'Starts with <html>';
        } elseif (stripos($trimmed_text, '<!DOCTYPE') === 0) {
            $skip_reason = 'Starts with <!DOCTYPE>';
        } elseif (stripos($trimmed_text, '<script') === 0) {
            // Controleer of dit de reden is voor het overslaan van description
            $skip_reason = 'Starts with <script>';
        } elseif (strlen($text) > 20000) { // Lengte check
            $skip_reason = 'Length > 20000';
        }

        // Als er een reden is om over te slaan, log het en stop
        if (!empty($skip_reason)) {
            $context_hint = substr(preg_replace('/\s+/', ' ', $trimmed_text), 0, 100);
            return $text; // Geef originele tekst terug
        }

        // --- EINDE GUARD MET LOGGING ---


        // --- Uitsluiten van shortcodes die in admin zijn opgegeven ---
        $extracted = $this->extract_excluded_shortcodes($text);
        $text_to_translate = $extracted['text'];
        $shortcodes = $extracted['shortcodes'];
        if (!empty($shortcodes)) {
        }

        // Detect: tekst bestaat alleen uit uitgesloten shortcodes?
        $only_excluded = false;
        if (!empty($shortcodes)) {
            $stripped = trim(wp_strip_all_tags(str_replace(array_values($shortcodes), '', $text_to_translate)));
            if ($stripped === '') {
                $only_excluded = true;
            }
        }

        // --- Strip alle shortcodes vóór het berekenen van de cache-key en vóór vertaling ---
        $text_for_cache = $this->strip_all_shortcodes_for_cache($text_to_translate);
        // Gebruik md5 van de gestripte tekst als basis identifier
        $cache_identifier = md5($text_for_cache);
        // Genereer keys met de centrale functie
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');

        // SHORTCODE-ONLY: alleen in memory cache!
        if ($only_excluded) {
            if (isset(self::$translation_memory[$memory_key])) {
                $result = self::$translation_memory[$memory_key];
                if (!empty($shortcodes)) {
                    $result = $this->restore_excluded_shortcodes($result, $shortcodes);
                }
                return $result;
            }
            // Gebruik originele tekst met uitgesloten shortcodes teruggeplaatst
            $result = $text; // Start met originele tekst
            self::$translation_memory[$memory_key] = $text; // Cache originele tekst
            return $text;
        }

        // Normale cache flow (ook voor gemixte content)
        if (isset(self::$translation_memory[$memory_key])) {
            $result = self::$translation_memory[$memory_key];
            if (!empty($shortcodes)) {
                $result = $this->restore_excluded_shortcodes($result, $shortcodes);
            }
            return $result;
        }

        // Genereer transient key
        $transient_key = $this->generate_cache_key($cache_identifier, $target_language, 'trans');
        $cached = get_transient($transient_key);
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            if (strpos($cached, self::TRANSLATION_MARKER) !== false) {
                $result = $cached;
                if (!empty($shortcodes)) {
                    $result = $this->restore_excluded_shortcodes($result, $shortcodes);
                }
                self::$translation_memory[$memory_key] = $result;
                return $result;
            } else {
            }
        }


        // --- Disk Cache Key ---
        $disk_cache_key = $this->generate_cache_key($cache_identifier, $target_language, 'disk');

        // Alleen disk cache als toegestaan
        $disk_cached = false;
        if ($use_disk_cache) {
            // get_cached_content verwacht de key ZONDER .cache suffix
            $disk_cached = $this->get_cached_content($disk_cache_key);
            if ($disk_cached !== false) {
                if (strpos($disk_cached, self::TRANSLATION_MARKER) !== false) {
                    $result = $disk_cached;
                    if (!empty($shortcodes)) {
                        $result = $this->restore_excluded_shortcodes($result, $shortcodes);
                    }
                    self::$translation_memory[$memory_key] = $result; // Update memory cache
                    set_transient($transient_key, $result, $this->expiration_hours * 3600); // Update transient
                    return $result;
                } else {
                }
            }
        } else {
        }

        // --- Extract script blocks, img tags, overige shortcodes (voor API) ---
        $placeholders = [];
        $placeholder_index = 0;

        // 1. Extract anchor tags from $text_to_translate
        $text_processed = preg_replace_callback(
            '/<a\s+[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i',
            function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = "{{LINK_PLACEHOLDER_{$placeholder_index}}}";
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            },
            $text_to_translate
        );

        // 2. Extract script blocks from $text_processed
        $text_processed = preg_replace_callback(
            '/<script.*?<\/script>/is',
            function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = "{{SCRIPT_PLACEHOLDER_{$placeholder_index}}}";
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            },
            $text_to_translate // Start processing from text without excluded shortcodes
        );

        // 3. Extract img tags from $text_processed
        $text_processed = preg_replace_callback(
            '/<img[^>]+>/i',
            function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = "{{IMG_PLACEHOLDER_{$placeholder_index}}}";
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            },
            $text_processed
        );

        // 4. Extract overige shortcodes (niet de excluded) from $text_processed
        $excluded_tags = $this->settings['exclude_shortcodes'] ?? [];
        $pattern = '/\[(\[?)([a-zA-Z0-9_\-]+)([^\]]*?)(?:\](?:.*?\[\/\2\])|\s*\/?\])/s';
        $text_processed = preg_replace_callback(
            $pattern,
            function ($matches) use (&$placeholders, &$placeholder_index, $excluded_tags) {
                $tag = isset($matches[2]) ? $matches[2] : '';
                // Skip if it's an excluded shortcode (already handled by extract_excluded_shortcodes)
                if (in_array($tag, $excluded_tags, true)) {
                    // This should technically not happen if extract_excluded_shortcodes worked correctly,
                    // but as a safeguard, return the original match.
                    return $matches[0];
                }
                $placeholder = "{{SHORTCODE_PLACEHOLDER_{$placeholder_index}}}";
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            },
            $text_processed
        );
        try {
            $translated_text_with_placeholders = $this->do_translate($text_processed, $source_language, $target_language, $is_title, $use_disk_cache);
        } catch (\Exception $e) {
            // Return original text with excluded shortcodes restored if applicable
            if (!empty($shortcodes)) {
                return $this->restore_excluded_shortcodes($text, $shortcodes); // Restore to original $text
            }
            return $text; // Return original text
        }

        // --- Restore placeholders ---
        $final_translated_text = $translated_text_with_placeholders;
        if (!empty($placeholders)) {
            // Gebruik strtr voor veiligere vervanging
            $final_translated_text = strtr($translated_text_with_placeholders, $placeholders);
        }

        // --- Restore excluded shortcodes ---
        if (!empty($shortcodes)) {
            $final_translated_text = $this->restore_excluded_shortcodes($final_translated_text, $shortcodes);
        }

        // --- Sanity check: If translation resulted in empty text but original wasn't, log critical error ---
        // Deze check blijft belangrijk, maar we retourneren $text ipv $final_translated_text als de marker mist.
        if (empty(trim(wp_strip_all_tags(str_replace(self::TRANSLATION_MARKER, '', (string)$final_translated_text)))) && !empty(trim(wp_strip_all_tags($text)))) {
            // Geen caching, geef origineel terug
            return $text;
        }

        // --- Cache de vertaalde tekst ---
        // Sla het resultaat ALTIJD op in memory cache voor dit request.
        self::$translation_memory[$memory_key] = $final_translated_text;

        // Sla op in persistent cache (transient/disk) alleen als de marker aanwezig is.
        if (strpos((string)$final_translated_text, self::TRANSLATION_MARKER) !== false) {
            // Store in transient
            set_transient($transient_key, $final_translated_text, $this->expiration_hours * 3600);
            // Store in disk if enabled
            if ($use_disk_cache) {
                // save_to_cache verwacht de key ZONDER .cache suffix
                $this->save_to_cache($disk_cache_key, $final_translated_text);
            }
        } else {
            // Als de marker ontbreekt (API gaf originele tekst terug), log dit maar sla NIET opnieuw op in transient/disk.
            // De memory cache is al bijgewerkt.
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

        // Verbeterde prompt: explicieter over vertalen en behoud structuur/placeholders
        $system_prompt = sprintf(
            'You are a translation engine. Translate the following text from %s to %s. ' .
                'Preserve HTML structure, placeholders like {{PLACEHOLDER_X}}, and line breaks. ' .
                'Return ONLY the translated text, without any additional explanations or markdown formatting. ' .
                'Even if the input seems partially translated, provide the full translation in the target language %s. ' .
                'If the input text is empty or cannot be translated meaningfully, return the original input text unchanged.',
            ucfirst($source_language),
            ucfirst($target_language),
            ucfirst($target_language) 
        );


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

        // Trim spaties van begin/eind voor betere vergelijking
        $trimmed_original = trim($text);
        $trimmed_translated = trim((string)$translated);

        // Controleer of de API een niet-lege string teruggaf
        if (!empty($trimmed_translated)) {
            // API gaf een niet-lege string terug. Voeg ALTIJD de marker toe.
            $result_with_marker = $translated . self::TRANSLATION_MARKER;

            return $result_with_marker; // Geef vertaalde of originele tekst MET marker terug
        } else {
            // API gaf lege of ongeldige content terug.
            throw new \Exception("API returned empty or invalid content.");
        }
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

        // Batch handling voor specifieke types
        if (in_array($type, ['post_title', 'menu_item', 'widget_title'], true) && is_array($content)) {
            $target_language_batch = $this->get_current_language();
            $translated_batch = $this->batch_translate_items($content, $this->default_language, $target_language_batch, $type);

            // Fallback: als batch faalt of geen array oplevert, geef altijd een array terug
            if (!is_array($translated_batch) || count($translated_batch) !== count($content)) {
                return array_values($content);
            }
            return $translated_batch;
        }

        // Zorg dat content een string is
        if (is_array($content)) {
            $content = (string) reset($content);
        }

        $target_language = $this->get_current_language();
        $default_language = $this->default_language ?: ($this->settings['default_language'] ?? 'nl');
        $cache_identifier = md5($content . $type);
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');

        // --- AANGEPASTE Memory Cache Check ---
        // Als het item in de memory cache zit voor dit request, geef het *altijd* terug.
        // Dit voorkomt de loop als de API de originele tekst teruggeeft.
        if (isset(self::$translation_memory[$memory_key])) {
            return self::$translation_memory[$memory_key];
        }
        // --- EINDE AANGEPASTE Memory Cache Check ---


        $use_disk_cache = !in_array($type, ['post_title', 'menu_item', 'widget_title'], true);
        $is_title = in_array($type, ['post_title', 'menu_item', 'widget_title'], true);

        // Roep translate_text aan, die do_translate gebruikt en persistent caching regelt
        $translated = $this->translate_text(
            $content,
            $default_language,
            $target_language,
            $is_title,
            $use_disk_cache
        );

        // Update memory cache ALTIJD met het resultaat (met of zonder marker)
        // Dit zorgt ervoor dat bij volgende aanroepen binnen dit request de cache hit hierboven werkt.
        self::$translation_memory[$memory_key] = $translated;


        return $translated;
    }

    /**
     * Translate post content (gebruik 'raw' content om filter-recursie te voorkomen).
     *
     * @param \WP_Post|null $post
     * @return string
     */
    public function translate_post_content($post = null): string
    {
        // Haal post ID en content op
        $post_id = $post ? $post->ID : get_the_ID();
        if (!$post_id) {
            return ''; // Geen post ID gevonden
        }
        $content = get_post_field('post_content', $post_id, 'raw');
        if ($content === null) {
            $content = ''; // Zorg dat het een string is
        }

        // Check of vertaling nodig is
        if (!$this->needs_translation()) {
            return $content;
        }

        $target_language = $this->get_current_language();
        // Gebruik 'post_' + ID als identifier voor post content memory cache
        $cache_identifier = 'post_' . $post_id;
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');

        if (isset(self::$translation_memory[$memory_key])) {
            return self::$translation_memory[$memory_key];
        }

        // Roep translate_template_part aan (die translate_text gebruikt met disk cache)
        $translated = $this->translate_template_part((string)$content, 'post_content');

        // Alleen in memory cache zetten als marker aanwezig is of doeltaal default is
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
                $term->name = $this->translate_text((string)$term->name, $this->default_language, $this->get_current_language(), true);
            }
        }
        return $terms;
    }

    /**
     * Log een event.
     *
     * @param string $message
     * @param string $level The log level (error, warning, info, debug).
     */
    public function log_event(string $message, string $level = 'debug'): void
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        error_log("AI Translate [{$level}][{$timestamp}]: {$message}");
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
        $cache_key = 'ai_translate_transients_to_delete';
        $transients_to_delete = wp_cache_get($cache_key);

        if (false === $transients_to_delete) {
            // Get transients to delete
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query is necessary to get transients by pattern.
            $transients_to_delete = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT option_name FROM " . $wpdb->prefix . "options WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                    '_transient_ai_translate_trans_%',
                    '_transient_timeout_ai_translate_trans_%',
                    '_transient_ai_translate_slug_%',
                    '_transient_timeout_ai_translate_slug_%',
                    '_transient_ai_translate_slug_cache_%', // Add this line for the new slug cache
                    '_transient_timeout_ai_translate_slug_cache_%' // Add this line for the new slug cache timeout
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct query is necessary for table truncation.
        $wpdb->query("TRUNCATE TABLE $table_name");
    }

    /**
     * Clear zowel file cache als transients en de slug cache tabel.
     */
    public function clear_all_cache(): void
    {
        $this->clear_translation_cache();
        $this->clear_transient_cache();
        $this->clear_slug_cache_table();
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
        // Gebruik caching voor performance
        if ($this->current_language !== null) {
            return $this->current_language;
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

        // 2. Check cookie (tweede prioriteit)
        if (isset($_COOKIE['ai_translate_lang'])) {
            $cookie_lang = sanitize_text_field(wp_unslash($_COOKIE['ai_translate_lang']));
            if (in_array($cookie_lang, $all_languages, true)) {
                $this->current_language = $cookie_lang;
                return $this->current_language;
            }
        }

        // 3. Detecteer browsertaal als geen taal in URL/cookie en detecteerbare talen zijn ingesteld
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

        // 4. Als URL geen taal bevat en we zijn niet in admin, gebruik default taal
        if (!is_admin() && !wp_doing_ajax()) {
            $this->current_language = $default_language;
            return $this->current_language;
        }

        // 5. Fallback: gebruik default taal
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
        $current_post_id = null;
        if (is_singular()) { // Check if it's a single post or page
            $current_post_id = get_the_ID();
        }

        echo '<div class="ai-translate-switcher">';
        if (isset($languages[$current_lang])) {
            printf(
                // Geen inline onclick meer, alleen HTML
                '<button class="current-lang" title="%s">%s %s <span class="arrow">&#9662;</span></button>',
                esc_attr__('Choose language', 'ai-translate'),
                sprintf('<img src="%s" alt="%s" width="20" height="15" />', esc_url(plugins_url("assets/flags/{$current_lang}.png", AI_TRANSLATE_FILE)), \esc_attr($languages[$current_lang])),
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

            if (function_exists('wp_add_inline_script')) {
                $debug_data = [
                    'base_url_input' => $base_url,
                    'lang_code_input' => $lang_code,
                    'generated_url' => $url,
                    'current_lang_php' => $this->get_current_language(),
                    'default_lang_php' => $this->default_language,
                ];
                wp_add_inline_script(
                    'ai-translate-switcher-js',
                    'aiTranslateDebug.translateUrlCalls.push(' . json_encode($debug_data) . ');',
                    'after'
                );
            }
            printf(
                '<a href="%s" class="lang-option %s" data-lang="%s">%s %s</a>',
                esc_url($url),
                $is_current ? 'active' : '',
                esc_attr($lang_code),
                sprintf('<img src="%s" alt="%s" width="20" height="15" />', esc_url(plugins_url("assets/flags/{$lang_code}.png", AI_TRANSLATE_FILE)), \esc_attr($lang_name)),
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
        return $this->translate_text($items, $source_lang, $target_lang);
    }
    /**
     * Wis alle cachebestanden voor een specifieke taal.
     *
     * @param string $lang_code Taalcode (bijv. 'en', 'de'). Moet gevalideerd zijn.
     * @return int Aantal verwijderde bestanden
     */    public function clear_cache_for_language(string $lang_code): int
    {
        // Basis validatie
        if (empty($lang_code) || !preg_match('/^[a-z]{2,3}(?:-[a-z]{2,4})?$/i', $lang_code)) {
            $error = "Ongeldige taalcode '$lang_code' opgegeven voor cache wissen.";
            throw new \InvalidArgumentException(esc_html($error));
        }

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
                } else {
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

        // --- Start Batch Processing ---
        $original_keys = array_keys($items); // Bewaar originele keys
        $items_to_translate = array_values($items); // Werk met numerieke array voor API

        // Gebruik hash van items + type als identifier
        $cache_identifier = md5(json_encode($items_to_translate) . $type); // Hash van values
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

        // --- Cache miss ---
        // Log alleen bij cache miss

        $system_prompt = sprintf(
            'You are a translation engine. Translate the following text from %s to %s. ' .
                'Preserve HTML structure, placeholders like {{PLACEHOLDER_X}}, and line breaks. ' .
                'Return ONLY the translated text, without any additional explanations or markdown formatting. ' .
                'Even if the input seems partially translated, provide the full translation in the target language %s. ' .
                'If the input text is empty or cannot be translated meaningfully, return the original input text unchanged.',
            ucfirst($source_language),
            ucfirst($target_language)
        );

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
                    foreach ($translated_array as $index => $value) {
                        if (!is_string($value)) {
                            $translated_array[$index] = isset($items_to_translate[$index]) && is_string($items_to_translate[$index]) ? $items_to_translate[$index] : '';
                        }
                    }

                    set_transient($transient_key, $translated_array, $this->expiration_hours * 3600);
                    self::$translation_memory[$memory_key] = $translated_array;
                    return array_combine($original_keys, $translated_array);
                }

                $fail_reason = "Invalid JSON or item count mismatch";
                if (is_array($decoded)) $fail_reason = "Item count mismatch (expected " . count($items_to_translate) . ", got " . count($decoded['items'] ?? $decoded) . ")";
                elseif ($decoded === null) $fail_reason = "Invalid JSON response";
                // Log volledige API response bij fout

                if ($attempt === 1) {
                    $data['messages'][0]['content'] = sprintf(
                        'You are a translation engine. Translate the following text from %s to %s. ' .
                            'Preserve HTML structure, placeholders like {{PLACEHOLDER_X}}, and line breaks. ' .
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

        return $items;
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
                if (is_file($file) && $this->is_cache_expired($file)) {
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
     * Haal shortcodes uit tekst die niet vertaald mogen worden.
     *
     * @param string $text
     * @return array [ 'text' => string, 'shortcodes' => array ]
     */
    public function extract_excluded_shortcodes($text)
    {
        $settings = $this->get_settings();
        $exclude = isset($settings['exclude_shortcodes']) && is_array($settings['exclude_shortcodes'])
            ? $settings['exclude_shortcodes']
            : [];
        // Voeg altijd de hardcoded shortcodes toe
        $exclude = array_unique(array_merge($exclude, self::get_always_excluded_shortcodes()));
        if (empty($exclude)) {
            return ['text' => $text, 'shortcodes' => []];
        }
        $shortcodes = [];
        $pattern = $this->get_shortcode_regex($exclude);
        $text_without = preg_replace_callback("/$pattern/", function ($m) use (&$shortcodes) {
            $placeholder = '[[[AI_TRANSLATE_SC_' . count($shortcodes) . ']]]';
            $shortcodes[$placeholder] = $m[0];
            return $placeholder;
        }, $text);
        return ['text' => $text_without, 'shortcodes' => $shortcodes];
    }

    /**
     * Zet shortcodes terug in tekst na vertaling.
     *
     * @param string $text
     * @param array $shortcodes
     * @return string
     */
    public function restore_excluded_shortcodes($text, $shortcodes)
    {
        if (empty($shortcodes)) {
            return $text;
        }
        $text = strtr($text, $shortcodes);
        return $text;
    }

    /**
     * Genereer regex voor opgegeven shortcodes.
     *
     * @param array<string> $shortcodes
     * @return string
     */
    private function get_shortcode_regex(array $shortcodes): string
    {
        $tagnames = array_map('preg_quote', $shortcodes);
        $tagregexp = join('|', $tagnames);
        // Gebaseerd op get_shortcode_regex() van WP, maar alleen voor opgegeven tags
        // Modified regex to use a simpler non-greedy match for content within enclosing shortcodes
        return '\\[(\\[?)(' . $tagregexp . ')(?![\\w-])([^[\\]]*?)(?:((?:\\/(?!\\]))?\\])|\\](?:(.*?)\\[\\/\\2\\])?)(\\]?)';
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

    public function translate_url(string $url, string $language, ?int $post_id = null): string
    {
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

        $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
        $host = $parsed_url['host'] ?? '';
        $port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $query = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

        $new_url = $scheme . $host . $port . $new_path . $query . $fragment;

        return $new_url;
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
                    $original_slug = $path_parts[count($path_parts) - 1];
                    $path_parts[count($path_parts) - 1] = $translated_slug;
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
        // Check if a known CPT slug is present in the path
        // Assuming 'service' and 'product' are the CPT slugs
        if (count($path_segments) >= 2) {
            $potential_post_type_slug = $path_segments[count($path_segments) - 2];
            if ($potential_post_type_slug === 'service') {
                $post_type_from_path = 'service';
            } elseif ($potential_post_type_slug === 'product') {
                $post_type_from_path = 'product';
            }
        }

        $query = "SELECT ID, post_type FROM {$wpdb->posts} WHERE post_name = %s AND post_status = 'publish'";
        $params = [$slug];

        if ($post_type_from_path) {
            $query .= " AND post_type = %s";
            $params[] = $post_type_from_path;
        } else {
            // If no specific post type from path, prioritize pages and then posts
            $query .= " AND post_type IN ('post', 'page', 'service', 'product')"; // Include known CPTs
        }

        $query .= " ORDER BY post_type = 'page' DESC, post_date DESC LIMIT 1";

        $post = $wpdb->get_row($wpdb->prepare($query, ...$params));

        return $post ? (int)$post->ID : null;
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

        // Check custom database table first
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

        // Check transient cache
        $transient_key = $this->generate_cache_key($cache_key, $target_language, 'slug_trans');
        $cached_slug = get_transient($transient_key);
        if ($cached_slug !== false) {
            self::$translation_memory[$cache_key] = $cached_slug;
            return $cached_slug;
        }

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

            // Save to custom database table
            if ($post_id !== null) {
                $replace_result = $wpdb->replace(
                    $table_name,
                    [
                        'post_id' => $post_id,
                        'original_slug' => $original_slug,
                        'translated_slug' => $translated_slug,
                        'language_code' => $target_language,
                    ],
                    ['%d', '%s', '%s', '%s']
                );
                if ($replace_result === false) {
                    $this->log_event("Failed to save translated slug to DB: {$wpdb->last_error}", 'error');
                } else {
                    // Successfully saved, no need to log debug
                }
            } else {
                // Not saving to DB because post_id is null, no need to log debug
            }

            // Save to transient cache
            set_transient($transient_key, $translated_slug, $this->expiration_hours * 3600);

            return $translated_slug;
        } catch (\Exception $e) {
            $this->log_event("Error translating slug '{$original_slug}': " . $e->getMessage(), 'error');
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

        // Batch vertaal titels
        if (!empty($titles_to_translate)) {
            try {
                // batch_translate_items geeft array terug met dezelfde keys als input
                $translated_titles = $this->batch_translate_items($titles_to_translate, $default_language, $target_language, 'menu_item');

                // Validatie: Zorg dat het resultaat een array is met dezelfde keys als de input
                // array_diff_key geeft keys terug die in de eerste array zitten maar niet in de tweede.
                // Als dit niet leeg is, missen er keys in het resultaat.
                if (!is_array($translated_titles) || count(array_diff_key($titles_to_translate, $translated_titles)) > 0) {
                    $translated_titles = null; // Zet terug naar null bij structuurfout
                } else {
                }
            } catch (\Exception $e) {
                // Log de exception die optrad tijdens de batch call
                $this->log_event("Exception during batch translation for menu titles: " . $e->getMessage(), 'error');
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
                } else {
                }
            } catch (\Exception $e) {
                $this->log_event("Exception during batch translation for menu descriptions: " . $e->getMessage(), 'error');
                $translated_descriptions = null;
            }
        }

        // Map de vertalingen terug naar de items, alleen als de respectievelijke batch succesvol was
        foreach ($items as $index => $item) {
            // Update titel als deze succesvol vertaald is (resultaat is niet null)
            if ($translated_titles !== null && isset($titles_to_translate[$index]) && isset($translated_titles[$index])) {
                $original_title = $titles_to_translate[$index];
                $translated_title = $translated_titles[$index];
                // Voeg marker toe als de vertaling daadwerkelijk verschilt
                if ($translated_title !== $original_title) {
                    $item->title = $translated_title . self::TRANSLATION_MARKER;
                } else {
                    $item->title = $translated_title; // Geen marker als gelijk aan origineel
                }
            }
            // Update description als deze succesvol vertaald is (resultaat is niet null)
            if ($translated_descriptions !== null && isset($descriptions_to_translate[$index]) && isset($translated_descriptions[$index])) {
                $original_description = $descriptions_to_translate[$index];
                $translated_description = $translated_descriptions[$index];
                // Voeg marker toe als de vertaling daadwerkelijk verschilt
                if ($translated_description !== $original_description) {
                    $item->description = $translated_description . self::TRANSLATION_MARKER;
                } else {
                    $item->description = $translated_description; // Geen marker als gelijk aan origineel
                }
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
        $cache_identifier = md5($title . 'widget_title'); // Add type context
        $memory_key = $this->generate_cache_key($cache_identifier, $target_language, 'mem');


        // Als het item in de memory cache zit voor dit request, geef het *altijd* terug.
        if (isset(self::$translation_memory[$memory_key])) {
            return self::$translation_memory[$memory_key];
        }

        // Roep translate_template_part aan, die de volledige cache- en vertaallogica bevat
        // Geef 'widget_title' mee als type voor context en correcte cache-instellingen (geen disk cache)
        $translated = $this->translate_template_part($title, 'widget_title');

        return $translated;
    }

    /**
     * Lijst met shortcodes die altijd uitgesloten moeten worden van vertaling (hardcoded, niet wijzigbaar via admin).
     * @return array
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
                    $is_expired = $this->is_cache_expired($file);

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
     * @param string $api_url The API URL to validate
     * @param string $api_key The API key to validate
     * @return array<string,mixed> Response data including models if successful
     * @throws \Exception If validation fails
     */
    public function validate_api_settings(string $provider_key, string $api_key): array
    {
        if (empty($provider_key) || empty($api_key)) {
            throw new \InvalidArgumentException('Provider key of API key is leeg.');
        }

        $api_url = self::get_api_url_for_provider($provider_key);
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
        // Log the raw translated_slug before any processing
        // No explicit urldecode() here, let WordPress handle it or rely on database matching
        $settings = $this->get_settings();
        $default_language = $settings['default_language'] ?? 'nl';
        // Skip if same language
        if ($source_language === $target_language) {
            // Try to find the post_type for the translated_slug
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
        // 1. Check custom slug translation table first (exact match)
        $decoded_translated_slug = $translated_slug; // Verwijder urldecode, gebruik de raw URL-encoded slug

        $cached_original_slug_data = $wpdb->get_row($wpdb->prepare(
            "SELECT post_id, original_slug FROM {$table_name_slugs}
             WHERE translated_slug = %s AND language_code = %s",
            $decoded_translated_slug, // Gebruik de gedecodeerde versie
            $source_language
        ));
        if (!$cached_original_slug_data) {
            // Try fuzzy match if exact match fails
            $cached_original_slug_data = $wpdb->get_row($wpdb->prepare(
                "SELECT post_id, original_slug FROM {$table_name_slugs}
                 WHERE translated_slug LIKE %s AND language_code = %s
                 ORDER BY LENGTH(translated_slug) ASC LIMIT 1", // Prefer shorter match
                $wpdb->esc_like($decoded_translated_slug) . '%', // Gebruik de gedecodeerde versie
                $source_language
            ));
        }
        if ($cached_original_slug_data) {
            $post = get_post($cached_original_slug_data->post_id);
            if ($post) {
                return ['slug' => $cached_original_slug_data->original_slug, 'post_type' => $post->post_type];
            } else {
                $this->log_event("reverse_translate_slug: Post not found for ID {$cached_original_slug_data->post_id} from custom slug table.", 'warning');
            }
        }
        // 2. Fallback: Try to find a post with this exact slug (might be already in target language or default)
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_name, post_type FROM {$wpdb->posts}
             WHERE post_name = %s
             AND post_status = 'publish'
             ORDER BY post_type = 'page' DESC, post_date DESC
             LIMIT 1",
            $decoded_translated_slug // Gebruik de gedecodeerde versie
        ));
        if (!$post) {
            // Try fuzzy match if exact match fails
            $post = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_name, post_type FROM {$wpdb->posts}
                 WHERE post_name LIKE %s
                 AND post_status = 'publish'
                 ORDER BY post_type = 'page' DESC, post_date DESC, LENGTH(post_name) ASC
                 LIMIT 1", // Prefer kortere match
                $wpdb->esc_like($decoded_translated_slug) . '%' // Gebruik de gedecodeerde versie
            ));
        }
        if ($post) {
            return ['slug' => $post->post_name, 'post_type' => $post->post_type]; // Found exact match
        }
        // 3. Fallback: If no exact match, search through all published posts to find one whose translated slug matches
        // This is the most expensive operation and should be a last resort.
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
            // If the translated version matches what we're looking for
            if ($potential_translated_slug === $translated_slug) {
                return ['slug' => $post->post_name, 'post_type' => $post->post_type]; // Return the original slug
            }
        }
        // No match found, fallback to original slug to prevent 404
        //$this->log_event("reverse_translate_slug: No match found after all lookups for '{$translated_slug}'. Falling back to original slug.", 'warning');

        // NIEUWE FALLBACK LOGICA
        // Probeer de post te vinden op basis van de gedecodeerde inkomende slug in de default taal
        $post_in_default_lang = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_name, post_type FROM {$wpdb->posts}
             WHERE post_name = %s
             AND post_status = 'publish'
             ORDER BY post_type = 'page' DESC, post_date DESC
             LIMIT 1",
            $decoded_translated_slug // Gebruik de gedecodeerde versie
        ));

        if ($post_in_default_lang) {
            // Als een post wordt gevonden met de gedecodeerde slug in de default taal,
            // retourneer dan de originele slug van die post.
            return ['slug' => $post_in_default_lang->post_name, 'post_type' => $post_in_default_lang->post_type];
        }

        // Als zelfs dat niet werkt, val dan terug op de gedecodeerde inkomende slug.
        // Dit zal waarschijnlijk nog steeds een 404 zijn, maar het is de laatste poging.
        return ['slug' => $decoded_translated_slug, 'post_type' => null]; // Gebruik de gedecodeerde versie
    }
}
