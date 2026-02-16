/**
 * Language Switcher Frontend JavaScript
 *
 * Handles the client-side functionality for language switcher widgets on the website.
 * Provides dropdown behavior, language switching with cookie persistence, and URL hash preservation.
 *
 * Features:
 * - Toggle language dropdown menus
 * - Set language cookies on switching
 * - Preserve URL hashes during language changes
 * - Close other open dropdowns when opening new ones
 *
 * @since 1.0.0
 */
(function () {
  /**
   * Utility function to get all elements matching a selector as an Array
   * @param {string} sel - CSS selector
   * @param {Element} root - Root element to search in (defaults to document)
   * @returns {Array} Array of matching elements
   */
  function qsAll(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  /**
   * Close all open language switcher dropdowns except the specified one
   * @param {Element} except - Element to exclude from closing (can be null to close all)
   */
  function closeAll(except) {
    qsAll(".ai-trans.ai-trans-open").forEach(function (w) {
      if (except && w === except) return;
      w.classList.remove("ai-trans-open");
      var b = w.querySelector(".ai-trans-btn");
      if (b) b.setAttribute("aria-expanded", "false");
    });
  }

  /**
   * Execute function when DOM is ready
   * @param {Function} fn - Function to execute when DOM is ready
   */
  function onReady(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn);
    } else {
      fn();
    }
  }

  /**
   * Initialize language switcher functionality when DOM is ready
   */
  onReady(function () {
    // Find all language switcher widgets on the page
    var wrappers = qsAll(".ai-trans");
    if (!wrappers.length) return;

    // Initialize each language switcher widget
    wrappers.forEach(function (w) {
      var b = w.querySelector(".ai-trans-btn");
      if (!b) return;

      // Handle dropdown toggle button clicks
      b.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        var open = !w.classList.contains("ai-trans-open");
        closeAll(w);
        if (open) {
          w.classList.add("ai-trans-open");
          b.setAttribute("aria-expanded", "true");
        } else {
          w.classList.remove("ai-trans-open");
          b.setAttribute("aria-expanded", "false");
        }
      });
    });

    /**
     * Language Switching Logic
     * Handle clicks on language switcher items to set cookies and preserve URL state
     */
    qsAll(".ai-trans-item").forEach(function (a) {
      a.addEventListener("click", function (e) {
        // Extract language code from data-lang attribute or fallback to URL parsing
        var lang = a.getAttribute("data-lang") || "";
        if (!lang) {
          // Fallback: Try to extract from href
          var href = a.getAttribute("href") || "";
          
          // Try ?switch_lang=xx
          var match = href.match(/[?&]switch_lang=([a-z]{2})/i);
          if (match) {
            lang = match[1].toLowerCase();
          } else {
            // Try /xx/ in URL path
            match = href.match(/\/([a-z]{2})\/$/i);
            if (match) {
              lang = match[1].toLowerCase();
            }
          }
        }

        // Set language preference cookie immediately before navigation
        if (lang) {
          var expires = new Date();
          expires.setTime(expires.getTime() + 30 * 24 * 60 * 60 * 1000); // 30 days
          var secure = window.location.protocol === "https:" ? ";secure" : "";
          
          // Extract domain from current hostname for cookie scope
          // Examples: www.netcare.nl -> .netcare.nl, netcare.nl -> .netcare.nl
          var hostname = window.location.hostname;
          var domain = hostname;
          // If hostname has subdomain, use parent domain for broader cookie scope
          if (hostname.split('.').length > 2) {
            domain = hostname.substring(hostname.indexOf('.'));
          } else {
            domain = '.' + hostname;
          }
          
          var cookieStr = "ai_translate_lang=" + lang + ";path=/;domain=" + domain + ";expires=" + expires.toUTCString() + ";samesite=lax" + secure;
          document.cookie = cookieStr;
        }

        // Preserve current URL hash (anchor links) when switching language
        var h = window.location.hash || "";
        if (h) {
          var href = a.getAttribute("href") || "";
          if (href) {
            // Replace any existing hash with current hash
            var base = href.split("#")[0];
            a.setAttribute("href", base + h);
          }
        }
      });
    });

    // Close all dropdowns when clicking outside
    document.addEventListener("click", function () {
      closeAll(null);
    });
  });
})();



