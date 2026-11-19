<?php
require_once __DIR__ . '/../model/Auth.php';
require_once __DIR__ . '/../model/AccessManager.php';
require_once __DIR__ . '/../model/AccessRequestManager.php';
require_once __DIR__ . '/../partials/auth_finea_mark.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$intent = trim((string)($_GET['intent'] ?? $_POST['intent'] ?? 'approve'));

if (!\Auth::isLoggedIn()) {
    $_SESSION['post_login_redirect'] = $_SERVER['REQUEST_URI'] ?? (\AccessManager::getApplicationBasePath() . '/admin/access_request_review.php');
    header('Location: ' . \AccessManager::getApplicationBasePath() . '/login.php');
    exit();
}

if (!\AccessManager::hasPermission('acces_admin')) {
    header('Location: ' . \AccessManager::getApplicationBasePath() . '/acces_refuse.php?type=gestion_acces&page=' . urlencode('admin/access_request_review.php'));
    exit();
}

$manager = \AccessRequestManager::connect();
$request = null;
$message = '';
$messageTone = 'info';

try {
    if ($token === '') {
        throw new RuntimeException('Jeton de demande manquant');
    }
    $request = $manager->getRequestByAdminToken($token);
    if (!$request) {
        throw new RuntimeException('Demande introuvable ou lien expiré');
    }

    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $decision = trim((string)($_POST['decision'] ?? ''));
        $decisionMessage = trim((string)($_POST['decision_message'] ?? ''));
        $request = $manager->resolveByAdminToken($token, $decision, (int)($_SESSION['user_id'] ?? 0), (string)($_SESSION['user_name'] ?? 'Admin'), $decisionMessage !== '' ? $decisionMessage : null);
        $message = $decision === 'granted' ? 'Accès accordé avec succès.' : 'Demande refusée avec succès.';
        $messageTone = $decision === 'granted' ? 'success' : 'error';
    }
} catch (Throwable $e) {
    $message = $e->getMessage();
    $messageTone = 'error';
}

$requesterName = trim((string)($request['requester_name'] ?? 'Utilisateur'));
$requestedPage = trim((string)($request['requested_page'] ?? 'Page inconnue'));
$primaryLabel = trim((string)($request['primary_permission_label'] ?? 'Accès non déterminé'));
$primaryCode = trim((string)($request['primary_permission'] ?? ''));
$alternativePermissions = (array)($request['alternative_permissions'] ?? []);
$status = trim((string)($request['status'] ?? 'pending'));
$appBasePath = \AccessManager::getApplicationBasePath();
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revue demande d'accès</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Manrope', sans-serif;
            background: linear-gradient(135deg, #eff6ff 0%, #eef2ff 48%, #f8fafc 100%);
            color: #0f172a;
            padding: 24px;
        }

        .shell {
            width: min(980px, 100%);
            margin: 0 auto;
        }

        .card {
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 28px;
            box-shadow: 0 26px 60px rgba(15, 23, 42, 0.12);
            overflow: hidden;
        }

        .hero {
            padding: 30px 32px 22px;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 100%);
            color: #fff;
        }

        .body {
            padding: 28px 32px 32px;
        }

        .kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
        }

        h1 {
            margin: 18px 0 10px;
            font-size: 2.3rem;
            line-height: 1.05;
            letter-spacing: -0.04em;
        }

        p {
            margin: 0;
            color: #475569;
            line-height: 1.7;
        }

        .hero p {
            color: rgba(255, 255, 255, 0.82);
        }

        .toolbar {
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
            border: none;
        }

        .btn-dark {
            background: #fff;
            color: #0f172a;
        }

        .btn-outline {
            background: transparent;
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.18);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-top: 20px;
        }

        .meta {
            border-radius: 18px;
            padding: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .label {
            display: block;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: #64748b;
        }

        .value {
            margin-top: 6px;
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
            padding: 10px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
        }

        .status.pending {
            background: rgba(37, 99, 235, 0.08);
            color: #1d4ed8;
        }

        .status.granted {
            background: rgba(15, 118, 110, 0.1);
            color: #0f766e;
        }

        .status.refused {
            background: rgba(220, 38, 38, 0.1);
            color: #dc2626;
        }

        .message {
            margin-top: 16px;
            border-radius: 16px;
            padding: 14px 16px;
            font-weight: 700;
        }

        .message.success {
            background: rgba(15, 118, 110, 0.1);
            color: #0f766e;
        }

        .message.error {
            background: rgba(220, 38, 38, 0.1);
            color: #dc2626;
        }

        .actions {
            margin-top: 24px;
            display: grid;
            gap: 16px;
        }

        .panel {
            border-radius: 22px;
            padding: 20px;
            border: 1px solid #e2e8f0;
            background: #fff;
        }

        .panel h2 {
            margin: 0 0 10px;
            font-size: 1.1rem;
        }

        textarea {
            width: 100%;
            min-height: 110px;
            resize: vertical;
            border-radius: 16px;
            border: 1px solid #cbd5e1;
            padding: 14px;
            font: inherit;
            margin-top: 12px;
        }

        .panel-actions {
            margin-top: 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn-approve {
            background: linear-gradient(135deg, #0f766e, #2563eb);
            color: #fff;
        }

        .btn-refuse {
            background: linear-gradient(135deg, #dc2626, #f97316);
            color: #fff;
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 12px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
            margin-top: 12px;
            margin-right: 8px;
        }

        .finea-wrap {
            margin-top: 12px;
        }

        .review-finea-wrap {
            display: flex;
            justify-content: flex-start;
        }

        .review-finea-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(216, 180, 254, 0.42);
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
        }

        .review-finea-logo {
            display: block;
            height: 20px;
            width: auto;
            object-fit: contain;
        }

        .review-finea-icon {
            display: inline-flex;
            width: 34px;
            height: 34px;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #f8fafc;
            padding: 6px;
        }

        .review-finea-label {
            display: block;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.18em;
            text-transform: uppercase;
            color: #c026d3;
        }

        .review-finea-subtitle {
            display: block;
            font-size: 11px;
            color: #64748b;
            line-height: 1.35;
        }

        @media (max-width: 720px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .body,
            .hero {
                padding: 24px;
            }
        }
    </style>
</head>

<body>
    <main class="shell">
        <article class="card">
            <header class="hero">
                <span class="kicker"><i class="bi bi-shield-lock-fill"></i> Demande d'accès utilisateur</span>
                <h1>Revoir la demande et prendre une décision.</h1>
                <p>Depuis cet écran, l'informatique peut accorder ou refuser l'accès demandé. La page d'attente de l'utilisateur sera mise à jour automatiquement.</p>
                <div class="finea-wrap">
                    <?php renderAuthFineaMark([
                        'subtitle' => 'Validation sécurisée d\'une demande d\'accès Finéa',
                        'container_class' => 'review-finea-wrap',
                        'badge_class' => 'review-finea-badge',
                        'logo_class' => 'review-finea-logo',
                        'icon_wrapper_class' => 'review-finea-icon',
                        'fallback_icon_wrapper_class' => 'review-finea-icon',
                        'label_class' => 'review-finea-label',
                        'subtitle_class' => 'review-finea-subtitle'
                    ]); ?>
                </div>
                <div class="toolbar">
                    <a href="<?= htmlspecialchars($appBasePath . '/admin/dashboard_admin.php', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-dark"><i class="bi bi-speedometer2"></i> Dashboard admin</a>
                    <a href="<?= htmlspecialchars($appBasePath . '/admin/gestion_acces.php', ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline"><i class="bi bi-person-lock"></i> Gestion des accès</a>
                </div>
            </header>
            <section class="body">
                <?php if ($message !== ''): ?>
                    <div class="message <?= htmlspecialchars($messageTone) ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <div class="status <?= htmlspecialchars($status) ?>">
                    <i class="bi <?= $status === 'granted' ? 'bi-check-circle-fill' : ($status === 'refused' ? 'bi-x-circle-fill' : 'bi-hourglass-split') ?>"></i>
                    <?= htmlspecialchars($status === 'granted' ? 'Demande déjà accordée' : ($status === 'refused' ? 'Demande déjà refusée' : 'Demande en attente')) ?>
                </div>

                <div class="grid">
                    <article class="meta"><span class="label">Utilisateur</span>
                        <div class="value"><?= htmlspecialchars($requesterName) ?></div>
                    </article>
                    <article class="meta"><span class="label">Page demandée</span>
                        <div class="value"><?= htmlspecialchars($requestedPage) ?></div>
                    </article>
                    <article class="meta"><span class="label">Accès recommandé</span>
                        <div class="value"><?= htmlspecialchars($primaryLabel) ?><?= $primaryCode !== '' ? ' (' . htmlspecialchars($primaryCode) . ')' : '' ?></div>
                    </article>
                    <article class="meta"><span class="label">Date de demande</span>
                        <div class="value"><?= htmlspecialchars((string)($request['created_at'] ?? '')) ?></div>
                    </article>
                </div>

                <?php if ($alternativePermissions): ?>
                    <div>
                        <?php foreach ($alternativePermissions as $permission): ?>
                            <span class="chip"><i class="bi bi-diagram-3-fill"></i> <?= htmlspecialchars($permission) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($request && $status === 'pending'): ?>
                    <div class="actions">
                        <form method="post" class="panel">
                            <h2>Accorder l'accès</h2>
                            <p>Cette action active le droit recommandé pour l'utilisateur puis met à jour sa page d'attente en temps réel.</p>
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            <input type="hidden" name="decision" value="granted">
                            <textarea name="decision_message" placeholder="Message optionnel à destination de l'utilisateur (ex: accès activé, reconnectez-vous si besoin)."><?= htmlspecialchars($intent === 'approve' ? 'Accès activé par le support informatique.' : '') ?></textarea>
                            <div class="panel-actions">
                                <button type="submit" class="btn btn-approve"><i class="bi bi-check-circle-fill"></i> Accorder maintenant</button>
                            </div>
                        </form>

                        <form method="post" class="panel">
                            <h2>Refuser la demande</h2>
                            <p>Cette action informe l'utilisateur que l'accès n'a pas été accordé et le renvoie vers son point de départ.</p>
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            <input type="hidden" name="decision" value="refused">
                            <textarea name="decision_message" placeholder="Motif du refus ou consigne à transmettre à l'utilisateur."><?= htmlspecialchars($intent === 'refuse' ? 'Demande refusée par le support informatique.' : '') ?></textarea>
                            <div class="panel-actions">
                                <button type="submit" class="btn btn-refuse"><i class="bi bi-x-circle-fill"></i> Refuser la demande</button>
                            </div>
                        </form>
                    </div>
                <?php elseif ($request): ?>
                    <div class="panel" style="margin-top:24px;">
                        <h2>Décision enregistrée</h2>
                        <p>La demande a déjà été traitée. Vous pouvez revenir au tableau de bord admin ou consulter la gestion des accès si un ajustement supplémentaire est nécessaire.</p>
                        <?php if (!empty($request['decision_message'])): ?>
                            <textarea readonly><?= htmlspecialchars((string)$request['decision_message']) ?></textarea>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>
        </article>
    </main>
</body>

</html>