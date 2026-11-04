<?php

/**
 * Pure decisions behind the language redirects on template_redirect: which path to send a visitor to,
 * which language a page is served in, and which language/path combinations are refused.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Case-insensitive language code comparison.
 */
function ai_translate_same_lang(string $a, string $b): bool
{
    return strtolower($a) === strtolower($b);
}

/**
 * The same path in another language: "/" becomes "/{lang}/", "/over-ons/" becomes "/{lang}/over-ons/".
 *
 * @param string $lang Language code.
 * @param string $path Request path, starting with "/".
 */
function ai_translate_lang_prefixed_path(string $lang, string $path): string
{
    return $path === '/' ? '/' . $lang . '/' : '/' . $lang . $path;
}

/**
 * The path without its leading language segment, with a trailing slash ("/nl/over-ons" gives "/over-ons/").
 * Only the segment equal to $lang is removed; the root gives "/".
 */
function ai_translate_strip_lang_prefix(string $path, string $lang): string
{
    $stripped = preg_replace('#^/' . preg_quote($lang, '#') . '(?=/|$)#i', '', $path);
    if (!is_string($stripped) || $stripped === '') {
        return '/';
    }
    return substr($stripped, -1) === '/' ? $stripped : $stripped . '/';
}

/**
 * Path a request with an explicit ?lang= parameter is redirected to.
 * A language in the path wins; without one the parameter decides, and the default language has no prefix.
 *
 * @param string      $path         Request path.
 * @param string|null $lang_in_path Language the path starts with.
 * @param string      $lang_param   Sanitized value of ?lang=.
 * @param string      $default      Default language.
 */
function ai_translate_lang_param_target_path(string $path, ?string $lang_in_path, string $lang_param, string $default): string
{
    if ($lang_in_path === null) {
        $lang_param = strtolower($lang_param);
        if ($lang_param !== '' && $default !== '' && !ai_translate_same_lang($lang_param, $default)) {
            $path = '/' . $lang_param . '/';
        }
    }
    if ($path !== '/' && substr($path, -1) !== '/') {
        $path .= '/';
    }
    return $path;
}

/**
 * The language a page is served in. A language in the URL wins; the cookie is deliberately ignored
 * here (it only matters on the root, which the caller resolves first).
 *
 * @param string|null $resolved     Language already decided by the caller (browser detection, cookie on root).
 * @param string|null $lang_in_path Language the path starts with.
 * @param string      $default      Default language.
 */
function ai_translate_served_lang(?string $resolved, ?string $lang_in_path, string $default): string
{
    $lang = $resolved ?? $lang_in_path ?? $default;
    if ($lang === '') {
        $lang = $default;
    }
    return strtolower(sanitize_key($lang));
}

/**
 * Whether a path (after its language segment) contains a non-Latin script such as Cyrillic, Greek,
 * Arabic, Hebrew, Indic, Thai, Georgian or CJK. Latin Extended (ħ, é) does not count.
 *
 * @param string $path Request path including the language segment.
 */
function ai_translate_path_has_non_latin_script(string $path): bool
{
    $without_lang = preg_replace('#^/([a-z]{2})/#i', '/', $path);
    if ($without_lang === $path) {
        return false;
    }
    return (bool) preg_match(
        '/[\x{0370}-\x{03FF}\x{0400}-\x{052F}\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0900}-\x{097F}\x{0E00}-\x{0E7F}\x{10A0}-\x{10FF}\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u',
        rawurldecode((string) $without_lang)
    );
}

/**
 * Whether a request in this language must be refused with a 404: the language is neither enabled nor
 * detectable (no translation on demand for arbitrary codes), or the path uses a script the language does not.
 *
 * @param string   $lang       Language in the path.
 * @param string   $path       Request path.
 * @param string[] $enabled    Enabled languages.
 * @param string[] $detectable Detectable languages.
 * @param bool     $non_latin  Whether $lang is a non-Latin language.
 */
function ai_translate_lang_request_refused(string $lang, string $path, array $enabled, array $detectable, bool $non_latin): bool
{
    $lang = strtolower($lang);
    $offered = in_array($lang, array_map('strtolower', $enabled), true)
        || in_array($lang, array_map('strtolower', $detectable), true);
    if (!$offered) {
        return true;
    }
    return ai_translate_path_has_non_latin_script($path) && !$non_latin;
}

/**
 * Turn the current request into a 404 response.
 */
function ai_translate_serve_404(): void
{
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
}

/**
 * Filter the WordPress locale to the language served ("de" gives "de_DE").
 */
function ai_translate_use_locale_of(string $lang): void
{
    add_filter('locale', static function ($locale) use ($lang) {
        return $lang !== '' ? strtolower($lang) . '_' . strtoupper($lang) : $locale;
    });
}
