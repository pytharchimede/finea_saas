<?php
session_start();

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/model/ProjectPlanningImport.php';

$pdo = \Database::getConnection();
$projectPlanningModel = new \ProjectPlanningImport($pdo);

// Filtre "chantiers actifs" (best-effort) pour les listes de sélection.
$hasArchiveColumns = false;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM chantier_banamur")->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasArchiveColumns = !empty($cols) && in_array('archived_at', $cols, true);
} catch (Throwable $e) {
    $hasArchiveColumns = false;
}

$chantierId = (int)($_GET['chantier_id'] ?? $_GET['id'] ?? 0);
if ($chantierId <= 0) {
    header('Location: details_chantiers.php');
    exit;
}

$chantierStmt = $pdo->prepare(
    "SELECT
        id,
        code,
        titre,
        COALESCE(NULLIF(TRIM(titre), ''), NULLIF(TRIM(code), ''), CONCAT('Chantier #', id)) AS libelle
     FROM chantier_banamur
     WHERE id = :id" . ($hasArchiveColumns ? " AND archived_at IS NULL" : "") . "
     LIMIT 1"
);
$chantierStmt->execute(['id' => $chantierId]);
$chantier = $chantierStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if (!$chantier) {
    header('Location: details_chantiers.php?error=chantier_not_found');
    exit;
}

\AuditTrail::logVisualEvent('preview', [
    'module' => 'planning',
    'action' => 'planning_dashboard_preview',
    'entity_type' => 'chantier',
    'entity_id' => (string)$chantierId,
    'entity_label' => (string)($chantier['libelle'] ?? 'Planning chantier'),
    'details' => [
        'view_mode' => 'browser_dashboard',
    ],
], $pdo);

$switchSql = "SELECT
        id,
        code,
        COALESCE(NULLIF(TRIM(titre), ''), NULLIF(TRIM(code), ''), CONCAT('Chantier #', id)) AS libelle
     FROM chantier_banamur\n";

if ($hasArchiveColumns) {
    $switchSql .= "WHERE archived_at IS NULL\n";
}

$switchSql .= "ORDER BY COALESCE(NULLIF(TRIM(titre), ''), code, id) ASC";

$switchStmt = $pdo->query($switchSql);
$chantierSwitchOptions = $switchStmt ? ($switchStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

$planningImportFlash = $_SESSION['planning_import_flash'] ?? null;
unset($_SESSION['planning_import_flash']);

$today = new DateTimeImmutable('today');
$todayIso = $today->format('Y-m-d');

$formatDate = static function (?string $value, bool $withTime = false): string {
    if (!$value) {
        return 'Non planifie';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'Non planifie';
    }

    return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
};

$normalizeProjectDate = static function (?DateTimeImmutable $date): ?DateTimeImmutable {
    if (!$date instanceof DateTimeImmutable) {
        return null;
    }

    $year = (int)$date->format('Y');
    if ($year >= 0 && $year < 1000) {
        $correctedDate = $date->setDate(
            2000 + $year,
            (int)$date->format('m'),
            (int)$date->format('d')
        );
        if ($correctedDate instanceof DateTimeImmutable) {
            return $correctedDate;
        }
    }

    if ($year >= 1900 && $year < 2000) {
        $shiftedDate = $date->modify('+100 years');
        if ($shiftedDate instanceof DateTimeImmutable) {
            return $shiftedDate;
        }
    }

    return $date;
};

$safeLabel = static function ($value, int $max = 44): string {
    $label = trim((string)$value);
    if ($label === '') {
        return 'Sans libelle';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($label, 'UTF-8') > $max
            ? mb_substr($label, 0, $max - 1, 'UTF-8') . '...'
            : $label;
    }

    return strlen($label) > $max ? substr($label, 0, $max - 3) . '...' : $label;
};

$projectStatusPalette = [
    'non_planifie' => ['label' => 'Non planifie', 'color' => '#94a3b8', 'bg' => 'rgba(148,163,184,0.14)', 'badge' => 'bg-slate-100 text-slate-700'],
    'a_venir' => ['label' => 'A venir', 'color' => '#7c3aed', 'bg' => 'rgba(124,58,237,0.14)', 'badge' => 'bg-violet-100 text-violet-800'],
    'en_cours' => ['label' => 'En cours', 'color' => '#2563eb', 'bg' => 'rgba(37,99,235,0.14)', 'badge' => 'bg-blue-100 text-blue-800'],
    'termine_theorique' => ['label' => 'Termine', 'color' => '#059669', 'bg' => 'rgba(5,150,105,0.14)', 'badge' => 'bg-emerald-100 text-emerald-800'],
];

$projectRealtimeStatusPalette = [
    'non_demarre' => ['label' => 'Non demarre', 'badge' => 'bg-slate-100 text-slate-700'],
    'en_cours' => ['label' => 'En cours', 'badge' => 'bg-sky-100 text-sky-700'],
    'bloque' => ['label' => 'Bloque', 'badge' => 'bg-rose-100 text-rose-700'],
    'termine' => ['label' => 'Termine', 'badge' => 'bg-emerald-100 text-emerald-700'],
];

$projectImport = $projectPlanningModel->getActiveImportByChantier($chantierId);
$projectRealtimeSnapshot = $projectImport ? $projectPlanningModel->getRealtimeSnapshotByImport((int)$projectImport['id'], 15) : ['latest_by_task' => [], 'recent_updates' => []];
$projectLatestRealtimeByTask = $projectRealtimeSnapshot['latest_by_task'] ?? [];
$projectRecentRealtimeUpdates = array_values($projectRealtimeSnapshot['recent_updates'] ?? []);
$assignmentDirectory = $projectPlanningModel->getAssignmentDirectory();
$assignmentsByTask = $projectImport ? $projectPlanningModel->getAssignmentsByImportId((int)($projectImport['id'] ?? 0)) : [];
$projectTasks = [];
$projectPlanningStart = null;
$projectPlanningEnd = null;
$projectSummaryCount = 0;
$projectLeafCount = 0;
$projectWeightedProgress = 0.0;
$projectWeightedDuration = 0;
$projectActualWeightedProgress = 0.0;
$projectActualWeightedDuration = 0;
$projectTrackedLeafCount = 0;
$projectBlockedCount = 0;
$projectDelayedCount = 0;

if ($projectImport && !empty($projectImport['tasks'])) {
    foreach ($projectImport['tasks'] as $taskRow) {
        $startRaw = trim((string)($taskRow['start_date'] ?? ''));
        $endRaw = trim((string)($taskRow['end_date'] ?? ''));

        $startDate = $startRaw !== '' ? $normalizeProjectDate(DateTimeImmutable::createFromFormat('Y-m-d', $startRaw) ?: null) : null;
        $endDate = $endRaw !== '' ? $normalizeProjectDate(DateTimeImmutable::createFromFormat('Y-m-d', $endRaw) ?: null) : null;

        if ($startDate && $endDate && $endDate < $startDate) {
            $endDate = $startDate;
            $endRaw = $startRaw;
        }

        if ($startDate && ($projectPlanningStart === null || $startDate < $projectPlanningStart)) {
            $projectPlanningStart = $startDate;
        }
        if ($endDate && ($projectPlanningEnd === null || $endDate > $projectPlanningEnd)) {
            $projectPlanningEnd = $endDate;
        }

        $statusKey = 'non_planifie';
        $progress = 0.0;
        $durationDays = 0;

        if ($startDate && $endDate) {
            $durationDays = max(1, (int)$startDate->diff($endDate)->days + 1);
            if ($today < $startDate) {
                $statusKey = 'a_venir';
                $progress = 0.0;
            } elseif ($today > $endDate) {
                $statusKey = 'termine_theorique';
                $progress = 100.0;
            } else {
                $statusKey = 'en_cours';
                $elapsed = max(1, (int)$startDate->diff($today)->days + 1);
                $progress = min(100, round(($elapsed / $durationDays) * 100, 1));
            }
        }

        $isSummary = !empty($taskRow['is_summary']);
        $taskId = (int)($taskRow['id'] ?? 0);
        $latestRealtime = $projectLatestRealtimeByTask[$taskId] ?? null;
        $actualProgress = $latestRealtime !== null ? max(0.0, min(100.0, (float)($latestRealtime['progress_percent'] ?? 0))) : null;
        $actualStatusKey = $latestRealtime !== null ? (string)($latestRealtime['status_code'] ?? 'en_cours') : null;
        $actualStatusLabel = $actualStatusKey !== null
            ? (string)($projectRealtimeStatusPalette[$actualStatusKey]['label'] ?? 'En cours')
            : 'Aucune mise a jour';
        $actualUpdatedAt = $latestRealtime !== null ? (string)($latestRealtime['created_at'] ?? '') : '';
        $actualMeetingDate = $latestRealtime !== null ? trim((string)($latestRealtime['meeting_date'] ?? '')) : '';
        $actualUpdatedBy = $latestRealtime !== null ? trim((string)($latestRealtime['updated_by'] ?? '')) : '';
        $actualComment = $latestRealtime !== null ? trim((string)($latestRealtime['comment_text'] ?? '')) : '';

        if ($isSummary) {
            $projectSummaryCount++;
        } else {
            $projectLeafCount++;
            if ($durationDays > 0) {
                $projectWeightedProgress += $progress * $durationDays;
                $projectWeightedDuration += $durationDays;
                if ($actualProgress !== null) {
                    $projectActualWeightedProgress += $actualProgress * $durationDays;
                    $projectActualWeightedDuration += $durationDays;
                }
            }
            if ($actualProgress !== null) {
                $projectTrackedLeafCount++;
                if ($actualStatusKey === 'bloque') {
                    $projectBlockedCount++;
                }
                if ($statusKey !== 'a_venir' && $actualProgress + 5 < $progress) {
                    $projectDelayedCount++;
                }
            }
        }

        $projectTasks[] = [
            'id' => $taskId,
            'taskUid' => (string)($taskRow['task_uid'] ?? ''),
            'parentUid' => (string)($taskRow['parent_uid'] ?? ''),
            'outlineLevel' => max(1, (int)($taskRow['outline_level'] ?? 1)),
            'groupLabel' => (string)($taskRow['group_label'] ?? ''),
            'fullLabel' => (string)($taskRow['task_name'] ?? 'Tache importee'),
            'label' => $safeLabel((string)($taskRow['task_name'] ?? 'Tache importee')),
            'start' => $startDate ? $startDate->format('Y-m-d') : null,
            'end' => $endDate ? $endDate->format('Y-m-d') : null,
            'startLabel' => $formatDate($startDate ? $startDate->format('Y-m-d') : null),
            'endLabel' => $formatDate($endDate ? $endDate->format('Y-m-d') : null),
            'durationLabel' => (string)($taskRow['duration_label'] ?? ''),
            'predecessorsLabel' => (string)($taskRow['predecessors_label'] ?? ''),
            'statusKey' => $statusKey,
            'statusLabel' => $projectStatusPalette[$statusKey]['label'] ?? 'Non planifie',
            'plannedProgress' => $progress,
            'actualProgress' => $actualProgress,
            'actualStatusKey' => $actualStatusKey,
            'actualStatusLabel' => $actualStatusLabel,
            'actualUpdatedAt' => $actualUpdatedAt,
            'actualUpdatedAtLabel' => $actualUpdatedAt !== '' ? $formatDate($actualUpdatedAt, true) : 'Aucune mise a jour',
            'actualMeetingDate' => $actualMeetingDate !== '' ? $actualMeetingDate : null,
            'actualMeetingDateLabel' => $actualMeetingDate !== '' ? $formatDate($actualMeetingDate) : 'Aucune reunion',
            'actualUpdatedBy' => $actualUpdatedBy,
            'actualComment' => $actualComment,
            'isSummary' => $isSummary,
        ];
    }
}

$projectLeafTaskById = [];
foreach ($projectTasks as $task) {
    if (!empty($task['isSummary'])) {
        continue;
    }
    $projectLeafTaskById[(int)$task['id']] = $task;
}

$assignmentPersonnelStats = [];
$assignmentServiceStats = [];
$assignmentUnassignedLeafCount = 0;

foreach ($projectLeafTaskById as $taskId => $task) {
    $assignment = $assignmentsByTask[$taskId] ?? null;
    $taskPersonnel = $assignment ? ($assignment['personnel'] ?? []) : [];
    $taskServices = $assignment ? ($assignment['services'] ?? []) : [];

    if (empty($taskPersonnel) && empty($taskServices)) {
        $assignmentUnassignedLeafCount++;
    }

    $plannedProgress = (float)($task['plannedProgress'] ?? 0);
    $actualProgress = $task['actualProgress'] !== null ? (float)$task['actualProgress'] : null;
    $actualStatusKey = $task['actualStatusKey'] !== null ? (string)$task['actualStatusKey'] : '';
    $isTracked = $actualProgress !== null;
    $isBlocked = $isTracked && $actualStatusKey === 'bloque';
    $isDelayed = $isTracked && (string)($task['statusKey'] ?? '') !== 'a_venir' && $actualProgress + 5 < $plannedProgress;

    foreach ($taskPersonnel as $person) {
        $personId = (int)($person['id'] ?? 0);
        if ($personId <= 0) {
            continue;
        }

        if (!isset($assignmentPersonnelStats[$personId])) {
            $assignmentPersonnelStats[$personId] = [
                'id' => $personId,
                'name' => trim((string)($person['name'] ?? '')),
                'matricule' => trim((string)($person['matricule'] ?? '')),
                'service_label' => trim((string)($person['service_label'] ?? '')),
                'task_count' => 0,
                'tracked_count' => 0,
                'blocked_count' => 0,
                'delayed_count' => 0,
                'planned_sum' => 0.0,
                'actual_sum' => 0.0,
                'actual_points' => 0,
            ];
        }

        $assignmentPersonnelStats[$personId]['task_count']++;
        $assignmentPersonnelStats[$personId]['planned_sum'] += $plannedProgress;
        if ($isTracked) {
            $assignmentPersonnelStats[$personId]['tracked_count']++;
            $assignmentPersonnelStats[$personId]['actual_sum'] += (float)$actualProgress;
            $assignmentPersonnelStats[$personId]['actual_points']++;
        }
        if ($isBlocked) {
            $assignmentPersonnelStats[$personId]['blocked_count']++;
        }
        if ($isDelayed) {
            $assignmentPersonnelStats[$personId]['delayed_count']++;
        }
    }

    foreach ($taskServices as $service) {
        $serviceId = (int)($service['id'] ?? 0);
        if ($serviceId <= 0) {
            continue;
        }

        if (!isset($assignmentServiceStats[$serviceId])) {
            $assignmentServiceStats[$serviceId] = [
                'id' => $serviceId,
                'label' => trim((string)($service['label'] ?? '')),
                'task_count' => 0,
                'tracked_count' => 0,
                'blocked_count' => 0,
                'delayed_count' => 0,
                'planned_sum' => 0.0,
                'actual_sum' => 0.0,
                'actual_points' => 0,
            ];
        }

        $assignmentServiceStats[$serviceId]['task_count']++;
        $assignmentServiceStats[$serviceId]['planned_sum'] += $plannedProgress;
        if ($isTracked) {
            $assignmentServiceStats[$serviceId]['tracked_count']++;
            $assignmentServiceStats[$serviceId]['actual_sum'] += (float)$actualProgress;
            $assignmentServiceStats[$serviceId]['actual_points']++;
        }
        if ($isBlocked) {
            $assignmentServiceStats[$serviceId]['blocked_count']++;
        }
        if ($isDelayed) {
            $assignmentServiceStats[$serviceId]['delayed_count']++;
        }
    }
}

$assignmentPersonnelStats = array_values($assignmentPersonnelStats);
usort($assignmentPersonnelStats, static function (array $a, array $b): int {
    $left = trim((string)($a['name'] ?? ''));
    $right = trim((string)($b['name'] ?? ''));
    if (function_exists('mb_strtolower')) {
        $left = mb_strtolower($left, 'UTF-8');
        $right = mb_strtolower($right, 'UTF-8');
    } else {
        $left = strtolower($left);
        $right = strtolower($right);
    }
    return strcmp($left, $right);
});

$assignmentServiceStats = array_values($assignmentServiceStats);
usort($assignmentServiceStats, static function (array $a, array $b): int {
    $left = trim((string)($a['label'] ?? ''));
    $right = trim((string)($b['label'] ?? ''));
    if (function_exists('mb_strtolower')) {
        $left = mb_strtolower($left, 'UTF-8');
        $right = mb_strtolower($right, 'UTF-8');
    } else {
        $left = strtolower($left);
        $right = strtolower($right);
    }
    return strcmp($left, $right);
});

$projectStats = [
    'hasImport' => $projectImport !== null,
    'taskCount' => count($projectTasks),
    'summaryCount' => $projectSummaryCount,
    'leafCount' => $projectLeafCount,
    'globalProgress' => $projectWeightedDuration > 0 ? round($projectWeightedProgress / $projectWeightedDuration, 1) : 0.0,
    'actualGlobalProgress' => $projectActualWeightedDuration > 0 ? round($projectActualWeightedProgress / $projectActualWeightedDuration, 1) : 0.0,
    'trackedLeafCount' => $projectTrackedLeafCount,
    'blockedCount' => $projectBlockedCount,
    'delayedCount' => $projectDelayedCount,
    'recentUpdateCount' => count($projectRecentRealtimeUpdates),
    'planningStart' => $projectPlanningStart ? $formatDate($projectPlanningStart->format('Y-m-d')) : 'A definir',
    'planningEnd' => $projectPlanningEnd ? $formatDate($projectPlanningEnd->format('Y-m-d')) : 'A definir',
    'sourceLabel' => $projectImport ? (string)($projectImport['source_label'] ?? 'Planning Project') : '',
    'sourceType' => $projectImport ? (string)($projectImport['source_type'] ?? 'project') : '',
    'importedBy' => $projectImport ? (string)($projectImport['imported_by'] ?? '') : '',
    'importedAt' => $projectImport && !empty($projectImport['created_at']) ? $formatDate((string)$projectImport['created_at'], true) : 'Jamais',
    'meetingReferenceDate' => $today->format('Y-m-d'),
];

$projectPlannerPayload = [
    'importId' => $projectImport ? (int)($projectImport['id'] ?? 0) : 0,
    'today' => $todayIso,
    'todayLabel' => $today->format('d/m/Y'),
    'planningStart' => $projectPlanningStart ? $projectPlanningStart->format('Y-m-d') : null,
    'planningEnd' => $projectPlanningEnd ? $projectPlanningEnd->format('Y-m-d') : null,
    'realtimeEndpoint' => 'request/project_planning_realtime.php',
    'assignmentEndpoint' => 'request/project_planning_assignment.php',
    'assignmentDirectory' => $assignmentDirectory,
    'meetingDate' => $today->format('Y-m-d'),
    'recentUpdates' => array_values($projectRecentRealtimeUpdates),
    'statusPalette' => $projectRealtimeStatusPalette,
    'gantt' => array_values($projectTasks),
];

$pageTitle = 'Planning Project - ' . (string)$chantier['libelle'];
$activeMenu = 'chantiers';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(244,114,182,0.12),_transparent_26%),radial-gradient(circle_at_top_right,_rgba(59,130,246,0.12),_transparent_28%),linear-gradient(180deg,_#f8fafc_0%,_#fdf4ff_44%,_#ffffff_100%)]">
    <script>
        function auditVisualPrintPlanning() {
            const payload = new URLSearchParams({
                kind: 'print',
                module: 'planning',
                action: 'planning_dashboard_print',
                entity_type: 'chantier',
                entity_id: <?= json_encode((string)$chantierId) ?>,
                entity_label: <?= json_encode((string)($chantier['libelle'] ?? 'Planning chantier')) ?>,
                source_path: 'decaissement/detail_travaux_chantier.php',
                client_timestamp: new Date().toISOString()
            });

            if (navigator.sendBeacon) {
                navigator.sendBeacon('../request/audit_visual_event.php', payload);
                return;
            }

            fetch('../request/audit_visual_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: payload.toString(),
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function() {});
        }
    </script>
    <div class="container mx-auto px-4 py-10">
        <div class="flex flex-col gap-6 xl:flex-row xl:items-start xl:justify-between mb-8">
            <div>
                <a href="details_chantiers.php" class="inline-flex items-center text-fuchsia-700 hover:text-fuchsia-900 mb-4 font-medium">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Retour aux chantiers
                </a>
                <div class="inline-flex items-center gap-2 rounded-full border border-fuchsia-100 bg-white/80 px-4 py-2 text-xs font-semibold uppercase tracking-[0.28em] text-fuchsia-700 shadow-sm">
                    <span class="h-2 w-2 rounded-full bg-fuchsia-500"></span>
                    Planning Project
                </div>
                <h1 class="mt-4 text-4xl font-black tracking-tight text-slate-900">Planning chantier independant</h1>
                <p class="mt-3 max-w-3xl text-base leading-7 text-slate-600">
                    Cette page ne gere que le planning importe depuis Microsoft Project. Il n'y a ici aucune liaison avec le devis, les debourses ou le suivi reunion. Le seul point commun est le chantier.
                </p>
                <div class="mt-4 flex flex-wrap gap-3 text-sm text-slate-500">
                    <label class="inline-flex items-center gap-3 rounded-full bg-white/90 px-4 py-2 shadow-sm border border-slate-100">
                        <span class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Chantier</span>
                        <select id="planningChantierSwitch" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-fuchsia-400">
                            <?php foreach ($chantierSwitchOptions as $chantierOption): ?>
                                <?php $chantierOptionId = (int)($chantierOption['id'] ?? 0); ?>
                                <option value="<?= $chantierOptionId ?>" <?= $chantierOptionId === $chantierId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(trim((string)($chantierOption['code'] ?? '')) !== '' ? ((string)$chantierOption['code'] . ' - ' . (string)($chantierOption['libelle'] ?? 'Chantier')) : (string)($chantierOption['libelle'] ?? 'Chantier'), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/90 px-4 py-2 shadow-sm border border-slate-100">
                        <i class="fas fa-building text-fuchsia-600"></i>
                        <?= htmlspecialchars((string)$chantier['libelle'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/90 px-4 py-2 shadow-sm border border-slate-100">
                        <i class="fas fa-calendar-day text-sky-600"></i>
                        Reference: <?= htmlspecialchars($today->format('d/m/Y'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 xl:justify-end">
                <?php if ($projectStats['hasImport']): ?>
                    <button id="projectMeetingSheetExport" type="button" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-800 shadow-lg shadow-slate-200/70 ring-1 ring-slate-200 transition hover:bg-slate-50">
                        <i class="fas fa-file-pdf mr-2 text-rose-600"></i>
                        Exporter la seance choisie
                    </button>
                <?php endif; ?>
                <button onclick="auditVisualPrintPlanning(); window.print();" class="inline-flex items-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-900/15 transition hover:bg-slate-800">
                    <i class="fas fa-print mr-2"></i>
                    Imprimer
                </button>
            </div>
        </div>

        <?php if (!empty($planningImportFlash['message'])): ?>
            <?php $flashType = (string)($planningImportFlash['type'] ?? 'success'); ?>
            <div class="mb-8 rounded-3xl border px-5 py-4 text-sm <?= $flashType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>">
                <?= htmlspecialchars((string)$planningImportFlash['message'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[0.95fr_1.05fr] mb-8">
            <div class="rounded-[2rem] border border-white/70 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]" data-accordion-card data-accordion-state="closed">
                <div class="flex items-start justify-between gap-4 mb-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Import source</p>
                        <h2 class="mt-1 text-2xl font-black text-slate-900">Charger un planning Project</h2>
                        <p class="mt-1 text-sm text-slate-500">Importe un fichier exporte depuis Microsoft Project ou colle le tableau directement. Ce planning est stocke a part et remplace uniquement l'import actif de ce chantier.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="rounded-2xl bg-fuchsia-50 p-3 text-fuchsia-700">
                            <i class="fas fa-file-import text-xl"></i>
                        </div>
                        <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50" data-accordion-button aria-label="Replier / Deplier" aria-expanded="false">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>

                <div data-accordion-body>
                    <form action="request/import_project_planning.php" method="POST" enctype="multipart/form-data" class="space-y-5 rounded-[1.75rem] border border-slate-200 bg-gradient-to-br from-white via-slate-50 to-fuchsia-50 p-5">
                        <input type="hidden" name="chantier_id" value="<?= htmlspecialchars((string)$chantierId, ENT_QUOTES, 'UTF-8') ?>">

                        <label class="block">
                            <span class="mb-2 block text-sm font-semibold text-slate-600">Libelle de la source</span>
                            <input name="source_label" type="text" value="<?= htmlspecialchars($projectStats['sourceLabel'] !== '' ? $projectStats['sourceLabel'] : 'Planning Project', ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-fuchsia-400" placeholder="Exemple: BANAMUR - PLANNING COMMODITES">
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-sm font-semibold text-slate-600">Fichier Project / export</span>
                            <input name="project_file" type="file" accept=".mpp,.xlsx,.xls,.csv,.txt" class="block w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 file:mr-4 file:rounded-full file:border-0 file:bg-fuchsia-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-fuchsia-700 hover:file:bg-fuchsia-200">
                            <p class="mt-2 text-xs text-slate-500">Le .mpp binaire natif n'est pas interprete directement ici. Si besoin, exporte depuis Microsoft Project en Excel, CSV ou TXT, ou colle le tableau ci-dessous.</p>
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-sm font-semibold text-slate-600">Coller la vue tableau Project</span>
                            <textarea name="project_text" rows="12" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-fuchsia-400" placeholder="Colle ici les lignes de Microsoft Project avec les colonnes Tache / Debut / Fin / Duree / Predecesseurs."></textarea>
                        </label>

                        <div class="flex flex-wrap items-center gap-3">
                            <button type="submit" class="inline-flex items-center rounded-2xl bg-fuchsia-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-fuchsia-600/15 transition hover:bg-fuchsia-700">
                                <i class="fas fa-upload mr-2"></i>
                                Importer le planning
                            </button>
                            <span class="text-xs text-slate-500">Aucun devis ni debourse n'est lu sur cette page.</span>
                        </div>
                    </form>
                </div>
            </div>

            <div class="rounded-[2rem] border border-white/70 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]" data-accordion-card data-accordion-state="closed">
                <div class="flex items-start justify-between gap-4 mb-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Etat courant</p>
                        <h2 class="mt-1 text-2xl font-black text-slate-900">Planning actif du chantier</h2>
                        <p class="mt-1 text-sm text-slate-500">Resume du planning importe actuellement pour ce chantier, toujours separe de tout debourse.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="rounded-2xl bg-slate-100 p-3 text-slate-700">
                            <i class="fas fa-diagram-project text-xl"></i>
                        </div>
                        <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50" data-accordion-button aria-label="Replier / Deplier" aria-expanded="false">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>

                <div data-accordion-body>
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 mb-5">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Taches</div>
                            <div class="mt-2 text-2xl font-black text-slate-900"><?= number_format($projectStats['taskCount'], 0, ',', ' ') ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Syntheses</div>
                            <div class="mt-2 text-2xl font-black text-slate-900"><?= number_format($projectStats['summaryCount'], 0, ',', ' ') ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Feuilles</div>
                            <div class="mt-2 text-2xl font-black text-slate-900"><?= number_format($projectStats['leafCount'], 0, ',', ' ') ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Planifie</div>
                            <div id="projectStatPlannedProgress" class="mt-2 text-2xl font-black text-slate-900"><?= number_format($projectStats['globalProgress'], 1, ',', ' ') ?>%</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Reel saisi</div>
                            <div id="projectStatActualProgress" class="mt-2 text-2xl font-black text-slate-900"><?= number_format($projectStats['actualGlobalProgress'], 1, ',', ' ') ?>%</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Suivies en direct</div>
                            <div id="projectStatTrackedLeafCount" class="mt-2 text-2xl font-black text-slate-900"><?= number_format($projectStats['trackedLeafCount'], 0, ',', ' ') ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Blocages</div>
                            <div id="projectStatBlockedCount" class="mt-2 text-2xl font-black text-slate-900"><?= number_format($projectStats['blockedCount'], 0, ',', ' ') ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Retards detectes</div>
                            <div id="projectStatDelayedCount" class="mt-2 text-2xl font-black text-slate-900"><?= number_format($projectStats['delayedCount'], 0, ',', ' ') ?></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 text-sm text-slate-600 lg:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Horizon</div>
                            <div class="mt-2 font-semibold text-slate-900"><?= htmlspecialchars($projectStats['planningStart'], ENT_QUOTES, 'UTF-8') ?> au <?= htmlspecialchars($projectStats['planningEnd'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Dernier import</div>
                            <div class="mt-2 font-semibold text-slate-900"><?= htmlspecialchars($projectStats['importedAt'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($projectStats['importedBy'] !== '' ? $projectStats['importedBy'] : 'Utilisateur non renseigne', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 lg:col-span-2">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Source</div>
                            <div class="mt-2 font-semibold text-slate-900"><?= htmlspecialchars($projectStats['sourceLabel'] !== '' ? $projectStats['sourceLabel'] : 'Aucun import actif', ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($projectStats['sourceType'] !== '' ? strtoupper($projectStats['sourceType']) : 'PROJECT', ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>

                    <?php if (!$projectStats['hasImport']): ?>
                        <div class="mt-5 rounded-3xl border border-dashed border-slate-200 bg-slate-50 px-5 py-10 text-center text-sm text-slate-500">
                            Aucun planning Project n'a encore ete importe pour ce chantier.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-[0.95fr_1.05fr] mb-8">
            <div class="rounded-[2rem] border border-white/70 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]" data-accordion-card data-accordion-state="closed">
                <div class="flex items-start justify-between gap-4 mb-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Point de reunion</p>
                        <h2 class="mt-1 text-2xl font-black text-slate-900">Mettre a jour chaque point du planning</h2>
                        <p class="mt-1 text-sm text-slate-500">Pendant la reunion, selectionne un point du planning, saisis l'avancement, le statut et la decision de seance. Chaque mise a jour reste rattachee a la date de reunion.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="rounded-2xl bg-sky-50 p-3 text-sky-700">
                            <i class="fas fa-tower-broadcast text-xl"></i>
                        </div>
                        <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50" data-accordion-button aria-label="Replier / Deplier" aria-expanded="false">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>

                <?php $projectLeafTasks = array_values(array_filter($projectTasks, static function (array $task): bool {
                    return empty($task['isSummary']);
                })); ?>

                <div data-accordion-body>
                    <?php if (!empty($projectLeafTasks)): ?>
                        <form id="projectRealtimeForm" class="space-y-5 rounded-[1.75rem] border border-slate-200 bg-gradient-to-br from-white via-sky-50 to-white p-5">
                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[0.9fr_1.1fr]">
                                <label class="block">
                                    <span class="mb-2 block text-sm font-semibold text-slate-600">Recherche point du planning</span>
                                    <input id="projectRealtimeTaskSearch" type="search" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-400" placeholder="Tape pour filtrer les points du planning...">
                                </label>
                                <label class="block">
                                    <span class="mb-2 block text-sm font-semibold text-slate-600">Point du planning</span>
                                    <select id="projectRealtimeTask" name="task_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-400">
                                        <?php foreach ($projectLeafTasks as $task): ?>
                                            <option value="<?= (int)$task['id'] ?>"><?= htmlspecialchars($task['fullLabel'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>

                            <div id="projectSelectedTaskCard" class="rounded-[1.5rem] border border-sky-100 bg-white/90 px-4 py-4 shadow-sm"></div>

                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                <label class="block">
                                    <span class="mb-2 block text-sm font-semibold text-slate-600">Affecter a un ou plusieurs services</span>
                                    <div class="relative">
                                        <input id="projectAssignmentServicesSearch" type="search" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-400" placeholder="Rechercher un service...">
                                        <div id="projectAssignmentServicesResults" class="absolute z-40 mt-2 hidden max-h-56 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-lg"></div>
                                    </div>

                                    <select id="projectAssignmentServices" name="service_ids[]" multiple class="hidden">
                                        <?php foreach (($assignmentDirectory['services'] ?? []) as $service): ?>
                                            <?php $serviceId = (int)($service['id'] ?? 0); ?>
                                            <?php if ($serviceId <= 0) {
                                                continue;
                                            } ?>
                                            <option value="<?= $serviceId ?>"><?= htmlspecialchars(trim((string)($service['label'] ?? 'Service')), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="projectAssignmentServicesBadges" class="mt-3 flex flex-wrap gap-2"></div>
                                </label>

                                <label class="block">
                                    <span class="mb-2 block text-sm font-semibold text-slate-600">Affecter a un ou plusieurs personnels</span>
                                    <div class="relative">
                                        <input id="projectAssignmentPersonnelSearch" type="search" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-400" placeholder="Rechercher un personnel...">
                                        <div id="projectAssignmentPersonnelResults" class="absolute z-40 mt-2 hidden max-h-56 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-lg"></div>
                                    </div>

                                    <select id="projectAssignmentPersonnel" name="personnel_ids[]" multiple class="hidden">
                                        <?php foreach (($assignmentDirectory['personnel'] ?? []) as $person): ?>
                                            <?php $personId = (int)($person['id'] ?? 0); ?>
                                            <?php if ($personId <= 0) {
                                                continue;
                                            } ?>
                                            <?php
                                            $personName = trim((string)($person['name'] ?? 'Personnel'));
                                            $serviceLabel = trim((string)($person['service_label'] ?? ''));
                                            $matricule = trim((string)($person['matricule'] ?? ''));
                                            $suffixParts = [];
                                            if ($serviceLabel !== '') {
                                                $suffixParts[] = $serviceLabel;
                                            }
                                            if ($matricule !== '') {
                                                $suffixParts[] = $matricule;
                                            }
                                            $suffix = !empty($suffixParts) ? ' • ' . implode(' • ', $suffixParts) : '';
                                            ?>
                                            <option value="<?= $personId ?>"><?= htmlspecialchars($personName . $suffix, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="projectAssignmentPersonnelBadges" class="mt-3 flex flex-wrap gap-2"></div>
                                </label>
                            </div>

                            <label class="block">
                                <span class="mb-2 block text-sm font-semibold text-slate-600">Date de reunion</span>
                                <input id="projectMeetingDate" name="meeting_date" type="date" value="<?= htmlspecialchars($projectStats['meetingReferenceDate'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-400">
                            </label>

                            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_150px]">
                                <label class="block">
                                    <span class="mb-2 block text-sm font-semibold text-slate-600">Progression reelle</span>
                                    <input id="projectRealtimeProgressRange" type="range" min="0" max="100" step="1" value="0" class="w-full accent-sky-600">
                                </label>
                                <label class="block">
                                    <span class="mb-2 block text-sm font-semibold text-slate-600">Valeur</span>
                                    <div class="relative">
                                        <input id="projectRealtimeProgressInput" name="progress" type="number" min="0" max="100" step="1" value="0" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 pr-10 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-400">
                                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-sm text-slate-400">%</span>
                                    </div>
                                </label>
                            </div>

                            <label class="block">
                                <span class="mb-2 block text-sm font-semibold text-slate-600">Statut reel</span>
                                <select id="projectRealtimeStatus" name="status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-400">
                                    <?php foreach ($projectRealtimeStatusPalette as $statusKey => $statusConfig): ?>
                                        <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$statusConfig['label'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="block">
                                <span class="mb-2 block text-sm font-semibold text-slate-600">Decision / commentaire de reunion</span>
                                <textarea id="projectRealtimeComment" name="comment" rows="4" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-sky-400" placeholder="Exemple: zone 2 terminee, relance fournisseur, blocage coffrage leve avant vendredi..."></textarea>
                            </label>

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div id="projectRealtimeHint" class="text-xs text-slate-500">Chaque ligne du tableau peut alimenter ce formulaire pendant la reunion.</div>
                                <button type="submit" class="inline-flex items-center rounded-2xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-sky-600/15 transition hover:bg-sky-700">
                                    <i class="fas fa-clipboard-check mr-2"></i>
                                    Enregistrer le point de reunion
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="rounded-[1.75rem] border border-dashed border-slate-200 bg-slate-50 px-6 py-16 text-center text-slate-500">
                            Importe d'abord des taches Project feuilles pour activer le suivi temps reel.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="rounded-[2rem] border border-white/70 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]" data-accordion-card data-accordion-state="closed">
                <div class="flex items-start justify-between gap-4 mb-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Historique reunion</p>
                        <h2 class="mt-1 text-2xl font-black text-slate-900">Derniers points saisis en reunion</h2>
                        <p class="mt-1 text-sm text-slate-500">Chaque point mis a jour pendant la reunion est conserve avec sa date de seance, son statut et son commentaire.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="rounded-2xl bg-emerald-50 p-3 text-emerald-700">
                            <i class="fas fa-wave-square text-xl"></i>
                        </div>
                        <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50" data-accordion-button aria-label="Replier / Deplier" aria-expanded="false">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                    </div>
                </div>

                <div data-accordion-body>
                    <div class="mb-4 grid grid-cols-3 gap-3">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Maj recentes</div>
                            <div id="projectRecentUpdateCount" class="mt-2 text-2xl font-black text-slate-900"><?= number_format($projectStats['recentUpdateCount'], 0, ',', ' ') ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Actualisation</div>
                            <div class="mt-2 text-sm font-semibold text-slate-900">30 sec</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Contexte</div>
                            <div class="mt-2 text-sm font-semibold text-slate-900">Reunion chantier</div>
                        </div>
                    </div>

                    <div id="projectRealtimeFeed" class="space-y-3 rounded-[1.75rem] border border-slate-200 bg-slate-50 p-4"></div>
                </div>
            </div>
        </div>

        <div class="rounded-[2rem] border border-white/70 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)] mb-8" data-accordion-card data-accordion-state="closed">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between mb-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Gantt Project</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Planning importe</h2>
                    <p class="mt-1 text-sm text-slate-500">Lecture exclusive du planning provenant de Project. Aucune ligne devis ou debourse n'est utilisee ici.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        <span class="font-semibold text-slate-500">Affichage</span>
                        <select id="projectScopeFilter" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-fuchsia-400">
                            <option value="all">Toutes les taches</option>
                            <option value="leaf">Taches feuilles</option>
                            <option value="summary">Taches syntheses</option>
                        </select>
                    </label>
                    <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        <span class="font-semibold text-slate-500">Zoom</span>
                        <select id="projectZoom" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-fuchsia-400">
                            <option value="compact">Compact</option>
                            <option value="normal" selected>Normal</option>
                            <option value="large">Large</option>
                        </select>
                    </label>
                    <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                        <span class="font-semibold text-slate-500">Recherche</span>
                        <input id="projectSearch" type="search" class="w-48 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-fuchsia-400" placeholder="Nom ou groupe...">
                    </label>
                    <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50" data-accordion-button aria-label="Replier / Deplier" aria-expanded="false">
                        <i class="fas fa-chevron-down"></i>
                    </button>
                </div>
            </div>

            <div data-accordion-body>
                <div class="overflow-hidden rounded-[1.75rem] border border-slate-200 bg-gradient-to-br from-white via-slate-50 to-sky-50 text-slate-900">
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 text-sm text-slate-600">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 shadow-sm border border-slate-200">
                                <span class="h-2.5 w-2.5 rounded-full bg-fuchsia-500"></span>
                                Aujourd'hui: <?= htmlspecialchars($today->format('d/m/Y'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 shadow-sm border border-slate-200">
                                <i class="fas fa-link-slash"></i>
                                Aucun lien avec devis / debourse
                            </span>
                        </div>
                        <div id="projectGanttSummaryText" class="text-xs text-slate-500"></div>
                    </div>
                    <div id="projectGanttPlanner" class="overflow-auto max-h-[75vh]"></div>
                </div>
            </div>
        </div>

        <div class="rounded-[2rem] border border-white/70 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]" data-accordion-card data-accordion-state="closed">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between mb-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Tableau Project</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Liste des taches importees</h2>
                    <p class="mt-1 text-sm text-slate-500">Lecture brute du planning Project, avec niveau de hierarchie, duree et predecesseurs.</p>
                </div>
                <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50" data-accordion-button aria-label="Replier / Deplier" aria-expanded="false">
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>

            <div data-accordion-body>
                <?php if (!empty($projectTasks)): ?>
                    <div class="overflow-auto rounded-[1.5rem] border border-slate-200">
                        <table class="min-w-full divide-y divide-slate-200 bg-white text-sm">
                            <thead class="bg-slate-50 text-slate-600">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">Type</th>
                                    <th class="px-4 py-3 text-left font-semibold">Tache</th>
                                    <th class="px-4 py-3 text-left font-semibold">Debut</th>
                                    <th class="px-4 py-3 text-left font-semibold">Fin</th>
                                    <th class="px-4 py-3 text-left font-semibold">Duree</th>
                                    <th class="px-4 py-3 text-left font-semibold">Predecesseurs</th>
                                    <th class="px-4 py-3 text-left font-semibold">Planifie</th>
                                    <th class="px-4 py-3 text-left font-semibold">Reel</th>
                                    <th class="px-4 py-3 text-left font-semibold">Derniere mise a jour</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 text-slate-700">
                                <?php foreach ($projectTasks as $task): ?>
                                    <tr data-project-task-row="<?= (int)$task['id'] ?>" class="transition-colors duration-150">
                                        <td class="px-4 py-3 align-top">
                                            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= htmlspecialchars($task['isSummary'] ? 'bg-fuchsia-100 text-fuchsia-800' : 'bg-slate-100 text-slate-700', ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($task['isSummary'] ? 'Synthese' : 'Feuille', ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div style="padding-left: <?= max(0, ((int)$task['outlineLevel'] - 1) * 18) ?>px;">
                                                <div class="font-semibold text-slate-900"><?= htmlspecialchars($task['fullLabel'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="mt-1 text-xs text-slate-500">Niveau <?= number_format((int)$task['outlineLevel'], 0, ',', ' ') ?><?= trim((string)$task['groupLabel']) !== '' ? ' • ' . htmlspecialchars((string)$task['groupLabel'], ENT_QUOTES, 'UTF-8') : '' ?></div>
                                                <?php if (!$task['isSummary']): ?>
                                                    <button type="button" data-project-focus-task="<?= (int)$task['id'] ?>" class="mt-2 inline-flex items-center rounded-full bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700 transition hover:bg-sky-100">
                                                        <i class="fas fa-bullseye mr-1"></i>
                                                        Mettre a jour en reunion
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 align-top"><?= htmlspecialchars($task['startLabel'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3 align-top"><?= htmlspecialchars($task['endLabel'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3 align-top"><?= htmlspecialchars(trim((string)$task['durationLabel']) !== '' ? (string)$task['durationLabel'] : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3 align-top"><?= htmlspecialchars(trim((string)$task['predecessorsLabel']) !== '' ? (string)$task['predecessorsLabel'] : '-', ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3 align-top">
                                            <span data-project-plan-badge="<?= (int)$task['id'] ?>" class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= htmlspecialchars((string)($projectStatusPalette[$task['statusKey']]['badge'] ?? 'bg-slate-100 text-slate-700'), ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars((string)$task['statusLabel'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 align-top">
                                            <div class="space-y-2">
                                                <span data-project-realtime-badge="<?= (int)$task['id'] ?>" class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= htmlspecialchars((string)($task['actualStatusKey'] !== null ? ($projectRealtimeStatusPalette[$task['actualStatusKey']]['badge'] ?? 'bg-slate-100 text-slate-700') : 'bg-slate-100 text-slate-500'), ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars((string)$task['actualStatusLabel'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                                <div data-project-realtime-progress="<?= (int)$task['id'] ?>" class="text-xs text-slate-500">
                                                    <?= htmlspecialchars($task['actualProgress'] !== null ? number_format((float)$task['actualProgress'], 1, ',', ' ') . '%' : 'Aucune saisie', ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td data-project-last-update="<?= (int)$task['id'] ?>" class="px-4 py-3 align-top text-xs text-slate-500">
                                            <div><?= htmlspecialchars((string)$task['actualMeetingDateLabel'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="mt-1 text-[11px] text-slate-400"><?= htmlspecialchars((string)$task['actualUpdatedAtLabel'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="rounded-[1.75rem] border border-dashed border-slate-200 bg-slate-50 px-6 py-16 text-center text-slate-500">
                        Importe un planning Project pour afficher le Gantt et la liste des taches de ce chantier.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-6 rounded-[2rem] border border-white/70 bg-white/95 p-6 shadow-[0_24px_80px_rgba(15,23,42,0.08)]" data-accordion-card data-accordion-state="closed">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between mb-6">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Repartition</p>
                    <h2 class="mt-1 text-2xl font-black text-slate-900">Repartition des taches et efficacite</h2>
                    <p class="mt-1 text-sm text-slate-500">Synthese basee sur les affectations (services/personnel) et les mises a jour de reunion.</p>
                </div>
                <button type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-600 transition hover:bg-slate-50" data-accordion-button aria-label="Replier / Deplier" aria-expanded="false">
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>

            <div data-accordion-body>
                <div class="mb-5 grid grid-cols-1 gap-3 text-sm text-slate-600 lg:grid-cols-3">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Taches feuilles</div>
                        <div class="mt-2 text-2xl font-black text-slate-900"><?= number_format((int)$projectStats['leafCount'], 0, ',', ' ') ?></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Non affectees</div>
                        <div class="mt-2 text-2xl font-black text-slate-900"><?= number_format((int)$assignmentUnassignedLeafCount, 0, ',', ' ') ?></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Personnels impliques</div>
                        <div class="mt-2 text-2xl font-black text-slate-900"><?= number_format(count($assignmentPersonnelStats), 0, ',', ' ') ?></div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <div class="rounded-[1.5rem] border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <div class="text-sm font-semibold text-slate-800">Par personnel</div>
                            <div class="mt-1 text-xs text-slate-500">Moyennes calculees sur les taches affectees (feuilles).</div>
                        </div>

                        <?php if (!empty($assignmentPersonnelStats)): ?>
                            <div class="overflow-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="bg-slate-50 text-slate-600">
                                        <tr>
                                            <th class="px-4 py-3 text-left font-semibold">Personnel</th>
                                            <th class="px-4 py-3 text-left font-semibold">Service</th>
                                            <th class="px-4 py-3 text-right font-semibold">Taches</th>
                                            <th class="px-4 py-3 text-right font-semibold">Suivi</th>
                                            <th class="px-4 py-3 text-right font-semibold">Planifie</th>
                                            <th class="px-4 py-3 text-right font-semibold">Reel</th>
                                            <th class="px-4 py-3 text-right font-semibold">Retards</th>
                                            <th class="px-4 py-3 text-right font-semibold">Blocages</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 text-slate-700">
                                        <?php foreach ($assignmentPersonnelStats as $row): ?>
                                            <?php
                                            $taskCount = max(0, (int)($row['task_count'] ?? 0));
                                            $trackedCount = max(0, (int)($row['tracked_count'] ?? 0));
                                            $plannedAvg = $taskCount > 0 ? ((float)($row['planned_sum'] ?? 0) / $taskCount) : 0.0;
                                            $actualPoints = max(0, (int)($row['actual_points'] ?? 0));
                                            $actualAvg = $actualPoints > 0 ? ((float)($row['actual_sum'] ?? 0) / $actualPoints) : 0.0;
                                            $coverage = $taskCount > 0 ? round(($trackedCount / $taskCount) * 100, 1) : 0.0;
                                            ?>
                                            <tr>
                                                <td class="px-4 py-3">
                                                    <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($row['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                                    <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string)($row['matricule'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                                </td>
                                                <td class="px-4 py-3 text-xs text-slate-600"><?= htmlspecialchars((string)($row['service_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="px-4 py-3 text-right font-semibold"><?= number_format($taskCount, 0, ',', ' ') ?></td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format($coverage, 1, ',', ' ') ?>%</td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format($plannedAvg, 1, ',', ' ') ?>%</td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format($actualAvg, 1, ',', ' ') ?>%</td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format((int)($row['delayed_count'] ?? 0), 0, ',', ' ') ?></td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format((int)($row['blocked_count'] ?? 0), 0, ',', ' ') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="px-6 py-10 text-center text-sm text-slate-500">Aucune affectation personnel enregistree pour ce chantier.</div>
                        <?php endif; ?>
                    </div>

                    <div class="rounded-[1.5rem] border border-slate-200 bg-white">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <div class="text-sm font-semibold text-slate-800">Par service</div>
                            <div class="mt-1 text-xs text-slate-500">Utile quand on affecte directement une tache a un service.</div>
                        </div>

                        <?php if (!empty($assignmentServiceStats)): ?>
                            <div class="overflow-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <thead class="bg-slate-50 text-slate-600">
                                        <tr>
                                            <th class="px-4 py-3 text-left font-semibold">Service</th>
                                            <th class="px-4 py-3 text-right font-semibold">Taches</th>
                                            <th class="px-4 py-3 text-right font-semibold">Suivi</th>
                                            <th class="px-4 py-3 text-right font-semibold">Planifie</th>
                                            <th class="px-4 py-3 text-right font-semibold">Reel</th>
                                            <th class="px-4 py-3 text-right font-semibold">Retards</th>
                                            <th class="px-4 py-3 text-right font-semibold">Blocages</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 text-slate-700">
                                        <?php foreach ($assignmentServiceStats as $row): ?>
                                            <?php
                                            $taskCount = max(0, (int)($row['task_count'] ?? 0));
                                            $trackedCount = max(0, (int)($row['tracked_count'] ?? 0));
                                            $plannedAvg = $taskCount > 0 ? ((float)($row['planned_sum'] ?? 0) / $taskCount) : 0.0;
                                            $actualPoints = max(0, (int)($row['actual_points'] ?? 0));
                                            $actualAvg = $actualPoints > 0 ? ((float)($row['actual_sum'] ?? 0) / $actualPoints) : 0.0;
                                            $coverage = $taskCount > 0 ? round(($trackedCount / $taskCount) * 100, 1) : 0.0;
                                            ?>
                                            <tr>
                                                <td class="px-4 py-3 font-semibold text-slate-900"><?= htmlspecialchars((string)($row['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="px-4 py-3 text-right font-semibold"><?= number_format($taskCount, 0, ',', ' ') ?></td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format($coverage, 1, ',', ' ') ?>%</td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format($plannedAvg, 1, ',', ' ') ?>%</td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format($actualAvg, 1, ',', ' ') ?>%</td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format((int)($row['delayed_count'] ?? 0), 0, ',', ' ') ?></td>
                                                <td class="px-4 py-3 text-right text-slate-600"><?= number_format((int)($row['blocked_count'] ?? 0), 0, ',', ' ') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="px-6 py-10 text-center text-sm text-slate-500">Aucune affectation service enregistree pour ce chantier.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    [data-accordion-card][data-accordion-state="closed"] [data-accordion-body] {
        display: none;
    }

    [data-accordion-card] [data-accordion-button] i {
        transition: transform 180ms ease;
    }

    [data-accordion-card][data-accordion-state="closed"] [data-accordion-button] i {
        transform: rotate(-90deg);
    }

    .planner-grid {
        position: relative;
        min-width: 100%;
    }

    .planner-grid::before {
        content: '';
        position: absolute;
        inset: 0;
        background-image: linear-gradient(to right, rgba(148, 163, 184, 0.12) 1px, transparent 1px);
        background-size: var(--planner-day-width, 30px) 100%;
        pointer-events: none;
    }

    #projectGanttPlanner {
        position: relative;
        max-height: min(75vh, 920px);
        overflow: auto;
        overscroll-behavior: contain;
    }

    .planner-today-line {
        position: absolute;
        top: 0;
        bottom: 0;
        width: 2px;
        background: linear-gradient(180deg, rgba(217, 70, 239, 0.95), rgba(59, 130, 246, 0.35));
        box-shadow: 0 0 0 3px rgba(217, 70, 239, 0.12);
        pointer-events: none;
    }

    .planner-task-bar {
        position: relative;
        overflow: hidden;
        border-radius: 9999px;
        min-height: 32px;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08), 0 12px 28px rgba(15, 23, 42, 0.18);
    }

    .planner-task-bar::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, rgba(255, 255, 255, 0.18), transparent 45%, rgba(255, 255, 255, 0.06));
        pointer-events: none;
    }

    .planner-header {
        position: sticky;
        top: 0;
        z-index: 30;
        background: rgba(255, 255, 255, 0.96);
        backdrop-filter: blur(10px);
    }

    .planner-meta-cell {
        background: rgba(255, 255, 255, 0.96);
        color: #0f172a;
    }

    .planner-meta-cell p:nth-child(2) {
        color: #475569;
    }

    .planner-meta-cell p:nth-child(3),
    .planner-meta-cell p:nth-child(4) {
        color: #64748b;
    }

    .project-selected-row {
        background: rgba(14, 165, 233, 0.08);
    }

    .planner-selected-task {
        background: linear-gradient(90deg, rgba(14, 165, 233, 0.08), rgba(14, 165, 233, 0.02));
    }

    .planner-selected-task .planner-meta-cell {
        box-shadow: inset 4px 0 0 #0ea5e9;
        background: rgba(240, 249, 255, 0.96);
    }

    @media print {
        #projectGanttPlanner {
            overflow: visible !important;
            max-height: none !important;
        }
    }
</style>

<script>
    const projectPlannerData = <?= json_encode($projectPlannerPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    document.addEventListener('DOMContentLoaded', function() {
        const planningChantierSwitch = document.getElementById('planningChantierSwitch');
        const projectScopeFilter = document.getElementById('projectScopeFilter');
        const projectZoom = document.getElementById('projectZoom');
        const projectSearch = document.getElementById('projectSearch');
        const projectGanttRoot = document.getElementById('projectGanttPlanner');
        const projectGanttSummaryText = document.getElementById('projectGanttSummaryText');
        const projectRealtimeForm = document.getElementById('projectRealtimeForm');
        const projectRealtimeTask = document.getElementById('projectRealtimeTask');
        const projectRealtimeTaskSearch = document.getElementById('projectRealtimeTaskSearch');
        const projectSelectedTaskCard = document.getElementById('projectSelectedTaskCard');
        const projectMeetingDate = document.getElementById('projectMeetingDate');
        const projectMeetingSheetExport = document.getElementById('projectMeetingSheetExport');
        const projectAssignmentServicesSearch = document.getElementById('projectAssignmentServicesSearch');
        const projectAssignmentServicesResults = document.getElementById('projectAssignmentServicesResults');
        const projectAssignmentServices = document.getElementById('projectAssignmentServices');
        const projectAssignmentPersonnelSearch = document.getElementById('projectAssignmentPersonnelSearch');
        const projectAssignmentPersonnelResults = document.getElementById('projectAssignmentPersonnelResults');
        const projectAssignmentPersonnel = document.getElementById('projectAssignmentPersonnel');
        const projectAssignmentServicesBadges = document.getElementById('projectAssignmentServicesBadges');
        const projectAssignmentPersonnelBadges = document.getElementById('projectAssignmentPersonnelBadges');
        const projectRealtimeProgressRange = document.getElementById('projectRealtimeProgressRange');
        const projectRealtimeProgressInput = document.getElementById('projectRealtimeProgressInput');
        const projectRealtimeStatus = document.getElementById('projectRealtimeStatus');
        const projectRealtimeComment = document.getElementById('projectRealtimeComment');
        const projectRealtimeHint = document.getElementById('projectRealtimeHint');
        const projectRealtimeFeed = document.getElementById('projectRealtimeFeed');
        const projectRecentUpdateCount = document.getElementById('projectRecentUpdateCount');
        const projectStatActualProgress = document.getElementById('projectStatActualProgress');
        const projectStatTrackedLeafCount = document.getElementById('projectStatTrackedLeafCount');
        const projectStatBlockedCount = document.getElementById('projectStatBlockedCount');
        const projectStatDelayedCount = document.getElementById('projectStatDelayedCount');
        const projectLeafTasks = Array.isArray(projectPlannerData.gantt) ?
            projectPlannerData.gantt.filter(task => !task.isSummary) : [];

        const assignmentDirectory = projectPlannerData.assignmentDirectory || {
            services: [],
            personnel: []
        };
        const assignmentPersonnelById = new Map((assignmentDirectory.personnel || []).map(person => [Number(person.id || 0), person]));
        const assignmentCache = new Map();
        let assignmentFetchToken = 0;

        // Draft utilisateur: empêche les rafraîchissements auto de vider / écraser la saisie.
        // Les champs ne doivent se vider que manuellement ou lors d'un changement de libellé (point du planning).
        let draftCommentDirty = false;
        let draftAssignmentsDirty = false;
        let suppressDraftAssignments = 0;

        let currentSelectedTaskId = projectLeafTasks.length ? Number(projectLeafTasks[0].id) : null;

        const numberFormatter = new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });

        const percentFormatter = new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1
        });

        function setAccordionState(card, state) {
            if (!card) {
                return;
            }
            const normalized = state === 'closed' ? 'closed' : 'open';
            card.setAttribute('data-accordion-state', normalized);
            card.querySelectorAll('[data-accordion-button]').forEach(button => {
                button.setAttribute('aria-expanded', normalized === 'open' ? 'true' : 'false');
            });
        }

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [char] || char));
        }

        function getMultiSelectIds(selectEl) {
            if (!selectEl) {
                return [];
            }
            return Array.from(selectEl.selectedOptions)
                .map(option => Number(option.value || 0))
                .filter(id => Number.isFinite(id) && id > 0);
        }

        function applyMultiSelectIds(selectEl, ids) {
            if (!selectEl) {
                return;
            }
            const set = new Set((Array.isArray(ids) ? ids : []).map(value => Number(value)));
            Array.from(selectEl.options).forEach(option => {
                option.selected = set.has(Number(option.value || 0));
            });
        }

        function getAllOptionIds(selectEl) {
            if (!selectEl) {
                return [];
            }
            return Array.from(selectEl.options)
                .map(option => Number(option.value || 0))
                .filter(id => Number.isFinite(id) && id > 0);
        }

        function areAllOptionsSelected(selectEl) {
            if (!selectEl) {
                return false;
            }

            const allIds = getAllOptionIds(selectEl);
            if (!allIds.length) {
                return false;
            }

            const selected = new Set(getMultiSelectIds(selectEl));
            if (selected.size < allIds.length) {
                return false;
            }

            return allIds.every(id => selected.has(id));
        }

        function selectAllOptions(selectEl) {
            if (!selectEl) {
                return;
            }
            Array.from(selectEl.options).forEach(option => {
                option.selected = true;
            });
        }

        function clearAllOptions(selectEl) {
            if (!selectEl) {
                return;
            }
            Array.from(selectEl.options).forEach(option => {
                option.selected = false;
            });
        }

        function renderServiceBadges() {
            if (!projectAssignmentServices || !projectAssignmentServicesBadges) {
                return;
            }

            if (areAllOptionsSelected(projectAssignmentServices)) {
                projectAssignmentServicesBadges.innerHTML = `
                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold bg-slate-100 text-slate-700">
                        <span class="truncate max-w-[240px]">Tous les services</span>
                        <button type="button" class="rounded-full bg-white/60 px-2 py-1 text-[10px] font-black text-slate-700 transition hover:bg-white" data-select-remove-all-services="1" aria-label="Retirer">
                            <i class="fas fa-times"></i>
                        </button>
                    </span>
                `;

                const removeBtn = projectAssignmentServicesBadges.querySelector('[data-select-remove-all-services]');
                if (removeBtn) {
                    removeBtn.addEventListener('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        clearAllOptions(projectAssignmentServices);
                        projectAssignmentServices.dispatchEvent(new Event('change', {
                            bubbles: true
                        }));
                    });
                }
                return;
            }

            renderSelectBadges(projectAssignmentServices, projectAssignmentServicesBadges, 'bg-slate-100 text-slate-700');
        }

        function renderSelectBadges(selectEl, containerEl, badgeClass) {
            if (!selectEl || !containerEl) {
                return;
            }

            const selected = Array.from(selectEl.selectedOptions);
            if (!selected.length) {
                containerEl.innerHTML = '';
                return;
            }

            containerEl.innerHTML = selected.map(option => {
                const value = String(option.value || '');
                const label = escapeHtml(option.textContent || 'Selection');
                return `
                    <span class="inline-flex items-center gap-2 rounded-full px-3 py-1 text-xs font-semibold ${badgeClass}">
                        <span class="truncate max-w-[240px]">${label}</span>
                        <button type="button" class="rounded-full bg-white/60 px-2 py-1 text-[10px] font-black text-slate-700 transition hover:bg-white" data-select-remove="${escapeHtml(value)}" aria-label="Retirer">
                            <i class="fas fa-times"></i>
                        </button>
                    </span>
                `;
            }).join('');

            containerEl.querySelectorAll('[data-select-remove]').forEach(button => {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const idToRemove = Number(button.getAttribute('data-select-remove') || 0);
                    Array.from(selectEl.options).forEach(option => {
                        if (Number(option.value || 0) === idToRemove) {
                            option.selected = false;
                        }
                    });
                    selectEl.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                });
            });
        }

        function setDropdownOpen(containerEl, open) {
            if (!containerEl) {
                return;
            }
            containerEl.classList.toggle('hidden', !open);
        }

        function setSelectOptionSelected(selectEl, id, selected) {
            if (!selectEl) {
                return;
            }
            const targetId = Number(id || 0);
            if (!targetId) {
                return;
            }
            Array.from(selectEl.options).forEach(option => {
                if (Number(option.value || 0) === targetId) {
                    option.selected = !!selected;
                }
            });
        }

        function getMatchingOptions(selectEl, query, excludeSelected) {
            const results = [];
            if (!selectEl) {
                return results;
            }
            const selectedIds = excludeSelected ? new Set(getMultiSelectIds(selectEl)) : null;
            const normalizedQuery = normalizeSearch(query);

            Array.from(selectEl.options).forEach(option => {
                const id = Number(option.value || 0);
                if (!id) {
                    return;
                }
                if (selectedIds && selectedIds.has(id)) {
                    return;
                }

                const label = String(option.textContent || '');
                if (normalizedQuery && !normalizeSearch(label).includes(normalizedQuery)) {
                    return;
                }

                results.push({
                    id,
                    label
                });
            });

            return results;
        }

        function renderServiceResults() {
            if (!projectAssignmentServicesSearch || !projectAssignmentServicesResults || !projectAssignmentServices) {
                return;
            }

            const normalizedQuery = normalizeSearch(projectAssignmentServicesSearch.value);
            const allSelected = areAllOptionsSelected(projectAssignmentServices);
            const showAllRow = normalizedQuery === '' || 'tout'.includes(normalizedQuery) || normalizedQuery.includes('tout') || normalizedQuery.includes('tous');

            const matches = getMatchingOptions(projectAssignmentServices, projectAssignmentServicesSearch.value, true).slice(0, 60);

            const rows = [];

            if (showAllRow) {
                rows.push(`
                    <button type="button" class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm ${allSelected ? 'text-slate-400 cursor-not-allowed' : 'text-slate-700 transition hover:bg-slate-50'}" data-service-pick="__all__" ${allSelected ? 'disabled' : ''}>
                        <span class="truncate font-semibold">Tout</span>
                        <span class="text-xs ${allSelected ? 'text-slate-300' : 'text-slate-400'}">Tous les services</span>
                    </button>
                `);
                rows.push('<div class="my-2 border-t border-slate-100"></div>');
            }

            if (!matches.length) {
                rows.push('<div class="px-3 py-2 text-sm text-slate-500">Aucun service</div>');
                projectAssignmentServicesResults.innerHTML = rows.join('');
                return;
            }

            rows.push(matches.map(item => `
                <button type="button" class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50" data-service-pick="${item.id}">
                    <span class="truncate">${escapeHtml(item.label)}</span>
                    <i class="fas fa-plus text-slate-400"></i>
                </button>
            `).join(''));

            projectAssignmentServicesResults.innerHTML = rows.join('');
        }

        function getPersonnelServiceId(personnelId) {
            const person = assignmentPersonnelById.get(Number(personnelId || 0));
            return Number(person && person.service_id ? person.service_id : 0);
        }

        function renderPersonnelResults() {
            if (!projectAssignmentPersonnelSearch || !projectAssignmentPersonnelResults || !projectAssignmentPersonnel) {
                return;
            }

            const selectedServiceIds = new Set(getMultiSelectIds(projectAssignmentServices));
            const allServicesSelected = areAllOptionsSelected(projectAssignmentServices);
            const query = projectAssignmentPersonnelSearch.value;
            const normalizedQuery = normalizeSearch(query);
            const selectedPersonnelIds = new Set(getMultiSelectIds(projectAssignmentPersonnel));
            const matches = [];

            Array.from(projectAssignmentPersonnel.options).forEach(option => {
                const id = Number(option.value || 0);
                if (!id) {
                    return;
                }
                if (selectedPersonnelIds.has(id)) {
                    return;
                }

                const serviceId = getPersonnelServiceId(id);
                if (selectedServiceIds.size && !allServicesSelected && (!serviceId || !selectedServiceIds.has(serviceId))) {
                    return;
                }

                const label = String(option.textContent || '');
                if (normalizedQuery && !normalizeSearch(label).includes(normalizedQuery)) {
                    return;
                }

                matches.push({
                    id,
                    label
                });
            });

            const sliced = matches.slice(0, 200);
            if (!sliced.length) {
                projectAssignmentPersonnelResults.innerHTML = '<div class="px-3 py-2 text-sm text-slate-500">Aucun personnel</div>';
                return;
            }

            projectAssignmentPersonnelResults.innerHTML = sliced.map(item => `
                <button type="button" class="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm text-slate-700 transition hover:bg-slate-50" data-personnel-pick="${item.id}">
                    <span class="truncate">${escapeHtml(item.label)}</span>
                    <i class="fas fa-plus text-slate-400"></i>
                </button>
            `).join('');
        }

        function syncServicesFromSelectedPersonnel() {
            if (!projectAssignmentServices || !projectAssignmentPersonnel) {
                return;
            }

            const selectedPersonnelIds = getMultiSelectIds(projectAssignmentPersonnel);
            if (!selectedPersonnelIds.length) {
                return;
            }

            const currentServiceIds = new Set(getMultiSelectIds(projectAssignmentServices));
            let changed = false;

            selectedPersonnelIds.forEach(personnelId => {
                const serviceId = getPersonnelServiceId(personnelId);
                if (serviceId > 0 && !currentServiceIds.has(serviceId)) {
                    currentServiceIds.add(serviceId);
                    changed = true;
                }
            });

            if (changed) {
                applyMultiSelectIds(projectAssignmentServices, Array.from(currentServiceIds));
                projectAssignmentServices.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
            }
        }

        function applyAssignmentsToForm(assignment) {
            if (!projectAssignmentServices || !projectAssignmentPersonnel) {
                return;
            }

            suppressDraftAssignments++;
            try {
                applyMultiSelectIds(projectAssignmentServices, assignment && assignment.service_ids ? assignment.service_ids : []);
                applyMultiSelectIds(projectAssignmentPersonnel, assignment && assignment.personnel_ids ? assignment.personnel_ids : []);
                syncServicesFromSelectedPersonnel();
                renderServiceBadges();
                renderSelectBadges(projectAssignmentPersonnel, projectAssignmentPersonnelBadges, 'bg-sky-100 text-sky-700');
                renderServiceResults();
                renderPersonnelResults();
            } finally {
                suppressDraftAssignments = Math.max(0, suppressDraftAssignments - 1);
            }
        }

        async function loadAssignmentsForTask(taskId) {
            const id = Number(taskId || 0);
            if (!id || !projectPlannerData.assignmentEndpoint) {
                return;
            }

            if (assignmentCache.has(id)) {
                const cached = assignmentCache.get(id);
                applyAssignmentsToForm(cached || {});
                return;
            }

            const token = ++assignmentFetchToken;
            try {
                const response = await fetch(`${projectPlannerData.assignmentEndpoint}?task_id=${encodeURIComponent(id)}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const payload = await response.json();
                if (token !== assignmentFetchToken) {
                    return;
                }
                if (!response.ok || !payload.ok || !payload.assignment) {
                    return;
                }

                assignmentCache.set(id, payload.assignment);
                applyAssignmentsToForm(payload.assignment || {});
            } catch (error) {}
        }

        async function saveAssignmentsForTask(taskId) {
            const id = Number(taskId || 0);
            if (!id || !projectPlannerData.assignmentEndpoint) {
                return;
            }

            const formData = new FormData();
            formData.set('task_id', String(id));

            getMultiSelectIds(projectAssignmentServices).forEach(serviceId => {
                formData.append('service_ids[]', String(serviceId));
            });
            getMultiSelectIds(projectAssignmentPersonnel).forEach(personnelId => {
                formData.append('personnel_ids[]', String(personnelId));
            });

            const response = await fetch(projectPlannerData.assignmentEndpoint, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok || !payload.assignment) {
                throw new Error(payload.error || 'Enregistrement des affectations impossible');
            }

            assignmentCache.set(id, payload.assignment);
            return payload.assignment;
        }

        function normalizeSearch(value) {
            return String(value || '')
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .toLowerCase()
                .trim();
        }

        function formatPercent(value) {
            return percentFormatter.format(Number(value) || 0) + '%';
        }

        function parseIsoDate(value) {
            const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!match) {
                return null;
            }

            let year = Number(match[1]);
            const month = Number(match[2]);
            const day = Number(match[3]);
            if (year >= 1900 && year < 2000) {
                year += 100;
            }

            return new Date(year, Math.max(0, month - 1), day, 12, 0, 0, 0);
        }

        function dayDiffInclusive(start, end) {
            const startDate = parseIsoDate(start);
            const endDate = parseIsoDate(end);
            if (!startDate || !endDate) {
                return 1;
            }
            return Math.max(1, Math.round((endDate - startDate) / 86400000) + 1);
        }

        function dayOffset(start, pivot) {
            const startDate = parseIsoDate(start);
            const pivotDate = parseIsoDate(pivot);
            if (!startDate || !pivotDate) {
                return 0;
            }
            return Math.round((pivotDate - startDate) / 86400000);
        }

        function formatDateLabel(value) {
            const date = parseIsoDate(value);
            if (!date) {
                return 'Aucune date';
            }
            return date.toLocaleDateString('fr-FR');
        }

        function formatDateTimeLabel(value) {
            const date = value ? new Date(String(value).replace(' ', 'T')) : null;
            if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
                return 'Aucune mise a jour';
            }
            return date.toLocaleDateString('fr-FR') + ' ' + date.toLocaleTimeString('fr-FR', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function realtimeBadgeClass(status) {
            if (status === 'termine') {
                return 'bg-emerald-100 text-emerald-700';
            }
            if (status === 'bloque') {
                return 'bg-rose-100 text-rose-700';
            }
            if (status === 'en_cours') {
                return 'bg-sky-100 text-sky-700';
            }
            return 'bg-slate-100 text-slate-700';
        }

        function syncProgressInputs(source) {
            if (!projectRealtimeProgressRange || !projectRealtimeProgressInput) {
                return;
            }
            const rawValue = Number(source.value || 0);
            const normalizedValue = Math.max(0, Math.min(100, Number.isFinite(rawValue) ? rawValue : 0));
            const roundedValue = Math.round(normalizedValue);
            projectRealtimeProgressRange.value = String(roundedValue);
            projectRealtimeProgressInput.value = String(roundedValue);
        }

        function getTaskById(taskId) {
            return Array.isArray(projectPlannerData.gantt) ?
                projectPlannerData.gantt.find(task => Number(task.id) === Number(taskId)) || null :
                null;
        }

        function populateTaskSelect(searchQuery, preferredTaskId) {
            if (!projectRealtimeTask) {
                return;
            }

            const normalizedQuery = normalizeSearch(searchQuery || '');
            const selectedTaskId = preferredTaskId !== undefined && preferredTaskId !== null ?
                Number(preferredTaskId) :
                Number(projectRealtimeTask.value || currentSelectedTaskId || 0);

            const filteredTasks = !normalizedQuery ?
                projectLeafTasks.slice() :
                projectLeafTasks.filter(task => normalizeSearch(`${task.fullLabel} ${task.groupLabel || ''}`).includes(normalizedQuery));

            let effectiveTasks = filteredTasks;
            const selectedTask = getTaskById(selectedTaskId);
            if (selectedTask && !filteredTasks.some(task => Number(task.id) === selectedTaskId)) {
                effectiveTasks = [selectedTask].concat(filteredTasks);
            }

            projectRealtimeTask.innerHTML = effectiveTasks.map(task => {
                const selectedAttribute = Number(task.id) === selectedTaskId ? ' selected' : '';
                return `<option value="${Number(task.id)}"${selectedAttribute}>${escapeHtml(task.fullLabel || 'Point du planning')}</option>`;
            }).join('');

            if (!effectiveTasks.length) {
                projectRealtimeTask.innerHTML = '<option value="">Aucun point correspondant</option>';
                currentSelectedTaskId = null;
                return;
            }

            const fallbackTaskId = effectiveTasks.some(task => Number(task.id) === selectedTaskId) ?
                selectedTaskId :
                Number(effectiveTasks[0].id);
            projectRealtimeTask.value = String(fallbackTaskId);
            currentSelectedTaskId = fallbackTaskId;
        }

        function renderSelectedTaskCard(task) {
            if (!projectSelectedTaskCard) {
                return;
            }

            if (!task) {
                projectSelectedTaskCard.innerHTML = '<div class="text-sm text-slate-500">Aucun point selectionne.</div>';
                return;
            }

            const actualProgress = task.actualProgress !== null && task.actualProgress !== undefined ?
                formatPercent(task.actualProgress) :
                'Aucune saisie';
            const actualStatusBadge = realtimeBadgeClass(task.actualStatusKey);
            projectSelectedTaskCard.innerHTML = `
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Point selectionne</div>
                        <div class="mt-2 text-lg font-black text-slate-900">${escapeHtml(task.fullLabel || 'Point du planning')}</div>
                        <div class="mt-1 text-sm text-slate-500">${escapeHtml(task.groupLabel || 'Planning Project')}</div>
                        <div class="mt-2 text-xs text-slate-500">${escapeHtml(task.startLabel || 'Non planifie')} → ${escapeHtml(task.endLabel || 'Non planifie')}</div>
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm lg:min-w-[280px]">
                        <div class="rounded-2xl bg-slate-50 px-3 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Planifie</div>
                            <div class="mt-2 font-bold text-slate-900">${formatPercent(task.plannedProgress || 0)}</div>
                        </div>
                        <div class="rounded-2xl bg-slate-50 px-3 py-3">
                            <div class="text-xs font-semibold uppercase tracking-wide text-slate-400">Reunion</div>
                            <div class="mt-2 font-bold text-slate-900">${actualProgress}</div>
                        </div>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                    <span class="inline-flex rounded-full px-3 py-1 font-semibold ${actualStatusBadge}">${escapeHtml(task.actualStatusLabel || 'Aucune mise a jour')}</span>
                    <span>Derniere reunion: ${escapeHtml(task.actualMeetingDateLabel || 'Aucune reunion')}</span>
                    <span>Derniere saisie: ${escapeHtml(task.actualUpdatedAtLabel || 'Aucune mise a jour')}</span>
                </div>
                ${task.actualComment ? `<div class="mt-4 rounded-2xl bg-sky-50 px-4 py-3 text-sm text-slate-700">${escapeHtml(task.actualComment)}</div>` : ''}
            `;
        }

        function applySelectedTaskHighlight() {
            document.querySelectorAll('[data-project-task-row]').forEach(row => {
                row.classList.toggle('project-selected-row', Number(row.getAttribute('data-project-task-row')) === Number(currentSelectedTaskId));
            });
            document.querySelectorAll('[data-project-gantt-row]').forEach(row => {
                row.classList.toggle('planner-selected-task', Number(row.getAttribute('data-project-gantt-row')) === Number(currentSelectedTaskId));
            });
        }

        function applyUpdateToTask(update) {
            const task = getTaskById(update.task_id);
            if (!task) {
                return;
            }

            task.actualProgress = Number(update.progress_percent || 0);
            task.actualStatusKey = String(update.status_code || 'en_cours');
            task.actualStatusLabel = projectPlannerData.statusPalette?.[task.actualStatusKey]?.label || 'En cours';
            task.actualUpdatedAt = String(update.created_at || '');
            task.actualUpdatedAtLabel = formatDateTimeLabel(update.created_at || '');
            task.actualMeetingDate = String(update.meeting_date || '');
            task.actualMeetingDateLabel = task.actualMeetingDate ? formatDateLabel(task.actualMeetingDate) : 'Aucune reunion';
            task.actualUpdatedBy = String(update.updated_by || '');
            task.actualComment = String(update.comment_text || '');
        }

        function refreshStatsFromTasks() {
            const leafTasks = Array.isArray(projectPlannerData.gantt) ?
                projectPlannerData.gantt.filter(task => !task.isSummary) : [];
            let trackedCount = 0;
            let blockedCount = 0;
            let delayedCount = 0;
            let weightedActual = 0;
            let weightedDuration = 0;

            leafTasks.forEach(task => {
                const durationDays = task.start && task.end ? dayDiffInclusive(task.start, task.end) : 0;
                if (task.actualProgress !== null && task.actualProgress !== undefined) {
                    trackedCount++;
                    if (durationDays > 0) {
                        weightedActual += Number(task.actualProgress || 0) * durationDays;
                        weightedDuration += durationDays;
                    }
                }
                if (task.actualStatusKey === 'bloque') {
                    blockedCount++;
                }
                if (task.actualProgress !== null && Number(task.actualProgress || 0) + 5 < Number(task.plannedProgress || 0) && task.statusKey !== 'a_venir') {
                    delayedCount++;
                }
            });

            if (projectStatActualProgress) {
                projectStatActualProgress.textContent = formatPercent(weightedDuration > 0 ? (weightedActual / weightedDuration) : 0);
            }
            if (projectStatTrackedLeafCount) {
                projectStatTrackedLeafCount.textContent = numberFormatter.format(trackedCount);
            }
            if (projectStatBlockedCount) {
                projectStatBlockedCount.textContent = numberFormatter.format(blockedCount);
            }
            if (projectStatDelayedCount) {
                projectStatDelayedCount.textContent = numberFormatter.format(delayedCount);
            }
        }

        function updateTaskRow(task) {
            const badge = document.querySelector(`[data-project-realtime-badge="${task.id}"]`);
            const progress = document.querySelector(`[data-project-realtime-progress="${task.id}"]`);
            const lastUpdate = document.querySelector(`[data-project-last-update="${task.id}"]`);

            if (badge) {
                badge.className = `inline-flex rounded-full px-3 py-1 text-xs font-semibold ${realtimeBadgeClass(task.actualStatusKey)}`;
                badge.textContent = task.actualStatusLabel || 'Aucune mise a jour';
            }
            if (progress) {
                progress.textContent = task.actualProgress !== null && task.actualProgress !== undefined ?
                    formatPercent(task.actualProgress) :
                    'Aucune saisie';
            }
            if (lastUpdate) {
                lastUpdate.innerHTML = `<div>${escapeHtml(task.actualMeetingDateLabel || 'Aucune reunion')}</div><div class="mt-1 text-[11px] text-slate-400">${escapeHtml(task.actualUpdatedAtLabel || 'Aucune mise a jour')}</div>`;
            }
        }

        function renderRealtimeFeed() {
            if (!projectRealtimeFeed) {
                return;
            }

            const updates = Array.isArray(projectPlannerData.recentUpdates) ? projectPlannerData.recentUpdates : [];
            if (projectRecentUpdateCount) {
                projectRecentUpdateCount.textContent = numberFormatter.format(updates.length);
            }

            if (!updates.length) {
                projectRealtimeFeed.innerHTML = '<div class="rounded-2xl bg-white px-4 py-5 text-sm text-slate-500">Aucun point de reunion saisi pour le moment.</div>';
                return;
            }

            projectRealtimeFeed.innerHTML = updates.map(update => {
                const badge = realtimeBadgeClass(String(update.status_code || 'non_demarre'));
                const meetingLabel = update.meeting_date ? formatDateLabel(update.meeting_date) : 'Sans date';
                const dateTimeLabel = formatDateTimeLabel(update.created_at || '');
                const userLabel = escapeHtml(update.updated_by || 'SYSTEM');
                const commentLabel = escapeHtml(update.comment_text || '');
                const taskLabel = escapeHtml(update.task_name || 'Point du planning');
                return `
                    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="font-semibold text-slate-900">${taskLabel}</div>
                                <div class="mt-1 text-xs text-slate-500">Reunion du ${meetingLabel} • saisi le ${dateTimeLabel}</div>
                                <div class="mt-1 text-xs text-slate-500">Par ${userLabel}</div>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold ${badge}">${escapeHtml(projectPlannerData.statusPalette?.[update.status_code]?.label || 'En cours')}</span>
                                <div class="mt-2 text-sm font-semibold text-slate-900">${formatPercent(update.progress_percent || 0)}</div>
                            </div>
                        </div>
                        ${commentLabel ? `<div class="mt-3 rounded-xl bg-slate-50 px-3 py-3 text-sm text-slate-600">${commentLabel}</div>` : ''}
                    </div>
                `;
            }).join('');
        }

        function focusTaskForMeeting(taskId, options = {}) {
            const task = getTaskById(taskId);
            if (!task || !projectRealtimeTask) {
                return;
            }

            const shouldScroll = options.scrollIntoView === true;

            const preserveDraft = options.preserveDraft === true;
            const previousTaskId = currentSelectedTaskId;
            const nextTaskId = Number(task.id);
            const didLibelleChange = previousTaskId === null || Number(previousTaskId) !== nextTaskId;
            if (didLibelleChange) {
                draftCommentDirty = false;
                draftAssignmentsDirty = false;
            }

            currentSelectedTaskId = nextTaskId;
            populateTaskSelect(projectRealtimeTaskSearch ? projectRealtimeTaskSearch.value : '', task.id);
            projectRealtimeTask.value = String(task.id);
            if (projectRealtimeStatus) {
                projectRealtimeStatus.value = task.actualStatusKey || 'en_cours';
            }
            if (projectRealtimeComment) {
                const commentIsActive = document.activeElement === projectRealtimeComment;
                if (!preserveDraft || (!draftCommentDirty && !commentIsActive)) {
                    projectRealtimeComment.value = task.actualComment || '';
                }
            }
            if (projectMeetingDate) {
                projectMeetingDate.value = task.actualMeetingDate || projectPlannerData.meetingDate || '';
            }
            const progressValue = task.actualProgress !== null && task.actualProgress !== undefined ? task.actualProgress : task.plannedProgress || 0;
            if (projectRealtimeProgressInput && projectRealtimeProgressRange) {
                projectRealtimeProgressInput.value = String(Math.round(Number(progressValue) || 0));
                syncProgressInputs(projectRealtimeProgressInput);
            }
            renderSelectedTaskCard(task);
            applySelectedTaskHighlight();
            if (!preserveDraft || !draftAssignmentsDirty) {
                loadAssignmentsForTask(task.id);
            }
            if (projectRealtimeHint) {
                projectRealtimeHint.textContent = `Point cible: ${task.fullLabel}`;
            }
            if (shouldScroll && projectRealtimeForm) {
                projectRealtimeForm.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }
        }

        async function refreshMeetingUpdates() {
            if (!projectPlannerData.importId || !projectPlannerData.realtimeEndpoint) {
                return;
            }

            try {
                const response = await fetch(`${projectPlannerData.realtimeEndpoint}?import_id=${encodeURIComponent(projectPlannerData.importId)}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                const payload = await response.json();
                if (!response.ok || !payload.ok) {
                    return;
                }

                const latestByTask = payload.latest_by_task || {};
                Object.keys(latestByTask).forEach(taskId => {
                    applyUpdateToTask(latestByTask[taskId]);
                    const task = getTaskById(taskId);
                    if (task) {
                        updateTaskRow(task);
                    }
                });

                projectPlannerData.recentUpdates = Array.isArray(payload.recent_updates) ? payload.recent_updates : [];
                renderRealtimeFeed();
                refreshStatsFromTasks();
                renderProjectGantt();
                if (currentSelectedTaskId !== null) {
                    focusTaskForMeeting(currentSelectedTaskId, {
                        preserveDraft: true
                    });
                }
            } catch (error) {}
        }

        function getDayWidth() {
            const mode = projectZoom ? projectZoom.value : 'normal';
            if (mode === 'compact') {
                return 24;
            }
            if (mode === 'large') {
                return 38;
            }
            return 30;
        }

        function buildGanttHeader(start, dayCount, dayWidth) {
            const wrapper = document.createElement('div');
            wrapper.className = 'planner-grid planner-header';
            wrapper.style.setProperty('--planner-day-width', `${dayWidth}px`);
            wrapper.style.marginLeft = '360px';

            const monthRow = document.createElement('div');
            monthRow.className = 'grid border-b border-slate-200';
            monthRow.style.gridTemplateColumns = `repeat(${dayCount}, ${dayWidth}px)`;

            const dayRow = document.createElement('div');
            dayRow.className = 'grid';
            dayRow.style.gridTemplateColumns = `repeat(${dayCount}, ${dayWidth}px)`;

            let current = parseIsoDate(start);
            let currentMonthKey = '';
            let monthSpan = 0;
            let monthStartIndex = 0;

            if (!current) {
                return wrapper;
            }

            for (let index = 0; index < dayCount; index++) {
                const monthKey = `${current.getFullYear()}-${current.getMonth()}`;
                if (currentMonthKey === '') {
                    currentMonthKey = monthKey;
                    monthStartIndex = index;
                    monthSpan = 0;
                }
                if (monthKey !== currentMonthKey) {
                    const monthCell = document.createElement('div');
                    monthCell.className = 'flex items-center justify-center border-r border-slate-200 px-2 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500';
                    monthCell.style.gridColumn = `${monthStartIndex + 1} / span ${monthSpan}`;
                    monthCell.textContent = new Date(current.getFullYear(), current.getMonth() - 1, 1).toLocaleDateString('fr-FR', {
                        month: 'short',
                        year: 'numeric'
                    });
                    monthRow.appendChild(monthCell);
                    currentMonthKey = monthKey;
                    monthStartIndex = index;
                    monthSpan = 0;
                }
                monthSpan++;

                const dayCell = document.createElement('div');
                dayCell.className = 'flex flex-col items-center justify-center border-r border-slate-200 px-1 py-2 text-[10px] text-slate-500';
                dayCell.innerHTML = `<span class="font-semibold text-slate-800">${String(current.getDate()).padStart(2, '0')}</span><span>${current.toLocaleDateString('fr-FR', { weekday: 'short' }).slice(0, 2)}</span>`;
                dayRow.appendChild(dayCell);
                current.setDate(current.getDate() + 1);
            }

            if (monthSpan > 0) {
                const finalMonthDate = new Date(current.getFullYear(), current.getMonth() - 1, 1);
                const monthCell = document.createElement('div');
                monthCell.className = 'flex items-center justify-center border-r border-slate-200 px-2 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500';
                monthCell.style.gridColumn = `${monthStartIndex + 1} / span ${monthSpan}`;
                monthCell.textContent = finalMonthDate.toLocaleDateString('fr-FR', {
                    month: 'short',
                    year: 'numeric'
                });
                monthRow.appendChild(monthCell);
            }

            wrapper.appendChild(monthRow);
            wrapper.appendChild(dayRow);
            return wrapper;
        }

        function buildProjectTaskRow(task, start, dayWidth, minHeight) {
            const row = document.createElement('div');
            row.setAttribute('data-project-gantt-row', String(task.id));
            row.className = 'flex min-h-[70px] border-b border-slate-200';

            const indent = Math.max(0, (Number(task.outlineLevel || 1) - 1) * 18);
            const safeFullLabel = escapeHtml(task.fullLabel || 'Tache importee');
            const safeGroup = escapeHtml(task.groupLabel || 'Planning Project');
            const safeStartLabel = escapeHtml(task.startLabel || 'Non planifie');
            const safeEndLabel = escapeHtml(task.endLabel || 'Non planifie');
            const safeDuration = escapeHtml(task.durationLabel || '-');
            const safePred = escapeHtml(task.predecessorsLabel || 'Aucun');
            const badgeClass = task.isSummary ? 'bg-fuchsia-100 text-fuchsia-700' : 'bg-sky-100 text-sky-700';
            const plannedProgress = Number(task.plannedProgress || 0);
            const actualProgress = task.actualProgress !== null && task.actualProgress !== undefined ? Number(task.actualProgress) : null;

            const meta = document.createElement('div');
            meta.className = 'planner-meta-cell sticky left-0 z-10 flex w-[360px] shrink-0 flex-col justify-center border-r border-slate-200 px-5 py-4 backdrop-blur';
            meta.innerHTML = `
                <div class="flex items-start justify-between gap-3">
                    <div style="padding-left:${indent}px;">
                        <p class="text-sm font-semibold text-slate-900">${safeFullLabel}</p>
                        <p class="mt-1 text-xs text-slate-500">${safeGroup}</p>
                        <p class="mt-1 text-[11px] text-slate-500">${safeStartLabel} → ${safeEndLabel}</p>
                        <p class="mt-1 text-[11px] text-slate-500">Duree: ${safeDuration} • Predecesseurs: ${safePred}</p>
                        <p class="mt-2 text-[11px] font-medium text-slate-600">Planifie: ${formatPercent(plannedProgress)}${actualProgress !== null ? ` • Reunion: ${formatPercent(actualProgress)}` : ''}</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-[10px] font-semibold uppercase tracking-wide ${badgeClass}">${task.isSummary ? 'Synthese' : task.statusLabel}</span>
                </div>
            `;
            row.appendChild(meta);

            const timeline = document.createElement('div');
            timeline.className = 'planner-grid relative flex-1';
            timeline.style.setProperty('--planner-day-width', `${dayWidth}px`);
            timeline.style.minHeight = `${minHeight}px`;

            if (task.start && task.end) {
                const offset = dayOffset(start, task.start) * dayWidth;
                const duration = dayDiffInclusive(task.start, task.end);
                const width = Math.max(duration * dayWidth, dayWidth);

                const bar = document.createElement('div');
                bar.className = 'planner-task-bar absolute top-1/2 -translate-y-1/2';
                bar.style.left = `${offset + 8}px`;
                bar.style.width = `${Math.max(width - 12, dayWidth * 0.72)}px`;
                bar.style.background = task.isSummary ?
                    'linear-gradient(135deg, #c084fc, #8b5cf6)' :
                    'linear-gradient(135deg, #38bdf8, #34d399)';

                const progressOverlay = document.createElement('div');
                progressOverlay.className = 'absolute left-0 top-0 h-full bg-white/25';
                progressOverlay.style.width = `${Math.max(0, Math.min(100, plannedProgress))}%`;
                bar.appendChild(progressOverlay);

                if (actualProgress !== null && !task.isSummary) {
                    const actualMarker = document.createElement('div');
                    actualMarker.className = 'absolute top-1/2 z-20 h-10 w-1.5 -translate-y-1/2 rounded-full bg-slate-900/70';
                    actualMarker.style.left = `${Math.max(8, Math.min(width - 6, (Math.max(0, Math.min(100, actualProgress)) / 100) * width))}px`;
                    bar.appendChild(actualMarker);
                }

                const label = document.createElement('div');
                label.className = 'relative z-10 flex h-full items-center justify-between gap-3 px-4 text-xs font-semibold text-white';
                label.innerHTML = `<span class="truncate">${escapeHtml(task.label || task.fullLabel || '')}</span><span>${formatPercent(plannedProgress)}</span>`;
                bar.appendChild(label);

                timeline.appendChild(bar);
            } else {
                const placeholder = document.createElement('div');
                placeholder.className = 'flex h-full items-center px-5 text-xs text-slate-500';
                placeholder.textContent = 'Aucune plage planifiee';
                timeline.appendChild(placeholder);
            }

            row.appendChild(timeline);
            return row;
        }

        function renderProjectGantt() {
            if (!projectGanttRoot || !projectGanttSummaryText) {
                return;
            }

            const dayWidth = getDayWidth();
            const scope = projectScopeFilter ? projectScopeFilter.value : 'all';
            const query = normalizeSearch(projectSearch ? projectSearch.value : '');

            const tasks = Array.isArray(projectPlannerData.gantt) ? projectPlannerData.gantt.filter(task => {
                if (scope === 'leaf' && task.isSummary) {
                    return false;
                }
                if (scope === 'summary' && !task.isSummary) {
                    return false;
                }
                if (!query) {
                    return true;
                }

                const haystack = normalizeSearch([
                    task.fullLabel,
                    task.groupLabel,
                    task.durationLabel,
                    task.predecessorsLabel
                ].join(' '));
                return haystack.includes(query);
            }) : [];

            projectGanttRoot.innerHTML = '';

            if (!projectPlannerData.planningStart || !projectPlannerData.planningEnd || !tasks.length) {
                projectGanttRoot.innerHTML = '<div class="px-6 py-14 text-center text-sm text-slate-400">Aucun planning Project importable a afficher pour ce chantier.</div>';
                projectGanttSummaryText.textContent = '0 tache affichee';
                return;
            }

            const totalDays = dayDiffInclusive(projectPlannerData.planningStart, projectPlannerData.planningEnd);
            const container = document.createElement('div');
            container.className = 'min-w-max';

            const header = buildGanttHeader(projectPlannerData.planningStart, totalDays, dayWidth);
            container.appendChild(header);

            const timelineWrap = document.createElement('div');
            timelineWrap.className = 'relative';
            const todayOffset = Math.max(0, Math.min(totalDays, dayOffset(projectPlannerData.planningStart, projectPlannerData.today))) * dayWidth;

            const todayLine = document.createElement('div');
            todayLine.className = 'planner-today-line';
            todayLine.style.left = `${360 + todayOffset + (dayWidth / 2)}px`;
            timelineWrap.appendChild(todayLine);

            tasks.forEach(task => {
                timelineWrap.appendChild(buildProjectTaskRow(task, projectPlannerData.planningStart, dayWidth, 70));
            });

            container.appendChild(timelineWrap);
            projectGanttRoot.appendChild(container);
            projectGanttSummaryText.textContent = `${numberFormatter.format(tasks.length)} tache(s) affichee(s) • horizon ${totalDays} j`;
            applySelectedTaskHighlight();
        }

        document.querySelectorAll('[data-project-focus-task]').forEach(button => {
            button.addEventListener('click', function() {
                focusTaskForMeeting(button.getAttribute('data-project-focus-task'), {
                    scrollIntoView: true
                });
            });
        });

        if (projectRealtimeProgressRange) {
            projectRealtimeProgressRange.addEventListener('input', function() {
                syncProgressInputs(projectRealtimeProgressRange);
            });
        }

        if (projectRealtimeProgressInput) {
            projectRealtimeProgressInput.addEventListener('input', function() {
                syncProgressInputs(projectRealtimeProgressInput);
            });
            projectRealtimeProgressInput.addEventListener('change', function() {
                syncProgressInputs(projectRealtimeProgressInput);
            });
        }

        if (projectRealtimeTaskSearch) {
            projectRealtimeTaskSearch.addEventListener('input', function() {
                populateTaskSelect(projectRealtimeTaskSearch.value, currentSelectedTaskId);
                if (projectRealtimeTask && projectRealtimeTask.value) {
                    focusTaskForMeeting(projectRealtimeTask.value);
                } else {
                    renderSelectedTaskCard(null);
                }
            });
        }

        if (projectRealtimeTask) {
            projectRealtimeTask.addEventListener('change', function() {
                focusTaskForMeeting(projectRealtimeTask.value);
            });
        }

        if (projectRealtimeForm) {
            projectRealtimeForm.addEventListener('submit', async function(event) {
                event.preventDefault();
                if (!projectPlannerData.realtimeEndpoint || !projectRealtimeTask) {
                    return;
                }

                const formData = new FormData(projectRealtimeForm);
                if (!formData.get('meeting_date') && projectMeetingDate) {
                    formData.set('meeting_date', projectMeetingDate.value || projectPlannerData.meetingDate || '');
                }

                if (projectRealtimeHint) {
                    projectRealtimeHint.textContent = 'Enregistrement du point de reunion...';
                }

                try {
                    const response = await fetch(projectPlannerData.realtimeEndpoint, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok || !payload.update) {
                        throw new Error(payload.error || 'Enregistrement impossible');
                    }

                    if (projectRealtimeHint) {
                        projectRealtimeHint.textContent = 'Point de reunion enregistre, sauvegarde des affectations...';
                    }

                    // Le commentaire est déjà enregistré côté backend à ce stade.
                    draftCommentDirty = false;

                    try {
                        await saveAssignmentsForTask(payload.update.task_id);
                        draftAssignmentsDirty = false;
                    } catch (assignmentError) {
                        if (projectRealtimeHint) {
                            projectRealtimeHint.textContent = assignmentError instanceof Error ? assignmentError.message : 'Erreur d enregistrement des affectations';
                        }
                    }

                    applyUpdateToTask(payload.update);
                    const task = getTaskById(payload.update.task_id);
                    if (task) {
                        updateTaskRow(task);
                    }

                    projectPlannerData.recentUpdates = [payload.update].concat(Array.isArray(projectPlannerData.recentUpdates) ? projectPlannerData.recentUpdates : [])
                        .slice(0, 20);

                    renderRealtimeFeed();
                    refreshStatsFromTasks();
                    renderProjectGantt();
                    focusTaskForMeeting(payload.update.task_id);

                    if (projectRealtimeHint) {
                        projectRealtimeHint.textContent = 'Point de reunion + affectations enregistres.';
                    }
                } catch (error) {
                    if (projectRealtimeHint) {
                        projectRealtimeHint.textContent = error instanceof Error ? error.message : 'Erreur d enregistrement';
                    }
                }
            });
        }

        if (projectAssignmentServices) {
            projectAssignmentServices.addEventListener('change', function() {
                if (suppressDraftAssignments === 0) {
                    draftAssignmentsDirty = true;
                }
                renderServiceBadges();
                renderServiceResults();
                renderPersonnelResults();
            });
            renderServiceBadges();
        }

        if (projectAssignmentPersonnel) {
            projectAssignmentPersonnel.addEventListener('change', function() {
                if (suppressDraftAssignments === 0) {
                    draftAssignmentsDirty = true;
                }
                renderSelectBadges(projectAssignmentPersonnel, projectAssignmentPersonnelBadges, 'bg-sky-100 text-sky-700');
                syncServicesFromSelectedPersonnel();
                renderPersonnelResults();
            });
            renderSelectBadges(projectAssignmentPersonnel, projectAssignmentPersonnelBadges, 'bg-sky-100 text-sky-700');
        }

        if (projectRealtimeComment) {
            projectRealtimeComment.addEventListener('input', function() {
                draftCommentDirty = true;
            });
        }

        if (projectAssignmentServicesSearch && projectAssignmentServicesResults) {
            projectAssignmentServicesSearch.addEventListener('input', function() {
                renderServiceResults();
                setDropdownOpen(projectAssignmentServicesResults, true);
            });
            projectAssignmentServicesSearch.addEventListener('focus', function() {
                renderServiceResults();
                setDropdownOpen(projectAssignmentServicesResults, true);
            });
            projectAssignmentServicesResults.addEventListener('click', function(event) {
                const button = event.target.closest('[data-service-pick]');
                if (!button) {
                    return;
                }
                const rawPick = String(button.getAttribute('data-service-pick') || '');
                if (rawPick === '__all__') {
                    selectAllOptions(projectAssignmentServices);
                    projectAssignmentServices.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                    projectAssignmentServicesSearch.value = '';
                    setDropdownOpen(projectAssignmentServicesResults, false);
                    return;
                }

                const serviceId = Number(rawPick || 0);
                setSelectOptionSelected(projectAssignmentServices, serviceId, true);
                projectAssignmentServices.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
                projectAssignmentServicesSearch.value = '';
                renderServiceResults();
            });
        }

        if (projectAssignmentPersonnelSearch && projectAssignmentPersonnelResults) {
            projectAssignmentPersonnelSearch.addEventListener('input', function() {
                renderPersonnelResults();
                setDropdownOpen(projectAssignmentPersonnelResults, true);
            });
            projectAssignmentPersonnelSearch.addEventListener('focus', function() {
                renderPersonnelResults();
                setDropdownOpen(projectAssignmentPersonnelResults, true);
            });
            projectAssignmentPersonnelResults.addEventListener('click', function(event) {
                const button = event.target.closest('[data-personnel-pick]');
                if (!button) {
                    return;
                }
                const personnelId = Number(button.getAttribute('data-personnel-pick') || 0);
                setSelectOptionSelected(projectAssignmentPersonnel, personnelId, true);
                projectAssignmentPersonnel.dispatchEvent(new Event('change', {
                    bubbles: true
                }));
                projectAssignmentPersonnelSearch.value = '';
                renderPersonnelResults();
            });
        }

        document.addEventListener('click', function(event) {
            const target = event.target;
            if (projectAssignmentServicesResults && projectAssignmentServicesSearch) {
                const insideService = projectAssignmentServicesResults.contains(target) || projectAssignmentServicesSearch.contains(target);
                if (!insideService) {
                    setDropdownOpen(projectAssignmentServicesResults, false);
                }
            }

            if (projectAssignmentPersonnelResults && projectAssignmentPersonnelSearch) {
                const insidePersonnel = projectAssignmentPersonnelResults.contains(target) || projectAssignmentPersonnelSearch.contains(target);
                if (!insidePersonnel) {
                    setDropdownOpen(projectAssignmentPersonnelResults, false);
                }
            }
        });

        if (planningChantierSwitch) {
            planningChantierSwitch.addEventListener('change', function() {
                const selectedChantierId = String(planningChantierSwitch.value || '');
                if (!selectedChantierId) {
                    return;
                }
                window.location.href = `detail_travaux_chantier.php?chantier_id=${encodeURIComponent(selectedChantierId)}`;
            });
        }

        if (projectMeetingSheetExport) {
            projectMeetingSheetExport.addEventListener('click', function() {
                const params = new URLSearchParams();
                params.set('chantier_id', String(<?= (int)$chantierId ?>));
                if (projectMeetingDate && projectMeetingDate.value) {
                    params.set('meeting_date', projectMeetingDate.value);
                }
                window.open(`request/export_project_reunion_sheet_pdf.php?${params.toString()}`, '_blank', 'noopener');
            });
        }

        if (projectScopeFilter) {
            projectScopeFilter.addEventListener('change', renderProjectGantt);
        }

        if (projectZoom) {
            projectZoom.addEventListener('change', renderProjectGantt);
        }

        if (projectSearch) {
            projectSearch.addEventListener('input', renderProjectGantt);
        }

        document.querySelectorAll('[data-accordion-card]').forEach(card => {
            const button = card.querySelector('[data-accordion-button]');
            if (!button) {
                return;
            }

            const initial = card.getAttribute('data-accordion-state') || 'open';
            setAccordionState(card, initial);

            button.addEventListener('click', function(event) {
                event.preventDefault();
                event.stopPropagation();
                const isClosed = card.getAttribute('data-accordion-state') === 'closed';
                setAccordionState(card, isClosed ? 'open' : 'closed');
            });
        });

        populateTaskSelect('', currentSelectedTaskId);
        renderRealtimeFeed();
        refreshStatsFromTasks();
        renderProjectGantt();

        if (projectRealtimeTask) {
            focusTaskForMeeting(projectRealtimeTask.value);
        }

        if (projectPlannerData.importId) {
            window.setInterval(refreshMeetingUpdates, 30000);
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
