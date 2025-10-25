<?php

namespace AITranslate;

/**
 * Inject and adjust SEO-related tags on translated pages.
 */
final class AI_SEO
{
    /**
     * Inject hreflang/canonical and translate simple meta labels.
     *
     * @param string $html
     * @param string $lang
     * @return string
     */
    public static function inject($html, $lang)
    {
        if (is_admin()) {
            return $html;
        }

        $default = AI_Lang::default();
        if ($lang === null) {
            return $html;
        }

        // Parse HTML into DOM
        $doc = new \DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $htmlToLoad = self::ensureUtf8($html);
        $flags = LIBXML_HTML_NODEFDTD;
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $htmlToLoad, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $xpath = new \DOMXPath($doc);
        if (function_exists('ai_translate_dbg')) {
            ai_translate_dbg('seo_inject_start', [
                'lang' => $lang,
                'has_head' => (bool) $doc->getElementsByTagName('head')->item(0),
            ]);
        }
        $head = $doc->getElementsByTagName('head')->item(0);
        if (!$head) {
            // Create <head> if missing
            $head = $doc->createElement('head');
            $htmlEl = $doc->getElementsByTagName('html')->item(0);
            if ($htmlEl) {
                $htmlEl->insertBefore($head, $htmlEl->firstChild);
            } else {
                // As a fallback, prepend head at the top-level
                $doc->insertBefore($head, $doc->firstChild);
            }
        }

        // Translate <title> content for non-default languages (preserve structure)
        try {
            if ($default && $lang && strtolower((string)$lang) !== strtolower((string)$default)) {
                $titleNode = $doc->getElementsByTagName('title')->item(0);
                if ($titleNode) {
                    $origTitle = trim((string) $titleNode->textContent);
                    if ($origTitle !== '') {
                        $newTitle = self::maybeTranslateMeta($origTitle, $default, $lang);
                        if (is_string($newTitle) && $newTitle !== '') {
                            while ($titleNode->firstChild) { $titleNode->removeChild($titleNode->firstChild); }
                            $titleNode->appendChild($doc->createTextNode($newTitle));
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fail-safe: keep original title on error
        }

        // 1) Meta Description (add if missing)
        $hasMetaDesc = false;
        $metaDescNodes = $xpath->query('//head/meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="description"]');
        if ($metaDescNodes && $metaDescNodes->length > 0) {
            $hasMetaDesc = true; // Existing description likely already translated by AI_DOM
        }
        if (!$hasMetaDesc) {
            $desc = self::computeMetaDescription();
            if ($desc !== '') {
                $finalDesc = self::maybeTranslateMeta($desc, $default, $lang);
                $finalDesc = self::truncateUtf8($finalDesc, 200, '...');
                if ($finalDesc !== '') {
                    $meta = $doc->createElement('meta');
                    $meta->setAttribute('name', 'description');
                    $meta->setAttribute('content', esc_attr($finalDesc));
                    $head->appendChild($meta);
                    $head->appendChild($doc->createTextNode("\n"));
                }
            }
        }

        // 2) Open Graph (add missing og:* tags)
        $ogTitleMissing = self::isOgMissing($xpath, 'og:title');
        $ogDescMissing = self::isOgMissing($xpath, 'og:description');
        $ogImageMissing = self::isOgMissing($xpath, 'og:image');
        $ogUrlMissing   = self::isOgMissing($xpath, 'og:url');

        if ($ogTitleMissing || $ogDescMissing || $ogImageMissing || $ogUrlMissing) {
            $siteName = (string) get_bloginfo('name');
            $ogTitle = '';
            if ($ogTitleMissing) {
                if (is_singular()) {
                    $postId = get_queried_object_id();
                    if ($postId) {
                        $ogTitle = (string) get_the_title($postId);
                    }
                } elseif (is_front_page() || is_home()) {
                    $ogTitle = (string) get_bloginfo('description');
                } elseif (is_archive()) {
                    $ogTitle = (string) get_the_archive_title();
                }
                if ($ogTitle !== '' && $siteName !== '') {
                    $ogTitle = trim($ogTitle) . ' - ' . $siteName;
                }
                if ($ogTitle !== '') {
                    $ogTitle = self::maybeTranslateMeta($ogTitle, $default, $lang);
                }
            }

            $ogDesc = '';
            if ($ogDescMissing) {
                $ogDesc = self::computeMetaDescription();
                if ($ogDesc !== '') {
                    $ogDesc = self::maybeTranslateMeta($ogDesc, $default, $lang);
                    $ogDesc = self::truncateUtf8($ogDesc, 200, '...');
                }
            }

            $ogImage = '';
            if ($ogImageMissing) {
                if (is_singular()) {
                    $postId = get_queried_object_id();
                    if ($postId && has_post_thumbnail($postId)) {
                        $imgId = get_post_thumbnail_id($postId);
                        $url = wp_get_attachment_image_url($imgId, 'large');
                        if (is_string($url) && $url !== '') {
                            $ogImage = (string) $url;
                        }
                    }
                }
                if ($ogImage === '') {
                    $logoId = get_theme_mod('custom_logo');
                    if ($logoId) {
                        $url = wp_get_attachment_image_url($logoId, 'large');
                        if (is_string($url) && $url !== '') {
                            $ogImage = (string) $url;
                        }
                    }
                }
            }

            $ogUrl = '';
            if ($ogUrlMissing) {
                $currentAbs = self::currentPageUrlAbsolute();
                $path = AI_URL::rewrite_single_href($currentAbs, $lang, $default);
                if (is_string($path) && $path !== '') {
                    $ogUrl = home_url($path);
                }
                if ($ogUrl === '') {
                    $ogUrl = $currentAbs;
                }
            }

            if ($ogTitleMissing && $ogTitle !== '') {
                $meta = $doc->createElement('meta');
                $meta->setAttribute('property', 'og:title');
                $meta->setAttribute('content', esc_attr(trim($ogTitle)));
                $head->appendChild($meta);
                $head->appendChild($doc->createTextNode("\n"));
            }
            if ($ogDescMissing && $ogDesc !== '') {
                $meta = $doc->createElement('meta');
                $meta->setAttribute('property', 'og:description');
                $meta->setAttribute('content', esc_attr(trim($ogDesc)));
                $head->appendChild($meta);
                $head->appendChild($doc->createTextNode("\n"));
            }
            if ($ogImageMissing && $ogImage !== '') {
                $meta = $doc->createElement('meta');
                $meta->setAttribute('property', 'og:image');
                $meta->setAttribute('content', esc_url($ogImage));
                $head->appendChild($meta);
                $head->appendChild($doc->createTextNode("\n"));
            }
            if ($ogUrlMissing && $ogUrl !== '') {
                $meta = $doc->createElement('meta');
                $meta->setAttribute('property', 'og:url');
                $meta->setAttribute('content', esc_url($ogUrl));
                $head->appendChild($meta);
                $head->appendChild($doc->createTextNode("\n"));
            }
        }

        // 3) hreflang alternates
        self::injectHreflang($doc, $xpath, $head, $lang, $default);
        if (function_exists('ai_translate_dbg')) {
            $hreflangs = [];
            $links = $xpath->query('//head/link[@rel="alternate" and @hreflang]');
            if ($links) {
                foreach ($links as $lnk) {
                    if ($lnk instanceof \DOMElement) {
                        $hreflangs[] = strtolower((string) $lnk->getAttribute('hreflang'));
                    }
                }
            }
            ai_translate_dbg('seo_inject_done', [ 'lang' => $lang, 'hreflangs' => $hreflangs ]);
        }

        $result = $doc->saveHTML();
        
        // Remove XML declaration added by loadHTML (causes quirks mode)
        $result = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $result);
        
        // Preserve DOCTYPE from original HTML to avoid quirks mode
        if (preg_match('/^(<!DOCTYPE[^>]*>)/i', (string) $html, $docMatch)) {
            // Ensure we don't duplicate if saveHTML already includes it
            if (stripos($result, '<!DOCTYPE') === false) {
                $result = $docMatch[1] . "\n" . $result;
            }
        }
        
        // Preserve original <body> framing to avoid theme structure conflicts
        // Extract only the <head> from DOMDocument result and merge with original HTML body
        if (preg_match('/<head\b[^>]*>([\s\S]*?)<\/head>/i', (string) $result, $headNew) &&
            preg_match('/<head\b[^>]*>([\s\S]*?)<\/head>/i', (string) $html, $headOrig) &&
            preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', (string) $html, $bodyOrig)) {
            
            $newHead = (string) $headNew[1];
            $origBodyTag = (string) $bodyOrig[0];
            
            // Build result: DOCTYPE + updated head + original body
            $doctype = '';
            if (preg_match('/^(<!DOCTYPE[^>]*>)/i', (string) $html, $doctypeMatch)) {
                $doctype = $doctypeMatch[1] . "\n";
            }
            
            $htmlTag = '<html';
            if (preg_match('/<html\b([^>]*)>/i', (string) $html, $htmlMatch)) {
                $htmlTag = '<html' . $htmlMatch[1] . '>';
            }
            
            $result = $doctype . $htmlTag . "\n<head>" . $newHead . "</head>\n" . $origBodyTag . "</html>";
        }
        
        return $result;
    }

    /**
     * Compute a meta description candidate in the site's default language.
     *
     * @return string
     */
    private static function computeMetaDescription()
    {
        // Homepage setting first
        if (is_front_page() || is_home()) {
            $settings = get_option('ai_translate_settings', []);
            $homepage = isset($settings['homepage_meta_description']) ? (string) $settings['homepage_meta_description'] : '';
            if (trim($homepage) !== '') {
                return $homepage;
            }
        }
        if (is_singular() && !(is_front_page() || is_home())) {
            $postId = get_queried_object_id();
            if ($postId) {
                $excerpt = (string) get_post_field('post_excerpt', $postId, 'raw');
                if (trim($excerpt) !== '') {
                    return $excerpt;
                }
                $content = (string) get_post_field('post_content', $postId, 'raw');
                $stripped = wp_strip_all_tags(strip_shortcodes($content));
                return (string) wp_trim_words($stripped, 55, '');
            }
        }
        return (string) get_option('blogdescription', '');
    }

    /**
     * Translate a small meta string when needed using the batch API.
     *
     * @param string $text
     * @param string $default
     * @param string $lang
     * @return string
     */
    private static function maybeTranslateMeta($text, $default, $lang)
    {
        if (!is_string($text) || $text === '') {
            return '';
        }
        // If default or lang is missing, or already in default language, do not translate
        if (!$default || !$lang || strtolower((string)$lang) === strtolower((string)$default)) {
            return $text;
        }
        $plan = ['segments' => [ ['id' => 'm', 'text' => (string)$text, 'type' => 'meta'] ]];
        $settings = get_option('ai_translate_settings', []);
        $ctx = ['website_context' => isset($settings['website_context']) ? (string)$settings['website_context'] : ''];
        $res = AI_Batch::translate_plan($plan, $default, $lang, $ctx);
        $segs = isset($res['segments']) && is_array($res['segments']) ? $res['segments'] : [];
        $translated = isset($segs['m']) ? (string) $segs['m'] : $text;
        return $translated;
    }

    /**
     * Inject hreflang alternate links into <head>.
     *
     * @param \DOMDocument $doc
     * @param \DOMXPath $xpath
     * @param \DOMElement $head
     * @param string $current
     * @param string $default
     * @return void
     */
    private static function injectHreflang($doc, $xpath, $head, $current, $default)
    {
        // Collect existing hreflang rels to avoid duplicates
        $existing = [];
        $links = $xpath->query('//head/link[@rel="alternate" and @hreflang]');
        if ($links) {
            foreach ($links as $lnk) {
                if ($lnk instanceof \DOMElement) {
                    $existing[strtolower((string) $lnk->getAttribute('hreflang'))] = true;
                }
            }
        }

        $settings = get_option('ai_translate_settings', []);
        $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : [];
        $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : [];
        $langs = array_values(array_unique(array_merge($enabled, $detectable)));
        if (is_string($default) && $default !== '' && !in_array($default, $langs, true)) {
            $langs[] = $default;
        }

        $currentAbs = self::currentPageUrlAbsolute();
        $defaultUrl = '';

        foreach ($langs as $lc) {
            $lc = sanitize_key((string) $lc);
            if (isset($existing[$lc])) {
                continue; // already present
            }
            $path = AI_URL::rewrite_single_href($currentAbs, $lc, $default);
            if (!is_string($path) || $path === '') {
                continue;
            }
            $href = home_url($path);
            if ($lc === $default) {
                $defaultUrl = $href;
            }
            $el = $doc->createElement('link');
            $el->setAttribute('rel', 'alternate');
            $el->setAttribute('hreflang', $lc);
            $el->setAttribute('href', esc_url($href));
            $head->appendChild($doc->createTextNode("\n"));
            $head->appendChild($el);            
        }

        // x-default equals default language URL
        if (is_string($default) && $default !== '' && !isset($existing['x-default'])) {
            if ($defaultUrl === '') {
                $path = AI_URL::rewrite_single_href($currentAbs, $default, $default);
                if (is_string($path) && $path !== '') {
                    $defaultUrl = home_url($path);
                }
            }
            if ($defaultUrl !== '') {
                $el = $doc->createElement('link');
                $el->setAttribute('rel', 'alternate');
                $el->setAttribute('hreflang', 'x-default');
                $el->setAttribute('href', esc_url($defaultUrl));
                $head->appendChild($doc->createTextNode("\n"));
                $head->appendChild($el);
                $head->appendChild($doc->createTextNode("\n"));
            }
        }
    }

    /**
     * Determine if a given Open Graph property is missing.
     *
     * @param \DOMXPath $xpath
     * @param string $prop
     * @return bool
     */
    private static function isOgMissing($xpath, $prop)
    {
        $q = sprintf('//head/meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]', strtolower($prop));
        $nodes = $xpath->query($q);
        return !($nodes && $nodes->length > 0);
    }

    /**
     * Truncate a UTF-8 string at character boundary.
     *
     * @param string $text
     * @param int $max
     * @param string $suffix
     * @return string
     */
    private static function truncateUtf8($text, $max, $suffix)
    {
        $text = (string) $text;
        if ($text === '') return '';
        if (mb_strlen($text) <= $max) return $text;
        $tr = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($tr, ' ');
        if ($lastSpace !== false) {
            $tr = mb_substr($tr, 0, $lastSpace);
        } else {
            $tr = mb_substr($tr, 0, max(0, $max - mb_strlen($suffix)));
        }
        return rtrim($tr) . $suffix;
    }

    /**
     * Ensure string is UTF-8 before loadHTML.
     *
     * @param string $html
     * @return string
     */
    private static function ensureUtf8($html)
    {
        if (!\mb_detect_encoding($html, 'UTF-8', true)) {
            $html = \mb_convert_encoding($html, 'UTF-8');
        }
        return $html;
    }

    /**
     * Compute absolute URL for the current page.
     *
     * @return string
     */
    private static function currentPageUrlAbsolute()
    {
        if (is_singular()) {
            $postId = get_queried_object_id();
            if ($postId) {
                $p = get_permalink($postId);
                if (is_string($p) && $p !== '') return (string) $p;
            }
        }
        if (is_front_page() || is_home()) {
            return home_url('/');
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        if ($path === '') { $path = '/'; }
        return home_url($path);
    }
}


