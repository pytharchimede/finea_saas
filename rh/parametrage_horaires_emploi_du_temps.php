<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhScheduleController.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';

$pdo = \Database::getConnection();
$schedule = (new \RhScheduleController($pdo))->service();
$directory = (new \RhPersonnelController($pdo))->service();

$flashType = null;
$flashMessage = null;
$flashKey = 'schedule_work_windows_flash';
if (!empty($_SESSION[$flashKey]) && is_array($_SESSION[$flashKey])) {
    $flashType = (string)($_SESSION[$flashKey]['type'] ?? 'success');
    $flashMessage = (string)($_SESSION[$flashKey]['message'] ?? '');
    unset($_SESSION[$flashKey]);
}

$buildPageUrl = static function (int $personnelId, int $serviceId): string {
    $params = [];
    if ($personnelId > 0) {
        $params['personnel_id'] = $personnelId;
    }
    if ($serviceId > 0) {
        $params['service_id'] = $serviceId;
    }

    return 'parametrage_horaires_emploi_du_temps.php' . ($params ? ('?' . http_build_query($params)) : '');
};

$buildWorkWindowPayload = static function (string $prefix): array {
    $enabled = $_POST[$prefix . '_enabled'] ?? [];
    $start = $_POST[$prefix . '_start'] ?? [];
    $end = $_POST[$prefix . '_end'] ?? [];
    $payload = [];

    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $payload[$weekday] = [
            'enabled' => isset($enabled[$weekday]),
            'start_time' => (string)($start[$weekday] ?? ''),
            'end_time' => (string)($end[$weekday] ?? ''),
        ];
    }

    return $payload;
};

$personnelId = max(0, (int)($_GET['personnel_id'] ?? $_POST['personnel_id'] ?? 0));
$serviceId = max(0, (int)($_GET['service_id'] ?? $_POST['service_id'] ?? 0));

if ($personnelId <= 0) {
    try {
        $personnelId = (int)($schedule->resolvePersonnelIdByUserId((int)($_SESSION['user_id'] ?? 0)) ?? 0);
    } catch (Throwable $e) {
        $personnelId = 0;
    }
}

$personnel = $personnelId > 0 ? $schedule->getPersonnelScheduleContext($personnelId) : null;
if ($serviceId <= 0 && $personnel) {
    $serviceId = (int)($personnel['service_id'] ?? 0);
}

$auditWorkWindowAction = static function (string $eventType, string $action, string $result, string $entityType, ?string $entityId, ?string $entityLabel, array $details = []) use ($pdo): void {
    \AuditTrail::log($eventType, [
        'module' => 'rh',
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'entity_label' => $entityLabel,
        'result' => $result,
        'details' => $details,
    ], $pdo);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'save_personnel_work_windows') {
        $targetPersonnelId = max(0, (int)($_POST['personnel_id'] ?? 0));
        $result = $schedule->savePersonnelWorkWindows(
            $targetPersonnelId,
            $buildWorkWindowPayload('personnel_window'),
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );

        $targetPersonnel = $targetPersonnelId > 0 ? $schedule->getPersonnelScheduleContext($targetPersonnelId) : null;
        $targetServiceId = (int)($targetPersonnel['service_id'] ?? 0);
        $_SESSION[$flashKey] = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => !empty($result['ok']) ? 'Horaires employé mis à jour.' : (string)($result['error'] ?? 'Enregistrement impossible.'),
        ];

        $auditWorkWindowAction(
            !empty($result['ok']) ? 'schedule_work_window_personnel_saved' : 'schedule_work_window_personnel_failed',
            'save_personnel_work_windows',
            !empty($result['ok']) ? 'success' : 'failed',
            'personnel',
            $targetPersonnelId > 0 ? (string)$targetPersonnelId : null,
            (string)($targetPersonnel['nom'] ?? 'personnel'),
            ['service_id' => $targetServiceId > 0 ? $targetServiceId : null]
        );

        header('Location: ' . $buildPageUrl($targetPersonnelId, $targetServiceId));
        exit;
    }

    if ($action === 'save_personnel_load_threshold') {
        $targetPersonnelId = max(0, (int)($_POST['personnel_id'] ?? 0));
        $result = $schedule->savePersonnelDailyLoadThresholdHours(
            $targetPersonnelId,
            (string)($_POST['personnel_load_threshold_hours'] ?? ''),
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );

        $targetPersonnel = $targetPersonnelId > 0 ? $schedule->getPersonnelScheduleContext($targetPersonnelId) : null;
        $targetServiceId = (int)($targetPersonnel['service_id'] ?? 0);
        $_SESSION[$flashKey] = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => !empty($result['ok']) ? 'Seuil collaborateur mis à jour.' : (string)($result['error'] ?? 'Enregistrement impossible.'),
        ];

        $auditWorkWindowAction(
            !empty($result['ok']) ? 'schedule_load_threshold_personnel_saved' : 'schedule_load_threshold_personnel_failed',
            'save_personnel_load_threshold',
            !empty($result['ok']) ? 'success' : 'failed',
            'personnel',
            $targetPersonnelId > 0 ? (string)$targetPersonnelId : null,
            (string)($targetPersonnel['nom'] ?? 'personnel'),
            ['value' => $result['value'] ?? null]
        );

        header('Location: ' . $buildPageUrl($targetPersonnelId, $targetServiceId));
        exit;
    }

    if ($action === 'reset_personnel_load_threshold') {
        $targetPersonnelId = max(0, (int)($_POST['personnel_id'] ?? 0));
        $targetPersonnel = $targetPersonnelId > 0 ? $schedule->getPersonnelScheduleContext($targetPersonnelId) : null;
        $targetServiceId = (int)($targetPersonnel['service_id'] ?? 0);
        $resetOk = $schedule->resetPersonnelDailyLoadThresholdHours($targetPersonnelId);
        $_SESSION[$flashKey] = [
            'type' => $resetOk ? 'success' : 'error',
            'message' => $resetOk ? 'Seuil collaborateur réinitialisé sur le niveau service/défaut.' : 'Réinitialisation impossible.',
        ];

        $auditWorkWindowAction(
            $resetOk ? 'schedule_load_threshold_personnel_reset' : 'schedule_load_threshold_personnel_reset_failed',
            'reset_personnel_load_threshold',
            $resetOk ? 'success' : 'failed',
            'personnel',
            $targetPersonnelId > 0 ? (string)$targetPersonnelId : null,
            (string)($targetPersonnel['nom'] ?? 'personnel')
        );

        header('Location: ' . $buildPageUrl($targetPersonnelId, $targetServiceId));
        exit;
    }

    if ($action === 'reset_personnel_work_windows') {
        $targetPersonnelId = max(0, (int)($_POST['personnel_id'] ?? 0));
        $targetPersonnel = $targetPersonnelId > 0 ? $schedule->getPersonnelScheduleContext($targetPersonnelId) : null;
        $targetServiceId = (int)($targetPersonnel['service_id'] ?? 0);
        $resetOk = $schedule->resetPersonnelWorkWindows($targetPersonnelId);
        $_SESSION[$flashKey] = [
            'type' => $resetOk ? 'success' : 'error',
            'message' => $resetOk ? 'Surcharge employé supprimée. Héritage service/défaut restauré.' : 'Réinitialisation impossible.',
        ];

        $auditWorkWindowAction(
            $resetOk ? 'schedule_work_window_personnel_reset' : 'schedule_work_window_personnel_reset_failed',
            'reset_personnel_work_windows',
            $resetOk ? 'success' : 'failed',
            'personnel',
            $targetPersonnelId > 0 ? (string)$targetPersonnelId : null,
            (string)($targetPersonnel['nom'] ?? 'personnel'),
            ['service_id' => $targetServiceId > 0 ? $targetServiceId : null]
        );

        header('Location: ' . $buildPageUrl($targetPersonnelId, $targetServiceId));
        exit;
    }

    if ($action === 'save_service_work_windows') {
        $targetServiceId = max(0, (int)($_POST['service_id'] ?? 0));
        $result = $schedule->saveServiceWorkWindows(
            $targetServiceId,
            $buildWorkWindowPayload('service_window'),
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );

        $_SESSION[$flashKey] = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => !empty($result['ok']) ? 'Horaires du service mis à jour.' : (string)($result['error'] ?? 'Enregistrement impossible.'),
        ];

        $auditWorkWindowAction(
            !empty($result['ok']) ? 'schedule_work_window_service_saved' : 'schedule_work_window_service_failed',
            'save_service_work_windows',
            !empty($result['ok']) ? 'success' : 'failed',
            'service',
            $targetServiceId > 0 ? (string)$targetServiceId : null,
            'service_' . $targetServiceId,
            ['personnel_id' => $personnelId > 0 ? $personnelId : null]
        );

        header('Location: ' . $buildPageUrl($personnelId, $targetServiceId));
        exit;
    }

    if ($action === 'save_service_load_threshold') {
        $targetServiceId = max(0, (int)($_POST['service_id'] ?? 0));
        $result = $schedule->saveServiceDailyLoadThresholdHours(
            $targetServiceId,
            (string)($_POST['service_load_threshold_hours'] ?? ''),
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
        );

        $_SESSION[$flashKey] = [
            'type' => !empty($result['ok']) ? 'success' : 'error',
            'message' => !empty($result['ok']) ? 'Seuil service mis à jour.' : (string)($result['error'] ?? 'Enregistrement impossible.'),
        ];

        $auditWorkWindowAction(
            !empty($result['ok']) ? 'schedule_load_threshold_service_saved' : 'schedule_load_threshold_service_failed',
            'save_service_load_threshold',
            !empty($result['ok']) ? 'success' : 'failed',
            'service',
            $targetServiceId > 0 ? (string)$targetServiceId : null,
            'service_' . $targetServiceId,
            ['value' => $result['value'] ?? null]
        );

        header('Location: ' . $buildPageUrl($personnelId, $targetServiceId));
        exit;
    }

    if ($action === 'reset_service_load_threshold') {
        $targetServiceId = max(0, (int)($_POST['service_id'] ?? 0));
        $resetOk = $schedule->resetServiceDailyLoadThresholdHours($targetServiceId);
        $_SESSION[$flashKey] = [
            'type' => $resetOk ? 'success' : 'error',
            'message' => $resetOk ? 'Seuil service réinitialisé sur la valeur par défaut.' : 'Réinitialisation impossible.',
        ];

        $auditWorkWindowAction(
            $resetOk ? 'schedule_load_threshold_service_reset' : 'schedule_load_threshold_service_reset_failed',
            'reset_service_load_threshold',
            $resetOk ? 'success' : 'failed',
            'service',
            $targetServiceId > 0 ? (string)$targetServiceId : null,
            'service_' . $targetServiceId
        );

        header('Location: ' . $buildPageUrl($personnelId, $targetServiceId));
        exit;
    }

    if ($action === 'reset_service_work_windows') {
        $targetServiceId = max(0, (int)($_POST['service_id'] ?? 0));
        $resetOk = $schedule->resetServiceWorkWindows($targetServiceId);
        $_SESSION[$flashKey] = [
            'type' => $resetOk ? 'success' : 'error',
            'message' => $resetOk ? 'Surcharge service supprimée. Défaut restauré.' : 'Réinitialisation impossible.',
        ];

        $auditWorkWindowAction(
            $resetOk ? 'schedule_work_window_service_reset' : 'schedule_work_window_service_reset_failed',
            'reset_service_work_windows',
            $resetOk ? 'success' : 'failed',
            'service',
            $targetServiceId > 0 ? (string)$targetServiceId : null,
            'service_' . $targetServiceId,
            ['personnel_id' => $personnelId > 0 ? $personnelId : null]
        );

        header('Location: ' . $buildPageUrl($personnelId, $targetServiceId));
        exit;
    }
}

$personnelChoices = $directory->getPersonnelChoices('', 2000);
$serviceOptions = $directory->getServices();
$selectedServiceLabel = '';
foreach ($serviceOptions as $serviceOption) {
    if ((int)($serviceOption['id'] ?? 0) === $serviceId) {
        $selectedServiceLabel = trim((string)($serviceOption['label'] ?? ''));
        break;
    }
}
$effectiveWorkWindows = $personnelId > 0 ? $schedule->getPersonnelWorkWindows($personnelId) : $schedule->getDefaultWorkWindows();
$serviceWorkWindows = $serviceId > 0 ? $schedule->getServiceWorkWindows($serviceId) : $schedule->getDefaultWorkWindows();
$defaultLoadThresholdHours = $schedule->getDefaultDailyLoadThresholdHours();
$serviceLoadThresholdHours = $serviceId > 0 ? $schedule->getServiceDailyLoadThresholdHours($serviceId) : $defaultLoadThresholdHours;
$personnelLoadThresholdHours = $personnelId > 0 ? $schedule->getPersonnelDailyLoadThresholdHours($personnelId) : $defaultLoadThresholdHours;
$pageTitle = 'Horaires emploi du temps';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(241,245,249,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(199,210,254,0.55),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="relative overflow-hidden rounded-[32px] border border-indigo-100 bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <?php if ($personnelId > 0): ?><div class="relative z-10 mb-5 flex justify-end"><a target="_blank" href="export_emploi_du_temps_hebdomadaire.php?personnel_id=<?= (int)$personnelId ?>" class="rounded-2xl bg-amber-300 px-5 py-3 text-sm font-black text-slate-950"><i class="fas fa-file-pdf mr-2"></i>Exporter le récap hebdomadaire</a></div><?php endif; ?>
            <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <div class="absolute bottom-0 right-20 h-24 w-24 rounded-full bg-indigo-300/20 blur-2xl"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-indigo-200">Ressources humaines</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Horaires emploi du temps</h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">
                        Paramétrez les fenêtres de planification par employé et par service. L'héritage appliqué est : employé, puis service, puis défaut global.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="parametrage_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-sliders-h mr-2"></i>
                        Paramétrage RH
                    </a>
                    <a href="../emploi_du_temps.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        Retour emploi du temps
                    </a>
                </div>
            </div>
        </div>

        <?php if ($flashType !== null && $flashMessage !== null && $flashMessage !== ''): ?>
            <div class="mt-6 rounded-2xl border px-5 py-4 text-sm font-medium <?= $flashType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                <i class="fas <?= $flashType === 'success' ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-circle text-rose-500' ?> mr-2"></i>
                <?= htmlspecialchars($flashMessage) ?>
            </div>
        <?php endif; ?>

        <div class="mt-6 rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <form method="get" class="grid gap-4 lg:grid-cols-[1.2fr_1fr_auto] lg:items-end">
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Personnel</label>
                    <select name="personnel_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:bg-white">
                        <option value="0">Sélectionner un collaborateur</option>
                        <?php foreach ($personnelChoices as $choice): ?>
                            <option value="<?= (int)($choice['id'] ?? 0) ?>" <?= (int)($choice['id'] ?? 0) === $personnelId ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($choice['full_name'] ?? '')) ?>
                                <?php if (!empty($choice['service_label'])): ?>
                                    • <?= htmlspecialchars((string)$choice['service_label']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Service</label>
                    <select name="service_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:bg-white">
                        <option value="0">Sélectionner un service</option>
                        <?php foreach ($serviceOptions as $serviceOption): ?>
                            <option value="<?= (int)($serviceOption['id'] ?? 0) ?>" <?= (int)($serviceOption['id'] ?? 0) === $serviceId ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)($serviceOption['label'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">Charger</button>
                </div>
            </form>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1fr_1fr_1fr]">
            <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Lecture</div>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Horaires effectifs</h2>
                <p class="mt-2 text-sm text-slate-500">Utilisés par le moteur de planification automatique.</p>
                <div class="mt-4 space-y-2">
                    <?php foreach ($effectiveWorkWindows as $row): ?>
                        <div class="flex items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3">
                            <div>
                                <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)$row['label']) ?></div>
                                <div class="text-xs text-slate-400 uppercase tracking-wide"><?= htmlspecialchars((string)$row['source']) ?></div>
                            </div>
                            <div class="text-sm font-medium <?= !empty($row['enabled']) ? 'text-emerald-700' : 'text-slate-400' ?>">
                                <?= !empty($row['enabled']) ? htmlspecialchars((string)$row['start_time'] . ' → ' . (string)$row['end_time']) : 'Fermé' ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Employé</div>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Surcharge collaborateur</h2>
                <p class="mt-2 text-sm text-slate-500">Cible : <?= htmlspecialchars((string)($personnel['nom'] ?? 'Aucun collaborateur sélectionné')) ?></p>

                <?php if ($personnelId > 0): ?>
                    <div class="mt-4 rounded-2xl border border-indigo-100 bg-indigo-50/70 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-indigo-700">Seuil journalier</div>
                        <div class="mt-2 text-sm text-slate-700">Au-delà, une journée est signalée en surcharge dans l'emploi du temps.</div>
                        <form method="post" class="mt-3 flex flex-wrap items-end gap-3">
                            <input type="hidden" name="action" value="save_personnel_load_threshold" />
                            <input type="hidden" name="personnel_id" value="<?= (int)$personnelId ?>" />
                            <label class="text-sm text-slate-700">
                                <span class="block mb-1">Heures max / jour</span>
                                <input type="number" name="personnel_load_threshold_hours" min="1" max="24" step="0.25" value="<?= htmlspecialchars(number_format($personnelLoadThresholdHours, 2, '.', '')) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                            </label>
                            <button type="submit" class="rounded-2xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">Enregistrer le seuil</button>
                        </form>
                        <form method="post" class="mt-2">
                            <input type="hidden" name="action" value="reset_personnel_load_threshold" />
                            <input type="hidden" name="personnel_id" value="<?= (int)$personnelId ?>" />
                            <button type="submit" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Revenir au seuil service/défaut</button>
                        </form>
                    </div>

                    <form method="post" class="mt-4 space-y-2">
                        <input type="hidden" name="action" value="save_personnel_work_windows" />
                        <input type="hidden" name="personnel_id" value="<?= (int)$personnelId ?>" />
                        <?php foreach ($effectiveWorkWindows as $row): ?>
                            <div class="grid grid-cols-[auto,1fr,1fr] items-center gap-2 rounded-2xl border border-slate-100 px-3 py-3">
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="personnel_window_enabled[<?= (int)$row['weekday'] ?>]" value="1" <?= !empty($row['enabled']) ? 'checked' : '' ?> />
                                    <span><?= htmlspecialchars((string)$row['label']) ?></span>
                                </label>
                                <input type="time" name="personnel_window_start[<?= (int)$row['weekday'] ?>]" value="<?= htmlspecialchars((string)$row['start_time']) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                                <input type="time" name="personnel_window_end[<?= (int)$row['weekday'] ?>]" value="<?= htmlspecialchars((string)$row['end_time']) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                            </div>
                        <?php endforeach; ?>
                        <div class="flex flex-wrap gap-2 pt-2">
                            <button type="submit" class="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">Enregistrer employé</button>
                        </div>
                    </form>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="action" value="reset_personnel_work_windows" />
                        <input type="hidden" name="personnel_id" value="<?= (int)$personnelId ?>" />
                        <button type="submit" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Revenir à l'héritage service/défaut</button>
                    </form>
                <?php else: ?>
                    <div class="mt-4 rounded-2xl bg-slate-50 px-4 py-6 text-sm text-slate-500">Choisissez un collaborateur pour activer l'édition.</div>
                <?php endif; ?>
            </div>

            <div class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Service</div>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Surcharge service</h2>
                <p class="mt-2 text-sm text-slate-500">Service ciblé : <?= htmlspecialchars($serviceId > 0 ? ($selectedServiceLabel !== '' ? $selectedServiceLabel : ('#' . $serviceId)) : 'Aucun service sélectionné') ?></p>

                <?php if ($serviceId > 0): ?>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-700">Seuil journalier service</div>
                        <div class="mt-2 text-sm text-slate-600">Valeur héritée par défaut par les collaborateurs du service. Défaut global : <?= htmlspecialchars(number_format($defaultLoadThresholdHours, 2, ',', ' ')) ?> h.</div>
                        <form method="post" class="mt-3 flex flex-wrap items-end gap-3">
                            <input type="hidden" name="action" value="save_service_load_threshold" />
                            <input type="hidden" name="personnel_id" value="<?= (int)$personnelId ?>" />
                            <input type="hidden" name="service_id" value="<?= (int)$serviceId ?>" />
                            <label class="text-sm text-slate-700">
                                <span class="block mb-1">Heures max / jour</span>
                                <input type="number" name="service_load_threshold_hours" min="1" max="24" step="0.25" value="<?= htmlspecialchars(number_format($serviceLoadThresholdHours, 2, '.', '')) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                            </label>
                            <button type="submit" class="rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800">Enregistrer le seuil</button>
                        </form>
                        <form method="post" class="mt-2">
                            <input type="hidden" name="action" value="reset_service_load_threshold" />
                            <input type="hidden" name="personnel_id" value="<?= (int)$personnelId ?>" />
                            <input type="hidden" name="service_id" value="<?= (int)$serviceId ?>" />
                            <button type="submit" class="rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Revenir au défaut global</button>
                        </form>
                    </div>

                    <form method="post" class="mt-4 space-y-2">
                        <input type="hidden" name="action" value="save_service_work_windows" />
                        <input type="hidden" name="personnel_id" value="<?= (int)$personnelId ?>" />
                        <input type="hidden" name="service_id" value="<?= (int)$serviceId ?>" />
                        <?php foreach ($serviceWorkWindows as $row): ?>
                            <div class="grid grid-cols-[auto,1fr,1fr] items-center gap-2 rounded-2xl border border-slate-100 px-3 py-3">
                                <label class="flex items-center gap-2 text-sm text-slate-700">
                                    <input type="checkbox" name="service_window_enabled[<?= (int)$row['weekday'] ?>]" value="1" <?= !empty($row['enabled']) ? 'checked' : '' ?> />
                                    <span><?= htmlspecialchars((string)$row['label']) ?></span>
                                </label>
                                <input type="time" name="service_window_start[<?= (int)$row['weekday'] ?>]" value="<?= htmlspecialchars((string)$row['start_time']) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                                <input type="time" name="service_window_end[<?= (int)$row['weekday'] ?>]" value="<?= htmlspecialchars((string)$row['end_time']) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" />
                            </div>
                        <?php endforeach; ?>
                        <div class="flex flex-wrap gap-2 pt-2">
                            <button type="submit" class="rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">Enregistrer service</button>
                        </div>
                    </form>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="action" value="reset_service_work_windows" />
                        <input type="hidden" name="personnel_id" value="<?= (int)$personnelId ?>" />
                        <input type="hidden" name="service_id" value="<?= (int)$serviceId ?>" />
                        <button type="submit" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Revenir au défaut global</button>
                    </form>
                <?php else: ?>
                    <div class="mt-4 rounded-2xl bg-slate-50 px-4 py-6 text-sm text-slate-500">Choisissez un service ou un collaborateur rattaché à un service.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
