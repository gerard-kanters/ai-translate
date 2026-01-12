<?php

namespace AITranslate;

// Load the core class if it doesn't exist yet.
// Since this file is in the "includes" folder, the core class is at the same level.
if (! class_exists('AI_Translate_Core')) {
    $core_class_file = plugin_dir_path(__FILE__) . 'class-ai-translate-core.php';
    if (file_exists($core_class_file)) {
        require_once $core_class_file;
    } else {
        add_action('admin_notices', function () use ($core_class_file) {
            echo '<div class="error"><p>Core class not found. Make sure the file exists at: ' . esc_html($core_class_file) . '</p></div>';
        });
    }
}

/**
 * Safe sprintf function that handles corrupted translations with wrong number of placeholders
 *
 * @param string $format The format string
 * @param mixed ...$args The arguments to format
 * @return string The formatted string
 */
function safe_sprintf($format, ...$args) {
    // Count %s placeholders in the format string
    $placeholder_count = substr_count($format, '%s');

    // If no placeholders, return the format as-is
    if ($placeholder_count === 0) {
        return $format;
    }

    // If correct number of placeholders, use normal sprintf
    if ($placeholder_count === count($args)) {
        return sprintf($format, ...$args);
    }

    // Handle corrupted translations
    if ($placeholder_count < count($args)) {
        // Too many arguments - use only the needed ones
        $used_args = array_slice($args, 0, $placeholder_count);
        return sprintf($format, ...$used_args);
    } else {
        // Too few arguments - replace placeholders with available arguments
        $result = $format;
        foreach ($args as $arg) {
            $result = preg_replace('/%s/', $arg, $result, 1);
        }
        // Remove any remaining %s placeholders
        $result = str_replace('%s', '', $result);
        return $result;
    }
}

/**
 * Handle AJAX request to clear cache for a specific language.
 *
 * Security: requires manage_options capability and a valid nonce.
 * Output is JSON via wp_send_json_*.
 */
function ajax_clear_cache_language()
{
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions to perform this action.', 'ai-translate')]);
        return;
    }

    // Validate nonce
    $nonce_value = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : null;
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'clear_cache_language_action')) {
        wp_send_json_error(['message' => __('Security check failed (nonce). Refresh the page and try again.', 'ai-translate')]);
        return;
    }

    // Validate language code
    if (!isset($_POST['lang_code']) || empty($_POST['lang_code'])) {
        wp_send_json_error(['message' => __('No language selected.', 'ai-translate')]);
        return;
    }

    $lang_code = sanitize_text_field(wp_unslash($_POST['lang_code']));

    // Clear the cache for this language
    $translator = AI_Translate_Core::get_instance();
    try {
        $count = $translator->clear_cache_for_language($lang_code);
        wp_send_json_success([
            'message' => safe_sprintf(__('Cache for language "%s" cleared. %d files removed.', 'ai-translate'), $lang_code, $count),
            'count' => $count
        ]);
    } catch (\Exception $e) {
        wp_send_json_error([
            'message' => __('Error clearing cache:', 'ai-translate') . ' ' . $e->getMessage()
        ]);
    }
}

// Register the AJAX handlers
add_action('wp_ajax_ai_translate_clear_cache_language', __NAMESPACE__ . '\\ajax_clear_cache_language');

/**
 * Handle AJAX request to delete cache for a specific post in all languages.
 *
 * Security: requires manage_options capability and a valid nonce.
 */
function ajax_delete_post_cache()
{
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions to perform this action.', 'ai-translate')]);
        return;
    }

    // Validate nonce
    $nonce_value = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : null;
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'ai_translate_delete_cache')) {
        wp_send_json_error(['message' => __('Security check failed (nonce). Refresh the page and try again.', 'ai-translate')]);
        return;
    }

    // Validate post ID
    $raw_post_id = isset($_POST['post_id']) ? wp_unslash($_POST['post_id']) : null;
    if ($raw_post_id === null || trim((string) $raw_post_id) === '') {
        wp_send_json_error(['message' => __('No post ID provided.', 'ai-translate')]);
        return;
    }

    $post_id = intval($raw_post_id);
    
    if ($post_id < 0) {
        wp_send_json_error(['message' => __('Invalid post ID.', 'ai-translate')]);
        return;
    }
    
    // For homepage (post_id = 0), skip post verification
    if ($post_id > 0) {
        // Verify post exists and user has permission to access it
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('Post not found.', 'ai-translate')]);
            return;
        }
    }
    
    // Ensure table exists
    AI_Cache_Meta::ensure_table_exists();
    
    // Get cache records from database
    $records = AI_Cache_Meta::get($post_id);
    
    // Get allowed cache directory for path validation
    $uploads = wp_upload_dir();
    $allowed_base = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
    $allowed_base = wp_normalize_path($allowed_base);
    
    $deleted = 0;
    $errors = [];
    
    if (!empty($records)) {
        foreach ($records as $record) {
            if (!isset($record->cache_file) || empty($record->cache_file)) {
                continue;
            }
            
            $cache_file = wp_normalize_path($record->cache_file);
            
            // Security: Ensure file is within allowed cache directory (prevent path traversal)
            if (strpos($cache_file, $allowed_base) !== 0) {
                $errors[] = safe_sprintf(__('Invalid file path: %s', 'ai-translate'), basename($cache_file));
                continue;
            }
            
            // Security: Ensure file exists and is a regular file (not directory or symlink)
            if (!file_exists($cache_file) || !is_file($cache_file)) {
                continue;
            }
            
            // Security: Additional check - file must have .html extension
            if (substr($cache_file, -5) !== '.html') {
                $errors[] = safe_sprintf(__('Invalid file type: %s', 'ai-translate'), basename($cache_file));
                continue;
            }
            
            if (@unlink($cache_file)) {
                $deleted++;
            } else {
                $errors[] = safe_sprintf(__('Could not delete file: %s', 'ai-translate'), basename($cache_file));
            }
        }
    }
    
    // Delete metadata records
    $meta_deleted = AI_Cache_Meta::delete($post_id);
    
    // Clear any transients
    delete_transient('ai_translate_cache_table_data');
    
    $message = safe_sprintf(__('Cache deleted for %d languages', 'ai-translate'), $deleted);
    if (!empty($errors)) {
        $message .= '. ' . __('Warnings:', 'ai-translate') . ' ' . implode(', ', $errors);
    }
    
    wp_send_json_success([
        'message' => $message,
        'deleted' => $deleted,
        'meta_deleted' => $meta_deleted
    ]);
}

add_action('wp_ajax_ai_translate_delete_cache', __NAMESPACE__ . '\\ajax_delete_post_cache');

/**
 * Handle AJAX request to fetch cached URLs for a language (lazy-loaded in admin).
 *
 * Security: requires manage_options and dedicated nonce.
 *
 * @return void
 */
function ajax_get_cache_urls_by_language()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions to perform this action.', 'ai-translate')]);
        return;
    }
    
    $nonce_value = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : null;
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'ai_translate_cache_urls_nonce')) {
        wp_send_json_error(['message' => __('Security check failed (nonce). Refresh the page and try again.', 'ai-translate')]);
        return;
    }
    
    $lang_code = isset($_POST['lang_code']) ? sanitize_key(wp_unslash($_POST['lang_code'])) : '';
    if ($lang_code === '') {
        wp_send_json_error(['message' => __('No language selected.', 'ai-translate')]);
        return;
    }
    
    AI_Cache_Meta::ensure_table_exists();
    $result = AI_Cache_Meta::get_cached_urls_for_language($lang_code, 500);
    
    wp_send_json_success($result);
}

add_action('wp_ajax_ai_translate_get_cache_urls_by_language', __NAMESPACE__ . '\\ajax_get_cache_urls_by_language');

/**
 * AJAX handler: Delete a single cache file
 *
 * @return void (sends JSON response)
 */
function ajax_delete_cache_file()
{
    // Verify nonce
    $nonce_value = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'ai_translate_delete_cache_file_nonce')) {
        wp_send_json_error(array('message' => __('Security check failed', 'ai-translate')));
        return;
    }
    
    // Check capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('Insufficient permissions', 'ai-translate')));
        return;
    }
    
    // Get parameters
    $cache_file = isset($_POST['cache_file']) ? wp_normalize_path(sanitize_text_field(wp_unslash($_POST['cache_file']))) : '';
    $language = isset($_POST['language']) ? sanitize_text_field(wp_unslash($_POST['language'])) : '';
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    
    if (empty($cache_file)) {
        wp_send_json_error(array('message' => __('No cache file specified', 'ai-translate')));
        return;
    }
    
    // Verify file exists and is in allowed cache directory
    $uploads = wp_upload_dir();
    $cache_base = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
    $cache_file_normalized = wp_normalize_path($cache_file);
    
    // Security check: ensure file is within cache directory
    if (strpos($cache_file_normalized, wp_normalize_path($cache_base)) !== 0) {
        wp_send_json_error(array('message' => __('Invalid cache file path', 'ai-translate')));
        return;
    }
    
    if (!file_exists($cache_file_normalized)) {
        wp_send_json_error(array('message' => __('Cache file not found', 'ai-translate')));
        return;
    }
    
    // Delete the file
    if (!@unlink($cache_file_normalized)) {
        wp_send_json_error(array('message' => __('Failed to delete cache file', 'ai-translate')));
        return;
    }
    
    // Delete from database metadata if it exists
    if (class_exists('\\AITranslate\\AI_Cache_Meta')) {
        \AITranslate\AI_Cache_Meta::delete_by_file($cache_file_normalized);
    }
    
    wp_send_json_success(array('message' => __('Cache file deleted successfully', 'ai-translate')));
}

add_action('wp_ajax_ai_translate_delete_cache_file', __NAMESPACE__ . '\\ajax_delete_cache_file');

/**
 * Warm cache for multiple languages in parallel using curl_multi.
 *
 * @param int $post_id Post ID to warm cache for
 * @param string $base_path Base path (e.g., '/page-slug/')
 * @param array $lang_codes Array of language codes to warm
 * @return array Associative array with lang_code as key and result array as value
 */
function warm_cache_batch($post_id, $base_path, $lang_codes)
{
    $results = [];
    
    if (empty($lang_codes)) {
        return $results;
    }
    
    // Check if curl_multi is available
    if (!function_exists('curl_multi_init')) {
        // Fallback to sequential if curl_multi not available
        foreach ($lang_codes as $lang_code) {
            $translated_path = "/{$lang_code}" . ($base_path ?: '/');
            $results[$lang_code] = warm_cache_internal_request($post_id, $translated_path, $lang_code);
        }
        return $results;
    }
    
    // Parse URL to get host
    $parsed = parse_url(home_url());
    $host = isset($parsed['host']) ? $parsed['host'] : '';
    if (isset($parsed['port'])) {
        $host .= ':' . $parsed['port'];
    }
    
    // Initialize curl_multi handle
    $mh = curl_multi_init();
    $curl_handles = [];
    
    // Create curl handles for each language
    foreach ($lang_codes as $lang_code) {
        $translated_path = "/{$lang_code}" . ($base_path ?: '/');
        $translated_url = home_url($translated_path);
        
        $ch = curl_init($translated_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_COOKIE => 'ai_translate_lang=' . urlencode($lang_code),
            CURLOPT_HTTPHEADER => array(
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                'Accept-Language: ' . $lang_code . ',' . $lang_code . ';q=0.9,en;q=0.8',
                'Accept-Encoding: gzip, deflate, br',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1'
            )
        ));
        
        curl_multi_add_handle($mh, $ch);
        $curl_handles[$lang_code] = $ch;
    }
    
    // Execute all handles in parallel
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1); // Small delay to prevent CPU spinning
    } while ($running > 0);
    
    // Process results
    foreach ($curl_handles as $lang_code => $ch) {
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_multi_remove_handle($mh, $ch);
        
        if (!empty($error)) {
            $results[$lang_code] = array('success' => false, 'error' => $error);
            continue;
        }
        
        // Accept 200 as success
        if ($http_code === 200) {
            // Wait a moment for async cache writes to complete
            usleep(500000); // 0.5 second
            
            // Use correct route_id format (post:ID for posts, path:md5(/) for homepage)
            if ($post_id === 0) {
                $route_id = 'path:' . md5('/');
            } else {
                $route_id = 'post:' . $post_id;
            }
            $cache_key = \AITranslate\AI_Cache::key($lang_code, $route_id, '');
            $cache_file = \AITranslate\AI_Cache::get_file_path($cache_key);
            
            if ($cache_file && file_exists($cache_file)) {
                // Ensure metadata is inserted/updated in database
                $cache_hash = md5($cache_key);
                AI_Cache_Meta::insert($post_id, $lang_code, $cache_file, $cache_hash);
                
                $results[$lang_code] = array('success' => true, 'error' => '');
            } else {
                // Check again after a longer delay
                sleep(1);
                if ($cache_file && file_exists($cache_file)) {
                    $cache_hash = md5($cache_key);
                    AI_Cache_Meta::insert($post_id, $lang_code, $cache_file, $cache_hash);
                    $results[$lang_code] = array('success' => true, 'error' => '');
                } else {
                    $results[$lang_code] = array('success' => false, 'error' => __('Cache file not generated', 'ai-translate'));
                }
            }
        } elseif ($http_code === 301 || $http_code === 302) {
            // Redirect - might be normal, but we'll mark as success if cache exists
            $route_id = 'post:' . $post_id;
            $cache_key = \AITranslate\AI_Cache::key($lang_code, $route_id, '');
            $cache_file = \AITranslate\AI_Cache::get_file_path($cache_key);
            
            if ($cache_file && file_exists($cache_file)) {
                $cache_hash = md5($cache_key);
                AI_Cache_Meta::insert($post_id, $lang_code, $cache_file, $cache_hash);
                $results[$lang_code] = array('success' => true, 'error' => '');
            } else {
                $results[$lang_code] = array('success' => false, 'error' => safe_sprintf(__('HTTP %d (redirect)', 'ai-translate'), $http_code));
            }
        } else {
            $results[$lang_code] = array('success' => false, 'error' => safe_sprintf(__('HTTP %d', 'ai-translate'), $http_code));
        }
    }
    
    curl_multi_close($mh);
    
    return $results;
}

/**
 * Make an internal WordPress request that goes through the full stack to generate cache.
 * 
 * This function makes an HTTP request to the site itself (like curl would),
 * which goes through the full WordPress stack including template_redirect,
 * triggering the output buffer callback that generates the cache.
 *
 * @param int $post_id Post ID to warm cache for
 * @param string $request_path Request path (e.g., '/en/page-slug/')
 * @param string $lang_code Language code
 * @return array Array with 'success' (bool) and 'error' (string) keys
 */
function warm_cache_internal_request($post_id, $request_path, $lang_code)
{
    // Build full URL for the request
    $translated_url = home_url($request_path);
    
    // Parse URL to get host and path
    $parsed = parse_url($translated_url);
    $host = isset($parsed['host']) ? $parsed['host'] : '';
    if (isset($parsed['port'])) {
        $host .= ':' . $parsed['port'];
    }
    
    // Create cookie jar for the request
    $cookies = array();
    $cookies[] = new \WP_Http_Cookie(array(
        'name' => 'ai_translate_lang',
        'value' => $lang_code,
        'domain' => $host,
        'path' => '/'
    ));
    
    // Make HTTP request to the site itself
    // This goes through the full WordPress stack and triggers cache generation
    // Using same approach as curl: full HTTP request with proper headers and cookies
    $response = wp_remote_get($translated_url, array(
        'timeout' => 90,
        'sslverify' => false,
        'cookies' => $cookies,
        'headers' => array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language' => $lang_code . ',' . $lang_code . ';q=0.9,en;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1'
        ),
        'redirection' => 5, // Follow redirects
        'httpversion' => '1.1'
    ));
    
    if (is_wp_error($response)) {
        return array('success' => false, 'error' => $response->get_error_message());
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    
    // Accept 200 as success (redirects might indicate issues, but we'll check cache anyway)
    if ($response_code === 200) {
        // Verify cache was generated by checking if cache file exists
        // Wait a moment for async cache writes to complete
        usleep(500000); // 0.5 second
        
        $route_id = 'post:' . $post_id;
        $cache_key = \AITranslate\AI_Cache::key($lang_code, $route_id, '');
        $cache_file = \AITranslate\AI_Cache::get_file_path($cache_key);
        
        if ($cache_file && file_exists($cache_file)) {
            // Ensure metadata is inserted/updated in database
            // The cache file exists, but metadata might not be in database yet
            $cache_hash = md5($cache_key);
            AI_Cache_Meta::insert($post_id, $lang_code, $cache_file, $cache_hash);
            
            return array('success' => true, 'error' => '');
        } else {
            // Check again after a longer delay (some systems might be slower)
            sleep(1);
            if ($cache_file && file_exists($cache_file)) {
                // Ensure metadata is inserted/updated in database
                $cache_hash = md5($cache_key);
                AI_Cache_Meta::insert($post_id, $lang_code, $cache_file, $cache_hash);
                
                return array('success' => true, 'error' => '');
            }
            // Request was successful but cache not found - might be bypassed or error in generation
            return array('success' => false, 'error' => __('Cache file not generated after successful request', 'ai-translate'));
        }
    }
    
    // Also accept redirects as they might be normal (language redirects)
    if ($response_code === 301 || $response_code === 302) {
        $location = wp_remote_retrieve_header($response, 'location');
        if ($location) {
            // Try following the redirect
            $redirect_url = (strpos($location, 'http') === 0) ? $location : home_url($location);
            return warm_cache_internal_request($post_id, parse_url($redirect_url, PHP_URL_PATH), $lang_code);
        }
    }
    
    return array('success' => false, 'error' => safe_sprintf(__('HTTP %d', 'ai-translate'), $response_code));
}

/**
 * Handle AJAX request to warm cache for a specific post in all enabled languages.
 *
 * Security: requires manage_options capability and a valid nonce.
 */
function ajax_warm_post_cache()
{
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions to perform this action.', 'ai-translate')]);
        return;
    }

    // Validate nonce
    $nonce_value = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : null;
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'ai_translate_warm_cache')) {
        wp_send_json_error(['message' => __('Security check failed (nonce). Refresh the page and try again.', 'ai-translate')]);
        return;
    }

    // Validate post ID
    $raw_post_id = isset($_POST['post_id']) ? wp_unslash($_POST['post_id']) : null;
    if ($raw_post_id === null || trim((string) $raw_post_id) === '') {
        wp_send_json_error(['message' => __('No post ID provided.', 'ai-translate')]);
        return;
    }

    $post_id = intval($raw_post_id);
    
    if ($post_id < 0) {
        wp_send_json_error(['message' => __('Invalid post ID.', 'ai-translate')]);
        return;
    }
    
    // For homepage (post_id = 0), skip post verification
    if ($post_id > 0) {
        // Verify post exists and user has permission to access it
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('Post not found.', 'ai-translate')]);
            return;
        }
        
        // Security: Only allow published posts and pages (prevent access to drafts/private posts)
        if ($post->post_status !== 'publish') {
            wp_send_json_error(['message' => __('Only published posts can be cached.', 'ai-translate')]);
            return;
        }
    }
    
    // Ensure table exists
    AI_Cache_Meta::ensure_table_exists();
    
    // Get enabled languages (selectable + detectable)
    $enabled = \AITranslate\AI_Lang::enabled();
    $detectable = \AITranslate\AI_Lang::detectable();
    $languages = array_unique(array_merge($enabled, $detectable));
    
    if (empty($languages)) {
        wp_send_json_error(['message' => __('No languages configured.', 'ai-translate')]);
        return;
    }
    
    // Get post URL (homepage uses home_url)
    if ($post_id === 0) {
        $post_url = home_url('/');
    } else {
        $post_url = get_permalink($post_id);
        if (!$post_url) {
            wp_send_json_error(['message' => __('Could not generate post URL.', 'ai-translate')]);
            return;
        }
    }
    
    // Security: Validate URL is from same site (prevent SSRF)
    $parsed_url = parse_url($post_url);
    $site_url = parse_url(home_url());
    if (!isset($parsed_url['host']) || !isset($site_url['host']) || $parsed_url['host'] !== $site_url['host']) {
        wp_send_json_error(['message' => __('Invalid post URL.', 'ai-translate')]);
        return;
    }
    
    // Get existing cache languages for this post
    $existing = AI_Cache_Meta::get_cached_languages($post_id);
    
    $warmed = 0;
    $skipped = 0;
    $errors = [];
    $default_lang = \AITranslate\AI_Lang::default();
    
    // Filter languages to warm (exclude default and already cached)
    $languages_to_warm = [];
    foreach ($languages as $lang_code) {
        // Skip default language
        if ($default_lang && strtolower($lang_code) === strtolower($default_lang)) {
            $skipped++;
            continue;
        }
        
        // Skip if cache already exists
        if (in_array($lang_code, $existing, true)) {
            $skipped++;
            continue;
        }
        
        $languages_to_warm[] = $lang_code;
    }
    
    // Process languages in parallel batches of 5
    $batch_size = 5;
    $path = parse_url($post_url, PHP_URL_PATH);
    
    for ($i = 0; $i < count($languages_to_warm); $i += $batch_size) {
        $batch = array_slice($languages_to_warm, $i, $batch_size);
        $results = warm_cache_batch($post_id, $path, $batch);
        
        foreach ($results as $lang_code => $result) {
            if ($result['success']) {
                $warmed++;
            } else {
                $errors[] = sprintf(__('Error for %s: %s', 'ai-translate'), $lang_code, $result['error']);
            }
        }
    }
    
    // Clear transient cache to force refresh of stats
    delete_transient('ai_translate_cache_table_data');
    
    // Force refresh of cache metadata by ensuring all generated cache files have metadata
    // This ensures the database is in sync with the filesystem
    if ($warmed > 0) {
        // Use correct route_id format (post:ID for posts, path:md5(/) for homepage)
        if ($post_id === 0) {
            $route_id = 'path:' . md5('/');
        } else {
            $route_id = 'post:' . $post_id;
        }
        $enabled = \AITranslate\AI_Lang::enabled();
        $detectable = \AITranslate\AI_Lang::detectable();
        $all_langs = array_unique(array_merge($enabled, $detectable));
        
        foreach ($all_langs as $lang) {
            $cache_key = \AITranslate\AI_Cache::key($lang, $route_id, '');
            $cache_file = \AITranslate\AI_Cache::get_file_path($cache_key);
            
            if ($cache_file && file_exists($cache_file)) {
                // Ensure metadata exists for this cache file
                $cache_hash = md5($cache_key);
                AI_Cache_Meta::insert($post_id, $lang, $cache_file, $cache_hash);
            }
        }
    }
    
    $message = sprintf(__('Cache warmed for %d languages', 'ai-translate'), $warmed);
    if ($skipped > 0) {
        $message .= sprintf(__(' (%d skipped)', 'ai-translate'), $skipped);
    }
    if (!empty($errors)) {
        $message .= '. ' . __('Warnings:', 'ai-translate') . ' ' . implode(', ', array_slice($errors, 0, 3));
        if (count($errors) > 3) {
            $message .= sprintf(__(' and %d more', 'ai-translate'), count($errors) - 3);
        }
    }
    
    wp_send_json_success([
        'message' => $message,
        'warmed' => $warmed,
        'skipped' => $skipped,
        'errors' => $errors
    ]);
}

add_action('wp_ajax_ai_translate_warm_cache', __NAMESPACE__ . '\\ajax_warm_post_cache');

/**
 * Handle AJAX request to generate a website context suggestion.
 *
 * Security: requires manage_options capability and a valid nonce.
 * Clears prompt cache to ensure immediate use of new context.
 */
function ajax_generate_website_context()
{
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions to perform this action.', 'ai-translate')]);
        return;
    }

    // Validate nonce
    $nonce_value = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : null;
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'generate_website_context_nonce')) {
        wp_send_json_error(['message' => __('Security check failed (nonce). Refresh the page and try again.', 'ai-translate')]);
        return;
    }

    // Get domain from request (for multi-domain caching support)
    $requested_domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : '';
    
    // Generate website context suggestion
    $translator = AI_Translate_Core::get_instance();
    try {
        $context_suggestion = $translator->generate_website_context_suggestion($requested_domain);
        
        // Clear prompt cache to ensure new context is used immediately
        $translator->clear_prompt_cache();
        
        wp_send_json_success([
            'context' => $context_suggestion
        ]);
    } catch (\Exception $e) {
        wp_send_json_error([
            'message' => __('Error generating context:', 'ai-translate') . ' ' . $e->getMessage()
        ]);
    }
}

// Register the new AJAX handler
add_action('wp_ajax_ai_translate_generate_website_context', __NAMESPACE__ . '\\ajax_generate_website_context');

/**
 * Handle AJAX request to generate homepage meta description.
 */
function ajax_generate_homepage_meta()
{
    // Check permissions
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions to perform this action.', 'ai-translate')]);
        return;
    }

    // Validate nonce
    $nonce_value = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : null;
    if (!$nonce_value || !wp_verify_nonce($nonce_value, 'generate_homepage_meta_nonce')) {
        wp_send_json_error(['message' => __('Security check failed (nonce). Refresh the page and try again.', 'ai-translate')]);
        return;
    }

    // Get domain from request (for multi-domain caching support)
    $requested_domain = isset($_POST['domain']) ? sanitize_text_field(wp_unslash($_POST['domain'])) : '';
    
    // Generate meta description
    $translator = AI_Translate_Core::get_instance();
    try {
        $meta_description = $translator->generate_homepage_meta_description($requested_domain);
        
        wp_send_json_success([
            'meta' => $meta_description
        ]);
    } catch (\Exception $e) {
        wp_send_json_error([
            'message' => __('Error generating meta description:', 'ai-translate') . ' ' . $e->getMessage()
        ]);
    }
}
add_action('wp_ajax_ai_translate_generate_homepage_meta', __NAMESPACE__ . '\\ajax_generate_homepage_meta');

/**
 * Clear prompt cache when settings are updated (specifically when website context changes)
 */
add_action('update_option_ai_translate_settings', function ($old_value, $value) {
    // Check if website context has changed
    $old_context = isset($old_value['website_context']) ? $old_value['website_context'] : '';
    $new_context = isset($value['website_context']) ? $value['website_context'] : '';
    
    if ($old_context !== $new_context) {
        // Clear prompt cache to force regeneration with new context
        if (class_exists('AI_Translate_Core')) {
            $core = AI_Translate_Core::get_instance();
            $core->clear_prompt_cache();
        }
    }
}, 10, 2);

/**
 * AJAX handlers and admin UI bootstrap below.
 */

// Add admin menu
add_action('admin_menu', function () {
    add_menu_page(
        __('AI Translate', 'ai-translate'),
        __('AI Translate', 'ai-translate'),
        'manage_options',
        'ai-translate',
        __NAMESPACE__ . '\\render_admin_page',
        'dashicons-translation',
        100
    );
});

// Enqueue admin scripts and styles
add_action('admin_enqueue_scripts', function ($hook) {
    // Only load on our plugin's admin page
    if ($hook !== 'toplevel_page_ai-translate') {
        return;
    }

    // Enqueue CSS
    wp_enqueue_style(
        'ai-translate-admin-css',
        plugin_dir_url(__DIR__) . 'assets/admin-page.css',
        array(),
        '1.0.0'
    );

    // Enqueue main admin JavaScript
    wp_enqueue_script(
        'ai-translate-admin-js',
        plugin_dir_url(__DIR__) . 'assets/admin-page.js',
        array('jquery'),
        '1.0.0',
        true
    );

    // Enqueue cache table JavaScript
    wp_enqueue_script(
        'ai-translate-cache-table-js',
        plugin_dir_url(__DIR__) . 'assets/admin-cache-table.js',
        array('jquery'),
        '1.0.0',
        true
    );

    // Localize script with data needed by JavaScript
    $settings = get_option('ai_translate_settings', []);
    wp_localize_script('ai-translate-admin-js', 'aiTranslateAdmin', array(
        'adminUrl' => esc_url(admin_url('admin.php?page=ai-translate')),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'getModelsNonce' => wp_create_nonce('ai_translate_get_models_nonce'),
        'validateApiNonce' => wp_create_nonce('ai_translate_validate_api_nonce'),
        'getCustomUrlNonce' => wp_create_nonce('ai_translate_get_custom_url_nonce'),
        'generateContextNonce' => wp_create_nonce('generate_website_context_nonce'),
        'generateMetaNonce' => wp_create_nonce('generate_homepage_meta_nonce'),
        'apiKeys' => isset($settings['api_keys']) && is_array($settings['api_keys']) ? $settings['api_keys'] : [],
        'models' => isset($settings['models']) && is_array($settings['models']) ? $settings['models'] : [],
        'customModel' => isset($settings['custom_model']) ? $settings['custom_model'] : '',
        'strings' => array(
            'enterApiKeyFirst' => __('Enter API Key first...', 'ai-translate'),
            'enterApiKeyToLoadModels' => __('Enter API Key first to load models.', 'ai-translate'),
            'loadingModels' => __('Loading models...', 'ai-translate'),
            'modelsLoadedSuccessfully' => __('Models loaded successfully.', 'ai-translate'),
            'noModelsFound' => __('No models found', 'ai-translate'),
            'invalidKey' => __('Invalid key', 'ai-translate'),
            'errorLoadingModels' => __('Error loading models', 'ai-translate'),
            'selectApiProviderFirst' => __('Select API Provider first...', 'ai-translate'),
            'unknownError' => __('Unknown error', 'ai-translate'),
            'saveSettingsFirst' => __('Save your settings first before opening the menu editor.', 'ai-translate'),
        ),
    ));
    
    // Localize cache table script with ajaxurl (WordPress provides ajaxurl in admin, but we make it explicit)
    wp_localize_script('ai-translate-cache-table-js', 'aiTranslateCacheTable', array(
        'ajaxurl' => admin_url('admin-ajax.php'),
        'listNonce' => wp_create_nonce('ai_translate_cache_urls_nonce'),
        'delete_nonce' => wp_create_nonce('ai_translate_delete_cache_file_nonce'),
        'strings' => array(
            'loading' => __('Loading cached URLs...', 'ai-translate'),
            'error' => __('Could not load cached URLs. Please try again.', 'ai-translate'),
            'timeout' => __('Request timed out. The site may have many cache files. Please try again or use the rescan function.', 'ai-translate'),
            'empty' => __('No cached pages for this language.', 'ai-translate'),
            'show' => __('Show cached URLs', 'ai-translate'),
            'hide' => __('Hide cached URLs', 'ai-translate'),
            'truncated' => __('Showing first %1$s of %2$s entries', 'ai-translate'),
            'delete_file' => __('Delete file', 'ai-translate'),
            'deleting' => __('Deleting...', 'ai-translate'),
            'confirm_delete' => __('Are you sure you want to delete this cache file?', 'ai-translate'),
            'delete_error' => __('Failed to delete cache file', 'ai-translate'),
        ),
    ));
});

// Register settings (without the shortcodes section)
add_action('admin_init', function () {
    register_setting('ai_translate', 'ai_translate_settings', [
        'sanitize_callback' => function ($input) {
            // Start from existing settings to avoid accidental defaults.
            $current_settings = get_option('ai_translate_settings', []);
            $sanitized = is_array($current_settings) ? $current_settings : [];

            // Detecteer of dit een volledige formulier-submit is (niet AJAX)
            // Dit is cruciaal om te weten of input in 'dagen' (form) of 'uren' (intern/AJAX) is
            // en of checkboxes die afwezig zijn als FALSE moeten worden beschouwd.
            $is_form_submit = isset($input['_form_submit']) && $input['_form_submit'] === '1';

            // Ensure expected arrays exist
            if (!isset($sanitized['api_keys']) || !is_array($sanitized['api_keys'])) {
                $sanitized['api_keys'] = [];
            }
            if (!isset($sanitized['models']) || !is_array($sanitized['models'])) {
                $sanitized['models'] = [];
            }

            // API keys array (handle direct updates/AJAX)
            if (isset($input['api_keys']) && is_array($input['api_keys'])) {
                foreach ($input['api_keys'] as $pk => $kv) {
                    $sanitized['api_keys'][$pk] = sanitize_text_field($kv);
                }
            }

            // Models array (handle direct updates/AJAX)
            if (isset($input['models']) && is_array($input['models'])) {
                foreach ($input['models'] as $pk => $m) {
                    $sanitized['models'][$pk] = sanitize_text_field($m);
                }
            }

            // Provider
            $valid_providers = array_keys(AI_Translate_Core::get_api_providers());
            if (isset($input['api_provider'])) {
                $prov = sanitize_text_field($input['api_provider']);
                if (in_array($prov, $valid_providers, true)) {
                    $sanitized['api_provider'] = $prov;
                } else {
                    add_settings_error(
                        'ai_translate_settings',
                        'invalid_api_provider',
                        __('Invalid API provider selected.', 'ai-translate'),
                        'error'
                    );
                }
            }

            // legacy key removal
            if (isset($input['api_url'])) {
                unset($input['api_url']);
            }

            // Custom API URL
            if (isset($input['custom_api_url'])) {
                $sanitized['custom_api_url'] = esc_url_raw(trim($input['custom_api_url']));
            }
            if (($sanitized['api_provider'] ?? null) === 'custom') {
                if (empty($sanitized['custom_api_url'])) {
                    add_settings_error(
                        'ai_translate_settings',
                        'custom_url_required',
                        __('Custom API URL is required for the custom provider.', 'ai-translate'),
                        'error'
                    );
                }
            }

            // Cache expiration (days → hours), minimum 14 days
            if ($is_form_submit && isset($input['cache_expiration'])) {
                // Form submit: input is in dagen -> converteer naar uren
                $cache_days = intval($input['cache_expiration']);
                if ($cache_days < 14) {
                    add_settings_error(
                        'ai_translate_settings',
                        'cache_duration_too_low',
                        __('Cache duration cannot be less than 14 days. It has been automatically set to 14 days.', 'ai-translate'),
                        'warning'
                    );
                    $cache_days = 14;
                }
                $sanitized['cache_expiration'] = $cache_days * 24;
            } elseif (array_key_exists('cache_expiration', $input)) {
                // Not a form submit (e.g. AJAX or internal update): input is already in hours (from current settings)
                // Just keep the value as is (validation for bounds happens later if needed)
                $sanitized['cache_expiration'] = (int) $input['cache_expiration'];
            } else {
                // Validate existing value even when not in input
                $existing = isset($sanitized['cache_expiration']) ? (int) $sanitized['cache_expiration'] : null;
                if ($existing === null || $existing < (14 * 24) || $existing > (365 * 24)) {
                     // Invalid or missing value - reset to default
                     $sanitized['cache_expiration'] = 14 * 24;
                }
            }
            
            // Emergency fix: if value is absurdly high due to previous bug, reset it
            // 50000 hours is approx 2000 days or 5.7 years, which is way beyond reasonable
            if (isset($sanitized['cache_expiration']) && $sanitized['cache_expiration'] > 50000) {
                 $sanitized['cache_expiration'] = 14 * 24;
            }

            // Auto-clear pages on menu update (checkbox)
            // Bij formulier-submit: checkbox is aangevinkt als key bestaat, anders uitgevinkt
            // Bij AJAX/partial update: alleen updaten als expliciet in input
            if ($is_form_submit) {
                $sanitized['auto_clear_pages_on_menu_update'] = isset($input['auto_clear_pages_on_menu_update']);
            } elseif (array_key_exists('auto_clear_pages_on_menu_update', $input)) {
                $sanitized['auto_clear_pages_on_menu_update'] = (bool) $input['auto_clear_pages_on_menu_update'];
            } elseif (!isset($sanitized['auto_clear_pages_on_menu_update'])) {
                // Initialiseer als nog niet gezet
                $sanitized['auto_clear_pages_on_menu_update'] = true;
            }

            // Stop translations except cache invalidation (checkbox)
            // Bij formulier-submit: checkbox is aangevinkt als key bestaat, anders uitgevinkt
            // Bij AJAX/partial update: alleen updaten als expliciet in input
            if ($is_form_submit) {
                $sanitized['stop_translations_except_cache_invalidation'] = isset($input['stop_translations_except_cache_invalidation']);
            } elseif (array_key_exists('stop_translations_except_cache_invalidation', $input)) {
                $sanitized['stop_translations_except_cache_invalidation'] = (bool) $input['stop_translations_except_cache_invalidation'];
            } elseif (!isset($sanitized['stop_translations_except_cache_invalidation'])) {
                // Initialiseer als nog niet gezet
                $sanitized['stop_translations_except_cache_invalidation'] = false;
            }

            // Model selection (per provider)
            if (isset($input['selected_model'])) {
                $selected_provider = $sanitized['api_provider'] ?? ($current_settings['api_provider'] ?? null);
                $model_to_store = null;
                if ($input['selected_model'] === 'custom') {
                    if (!empty($input['custom_model'])) {
                        $model_to_store = trim($input['custom_model']);
                    } else {
                        add_settings_error(
                            'ai_translate_settings',
                            'custom_model_required',
                            __('Custom model name is required when selecting a custom model.', 'ai-translate'),
                            'error'
                        );
                    }
                } else {
                    $model_to_store = trim(sanitize_text_field($input['selected_model']));
                }
                if ($selected_provider && $model_to_store !== null) {
                    $sanitized['models'][$selected_provider] = $model_to_store;
                    $sanitized['selected_model'] = $model_to_store;
                }
            }

            // API key per provider
            if (isset($input['api_key'])) {
                $provider_for_key = $sanitized['api_provider'] ?? ($input['api_provider'] ?? ($current_settings['api_provider'] ?? null));
                if ($provider_for_key) {
                    $key_val = trim(sanitize_text_field($input['api_key']));
                    $sanitized['api_keys'][$provider_for_key] = $key_val;
                    if ($key_val === '') {
                        add_settings_error(
                            'ai_translate_settings',
                            'api_key_missing',
                            __('API key is required for the selected provider.', 'ai-translate'),
                            'error'
                        );
                    }
                }
            }

            // Homepage meta description (per-domain when multi-domain caching is enabled)
            $multi_domain = isset($sanitized['multi_domain_caching']) ? (bool) $sanitized['multi_domain_caching'] : false;
            if (isset($input['homepage_meta_description'])) {
                if ($multi_domain) {
                    // Multi-domain caching: store per-domain meta description
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
                    
                    // Initialize per-domain array if not exists
                    if (!isset($sanitized['homepage_meta_description_per_domain']) || !is_array($sanitized['homepage_meta_description_per_domain'])) {
                        $sanitized['homepage_meta_description_per_domain'] = [];
                    }
                    
                    // Store meta description for current domain
                    $sanitized['homepage_meta_description_per_domain'][$active_domain] = sanitize_textarea_field($input['homepage_meta_description']);
                } else {
                    // Single domain: use global meta description
                    $sanitized['homepage_meta_description'] = sanitize_textarea_field($input['homepage_meta_description']);
                }
            }

            // Website context (per-domain when multi-domain caching is enabled)
            if (isset($input['website_context'])) {
                if ($multi_domain) {
                    // Multi-domain caching: store per-domain website context
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
                    
                    // Initialize per-domain array if not exists
                    if (!isset($sanitized['website_context_per_domain']) || !is_array($sanitized['website_context_per_domain'])) {
                        $sanitized['website_context_per_domain'] = [];
                    }
                    
                    // Store website context for current domain
                    $sanitized['website_context_per_domain'][$active_domain] = sanitize_textarea_field($input['website_context']);
                } else {
                    // Single domain: use global website context
                    $sanitized['website_context'] = sanitize_textarea_field($input['website_context']);
                }
            }

            // Default language
            if (isset($input['default_language'])) {
                $sanitized['default_language'] = sanitize_text_field($input['default_language']);
            }

            // Enabled languages (switcher)
            if (isset($input['enabled_languages']) && is_array($input['enabled_languages'])) {
                $sanitized['enabled_languages'] = array_values(array_unique(array_map('sanitize_text_field', $input['enabled_languages'])));
            }

            // Detectable languages (auto)
            if (isset($input['detectable_languages']) && is_array($input['detectable_languages'])) {
                $sanitized['detectable_languages'] = array_values(array_unique(array_map('sanitize_text_field', $input['detectable_languages'])));
            }

            // Switcher position
            if (isset($input['switcher_position'])) {
                $valid_positions = array('bottom-left', 'bottom-right', 'top-left', 'top-right', 'none');
                $position = sanitize_text_field($input['switcher_position']);
                if (in_array($position, $valid_positions, true)) {
                    $sanitized['switcher_position'] = $position;
                } else {
                    $sanitized['switcher_position'] = 'bottom-left';
                }
            } elseif (!isset($sanitized['switcher_position'])) {
                $sanitized['switcher_position'] = 'bottom-left';
            }

            // Multi-domain caching toggle
            // Bij formulier-submit: checkbox is aangevinkt als key bestaat, anders uitgevinkt
            // Bij AJAX/partial update: alleen updaten als expliciet in input
            if ($is_form_submit) {
                $sanitized['multi_domain_caching'] = isset($input['multi_domain_caching']);
            } elseif (array_key_exists('multi_domain_caching', $input)) {
                $sanitized['multi_domain_caching'] = (bool) $input['multi_domain_caching'];
            } elseif (!isset($sanitized['multi_domain_caching'])) {
                // Initialiseer als nog niet gezet
                $sanitized['multi_domain_caching'] = false;
            }

            return $sanitized;
        }
    ]);

    // API Settings Section
    add_settings_section(
        'ai_translate_api',
        __('API Settings', 'ai-translate'),
        null,
        'ai-translate'
    );
    add_settings_field(
        'api_provider',
        __('API Provider', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $current_provider_key = isset($settings['api_provider']) ? $settings['api_provider'] : '';
            $providers = AI_Translate_Core::get_api_providers();

            echo '<select id="api_provider_select" name="ai_translate_settings[api_provider]">';
            echo '<option value="" ' . selected($current_provider_key, '', false) . ' disabled hidden>' . esc_html__('— Select provider —', 'ai-translate') . '</option>';
            foreach ($providers as $key => $provider_details) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($current_provider_key, $key, false) . '>' . esc_html($provider_details['name']) . '</option>';
            }
            echo '</select>';
            // GPT-5 warning for OpenAI
            echo '<div id="openai_gpt5_warning" style="margin-top:10px; display:none;">';
            echo '<p class="description" style="color: #d63638;"><strong>' . esc_html__('Note:', 'ai-translate') . '</strong> ' . esc_html__('GPT-5 is blocked, since reasoning cannot be disabled and not required for translations and therefore too slow and expensive.', 'ai-translate') . '</p>';
            echo '</div>';
            // Custom URL field
            echo '<div id="custom_api_url_div" style="margin-top:10px; display:none;">';
            echo '<input type="url" name="ai_translate_settings[custom_api_url]" value="' . esc_attr($settings['custom_api_url'] ?? '') . '" placeholder="https://your-custom-api.com/v1/" class="regular-text">';
            $description_text = esc_html__('Enter the endpoint URL for your custom API provider. Example: %s', 'ai-translate');
            $url_link = '<a href="https://openrouter.ai/api/v1/" target="_blank">https://openrouter.ai/api/v1/</a>';

            // Count %s placeholders in the translated string to prevent sprintf errors
            $placeholder_count = substr_count($description_text, '%s');

    if ($placeholder_count === 1) {
        $description_html = safe_sprintf($description_text, $url_link);
    } elseif ($placeholder_count === 0) {
        $description_html = $description_text;
    } else {
        // Fallback for corrupted translations with too many placeholders
        $description_html = str_replace('%s', $url_link, $description_text);
        // Remove extra %s placeholders that couldn't be replaced
        $description_html = preg_replace('/%s/', '', $description_html);
    }

            echo '<p class="description">' . $description_html . '</p>';
            echo '</div>';

        },
        'ai-translate',
        'ai_translate_api'
    );
    add_settings_field(
        'api_key',
        __('API Key', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $current_provider_key = isset($settings['api_provider']) ? $settings['api_provider'] : '';
            // Haal de API-sleutel op uit de nieuwe 'api_keys' array
            $api_keys = $settings['api_keys'] ?? [];
            $value = $api_keys[$current_provider_key] ?? ''; // Toon de sleutel voor de geselecteerde provider
            echo '<input type="password" name="ai_translate_settings[api_key]" value="' . esc_attr($value) . '" class="regular-text">';
            echo ' <span id="api-key-request-link-span" style="margin-left:10px;"></span>';
        },
        'ai-translate',
        'ai_translate_api'
    );
    add_settings_field(
        'selected_model',
        __('Translation Model', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $current_provider = isset($settings['api_provider']) ? $settings['api_provider'] : '';
            $models = isset($settings['models']) ? $settings['models'] : [];
            $selected_model = $current_provider !== '' ? ($models[$current_provider] ?? '') : '';
            $custom_model = isset($settings['custom_model']) ? $settings['custom_model'] : '';
            echo '<select name="ai_translate_settings[selected_model]" id="selected_model">';
            echo '<option value="" ' . selected($selected_model, '', false) . ' disabled hidden>' . esc_html__('— Select model —', 'ai-translate') . '</option>';
            if ($selected_model) {
                echo '<option value="' . esc_attr($selected_model) . '" selected>' . esc_html($selected_model) . '</option>';
            }
            echo '<option value="custom">' . esc_html__('Select...', 'ai-translate') . '</option>';
            echo '</select> ';
            echo '<div id="custom_model_div" style="margin-top:10px; display:none;">';
            echo '<input type="text" name="ai_translate_settings[custom_model]" value="' . esc_attr($custom_model) . '" placeholder="' . esc_attr__('E.g.: deepseek-chat, gpt-4o, ...', 'ai-translate') . '" class="regular-text">';
            echo '</div>';
            echo '<button type="button" class="button" id="ai-translate-validate-api">' . esc_html__('Validate API settings', 'ai-translate') . '</button>';
            echo '<span id="ai-translate-api-status" style="margin-left:10px;"></span>';

        },
        'ai-translate',
        'ai_translate_api'
    );

    // Language Settings Section
    add_settings_section(
        'ai_translate_languages',
        __('Language Settings', 'ai-translate'),
        function () {
            echo '<p>' . esc_html(__('Select the default language for your site and which languages should be available in the language switcher. Detectable languages will be used if a visitor\'s browser preference matches, but won\'t show in the switcher.', 'ai-translate')) . '</p>';
        },
        'ai-translate'
    );
    add_settings_field(
        'default_language',
        __('Default Language', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $value = isset($settings['default_language']) ? $settings['default_language'] : '';
            $core = AI_Translate_Core::get_instance();
            $languages = $core->get_available_languages(); // Get all available languages
            echo '<select name="ai_translate_settings[default_language]">';
            echo '<option value="" ' . selected($value, '', false) . ' disabled hidden>' . esc_html__('— Select default language —', 'ai-translate') . '</option>';
            // Ensure current saved language stays visible if not in list
            if ($value !== '' && !isset($languages[$value])) {
                echo '<option value="' . esc_attr($value) . '" selected>' . esc_html(ucfirst($value)) . ' (' . esc_html__('Current', 'ai-translate') . ')</option>';
            }
            foreach ($languages as $code => $name) {
                echo '<option value="' . esc_attr($code) . '" ' . selected($value, $code, false) . '>' . esc_html($name . ' (' . $code . ')') . '</option>';
            }
            echo '</select>';
        },
        'ai-translate',
        'ai_translate_languages'
    );
    add_settings_field(
        'enabled_languages',
        __('Enabled Languages (in Switcher)', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $enabled = isset($settings['enabled_languages']) ? (array)$settings['enabled_languages'] : [];
            $core = AI_Translate_Core::get_instance();
            $languages = $core->get_available_languages(); // Use all available languages
            echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">';
            // Flags base URL
            $flags_url = plugin_dir_url(__DIR__) . 'assets/flags/';
            foreach ($languages as $code => $name) {
                // Exclude detectable languages from being selectable here? Optional.
                // For now, allow overlap but explain via description.
                echo '<label style="display:block;margin-bottom:5px;">';
                echo '<input type="checkbox" name="ai_translate_settings[enabled_languages][]" value="' . esc_attr($code) . '" ' .
                    checked(in_array($code, $enabled), true, false) . '> ' .
                    '<img src="' . esc_url($flags_url . $code . '.png') . '" alt="' . esc_attr(strtoupper($code)) . '" style="width:16px;height:12px;vertical-align:middle;margin-right:6px;">' .
                    esc_html($name . ' (' . $code . ')');
                echo '</label>';
            }
            echo '</div>';
            echo '<p class="description">' . esc_html(__('Languages selected here will appear in the language switcher.', 'ai-translate')) . '</p>';
        },
        'ai-translate',
        'ai_translate_languages'
    );
    // --- Add Detectable Languages Field ---
    add_settings_field(
        'detectable_languages',
        __('Detectable Languages (Auto-Translate)', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $detected_enabled = isset($settings['detectable_languages']) ? (array)$settings['detectable_languages'] : [];

            $core = AI_Translate_Core::get_instance();
            // Retrieve ALL available languages from the core class
            $languages = $core->get_available_languages();

            // If no detectable languages are set, default to all available languages
            if (empty($detected_enabled)) {
                $detected_enabled = array_keys($languages);
            }

            // Flags base URL
            $flags_url = plugin_dir_url(__DIR__) . 'assets/flags/';

            echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #fff;">';

            // Loop through ALL available languages from the core class
            foreach ($languages as $code => $name) {
                echo '<label style="display:block;margin-bottom:5px;">';
                echo '<input type="checkbox" name="ai_translate_settings[detectable_languages][]" value="' . esc_attr($code) . '" ' .
                    checked(in_array($code, $detected_enabled), true, false) . '> ' .
                    '<img src="' . esc_url($flags_url . $code . '.png') . '" alt="' . esc_attr(strtoupper($code)) . '" style="width:16px;height:12px;vertical-align:middle;margin-right:6px;">' .
                    esc_html($name . ' (' . $code . ')');
                echo '</label>';
            }
            echo '</div>';
            echo '<p class="description">' . esc_html(__('If a visitor\'s browser language matches one of these, the site will be automatically translated (if enabled), but these languages won\'t show in the switcher.', 'ai-translate')) . '</p>';
        },
        'ai-translate',
        'ai_translate_languages'
    );
    // --- End Detectable Languages Field ---

    add_settings_field(
        'switcher_position',
        __('Language Switcher Position', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $position = isset($settings['switcher_position']) ? $settings['switcher_position'] : 'bottom-left';
            $positions = array(
                'bottom-left' => __('Bottom Left Corner', 'ai-translate'),
                'bottom-right' => __('Bottom Right Corner', 'ai-translate'),
                'top-left' => __('Top Left Corner', 'ai-translate'),
                'top-right' => __('Top Right Corner', 'ai-translate'),
                'none' => __('Hidden - Add manually via Appearance > Menus', 'ai-translate'),
            );
            echo '<fieldset>';
            foreach ($positions as $value => $label) {
                echo '<label style="display:block;margin-bottom:8px;">';
                echo '<input type="radio" name="ai_translate_settings[switcher_position]" value="' . esc_attr($value) . '" ' . checked($position, $value, false) . '> ';
                echo esc_html($label);
                echo '</label>';
            }
            echo '</fieldset>';
            echo '<p class="description">' . esc_html(__('Choose where the language switcher appears on your website. "Hidden - Add manually via Appearance > Menus" means the switcher will not be visible in corners - add it manually to your navigation menu instead. Corner positions show a floating switcher. The menu will open upward for bottom positions and downward for top positions.', 'ai-translate')) . '</p>';
            echo '<p><a href="' . esc_url(admin_url('nav-menus.php')) . '" target="_blank" class="button button-secondary">' . esc_html(__('Open Menu Editor', 'ai-translate')) . '</a> ' . esc_html(__('Add language switcher to your navigation menus', 'ai-translate')) . '</p>';
        },
        'ai-translate',
        'ai_translate_languages'
    );

    // Cache Settings Section
    add_settings_section(
        'ai_translate_cache',
        __('Cache Settings', 'ai-translate'),
        null,
        'ai-translate'
    );
    add_settings_field(
        'cache_expiration',
        __('Cache Duration (days)', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $raw = $settings['cache_expiration'] ?? null;
            $days = 14;

            // cache_expiration should be stored in HOURS, but some installs may still have legacy DAYS.
            if (is_numeric($raw)) {
                $num = (float) $raw;
                if ($num >= 14 && $num < (14 * 24)) {
                    // Likely legacy days (e.g. "14")
                    $days = (int) $num;
                } elseif ($num >= (14 * 24)) {
                    // Hours (e.g. 336)
                    $days = (int) floor($num / 24);
                }
            }

            if ($days < 14) {
                $days = 14;
            }
            echo '<input type="number" name="ai_translate_settings[cache_expiration]" value="' . esc_attr($days) . '" class="small-text" min="14"> ' . esc_html__('days', 'ai-translate');
            echo ' <em style="margin-left:10px;">(' . esc_html__('minimum 14 days', 'ai-translate') . ')</em>';
        },
        'ai-translate',
        'ai_translate_cache'
    );
    add_settings_field(
        'keep_slugs_in_english',
        __('Keep URL Slugs in English', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $value = isset($settings['keep_slugs_in_english']) ? (bool)$settings['keep_slugs_in_english'] : false;
            echo '<input type="checkbox" name="ai_translate_settings[keep_slugs_in_english]" value="1" ' . checked($value, true, false) . '> ';
            echo '<label>' . esc_html__('Keep URL slugs in English instead of translating them to target languages', 'ai-translate') . '</label>';
            echo '<p class="description">' . esc_html__('When enabled, URLs will remain consistent across languages (e.g., /zh/cv-tips/ instead of /zh/简历技巧/).', 'ai-translate') . '</p>';
        },
        'ai-translate',
        'ai_translate_cache'
    );
    add_settings_field(
        'auto_clear_pages_on_menu_update',
        __('Auto-Clear Pages on Menu Update', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $raw = $settings['auto_clear_pages_on_menu_update'] ?? true;
            $value = function_exists('wp_validate_boolean') ? wp_validate_boolean($raw) : (bool) $raw;
            echo '<label>';
            echo '<input type="checkbox" name="ai_translate_settings[auto_clear_pages_on_menu_update]" value="1" ' . checked($value, true, false) . '> ';
            echo esc_html__('Automatically clear all page caches when menu items are updated', 'ai-translate');
            echo '</label>';
            echo '<p class="description">';
            echo '<strong>' . esc_html__('Note:', 'ai-translate') . '</strong> ';
            echo esc_html__('Menu translation caches (transients) are ALWAYS cleared when you update menus, regardless of this setting.', 'ai-translate');
            echo '<br>';
            echo '<strong>' . esc_html__('When enabled:', 'ai-translate') . '</strong> ';
            echo esc_html__('All translated page HTML caches will also be cleared. Menu changes are visible immediately, but requires re-translating pages on next visit (expensive for large sites with many languages).', 'ai-translate');
            echo '<br>';
            echo '<strong>' . esc_html__('When disabled:', 'ai-translate') . '</strong> ';
            echo esc_html__('Only menu caches are cleared. Page HTML caches remain. Menu changes in already-cached pages will only be visible after cache expires or manual clearing via Cache tab.', 'ai-translate');
            echo '</p>';
        },
        'ai-translate',
        'ai_translate_cache'
    );
    add_settings_field(
        'multi_domain_caching',
        __('Multi-Domain Caching', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $raw = $settings['multi_domain_caching'] ?? false;
            $value = function_exists('wp_validate_boolean') ? wp_validate_boolean($raw) : (bool) $raw;
            echo '<label>';
            echo '<input type="checkbox" name="ai_translate_settings[multi_domain_caching]" value="1" ' . checked($value, true, false) . '> ';
            echo esc_html__('Enable separate cache per domain', 'ai-translate');
            echo '</label>';
            echo '<p class="description">';
            echo esc_html__('When enabled, each domain will have its own cache directory named after the site name. This prevents cache conflicts when multiple domains share the same WordPress installation.', 'ai-translate');
            echo '</p>';
        },
        'ai-translate',
        'ai_translate_cache'
    );
    add_settings_field(
        'stop_translations_except_cache_invalidation',
        __('Stop translations (except cache invalidation)', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $raw = $settings['stop_translations_except_cache_invalidation'] ?? false;
            $value = function_exists('wp_validate_boolean') ? wp_validate_boolean($raw) : (bool) $raw;
            echo '<label>';
            echo '<input type="checkbox" name="ai_translate_settings[stop_translations_except_cache_invalidation]" value="1" ' . checked($value, true, false) . '> ';
            echo esc_html__('Stop translations (except cache invalidation)', 'ai-translate');
            echo '</label>';
            echo '<p class="description">';
            echo esc_html__('When enabled, new translations via API will be blocked. Translations will only occur when cached pages expire and need to be refreshed.', 'ai-translate');
            echo '</p>';
        },
        'ai-translate',
        'ai_translate_cache'
    );

    // Advanced Settings Section
    add_settings_section(
        'ai_translate_advanced',
        __('Advanced Settings', 'ai-translate'),
        null,
        'ai-translate'
    );

    // --- Add Homepage Meta Description Field ---
    add_settings_field(
        'homepage_meta_description',
        __('Homepage Meta Description', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
            
            // Determine active domain
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
            
            // Get value based on multi-domain setting
            if ($multi_domain) {
                $domain_meta = isset($settings['homepage_meta_description_per_domain']) && is_array($settings['homepage_meta_description_per_domain']) 
                    ? $settings['homepage_meta_description_per_domain'] 
                    : [];
                $value = isset($domain_meta[$active_domain]) ? $domain_meta[$active_domain] : '';
                echo '<p class="description" style="margin-bottom: 10px;"><strong>' . esc_html__('Active domain:', 'ai-translate') . '</strong> <code>' . esc_html($active_domain) . '</code></p>';
            } else {
                $value = isset($settings['homepage_meta_description']) ? $settings['homepage_meta_description'] : '';
            }
            
            echo '<textarea name="ai_translate_settings[homepage_meta_description]" id="homepage_meta_description_field" rows="3" class="large-text">' . esc_textarea($value) . '</textarea>';
            if ($multi_domain) {
                echo '<p class="description">' . esc_html(__('Enter the specific meta description for the homepage for the current domain (in the default language). This will override the site tagline or generated excerpt on the homepage.', 'ai-translate')) . '</p>';
            } else {
                echo '<p class="description">' . esc_html(__('Enter the specific meta description for the homepage (in the default language). This will override the site tagline or generated excerpt on the homepage.', 'ai-translate')) . '</p>';
            }
            echo '<button type="button" class="button" id="generate-meta-btn" style="margin-top: 10px;">' . esc_html__('Generate Meta Description', 'ai-translate') . '</button>';
            echo '<span id="generate-meta-status" style="margin-left: 10px;"></span>';
        },
        'ai-translate',
        'ai_translate_advanced' // Add to Advanced section
    );
    // --- End Homepage Meta Description Field ---

    // --- Add Website Context Field ---
    add_settings_field(
        'website_context',
        __('Website Context', 'ai-translate'),
        function () {
            $settings = get_option('ai_translate_settings');
            $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
            
            // Determine active domain
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
            
            // Get value based on multi-domain setting
            if ($multi_domain) {
                $domain_context = isset($settings['website_context_per_domain']) && is_array($settings['website_context_per_domain']) 
                    ? $settings['website_context_per_domain'] 
                    : [];
                $value = isset($domain_context[$active_domain]) ? $domain_context[$active_domain] : '';
                echo '<p class="description" style="margin-bottom: 10px;"><strong>' . esc_html__('Active domain:', 'ai-translate') . '</strong> <code>' . esc_html($active_domain) . '</code></p>';
            } else {
                $value = isset($settings['website_context']) ? $settings['website_context'] : '';
            }
            
            $placeholder = __('Describe your website, business, or organization. For example:', 'ai-translate') . "\n\n" .
                __('We are a healthcare technology company specializing in patient management systems. Our services include electronic health records, appointment scheduling, and telemedicine solutions. We serve hospitals, clinics, and healthcare providers across Europe.', 'ai-translate') . "\n\n" .
                __('Or:', 'ai-translate') . "\n\n" .
                __('This is a personal blog about sustainable gardening and organic farming techniques. I share tips, tutorials, and experiences from my own garden.', 'ai-translate');
            echo '<textarea name="ai_translate_settings[website_context]" id="website_context_field" rows="5" class="large-text" placeholder="' . esc_attr($placeholder) . '">' . esc_textarea($value) . '</textarea>';
            if ($multi_domain) {
                echo '<p class="description">' . esc_html(__('Provide context about your website or business for the current domain to help the AI generate more accurate and contextually appropriate translations.', 'ai-translate')) . '</p>';
            } else {
                echo '<p class="description">' . esc_html(__('Provide context about your website or business to help the AI generate more accurate and contextually appropriate translations. ', 'ai-translate')) . '</p>';
            }
            echo '<button type="button" class="button" id="generate-context-btn" style="margin-top: 10px;">' . esc_html__('Generate Context from Homepage', 'ai-translate') . '</button>';
            echo '<span id="generate-context-status" style="margin-left: 10px;"></span>';
        },
        'ai-translate',
        'ai_translate_advanced'
    );
    // --- End Website Context Field ---

});

/**
 * Render the AI Translate admin page (tabs: General, Cache).
 *
 * Contains WordPress settings API sections/fields and cache management UI.
 * Uses nonces and capability checks for all mutating actions.
 */
function render_admin_page()
{
    $cache_language_message = '';
    if (
        isset($_POST['clear_cache_language']) &&
        check_admin_referer('clear_cache_language_action', 'clear_cache_language_nonce') &&
        isset($_POST['cache_language']) &&
        class_exists('AI_Translate_Core')
    ) {
        $lang_code = sanitize_text_field(wp_unslash($_POST['cache_language']));
        $core = AI_Translate_Core::get_instance();

        // Get the number of files before deletion
        $cache_stats_before = $core->get_cache_statistics();
        $before_count = isset($cache_stats_before['languages'][$lang_code]) ? $cache_stats_before['languages'][$lang_code] : 0;

        // Clear cache files (now returns an array with more information)
        $result = $core->clear_cache_for_language($lang_code);

        // Get the number of files after deletion
        $cache_stats_after = $core->get_cache_statistics();
        $after_count = isset($cache_stats_after['languages'][$lang_code]) ? $cache_stats_after['languages'][$lang_code] : 0;

        // Get the language code for display (nicer than the code)
        $languages = $core->get_available_languages();
        $lang_name = isset($languages[$lang_code]) ? $languages[$lang_code] : $lang_code;

        // Ensure a clear message
        if ($result['success']) {
            if ($result['count'] > 0) {
                $notice_class = isset($result['warning']) ? 'notice-warning' : 'notice-success';
                $cache_language_message = '<div class="notice ' . esc_attr($notice_class) . '" id="cache-cleared-message">
                    <p>' . sprintf(
                        __('Cache for language %s cleared.', 'ai-translate'),
                        '<strong>' . esc_html($lang_name) . ' (' . esc_html($lang_code) . ')</strong>'
                    ) . ' 
                    <br>' . sprintf(__('Files removed: %d', 'ai-translate'), intval($result['count'])) . ' 
                    <br>' . sprintf(__('Remaining files: %d', 'ai-translate'), intval($after_count)) . '</p>';

                if (isset($result['warning'])) {
                    $cache_language_message .= '<p class="error-message">' . esc_html__('Note:', 'ai-translate') . ' ' . esc_html($result['warning']) . '</p>';
                }

                $cache_language_message .= '</div>';
            } else {
                $cache_language_message = '<div class="notice notice-info" id="cache-cleared-message">
                    <p>' . sprintf(
                        __('No cache files found for language %s.', 'ai-translate'),
                        '<strong>' . esc_html($lang_name) . ' (' . esc_html($lang_code) . ')</strong>'
                    ) . '</p>
                </div>';
            }
        } else {
            $error_message = isset($result['error']) ? $result['error'] : __('Unknown error', 'ai-translate');
            $cache_language_message = '<div class="notice notice-error" id="cache-cleared-message">
                <p>' . sprintf(
                    __('Error clearing cache for language %s: %s', 'ai-translate'),
                    '<strong>' . esc_html($lang_name) . ' (' . esc_html($lang_code) . ')</strong>',
                    esc_html($error_message)
                ) . '</p>
            </div>';
        }
    }

    // Determine active tab
    $active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'general';
?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php settings_errors(); // Display admin notices, including those from add_settings_error ?>
        <!-- Tab navigation -->
        <h2 class="nav-tab-wrapper">
            <a href="?page=ai-translate&tab=general" class="nav-tab <?php echo esc_attr($active_tab === 'general' ? 'nav-tab-active' : ''); ?>"><?php echo esc_html__('General', 'ai-translate'); ?></a>
            <a href="?page=ai-translate&tab=cache" class="nav-tab <?php echo esc_attr($active_tab === 'cache' ? 'nav-tab-active' : ''); ?>"><?php echo esc_html__('Cache', 'ai-translate'); ?></a>

        </h2>
        <div id="tab-content">
            <div id="general" class="tab-panel" style="<?php echo esc_attr($active_tab === 'general' || $active_tab === 'logs' ? 'display:block;' : 'display:none;'); ?>"> <?php // Logs tab now refers to general ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('ai_translate');
                    do_settings_sections('ai-translate');
                    ?>
                    <!-- Hidden field to indicate this is a full form submit (not AJAX) -->
                    <input type="hidden" name="ai_translate_settings[_form_submit]" value="1">
                    <?php
                    submit_button();
                    ?>
                </form>
            </div>
              <div id="cache" class="tab-panel" style="<?php echo esc_attr($active_tab === 'cache' ? 'display:block;' : 'display:none;'); ?>">
                <h2><?php echo esc_html__('Cache Management', 'ai-translate'); ?></h2>
                <?php wp_nonce_field('clear_cache_language_action', 'clear_cache_language_nonce'); // Nonce for AJAX clear language cache ?>
                
                <!-- Clear all caches -->
                <h3><?php echo esc_html__('Clear all language caches', 'ai-translate'); ?></h3>
                <p><?php echo esc_html__('Clear all language caches.', 'ai-translate'); ?> <strong><?php echo esc_html__('Menu and slug cache are preserved to maintain URL stability.', 'ai-translate'); ?></strong></p>
                <?php
                if (isset($_POST['clear_cache']) && check_admin_referer('clear_cache_action', 'clear_cache_nonce')) {
                    if (!class_exists('AI_Translate_Core')) {
                        require_once __DIR__ . '/class-ai-translate-core.php';
                    }
                    $core = AI_Translate_Core::get_instance();
                    // Clear disk-based language caches
                    $core->clear_language_disk_caches_only();
                    // Also clear segment translation transients
                    global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ai_tr_seg_%' OR option_name LIKE '_transient_timeout_ai_tr_seg_%'");
                    // Clear PHP opcache to ensure new code is active
                    if (function_exists('opcache_reset')) {
                        opcache_reset();
                    }
                    echo '<div class="notice notice-success"><p>' . esc_html__('All language caches and translation transients cleared. Menu and slug cache preserved.', 'ai-translate') . '</p></div>';
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_cache_action', 'clear_cache_nonce'); ?>
                    <?php submit_button(__('Clear all language caches', 'ai-translate'), 'delete', 'clear_cache', false); ?>
                </form>

                <hr style="margin: 20px 0;">

                <!-- Clear menu cache (including menu translation tables) -->
                <h3><?php echo esc_html__('Clear menu cache', 'ai-translate'); ?></h3>
                <p><?php echo esc_html__('Clear menu caches including all menu-item translations. This will force fresh translations for all menu items. Languages and Slug map are not affected.', 'ai-translate'); ?></p>
                <?php
                if (isset($_POST['clear_menu_cache']) && check_admin_referer('clear_menu_cache_action', 'clear_menu_cache_nonce')) {
                    if (!current_user_can('manage_options')) {
                        echo '<div class="notice notice-error"><p>' . esc_html__('Insufficient permissions.', 'ai-translate') . '</p></div>';
                    } else {
                        if (!class_exists('AI_Translate_Core')) {
                            require_once __DIR__ . '/class-ai-translate-core.php';
                        }
                        $core = AI_Translate_Core::get_instance();
                        $res = $core->clear_menu_cache();
                        $tables = isset($res['tables_cleared']) && is_array($res['tables_cleared']) ? $res['tables_cleared'] : [];
                        $transients = isset($res['transients_cleared']) ? (int) $res['transients_cleared'] : 0;
                        $msg = __('Menu cache cleared', 'ai-translate');
                        if ($transients > 0) {
                            $msg .= ' (' . sprintf(_n('%d menu-item translation removed', '%d menu-item translations removed', $transients, 'ai-translate'), $transients) . ')';
                        }
                        if (!empty($tables)) {
                            $safe = array_map('esc_html', $tables);
                            $msg .= ' ' . sprintf(__('and tables truncated: %s', 'ai-translate'), implode(', ', $safe));
                        }
                        echo '<div class="notice notice-success"><p>' . esc_html($msg) . '.</p></div>';
                    }
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_menu_cache_action', 'clear_menu_cache_nonce'); ?>
                    <?php submit_button(__('Clear menu cache', 'ai-translate'), 'delete', 'clear_menu_cache', false); ?>
                </form>

                <hr style="margin: 20px 0;">

                <!-- Clear slug cache (truncate slug table) -->
                <h3><?php echo esc_html__('Clear slug cache', 'ai-translate'); ?></h3>
                <p><?php echo esc_html__('Clear the slug table used for translated URLs. Language and menu caches are not affected.', 'ai-translate'); ?></p>
                <?php
                if (isset($_POST['clear_slug_cache']) && check_admin_referer('clear_slug_cache_action', 'clear_slug_cache_nonce')) {
                    if (!current_user_can('manage_options')) {
                        echo '<div class="notice notice-error"><p>' . esc_html__('Insufficient permissions.', 'ai-translate') . '</p></div>';
                    } else {
                        if (!class_exists('AI_Translate_Core')) { require_once __DIR__ . '/class-ai-translate-core.php'; }
                        $core = AI_Translate_Core::get_instance();
                        $res = $core->clear_slug_map();
                        if (!empty($res['success'])) {
                            $num = isset($res['cleared']) ? (int) $res['cleared'] : 0;
                            echo '<div class="notice notice-success"><p>' . sprintf(__('Slug cache cleared. Rows removed: %d.', 'ai-translate'), intval($num)) . '</p></div>';
                        } else {
                            $msg = isset($res['message']) ? $res['message'] : __('Unknown error', 'ai-translate');
                            echo '<div class="notice notice-error"><p>' . sprintf(__('Failed to clear slug cache: %s.', 'ai-translate'), esc_html($msg)) . '</p></div>';
                        }
                    }
                }
                ?>
                <form method="post">
                    <?php wp_nonce_field('clear_slug_cache_action', 'clear_slug_cache_nonce'); ?>
                    <?php submit_button(__('Clear slug cache', 'ai-translate'), 'delete', 'clear_slug_cache', false); ?>
                </form>

                <hr style="margin: 20px 0;">

                
                <h3><?php echo esc_html__('Clear cache per language', 'ai-translate'); ?></h3>
                <?php
                if (!empty($cache_language_message)) {
                    echo wp_kses_post($cache_language_message);
                }
                $core = \AITranslate\AI_Translate_Core::get_instance();
                $languages = $core->get_available_languages();
                
                // Sort languages alphabetically by name (ascending)
                asort($languages);

                // Haal cache statistieken op
                $cache_stats = $core->get_cache_statistics();
                $language_counts = $cache_stats['languages'] ?? [];
                ?>
                <div class="cache-stats-section">
                    <h4><?php echo esc_html__('Cache overview per language', 'ai-translate'); ?></h4>
                    <?php
                    // Show cache directory info
                    $settings = get_option('ai_translate_settings', []);
                    $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
                    if ($multi_domain) {
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
                        
                        $sanitized = sanitize_file_name($active_domain);
                        if (empty($sanitized)) {
                            $sanitized = 'default';
                        }
                        $uploads = wp_upload_dir();
                        $cache_dir = trailingslashit($uploads['basedir']) . 'ai-translate/cache/' . $sanitized . '/';
                        echo '<p class="description" style="margin-bottom: 15px;">';
                        echo '<strong>' . esc_html__('Cache directory:', 'ai-translate') . '</strong> ';
                        echo '<code>' . esc_html($cache_dir) . '</code>';
                        echo '</p>';
                    }
                    ?>
                    <?php
                    // Get detailed statistics
                    $languages_details = isset($cache_stats['languages_details']) ? $cache_stats['languages_details'] : [];
                    $total_expired = $cache_stats['expired_files'] ?? 0;

                    // Calculate total size in MB
                    $total_size_mb = isset($cache_stats['total_size']) ? number_format($cache_stats['total_size'] / (1024 * 1024), 2) : 0;

                    // Last update timestamp
                    $last_modified = isset($cache_stats['last_modified']) ? wp_date('d-m-Y H:i:s', $cache_stats['last_modified']) : __('Unknown', 'ai-translate');
                    ?>

                    <div class="cache-summary" style="margin-bottom: 15px; display: flex; gap: 20px;">
                        <div class="summary-item">
                            <strong><?php echo esc_html__('Total number of files:', 'ai-translate'); ?></strong> <span id="total-cache-count"><?php echo intval($cache_stats['total_files'] ?? 0); ?></span>
                        </div>
                        <div class="summary-item">
                            <strong><?php echo esc_html__('Total size:', 'ai-translate'); ?></strong> <?php echo esc_html($total_size_mb); ?> MB
                        </div>
                        <div class="summary-item">
                            <strong><?php echo esc_html__('Expired files:', 'ai-translate'); ?></strong> <?php echo intval($total_expired); ?>
                        </div>
                        <div class="summary-item">
                            <strong><?php echo esc_html__('Last update:', 'ai-translate'); ?></strong> <?php echo esc_html($last_modified); ?>
                        </div>
                    </div>

                    <table id="cache-language-table" class="widefat striped" style="width: 100%;">
                        <thead>
                            <tr>
                                <th class="sortable" data-sort="language" data-sort-type="text">
                                    <?php echo esc_html__('Language', 'ai-translate'); ?>
                                    <span class="sort-indicator"></span>
                                </th>
                                <th class="sortable" data-sort="files" data-sort-type="number">
                                    <?php echo esc_html__('Cache files', 'ai-translate'); ?>
                                    <span class="sort-indicator"></span>
                                </th>
                                <th class="sortable" data-sort="size" data-sort-type="number">
                                    <?php echo esc_html__('Size', 'ai-translate'); ?>
                                    <span class="sort-indicator"></span>
                                </th>
                                <th class="sortable" data-sort="expired" data-sort-type="number">
                                    <?php echo esc_html__('Expired', 'ai-translate'); ?>
                                    <span class="sort-indicator"></span>
                                </th>
                                <th class="sortable" data-sort="lastupdate" data-sort-type="date">
                                    <?php echo esc_html__('Last update', 'ai-translate'); ?>
                                    <span class="sort-indicator"></span>
                                </th>
                                <th><?php echo esc_html__('Action', 'ai-translate'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $total_files = 0;
                            foreach ($languages as $code => $name):
                                $count = isset($language_counts[$code]) ? $language_counts[$code] : 0;
                                $total_files += $count;

                                // Haal gedetailleerde info op
                                $details = $languages_details[$code] ?? [];
                                $size_mb = isset($details['size']) ? number_format($details['size'] / (1024 * 1024), 2) : '0.00';
                                $expired = isset($details['expired_count']) ? $details['expired_count'] : 0;
                                $last_mod = isset($details['last_modified']) ? wp_date('d-m-Y H:i:s', $details['last_modified']) : 'N/A';
                            ?>
                                <tr id="cache-row-<?php echo esc_attr($code); ?>" class="cache-language-row <?php echo ($count > 0) ? 'has-cache' : 'no-cache'; ?>" 
                                    data-language="<?php echo esc_attr(strtolower($name)); ?>"
                                    data-files="<?php echo intval($count); ?>"
                                    data-size="<?php echo esc_attr($details['size'] ?? 0); ?>"
                                    data-expired="<?php echo intval($expired); ?>"
                                    data-lastupdate="<?php echo esc_attr($details['last_modified'] ?? 0); ?>">
                                    <td>
                                        <button type="button" class="button-link ai-cache-toggle-urls ai-cache-lang-trigger" data-lang="<?php echo esc_attr($code); ?>" aria-expanded="false">
                                            <?php echo esc_html($name); ?> (<?php echo esc_html($code); ?>)
                                        </button>
                                    </td>
                                    <td><span class="cache-count" data-lang="<?php echo esc_attr($code); ?>"><?php echo intval($count); ?></span> <?php echo esc_html__('files', 'ai-translate'); ?></td>
                                    <td><?php echo esc_html($size_mb); ?> MB</td>
                                    <td><?php echo intval($expired); ?></td>
                                    <td><?php echo esc_html($last_mod); ?></td>
                                    <td>
                                        <?php if ($count > 0): ?>
                                            <button type="button" class="button button-small quick-clear-cache" data-lang="<?php echo esc_attr($code); ?>">
                                                <?php echo esc_html__('Cache clear', 'ai-translate'); ?>
                                            </button>
                                        <?php else: ?>
                                            <span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="<?php echo esc_attr__('No cache files', 'ai-translate'); ?>"></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr id="cache-details-<?php echo esc_attr($code); ?>" class="cache-language-details" style="display:none;">
                                    <td colspan="6">
                                        <div class="cache-language-details__content" aria-live="polite"></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <th><?php echo esc_html__('Total', 'ai-translate'); ?></th>
                                <th><span id="table-total-count"><?php echo intval($total_files); ?></span> <?php echo esc_html__('files', 'ai-translate'); ?></th>
                                <th><?php echo esc_html($total_size_mb); ?> MB</th>
                                <th><?php echo intval($total_expired); ?></th>
                                <th><?php echo esc_html($last_modified); ?></th>
                                <th>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <hr style="margin: 30px 0;">

                <!-- Cache Management Table -->
                <h3><?php echo esc_html__('Translated Pages Overview', 'ai-translate'); ?></h3>
                <p><?php echo esc_html__('Manage cache per page or blog. Delete cache to retranslate, or warm cache to proactively generate translations.', 'ai-translate'); ?></p>
                
                <?php
                // Handle manual rescan request
                if (isset($_POST['rescan_cache_meta']) && check_admin_referer('rescan_cache_meta_action', 'rescan_cache_meta_nonce')) {
                    $count = AI_Cache_Meta::populate_existing_cache(true);
                    if ($count > 0) {
                        echo '<div class="notice notice-success is-dismissible"><p>';
                        echo sprintf(__('Cache metadata rescanned: %d cache records added or updated.', 'ai-translate'), $count);
                        echo '</p></div>';
                    } else {
                        echo '<div class="notice notice-warning is-dismissible"><p>';
                        echo __('No cache records added. Possible causes:', 'ai-translate');
                        echo '<ul style="margin-left: 20px; margin-top: 10px;">';
                        echo '<li>' . __('Cache files do not exist at the expected location', 'ai-translate') . '</li>';
                        echo '<li>' . __('Cache files have a different format than expected', 'ai-translate') . '</li>';
                        echo '<li>' . __('Multi-domain caching configuration does not match', 'ai-translate') . '</li>';
                        echo '</ul>';
                        echo '</p></div>';
                    }
                }
                
                // One-time population of existing cache metadata (if not done yet)
                $populated = get_option('ai_translate_cache_meta_populated', false);
                if ($populated === false) {
                    $count = AI_Cache_Meta::populate_existing_cache();
                    update_option('ai_translate_cache_meta_populated', true);
                    if ($count > 0) {
                        echo '<div class="notice notice-info is-dismissible"><p>';
                        echo sprintf(__('Cache metadata initialized: %d existing cache records added.', 'ai-translate'), $count);
                        echo '</p></div>';
                    }
                }
                
                // Clean up orphaned cache metadata records (cache files that no longer exist)
                // Only run cleanup once per hour to avoid performance impact
                $last_cleanup = get_transient('ai_translate_cache_meta_last_cleanup');
                if ($last_cleanup === false) {
                    AI_Cache_Meta::sync_from_filesystem();
                    set_transient('ai_translate_cache_meta_last_cleanup', time(), HOUR_IN_SECONDS);
                }
                
                // Get posts with cache stats
                $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
                $per_page = 50;
                $offset = ($paged - 1) * $per_page;
                
                $posts = AI_Cache_Meta::get_posts_with_cache_stats($offset, $per_page);
                $total_posts = AI_Cache_Meta::get_total_posts_count();
                $total_pages = ceil($total_posts / $per_page);
                
                // Check if table is empty but cache files might exist
                $cache_meta_count = AI_Cache_Meta::get_total_cache_meta_count();
                if ($cache_meta_count === 0 && !empty($posts)) {
                    // Check if cache directory exists (indicates cache files might exist)
                    $uploads = wp_upload_dir();
                    $cache_dir = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
                    if (is_dir($cache_dir)) {
                        // Cache directory exists, suggest rescan
                        echo '<div class="notice notice-warning is-dismissible"><p>';
                        echo __('Cache directory found but no metadata. Click "Scan Cache Files" to generate metadata.', 'ai-translate');
                        echo '</p></div>';
                    }
                }
                ?>
                
                <?php if (!empty($posts)): ?>
                <form method="post" style="margin-bottom: 15px;">
                    <?php wp_nonce_field('rescan_cache_meta_action', 'rescan_cache_meta_nonce'); ?>
                    <button type="submit" name="rescan_cache_meta" class="button button-secondary">
                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                        <?php echo esc_html__('Scan Cache Files', 'ai-translate'); ?>
                    </button>
                    <p class="description" style="margin-top: 5px;">
                        <?php echo esc_html__('Scan the filesystem for cache files and add metadata to the database.', 'ai-translate'); ?>
                    </p>
                </form>
                <?php endif; ?>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th style="width: 8%;"><?php echo esc_html__('Type', 'ai-translate'); ?></th>
                            <th style="width: 30%;"><?php echo esc_html__('Title', 'ai-translate'); ?></th>
                            <th style="width: 25%;"><?php echo esc_html__('URL', 'ai-translate'); ?></th>
                            <th style="width: 12%;"><?php echo esc_html__('Status', 'ai-translate'); ?></th>
                            <th style="width: 25%;"><?php echo esc_html__('Actions', 'ai-translate'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($posts)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 20px;">
                                    <?php echo esc_html__('No published pages or posts found.', 'ai-translate'); ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($posts as $post): ?>
                            <tr>
                                <td><?php echo esc_html(ucfirst($post->post_type)); ?></td>
                                <td>
                                    <strong><?php echo esc_html($post->post_title); ?></strong>
                                </td>
                                <td>
                                    <a href="<?php echo esc_url($post->url); ?>" target="_blank" rel="noopener">
                                        <?php 
                                        $url_display = parse_url($post->url, PHP_URL_PATH);
                                        if (strlen($url_display) > 40) {
                                            $url_display = substr($url_display, 0, 37) . '...';
                                        }
                                        echo esc_html($url_display); 
                                        ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    $percentage = $post->percentage;
                                    $status_class = '';
                                    if ($percentage === 100) {
                                        $status_class = 'ai-translate-status-100';
                                    } elseif ($percentage === 0) {
                                        $status_class = 'ai-translate-status-0';
                                    } else {
                                        $status_class = 'ai-translate-status-partial';
                                    }
                                    ?>
                                    <span class="ai-translate-status <?php echo esc_attr($status_class); ?>">
                                        <?php echo esc_html($post->cached_languages . ' of ' . $post->total_languages); ?>
                                    </span>
                                </td>
                                <td>
                                    <button 
                                        type="button"
                                        class="button button-secondary ai-translate-delete-cache" 
                                        data-post-id="<?php echo esc_attr($post->ID); ?>"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('ai_translate_delete_cache')); ?>"
                                        title="<?php echo esc_attr__('Delete cache for this page in all languages', 'ai-translate'); ?>">
                                        <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                                        <?php echo esc_html__('Delete Cache', 'ai-translate'); ?>
                                    </button>
                                    
                                    <button 
                                        type="button"
                                        class="button button-primary ai-translate-warm-cache" 
                                        data-post-id="<?php echo esc_attr($post->ID); ?>"
                                        data-nonce="<?php echo esc_attr(wp_create_nonce('ai_translate_warm_cache')); ?>"
                                        title="<?php echo esc_attr__('Generate translations for all enabled languages', 'ai-translate'); ?>">
                                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                                        <?php echo esc_html__('Warm Cache', 'ai-translate'); ?>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1): ?>
                <div class="tablenav" style="margin-top: 15px;">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => __('&laquo; Previous', 'ai-translate'),
                            'next_text' => __('Next &raquo;', 'ai-translate'),
                            'total' => $total_pages,
                            'current' => $paged
                        ));
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// --- AJAX handler voor dynamisch ophalen van modellen ---
add_action('wp_ajax_ai_translate_get_models', function () {
    check_ajax_referer('ai_translate_get_models_nonce', 'nonce'); // Add nonce verification
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions.', 'ai-translate')]);
    }
    // Haal actuele waarden uit POST als aanwezig
    $provider_key = isset($_POST['api_provider']) ? sanitize_text_field(wp_unslash($_POST['api_provider'])) : null;
    $api_key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';

    if (!$provider_key) { // Als provider niet in POST zit, haal uit settings (zonder default)
        $settings = get_option('ai_translate_settings');
        $provider_key = $settings['api_provider'] ?? '';
        if (empty($api_key) && $provider_key !== '') { // Als API key ook niet in POST zat, haal uit settings voor de gekozen provider
            $api_keys = $settings['api_keys'] ?? [];
            $api_key = $api_keys[$provider_key] ?? '';
        }
    }
    if (empty($provider_key)) {
        wp_send_json_error(['message' => 'API Provider ontbreekt.']);
    }
    
    $api_url = AI_Translate_Core::get_api_url_for_provider($provider_key);
    // Als de provider 'custom' is, gebruik dan de custom_api_url uit POST
    if ($provider_key === 'custom') {
        if (isset($_POST['custom_api_url_value'])) {
            $api_url = esc_url_raw(trim(sanitize_text_field(wp_unslash($_POST['custom_api_url_value']))));
        } else {
            $settings = isset($settings) ? $settings : get_option('ai_translate_settings');
            if (empty($api_url)) {
                $api_url = esc_url_raw(trim((string) ($settings['custom_api_url'] ?? '')));
            }
        }
    }

    if (empty($api_url) || empty($api_key)) {
        wp_send_json_error(['message' => __('API Provider, API Key or Custom API URL is missing or invalid.', 'ai-translate')]);
        return;
    }

    $endpoint = rtrim($api_url, '/') . '/models';
    $headers = [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json',
    ];
    // OpenRouter requires Referer header
    if ($provider_key === 'openrouter' || ($provider_key === 'custom' && strpos($api_url, 'openrouter.ai') !== false)) {
        $headers['Referer'] = home_url();
        $headers['X-Title'] = get_bloginfo('name');
    }
    $response = wp_remote_get($endpoint, [
        'headers' => $headers,
        'timeout' => 20,
        'sslverify' => true,
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()]);
    }
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($code !== 200) {
        // Check if error is due to invalid API key (401, 403)
        $isInvalidKey = ($code === 401 || $code === 403);
        if ($isInvalidKey) {
            $errorMessage = __('Invalid key', 'ai-translate') . ': ' . $body;
        } else {
            $errorMessage = __('API error:', 'ai-translate') . ' ' . $body;
        }
        wp_send_json_error(['message' => $errorMessage]);
    }
    $data = json_decode($body, true);
    if (!isset($data['data']) || !is_array($data['data'])) {
        wp_send_json_error(['message' => __('Invalid response from API', 'ai-translate')]);
    }
    $models = array_map(function ($m) {
        return is_array($m) && isset($m['id']) ? $m['id'] : (is_string($m) ? $m : null);
    }, $data['data']);
    $models = array_filter($models);
    // Block GPT-5 models as they are designed for complex reasoning tasks and have 3-5x higher latency
    // GPT-5 is unsuitable for real-time website translations (use gpt-4o-mini or gpt-4.1-mini instead)
    $models = array_filter($models, function($model) {
        return !preg_match('/^(gpt-5|o1-|o3-)/i', $model);
    });
    sort($models);
    wp_send_json_success(['models' => $models]);
});

// --- AJAX handler voor dynamisch ophalen van custom URL ---
add_action('wp_ajax_ai_translate_get_custom_url', function () {
    check_ajax_referer('ai_translate_get_custom_url_nonce', 'nonce'); // Nonce verification
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions.', 'ai-translate')]);
    }

    $settings = get_option('ai_translate_settings', []);
    wp_send_json_success(['settings' => $settings]);
});


// --- AJAX handler voor API validatie ---
add_action('wp_ajax_ai_translate_validate_api', function () {
    check_ajax_referer('ai_translate_validate_api_nonce', 'nonce'); // Add nonce verification
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Insufficient permissions.', 'ai-translate')]);
    }

    // Haal actuele waarden uit POST als aanwezig
    $provider_key = isset($_POST['api_provider']) ? sanitize_text_field(wp_unslash($_POST['api_provider'])) : null;
    $api_key = isset($_POST['api_key']) ? trim(sanitize_text_field(wp_unslash($_POST['api_key']))) : '';
    $model = isset($_POST['model']) ? trim(sanitize_text_field(wp_unslash($_POST['model']))) : '';
    $custom_api_url_value = isset($_POST['custom_api_url_value']) ? esc_url_raw(trim(sanitize_text_field(wp_unslash($_POST['custom_api_url_value'])))) : '';

    // Als provider niet in POST zit, haal uit settings
    if (!$provider_key) {
        $settings = get_option('ai_translate_settings');
        $provider_key = $settings['api_provider'] ?? '';
        if ($provider_key !== '') {
            if (empty($api_key)) {
                $api_keys = $settings['api_keys'] ?? [];
                $api_key = $api_keys[$provider_key] ?? '';
            }
            if (empty($model)) { $model = $settings['selected_model'] ?? ''; }
            if (empty($custom_api_url_value)) { $custom_api_url_value = $settings['custom_api_url'] ?? ''; }
        }
    }
    if (empty($provider_key)) {
        wp_send_json_error(['message' => 'API Provider ontbreekt.']);
    }
    if (empty($api_key)) {
        wp_send_json_error(['message' => 'API Key ontbreekt.']);
    }
    if ($provider_key === 'custom' && empty($custom_api_url_value)) {
        wp_send_json_error(['message' => 'Custom API URL ontbreekt voor provider custom.']);
    }

    $core = AI_Translate_Core::get_instance();

    try {
        // Roep de validate_api_settings functie aan in de core class, inclusief model test
        $validation_result = $core->validate_api_settings($provider_key, $api_key, $custom_api_url_value, $model);

        // Als validatie succesvol is, sla settings op
        if (isset($_POST['save_settings']) && $_POST['save_settings'] === '1') {
            $current_settings = get_option('ai_translate_settings', []);
            
            // Behoud alle bestaande instellingen - alleen API-gerelateerde velden worden geüpdatet
            // Dit voorkomt dat andere instellingen (zoals checkboxes) worden gewijzigd
            $updated_settings = $current_settings;
            
            // Zorg ervoor dat 'api_keys' array bestaat
            if (!isset($updated_settings['api_keys']) || !is_array($updated_settings['api_keys'])) {
                $updated_settings['api_keys'] = [];
            }

            // Update alleen de API-gerelateerde velden
            $updated_settings['api_keys'][$provider_key] = $api_key;
            $updated_settings['api_provider'] = $provider_key;
            $updated_settings['selected_model'] = $model;

            // Zorg ervoor dat het model ook per provider wordt weggeschreven
            if (!isset($updated_settings['models']) || !is_array($updated_settings['models'])) {
                $updated_settings['models'] = [];
            }
            if (is_string($model) && $model !== '') {
                $updated_settings['models'][$provider_key] = $model;
            }
            
            if (isset($_POST['custom_model_value'])) {
                $updated_settings['custom_model'] = trim(sanitize_text_field(wp_unslash($_POST['custom_model_value'])));
            } elseif ($model !== 'custom' && isset($updated_settings['custom_model'])) {
                $updated_settings['custom_model'] = '';
            }
            
            // Sla custom_api_url op als deze is meegegeven
            if (!empty($custom_api_url_value)) {
                $updated_settings['custom_api_url'] = $custom_api_url_value;
            }

            // Update checkbox waarden uit POST (indien meegegeven door JS)
            // Dit zorgt ervoor dat als de gebruiker het vinkje verandert en dan valideert, de nieuwe waarde wordt opgeslagen
            if (isset($_POST['stop_translations_except_cache_invalidation'])) {
                $updated_settings['stop_translations_except_cache_invalidation'] = $_POST['stop_translations_except_cache_invalidation'] === '1';
            }
            if (isset($_POST['auto_clear_pages_on_menu_update'])) {
                $updated_settings['auto_clear_pages_on_menu_update'] = $_POST['auto_clear_pages_on_menu_update'] === '1';
            }
            if (isset($_POST['multi_domain_caching'])) {
                $updated_settings['multi_domain_caching'] = $_POST['multi_domain_caching'] === '1';
            }

            // Sla de instellingen op via update_option
            // De sanitize_callback behoudt automatisch alle checkbox-waarden en andere instellingen
            // die niet in $updated_settings staan
            update_option('ai_translate_settings', $updated_settings);
        }
        wp_send_json_success(['message' => __('API and model are working. API settings have been saved.', 'ai-translate')]);

    } catch (\Exception $e) {
        wp_send_json_error(['message' => __('API validation failed:', 'ai-translate') . ' ' . $e->getMessage()]);
    }
});

add_action('update_option_ai_translate_settings', 'AITranslate\\maybe_flush_rules_on_settings_update', 20, 2);

/**
 * Flush rewrite rules when language-related settings change.
 *
 * @param array $old
 * @param array $new
 * @return void
 */
function maybe_flush_rules_on_settings_update($old, $new)
{
    $keys = ['default_language', 'enabled_languages', 'detectable_languages'];
    foreach ($keys as $k) {
        $old_v = isset($old[$k]) ? $old[$k] : null;
        $new_v = isset($new[$k]) ? $new[$k] : null;
        if ($old_v !== $new_v) {
            flush_rewrite_rules();
            return;
        }
    }
}