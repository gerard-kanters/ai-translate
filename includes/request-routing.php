<?php

/**
 * Translated URL routing on parse_request: old slugs, blog pagination, custom post type archives, terms and posts.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolve _wp_old_slug in parse_request and redirect to canonical URL before 404 is set.
 * Priority 0 so this runs before any 404 redirect plugin (which would send 404s to homepage).
 */
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax() || ai_translate_is_xml_request()) {
        return;
    }
    $request_path = ai_translate_request_path(true);
    if (!is_string($request_path) || $request_path === '' || $request_path === '/') {
        return;
    }
    if (preg_match('#^/([a-z]{2})(?:/|$)#i', $request_path)) {
        return;
    }
    $path_parts = array_filter(explode('/', trim($request_path, '/')));
    if (empty($path_parts)) {
        return;
    }
    $slug = end($path_parts);
    global $wpdb;
    $post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 1",
        $slug
    ));
    if (!$post_id) {
        return;
    }
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        return;
    }
    $permalink = get_permalink($post);
    if ($permalink) {
        wp_safe_redirect($permalink, 301);
        exit;
    }
}, 0);

/**
 * Ensure language-only URLs (e.g., /en/, /de/) route to the site's front page.
 */
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    if (ai_translate_is_xml_request()) {
        return;
    }
    $request_path = ai_translate_request_path(true);
    if (!is_string($request_path)) {
        $request_path = '/';
    }
    if (preg_match('#^/([a-z]{2})/?$#i', $request_path)) {
        // Mirror org behaviour: only force front when no other query vars (except 'lang').
        // Special-case: when theme uses ?blogpage or native 'paged', route to the posts page.
        $qv = is_array($wp->query_vars) ? $wp->query_vars : array();
        $blogPaged = isset($qv['blogpage']) ? (int)$qv['blogpage'] : (isset($_GET['blogpage']) ? (int) $_GET['blogpage'] : 0);
        $paged = isset($qv['paged']) ? (int)$qv['paged'] : (isset($_GET['paged']) ? (int) $_GET['paged'] : 0);
        if ($blogPaged > 0 || $paged > 0) {
            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id > 0) {
                $posts_page = get_post($posts_page_id);
                if ($posts_page) {
                    $existing = is_array($wp->query_vars) ? $wp->query_vars : array();
                    // Route to posts index by pointing to the posts page slug and paged
                    $wp->query_vars = array_merge($existing, array(
                        'pagename' => (string) get_page_uri($posts_page_id),
                        'paged' => $paged ?: $blogPaged,
                    ));
                    return;
                }
            }
            // If no posts page configured, fall back to home with pagination
            return;
        }
        $qv_no_lang = array_diff_key($qv, ['lang' => 1]);
        if (!empty($qv_no_lang)) {
            return;
        }
        $front_id = (int) get_option('page_on_front');
        if ($front_id > 0) {
            $front_post = get_post($front_id);
            if ($front_post) {
                // Merge, do not drop existing query vars (e.g., blogpage/paged)
                $existing = is_array($wp->query_vars) ? $wp->query_vars : array();
                $wp->query_vars = array_merge($existing, array(
                    'page_id' => $front_id,
                    'pagename' => (string) $front_post->post_name,
                    'post_type' => (string) $front_post->post_type,
                ));
                $wp->is_page = true;
                $wp->is_singular = true;
                $wp->is_home = false;
                $wp->is_404 = false;
            }
        }
    }
});

/**
 * Route /{lang}/page/{n} to the posts page with proper pagination.
 */
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    if (ai_translate_is_xml_request()) {
        return;
    }
    $path = ai_translate_request_path(true);
    if (!is_string($path) || $path === '/') {
        return;
    }
    if (!preg_match('#^/([a-z]{2})/page/([0-9]+)/?$#i', $path, $m)) {
        return;
    }
    $n = max(1, (int) $m[2]);
    $posts_page_id = (int) get_option('page_for_posts');
    $existing = is_array($wp->query_vars) ? $wp->query_vars : array();
    $wp->query_vars = $existing;
    $wp->query_vars['paged'] = $n;
    if ($posts_page_id > 0) {
        $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
        $wp->is_home = true;
        $wp->is_page = false;
        $wp->is_singular = false;
        $wp->is_404 = false;
    } else {
        $wp->is_home = true;
        $wp->is_404 = false;
    }
});

/**
 * Ensure /{lang}/?blogpage={n} resolves to the posts index with pagination.
 * Some themes use a custom query var (?blogpage). Map this early and point to posts page slug.
 */

/**
 * Check if a URL path segment matches a public CPT archive.
 * Returns the post type name when the segment equals the post type name,
 * the has_archive slug, or the rewrite slug of a CPT that has an archive.
 * Returns null when no match is found.
 *
 * @param string $segment URL segment to test (single, no slashes).
 * @return string|null Post type name or null.
 */
function ai_translate_detect_cpt_archive($segment)
{
    if ($segment === '') {
        return null;
    }
    $post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
    foreach ($post_types as $pt) {
        if (!$pt->has_archive) {
            continue;
        }
        if ($pt->name === $segment) {
            return $pt->name;
        }
        if (is_string($pt->has_archive) && $pt->has_archive === $segment) {
            return $pt->name;
        }
        if (!empty($pt->rewrite['slug']) && $pt->rewrite['slug'] === $segment) {
            return $pt->name;
        }
    }
    return null;
}

/**
 * Return the public rewrite-slug prefix for a post type (e.g. 'product/' or 'urun/').
 * Empty for built-in post/page or when the type has no rewrite slug.
 *
 * WooCommerce (and other CPTs) often use a rewrite slug that differs from the
 * post type name (product_base = 'urun' while post type remains 'product').
 * All language-prefixed URLs must use the rewrite slug, not the type name.
 *
 * @param string $post_type Post type name.
 * @return string Prefix with trailing slash, or ''.
 */
function ai_translate_cpt_path_prefix($post_type)
{
    $post_type = (string) $post_type;
    if ($post_type === '' || $post_type === 'post' || $post_type === 'page') {
        return '';
    }
    $obj = get_post_type_object($post_type);
    if (!$obj || empty($obj->rewrite['slug'])) {
        return '';
    }
    return trim((string) $obj->rewrite['slug'], '/') . '/';
}

/**
 * Resolve a URL path segment to a public CPT name.
 * Matches the post type name OR its rewrite slug (e.g. 'urun' → 'product').
 *
 * @param string $segment First path segment after the language code.
 * @return array{type:string,rewrite_slug:string}|null
 */
function ai_translate_match_cpt_segment($segment)
{
    $segment = trim((string) $segment, '/');
    if ($segment === '') {
        return null;
    }
    foreach (get_post_types(['public' => true, '_builtin' => false], 'objects') as $pt) {
        $rewrite_slug = !empty($pt->rewrite['slug']) ? trim((string) $pt->rewrite['slug'], '/') : (string) $pt->name;
        if ($segment === (string) $pt->name || $segment === $rewrite_slug) {
            return [
                'type'         => (string) $pt->name,
                'rewrite_slug' => $rewrite_slug,
            ];
        }
    }
    return null;
}

/**
 * Resolve a language-stripped URL path to a taxonomy term archive.
 * Handles built-in category/tag slugs as well as custom taxonomies whose
 * rewrite slug differs from the taxonomy name (e.g. WooCommerce 'product_cat'
 * with rewrite slug 'product-category'). Supports hierarchical term paths
 * (parent/child) and a trailing /page/N pagination suffix.
 *
 * @param string $path Path without language prefix (e.g. 'product-category/dragons/page/2').
 * @return array{term:\WP_Term,paged:int}|null Term plus page number, or null when no match.
 */
function ai_translate_resolve_term_from_path($path)
{
    $path = trim((string) $path, '/');
    if ($path === '') {
        return null;
    }
    $paged = 0;
    if (preg_match('#^(.+)/page/([0-9]+)$#i', $path, $pm)) {
        $path = trim($pm[1], '/');
        $paged = (int) $pm[2];
    }
    $parts = array_values(array_filter(explode('/', $path)));
    if (empty($parts)) {
        return null;
    }
    $term_slug = (string) end($parts);

    // Built-in taxonomies: bare term slug as last path segment.
    foreach (array('category', 'post_tag') as $builtin) {
        $term = get_term_by('slug', $term_slug, $builtin);
        if ($term && !is_wp_error($term)) {
            return array('term' => $term, 'paged' => $paged);
        }
    }

    if (count($parts) < 2) {
        return null;
    }

    // Custom taxonomies: match the URL base against the taxonomy name or its
    // rewrite slug (may contain multiple segments, e.g. 'shop/category').
    $base = implode('/', array_slice($parts, 0, count($parts) - 1));
    foreach (get_taxonomies(array('public' => true), 'objects') as $tax) {
        $candidates = array($tax->name);
        if (!empty($tax->rewrite['slug'])) {
            $candidates[] = trim((string) $tax->rewrite['slug'], '/');
        }
        foreach (array_unique($candidates) as $slug_base) {
            if ($slug_base === '') {
                continue;
            }
            // Exact match, or prefix match for hierarchical term paths (base/parent/child).
            if ($base === $slug_base || str_starts_with($base, $slug_base . '/')) {
                $term = get_term_by('slug', $term_slug, $tax->name);
                if ($term && !is_wp_error($term)) {
                    return array('term' => $term, 'paged' => $paged);
                }
            }
        }
    }
    return null;
}

/**
 * Map a resolved taxonomy term onto WP query vars (category_name / tag / {taxonomy} + paged).
 *
 * @param array $vars          Query vars (request filter) or $wp->query_vars.
 * @param array $resolved_term Result of ai_translate_resolve_term_from_path().
 * @return array
 */
function ai_translate_apply_resolved_term_to_vars(array $vars, array $resolved_term): array {
    if (empty($resolved_term['term']) || !($resolved_term['term'] instanceof \WP_Term)) {
        return $vars;
    }
    $term = $resolved_term['term'];
    $tax = $term->taxonomy;
    if ($tax === 'category') {
        $vars['category_name'] = $term->slug;
    } elseif ($tax === 'post_tag') {
        $vars['tag'] = $term->slug;
    } else {
        $vars[$tax] = $term->slug;
    }
    if (!empty($resolved_term['paged']) && (int) $resolved_term['paged'] > 1) {
        $vars['paged'] = (int) $resolved_term['paged'];
    }
    return $vars;
}

/**
 * Split a registered rewrite-endpoint suffix or a /page/N pagination suffix off
 * a language-stripped URL path. WooCommerce appends endpoints to the page URL
 * (e.g. 'my-account/orders', 'checkout/order-received/123') and page-based
 * archives such as the shop page paginate as 'shop/page/2'. The base path must
 * be resolved via the slug map; the suffix maps to extra query vars and must be
 * preserved in canonical redirects.
 *
 * @param string $path Path without language prefix (e.g. 'my-account/orders').
 * @return array{base:string,suffix:string,endpoint:?string,value:string,paged:int}
 *         base = path without suffix, suffix = raw suffix string ('' when none),
 *         endpoint = query var name for a rewrite endpoint (null when none),
 *         value = endpoint value (e.g. order ID), paged = page number (0 when none).
 */
function ai_translate_split_endpoint_suffix($path)
{
    $result = array('base' => trim((string) $path, '/'), 'suffix' => '', 'endpoint' => null, 'value' => '', 'paged' => 0);
    $segments = array_values(array_filter(explode('/', $result['base']), 'strlen'));
    $count = count($segments);
    if ($count < 2) {
        return $result;
    }

    // Trailing /page/N pagination. Requires at least 3 segments so the base path
    // stays non-empty; bare /{lang}/page/N is handled by its dedicated handler.
    if ($count >= 3 && strtolower($segments[$count - 2]) === 'page' && ctype_digit($segments[$count - 1])) {
        $result['paged'] = (int) $segments[$count - 1];
        $result['base'] = implode('/', array_slice($segments, 0, $count - 2));
        $result['suffix'] = 'page/' . $result['paged'];
        return $result;
    }

    // Registered rewrite endpoints (WooCommerce my-account/checkout endpoints, core 'embed', etc.).
    global $wp_rewrite;
    if (!isset($wp_rewrite->endpoints) || !is_array($wp_rewrite->endpoints)) {
        return $result;
    }
    foreach ($segments as $i => $segment) {
        if ($i === 0) {
            continue; // First segment is always part of the page path.
        }
        foreach ($wp_rewrite->endpoints as $endpoint) {
            if (!is_array($endpoint) || !isset($endpoint[1]) || (string) $endpoint[1] !== $segment) {
                continue;
            }
            // Only endpoints valid on pages or the site root (WooCommerce uses EP_ROOT | EP_PAGES).
            $mask = isset($endpoint[0]) ? (int) $endpoint[0] : 0;
            if (($mask & (EP_PAGES | EP_ROOT)) === 0) {
                continue;
            }
            $result['endpoint'] = (isset($endpoint[2]) && $endpoint[2] !== '') ? (string) $endpoint[2] : $segment;
            $result['value'] = implode('/', array_slice($segments, $i + 1));
            $result['base'] = implode('/', array_slice($segments, 0, $i));
            $result['suffix'] = $segment . ($result['value'] !== '' ? '/' . $result['value'] : '');
            return $result;
        }
    }
    return $result;
}

// Map translated paths like /{lang}/{translated-slug} to the original content (page or post)
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    if (ai_translate_is_xml_request()) {
        return;
    }
    $reqUriRaw = ai_translate_request_uri();
    $path = ai_translate_strip_site_path(wp_parse_url($reqUriRaw, PHP_URL_PATH) ?: '/');
    if (!is_string($path) || $path === '/') {
        return;
    }
    if (!preg_match('#^/([a-z]{2})(?:/(.*))?$#i', $path, $m)) {
        return;
    }
    $lang = strtolower($m[1]);
    $rest = isset($m[2]) ? (string) $m[2] : '';
    $rest = trim($rest, '/');
    if ($rest === '') {
        return; // handled by previous block (language root)
    }
    // Decode URL-encoded path (e.g. Hungarian slugs) so slug lookup matches DB
    if (strpos($rest, '%') !== false) {
        $rest_decoded = rawurldecode($rest);
        if (mb_check_encoding($rest_decoded, 'UTF-8')) {
            $rest = $rest_decoded;
        }
    }
    // Let the dedicated pagination handler manage /{lang}/page/{n}
    if (preg_match('#^page/[0-9]+/?$#i', $rest)) {
        return;
    }

    // Split a trailing rewrite-endpoint suffix (WooCommerce my-account/checkout
    // endpoints) or /page/N pagination off the path. The remaining base path is
    // resolved via the slug map; the suffix is mapped to query vars below and
    // preserved when redirecting to the canonical translated URL.
    $suffix_info = ai_translate_split_endpoint_suffix($rest);
    if ($suffix_info['suffix'] !== '' && $suffix_info['base'] !== '') {
        $rest = $suffix_info['base'];
    }

    // Try exact mapping from translated path to post ID via slug map
    $post_id = null;
    $expected_post_type = null;

    // Check if path contains a CPT rewrite-slug / post-type prefix (e.g. product/slug or urun/slug)
    $slug_used_for_lookup = null;
    $detected_post_type = null;
    $detected_rewrite_slug = null;
    if (strpos($rest, '/') !== false) {
        $parts = explode('/', $rest, 2);
        if (count($parts) === 2) {
            $potential_prefix = $parts[0];
            $potential_slug = trim($parts[1], '/');
            $slug_used_for_lookup = $potential_slug;

            // Match post type name OR rewrite slug (WooCommerce product_base may be 'urun').
            $cpt_match = ai_translate_match_cpt_segment($potential_prefix);
            if ($cpt_match) {
                $detected_post_type = $cpt_match['type'];
                $detected_rewrite_slug = $cpt_match['rewrite_slug'];
                // If slug is empty (only CPT prefix, e.g., /it/product/), route to archive or redirect
                if ($potential_slug === '') {
                    $cpt_obj = get_post_type_object($detected_post_type);
                    if ($cpt_obj && $cpt_obj->has_archive) {
                        $wp->query_vars = array_diff_key($wp->query_vars, ['ai_translate_path' => 1, 'name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
                        $wp->query_vars['post_type'] = $detected_post_type;
                        $wp->is_archive  = true;
                        $wp->is_singular = false;
                        $wp->is_404      = false;
                        return;
                    }
                    nocache_headers();
                    wp_safe_redirect(home_url('/' . $lang . '/'), 301);
                    exit;
                }
                $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $potential_slug);
                if ($post_id) {
                    $post = get_post((int) $post_id);
                    if ($post && $post->post_type === $detected_post_type) {
                        $expected_post_type = $detected_post_type;
                    } else {
                        // Post type mismatch, invalid result
                        $post_id = null;
                    }
                }
            }
        }
    } else {
        // Single segment: CPT archive root by name or rewrite/archive slug (e.g. /it/product, /it/urun)
        $cpt_match = ai_translate_match_cpt_segment($rest);
        if ($cpt_match) {
            $detected_post_type = $cpt_match['type'];
            $detected_rewrite_slug = $cpt_match['rewrite_slug'];
            $cpt_obj = get_post_type_object($detected_post_type);
            if ($cpt_obj && $cpt_obj->has_archive) {
                $wp->query_vars = array_diff_key($wp->query_vars, ['ai_translate_path' => 1, 'name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
                $wp->query_vars['post_type'] = $detected_post_type;
                $wp->is_archive   = true;
                $wp->is_singular  = false;
                $wp->is_404       = false;
                return;
            }
            nocache_headers();
            wp_safe_redirect(home_url('/' . $lang . '/'), 301);
            exit;
        }
    }

    // History/cross-language fallback for CPT-prefixed paths (e.g. /fr/product/<old-slug>/):
    // after a re-translation the previous translated slug only exists in the slug-history
    // table, and cached listing pages can still link to it. Resolve it (current map in any
    // language + history) so the canonical-redirect block below 301s to the current URL
    // instead of serving a 404.
    if (!$post_id && $detected_post_type && $slug_used_for_lookup !== null && $slug_used_for_lookup !== '') {
        $candidate_ids = \AITranslate\AI_Slugs::find_post_ids_by_translated_slug($slug_used_for_lookup);
        $unique_target = null;
        foreach ($candidate_ids as $cid) {
            $cp = get_post((int) $cid);
            if ($cp && $cp->post_status === 'publish' && $cp->post_type === $detected_post_type) {
                if ($unique_target === null) {
                    $unique_target = (int) $cid;
                } elseif ($unique_target !== (int) $cid) {
                    $unique_target = null; // ambiguous: multiple posts share this old slug
                    break;
                }
            }
        }
        if ($unique_target !== null) {
            $post_id = $unique_target;
            $expected_post_type = $detected_post_type;
        }
    }

    // Fallback: try the entire path as slug (for pages/posts without post type prefix)
    if (!$post_id) {
        $slug_used_for_lookup = $rest;
        $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $rest);
    }

    // Cross-language fallback: a slug that exists as translated_slug for OTHER languages
    // (e.g. /uk/kontakt/ where 'kontakt' is the cs/de/da/etc slug for the same Contact page)
    // resolves to the correct post. The redirect block below (line ~2050) will then 301
    // to the canonical URL for the current language.
    if (!$post_id && strpos($rest, '/') === false) {
        $candidate_ids = \AITranslate\AI_Slugs::find_post_ids_by_translated_slug($rest);
        if (!empty($candidate_ids)) {
            $unique_target = null;
            foreach ($candidate_ids as $cid) {
                $cp = get_post((int) $cid);
                if ($cp && $cp->post_status === 'publish' && $cp->post_type !== 'attachment') {
                    if ($unique_target === null) {
                        $unique_target = (int) $cid;
                    } elseif ($unique_target !== (int) $cid) {
                        $unique_target = null;
                        break;
                    }
                }
            }
            if ($unique_target !== null) {
                $post_id = $unique_target;
                $slug_used_for_lookup = $rest;
            }
        }
    }

    // CPT archive fallback: check if the segment matches an archive slug that differs from the
    // post type name (e.g. post type 'service' with has_archive/rewrite slug 'services').
    // Only a single-segment path can be a CPT archive root.
    if (!$post_id && strpos($rest, '/') === false) {
        $cpt_archive_type = ai_translate_detect_cpt_archive($rest);
        if ($cpt_archive_type) {
            $wp->query_vars = array_diff_key($wp->query_vars, ['ai_translate_path' => 1, 'name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
            $wp->query_vars['post_type'] = $cpt_archive_type;
            $wp->is_archive  = true;
            $wp->is_singular = false;
            $wp->is_404      = false;
            return;
        }
    }

    if ($post_id) {
        // Get the post object for further processing
        $post = get_post((int) $post_id);

        // Normalize percent-encoded URL parts to UTF-8 before comparing. Slugs with
        // non-ASCII characters (e.g. legacy 'cannelé') arrive %-encoded from the
        // browser while the DB stores literal UTF-8; comparing raw strings made
        // logically identical URLs look different, causing 301s to the same URL
        // (redirect loop: "page isn't redirecting properly").
        $ai_url_norm = static function ($value) {
            $value = (string) $value;
            if (strpos($value, '%') !== false) {
                $decoded = rawurldecode($value);
                if (mb_check_encoding($decoded, 'UTF-8')) {
                    return $decoded;
                }
            }
            return $value;
        };

        // Check if we found the post via the correct translated slug, or via fallback.
        // Also enforce the CPT rewrite-slug prefix (e.g. /en/witch/ → /en/urun/witch/).
        $correct_translated_slug = \AITranslate\AI_Slugs::get_or_generate($post_id, $lang);
        $found_via_correct_slug = ($correct_translated_slug && $ai_url_norm($slug_used_for_lookup) === $ai_url_norm($correct_translated_slug));

        if ($correct_translated_slug && $post) {
            $cpt_prefix = ai_translate_cpt_path_prefix((string) $post->post_type);
            $correct_url = home_url('/' . $lang . '/' . $cpt_prefix . $correct_translated_slug . '/');

            // Structure check: CPT URLs must include the rewrite slug prefix.
            $expected_rest = trim($cpt_prefix . $correct_translated_slug, '/');
            $current_rest_norm = $ai_url_norm(trim($rest, '/'));
            $structure_ok = ($current_rest_norm === $ai_url_norm($expected_rest));

            if (!$found_via_correct_slug || !$structure_ok) {
                // Re-attach endpoint/pagination suffix so e.g. order-received and
                // my-account endpoints survive the canonical redirect.
                if ($suffix_info['suffix'] !== '') {
                    $correct_url .= $suffix_info['suffix'] . '/';
                }

                // Only redirect if the URL is actually different in decoded form;
                // redirecting to a URL that only differs in percent-encoding loops forever.
                $current_url = home_url($path);
                if (untrailingslashit($ai_url_norm($correct_url)) !== untrailingslashit($ai_url_norm($current_url))) {
                    // Preserve the query string (e.g. ?key=wc_order_... on order-received).
                    $query = (string) wp_parse_url($reqUriRaw, PHP_URL_QUERY);
                    if ($query !== '') {
                        $correct_url .= '?' . $query;
                    }
                    nocache_headers();
                    wp_safe_redirect($correct_url, 301);
                    exit;
                }
            }
        }
        $wp->query_vars = array_diff_key($wp->query_vars, ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
        $posts_page_id = (int) get_option('page_for_posts');

        if ($expected_post_type && post_type_exists($expected_post_type)) {
            // Custom post type
            $wp->query_vars['post_type'] = $expected_post_type;
            $wp->query_vars['name'] = $post->post_name;
            $wp->is_singular = true;
            $wp->is_single = true;
        } elseif ($post && $post->post_type === 'page') {
            if ($posts_page_id > 0 && (int)$post_id === $posts_page_id) {
                // Treat posts page as home (blog index) so pagination works
                $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
                $wp->is_home = true;
                $wp->is_page = false;
                $wp->is_singular = false;
            } else {
                $wp->query_vars['page_id'] = (int) $post_id;
                $wp->is_page = true;
                $wp->is_singular = true;
            }
        } else {
            // Regular post
            $wp->query_vars['p'] = (int) $post_id;
            if ($post && !empty($post->post_type)) {
                $wp->query_vars['post_type'] = $post->post_type;
            }
            $wp->is_single = true;
            $wp->is_singular = true;
        }
        // Apply the endpoint/pagination suffix (e.g. WooCommerce 'orders',
        // 'order-received/123', or 'page/2' on the shop page).
        if ($suffix_info['endpoint'] !== null) {
            $wp->query_vars[$suffix_info['endpoint']] = $suffix_info['value'];
        } elseif ($suffix_info['paged'] > 1) {
            $wp->query_vars['paged'] = $suffix_info['paged'];
        }
        $wp->is_404 = false;
        return;
    }

    // Replicate org behaviour: strip current language from path and map basename back to source
    $default = \AITranslate\AI_Lang::default();
    $clean = preg_replace('#^' . preg_quote($lang, '#') . '/#i', '', ltrim($rest, '/'));
    $clean = trim($clean, '/');
    $basename = trim(basename($clean), '/');
    if ($basename !== '') {
        $srcBase = \AITranslate\AI_Slugs::resolve_any_to_source_slug($basename);
        if ($srcBase) {
            $dir = trim((string) dirname($clean), '/');
            if ($dir === '\\' || $dir === '.') {
                $dir = '';
            }
            $sourcePath = $dir !== '' ? ($dir . '/' . $srcBase) : $srcBase;

            // Resolve across all public post types (page, post, CPT)
            $public_types = get_post_types(['public' => true], 'names');
            $post = get_page_by_path($sourcePath, OBJECT, array_values($public_types));
            if ($post) {
                $wp->query_vars = array_diff_key($wp->query_vars, ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1]);
                $posts_page_id = (int) get_option('page_for_posts');
                if ($post->post_type === 'page') {
                    if ($posts_page_id > 0 && (int)$post->ID === $posts_page_id) {
                        $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
                        $wp->is_home = true;
                        $wp->is_page = false;
                        $wp->is_singular = false;
                    } else {
                        $wp->query_vars['page_id'] = (int) $post->ID;
                        $wp->is_page = true;
                    }
                } else {
                    $wp->query_vars['p'] = (int) $post->ID;
                    $wp->query_vars['post_type'] = $post->post_type;
                    $wp->is_single = true;
                }
                $wp->is_singular = true;
                $wp->is_404 = false;
                return;
            }

            // Fallback: let WP try resolving as page path
            $wp->query_vars['pagename'] = $sourcePath;
            // Do not force 404 state here; allow core to resolve properly
        }
    }

    // Fallback als er geen slug-mapregel is (pagina nog nooit vertaald): zoek op post_name (bron) via get_page_by_path.
    // Zonder dit geeft /{lang}/{bron-slug}/ 404 en faalt warm cache.
    if ($rest !== '') {
        $public_types = get_post_types(['public' => true], 'names');
        $public_types = array_diff((array) $public_types, ['attachment']);
        if (empty($public_types)) {
            $public_types = ['post', 'page'];
        }
        $public_types = array_values($public_types);

        $post = null;
        if (strpos($rest, '/') !== false) {
            $parts = explode('/', $rest, 2);
            $first = $parts[0];
            foreach (get_post_types(['public' => true, '_builtin' => false], 'objects') as $pt) {
                if (!empty($pt->rewrite['slug']) && $pt->rewrite['slug'] === $first) {
                    $post = get_page_by_path($parts[1], OBJECT, [$pt->name]);
                    break;
                }
            }
            if (!$post) {
                $post = get_page_by_path($rest, OBJECT, $public_types);
            }
        } else {
            $post = get_page_by_path($rest, OBJECT, $public_types);
        }
        if (!$post) {
            $resolved_term = ai_translate_resolve_term_from_path($rest);
            if ($resolved_term) {
                $wp->query_vars = array_diff_key($wp->query_vars, ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
                $wp->query_vars = ai_translate_apply_resolved_term_to_vars($wp->query_vars, $resolved_term);
                $wp->is_404 = false;
                return;
            }
        }
        if ($post && isset($post->ID) && isset($post->post_type) && $post->post_type !== 'attachment') {
            $wp->query_vars = array_diff_key($wp->query_vars, ['name' => 1, 'pagename' => 1, 'page_id' => 1, 'p' => 1, 'post_type' => 1]);
            $posts_page_id = (int) get_option('page_for_posts');
            if ($post->post_type === 'page') {
                if ($posts_page_id > 0 && (int) $post->ID === $posts_page_id) {
                    $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
                    $wp->is_home = true;
                    $wp->is_page = false;
                    $wp->is_singular = false;
                } else {
                    $wp->query_vars['page_id'] = (int) $post->ID;
                    $wp->is_page = true;
                    $wp->is_singular = true;
                }
            } else {
                $wp->query_vars['p'] = (int) $post->ID;
                $wp->query_vars['post_type'] = $post->post_type;
                $wp->is_single = true;
                $wp->is_singular = true;
            }
            $wp->is_404 = false;
            return;
        }
    }

    // No resolution found for this language-prefixed URL.
    // Remove the rewrite-generated pagename (e.g. "es/bestaat-niet") to prevent
    // WordPress from falling back to the blog index instead of properly triggering a 404.
    unset($wp->query_vars['pagename']);
    $wp->query_vars['error'] = '404';
});
