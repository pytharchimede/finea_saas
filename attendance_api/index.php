<?php

declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';
$cfg = epordo_config();

?>
<!doctype html>
<html lang="fr">

<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Epordo — Pointage temps réel</title>
    <link rel="stylesheet" href="assets/app.css" />
</head>

<body>
    <div class="container">
        <header class="header">
            <h1>Epordo — Pointage du personnel</h1>
            <p class="muted">
                Interface locale (WAMP). Deux modes : MQTT (temps réel) et Backend (API + captcha).
            </p>
            <p class="notice">
                Sécurité : évitez d’exposer cette page sur Internet. Les identifiants ne sont pas stockés en base ; le token est gardé en session PHP.
            </p>
        </header>

        <div class="tabs" role="tablist">
            <button id="tab-mqtt" class="tab active" type="button" role="tab" aria-selected="true">MQTT (temps réel)</button>
            <button id="tab-backend" class="tab" type="button" role="tab" aria-selected="false">Backend (API)</button>
        </div>

        <main>
            <section id="panel-mqtt" class="panel active" role="tabpanel" aria-labelledby="tab-mqtt">
                <h2>Connexion MQTT (WebSocket)</h2>

                <div class="grid">
                    <label>
                        <span>URL WebSocket</span>
                        <input id="mqttUrl" type="text" value="ws://66.94.99.149:8083/mqtt" />
                    </label>
                    <label>
                        <span>ClientId</span>
                        <input id="mqttClientId" type="text" placeholder="ex: web_" />
                    </label>
                    <label>
                        <span>Username MQTT</span>
                        <input id="mqttUser" type="text" placeholder="fourni par Epordo" />
                    </label>
                    <label>
                        <span>Password MQTT</span>
                        <input id="mqttPass" type="password" placeholder="fourni par Epordo" />
                    </label>
                    <label>
                        <span>Topic ID</span>
                        <input id="mqttTopicId" type="text" placeholder="ex: 35" />
                    </label>
                </div>

                <div class="row">
                    <button id="mqttConnect" type="button" class="primary">Connecter</button>
                    <button id="mqttDisconnect" type="button" class="secondary" disabled>Déconnecter</button>
                    <span id="mqttStatus" class="badge">Déconnecté</span>
                </div>

                <h3>Pointages reçus</h3>
                <div class="tableWrap">
                    <table class="table" id="mqttTable">
                        <thead>
                            <tr>
                                <th>Date/heure</th>
                                <th>User ID</th>
                                <th>Device</th>
                                <th>SN</th>
                                <th>In/Out</th>
                                <th>Mode</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <details class="details">
                    <summary>Debug MQTT (messages bruts)</summary>
                    <pre id="mqttRaw" class="pre"></pre>
                </details>

                <p class="muted small">
                    Abonnement : <code>epd/cdtapi/&lt;ID&gt;/update/#</code> (inclut <code>.../update/log</code>). Si vous ne recevez rien, vérifiez que le device est en mode MQTT côté Epordo.
                </p>
            </section>

            <section id="panel-backend" class="panel" role="tabpanel" aria-labelledby="tab-backend">
                <h2>Backend Epordo (token + requêtes)</h2>

                <div class="row">
                    <button id="captchaReload" class="secondary" type="button">Recharger captcha</button>
                    <span class="muted small">Le captcha est requis par l’API.</span>
                </div>
                <div class="row captchaRow">
                    <img id="captchaImg" alt="captcha" />
                    <label>
                        <span>Captcha</span>
                        <input id="captchaCode" type="text" placeholder="code" />
                    </label>
                </div>

                <div class="grid">
                    <label>
                        <span>Username</span>
                        <input id="beUser" type="text" value="<?= htmlspecialchars((string)$cfg['defaultUsername'], ENT_QUOTES, 'UTF-8') ?>" placeholder="A00..." />
                    </label>
                    <label>
                        <span>Password</span>
                        <input id="bePass" type="password" value="<?= htmlspecialchars((string)$cfg['defaultPassword'], ENT_QUOTES, 'UTF-8') ?>" placeholder="••••••" />
                    </label>
                    <label>
                        <span>Lang</span>
                        <input id="beLang" type="text" value="<?= htmlspecialchars((string)$cfg['lang'], ENT_QUOTES, 'UTF-8') ?>" />
                    </label>
                </div>

                <div class="row">
                    <button id="beLogin" class="primary" type="button">Se connecter</button>
                    <span id="beStatus" class="badge">Non connecté</span>
                </div>

                <h3>Récupération des pointages</h3>
                <div class="grid">
                    <label>
                        <span>Début (YYYY-MM-DD)</span>
                        <input id="beStart" type="date" />
                    </label>
                    <label>
                        <span>Fin (YYYY-MM-DD)</span>
                        <input id="beEnd" type="date" />
                    </label>
                    <label>
                        <span>Taille page</span>
                        <input id="bePageSize" type="number" value="20" min="1" max="200" />
                    </label>
                </div>

                <div class="row">
                    <button id="beFetch" class="secondary" type="button" disabled>Charger une fois</button>
                    <button id="bePoll" class="secondary" type="button" disabled>Démarrer polling</button>
                    <button id="beStop" class="secondary" type="button" disabled>Arrêter</button>
                    <span id="bePollStatus" class="muted small"></span>
                </div>

                <div class="tableWrap">
                    <table class="table" id="beTable">
                        <thead>
                            <tr>
                                <th>Date/heure</th>
                                <th>Employé</th>
                                <th>Matricule</th>
                                <th>Département</th>
                                <th>Badgeuse</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <details class="details">
                    <summary>Debug backend (réponse brute)</summary>
                    <pre id="beRaw" class="pre"></pre>
                </details>
            </section>
        </main>
    </div>

    <!-- mqtt.js via CDN (browser build) -->
    <script src="https://unpkg.com/mqtt@4.6.7/dist/mqtt.min.js"></script>
    <script src="assets/app.js"></script>
</body>

</html>