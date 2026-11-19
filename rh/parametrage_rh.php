<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhAttendanceController.php';
require_once __DIR__ . '/../controllers/RhExplanationController.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';

$pdo = \Database::getConnection();
$directory = (new \RhPersonnelController($pdo))->service();
$rhExplanationManager = (new \RhExplanationController($pdo))->service();
$rhAttendanceController = new \RhAttendanceController($pdo);
$absenceNotifier = $rhAttendanceController->absenceNotifier();


function rhEnsureAttendanceSettingsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_attendance_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(80) NOT NULL,
        setting_value VARCHAR(120) NOT NULL,
        setting_label VARCHAR(190) DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_rh_attendance_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function rhAttendanceDefaultSettings(): array
{
    return [
        'daily_work_target_hours' => ['value' => '10.00', 'label' => 'Volume journalier cible en heures'],
        'late_reference_time' => ['value' => '08:40', 'label' => 'Heure normale d arrivee'],
        'arrival_tolerance_minutes' => ['value' => '10', 'label' => 'Tolerance arrivee en minutes'],
        'departure_tolerance_minutes' => ['value' => '10', 'label' => 'Tolerance depart/deficit en minutes'],
        'overtime_tolerance_minutes' => ['value' => '15', 'label' => 'Tolerance heures supplementaires en minutes'],
    ];
}

function rhGetAttendanceSettings(PDO $pdo): array
{
    rhEnsureAttendanceSettingsTable($pdo);
    $defaults = rhAttendanceDefaultSettings();

    $stmt = $pdo->query('SELECT setting_key, setting_value FROM rh_attendance_settings');
    $saved = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $saved[(string) $row['setting_key']] = (string) $row['setting_value'];
    }

    $settings = [];
    foreach ($defaults as $key => $meta) {
        $settings[$key] = $saved[$key] ?? $meta['value'];
    }

    return $settings;
}

function rhSaveAttendanceSettings(PDO $pdo, array $data, ?int $userId = null): void
{
    rhEnsureAttendanceSettingsTable($pdo);
    $defaults = rhAttendanceDefaultSettings();

    $dailyHours = max(1, min(24, (float) str_replace(',', '.', (string) ($data['daily_work_target_hours'] ?? $defaults['daily_work_target_hours']['value']))));
    $lateRef = trim((string) ($data['late_reference_time'] ?? $defaults['late_reference_time']['value']));
    if (!preg_match('/^\d{2}:\d{2}$/', $lateRef)) {
        $lateRef = $defaults['late_reference_time']['value'];
    }

    $values = [
        'daily_work_target_hours' => number_format($dailyHours, 2, '.', ''),
        'late_reference_time' => $lateRef,
        'arrival_tolerance_minutes' => (string) max(0, min(240, (int) ($data['arrival_tolerance_minutes'] ?? $defaults['arrival_tolerance_minutes']['value']))),
        'departure_tolerance_minutes' => (string) max(0, min(240, (int) ($data['departure_tolerance_minutes'] ?? $defaults['departure_tolerance_minutes']['value']))),
        'overtime_tolerance_minutes' => (string) max(0, min(240, (int) ($data['overtime_tolerance_minutes'] ?? $defaults['overtime_tolerance_minutes']['value']))),
    ];

    $stmt = $pdo->prepare('INSERT INTO rh_attendance_settings (setting_key, setting_value, setting_label, updated_by)
        VALUES (:setting_key, :setting_value, :setting_label, :updated_by)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), setting_label = VALUES(setting_label), updated_by = VALUES(updated_by), updated_at = NOW()');

    foreach ($values as $key => $value) {
        $stmt->execute([
            'setting_key' => $key,
            'setting_value' => $value,
            'setting_label' => $defaults[$key]['label'] ?? $key,
            'updated_by' => $userId,
        ]);
    }
}

$activeTab = trim((string) ($_GET['tab'] ?? 'services'));
if (!in_array($activeTab, ['services', 'fonctions', 'statuts', 'sites', 'discipline', 'pointage'], true)) {
    $activeTab = 'services';
}

$editId = max(0, (int) ($_GET['edit'] ?? 0));
$disciplineFaultEditId = max(0, (int) ($_GET['fault_edit'] ?? 0));
$disciplineSanctionEditId = max(0, (int) ($_GET['sanction_edit'] ?? 0));

$statusType = null;
$statusMessage = null;

$logRhSettingEvent = static function (string $eventType, string $action, string $entityType, ?string $entityId, ?string $entityLabel, array $details = []) use ($pdo): void {
    \AuditTrail::log($eventType, [
        'module' => 'rh',
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'entity_label' => $entityLabel,
        'details' => $details,
    ], $pdo);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = trim((string) ($_POST['section'] ?? ''));
    $action  = trim((string) ($_POST['action'] ?? ''));
    $tabMap  = ['service' => 'services', 'fonction' => 'fonctions', 'statut' => 'statuts', 'site' => 'sites', 'fault_level' => 'discipline', 'sanction_level' => 'discipline', 'pointage_exemption' => 'pointage', 'attendance_tolerance' => 'pointage'];
    $redirectTab = $tabMap[$section] ?? 'services';

    try {
        if ($section === 'service') {
            if ($action === 'create') {
                $label = trim((string) ($_POST['label'] ?? ''));
                $newId = $directory->createService($label);
                $logRhSettingEvent('rh_setting_created', 'parametrage_create', 'service', (string) $newId, $label, [
                    'section' => 'service',
                ]);
                header('Location: parametrage_rh.php?tab=services&saved=1');
            } elseif ($action === 'update') {
                $serviceId = (int) ($_POST['id'] ?? 0);
                $label = trim((string) ($_POST['label'] ?? ''));
                $directory->updateService($serviceId, $label);
                $logRhSettingEvent('rh_setting_updated', 'parametrage_update', 'service', (string) $serviceId, $label, [
                    'section' => 'service',
                ]);
                header('Location: parametrage_rh.php?tab=services&updated=1');
            } elseif ($action === 'delete') {
                $serviceId = (int) ($_POST['id'] ?? 0);
                $deleted = $directory->deleteService($serviceId);
                if ($deleted) {
                    $logRhSettingEvent('rh_setting_deleted', 'parametrage_delete', 'service', (string) $serviceId, null, [
                        'section' => 'service',
                    ]);
                }
                header('Location: parametrage_rh.php?tab=services&deleted=1');
            }
            exit;
        }

        if ($section === 'fonction') {
            if ($action === 'create') {
                $label = trim((string) ($_POST['label'] ?? ''));
                $newId = $directory->createFonction($label);
                $logRhSettingEvent('rh_setting_created', 'parametrage_create', 'fonction', (string) $newId, $label, [
                    'section' => 'fonction',
                ]);
                header('Location: parametrage_rh.php?tab=fonctions&saved=1');
            } elseif ($action === 'update') {
                $fonctionId = (int) ($_POST['id'] ?? 0);
                $label = trim((string) ($_POST['label'] ?? ''));
                $directory->updateFonction($fonctionId, $label);
                $logRhSettingEvent('rh_setting_updated', 'parametrage_update', 'fonction', (string) $fonctionId, $label, [
                    'section' => 'fonction',
                ]);
                header('Location: parametrage_rh.php?tab=fonctions&updated=1');
            } elseif ($action === 'delete') {
                $fonctionId = (int) ($_POST['id'] ?? 0);
                $deleted = $directory->deleteFonction($fonctionId);
                if ($deleted) {
                    $logRhSettingEvent('rh_setting_deleted', 'parametrage_delete', 'fonction', (string) $fonctionId, null, [
                        'section' => 'fonction',
                    ]);
                }
                header('Location: parametrage_rh.php?tab=fonctions&deleted=1');
            }
            exit;
        }

        if ($section === 'statut') {
            if ($action === 'create') {
                $code = (int) ($_POST['code'] ?? 0);
                $label = trim((string) ($_POST['label'] ?? ''));
                $abbreviation = trim((string) ($_POST['abbreviation'] ?? ''));
                $newId = $directory->createRhStatut(
                    $code,
                    $label,
                    !empty($_POST['is_active']),
                    (int) ($_POST['sort_order'] ?? 0),
                    $abbreviation
                );
                $logRhSettingEvent('rh_setting_created', 'parametrage_create', 'statut_rh', (string) $newId, $label, [
                    'section' => 'statut',
                    'code' => $code,
                    'abbreviation' => $abbreviation,
                    'is_active' => !empty($_POST['is_active']),
                    'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ]);
                header('Location: parametrage_rh.php?tab=statuts&saved=1');
            } elseif ($action === 'update') {
                $statutId = (int) ($_POST['id'] ?? 0);
                $code = (int) ($_POST['code'] ?? 0);
                $label = trim((string) ($_POST['label'] ?? ''));
                $abbreviation = trim((string) ($_POST['abbreviation'] ?? ''));
                $directory->updateRhStatut(
                    $statutId,
                    $code,
                    $label,
                    !empty($_POST['is_active']),
                    (int) ($_POST['sort_order'] ?? 0),
                    $abbreviation
                );
                $logRhSettingEvent('rh_setting_updated', 'parametrage_update', 'statut_rh', (string) $statutId, $label, [
                    'section' => 'statut',
                    'code' => $code,
                    'abbreviation' => $abbreviation,
                    'is_active' => !empty($_POST['is_active']),
                    'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ]);
                header('Location: parametrage_rh.php?tab=statuts&updated=1');
            } elseif ($action === 'delete') {
                $statutId = (int) ($_POST['id'] ?? 0);
                $deleted = $directory->deleteRhStatut($statutId);
                if ($deleted) {
                    $logRhSettingEvent('rh_setting_deleted', 'parametrage_delete', 'statut_rh', (string) $statutId, null, [
                        'section' => 'statut',
                    ]);
                }
                header('Location: parametrage_rh.php?tab=statuts&deleted=1');
            }
            exit;
        }

        if ($section === 'site') {
            if ($action === 'create') {
                $code = (int) ($_POST['code'] ?? 0);
                $label = trim((string) ($_POST['label'] ?? ''));
                $newId = $directory->createRhSite(
                    $code,
                    $label,
                    !empty($_POST['is_active']),
                    (int) ($_POST['sort_order'] ?? 0)
                );
                $logRhSettingEvent('rh_setting_created', 'parametrage_create', 'site_rh', (string) $newId, $label, [
                    'section' => 'site',
                    'code' => $code,
                    'is_active' => !empty($_POST['is_active']),
                    'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ]);
                header('Location: parametrage_rh.php?tab=sites&saved=1');
            } elseif ($action === 'update') {
                $siteId = (int) ($_POST['id'] ?? 0);
                $code = (int) ($_POST['code'] ?? 0);
                $label = trim((string) ($_POST['label'] ?? ''));
                $directory->updateRhSite(
                    $siteId,
                    $code,
                    $label,
                    !empty($_POST['is_active']),
                    (int) ($_POST['sort_order'] ?? 0)
                );
                $logRhSettingEvent('rh_setting_updated', 'parametrage_update', 'site_rh', (string) $siteId, $label, [
                    'section' => 'site',
                    'code' => $code,
                    'is_active' => !empty($_POST['is_active']),
                    'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ]);
                header('Location: parametrage_rh.php?tab=sites&updated=1');
            } elseif ($action === 'delete') {
                $siteId = (int) ($_POST['id'] ?? 0);
                $deleted = $directory->deleteRhSite($siteId);
                if ($deleted) {
                    $logRhSettingEvent('rh_setting_deleted', 'parametrage_delete', 'site_rh', (string) $siteId, null, [
                        'section' => 'site',
                    ]);
                }
                header('Location: parametrage_rh.php?tab=sites&deleted=1');
            }
            exit;
        }

        if ($section === 'fault_level') {
            if ($action === 'create' || $action === 'update') {
                $faultLevelId = $action === 'update' ? (int) ($_POST['id'] ?? 0) : 0;
                $savedId = $rhExplanationManager->saveFaultLevel($faultLevelId, [
                    'code' => (string) ($_POST['code'] ?? ''),
                    'label' => (string) ($_POST['label'] ?? ''),
                    'severity_rank' => (int) ($_POST['severity_rank'] ?? 1),
                    'description' => (string) ($_POST['description'] ?? ''),
                    'default_sanction_level_id' => (int) ($_POST['default_sanction_level_id'] ?? 0),
                    'is_active' => !empty($_POST['is_active']),
                    'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ]);
                $logRhSettingEvent($action === 'create' ? 'rh_setting_created' : 'rh_setting_updated', 'parametrage_' . $action, 'fault_level', (string) $savedId, (string) ($_POST['label'] ?? ''), [
                    'section' => 'fault_level',
                    'severity_rank' => (int) ($_POST['severity_rank'] ?? 1),
                    'default_sanction_level_id' => (int) ($_POST['default_sanction_level_id'] ?? 0),
                ]);
                header('Location: parametrage_rh.php?tab=discipline&' . ($action === 'create' ? 'saved=1' : 'updated=1'));
            } elseif ($action === 'delete') {
                $faultLevelId = (int) ($_POST['id'] ?? 0);
                $deleted = $rhExplanationManager->deleteFaultLevel($faultLevelId);
                if ($deleted) {
                    $logRhSettingEvent('rh_setting_deleted', 'parametrage_delete', 'fault_level', (string) $faultLevelId, null, [
                        'section' => 'fault_level',
                    ]);
                }
                header('Location: parametrage_rh.php?tab=discipline&deleted=1');
            }
            exit;
        }

        if ($section === 'sanction_level') {
            if ($action === 'create' || $action === 'update') {
                $sanctionLevelId = $action === 'update' ? (int) ($_POST['id'] ?? 0) : 0;
                $savedId = $rhExplanationManager->saveSanctionLevel($sanctionLevelId, [
                    'code' => (string) ($_POST['code'] ?? ''),
                    'label' => (string) ($_POST['label'] ?? ''),
                    'severity_rank' => (int) ($_POST['severity_rank'] ?? 1),
                    'description' => (string) ($_POST['description'] ?? ''),
                    'category' => (string) ($_POST['category'] ?? 'disciplinary'),
                    'disables_platform_access' => !empty($_POST['disables_platform_access']),
                    'is_active' => !empty($_POST['is_active']),
                    'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ]);
                $logRhSettingEvent($action === 'create' ? 'rh_setting_created' : 'rh_setting_updated', 'parametrage_' . $action, 'sanction_level', (string) $savedId, (string) ($_POST['label'] ?? ''), [
                    'section' => 'sanction_level',
                    'severity_rank' => (int) ($_POST['severity_rank'] ?? 1),
                    'category' => (string) ($_POST['category'] ?? 'disciplinary'),
                    'disables_platform_access' => !empty($_POST['disables_platform_access']),
                ]);
                header('Location: parametrage_rh.php?tab=discipline&' . ($action === 'create' ? 'saved=1' : 'updated=1'));
            } elseif ($action === 'delete') {
                $sanctionLevelId = (int) ($_POST['id'] ?? 0);
                $deleted = $rhExplanationManager->deleteSanctionLevel($sanctionLevelId);
                if ($deleted) {
                    $logRhSettingEvent('rh_setting_deleted', 'parametrage_delete', 'sanction_level', (string) $sanctionLevelId, null, [
                        'section' => 'sanction_level',
                    ]);
                }
                header('Location: parametrage_rh.php?tab=discipline&deleted=1');
            }
            exit;
        }

        if ($section === 'attendance_tolerance') {
            rhSaveAttendanceSettings($pdo, $_POST, (int) ($_SESSION['user_id'] ?? 0));
            $logRhSettingEvent('rh_attendance_tolerance_updated', 'parametrage_update', 'attendance_tolerance', null, 'Regles de tolerance pointage', [
                'daily_work_target_hours' => (string) ($_POST['daily_work_target_hours'] ?? ''),
                'late_reference_time' => (string) ($_POST['late_reference_time'] ?? ''),
                'arrival_tolerance_minutes' => (int) ($_POST['arrival_tolerance_minutes'] ?? 0),
                'departure_tolerance_minutes' => (int) ($_POST['departure_tolerance_minutes'] ?? 0),
                'overtime_tolerance_minutes' => (int) ($_POST['overtime_tolerance_minutes'] ?? 0),
            ]);
            header('Location: parametrage_rh.php?tab=pointage&updated=1');
            exit;
        }

        if ($section === 'pointage_exemption') {
            $personnelId = (int) ($_POST['personnel_id'] ?? 0);
            if ($action === 'create') {
                $reason = trim((string) ($_POST['reason'] ?? ''));
                $absenceNotifier->addPointageExemption($personnelId, $reason, [
                    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                    'name' => (string) ($_SESSION['user_name'] ?? ''),
                ]);
                $logRhSettingEvent('rh_pointage_exemption_created', 'parametrage_create', 'pointage_exemption', (string) $personnelId, null, [
                    'reason' => $reason,
                ]);
                header('Location: parametrage_rh.php?tab=pointage&saved=1');
            } elseif ($action === 'delete') {
                $absenceNotifier->removePointageExemption($personnelId);
                $logRhSettingEvent('rh_pointage_exemption_deleted', 'parametrage_delete', 'pointage_exemption', (string) $personnelId, null);
                header('Location: parametrage_rh.php?tab=pointage&deleted=1');
            }
            exit;
        }

        throw new RuntimeException('Section non reconnue.');
    } catch (Throwable $e) {
        \AuditTrail::log('rh_setting_failed', [
            'module' => 'rh',
            'action' => 'parametrage_' . ($action !== '' ? $action : 'unknown'),
            'entity_type' => $section !== '' ? $section : 'parametrage_rh',
            'result' => 'failed',
            'severity' => 'error',
            'details' => [
                'message' => $e->getMessage(),
                'section' => $section,
                'redirect_tab' => $redirectTab,
            ],
        ], $pdo);
        $statusType = 'error';
        $statusMessage = $e->getMessage();
    }
}

if ($statusMessage === null) {
    if (isset($_GET['saved'])) {
        $statusType = 'success';
        $statusMessage = 'Entrée ajoutée avec succès.';
    } elseif (isset($_GET['updated'])) {
        $statusType = 'success';
        $statusMessage = 'Entrée mise à jour avec succès.';
    } elseif (isset($_GET['deleted'])) {
        $statusType = 'success';
        $statusMessage = 'Entrée supprimée.';
    }
}

$services  = $directory->getServices();
$fonctions = $directory->getFonctionsList();
$statuts   = $directory->getRhStatuts(true);
$sites     = $directory->getRhSites(true);
$faultLevels = $rhExplanationManager->listFaultLevels();
$sanctionLevels = $rhExplanationManager->listSanctionLevels();
$faultLevelEdit = $disciplineFaultEditId > 0 ? $rhExplanationManager->getFaultLevelById($disciplineFaultEditId) : null;
$sanctionLevelEdit = $disciplineSanctionEditId > 0 ? $rhExplanationManager->getSanctionLevelById($disciplineSanctionEditId) : null;
$pointageExemptions = $absenceNotifier->listPointageExemptions();
$pointagePersonnel = $rhAttendanceController->listPointageEligiblePersonnel();
$attendanceSettings = rhGetAttendanceSettings($pdo);

/* ─── helpers locaux ──────────────────────────────────────────────────────── */
function tabUrl(string $tab, array $extra = []): string
{
    return 'parametrage_rh.php?' . http_build_query(array_merge(['tab' => $tab], $extra));
}

function tabClass(string $tab, string $active): string
{
    return $tab === $active
        ? 'rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-slate-950 shadow-sm'
        : 'rounded-xl px-5 py-2.5 text-sm font-semibold text-slate-500 transition hover:text-slate-700 hover:bg-white/60';
}

$pageTitle  = 'Paramétrage RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(241,245,249,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(199,210,254,0.75),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">

        <!-- En-tête ─────────────────────────────────────────────────────────── -->
        <div class="relative overflow-hidden rounded-[32px] border border-indigo-100 bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <div class="absolute bottom-0 right-20 h-24 w-24 rounded-full bg-indigo-300/20 blur-2xl"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-indigo-200">Ressources humaines</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Paramétrage RH</h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">
                        Administrez l'ensemble des référentiels RH : services, fonctions, types de contrat, sites géographiques, motifs de sortie et règles de paie.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="parametrage_motifs_sortie_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-door-open mr-2"></i>
                        Motifs de sortie
                    </a>
                    <a href="parametrage_paie_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-sliders-h mr-2"></i>
                        Règles contrats
                    </a>
                    <a href="parametrage_contrat_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-signature mr-2"></i>
                        Signataires
                    </a>
                    <a href="parametrage_hierarchie_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-sitemap mr-2"></i>
                        Hiérarchie explications
                    </a>
                    <a href="parametrage_horaires_emploi_du_temps.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        Horaires planning
                    </a>
                    <a href="feries_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-calendar-times mr-2"></i>
                        Jours fériés
                    </a>
                    <a href="dashboard_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-tachometer-alt mr-2"></i>
                        Tableau de bord RH
                    </a>
                </div>
            </div>
        </div>

        <?php if ($statusType !== null && $statusMessage !== null): ?>
            <div class="mt-6 rounded-2xl border px-5 py-4 text-sm font-medium <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                <i class="fas <?= $statusType === 'success' ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-circle text-rose-500' ?> mr-2"></i>
                <?= htmlspecialchars((string) $statusMessage) ?>
            </div>
        <?php endif; ?>

        <!-- Onglets ──────────────────────────────────────────────────────────── -->
        <div class="mt-8 flex flex-wrap gap-1 rounded-2xl bg-slate-100 p-1.5">
            <a href="<?= tabUrl('services') ?>" class="<?= tabClass('services', $activeTab) ?>">
                <i class="fas fa-sitemap mr-2 text-xs"></i>Services
                <span class="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600"><?= count($services) ?></span>
            </a>
            <a href="<?= tabUrl('fonctions') ?>" class="<?= tabClass('fonctions', $activeTab) ?>">
                <i class="fas fa-briefcase mr-2 text-xs"></i>Fonctions
                <span class="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600"><?= count($fonctions) ?></span>
            </a>
            <a href="<?= tabUrl('statuts') ?>" class="<?= tabClass('statuts', $activeTab) ?>">
                <i class="fas fa-id-card mr-2 text-xs"></i>Types de contrat
                <span class="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600"><?= count($statuts) ?></span>
            </a>
            <a href="<?= tabUrl('sites') ?>" class="<?= tabClass('sites', $activeTab) ?>">
                <i class="fas fa-map-marker-alt mr-2 text-xs"></i>Sites
                <span class="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600"><?= count($sites) ?></span>
            </a>
            <a href="<?= tabUrl('discipline') ?>" class="<?= tabClass('discipline', $activeTab) ?>">
                <i class="fas fa-balance-scale mr-2 text-xs"></i>Discipline
                <span class="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600"><?= count($faultLevels) + count($sanctionLevels) ?></span>
            </a>
            <a href="<?= tabUrl('pointage') ?>" class="<?= tabClass('pointage', $activeTab) ?>">
                <i class="fas fa-user-clock mr-2 text-xs"></i>Pointage
                <span class="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600"><?= count($pointageExemptions) ?></span>
            </a>
        </div>

        <!-- Contenu ─────────────────────────────────────────────────────────── -->
        <div class="mt-6">

            <?php /* ══════════════ SERVICES ══════════════ */ if ($activeTab === 'services'): ?>
                <div class="grid gap-6 xl:grid-cols-[1fr_380px]">

                    <!-- Liste -->
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                        <div class="mb-6 flex items-center justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Référentiel</p>
                                <h2 class="mt-1 text-xl font-semibold text-slate-950">Services (<?= count($services) ?>)</h2>
                                <p class="mt-1 text-sm text-slate-500">Unités organisationnelles affectées aux collaborateurs.</p>
                            </div>
                        </div>

                        <?php if (empty($services)): ?>
                            <div class="rounded-2xl bg-slate-50 py-10 text-center text-sm text-slate-400">
                                <i class="fas fa-inbox mb-2 text-2xl block"></i>
                                Aucun service configuré. Ajoutez-en un à droite.
                            </div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($services as $svc): ?>
                                    <?php $isEdit = $editId === (int) $svc['id'] && $activeTab === 'services'; ?>
                                    <?php if ($isEdit): ?>
                                        <form method="post" class="flex items-center gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3">
                                            <input type="hidden" name="section" value="service">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int) $svc['id'] ?>">
                                            <input type="text" name="label" value="<?= htmlspecialchars((string) $svc['label']) ?>" required autofocus
                                                class="flex-1 rounded-xl border border-indigo-300 bg-white px-4 py-2.5 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                            <button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">Enregistrer</button>
                                            <a href="<?= tabUrl('services') ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler</a>
                                        </form>
                                    <?php else: ?>
                                        <div class="flex items-center justify-between rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 hover:border-slate-200 hover:bg-white transition">
                                            <div class="flex items-center gap-3">
                                                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600 text-xs font-bold"><?= (int) $svc['id'] ?></div>
                                                <span class="font-medium text-slate-800"><?= htmlspecialchars((string) $svc['label']) ?></span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <a href="<?= tabUrl('services', ['edit' => $svc['id']]) ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                                                    <i class="fas fa-pen mr-1"></i>Modifier
                                                </a>
                                                <form method="post" onsubmit="return confirm('Supprimer le service « <?= htmlspecialchars(addslashes((string) $svc['label'])) ?> » ?');" class="inline">
                                                    <input type="hidden" name="section" value="service">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $svc['id'] ?>">
                                                    <button type="submit" class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100">
                                                        <i class="fas fa-trash-alt mr-1"></i>Supprimer
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Formulaire ajout -->
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur self-start">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Nouveau</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Ajouter un service</h2>
                        <form method="post" class="mt-6 space-y-4">
                            <input type="hidden" name="section" value="service">
                            <input type="hidden" name="action" value="create">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Nom du service <span class="text-rose-500">*</span></label>
                                <input type="text" name="label" required placeholder="Ex : Direction Technique"
                                    class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                            </div>
                            <button type="submit" class="w-full rounded-2xl bg-indigo-600 py-3 text-sm font-semibold text-white hover:bg-indigo-700">
                                <i class="fas fa-plus mr-2"></i>Créer le service
                            </button>
                        </form>
                    </div>
                </div>

            <?php /* ══════════════ FONCTIONS ══════════════ */ elseif ($activeTab === 'fonctions'): ?>
                <div class="grid gap-6 xl:grid-cols-[1fr_380px]">

                    <!-- Liste -->
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                        <div class="mb-6">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-violet-600">Référentiel</p>
                            <h2 class="mt-1 text-xl font-semibold text-slate-950">Fonctions (<?= count($fonctions) ?>)</h2>
                            <p class="mt-1 text-sm text-slate-500">Postes et fonctions attribués aux collaborateurs.</p>
                        </div>

                        <?php if (empty($fonctions)): ?>
                            <div class="rounded-2xl bg-slate-50 py-10 text-center text-sm text-slate-400">
                                <i class="fas fa-inbox mb-2 text-2xl block"></i>
                                Aucune fonction configurée.
                            </div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($fonctions as $fnc): ?>
                                    <?php $isEdit = $editId === (int) $fnc['id'] && $activeTab === 'fonctions'; ?>
                                    <?php if ($isEdit): ?>
                                        <form method="post" class="flex items-center gap-3 rounded-2xl border border-violet-200 bg-violet-50 px-4 py-3">
                                            <input type="hidden" name="section" value="fonction">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int) $fnc['id'] ?>">
                                            <input type="text" name="label" value="<?= htmlspecialchars((string) $fnc['label']) ?>" required autofocus
                                                class="flex-1 rounded-xl border border-violet-300 bg-white px-4 py-2.5 text-sm outline-none focus:border-violet-500 focus:ring-2 focus:ring-violet-100">
                                            <button type="submit" class="rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-700">Enregistrer</button>
                                            <a href="<?= tabUrl('fonctions') ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler</a>
                                        </form>
                                    <?php else: ?>
                                        <div class="flex items-center justify-between rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 hover:border-slate-200 hover:bg-white transition">
                                            <div class="flex items-center gap-3">
                                                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-100 text-violet-600 text-xs font-bold"><?= (int) $fnc['id'] ?></div>
                                                <span class="font-medium text-slate-800"><?= htmlspecialchars((string) $fnc['label']) ?></span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <a href="<?= tabUrl('fonctions', ['edit' => $fnc['id']]) ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                                                    <i class="fas fa-pen mr-1"></i>Modifier
                                                </a>
                                                <form method="post" onsubmit="return confirm('Supprimer la fonction « <?= htmlspecialchars(addslashes((string) $fnc['label'])) ?> » ?');" class="inline">
                                                    <input type="hidden" name="section" value="fonction">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $fnc['id'] ?>">
                                                    <button type="submit" class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100">
                                                        <i class="fas fa-trash-alt mr-1"></i>Supprimer
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Formulaire ajout -->
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur self-start">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Nouveau</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Ajouter une fonction</h2>
                        <form method="post" class="mt-6 space-y-4">
                            <input type="hidden" name="section" value="fonction">
                            <input type="hidden" name="action" value="create">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Libellé de la fonction <span class="text-rose-500">*</span></label>
                                <input type="text" name="label" required placeholder="Ex : Ingénieur travaux"
                                    class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-violet-400 focus:ring-2 focus:ring-violet-100">
                            </div>
                            <button type="submit" class="w-full rounded-2xl bg-violet-600 py-3 text-sm font-semibold text-white hover:bg-violet-700">
                                <i class="fas fa-plus mr-2"></i>Créer la fonction
                            </button>
                        </form>
                    </div>
                </div>

            <?php /* ══════════════ STATUTS ══════════════ */ elseif ($activeTab === 'statuts'): ?>
                <div class="grid gap-6 xl:grid-cols-[1fr_420px]">

                    <!-- Liste -->
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                        <div class="mb-6">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-sky-600">Référentiel</p>
                            <h2 class="mt-1 text-xl font-semibold text-slate-950">Types de contrat (<?= count($statuts) ?>)</h2>
                            <p class="mt-1 text-sm text-slate-500">
                                Chaque statut est identifié par un <strong>code entier</strong> stocké dans le dossier du personnel.
                                L'abrege sert a generer le matricule RH.
                            </p>
                        </div>

                        <?php if (empty($statuts)): ?>
                            <div class="rounded-2xl bg-slate-50 py-10 text-center text-sm text-slate-400">
                                <i class="fas fa-inbox mb-2 text-2xl block"></i>Aucun statut configuré.
                            </div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($statuts as $st): ?>
                                    <?php $isEdit = $editId === (int) $st['id'] && $activeTab === 'statuts'; ?>
                                    <?php if ($isEdit): ?>
                                        <form method="post" class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-4 space-y-3">
                                            <input type="hidden" name="section" value="statut">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
                                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                                <div>
                                                    <label class="mb-1 block text-xs font-semibold text-slate-600">Code entier <span class="text-rose-500">*</span></label>
                                                    <input type="number" name="code" value="<?= (int) $st['code'] ?>" required
                                                        class="w-full rounded-xl border border-sky-300 bg-white px-3 py-2 text-sm outline-none focus:border-sky-500">
                                                </div>
                                                <div class="col-span-2">
                                                    <label class="mb-1 block text-xs font-semibold text-slate-600">Libellé <span class="text-rose-500">*</span></label>
                                                    <input type="text" name="label" value="<?= htmlspecialchars((string) $st['label']) ?>" required autofocus
                                                        class="w-full rounded-xl border border-sky-300 bg-white px-3 py-2 text-sm outline-none focus:border-sky-500">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-xs font-semibold text-slate-600">Abrege matricule</label>
                                                    <input type="text" name="abbreviation" value="<?= htmlspecialchars((string) ($st['abbreviation'] ?? '')) ?>" maxlength="12" placeholder="Ex : PREST"
                                                        class="w-full rounded-xl border border-sky-300 bg-white px-3 py-2 text-sm font-semibold uppercase outline-none focus:border-sky-500">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-xs font-semibold text-slate-600">Ordre</label>
                                                    <input type="number" name="sort_order" value="<?= (int) $st['sort_order'] ?>"
                                                        class="w-full rounded-xl border border-sky-300 bg-white px-3 py-2 text-sm outline-none focus:border-sky-500">
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="flex items-center gap-2 text-sm font-medium text-slate-700 cursor-pointer">
                                                    <input type="checkbox" name="is_active" value="1" <?= (int) $st['is_active'] ? 'checked' : '' ?>
                                                        class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                                                    Actif (proposé dans les formulaires)
                                                </label>
                                                <div class="flex gap-2">
                                                    <button type="submit" class="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-700">Enregistrer</button>
                                                    <a href="<?= tabUrl('statuts') ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler</a>
                                                </div>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <div class="flex items-center justify-between rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 hover:border-slate-200 hover:bg-white transition">
                                            <div class="flex items-center gap-4">
                                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-100 text-sky-700 text-sm font-bold"><?= (int) $st['code'] ?></div>
                                                <div>
                                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) $st['label']) ?></div>
                                                    <div class="text-xs text-slate-400">
                                                        Code <?= (int) $st['code'] ?> · Matricule <?= htmlspecialchars((string) ($st['abbreviation'] ?: 'AUT')) ?> · Ordre <?= (int) $st['sort_order'] ?> ·
                                                        <?php if ((int) $st['is_active']): ?>
                                                            <span class="text-emerald-600 font-semibold">Actif</span>
                                                        <?php else: ?>
                                                            <span class="text-amber-600 font-semibold">Inactif</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <a href="<?= tabUrl('statuts', ['edit' => $st['id']]) ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                                                    <i class="fas fa-pen mr-1"></i>Modifier
                                                </a>
                                                <form method="post" onsubmit="return confirm('Supprimer le statut « <?= htmlspecialchars(addslashes((string) $st['label'])) ?> » ?');" class="inline">
                                                    <input type="hidden" name="section" value="statut">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $st['id'] ?>">
                                                    <button type="submit" class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100">
                                                        <i class="fas fa-trash-alt mr-1"></i>Supprimer
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Formulaire ajout -->
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur self-start">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Nouveau</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Ajouter un statut</h2>
                        <div class="mt-3 rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                            <i class="fas fa-info-circle mr-1"></i>
                            Le <strong>code</strong> est l'entier stocke dans la base. L'<strong>abrege</strong> apparait dans les matricules : BNM-PREST-0001, BNM-STGE-0001, etc.
                        </div>
                        <form method="post" class="mt-5 space-y-4">
                            <input type="hidden" name="section" value="statut">
                            <input type="hidden" name="action" value="create">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Code entier <span class="text-rose-500">*</span></label>
                                    <input type="number" name="code" required placeholder="Ex : 5"
                                        class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Ordre d'affichage</label>
                                    <input type="number" name="sort_order" value="0" placeholder="0"
                                        class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                                </div>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Libellé <span class="text-rose-500">*</span></label>
                                <input type="text" name="label" required placeholder="Ex : CDI"
                                    class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Abrege matricule</label>
                                <input type="text" name="abbreviation" maxlength="12" placeholder="Ex : MAJ"
                                    class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold uppercase outline-none transition focus:border-sky-400 focus:ring-2 focus:ring-sky-100">
                            </div>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" name="is_active" value="1" checked
                                    class="h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500">
                                <span class="text-sm font-medium text-slate-700">Actif (proposé dans les formulaires)</span>
                            </label>
                            <button type="submit" class="w-full rounded-2xl bg-sky-600 py-3 text-sm font-semibold text-white hover:bg-sky-700">
                                <i class="fas fa-plus mr-2"></i>Créer le statut
                            </button>
                        </form>
                    </div>
                </div>

            <?php /* ══════════════ SITES ══════════════ */ elseif ($activeTab === 'sites'): ?>
                <div class="grid gap-6 xl:grid-cols-[1fr_420px]">

                    <!-- Liste -->
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                        <div class="mb-6">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-teal-600">Référentiel</p>
                            <h2 class="mt-1 text-xl font-semibold text-slate-950">Sites géographiques (<?= count($sites) ?>)</h2>
                            <p class="mt-1 text-sm text-slate-500">Agences, chantiers permanents ou bureaux régionaux. Le code correspond à <code class="rounded bg-slate-100 px-1">site_id</code> dans le dossier personnel.</p>
                        </div>

                        <?php if (empty($sites)): ?>
                            <div class="rounded-2xl bg-slate-50 py-10 text-center text-sm text-slate-400">
                                <i class="fas fa-inbox mb-2 text-2xl block"></i>Aucun site configuré.
                            </div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($sites as $site): ?>
                                    <?php $isEdit = $editId === (int) $site['id'] && $activeTab === 'sites'; ?>
                                    <?php if ($isEdit): ?>
                                        <form method="post" class="rounded-2xl border border-teal-200 bg-teal-50 px-4 py-4 space-y-3">
                                            <input type="hidden" name="section" value="site">
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="id" value="<?= (int) $site['id'] ?>">
                                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                                <div>
                                                    <label class="mb-1 block text-xs font-semibold text-slate-600">Code <span class="text-rose-500">*</span></label>
                                                    <input type="number" name="code" value="<?= (int) $site['code'] ?>" required
                                                        class="w-full rounded-xl border border-teal-300 bg-white px-3 py-2 text-sm outline-none focus:border-teal-500">
                                                </div>
                                                <div class="col-span-2">
                                                    <label class="mb-1 block text-xs font-semibold text-slate-600">Libellé <span class="text-rose-500">*</span></label>
                                                    <input type="text" name="label" value="<?= htmlspecialchars((string) $site['label']) ?>" required autofocus
                                                        class="w-full rounded-xl border border-teal-300 bg-white px-3 py-2 text-sm outline-none focus:border-teal-500">
                                                </div>
                                                <div>
                                                    <label class="mb-1 block text-xs font-semibold text-slate-600">Ordre</label>
                                                    <input type="number" name="sort_order" value="<?= (int) $site['sort_order'] ?>"
                                                        class="w-full rounded-xl border border-teal-300 bg-white px-3 py-2 text-sm outline-none focus:border-teal-500">
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-between gap-3">
                                                <label class="flex items-center gap-2 text-sm font-medium text-slate-700 cursor-pointer">
                                                    <input type="checkbox" name="is_active" value="1" <?= (int) $site['is_active'] ? 'checked' : '' ?>
                                                        class="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                                    Actif
                                                </label>
                                                <div class="flex gap-2">
                                                    <button type="submit" class="rounded-xl bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Enregistrer</button>
                                                    <a href="<?= tabUrl('sites') ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler</a>
                                                </div>
                                            </div>
                                        </form>
                                    <?php else: ?>
                                        <div class="flex items-center justify-between rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 hover:border-slate-200 hover:bg-white transition">
                                            <div class="flex items-center gap-4">
                                                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-100 text-teal-700 text-sm font-bold"><?= (int) $site['code'] ?></div>
                                                <div>
                                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) $site['label']) ?></div>
                                                    <div class="text-xs text-slate-400">
                                                        Code <?= (int) $site['code'] ?> · Ordre <?= (int) $site['sort_order'] ?> ·
                                                        <?php if ((int) $site['is_active']): ?>
                                                            <span class="text-emerald-600 font-semibold">Actif</span>
                                                        <?php else: ?>
                                                            <span class="text-amber-600 font-semibold">Inactif</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <a href="<?= tabUrl('sites', ['edit' => $site['id']]) ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50">
                                                    <i class="fas fa-pen mr-1"></i>Modifier
                                                </a>
                                                <form method="post" onsubmit="return confirm('Supprimer le site « <?= htmlspecialchars(addslashes((string) $site['label'])) ?> » ?');" class="inline">
                                                    <input type="hidden" name="section" value="site">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="id" value="<?= (int) $site['id'] ?>">
                                                    <button type="submit" class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100">
                                                        <i class="fas fa-trash-alt mr-1"></i>Supprimer
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Formulaire ajout -->
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur self-start">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Nouveau</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Ajouter un site</h2>
                        <form method="post" class="mt-6 space-y-4">
                            <input type="hidden" name="section" value="site">
                            <input type="hidden" name="action" value="create">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Code entier <span class="text-rose-500">*</span></label>
                                    <input type="number" name="code" required placeholder="Ex : 2"
                                        class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-teal-400 focus:ring-2 focus:ring-teal-100">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Ordre d'affichage</label>
                                    <input type="number" name="sort_order" value="0"
                                        class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-teal-400 focus:ring-2 focus:ring-teal-100">
                                </div>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Nom du site <span class="text-rose-500">*</span></label>
                                <input type="text" name="label" required placeholder="Ex : Bouaké"
                                    class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-teal-400 focus:ring-2 focus:ring-teal-100">
                            </div>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" name="is_active" value="1" checked
                                    class="h-4 w-4 rounded border-slate-300 text-teal-600 focus:ring-teal-500">
                                <span class="text-sm font-medium text-slate-700">Actif</span>
                            </label>
                            <button type="submit" class="w-full rounded-2xl bg-teal-600 py-3 text-sm font-semibold text-white hover:bg-teal-700">
                                <i class="fas fa-plus mr-2"></i>Créer le site
                            </button>
                        </form>
                    </div>
                </div>

            <?php /* ══════════════ DISCIPLINE ══════════════ */ elseif ($activeTab === 'discipline'): ?>
                <div class="space-y-6">
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-rose-600">Gouvernance disciplinaire</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Niveaux de fautes et sanctions</h2>
                        <p class="mt-2 text-sm text-slate-500">Ces paramètres alimentent la clôture RH des demandes d’explications. Chaque niveau de faute peut proposer une sanction par défaut, modifiable au cas par cas lors de la décision finale.</p>
                    </div>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                            <div class="mb-6 flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-600">Référentiel</p>
                                    <h3 class="mt-1 text-xl font-semibold text-slate-950">Fautes (<?= count($faultLevels) ?>)</h3>
                                </div>
                                <?php if ($faultLevelEdit): ?>
                                    <a href="<?= tabUrl('discipline') ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler l'édition</a>
                                <?php endif; ?>
                            </div>
                            <div class="space-y-3">
                                <?php if (!$faultLevels): ?>
                                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center text-sm text-slate-500">Aucun niveau de faute configuré.</div>
                                <?php else: ?>
                                    <?php foreach ($faultLevels as $faultLevel): ?>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                            <div class="flex items-start justify-between gap-4">
                                                <div>
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-amber-800"><?= htmlspecialchars((string) ($faultLevel['code'] ?? '')) ?></span>
                                                        <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700">Gravité <?= (int) ($faultLevel['severity_rank'] ?? 0) ?></span>
                                                        <?php if (empty($faultLevel['is_active'])): ?>
                                                            <span class="rounded-full bg-slate-300 px-3 py-1 text-xs font-semibold text-slate-700">Inactif</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mt-3 text-base font-semibold text-slate-900"><?= htmlspecialchars((string) ($faultLevel['label'] ?? '')) ?></div>
                                                    <div class="mt-1 text-sm text-slate-600"><?= !empty($faultLevel['description']) ? htmlspecialchars((string) $faultLevel['description']) : 'Aucune description complémentaire.' ?></div>
                                                    <div class="mt-2 text-xs text-slate-500">Sanction par défaut : <?= !empty($faultLevel['default_sanction_label']) ? htmlspecialchars((string) $faultLevel['default_sanction_label']) : 'Aucune' ?></div>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <a href="<?= tabUrl('discipline', ['fault_edit' => (int) $faultLevel['id']]) ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"><i class="fas fa-pen mr-1"></i>Modifier</a>
                                                    <form method="post" onsubmit="return confirm('Supprimer le niveau de faute « <?= htmlspecialchars(addslashes((string) ($faultLevel['label'] ?? ''))) ?> » ?');" class="inline">
                                                        <input type="hidden" name="section" value="fault_level">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= (int) $faultLevel['id'] ?>">
                                                        <button type="submit" class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100"><i class="fas fa-trash-alt mr-1"></i>Supprimer</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="mt-6 rounded-3xl border border-amber-100 bg-amber-50 p-5">
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-700"><?= $faultLevelEdit ? 'Modifier' : 'Nouveau' ?></p>
                                <h4 class="mt-1 text-lg font-semibold text-slate-950"><?= $faultLevelEdit ? 'Éditer un niveau de faute' : 'Ajouter un niveau de faute' ?></h4>
                                <form method="post" class="mt-5 space-y-4">
                                    <input type="hidden" name="section" value="fault_level">
                                    <input type="hidden" name="action" value="<?= $faultLevelEdit ? 'update' : 'create' ?>">
                                    <?php if ($faultLevelEdit): ?>
                                        <input type="hidden" name="id" value="<?= (int) ($faultLevelEdit['id'] ?? 0) ?>">
                                    <?php endif; ?>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Code <span class="text-rose-500">*</span></label>
                                            <input type="text" name="code" value="<?= htmlspecialchars((string) ($faultLevelEdit['code'] ?? '')) ?>" required class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:ring-2 focus:ring-amber-100">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Libellé <span class="text-rose-500">*</span></label>
                                            <input type="text" name="label" value="<?= htmlspecialchars((string) ($faultLevelEdit['label'] ?? '')) ?>" required class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:ring-2 focus:ring-amber-100">
                                        </div>
                                    </div>
                                    <div class="grid gap-4 md:grid-cols-3">
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Rang de gravité</label>
                                            <input type="number" name="severity_rank" min="1" value="<?= (int) ($faultLevelEdit['severity_rank'] ?? 1) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:ring-2 focus:ring-amber-100">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Ordre d'affichage</label>
                                            <input type="number" name="sort_order" value="<?= (int) ($faultLevelEdit['sort_order'] ?? 0) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:ring-2 focus:ring-amber-100">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Sanction par défaut</label>
                                            <select name="default_sanction_level_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:ring-2 focus:ring-amber-100">
                                                <option value="0">Aucune</option>
                                                <?php foreach ($sanctionLevels as $sanctionLevel): ?>
                                                    <option value="<?= (int) $sanctionLevel['id'] ?>" <?= (int) ($faultLevelEdit['default_sanction_level_id'] ?? 0) === (int) ($sanctionLevel['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string) ($sanctionLevel['label'] ?? '')) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Description</label>
                                        <textarea name="description" class="min-h-[110px] w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:ring-2 focus:ring-amber-100"><?= htmlspecialchars((string) ($faultLevelEdit['description'] ?? '')) ?></textarea>
                                    </div>
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox" name="is_active" value="1" <?= !isset($faultLevelEdit['is_active']) || !empty($faultLevelEdit['is_active']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-amber-600 focus:ring-amber-500">
                                        <span class="text-sm font-medium text-slate-700">Actif dans les décisions RH</span>
                                    </label>
                                    <button type="submit" class="w-full rounded-2xl bg-amber-600 py-3 text-sm font-semibold text-white hover:bg-amber-700"><i class="fas fa-save mr-2"></i><?= $faultLevelEdit ? 'Mettre à jour le niveau de faute' : 'Créer le niveau de faute' ?></button>
                                </form>
                            </div>
                        </div>

                        <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                            <div class="mb-6 flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-rose-600">Référentiel</p>
                                    <h3 class="mt-1 text-xl font-semibold text-slate-950">Sanctions (<?= count($sanctionLevels) ?>)</h3>
                                </div>
                                <?php if ($sanctionLevelEdit): ?>
                                    <a href="<?= tabUrl('discipline') ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Annuler l'édition</a>
                                <?php endif; ?>
                            </div>
                            <div class="space-y-3">
                                <?php if (!$sanctionLevels): ?>
                                    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-center text-sm text-slate-500">Aucun niveau de sanction configuré.</div>
                                <?php else: ?>
                                    <?php foreach ($sanctionLevels as $sanctionLevel): ?>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                            <div class="flex items-start justify-between gap-4">
                                                <div>
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-rose-800"><?= htmlspecialchars((string) ($sanctionLevel['code'] ?? '')) ?></span>
                                                        <span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-semibold text-slate-700">Niveau <?= (int) ($sanctionLevel['severity_rank'] ?? 0) ?></span>
                                                        <span class="rounded-full <?= (string) ($sanctionLevel['category'] ?? 'disciplinary') === 'warning' ? 'bg-sky-100 text-sky-800' : 'bg-emerald-100 text-emerald-800' ?> px-3 py-1 text-xs font-semibold">
                                                            <?= (string) ($sanctionLevel['category'] ?? 'disciplinary') === 'warning' ? 'Avertissement' : 'Sanction disciplinaire' ?>
                                                        </span>
                                                        <?php if (!empty($sanctionLevel['disables_platform_access'])): ?>
                                                            <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-semibold text-rose-800">Coupe accès</span>
                                                        <?php endif; ?>
                                                        <?php if (empty($sanctionLevel['is_active'])): ?>
                                                            <span class="rounded-full bg-slate-300 px-3 py-1 text-xs font-semibold text-slate-700">Inactif</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mt-3 text-base font-semibold text-slate-900"><?= htmlspecialchars((string) ($sanctionLevel['label'] ?? '')) ?></div>
                                                    <div class="mt-1 text-sm text-slate-600"><?= !empty($sanctionLevel['description']) ? htmlspecialchars((string) $sanctionLevel['description']) : 'Aucune description complémentaire.' ?></div>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <a href="<?= tabUrl('discipline', ['sanction_edit' => (int) $sanctionLevel['id']]) ?>" class="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"><i class="fas fa-pen mr-1"></i>Modifier</a>
                                                    <form method="post" onsubmit="return confirm('Supprimer le niveau de sanction « <?= htmlspecialchars(addslashes((string) ($sanctionLevel['label'] ?? ''))) ?> » ?');" class="inline">
                                                        <input type="hidden" name="section" value="sanction_level">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= (int) $sanctionLevel['id'] ?>">
                                                        <button type="submit" class="rounded-lg border border-rose-100 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100"><i class="fas fa-trash-alt mr-1"></i>Supprimer</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <div class="mt-6 rounded-3xl border border-rose-100 bg-rose-50 p-5">
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-rose-700"><?= $sanctionLevelEdit ? 'Modifier' : 'Nouveau' ?></p>
                                <h4 class="mt-1 text-lg font-semibold text-slate-950"><?= $sanctionLevelEdit ? 'Éditer une sanction' : 'Ajouter une sanction' ?></h4>
                                <form method="post" class="mt-5 space-y-4">
                                    <input type="hidden" name="section" value="sanction_level">
                                    <input type="hidden" name="action" value="<?= $sanctionLevelEdit ? 'update' : 'create' ?>">
                                    <?php if ($sanctionLevelEdit): ?>
                                        <input type="hidden" name="id" value="<?= (int) ($sanctionLevelEdit['id'] ?? 0) ?>">
                                    <?php endif; ?>
                                    <div class="grid gap-4 md:grid-cols-2">
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Code <span class="text-rose-500">*</span></label>
                                            <input type="text" name="code" value="<?= htmlspecialchars((string) ($sanctionLevelEdit['code'] ?? '')) ?>" required class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Libellé <span class="text-rose-500">*</span></label>
                                            <input type="text" name="label" value="<?= htmlspecialchars((string) ($sanctionLevelEdit['label'] ?? '')) ?>" required class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                                        </div>
                                    </div>
                                    <div class="grid gap-4 md:grid-cols-3">
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Niveau</label>
                                            <input type="number" name="severity_rank" min="1" value="<?= (int) ($sanctionLevelEdit['severity_rank'] ?? 1) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Ordre d'affichage</label>
                                            <input type="number" name="sort_order" value="<?= (int) ($sanctionLevelEdit['sort_order'] ?? 0) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                                        </div>
                                        <div>
                                            <label class="mb-2 block text-sm font-medium text-slate-700">Catégorie</label>
                                            <select name="category" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                                                <option value="warning" <?= (string) ($sanctionLevelEdit['category'] ?? 'disciplinary') === 'warning' ? 'selected' : '' ?>>Avertissement simple</option>
                                                <option value="disciplinary" <?= (string) ($sanctionLevelEdit['category'] ?? 'disciplinary') === 'disciplinary' ? 'selected' : '' ?>>Sanction disciplinaire</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Description</label>
                                        <textarea name="description" class="min-h-[110px] w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:ring-2 focus:ring-rose-100"><?= htmlspecialchars((string) ($sanctionLevelEdit['description'] ?? '')) ?></textarea>
                                    </div>
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox" name="disables_platform_access" value="1" <?= !empty($sanctionLevelEdit['disables_platform_access']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                        <span class="text-sm font-medium text-slate-700">Désactiver automatiquement l'accès plateforme à la clôture</span>
                                    </label>
                                    <label class="flex items-center gap-3 cursor-pointer">
                                        <input type="checkbox" name="is_active" value="1" <?= !isset($sanctionLevelEdit['is_active']) || !empty($sanctionLevelEdit['is_active']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-rose-600 focus:ring-rose-500">
                                        <span class="text-sm font-medium text-slate-700">Actif dans les décisions RH</span>
                                    </label>
                                    <button type="submit" class="w-full rounded-2xl bg-rose-600 py-3 text-sm font-semibold text-white hover:bg-rose-700"><i class="fas fa-save mr-2"></i><?= $sanctionLevelEdit ? 'Mettre à jour la sanction' : 'Créer la sanction' ?></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php elseif ($activeTab === 'pointage'): ?>
                <div class="mb-6 rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                    <div class="mb-6">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Tolérances horaires</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Règles de retard, déficit et heures supplémentaires</h2>
                        <p class="mt-1 text-sm text-slate-500">Ces valeurs évitent de qualifier un collaborateur en retard ou en heure supplémentaire pour quelques minutes d'écart.</p>
                    </div>
                    <form method="post" class="grid gap-4 lg:grid-cols-5 lg:items-end">
                        <input type="hidden" name="section" value="attendance_tolerance">
                        <input type="hidden" name="action" value="update">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Heure normale d'arrivée</label>
                            <input type="time" name="late_reference_time" value="<?= htmlspecialchars((string) ($attendanceSettings['late_reference_time'] ?? '08:40')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Objectif journalier (h)</label>
                            <input type="number" step="0.25" min="1" max="24" name="daily_work_target_hours" value="<?= htmlspecialchars((string) ($attendanceSettings['daily_work_target_hours'] ?? '10.00')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Tolérance arrivée (min)</label>
                            <input type="number" min="0" max="240" name="arrival_tolerance_minutes" value="<?= (int) ($attendanceSettings['arrival_tolerance_minutes'] ?? 10) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Tolérance départ/déficit (min)</label>
                            <input type="number" min="0" max="240" name="departure_tolerance_minutes" value="<?= (int) ($attendanceSettings['departure_tolerance_minutes'] ?? 10) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Tolérance HS (min)</label>
                            <input type="number" min="0" max="240" name="overtime_tolerance_minutes" value="<?= (int) ($attendanceSettings['overtime_tolerance_minutes'] ?? 15) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100">
                        </div>
                        <div class="lg:col-span-5 flex justify-end">
                            <button type="submit" class="rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
                                <i class="fas fa-save mr-2"></i>Enregistrer les tolérances
                            </button>
                        </div>
                    </form>
                </div>
                <div class="grid gap-6 xl:grid-cols-[1fr_420px]">
                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                        <div class="mb-6">
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-600">Pointage</p>
                            <h2 class="mt-1 text-xl font-semibold text-slate-950">Personnes dispensees de pointage (<?= count($pointageExemptions) ?>)</h2>
                            <p class="mt-1 text-sm text-slate-500">Ces collaborateurs ne recevront pas d'alertes d'absence et leurs jours sans badgeage ne seront pas convertis automatiquement en absences de paie.</p>
                        </div>

                        <?php if (!$pointageExemptions): ?>
                            <div class="rounded-2xl bg-slate-50 py-10 text-center text-sm text-slate-400">
                                <i class="fas fa-user-clock mb-2 block text-2xl"></i>
                                Aucune exemption active.
                            </div>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($pointageExemptions as $exemption): ?>
                                    <div class="flex flex-col gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($exemption['full_name'] ?? '')) ?></div>
                                            <div class="mt-1 text-xs text-slate-500">
                                                <?= htmlspecialchars((string) ($exemption['matricule'] ?? '')) ?> · <?= htmlspecialchars((string) ($exemption['email'] ?? '')) ?>
                                            </div>
                                            <?php if (trim((string) ($exemption['reason'] ?? '')) !== ''): ?>
                                                <div class="mt-2 text-sm text-slate-600"><?= htmlspecialchars((string) $exemption['reason']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <form method="post" onsubmit="return confirm('Retirer cette exemption de pointage ?');">
                                            <input type="hidden" name="section" value="pointage_exemption">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="personnel_id" value="<?= (int) ($exemption['personnel_id'] ?? 0) ?>">
                                            <button type="submit" class="rounded-xl border border-rose-100 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100">
                                                <i class="fas fa-trash-alt mr-2"></i>Retirer
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur self-start">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Nouvelle exemption</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-950">Ajouter une personne</h2>
                        <form method="post" class="mt-6 space-y-4">
                            <input type="hidden" name="section" value="pointage_exemption">
                            <input type="hidden" name="action" value="create">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Personnel <span class="text-rose-500">*</span></label>
                                <select name="personnel_id" required class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                                    <option value="">Selectionner...</option>
                                    <?php foreach ($pointagePersonnel as $person): ?>
                                        <option value="<?= (int) ($person['id'] ?? 0) ?>">
                                            <?= htmlspecialchars(trim((string) ($person['full_name'] ?? '')) . ' - ' . (string) ($person['matricule'] ?? '') . ' - ' . (string) ($person['fonction_label'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Motif</label>
                                <textarea name="reason" rows="3" placeholder="Ex : Directeur General dispense de badgeage" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100"></textarea>
                            </div>
                            <button type="submit" class="w-full rounded-2xl bg-indigo-600 py-3 text-sm font-semibold text-white hover:bg-indigo-700">
                                <i class="fas fa-plus mr-2"></i>Ajouter l'exemption
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

        </div><!-- /contenu -->

        <!-- Liens vers les autres pages de paramétrage ─────────────────────── -->
        <div class="mt-10 rounded-[30px] border border-slate-200/80 bg-slate-950 p-7 text-white shadow-[0_22px_70px_rgba(15,23,42,0.28)]">
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">Autres paramètrages</p>
            <h2 class="mt-2 text-xl font-semibold">Modules complémentaires</h2>
            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <a href="parametrage_motifs_sortie_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                    <i class="fas fa-door-open mb-2 text-amber-300 text-lg block"></i>
                    <div class="font-semibold text-sm">Motifs de sortie</div>
                    <div class="mt-1 text-xs text-slate-400">Libellés du select lors d'une sortie</div>
                </a>
                <a href="parametrage_paie_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                    <i class="fas fa-sliders-h mb-2 text-emerald-300 text-lg block"></i>
                    <div class="font-semibold text-sm">Règles de paie</div>
                    <div class="mt-1 text-xs text-slate-400">Coefficients CDI, CDD, stage, vacataire</div>
                </a>
                <a href="parametrage_contrat_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                    <i class="fas fa-signature mb-2 text-sky-300 text-lg block"></i>
                    <div class="font-semibold text-sm">Signataires et filigranes</div>
                    <div class="mt-1 text-xs text-slate-400">DG, RH et numérotation CTRH</div>
                </a>
                <a href="feries_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                    <i class="fas fa-calendar-times mb-2 text-rose-300 text-lg block"></i>
                    <div class="font-semibold text-sm">Jours fériés</div>
                    <div class="mt-1 text-xs text-slate-400">Dates exclues du calcul jours ouvrés</div>
                </a>
            </div>
        </div>

    </div>
</div>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>