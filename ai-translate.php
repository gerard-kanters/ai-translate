<?php
/*
Plugin Name: AI Translate
Plugin URI: https://github.com/gerard-kanters/ai-translate
Description: Translate your wordpress site with AI 🤖
Version: 1.14
Author: Gerard Kanters
Author URI: https://www.netcare.nl/
License: GPL2
*/

declare(strict_types=1);


namespace AITranslate;


if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('AI_TRANSLATE_VERSION', '1.14');
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


add_action('plugins_loaded', function () { // Keep this hook for loading core
    // Plugin initialisatie
    add_action('init', __NAMESPACE__ . '\\init_plugin', 5); // Keep for basic init like scripts
    add_action('init', __NAMESPACE__ . '\\add_language_rewrite_rules', 10); // Add rewrite rules later in init

    if (is_admin()) {
        require_once AI_TRANSLATE_DIR . 'includes/admin-page.php';
        return;
    }

    $core = AI_Translate_Core::get_instance();

    add_action('wp_head', [$core, 'add_simple_meta_description'], 99);

    // Start output buffering voor volledige HTML vertaling (page builder support)
    add_action('template_redirect', function () {
        if (!is_admin() && !wp_doing_ajax()) {
            ob_start('AITranslate\\ai_translate_buffer_callback');
        }
    });

    // Callback functie voor output buffering
    function ai_translate_buffer_callback($buffer) {
        $core = \AITranslate\AI_Translate_Core::get_instance();
        $current_language = $core->get_current_language();
        $default_language = $core->get_settings()['default_language'];

        if ($current_language === $default_language) {
            return $buffer; // Geen vertaling nodig
        }

        // Regex om alle href attributen te vinden
        $buffer = preg_replace_callback(
            '/(<a\s+[^>]*href=["\'])([^"\']*)((?:["\'][^>]*>)|(?:["\']\s*\/>))/i',
            function ($matches) use ($core, $current_language) {
                $before_href = $matches[1]; // Alles voor de href waarde
                $original_url = $matches[2]; // De originele URL
                $after_href = $matches[3]; // Alles na de href waarde

                // Gebruik wp_parse_url voor robuustere interne URL detectie
                $parsed_url = wp_parse_url($original_url);

                // Controleer of het een relatieve URL is of een interne absolute URL
                if (
                    !isset($parsed_url['scheme']) || // Relatieve URL
                    (isset($parsed_url['host']) && $parsed_url['host'] === wp_parse_url(home_url(), PHP_URL_HOST)) // Interne absolute URL
                ) {
                    // Vertaal de URL
                    $translated_url = $core->translate_url($original_url, $current_language);
                    return $before_href . $translated_url . $after_href;
                }
                return $matches[0]; // Retourneer originele tag als het geen interne URL is
            },
            $buffer
        );

        return $buffer;
    }

    add_action('wp_footer', [$core, 'hook_display_language_switcher']);

    add_filter('wp_nav_menu_objects', function ($items, $args) use ($core) {
        $target_language = $core->get_current_language();
        $settings = $core->get_settings();
        $default_language = $settings['default_language'];

        if ($target_language === $default_language || empty($items)) {
            return $items;
        }

        foreach ($items as $item) {
            $original_title = $item->title;
            $original_url = $item->url;
            
            // Translate the title
            $item->title = $core->translate_text($item->title, $default_language, $target_language, true);
            // Remove the marker before display
            $item->title = AI_Translate_Core::remove_translation_marker($item->title);

            // Translate URLs for menu items
            if (
                isset($item->object_id) &&
                in_array($item->object, ['page', 'post', 'custom_post_type']) &&
                $item->type === 'post_type'
            ) {
                $url = get_permalink($item->object_id);
                $item->url = $core->translate_url($url, $target_language);
            } elseif (strpos($item->url, home_url()) === 0) {
                // Also translate other internal links (like custom links to homepage)
                $item->url = $core->translate_url($item->url, $target_language);
            }
        }
        return $items;
    }, 10, 2);

    add_filter('option_blogname', function ($value) use ($core) {
        $translated_value = $core->translate_template_part($value, 'site_title');
        // Marker direct strippen
        return \AITranslate\AI_Translate_Core::remove_translation_marker($translated_value);
    }, 100);
    
    add_filter('option_blogdescription', function ($value) use ($core) {
        $translated_value = $core->translate_template_part($value, 'tagline');
        // Marker direct strippen
        return \AITranslate\AI_Translate_Core::remove_translation_marker($translated_value);
    }, 100);

    // Voeg de home_url filter pas toe via de wp actie zodat de globale query beschikbaar is
    add_action('wp', function () {
        add_filter('home_url', function ($url, $path, $orig_scheme, $blog_id) {
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
            add_filter('widget_text', function ($text) use ($core) {
                // Skip in admin
                if (is_admin() && !wp_doing_ajax()) {
                    return $text;
                }

                // NIEUWE CHECK: Zorg ervoor dat $text een string is voordat we trim() gebruiken
                if (!is_string($text)) {
                    // Als $text geen string is (bijv. null), retourneer direct.
                    // Dit voorkomt de TypeError bij trim() en verdere verwerking.
                    return $text;
                }

                // Skip empty content (nu veilig om trim te gebruiken)
                if (empty(trim($text))) {
                    return $text;
                }

                // Voorkom recursie
                static $processing_widget_text = false;
                if ($processing_widget_text) {
                    return $text;
                }
                $processing_widget_text = true;

                // First, clean up any existing multiple markers
                // Controleer of de marker aanwezig is in de string $text
                if (strpos($text, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
                    // Strip all instances of the marker to prevent accumulation
                    $clean_text = str_replace(AI_Translate_Core::TRANSLATION_MARKER, '', $text);

                    // Translate the cleaned text as a whole
                    $result = $core->translate_template_part($clean_text, 'widget_text');
                } else {
                    // Normal case - translate everything
                    $result = $core->translate_template_part($text, 'widget_text');
                }

                $processing_widget_text = false;
                // Zorg ervoor dat het resultaat ook een string is (hoewel translate_template_part dat zou moeten doen)
                // Als het geen string is, retourneer de originele $text om verdere fouten te voorkomen.
                return is_string($result) ? $result : $text;
            }, 10);
            
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

    add_filter('the_title', function ($title, $id = null) use ($core) {
        // Als de marker al aanwezig is (bijv. door een eerdere filter), retourneer direct.
        // Dit voorkomt dubbele verwerking en mogelijke problemen.
        if (strpos((string)$title, AI_Translate_Core::TRANSLATION_MARKER) !== false) {
            return $title;
        }
    
        // Standaard checks: niet vertalen als niet nodig, in admin, of lege titel.
        if (!$core->needs_translation() || is_admin() || empty($title)) {
            return $title;
        }
    
        // Recursie guard: voorkom oneindige loops als dit filter opnieuw wordt aangeroepen.
        static $processing_title = [];
        $guard_key = $id ?? md5($title); // Gebruik post ID of hash van titel als unieke sleutel.
        if (isset($processing_title[$guard_key])) {
            return $title; // Al bezig met verwerken van deze titel.
        }
        $processing_title[$guard_key] = true; // Markeer als bezig.
    
        // Vertaal de titel met de core functie.
        $translated_title = $core->translate_template_part($title, 'post_title');
    
        // Verwijder de vertaalmarker direct na de vertaling.
        $result = AI_Translate_Core::remove_translation_marker($translated_title);
    
        // Verwijder de guard markering zodat de titel opnieuw verwerkt kan worden indien nodig.
        unset($processing_title[$guard_key]);
    
        // Retourneer de vertaalde titel zonder marker.
        return $result;
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
    
    // Haal de taalcode uit de cookie
    add_action('init', function () {
        $language_code = isset($_COOKIE['ai_translate_lang']) ? sanitize_text_field(wp_unslash($_COOKIE['ai_translate_lang'])) : '';
        $settings = AI_Translate_Core::get_instance()->get_settings();
        $default_language = $settings['default_language'];
        // Als de cookie geen taalcode bevat, of als de taalcode ongeldig is, stel dan de standaardtaal in
        if (empty($language_code) || !in_array($language_code, $settings['enabled_languages'])) {
            $language_code = $default_language;

            // Log de huidige taal
            //error_log("AI Translate: Current language set to: $language_code");
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
}); // End of plugins_loaded action

// Start output buffering voor volledige HTML vertaling (page builder support)
// Nieuwe, veiligere hook:
add_filter('template_include', function ($template) {
    $core = \AITranslate\AI_Translate_Core::get_instance();
    return $template;
}, 0);

add_filter('plugin_action_links_' . plugin_basename(__FILE__), __NAMESPACE__ . '\\ai_translate_settings_link');

function ai_translate_settings_link($links): array
{
    $settings_link = '<a href="admin.php?page=ai-translate">Settings</a>';
    array_unshift($links, $settings_link);
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
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY unique_translation (post_id, language_code),
        KEY idx_translated_slug (translated_slug, language_code),
        KEY idx_original_slug (original_slug)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function plugin_deactivate(): void
{
    flush_rewrite_rules();
}

function init_plugin(): void
{
    // Basic init - enqueue scripts/styles
    $core = AI_Translate_Core::get_instance();
    $settings = $core->get_settings();
    if (!empty($settings['debug_mode'])) {
        //error_log("PLUGIN init_plugin: Plugin initialized. Memory: " . memory_get_usage());
    }
    wp_enqueue_script('jquery');
    // Enqueue switcher JS only if needed (moved from core class potentially)
    if (!is_admin()) {
        wp_enqueue_script('ai-translate-switcher-js', plugins_url('assets/language-switcher.js', AI_TRANSLATE_FILE), ['jquery'], AI_TRANSLATE_VERSION, true);
    }
    wp_enqueue_style('ai-translate-switcher', plugins_url('assets/language-switcher.css', AI_TRANSLATE_FILE), [], AI_TRANSLATE_VERSION);
}

function add_language_rewrite_rules(): void
{
    // Add rewrite rules for translated custom post types (services and products)
    add_rewrite_rule('^([a-z]{2})/service/([^/]+)/?$', 'index.php?lang=$matches[1]&post_type=service&name=$matches[2]', 'top');
    add_rewrite_rule('^([a-z]{2})/product/([^/]+)/?$', 'index.php?lang=$matches[1]&post_type=product&name=$matches[2]', 'top');

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

    // Rule for pages/posts with language prefix
    add_rewrite_rule('^' . $lang_regex . '/(.+?)/?$', 'index.php?lang=$matches[1]&pagename=$matches[2]', 'top');
    // Blogposts (standaard post type)
    add_rewrite_rule('^' . $lang_regex . '/([^/]+)/?$', 'index.php?lang=$matches[1]&name=$matches[2]', 'top');
    // Rule for homepage with language prefix
    add_rewrite_rule('^' . $lang_regex . '/?$', 'index.php?lang=$matches[1]', 'top');

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
            $post->post_excerpt = $core->translate_template_part($post->post_excerpt, 'post_excerpt');
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
    add_filter('get_the_excerpt', [$class_name_for_filters, 'remove_translation_marker'], $marker_removal_priority);
    add_filter('the_excerpt', [$class_name_for_filters, 'remove_translation_marker'], $marker_removal_priority);

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
    // error_log('AI Translate Fout: Klasse ' . $class_name_for_filters . ' of methode remove_translation_marker niet gevonden tijdens initialisatie van marker verwijderingsfilters.');
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
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
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
                    $wp->query_vars = ['page_id' => $blog_id];
                } elseif ($front_id && intval($front_id) > 0) {
                    $wp->query_vars = ['page_id' => $front_id];
                } elseif ($blog_id && intval($blog_id) > 0) {
                    $wp->query_vars = ['page_id' => $blog_id];
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
    // Tijdelijk uitgeschakeld voor debugging
    return $redirect_url;
}, 99, 2);

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
    }
    if (isset($title_parts['site']) && !empty($title_parts['site'])) {
        $title_parts['site'] = $core->translate_template_part($title_parts['site'], 'site_title');
        $title_parts['site'] = $core::remove_translation_marker($title_parts['site']);
    }
    if (isset($title_parts['tagline']) && !empty($title_parts['tagline'])) {
        $title_parts['tagline'] = $core->translate_template_part($title_parts['tagline'], 'tagline');
        $title_parts['tagline'] = $core::remove_translation_marker($title_parts['tagline']);
    }
    return $title_parts;
}, 99);


