(function () {
  "use strict";

  const button = document.getElementById("mobile-qr-login-button");
  const panel = document.getElementById("mobile-qr-login-panel");
  const image = document.getElementById("mobile-qr-image");
  const status = document.getElementById("mobile-qr-status");
  const cancel = document.getElementById("mobile-qr-cancel");
  const countdown = document.getElementById("mobile-qr-countdown");
  if (!button || !panel || !image || !status) return;

  let timer = null;
  let pollTimer = null;
  let publicId = null;
  let remaining = 0;
  let stopped = true;

  async function post(url, body) {
    const response = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(body || {}),
    });
    const data = await response.json();
    if (!response.ok || data.status === "error") throw new Error(data.message || "Opération impossible.");
    return data;
  }

  function setStatus(text, error) {
    status.textContent = text;
    status.className = error
      ? "mt-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"
      : "mt-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800";
  }

  function stop() {
    stopped = true;
    clearInterval(timer);
    clearTimeout(pollTimer);
    timer = null;
    pollTimer = null;
  }

  function reset() {
    stop();
    publicId = null;
    panel.classList.add("hidden");
    button.disabled = false;
  }

  async function poll() {
    if (stopped || !publicId) return;
    try {
      const result = await post("request/mobile_passkey_status.php", { public_id: publicId });
      if (result.login_status === "approved") {
        stop();
        setStatus("Téléphone validé. Ouverture de votre session FINEA…", false);
        const consumed = await post("request/mobile_passkey_consume.php", { public_id: publicId });
        window.location.href = consumed.redirect || "selection_portail.php";
        return;
      }
      if (["expired", "consumed", "cancelled"].includes(result.login_status)) {
        stop();
        setStatus("Ce QR code n’est plus valide. Générez-en un nouveau.", true);
        button.disabled = false;
        return;
      }
      pollTimer = window.setTimeout(poll, 1500);
    } catch (error) {
      stop();
      setStatus(error.message, true);
      button.disabled = false;
    }
  }

  button.addEventListener("click", async () => {
    stop();
    button.disabled = true;
    panel.classList.remove("hidden");
    setStatus("Préparation du QR code sécurisé…", false);
    try {
      const result = await post("request/mobile_passkey_start.php", {});
      publicId = result.public_id;
      image.src = result.qr_data_uri;
      image.alt = "QR code de connexion FINEA à scanner avec votre téléphone";
      remaining = Number(result.expires_in || 300);
      stopped = false;
      setStatus("Scannez ce QR code avec l’appareil photo de votre téléphone puis confirmez votre Passkey sur le téléphone.", false);
      if (countdown) countdown.textContent = `${remaining}s`;
      timer = window.setInterval(() => {
        remaining -= 1;
        if (countdown) countdown.textContent = `${Math.max(0, remaining)}s`;
        if (remaining <= 0) {
          stop();
          setStatus("Le QR code a expiré. Générez-en un nouveau.", true);
          button.disabled = false;
        }
      }, 1000);
      poll();
    } catch (error) {
      stop();
      setStatus(error.message, true);
      button.disabled = false;
    }
  });

  cancel?.addEventListener("click", reset);
})();
