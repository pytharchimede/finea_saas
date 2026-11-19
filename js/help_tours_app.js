/*
  Finéa Help Tours - Application bootstrap
  - Chargé globalement via menu.php
  - Déclenche un mini-tour "global" 1 seule fois (versionné)
*/

(function () {
  "use strict";

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
      return rect && rect.width > 0 && rect.height > 0;
    } catch (e) {
      return false;
    }
  }

  function onReady(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    if (
      !window.FineaHelpTour ||
      typeof window.FineaHelpTour.create !== "function"
    ) {
      return;
    }

    var helpBtn = document.getElementById("helpBtn");
    var helpSelector =
      helpBtn && isElementVisible(helpBtn) ? "#helpBtn" : "#helpBtnFloating";

    var steps = [
      {
        title: "Bienvenue",
        text: "Ce guide rapide vous montre où naviguer et où retrouver l’aide.",
        selector: "#appMenu",
        placement: "bottom",
      },
      {
        title: "Aide",
        text: "Cliquez ici à tout moment pour relancer le guide.",
        selector: helpSelector,
        placement: "bottom",
      },
    ];

    var tour = window.FineaHelpTour.create({
      key: "app-global",
      version: "2",
      helpButtonId: "helpBtn",
      steps: steps,
    });

    tour.bindHelpButton();
    tour.autoStartIfNeeded();
  });
})();
