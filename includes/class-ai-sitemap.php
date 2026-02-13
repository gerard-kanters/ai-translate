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
        // Language homepages (/{lang}/)
        wp_register_sitemap_provider('languages', new AI_Sitemap_Provider_Languages());

        // Translated posts/pages/CPT (/{lang}/{translated-slug}/)
        wp_register_sitemap_provider('ai-translate-posts', new AI_Sitemap_Provider_Translated());
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
    //  Translated post/page/CPT entries
    // ──────────────────────────────────────────────

    /**
     * Get translated post entries for a specific language.
     *
     * Only includes posts that already have a translated slug in the slugs table.
     * Does NOT trigger API calls.
     *
     * @param string $lang   Language code (e.g. 'de', 'it').
     * @param int    $limit  Max entries to return.
     * @param int    $offset Offset for pagination.
     * @return array[] Each entry has 'loc' (string) and optional 'lastmod' (string ISO 8601).
     */
    public static function get_translated_entries($lang, $limit = 2000, $offset = 0)
    {
        global $wpdb;
        $table    = $wpdb->prefix . 'ai_translate_slugs';
        $col_lang = self::slug_lang_column();

        $public_types = get_post_types(['public' => true], 'names');
        $public_types = array_diff($public_types, ['attachment']);
        if (empty($public_types)) {
            $public_types = ['post', 'page'];
        }
        $placeholders = implode(', ', array_fill(0, count($public_types), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.translated_slug, p.post_modified_gmt
             FROM `{$table}` s
             JOIN `{$wpdb->posts}` p ON p.ID = s.post_id
             WHERE s.`{$col_lang}` = %s
               AND p.post_status = 'publish'
               AND p.post_type IN ({$placeholders})
               AND s.translated_slug != ''
             ORDER BY s.post_id ASC
             LIMIT %d OFFSET %d",
            array_merge([$lang], array_values($public_types), [$limit, $offset])
        ), ARRAY_A);

        if (!is_array($rows) || empty($rows)) {
            return [];
        }

        $home = rtrim(home_url('/'), '/');
        $entries = [];
        foreach ($rows as $row) {
            $slug = ltrim($row['translated_slug'], '/');
            if ($slug === '') {
                continue;
            }
            $entry = [
                'loc' => $home . '/' . $lang . '/' . $slug . '/',
            ];
            if (!empty($row['post_modified_gmt']) && $row['post_modified_gmt'] !== '0000-00-00 00:00:00') {
                $entry['lastmod'] = strtotime($row['post_modified_gmt']);
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Count translated post entries for a specific language.
     *
     * @param string $lang Language code.
     * @return int
     */
    public static function count_translated_entries($lang)
    {
        global $wpdb;
        $table    = $wpdb->prefix . 'ai_translate_slugs';
        $col_lang = self::slug_lang_column();

        $public_types = get_post_types(['public' => true], 'names');
        $public_types = array_diff($public_types, ['attachment']);
        if (empty($public_types)) {
            $public_types = ['post', 'page'];
        }
        $placeholders = implode(', ', array_fill(0, count($public_types), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM `{$table}` s
             JOIN `{$wpdb->posts}` p ON p.ID = s.post_id
             WHERE s.`{$col_lang}` = %s
               AND p.post_status = 'publish'
               AND p.post_type IN ({$placeholders})
               AND s.translated_slug != ''",
            array_merge([$lang], array_values($public_types))
        ));
    }

    /**
     * Get non-default languages that have at least one translated entry.
     *
     * @return string[]
     */
    public static function get_sitemap_languages()
    {
        $default    = AI_Lang::default();
        $enabled    = AI_Lang::enabled();
        $detectable = AI_Lang::detectable();
        $langs      = array_values(array_unique(array_merge($enabled, $detectable)));

        $result = [];
        foreach ($langs as $lang) {
            $lang = strtolower((string) $lang);
            if ($default !== null && $lang === strtolower((string) $default)) {
                continue;
            }
            $result[] = $lang;
        }
        return $result;
    }

    /**
     * Detect slug table language column name.
     *
     * Uses the transient set by AI_Slugs::detect_schema() to avoid extra SHOW COLUMNS queries.
     *
     * @return string Column name ('lang' or 'language_code').
     */
    private static function slug_lang_column()
    {
        $schema = get_transient('ai_tr_slugs_schema');
        return ($schema === 'original') ? 'language_code' : 'lang';
    }

    // ──────────────────────────────────────────────
    //  Google XML Sitemaps plugin integration
    // ──────────────────────────────────────────────

    /**
     * Hook: sm_build_index – add our sitemaps to the Google XML Sitemaps index.
     *
     * @param \GoogleSitemapGenerator $gsg
     */
    public static function gsm_build_index($gsg)
    {
        // Language homepages
        $lang_entries = self::get_language_entries();
        if (!empty($lang_entries)) {
            $lastmod = 0;
            foreach ($lang_entries as $e) {
                if (!empty($e['lastmod']) && $e['lastmod'] > $lastmod) {
                    $lastmod = $e['lastmod'];
                }
            }
            $gsg->add_sitemap('languages-sitemap', null, $lastmod);
        }

        // Translated posts per language
        foreach (self::get_sitemap_languages() as $lang) {
            $entries = self::get_translated_entries($lang, 1, 0);
            if (!empty($entries)) {
                $lastmod = !empty($entries[0]['lastmod']) ? $entries[0]['lastmod'] : 0;
                $gsg->add_sitemap('translated-' . $lang . '-sitemap', null, $lastmod);
            }
        }
    }

    /**
     * Hook: sm_build_content – output language homepage + translated post URLs.
     *
     * @param \GoogleSitemapGenerator $gsg
     * @param string $type
     * @param string $params
     */
    public static function gsm_build_content($gsg, $type, $params)
    {
        // Language homepages sitemap
        $is_lang = ($type === 'languages')
            || ($type === 'pt' && is_string($params) && strpos($params, 'languages') === 0);

        if ($is_lang) {
            foreach (self::get_language_entries() as $e) {
                $gsg->add_url($e['loc'], $e['lastmod'], 'weekly', 0.5);
            }
            return;
        }

        // Translated posts sitemaps: translated-{lang}-sitemap
        $is_translated = ($type === 'pt' && is_string($params) && strpos($params, 'translated-') === 0);
        if (!$is_translated) {
            return;
        }

        // Extract language code from params: "translated-{lang}-sitemap..."
        if (preg_match('/^translated-([a-z]{2,5})-/', $params, $m)) {
            $lang    = $m[1];
            $entries = self::get_translated_entries($lang, 50000, 0);
            foreach ($entries as $e) {
                $lastmod = !empty($e['lastmod']) ? $e['lastmod'] : 0;
                $gsg->add_url($e['loc'], $lastmod, 'weekly', 0.5);
            }
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

/**
 * WP native sitemap provider for translated posts/pages/CPT.
 *
 * Registers one subtype per non-default language. Each subtype sitemap contains
 * all translated posts for that language, using the translated slugs from the
 * ai_translate_slugs table.
 *
 * Sitemap URLs look like: /wp-sitemap-ai-translate-posts-{lang}-{page}.xml
 */
class AI_Sitemap_Provider_Translated extends \WP_Sitemaps_Provider
{
    public function __construct()
    {
        $this->name        = 'ai-translate-posts';
        $this->object_type = 'ai-translate-posts';
    }

    /**
     * Return one subtype per non-default language.
     *
     * @return \stdClass[]
     */
    public function get_object_subtypes()
    {
        $subtypes = [];
        foreach (AI_Sitemap::get_sitemap_languages() as $lang) {
            $obj       = new \stdClass();
            $obj->name = $lang;
            $subtypes[$lang] = $obj;
        }
        return $subtypes;
    }

    /**
     * @param int    $page_num
     * @param string $object_subtype Language code.
     * @return array[]
     */
    public function get_url_list($page_num, $object_subtype = '')
    {
        $lang = sanitize_key($object_subtype);
        if ($lang === '') {
            return [];
        }

        $max_urls = wp_sitemaps_get_max_urls($this->object_type);
        $offset   = ($page_num - 1) * $max_urls;

        $entries = AI_Sitemap::get_translated_entries($lang, $max_urls, $offset);

        $urls = [];
        foreach ($entries as $e) {
            $entry = ['loc' => $e['loc']];
            if (!empty($e['lastmod'])) {
                $entry['lastmod'] = wp_date('c', $e['lastmod']);
            }
            $urls[] = $entry;
        }
        return $urls;
    }

    /**
     * @param string $object_subtype Language code.
     * @return int
     */
    public function get_max_num_pages($object_subtype = '')
    {
        $lang = sanitize_key($object_subtype);
        if ($lang === '') {
            return 0;
        }

        $count    = AI_Sitemap::count_translated_entries($lang);
        $max_urls = wp_sitemaps_get_max_urls($this->object_type);

        return (int) ceil($count / $max_urls);
    }
}
