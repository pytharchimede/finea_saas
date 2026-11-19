<?php
// Page de succès après création de fiche
// Paramètres attendus: num_fiche (obligatoire), id (optionnel)
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';

function loadSuccessActionUsageStats(PDO $pdo, ?int $userId = null): array
{
    \AuditTrail::ensureSchema($pdo);

    $filterSql = '';
    $params = [];
    if ($userId !== null && $userId > 0) {
        $filterSql = ' AND user_id = :user_id';
        $params['user_id'] = $userId;
    }

    $sql = "
        SELECT action_name, SUM(click_count) AS total_clicks
        FROM (
            SELECT action AS action_name, COUNT(*) AS click_count
            FROM audit_trail
            WHERE event_type = 'success_fiche_action_click'
              AND action LIKE 'success_fiche_click_%'
              {$filterSql}
            GROUP BY action

            UNION ALL

            SELECT action AS action_name, COUNT(*) AS click_count
            FROM audit_trail_archive
            WHERE event_type = 'success_fiche_action_click'
              AND action LIKE 'success_fiche_click_%'
              {$filterSql}
            GROUP BY action
        ) usage_rows
        GROUP BY action_name
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $stats = [];
    foreach ($rows as $row) {
        $actionName = strtolower(trim((string) ($row['action_name'] ?? '')));
        if ($actionName === '' || strpos($actionName, 'success_fiche_click_') !== 0) {
            continue;
        }

        $actionKey = substr($actionName, strlen('success_fiche_click_'));
        $stats[$actionKey] = (int) ($row['total_clicks'] ?? 0);
    }

    return $stats;
}

$pdo = \Database::getConnection();
$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$currentUserName = trim((string) ($_SESSION['user_name'] ?? ''));

$num_fiche = isset($_GET['num_fiche']) ? htmlspecialchars($_GET['num_fiche']) : null;
$id_fiche = isset($_GET['id']) ? intval($_GET['id']) : null;

// Mode multi: nums=FD-....,FD-....
$num_fiches = [];
$count = isset($_GET['count']) ? (int) $_GET['count'] : null;
if (!empty($_GET['nums'])) {
    $raw = (string) $_GET['nums'];
    $parts = array_filter(array_map('trim', explode(',', $raw)));
    foreach ($parts as $p) {
        // Valider format attendu: FD-yymm-xxxxx
        if (preg_match('/^FD-\d{4}-\d{5}$/', $p)) {
            $num_fiches[] = $p;
        }
    }
    $num_fiches = array_values(array_unique($num_fiches));
    if ($num_fiches && !$num_fiche) {
        $num_fiche = htmlspecialchars($num_fiches[0]);
    }
}

if (!$num_fiche) {
    header('Location: formulaire_demande_decaissement.php');
    exit;
}

$actionDefinitions = [
    'dashboard_employee' => [
        'title' => 'Retour au tableau de bord employé',
        'description' => 'Revenir à l\'espace employé pour suivre vos demandes et continuer votre navigation.',
        'href' => '../employe/dashboard_employe.php',
        'target' => 'self',
        'icon' => 'fas fa-th-large',
        'primary_class' => 'dashboard',
        'eyebrow' => 'Navigation rapide',
        'cta' => 'Tableau de bord',
        'secondary_class' => 'green',
        'secondary_button_class' => 'btn-outline-success',
        'secondary_button_icon' => 'fas fa-home',
        'secondary_button_label' => 'Dashboard',
    ],
    'new_request' => [
        'title' => 'Faire une autre fiche',
        'description' => 'Relancer immédiatement une nouvelle demande sans repasser par d\'autres écrans.',
        'href' => 'formulaire_demande_decaissement.php',
        'target' => 'self',
        'icon' => 'fas fa-file-signature',
        'primary_class' => 'new-request',
        'eyebrow' => 'Action recommandée',
        'cta' => 'Nouvelle fiche',
        'secondary_class' => '',
        'secondary_button_class' => 'btn-outline-primary',
        'secondary_button_icon' => 'fas fa-plus-circle',
        'secondary_button_label' => 'Nouvelle fiche',
    ],
    'recap_employee' => [
        'title' => 'Voir mon récapitulatif',
        'description' => 'Afficher la liste de vos décaissements et leur état de traitement.',
        'href' => '../employe/mes_decaissements_employe.php',
        'target' => 'self',
        'icon' => 'fas fa-list-check',
        'primary_class' => 'dashboard',
        'eyebrow' => 'Suivi personnel',
        'cta' => 'Voir le récap',
        'secondary_class' => '',
        'secondary_card_style' => 'border:1px solid #dbeafe;',
        'secondary_icon_style' => 'background:linear-gradient(135deg,#1d4ed8,#60a5fa);',
        'secondary_button_class' => 'btn-outline-primary',
        'secondary_button_icon' => 'fas fa-rectangle-list',
        'secondary_button_label' => 'Voir le récap',
    ],
    'view_fiche' => [
        'title' => 'Voir la fiche',
        'description' => 'Consulter les détails complets de cette fiche.',
        'href' => 'voir_fiche_details.php?num_fiche=' . urlencode($num_fiche),
        'target' => 'blank',
        'icon' => 'fas fa-eye',
        'primary_class' => 'dashboard',
        'eyebrow' => 'Consultation',
        'cta' => 'Ouvrir la fiche',
        'secondary_class' => 'alt',
        'secondary_button_class' => 'btn-outline-secondary',
        'secondary_button_icon' => 'fas fa-external-link-alt',
        'secondary_button_label' => 'Ouvrir',
    ],
    'portal' => [
        'title' => 'Retour au portail',
        'description' => 'Revenir au portail pour choisir un autre espace de travail.',
        'href' => '../selection_portail.php',
        'target' => 'self',
        'icon' => 'fas fa-compass',
        'primary_class' => 'new-request',
        'eyebrow' => 'Navigation globale',
        'cta' => 'Portail',
        'secondary_class' => 'green',
        'secondary_button_class' => 'btn-outline-success',
        'secondary_button_icon' => 'fas fa-arrow-left',
        'secondary_button_label' => 'Portail',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['track_action'])) {
    header('Content-Type: application/json; charset=UTF-8');

    try {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

        $actionKey = strtolower(trim((string) ($payload['action_key'] ?? '')));
        if ($actionKey === '' || !isset($actionDefinitions[$actionKey])) {
            throw new RuntimeException('Action inconnue.');
        }

        \AuditTrail::log('success_fiche_action_click', [
            'module' => 'decaissement',
            'action' => 'success_fiche_click_' . $actionKey,
            'entity_type' => 'fiche_decaissement_success_action',
            'entity_id' => $id_fiche ? (string) $id_fiche : null,
            'entity_label' => (string) ($actionDefinitions[$actionKey]['title'] ?? $actionKey),
            'page_name' => 'success_fiche.php',
            'details' => [
                'num_fiche' => $num_fiche,
                'action_key' => $actionKey,
                'target' => (string) ($actionDefinitions[$actionKey]['target'] ?? 'self'),
                'view_mode' => 'success_action_click',
            ],
        ], $pdo);

        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    exit;
}

$globalActionUsageStats = loadSuccessActionUsageStats($pdo);
$personalActionUsageStats = loadSuccessActionUsageStats($pdo, $currentUserId);
$defaultOrderLookup = array_flip(array_keys($actionDefinitions));
$orderedActions = [];
foreach ($actionDefinitions as $actionKey => $definition) {
    $orderedActions[] = $definition + [
        'key' => $actionKey,
        'global_click_count' => (int) ($globalActionUsageStats[$actionKey] ?? 0),
        'personal_click_count' => (int) ($personalActionUsageStats[$actionKey] ?? 0),
    ];
}

usort($orderedActions, static function (array $left, array $right) use ($defaultOrderLookup): int {
    $personalComparison = ($right['personal_click_count'] ?? 0) <=> ($left['personal_click_count'] ?? 0);
    if ($personalComparison !== 0) {
        return $personalComparison;
    }

    $globalComparison = ($right['global_click_count'] ?? 0) <=> ($left['global_click_count'] ?? 0);
    if ($globalComparison !== 0) {
        return $globalComparison;
    }

    return ($defaultOrderLookup[$left['key']] ?? PHP_INT_MAX) <=> ($defaultOrderLookup[$right['key']] ?? PHP_INT_MAX);
});

$totalGlobalActionClicks = array_sum(array_column($orderedActions, 'global_click_count'));
$totalPersonalActionClicks = array_sum(array_column($orderedActions, 'personal_click_count'));
foreach ($orderedActions as &$orderedAction) {
    $orderedAction['global_click_rate'] = $totalGlobalActionClicks > 0
        ? round(((int) $orderedAction['global_click_count'] * 100) / $totalGlobalActionClicks, 1)
        : 0.0;
    $orderedAction['personal_click_rate'] = $totalPersonalActionClicks > 0
        ? round(((int) $orderedAction['personal_click_count'] * 100) / $totalPersonalActionClicks, 1)
        : 0.0;
}
unset($orderedAction);

$primaryActions = array_slice($orderedActions, 0, 2);
$secondaryActions = array_slice($orderedActions, 2);
$trackingUrl = 'success_fiche.php?track_action=1&num_fiche=' . rawurlencode($num_fiche) . ($id_fiche ? '&id=' . rawurlencode((string) $id_fiche) : '');

\AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'success_fiche_view',
    'entity_type' => 'fiche_decaissement_success',
    'entity_id' => $id_fiche ? (string)$id_fiche : null,
    'entity_label' => (string)$num_fiche,
    'page_name' => 'success_fiche.php',
    'details' => [
        'count' => $count,
        'multi_count' => count($num_fiches),
        'view_mode' => 'success_screen',
    ],
], $pdo);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Fiche créée avec succès</title>
    <link rel="stylesheet" href="plugins/css/bootstrap/bootstrap.min.css" />
    <link rel="stylesheet" href="plugins/css/fontawesome/all.min.css" />
    <style>
        body {
            background: radial-gradient(circle at 20% 20%, #f3f8ff 0%, #eef2f7 35%, #e4ecf5 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .success-wrapper {
            max-width: 1080px;
            margin: 40px auto;
            padding: 40px;
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(6px);
            border-radius: 28px;
            box-shadow: 0 10px 35px -10px rgba(33, 150, 243, 0.25);
            position: relative;
            overflow: hidden;
        }

        .success-wrapper:before,
        .success-wrapper:after {
            content: '';
            position: absolute;
            border-radius: 50%;
            filter: blur(35px);
            opacity: .25;
            animation: float 12s linear infinite;
        }

        .success-wrapper:before {
            width: 420px;
            height: 420px;
            background: linear-gradient(135deg, #42a5f5, #7e57c2);
            top: -140px;
            right: -120px;
            animation-direction: reverse;
        }

        .success-wrapper:after {
            width: 360px;
            height: 360px;
            background: linear-gradient(135deg, #26c6da, #66bb6a);
            bottom: -120px;
            left: -100px;
        }

        @keyframes float {
            0% {
                transform: translateY(0) rotate(0deg);
            }

            50% {
                transform: translateY(30px) rotate(90deg);
            }

            100% {
                transform: translateY(0) rotate(180deg);
            }
        }

        .icon-circle {
            width: 140px;
            height: 140px;
            background: linear-gradient(135deg, #43a047, #66bb6a);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            box-shadow: 0 10px 25px -8px rgba(76, 175, 80, .55);
            position: relative;
        }

        .icon-circle i {
            font-size: 64px;
            color: #fff;
        }

        h1 {
            font-weight: 700;
            letter-spacing: .5px;
            background: linear-gradient(90deg, #1e88e5, #673ab7);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .fiche-badge {
            display: inline-block;
            padding: 10px 22px;
            background: linear-gradient(90deg, #1976d2, #512da8);
            color: #fff;
            font-weight: 600;
            border-radius: 40px;
            font-size: 1.05rem;
            box-shadow: 0 4px 14px -4px rgba(63, 81, 181, .55);
        }

        .success-summary {
            max-width: 760px;
            margin: 24px auto 0;
            padding: 18px 20px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(13, 71, 161, .06), rgba(76, 175, 80, .08));
            border: 1px solid rgba(25, 118, 210, .12);
            color: #425466;
            font-size: .95rem;
            line-height: 1.5;
        }

        .section-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 10px 0 18px;
            color: #4b5563;
            font-size: .9rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .12em;
        }

        .primary-actions {
            display: grid;
            gap: 18px;
            margin-top: 12px;
        }

        @media(min-width:768px) {
            .primary-actions {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .primary-action {
            position: relative;
            overflow: hidden;
            border-radius: 24px;
            padding: 28px 24px;
            min-height: 220px;
            color: #fff;
            box-shadow: 0 20px 45px -24px rgba(15, 23, 42, .45);
            cursor: pointer;
            transition: transform .28s ease, box-shadow .28s ease;
        }

        .primary-action:hover {
            transform: translateY(-5px);
            box-shadow: 0 28px 50px -24px rgba(15, 23, 42, .55);
        }

        .primary-action:focus-visible {
            outline: 3px solid rgba(255, 255, 255, .7);
            outline-offset: 3px;
        }

        .primary-action.new-request {
            background: linear-gradient(135deg, #0f766e 0%, #14b8a6 50%, #67e8f9 100%);
        }

        .primary-action.dashboard {
            background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 55%, #60a5fa 100%);
        }

        .primary-action:before {
            content: '';
            position: absolute;
            inset: auto -40px -50px auto;
            width: 180px;
            height: 180px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .12);
            filter: blur(2px);
        }

        .primary-action .eyebrow {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .16em;
            opacity: .82;
        }

        .primary-action .primary-icon {
            width: 70px;
            height: 70px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            margin: 18px 0 16px;
            background: rgba(255, 255, 255, .14);
            font-size: 30px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .12);
        }

        .primary-action h2 {
            margin: 0 0 10px;
            color: #fff;
            font-size: 1.55rem;
            font-weight: 700;
            letter-spacing: .02em;
        }

        .primary-action p {
            margin: 0;
            color: rgba(255, 255, 255, .9);
            font-size: .96rem;
            line-height: 1.45;
            max-width: 320px;
        }

        .primary-action .cta-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            padding: 11px 16px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .16);
            color: #fff;
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
        }

        .action-stat {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .16);
            color: #fff;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .04em;
        }

        .action-stat.is-global {
            background: rgba(255, 255, 255, .08);
            color: rgba(255, 255, 255, .62);
            font-size: .7rem;
            font-weight: 600;
            letter-spacing: .02em;
            padding: 6px 10px;
        }

        .action-stats-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
        }

        .secondary-stat {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: .74rem;
            font-weight: 700;
        }

        .secondary-stat.is-global {
            background: rgba(148, 163, 184, .12);
            color: rgba(71, 85, 105, .55);
            font-size: .68rem;
            font-weight: 600;
            border: 1px solid rgba(148, 163, 184, .12);
        }

        .secondary-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 12px;
        }

        .usage-summary {
            margin-top: 8px;
            text-align: center;
            color: #64748b;
            font-size: .84rem;
        }

        .grid-actions {
            display: grid;
            gap: 22px;
            margin-top: 40px;
        }

        @media(min-width:640px) {
            .grid-actions {
                grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            }
        }

        .action-card {
            position: relative;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 24px 20px 22px;
            box-shadow: 0 4px 22px -6px rgba(0, 0, 0, 0.08);
            transition: .35s cubic-bezier(.4, .2, .2, 1);
            overflow: hidden;
        }

        .action-card.secondary {
            padding: 22px 18px 20px;
        }

        .action-card.clickable {
            cursor: pointer;
        }

        .action-card.clickable:active {
            transform: translateY(-2px) scale(.99);
        }

        .action-card:before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(30, 136, 229, .08), rgba(123, 31, 162, .08));
            opacity: 0;
            transition: .35s;
        }

        .action-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 14px 34px -10px rgba(30, 136, 229, .35);
        }

        .action-card:hover:before {
            opacity: 1;
        }

        .action-icon {
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            margin-bottom: 14px;
            font-size: 26px;
            background: linear-gradient(135deg, #1e88e5, #42a5f5);
            color: #fff;
            box-shadow: 0 6px 14px -6px rgba(33, 150, 243, .55);
        }

        .action-card.alt .action-icon {
            background: linear-gradient(135deg, #7b1fa2, #ab47bc);
            box-shadow: 0 6px 14px -6px rgba(156, 39, 176, .55);
        }

        .action-card.green .action-icon {
            background: linear-gradient(135deg, #2e7d32, #43a047);
            box-shadow: 0 6px 14px -6px rgba(76, 175, 80, .55);
        }

        .action-title {
            font-size: 1.05rem;
            font-weight: 600;
            margin: 4px 0 4px;
            color: #374151;
        }

        .action-desc {
            font-size: .82rem;
            color: #607d8b;
            line-height: 1.3;
            min-height: 32px;
        }

        .action-card a.btn {
            margin-top: 14px;
            font-weight: 600;
            border-radius: 30px;
            font-size: .78rem;
            letter-spacing: .5px;
            text-transform: uppercase;
        }

        .divider {
            height: 1px;
            width: 100%;
            background: linear-gradient(90deg, transparent, #c5cae9, transparent);
            margin: 32px 0 16px;
        }

        .next-hint {
            font-size: .85rem;
            color: #546e7a;
        }

        .secondary-note {
            max-width: 760px;
            margin: 0 auto;
            color: #607d8b;
            font-size: .88rem;
        }

        .confetti {
            position: absolute;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .confetti span {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #42a5f5;
            top: -10px;
            animation: fall 6s linear infinite;
            opacity: .85;
        }

        .confetti span:nth-child(3n) {
            background: #66bb6a;
            width: 12px;
            height: 12px;
        }

        .confetti span:nth-child(4n) {
            background: #ab47bc;
        }

        .confetti span:nth-child(5n) {
            background: #ffa000;
            width: 8px;
            height: 8px;
        }

        @keyframes fall {
            0% {
                transform: translateY(-10px) rotate(0deg);
            }

            70% {
                opacity: 1;
            }

            100% {
                transform: translateY(110vh) rotate(720deg);
                opacity: 0;
            }
        }

        @media(max-width:767px) {
            body {
                align-items: flex-start;
            }

            .success-wrapper {
                margin: 20px auto;
                padding: 26px 18px 24px;
                border-radius: 24px;
            }

            .icon-circle {
                width: 110px;
                height: 110px;
                margin-bottom: 18px;
            }

            .icon-circle i {
                font-size: 50px;
            }

            h1 {
                font-size: 1.9rem;
            }

            .primary-action {
                min-height: 0;
                padding: 22px 18px;
                border-radius: 20px;
            }

            .primary-action h2 {
                font-size: 1.3rem;
            }

            .primary-action .primary-icon {
                width: 60px;
                height: 60px;
                margin: 14px 0 14px;
                font-size: 26px;
            }

            .action-stat {
                font-size: .72rem;
            }

            .action-card.secondary {
                padding: 18px 16px 18px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="success-wrapper position-relative">
            <div class="confetti">
                <?php for ($i = 0; $i < 45; $i++): $l = rand(2, 95);
                    $d = rand(0, 2000);
                    $s = rand(4, 9); ?>
                    <span style="left:<?= $l ?>%; animation-delay: <?= $d ?>ms; animation-duration: <?= $s ?>s"></span>
                <?php endfor; ?>
            </div>
            <div class="text-center position-relative">
                <div class="icon-circle"><i class="fas fa-check-circle"></i></div>
                <h1>Demande enregistrée</h1>
                <p class="mt-3 mb-2 text-muted" style="font-size:0.95rem;">Votre fiche a été créée avec succès dans le système.</p>
                <div class="mt-2">
                    <span class="fiche-badge"><i class="fas fa-hashtag"></i> FICHE&nbsp;: <?= $num_fiche ?></span>
                </div>

                <?php if (!empty($num_fiches) && count($num_fiches) > 1): ?>
                    <div class="mt-3">
                        <div class="text-muted" style="font-size:0.9rem;"><?= (int)($count ?: count($num_fiches)) ?> fiches créées</div>
                        <div class="d-flex flex-wrap justify-content-center gap-2 mt-2">
                            <?php foreach ($num_fiches as $nf): ?>
                                <a href="voir_fiche_details.php?num_fiche=<?= htmlspecialchars($nf) ?>" target="_blank" class="badge bg-light text-dark" style="text-decoration:none; border:1px solid rgba(0,0,0,0.12); padding:10px 14px; border-radius:999px;">
                                    <i class="fas fa-file-invoice"></i> <?= htmlspecialchars($nf) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="divider"></div>
            <div class="success-summary text-center">
                Les actions sont classées selon les habitudes de l'utilisateur connecté. Les statistiques globales restent affichées en parallèle pour comparaison.
            </div>
            <div class="usage-summary">
                <?php if ($totalPersonalActionClicks > 0): ?>
                    Classement personnalisé pour <?= htmlspecialchars($currentUserName !== '' ? $currentUserName : 'l’utilisateur connecté') ?> à partir de <?= number_format($totalPersonalActionClicks, 0, ',', ' ') ?> clic<?= $totalPersonalActionClicks > 1 ? 's' : '' ?> personnels, avec comparaison sur <?= number_format($totalGlobalActionClicks, 0, ',', ' ') ?> clic<?= $totalGlobalActionClicks > 1 ? 's' : '' ?> globaux.
                <?php elseif ($totalGlobalActionClicks > 0): ?>
                    Aucun historique personnel disponible. Le classement utilise les <?= number_format($totalGlobalActionClicks, 0, ',', ' ') ?> clic<?= $totalGlobalActionClicks > 1 ? 's' : '' ?> globaux en attendant vos propres habitudes.
                <?php else: ?>
                    Aucune donnée de clic disponible pour le moment. L'ordre affiché utilise le parcours recommandé par défaut.
                <?php endif; ?>
            </div>

            <div class="section-title"><i class="fas fa-star text-warning"></i> Actions principales</div>

            <div class="primary-actions">
                <?php foreach ($primaryActions as $index => $action): ?>
                    <div class="primary-action <?= htmlspecialchars((string) ($action['primary_class'] ?? 'dashboard')) ?>" data-link="<?= htmlspecialchars((string) ($action['href'] ?? '#')) ?>" data-target="<?= htmlspecialchars((string) ($action['target'] ?? 'self')) ?>" data-action-key="<?= htmlspecialchars((string) ($action['key'] ?? '')) ?>" tabindex="0" role="link" aria-label="<?= htmlspecialchars((string) ($action['title'] ?? 'Action')) ?>">
                        <div class="eyebrow"><?= $index === 0 ? 'Votre action la plus fréquente' : 'Votre deuxième action la plus fréquente' ?></div>
                        <div class="primary-icon"><i class="<?= htmlspecialchars((string) ($action['icon'] ?? 'fas fa-bolt')) ?>"></i></div>
                        <h2><?= htmlspecialchars((string) ($action['title'] ?? '')) ?></h2>
                        <p><?= htmlspecialchars((string) ($action['description'] ?? '')) ?></p>
                        <div class="action-stats-stack">
                            <div class="action-stat"><i class="fas fa-user"></i> Vous : <?= number_format((float) ($action['personal_click_rate'] ?? 0), 1, ',', ' ') ?>%</div>
                            <div class="action-stat is-global"><i class="fas fa-users"></i> Global : <?= number_format((float) ($action['global_click_rate'] ?? 0), 1, ',', ' ') ?>%</div>
                        </div>
                        <div class="cta-pill"><i class="<?= htmlspecialchars((string) ($action['icon'] ?? 'fas fa-arrow-right')) ?>"></i> <?= htmlspecialchars((string) ($action['cta'] ?? 'Ouvrir')) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="divider"></div>
            <p class="text-center next-hint mb-2"><i class="fas fa-lightbulb text-warning"></i> Autres actions disponibles</p>
            <p class="text-center secondary-note mb-4">Vous pouvez aussi ouvrir le récapitulatif de vos décaissements, voir la fiche créée ou revenir au portail.</p>

            <div class="grid-actions mt-0">
                <?php foreach ($secondaryActions as $action): ?>
                    <div class="action-card secondary <?= htmlspecialchars((string) ($action['secondary_class'] ?? '')) ?> clickable" <?= !empty($action['secondary_card_style']) ? ' style="' . htmlspecialchars((string) $action['secondary_card_style']) . '"' : '' ?> data-link="<?= htmlspecialchars((string) ($action['href'] ?? '#')) ?>" data-target="<?= htmlspecialchars((string) ($action['target'] ?? 'self')) ?>" data-action-key="<?= htmlspecialchars((string) ($action['key'] ?? '')) ?>">
                        <div class="secondary-stats">
                            <div class="secondary-stat"><i class="fas fa-user"></i> Vous : <?= number_format((float) ($action['personal_click_rate'] ?? 0), 1, ',', ' ') ?>%</div>
                            <div class="secondary-stat is-global"><i class="fas fa-users"></i> Global : <?= number_format((float) ($action['global_click_rate'] ?? 0), 1, ',', ' ') ?>%</div>
                        </div>
                        <div class="action-icon" <?= !empty($action['secondary_icon_style']) ? ' style="' . htmlspecialchars((string) $action['secondary_icon_style']) . '"' : '' ?>><i class="<?= htmlspecialchars((string) ($action['icon'] ?? 'fas fa-bolt')) ?>"></i></div>
                        <div class="action-title"><?= htmlspecialchars((string) ($action['title'] ?? '')) ?></div>
                        <div class="action-desc"><?= htmlspecialchars((string) ($action['description'] ?? '')) ?></div>
                        <a href="<?= htmlspecialchars((string) ($action['href'] ?? '#')) ?>" class="btn <?= htmlspecialchars((string) ($action['secondary_button_class'] ?? 'btn-outline-primary')) ?> btn-sm" <?= ($action['target'] ?? 'self') === 'blank' ? ' target="_blank"' : '' ?> data-action-key="<?= htmlspecialchars((string) ($action['key'] ?? '')) ?>"><i class="<?= htmlspecialchars((string) ($action['secondary_button_icon'] ?? 'fas fa-arrow-right')) ?>"></i> <?= htmlspecialchars((string) ($action['secondary_button_label'] ?? 'Ouvrir')) ?></a>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="text-center mt-5" style="font-size:0.75rem; color:#90a4ae;">&copy; <?= date('Y') ?> Fiche décaissement • Généré automatiquement</div>
        </div>
    </div>
    <script src="plugins/js/bootstrap/bootstrap.min.js"></script>
    <script src="plugins/js/fontawesome/all.min.js"></script>
    <script>
        (function() {
            const trackingUrl = <?= json_encode($trackingUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

            function trackActionUsage(actionKey) {
                if (!actionKey || !trackingUrl) {
                    return;
                }

                const payload = JSON.stringify({
                    action_key: actionKey
                });
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(trackingUrl, payload);
                    return;
                }

                fetch(trackingUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: payload,
                    keepalive: true
                }).catch(() => {});
            }

            const cards = document.querySelectorAll('.action-card.clickable, .primary-action');
            cards.forEach(c => {
                c.setAttribute('tabindex', '0');
                c.addEventListener('click', e => {
                    // Éviter double clic si bouton interne
                    if (e.target.closest('a.btn')) return;
                    const url = c.dataset.link;
                    const tgt = c.dataset.target || 'self';
                    const actionKey = c.dataset.actionKey || '';
                    if (!url) return;
                    trackActionUsage(actionKey);
                    if (tgt === 'blank') window.open(url, '_blank');
                    else window.location.href = url;
                });
                c.addEventListener('keydown', e => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        c.click();
                    }
                });
            });

            document.querySelectorAll('a.btn[data-action-key]').forEach(link => {
                link.addEventListener('click', () => {
                    trackActionUsage(link.dataset.actionKey || '');
                });
            });
        })();
    </script>
</body>

</html>