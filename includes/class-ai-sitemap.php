<?php

namespace AITranslate;

/**
 * Sitemap integration for AI Translate.
 * Supports WordPress native sitemaps (WP 5.5+) and Google XML Sitemaps plugin.
 */
final class AI_Sitemap
{
    /**
     * Initialize sitemap integration.
     */
    public static function init()
    {
        // WordPress native sitemaps (WP 5.5+)
        if (function_exists('wp_sitemaps_get_server')) {
            add_action('init', [__CLASS__, 'register_providers']);
        }

        // Google XML Sitemaps plugin: hook only after plugins loaded and when that plugin is active
        add_action('plugins_loaded', [__CLASS__, 'maybe_register_google_sitemap_hooks'], 20);
    }

    /**
     * Register hooks for Google XML Sitemaps plugin only when that plugin is active.
     */
    public static function maybe_register_google_sitemap_hooks()
    {
        if (!class_exists('GoogleSitemapGeneratorStatus')) {
            return;
        }
        add_action('sm_build_index', [__CLASS__, 'gsm_build_index'], 10, 1);
        add_action('sm_build_content', [__CLASS__, 'gsm_build_content'], 10, 3);
    }

    /**
     * Register WP native sitemap providers.
     */
    public static function register_providers()
    {
        $provider = new AI_Sitemap_Provider_Languages();
        wp_register_sitemap_provider('languages', $provider);
    }

    /**
     * Get language homepage entries for sitemap use.
     *
     * @return array[] Each entry has 'loc' (string) and optional 'lastmod' (int unix timestamp).
     */
    public static function get_language_entries()
    {
        $default = AI_Lang::default();
        $enabled = AI_Lang::enabled();
        $detectable = AI_Lang::detectable();
        $langs = array_values(array_unique(array_merge($enabled, $detectable)));

        $entries = [];
        $home = rtrim(home_url('/'), '/');

        // Determine lastmod from homepage
        $lastmod_ts = 0;
        $page_on_front = (int) get_option('page_on_front');
        if ($page_on_front > 0) {
            $mod = get_post_field('post_modified_gmt', $page_on_front);
            if ($mod) {
                $lastmod_ts = strtotime($mod);
            }
        }

        foreach ($langs as $lang) {
            $lang = strtolower((string) $lang);

            // Skip default language (already in standard sitemap)
            if ($lang === strtolower((string) $default)) {
                continue;
            }

            $entries[] = [
                'loc'     => $home . '/' . $lang . '/',
                'lastmod' => $lastmod_ts,
            ];
        }

        return $entries;
    }

    // ──────────────────────────────────────────────
    //  Google XML Sitemaps plugin integration
    // ──────────────────────────────────────────────

    /**
     * Hook: sm_build_index – add our sitemap to the Google XML Sitemaps index.
     *
     * @param \GoogleSitemapGenerator $gsg
     */
    public static function gsm_build_index($gsg)
    {
        $entries = self::get_language_entries();
        if (empty($entries)) {
            return;
        }

        $lastmod = 0;
        foreach ($entries as $e) {
            if (!empty($e['lastmod']) && $e['lastmod'] > $lastmod) {
                $lastmod = $e['lastmod'];
            }
        }

        $gsg->add_sitemap('languages-sitemap', null, $lastmod);
    }

    /**
     * Hook: sm_build_content – output language homepage URLs when our type is requested.
     * Google XML Sitemaps routes languages-sitemap.xml as type='pt', params='languages-p...'
     *
     * @param \GoogleSitemapGenerator $gsg
     * @param string $type
     * @param string $params
     */
    public static function gsm_build_content($gsg, $type, $params)
    {
        // The plugin routes unknown sitemap types as pt-{name}-p{page}-...
        $is_ours = ($type === 'languages')
            || ($type === 'pt' && is_string($params) && strpos($params, 'languages') === 0);

        if (!$is_ours) {
            return;
        }

        $entries = self::get_language_entries();
        foreach ($entries as $e) {
            $gsg->add_url(
                $e['loc'],
                $e['lastmod'],
                'weekly',
                0.5
            );
        }
    }
}

/**
 * WP native sitemap provider for language homepages.
 */
class AI_Sitemap_Provider_Languages extends \WP_Sitemaps_Provider
{
    public function __construct()
    {
        $this->name        = 'languages';
        $this->object_type = 'ai-translate';
    }

    /**
     * @param int    $page_num
     * @param string $object_subtype
     * @return array[]
     */
    public function get_url_list($page_num, $object_subtype = '')
    {
        if ($page_num > 1) {
            return [];
        }

        $urls = [];
        foreach (AI_Sitemap::get_language_entries() as $e) {
            $entry = ['loc' => $e['loc']];
            if (!empty($e['lastmod'])) {
                $entry['lastmod'] = wp_date('c', $e['lastmod']);
            }
            $urls[] = $entry;
        }
        return $urls;
    }

    /**
     * @param string $object_subtype
     * @return int
     */
    public function get_max_num_pages($object_subtype = '')
    {
        return 1;
    }
}
