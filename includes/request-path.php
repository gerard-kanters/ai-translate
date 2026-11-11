<?php

/**
 * The URL of the current request, as every routing and caching decision reads it.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The request URI, sanitized. A URI that was encoded twice (it contains "%25") is decoded once more.
 *
 * @param string $default Value when the server gives no request URI ('' or '/').
 * @return string Path and query string.
 */
function ai_translate_request_uri(string $default = ''): string
{
    $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : $default;
    return strpos($uri, '%25') !== false ? urldecode($uri) : $uri;
}

/**
 * The path of the request without query string and without the WordPress sub-directory.
 *
 * @param bool $root_when_empty Return '/' when the URI has no path (instead of '').
 * @return string
 */
function ai_translate_request_path(bool $root_when_empty = false): string
{
    $path = wp_parse_url(ai_translate_request_uri(), PHP_URL_PATH);
    return ai_translate_strip_site_path($root_when_empty ? ((string) $path ?: '/') : (string) $path);
}

/**
 * The language code a path starts with ("/de/over-ons/" gives "de"), lowercased and sanitized.
 * Any two letters count: whether the language is offered is decided by the caller.
 *
 * @param string $path Request path.
 * @return string|null Null when the path does not start with a two-letter segment.
 */
function ai_translate_lang_in_path(string $path): ?string
{
    if ($path === '' || !preg_match('#^/([a-z]{2})(?:/|$)#i', $path, $m)) {
        return null;
    }
    return strtolower(sanitize_key($m[1]));
}
