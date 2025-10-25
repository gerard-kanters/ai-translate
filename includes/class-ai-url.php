<?php

namespace AITranslate;

/**
 * URL rewriting utilities for internal links and slug mapping.
 */
final class AI_URL
{
    /**
     * Rewrite internal links based on current language.
     *
     * @param string $html
     * @param string $lang
     * @return string
     */
    public static function rewrite($html, $lang)
    {
        if (is_admin()) {
            return $html;
        }
        $default = AI_Lang::default();
        if ($lang === null || $default === null) {
            return $html;
        }

        $doc = new \DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $flags = LIBXML_HTML_NODEFDTD;
        $doc->loadHTML($html, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $xpath = new \DOMXPath($doc);
        $links = $xpath->query('//a[@href]');
        if ($links) {
            foreach ($links as $a) {
                /** @var \DOMElement $a */
                // Skip language switcher or marked links
                if ($a->hasAttribute('data-ai-trans-skip')) {
                    continue;
                }
                $href = (string) $a->getAttribute('href');
                if ($href === '' || self::isSkippableHref($href)) {
                    continue;
                }
                $new = self::rewriteHref($href, $lang, $default);
                if ($new !== null) {
                    $a->setAttribute('href', $new);
                }
            }
        }

        // Rewrite singular permalinks using slug map when possible
        $permalinks = $xpath->query('//a[@href]');
        if ($permalinks) {
            foreach ($permalinks as $a) {
                /** @var \DOMElement $a */
                // Skip language switcher or marked links
                if ($a->hasAttribute('data-ai-trans-skip')) {
                    continue;
                }
                $href = (string) $a->getAttribute('href');
                if ($href === '' || self::isSkippableHref($href)) continue;
                // For posts index pagination, prefer ?blogpage=N variant to keep theme compatible
                $hrefParts = wp_parse_url($href);
                $path = isset($hrefParts['path']) ? (string) $hrefParts['path'] : '';
                if ($path !== '' && preg_match('#/page/([0-9]+)/?$#', $path, $mm)) {
                    $postsPageId = (int) get_option('page_for_posts');
                    if ($postsPageId > 0) {
                        $postsSlug = (string) get_page_uri($postsPageId);
                        $translatedSlug = \AITranslate\AI_Slugs::get_or_generate($postsPageId, $lang);
                        if (!is_string($translatedSlug) || $translatedSlug === '') {
                            $translatedSlug = $postsSlug;
                        }
                        // Build base posts index URL in current language
                        $base = (strtolower($lang) === strtolower($default))
                            ? ('/' . trim($postsSlug, '/') . '/')
                            : ('/' . $lang . '/' . trim($translatedSlug, '/') . '/');
                        $n = max(1, (int) $mm[1]);
                        // Merge existing query and set blogpage
                        $params = array();
                        if (isset($hrefParts['query']) && $hrefParts['query'] !== '') {
                            parse_str((string)$hrefParts['query'], $params);
                        }
                        $params['blogpage'] = $n;
                        $q = '?' . http_build_query($params);
                        $frag = isset($hrefParts['fragment']) ? ('#' . $hrefParts['fragment']) : '';
                        $a->setAttribute('href', $base . $q . $frag);
                        continue;
                    }
                }
                $translated = self::rewriteSingularWithSlugMap($href, $lang, $default);
                if ($translated) {
                    $a->setAttribute('href', $translated);
                }
            }
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
        
        // Preserve original framing and only update the body inner content with rewritten URLs
        if (preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', (string) $result, $bodyNew) &&
            preg_match('/<body\b[^>]*>([\s\S]*?)<\/body>/i', (string) $html, $bodyOrig)) {
            
            $newBodyInner = (string) $bodyNew[1];
            
            // Only replace body inner content (URLs have been rewritten), keep everything else from original
            $result = (string) preg_replace('/(<body\b[^>]*>)[\s\S]*?(<\/body>)/i', '$1' . $newBodyInner . '$2', (string) $html, 1);
        }
        
        return $result;
    }

    /**
     * Rewrite a single URL (relative or absolute) to the target language path.
     * Returns a site-relative path with query/fragment when internal; null when external or not rewritable.
     *
     * @param string $href
     * @param string $lang
     * @param string|null $default
     * @return string|null
     */
    public static function rewrite_single_href($href, $lang, $default = null)
    {
        if ($default === null) {
            $default = AI_Lang::default();
        }
        if ($lang === null || $default === null) {
            return null;
        }
        return self::rewriteHref($href, $lang, $default);
    }

    private static function isSkippableHref($href)
    {
        $href = ltrim($href);
        if ($href === '' || $href[0] === '#') return true;
        $lower = strtolower($href);
        if (str_starts_with($lower, 'mailto:')) return true;
        if (str_starts_with($lower, 'tel:')) return true;
        if (str_starts_with($lower, 'javascript:')) return true;
        // Never rewrite admin/auth/REST endpoints
        if (strpos($lower, '/wp-admin/') !== false) return true;
        if (strpos($lower, 'wp-login.php') !== false) return true;
        if (strpos($lower, '/wp-json/') !== false) return true;
        if (strpos($lower, 'admin-ajax.php') !== false) return true;
        if (strpos($lower, 'rest_route=') !== false) return true;
        return false;
    }

    private static function rewriteHref($href, $lang, $default)
    {
        $home = home_url('/');
        $homeParts = wp_parse_url($home);
        $hrefParts = wp_parse_url($href);

        // Determine if internal
        $isRelative = !isset($hrefParts['host']) && !isset($hrefParts['scheme']);
        $isInternal = $isRelative;
        if (!$isInternal && isset($hrefParts['host']) && isset($homeParts['host'])) {
            $isInternal = strtolower($hrefParts['host']) === strtolower($homeParts['host']);
        }
        if (!$isInternal) {
            return null; // leave external links unchanged
        }

        // Build path
        $path = isset($hrefParts['path']) ? $hrefParts['path'] : '/';
        $path = self::normalizePath($path, $homeParts);
        // Do not attempt to split arbitrary two-letter prefixes; rely on proper rewrite rules

        // Special handling for blog pagination links and posts index:
        // - Theme may use '/?blogpage=N' without a path → rewrite to '/{lang}/{translated-posts-slug}/page/N/'
        // - Path '/{posts-slug}/page/N/' → rewrite to '/{lang}/{translated-posts-slug}/page/N/'
        $postsPageId = (int) get_option('page_for_posts');
        if ($postsPageId > 0) {
            $postsSlug = (string) get_page_uri($postsPageId); // source slug, e.g., 'nieuws'
            $translatedSlug = \AITranslate\AI_Slugs::get_or_generate($postsPageId, $lang);
            if (!is_string($translatedSlug) || $translatedSlug === '') {
                $translatedSlug = $postsSlug;
            }
            $query = isset($hrefParts['query']) ? (string) $hrefParts['query'] : '';
            $params = array();
            if ($query !== '') {
                parse_str($query, $params);
            }
            $hasBlogPage = isset($params['blogpage']) && (int) $params['blogpage'] > 0;

            // Case A: '/?blogpage=N' or '/{lang}/?blogpage=N' (path is root)
            $pathNoLang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
            if ($pathNoLang === '') { $pathNoLang = '/'; }
            if (($path === '/' || $pathNoLang === '/') && $hasBlogPage) {
                $n = max(1, (int) $params['blogpage']);
                // Build language-aware path to posts index with blogpage query
                if (strtolower($lang) === strtolower($default)) {
                    $newPath = '/' . trim($postsSlug, '/') . '/';
                } else {
                    $newPath = '/' . $lang . '/' . trim($translatedSlug, '/') . '/';
                }
                $frag = isset($hrefParts['fragment']) ? ('#' . $hrefParts['fragment']) : '';
                // Ensure blogpage stays in querystring
                if (!isset($params['blogpage'])) { $params['blogpage'] = $n; }
                $q = '?' . http_build_query($params);
                return $newPath . $q . $frag;
            }

            // Case B: '/{postsSlug}/page/N/' or '/{lang}/{translatedSlug}/page/N/'
            $segments = array_values(array_filter(explode('/', ltrim($pathNoLang, '/'))));
            if (!empty($segments)) {
                $first = isset($segments[0]) ? (string) $segments[0] : '';
                $hasPageSeg = false;
                $n = 0;
                for ($i = 0; $i < count($segments) - 1; $i++) {
                    if ($segments[$i] === 'page' && ctype_digit((string) $segments[$i+1])) {
                        $hasPageSeg = true;
                        $n = (int) $segments[$i+1];
                        break;
                    }
                }
                if ($hasPageSeg && ($first === $postsSlug || $first === $translatedSlug)) {
                    if (strtolower($lang) === strtolower($default)) {
                        $newPath = '/' . trim($postsSlug, '/') . '/';
                    } else {
                        $newPath = '/' . $lang . '/' . trim($translatedSlug, '/') . '/';
                    }
                    // Convert page/N to blogpage=N query
                    $params = array();
                    if (isset($hrefParts['query']) && $hrefParts['query'] !== '') {
                        parse_str((string)$hrefParts['query'], $params);
                    }
                    $params['blogpage'] = max(1, $n);
                    $q = '?' . http_build_query($params);
                    $frag = isset($hrefParts['fragment']) ? ('#' . $hrefParts['fragment']) : '';
                    return $newPath . $q . $frag;
                }
            }
        }

        // If current lang is default: strip any leading /xx/ prefix
        if (strtolower($lang) === strtolower($default)) {
            $path = preg_replace('#^/[a-z]{2}(?=/|$)#i', '', $path);
            if ($path === '') { $path = '/'; }
        } else {
            // Always ensure prefix /{lang}/ and avoid double language
            $pathNoLang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
            $path = '/' . $lang . '/' . ltrim($pathNoLang, '/');
        }

        $query = isset($hrefParts['query']) ? ('?' . $hrefParts['query']) : '';
        $frag = isset($hrefParts['fragment']) ? ('#' . $hrefParts['fragment']) : '';
        return $path . $query . $frag; // relative site-path keeps site portable
    }

    private static function normalizePath($path, $homeParts)
    {
        if ($path === '') return '/';
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        // Do not auto-insert slashes after arbitrary 2-letter prefixes to avoid truncating paths like '/contact'
        // Remove site subdir if WP is installed in a subdirectory
        $sitePath = isset($homeParts['path']) ? rtrim($homeParts['path'], '/') : '';
        if ($sitePath !== '' && str_starts_with($path, $sitePath . '/')) {
            $path = substr($path, strlen($sitePath));
            if ($path === '') $path = '/';
        }
        return $path;
    }

    private static function rewriteSingularWithSlugMap($href, $lang, $default)
    {
        // Only apply when on non-default target language
        if ($lang === null || $default === null) return null;
        if (strtolower($lang) === strtolower($default)) return null;

        $home = home_url('/');
        $homeParts = wp_parse_url($home);
        $hrefParts = wp_parse_url($href);
        $isRelative = !isset($hrefParts['host']) && !isset($hrefParts['scheme']);
        $isInternal = $isRelative;
        if (!$isInternal && isset($hrefParts['host']) && isset($homeParts['host'])) {
            $isInternal = strtolower($hrefParts['host']) === strtolower($homeParts['host']);
        }
        if (!$isInternal) return null;

        $path = isset($hrefParts['path']) ? $hrefParts['path'] : '/';
        $path = self::normalizePath($path, $homeParts);
        // Strip any leading language from the path to resolve the original post
        $pathNoLang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
        if ($pathNoLang === '') $pathNoLang = '/';

        // Resolve by source slug: map any translated slug back to source, then locate post
        $basenameNoLang = trim(basename($pathNoLang), '/');
        $source = \AITranslate\AI_Slugs::resolve_any_to_source_slug($basenameNoLang);
        if ($source === null) {
            $source = $basenameNoLang; // assume already source
        }
        $dirname = rtrim(dirname($pathNoLang), '/');
        if ($dirname === '\\' || $dirname === '.') $dirname = '';
        $reconstructed = $dirname !== '' ? ('/' . trim($dirname, '/') . '/' . $source) : ('/' . $source);
        $fullOriginal = home_url($reconstructed);
        $post_id = url_to_postid($fullOriginal);
        if (!$post_id) return null;

        $translated_slug = \AITranslate\AI_Slugs::get_or_generate($post_id, $lang);
        if (!$translated_slug) return null;

        // Replace the basename with translated slug and prefix language
        // Use source basename (may be Unicode) for replacement
        $basename = $source;
        $dirname = rtrim(dirname($pathNoLang), '/');
        if ($dirname === '\\' || $dirname === '.') $dirname = '';
        $newPath = '/' . $lang . '/';
        if ($dirname !== '') {
            // strip existing language code from dirname
            $dirname = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', '/' . ltrim($dirname, '/'));
            $newPath .= trim($dirname, '/') . '/';
        }
        // If homepage => '/{lang}/'
        if ($basename === '' || $basename === 'home' || $basename === 'index') {
            $newPath = '/' . $lang . '/';
        } else {
            // If translation failed fallback is source; still build path
            $newPath .= $translated_slug !== '' ? $translated_slug : $basename;
        }
        // If original path had trailing slash, keep it
        if (substr($path, -1) === '/') {
            $newPath .= '/';
        }

        $query = isset($hrefParts['query']) ? ('?' . $hrefParts['query']) : '';
        $frag = isset($hrefParts['fragment']) ? ('#' . $hrefParts['fragment']) : '';
        return $newPath . $query . $frag;
    }
}


