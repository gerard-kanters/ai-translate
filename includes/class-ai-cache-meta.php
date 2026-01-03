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
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
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
        $all_langs = array_unique(array_merge($enabled, $detectable));
        
        // Exclude default language from total count (default language is not translated)
        $default_lang = \AITranslate\AI_Lang::default();
        if ($default_lang) {
            $all_langs = array_filter($all_langs, function($lang) use ($default_lang) {
                return strtolower($lang) !== strtolower($default_lang);
            });
        }
        $total_languages = count($all_langs);
        
        $table_name = self::get_table_name();
        
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
                AND p.post_type IN ('page', 'post')
            GROUP BY 
                p.ID, p.post_type, p.post_title
            ORDER BY 
                p.post_type ASC, p.post_title ASC
            LIMIT %d OFFSET %d",
            $limit,
            $offset
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
                    AND p.post_type IN ('page', 'post')
                ORDER BY 
                    p.post_type ASC, p.post_title ASC
                LIMIT %d OFFSET %d",
                $limit,
                $offset
            );
            $results = $wpdb->get_results($query);
            if (!is_array($results)) {
                return array();
            }
            // Set cached_languages to 0 for all posts
            foreach ($results as &$row) {
                $row->cached_languages = 0;
            }
        }
        
        // Add percentage and URL
        foreach ($results as &$row) {
            // Ensure cached_languages is set (should be 0 if not set)
            if (!isset($row->cached_languages)) {
                $row->cached_languages = 0;
            }
            $row->total_languages = $total_languages;
            $row->percentage = $total_languages > 0 
                ? round(($row->cached_languages / $total_languages) * 100) 
                : 0;
            $permalink = get_permalink($row->ID);
            $row->url = $permalink ? $permalink : '';
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
        
        $count = $wpdb->get_var(
            "SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_type IN ('page', 'post')"
        );
        
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
        $count = $wpdb->get_var("SELECT COUNT(*) FROM " . $table_name);
        
        if ($wpdb->last_error) {
            return 0;
        }
        
        return (int) $count;
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
        $posts = get_posts(array(
            'post_type' => array('page', 'post'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        
        if (empty($posts)) {
            return $added;
        }
        
        $enabled = \AITranslate\AI_Lang::enabled();
        $detectable = \AITranslate\AI_Lang::detectable();
        $all_langs = array_unique(array_merge($enabled, $detectable));
        $default_lang = \AITranslate\AI_Lang::default();
        
        foreach ($posts as $post_id) {
            $permalink = get_permalink($post_id);
            if (!$permalink) {
                continue;
            }
            
            $route_id = 'post:' . $post_id;
            
            foreach ($all_langs as $lang) {
                // Skip default language
                if ($default_lang && strtolower($lang) === strtolower($default_lang)) {
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
        
        // Get all published posts first (more efficient than checking for each file)
        $posts = get_posts(array(
            'post_type' => array('page', 'post'),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ));
        
        if (empty($posts)) {
            return 0;
        }
        
        $enabled = \AITranslate\AI_Lang::enabled();
        $detectable = \AITranslate\AI_Lang::detectable();
        $all_langs = array_unique(array_merge($enabled, $detectable));
        $default_lang = \AITranslate\AI_Lang::default();
        
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
            $is_enabled = in_array($lang_lower, array_map('strtolower', $all_langs), true);
            if (!$is_enabled) {
                continue;
            }
            
            // Skip default language
            if ($default_lang && strtolower($lang) === strtolower($default_lang)) {
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
