(function () {
  "use strict";

  const els = {
    threads: document.getElementById("comm-threads"),
    messages: document.getElementById("comm-messages"),
    header: document.getElementById("comm-header"),
    chantierSelect: document.getElementById("comm-chantier"), // peut être absent (UX WhatsApp)
    recipientsSelect: document.getElementById("comm-recipients"), // fallback si l'ancien select existe encore
    attachmentsInput: document.getElementById("comm-attachments"),
    attachBtn: document.getElementById("comm-attach-btn"),
    cameraPhotoInput: document.getElementById("comm-camera-photo"),
    cameraVideoInput: document.getElementById("comm-camera-video"),
    cameraPhotoBtn: document.getElementById("comm-camera-photo-btn"),
    cameraVideoBtn: document.getElementById("comm-camera-video-btn"),
    emojiBtn: document.getElementById("comm-emoji-btn"),
    stickerBtn: document.getElementById("comm-sticker-btn"),
    emojiPanel: document.getElementById("comm-emoji-panel"),
    stickerPanel: document.getElementById("comm-sticker-panel"),
    attachmentsLabel: document.getElementById("comm-attachments-label"),
    selectedFilesWrap: document.getElementById("comm-selected-files-wrap"),
    selectedFiles: document.getElementById("comm-selected-files"),
    recipientInput: document.getElementById("comm-recipient-input"),
    recipientSearch: document.getElementById("comm-recipient-search"),
    recipientSuggestions: document.getElementById("comm-recipient-suggestions"),
    recipientLockNote: document.getElementById("comm-recipient-lock-note"),
    typeMessage: document.getElementById("comm-type-message"),
    typeTask: document.getElementById("comm-type-task"),
    taskFields: document.getElementById("comm-task-fields"),
    taskTitle: document.getElementById("comm-task-title"),
    deadline: document.getElementById("comm-deadline"),
    body: document.getElementById("comm-body"),
    form: document.getElementById("comm-form"),
    error: document.getElementById("comm-error"),
    typing: document.getElementById("comm-typing"),
    voiceBtn: document.getElementById("comm-voice-btn"),
    sendBtn: document.getElementById("comm-send-btn"),
    voiceStatus: document.getElementById("comm-voice-status"),
    replyBanner: document.getElementById("comm-reply-banner"),
    replyTitle: document.getElementById("comm-reply-title"),
    replyText: document.getElementById("comm-reply-text"),
    replyCancel: document.getElementById("comm-reply-cancel"),
    sendProgress: document.getElementById("comm-send-progress"),
    sendProgressBar: document.getElementById("comm-send-progress-bar"),
    sendStatus: document.getElementById("comm-send-status"),
  };

  let state = {
    me: null,
    users: [],
    usersById: new Map(),
    chantierById: new Map(),
    serverMessages: [],
    currentChantierId: 0,
    pollTimer: null,
    typingPollTimer: null,
    typingPollInFlight: false,
    lastMessagesFingerprint: "",
    lastServerMessagesFingerprint: "",
    pagination: {
      pageSize: 30,
      hasMore: false,
      oldestMessageId: 0,
      newestMessageId: 0,
      loadingOlder: false,
      latestPromise: null,
    },
    virtualList: {
      threshold: 70,
      overscan: 8,
      defaultItemHeight: 132,
      measuredHeights: new Map(),
      topSpacer: null,
      bottomSpacer: null,
      viewport: null,
      scrollFrame: 0,
      bottomStickFrame: 0,
      autoScrollUnlockTimer: 0,
      isAutoScrolling: false,
      lastScrollTop: 0,
      manualBrowsing: false,
      anchorMessageId: "",
      stickToBottom: true,
    },
    selectedRecipientIds: new Set(),
    selectedFiles: [],
    replyTo: null,
    recipientUi: {
      isFocused: false,
      activeIndex: -1,
      matches: [],
    },
    localMessages: [],
    localMessageSeq: 0,
    typingThrottleTimer: null,
    typingStopTimer: null,
    lastTypingSentAt: 0,
    isTyping: false,
    voice: {
      isRecording: false,
      stream: null,
      recorder: null,
      chunks: [],
    },
    sending: {
      active: false,
      progress: 0,
      label: "",
    },
  };

  function showError(msg) {
    if (!els.error) return;
    els.error.textContent = msg || "";
    els.error.classList.toggle("hidden", !msg);
  }

  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function escapeAttr(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function getInitials(name) {
    const cleaned = String(name || "")
      .trim()
      .replace(/\s+/g, " ");
    if (!cleaned) return "?";
    const parts = cleaned.split(" ").filter(Boolean);
    const first = parts[0] ? parts[0][0] : "";
    const last = parts.length > 1 ? parts[parts.length - 1][0] : "";
    const out = (first + last).toUpperCase();
    return out || "?";
  }

  function normalizeSearchText(value) {
    return String(value || "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase()
      .trim();
  }

  function matchesRecipientQuery(display, query) {
    const normalizedDisplay = normalizeSearchText(display);
    const normalizedQuery = normalizeSearchText(query);
    if (!normalizedQuery) return false;
    if (normalizedDisplay.includes(normalizedQuery)) return true;
    const parts = normalizedDisplay.split(/\s+/).filter(Boolean);
    return parts.some((part) => part.startsWith(normalizedQuery));
  }

  function getUserInfo(userId) {
    return state.usersById.get(Number(userId)) || null;
  }

  function getSenderProfile(message) {
    const senderId = Number(
      message && message.sender_user_id ? message.sender_user_id : 0,
    );
    const userInfo = getUserInfo(senderId);
    const display = String(
      (message && message.sender_display) ||
        (userInfo && userInfo.display) ||
        `Utilisateur #${senderId || "?"}`,
    );
    const photo = String(
      (message && message.sender_photo) || (userInfo && userInfo.photo) || "",
    );

    return {
      id: senderId,
      display,
      photo,
    };
  }

  function resolvePhotoUrl(raw) {
    const v = String(raw || "").trim();
    if (!v) return "";
    if (/^(https?:|data:)/i.test(v)) return v;
    if (v.startsWith("/")) return v;
    return v;
  }

  function renderAvatarHtml(user, sizeClass) {
    const display = (user && user.display) || "";
    const initials = getInitials(display);
    const photoUrl = resolvePhotoUrl(user && user.photo);
    const size = sizeClass || "w-6 h-6";

    if (photoUrl) {
      return (
        `<img src="${escapeAttr(photoUrl)}" alt="${escapeAttr(display)}" ` +
        `class="${size} rounded-full object-cover bg-gray-100 border border-gray-200" loading="lazy">`
      );
    }

    return (
      `<div class="${size} rounded-full bg-gray-200 text-gray-700 border border-gray-200 ` +
      `flex items-center justify-center text-[10px] font-bold" aria-hidden="true">` +
      `${escapeHtml(initials)}` +
      `</div>`
    );
  }

  function getFileExt(name) {
    const n = String(name || "");
    const m = n.match(/\.([a-z0-9]+)$/i);
    return m ? String(m[1] || "").toLowerCase() : "";
  }

  function classifyAttachment(att) {
    const mime = String(att && att.mime ? att.mime : "").toLowerCase();
    const ext = getFileExt(att && att.name);
    const name = String(att && att.name ? att.name : "").toLowerCase();
    const isExplicitAudioMime = mime.startsWith("audio/");
    const isExplicitVideoMime = mime.startsWith("video/");
    const isVoiceNamed =
      name.startsWith("note_vocale_") || name.includes("note vocale");
    const isImage =
      mime.startsWith("image/") ||
      ["jpg", "jpeg", "png", "gif", "webp"].includes(ext);
    const isAudio =
      isExplicitAudioMime ||
      (!isExplicitVideoMime &&
        (isVoiceNamed || ["mp3", "m4a", "wav", "ogg", "opus"].includes(ext))) ||
      (!mime && ext === "webm" && isVoiceNamed);
    const isVideo =
      isExplicitVideoMime ||
      (!isExplicitAudioMime &&
        !isAudio &&
        ["mp4", "webm", "mov"].includes(ext));
    return { isImage, isVideo, isAudio, mime, ext };
  }

  function isVoiceNoteAttachment(att) {
    const cls = classifyAttachment(att);
    if (!cls.isAudio) return false;
    const name = String(att && att.name ? att.name : "").toLowerCase();
    return name.startsWith("note_vocale_") || name.includes("note vocale");
  }

  function getAttachmentLabel(att) {
    if (isVoiceNoteAttachment(att)) {
      return "Note vocale";
    }
    return String(att && att.name ? att.name : "Pièce jointe");
  }

  function getAttachmentMetaLabel(att) {
    if (isVoiceNoteAttachment(att)) {
      return "Message vocal";
    }
    return String(att && att.name ? att.name : "Pièce jointe");
  }

  function openFileInput(input) {
    if (!input) return;
    try {
      input.click();
    } catch (e) {}
  }

  function appendFilesFromInput(input) {
    if (!input) return;
    const files = input.files ? Array.from(input.files) : [];
    appendSelectedFiles(files);
    input.value = "";
  }

  function insertTextAtCursor(input, text) {
    if (!input) return;
    const value = String(input.value || "");
    const start = Number(input.selectionStart ?? value.length);
    const end = Number(input.selectionEnd ?? value.length);
    const nextValue = value.slice(0, start) + text + value.slice(end);
    input.value = nextValue;
    const cursor = start + text.length;
    input.setSelectionRange(cursor, cursor);
    input.dispatchEvent(new Event("input", { bubbles: true }));
    input.focus();
  }

  function toggleComposerPanel(panelName) {
    if (els.emojiPanel) {
      els.emojiPanel.classList.toggle(
        "is-visible",
        panelName === "emoji" &&
          !els.emojiPanel.classList.contains("is-visible"),
      );
      if (panelName !== "emoji") {
        els.emojiPanel.classList.remove("is-visible");
      }
    }
    if (els.stickerPanel) {
      els.stickerPanel.classList.toggle(
        "is-visible",
        panelName === "sticker" &&
          !els.stickerPanel.classList.contains("is-visible"),
      );
      if (panelName !== "sticker") {
        els.stickerPanel.classList.remove("is-visible");
      }
    }
  }

  function closeComposerPanels() {
    if (els.emojiPanel) els.emojiPanel.classList.remove("is-visible");
    if (els.stickerPanel) els.stickerPanel.classList.remove("is-visible");
  }

  function renderAudioWaveHtml(isMine) {
    const className = isMine
      ? "audio-card-wave audio-card-wave--mine"
      : "audio-card-wave audio-card-wave--other";
    return (
      `<div class="${className}" aria-hidden="true">` +
      `<span></span><span></span><span></span><span></span><span></span><span></span><span></span>` +
      `</div>`
    );
  }

  function autoResizeComposer() {
    if (!els.body) return;
    els.body.style.height = "auto";
    const nextHeight = Math.min(Math.max(els.body.scrollHeight, 42), 120);
    els.body.style.height = `${nextHeight}px`;
  }

  function fileToKey(file) {
    return [
      String(file && file.name ? file.name : ""),
      String(file && file.size ? file.size : 0),
      String(file && file.lastModified ? file.lastModified : 0),
      String(file && file.type ? file.type : ""),
    ].join("::");
  }

  function syncAttachmentsInputFromState() {
    if (!els.attachmentsInput) return;
    if (typeof DataTransfer === "undefined") return;

    const dt = new DataTransfer();
    state.selectedFiles.forEach((file) => {
      dt.items.add(file);
    });
    els.attachmentsInput.files = dt.files;
  }

  function renderSelectedFiles() {
    if (!els.selectedFiles || !els.selectedFilesWrap) return;

    els.selectedFiles.innerHTML = "";
    if (!state.selectedFiles.length) {
      els.selectedFilesWrap.classList.add("hidden");
      if (els.attachmentsLabel) {
        els.attachmentsLabel.textContent = "";
      }
      updateBodyRequired();
      updateComposerActionVisibility();
      return;
    }

    els.selectedFilesWrap.classList.remove("hidden");
    state.selectedFiles.forEach((file, index) => {
      const cls = classifyAttachment({ name: file.name, mime: file.type });
      let icon = "fa-paperclip";
      if (cls.isImage) icon = "fa-image";
      else if (cls.isVideo) icon = "fa-film";
      else if (cls.isAudio) icon = "fa-microphone";

      const chip = document.createElement("div");
      chip.className = "file-chip";

      const label = document.createElement("span");
      label.className = "file-chip-label";
      label.innerHTML = `<i class="fa-solid ${icon} mr-2"></i>${escapeHtml(file.name)}`;

      const removeBtn = document.createElement("button");
      removeBtn.type = "button";
      removeBtn.setAttribute("aria-label", "Retirer " + file.name);
      removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      removeBtn.addEventListener("click", () => {
        state.selectedFiles.splice(index, 1);
        syncAttachmentsInputFromState();
        renderSelectedFiles();
      });

      chip.appendChild(label);
      chip.appendChild(removeBtn);
      els.selectedFiles.appendChild(chip);
    });

    if (els.attachmentsLabel) {
      els.attachmentsLabel.textContent = `${state.selectedFiles.length} fichier(s) sélectionné(s)`;
    }

    updateBodyRequired();
    updateComposerActionVisibility();
  }

  function appendSelectedFiles(files) {
    const incoming = Array.isArray(files) ? files : [];
    const existing = new Set(
      state.selectedFiles.map((file) => fileToKey(file)),
    );

    incoming.forEach((file) => {
      const key = fileToKey(file);
      if (existing.has(key)) return;
      state.selectedFiles.push(file);
      existing.add(key);
    });

    syncAttachmentsInputFromState();
    renderSelectedFiles();
  }

  function clearSelectedFiles() {
    state.selectedFiles = [];
    if (els.attachmentsInput) {
      els.attachmentsInput.value = "";
    }
    syncAttachmentsInputFromState();
    renderSelectedFiles();
  }

  function formatLocalTimestamp() {
    return new Date().toISOString();
  }

  function formatNaturalDateTime(value) {
    if (!value) return "";

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
      return String(value);
    }

    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const targetDay = new Date(
      date.getFullYear(),
      date.getMonth(),
      date.getDate(),
    );
    const diffDays = Math.round((targetDay - today) / 86400000);
    const timeText = new Intl.DateTimeFormat("fr-FR", {
      hour: "2-digit",
      minute: "2-digit",
    }).format(date);

    if (diffDays === 0) {
      return `Aujourd’hui à ${timeText}`;
    }

    if (diffDays === -1) {
      return `Hier à ${timeText}`;
    }

    if (diffDays === 1) {
      return `Demain à ${timeText}`;
    }

    const dateText = new Intl.DateTimeFormat("fr-FR", {
      day: "numeric",
      month: "long",
      year: "numeric",
    }).format(date);

    return `${dateText} à ${timeText}`;
  }

  function createLocalAttachmentFromFile(file) {
    const objectUrl = URL.createObjectURL(file);
    return {
      id: `local-attachment-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      name: String(file && file.name ? file.name : "Pièce jointe"),
      mime: String(file && file.type ? file.type : "application/octet-stream"),
      size: Number(file && file.size ? file.size : 0),
      url: objectUrl,
      preview_url: objectUrl,
      __objectUrl: objectUrl,
    };
  }

  function getReplySummary(message) {
    if (!message) return "Message";
    if (String(message.message_type || "") === "task") {
      return String(message.task_title || "Tâche").trim() || "Tâche";
    }

    const body = String(message.body || "").trim();
    if (body) {
      return body.replace(/\s+/g, " ");
    }

    const attachments = Array.isArray(message.attachments)
      ? message.attachments
      : [];
    if (attachments.length) {
      if (attachments.some((att) => isVoiceNoteAttachment(att))) {
        return "Note vocale";
      }
      return "Pièce jointe";
    }

    if (Number(message.attachment_count || 0) > 0) {
      return "Pièce jointe";
    }

    return "Message";
  }

  function getReplyPayloadFromMessage(message) {
    if (!message) return null;
    const sender = getSenderProfile(message);
    return {
      id: Number(message.id || 0),
      sender_display: String(sender.display || "Utilisateur"),
      message_type: String(message.message_type || "message"),
      body: String(message.body || ""),
      task_title: String(message.task_title || ""),
      created_at_iso: String(message.created_at_iso || ""),
      attachment_count: Number(message.attachment_count || 0),
      attachments: Array.isArray(message.attachments)
        ? message.attachments
        : [],
    };
  }

  function renderReplyComposer() {
    const reply = state.replyTo;
    if (!els.replyBanner || !els.replyTitle || !els.replyText) return;

    if (!reply || !reply.id) {
      els.replyBanner.classList.remove("is-visible");
      els.replyTitle.textContent = "";
      els.replyText.textContent = "";
      return;
    }

    els.replyTitle.textContent = `Réponse à ${reply.sender_display || "Utilisateur"}`;
    els.replyText.textContent = getReplySummary(reply);
    els.replyBanner.classList.add("is-visible");
  }

  function isReplyModeLocked() {
    return !!(state.replyTo && state.replyTo.id);
  }

  function updateRecipientLockUi() {
    const locked = isReplyModeLocked();
    if (els.recipientInput) {
      els.recipientInput.classList.toggle("recipient-input-locked", locked);
    }
    if (els.recipientLockNote) {
      els.recipientLockNote.classList.toggle("is-visible", locked);
    }
    if (els.recipientSearch) {
      els.recipientSearch.readOnly = locked;
      if (locked) {
        els.recipientSearch.blur();
      }
    }
  }

  function clearReplyTarget() {
    state.replyTo = null;
    renderReplyComposer();
    updateRecipientLockUi();
  }

  function setReplyTarget(message) {
    if (!message || !message.id) return;
    state.replyTo = getReplyPayloadFromMessage(message);

    const recipientIds = new Set();
    const recipients = Array.isArray(message.recipients)
      ? message.recipients
      : [];
    recipients.forEach((recipient) => {
      const id = Number(recipient && recipient.id ? recipient.id : 0);
      if (id > 0 && (!state.me || id !== Number(state.me.id))) {
        recipientIds.add(String(id));
      }
    });

    const senderId = Number(message.sender_user_id || 0);
    if (senderId > 0 && (!state.me || senderId !== Number(state.me.id))) {
      recipientIds.add(String(senderId));
    }

    state.selectedRecipientIds = recipientIds;
    syncRecipientsControls();
    hideRecipientSuggestions();
    renderReplyComposer();
    updateRecipientLockUi();
    updateComposerActionVisibility();

    if (els.body) {
      try {
        els.body.focus();
      } catch (e) {}
    }
  }

  function addLocalMessage(payload) {
    state.localMessageSeq += 1;
    const localId = `local-${Date.now()}-${state.localMessageSeq}`;
    const recipients = Array.from(state.selectedRecipientIds || []).map(
      (id) => {
        const user = getUserInfo(id);
        return {
          id: Number(id),
          display: (user && user.display) || `Utilisateur #${id}`,
        };
      },
    );

    const message = {
      id: localId,
      sender_user_id: Number(state.me && state.me.id ? state.me.id : 0),
      sender_display: String(
        (state.me &&
          (state.me.display || state.me.name || state.me.mail_pro)) ||
          "Moi",
      ),
      sender_photo: String((state.me && state.me.photo) || ""),
      message_type: String((payload && payload.message_type) || "message"),
      body: String((payload && payload.body) || ""),
      task_title: String((payload && payload.task_title) || ""),
      deadline_iso: String((payload && payload.deadline_iso) || ""),
      created_at_iso: formatLocalTimestamp(),
      recipients: recipients,
      attachments: Array.isArray(payload && payload.attachments)
        ? payload.attachments
        : [],
      reply_to: payload && payload.reply_to ? payload.reply_to : null,
      recipient_count: recipients.length,
      read_count: 0,
      __localStatus: "sending",
      __localId: localId,
    };

    state.localMessages.push(message);
    renderMessages(getRenderableMessages(state.serverMessages));
    return localId;
  }

  function removeLocalMessage(localId) {
    const next = [];
    state.localMessages.forEach((message) => {
      if (String(message.__localId || message.id) === String(localId)) {
        const attachments = Array.isArray(message.attachments)
          ? message.attachments
          : [];
        attachments.forEach((attachment) => {
          if (attachment && attachment.__objectUrl) {
            try {
              URL.revokeObjectURL(attachment.__objectUrl);
            } catch (e) {}
          }
        });
        return;
      }
      next.push(message);
    });
    state.localMessages = next;
    renderMessages(getRenderableMessages(state.serverMessages));
  }

  function getRenderableMessages(serverMessages) {
    const base = Array.isArray(serverMessages) ? serverMessages.slice() : [];
    if (!state.localMessages.length) {
      return base;
    }
    return base.concat(state.localMessages);
  }

  function mergeMessageLists(existingMessages, incomingMessages) {
    const merged = new Map();

    (Array.isArray(existingMessages) ? existingMessages : []).forEach(
      (message) => {
        const id = String(message && message.id ? message.id : "");
        if (!id) return;
        merged.set(id, message);
      },
    );

    (Array.isArray(incomingMessages) ? incomingMessages : []).forEach(
      (message) => {
        const id = String(message && message.id ? message.id : "");
        if (!id) return;
        merged.set(id, message);
      },
    );

    return Array.from(merged.values()).sort(
      (left, right) =>
        Number((left && left.id) || 0) - Number((right && right.id) || 0),
    );
  }

  function updatePaginationState(response) {
    state.pagination.hasMore = !!(response && response.has_more);
    state.pagination.oldestMessageId = state.serverMessages.length
      ? Number((state.serverMessages[0] && state.serverMessages[0].id) || 0)
      : Number((response && response.oldest_message_id) || 0);
    state.pagination.newestMessageId = state.serverMessages.length
      ? Number(
          (state.serverMessages[state.serverMessages.length - 1] &&
            state.serverMessages[state.serverMessages.length - 1].id) ||
            0,
        )
      : Number((response && response.newest_message_id) || 0);
  }

  function ensureMessagesVirtualScaffold() {
    if (!els.messages) {
      return { topSpacer: null, viewport: null, bottomSpacer: null };
    }

    let topSpacer = els.messages.querySelector("[data-messages-top-spacer]");
    if (!topSpacer) {
      topSpacer = document.createElement("div");
      topSpacer.setAttribute("data-messages-top-spacer", "1");
      topSpacer.style.height = "0px";
      els.messages.appendChild(topSpacer);
    }

    let viewport = els.messages.querySelector("[data-messages-viewport]");
    if (!viewport) {
      viewport = document.createElement("div");
      viewport.setAttribute("data-messages-viewport", "1");
      viewport.className = "space-y-2 md:space-y-3";
      els.messages.appendChild(viewport);
    }

    let bottomSpacer = els.messages.querySelector(
      "[data-messages-bottom-spacer]",
    );
    if (!bottomSpacer) {
      bottomSpacer = document.createElement("div");
      bottomSpacer.setAttribute("data-messages-bottom-spacer", "1");
      bottomSpacer.style.height = "0px";
      els.messages.appendChild(bottomSpacer);
    }

    state.virtualList.topSpacer = topSpacer;
    state.virtualList.viewport = viewport;
    state.virtualList.bottomSpacer = bottomSpacer;

    return { topSpacer, viewport, bottomSpacer };
  }

  function getKnownMessageHeight(message) {
    const id = String(message && message.id ? message.id : "");
    if (!id) {
      return state.virtualList.defaultItemHeight;
    }
    return (
      state.virtualList.measuredHeights.get(id) ||
      state.virtualList.defaultItemHeight
    );
  }

  function sumMessageHeights(messages, startIndex, endIndex) {
    if (startIndex > endIndex) return 0;
    let total = 0;
    for (let index = startIndex; index <= endIndex; index += 1) {
      total += getKnownMessageHeight(messages[index]);
    }
    return total;
  }

  function computeVirtualRange(messages) {
    const list = Array.isArray(messages) ? messages : [];
    const shouldVirtualize = !!(
      els.messages && list.length >= state.virtualList.threshold
    );

    if (!shouldVirtualize) {
      return {
        shouldVirtualize: false,
        start: 0,
        end: Math.max(0, list.length - 1),
        topHeight: 0,
        bottomHeight: 0,
      };
    }

    const viewportHeight = Math.max(Number(els.messages.clientHeight) || 0, 1);
    const scrollTop = Math.max(Number(els.messages.scrollTop) || 0, 0);
    const overscanPx =
      state.virtualList.overscan * state.virtualList.defaultItemHeight;
    const topBoundary = Math.max(0, scrollTop - overscanPx);
    const bottomBoundary = scrollTop + viewportHeight + overscanPx;

    let offset = 0;
    let start = 0;
    let end = list.length - 1;
    let foundStart = false;

    for (let index = 0; index < list.length; index += 1) {
      const nextOffset = offset + getKnownMessageHeight(list[index]);
      if (!foundStart && nextOffset >= topBoundary) {
        start = Math.max(0, index - state.virtualList.overscan);
        foundStart = true;
      }
      if (nextOffset >= bottomBoundary) {
        end = Math.min(list.length - 1, index + state.virtualList.overscan);
        break;
      }
      offset = nextOffset;
    }

    if (!foundStart) {
      start = Math.max(0, list.length - state.virtualList.overscan * 2 - 1);
    }

    const anchorMessageId = String(state.virtualList.anchorMessageId || "");
    if (anchorMessageId) {
      const anchorIndex = list.findIndex(
        (message) =>
          String(message && message.id ? message.id : "") === anchorMessageId,
      );
      if (anchorIndex !== -1) {
        start = Math.min(
          start,
          Math.max(0, anchorIndex - state.virtualList.overscan),
        );
        end = Math.max(
          end,
          Math.min(list.length - 1, anchorIndex + state.virtualList.overscan),
        );
      }
    }

    return {
      shouldVirtualize: true,
      start,
      end,
      topHeight: sumMessageHeights(list, 0, start - 1),
      bottomHeight: sumMessageHeights(list, end + 1, list.length - 1),
    };
  }

  function measureVisibleMessageHeights(messages, range) {
    const viewport = state.virtualList.viewport;
    if (!viewport) return;

    let changed = false;
    Array.from(viewport.querySelectorAll("[data-message-id]")).forEach(
      (node) => {
        const messageId = String(node.getAttribute("data-message-id") || "");
        if (!messageId) return;
        const height = Math.max(
          Math.round(node.getBoundingClientRect().height || 0),
          1,
        );
        if (state.virtualList.measuredHeights.get(messageId) !== height) {
          state.virtualList.measuredHeights.set(messageId, height);
          changed = true;
        }
      },
    );

    if (!changed || !range.shouldVirtualize) return;

    if (state.virtualList.topSpacer) {
      state.virtualList.topSpacer.style.height = `${sumMessageHeights(
        messages,
        0,
        range.start - 1,
      )}px`;
    }
    if (state.virtualList.bottomSpacer) {
      state.virtualList.bottomSpacer.style.height = `${sumMessageHeights(
        messages,
        range.end + 1,
        messages.length - 1,
      )}px`;
    }
  }

  function scheduleVirtualRender() {
    if (!els.messages) return;
    if (state.virtualList.scrollFrame) return;

    state.virtualList.scrollFrame = window.requestAnimationFrame(() => {
      state.virtualList.scrollFrame = 0;
      renderMessages(getRenderableMessages(state.serverMessages), {
        fromScroll: true,
      });
    });
  }

  function renderAttachmentsHtml(attachments, isMine) {
    const list = Array.isArray(attachments) ? attachments : [];
    if (!list.length) return "";

    const textColor = isMine ? "text-indigo-100" : "text-gray-600";
    const linkColor = isMine
      ? "text-indigo-100 hover:text-white"
      : "text-indigo-700 hover:text-indigo-900";

    const parts = list.map((a) => {
      const url = String(a.url || "#");
      const previewUrl = String(a.preview_url || a.url || "#");
      const name = String(a.name || "Pièce jointe");

      const cls = classifyAttachment(a);

      if (cls.isImage) {
        return (
          `<a href="${escapeAttr(url)}" target="_blank" rel="noopener" class="block mt-2">` +
          `<img src="${escapeAttr(previewUrl)}" alt="${escapeAttr(name)}" ` +
          `class="max-w-[320px] w-full rounded-xl border border-gray-200 bg-white object-cover">` +
          `</a>`
        );
      }

      if (cls.isAudio) {
        const cardClass = isMine
          ? "audio-card audio-card--mine text-indigo-50"
          : "audio-card audio-card--other text-slate-700";
        const metaClass = isMine ? "text-indigo-100/90" : "text-slate-500";
        const iconWrapClass = isMine
          ? "audio-card-icon audio-card-icon--mine"
          : "audio-card-icon audio-card-icon--other";
        return (
          `<div class="${cardClass}">` +
          `<div class="audio-card-head">` +
          `<div class="${iconWrapClass}">` +
          `<i class="fa-solid fa-microphone"></i>` +
          `</div>` +
          `<div class="min-w-0 flex-1">` +
          `<div class="text-sm font-semibold">${escapeHtml(
            getAttachmentLabel(a),
          )}</div>` +
          `<div class="text-[11px] ${metaClass}">${escapeHtml(
            getAttachmentMetaLabel(a),
          )}</div>` +
          `${renderAudioWaveHtml(isMine)}` +
          `</div>` +
          `</div>` +
          `<audio src="${escapeAttr(previewUrl)}" controls preload="metadata"></audio>` +
          `</div>`
        );
      }

      if (cls.isVideo) {
        return (
          `<div class="mt-2">` +
          `<video src="${escapeAttr(previewUrl)}" controls preload="metadata" ` +
          `class="max-w-[320px] w-full rounded-xl border border-gray-200 bg-black"></video>` +
          `</div>`
        );
      }

      return (
        `<div class="mt-2 text-xs ${textColor}">` +
        `<a class="underline ${linkColor}" href="${escapeAttr(url)}" target="_blank" rel="noopener">` +
        `<i class="fa-solid fa-paperclip mr-2"></i>${escapeHtml(name)}` +
        `</a></div>`
      );
    });

    return parts.join("");
  }

  async function apiPost(url, bodyObj) {
    const params = new URLSearchParams();
    Object.entries(bodyObj || {}).forEach(([k, v]) => {
      if (Array.isArray(v)) {
        v.forEach((it) => params.append(k + "[]", String(it)));
      } else if (v !== undefined && v !== null) {
        params.append(k, String(v));
      }
    });

    const res = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: params.toString(),
    });
    return res.json();
  }

  async function apiPostFormData(url, formData) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open("POST", url, true);
      xhr.responseType = "json";

      xhr.upload.onprogress = (event) => {
        if (!event || typeof event.loaded !== "number") return;
        if (typeof apiPostFormData.onProgress === "function") {
          apiPostFormData.onProgress(event);
        }
      };

      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          const response = xhr.response;
          if (response && typeof response === "object") {
            resolve(response);
            return;
          }

          try {
            resolve(JSON.parse(xhr.responseText || "{}"));
          } catch (e) {
            reject(e);
          }
          return;
        }

        reject(new Error(`HTTP ${xhr.status}`));
      };

      xhr.onerror = () => {
        reject(new Error("Erreur réseau"));
      };

      xhr.send(formData);
    });
  }

  apiPostFormData.onProgress = null;

  async function apiGet(url) {
    const res = await fetch(url, { method: "GET" });
    return res.json();
  }

  function hideRecipientSuggestions() {
    if (!els.recipientSuggestions) return;
    state.recipientUi.activeIndex = -1;
    state.recipientUi.matches = [];
    els.recipientSuggestions.classList.add("hidden");
    els.recipientSuggestions.innerHTML = "";
  }

  function getRecipientMatches(query) {
    const q = String(query || "").trim();
    if (!q) return [];

    const selected = new Set(
      Array.from(state.selectedRecipientIds || []).map((x) => String(x)),
    );

    const out = [];
    (state.users || []).forEach((u) => {
      if (state.me && Number(u.id) === Number(state.me.id)) return;
      const idStr = String(u.id);
      if (selected.has(idStr)) return;
      const display = String(u.display || "");
      if (matchesRecipientQuery(display, q)) {
        out.push({ id: idStr, display: display });
      }
    });

    return out.slice(0, 8);
  }

  function addRecipient(idStr) {
    if (isReplyModeLocked()) return;
    const id = String(idStr);
    if (!id) return;
    if (state.me && Number(id) === Number(state.me.id)) return;
    state.selectedRecipientIds.add(id);
    if (els.recipientSearch) els.recipientSearch.value = "";
    renderRecipientBadges();
    syncRecipientsSelectFallback();
    hideRecipientSuggestions();
  }

  function removeLastRecipient() {
    if (isReplyModeLocked()) return;
    const arr = Array.from(state.selectedRecipientIds || []);
    const last = arr.length ? String(arr[arr.length - 1]) : "";
    if (!last) return;
    state.selectedRecipientIds.delete(last);
    renderRecipientBadges();
    syncRecipientsSelectFallback();
  }

  function renderRecipientBadges() {
    if (!els.recipientInput || !els.recipientSearch) return;

    // Retirer les badges existants
    Array.from(
      els.recipientInput.querySelectorAll("[data-comm-badge]"),
    ).forEach((n) => n.remove());

    const selected = Array.from(state.selectedRecipientIds || []).map(String);
    selected.forEach((idStr) => {
      const user = getUserInfo(idStr);
      const userDisplay = (user && user.display) || "Utilisateur #" + idStr;

      const badge = document.createElement("span");
      badge.setAttribute("data-comm-badge", "1");
      badge.className =
        "inline-flex items-center gap-2 rounded-full border border-gray-200 bg-gray-50 text-gray-700 px-3 py-1 text-xs font-semibold";

      const label = document.createElement("span");
      label.textContent = userDisplay;

      const removeBtn = document.createElement("button");
      removeBtn.type = "button";
      removeBtn.className =
        "text-gray-500 hover:text-gray-700 transition inline-flex items-center";
      removeBtn.setAttribute("aria-label", "Retirer " + userDisplay);
      removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      removeBtn.addEventListener("click", () => {
        if (isReplyModeLocked()) return;
        state.selectedRecipientIds.delete(String(idStr));
        renderRecipientBadges();
        syncRecipientsSelectFallback();
      });

      badge.appendChild(label);
      badge.appendChild(removeBtn);

      els.recipientInput.insertBefore(badge, els.recipientSearch);
    });

    publishHubSnapshot();
  }

  function renderRecipientSuggestions() {
    if (!els.recipientSuggestions || !els.recipientSearch) return;
    const matches = getRecipientMatches(els.recipientSearch.value);
    state.recipientUi.matches = matches;
    els.recipientSuggestions.innerHTML = "";

    if (!matches.length) {
      hideRecipientSuggestions();
      return;
    }

    let activeIndex = Number(state.recipientUi.activeIndex || 0);
    if (activeIndex < 0 || activeIndex >= matches.length) {
      activeIndex = 0;
    }
    state.recipientUi.activeIndex = activeIndex;

    matches.forEach((m, idx) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className =
        "suggestion-item w-full text-left px-3 py-2 text-sm hover:bg-indigo-50 transition " +
        (idx === activeIndex ? "is-active" : "bg-white");
      btn.textContent = m.display;
      btn.setAttribute("data-suggestion-index", String(idx));
      btn.addEventListener("mouseenter", () => {
        state.recipientUi.activeIndex = idx;
        Array.from(
          els.recipientSuggestions.querySelectorAll(".suggestion-item"),
        ).forEach((item) => {
          item.classList.toggle(
            "is-active",
            Number(item.getAttribute("data-suggestion-index") || -1) === idx,
          );
        });
      });
      btn.addEventListener("mousedown", (event) => {
        event.preventDefault();
      });
      btn.addEventListener("click", () => addRecipient(m.id));
      els.recipientSuggestions.appendChild(btn);
    });

    els.recipientSuggestions.classList.remove("hidden");
  }

  function moveRecipientSuggestion(direction) {
    const matches = Array.isArray(state.recipientUi.matches)
      ? state.recipientUi.matches
      : [];
    if (!matches.length) return;
    const current = Number(state.recipientUi.activeIndex || 0);
    const next = (current + direction + matches.length) % matches.length;
    state.recipientUi.activeIndex = next;
    renderRecipientSuggestions();
  }

  function selectActiveRecipientSuggestion() {
    const matches = Array.isArray(state.recipientUi.matches)
      ? state.recipientUi.matches
      : [];
    if (!matches.length) return false;
    const index = Number(state.recipientUi.activeIndex || 0);
    const match = matches[index] || matches[0];
    if (!match) return false;
    addRecipient(match.id);
    return true;
  }

  function syncRecipientsSelectFallback() {
    if (!els.recipientsSelect) return;
    const selected = new Set(
      Array.from(state.selectedRecipientIds || []).map(String),
    );
    Array.from(els.recipientsSelect.options || []).forEach((o) => {
      o.selected = selected.has(String(o.value));
    });
  }

  function syncRecipientsControls() {
    renderRecipientBadges();
    syncRecipientsSelectFallback();
  }

  function getCurrentContextLabel() {
    const ch = state.chantierById.get(Number(state.currentChantierId || 0));
    if (ch) {
      return [ch.code, ch.titre].filter(Boolean).join(" — ");
    }
    return "la conversation";
  }

  function buildTaskSnapshot() {
    const now = Date.now();
    return (Array.isArray(state.serverMessages) ? state.serverMessages : [])
      .filter((message) => String(message && message.message_type) === "task")
      .slice()
      .sort((a, b) => Number(b.id || 0) - Number(a.id || 0))
      .map((message) => {
        const deadlineIso = String((message && message.deadline_iso) || "");
        const deadlineTs = deadlineIso ? new Date(deadlineIso).getTime() : 0;
        return {
          id: String((message && message.id) || ""),
          chantier_id: Number((message && message.chantier_id) || 0),
          task_title: String((message && message.task_title) || "Tâche"),
          body: String((message && message.body) || ""),
          deadline_iso: deadlineIso,
          sender_display: String((message && message.sender_display) || ""),
          recipient_labels: Array.isArray(message && message.recipients)
            ? message.recipients.map((recipient) =>
                String((recipient && recipient.display) || ""),
              )
            : [],
          is_overdue: !!(deadlineTs && deadlineTs < now),
        };
      });
  }

  function buildHubSnapshot() {
    const selectedRecipients = Array.from(state.selectedRecipientIds || [])
      .map((id) => getUserInfo(id))
      .filter(Boolean)
      .map((user) => ({
        id: Number(user.id),
        display: String(user.display || ""),
        mail_pro: String(user.mail_pro || ""),
      }));

    return {
      me: state.me
        ? {
            id: Number(state.me.id || 0),
            display: String(state.me.display || ""),
          }
        : null,
      selectedRecipients,
      currentChantierId: Number(state.currentChantierId || 0),
      currentContextLabel: getCurrentContextLabel(),
      chantiers: Array.from(state.chantierById.values()).map((chantier) => ({
        id: Number(chantier.id || 0),
        code: String(chantier.code || ""),
        titre: String(chantier.titre || ""),
      })),
      taskMode: !!(els.typeTask && els.typeTask.checked),
      tasks: buildTaskSnapshot(),
    };
  }

  function publishHubSnapshot() {
    const detail = buildHubSnapshot();
    document.dispatchEvent(new window.CustomEvent("comm:snapshot", { detail }));
    return detail;
  }

  window.CommHubBridge = {
    getSnapshot: buildHubSnapshot,
    publishSnapshot: publishHubSnapshot,
    openTaskComposer() {
      if (els.typeTask) els.typeTask.checked = true;
      if (els.typeMessage) els.typeMessage.checked = false;
      setTypeUi();
      if (els.taskTitle) {
        try {
          els.taskTitle.focus();
        } catch (e) {}
      }
    },
  };

  function renderHeader(chantierId) {
    if (!els.header) return;
    const ch = state.chantierById.get(Number(chantierId));
    if (!ch) {
      els.header.textContent = "Communication";
      publishHubSnapshot();
      return;
    }
    els.header.textContent = `${ch.code} — ${ch.titre}`;
    publishHubSnapshot();
  }

  function isMessagesNearBottom() {
    if (!els.messages) return true;
    const threshold = 80;
    const remaining =
      els.messages.scrollHeight -
      els.messages.scrollTop -
      els.messages.clientHeight;
    return remaining <= threshold;
  }

  function scrollMessagesToBottom() {
    if (!els.messages) return;
    state.virtualList.stickToBottom = true;
    state.virtualList.manualBrowsing = false;

    if (state.virtualList.bottomStickFrame) {
      window.cancelAnimationFrame(state.virtualList.bottomStickFrame);
      state.virtualList.bottomStickFrame = 0;
    }
    if (state.virtualList.autoScrollUnlockTimer) {
      window.clearTimeout(state.virtualList.autoScrollUnlockTimer);
      state.virtualList.autoScrollUnlockTimer = 0;
    }
    state.virtualList.isAutoScrolling = true;

    const applyBottomScroll = () => {
      try {
        els.messages.scrollTop = els.messages.scrollHeight;
      } catch (e) {}
    };

    applyBottomScroll();
    state.virtualList.bottomStickFrame = window.requestAnimationFrame(() => {
      state.virtualList.bottomStickFrame = 0;
      applyBottomScroll();
      state.virtualList.autoScrollUnlockTimer = window.setTimeout(() => {
        state.virtualList.isAutoScrolling = false;
        state.virtualList.stickToBottom = false;
        state.virtualList.autoScrollUnlockTimer = 0;
      }, 48);
    });
  }

  function cancelAutoScrollToBottom() {
    if (state.virtualList.bottomStickFrame) {
      window.cancelAnimationFrame(state.virtualList.bottomStickFrame);
      state.virtualList.bottomStickFrame = 0;
    }
    if (state.virtualList.autoScrollUnlockTimer) {
      window.clearTimeout(state.virtualList.autoScrollUnlockTimer);
      state.virtualList.autoScrollUnlockTimer = 0;
    }
    state.virtualList.isAutoScrolling = false;
    state.virtualList.stickToBottom = false;
  }

  function getLastRenderedMessageId() {
    if (!els.messages) return "";
    const attrValue = String(
      els.messages.getAttribute("data-last-rendered-message-id") || "",
    );
    if (attrValue) return attrValue;
    const nodes = els.messages.querySelectorAll("[data-message-id]");
    const last = nodes.length ? nodes[nodes.length - 1] : null;
    return last ? String(last.getAttribute("data-message-id") || "") : "";
  }

  function buildMessageSignature(message) {
    return JSON.stringify({
      id: Number(message && message.id ? message.id : 0),
      sender: Number(
        message && message.sender_user_id ? message.sender_user_id : 0,
      ),
      senderDisplay: String((message && message.sender_display) || ""),
      senderPhoto: String((message && message.sender_photo) || ""),
      type: String((message && message.message_type) || ""),
      body: String((message && message.body) || ""),
      taskTitle: String((message && message.task_title) || ""),
      deadline: String((message && message.deadline_iso) || ""),
      createdAt: String((message && message.created_at_iso) || ""),
      recipients: Array.isArray(message && message.recipients)
        ? message.recipients.map((item) => ({
            id: Number(item && item.id ? item.id : 0),
            display: String((item && item.display) || ""),
          }))
        : [],
      attachments: Array.isArray(message && message.attachments)
        ? message.attachments.map((item) => ({
            id: Number(item && item.id ? item.id : 0),
            name: String((item && item.name) || ""),
            mime: String((item && item.mime) || ""),
            url: String((item && item.url) || ""),
            previewUrl: String((item && item.preview_url) || ""),
          }))
        : [],
      replyTo:
        message && message.reply_to
          ? {
              id: Number(message.reply_to.id || 0),
              senderDisplay: String(message.reply_to.sender_display || ""),
              type: String(message.reply_to.message_type || ""),
              body: String(message.reply_to.body || ""),
              taskTitle: String(message.reply_to.task_title || ""),
              attachmentCount: Number(message.reply_to.attachment_count || 0),
              createdAt: String(message.reply_to.created_at_iso || ""),
            }
          : null,
      recipientCount: Number(
        message && message.recipient_count ? message.recipient_count : 0,
      ),
      readCount: Number(message && message.read_count ? message.read_count : 0),
      localStatus: String((message && message.__localStatus) || ""),
    });
  }

  function buildMessageHtml(message) {
    const isMine =
      state.me && Number(message.sender_user_id) === Number(state.me.id);
    const senderUser = getSenderProfile(message);
    const senderDisplay = senderUser.display;

    const recipients = Array.isArray(message.recipients)
      ? message.recipients
      : [];
    const recipientsText = recipients.length
      ? recipients
          .map((item) => String((item && item.display) || ""))
          .filter(Boolean)
          .join(", ")
      : "";

    const attachments = Array.isArray(message.attachments)
      ? message.attachments
      : [];
    const replyTo = message && message.reply_to ? message.reply_to : null;
    const createdAtText = formatNaturalDateTime(message.created_at_iso || "");
    const deadlineText = formatNaturalDateTime(message.deadline_iso || "");
    const headerTextColor = isMine ? "text-indigo-100" : "text-gray-500";
    const headerHtml =
      `<div class="flex items-center gap-2 text-xs ${headerTextColor}">` +
      `${renderAvatarHtml(senderUser, "w-6 h-6")}` +
      `<div class="min-w-0 font-semibold truncate">${escapeHtml(senderDisplay)}</div>` +
      `<div class="ml-auto whitespace-nowrap">${escapeHtml(
        createdAtText,
      )}</div>` +
      `</div>`;

    let bodyHtml = "";
    if (replyTo && replyTo.id) {
      const replyClass = isMine
        ? "message-reply-snippet"
        : "message-reply-snippet message-reply-snippet--other";
      bodyHtml +=
        `<button type="button" class="${replyClass}" data-action="jump-to-message" data-target-message-id="${escapeAttr(
          String(replyTo.id || ""),
        )}">` +
        `<div class="message-reply-author">${escapeHtml(
          String(replyTo.sender_display || "Utilisateur"),
        )}</div>` +
        `<div class="message-reply-text">${escapeHtml(
          getReplySummary(replyTo),
        )}</div>` +
        `</button>`;
    }

    if (String(message.message_type) === "task") {
      bodyHtml +=
        `<div class="mt-2 font-semibold">` +
        `<i class="fa-solid fa-list-check mr-2"></i>${escapeHtml(
          message.task_title || "Tâche",
        )}</div>`;
      if (message.deadline_iso) {
        bodyHtml +=
          `<div class="text-sm mt-1 ${isMine ? "text-indigo-100" : "text-gray-600"}">` +
          `Deadline: <span class="font-semibold">${escapeHtml(
            deadlineText,
          )}</span></div>`;
      }
      if (message.body) {
        bodyHtml += `<div class="mt-2 text-sm whitespace-pre-wrap">${escapeHtml(
          message.body,
        )}</div>`;
      }
    } else {
      if (message.body) {
        bodyHtml += `<div class="mt-2 text-sm whitespace-pre-wrap">${escapeHtml(
          message.body,
        )}</div>`;
      }
    }

    let footerHtml = "";
    if (recipientsText) {
      footerHtml =
        `<div class="mt-2 text-xs ${isMine ? "text-indigo-100" : "text-gray-500"}">` +
        `Destinataires: ${escapeHtml(recipientsText)}` +
        `</div>`;
    }

    const attachmentsHtml = renderAttachmentsHtml(attachments, isMine);

    let statusHtml = "";
    if (isMine) {
      const recipientCount = Number(message.recipient_count || 0);
      const readCount = Number(message.read_count || 0);
      const label =
        message.__localStatus === "sending"
          ? "Envoi…"
          : recipientCount > 0 && readCount > 0
            ? "✓✓ Vu"
            : "✓ Envoyé";
      statusHtml = `<div class="mt-2 text-[11px] text-indigo-100 text-right">${escapeHtml(
        label,
      )}</div>`;
    }

    const actionLabel = isMine
      ? "text-indigo-100 hover:bg-white/20"
      : "text-slate-500 hover:bg-slate-100";
    const actionsHtml =
      `<div class="flex justify-end mt-2">` +
      `<button type="button" class="inline-flex items-center justify-center w-7 h-7 rounded-full ${actionLabel} transition" title="Répondre" aria-label="Répondre" data-action="reply" data-message-id="${escapeAttr(
        String(message.id || ""),
      )}">` +
      `<i class="fa-solid fa-reply text-xs"></i>` +
      `</button>` +
      `</div>`;

    return {
      isMine,
      html:
        headerHtml +
        bodyHtml +
        attachmentsHtml +
        footerHtml +
        statusHtml +
        actionsHtml,
    };
  }

  function findMessageById(messageId) {
    const target = String(messageId || "");
    const messages = getRenderableMessages(state.serverMessages);
    for (const message of messages) {
      if (String(message && message.id ? message.id : "") === target) {
        return message;
      }
    }
    return null;
  }

  function buildMessagesFingerprint(messages) {
    const list = Array.isArray(messages) ? messages : [];
    return JSON.stringify(
      list.map((message) => ({
        id: Number(message && message.id ? message.id : 0),
        sender: Number(
          message && message.sender_user_id ? message.sender_user_id : 0,
        ),
        deadline: String((message && message.deadline_iso) || ""),
        createdAt: String((message && message.created_at_iso) || ""),
        readCount: Number(
          message && message.read_count ? message.read_count : 0,
        ),
        recipientCount: Number(
          message && message.recipient_count ? message.recipient_count : 0,
        ),
        replyToId: Number(
          message && message.reply_to && message.reply_to.id
            ? message.reply_to.id
            : 0,
        ),
        attachments: Array.isArray(message && message.attachments)
          ? message.attachments.map((item) => ({
              id: Number(item && item.id ? item.id : 0),
              mime: String((item && item.mime) || ""),
            }))
          : [],
      })),
    );
  }

  function scrollToMessage(messageId) {
    if (!els.messages || typeof CSS === "undefined" || !CSS.escape) return;
    state.virtualList.anchorMessageId = String(messageId || "");
    renderMessages(getRenderableMessages(state.serverMessages), {
      force: true,
    });

    window.requestAnimationFrame(() => {
      const target = els.messages.querySelector(
        `[data-message-id="${CSS.escape(String(messageId || ""))}"]`,
      );
      if (!target) {
        state.virtualList.anchorMessageId = "";
        return;
      }

      target.scrollIntoView({ behavior: "smooth", block: "center" });
      target.classList.add("message-target-flash");
      window.setTimeout(() => {
        target.classList.remove("message-target-flash");
        state.virtualList.anchorMessageId = "";
      }, 1800);
    });
  }

  function createMessageNode(message) {
    const wrap = document.createElement("div");
    wrap.setAttribute("data-message-id", String(message.id || ""));

    const bubble = document.createElement("div");
    bubble.setAttribute("data-message-bubble", "1");
    wrap.appendChild(bubble);

    updateMessageNode(wrap, message);
    return wrap;
  }

  function updateMessageNode(wrap, message) {
    if (!wrap) return false;

    const signature = buildMessageSignature(message);
    if (wrap.getAttribute("data-message-signature") === signature) {
      return false;
    }

    const bubble = wrap.querySelector("[data-message-bubble]");
    if (!bubble) return false;

    const rendered = buildMessageHtml(message);
    wrap.className =
      "flex " + (rendered.isMine ? "justify-end" : "justify-start");
    bubble.className =
      "max-w-[80%] rounded-2xl pl-4 pr-4 pt-3 pb-2 shadow-sm border " +
      (rendered.isMine
        ? "bg-indigo-600 text-white border-indigo-600"
        : "bg-white text-gray-800 border-gray-200");
    bubble.innerHTML = rendered.html;
    wrap.setAttribute("data-message-signature", signature);
    return true;
  }

  function renderMessages(messages, options) {
    if (!els.messages) return;

    const list = Array.isArray(messages) ? messages : [];
    const opts = options || {};
    const scaffold = ensureMessagesVirtualScaffold();
    const viewport = scaffold.viewport;
    const wasNearBottom = opts.fromScroll ? false : isMessagesNearBottom();
    const previousLastId = getLastRenderedMessageId();
    const range = computeVirtualRange(list);
    const visibleList = range.shouldVirtualize
      ? list.slice(range.start, range.end + 1)
      : list;

    const emptyNode = els.messages.querySelector("[data-empty-messages]");
    if (!list.length) {
      if (viewport) {
        viewport.innerHTML = "";
      }
      if (scaffold.topSpacer) {
        scaffold.topSpacer.style.height = "0px";
      }
      if (scaffold.bottomSpacer) {
        scaffold.bottomSpacer.style.height = "0px";
      }
      els.messages.setAttribute("data-last-rendered-message-id", "");
      if (!emptyNode) {
        const empty = document.createElement("div");
        empty.setAttribute("data-empty-messages", "1");
        empty.className = "text-sm text-gray-500";
        empty.textContent = "Aucun message.";
        els.messages.appendChild(empty);
      }
      return;
    }

    if (emptyNode) {
      emptyNode.remove();
    }

    if (scaffold.topSpacer) {
      scaffold.topSpacer.style.height = range.shouldVirtualize
        ? `${range.topHeight}px`
        : "0px";
    }
    if (scaffold.bottomSpacer) {
      scaffold.bottomSpacer.style.height = range.shouldVirtualize
        ? `${range.bottomHeight}px`
        : "0px";
    }
    els.messages.setAttribute(
      "data-last-rendered-message-id",
      String((list[list.length - 1] && list[list.length - 1].id) || ""),
    );

    const existingNodes = new Map();
    Array.from(
      (viewport && viewport.querySelectorAll("[data-message-id]")) || [],
    ).forEach((node) => {
      existingNodes.set(
        String(node.getAttribute("data-message-id") || ""),
        node,
      );
    });

    visibleList.forEach((message, index) => {
      const id = String(message && message.id ? message.id : "");
      if (!id) return;

      let node = existingNodes.get(id);
      if (!node) {
        node = createMessageNode(message);
      } else {
        updateMessageNode(node, message);
        existingNodes.delete(id);
      }

      const currentNodeAtIndex = viewport
        ? viewport.children[index] || null
        : null;
      if (currentNodeAtIndex !== node) {
        viewport.insertBefore(node, currentNodeAtIndex);
      }
    });

    existingNodes.forEach((node) => {
      node.remove();
    });

    measureVisibleMessageHeights(list, range);

    const lastMessage = list.length ? list[list.length - 1] : null;
    const nextLastId = lastMessage ? String(lastMessage.id || "") : "";
    const shouldScrollToBottom =
      (!state.virtualList.manualBrowsing &&
        !opts.fromScroll &&
        (wasNearBottom || state.virtualList.stickToBottom)) ||
      (previousLastId !== nextLastId &&
        !state.virtualList.manualBrowsing &&
        lastMessage &&
        state.me &&
        Number(lastMessage.sender_user_id) === Number(state.me.id));

    if (shouldScrollToBottom) {
      scrollMessagesToBottom();
    }
  }

  function setTypingIndicator(typingUsers) {
    if (!els.typing) return;
    const list = Array.isArray(typingUsers) ? typingUsers : [];
    if (!list.length) {
      els.typing.classList.add("hidden");
      els.typing.textContent = "";
      return;
    }

    const first = list[0];
    const who = String((first && first.display) || "").trim();
    els.typing.textContent = who
      ? `${who} est en train d’écrire…`
      : "En train d’écrire…";
    els.typing.classList.remove("hidden");
  }

  function setTypeUi() {
    const isTask = els.typeTask && els.typeTask.checked;
    if (els.taskFields) {
      els.taskFields.style.display = isTask ? "block" : "none";
    }
    if (els.taskTitle) {
      els.taskTitle.required = !!isTask;
    }
    if (els.deadline) {
      els.deadline.required = !!isTask;
    }
    if (els.body) {
      els.body.placeholder = isTask
        ? "Détails (optionnel)…"
        : "Écris un message…";
    }

    updateBodyRequired();
    updateComposerActionVisibility();
    publishHubSnapshot();
  }

  function updateBodyRequired() {
    if (!els.body) return;
    const isTask = els.typeTask && els.typeTask.checked;
    if (isTask) {
      els.body.required = false;
      return;
    }
    const hasFiles =
      Array.isArray(state.selectedFiles) && state.selectedFiles.length > 0;
    els.body.required = !hasFiles;
  }

  function updateSendingUi() {
    const isSending = !!(state.sending && state.sending.active);
    const progress = Math.max(
      0,
      Math.min(100, Number((state.sending && state.sending.progress) || 0)),
    );
    const label = String((state.sending && state.sending.label) || "");

    if (els.sendProgress) {
      els.sendProgress.classList.toggle("is-visible", isSending);
    }
    if (els.sendProgressBar) {
      els.sendProgressBar.style.width = `${progress}%`;
    }
    if (els.sendStatus) {
      els.sendStatus.textContent = isSending ? label : "";
      els.sendStatus.classList.toggle("hidden", !isSending || !label);
    }
    if (els.form) {
      els.form.classList.toggle("is-sending", isSending);
    }

    const toggleDisabled = (node, disabled) => {
      if (!node) return;
      node.disabled = disabled;
    };

    toggleDisabled(els.attachBtn, isSending);
    toggleDisabled(els.attachmentsInput, isSending);
    toggleDisabled(els.cameraPhotoBtn, isSending);
    toggleDisabled(els.cameraPhotoInput, isSending);
    toggleDisabled(els.cameraVideoBtn, isSending);
    toggleDisabled(els.cameraVideoInput, isSending);
    toggleDisabled(els.recipientSearch, isSending);
    toggleDisabled(els.typeMessage, isSending);
    toggleDisabled(els.typeTask, isSending);
    toggleDisabled(els.taskTitle, isSending);
    toggleDisabled(els.deadline, isSending);
    toggleDisabled(els.body, isSending);
    toggleDisabled(
      els.voiceBtn,
      isSending || (state.voice && state.voice.isRecording),
    );
    toggleDisabled(els.sendBtn, isSending);

    if (els.sendBtn) {
      els.sendBtn.innerHTML = isSending
        ? '<span class="send-btn-spinner" aria-hidden="true"></span>'
        : '<i class="fa-solid fa-paper-plane"></i>';
    }
  }

  function setSendingState(active, label, progress) {
    state.sending.active = !!active;
    state.sending.label = String(label || "");
    state.sending.progress = Number(progress || 0);
    updateSendingUi();
  }

  function updateSendingProgress(progress, label) {
    state.sending.progress = Math.max(0, Math.min(100, Number(progress || 0)));
    if (label !== undefined) {
      state.sending.label = String(label || "");
    }
    updateSendingUi();
  }

  async function runLockedSend(sendFn) {
    if (state.sending.active) {
      return false;
    }

    setSendingState(true, "Préparation de l’envoi…", 2);
    showError("");

    const previousProgressHandler = apiPostFormData.onProgress;
    apiPostFormData.onProgress = (event) => {
      if (event && event.lengthComputable && event.total > 0) {
        const raw = Math.round((event.loaded / event.total) * 88);
        updateSendingProgress(Math.max(8, raw), "Envoi en cours…");
        return;
      }
      updateSendingProgress(35, "Envoi en cours…");
    };

    try {
      await sendFn();
      updateSendingProgress(100, "Message envoyé");
      await new Promise((resolve) => setTimeout(resolve, 220));
      return true;
    } finally {
      apiPostFormData.onProgress = previousProgressHandler;
      setSendingState(false, "", 0);
      updateComposerActionVisibility();
    }
  }

  function updateComposerActionVisibility() {
    if (!els.voiceBtn || !els.sendBtn) return;

    if (state.sending && state.sending.active) {
      els.voiceBtn.classList.add("is-hidden");
      els.sendBtn.classList.remove("is-hidden");
      return;
    }

    const isTask = !!(els.typeTask && els.typeTask.checked);
    const hasText = !!String((els.body && els.body.value) || "").trim();
    const hasFiles =
      Array.isArray(state.selectedFiles) && state.selectedFiles.length > 0;
    const shouldShowSend = isTask || hasText || hasFiles;

    els.voiceBtn.classList.toggle("is-hidden", shouldShowSend);
    els.sendBtn.classList.toggle("is-hidden", !shouldShowSend);
  }

  async function loadState() {
    const data = await apiGet("api/communication_state.php");
    if (!data || !data.ok) {
      showError((data && data.error) || "Impossible de charger l’état.");
      return;
    }

    showError("");

    state.me = data.me;
    state.users = data.users || [];
    state.usersById = new Map();
    state.users.forEach((u) => {
      state.usersById.set(Number(u.id), {
        id: Number(u.id),
        display: String(u.display || ""),
        mail_pro: String(u.mail_pro || ""),
        photo: String(u.photo || ""),
      });
    });

    state.chantierById = new Map();
    (data.chantiers || []).forEach((c) => {
      state.chantierById.set(Number(c.id), c);
    });

    state.serverMessages = [];
    state.lastMessagesFingerprint = "";
    state.lastServerMessagesFingerprint = "";
    state.pagination.hasMore = false;
    state.pagination.oldestMessageId = 0;
    state.pagination.newestMessageId = 0;
    state.pagination.loadingOlder = false;
    state.virtualList.measuredHeights.clear();
    if (state.virtualList.bottomStickFrame) {
      window.cancelAnimationFrame(state.virtualList.bottomStickFrame);
      state.virtualList.bottomStickFrame = 0;
    }
    if (state.virtualList.autoScrollUnlockTimer) {
      window.clearTimeout(state.virtualList.autoScrollUnlockTimer);
      state.virtualList.autoScrollUnlockTimer = 0;
    }
    state.virtualList.isAutoScrolling = false;
    state.virtualList.lastScrollTop = 0;
    state.virtualList.manualBrowsing = false;
    state.virtualList.anchorMessageId = "";
    state.virtualList.stickToBottom = true;

    // UX WhatsApp: pas de select chantier requis (le chantier vient de la discussion sélectionnée)

    if (els.recipientsSelect) {
      els.recipientsSelect.innerHTML = "";
      state.users.forEach((u) => {
        if (state.me && Number(u.id) === Number(state.me.id)) return;
        const opt = document.createElement("option");
        opt.value = String(u.id);
        opt.textContent = u.display;
        els.recipientsSelect.appendChild(opt);
      });
    }

    syncRecipientsControls();

    // Discussion unique: chantier_id = 0
    state.currentChantierId = 0;
    renderHeader(state.currentChantierId);
    await loadMessages();
    startPolling();
    startTypingPolling();
  }

  async function loadMessages(options) {
    if (
      state.currentChantierId === null ||
      state.currentChantierId === undefined
    )
      return;
    const opts = options || {};
    const beforeMessageId = Number(opts.beforeMessageId || 0);
    const isLatestLoad = beforeMessageId <= 0;

    if (isLatestLoad && state.pagination.latestPromise) {
      return state.pagination.latestPromise;
    }

    const request = (async () => {
      const preserveScroll = !!opts.preserveScroll;
      const previousScrollTop =
        preserveScroll && els.messages ? els.messages.scrollTop : 0;
      const previousScrollHeight =
        preserveScroll && els.messages ? els.messages.scrollHeight : 0;
      const data = await apiPost("api/communication_messages.php", {
        chantier_id: state.currentChantierId,
        limit: state.pagination.pageSize,
        before_message_id: beforeMessageId > 0 ? beforeMessageId : undefined,
      });
      if (!data || !data.ok) {
        showError(
          (data && data.error) || "Impossible de charger les messages.",
        );
        return;
      }
      showError("");
      const nextServerMessages = Array.isArray(data.messages)
        ? data.messages
        : [];
      if (beforeMessageId <= 0) {
        const serverFingerprint = buildMessagesFingerprint(nextServerMessages);
        if (
          !opts.force &&
          serverFingerprint === state.lastServerMessagesFingerprint &&
          state.localMessages.length === 0
        ) {
          updatePaginationState(data);
          return;
        }
        state.lastServerMessagesFingerprint = serverFingerprint;
      }

      state.serverMessages =
        beforeMessageId > 0
          ? mergeMessageLists(nextServerMessages, state.serverMessages)
          : mergeMessageLists(state.serverMessages, nextServerMessages);
      updatePaginationState(data);

      const renderableMessages = getRenderableMessages(state.serverMessages);
      const fingerprint = buildMessagesFingerprint(renderableMessages);
      if (!opts.force && fingerprint === state.lastMessagesFingerprint) {
        return;
      }
      state.lastMessagesFingerprint = fingerprint;
      renderMessages(renderableMessages, opts);
      publishHubSnapshot();

      if (preserveScroll && els.messages) {
        const nextScrollHeight = els.messages.scrollHeight;
        const delta = nextScrollHeight - previousScrollHeight;
        els.messages.scrollTop = previousScrollTop + delta;
      }
    })();

    if (isLatestLoad) {
      state.pagination.latestPromise = request.finally(() => {
        state.pagination.latestPromise = null;
      });
      return state.pagination.latestPromise;
    }

    return request;
  }

  async function loadOlderMessages() {
    if (state.pagination.loadingOlder || !state.pagination.hasMore) {
      return;
    }

    const beforeMessageId = Number(state.pagination.oldestMessageId || 0);
    if (beforeMessageId <= 0) {
      return;
    }

    state.pagination.loadingOlder = true;
    try {
      await loadMessages({
        beforeMessageId,
        preserveScroll: true,
        force: true,
      });
    } finally {
      state.pagination.loadingOlder = false;
    }
  }

  async function loadTyping() {
    const data = await apiGet("api/communication_typing_poll.php");
    if (!data || !data.ok) {
      setTypingIndicator([]);
      return;
    }
    setTypingIndicator(data.typing || []);
  }

  function startPolling() {
    stopPolling();
    if (document.hidden) return;
    state.pollTimer = setTimeout(async () => {
      try {
        await loadMessages();
      } catch (error) {
        // Réessai au prochain cycle.
      } finally {
        startPolling();
      }
    }, 15000);
  }

  function startTypingPolling() {
    stopTypingPolling();
    if (document.hidden || state.selectedRecipientIds.size === 0) return;
    state.typingPollTimer = setTimeout(async () => {
      if (state.typingPollInFlight) return;
      state.typingPollInFlight = true;
      try {
        await loadTyping();
      } catch (error) {
        // Réessai au prochain cycle actif.
      } finally {
        state.typingPollInFlight = false;
        startTypingPolling();
      }
    }, 7000);
  }

  function stopTypingPolling() {
    if (state.typingPollTimer) {
      clearTimeout(state.typingPollTimer);
      state.typingPollTimer = null;
    }
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearTimeout(state.pollTimer);
      state.pollTimer = null;
    }
  }

  async function sendTyping(isTyping) {
    const recipients = Array.from(state.selectedRecipientIds || []).map(String);
    // Si pas de destinataire, on purge le typing côté serveur
    await apiPost("api/communication_typing.php", {
      typing: isTyping ? 1 : 0,
      recipients: recipients,
    });
  }

  function scheduleTypingPing() {
    if (!document.hidden && state.selectedRecipientIds.size > 0 && !state.typingPollTimer) {
      startTypingPolling();
    }
    // throttle simple (2s)
    const now = Date.now();
    if (now - (state.lastTypingSentAt || 0) < 1800) {
      return;
    }
    state.lastTypingSentAt = now;
    state.isTyping = true;
    sendTyping(true).catch(() => {});

    if (state.typingStopTimer) {
      clearTimeout(state.typingStopTimer);
    }
    state.typingStopTimer = setTimeout(() => {
      state.isTyping = false;
      sendTyping(false).catch(() => {});
    }, 2500);
  }

  function stopTyping() {
    state.isTyping = false;
    if (state.typingStopTimer) {
      clearTimeout(state.typingStopTimer);
      state.typingStopTimer = null;
    }
    sendTyping(false).catch(() => {});
  }

  function pickAudioExtension(mime) {
    const m = String(mime || "").toLowerCase();
    if (m.includes("ogg")) return "ogg";
    if (m.includes("opus")) return "ogg";
    if (m.includes("wav")) return "wav";
    if (m.includes("mpeg")) return "mp3";
    if (m.includes("mp4")) return "m4a";
    if (m.includes("webm")) return "webm";
    return "webm";
  }

  function pickSupportedAudioMimeType() {
    if (typeof MediaRecorder === "undefined") {
      return "audio/webm";
    }

    const candidates = [
      "audio/webm;codecs=opus",
      "audio/webm",
      "audio/ogg;codecs=opus",
      "audio/ogg",
      "audio/mp4",
    ];

    for (const candidate of candidates) {
      try {
        if (MediaRecorder.isTypeSupported(candidate)) {
          return candidate;
        }
      } catch (e) {}
    }

    return "audio/webm";
  }

  async function sendVoiceNote(blob) {
    if (state.sending.active) {
      return;
    }
    const recipients = Array.from(state.selectedRecipientIds || []).map(String);
    if (!recipients.length) {
      showError("Sélectionnez au moins un destinataire.");
      return;
    }

    const chantierId = String(state.currentChantierId ?? 0);
    const mime = String(blob && blob.type ? blob.type : "audio/webm");
    const ext = pickAudioExtension(mime);
    const filename = `note_vocale_${Date.now()}.${ext}`;
    const file = new File([blob], filename, { type: mime });
    const localMessageId = addLocalMessage({
      message_type: "message",
      body: "",
      attachments: [createLocalAttachmentFromFile(file)],
      reply_to: state.replyTo,
    });

    const fd = new FormData();
    fd.append("chantier_id", chantierId);
    fd.append("message_type", "message");
    if (state.replyTo && state.replyTo.id) {
      fd.append("reply_to_message_id", String(state.replyTo.id));
    }
    recipients.forEach((r) => fd.append("recipients[]", String(r)));
    fd.append("task_title", "");
    fd.append("deadline", "");
    fd.append("body", "");
    fd.append("attachments[]", file, filename);

    try {
      const ok = await runLockedSend(async () => {
        const res = await apiPostFormData("api/communication_send.php", fd);
        updateSendingProgress(92, "Finalisation…");
        if (!res || !res.ok) {
          throw new Error(
            (res && (res.error || res.message)) || "Envoi impossible.",
          );
        }

        removeLocalMessage(localMessageId);

        if (state.selectedRecipientIds) state.selectedRecipientIds.clear();
        if (els.recipientSearch) els.recipientSearch.value = "";
        syncRecipientsControls();
        hideRecipientSuggestions();
        clearReplyTarget();

        await loadMessages();
      });

      if (ok && els.body) {
        els.body.focus();
      }
    } catch (error) {
      removeLocalMessage(localMessageId);
      showError(error && error.message ? error.message : "Envoi impossible.");
    }
  }

  async function toggleVoiceRecording() {
    if (!els.voiceBtn) return;
    if (state.voice.isRecording) {
      try {
        if (state.voice.recorder && state.voice.recorder.state !== "inactive") {
          state.voice.recorder.stop();
        }
      } catch (e) {}
      return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      showError("Enregistrement audio non supporté par ce navigateur.");
      return;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const preferredMime = pickSupportedAudioMimeType();
      let recorder;
      try {
        recorder = new MediaRecorder(stream, { mimeType: preferredMime });
      } catch (e) {
        recorder = new MediaRecorder(stream);
      }
      state.voice.stream = stream;
      state.voice.recorder = recorder;
      state.voice.chunks = [];

      recorder.ondataavailable = (ev) => {
        if (ev && ev.data && ev.data.size > 0) {
          state.voice.chunks.push(ev.data);
        }
      };

      recorder.onstop = async () => {
        state.voice.isRecording = false;
        try {
          if (els.voiceStatus) els.voiceStatus.textContent = "";
          if (els.voiceBtn) {
            els.voiceBtn.classList.remove("is-recording");
          }
        } catch (e) {}

        try {
          if (state.voice.stream) {
            state.voice.stream.getTracks().forEach((t) => t.stop());
          }
        } catch (e) {}

        const chunks = state.voice.chunks || [];
        state.voice.chunks = [];

        const mime = String(
          blobFromChunksMime(chunks) ||
            (recorder && recorder.mimeType) ||
            preferredMime ||
            "audio/webm",
        );
        const blob = new Blob(chunks, { type: mime });
        if (!blob || blob.size <= 0) {
          return;
        }

        try {
          if (els.voiceStatus)
            els.voiceStatus.textContent = "Envoi de la note vocale…";
          await sendVoiceNote(blob);
        } catch (e) {
          showError("Erreur lors de l’envoi de la note vocale.");
        } finally {
          try {
            if (els.voiceStatus) els.voiceStatus.textContent = "";
          } catch (e) {}
        }
      };

      state.voice.isRecording = true;
      if (els.voiceStatus) {
        els.voiceStatus.textContent = "Enregistrement… cliquez pour arrêter";
      }
      try {
        els.voiceBtn.classList.add("is-recording");
      } catch (e) {}

      recorder.start();
    } catch (e) {
      showError("Impossible d’accéder au micro.");
    }
  }

  function blobFromChunksMime(chunks) {
    const firstChunk =
      Array.isArray(chunks) && chunks.length ? chunks[0] : null;
    return String((firstChunk && firstChunk.type) || "");
  }

  async function onSubmit(ev) {
    ev.preventDefault();
    if (state.sending.active) {
      return;
    }
    showError("");

    stopTyping();

    const chantierId = String(state.currentChantierId ?? 0);
    const messageType =
      els.typeTask && els.typeTask.checked ? "task" : "message";

    const recipients = Array.from(state.selectedRecipientIds || []).map(String);
    if (!recipients.length) {
      showError("Sélectionnez au moins un destinataire.");
      return;
    }

    const fd = new FormData();
    fd.append("chantier_id", chantierId);
    fd.append("message_type", messageType);
    if (state.replyTo && state.replyTo.id) {
      fd.append("reply_to_message_id", String(state.replyTo.id));
    }
    recipients.forEach((r) => fd.append("recipients[]", String(r)));
    fd.append("task_title", els.taskTitle ? els.taskTitle.value : "");
    fd.append("deadline", els.deadline ? els.deadline.value : "");
    fd.append("body", els.body ? els.body.value : "");

    state.selectedFiles.forEach((file) => {
      fd.append("attachments[]", file, file.name);
    });

    try {
      const ok = await runLockedSend(async () => {
        const res = await apiPostFormData("api/communication_send.php", fd);
        updateSendingProgress(92, "Finalisation…");
        if (!res || !res.ok) {
          throw new Error(
            (res && (res.error || res.message)) || "Envoi impossible.",
          );
        }

        if (els.body) els.body.value = "";
        if (els.taskTitle) els.taskTitle.value = "";
        if (els.deadline) els.deadline.value = "";
        if (state.selectedRecipientIds) state.selectedRecipientIds.clear();
        if (els.recipientSearch) els.recipientSearch.value = "";
        syncRecipientsControls();
        hideRecipientSuggestions();
        clearReplyTarget();

        clearSelectedFiles();
        autoResizeComposer();
        await loadMessages();
      });

      if (ok && els.body) {
        els.body.focus();
      }
    } catch (error) {
      showError(error && error.message ? error.message : "Envoi impossible.");
    }
  }

  function init() {
    if (!els.form) return;

    if (els.attachBtn && els.attachmentsInput) {
      els.attachBtn.addEventListener("click", () => {
        openFileInput(els.attachmentsInput);
      });

      els.attachmentsInput.addEventListener("change", () => {
        appendFilesFromInput(els.attachmentsInput);
      });
    }

    if (els.cameraPhotoBtn && els.cameraPhotoInput) {
      els.cameraPhotoBtn.addEventListener("click", () => {
        openFileInput(els.cameraPhotoInput);
      });
      els.cameraPhotoInput.addEventListener("change", () => {
        appendFilesFromInput(els.cameraPhotoInput);
      });
    }

    if (els.cameraVideoBtn && els.cameraVideoInput) {
      els.cameraVideoBtn.addEventListener("click", () => {
        openFileInput(els.cameraVideoInput);
      });
      els.cameraVideoInput.addEventListener("change", () => {
        appendFilesFromInput(els.cameraVideoInput);
      });
    }

    if (els.emojiBtn) {
      els.emojiBtn.addEventListener("click", () => {
        toggleComposerPanel("emoji");
      });
    }

    if (els.stickerBtn) {
      els.stickerBtn.addEventListener("click", () => {
        toggleComposerPanel("sticker");
      });
    }

    if (els.emojiPanel) {
      els.emojiPanel.addEventListener("click", (event) => {
        const trigger = event.target.closest("[data-emoji]");
        if (!trigger || !els.body) return;
        insertTextAtCursor(
          els.body,
          String(trigger.getAttribute("data-emoji") || ""),
        );
      });
    }

    if (els.stickerPanel) {
      els.stickerPanel.addEventListener("click", (event) => {
        const trigger = event.target.closest("[data-sticker]");
        if (!trigger || !els.body) return;
        insertTextAtCursor(
          els.body,
          String(trigger.getAttribute("data-sticker") || ""),
        );
      });
    }

    if (els.replyCancel) {
      els.replyCancel.addEventListener("click", () => {
        clearReplyTarget();
      });
    }

    if (els.messages) {
      els.messages.addEventListener(
        "scroll",
        () => {
          const currentScrollTop = Number(els.messages.scrollTop || 0);
          const nearBottom = isMessagesNearBottom();
          const scrolledUp =
            currentScrollTop + 4 < state.virtualList.lastScrollTop;

          state.virtualList.lastScrollTop = currentScrollTop;

          if (scrolledUp) {
            cancelAutoScrollToBottom();
            state.virtualList.manualBrowsing = true;
          } else if (nearBottom) {
            state.virtualList.manualBrowsing = false;
          }

          if (state.virtualList.isAutoScrolling) {
            state.virtualList.stickToBottom = true;
            return;
          }

          state.virtualList.stickToBottom =
            nearBottom && !state.virtualList.manualBrowsing;
          if (els.messages.scrollTop <= 120) {
            loadOlderMessages().catch(() => {});
          }
          if (nearBottom) {
            return;
          }
          scheduleVirtualRender();
        },
        { passive: true },
      );

      els.messages.addEventListener("click", (event) => {
        const trigger = event.target.closest("[data-action='reply']");
        if (trigger) {
          const messageId = String(
            trigger.getAttribute("data-message-id") || "",
          );
          if (!messageId) return;
          const message = findMessageById(messageId);
          if (!message) return;
          setReplyTarget(message);
          return;
        }

        const jumpTrigger = event.target.closest(
          "[data-action='jump-to-message']",
        );
        if (!jumpTrigger) return;
        const targetMessageId = String(
          jumpTrigger.getAttribute("data-target-message-id") || "",
        );
        if (!targetMessageId) return;
        scrollToMessage(targetMessageId);
      });
    }

    if (els.recipientInput && els.recipientSearch) {
      // Click dans le champ = focus input
      els.recipientInput.addEventListener("click", () => {
        if (isReplyModeLocked()) return;
        state.recipientUi.isFocused = true;
        try {
          els.recipientSearch.focus();
        } catch (e) {}
      });

      els.recipientSearch.addEventListener("input", () => {
        if (isReplyModeLocked()) return;
        state.recipientUi.isFocused = true;
        state.recipientUi.activeIndex = 0;
        renderRecipientSuggestions();
        scheduleTypingPing();
      });

      els.recipientSearch.addEventListener("focus", () => {
        if (isReplyModeLocked()) return;
        state.recipientUi.isFocused = true;
        renderRecipientSuggestions();
      });

      els.recipientSearch.addEventListener("blur", () => {
        state.recipientUi.isFocused = false;
        // délai pour permettre le clic sur une suggestion
        setTimeout(() => hideRecipientSuggestions(), 120);
      });

      els.recipientSearch.addEventListener("keydown", (e) => {
        if (isReplyModeLocked()) {
          e.preventDefault();
          return;
        }
        if (e.key === "ArrowDown") {
          e.preventDefault();
          if (!state.recipientUi.matches.length) {
            renderRecipientSuggestions();
          } else {
            moveRecipientSuggestion(1);
          }
          return;
        }

        if (e.key === "ArrowUp") {
          e.preventDefault();
          if (!state.recipientUi.matches.length) {
            renderRecipientSuggestions();
          } else {
            moveRecipientSuggestion(-1);
          }
          return;
        }

        if (e.key === "Enter") {
          e.preventDefault();
          if (!selectActiveRecipientSuggestion()) {
            renderRecipientSuggestions();
          }
          return;
        }

        if (e.key === "Backspace") {
          const v = String(els.recipientSearch.value || "");
          if (!v) {
            removeLastRecipient();
          }
        }
      });
    }

    if (els.body) {
      autoResizeComposer();
      els.body.addEventListener("input", () => {
        autoResizeComposer();
        scheduleTypingPing();
        updateComposerActionVisibility();
      });
      els.body.addEventListener("keydown", (event) => {
        if (event.key !== "Enter") return;
        if (event.shiftKey || event.altKey || event.ctrlKey || event.metaKey)
          return;
        if (event.isComposing) return;
        if (els.typeTask && els.typeTask.checked) return;
        event.preventDefault();
        if (state.sending.active) return;
        if (els.form) {
          els.form.requestSubmit();
        }
      });
      els.body.addEventListener("blur", () => {
        stopTyping();
      });
    }

    if (els.voiceBtn) {
      els.voiceBtn.addEventListener("click", () => {
        toggleVoiceRecording().catch(() => {
          showError("Erreur lors de l’enregistrement audio.");
        });
      });
    }

    if (els.typeMessage) els.typeMessage.addEventListener("change", setTypeUi);
    if (els.typeTask) els.typeTask.addEventListener("change", setTypeUi);

    document.addEventListener("click", (event) => {
      const target = event.target;
      if (
        els.emojiPanel &&
        els.stickerPanel &&
        !els.emojiPanel.contains(target) &&
        !els.stickerPanel.contains(target) &&
        !(els.emojiBtn && els.emojiBtn.contains(target)) &&
        !(els.stickerBtn && els.stickerBtn.contains(target))
      ) {
        closeComposerPanels();
      }
    });

    setTypeUi();
    renderSelectedFiles();
    renderReplyComposer();
    updateRecipientLockUi();
    updateComposerActionVisibility();
    updateSendingUi();

    els.form.addEventListener("submit", (e) => {
      onSubmit(e).catch(() => showError("Erreur lors de l’envoi."));
    });

    loadState().catch(() => showError("Erreur de chargement."));

    window.addEventListener("resize", () => {
      scheduleVirtualRender();
    });

    window.addEventListener("beforeunload", () => {
      try {
        stopTyping();
      } catch (e) {}
    });

    document.addEventListener("visibilitychange", () => {
      if (document.hidden) {
        stopPolling();
        stopTypingPolling();
        return;
      }
      loadMessages().catch(() => {});
      startPolling();
      if (state.selectedRecipientIds.size > 0) {
        loadTyping().catch(() => {});
        startTypingPolling();
      }
    });
  }

  document.addEventListener("DOMContentLoaded", init);
})();
