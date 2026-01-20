# NAV Menu Language Switcher Implementation

This document describes the implementation of a language switcher that integrates directly into WordPress navigation menus, replacing the floating popup switcher with menu items that can be positioned exactly where needed in the navigation.

## Problem Statement

Users want to exclude certain menu items from automatic translation or place a language switcher exactly where they want it in their navigation, without relying on the floating popup switcher. The current floating switcher appears in fixed positions (corners) and may not fit all design requirements.

## Solution Overview

Implement a NAV menu integration that allows language switcher items to be added as standard WordPress menu items. This provides:

1. **Menu Item Exclusion**: Ability to exclude specific menu items from automatic translation
2. **Flexible Positioning**: Language switcher can be placed anywhere in navigation menus
3. **Mobile Compatibility**: Responsive design considerations for mobile navigation
4. **Admin Integration**: Reuse existing language settings and switcher placement logic

## Implementation Details

### 1. Menu Item Types

Add new menu item types to WordPress navigation menus:

- **Language Switcher Container**: A menu item that renders as a dropdown containing all enabled languages
- **Individual Language Items**: Direct menu items for specific languages (optional, for custom layouts)

### 2. Admin Panel Integration

Reuse existing language switcher placement settings in the admin panel:

```php
// Add to existing position options
$valid_positions = array(
    'nav-start',      // At beginning of navigation
    'nav-end',        // At end of navigation
    'bottom-left',    // Existing floating positions
    'bottom-right',
    'top-left',
    'top-right'
);
```

### 3. Menu Walker Modification

Extend WordPress menu walker to handle language switcher items:

```php
class AI_Translate_Nav_Walker extends Walker_Nav_Menu {
    private $enabled_languages = array();
    private $current_language = '';
    private $flags_url = '';

    public function __construct() {
        $settings = get_option('ai_translate_settings', array());
        $this->enabled_languages = isset($settings['enabled_languages']) ?
            array_values($settings['enabled_languages']) : array();
        $this->current_language = \AITranslate\AI_Lang::get_current();
        $this->flags_url = plugin_dir_url(__FILE__) . 'assets/flags/';
    }

    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
        // Handle special menu item types for language switching
        if (isset($item->object) && $item->object === 'ai_language_switcher') {
            $output .= $this->render_language_switcher($item, $args, $depth);
            return;
        }

        // Check if item should be excluded from translation
        if ($this->should_exclude_from_translation($item)) {
            $item->classes[] = 'ai-trans-skip';
        }

        parent::start_el($output, $item, $depth, $args, $id);
    }

    private function render_language_switcher($item, $args, $depth) {
        // Render dropdown or individual language items based on menu item configuration
        $switcher_type = get_post_meta($item->ID, '_ai_switcher_type', true) ?: 'dropdown';

        if ($switcher_type === 'dropdown') {
            return $this->render_dropdown_switcher($item, $args);
        } else {
            return $this->render_individual_items($item, $args, $depth);
        }
    }
}
```

### 4. CSS Implementation (`/assets/nav-switcher.css`)

New CSS for menu integration:

```css
/* NAV menu language switcher */
.ai-nav-language-switcher {
    position: relative;
}

.ai-nav-language-dropdown {
    display: none;
    position: absolute;
    top: 100%;
    left: 0;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    min-width: 200px;
    z-index: 1000;
}

.ai-nav-language-switcher:hover .ai-nav-language-dropdown,
.ai-nav-language-switcher:focus-within .ai-nav-language-dropdown {
    display: block;
}

.ai-nav-language-item {
    display: block;
    padding: 8px 12px;
    text-decoration: none;
    color: #333;
    border-bottom: 1px solid #eee;
}

.ai-nav-language-item:hover,
.ai-nav-language-item:focus {
    background: #f8f9fa;
}

.ai-nav-language-item:last-child {
    border-bottom: none;
}

.ai-nav-language-flag {
    width: 20px;
    height: 14px;
    margin-right: 8px;
    vertical-align: middle;
    border-radius: 2px;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .ai-nav-language-dropdown {
        position: static;
        display: block;
        box-shadow: none;
        border: none;
        background: transparent;
    }

    .ai-nav-language-item {
        padding: 12px;
        border-bottom: 1px solid #eee;
    }
}
```

### 5. JavaScript Integration (`/assets/nav-switcher.js`)

Handle menu interactions and cookie setting:

```javascript
(function() {
    'use strict';

    // Handle language switcher in navigation menus
    document.addEventListener('DOMContentLoaded', function() {
        const switchers = document.querySelectorAll('.ai-nav-language-switcher');

        switchers.forEach(function(switcher) {
            const trigger = switcher.querySelector('.ai-nav-language-trigger');
            const dropdown = switcher.querySelector('.ai-nav-language-dropdown');

            if (!trigger || !dropdown) return;

            // Toggle dropdown on click
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();

                // Close other dropdowns
                document.querySelectorAll('.ai-nav-language-dropdown.active')
                    .forEach(function(active) {
                        if (active !== dropdown) {
                            active.classList.remove('active');
                        }
                    });

                dropdown.classList.toggle('active');
            });

            // Handle language selection
            dropdown.querySelectorAll('.ai-nav-language-item').forEach(function(item) {
                item.addEventListener('click', function(e) {
                    const lang = this.getAttribute('data-lang');
                    if (lang) {
                        setLanguageCookie(lang);
                        // Navigation will happen via href
                    }
                });
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            document.querySelectorAll('.ai-nav-language-dropdown.active')
                .forEach(function(dropdown) {
                    dropdown.classList.remove('active');
                });
        });
    });

    function setLanguageCookie(lang) {
        const expires = new Date();
        expires.setTime(expires.getTime() + 30 * 24 * 60 * 60 * 1000);

        let domain = window.location.hostname;
        if (domain.split('.').length > 2) {
            domain = domain.substring(domain.indexOf('.'));
        } else {
            domain = '.' + domain;
        }

        const cookieStr = `ai_translate_lang=${lang};path=/;domain=${domain};expires=${expires.toUTCString()};samesite=lax`;
        document.cookie = cookieStr;
    }
})();
```

### 6. Menu Item Exclusion

Add data attribute to exclude menu items from translation:

```php
function should_exclude_from_translation($menu_item) {
    // Check if item has exclusion meta
    $exclude = get_post_meta($menu_item->ID, '_ai_exclude_translation', true);
    return $exclude === '1';
}
```

### 7. Admin Interface for Menu Items

Add custom fields to menu item editing:

```php
// Add custom fields to menu item
add_action('wp_nav_menu_item_custom_fields', function($item_id, $item, $depth, $args) {
    $exclude = get_post_meta($item_id, '_ai_exclude_translation', true);
    ?>
    <p class="field-ai-exclude">
        <label for="edit-menu-item-ai-exclude-<?php echo $item_id; ?>">
            <input type="checkbox" id="edit-menu-item-ai-exclude-<?php echo $item_id; ?>"
                   name="menu-item-ai-exclude[<?php echo $item_id; ?>]"
                   value="1" <?php checked($exclude, '1'); ?> />
            <?php _e('Exclude from AI translation', 'ai-translate'); ?>
        </label>
    </p>
    <?php
}, 10, 4);

// Save custom fields
add_action('wp_update_nav_menu_item', function($menu_id, $menu_item_db_id) {
    if (isset($_POST['menu-item-ai-exclude'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_ai_exclude_translation', '1');
    } else {
        delete_post_meta($menu_item_db_id, '_ai_exclude_translation');
    }
}, 10, 2);
```

## Mobile Considerations

1. **Responsive Design**: Dropdown becomes accordion-style on mobile
2. **Touch Friendly**: Larger touch targets for mobile devices
3. **Performance**: Lazy-load flag images on mobile to reduce bandwidth

## Technical Requirements

### Dependencies
- WordPress 5.0+
- PHP 8.0+
- Existing AI Translate plugin infrastructure

### Files to Modify/Create
1. `/includes/class-ai-nav-menu.php` - New navigation menu handler
2. `/assets/nav-switcher.css` - Menu-specific styles
3. `/assets/nav-switcher.js` - Menu interaction JavaScript
4. `/includes/admin-page.php` - Add menu exclusion options
5. `/ai-translate.php` - Initialize menu walker and enqueue assets

### Database Changes
- New post meta: `_ai_exclude_translation` for menu items
- Update settings to include menu positions

## ✅ COMPLETED: Shortcode Solution (Recommended First Step)

**Status**: ✅ **IMPLEMENTED**

The shortcode solution has been fully implemented and provides 80% of the required functionality with 20% of the effort.

#### Files Created/Modified:
- `ai-translate.php`: Added `ai_translate_language_switcher_shortcode()` function and shortcode registration
- `assets/language-switcher.css`: Complete responsive styling for both dropdown and inline layouts
- `assets/language-switcher.js`: Full accessibility support with keyboard navigation and ARIA compliance

#### Usage Examples:

```php
// Basic dropdown (default)
[ai_language_switcher]

// Inline horizontal layout
[ai_language_switcher type="inline"]

// Without flags
[ai_language_switcher show_flags="false"]

// Without codes
[ai_language_switcher show_codes="false"]

// Custom CSS class
[ai_language_switcher class="my-custom-switcher"]

// Combined options
[ai_language_switcher type="inline" show_flags="true" show_codes="false" class="header-languages"]
```

#### Features:
- ✅ **Dropdown Layout**: Current language button with expandable menu
- ✅ **Inline Layout**: Horizontal list of all languages
- ✅ **Flexible Display**: Show/hide flags and language codes independently
- ✅ **Accessibility**: Full keyboard navigation, ARIA labels, screen reader support
- ✅ **Mobile Responsive**: Touch-friendly on mobile devices
- ✅ **WordPress Menu Integration**: Native menu item support (zie hieronder)
- ✅ **Theme Integration**: Works with any theme that supports shortcodes

#### WordPress Menu Integration

De plugin voegt nu **native ondersteuning** toe voor taalwisselaars in WordPress menus met een gebruiksvriendelijke interface:

### 🆕 **Nieuwe Methode: Direct "Language Switcher" Toevoegen**

**Stap 1: Menu Editor Openen**
1. Ga naar **WordPress Admin → Appearance → Menus**
2. Je ziet nu een nieuwe **"Language" tab** naast Pages, Posts, Custom Links, etc.

**Stap 2: Language Switcher Toevoegen**
1. Klik op de **"Language" tab**
2. Vink het vakje aan bij **"Language Switcher"**
3. Klik op de **"Add to Menu"** knop
4. Het item verschijnt automatisch in je menu lijst met de titel "Language Switcher"

**Stap 3: Menu Opslaan**
1. Klik op **"Save Menu"**
2. Het taalwisselaar item werkt direct in je navigatie!

**💡 Pro Tip**: De 🌐 Language Switcher verschijnt automatisch in de lijst met menu items (bij Pages, Posts, Custom Links, Categories).

**🔧 Troubleshooting**: Als je de optie niet ziet, kijk in de browser console (F12) voor debug berichten van "AI Translate", of refresh de pagina.

**🎯 Hoe het eruit ziet:**
```
┌─────────────────────────────────────────────────────────────┐
│ WordPress Admin > Appearance > Menus                        │
├─────────────────────────────────────────────────────────────┤
│ Menu-items toevoegen                                        │
│                                                            │
│ [+] Pagina's                                                │
│     □ Cookiebeleid (EU)                                    │
│     □ Start met MyVox                                       │
│     □ ...                                                   │
│                                                            │
│ [+] Berichten                                               │
│ [+] Aangepaste links                                        │
│ [+] Categorieën                                             │
│                                                            │
│ 🌐 Language Switcher ←─────── NIEUWE OPTIE ──────────────┐ │
│     Add a dropdown language switcher with flags        │
│   [Add to Menu] ←─────── KLik HIER ──────────────────────┐ │
│                                                            │
│ [Aan menu toevoegen]                                       │
├─────────────────────────────────────────────────────────────┤
│ Menu Structuur:                                            │
│ ├─ Home                                                    │
│ ├─ Functionaliteit                                         │
│ └─ Language Switcher ←─── VERSCHIJNT HIER ──────────────┐ │
├─────────────────────────────────────────────────────────────┤
│ 💡 AI Translate Notice:                                    │
│ You can now add language switchers directly...           │
└─────────────────────────────────────────────────────────────┘
```

**🚀 Quick Setup**: Klik op "Auto-add Language Switcher" in de admin notice voor instant setup!

### 🔄 **Alternatieve Methode: Via Custom Links (Legacy)**

**Stap 1: Menu Item Aanmaken**
1. Klik op **"Add menu item" → "Custom Links"**
2. Voer een dummy URL in (bijv. `#`) en titel (bijv. "Languages")
3. Klik **"Add to Menu"**

**Stap 2: Omzetten naar Taalwisselaar**
1. Klik op het ▼ naast het nieuwe menu item om opties uit te klappen
2. Vink aan: **"Make this a language switcher"**
3. Kies het type: **"Dropdown"** of **"Inline"**
4. Stel in of je vlaggen en/of taal codes wilt tonen
5. Klik **"Save Menu"**

### 📍 **Menu Locatie Toewijzen**

1. Zorg dat het menu toegewezen is aan een menu locatie (bijv. "Primary Menu")
2. Het taalwisselaar item verschijnt nu in je navigatie
3. **Standaard instellingen**: Dropdown met vlaggen en taal codes

**Alternatieve Methode: Direct in Theme Code**

```php
// In header.php, nav.php, of functions.php
wp_nav_menu(array(
    'theme_location' => 'primary',
    'walker' => new AI_Translate_Menu_Walker(),
    'items_wrap' => '<ul id="%1$s" class="%2$s">%3$s</ul>'
));
```

#### Benefits:
- **Immediate Solution**: Works today without complex menu integration
- **User Control**: Place anywhere in content, widgets, or theme files
- **Theme Agnostic**: No dependency on menu system complexities
- **Backward Compatible**: Doesn't affect existing floating switcher

---

## Implementation Steps

### Phase 1: Core Infrastructure
- [ ] Create `class-ai-nav-menu.php` with proper menu walker
- [ ] Implement language switcher menu item type registration
- [ ] Add basic rendering logic for dropdown and individual items

### Phase 2: Admin Interface
- [ ] Extend menu item metabox with language switcher options
- [ ] Add exclusion checkbox to all menu items
- [ ] Update admin-page.php to handle menu-related settings

### Phase 3: Frontend Implementation
- [ ] Create `/assets/nav-switcher.css` with responsive design
- [ ] Implement `/assets/nav-switcher.js` with proper event handling
- [ ] Add keyboard navigation and accessibility features

### Phase 4: Integration & Testing
- [ ] Modify `wp_nav_menu` calls to use custom walker when needed
- [ ] Test with various themes and menu configurations
- [ ] Performance testing with large menus

### Phase 5: Edge Cases & Polish
- [ ] Handle nested menu structures
- [ ] Test with page builders (Elementor, WPBakery, etc.)
- [ ] Implement fallback for themes that don't support custom walkers

## Shortcode Usage Guide

### Basic Usage
Place the shortcode anywhere in your content, widgets, or theme files:

```php
[ai_language_switcher]
```

### Advanced Usage Examples

#### In Header/Navigation
```php
<!-- In header.php or custom header widget -->
<div class="site-header-languages">
    <?php echo do_shortcode('[ai_language_switcher type="inline" show_codes="false" class="header-languages"]'); ?>
</div>
```

#### In Footer
```php
<!-- In footer.php -->
<div class="footer-languages">
    <?php echo do_shortcode('[ai_language_switcher type="dropdown" class="footer-languages"]'); ?>
</div>
```

#### In Page Builders
- **Elementor**: Use the "Shortcode" widget
- **WPBakery**: Use the "Raw HTML" or "Text Block" element
- **Gutenberg**: Use the "Shortcode" block

#### In Theme Files
```php
// In functions.php or custom theme functions
function add_language_switcher_to_header() {
    echo '<div class="custom-language-switcher">';
    echo do_shortcode('[ai_language_switcher type="inline" class="nav-languages"]');
    echo '</div>';
}
add_action('wp_header', 'add_language_switcher_to_header');
```

### CSS Customization

The shortcode accepts a custom CSS class for styling:

```php
[ai_language_switcher class="my-custom-theme-languages"]
```

```css
/* Custom styling example */
.my-custom-theme-languages .ai-language-switcher-btn {
    background: #your-brand-color;
    border-radius: 20px;
}

.my-custom-theme-languages .ai-language-item:hover {
    background: #your-hover-color;
}
```

### Mobile Considerations

The shortcode is fully responsive by default:
- **Dropdown**: Becomes full-width on mobile with larger touch targets
- **Inline**: Wraps languages and increases spacing for touch
- **Accessibility**: Maintains keyboard navigation on mobile devices

## Benefits

1. **Flexibility**: Language switcher can be positioned anywhere in navigation
2. **Design Integration**: Matches existing navigation design patterns
3. **Accessibility**: Better keyboard navigation and screen reader support
4. **Performance**: No floating elements affecting page layout
5. **User Control**: Fine-grained control over which menu items get translated

## Critical Considerations & Risks

### ⚠️ High-Risk Issues

1. **Theme Compatibility**: Many themes override `wp_nav_menu` calls and may not respect custom walkers
   - **Mitigation**: Provide theme integration guide and fallback options

2. **Menu Cache Plugins**: Popular plugins like WP Rocket cache menu HTML
   - **Mitigation**: Document cache clearing requirements and provide admin notices

3. **Page Builders**: Elementor, Divi, etc. handle menus differently
   - **Mitigation**: Test with major page builders and provide compatibility notes

4. **Performance Impact**: Custom walker adds processing overhead
   - **Mitigation**: Implement caching for language data and optimize rendering

### 🔒 Security Considerations

1. **Menu Item Validation**: Ensure only authorized users can create language switcher items
2. **XSS Prevention**: Sanitize all menu item data and escape output properly
3. **CSRF Protection**: Use nonces for menu item updates

### 📱 Accessibility Requirements

1. **Keyboard Navigation**: Full keyboard support for dropdown navigation
2. **Screen Readers**: Proper ARIA labels and roles
3. **Focus Management**: Logical tab order and focus trapping
4. **High Contrast**: Ensure visibility in high contrast modes

## Migration Path

Existing floating switcher configurations will continue to work. New menu-based switchers can be enabled per-menu or globally through admin settings.

### Backward Compatibility

- Existing `nav-start`/`nav-end` positions remain functional
- No breaking changes to current API
- Graceful degradation when menu integration fails

### Recommended Rollout Strategy

1. **Beta Phase**: Enable for specific sites with comprehensive testing
2. **Staged Rollout**: Gradually enable for production sites
3. **Fallback Mechanism**: Provide option to revert to floating switcher