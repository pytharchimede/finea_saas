(function () {
  function ready(fn) {
    if (document.readyState !== "loading") fn();
    else document.addEventListener("DOMContentLoaded", fn);
  }

  ready(function () {
    const form = document.getElementById("ai-assistant-form");
    if (!form) return;

    const existingRequestSelect = document.getElementById(
      "ai-existing-request-select",
    );
    const requestTypeDetected = document.getElementById(
      "ai-request-type-detected",
    );
    const requestIdInput = document.getElementById("ai-request-id");
    const resultText = document.getElementById("ai-result-text");
    const messageBox = document.getElementById("ai-message");
    const generateButton = document.getElementById("ai-generate-button");
    const improveButton = document.getElementById("ai-improve-button");
    const copyButton = document.getElementById("ai-copy-button");
    const saveTemplateButton = document.getElementById(
      "ai-save-template-button",
    );
    const templateTitle = document.getElementById("ai-template-title");
    const feedbackButton = document.getElementById("ai-feedback-button");
    const feedbackPanel = document.getElementById("ai-feedback-panel");
    const submitFeedbackButton = document.getElementById(
      "ai-submit-feedback-button",
    );
    const feedbackRating = document.getElementById("ai-feedback-rating");
    const feedbackAccepted = document.getElementById("ai-feedback-accepted");
    const feedbackComment = document.getElementById("ai-feedback-comment");

    function showMessage(message, isError) {
      if (!messageBox) return;
      messageBox.classList.remove(
        "hidden",
        "border-emerald-200",
        "bg-emerald-50",
        "text-emerald-800",
        "border-rose-200",
        "bg-rose-50",
        "text-rose-800",
      );
      messageBox.classList.add(
        isError ? "border-rose-200" : "border-emerald-200",
      );
      messageBox.classList.add(isError ? "bg-rose-50" : "bg-emerald-50");
      messageBox.classList.add(isError ? "text-rose-800" : "text-emerald-800");
      messageBox.textContent = message || "";
    }

    function setBusy(button, busy, label) {
      if (!button) return;
      if (!button.dataset.originalText) {
        button.dataset.originalText = button.textContent;
      }
      button.disabled = !!busy;
      button.classList.toggle("opacity-60", !!busy);
      button.textContent = busy ? label : button.dataset.originalText;
    }

    function buildFormData(action) {
      const data = new FormData(form);
      data.set("action", action);
      data.set("current_text", resultText ? resultText.value : "");
      return data;
    }

    function setFieldValue(name, value) {
      const field = form.querySelector('[name="' + name + '"]');
      if (!field || typeof value === "undefined" || value === null) return;
      field.value = String(value);
      field.dispatchEvent(new Event("change", { bubbles: true }));
    }

    function applyExistingRequestContext(context) {
      if (!context || typeof context !== "object") return;

      [
        "module",
        "task_type",
        "tone",
        "role_demandeur",
        "nom_employe",
        "poste",
        "service",
        "date",
        "motif",
        "periode",
        "responsable",
        "entreprise",
        "montant",
        "matricule",
        "request_type",
        "request_title",
        "heures",
        "date_effective",
      ].forEach(function (name) {
        if (Object.prototype.hasOwnProperty.call(context, name)) {
          setFieldValue(name, context[name]);
        }
      });

      if (Object.prototype.hasOwnProperty.call(context, "input_text")) {
        setFieldValue("input_text", context.input_text);
      }
      if (requestIdInput) requestIdInput.value = "";
      if (requestTypeDetected) {
        const requestType = context.request_type || "";
        requestTypeDetected.textContent = requestType
          ? "Type metier detecte : " + requestType
          : "";
        requestTypeDetected.classList.toggle("hidden", !requestType);
      }
      showMessage(
        "Demande chargee. Vous pouvez ajuster les champs avant generation.",
        false,
      );
    }

    function postAction(action, button, busyLabel) {
      setBusy(button, true, busyLabel || "Traitement...");
      return fetch("index.php", {
        method: "POST",
        body: buildFormData(action),
        headers: { Accept: "application/json" },
      })
        .then(function (response) {
          return response.json().then(function (payload) {
            if (!response.ok || !payload.success) {
              const error = new Error(payload.message || "Action impossible.");
              error.payload = payload;
              throw error;
            }
            return payload;
          });
        })
        .finally(function () {
          setBusy(button, false);
        });
    }

    if (generateButton) {
      generateButton.addEventListener("click", function () {
        postAction("generate", generateButton, "Generation...")
          .then(function (payload) {
            if (resultText) resultText.value = payload.generated_text || "";
            if (requestIdInput) requestIdInput.value = payload.request_id || "";
            showMessage(
              "Texte genere avec le modele : " +
                (payload.template_title || "interne"),
              false,
            );
          })
          .catch(function (error) {
            showMessage(error.message || "Generation impossible.", true);
          });
      });
    }

    if (existingRequestSelect) {
      existingRequestSelect.addEventListener("change", function () {
        const option =
          existingRequestSelect.options[existingRequestSelect.selectedIndex];
        if (!option || !option.dataset.context) {
          if (requestTypeDetected) {
            requestTypeDetected.classList.add("hidden");
            requestTypeDetected.textContent = "";
          }
          showMessage("Saisie libre activee.", false);
          return;
        }
        try {
          applyExistingRequestContext(JSON.parse(option.dataset.context));
        } catch (error) {
          showMessage("Impossible de charger cette demande.", true);
        }
      });
    }

    if (improveButton) {
      improveButton.addEventListener("click", function () {
        postAction("improve_external", improveButton, "Amelioration...")
          .then(function (payload) {
            if (resultText)
              resultText.value = payload.generated_text || resultText.value;
            if (requestIdInput && payload.request_id)
              requestIdInput.value = payload.request_id;
            showMessage(
              "Texte ameliore avec " + (payload.provider || "IA externe") + ".",
              false,
            );
          })
          .catch(function (error) {
            if (error.payload && error.payload.generated_text && resultText) {
              resultText.value = error.payload.generated_text;
            }
            showMessage(error.message || "Amelioration IA indisponible.", true);
          });
      });
    }

    if (copyButton) {
      copyButton.addEventListener("click", function () {
        const value = resultText ? resultText.value : "";
        if (!value) {
          showMessage("Aucun texte a copier.", true);
          return;
        }
        if (navigator.clipboard) {
          navigator.clipboard
            .writeText(value)
            .then(function () {
              showMessage("Texte copie.", false);
            })
            .catch(function () {
              resultText.select();
              document.execCommand("copy");
              showMessage("Texte copie.", false);
            });
          return;
        }
        resultText.select();
        document.execCommand("copy");
        showMessage("Texte copie.", false);
      });
    }

    if (saveTemplateButton) {
      saveTemplateButton.addEventListener("click", function () {
        if (!resultText || !resultText.value.trim()) {
          showMessage("Le resultat est vide.", true);
          return;
        }
        const hiddenTitle = document.createElement("input");
        hiddenTitle.type = "hidden";
        hiddenTitle.name = "title";
        hiddenTitle.value = templateTitle ? templateTitle.value : "";
        form.appendChild(hiddenTitle);
        postAction("save_template", saveTemplateButton, "Enregistrement...")
          .then(function (payload) {
            showMessage(payload.message || "Modele enregistre.", false);
          })
          .catch(function (error) {
            showMessage(error.message || "Enregistrement impossible.", true);
          })
          .finally(function () {
            hiddenTitle.remove();
          });
      });
    }

    if (feedbackButton && feedbackPanel) {
      feedbackButton.addEventListener("click", function () {
        feedbackPanel.classList.toggle("hidden");
      });
    }

    if (submitFeedbackButton) {
      submitFeedbackButton.addEventListener("click", function () {
        if (!requestIdInput || !requestIdInput.value) {
          showMessage(
            "Generez ou selectionnez un texte avant le feedback.",
            true,
          );
          return;
        }
        const extras = [
          ["rating", feedbackRating ? feedbackRating.value : ""],
          ["accepted", feedbackAccepted && feedbackAccepted.checked ? "1" : ""],
          ["corrected_text", resultText ? resultText.value : ""],
          ["comment", feedbackComment ? feedbackComment.value : ""],
        ];
        const added = extras.map(function (pair) {
          const input = document.createElement("input");
          input.type = "hidden";
          input.name = pair[0];
          input.value = pair[1];
          form.appendChild(input);
          return input;
        });
        postAction("feedback", submitFeedbackButton, "Envoi...")
          .then(function (payload) {
            showMessage(payload.message || "Feedback enregistre.", false);
            if (feedbackPanel) feedbackPanel.classList.add("hidden");
          })
          .catch(function (error) {
            showMessage(error.message || "Feedback impossible.", true);
          })
          .finally(function () {
            added.forEach(function (node) {
              node.remove();
            });
          });
      });
    }

    document.querySelectorAll(".ai-history-item").forEach(function (button) {
      button.addEventListener("click", function () {
        if (resultText) resultText.value = button.dataset.text || "";
        if (requestIdInput)
          requestIdInput.value = button.dataset.requestId || "";
        showMessage("Generation chargee depuis l historique.", false);
      });
    });
  });
})();
