<?php

namespace AITranslate;

/**
 * Output Buffer manager.
 */
final class AI_OB
{
    /** @var AI_OB|null */
    private static $instance;

    /**
     * Singleton instance.
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Start output buffering.
     */
    public function start()
    {
        ob_start([$this, 'callback'], 0, PHP_OUTPUT_HANDLER_STDFLAGS);
    }

    /**
     * OB callback to translate and cache the page.
     *
     * @param string $html
     * @return string
     */
    public function callback($html)
    {
        static $processing = false;
        if ($processing) {
            return $html;
        }
        $processing = true;

        // PHASE 1: Basic environment checks
        if ($this->should_skip_basic_checks()) {
            $processing = false;
            return $html;
        }

        // PHASE 2: Content type validation
        if ($this->should_skip_content_type($html)) {
            $processing = false;
            return $html;
        }

        // PHASE 3: Page structure validation
        if ($this->should_skip_page_structure()) {
            $processing = false;
            return $html;
        }

        // PHASE 4: File type checks
        if ($this->should_skip_file_types($html)) {
            $processing = false;
            return $html;
        }

        // PHASE 5: Language and user validation
        if ($this->should_skip_language_or_user()) {
            $processing = false;
            return $html;
        }

        // Get validated language (already checked in PHASE 5)
        $lang = AI_Lang::current();

        // For default language: inject SEO tags but no translation
        $needsTranslation = AI_Lang::should_translate($lang);
        if (!$needsTranslation) {
            $htmlWithSEO = AI_SEO::inject($html, $lang);
            $processing = false;
            return $htmlWithSEO;
        }

        // Get user cache bypass setting (already determined in PHASE 5)
        $settings = get_option('ai_translate_settings', array());
        $stopTranslations = isset($settings['stop_translations_except_cache_invalidation']) &&
                           $settings['stop_translations_except_cache_invalidation'];

        $bypassUserCache = false;
        if (!$stopTranslations) {
            if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                $bypassUserCache = true;
            }
            if (function_exists('is_admin_bar_showing') && is_admin_bar_showing()) {
                $bypassUserCache = true;
            }
        }

        $route = $this->current_route_id();
        $url = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        // Allow cache bypass via nocache parameter for testing
        $nocache = isset($_GET['nocache']) || isset($_GET['no_cache']);
        
        // Check for dynamic query parameters that indicate the page should be translated but not cached
        // Examples: WooCommerce add-to-cart, form submissions, AJAX actions, etc.
        $hasDynamicQueryParams = $this->has_dynamic_query_parameters();
        
        // Note: content_version removed from cache key for stability
        // route_id is already unique per page, making content_version unnecessary
        // Cache expiry (14+ days) ensures automatic refresh
        $key = AI_Cache::key($lang, $route, '');

        // Check if route has valid content before proceeding with translation or caching
        // This prevents API calls for menu items, redirects, or paths without actual content
        $hasValidContent = $this->route_has_valid_content($route);
        if (!$hasValidContent) {
            $processing = false;
            return $html; // Return untranslated HTML without processing
        }

        if (!$bypassUserCache && !$nocache && !$hasDynamicQueryParams) {
            $cached = AI_Cache::get($key);
            if ($cached !== false) {
                // Validate cached content for substantial untranslated text (> 4 words)
                $defaultLang = AI_Lang::default();
                if ($defaultLang !== null) {
                    $untranslatedCheck = $this->detect_untranslated_content($cached, $lang, $defaultLang, $url);
                    $wordCount = isset($untranslatedCheck['word_count']) ? (int) $untranslatedCheck['word_count'] : 0;
                    $reason = isset($untranslatedCheck['reason']) ? (string) $untranslatedCheck['reason'] : '';
                    $isUIAttributes = strpos($reason, 'UI attribute') !== false;
                    
                    // UI attributes are handled separately via batch-strings (JavaScript) and stored in transient cache (ai_tr_attr_*)
                    // They are independent of the page cache, so we should NOT invalidate the page cache for UI attributes
                    // Only invalidate for body text with > 4 untranslated words
                    if ($untranslatedCheck['has_untranslated'] && !$isUIAttributes && $wordCount > 4) {
                        $retryKey = 'ai_translate_retry_' . md5($key);
                        $retryCount = (int) get_transient($retryKey);
                        
                        if ($retryCount < 1) {
                            // Body text: use retry counter to avoid infinite loops
                            set_transient($retryKey, $retryCount + 1, HOUR_IN_SECONDS);
                            // Fall through to translation below (don't return cached)
                        } else {
                            // Max retries reached for body text, serve cached version anyway
                            $processing = false;
                            return $cached;
                        }
                    } else {
                        // Cache is good, return it
                        // Note: UI attributes will be translated client-side via batch-strings if needed
                        $processing = false;
                        return $cached;
                    }
                } else {
                    // No default language configured, return cache as-is
                    $processing = false;
                    return $cached;
                }
            }
        } else {
            $bypassReason = $bypassUserCache ? 'logged_in_user' : ($nocache ? 'nocache_param' : 'unknown');
        }
        
        // Implement cache locking to prevent race conditions from concurrent requests
        // When multiple spiders/crawlers hit the same uncached page simultaneously,
        // only the first request generates the translation while others wait
        $lockKey = 'ai_translate_lock_' . md5($key);
        $maxLockWait = 30; // Maximum seconds to wait for lock
        $lockAcquired = false;
        
        if (!$bypassUserCache && !$nocache) {
            $lockStart = time();
            // Try to acquire lock, wait if another process is generating this page
            while (($lockTime = get_transient($lockKey)) !== false) {
                if ((time() - $lockStart) > $maxLockWait) {
                    // Another request is still generating this page; avoid duplicate API calls
                    // Serve the current HTML without starting a second translation pass
                    $processing = false;
                    return $html;
                }
                // Wait 200ms before checking again
                usleep(200000);
                // Check if cache became available while waiting
                $cached = AI_Cache::get($key);
                if ($cached !== false) {
                    $processing = false;
                    return $cached;
                }
            }
            
            // Acquire lock for this page generation
            set_transient($lockKey, time(), 120); // Lock expires after 2 minutes as failsafe
            $lockAcquired = true;
        }
        
        $timeLimit = (int) ini_get('max_execution_time');
        $elapsed = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        $remaining = $timeLimit > 0 ? ($timeLimit - $elapsed) : 120;
        if ($remaining < 20) {
            if ($lockAcquired) {
                delete_transient($lockKey);
            }
            $processing = false;
            return $html;
        }
        
        // Extend execution time for large pages
        if ($timeLimit > 0 && $timeLimit < 120) {
            @set_time_limit(120);
        }

        // Validate HTML before processing: must have minimum length and essential tags
        // This prevents caching incomplete/empty HTML from spiders or partial buffer flushes
        $htmlLen = strlen($html);
        $hasHtml = (stripos($html, '<html') !== false || stripos($html, '<!DOCTYPE') !== false);
        $hasBody = (stripos($html, '<body') !== false);
        
        if ($htmlLen < 500 || !$hasHtml || !$hasBody) {
            if ($lockAcquired) {
                delete_transient($lockKey);
            }
            $processing = false;
            return $html; // Return untranslated incomplete HTML without caching
        }

        // Check if translations are stopped (except for cache invalidation)
        // Exception: search pages should always be translated even if stop_translations is enabled
        $settings = get_option('ai_translate_settings', []);
        $is_search_page = function_exists('is_search') && is_search();
        $stop_translations = isset($settings['stop_translations_except_cache_invalidation']) ? (bool) $settings['stop_translations_except_cache_invalidation'] : false;
        if ($stop_translations && !$is_search_page) {
            // Only allow translation if cache exists and is expired (cache invalidation)
            // Block new translations for pages that don't have a cache yet
            // Use reflection or direct file check to determine if cache exists and is expired
            $uploads = wp_upload_dir();
            $base = trailingslashit($uploads['basedir']) . 'ai-translate/cache/';
            $site_dir = '';
            if (isset($settings['multi_domain_caching']) && (bool) $settings['multi_domain_caching']) {
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
                    $active_domain = 'default';
                }
                $site_dir = sanitize_file_name($active_domain);
                if (empty($site_dir)) {
                    $site_dir = 'default';
                }
            }
            if (!empty($site_dir)) {
                $base = trailingslashit($base) . $site_dir . '/';
            }
            $parts = explode(':', (string) $key);
            $lang_part = isset($parts[3]) ? sanitize_key($parts[3]) : 'xx';
            $dir = $base . $lang_part . '/pages/';
            $hash = md5($key);
            $cache_file = $dir . substr($hash, 0, 2) . '/' . $hash . '.html';
            
            $cache_exists = is_file($cache_file);
            $cache_is_expired = false;
            if ($cache_exists) {
                $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14 * 24);
                $expiry_seconds = $expiry_hours * HOUR_IN_SECONDS;
                $mtime = @filemtime($cache_file);
                if ($mtime) {
                    $age_seconds = time() - (int) $mtime;
                    $cache_is_expired = $age_seconds > $expiry_seconds;
                }
            }
            
            if (!$cache_exists || !$cache_is_expired) {
                // Cache doesn't exist or is not expired, block translation
                if ($lockAcquired) {
                    delete_transient($lockKey);
                }
                $reason = $cache_exists ? 'cache_not_expired' : 'cache_not_exists';
                
                // If cache doesn't exist, inject warning message for logged-in admins
                if (!$cache_exists && function_exists('current_user_can') && current_user_can('manage_options')) {
                    $warning = '<!-- AI-Translate: stop_translations is enabled but no cache exists for ' . esc_html($lang) . ' language. Showing default language. Disable stop_translations to generate cache. -->';
                    $html = str_replace('</head>', $warning . '</head>', $html);
                }
                
                $processing = false;
                return $html; // Return untranslated HTML (or cached if exists)
            }
        }

        $plan = AI_DOM::plan($html);
        $res = AI_Batch::translate_plan($plan, AI_Lang::default(), $lang, $this->site_context());
        $translations = is_array(($res['segments'] ?? null)) ? $res['segments'] : [];
        if (empty($translations)) {
            $processing = false;
            $html2 = $html;
        } else {
            $merged = AI_DOM::merge($plan, $translations, $lang);
            // Preserve original <body> framing to avoid theme conflicts: replace only inner body
            $html2 = $merged;
            if (preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', (string) $merged, $mNew) &&
                preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', (string) $html, $mOrig)) {
                $newInner = (string) $mNew[1];
                $html2 = (string) preg_replace('/(<body\b[^>]*>)[\s\S]*?(<\/body>)/i', '$1' . $newInner . '$2', (string) $html, 1);
            }
            
            // Update HTML lang attribute in the preserved original HTML to match target language
            if ($lang !== null && $lang !== '') {
                $locale = self::getLangAttribute($lang);
                // Replace existing lang attribute
                $html2 = preg_replace('/(<html\b[^>]*\s)lang=["\'][^"\']*["\']/i', '$1lang="' . esc_attr($locale) . '"', $html2, 1);
                // If no lang attribute exists, add it to the html tag
                if (!preg_match('/<html\b[^>]*\slang=/i', $html2)) {
                    $html2 = preg_replace('/(<html\b)([^>]*)>/i', '$1$2 lang="' . esc_attr($locale) . '">', $html2, 1);
                }
            }
        }

        // Ensure lang attribute is set before SEO injection
        if ($lang !== null && $lang !== '' && !empty($html2)) {
            $locale = self::getLangAttribute($lang);
            // Double-check lang attribute is correct in final HTML
            if (!preg_match('/<html\b[^>]*\slang=["\']' . preg_quote($locale, '/') . '["\']/i', $html2)) {
                $html2 = preg_replace('/(<html\b[^>]*\s)lang=["\'][^"\']*["\']/i', '$1lang="' . esc_attr($locale) . '"', $html2, 1);
                if (!preg_match('/<html\b[^>]*\slang=/i', $html2)) {
                    $html2 = preg_replace('/(<html\b)([^>]*)>/i', '$1$2 lang="' . esc_attr($locale) . '">', $html2, 1);
                }
            }
        }

        $html3 = AI_SEO::inject($html2, $lang);
        $html3 = AI_URL::rewrite($html3, $lang);

        // Validate final output before caching
        $html3Len = strlen($html3);
        $html3HasHtml = (stripos($html3, '<html') !== false || stripos($html3, '<!DOCTYPE') !== false);
        $html3HasBody = (stripos($html3, '<body') !== false);
        
        if ($html3Len < 500 || !$html3HasHtml || !$html3HasBody) {
            if ($lockAcquired) {
                delete_transient($lockKey);
            }
            $processing = false;
            return $html3; // Return output but don't cache it
        }

        // Only cache if not bypassed and no dynamic query parameters
        // Dynamic query parameters indicate pages that should be translated but not cached
        // (e.g., WooCommerce add-to-cart, form submissions, AJAX actions)
        if (!$bypassUserCache && !$hasDynamicQueryParams) {
            AI_Cache::set($key, $html3);
        }
        
        // Release lock after successful cache generation
        if ($lockAcquired) {
            delete_transient($lockKey);
        }
        
        $processing = false;
        return $html3;
    }

    /**
     * Check if current request has dynamic query parameters that should not be cached.
     * These pages should be translated but not cached (e.g., WooCommerce add-to-cart, form submissions).
     *
     * @return bool True if dynamic query parameters are present
     */
    private function has_dynamic_query_parameters()
    {
        if (empty($_GET)) {
            return false;
        }
        
        // List of query parameters that indicate dynamic functionality
        // These pages should be translated but NOT cached
        $dynamic_params = array(
            // WooCommerce
            'add-to-cart',
            'remove_item',
            'update_cart',
            'apply_coupon',
            'checkout',
            'order-received',
            'order-pay',
            // Form submissions
            'form_submitted',
            'submit',
            'action',
            // AJAX actions
            'ajax',
            'wc-ajax',
            // User actions
            'login',
            'logout',
            'register',
            'resetpass',
            'lostpassword',
            // Other dynamic actions
            'preview',
            'preview_id',
            'preview_nonce',
            'customize',
            'customize_theme',
        );
        
        // Check if any dynamic parameter is present
        foreach ($dynamic_params as $param) {
            if (isset($_GET[$param])) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Convert language code to proper locale format for HTML lang attribute.
     *
     * @param string $lang Language code (e.g., 'de', 'en', 'nl')
     * @return string Locale format (e.g., 'de-DE', 'en-GB', 'nl-NL')
     */
    private static function getLangAttribute($lang)
    {
        $lang = strtolower(trim($lang));
        $localeMap = [
            'nl' => 'nl-NL',
            'en' => 'en-GB',
            'de' => 'de-DE',
            'fr' => 'fr-FR',
            'es' => 'es-ES',
            'it' => 'it-IT',
            'pt' => 'pt-PT',
        ];
        return $localeMap[$lang] ?? $lang;
    }

    /**
     * Compute a route identifier (path or post ID).
     * Uses consistent detection to prevent cache duplication while supporting search and archives.
     *
     * @return string
     */
    private function current_route_id()
    {
        // Check for search pages first - these need query parameters in route_id
        if (function_exists('is_search') && is_search()) {
            $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
            // For search: include query parameters (especially 's' parameter) in route_id
            // This ensures different search terms get different caches
            $req = preg_replace('#/+#', '/', $req);
            $req = preg_replace('#^/([a-z]{2})(?:/|$)#i', '/', $req);
            if ($req === '') {
                $req = '/';
            }
            // Include query string for search to differentiate search terms
            $query_string = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
            if ($query_string !== '') {
                // Only include relevant query parameters (s, paged, etc.)
                parse_str($query_string, $query_params);
                $relevant_params = array();
                if (isset($query_params['s'])) {
                    $relevant_params['s'] = $query_params['s'];
                }
                if (isset($query_params['paged'])) {
                    $relevant_params['paged'] = $query_params['paged'];
                }
                if (!empty($relevant_params)) {
                    $query_string = http_build_query($relevant_params);
                    return 'path:' . md5($req . '?' . $query_string);
                }
            }
            return 'path:' . md5($req);
        }
        
        // For singular posts/pages: use post ID (consistent, prevents duplicates)
        // Only use post ID if it's actually a singular post/page (not archive/search/attachment)
        if (function_exists('is_singular') && is_singular()) {
            // Skip attachments - they should not be cached
            if (function_exists('is_attachment') && is_attachment()) {
                // Fall through to path-based route_id below
            } else {
                $post_id = get_queried_object_id();
                if ($post_id > 0) {
                    // Double-check: verify this is not an attachment by checking post_type
                    $queried_object = get_queried_object();
                    if ($queried_object && isset($queried_object->post_type) && $queried_object->post_type === 'attachment') {
                        // Fall through to path-based route_id below
                    } else {
                        return 'post:' . $post_id;
                    }
                }
            }
        }
        
        // For homepage: always use consistent route_id to prevent duplicate caches
        // This prevents homepage from being cached multiple times with different route_ids
        if (function_exists('is_front_page') && is_front_page()) {
            $front_page_id = (int) get_option('page_on_front');
            if ($front_page_id > 0) {
                // Static front page: use post ID
                return 'post:' . $front_page_id;
            } else {
                // Posts listing homepage: always use normalized path
                // Normalize to '/' to prevent /en/, /en, //, etc. from creating different caches
                return 'path:' . md5('/');
            }
        }
        
        // Try to get post ID from queried object for edge cases
        // But only if it's not an archive/search/attachment (to avoid caching archives/attachments as posts)
        $post_id = get_queried_object_id();
        if ($post_id > 0) {
            // Double-check: only use post ID if it's not an archive
            if (!function_exists('is_archive') || !is_archive()) {
                // Additional check: verify this is actually a post/page, not a term or attachment
                $queried_object = get_queried_object();
                if ($queried_object && isset($queried_object->post_type)) {
                    // Skip attachments - they should not be cached
                    if ($queried_object->post_type !== 'attachment') {
                        return 'post:' . $post_id;
                    }
                }
            }
        }
        
        // Fallback: Try to find post by URL path for edge cases where is_singular() fails
        // This prevents duplicate caches for the same page
        $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        if ($req !== '') {
            // Normalize: remove query string and fragments for path lookup
            $path = (string) parse_url($req, PHP_URL_PATH);
            if ($path === null) {
                $path = $req;
            }
            
            // Normalize slashes
            $path = preg_replace('#/+#', '/', $path);
            
            // Remove leading language code to get clean path
            $clean_path = preg_replace('#^/([a-z]{2})(?:/|$)#i', '/', $path);
            if ($clean_path === '') {
                $clean_path = '/';
            }
            
            // Try to find post by path (without language prefix)
            // This helps catch cases where WordPress hasn't set up query yet
            // But skip if this looks like an archive (has category/tag/etc in path)
            $path_parts = array_filter(explode('/', trim($clean_path, '/')));
            if (!empty($path_parts) && !in_array($clean_path, array('/category/', '/tag/', '/author/', '/date/'))) {
                $slug = end($path_parts);
            // Try to find post/page by slug (exclude attachments)
            $found_post = get_page_by_path($slug, OBJECT, array('post', 'page'));
            if ($found_post && isset($found_post->ID)) {
                // Skip attachments - they should not be cached
                if (isset($found_post->post_type) && $found_post->post_type !== 'attachment') {
                    return 'post:' . $found_post->ID;
                }
            }
            }
        }
        
        // Final fallback: use path-based route_id for archives, etc.
        // Remove language code prefix from URI to prevent cache duplication across languages
        $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        if ($req === '') {
            $req = '/';
        }
        // Normalize multiple slashes
        $req = preg_replace('#/+#', '/', $req);
        // Remove leading language code (e.g., /en/ or /de/)
        $req = preg_replace('#^/([a-z]{2})(?:/|$)#i', '/', $req);
        if ($req === '') {
            $req = '/';
        }
        
        // For regular archives/pages: use path only (no query parameters)
        // Query parameters are handled separately:
        // - Search: query params included in route_id (handled above)
        // - WooCommerce/dynamic: query params excluded, page not cached (handled by has_dynamic_query_parameters)
        // - Tracking params: excluded to prevent duplicate caches
        $req_path = (string) parse_url($req, PHP_URL_PATH);
        if ($req_path === null) {
            $req_path = $req;
        }
        
        // Normalize homepage paths to prevent duplicate caches
        // /, /en/, /en, //, etc. should all become /
        $req_path = preg_replace('#^/+$#', '/', $req_path);
        if ($req_path === '' || $req_path === '//') {
            $req_path = '/';
        }
        
        return 'path:' . md5($req_path);
    }

    /**
     * PHASE 1: Basic environment checks
     * Skip processing for admin pages, etc.
     */
    private function should_skip_basic_checks()
    {
        // Skip admin pages
        if (is_admin()) {
            return true;
        }

        return false;
    }

    /**
     * PHASE 2: Content type validation
     * Skip processing for 404s, attachments, archives, etc.
     */
    private function should_skip_content_type($html)
    {
        // Skip 404 pages - multiple detection methods
        if ($this->is_404_page($html)) {
            return true;
        }

        // Skip attachment pages - multiple detection methods
        if ($this->is_attachment_page()) {
            return true;
        }

        // Skip archive pages - multiple archive types
        if ($this->is_archive_page()) {
            return true;
        }

        return false;
    }

    /**
     * PHASE 3: Page structure validation
     * Ensure we only process appropriate page types
     */
    private function should_skip_page_structure()
    {
        // Only translate singular posts/pages, with exceptions for search/homepage
        if (function_exists('is_singular') && !is_singular()) {
            $is_search = function_exists('is_search') && is_search();
            $is_front_page = function_exists('is_front_page') && is_front_page();
            if (!$is_search && !$is_front_page) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHASE 4: File type checks
     * Skip XML files, sitemaps, etc.
     */
    private function should_skip_file_types($html)
    {
        // Skip XML files and sitemaps
        $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (preg_match('/\.xml$/i', $reqPath) ||
            isset($_GET['sitemap']) ||
            isset($_GET['sitemap-index']) ||
            preg_match('/sitemap/i', $reqPath)) {
            return true;
        }

        // Skip XML content
        $htmlTrimmed = trim($html);
        if (strpos($htmlTrimmed, '<?xml') === 0 || strpos($htmlTrimmed, '<xml') === 0) {
            return true;
        }

        return false;
    }

    /**
     * PHASE 5: Language and user validation
     * Handle language settings and user-specific bypasses
     */
    private function should_skip_language_or_user()
    {
        // Language validation
        $lang = AI_Lang::current();
        if ($lang === null) {
            return true;
        }

        // For default language: inject SEO but no translation (handled later)
        $needsTranslation = AI_Lang::should_translate($lang);
        if (!$needsTranslation) {
            return false; // Allow processing for SEO injection
        }

        // User cache bypass (logged-in users, admin bar) - allow processing but skip caching
        $settings = get_option('ai_translate_settings', array());
        $stopTranslations = isset($settings['stop_translations_except_cache_invalidation']) &&
                           $settings['stop_translations_except_cache_invalidation'];

        if (!$stopTranslations) {
            if (function_exists('is_user_logged_in') && is_user_logged_in()) {
                return false; // Allow processing, bypass cache later
            }
            if (function_exists('is_admin_bar_showing') && is_admin_bar_showing()) {
                return false; // Allow processing, bypass cache later
            }
        }

        return false;
    }

    /**
     * Check if current page is a 404 error page
     */
    private function is_404_page($html)
    {
        global $wp_query;
        if (isset($wp_query) && is_object($wp_query) && $wp_query->is_404()) {
            return true;
        }

        // Fallback: check HTML content for 404 indicators
        $htmlLower = strtolower($html);
        $is404InContent = (
            stripos($html, '404') !== false && (
                stripos($htmlLower, 'page not found') !== false ||
                stripos($htmlLower, 'niet gevonden') !== false ||
                stripos($htmlLower, 'nicht gefunden') !== false ||
                stripos($htmlLower, 'page non trouvée') !== false ||
                stripos($htmlLower, 'página no encontrada') !== false ||
                stripos($htmlLower, 'pagina non trovata') !== false ||
                (stripos($htmlLower, '<title') !== false &&
                 stripos($htmlLower, '404') !== false &&
                 stripos($htmlLower, 'not found') !== false)
            )
        );

        return $is404InContent;
    }

    /**
     * Check if current page is an attachment page
     */
    private function is_attachment_page()
    {
        // Primary check
        if (function_exists('is_attachment') && is_attachment()) {
            return true;
        }

        // Queried object check
        $queried_object = get_queried_object();
        if ($queried_object && isset($queried_object->post_type) && $queried_object->post_type === 'attachment') {
            return true;
        }

        // Post ID check
        $current_post_id = get_queried_object_id();
        if ($current_post_id > 0) {
            $current_post = get_post($current_post_id);
            if ($current_post && isset($current_post->post_type) && $current_post->post_type === 'attachment') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if current page is an archive page
     */
    private function is_archive_page()
    {
        // Primary archive check
        if (function_exists('is_archive') && is_archive()) {
            return true;
        }

        // Specific archive types
        $archive_functions = ['is_category', 'is_tag', 'is_author', 'is_date', 'is_tax'];
        foreach ($archive_functions as $function) {
            if (function_exists($function) && $function()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a route corresponds to valid content that should be cached.
     * Prevents caching of menu items, redirects, or paths without actual content.
     *
     * @param string $route The route_id (e.g., 'post:123' or 'path:md5hash')
     * @return bool True if route has valid content, false otherwise
     */
    private function route_has_valid_content($route)
    {
        // If route is post-based, verify the post exists and is published
        if (strpos($route, 'post:') === 0) {
            $post_id = (int) substr($route, 5);
            if ($post_id > 0) {
                $post = get_post($post_id);
                // Only cache if post exists, is published, and is not a nav_menu_item
                return $post && $post->post_status === 'publish' && $post->post_type !== 'nav_menu_item';
            }
            return false;
        }

        // For path-based routes, check if WordPress has a valid query
        if (strpos($route, 'path:') === 0) {
            // Check if current query has valid posts
            if (function_exists('have_posts') && have_posts()) {
                return true;
            }

            // Check for archives, search, or other valid non-singular pages
            if (function_exists('is_archive') && is_archive()) {
                return true;
            }
            if (function_exists('is_search') && is_search()) {
                return true;
            }
            if (function_exists('is_front_page') && is_front_page()) {
                return true;
            }

            // For other paths, try to find if there's actual content
            // This prevents caching of menu-only paths or redirects
            $queried_object = get_queried_object();
            if ($queried_object && isset($queried_object->post_type)) {
                // Allow real content types, but not nav_menu_item
                return $queried_object->post_type !== 'nav_menu_item';
            }

            // If no valid content found, don't cache
            return false;
        }

        // Unknown route format, be conservative and don't cache
        return false;
    }

    /**
     * Compute a content version hash based on common content signals.
     *
     * @return string
     */
    private function content_version()
    {
        $bits = [];
        if (is_singular()) {
            $post_id = get_queried_object_id();
            $modified = $post_id ? get_post_modified_time('U', true, $post_id) : 0;
            $bits[] = 'singular:' . (int) $post_id . ':' . (int) $modified;
        } else {
            // Archive/search: include query vars and recent posts modified.
            $q = get_queried_object();
            $bits[] = 'archive:' . maybe_serialize($q);
        }
        return substr(sha1(implode('|', $bits)), 0, 16);
    }

    /**
     * Build site context for translation prompts.
     *
     * @return array
     */
    private function site_context()
    {
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
        
        // Get homepage meta description (per-domain if multi-domain caching is enabled)
        $homepage_meta = '';
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
            
            $domain_meta = isset($settings['homepage_meta_description_per_domain']) && is_array($settings['homepage_meta_description_per_domain']) 
                ? $settings['homepage_meta_description_per_domain'] 
                : [];
            
            if (isset($domain_meta[$active_domain]) && trim((string) $domain_meta[$active_domain]) !== '') {
                $homepage_meta = trim((string) $domain_meta[$active_domain]);
            }
        }
        
        if (empty($homepage_meta)) {
            $homepage_meta = isset($settings['homepage_meta_description']) ? (string)$settings['homepage_meta_description'] : '';
        }
        
        return [
            'site_name' => (string) get_bloginfo('name'),
            'default_language' => AI_Lang::default(),
            'website_context' => $website_context,
            'homepage_meta_description' => $homepage_meta,
        ];
    }

    /**
     * Detect if translated HTML contains substantial untranslated text (> 4 words).
     * Only detects character-set mismatches: Latin ↔ Non-Latin.
     * Does NOT detect Latin → Latin (e.g. EN→DE, NL→FR) as this requires language-specific analysis.
     * Also checks UI attributes (placeholder, title, aria-label, button values) for untranslated content.
     *
     * @param string $html Translated HTML
     * @param string $targetLang Target language code
     * @param string $sourceLang Source language code
     * @param string $url Request URL for logging
     * @return array{has_untranslated:bool,word_count:int,reason:string,untranslated_attributes?:array}
     */
    private function detect_untranslated_content($html, $targetLang, $sourceLang, $url = '')
    {
        $result = ['has_untranslated' => false, 'word_count' => 0, 'reason' => ''];
        
        $doc = new \DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);
        
        $xpath = new \DOMXPath($doc);
        $bodyNodes = $xpath->query('//body');
        if (!$bodyNodes || $bodyNodes->length === 0) {
            return $result;
        }

        // Check UI attributes for untranslated content (works for all languages)
        $uiAttributesCheck = $this->check_ui_attributes_untranslated($xpath, $targetLang, $sourceLang, $url);
        if ($uiAttributesCheck['has_untranslated']) {
            $result['has_untranslated'] = true;
            $result['word_count'] = $uiAttributesCheck['word_count'];
            $result['reason'] = $uiAttributesCheck['reason'];
            $result['untranslated_attributes'] = $uiAttributesCheck['untranslated_attributes'] ?? [];
            return $result;
        }

        // Strip boilerplate (nav/footer/forms/scripts) before text extraction to reduce false positives.
        $pruneSelectors = ['//script', '//style', '//noscript', '//svg', '//nav', '//header', '//footer', '//form', '//input', '//button', '//select', '//option', '//textarea'];
        foreach ($pruneSelectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes instanceof \DOMNodeList) {
                foreach ($nodes as $node) {
                    if ($node->parentNode) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }

        // Prefer <main> or <article> content when available; fall back to body.
        $contentNode = $xpath->query('//main');
        if (!$contentNode || $contentNode->length === 0) {
            $contentNode = $xpath->query('//article');
        }
        $textSource = ($contentNode && $contentNode->length > 0) ? $contentNode->item(0) : $bodyNodes->item(0);
        $bodyText = trim((string) $textSource->textContent);
        if (mb_strlen($bodyText) < 50) {
            return $result;
        }
        
        $nonLatinLangs = ['zh', 'ja', 'ko', 'ar', 'he', 'th', 'ka', 'ru', 'uk', 'bg', 'el', 'hi'];
        $sourceIsNonLatin = in_array(strtolower($sourceLang), $nonLatinLangs, true);
        $targetIsNonLatin = in_array(strtolower($targetLang), $nonLatinLangs, true);
        
        // Only detect if character sets differ (Latin ↔ Non-Latin)
        if ($sourceIsNonLatin === $targetIsNonLatin) {
            return $result;
        }
        
        $latinChars = preg_match_all('/[a-zA-Z]/', $bodyText);
        $totalChars = mb_strlen($bodyText);
        $latinRatio = $totalChars > 0 ? ($latinChars / $totalChars) : 0;
        
        // Use more lenient threshold for non-Latin target languages (0.70 instead of 0.40)
        // This allows natural Latin content like URLs, brand names, and boilerplate
        // Many websites have significant Latin content even in non-Latin languages
        $expectedLatinRatio = $targetIsNonLatin ? 0.70 : 0.50;
        $unexpectedDirection = $targetIsNonLatin ? ($latinRatio > $expectedLatinRatio) : ($latinRatio < $expectedLatinRatio);
        
        if ($unexpectedDirection) {
            preg_match_all('/\b[a-zA-Z]+\b/', $bodyText, $matches);
            $latinWords = isset($matches[0]) ? $matches[0] : [];
            
            $commonExclusions = ['CEO', 'CTO', 'IT', 'AI', 'API', 'URL', 'SEO', 'SaaS', 'B2B', 'B2C', 
                'WordPress', 'NetCare', 'Centillien', 'LinkedIn', 'Facebook', 'Twitter', 'Instagram',
                'WhatsApp', 'YouTube', 'Google', 'Microsoft', 'Apple', 'iPhone', 'iPad', 'Android',
                'Windows', 'Mac', 'Linux', 'HTML', 'CSS', 'JavaScript', 'PHP', 'SQL', 'HTTP', 'HTTPS',
                'PDF', 'JSON', 'XML', 'REST', 'SOAP', 'VPN', 'DNS', 'IP', 'TCP', 'UDP', 'USB', 'RAM',
                'CPU', 'GPU', 'SSD', 'HDD', 'DVD', 'CD', 'iOS', 'macOS', 'Wi-Fi', 'Bluetooth', 'NFC'];
            
            $filteredWords = array_filter($latinWords, function($word) use ($commonExclusions) {
                if (mb_strlen($word) <= 2) return false;
                if (is_numeric($word)) return false;
                if (in_array($word, $commonExclusions, true)) return false;
                if (strtoupper($word) === $word && mb_strlen($word) <= 5) return false;
                return true;
            });
            
            $wordCount = count($filteredWords);
            $normalizedText = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $bodyText);
            $allWords = preg_split('/\s+/u', (string) $normalizedText, -1, PREG_SPLIT_NO_EMPTY);
            $totalWordCount = is_array($allWords) ? count($allWords) : 0;
            $latinWordRatio = $totalWordCount > 0 ? ($wordCount / $totalWordCount) : 0;

            // Require substantial proportion of Latin words before invalidating non-Latin targets.
            if ($wordCount > 4 && (!$targetIsNonLatin || $latinWordRatio > 0.55)) {
                $result['has_untranslated'] = true;
                $result['word_count'] = $wordCount;
                
                if ($targetIsNonLatin) {
                    $result['reason'] = sprintf('Non-Latin target (%s) has %d Latin words (%.1f%% Latin)', 
                        strtoupper($targetLang), $wordCount, $latinRatio * 100);
                } else {
                    $result['reason'] = sprintf('Latin target (%s) has too few Latin chars (%.1f%%, expected >%.0f%%)', 
                        strtoupper($targetLang), $latinRatio * 100, $expectedLatinRatio * 100);
                }
                
                return $result;
            }
        }
        
        return $result;
    }

    /**
     * Check if UI attributes (placeholder, title, aria-label, button values) are untranslated.
     * Works for all languages by checking if attributes are cached in transient.
     *
     * @param \DOMXPath $xpath XPath instance for DOM navigation
     * @param string $targetLang Target language code
     * @param string $sourceLang Source language code
     * @param string $url Request URL for logging
     * @return array{has_untranslated:bool,word_count:int,reason:string,untranslated_attributes:array}
     */
    private function check_ui_attributes_untranslated($xpath, $targetLang, $sourceLang, $url = '')
    {
        $result = ['has_untranslated' => false, 'word_count' => 0, 'reason' => '', 'untranslated_attributes' => []];
        
        // Skip check for default language
        if (strtolower($targetLang) === strtolower($sourceLang)) {
            return $result;
        }
        
        // Collect UI attributes that need translation (same as JavaScript does)
        $uiStrings = [];
        $uiStringsWithAttr = [];
        $nodes = $xpath->query('//input | //textarea | //select | //button | //*[@title] | //*[@aria-label] | //*[contains(@class, "initial-greeting")] | //*[contains(@class, "chatbot-bot-text")]');
        
        if (!$nodes || $nodes->length === 0) {
            return $result;
        }
        
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            
            // Skip elements with data-ai-trans-skip attribute
            if ($node->hasAttribute('data-ai-trans-skip')) {
                continue;
            }
            
            // Collect placeholder
            if ($node->hasAttribute('placeholder')) {
                $text = trim($node->getAttribute('placeholder'));
                if ($text !== '' && mb_strlen($text) >= 2) {
                    $normalized = preg_replace('/\s+/u', ' ', $text);
                    $uiStrings[$normalized] = $normalized;
                    $uiStringsWithAttr[$normalized] = ['attr' => 'placeholder', 'text' => $text, 'tag' => strtolower($node->tagName ?? '')];
                }
            }
            
            // Collect title
            if ($node->hasAttribute('title')) {
                $text = trim($node->getAttribute('title'));
                if ($text !== '' && mb_strlen($text) >= 2) {
                    $normalized = preg_replace('/\s+/u', ' ', $text);
                    $uiStrings[$normalized] = $normalized;
                    if (!isset($uiStringsWithAttr[$normalized])) {
                        $uiStringsWithAttr[$normalized] = ['attr' => 'title', 'text' => $text, 'tag' => strtolower($node->tagName ?? '')];
                    }
                }
            }
            
            // Collect aria-label
            if ($node->hasAttribute('aria-label')) {
                $text = trim($node->getAttribute('aria-label'));
                if ($text !== '' && mb_strlen($text) >= 2) {
                    $normalized = preg_replace('/\s+/u', ' ', $text);
                    $uiStrings[$normalized] = $normalized;
                    if (!isset($uiStringsWithAttr[$normalized])) {
                        $uiStringsWithAttr[$normalized] = ['attr' => 'aria-label', 'text' => $text, 'tag' => strtolower($node->tagName ?? '')];
                    }
                }
            }
            
            // Collect value for input buttons
            $tagName = strtolower($node->tagName ?? '');
            if ($tagName === 'input') {
                $type = strtolower($node->getAttribute('type') ?? '');
                if (in_array($type, ['submit', 'button', 'reset'], true)) {
                    $text = trim($node->getAttribute('value') ?? '');
                    if ($text !== '' && mb_strlen($text) >= 2) {
                        $normalized = preg_replace('/\s+/u', ' ', $text);
                        $uiStrings[$normalized] = $normalized;
                        if (!isset($uiStringsWithAttr[$normalized])) {
                            $uiStringsWithAttr[$normalized] = ['attr' => 'value', 'text' => $text, 'tag' => 'input[' . $type . ']'];
                        }
                    }
                }
            }
            
            // Collect textContent for chatbot elements
            if ($node->hasAttribute('class')) {
                $classes = $node->getAttribute('class');
                if (strpos($classes, 'initial-greeting') !== false || strpos($classes, 'chatbot-bot-text') !== false) {
                    $text = trim($node->textContent ?? '');
                    if ($text !== '' && mb_strlen($text) >= 2) {
                        $normalized = preg_replace('/\s+/u', ' ', $text);
                        $uiStrings[$normalized] = $normalized;
                        if (!isset($uiStringsWithAttr[$normalized])) {
                            $uiStringsWithAttr[$normalized] = ['attr' => 'textContent', 'text' => $text, 'tag' => strtolower($node->tagName ?? '')];
                        }
                    }
                }
            }
        }
        
        if (empty($uiStrings)) {
            return $result;
        }
        
        // Check if UI strings are cached (translated)
        // UI attributes can be cached in two places:
        // 1. ai_tr_attr_* (JavaScript batch-strings cache)
        // 2. ai_tr_seg_* (PHP translation plan cache, format: ai_tr_seg_{lang}_{md5('attr|md5(text)')})
        $untranslatedCount = 0;
        $untranslatedAttributes = [];
        foreach ($uiStrings as $normalized) {
            $cached = false;
            
            // First check JavaScript cache (ai_tr_attr_*)
            $attrCacheKey = 'ai_tr_attr_' . $targetLang . '_' . md5($normalized);
            $cached = function_exists('ai_translate_get_attr_transient') 
                ? ai_translate_get_attr_transient($attrCacheKey) 
                : get_transient($attrCacheKey);
            
            // If not found in JavaScript cache, check PHP translation plan cache (ai_tr_seg_*)
            if ($cached === false) {
                $segKey = 'attr|' . md5($normalized);
                $segCacheKey = 'ai_tr_seg_' . $targetLang . '_' . md5($segKey);
                $cached = get_transient($segCacheKey);
            }
            
            if ($cached === false) {
                // Heuristic: if text already appears to be in target language, seed cache to prevent repeated invalidations
                $textSample = isset($uiStringsWithAttr[$normalized]['text']) ? (string) $uiStringsWithAttr[$normalized]['text'] : $normalized;
                $looksTarget = $this->looks_like_target_lang($textSample, $targetLang, $sourceLang);
                if ($looksTarget) {
                    // Seed attr cache with the original text as translation
                    if (function_exists('ai_translate_set_attr_transient')) {
                        ai_translate_set_attr_transient($attrCacheKey, $textSample, DAY_IN_SECONDS);
                    } else {
                        set_transient($attrCacheKey, $textSample, DAY_IN_SECONDS);
                    }
                    if (isset($uiStringsWithAttr[$normalized])) {
                        $uiStringsWithAttr[$normalized]['seeded'] = true;
                    }
                    // Mark as cached to skip counting as untranslated
                    $cached = $textSample;
                } else {
                    $untranslatedCount++;
                    if (isset($uiStringsWithAttr[$normalized])) {
                        $untranslatedAttributes[] = $uiStringsWithAttr[$normalized];
                    }
                }
            }
        }
        
        if ($untranslatedCount > 0) {
            $result['has_untranslated'] = true;
            $result['word_count'] = $untranslatedCount;
            $result['reason'] = sprintf('Found %d untranslated UI attribute(s) (placeholder/title/aria-label/button values)', $untranslatedCount);
            $result['untranslated_attributes'] = $untranslatedAttributes;
        }
        
        return $result;
    }

    /**
     * Simple heuristic to detect if a text already matches the target language script.
     * For non-Latin targets: accept when Latin char ratio < 0.30.
     * For Latin targets: accept when Latin char ratio >= 0.30 and Cyrillic/Arabic/etc. are minimal.
     */
    private function looks_like_target_lang($text, $targetLang, $sourceLang)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return false;
        }
        $latinCount = preg_match_all('/[A-Za-z]/', $text);
        $totalChars = max(1, mb_strlen($text));
        $latinRatio = $latinCount / $totalChars;
        
        $nonLatinTargets = ['zh','ja','ko','ar','he','th','ka','ru','uk','bg','el','hi'];
        $targetIsNonLatin = in_array(strtolower($targetLang), $nonLatinTargets, true);
        $sourceIsNonLatin = in_array(strtolower($sourceLang), $nonLatinTargets, true);
        
        // If target is non-Latin: consider translated when Latin ratio is low
        if ($targetIsNonLatin && $latinRatio < 0.30) {
            return true;
        }
        // If target is Latin and source is non-Latin: consider translated when Latin ratio is reasonable
        if (!$targetIsNonLatin && $sourceIsNonLatin && $latinRatio >= 0.30) {
            return true;
        }
        return false;
    }
}
