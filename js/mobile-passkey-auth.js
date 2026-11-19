(function () {
  "use strict";

  const card = document.getElementById("mobile-passkey-card");
  const button = document.getElementById("mobile-passkey-confirm");
  const message = document.getElementById("mobile-passkey-message");
  if (!card || !button) return;

  function decode(value) {
    const base64 = value.replace(/-/g, "+").replace(/_/g, "/").padEnd(Math.ceil(value.length / 4) * 4, "=");
    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0)).buffer;
  }

  function encode(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = "";
    bytes.forEach((byte) => (binary += String.fromCharCode(byte)));
    return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
  }

  function requestOptions(options) {
    options.challenge = decode(options.challenge);
    options.allowCredentials = (options.allowCredentials || []).map((item) => ({ ...item, id: decode(item.id) }));
    return options;
  }

  function credentialJson(credential) {
    return {
      id: credential.id,
      rawId: encode(credential.rawId),
      type: credential.type,
      response: {
        clientDataJSON: encode(credential.response.clientDataJSON),
        authenticatorData: encode(credential.response.authenticatorData),
        signature: encode(credential.response.signature),
        userHandle: credential.response.userHandle ? encode(credential.response.userHandle) : null,
      },
      clientExtensionResults: credential.getClientExtensionResults(),
    };
  }

  async function post(url, body) {
    const response = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(body),
    });
    const data = await response.json();
    if (!response.ok || data.status === "error") throw new Error(data.message || "Opération impossible.");
    return data;
  }

  function show(text, error) {
    message.textContent = text;
    message.className = "msg " + (error ? "err" : "ok");
  }

  function scannerIdentity() {
    const key = "finea_mobile_scanner_id";
    let token = localStorage.getItem(key) || "";
    if (!/^[A-Za-z0-9_-]{43}$/.test(token)) {
      const bytes = new Uint8Array(32);
      crypto.getRandomValues(bytes);
      token = encode(bytes);
      localStorage.setItem(key, token);
    }
    const ua = navigator.userAgent || "";
    let label = /iPhone/i.test(ua) ? "iPhone" : (/iPad/i.test(ua) ? "iPad" : (/Android/i.test(ua) ? "Téléphone Android" : "Téléphone"));
    if (/Edg\//i.test(ua)) label += " · Edge";
    else if (/Firefox\//i.test(ua)) label += " · Firefox";
    else if (/Chrome\//i.test(ua)) label += " · Chrome";
    else if (/Safari\//i.test(ua)) label += " · Safari";
    return { device_token: token, device_label: label };
  }

  if (!window.PublicKeyCredential || !navigator.credentials) {
    button.disabled = true;
    show("Ce téléphone ou ce navigateur ne prend pas en charge les Passkeys. Ouvrez le QR code avec Chrome, Safari ou un navigateur récent.", true);
    return;
  }

  button.addEventListener("click", async () => {
    button.disabled = true;
    try {
      const token = card.dataset.token;
      const options = await post("request/mobile_passkey_options.php", { token });
      const credential = await navigator.credentials.get({ publicKey: requestOptions(options) });
      const result = await post("request/mobile_passkey_verify.php", { token, credential: credentialJson(credential), ...scannerIdentity() });
      show(result.message || "Identité confirmée. Revenez sur l’ordinateur.", false);
      button.textContent = "Identité confirmée";
    } catch (error) {
      const cancelled = error && (error.name === "NotAllowedError" || error.name === "AbortError");
      show(cancelled ? "Validation annulée. Vous pouvez réessayer." : error.message, true);
      button.disabled = false;
    }
  });
})();
