<?php

/**
 * The "request" filter that maps translated paths to WordPress query vars, request-context checks and Vary headers.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Map ai_path to WordPress internal query vars.
 */
add_filter('request', function ($vars) {
    // Hard skip for admin paths
    $req = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
    if ($req !== '' && (strpos($req, '/wp-admin/') !== false || strpos($req, 'wp-login.php') !== false)) {
        return $vars;
    }
    // Fallback: if rewrite did not set ai_translate_path (e.g. URL with %), extract path from REQUEST_URI
    if (empty($vars['ai_translate_path']) && !empty($req) && preg_match('#^/([a-z]{2})/([^?]+)#i', $req, $m)) {
        $path_from_uri = ai_translate_strip_site_path((string) wp_parse_url($req, PHP_URL_PATH));
        if ($path_from_uri !== '' && preg_match('#^/([a-z]{2})(?:/(.*))?$#i', $path_from_uri, $path_m)) {
            $vars['lang'] = strtolower($path_m[1]);
            $vars['ai_translate_path'] = isset($path_m[2]) ? trim($path_m[2], '/') : '';
        }
    }
    // ai_translate_path: the generic rewrite rule sets ai_translate_path for /{lang}/{path}/.
    // Resolve to post before the main query so the correct page_id or p+post_type is included.
    if (!empty($vars['ai_translate_path']) && !empty($vars['lang'])) {
        $path = trim((string) $vars['ai_translate_path'], '/');
        $lang = (string) $vars['lang'];
        // Split off a rewrite-endpoint or /page/N suffix (e.g. 'my-account/orders',
        // 'shop/page/2') so the base path can be resolved via the slug map.
        $suffix_info = ai_translate_split_endpoint_suffix($path);
        $resolve_path = ($suffix_info['suffix'] !== '' && $suffix_info['base'] !== '') ? $suffix_info['base'] : $path;
        $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $resolve_path);
        if (!$post_id && strpos($resolve_path, '/') !== false) {
            $base = basename($resolve_path);
            $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $base);
            if (!$post_id) {
                // Generic fallback: try to find post by translated slug (prefers non-attachments)
                $post_id = \AITranslate\AI_Slugs::resolve_translated_slug_to_post($base, $lang);
            }
        }
        if ($post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_status === 'publish') {
                if ($post->post_type === 'page') {
                    $vars['page_id'] = (int) $post->ID;
                } else {
                    $vars['p'] = (int) $post->ID;
                    $vars['post_type'] = $post->post_type;
                }
                if ($suffix_info['endpoint'] !== null) {
                    $vars[$suffix_info['endpoint']] = $suffix_info['value'];
                } elseif ($suffix_info['paged'] > 1) {
                    $vars['paged'] = $suffix_info['paged'];
                }
            }
            unset($vars['ai_translate_path']);
        } else {
            // Path did not resolve to a post; try taxonomy (category, tag, custom tax).
            $resolved_term = ai_translate_resolve_term_from_path($path);
            if ($resolved_term) {
                $vars = ai_translate_apply_resolved_term_to_vars($vars, $resolved_term);
                unset($vars['ai_translate_path']);
            }
        }
    }
    // Handle 'lang' query var: map translated slug back to source for both pages and singles
    if (isset($vars['lang'])) {
        // pagename can be hierarchical: map EACH segment back to its source slug
        if (!empty($vars['pagename'])) {
            $pg = (string) $vars['pagename'];
            $segments = array_values(array_filter(explode('/', trim($pg, '/'))));
            if (!empty($segments)) {
                foreach ($segments as $i => $seg) {
                    $src = \AITranslate\AI_Slugs::resolve_any_to_source_slug((string) $seg);
                    if ($src) {
                        $segments[$i] = $src;
                    }
                }
                $vars['pagename'] = implode('/', $segments);
            }
        }
        if (!empty($vars['name'])) {
            $nm = (string) $vars['name'];
            // If post_type is specified, use it to resolve slug conflicts
            $expected_post_type = !empty($vars['post_type']) ? $vars['post_type'] : null;
            $src = \AITranslate\AI_Slugs::resolve_any_to_source_slug($nm, $expected_post_type);
            if (!$src && !empty($vars['lang'])) {
                // Generic fallback: find post by translated slug with post_type filter
                if ($expected_post_type) {
                    $post_id = \AITranslate\AI_Slugs::resolve_translated_slug_to_post_by_type($nm, (string) $vars['lang'], $expected_post_type);
                } else {
                    $post_id = \AITranslate\AI_Slugs::resolve_translated_slug_to_post($nm, (string) $vars['lang']);
                }
                if ($post_id) {
                    $post = get_post($post_id);
                    if ($post && $post->post_status === 'publish') {
                        $src = $post->post_name;
                    }
                }
            }
            if ($src) {
                $vars['name'] = $src;
            }
        }
    }
    return $vars;
});

/**
 * Detect page-builder editor / preview context (Elementor, Divi, Beaver, WPBakery, Oxygen,
 * Bricks, Breakdance, Brizy, Thrive) and WordPress core post preview.
 *
 * Editor previews are loaded inside an iframe by a logged-in admin and depend on the
 * original (untranslated) URL plus query parameters. The plugin must NOT redirect or
 * translate these requests, otherwise the editor loses preview parameters and the
 * builder reports a session error.
 *
 * @return bool True when the current request is a builder/preview context.
 */
function ai_translate_is_editor_context()
{
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only detection only.
    static $is_editor = null;
    if ($is_editor !== null) {
        return $is_editor;
    }

    // Known, builder-specific query parameters (verified in each builder's source).
    $builder_params = array(
        'elementor-preview',         // Elementor editor preview iframe
        'elementor_library',         // Elementor library preview
        'et_fb',                     // Divi Visual Builder
        'et_pb_preview',             // Divi preview
        'fl_builder',                // Beaver Builder
        'vc_editable',               // WPBakery / Visual Composer
        'vc_action',                 // WPBakery / Visual Composer
        'tve',                       // Thrive Architect
        'ct_builder',                // Oxygen Builder
        'oxygen',                    // Oxygen Builder loader URL
        'bricks',                    // Bricks Builder
        'breakdance',                // Breakdance Builder loader URL (?breakdance=builder)
        'breakdance_iframe',         // Breakdance Builder iframe
        'breakdance_open_document',  // Breakdance render document
        '_breakdance_doing_ajax',    // Breakdance frontend AJAX (POST /?page_id=X&_breakdance_doing_ajax=yes)
        'brizy-edit',                // Brizy Builder
        'brizy-edit-iframe',         // Brizy Builder iframe
        'uxb_iframe',                // Flatsome UX Builder iframe (?post_id=X&uxb_iframe=1)
        'td_action',                 // Newspaper tagDiv Composer edit mode (?td_action=tdc)
        'tdc_action',                // Newspaper tagDiv Composer iframe (?tdc_action=iframe)
        'fb-edit',                   // Avada Live frontend builder (?fb-edit=1)
    );
    foreach ($builder_params as $param) {
        if (isset($_GET[$param])) {
            $is_editor = true;
            return true;
        }
    }

    // WordPress core post preview (preview=true and/or preview_id) — only for users
    // that can actually edit, to avoid abuse via crafted query strings on public URLs.
    $has_preview_param = (
        (isset($_GET['preview']) && (string) $_GET['preview'] !== 'false')
        || isset($_GET['preview_id'])
        || isset($_GET['preview_nonce'])
    );
    if ($has_preview_param && is_user_logged_in()) {
        $is_editor = true;
        return true;
    }

    // Generic fallback: any iframe loaded from /wp-admin/ by a user with edit capability.
    // Sec-Fetch-Dest is sent by all modern browsers (Chrome 80+, Firefox 90+, Safari 16.4+).
    // Combination of iframe destination + wp-admin Referer + edit capability uniquely
    // identifies a builder preview iframe, so unknown/future builders are also covered
    // without us needing to learn each builder's specific query parameter.
    if (is_user_logged_in() && function_exists('current_user_can') && current_user_can('edit_posts')) {
        $sec_fetch_dest = isset($_SERVER['HTTP_SEC_FETCH_DEST'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_SEC_FETCH_DEST']))
            : '';
        if ($sec_fetch_dest === 'iframe' || $sec_fetch_dest === 'frame') {
            $referer = wp_get_referer();
            if ($referer && strpos((string) $referer, '/wp-admin/') !== false) {
                $is_editor = true;
                return true;
            }
        }
    }

    $is_editor = false;
    return false;
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Check if current request is an XML file (sitemap, robots.txt, etc.).
 *
 * @return bool
 */
function ai_translate_is_xml_request()
{
    $reqPath = ai_translate_request_path();

    // Check if path ends with .xml
    if (preg_match('/\.xml$/i', $reqPath)) {
        return true;
    }

    // Check for WordPress sitemap query vars
    if (isset($_GET['sitemap']) || isset($_GET['sitemap-index'])) {
        return true;
    }

    // Check for sitemap in path (e.g., /wp-sitemap.xml, /sitemap.xml)
    if (preg_match('/sitemap/i', $reqPath)) {
        return true;
    }

    return false;
}

/**
 * Check if the current request's User-Agent is a known search engine crawler.
 * Used to keep bots on the default language instead of following browser-language
 * auto-detection (crawlers rarely send a meaningful Accept-Language header, and
 * should never trigger/see a language redirect for SEO consistency).
 *
 * @return bool
 */
function ai_translate_is_known_bot_ua()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])) : '';
    return $ua !== '' && (bool) preg_match('/googlebot|googleother|bingbot|yandexbot|baiduspider|duckduckbot|slurp|facebot|ia_archiver/i', $ua);
}

/**
 * Whether a URL (Referer/Origin) belongs to this site.
 * Compares host (and subdirectory path when applicable). Rejects prefix tricks
 * like https://example.com.attacker.tld/ that pass a naive strpos(home_url()) check.
 *
 * @param string $url Absolute URL or Origin header value.
 * @return bool
 */
function ai_translate_is_same_site_url($url)
{
    $url = esc_url_raw(trim((string) $url));
    if ($url === '') {
        return false;
    }

    $home_parts = wp_parse_url(home_url('/'));
    $url_parts = wp_parse_url($url);
    if (!is_array($home_parts) || !is_array($url_parts)) {
        return false;
    }

    $home_host = isset($home_parts['host']) ? strtolower((string) $home_parts['host']) : '';
    $url_host = isset($url_parts['host']) ? strtolower((string) $url_parts['host']) : '';
    if ($home_host === '' || $url_host === '' || $home_host !== $url_host) {
        return false;
    }

    $home_scheme = isset($home_parts['scheme']) ? strtolower((string) $home_parts['scheme']) : 'http';
    $url_scheme = isset($url_parts['scheme']) ? strtolower((string) $url_parts['scheme']) : '';
    // Origin headers always include a scheme; Referer should too. Only http/https allowed.
    if ($url_scheme === '' || !in_array($url_scheme, ['http', 'https'], true) || !in_array($home_scheme, ['http', 'https'], true)) {
        return false;
    }

    $home_port = isset($home_parts['port']) ? (int) $home_parts['port'] : null;
    $url_port = isset($url_parts['port']) ? (int) $url_parts['port'] : null;
    if ($home_port !== $url_port) {
        return false;
    }

    // Subdirectory installs: URL path must stay under the site path.
    $home_path = isset($home_parts['path']) ? untrailingslashit((string) $home_parts['path']) : '';
    if ($home_path !== '' && $home_path !== '/') {
        $url_path = isset($url_parts['path']) ? (string) $url_parts['path'] : '/';
        if ($url_path !== $home_path && strpos($url_path, $home_path . '/') !== 0) {
            return false;
        }
    }

    return true;
}

/**
 * Add Accept-Language/Cookie to the Vary header on language-detection-based redirects.
 *
 * These redirects (browser-language auto-detect, cookie-based root/path redirects) decide
 * their target based on request headers, not just the URL. A shared cache (CDN/reverse proxy)
 * that does not honor Cache-Control but does honor Vary will otherwise reuse one visitor's
 * redirect for every other visitor of the same URL. This is defense-in-depth alongside the
 * existing nocache_headers() call, not a replacement for it.
 *
 * @return void
 */
function ai_translate_add_vary_headers()
{
    if (headers_sent()) {
        return;
    }
    $existing = [];
    foreach (headers_list() as $h) {
        if (stripos($h, 'Vary:') === 0) {
            $existing = array_map('trim', explode(',', substr($h, strlen('Vary:'))));
            break;
        }
    }
    $merged = array_values(array_unique(array_filter(array_merge($existing, ['Accept-Language', 'Cookie']))));
    header('Vary: ' . implode(', ', $merged));
}
