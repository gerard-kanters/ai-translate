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
        
        if (is_admin()) {
            $processing = false;
            return $html;
        }
        
        // Skip XML files (sitemaps, etc.) - they should not be processed
        $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if (preg_match('/\.xml$/i', $reqPath) || 
            isset($_GET['sitemap']) || 
            isset($_GET['sitemap-index']) ||
            preg_match('/sitemap/i', $reqPath)) {
            $processing = false;
            return $html;
        }
        
        // Additional check: if content starts with XML declaration, skip processing
        $htmlTrimmed = trim($html);
        if (strpos($htmlTrimmed, '<?xml') === 0 || strpos($htmlTrimmed, '<xml') === 0) {
            $processing = false;
            return $html;
        }
        $lang = AI_Lang::current();
        if ($lang === null) {
            $processing = false;
            return $html;
        }
        
        // For default language: inject SEO tags (meta description, hreflang) but no translation
        $needsTranslation = AI_Lang::should_translate($lang);
        if (!$needsTranslation) {
            $htmlWithSEO = AI_SEO::inject($html, $lang);
            $processing = false;
            return $htmlWithSEO;
        }

        // Never serve cached HTML to logged-in users or when admin bar should be visible.
        // This prevents returning a cached anonymous page that lacks the admin toolbar.
        $bypassUserCache = false;
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            $bypassUserCache = true;
        }
        if (function_exists('is_admin_bar_showing') && is_admin_bar_showing()) {
            $bypassUserCache = true;
        }

        $route = $this->current_route_id();
        $url = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        // Allow cache bypass via nocache parameter for testing
        $nocache = isset($_GET['nocache']) || isset($_GET['no_cache']);
        // Note: content_version removed from cache key for stability
        // route_id is already unique per page, making content_version unnecessary
        // Cache expiry (14+ days) ensures automatic refresh
        $key = AI_Cache::key($lang, $route, '');
        if (!$bypassUserCache && !$nocache) {
            $cached = AI_Cache::get($key);
            if ($cached !== false) {
                // Validate cached content for substantial untranslated text (> 4 words)
                $defaultLang = AI_Lang::default();
                if ($defaultLang !== null) {
                    $untranslatedCheck = $this->detect_untranslated_content($cached, $lang, $defaultLang, $url);
                    // Only log validation if there are problems
                    if ($untranslatedCheck['has_untranslated']) {
                        \ai_translate_dbg('Page cache validation', [
                            'lang' => $lang,
                            'route' => $route,
                            'url' => $url,
                            'has_untranslated' => $untranslatedCheck['has_untranslated'],
                            'word_count' => $untranslatedCheck['word_count'] ?? 0,
                            'reason' => $untranslatedCheck['reason'] ?? ''
                        ]);
                    }
                    // Invalidate if there are untranslated words (> 4) OR untranslated UI attributes (> 0)
                    // UI attributes are always invalidated regardless of count to ensure proper translation
                    $wordCount = isset($untranslatedCheck['word_count']) ? (int) $untranslatedCheck['word_count'] : 0;
                    $reason = isset($untranslatedCheck['reason']) ? (string) $untranslatedCheck['reason'] : '';
                    $isUIAttributes = strpos($reason, 'UI attribute') !== false;
                    
                    // Invalidate if: (1) UI attributes are untranslated (any count), or (2) body text has > 4 untranslated words
                    if ($untranslatedCheck['has_untranslated'] && ($isUIAttributes || $wordCount > 4)) {
                        $retryKey = 'ai_translate_retry_' . md5($key);
                        $retryCount = (int) get_transient($retryKey);
                        
                        // For UI attributes: only invalidate if there are many (> 5) untranslated attributes
                        // Small number of missing attributes are likely being translated via batch-strings (JavaScript)
                        // This prevents cache invalidation while batch-strings is already translating them
                        if ($isUIAttributes) {
                            // Only invalidate if there are many untranslated UI attributes (> 5)
                            // Small number (< 5) are likely being translated via batch-strings, so accept cache
                            if ($wordCount > 5) {
                                // UI attributes are now systematically collected, so retry to ensure they get translated
                                // Reset retry counter for UI attributes to allow retranslation
                                delete_transient($retryKey);
                                \ai_translate_dbg('Page cache invalidated due to many untranslated UI attributes, retrying translation', [
                                    'lang' => $lang,
                                    'route' => $route,
                                    'url' => $url,
                                    'word_count' => $wordCount,
                                    'reason' => $reason,
                                    'untranslated_attributes' => $untranslatedCheck['untranslated_attributes'] ?? []
                                ]);
                                // Fall through to translation below (don't return cached)
                            } else {
                                // Small number of missing UI attributes - likely being translated via batch-strings
                                // Accept cache to avoid double translation
                                \ai_translate_dbg('Page cache accepted despite few untranslated UI attributes (likely being translated via batch-strings)', [
                                    'lang' => $lang,
                                    'route' => $route,
                                    'url' => $url,
                                    'word_count' => $wordCount,
                                    'reason' => $reason,
                                    'untranslated_attributes' => $untranslatedCheck['untranslated_attributes'] ?? []
                                ]);
                                $processing = false;
                                return $cached;
                            }
                        } elseif ($retryCount < 1) {
                            // Body text: use retry counter to avoid infinite loops
                            set_transient($retryKey, $retryCount + 1, HOUR_IN_SECONDS);
                            \ai_translate_dbg('Page cache invalidated due to untranslated content, retrying translation', [
                                'lang' => $lang,
                                'route' => $route,
                                'url' => $url,
                                'retry_count' => $retryCount + 1,
                                'word_count' => $wordCount,
                                'reason' => $reason
                            ]);
                            // Fall through to translation below (don't return cached)
                        } else {
                            // Max retries reached for body text, serve cached version anyway
                            \ai_translate_dbg('Page cache served despite untranslated content (max retries reached)', [
                                'lang' => $lang,
                                'route' => $route,
                                'url' => $url,
                                'retry_count' => $retryCount,
                                'word_count' => $wordCount,
                                'reason' => $reason
                            ]);
                            $processing = false;
                            return $cached;
                        }
                    } else {
                        // Cache is good, return it
                        $processing = false;
                        return $cached;
                    }
                } else {
                    // No default language configured, return cache as-is
                    $processing = false;
                    return $cached;
                }
            } else {
                \ai_translate_dbg('Page cache MISS', [
                    'lang' => $lang,
                    'route' => $route,
                    'url' => $url,
                    'key_preview' => substr($key, 0, 50),
                    'reason' => 'not_found'
                ]);
            }
        } else {
            \ai_translate_dbg('Page cache BYPASSED', [
                'lang' => $lang,
                'route' => $route,
                'url' => $url,
                'reason' => $bypassUserCache ? 'logged_in_user' : ($nocache ? 'nocache_param' : 'unknown')
            ]);
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
                    // Lock timeout - proceed anyway to avoid infinite wait
                    break;
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
        $settings = get_option('ai_translate_settings', []);
        $stop_translations = isset($settings['stop_translations_except_cache_invalidation']) ? (bool) $settings['stop_translations_except_cache_invalidation'] : false;
        if ($stop_translations) {
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
                \ai_translate_dbg('Translation blocked: stop_translations enabled', [
                    'lang' => $lang,
                    'route' => $route,
                    'url' => $url,
                    'reason' => $cache_exists ? 'cache_not_expired' : 'cache_not_exists'
                ]);
                $processing = false;
                return $html; // Return untranslated HTML
            }
            // Cache exists and is expired, allow translation (cache invalidation)
            \ai_translate_dbg('Translation allowed: cache expired (cache invalidation)', [
                'lang' => $lang,
                'route' => $route,
                'url' => $url
            ]);
        }

        $plan = AI_DOM::plan($html);
        \ai_translate_dbg('Starting page translation', [
            'lang' => $lang,
            'route' => $route,
            'url' => $url,
            'num_segments_in_plan' => isset($plan['segments']) ? count($plan['segments']) : 0
        ]);
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

        if (!$bypassUserCache) {
            AI_Cache::set($key, $html3);
        } else {
            \ai_translate_dbg('Page cache NOT saved (bypassed)', [
                'lang' => $lang,
                'route' => $route,
                'url' => $url,
                'reason' => 'logged_in_user'
            ]);
        }
        
        // Release lock after successful cache generation
        if ($lockAcquired) {
            delete_transient($lockKey);
        }
        
        $processing = false;
        return $html3;
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
     *
     * @return string
     */
    private function current_route_id()
    {
        if (is_singular()) {
            $post_id = get_queried_object_id();
            if ($post_id) {
                return 'post:' . $post_id;
            }
        }
        // Fallback to request URI (path + query) to ensure unique cache per paginated/archive view
        // IMPORTANT: Remove language code prefix from URI to prevent cache duplication across languages
        // This ensures /ka/service/... and /da/service/... use the same route_id (and cache key includes lang)
        $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        if ($req === '') { $req = '/'; }
        // Remove leading language code (e.g., /ka/ or /da/) from path to normalize route_id
        // The language is already part of the cache key, so we don't need it in route_id
        $req = preg_replace('#^/([a-z]{2})(?:/|$)#i', '/', $req);
        if ($req === '') { $req = '/'; }
        return 'path:' . md5($req);
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
                $untranslatedCount++;
                if (isset($uiStringsWithAttr[$normalized])) {
                    $untranslatedAttributes[] = $uiStringsWithAttr[$normalized];
                }
            }
        }
        
        if ($untranslatedCount > 0) {
            $result['has_untranslated'] = true;
            $result['word_count'] = $untranslatedCount;
            $result['reason'] = sprintf('Found %d untranslated UI attribute(s) (placeholder/title/aria-label/button values)', $untranslatedCount);
            $result['untranslated_attributes'] = $untranslatedAttributes;
            
            // Log details about untranslated attributes
            \ai_translate_dbg('Untranslated UI attributes detected', [
                'url' => $url,
                'lang' => $targetLang,
                'count' => $untranslatedCount,
                'attributes' => $untranslatedAttributes
            ]);
        }
        
        return $result;
    }
}