(function () {
  "use strict";

  function decode(value) {
    const base64 = value.replace(/-/g, "+").replace(/_/g, "/").padEnd(Math.ceil(value.length / 4) * 4, "=");
    const bytes = Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
    return bytes.buffer;
  }

  function encode(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = "";
    bytes.forEach((byte) => (binary += String.fromCharCode(byte)));
    return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
  }

  function creationOptions(options) {
    options.challenge = decode(options.challenge);
    options.user.id = decode(options.user.id);
    options.excludeCredentials = (options.excludeCredentials || []).map((item) => ({ ...item, id: decode(item.id) }));
    return options;
  }

  function requestOptions(options) {
    options.challenge = decode(options.challenge);
    options.allowCredentials = (options.allowCredentials || []).map((item) => ({ ...item, id: decode(item.id) }));
    return options;
  }

  function credentialJson(credential) {
    const response = credential.response;
    const result = {
      id: credential.id,
      rawId: encode(credential.rawId),
      type: credential.type,
      response: { clientDataJSON: encode(response.clientDataJSON) },
      clientExtensionResults: credential.getClientExtensionResults(),
    };
    if (response.attestationObject) {
      result.response.attestationObject = encode(response.attestationObject);
      result.response.transports = typeof response.getTransports === "function" ? response.getTransports() : [];
    } else {
      result.response.authenticatorData = encode(response.authenticatorData);
      result.response.signature = encode(response.signature);
      result.response.userHandle = response.userHandle ? encode(response.userHandle) : null;
    }
    return result;
  }

  async function post(url, body) {
    const response = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(body || {}),
    });
    const data = await response.json();
    if (!response.ok || data.status === "error") {
      const error = new Error(data.message || "Opération impossible.");
      error.code = data.code || null;
      throw error;
    }
    return data;
  }

  function message(target, text, error) {
    if (!target) return;
    target.textContent = text;
    target.style.display = "block";
    target.classList.remove("d-none", "alert-success", "alert-danger", "border-green-200", "bg-green-50", "text-green-800", "border-red-200", "bg-red-50", "text-red-800");
    target.classList.add(error ? "alert-danger" : "alert-success");
    target.classList.add(error ? "border-red-200" : "border-green-200", error ? "bg-red-50" : "bg-green-50", error ? "text-red-800" : "text-green-800");
  }

  function automaticDeviceName() {
    const ua = navigator.userAgent || "";
    if (/iPhone/i.test(ua)) return "iPhone";
    if (/iPad/i.test(ua)) return "iPad";
    if (/Android/i.test(ua)) return "Téléphone Android";
    if (/Windows/i.test(ua)) return "PC Windows";
    if (/Macintosh|Mac OS/i.test(ua)) return "Mac";
    return navigator.userAgentData?.platform || navigator.platform || "Mon appareil";
  }

  function cleanContextPart(value) {
    return String(value ?? "")
      .trim()
      .replace(/[^a-zA-Z0-9_.-]+/g, "_")
      .replace(/^_+|_+$/g, "")
      .slice(0, 60);
  }

  function deriveSignatureContext(form, submitter) {
    if (form?.dataset?.passkeyContext) return form.dataset.passkeyContext;
    const field = (name) => form?.querySelector(`[name="${name}"]`)?.value || "";
    const action = field("action") || submitter?.value || form?.id || "sign";
    const role = field("signature_role") || field("role") || field("etape") || field("approval_role") || "signature";
    const entityId = field("contract_id") || field("request_id") || field("id") || field("personnel_id") || field("mission_id") || "0";
    const period = field("period") || field("payroll_period") || "";
    const path = window.location.pathname.split("/").filter(Boolean).slice(-2).join("_") || "finea";
    return ["legacy", path, action, entityId, role, period].map(cleanContextPart).filter(Boolean).join(":").slice(0, 190);
  }

  const loginButton = document.getElementById("passkey-login-button");
  if (loginButton) {
    const target = document.querySelector(".message");
    window.activatePasskeyOnlyLogin = function () {
      document.getElementById("login-form")?.classList.add("hidden");
      document.getElementById("login-method-separator")?.classList.add("hidden");
      document.getElementById("password-recovery")?.classList.add("hidden");
      document.querySelector(".attempts-info")?.setAttribute("style", "display:none");
      if (target) target.style.display = "none";
      document.getElementById("passkey-secured-account")?.classList.remove("hidden");
      const title = document.getElementById("login-state-title");
      const subtitle = document.getElementById("login-state-subtitle");
      const icon = document.getElementById("login-state-icon");
      if (title) title.textContent = "Connexion sécurisée";
      if (subtitle) subtitle.textContent = "Votre compte accepte uniquement une Passkey";
      if (icon) icon.innerHTML = '<i class="fa-solid fa-fingerprint"></i>';
      loginButton.className = "mt-5 flex w-full items-center justify-center gap-3 rounded-2xl bg-gradient-to-r from-indigo-700 to-violet-700 px-5 py-4 text-base font-black text-white shadow-lg shadow-indigo-200 transition hover:from-indigo-800 hover:to-violet-800 focus:outline-none focus:ring-4 focus:ring-indigo-200";
      loginButton.querySelector("span").textContent = "Continuer avec ma Passkey";
    };

    document.getElementById("use-another-account")?.addEventListener("click", () => {
      const form = document.getElementById("login-form");
      form?.reset();
      form?.classList.remove("hidden");
      document.getElementById("login-method-separator")?.classList.remove("hidden");
      document.getElementById("password-recovery")?.classList.remove("hidden");
      document.getElementById("passkey-secured-account")?.classList.add("hidden");
      if (target) target.style.display = "none";
      const title = document.getElementById("login-state-title");
      const subtitle = document.getElementById("login-state-subtitle");
      const icon = document.getElementById("login-state-icon");
      if (title) title.textContent = "Connexion à votre Espace";
      if (subtitle) subtitle.textContent = "Veuillez saisir les identifiants professionnels de l’autre compte";
      if (icon) icon.innerHTML = '<i class="fa-solid fa-right-to-bracket"></i>';
      loginButton.className = "w-full py-3 text-sm font-semibold rounded-2xl border border-indigo-200 bg-indigo-50 text-indigo-800 hover:bg-indigo-100 transition flex items-center justify-center gap-2";
      loginButton.querySelector("span").textContent = "Se connecter avec une Passkey";
      form?.querySelector('[name="mail_pro"]')?.focus();
    });

    if (!window.PublicKeyCredential || !navigator.credentials) {
      loginButton.disabled = true;
      message(target, "Ce navigateur ne prend pas en charge la connexion par Passkey. Utilisez votre mot de passe ou un navigateur récent.", true);
    } else {
      loginButton.addEventListener("click", async () => {
        loginButton.disabled = true;
        try {
          const options = await post("request/passkey_login_options.php", {});
          const credential = await navigator.credentials.get({ publicKey: requestOptions(options) });
          const result = await post("request/passkey_login_verify.php", credentialJson(credential));
          message(target, result.message, false);
          window.location.href = result.redirect || "selection_portail.php";
        } catch (error) {
          const cancelled = error && (error.name === "NotAllowedError" || error.name === "AbortError");
          if (cancelled) window.activatePasskeyOnlyLogin();
          else message(target, error.message, true);
        } finally {
          loginButton.disabled = false;
        }
      });
    }
  }

  const security = document.getElementById("passkey-security");
  const registerButton = document.getElementById("passkey-register-button");
  if (security && registerButton) {
    const csrf = security.dataset.csrf;
    const endpointBase = (security.dataset.endpointBase || "request").replace(/\/$/, "");
    const target = document.getElementById("passkey-message");
    if (!window.PublicKeyCredential || !navigator.credentials) {
      registerButton.disabled = true;
      message(target, "Ce navigateur ne prend pas en charge les Passkeys. Utilisez un navigateur récent.", true);
    } else {
      registerButton.addEventListener("click", async () => {
        registerButton.disabled = true;
        try {
          const options = await post(`${endpointBase}/passkey_register_options.php`, { csrf_token: csrf });
          const credential = await navigator.credentials.create({ publicKey: creationOptions(options) });
          const deviceName = automaticDeviceName();
          const result = await post(`${endpointBase}/passkey_register_verify.php`, { csrf_token: csrf, device_name: deviceName, credential: credentialJson(credential) });
          message(target, result.message, false);
          if (result.logout_required) {
            window.setTimeout(() => { window.location.href = result.redirect || "../login.php"; }, 900);
            return;
          }
          window.location.reload();
        } catch (error) {
          const cancelled = error && (error.name === "NotAllowedError" || error.name === "AbortError");
          message(target, cancelled ? "Enregistrement de la Passkey annulé." : error.message, true);
        } finally {
          registerButton.disabled = false;
        }
      });
    }

    document.querySelectorAll(".passkey-revoke-button").forEach((button) => button.addEventListener("click", async () => {
      if (!window.confirm("Révoquer uniquement cette Passkey ?")) return;
      try {
        const row = button.closest("[data-passkey-id]");
        const result = await post(`${endpointBase}/passkey_revoke.php`, { id: Number(row.dataset.passkeyId), csrf_token: csrf });
        row.remove();
        message(target, result.message, false);
        if (result.logout_required) window.setTimeout(() => { window.location.href = result.redirect || "../login.php"; }, 900);
      } catch (error) {
        message(target, error.message, true);
      }
    }));

    document.querySelectorAll(".device-session-revoke").forEach((button) => button.addEventListener("click", async () => {
      if (!window.confirm("Déconnecter cet appareil du compte ?")) return;
      button.disabled = true;
      try {
        const row = button.closest("[data-device-session-id]");
        const result = await post(`${endpointBase}/device_session_revoke.php`, { id: Number(row.dataset.deviceSessionId), csrf_token: csrf });
        row.remove();
        message(target, result.message, false);
        if (result.logout_required) window.setTimeout(() => { window.location.href = result.redirect || "../login.php"; }, 700);
      } catch (error) {
        message(target, error.message, true);
        button.disabled = false;
      }
    }));

    const revokeOthersButton = document.getElementById("device-sessions-revoke-others");
    if (revokeOthersButton) revokeOthersButton.addEventListener("click", async () => {
      if (!window.confirm("Déconnecter tous les autres appareils et conserver uniquement celui-ci ?")) return;
      revokeOthersButton.disabled = true;
      try {
        const result = await post(`${endpointBase}/device_session_revoke.php`, { action: "revoke_others", csrf_token: csrf });
        document.querySelectorAll("[data-device-session-id]").forEach((row) => {
          if (!row.querySelector("span")?.textContent.includes("Cet appareil")) row.remove();
        });
        revokeOthersButton.remove();
        message(target, result.message, false);
      } catch (error) {
        message(target, error.message, true);
        revokeOthersButton.disabled = false;
      }
    });

    document.querySelectorAll(".mobile-scanner-revoke").forEach((button) => button.addEventListener("click", async () => {
      if (!window.confirm("Révoquer ce téléphone pour les prochaines validations par QR code ?")) return;
      button.disabled = true;
      try {
        const row = button.closest("[data-mobile-scanner-id]");
        const result = await post(`${endpointBase}/mobile_scanner_revoke.php`, { id: Number(row.dataset.mobileScannerId), csrf_token: csrf });
        row.remove();
        message(target, result.message, false);
      } catch (error) {
        message(target, error.message, true);
        button.disabled = false;
      }
    }));

    const policyToggle = document.getElementById("passkey-only-toggle");
    if (policyToggle && !policyToggle.disabled) policyToggle.addEventListener("change", async () => {
      policyToggle.disabled = true;
      try {
        const result = await post(`${endpointBase}/passkey_policy.php`, { enabled: policyToggle.checked, csrf_token: csrf });
        message(target, result.message, false);
        if (result.logout_required) {
          window.setTimeout(() => { window.location.href = result.redirect || "../login.php"; }, 900);
          return;
        }
      } catch (error) {
        policyToggle.checked = !policyToggle.checked;
        message(target, error.message, true);
      } finally {
        policyToggle.disabled = false;
      }
    });
  }

  window.FineaPasskey = {
    async confirmSignature(endpointBase, context) {
      if (!window.PublicKeyCredential || !navigator.credentials) throw new Error("Ce navigateur ne prend pas en charge les Passkeys.");
      const base = (endpointBase || window.FINEA_PASSKEY_ENDPOINT_BASE || "request").replace(/\/$/, "");
      const signatureContext = String(context || "").trim();
      let options;
      try {
        options = await post(`${base}/passkey_signature_options.php`, { context: signatureContext });
      } catch (error) {
        if (error.code === "passkey_setup_required") {
          const profileUrl = `${base.replace(/request$/, "")}decaissement/profil.php#passkey-security`;
          document.getElementById("passkey-required-modal")?.remove();
          const modal = document.createElement("div");
          modal.id = "passkey-required-modal";
          modal.className = "fixed inset-0 z-[300] flex items-end justify-center bg-slate-950/70 p-4 backdrop-blur-sm sm:items-center";
          modal.setAttribute("role", "dialog");
          modal.setAttribute("aria-modal", "true");
          modal.setAttribute("aria-labelledby", "passkey-required-title");
          modal.innerHTML = `<section class="relative w-full max-w-lg overflow-hidden rounded-[30px] border border-white/20 bg-white shadow-2xl">
            <div class="bg-gradient-to-br from-indigo-700 via-violet-700 to-sky-600 px-6 pb-7 pt-6 text-white sm:px-8">
              <button type="button" data-close class="absolute right-5 top-5 grid h-10 w-10 place-items-center rounded-full bg-white/15 text-white transition hover:bg-white/25" aria-label="Fermer"><i class="fa-solid fa-xmark"></i></button>
              <span class="grid h-14 w-14 place-items-center rounded-2xl bg-white/15 text-2xl shadow-inner"><i class="fa-solid fa-fingerprint"></i></span>
              <p class="mt-5 text-xs font-black uppercase tracking-[.22em] text-sky-100">Protection de votre signature</p>
              <h2 id="passkey-required-title" class="mt-2 pr-8 text-2xl font-black">Créez votre Passkey pour signer</h2>
              <p class="mt-2 leading-6 text-indigo-50">Avant d’utiliser votre signature de profil, FINEA doit confirmer que c’est bien vous. Cette vérification protège votre identité et le document.</p>
            </div>
            <div class="space-y-4 p-6 sm:p-8">
              <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl bg-indigo-50 p-3 text-sm font-bold text-indigo-950"><i class="fa-solid fa-user-shield mb-2 block text-lg text-indigo-600"></i>Identité vérifiée</div>
                <div class="rounded-2xl bg-sky-50 p-3 text-sm font-bold text-sky-950"><i class="fa-solid fa-mobile-screen-button mb-2 block text-lg text-sky-600"></i>Téléphone ou appareil</div>
                <div class="rounded-2xl bg-emerald-50 p-3 text-sm font-bold text-emerald-950"><i class="fa-solid fa-lock mb-2 block text-lg text-emerald-600"></i>Sans nouveau mot de passe</div>
              </div>
              <p class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-950"><i class="fa-solid fa-circle-info mr-2 text-amber-600"></i><b>À faire une seule fois :</b> ouvrez votre profil, appuyez sur « Créer ma Passkey », puis revenez signer le document.</p>
              <a href="${profileUrl}" class="flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-700 px-5 py-3.5 font-black text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-800"><i class="fa-solid fa-fingerprint"></i>Configurer ma Passkey maintenant</a>
              <button type="button" data-close class="w-full rounded-2xl border border-slate-200 px-5 py-3 font-bold text-slate-600 transition hover:bg-slate-50">Je le ferai plus tard</button>
            </div>
          </section>`;
          document.body.appendChild(modal);
          modal.querySelectorAll("[data-close]").forEach((button) => button.addEventListener("click", () => modal.remove()));
          modal.addEventListener("click", (event) => { if (event.target === modal) modal.remove(); });
          modal.querySelector("[data-close]")?.focus();
          error.handled = true;
        }
        throw error;
      }
      const credential = await navigator.credentials.get({ publicKey: requestOptions(options) });
      return post(`${base}/passkey_signature_verify.php`, { context: signatureContext, credential: credentialJson(credential) });
    }
  };

  // One browser ceremony at a time: the server challenge is session-scoped.
  const confirmSignature = window.FineaPasskey.confirmSignature;
  let signatureConfirmationInProgress = false;
  window.FineaPasskey.confirmSignature = async function (...args) {
    if (signatureConfirmationInProgress) throw new Error("Une confirmation Passkey est déjà en cours. Terminez-la avant de continuer.");
    signatureConfirmationInProgress = true;
    try { return await confirmSignature.apply(this, args); }
    finally { signatureConfirmationInProgress = false; }
  };

  const signatureForms = new Set(document.querySelectorAll("form[data-passkey-signature], #workflowSignForm, #signForm, #pvForm, #mission-form"));
  document.querySelectorAll("form").forEach((form) => {
    const action = form.querySelector('input[name="action"]')?.value || "";
    if (["save_signature", "save_employee_signature", "save_rh_signature", "sign_contract", "sign"].includes(action)) {
      signatureForms.add(form);
    }
  });
  document.querySelectorAll(".signature-value").forEach((input) => { if (input.form) signatureForms.add(input.form); });
  document.querySelectorAll(".signature-canvas").forEach((canvas) => { if (canvas.closest("form")) signatureForms.add(canvas.closest("form")); });

  signatureForms.forEach((form) => form.addEventListener("submit", async (event) => {
    if (form.hasAttribute('data-rh-manual-signature') && form.querySelector('[name="signature_method"]:checked')?.value === 'manual') return;
    if (form.id === "pvForm" && form.elements.submit_po && !form.elements.submit_po.checked) return;
    if (form.id === "mission-form") {
      const action = event.submitter?.value || form.elements.form_action?.value || "save";
      const signsOnSave = form.elements.drh_signature_confirmed?.value === "1" || form.elements.employee_signature_choice?.value === "po";
      if (!(["approve", "sign_employee"].includes(action) || (action === "save" && signsOnSave))) return;
    }
    if (form.id === "workflowDataForm" && (form.elements.action?.value !== "save" || !form.querySelector('[name="signature"]')?.value)) return;
    if (event.defaultPrevented) return;
    if (form.dataset.passkeyVerified === "1") { delete form.dataset.passkeyVerified; return; }
    event.preventDefault();
    if (form.dataset.passkeyPending === "1") return;
    form.dataset.passkeyPending = "1";
    const submitter = event.submitter;
    if (submitter) submitter.disabled = true;
    try {
      const context = deriveSignatureContext(form, submitter);
      await window.FineaPasskey.confirmSignature(form.dataset.passkeyEndpointBase, context);
      form.dataset.passkeyVerified = "1";
      if (submitter) submitter.disabled = false;
      if (typeof form.requestSubmit === "function") form.requestSubmit(submitter || undefined); else form.submit();
      delete form.dataset.passkeyVerified;
    } catch (error) {
      if (!error.handled) window.alert(error?.name === "NotAllowedError" ? "Confirmation Passkey annulée." : error.message);
      if (submitter) submitter.disabled = false;
    } finally {
      delete form.dataset.passkeyPending;
    }
  }));

  document.querySelectorAll("a[data-passkey-signature-link]").forEach((link) => link.addEventListener("click", async (event) => {
    if (link.dataset.passkeyVerified === "1") return;
    event.preventDefault();
    try {
      const context = link.dataset.passkeyContext || ["legacy_link", cleanContextPart(location.pathname), cleanContextPart(link.href)].join(":").slice(0, 190);
      await window.FineaPasskey.confirmSignature(link.dataset.passkeyEndpointBase, context);
      link.dataset.passkeyVerified = "1";
      window.location.href = link.href;
    } catch (error) {
      if (!error.handled) window.alert(error?.name === "NotAllowedError" ? "Confirmation Passkey annulée." : error.message);
    }
  }));

  document.addEventListener("click", async (event) => {
    const button = event.target.closest?.("#employeeSignButton");
    if (!button || button.dataset.passkeyVerified === "1") return;
    event.preventDefault();
    event.stopImmediatePropagation();
    button.disabled = true;
    try {
      const context = button.dataset.passkeyContext || deriveSignatureContext(button.closest("form"), button);
      await window.FineaPasskey.confirmSignature(button.dataset.passkeyEndpointBase, context);
      button.dataset.passkeyVerified = "1";
      button.disabled = false;
      button.click();
    } catch (error) {
      if (!error.handled) window.alert(error?.name === "NotAllowedError" ? "Confirmation Passkey annulée." : error.message);
      button.disabled = false;
    }
  }, true);
})();


