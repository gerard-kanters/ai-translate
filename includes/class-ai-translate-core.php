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
            'openai' => [ 'name' => 'OpenAI compatible (api.openai.com)', 'base_url' => 'https://api.openai.com/v1' ],
            'deepseek' => [ 'name' => 'DeepSeek (openai-compatible)', 'base_url' => 'https://api.deepseek.com/v1' ],
            'custom' => [ 'name' => 'Custom (OpenAI-compatible)', 'base_url' => '' ],
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
            'nl' => 'Nederlands',
            'de' => 'Deutsch',
            'fr' => 'Français',
            'es' => 'Español',
            'it' => 'Italiano',
            'pt' => 'Português',
            'pl' => 'Polski',
            'cs' => 'Čeština',
            'da' => 'Dansk',
            'fi' => 'Suomi',
            'sv' => 'Svenska',
            'no' => 'Norsk',
            'ro' => 'Română',
            'ru' => 'Русский',
            'uk' => 'Українська',
            'tr' => 'Türkçe',
            'el' => 'Ελληνικά',
            'hu' => 'Magyar',
            'bg' => 'Български',
            'ar' => 'العربية',
            'he' => 'עברית',
            'hi' => 'हिन्दी',
            'th' => 'ไทย',
            'vi' => 'Tiếng Việt',
            'zh' => '中文',
            'ja' => '日本語',
            'ko' => '한국어',
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
     * Clear prompt cache (currently same as memory/transients for simplicity).
     */
    public function clear_prompt_cache()
    {
        $this->clear_memory_and_transients_except_slugs();
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