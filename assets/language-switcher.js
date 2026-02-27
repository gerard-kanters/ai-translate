/**
 * AI Translate Language Switcher Shortcode JavaScript
 * Handles dropdown toggle and accessibility features
 */
(function() {
    'use strict';

    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initLanguageSwitchers();
    });

    /**
     * Initialize all language switcher dropdowns
     */
    function initLanguageSwitchers() {
        const switchers = document.querySelectorAll('.ai-language-switcher-dropdown');

        switchers.forEach(function(switcher) {
            const button = switcher.querySelector('.ai-language-switcher-btn');
            const menu = switcher.querySelector('.ai-language-switcher-menu');

            if (!button || !menu) {
                return;
            }

            // Toggle dropdown on button click
            button.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                toggleDropdown(switcher);
            });

            // Handle keyboard navigation
            button.addEventListener('keydown', function(event) {
                handleButtonKeydown(event, switcher);
            });

            // Handle menu item keydown
            const menuItems = menu.querySelectorAll('.ai-language-item');
            menuItems.forEach(function(item, index) {
                item.addEventListener('keydown', function(event) {
                    handleMenuItemKeydown(event, switcher, index, menuItems);
                });
            });
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            closeAllDropdowns();
        });

        // Close dropdowns on escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeAllDropdowns();
            }
        });
    }

    /**
     * Toggle dropdown visibility
     */
    function toggleDropdown(switcher) {
        const button = switcher.querySelector('.ai-language-switcher-btn');
        const menu = switcher.querySelector('.ai-language-switcher-menu');
        const isExpanded = button.getAttribute('aria-expanded') === 'true';

        if (isExpanded) {
            closeDropdown(switcher);
        } else {
            // Close other dropdowns first
            closeAllDropdowns();
            openDropdown(switcher);
        }
    }

    /**
     * Open a dropdown
     */
    function openDropdown(switcher) {
        const button = switcher.querySelector('.ai-language-switcher-btn');
        const menu = switcher.querySelector('.ai-language-switcher-menu');

        button.setAttribute('aria-expanded', 'true');
        menu.removeAttribute('hidden');

        // Focus first menu item for keyboard navigation
        const firstItem = menu.querySelector('.ai-language-item');
        if (firstItem) {
            firstItem.focus();
        }
    }

    /**
     * Close a dropdown
     */
    function closeDropdown(switcher) {
        const button = switcher.querySelector('.ai-language-switcher-btn');
        const menu = switcher.querySelector('.ai-language-switcher-menu');

        button.setAttribute('aria-expanded', 'false');
        menu.setAttribute('hidden', '');

        // Return focus to button
        button.focus();
    }

    /**
     * Close all dropdowns
     */
    function closeAllDropdowns() {
        const openSwitchers = document.querySelectorAll('.ai-language-switcher-dropdown .ai-language-switcher-btn[aria-expanded="true"]');

        openSwitchers.forEach(function(button) {
            const switcher = button.closest('.ai-language-switcher-dropdown');
            if (switcher) {
                closeDropdown(switcher);
            }
        });
    }

    /**
     * Handle button keyboard events
     */
    function handleButtonKeydown(event, switcher) {
        const button = switcher.querySelector('.ai-language-switcher-btn');

        switch (event.key) {
            case 'ArrowDown':
            case 'Enter':
            case ' ':
                event.preventDefault();
                toggleDropdown(switcher);
                break;
            case 'ArrowUp':
                event.preventDefault();
                // Open dropdown and focus last item
                closeAllDropdowns();
                openDropdown(switcher);
                const menu = switcher.querySelector('.ai-language-switcher-menu');
                const lastItem = menu.querySelector('.ai-language-item:last-child');
                if (lastItem) {
                    lastItem.focus();
                }
                break;
        }
    }

    /**
     * Handle menu item keyboard events
     */
    function handleMenuItemKeydown(event, switcher, currentIndex, menuItems) {
        const menu = switcher.querySelector('.ai-language-switcher-menu');

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                const nextIndex = Math.min(currentIndex + 1, menuItems.length - 1);
                menuItems[nextIndex].focus();
                break;
            case 'ArrowUp':
                event.preventDefault();
                const prevIndex = Math.max(currentIndex - 1, 0);
                menuItems[prevIndex].focus();
                break;
            case 'Home':
                event.preventDefault();
                menuItems[0].focus();
                break;
            case 'End':
                event.preventDefault();
                menuItems[menuItems.length - 1].focus();
                break;
            case 'Escape':
                event.preventDefault();
                closeDropdown(switcher);
                break;
            case 'Enter':
            case ' ':
                // Let the link handle the navigation
                break;
        }
    }

    /**
     * Set focus trap for accessibility
     * When dropdown is open, trap focus within the menu
     */
    function trapFocus(element, event) {
        const focusableElements = element.querySelectorAll(
            'a[href], button, textarea, input[type="text"], input[type="radio"], input[type="checkbox"], select'
        );
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        if (event.key === 'Tab') {
            if (event.shiftKey) {
                if (document.activeElement === firstElement) {
                    event.preventDefault();
                    lastElement.focus();
                }
            } else {
                if (document.activeElement === lastElement) {
                    event.preventDefault();
                    firstElement.focus();
                }
            }
        }
    }

    // Mobile menu support - toggle submenus on click/tap
    initMobileMenuSupport();
})();

/**
 * Initialize mobile menu support for language switcher submenus
 */
function initMobileMenuSupport() {
    let attempts = 0;
    const maxAttempts = 10;

    function bindMobileSwitchers() {
        // Find language switcher menu items
        const languageItems = document.querySelectorAll('.menu-item-language-switcher');
        let boundCount = 0;

        languageItems.forEach(function(item) {
            const link = item.querySelector('.ai-menu-language-current');
            const submenu = item.querySelector('.sub-menu');

            // Only handle dropdown items (with submenu), inline items work without JS
            if (link && submenu) {
                if (link.getAttribute('data-ai-mobile-bound') === '1') {
                    return;
                }
                link.setAttribute('data-ai-mobile-bound', '1');
                boundCount++;

                // Add click handler for mobile
                link.addEventListener('click', function(event) {
                    // Prevent hash-jumps and theme anchor handlers from hijacking this toggle.
                    event.preventDefault();

                    // Use click-to-expand when: (a) touch device (no hover) or (b) narrow viewport (no hover rule <769px).
                    var needsClick = window.matchMedia('(hover: none)').matches || window.innerWidth <= 768;
                    if (needsClick) {
                        event.stopPropagation();

                        // Toggle submenu visibility
                        const isVisible = submenu.classList.contains('ai-force-show');

                        if (isVisible) {
                            submenu.style.display = '';
                            submenu.style.removeProperty('max-height');
                            submenu.classList.remove('ai-force-show');
                            link.setAttribute('aria-expanded', 'false');
                        } else {
                            // Close other language switcher submenus first
                            document.querySelectorAll('.menu-item-language-switcher .sub-menu').forEach(function(otherSubmenu) {
                                if (otherSubmenu !== submenu) {
                                    otherSubmenu.style.display = '';
                                    otherSubmenu.style.removeProperty('max-height');
                                    otherSubmenu.classList.remove('ai-force-show');
                                    const otherLink = otherSubmenu.closest('.menu-item-language-switcher').querySelector('.ai-menu-language-current');
                                    if (otherLink) {
                                        otherLink.setAttribute('aria-expanded', 'false');
                                    }
                                }
                            });

                            submenu.style.display = 'block';
                            submenu.classList.add('ai-force-show');
                            link.setAttribute('aria-expanded', 'true');
                            // Override theme's max-height:0 on submenus (many mobile themes use inline max-height:0 !important)
                            submenu.style.setProperty('max-height', '500px', 'important');
                        }
                    }
                });

                // Add ARIA attributes for accessibility
                link.setAttribute('aria-expanded', 'false');
                link.setAttribute('aria-haspopup', 'true');
            }
        });

        // Retry shortly for themes that render/replace menus after initial load.
        if (boundCount === 0 && attempts < maxAttempts) {
            attempts++;
            setTimeout(bindMobileSwitchers, 300);
        }
    }

    bindMobileSwitchers();
}