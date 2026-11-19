/* global mqtt */

const $ = (id) => document.getElementById(id);

function setBadge(el, text, kind) {
  el.textContent = text;
  el.classList.remove("ok", "err");
  if (kind) el.classList.add(kind);
}

function safeJsonParse(s) {
  try {
    return JSON.parse(s);
  } catch {
    return null;
  }
}

function formatIoTime(ioTime) {
  // Format from YYYYMMDDHHMMSS
  if (!ioTime || typeof ioTime !== "string" || ioTime.length < 14)
    return String(ioTime ?? "");
  const y = ioTime.slice(0, 4);
  const m = ioTime.slice(4, 6);
  const d = ioTime.slice(6, 8);
  const hh = ioTime.slice(8, 10);
  const mm = ioTime.slice(10, 12);
  const ss = ioTime.slice(12, 14);
  return `${y}-${m}-${d} ${hh}:${mm}:${ss}`;
}

function addRow(tableBody, cells) {
  const tr = document.createElement("tr");
  cells.forEach((c) => {
    const td = document.createElement("td");
    td.textContent = c;
    tr.appendChild(td);
  });
  tableBody.insertBefore(tr, tableBody.firstChild);
  // keep table small
  while (tableBody.children.length > 200) {
    tableBody.removeChild(tableBody.lastChild);
  }
}

// Tabs
const tabMqtt = $("tab-mqtt");
const tabBackend = $("tab-backend");
const panelMqtt = $("panel-mqtt");
const panelBackend = $("panel-backend");

function activateTab(which) {
  const isMqtt = which === "mqtt";
  tabMqtt.classList.toggle("active", isMqtt);
  tabBackend.classList.toggle("active", !isMqtt);
  tabMqtt.setAttribute("aria-selected", String(isMqtt));
  tabBackend.setAttribute("aria-selected", String(!isMqtt));
  panelMqtt.classList.toggle("active", isMqtt);
  panelBackend.classList.toggle("active", !isMqtt);
}

tabMqtt.addEventListener("click", () => activateTab("mqtt"));
tabBackend.addEventListener("click", () => activateTab("backend"));

// ----------------------
// MQTT mode
// ----------------------
let mqttClient = null;
let mqttConnected = false;

const mqttStatus = $("mqttStatus");
const mqttConnectBtn = $("mqttConnect");
const mqttDisconnectBtn = $("mqttDisconnect");
const mqttRaw = $("mqttRaw");
const mqttTableBody = $("mqttTable").querySelector("tbody");

function mqttSaveSettings() {
  try {
    localStorage.setItem("epordo.mqtt.url", $("mqttUrl").value || "");
    localStorage.setItem("epordo.mqtt.clientId", $("mqttClientId").value || "");
    localStorage.setItem("epordo.mqtt.user", $("mqttUser").value || "");
    localStorage.setItem("epordo.mqtt.topicId", $("mqttTopicId").value || "");
    // Password: keep only for current browser tab/session.
    sessionStorage.setItem("epordo.mqtt.pass", $("mqttPass").value || "");
  } catch {
    // ignore storage errors
  }
}

function mqttRestoreSettings() {
  try {
    const url = localStorage.getItem("epordo.mqtt.url");
    const clientId = localStorage.getItem("epordo.mqtt.clientId");
    const user = localStorage.getItem("epordo.mqtt.user");
    const topicId = localStorage.getItem("epordo.mqtt.topicId");
    const pass = sessionStorage.getItem("epordo.mqtt.pass");

    if (url) $("mqttUrl").value = url;
    if (clientId) $("mqttClientId").value = clientId;
    if (user) $("mqttUser").value = user;
    if (topicId) $("mqttTopicId").value = topicId;
    if (pass) $("mqttPass").value = pass;
  } catch {
    // ignore storage errors
  }
}

function mqttCanAutoConnect() {
  const url = $("mqttUrl").value.trim();
  const username = $("mqttUser").value.trim();
  const password = $("mqttPass").value;
  const topicId = $("mqttTopicId").value.trim();
  return !!(url && username && password && topicId);
}

function mqttSetUiConnected(connected) {
  mqttConnected = connected;
  mqttConnectBtn.disabled = connected;
  mqttDisconnectBtn.disabled = !connected;
  setBadge(
    mqttStatus,
    connected ? "Connecté" : "Déconnecté",
    connected ? "ok" : null,
  );
}

function mqttAppendRaw(line) {
  const now = new Date().toISOString();
  mqttRaw.textContent = `[${now}] ${line}\n` + mqttRaw.textContent;
  // keep debug log limited
  mqttRaw.textContent = mqttRaw.textContent.slice(0, 6000);
}

mqttConnectBtn.addEventListener("click", () => {
  const url = $("mqttUrl").value.trim();
  const clientIdInput = $("mqttClientId").value.trim();
  const username = $("mqttUser").value.trim();
  const password = $("mqttPass").value;
  const topicId = $("mqttTopicId").value.trim();

  mqttSaveSettings();

  if (!url || !topicId || !username || !password) {
    mqttAppendRaw(
      "Veuillez renseigner URL + Topic ID + username/password MQTT.",
    );
    setBadge(mqttStatus, "Champs manquants", "err");
    return;
  }

  const clientId =
    clientIdInput !== ""
      ? clientIdInput
      : `web_${Math.random().toString(16).slice(2)}`;
  $("mqttClientId").value = clientId;

  // Connect using mqtt.js (WebSocket)
  try {
    mqttAppendRaw(`Connexion à ${url} (clientId=${clientId})...`);

    mqttClient = mqtt.connect(url, {
      clientId,
      username,
      password,
      clean: true,
      reconnectPeriod: 5000,
      connectTimeout: 15_000,
      keepalive: 60,
    });

    mqttClient.on("connect", () => {
      mqttSetUiConnected(true);
      const topic = `epd/cdtapi/${topicId}/update/#`;
      mqttAppendRaw(`Connecté. Subscribe: ${topic}`);
      mqttClient.subscribe(topic, { qos: 0 }, (err) => {
        if (err) {
          mqttAppendRaw(`Subscribe error: ${err.message || err}`);
          setBadge(mqttStatus, "Subscribe error", "err");
        }
      });
    });

    mqttClient.on("reconnect", () => {
      mqttAppendRaw("Reconnexion en cours...");
      setBadge(mqttStatus, "Reconnexion...", null);
    });

    mqttClient.on("error", (err) => {
      mqttAppendRaw(`Erreur: ${err?.message || err}`);
      setBadge(mqttStatus, "Erreur", "err");
    });

    mqttClient.on("close", () => {
      mqttAppendRaw("Connexion fermée.");
      mqttSetUiConnected(false);
    });

    mqttClient.on("message", (topic, payload) => {
      const text = new TextDecoder().decode(payload);
      mqttAppendRaw(`${topic} => ${text}`);

      const obj = safeJsonParse(text);
      if (obj && (topic.includes("/log") || (obj.io_time && obj.user_id))) {
        addRow(mqttTableBody, [
          formatIoTime(obj.io_time),
          String(obj.user_id ?? ""),
          String(obj.devId ?? ""),
          String(obj.sbxlh ?? ""),
          String(obj.io_mode ?? ""),
          String(obj.verify_mode ?? ""),
        ]);
      }
    });

    setBadge(mqttStatus, "Connexion...", null);
    mqttConnectBtn.disabled = true;
  } catch (e) {
    mqttAppendRaw(`Exception: ${e?.message || e}`);
    setBadge(mqttStatus, "Erreur", "err");
  }
});

mqttDisconnectBtn.addEventListener("click", () => {
  if (!mqttClient) return;
  mqttAppendRaw("Déconnexion...");
  mqttClient.end(true);
  mqttClient = null;
  mqttSetUiConnected(false);
});

// Persist MQTT fields as user types
["mqttUrl", "mqttClientId", "mqttUser", "mqttPass", "mqttTopicId"].forEach(
  (id) => {
    const el = $(id);
    if (!el) return;
    el.addEventListener("input", mqttSaveSettings);
  },
);

// ----------------------
// Backend mode
// ----------------------
const captchaImg = $("captchaImg");
const captchaReload = $("captchaReload");
const beLogin = $("beLogin");
const beStatus = $("beStatus");
const beFetch = $("beFetch");
const bePoll = $("bePoll");
const beStop = $("beStop");
const beRaw = $("beRaw");
const beTableBody = $("beTable").querySelector("tbody");
const bePollStatus = $("bePollStatus");

let beSelectedDayKey = null;
let beSelectedDateLabel = "";

let pollTimer = null;

function beAppendRaw(obj) {
  const s = typeof obj === "string" ? obj : JSON.stringify(obj, null, 2);
  beRaw.textContent = s;
}

async function loadCaptcha() {
  // Fetch captcha as a blob to ensure cookies/session are preserved.
  // This avoids edge cases where the <img> request doesn't attach cookies as expected.
  const url = `api/captcha.php?x=${Date.now()}`;
  const res = await fetch(url, {
    cache: "no-store",
    credentials: "same-origin",
  });
  if (!res.ok) {
    throw new Error(`Captcha HTTP ${res.status}`);
  }
  const blob = await res.blob();

  const old = captchaImg.dataset.blobUrl;
  if (old) {
    try {
      URL.revokeObjectURL(old);
    } catch {
      // ignore
    }
  }

  const blobUrl = URL.createObjectURL(blob);
  captchaImg.dataset.blobUrl = blobUrl;
  captchaImg.src = blobUrl;
}

async function loadCaptchaSafe() {
  try {
    await loadCaptcha();
  } catch (e) {
    const msg = e?.message || String(e);
    // Ne bloque pas l'app si le captcha est indisponible.
    beAppendRaw({ ok: false, error: `Captcha: ${msg}` });
    captchaImg.removeAttribute("src");
    captchaImg.alt = "Captcha indisponible";
  }
}

captchaReload.addEventListener("click", loadCaptchaSafe);

async function apiPost(path, data) {
  try {
    const res = await fetch(path, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "same-origin",
      body: JSON.stringify(data || {}),
    });

    const text = await res.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch {
      json = text;
    }
    return { status: res.status, data: json };
  } catch (e) {
    return {
      status: 0,
      data: {
        ok: false,
        error: e?.message || "Erreur réseau (fetch)",
      },
    };
  }
}

function backendSetLoggedIn(isLoggedIn) {
  beFetch.disabled = !isLoggedIn;
  bePoll.disabled = !isLoggedIn;
  beStop.disabled = !isLoggedIn;
  setBadge(
    beStatus,
    isLoggedIn ? "Connecté" : "Non connecté",
    isLoggedIn ? "ok" : null,
  );
}

async function backendRefreshSessionState() {
  // If a token exists already in PHP session, start automatically.
  try {
    const res = await fetch("api/me.php", { cache: "no-store" });
    const data = await res.json();
    if (data?.ok && data?.hasToken) {
      backendSetLoggedIn(true);
      startBackendPolling();
      return true;
    }
  } catch {
    // ignore
  }

  backendSetLoggedIn(false);
  await loadCaptchaSafe();
  return false;
}

async function startBackendPolling() {
  if (pollTimer) return;
  bePollStatus.textContent = "Polling démarré (toutes les 5s)...";
  await fetchAttendanceOnce();
  pollTimer = window.setInterval(fetchAttendanceOnce, 5000);
}

beLogin.addEventListener("click", async () => {
  const username = $("beUser").value.trim();
  const password = $("bePass").value;
  const yzm = $("captchaCode").value.trim();
  const lang = $("beLang").value.trim() || "en";

  if (!username || !password || !yzm) {
    setBadge(beStatus, "Champs manquants", "err");
    return;
  }

  setBadge(beStatus, "Connexion...", null);
  const { status, data } = await apiPost("api/login.php", {
    yhmc: username,
    yhmm: password,
    yzm,
    lang,
  });
  beAppendRaw(data);

  if (status === 200 && data && data.ok) {
    backendSetLoggedIn(true);
    // Mode automatique: démarre le polling dès que le login réussit.
    startBackendPolling();
  } else {
    backendSetLoggedIn(false);
    if (status === 0) {
      setBadge(beStatus, "Erreur réseau/serveur", "err");
    } else {
      setBadge(beStatus, "Échec login", "err");
    }
    // Captcha often expires after a try
    await loadCaptchaSafe();
  }
});

function normalizeBackendRecords(resp) {
  // The backend format isn't fully documented. We'll try a few common shapes.
  // Return array of records.
  const d = resp?.data;
  if (!d) return [];

  // If server returns {data: {data: [...]}}
  if (Array.isArray(d?.data?.data)) return d.data.data;
  if (Array.isArray(d?.data?.rows)) return d.data.rows;
  if (Array.isArray(d?.data?.list)) return d.data.list;

  // If returns {data: [...]}
  if (Array.isArray(d?.data)) return d.data;

  // DataTables-like payload: { aaData: [...] }
  if (Array.isArray(d?.aaData)) return d.aaData;

  return [];
}

function pick(v, fallback = "") {
  if (v === null || v === undefined) return fallback;
  return String(v);
}

function addBackendRow(r) {
  // Some backends return aaData as array-of-arrays.
  if (Array.isArray(r)) {
    const cells = r.slice(0, 6).map((v) => pick(v));
    while (cells.length < 6) cells.push("");
    addRow(beTableBody, cells);
    return;
  }

  // Web platform shape: { cqkrq, ygxm, bmmc, qklist, qkcount, ... }
  if (r && typeof r === "object" && ("qklist" in r || "cqkrq" in r)) {
    const date = pick(r.cqkrq || r.riqi);
    const employeeName = pick(r.ygxm);
    const employeeId = pick(r.yggh || r.ygid || r.djh);
    const dept = pick(r.bmmc);
    const device = pick(r.tbmmc || r.sbmc || r.deviceName);
    const type = pick(r.qklist || r.qkcount || r.io_mode || r.type || r.lx);
    addRow(beTableBody, [date, employeeName, employeeId, dept, device, type]);
    return;
  }

  // Web platform (queryQkjlyxx.do): d1..d31 per day (HTML with </br>)
  if (
    r &&
    typeof r === "object" &&
    ("ygxm" in r || "bmmc" in r) &&
    Object.keys(r).some((k) => /^d\d{1,2}$/.test(k))
  ) {
    const dateLabel = beSelectedDateLabel || "";
    const employeeName = pick(r.ygxm);
    const employeeId = pick(r.yggh || r.djh || r.id);
    const dept = pick(r.bmmc);
    const device = pick(r.tbmmc || r.sbid || r.sbmc || r.deviceName);

    let times = "";
    if (beSelectedDayKey && beSelectedDayKey in r) {
      times = pick(r[beSelectedDayKey]);
    } else {
      // fallback: show some day column if selected day not known
      times = pick(r.d1 || r.d15 || r.d16 || r.d20);
    }
    times = times.replaceAll("</br>", ", ").replaceAll("<br>", ", ");

    addRow(beTableBody, [
      dateLabel,
      employeeName,
      employeeId,
      dept,
      device,
      times,
    ]);
    return;
  }

  // Unknown field names; we try common ones.
  const dt = pick(r.io_time || r.kqsj || r.sj || r.time || r.riqi);
  const employeeName = pick(r.ygxm || r.name || r.xm);
  const employeeId = pick(r.yggh || r.empId || r.user_id || r.userid);
  const dept = pick(r.bmmc || r.dept || r.deptName);
  const device = pick(r.tbmmc || r.sbmc || r.deviceName);
  const type = pick(r.io_mode || r.type || r.lx);

  addRow(beTableBody, [dt, employeeName, employeeId, dept, device, type]);
}

async function fetchAttendanceOnce() {
  const start = $("beStart").value;
  const end = $("beEnd").value;
  const pageSize = Number($("bePageSize").value || 20);
  const lang = $("beLang").value.trim() || "en_US";

  // Extract selected day to render the proper dXX column (platform uses d1..d31).
  const selectedDay = start ? Number(start.slice(8, 10)) : NaN;
  beSelectedDayKey = Number.isFinite(selectedDay) ? `d${selectedDay}` : null;
  beSelectedDateLabel = start || "";

  // attendance month: the platform uses YYYY-MM (ex: 2026-04)
  const month = start ? start.slice(0, 7) : "";

  const payload = {
    currentpage: 1,
    sEcho: 1,
    iDisplayStart: 0,
    iDisplayLength: pageSize,
    page: 0,
    tcj: "",
    lang,
    s_kqyf: month,
    ksrq_search: start ? start.replaceAll("-", "") : "",
    jsrq_search: end ? end.replaceAll("-", "") : "",
  };

  const { status, data } = await apiPost("api/attendance.php", payload);
  beAppendRaw(data);

  if (status !== 200 || !data?.ok) {
    bePollStatus.textContent = "Erreur de récupération.";
    return;
  }

  const records = normalizeBackendRecords(data);
  // Remplace l’affichage à chaque refresh pour éviter les doublons en polling.
  beTableBody.textContent = "";
  records.forEach(addBackendRow);
  bePollStatus.textContent = `OK (${records.length} enregistrements)`;
}

beFetch.addEventListener("click", fetchAttendanceOnce);

bePoll.addEventListener("click", startBackendPolling);

beStop.addEventListener("click", () => {
  if (pollTimer) {
    window.clearInterval(pollTimer);
    pollTimer = null;
  }
  bePollStatus.textContent = "Arrêté.";
});

// init
setBadge(beStatus, "Vérification session...", null);

// suggest dates: today
const today = new Date();
const yyyy = today.getFullYear();
const mm = String(today.getMonth() + 1).padStart(2, "0");
const dd = String(today.getDate()).padStart(2, "0");
$("beStart").value = `${yyyy}-${mm}-${dd}`;
$("beEnd").value = `${yyyy}-${mm}-${dd}`;

// Auto mode
mqttRestoreSettings();
backendRefreshSessionState();

// Auto-connect MQTT if everything is already filled (e.g. password manager or session restore)
setTimeout(() => {
  if (!mqttConnected && !mqttClient && mqttCanAutoConnect()) {
    mqttAppendRaw("Auto-connexion MQTT (champs déjà remplis)...");
    mqttConnectBtn.click();
  }
}, 300);
