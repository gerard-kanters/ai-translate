<?php
/*
Plugin Name: AI Translate
Plugin URI: https://netcare.nl/product/ai-translate-voor-wordpress/
Description: Translate your wordpress site with AI 🤖
Version: 1.30
Author: NetCare
Author URI: https://netcare.nl/
License: GPL2
*/

declare(strict_types=1);

namespace AITranslate;

if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('AI_TRANSLATE_VERSION', '1.30');
define('AI_TRANSLATE_FILE', __FILE__);
define('AI_TRANSLATE_DIR', plugin_dir_path(__FILE__));
define('AI_TRANSLATE_URL', plugin_dir_url(__FILE__));

// Include de core class
require_once AI_TRANSLATE_DIR . 'includes/class-ai-translate-core.php';

// Hooks voor activatie en deactivatie
register_activation_hook(__FILE__, __NAMESPACE__ . '\\plugin_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\plugin_deactivate');

// AJAX handlers (legacy)
add_action('wp_ajax_ai_translate_clear_logs', [AI_Translate_Core::get_instance(), 'clear_logs']);

// Zoekquery vertaling - moet vroeg geladen worden
add_filter('pre_get_posts', function($query) {
    $core = AI_Translate_Core::get_instance();
    
    // Alleen voor zoekopdrachten
    if (!$query->is_search() || empty($query->get('s'))) {
        return $query;
    }
    
    $settings = $core->get_settings();
    
    // Alleen vertalen als meertalige zoekfunctionaliteit is ingeschakeld
    if (!empty($settings['enable_multilingual_search'])) {
        $current_language = $core->get_current_language();
        $default_language = $settings['default_language'] ?? 'nl';
        
        // Skip als we al in de standaardtaal zijn
        if ($current_language === $default_language) {
            return $query;
        }
        
        // Haal de zoekterm op
        $search_terms = $query->get('s');
        
        // Vertaal de zoekterm naar de standaardtaal
        $translated_search_terms = $core->translate_search_terms($search_terms, $current_language, $default_language);
        
        // Vervang de zoekterm in de query
        if (!empty($translated_search_terms) && $translated_search_terms !== $search_terms) {
            $query->set('s', $translated_search_terms);
        }
    }
    
    return $query;
}, 10, 1);

add_action('plugins_loaded', function () { // Keep this hook for loading core
    // Plugin initialisatie
    add_action('init', __NAMESPACE__ . '\\init_plugin', 5); // Keep for basic init like scripts
    add_action('init', __NAMESPACE__ . '\\add_language_rewrite_rules', 10); // Add rewrite rules later in init
    
    // Force flush rewrite rules if needed (only once per request)
    if (!get_transient('ai_translate_rules_flushed')) {
        add_action('init', __NAMESPACE__ . '\\force_flush_rewrite_rules', 15);
        
        // Gebruik de cache instelling voor de transient duur
        $core = AI_Translate_Core::get_instance();
        $settings = $core->get_settings();
        $cache_hours = $settings['cache_expiration'] ?? (14 * 24); // Default 14 dagen in uren
        $cache_seconds = $cache_hours * 3600; // Converteer naar seconden
        
        set_transient('ai_translate_rules_flushed', true, $cache_seconds);
    }

    if (is_admin()) {
        require_once AI_TRANSLATE_DIR . 'includes/admin-page.php';
        return;
    }

    $core = AI_Translate_Core::get_instance();
    add_action('wp_head', [$core, 'add_simple_meta_description'], 99);
    add_action('wp_footer', [$core, 'hook_display_language_switcher']);

    add_filter('wp_nav_menu_objects', [$core, 'translate_menu_items'], 999, 2);

    // Extra filter voor menu vertaling via wp_nav_menu
    add_filter('wp_nav_menu', function($nav_menu, $args) use ($core) {
        if (!$core->needs_translation() || is_admin()) {
            return $nav_menu;
        }
        
        // Parse de HTML en vertaal de menu items
        $dom = new \DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($nav_menu, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            $text = $link->textContent;
            if (!empty(trim($text))) {
                $translated_text = $core->translate_template_part($text, 'menu_item');
                $link->textContent = $core->clean_html_string($translated_text);
            }
        }
        
        return $dom->saveHTML();
    }, 999, 2);

    add_filter('option_blogname', function ($value) use ($core) {
        $translated_value = $core->translate_template_part($value, 'site_title');
        // Marker direct strippen
        $cleaned = \AITranslate\AI_Translate_Core::remove_translation_marker($translated_value);
        // Verwijder alle HTML (zoals <p>) uit de site_title
        return $core->clean_html_string($cleaned);
    }, 100);
    
    add_filter('option_blogdescription', function ($value) use ($core) {
        $translated_value = $core->translate_template_part($value, 'tagline');
        // Marker direct strippen
        return \AITranslate\AI_Translate_Core::remove_translation_marker($translated_value);
    }, 100);

    // Voeg de home_url filter pas toe via de wp actie zodat de globale query beschikbaar is
    add_action('wp', function () {
        $core = AI_Translate_Core::get_instance();
        $settings = $core->get_settings();
        
        // Voeg meertalige zoekfunctionaliteit toe
        if (!empty($settings['enable_multilingual_search'])) {
            // Filter voor zoekresultaten vertaling
            add_filter('the_title', [$core, 'translate_search_result_title'], 10, 1);
            add_filter('the_excerpt', [$core, 'translate_search_result_excerpt'], 10, 1);
        }
        
        add_filter('home_url', function ($url, $path, $orig_scheme, $blog_id) {
            if (is_admin()) {
                return $url;
            }
            $core = AI_Translate_Core::get_instance();
            $settings = $core->get_settings();
            $default_language = $settings['default_language'] ?? 'nl';

            // Get current language
            global $wp_query;
            if (isset($wp_query) && is_object($wp_query)) {
                $current_language = $core->get_current_language();
            } else {
                $current_language = isset($_COOKIE['ai_translate_lang']) ? sanitize_text_field(wp_unslash($_COOKIE['ai_translate_lang'])) : $default_language;
            }

            // 1. Widget title filter
            add_filter('widget_title', function ($title) use ($core) {
                // Standaard checks: niet vertalen in admin of als titel leeg is.
                if (is_admin() && !wp_doing_ajax()) {
                    return $title;
                }
                if (empty(trim($title))) {
                    return $title;
                }

                // Roep direct de individuele vertaalfunctie aan. Deze functie bevat caching (memory/transient/disk) en roept translate_template_part aan.
                // De marker wordt binnen translate_widget_title of de onderliggende functies verwijderd indien nodig.
                return $core->translate_widget_title($title);
            }, 10); // Prioriteit 10 is standaard

                   

            
            // 2. Widget text filter (voor oudere text widgets)
            add_filter('widget_text', function ($text) use ($core, $current_language) {
                // Skip in admin
                if (is_admin() && !wp_doing_ajax()) {
                    return $text;
                }

                // Skip empty content
                if (empty(trim($text))) {
                    return $text;
                }

                // Voorkom recursie
                static $processing_widget_text = false;
                if ($processing_widget_text) {
                    return $text;
                }
                $processing_widget_text = true;

                // First translate all URLs in the text
                $text_with_translated_urls = preg_replace_callback(
                    '/<a([^>]*)href=["\']([^"\']*)["\']([^>]*)>/i',
                    function($matches) use ($core, $current_language) {
                        $attributes_before = $matches[1];
                        $url = $matches[2];
                        $attributes_after = $matches[3];
                        
                        // Use wp_parse_url for more robust internal URL detection
                        $parsed_url = wp_parse_url($url);
                        
                        // Check if it's a relative URL or internal absolute URL
                        if (!isset($parsed_url['scheme']) ||
                            (isset($parsed_url['host']) && $parsed_url['host'] === wp_parse_url(home_url(), PHP_URL_HOST))
                        ) {
                            $url = $core->translate_url($url, $current_language);
                        }
                        
                        return '<a' . $attributes_before . 'href="' . $url . '"' . $attributes_after . '>';
                    },
                    $text
                );

                // Then handle content translation
                if (strpos($text_with_translated_urls, '<!--aitranslate:translated-->') !== false) {
                    // Strip all instances of the marker to prevent accumulation
                    $clean_text = str_replace('<!--aitranslate:translated-->', '', $text_with_translated_urls);
                    // Translate the cleaned text as a whole
                    $result = $core->translate_template_part($clean_text, 'widget_text');
                } else {
                    // Normal case - translate everything
                    $result = $core->translate_template_part($text_with_translated_urls, 'widget_text');
                }

                $processing_widget_text = false;
                return $result;
            }, 10);

            // 3. Widget text content filter (voor nieuwere text widgets met rijke editor)
            add_filter('widget_text_content', function ($content) use ($core, $current_language) {
                // Skip in admin
                if (is_admin() && !wp_doing_ajax()) {
                    return $content;
                }

                // Skip empty content
                if (empty(trim($content))) {
                    return $content;
                }

                // Voorkom recursie
                static $processing_widget_content = false;
                if ($processing_widget_content) {
                    return $content;
                }
                $processing_widget_content = true;

                // First translate all URLs in the content
                $content_with_translated_urls = preg_replace_callback(
                    '/<a([^>]*)href=["\']([^"\']*)["\']([^>]*)>/i',
                    function($matches) use ($core, $current_language) {
                        $attributes_before = $matches[1];
                        $url = $matches[2];
                        $attributes_after = $matches[3];
                        
                        // Use wp_parse_url for more robust internal URL detection
                        $parsed_url = wp_parse_url($url);
                        
                        // Check if it's a relative URL or internal absolute URL
                        if (!isset($parsed_url['scheme']) ||
                            (isset($parsed_url['host']) && $parsed_url['host'] === wp_parse_url(home_url(), PHP_URL_HOST))
                        ) {
                            $url = $core->translate_url($url, $current_language);
                        }
                        
                        return '<a' . $attributes_before . 'href="' . $url . '"' . $attributes_after . '>';
                    },
                    $content
                );

                // Then translate the content
                $result = $core->translate_template_part($content_with_translated_urls, 'widget_text');

                $processing_widget_content = false;
                return $result;
            }, 10);

            // Use path-based language prefix instead of query parameter
            if ($current_language !== $default_language) {
                // Parse URL to get components
                $parsed_url = wp_parse_url($url);
                $current_path = $parsed_url['path'] ?? '';

                // Remove existing language prefix if present
                $clean_path = preg_replace('#^/([a-z]{2})/#', '/', $current_path);

                // Add language prefix
                $new_path = '/' . $current_language . $clean_path;

                // Reconstruct URL
                $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
                $host = $parsed_url['host'] ?? '';
                $port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
                $query = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
                $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';

                $url = $scheme . $host . $port . $new_path . $query . $fragment;
            }

            return $url;
        }, 10, 4);
    });

    // Zoekformulier vertaling - altijd laden
    add_filter('get_search_form', function($form) use ($core) {
        $settings = $core->get_settings();
        
        // Alleen vertalen als meertalige zoekfunctionaliteit is ingeschakeld
        if (!empty($settings['enable_multilingual_search'])) {
            $form = $core->translate_search_form($form);
            $form = $core->translate_search_placeholders($form);
        }
        
        return $form;
    }, 10, 1);

    // Zoekresultaten teksten vertaling - altijd laden
    add_filter('get_search_query', function($query) use ($core) {
        $settings = $core->get_settings();
        
        // Alleen vertalen als meertalige zoekfunctionaliteit is ingeschakeld
        if (!empty($settings['enable_multilingual_search'])) {
            $query = $core->translate_search_query($query);
        }
        
        return $query;
    }, 10, 1);

    // Zoekpagina titel vertaling - altijd laden
    add_filter('the_title', function($title, $post_id = null) use ($core) {
        $settings = $core->get_settings();
        
        // Alleen vertalen als meertalige zoekfunctionaliteit is ingeschakeld
        if (!empty($settings['enable_multilingual_search'])) {
            $title = $core->translate_search_page_title($title);
        }
        
        return $title;
    }, 10, 2);

    // Zoekresultaten content vertaling - altijd laden
    add_filter('the_content', function($content) use ($core) {
        $settings = $core->get_settings();
        
        // Alleen vertalen als meertalige zoekfunctionaliteit is ingeschakeld
        if (!empty($settings['enable_multilingual_search'])) {
            $content = $core->translate_search_content($content);
        }
        
        return $content;
    }, 10, 1);

    // Comment form translation filter - comprehensive approach
    add_filter('comment_form_defaults', function ($defaults) use ($core) {
        // Skip in admin
        if (is_admin() && !wp_doing_ajax()) {
            return $defaults;
        }
        
        // Skip if translation not needed
        if (!$core->needs_translation()) {
            return $defaults;
        }
        
        // Prevent recursion
        static $processing_comment_form = false;
        if ($processing_comment_form) {
            return $defaults;
        }
        $processing_comment_form = true;
        
        // Translate standard text fields
        $text_fields = [
            'title_reply', 'title_reply_to', 'cancel_reply_link', 'label_submit',
            'comment_notes_before', 'comment_notes_after'
        ];
        
        foreach ($text_fields as $field) {
            if (!empty($defaults[$field])) {
                $translated = $core->translate_template_part($defaults[$field], 'comment_form');
                $defaults[$field] = $core->clean_html_string($translated);
            }
        }
        
        // Translate individual input fields (Name, Email, Website)
        if (!empty($defaults['fields']) && is_array($defaults['fields'])) {
            foreach ($defaults['fields'] as $key => $field_html) {
                // Translate label text
                $defaults['fields'][$key] = preg_replace_callback(
                    '/(<label[^>]*>)(.*?)(<\/label>)/i',
                    function ($matches) use ($core) {
                        $label_text = $matches[2];
                        $translated_label = $core->translate_template_part($label_text, 'comment_form');
                        $translated_label = \AITranslate\AI_Translate_Core::remove_translation_marker($translated_label);
                        return $matches[1] . $translated_label . $matches[3];
                    },
                    $field_html
                );
                
                // Translate placeholder attributes
                $defaults['fields'][$key] = preg_replace_callback(
                    '/placeholder=[\'"]([^\'"]+)[\'"]/i',
                    function ($matches) use ($core) {
                        $placeholder = $matches[1];
                        $translated_placeholder = $core->translate_template_part($placeholder, 'comment_form');
                        $translated_placeholder = \AITranslate\AI_Translate_Core::remove_translation_marker($translated_placeholder);
                        return 'placeholder="' . esc_attr($translated_placeholder) . '"';
                    },
                    $defaults['fields'][$key]
                );
            }
        }
        
        // Translate the comment field (textarea)
        if (!empty($defaults['comment_field'])) {
            // Translate label text
            $defaults['comment_field'] = preg_replace_callback(
                '/(<label[^>]*>)(.*?)(<\/label>)/i',
                function ($matches) use ($core) {
                    $label_text = $matches[2];
                    $translated_label = $core->translate_template_part($label_text, 'comment_form');
                    $translated_label = $core->clean_html_string($translated_label);
                    return $matches[1] . $translated_label . $matches[3];
                },
                $defaults['comment_field']
            );
            
            // Translate placeholder attributes
            $defaults['comment_field'] = preg_replace_callback(
                '/placeholder=[\'"]([^\'"]+)[\'"]/i',
                function ($matches) use ($core) {
                    $placeholder = $matches[1];
                    $translated_placeholder = $core->translate_template_part($placeholder, 'comment_form');
                    $translated_placeholder = $core->clean_html_string($translated_placeholder);
                    return 'placeholder="' . esc_attr($translated_placeholder) . '"';
                },
                $defaults['comment_field']
            );
        }
        
        $processing_comment_form = false;
        return $defaults;
    }, 20);

    add_filter('the_title', function ($title, $id = null) use ($core) {
        if (is_admin() && !wp_doing_ajax()) {
            return $title;
        }
        if (empty(trim($title))) {
            return $title;
        }
        // Vertaal de titel
        $translated = $core->translate_template_part($title, 'post_title');
        // Schoon de output op zodat er nooit HTML in de paginatitel komt
        return AI_Translate_Core::get_instance()->clean_html_string($translated);
    }, 10, 2);

    add_filter('the_content', function ($content) use ($core) {
        // Controleer of de marker al aanwezig is
        if (strpos($content, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
            return $content;
        }
    
        // Standaard checks: niet vertalen als niet nodig, in admin, of lege content.
        if (!$core->needs_translation() || is_admin() || empty($content)) {
            return $content;
        }
    
        // Recursie guard
        static $processing_content = false;
        if ($processing_content) {
            return $content;
        }
        $processing_content = true;
    
        // First exclude content within <div class="notranslate"> from translation
        $content = preg_replace_callback(
            '/<div[^>]*class=["\'][^"\']*notranslate[^"\']*["\'][^>]*>(.*?)<\/div>/is',
            function ($matches) {
                // Return the original content within the notranslate div
                return $matches[0];
            },
            $content
        );

        // Finally translate the content and add marker
        $translated = $core->translate_template_part($content, 'post_content');
        if (strpos($translated, AI_Translate_Core::TRANSLATION_MARKER) === false) {
            $translated .= AI_Translate_Core::TRANSLATION_MARKER;
        }
    
        // Now translate links within the translated content
        $current_language = $core->get_current_language();
        $default_language = $core->get_settings()['default_language'];
        // Now find and translate links within the translated content
        $current_language = $core->get_current_language();
        $default_language = $core->get_settings()['default_language'];
        if ($current_language !== $default_language) {
            // Find all anchor tags and their href attributes
            preg_match_all('/<a\s+[^>]*href=["\']([^"\']*)["\'][^>]*>/i', $translated, $matches, PREG_SET_ORDER);

            if (!empty($matches)) {
                foreach ($matches as $match) {
                    $original_tag = $match[0]; // Full original <a> tag
                    $original_href = $match[1]; // Original href value

                    // Use wp_parse_url for more robust internal URL detection
                    $parsed_url = wp_parse_url($original_href);

                    // Check if it's a relative URL (doesn't start with http/https) or an internal absolute URL
                    if (
                        (!preg_match('/^https?:\/\//i', $original_href)) || // Check if it doesn't start with http or https
                        (isset($parsed_url['host']) && $parsed_url['host'] === wp_parse_url(home_url(), PHP_URL_HOST))
                    ) {
                        // It's likely an internal or relative URL, translate it
                        $translated_url = $core->translate_url($original_href, $current_language);

                        // Replace the original href with the translated href in the translated content
                        // Use a precise replacement to avoid unintended changes
                        $translated = str_replace(
                            'href="' . $original_href . '"',
                            'href="' . $translated_url . '"',
                            $translated
                        );
                         $translated = str_replace(
                            "href='" . $original_href . "'",
                            "href='" . $translated_url . "'",
                            $translated
                        );
                    }
                }
            }
        }

        $processing_content = false;
        return $translated;
    }, 1);

    // Add filter for author name
    add_filter('the_author', function ($author_name) use ($core) {
        if (!$core->needs_translation() || is_admin() || empty($author_name)) {
            return $author_name;
        }
        return $core->translate_template_part($author_name, 'author_name');
    }, 10);

    // Add filter for author description
    add_filter('get_the_author_description', function ($author_description) use ($core) {
        if (!$core->needs_translation() || is_admin() || empty($author_description)) {
            return $author_description;
        }
        return $core->translate_template_part($author_description, 'author_description');
    }, 10);
    
    // --- Plugin Content Translation ---
    // Veilige manier om plugin-gegenereerde content te vertalen
    add_filter('do_shortcode_tag', function ($output, $tag, $attr) use ($core) {
        // Skip in admin
        if (is_admin() && !wp_doing_ajax()) {
            return $output;
        }
        
        // Skip if translation not needed
        if (!$core->needs_translation()) {
            return $output;
        }
        
        // Skip empty output
        if (!is_string($output)) {
            return $output;
        }
        
        if (empty(trim($output))) {
            return $output;
        }
        
        // Skip if already translated (contains marker)
        if (strpos($output, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
            return $output;
        }
        
        // Prevent recursion
        static $processing_shortcode = false;
        if ($processing_shortcode) {
            return $output;
        }
        $processing_shortcode = true;
        
        // Skip specific shortcodes that should not be translated
        $excluded_shortcodes = [
            'contact-form-7', 'wpcf7', 'fluentform', // Already handled separately
            'gallery', 'audio', 'video', 'playlist', // Media shortcodes
            'embed', 'wp_embed', // Embed shortcodes
            'code', 'pre', // Code blocks
            'script', 'style', // Script/style tags
        ];
        
        if (in_array($tag, $excluded_shortcodes, true)) {
            $processing_shortcode = false;
            return $output;
        }
        
        // Generate cache key for this shortcode output
        $form_id = isset($attr['id']) ? $attr['id'] : 'unknown';
        $cache_key = 'shortcode_' . $tag . '_' . $form_id . '_' . $core->get_current_language() . '_' . md5($output);
        $cached = $core->get_cached_content($cache_key);
        
        if ($cached !== false) {
            $processing_shortcode = false;
            return $cached;
        }
        
        // Translate the output
        $translated_output = $core->translate_template_part($output, 'plugin_content');
        
        // Cache the result
        $core->save_to_cache($cache_key, $translated_output);
        
        $processing_shortcode = false;
        return $translated_output;
    }, 10, 3);
    
    // Filter voor plugin output die via wp_head of wp_footer wordt toegevoegd
    add_action('wp_head', function () use ($core) {
        if (!$core->needs_translation() || is_admin()) {
            return;
        }
        
        // Filter voor plugin output in head
        add_filter('wp_head', function ($output) use ($core) {
            // Check if output is a string before using trim()
            if (!is_string($output)) {
                return $output;
            }
            
            if (empty(trim($output))) {
                return $output;
            }
            
            // Skip if already translated
            if (strpos($output, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
                return $output;
            }
            
            // Skip script and style tags
            if (preg_match('/<(script|style|link|meta)/i', $output)) {
                return $output;
            }
            
            return $core->translate_template_part($output, 'plugin_head');
        }, 999);
    }, 1);
    
    // Filter voor plugin output die via wp_footer wordt toegevoegd
    add_action('wp_footer', function () use ($core) {
        if (!$core->needs_translation() || is_admin()) {
            return;
        }
        
        // Filter voor plugin output in footer
        add_filter('wp_footer', function ($output) use ($core) {
            // Check if output is a string before using trim()
            if (!is_string($output)) {
                return $output;
            }
            
            if (empty(trim($output))) {
                return $output;
            }
            
            // Skip if already translated
            if (strpos($output, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
                return $output;
            }
            
            // Skip script and style tags
            if (preg_match('/<(script|style|link|meta)/i', $output)) {
                return $output;
            }
            
            return $core->translate_template_part($output, 'plugin_footer');
        }, 999);
    }, 1);
    
    // Filter voor plugin output die via template hooks wordt toegevoegd
    add_action('wp', function () use ($core) {
        if (!$core->needs_translation() || is_admin()) {
            return;
        }
        
        // Filter voor plugin output via template hooks
        $template_hooks = [
            'wp_body_open',
            'wp_footer',
            'wp_head',
            'get_header',
            'get_footer',
            'get_sidebar',
        ];
        
        foreach ($template_hooks as $hook) {
            add_filter($hook, function ($output) use ($core, $hook) {
                // Check if output is a string before using trim()
                if (!is_string($output)) {
                    return $output;
                }
                
                if (empty(trim($output))) {
                    return $output;
                }
                
                // Skip if already translated
                if (strpos($output, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
                    return $output;
                }
                
                // Skip script and style tags
                if (preg_match('/<(script|style|link|meta)/i', $output)) {
                    return $output;
                }
                
                return $core->translate_template_part($output, 'plugin_' . $hook);
            }, 999);
        }
    });
    
    // Haal de taalcode uit de cookie
    add_action('init', function () {
        $language_code = isset($_COOKIE['ai_translate_lang']) ? sanitize_text_field(wp_unslash($_COOKIE['ai_translate_lang'])) : '';
        $settings = AI_Translate_Core::get_instance()->get_settings();
        $default_language = $settings['default_language'];
        // Als de cookie geen taalcode bevat, of als de taalcode ongeldig is, stel dan de standaardtaal in
        if (empty($language_code) || !in_array($language_code, $settings['enabled_languages'])) {
            $language_code = $default_language;

            // Log de huidige taal
        }

        // Process permalinks to use path-based language URLs
        add_filter('post_link', function (string $permalink, $post, $leavename): string {
            $core = AI_Translate_Core::get_instance();
            $current_language = $core->get_current_language();
            $default_language = $core->get_settings()['default_language'];

            if ($current_language !== $default_language) {
                // Use translate_url to get path-based URL with translated slug
                $permalink = $core->translate_url($permalink, $current_language, $post->ID);
            }

            return $permalink;
        }, 10, 3);

        // Also handle page links
        add_filter('page_link', function (string $permalink, $post_id, $sample): string {
            $core = AI_Translate_Core::get_instance();
            $current_language = $core->get_current_language();
            $default_language = $core->get_settings()['default_language'];

            if ($current_language !== $default_language) {
                // Use translate_url to get path-based URL with translated slug
                $permalink = $core->translate_url($permalink, $current_language, $post_id);
            }

            return $permalink;
        }, 10, 3);
    }); // End of init action

    // --- Algemene Plugin Output Translation via Output Buffering ---
    // Deze methode vangt alle output op die door plugins wordt gegenereerd
    add_action('template_redirect', function () use ($core) {
        // Skip in admin
        if (is_admin()) {
            return;
        }
        
        // Skip if translation not needed
        if (!$core->needs_translation()) {
            return;
        }
        
        // Start output buffering to catch all plugin output
        ob_start(function ($buffer) use ($core) {
            // Check if buffer is a string before using trim()
            if (!is_string($buffer)) {
                return $buffer;
            }
            
            if (empty(trim($buffer))) {
                return $buffer;
            }
            
            // Skip if already translated
            if (strpos($buffer, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
                return $buffer;
            }
            
            // Skip if it's just HTML structure without translatable content
            if (preg_match('/^<!DOCTYPE|<html|<head|<body|<script|<style|<link|<meta/i', $buffer)) {
                return $buffer;
            }
            
            // Generate cache key for this buffer
            $cache_key = 'output_buffer_' . $core->get_current_language() . '_' . md5($buffer);
            $cached = $core->get_cached_content($cache_key);
            
            if ($cached !== false) {
                return $cached;
            }
            
            // Translate the buffer
            $translated_buffer = $core->translate_template_part($buffer, 'output_buffer');
            
            // Cache the result
            $core->save_to_cache($cache_key, $translated_buffer);
            
            return $translated_buffer;
        });
    }, 1);
    
    // Clean up output buffer at the end
    add_action('shutdown', function () {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    }, 999);

    // Hook om slug vertalingen te resetten wanneer de originele slug verandert
    add_action('wp_insert_post', function ($post_id, $post, $update) {
        // Alleen uitvoeren bij updates (niet bij nieuwe posts)
        if (!$update) {
            return;
        }
        
        $core = AI_Translate_Core::get_instance();
        
        // Haal de oude post data op
        $old_post = get_post($post_id);
        if (!$old_post) {
            return;
        }
        
        // Check of de slug is veranderd
        $old_slug = get_post_meta($post_id, '_ai_translate_original_slug', true);
        if ($old_slug && $old_slug !== $post->post_name) {
            // Slug is veranderd, verwijder alle vertaalde slugs voor deze post
            $core->clear_slug_cache_for_post($post_id);
            
            // Update de opgeslagen originele slug
            update_post_meta($post_id, '_ai_translate_original_slug', $post->post_name);
        } elseif (!$old_slug) {
            // Eerste keer, sla de originele slug op
            update_post_meta($post_id, '_ai_translate_original_slug', $post->post_name);
        }
    }, 10, 3);

    // --- Specifieke Plugin Filters ---
    // WooCommerce filters
    if (class_exists('WooCommerce')) {
        add_filter('woocommerce_product_title', function ($title) use ($core) {
            if (!$core->needs_translation() || is_admin() || empty($title)) {
                return $title;
            }
            return $core->translate_template_part($title, 'woocommerce_title');
        }, 10);
        
        add_filter('woocommerce_product_description', function ($description) use ($core) {
            if (!$core->needs_translation() || is_admin() || empty($description)) {
                return $description;
            }
            return $core->translate_template_part($description, 'woocommerce_description');
        }, 10);
        
        add_filter('woocommerce_product_short_description', function ($short_description) use ($core) {
            if (!$core->needs_translation() || is_admin() || empty($short_description)) {
                return $short_description;
            }
            return $core->translate_template_part($short_description, 'woocommerce_short_description');
        }, 10);
        
        add_filter('woocommerce_cart_item_name', function ($name) use ($core) {
            if (!$core->needs_translation() || is_admin() || empty($name)) {
                return $name;
            }
            return $core->translate_template_part($name, 'woocommerce_cart_item');
        }, 10);
    }
    
    // Contact Form 7 filters (als Fluent Forms niet wordt gebruikt)
    if (class_exists('WPCF7')) {
        add_filter('wpcf7_form_elements', function ($content) use ($core) {
            if (!$core->needs_translation() || is_admin() || empty($content)) {
                return $content;
            }
            
            // Skip if already translated
            if (strpos($content, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
                return $content;
            }
            
            return $core->translate_template_part($content, 'contact_form_7');
        }, 10);
    }
    
    // Elementor filters
    if (class_exists('Elementor\Plugin')) {
        add_filter('elementor/frontend/the_content', function ($content) use ($core) {
            if (!$core->needs_translation() || is_admin() || empty($content)) {
                return $content;
            }
            
            // Skip if already translated
            if (strpos($content, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
                return $content;
            }
            
            return $core->translate_template_part($content, 'elementor_content');
        }, 10);
    }
    
    // Divi filters
    if (function_exists('et_setup_theme')) {
        add_filter('et_pb_all_fields_unprocessed', function ($fields) use ($core) {
            if (!$core->needs_translation() || is_admin() || empty($fields)) {
                return $fields;
            }
            
            // Translate text fields in Divi modules
            $text_fields = ['title', 'content', 'description', 'subtitle'];
            foreach ($text_fields as $field) {
                if (isset($fields[$field]) && !empty($fields[$field])) {
                    $fields[$field] = $core->translate_template_part($fields[$field], 'divi_' . $field);
                }
            }
            
            return $fields;
        }, 10);
    }
    
    // Beaver Builder filters
    if (class_exists('FLBuilder')) {
        add_filter('fl_builder_render_module_content', function ($content, $module) use ($core) {
            if (!$core->needs_translation() || is_admin() || empty($content)) {
                return $content;
            }
            
            // Skip if already translated
            if (strpos($content, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
                return $content;
            }
            
            return $core->translate_template_part($content, 'beaver_builder');
        }, 10, 2);
    }
    

}); // End of plugins_loaded action

// Start output buffering voor volledige HTML vertaling (page builder support)
// Nieuwe, veiligere hook:
add_filter('template_include', function ($template) {
    $core = \AITranslate\AI_Translate_Core::get_instance();
    return $template;
}, 0);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), __NAMESPACE__ . '\\ai_translate_settings_link');

// Add 5-star rating link to plugin row meta
add_filter('plugin_row_meta', __NAMESPACE__ . '\\ai_translate_plugin_row_meta', 10, 2);

function ai_translate_settings_link($links): array
{
    $settings_link = '<a href="admin.php?page=ai-translate">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}

function ai_translate_plugin_row_meta($links, $file): array
{
    if (plugin_basename(__FILE__) === $file) {
        $rating_link = '<a href="https://wordpress.org/support/plugin/ai-translate/reviews/" target="_blank" rel="noopener noreferrer" title="Rate AI Translate on WordPress.org" style="color: #ffb900">' .
            '<span class="dashicons dashicons-star-filled" style="font-size: 16px; width:16px; height: 16px"></span>' .
            '<span class="dashicons dashicons-star-filled" style="font-size: 16px; width:16px; height: 16px"></span>' .
            '<span class="dashicons dashicons-star-filled" style="font-size: 16px; width:16px; height: 16px"></span>' .
            '<span class="dashicons dashicons-star-filled" style="font-size: 16px; width:16px; height: 16px"></span>' .
            '<span class="dashicons dashicons-star-filled" style="font-size: 16px; width:16px; height: 16px"></span>' .
            '</a>';
        $links[] = $rating_link;
    }
    return $links;
}

function plugin_activate(): void
{
    $core = AI_Translate_Core::get_instance();
    $settings = get_option('ai_translate_settings');
    $default_settings = $core->get_default_settings();
    if ($settings === false) {
        update_option('ai_translate_settings', $default_settings);
    } else {
        // Voeg ontbrekende default settings toe zonder bestaande te overschrijven
        $merged = array_merge($default_settings, $settings);
        update_option('ai_translate_settings', $merged);
    }
    $upload_dir = wp_upload_dir();
    $plugin_upload_dir = $upload_dir['basedir'] . '/ai-translate';
    $cache_dir = $plugin_upload_dir . '/cache';
    if (!file_exists($cache_dir)) {
        wp_mkdir_p($cache_dir);
    }
    $log_dir = $plugin_upload_dir . '/logs';
    if (!file_exists($log_dir)) {
        wp_mkdir_p($log_dir);
    }
    $flags_dir = AI_TRANSLATE_DIR . 'assets/flags';
    if (!file_exists($flags_dir)) {
        wp_mkdir_p($flags_dir);
    }
    
    // Voeg rewrite rules toe en flush ze
    add_language_rewrite_rules();
    flush_rewrite_rules();

    // Create custom table for slug translations
    global $wpdb;
    $table_name = $wpdb->prefix . 'ai_translate_slugs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        original_slug varchar(200) NOT NULL,
        translated_slug varchar(200) NOT NULL,
        language_code varchar(10) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_slug_translation (post_id, language_code),
        KEY original_slug (original_slug),
        KEY translated_slug (translated_slug),
        KEY language_code (language_code)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Initialiseer bestaande posts met hun originele slug metadata
    initialize_existing_posts_slug_metadata();
    
    // Migreer bestaande vertaalde URLs van transients naar database voor backwards compatibility
    migrate_existing_translated_urls();
}

function plugin_deactivate(): void
{
    flush_rewrite_rules();
}

/**
 * Force flush rewrite rules to ensure language rules are active
 */
function force_flush_rewrite_rules(): void
{
    add_language_rewrite_rules();
    flush_rewrite_rules();
}

function init_plugin(): void
{
    // Basic init - enqueue scripts/styles
    $core = AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    wp_enqueue_script('jquery');
    // Enqueue switcher JS only if needed (moved from core class potentially)
    if (!is_admin()) {
        wp_enqueue_script('ai-translate-switcher-js', plugins_url('assets/language-switcher.js', AI_TRANSLATE_FILE), ['jquery'], AI_TRANSLATE_VERSION, true);
    }
    wp_enqueue_style('ai-translate-switcher', plugins_url('assets/language-switcher.css', AI_TRANSLATE_FILE), [], AI_TRANSLATE_VERSION);
}

function add_language_rewrite_rules(): void
{
    $core = AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    $selected_languages = $settings['enabled_languages'] ?? [];
    $detectable_languages = $settings['detectable_languages'] ?? [];
    $enabled_languages = array_unique(array_merge($selected_languages, $detectable_languages));
    $default_language = $settings['default_language'] ?? 'nl';

    // Create a regex part for enabled languages, excluding the default one
    $lang_codes = array_diff($enabled_languages, [$default_language]);
    if (empty($lang_codes)) {
        return; // No non-default languages enabled
    }
    $lang_regex = '(' . implode('|', array_map('preg_quote', $lang_codes, ['/'])) . ')'; // e.g., (en|fr|de)

    // BELANGRIJKE VOLGORDE: Specifieke regels eerst, algemene regels later
    
    // 1. Rule for homepage with language prefix (MOET EERST!)
    add_rewrite_rule('^' . $lang_regex . '/?$', 'index.php?lang=$matches[1]', 'top');
    
    // 2. Rules for custom post types
    add_rewrite_rule('^' . $lang_regex . '/(?!wp-admin|wp-login.php)(service)/([^/]+)/?$', 'index.php?lang=$matches[1]&post_type=service&name=$matches[3]', 'top');
    add_rewrite_rule('^' . $lang_regex . '/(?!wp-admin|wp-login.php)(product)/([^/]+)/?$', 'index.php?lang=$matches[1]&post_type=product&name=$matches[3]', 'top');
    
    // 3. Rule for pages with language prefix
    add_rewrite_rule('^' . $lang_regex . '/(?!wp-admin|wp-login.php)(.+?)/?$', 'index.php?lang=$matches[1]&pagename=$matches[2]', 'top');
    
    // 4. Rule for single posts with language prefix (LAATSTE!)
    add_rewrite_rule('^' . $lang_regex . '/(?!wp-admin|wp-login.php)([^/]+)/?$', 'index.php?lang=$matches[1]&name=$matches[2]', 'top');

    // Add 'lang' to query vars so get_query_var works
    add_filter('query_vars', __NAMESPACE__ . '\\add_language_query_var');
}

function add_language_query_var(array $vars): array
{
    $vars[] = 'lang';
    $vars[] = 'original_url';
    return $vars;
}

function fix_redirect_loops($redirect_url, string $requested_url): string|false
{
    if (!$redirect_url || strpos($requested_url, '/wp-admin') !== false) {
        return $redirect_url;
    }
    $core = AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    if (!empty($settings['enabled_languages'])) {
        $languages = is_array($settings['enabled_languages']) ? $settings['enabled_languages'] : explode(',', $settings['enabled_languages']);
        foreach ($languages as $lang) {
            if (strpos($requested_url, '/' . $lang . '/') !== false) {
                return false;
            }
        }
    }
    return $redirect_url;
}

function translate_posts(array $posts): array
{
    if (is_admin() || empty($posts)) {
        return $posts;
    }
    $core = AI_Translate_Core::get_instance();
    foreach ($posts as $post) {
        if ($post->post_type === 'post' || $post->post_type === 'page') {
            $post->post_title   = $core->translate_template_part($post->post_title, 'post_title');
            $post->post_content = $core->translate_template_part($post->post_content, 'post_content');
        }
    }
    return $posts;
}

function should_translate(array $settings): bool
{
    $target_language = get_target_language($settings);
    return $target_language !== $settings['default_language'];
}

function translate_post_field($value, string $field, \WP_Post $post): string
{
    if ($field === 'post_content') {
        $core = AI_Translate_Core::get_instance();
        return $core->translate_post_content($post);
    }
    return $value;
}

function translate_terms(?array $terms, int $post_id, string $taxonomy): ?array
{
    if (empty($terms) || is_wp_error($terms)) {
        return $terms;
    }
    $core = AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    if (!should_translate($settings)) {
        return $terms;
    }
    $target_language = get_target_language($settings);
    if ($target_language === $settings['default_language']) {
        return $terms;
    }
    foreach ($terms as $term) {
        if (isset($term->name)) {
            $term->name = translate_text($term->name, $settings['default_language'], $target_language, true);
        }
    }
    return $terms;
}

function get_target_language(array $settings): string
{
    $core = AI_Translate_Core::get_instance();
    return $core->get_current_language();
}

function translate_text(string $text, string $source_language, string $target_language, bool $is_title = false): string
{
    $core = AI_Translate_Core::get_instance();
    return $core->translate_text($text, $source_language, $target_language, $is_title);
}

function translate_menu_items(array $items): array
{
    if (empty($items)) {
        return $items;
    }
    $core = AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    if (!should_translate($settings)) {
        return $items;
    }
    foreach ($items as $item) {
        $item->title = translate_text($item->title, $settings['default_language'], get_target_language($settings), true);
    }
    return $items;
}

function register_rewrite_rules(): void
{
    $core = AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    $languages = $settings['enabled_languages'] ?? [];
    $default_lang = $settings['default_language'] ?? 'nl';

    // Add default language to the list for rewrite rules if not present
    $all_langs_for_rules = array_unique(array_merge([$default_lang], $languages));

    if (!empty($all_langs_for_rules)) {
        $lang_codes = implode('|', array_map('preg_quote', $all_langs_for_rules));
        // Rule for language prefix - target includes 'lang' for WP core routing compatibility
        add_rewrite_rule('^(' . $lang_codes . ')/?(.*)?', 'index.php?lang=$matches[1]&pagename=$matches[2]', 'top');
    }
}

function add_query_vars(array $vars): array
{
    $vars[] = 'lang'; // Keep for WP routing    
    return $vars;
}

function enqueue_switcher_assets(): void
{
    // ... existing code ...
    wp_enqueue_style('ai-translate-switcher', plugins_url('assets/css/language-switcher.css', AI_TRANSLATE_FILE), array(), filemtime(AI_TRANSLATE_DIR . 'assets/css/language-switcher.css'));
}

function set_language_cookie(): void
{
    // ... existing code ...
    $core = AI_Translate_Core::get_instance();
    $current_lang = $core->get_current_language();
    $cookie_name = 'ai_translate_lang';
    $cookie_value = $current_lang;
    $cookie_path = defined('COOKIEPATH') ? COOKIEPATH : '/';
    $cookie_domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

    $options = [
        'expires' => time() + (86400 * 30), // 30 days
        'path' => $cookie_path,
        'domain' => $cookie_domain,
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax'
    ];

    if (!isset($_COOKIE[$cookie_name]) || $_COOKIE[$cookie_name] !== $cookie_value) {
        setcookie($cookie_name, $cookie_value, $options);
        $_COOKIE[$cookie_name] = $cookie_value;
    }
}

function activate_plugin(): void
{
    // ... existing code ...
    if (!class_exists(__NAMESPACE__ . '\\AI_Translate_Core')) {
        require_once AI_TRANSLATE_DIR . 'includes/class-ai-translate-core.php';
    }
    register_rewrite_rules();
    flush_rewrite_rules();
}

function deactivate_plugin(): void
{
    // ... existing code ...
    flush_rewrite_rules();
}

function maybe_flush_rules_on_settings_update($old_value, $value): void
{
    // ... existing code ...
    $old_sitemap_setting = isset($old_value['generate_sitemap']) ? (bool) $old_value['generate_sitemap'] : false;
    $new_sitemap_setting = isset($value['generate_sitemap']) ? (bool) $value['generate_sitemap'] : false;

    if ($old_sitemap_setting !== $new_sitemap_setting) {
        // Schedule flush to run after rules have been re-registered based on new setting
        add_action('shutdown', function () {
            if (!class_exists(__NAMESPACE__ . '\\AI_Translate_Core')) {
                require_once AI_TRANSLATE_DIR . 'includes/class-ai-translate-core.php';
            }
            // Re-register rules based on the *new* setting before flushing
            register_rewrite_rules();
            flush_rewrite_rules();
        }, 99); // Run late in shutdown
    }
}

// --- Filters om de vertaalmarker te verwijderen uit de uiteindelijke output ---
// Deze filters draaien met hoge prioriteit (99) NA de meeste andere plugins.
$class_name_for_filters = '\AITranslate\AI_Translate_Core'; // Gebruik Fully Qualified Class Name

// Controleer eerst of de class en de benodigde methodes bestaan
if (
    class_exists($class_name_for_filters) &&
    method_exists($class_name_for_filters, 'remove_translation_marker')
) {
    $marker_removal_priority = 99; // Hoge prioriteit

    // --- Standaard WordPress Filters ---

    // Specifieke check voor bloginfo methode
    if (method_exists($class_name_for_filters, 'remove_marker_from_bloginfo')) {
        add_filter('get_bloginfo', [$class_name_for_filters, 'remove_marker_from_bloginfo'], $marker_removal_priority, 2);
    }

    // --- SEO Plugin Filters ---

    // Yoast SEO
    add_filter('wpseo_opengraph_desc', [$class_name_for_filters, 'remove_translation_marker'], $marker_removal_priority);
    add_filter('wpseo_metadesc', [$class_name_for_filters, 'remove_translation_marker'], $marker_removal_priority);

    // Rank Math
    add_filter('rank_math/frontend/description', [$class_name_for_filters, 'remove_translation_marker'], $marker_removal_priority);
    add_filter('rank_math/opengraph/description', [$class_name_for_filters, 'remove_translation_marker'], $marker_removal_priority);

    // SEOPress
    add_filter('seopress_titles_desc', [$class_name_for_filters, 'remove_translation_marker'], $marker_removal_priority);
    add_filter('seopress_social_og_desc', [$class_name_for_filters, 'remove_translation_marker'], $marker_removal_priority);

    // --- Andere Plugin Filters ---

    // Jetpack Open Graph Tags (specifieke check nodig)
    if (method_exists($class_name_for_filters, 'remove_marker_from_jetpack_og_tags')) {
        add_filter('jetpack_open_graph_tags', [$class_name_for_filters, 'remove_marker_from_jetpack_og_tags'], $marker_removal_priority);
    }
} else {
    // Log een fout als de class of methode niet gevonden wordt
}
// --- Einde marker verwijderingsfilters ---

// Zet de juiste lang- en dir-attribuut in de <html> tag op basis van de actieve taal
add_filter('language_attributes', function($output) {
    $core = \AITranslate\AI_Translate_Core::get_instance();
    $lang = esc_attr($core->get_current_language());
    $rtl_langs = ['ar', 'he', 'fa', 'ur'];
    $dir = in_array($lang, $rtl_langs, true) ? ' dir="rtl"' : '';
    return 'lang="' . $lang . '"' . $dir;
}, 99);

// Forceer juiste homepage/blog query bij alleen taalprefix in de URL, maar alleen als er geen andere query_vars zijn
add_action('parse_request', function ($wp) {
    // Voeg een extra controle toe voor admin-gerelateerde URL's
    $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    if (
        is_admin() ||
        (defined('DOING_AJAX') && DOING_AJAX) ||
        strpos($request_uri, '/wp-admin/') !== false ||
        strpos($request_uri, '/wp-login.php') !== false
    ) {
        return;
    }

    $core = \AITranslate\AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    $default_language = $settings['default_language'] ?? 'nl';
    $current_language = $core->get_current_language();
    $all_languages = array_keys($core->get_available_languages());

    $request_path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(sanitize_url(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH) : '/';
    if (!is_string($request_path)) {
        $request_path = '/';
    } else {
        $request_path = preg_replace('#/+#', '/', $request_path);
    }

    if (preg_match('#^/([a-z]{2,3}(?:-[a-z]{2,4})?)/?$#i', $request_path, $matches)) {
        $lang = strtolower($matches[1]);
        if (in_array($lang, $all_languages, true)) {
            if (empty($wp->query_vars) || (count($wp->query_vars) === 1 && isset($wp->query_vars['lang']))) {
                $front_id = get_option('page_on_front');
                $blog_id = get_option('page_for_posts');
                if ($blog_id && intval($blog_id) > 0 && $front_id && $front_id == $blog_id) {
                    $front_post = get_post($blog_id);
                    $wp->query_vars = [
                        'page_id' => $blog_id,
                        'pagename' => $front_post ? $front_post->post_name : '',
                        'post_type' => $front_post ? $front_post->post_type : 'page',
                    ];
                    $wp->is_page = true;
                    $wp->is_singular = true;
                } elseif ($front_id && intval($front_id) > 0) {
                    $front_post = get_post($front_id);
                    $wp->query_vars = [
                        'page_id' => $front_id,
                        'pagename' => $front_post ? $front_post->post_name : '',
                        'post_type' => $front_post ? $front_post->post_type : 'page',
                    ];
                    $wp->is_page = true;
                    $wp->is_singular = true;
                } elseif ($blog_id && intval($blog_id) > 0) {
                    $front_post = get_post($blog_id);
                    $wp->query_vars = [
                        'page_id' => $blog_id,
                        'pagename' => $front_post ? $front_post->post_name : '',
                        'post_type' => $front_post ? $front_post->post_type : 'page',
                    ];
                    $wp->is_page = true;
                    $wp->is_singular = true;
                } else {
                    $wp->query_vars = ['is_home' => true];
                }
                $wp->is_home = true;
                $wp->is_404 = false;
                return;
            }
        }
    }

    if ($current_language === $default_language) {
        return;
    }

    $clean_path = preg_replace('#^/' . preg_quote($current_language, '#') . '/#', '/', $request_path);
    
    if ($clean_path === '/' || empty(trim($clean_path, '/'))) {
        return;
    }

    $slug = basename(trim($clean_path, '/'));
    if (empty($slug)) {
        return;
    }

    $original_post_data = $core->reverse_translate_slug($slug, $current_language, $default_language);
    
    if ($original_post_data && $original_post_data['slug'] !== $slug) {
        $wp->query_vars = [];
        $original_slug = $original_post_data['slug'];
        $original_post_type = $original_post_data['post_type'];

        if ($original_post_type === 'page') {
            $wp->query_vars['pagename'] = $original_slug;
        } else {
            $wp->query_vars['name'] = $original_slug;
            $wp->query_vars['post_type'] = $original_post_type;
        }
        $wp->is_404 = false;
    }
});


// --- Canonical redirect fix voor homepage met taalprefix ---
add_filter('redirect_canonical', function($redirect_url, $requested_url) {
    $core = \AITranslate\AI_Translate_Core::get_instance();
    $current_language = $core->get_current_language();
    $default_language = $core->get_settings()['default_language'];

    // Als de huidige taal de standaardtaal is, of als we in de admin zijn,
    // laat WordPress de canonical redirect afhandelen.
    if ($current_language === $default_language || is_admin()) {
        return $redirect_url;
    }

    // Als de redirect_url leeg is, of als de requested_url al de taalprefix bevat,
    // en de redirect_url bevat deze niet, dan is er mogelijk een conflict.
    // We moeten ervoor zorgen dat de canonical URL de taalprefix behoudt.
    if ($redirect_url && strpos($requested_url, '/' . $current_language . '/') !== false) {
        $parsed_redirect_url = wp_parse_url($redirect_url);
        $parsed_requested_url = wp_parse_url($requested_url);

        // Als de redirect_url geen taalprefix heeft, voeg deze dan toe.
        if (
            isset($parsed_redirect_url['path']) &&
            strpos($parsed_redirect_url['path'], '/' . $current_language . '/') === false &&
            isset($parsed_requested_url['path']) &&
            strpos($parsed_requested_url['path'], '/' . $current_language . '/') !== false
        ) {
            $new_path = '/' . $current_language . $parsed_redirect_url['path'];            
            $redirect_url = str_replace($parsed_redirect_url['path'], $new_path, $redirect_url);
        }
    }

    return $redirect_url;
}, 10, 2); // Lage prioriteit om andere plugins eerst te laten draaien

// --- Vertaal ook de browser <title> tag via document_title_parts, net als menu items ---
add_filter('document_title_parts', function ($title_parts) {
    $core = AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    $default_language = $settings['default_language'];
    $current_language = $core->get_current_language();
    if ($current_language === $default_language) {
        return $title_parts;
    }
    // Vertaal de belangrijkste onderdelen met dezelfde logica als menu items en paginatitel
    if (isset($title_parts['title']) && !empty($title_parts['title'])) {
        $title_parts['title'] = $core->translate_template_part($title_parts['title'], 'post_title');
        $title_parts['title'] = $core::remove_translation_marker($title_parts['title']);
        $title_parts['title'] = $core->clean_html_string($title_parts['title']);
    }
    if (isset($title_parts['site']) && !empty($title_parts['site'])) {
        $title_parts['site'] = $core->translate_template_part($title_parts['site'], 'site_title');
        $title_parts['site'] = $core::remove_translation_marker($title_parts['site']);
        $title_parts['site'] = $core->clean_html_string($title_parts['site']);
    }
    if (isset($title_parts['tagline']) && !empty($title_parts['tagline'])) {
        $title_parts['tagline'] = $core->translate_template_part($title_parts['tagline'], 'tagline');
        $title_parts['tagline'] = $core::remove_translation_marker($title_parts['tagline']);
        $title_parts['tagline'] = $core->clean_html_string($title_parts['tagline']);
    }
    return $title_parts;
}, 99);

// --- Excerpt vertaling na genereren excerpt ---
add_filter('get_the_excerpt', function($excerpt, $post) {
    // Alleen vertalen als er een excerpt is (dus niet leeg)
    if (empty(trim($excerpt))) {
        return $excerpt;
    }
    // Voorkom vertaling in admin
    if (is_admin() && !wp_doing_ajax()) {
        return $excerpt;
    }
    $core = \AITranslate\AI_Translate_Core::get_instance();
    // Vertaal alleen als nodig
    if (!$core->needs_translation()) {
        return $excerpt;
    }
    // Vertaal de excerpt
    $translated = $core->translate_template_part($excerpt, 'post_excerpt');
    // Marker verwijderen
    $translated = $core::remove_translation_marker($translated);
    return $translated;
}, 20, 2);

/**
 * Initialize existing posts with their original slug metadata.
 * This ensures the slug tracking system works for existing content.
 */
function initialize_existing_posts_slug_metadata(): void
{
    global $wpdb;
    
    // Haal alle published posts en pages op die nog geen slug metadata hebben
    $posts = $wpdb->get_results(
        "SELECT p.ID, p.post_name, p.post_type 
         FROM {$wpdb->posts} p 
         LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ai_translate_original_slug'
         WHERE p.post_status = 'publish' 
         AND p.post_type IN ('post', 'page') 
         AND pm.meta_id IS NULL
         ORDER BY p.post_date DESC"
    );
    
    if (!empty($posts)) {
        foreach ($posts as $post) {
            update_post_meta($post->ID, '_ai_translate_original_slug', $post->post_name);
        }
    }
}

/**
 * Migrate existing translated URLs from transients to database for backwards compatibility.
 * This ensures that URLs that were already translated before the database implementation
 * remain stable and don't change when the LLM provides different translations.
 */
function migrate_existing_translated_urls(): void
{
    global $wpdb;
    $core = AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    
    // Haal alle enabled en detectable talen op
    $enabled_languages = $settings['enabled_languages'] ?? [];
    $detectable_languages = $settings['detectable_languages'] ?? [];
    $all_languages = array_unique(array_merge($enabled_languages, $detectable_languages));
    $default_language = $settings['default_language'] ?? 'nl';
    
    // Filter de default taal eruit (die hoeven we niet te migreren)
    $languages_to_migrate = array_diff($all_languages, [$default_language]);
    
    if (empty($languages_to_migrate)) {
        return; // Geen talen om te migreren
    }
    
    // Haal alle published posts en pages op
    $posts = $wpdb->get_results(
        "SELECT ID, post_name, post_type 
         FROM {$wpdb->posts} 
         WHERE post_status = 'publish' 
         AND post_type IN ('post', 'page') 
         ORDER BY post_date DESC"
    );
    
    if (empty($posts)) {
        return;
    }
    
    $table_name = $wpdb->prefix . 'ai_translate_slugs';
    $migrated_count = 0;
    
    foreach ($posts as $post) {
        foreach ($languages_to_migrate as $target_language) {
            // Controleer of er al een database entry bestaat
            $existing_entry = $wpdb->get_var($wpdb->prepare(
                "SELECT translated_slug FROM {$table_name} WHERE original_slug = %s AND language_code = %s AND post_id = %d",
                $post->post_name,
                $target_language,
                $post->ID
            ));
            
            // Als er al een database entry bestaat, sla over
            if ($existing_entry !== null) {
                continue;
            }
            
            // Probeer transient cache te vinden voor deze slug vertaling
            $cache_key = "slug_{$post->post_name}_{$post->post_type}_{$default_language}_{$target_language}";
            $transient_key = $core->generate_cache_key($cache_key, $target_language, 'slug_trans');
            $cached_slug = get_transient($transient_key);
            
            if ($cached_slug !== false) {
                // Migreer van transient naar database
                $insert_result = $wpdb->insert(
                    $table_name,
                    [
                        'post_id' => $post->ID,
                        'original_slug' => $post->post_name,
                        'translated_slug' => $cached_slug,
                        'language_code' => $target_language,
                    ],
                    ['%d', '%s', '%s', '%s']
                );
                
                if ($insert_result !== false) {
                    $migrated_count++;
                    
                    // Verwijder de transient na succesvolle migratie
                    delete_transient($transient_key);
                }
            }
        }
    }
    
    // Log de migratie resultaten
    if ($migrated_count > 0) {
        $core->log_event("Migrated {$migrated_count} translated URLs from transients to database for backwards compatibility", 'info');
    }
}
