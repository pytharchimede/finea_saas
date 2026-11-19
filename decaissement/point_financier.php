<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
$pageTitle = 'Point financier - DécaissApp';
$activeMenu = 'pointfinancier';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$auditPdo = Database::getConnection();
AuditTrail::log('page_view', [
    'module' => 'decaissement',
    'action' => 'point_financier_view',
    'entity_type' => 'point_financier',
    'entity_label' => 'Point financier',
    'page_name' => 'point_financier.php',
    'result' => 'success',
    'details' => [
        'etat_demande' => $_GET['etat_demande'] ?? 'toutes',
        'beneficiaire' => $_GET['beneficiaire'] ?? null,
        'num_fiche' => $_GET['num_fiche'] ?? null,
        'detail_fiche' => $_GET['detail_fiche'] ?? null,
        'chantier' => $_GET['chantier'] ?? null,
        'debourse' => $_GET['debourse'] ?? null,
        'view_mode' => 'browser_dashboard',
    ],
], $auditPdo);

$pfIndexMissingCount = 0;
$pfIndexMaintenanceUrl = ($layoutRootBase ?? '../') . 'admin/maintenance_point_financier_indexes.php';

// Important: les index améliorent la performance/stabilité mais ne sont pas requis pour “fonctionner”.
// On se contente d'alerter les admins (pas d'ALTER TABLE automatique à chaque chargement).
if (class_exists('AccessManager') && AccessManager::hasPermission('acces_admin')) {
    $cacheTsKey = 'pf_idx_audit_ts';
    $cacheAuditKey = 'pf_idx_audit';
    $forceRecheck = !empty($_GET['recheck_indexes']);
    $ttlSeconds = 15 * 60;

    if ($forceRecheck || empty($_SESSION[$cacheTsKey]) || (time() - (int)$_SESSION[$cacheTsKey]) > $ttlSeconds) {
        try {
            require_once __DIR__ . '/../model/Database.php';
            require_once __DIR__ . '/model/PointFinancierSchema.php';
            $pdo = Database::getConnection();
            $_SESSION[$cacheAuditKey] = PointFinancierSchema::checkIndexes($pdo);
        } catch (Throwable $e) {
            $_SESSION[$cacheAuditKey] = null;
        }
        $_SESSION[$cacheTsKey] = time();
    }

    $audit = $_SESSION[$cacheAuditKey] ?? null;
    if (is_array($audit)) {
        foreach ($audit as $row) {
            $st = (string)($row['status'] ?? '');
            if ($st === 'missing' || $st === 'failed') {
                $pfIndexMissingCount++;
            }
        }
    }
}
?>
<style>
    /* Styles pour les selects modernes */
    .select-modern {
        position: relative;
    }

    .select-trigger {
        width: 100%;
        padding: 12px 16px;
        background: rgba(255, 255, 255, 0.8);
        backdrop-filter: blur(4px);
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 14px;
        color: #374151;
    }

    .select-trigger:hover {
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .select-trigger:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .select-arrow {
        color: #9ca3af;
        transition: transform 0.2s ease;
    }

    .select-modern:not(.disabled) .select-trigger:hover .select-arrow {
        transform: rotate(180deg);
    }

    .select-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        z-index: 1000;
        max-height: 300px;
        overflow: hidden;
    }

    .select-search-wrap {
        padding: 12px;
        border-bottom: 1px solid #f3f4f6;
    }

    .select-search {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #d1d5db;
        border-radius: 8px;
        font-size: 14px;
    }

    .select-search:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .select-options {
        max-height: 200px;
        overflow-y: auto;
    }

    .select-option {
        width: 100%;
        padding: 10px 16px;
        text-align: left;
        border: none;
        background: none;
        cursor: pointer;
        font-size: 14px;
        color: #374151;
        transition: background-color 0.15s ease;
    }

    .select-option:hover {
        background-color: #f3f4f6;
    }

    .select-empty {
        padding: 16px;
        text-align: center;
        color: #9ca3af;
        font-size: 14px;
    }

    .select-modern.disabled .select-trigger {
        background: #f9fafb;
        color: #9ca3af;
        cursor: not-allowed;
    }

    /* Styles pour les cartes */
    .card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05), 0 10px 25px rgba(0, 0, 0, 0.1);
        transition: all 0.3s ease;
        border: 1px solid rgba(255, 255, 255, 0.8);
    }

    .card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
    }

    .card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #f3f4f6;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .pf-card {
        overflow: hidden;
        border: 1px solid rgba(99, 102, 241, 0.08);
        background:
            radial-gradient(circle at top right, rgba(99, 102, 241, 0.12), transparent 34%),
            linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.98));
    }

    .pf-card-topline {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding-bottom: 12px;
        margin-bottom: 14px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.9);
    }

    .pf-card-number {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-width: 0;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: #4338ca;
    }

    .pf-card-badges {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 8px;
        flex-wrap: wrap;
    }

    .pf-card-amountline {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 14px;
        padding: 10px 14px 12px;
        border-radius: 18px;
        border: 1px solid rgba(16, 185, 129, 0.12);
        background: linear-gradient(135deg, rgba(236, 253, 245, 0.95), rgba(255, 255, 255, 0.96));
    }

    .pf-card-amountline-label {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #047857;
    }

    .pf-card::before {
        content: '';
        position: absolute;
        inset: 0 0 auto 0;
        height: 4px;
        background: linear-gradient(90deg, #4f46e5 0%, #8b5cf6 50%, #06b6d4 100%);
    }

    .pf-card-header {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 12px;
        align-items: center;
    }

    .pf-avatar {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        overflow: hidden;
        border: 3px solid rgba(255, 255, 255, 0.85);
        box-shadow: 0 12px 28px rgba(79, 70, 229, 0.18);
        background: linear-gradient(135deg, #eef2ff 0%, #e0f2fe 100%);
        flex-shrink: 0;
    }

    .pf-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    .pf-avatar-fallback {
        display: flex;
        width: 100%;
        height: 100%;
        align-items: center;
        justify-content: center;
        color: #4f46e5;
        font-size: 1.15rem;
        font-weight: 700;
    }

    .pf-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        border-radius: 999px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .pf-card-meta {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .pf-card-meta-item {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.82);
        padding: 10px 12px;
        min-width: 0;
    }

    .pf-card-meta-item--wide {
        grid-column: 1 / -1;
    }

    .pf-card-meta-label {
        display: block;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 4px;
    }

    .pf-card-meta-value {
        display: block;
        font-size: 12px;
        line-height: 1.5;
        font-weight: 600;
        color: #0f172a;
        word-break: normal;
        overflow-wrap: break-word;
    }

    .pf-card-meta-value--single {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .pf-clamp-2 {
        display: -webkit-box;
        line-clamp: 2;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .pf-panel {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.78);
        padding: 14px;
        backdrop-filter: blur(8px);
    }

    .pf-panel-title {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 10px;
    }

    .pf-key-value {
        display: grid;
        grid-template-columns: 96px minmax(0, 1fr);
        gap: 10px;
        align-items: start;
        font-size: 13px;
        line-height: 1.55;
    }

    .pf-key-value+.pf-key-value {
        margin-top: 8px;
    }

    .pf-key {
        color: #64748b;
        font-weight: 600;
    }

    .pf-value {
        color: #0f172a;
        font-weight: 600;
        min-width: 0;
        word-break: break-word;
    }

    .pf-notes {
        color: #334155;
        font-size: 13px;
        line-height: 1.7;
        white-space: pre-line;
        max-height: 8.4rem;
        overflow: auto;
        padding-right: 4px;
    }

    .pf-timeline {
        display: grid;
        gap: 10px;
    }

    .pf-timeline-item {
        display: grid;
        grid-template-columns: 12px minmax(0, 1fr);
        gap: 12px;
        align-items: start;
    }

    .pf-timeline-dot {
        width: 12px;
        height: 12px;
        margin-top: 5px;
        border-radius: 999px;
        box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.95);
    }

    .pf-timeline-card {
        border-radius: 14px;
        padding: 10px 12px;
        border: 1px solid rgba(226, 232, 240, 0.85);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.96));
    }

    .pf-timeline-label {
        font-size: 12px;
        font-weight: 700;
        color: #0f172a;
    }

    .pf-timeline-date {
        margin-top: 2px;
        font-size: 11px;
        color: #64748b;
    }

    .pf-timeline-empty {
        font-size: 12px;
        color: #94a3b8;
        font-style: italic;
    }

    .pf-amount {
        font-size: 1.35rem;
        font-weight: 800;
        color: #047857;
        letter-spacing: -0.02em;
        line-height: 1;
    }

    .pf-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-wrap: wrap;
    }

    .pf-refusal-compact {
        margin-top: 10px;
        border: 1px solid rgba(254, 205, 211, 0.95);
        border-radius: 14px;
        background: linear-gradient(180deg, rgba(255, 241, 242, 0.96), rgba(255, 255, 255, 0.98));
        padding: 10px 12px;
    }

    .pf-refusal-compact-title {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #be123c;
        margin-bottom: 4px;
    }

    .pf-refusal-compact-text {
        font-size: 12px;
        line-height: 1.55;
        font-weight: 600;
        color: #881337;
        white-space: pre-line;
    }

    .pf-accordion {
        border: 1px solid rgba(226, 232, 240, 0.9);
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.78);
        overflow: hidden;
    }

    .pf-accordion summary {
        list-style: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 16px;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        color: #475569;
    }

    .pf-accordion summary::-webkit-details-marker {
        display: none;
    }

    .pf-accordion summary::after {
        content: '+';
        font-size: 18px;
        line-height: 1;
        color: #4338ca;
    }

    .pf-accordion[open] summary::after {
        content: '−';
    }

    .pf-accordion-body {
        padding: 0 16px 16px;
        border-top: 1px solid rgba(226, 232, 240, 0.9);
    }

    .pf-modal {
        position: fixed;
        inset: 0;
        z-index: 1200;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: rgba(15, 23, 42, 0.58);
        backdrop-filter: blur(6px);
    }

    .pf-modal.is-open {
        display: flex;
    }

    .pf-modal-dialog {
        width: min(1040px, 100%);
        max-height: calc(100vh - 48px);
        overflow: hidden;
        border-radius: 28px;
        border: 1px solid rgba(148, 163, 184, 0.25);
        background: radial-gradient(circle at top right, rgba(99, 102, 241, 0.14), transparent 30%), linear-gradient(180deg, rgba(255, 255, 255, 0.99), rgba(248, 250, 252, 0.98));
        box-shadow: 0 30px 80px rgba(15, 23, 42, 0.28);
    }

    .pf-modal-header {
        display: flex;
        align-items: start;
        justify-content: space-between;
        gap: 16px;
        padding: 24px 28px 18px;
        border-bottom: 1px solid rgba(226, 232, 240, 0.95);
    }

    .pf-modal-body {
        max-height: calc(100vh - 180px);
        overflow: auto;
        padding: 24px 28px 28px;
    }

    .pf-modal-grid {
        display: grid;
        gap: 18px;
        grid-template-columns: minmax(0, 1.15fr) minmax(320px, 0.85fr);
    }

    .pf-modal-close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 42px;
        height: 42px;
        border-radius: 999px;
        border: 1px solid rgba(203, 213, 225, 0.95);
        background: white;
        color: #334155;
        font-size: 20px;
        font-weight: 700;
        transition: all 0.2s ease;
    }

    .pf-modal-close:hover {
        background: #eef2ff;
        color: #4338ca;
    }

    .pf-modal-title {
        font-size: 1.5rem;
        line-height: 1.2;
        font-weight: 800;
        color: #0f172a;
    }

    .pf-modal-subtitle {
        margin-top: 6px;
        font-size: 13px;
        color: #64748b;
    }

    @media (max-width: 900px) {
        .pf-modal-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .pf-card-meta {
            grid-template-columns: 1fr;
        }

        .pf-modal {
            padding: 12px;
        }

        .pf-modal-header,
        .pf-modal-body {
            padding-left: 16px;
            padding-right: 16px;
        }
    }

    .card-title {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
    }

    .card-subtitle {
        font-size: 14px;
        color: #6b7280;
        margin-top: 4px;
    }

    /* Styles pour les panels de chart */
    .panel-chart {
        background: white;
        border-radius: 16px;
        padding: 20px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        border: 1px solid #f3f4f6;
    }

    .chart-title {
        font-size: 18px;
        font-weight: 600;
        color: #1f2937;
        margin-bottom: 16px;
    }

    .chart-rect {
        height: 200px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #6b7280;
    }

    /* Styles pour les filtres actifs */
    .filter-chip {
        display: inline-flex;
        align-items: center;
        padding: 4px 8px;
        background: #e0e7ff;
        color: #3730a3;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 500;
    }

    .filter-recap {
        border: 1px solid rgba(99, 102, 241, 0.12);
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.92) 0%, rgba(238, 242, 255, 0.92) 100%);
        box-shadow: 0 12px 30px rgba(79, 70, 229, 0.08);
    }

    .filter-recap.flash {
        animation: filterRecapPulse 0.85s ease;
    }

    .pf-calendar {
        display: grid;
        gap: 10px;
        grid-template-columns: repeat(7, minmax(0, 1fr));
    }

    .pf-calendar-weekday {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #64748b;
        text-align: center;
    }

    .pf-calendar-day {
        min-height: 112px;
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.92);
        padding: 10px;
        text-align: left;
        transition: transform 140ms ease, box-shadow 140ms ease, border-color 140ms ease, background-color 140ms ease;
    }

    .pf-calendar-day:hover,
    .pf-calendar-day:focus {
        outline: none;
        transform: translateY(-1px);
        border-color: rgba(79, 70, 229, 0.55);
        box-shadow: 0 14px 30px rgba(15, 23, 42, 0.12);
        background: #ffffff;
    }

    .pf-calendar-day.is-outside {
        opacity: 0.45;
        background: #f8fafc;
    }

    .pf-calendar-day.has-amount {
        border-color: rgba(16, 185, 129, 0.28);
        background: linear-gradient(180deg, rgba(236, 253, 245, 0.96), rgba(255, 255, 255, 0.98));
    }

    .pf-calendar-day.is-selected {
        border-color: rgba(79, 70, 229, 0.72);
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.14), 0 14px 34px rgba(79, 70, 229, 0.18);
    }

    .pf-calendar-day-number {
        font-size: 13px;
        font-weight: 800;
        color: #0f172a;
    }

    .pf-calendar-amount {
        margin-top: 10px;
        font-size: 13px;
        line-height: 1.25;
        font-weight: 800;
        color: #047857;
    }

    .pf-calendar-count {
        margin-top: 5px;
        font-size: 11px;
        font-weight: 700;
        color: #475569;
    }

    .pf-calendar-sample {
        margin-top: 8px;
        display: grid;
        gap: 4px;
    }

    .pf-calendar-sample-item {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        border-radius: 999px;
        background: rgba(79, 70, 229, 0.08);
        padding: 3px 7px;
        font-size: 10px;
        font-weight: 700;
        color: #4338ca;
    }

    .pf-calendar-detail {
        border: 1px solid rgba(226, 232, 240, 0.95);
        border-radius: 18px;
        background: rgba(248, 250, 252, 0.9);
        padding: 14px;
    }

    @media (max-width: 768px) {
        .pf-calendar {
            gap: 6px;
        }

        .pf-calendar-day {
            min-height: 86px;
            border-radius: 12px;
            padding: 7px;
        }

        .pf-calendar-amount {
            font-size: 11px;
        }

        .pf-calendar-sample {
            display: none;
        }
    }

    @keyframes filterRecapPulse {
        0% {
            transform: translateY(0);
            box-shadow: 0 0 0 rgba(79, 70, 229, 0);
        }

        35% {
            transform: translateY(-2px);
            box-shadow: 0 16px 38px rgba(79, 70, 229, 0.16);
        }

        100% {
            transform: translateY(0);
            box-shadow: 0 12px 30px rgba(79, 70, 229, 0.08);
        }
    }
</style>
<div class="container mx-auto py-8 px-4">
    <h1 class="text-3xl font-bold text-indigo-700 mb-6">Point financier</h1>

    <?php if ($pfIndexMissingCount > 0): ?>
        <div class="mb-6 p-5 rounded-2xl bg-amber-50 border border-amber-200">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="text-sm text-amber-900">
                    <div class="font-semibold">Optimisation DB recommandée</div>
                    <div class="mt-1">
                        Il manque <strong><?= (int)$pfIndexMissingCount ?></strong> index(s) utiles pour éviter lenteurs/timeouts sur « Point financier » (mutualisé).
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="<?= htmlspecialchars($pfIndexMaintenanceUrl) ?>" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition font-semibold text-sm">
                        Appliquer
                    </a>
                    <a href="?recheck_indexes=1" class="inline-flex items-center px-4 py-2 bg-white border border-amber-300 text-amber-900 rounded-xl hover:bg-amber-100 transition font-semibold text-sm">
                        Re-vérifier
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Formulaire global -->
    <div class="bg-gradient-to-br from-white via-indigo-50 to-purple-50 backdrop-blur-sm rounded-3xl shadow-2xl border border-indigo-100/50 p-8 mb-10">
        <div class="flex items-center mb-6">
            <div class="bg-gradient-to-r from-indigo-500 to-purple-600 p-3 rounded-2xl mr-4">
                <i class="fas fa-search text-white text-xl"></i>
            </div>
            <div>
                <h2 class="text-2xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">Filtres de recherche</h2>
                <p class="text-sm text-gray-600">Affinez vos résultats pour une analyse précise</p>
            </div>
        </div>

        <form id="globalForm" class="space-y-6">
            <!-- Première ligne -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-6">
                <div class="space-y-2">
                    <label class="flex items-center text-sm font-semibold text-gray-700">
                        <i class="fas fa-user mr-2 text-indigo-500"></i>
                        Bénéficiaire
                    </label>
                    <div class="relative">
                        <input name="beneficiaire" class="w-full pl-4 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200 bg-white/80 backdrop-blur-sm" placeholder="Nom du bénéficiaire" />
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="flex items-center text-sm font-semibold text-gray-700">
                        <i class="fas fa-hashtag mr-2 text-indigo-500"></i>
                        N° de fiche
                    </label>
                    <div class="relative">
                        <input name="num_fiche" class="w-full pl-4 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200 bg-white/80 backdrop-blur-sm" placeholder="Ex: FEB-2026-001" />
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                    </div>
                </div>

                <div class="space-y-2 xl:col-span-2">
                    <label class="flex items-center text-sm font-semibold text-gray-700">
                        <i class="fas fa-align-left mr-2 text-indigo-500"></i>
                        Libellé / détail de demande
                    </label>
                    <div class="relative">
                        <input name="detail_fiche" class="w-full pl-4 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200 bg-white/80 backdrop-blur-sm" placeholder="Libellé, précision, détail..." />
                        <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="flex items-center text-sm font-semibold text-gray-700">
                        <i class="fas fa-building mr-2 text-indigo-500"></i>
                        Chantier
                    </label>
                    <div id="selectChantier" class="select-modern" data-placeholder="Sélectionner un chantier"></div>
                    <input type="hidden" name="chantier" />
                </div>

                <div class="space-y-2">
                    <label class="flex items-center text-sm font-semibold text-gray-700">
                        <i class="fas fa-money-bill-wave mr-2 text-indigo-500"></i>
                        Déboursé
                    </label>
                    <div id="selectDebourse" class="select-modern disabled" data-placeholder="Choisir un déboursé"></div>
                    <input type="hidden" name="debourse" />
                </div>



            </div>

            <!-- Deuxième ligne -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">

                <div class="space-y-2">
                    <label class="flex items-center text-sm font-semibold text-gray-700">
                        <i class="fas fa-bullseye mr-2 text-indigo-500"></i>
                        Date précise
                    </label>
                    <input type="date" name="exact_date" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200 bg-white/80 backdrop-blur-sm" />
                </div>

                <div class="space-y-2">
                    <label class="flex items-center text-sm font-semibold text-gray-700">
                        <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i>
                        Date de début
                    </label>
                    <input type="date" name="from" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200 bg-white/80 backdrop-blur-sm" />
                </div>

                <div class="space-y-2">
                    <label class="flex items-center text-sm font-semibold text-gray-700">
                        <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i>
                        Date de fin
                    </label>
                    <input type="date" name="to" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200 bg-white/80 backdrop-blur-sm" />
                </div>



                <div class="space-y-2">
                    <label class="flex items-center text-sm font-semibold text-gray-700">
                        <i class="fas fa-layer-group mr-2 text-indigo-500"></i>
                        Etat de la demande
                    </label>
                    <select name="etat_demande" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all duration-200 bg-white/80 backdrop-blur-sm">
                        <option value="en_attente">Conformité</option>
                        <option value="pre_achat">Approbation</option>
                        <option value="valides_achat">Validation Achat</option>
                        <option value="decaissements">Décaissements à traiter</option>
                        <option value="decaissees">Décaissements</option>
                        <option value="refus_demandes">Refus</option>
                        <option value="toutes" selected>Toutes les demandes</option>
                        <option value="attente_globale" hidden>En attente</option>
                    </select>
                </div>

            </div>

            <!-- Ligne dédiée aux actions -->
            <div class="rounded-2xl border border-indigo-100 bg-white/70 p-4 shadow-sm">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="text-sm font-bold text-slate-800">Actions de recherche</div>
                        <div class="text-xs text-slate-500">Appliquez les filtres, réinitialisez ou exportez le point financier filtré.</div>
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:flex lg:justify-end">
                        <button class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 px-5 py-3 font-semibold text-white shadow-lg transition-all duration-200 hover:-translate-y-0.5 hover:from-indigo-700 hover:to-purple-700 hover:shadow-xl" type="submit">
                            <i class="fas fa-filter mr-2"></i>
                            Appliquer les filtres
                        </button>
                        <button class="inline-flex items-center justify-center rounded-xl bg-slate-100 px-5 py-3 font-semibold text-slate-700 transition-all duration-200 hover:bg-slate-200" type="button" id="btnReset">
                            <i class="fas fa-undo mr-2"></i>
                            Réinitialiser
                        </button>
                        <button type="button" id="btnExportCsv" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-green-500 to-emerald-600 px-5 py-3 font-semibold text-white shadow-lg transition-all duration-200 hover:-translate-y-0.5 hover:from-green-600 hover:to-emerald-700 hover:shadow-xl">
                            <i class="fas fa-file-csv mr-2"></i>
                            Export CSV
                        </button>
                        <button type="button" id="btnExportPdf" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-red-500 to-rose-600 px-5 py-3 font-semibold text-white shadow-lg transition-all duration-200 hover:-translate-y-0.5 hover:from-red-600 hover:to-rose-700 hover:shadow-xl">
                            <i class="fas fa-file-pdf mr-2"></i>
                            Export PDF
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 pt-2">
                <span class="text-sm font-semibold text-gray-700">Raccourcis :</span>
                <button type="button" class="quick-range-btn inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700" data-range-preset="today">
                    <i class="fas fa-calendar-day text-indigo-500"></i>
                    Aujourd'hui
                </button>
                <button type="button" class="quick-range-btn inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700" data-range-preset="yesterday">
                    <i class="fas fa-clock text-indigo-500"></i>
                    Hier
                </button>
                <button type="button" class="quick-range-btn inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700" data-range-preset="last7days">
                    <i class="fas fa-stream text-indigo-500"></i>
                    7 derniers jours
                </button>
                <button type="button" class="quick-range-btn inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700" data-range-preset="month">
                    <i class="fas fa-calendar-alt text-indigo-500"></i>
                    Ce mois
                </button>
                <button type="button" class="quick-range-btn inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700" data-range-preset="year">
                    <i class="fas fa-calendar text-indigo-500"></i>
                    Cette année
                </button>
            </div>

            <div id="filterRecap" class="filter-recap hidden rounded-2xl px-5 py-4">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.25em] text-indigo-600">
                            <i class="fas fa-chart-line"></i>
                            Récapitulatif rapide
                        </div>
                        <div id="filterRecapTitle" class="mt-2 text-base font-semibold text-slate-900">Aucun résultat chargé</div>
                        <div id="filterRecapSubtitle" class="mt-1 text-sm text-slate-500">Lancez une recherche pour afficher une synthèse ici.</div>
                    </div>
                    <div id="filterRecapMetrics" class="grid grid-cols-2 gap-3 lg:min-w-[440px] lg:grid-cols-4"></div>
                </div>
            </div>
        </form>
    </div>

    <!-- Stats -->
    <section id="statsSection" class="mb-12">
        <h2 class="text-xl font-semibold text-indigo-700 mb-4">Statistiques synthétiques</h2>
        <div id="loadingGauge" class="hidden mb-6">
            <div class="flex items-center justify-between text-xs text-gray-600 mb-2">
                <span id="loadingGaugeLabel">Chargement...</span>
                <span id="loadingGaugePct">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                <div id="loadingGaugeBar" class="h-2 bg-gradient-to-r from-indigo-600 to-purple-600 rounded-full" style="width:0%"></div>
            </div>
        </div>
        <div id="statsSummary" class="grid sm:grid-cols-2 xl:grid-cols-5 gap-5 mb-8"></div>
        <div id="workflowOverview" class="grid sm:grid-cols-2 xl:grid-cols-5 gap-4 mb-8"></div>
        <div class="grid md:grid-cols-2 gap-8">
            <div class="panel-chart">
                <h3 class="chart-title">Prévision vs Sorties</h3>
                <div id="chartPrevuSorties" class="chart-rect">Chargement...</div>
            </div>
            <div class="panel-chart">
                <h3 class="chart-title">Sorties par chantier</h3>
                <div id="chartChantier" class="h-48 overflow-auto text-xs"></div>
            </div>
            <div class="panel-chart md:col-span-2">
                <h3 class="chart-title">Évolution quotidienne des sorties</h3>
                <div id="chartDay" class="h-56 flex items-end gap-1 flex-nowrap overflow-x-auto pr-2"></div>
            </div>
            <div class="panel-chart md:col-span-2">
                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between mb-4">
                    <div>
                        <h3 class="chart-title mb-1">Calendrier des decaissements</h3>
                        <div id="pfCalendarSubtitle" class="text-xs text-slate-500">Les montants suivent les filtres courants.</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="pfCalendarPrev" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <div id="pfCalendarMonthLabel" class="min-w-[170px] text-center text-sm font-bold text-slate-800"></div>
                        <button type="button" id="pfCalendarNext" class="inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <div id="pfDisbursementCalendar" class="pf-calendar"></div>
                <div id="pfCalendarDayDetail" class="pf-calendar-detail mt-4 hidden"></div>
            </div>
        </div>
    </section>

    <!-- Listing -->
    <section id="listingSection">
        <div class="flex flex-wrap items-center justify-between mb-4">
            <div class="text-sm">
                <span class="font-bold text-indigo-700" id="nbFiches">0</span> fiche(s) | Total: <span class="font-bold text-green-600" id="totalMontant">0</span> FCFA
            </div>
            <div id="activeFilters" class="flex flex-wrap gap-2 text-[11px]"></div>
        </div>
        <div id="selectionSynth" class="hidden mb-6"></div>
        <div id="cards-container" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3"></div>
    </section>
</div>

<div id="pfDetailModal" class="pf-modal" aria-hidden="true">
    <div class="pf-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="pfDetailModalTitle">
        <div class="pf-modal-header">
            <div>
                <div id="pfDetailModalTitle" class="pf-modal-title">Détails fiche</div>
                <div id="pfDetailModalSubtitle" class="pf-modal-subtitle">Chargement...</div>
            </div>
            <button type="button" class="pf-modal-close" data-pf-modal-close aria-label="Fermer">×</button>
        </div>
        <div id="pfDetailModalBody" class="pf-modal-body"></div>
    </div>
</div>
<script>
    const isDemo = (typeof getQueryParam === 'function' && getQueryParam('demo') === '1');
    const etatLabels = {
        decaissements: 'Décaissements à traiter',
        refus_demandes: 'Refus',
        en_attente: 'Conformité',
        pre_achat: 'Approbation',
        valides_achat: 'Validation Achat',
        toutes: 'Toutes les demandes',
        attente_globale: 'En attente',
        decaissees: 'Décaissements'
    };
    window.currencyFormatCFA = window.currencyFormatCFA || function(n) {
        try {
            return (Number(n) || 0).toLocaleString('fr-FR') + ' CFA';
        } catch {
            return n + ' CFA';
        }
    };

    function setLoading(state) {
        const wrap = document.getElementById('cards-container');
        if (!state) {
            wrap.querySelectorAll('.skeleton').forEach(el => el.remove());
            return;
        }
        wrap.innerHTML = '';
        for (let i = 0; i < 8; i++) {
            const sk = document.createElement('div');
            sk.className = 'card p-6 bg-white rounded-2xl shadow skeleton';
            sk.style.height = '190px';
            wrap.appendChild(sk);
        }
    }

    function notifyError(message) {
        if (typeof showToast === 'function') {
            showToast(message, 'error');
            return;
        }
        alert(message);
    }

    async function fetchJson(url, options = {}) {
        const res = await fetch(url, {
            signal: options.signal,
            headers: {
                'Accept': 'application/json'
            }
        });
        const text = await res.text();
        let json;
        try {
            json = text ? JSON.parse(text) : null;
        } catch (e) {
            throw new Error(`Réponse non JSON (HTTP ${res.status})`);
        }
        if (!res.ok) {
            throw new Error(json?.error || `Erreur serveur (HTTP ${res.status})`);
        }
        return json;
    }

    function setProgress(percent, label) {
        const gauge = document.getElementById('loadingGauge');
        const bar = document.getElementById('loadingGaugeBar');
        const pct = document.getElementById('loadingGaugePct');
        const lab = document.getElementById('loadingGaugeLabel');
        if (!gauge || !bar || !pct || !lab) return;

        const clamped = Math.max(0, Math.min(100, Number(percent) || 0));
        gauge.classList.remove('hidden');
        bar.style.width = clamped + '%';
        pct.textContent = clamped + '%';
        lab.textContent = label || 'Chargement...';
    }

    function hideProgressSoon() {
        const gauge = document.getElementById('loadingGauge');
        if (!gauge) return;
        setTimeout(() => gauge.classList.add('hidden'), 250);
    }

    async function fetchAndRender(signal) {
        const params = collectParams();
        const endpoint = 'request/fiches_decaissement_list.php?' + params.toString();
        setLoading(true);
        try {
            const json = await fetchJson(endpoint, {
                signal
            });
            if (!json?.ok) {
                notifyError('Erreur: ' + (json?.error || ''));
                return;
            }
            document.getElementById('nbFiches').textContent = json.count || (json.items?.length || 0);
            document.getElementById('totalMontant').textContent = (json.total || 0).toLocaleString('fr-FR');
            const wrap = document.getElementById('cards-container');
            lastFetchedFiches = json.items || [];
            wrap.innerHTML = lastFetchedFiches.map(cardTemplate).join('');
            bindEvents(wrap);
            renderDisbursementCalendar({
                resetMonth: true
            });
            return {
                count: Number(json.count || (json.items?.length || 0)),
                total: Number(json.total || 0)
            };
        } finally {
            setLoading(false);
        }
    }

    function ficheViewLink(numFiche, options = {}) {
        if (!numFiche) return '';
        const classes = options.classes || 'inline-flex items-center gap-1 px-2 py-1 rounded-lg border border-indigo-200 bg-white text-indigo-600 hover:bg-indigo-50 hover:text-indigo-800 transition';
        const title = escapeHtml(options.title || 'Voir la fiche');
        const label = options.label || `<svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12H9m12 0A9 9 0 1 1 3 12a9 9 0 0 1 18 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" /></svg>`;
        return `<a href="voir_fiche_details.php?num_fiche=${encodeURIComponent(numFiche)}" target="_blank" rel="noopener" class="${classes}" title="${title}">${label}</a>`;
    }

    function formatDateTimeFR(value) {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return escapeHtml(String(value));
        }
        return date.toLocaleString('fr-FR', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }

    function getInitials(name) {
        const parts = String(name || '').trim().split(/\s+/).filter(Boolean).slice(0, 2);
        if (!parts.length) return 'PF';
        return parts.map(part => part.charAt(0).toUpperCase()).join('');
    }

    function renderBeneficiaryAvatar(f) {
        const photoUrl = f.photo_url || null;
        if (photoUrl) {
            return `<div class="pf-avatar"><img src="${escapeHtml(photoUrl)}" alt="Photo de ${escapeHtml(f.beficiaire_fiche || 'bénéficiaire')}"></div>`;
        }
        return `<div class="pf-avatar"><div class="pf-avatar-fallback">${escapeHtml(getInitials(f.beficiaire_fiche || ''))}</div></div>`;
    }

    function timelineToneClasses(tone) {
        switch (tone) {
            case 'blue':
                return {
                    dot: 'background:#2563eb',
                        card: 'border-color: rgba(191, 219, 254, 0.95); background: linear-gradient(180deg, rgba(239, 246, 255, 0.95), rgba(255, 255, 255, 0.98));'
                };
            case 'amber':
                return {
                    dot: 'background:#d97706',
                        card: 'border-color: rgba(253, 230, 138, 0.95); background: linear-gradient(180deg, rgba(255, 251, 235, 0.96), rgba(255, 255, 255, 0.98));'
                };
            case 'indigo':
                return {
                    dot: 'background:#4f46e5',
                        card: 'border-color: rgba(199, 210, 254, 0.95); background: linear-gradient(180deg, rgba(238, 242, 255, 0.96), rgba(255, 255, 255, 0.98));'
                };
            case 'green':
                return {
                    dot: 'background:#059669',
                        card: 'border-color: rgba(167, 243, 208, 0.95); background: linear-gradient(180deg, rgba(236, 253, 245, 0.96), rgba(255, 255, 255, 0.98));'
                };
            case 'red':
                return {
                    dot: 'background:#dc2626',
                        card: 'border-color: rgba(254, 205, 211, 0.95); background: linear-gradient(180deg, rgba(255, 241, 242, 0.96), rgba(255, 255, 255, 0.98));'
                };
            case 'slate':
            default:
                return {
                    dot: 'background:#475569',
                        card: 'border-color: rgba(226, 232, 240, 0.95); background: linear-gradient(180deg, rgba(248, 250, 252, 0.98), rgba(255, 255, 255, 0.98));'
                };
        }
    }

    function buildWorkflowTimeline(f) {
        const timeline = Array.isArray(f.workflow_timeline) ? f.workflow_timeline : [];
        if (!timeline.length) {
            return '<div class="pf-timeline-empty">Aucun évènement horodaté disponible.</div>';
        }

        return `<div class="pf-timeline">${timeline.map(item => {
            const tone = timelineToneClasses(item.tone || 'slate');
            return `<div class="pf-timeline-item">
                <span class="pf-timeline-dot" style="${tone.dot}"></span>
                <div class="pf-timeline-card" style="${tone.card}">
                    <div class="pf-timeline-label">${escapeHtml(item.label || 'Évènement')}</div>
                    <div class="pf-timeline-date">${formatDateTimeFR(item.date)}</div>
                </div>
            </div>`;
        }).join('')}</div>`;
    }

    function buildMetaCard(label, value, options = {}) {
        const extraClass = options.wide ? ' pf-card-meta-item--wide' : '';
        const valueClass = [
            'pf-card-meta-value',
            options.clamp ? 'pf-clamp-2' : '',
            options.singleLine ? 'pf-card-meta-value--single' : ''
        ].filter(Boolean).join(' ');
        return `<div class="pf-card-meta-item${extraClass}">
            <span class="pf-card-meta-label">${escapeHtml(label)}</span>
            <span class="${valueClass}">${escapeHtml(value || '—')}</span>
        </div>`;
    }

    function buildDetailRows(f) {
        const chantier = [f.chantier_code || '', f.chantier_titre || ''].filter(Boolean).join(' - ');
        const debourse = f.devis_designation ? `#${f.debourse_id || '—'} - ${f.devis_designation}` : (f.debourse_id ? ('#' + f.debourse_id) : '—');
        const ligneDebourse = f.ligne_designation || '—';
        const detailsDemande = String(f.details_display || f.details_demande || '').trim();

        return [
            buildMetaCard('Chantier', chantier || '—', {
                wide: true,
                singleLine: true
            }),
            buildMetaCard('Déboursé', debourse || '—', {
                wide: true,
                singleLine: true
            }),
            buildMetaCard('Ligne déboursé', ligneDebourse || '—', {
                singleLine: true
            }),
            buildMetaCard('Précision', f.precision || '—', {
                singleLine: true
            }),
            buildMetaCard('Détail saisi', detailsDemande || '—', {
                wide: true,
                clamp: true
            })
        ].join('');
    }

    function renderModalDetail(f) {
        const chantier = [f.chantier_code || '', f.chantier_titre || ''].filter(Boolean).join(' - ');
        const debourse = f.devis_designation ? `#${f.debourse_id || '—'} - ${f.devis_designation}` : (f.debourse_id ? ('#' + f.debourse_id) : '—');
        const detailsDemande = String(f.details_display || f.details_demande || '').trim();
        const motifBlock = f.motif_refus ? `<div class="pf-panel" style="border-color: rgba(254, 205, 211, 0.95); background: linear-gradient(180deg, rgba(255,241,242,0.96), rgba(255,255,255,0.98));"><div class="pf-panel-title" style="color:#be123c;">Motif de refus</div><div class="pf-notes" style="color:#881337; max-height:none;">${escapeHtml(f.motif_refus)}</div></div>` : '';

        return `
            <div class="pf-modal-grid">
                <div class="grid gap-4">
                    <div class="pf-panel">
                        <div class="pf-panel-title">Informations métier</div>
                        <div class="grid gap-2">
                            <div class="pf-key-value"><span class="pf-key">Bénéficiaire</span><span class="pf-value">${escapeHtml(f.beficiaire_fiche || '—')}</span></div>
                            <div class="pf-key-value"><span class="pf-key">Chantier</span><span class="pf-value">${escapeHtml(chantier || '—')}</span></div>
                            <div class="pf-key-value"><span class="pf-key">Déboursé</span><span class="pf-value">${escapeHtml(debourse || '—')}</span></div>
                            <div class="pf-key-value"><span class="pf-key">Ligne</span><span class="pf-value">${escapeHtml(f.ligne_designation || '—')}</span></div>
                            <div class="pf-key-value"><span class="pf-key">Précision</span><span class="pf-value">${escapeHtml(f.precision || '—')}</span></div>
                            <div class="pf-key-value"><span class="pf-key">Montant</span><span class="pf-value">${currencyFormatCFA(f.montant_fiche)}</span></div>
                            <div class="pf-key-value"><span class="pf-key">Statut</span><span class="pf-value">${escapeHtml(f.status || '—')}</span></div>
                        </div>
                    </div>
                    <div class="pf-panel">
                        <div class="pf-panel-title">Détail saisi</div>
                        <div class="pf-notes" style="max-height:none;">${escapeHtml(detailsDemande || 'Aucun détail saisi.')}</div>
                    </div>
                    ${motifBlock}
                </div>
                <div class="grid gap-4">
                    <details class="pf-accordion">
                        <summary>Chronologie métier</summary>
                        <div class="pf-accordion-body">
                            <div class="pt-4">${buildWorkflowTimeline(f)}</div>
                        </div>
                    </details>
                    <div class="pf-panel">
                        <div class="pf-panel-title">Repères rapides</div>
                        <div class="grid gap-2">
                            <div class="pf-key-value"><span class="pf-key">Référence</span><span class="pf-value">${escapeHtml(f.reference_label || 'Date demande')}</span></div>
                            <div class="pf-key-value"><span class="pf-key">Date repère</span><span class="pf-value">${formatDateTimeFR(f.reference_date || f.date_creat_fiche)}</span></div>
                            <div class="pf-key-value"><span class="pf-key">Création</span><span class="pf-value">${formatDateTimeFR(f.date_creat_fiche)}</span></div>
                        </div>
                    </div>
                </div>
            </div>`;
    }

    function openDetailModal(f) {
        const modal = document.getElementById('pfDetailModal');
        const titleNode = document.getElementById('pfDetailModalTitle');
        const subtitleNode = document.getElementById('pfDetailModalSubtitle');
        const bodyNode = document.getElementById('pfDetailModalBody');
        if (!modal || !titleNode || !subtitleNode || !bodyNode) return;

        titleNode.textContent = `Fiche N° ${f.num_fiche || '—'}`;
        subtitleNode.textContent = `${f.beficiaire_fiche || 'Bénéficiaire'} • ${f.status || 'Statut non défini'} • ${currencyFormatCFA(f.montant_fiche)}`;
        bodyNode.innerHTML = renderModalDetail(f);
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeDetailModal() {
        const modal = document.getElementById('pfDetailModal');
        const bodyNode = document.getElementById('pfDetailModalBody');
        if (!modal || !bodyNode) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        bodyNode.innerHTML = '';
        document.body.style.overflow = '';
    }

    function cardTemplate(f) {
        const mode = f.mode ? `<span class="pf-chip" style="background:#eef2ff;color:#4338ca;">${escapeHtml(f.mode)}</span>` : '';
        const statusChip = f.status ? `<span class="pf-chip" style="background:${f.badge === 'green' ? '#ecfdf5;color:#047857' : f.badge === 'red' ? '#fff1f2;color:#be123c' : f.badge === 'indigo' ? '#eef2ff;color:#4338ca' : f.badge === 'sky' ? '#ecfeff;color:#0f766e' : f.badge === 'amber' ? '#fffbeb;color:#b45309' : f.badge === 'blue' ? '#eff6ff;color:#1d4ed8' : '#f8fafc;color:#475569'};">${escapeHtml(f.status)}</span>` : '';
        const ficheMeta = f.reference_label ? escapeHtml(f.reference_label) : 'Date de référence';
        const ficheLink = ficheViewLink(f.num_fiche, {
            classes: 'inline-flex items-center justify-center w-7 h-7 rounded-full border border-indigo-200 bg-white text-indigo-600 hover:bg-indigo-50 hover:text-indigo-800 transition',
            title: 'Voir la fiche'
        });
        const ficheAction = ficheViewLink(f.num_fiche, {
            classes: 'inline-flex items-center gap-1 px-3 py-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 text-white hover:from-indigo-700 hover:to-violet-700 transition text-xs font-semibold shadow-sm',
            title: 'Ouvrir la fiche',
            label: 'Visualiser'
        });
        const detailAction = `<button type="button" class="js-open-pf-detail inline-flex items-center gap-1 px-3 py-2 rounded-xl border border-indigo-200 bg-white text-indigo-700 hover:bg-indigo-50 transition text-xs font-semibold shadow-sm" data-num="${escapeHtml(f.num_fiche || '')}">Détails</button>`;
        const refusalCompact = f.motif_refus ? `<div class="pf-refusal-compact"><div class="pf-refusal-compact-title">Motif de refus</div><div class="pf-refusal-compact-text pf-clamp-2">${escapeHtml(f.motif_refus)}</div></div>` : '';
        return `
        <article class="card pf-card p-5 relative">
            <div class="pf-card-topline">
                <div class="pf-card-number">
                    <span>Fiche N° ${escapeHtml(f.num_fiche || '')}</span>
                    ${ficheLink}
                </div>
                <div class="pf-card-badges">
                    ${mode}
                    ${statusChip}
                </div>
            </div>

            <div class="pf-card-amountline">
                <span class="pf-card-amountline-label">Montant demandé</span>
                <span class="pf-amount">${currencyFormatCFA(f.montant_fiche)}</span>
            </div>

            <div class="pf-card-header">
                <div class="flex items-start gap-4 min-w-0">
                    ${renderBeneficiaryAvatar(f)}
                    <div class="min-w-0 flex-1">
                        <div class="text-lg font-bold text-slate-900 leading-tight">${escapeHtml(f.beficiaire_fiche || 'Bénéficiaire non renseigné')}</div>
                        <div class="mt-1 text-[11px] text-slate-500">${ficheMeta}</div>
                        <div class="text-[11px] font-semibold text-slate-700">${formatDateTimeFR(f.reference_date || f.date_creat_fiche)}</div>
                    </div>
                </div>
            </div>

            <div class="mt-4 pf-card-meta">
                ${buildDetailRows(f)}
            </div>

            ${refusalCompact}

            <div class="mt-4 pf-card-footer">
                <div class="grid gap-1 text-xs text-slate-500">
                    <span>${escapeHtml(f.reference_label || 'Date demande')}</span>
                    <span class="font-semibold text-slate-700">Créée le ${formatDateTimeFR(f.date_creat_fiche)}</span>
                </div>
                <div class="flex items-center gap-3 ml-auto">
                    ${detailAction}
                    ${ficheAction}
                </div>
            </div>
        </article>`;
    }

    function bindEvents(root = document) {
        root.querySelectorAll('.js-open-pf-detail').forEach(button => {
            button.addEventListener('click', () => {
                const numFiche = button.getAttribute('data-num') || '';
                const fiche = lastFetchedFiches.find(item => String(item.num_fiche || '') === String(numFiche));
                if (!fiche) return;
                openDetailModal(fiche);
            });
        });
    }

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, s => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            '\'': '&#39;'
        } [s]));
    }

    function formatDateFR(iso) {
        try {
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return iso;
            return d.toLocaleDateString('fr-FR', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        } catch {
            return iso;
        }
    }

    function parseIsoDate(dateString) {
        if (!dateString) return null;
        const match = String(dateString).match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) return null;
        return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    }

    function formatDateLabel(date, options) {
        if (!(date instanceof Date) || Number.isNaN(date.getTime())) return '';
        return date.toLocaleDateString('fr-FR', options);
    }

    function buildContinuousDailySeries(days, interval = {}) {
        const amounts = new Map();
        (days || []).forEach(item => {
            if (!item || !item.d) return;
            amounts.set(String(item.d), Number(item.montant || 0));
        });

        let startDate = parseIsoDate(interval.exact_date || interval.from || '');
        if (!startDate && days && days.length) {
            startDate = parseIsoDate(days[0].d);
        }
        if (!startDate) {
            return [];
        }

        let endDate = parseIsoDate(interval.exact_date || interval.to || '');
        if (!endDate) {
            const today = new Date();
            endDate = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        }

        if (endDate < startDate) {
            const swapDate = startDate;
            startDate = endDate;
            endDate = swapDate;
        }

        const series = [];
        for (let cursor = new Date(startDate); cursor <= endDate; cursor.setDate(cursor.getDate() + 1)) {
            const iso = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}-${String(cursor.getDate()).padStart(2, '0')}`;
            series.push({
                d: iso,
                montant: amounts.has(iso) ? amounts.get(iso) : 0
            });
        }

        return series;
    }

    function formatIsoDate(date) {
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    }

    function getFicheReferenceDateKey(f) {
        const raw = String((f && (f.date_decaisse || f.date_decaissement || f.reference_date || f.date_creat_fiche)) || '').trim();
        if (!raw) return '';
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (match) return `${match[1]}-${match[2]}-${match[3]}`;
        const date = new Date(raw);
        return Number.isNaN(date.getTime()) ? '' : formatIsoDate(date);
    }

    function isEffectiveDisbursement(f) {
        if (!f) return false;
        if (Number(f.decaisse || 0) === 1) return true;
        return String(f.status_key || '').toLowerCase() === 'decaissees';
    }

    let pfCalendarMonth = null;
    let pfCalendarSelectedDate = '';

    function getCalendarInitialMonth() {
        const fd = new FormData(formGlobal);
        const exactDate = fd.get('exact_date');
        const from = fd.get('from');
        const firstFicheDate = (lastFetchedFiches || []).filter(isEffectiveDisbursement).map(getFicheReferenceDateKey).filter(Boolean).sort()[0] || '';
        const seed = exactDate || from || firstFicheDate || formatIsoDate(new Date());
        const date = parseIsoDate(seed) || new Date();
        return new Date(date.getFullYear(), date.getMonth(), 1);
    }

    function groupFichesByReferenceDate(rows) {
        const grouped = new Map();
        (rows || []).forEach(f => {
            if (!isEffectiveDisbursement(f)) return;
            const key = getFicheReferenceDateKey(f);
            if (!key) return;
            if (!grouped.has(key)) {
                grouped.set(key, {
                    date: key,
                    total: 0,
                    count: 0,
                    fiches: []
                });
            }
            const day = grouped.get(key);
            day.total += Number(f.montant_fiche || 0);
            day.count += 1;
            day.fiches.push(f);
        });
        return grouped;
    }

    function renderCalendarDayDetail(dateKey, grouped) {
        const detail = document.getElementById('pfCalendarDayDetail');
        if (!detail) return;
        const day = grouped.get(dateKey);
        if (!day || !day.count) {
            detail.classList.add('hidden');
            detail.innerHTML = '';
            return;
        }

        const titleDate = formatDateLabel(parseIsoDate(dateKey), {
            weekday: 'long',
            day: '2-digit',
            month: 'long',
            year: 'numeric'
        });
        detail.classList.remove('hidden');
        detail.innerHTML = `
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <div class="text-sm font-bold text-slate-900">${escapeHtml(titleDate)}</div>
                    <div class="text-xs text-slate-500">${day.count} fiche(s) - ${currencyFormatCFA(day.total)}</div>
                </div>
                <button type="button" id="pfCalendarApplyDate" class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700">
                    Filtrer ce jour
                </button>
            </div>
            <div class="mt-3 grid gap-2">
                ${day.fiches.slice(0, 8).map(f => `
                    <button type="button" class="js-open-pf-detail flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left hover:border-indigo-200 hover:bg-indigo-50" data-num="${escapeHtml(f.num_fiche || '')}">
                        <span class="min-w-0">
                            <span class="block truncate text-xs font-bold text-slate-900">Fiche N ${escapeHtml(f.num_fiche || '')} - ${escapeHtml(f.beficiaire_fiche || 'Beneficiaire')}</span>
                            <span class="block truncate text-[11px] text-slate-500">${escapeHtml(f.chantier_code || '')} ${escapeHtml(f.chantier_titre || '')}</span>
                        </span>
                        <span class="shrink-0 text-xs font-bold text-emerald-700">${currencyFormatCFA(f.montant_fiche)}</span>
                    </button>
                `).join('')}
                ${day.fiches.length > 8 ? `<div class="text-xs text-slate-500">+ ${day.fiches.length - 8} autre(s) fiche(s)</div>` : ''}
            </div>
        `;
        bindEvents(detail);
        const applyButton = detail.querySelector('#pfCalendarApplyDate');
        if (applyButton) {
            applyButton.addEventListener('click', () => {
                exactDateInput.value = dateKey;
                fromInput.value = '';
                toInput.value = '';
                updateQuickRangeButtons();
                applyFilters({
                    highlightRecap: true,
                    autoScroll: true
                });
            });
        }
    }

    function renderDisbursementCalendar(options = {}) {
        const calendar = document.getElementById('pfDisbursementCalendar');
        const label = document.getElementById('pfCalendarMonthLabel');
        const subtitle = document.getElementById('pfCalendarSubtitle');
        const detail = document.getElementById('pfCalendarDayDetail');
        if (!calendar || !label) return;

        if (!pfCalendarMonth || options.resetMonth) {
            pfCalendarMonth = getCalendarInitialMonth();
            pfCalendarSelectedDate = '';
        }

        const grouped = groupFichesByReferenceDate(lastFetchedFiches);
        const monthStart = new Date(pfCalendarMonth.getFullYear(), pfCalendarMonth.getMonth(), 1);
        const monthEnd = new Date(pfCalendarMonth.getFullYear(), pfCalendarMonth.getMonth() + 1, 0);
        const gridStart = new Date(monthStart);
        const dayOffset = (gridStart.getDay() + 6) % 7;
        gridStart.setDate(gridStart.getDate() - dayOffset);

        label.textContent = monthStart.toLocaleDateString('fr-FR', {
            month: 'long',
            year: 'numeric'
        });

        const monthKeys = Array.from(grouped.keys()).filter(key => {
            const d = parseIsoDate(key);
            return d && d >= monthStart && d <= monthEnd;
        });
        const monthTotal = monthKeys.reduce((sum, key) => sum + (grouped.get(key)?.total || 0), 0);
        const monthCount = monthKeys.reduce((sum, key) => sum + (grouped.get(key)?.count || 0), 0);
        if (subtitle) {
            subtitle.textContent = monthCount ? `${monthCount} fiche(s) sur ce mois - ${currencyFormatCFA(monthTotal)}` : 'Aucun decaissement sur ce mois avec les filtres courants.';
        }

        const weekdays = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        const cells = weekdays.map(day => `<div class="pf-calendar-weekday">${day}</div>`);
        for (let i = 0; i < 42; i++) {
            const current = new Date(gridStart);
            current.setDate(gridStart.getDate() + i);
            const key = formatIsoDate(current);
            const day = grouped.get(key);
            const outside = current.getMonth() !== monthStart.getMonth();
            const selected = pfCalendarSelectedDate === key;
            const samples = day ? day.fiches.slice(0, 2).map(f => `<div class="pf-calendar-sample-item">${escapeHtml(f.num_fiche || '')} - ${escapeHtml(f.beficiaire_fiche || '')}</div>`).join('') : '';
            cells.push(`
                <button type="button" class="pf-calendar-day ${outside ? 'is-outside' : ''} ${day ? 'has-amount' : ''} ${selected ? 'is-selected' : ''}" data-calendar-date="${key}">
                    <div class="pf-calendar-day-number">${current.getDate()}</div>
                    ${day ? `<div class="pf-calendar-amount">${currencyFormatCFA(day.total)}</div><div class="pf-calendar-count">${day.count} fiche(s)</div><div class="pf-calendar-sample">${samples}</div>` : '<div class="mt-3 text-[11px] text-slate-400">-</div>'}
                </button>
            `);
        }
        calendar.innerHTML = cells.join('');
        calendar.querySelectorAll('[data-calendar-date]').forEach(button => {
            button.addEventListener('click', () => {
                pfCalendarSelectedDate = button.getAttribute('data-calendar-date') || '';
                renderDisbursementCalendar();
                renderCalendarDayDetail(pfCalendarSelectedDate, grouped);
            });
        });

        if (!pfCalendarSelectedDate && detail) {
            detail.classList.add('hidden');
            detail.innerHTML = '';
        } else if (pfCalendarSelectedDate) {
            renderCalendarDayDetail(pfCalendarSelectedDate, grouped);
        }
    }

    function getPresetRange(preset) {
        const today = new Date();
        const end = new Date(today.getFullYear(), today.getMonth(), today.getDate());
        if (preset === 'today') {
            return {
                exactDate: formatIsoDate(end),
                from: '',
                to: ''
            };
        }
        if (preset === 'yesterday') {
            const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
            return {
                exactDate: formatIsoDate(yesterday),
                from: '',
                to: ''
            };
        }
        if (preset === 'last7days') {
            return {
                exactDate: '',
                from: formatIsoDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6)),
                to: formatIsoDate(end)
            };
        }
        if (preset === 'month') {
            return {
                exactDate: '',
                from: formatIsoDate(new Date(today.getFullYear(), today.getMonth(), 1)),
                to: formatIsoDate(end)
            };
        }
        if (preset === 'year') {
            return {
                exactDate: '',
                from: formatIsoDate(new Date(today.getFullYear(), 0, 1)),
                to: formatIsoDate(end)
            };
        }

        return null;
    }

    function getActiveRangePreset() {
        const today = new Date();
        const todayIso = formatIsoDate(new Date(today.getFullYear(), today.getMonth(), today.getDate()));
        if (exactDateInput?.value === todayIso) {
            return 'today';
        }
        const yesterdayIso = formatIsoDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1));
        if (exactDateInput?.value === yesterdayIso) {
            return 'yesterday';
        }
        if (fromInput?.value === formatIsoDate(new Date(today.getFullYear(), today.getMonth(), today.getDate() - 6)) && toInput?.value === todayIso && !exactDateInput?.value) {
            return 'last7days';
        }
        if (fromInput?.value === formatIsoDate(new Date(today.getFullYear(), today.getMonth(), 1)) && toInput?.value === todayIso && !exactDateInput?.value) {
            return 'month';
        }
        if (fromInput?.value === formatIsoDate(new Date(today.getFullYear(), 0, 1)) && toInput?.value === todayIso && !exactDateInput?.value) {
            return 'year';
        }
        return null;
    }

    function updateQuickRangeButtons() {
        const activePreset = getActiveRangePreset();
        document.querySelectorAll('.quick-range-btn').forEach(button => {
            const isActive = button.getAttribute('data-range-preset') === activePreset;
            button.classList.toggle('border-indigo-500', isActive);
            button.classList.toggle('bg-indigo-600', isActive);
            button.classList.toggle('text-white', isActive);
            button.classList.toggle('shadow-lg', isActive);
            button.classList.toggle('border-gray-200', !isActive);
            button.classList.toggle('bg-white', !isActive);
            button.classList.toggle('text-gray-700', !isActive);
            const icon = button.querySelector('i');
            if (icon) {
                icon.classList.toggle('text-white', isActive);
                icon.classList.toggle('text-indigo-500', !isActive);
            }
        });
    }

    function applyQuickRangePreset(preset) {
        const range = getPresetRange(preset);
        if (!range) return;
        exactDateInput.value = range.exactDate;
        fromInput.value = range.from;
        toInput.value = range.to;
        updateQuickRangeButtons();
        applyFilters({
            highlightRecap: true,
            autoScroll: true
        });
    }

    // ---- Formulaire global events ----
    const formGlobal = document.getElementById('globalForm');
    formGlobal.addEventListener('submit', e => {
        e.preventDefault();
        applyFilters({
            highlightRecap: true,
            autoScroll: true
        });
    });
    document.getElementById('btnReset').onclick = () => {
        formGlobal.reset();
        clearSelect('chantier');
        clearSelect('debourse');
        updateQuickRangeButtons();
        applyFilters({
            highlightRecap: true,
            autoScroll: true
        });
    };
    document.getElementById('btnExportCsv').onclick = () => exportCsv(lastFetchedFiches);
    document.getElementById('btnExportPdf').onclick = () => exportPdf();

    function collectParams() {
        const fd = new FormData(formGlobal);
        return new URLSearchParams(fd);
    }

    function getFilterRecapContext() {
        const fd = new FormData(formGlobal);
        const chantier = fd.get('chantier');
        const debourse = fd.get('debourse');
        const exactDate = fd.get('exact_date');
        const from = fd.get('from');
        const to = fd.get('to');
        const status = fd.get('etat_demande');
        const benef = fd.get('beneficiaire');
        const numFiche = fd.get('num_fiche');
        const detailFiche = fd.get('detail_fiche');

        let scopeLabel = 'Tous les périmètres';
        if (debourse) {
            scopeLabel = selectedDebourseLabel || ('Déboursé #' + debourse);
        } else if (chantier) {
            scopeLabel = selectedChantierLabel || ('Chantier #' + chantier);
        }

        let periodLabel = 'Période globale';
        if (exactDate) {
            periodLabel = exactDate;
        } else if (from || to) {
            periodLabel = (from || '...') + ' → ' + (to || '...');
        }

        return {
            scopeLabel,
            periodLabel,
            statusLabel: etatLabels[status] || 'Toutes les demandes',
            benefLabel: benef ? String(benef) : 'Tous les bénéficiaires',
            numFicheLabel: numFiche ? String(numFiche) : '',
            detailFicheLabel: detailFiche ? String(detailFiche) : ''
        };
    }

    function renderFilterRecap(summary, options = {}) {
        const recap = document.getElementById('filterRecap');
        const title = document.getElementById('filterRecapTitle');
        const subtitle = document.getElementById('filterRecapSubtitle');
        const metrics = document.getElementById('filterRecapMetrics');
        if (!recap || !title || !subtitle || !metrics) return;
        if (!options.highlightRecap && recap.classList.contains('hidden')) return;

        const context = getFilterRecapContext();
        const count = Number(summary?.count || 0);
        const total = Number(summary?.total || 0);
        const cards = [{
                label: 'Fiches',
                value: count.toLocaleString('fr-FR'),
                tone: 'text-indigo-700 bg-indigo-50 border-indigo-100'
            },
            {
                label: 'Montant total',
                value: currencyFormatCFA(total),
                tone: 'text-emerald-700 bg-emerald-50 border-emerald-100'
            },
            {
                label: 'État',
                value: context.statusLabel,
                tone: 'text-amber-700 bg-amber-50 border-amber-100'
            },
            {
                label: 'Période',
                value: context.periodLabel,
                tone: 'text-slate-700 bg-slate-50 border-slate-200'
            }
        ];

        recap.classList.remove('hidden');
        title.textContent = count + ' fiche(s) trouvée(s) pour ' + context.scopeLabel;
        subtitle.textContent = context.benefLabel + ' • ' + context.statusLabel;
        metrics.innerHTML = cards.map(card => `<div class="rounded-2xl border px-4 py-3 ${card.tone}"><div class="text-[11px] font-semibold uppercase tracking-wide opacity-75">${escapeHtml(card.label)}</div><div class="mt-1 text-sm font-bold leading-snug">${escapeHtml(card.value)}</div></div>`).join('');

        recap.classList.remove('flash');
        void recap.offsetWidth;
        if (options.highlightRecap) {
            recap.classList.add('flash');
        }

        if (recapScrollTimer) {
            clearTimeout(recapScrollTimer);
            recapScrollTimer = null;
        }
        if (options.autoScroll) {
            recapScrollTimer = window.setTimeout(() => {
                document.getElementById('listingSection')?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }, 480);
        }
    }

    const exactDateInput = formGlobal.querySelector('[name="exact_date"]');
    const fromInput = formGlobal.querySelector('[name="from"]');
    const toInput = formGlobal.querySelector('[name="to"]');
    if (exactDateInput && fromInput && toInput) {
        exactDateInput.addEventListener('change', () => {
            if (exactDateInput.value) {
                fromInput.value = '';
                toInput.value = '';
            }
            updateQuickRangeButtons();
        });

        const clearExactDate = () => {
            if (fromInput.value || toInput.value) {
                exactDateInput.value = '';
            }
            updateQuickRangeButtons();
        };

        fromInput.addEventListener('change', clearExactDate);
        toInput.addEventListener('change', clearExactDate);
    }

    document.querySelectorAll('.quick-range-btn').forEach(button => {
        button.addEventListener('click', () => {
            applyQuickRangePreset(button.getAttribute('data-range-preset'));
        });
    });

    let currentLoadController = null;
    let currentLoadSeq = 0;

    async function applyFilters(options = {}) {
        const seq = ++currentLoadSeq;
        if (currentLoadController) {
            try {
                currentLoadController.abort();
            } catch (e) {}
        }
        currentLoadController = new AbortController();
        const signal = currentLoadController.signal;

        setProgress(5, 'Chargement des données...');
        try {
            setProgress(20, 'Chargement des statistiques...');
            await loadStats(signal);
            if (seq !== currentLoadSeq) return;

            setProgress(55, 'Chargement des fiches...');
            const summary = await fetchAndRender(signal);
            if (seq !== currentLoadSeq) return;

            renderFilterRecap(summary, options);

            setProgress(85, 'Mise à jour des filtres...');
            renderActiveFilters();

            setProgress(92, 'Synthèse...');
            await renderSelectionSynth(signal);

            setProgress(100, 'Terminé');
            hideProgressSoon();
        } catch (e) {
            if (e?.name === 'AbortError') return;
            notifyError(e?.message || 'Erreur réseau');
            setProgress(100, 'Erreur');
            hideProgressSoon();
        }
    }

    let selectedChantierLabel = '';
    let selectedDebourseLabel = '';

    function renderActiveFilters() {
        const box = document.getElementById('activeFilters');
        const fd = new FormData(formGlobal);
        const chips = [];
        const benef = fd.get('beneficiaire');
        if (benef) chips.push(`<span class='filter-chip'>Bénéficiaire: <strong>${escapeHtml(benef)}</strong></span>`);
        const numFiche = fd.get('num_fiche');
        if (numFiche) chips.push(`<span class='filter-chip'>N° fiche: <strong>${escapeHtml(numFiche)}</strong></span>`);
        const detailFiche = fd.get('detail_fiche');
        if (detailFiche) chips.push(`<span class='filter-chip'>Libellé/détail: <strong>${escapeHtml(detailFiche)}</strong></span>`);
        const exactDate = fd.get('exact_date');
        const from = fd.get('from');
        const to = fd.get('to');
        if (exactDate) {
            chips.push(`<span class='filter-chip'>Date précise: <strong>${escapeHtml(exactDate)}</strong></span>`);
        } else if (from || to) {
            chips.push(`<span class='filter-chip'>Période: <strong>${escapeHtml(from||'?')}</strong> → <strong>${escapeHtml(to||'?')}</strong></span>`);
        }
        const ch = fd.get('chantier');
        if (ch) chips.push(`<span class='filter-chip'>Chantier: <strong>${escapeHtml(selectedChantierLabel||ch)}</strong></span>`);
        const deb = fd.get('debourse');
        if (deb) chips.push(`<span class='filter-chip'>Déboursé: <strong>${escapeHtml(selectedDebourseLabel||('#'+deb))}</strong></span>`);
        const etat = fd.get('etat_demande');
        if (etat && etatLabels[etat]) chips.push(`<span class='filter-chip'>Etat: <strong>${escapeHtml(etatLabels[etat])}</strong></span>`);
        box.innerHTML = chips.join('');
    }

    // ---- Stats tableau de bord (utilise mêmes filtres) ----
    async function loadStats(signal) {
        const params = collectParams();
        const boxSummary = document.getElementById('statsSummary');
        const workflowOverview = document.getElementById('workflowOverview');
        const prevDiv = document.getElementById('chartPrevuSorties');
        const chantDiv = document.getElementById('chartChantier');
        const dayDiv = document.getElementById('chartDay');
        prevDiv.textContent = 'Chargement...';
        chantDiv.textContent = '';
        dayDiv.textContent = 'Chargement...';
        workflowOverview.innerHTML = '';
        try {
            const j = await fetchJson('request/point_financier_stats.php?' + params.toString(), {
                signal
            });
            if (!j?.ok) {
                prevDiv.textContent = 'Erreur stats';
                return;
            }
            boxSummary.innerHTML = `
                    <div class='bg-indigo-50 rounded p-3'><div class='text-[10px] text-indigo-600 uppercase'>Prévu Total</div><div class='font-semibold text-indigo-700 text-lg'>${currencyFormatCFA(j.prevu_total||0)}</div><div class='text-[10px] text-indigo-700 mt-1'>${j.total_fiches||0} fiche(s)</div></div>
                    <div class='bg-slate-50 rounded p-3'><div class='text-[10px] text-slate-600 uppercase'>Demandes Total</div><div class='font-semibold text-slate-700 text-lg'>${currencyFormatCFA(j.montant_demandes_total||0)}</div><div class='text-[10px] text-slate-700 mt-1'>${j.nb_fiches_demandes||0} fiche(s)</div></div>
                    <button type='button' class='top-status-card w-full text-left bg-red-50 rounded p-3 transition hover:shadow-md hover:-translate-y-0.5' data-top-status-target='refus_demandes'><div class='text-[10px] text-red-600 uppercase'>Refus Total</div><div class='font-semibold text-red-700 text-lg'>${currencyFormatCFA(j.montant_refuse||0)}</div><div class='text-[10px] text-red-700 mt-1'>${j.nb_fiches_refusees||0} fiche(s)</div><div class='text-[10px] text-red-600 mt-2 font-semibold'>Cliquer pour visualiser</div></button>
                    <button type='button' class='top-status-card w-full text-left bg-amber-50 rounded p-3 transition hover:shadow-md hover:-translate-y-0.5' data-top-status-target='attente_globale'><div class='text-[10px] text-amber-600 uppercase'>Total en attente</div><div class='font-semibold text-amber-700 text-lg'>${currencyFormatCFA(j.montant_attente||0)}</div><div class='text-[10px] text-amber-700 mt-1'>${j.nb_fiches_attente||0} fiche(s)</div><div class='text-[10px] text-amber-600 mt-2 font-semibold'>Cliquer pour visualiser</div></button>
                    <button type='button' class='top-status-card w-full text-left bg-green-50 rounded p-3 transition hover:shadow-md hover:-translate-y-0.5' data-top-status-target='decaissees'><div class='text-[10px] text-green-600 uppercase'>Total Décaissé</div><div class='font-semibold text-green-700 text-lg'>${currencyFormatCFA(j.montant_decaissements||0)}</div><div class='text-[10px] text-green-700 mt-1'>${j.nb_fiches_dec||0} fiche(s)</div><div class='text-[10px] text-green-600 mt-2 font-semibold'>Cliquer pour visualiser</div></button>`;

            boxSummary.querySelectorAll('[data-top-status-target]').forEach(button => {
                button.addEventListener('click', () => {
                    const status = button.getAttribute('data-top-status-target') || 'refus_demandes';
                    formGlobal.querySelector('[name="etat_demande"]').value = status;
                    applyFilters();
                });
            });

            const overview = j.workflow_overview || {};
            const currentStatus = formGlobal.querySelector('[name="etat_demande"]').value || 'toutes';
            const statusCards = [{
                    label: 'Total fiches',
                    value: Number(overview.total_fiches || 0),
                    percent: null,
                    tone: 'slate',
                    hint: 'Périmètre courant',
                    status: ''
                },
                {
                    label: 'Créées',
                    value: Number(overview.nb_creees || 0),
                    percent: Number(overview.pct_creees || 0),
                    tone: 'indigo',
                    hint: currencyFormatCFA(overview.montant_creees || 0),
                    status: ''
                },
                {
                    label: 'Refusées',
                    value: Number(overview.nb_refusees || 0),
                    percent: Number(overview.pct_refusees || 0),
                    tone: 'red',
                    hint: 'Cliquer pour visualiser',
                    status: 'refus_demandes'
                },
                {
                    label: 'En attente',
                    value: Number(overview.nb_en_attente || 0),
                    percent: Number(overview.pct_en_attente || 0),
                    tone: 'amber',
                    hint: 'Cliquer pour visualiser',
                    status: 'attente_globale'
                },
                {
                    label: 'Décaissées',
                    value: Number(overview.nb_decaissees || 0),
                    percent: Number(overview.pct_decaissees || 0),
                    tone: 'green',
                    hint: '',
                    status: 'decaissees'
                }
            ];
            const toneClasses = {
                slate: 'border-slate-200 bg-slate-50 text-slate-700',
                indigo: 'border-indigo-200 bg-indigo-50 text-indigo-700',
                red: 'border-red-200 bg-red-50 text-red-700',
                amber: 'border-amber-200 bg-amber-50 text-amber-700',
                green: 'border-green-200 bg-green-50 text-green-700'
            };
            workflowOverview.innerHTML = statusCards.map(card => {
                const pctText = card.percent === null ? '' : `<div class='text-xs mt-2 opacity-80'>${card.percent}% du total</div>`;
                const isActive = card.status !== '' && card.status === currentStatus;
                const activeClasses = isActive ? 'ring-2 ring-offset-2 ring-indigo-500 shadow-xl scale-[1.02]' : '';
                const activeTag = isActive ? `<div class='inline-flex items-center gap-1 rounded-full bg-indigo-600 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-white'>Actif</div>` : '';
                const disabledClasses = card.status === '' ? 'cursor-default' : '';
                return `<button type='button' class='text-left rounded-2xl border p-4 transition hover:-translate-y-0.5 hover:shadow-md ${toneClasses[card.tone] || toneClasses.slate} ${activeClasses} ${disabledClasses}' data-status-target='${card.status}'>
                    <div class='flex items-start justify-between gap-3'>
                        <div class='text-[11px] uppercase tracking-wide font-semibold opacity-80'>${escapeHtml(card.label)}</div>
                        ${activeTag}
                    </div>
                    <div class='mt-2 text-3xl font-bold'>${Number(card.value || 0).toLocaleString('fr-FR')}</div>
                    ${pctText}
                    <div class='text-xs mt-2 opacity-80'>${escapeHtml(card.hint)}</div>
                </button>`;
            }).join('');
            workflowOverview.querySelectorAll('[data-status-target]').forEach(button => {
                button.addEventListener('click', () => {
                    const status = button.getAttribute('data-status-target') || 'toutes';
                    if (!status) return;
                    formGlobal.querySelector('[name="etat_demande"]').value = status;
                    applyFilters();
                });
            });

            if (j.empty) {
                prevDiv.innerHTML = '<div class="text-xs text-gray-400">Aucune donnée sur la période / filtres</div>';
                chantDiv.innerHTML = '<div class="text-xs text-gray-400">Aucune donnée</div>';
                dayDiv.innerHTML = '<div class="text-xs text-gray-400">Aucune donnée</div>';
                return;
            }
            // Bars Prévu / Sorties améliorées
            const prevu = j.prevu_total || 0;
            const sorties = j.sorties_total || 0;
            const max = Math.max(prevu, sorties, 1);
            const prevuPercent = (prevu / max) * 100;
            const sortiesPercent = (sorties / max) * 100;
            prevDiv.innerHTML = `
                <div class='relative h-full flex items-end justify-center gap-8 p-4'>
                    <!-- Grille de fond -->
                    <div class='absolute inset-0 flex flex-col justify-between py-4'>
                        ${[100, 75, 50, 25, 0].map(p => `<div class='w-full border-t border-gray-200' style='bottom: ${p}%'></div>`).join('')}
                    </div>

                    <!-- Barre Prévu -->
                    <div class='relative flex flex-col items-center group'>
                        <div class='relative'>
                            <div class='w-16 bg-gradient-to-t from-indigo-400 to-indigo-500 rounded-t-lg shadow-lg transition-all duration-500 ease-out group-hover:scale-105'
                                 style='height: ${prevuPercent * 0.8}%'>
                            </div>
                            <div class='absolute -top-8 left-1/2 transform -translate-x-1/2 bg-indigo-600 text-white text-xs px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap'>
                                ${currencyFormatCFA(prevu)}
                            </div>
                        </div>
                        <div class='mt-3 text-center'>
                            <div class='text-sm font-semibold text-indigo-700'>Prévu</div>
                            <div class='text-xs text-gray-500'>${currencyFormatCFA(prevu)}</div>
                        </div>
                    </div>

                    <!-- Barre Sorties -->
                    <div class='relative flex flex-col items-center group'>
                        <div class='relative'>
                            <div class='w-16 bg-gradient-to-t from-green-400 to-green-500 rounded-t-lg shadow-lg transition-all duration-500 ease-out group-hover:scale-105'
                                 style='height: ${sortiesPercent * 0.8}%'>
                            </div>
                            <div class='absolute -top-8 left-1/2 transform -translate-x-1/2 bg-green-600 text-white text-xs px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap'>
                                ${currencyFormatCFA(sorties)}
                            </div>
                        </div>
                        <div class='mt-3 text-center'>
                            <div class='text-sm font-semibold text-green-700'>Sorties</div>
                            <div class='text-xs text-gray-500'>${currencyFormatCFA(sorties)}</div>
                        </div>
                    </div>

                    <!-- Indicateur de différence -->
                    <div class='absolute top-4 right-4 text-xs'>
                        ${prevu > sorties ?
                            `<span class='text-orange-600'>Écart: ${currencyFormatCFA(prevu - sorties)}</span>` :
                            prevu < sorties ?
                            `<span class='text-red-600'>Dépassement: ${currencyFormatCFA(sorties - prevu)}</span>` :
                            `<span class='text-green-600'>Équilibré</span>`
                        }
                    </div>
                </div>
            `;
            // Par chantier avec graphique
            const chantiers = j.par_chantier || [];
            if (chantiers.length) {
                const maxChant = Math.max(...chantiers.map(c => parseFloat(c.montant) || 0), 1);
                chantDiv.innerHTML = `
                    <div class='space-y-3'>
                        ${chantiers.map(c => `
                            <div class='flex items-center gap-3'>
                                <div class='flex-1 min-w-0'>
                                    <div class='text-xs font-medium text-gray-900 truncate'>${escapeHtml(c.code || '')} - ${escapeHtml(c.titre || '')}</div>
                                    <div class='w-full bg-gray-200 rounded-full h-2 mt-1'>
                                        <div class='bg-indigo-500 h-2 rounded-full' style='width:${((parseFloat(c.montant)||0)/maxChant)*100}%'></div>
                                    </div>
                                </div>
                                <div class='text-xs font-semibold text-gray-700 whitespace-nowrap'>${currencyFormatCFA(c.montant||0)}</div>
                            </div>
                        `).join('')}
                    </div>
                `;
            } else {
                chantDiv.innerHTML = '<div class="text-xs text-gray-400">Aucune donnée</div>';
            }
            // Évolution quotidienne améliorée
            dayDiv.innerHTML = '';
            const days = buildContinuousDailySeries(j.par_jour || [], j.interval || {});
            if (days.length) {
                const maxDay = Math.max(...days.map(d => parseFloat(d.montant) || 0), 1);

                // Conteneur principal
                const container = document.createElement('div');
                container.className = 'relative h-full flex items-end gap-1 p-4 overflow-x-auto';

                // Axe Y avec graduations
                const yAxis = document.createElement('div');
                yAxis.className = 'absolute left-0 top-0 bottom-0 w-12 flex flex-col justify-between text-xs text-gray-400 pr-2';
                yAxis.innerHTML = `
                    <div class='text-right'>${currencyFormatCFA(maxDay)}</div>
                    <div class='text-right'>${currencyFormatCFA(maxDay * 0.75)}</div>
                    <div class='text-right'>${currencyFormatCFA(maxDay * 0.5)}</div>
                    <div class='text-right'>${currencyFormatCFA(maxDay * 0.25)}</div>
                    <div class='text-right'>0</div>
                `;
                container.appendChild(yAxis);

                // Zone des barres
                const barsContainer = document.createElement('div');
                barsContainer.className = 'flex items-end gap-1 flex-1 ml-12';

                days.forEach((d, index) => {
                    const montant = parseFloat(d.montant) || 0;
                    const heightPercent = (montant / maxDay) * 100;
                    const date = parseIsoDate(d.d);
                    const dayLabel = formatDateLabel(date, {
                        day: '2-digit',
                        month: 'short',
                        year: '2-digit'
                    });
                    const fullDate = formatDateLabel(date, {
                        weekday: 'long',
                        day: '2-digit',
                        month: 'long',
                        year: 'numeric'
                    });

                    // Couleur dynamique selon le montant
                    let barColor = 'bg-green-500';
                    if (montant > maxDay * 0.8) barColor = 'bg-red-500';
                    else if (montant > maxDay * 0.6) barColor = 'bg-orange-500';
                    else if (montant > maxDay * 0.4) barColor = 'bg-yellow-500';
                    else if (montant > 0) barColor = 'bg-green-500';
                    else barColor = 'bg-gray-300';

                    const barWrapper = document.createElement('div');
                    barWrapper.className = 'flex flex-col items-center group relative flex-shrink-0';
                    barWrapper.style.width = '40px';

                    barWrapper.innerHTML = `
                        <div class='relative'>
                            <div class='w-6 ${barColor} rounded-t transition-all duration-300 group-hover:scale-110 group-hover:brightness-110 shadow-sm'
                                 style='height: ${Math.max(heightPercent * 0.8, 2)}px'>
                            </div>
                            <!-- Tooltip -->
                            <div class='absolute bottom-full left-1/2 transform -translate-x-1/2 mb-2 bg-gray-800 text-white text-xs px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity whitespace-nowrap z-10'>
                                ${fullDate}<br>
                                <strong>${currencyFormatCFA(montant)}</strong>
                                <div class='absolute top-full left-1/2 transform -translate-x-1/2 w-0 h-0 border-l-4 border-r-4 border-t-4 border-transparent border-t-gray-800'></div>
                            </div>
                        </div>
                        <div class='mt-2 text-center'>
                            <div class='text-[10px] font-medium text-gray-700 leading-tight'>${dayLabel}</div>
                        </div>
                    `;

                    barsContainer.appendChild(barWrapper);
                });

                container.appendChild(barsContainer);

                // Ligne de tendance (optionnel)
                if (days.length > 1) {
                    const trendLine = document.createElement('svg');
                    trendLine.className = 'absolute top-4 left-12 right-4 h-32 pointer-events-none opacity-20';
                    trendLine.setAttribute('viewBox', `0 0 ${days.length * 40} 128`);

                    let pathData = '';
                    days.forEach((d, i) => {
                        const montant = parseFloat(d.montant) || 0;
                        const x = i * 40 + 20;
                        const y = 128 - (montant / maxDay) * 100;
                        pathData += (i === 0 ? 'M' : 'L') + `${x} ${y} `;
                    });

                    trendLine.innerHTML = `<path d="${pathData}" stroke="#6366f1" stroke-width="2" fill="none" stroke-dasharray="5,5"></path>`;
                    container.appendChild(trendLine);
                }

                dayDiv.appendChild(container);
            } else {
                dayDiv.innerHTML = '<div class="text-xs text-gray-400 flex items-center justify-center h-full">Aucune donnée</div>';
            }
        } catch (e) {
            if (e?.name === 'AbortError') return;
            prevDiv.textContent = 'Erreur réseau';
            chantDiv.innerHTML = '<div class="text-xs text-gray-400">Erreur</div>';
            dayDiv.innerHTML = '<div class="text-xs text-gray-400">Erreur</div>';
            throw e;
        }
    }
    // ---- Select recherchables chantier / déboursé ----
    let dataChantiers = [];
    let dataDebourses = [];
    let loadedChantiers = false;
    let lastFetchedFiches = [];
    let recapScrollTimer = null;

    function buildSelect(containerId, onPick) {
        const el = document.getElementById(containerId);
        const placeholder = el.dataset.placeholder || '';
        el.classList.add('relative');
        el.innerHTML = `<button type='button' class='select-trigger'><span class='select-label'>${placeholder}</span><i class='fas fa-chevron-down select-arrow'></i></button><div class='select-dropdown hidden'><div class='select-search-wrap'><input type='text' class='select-search' placeholder='Rechercher...'></div><div class='select-options'></div><div class='select-empty hidden'>Aucun résultat</div></div>`;
        const trigger = el.querySelector('.select-trigger');
        const dropdown = el.querySelector('.select-dropdown');
        const search = el.querySelector('.select-search');
        const opts = el.querySelector('.select-options');
        const empty = el.querySelector('.select-empty');

        function close() {
            dropdown.classList.add('hidden');
        }

        function open() {
            dropdown.classList.remove('hidden');
            search.value = '';
            filter();
            search.focus();
        }
        trigger.onclick = () => dropdown.classList.contains('hidden') ? open() : close();
        document.addEventListener('click', e => {
            if (!el.contains(e.target)) close();
        });

        function render(list) {
            opts.innerHTML = list.map(o => `<button type='button' data-id='${o.id}' class='select-option'>${escapeHtml(o.label)}</button>`).join('');
            opts.querySelectorAll('.select-option').forEach(b => b.onclick = () => {
                el.querySelector('.select-label').textContent = b.textContent;
                onPick(b.getAttribute('data-id'), b.textContent);
                close();
            });
            empty.classList.toggle('hidden', !!list.length);
        }

        function filter() {
            const term = search.value.toLowerCase();
            const list = (el.id === 'selectChantier' ? dataChantiers : dataDebourses).filter(o => !term || o.label.toLowerCase().includes(term));
            render(list);
        }
        search.oninput = filter;
        el.filter = filter;
        el.render = render;
        el.reset = () => {
            el.querySelector('.select-label').textContent = placeholder;
        };
    }

    function clearSelect(name) {
        const hidden = formGlobal.querySelector(`[name="${name}"]`);
        if (hidden) hidden.value = '';
        if (name === 'chantier') {
            dataDebourses = [];
            document.getElementById('selectDebourse').classList.add('disabled');
            document.getElementById('selectDebourse').reset?.();
        }
        if (name === 'debourse') {
            document.getElementById('selectDebourse').reset?.();
        }
    }
    async function ensureChantiers() {
        if (loadedChantiers) return;
        try {
            const j = await fetchJson('request/point_financier_detail.php?lists_only=1');
            if (j.ok) {
                dataChantiers = (j.chantiers || []).map(c => ({
                    id: c.id,
                    label: `${c.code} - ${c.titre}`
                }));
                document.getElementById('selectChantier').render(dataChantiers);
                loadedChantiers = true;
            }
        } catch (e) {
            // silencieux: l'utilisateur peut quand même filtrer par dates/bénéficiaire
        }
    }
    async function loadDebourses(chId) {
        const params = new URLSearchParams({
            chantier: chId
        });
        params.set('lists_only', '1');
        try {
            const j = await fetchJson('request/point_financier_detail.php?' + params.toString());
            if (j.ok) {
                dataDebourses = (j.debourses || []).map(d => ({
                    id: d.id,
                    label: `#${d.id} - ${(d.devis_designation||'Déboursé')} (${Number(d.montant_debourse||0).toLocaleString('fr-FR')} CFA)`
                }));
                const deb = document.getElementById('selectDebourse');
                deb.classList.remove('disabled');
                deb.render(dataDebourses);
            }
        } catch (e) {
            // ignore
        }
    }

    buildSelect('selectChantier', (id, label) => {
        formGlobal.querySelector('[name="chantier"]').value = id;
        selectedChantierLabel = label;
        clearSelect('debourse');
        loadDebourses(id);
        applyFilters();
    });
    buildSelect('selectDebourse', (id, label) => {
        formGlobal.querySelector('[name="debourse"]').value = id;
        selectedDebourseLabel = label;
        applyFilters();
    });
    ensureChantiers();

    // ---- Export CSV (listing courant) ----
    function exportCsv(rows) {
        if (!rows.length) {
            if (typeof showToast === 'function') showToast('Aucune fiche', 'warning');
            return;
        }
        const headers = ['num_fiche', 'etat', 'beneficiaire', 'montant', 'date_reference', 'date_demande', 'designation_fiche', 'precision_fiche', 'details_demande', 'motif_refus'];
        const csv = [headers.join(';')].concat(rows.map(r => [
            r.num_fiche,
            (r.status || '').replace(/;/g, ','),
            (r.beficiaire_fiche || '').replace(/;/g, ','),
            r.montant_fiche,
            r.reference_date || '',
            r.date_creat_fiche || '',
            (r.designation || r.designation_fiche || '').replace(/;/g, ','),
            (r.precision || r.precision_fiche || '').replace(/;/g, ','),
            (r.details_demande || '').replace(/;/g, ','),
            (r.motif_refus || '').replace(/;/g, ',')
        ].join(';'))).join('\n');
        const blob = new Blob([csv], {
            type: 'text/csv;charset=utf-8;'
        });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'point_financier.csv';
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 300);
    }

    function exportPdf() {
        const params = collectParams();
        window.open('request/export_point_financier_pdf.php?' + params.toString(), '_blank');
    }

    // ---- Synthèse sélection (rapide) ----
    async function renderSelectionSynth(signal) {
        const fd = new FormData(formGlobal);
        const ch = fd.get('chantier');
        const deb = fd.get('debourse');
        const box = document.getElementById('selectionSynth');
        if (!ch && !deb) {
            box.classList.add('hidden');
            box.innerHTML = '';
            return;
        }
        const params = new URLSearchParams();
        if (ch) params.set('chantier', ch);
        if (deb) params.set('debourse', deb);
        if (fd.get('exact_date')) params.set('exact_date', fd.get('exact_date'));
        if (fd.get('from')) params.set('from', fd.get('from'));
        if (fd.get('to')) params.set('to', fd.get('to'));
        if (fd.get('etat_demande')) params.set('etat_demande', fd.get('etat_demande'));
        if (fd.get('beneficiaire')) params.set('beneficiaire', fd.get('beneficiaire'));
        if (fd.get('num_fiche')) params.set('num_fiche', fd.get('num_fiche'));
        if (fd.get('detail_fiche')) params.set('detail_fiche', fd.get('detail_fiche'));
        try {
            const j = await fetchJson('request/point_financier_detail.php?' + params.toString(), {
                signal
            });
            if (!j.ok) {
                box.classList.add('hidden');
                return;
            }
            const s = j.synthese || {};
            const att = j.attente || {};
            const ratio = j.ratio_debourse;
            const labelScope = deb ? ('Déboursé ' + (selectedDebourseLabel || ('#' + deb))) : ('Chantier ' + (selectedChantierLabel || ch));
            const statusLabel = j.status_label || etatLabels[fd.get('etat_demande')] || 'Toutes les demandes';
            box.classList.remove('hidden');
            box.innerHTML = `<div class='grid md:grid-cols-5 gap-4 bg-gradient-to-r from-indigo-50 via-white to-indigo-50 rounded-xl p-4 border border-indigo-100'>
                    <div class='col-span-2'><div class='text-[11px] uppercase text-indigo-600 font-semibold'>Périmètre</div><div class='font-bold text-indigo-700 text-sm mt-1'>${escapeHtml(labelScope)}</div><div class='text-[11px] text-gray-500 mt-1'>${escapeHtml(statusLabel)} | ${(s.debut||'-')} → ${(s.fin||'-')}</div></div>
               <div><div class='mini-metric'>${escapeHtml(statusLabel)}</div><div class='mini-val text-green-600'>${currencyFormatCFA(s.total||0)}</div></div>
               <div><div class='mini-metric'>À décaisser</div><div class='mini-val text-amber-600'>${currencyFormatCFA(att.montant_attente||0)}</div></div>
               <div><div class='mini-metric'>Fiches</div><div class='mini-val text-indigo-700'>${s.nb_fiches||0}</div>${ratio!==null?`<div class='text-[10px] text-gray-500 mt-1'>Prog: ${ratio}%</div>`:''}</div>
            </div>`;
        } catch (e) {
            if (e?.name === 'AbortError') return;
            box.classList.add('hidden');
        }
    }

    // Initial
    document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('pfDetailModal');
        const closeButton = document.querySelector('[data-pf-modal-close]');

        if (closeButton) {
            closeButton.addEventListener('click', closeDetailModal);
        }

        if (modal) {
            modal.addEventListener('click', event => {
                if (event.target === modal) {
                    closeDetailModal();
                }
            });
        }

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                closeDetailModal();
            }
        });

        updateQuickRangeButtons();
        const calendarPrev = document.getElementById('pfCalendarPrev');
        const calendarNext = document.getElementById('pfCalendarNext');
        if (calendarPrev) {
            calendarPrev.addEventListener('click', () => {
                if (!pfCalendarMonth) pfCalendarMonth = getCalendarInitialMonth();
                pfCalendarMonth = new Date(pfCalendarMonth.getFullYear(), pfCalendarMonth.getMonth() - 1, 1);
                pfCalendarSelectedDate = '';
                renderDisbursementCalendar();
            });
        }
        if (calendarNext) {
            calendarNext.addEventListener('click', () => {
                if (!pfCalendarMonth) pfCalendarMonth = getCalendarInitialMonth();
                pfCalendarMonth = new Date(pfCalendarMonth.getFullYear(), pfCalendarMonth.getMonth() + 1, 1);
                pfCalendarSelectedDate = '';
                renderDisbursementCalendar();
            });
        }
        applyFilters();
        if (isDemo && typeof showToast === 'function') showToast('Mode démo: Point financier', 'info');
    });
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
