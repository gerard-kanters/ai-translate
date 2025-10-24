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
     * - Throws \Exception on failure.
     *
     * @param string $provider_key
     * @param string $api_key
     * @param string $custom_api_url
     * @return array{ok:bool}
     * @throws \Exception
     */
    public function validate_api_settings($provider_key, $api_key, $custom_api_url = '')
    {
        $provider_key = (string) $provider_key;
        $api_key = (string) $api_key;
        $custom_api_url = (string) $custom_api_url;

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

        $endpoint = rtrim($base, '/') . '/models';
        $resp = wp_remote_get($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
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
        $uploads = wp_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'ai-translate/cache/' . $lang . '/pages/';
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
        // Clear transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
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
        $this->clear_memory_and_transients_except_slugs();
    }

    /**
     * Clear only disk-based language caches (HTML artifacts) and preserve menu and slug caches.
     * Does not touch transients or object cache.
     *
     * @return void
     */
    public function clear_language_disk_caches_only()
    {
        $uploads = wp_upload_dir();
        $root = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
        if (!is_dir($root)) {
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
     * - If legacy plugin tables for menu translations exist, truncates them.
     * - Never touches slug map table.
     *
     * @return array{wp_caches_cleared:bool,tables_cleared:array<int,string>}
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

        // Optionally truncate legacy/alternate plugin tables if they exist
        $tablesCleared = [];
        global $wpdb;
        $candidates = [
            $wpdb->prefix . 'ai_translate_menus',
            $wpdb->prefix . 'ai_translate_menu_items',
        ];
        foreach ($candidates as $tbl) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tbl));
            if ($exists === $tbl) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->query("TRUNCATE TABLE {$tbl}");
                $tablesCleared[] = $tbl;
            }
        }

        return [
            'wp_caches_cleared' => true,
            'tables_cleared' => $tablesCleared,
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
        $wpdb->query("TRUNCATE TABLE {$table}");
        return ['success' => true, 'cleared' => $count];
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
        $sourceLang = $source_language ? (string) $source_language : 'unknown';
        $targetLang = (string) $target_language;
        $websiteContext = isset($context['website_context']) && $context['website_context'] !== ''
            ? "\n\nWebsite context: " . (string) $context['website_context']
            : '';
        $titleHint = isset($context['is_title']) && $context['is_title'] ? "\n- If the text is a title or menu label, keep it concise and natural." : '';

        $prompt = sprintf(
            'You are a professional translation engine. CRITICAL: The source language is %s and the target language is %s. IGNORE the apparent language of the input text and translate as instructed.%s%s
        
        TRANSLATION STYLE:
        - Make the translation sound natural, fluent, and professional, as if written by a native speaker.
        - Adapt phrasing slightly to ensure it is persuasive and consistent with standard website language in the target language.
        - Avoid literal translations that sound awkward or robotic.
        - Use idiomatic expressions and natural vocabulary appropriate for the audience and context.
        - Maintain the original tone and intent while ensuring the text flows smoothly and is suitable for publication.
        
        MENU ITEMS:
        - For segments where segment.type is "menu": translate to at most two words.
        - Prefer concise, standard navigation labels while preserving meaning.
        - If the original is longer, choose the best two-word equivalent in the target language.%s',
            $sourceLang,
            $targetLang,
            $websiteContext,
            '',
            $titleHint
        );

        return $prompt;
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
        $stats = [
            'total_files' => 0,
            'total_size' => 0,
            'expired_files' => 0,
            'last_modified' => 0,
            'languages' => [],
            'languages_details' => [],
        ];
        $expiry_hours = (int) (get_option('ai_translate_settings')['cache_expiration'] ?? (14 * 24));
        $expiry_seconds = max(14 * 24, $expiry_hours) * HOUR_IN_SECONDS;
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
    public function generate_website_context_suggestion()
    {
        // Try to get the homepage content/title to build a short context
        $home_id = (int) get_option('page_on_front');
        $site_name = (string) get_bloginfo('name');
        if ($home_id > 0) {
            $post = get_post($home_id);
            if ($post) {
                $title = trim((string) $post->post_title);
                $excerpt = trim(wp_strip_all_tags((string) $post->post_excerpt !== '' ? $post->post_excerpt : $post->post_content));
                $excerpt = mb_substr($excerpt, 0, 280);
                $parts = [];
                if ($site_name !== '') $parts[] = $site_name;
                if ($title !== '') $parts[] = $title;
                if ($excerpt !== '') $parts[] = $excerpt;
                return implode(' — ', $parts);
            }
        }
        return $site_name !== '' ? $site_name : 'Website context';
    }
}