<?php

namespace AITranslate;

/**
 * Conservative 404 recovery for language-prefixed URLs.
 *
 * When the existing routing has determined that a request is a real 404, this
 * class tries to find exactly one high-confidence canonical translated URL to
 * redirect the visitor to. It is intentionally strict:
 *
 * - Only acts on language-prefixed paths /{lang}/...
 * - Only redirects when there is exactly one valid post candidate.
 * - Never triggers a new translation (uses the existing slug map only).
 * - Returns null on any ambiguity, so a real 404 stays a 404.
 *
 * This is Phase 1 of docs/404-herstelplan-op-basis-van-url.md.
 */
final class AI_404_Recovery
{
    /**
     * Try to resolve a 404'd request path to a canonical translated URL.
     *
     * @param string $request_path Site-relative path (e.g. "/en/old-slug/").
     * @return string|null Absolute canonical URL or null when no safe match.
     */
    public static function resolve($request_path)
    {
        $path = (string) $request_path;
        if ($path === '' || $path === '/') return null;

        // Strip query string if accidentally included.
        $qpos = strpos($path, '?');
        if ($qpos !== false) {
            $path = substr($path, 0, $qpos);
        }

        if (!preg_match('#^/([a-z]{2})(?:/(.*))?/?$#i', $path, $m)) {
            return null;
        }
        $lang = strtolower($m[1]);
        $rest = isset($m[2]) ? trim((string) $m[2], '/') : '';
        if ($rest === '') return null;

        if (strpos($rest, '%') !== false) {
            $decoded = rawurldecode($rest);
            if (mb_check_encoding($decoded, 'UTF-8')) {
                $rest = $decoded;
            }
        }

        // Skip request shapes that are not single content pages.
        if (preg_match('#^page/[0-9]+/?$#i', $rest)) return null;
        if (str_starts_with($rest, 'feed')) return null;
        if (str_contains($rest, 'wp-')) return null;

        if (!self::is_recognised_language($lang)) {
            return null;
        }

        // Detect optional CPT prefix to require a matching post_type for safety.
        $expected_post_type = null;
        $basename = $rest;
        if (strpos($rest, '/') !== false) {
            $parts = array_values(array_filter(explode('/', $rest), 'strlen'));
            $first = $parts[0] ?? '';
            if ($first !== '' && function_exists('post_type_exists') && post_type_exists($first)) {
                $expected_post_type = $first;
            }
            $basename = (string) end($parts);
        }
        $basename = trim($basename, '/');
        if ($basename === '') return null;

        $candidate_ids = AI_Slugs::find_post_ids_by_translated_slug($basename);
        $was_auto_discovered = false;
        
        if (empty($candidate_ids)) {
            // Fallback: auto-discover via source_slug, cross-language match, or Jaccard similarity.
            $discovered_id = AI_Slugs::discover_post_id_for_404_slug($basename, $lang);
            if ($discovered_id !== null) {
                $candidate_ids = [$discovered_id];
                $was_auto_discovered = true;
            } else {
                return null;
            }
        }

        // Filter strictly: published, public, non-attachment, optional CPT match.
        $valid = self::filter_candidate_posts($candidate_ids, $expected_post_type);

        // CPT-prefix in the URL may be wrong (e.g. /lang/product/<slug>/ on a regular post).
        // If the strict filter yields no unique hit, retry without the CPT constraint —
        // we still require exactly one valid published, public, non-attachment post.
        if (count($valid) !== 1 && $expected_post_type !== null) {
            $relaxed = self::filter_candidate_posts($candidate_ids, null);
            if (count($relaxed) === 1) {
                $valid = $relaxed;
            }
        }

        if (count($valid) !== 1) {
            return null;
        }
        $post = reset($valid);

        $target_url = self::build_canonical_url($post, $lang);
        if ($target_url === null || $target_url === '') {
            return null;
        }

        // Self-redirect / loop guard: ignore trailing-slash differences.
        $current_url = function_exists('home_url') ? home_url($path) : $path;
        if (rtrim($target_url, '/') === rtrim((string) $current_url, '/')) {
            return null;
        }

        // Same-host guard.
        $home_host = function_exists('home_url') ? wp_parse_url(home_url('/'), PHP_URL_HOST) : null;
        $tgt_host  = wp_parse_url($target_url, PHP_URL_HOST);
        if ($home_host !== null && $home_host !== $tgt_host) {
            return null;
        }

        if ($was_auto_discovered) {
            AI_Slugs::record_history($post->ID, $lang, $basename);
        }

        return $target_url;
    }

    /**
     * Filter candidate post IDs down to published, public, non-attachment posts.
     * When $expected_post_type is provided, also requires a matching post_type.
     *
     * @param array<int> $candidate_ids
     * @param string|null $expected_post_type
     * @return array<int,object> Post objects keyed by ID.
     */
    private static function filter_candidate_posts(array $candidate_ids, $expected_post_type)
    {
        $valid = [];
        foreach ($candidate_ids as $pid) {
            $post = get_post((int) $pid);
            if (!$post || $post->post_status !== 'publish') continue;
            if ($post->post_type === 'attachment') continue;
            if (!self::is_public_post_type((string) $post->post_type)) continue;
            if ($expected_post_type !== null && $post->post_type !== $expected_post_type) continue;
            $valid[(int) $post->ID] = $post;
        }
        return $valid;
    }

    /**
     * Build the canonical translated URL for a post in the target language.
     * Will not trigger a new translation: requires an existing translated slug
     * (or homepage / default-language behaviour).
     *
     * @param object $post WordPress post object.
     * @param string $lang Target language code.
     * @return string|null Absolute URL or null when no safe URL can be built.
     */
    private static function build_canonical_url($post, $lang)
    {
        $default = AI_Lang::default();
        $is_default = ($default !== null && strtolower($lang) === strtolower((string) $default));

        $front_id = (int) get_option('page_on_front');
        if ($front_id > 0 && (int) $post->ID === $front_id) {
            $path = $is_default ? '/' : '/' . $lang . '/';
            return home_url($path);
        }

        if ($is_default) {
            // Default language uses the source slug; build via permalink to honour CPT prefix.
            $perm = get_permalink((int) $post->ID);
            return is_string($perm) ? $perm : null;
        }

        // Non-default language: require an existing translated slug; do NOT call API.
        $translated = AI_Slugs::get_or_generate((int) $post->ID, $lang, false);
        if (!is_string($translated) || $translated === '') {
            return null;
        }

        $cpt_prefix = '';
        if ($post->post_type !== 'page' && $post->post_type !== 'post') {
            $obj = get_post_type_object((string) $post->post_type);
            if ($obj && !empty($obj->rewrite['slug'])) {
                $cpt_prefix = trim((string) $obj->rewrite['slug'], '/') . '/';
            }
        }

        $path = '/' . $lang . '/' . $cpt_prefix . trim($translated, '/') . '/';
        return home_url($path);
    }

    /**
     * Is this language code one we should consider for recovery (default,
     * enabled, or detectable)?
     *
     * @param string $lang
     * @return bool
     */
    private static function is_recognised_language($lang)
    {
        $low = strtolower((string) $lang);
        $default = AI_Lang::default();
        if ($default !== null && $low === strtolower((string) $default)) return true;
        $enabled    = (array) AI_Lang::enabled();
        $detectable = (array) AI_Lang::detectable();
        if (in_array($low, array_map('strtolower', $enabled), true)) return true;
        if (in_array($low, array_map('strtolower', $detectable), true)) return true;
        return false;
    }

    /**
     * Whether the given post type is registered as public.
     *
     * @param string $post_type
     * @return bool
     */
    private static function is_public_post_type($post_type)
    {
        if ($post_type === '') return false;
        $obj = function_exists('get_post_type_object') ? get_post_type_object($post_type) : null;
        if (!$obj) return false;
        return !empty($obj->public);
    }
}
