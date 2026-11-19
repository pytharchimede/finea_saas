<?php
require_once __DIR__ . '/auth_check.php';

$appBasePath = \AccessManager::getApplicationBasePath();

$requestId = (int)($_GET['id'] ?? 0);
if ($requestId <= 0) {
    header('Location: ' . $appBasePath . '/selection_portail.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demande d'accès en cours</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --bg: #0f172a;
            --card: rgba(255, 255, 255, 0.92);
            --border: rgba(148, 163, 184, 0.18);
            --accent: #2563eb;
            --accent2: #0f766e;
            --ink: #0f172a;
            --muted: #64748b;
            --danger: #dc2626;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', sans-serif;
            background: radial-gradient(circle at top left, rgba(37, 99, 235, 0.18), transparent 28%), linear-gradient(135deg, #eff6ff 0%, #e0f2fe 52%, #f8fafc 100%);
            color: var(--ink);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .shell {
            width: min(760px, 100%);
            border-radius: 32px;
            padding: 34px;
            background: var(--card);
            border: 1px solid var(--border);
            box-shadow: 0 32px 70px rgba(15, 23, 42, 0.14);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.08);
            color: var(--accent);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        h1 {
            margin: 18px 0 12px;
            font-size: clamp(2rem, 4vw, 3rem);
            line-height: 1.05;
            letter-spacing: -0.04em;
        }

        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.75;
            font-size: 1rem;
        }

        .progress-wrap {
            margin-top: 28px;
            padding: 22px;
            border-radius: 24px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .progress-track {
            width: 100%;
            height: 18px;
            border-radius: 999px;
            background: #dbeafe;
            overflow: hidden;
            margin-top: 12px;
        }

        .progress-bar {
            height: 100%;
            width: 22%;
            border-radius: inherit;
            background: linear-gradient(135deg, var(--accent2), var(--accent));
            transition: width 0.35s ease;
        }

        .state-card {
            margin-top: 18px;
            border-radius: 20px;
            padding: 18px 20px;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid #e2e8f0;
        }

        .state-label {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: #475569;
        }

        .state-value {
            margin-top: 8px;
            font-size: 1.02rem;
            font-weight: 700;
            color: var(--ink);
        }

        .message {
            margin-top: 10px;
            color: var(--muted);
            line-height: 1.7;
        }

        .actions {
            margin-top: 26px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 50px;
            padding: 0 20px;
            border-radius: 16px;
            text-decoration: none;
            font-weight: 700;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent2), var(--accent));
            color: #fff;
        }

        .btn-secondary {
            background: #fff;
            color: var(--ink);
            border: 1px solid #cbd5e1;
        }

        .status-success .badge {
            background: rgba(15, 118, 110, 0.1);
            color: var(--accent2);
        }

        .status-success .progress-bar {
            width: 100%;
        }

        .status-refused .badge {
            background: rgba(220, 38, 38, 0.1);
            color: var(--danger);
        }

        .status-refused .progress-bar {
            width: 100%;
            background: linear-gradient(135deg, #f97316, #dc2626);
        }

        .countdown {
            margin-top: 10px;
            font-size: 0.92rem;
            color: #475569;
            font-weight: 700;
        }

        @media (max-width: 640px) {
            .shell {
                padding: 24px;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <main class="shell" id="waitShell">
        <span class="badge" id="statusBadge"><i class="bi bi-hourglass-split"></i> Demande en cours</span>
        <h1 id="pageTitle">Votre demande d'accès est en traitement.</h1>
        <p id="introText">Le support informatique a été notifié. Cette page vérifie automatiquement l'évolution de votre demande et vous orientera dès qu'une décision sera prise.</p>

        <section class="progress-wrap">
            <div class="state-label">Jauge de traitement</div>
            <div class="progress-track">
                <div class="progress-bar" id="progressBar"></div>
            </div>
            <div class="message" id="progressMessage">En attente d'une décision de l'équipe informatique...</div>
        </section>

        <section class="state-card">
            <div class="state-label">Statut actuel</div>
            <div class="state-value" id="stateValue">Demande transmise au support</div>
            <div class="message" id="decisionMessage">Dès qu'un administrateur traite la demande, ce message sera mis à jour ici.</div>
            <div class="countdown" id="countdownBox"></div>
        </section>

        <div class="actions">
            <a href="<?= htmlspecialchars($appBasePath . '/selection_portail.php', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary"><i class="bi bi-house-door-fill"></i> Retour au portail</a>
            <button type="button" class="btn btn-secondary" onclick="history.back()"><i class="bi bi-arrow-left"></i> Retour</button>
        </div>
    </main>

    <script>
        (() => {
            const appBasePath = <?= json_encode($appBasePath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const requestId = <?= (int)$requestId ?>;
            const waitShell = document.getElementById('waitShell');
            const statusBadge = document.getElementById('statusBadge');
            const pageTitle = document.getElementById('pageTitle');
            const introText = document.getElementById('introText');
            const progressBar = document.getElementById('progressBar');
            const progressMessage = document.getElementById('progressMessage');
            const stateValue = document.getElementById('stateValue');
            const decisionMessage = document.getElementById('decisionMessage');
            const countdownBox = document.getElementById('countdownBox');
            let pollTimer = null;
            let redirectTimer = null;

            function setPendingState(request) {
                waitShell.classList.remove('status-success', 'status-refused');
                statusBadge.innerHTML = '<i class="bi bi-hourglass-split"></i> Demande en cours';
                pageTitle.textContent = 'Votre demande d\'accès est en traitement.';
                introText.textContent = 'Le support informatique a été notifié. Cette page vérifie automatiquement l\'évolution de votre demande et vous orientera dès qu\'une décision sera prise.';
                progressBar.style.width = '42%';
                progressMessage.textContent = 'Le support informatique examine l\'accès à accorder pour ' + (request.requested_page || 'la page demandée') + '.';
                stateValue.textContent = 'En attente de décision';
                decisionMessage.textContent = request.support_email_sent_at ? 'L\'email a bien été transmis au support. Vous pouvez laisser cette page ouverte.' : 'Préparation de la notification support.';
                countdownBox.textContent = '';
            }

            function runCountdown(targetUrl, textPrefix) {
                let remaining = 5;
                countdownBox.textContent = textPrefix + ' dans ' + remaining + ' seconde(s)...';
                redirectTimer = window.setInterval(() => {
                    remaining -= 1;
                    countdownBox.textContent = textPrefix + ' dans ' + remaining + ' seconde(s)...';
                    if (remaining <= 0) {
                        window.clearInterval(redirectTimer);
                        window.location.href = targetUrl;
                    }
                }, 1000);
            }

            function setGrantedState(request) {
                if (pollTimer) {
                    window.clearInterval(pollTimer);
                }
                waitShell.classList.add('status-success');
                waitShell.classList.remove('status-refused');
                statusBadge.innerHTML = '<i class="bi bi-check-circle-fill"></i> Accès accordé';
                pageTitle.textContent = 'Vos accès viennent d\'être accordés.';
                introText.textContent = 'La permission requise a été activée pour votre compte. Vous allez être redirigé automatiquement vers la page demandée.';
                progressBar.style.width = '100%';
                progressMessage.textContent = 'Le droit ' + (request.primary_permission_label || 'requis') + ' est maintenant actif pour votre profil.';
                stateValue.textContent = 'Demande approuvée';
                decisionMessage.textContent = request.decision_message || ('Décision traitée par ' + (request.admin_user_name || 'l\'équipe informatique') + '.');
                runCountdown(request.requested_page_url || (appBasePath + '/selection_portail.php'), 'Redirection vers la page demandée');
            }

            function setRefusedState(request) {
                if (pollTimer) {
                    window.clearInterval(pollTimer);
                }
                waitShell.classList.add('status-refused');
                waitShell.classList.remove('status-success');
                statusBadge.innerHTML = '<i class="bi bi-x-circle-fill"></i> Demande refusée';
                pageTitle.textContent = 'La demande d\'accès a été refusée.';
                introText.textContent = 'Le support informatique n\'a pas accordé l\'accès demandé. Vous allez être redirigé vers votre page précédente.';
                progressBar.style.width = '100%';
                progressMessage.textContent = 'Le support a refusé l\'ouverture de ce droit pour le moment.';
                stateValue.textContent = 'Demande refusée';
                decisionMessage.textContent = request.decision_message || 'Consultez votre responsable ou le support pour plus de précisions.';
                runCountdown(request.origin_url || (appBasePath + '/selection_portail.php'), 'Retour à la page précédente');
            }

            async function pollStatus() {
                try {
                    const response = await fetch(appBasePath + '/api/access_request_status.php?id=' + encodeURIComponent(String(requestId)), {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const json = await response.json();
                    if (!response.ok || !json.ok) {
                        throw new Error(json.error || 'Impossible de récupérer le statut');
                    }
                    const request = json.request || {};
                    if (request.status === 'granted') {
                        setGrantedState(request);
                        return;
                    }
                    if (request.status === 'refused') {
                        setRefusedState(request);
                        return;
                    }
                    setPendingState(request);
                } catch (error) {
                    progressMessage.textContent = error.message || 'Impossible de vérifier le statut pour le moment.';
                }
            }

            pollStatus();
            pollTimer = window.setInterval(pollStatus, 5000);
        })();
    </script>
</body>

</html>