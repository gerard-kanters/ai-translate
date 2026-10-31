<?php

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST endpoint ai-translate/v1/batch-strings: translates short UI strings (placeholders, aria
 * labels, client-rendered texts) for the JavaScript on the page.
 *
 * The endpoint is reachable by anonymous visitors and spends translation credits, so every step
 * that decides whether something is sent to the translation service lives here, in small methods.
 */
final class AI_REST_Strings
{
    /** Requests per minute per IP address. */
    private const IP_LIMIT = 30;

    /** Strings per request and characters per string (UI texts are short). */
    private const MAX_STRINGS = 50;
    private const MAX_LENGTH = 150;

    /** How long the fetched page HTML is kept; it only serves to check that strings occur on the page. */
    private const PAGE_HTML_TTL = 6 * HOUR_IN_SECONDS;

    public static function register(): void
    {
        register_rest_route('ai-translate/v1', '/batch-strings', [
            'methods' => 'POST',
            'permission_callback' => [self::class, 'permission'],
            'args' => [],
            'callback' => [self::class, 'handle'],
        ]);
    }

    /**
     * @return true|\WP_Error
     */
    public static function permission(\WP_REST_Request $request)
    {
        // Bot check first: bots get cached answers only, no need for referer/nonce
        if (\ai_translate_is_known_bot_ua()) {
            $request->set_param('_ai_tr_bot_no_nonce', 1);
            return true;
        }
        // Referer check: must originate from this site (exact host, not prefix match)
        $referer = isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
        if ($referer === '' || !\ai_translate_is_same_site_url($referer)) {
            return new \WP_Error('rest_forbidden', 'Invalid referer', ['status' => 403]);
        }
        $nonce = $request->get_header('X-WP-Nonce');
        if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }
        $nonce_param = $request->get_param('nonce');
        if ($nonce_param && wp_verify_nonce($nonce_param, 'ai_translate_front_nonce')) {
            return true;
        }

        // Public frontend pages can be heavily cached, causing stale inline nonces.
        // Allow anonymous same-site requests without requiring a valid nonce.
        if (!is_user_logged_in()) {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_ORIGIN'])) : '';
            // When Origin is present it must match this site; an empty Origin is allowed
            // (some browsers omit it on same-site navigations) because Referer already passed.
            if ($origin !== '' && !\ai_translate_is_same_site_url($origin)) {
                return new \WP_Error('rest_forbidden', 'Invalid origin', ['status' => 403]);
            }
            return true;
        }

        return new \WP_Error('rest_forbidden', 'Invalid nonce', ['status' => 403]);
    }

    public static function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $is_bot_request = (bool) $request->get_param('_ai_tr_bot_no_nonce');

        // REMOTE_ADDR only: forwarded-for headers can be set by the client. Behind a proxy that
        // does not restore the visitor address, visitors share one counter (the hourly budget
        // below is what protects the translation spend either way).
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        if (!rate_limit_hit('rest-ip-' . $ip, self::IP_LIMIT, 60)) {
            return new \WP_REST_Response(['error' => 'Rate limit exceeded'], 429);
        }

        $referer = self::normalize_referer(isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_REFERER'])) : '');
        $prepared = self::prepare_texts($request->get_param('strings'));
        $originals = $prepared['originals'];

        $lang = self::resolve_language(sanitize_key((string) ($request->get_param('lang') ?? '')), $referer);
        $default = AI_Lang::default();
        // Only languages this site actually offers: the language comes from the request, and without
        // this anyone could have page strings translated into any code, at the site owner's cost.
        if ($lang === null || $default === null || !lang_is_offered($lang)) {
            return self::respond([]);
        }
        if (strtolower($lang) === strtolower($default)) {
            return self::respond(self::source_map(array_keys($originals), $originals));
        }

        $expiry = max(1, AI_Translate_Core::cache_expiration_hours()) * HOUR_IN_SECONDS;
        // With translations stopped, or for bots, only what is cached is answered; the rest comes back as source text.
        $stop_translations = (bool) AI_Translate_Core::get_setting('stop_translations_except_cache_invalidation', false);
        $skip_api = $stop_translations || $is_bot_request;

        $map = [];
        $toTranslate = [];
        $i = 0;
        foreach (array_keys($originals) as $normalized) {
            $normalized = (string) $normalized;
            $cached = self::lookup($lang, $normalized, $expiry);
            if ($cached !== false && ($skip_api || self::translation_is_usable((string) $cached, $lang))) {
                $map[$originals[$normalized]] = (string) $cached;
                continue;
            }
            if ($skip_api) {
                $map[$originals[$normalized]] = $originals[$normalized];
                continue;
            }
            $toTranslate['s' . (++$i)] = $normalized;
        }

        if (!empty($toTranslate)) {
            $toTranslate = self::keep_strings_on_page($toTranslate, $referer);
        }

        // Site-wide cap on strings this endpoint may send to the translation service per hour.
        // Anonymous visitors can reach it, so the per-IP limit alone does not bound the spend.
        // Filter 'ai_translate_rest_hourly_string_budget': 0 or less turns the cap off.
        if (!empty($toTranslate)) {
            $hourly_budget = (int) apply_filters('ai_translate_rest_hourly_string_budget', 1500);
            if ($hourly_budget > 0 && !rate_limit_hit('rest-strings-hour', $hourly_budget, HOUR_IN_SECONDS, count($toTranslate))) {
                // Budget used up: answer with the source texts, nothing is sent out
                $map += self::source_map($toTranslate, $originals);
                $toTranslate = [];
            }
        }

        if (!empty($toTranslate)) {
            $map += self::translate($toTranslate, $originals, (string) $default, (string) $lang, $expiry);
        }
        return self::respond($map);
    }

    /**
     * Collapse repeated slashes in the path of a URL ("/de//" becomes "/de/"). A URL without a
     * path is returned as it came.
     */
    public static function normalize_referer(string $referer): string
    {
        if ($referer === '') {
            return '';
        }
        $parts = wp_parse_url($referer);
        if (!is_array($parts) || !isset($parts['path'])) {
            return $referer;
        }
        $out = '';
        if (isset($parts['scheme'])) {
            $out .= $parts['scheme'] . '://';
        }
        if (isset($parts['host'])) {
            $out .= $parts['host'];
            if (isset($parts['port'])) {
                $out .= ':' . $parts['port'];
            }
        }
        $out .= preg_replace('#/+#', '/', $parts['path']);
        if (isset($parts['query'])) {
            $out .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $out .= '#' . $parts['fragment'];
        }
        return $out;
    }

    /**
     * Validate and normalize the posted strings: at most 50 strings of at most 150 characters,
     * trimmed with whitespace collapsed. "Naam " and "Naam" count as one text.
     *
     * @param mixed $strings Value of the "strings" parameter.
     * @return array{originals: array<string,string>} Normalized text => the first original form posted for it
     *                                                (the page script looks the answer up by that form).
     */
    public static function prepare_texts($strings): array
    {
        $originals = [];
        if (!is_array($strings)) {
            return ['originals' => $originals];
        }
        foreach (array_slice($strings, 0, self::MAX_STRINGS) as $s) {
            if (!is_string($s) || mb_strlen($s) > self::MAX_LENGTH) {
                continue;
            }
            $original = trim($s);
            if ($original === '') {
                continue;
            }
            $normalized = preg_replace('/\s+/u', ' ', $original);
            if (!isset($originals[$normalized])) {
                $originals[$normalized] = $original;
            }
        }
        return ['originals' => $originals];
    }

    /**
     * Language of the request: the parameter sent by the page script, else a language prefix in the
     * Referer path, else the current language.
     */
    public static function resolve_language(string $param, string $referer): ?string
    {
        if ($param !== '') {
            return $param;
        }
        $path = $referer !== '' ? (wp_parse_url($referer, PHP_URL_PATH) ?: '') : '';
        if ($path !== '' && preg_match('#/([a-z]{2})(?:/|$)#i', $path, $m)) {
            return strtolower($m[1]);
        }
        return AI_Lang::current();
    }

    /**
     * Whether a cached translation can be served. Long texts for a non-Latin language that are
     * mostly Latin letters are treated as failed translations (the model returned the source).
     * Short texts are exempt: brand names and codes stay Latin. A translation identical to the
     * source is fine (the text was already in the target language).
     */
    public static function translation_is_usable(string $cached, string $lang): bool
    {
        $len = mb_strlen($cached);
        if ($len <= 100 || !AI_Lang::is_non_latin($lang)) {
            return true;
        }
        $latin = preg_match_all('/[a-zA-Z]/', $cached);
        return ($latin / $len) <= 0.4;
    }

    /**
     * Remove a language annotation that models sometimes append, such as "(français)" or
     * "(NL : tapez un message...)". Only trailing text in parentheses that the source did not have.
     */
    public static function strip_model_annotation(string $translation, string $source): string
    {
        if (strpos($source, '(') !== false) {
            return $translation;
        }
        $first = '/\s*\((?:[a-zA-Z]{2}\s*:|[^)]{2,30}ais|[^)]{2,30}isch|[^)]{2,30}ish|[^)]{2,30}ese|[^)]{2,30}ñol|[^)]{2,30}iano)[^)]*\)\s*$/u';
        if (!preg_match($first, $translation)) {
            return $translation;
        }
        do {
            $translation = preg_replace('/\s*\([^)]*\)\s*$/u', '', $translation);
        } while (preg_match('/\s*\([^)]*\)\s*$/u', $translation));
        return trim($translation);
    }

    /**
     * Segment type for the translation prompt: sentences are "node", short UI labels "meta".
     */
    public static function segment_type(string $text): string
    {
        $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return count($words) > 4 ? 'node' : 'meta';
    }

    /**
     * Whether a string occurs in the HTML of the page (in attributes or content), compared without
     * regard to case.
     */
    public static function occurs_in_html(string $text, string $html): bool
    {
        return (bool) preg_match('/' . preg_quote($text, '/') . '/iu', $html);
    }

    /**
     * Texts as they were posted, keyed by id, for ids in $ids that are normalized texts.
     *
     * @param array<int|string,string> $normalizedById Normalized texts (values) to answer with their source text.
     * @param array<string,string>     $originals      Normalized text => posted form.
     * @return array<string,string>
     */
    private static function source_map(array $normalizedById, array $originals): array
    {
        $map = [];
        foreach ($normalizedById as $normalized) {
            $normalized = (string) $normalized;
            $original = $originals[$normalized] ?? $normalized;
            $map[$original] = $original;
        }
        return $map;
    }

    /**
     * Cached translation of a text, from the segment store or, on older installs, from the
     * transient the earlier versions wrote. False when there is none.
     *
     * @return string|false
     */
    private static function lookup(string $lang, string $normalized, int $expiry)
    {
        $hash = AI_Segment_Store::hash('attr', $normalized, $lang);
        $hit = AI_Segment_Store::get_many($lang, [$hash], $expiry);
        if (isset($hit[$hash])) {
            return $hit[$hash];
        }
        $legacy = \ai_translate_get_attr_transient(\ai_translate_attr_cache_key($lang, $normalized));
        if ($legacy !== false) {
            return $legacy;
        }
        if (AI_Segment_Store::legacy_allowed($lang)) {
            return get_transient(\ai_translate_seg_attr_cache_key($lang, $normalized));
        }
        return false;
    }

    /**
     * HTML of the page the request came from, fetched once and kept for a few hours. The Referer is
     * supplied by the client, so the query string and fragment are dropped: every "?x=N" would
     * otherwise be a new fetch of the site and a new stored copy of the page.
     *
     * @return string|false False when the page could not be fetched.
     */
    private static function page_html(string $referer)
    {
        $page_url = (string) strtok($referer, '?#');
        $key = 'ai_tr_page_html_' . md5($page_url);
        $html = get_transient($key);
        if ($html !== false) {
            return $html;
        }
        $response = wp_remote_get($page_url, [
            'timeout' => 5,
            'sslverify' => true,
            'headers' => ['User-Agent' => 'AI-Translate-Validator/1.0'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        $html = wp_remote_retrieve_body($response);
        set_transient($key, $html, self::PAGE_HTML_TTL);
        return $html;
    }

    /**
     * Keep only strings that really are on the page the request claims to come from, so the
     * endpoint cannot be used to translate arbitrary text. Nothing is kept when the Referer is not
     * from this site or the page cannot be fetched.
     *
     * @param array<string,string> $toTranslate Id => normalized text.
     * @return array<string,string>
     */
    private static function keep_strings_on_page(array $toTranslate, string $referer): array
    {
        if ($referer === '' || !\ai_translate_is_same_site_url($referer)) {
            return [];
        }
        $html = self::page_html($referer);
        if ($html === false || $html === '') {
            return [];
        }
        $kept = [];
        foreach ($toTranslate as $id => $text) {
            if (
                self::occurs_in_html($text, $html)
                // Client-side rendered UI strings (e.g. WooCommerce block cart/checkout) are not in the
                // server HTML: check them against the shipped catalog, the catalog with placeholders
                // filled in (AJAX notices with a product name), and titles and term names in the database.
                || \ai_translate_is_known_catalog_string($text)
                || \ai_translate_matches_catalog_pattern($text)
                || \ai_translate_is_known_title_or_term_string($text)
            ) {
                $kept[$id] = $text;
            }
        }
        return $kept;
    }

    /**
     * Translate the strings and store the results.
     *
     * @param array<string,string> $toTranslate Id => normalized text.
     * @param array<string,string> $originals   Normalized text => posted form.
     * @return array<string,string> Posted form => translation.
     */
    private static function translate(array $toTranslate, array $originals, string $default, string $lang, int $expiry): array
    {
        $plan = ['segments' => []];
        foreach ($toTranslate as $id => $text) {
            $plan['segments'][] = ['id' => $id, 'text' => $text, 'type' => self::segment_type($text)];
        }
        $ctx = ['website_context' => AI_Translate_Core::get_website_context()];
        $res = AI_Batch::translate_plan($plan, $default, $lang, $ctx);
        $segs = isset($res['segments']) && is_array($res['segments']) ? $res['segments'] : [];

        $map = [];
        $rows = [];
        foreach ($toTranslate as $id => $normalized) {
            $tr = self::strip_model_annotation(isset($segs[$id]) ? (string) $segs[$id] : $normalized, $normalized);
            $map[$originals[$normalized] ?? $normalized] = $tr;
            // Also an unchanged text is stored: it was already in the target language and must not be sent again
            $rows[AI_Segment_Store::hash('attr', $normalized, $lang)] = ['text' => $tr, 'type' => 'attr'];
        }
        AI_Segment_Store::set_many($lang, $rows);
        return $map;
    }

    private static function respond(array $map): \WP_REST_Response
    {
        return new \WP_REST_Response(['success' => true, 'data' => ['map' => $map]], 200);
    }
}
