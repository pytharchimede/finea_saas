document.addEventListener("DOMContentLoaded", function () {
  // Constantes globales
  const progressBar = document.getElementById("progress-bar");
  const steps = document.querySelectorAll(".setup-content");
  const totalSteps = steps.length;
  let currentStep = 0;
  const form = document.getElementById("form_fiche");

  // Sauvegarde des champs requis initiaux et sync en fonction de l'étape visible
  const allFormControls = Array.from(
    document.querySelectorAll(
      "#form_fiche input, #form_fiche select, #form_fiche textarea",
    ),
  );

  allFormControls.forEach((el) => {
    if (el.hasAttribute("required")) {
      el.dataset.requiredSaved = "1";
    }
  });

  function syncRequiredWithVisibility() {
    // Retirer required de tous les champs par défaut
    allFormControls.forEach((el) => {
      el.removeAttribute("required");
    });
    // Réactiver required uniquement pour les champs de l'étape active qui étaient requis à l'origine
    const active = document.querySelector(".setup-content.active");
    if (!active) return;
    const activeRequired = active.querySelectorAll("[data-required-saved='1']");
    activeRequired.forEach((el) => el.setAttribute("required", "required"));
  }

  function getSelectedLigneDebourseIds() {
    const sel = document.getElementById("precision_select");
    if (!sel) return [];
    const ids = Array.from(sel.selectedOptions || [])
      .map((o) => parseInt(o.value, 10))
      .filter((n) => Number.isFinite(n) && n > 0);
    // dédoublonnage (au cas où)
    return Array.from(new Set(ids));
  }

  function parseNumberFCFA(v) {
    if (v === null || v === undefined) return 0;
    const n = parseFloat(String(v).replace(/[^\d.-]/g, ""));
    return Number.isFinite(n) ? n : 0;
  }

  function formatFCFA(n) {
    const x = Number.isFinite(Number(n)) ? Number(n) : 0;
    return x.toLocaleString("fr-FR") + " FCFA";
  }

  // Cache des détails par ligne de déboursé (montant prévu / décaissé / restant)
  const ligneDebourseDetailsCache = new Map();

  function fetchLigneDebourseDetails(ligneDebourseId) {
    const id = parseInt(ligneDebourseId, 10);
    if (!Number.isFinite(id) || id <= 0) {
      return Promise.reject(new Error("ID ligne déboursé invalide"));
    }
    if (ligneDebourseDetailsCache.has(id)) {
      return Promise.resolve(ligneDebourseDetailsCache.get(id));
    }

    return fetch("request/get_ligne_debourse_details.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "ligne_debourse_id=" + encodeURIComponent(String(id)),
    })
      .then((r) => {
        if (!r.ok) throw new Error("Erreur réseau");
        return r.json();
      })
      .then((data) => {
        if (!data || !data.success) {
          throw new Error((data && data.message) || "Erreur détails ligne");
        }

        const mapped = {
          id,
          ligne: data.ligne || {},
          montant_prevu: parseNumberFCFA(data.montant_prevu),
          montant_decaisse: parseNumberFCFA(data.montant_decaisse),
          montant_restant: parseNumberFCFA(data.montant_restant),
        };
        ligneDebourseDetailsCache.set(id, mapped);
        return mapped;
      });
  }

  function setMontantStepMode(isMultiMode) {
    const singleBlock = document.getElementById("montant-single-block");
    const multiBlock = document.getElementById("montant-multi-block");
    if (singleBlock) singleBlock.style.display = isMultiMode ? "none" : "block";
    if (multiBlock) multiBlock.style.display = isMultiMode ? "block" : "none";

    // UX: à l'étape 3, on bloque le bouton "Suivant" tant que les montants multi ne sont pas tous saisis
    // (on l'ouvrira via checkDepassementsMulti() dès que tout est OK)
    if (isMultiMode) {
      applyStep3NextButtonState({
        hasOverrun: false,
        hasEmpty: true,
        total: 0,
      });
    } else {
      applyStep3NextButtonState(null);
    }
  }

  function isMontantMultiModeVisible() {
    const multiBlock = document.getElementById("montant-multi-block");
    if (!multiBlock) return false;
    return multiBlock.style.display !== "none";
  }

  function applyStep3NextButtonState(st) {
    const nextBtn = document.getElementById("first_next_button_3");
    if (!nextBtn) return;

    // Si on n'est pas en mode multi-montants, ne pas bloquer le bouton.
    if (!isMontantMultiModeVisible()) {
      nextBtn.disabled = false;
      return;
    }

    // Si le tableau n'est pas encore rendu, on bloque.
    const tbody = document.querySelector("#table-montants-lignes tbody");
    const rowCount = tbody
      ? tbody.querySelectorAll("tr[data-ligne-id]").length
      : 0;
    renderRecapBudgetTable();
    if (rowCount <= 0) {
      nextBtn.disabled = true;
      return;
    }

    const hasEmpty = !!(st && st.hasEmpty);
    const total = st && Number.isFinite(st.total) ? st.total : 0;
    // Règle métier: un dépassement budgétaire ne doit PAS bloquer l'avancement.
    // On bloque uniquement si des champs requis sont manquants (montants/détails) ou total = 0.
    nextBtn.disabled = hasEmpty || total <= 0;
  }

  // UX: auto-grow des textarea (détails longs)
  function autoGrowTextarea(el) {
    if (!el || String(el.tagName).toUpperCase() !== "TEXTAREA") return;
    try {
      el.style.height = "auto";
      el.style.overflowY = "hidden";
      el.style.height = `${Math.max(el.scrollHeight, 44)}px`;
    } catch (e) {
      // ignore
    }
  }

  function escapeHtml(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/\"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function lineRequiresSupplier(rawText) {
    const hay = String(rawText || "").toLowerCase();
    const patterns = [
      /\blocation\b/,
      /\bfourniture\b/,
      /\bfournitures\b/,
      /\bfournisseur\b/,
      /\bfournisseurs\b/,
      /\bfourniture\s+et\s+pose\b/,
      /\bfourniture\/?pose\b/,
      /\btransport\b/,
      /\bachat\s+de\s+mat[ée]riel\b/,
      /\bachat\s+de\s+mat[ée]riaux\b/,
      /\bachat\s+de\s+consommables?\b/,
      /\bachat\s+des?\s+[ée]quipements?\b/,
      /\btransport\s+et\s+mobilisation\s+engin\b/,
      /\btransport\s+des\s+materiels?\b/,
      /\btransport\s+materiel\b/,
      /\bassurance\s+[ée]quipement\b/,
      /\bcertification\s+des?\s+engins?\b/,
      /\bfrais\s+de\s+location\b/,
      /\bavance\s+de\s+location\b/,
      /\bengins?\b/,
      /\bchargeuse\b/,
      /\bcamion\b/,
      /\bgrue\b/,
      /\bcompacteur\b/,
      /\b[ée]chafaudage\b/,
      /\bepi\b/,
      /\bmat[ée]riel\b/,
      /\bmat[ée]riaux\b/,
      /\bconsommables?\b/,
    ];

    return patterns.some((pattern) => pattern.test(hay));
  }

  function getSelectedSupplierOption() {
    const fournisseurSelect = document.getElementById("fournisseur_id");
    if (!fournisseurSelect || !fournisseurSelect.value) return null;
    return fournisseurSelect.options[fournisseurSelect.selectedIndex] || null;
  }

  function getSupplierCategoryList(option) {
    return String(option?.dataset?.categories || "")
      .split(",")
      .map((value) => value.trim())
      .filter(Boolean);
  }

  function getSupplierDisplayName(option) {
    return option?.dataset?.displayName || option?.dataset?.raisonSociale || "";
  }

  function getSupplierLegalName(option) {
    return option?.dataset?.legalName || "";
  }

  function getSupplierDocuments(option) {
    return String(option?.dataset?.documents || "")
      .split(",")
      .map((value) => value.trim())
      .filter(Boolean);
  }

  function renderSameCategorySuppliers(selectedOption) {
    const container = document.getElementById("fournisseur-same-category-list");
    const fournisseurSelect = document.getElementById("fournisseur_id");
    if (!container || !fournisseurSelect) return;

    const selectedCategories = new Set(getSupplierCategoryList(selectedOption));
    if (!selectedOption || selectedCategories.size === 0) {
      container.innerHTML =
        '<span class="text-muted small">Sélectionnez un fournisseur catégorisé pour voir les alternatives.</span>';
      return;
    }

    const matches = Array.from(fournisseurSelect.options)
      .filter((option) => option.value && option.value !== selectedOption.value)
      .filter((option) =>
        getSupplierCategoryList(option).some((category) =>
          selectedCategories.has(category),
        ),
      );

    if (!matches.length) {
      container.innerHTML =
        '<span class="text-muted small">Aucune alternative validée trouvée dans les mêmes catégories.</span>';
      return;
    }

    container.innerHTML = "";
    matches.slice(0, 8).forEach((option) => {
      const button = document.createElement("button");
      button.type = "button";
      button.className = "supplier-alt-choice";
      button.title = getSupplierDisplayName(option) || option.textContent || "";

      const visual = document.createElement("div");
      visual.className = "supplier-alt-choice-visual";
      if (option.dataset?.visual) {
        const image = document.createElement("img");
        image.src = option.dataset.visual;
        image.alt = getSupplierDisplayName(option) || "Fournisseur";
        visual.appendChild(image);
      } else {
        const icon = document.createElement("i");
        icon.className =
          option.dataset?.type === "physique"
            ? "fas fa-user"
            : "fas fa-building";
        visual.appendChild(icon);
      }

      const name = document.createElement("div");
      name.className = "supplier-alt-choice-name";
      name.textContent = getSupplierDisplayName(option) || option.textContent;

      const type = document.createElement("div");
      type.className = "supplier-alt-choice-type";
      type.textContent = option.dataset.typeLabel || "Fournisseur validé";

      button.appendChild(visual);
      button.appendChild(name);
      button.appendChild(type);

      button.addEventListener("click", () => {
        fournisseurSelect.value = option.value;
        fournisseurSelect.dispatchEvent(new Event("change", { bubbles: true }));
      });
      container.appendChild(button);
    });
  }

  function renderSupplierPickerOptions(filterText) {
    const select = document.getElementById("fournisseur_id");
    const list = document.getElementById("fournisseur-options-list");
    if (!select || !list) return;

    const query = String(filterText || "")
      .trim()
      .toLowerCase();

    const options = Array.from(select.options || []).filter(
      (option) => option && String(option.value || "").trim() !== "",
    );

    list.innerHTML = "";
    let shown = 0;

    options.forEach((option) => {
      const haystack = [
        option.textContent,
        getSupplierDisplayName(option),
        getSupplierLegalName(option),
        option.dataset?.contact,
        option.dataset?.telephone,
        option.dataset?.email,
        option.dataset?.categories,
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();

      if (query && !haystack.includes(query)) return;

      const isSelected = select.value === option.value;
      const isBlocked = Boolean(option.disabled);
      const label = getSupplierDisplayName(option) || option.textContent || "";
      const meta = [
        option.dataset?.typeLabel,
        getSupplierLegalName(option) && getSupplierLegalName(option) !== label
          ? getSupplierLegalName(option)
          : "",
        option.dataset?.categories,
        option.dataset?.telephone,
      ]
        .filter(Boolean)
        .join(" • ");

      const button = document.createElement("button");
      button.type = "button";
      button.className =
        "list-group-item list-group-item-action d-flex justify-content-between align-items-start" + (isBlocked ? " disabled opacity-50" : "");
      button.disabled = isBlocked;
      button.innerHTML = `
        <div class="me-2" style="min-width:0;">
          <div class="fw-semibold" style="white-space:normal; word-break:break-word;">${escapeHtml(label)}</div>
          <div class="${isBlocked?'text-danger fw-semibold':'text-muted'} small">${escapeHtml(isBlocked ? option.textContent.split(' — INDISPONIBLE : ')[1] || 'Justificatifs de paiement et facture/FNE à fournir' : meta || "Fournisseur validé")}</div>
        </div>
        <div><span class="badge ${isBlocked?'bg-secondary':isSelected ? "bg-primary" : "bg-light text-dark"}">${isBlocked?'Indisponible':isSelected ? "Sélectionné" : "Choisir"}</span></div>
      `;
      button.addEventListener("click", (event) => {
        event.preventDefault();
        select.value = option.value;
        select.dispatchEvent(new Event("change", { bubbles: true }));
        const trigger = document.getElementById("supplierPickerDropdown");
        if (trigger && window.bootstrap?.Dropdown) {
          window.bootstrap.Dropdown.getOrCreateInstance(trigger).hide();
        }
      });
      list.appendChild(button);
      shown += 1;
    });

    if (shown === 0) {
      const empty = document.createElement("div");
      empty.className = "supplier-picker-empty";
      empty.textContent = "Aucun fournisseur trouvé.";
      list.appendChild(empty);
    }
  }

  function syncSupplierCurrentSelection() {
    const option = getSelectedSupplierOption();
    const nameEl = document.getElementById("fournisseur-current-name");
    const metaEl = document.getElementById("fournisseur-current-meta");
    const wrapper = document.getElementById("fournisseur-current-selection");
    const trigger = document.getElementById("supplierPickerDropdown");

    if (nameEl) {
      nameEl.textContent =
        getSupplierDisplayName(option) || "Aucun fournisseur sélectionné";
    }
    if (metaEl) {
      const meta = [
        option?.dataset?.typeLabel,
        getSupplierLegalName(option) &&
        getSupplierLegalName(option) !== getSupplierDisplayName(option)
          ? getSupplierLegalName(option)
          : "",
        option?.dataset?.categories,
        option?.dataset?.telephone,
      ]
        .filter(Boolean)
        .join(" • ");
      metaEl.textContent =
        meta || "La sélection apparaîtra ici avant validation de l'étape.";
    }
    if (wrapper) {
      wrapper.classList.toggle("is-empty", !option);
    }
    if (trigger) {
      trigger.textContent =
        getSupplierDisplayName(option) || "Choisir un fournisseur";
    }
  }

  function getSupplierRequirementState() {
    const selectedIds = getSelectedLigneDebourseIds();
    return {
      required: true,
      selectedIds,
    };
  }

  function updateSupplierSelectionUI() {
    const block = document.getElementById("fournisseur-selection-block");
    const stepHint = document.getElementById("supplier-step-hint");
    const hint = document.getElementById("fournisseur-required-hint");
    const summary = document.getElementById("fournisseur-selected-summary");
    const option = getSelectedSupplierOption();
    const state = getSupplierRequirementState();

    if (stepHint) {
      stepHint.style.display = state.required ? "block" : "none";
    }

    if (!block) return state;

    block.style.display = state.required ? "block" : "none";
    if (hint) {
      hint.style.display = state.required && !option ? "block" : "none";
    }

    if (summary) {
      summary.style.display = option ? "block" : "none";
    }

    syncSupplierCurrentSelection();
    renderSupplierPickerOptions(
      document.getElementById("fournisseur_search")?.value || "",
    );

    const contactDisplay = document.getElementById(
      "fournisseur-contact-display",
    );
    const telephoneDisplay = document.getElementById(
      "fournisseur-telephone-display",
    );
    const emailDisplay = document.getElementById("fournisseur-email-display");
    const addressDisplay = document.getElementById(
      "fournisseur-address-display",
    );
    const documentsDisplay = document.getElementById(
      "fournisseur-documents-display",
    );
    const categoriesDisplay = document.getElementById(
      "fournisseur-categories-display",
    );
    const nameDisplay = document.getElementById("fournisseur-name-display");
    const typeDisplay = document.getElementById("fournisseur-type-display");
    const cniWrapper = document.getElementById(
      "fournisseur-cni-display-wrapper",
    );
    const cniDisplay = document.getElementById("fournisseur-cni-display");
    if (contactDisplay) {
      contactDisplay.textContent = option?.dataset?.contact || "Non renseigné";
    }
    if (telephoneDisplay) {
      telephoneDisplay.textContent =
        option?.dataset?.telephone || "Non renseigné";
    }
    if (emailDisplay) {
      emailDisplay.textContent = option?.dataset?.email || "Non renseigné";
    }
    if (addressDisplay) {
      addressDisplay.textContent = option?.dataset?.address || "Non renseigné";
    }
    if (documentsDisplay) {
      const documents = getSupplierDocuments(option);
      documentsDisplay.textContent = documents.length
        ? documents.join(", ")
        : "Aucune pièce détectée";
    }
    if (categoriesDisplay) {
      categoriesDisplay.textContent =
        option?.dataset?.categories || "Aucune catégorie";
    }
    if (nameDisplay) {
      nameDisplay.textContent = getSupplierDisplayName(option) || "—";
    }
    if (typeDisplay) {
      typeDisplay.textContent = option?.dataset?.typeLabel || "—";
    }
    if (cniWrapper && cniDisplay) {
      if (option?.dataset?.cni) {
        cniDisplay.href = option.dataset.cni;
        cniWrapper.style.display = "block";
      } else {
        cniDisplay.href = "#";
        cniWrapper.style.display = "none";
      }
    }

    renderSameCategorySuppliers(option);

    return state;
  }

  function filterSupplierOptions() {
    const input = document.getElementById("fournisseur_search");
    renderSupplierPickerOptions(input?.value || "");
  }

  function initSupplierPicker() {
    renderSupplierPickerOptions("");
    syncSupplierCurrentSelection();
  }

  function syncCurrentStepFromDom() {
    const activeIndex = Array.from(steps).findIndex((step) =>
      step.classList.contains("active"),
    );
    currentStep = activeIndex >= 0 ? activeIndex : 0;
  }

  /************* BÉNÉFICIAIRE = PERSONNEL (RH) *************/
  function normalizePhoneForInput(raw) {
    const digits = String(raw || "").replace(/\D+/g, "");
    if (digits.length === 10) return digits;
    // Si indicatif (+225...) on prend les 10 derniers chiffres.
    if (digits.length > 10) return digits.slice(-10);
    return digits;
  }

  function setIfEmptyOrAutofilled(el, value) {
    if (!el) return;
    const v = String(value || "").trim();
    if (!v) return;

    const cur = String(el.value || "");
    const lastAuto = el.dataset.lastAutofill || "";
    if (!cur.trim() || cur === lastAuto) {
      el.value = v;
      el.dataset.lastAutofill = v;
      try {
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
      } catch (e) {}
    }
  }

  function setDropAreaPreviewFromUrl(previewId, url, label) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    const u = String(url || "").trim();
    if (!u) return;
    preview.innerHTML = `
      <div class="d-flex flex-column align-items-start gap-1">
        <img src="${escapeHtml(u)}" alt="Aperçu" style="max-width: 150px; max-height: 150px; margin-top: 10px; object-fit:cover;" />
        ${label ? `<div class="text-muted" style="font-size:0.75rem;">${escapeHtml(label)}</div>` : ""}
      </div>
    `;
  }

  function initPersonnelBeneficiaireSelector() {
    const toggle = document.getElementById("beneficiaire_is_personnel");
    const container = document.getElementById("personnel-search-container");
    const input = document.getElementById("personnel_search");
    const results = document.getElementById("personnel-search-results");
    const hint = document.getElementById("personnel-required-hint");
    const hidId = document.getElementById("personnel_id");
    const hidHasPhoto = document.getElementById("personnel_has_photo");
    const hidHasCni = document.getElementById("personnel_has_cni");
    const hidPhotoUrl = document.getElementById("personnel_photo_url");
    const hidCniUrl = document.getElementById("personnel_cni_url");

    const selectedBox = document.getElementById("personnel-selected");
    const namePrev = document.getElementById("personnel-fullname-preview");
    const phonePrev = document.getElementById("personnel-phone-preview");
    const idPrev = document.getElementById("personnel-id-preview");
    const photoImg = document.getElementById("personnel-photo-preview");
    const photoMissing = document.getElementById("personnel-photo-missing");
    const cniImg = document.getElementById("personnel-cni-preview");
    const cniMissing = document.getElementById("personnel-cni-missing");

    if (!toggle || !container || !input || !results || !hidId) return;

    let debounceTimer = null;
    let lastRequestToken = 0;

    function clearSelection(keepTypedFields) {
      hidId.value = "";
      if (hidHasPhoto) hidHasPhoto.value = "0";
      if (hidHasCni) hidHasCni.value = "0";
      if (hidPhotoUrl) hidPhotoUrl.value = "";
      if (hidCniUrl) hidCniUrl.value = "";

      if (selectedBox) selectedBox.style.display = "none";
      if (namePrev) namePrev.textContent = "";
      if (phonePrev) phonePrev.textContent = "—";
      if (idPrev) idPrev.textContent = "—";
      if (photoImg) {
        photoImg.src = "";
        photoImg.style.display = "none";
      }
      if (photoMissing) photoMissing.style.display = "none";
      if (cniImg) {
        cniImg.src = "";
        cniImg.style.display = "none";
      }
      if (cniMissing) cniMissing.style.display = "none";

      if (!keepTypedFields) {
        // Ne pas effacer nom/tel saisis par l'utilisateur.
      }

      if (hint) hint.style.display = toggle.checked ? "block" : "none";
      try {
        if (typeof window.toggleNextButton_1 === "function") {
          window.toggleNextButton_1();
        }
      } catch (e) {}

      try {
        if (typeof window.updateStep4NextButtonState === "function") {
          window.updateStep4NextButtonState();
        }
      } catch (e) {}
    }

    function setResultsVisible(vis) {
      results.style.display = vis ? "block" : "none";
    }

    function renderResults(items) {
      results.innerHTML = "";
      if (!items || items.length === 0) {
        const div = document.createElement("div");
        div.className = "list-group-item text-muted small";
        div.textContent = "Aucun résultat";
        results.appendChild(div);
        setResultsVisible(true);
        return;
      }

      items.forEach((it) => {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className =
          "list-group-item list-group-item-action d-flex justify-content-between align-items-start";
        const fullName = String(it.full_name || "").trim();
        const phone = String(it.phone || "").trim();
        btn.innerHTML = `
          <div style="min-width:0;">
            <div class="fw-semibold" style="white-space:normal; word-break:break-word;">${escapeHtml(fullName || "(sans nom)")}</div>
            <div class="text-muted small">ID: ${escapeHtml(String(it.id || ""))}</div>
          </div>
          <div class="text-muted small" style="white-space:nowrap;">${escapeHtml(phone || "")}</div>
        `;
        btn.addEventListener("click", () => {
          const id = parseInt(String(it.id || "0"), 10);
          if (!Number.isFinite(id) || id <= 0) return;
          hidId.value = String(id);
          input.value = fullName;
          setResultsVisible(false);
          if (hint) hint.style.display = "none";
          fetchPersonnelDetails(id);
          try {
            if (typeof window.toggleNextButton_1 === "function") {
              window.toggleNextButton_1();
            }
          } catch (e) {}
        });
        results.appendChild(btn);
      });

      setResultsVisible(true);
    }

    function fetchSearch(term) {
      const token = ++lastRequestToken;
      return fetch("request/search_personnel.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "q=" + encodeURIComponent(String(term || "")),
      })
        .then((r) => {
          if (!r.ok) throw new Error("Erreur réseau");
          return r.json();
        })
        .then((data) => {
          if (token !== lastRequestToken) return;
          if (!data || !data.success) {
            renderResults([]);
            return;
          }
          renderResults(Array.isArray(data.results) ? data.results : []);
        })
        .catch(() => {
          if (token !== lastRequestToken) return;
          renderResults([]);
        });
    }

    function fetchPersonnelDetails(id) {
      const pid = parseInt(String(id || "0"), 10);
      if (!Number.isFinite(pid) || pid <= 0) return;
      if (selectedBox) selectedBox.style.display = "block";
      if (idPrev) idPrev.textContent = String(pid);

      return fetch("request/get_personnel_details.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "personnel_id=" + encodeURIComponent(String(pid)),
      })
        .then((r) => {
          if (!r.ok) throw new Error("Erreur réseau");
          return r.json();
        })
        .then((data) => {
          const p = data && data.success ? data.personnel : null;
          if (!p) return;

          const fullName = String(p.full_name || "").trim();
          const phone = normalizePhoneForInput(p.phone);
          const photoUrl = String(p.photo_url || "").trim();
          const cniUrl = String(p.cni_url || "").trim();

          if (namePrev) namePrev.textContent = fullName || "(sans nom)";
          if (phonePrev) phonePrev.textContent = phone || "—";

          if (hidHasPhoto) hidHasPhoto.value = p.has_photo ? "1" : "0";
          if (hidHasCni) hidHasCni.value = p.has_cni ? "1" : "0";
          if (hidPhotoUrl) hidPhotoUrl.value = photoUrl;
          if (hidCniUrl) hidCniUrl.value = cniUrl;

          // Prévisualisations RH
          if (photoImg && photoMissing) {
            if (photoUrl) {
              photoImg.src = photoUrl;
              photoImg.style.display = "block";
              photoMissing.style.display = "none";
            } else {
              photoImg.src = "";
              photoImg.style.display = "none";
              photoMissing.style.display = "block";
            }
          }
          if (cniImg && cniMissing) {
            if (cniUrl) {
              cniImg.src = cniUrl;
              cniImg.style.display = "block";
              cniMissing.style.display = "none";
            } else {
              cniImg.src = "";
              cniImg.style.display = "none";
              cniMissing.style.display = "block";
            }
          }

          // Auto-remplissage (non destructif)
          setIfEmptyOrAutofilled(
            document.getElementById("nom_prenom"),
            fullName,
          );
          setIfEmptyOrAutofilled(document.getElementById("telephone"), phone);

          // Si aucune photo/CNI uploadée, on peut afficher un aperçu depuis RH
          const photoFile =
            document.getElementById("photo_demandeur")?.files?.[0];
          const cniFile = document.getElementById("cni_demandeur")?.files?.[0];
          if (!photoFile && photoUrl) {
            setDropAreaPreviewFromUrl(
              "photo-preview",
              photoUrl,
              "Pré-rempli depuis RH",
            );
          }
          if (!cniFile && cniUrl) {
            setDropAreaPreviewFromUrl(
              "cni-preview",
              cniUrl,
              "Pré-rempli depuis RH",
            );
          }

          try {
            if (typeof window.toggleNextButton_1 === "function") {
              window.toggleNextButton_1();
            }
          } catch (e) {}
          try {
            if (typeof window.updateStep4NextButtonState === "function") {
              window.updateStep4NextButtonState();
            }
          } catch (e) {}
        })
        .catch(() => {
          // ignore
        });
    }

    function onToggleChange() {
      container.style.display = toggle.checked ? "block" : "none";
      if (!toggle.checked) {
        setResultsVisible(false);
        input.value = "";
        clearSelection(true);
      } else {
        // activation
        if (hint) hint.style.display = hidId.value ? "none" : "block";
        try {
          if (typeof window.toggleNextButton_1 === "function") {
            window.toggleNextButton_1();
          }
        } catch (e) {}
      }
    }

    toggle.addEventListener("change", onToggleChange);

    input.addEventListener("input", () => {
      const term = String(input.value || "").trim();
      if (debounceTimer) clearTimeout(debounceTimer);
      // Si l'utilisateur retape, on invalide l'ID sélectionné
      if (hidId.value) {
        clearSelection(true);
      }
      if (!toggle.checked) return;
      if (term.length < 2) {
        setResultsVisible(false);
        return;
      }
      debounceTimer = setTimeout(() => fetchSearch(term), 220);
    });

    document.addEventListener("click", (e) => {
      const within =
        e.target.closest("#personnel-search-container") ||
        e.target.closest("#personnel_search");
      if (!within) {
        setResultsVisible(false);
      }
    });

    // init
    onToggleChange();
  }

  // UI moderne: badges + dropdown (le <select multiple> reste la source de vérité).
  // Un code FEB ne peut produire qu'une seule fiche: on force donc une seule ligne.
  function initPrecisionBadgePicker() {
    const sel = document.getElementById("precision_select");
    const list = document.getElementById("precision-options-list");
    const badgesWrap = document.getElementById("precision-selected-badges");
    const empty = document.getElementById("precision-selected-empty");
    const countEl = document.getElementById("precision-selected-count");
    const search = document.getElementById("precision-search");
    if (!sel || !list || !badgesWrap || !empty) return;

    function getOptionById(id) {
      const v = String(id);
      return Array.from(sel.options || []).find((o) => String(o.value) === v);
    }

    function buildOptionsList(filterText) {
      const ft = String(filterText || "")
        .trim()
        .toLowerCase();
      list.innerHTML = "";

      const opts = Array.from(sel.options || []).filter(
        (o) => o && String(o.value).trim() !== "" && !o.disabled,
      );

      let shown = 0;
      opts.forEach((o) => {
        const label = String(o.text || "");
        if (ft && !label.toLowerCase().includes(ft)) return;

        const id = parseInt(String(o.value), 10);
        if (!Number.isFinite(id) || id <= 0) return;

        const isSelected = !!o.selected;

        const btn = document.createElement("button");
        btn.type = "button";
        btn.className =
          "list-group-item list-group-item-action d-flex justify-content-between align-items-start";
        btn.setAttribute("data-ligne-id", String(id));

        btn.innerHTML = `
          <div class="me-2" style="min-width:0;">
            <div class="fw-semibold" style="white-space:normal; word-break:break-word;">${escapeHtml(label)}</div>
            <div class="text-muted small">ID: ${id}</div>
          </div>
          <div>
            <span class="badge ${isSelected ? "bg-primary" : "bg-light text-dark"}">${isSelected ? "Sélectionné" : "Choisir"}</span>
          </div>
        `;

        btn.addEventListener("click", (e) => {
          e.preventDefault();
          const shouldSelect = !o.selected;
          Array.from(sel.options || []).forEach((option) => {
            option.selected = false;
          });
          o.selected = shouldSelect;
          sel.dispatchEvent(new Event("change", { bubbles: true }));
        });

        list.appendChild(btn);
        shown += 1;
      });

      if (shown === 0) {
        const div = document.createElement("div");
        div.className = "text-muted small px-2 py-1";
        div.textContent = "Aucun résultat";
        list.appendChild(div);
      }
    }

    function renderBadges() {
      const ids = getSelectedLigneDebourseIds();
      if (ids.length > 1) {
        const keep = ids[0];
        Array.from(sel.options || []).forEach((option) => {
          option.selected = String(option.value) === String(keep);
        });
        sel.dispatchEvent(new Event("change", { bubbles: true }));
        return;
      }

      badgesWrap.innerHTML = "";
      if (countEl) countEl.textContent = String(ids.length);

      if (ids.length === 0) {
        empty.style.display = "block";
        return;
      }

      empty.style.display = "none";
      ids.forEach((id) => {
        const opt = getOptionById(id);
        const label = opt ? String(opt.text || "") : `Ligne ${id}`;
        const badge = document.createElement("span");
        badge.className =
          "badge rounded-pill bg-primary d-inline-flex align-items-center gap-2 px-3 py-2";
        badge.innerHTML = `
          <span style="max-width:520px; white-space:normal; word-break:break-word;">${escapeHtml(label)}</span>
          <button type="button" class="btn-close btn-close-white" aria-label="Retirer" data-ligne-id="${id}"></button>
        `;
        badge.querySelector("button")?.addEventListener("click", (e) => {
          e.preventDefault();
          e.stopPropagation();
          const o = getOptionById(id);
          if (o) o.selected = false;
          sel.dispatchEvent(new Event("change", { bubbles: true }));
        });
        badgesWrap.appendChild(badge);
      });
    }

    function syncUI() {
      renderBadges();
      buildOptionsList(search ? search.value : "");
    }

    // Sync sur changement sélection
    sel.addEventListener("change", syncUI);
    // Recherche
    if (search) {
      search.addEventListener("input", () => buildOptionsList(search.value));
    }
    // Sync sur chargement options (AJAX)
    try {
      const obs = new MutationObserver(() => syncUI());
      obs.observe(sel, { childList: true, subtree: true });
    } catch (e) {
      // ignore
    }

    // Initial
    syncUI();
  }

  function showMontantInfoSingleMode() {
    const section = document.getElementById("montant-info-section");
    const single = document.getElementById("montant-info-single");
    const multi = document.getElementById("montant-info-multi");
    if (section) section.style.display = "block";
    if (single) single.style.display = "";
    if (multi) multi.style.display = "none";
  }

  function renderMontantInfosMulti(selectedIds) {
    const section = document.getElementById("montant-info-section");
    const single = document.getElementById("montant-info-single");
    const multi = document.getElementById("montant-info-multi");
    const grid = document.getElementById("montant-multi-cards");
    if (!section || !multi || !grid) return;

    if (!selectedIds || selectedIds.length === 0) {
      section.style.display = "none";
      return;
    }

    section.style.display = "block";
    if (single) single.style.display = "none";
    multi.style.display = "block";
    grid.innerHTML = "";

    selectedIds.forEach((id) => {
      const det = ligneDebourseDetailsCache.get(id);
      const designation =
        (det && det.ligne && det.ligne.designation) || `Ligne ${id}`;
      const categorie = (det && det.ligne && det.ligne.categorie) || "";
      const prevu = det ? det.montant_prevu : 0;
      const decaisse = det ? det.montant_decaisse : 0;
      const restant = det ? det.montant_restant : prevu - decaisse;
      const ratio = prevu > 0 ? (decaisse / prevu) * 100 : 0;

      const col = document.createElement("div");
      col.className = "col-12 col-md-6 col-xl-4";

      const restantBadge = restant <= 0 ? "danger" : "success";
      const ratioClamped = Math.max(0, Math.min(100, ratio));
      const ratioClass =
        ratio > 100 ? "danger" : ratio >= 95 ? "warning" : "success";

      col.innerHTML = `
        <div class="card h-100 border-${restant <= 0 ? "danger" : "primary"}">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="fw-semibold" style="min-width:0; white-space:normal; word-break:break-word;">${escapeHtml(designation)}</div>
              <span class="badge bg-light text-dark">ID: ${id}</span>
            </div>
            ${categorie ? `<div class="text-muted small mt-1">${escapeHtml(categorie)}</div>` : ""}

            <div class="mt-3">
              <div class="d-flex justify-content-between text-muted small">
                <span>Progression</span>
                <span>${Number.isFinite(ratio) ? ratio.toFixed(1) : "0.0"}%</span>
              </div>
              <div class="progress" style="height:6px;">
                <div class="progress-bar bg-${ratioClass}" style="width:${ratioClamped}%;"></div>
              </div>
            </div>

            <div class="row g-2 mt-3">
              <div class="col-6">
                <div class="text-muted small">Prévu</div>
                <div class="fw-semibold">${formatFCFA(prevu)}</div>
              </div>
              <div class="col-6">
                <div class="text-muted small">Déjà décaissé</div>
                <div class="fw-semibold">${formatFCFA(decaisse)}</div>
              </div>
              <div class="col-12">
                <div class="text-muted small">Restant</div>
                <div class="fw-semibold"><span class="badge bg-${restantBadge}">${formatFCFA(restant)}</span></div>
              </div>
            </div>
          </div>
        </div>
      `;

      grid.appendChild(col);
    });
  }

  function renderMontantsLignesTable(selectedIds) {
    const tbody = document.querySelector("#table-montants-lignes tbody");
    const alertGlobal = document.getElementById("multi-depassement-alert");
    if (!tbody) return;
    tbody.innerHTML = "";
    if (alertGlobal) alertGlobal.style.display = "none";

    const defaultDetails = String(
      document.getElementById("details_demande")?.value || "",
    ).trim();

    // Mémoriser la dernière valeur "par défaut" pour permettre une synchro non-destructive
    lastDefaultDetailsForLignes = defaultDetails;

    selectedIds.forEach((id) => {
      const det = ligneDebourseDetailsCache.get(id);
      const designation =
        (det && det.ligne && det.ligne.designation) || "Ligne " + id;
      const prevu = det ? det.montant_prevu : 0;
      const decaisse = det ? det.montant_decaisse : 0;
      const restant = det ? det.montant_restant : prevu - decaisse;

      // Ligne principale: montants + statut
      const trMain = document.createElement("tr");
      trMain.setAttribute("data-ligne-id", String(id));
      trMain.innerHTML = `
        <td style="max-width: 420px; white-space: normal; word-break: break-word;">
          <div class="fw-semibold">${escapeHtml(designation)}</div>
          <div class="text-muted" style="font-size:0.78rem;">ID: ${id}</div>
        </td>
        <td class="text-end">${formatFCFA(prevu)}</td>
        <td class="text-end">${formatFCFA(decaisse)}</td>
        <td class="text-end"><span class="badge bg-${restant <= 0 ? "danger" : "success"}">${formatFCFA(restant)}</span></td>
        <td class="text-end">
          <input type="number" min="0" step="1" class="form-control form-control-sm montant-ligne-input" data-ligne-id="${id}" placeholder="0" style="min-width:130px;" />
        </td>
        <td>
          <span class="badge bg-secondary statut-ligne">—</span>
        </td>
      `;

      // Ligne détails: champ plein largeur (certains détails peuvent être longs)
      const trDetails = document.createElement("tr");
      trDetails.className = "table-light";
      trDetails.setAttribute("data-details-for", String(id));
      trDetails.innerHTML = `
        <td colspan="6" style="padding: 10px 12px;">
          <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
            <div class="small text-muted"><strong>Détails (fiche)</strong> <span class="text-danger">*</span></div>
            <div class="small text-muted">Ligne ID: ${id}</div>
          </div>
          <textarea rows="2" class="form-control form-control-sm details-ligne-input" data-ligne-id="${id}" placeholder="Détails de cette fiche...">${escapeHtml(defaultDetails)}</textarea>
          <div class="text-muted" style="font-size:0.75rem; margin-top:2px;">Obligatoire</div>
        </td>
      `;

      tbody.appendChild(trMain);
      tbody.appendChild(trDetails);
    });

    // listeners input
    tbody.querySelectorAll(".montant-ligne-input").forEach((inp) => {
      inp.addEventListener("input", () => {
        checkDepassementsMulti();
        updateRecap();
      });
      inp.addEventListener("change", () => {
        checkDepassementsMulti();
        updateRecap();
      });
    });

    tbody.querySelectorAll(".details-ligne-input").forEach((inp) => {
      autoGrowTextarea(inp);
      inp.addEventListener("input", () => {
        autoGrowTextarea(inp);
        checkDepassementsMulti();
        updateRecap();
      });
      inp.addEventListener("change", () => {
        autoGrowTextarea(inp);
        checkDepassementsMulti();
        updateRecap();
      });
    });

    checkDepassementsMulti();
  }

  function refreshMontantStepFromSelection() {
    const selectedIds = getSelectedLigneDebourseIds();
    evaluateTransportSpecVisibility();

    const infoMulti = document.getElementById("precision-multi-info");
    if (infoMulti) {
      infoMulti.style.display = selectedIds.length > 1 ? "block" : "none";
    }

    const affectationVal = document.getElementById("affectation")?.value;
    const isChantierNewSystem =
      affectationVal === "1" && selectedIds.length > 0;
    setMontantStepMode(isChantierNewSystem);

    if (selectedIds.length !== 1) {
      const fichesExistantesSection = document.getElementById(
        "fiches-existantes-section",
      );
      if (fichesExistantesSection) {
        fichesExistantesSection.style.display = "none";
      }
    }

    if (selectedIds.length === 0) {
      const montantInfoSection = document.getElementById(
        "montant-info-section",
      );
      const fichesExistantesSection = document.getElementById(
        "fiches-existantes-section",
      );
      if (montantInfoSection) {
        montantInfoSection.style.display = "none";
      }
      if (fichesExistantesSection) {
        fichesExistantesSection.style.display = "none";
      }
      renderMontantsLignesTable([]);
      renderRecapBudgetTable();
      return Promise.resolve();
    }

    return Promise.all(
      selectedIds.map((id) =>
        fetchLigneDebourseDetails(id).catch((e) => {
          console.error("Détails ligne échoués", id, e);
          return null;
        }),
      ),
    ).then(() => {
      if (isChantierNewSystem) {
        renderMontantsLignesTable(selectedIds);
      }

      if (selectedIds.length > 1) {
        renderMontantInfosMulti(selectedIds);
      }

      if (selectedIds.length === 1) {
        const oneId = selectedIds[0];
        const det = ligneDebourseDetailsCache.get(oneId);
        if (!det) {
          montantPrecision = null;
          renderRecapBudgetTable();
          return;
        }

        showMontantInfoSingleMode();

        const montantInfo = document.getElementById("montant-info-section");
        const montantPrevu = document.getElementById("montant-prevu-display");
        const montantDecaisse = document.getElementById(
          "montant-decaisse-display",
        );
        const montantRestant = document.getElementById(
          "montant-restant-display",
        );
        if (montantInfo) montantInfo.style.display = "block";
        if (montantPrevu)
          montantPrevu.textContent = formatFCFA(det.montant_prevu);
        if (montantDecaisse) {
          montantDecaisse.textContent = formatFCFA(det.montant_decaisse);
        }
        if (montantRestant) {
          montantRestant.textContent = formatFCFA(det.montant_restant);
          montantRestant.className =
            "h5 " + (det.montant_restant <= 0 ? "text-danger" : "text-success");
        }
        montantPrecision = det.montant_restant;
        checkMontantDepassement();
      } else {
        montantPrecision = null;
        checkMontantDepassement();
      }

      renderRecapBudgetTable();
    });
  }

  // Dernière valeur par défaut propagée dans les détails par ligne
  let lastDefaultDetailsForLignes = "";

  function syncDefaultDetailsToLignes(force) {
    if (!isMontantMultiModeVisible()) return;
    const tbody = document.querySelector("#table-montants-lignes tbody");
    if (!tbody) return;

    const newDefault = String(
      document.getElementById("details_demande")?.value || "",
    ).trim();

    tbody.querySelectorAll(".details-ligne-input").forEach((inp) => {
      const cur = String(inp.value || "").trim();
      if (
        force ||
        !cur ||
        (lastDefaultDetailsForLignes !== null &&
          cur === lastDefaultDetailsForLignes)
      ) {
        inp.value = newDefault;
        autoGrowTextarea(inp);
      }
    });

    lastDefaultDetailsForLignes = newDefault;
    checkDepassementsMulti();
  }

  function getMontantsByLigneFromTable() {
    const tbody = document.querySelector("#table-montants-lignes tbody");
    const out = {};
    if (!tbody) return out;
    tbody.querySelectorAll(".montant-ligne-input").forEach((inp) => {
      const id = parseInt(inp.getAttribute("data-ligne-id") || "0", 10);
      if (!Number.isFinite(id) || id <= 0) return;
      const v = parseNumberFCFA(inp.value);
      out[id] = v;
    });
    return out;
  }

  function getDetailsByLigneFromTable() {
    const tbody = document.querySelector("#table-montants-lignes tbody");
    const out = {};
    if (!tbody) return out;
    tbody.querySelectorAll(".details-ligne-input").forEach((inp) => {
      const id = parseInt(inp.getAttribute("data-ligne-id") || "0", 10);
      if (!Number.isFinite(id) || id <= 0) return;
      out[id] = String(inp.value || "").trim();
    });
    return out;
  }

  function getLigneOptionLabel(ligneId) {
    const option = document.querySelector(
      `#precision_select option[value="${ligneId}"]`,
    );
    return option ? String(option.textContent || "").trim() : "";
  }

  function setRecapBudgetTotals(prevu, decaisse, demande, restant) {
    const totalPrevuEl = document.getElementById("recap-total-prevu");
    const totalDecaisseEl = document.getElementById("recap-total-decaisse");
    const totalDemandeEl = document.getElementById("recap-total-demande");
    const totalRestantEl = document.getElementById("recap-total-restant");
    if (totalPrevuEl) totalPrevuEl.textContent = formatFCFA(prevu);
    if (totalDecaisseEl) totalDecaisseEl.textContent = formatFCFA(decaisse);
    if (totalDemandeEl) totalDemandeEl.textContent = formatFCFA(demande);
    if (totalRestantEl) totalRestantEl.textContent = formatFCFA(restant);
  }

  function renderRecapBudgetTable() {
    const tbody = document.getElementById("recap-budget-table-body");
    if (!tbody) return;

    const selectedIds = getSelectedLigneDebourseIds();
    const isMulti = isMontantMultiModeVisible();
    const montantsByLigne = isMulti ? getMontantsByLigneFromTable() : {};
    const detailsByLigne = isMulti ? getDetailsByLigneFromTable() : {};
    const globalDetails = String(
      document.getElementById("details_demande")?.value || "",
    ).trim();
    const singleMontant = parseNumberFCFA(
      document.getElementById("montant")?.value || 0,
    );

    let totalPrevu = 0;
    let totalDecaisse = 0;
    let totalDemande = 0;
    let totalRestant = 0;

    const rows = [];

    if (selectedIds.length > 0) {
      selectedIds.forEach((id, index) => {
        const det = ligneDebourseDetailsCache.get(id);
        const designation =
          String(det?.ligne?.designation || "").trim() ||
          getLigneOptionLabel(id) ||
          `Ligne ${id}`;
        const prevu = parseNumberFCFA(det?.montant_prevu || 0);
        const decaisse = parseNumberFCFA(det?.montant_decaisse || 0);
        const restant = parseNumberFCFA(
          det?.montant_restant || prevu - decaisse,
        );

        let demande = 0;
        if (isMulti) {
          demande = parseNumberFCFA(montantsByLigne[id] || 0);
        } else if (selectedIds.length === 1 && index === 0) {
          demande = singleMontant;
        }

        totalPrevu += prevu;
        totalDecaisse += decaisse;
        totalDemande += demande;
        totalRestant += restant - demande;

        let statutClass = "secondary";
        let statutLabel = "En attente";

        if (!(demande > 0)) {
          statutLabel = "Montant requis";
        } else if (demande > restant) {
          statutClass = "danger";
          statutLabel = "DÉPASSEMENT";
        } else {
          statutClass = "success";
          statutLabel = "OK";
        }

        const details = isMulti
          ? String(detailsByLigne[id] || "").trim()
          : globalDetails;
        const detailsLine = details
          ? `<div class="text-muted small mt-1">${escapeHtml(details)}</div>`
          : "";

        rows.push(`
          <tr>
            <td>
              <div class="fw-semibold">${escapeHtml(designation)}</div>
              ${detailsLine}
            </td>
            <td class="text-end">${formatFCFA(prevu)}</td>
            <td class="text-end">${formatFCFA(decaisse)}</td>
            <td class="text-end">${formatFCFA(demande)}</td>
            <td><span class="badge bg-${statutClass}">${statutLabel}</span></td>
          </tr>
        `);
      });
    }

    if (rows.length === 0) {
      tbody.innerHTML =
        '<tr><td colspan="5" class="recap-empty-state">Les lignes budgétaires apparaîtront ici.</td></tr>';
      setRecapBudgetTotals(0, 0, 0, 0);
      return;
    }

    tbody.innerHTML = rows.join("");
    setRecapBudgetTotals(totalPrevu, totalDecaisse, totalDemande, totalRestant);
  }

  function checkDepassementsMulti() {
    const alertGlobal = document.getElementById("multi-depassement-alert");
    const tbody = document.querySelector("#table-montants-lignes tbody");
    if (!tbody) return { hasOverrun: false, hasEmpty: false, total: 0 };

    let hasOverrun = false;
    let hasEmpty = false;
    let hasMissingDetails = false;
    let total = 0;

    tbody.querySelectorAll("tr[data-ligne-id]").forEach((tr) => {
      const id = parseInt(tr.getAttribute("data-ligne-id") || "0", 10);
      const det = ligneDebourseDetailsCache.get(id);
      const restant = det ? det.montant_restant : 0;
      const inp = tr.querySelector(".montant-ligne-input");
      const detailsInp = tbody.querySelector(
        `.details-ligne-input[data-ligne-id="${id}"]`,
      );
      const badge = tr.querySelector(".statut-ligne");
      const val = parseNumberFCFA(inp ? inp.value : 0);

      const detailsVal = String(detailsInp ? detailsInp.value : "").trim();

      // Une ligne est considérée "complète" seulement si un montant > 0 est saisi
      // (cohérent avec la validation serveur qui exige > 0 par ligne)
      const isMissingOrZero =
        !inp || String(inp.value).trim() === "" || !(val > 0);
      if (isMissingOrZero) hasEmpty = true;

      if (!detailsVal) {
        hasMissingDetails = true;
        hasEmpty = true;
      }

      total += val;

      const isOver = restant > 0 ? val > restant : val > 0;
      if (isOver) hasOverrun = true;

      if (badge) {
        if (isMissingOrZero) {
          badge.className = "badge bg-secondary statut-ligne";
          badge.textContent = "Montant requis";
        } else if (!detailsVal) {
          badge.className = "badge bg-secondary statut-ligne";
          badge.textContent = "Détails requis";
        } else if (isOver) {
          badge.className = "badge bg-danger statut-ligne";
          badge.textContent = "DÉPASSEMENT";
        } else {
          badge.className = "badge bg-success statut-ligne";
          badge.textContent = "OK";
        }
      }

      tr.classList.toggle("table-danger", isOver);
    });

    if (alertGlobal) {
      alertGlobal.style.display = hasOverrun ? "block" : "none";
    }

    const st = { hasOverrun, hasEmpty, hasMissingDetails, total };
    applyStep3NextButtonState(st);
    return st;
  }

  // Initialiser l'UI moderne des lignes (badges) si présente
  try {
    initPrecisionBadgePicker();
  } catch (e) {
    // ignore
  }

  // Initialiser la sélection "bénéficiaire = personnel" si présente
  try {
    initPersonnelBeneficiaireSelector();
  } catch (e) {
    // ignore
  }

  // Variable pour stocker l'entreprise sélectionnée (globale)
  window.entrepriseSelectionnee = null;

  // Presets / comportement spécial (BUREAU FIDEST BANAMUR -> affiché Bureau, traité comme Chantier + auto-select CH0066)
  const SPECIAL_COMPANY_BUREAU_FIDEST_BANAMUR = "BUREAU FIDEST BANAMUR";
  const SPECIAL_CHANTIER_CODE_TARGET = "ch0066"; // match insensible à la casse (champ code)

  window.__enterprisePreset = {
    active: false,
    applied: false,
    type: null,
  };

  window.__chantierAutoSelect = null; // { textIncludes: string, lock: boolean }

  function isSpecialBureauFidestBanamur(companyName) {
    return (
      String(companyName || "").trim() === SPECIAL_COMPANY_BUREAU_FIDEST_BANAMUR
    );
  }

  function isBureauPresetActive() {
    return (
      !!window.__enterprisePreset &&
      window.__enterprisePreset.active === true &&
      window.__enterprisePreset.type === "bureau-fidest-banamur"
    );
  }

  function setAffectationOptionLabelOverride(enable) {
    const affectationEl = document.getElementById("affectation");
    if (!affectationEl) return;
    const optChantier = affectationEl.querySelector('option[value="1"]');
    if (!optChantier) return;

    if (enable) {
      if (!optChantier.dataset.originalText) {
        optChantier.dataset.originalText = optChantier.textContent || "";
      }
      optChantier.textContent = "Bureau";
    } else if (optChantier.dataset.originalText) {
      optChantier.textContent = optChantier.dataset.originalText;
      delete optChantier.dataset.originalText;
    }
  }

  function normalizeEntrepriseSelection(companyName) {
    return isSpecialBureauFidestBanamur(companyName) ? "BANAMUR" : companyName;
  }

  function persistEntrepriseSelection(companyName) {
    const companyNameForServer = normalizeEntrepriseSelection(companyName);
    if (!companyNameForServer) return;

    $.ajax({
      url: "request/define_company.php",
      type: "POST",
      data: "companyName=" + encodeURIComponent(companyNameForServer),
      success: function (response) {
        if (typeof response === "string") {
          try {
            response = JSON.parse(response);
          } catch (e) {
            console.warn("Réponse define_company.php non JSON", response);
            return;
          }
        }

        if (response.status === "success") {
          console.log(response.message);
        } else {
          console.log(
            response.message || "Une erreur est survenue. Veuillez réessayer.",
          );
        }
      },
      error: function (xhr, status, error) {
        console.error("Erreur:", error);
        console.log("Erreur lors de l'envoi de la requete.");
      },
    });
  }

  function ensureHiddenField(id, name, value) {
    let el = document.getElementById(id);
    if (!el) {
      el = document.createElement("input");
      el.type = "hidden";
      el.id = id;
      el.name = name;
      (document.getElementById("form_fiche") || document.body).appendChild(el);
    }
    el.value = value ?? "";
    return el;
  }

  function removeHiddenField(id) {
    const el = document.getElementById(id);
    if (el && el.parentNode) el.parentNode.removeChild(el);
  }

  function setAffectationLocked(locked) {
    const affectationEl = document.getElementById("affectation");
    if (!affectationEl) return;
    if (locked) {
      affectationEl.setAttribute("disabled", "disabled");
      affectationEl.classList.add("bg-light");
      ensureHiddenField(
        "affectation_locked_value",
        "affectation",
        affectationEl.value,
      );
    } else {
      affectationEl.removeAttribute("disabled");
      affectationEl.classList.remove("bg-light");
      removeHiddenField("affectation_locked_value");
    }
  }

  function setChantierLocked(locked) {
    const chantierEl = document.getElementById("chantier");
    if (!chantierEl) return;
    if (locked) {
      chantierEl.setAttribute("disabled", "disabled");
      chantierEl.classList.add("bg-light");
      ensureHiddenField("chantier_locked_value", "chantier", chantierEl.value);
    } else {
      chantierEl.removeAttribute("disabled");
      chantierEl.classList.remove("bg-light");
      removeHiddenField("chantier_locked_value");
    }
  }

  function clearEnterprisePreset() {
    window.__enterprisePreset = { active: false, applied: false, type: null };
    window.__chantierAutoSelect = null;
    setAffectationLocked(false);
    setChantierLocked(false);
    setAffectationOptionLabelOverride(false);
  }

  function applyEnterprisePresetForStep2IfNeeded() {
    if (!window.__enterprisePreset || !window.__enterprisePreset.active) return;
    const affectationEl = document.getElementById("affectation");
    if (!affectationEl) return;

    // Force affectation Chantier (1) mais affiché comme "Bureau" pour éviter la confusion
    setAffectationOptionLabelOverride(true);
    if (affectationEl.value !== "1") affectationEl.value = "1";

    // Définir l'auto-sélection du chantier avant de déclencher le change
    window.__chantierAutoSelect = {
      codeEquals: SPECIAL_CHANTIER_CODE_TARGET,
      lock: true,
    };

    // Déclenche la logique existante (affichage chantier + chargement chantiers)
    try {
      affectationEl.dispatchEvent(new Event("change", { bubbles: true }));
    } catch (e) {
      // fallback vieux navigateurs
      affectationEl.dispatchEvent(new Event("change"));
    }

    // Verrouiller affectation (pour rester sur le preset)
    setAffectationLocked(true);
    window.__enterprisePreset.applied = true;
  }

  //Log choix du mode de paiement
  document.querySelectorAll('input[name="mode_paiement"]').forEach((radio) => {
    radio.addEventListener("change", (event) => {
      console.log("Mode de paiement sélectionné :", event.target.value);
    });
  });

  // Mise à jour de la barre de progression
  function updateProgressBar() {
    syncCurrentStepFromDom();
    const supplierRequired = getSupplierRequirementState().required;
    const effectiveSteps = Array.from(steps).filter(
      (step) => step.id !== "step-supplier" || supplierRequired,
    );
    const activeStep = document.querySelector(".setup-content.active");
    const effectiveIndex = Math.max(
      effectiveSteps.findIndex((step) => step === activeStep),
      0,
    );
    const progressPercentage =
      ((effectiveIndex + 1) / Math.max(effectiveSteps.length, 1)) * 100;
    progressBar.style.width = progressPercentage + "%";
    progressBar.textContent = `Étape ${effectiveIndex + 1} sur ${effectiveSteps.length}`;

    // Changer la classe en fonction du pourcentage
    progressBar.classList.remove("low", "medium", "high");
    if (progressPercentage <= 33) {
      progressBar.classList.add("low");
    } else if (progressPercentage <= 66) {
      progressBar.classList.add("medium");
    } else {
      progressBar.classList.add("high");
    }
  }

  // Sync initial
  syncRequiredWithVisibility();
  updateProgressBar();

  function activateStep(targetStep) {
    const currentActive = document.querySelector(".setup-content.active");
    const target = document.querySelector(targetStep);
    if (!target) {
      console.warn("Étape cible introuvable:", targetStep);
      return false;
    }
    if (currentActive && currentActive !== target) {
      currentActive.classList.remove("active");
    }
    target.classList.add("active");
    updateProgressBar();
    syncRequiredWithVisibility();
    return true;
  }

  // Navigation entre étapes
  document.querySelectorAll(".nextBtn").forEach((button) => {
    button.addEventListener("click", function () {
      let targetStep = this.getAttribute("data-target");
      // Validation conditionnelle avant de changer d'étape
      const currentActive = document.querySelector(".setup-content.active");
      if (currentActive && currentActive.id === "step-2") {
        const affectationVal = document.getElementById("affectation").value;
        if (affectationVal === "1") {
          // chantier
          // Doit avoir sélection chantier + déboursé + précision
          if (!document.getElementById("chantier").value) {
            alert("Sélectionnez d'abord un chantier.");
            return;
          }
          if (!document.getElementById("motif_select").value) {
            alert("Sélectionnez un déboursé.");
            return;
          }
          if (getSelectedLigneDebourseIds().length === 0) {
            alert("Sélectionnez au moins une ligne de déboursé.");
            return;
          }
          // Détails obligatoires à l'étape 2
          const details = (
            document.getElementById("details_demande")?.value || ""
          ).trim();
          if (!details) {
            alert("Renseignez les détails de la demande.");
            try {
              document.getElementById("details_demande").focus();
            } catch (e) {}
            return;
          }
        } else if (affectationVal === "19") {
          // bureau
          if (!document.getElementById("motif_input").value.trim()) {
            alert("Renseignez le motif de la demande.");
            return;
          }
          // Détails obligatoires à l'étape 2 (bureau)
          const details = (
            document.getElementById("details_demande")?.value || ""
          ).trim();
          if (!details) {
            alert("Renseignez les détails de la demande.");
            try {
              document.getElementById("details_demande").focus();
            } catch (e) {}
            return;
          }
        }

        updateSupplierSelectionUI();
        targetStep = "#step-supplier";
      }

      if (currentActive && currentActive.id === "step-supplier") {
        const supplierState = updateSupplierSelectionUI();
        if (
          supplierState.required &&
          !document.getElementById("fournisseur_id")?.value
        ) {
          alert(
            "Sélectionnez un fournisseur validé avant de continuer vers les données de décaissement.",
          );
          try {
            document.getElementById("fournisseur_search")?.focus();
          } catch (e) {}
          return;
        }
      }

      // Validation avant de quitter l'étape 3 (montants + mode paiement)
      if (currentActive && currentActive.id === "step-3") {
        const affectationVal = document.getElementById("affectation").value;
        const selectedIds = getSelectedLigneDebourseIds();
        const isChantierNewSystem =
          affectationVal === "1" && selectedIds.length > 0;
        const modePaiement = document.querySelector(
          'input[name="mode_paiement"]:checked',
        );
        if (!modePaiement) {
          alert("Sélectionnez un mode de paiement.");
          return;
        }

        if (isChantierNewSystem) {
          const st = checkDepassementsMulti();
          if (st.hasMissingDetails) {
            alert(
              "Veuillez renseigner les détails pour chaque fiche (colonne Détails).",
            );
            return;
          }
          if (st.hasEmpty || st.total <= 0) {
            alert(
              "Veuillez saisir un montant demandé (supérieur à 0) pour chaque ligne sélectionnée.",
            );
            return;
          }
        } else {
          const montant = parseNumberFCFA(
            document.getElementById("montant")?.value,
          );
          if (!montant || montant <= 0) {
            alert("Veuillez saisir un montant valide.");
            return;
          }
        }
      }
      // Exiger signature avant récap (passage de l'étape 4 -> 5)
      if (
        currentActive &&
        currentActive.id === "step-4" &&
        targetStep === "#step-5"
      ) {
        const sigVal = document.getElementById("signature_base64")?.value || "";
        if (!sigVal) {
          alert(
            "La signature est obligatoire. Dessinez et cliquez sur 'Utiliser la signature'.",
          );
          return;
        }
      }
      if (!activateStep(targetStep)) {
        return;
      }

      // En arrivant sur l'étape 3, si le tableau multi existe déjà, pré-remplir les détails non saisis
      if (targetStep === "#step-3") {
        Promise.resolve()
          .then(() => refreshMontantStepFromSelection())
          .then(() => {
            try {
              syncDefaultDetailsToLignes(false);
            } catch (e) {}
          })
          .catch(() => {
            try {
              syncDefaultDetailsToLignes(false);
            } catch (e) {}
          });
      }

      // Si on arrive sur l'étape 2, appliquer le preset éventuel (BUREAU FIDEST BANAMUR)
      if (targetStep === "#step-2") {
        applyEnterprisePresetForStep2IfNeeded();
      }
    });
  });

  // Si l'utilisateur modifie les détails généraux, propager aux lignes encore vides / non modifiées
  const detailsDemandeEl = document.getElementById("details_demande");
  if (detailsDemandeEl) {
    autoGrowTextarea(detailsDemandeEl);
    detailsDemandeEl.addEventListener("input", () =>
      syncDefaultDetailsToLignes(false),
    );
    detailsDemandeEl.addEventListener("change", () =>
      syncDefaultDetailsToLignes(false),
    );
    detailsDemandeEl.addEventListener("input", () =>
      autoGrowTextarea(detailsDemandeEl),
    );
  }

  document.querySelectorAll(".prevBtn").forEach((button) => {
    button.addEventListener("click", function () {
      let targetStep = this.getAttribute("data-target");
      const currentActive = document.querySelector(".setup-content.active");
      if (currentActive && currentActive.id === "step-3") {
        const supplierState = getSupplierRequirementState();
        if (supplierState.required) {
          targetStep = "#step-supplier";
        }
      }
      activateStep(targetStep);
    });
  });

  // Avant soumission, s'assurer que seuls les champs de l'étape active restent "required"
  if (form) {
    form.addEventListener("submit", function () {
      syncRequiredWithVisibility();
    });
  }

  // Sélection de l'entreprise + préparation du preset éventuel
  document.querySelectorAll(".company-card").forEach((card) => {
    card.addEventListener("click", function () {
      const rawCompany = this.getAttribute("data-company");
      const targetStep = this.getAttribute("data-target") || "#step-1";

      if (isSpecialBureauFidestBanamur(rawCompany)) {
        window.entrepriseSelectionnee =
          normalizeEntrepriseSelection(rawCompany);
        window.__enterprisePreset = {
          active: true,
          applied: false,
          type: "bureau-fidest-banamur",
        };
        console.log(
          "Entreprise choisie (preset bureau):",
          rawCompany,
          "->",
          window.entrepriseSelectionnee,
        );
      } else {
        clearEnterprisePreset();
        window.entrepriseSelectionnee = rawCompany;
        console.log("Entreprise choisie : " + window.entrepriseSelectionnee);
      }

      persistEntrepriseSelection(rawCompany);
      activateStep(targetStep);
    });
  });

  // Initialisation des champs (avec gardes si éléments absents)
  const motifInputWrapper = document.getElementById("motif-input");
  const motifInput = document.getElementById("motif_input");
  const motifSelectWrapper = document.getElementById("motif-select");
  const motifSelect = document.getElementById("motif_select");
  const chantierSelectWrapper = document.getElementById("chantier-select");
  if (motifInputWrapper) motifInputWrapper.style.display = "none";
  if (motifInput) motifInput.setAttribute("disabled", "disabled");
  if (motifSelectWrapper) motifSelectWrapper.style.display = "none";
  if (motifSelect) motifSelect.setAttribute("disabled", "disabled");
  if (chantierSelectWrapper) chantierSelectWrapper.style.display = "none";

  // Gestion du champ d'affectation
  document
    .getElementById("affectation")
    .addEventListener("change", function () {
      const affectation = this.value;

      const bureauInfo = document.getElementById("bureau-chantier-info");
      if (bureauInfo) {
        const show = affectation === "19" || isBureauPresetActive();
        bureauInfo.style.display = show ? "block" : "none";
        if (show) {
          if (isBureauPresetActive()) {
            bureauInfo.innerHTML =
              "<strong>INFO</strong> — Pour l’affectation <strong>Bureau</strong>, la demande est automatiquement rattachée au chantier technique <strong>CH0066</strong>. Vous n’avez rien à modifier.";
          } else {
            bureauInfo.innerHTML =
              "<strong>INFO</strong> — Affectation <strong>Bureau</strong> : renseignez le motif et les détails de la demande.";
          }
        }
      }

      const chantierLabel = document.getElementById("chantier-label");
      if (chantierLabel) {
        chantierLabel.textContent = isBureauPresetActive()
          ? "Chantier (Bureau)"
          : "Code Chantier";
      }

      // Si on quitte l'affectation chantier, on déverrouille le chantier (au cas où)
      if (affectation !== "1") {
        setChantierLocked(false);
        // Si l'utilisateur change d'affectation manuellement, on ne garde pas l'auto-select
        window.__chantierAutoSelect = null;
      }

      if (chantierSelectWrapper)
        chantierSelectWrapper.style.display =
          affectation === "1" ? "block" : "none";
      const bureauSelectWrapper = document.getElementById("bureau-select");
      if (bureauSelectWrapper)
        bureauSelectWrapper.style.display =
          affectation === "19" ? "block" : "none";

      // Gestion du champ motif
      if (affectation === "1") {
        console.log("Affectation = chantier");
        if (motifSelect) motifSelect.setAttribute("disabled", "disabled");

        const serviceEl = document.getElementById("service");
        if (serviceEl) serviceEl.required = false; // Rendre service non requis

        // Affectation chantier : affiche le select pour le motif
        if (motifSelectWrapper) motifSelectWrapper.style.display = "block";
        if (motifInputWrapper) motifInputWrapper.style.display = "none";
        if (motifInput) motifInput.value = ""; // Réinitialise l'input
        if (chantierSelectWrapper)
          chantierSelectWrapper.style.display = "block";

        // Charger automatiquement les chantiers
        console.log(
          "Affectation chantier sélectionnée, chargement des chantiers...",
        );
        if (!window.entrepriseSelectionnee) {
          console.warn(
            "Aucune entreprise sélectionnée. Impossible de charger les chantiers.",
          );
          // Afficher un message à l'utilisateur
          const chantierSelect = document.getElementById("chantier");
          chantierSelect.innerHTML =
            '<option value="">Veuillez d\'abord sélectionner une entreprise</option>';
        } else {
          console.log(
            "Affectation chantier sélectionnée, chargement des chantiers...",
          );
          chargerChantiers();
        }
      } else if (affectation === "19") {
        console.log("Affectation  = Bureau");
        if (motifInput) motifInput.removeAttribute("disabled");

        const chantierEl = document.getElementById("chantier");
        if (chantierEl) chantierEl.required = false; // Rendre service non requis

        // Affectation bureau : affiche l'input pour le motif
        if (motifSelectWrapper) motifSelectWrapper.style.display = "none";
        if (motifInputWrapper) motifInputWrapper.style.display = "block";
        if (motifSelect) motifSelect.value = ""; // Réinitialise le select
        if (chantierSelectWrapper) chantierSelectWrapper.style.display = "none";
      } else {
        if (motifSelectWrapper) motifSelectWrapper.style.display = "none";
        if (motifInputWrapper) motifInputWrapper.style.display = "none";
        if (chantierSelectWrapper) chantierSelectWrapper.style.display = "none";
      }
    });

  // Fonction pour charger les chantiers (GLOBALE)
  window.chargerChantiers = function () {
    console.log(
      "Fonction chargerChantiers appelée, entreprise:",
      window.entrepriseSelectionnee,
    );

    const chantierSelect = document.getElementById("chantier");
    const chantierLoading = document.getElementById("chantier-loading");

    // Vider le select et afficher le loader
    chantierSelect.innerHTML = '<option value="">--Choisir Chantier--</option>';
    chantierLoading.style.display = "block";

    // Préparer l'URL avec le paramètre entreprise si défini
    let url = "request/get_chantiers.php";
    let requestOptions = {
      method: "GET",
    };

    if (window.entrepriseSelectionnee) {
      requestOptions = {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: "entreprise=" + encodeURIComponent(window.entrepriseSelectionnee),
      };
    }

    fetch(url, requestOptions)
      .then((response) => {
        if (!response.ok) {
          throw new Error("Erreur réseau");
        }
        return response.text().then((text) => {
          const trimmedText = text.trim();

          if (!trimmedText) {
            throw new Error(
              "Réponse vide retournée par request/get_chantiers.php",
            );
          }

          try {
            return JSON.parse(trimmedText);
          } catch (error) {
            console.error("Réponse brute get_chantiers.php:", trimmedText);
            throw new Error(
              "Réponse JSON invalide retournée par request/get_chantiers.php",
            );
          }
        });
      })
      .then((data) => {
        chantierLoading.style.display = "none";

        if (data.success && Array.isArray(data.chantiers)) {
          let count = 0;
          const blockedReasons = [];
          data.chantiers.forEach((chantier) => {
            const option = document.createElement("option");
            option.value = chantier.id;
            option.text = `${chantier.code} - ${chantier.titre}${Number(chantier.disbursement_blocked) === 1 ? " — BLOQUÉ (urgence à régulariser)" : ""}`;
            if (Number(chantier.disbursement_blocked) === 1) {
              option.disabled = true;
              option.dataset.blockReason = chantier.disbursement_block_reason || "Ce chantier est bloqué par une urgence décaissée non régularisée.";
              option.style.color = "#94a3b8";
              blockedReasons.push(`${chantier.code} : ${chantier.disbursement_block_reason}`);
            }
            chantierSelect.appendChild(option);
            count++;
          });
          console.log("Chantiers insérés dans le select:", count);
          const blockReasonBox = document.getElementById("chantier-block-reason");
          if (blockReasonBox) {
            blockReasonBox.style.display = blockedReasons.length ? "block" : "none";
            blockReasonBox.innerHTML = blockedReasons.length
              ? `<strong>Chantiers indisponibles pour tout nouveau décaissement :</strong><br>${blockedReasons.map((reason) => reason.replace(/</g, "&lt;").replace(/>/g, "&gt;")).join("<br>")}`
              : "";
          }

          if (data.chantiers.length === 0) {
            const option = document.createElement("option");
            option.value = "";
            option.text = `Aucun chantier ${
              window.entrepriseSelectionnee || ""
            } disponible`;
            option.disabled = true;
            chantierSelect.appendChild(option);
          }

          // Auto-sélection éventuelle (ex: preset CH0066)
          if (
            window.__chantierAutoSelect &&
            (window.__chantierAutoSelect.codeEquals ||
              window.__chantierAutoSelect.textIncludes)
          ) {
            const codeNeedle = String(
              window.__chantierAutoSelect.codeEquals || "",
            )
              .toLowerCase()
              .trim();
            const textNeedle = String(
              window.__chantierAutoSelect.textIncludes || "",
            )
              .toLowerCase()
              .trim();

            // 1) Match direct sur les données (plus fiable)
            let matchedId = null;
            try {
              const matchData = (data.chantiers || []).find((c) => {
                const titre = String(c.titre || "").toLowerCase();
                const code = String(c.code || "").toLowerCase();
                if (codeNeedle) return code.trim() === codeNeedle;
                return titre.includes(textNeedle) || code.includes(textNeedle);
              });
              if (matchData && matchData.id) matchedId = String(matchData.id);
            } catch (e) {
              matchedId = null;
            }

            // 2) Fallback sur texte des options
            if (!matchedId) {
              const opts = Array.from(chantierSelect.options || []);
              const foundOpt = opts.find((o) =>
                (() => {
                  const t = String(o.text || "").toLowerCase();
                  if (codeNeedle) return t.startsWith(codeNeedle);
                  return t.includes(textNeedle);
                })(),
              );
              if (foundOpt && foundOpt.value)
                matchedId = String(foundOpt.value);
            }

            if (matchedId) {
              chantierSelect.value = matchedId;
              // Déclencher les chargements dépendants (débourses/lignes)
              try {
                chantierSelect.dispatchEvent(
                  new Event("change", { bubbles: true }),
                );
              } catch (e) {
                chantierSelect.dispatchEvent(new Event("change"));
              }

              // Verrouiller/griser le chantier si demandé, sans perdre la valeur au submit
              if (window.__chantierAutoSelect.lock) {
                setChantierLocked(true);
              }
            } else {
              console.warn(
                "Preset chantier introuvable dans la liste :",
                window.__chantierAutoSelect.codeEquals ||
                  window.__chantierAutoSelect.textIncludes,
              );
              setChantierLocked(false);
            }
          }
        } else {
          const option = document.createElement("option");
          option.value = "";
          option.text = data.message || "Erreur lors du chargement";
          option.disabled = true;
          chantierSelect.appendChild(option);
        }
      })
      .catch((error) => {
        chantierLoading.style.display = "none";
        console.error("Erreur lors du chargement des chantiers :", error);

        const option = document.createElement("option");
        option.value = "";
        option.text = "Erreur de chargement";
        option.disabled = true;
        chantierSelect.appendChild(option);
      });
  }; // Fin de window.chargerChantiers

  //Lors de la sélection d'un service (affectation bureau)
  const serviceElForChange = document.getElementById("service");
  if (serviceElForChange) {
    serviceElForChange.addEventListener("change", function () {
      const service = this.value;
      if (service !== "") {
        if (motifInput) motifInput.removeAttribute("disabled");
      } else if (motifInput) {
        motifInput.setAttribute("disabled", "disabled");
      }
    });
  }

  // Lors de la sélection d'un chantier (NOUVEAU SYSTÈME)
  document.getElementById("chantier").addEventListener("change", function () {
    const chantierId = this.value;
    console.log("Chantier sélectionné:", chantierId);

    // Réinitialiser les champs dépendants
    const motifSelect = document.getElementById("motif_select");
    const precisionSelect = document.getElementById("precision_select");
    motifSelect.innerHTML = '<option value="">--Choisir un déboursé--</option>';
    precisionSelect.innerHTML =
      '<option value="" disabled selected>--Choisir une demande précise--</option>';
    motifSelect.setAttribute("disabled", "disabled");
    precisionSelect.setAttribute("disabled", "disabled");

    // Cacher les sections d'informations
    document.getElementById("montant-info-section").style.display = "none";
    document.getElementById("fiches-existantes-section").style.display = "none";

    if (chantierId !== "") {
      console.log("Chargement des déboursés pour le chantier:", chantierId);
      // Afficher le loader pour les déboursés
      document.getElementById("chantier-loading").style.display = "block";

      // Charger les déboursés pour ce chantier
      fetch("request/get_debourses_by_chantier.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: "chantier_id=" + encodeURIComponent(chantierId),
      })
        .then((response) => {
          if (!response.ok) {
            throw new Error("Erreur réseau ou réponse invalide");
          }
          return response.json();
        })
        .then((data) => {
          console.log("Réponse déboursés:", data);
          document.getElementById("chantier-loading").style.display = "none";

          if (data.success && Array.isArray(data.debourses)) {
            console.log(`${data.debourses.length} déboursés trouvés`);
            data.debourses.forEach((debourse) => {
              const option = document.createElement("option");
              option.value = debourse.id;
              option.text = debourse.titre;
              // Si l'API renvoie un regroupement, on stocke la liste des IDs à utiliser
              // pour charger toutes les lignes correspondantes.
              if (debourse.ids_csv) {
                option.dataset.ids = String(debourse.ids_csv);
              }
              motifSelect.appendChild(option);
            });
            motifSelect.removeAttribute("disabled");
          } else {
            console.log("Aucun déboursé trouvé ou erreur:", data.message);
            const option = document.createElement("option");
            option.value = "";
            option.text = data.message || "Aucun déboursé disponible";
            option.disabled = true;
            motifSelect.appendChild(option);
          }
        })
        .catch((error) => {
          document.getElementById("chantier-loading").style.display = "none";
          console.error("Erreur lors du chargement des déboursés :", error);
        });
    }
  });

  // Lors de la sélection d'un déboursé (NOUVEAU SYSTÈME)
  document
    .getElementById("motif_select")
    .addEventListener("change", function () {
      const selectedOption = this.selectedOptions
        ? this.selectedOptions[0]
        : null;
      const debourseId =
        (selectedOption && selectedOption.dataset
          ? selectedOption.dataset.ids
          : null) || this.value;
      const precisionSelect = document.getElementById("precision_select");
      const precisionWrapper = document.getElementById("precision-select");

      // Réinitialiser le select des demandes précises
      precisionSelect.innerHTML =
        '<option value="" disabled selected>--Choisir une demande précise--</option>';
      precisionSelect.setAttribute("disabled", "disabled");
      if (precisionWrapper) precisionWrapper.style.display = "none";

      // Cacher les sections d'informations
      document.getElementById("montant-info-section").style.display = "none";
      document.getElementById("fiches-existantes-section").style.display =
        "none";

      if (debourseId !== "") {
        // Charger les lignes de déboursé
        fetch("request/get_lignes_debourse.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: "debourse_id=" + encodeURIComponent(debourseId),
        })
          .then((response) => {
            if (!response.ok) {
              throw new Error("Erreur réseau ou réponse invalide");
            }
            return response.text().then((text) => {
              const trimmedText = text.trim();

              if (!trimmedText) {
                throw new Error(
                  "Réponse vide retournée par request/get_lignes_debourse.php",
                );
              }

              try {
                return JSON.parse(trimmedText);
              } catch (error) {
                console.error(
                  "Réponse brute get_lignes_debourse.php:",
                  trimmedText,
                );
                throw new Error(
                  "Réponse JSON invalide retournée par request/get_lignes_debourse.php",
                );
              }
            });
          })
          .then((data) => {
            if (data.success && Array.isArray(data.lignes)) {
              data.lignes.forEach((ligne) => {
                const option = document.createElement("option");
                option.value = ligne.id;
                option.text = `${ligne.designation} (${parseInt(
                  ligne.montant_prevu,
                  10,
                ).toLocaleString("fr-FR")} FCFA)`;
                option.setAttribute("data-montant", ligne.montant_prevu);
                precisionSelect.appendChild(option);
              });
              precisionSelect.removeAttribute("disabled");
              if (precisionWrapper) precisionWrapper.style.display = "block";
            } else {
              const option = document.createElement("option");
              option.value = "";
              option.text = data.message || "Aucune demande précise disponible";
              option.disabled = true;
              precisionSelect.appendChild(option);
              if (precisionWrapper) precisionWrapper.style.display = "block"; // on affiche quand même pour informer
            }
          })
          .catch((error) => {
            console.error(
              "Erreur lors du chargement des lignes de déboursé :",
              error,
            );
          });
      }
    });

  // Lors de la sélection d'une demande précise (NOUVEAU SYSTÈME)
  document
    .getElementById("precision_select")
    .addEventListener("change", function () {
      refreshMontantStepFromSelection();
    });

  let montantPrecision = null;

  // Quand on saisit le montant, on vérifie le dépassement (garde la logique existante)
  document.getElementById("montant").addEventListener("input", function () {
    checkMontantDepassement();
  });

  function checkMontantDepassement() {
    const montant = parseFloat(document.getElementById("montant").value);
    const alertDiv = document.getElementById("montant-depassement-alert");
    if (montantPrecision && montant > montantPrecision) {
      alertDiv.style.display = "block";
    } else {
      alertDiv.style.display = "none";
    }
  }

  // Fonction pour afficher l'aperçu des fichiers
  function displayPreview(input, previewId) {
    const file = input.files[0];
    if (file && file.type.startsWith("image/")) {
      const reader = new FileReader();
      reader.onload = function (e) {
        const preview = document.getElementById(previewId);
        preview.innerHTML = `<img src="${e.target.result}" alt="Aperçu" />`;
      };
      reader.readAsDataURL(file);
    } else {
      alert("Veuillez télécharger une image valide.");
    }
  }

  // Gestion des zones de drag-and-drop
  function setupDropArea(areaId, inputId, previewId) {
    const dropArea = document.getElementById(areaId);
    const fileInput = document.getElementById(inputId);

    dropArea.addEventListener("click", () => fileInput.click());

    dropArea.addEventListener("dragover", (e) => {
      e.preventDefault();
      dropArea.style.backgroundColor = "#eaf4ff";
    });

    dropArea.addEventListener("dragleave", (e) => {
      e.preventDefault();
      dropArea.style.backgroundColor = "#f9f9f9";
    });

    dropArea.addEventListener("drop", (e) => {
      e.preventDefault();
      dropArea.style.backgroundColor = "#f9f9f9";
      fileInput.files = e.dataTransfer.files;
      displayPreview(fileInput, previewId);
    });

    fileInput.addEventListener("change", () =>
      displayPreview(fileInput, previewId),
    );
  }

  // Initialiser les zones de drag-and-drop
  setupDropArea("photo-drop-area", "photo_demandeur", "photo-preview");
  setupDropArea("cni-drop-area", "cni_demandeur", "cni-preview");

  // Gestion de la soumission du formulaire
  document
    .getElementById("form_fiche")
    .addEventListener("submit", function (e) {
      e.preventDefault(); // Empêche le rechargement de la page
      // Validation spécifique bloc transport si affiché
      const specBox = document.getElementById("spec-transport-box");
      if (specBox && specBox.style.display !== "none") {
        const requiredIds = [
          "chauffeur_nom",
          "chauffeur_tel",
          "vehicule_matricule",
          "volume_m3",
          "quantite_carburant",
        ];
        for (const id of requiredIds) {
          const el = document.getElementById(id);
          if (!el || !String(el.value).trim()) {
            alert("Veuillez renseigner le champ: " + id.replace(/_/g, " "));
            el && el.focus();
            return;
          }
        }
      }
      // Validation signature obligatoire
      const sigVal = document.getElementById("signature_base64")?.value || "";
      if (!sigVal) {
        alert("Signature obligatoire avant soumission.");
        return;
      }

      // Collecte des données du formulaire
      var formData = new FormData(this);

      // Ajout des fichiers pour la photo et la CNI, si nécessaires
      var photoFile = document.getElementById("photo_demandeur").files[0];
      var cniFile = document.getElementById("cni_demandeur").files[0];
      const affectationVal = document.getElementById("affectation").value;

      // Nouveau système si affectation chantier (1) et au moins une ligne de déboursé est sélectionnée
      const selectedIds = getSelectedLigneDebourseIds();
      const useNewSystem = affectationVal === "1" && selectedIds.length > 0;

      let urlEndpoint;
      let payloadFormData;

      if (useNewSystem) {
        urlEndpoint = "request/submit_new_fiche.php";
        payloadFormData = new FormData();
        const supplierState = updateSupplierSelectionUI();
        const fournisseurOption = getSelectedSupplierOption();
        if (supplierState.required && !fournisseurOption) {
          alert(
            "Sélectionnez un fournisseur validé avant de soumettre cette fiche.",
          );
          return;
        }
        // Mapping des champs attendus par submit_new_fiche.php
        payloadFormData.append(
          "beficiaire_fiche",
          document.getElementById("nom_prenom").value.trim(),
        );
        payloadFormData.append(
          "tel_beneficiaire_fiche",
          document.getElementById("telephone").value.trim(),
        );

        // Bénéficiaire = personnel (optionnel)
        const isPersonnel = !!document.getElementById(
          "beneficiaire_is_personnel",
        )?.checked;
        payloadFormData.append(
          "beneficiaire_is_personnel",
          isPersonnel ? "1" : "0",
        );
        payloadFormData.append(
          "personnel_id",
          String(document.getElementById("personnel_id")?.value || ""),
        );

        // Fallback serveur: médias RH (si présents)
        payloadFormData.append(
          "personnel_has_photo",
          String(document.getElementById("personnel_has_photo")?.value || "0"),
        );
        payloadFormData.append(
          "personnel_has_cni",
          String(document.getElementById("personnel_has_cni")?.value || "0"),
        );
        payloadFormData.append(
          "personnel_photo_url",
          String(document.getElementById("personnel_photo_url")?.value || ""),
        );
        payloadFormData.append(
          "personnel_cni_url",
          String(document.getElementById("personnel_cni_url")?.value || ""),
        );
        // Montants par ligne (multi)
        const montantsByLigne = getMontantsByLigneFromTable();
        const detailsByLigne = getDetailsByLigneFromTable();
        const st = checkDepassementsMulti();
        if (st.hasMissingDetails) {
          alert(
            "Veuillez renseigner les détails pour chaque fiche (colonne Détails) avant de soumettre.",
          );
          return;
        }
        if (st.hasEmpty || st.total <= 0) {
          alert(
            "Veuillez saisir un montant demandé (supérieur à 0) pour chaque ligne sélectionnée.",
          );
          return;
        }

        // Pour compat/backward: on envoie un montant total
        payloadFormData.append("montant_fiche", String(st.total));
        payloadFormData.append(
          "chantier_id",
          document.getElementById("chantier").value,
        );

        // Envoyer la/les lignes sélectionnées
        selectedIds.forEach((id) => {
          payloadFormData.append("ligne_debourse_ids[]", String(id));
          payloadFormData.append(
            `montants_by_ligne[${id}]`,
            String(montantsByLigne[id] || 0),
          );
          payloadFormData.append(
            `details_by_ligne[${id}]`,
            String(detailsByLigne[id] || ""),
          );
        });
        // compat (première ligne)
        payloadFormData.append("precision_select", String(selectedIds[0]));

        payloadFormData.append(
          "details_demande",
          document.getElementById("details_demande").value.trim(),
        );
        payloadFormData.append(
          "fournisseur_id",
          fournisseurOption ? fournisseurOption.value : "",
        );
        payloadFormData.append(
          "fournisseur_nom",
          getSupplierDisplayName(fournisseurOption) || "",
        );
        payloadFormData.append(
          "fournisseur_contact_nom",
          fournisseurOption?.dataset?.contact || "",
        );
        payloadFormData.append(
          "fournisseur_telephone",
          fournisseurOption?.dataset?.telephone || "",
        );
        payloadFormData.append(
          "fournisseur_type",
          fournisseurOption?.dataset?.type || "",
        );
        payloadFormData.append(
          "fournisseur_categories",
          fournisseurOption?.dataset?.categories || "",
        );
        const modePaiement = document.querySelector(
          'input[name="mode_paiement"]:checked',
        );
        if (modePaiement)
          payloadFormData.append("mode_paiement", modePaiement.value);
        // Fichiers selon noms attendus
        const photoFile = document.getElementById("photo_demandeur").files[0];
        const cniFile = document.getElementById("cni_demandeur").files[0];
        if (photoFile) payloadFormData.append("photo_beneficiaire", photoFile);
        if (cniFile) payloadFormData.append("cni_beneficiaire", cniFile);
        // Ajouter signature
        payloadFormData.append(
          "signature_base64",
          document.getElementById("signature_base64").value || "",
        );
        // Ajouter champs spécifiques transport si présents
        const specBox = document.getElementById("spec-transport-box");
        if (specBox && specBox.style.display !== "none") {
          const mapFields = {
            chauffeur_nom: "chauffeur_nom",
            chauffeur_tel: "chauffeur_tel",
            vehicule_matricule: "vehicule_matricule",
            volume_m3: "volume_m3",
            quantite_carburant: "quantite_carburant",
          };
          Object.keys(mapFields).forEach((id) => {
            const v = document.getElementById(id)?.value || "";
            payloadFormData.append(mapFields[id], v);
          });
        }
      } else {
        // Ancien système (bureau ou pas de ligne précise sélectionnée)
        urlEndpoint = "request/insert_fiche.php";
        payloadFormData = new FormData(this); // récupère tous les champs existants
        // Ajouter fichier sous noms existants
        const photoFile = document.getElementById("photo_demandeur").files[0];
        const cniFile = document.getElementById("cni_demandeur").files[0];
        if (photoFile) payloadFormData.append("photo_demandeur", photoFile);
        if (cniFile) payloadFormData.append("cni_demandeur", cniFile);
      }

      console.log(
        "Soumission fiche | Nouveau système:",
        useNewSystem,
        "Endpoint:",
        urlEndpoint,
      );

      $.ajax({
        url: urlEndpoint,
        type: "POST",
        data: payloadFormData,
        contentType: false,
        processData: false,
        beforeSend: function () {
          $("#submit-button").prop("disabled", true).text("Envoi...");
        },
        success: function (response) {
          if (typeof response === "string") {
            try {
              response = JSON.parse(response);
            } catch (e) {
              console.error("Réponse non JSON", response);
            }
          }
          console.log("Réponse serveur:", response);
          if (response.success === true || response.status === "success") {
            const numFiche = response.num_fiche || response.message || "";
            const idFiche = response.id_fiche || "";
            const nums = Array.isArray(response.num_fiches)
              ? response.num_fiches
              : null;
            // Redirection vers page succès
            const extra =
              nums && nums.length > 1
                ? `&count=${encodeURIComponent(String(nums.length))}&nums=${encodeURIComponent(nums.join(","))}`
                : "";
            window.location.href = `success_fiche.php?num_fiche=${encodeURIComponent(
              numFiche,
            )}&id=${encodeURIComponent(idFiche)}${extra}`;
          } else {
            alert(response.message || "Erreur lors de la création de la fiche");
          }
        },
        error: function (xhr, status, error) {
          console.error("Erreur AJAX:", status, error, xhr.responseText);
          alert("Erreur réseau ou serveur.");
        },
        complete: function () {
          $("#submit-button").prop("disabled", false).text("Soumettre");
        },
      });
    });

  //Script recap
  // Fonction pour mettre à jour le récapitulatif à chaque changement dans le formulaire
  const updateRecap = () => {
    // Récupérer les valeurs des champs du formulaire
    const nomPrenom = document.getElementById("nom_prenom").value;
    const telephone = document.getElementById("telephone").value;
    const affectation = document.getElementById("affectation").value;
    const motifSelectEl = document.getElementById("motif_select");
    const motifInputEl = document.getElementById("motif_input");
    const motif =
      (motifSelectEl && motifSelectEl.selectedIndex >= 0
        ? motifSelectEl.options[motifSelectEl.selectedIndex]?.text ||
          motifSelectEl.value
        : "") || (motifInputEl ? motifInputEl.value : "");
    const motifFinalHidden = document.getElementById("motif_final");
    if (motifFinalHidden) motifFinalHidden.value = motif;
    const details = document.getElementById("details_demande").value;
    const fournisseurOption = getSelectedSupplierOption();
    const supplierState = updateSupplierSelectionUI();
    // Montant recap: total si multi-lignes, sinon montant unique
    let montant = document.getElementById("montant")?.value || "";
    const affectationVal = document.getElementById("affectation")?.value || "";
    const selectedIds = getSelectedLigneDebourseIds();
    if (affectationVal === "1" && selectedIds.length > 0) {
      const st = checkDepassementsMulti();
      montant = st && st.total ? String(st.total) : "";
    }

    // Récupérer la valeur du mode de paiement sélectionné
    const modePaiement =
      document.querySelector('input[name="mode_paiement"]:checked')?.value ||
      "Non sélectionné";

    // Mettre à jour le récapitulatif avec les données du formulaire
    document.getElementById("recap-nom-prenom").textContent =
      nomPrenom || "Non renseigné";

    //Formatage du numéro de téléphone
    const formatTelephone = (telephone) => {
      if (!telephone) return "Non renseigné";

      // Supprimer tous les caractères non numériques
      const cleanNumber = telephone.replace(/\D/g, "");

      // Vérifier la longueur et formater le numéro
      if (cleanNumber.length === 10) {
        return cleanNumber.replace(
          /(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/,
          "$1 $2 $3 $4 $5",
        );
      } else if (cleanNumber.length === 8) {
        // Format alternatif (par exemple, pour un numéro sans indicatif)
        return cleanNumber.replace(
          /(\d{2})(\d{2})(\d{2})(\d{2})/,
          "$1 $2 $3 $4",
        );
      }

      // Si le numéro est invalide, renvoyer "Non renseigné"
      return "Non renseigné";
    };

    // Utilisation dans le script
    document.getElementById("recap-telephone").textContent =
      formatTelephone(telephone);

    // Pour l'affectation, préciser son libellé
    // NB: le preset "BUREAU FIDEST BANAMUR" utilise techniquement l'affectation Chantier (1)
    // pour conserver le workflow déboursés/multi-fiches, mais doit s'afficher en "Bureau".
    let affectationText;

    if (isBureauPresetActive()) {
      affectationText = "Bureau — chantier technique CH0066";
    } else {
      switch (parseInt(affectation, 10)) {
        case 1:
          affectationText = "Chantier";
          break;
        case 19:
          affectationText = "Bureau";
          break;
        default:
          affectationText = "Non renseigné"; // Valeur par défaut si aucune correspondance
      }
    }

    document.getElementById("recap-affectation").textContent = affectationText;
    //Fin affectation
    document.getElementById("recap-motif").textContent =
      motif || "Non renseigné";
    document.getElementById("recap-details").textContent =
      details || "Non renseigné";
    const recapFournisseurRow = document.getElementById(
      "recap-fournisseur-row",
    );
    const recapFournisseurTypeRow = document.getElementById(
      "recap-fournisseur-type-row",
    );
    const recapFournisseurCategoriesRow = document.getElementById(
      "recap-fournisseur-categories-row",
    );
    const recapFournisseurContactRow = document.getElementById(
      "recap-fournisseur-contact-row",
    );
    const recapFournisseurTelephoneRow = document.getElementById(
      "recap-fournisseur-telephone-row",
    );
    const recapFournisseurEmailRow = document.getElementById(
      "recap-fournisseur-email-row",
    );
    const recapFournisseurAddressRow = document.getElementById(
      "recap-fournisseur-address-row",
    );
    const recapFournisseurDocumentsRow = document.getElementById(
      "recap-fournisseur-documents-row",
    );
    const shouldShowSupplier = supplierState.required || !!fournisseurOption;
    if (recapFournisseurRow) {
      recapFournisseurRow.style.display = shouldShowSupplier ? "block" : "none";
    }
    if (recapFournisseurTypeRow) {
      recapFournisseurTypeRow.style.display = shouldShowSupplier
        ? "block"
        : "none";
    }
    if (recapFournisseurCategoriesRow) {
      recapFournisseurCategoriesRow.style.display = shouldShowSupplier
        ? "block"
        : "none";
    }
    if (recapFournisseurContactRow) {
      recapFournisseurContactRow.style.display = shouldShowSupplier
        ? "block"
        : "none";
    }
    if (recapFournisseurTelephoneRow) {
      recapFournisseurTelephoneRow.style.display = shouldShowSupplier
        ? "block"
        : "none";
    }
    if (recapFournisseurEmailRow) {
      recapFournisseurEmailRow.style.display = shouldShowSupplier
        ? "block"
        : "none";
    }
    if (recapFournisseurAddressRow) {
      recapFournisseurAddressRow.style.display = shouldShowSupplier
        ? "block"
        : "none";
    }
    if (recapFournisseurDocumentsRow) {
      recapFournisseurDocumentsRow.style.display = shouldShowSupplier
        ? "block"
        : "none";
    }
    const recapFournisseur = document.getElementById("recap-fournisseur");
    const recapFournisseurType = document.getElementById(
      "recap-fournisseur-type",
    );
    const recapFournisseurCategories = document.getElementById(
      "recap-fournisseur-categories",
    );
    const recapFournisseurContact = document.getElementById(
      "recap-fournisseur-contact",
    );
    const recapFournisseurTelephone = document.getElementById(
      "recap-fournisseur-telephone",
    );
    const recapFournisseurEmail = document.getElementById(
      "recap-fournisseur-email",
    );
    const recapFournisseurAddress = document.getElementById(
      "recap-fournisseur-address",
    );
    const recapFournisseurDocuments = document.getElementById(
      "recap-fournisseur-documents",
    );
    if (recapFournisseur) {
      recapFournisseur.textContent =
        getSupplierDisplayName(fournisseurOption) ||
        (supplierState.required ? "Non renseigné" : "—");
    }
    if (recapFournisseurType) {
      recapFournisseurType.textContent =
        fournisseurOption?.dataset?.typeLabel ||
        (supplierState.required ? "Non renseigné" : "—");
    }
    if (recapFournisseurCategories) {
      recapFournisseurCategories.textContent =
        fournisseurOption?.dataset?.categories ||
        (supplierState.required ? "Non renseigné" : "—");
    }
    if (recapFournisseurContact) {
      recapFournisseurContact.textContent =
        fournisseurOption?.dataset?.contact || "Non renseigné";
    }
    if (recapFournisseurTelephone) {
      recapFournisseurTelephone.textContent =
        fournisseurOption?.dataset?.telephone || "Non renseigné";
    }
    if (recapFournisseurEmail) {
      recapFournisseurEmail.textContent =
        fournisseurOption?.dataset?.email || "Non renseigné";
    }
    if (recapFournisseurAddress) {
      recapFournisseurAddress.textContent =
        fournisseurOption?.dataset?.address || "Non renseigné";
    }
    if (recapFournisseurDocuments) {
      const documents = getSupplierDocuments(fournisseurOption);
      recapFournisseurDocuments.textContent = documents.length
        ? documents.join(", ")
        : supplierState.required
          ? "Non renseigné"
          : "—";
    }
    const recapMontant = document.getElementById("recap-montant");
    if (recapMontant) {
      recapMontant.textContent = montant
        ? `${parseInt(montant, 10)
            .toLocaleString("fr-FR")
            .replace(/ /g, "\u00A0")} FCFA`
        : "Non renseigné";
    }

    renderRecapBudgetTable();

    document.getElementById("recap-mode").textContent =
      modePaiement || "Non renseigné";

    // Mettre à jour les images (si des fichiers sont chargés)
    const photoFile = document.getElementById("photo_demandeur").files[0];
    const cniFile = document.getElementById("cni_demandeur").files[0];

    const personnelPhotoUrl = String(
      document.getElementById("personnel_photo_url")?.value || "",
    ).trim();
    const personnelCniUrl = String(
      document.getElementById("personnel_cni_url")?.value || "",
    ).trim();

    if (photoFile) {
      document.getElementById("photo-identite").src =
        URL.createObjectURL(photoFile);
    } else if (personnelPhotoUrl) {
      document.getElementById("photo-identite").src = personnelPhotoUrl;
    } else {
      document.getElementById("photo-identite").src =
        "https://assets.codeur.com/uli89xy5439jz7s5ud67n92qi9g8"; // Image par défaut si pas de photo
    }

    if (cniFile) {
      document.getElementById("cni-image").src = URL.createObjectURL(cniFile);
    } else if (personnelCniUrl) {
      document.getElementById("cni-image").src = personnelCniUrl;
    } else {
      document.getElementById("cni-image").src =
        "https://app.fidest.ci/logi/img/logo_connex.png"; // Image par défaut si pas de CNI
    }
  };

  // Ajouter des écouteurs d'événements pour mettre à jour le récapitulatif à chaque modification
  document.getElementById("nom_prenom").addEventListener("input", updateRecap);
  document.getElementById("telephone").addEventListener("input", updateRecap);
  document
    .getElementById("affectation")
    .addEventListener("change", updateRecap);
  document
    .getElementById("motif_select")
    .addEventListener("change", updateRecap);
  document.getElementById("motif_input").addEventListener("input", updateRecap);
  document
    .getElementById("details_demande")
    .addEventListener("input", updateRecap);
  document.getElementById("montant").addEventListener("input", updateRecap);
  document.querySelectorAll('input[name="mode_paiement"]').forEach((radio) => {
    radio.addEventListener("change", updateRecap);
  });

  // Ajouter un événement pour les fichiers photo et CNI
  document
    .getElementById("photo_demandeur")
    .addEventListener("change", updateRecap);
  document
    .getElementById("cni_demandeur")
    .addEventListener("change", updateRecap);
  document.getElementById("fournisseur_id")?.addEventListener("change", () => {
    updateSupplierSelectionUI();
    updateRecap();
  });
  document
    .getElementById("fournisseur_search")
    ?.addEventListener("input", filterSupplierOptions);
  document
    .getElementById("precision_select")
    ?.addEventListener("change", () => {
      updateSupplierSelectionUI();
      updateRecap();
    });

  // Initialiser le récapitulatif dès que la page est chargée
  initSupplierPicker();
  filterSupplierOptions();
  updateSupplierSelectionUI();
  updateRecap();

  /************* LOGIQUE SPÉCIFICATIONS TRANSPORT (CH060 + carburant) *************/
  function getSelectedChantierCode() {
    const sel = document.getElementById("chantier");
    if (!sel) return "";
    const opt = sel.options[sel.selectedIndex];
    if (!opt) return "";
    // format: CODE - Titre
    const txt = opt.text || "";
    const code = txt.split(" - ")[0].trim();
    return code;
  }
  function selectedPrecisionLabel() {
    const sel = document.getElementById("precision_select");
    if (!sel) return "";
    const selected = Array.from(sel.selectedOptions || []);
    if (selected.length !== 1) return "";
    const opt = selected[0];
    return opt ? opt.text || "" : "";
  }
  function evaluateTransportSpecVisibility() {
    const code = getSelectedChantierCode();
    const precisionLib = selectedPrecisionLabel().toLowerCase();
    const box = document.getElementById("spec-transport-box");
    if (!box) return;
    const isTarget = code === "CH060" && /carburant/.test(precisionLib);
    box.style.display = isTarget ? "block" : "none";
    // Si on masque, on nettoie les champs (mais conserve defaults volume/quantité)
    if (!isTarget) {
      ["chauffeur_nom", "chauffeur_tel", "vehicule_matricule"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = "";
      });
    } else {
      // pré-remplir volume/quantité si vide
      const vol = document.getElementById("volume_m3");
      const qt = document.getElementById("quantite_carburant");
      if (vol && !vol.value) vol.value = "19.2";
      if (qt && !qt.value) qt.value = "50";
    }
  }
  // Brancher sur changement chantier / precision
  const chantierSel = document.getElementById("chantier");
  if (chantierSel) {
    chantierSel.addEventListener("change", () => {
      setTimeout(evaluateTransportSpecVisibility, 100); // après rechargement éventuel des options
    });
  }
  const precisionSel = document.getElementById("precision_select");
  if (precisionSel) {
    precisionSel.addEventListener("change", evaluateTransportSpecVisibility);
  }
  // Initial
  evaluateTransportSpecVisibility();

  /******************* SIGNATURE PAD *******************/
  const signatureCanvas = document.getElementById("signature-pad");
  if (signatureCanvas) {
    const ctx = signatureCanvas.getContext("2d");
    let drawing = false;
    let hasSignature = false;
    let strokeCount = 0; // nombre de segments
    let totalDistance = 0; // distance cumulée pour estimer si signature vide
    const MIN_STROKES = 1;
    const MIN_DISTANCE = 50;
    const statusEl = document.getElementById("signature-status");
    const hintEl = document.getElementById("signature-hint");

    function resizeCanvas() {
      const ratio = window.devicePixelRatio || 1;
      const rect = signatureCanvas.getBoundingClientRect();
      // Sauvegarde contenu existant si besoin (optionnel: ici on efface toujours)
      signatureCanvas.width = rect.width * ratio;
      signatureCanvas.height = rect.height * ratio;
      ctx.setTransform(1, 0, 0, 1, 0, 0); // reset
      ctx.scale(ratio, ratio); // échelle d'affichage seulement
      ctx.lineCap = "round";
      ctx.lineJoin = "round";
      ctx.lineWidth = 2;
      ctx.lineWidth = 3; // ligne plus visible
      ctx.strokeStyle = "#222";
      // Effacer visuel
      ctx.fillStyle = "#fff";
      ctx.clearRect(0, 0, rect.width, rect.height);
      ctx.fillRect(0, 0, rect.width, rect.height);
      // Debug width
      // console.log('[SIGNATURE] resizeCanvas rect.width=', rect.width);
    }
    // Initial resize différé : certains layouts ne sont pas encore stabilisés à DOMContentLoaded
    resizeCanvas();
    if (signatureCanvas.getBoundingClientRect().width < 50) {
      // Retenter après layout
      setTimeout(() => {
        // console.log('[SIGNATURE] Second resize timeout');
        resizeCanvas();
      }, 150);
      requestAnimationFrame(() => {
        // console.log('[SIGNATURE] RAF resize');
        resizeCanvas();
      });
    }
    window.addEventListener("load", resizeCanvas);
    window.addEventListener("resize", resizeCanvas);
    // Observer pour changement dynamique de taille parent
    if (window.ResizeObserver) {
      const ro = new ResizeObserver(() => resizeCanvas());
      ro.observe(signatureCanvas.parentElement);
    }

    let lastPoint = null;

    function startDraw(x, y) {
      drawing = true;
      hasSignature = true;
      strokeCount++;
      ctx.beginPath();
      ctx.moveTo(x, y);
      if (hintEl) hintEl.style.display = "none";
      lastPoint = { x, y };
    }
    function drawLine(x, y) {
      if (!drawing) return;
      ctx.lineTo(x, y);
      ctx.stroke();
      if (lastPoint) {
        const dx = x - lastPoint.x;
        const dy = y - lastPoint.y;
        totalDistance += Math.sqrt(dx * dx + dy * dy);
        lastPoint = { x, y };
      }
    }
    function endDraw() {
      drawing = false;
      // Auto capture si seuil atteint (confort utilisateur)
      if (
        hasSignature &&
        strokeCount >= MIN_STROKES &&
        totalDistance >= MIN_DISTANCE &&
        hiddenSig &&
        !hiddenSig.value
      ) {
        const dataURL = signatureCanvas.toDataURL("image/png");
        hiddenSig.value = dataURL;
        if (statusEl) statusEl.textContent = "Signature capturée";
        const recapSig = document.getElementById("recap-signature");
        if (recapSig) {
          recapSig.innerHTML = `<img src="${dataURL}" alt="Signature" style="max-width:100%; max-height:110px; object-fit:contain;" />`;
        }
      }
    }

    function pointerPos(e) {
      const rect = signatureCanvas.getBoundingClientRect();
      return {
        x: e.clientX - rect.left,
        y: e.clientY - rect.top,
      };
    }

    signatureCanvas.addEventListener("mousedown", (e) => {
      const p = pointerPos(e);
      startDraw(p.x, p.y);
    });
    signatureCanvas.addEventListener("mousemove", (e) => {
      const p = pointerPos(e);
      drawLine(p.x, p.y);
    });
    window.addEventListener("mouseup", endDraw);
    // Touch
    signatureCanvas.addEventListener("touchstart", (e) => {
      e.preventDefault();
      if (e.touches.length) {
        const t = e.touches[0];
        const p = pointerPos(t);
        startDraw(p.x, p.y);
      }
    });
    signatureCanvas.addEventListener("touchmove", (e) => {
      e.preventDefault();
      if (e.touches.length) {
        const t = e.touches[0];
        const p = pointerPos(t);
        drawLine(p.x, p.y);
      }
    });
    signatureCanvas.addEventListener("touchend", (e) => {
      e.preventDefault();
      endDraw();
    });

    // Boutons
    const btnEffacer = document.getElementById("btn-effacer-signature");
    const btnValider = document.getElementById("btn-valider-signature");
    const btnAnnuler = document.getElementById("btn-annuler-signature");
    const hiddenSig = document.getElementById("signature_base64");
    function clearSignature() {
      resizeCanvas();
      hasSignature = false;
      strokeCount = 0;
      totalDistance = 0;
      if (hiddenSig) hiddenSig.value = "";
      if (statusEl) statusEl.textContent = "Aucune signature capturée";
      if (hintEl) hintEl.style.display = "block";
      // Récap
      const recapSig = document.getElementById("recap-signature");
      if (recapSig) recapSig.innerHTML = "Aucune signature";
    }
    if (btnEffacer) btnEffacer.addEventListener("click", clearSignature);
    if (btnValider)
      btnValider.addEventListener("click", () => {
        // Seuils minimaux
        if (
          !hasSignature ||
          strokeCount < MIN_STROKES ||
          totalDistance < MIN_DISTANCE
        ) {
          alert(
            "Signature trop courte ou absente. Merci de signer correctement.",
          );
          return;
        }
        const dataURL = signatureCanvas.toDataURL("image/png");
        if (hiddenSig) hiddenSig.value = dataURL;
        if (statusEl) statusEl.textContent = "Signature capturée";
        // Afficher dans le récap si présent
        const recapSig = document.getElementById("recap-signature");
        if (recapSig) {
          recapSig.innerHTML = `<img src="${dataURL}" alt="Signature" style="max-width:100%; max-height:110px; object-fit:contain;" />`;
        }
        // Génération blob pour upload fiable
        try {
          fetch(dataURL)
            .then((r) => r.blob())
            .then((b) => {
              window.signatureBlob = b;
              console.log("[SIGNATURE] Blob (validation) taille=", b.size);
            });
        } catch (err) {
          console.warn("[SIGNATURE] Echec blob validation", err);
        }
      });
    if (btnAnnuler)
      btnAnnuler.addEventListener("click", () => {
        if (confirm("Annuler la signature actuelle ?")) {
          clearSignature();
        }
      });
    // Bouton annuler signature dans récap
    const btnAnnulerRecap = document.getElementById(
      "btn-annuler-signature-recap",
    );
    if (btnAnnulerRecap)
      btnAnnulerRecap.addEventListener("click", () => {
        if (confirm("Supprimer la signature avant soumission ?")) {
          clearSignature();
        }
      });
  }
});
