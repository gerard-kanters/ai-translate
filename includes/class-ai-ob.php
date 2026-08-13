<?php

namespace AITranslate;

// Output-buffer logic only reads $_GET / $_SERVER to decide which cache file to serve and which
// language to render. No state is mutated in those branches; nonce verification would be
// inappropriate here. State-changing handlers live in admin-page.php and verify nonces explicitly.
// phpcs:disable WordPress.Security.NonceVerification.Recommended

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
        // Ensure is_rtl() reflects the active AI Translate language before the
        // theme renders (body_class, language_attributes, rtl.css enqueue).
        $lang = AI_Lang::current();
        if ($lang !== null && $lang !== '') {
            AI_Lang::sync_wp_text_direction($lang);
        }
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
            // Admin should see admin bar but it's not in cached content – add it.
            $admin_bar_html = $this->get_simple_admin_bar_html();
            $html = preg_replace('/(<body[^>]*>)/', '$1' . $admin_bar_html, $html, 1);
        } elseif (!$should_show_admin_bar && $has_admin_bar) {
            // Regular user should NOT see admin bar but it's in cached content – strip it.
            // Uses a depth-counting string traversal instead of DOMDocument to avoid a
            // third DOM parse (pipeline already uses exactly two DOMDocument passes).
            $html = self::strip_admin_bar($html);
        }

        // Update internal permalinks to use current translated slugs
        $html = $this->update_cached_permalinks($html);

        // Replace WordPress site_url host with current request host to prevent cross-origin AJAX issues
        $html = $this->fix_cached_domain($html);

        // Pages cached before dir="rtl" support lack the attribute; apply it on serve
        // so existing Arabic/Hebrew caches render right-to-left immediately instead of
        // only after cache expiry. Idempotent for pages already generated with the fix.
        $active_lang = AI_Lang::current();
        if ($active_lang !== null && AI_Lang::is_rtl($active_lang)) {
            $html = self::apply_html_lang_dir($html, $active_lang);
        }

        return $html;
    }

    /**
     * Replace the WordPress site_url host with the current request host in cached HTML.
     * Prevents cross-origin AJAX failures when a site is accessed via an alias domain.
     *
     * @param string $html
     * @return string
     */
    private function fix_cached_domain($html)
    {
        $request_host = isset($_SERVER['HTTP_HOST']) ? strtok(sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_HOST'])), ':') : '';
        if ($request_host === '') {
            return $html;
        }

        // Extract the domain baked into cached HTML from WordPress URL patterns
        if (!preg_match('#https?://([a-z0-9.-]+)/wp-(?:content|admin|includes)/#i', $html, $m)) {
            return $html;
        }
        $cached_host = $m[1];

        if (strcasecmp($cached_host, $request_host) === 0) {
            return $html;
        }

        return str_replace($cached_host, $request_host, $html);
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
        $lang    = \AITranslate\AI_Lang::current();
        $default = \AITranslate\AI_Lang::default();

        if ($lang === null || $default === null || strtolower($lang) === strtolower($default)) {
            return $html;
        }

        // Match all internal links that already carry the language prefix:
        //   href="/{lang}/slug/"  or  href="/{lang}/base/slug/"
        // Group 1 = full path (e.g. /de/diensten/pakket/)
        // Group 2 = everything after the language segment (e.g. diensten/pakket)
        $lang_re = preg_quote($lang, '#');
        $pattern = '#href="(/' . $lang_re . '/([^"?\#\s]+)/?)"#i';

        $html = preg_replace_callback($pattern, function ($matches) use ($lang) {
            $path     = trim($matches[2], '/'); // e.g. "diensten/pakket"
            $parts    = explode('/', $path);
            $old_slug = array_pop($parts);      // last segment is the slug

            if ($old_slug === '') {
                return $matches[0];
            }

            // Try to find the post by translated slug, then by source slug.
            $post_id = \AITranslate\AI_Slugs::resolve_translated_slug_to_post($old_slug, $lang);
            if (!$post_id) {
                $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $old_slug);
            }

            if ($post_id) {
                // No-generate: serving from cache must not trigger new segment-cache writes.
                $correct_slug = \AITranslate\AI_Slugs::get_or_generate($post_id, $lang, false);
                if ($correct_slug !== null && $correct_slug !== '' && $correct_slug !== $old_slug) {
                    $parts[]  = trim($correct_slug, '/');
                    $new_href = '/' . $lang . '/' . implode('/', $parts) . '/';
                    return 'href="' . $new_href . '"';
                }
            }

            return $matches[0];
        }, $html);

        return $html;
    }

    /**
     * Strip the WordPress admin bar from HTML without using DOMDocument.
     *
     * Uses a depth-counting character scan to find the matching </div> for the
     * wpadminbar container, plus regex for the associated inline <style> blocks
     * and the admin-bar CSS class on <html>/<body>.
     *
     * @param string $html
     * @return string
     */
    private static function strip_admin_bar(string $html): string
    {
        // Locate the opening <div tag that carries id="wpadminbar"
        if (!preg_match('/<div\b[^>]*\bid=["\']wpadminbar["\'][^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $div_start  = (int) $m[0][1];
        $after_open = $div_start + strlen($m[0][0]);

        // Walk the string counting <div>…</div> depth to find the matching closing tag.
        $depth   = 1;
        $pos     = $after_open;
        $len     = strlen($html);
        $div_end = null;
        while ($pos < $len && $depth > 0) {
            if (substr_compare($html, '<div', $pos, 4, true) === 0) {
                $c = $pos + 4 < $len ? $html[$pos + 4] : ' ';
                if ($c === ' ' || $c === '>' || $c === "\n" || $c === "\r" || $c === "\t" || $c === '/') {
                    $depth++;
                    $pos += 4;
                    continue;
                }
            }
            if (substr_compare($html, '</div>', $pos, 6, true) === 0) {
                $depth--;
                if ($depth === 0) {
                    $div_end = $pos + 6;
                    break;
                }
                $pos += 6;
                continue;
            }
            $pos++;
        }

        if ($div_end !== null) {
            $html = substr($html, 0, $div_start) . substr($html, $div_end);
        }

        // Remove inline <style> blocks that belong to the admin bar.
        $html = preg_replace(
            '/<style\b[^>]*>[\s\S]*?(?:#wpadminbar\b|body\s*\{[^}]*margin-top\s*:\s*32px)[\s\S]*?<\/style>/i',
            '',
            $html
        );

        // Remove admin-bar class from <html> and <body> opening tags.
        $html = preg_replace('/(<(?:html|body)\b[^>]*?)\s*\badmin-bar\b([^>]*>)/i', '$1$2', $html, 2);

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
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])) : '';
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
        $stopTranslations = (bool) AI_Translate_Core::get_setting('stop_translations_except_cache_invalidation', false);

        // PERFORMANCE FIX: Disable cache bypass completely for all users
        // This ensures optimal performance for everyone, including admins
        $bypassUserCache = false;

        $route = $this->current_route_id();
        $url = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
        // URL decode only if double-encoded (contains %25)
        if (strpos($url, '%25') !== false) {
            $url = urldecode($url);
        }
        // Allow cache bypass via nocache parameter for authenticated admins only
        $nocache = (isset($_GET['nocache']) || isset($_GET['no_cache'])) && current_user_can('manage_options');
        
        // Check for dynamic query parameters that indicate the page should be translated but not cached
        // Examples: WooCommerce add-to-cart, form submissions, AJAX actions, etc.
        $hasDynamicQueryParams = $this->has_dynamic_query_parameters();

        // DONOTCACHEPAGE means "do not store this response". WooCommerce sets it on
        // cart/checkout/my-account (session HTML). Jetpack Subscriptions also sets it
        // for every manage_options user via is_user_auth() — those admins must still
        // be served the shared page cache (admin bar is injected afterwards).
        $noCachePage = defined('DONOTCACHEPAGE') && DONOTCACHEPAGE;
        $neverCachedPostIds = AI_Cache_Meta::get_never_cached_post_ids();
        $routePostId = (strpos((string) $route, 'post:') === 0) ? (int) substr((string) $route, 5) : 0;
        $isNeverCachedRoute = ($routePostId > 0 && in_array($routePostId, $neverCachedPostIds, true));
        $isPrivilegedAdmin = function_exists('current_user_can') && current_user_can('manage_options');
        // Jetpack Subscriptions defines DONOTCACHEPAGE for every manage_options user.
        // Those admins still get the shared page cache; the admin bar is injected after.
        // Session-specific routes (cart, paywalls, dynamic query params) still skip the read.
        $skipCacheRead = $hasDynamicQueryParams
            || $isNeverCachedRoute
            || ((bool) $noCachePage && !$isPrivilegedAdmin);

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

        if (!$bypassUserCache && !$nocache && !$skipCacheRead) {
            $cached = AI_Cache::get($key);

            if ($cached !== false && !$this->content_matches_target_lang($cached, $lang)) {
                $retryKey = 'ai_tr_retry_' . md5($key);
                $retries = (int) get_transient($retryKey);
                if ($retries >= 3) {
                    // Max retries reached — serve cached content as-is
                } else {
                    AI_Cache::delete($key);
                    $cached = false;
                }
            }

            // Reject truncated cache (from bot visits): missing closing tags breaks chat/greeting
            if ($cached !== false) {
                $hasClosingTags = (stripos($cached, '</body>') !== false && stripos($cached, '</html>') !== false);
                if (!$hasClosingTags) {
                    AI_Cache::delete($key);
                    $cached = false;
                }
            }

            if ($cached !== false) {
                $processing = false;
                return $this->post_process_cached_content($cached);
            }
        }
        
        // Implement cache locking to prevent race conditions from concurrent requests
        // When multiple spiders/crawlers hit the same uncached page simultaneously,
        // only the first request generates the translation while others wait
        $lockKey = 'ai_translate_lock_' . md5($key);
        $maxLockWait = 30; // Maximum seconds to wait for lock
        $lockAcquired = false;

        // Pages that will not be written to the shared cache (session-specific HTML
        // or DONOTCACHEPAGE) must not wait on the lock: nothing will appear.
        if (!$bypassUserCache && !$nocache && !$noCachePage && !$isNeverCachedRoute) {
            $lockStart = time();
            // Wait while another process holds the lock
            while (!self::try_acquire_cache_lock($lockKey)) {
                if ((time() - $lockStart) > $maxLockWait) {
                    // Another request is still generating this page; avoid duplicate API calls
                    $processing = false;
                    return $html;
                }
                usleep(200000);
                $cached = AI_Cache::get($key);
                if ($cached !== false) {
                    $processing = false;
                    return $this->post_process_cached_content($cached);
                }
            }
            $lockAcquired = true;
        }
        
        $timeLimit = (int) ini_get('max_execution_time');
        $elapsed = microtime(true) - (float) (isset($_SERVER['REQUEST_TIME_FLOAT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_TIME_FLOAT'])) : microtime(true));
        $remaining = $timeLimit > 0 ? ($timeLimit - $elapsed) : 120;
        if ($remaining < 20) {
            if ($lockAcquired) {
                self::release_cache_lock($lockKey);
            }
            $processing = false;
            return $html;
        }
        
        // Extend execution time for large pages (translation pipeline can exceed default 30s on big HTML).
        if ($timeLimit > 0 && $timeLimit < 120) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required for large-page translation that exceeds default execution time
            @set_time_limit(120);
        }

        // Validate HTML before processing: must have minimum length and essential tags
        // This prevents caching incomplete/empty HTML from spiders or partial buffer flushes
        $htmlLen = strlen($html);
        $hasHtml = (stripos($html, '<html') !== false || stripos($html, '<!DOCTYPE') !== false);
        $hasBody = (stripos($html, '<body') !== false);

        if ($htmlLen < 500 || !$hasHtml || !$hasBody) {
            if ($lockAcquired) {
                self::release_cache_lock($lockKey);
            }
            $processing = false;
            return $html; // Return untranslated incomplete HTML without caching
        }

        // Check if translations are stopped (except for cache invalidation)
        // Exception: search pages should always be translated even if stop_translations is enabled
        $is_search_page = function_exists('is_search') && is_search();
        $stop_translations = (bool) AI_Translate_Core::get_setting('stop_translations_except_cache_invalidation', false);
        if ($stop_translations && !$is_search_page) {
            // Only allow translation if cache exists and is expired (cache invalidation)
            // Block new translations for pages that don't have a cache yet
            $cache_file = AI_Cache::get_file_path($key);
            $cache_exists = is_file($cache_file);
            $cache_is_expired = false;
            if ($cache_exists) {
                $expiry_seconds = AI_Translate_Core::cache_expiration_hours() * HOUR_IN_SECONDS;
                $mtime = @filemtime($cache_file);
                if ($mtime) {
                    $age_seconds = time() - (int) $mtime;
                    $cache_is_expired = $age_seconds > $expiry_seconds;
                }
            }
            
            if (!$cache_exists || !$cache_is_expired) {
                // Cache doesn't exist or is not expired, block translation
                if ($lockAcquired) {
                    self::release_cache_lock($lockKey);
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
            if ($lockAcquired) {
                self::release_cache_lock($lockKey);
            }
            $processing = false;
            return $html;
        } else {
            $merged = AI_DOM::merge($plan, $translations, $lang);
            // Preserve original <body> framing to avoid theme conflicts: replace only inner body
            $html2 = $merged;
            if (preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', (string) $merged, $mNew) &&
                preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', (string) $html, $mOrig)) {
                $newInner = (string) $mNew[1];
                // Use preg_replace_callback instead of preg_replace to prevent $n back-reference
                // interpretation in the replacement string. Content like bash code blocks may contain
                // $1, $2 etc. which preg_replace would interpret as capture group references,
                // injecting </body> mid-content and truncating the page.
                $html2 = (string) preg_replace_callback(
                    '/(<body\b[^>]*>)[\s\S]*?(<\/body>)/i',
                    function ($m) use ($newInner) {
                        return $m[1] . $newInner . $m[2];
                    },
                    (string) $html,
                    1
                );
            }
            
            // Update HTML lang attribute (and dir for RTL target languages) in the
            // preserved original HTML to match target language
            $html2 = self::apply_html_lang_dir($html2, $lang);
        }

        // Ensure lang (and dir for RTL) attributes are correct before SEO injection
        if ($lang !== null && $lang !== '' && !empty($html2)) {
            $locale = self::getLangAttribute($lang);
            $langOk = (bool) preg_match('/<html\b[^>]*\slang=["\']' . preg_quote($locale, '/') . '["\']/i', $html2);
            $dirOk = !AI_Lang::is_rtl($lang) || (bool) preg_match('/<html\b[^>]*\sdir=["\']rtl["\']/i', $html2);
            if (!$langOk || !$dirOk) {
                $html2 = self::apply_html_lang_dir($html2, $lang);
            }
        }

        // Protect script/style tags from DOMDocument corruption in SEO/URL pass.
        // DOMDocument::saveHTML() can encode & as &amp; inside <script>/<style>,
        // breaking JavaScript operators like && and CSS syntax.
        // CRITICAL: Only extract from <body>, never from <head>. Using <div> placeholders
        // in <head> causes DOMDocument to implicitly close <head> and move ALL subsequent
        // head elements (meta, link, style, script) into <body>, breaking page rendering.
        $seoPlaceholders = [];
        $seoPlaceholderCounter = 0;
        $headEndPos = stripos($html2, '</head>');
        if ($headEndPos !== false) {
            $headPart = substr($html2, 0, $headEndPos + 7);
            $bodyPart = substr($html2, $headEndPos + 7);
            $bodyPart = AI_DOM::extractAndReplace($bodyPart, 'script', $seoPlaceholders, $seoPlaceholderCounter);
            $bodyPart = AI_DOM::extractAndReplace($bodyPart, 'style', $seoPlaceholders, $seoPlaceholderCounter);
            $html2Protected = $headPart . $bodyPart;
        } else {
            $html2Protected = $html2;
            $html2Protected = AI_DOM::extractAndReplace($html2Protected, 'script', $seoPlaceholders, $seoPlaceholderCounter);
            $html2Protected = AI_DOM::extractAndReplace($html2Protected, 'style', $seoPlaceholders, $seoPlaceholderCounter);
        }

        // Combined SEO + URL pass: single DOM parse instead of two separate ones
        $doc = new \DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $htmlToLoad = AI_DOM::ensureUtf8($html2Protected);
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
        // Preserve original body attributes/classes (Kadence relies on these for layout sizing).
        if (preg_match('/<body\b[^>]*>/i', (string) $html2, $origBodyOpen)) {
            $html3 = (string) preg_replace('/<body\b[^>]*>/i', (string) $origBodyOpen[0], (string) $html3, 1);
        }

        // Restore script/style tags from placeholders
        if (!empty($seoPlaceholders)) {
            foreach ($seoPlaceholders as $placeholderId => $original_tag) {
                $html3 = AI_DOM::restorePlaceholder($html3, $placeholderId, $original_tag);
            }
        }

        // Translate Twitter Card and JSON-LD text that Jetpack/plugins inject via wp_head.
        // These run as regex on the final HTML because DOMDocument placeholder divs break
        // the <head> structure, causing these meta/script tags to be inaccessible in the DOM pass.
        $html3 = AI_SEO::translateTwitterCards($html3, $lang);
        $html3 = AI_SEO::translateJsonLd($html3, $lang);

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
        $html3HasClosingTags = (stripos($html3, '</body>') !== false && stripos($html3, '</html>') !== false);

        if ($html3Len < 500 || !$html3HasHtml || !$html3HasBody || !$html3HasClosingTags) {
            if ($lockAcquired) {
                self::release_cache_lock($lockKey);
            }
            $processing = false;
            return $this->post_process_cached_content($html3); // Return output but don't cache it
        }

        // Only cache if not bypassed, no dynamic query parameters, and content is cacheable
        // Dynamic query parameters indicate pages that should be translated but not cached
        // (e.g., WooCommerce add-to-cart, form submissions, AJAX actions)
        // Additional check: only cache if route corresponds to cacheable content (not search pages, etc.)
        $isCacheable = $this->route_has_valid_content($route);
        
        $contentMatchesLang = $this->content_matches_target_lang($html3, $lang);
        if (!$bypassUserCache && !$hasDynamicQueryParams && !$noCachePage && !$isNeverCachedRoute && $isCacheable) {
            $retryKey = 'ai_tr_retry_' . md5($key);
            if ($contentMatchesLang) {
                AI_Cache::set($key, $html3);
                delete_transient($retryKey);
            } else {
                $retries = (int) get_transient($retryKey);
                $retries++;
                set_transient($retryKey, $retries, 14 * DAY_IN_SECONDS);
                if ($retries >= 3) {
                    AI_Cache::set($key, $html3);
                }
            }
        }
        
        // Release lock after successful cache generation
        if ($lockAcquired) {
            self::release_cache_lock($lockKey);
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
                $cacheKey = ai_translate_attr_cache_key($lang, $normalized);
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
            // WooCommerce catalog sorting/filtering (classic widgets/themes use
            // query params; the cache key excludes query params, so these views
            // must be translated per-request instead of served from cache)
            'orderby',
            'min_price',
            'max_price',
            'rating_filter',
            'product-page',
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
            // Comment moderation: WordPress redirects to permalink?unapproved=ID&moderation-hash=HASH
            // after a comment that requires moderation. The "awaiting moderation" notice for the
            // poster is rendered server-side based on these params, so the response must not be
            // served from cache (and must not be cached itself).
            'unapproved',
            'moderation-hash',
        );
        
        // Check if any dynamic parameter is present
        foreach ($dynamic_params as $param) {
            if (isset($_GET[$param])) {
                return true;
            }
        }

        // WooCommerce layered-nav attribute filters use per-attribute parameter
        // names (filter_color, query_type_color, ...), so match by prefix.
        foreach (array_keys($_GET) as $param_name) {
            if (strpos((string) $param_name, 'filter_') === 0 || strpos((string) $param_name, 'query_type_') === 0) {
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
     * Set the <html> lang attribute to the target language and, for RTL target
     * languages, dir="rtl" plus class "rtl" on <html> and <body>.
     *
     * AI_Lang::sync_wp_text_direction() makes is_rtl() true during render so themes
     * can load rtl.css; this helper still patches cached HTML that was generated
     * before that sync (missing dir/class). Idempotent and safe to re-apply.
     *
     * @param string      $html Full page HTML.
     * @param string|null $lang Target language code.
     * @return string
     */
    public static function apply_html_lang_dir($html, $lang)
    {
        if ($lang === null || $lang === '') {
            return $html;
        }
        $locale = self::getLangAttribute($lang);
        // Replace existing lang attribute, or add it to the <html> tag when absent.
        $html = preg_replace('/(<html\b[^>]*\s)lang=["\'][^"\']*["\']/i', '$1lang="' . esc_attr($locale) . '"', $html, 1);
        if (!preg_match('/<html\b[^>]*\slang=/i', $html)) {
            $html = preg_replace('/(<html\b)([^>]*)>/i', '$1$2 lang="' . esc_attr($locale) . '">', $html, 1);
        }
        if (AI_Lang::is_rtl($lang)) {
            // Replace existing dir attribute, or add it to the <html> tag when absent.
            $html = preg_replace('/(<html\b[^>]*\s)dir=["\'][^"\']*["\']/i', '$1dir="rtl"', $html, 1);
            if (!preg_match('/<html\b[^>]*\sdir=/i', $html)) {
                $html = preg_replace('/(<html\b)([^>]*)>/i', '$1$2 dir="rtl">', $html, 1);
            }
            $html = self::ensure_rtl_class_on_tag($html, 'html');
            $html = self::ensure_rtl_class_on_tag($html, 'body');
            // Page builders often bake style="text-align: left" into content.
            // dir=rtl alone does not override inline styles; flip left → right only
            // (idempotent on cached re-serve via post_process_cached_content).
            $html = self::flip_inline_text_align_left_to_right($html);
            // Pages cached while WP_Styles still thought LTR link to style.css
            // (body{direction:ltr}). Swap to existing *-rtl.css files on serve.
            $html = self::rewrite_stylesheets_to_rtl($html);
        }
        return $html;
    }

    /**
     * Rewrite stylesheet hrefs to their -rtl variants when those files exist.
     *
     * Mirrors WordPress wp_style_add_data( ..., 'rtl', 'replace' ) for HTML that
     * was rendered/cached before WP_Styles::$text_direction was synced.
     * Idempotent: already-rtl hrefs and missing -rtl files are left unchanged.
     *
     * @param string $html Full page HTML.
     * @return string
     */
    private static function rewrite_stylesheets_to_rtl($html)
    {
        if ($html === '' || stripos($html, '.css') === false) {
            return $html;
        }

        $contentUrl = function_exists('content_url') ? content_url('/') : '';
        $contentDir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '';
        if ($contentUrl === '' || $contentDir === '') {
            return $html;
        }

        return (string) preg_replace_callback(
            '/(<link\b[^>]*\bhref=(["\']))([^"\']+\.css[^"\']*)(\2[^>]*>)/i',
            static function ($m) use ($contentUrl, $contentDir) {
                $href = $m[3];
                $pathPart = (string) (wp_parse_url($href, PHP_URL_PATH) ?? '');
                if ($pathPart === '' || preg_match('/-rtl(\.min)?\.css$/i', $pathPart)) {
                    return $m[0];
                }

                $rtlPathPart = (string) preg_replace('/(\.min)?\.css$/i', '-rtl$1.css', $pathPart);
                if ($rtlPathPart === '' || $rtlPathPart === $pathPart) {
                    return $m[0];
                }

                $marker = '/wp-content/';
                $pos = stripos($rtlPathPart, $marker);
                if ($pos === false) {
                    return $m[0];
                }
                $rel = substr($rtlPathPart, $pos + strlen($marker));
                $fsPath = trailingslashit($contentDir) . ltrim(str_replace('\\', '/', $rel), '/');
                if (!is_readable($fsPath)) {
                    return $m[0];
                }

                $newHref = str_replace($pathPart, $rtlPathPart, $href);
                return $m[1] . $newHref . $m[4];
            },
            $html
        );
    }

    /**
     * Rewrite inline style="text-align: left" to right for RTL pages.
     *
     * Only left → right (never right → left) so repeated application on cache
     * hits stays idempotent.
     *
     * @param string $html Full page HTML.
     * @return string
     */
    private static function flip_inline_text_align_left_to_right($html)
    {
        if ($html === '' || stripos($html, 'text-align') === false) {
            return $html;
        }

        return (string) preg_replace(
            '/(style\s*=\s*["\'][^"\']*?\btext-align\s*:\s*)left\b/i',
            '$1right',
            $html
        );
    }

    /**
     * Ensure a tag's class attribute contains "rtl" (add class attr if missing).
     *
     * @param string $html Full page HTML.
     * @param string $tag  Tag name (html|body).
     * @return string
     */
    private static function ensure_rtl_class_on_tag($html, $tag)
    {
        $tag = strtolower((string) $tag);
        if ($tag === '' || !preg_match('/<(?:' . preg_quote($tag, '/') . ')\b/i', $html)) {
            return $html;
        }

        if (preg_match('/(<' . preg_quote($tag, '/') . '\b[^>]*\sclass=["\'])([^"\']*)(["\'])/i', $html, $m)) {
            if (preg_match('/(?:^|\s)rtl(?:\s|$)/', $m[2])) {
                return $html;
            }
            return preg_replace(
                '/(<' . preg_quote($tag, '/') . '\b[^>]*\sclass=["\'])([^"\']*)(["\'])/i',
                '$1rtl $2$3',
                $html,
                1
            );
        }

        return preg_replace(
            '/(<' . preg_quote($tag, '/') . '\b)([^>]*)>/i',
            '$1$2 class="rtl">',
            $html,
            1
        );
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
            $req = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
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
            $query_string = isset($_SERVER['QUERY_STRING']) ? sanitize_text_field(wp_unslash((string) $_SERVER['QUERY_STRING'])) : '';
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
        
        // For 404 pages: use a single consistent route_id so all 404s share one cache entry per language
        if (function_exists('is_404') && is_404()) {
            return 'path:' . md5('/404');
        }

        // Paginated views (e.g. the shop page on /page/2): use a path-based route so
        // every page number gets its own cache entry. A 'post:' route would collide
        // with page 1 (same key) and corrupt the cache-meta row for the post, which
        // tracks exactly one file per (post, language) for invalidation/warming.
        if (function_exists('is_paged') && is_paged()) {
            $req = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
            if (strpos($req, '%25') !== false) {
                $req = urldecode($req);
            }
            $req_path = (string) wp_parse_url($req, PHP_URL_PATH);
            if ($req_path === '') {
                $req_path = '/';
            }
            $req_path = preg_replace('#/+#', '/', $req_path);
            // Strip language prefix so the route stays stable (language is part of the cache key)
            $req_path = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $req_path);
            if ($req_path === '') {
                $req_path = '/';
            }
            return 'path:' . md5(untrailingslashit($req_path) . '/');
        }

        // Taxonomy archives: always use a path-based route. Term slugs are not in the
        // slug map and can collide with post slugs; resolving them via the post-resolution
        // heuristics below would cache the archive under a post route (cache collision).
        if ((function_exists('is_category') && is_category()) ||
            (function_exists('is_tag') && is_tag()) ||
            (function_exists('is_tax') && is_tax())) {
            $req = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
            if (strpos($req, '%25') !== false) {
                $req = urldecode($req);
            }
            $req_path = (string) wp_parse_url($req, PHP_URL_PATH);
            if ($req_path === '') {
                $req_path = '/';
            }
            $req_path = preg_replace('#/+#', '/', $req_path);
            // Strip language prefix so all languages share one route (language is part of the cache key)
            $req_path = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $req_path);
            if ($req_path === '') {
                $req_path = '/';
            }
            return 'path:' . md5(untrailingslashit($req_path) . '/');
        }

        // CRITICAL: For warm cache requests and translated URLs, resolve the post ID from the URL FIRST
        // This is needed because WordPress query functions (is_singular, get_queried_object_id) 
        // may not work correctly during warm cache internal requests
        $req_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
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
            $paged = get_query_var('paged', 0);
            if ($front_page_id > 0) {
                // Static front page: use post ID
                return 'post:' . $front_page_id;
            } else {
                // Posts listing homepage: include page number to differentiate paginated pages.
                // is_front_page() returns true for ALL paginated blog pages when show_on_front='posts',
                // so without paged in the key, /page/2 would serve the cached /page/1 content.
                $path_key = ($paged > 1) ? '/page/' . $paged : '/';
                return 'path:' . md5($path_key);
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
        $req = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
        // URL decode only if double-encoded (contains %25)
        if (strpos($req, '%25') !== false) {
            $req = urldecode($req);
        }
        if ($req !== '') {
            // Normalize: remove query string and fragments for path lookup
            $path = (string) wp_parse_url($req, PHP_URL_PATH);
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
        $req = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
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
        $req_path = (string) wp_parse_url($req, PHP_URL_PATH);
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
     * PHASE 1: Basic environment checks.
     * Returns true when the request originates from wp-admin and should not be processed.
     *
     * @return bool
     */
    private function should_skip_basic_checks()
    {
        // Skip admin pages
        if (is_admin()) {
            return true;
        }

        // Skip page-builder editor/preview iframes (Elementor, Divi, Beaver, ...) and
        // WordPress post preview. These are rendered for a logged-in admin and depend on
        // builder-specific scripts in the original HTML; translating/rewriting the output
        // breaks the builder's editor session.
        if (function_exists('ai_translate_is_editor_context') && ai_translate_is_editor_context()) {
            return true;
        }

        return false;
    }

    /**
     * PHASE 2: Content type validation.
     * Returns true for 404 pages on the default language, redirect responses, attachments, and archives.
     *
     * @param string $html Raw output buffer content used for fallback HTML-based detection.
     * @return bool
     */
    private function should_skip_content_type($html)
    {
        // Skip 404 pages - but allow translation when a non-default language is active
        // Users encountering a 404 in another language should see translated error messages
        if ($this->is_404_page($html)) {
            $lang = AI_Lang::current();
            if ($lang === null || !AI_Lang::should_translate($lang)) {
                return true;
            }
            // Non-default language active: allow 404 through for translation
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
     * PHASE 3: Page structure validation.
     * Returns true when the current page is not a singular post/page, search result, front page, 404,
     * or post-type archive (CPT listing pages, WooCommerce shop).
     *
     * @return bool
     */
    private function should_skip_page_structure()
    {
        // Only translate singular posts/pages, with exceptions for search/homepage/404/CPT-archives/taxonomy-archives
        if (function_exists('is_singular') && !is_singular()) {
            $is_search           = function_exists('is_search') && is_search();
            $is_front_page       = function_exists('is_front_page') && is_front_page();
            $is_404              = function_exists('is_404') && is_404();
            $is_post_type_archive = function_exists('is_post_type_archive') && is_post_type_archive();
            $is_tax_archive      = (function_exists('is_category') && is_category()) ||
                                   (function_exists('is_tag') && is_tag()) ||
                                   (function_exists('is_tax') && is_tax());
            if (!$is_search && !$is_front_page && !$is_404 && !$is_post_type_archive && !$is_tax_archive) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHASE 4: File type checks.
     * Returns true for XML files, sitemap URLs, and responses whose body starts with an XML declaration.
     *
     * @param string $html Raw output buffer content.
     * @return bool
     */
    private function should_skip_file_types($html)
    {
        // Skip XML files and sitemaps
        $req_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
        // URL decode only if double-encoded (contains %25)
        if (strpos($req_uri, '%25') !== false) {
            $req_uri = urldecode($req_uri);
        }
        $reqPath = (string) wp_parse_url($req_uri, PHP_URL_PATH);
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
     * PHASE 5: Language and user validation.
     * Returns true when no translatable language is active or when the request is for the default language.
     *
     * @return bool
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
     * Check if the current page is a 404 error page.
     * Uses wp_query first, then falls back to HTML content heuristics.
     *
     * @param string $html Raw output buffer content.
     * @return bool
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
     * Atomically try to acquire a short-lived page-generation lock.
     * Object cache: wp_cache_add. Otherwise: add_option (unique insert).
     *
     * @param string $lockKey
     * @return bool True if this request acquired the lock
     */
    private static function try_acquire_cache_lock($lockKey)
    {
        $ttl = 120;
        if (wp_using_ext_object_cache()) {
            return (bool) wp_cache_add($lockKey, time(), 'ai_translate_locks', $ttl);
        }

        $option = '_ai_tr_lock_' . md5((string) $lockKey);
        $existing = get_option($option);
        if ($existing !== false && is_numeric($existing) && (time() - (int) $existing) > $ttl) {
            delete_option($option);
        }

        if (!add_option($option, time(), '', 'no')) {
            return false;
        }
        set_transient($lockKey, time(), $ttl);
        return true;
    }

    /**
     * Release a page-generation lock acquired via try_acquire_cache_lock().
     *
     * @param string $lockKey
     * @return void
     */
    private static function release_cache_lock($lockKey)
    {
        delete_transient($lockKey);
        if (wp_using_ext_object_cache()) {
            wp_cache_delete($lockKey, 'ai_translate_locks');
        } else {
            delete_option('_ai_tr_lock_' . md5((string) $lockKey));
        }
    }

    /**
     * Check if the current page is a media attachment page.
     *
     * @return bool
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
     * Check if the current page is an archive type that should NOT be translated.
     * Post-type archives (CPT listings, WooCommerce shop) and taxonomy archives
     * (category, tag, custom taxonomies like product_cat) ARE translated: they are
     * SEO-relevant listing pages reachable from translated navigation.
     * Author and date archives are skipped (near-infinite URL space, little value).
     *
     * @return bool
     */
    private function is_archive_page()
    {
        // Post-type archives (CPT listing pages, WooCommerce shop) must be translated.
        if (function_exists('is_post_type_archive') && is_post_type_archive()) {
            return false;
        }

        // Taxonomy archives (category, tag, custom tax) must be translated.
        if ((function_exists('is_category') && is_category()) ||
            (function_exists('is_tag') && is_tag()) ||
            (function_exists('is_tax') && is_tax())) {
            return false;
        }

        // Remaining archive types (author, date) are skipped.
        if (function_exists('is_archive') && is_archive()) {
            return true;
        }

        // Belt-and-suspenders for themes that bypass is_archive
        $archive_functions = ['is_author', 'is_date'];
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
                    // Password-protected posts are visitor/cookie-dependent (post_password_required()).
                    // The shared translation cache is not per-visitor, so whichever HTML happens to be
                    // rendered first (the password form, or the real content for a visitor who already
                    // holds the password cookie) would get baked in and served to every subsequent
                    // visitor of that language, regardless of their own password state. Skip entirely.
                    if (!empty($post->post_password)) {
                        return false;
                    }

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
                $search_query = isset($_GET['s']) ? trim(sanitize_text_field(wp_unslash((string) $_GET['s']))) : '';
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
                // Only cache if post exists, is published, is not a nav_menu_item, and is not
                // password-protected (see route_should_be_translated() for why: shared cache
                // cannot vary by the requesting visitor's password cookie).
                return $post && $post->post_status === 'publish' && $post->post_type !== 'nav_menu_item'
                    && empty($post->post_password);
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
            // Cache translated 404 pages - always same content, avoids repeated API calls
            if (function_exists('is_404') && is_404()) {
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
     * Lightweight check whether HTML body content matches the expected target language.
     * Detects Latin ↔ Non-Latin mismatches only (e.g. NL body text cached for JA target).
     * Returns true when content appears correct or when detection is not possible (Latin↔Latin).
     *
     * @param string $html  Full page HTML
     * @param string|null $targetLang  Target language code
     * @return bool True if content looks correct for the target language
     */
    private function content_matches_target_lang($html, $targetLang)
    {
        if ($targetLang === null) {
            return true;
        }
        $defaultLang = AI_Lang::default();
        if ($defaultLang === null || strtolower($targetLang) === strtolower($defaultLang)) {
            return true;
        }

        $targetIsNonLatin = AI_Lang::is_non_latin($targetLang);
        $sourceIsNonLatin = AI_Lang::is_non_latin($defaultLang);

        if ($targetIsNonLatin === $sourceIsNonLatin) {
            return true;
        }

        if (!preg_match('/<body[^>]*>(.*)<\/body>/si', $html, $m)) {
            return true;
        }
        $bodyHtml = $m[1];
        $bodyHtml = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $bodyHtml);
        $bodyHtml = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $bodyHtml);
        $bodyText = html_entity_decode(trim(wp_strip_all_tags($bodyHtml)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (mb_strlen($bodyText) < 100) {
            return true;
        }

        $latinChars = preg_match_all('/[a-zA-Z]/', $bodyText);
        $totalChars = max(1, mb_strlen($bodyText));
        $latinRatio = $latinChars / $totalChars;

        if ($targetIsNonLatin && $latinRatio > 0.4) {
            return false;
        }
        if (!$targetIsNonLatin && $sourceIsNonLatin && $latinRatio < 0.3) {
            return false;
        }

        return true;
    }
}