<?php
/**
 * AI Translate Cache Metadata Manager
 * 
 * Handles cache metadata tracking in the database for performance optimization.
 * Avoids filesystem scanning by maintaining a database table of cached pages and their languages.
 *
 * @package AITranslate
 */

namespace AITranslate;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cache Metadata Manager Class
 * 
 * Manages the wp_ai_translate_cache_meta table for tracking which pages are cached in which languages.
 */
class AI_Cache_Meta
{
    /**
     * Get the table name with WordPress prefix
     *
     * @return string Full table name
     */
    public static function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'ai_translate_cache_meta';
    }

    /**
     * Get the cache directory path for the current domain (same logic as AI_Cache)
     *
     * @return string Cache directory path
     */
    private static function get_cache_directory()
    {
        $uploads = wp_upload_dir();
        $base = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
        
        // Add site-specific directory if multi-domain caching is enabled
        $site_dir = self::get_site_cache_dir();
        if (!empty($site_dir)) {
            $base = trailingslashit($base) . $site_dir . '/';
        }
        
        return $base;
    }
    
    /**
     * Get site-specific cache directory name (same logic as AI_Cache::get_site_cache_dir)
     *
     * @return string
     */
    private static function get_site_cache_dir()
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
     * Create the cache metadata table
     * Called on plugin activation
     *
     * @return void
     */
    public static function create_table()
    {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            language_code VARCHAR(10) NOT NULL,
            cache_file VARCHAR(255) NOT NULL,
            cache_hash VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            file_size INT UNSIGNED DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY post_lang (post_id, language_code),
            KEY post_id (post_id),
            KEY language_code (language_code),
            KEY created_at (created_at),
            KEY language_created (language_code, created_at),
            KEY post_id_created (post_id, created_at),
            KEY cache_hash (cache_hash)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Ensure indexes exist (for existing installations)
        self::ensure_indexes();
    }

    /**
     * Ensure all required indexes exist on the cache metadata table
     * Called during table creation and plugin updates
     *
     * @return void
     */
    public static function ensure_indexes()
    {
        global $wpdb;

        if (get_option('ai_translate_cache_meta_indexes_applied', false)) {
            return;
        }

        $table_name = self::get_table_name();

        // Check if table exists first
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) !== $table_name) {
            return;
        }

        // Get existing indexes
        $existing_indexes = $wpdb->get_col($wpdb->prepare(
            "SHOW INDEX FROM %i WHERE Key_name != 'PRIMARY'",
            $table_name
        ));

        $indexes_to_check = [
            'post_lang' => "UNIQUE KEY post_lang (post_id, language_code)",
            'post_id' => "KEY post_id (post_id)",
            'language_code' => "KEY language_code (language_code)",
            'created_at' => "KEY created_at (created_at)",
            'language_created' => "KEY language_created (language_code, created_at)",
            'post_id_created' => "KEY post_id_created (post_id, created_at)",
            'cache_hash' => "KEY cache_hash (cache_hash)"
        ];

        foreach ($indexes_to_check as $index_name => $index_definition) {
            if (in_array($index_name, $existing_indexes, true)) {
                continue;
            }

            // Guard against race conditions / duplicate key errors by double-checking the schema.
            $schema_index_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE table_schema = %s AND table_name = %s AND index_name = %s",
                $wpdb->dbname,
                str_replace($wpdb->prefix, '', $table_name),
                $index_name
            ));
            if ((int) $schema_index_exists > 0) {
                continue;
            }

            // Index doesn't exist, create it
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $result = $wpdb->query($wpdb->prepare(
                "ALTER TABLE %i ADD %1s",
                $table_name,
                $index_definition
            ));

            if ($result === false && stripos($wpdb->last_error, 'Duplicate key name') !== false) {
                $wpdb->flush();
            }
        }

        update_option('ai_translate_cache_meta_indexes_applied', true);
    }

    /**
     * Insert or update cache metadata record
     *
     * @param int    $post_id       Post ID
     * @param string $language_code Language code (e.g. 'de', 'fr')
     * @param string $cache_file    Full path to cache file
     * @param string $cache_hash    Hash of the cached content
     * @return bool Success
     */
    public static function insert($post_id, $language_code, $cache_file, $cache_hash)
    {
        global $wpdb;
        
        // Ensure table exists before inserting
        self::ensure_table_exists();
        
        $file_size = file_exists($cache_file) ? filesize($cache_file) : 0;
        
        $result = $wpdb->replace(
            self::get_table_name(),
            array(
                'post_id'       => $post_id,
                'language_code' => $language_code,
                'cache_file'    => $cache_file,
                'cache_hash'    => $cache_hash,
                'created_at'    => current_time('mysql'),
                'file_size'     => $file_size
            ),
            array('%d', '%s', '%s', '%s', '%s', '%d')
        );
        
        return $result !== false;
    }

    /**
     * Delete cache metadata for a post
     *
     * @param int         $post_id       Post ID
     * @param string|null $language_code Optional language code (null = all languages)
     * @return int Number of records deleted
     */
    public static function delete($post_id, $language_code = null)
    {
        global $wpdb;
        
        $where = array('post_id' => $post_id);
        $where_format = array('%d');
        
        if ($language_code !== null) {
            $where['language_code'] = $language_code;
            $where_format[] = '%s';
        }
        
        return (int) $wpdb->delete(
            self::get_table_name(),
            $where,
            $where_format
        );
    }

    /**
     * Clear all cached translations for a post: delete cache files and metadata.
     * Gebruikt bij inhouds- of titelwijziging zodat bij het volgende bezoek opnieuw wordt vertaald.
     *
     * @param int $post_id Post ID
     * @return array{deleted: int, meta_deleted: int, errors: string[]}
     */
    public static function clear_post_cache($post_id)
    {
        $deleted = 0;
        $errors = [];
        self::ensure_table_exists();
        $records = self::get($post_id);
        $uploads = wp_upload_dir();
        $allowed_base = wp_normalize_path(trailingslashit($uploads['basedir']) . 'ai-translate/cache/');

        foreach ($records as $record) {
            if (empty($record->cache_file)) {
                continue;
            }
            $cache_file = wp_normalize_path($record->cache_file);
            if (strpos($cache_file, $allowed_base) !== 0) {
                continue;
            }
            if (!file_exists($cache_file) || !is_file($cache_file)) {
                continue;
            }
            if (substr($cache_file, -5) !== '.html') {
                continue;
            }
            if (@unlink($cache_file)) {
                ++$deleted;
            } else {
                $errors[] = basename($cache_file);
            }
        }

        $meta_deleted = self::delete($post_id);
        delete_transient('ai_translate_cache_table_data');

        return ['deleted' => $deleted, 'meta_deleted' => $meta_deleted, 'errors' => $errors];
    }

    /**
     * Delete cache metadata for a specific language
     *
     * @param string $language_code Language code
     * @return int Number of records deleted
     */
    public static function delete_by_language($language_code)
    {
        global $wpdb;
        
        return (int) $wpdb->delete(
            self::get_table_name(),
            array('language_code' => $language_code),
            array('%s')
        );
    }

    /**
     * Delete cache metadata for a specific cache file
     *
     * @param string $cache_file Full path to cache file
     * @return int Number of records deleted
     */
    public static function delete_by_file($cache_file)
    {
        global $wpdb;
        
        // Ensure table exists before querying
        self::ensure_table_exists();
        
        return (int) $wpdb->delete(
            self::get_table_name(),
            array('cache_file' => wp_normalize_path($cache_file)),
            array('%s')
        );
    }

    /**
     * Delete cache metadata for all records whose cache_file path starts with the given prefix.
     * Used when clearing disk cache for a directory so the "X of Y" per-page status stays in sync.
     *
     * @param string $path_prefix Directory path prefix (e.g. .../ai-translate/cache/de/pages/)
     * @return int Number of records deleted
     */
    public static function delete_by_path_prefix($path_prefix)
    {
        global $wpdb;

        self::ensure_table_exists();

        $normalized = wp_normalize_path($path_prefix);
        $pattern = $wpdb->esc_like($normalized) . '%';
        $table = self::get_table_name();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE cache_file LIKE %s",
            $pattern
        ));

        if ($deleted > 0) {
            delete_transient('ai_translate_cache_table_data');
        }

        return $deleted;
    }

    /**
     * Get all cache records for a post
     *
     * @param int $post_id Post ID
     * @return array Array of cache records
     */
    public static function get($post_id)
    {
        global $wpdb;
        
        // Ensure table exists before querying
        self::ensure_table_exists();
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::get_table_name() . " WHERE post_id = %d",
            $post_id
        ));
        
        return is_array($results) ? $results : array();
    }

    /**
     * Get posts with cache statistics
     * Optimized single query with JOIN
     *
     * @param int $offset Posts offset for pagination
     * @param int $limit  Posts per page
     * @return array Array of posts with cache info
     */
    public static function get_posts_with_cache_stats($offset = 0, $limit = 50)
    {
        global $wpdb;
        
        // Ensure table exists before querying
        self::ensure_table_exists();
        
        $enabled = \AITranslate\AI_Lang::enabled();
        $detectable = \AITranslate\AI_Lang::detectable();
        // Normalize to lowercase before merging to avoid case-sensitivity issues (e.g., "IT" vs "it")
        $enabled_normalized = array_map('strtolower', $enabled);
        $detectable_normalized = array_map('strtolower', $detectable);
        $all_langs = array_unique(array_merge($enabled_normalized, $detectable_normalized));
        
        $table_name = self::get_table_name();
        
        // Also get all unique languages from cache metadata table to ensure we count all active languages
        // This catches any languages that might be cached but not in enabled/detectable arrays
        $cached_langs_in_db = $wpdb->get_col("SELECT DISTINCT LOWER(language_code) FROM " . $table_name);
        if (is_array($cached_langs_in_db) && !empty($cached_langs_in_db)) {
            $all_langs = array_unique(array_merge($all_langs, $cached_langs_in_db));
        }
        
        // Exclude default language from total count (default language is not translated)
        $default_lang = \AITranslate\AI_Lang::default();
        if ($default_lang) {
            $default_lang_normalized = strtolower($default_lang);
            $all_langs = array_filter($all_langs, function($lang) use ($default_lang_normalized) {
                return $lang !== $default_lang_normalized;
            });
        }
        $total_languages = count($all_langs);
        
        // Get all public post types (including custom post types)
        $public_post_types = get_post_types(array('public' => true), 'names');
        // Exclude attachments as they should not be translated
        $public_post_types = array_diff($public_post_types, array('attachment'));
        // Fallback to page and post if no public post types found
        if (empty($public_post_types)) {
            $public_post_types = array('page', 'post');
        }
        $post_types_placeholders = implode(', ', array_fill(0, count($public_post_types), '%s'));
        
        // Try to execute query with JOIN first
        $query = $wpdb->prepare(
            "SELECT 
                p.ID,
                p.post_type,
                p.post_title,
                COUNT(c.language_code) as cached_languages
            FROM 
                {$wpdb->posts} p
            LEFT JOIN 
                " . $table_name . " c ON p.ID = c.post_id
            WHERE 
                p.post_status = 'publish'
                AND p.post_type IN ($post_types_placeholders)
            GROUP BY 
                p.ID, p.post_type, p.post_title
            ORDER BY 
                p.post_type ASC, p.post_title ASC
            LIMIT %d OFFSET %d",
            array_merge(array_values($public_post_types), array($limit, $offset))
        );
        
        $results = $wpdb->get_results($query);
        
        // If query failed (e.g., table doesn't exist), use fallback query
        if (!is_array($results) || !empty($wpdb->last_error)) {
            $wpdb->last_error = ''; // Clear error
            // Fallback query without JOIN
            $query = $wpdb->prepare(
                "SELECT 
                    p.ID,
                    p.post_type,
                    p.post_title
                FROM 
                    {$wpdb->posts} p
                WHERE 
                    p.post_status = 'publish'
                    AND p.post_type IN ($post_types_placeholders)
                ORDER BY 
                    p.post_type ASC, p.post_title ASC
                LIMIT %d OFFSET %d",
                array_merge(array_values($public_post_types), array($limit, $offset))
            );
            $results = $wpdb->get_results($query);
            if (!is_array($results)) {
                $results = array();
            }
            // Set cached_languages to 0 for all posts
            foreach ($results as &$row) {
                $row->cached_languages = 0;
            }
        }
        
        // Add homepage row for "latest posts" front page (show_on_front = posts).
        // For a static front page, the homepage is already a regular page post and is included via the posts query.
        // Always show homepage in list when show_on_front = posts, even if no metadata records exist yet
        if ($offset === 0 && get_option('show_on_front') === 'posts') {
            $homepage_cached = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT language_code) FROM " . $table_name . " WHERE post_id = %d",
                0
            ));

            $homepage_obj = new \stdClass();
            $homepage_obj->ID = 0;
            $homepage_obj->post_type = 'homepage';
            $homepage_obj->post_title = __('Homepage', 'ai-translate');
            $homepage_obj->cached_languages = $homepage_cached;
            $homepage_obj->total_languages = $total_languages;
            $homepage_obj->percentage = $total_languages > 0
                ? round(($homepage_cached / $total_languages) * 100)
                : 0;
            $homepage_obj->url = home_url('/');

            // Insert homepage at the beginning
            array_unshift($results, $homepage_obj);
        }
        
        // Add percentage and URL
        foreach ($results as &$row) {
            // Ensure cached_languages is set (should be 0 if not set)
            if (!isset($row->cached_languages)) {
                $row->cached_languages = 0;
            }
            if (!isset($row->total_languages)) {
                $row->total_languages = $total_languages;
            }
            if (!isset($row->percentage)) {
                $row->percentage = $total_languages > 0 
                    ? round(($row->cached_languages / $total_languages) * 100) 
                    : 0;
            }
            if (!isset($row->url)) {
                if ($row->ID === 0) {
                    $row->url = home_url('/');
                } else {
                    $permalink = get_permalink($row->ID);
                    $row->url = $permalink ? $permalink : '';
                }
            }
        }
        
        return $results;
    }

    /**
     * Get total count of posts (for pagination)
     *
     * @return int Total post count
     */
    public static function get_total_posts_count()
    {
        global $wpdb;
        
        // Get all public post types (including custom post types)
        $public_post_types = get_post_types(array('public' => true), 'names');
        // Exclude attachments as they should not be translated
        $public_post_types = array_diff($public_post_types, array('attachment'));
        // Fallback to page and post if no public post types found
        if (empty($public_post_types)) {
            $public_post_types = array('page', 'post');
        }
        $post_types_placeholders = implode(', ', array_fill(0, count($public_post_types), '%s'));
        
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                FROM {$wpdb->posts}
                WHERE post_status = %s
                AND post_type IN ($post_types_placeholders)",
                array_merge(array('publish'), array_values($public_post_types))
            )
        );

        // Add 1 for homepage row when the front page is a posts listing.
        // (Static front page is already included as a regular page post.)
        if (get_option('show_on_front') === 'posts') {
            $count++;
        }
        
        return (int) $count;
    }
    
    /**
     * Get total count of cache metadata records
     *
     * @return int Total cache metadata count
     */
    public static function get_total_cache_meta_count()
    {
        global $wpdb;
        
        // Ensure table exists before querying
        self::ensure_table_exists();
        
        $table_name = self::get_table_name();
        
        // Try to get count, return 0 if table doesn't exist
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $table_name));
        
        if ($wpdb->last_error) {
            return 0;
        }
        
        return (int) $count;
    }
    
    /**
     * Get cached URLs for a language without preloading everything (lazy for admin).
     *
     * @param string $language_code Language code.
     * @param int    $limit         Max number of records to return (cap at 1000).
     * @return array{language:string,total:int,items:array<int,array{post_id:int,url:string,title:string,file_size:int,updated_at:int,cache_file:string}>,truncated:bool}
     */
    public static function get_cached_urls_for_language($language_code, $limit = 300)
    {
        global $wpdb;
        
        self::ensure_table_exists();
        
        $lang = strtolower(sanitize_key($language_code));
        if ($lang === '') {
            return [
                'language' => '',
                'total' => 0,
                'items' => [],
                'truncated' => false,
            ];
        }
        
        $limit = max(1, min((int) $limit, 1000));
        $table = self::get_table_name();
        
        // Count actual cache files on filesystem for this language
        $uploads = wp_upload_dir();
        $cache_base = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
        
        // Add site-specific directory if multi-domain caching is enabled
        $site_dir = self::get_site_cache_dir();
        if (!empty($site_dir)) {
            $cache_base = trailingslashit($cache_base) . $site_dir . '/';
        }
        
        $lang_cache_dir = $cache_base . $lang . '/pages/';
        $filesystem_count = 0;
        if (is_dir($lang_cache_dir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($lang_cache_dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'html') {
                    $filesystem_count++;
                }
            }
        }
        
        // Total in database for truncated flag
        $db_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE language_code = %s",
            $lang
        ));
        
        $buildItems = function() use ($wpdb, $table, $lang, $limit) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, cache_file, file_size, created_at FROM {$table} WHERE language_code = %s ORDER BY created_at DESC LIMIT %d",
                $lang,
                $limit
            ));
            
            $items = [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $cache_file = wp_normalize_path((string) $row->cache_file);
                    
                    // Skip invalid paths or missing files
                    if ($cache_file === '' || !file_exists($cache_file) || !is_file($cache_file)) {
                        continue;
                    }
                    
                    $post_id = (int) $row->post_id;
                    $title = $post_id === 0 ? __('Homepage', 'ai-translate') : get_the_title($post_id);
                    if ($title === '') {
                        $title = sprintf(__('Post %d', 'ai-translate'), $post_id);
                    }
                    // Decode HTML entities to normal characters
                    $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    
                    $url = self::build_translated_url($post_id, $lang);
                    $mtime = @filemtime($cache_file);
                    $filesize = file_exists($cache_file) ? (int) filesize($cache_file) : 0;
                    
                    $items[] = [
                        'post_id' => $post_id,
                        'url' => $url,
                        'title' => $title,
                        'file_size' => $filesize,
                        'updated_at' => $mtime ? (int) $mtime : (int) strtotime((string) $row->created_at),
                        'cache_file' => $cache_file,
                    ];
                }
            }
            return $items;
        };
        
        $items = $buildItems();
        
        // Als het aantal bestanden op filesystem hoger is dan in database,
        // voeg alleen de ontbrekende bestanden toe zonder volledige rescan (te traag voor AJAX)
        // De volledige rescan kan handmatig worden gedaan via een aparte actie
        if ($filesystem_count > count($items) && is_dir($lang_cache_dir)) {
            $db_files = array_column($items, 'cache_file');
            $db_files_normalized = array_map('wp_normalize_path', $db_files);
            
            // Limiteer het aantal bestanden dat we scannen om timeout te voorkomen
            $max_scan = 50; // Max 50 bestanden per AJAX call
            $scanned = 0;
            
            // Scan filesystem voor ontbrekende bestanden (beperkt)
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($lang_cache_dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($scanned >= $max_scan) {
                    break; // Stop na max aantal scans
                }
                
                if ($file->isFile() && $file->getExtension() === 'html') {
                    $file_path = wp_normalize_path($file->getPathname());
                    if (!in_array($file_path, $db_files_normalized, true)) {
                        $scanned++;
                        
                        // Probeer snel te bepalen wat het is zonder volledige file read
                        // Gebruik alleen filename hash matching als snelle check
                        $filename = basename($file_path, '.html');
                        
                        // Quick check: als het een bekende homepage hash is
                        global $wpdb;
                        $db_identifier = defined('DB_NAME') ? DB_NAME : '';
                        $table_prefix = isset($wpdb->prefix) ? $wpdb->prefix : 'wp_';
                        $site_hash = substr(md5($db_identifier . '|' . $table_prefix), 0, 8);
                        $homepage_route = 'path:' . md5('/');
                        $homepage_hash = md5('ait:v4:' . $site_hash . ':' . $lang . ':' . $homepage_route);
                        
                        $post_id = -1;
                        $title = __('Unknown (not in DB)', 'ai-translate');
                        $url = '#';
                        
                        if ($filename === $homepage_hash) {
                            $post_id = 0;
                            $title = __('Homepage (not in DB)', 'ai-translate');
                            $url = self::build_translated_url(0, $lang);
                        } else {
                            // Probeer alleen eerste paar bytes te lezen voor title
                            $content = @file_get_contents($file_path, false, null, 0, 2000);
                            if ($content !== false && preg_match('/<title[^>]*>([^<]+)<\/title>/i', $content, $matches)) {
                                $title_text = trim(strip_tags($matches[1]));
                                // Decode HTML entities to normal characters
                                $title_text = html_entity_decode($title_text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                                $title = $title_text . ' (' . __('not in DB', 'ai-translate') . ')';
                            }
                        }
                        
                        $items[] = [
                            'post_id' => $post_id,
                            'url' => $url,
                            'title' => $title,
                            'file_size' => (int) $file->getSize(),
                            'updated_at' => (int) $file->getMTime(),
                            'cache_file' => $file_path,
                        ];
                    }
                }
            }
            
            // Als er nog meer bestanden zijn, voeg een melding toe
            if ($filesystem_count > count($items)) {
                $remaining = $filesystem_count - count($items);
                $items[] = [
                    'post_id' => -2, // Special ID voor info message
                    'url' => '#',
                    'title' => sprintf(__('... and %d more files not in database (use rescan to add them)', 'ai-translate'), $remaining),
                    'file_size' => 0,
                    'updated_at' => 0,
                    'cache_file' => '',
                ];
            }
        }
        
        return [
            'language' => $lang,
            'total' => max($filesystem_count, $db_count),
            'items' => $items,
            'truncated' => max($filesystem_count, $db_count) > $limit,
        ];
    }
    
    /**
     * Build translated URL for a post and language without triggering new translations.
     *
     * @param int    $post_id Post ID (0 = homepage).
     * @param string $lang    Language code.
     * @return string
     */
    private static function build_translated_url($post_id, $lang)
    {
        $lang = strtolower(sanitize_key($lang));
        $path = '/';
        
        if ($post_id === 0) {
            $path = '/';
        } else {
            $permalink = get_permalink($post_id);
            if ($permalink) {
                $parsed = parse_url($permalink, PHP_URL_PATH);
                if (is_string($parsed) && $parsed !== '') {
                    $path = '/' . ltrim($parsed, '/');
                }
            }
        }
        
        $path = trim($path, '/');
        
        // Gebruik bestaande vertaalde slug als die er is (geen nieuwe vertaling triggeren)
        $translated_slug = self::get_translated_slug_for_lang($post_id, $lang);
        if ($translated_slug !== '') {
            $segments = $path === '' ? [] : explode('/', $path);
            if (!empty($segments)) {
                // Vervang laatste segment door de vertaalde slug
                $segments[count($segments) - 1] = $translated_slug;
            } else {
                $segments[] = $translated_slug;
            }
            $path = implode('/', array_map('rawurlencode', $segments));
        }
        
        $translated_path = '/' . $lang;
        if ($path !== '') {
            $translated_path .= '/' . $path;
        }
        $translated_path = user_trailingslashit('/' . trim($translated_path, '/'));
        
        return home_url($translated_path);
    }
    
    /**
     * Haal bestaande vertaalde slug op uit de slug-tabel (geen nieuwe vertaling genereren).
     *
     * @param int    $post_id Post ID
     * @param string $lang    Language code
     * @return string Vertaalde slug of lege string
     */
    private static function get_translated_slug_for_lang($post_id, $lang)
    {
        if ($post_id <= 0 || $lang === '') {
            return '';
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ai_translate_slugs';
        
        // Detecteer kolomnamen (oude vs nieuwe schema)
        static $schema = null;
        if ($schema === null) {
            $cols = $wpdb->get_col($wpdb->prepare("SHOW COLUMNS FROM %i", $table), 0);
            $schema = [
                'lang_col' => in_array('lang', (array) $cols, true) ? 'lang' : 'language_code',
                'source_col' => in_array('source_slug', (array) $cols, true) ? 'source_slug' : 'original_slug',
            ];
        }
        
        $lang_col = $schema['lang_col'];
        $translated_slug = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT translated_slug FROM {$table} WHERE post_id = %d AND {$lang_col} = %s LIMIT 1",
            $post_id,
            $lang
        ));
        
        return $translated_slug !== '' ? $translated_slug : '';
    }
    
    /**
     * Ensure the cache metadata table exists, create it if it doesn't
     *
     * @return void
     */
    public static function ensure_table_exists()
    {
        global $wpdb;
        
        $table_name = self::get_table_name();
        
        // Try to query the table - if it fails, it doesn't exist
        $wpdb->suppress_errors();
        $result = $wpdb->get_var("SELECT COUNT(*) FROM " . $table_name . " LIMIT 1");
        $wpdb->suppress_errors(false);
        
        // If query failed, table doesn't exist
        if ($wpdb->last_error) {
            $wpdb->last_error = ''; // Clear error
            // Table doesn't exist, create it
            self::create_table();
        }
    }

    /**
     * Background sync: clean up orphaned records (cache files that no longer exist)
     * Should be called via WP Cron every hour
     *
     * @return int Number of orphaned records removed
     */
    public static function sync_from_filesystem()
    {
        global $wpdb;
        
        // Ensure table exists before querying
        self::ensure_table_exists();
        
        // Get all metadata records
        $records = $wpdb->get_results("SELECT id, cache_file FROM " . self::get_table_name());
        
        if (!is_array($records)) {
            return 0;
        }
        
        $removed = 0;
        foreach ($records as $record) {
            // Check if cache file still exists
            if (!file_exists($record->cache_file)) {
                // File no longer exists, remove metadata record
                $wpdb->delete(
                    self::get_table_name(),
                    array('id' => $record->id),
                    array('%d')
                );
                $removed++;
            }
        }
        
        return $removed;
    }

    /**
     * Populate metadata for existing cache files (one-time migration)
     * Scans cache files and adds metadata for posts only (not paths)
     *
     * @param bool $force_rescan Force rescan even if already populated
     * @return int Number of records added
     */
    public static function populate_existing_cache($force_rescan = false)
    {
        // First, try to match cache files by scanning filesystem and matching with posts
        // This is more reliable than generating keys and checking if files exist
        $added = self::scan_filesystem_for_cache($force_rescan);
        
        // Also try the reverse: generate keys for all posts and check if files exist
        // This catches any files that the filesystem scan might have missed
        // Include all post types (attachments included so we can detect if they were incorrectly cached)
        // The actual prevention happens in class-ai-ob.php where attachments are excluded from caching
        global $wpdb;
        $all_post_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status IN ('publish', 'inherit') AND post_type NOT IN ('revision', 'nav_menu_item', 'custom_css', 'jp_img_sitemap', 'jp_sitemap', 'jp_sitemap_master', 'wds-slider', 'fmemailverification', 'is_search_form', 'oembed_cache')"
        );
        
        // Also include homepage (post_id = 0)
        $posts = array_merge(array(0), (array) $all_post_ids);
        
        if (empty($posts)) {
            return $added;
        }
        
        $enabled = \AITranslate\AI_Lang::enabled();
        $detectable = \AITranslate\AI_Lang::detectable();
        // Normalize to lowercase before merging to avoid case-sensitivity issues (e.g., "IT" vs "it")
        $enabled_normalized = array_map('strtolower', $enabled);
        $detectable_normalized = array_map('strtolower', $detectable);
        $all_langs = array_unique(array_merge($enabled_normalized, $detectable_normalized));
        $default_lang = \AITranslate\AI_Lang::default();
        $default_lang_normalized = $default_lang ? strtolower($default_lang) : null;
        
        foreach ($posts as $post_id) {
            // Handle homepage (post_id = 0)
            if ($post_id === 0) {
                $route_id = 'path:' . md5('/');
            } else {
                $permalink = get_permalink($post_id);
                if (!$permalink) {
                    continue;
                }
                $route_id = 'post:' . $post_id;
            }
            
            foreach ($all_langs as $lang) {
                // Skip default language
                if ($default_lang_normalized && $lang === $default_lang_normalized) {
                    continue;
                }
                
                // Skip if already cached (unless force rescan)
                if (!$force_rescan && self::is_cached($post_id, $lang)) {
                    continue;
                }
                
                // Generate cache key
                $key = \AITranslate\AI_Cache::key($lang, $route_id, '');
                $file_path = \AITranslate\AI_Cache::get_file_path($key);
                
                // Check if cache file exists
                if ($file_path && file_exists($file_path)) {
                    $cache_hash = md5($key);
                    if (self::insert($post_id, $lang, $file_path, $cache_hash)) {
                        $added++;
                    }
                }
            }
        }
        
        return $added;
    }
    
    /**
     * Scan filesystem for cache files and add metadata
     * Uses the same directory logic as AI_Cache to find the correct domain directory
     *
     * @param bool $force_rescan Force rescan even if metadata exists
     * @return int Number of records added
     */
    private static function scan_filesystem_for_cache($force_rescan = false)
    {
        // Use the same directory logic as AI_Cache::get_file_path()
        $cache_dir = self::get_cache_directory();
        
        // Always check what's actually in the uploads directory
        $uploads = wp_upload_dir();
        $base_cache_dir = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
        
        if (!is_dir($base_cache_dir)) {
            return 0;
        }
        
        // List all directories in base cache directory
        $dirs = glob($base_cache_dir . '*/', GLOB_ONLYDIR);
        
        if (is_array($dirs) && !empty($dirs)) {
            // Multi-domain: scan all domain directories
            $added = 0;
            foreach ($dirs as $domain_dir) {
                $result = self::scan_cache_directory($domain_dir, $force_rescan);
                $added += $result;
            }
            return $added;
        } else {
            // Single domain: scan base directory
            return self::scan_cache_directory($base_cache_dir, $force_rescan);
        }
    }
    
    /**
     * Scan a cache directory for cache files and extract post_id from route_id
     *
     * @param string $cache_dir Cache directory path
     * @param bool $force_rescan Force rescan even if metadata exists
     * @return int Number of records added
     */
    private static function scan_cache_directory($cache_dir, $force_rescan = false)
    {
        if (!is_dir($cache_dir)) {
            return 0;
        }
        
        $added = 0;
        $files_found = 0;
        $files_matched = 0;
        
        // Get all published posts first (including all post types)
        // Note: Attachments are included here so we can detect if they were incorrectly cached
        // The actual prevention happens in class-ai-ob.php where attachments are excluded from caching
        global $wpdb;
        $all_post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                WHERE post_status IN ('publish', 'inherit')
                AND post_type NOT IN ('revision', 'nav_menu_item', 'custom_css', 'jp_img_sitemap', 'jp_sitemap', 'jp_sitemap_master', 'wds-slider', 'fmemailverification', 'is_search_form', 'oembed_cache')
                ORDER BY ID ASC
                LIMIT %d",
                10000
            )
        );
        
        // Also include homepage (post_id = 0)
        $posts = array_merge(array(0), (array) $all_post_ids);
        
        if (empty($posts)) {
            return 0;
        }
        
        $enabled = \AITranslate\AI_Lang::enabled();
        $detectable = \AITranslate\AI_Lang::detectable();
        // Normalize to lowercase before merging to avoid case-sensitivity issues (e.g., "IT" vs "it")
        $enabled_normalized = array_map('strtolower', $enabled);
        $detectable_normalized = array_map('strtolower', $detectable);
        $all_langs = array_unique(array_merge($enabled_normalized, $detectable_normalized));
        $default_lang = \AITranslate\AI_Lang::default();
        $default_lang_normalized = $default_lang ? strtolower($default_lang) : null;
        
        // Scan language directories - try both with and without 'pages' subdirectory
        $lang_dirs = glob($cache_dir . '*/pages/', GLOB_ONLYDIR);
        
        if (!is_array($lang_dirs) || empty($lang_dirs)) {
            // Try without 'pages' subdirectory (older cache format)
            $lang_dirs = glob($cache_dir . '*/', GLOB_ONLYDIR);
            if (!is_array($lang_dirs) || empty($lang_dirs)) {
                return 0;
            }
            // Filter to only language directories (2-letter codes)
            $lang_dirs = array_filter($lang_dirs, function($dir) {
                $basename = basename($dir);
                return strlen($basename) === 2 && ctype_alpha($basename);
            });
        }
        
        foreach ($lang_dirs as $lang_pages_dir) {
            // Extract language from path
            // Handle both 'lang/pages/' and 'lang/' structures
            if (basename($lang_pages_dir) === 'pages') {
                $lang = basename(dirname($lang_pages_dir));
            } else {
                $lang = basename($lang_pages_dir);
            }
            
            // Skip if language is not in enabled/detectable list
            $lang_lower = strtolower($lang);
            $is_enabled = in_array($lang_lower, $all_langs, true);
            if (!$is_enabled) {
                continue;
            }
            
            // Skip default language
            if ($default_lang_normalized && $lang_lower === $default_lang_normalized) {
                continue;
            }
            
            // Scan hash subdirectories - try both 'pages/hash/' and direct 'hash/' structures
            $hash_dirs = glob($lang_pages_dir . '*/', GLOB_ONLYDIR);
            if (!is_array($hash_dirs) || empty($hash_dirs)) {
                // Try finding files directly in language directory (older format)
                $cache_files = glob($lang_pages_dir . '*.html');
                if (is_array($cache_files) && !empty($cache_files)) {
                    // Process files directly (no hash subdirectory)
                    foreach ($cache_files as $cache_file) {
                        $added += self::process_cache_file($cache_file, $lang, $posts, $force_rescan);
                    }
                }
                continue;
            }
            
            foreach ($hash_dirs as $hash_dir) {
                // Find all .html cache files
                $cache_files = glob($hash_dir . '*.html');
                if (!is_array($cache_files) || empty($cache_files)) {
                    continue;
                }
                
                foreach ($cache_files as $cache_file) {
                    $files_found++;
                    $result = self::process_cache_file($cache_file, $lang, $posts, $force_rescan);
                    if ($result > 0) {
                        $files_matched++;
                    }
                    $added += $result;
                }
            }
        }
        
        return $added;
    }
    
    /**
     * Process a single cache file and try to match it with a post
     *
     * @param string $cache_file Full path to cache file
     * @param string $lang Language code
     * @param array $posts Array of post IDs
     * @param bool $force_rescan Force rescan even if metadata exists
     * @return int Number of records added (0 or 1)
     */
    private static function process_cache_file($cache_file, $lang, $posts, $force_rescan = false)
    {
        // Ensure table exists before querying
        self::ensure_table_exists();
        
        // Check if this file already has metadata (unless force rescan)
        if (!$force_rescan) {
            global $wpdb;
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::get_table_name() . " WHERE cache_file = %s",
                $cache_file
            ));
            
            if ($existing > 0) {
                return 0; // Already has metadata
            }
        }
        
        // Extract hash from filename (format: hash.html in subdirectory hash[0:2]/hash.html)
        $filename = basename($cache_file, '.html');
        $hash_dir = basename(dirname($cache_file));
        
        // The filename should be the full hash (32 chars), and the directory should be first 2 chars
        if (empty($filename) || strlen($filename) !== 32) {
            return 0; // Invalid filename format
        }
        
        // Try to find matching post by checking all posts
        // Generate key for each post and see if the file path matches
        $match_attempts = 0;
        foreach ($posts as $post_id) {
            $match_attempts++;
            $route_id = 'post:' . $post_id;
            $key = \AITranslate\AI_Cache::key($lang, $route_id, '');
            $expected_path = \AITranslate\AI_Cache::get_file_path($key);
            
            // Normalize paths for comparison
            $expected_path_normalized = wp_normalize_path($expected_path);
            $cache_file_normalized = wp_normalize_path($cache_file);
            
            // Check if the hash matches (most reliable method)
            $expected_hash = md5($key);
            $file_hash = $filename; // The filename is the full hash
            
            // Also check path match (in case directory structure differs)
            if ($expected_hash === $file_hash || $expected_path_normalized === $cache_file_normalized) {
                // Found matching post
                $cache_hash = md5($key);
                if (self::insert($post_id, $lang, $cache_file, $cache_hash)) {
                    return 1;
                }
                return 0;
            }
        }
        
        // If no exact match found, try reading the cache file to extract post_id
        // This handles cases where the cache file format might be different
        if (file_exists($cache_file) && is_readable($cache_file)) {
            $content = @file_get_contents($cache_file);
            if ($content !== false && strlen($content) > 0) {
                // Try to find post_id in HTML content
                // Look for common WordPress patterns like data-post-id, post-123, etc.
                if (preg_match('/data-post-id=["\'](\d+)["\']/', $content, $matches)) {
                    $found_post_id = (int) $matches[1];
                    if ($found_post_id > 0 && in_array($found_post_id, $posts, true)) {
                        $route_id = 'post:' . $found_post_id;
                        $key = \AITranslate\AI_Cache::key($lang, $route_id, '');
                        $cache_hash = md5($key);
                        if (self::insert($found_post_id, $lang, $cache_file, $cache_hash)) {
                            return 1;
                        }
                    }
                }
                
                // Try to find post ID in class names (post-123)
                if (preg_match('/\bpost-(\d+)\b/', $content, $matches)) {
                    $found_post_id = (int) $matches[1];
                    if ($found_post_id > 0 && in_array($found_post_id, $posts, true)) {
                        $route_id = 'post:' . $found_post_id;
                        $key = \AITranslate\AI_Cache::key($lang, $route_id, '');
                        $cache_hash = md5($key);
                        if (self::insert($found_post_id, $lang, $cache_file, $cache_hash)) {
                            return 1;
                        }
                    }
                }
            }
        }
        
        // Final fallback: try all post IDs in database if no match found yet
        // This catches cases where post types were excluded or posts were deleted
        // Attachments included so we can detect if they were incorrectly cached
        if (empty($posts) || count($posts) < 100) {
            global $wpdb;
            $all_post_ids_fallback = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                    WHERE ID > 0
                    AND post_type NOT IN ('revision', 'nav_menu_item')
                    ORDER BY ID ASC
                    LIMIT %d",
                    1000
                )
            );
            
            if (!empty($all_post_ids_fallback)) {
                // Also try homepage
                $all_post_ids_fallback = array_merge(array(0), $all_post_ids_fallback);
                
                foreach ($all_post_ids_fallback as $post_id) {
                    if (in_array($post_id, $posts, true)) {
                        continue; // Already checked
                    }
                    
                    $route_id = $post_id === 0 ? 'path:' . md5('/') : 'post:' . $post_id;
                    $key = \AITranslate\AI_Cache::key($lang, $route_id, '');
                    $expected_hash = md5($key);
                    
                    if ($expected_hash === $filename) {
                        // Found matching post
                        $cache_hash = md5($key);
                        if (self::insert($post_id, $lang, $cache_file, $cache_hash)) {
                            return 1;
                        }
                        return 0;
                    }
                }
            }
        }
        
        return 0;
    }

    /**
     * Get cached languages for a post
     *
     * @param int $post_id Post ID
     * @return array Array of language codes
     */
    public static function get_cached_languages($post_id)
    {
        global $wpdb;
        
        // Ensure table exists before querying
        self::ensure_table_exists();
        
        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT language_code FROM " . self::get_table_name() . " WHERE post_id = %d",
            $post_id
        ));
        
        return is_array($results) ? $results : array();
    }

    /**
     * Check if a post is cached in a specific language
     *
     * @param int    $post_id       Post ID
     * @param string $language_code Language code
     * @return bool True if cached
     */
    public static function is_cached($post_id, $language_code)
    {
        global $wpdb;
        
        // Ensure table exists before querying
        self::ensure_table_exists();
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::get_table_name() . " 
            WHERE post_id = %d AND language_code = %s",
            $post_id,
            $language_code
        ));
        
        return (int) $exists > 0;
    }
}
