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
            KEY lang_slug (lang, translated_slug),
            KEY source_slug (source_slug),
            KEY translated_slug (translated_slug),
            KEY lang_updated (lang, updated_gmt),
            KEY post_id_updated (post_id, updated_gmt)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Invalidate cached schema detection so detect_schema() re-checks after table change
        delete_transient('ai_tr_slugs_schema');

        // Only ensure indexes exist if this is a fresh installation (not on every init)
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
        if (!$table_exists) {
            self::ensure_indexes();
        }
    }

    /**
     * Ensure all required indexes exist on the slug map table
     * Called during table creation and plugin updates
     *
     * @return void
     */
    public static function ensure_indexes()
    {
        global $wpdb;

        $table = self::table_name();

        // Check if table exists first
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return;
        }

        // Check if table has the expected new schema (contains 'lang' and 'source_slug' columns)
        $cols = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $table), 0);
        $has_new_schema = in_array('lang', $cols, true) && in_array('source_slug', $cols, true) && in_array('updated_gmt', $cols, true);
        if (!$has_new_schema) {
            // Table still has old schema, skip index management to avoid errors
            return;
        }

        // Get existing indexes
        $existing_indexes_result = $wpdb->get_col($wpdb->prepare(
            "SHOW INDEX FROM %i WHERE Key_name != 'PRIMARY'",
            $table
        ));

        $indexes_to_check = [
            'lang_slug',
            'source_slug',
            'translated_slug',
            'lang_updated',
            'post_id_updated'
        ];

        foreach ($indexes_to_check as $index_name) {
            if (!in_array($index_name, $existing_indexes_result, true)) {
                // Index doesn't exist, try to create it
                $index_definitions = [
                    'lang_slug' => "ADD KEY lang_slug (lang, translated_slug)",
                    'source_slug' => "ADD KEY source_slug (source_slug)",
                    'translated_slug' => "ADD KEY translated_slug (translated_slug)",
                    'lang_updated' => "ADD KEY lang_updated (lang, updated_gmt)",
                    'post_id_updated' => "ADD KEY post_id_updated (post_id, updated_gmt)"
                ];

                // Use raw query to avoid prepare issues with complex syntax
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
                $result = $wpdb->query("ALTER TABLE {$table} " . $index_definitions[$index_name]);
                if ($result === false) {
                    // Silently ignore errors - indexes might already exist or table might be locked
                    // Don't log errors to avoid spam in debug logs
                }
            }
        }
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
        // Normaliseer URL-gecodeerd path (bijv. Hongaarse slugs) naar UTF-8 zodat exacte match werkt
        if (strpos($slug, '%') !== false) {
            $decoded = rawurldecode($slug);
            if (mb_check_encoding($decoded, 'UTF-8')) {
                $slug = $decoded;
            }
        }
        $schema = self::detect_schema();
        $colLang = $schema === 'original' ? 'language_code' : 'lang';
        $colTrans = 'translated_slug';
        $colSource = $schema === 'original' ? 'original_slug' : 'source_slug';
        
        // Exact match first (support both schemas), but exclude attachments
        $post_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$table} WHERE {$colLang} = %s AND {$colTrans} = %s LIMIT 1", $lang, $slug));
        if ($post_id) {
            // Verify it's not an attachment
            $post = get_post((int) $post_id);
            if ($post && $post->post_type !== 'attachment') {
                return (int) $post_id;
            }
        }
        
        // Also try exact match with URL-encoded slugs in database
        $rows_encoded = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, {$colTrans} FROM {$table} WHERE {$colLang} = %s AND {$colTrans} LIKE %s",
            $lang,
            '%' . $wpdb->esc_like('%', '\\') . '%'
        ), ARRAY_A);
        if (is_array($rows_encoded)) {
            foreach ($rows_encoded as $row) {
                $stored = isset($row[$colTrans]) ? (string) $row[$colTrans] : '';
                if ($stored === '' || strpos($stored, '%') === false) continue;
                $stored_decoded = rawurldecode($stored);
                if (mb_check_encoding($stored_decoded, 'UTF-8') && $stored_decoded === $slug) {
                    $post_id = (int) $row['post_id'];
                    // Verify it's not an attachment
                    $post = get_post($post_id);
                    if ($post && $post->post_type !== 'attachment') {
                        self::fix_encoded_slug_in_db($post_id, $lang, $stored);
                        return $post_id;
                    }
                }
            }
        }
        
        // Fuzzy fallback: handle truncated prefixes (e.g., 'kketten' vs 'pakketten')
        // Only match if translated_slug is a prefix of the requested slug (truncated prefix case)
        // This prevents matching "学生音乐会" when requesting "学生音乐会3"
        // We need to check all translated slugs and see if any is a prefix of the requested slug
        // Prefer the longest matching prefix (most specific match)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, {$colTrans} FROM {$table} WHERE {$colLang} = %s ORDER BY LENGTH({$colTrans}) DESC",
            $lang
        ), ARRAY_A);
        $best_match = null;
        $best_match_length = 0;
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $translated = isset($row[$colTrans]) ? (string) $row[$colTrans] : '';
                if ($translated === '') continue;
                // Decode if URL-encoded
                if (strpos($translated, '%') !== false) {
                    $translated_decoded = rawurldecode($translated);
                    if (mb_check_encoding($translated_decoded, 'UTF-8')) {
                        $translated = $translated_decoded;
                    }
                }
                // Check if translated slug is a prefix of the requested slug (truncated prefix)
                // IMPORTANT: Only match if it's actually a truncated prefix, not if the slug is longer
                // This means the translated slug must be shorter than the requested slug
                if (str_starts_with($slug, $translated) && mb_strlen($translated) < mb_strlen($slug)) {
                    $translated_length = mb_strlen($translated);
                    // Keep the longest matching prefix
                    if ($translated_length > $best_match_length) {
                        $best_match = $row;
                        $best_match_length = $translated_length;
                    }
                }
            }
        }
        if ($best_match !== null) {
            $post_id = (int) $best_match['post_id'];
            // Verify it's not an attachment
            $post = get_post($post_id);
            if ($post && $post->post_type !== 'attachment') {
                // Fix encoded slug in DB if needed
                $stored_original = isset($best_match[$colTrans]) ? (string) $best_match[$colTrans] : '';
                $translated_final = $stored_original;
                if (strpos($stored_original, '%') !== false) {
                    $translated_decoded = rawurldecode($stored_original);
                    if (mb_check_encoding($translated_decoded, 'UTF-8')) {
                        $translated_final = $translated_decoded;
                    }
                }
                if ($stored_original !== $translated_final && strpos($stored_original, '%') !== false) {
                    self::fix_encoded_slug_in_db($post_id, $lang, $stored_original);
                }
                return $post_id;
            }
        }
        
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
        if ($post_id) return (int) $post_id;

        // Fallback for wrongly stored URL-encoded slugs (e.g. Hungarian): request path may be
        // decoded ("fellépés-...") or still encoded ("fell%c3%a9p%c3%a9s-..."); DB may contain encoded.
        // Compare both sides in decoded form.
        $slug_normalized = (strpos($slug, '%') !== false) ? rawurldecode($slug) : $slug;
        if ($slug_normalized !== $slug && !mb_check_encoding($slug_normalized, 'UTF-8')) {
            $slug_normalized = $slug;
        }
        $encoded_like = '%' . $wpdb->esc_like('%', '\\') . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, translated_slug FROM {$table} WHERE {$colLang} = %s AND translated_slug LIKE %s",
            $lang,
            $encoded_like
        ), ARRAY_A);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $stored = isset($row['translated_slug']) ? (string) $row['translated_slug'] : '';
                if ($stored === '') continue;
                $stored_decoded = rawurldecode($stored);
                if ($stored_decoded === $slug_normalized || $stored_decoded === $slug) {
                    $post_id = (int) $row['post_id'];
                    self::fix_encoded_slug_in_db($post_id, $lang, $stored);
                    return $post_id;
                }
            }
        }
        return null;
    }

    /**
     * Replace URL-encoded translated_slug with UTF-8 so future lookups match without fallback.
     *
     * @param int $post_id Post ID
     * @param string $lang Language code
     * @param string $encoded_slug Current stored (encoded) slug
     */
    private static function fix_encoded_slug_in_db($post_id, $lang, $encoded_slug)
    {
        $decoded = rawurldecode($encoded_slug);
        if ($decoded === $encoded_slug || !mb_check_encoding($decoded, 'UTF-8')) {
            return;
        }
        global $wpdb;
        $table = self::table_name();
        $schema = self::detect_schema();
        $colLang = $schema === 'original' ? 'language_code' : 'lang';
        $colSource = $schema === 'original' ? 'original_slug' : 'source_slug';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$colSource} FROM {$table} WHERE post_id = %d AND {$colLang} = %s",
            $post_id,
            $lang
        ), ARRAY_A);
        if (!is_array($row) || empty($row[$colSource])) {
            return;
        }
        $source_slug = (string) $row[$colSource];
        $version = md5($source_slug);
        self::upsert_row($post_id, $lang, $source_slug, $decoded, $version);
    }

    /**
     * Resolve any slug (source or translated) to source slug.
     * If post_type is provided, filters by that type to handle slug conflicts.
     *
     * @param string $path Slug to resolve
     * @param string|null $post_type Optional post type to filter by
     * @return string|null Source slug or null
     */
    public static function resolve_any_to_source_slug($path, $post_type = null)
    {
        global $wpdb;
        $table = self::table_name();
        $slug = trim($path, '/');
        if ($slug === '') return null;
        $schema = self::detect_schema();
        $colSource = $schema === 'original' ? 'original_slug' : 'source_slug';
        
        // If post_type is specified, filter by it to handle slug conflicts
        if ($post_type !== null) {
            // Get all posts with this translated slug, then filter by post_type
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, {$colSource} FROM {$table} WHERE translated_slug = %s",
                $slug
            ), ARRAY_A);
            
            if (!empty($rows)) {
                foreach ($rows as $row) {
                    $post_id = (int) $row['post_id'];
                    $post = get_post($post_id);
                    if ($post && $post->post_type === $post_type) {
                        return isset($row[$colSource]) ? (string) $row[$colSource] : null;
                    }
                }
            }
        }
        
        // Default behavior: return first match (for backward compatibility)
        $source = $wpdb->get_var($wpdb->prepare("SELECT {$colSource} FROM {$table} WHERE translated_slug = %s LIMIT 1", $slug));
        if ($source) return (string) $source;

        // Fallback: match URL-encoded stored slug (e.g. Hungarian)
        $encoded_like = '%' . $wpdb->esc_like('%', '\\') . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, {$colSource}, translated_slug FROM {$table} WHERE translated_slug LIKE %s",
            $encoded_like
        ), ARRAY_A);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $stored = isset($row['translated_slug']) ? (string) $row['translated_slug'] : '';
                if ($stored !== '' && rawurldecode($stored) === $slug) {
                    return isset($row[$colSource]) ? (string) $row[$colSource] : null;
                }
            }
        }
        return null;
    }

    /**
     * Get or create a translated slug for a post and language.
     *
     * @param int $post_id
     * @param string $lang target language
     * @param bool $allow_translate When false, only return existing slug (no API/segment cache write). Use when serving from page cache.
     * @return string|null translated slug
     */
    public static function get_or_generate($post_id, $lang, $allow_translate = true)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return null;
        $default = AI_Lang::default();
        if ($default === null) return null;
        // Homepage should always be '/{lang}/'
        $front_id = (int) get_option('page_on_front');
        if ($front_id > 0 && $post_id === $front_id) {
            if (!$allow_translate) {
                $row = self::get_row($post_id, $lang);
                return ($row && isset($row['translated_slug'])) ? (string) $row['translated_slug'] : '';
            }
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
            if (!$allow_translate) {
                return $existing;
            }
            if (str_ends_with($source_slug, $existing) && (strlen($source_slug) - strlen($existing)) >= 2) {
                self::upsert_row($post_id, $lang, $source_slug, $source_slug, $version);
                return $source_slug;
            }
            return $existing;
        }

        // When serving from page cache we must not trigger translation (no segment cache write)
        if (!$allow_translate) {
            return null;
        }

        // Translate once via provider (batch-providers), using AI_Batch::translate_plan
        $plan = [
            'segments' => [ ['id' => 'slug', 'text' => $source_slug, 'type' => 'meta'] ],
        ];
        $settings = get_option('ai_translate_settings', []);
        $keep_slugs_english = isset($settings['keep_slugs_in_english']) ? (bool) $settings['keep_slugs_in_english'] : false;

        // If slugs should be kept in English, return source slug directly
        if ($keep_slugs_english) {
            self::upsert_row($post_id, $lang, $source_slug, $source_slug, $version);
            return $source_slug;
        }

        $ctx = [ 'website_context' => AI_Translate_Core::get_website_context() ];
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
        
        // Log for warm cache debugging
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $is_warm_cache_request = (strpos($user_agent, 'AITranslateCacheWarmer') !== false);
        if ($is_warm_cache_request) {
            $uploads = wp_upload_dir();
            $log_dir = trailingslashit($uploads['basedir']) . 'ai-translate/logs/';
            if (!is_dir($log_dir)) {
                wp_mkdir_p($log_dir);
            }
            $log_file = $log_dir . 'warm-cache-debug.log';
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[{$timestamp}] SLUGS_GET_OR_GENERATE: Generated new slug | post_id: {$post_id} | lang: {$lang} | translated_slug: {$translated_slug}\n";
            @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        }
        
        return $translated_slug;
    }

    /**
     * Fallback: find post_id for a translated slug when direct lookup fails.
     * Prefers non-attachment posts (service, page, post).
     * 
     * @param string $translated_slug The translated slug to search for
     * @param string $lang Language code
     * @return int|null Post ID or null if not found
     */
    public static function resolve_translated_slug_to_post($translated_slug, $lang)
    {
        global $wpdb;
        $table = self::table_name();
        $schema = self::detect_schema();
        $colLang = $schema === 'original' ? 'language_code' : 'lang';
        
        // Find all posts with this translated slug
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id FROM {$table} WHERE translated_slug = %s AND {$colLang} = %s",
            $translated_slug,
            $lang
        ), ARRAY_A);
        
        if (empty($rows)) {
            return null;
        }
        
        // Prefer non-attachment posts
        foreach ($rows as $row) {
            $post_id = (int) $row['post_id'];
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish' && $post->post_type !== 'attachment') {
                return $post_id;
            }
        }
        
        // Fallback: return first post if all are attachments
        foreach ($rows as $row) {
            $post_id = (int) $row['post_id'];
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                return $post_id;
            }
        }
        
        return null;
    }

    /**
     * Resolve translated slug to post ID, filtered by post_type to handle slug conflicts.
     *
     * @param string $translated_slug The translated slug
     * @param string $lang Language code
     * @param string $post_type Expected post type
     * @return int|null Post ID or null
     */
    public static function resolve_translated_slug_to_post_by_type($translated_slug, $lang, $post_type)
    {
        global $wpdb;
        $table = self::table_name();
        $schema = self::detect_schema();
        $colLang = $schema === 'original' ? 'language_code' : 'lang';
        
        // Find all posts with this translated slug
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, translated_slug FROM {$table} WHERE translated_slug = %s AND {$colLang} = %s",
            $translated_slug,
            $lang
        ), ARRAY_A);

        // Fallback: match URL-encoded stored slug (e.g. Hungarian); compare in decoded form
        if (empty($rows)) {
            $slug_norm = (strpos($translated_slug, '%') !== false) ? rawurldecode($translated_slug) : $translated_slug;
            if ($slug_norm !== $translated_slug && !mb_check_encoding($slug_norm, 'UTF-8')) {
                $slug_norm = $translated_slug;
            }
            $encoded_like = '%' . $wpdb->esc_like('%', '\\') . '%';
            $rows_enc = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, translated_slug FROM {$table} WHERE {$colLang} = %s AND translated_slug LIKE %s",
                $lang,
                $encoded_like
            ), ARRAY_A);
            if (is_array($rows_enc)) {
                foreach ($rows_enc as $row) {
                    $stored = isset($row['translated_slug']) ? (string) $row['translated_slug'] : '';
                    if ($stored === '') continue;
                    $stored_decoded = rawurldecode($stored);
                    if ($stored_decoded === $slug_norm || $stored_decoded === $translated_slug) {
                        $post_id = (int) $row['post_id'];
                        self::fix_encoded_slug_in_db($post_id, $lang, $stored);
                        $rows = [ $row ];
                        break;
                    }
                }
            }
        }

        if (empty($rows)) {
            return null;
        }

        // First try: find post with matching post_type
        foreach ($rows as $row) {
            $post_id = (int) $row['post_id'];
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish' && $post->post_type === $post_type) {
                return $post_id;
            }
        }

        return null;
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

        // Check persistent transient to avoid SHOW COLUMNS on every request
        $cached = get_transient('ai_tr_slugs_schema');
        if ($cached !== false && in_array($cached, ['original', 'new'], true)) {
            $schema = $cached;
            return $schema;
        }

        global $wpdb;
        $table = self::table_name();
        $cols = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $table), 0);
        if (!is_array($cols)) {
            // Safe default: assume original to avoid querying non-existent 'lang'/'source_slug'
            $schema = 'original';
            set_transient('ai_tr_slugs_schema', $schema, DAY_IN_SECONDS);
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
        set_transient('ai_tr_slugs_schema', $schema, DAY_IN_SECONDS);
        return $schema;
    }
}


