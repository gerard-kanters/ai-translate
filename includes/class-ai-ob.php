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
        if (is_admin()) {
            return $html;
        }
        $lang = AI_Lang::current();
        if ($lang === null || !AI_Lang::should_translate($lang)) {
            return $html; // No translation when at default language or language not determined.
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
        $key = AI_Cache::key($lang, $route, $this->content_version());
        $cached = AI_Cache::get($key);
        if ($cached !== false) {
            return $cached;
        }

        $plan = AI_DOM::plan($html);
        $res = AI_Batch::translate_plan($plan, AI_Lang::default(), $lang, $this->site_context());
        $translations = is_array(($res['segments'] ?? null)) ? $res['segments'] : [];
        // If provider fails, serve original (no blank pages)
        $html2 = empty($translations) ? $html : AI_DOM::merge($plan, $translations);

        $html3 = AI_SEO::inject($html2, $lang);
        $html3 = AI_URL::rewrite($html3, $lang);

        AI_Cache::set($key, $html3);
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


