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

    // Preserve current hash (#...) when switching language
    qsAll(".ai-trans-item").forEach(function (a) {
      a.addEventListener("click", function () {
        var h = window.location.hash || "";
        if (!h) return;
        var href = a.getAttribute("href") || "";
        if (!href) return;
        // Replace any existing hash with current hash
        var base = href.split("#")[0];
        a.setAttribute("href", base + h);
      });
    });

    document.addEventListener("click", function () {
      closeAll(null);
    });
  });
})();



