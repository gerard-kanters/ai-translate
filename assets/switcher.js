(function () {
  function qsAll(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function closeAll(except) {
    qsAll(".ai-trans.ai-trans-open").forEach(function (w) {
      if (except && w === except) return;
      w.classList.remove("ai-trans-open");
      var b = w.querySelector(".ai-trans-btn");
      if (b) b.setAttribute("aria-expanded", "false");
    });
  }

  function onReady(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    var wrappers = qsAll(".ai-trans");
    if (!wrappers.length) return;

    wrappers.forEach(function (w) {
      var b = w.querySelector(".ai-trans-btn");
      if (!b) return;
      b.addEventListener("click", function (e) {
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

    // Set cookie and preserve hash when switching language
    qsAll(".ai-trans-item").forEach(function (a) {
      a.addEventListener("click", function (e) {
        console.log("[AI-Translate] Language switcher clicked", a);
        
        // Get language code from data-lang attribute (primary method)
        var lang = a.getAttribute("data-lang") || "";
        if (!lang) {
          // Fallback: Try to extract from href
          var href = a.getAttribute("href") || "";
          console.log("[AI-Translate] Extracting lang from href", href);
          
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
        
        console.log("[AI-Translate] Language code extracted:", lang, "from data-lang:", a.getAttribute("data-lang"));
        
        // Set cookie immediately via JavaScript (before navigation)
        if (lang) {
          var expires = new Date();
          expires.setTime(expires.getTime() + 30 * 24 * 60 * 60 * 1000); // 30 days
          var secure = window.location.protocol === "https:" ? ";secure" : "";
          var cookieStr = "ai_translate_lang=" + lang + ";path=/;expires=" + expires.toUTCString() + ";samesite=lax" + secure;
          document.cookie = cookieStr;
          console.log("[AI-Translate] Cookie set via JS:", cookieStr);
          console.log("[AI-Translate] Current cookies:", document.cookie);
        } else {
          console.warn("[AI-Translate] No language code found, cookie NOT set");
        }
        
        // Preserve current hash (#...) when switching language
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

    document.addEventListener("click", function () {
      closeAll(null);
    });
  });
})();



