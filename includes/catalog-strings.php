<?php

/**
 * Validation of client-side strings against the shipped .pot catalogs and the product and term names in the database.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check whether a string is a known source string (msgid) in the translation
 * catalog (.pot) shipped by an active plugin. Used to validate client-side
 * rendered UI strings (e.g. WooCommerce block cart/checkout) that are absent
 * from the server-rendered HTML, without opening the batch-strings endpoint
 * up as a free-form translation proxy.
 *
 * @param string $text Normalized source string.
 * @return bool
 */
/**
 * Discover .pot catalogs shipped by active plugins (shared by the catalog validators).
 *
 * @return string[] Readable .pot file paths.
 */
function ai_translate_get_catalog_files()
{
    static $catalogs = null;
    if ($catalogs === null) {
        $catalogs = [];
        $active = (array) get_option('active_plugins', []);
        foreach ($active as $plugin_file) {
            $plugin_dir = dirname((string) $plugin_file);
            if ($plugin_dir === '.' || $plugin_dir === '') {
                continue; // single-file plugin, ships no catalog
            }
            $dir = WP_PLUGIN_DIR . '/' . $plugin_dir;
            foreach (['/languages', '/i18n/languages'] as $sub) {
                foreach ((array) glob($dir . $sub . '/*.pot') as $pot) {
                    if (is_string($pot) && is_readable($pot)) {
                        $catalogs[] = $pot;
                    }
                }
            }
        }
    }
    return $catalogs;
}

/**
 * Collect catalog template strings used to validate client-side rendered UI text:
 * single-line msgids from active plugins' .pot catalogs (English source strings)
 * plus translations from installed language packs (.mo) for the site locale, so
 * runtime strings rendered in the site's own locale are recognized as well.
 *
 * @return string[]
 */
function ai_translate_get_catalog_templates()
{
    static $templates = null;
    if ($templates !== null) {
        return $templates;
    }
    $templates = [];
    foreach (ai_translate_get_catalog_files() as $pot) {
        $contents = (string) file_get_contents($pot);
        if ($contents === '') {
            continue;
        }
        if (preg_match_all('/^msgid(?:_plural)? "((?:[^"\\\\]|\\\\.)*)"$/m', $contents, $m)) {
            foreach ($m[1] as $raw) {
                $templates[] = stripcslashes($raw);
            }
        }
    }
    // Language packs: runtime strings (WC notices, block UI) are rendered in the site
    // locale when a pack is installed, so the English msgids alone are not enough.
    $locale = function_exists('get_locale') ? get_locale() : '';
    if ($locale !== '' && !function_exists('get_file_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if ($locale !== '' && !class_exists('MO')) {
        require_once ABSPATH . WPINC . '/pomo/mo.php';
    }
    if ($locale !== '') {
        foreach ((array) get_option('active_plugins', []) as $plugin_file) {
            $data = get_file_data(WP_PLUGIN_DIR . '/' . $plugin_file, ['TextDomain' => 'Text Domain']);
            $domain = isset($data['TextDomain']) ? trim((string) $data['TextDomain']) : '';
            if ($domain === '') {
                continue;
            }
            $candidates = [
                WP_LANG_DIR . '/plugins/' . $domain . '-' . $locale . '.mo',
                WP_PLUGIN_DIR . '/' . dirname((string) $plugin_file) . '/languages/' . $domain . '-' . $locale . '.mo',
            ];
            foreach ($candidates as $mo_file) {
                if (!is_readable($mo_file)) {
                    continue;
                }
                $mo = new \MO();
                if (!$mo->import_from_file($mo_file)) {
                    continue;
                }
                foreach ($mo->entries as $entry) {
                    // Include every plural form; translations[0] alone misses rendered plurals.
                    foreach ((array) $entry->translations as $msgstr) {
                        $msgstr = (string) $msgstr;
                        if ($msgstr !== '') {
                            $templates[] = $msgstr;
                        }
                    }
                }
                break; // first readable pack for this plugin is enough
            }
        }
    }
    return $templates;
}

function ai_translate_is_known_catalog_string($text)
{
    $text = (string) $text;
    if ($text === '' || mb_strlen($text) > 150) {
        return false;
    }
    static $set = null;
    if ($set === null) {
        $set = array_flip(ai_translate_get_catalog_templates());
    }
    return isset($set[$text]);
}

/**
 * Whether a string matches a catalog template with placeholders filled in, e.g. a
 * WooCommerce notice like '"Bike" has been added to your cart.' rendered from the
 * template '"%s" has been added to your cart.'. Such runtime strings are absent from
 * both the server HTML and the exact-template catalog check. Matching is anchored and
 * only accepts templates that actually contain placeholders, so the batch-strings
 * endpoint stays closed for arbitrary free-form text.
 *
 * @param string $text Normalized source string.
 * @return bool
 */
function ai_translate_matches_catalog_pattern($text)
{
    $text = (string) $text;
    if ($text === '' || mb_strlen($text) > 150) {
        return false;
    }
    static $patterns = null;
    if ($patterns === null) {
        $patterns = [];
        foreach (ai_translate_get_catalog_templates() as $template) {
            // Skip pure-literal templates; only placeholder templates become patterns.
            if (!preg_match('/%(?:\d+\$)?[sd]/', $template)) {
                continue;
            }
            // Require at least 3 literal letters besides the placeholders: a template
            // like "%s" alone would compile to ^.+?$ and match any arbitrary string,
            // reopening the endpoint as a free translation proxy.
            $literal = preg_replace('/%(?:\d+\$)?[sd%]/', '', $template);
            if (!preg_match('/\p{L}{3}/u', $literal)) {
                continue;
            }
            $tmp = preg_replace('/%%/', "\x01", $template);
            $tmp = preg_replace('/%(?:\d+\$)?[sd]/', "\x02", $tmp);
            $regex = preg_quote($tmp, '/');
            $regex = str_replace("\x01", '%', $regex);
            $regex = str_replace("\x02", '.+?', $regex);
            $patterns[] = '/^' . $regex . '$/u';
        }
    }
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text)) {
            return true;
        }
    }
    return false;
}

/**
 * Whether a string exists as a product/variation title or taxonomy term name.
 * WooCommerce block cart/checkout renders product names and attribute values
 * client-side via the Store API, so they fail both the page-HTML and catalog
 * checks. Validating against the site's own database keeps the batch-strings
 * endpoint closed for arbitrary text while covering catalog content. Returns
 * false without querying when WooCommerce is not active.
 *
 * @param string $text Normalized source string.
 * @return bool
 */
function ai_translate_is_known_product_or_term_string($text)
{
    $text = trim((string) $text);
    if ($text === '' || mb_strlen($text) > 150 || !post_type_exists('product')) {
        return false;
    }
    static $cache = [];
    if (array_key_exists($text, $cache)) {
        return $cache[$text];
    }
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded lookup, static-cached per request
    $found = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM $wpdb->posts WHERE post_type IN ('product','product_variation') AND post_status = 'publish' AND post_title = %s LIMIT 1",
        $text
    ));
    if (!$found) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded lookup, static-cached per request
        $found = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM $wpdb->terms WHERE name = %s LIMIT 1",
            $text
        ));
    }
    $cache[$text] = $found;
    return $found;
}
