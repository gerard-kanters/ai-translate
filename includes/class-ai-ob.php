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
        // Allow cache bypass via nocache parameter for testing
        $nocache = isset($_GET['nocache']) || isset($_GET['no_cache']);
        // Note: content_version removed from cache key for stability
        // route_id is already unique per page, making content_version unnecessary
        // Cache expiry (14+ days) ensures automatic refresh
        $key = AI_Cache::key($lang, $route, '');
        if (!$bypassUserCache && !$nocache) {
            $cached = AI_Cache::get($key);
            \ai_translate_dbg('Page cache lookup', [
                'lang' => $lang,
                'route' => $route,
                'key_preview' => substr($key, 0, 50),
                'cache_found' => $cached !== false
            ]);
            if ($cached !== false) {
                \ai_translate_dbg('Page cache HIT', [
                    'lang' => $lang,
                    'route' => $route,
                    'key_preview' => substr($key, 0, 50)
                ]);
                // Validate cached content for substantial untranslated text (> 4 words)
                $defaultLang = AI_Lang::default();
                if ($defaultLang !== null) {
                    $untranslatedCheck = $this->detect_untranslated_content($cached, $lang, $defaultLang);
                    \ai_translate_dbg('Page cache validation', [
                        'lang' => $lang,
                        'route' => $route,
                        'has_untranslated' => $untranslatedCheck['has_untranslated'],
                        'word_count' => $untranslatedCheck['word_count'] ?? 0,
                        'reason' => $untranslatedCheck['reason'] ?? ''
                    ]);
                    // Only invalidate if there are actually untranslated words (> 4)
                    // If word_count is 0 or missing, the check is likely a false positive
                    $wordCount = isset($untranslatedCheck['word_count']) ? (int) $untranslatedCheck['word_count'] : 0;
                    if ($untranslatedCheck['has_untranslated'] && $wordCount > 4) {
                        // Check retry counter to avoid infinite loops
                        $retryKey = 'ai_translate_retry_' . md5($key);
                        $retryCount = (int) get_transient($retryKey);
                        
                        if ($retryCount < 1) {
                            // Mark as retry and invalidate cache to force retranslation
                            set_transient($retryKey, $retryCount + 1, HOUR_IN_SECONDS);
                            \ai_translate_dbg('Page cache invalidated due to untranslated content, retrying translation', [
                                'lang' => $lang,
                                'route' => $route,
                                'retry_count' => $retryCount + 1,
                                'word_count' => $wordCount
                            ]);
                            // Fall through to translation below (don't return cached)
                        } else {
                            // Max retries reached, serve cached version anyway
                            $processing = false;
                            return $cached;
                        }
                    } else {
                        // Cache is good, return it
                        \ai_translate_dbg('Page cache validated OK, serving cached page', [
                            'lang' => $lang,
                            'route' => $route
                        ]);
                        $processing = false;
                        return $cached;
                    }
                } else {
                    // No default language configured, return cache as-is
                    \ai_translate_dbg('Page cache served (no default lang check)', [
                        'lang' => $lang,
                        'route' => $route
                    ]);
                    $processing = false;
                    return $cached;
                }
            } else {
                \ai_translate_dbg('Page cache MISS', [
                    'lang' => $lang,
                    'route' => $route,
                    'key_preview' => substr($key, 0, 50),
                    'reason' => 'not_found'
                ]);
            }
        } else {
            \ai_translate_dbg('Page cache BYPASSED', [
                'lang' => $lang,
                'route' => $route,
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
                    \ai_translate_dbg('Page cache acquired while waiting for lock', [
                        'lang' => $lang,
                        'route' => $route,
                        'wait_time' => time() - $lockStart . 's'
                    ]);
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

        $plan = AI_DOM::plan($html);
        \ai_translate_dbg('Starting page translation', [
            'lang' => $lang,
            'route' => $route,
            'num_segments_in_plan' => isset($plan['segments']) ? count($plan['segments']) : 0
        ]);
        $res = AI_Batch::translate_plan($plan, AI_Lang::default(), $lang, $this->site_context());
        $translations = is_array(($res['segments'] ?? null)) ? $res['segments'] : [];
        \ai_translate_dbg('Page translation completed', [
            'lang' => $lang,
            'route' => $route,
            'num_translations' => count($translations)
        ]);
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
            \ai_translate_dbg('Page cache SAVED', [
                'lang' => $lang,
                'route' => $route,
                'html_size' => strlen($html3) . ' bytes',
                'key_preview' => substr($key, 0, 50)
            ]);
        } else {
            \ai_translate_dbg('Page cache NOT saved (bypassed)', [
                'lang' => $lang,
                'route' => $route,
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
        $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
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
        return [
            'site_name' => (string) get_bloginfo('name'),
            'default_language' => AI_Lang::default(),
            'website_context' => isset($settings['website_context']) ? (string)$settings['website_context'] : '',
            'homepage_meta_description' => isset($settings['homepage_meta_description']) ? (string)$settings['homepage_meta_description'] : '',
        ];
    }

    /**
     * Detect if translated HTML contains substantial untranslated text (> 4 words).
     * Only detects character-set mismatches: Latin ↔ Non-Latin.
     * Does NOT detect Latin → Latin (e.g. EN→DE, NL→FR) as this requires language-specific analysis.
     *
     * @param string $html Translated HTML
     * @param string $targetLang Target language code
     * @param string $sourceLang Source language code
     * @return array{has_untranslated:bool,word_count:int,reason:string}
     */
    private function detect_untranslated_content($html, $targetLang, $sourceLang)
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
        
        $bodyText = trim((string) $bodyNodes->item(0)->textContent);
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
        
        // Use more lenient threshold for non-Latin target languages (0.60 instead of 0.40)
        // This allows natural Latin content like URLs, brand names, and boilerplate
        // Many websites have significant Latin content even in non-Latin languages
        $expectedLatinRatio = $targetIsNonLatin ? 0.60 : 0.50;
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
            if ($wordCount > 4) {
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
}