<?php
/**
 * Plugin Name: AI Translate
 * Description: AI based translation plugin. Adding 23 languages in a few clicks. 
 * Author: Netcare
 * Author URI: https://netcare.nl/
 * Version: 2.0.1
 * Requires PHP: 8.0
 * Text Domain: ai-translate
 */

// Do not allow direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Include core/admin and runtime classes.
require_once __DIR__ . '/includes/class-ai-translate-core.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/class-ai-lang.php';
require_once __DIR__ . '/includes/class-ai-cache.php';
require_once __DIR__ . '/includes/class-ai-dom.php';
require_once __DIR__ . '/includes/class-ai-batch.php';
require_once __DIR__ . '/includes/class-ai-seo.php';
require_once __DIR__ . '/includes/class-ai-url.php';
require_once __DIR__ . '/includes/class-ai-ob.php';
require_once __DIR__ . '/includes/class-ai-slugs.php';


// Lightweight debug logger for routing analysis
if (!function_exists('ai_translate_dbg')) {
    function ai_translate_dbg($message, $context = array()) {
        $msg = '[ai-translate] ' . (string) $message;
        if (is_array($context) && !empty($context)) {
            if (function_exists('wp_json_encode')) {
                $msg .= ' ' . wp_json_encode($context);
            } else {
                $msg .= ' ' . json_encode($context);
            }
        }
        // Log to PHP error log
        error_log($msg);
        // Also mirror to uploads-based log file to ensure availability on production
        if (function_exists('wp_upload_dir')) {
            $up = wp_upload_dir();
            if (is_array($up) && !empty($up['basedir'])) {
                $dir = rtrim((string)$up['basedir'], '/\\') . '/ai-translate/logs/';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $line = gmdate('c') . ' ' . $msg . "\n";
                // Write to both ai-router.log and urlmap.log for compatibility with tooling
                @file_put_contents($dir . 'ai-router.log', $line, FILE_APPEND);
                @file_put_contents($dir . 'urlmap.log', $line, FILE_APPEND);
            }
        }
    }
}

// Ensure slug map table exists early in init
add_action('init', function () {
    \AITranslate\AI_Slugs::install_table();
}, 1);

 

// Add original-style language-prefixed rewrite rules using 'lang' query var to ensure WP resolves pages via pagename
add_action('init', function () {
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : array();
    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : array();
    $default = isset($settings['default_language']) ? (string) $settings['default_language'] : '';
    $langs = array_values(array_unique(array_merge($enabled, $detectable)));
    if ($default !== '') { $langs = array_diff($langs, array($default)); }
    $langs = array_filter(array_map('sanitize_key', $langs));
    if (empty($langs)) return;
    $regex = '(' . implode('|', array_map(function ($l) { return preg_quote($l, '/'); }, $langs)) . ')';
    add_rewrite_rule('^' . $regex . '/?$', 'index.php?lang=$matches[1]', 'top');
    // Explicit CPT bases similar to org implementation (keep before generic page/name rules)
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)(product)/([^/]+)/?$', 'index.php?lang=$matches[1]&post_type=product&name=$matches[3]', 'top');
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)(service)/([^/]+)/?$', 'index.php?lang=$matches[1]&post_type=service&name=$matches[3]', 'top');
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)(.+?)/?$', 'index.php?lang=$matches[1]&pagename=$matches[2]', 'top');
    add_rewrite_rule('^' . $regex . '/(?!wp-admin|wp-login\.php)([^/]+)/?$', 'index.php?lang=$matches[1]&name=$matches[2]', 'top');
}, 2);

add_filter('query_vars', function ($vars) {
    if (!in_array('lang', $vars, true)) { $vars[] = 'lang'; }
    return $vars;
});

/**
 * Prevent WordPress from redirecting language-prefixed URLs to the canonical root.
 */
add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
    $req = (string) $requested_url;
    $path = (string) parse_url($req, PHP_URL_PATH);
    if ($path !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $path)) {
        ai_translate_dbg('redirect_canonical prevented', ['requested' => $requested_url]);
        return false; // keep /xx or /xx/... as requested
    }
    ai_translate_dbg('redirect_canonical pass', ['requested' => $requested_url, 'redirect' => $redirect_url]);
    return $redirect_url;
}, 10, 2);

/**
 * Flush rewrite rules on activation.
 */
register_activation_hook(__FILE__, function () {
    // Ensure rules are registered before flushing
    do_action('init');
    flush_rewrite_rules();
});

/**
 * Map ai_path to WordPress internal query vars.
 */
add_filter('request', function ($vars) {
    // Hard skip for admin paths
    $req = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($req !== '' && (strpos($req, '/wp-admin/') !== false || strpos($req, 'wp-login.php') !== false)) {
        return $vars;
    }
    ai_translate_dbg('request_in', [
        'REQUEST_URI' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        'vars' => array_intersect_key((array)$vars, ['lang'=>1,'pagename'=>1,'name'=>1,'blogpage'=>1,'paged'=>1])
    ]);
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
                ai_translate_dbg('request_pagename_mapped', ['pagename' => $vars['pagename']]);
            }
        }
        if (!empty($vars['name'])) {
            $nm = (string) $vars['name'];
            $src = \AITranslate\AI_Slugs::resolve_any_to_source_slug($nm);
                if ($src) {
                    $vars['name'] = $src;
                    ai_translate_dbg('request_name_mapped', ['name' => $vars['name']]);
                }
        }
    }
    ai_translate_dbg('request_out', array_intersect_key((array)$vars, ['lang'=>1,'pagename'=>1,'name'=>1,'paged'=>1]));
    return $vars;
});

/**
 * Start output buffering for front-end (skip admin, AJAX, REST and feeds).
 */
add_action('template_redirect', function () {
    // Skip only admin/AJAX/REST/feeds; allow default language to pass so SEO injection can run
    if (is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed()) {
        return;
    }
    // Force language detection strictly by URL prefix first (cookie only fallback)
    \AITranslate\AI_Lang::detect();
    ai_translate_dbg('template_redirect_start', [
        'REQUEST_URI' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        'lang_qv' => (string) (get_query_var('lang') ?: ''),
        'paged' => (int) get_query_var('paged'),
        'blogpage' => isset($_GET['blogpage']) ? (int) $_GET['blogpage'] : 0,
    ]);

    // Remove force-home logic; keep template_redirect minimal
    // Sync cookie with URL language when present
    $lang_q = get_query_var('lang');
    if (is_string($lang_q) && $lang_q !== '') {
        $cookie_val = isset($_COOKIE['ai_translate_lang']) ? (string) $_COOKIE['ai_translate_lang'] : '';
        if ($cookie_val !== $lang_q) {
            setcookie('ai_translate_lang', $lang_q, time() + 30 * DAY_IN_SECONDS, '/', '', false, true);
            $_COOKIE['ai_translate_lang'] = $lang_q;
        }
    } else {
        // Fallback: if URL path starts with /{xx}/, sync cookie even if rewrite didn't set query var
        $reqPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($reqPath !== '' && preg_match('#^/([a-z]{2})(?:/|$)#i', $reqPath, $mm)) {
            $lang_from_path = strtolower($mm[1]);
            $cookie_val = isset($_COOKIE['ai_translate_lang']) ? (string) $_COOKIE['ai_translate_lang'] : '';
            if ($cookie_val !== $lang_from_path) {
                setcookie('ai_translate_lang', $lang_from_path, time() + 30 * DAY_IN_SECONDS, '/', '', false, true);
                $_COOKIE['ai_translate_lang'] = $lang_from_path;
            }
        }
    }
    \AITranslate\AI_OB::instance()->start();
}, 1);

/**
 * Ensure that when viewing the posts index (translated posts page) with ?blogpage=N,
 * the main query uses paged=N so page 2+ actually render page 2+ instead of page 1.
 */
// Removed pagination forcing; theme handles pagination

// Never adjust admin URLs or rewrite menu items in admin or default language
add_filter('nav_menu_link_attributes', function ($atts, $item) {
    if (is_admin() || \AITranslate\AI_Lang::is_exempt_request()) {
        return $atts;
    }
    return $atts;
}, 10, 2);

// Language switcher is intentionally removed as per request
/**
 * Minimal server-side language switcher (no JS): renders links to current path in other languages.
 */
add_action('wp_footer', function () {
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? array_values($settings['enabled_languages']) : array();
    $default = isset($settings['default_language']) ? (string)$settings['default_language'] : '';
    if (empty($enabled) || $default === '') {
        return;
    }

    // Determine current path and strip any leading /xx/
    $reqUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
    $path = (string) parse_url($reqUri, PHP_URL_PATH);
    if ($path === '') { $path = '/'; }
    $pathNoLang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
    if ($pathNoLang === '') { $pathNoLang = '/'; }

    $flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';

    // Inline minimal CSS for bottom-LEFT button, popup opens UPWARDS (namespaced classes to avoid theme conflicts)
    echo '<style>.ai-trans{position:fixed;bottom:20px;left:20px;z-index:2147483000}.ai-trans .ai-trans-btn{display:inline-flex;align-items:center;justify-content:center;gap:4px;padding:8px 12px;border-radius:24px;border:none;background:#1e3a8a;color:#fff;box-shadow:0 2px 8px rgba(0,0,0,.2);cursor:pointer;font-size:13px;font-weight:600}.ai-trans .ai-trans-btn img{width:20px;height:14px;border-radius:2px}.ai-trans .ai-trans-menu{position:absolute;bottom:100%;left:0;margin-bottom:8px;background:#fff;border:1px solid #ddd;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.15);padding:8px;display:none;min-width:140px}.ai-trans.ai-trans-open .ai-trans-menu{display:block}.ai-trans .ai-trans-item{display:flex;align-items:center;gap:8px;padding:8px 10px;text-decoration:none;color:#222;border-radius:6px;font-size:13px}.ai-trans .ai-trans-item:hover{background:#f3f4f6}.ai-trans .ai-trans-item img{width:20px;height:14px;border-radius:2px}</style>';

    // Current language (from URL or default)
    $currentLang = null;
    if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $m)) { $currentLang = strtolower($m[1]); }
    if (!$currentLang) { $currentLang = $default; }
    $currentFlag = esc_url($flags_url . sanitize_key($currentLang) . '.png');

    echo '<div id="ai-trans" class="ai-trans">';
    // Show current language flag with code label
    echo '<button type="button" class="ai-trans-btn" aria-haspopup="true" aria-expanded="false" aria-controls="ai-trans-menu" title="' . esc_attr(strtoupper($currentLang)) . '"><img src="' . $currentFlag . '" alt="' . esc_attr($currentLang) . '"><span>' . esc_html(strtoupper($currentLang)) . '</span></button>';
    echo '<div id="ai-trans-menu" class="ai-trans-menu" role="menu">';

    foreach ($enabled as $code) {
        $code = sanitize_key($code);
        // Always go to the language homepage on switch
        $targetPath = ($code === $default) ? '/' : ('/' . $code . '/');
        // Normalize double slashes
        $targetPath = preg_replace('#/{2,}#','/',$targetPath);
        $url = esc_url( home_url( $targetPath ) );
        $flag = esc_url( $flags_url . $code . '.png' );
        echo '<a class="ai-trans-item" href="' . $url . '" role="menuitem" data-lang="' . esc_attr($code) . '" data-ai-trans-skip="1"><img src="' . $flag . '" alt="' . esc_attr(strtoupper($code)) . '"><span>' . esc_html(strtoupper($code)) . '</span></a>';
    }

    echo '</div></div>';

    // Minimal toggle script + cookie set on click + dynamic placeholder translation
    $ajaxUrl = esc_url(admin_url('admin-ajax.php'));
    $nonce = wp_create_nonce('ai_translate_front_nonce');
    echo '<script>(function(){var w=document.getElementById("ai-trans");if(!w)return;var b=w.querySelector(".ai-trans-btn");b.addEventListener("click",function(e){e.stopPropagation();var open=w.classList.toggle("ai-trans-open");b.setAttribute("aria-expanded",open?"true":"false")});document.addEventListener("click",function(e){if(!w.contains(e.target)){w.classList.remove("ai-trans-open");b.setAttribute("aria-expanded","false")}});w.addEventListener("click",function(e){var a=e.target.closest("a.ai-trans-item");if(!a)return;var lang=a.getAttribute("data-lang")||"";if(lang){var d=new Date(Date.now()+30*24*60*60*1000).toUTCString();document.cookie="ai_translate_lang="+encodeURIComponent(lang)+";path=/;expires="+d+";SameSite=Lax";}});
// Dynamic UI attribute translation (placeholder/title/aria-label/value of buttons)
var AI_TA={u:"' . $ajaxUrl . '",n:"' . esc_js($nonce) . '"};
function cS(r){var s=new Set();var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label]"):[];ns.forEach(function(el){if(el.hasAttribute("data-ai-trans-skip"))return;var ph=el.getAttribute("placeholder");if(ph&&ph.trim())s.add(ph.trim());var tl=el.getAttribute("title");if(tl&&tl.trim())s.add(tl.trim());var al=el.getAttribute("aria-label");if(al&&al.trim())s.add(al.trim());var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=el.getAttribute("value");if(v&&v.trim())s.add(v.trim());}}});return Array.from(s);} 
function aT(r,m){var ns=r.querySelectorAll?r.querySelectorAll("input,textarea,select,button,[title],[aria-label]"):[];ns.forEach(function(el){if(el.hasAttribute("data-ai-trans-skip"))return;var ph=el.getAttribute("placeholder");if(ph&&m[ph]!=null)el.setAttribute("placeholder",m[ph]);var tl=el.getAttribute("title");if(tl&&m[tl]!=null)el.setAttribute("title",m[tl]);var al=el.getAttribute("aria-label");if(al&&m[al]!=null)el.setAttribute("aria-label",m[al]);var tg=(el.tagName||"").toLowerCase();if(tg==="input"){var tp=(el.getAttribute("type")||"").toLowerCase();if(tp==="submit"||tp==="button"||tp==="reset"){var v=el.getAttribute("value");if(v&&m[v]!=null)el.setAttribute("value",m[v]);}}});}
function tA(r){var ss=cS(r);if(!ss.length)return;var x=new XMLHttpRequest();x.open("POST",AI_TA.u,true);x.setRequestHeader("Content-Type","application/x-www-form-urlencoded; charset=UTF-8");x.onreadystatechange=function(){if(x.readyState===4&&x.status===200){try{var resp=JSON.parse(x.responseText);if(resp&&resp.success&&resp.data&&resp.data.map){aT(r,resp.data.map);}}catch(e){}}};x.send("action=ai_translate_batch_strings&nonce="+encodeURIComponent(AI_TA.n)+"&strings="+encodeURIComponent(JSON.stringify(ss)));}
document.addEventListener("DOMContentLoaded",function(){tA(document);try{var mo=new MutationObserver(function(ms){ms.forEach(function(m){for(var i=0;i<m.addedNodes.length;i++){var n=m.addedNodes[i];if(n&&n.nodeType===1){tA(n);}}});});mo.observe(document.documentElement,{childList:true,subtree:true});}catch(e){}});
})();</script>';
});

/**
 * Ensure rewrite rules contain our language prefix rules; flush once if missing.
 */
add_action('init', function () {
    if (is_admin()) {
        return;
    }
    if (get_transient('ai_translate_rules_checked')) {
        return;
    }
    $rules = get_option('rewrite_rules');
    $has_lang_rule = false;
    if (is_array($rules)) {
        foreach ($rules as $regex => $target) {
            if (is_string($target) && (strpos($target, 'ai_lang=') !== false || strpos($target, 'lang=') !== false)) {
                $has_lang_rule = true;
                break;
            }
        }
    }
    set_transient('ai_translate_rules_checked', 1, DAY_IN_SECONDS);
    if (!$has_lang_rule) {
        // Flush once to persist currently-registered rules
        flush_rewrite_rules(false);
    }
}, 99);
/**
 * Frontend AJAX endpoint for dynamic UI attribute translation.
 */
add_action('wp_ajax_nopriv_ai_translate_batch_strings', function(){
    check_ajax_referer('ai_translate_front_nonce','nonce');
    $raw = isset($_POST['strings']) ? (string) wp_unslash($_POST['strings']) : '[]';
    $arr = json_decode($raw, true);
    if (!is_array($arr)) { wp_send_json_success(['map'=>[]]); }
    $texts = array_values(array_unique(array_filter(array_map(function($s){ return trim((string) $s); }, $arr))));
    $lang = \AITranslate\AI_Lang::current();
    $default = \AITranslate\AI_Lang::default();
    if ($lang === null || $default === null) { wp_send_json_success(['map'=>[]]); }
    if (strtolower($lang) === strtolower($default)) {
        $map = [];
        foreach ($texts as $t) { $map[$t] = $t; }
        wp_send_json_success(['map'=>$map]);
    }
    $settings = get_option('ai_translate_settings', array());
    $expiry_hours = isset($settings['cache_expiration']) ? (int) $settings['cache_expiration'] : (14*24);
    $expiry = max(1, $expiry_hours) * HOUR_IN_SECONDS;
    $map = [];
    $toTranslate = [];
    $idMap = [];
    $i = 0;
    foreach ($texts as $t) {
        $key = 'ai_tr_attr_' . $lang . '_' . md5($t);
        $cached = get_transient($key);
        if ($cached !== false) {
            $map[$t] = (string) $cached;
        } else {
            $id = 's' . (++$i);
            $toTranslate[$id] = $t;
            $idMap[$id] = $t;
        }
    }
    if (!empty($toTranslate)) {
        $plan = ['segments'=>[]];
        foreach ($toTranslate as $id=>$text) {
            $plan['segments'][] = ['id'=>$id, 'text'=>$text, 'type'=>'meta'];
        }
        $ctx = ['website_context' => isset($settings['website_context']) ? (string)$settings['website_context'] : ''];
        $res = \AITranslate\AI_Batch::translate_plan($plan, $default, $lang, $ctx);
        $segs = isset($res['segments']) && is_array($res['segments']) ? $res['segments'] : array();
        foreach ($toTranslate as $id=>$orig) {
            $tr = isset($segs[$id]) ? (string) $segs[$id] : $orig;
            $map[$orig] = $tr;
            set_transient('ai_tr_attr_' . $lang . '_' . md5($orig), $tr, $expiry);
        }
    }
    wp_send_json_success(['map'=>$map]);
});
add_action('wp_ajax_ai_translate_batch_strings', function(){ do_action('wp_ajax_nopriv_ai_translate_batch_strings'); });

/**
 * Ensure language-only URLs (e.g., /en/, /de/) route to the site's front page.
 */
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    $request_path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
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
                    ai_translate_dbg('lang_root_posts_paged', [
                        'REQUEST_URI' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                        'paged' => $paged ?: $blogPaged,
                        'pagename' => $wp->query_vars['pagename']
                    ]);
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
                ai_translate_dbg('lang_root_route', [
                    'REQUEST_URI' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                    'query_vars' => array_intersect_key($wp->query_vars, ['paged'=>1,'blogpage'=>1,'page_id'=>1])
                ]);
            }
        }
    }
});

/**
 * Route /{lang}/page/{n} to the posts page with proper pagination.
 */
// Removed parse_request pagination handler for /{lang}/page/{n}

/**
 * Ensure /{lang}/?blogpage={n} resolves to the posts index with pagination.
 * Some themes use a custom query var (?blogpage). Map this early and point to posts page slug.
 */
// Removed parse_request handler for language root pagination (?blogpage / paged)

// Map translated paths like /{lang}/{translated-slug} to the original content (page or post)
add_action('parse_request', function ($wp) {
    if (is_admin() || wp_doing_ajax()) {
        return;
    }
    $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
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
    ai_translate_dbg('parse_request_lang_path', ['path' => $path, 'lang' => $lang, 'rest' => $rest]);

    // Removed handlers for translated posts index pagination

    // Try exact mapping from translated path to post ID via slug map
    $post_id = \AITranslate\AI_Slugs::resolve_path_to_post($lang, $rest);
    if ($post_id) {
        $post = get_post((int) $post_id);
        $wp->query_vars = array_diff_key($wp->query_vars, ['name'=>1, 'pagename'=>1, 'page_id'=>1, 'p'=>1, 'post_type'=>1]);
        $posts_page_id = (int) get_option('page_for_posts');
        if ($post && $post->post_type === 'page') {
            if ($posts_page_id > 0 && (int)$post_id === $posts_page_id) {
                // Treat posts page as home (blog index) so pagination works
                $wp->query_vars['pagename'] = (string) get_page_uri($posts_page_id);
                $wp->is_home = true;
                $wp->is_page = false;
                $wp->is_singular = false;
            } else {
                $wp->query_vars['page_id'] = (int) $post_id;
                $wp->is_page = true;
            }
        } else {
            $wp->query_vars['p'] = (int) $post_id;
            if ($post && !empty($post->post_type)) {
                $wp->query_vars['post_type'] = $post->post_type;
            }
            $wp->is_single = true;
        }
        $wp->is_404 = false;
        $wp->is_singular = true;
        ai_translate_dbg('mapped_via_slug_table', [
            'post_id' => (int)$post_id,
            'post_type' => $post ? (string)$post->post_type : 'unknown'
        ]);
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
            if ($dir === '\\' || $dir === '.') { $dir = ''; }
            $sourcePath = $dir !== '' ? ($dir . '/' . $srcBase) : $srcBase;

            // Resolve across all public post types (page, post, CPT)
            $public_types = get_post_types(['public' => true], 'names');
            $post = get_page_by_path($sourcePath, OBJECT, array_values($public_types));
            if ($post) {
                $wp->query_vars = array_diff_key($wp->query_vars, ['name'=>1, 'pagename'=>1, 'page_id'=>1, 'p'=>1]);
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
                ai_translate_dbg('mapped_via_page_path', [
                    'sourcePath' => $sourcePath,
                    'post_id' => (int)$post->ID,
                    'post_type' => (string)$post->post_type
                ]);
                return;
            }

            // Fallback: let WP try resolving as page path
            $wp->query_vars['pagename'] = $sourcePath;
            // Do not force 404 state here; allow core to resolve properly
            ai_translate_dbg('fallback_set_pagename', ['sourcePath' => $sourcePath]);
        }
        // (debug logging removed)
    }
    // (debug logging removed)
});

/**
 * Rewrite internal permalinks (post/page) to stable translated slugs for current language.
 */
add_filter('post_link', function ($permalink, $post, $leavename) {
    if (is_admin()) return $permalink;
    $lang = \AITranslate\AI_Lang::current();
    $default = \AITranslate\AI_Lang::default();
    if ($lang === null || $default === null || strtolower($lang) === strtolower($default)) {
        return $permalink;
    }
    $translated = \AITranslate\AI_Slugs::get_or_generate((int) $post->ID, $lang);
    if ($translated === null) return $permalink;
    // Build path /{lang}/{translated-slug}/ respecting trailing slash (support Unicode slugs)
    $trail = substr($permalink, -1) === '/' ? '/' : '';
    $path = '/' . $lang . '/' . trim($translated, '/') . $trail;
    return home_url($path);
}, 10, 3);

add_filter('page_link', function ($permalink, $post_id, $sample) {
    if (is_admin()) return $permalink;
    $lang = \AITranslate\AI_Lang::current();
    $default = \AITranslate\AI_Lang::default();
    if ($lang === null || $default === null || strtolower($lang) === strtolower($default)) {
        return $permalink;
    }
    $translated = \AITranslate\AI_Slugs::get_or_generate((int) $post_id, $lang);
    if ($translated === null) return $permalink;
    $trail = substr($permalink, -1) === '/' ? '/' : '';
    $path = '/' . $lang . '/' . trim($translated, '/') . $trail;
    return home_url($path);
}, 10, 3);

/**
 * Keep translated slugs in sync when the original permalink (post_name) changes.
 * Only regenerates when the source slug actually changed to minimize API calls.
 */
add_action('save_post', function ($post_id, $post, $update) {
    static $processing = false;
    if ($processing) return;
    if (wp_is_post_revision($post_id)) return;
    if (!is_object($post) || $post->post_status !== 'publish') return;
    $current_slug = (string) $post->post_name;
    $stored = get_post_meta($post_id, '_ai_translate_original_slug', true);
    if ($stored === $current_slug && $stored !== '') return;
    $processing = true;
    update_post_meta($post_id, '_ai_translate_original_slug', $current_slug);
    $default = \AITranslate\AI_Lang::default();
    $settings = get_option('ai_translate_settings', array());
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : array();
    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : array();
    $core = \AITranslate\AI_Translate_Core::get_instance();
    $available = array_keys($core->get_available_languages());
    $langs = array_values(array_unique(array_merge($enabled, $detectable, $available)));
    foreach ($langs as $lang) {
        $lang = sanitize_key((string) $lang);
        if ($default !== null && strtolower($lang) === strtolower($default)) continue;
        \AITranslate\AI_Slugs::get_or_generate((int) $post_id, $lang);
    }
    $processing = false;
}, 10, 3);

add_action('post_updated', function ($post_id, $post_after, $post_before) {
    if (!is_object($post_before) || !is_object($post_after)) return;
    if ((string) $post_before->post_name === (string) $post_after->post_name) return;
    // Direct call instead of do_action to avoid infinite loop
    $cb = function_exists('get_post') ? get_post($post_id) : $post_after;
    do_action_ref_array('save_post', array($post_id, $cb, true));
}, 10, 3);

/**
 * One-time warmup: generate translated slugs for existing top-level pages so
 * language-prefixed URLs immediately resolve (prevents blank pages before first visit).
 * Mirrors robustness in the original implementation by pre-seeding the slug map.
 */
add_action('init', function () {
    if (get_option('ai_translate_slug_warmup_done')) {
        return;
    }
    // Only run on front-end to avoid slowing down admin
    if (is_admin()) {
        return;
    }
    $settings = get_option('ai_translate_settings', array());
    $default = \AITranslate\AI_Lang::default();
    if ($default === null) {
        update_option('ai_translate_slug_warmup_done', 1);
        return;
    }
    $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : array();
    $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : array();
    $langs = array_values(array_unique(array_merge($enabled, $detectable)));
    if (empty($langs)) {
        update_option('ai_translate_slug_warmup_done', 1);
        return;
    }
    // Warmup all published pages and posts so reverse mapping works immediately
    $pages = get_posts(array(
        'post_type' => array('page','post'),
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'orderby' => 'date',
        'order' => 'DESC',
    ));
    if (empty($pages)) {
        update_option('ai_translate_slug_warmup_done', 1);
        return;
    }
    foreach ($pages as $pid) {
        foreach ($langs as $lang) {
            $lang = sanitize_key((string) $lang);
            if (strtolower($lang) === strtolower($default)) {
                continue;
            }
            \AITranslate\AI_Slugs::get_or_generate((int) $pid, $lang);
        }
    }
    update_option('ai_translate_slug_warmup_done', 1);
}, 99);

/**
 * Intercept imminent 404s and remap translated slugs to source before WP renders a 404.
 * This mirrors original behaviour and fixes empty pages when rewrite did not bind.
 */
add_filter('pre_handle_404', function ($preempt, $wp_query) {
    if (!is_object($wp_query) || !$wp_query->is_main_query()) {
        return $preempt;
    }
    // Skip admin and default language
    if (\AITranslate\AI_Lang::is_exempt_request()) {
        return $preempt;
    }
    // Already resolved → nothing to do
    if (!$wp_query->is_404()) {
        return $preempt;
    }
    $reqPath = isset($_SERVER['REQUEST_URI']) ? (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    if ($reqPath === '' || !preg_match('#^/([a-z]{2})(?:/(.*))?$#i', $reqPath, $m)) {
        return $preempt;
    }
    $lang = strtolower($m[1]);
    $rest = isset($m[2]) ? (string) $m[2] : '';
    $rest = trim($rest, '/');
    if ($rest === '') {
        // Language root → front page
        $front_id = (int) get_option('page_on_front');
        if ($front_id > 0) {
            $wp_query->query_vars['page_id'] = $front_id;
            $wp_query->is_page = true;
            $wp_query->is_singular = true;
            $wp_query->is_home = false;
            $wp_query->is_404 = false;
            ai_translate_dbg('pre_handle_404_lang_root_to_front', ['REQUEST_URI' => $reqPath, 'page_id' => $front_id]);
            return true; // short-circuit 404
        }
        return $preempt;
    }
    // Try slug mapping
    $source = \AITranslate\AI_Slugs::resolve_any_to_source_slug($rest);
    if ($source) {
        $wp_query->query_vars = array_diff_key($wp_query->query_vars, ['name'=>1, 'pagename'=>1]);
        $wp_query->query_vars['pagename'] = $source;
        $wp_query->is_404 = false;
        $wp_query->is_page = true;
        $wp_query->is_singular = true;
        ai_translate_dbg('pre_handle_404_resolved_source', ['REQUEST_URI' => $reqPath, 'pagename' => $source]);
        return true;
    }
    return $preempt;
}, 10, 2);

/**
 * One-time flush of rewrite rules after deployment so language rules become active
 * without manual intervention. Safe due to option flag.
 */
add_action('init', function () {
    if (!get_option('ai_translate_rules_flushed_v1')) {
        flush_rewrite_rules(false);
        update_option('ai_translate_rules_flushed_v1', 1);
    }
}, 20);

// Ensure new rewrite rules are flushed once after deployment changes (CPT rules added)
add_action('init', function () {
    if (!get_option('ai_translate_rules_flushed_v2')) {
        flush_rewrite_rules(false);
        update_option('ai_translate_rules_flushed_v2', 1);
        ai_translate_dbg('rewrite_rules_flushed_v2');
    }
}, 21);

/**
 * Add Settings quick link on the Plugins page.
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('admin.php?page=ai-translate');
    $settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Instellingen', 'ai-translate') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});


