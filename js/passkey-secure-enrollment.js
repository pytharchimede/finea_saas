(function () {
  "use strict";

  const security = document.getElementById("passkey-security");
  const registerButton = document.getElementById("passkey-register-button");
  if (!security || !registerButton || !window.PublicKeyCredential || !navigator.credentials) return;

  const csrf = security.dataset.csrf || "";
  const endpointBase = (security.dataset.endpointBase || "request").replace(/\/$/, "");
  const target = document.getElementById("passkey-message");

  function decode(value) {
    const base64 = String(value).replace(/-/g, "+").replace(/_/g, "/").padEnd(Math.ceil(String(value).length / 4) * 4, "=");
    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0)).buffer;
  }

  function encode(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = "";
    bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
    return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
  }

  function requestOptions(options) {
    const copy = { ...options };
    copy.challenge = decode(copy.challenge);
    copy.allowCredentials = (copy.allowCredentials || []).map((item) => ({ ...item, id: decode(item.id) }));
    return copy;
  }

  function creationOptions(options) {
    const copy = { ...options, user: { ...options.user } };
    copy.challenge = decode(copy.challenge);
    copy.user.id = decode(copy.user.id);
    copy.excludeCredentials = (copy.excludeCredentials || []).map((item) => ({ ...item, id: decode(item.id) }));
    return copy;
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
      credentials: "same-origin",
    });
    const data = await response.json();
    if (!response.ok || data.status === "error") {
      const error = new Error(data.message || "Opération impossible.");
      error.code = data.code || null;
      throw error;
    }
    return data;
  }

  function show(text, isError) {
    if (!target) return;
    target.textContent = text;
    target.style.display = "block";
    target.classList.remove("d-none", "alert-success", "alert-danger", "border-green-200", "bg-green-50", "text-green-800", "border-red-200", "bg-red-50", "text-red-800");
    target.classList.add(isError ? "alert-danger" : "alert-success");
    target.classList.add(isError ? "border-red-200" : "border-green-200", isError ? "bg-red-50" : "bg-green-50", isError ? "text-red-800" : "text-green-800");
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

  async function authorizeEnrollment() {
    const authorization = await post(`${endpointBase}/passkey_enrollment_authorize_options.php`, { csrf_token: csrf });
    if (!authorization.authorization_required) return;

    show("Confirmez d’abord votre identité avec une Passkey déjà enregistrée.", false);
    const existingCredential = await navigator.credentials.get({ publicKey: requestOptions(authorization.publicKey) });
    await post(`${endpointBase}/passkey_enrollment_authorize_verify.php`, {
      csrf_token: csrf,
      credential: credentialJson(existingCredential),
    });
  }

  // Le gestionnaire historique de passkeys.js est en phase bubble. Celui-ci est
  // volontairement en capture afin d'imposer le step-up avant tout nouvel enrôlement.
  registerButton.addEventListener("click", async (event) => {
    event.preventDefault();
    event.stopImmediatePropagation();
    if (registerButton.disabled) return;

    registerButton.disabled = true;
    const originalHtml = registerButton.innerHTML;
    try {
      registerButton.innerHTML = '<i class="fa-solid fa-shield-halved"></i><span>Vérification de sécurité…</span>';
      await authorizeEnrollment();

      registerButton.innerHTML = '<i class="fa-solid fa-fingerprint"></i><span>Ajout de la nouvelle Passkey…</span>';
      const options = await post(`${endpointBase}/passkey_register_options.php`, { csrf_token: csrf });
      const newCredential = await navigator.credentials.create({ publicKey: creationOptions(options) });
      const result = await post(`${endpointBase}/passkey_register_verify.php`, {
        csrf_token: csrf,
        device_name: automaticDeviceName(),
        credential: credentialJson(newCredential),
      });

      show(result.message || "Nouvelle Passkey enregistrée.", false);
      if (result.logout_required) {
        window.setTimeout(() => { window.location.href = result.redirect || "../login.php"; }, 900);
        return;
      }
      window.setTimeout(() => window.location.reload(), 500);
    } catch (error) {
      const cancelled = error && (error.name === "NotAllowedError" || error.name === "AbortError");
      show(cancelled ? "Opération annulée. Aucune nouvelle Passkey n’a été ajoutée." : error.message, true);
    } finally {
      registerButton.disabled = false;
      registerButton.innerHTML = originalHtml;
    }
  }, true);

  // L'interface doit expliquer qu'une Passkey existante autorise les suivantes.
  if (security.querySelector("[data-passkey-id]")) {
    const heading = registerButton.closest("div")?.parentElement?.querySelector("h3");
    const description = heading?.parentElement?.querySelector("p");
    if (heading) heading.textContent = "Ajouter une autre Passkey";
    if (description) description.textContent = "Pour protéger votre compte, confirmez d’abord votre identité avec une Passkey déjà enregistrée. La nouvelle Passkey sera ensuite ajoutée.";
  }
})();
