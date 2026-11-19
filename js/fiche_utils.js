/**
 * Utilitaires de génération des liens de visualisation de fiche
 * Centralisé pour assurer cohérence et faciliter évolution future (ex: tracking, permissions front, etc.).
 */
(function (global) {
  function ficheViewUrl(num) {
    if (!num && num !== 0) return "#";
    return "voir_fiche_details.php?num_fiche=" + encodeURIComponent(num);
  }
  function ficheViewLink(num, options) {
    options = options || {};
    var classes =
      options.classes ||
      "inline-flex items-center justify-center w-8 h-8 rounded-full bg-white border border-indigo-200 text-indigo-600 hover:bg-indigo-50 transition";
    var title = options.title || "Voir la fiche";
    var newTab = options.newTab !== false; // true par défaut
    var icon = options.icon || "eye";
    var iconSvg = "";
    switch (icon) {
      case "eye":
      default:
        iconSvg =
          '<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
        break;
    }
    return (
      '<a href="' +
      ficheViewUrl(num) +
      '"' +
      (newTab ? ' target="_blank"' : "") +
      ' class="' +
      classes +
      '" title="' +
      title +
      '">' +
      iconSvg +
      "</a>"
    );
  }
  // Expose
  global.ficheViewUrl = ficheViewUrl;
  global.ficheViewLink = ficheViewLink;
})(window);
