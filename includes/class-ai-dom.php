<?php

namespace AITranslate;

/**
 * DOM extraction and merge for translation.
 */
final class AI_DOM
{
    /**
     * Build a translation plan from HTML.
     *
     * @param string $html
     * @return array{doc: \DOMDocument, segments: array<int,array{id:string,text:string,type:string,attr?:string}>, nodeIndex: array<string,mixed>}
     */
    public static function plan($html)
    {
        // CRITICAL: Remove speculationrules script tags completely before DOM processing
        // These contain JSON that DOMDocument corrupts when parsing/saving
        // Since prefetch/prerender rules should never be translated anyway, removing is safe
        $html = preg_replace('/<script\s+type=["\']?speculationrules["\']?[^>]*>[\s\S]*?<\/script>/i', '', $html);
        
        // Extract and preserve other script/style tags to prevent DOMDocument from corrupting them
        $placeholders = [];
        $placeholder_counter = 0;
        
        // Extract <script> tags (except speculationrules which is already removed)
        $html = self::extractAndReplace($html, 'script', $placeholders, $placeholder_counter);
        
        // Extract <style> tags
        $html = self::extractAndReplace($html, 'style', $placeholders, $placeholder_counter);

        $doc = new \DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);
        $htmlToLoad = self::ensureUtf8($html);
        $flags = LIBXML_HTML_NODEFDTD;
        // Hint UTF-8 to libxml to avoid mojibake for emojis/special chars
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $htmlToLoad, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        $segments = [];
        $nodeIndex = [];
        $counter = 0;
        $visitedTextNodes = new \SplObjectStorage();

        $exclusions = ['script','style','code','pre','noscript'];
        $translatableTags = ['title','p','h1','h2','h3','h4','h5','h6','li','td','th','caption','figcaption','span','label','a','button','img','input','textarea','select'];
        $attrNames = ['title','alt','placeholder','aria-label','value'];

        $xpath = new \DOMXPath($doc);
        // Exclude nodes by tag (but collect descendant text nodes for each translatable tag)
        foreach ($translatableTags as $tag) {
            $nodes = $xpath->query('//' . $tag);
            if (!$nodes) continue;
            foreach ($nodes as $node) {
                if (self::isExcluded($node, $exclusions)) {
                    continue;
                }
                // Collect descendant text nodes (preserve inline markup and cover <p>, <strong>, etc.)
                $textNodes = self::collectTextNodes($node, $exclusions, $visitedTextNodes);
                if (!empty($textNodes)) {
                    foreach ($textNodes as $tn) {
                        $orig = (string) $tn->nodeValue;
                        if (trim($orig) === '') { continue; }
						$id = 't' . (++$counter);
						// Preserve original leading/trailing whitespace to avoid losing spaces around inline elements
						$isMenu = self::isMenuContext($tn);
						$segments[] = ['id' => $id, 'text' => $orig, 'type' => ($isMenu ? 'menu' : 'node')];
                        $nodeIndex[$id] = $tn; // map to DOMText for precise merge
                    }
                }
                // Attributes
                if ($node instanceof \DOMElement) {
                    // Skip elements with data-ai-trans-skip attribute (matches JavaScript behavior)
                    if ($node->hasAttribute('data-ai-trans-skip')) {
                        continue;
                    }
                    // Include input[type=submit|button|reset] value
                    if (strtolower($node->tagName) === 'input') {
                        $type = strtolower($node->getAttribute('type'));
                        if (in_array($type, ['submit','button','reset'], true)) {
                            $val = trim($node->getAttribute('value'));
                            if ($val !== '') {
                                // Normalize: replace multiple spaces with single space (matches JavaScript behavior)
                                $normalized = preg_replace('/\s+/u', ' ', $val);
                                $id = 'a' . (++$counter);
                                $segments[] = ['id' => $id, 'text' => $normalized, 'type' => 'attr', 'attr' => 'value'];
                                $nodeIndex[$id] = $node;
                            }
                        }
                    }
                    // Include aria-labels on anchors and buttons explicitly
                    if (in_array(strtolower($node->tagName), ['a','button'], true)) {
                        foreach (['aria-label','title'] as $extra) {
                            if ($node->hasAttribute($extra)) {
                                $val = trim($node->getAttribute($extra));
                                if ($val !== '') {
                                    // Normalize: replace multiple spaces with single space (matches JavaScript behavior)
                                    $normalized = preg_replace('/\s+/u', ' ', $val);
                                    $id = 'a' . (++$counter);
                                    $segments[] = ['id' => $id, 'text' => $normalized, 'type' => 'attr', 'attr' => $extra];
                                    $nodeIndex[$id] = $node;
                                }
                            }
                        }
                    }
                    foreach ($attrNames as $an) {
                        if ($node->hasAttribute($an)) {
                            $val = trim($node->getAttribute($an));
                            if ($val !== '') {
                                // Normalize: replace multiple spaces with single space (matches JavaScript behavior)
                                $normalized = preg_replace('/\s+/u', ' ', $val);
                                $id = 'a' . (++$counter);
                                $segments[] = ['id' => $id, 'text' => $normalized, 'type' => 'attr', 'attr' => $an];
                                $nodeIndex[$id] = $node;
                            }
                        }
                    }
                }
            }
        }

        // Systematically collect UI attributes using the EXACT same logic as JavaScript
        // JavaScript: querySelectorAll("input,textarea,select,button,[title],[aria-label],.initial-greeting,.chatbot-bot-text")
        // This ensures we collect ALL UI elements that JavaScript also collects, preventing trial-and-error
        $uiElements = $xpath->query('//input | //textarea | //select | //button | //*[@title] | //*[@aria-label] | //*[contains(@class, "initial-greeting")] | //*[contains(@class, "chatbot-bot-text")]');
        if ($uiElements) {
            $processedElements = new \SplObjectStorage();
            foreach ($uiElements as $node) {
                if (!$node instanceof \DOMElement) {
                    continue;
                }
                if (self::isExcluded($node, $exclusions)) {
                    continue;
                }
                // Skip elements with data-ai-trans-skip attribute (matches JavaScript behavior)
                if ($node->hasAttribute('data-ai-trans-skip')) {
                    continue;
                }
                // Skip if already processed in translatableTags loop (to avoid duplicates)
                if ($processedElements->contains($node)) {
                    continue;
                }
                $processedElements->attach($node);
                
                // Normalize function (matches JavaScript: trim and replace multiple spaces)
                $normalize = function($text) {
                    return preg_replace('/\s+/u', ' ', trim($text));
                };
                
                // Collect placeholder (for input, textarea, select)
                $tagName = strtolower($node->tagName ?? '');
                if (in_array($tagName, ['input', 'textarea', 'select'], true)) {
                    if ($node->hasAttribute('placeholder')) {
                        $val = $normalize($node->getAttribute('placeholder'));
                        if ($val !== '' && mb_strlen($val) >= 2) {
                            // Check if already collected
                            $alreadyCollected = false;
                            foreach ($nodeIndex as $mapped) {
                                if ($mapped === $node) {
                                    foreach ($segments as $seg) {
                                        if (isset($seg['attr']) && $seg['attr'] === 'placeholder' && $nodeIndex[$seg['id']] === $node) {
                                            $alreadyCollected = true;
                                            break 2;
                                        }
                                    }
                                }
                            }
                            if (!$alreadyCollected) {
                                $id = 'a' . (++$counter);
                                $segments[] = ['id' => $id, 'text' => $val, 'type' => 'attr', 'attr' => 'placeholder'];
                                $nodeIndex[$id] = $node;
                            }
                        }
                    }
                }
                
                // Collect title
                if ($node->hasAttribute('title')) {
                    $val = $normalize($node->getAttribute('title'));
                    if ($val !== '' && mb_strlen($val) >= 2) {
                        $alreadyCollected = false;
                        foreach ($nodeIndex as $mapped) {
                            if ($mapped === $node) {
                                foreach ($segments as $seg) {
                                    if (isset($seg['attr']) && $seg['attr'] === 'title' && $nodeIndex[$seg['id']] === $node) {
                                        $alreadyCollected = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                        if (!$alreadyCollected) {
                            $id = 'a' . (++$counter);
                            $segments[] = ['id' => $id, 'text' => $val, 'type' => 'attr', 'attr' => 'title'];
                            $nodeIndex[$id] = $node;
                        }
                    }
                }
                
                // Collect aria-label
                if ($node->hasAttribute('aria-label')) {
                    $val = $normalize($node->getAttribute('aria-label'));
                    if ($val !== '' && mb_strlen($val) >= 2) {
                        $alreadyCollected = false;
                        foreach ($nodeIndex as $mapped) {
                            if ($mapped === $node) {
                                foreach ($segments as $seg) {
                                    if (isset($seg['attr']) && $seg['attr'] === 'aria-label' && $nodeIndex[$seg['id']] === $node) {
                                        $alreadyCollected = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                        if (!$alreadyCollected) {
                            $id = 'a' . (++$counter);
                            $segments[] = ['id' => $id, 'text' => $val, 'type' => 'attr', 'attr' => 'aria-label'];
                            $nodeIndex[$id] = $node;
                        }
                    }
                }
                
                // Collect value for input[type=submit|button|reset] (matches JavaScript behavior)
                if ($tagName === 'input') {
                    $type = strtolower($node->getAttribute('type') ?? '');
                    if (in_array($type, ['submit', 'button', 'reset'], true)) {
                        if ($node->hasAttribute('value')) {
                            $val = $normalize($node->getAttribute('value'));
                            if ($val !== '' && mb_strlen($val) >= 2) {
                                $alreadyCollected = false;
                                foreach ($nodeIndex as $mapped) {
                                    if ($mapped === $node) {
                                        foreach ($segments as $seg) {
                                            if (isset($seg['attr']) && $seg['attr'] === 'value' && $nodeIndex[$seg['id']] === $node) {
                                                $alreadyCollected = true;
                                                break 2;
                                            }
                                        }
                                    }
                                }
                                if (!$alreadyCollected) {
                                    $id = 'a' . (++$counter);
                                    $segments[] = ['id' => $id, 'text' => $val, 'type' => 'attr', 'attr' => 'value'];
                                    $nodeIndex[$id] = $node;
                                }
                            }
                        }
                    }
                }
                
                // Collect textContent for .initial-greeting and .chatbot-bot-text (matches JavaScript behavior)
                $classes = $node->getAttribute('class') ?? '';
                if (strpos($classes, 'initial-greeting') !== false || strpos($classes, 'chatbot-bot-text') !== false) {
                    $text = trim($node->textContent ?? '');
                    if ($text !== '' && mb_strlen($text) >= 2) {
                        $normalized = $normalize($text);
                        // Collect text nodes from this element
                        $textNodes = self::collectTextNodes($node, $exclusions, new \SplObjectStorage());
                        if (!empty($textNodes)) {
                            $tn = $textNodes[0];
                            // Check if this text node is already in index
                            $existingId = null;
                            foreach ($nodeIndex as $id => $mapped) {
                                if ($mapped === $tn) {
                                    $existingId = $id;
                                    break;
                                }
                            }
                            if ($existingId !== null) {
                                // Update existing segment with normalized text
                                foreach ($segments as &$seg) {
                                    if ($seg['id'] === $existingId) {
                                        $seg['text'] = $normalized;
                                        break;
                                    }
                                }
                                unset($seg);
                            } else {
                                // Add new segment
                                $id = 't' . (++$counter);
                                $segments[] = ['id' => $id, 'text' => $normalized, 'type' => 'node'];
                                $nodeIndex[$id] = $tn;
                            }
                        } else {
                            // No text nodes found, use element itself
                            $id = 't' . (++$counter);
                            $segments[] = ['id' => $id, 'text' => $normalized, 'type' => 'node'];
                            $nodeIndex[$id] = $node;
                        }
                    }
                }
            }
        }

        // Additionally: collect any remaining text nodes not under the above tags (global sweep)
        $allTextNodes = self::collectTextNodes($doc, $exclusions, $visitedTextNodes);
        if (!empty($allTextNodes)) {
            foreach ($allTextNodes as $tn) {
                $orig = (string) $tn->nodeValue;
                if (trim($orig) === '') continue;
                // Avoid duplicates: only include if not already in index
                $already = false;
                foreach ($nodeIndex as $mapped) { if ($mapped === $tn) { $already = true; break; } }
                if ($already) continue;
				$id = 't' . (++$counter);
				// Preserve whitespace
				$isMenu = self::isMenuContext($tn);
				$segments[] = ['id' => $id, 'text' => $orig, 'type' => ($isMenu ? 'menu' : 'node')];
                $nodeIndex[$id] = $tn;
            }
        }

        // OG title/description (but skip meta name="description" - handled by AI_SEO::inject())
        // AI_SEO handles meta description separately to ensure admin setting is used
        $metaDesc = $xpath->query('//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:title" or translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:description" ]');
        if ($metaDesc) {
            foreach ($metaDesc as $m) {
                if ($m instanceof \DOMElement) {
                    $content = trim((string) $m->getAttribute('content'));
                    if ($content !== '') {
                        $id = 'm' . (++$counter);
                        $segments[] = ['id' => $id, 'text' => $content, 'type' => 'attr', 'attr' => 'content'];
                        $nodeIndex[$id] = $m;
                    }
                }
            }
        }

        return [
            'doc' => $doc,
            'segments' => $segments,
            'nodeIndex' => $nodeIndex,
            'originalHTML' => $html,
            'placeholders' => $placeholders,
        ];
    }

    /**
     * Extract tags by type (script/style) and replace with placeholders.
     * More robust than regex as it handles attributes with special chars.
     *
     * @param string $html
     * @param string $tagName
     * @param array $placeholders
     * @param int $counter
     * @return string
     */
    private static function extractAndReplace($html, $tagName, &$placeholders, &$counter)
    {
        $result = '';
        $pos = 0;
        $tagLower = strtolower($tagName);
        $openTag = '<' . $tagName;
        $closeTag = '</' . $tagName . '>';
        $closeLowerTag = '</' . $tagLower . '>';
        
        while (($start = stripos($html, $openTag, $pos)) !== false) {
            // Add content before this tag
            $result .= substr($html, $pos, $start - $pos);
            
            // Find the end of opening tag (look for >)
            $tagEnd = strpos($html, '>', $start);
            if ($tagEnd === false) {
                // No closing > found, treat rest as normal content
                $result .= substr($html, $start);
                break;
            }
            
            // Find closing tag (case-insensitive)
            $closePos = stripos($html, $closeTag, $tagEnd);
            if ($closePos === false) {
                // No closing tag found, include what we have
                $result .= substr($html, $start);
                break;
            }
            
            // Extract full tag including content
            $fullTag = substr($html, $start, $closePos - $start + strlen($closeTag));
            
            // Create placeholder using data attribute to preserve through DOM parsing
            // HTML comments can be removed by DOMDocument, so use a script tag instead
            $placeholderId = strtoupper($tagName) . '_PLACEHOLDER_' . (++$counter);
            $placeholder = '<script type="text/plain" data-ai-placeholder="' . $placeholderId . '"></script>';
            $placeholders[$placeholderId] = $fullTag;
            $result .= $placeholder;
            
            // Move position forward
            $pos = $closePos + strlen($closeTag);
        }
        
        // Add remaining content
        if ($pos < strlen($html)) {
            $result .= substr($html, $pos);
        }
        
        return $result;
    }

    /**
     * Merge translated segments into the DOM and return final HTML.
     *
     * @param array $plan
     * @param array $translations map id => translated string
     * @return string
     */
    public static function merge(array $plan, array $translations)
    {
        $doc = $plan['doc'];
        $segments = $plan['segments'];
        $nodeIndex = $plan['nodeIndex'];

        foreach ($segments as $seg) {
            $id = $seg['id'];
            if (!isset($translations[$id])) {
                continue;
            }
            $translated = (string) $translations[$id];
            $node = $nodeIndex[$id] ?? null;
            if (!$node) continue;
            if (($seg['type'] ?? '') === 'attr') {
                $attr = $seg['attr'] ?? null;
                if ($attr && $node instanceof \DOMElement) {
                    $node->setAttribute($attr, $translated);
                }
            } else {
                // Replace at text-node granularity when available
                if ($node instanceof \DOMText) {
                    // Preserve leading/trailing spaces that often represent word boundaries
                    $orig = (string) $node->nodeValue;
                    $lead = '';
                    $trail = '';
                    // Capture whitespace only (spaces, tabs, non-breaking spaces) at both ends
                    if ($orig !== '') {
                        if (preg_match('/^([\x{00A0}\s]+)/u', $orig, $m1)) { $lead = (string) $m1[1]; }
                        if (preg_match('/([\x{00A0}\s]+)$/u', $orig, $m2)) { $trail = (string) $m2[1]; }
                    }
                    // Also ensure a space between adjacent inline anchors/text when original had none trimmed away
                    // Only add an extra single space if both ends are non-empty words and no whitespace exists between
                    $node->nodeValue = $lead . $translated . $trail;
                } else {
                    self::replaceNodeText($node, $translated);
                }
            }
        }

        // Cleanup: remove duplicated words around anchors introduced by segmented translation
        self::fixAnchorAdjacencyDuplicates($doc);

        // Return the full HTML - DOMDocument has fixed any structural issues
        $result = $doc->saveHTML();
        
        // Remove XML declaration added by loadHTML (causes quirks mode)
        $result = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $result);
        
        // Preserve DOCTYPE from original HTML to avoid quirks mode
        $origHTML = isset($plan['originalHTML']) ? $plan['originalHTML'] : '';
        if ($origHTML && preg_match('/^(<!DOCTYPE[^>]*>)/i', (string) $origHTML, $docMatch)) {
            // Ensure we don't duplicate if saveHTML already includes it
            if (stripos($result, '<!DOCTYPE') === false) {
                $result = $docMatch[1] . "\n" . $result;
            }
        }
        
        // Restore preserved script and style tags from placeholders
        if (isset($plan['placeholders']) && is_array($plan['placeholders'])) {
            foreach ($plan['placeholders'] as $placeholderId => $original_tag) {
                $result = str_replace('<script type="text/plain" data-ai-placeholder="' . $placeholderId . '"></script>', $original_tag, $result);
            }
        }
        
        return $result;
    }

    private static function isExcluded(\DOMNode $node, array $exclusions)
    {
        for ($n = $node; $n; $n = $n->parentNode) {
            if ($n instanceof \DOMElement) {
                $tag = strtolower($n->tagName);
                if (in_array($tag, $exclusions, true)) {
                    return true;
                }
                // Skip WordPress admin bar (front-end toolbar)
                $id = strtolower((string) $n->getAttribute('id'));
                if ($id === 'wpadminbar' || $id === 'wp-toolbar') {
                    return true;
                }
                $classAttr = ' ' . strtolower((string) $n->getAttribute('class')) . ' ';
                if (strpos($classAttr, ' wp-admin ') !== false || strpos($classAttr, ' no-translate ') !== false || strpos($classAttr, ' notranslate ') !== false) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function nodeText(\DOMNode $node)
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= $child->nodeValue;
            }
        }
        return $text;
    }

    /**
     * Collect all descendant DOMText nodes that are not inside excluded tags.
     * Uses a visited set to avoid duplicates when multiple tag selectors overlap.
     *
     * @param \DOMNode $root
     * @param array $exclusions
     * @param \SplObjectStorage $visited
     * @return \DOMText[]
     */
    private static function collectTextNodes(\DOMNode $root, array $exclusions, \SplObjectStorage $visited)
    {
        $result = [];
        $stack = [$root];
        while (!empty($stack)) {
            /** @var \DOMNode $n */
            $n = array_pop($stack);
            if ($n instanceof \DOMElement) {
                $tag = strtolower($n->tagName);
                if (in_array($tag, $exclusions, true)) {
                    continue;
                }
            }
            foreach (iterator_to_array($n->childNodes ?? []) as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    // Check if text node has any excluded ancestor
                    if (!$visited->contains($child) && !self::isExcluded($child, $exclusions)) {
                        $visited->attach($child);
                        $result[] = $child;
                    }
                } else {
                    $stack[] = $child;
                }
            }
        }
        return $result;
    }

    private static function replaceNodeText(\DOMNode $node, $text)
    {
        // Remove existing text nodes
        $toRemove = [];
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $toRemove[] = $child;
            }
        }
        foreach ($toRemove as $c) {
            $node->removeChild($c);
        }
        $node->insertBefore($node->ownerDocument->createTextNode($text), $node->firstChild);
    }

    private static function ensureUtf8($html)
    {
        if (!\mb_detect_encoding($html, 'UTF-8', true)) {
            $html = \mb_convert_encoding($html, 'UTF-8');
        }
        return $html;
    }

    /**
     * Remove duplicated adjacent anchor text such as "... Kontakt Kontakt ...".
     * Heuristic: if previous/next text node repeats the anchor's text, drop the duplicate.
     *
     * @param \DOMDocument $doc
     * @return void
     */
    private static function fixAnchorAdjacencyDuplicates(\DOMDocument $doc)
    {
        $xpath = new \DOMXPath($doc);
        $links = $xpath->query('//a');
        if (!$links) return;
        foreach ($links as $a) {
            if (!$a instanceof \DOMElement) continue;
            $anchorText = trim((string) $a->textContent);
            if ($anchorText === '') continue;

            // Previous sibling duplicate ("... Kontakt" + <a>Kontakt</a>)
            $prev = $a->previousSibling;
            if ($prev && $prev->nodeType === XML_TEXT_NODE) {
                $prevText = (string) $prev->nodeValue;
                $prevTrimRight = rtrim($prevText);
                if ($prevTrimRight !== '' && \mb_strlen($prevTrimRight) >= \mb_strlen($anchorText)) {
                    $tail = \mb_substr($prevTrimRight, -\mb_strlen($anchorText));
                    if ($tail === $anchorText) {
                        $newPrev = \mb_substr($prevTrimRight, 0, \mb_strlen($prevTrimRight) - \mb_strlen($anchorText));
                        $newPrev = rtrim($newPrev);
                        if ($newPrev !== '' && substr($newPrev, -1) !== ' ') {
                            $newPrev .= ' ';
                        }
                        $prev->nodeValue = $newPrev;
                    }
                }
            }

            // Next sibling duplicate (<a>Kontakt</a> + " Kontakt ...")
            $next = $a->nextSibling;
            if ($next && $next->nodeType === XML_TEXT_NODE) {
                $nextText = (string) $next->nodeValue;
                $nextTrimLeft = ltrim($nextText);
                if ($nextTrimLeft !== '' && \mb_strlen($nextTrimLeft) >= \mb_strlen($anchorText)) {
                    $head = \mb_substr($nextTrimLeft, 0, \mb_strlen($anchorText));
                    if ($head === $anchorText) {
                        $rest = \mb_substr($nextTrimLeft, \mb_strlen($anchorText));
                        $rest = ltrim($rest);
                        if ($rest !== '' && $rest[0] !== ' ') {
                            $rest = ' ' . $rest;
                        }
                        $next->nodeValue = $rest;
                    }
                }
            }
        }
    }

    /**
     * Detect if a text node is likely inside a site navigation/menu context.
     * Heuristics: inside <nav>, or ancestor role="navigation|menubar|menu", or class contains menu/nav/navbar/menu-item.
     *
     * @param \DOMText $textNode
     * @return bool
     */
    private static function isMenuContext(\DOMText $textNode)
    {
        for ($n = $textNode->parentNode; $n; $n = $n->parentNode) {
            if ($n instanceof \DOMElement) {
                $tag = strtolower($n->tagName);
                if ($tag === 'nav') {
                    return true;
                }
                $role = strtolower((string) $n->getAttribute('role'));
                if ($role === 'navigation' || $role === 'menubar' || $role === 'menu') {
                    return true;
                }
                $classAttr = ' ' . strtolower((string) $n->getAttribute('class')) . ' ';
                if ($classAttr !== '  ') {
                    if (strpos($classAttr, ' menu ') !== false
                        || strpos($classAttr, ' nav ') !== false
                        || strpos($classAttr, ' navbar ') !== false
                        || strpos($classAttr, ' menu-item ') !== false
                        || strpos($classAttr, ' nav-item ') !== false
                        || strpos($classAttr, ' navbar-nav ') !== false) {
                        return true;
                    }
                }
            }
            if ($n instanceof \DOMDocument) {
                break;
            }
        }
        return false;
    }
}


