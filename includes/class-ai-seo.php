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
        
        if (is_front_page() || is_home()) {
            $homepage_setting = self::get_homepage_meta_description();
            $blogdesc = (string) get_option('blogdescription', '');
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

        // 1) Meta Description (add if missing, or replace if empty/not admin setting for homepage)
        // For translated languages: always use computed description (admin setting) and translate it
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
        $isTranslatedLang = $default && $lang && strtolower((string)$lang) !== strtolower((string)$default);
        
        if ($existingMetaDesc === null) {
            // No meta description exists, add one
            $shouldReplace = true;
        } else {
            // Meta description exists, check if we should replace it
            $existingContent = trim((string) $existingMetaDesc->getAttribute('content'));
            
            // For translated languages: always replace with computed description (admin setting)
            if ($isTranslatedLang) {
                $shouldReplace = true;
            } elseif (is_front_page() || is_home()) {
                // For default language homepage: replace if empty or if admin setting exists and doesn't match
                $homepage = self::get_homepage_meta_description();
                if ($homepage !== '') {
                    // Admin setting exists: replace if empty or different
                    if ($existingContent === '' || $existingContent !== $homepage) {
                        $shouldReplace = true;
                    }
                } elseif ($existingContent === '') {
                    // No admin setting but existing is empty, use computed description
                    $shouldReplace = true;
                }
            } elseif ($existingContent === '') {
                // Not homepage, but existing is empty, use computed description
                $shouldReplace = true;
            }
        }
        
        if ($shouldReplace && $desc !== '') {
            $finalDesc = self::maybeTranslateMeta($desc, $default, $lang);
            $finalDesc = self::truncateUtf8($finalDesc, 200, '...');
            if ($finalDesc !== '') {
                if ($existingMetaDesc !== null) {
                    // Replace existing meta description
                    $existingMetaDesc->setAttribute('content', esc_attr($finalDesc));
                } else {
                    // Add new meta description
                    $meta = $doc->createElement('meta');
                    $meta->setAttribute('name', 'description');
                    $meta->setAttribute('content', esc_attr($finalDesc));
                    $head->appendChild($meta);
                    $head->appendChild($doc->createTextNode("\n"));
                }
            }
        } else {
            // For translated languages: ensure existing meta description is always translated
            if ($isTranslatedLang && $existingMetaDesc !== null && $desc === '') {
                // Even if computeMetaDescription returned empty, translate existing if present
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

        // 2) Open Graph (add missing og:* tags or replace for translated languages)
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
                    'en' => 'en_US',
                    'nl' => 'nl_NL',
                    'de' => 'de_DE',
                    'fr' => 'fr_FR',
                    'es' => 'es_ES',
                    'it' => 'it_IT',
                    'pt' => 'pt_PT',
                    'ru' => 'ru_RU',
                    'ka' => 'ka_GE',
                    'fi' => 'fi_FI',
                    'sv' => 'sv_SE',
                    'no' => 'no_NO',
                    'da' => 'da_DK',
                    'pl' => 'pl_PL',
                    'cs' => 'cs_CZ',
                    'sk' => 'sk_SK',
                    'hu' => 'hu_HU',
                    'ro' => 'ro_RO',
                    'bg' => 'bg_BG',
                    'hr' => 'hr_HR',
                    'sl' => 'sl_SI',
                    'el' => 'el_GR',
                    'tr' => 'tr_TR',
                    'ar' => 'ar_SA',
                    'he' => 'he_IL',
                    'hi' => 'hi_IN',
                    'ja' => 'ja_JP',
                    'ko' => 'ko_KR',
                    'zh' => 'zh_CN',
                    'th' => 'th_TH',
                    'vi' => 'vi_VN',
                    'id' => 'id_ID',
                    'uk' => 'uk_UA',
                    'kk' => 'kk_KZ',
                    'et' => 'et_EE',
                    'ga' => 'ga_IE',
                    'lv' => 'lv_LV',
                    'lt' => 'lt_LT',
                    'mt' => 'mt_MT',
                ];
                $ogLocale = $ogLocaleMap[$langNorm] ?? $langNorm;
            }
        }
        
        if ($ogLocale !== '') {
            // Always remove ALL existing og:locale tags first to ensure no duplicates
            $existingOgLocale = $xpath->query('//head/meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:locale"]');
            if ($existingOgLocale && $existingOgLocale->length > 0) {
                // Remove all existing og:locale tags (iterate backwards to avoid index issues)
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
            // Always add exactly one correct og:locale tag
            $meta = $doc->createElement('meta');
            $meta->setAttribute('property', 'og:locale');
            $meta->setAttribute('content', esc_attr($ogLocale));
            $head->appendChild($meta);
            $head->appendChild($doc->createTextNode("\n"));
        }
        
        // For all languages: synchronize og:description with computed meta description (admin setting)
        // For translated languages: always replace; for default language: replace if admin setting is set
        $shouldReplaceOgDesc = $isTranslatedLang;
        if (!$isTranslatedLang && (is_front_page() || is_home())) {
            // For default language homepage: check if admin setting is set
            $homepage = self::get_homepage_meta_description();
            if ($homepage !== '') {
                $shouldReplaceOgDesc = true;
            }
        }
        // Also replace if og:description is missing
        if ($ogDescMissing) {
            $shouldReplaceOgDesc = true;
        }
        
        // On homepage: always replace og:image with domain logo (if available via site_icon)
        $shouldReplaceOgImage = (is_front_page() || is_home());

        if ($ogTitleMissing || $shouldReplaceOgDesc || $ogImageMissing || $ogUrlMissing || $shouldReplaceOgImage) {
            $ogTitle = '';
            if ($ogTitleMissing) {
                // Use the title tag content directly (without adding site name)
                $titleNode = $doc->getElementsByTagName('title')->item(0);
                if ($titleNode) {
                    $ogTitle = trim((string) $titleNode->textContent);
                }
                // Fallback if title tag is not available
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
                // Translate og:title for translated languages
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
                // On homepage: check if a custom logo ID is provided via filter (only for sites that want to replace)
                if ($shouldReplaceOgImage) {
                    $logoId = apply_filters('ai_translate_homepage_logo_id', null);
                    if ($logoId && $logoId > 0) {
                        $url = wp_get_attachment_image_url($logoId, 'large');
                        if (is_string($url) && $url !== '') {
                            $ogImage = (string) $url;
                        }
                    }
                }
                
                // Normal logic: use featured image for singular pages (including homepage if it's a page)
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
                
                // Final fallback to theme logo
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
                    // Check if path is already a full URL (starts with http:// or https://)
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
                // Find existing og:description to replace, or add new one
                $existingOgDesc = $xpath->query('//head/meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:description"]');
                if ($existingOgDesc && $existingOgDesc->length > 0) {
                    // Replace existing og:description
                    $ogDescElem = $existingOgDesc->item(0);
                    if ($ogDescElem instanceof \DOMElement) {
                        $ogDescElem->setAttribute('content', esc_attr(trim($ogDesc)));
                    }
                } else {
                    // Add new og:description
                    $meta = $doc->createElement('meta');
                    $meta->setAttribute('property', 'og:description');
                    $meta->setAttribute('content', esc_attr(trim($ogDesc)));
                    $head->appendChild($meta);
                    $head->appendChild($doc->createTextNode("\n"));
                }
            } elseif ($isTranslatedLang && $shouldReplaceOgDesc && $ogDesc === '') {
                // For translated languages: translate existing og:description if computed is empty
                $existingOgDesc = $xpath->query('//head/meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:description"]');
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
                // Find existing og:image to replace, or add new one
                $existingOgImage = $xpath->query('//head/meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:image"]');
                if ($existingOgImage && $existingOgImage->length > 0) {
                    // Replace existing og:image
                    $ogImageElem = $existingOgImage->item(0);
                    if ($ogImageElem instanceof \DOMElement) {
                        $ogImageElem->setAttribute('content', esc_url($ogImage));
                    }
                } else {
                    // Add new og:image
                    $meta = $doc->createElement('meta');
                    $meta->setAttribute('property', 'og:image');
                    $meta->setAttribute('content', esc_url($ogImage));
                    $head->appendChild($meta);
                    $head->appendChild($doc->createTextNode("\n"));
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
     * Get the active domain for multi-domain caching support.
     *
     * @return string
     */
    private static function get_active_domain()
    {
        $active_domain = '';
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $active_domain = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
            // Remove port if present (e.g., "example.com:8080" -> "example.com")
            if (strpos($active_domain, ':') !== false) {
                $active_domain = strtok($active_domain, ':');
            }
        }
        
        // Fallback to SERVER_NAME if HTTP_HOST is not available
        if (empty($active_domain) && isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $active_domain = sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME']));
        }
        
        // Final fallback to home_url() host (should rarely be needed)
        if (empty($active_domain)) {
            $active_domain = parse_url(home_url(), PHP_URL_HOST);
            if (empty($active_domain)) {
                $active_domain = 'default';
            }
        }
        
        return $active_domain;
    }

    /**
     * Get homepage meta description for the current domain.
     * Supports per-domain meta descriptions when multi-domain caching is enabled.
     *
     * @return string
     */
    private static function get_homepage_meta_description()
    {
        $settings = get_option('ai_translate_settings', []);
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
        
        if ($multi_domain) {
            // Multi-domain caching enabled: use per-domain meta description
            $active_domain = self::get_active_domain();
            $domain_meta = isset($settings['homepage_meta_description_per_domain']) && is_array($settings['homepage_meta_description_per_domain']) 
                ? $settings['homepage_meta_description_per_domain'] 
                : [];
            
            if (isset($domain_meta[$active_domain]) && trim((string) $domain_meta[$active_domain]) !== '') {
                return trim((string) $domain_meta[$active_domain]);
            }
        }
        
        // Fallback to global homepage meta description
        $homepage = isset($settings['homepage_meta_description']) ? (string) $settings['homepage_meta_description'] : '';
        return trim($homepage);
    }

    /**
     * Get website context for the current domain.
     * Supports per-domain website context when multi-domain caching is enabled.
     *
     * @return string
     */
    private static function get_website_context()
    {
        $settings = get_option('ai_translate_settings', []);
        $multi_domain = isset($settings['multi_domain_caching']) ? (bool) $settings['multi_domain_caching'] : false;
        
        if ($multi_domain) {
            // Multi-domain caching enabled: use per-domain website context
            $active_domain = self::get_active_domain();
            $domain_context = isset($settings['website_context_per_domain']) && is_array($settings['website_context_per_domain']) 
                ? $settings['website_context_per_domain'] 
                : [];
            
            if (isset($domain_context[$active_domain]) && trim((string) $domain_context[$active_domain]) !== '') {
                return trim((string) $domain_context[$active_domain]);
            }
        }
        
        // Fallback to global website context
        $context = isset($settings['website_context']) ? (string) $settings['website_context'] : '';
        return trim($context);
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

        if ((int) $post_id === 0) {
            if (strtolower($lang) === strtolower($default)) {
                return home_url('/');
            }
            return home_url('/' . $lang . '/');
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
        $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        // URL decode only if double-encoded (contains %25)
        if (strpos($req_uri, '%25') !== false) {
            $req_uri = urldecode($req_uri);
        }
        $path = (string) parse_url($req_uri, PHP_URL_PATH);
        if ($path === '') { $path = '/'; }
        return home_url($path);
    }
}


