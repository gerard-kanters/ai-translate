<?php

namespace AITranslate;

/**
 * Inject and adjust SEO-related tags on translated pages.
 */
final class AI_SEO
{
    /**
     * Inject hreflang tags as plain text without DOM parsing.
     * This prevents JavaScript and other scripts from being corrupted.
     *
     * @param string $html
     * @param string $lang
     * @return string
     */
    public static function inject_hreflang_only($html, $lang)
    {
        if (is_admin()) {
            return $html;
        }

        $default = AI_Lang::default();
        if ($lang === null) {
            return $html;
        }

        // Generate hreflang tags as text
        $hreflangTags = self::generateHreflangTags($lang, $default);
        
        // Inject before </head> tag as plain text
        if (stripos($html, '</head>') !== false) {
            $html = preg_replace(
                '#</head>#i',
                "\n" . $hreflangTags . "\n</head>",
                $html,
                1
            );
        }
        
        return $html;
    }

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

        // Apply all SEO modifications to the DOM
        self::inject_dom($doc, $xpath, $lang);

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
     * Apply all SEO modifications to an already-parsed DOMDocument.
     * Modifies the document in place. Used by combined DOM pass in AI_OB.
     *
     * @param \DOMDocument $doc   Parsed HTML document.
     * @param \DOMXPath    $xpath XPath instance for the document.
     * @param string       $lang  Target language code.
     * @return void
     */
    public static function inject_dom(\DOMDocument $doc, \DOMXPath $xpath, string $lang): void
    {
        $default = AI_Lang::default();
        if ($default === null) {
            return;
        }

        $head = $doc->getElementsByTagName('head')->item(0);
        if (!$head) {
            $head = $doc->createElement('head');
            $htmlEl = $doc->getElementsByTagName('html')->item(0);
            if ($htmlEl) {
                $htmlEl->insertBefore($head, $htmlEl->firstChild);
            } else {
                $doc->insertBefore($head, $doc->firstChild);
            }
        }

        $isTranslatedLang = strtolower((string)$lang) !== strtolower((string)$default);

        // Translate <title> content for non-default languages
        try {
            if ($isTranslatedLang) {
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

        // 1) Meta Description
        $metaDescNodes = $xpath->query('//head/meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="description"]');
        $existingMetaDesc = null;
        if ($metaDescNodes && $metaDescNodes->length > 0) {
            $item = $metaDescNodes->item(0);
            if ($item instanceof \DOMElement) {
                $existingMetaDesc = $item;
            }
        }
        
        $desc = self::computeMetaDescription();
        $shouldReplace = false;
        
        if ($existingMetaDesc === null) {
            $shouldReplace = true;
        } else {
            $existingContent = trim((string) $existingMetaDesc->getAttribute('content'));
            if ($isTranslatedLang) {
                $shouldReplace = true;
            } elseif (is_front_page() || is_home()) {
                $homepage = self::get_homepage_meta_description();
                if ($homepage !== '') {
                    if ($existingContent === '' || $existingContent !== $homepage) {
                        $shouldReplace = true;
                    }
                } elseif ($existingContent === '') {
                    $shouldReplace = true;
                }
            } elseif ($existingContent === '') {
                $shouldReplace = true;
            }
        }
        
        if ($shouldReplace && $desc !== '') {
            $finalDesc = self::maybeTranslateMeta($desc, $default, $lang);
            $finalDesc = self::truncateUtf8($finalDesc, 200, '...');
            if ($finalDesc !== '') {
                if ($existingMetaDesc !== null) {
                    $existingMetaDesc->setAttribute('content', esc_attr($finalDesc));
                } else {
                    $meta = $doc->createElement('meta');
                    $meta->setAttribute('name', 'description');
                    $meta->setAttribute('content', esc_attr($finalDesc));
                    $head->appendChild($meta);
                    $head->appendChild($doc->createTextNode("\n"));
                }
            }
        } else {
            if ($isTranslatedLang && $existingMetaDesc !== null && $desc === '') {
                $existingContent = trim((string) $existingMetaDesc->getAttribute('content'));
                if ($existingContent !== '') {
                    $finalDesc = self::maybeTranslateMeta($existingContent, $default, $lang);
                    $finalDesc = self::truncateUtf8($finalDesc, 200, '...');
                    if ($finalDesc !== '') {
                        $existingMetaDesc->setAttribute('content', esc_attr($finalDesc));
                    }
                }
            }
        }

        // 2) Open Graph
        $ogTitleMissing = self::isOgMissing($xpath, 'og:title');
        $ogDescMissing = self::isOgMissing($xpath, 'og:description');
        $ogImageMissing = self::isOgMissing($xpath, 'og:image');
        $ogUrlMissing   = self::isOgMissing($xpath, 'og:url');
        
        $ogLocale = '';
        $langNorm = strtolower(trim((string) $lang));
        if ($langNorm !== '') {
            if (preg_match('/^([a-z]{2})[-_](.+)$/i', $langNorm, $m)) {
                $langPart = strtolower($m[1]);
                $regionPart = strtoupper(preg_replace('/[^a-z]/i', '', $m[2]));
                if ($regionPart !== '') {
                    $ogLocale = $langPart . '_' . $regionPart;
                }
            }
            if ($ogLocale === '') {
                $ogLocaleMap = [
                    'en' => 'en_US', 'nl' => 'nl_NL', 'de' => 'de_DE', 'fr' => 'fr_FR',
                    'es' => 'es_ES', 'it' => 'it_IT', 'pt' => 'pt_PT', 'ru' => 'ru_RU',
                    'ka' => 'ka_GE', 'fi' => 'fi_FI', 'sv' => 'sv_SE', 'no' => 'no_NO',
                    'da' => 'da_DK', 'pl' => 'pl_PL', 'cs' => 'cs_CZ', 'sk' => 'sk_SK',
                    'hu' => 'hu_HU', 'ro' => 'ro_RO', 'bg' => 'bg_BG', 'hr' => 'hr_HR',
                    'sl' => 'sl_SI', 'el' => 'el_GR', 'tr' => 'tr_TR', 'ar' => 'ar_SA',
                    'he' => 'he_IL', 'hi' => 'hi_IN', 'ja' => 'ja_JP', 'ko' => 'ko_KR',
                    'zh' => 'zh_CN', 'th' => 'th_TH', 'vi' => 'vi_VN', 'id' => 'id_ID',
                    'uk' => 'uk_UA', 'kk' => 'kk_KZ', 'et' => 'et_EE', 'ga' => 'ga_IE',
                    'lv' => 'lv_LV', 'lt' => 'lt_LT', 'mt' => 'mt_MT',
                ];
                $ogLocale = $ogLocaleMap[$langNorm] ?? $langNorm;
            }
        }
        
        if ($ogLocale !== '') {
            $existingOgLocale = $xpath->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:locale"]');
            if ($existingOgLocale && $existingOgLocale->length > 0) {
                for ($i = $existingOgLocale->length - 1; $i >= 0; $i--) {
                    $ogLocaleElem = $existingOgLocale->item($i);
                    if ($ogLocaleElem instanceof \DOMElement) {
                        $parent = $ogLocaleElem->parentNode;
                        if ($parent) {
                            $parent->removeChild($ogLocaleElem);
                        }
                    }
                }
            }
            $meta = $doc->createElement('meta');
            $meta->setAttribute('property', 'og:locale');
            $meta->setAttribute('content', esc_attr($ogLocale));
            $head->appendChild($meta);
            $head->appendChild($doc->createTextNode("\n"));
        }
        
        $shouldReplaceOgDesc = $isTranslatedLang;
        if (!$isTranslatedLang && (is_front_page() || is_home())) {
            $homepage = self::get_homepage_meta_description();
            if ($homepage !== '') {
                $shouldReplaceOgDesc = true;
            }
        }
        if ($ogDescMissing) {
            $shouldReplaceOgDesc = true;
        }
        
        $shouldReplaceOgImage = (is_front_page() || is_home());

        if ($ogTitleMissing || $shouldReplaceOgDesc || $ogImageMissing || $ogUrlMissing || $shouldReplaceOgImage) {
            $ogTitle = '';
            if ($ogTitleMissing) {
                $titleNode = $doc->getElementsByTagName('title')->item(0);
                if ($titleNode) {
                    $ogTitle = trim((string) $titleNode->textContent);
                }
                if ($ogTitle === '') {
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
                }
                if ($ogTitle !== '' && $isTranslatedLang) {
                    $ogTitle = self::maybeTranslateMeta($ogTitle, $default, $lang);
                }
            }

            $ogDesc = '';
            if ($shouldReplaceOgDesc) {
                $ogDesc = self::computeMetaDescription();
                if ($ogDesc !== '') {
                    $ogDesc = self::maybeTranslateMeta($ogDesc, $default, $lang);
                    $ogDesc = self::truncateUtf8($ogDesc, 200, '...');
                }
            }

            $ogImage = '';
            if ($ogImageMissing || $shouldReplaceOgImage) {
                if ($shouldReplaceOgImage) {
                    $logoId = apply_filters('ai_translate_homepage_logo_id', null);
                    if ($logoId && $logoId > 0) {
                        $url = wp_get_attachment_image_url($logoId, 'large');
                        if (is_string($url) && $url !== '') {
                            $ogImage = (string) $url;
                        }
                    }
                }
                if ($ogImage === '' && is_singular()) {
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
                    if (preg_match('#^https?://#i', $path)) {
                        $ogUrl = $path;
                    } else {
                        $ogUrl = home_url($path);
                    }
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
            if ($shouldReplaceOgDesc && $ogDesc !== '') {
                $existingOgDesc = $xpath->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:description"]');
                if ($existingOgDesc && $existingOgDesc->length > 0) {
                    $ogDescElem = $existingOgDesc->item(0);
                    if ($ogDescElem instanceof \DOMElement) {
                        $ogDescElem->setAttribute('content', esc_attr(trim($ogDesc)));
                    }
                } else {
                    $meta = $doc->createElement('meta');
                    $meta->setAttribute('property', 'og:description');
                    $meta->setAttribute('content', esc_attr(trim($ogDesc)));
                    $head->appendChild($meta);
                    $head->appendChild($doc->createTextNode("\n"));
                }
            } elseif ($isTranslatedLang && $shouldReplaceOgDesc && $ogDesc === '') {
                $existingOgDesc = $xpath->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:description"]');
                if ($existingOgDesc && $existingOgDesc->length > 0) {
                    $ogDescElem = $existingOgDesc->item(0);
                    if ($ogDescElem instanceof \DOMElement) {
                        $existingContent = trim((string) $ogDescElem->getAttribute('content'));
                        if ($existingContent !== '') {
                            $translatedOgDesc = self::maybeTranslateMeta($existingContent, $default, $lang);
                            $translatedOgDesc = self::truncateUtf8($translatedOgDesc, 200, '...');
                            if ($translatedOgDesc !== '') {
                                $ogDescElem->setAttribute('content', esc_attr(trim($translatedOgDesc)));
                            }
                        }
                    }
                }
            }
            if (($ogImageMissing || $shouldReplaceOgImage) && $ogImage !== '') {
                $existingOgImage = $xpath->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:image"]');
                if ($existingOgImage && $existingOgImage->length > 0) {
                    $ogImageElem = $existingOgImage->item(0);
                    if ($ogImageElem instanceof \DOMElement) {
                        $ogImageElem->setAttribute('content', esc_url($ogImage));
                    }
                } else {
                    $meta = $doc->createElement('meta');
                    $meta->setAttribute('property', 'og:image');
                    $meta->setAttribute('content', esc_url($ogImage));
                    $head->appendChild($meta);
                    $head->appendChild($doc->createTextNode("\n"));
                }
            }
            if ($isTranslatedLang) {
                $existingOgImageAlt = $xpath->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:image:alt"]');
                if ($existingOgImageAlt && $existingOgImageAlt->length > 0) {
                    $ogImageAltElem = $existingOgImageAlt->item(0);
                    if ($ogImageAltElem instanceof \DOMElement) {
                        $existingAlt = trim((string) $ogImageAltElem->getAttribute('content'));
                        if ($existingAlt !== '') {
                            $translatedAlt = self::maybeTranslateMeta($existingAlt, $default, $lang);
                            if (is_string($translatedAlt) && $translatedAlt !== '') {
                                $ogImageAltElem->setAttribute('content', esc_attr(trim($translatedAlt)));
                            }
                        }
                    }
                }
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
    }

    /**
     * Generate hreflang tags as plain text strings.
     *
     * @param string $lang
     * @param string $default
     * @return string
     */
    private static function generateHreflangTags($lang, $default)
    {
        $settings = get_option('ai_translate_settings', []);
        $enabled = isset($settings['enabled_languages']) && is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : [];
        $detectable = isset($settings['detectable_languages']) && is_array($settings['detectable_languages']) ? $settings['detectable_languages'] : [];
        $langs = array_values(array_unique(array_merge($enabled, $detectable)));
        if (is_string($default) && $default !== '' && !in_array($default, $langs, true)) {
            $langs[] = $default;
        }

        $currentAbs = self::currentPageUrlAbsolute();
        $defaultUrl = '';
        
        $post_id = 0;
        if (is_singular()) {
            $post_id = get_queried_object_id();
        }

        $tags = [];
        foreach ($langs as $lc) {
            $lc = sanitize_key((string) $lc);
            
            $href = self::buildHreflangUrl($currentAbs, $lc, $default, $post_id);
            if ($href === '') {
                continue;
            }
            
            if ($lc === $default) {
                $defaultUrl = $href;
            }
            
            $tags[] = '<link rel="alternate" hreflang="' . esc_attr($lc) . '" href="' . esc_url($href) . '" />';
        }

        if (is_string($default) && $default !== '') {
            if ($defaultUrl === '') {
                $defaultUrl = self::buildHreflangUrl($currentAbs, $default, $default, $post_id);
            }
            if ($defaultUrl !== '') {
                $tags[] = '<link rel="alternate" hreflang="x-default" href="' . esc_url($defaultUrl) . '" />';
            }
        }

        return implode("\n", $tags);
    }

    /**
     * Delegate to centralized active domain resolver.
     *
     * @return string
     */
    private static function get_active_domain()
    {
        return AI_Translate_Core::get_active_domain();
    }

    /**
     * Delegate to centralized homepage meta description.
     *
     * @return string
     */
    private static function get_homepage_meta_description()
    {
        return AI_Translate_Core::get_homepage_meta_description();
    }

    /**
     * Delegate to centralized website context.
     *
     * @return string
     */
    private static function get_website_context()
    {
        return AI_Translate_Core::get_website_context();
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
            $homepage = self::get_homepage_meta_description();
            if ($homepage !== '') {
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
        $blogdesc = (string) get_option('blogdescription', '');
        return $blogdesc;
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
        
        // Skip translating slug-like strings (menu items, generated slugs)
        // Heuristic: single line with hyphens but no spaces (e.g., "vioolles-eindhoven", "lezioni-violino-eindhoven")
        if (preg_match('/^[a-z0-9\-]+$/i', trim($text)) && strpos($text, ' ') === false && strpos($text, '-') !== false) {
            // This looks like a slug/menu-item, not real content - return as-is
            return $text;
        }
        
        $plan = ['segments' => [ ['id' => 'm', 'text' => (string)$text, 'type' => 'node'] ]];
        $ctx = [
            'website_context' => self::get_website_context(),
        ];
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
        // Remove all existing hreflang alternate links to ensure clean injection
        // This prevents conflicts with incorrectly generated hreflang tags from other sources
        $links = $xpath->query('//head/link[@rel="alternate" and @hreflang]');
        if ($links) {
            foreach ($links as $lnk) {
                if ($lnk instanceof \DOMElement && $lnk->parentNode) {
                    $lnk->parentNode->removeChild($lnk);
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
        
        // Determine post_id for singular pages to enable slug translation
        $post_id = 0;
        if (is_singular()) {
            $post_id = get_queried_object_id();
        }

        foreach ($langs as $lc) {
            $lc = sanitize_key((string) $lc);
            
            // Build URL with translated slug if available
            $href = self::buildHreflangUrl($currentAbs, $lc, $default, $post_id);
            if ($href === '') {
                continue;
            }
            
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
        if (is_string($default) && $default !== '' ) {
            if ($defaultUrl === '') {
                $defaultUrl = self::buildHreflangUrl($currentAbs, $default, $default, $post_id);
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
     * Build hreflang URL with translated slug for a given language.
     *
     * @param string $currentUrl
     * @param string $targetLang
     * @param string $default
     * @param int $post_id
     * @return string
     */
    private static function buildHreflangUrl($currentUrl, $targetLang, $default, $post_id)
    {
        // For homepage
        if (is_front_page() || is_home()) {
            if (strtolower($targetLang) === strtolower($default)) {
                return home_url('/');
            }
            return home_url('/' . $targetLang . '/');
        }
        
        // For singular posts/pages with slug translation
        if ($post_id > 0) {
            $translatedSlug = AI_Slugs::get_or_generate($post_id, $targetLang);
            if ($translatedSlug !== null) {
                if (strtolower($targetLang) === strtolower($default)) {
                    // Default language: no language prefix
                    return home_url('/' . ltrim($translatedSlug, '/') . '/');
                }
                // Non-default: include language prefix
                return home_url('/' . $targetLang . '/' . ltrim($translatedSlug, '/') . '/');
            }
        }
        
        // Fallback: use basic rewrite (language prefix only)
        $path = AI_URL::rewrite_single_href($currentUrl, $targetLang, $default);
        if (!is_string($path) || $path === '') {
            return '';
        }
        return home_url($path);
    }

    /**
     * Return the translated URL for a post and language (same URL as hreflang uses).
     * Use this for warm cache, crawlers, or any code that needs the canonical translated URL.
     *
     * @param int    $post_id Post ID (0 = homepage).
     * @param string $lang    Language code.
     * @return string Full URL or empty string on failure.
     */
    public static function get_translated_url($post_id, $lang)
    {
        $lang    = sanitize_key((string) $lang);
        $default = AI_Lang::default();
        if ($default === null || $default === '') {
            return '';
        }

        $front_page_id = (int) get_option('page_on_front');
        if ($front_page_id > 0 && (int) $post_id === $front_page_id) {
            if (strtolower($lang) === strtolower($default)) {
                return home_url('/');
            }
            return home_url('/' . $lang . '/');
        }

        if ((int) $post_id === 0) {
            if (strtolower($lang) === strtolower($default)) {
                return home_url('/');
            }
            return home_url('/' . $lang . '/');
        }

        // 404 page: use a non-existent path to trigger a 404 response
        if ((int) $post_id === -1) {
            return home_url('/' . $lang . '/ait-404-cache-warm/');
        }

        $translatedSlug = AI_Slugs::get_or_generate((int) $post_id, $lang);
        if ($translatedSlug !== null && $translatedSlug !== '') {
            if (strtolower($lang) === strtolower($default)) {
                return home_url('/' . ltrim($translatedSlug, '/') . '/');
            }
            return home_url('/' . $lang . '/' . ltrim($translatedSlug, '/') . '/');
        }

        $currentUrl = get_permalink((int) $post_id);
        if (!$currentUrl) {
            return '';
        }
        $path = AI_URL::rewrite_single_href($currentUrl, $lang, $default);
        if (!is_string($path) || $path === '') {
            return '';
        }
        return home_url($path);
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
        // Search entire document, not just <head>. DOMDocument may move OG tags from
        // Jetpack/Yoast/RankMath to <body> when placeholder <div>s break <head> structure.
        $q = sprintf('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]', strtolower($prop));
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
     * Delegate to centralized UTF-8 helper.
     *
     * @param string $html
     * @return string
     */
    private static function ensureUtf8($html)
    {
        return AI_DOM::ensureUtf8($html);
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
        $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        // URL decode only if double-encoded (contains %25)
        if (strpos($req_uri, '%25') !== false) {
            $req_uri = urldecode($req_uri);
        }
        $path = (string) parse_url($req_uri, PHP_URL_PATH);
        if ($path === '') { $path = '/'; }
        return home_url($path);
    }

    /**
     * Translate Twitter Card meta tags in final HTML.
     * Runs on the fully assembled HTML (after DOMDocument + placeholder restore)
     * because DOMDocument may move Jetpack meta tags out of <head> when
     * preceding script/style placeholders break the head structure.
     *
     * @param string $html Full HTML output.
     * @param string $lang Target language code.
     * @return string HTML with translated Twitter Card meta tags.
     */
    public static function translateTwitterCards($html, $lang)
    {
        $default = AI_Lang::default();
        if ($default === null || strtolower((string) $lang) === strtolower((string) $default)) {
            return $html;
        }

        $twitterNames = ['twitter:text:title', 'twitter:title', 'twitter:description', 'twitter:image:alt'];
        $names = implode('|', array_map(function ($n) { return preg_quote($n, '#'); }, $twitterNames));
        $pattern = '#(<meta\s+name="(?:' . $names . ')"\s+content=")([^"]+)("[^>]*>)#i';

        return preg_replace_callback($pattern, function ($m) use ($default, $lang) {
            $original = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (trim($original) === '') {
                return $m[0];
            }
            $translated = self::maybeTranslateMeta($original, $default, $lang);
            if (!is_string($translated) || $translated === '' || $translated === $original) {
                return $m[0];
            }
            return $m[1] . esc_attr($translated) . $m[3];
        }, $html);
    }

    /**
     * Translate JSON-LD BreadcrumbList name fields in final HTML.
     * Called after script placeholders have been restored.
     *
     * @param string $html Full HTML output.
     * @param string $lang Target language code.
     * @return string HTML with translated JSON-LD.
     */
    public static function translateJsonLd($html, $lang)
    {
        $default = AI_Lang::default();
        if ($default === null || strtolower((string) $lang) === strtolower((string) $default)) {
            return $html;
        }

        return preg_replace_callback(
            '#(<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>)(.*?)(</script>)#is',
            function ($m) use ($default, $lang) {
                $json = json_decode($m[2], true);
                if (!is_array($json)) {
                    return $m[0];
                }

                $changed = self::translateJsonLdNode($json, $default, $lang);
                if (!$changed) {
                    return $m[0];
                }

                $encoded = wp_json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($encoded) || $encoded === '') {
                    return $m[0];
                }
                return $m[1] . $encoded . $m[3];
            },
            $html
        );
    }

    /**
     * Recursively translate name/description fields in JSON-LD BreadcrumbList nodes.
     *
     * @param array  &$node   JSON-LD node (modified in place).
     * @param string $default Default language.
     * @param string $lang    Target language.
     * @return bool Whether any field was translated.
     */
    private static function translateJsonLdNode(array &$node, $default, $lang)
    {
        $type = $node['@type'] ?? '';
        $changed = false;        if ($type === 'BreadcrumbList' && isset($node['itemListElement']) && is_array($node['itemListElement'])) {
            foreach ($node['itemListElement'] as &$item) {
                if (isset($item['name']) && is_string($item['name']) && trim($item['name']) !== '') {
                    $translated = self::maybeTranslateMeta($item['name'], $default, $lang);
                    if (is_string($translated) && $translated !== '' && $translated !== $item['name']) {
                        $item['name'] = $translated;
                        $changed = true;
                    }
                }
            }
            unset($item);
        }        // Handle @graph arrays (used by Yoast and others)
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as &$graphNode) {
                if (is_array($graphNode)) {
                    if (self::translateJsonLdNode($graphNode, $default, $lang)) {
                        $changed = true;
                    }
                }
            }
            unset($graphNode);
        }        return $changed;
    }
}
