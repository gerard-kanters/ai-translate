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
        $lang = AI_Lang::current();
        if ($lang === null) {
            $processing = false;
            return $html;
        }
        
        // For default language: only inject hreflang tags (no translation needed)
        $needsTranslation = AI_Lang::should_translate($lang);
        if (!$needsTranslation) {
            $htmlWithHreflang = AI_SEO::inject_hreflang_only($html, $lang);
            $processing = false;
            return $htmlWithHreflang;
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
        // Debug: help analyze caching and keys on paginated/archive views
        if (function_exists('ai_translate_dbg')) {
            ai_translate_dbg('ob_callback', [
                'route' => $route,
                'lang' => $lang,
                'uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
            ]);
        }
        // Allow cache bypass via nocache parameter for testing
        $nocache = isset($_GET['nocache']) || isset($_GET['no_cache']);
        $key = AI_Cache::key($lang, $route, $this->content_version());
        if (!$bypassUserCache && !$nocache) {
            $cached = AI_Cache::get($key);
            if ($cached !== false) {
                $processing = false;
                return $cached;
            }
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
                    if (function_exists('ai_translate_dbg')) {
                        ai_translate_dbg('ob_lock_timeout', [
                            'key' => $key,
                            'waited' => (time() - $lockStart),
                        ]);
                    }
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
            if (function_exists('ai_translate_dbg')) {
                ai_translate_dbg('ob_callback_incomplete_html', [
                    'len' => $htmlLen,
                    'has_html' => $hasHtml,
                    'has_body' => $hasBody,
                    'uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                ]);
            }
            if ($lockAcquired) {
                delete_transient($lockKey);
            }
            $processing = false;
            return $html; // Return untranslated incomplete HTML without caching
        }

        $plan = AI_DOM::plan($html);
        $res = AI_Batch::translate_plan($plan, AI_Lang::default(), $lang, $this->site_context());
        $translations = is_array(($res['segments'] ?? null)) ? $res['segments'] : [];
        if (empty($translations)) {
            $processing = false;
            $html2 = $html;
        } else {
            $merged = AI_DOM::merge($plan, $translations);
            // Preserve original <body> framing to avoid theme conflicts: replace only inner body
            $html2 = $merged;
            if (preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', (string) $merged, $mNew) &&
                preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', (string) $html, $mOrig)) {
                $newInner = (string) $mNew[1];
                $html2 = (string) preg_replace('/(<body\b[^>]*>)[\s\S]*?(<\/body>)/i', '$1' . $newInner . '$2', (string) $html, 1);
            }
        }

        $html3 = AI_SEO::inject($html2, $lang);
        $html3 = AI_URL::rewrite($html3, $lang);

        // Validate final output before caching
        $html3Len = strlen($html3);
        $html3HasHtml = (stripos($html3, '<html') !== false || stripos($html3, '<!DOCTYPE') !== false);
        $html3HasBody = (stripos($html3, '<body') !== false);
        
        if ($html3Len < 500 || !$html3HasHtml || !$html3HasBody) {
            if (function_exists('ai_translate_dbg')) {
                ai_translate_dbg('ob_callback_incomplete_output', [
                    'len' => $html3Len,
                    'has_html' => $html3HasHtml,
                    'has_body' => $html3HasBody,
                    'uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                ]);
            }
            if ($lockAcquired) {
                delete_transient($lockKey);
            }
            $processing = false;
            return $html3; // Return output but don't cache it
        }

        if (!$bypassUserCache) {
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
}