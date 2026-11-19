/*
  Finéa Help Tour (lightweight guided popup)
  - No external dependencies
  - localStorage versioning (auto show once per version)
  - Optional element highlight + tooltip positioning
*/

(function () {
  "use strict";

  if (
    window.FineaHelpTour &&
    typeof window.FineaHelpTour.create === "function"
  ) {
    return;
  }

  function injectStyles() {
    if (document.getElementById("finea-help-tour-styles")) return;

    var style = document.createElement("style");
    style.id = "finea-help-tour-styles";
    style.type = "text/css";
    style.textContent =
      "[data-finea-tour-overlay='1']{position:fixed;inset:0;z-index:9998;background:rgba(0,0,0,.5);}" +
      "[data-finea-tour-popover='1']{position:fixed;z-index:9999;width:92vw;max-width:28rem;border:2px solid #e5e7eb;background:#fff;border-radius:1.5rem;box-shadow:0 20px 60px rgba(0,0,0,.25);}" +
      "[data-finea-tour-popover='1'] .finea-tour-pad{padding-left:1.25rem;padding-right:1.25rem;}" +
      "[data-finea-tour-popover='1'] .finea-tour-title{padding-top:1.25rem;font-weight:800;font-size:1rem;color:#111827;}" +
      "[data-finea-tour-popover='1'] .finea-tour-step{margin-top:.25rem;font-weight:700;font-size:.75rem;color:#4b5563;}" +
      "[data-finea-tour-popover='1'] .finea-tour-text{padding-top:.75rem;padding-bottom:0;font-size:.875rem;line-height:1.4;color:#1f2937;}" +
      "[data-finea-tour-popover='1'] .finea-tour-footer{padding-top:1rem;padding-bottom:1rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;}" +
      "[data-finea-tour-popover='1'] .finea-tour-actions{display:flex;align-items:center;gap:.5rem;}" +
      ".finea-tour-btn{appearance:none;border:1px solid #e5e7eb;background:#fff;color:#111827;border-radius:1rem;padding:.5rem 1rem;font-weight:800;font-size:.875rem;cursor:pointer;}" +
      ".finea-tour-btn:hover{background:#f3f4f6;}" +
      ".finea-tour-btn-primary{border-color:#111827;background:#111827;color:#fff;}" +
      ".finea-tour-btn-primary:hover{background:#1f2937;}" +
      ".finea-tour-btn[disabled]{opacity:.5;cursor:not-allowed;}" +
      ".finea-tour-highlight{outline:4px solid rgba(99,102,241,.75);outline-offset:4px;}";

    (document.head || document.documentElement).appendChild(style);
  }

  function clamp(value, min, max) {
    return Math.max(min, Math.min(max, value));
  }

  function qs(selector, root) {
    return (root || document).querySelector(selector);
  }

  function createEl(tag, className) {
    var el = document.createElement(tag);
    if (className) el.className = className;
    return el;
  }

  function isElementVisible(el) {
    if (!el || !(el instanceof Element)) return false;
    try {
      var style = window.getComputedStyle ? window.getComputedStyle(el) : null;
      if (style) {
        if (style.display === "none") return false;
        if (style.visibility === "hidden") return false;
        if (style.opacity === "0") return false;
      }
      var rect = el.getBoundingClientRect();
      if (!rect) return false;
      return rect.width > 0 && rect.height > 0;
    } catch (e) {
      return false;
    }
  }

  function ensureFloatingHelpButton(id) {
    var floatingId = String(id || "helpBtn") + "Floating";
    var existing = document.getElementById(floatingId);
    if (existing) return existing;

    var btn = document.createElement("button");
    btn.type = "button";
    btn.id = floatingId;
    btn.setAttribute("aria-label", "Aide");
    btn.setAttribute("title", "Aide");
    btn.setAttribute("data-finea-help-launcher", "1");
    btn.style.cssText =
      "position:fixed;right:16px;bottom:16px;z-index:9997;" +
      "display:inline-flex;align-items:center;gap:10px;" +
      "border:1px solid #e5e7eb;background:#ffffff;color:#111827;" +
      "border-radius:9999px;padding:10px 14px;" +
      "font-weight:800;font-size:13px;line-height:1;" +
      "box-shadow:0 12px 30px rgba(0,0,0,.15);cursor:pointer;";

    var bubble = document.createElement("span");
    bubble.textContent = "?";
    bubble.style.cssText =
      "display:inline-flex;align-items:center;justify-content:center;" +
      "width:28px;height:28px;border-radius:9999px;" +
      "background:#111827;color:#ffffff;font-weight:900;";

    var label = document.createElement("span");
    label.textContent = "Aide";

    btn.appendChild(bubble);
    btn.appendChild(label);

    try {
      (document.body || document.documentElement).appendChild(btn);
    } catch (e) {
      // ignore
    }

    return btn;
  }

  function normalizeSteps(steps) {
    if (!Array.isArray(steps)) return [];
    return steps
      .map(function (s) {
        if (!s) return null;
        return {
          title: String(s.title || ""),
          text: String(s.text || ""),
          selector: s.selector ? String(s.selector) : null,
          placement: s.placement ? String(s.placement) : "auto",
        };
      })
      .filter(Boolean);
  }

  function storageKeys(prefix, key) {
    var safeKey = String(key || "default");
    var p = String(prefix || "finea_help_tour");
    return {
      version: p + ":" + safeKey + ":version",
    };
  }

  function shouldAutoShow(keys, version) {
    try {
      var seenVersion = localStorage.getItem(keys.version);
      return String(seenVersion || "") !== String(version || "");
    } catch (e) {
      return false;
    }
  }

  function markSeen(keys, version) {
    try {
      localStorage.setItem(keys.version, String(version || ""));
    } catch (e) {
      // ignore
    }
  }

  function createHelpTour(config) {
    config = config || {};

    var key = String(config.key || "page");
    var version = String(config.version || "1");
    var prefix = String(config.storagePrefix || "finea_help_tour");
    var helpButtonId = config.helpButtonId ? String(config.helpButtonId) : null;
    var auto = config.auto !== false;

    var steps = normalizeSteps(config.steps);
    var keys = storageKeys(prefix, key);

    var state = {
      open: false,
      index: 0,
      overlay: null,
      popover: null,
      titleEl: null,
      textEl: null,
      stepEl: null,
      prevBtn: null,
      nextBtn: null,
      closeBtn: null,
      highlightedEl: null,
    };

    function clearHighlight() {
      if (!state.highlightedEl) return;
      try {
        state.highlightedEl.classList.remove("finea-tour-highlight");
        state.highlightedEl.classList.remove(
          "ring-4",
          "ring-indigo-400",
          "ring-offset-4",
          "ring-offset-white",
        );
        state.highlightedEl.removeAttribute("data-finea-tour-highlight");
      } catch (e) {
        // ignore
      }
      state.highlightedEl = null;
    }

    function applyHighlight(selector) {
      clearHighlight();
      if (!selector) return null;

      var el = qs(selector);
      if (!el) return null;

      try {
        el.setAttribute("data-finea-tour-highlight", "1");
        el.classList.add("finea-tour-highlight");
        el.classList.add(
          "ring-4",
          "ring-indigo-400",
          "ring-offset-4",
          "ring-offset-white",
        );
        state.highlightedEl = el;
      } catch (e) {
        // ignore
      }

      try {
        el.scrollIntoView({ behavior: "smooth", block: "center" });
      } catch (e2) {
        // ignore
      }

      return el;
    }

    function ensureUi() {
      if (state.overlay) return;

      injectStyles();

      var overlay = createEl("div", "fixed inset-0 z-[9998] bg-black/50");
      overlay.setAttribute("data-finea-tour-overlay", "1");
      overlay.setAttribute("role", "presentation");

      var popover = createEl(
        "div",
        "fixed z-[9999] w-[92vw] max-w-md rounded-[1.5rem] border-2 border-gray-200 bg-white shadow-xl",
      );
      popover.setAttribute("data-finea-tour-popover", "1");
      popover.setAttribute("role", "dialog");
      popover.setAttribute("aria-modal", "true");

      var header = createEl("div", "px-5 pt-5");
      header.classList.add("finea-tour-pad");
      var title = createEl(
        "div",
        "text-base font-extrabold text-gray-900 finea-tour-title",
      );
      var step = createEl(
        "div",
        "mt-1 text-xs font-semibold text-gray-600 finea-tour-step",
      );
      header.appendChild(title);
      header.appendChild(step);

      var body = createEl("div", "px-5 pt-3");
      body.classList.add("finea-tour-pad");
      var text = createEl(
        "div",
        "text-sm text-gray-800 leading-relaxed finea-tour-text",
      );
      body.appendChild(text);

      var footer = createEl(
        "div",
        "px-5 py-4 flex items-center justify-between gap-3",
      );
      footer.classList.add("finea-tour-pad", "finea-tour-footer");
      var left = createEl("div", "flex items-center gap-2 finea-tour-actions");
      var right = createEl("div", "flex items-center gap-2 finea-tour-actions");

      var closeBtn = createEl(
        "button",
        "px-4 py-2 text-sm font-extrabold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition finea-tour-btn",
      );
      closeBtn.type = "button";
      closeBtn.textContent = "Fermer";

      var prevBtn = createEl(
        "button",
        "px-4 py-2 text-sm font-extrabold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition disabled:opacity-50 disabled:cursor-not-allowed finea-tour-btn",
      );
      prevBtn.type = "button";
      prevBtn.textContent = "Précédent";

      var nextBtn = createEl(
        "button",
        "px-4 py-2 text-sm font-extrabold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition finea-tour-btn finea-tour-btn-primary",
      );
      nextBtn.type = "button";
      nextBtn.textContent = "Suivant";

      left.appendChild(closeBtn);
      right.appendChild(prevBtn);
      right.appendChild(nextBtn);
      footer.appendChild(left);
      footer.appendChild(right);

      popover.appendChild(header);
      popover.appendChild(body);
      popover.appendChild(footer);

      function onOverlayClick() {
        api.close(true);
      }

      function onKeyDown(ev) {
        if (!state.open) return;
        if (ev.key === "Escape") {
          ev.preventDefault();
          api.close(true);
          return;
        }
        if (ev.key === "ArrowRight") {
          ev.preventDefault();
          api.next();
          return;
        }
        if (ev.key === "ArrowLeft") {
          ev.preventDefault();
          api.prev();
          return;
        }
      }

      function onResizeOrScroll() {
        if (!state.open) return;
        positionPopover();
      }

      overlay.addEventListener("click", onOverlayClick);
      closeBtn.addEventListener("click", function () {
        api.close(true);
      });
      prevBtn.addEventListener("click", function () {
        api.prev();
      });
      nextBtn.addEventListener("click", function () {
        api.next();
      });

      document.addEventListener("keydown", onKeyDown);
      window.addEventListener("resize", onResizeOrScroll);
      window.addEventListener("scroll", onResizeOrScroll, true);

      state.overlay = overlay;
      state.popover = popover;
      state.titleEl = title;
      state.textEl = text;
      state.stepEl = step;
      state.prevBtn = prevBtn;
      state.nextBtn = nextBtn;
      state.closeBtn = closeBtn;
    }

    function positionPopover() {
      if (!state.popover) return;

      var step = steps[state.index] || null;
      var target = step && step.selector ? qs(step.selector) : null;

      var viewportW = window.innerWidth || document.documentElement.clientWidth;
      var viewportH =
        window.innerHeight || document.documentElement.clientHeight;

      // Default: centered
      var left = (viewportW - state.popover.offsetWidth) / 2;
      var top = (viewportH - state.popover.offsetHeight) / 2;

      if (target) {
        var r = target.getBoundingClientRect();
        var margin = 12;

        // Prefer below, else above
        var placeBelow =
          r.bottom + margin + state.popover.offsetHeight <= viewportH;
        var placeAbove = r.top - margin - state.popover.offsetHeight >= 0;

        var placement = step.placement;
        if (placement === "top") {
          placeBelow = false;
          placeAbove = true;
        } else if (placement === "bottom") {
          placeBelow = true;
          placeAbove = false;
        }

        if (placeBelow) {
          top = r.bottom + margin;
        } else if (placeAbove) {
          top = r.top - margin - state.popover.offsetHeight;
        } else {
          top = clamp(
            (viewportH - state.popover.offsetHeight) / 2,
            16,
            viewportH - state.popover.offsetHeight - 16,
          );
        }

        left = clamp(r.left, 16, viewportW - state.popover.offsetWidth - 16);
      }

      state.popover.style.left =
        Math.round(
          clamp(left, 12, viewportW - state.popover.offsetWidth - 12),
        ) + "px";
      state.popover.style.top =
        Math.round(
          clamp(top, 12, viewportH - state.popover.offsetHeight - 12),
        ) + "px";
    }

    function render() {
      if (!state.open) return;
      if (steps.length === 0) return;

      var s = steps[state.index];
      state.titleEl.textContent = s.title || "Aide";
      state.textEl.textContent = s.text || "";
      state.stepEl.textContent =
        "Étape " + (state.index + 1) + " / " + steps.length;

      state.prevBtn.disabled = state.index === 0;
      state.nextBtn.textContent =
        state.index === steps.length - 1 ? "Terminer" : "Suivant";

      applyHighlight(s.selector);

      // Wait one frame so popover has size
      window.requestAnimationFrame(function () {
        positionPopover();
      });
    }

    var api = {
      start: function (opts) {
        opts = opts || {};

        if (steps.length === 0) return;
        ensureUi();

        if (!state.open) {
          document.body.appendChild(state.overlay);
          document.body.appendChild(state.popover);
          state.open = true;
        }

        if (typeof opts.stepIndex === "number") {
          state.index = clamp(opts.stepIndex, 0, steps.length - 1);
        } else {
          state.index = 0;
        }

        render();
      },
      next: function () {
        if (!state.open) return;

        if (state.index >= steps.length - 1) {
          api.close(true);
          return;
        }

        state.index += 1;
        render();
      },
      prev: function () {
        if (!state.open) return;
        if (state.index <= 0) return;
        state.index -= 1;
        render();
      },
      close: function (markAsSeen) {
        if (!state.open) return;

        state.open = false;
        clearHighlight();

        try {
          if (state.overlay && state.overlay.parentNode)
            state.overlay.parentNode.removeChild(state.overlay);
          if (state.popover && state.popover.parentNode)
            state.popover.parentNode.removeChild(state.popover);
        } catch (e) {
          // ignore
        }

        if (markAsSeen) {
          markSeen(keys, version);
        }
      },
      bindHelpButton: function () {
        if (!helpButtonId) return;

        var primary = document.getElementById(helpButtonId);
        var buttons = [];

        if (primary) buttons.push(primary);
        if (!primary || !isElementVisible(primary)) {
          buttons.push(ensureFloatingHelpButton(helpButtonId));
        }

        buttons.forEach(function (btn) {
          if (!btn) return;
          if (btn.getAttribute("data-finea-help-bound") === "1") return;
          btn.setAttribute("data-finea-help-bound", "1");
          btn.addEventListener("click", function () {
            api.start();
          });
        });
      },
      autoStartIfNeeded: function () {
        if (!auto) return;
        if (!shouldAutoShow(keys, version)) return;

        // Delay to ensure layout is ready
        window.setTimeout(function () {
          api.start();
        }, 250);
      },
    };

    return api;
  }

  window.FineaHelpTour = {
    create: createHelpTour,
    _instances: {},
  };
})();
