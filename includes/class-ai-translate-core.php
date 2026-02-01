<?php

namespace AITranslate;

/**
 * Core services for AI Translate.
 * Provides provider config, validation helpers, and cache management used by admin UI and runtime.
 */
final class AI_Translate_Core
{
    /** @var AI_Translate_Core|null */
    private static $instance;

    /**
     * Singleton accessor.
     *
     * @return AI_Translate_Core
     */
    public static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * List of supported API providers.
     *
     * @return array<string,array{name:string,base_url:string}>
     */
    public static function get_api_providers()
    {
        return [
            'openai' => [ 'name' => 'OpenAI/ChatGPT', 'base_url' => 'https://api.openai.com/v1' ],
            'deepseek' => [ 'name' => 'DeepSeek', 'base_url' => 'https://api.deepseek.com/v1' ],
            'openrouter' => [ 'name' => 'OpenRouter', 'base_url' => 'https://openrouter.ai/api/v1' ],
            'groq' => [ 'name' => 'Groq', 'base_url' => 'https://api.groq.com/openai/v1' ],
            'deepinfra' => [ 'name' => 'DeepInfra', 'base_url' => 'https://api.deepinfra.com/v1/openai' ],
            'custom' => [ 'name' => 'Custom API', 'base_url' => '' ],
        ];
    }

    /**
     * Resolve API base URL for a provider.
     *
     * @param string $provider
     * @return string
     */
    public static function get_api_url_for_provider($provider)
    {
        $providers = self::get_api_providers();
        if (isset($providers[$provider])) {
            return (string) ($providers[$provider]['base_url'] ?? '');
        }
        return '';
    }

    /**
     * Validate API settings by performing a light request to the provider.
     * - For OpenAI-compatible APIs: GET /models with Bearer key.
     * - Optionally tests chat/completions endpoint with the selected model.
     * - Throws \Exception on failure.
     *
     * @param string $provider_key
     * @param string $api_key
     * @param string $custom_api_url
     * @param string $model Optional model to test with chat/completions
     * @return array{ok:bool}
     * @throws \Exception
     */
    public function validate_api_settings($provider_key, $api_key, $custom_api_url = '', $model = '')
    {
        $provider_key = (string) $provider_key;
        $api_key = (string) $api_key;
        $custom_api_url = (string) $custom_api_url;
        $model = (string) $model;

        if ($provider_key === '') {
            throw new \Exception('Provider ontbreekt');
        }
        if ($api_key === '') {
            throw new \Exception('API key ontbreekt');
        }

        $base = self::get_api_url_for_provider($provider_key);
        if ($provider_key === 'custom') {
            $base = $custom_api_url;
        }
        if ($base === '') {
            throw new \Exception('API URL ontbreekt');
        }

        // Custom API: reject Google API URL (not OpenAI-compatible)
        if ($provider_key === 'custom' && strpos($custom_api_url, 'googleapis.com') !== false) {
            throw new \Exception(__('Google API is not OpenAI compatible. Use Openrouter or Deepinfra for Gemini.', 'ai-translate'));
        }

        $endpoint = rtrim($base, '/') . '/models';
        $headers = [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ];
        // OpenRouter requires Referer header
        if ($provider_key === 'custom' && strpos($custom_api_url, 'openrouter.ai') !== false) {
            $headers['HTTP-Referer'] = 'https://github.com/gerard-kanters/ai-translate';
            $headers['X-Title'] = 'AI Translate';
        }
        $resp = wp_remote_get($endpoint, [
            'headers' => $headers,
            'timeout' => 15,
            'sslverify' => true,
        ]);
        if (is_wp_error($resp)) {
            throw new \Exception($resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
            $body = (string) wp_remote_retrieve_body($resp);
            throw new \Exception('HTTP ' . $code . ' ' . $body);
        }
        $body = (string) wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \Exception('Ongeldig antwoord van API');
        }

        // If model is provided, check if it exists in the models list first
        if ($model !== '') {
            $modelFound = false;
            $modelData = null;
            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $m) {
                    if (is_array($m) && isset($m['id']) && $m['id'] === $model) {
                        $modelFound = true;
                        $modelData = $m;
                        break;
                    }
                }
            }
            if (!$modelFound) {
                throw new \Exception('Model "' . $model . '" not found in available models list. Please check the model name.');
            }
        }

        // If model is provided, test chat/completions endpoint to ensure model is actually usable
        if ($model !== '') {
            $chatEndpoint = rtrim($base, '/') . '/chat/completions';
            $chatHeaders = [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ];
            // OpenRouter requires Referer header
            if ($provider_key === 'custom' && strpos($custom_api_url, 'openrouter.ai') !== false) {
                $chatHeaders['HTTP-Referer'] = 'https://github.com/gerard-kanters/ai-translate';
                $chatHeaders['X-Title'] = 'AI Translate';
            }
            $chatBody = [
                'model' => $model,
                'messages' => [
                    ['role' => 'user', 'content' => 'Test'],
                ],
            ];
            if ($provider_key === 'openrouter' || ($provider_key === 'custom' && strpos($custom_api_url, 'openrouter.ai') !== false)) {
                $chatBody['user'] = !empty($domain) ? $domain : parse_url(home_url(), PHP_URL_HOST);
            }
            // Chat test: one cap for all providers (OpenAI/OpenRouter require minimum 16)
            $chatTestMaxTokens = 32;
            // Newer models (gpt-5.x, o1-series, o3-series) use max_completion_tokens; others use max_tokens
            // DeepSeek v3.2 doesn't require either (per documentation)
            if (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o1-') || str_starts_with($model, 'o3-')) {
                $chatBody['max_completion_tokens'] = $chatTestMaxTokens;
                // GPT-5 models: use minimal reasoning for fast validation
                if (str_starts_with($model, 'gpt-5')) {
                    $chatBody['reasoning_effort'] = 'minimal';
                }
            } elseif (!str_starts_with($model, 'deepseek/deepseek-v3')) {
                $chatBody['max_tokens'] = $chatTestMaxTokens;
                $chatBody['temperature'] = 0;
            }
            $chatResp = wp_remote_post($chatEndpoint, [
                'headers' => $chatHeaders,
                'timeout' => 20,
                'sslverify' => true,
                'body' => wp_json_encode($chatBody),
            ]);
            if (is_wp_error($chatResp)) {
                throw new \Exception('Chat test failed: ' . $chatResp->get_error_message());
            }
            $chatCode = (int) wp_remote_retrieve_response_code($chatResp);
            if ($chatCode !== 200) {
                $chatBodyText = (string) wp_remote_retrieve_body($chatResp);
                throw new \Exception('Chat test failed (HTTP ' . $chatCode . '): ' . substr($chatBodyText, 0, 500));
            }
        }

        return ['ok' => true];
    }

    /**
     * Return available languages (code => label).
     *
     * @return array<string,string>
     */
    public function get_available_languages()
    {
        // Extensive but curated list aligned with assets/flags
        return [
            'en' => 'English',
            'nl' => 'Dutch',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'pl' => 'Polish',
            'cs' => 'Czech',
            'da' => 'Danish',
            'fi' => 'Finnish',
            'sv' => 'Swedish',
            'no' => 'Norwegian',
            'ro' => 'Romanian',
            'ru' => 'Russian',
            'uk' => 'Ukrainian',
            'tr' => 'Turkish',
            'el' => 'Greek',
            'hu' => 'Hungarian',
            'bg' => 'Bulgarian',
            'ar' => 'Arabic',
            'he' => 'Hebrew',
            'hi' => 'Hindi',
            'th' => 'Thai',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'ka' => 'Georgian',
            'kk' => 'Kazakh',
            'et' => 'Estonian',
            'ga' => 'Irish',
            'hr' => 'Croatian',
            'lv' => 'Latvian',
            'lt' => 'Lithuanian',
            'mt' => 'Maltese',
            'sl' => 'Slovenian',
            'sk' => 'Slovak',
            'id' => 'Indonesian',
        ];
    }

    /**
     * Clear translation cache for a language.
     * Returns stats with success, count and optional warning.
     *
     * @param string $lang
     * @return array{success:bool,count:int,warning?:string}
     */
    public function clear_cache_for_language($lang)
    {
        $lang = sanitize_key((string) $lang);
        
        // Clear disk cache for this language
        $uploads = wp_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
        
        // Add site-specific directory if multi-domain caching is enabled
        $site_dir = $this->get_site_cache_dir();
        if (!empty($site_dir)) {
            $base = trailingslashit($base) . $site_dir . '/';
        }
        
        $base = trailingslashit($base) . $lang . '/pages/';
        $count = 0;
        if (is_dir($base)) {
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rii as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    @unlink($file->getPathname());
                    $count++;
                }
            }
            // Remove empty directories
            foreach ($rii as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                }
            }
        }

        \AITranslate\AI_Cache_Meta::delete_by_path_prefix($base);
        
        // Clear segment translation transients for this language
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_ai_tr_seg_' . $lang . '_%',
            '_transient_timeout_ai_tr_seg_' . $lang . '_%'
        ));
        
        return ['success' => true, 'count' => $count];
    }

    /**
     * Clear all caches except slug map (DB table).
     */
    public function clear_all_cache_except_slugs()
    {
        $this->clear_all_cache(true);
    }

    /**
     * Clear memory/transient caches except slugs.
     */
    public function clear_memory_and_transients_except_slugs()
    {
        global $wpdb;
        // Clear all transients (including segment translations ai_tr_seg_* and menu items ai_tr_attr_*)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE %s
            OR option_name LIKE %s",
            '_transient_%',
            '_site_transient_%'
        ));
        // In-memory nothing persistent beyond this request
        wp_cache_flush();
    }

    /**
     * Clear all caches (disk + transients); optionally preserve slugs.
     *
     * @param bool $preserve_slugs
     * @return void
     */
    public function clear_all_cache($preserve_slugs = false)
    {
        $uploads = wp_upload_dir();
        $root = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
        
        // Add site-specific directory if multi-domain caching is enabled
        $site_dir = $this->get_site_cache_dir();
        if (!empty($site_dir)) {
            $root = trailingslashit($root) . $site_dir . '/';
        }
        
        if (is_dir($root)) {
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rii as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }
        }
        \AITranslate\AI_Cache_Meta::delete_by_path_prefix($root);
        $this->clear_memory_and_transients_except_slugs();
    }

    /**
     * Clear only disk-based language caches (HTML artifacts) for this site and preserve menu and slug caches.
     * Does not touch transients or object cache.
     * Clears only the site-specific cache dir (e.g. cache/netcare.nl) using this WordPress site's home host,
     * so the correct dir is cleared regardless of whether admin is opened via www or non-www.
     *
     * @return void
     */
    public function clear_language_disk_caches_only()
    {
        $uploads = wp_upload_dir();
        $root = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';

        $site_dir = $this->get_site_cache_dir_for_clearing();
        if (!empty($site_dir)) {
            $root = trailingslashit($root) . $site_dir . '/';
        }

        if (!is_dir($root)) {
            \AITranslate\AI_Cache_Meta::delete_by_path_prefix($root);
            return;
        }
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        \AITranslate\AI_Cache_Meta::delete_by_path_prefix($root);
    }

    /**
     * Site cache subdir name for clearing: based on this WordPress site's home URL host.
     * Ensures we clear the correct dir (e.g. netcare.nl) even when admin is opened via www.netcare.nl.
     *
     * @return string
     */
    private function get_site_cache_dir_for_clearing()
    {
        $settings = get_option('ai_translate_settings', []);
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;

        if (!$multi_domain) {
            return '';
        }

        $host = parse_url(home_url(), PHP_URL_HOST);
        if (empty($host)) {
            return 'default';
        }
        $sanitized = sanitize_file_name($host);
        return empty($sanitized) ? 'default' : $sanitized;
    }

    /**
     * Clear prompt cache (currently same as memory/transients for simplicity).
     */
    public function clear_prompt_cache()
    {
        $this->clear_memory_and_transients_except_slugs();
    }

    /**
     * Clear menu caches and optional plugin menu tables if present.
     * - Clears WordPress nav menu transients and object cache entries.
     * - Clears all menu-item translation transients (ai_tr_attr_*).
     * - If legacy plugin tables for menu translations exist, truncates them.
     * - Never touches slug map table.
     *
     * @return array{wp_caches_cleared:bool,tables_cleared:array<int,string>,transients_cleared:int}
     */
    public function clear_menu_cache()
    {
        // Clear common WordPress menu caches
        delete_transient('nav_menu');
        delete_transient('nav_menu_items');
        delete_transient('nav_menu_cache');

        // Clear object cache entries for registered menus and locations
        if (function_exists('get_nav_menu_locations')) {
            $menu_locations = get_nav_menu_locations();
            if (is_array($menu_locations)) {
                foreach ($menu_locations as $location => $menu_id) {
                    wp_cache_delete($menu_id, 'nav_menu');
                    wp_cache_delete($location, 'nav_menu_locations');
                }
            }
        }
        if (function_exists('wp_get_nav_menus')) {
            $menus = wp_get_nav_menus();
            if (is_array($menus)) {
                foreach ($menus as $menu) {
                    if (is_object($menu) && isset($menu->term_id)) {
                        wp_cache_delete($menu->term_id, 'nav_menu');
                    }
                }
            }
        }
        // Best-effort flush of a cache group if available (not standard in core)
        if (function_exists('wp_cache_flush_group')) {
            @wp_cache_flush_group('nav_menu');
        }

        // Clear ALL translation transients (ai_tr_attr_* for menu items and ai_tr_seg_* for segments)
        // This ensures renamed menu items and updated content get fresh translations
        $transients_cleared = 0;
        global $wpdb;
        
        // Delete all transients starting with ai_tr_attr_ or ai_tr_seg_
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s
            OR option_name LIKE %s",
            '_transient_ai_tr_attr_%',
            '_transient_timeout_ai_tr_attr_%',
            '_transient_ai_tr_seg_%',
            '_transient_timeout_ai_tr_seg_%'
        ));
        if ($result !== false) {
            $transients_cleared = (int) $result;
        }

        // Optionally truncate legacy/alternate plugin tables if they exist
        $tablesCleared = [];
        $candidates = [
            $wpdb->prefix . 'ai_translate_menus',
            $wpdb->prefix . 'ai_translate_menu_items',
        ];
        foreach ($candidates as $tbl) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tbl));
            if ($exists === $tbl) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->query($wpdb->prepare("TRUNCATE TABLE %i", $tbl));
                $tablesCleared[] = $tbl;
            }
        }

        return [
            'wp_caches_cleared' => true,
            'tables_cleared' => $tablesCleared,
            'transients_cleared' => $transients_cleared,
        ];
    }

    /**
     * Clear slug map table used for translated slugs.
     * Does not modify rewrite rules or other caches.
     *
     * @return array{success:bool,cleared:int,message?:string}
     */
    public function clear_slug_map()
    {
        global $wpdb;
        $table = $wpdb->prefix . 'ai_translate_slugs';
        // Verify table exists
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) {
            return ['success' => false, 'cleared' => 0, 'message' => 'Slug table not found'];
        }
        // Count rows for reporting
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        // Truncate the table
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query($wpdb->prepare("TRUNCATE TABLE %i", $table));
        return ['success' => true, 'cleared' => $count];
    }

    /**
     * Convert language code to full language name with code.
     *
     * @param string $code Language code (e.g. 'ka', 'en', 'nl').
     * @return string Full language name with code, e.g. "Georgian (ka)" or 'unknown' if not found.
     */
    private static function get_language_name($code)
    {
        if ($code === '' || $code === null) {
            return 'unknown';
        }
        $code = strtolower((string) $code);
        $core = self::get_instance();
        $languages = $core->get_available_languages();
        if (isset($languages[$code])) {
            return $languages[$code] . ' (' . strtoupper($code) . ')';
        }
        return 'unknown';
    }

    /**
     * Build a single, centralized system prompt for all translation requests.
     *
     * This ensures consistent instruction to the LLM across batch and any future single-call translations.
     *
     * @param string|null $source_language The expected source language code (nullable/empty if unknown).
     * @param string $target_language The desired target language code.
     * @param array $context Optional context array; supports 'website_context' and 'is_title'.
     * @return string
     */
    public static function build_translation_system_prompt($source_language, $target_language, array $context = [])
    {
        $sourceLangName = $source_language ? self::get_language_name((string) $source_language) : 'unknown';
        $targetLangName = self::get_language_name((string) $target_language);
        $websiteContext = isset($context['website_context']) && $context['website_context'] !== ''
            ? "\n\nWebsite context: " . (string) $context['website_context']
            : '';
        $titleHint = isset($context['is_title']) && $context['is_title'] ? "\n- If the text is a title or menu label, keep it concise and natural." : '';

        $prompt = sprintf(
            'You are a professional translation engine. Your ONLY job is to translate text from %s to %s.
        
        CRITICAL RULES - YOU MUST FOLLOW THESE EXACTLY:
        1. The input text is in %s (source language). You MUST translate it to %s (target language).
        2. ALWAYS translate every segment. NEVER return the source text unchanged.
        3. Even if the input text appears to already be in %s, you MUST still translate it to ensure it is properly in %s.
        4. Do NOT analyze or detect the language of the input. Simply translate from %s to %s as instructed.
        5. The output MUST be in %s. Every word must be translated.%s%s
        
        TRANSLATION STYLE:
        - Make the translation sound natural, fluent, and professional, as if written by a native speaker of %s.
        - Adapt phrasing slightly to ensure it is persuasive and consistent with standard website language in %s.
        - Avoid literal translations that sound awkward or robotic.
        - Use idiomatic expressions and natural vocabulary appropriate for %s speakers.
        - Maintain the original tone and intent while ensuring the text flows smoothly in %s.
        - ALWAYS translate every word. Do not skip any text. Do not return the source text.
        
        MENU ITEMS:
        - For segments where segment.type is "menu": translate literally and accurately.
        - Keep menu items concise (1-3 words maximum), but always preserve the exact meaning of the original text.
        - NEVER substitute with generic menu labels like "About", "News", or "Services" unless those words appear in the original.
        - Translate the actual words provided, not what you think the menu item should be called.
        - NEVER return the source text unchanged. Always translate to %s.
        
        URL SLUGS (META TYPE):
        - For segments where segment.type is "meta": these are URL slugs or short keywords.
        - Keep them SHORT and URL-friendly: maximum 2-3 words, ideally one compound word.
        - Use hyphens (-) to separate words if needed in Latin-based languages.
        - For UTF-8 languages use 2-4 characters maximum.
        - Never use full sentences, descriptions, or long phrases.
        - Example: "ai-consultancy" → "ai-beratung" (DE), "ai-conseil" (FR), "ai咨询" (ZH).
        
        OUTPUT FORMAT:
        - Return ONLY valid JSON in this exact format: {"translations": {"<id>": "<translated_text>"}}
        - Every segment ID must have a translated text in %s (target language).
        - Do not include any explanations, comments, or extra text outside the JSON.
        - The translated_text MUST be in %s, not in %s.%s',
            $sourceLangName,
            $targetLangName,
            $sourceLangName,
            $targetLangName,
            $targetLangName,
            $targetLangName,
            $sourceLangName,
            $targetLangName,
            $targetLangName,
            $websiteContext,
            $titleHint,
            $targetLangName,
            $targetLangName,
            $targetLangName,
            $targetLangName,
            $targetLangName,
            $targetLangName,
            $targetLangName,
            $sourceLangName,
            $targetLangName,
            $titleHint
        );

        return $prompt;
    }

    /**
     * Get site-specific cache directory name.
     * Returns sanitized domain name for use as directory name.
     * Uses the active domain (HTTP_HOST) instead of the WordPress home URL to support multi-domain setups.
     *
     * @return string
     */
    private function get_site_cache_dir()
    {
        $settings = get_option('ai_translate_settings', []);
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
        
        if (!$multi_domain) {
            return '';
        }
        
        // Use the active domain from HTTP_HOST (the domain the user is actually visiting)
        // This ensures each domain gets its own cache directory
        $active_domain = '';
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $active_domain = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
            // Remove port if present (e.g., "example.com:8080" -> "example.com")
            if (strpos($active_domain, ':') !== false) {
                $active_domain = strtok($active_domain, ':');
            }
        }
        
        // Fallback to SERVER_NAME if HTTP_HOST is not available
        if (empty($active_domain) && isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $active_domain = sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME']));
        }
        
        // Final fallback to home_url() host (should rarely be needed)
        if (empty($active_domain)) {
            $active_domain = parse_url(home_url(), PHP_URL_HOST);
            if (empty($active_domain)) {
                $active_domain = 'default';
            }
        }
        
        // Sanitize domain name for use as directory name
        $sanitized = sanitize_file_name($active_domain);
        if (empty($sanitized)) {
            $sanitized = 'default';
        }
        
        return $sanitized;
    }

    /**
     * Compute cache statistics for admin display.
     *
     * @return array{
     *   total_files:int,total_size:int,expired_files:int,last_modified:int,
     *   languages:array<string,int>,languages_details:array<string,array{size:int,expired_count:int,last_modified:int}>
     * }
     */
    public function get_cache_statistics()
    {
        $uploads = wp_upload_dir();
        $root = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
        
        // Add site-specific directory if multi-domain caching is enabled
        $site_dir = $this->get_site_cache_dir();
        if (!empty($site_dir)) {
            $root = trailingslashit($root) . $site_dir . '/';
        }
        
        $stats = [
            'total_files' => 0,
            'total_size' => 0,
            'expired_files' => 0,
            'last_modified' => 0,
            'languages' => [],
            'languages_details' => [],
        ];
        // Respect admin setting (cache_expiration in hours)
        // Admin validation ensures minimum 14 days, so we respect the setting directly
        $expiry_hours = (int) (get_option('ai_translate_settings')['cache_expiration'] ?? (14 * 24));
        $expiry_seconds = $expiry_hours * HOUR_IN_SECONDS;
        $now = time();

        if (!is_dir($root)) {
            return $stats;
        }

        $langs = scandir($root);
        if (!is_array($langs)) {
            return $stats;
        }
        foreach ($langs as $lang) {
            if ($lang === '.' || $lang === '..') continue;
            $langDir = $root . $lang . '/pages/';
            if (!is_dir($langDir)) continue;
            $count = 0;
            $size = 0;
            $expired = 0;
            $lastMod = 0;
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($langDir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($rii as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $count++;
                    $filesize = (int) $file->getSize();
                    $size += $filesize;
                    $mtime = (int) $file->getMTime();
                    if ($mtime > $lastMod) $lastMod = $mtime;
                    if (($now - $mtime) > $expiry_seconds) {
                        $expired++;
                        $stats['expired_files']++;
                    }
                    $stats['total_files']++;
                    $stats['total_size'] += $filesize;
                    if ($mtime > $stats['last_modified']) $stats['last_modified'] = $mtime;
                }
            }
            $stats['languages'][$lang] = $count;
            $stats['languages_details'][$lang] = [
                'size' => $size,
                'expired_count' => $expired,
                'last_modified' => $lastMod,
            ];
        }
        return $stats;
    }

    /**
     * Generate a website context suggestion based on homepage content.
     * This is a lightweight heuristic (no external calls) to avoid failures without API.
     *
     * @return string
     */
    /**
     * Get cleaned homepage content for AI analysis.
     *
     * @return string
     */
    /**
     * Get clean homepage content, optionally for a specific domain.
     *
     * @param string $domain Optional. Domain to fetch content from. If provided, fetches via HTTP.
     * @return string
     */
    private function get_clean_homepage_content($domain = '')
    {
        $settings = get_option('ai_translate_settings', []);
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
        
        // If multi-domain caching is enabled and a specific domain is provided, try to fetch from that domain
        if ($multi_domain && !empty($domain)) {
            // Determine protocol (prefer https, fallback to http)
            $protocol = 'https';
            if (isset($_SERVER['REQUEST_SCHEME'])) {
                $protocol = sanitize_text_field(wp_unslash($_SERVER['REQUEST_SCHEME']));
            } elseif (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                $protocol = 'https';
            }
            
            // Build URL for the specific domain
            $domain_url = $protocol . '://' . $domain . '/';
            
            // Fetch homepage content from the specific domain
            $response = wp_remote_get($domain_url, [
                'timeout' => 15,
                'sslverify' => true,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
                'redirection' => 5,
            ]);
            
            if (!is_wp_error($response)) {
                $response_code = wp_remote_retrieve_response_code($response);
                if ($response_code === 200) {
                    $html = wp_remote_retrieve_body($response);
                    
                    if (!empty($html)) {
                        // Extract text content from HTML
                        $dom = new \DOMDocument();
                        libxml_use_internal_errors(true);
                        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
                        libxml_clear_errors();
                        $xpath = new \DOMXPath($dom);
                        
                        // Get title
                        $title_nodes = $xpath->query('//title');
                        $title = '';
                        if ($title_nodes && $title_nodes->length > 0) {
                            $title = trim($title_nodes->item(0)->textContent);
                        }
                        
                        // Get main content (try common content selectors)
                        $content_selectors = [
                            '//main',
                            '//article',
                            '//div[@class="entry-content"]',
                            '//div[@class="content"]',
                            '//div[@id="content"]',
                            '//body',
                        ];
                        
                        $content_text = '';
                        foreach ($content_selectors as $selector) {
                            $nodes = $xpath->query($selector);
                            if ($nodes && $nodes->length > 0) {
                                $content_text = trim($nodes->item(0)->textContent);
                                if (!empty($content_text) && mb_strlen($content_text) > 100) {
                                    break;
                                }
                            }
                        }
                        
                        // Combine title and content
                        $raw_content = $title;
                        if (!empty($content_text)) {
                            $raw_content .= "\n" . $content_text;
                        }
                        
                        // Strip HTML entities and clean up
                        $content = wp_strip_all_tags($raw_content);
                        $content = preg_replace('/\s+/', ' ', $content);
                        $content = mb_substr($content, 0, 4000);
                        
                        if (!empty($content)) {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log('AI Translate: Fetched content from domain ' . $domain . ' (length: ' . mb_strlen($content) . ')');
                            }
                            return $content;
                        }
                    }
                } else {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('AI Translate: Failed to fetch from domain ' . $domain . ' (HTTP code: ' . $response_code . ')');
                    }
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('AI Translate: Error fetching from domain ' . $domain . ': ' . $response->get_error_message());
                }
            }
        }
        
        // Fallback to standard WordPress content fetching
        $content = '';
        $home_id = (int) get_option('page_on_front');
        $site_name = (string) 'AI Translate';
        
        if ($home_id > 0) {
            $post = get_post($home_id);
            if ($post) {
                // Strip tags and shortcodes
                $raw_content = $post->post_title . "\n" . ($post->post_excerpt ?: $post->post_content);
                // Strip shortcodes first, then HTML tags
                $content = wp_strip_all_tags(strip_shortcodes($raw_content));
                // Remove extra whitespace and newlines
                $content = preg_replace('/\s+/', ' ', $content);
                // Limit length
                $content = mb_substr($content, 0, 4000); 
            }
        }
        
        if (empty($content)) {
            $content = $site_name . ' - ' . get_bloginfo('description');
        }
        
        return $content;
    }

    /**
     * Get website context for a specific domain.
     * Supports per-domain website context when multi-domain caching is enabled.
     *
     * @param string $domain Optional. Domain to get context for. If not provided, uses active domain.
     * @return string
     */
    private function get_website_context_for_domain($domain = '')
    {
        $settings = get_option('ai_translate_settings', []);
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
        
        if ($multi_domain) {
            // Determine active domain if not provided
            $active_domain = $domain;
            if (empty($active_domain)) {
                if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
                    $active_domain = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
                    if (strpos($active_domain, ':') !== false) {
                        $active_domain = strtok($active_domain, ':');
                    }
                }
                if (empty($active_domain) && isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
                    $active_domain = sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME']));
                }
                if (empty($active_domain)) {
                    $active_domain = parse_url(home_url(), PHP_URL_HOST);
                    if (empty($active_domain)) {
                        $active_domain = 'default';
                    }
                }
            }
            
            // Multi-domain caching enabled: use per-domain website context
            $domain_context = isset($settings['website_context_per_domain']) && is_array($settings['website_context_per_domain']) 
                ? $settings['website_context_per_domain'] 
                : [];
            
            if (isset($domain_context[$active_domain]) && trim((string) $domain_context[$active_domain]) !== '') {
                return trim((string) $domain_context[$active_domain]);
            }
        }
        
        // Fallback to global website context
        $context = isset($settings['website_context']) ? (string) $settings['website_context'] : '';
        return trim($context);
    }

    /**
     * Generate a website context suggestion using the configured AI provider.
     * Falls back to local generation if API is not configured or fails.
     *
     * @param string $domain Optional. Domain to use for content fetching. If not provided, uses active domain.
     * @return string
     */
    public function generate_website_context_suggestion($domain = '')
    {
        // 1. Get settings
        $settings = get_option('ai_translate_settings', []);
        $provider = isset($settings['api_provider']) ? (string)$settings['api_provider'] : '';
        $models = isset($settings['models']) && is_array($settings['models']) ? $settings['models'] : [];
        $model = $provider !== '' ? ($models[$provider] ?? '') : '';
        $apiKeys = isset($settings['api_keys']) && is_array($settings['api_keys']) ? $settings['api_keys'] : [];
        $apiKey = $provider !== '' ? ($apiKeys[$provider] ?? '') : '';

        // Custom provider settings
        $baseUrl = self::get_api_url_for_provider($provider);
        if ($provider === 'custom') {
            $baseUrl = isset($settings['custom_api_url']) ? (string)$settings['custom_api_url'] : '';
        }

        // 2. Prepare content - use provided domain
        $content = $this->get_clean_homepage_content($domain);
        
        // Get site name - use domain-specific if multi-domain caching is enabled
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
        $site_name = '';
        if ($multi_domain && !empty($domain)) {
            // For multi-domain, try to extract site name from content or use domain
            $lines = explode("\n", $content);
            if (!empty($lines[0]) && trim($lines[0]) !== '') {
                $site_name = trim($lines[0]);
                if (mb_strlen($site_name) > 100) {
                    $site_name = mb_substr($site_name, 0, 100);
                }
            } else {
                $site_name = strtok($domain, ':');
            }
        } else {
            $site_name = (string) 'AI Translate';
        }

        // 3. Try AI generation if configured
        if ($provider !== '' && $model !== '' && $apiKey !== '' && $baseUrl !== '') {
            try {
                $endpoint = rtrim($baseUrl, '/') . '/chat/completions';
                
                $prompt = "Analyze the following website content and generate a concise website context description of maximum 5 lines.\n" .
                          "This description will be used to inform an AI translator about the nature, tone, and subject matter of the site.\n" .
                          "Rules:\n" .
                          "1. Plain text only. No Markdown, no HTML, no special formatting.\n" .
                          "2. Maximum 5 lines / sentences.\n" .
                          "3. Focus on what the company/site does, who it serves, and the key terminology.\n" .
                          "4. Do NOT include navigation menus, footer links, or irrelevant UI text.\n\n" .
                          "Website Content:\n" . $content;

                $body = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a helpful assistant that summarizes website content.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ];
                if ($provider === 'openrouter' || ($provider === 'custom' && strpos($baseUrl, 'openrouter.ai') !== false)) {
                    $body['user'] = !empty($domain) ? $domain : parse_url(home_url(), PHP_URL_HOST);
                }

                // Model specific params (consistent with Batch class)
                if (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o1-') || str_starts_with($model, 'o3-') || str_starts_with($model, 'deepseek/deepseek-v3')) {
                     $body['max_completion_tokens'] = 500;
                } else {
                     $body['max_tokens'] = 500;
                     $body['temperature'] = 0.3;
                }

                $headers = [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ];
                
                if ($provider === 'custom' && strpos($baseUrl, 'openrouter.ai') !== false) {
                    // Use the domain-specific URL if provided
                    if (!empty($domain)) {
                        $protocol = is_ssl() ? 'https' : 'http';
                        if (isset($_SERVER['REQUEST_SCHEME'])) {
                            $protocol = sanitize_text_field(wp_unslash($_SERVER['REQUEST_SCHEME']));
                        }
                        $headers['HTTP-Referer'] = 'https://github.com/gerard-kanters/ai-translate';
                    } else {
                        $headers['HTTP-Referer'] = 'https://github.com/gerard-kanters/ai-translate';
                    }
                    $headers['X-Title'] = 'AI Translate';
                }

                $response = wp_remote_post($endpoint, [
                    'headers' => $headers,
                    'timeout' => 30,
                    'sslverify' => true,
                    'body' => wp_json_encode($body),
                ]);

                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $data = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($data['choices'][0]['message']['content'])) {
                        $ai_context = trim($data['choices'][0]['message']['content']);
                        // Remove markdown code blocks if present
                        $ai_context = str_replace(["```json", "```JSON", "```"], '', $ai_context);
                        // Final cleanup
                        $ai_context = strip_tags($ai_context);
                        return $ai_context;
                    }
                }
            } catch (\Exception $e) {
                // Fall through to fallback
            }
        }

        // 4. Fallback (Local generation)
        $parts = [];
        if ($site_name !== '') $parts[] = $site_name;
        
        $desc = get_bloginfo('description');
        if ($desc) $parts[] = $desc;

        // Use the content we already prepared but much shorter for fallback
        if (!empty($content)) {
             // Take a smaller chunk for the simple fallback
             $fallback_content = mb_substr($content, 0, 280);
             if ($fallback_content !== $site_name && $fallback_content !== $desc) {
                 $parts[] = $fallback_content;
             }
        }
        
        return implode(' — ', $parts);
    }

    /**
     * Generate a homepage meta description using the configured AI provider.
     *
     * @param string $domain Optional. Domain to use for content fetching. If not provided, uses active domain.
     * @return string
     */
    public function generate_homepage_meta_description($domain = '')
    {
        // 1. Get settings
        $settings = get_option('ai_translate_settings', []);
        $provider = isset($settings['api_provider']) ? (string)$settings['api_provider'] : '';
        $models = isset($settings['models']) && is_array($settings['models']) ? $settings['models'] : [];
        $model = $provider !== '' ? ($models[$provider] ?? '') : '';
        $apiKeys = isset($settings['api_keys']) && is_array($settings['api_keys']) ? $settings['api_keys'] : [];
        $apiKey = $provider !== '' ? ($apiKeys[$provider] ?? '') : '';
        
        // Target language (default language of the site)
        $default_lang = isset($settings['default_language']) ? (string)$settings['default_language'] : 'en';
        $targetLangName = $this->get_language_name($default_lang);

        // Custom provider settings
        $baseUrl = self::get_api_url_for_provider($provider);
        if ($provider === 'custom') {
            $baseUrl = isset($settings['custom_api_url']) ? (string)$settings['custom_api_url'] : '';
        }

        // 2. Prepare content - use provided domain or active domain
        $content = $this->get_clean_homepage_content($domain);
        
        // Get site name - use domain-specific if multi-domain caching is enabled
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
        $site_name = '';
        if ($multi_domain && !empty($domain)) {
            // For multi-domain, try to extract site name from content or use domain
            // The content should already contain the title from the specific domain
            // Extract first line (title) if available
            $lines = explode("\n", $content);
            if (!empty($lines[0]) && trim($lines[0]) !== '') {
                $site_name = trim($lines[0]);
                // Limit to reasonable length for site name
                if (mb_strlen($site_name) > 100) {
                    $site_name = mb_substr($site_name, 0, 100);
                }
            } else {
                // Fallback to domain name (remove port if present)
                $site_name = strtok($domain, ':');
            }
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('AI Translate: Using domain-specific site name for ' . $domain . ': ' . $site_name);
            }
        } else {
            $site_name = (string) 'AI Translate';
        }
        
        // Get website context (per-domain if multi-domain caching is enabled)
        $website_context = $this->get_website_context_for_domain($domain);

        // 3. Try AI generation if configured
        if ($provider !== '' && $model !== '' && $apiKey !== '' && $baseUrl !== '') {
            try {
                $endpoint = rtrim($baseUrl, '/') . '/chat/completions';
                
                $context_section = '';
                if ($website_context !== '') {
                    $context_section = "\n\nWebsite Context (use this to understand the business/industry):\n" . $website_context;
                }
                
                $prompt = "You are creating a <meta name=\"description\"> tag content for a website homepage.\n\n" .
                          "CRITICAL REQUIREMENTS:\n" .
                          "1. Language: Write in " . $targetLangName . ".\n" .
                          "2. Length: EXACTLY 150-160 characters (not 180). This is the optimal length for search engines.\n" .
                          "3. Content: Extract and use the PRIMARY KEYWORDS from the website content. Be SPECIFIC about what the company/site does.\n" .
                          "4. NO generic phrases like 'hoogwaardige diensten', 'innovatieve oplossingen', 'expertise en kwaliteit', 'aansluiten bij uw behoeften'.\n" .
                          "5. Include the company name (" . $site_name . ") if it fits naturally.\n" .
                          "6. Be SPECIFIC: mention actual services, products, or unique value propositions mentioned in the content.\n" .
                          "7. Include a subtle call-to-action (e.g., 'Ontdek...', 'Bekijk...', 'Vraag...') but keep it natural.\n" .
                          "8. Write as if a professional SEO expert wrote it: keyword-rich, specific, compelling, and unique.\n" .
                          "9. Output ONLY the meta description text. No quotes, no HTML tags, no explanations.\n\n" .
                          "Website Content:\n" . $content . $context_section;

                $body = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are an expert SEO copywriter specializing in writing compelling, keyword-rich meta descriptions that drive click-through rates. You understand that generic descriptions hurt SEO performance.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ];
                if ($provider === 'openrouter' || ($provider === 'custom' && strpos($baseUrl, 'openrouter.ai') !== false)) {
                    $body['user'] = !empty($domain) ? $domain : parse_url(home_url(), PHP_URL_HOST);
                }

                // Model specific params
                if (str_starts_with($model, 'gpt-5') || str_starts_with($model, 'o1-') || str_starts_with($model, 'o3-') || str_starts_with($model, 'deepseek/deepseek-v3')) {
                     $body['max_completion_tokens'] = 300;
                } else {
                     $body['max_tokens'] = 300;
                     $body['temperature'] = 0.7;
                }

                $headers = [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type'  => 'application/json',
                ];
                
                if ($provider === 'custom' && strpos($baseUrl, 'openrouter.ai') !== false) {
                    // Use the domain-specific URL if provided
                    if (!empty($domain)) {
                        $protocol = is_ssl() ? 'https' : 'http';
                        if (isset($_SERVER['REQUEST_SCHEME'])) {
                            $protocol = sanitize_text_field(wp_unslash($_SERVER['REQUEST_SCHEME']));
                        }
                        $headers['HTTP-Referer'] = 'https://github.com/gerard-kanters/ai-translate';
                    } else {
                        $headers['HTTP-Referer'] = 'https://github.com/gerard-kanters/ai-translate';
                    }
                    $headers['X-Title'] = 'AI Translate';
                }

                $response = wp_remote_post($endpoint, [
                    'headers' => $headers,
                    'timeout' => 30,
                    'sslverify' => true,
                    'body' => wp_json_encode($body),
                ]);

                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $data = json_decode(wp_remote_retrieve_body($response), true);
                    if (isset($data['choices'][0]['message']['content'])) {
                        $meta = trim($data['choices'][0]['message']['content']);
                        $meta = str_replace(["```json", "```JSON", "```"], '', $meta);
                        $meta = strip_tags($meta);
                        return $meta;
                    }
                }
            } catch (\Exception $e) {
                // Fall through
            }
        }

        // 4. Fallback
        return mb_substr($content, 0, 160);
    }
}