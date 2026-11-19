// Menu burger mobile
// NOTE: La navbar (decaissement/includes/navbar.php) gère elle-même le burger.
// Ici on évite un double handler qui provoquait un "toggle" inversé (menu qui s'ouvre puis se referme).

// Utilitaires simples
window.currencyFormatCFA = function (n) {
  try {
    return (Number(n) || 0).toLocaleString("fr-FR") + " CFA";
  } catch (e) {
    return n + " CFA";
  }
};

// Helper: lire un paramètre d'URL
window.getQueryParam = function (name) {
  const url = new URL(window.location.href);
  return url.searchParams.get(name);
};

// Toast minimaliste (top-right)
window.showToast = function (message, type = "success") {
  let holder = document.getElementById("toast-holder");
  if (!holder) {
    holder = document.createElement("div");
    holder.id = "toast-holder";
    holder.style.position = "fixed";
    holder.style.top = "1rem";
    holder.style.right = "1rem";
    holder.style.zIndex = 9999;
    document.body.appendChild(holder);
  }
  const el = document.createElement("div");
  el.className =
    (type === "error"
      ? "bg-red-600"
      : type === "warn"
        ? "bg-amber-500"
        : "bg-indigo-600") +
    " text-white px-4 py-2 rounded-lg shadow mb-2 animate-fade-in";
  el.textContent = message;
  holder.appendChild(el);
  setTimeout(() => {
    el.style.transition = "opacity .3s ease";
    el.style.opacity = "0";
    setTimeout(() => el.remove(), 300);
  }, 2500);
};

(function () {
  var appConfig = window.BanamurAppConfig || {};
  var chatConfig = appConfig.employeeChat || null;
  var remoteAssistConfig = appConfig.remoteAssistance || null;
  if (!chatConfig || !chatConfig.enabled || !chatConfig.bootstrapUrl) {
    return;
  }

  var THREAD_PEER = "peer";
  var THREAD_GROUP = "group";
  var MAX_GROUP_NAME_LENGTH = 32;

  var state = {
    me: null,
    users: [],
    conversations: [],
    groups: [],
    openThreads: [],
    messagesByThread: {},
    typingByThread: {},
    unreadTotal: 0,
    lastIncomingMessageId: 0,
    pollTimer: null,
    pollInFlight: false,
    activePollIntervalMs: 15000,
    audioReady: false,
    rosterOpen: false,
    search: "",
    draftTextByThread: {},
    draftFilesByThread: {},
    typingTimeouts: {},
    groupCreatorOpen: false,
    groupCreatorName: "",
    groupCreatorMembers: [],
    membersViewerOpen: false,
    membersViewerGroupKey: "",
    groupMembersByGroup: {},
    callModalOpen: false,
    callThreadKey: "",
    callRoomName: "",
    callApi: null,
    callRemoteParticipants: 0,
    callScreenSharing: false,
    callScriptUrl: "",
  };

  function ensureStyles() {
    if (document.getElementById("employee-chat-styles")) {
      return;
    }
    var style = document.createElement("style");
    style.id = "employee-chat-styles";
    style.textContent =
      "" +
      ".echat-launcher{position:fixed;right:20px;bottom:18px;z-index:10020;display:flex;align-items:center;gap:10px;border:none;border-radius:999px;padding:13px 17px;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#fff;box-shadow:0 18px 45px rgba(15,23,42,.28);font:700 13px/1 ui-sans-serif,system-ui,sans-serif;cursor:pointer}" +
      ".echat-badge{display:inline-flex;min-width:20px;height:20px;align-items:center;justify-content:center;border-radius:999px;background:#f97316;color:#fff;font-size:11px;font-weight:800;padding:0 6px}" +
      ".echat-roster{position:fixed;right:20px;bottom:74px;width:360px;height:520px;max-height:calc(100vh - 110px);z-index:10019;background:#fff;border:1px solid rgba(148,163,184,.24);border-radius:26px;overflow:hidden;box-shadow:0 28px 70px rgba(15,23,42,.22);display:none;font-family:ui-sans-serif,system-ui,sans-serif}" +
      ".echat-roster.open{display:grid;grid-template-rows:auto auto 1fr}" +
      ".echat-roster-head{padding:14px 16px;background:linear-gradient(135deg,#eff6ff,#ecfeff);border-bottom:1px solid #dbeafe;display:flex;align-items:flex-start;justify-content:space-between;gap:10px}" +
      ".echat-roster-title{font-size:13px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#0f172a}" +
      ".echat-head-actions{display:flex;align-items:center;gap:8px}" +
      ".echat-head-btn{border:none;border-radius:999px;background:#dbeafe;color:#1d4ed8;padding:8px 10px;font-size:11px;font-weight:800;cursor:pointer}" +
      ".echat-roster-search{padding:10px 12px;border-bottom:1px solid #e2e8f0;background:#f8fafc}" +
      ".echat-roster-search input{width:100%;border:1px solid #cbd5e1;border-radius:14px;padding:10px 12px;font-size:12px}" +
      ".echat-roster-list{overflow:auto;background:#fff;padding-bottom:8px}" +
      ".echat-section{padding-top:8px}" +
      ".echat-section-title{padding:0 14px 8px;font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#64748b}" +
      ".echat-item{padding:12px 14px;border-bottom:1px solid #edf2f7;cursor:pointer}" +
      ".echat-item:hover{background:#eef2ff}" +
      ".echat-item-row{display:flex;align-items:center;gap:10px}" +
      ".echat-item-main{display:flex;align-items:center;gap:10px;min-width:0;flex:1}" +
      ".echat-item-text{min-width:0;flex:1}" +
      ".echat-item-name{font-size:12px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:6px}" +
      ".echat-pill{display:inline-flex;align-items:center;border-radius:999px;padding:2px 7px;font-size:10px;font-weight:800;background:#e2e8f0;color:#334155}" +
      ".echat-item-meta{font-size:11px;color:#64748b;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}" +
      ".echat-item-preview{margin-top:6px;font-size:11px;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}" +
      ".echat-avatar{width:38px;height:38px;border-radius:999px;background:linear-gradient(135deg,#1e3a8a,#0f766e);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;overflow:hidden;flex:0 0 auto}" +
      ".echat-avatar.sm{width:30px;height:30px;font-size:11px}" +
      ".echat-avatar img{width:100%;height:100%;object-fit:cover;display:block}" +
      ".echat-windows{position:fixed;right:20px;bottom:74px;z-index:10018;display:flex;flex-direction:row-reverse;align-items:flex-end;gap:12px;max-width:calc(100vw - 40px);pointer-events:none}" +
      ".echat-window{width:350px;height:460px;background:#fff;border:1px solid rgba(148,163,184,.24);border-radius:24px;overflow:hidden;box-shadow:0 24px 65px rgba(15,23,42,.22);display:grid;grid-template-rows:auto 1fr auto;pointer-events:auto;font-family:ui-sans-serif,system-ui,sans-serif}" +
      ".echat-window-head{padding:12px 14px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:10px}" +
      ".echat-window-head-main{display:flex;align-items:center;gap:10px;min-width:0;flex:1}" +
      ".echat-window-title{font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}" +
      ".echat-window-sub{font-size:10px;opacity:.82;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}" +
      ".echat-window-head-side{display:flex;align-items:center;gap:8px;flex:0 0 auto}" +
      ".echat-window-badge{display:inline-flex;align-items:center;justify-content:center;border:none;border-radius:999px;background:rgba(255,255,255,.16);color:#fff;padding:4px 8px;font-size:10px;font-weight:800;white-space:nowrap;cursor:pointer}" +
      ".echat-window-badge.call{background:rgba(217,70,239,.18);color:#f5d0fe}" +
      ".echat-icon-btn{border:none;background:transparent;color:inherit;cursor:pointer;font-size:16px;padding:0 2px;flex:0 0 auto}" +
      ".echat-messages{padding:12px;background:linear-gradient(180deg,#ffffff,#f8fafc);overflow:auto}" +
      ".echat-empty{padding:18px;text-align:center;color:#64748b;font-size:12px}" +
      ".echat-msg{display:flex;flex-direction:column;max-width:86%;margin-bottom:10px;padding:10px 12px;border-radius:18px;font-size:12px;line-height:1.5;white-space:pre-wrap;word-break:break-word}" +
      ".echat-msg.mine{margin-left:auto;background:#1d4ed8;color:#fff;border-bottom-right-radius:6px}" +
      ".echat-msg.other{margin-right:auto;background:#e2e8f0;color:#0f172a;border-bottom-left-radius:6px}" +
      ".echat-msg-author{font-size:11px;font-weight:800;margin-bottom:4px;opacity:.9}" +
      ".echat-msg-body a{color:inherit;text-decoration:underline;font-weight:700}" +
      ".echat-link-preview-list{display:flex;flex-direction:column;gap:8px;margin-top:8px}" +
      ".echat-link-card{display:block;border-radius:14px;overflow:hidden;background:rgba(255,255,255,.18);text-decoration:none;color:inherit}" +
      ".echat-msg.other .echat-link-card{background:#fff}" +
      ".echat-link-card iframe,.echat-link-card img,.echat-link-card video{display:block;width:100%;min-height:180px;border:none;background:#000;object-fit:cover}" +
      ".echat-link-card-body{padding:10px 12px}" +
      ".echat-link-card-title{font-size:11px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}" +
      ".echat-link-card-url{font-size:10px;opacity:.8;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}" +
      ".echat-msg-meta{margin-top:5px;font-size:10px;opacity:.82;display:flex;justify-content:space-between;gap:10px}" +
      ".echat-typing{min-height:18px;padding:0 14px 8px;font-size:11px;color:#64748b;background:#fff}" +
      ".echat-compose{padding:12px;border-top:1px solid #e2e8f0;background:#fff}" +
      ".echat-draft-files{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px}" +
      ".echat-file-chip{display:inline-flex;align-items:center;gap:6px;border-radius:999px;background:#e0f2fe;color:#0c4a6e;padding:5px 10px;font-size:11px;font-weight:700}" +
      ".echat-file-chip button{border:none;background:transparent;color:#0c4a6e;cursor:pointer;font-size:12px}" +
      ".echat-compose-row{display:flex;gap:8px;align-items:flex-end}" +
      ".echat-compose textarea{flex:1;resize:none;height:48px;max-height:100px;border:1px solid #cbd5e1;border-radius:16px;padding:11px 12px;font-size:12px}" +
      ".echat-send{border:none;border-radius:16px;background:#0f172a;color:#fff;padding:12px 14px;font-size:12px;font-weight:700;cursor:pointer}" +
      ".echat-send:disabled{opacity:.55;cursor:not-allowed}" +
      ".echat-attach{border:none;border-radius:16px;background:#eff6ff;color:#1d4ed8;padding:12px 13px;font-size:12px;font-weight:700;cursor:pointer}" +
      ".echat-attachment-list{display:flex;flex-direction:column;gap:6px;margin-top:8px}" +
      ".echat-attachment{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:7px 8px;border-radius:12px;background:rgba(255,255,255,.18);font-size:11px}" +
      ".echat-msg.other .echat-attachment{background:#fff}" +
      ".echat-attachment a{color:inherit;text-decoration:none;font-weight:700}" +
      ".echat-attachment small{display:block;opacity:.75}" +
      ".echat-attachment-preview{margin-top:8px;border-radius:12px;overflow:hidden;background:rgba(255,255,255,.18)}" +
      ".echat-msg.other .echat-attachment-preview{background:#fff}" +
      ".echat-attachment-preview img,.echat-attachment-preview video{display:block;width:100%;max-height:240px;object-fit:cover;background:#000}" +
      ".echat-preview{position:fixed;inset:0;z-index:10030;background:rgba(15,23,42,.64);display:none;align-items:center;justify-content:center;padding:20px}" +
      ".echat-preview.open{display:flex}" +
      ".echat-preview-card{width:min(900px,100%);height:min(78vh,760px);background:#fff;border-radius:24px;overflow:hidden;display:grid;grid-template-rows:auto 1fr}" +
      ".echat-preview-head{padding:14px 18px;background:#0f172a;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:10px}" +
      ".echat-preview-body{background:#f8fafc;display:flex;align-items:center;justify-content:center;padding:18px}" +
      ".echat-preview-body img,.echat-preview-body iframe,.echat-preview-body video{max-width:100%;max-height:100%;border:none;border-radius:14px;background:#fff}" +
      ".echat-preview-body audio{width:min(560px,100%)}" +
      ".echat-preview-fallback{padding:24px;text-align:center;color:#475569;font-size:14px}" +
      ".echat-modal{position:fixed;inset:0;z-index:10029;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;padding:20px}" +
      ".echat-modal.open{display:flex}" +
      ".echat-modal-card{width:min(520px,100%);max-height:min(86vh,780px);overflow:hidden;border-radius:24px;background:#fff;box-shadow:0 24px 65px rgba(15,23,42,.22);display:grid;grid-template-rows:auto auto 1fr auto}" +
      ".echat-modal-head{padding:16px 18px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:10px}" +
      ".echat-modal-body{padding:16px 18px;overflow:auto}" +
      ".echat-modal-field{margin-bottom:14px}" +
      ".echat-modal-field label{display:block;font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#475569;margin-bottom:8px}" +
      ".echat-modal-field input{width:100%;border:1px solid #cbd5e1;border-radius:14px;padding:11px 12px;font-size:13px}" +
      ".echat-member-list{display:flex;flex-direction:column;gap:8px}" +
      ".echat-member-item{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:16px;cursor:pointer}" +
      ".echat-member-item.active{border-color:#93c5fd;background:#eff6ff}" +
      ".echat-member-item input{width:16px;height:16px;flex:0 0 auto}" +
      ".echat-member-text{min-width:0;flex:1}" +
      ".echat-modal-actions{padding:14px 18px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;gap:10px;background:#fff}" +
      ".echat-btn{border:none;border-radius:14px;padding:10px 14px;font-size:12px;font-weight:800;cursor:pointer}" +
      ".echat-btn.secondary{background:#e2e8f0;color:#334155}" +
      ".echat-btn.primary{background:#1d4ed8;color:#fff}" +
      ".echat-group-name-counter{margin-top:6px;font-size:11px;color:#64748b;text-align:right}" +
      ".echat-call-modal{position:fixed;inset:0;z-index:10031;background:rgba(15,23,42,.64);display:none;align-items:center;justify-content:center;padding:20px}" +
      ".echat-call-modal.open{display:flex}" +
      ".echat-call-card{width:min(1120px,100%);height:min(86vh,860px);background:#fff;border-radius:28px;overflow:hidden;display:grid;grid-template-rows:auto auto 1fr;box-shadow:0 30px 90px rgba(15,23,42,.28)}" +
      ".echat-call-head{padding:16px 18px;background:linear-gradient(135deg,#0f172a,#1e3a8a);color:#fff;display:flex;align-items:center;justify-content:space-between;gap:12px}" +
      ".echat-call-head-main{min-width:0;flex:1}" +
      ".echat-call-title{font-size:15px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}" +
      ".echat-call-subtitle{font-size:11px;opacity:.85;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}" +
      ".echat-call-toolbar{padding:12px 18px;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between}" +
      ".echat-call-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center}" +
      ".echat-call-link{font-size:12px;color:#475569;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}" +
      ".echat-call-status{display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:8px 12px;background:#e2e8f0;color:#334155;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}" +
      ".echat-call-status-dot{width:10px;height:10px;border-radius:999px;background:#94a3b8;flex:0 0 auto}" +
      ".echat-call-stage{background:#020617;position:relative}" +
      ".echat-call-container{width:100%;height:100%;min-height:420px;display:flex;align-items:center;justify-content:center;color:#cbd5e1;padding:18px;text-align:center}" +
      ".echat-call-container iframe{width:100%;height:100%;min-height:420px;border:0;background:#020617}" +
      ".echat-call-placeholder{max-width:520px}" +
      ".echat-call-placeholder i{font-size:42px;color:#fff}" +
      "@media (max-width: 900px){.echat-windows{left:12px;right:12px;bottom:74px;flex-direction:column-reverse;align-items:stretch}.echat-window{width:auto;height:58vh}.echat-roster{left:12px;right:12px;width:auto;height:70vh}.echat-modal-card{width:100%}}";
    document.head.appendChild(style);
  }

  function formatDateTime(raw) {
    if (!raw) return "";
    var value = String(raw).replace(" ", "T");
    var date = new Date(value);
    if (Number.isNaN(date.getTime())) {
      return raw;
    }
    return date.toLocaleString("fr-FR", {
      hour: "2-digit",
      minute: "2-digit",
      day: "2-digit",
      month: "2-digit",
    });
  }

  function escapeHtml(value) {
    return String(value || "").replace(/[&<>"']/g, function (s) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
      }[s];
    });
  }

  function formatBytes(size) {
    var value = Number(size || 0);
    if (!value) return "";
    if (value >= 1024 * 1024) {
      return Math.round((value / (1024 * 1024)) * 10) / 10 + " Mo";
    }
    if (value >= 1024) {
      return Math.max(1, Math.round(value / 1024)) + " Ko";
    }
    return value + " o";
  }

  function getEffectiveSingleUploadLimit() {
    return Number(chatConfig.uploadMaxBytes || 0);
  }

  function getEffectivePostLimit() {
    return Number(chatConfig.postMaxBytes || 0);
  }

  function validateDraftFiles(files) {
    var nextFiles = Array.isArray(files) ? files.slice() : [];
    if (!nextFiles.length) {
      return nextFiles;
    }

    var uploadLimit = getEffectiveSingleUploadLimit();
    var postLimit = getEffectivePostLimit();
    var rejected = [];

    if (uploadLimit > 0) {
      nextFiles = nextFiles.filter(function (file) {
        var keep = Number(file.size || 0) <= uploadLimit;
        if (!keep) {
          rejected.push(
            String(file.name || "fichier") +
              " (" +
              formatBytes(file.size || 0) +
              ")",
          );
        }
        return keep;
      });
    }

    if (rejected.length) {
      window.showToast(
        "Fichier refusé: taille maximale serveur " +
          String(chatConfig.uploadMaxLabel || formatBytes(uploadLimit)) +
          ".",
        "error",
      );
    }

    var totalSize = nextFiles.reduce(function (sum, file) {
      return sum + Number(file.size || 0);
    }, 0);
    if (postLimit > 0 && totalSize > postLimit) {
      window.showToast(
        "Taille totale trop élevée pour l'envoi actuel: limite serveur " +
          String(chatConfig.postMaxLabel || formatBytes(postLimit)) +
          ".",
        "error",
      );
      return [];
    }

    return nextFiles;
  }

  function makeThreadKey(threadType, threadId) {
    return (
      String(threadType || THREAD_PEER) + ":" + String(Number(threadId || 0))
    );
  }

  function parseThreadKey(threadKey) {
    var raw = String(threadKey || "");
    var parts = raw.split(":");
    return {
      threadType: parts[0] === THREAD_GROUP ? THREAD_GROUP : THREAD_PEER,
      threadId: Number(parts[1] || 0),
    };
  }

  function isAbsoluteUrl(url) {
    return /^(https?:|data:|\/)/i.test(String(url || ""));
  }

  function resolveAssetUrl(path) {
    var raw = String(path || "").trim();
    if (!raw) {
      return "";
    }
    if (isAbsoluteUrl(raw)) {
      return raw;
    }
    var rootBase = String(chatConfig.rootBase || "");
    return rootBase + raw.replace(/^\/+/, "");
  }

  function getAttachmentUrl(id, scope) {
    return (
      chatConfig.downloadUrl +
      "?id=" +
      encodeURIComponent(String(id || 0)) +
      "&scope=" +
      encodeURIComponent(String(scope || THREAD_PEER))
    );
  }

  function isPreviewable(mime) {
    mime = String(mime || "").toLowerCase();
    return (
      mime.indexOf("image/") === 0 ||
      mime.indexOf("audio/") === 0 ||
      mime.indexOf("video/") === 0 ||
      mime === "application/pdf"
    );
  }

  function escapeRegExp(value) {
    return String(value || "").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  function extractUrls(text) {
    var matches = String(text || "").match(/https?:\/\/[^\s<]+/gi) || [];
    var seen = {};
    return matches
      .filter(function (url) {
        var normalized = url.replace(/[),.;!?]+$/, "");
        if (!normalized || seen[normalized]) {
          return false;
        }
        seen[normalized] = true;
        return true;
      })
      .map(function (url) {
        return url.replace(/[),.;!?]+$/, "");
      });
  }

  function isImageUrl(url) {
    return /\.(png|jpe?g|gif|webp|bmp|svg)(\?.*)?$/i.test(String(url || ""));
  }

  function isVideoUrl(url) {
    return /\.(mp4|webm|ogg|mov|m4v)(\?.*)?$/i.test(String(url || ""));
  }

  function getYouTubeEmbedUrl(url) {
    var match = String(url || "").match(
      /(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/shorts\/)([A-Za-z0-9_-]{6,})/i,
    );
    if (!match) {
      return "";
    }
    return "https://www.youtube.com/embed/" + encodeURIComponent(match[1]);
  }

  function getFacebookEmbedUrl(url) {
    if (!/(facebook\.com|fb\.watch)/i.test(String(url || ""))) {
      return "";
    }
    return (
      "https://www.facebook.com/plugins/video.php?href=" +
      encodeURIComponent(String(url || "")) +
      "&show_text=false&width=560"
    );
  }

  function renderMessageBody(body) {
    var text = String(body || "");
    var html = escapeHtml(text);
    var urls = extractUrls(text);

    urls.forEach(function (url) {
      var safeUrl = escapeHtml(url);
      var linkHtml =
        '<a href="' +
        safeUrl +
        '" target="_blank" rel="noopener">' +
        safeUrl +
        "</a>";
      html = html.replace(new RegExp(escapeRegExp(safeUrl), "g"), linkHtml);
    });

    html = html.replace(/\n/g, "<br>");

    if (!urls.length) {
      return '<div class="echat-msg-body">' + html + "</div>";
    }

    var previews = urls
      .map(function (url) {
        var embedUrl = getYouTubeEmbedUrl(url) || getFacebookEmbedUrl(url);
        if (embedUrl) {
          return (
            '<a class="echat-link-card" href="' +
            escapeHtml(url) +
            '" target="_blank" rel="noopener">' +
            '<iframe src="' +
            escapeHtml(embedUrl) +
            '" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>' +
            '<div class="echat-link-card-body"><div class="echat-link-card-title">Prévisualisation vidéo</div><div class="echat-link-card-url">' +
            escapeHtml(url) +
            "</div></div></a>"
          );
        }
        if (isImageUrl(url)) {
          return (
            '<a class="echat-link-card" href="' +
            escapeHtml(url) +
            '" target="_blank" rel="noopener">' +
            '<img src="' +
            escapeHtml(url) +
            '" loading="lazy" alt="Image liée">' +
            '<div class="echat-link-card-body"><div class="echat-link-card-title">Image liée</div><div class="echat-link-card-url">' +
            escapeHtml(url) +
            "</div></div></a>"
          );
        }
        if (isVideoUrl(url)) {
          return (
            '<a class="echat-link-card" href="' +
            escapeHtml(url) +
            '" target="_blank" rel="noopener">' +
            '<video src="' +
            escapeHtml(url) +
            '" preload="metadata" muted playsinline></video>' +
            '<div class="echat-link-card-body"><div class="echat-link-card-title">Vidéo liée</div><div class="echat-link-card-url">' +
            escapeHtml(url) +
            "</div></div></a>"
          );
        }
        return "";
      })
      .filter(Boolean)
      .join("");

    return (
      '<div class="echat-msg-body">' +
      html +
      "</div>" +
      (previews
        ? '<div class="echat-link-preview-list">' + previews + "</div>"
        : "")
    );
  }

  function ensureAudioUnlocked() {
    if (state.audioReady) return;
    state.audioReady = true;
  }

  function playNotificationSound() {
    if (!state.audioReady) return;
    try {
      var audioContext = new (
        window.AudioContext || window.webkitAudioContext
      )();
      var oscillator = audioContext.createOscillator();
      var gain = audioContext.createGain();
      oscillator.type = "sine";
      oscillator.frequency.setValueAtTime(820, audioContext.currentTime);
      oscillator.frequency.exponentialRampToValueAtTime(
        640,
        audioContext.currentTime + 0.16,
      );
      gain.gain.setValueAtTime(0.001, audioContext.currentTime);
      gain.gain.exponentialRampToValueAtTime(
        0.08,
        audioContext.currentTime + 0.02,
      );
      gain.gain.exponentialRampToValueAtTime(
        0.001,
        audioContext.currentTime + 0.2,
      );
      oscillator.connect(gain);
      gain.connect(audioContext.destination);
      oscillator.start();
      oscillator.stop(audioContext.currentTime + 0.2);
    } catch (e) {}
  }

  async function fetchJson(url, options) {
    var response = await fetch(
      url,
      Object.assign(
        {
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
        },
        options || {},
      ),
    );
    var data = await response.json();
    if (!response.ok || !data || !data.ok) {
      throw new Error((data && data.error) || "Erreur serveur");
    }
    return data;
  }

  function getUserById(userId) {
    userId = Number(userId || 0);
    return (
      state.users.find(function (user) {
        return Number(user.id || 0) === userId;
      }) || null
    );
  }

  function getGroupById(groupId) {
    groupId = Number(groupId || 0);
    return (
      state.groups.find(function (group) {
        return Number(group.group_id || group.thread_id || 0) === groupId;
      }) || null
    );
  }

  function getConversationByPeer(peerId) {
    peerId = Number(peerId || 0);
    return (
      state.conversations.find(function (conversation) {
        return (
          Number(conversation.peer_user_id || conversation.thread_id || 0) ===
          peerId
        );
      }) || null
    );
  }

  function getThreadDescriptor(threadKey) {
    var parsed = parseThreadKey(threadKey);
    if (parsed.threadType === THREAD_GROUP) {
      var group = getGroupById(parsed.threadId) || {};
      return {
        threadKey: makeThreadKey(THREAD_GROUP, parsed.threadId),
        threadType: THREAD_GROUP,
        threadId: parsed.threadId,
        display: group.display || "Groupe #" + parsed.threadId,
        initials: group.initials || "GR",
        photoUrl: resolveAssetUrl(group.photo_url || ""),
        subtitle:
          group.group_type === "service"
            ? group.service_label || "Groupe de service"
            : "Groupe personnalisé",
        mailPro: "",
        groupType: group.group_type || "custom",
        memberCount: Number(group.member_count || 0),
        unreadCount: Number(group.unread_count || 0),
        preview: group.last_message_body || "Ouvrir le groupe",
      };
    }

    var user = getUserById(parsed.threadId) || {};
    var conversation = getConversationByPeer(parsed.threadId) || {};
    return {
      threadKey: makeThreadKey(THREAD_PEER, parsed.threadId),
      threadType: THREAD_PEER,
      threadId: parsed.threadId,
      display:
        user.display ||
        conversation.display ||
        "Utilisateur #" + parsed.threadId,
      initials: user.initials || conversation.initials || "BA",
      photoUrl: resolveAssetUrl(user.photo_url || conversation.photo_url || ""),
      subtitle: user.mail_pro || conversation.mail_pro || "Discussion privée",
      mailPro: user.mail_pro || conversation.mail_pro || "",
      groupType: "",
      memberCount: 2,
      unreadCount: Number(conversation.unread_count || 0),
      preview: conversation.last_message_body || "Démarrer une discussion",
      serviceLabel: user.service_label || conversation.service_label || "",
    };
  }

  function isRemoteAssistEnabled() {
    return !!(
      remoteAssistConfig &&
      remoteAssistConfig.enabled &&
      remoteAssistConfig.domain
    );
  }

  function sanitizeJitsiDomain(value) {
    value = String(value || "meet.jit.si")
      .trim()
      .toLowerCase();
    value = value.replace(/^https?:\/\//, "").replace(/\/+$/, "");
    return value || "meet.jit.si";
  }

  function getCallRoomName(threadKey) {
    var parsed = parseThreadKey(threadKey);
    var prefix = String(
      (remoteAssistConfig && remoteAssistConfig.roomPrefix) || "FINAEA",
    )
      .trim()
      .toUpperCase()
      .replace(/[^A-Z0-9-]+/g, "-")
      .replace(/-+/g, "-")
      .replace(/^-|-$/g, "");
    if (parsed.threadType === THREAD_GROUP) {
      return (prefix || "FINAEA") + "-CHAT-GROUPE-" + String(parsed.threadId);
    }
    return (prefix || "FINAEA") + "-CHAT-" + String(parsed.threadId);
  }

  function getCallMeetingLink(roomName) {
    return (
      "https://" +
      sanitizeJitsiDomain(remoteAssistConfig && remoteAssistConfig.domain) +
      "/" +
      encodeURIComponent(String(roomName || ""))
    );
  }

  function truncateLabel(label, maxLength) {
    var raw = String(label || "").trim();
    var limit = Number(maxLength || 0);
    if (!limit || raw.length <= limit) {
      return raw;
    }
    return raw.slice(0, Math.max(0, limit - 1)).trim() + "…";
  }

  function setDraftText(threadKey, value) {
    state.draftTextByThread[String(threadKey)] = String(value || "");
  }

  function getDraftText(threadKey) {
    return state.draftTextByThread[String(threadKey)] || "";
  }

  function getDraftFiles(threadKey) {
    return state.draftFilesByThread[String(threadKey)] || [];
  }

  function setDraftFiles(threadKey, files) {
    state.draftFilesByThread[String(threadKey)] = validateDraftFiles(
      files || [],
    );
  }

  function avatarHtml(entity, extraClass) {
    var photoUrl = resolveAssetUrl(
      entity && entity.photoUrl
        ? entity.photoUrl
        : entity && entity.photo_url
          ? entity.photo_url
          : "",
    );
    var initials = String(
      entity && entity.initials ? entity.initials : "BA",
    ).slice(0, 2);
    return (
      '<span class="echat-avatar ' +
      escapeHtml(extraClass || "") +
      '">' +
      (photoUrl
        ? '<img src="' +
          escapeHtml(photoUrl) +
          '" alt="' +
          escapeHtml(entity && entity.display ? entity.display : initials) +
          '">'
        : escapeHtml(initials)) +
      "</span>"
    );
  }

  function createBaseUi() {
    ensureStyles();

    var launcher = document.createElement("button");
    launcher.type = "button";
    launcher.id = "echat-launcher";
    launcher.className = "echat-launcher";
    launcher.innerHTML =
      '<span>Discussion</span><span id="echat-launcher-badge" class="echat-badge" hidden>0</span>';

    var roster = document.createElement("div");
    roster.id = "echat-roster";
    roster.className = "echat-roster";
    roster.innerHTML =
      "" +
      '<div class="echat-roster-head">' +
      '  <div><div class="echat-roster-title">Messagerie</div><div style="font-size:11px;color:#475569;margin-top:3px">Disponible dans toute l\'application</div></div>' +
      '  <div class="echat-head-actions"><button type="button" id="echat-create-group" class="echat-head-btn">Nouveau groupe</button><button type="button" id="echat-roster-close" class="echat-icon-btn" style="color:#334155">×</button></div>' +
      "</div>" +
      '<div class="echat-roster-search"><input id="echat-search" type="text" placeholder="Rechercher un groupe ou un collaborateur" /></div>' +
      '<div id="echat-roster-list" class="echat-roster-list"></div>';

    var windows = document.createElement("div");
    windows.id = "echat-windows";
    windows.className = "echat-windows";

    var preview = document.createElement("div");
    preview.id = "echat-preview";
    preview.className = "echat-preview";
    preview.innerHTML =
      "" +
      '<div class="echat-preview-card">' +
      '  <div class="echat-preview-head">' +
      '    <div id="echat-preview-title" style="font-weight:700">Aperçu</div>' +
      '    <button type="button" id="echat-preview-close" class="echat-icon-btn">×</button>' +
      "  </div>" +
      '  <div id="echat-preview-body" class="echat-preview-body"></div>' +
      "</div>";

    var groupModal = document.createElement("div");
    groupModal.id = "echat-group-modal";
    groupModal.className = "echat-modal";
    groupModal.innerHTML =
      "" +
      '<div class="echat-modal-card">' +
      '  <div class="echat-modal-head">' +
      '    <div><div style="font-weight:800;font-size:14px">Créer un groupe</div><div style="font-size:11px;opacity:.85;margin-top:3px">Sélectionnez les participants du groupe personnalisé</div></div>' +
      '    <button type="button" id="echat-group-close" class="echat-icon-btn">×</button>' +
      "  </div>" +
      '  <div class="echat-modal-body">' +
      '    <div class="echat-modal-field"><label for="echat-group-name">Nom du groupe</label><input id="echat-group-name" type="text" maxlength="' +
      MAX_GROUP_NAME_LENGTH +
      '" placeholder="Ex: Equipe chantier Y4"><div id="echat-group-name-counter" class="echat-group-name-counter">0/' +
      MAX_GROUP_NAME_LENGTH +
      "</div></div>" +
      '    <div class="echat-modal-field"><label>Participants</label><div id="echat-group-members" class="echat-member-list"></div></div>' +
      "  </div>" +
      '  <div class="echat-modal-actions"><button type="button" id="echat-group-cancel" class="echat-btn secondary">Annuler</button><button type="button" id="echat-group-submit" class="echat-btn primary">Créer</button></div>' +
      "</div>";

    var membersModal = document.createElement("div");
    membersModal.id = "echat-members-modal";
    membersModal.className = "echat-modal";
    membersModal.innerHTML =
      "" +
      '<div class="echat-modal-card">' +
      '  <div class="echat-modal-head">' +
      '    <div><div id="echat-members-title" style="font-weight:800;font-size:14px">Membres du groupe</div><div id="echat-members-subtitle" style="font-size:11px;opacity:.85;margin-top:3px">Chargement...</div></div>' +
      '    <button type="button" id="echat-members-close" class="echat-icon-btn">×</button>' +
      "  </div>" +
      '  <div class="echat-modal-body"><div id="echat-members-list" class="echat-member-list"></div></div>' +
      '  <div class="echat-modal-actions"><button type="button" id="echat-members-cancel" class="echat-btn secondary">Fermer</button></div>' +
      "</div>";

    var callModal = document.createElement("div");
    callModal.id = "echat-call-modal";
    callModal.className = "echat-call-modal";
    callModal.innerHTML =
      "" +
      '<div class="echat-call-card">' +
      '  <div class="echat-call-head">' +
      '    <div class="echat-call-head-main"><div id="echat-call-title" class="echat-call-title">Appel vidéo</div><div id="echat-call-subtitle" class="echat-call-subtitle">Salle intégrée au chat, sans redirection</div></div>' +
      '    <button type="button" id="echat-call-close" class="echat-icon-btn">×</button>' +
      "  </div>" +
      '  <div class="echat-call-toolbar">' +
      '    <div class="echat-call-actions">' +
      '      <button type="button" id="echat-call-launch" class="echat-btn primary">Rejoindre l\'appel</button>' +
      '      <button type="button" id="echat-call-share-screen" class="echat-btn secondary">Partager l\'écran</button>' +
      '      <button type="button" id="echat-call-copy" class="echat-btn secondary">Copier le lien</button>' +
      "    </div>" +
      '    <div id="echat-call-status" class="echat-call-status"><span class="echat-call-status-dot"></span><span>En attente</span></div>' +
      '    <div id="echat-call-link" class="echat-call-link"></div>' +
      "  </div>" +
      '  <div class="echat-call-stage"><div id="echat-call-container" class="echat-call-container"><div class="echat-call-placeholder"><i class="fas fa-video"></i><div style="margin-top:16px;font-size:20px;font-weight:800;color:#fff">Visio prête dans le chat</div><div style="margin-top:10px;font-size:13px;line-height:1.7">La réunion s\'ouvre ici, sans quitter la page en cours. Le nom de salle est généré automatiquement depuis la conversation.</div></div></div></div>' +
      "</div>";

    document.body.appendChild(roster);
    document.body.appendChild(windows);
    document.body.appendChild(launcher);
    document.body.appendChild(preview);
    document.body.appendChild(groupModal);
    document.body.appendChild(membersModal);
    document.body.appendChild(callModal);

    return {
      launcher: launcher,
      launcherBadge: launcher.querySelector("#echat-launcher-badge"),
      roster: roster,
      rosterClose: roster.querySelector("#echat-roster-close"),
      rosterList: roster.querySelector("#echat-roster-list"),
      rosterCreateGroup: roster.querySelector("#echat-create-group"),
      search: roster.querySelector("#echat-search"),
      windows: windows,
      preview: preview,
      previewClose: preview.querySelector("#echat-preview-close"),
      previewBody: preview.querySelector("#echat-preview-body"),
      previewTitle: preview.querySelector("#echat-preview-title"),
      groupModal: groupModal,
      groupClose: groupModal.querySelector("#echat-group-close"),
      groupCancel: groupModal.querySelector("#echat-group-cancel"),
      groupSubmit: groupModal.querySelector("#echat-group-submit"),
      groupName: groupModal.querySelector("#echat-group-name"),
      groupNameCounter: groupModal.querySelector("#echat-group-name-counter"),
      groupMembers: groupModal.querySelector("#echat-group-members"),
      membersModal: membersModal,
      membersClose: membersModal.querySelector("#echat-members-close"),
      membersCancel: membersModal.querySelector("#echat-members-cancel"),
      membersTitle: membersModal.querySelector("#echat-members-title"),
      membersSubtitle: membersModal.querySelector("#echat-members-subtitle"),
      membersList: membersModal.querySelector("#echat-members-list"),
      callModal: callModal,
      callClose: callModal.querySelector("#echat-call-close"),
      callTitle: callModal.querySelector("#echat-call-title"),
      callSubtitle: callModal.querySelector("#echat-call-subtitle"),
      callLaunch: callModal.querySelector("#echat-call-launch"),
      callShareScreen: callModal.querySelector("#echat-call-share-screen"),
      callCopy: callModal.querySelector("#echat-call-copy"),
      callStatus: callModal.querySelector("#echat-call-status"),
      callLink: callModal.querySelector("#echat-call-link"),
      callContainer: callModal.querySelector("#echat-call-container"),
    };
  }

  var ui = createBaseUi();

  function updateLauncher() {
    var unread = Number(state.unreadTotal || 0);
    ui.launcherBadge.hidden = unread <= 0;
    ui.launcherBadge.textContent = unread > 99 ? "99+" : String(unread || 0);
  }

  function toggleRoster(forceOpen) {
    state.rosterOpen =
      typeof forceOpen === "boolean" ? forceOpen : !state.rosterOpen;
    ui.roster.classList.toggle("open", !!state.rosterOpen);
  }

  function openPreview(attachment) {
    if (!attachment) return;
    ui.preview.classList.add("open");
    ui.previewTitle.textContent = attachment.original_name || "Aperçu";
    var mime = String(attachment.mime_type || "").toLowerCase();
    var url = getAttachmentUrl(
      attachment.id,
      attachment.scope || attachment.attachment_scope || THREAD_PEER,
    );

    if (mime.indexOf("image/") === 0) {
      ui.previewBody.innerHTML =
        '<img src="' +
        escapeHtml(url) +
        '" alt="' +
        escapeHtml(attachment.original_name || "image") +
        '">';
    } else if (mime === "application/pdf") {
      ui.previewBody.innerHTML =
        '<iframe src="' + escapeHtml(url) + '"></iframe>';
    } else if (mime.indexOf("audio/") === 0) {
      ui.previewBody.innerHTML =
        '<audio controls autoplay src="' + escapeHtml(url) + '"></audio>';
    } else if (mime.indexOf("video/") === 0) {
      ui.previewBody.innerHTML =
        '<video controls autoplay src="' + escapeHtml(url) + '"></video>';
    } else {
      ui.previewBody.innerHTML =
        '<div class="echat-preview-fallback">Prévisualisation non disponible.<div style="margin-top:12px"><a href="' +
        escapeHtml(url) +
        '" target="_blank" rel="noopener" style="color:#1d4ed8;font-weight:700">Ouvrir le fichier</a></div></div>';
    }
  }

  function closePreview() {
    ui.preview.classList.remove("open");
    ui.previewBody.innerHTML = "";
  }

  function setCallStatus(label, color) {
    if (!ui.callStatus) {
      return;
    }
    ui.callStatus.innerHTML =
      '<span class="echat-call-status-dot" style="background:' +
      escapeHtml(color || "#94a3b8") +
      '"></span><span>' +
      escapeHtml(label || "En attente") +
      "</span>";
  }

  function updateCallUi(threadKey) {
    var descriptor = getThreadDescriptor(threadKey);
    var roomName = getCallRoomName(threadKey);
    state.callRoomName = roomName;
    if (ui.callTitle) {
      ui.callTitle.textContent =
        "Appel vidéo · " + String(descriptor.display || "Discussion");
    }
    if (ui.callSubtitle) {
      ui.callSubtitle.textContent =
        "Salle " + roomName + " ouverte dans la page actuelle";
    }
    if (ui.callLink) {
      ui.callLink.textContent = getCallMeetingLink(roomName);
      ui.callLink.title = getCallMeetingLink(roomName);
    }
  }

  function destroyCallApi() {
    if (state.callApi && typeof state.callApi.dispose === "function") {
      state.callApi.dispose();
    }
    state.callApi = null;
    state.callRemoteParticipants = 0;
    state.callScreenSharing = false;
  }

  function loadCallScript(scriptUrl) {
    return new Promise(function (resolve, reject) {
      if (window.JitsiMeetExternalAPI && state.callScriptUrl === scriptUrl) {
        resolve();
        return;
      }

      var existing = document.querySelector(
        'script[data-jitsi-chat="1"][src="' + scriptUrl + '"]',
      );
      if (existing) {
        existing.addEventListener(
          "load",
          function () {
            resolve();
          },
          { once: true },
        );
        existing.addEventListener(
          "error",
          function () {
            reject(new Error("Script Jitsi inaccessible."));
          },
          { once: true },
        );
        return;
      }

      var script = document.createElement("script");
      script.src = scriptUrl;
      script.async = true;
      script.dataset.jitsiChat = "1";
      script.onload = function () {
        state.callScriptUrl = scriptUrl;
        resolve();
      };
      script.onerror = function () {
        reject(new Error("Script Jitsi inaccessible."));
      };
      document.head.appendChild(script);
    });
  }

  function renderCallIframeFallback(meetingUrl) {
    destroyCallApi();
    if (ui.callContainer) {
      ui.callContainer.innerHTML =
        '<iframe src="' +
        escapeHtml(meetingUrl) +
        '#config.prejoinPageEnabled=false" allow="camera; microphone; fullscreen; display-capture; autoplay"></iframe>';
    }
    setCallStatus("Visio intégrée", "#f59e0b");
  }

  async function launchEmbeddedCall(threadKey, forceReload) {
    if (!isRemoteAssistEnabled() || !threadKey) {
      return;
    }

    var descriptor = getThreadDescriptor(threadKey);
    var roomName = getCallRoomName(threadKey);
    var meetingUrl = getCallMeetingLink(roomName);
    updateCallUi(threadKey);

    if (!ui.callContainer) {
      return;
    }

    if (!forceReload && state.callApi && state.callThreadKey === threadKey) {
      return;
    }

    setCallStatus("Connexion en cours", "#38bdf8");
    ui.callContainer.innerHTML =
      '<div class="echat-call-placeholder"><i class="fas fa-spinner fa-spin"></i><div style="margin-top:16px;font-size:20px;font-weight:800;color:#fff">Chargement de la visio</div><div style="margin-top:10px;font-size:13px;line-height:1.7">La salle ' +
      escapeHtml(roomName) +
      " est en préparation.</div></div>";

    try {
      await loadCallScript(
        String(
          (remoteAssistConfig && remoteAssistConfig.scriptUrl) ||
            "https://meet.jit.si/external_api.js",
        ),
      );
      destroyCallApi();
      ui.callContainer.innerHTML = "";
      state.callApi = new window.JitsiMeetExternalAPI(
        sanitizeJitsiDomain(remoteAssistConfig && remoteAssistConfig.domain),
        {
          roomName: roomName,
          parentNode: ui.callContainer,
          width: "100%",
          height: "100%",
          configOverwrite: {
            prejoinPageEnabled: false,
            toolbarButtons: [
              "microphone",
              "camera",
              "desktop",
              "fullscreen",
              "hangup",
              "chat",
              "participants-pane",
              "tileview",
              "settings",
              "raisehand",
              "videoquality",
              "filmstrip",
            ],
            startWithAudioMuted: !!remoteAssistConfig.startAudioMuted,
            startWithVideoMuted: !!remoteAssistConfig.startVideoMuted,
          },
          userInfo: {
            displayName: String(
              remoteAssistConfig.displayName ||
                descriptor.display ||
                "Utilisateur Finéa",
            ),
          },
        },
      );

      state.callApi.addEventListener("videoConferenceJoined", function () {
        state.callRemoteParticipants = 0;
        setCallStatus("Salle ouverte", "#10b981");
      });
      state.callApi.addEventListener("participantJoined", function () {
        state.callRemoteParticipants += 1;
        setCallStatus("Appel en cours", "#10b981");
      });
      state.callApi.addEventListener("participantLeft", function () {
        state.callRemoteParticipants = Math.max(
          0,
          state.callRemoteParticipants - 1,
        );
        if (state.callRemoteParticipants === 0 && !state.callScreenSharing) {
          setCallStatus("Seul dans la salle", "#f59e0b");
        }
      });
      state.callApi.addEventListener(
        "screenSharingStatusChanged",
        function (event) {
          state.callScreenSharing = !!(event && event.on);
          if (state.callScreenSharing) {
            setCallStatus("Partage d'écran actif", "#d946ef");
            return;
          }
          if (state.callRemoteParticipants > 0) {
            setCallStatus("Appel en cours", "#10b981");
            return;
          }
          setCallStatus("Salle ouverte", "#10b981");
        },
      );
      state.callApi.addEventListener("videoConferenceLeft", function () {
        setCallStatus("Appel terminé", "#94a3b8");
      });
      state.callApi.addEventListener("readyToClose", function () {
        setCallStatus("Appel terminé", "#94a3b8");
      });

      state.callApi.executeCommand(
        "displayName",
        String(
          remoteAssistConfig.displayName ||
            descriptor.display ||
            "Utilisateur Finéa",
        ),
      );
      state.callApi.executeCommand(
        "localSubject",
        "Appel vidéo · " + String(descriptor.display || roomName),
      );
    } catch (error) {
      renderCallIframeFallback(meetingUrl);
    }
  }

  async function openCallModal(threadKey) {
    if (!isRemoteAssistEnabled()) {
      window.showToast(
        "L'assistance à distance n'est pas activée pour votre profil.",
        "warn",
      );
      return;
    }
    state.callThreadKey = String(threadKey || "");
    state.callModalOpen = true;
    ui.callModal.classList.add("open");
    updateCallUi(state.callThreadKey);
    await launchEmbeddedCall(state.callThreadKey, true);
  }

  function closeCallModal() {
    state.callModalOpen = false;
    state.callThreadKey = "";
    destroyCallApi();
    if (ui.callContainer) {
      ui.callContainer.innerHTML =
        '<div class="echat-call-placeholder"><i class="fas fa-video"></i><div style="margin-top:16px;font-size:20px;font-weight:800;color:#fff">Visio prête dans le chat</div><div style="margin-top:10px;font-size:13px;line-height:1.7">La réunion s\'ouvre ici, sans quitter la page en cours. Le nom de salle est généré automatiquement depuis la conversation.</div></div>';
    }
    setCallStatus("En attente", "#94a3b8");
    ui.callModal.classList.remove("open");
  }

  async function shareCurrentCallScreen() {
    if (!state.callThreadKey) {
      return;
    }
    if (!state.callApi) {
      await launchEmbeddedCall(state.callThreadKey, false);
    }
    if (!state.callApi) {
      window.showToast("Impossible de lancer le partage d'écran.", "error");
      return;
    }
    try {
      state.callApi.executeCommand("toggleShareScreen");
    } catch (error) {
      window.showToast("Partage d'écran indisponible.", "error");
    }
  }

  async function copyCurrentCallLink() {
    if (!state.callRoomName) {
      return;
    }
    try {
      await navigator.clipboard.writeText(
        getCallMeetingLink(state.callRoomName),
      );
      window.showToast("Lien de l'appel copié.");
    } catch (error) {
      window.showToast("Copie manuelle requise.", "warn");
    }
  }

  function conversationSort(a, b) {
    return Number(b.last_message_id || 0) - Number(a.last_message_id || 0);
  }

  function renderRosterSection(title, items, emptyText) {
    if (!items.length) {
      return (
        '<div class="echat-section"><div class="echat-section-title">' +
        escapeHtml(title) +
        '</div><div class="echat-empty">' +
        escapeHtml(emptyText) +
        "</div></div>"
      );
    }

    return (
      '<div class="echat-section"><div class="echat-section-title">' +
      escapeHtml(title) +
      "</div>" +
      items.join("") +
      "</div>"
    );
  }

  function buildRosterItem(
    threadKey,
    descriptor,
    previewText,
    unreadCount,
    badgeLabel,
  ) {
    return (
      '<div class="echat-item" data-thread-key="' +
      escapeHtml(threadKey) +
      '"><div class="echat-item-row"><div class="echat-item-main">' +
      avatarHtml(descriptor, "sm") +
      '<div class="echat-item-text"><div class="echat-item-name">' +
      escapeHtml(descriptor.display) +
      (badgeLabel
        ? '<span class="echat-pill">' + escapeHtml(badgeLabel) + "</span>"
        : "") +
      '</div><div class="echat-item-meta">' +
      escapeHtml(descriptor.subtitle || descriptor.mailPro || "") +
      '</div><div class="echat-item-preview">' +
      escapeHtml(previewText || "Ouvrir la discussion") +
      "</div></div></div>" +
      (Number(unreadCount || 0) > 0
        ? '<span class="echat-badge">' +
          escapeHtml(String(unreadCount)) +
          "</span>"
        : "") +
      "</div></div>"
    );
  }

  function renderRoster() {
    var search = String(state.search || "")
      .trim()
      .toLowerCase();
    var knownPeerIds = {};
    var groupItems = [];
    var peerItems = [];
    var newPeerItems = [];

    state.groups
      .slice()
      .sort(conversationSort)
      .forEach(function (group) {
        var descriptor = getThreadDescriptor(
          makeThreadKey(THREAD_GROUP, group.group_id || group.thread_id),
        );
        var haystack = (
          String(descriptor.display || "") +
          " " +
          String(descriptor.subtitle || "") +
          " " +
          String(group.last_message_body || "")
        ).toLowerCase();
        if (search && haystack.indexOf(search) === -1) {
          return;
        }
        groupItems.push(
          buildRosterItem(
            descriptor.threadKey,
            descriptor,
            group.last_message_body || "Ouvrir le groupe",
            group.unread_count || 0,
            group.group_type === "service" ? "Service" : "Groupe",
          ),
        );
      });

    state.conversations
      .slice()
      .sort(conversationSort)
      .forEach(function (conversation) {
        var peerId = Number(
          conversation.peer_user_id || conversation.thread_id || 0,
        );
        knownPeerIds[String(peerId)] = true;
        var descriptor = getThreadDescriptor(
          makeThreadKey(THREAD_PEER, peerId),
        );
        var haystack = (
          String(descriptor.display || "") +
          " " +
          String(descriptor.subtitle || "") +
          " " +
          String(conversation.last_message_body || "")
        ).toLowerCase();
        if (search && haystack.indexOf(search) === -1) {
          return;
        }
        peerItems.push(
          buildRosterItem(
            descriptor.threadKey,
            descriptor,
            conversation.last_message_body || "Ouvrir la discussion",
            conversation.unread_count || 0,
            "Privé",
          ),
        );
      });

    state.users.forEach(function (user) {
      var peerId = Number(user.id || 0);
      if (knownPeerIds[String(peerId)]) {
        return;
      }
      var descriptor = getThreadDescriptor(makeThreadKey(THREAD_PEER, peerId));
      var haystack = (
        String(descriptor.display || "") +
        " " +
        String(descriptor.subtitle || "") +
        " " +
        String(user.service_label || "")
      ).toLowerCase();
      if (search && haystack.indexOf(search) === -1) {
        return;
      }
      newPeerItems.push(
        buildRosterItem(
          descriptor.threadKey,
          descriptor,
          "Démarrer une nouvelle discussion",
          0,
          descriptor.serviceLabel ? descriptor.serviceLabel : "Privé",
        ),
      );
    });

    ui.rosterList.innerHTML =
      renderRosterSection("Groupes", groupItems, "Aucun groupe trouvé.") +
      renderRosterSection(
        "Discussions",
        peerItems,
        "Aucune discussion active.",
      ) +
      renderRosterSection(
        "Collaborateurs",
        newPeerItems,
        "Aucun collaborateur trouvé.",
      );
  }

  function setTyping(threadKey, isTyping) {
    var parsed = parseThreadKey(threadKey);
    if (
      parsed.threadType !== THREAD_PEER ||
      !parsed.threadId ||
      !chatConfig.typingUrl
    ) {
      return;
    }
    fetch(chatConfig.typingUrl, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
      body: JSON.stringify({
        thread_type: THREAD_PEER,
        peer_user_id: parsed.threadId,
        is_typing: isTyping ? 1 : 0,
      }),
    }).catch(function () {});
  }

  function noteTyping(threadKey) {
    var parsed = parseThreadKey(threadKey);
    if (parsed.threadType !== THREAD_PEER || !parsed.threadId) {
      return;
    }
    setTyping(threadKey, true);
    if (state.typingTimeouts[String(threadKey)]) {
      clearTimeout(state.typingTimeouts[String(threadKey)]);
    }
    state.typingTimeouts[String(threadKey)] = setTimeout(function () {
      setTyping(threadKey, false);
    }, 2200);
  }

  function trimOpenThreads() {
    while (state.openThreads.length > 3) {
      state.openThreads.pop();
    }
  }

  function openThread(threadKey) {
    var parsed = parseThreadKey(threadKey);
    if (!parsed.threadId) {
      return;
    }
    threadKey = makeThreadKey(parsed.threadType, parsed.threadId);
    state.openThreads = state.openThreads.filter(function (openKey) {
      return openKey !== threadKey;
    });
    state.openThreads.unshift(threadKey);
    trimOpenThreads();
    toggleRoster(false);
    renderWindows();
    loadMessages(threadKey).catch(function (error) {
      window.showToast(error.message || "Chargement impossible.", "error");
    });
  }

  function closeThread(threadKey) {
    state.openThreads = state.openThreads.filter(function (openKey) {
      return openKey !== threadKey;
    });
    setTyping(threadKey, false);
    renderWindows();
  }

  function renderAttachmentList(attachments) {
    if (!attachments || !attachments.length) {
      return "";
    }
    return (
      '<div class="echat-attachment-list">' +
      attachments
        .map(function (attachment) {
          var scope =
            attachment.scope || attachment.attachment_scope || THREAD_PEER;
          var url = getAttachmentUrl(attachment.id, scope);
          var mime = String(attachment.mime_type || "").toLowerCase();
          var previewBtn = isPreviewable(attachment.mime_type)
            ? '<button type="button" class="echat-icon-btn" data-preview-attachment-id="' +
              attachment.id +
              '" data-preview-attachment-scope="' +
              escapeHtml(scope) +
              '" style="color:inherit">👁</button>'
            : "";
          var inlinePreview = "";
          if (mime.indexOf("image/") === 0) {
            inlinePreview =
              '<a class="echat-attachment-preview" href="' +
              escapeHtml(url) +
              '" target="_blank" rel="noopener"><img src="' +
              escapeHtml(url) +
              '" loading="lazy" alt="' +
              escapeHtml(attachment.original_name || "Image") +
              '"></a>';
          } else if (mime.indexOf("video/") === 0) {
            inlinePreview =
              '<div class="echat-attachment-preview"><video controls preload="metadata" src="' +
              escapeHtml(url) +
              '"></video></div>';
          }
          return (
            "<div>" +
            '<div class="echat-attachment">' +
            '<div style="min-width:0">' +
            '<a href="' +
            escapeHtml(url) +
            '" target="_blank" rel="noopener">' +
            escapeHtml(attachment.original_name || "Pièce jointe") +
            "</a>" +
            "<small>" +
            escapeHtml(formatBytes(attachment.file_size)) +
            "</small>" +
            "</div>" +
            '<div style="display:flex;align-items:center;gap:6px">' +
            previewBtn +
            '<a href="' +
            escapeHtml(url) +
            '" target="_blank" rel="noopener" style="color:inherit;font-weight:700">↗</a>' +
            "</div>" +
            "</div>" +
            inlinePreview +
            "</div>"
          );
        })
        .join("") +
      "</div>"
    );
  }

  function renderDraftFiles(threadKey) {
    var files = getDraftFiles(threadKey);
    if (!files.length) {
      return "";
    }
    return (
      '<div class="echat-draft-files">' +
      files
        .map(function (file, index) {
          return (
            '<span class="echat-file-chip">' +
            escapeHtml(file.name || "fichier") +
            '<button type="button" data-thread-key="' +
            escapeHtml(threadKey) +
            '" data-remove-file-index="' +
            index +
            '">×</button></span>'
          );
        })
        .join("") +
      "</div>"
    );
  }

  function renderMessagesForThread(threadKey) {
    var messages = state.messagesByThread[String(threadKey)] || [];
    if (!messages.length) {
      return '<div class="echat-empty">Aucun message pour le moment.</div>';
    }

    return messages
      .map(function (message) {
        var showAuthor =
          message.thread_type === THREAD_GROUP && !message.is_mine;
        return (
          '<div class="echat-msg ' +
          (message.is_mine ? "mine" : "other") +
          '">' +
          (showAuthor
            ? '<div class="echat-msg-author">' +
              escapeHtml(message.sender_display || "") +
              "</div>"
            : "") +
          renderMessageBody(message.body || "") +
          renderAttachmentList(message.attachments || []) +
          '<div class="echat-msg-meta"><span>' +
          escapeHtml(formatDateTime(message.created_at)) +
          "</span><span>" +
          escapeHtml(
            message.is_mine ? (message.is_read ? "Vu" : "Envoyé") : "",
          ) +
          "</span></div></div>"
        );
      })
      .join("");
  }

  function captureWindowState() {
    var context = {
      focus: null,
      scrollByThread: {},
    };

    var focusedInput = ui.windows.querySelector(
      "textarea[data-thread-key]:focus",
    );
    if (focusedInput) {
      context.focus = {
        threadKey: focusedInput.getAttribute("data-thread-key") || "",
        selectionStart: focusedInput.selectionStart || 0,
        selectionEnd: focusedInput.selectionEnd || 0,
      };
    }

    ui.windows
      .querySelectorAll("[data-messages-thread-key]")
      .forEach(function (container) {
        var threadKey =
          container.getAttribute("data-messages-thread-key") || "";
        context.scrollByThread[threadKey] = {
          scrollTop: container.scrollTop,
          stickToBottom:
            container.scrollHeight -
              container.scrollTop -
              container.clientHeight <
            36,
        };
      });

    return context;
  }

  function restoreWindowState(context) {
    Object.keys(context.scrollByThread || {}).forEach(function (threadKey) {
      var container = ui.windows.querySelector(
        '[data-messages-thread-key="' + threadKey + '"]',
      );
      if (!container) {
        return;
      }
      var meta = context.scrollByThread[threadKey];
      if (meta.stickToBottom) {
        container.scrollTop = container.scrollHeight;
      } else {
        container.scrollTop = meta.scrollTop;
      }
    });

    if (context.focus && context.focus.threadKey) {
      var input = ui.windows.querySelector(
        'textarea[data-thread-key="' + context.focus.threadKey + '"]',
      );
      if (input) {
        input.focus({ preventScroll: true });
        try {
          input.setSelectionRange(
            context.focus.selectionStart,
            context.focus.selectionEnd,
          );
        } catch (e) {}
      }
    }
  }

  function renderWindows() {
    var context = captureWindowState();

    if (!state.openThreads.length) {
      ui.windows.innerHTML = "";
      return;
    }

    ui.windows.innerHTML = state.openThreads
      .map(function (threadKey) {
        var descriptor = getThreadDescriptor(threadKey);
        var typing = !!state.typingByThread[String(threadKey)];
        return (
          '<section class="echat-window" data-thread-key="' +
          escapeHtml(threadKey) +
          '">' +
          '<div class="echat-window-head">' +
          '<div class="echat-window-head-main">' +
          avatarHtml(descriptor) +
          '<div style="min-width:0"><div class="echat-window-title">' +
          escapeHtml(
            descriptor.threadType === THREAD_GROUP
              ? truncateLabel(descriptor.display, MAX_GROUP_NAME_LENGTH)
              : descriptor.display,
          ) +
          '</div><div class="echat-window-sub">' +
          escapeHtml(
            descriptor.threadType === THREAD_GROUP
              ? descriptor.subtitle || "Groupe"
              : descriptor.subtitle || "Discussion privée",
          ) +
          '</div></div></div><div class="echat-window-head-side">' +
          (isRemoteAssistEnabled()
            ? '<button type="button" class="echat-window-badge call" data-call-thread-key="' +
              escapeHtml(threadKey) +
              '">Appel vidéo</button>'
            : "") +
          (descriptor.threadType === THREAD_GROUP && descriptor.memberCount
            ? '<button type="button" class="echat-window-badge" data-members-thread-key="' +
              escapeHtml(threadKey) +
              '">' +
              escapeHtml(String(descriptor.memberCount) + " membres") +
              "</button>"
            : "") +
          '<button type="button" class="echat-icon-btn" data-close-thread-key="' +
          escapeHtml(threadKey) +
          '">×</button></div>' +
          "</div>" +
          '<div class="echat-messages" data-messages-thread-key="' +
          escapeHtml(threadKey) +
          '">' +
          renderMessagesForThread(threadKey) +
          "</div>" +
          '<div><div class="echat-typing">' +
          (descriptor.threadType === THREAD_PEER && typing
            ? "Écrit un message…"
            : "&nbsp;") +
          '</div><div class="echat-compose">' +
          renderDraftFiles(threadKey) +
          '<div class="echat-compose-row">' +
          '<button type="button" class="echat-attach" data-attach-thread-key="' +
          escapeHtml(threadKey) +
          '">📎</button>' +
          '<input type="file" data-file-thread-key="' +
          escapeHtml(threadKey) +
          '" accept="image/*,video/*,audio/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.rar" multiple hidden>' +
          '<textarea data-thread-key="' +
          escapeHtml(threadKey) +
          '" placeholder="' +
          escapeHtml(
            descriptor.threadType === THREAD_GROUP
              ? "Votre message au groupe..."
              : "Votre message...",
          ) +
          '">' +
          escapeHtml(getDraftText(threadKey)) +
          "</textarea>" +
          '<button type="button" class="echat-send" data-send-thread-key="' +
          escapeHtml(threadKey) +
          '">Envoyer</button>' +
          "</div></div></div></section>"
        );
      })
      .join("");

    restoreWindowState(context);

    state.openThreads.forEach(function (threadKey) {
      if (!context.scrollByThread[threadKey]) {
        var container = ui.windows.querySelector(
          '[data-messages-thread-key="' + threadKey + '"]',
        );
        if (container) {
          container.scrollTop = container.scrollHeight;
        }
      }
    });
  }

  async function loadMessages(threadKey, options) {
    options = options || {};
    var parsed = parseThreadKey(threadKey);
    if (!parsed.threadId) {
      return;
    }

    var query =
      chatConfig.messagesUrl +
      "?thread_type=" +
      encodeURIComponent(parsed.threadType) +
      "&thread_id=" +
      encodeURIComponent(String(parsed.threadId)) +
      (parsed.threadType === THREAD_GROUP
        ? "&group_id=" + encodeURIComponent(String(parsed.threadId))
        : "&peer_user_id=" + encodeURIComponent(String(parsed.threadId))) +
      "&limit=80";

    var data = await fetchJson(query);
    state.messagesByThread[String(threadKey)] = Array.isArray(data.messages)
      ? data.messages
      : [];
    state.unreadTotal = Number(data.unread_total || state.unreadTotal || 0);

    if (parsed.threadType === THREAD_PEER) {
      state.typingByThread[String(threadKey)] = false;
      if (data.typing_by_peer && typeof data.typing_by_peer === "object") {
        state.typingByThread[String(threadKey)] =
          !!data.typing_by_peer[String(parsed.threadId)];
      }
      state.conversations = state.conversations.map(function (conversation) {
        if (Number(conversation.peer_user_id || 0) === parsed.threadId) {
          return Object.assign({}, conversation, { unread_count: 0 });
        }
        return conversation;
      });
    } else {
      state.groups = state.groups.map(function (group) {
        if (
          Number(group.group_id || group.thread_id || 0) === parsed.threadId
        ) {
          return Object.assign({}, group, { unread_count: 0 });
        }
        return group;
      });
    }

    if (!options.skipRender) {
      updateLauncher();
      renderRoster();
      renderWindows();
    }
  }

  async function sendMessage(threadKey) {
    var parsed = parseThreadKey(threadKey);
    if (!parsed.threadId) {
      return;
    }

    var body = String(getDraftText(threadKey) || "").trim();
    var files = getDraftFiles(threadKey);
    if (!body && !files.length) {
      window.showToast(
        "Aucun fichier exploitable à envoyer. Vérifiez la taille autorisée par le serveur.",
        "warn",
      );
      return;
    }

    var formData = new FormData();
    formData.set("thread_type", parsed.threadType);
    formData.set("thread_id", String(parsed.threadId));
    formData.set("body", body);
    if (parsed.threadType === THREAD_GROUP) {
      formData.set("group_id", String(parsed.threadId));
    } else {
      formData.set("peer_user_id", String(parsed.threadId));
    }
    files.forEach(function (file) {
      formData.append("attachments[]", file);
    });

    try {
      var response = await fetch(chatConfig.sendUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: formData,
      });
      var raw = await response.text();
      var data = null;
      try {
        data = raw ? JSON.parse(raw) : null;
      } catch (parseError) {
        throw new Error(
          raw || "Le serveur a retourne une reponse invalide pendant l'envoi.",
        );
      }
      if (!response.ok || !data || !data.ok) {
        throw new Error((data && data.error) || "Envoi impossible.");
      }

      setDraftText(threadKey, "");
      setDraftFiles(threadKey, []);
      setTyping(threadKey, false);
      if (!state.messagesByThread[String(threadKey)]) {
        state.messagesByThread[String(threadKey)] = [];
      }
      if (data.message) {
        state.messagesByThread[String(threadKey)].push(data.message);
      }
      renderWindows();
      await loadState(true);
    } catch (error) {
      window.showToast(error.message || "Envoi impossible.", "error");
    }
  }

  function renderGroupMembers() {
    var members = state.users
      .slice()
      .sort(function (a, b) {
        return String(a.display || "").localeCompare(
          String(b.display || ""),
          "fr",
          { sensitivity: "base" },
        );
      })
      .map(function (user) {
        var userId = Number(user.id || 0);
        var active = state.groupCreatorMembers.indexOf(userId) !== -1;
        return (
          '<label class="echat-member-item ' +
          (active ? "active" : "") +
          '" data-member-id="' +
          userId +
          '">' +
          '<input type="checkbox" ' +
          (active ? "checked" : "") +
          ' data-member-checkbox-id="' +
          userId +
          '">' +
          avatarHtml(
            {
              display: user.display,
              initials: user.initials,
              photoUrl: user.photo_url,
            },
            "sm",
          ) +
          '<span class="echat-member-text"><span class="echat-item-name">' +
          escapeHtml(user.display || "Utilisateur #" + userId) +
          '</span><span class="echat-item-meta">' +
          escapeHtml(user.mail_pro || user.service_label || "") +
          "</span></span></label>"
        );
      });

    ui.groupMembers.innerHTML = members.length
      ? members.join("")
      : '<div class="echat-empty">Aucun collaborateur disponible.</div>';
    ui.groupName.value = state.groupCreatorName;
    if (ui.groupNameCounter) {
      ui.groupNameCounter.textContent =
        String(state.groupCreatorName.length) +
        "/" +
        String(MAX_GROUP_NAME_LENGTH);
    }
    ui.groupModal.classList.toggle("open", !!state.groupCreatorOpen);
  }

  function renderMembersViewer() {
    var threadKey = state.membersViewerGroupKey;
    var descriptor = getThreadDescriptor(threadKey);
    var members = state.groupMembersByGroup[threadKey] || [];
    ui.membersTitle.textContent = descriptor.display || "Membres du groupe";
    ui.membersSubtitle.textContent =
      String(members.length || descriptor.memberCount || 0) + " membre(s)";
    ui.membersList.innerHTML = members.length
      ? members
          .map(function (member) {
            return (
              '<div class="echat-member-item active">' +
              avatarHtml(
                {
                  display: member.display,
                  initials: member.initials,
                  photoUrl: member.photo_url,
                },
                "sm",
              ) +
              '<span class="echat-member-text"><span class="echat-item-name">' +
              escapeHtml(member.display || "Utilisateur") +
              '</span><span class="echat-item-meta">' +
              escapeHtml(member.mail_pro || member.service_label || "") +
              "</span></span></div>"
            );
          })
          .join("")
      : '<div class="echat-empty">Aucun membre trouvé.</div>';
    ui.membersModal.classList.toggle("open", !!state.membersViewerOpen);
  }

  function openGroupCreator() {
    state.groupCreatorOpen = true;
    state.groupCreatorName = "";
    state.groupCreatorMembers = [];
    renderGroupMembers();
    window.setTimeout(function () {
      if (ui.groupName) {
        ui.groupName.focus();
      }
    }, 0);
  }

  function closeGroupCreator() {
    state.groupCreatorOpen = false;
    ui.groupModal.classList.remove("open");
  }

  async function openMembersViewer(threadKey) {
    var parsed = parseThreadKey(threadKey);
    if (
      parsed.threadType !== THREAD_GROUP ||
      !parsed.threadId ||
      !chatConfig.groupMembersUrl
    ) {
      return;
    }

    state.membersViewerOpen = true;
    state.membersViewerGroupKey = threadKey;
    ui.membersTitle.textContent =
      getThreadDescriptor(threadKey).display || "Membres du groupe";
    ui.membersSubtitle.textContent = "Chargement...";
    ui.membersList.innerHTML =
      '<div class="echat-empty">Chargement des membres...</div>';
    ui.membersModal.classList.add("open");

    try {
      var data = await fetchJson(
        chatConfig.groupMembersUrl +
          "?group_id=" +
          encodeURIComponent(String(parsed.threadId)),
      );
      state.groupMembersByGroup[threadKey] = Array.isArray(data.members)
        ? data.members
        : [];
      renderMembersViewer();
    } catch (error) {
      ui.membersSubtitle.textContent = "Erreur de chargement";
      ui.membersList.innerHTML =
        '<div class="echat-empty">' +
        escapeHtml(error.message || "Impossible de charger les membres.") +
        "</div>";
    }
  }

  function closeMembersViewer() {
    state.membersViewerOpen = false;
    state.membersViewerGroupKey = "";
    ui.membersModal.classList.remove("open");
  }

  async function createGroup() {
    if (!chatConfig.createGroupUrl) {
      return;
    }

    var memberIds = state.groupCreatorMembers.slice();
    if (!memberIds.length) {
      window.showToast("Sélectionnez au moins un collaborateur.", "warn");
      return;
    }

    state.groupCreatorName = String(ui.groupName.value || "").trim();
    if (!state.groupCreatorName) {
      window.showToast("Le nom du groupe est obligatoire.", "warn");
      ui.groupName.focus();
      return;
    }

    try {
      var data = await fetchJson(chatConfig.createGroupUrl, {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: JSON.stringify({
          name: state.groupCreatorName,
          member_ids: memberIds,
        }),
      });
      closeGroupCreator();
      await loadState(true);
      if (data.group && Number(data.group.group_id || 0) > 0) {
        openThread(makeThreadKey(THREAD_GROUP, data.group.group_id));
      }
      window.showToast("Groupe créé avec succès.");
    } catch (error) {
      window.showToast(
        error.message || "Création du groupe impossible.",
        "error",
      );
    }
  }

  async function loadState(silent) {
    var previousTop = Number(state.lastIncomingMessageId || 0);
    var openPeerIds = state.openThreads
      .map(function (threadKey) {
        var parsed = parseThreadKey(threadKey);
        return parsed.threadType === THREAD_PEER ? parsed.threadId : 0;
      })
      .filter(function (value) {
        return value > 0;
      });

    var query = openPeerIds.length
      ? "?open_peer_ids=" + encodeURIComponent(openPeerIds.join(","))
      : "";
    var data = await fetchJson(chatConfig.bootstrapUrl + query);
    state.me = data.me || null;
    state.users = Array.isArray(data.state && data.state.users)
      ? data.state.users
      : [];
    state.conversations = Array.isArray(data.state && data.state.conversations)
      ? data.state.conversations
      : [];
    state.groups = Array.isArray(data.state && data.state.groups)
      ? data.state.groups
      : [];
    state.unreadTotal = Number((data.state && data.state.unread_total) || 0);
    state.typingByThread = {};
    if (
      data.state &&
      data.state.typing_by_peer &&
      typeof data.state.typing_by_peer === "object"
    ) {
      Object.keys(data.state.typing_by_peer).forEach(function (key) {
        state.typingByThread[makeThreadKey(THREAD_PEER, key)] =
          !!data.state.typing_by_peer[key];
      });
    }

    var newestIncoming = 0;
    state.conversations.forEach(function (conversation) {
      if (
        Number(conversation.last_sender_user_id || 0) !==
        Number((state.me && state.me.id) || 0)
      ) {
        newestIncoming = Math.max(
          newestIncoming,
          Number(conversation.last_message_id || 0),
        );
      }
    });
    state.groups.forEach(function (group) {
      if (
        Number(group.last_sender_user_id || 0) !==
        Number((state.me && state.me.id) || 0)
      ) {
        newestIncoming = Math.max(
          newestIncoming,
          Number(group.last_message_id || 0),
        );
      }
    });

    if (!silent && previousTop > 0 && newestIncoming > previousTop) {
      playNotificationSound();
    }
    state.lastIncomingMessageId = Math.max(previousTop, newestIncoming);

    updateLauncher();
    renderRoster();

    for (var index = 0; index < state.openThreads.length; index += 1) {
      await loadMessages(state.openThreads[index], { skipRender: true });
    }
    renderWindows();
    renderGroupMembers();

    state.activePollIntervalMs = Math.max(
      15000,
      Number(data.poll_interval_ms || 15000),
    );
  }

  function nextPollDelay() {
    return state.openThreads.length || state.rosterOpen
      ? state.activePollIntervalMs
      : 60000;
  }

  function scheduleChatPoll(delay) {
    if (state.pollTimer) {
      window.clearTimeout(state.pollTimer);
      state.pollTimer = null;
    }
    if (document.hidden) return;
    state.pollTimer = window.setTimeout(runChatPoll, delay);
  }

  async function runChatPoll() {
    if (document.hidden || state.pollInFlight) return;
    state.pollInFlight = true;
    try {
      await loadState(false);
    } catch (error) {
      // Le prochain cycle réessaiera sans multiplier les requêtes.
    } finally {
      state.pollInFlight = false;
      scheduleChatPoll(nextPollDelay());
    }
  }

  document.addEventListener("visibilitychange", function () {
    if (document.hidden) {
      if (state.pollTimer) window.clearTimeout(state.pollTimer);
      state.pollTimer = null;
      return;
    }
    scheduleChatPoll(0);
  });

  ui.launcher.addEventListener("click", function () {
    ensureAudioUnlocked();
    toggleRoster();
  });
  ui.rosterClose.addEventListener("click", function () {
    toggleRoster(false);
  });
  ui.rosterCreateGroup.addEventListener("click", function () {
    openGroupCreator();
  });
  ui.search.addEventListener("input", function () {
    state.search = ui.search.value || "";
    renderRoster();
  });
  ui.rosterList.addEventListener("click", function (event) {
    var item = event.target.closest(".echat-item");
    if (!item) return;
    openThread(item.getAttribute("data-thread-key"));
  });
  ui.windows.addEventListener("click", function (event) {
    var closeBtn = event.target.closest("[data-close-thread-key]");
    if (closeBtn) {
      closeThread(closeBtn.getAttribute("data-close-thread-key"));
      return;
    }

    var membersBtn = event.target.closest("[data-members-thread-key]");
    if (membersBtn) {
      openMembersViewer(
        membersBtn.getAttribute("data-members-thread-key") || "",
      );
      return;
    }

    var callBtn = event.target.closest("[data-call-thread-key]");
    if (callBtn) {
      openCallModal(callBtn.getAttribute("data-call-thread-key") || "");
      return;
    }

    var sendBtn = event.target.closest("[data-send-thread-key]");
    if (sendBtn) {
      sendMessage(sendBtn.getAttribute("data-send-thread-key"));
      return;
    }

    var attachBtn = event.target.closest("[data-attach-thread-key]");
    if (attachBtn) {
      var threadKey = attachBtn.getAttribute("data-attach-thread-key");
      var input = ui.windows.querySelector(
        '[data-file-thread-key="' + threadKey + '"]',
      );
      if (input) input.click();
      return;
    }

    var previewBtn = event.target.closest("[data-preview-attachment-id]");
    if (previewBtn) {
      var attachmentId = Number(
        previewBtn.getAttribute("data-preview-attachment-id") || 0,
      );
      var attachmentScope = String(
        previewBtn.getAttribute("data-preview-attachment-scope") || THREAD_PEER,
      );
      var found = null;
      Object.keys(state.messagesByThread).some(function (threadKey) {
        return (state.messagesByThread[threadKey] || []).some(
          function (message) {
            return (message.attachments || []).some(function (attachment) {
              if (
                Number(attachment.id || 0) === attachmentId &&
                String(
                  attachment.scope ||
                    attachment.attachment_scope ||
                    THREAD_PEER,
                ) === attachmentScope
              ) {
                found = attachment;
                return true;
              }
              return false;
            });
          },
        );
      });
      if (found) {
        openPreview(found);
      }
      return;
    }

    var removeFileBtn = event.target.closest("[data-remove-file-index]");
    if (removeFileBtn) {
      var threadKeyToEdit = removeFileBtn.getAttribute("data-thread-key") || "";
      var files = getDraftFiles(threadKeyToEdit).slice();
      files.splice(
        Number(removeFileBtn.getAttribute("data-remove-file-index") || -1),
        1,
      );
      setDraftFiles(threadKeyToEdit, files);
      renderWindows();
    }
  });
  ui.windows.addEventListener("change", function (event) {
    var input = event.target.closest("[data-file-thread-key]");
    if (!input) return;
    var threadKey = input.getAttribute("data-file-thread-key") || "";
    var files = Array.prototype.slice.call(input.files || []);
    setDraftFiles(threadKey, files);
    input.value = "";
    renderWindows();
    var acceptedFiles = getDraftFiles(threadKey);
    if (acceptedFiles.length) {
      window.showToast(
        acceptedFiles.length === 1
          ? "1 fichier prêt à être envoyé."
          : String(acceptedFiles.length) + " fichiers prêts à être envoyés.",
        "success",
      );
    }
  });
  ui.windows.addEventListener("input", function (event) {
    var input = event.target.closest("textarea[data-thread-key]");
    if (!input) return;
    var threadKey = input.getAttribute("data-thread-key") || "";
    setDraftText(threadKey, input.value || "");
    noteTyping(threadKey);
  });
  ui.windows.addEventListener("keydown", function (event) {
    var input = event.target.closest("textarea[data-thread-key]");
    if (!input) return;
    if (event.key === "Enter" && !event.shiftKey) {
      event.preventDefault();
      sendMessage(input.getAttribute("data-thread-key") || "");
    }
  });
  ui.preview.addEventListener("click", function (event) {
    if (event.target === ui.preview) {
      closePreview();
    }
  });
  ui.previewClose.addEventListener("click", closePreview);
  ui.groupClose.addEventListener("click", closeGroupCreator);
  ui.groupCancel.addEventListener("click", closeGroupCreator);
  ui.groupSubmit.addEventListener("click", createGroup);
  ui.groupName.addEventListener("input", function () {
    state.groupCreatorName = String(ui.groupName.value || "").slice(
      0,
      MAX_GROUP_NAME_LENGTH,
    );
    ui.groupName.value = state.groupCreatorName;
    if (ui.groupNameCounter) {
      ui.groupNameCounter.textContent =
        String(state.groupCreatorName.length) +
        "/" +
        String(MAX_GROUP_NAME_LENGTH);
    }
  });
  ui.groupMembers.addEventListener("click", function (event) {
    var item = event.target.closest("[data-member-id]");
    if (!item) return;
    var memberId = Number(item.getAttribute("data-member-id") || 0);
    if (!memberId) return;
    var index = state.groupCreatorMembers.indexOf(memberId);
    if (index === -1) {
      state.groupCreatorMembers.push(memberId);
    } else {
      state.groupCreatorMembers.splice(index, 1);
    }
    renderGroupMembers();
  });
  ui.groupModal.addEventListener("click", function (event) {
    if (event.target === ui.groupModal) {
      closeGroupCreator();
    }
  });
  ui.membersClose.addEventListener("click", closeMembersViewer);
  ui.membersCancel.addEventListener("click", closeMembersViewer);
  ui.membersModal.addEventListener("click", function (event) {
    if (event.target === ui.membersModal) {
      closeMembersViewer();
    }
  });
  ui.callClose.addEventListener("click", closeCallModal);
  ui.callLaunch.addEventListener("click", function () {
    if (state.callThreadKey) {
      launchEmbeddedCall(state.callThreadKey, true);
    }
  });
  ui.callShareScreen.addEventListener("click", function () {
    shareCurrentCallScreen();
  });
  ui.callCopy.addEventListener("click", function () {
    copyCurrentCallLink();
  });
  ui.callModal.addEventListener("click", function (event) {
    if (event.target === ui.callModal) {
      closeCallModal();
    }
  });
  window.addEventListener("pointerdown", ensureAudioUnlocked, { once: true });
  window.addEventListener("keydown", ensureAudioUnlocked, { once: true });

  loadState(true)
    .catch(function () {
      toggleRoster(false);
      window.showToast(
        "La messagerie instantanée est momentanément indisponible.",
        "warn",
      );
    })
    .finally(function () {
      scheduleChatPoll(nextPollDelay());
    });
})();
