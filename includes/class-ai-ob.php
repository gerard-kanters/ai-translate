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
     * Post-process cached content to handle admin bar and update permalinks.
     *
     * @param string $html The cached HTML content
     * @return string Modified HTML with correct admin bar state and updated permalinks
     */
    private function post_process_cached_content($html)
    {
        // Check if current user should see admin bar
        $should_show_admin_bar = function_exists('is_admin_bar_showing') && is_admin_bar_showing();

        // Check if admin bar is currently present in HTML
        $has_admin_bar = strpos($html, 'id="wpadminbar"') !== false;


        if ($should_show_admin_bar && !$has_admin_bar) {
            // Admin should see admin bar but it's not in cached content
            // Add simple admin bar HTML
            $admin_bar_html = $this->get_simple_admin_bar_html();
            $html = preg_replace('/(<body[^>]*>)/', '$1' . $admin_bar_html, $html, 1);
        } elseif (!$should_show_admin_bar && $has_admin_bar) {
            // Regular user should NOT see admin bar but it's in cached content
            // Remove admin bar completely
            $html = preg_replace(
                '/<div[^>]*id=["\']wpadminbar["\'][^>]*>.*?<\/div>/s',
                '',
                $html
            );
        }

        // Update internal permalinks to use current translated slugs
        $html = $this->update_cached_permalinks($html);

        return $html;
    }

    /**
     * Update internal permalinks in cached HTML to use current translated slugs.
     * This ensures that even if slugs change after caching, links remain correct.
     *
     * @param string $html Cached HTML content
     * @return string HTML with updated permalinks
     */
    private function update_cached_permalinks($html)
    {
        $lang = \AITranslate\AI_Lang::current();
        $default = \AITranslate\AI_Lang::default();
        
        if ($lang === null || $default === null || strtolower($lang) === strtolower($default)) {
            return $html;
        }

        // Find all internal links: href="/xx/service/slug/" or href="/service/slug/"
        // Pattern matches: /{lang}/service/{slug}/ or /service/{slug}/
        $pattern = '#href="(/(?:' . preg_quote($lang, '#') . '/)?service/([^/"]+)/?)"#i';
        
        $html = preg_replace_callback($pattern, function ($matches) use ($lang) {
            $full_href = $matches[1];
            $old_slug = $matches[2];
            
            // Try to find the post by this slug (could be source or old translated slug)
            $post_id = \AITranslate\AI_Slugs::resolve_translated_slug_to_post($old_slug, $lang);
            if (!$post_id) {
                // Try resolving as source slug
                $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $old_slug);
            }
            
            if ($post_id) {
                // Only use existing slug (no translation): we are serving from page cache and must not trigger segment cache writes
                $correct_slug = \AITranslate\AI_Slugs::get_or_generate($post_id, $lang, false);
                if ($correct_slug !== null && $correct_slug !== '' && $correct_slug !== $old_slug) {
                    // Replace with correct slug
                    $new_href = '/' . $lang . '/service/' . trim($correct_slug, '/') . '/';
                    return 'href="' . $new_href . '"';
                }
            }
            
            // No change needed or couldn't resolve
            return $matches[0];
        }, $html);

        return $html;
    }

    /**
     * Get simple admin bar HTML for cached content.
     *
     * @return string Simple admin bar HTML
     */
    private function get_simple_admin_bar_html()
    {
        return '<div id="wpadminbar" class="nojq"><div class="quicklinks"><ul class="ab-top-menu"><li><a href="/wp-admin/">Dashboard</a></li><li><a href="/wp-admin/edit.php">Posts</a></li><li><a href="/wp-admin/users.php">Users</a></li><li><a href="/wp-login.php?action=logout">Logout</a></li></ul></div></div><style>#wpadminbar{background:#23282d;height:32px;position:fixed;top:0;left:0;right:0;z-index:99999;font-size:13px}#wpadminbar .quicklinks{padding:0 24px}#wpadminbar .ab-top-menu{margin:0;padding:6px 0;list-style:none}#wpadminbar .ab-top-menu li{float:left;margin:0 6px 0 0}#wpadminbar .ab-top-menu li a{color:#eee;text-decoration:none;padding:4px 8px}#wpadminbar .ab-top-menu li a:hover{background:#32373c}body{margin-top:32px!important}</style>';
    }

    /**
     * OB callback to translate and cache the page.
     *
     * @param string $html
     * @return string
     */
    public function callback($html)
    {
        // Detect warm cache requests (bypass static processing check)
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $is_warm_cache_request = (strpos($user_agent, 'AITranslateCacheWarmer') !== false);
        
        static $processing = false;
        
        // Allow warm cache requests to bypass static processing check (they need to generate cache)
        if ($processing && !$is_warm_cache_request) {
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

        // PERFORMANCE FIX: Disable cache bypass completely for all users
        // This ensures optimal performance for everyone, including admins
        $bypassUserCache = false;

        $route = $this->current_route_id();
        $url = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        // URL decode only if double-encoded (contains %25)
        if (strpos($url, '%25') !== false) {
            $url = urldecode($url);
        }
        // Allow cache bypass via nocache parameter for testing
        $nocache = isset($_GET['nocache']) || isset($_GET['no_cache']);
        
        // Check for dynamic query parameters that indicate the page should be translated but not cached
        // Examples: WooCommerce add-to-cart, form submissions, AJAX actions, etc.
        $hasDynamicQueryParams = $this->has_dynamic_query_parameters();
        
        // Note: content_version removed from cache key for stability
        // route_id is already unique per page, making content_version unnecessary
        // Cache expiry (14+ days) ensures automatic refresh
        $key = AI_Cache::key($lang, $route, '');

        // Check if route should be translated (but may not be cached)
        // This allows search pages and other dynamic content to be translated but not cached
        $shouldTranslate = $this->route_should_be_translated($route);
        if (!$shouldTranslate) {
            $processing = false;
            return $html; // Return untranslated HTML without processing
        }

        if (!$bypassUserCache && !$nocache && !$hasDynamicQueryParams) {
            $cached = AI_Cache::get($key);

            if ($cached !== false) {
                // PERFORMANCE FIX: Skip expensive untranslated content validation for cached content
                // This validation can take 10+ seconds and is not necessary for admin users or recent cache

                // All users get cached content with admin bar post-processing
                $processing = false;
                return $this->post_process_cached_content($cached);
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

        // Combined SEO + URL pass: single DOM parse instead of two separate ones
        $doc = new \DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $htmlToLoad = AI_DOM::ensureUtf8($html2);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $htmlToLoad, LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $xpath = new \DOMXPath($doc);
        AI_SEO::inject_dom($doc, $xpath, $lang);
        AI_URL::rewrite_dom($doc, $lang);

        $html3 = $doc->saveHTML();
        $html3 = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $html3);
        if (preg_match('/^(<!DOCTYPE[^>]*>)/i', $html2, $docMatch)) {
            if (stripos($html3, '<!DOCTYPE') === false) {
                $html3 = $docMatch[1] . "\n" . $html3;
            }
        }

        // Translate chatbot greeting strings embedded in inline script JSON (e.g. Kognetiks kchat_settings).
        // These are JavaScript strings that become DOM content at runtime, outside our DOM translation scope.
        $defaultLang = AI_Lang::default();
        if ($defaultLang !== null) {
            $html3 = $this->translateInlineScriptJsonValues($html3, $lang, $defaultLang);
        }

        // Validate final output before caching
        $html3Len = strlen($html3);
        $html3HasHtml = (stripos($html3, '<html') !== false || stripos($html3, '<!DOCTYPE') !== false);
        $html3HasBody = (stripos($html3, '<body') !== false);
        
        if ($html3Len < 500 || !$html3HasHtml || !$html3HasBody) {
            if ($lockAcquired) {
                delete_transient($lockKey);
            }
            $processing = false;
            return $this->post_process_cached_content($html3); // Return output but don't cache it
        }

        // Only cache if not bypassed, no dynamic query parameters, and content is cacheable
        // Dynamic query parameters indicate pages that should be translated but not cached
        // (e.g., WooCommerce add-to-cart, form submissions, AJAX actions)
        // Additional check: only cache if route corresponds to cacheable content (not search pages, etc.)
        $isCacheable = $this->route_has_valid_content($route);
        
        if (!$bypassUserCache && !$hasDynamicQueryParams && $isCacheable) {
            AI_Cache::set($key, $html3);
        }
        
        // Release lock after successful cache generation
        if ($lockAcquired) {
            delete_transient($lockKey);
        }
        
        $processing = false;
        return $this->post_process_cached_content($html3);
    }

    /**
     * Translate known JSON string values inside inline &lt;script&gt; tags.
     * Targets chatbot plugins (e.g., Kognetiks) that embed translatable strings in JS settings objects.
     * Uses the existing transient cache; only calls the translation API on cache miss.
     *
     * @param string $html  Full page HTML
     * @param string $lang  Target language code
     * @param string $default  Default (source) language code
     * @return string HTML with translated inline script values
     */
    private function translateInlineScriptJsonValues($html, $lang, $default)
    {
        if ($lang === $default) {
            return $html;
        }

        // JSON keys whose values should be translated (chatbot greetings/names)
        $keys = [
            'chatbot_chatgpt_initial_greeting',
            'chatbot_chatgpt_subsequent_greeting',
            'chatbot_chatgpt_bot_name',
        ];

        foreach ($keys as $jsonKey) {
            $pattern = '/"' . preg_quote($jsonKey, '/') . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/';
            $html = preg_replace_callback($pattern, function ($m) use ($lang, $default, $jsonKey) {
                $jsonStr = $m[1];
                $original = json_decode('"' . $jsonStr . '"');
                if ($original === null || trim($original) === '') {
                    return $m[0];
                }
                $normalized = preg_replace('/\s+/u', ' ', trim($original));
                if (mb_strlen($normalized) < 2) {
                    return $m[0];
                }

                // Check transient cache first
                $cacheKey = 'ai_tr_attr_' . $lang . '_' . md5($normalized);
                $cached = ai_translate_get_attr_transient($cacheKey);
                if ($cached !== false) {
                    $escaped = substr(json_encode((string) $cached, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
                    return '"' . $jsonKey . '":"' . $escaped . '"';
                }

                // Cache miss — translate via AI_Batch
                $settings = \AITranslate\AI_Translate_Core::settings();
                $ctx = ['website_context' => isset($settings['website_context']) ? (string) $settings['website_context'] : ''];
                $plan = ['segments' => [['id' => 's1', 'text' => $normalized, 'type' => 'node']]];
                $res = \AITranslate\AI_Batch::translate_plan($plan, $default, $lang, $ctx);
                $segs = isset($res['segments']) && is_array($res['segments']) ? $res['segments'] : [];
                $tr = isset($segs['s1']) ? (string) $segs['s1'] : $normalized;

                // Cache the result
                $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14 * 24);
                $expiry = max(1, $expiry_hours) * HOUR_IN_SECONDS;
                ai_translate_set_attr_transient($cacheKey, $tr, $expiry);

                $escaped = substr(json_encode($tr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
                return '"' . $jsonKey . '":"' . $escaped . '"';
            }, $html);
        }

        return $html;
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
            // URL decode only if double-encoded (contains %25)
            if (strpos($req, '%25') !== false) {
                $req = urldecode($req);
            }
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
        
        // CRITICAL: For warm cache requests and translated URLs, resolve the post ID from the URL FIRST
        // This is needed because WordPress query functions (is_singular, get_queried_object_id) 
        // may not work correctly during warm cache internal requests
        $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($req_uri !== '' && preg_match('#^/([a-z]{2})/([^/?]+)/?#i', $req_uri, $url_match)) {
            $url_lang = strtolower($url_match[1]);
            $url_slug = $url_match[2];
            // Try to resolve the translated slug to a post ID
            $resolved_id = \AITranslate\AI_Slugs::resolve_path_to_post($url_lang, $url_slug);
            if ($resolved_id !== null && $resolved_id > 0) {
                return 'post:' . $resolved_id;
            }
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
        // URL decode only if double-encoded (contains %25)
        if (strpos($req, '%25') !== false) {
            $req = urldecode($req);
        }
        if ($req !== '') {
            // Normalize: remove query string and fragments for path lookup
            $path = (string) parse_url($req, PHP_URL_PATH);
            if ($path === null) {
                $path = $req;
            }
            
            // Normalize slashes
            $path = preg_replace('#/+#', '/', $path);
            
            // Extract language code from path
            $lang_from_path = null;
            if (preg_match('#^/([a-z]{2})(?:/|$)#i', $path, $lang_match)) {
                $lang_from_path = strtolower($lang_match[1]);
            }
            
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
                
                // IMPORTANT: First try to resolve translated slug to post ID using AI_Slugs
                // This is needed for warm cache requests where the URL uses translated slugs
                if ($lang_from_path !== null) {
                    $resolved_post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang_from_path, $slug);
                    if ($resolved_post_id !== null && $resolved_post_id > 0) {
                        return 'post:' . $resolved_post_id;
                    }
                }
                
                // Fallback: Try to find post/page by original slug
                $public_post_types = get_post_types(array('public' => true), 'names');
                $public_post_types = array_diff($public_post_types, array('attachment'));
                if (empty($public_post_types)) {
                    $public_post_types = array('post', 'page');
                }
                $found_post = get_page_by_path($slug, OBJECT, array_values($public_post_types));
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
        // URL decode only if double-encoded (contains %25 indicating double encoding)
        if (strpos($req, '%25') !== false) {
            $req = urldecode($req);
        }
        // Normalize multiple slashes
        $req = preg_replace('#/+#', '/', $req);
        // Remove leading language code (e.g., /en/ or /de/)
        $req = preg_replace('#^/([a-z]{2})(?:/|$)#i', '/', $req);
        if ($req === '') {
            $req = '/';
        }

        // Try to resolve translated slugs back to source slugs for proper caching
        $path_parts = array_filter(explode('/', trim($req, '/')));
        if (!empty($path_parts)) {
            $last_part = end($path_parts);
            $source_slug = \AITranslate\AI_Slugs::resolve_any_to_source_slug($last_part);
            if ($source_slug !== null) {
                // Replace the translated slug with the source slug
                $path_parts[count($path_parts) - 1] = $source_slug;
                $req = '/' . implode('/', $path_parts) . '/';
            }
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

        // Skip redirect pages - detect redirect responses
        if ($this->is_redirect_page($html)) {
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
        $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        // URL decode only if double-encoded (contains %25)
        if (strpos($req_uri, '%25') !== false) {
            $req_uri = urldecode($req_uri);
        }
        $reqPath = (string) parse_url($req_uri, PHP_URL_PATH);
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

        // PERFORMANCE FIX: Disable cache bypass completely for all users
        // This ensures optimal performance for everyone, including admins
        // Admins can still see real-time translations by clearing cache when needed

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
     * Check if current page is a redirect response.
     *
     * @param string $html
     * @return bool
     */
    private function is_redirect_page($html)
    {
        // PERFORMANCE CRITICAL: Avoid expensive HTTP requests on every page load
        // Only check for actual redirect indicators, not make HTTP requests

        // Check 1: headers already sent contain Location header
        if (!headers_sent() && function_exists('headers_list')) {
            $headers = headers_list();
            foreach ($headers as $header) {
                if (stripos($header, 'Location:') === 0) {
                    return true;
                }
            }
        }

        // Check 2: HTTP status code indicates redirect
        $status_code = http_response_code();
        if (in_array($status_code, array(301, 302, 303, 307, 308), true)) {
            return true;
        }

        // PERFORMANCE CRITICAL: Completely disable HTTP requests for redirect detection
        // The performance impact is too severe. Rely only on WordPress built-in redirect detection
        // which uses headers and status codes, not external HTTP requests.
        // This check is not critical for the plugin's core functionality.
        return false;
    }

    /**
     * Get the current request URL.
     *
     * @return string|null
     */
    private function get_current_url()
    {
        if (!isset($_SERVER['HTTP_HOST']) || !isset($_SERVER['REQUEST_URI'])) {
            return null;
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));

        return $protocol . '://' . $host . $uri;
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
     * Check if a route should be translated (may or may not be cached).
     * This is more permissive than route_has_valid_content() - allows search pages, etc.
     *
     * @param string $route The route_id (e.g., 'post:123' or 'path:md5hash')
     * @return bool True if route should be translated
     */
    private function route_should_be_translated($route)
    {
        // If route is post-based, verify the post exists, is published, and has content
        if (strpos($route, 'post:') === 0) {
            $post_id = (int) substr($route, 5);
            if ($post_id > 0) {
                $post = get_post($post_id);
                // Translate if post exists, is published, and has meaningful content
                if ($post && $post->post_status === 'publish') {
                    // Skip specific post types that are plugin elements without real content
                    $skip_post_types = ['easy-pricing-table', 'nav_menu_item'];
                    if (in_array($post->post_type, $skip_post_types)) {
                        return false;
                    }

                    // For other post types, require at least some meaningful content
                    $content_length = strlen(trim($post->post_content ?? ''));
                    // Allow posts with substantial content, or any content for standard post types
                    if ($content_length > 50 || in_array($post->post_type, ['post', 'page'])) {
                        return true;
                    }
                }
            }
            return false;
        }

        // For path-based routes, allow more content types
        if (strpos($route, 'path:') === 0) {
            // Check if current query has valid posts (including search results)
            if (function_exists('have_posts')) {
                return true; // Allow even empty search results to be translated
            }

            // Check for archives, search, or other valid pages
            if (function_exists('is_archive') && is_archive()) {
                return true;
            }
            if (function_exists('is_search') && is_search()) {
                // Don't translate empty search queries - they produce no meaningful results
                $search_query = isset($_GET['s']) ? trim($_GET['s']) : '';
                if (empty($search_query)) {
                    return false; // Skip empty search queries
                }
                return true; // Translate search pages with actual queries
            }
            if (function_exists('is_front_page') && is_front_page()) {
                return true;
            }

            // For other paths, check if there's a queried object
            $queried_object = get_queried_object();
            if ($queried_object) {
                return true; // Allow translation for any valid WordPress query
            }

            // If no valid content found, don't translate
            return false;
        }

        // Unknown route format, be conservative and don't translate
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
            // Never cache pages with search query parameters - they are dynamic
            // This catches search pages, filtered archives, and other dynamic content
            // Block any page that has a search parameter, even if empty
            if (isset($_GET['s'])) {
                return false; // Don't cache search results (even empty searches)
            }

            // Also check for other dynamic query parameters that indicate non-cacheable content
            $dynamic_params = ['filter', 'orderby', 'order', 'paged'];
            foreach ($dynamic_params as $param) {
                if (isset($_GET[$param]) && !empty($_GET[$param])) {
                    return false; // Don't cache filtered/paged content
                }
            }

            // Check if current query has valid posts
            if (function_exists('have_posts') && have_posts()) {
                return true;
            }

            // Check for archives or other valid non-singular pages
            if (function_exists('is_archive') && is_archive()) {
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
     * Build site context for translation prompts using centralized helpers.
     *
     * @return array
     */
    private function site_context()
    {
        return [
            'site_name' => (string) get_bloginfo('name'),
            'default_language' => AI_Lang::default(),
            'website_context' => AI_Translate_Core::get_website_context(),
            'homepage_meta_description' => AI_Translate_Core::get_homepage_meta_description(),
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
        $nodes = $xpath->query('//input | //textarea | //select | //button | //*[@title] | //*[@aria-label] | //img[@alt] | //*[contains(@class, "initial-greeting")] | //*[contains(@class, "chatbot-bot-text")]');
        
        if (!$nodes || $nodes->length === 0) {
            return $result;
        }
        
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            
            // Skip elements with data-ai-trans-skip attribute (also check ancestors)
            $skip = false;
            $check = $node;
            while ($check instanceof \DOMElement) {
                if ($check->hasAttribute('data-ai-trans-skip')) {
                    $skip = true;
                    break;
                }
                $check = $check->parentNode;
            }
            if ($skip) {
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
            
            // Collect alt for images
            if ($node->hasAttribute('alt')) {
                $text = trim($node->getAttribute('alt'));
                if ($text !== '' && mb_strlen($text) >= 2) {
                    $normalized = preg_replace('/\s+/u', ' ', $text);
                    $uiStrings[$normalized] = $normalized;
                    if (!isset($uiStringsWithAttr[$normalized])) {
                        $uiStringsWithAttr[$normalized] = ['attr' => 'alt', 'text' => $text, 'tag' => strtolower($node->tagName ?? '')];
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