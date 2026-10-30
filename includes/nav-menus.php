<?php

/**
 * Navigation menu integration: language switcher meta box, menu item fields, walker.
 *
 * Loaded from ai-translate.php at the place where this code used to be, so hooks register in the same order.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Language Switcher meta box to menu editor
 */
add_action('admin_head-nav-menus.php', function() {
    add_meta_box(
        'ai-translate-language-switcher',
        __('🌐 Language Switcher', 'ai-translate'),
        function() {
            ?>
            <div id="ai-language-switcher" class="posttypediv">
                <div class="tabs-panel tabs-panel-active">
                    <ul class="categorychecklist form-no-clear">
                        <li>
                            <label class="menu-item-title">
                                <input type="checkbox" class="menu-item-checkbox" name="menu-item[-1][menu-item-object-id]" value="-1">
                                <?php esc_html_e('🌐 Language Switcher', 'ai-translate'); ?>
                            </label>
                            <input type="hidden" class="menu-item-type" name="menu-item[-1][menu-item-type]" value="custom">
                            <input type="hidden" class="menu-item-title" name="menu-item[-1][menu-item-title]" value="<?php esc_attr_e('Language Switcher', 'ai-translate'); ?>">
                            <input type="hidden" class="menu-item-url" name="menu-item[-1][menu-item-url]" value="#">
                            <input type="hidden" class="menu-item-classes" name="menu-item[-1][menu-item-classes]" value="ai-language-switcher">
                        </li>
                    </ul>
                </div>
                <p class="button-controls">
                    <span class="add-to-menu">
                        <input type="submit" class="button-secondary submit-add-to-menu right" value="<?php esc_attr_e('Add to Menu', 'ai-translate'); ?>" name="add-ai-language-switcher-menu-item" id="submit-ai-language-switcher">
                        <span class="spinner"></span>
                    </span>
                </p>
            </div>
            <?php
        },
        'nav-menus',
        'side',
        'default'
    );
});

/**
 * Handle adding language switcher menu item via normal WordPress menu process
 */
add_action('wp_update_nav_menu', function($menu_id, $menu_data = null) {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs inside core nav-menu save flow which verifies its own update-nav_menu nonce; no state is mutated here
    if (isset($_POST['menu-item'][-1]['menu-item-object-id']) && $_POST['menu-item'][-1]['menu-item-object-id'] == '-1') {
        // Language switcher was selected, it will be processed by wp_update_nav_menu_item
    }
});

add_action('wp_update_nav_menu_item', function($menu_id, $menu_item_db_id, $args) {
    $classes = isset($args['menu-item-classes']) ? $args['menu-item-classes'] : '';
    if (is_string($classes)) {
        $classes = explode(' ', $classes);
    }
    if (is_array($classes) && in_array('ai-language-switcher', $classes, true)) {
        update_post_meta($menu_item_db_id, '_menu_item_is_language_switcher', '1');
        update_post_meta($menu_item_db_id, '_menu_item_switcher_type', 'dropdown');
        update_post_meta($menu_item_db_id, '_menu_item_show_flags', 'true');
        update_post_meta($menu_item_db_id, '_menu_item_show_codes', 'true');
    }
}, 10, 3);

/**
 * Add Language Switcher tab to menu editor using WordPress hooks (fallback)
 */

add_action('admin_head-nav-menus.php', function() {
    // Add Language Switcher directly to the menu item types list
    ?>
    // Using native WordPress meta box approach instead of JavaScript manipulation
    <?php
});

/**
 * Add custom fields to menu items for language switcher
 */
add_action('wp_nav_menu_item_custom_fields', function($item_id, $item, $depth, $args) {
    $is_language_switcher = get_post_meta($item_id, '_menu_item_is_language_switcher', true);
    if ($is_language_switcher !== '1') {
        $item_classes = is_array($item->classes) ? $item->classes : array();
        if (in_array('ai-language-switcher', $item_classes, true) || $item->object === 'ai_language_switcher') {
            $is_language_switcher = '1';
            update_post_meta($item_id, '_menu_item_is_language_switcher', '1');
        }
    }
    $switcher_type = get_post_meta($item_id, '_menu_item_switcher_type', true) ?: 'dropdown';
    $show_flags = get_post_meta($item_id, '_menu_item_show_flags', true) !== 'false'; // Default true
    $show_codes = get_post_meta($item_id, '_menu_item_show_codes', true) !== 'false'; // Default true
    ?>
    <?php
    if ($is_language_switcher === '1') {
        $sw_position = \AITranslate\AI_Translate_Core::switcher_position();
        if ($sw_position !== 'none') {
            $settings_url = admin_url('admin.php?page=ai-translate');
            ?>
            <div class="ai-switcher-warning" style="background:#fef2f2;border:1px solid #dc2626;border-radius:4px;padding:8px 12px;margin:8px 0;">
                <p style="color:#dc2626;margin:0;font-weight:600;">
                    <?php printf(
                        /* translators: %1$s: string value, %2$s: string value, %3$s: string value */ esc_html__('This menu item is inactive. The language switcher is set to "%1$s" in %2$splugin settings%3$s. Set it to "Hidden" to use the menu switcher.', 'ai-translate'),
                        esc_html(ucwords(str_replace('-', ' ', $sw_position))),
                        '<a href="' . esc_url($settings_url) . '#switcher_position" style="color:#dc2626;">',
                        '</a>'
                    ); ?>
                </p>
            </div>
            <script type="text/javascript">
            (function(){
                var el = document.getElementById('menu-item-<?php echo (int) $item_id; ?>');
                if (el) {
                    var handle = el.querySelector('.menu-item-handle');
                    if (handle) { handle.style.borderLeft = '4px solid #dc2626'; }
                    var title = el.querySelector('.menu-item-title');
                    if (title) { title.style.color = '#dc2626'; }
                }
            })();
            </script>
            <?php
        }
    }
    ?>
    <p class="field-menu-item-is-language-switcher description description-wide">
        <label for="edit-menu-item-is-language-switcher-<?php echo (int) $item_id; ?>">
            <input type="checkbox" id="edit-menu-item-is-language-switcher-<?php echo (int) $item_id; ?>"
                   name="menu-item-is-language-switcher[<?php echo (int) $item_id; ?>]"
                   value="1" <?php checked($is_language_switcher, '1'); ?> />
            <?php esc_html_e('Make this a language switcher', 'ai-translate'); ?>
        </label>
    </p>

    <div class="field-menu-item-switcher-options description description-wide" style="display: <?php echo $is_language_switcher ? 'block' : 'none'; ?>;">
        <p>
            <label for="edit-menu-item-switcher-type-<?php echo (int) $item_id; ?>">
                <?php esc_html_e('Switcher Type:', 'ai-translate'); ?>
                <select id="edit-menu-item-switcher-type-<?php echo (int) $item_id; ?>"
                        name="menu-item-switcher-type[<?php echo (int) $item_id; ?>]">
                    <option value="dropdown" <?php selected($switcher_type, 'dropdown'); ?>><?php esc_html_e('Dropdown', 'ai-translate'); ?></option>
                    <option value="inline" <?php selected($switcher_type, 'inline'); ?>><?php esc_html_e('Inline', 'ai-translate'); ?></option>
                </select>
            </label>
        </p>

        <p>
            <label for="edit-menu-item-show-flags-<?php echo (int) $item_id; ?>">
                <input type="checkbox" id="edit-menu-item-show-flags-<?php echo (int) $item_id; ?>"
                       name="menu-item-show-flags[<?php echo (int) $item_id; ?>]"
                       value="1" <?php checked($show_flags, true); ?> />
                <?php esc_html_e('Show language flags', 'ai-translate'); ?>
            </label>
        </p>

        <p>
            <label for="edit-menu-item-show-codes-<?php echo (int) $item_id; ?>">
                <input type="checkbox" id="edit-menu-item-show-codes-<?php echo (int) $item_id; ?>"
                       name="menu-item-show-codes[<?php echo (int) $item_id; ?>]"
                       value="1" <?php checked($show_codes, true); ?> />
                <?php esc_html_e('Show language codes', 'ai-translate'); ?>
            </label>
        </p>
    </div>

    <script type="text/javascript">
    (function($) {
        $('#edit-menu-item-is-language-switcher-<?php echo (int) $item_id; ?>').on('change', function() {
            $(this).closest('.menu-item-settings').find('.field-menu-item-switcher-options').toggle($(this).is(':checked'));
        });
    })(jQuery);
    </script>
    <?php
}, 10, 4);

// Success message removed per user request - interface should be self-explanatory

/**
 * Normalize language switcher CSS classes so detection works regardless of how
 * the menu item was added (meta box sets 'ai-language-switcher', auto-add sets
 * 'menu-item-language-switcher'). Also adds 'menu-item-has-children' for themes
 * that need it for dropdown styling.
 */
add_filter('nav_menu_css_class', function($classes, $item) {
    $is_switcher = in_array('ai-language-switcher', $classes, true)
        || in_array('menu-item-language-switcher', $classes, true)
        || $item->object === 'ai_language_switcher'
        || get_post_meta($item->ID, '_menu_item_is_language_switcher', true) === '1';

    if ($is_switcher) {
        if (!in_array('ai-language-switcher', $classes, true)) {
            $classes[] = 'ai-language-switcher';
        }
        if (!in_array('menu-item-language-switcher', $classes, true)) {
            $classes[] = 'menu-item-language-switcher';
        }
        if (!in_array('menu-item-has-children', $classes, true)) {
            $classes[] = 'menu-item-has-children';
        }
    }

    return $classes;
}, 10, 2);

/**
 * Force custom walker when menu contains language switcher items
 */
add_filter('wp_nav_menu_args', function($args) {
    // Check if this menu contains language switcher items
    if (isset($args['menu']) && is_object($args['menu'])) {
        $menu_items = wp_get_nav_menu_items($args['menu']->term_id);
    } elseif (isset($args['menu']) && is_numeric($args['menu'])) {
        $menu_items = wp_get_nav_menu_items($args['menu']);
    } elseif (isset($args['theme_location'])) {
        $menu_locations = get_nav_menu_locations();
        if (isset($menu_locations[$args['theme_location']])) {
            $menu_items = wp_get_nav_menu_items($menu_locations[$args['theme_location']]);
        }
    }

    if (isset($menu_items) && is_array($menu_items)) {
        foreach ($menu_items as $item) {
            $is_language_switcher = get_post_meta($item->ID, '_menu_item_is_language_switcher', true);
            if ($is_language_switcher === '1' || $item->object === 'ai_language_switcher') {
                // This menu contains a language switcher, force our custom walker
                $args['walker'] = new AI_Translate_Menu_Walker();
                break;
            }
        }
    }

    return $args;
});

// Add shortcode for menu language switcher
add_shortcode('ai_menu_language_switcher', function($atts) {
    $atts = shortcode_atts(['show_flags' => 'true', 'show_codes' => 'true'], $atts);
    $html = ai_translate_generate_switcher_html(
        'dropdown',
        $atts['show_flags'] === 'true',
        $atts['show_codes'] === 'true'
    );
    if ($html === '') return '';
    return '<div class="menu-item-language-switcher menu-item-has-children">' . $html . '</div>';
});

// Direct database approach: Update menu items when they are saved

// Clean up existing menu items that have HTML in title
add_action('admin_init', function() {
    $menu_items = get_posts(array(
        'post_type' => 'nav_menu_item',
        'meta_key' => '_menu_item_object',
        'meta_value' => 'ai_language_switcher',
        'posts_per_page' => -1
    ));

    foreach ($menu_items as $menu_item) {
        $current_title = get_post_meta($menu_item->ID, '_menu_item_title', true);

        // If title contains HTML, clean it up
        if (strpos($current_title, '<img') !== false) {
            wp_update_nav_menu_item(
                get_post_meta($menu_item->ID, '_menu_item_menu_item_parent', true) ?: 0,
                $menu_item->ID,
                array(
                            'menu-item-title' => __('Language Switcher', 'ai-translate')
                )
            );
        }
    }
});

// Add JavaScript to replace language switcher menu items on frontend
add_action('wp_footer', function() {
    // Only run if we have language switcher menu items
    $enabled_languages = \AITranslate\AI_Translate_Core::enabled_languages();
    $default_language = \AITranslate\AI_Translate_Core::default_language();
    if ($default_language !== '' && !in_array($default_language, $enabled_languages, true)) {
        $enabled_languages[] = $default_language;
    }

    if (empty($enabled_languages) || empty($default_language)) {
        return;
    }

    // Determine current language
    $current_lang = null;
    $req_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '/';
    $path = (string) wp_parse_url($req_uri, PHP_URL_PATH);
    if ($path === '') {
        $path = '/';
    }
    $path = ai_translate_strip_site_path($path);

    if (preg_match('#^/([a-z]{2})(?=/|$)#i', $path, $matches)) {
        $current_lang = strtolower($matches[1]);
    }

    if (!$current_lang) {
        $current_lang = $default_language;
    }

    // Build base path (remove language prefix if present)
    $path_no_lang = preg_replace('#^/([a-z]{2})(?=/|$)#i', '', $path);
    if ($path_no_lang === '') {
        $path_no_lang = '/';
    }
    $path_no_lang = ai_translate_canonical_path($path_no_lang);

    $flags_url = plugin_dir_url(AI_TRANSLATE_PLUGIN_FILE) . 'assets/flags/';

    // Generate submenu HTML (exclude current language)
    $submenu_html = '<ul class="sub-menu children">';
    foreach ($enabled_languages as $lang_code) {
        $lang_code = sanitize_key($lang_code);
        $is_current = ($lang_code === $current_lang);

        // Skip current language
        if ($is_current) {
            continue;
        }

        $lang_label = strtoupper($lang_code);

        // Build URL
        $sub = ai_translate_site_path();
        if ($lang_code === $default_language) {
            $lang_url = esc_url($sub . $path_no_lang);
        } else {
            $lang_url = esc_url($sub . '/' . $lang_code . $path_no_lang);
        }

        $submenu_html .= '<li class="menu-item">';
        $submenu_html .= '<a href="' . $lang_url . '" data-lang="' . esc_attr($lang_code) . '" data-ai-trans-skip="1">';

        $submenu_html .= '<img src="' . esc_url($flags_url . $lang_code . '.png') . '" alt="' . esc_attr($lang_label) . '" class="ai-menu-language-flag" />';
        $submenu_html .= '<span class="ai-menu-language-code">' . esc_html($lang_label) . '</span>';

        $submenu_html .= '</a>';
        $submenu_html .= '</li>';
    }
    $submenu_html .= '</ul>';

    // Current language display
    $current_flag = esc_url($flags_url . sanitize_key($current_lang) . '.png');
    $current_label = strtoupper($current_lang);

    $replacement_html = '<li class="menu-item menu-item-language-switcher menu-item-has-children">';
    $replacement_html .= '<a href="#" class="ai-menu-language-current" data-ai-trans-skip="1">';
    $replacement_html .= '<img src="' . $current_flag . '" alt="' . esc_attr($current_label) . '" class="ai-menu-language-flag" />';
    $replacement_html .= '<span class="ai-menu-language-code">' . esc_html($current_label) . '</span>';
    $replacement_html .= '</a>';
    $replacement_html .= $submenu_html;
    $replacement_html .= '</li>';

    ?>
    <script>
    (function() {
        var replacementHtml = <?php echo json_encode($replacement_html); ?>;

        function initLanguageSwitcherReplacement() {

            // Find language switcher items by stable classes/object markers (not translated text).
            const candidates = document.querySelectorAll(
                'li.menu-item-language-switcher > a[href="#"], li.ai-language-switcher > a[href="#"], li[class*="menu-item-object-ai_language_switcher"] > a[href="#"], a.ai-menu-language-current[href="#"]'
            );

            candidates.forEach(function(link) {
                const listItem = link.closest('li');
                if (!listItem) {
                    return;
                }
                // Replace only when not already rendered with a submenu.
                if (!listItem.querySelector('.sub-menu') || !listItem.classList.contains('menu-item-language-switcher')) {
                    listItem.outerHTML = replacementHtml;
                }
            });

            // Safety net: if a language-switcher container exists without submenu, extract and
            // append the submenu from the replacement HTML rather than a second JSON blob.
            if (candidates.length === 0) {
                const switcherItems = document.querySelectorAll('li.menu-item-language-switcher');
                switcherItems.forEach(function(listItem) {
                    if (!listItem.querySelector('.sub-menu')) {
                        var tmp = document.createElement('ul');
                        tmp.innerHTML = replacementHtml;
                        var subMenu = tmp.querySelector('.sub-menu');
                        if (subMenu) {
                            listItem.appendChild(subMenu);
                        }
                        listItem.classList.add('menu-item-has-children');
                    }
                });
            }
        }

        // Run immediately, then once after a short delay for dynamically loaded content.
        initLanguageSwitcherReplacement();
        setTimeout(initLanguageSwitcherReplacement, 500);

        // Set cookie BEFORE navigating on language switch so server-side redirects
        // (RULE 4 / RULE 5) immediately see the new preference and don't bounce back.
        // Use the same domain format as PHP (.hostname) to avoid duplicate cookie conflicts
        // where the browser sends both an old PHP-set cookie and a new JS-set cookie.
        document.addEventListener('click', function(e) {
            var link = e.target.closest('a[data-lang]');
            if (link) {
                var lang = link.getAttribute('data-lang');
                if (lang) {
                    var host = window.location.hostname;
                    var cookieDomain = (host.indexOf('.') !== -1 && !/^\d+\.\d+\.\d+\.\d+$/.test(host)) ? '.' + host : '';
                    var cookieStr = 'ai_translate_lang=' + encodeURIComponent(lang) + '; path=/; max-age=2592000; SameSite=Lax; Secure';
                    if (cookieDomain) { cookieStr += '; domain=' + cookieDomain; }
                    document.cookie = cookieStr;
                    // Also clear any cookie set without domain (plain hostname) to avoid duplicates
                    document.cookie = 'ai_translate_lang=; path=/; max-age=0; SameSite=Lax; Secure';
                }
            }
        }, true);

    })();
    </script>
    <?php
});

/**
 * Save custom menu item fields.
 * Guard: only process when the menu editor custom fields were actually rendered
 * for this item. The switcher-type <select> is always submitted (even when its
 * parent div is hidden), so its presence proves the form was rendered. On the
 * initial add via the meta box, custom fields are not yet rendered, and we must
 * not overwrite the defaults set by the meta-box save handler.
 */
add_action('wp_update_nav_menu_item', function($menu_id, $menu_item_db_id) {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- runs inside core nav-menu save flow which verifies its own update-nav_menu nonce
    if (!isset($_POST['menu-item-switcher-type'][$menu_item_db_id])) {
        return;
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- this callback runs inside core's wp_update_nav_menu_item save flow which verifies its own update-nav_menu nonce
    if (isset($_POST['menu-item-is-language-switcher'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_is_language_switcher', '1');
        $switcher_type = isset($_POST['menu-item-switcher-type'][$menu_item_db_id])
            ? sanitize_text_field(wp_unslash((string) $_POST['menu-item-switcher-type'][$menu_item_db_id]))
            : '';
        update_post_meta($menu_item_db_id, '_menu_item_switcher_type', $switcher_type);
        update_post_meta($menu_item_db_id, '_menu_item_show_flags',
            isset($_POST['menu-item-show-flags'][$menu_item_db_id]) ? 'true' : 'false');
        update_post_meta($menu_item_db_id, '_menu_item_show_codes',
            isset($_POST['menu-item-show-codes'][$menu_item_db_id]) ? 'true' : 'false');
    } else {
        delete_post_meta($menu_item_db_id, '_menu_item_is_language_switcher');
        delete_post_meta($menu_item_db_id, '_menu_item_switcher_type');
        delete_post_meta($menu_item_db_id, '_menu_item_show_flags');
        delete_post_meta($menu_item_db_id, '_menu_item_show_codes');
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing
}, 10, 2);

/**
 * Render language switcher via walker_nav_menu_start_el filter.
 * This fires for ANY walker (including Elementor Pro, Astra, etc.) so the
 * switcher is rendered server-side even when the custom walker is bypassed.
 * Our own AI_Translate_Menu_Walker returns early before parent::start_el(),
 * so this filter never fires for items already handled by the custom walker.
 */
add_filter('walker_nav_menu_start_el', function($item_output, $item, $depth, $args) {
    $classes = is_array($item->classes) ? $item->classes : array();
    $is_switcher = in_array('ai-language-switcher', $classes, true)
        || in_array('menu-item-language-switcher', $classes, true)
        || $item->object === 'ai_language_switcher'
        || get_post_meta($item->ID, '_menu_item_is_language_switcher', true) === '1';

    if (!$is_switcher) {
        return $item_output;
    }

    $switcher_type = get_post_meta($item->ID, '_menu_item_switcher_type', true) ?: 'dropdown';
    $show_flags = get_post_meta($item->ID, '_menu_item_show_flags', true) !== 'false';
    $show_codes = get_post_meta($item->ID, '_menu_item_show_codes', true) !== 'false';

    return ai_translate_generate_switcher_html(
        $switcher_type === 'inline' ? 'inline' : 'dropdown',
        $show_flags,
        $show_codes
    );
}, 10, 4);

/**
 * Custom menu walker to render language switcher items
 */
class AI_Translate_Menu_Walker extends Walker_Nav_Menu {
    private $skip_item_id = 0;

    private function is_switcher_item($item) {
        return $item->object === 'ai_language_switcher' ||
               get_post_meta($item->ID, '_menu_item_is_language_switcher', true) === '1';
    }

    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
        if ($this->is_switcher_item($item)) {
            $position = \AITranslate\AI_Translate_Core::switcher_position();
            if ($position !== 'none') {
                $this->skip_item_id = $item->ID;
                return;
            }
            $this->render_language_switcher_menu_item($output, $item, $depth, $args, $id);
            return;
        }

        parent::start_el($output, $item, $depth, $args, $id);
    }

    public function end_el(&$output, $item, $depth = 0, $args = null) {
        if ($this->skip_item_id === $item->ID) {
            $this->skip_item_id = 0;
            return;
        }
        parent::end_el($output, $item, $depth, $args);
    }

    /**
     * Render language switcher as a menu item with submenu
     */
    private function render_language_switcher_menu_item(&$output, $item, $depth, $args, $id) {
        $switcher_type = get_post_meta($item->ID, '_menu_item_switcher_type', true) ?: 'dropdown';
        $show_flags = get_post_meta($item->ID, '_menu_item_show_flags', true) !== 'false';
        $show_codes = get_post_meta($item->ID, '_menu_item_show_codes', true) !== 'false';
        $classes = array('menu-item', 'menu-item-language-switcher');
        if ($switcher_type !== 'inline') {
            $classes[] = 'menu-item-has-children';
        }
        if (!empty($item->classes)) {
            $classes = array_merge($classes, (array) $item->classes);
        }
        $output .= '<li id="menu-item-' . (int) $item->ID . '" class="' . esc_attr(implode(' ', array_unique($classes))) . '">';
        $output .= ai_translate_generate_switcher_html(
            $switcher_type === 'inline' ? 'inline' : 'dropdown',
            $show_flags,
            $show_codes
        );
        $output .= '</li>';
    }
}
