(function () {
  "use strict";

  if (window.__fineaPopupNotificationsLoaded) return;
  window.__fineaPopupNotificationsLoaded = true;

  function getBaseUrl() {
    const scripts = document.querySelectorAll("script[src]");
    for (const script of scripts) {
      if (script.src.includes("assets/js/finea-popup-notifications.js")) {
        return script.src.split("assets/js/finea-popup-notifications.js")[0];
      }
    }

    if (window.location.pathname.includes("/banamur_project_app/")) {
      return window.location.origin + "/banamur_project_app/";
    }

    return window.location.origin + "/";
  }

  const BASE_URL = getBaseUrl();

  function escapeHtml(value) {
    return String(value || "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function typeLabel(type) {
    return (
      {
        nouveau_module: "Nouveau module",
        nouvelle_fonctionnalite: "Fonctionnalité",
        correction: "Correction",
        maintenance: "Maintenance",
        information: "Information",
        mise_a_jour: "Mise à jour",
        sondage: "Sondage",
      }[type] || "Information"
    );
  }

  function typeIcon(type) {
    return (
      {
        nouveau_module: "fa-cubes",
        nouvelle_fonctionnalite: "fa-wand-magic-sparkles",
        correction: "fa-screwdriver-wrench",
        maintenance: "fa-gears",
        information: "fa-circle-info",
        mise_a_jour: "fa-rotate",
        sondage: "fa-square-poll-vertical",
      }[type] || "fa-circle-info"
    );
  }

  function hasSuccessfulReadValidation(notification) {
    return !!(
      notification.read_validation &&
      Number(notification.read_validation.is_success || 0) === 1
    );
  }

  function requiresPendingReadValidation(notification) {
    return Number(notification.requires_read_validation || 0) === 1 && !hasSuccessfulReadValidation(notification);
  }

  function buildActions(notification) {
    const actions = Array.isArray(notification.actions) ? notification.actions : [];

    if (actions.length === 0) {
      return `
        <button type="button" class="finea-popup-action is-primary"
                data-action-id=""
                data-action-type="ack"
                data-action-value="">
          <i class="fas fa-check"></i>
          J’ai compris
        </button>
      `;
    }

    return actions
      .map(function (action) {
        return `
          <button type="button"
                  class="finea-popup-action ${Number(action.is_primary) === 1 ? "is-primary" : ""}"
                  data-action-id="${escapeHtml(action.id)}"
                  data-action-type="${escapeHtml(action.action_type || "ack")}"
                  data-action-value="${escapeHtml(action.action_value || "")}">
            ${escapeHtml(action.label)}
          </button>
        `;
      })
      .join("");
  }

  function buildValidationQuestions(notification) {
    if (Number(notification.requires_read_validation || 0) !== 1) {
      return "";
    }

    if (hasSuccessfulReadValidation(notification)) {
      return `
        <div class="finea-popup-validation is-success">
          <div class="finea-popup-validation-success">
            <i class="fas fa-circle-check"></i>
            Lecture déjà validée. Vous pouvez confirmer votre action.
          </div>
        </div>
      `;
    }

    const questions = Array.isArray(notification.questions) ? notification.questions : [];
    if (questions.length === 0) {
      return `
        <div class="finea-popup-validation is-warning">
          <div class="finea-popup-validation-title">
            <i class="fas fa-triangle-exclamation"></i>
            QCM non configuré
          </div>
          <p class="finea-popup-validation-help">
            Cette notification exige une validation, mais aucune question n’a été transmise.
          </p>
        </div>
      `;
    }

    return `
      <div class="finea-popup-validation">
        <div class="finea-popup-validation-title">
          <i class="fas fa-clipboard-check"></i>
          Validation de lecture obligatoire
        </div>
        <p class="finea-popup-validation-help">
          Lisez le message, cochez la bonne réponse, puis utilisez le bouton <strong>Valider le QCM</strong> en bas de la popup. Les boutons d’action apparaîtront seulement après validation.
        </p>

        ${questions
          .map(function (question, qIndex) {
            const choices = Array.isArray(question.choices) ? question.choices : [];
            return `
              <div class="finea-popup-question" data-question-id="${escapeHtml(question.id)}">
                <div class="finea-popup-question-text">
                  ${qIndex + 1}. ${escapeHtml(question.question_text)}
                </div>
                <div class="finea-popup-choices">
                  ${choices
                    .map(function (choice) {
                      return `
                        <label class="finea-popup-choice">
                          <input type="radio"
                                 name="finea_popup_question_${escapeHtml(question.id)}"
                                 value="${escapeHtml(choice.id)}">
                          <span>${escapeHtml(choice.choice_text)}</span>
                        </label>
                      `;
                    })
                    .join("")}
                </div>
              </div>
            `;
          })
          .join("")}
      </div>
    `;
  }

  function buildActionZone(notification) {
    if (requiresPendingReadValidation(notification)) {
      return `
        <div class="finea-popup-action-zone is-validation-step">
          <div class="finea-popup-required">
            <i class="fas fa-lock"></i>
            Validez d’abord le QCM pour afficher les boutons d’action.
          </div>
          <div class="finea-popup-error" role="alert" hidden></div>
          <button type="button" class="finea-popup-validate-reading is-primary">
            <i class="fas fa-check-double"></i>
            Valider le QCM
          </button>
        </div>
      `;
    }

    return `
      <div class="finea-popup-action-zone">
        <div class="finea-popup-required">
          <i class="fas fa-lock"></i>
          Un choix est obligatoire pour confirmer la prise de connaissance.
        </div>
        <div class="finea-popup-error" role="alert" hidden></div>
        <div class="finea-popup-actions">
          ${buildActions(notification)}
        </div>
      </div>
    `;
  }

  async function markAsRead(notificationId) {
    try {
      const formData = new FormData();
      formData.append("notification_id", notificationId);

      const response = await fetch(BASE_URL + "ajax/mark_popup_notification_read.php", {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      });
      return response.ok;
    } catch (e) {
      console.warn("Notification popup: lecture non enregistrée.", e);
      return false;
    }
  }

  async function submitReadValidation(notificationId, card) {
    const formData = new FormData();
    formData.append("notification_id", notificationId);

    card.querySelectorAll(".finea-popup-question").forEach(function (question) {
      const questionId = question.dataset.questionId || "";
      const selected = question.querySelector("input[type='radio']:checked");
      if (questionId) {
        formData.append("answers[" + questionId + "]", selected ? selected.value : "");
      }
    });

    const response = await fetch(BASE_URL + "ajax/submit_popup_notification_read_validation.php", {
      method: "POST",
      body: formData,
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    });

    const rawResponse = await response.text();
    let data;
    try {
      data = JSON.parse(rawResponse);
    } catch (error) {
      throw new Error(response.ok
        ? "Le serveur a renvoyé une réponse illisible. Rechargez la page puis réessayez."
        : "La validation est momentanément indisponible (HTTP " + response.status + "). Réessayez.");
    }

    if (!data.success) {
      throw new Error(data.message || "Validation refusée.");
    }

    return data;
  }

  async function submitResponse(notificationId, actionId, button) {
    try {
      const formData = new FormData();
      formData.append("notification_id", notificationId);
      formData.append("action_id", actionId || "");

      const response = await fetch(BASE_URL + "ajax/submit_popup_notification_response.php", {
        method: "POST",
        body: formData,
        credentials: "same-origin",
      });

      const data = await response.json().catch(function () {
        return { success: false };
      });

      if (!data.success) {
        throw new Error(data.message || "Réponse refusée.");
      }
      return data;
    } catch (e) {
      console.warn("Notification popup: réponse non enregistrée.", e);
      const card = button.closest(".finea-popup-card");
      const errorBox = card ? card.querySelector(".finea-popup-error") : null;
      if (errorBox) {
        errorBox.textContent = e && e.message ? e.message : "La réponse n’a pas pu être enregistrée. Veuillez réessayer.";
        errorBox.hidden = false;
      }
      return null;
    }
  }

  function showActionButtons(overlay, notification) {
    const zone = overlay.querySelector(".finea-popup-action-zone");
    const validationBox = overlay.querySelector(".finea-popup-validation");

    if (validationBox) {
      validationBox.classList.add("is-success");
      validationBox.innerHTML = `
        <div class="finea-popup-validation-success">
          <i class="fas fa-circle-check"></i>
          QCM validé. Vous pouvez maintenant cliquer sur le bouton d’action.
        </div>
      `;
    }

    if (zone) {
      zone.classList.remove("is-validation-step");
      zone.innerHTML = `
        <div class="finea-popup-required">
          <i class="fas fa-unlock"></i>
          Lecture validée. Confirmez maintenant votre prise de connaissance.
        </div>
        <div class="finea-popup-error" role="alert" hidden></div>
        <div class="finea-popup-actions">
          ${buildActions(notification)}
        </div>
      `;
      bindActionButtons(overlay, notification);
    }
  }

  function bindValidationButton(overlay, notification) {
    const validateReadingBtn = overlay.querySelector(".finea-popup-validate-reading");
    if (!validateReadingBtn) return;

    validateReadingBtn.addEventListener("click", async function () {
      const card = overlay.querySelector(".finea-popup-card");
      const errorBox = overlay.querySelector(".finea-popup-error");

      validateReadingBtn.disabled = true;
      validateReadingBtn.classList.add("is-loading");
      if (errorBox) errorBox.hidden = true;

      try {
        const result = await submitReadValidation(notification.id, card);

        if (!result.passed) {
          const attemptsText = result.attempts_count ? " Tentative n°" + result.attempts_count + "." : "";
          throw new Error(
            (result.message || "Réponse incorrecte. Relisez le message, choisissez la bonne réponse, puis cliquez à nouveau sur Valider le QCM.") +
              attemptsText
          );
        }

        showActionButtons(overlay, notification);
      } catch (e) {
        if (errorBox) {
          errorBox.textContent = e && e.message
            ? e.message
            : "Validation impossible. Relisez la notification puis cliquez sur Valider le QCM.";
          errorBox.hidden = false;
          errorBox.scrollIntoView({ behavior: "smooth", block: "nearest" });
        }
      } finally {
        validateReadingBtn.disabled = false;
        validateReadingBtn.classList.remove("is-loading");
      }
    });
  }

  function bindActionButtons(overlay, notification) {
    overlay.querySelectorAll(".finea-popup-action").forEach(function (button) {
      button.addEventListener("click", async function () {
        const actionId = button.dataset.actionId || "";
        const buttons = overlay.querySelectorAll(".finea-popup-action");
        const errorBox = overlay.querySelector(".finea-popup-error");

        buttons.forEach(function (item) {
          item.disabled = true;
        });
        button.classList.add("is-loading");
        if (errorBox) errorBox.hidden = true;

        const result = await submitResponse(notification.id, actionId, button);
        if (!result) {
          buttons.forEach(function (item) {
            item.disabled = false;
          });
          button.classList.remove("is-loading");
          return;
        }

        overlay.remove();
        document.body.classList.remove("finea-popup-open");

        if (result.action_type === "link" && result.action_value !== "") {
          window.location.href = result.action_value;
          return;
        }

        window.setTimeout(fetchPendingNotification, 180);
      });
    });
  }

  function buildModal(notification) {
    const overlay = document.createElement("div");
    overlay.className = "finea-popup-overlay";

    overlay.innerHTML = `
      <div class="finea-popup-card finea-popup-priority-${escapeHtml(notification.priority || "normal")}">
        <div class="finea-popup-header">
          <div class="finea-popup-icon">
            <i class="fas ${typeIcon(notification.type)}"></i>
          </div>

          <div>
            <div class="finea-popup-badge">${typeLabel(notification.type)}</div>
            <h2>${escapeHtml(notification.title)}</h2>
          </div>
        </div>

        ${notification.short_message ? `<p class="finea-popup-short">${escapeHtml(notification.short_message)}</p>` : ""}

        <div class="finea-popup-body">
          ${escapeHtml(notification.body).replaceAll("\n", "<br>")}
        </div>

        ${buildValidationQuestions(notification)}
        ${buildActionZone(notification)}
      </div>
    `;

    document.body.appendChild(overlay);
    document.body.classList.add("finea-popup-open");
    if(window.FineaSpeech){window.FineaSpeech.mount(overlay.querySelector(".finea-popup-card"),notification);window.FineaSpeech.notify(notification,"popup");}
    markAsRead(notification.id);

    bindValidationButton(overlay, notification);
    bindActionButtons(overlay, notification);
  }

  async function fetchPendingNotification() {
    try {
      const response = await fetch(BASE_URL + "ajax/get_pending_popup_notifications.php", {
        method: "GET",
        credentials: "same-origin",
      });

      const data = await response.json();

      if (data.success && data.notification) {
        buildModal(data.notification);
      }
    } catch (e) {
      console.warn("Notification popup: chargement impossible.", e);
    }
  }

  function injectStyles() {
    const style = document.createElement("style");
    style.innerHTML = `
      .finea-popup-overlay {
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(8px);
        display: flex;
        align-items: flex-start;
        justify-content: center;
        padding: 20px;
        overflow-y: auto;
        overscroll-behavior: contain;
      }

      body.finea-popup-open { overflow: hidden; }

      .finea-popup-card {
        position: relative;
        width: min(620px, 100%);
        max-height: calc(100dvh - 40px);
        overflow-y: auto;
        box-sizing: border-box;
        background: #ffffff;
        border-radius: 26px;
        box-shadow: 0 30px 80px rgba(15, 23, 42, 0.25);
        padding: 30px;
        animation: fineaPopupIn 0.25s ease-out;
        border-top: 6px solid #4f46e5;
        scrollbar-gutter: stable;
      }

      .finea-popup-priority-high { border-top-color: #f97316; }
      .finea-popup-priority-critical { border-top-color: #dc2626; }

      .finea-popup-header {
        display: flex;
        gap: 16px;
        align-items: flex-start;
        padding-right: 0;
      }

      .finea-popup-icon {
        width: 56px;
        height: 56px;
        border-radius: 18px;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        flex: none;
      }

      .finea-popup-badge {
        display: inline-flex;
        align-items: center;
        padding: 5px 12px;
        border-radius: 999px;
        background: #eef2ff;
        color: #3730a3;
        font-size: 12px;
        font-weight: 700;
        margin-bottom: 8px;
      }

      .finea-popup-card h2 {
        margin: 0;
        color: #0f172a;
        font-size: 25px;
        line-height: 1.2;
        font-weight: 800;
      }

      .finea-popup-short {
        margin: 20px 0 0;
        color: #475569;
        font-size: 16px;
        font-weight: 600;
      }

      .finea-popup-body {
        margin-top: 18px;
        color: #334155;
        line-height: 1.7;
        font-size: 15px;
        max-height: 300px;
        overflow-y: auto;
      }

      .finea-popup-validation {
        margin-top: 20px;
        padding: 16px;
        border-radius: 18px;
        background: #ecfdf5;
        border: 1px solid #bbf7d0;
      }

      .finea-popup-validation.is-warning {
        background: #fffbeb;
        border-color: #fde68a;
      }

      .finea-popup-validation.is-success {
        background: #ecfdf5;
        border-color: #86efac;
      }

      .finea-popup-validation-title {
        display: flex;
        align-items: center;
        gap: 9px;
        color: #065f46;
        font-size: 15px;
        font-weight: 800;
      }

      .finea-popup-validation-help {
        margin: 8px 0 14px;
        color: #047857;
        font-size: 13px;
        font-weight: 600;
      }

      .finea-popup-question {
        margin-top: 14px;
        padding: 13px;
        background: #ffffff;
        border: 1px solid #d1fae5;
        border-radius: 14px;
      }

      .finea-popup-question-text {
        color: #0f172a;
        font-size: 14px;
        font-weight: 800;
        margin-bottom: 10px;
      }

      .finea-popup-choices { display: grid; gap: 8px; }

      .finea-popup-choice {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 10px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #f8fafc;
        color: #334155;
        font-size: 14px;
        font-weight: 650;
        cursor: pointer;
      }

      .finea-popup-choice:hover { background: #f1f5f9; }
      .finea-popup-choice input { margin-top: 2px; }

      .finea-popup-validation-success {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #065f46;
        font-weight: 800;
      }

      .finea-popup-action-zone {
        position: sticky;
        bottom: -30px;
        margin: 28px -30px -30px;
        padding: 16px 30px 30px;
        background: linear-gradient(180deg, rgba(255,255,255,0.88), #ffffff 35%);
        border-top: 1px solid #e2e8f0;
        z-index: 2;
      }

      .finea-popup-actions {
        margin-top: 12px;
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        justify-content: flex-end;
      }

      .finea-popup-required {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 11px 13px;
        border: 1px solid #fde68a;
        border-radius: 12px;
        background: #fffbeb;
        color: #92400e;
        font-size: 13px;
        font-weight: 700;
      }

      .finea-popup-error {
        margin-top: 12px;
        padding: 11px 13px;
        border: 1px solid #fecaca;
        border-radius: 12px;
        background: #fef2f2;
        color: #991b1b;
        font-size: 13px;
        font-weight: 700;
      }

      .finea-popup-action,
      .finea-popup-validate-reading {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 13px 18px;
        background: #ffffff;
        color: #334155;
        font-weight: 800;
        cursor: pointer;
        transition: all .2s ease;
      }

      .finea-popup-validate-reading {
        width: 100%;
        margin-top: 12px;
        border: none;
        background: linear-gradient(135deg, #059669, #10b981);
        color: #fff;
        box-shadow: 0 14px 30px rgba(5, 150, 105, 0.22);
      }

      .finea-popup-action:hover,
      .finea-popup-validate-reading:hover {
        transform: translateY(-1px);
      }

      .finea-popup-action.is-primary {
        border: none;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #fff;
        box-shadow: 0 14px 30px rgba(79, 70, 229, 0.28);
      }

      .finea-popup-action:disabled,
      .finea-popup-validate-reading:disabled {
        opacity: .65;
        cursor: not-allowed;
      }

      .finea-popup-action.is-loading::after,
      .finea-popup-validate-reading.is-loading::after {
        content: "";
        display: inline-block;
        width: 14px;
        height: 14px;
        margin-left: 8px;
        border: 2px solid currentColor;
        border-right-color: transparent;
        border-radius: 50%;
        vertical-align: -2px;
        animation: fineaPopupSpin .7s linear infinite;
      }

      @keyframes fineaPopupIn {
        from { opacity: 0; transform: translateY(20px) scale(0.98); }
        to { opacity: 1; transform: translateY(0) scale(1); }
      }

      @keyframes fineaPopupSpin { to { transform: rotate(360deg); } }

      @media (max-width: 640px) {
        .finea-popup-overlay { padding: 12px; }
        .finea-popup-card {
          max-height: calc(100dvh - 24px);
          padding: 24px;
          border-radius: 22px;
        }
        .finea-popup-header { flex-direction: column; }
        .finea-popup-card h2 { font-size: 21px; }
        .finea-popup-action-zone {
          bottom: -24px;
          margin: 24px -24px -24px;
          padding: 14px 24px 24px;
        }
        .finea-popup-actions { justify-content: stretch; }
        .finea-popup-action { width: 100%; }
      }
    `;

    document.head.appendChild(style);
  }

  document.addEventListener("DOMContentLoaded", function () {
    const path = window.location.pathname.toLowerCase();

    if (path.includes("login") || path.includes("logout") || path.includes("connexion")) {
      return;
    }

    injectStyles();
    fetchPendingNotification();
  });
})();

