<?php

namespace AITranslate;

/**
 * Persistent translated slugs per post and language.
 */
final class AI_Slugs
{
    /**
     * Create slug map table if not exists.
     *
     * @return void
     */
    public static function install_table()
    {
        global $wpdb;
        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            post_id BIGINT(20) UNSIGNED NOT NULL,
            lang VARCHAR(10) NOT NULL,
            source_slug VARCHAR(200) NOT NULL,
            translated_slug VARCHAR(200) NOT NULL,
            source_version VARCHAR(32) NOT NULL,
            updated_gmt DATETIME NOT NULL,
            PRIMARY KEY (post_id, lang),
            KEY lang_slug (lang, translated_slug)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Resolve a translated path to a post ID.
     *
     * @param string $lang
     * @param string $path path without leading language (e.g., "paketten")
     * @return int|null
     */
    public static function resolve_path_to_post($lang, $path)
    {
        global $wpdb;
        $table = self::table_name();
        $slug = trim($path, '/');
        if ($slug === '') return null;
        $schema = self::detect_schema();
        $colLang = $schema === 'original' ? 'language_code' : 'lang';
        $colTrans = 'translated_slug';
        $colSource = $schema === 'original' ? 'original_slug' : 'source_slug';
        
        // Exact match first (support both schemas)
        $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$table} WHERE {$colLang} = %s AND {$colTrans} = %s LIMIT 1", $lang, $slug));
        if ($post_id) return (int) $post_id;
        
        // Fuzzy fallback: handle truncated prefixes (e.g., 'kketten' vs 'pakketten')
        $like = '%' . $wpdb->esc_like($slug);
        $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$table} WHERE {$colLang} = %s AND {$colTrans} LIKE %s ORDER BY LENGTH({$colTrans}) ASC LIMIT 1", $lang, $like));
        if ($post_id) return (int) $post_id;
        
        // Fallback to original slug: if translated slug doesn't match, try matching against source slug
        // This handles cases where the URL still uses the original slug (e.g., /it/blogs/ when translated slug is /it/blog/)
        // Prefer posts that already have a translation entry for this language (even if slug differs)
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE {$colSource} = %s AND {$colLang} = %s LIMIT 1",
            $slug,
            $lang
        ));
        if ($post_id) return (int) $post_id;
        
        // Final fallback: match source slug regardless of language (for posts that might not have translation entry yet)
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE {$colSource} = %s LIMIT 1",
            $slug
        ));
        return $post_id ? (int)$post_id : null;
    }

    /**
     * Resolve any translated slug (any language) to source slug.
     *
     * @param string $path
     * @return string|null source slug (default language)
     */
    public static function resolve_any_to_source_slug($path)
    {
        global $wpdb;
        $table = self::table_name();
        $slug = trim($path, '/');
        if ($slug === '') return null;
        $schema = self::detect_schema();
        $colSource = $schema === 'original' ? 'original_slug' : 'source_slug';
        $source = $wpdb->get_var($wpdb->prepare("SELECT {$colSource} FROM {$table} WHERE translated_slug = %s LIMIT 1", $slug));
        return $source ? (string)$source : null;
    }

    /**
     * Get or create a translated slug for a post and language.
     *
     * @param int $post_id
     * @param string $lang target language
     * @return string|null translated slug
     */
    public static function get_or_generate($post_id, $lang)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return null;
        $default = AI_Lang::default();
        if ($default === null) return null;
        // Homepage should always be '/{lang}/'
        $front_id = (int) get_option('page_on_front');
        if ($front_id > 0 && $post_id === $front_id) {
            // Store empty translated slug for consistency
            $source_slug_hp = self::get_source_slug($post_id);
            $version_hp = md5($source_slug_hp);
            self::upsert_row($post_id, $lang, (string)$source_slug_hp, '', $version_hp);
            return '';
        }
        $source_slug = self::get_source_slug($post_id);
        if ($source_slug === '') return null;
        
        // For default language: return source slug directly (no translation needed)
        if (strtolower($lang) === strtolower($default)) {
            return $source_slug;
        }
        
        $version = md5($source_slug);

        $row = self::get_row($post_id, $lang);
        if ($row && isset($row['translated_slug']) && $row['translated_slug'] !== '' && (!isset($row['source_version']) || $row['source_version'] === $version)) {
            $existing = (string) $row['translated_slug'];
            // Validate existing stored slug: if it looks truncated (suffix of source with >=2 lost chars), fallback to source
            if (str_ends_with($source_slug, $existing) && (strlen($source_slug) - strlen($existing)) >= 2) {
                self::upsert_row($post_id, $lang, $source_slug, $source_slug, $version);
                return $source_slug;
            }
            return $existing;
        }

        // Translate once via provider (batch-providers), using AI_Batch::translate_plan
        $plan = [
            'segments' => [ ['id' => 'slug', 'text' => $source_slug, 'type' => 'meta'] ],
        ];
        $settings = get_option('ai_translate_settings', []);
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
        
        // Get website context (per-domain if multi-domain caching is enabled)
        $website_context = '';
        if ($multi_domain) {
            $active_domain = '';
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
            
            $domain_context = isset($settings['website_context_per_domain']) && is_array($settings['website_context_per_domain']) 
                ? $settings['website_context_per_domain'] 
                : [];
            
            if (isset($domain_context[$active_domain]) && trim((string) $domain_context[$active_domain]) !== '') {
                $website_context = trim((string) $domain_context[$active_domain]);
            }
        }
        
        if (empty($website_context)) {
            $website_context = isset($settings['website_context']) ? (string)$settings['website_context'] : '';
        }
        
        $ctx = [ 'website_context' => $website_context ];
        // Always translate from original default language to target
        $res = AI_Batch::translate_plan($plan, $default, $lang, $ctx);
        $translations = is_array($res['segments'] ?? null) ? $res['segments'] : [];
        $translated = isset($translations['slug']) ? (string) $translations['slug'] : '';
        // Fallback: if provider missing or translation failed, keep source slug to avoid broken URLs
        if ($translated === '') {
            $translated = $source_slug;
        }
        // Normalize slug with Unicode support (allow letters/numbers across scripts)
        // First try WordPress sanitize_title; if it strips to empty (e.g., CJK), fallback to Unicode cleaning
        $translated_slug = sanitize_title($translated);
        if ($translated_slug === '' || $translated_slug === '-') {
            $tmp = preg_replace('/[^\p{L}\p{N}\s\-]+/u', '', $translated); // keep letters, numbers, spaces, hyphens
            $tmp = preg_replace('/[\s_]+/u', '-', (string) $tmp);            // spaces/underscores to hyphen
            $tmp = trim(preg_replace('/-+/u', '-', (string) $tmp), '-');      // collapse hyphens
            $translated_slug = (string) $tmp;
        } else {
            // Ensure consistency on ASCII path as well
            $translated_slug = preg_replace('/-+/u', '-', (string) $translated_slug);
            $translated_slug = trim((string) $translated_slug, '-');
        }
        
        // Validate slug length: reject translations that are too long (likely full sentences)
        $maxLength = 60; // Maximum characters for a slug
        $charCount = mb_strlen($translated_slug);
        if ($charCount > $maxLength) {
            // Translation is too long (probably a sentence instead of a slug), use source slug as fallback
            $translated_slug = $source_slug;
        }
        
        // Guard: if translation looks like a suffix of the source (lost leading chars), fallback to source slug
        if ($translated_slug !== '' && str_ends_with($source_slug, $translated_slug) && (strlen($source_slug) - strlen($translated_slug)) >= 2) {
            $translated_slug = $source_slug;
        }
        if ($translated_slug === '') return null;

        self::upsert_row($post_id, $lang, $source_slug, $translated_slug, $version);
        return $translated_slug;
    }

    private static function get_source_slug($post_id)
    {
        $post = get_post($post_id);
        if (!$post) return '';
        // Use post_name (basename); hierarchical support can be added later via get_page_uri
        return (string) $post->post_name;
    }

    private static function get_row($post_id, $lang)
    {
        global $wpdb;
        $table = self::table_name();
        $schema = self::detect_schema();
        $colLang = $schema === 'original' ? 'language_code' : 'lang';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %d AND {$colLang} = %s", $post_id, $lang), ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        if ($schema === 'original') {
            // Normalize keys for original schema so callers can read a unified shape
            $row['source_slug'] = isset($row['original_slug']) ? (string) $row['original_slug'] : '';
            // Compute a virtual source_version for comparison without altering DB schema
            $row['source_version'] = md5(self::get_source_slug((int) $post_id));
            if (!isset($row['translated_slug'])) {
                $row['translated_slug'] = '';
            }
        }
        return $row;
    }

    private static function upsert_row($post_id, $lang, $source_slug, $translated_slug, $version)
    {
        global $wpdb;
        $table = self::table_name();
        $schema = self::detect_schema();
        $now = gmdate('Y-m-d H:i:s');
        
        // First, check if source_slug changed - if so, delete old row to avoid orphaned mappings
        $existing = self::get_row($post_id, $lang);
        if ($existing && isset($existing['source_slug']) && $existing['source_slug'] !== '' && $existing['source_slug'] !== $source_slug) {
            // Source slug changed - delete the old mapping
            if ($schema === 'original') {
                $colLang = 'language_code';
            } else {
                $colLang = 'lang';
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->delete($table, ['post_id' => (int) $post_id, $colLang => (string) $lang], ['%d', '%s']);
        }
        
        if ($schema === 'original') {
            // Original schema
            $wpdb->replace(
                $table,
                [
                    'post_id' => (int) $post_id,
                    'original_slug' => (string) $source_slug,
                    'translated_slug' => (string) $translated_slug,
                    'language_code' => (string) $lang,
                    'created_at' => $now,
                ],
                ['%d','%s','%s','%s','%s']
            );
        } else {
            // New schema (this plugin)
            $wpdb->replace(
                $table,
                [
                    'post_id' => (int) $post_id,
                    'lang' => (string) $lang,
                    'source_slug' => (string) $source_slug,
                    'translated_slug' => (string) $translated_slug,
                    'source_version' => (string) $version,
                    'updated_gmt' => $now,
                ],
                ['%d','%s','%s','%s','%s','%s']
            );
        }
    }

    private static function table_name()
    {
        global $wpdb;
        // Align with original plugin table name for compatibility
        return $wpdb->prefix . 'ai_translate_slugs';
    }

    /**
     * Detect whether table uses original schema (language_code/original_slug) or new (lang/source_slug).
     * @return string 'original'|'new'
     */
    private static function detect_schema()
    {
        static $schema = null;
        if ($schema !== null) return $schema;
        global $wpdb;
        $table = self::table_name();
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        if (!is_array($cols)) {
            // Safe default: assume original to avoid querying non-existent 'lang'/'source_slug'
            $schema = 'original';
            return $schema;
        }
        $hasLanguageCode = in_array('language_code', $cols, true);
        $hasOriginalSlug = in_array('original_slug', $cols, true);
        $hasLang = in_array('lang', $cols, true);
        $hasSourceSlug = in_array('source_slug', $cols, true);
        if ($hasLanguageCode || $hasOriginalSlug) {
            $schema = 'original';
        } elseif ($hasLang && $hasSourceSlug) {
            $schema = 'new';
        } else {
            // Ambiguous: prefer original to prevent SQL errors against 'lang'/'source_slug'
            $schema = 'original';
        }
        return $schema;
    }
}


