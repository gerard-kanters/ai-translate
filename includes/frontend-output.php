<?php

/**
 * Front-end output: _wp_old_slug and language redirects on template_redirect, head tags, output buffering, inline scripts.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Start output buffering for front-end (skip admin, AJAX, REST, feeds and XML files).
 * Handles language detection and redirection according to the 4 language switcher rules.
 */
// Check for _wp_old_slug redirects before other plugin logic (priority 1).
// Request path is checked first: if it matches an old slug in DB, redirect. Do not rely on is_404
// because themes/plugins may serve the homepage instead of 404 for unknown slugs.
add_action('template_redirect', function () {
    if (!isset($_SERVER['REQUEST_URI'])) {
        return;
    }
    $request_uri = esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']));
    $request_path = ai_translate_strip_site_path((string) wp_parse_url($request_uri, PHP_URL_PATH));
    if (!is_string($request_path) || $request_path === '' || $request_path === '/') {
        return;
    }
    // Skip if URL has language prefix (let AI Translate handle those)
    if (preg_match('#^/([a-z]{2})(?:/|$)#i', $request_path)) {
        return;
    }
    // Single-segment path (e.g. /programmeren-met-een-ai-agen/) – might be an old slug
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
}, 1);

/**
 * Conservative 404 recovery: when a request would otherwise serve a 404, look
 * up the basename in the slug map (any language) and redirect to the canonical
 * translated URL when there is exactly one safe match. Only acts on
 * /{lang}/... paths and never triggers a new translation. See
 * docs/404-herstelplan-op-basis-van-url.md (Fase 1).
 *
 * Runs at priority 999 so it executes after all other template_redirect
 * callbacks have had a chance to resolve or fix the request.
 */
add_action('template_redirect', function () {
    if (!is_404()) {
        return;
    }
    if (is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || ai_translate_is_xml_request()) {
        return;
    }
    if (!isset($_SERVER['REQUEST_URI'])) {
        return;
    }
    $reqUriRaw = esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']));
    if (strpos($reqUriRaw, '%25') !== false) {
        $reqUriRaw = urldecode($reqUriRaw);
    }
    $request_path = ai_translate_strip_site_path((string) (wp_parse_url($reqUriRaw, PHP_URL_PATH) ?: '/'));

    $target_url = \AITranslate\AI_404_Recovery::resolve($request_path);
    if (!is_string($target_url) || $target_url === '') {
        return;
    }

    nocache_headers();
    wp_safe_redirect(esc_url_raw($target_url), 301);
    exit;
}, 999);

add_action('template_redirect', function () {
    // Handle ai_translate_path query var for custom URL resolution
    if (isset($_GET['ai_translate_path'])) {
        $path = sanitize_text_field(wp_unslash($_GET['ai_translate_path']));
        // Remove the query var so WordPress doesn't see it
        unset($_GET['ai_translate_path']);

        // Try to resolve the path without language prefix
        $resolved_post = \AITranslate\AI_Slugs::resolve_path_to_post('', $path);
        if ($resolved_post) {
            // Set the resolved post ID so WordPress loads the correct content
            global $wp_query;
            $wp_query->set('p', $resolved_post);
            $wp_query->set('post_type', 'any');
            // Make sure WordPress knows this is a singular post
            $wp_query->is_singular = true;
            $wp_query->is_single = true;
            $wp_query->is_archive = false;
            $wp_query->is_page = false;
        }
    }

    // Skip admin/AJAX/REST/feeds/XML files
    if (is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || ai_translate_is_xml_request()) {
        return;
    }

    // Skip page-builder editor/preview iframes (Elementor, Divi, Beaver, ...) and WP post preview.
    // These are loaded by a logged-in admin and rely on the original URL + query parameters.
    // Redirecting them to /{lang}/ would drop the preview parameters and break the editor
    // ("Session expired" in Elementor). The frontend is rendered as-is in the default language.
    if (ai_translate_is_editor_context()) {
        return;
    }

    $reqPathRaw = esc_url_raw(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
    // URL decode only if double-encoded (contains %25 indicating double encoding)
    if (strpos($reqPathRaw, '%25') !== false) {
        $reqPathRaw = urldecode($reqPathRaw);
    }
    $reqPath = ai_translate_strip_site_path((string) wp_parse_url($reqPathRaw, PHP_URL_PATH));
    $defaultLang = \AITranslate\AI_Lang::default();
    
    // CRITICAL: If we're on /{default}/ and cookie doesn't match, render page FIRST
    // This prevents redirect before cookie is set
    if ($reqPath !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $reqPath, $m)) {
        $langFromUrl = strtolower(sanitize_key($m[1]));
        if ($defaultLang && strtolower($langFromUrl) === strtolower((string) $defaultLang)) {
            $cookieLang = isset($_COOKIE['ai_translate_lang']) ? strtolower(sanitize_key((string) $_COOKIE['ai_translate_lang'])) : '';
            // If cookie doesn't match, set language and render page (don't redirect yet)
            if ($cookieLang !== '' && strtolower($cookieLang) !== strtolower((string) $defaultLang)) {
                \AITranslate\AI_Lang::set_cookie((string) $defaultLang);
                \AITranslate\AI_Lang::set_current((string) $defaultLang);
                // Add JavaScript redirect to site root after page renders
                add_action('wp_footer', function() {
                    echo '<script>window.location.replace(' . wp_json_encode(home_url('/')) . ');</script>';
                }, 999);
                // Continue to render page - Rule 3 below will be skipped
                // Set final language and start OB
                $finalLang = (string) $defaultLang;
                \AITranslate\AI_Lang::set_current($finalLang);
                add_filter('locale', function ($locale) use ($finalLang) {
                    return strtolower($finalLang) . '_' . strtoupper($finalLang);
                });
                \AITranslate\AI_OB::instance()->start();
                return; // Stop here - don't execute Rule 3 redirect
            }
        }
    }

    // Check if we just switched languages - switcher has HIGHEST priority!

    // Extract language from URL
    $langFromUrl = null;
    if ($reqPath !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $reqPath, $m)) {
        $langFromUrl = strtolower(sanitize_key($m[1]));
        
        // SECURITY: Prevent unauthorized translation by blocking non-enabled/non-detectable languages
        // If language is in URL but not enabled or detectable, return 404 to prevent API costs
        if ($langFromUrl !== null && $defaultLang && strtolower($langFromUrl) !== strtolower((string) $defaultLang)) {
            $enabled = \AITranslate\AI_Lang::enabled();
            $detectable = \AITranslate\AI_Lang::detectable();
            $langLower = strtolower($langFromUrl);
            $isEnabled = in_array($langLower, array_map('strtolower', $enabled), true);
            $isDetectable = in_array($langLower, array_map('strtolower', $detectable), true);
            
            // If language is not enabled and not detectable, return 404 to prevent unauthorized translation
            if (!$isEnabled && !$isDetectable) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
                return;
            }
            
            // SECURITY: Prevent DOS attack by blocking language/slug mismatches.
            // Only flag real non-Latin *scripts* (Cyrillic/Greek/Arabic/Hebrew/CJK/…),
            // never Latin Extended (Maltese ħ, French é, etc.). The old
            // [\x{0080}-\x{FFFF}] + incomplete accent-lang whitelist falsely 404'd
            // /mt/…ħ…/ → AI_404_Recovery self-redirect loop (curl: max redirects).
            $pathWithoutLang = preg_replace('#^/([a-z]{2})/#i', '/', $reqPath);
            if ($pathWithoutLang !== $reqPath) {
                $decodedPath = rawurldecode((string) $pathWithoutLang);
                // Scripts matching AI_Lang::non_latin_codes(): el, Cyrillic family,
                // he, ar, hi, th, ka, ja kana, CJK, ko Hangul.
                $hasNonLatinScript = (bool) preg_match(
                    '/[\x{0370}-\x{03FF}\x{0400}-\x{052F}\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0900}-\x{097F}\x{0E00}-\x{0E7F}\x{10A0}-\x{10FF}\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u',
                    $decodedPath
                );

                if ($hasNonLatinScript && !\AITranslate\AI_Lang::is_non_latin($langLower)) {
                    global $wp_query;
                    $wp_query->set_404();
                    status_header(404);
                    nocache_headers();
                    return;
                }
            }
        }
    }

    // RULE 0: Strip explicit ?lang= query parameter from URL → redirect to clean path-based URL
    // The ?lang= parameter is only used internally by rewrite rules, never in actual visitor URLs.
    // Examples: /es?lang=fr → /es/  |  /?lang=es → /es/  |  /?lang={default} → /
    if (isset($_GET['lang']) && trim(sanitize_text_field(wp_unslash((string) $_GET['lang']))) !== '') {
        $cleanParams = array_map(static function ($v) {
            return is_string($v) ? sanitize_text_field(wp_unslash($v)) : $v;
        }, $_GET);
        unset($cleanParams['lang']);

        $targetPath = $reqPath;
        if ($langFromUrl === null) {
            // No language in path yet — use the GET parameter to build the correct path
            $getLang = strtolower(sanitize_key((string) $_GET['lang']));
            if ($getLang !== '' && $defaultLang && strtolower($getLang) !== strtolower((string) $defaultLang)) {
                $targetPath = '/' . $getLang . '/';
            }
        }

        // Ensure trailing slash (except bare root)
        if ($targetPath !== '/' && substr($targetPath, -1) !== '/') {
            $targetPath .= '/';
        }

        $targetUrl = home_url($targetPath);
        if (!empty($cleanParams)) {
            $targetUrl = add_query_arg($cleanParams, $targetUrl);
        }

        nocache_headers();
        wp_safe_redirect(esc_url_raw($targetUrl), 301);
        exit;
    }

    $cookieLang = isset($_COOKIE['ai_translate_lang']) ? strtolower(sanitize_key((string) $_COOKIE['ai_translate_lang'])) : '';

    // Ensure search requests have language-prefixed URL (before RULE 4 to prevent early return)
    // Check for search parameter 's' in query string
    $hasSearchParam = isset($_GET['s']) && trim(sanitize_text_field(wp_unslash((string) $_GET['s']))) !== '';
    if ($hasSearchParam) {
        // Use cookie language if available, otherwise use URL language, otherwise default
        $searchLang = $cookieLang !== '' ? $cookieLang : ($langFromUrl !== null ? $langFromUrl : (string) $defaultLang);
        if ($searchLang !== '' && $defaultLang && strtolower($searchLang) !== strtolower((string) $defaultLang)) {
            $reqUriRaw = esc_url_raw(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
            // URL decode only if double-encoded (contains %25 indicating double encoding)
            if (strpos($reqUriRaw, '%25') !== false) {
                $reqUriRaw = urldecode($reqUriRaw);
            }
            $pathNow = ai_translate_strip_site_path((string) wp_parse_url($reqUriRaw, PHP_URL_PATH));
            if ($pathNow === '' || $pathNow === '/' || !preg_match('#^/([a-z]{2})(?:/|$)#i', $pathNow)) {
                $base = home_url('/' . $searchLang . '/');
                $target = add_query_arg($_GET, $base);
                nocache_headers();
                wp_safe_redirect($target, 302);
                exit;
            }
        }
    }

    // RULE 3: Canonicalize default language - strip /{default}/ prefix, keep the rest.
    // /{default}/ → /  and  /{default}/product/x/ → /product/x/
    // (Previously always redirected to /, which broke deep default-language URLs
    // and could bounce against WooCommerce/CPT canonical redirects.)
    if ($langFromUrl !== null && $defaultLang && strtolower($langFromUrl) === strtolower((string) $defaultLang)) {
        if ($reqPath !== '/') {
            \AITranslate\AI_Lang::set_cookie((string) $defaultLang);
            \AITranslate\AI_Lang::set_current((string) $defaultLang);
            $stripped = preg_replace('#^/' . preg_quote($langFromUrl, '#') . '(?=/|$)#i', '', $reqPath);
            if (!is_string($stripped) || $stripped === '') {
                $stripped = '/';
            }
            if ($stripped !== '/' && substr($stripped, -1) !== '/') {
                $stripped .= '/';
            }
            nocache_headers();
            wp_safe_redirect(home_url($stripped), 302);
            exit;
        }
    }

    // RULE 4: If cookie exists and on root, redirect to cookie language or serve default
    if ($reqPath === '/' && $cookieLang !== '' && !$hasSearchParam) {
        if ($defaultLang && strtolower($cookieLang) !== strtolower((string) $defaultLang)) {
            // Returning visitor with non-default language preference - redirect to their preferred URL
            nocache_headers();
            ai_translate_add_vary_headers();
            wp_safe_redirect(home_url('/' . $cookieLang . '/'), 302);
            exit;
        }
        // Cookie is set to default language - serve default language page
        $finalLang = (string) $defaultLang;
        \AITranslate\AI_Lang::set_current($finalLang);
        add_filter('locale', function ($locale) {
            $currentLang = \AITranslate\AI_Lang::current();
            if ($currentLang) {
                return strtolower($currentLang) . '_' . strtoupper($currentLang);
            }
            return $locale;
        });
        \AITranslate\AI_OB::instance()->start();
        return;
    }

    // RULE 5: Non-root pages without language prefix - redirect to cookie language URL
    // If visitor has a non-default language preference and visits a page without a language
    // prefix, redirect them to the language-prefixed version so they see it in their language.
    if ($langFromUrl === null && $reqPath !== '/' && $cookieLang !== '' && $defaultLang &&
        strtolower($cookieLang) !== strtolower((string) $defaultLang) && !$hasSearchParam) {
        nocache_headers();
        ai_translate_add_vary_headers();
        wp_safe_redirect(home_url('/' . $cookieLang . $reqPath), 302);
        exit;
    }

    $resolvedLang = null;

    // RULE 1: First visit (no cookie) - detect browser language and redirect
    if ($cookieLang === '' && $langFromUrl === null && !$hasSearchParam) {
        // Crawlers must never follow a browser-language redirect: their Accept-Language
        // header is often generic/absent, and they should consistently see the default
        // language for SEO/indexing consistency.
        if (ai_translate_is_known_bot_ua()) {
            $resolvedLang = (string) $defaultLang;
        } else {
            \AITranslate\AI_Lang::reset();
            \AITranslate\AI_Lang::detect();
            $detected = \AITranslate\AI_Lang::current();
            if (!$detected) {
                $detected = (string) $defaultLang;
            }

            $resolvedLang = $detected;

            // Set cookie (central helper handles domains/headers)
            \AITranslate\AI_Lang::set_cookie($detected);

            // Redirect to language URL if not default
            // IMPORTANT: prevent browsers/proxies from caching this 302 (incognito can get "stuck").
            // Vary is defense-in-depth: a shared cache that ignores Cache-Control but honors Vary
            // will still not reuse this visitor's redirect for a visitor with a different
            // Accept-Language/cookie state.
            if ($detected && strtolower($detected) !== strtolower((string) $defaultLang)) {
                nocache_headers();
                ai_translate_add_vary_headers();
                // For root: redirect to /{lang}/; for non-root: redirect to /{lang}{path}
                $targetPath = ($reqPath === '/') ? '/' . $detected . '/' : '/' . $detected . $reqPath;
                wp_safe_redirect(home_url($targetPath), 302);
                exit;
            }
            // If detected == default, no redirect needed, just set language below
        }
    } elseif ($reqPath === '/' && $cookieLang !== '') {
        // Returning visitor on root: respect stored preference
        $resolvedLang = $cookieLang;

        if ($defaultLang && strtolower($cookieLang) !== strtolower((string) $defaultLang)) {
            nocache_headers();
            ai_translate_add_vary_headers();
            wp_safe_redirect(home_url('/' . $cookieLang . '/'), 302);
            exit;
        }
    }

    // Sync cookie if URL has language prefix
    if ($langFromUrl !== null && ($cookieLang === '' || $cookieLang !== $langFromUrl)) {
        \AITranslate\AI_Lang::set_cookie($langFromUrl);
    }

    // Set language for content
    // IMPORTANT: If URL has a language code, use it. Otherwise, use default language.
    // Cookie should ONLY influence behavior on root /, not on other pages!
    $finalLang = $resolvedLang;
    if ($finalLang === null) {
        // If URL has language code, use it. Otherwise, use default.
        // Do NOT use cookie for pages without language code in URL!
        if ($langFromUrl !== null) {
            $finalLang = $langFromUrl;
        } else {
            $finalLang = (string) $defaultLang;
        }
    }
    if ($finalLang === '') {
        $finalLang = (string) $defaultLang;
    }
    $finalLang = strtolower(sanitize_key((string) $finalLang));
    if ($finalLang !== $cookieLang && $finalLang !== '') {
        \AITranslate\AI_Lang::set_cookie($finalLang);
    }

    \AITranslate\AI_Lang::set_current($finalLang);

    // Set WordPress locale
    $localeSetter = function ($locale) use ($finalLang) {
        if ($finalLang) {
            return strtolower($finalLang) . '_' . strtoupper($finalLang);
        }
        return $locale;
    };
    add_filter('locale', $localeSetter);

    // Handle full_reset parameter for testing
    if (isset($_GET['full_reset']) && $_GET['full_reset'] === '1') {
        \AITranslate\AI_Lang::set_cookie((string) $defaultLang);
        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    // Ensure search requests have language-prefixed URL (fallback for is_search() check)
    if (function_exists('is_search') && is_search()) {
        $cur = \AITranslate\AI_Lang::current();
        $def = \AITranslate\AI_Lang::default();
        $pathNow = ai_translate_strip_site_path((string) wp_parse_url(esc_url_raw(wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? ''))), PHP_URL_PATH));
        if ($cur && $def && strtolower($cur) !== strtolower($def)) {
            if ($pathNow === '' || $pathNow === '/' || !preg_match('#^/([a-z]{2})(?:/|$)#i', $pathNow)) {
                $base = home_url('/' . $cur . '/');
                $target = add_query_arg($_GET, $base);
                wp_safe_redirect($target, 302);
                exit;
            }
        }
    }

    \AITranslate\AI_OB::instance()->start();
}, 5); // Priority 5 to run AFTER preloader plugins (e.g. Safelayout Cute Preloader at 2).
// Preloader must ob_start first so it injects into AI-Translate output, not vice versa.

/**
 * Client-side cookie-based language redirect fallback.
 *
 * When a full-page cache (Kinsta, CDN, Varnish) serves a cached HTML page, PHP
 * never runs and server-side redirect rules (RULE 4/5 in template_redirect) cannot
 * fire. This inline <head> script detects a non-default ai_translate_lang cookie on
 * a URL without a language prefix and performs a client-side redirect — bypassing
 * the cache layer entirely and restoring expected behavior for returning visitors.
 */
add_action('wp_head', function () {
    $default = \AITranslate\AI_Lang::default();
    if ($default === '') {
        return;
    }
    $default = strtolower(sanitize_key($default));
?>
<script>
(function(){
  var c=document.cookie.match(/(?:^|; )ai_translate_lang=([^;]+)/);
  if(!c)return;
  var lang=decodeURIComponent(c[1]).toLowerCase();
  if(lang==="<?php echo esc_js($default); ?>")return;
  if(/^\/([a-z]{2})(?:\/|$)/i.test(location.pathname))return;
  var ua=navigator.userAgent||"";
  if(/googlebot|googleother|bingbot|yandexbot|baiduspider|duckduckbot|slurp|facebot|ia_archiver/i.test(ua))return;
  location.replace(location.origin+"/"+lang+location.pathname);
})();
</script>
<?php
}, -9999);

/**
 * Ensure that when viewing the posts index (translated posts page) with ?blogpage=N,
 * the main query uses paged=N so page 2+ actually render page 2+ instead of page 1.
 */
add_action('parse_request', ['\AITranslate\AI_URL', 'map_blog_pagination'], 100);

/**
 * Return the canonical root-relative path for the current page in the original/default language.
 * Bypasses post_link/page_link filters (which prefix the current language + translated slug)
 * by reading raw WordPress slugs via get_page_uri() / post_name directly.
 * Example: on /fr/actualites/ this returns /nieuws/ so all language links use the original slug.
 *
 * @param string $fallback Path to use when canonical URL cannot be determined.
 * @return string Root-relative path ending with '/'.
 */
function ai_translate_canonical_path($fallback) {
    if ($fallback === '/' || !function_exists('get_queried_object')) {
        return $fallback;
    }
    // Subfolder installs: treat the sub-directory root (e.g. '/moretti/') as '/'
    // so the homepage link resolves to '/' instead of the front-page slug (e.g. '/home/').
    $sub = ai_translate_site_path();
    if ($sub !== '' && (rtrim($fallback, '/') === $sub)) {
        return '/';
    }
    $obj = get_queried_object();
    $path = null;
    if ($obj instanceof WP_Post && isset($obj->ID)) {
        if ($obj->post_type === 'page') {
            // get_page_uri builds parent/child slug path without any permalink filter
            $uri = get_page_uri($obj->ID);
            if ($uri) { $path = '/' . trim($uri, '/') . '/'; }
        } else {
            // Raw post slug + CPT rewrite prefix (e.g. product/ → urun/) so the
            // language switcher keeps WooCommerce/CPT URLs routable.
            $slug = get_post_field('post_name', $obj->ID);
            if ($slug) {
                $prefix = function_exists('ai_translate_cpt_path_prefix')
                    ? ai_translate_cpt_path_prefix((string) $obj->post_type)
                    : '';
                $path = '/' . $prefix . trim((string) $slug, '/') . '/';
            }
        }
    } elseif ($obj instanceof WP_Term && isset($obj->term_id)) {
        // ai-translate has no term_link filter, safe to use
        $p = get_term_link($obj);
        if ($p && !is_wp_error($p)) {
            $tp = (string) wp_parse_url($p, PHP_URL_PATH);
            if ($tp && $tp !== '/') { $path = trailingslashit($tp); }
        }
    } elseif ($obj instanceof WP_Post_Type && isset($obj->name)) {
        $p = get_post_type_archive_link($obj->name);
        if ($p) {
            $tp = (string) wp_parse_url($p, PHP_URL_PATH);
            if ($tp && $tp !== '/') { $path = trailingslashit($tp); }
        }
    }
    return $path ?? $fallback;
}

/**
 * Render the floating language switcher with the shared frontend renderer.
 */
add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }
    $position = \AITranslate\AI_Translate_Core::switcher_position();
    $valid_positions = array('bottom-left', 'bottom-right', 'top-left', 'top-right', 'none');
    if (!in_array($position, $valid_positions, true)) {
        $position = 'bottom-left';
    }
    if ($position !== 'none') {
        echo ai_translate_generate_switcher_html(
            'dropdown',
            true,
            true,
            'ai-language-switcher-floating ai-language-switcher-pos-' . $position
        );
    }
});

/**
 * Translate dynamically rendered frontend strings on every frontend request.
 */
add_action('wp_footer', function () {
    if (is_admin()) {
        return;
    }
    $restUrl = esc_url_raw(rest_url('ai-translate/v1/batch-strings'));
    $nonce = wp_create_nonce('ai_translate_front_nonce');
    echo '<script>(function(){var AI_TA={u:"' . esc_url($restUrl) . '",n:"' . esc_js($nonce) . '"};
// Dynamic UI attribute translation (placeholder/title/aria-label/value of buttons)
function gL(){try{var m=location.pathname.match(/^\/([a-z]{2})(?:\/|$)/i);if(m){return (m[1]||"").toLowerCase();}var mc=document.cookie.match(/(?:^|; )ai_translate_lang=([^;]+)/);if(mc){return decodeURIComponent(mc[1]||"").toLowerCase();}}catch(e){}return "";}
function wR(r){var out=[];var roots=r.querySelectorAll?r.querySelectorAll("[class*=\"wp-block-woocommerce-\"],.wc-block-components-drawer,.woocommerce-message,.woocommerce-error,.woocommerce-info,.widget_shopping_cart_content"):[];roots.forEach(function(root){if(root.parentElement&&root.parentElement.closest&&root.parentElement.closest("[class*=\"wp-block-woocommerce-\"],.wc-block-components-drawer,.woocommerce-message,.woocommerce-error,.woocommerce-info,.widget_shopping_cart_content"))return;if(root.closest&&root.closest("[data-ai-trans-skip]"))return;out.push(root);});return out;}
function wT(root,cb){var w=document.createTreeWalker(root,NodeFilter.SHOW_TEXT,null);var t;while((t=w.nextNode())){var p=t.parentElement;if(!p)continue;var tg=(p.tagName||"").toLowerCase();if(tg==="script"||tg==="style")continue;if(p.closest&&p.closest("[data-ai-trans-skip]"))continue;cb(t);}}
function cS(r){function n(t){return t?t.trim().replace(/\s+/g," "):""}var s=new Set();var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label],img[alt],.initial-greeting,.chatbot-bot-text,.jp-relatedposts-post-title a,.jp-relatedposts-post-context,#jp-relatedposts .jp-relatedposts-headline"):[];wR(r).forEach(function(root){wT(root,function(t){var v=n(t.nodeValue);if(v&&v.length>1&&v.length<=150&&/[A-Za-z][A-Za-z]/.test(v))s.add(v);});});ns.forEach(function(el){if(el.closest?el.closest("[data-ai-trans-skip]"):el.hasAttribute("data-ai-trans-skip"))return;var ph=n(el.getAttribute("placeholder"));if(ph)s.add(ph);var tl=n(el.getAttribute("title"));if(tl)s.add(tl);var al=n(el.getAttribute("aria-label"));if(al)s.add(al);var at=n(el.getAttribute("alt"));if(at)s.add(at);var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=n(el.getAttribute("value"));if(v)s.add(v);}}var tc=el.textContent;var inJetpack=(el.closest&&(el.closest(".jp-relatedposts")||el.closest("#jp-relatedposts")));if((el.classList.contains("initial-greeting")||el.classList.contains("chatbot-bot-text")||inJetpack)&&tc){var tcn=n(tc);if(tcn)s.add(tcn);}});return Array.from(s);} 
 function aT(r,m){wR(r).forEach(function(root){wT(root,function(t){var v=t.nodeValue;if(!v)return;var vt=v.trim().replace(/\s+/g," ");if(vt&&m[vt]!=null&&m[vt]!==vt)t.nodeValue=m[vt];});});var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label],img[alt],.initial-greeting,.chatbot-bot-text,.jp-relatedposts-post-title a,.jp-relatedposts-post-context,#jp-relatedposts .jp-relatedposts-headline"):[];ns.forEach(function(el){if(el.closest?el.closest("[data-ai-trans-skip]"):el.hasAttribute("data-ai-trans-skip"))return;var ph=el.getAttribute("placeholder");if(ph){var pht=ph.trim();if(pht&&m[pht]!=null)el.setAttribute("placeholder",m[pht]);}var tl=el.getAttribute("title");if(tl){var tlt=tl.trim();if(tlt&&m[tlt]!=null)el.setAttribute("title",m[tlt]);}var al=el.getAttribute("aria-label");if(al){var alt=al.trim();if(alt&&m[alt]!=null)el.setAttribute("aria-label",m[alt]);}var at=el.getAttribute("alt");if(at){var att=at.trim();if(att&&m[att]!=null)el.setAttribute("alt",m[att]);}var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=el.getAttribute("value");if(v){var vt=v.trim();if(vt&&m[vt]!=null)el.setAttribute("value",m[vt]);}}}var tc=el.textContent;var inJetpack=(el.closest&&(el.closest(".jp-relatedposts")||el.closest("#jp-relatedposts")));if((el.classList.contains("initial-greeting")||el.classList.contains("chatbot-bot-text")||inJetpack)&&tc){var tct=tc.trim();if(tct&&m[tct]!=null)el.textContent=m[tct];}});} 
function tA(r){if(tA.called)return;tA.called=true;var ua=(typeof navigator!=="undefined"&&navigator.userAgent)?navigator.userAgent:"";if(/googlebot|googleother|bingbot|yandexbot|baiduspider|duckduckbot|slurp|facebot|ia_archiver/i.test(ua)){tA.called=false;return;}var ss=cS(r);if(!ss.length){tA.called=false;return;}var x=new XMLHttpRequest();x.open("POST",AI_TA.u,true);x.setRequestHeader("Content-Type","application/json; charset=UTF-8");x.onreadystatechange=function(){if(x.readyState===4){tA.called=false;if(x.status===200){try{var resp=JSON.parse(x.responseText);if(resp&&resp.success&&resp.data&&resp.data.map){aT(r,resp.data.map);}}catch(e){}}}};x.send(JSON.stringify({nonce:AI_TA.n,lang:gL(),strings:ss}));}
document.addEventListener("DOMContentLoaded",function(){var checkPage=function(){if(document.readyState==="complete"){setTimeout(function(){tA(document);},1500);}else{setTimeout(checkPage,100);}};checkPage();var moT=null,sel="input,textarea,select,button,[title],[aria-label],img[alt],.initial-greeting,.chatbot-bot-text,.jp-relatedposts-post-title a,.jp-relatedposts-post-context,#jp-relatedposts .jp-relatedposts-headline,[class*=\"wc-block\"],[class*=\"wp-block-woocommerce-\"],.woocommerce-message,.woocommerce-error,.woocommerce-info,.widget_shopping_cart_content";if(typeof MutationObserver!=="undefined"&&gL()){var mo=new MutationObserver(function(muts){for(var f=false,i=0;i<muts.length&&!f;i++){var a=muts[i].addedNodes;for(var j=0;j<a.length&&!f;j++){var n=a[j];if(n.nodeType===1){if(n.matches&&n.matches(sel))f=true;else if(n.querySelector&&n.querySelector(sel))f=true;}}}if(f){clearTimeout(moT);moT=setTimeout(function(){tA(document);},500);}});mo.observe(document.body||document.documentElement,{childList:true,subtree:true});}});
})();</script>';
});
