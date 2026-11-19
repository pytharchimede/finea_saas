<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';

$pdo = \Database::getConnection();
$actorId = (int) ($_SESSION['user_id'] ?? 0);
$actorName = trim((string) ($_SESSION['user_name'] ?? ''));
if ($actorName === '') {
    $actorName = trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
}

function notifMatrixEnsureTables(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_notification_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            event_key VARCHAR(80) NOT NULL,
            channel_email TINYINT(1) NOT NULL DEFAULT 0,
            channel_system TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_event (user_id, event_key),
            KEY idx_user_notification_preferences_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function notifMatrixBaseEvents(): array
{
    return [
        'employee_absences' => ['group' => 'RH', 'label' => 'Absences RH'],
        'employee_absences.absence_marked' => ['group' => 'RH', 'label' => 'Absence detectee'],
        'employee_absences.absence_reminder_24h' => ['group' => 'RH', 'label' => 'Rappel absence 24h'],
        'employee_absences.absence_threshold_3' => ['group' => 'RH', 'label' => 'Alerte seuil absences'],
        'legal_requests' => ['group' => 'Demandes', 'label' => 'Toutes demandes employes'],
        'legal_request_submitted.permission_absence' => ['group' => 'Demandes', 'label' => 'Nouvelle permission'],
        'legal_request_submitted.demande_explication' => ['group' => 'Demandes', 'label' => 'Nouvelle demande explication'],
        'legal_request_submitted.feb' => ['group' => 'FEB', 'label' => 'Nouvelle FEB'],
        'legal_request_processed.approved' => ['group' => 'Demandes', 'label' => 'Demande approuvee'],
        'legal_request_processed.rejected' => ['group' => 'Demandes', 'label' => 'Demande refusee'],
        'finance_decaissement' => ['group' => 'Finance', 'label' => 'Toutes notifications finance'],
        'decaissement_submitted.default' => ['group' => 'Finance', 'label' => 'Nouvelle fiche decaissement'],
        'decaissement_submitted.epi' => ['group' => 'Finance', 'label' => 'Nouvelle fiche EPI'],
        'decaissement_processed.approved' => ['group' => 'Finance', 'label' => 'Fiche approuvee'],
        'decaissement_processed.rejected' => ['group' => 'Finance', 'label' => 'Fiche refusee'],
        'broken_debourse_links_detected.alert' => ['group' => 'Finance', 'label' => 'Liens debourses casses'],
        'mailbox' => ['group' => 'Communication', 'label' => 'Messagerie et documents'],
        'planning_operations' => ['group' => 'Planning', 'label' => 'Planning et taches'],
        'logistics_operations' => ['group' => 'Logistique', 'label' => 'Operations logistiques'],
        'attendance_devices' => ['group' => 'RH', 'label' => 'Supervision badgeuse'],
        'attendance_device_alert.api_unreachable' => ['group' => 'RH', 'label' => 'Badgeuse API inaccessible'],
        'attendance_device_alert.offline' => ['group' => 'RH', 'label' => 'Badgeuse deconnectee'],
        'attendance_device_alert.clock_drift' => ['group' => 'RH', 'label' => 'Lecture directe horloge badgeuse'],
        'attendance_device_alert.server_time_mismatch' => ['group' => 'RH', 'label' => 'Decalage heure serveur/base'],
        'rh_management' => ['group' => 'RH', 'label' => 'Organisation RH'],
        'supplier_portal' => ['group' => 'Fournisseurs', 'label' => 'Portail fournisseurs'],
    ];
}

function notifMatrixEvents(PDO $pdo): array
{
    $events = notifMatrixBaseEvents();
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS system_notification_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_key VARCHAR(64) NOT NULL,
            sub_key VARCHAR(64) NULL,
            label VARCHAR(255) NOT NULL,
            title_template VARCHAR(255) NULL,
            message_template TEXT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            recipient_team VARCHAR(32) NOT NULL DEFAULT "rh",
            channels_json TEXT NULL,
            excluded_emails_json TEXT NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_rule (event_key, sub_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $rows = $pdo->query('SELECT event_key, sub_key, label FROM system_notification_rules ORDER BY event_key, sub_key')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $event = strtolower(trim((string) ($row['event_key'] ?? '')));
            $sub = strtolower(trim((string) ($row['sub_key'] ?? '')));
            if ($event === '') {
                continue;
            }
            $key = $sub !== '' ? ($event . '.' . $sub) : $event;
            $events[$key] = [
                'group' => notifMatrixEventGroup($event),
                'label' => trim((string) ($row['label'] ?? '')) !== '' ? (string) $row['label'] : $key,
            ];
        }
    } catch (Throwable $e) {
        // La matrice reste utilisable avec le catalogue de base.
    }

    uasort($events, static function (array $a, array $b): int {
        return strcmp(($a['group'] ?? '') . ($a['label'] ?? ''), ($b['group'] ?? '') . ($b['label'] ?? ''));
    });
    return $events;
}

function notifMatrixEventGroup(string $event): string
{
    if (strpos($event, 'logistics_') === 0) {
        return 'Logistique';
    }
    if (strpos($event, 'decaissement_') === 0 || $event === 'broken_debourse_links_detected') {
        return 'Finance';
    }
    if (strpos($event, 'legal_request_') === 0) {
        return 'Demandes';
    }
    if (strpos($event, 'schedule_') === 0) {
        return 'Planning';
    }
    if (strpos($event, 'communication_') === 0 || $event === 'document_share_created') {
        return 'Communication';
    }
    if (strpos($event, 'supplier_') === 0) {
        return 'Fournisseurs';
    }
    if (strpos($event, 'rh_') === 0 || strpos($event, 'employee_absence') === 0) {
        return 'RH';
    }
    if ($event === 'attendance_device_alert') {
        return 'RH';
    }
    if (strpos($event, 'crm_') === 0) {
        return 'CRM';
    }
    return 'General';
}

function notifMatrixUpsert(PDO $pdo, int $userId, string $eventKey, ?int $email, ?int $system): void
{
    $eventKey = strtolower(trim($eventKey));
    if ($userId <= 0 || $eventKey === '') {
        return;
    }

    $emailValue = $email ?? 0;
    $systemValue = $system ?? 1;
    $updates = ['updated_at = NOW()'];
    if ($email !== null) {
        $updates[] = 'channel_email = VALUES(channel_email)';
    }
    if ($system !== null) {
        $updates[] = 'channel_system = VALUES(channel_system)';
    }

    $stmt = $pdo->prepare('
        INSERT INTO user_notification_preferences (user_id, event_key, channel_email, channel_system, updated_at)
        VALUES (:user_id, :event_key, :email, :system, NOW())
        ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
    );
    $stmt->execute([
        'user_id' => $userId,
        'event_key' => $eventKey,
        'email' => $emailValue,
        'system' => $systemValue,
    ]);
}

function notifMatrixWantsJson(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || !empty($_POST['ajax']);
}

function notifMatrixRespond(string $type, string $message, array $payload = []): void
{
    if (notifMatrixWantsJson()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $type !== 'error', 'type' => $type, 'message' => $message] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $params = $_GET;
    unset($params['flash_type'], $params['flash']);
    $params['flash_type'] = $type;
    $params['flash'] = $message;
    header('Location: notification_matrix.php?' . http_build_query($params));
    exit;
}

notifMatrixEnsureTables($pdo);
$events = notifMatrixEvents($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = strtolower(trim((string) ($_POST['action'] ?? '')));
    try {
        if (in_array($action, ['disable_all', 'enable_all', 'disable_email_all', 'enable_email_all', 'disable_system_all', 'enable_system_all'], true)) {
            $targetValue = $action === 'enable_all' ? 1 : 0;
            if (in_array($action, ['enable_email_all', 'enable_system_all'], true)) {
                $targetValue = 1;
            }
            $emailValue = in_array($action, ['disable_all', 'enable_all', 'disable_email_all', 'enable_email_all'], true) ? $targetValue : null;
            $systemValue = in_array($action, ['disable_all', 'enable_all', 'disable_system_all', 'enable_system_all'], true) ? $targetValue : null;
            $userIds = array_map('intval', $pdo->query('SELECT id FROM user_devis_banamur ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $pdo->beginTransaction();
            foreach ($userIds as $uid) {
                foreach (array_keys($events) as $eventKey) {
                    notifMatrixUpsert($pdo, $uid, (string) $eventKey, $emailValue, $systemValue);
                }
            }
            $pdo->commit();
            \AuditTrail::log('admin_notification_matrix_bulk_update', [
                'module' => 'admin',
                'action' => $action,
                'entity_type' => 'user_notification_preferences',
                'entity_label' => 'Matrice notifications personnel',
                'user_id' => $actorId,
                'user_name' => $actorName,
                'details' => ['users' => count($userIds), 'events' => count($events), 'email_value' => $emailValue, 'system_value' => $systemValue],
            ], $pdo);
            $messages = [
                'disable_all' => 'Toutes les notifications FINEA ont ete desactivees.',
                'enable_all' => 'Toutes les notifications ont ete reactivees.',
                'disable_email_all' => 'Tous les emails de notification ont ete desactives.',
                'enable_email_all' => 'Tous les emails de notification ont ete reactives.',
                'disable_system_all' => 'Toutes les notifications systeme ont ete desactivees.',
                'enable_system_all' => 'Toutes les notifications systeme ont ete reactivees.',
            ];
            notifMatrixRespond('success', $messages[$action] ?? 'Preferences mises a jour.', ['scope' => 'all', 'email_value' => $emailValue, 'system_value' => $systemValue]);
        }

        if ($action === 'toggle') {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $eventKey = strtolower(trim((string) ($_POST['event_key'] ?? '')));
            $channel = strtolower(trim((string) ($_POST['channel'] ?? '')));
            $value = !empty($_POST['value']) ? 1 : 0;
            if ($userId <= 0 || !isset($events[$eventKey]) || !in_array($channel, ['email', 'system'], true)) {
                throw new RuntimeException('Parametre invalide.');
            }
            notifMatrixUpsert($pdo, $userId, $eventKey, $channel === 'email' ? $value : null, $channel === 'system' ? $value : null);
            \AuditTrail::log('admin_notification_matrix_toggle', [
                'module' => 'admin',
                'action' => 'toggle_notification_preference',
                'entity_type' => 'user_account',
                'entity_id' => (string) $userId,
                'entity_label' => $eventKey . ':' . $channel,
                'user_id' => $actorId,
                'user_name' => $actorName,
                'details' => ['event_key' => $eventKey, 'channel' => $channel, 'value' => $value],
            ], $pdo);
            notifMatrixRespond('success', 'Preference mise a jour.', ['scope' => 'toggle', 'user_id' => $userId, 'event_key' => $eventKey, 'channel' => $channel, 'value' => $value]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        notifMatrixRespond('error', 'Action impossible : ' . $e->getMessage());
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
$groupFilter = trim((string) ($_GET['group'] ?? ''));
$groups = array_values(array_unique(array_map(static fn(array $event): string => (string) ($event['group'] ?? 'General'), $events)));
sort($groups);

$where = [];
$params = [];
if ($q !== '') {
    $where[] = "(CONCAT(COALESCE(prenom,''), ' ', COALESCE(nom,''), ' ', COALESCE(mail_pro,'')) LIKE :q)";
    $params['q'] = '%' . $q . '%';
}
$sql = 'SELECT id, prenom, nom, mail_pro, active FROM user_devis_banamur';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY active DESC, prenom ASC, nom ASC LIMIT 120';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($groupFilter !== '') {
    $events = array_filter($events, static fn(array $event): bool => (string) ($event['group'] ?? '') === $groupFilter);
}

$userIds = array_map(static fn(array $user): int => (int) ($user['id'] ?? 0), $users);
$prefs = [];
if ($userIds) {
    $in = [];
    $prefParams = [];
    foreach ($userIds as $idx => $uid) {
        $key = ':u' . $idx;
        $in[] = $key;
        $prefParams[$key] = $uid;
    }
    $prefStmt = $pdo->prepare('SELECT user_id, event_key, channel_email, channel_system FROM user_notification_preferences WHERE user_id IN (' . implode(',', $in) . ')');
    $prefStmt->execute($prefParams);
    foreach ($prefStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $prefs[(int) ($row['user_id'] ?? 0)][strtolower((string) ($row['event_key'] ?? ''))] = [
            'email' => (int) ($row['channel_email'] ?? 0) === 1,
            'system' => (int) ($row['channel_system'] ?? 0) === 1,
            'custom' => true,
        ];
    }
}

$totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM user_devis_banamur')->fetchColumn();
$totalDisabledEmail = (int) $pdo->query('SELECT COUNT(*) FROM user_notification_preferences WHERE channel_email = 0')->fetchColumn();
$totalDisabledSystem = (int) $pdo->query('SELECT COUNT(*) FROM user_notification_preferences WHERE channel_system = 0')->fetchColumn();

$pageTitle = 'Matrice notifications personnel';
$activeMenu = 'admin';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-slate-50">
    <div class="mx-auto max-w-[1800px] px-4 py-8">
        <div class="rounded-[2rem] border border-white bg-gradient-to-br from-slate-950 via-indigo-950 to-slate-900 p-6 text-white shadow-xl">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.2em] text-indigo-100">
                        <i class="fas fa-bell-slash"></i> Administration
                    </div>
                    <h1 class="mt-4 text-3xl font-black">Matrice notifications FINEA</h1>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-200">Visualiser et piloter en un seul endroit les notifications email et systeme de tout le personnel. Les utilisateurs peuvent toujours reactiver leurs preferences depuis leur profil.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="dashboard_admin.php" class="inline-flex items-center rounded-2xl border border-white/15 px-4 py-3 text-sm font-bold text-white hover:bg-white/10"><i class="fas fa-arrow-left mr-2"></i>Dashboard admin</a>
                    <a href="notifications_settings.php" class="inline-flex items-center rounded-2xl bg-white px-4 py-3 text-sm font-bold text-slate-900 hover:bg-indigo-50"><i class="fas fa-sliders mr-2"></i>Regles d'envoi</a>
                </div>
            </div>
        </div>

        <?php if (!empty($_GET['flash'])): ?>
            <?php $isError = (string) ($_GET['flash_type'] ?? '') === 'error'; ?>
            <div class="mt-5 rounded-2xl border <?= $isError ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?> px-5 py-4 text-sm font-bold">
                <?= htmlspecialchars((string) $_GET['flash'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="mt-6 grid gap-4 md:grid-cols-4">
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Comptes</div>
                <div class="mt-2 text-3xl font-black text-slate-950"><?= number_format($totalUsers, 0, ',', ' ') ?></div>
            </div>
            <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">Evenements</div>
                <div class="mt-2 text-3xl font-black text-indigo-700"><?= number_format(count($events), 0, ',', ' ') ?></div>
            </div>
            <div class="rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <div class="text-xs font-bold uppercase tracking-[0.18em] text-amber-700">Emails coupes</div>
                <div class="mt-2 text-3xl font-black text-amber-900"><?= number_format($totalDisabledEmail, 0, ',', ' ') ?></div>
            </div>
            <div class="rounded-3xl border border-rose-200 bg-rose-50 p-5 shadow-sm">
                <div class="text-xs font-bold uppercase tracking-[0.18em] text-rose-700">Systeme coupe</div>
                <div class="mt-2 text-3xl font-black text-rose-900"><?= number_format($totalDisabledSystem, 0, ',', ' ') ?></div>
            </div>
        </div>

        <div class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                <form method="get" class="grid flex-1 gap-3 md:grid-cols-[1fr_auto_auto] md:items-end">
                    <label>
                        <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Recherche personnel</span>
                        <input name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="Nom, email, login..." class="mt-1 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-indigo-300 focus:ring-4 focus:ring-indigo-100">
                    </label>
                    <label>
                        <span class="text-xs font-bold uppercase tracking-[0.16em] text-slate-500">Famille</span>
                        <select name="group" class="mt-1 h-12 rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-indigo-300 focus:ring-4 focus:ring-indigo-100">
                            <option value="">Toutes</option>
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?>" <?= $groupFilter === $group ? 'selected' : '' ?>><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="h-12 rounded-2xl bg-slate-900 px-5 text-sm font-bold text-white hover:bg-slate-800"><i class="fas fa-filter mr-2"></i>Filtrer</button>
                </form>
                <div class="flex flex-wrap gap-3">
                    <form method="post" data-notif-async="1" data-confirm-message="Desactiver tous les emails de notification pour tous les comptes ?">
                        <input type="hidden" name="action" value="disable_email_all">
                        <button class="h-12 rounded-2xl bg-rose-600 px-4 text-sm font-black text-white shadow-sm hover:bg-rose-700"><i class="fas fa-envelope-circle-xmark mr-2"></i>Couper emails</button>
                    </form>
                    <form method="post" data-notif-async="1" data-confirm-message="Desactiver toutes les notifications systeme pour tous les comptes ?">
                        <input type="hidden" name="action" value="disable_system_all">
                        <button class="h-12 rounded-2xl bg-slate-800 px-4 text-sm font-black text-white shadow-sm hover:bg-slate-900"><i class="fas fa-bell-slash mr-2"></i>Couper systeme</button>
                    </form>
                    <form method="post" data-notif-async="1" data-confirm-message="Reactiver tous les emails de notification pour tous les comptes ?">
                        <input type="hidden" name="action" value="enable_email_all">
                        <button class="h-12 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 text-sm font-black text-emerald-800 hover:bg-emerald-100"><i class="fas fa-envelope-circle-check mr-2"></i>Reactiver emails</button>
                    </form>
                    <form method="post" data-notif-async="1" data-confirm-message="Reactiver toutes les notifications systeme pour tous les comptes ?">
                        <input type="hidden" name="action" value="enable_system_all">
                        <button class="h-12 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 text-sm font-black text-indigo-800 hover:bg-indigo-100"><i class="fas fa-bell mr-2"></i>Reactiver systeme</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 px-5 py-4">
                <h2 class="text-lg font-black text-slate-950">Matrice de preferences</h2>
                <p class="mt-1 text-sm text-slate-500">Les interrupteurs bleus/verts sont actifs. Les cellules sans reglage explicite utilisent le comportement par defaut de FINEA.</p>
            </div>
            <div class="overflow-auto">
                <table class="min-w-full border-separate border-spacing-0 text-sm">
                    <thead class="sticky top-0 z-10 bg-slate-100 text-left text-xs uppercase tracking-[0.16em] text-slate-500">
                        <tr>
                            <th class="sticky left-0 z-20 min-w-[260px] border-b border-slate-200 bg-slate-100 px-4 py-3">Personnel</th>
                            <?php foreach ($events as $eventKey => $event): ?>
                                <th class="min-w-[190px] border-b border-slate-200 px-3 py-3">
                                    <span class="block rounded-full bg-white px-2 py-1 text-[10px] font-black text-slate-500"><?= htmlspecialchars((string) ($event['group'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="mt-2 block font-black normal-case tracking-normal text-slate-800"><?= htmlspecialchars((string) ($event['label'] ?? $eventKey), ENT_QUOTES, 'UTF-8') ?></span>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$users): ?>
                            <tr><td class="px-4 py-8 text-center text-slate-500" colspan="<?= count($events) + 1 ?>">Aucun utilisateur trouve.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($users as $user): ?>
                            <?php
                            $uid = (int) ($user['id'] ?? 0);
                            $label = trim((string) ($user['prenom'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
                            if ($label === '') {
                                $label = 'Utilisateur #' . $uid;
                            }
                            ?>
                            <tr class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="sticky left-0 z-10 border-b border-slate-100 bg-white px-4 py-4">
                                    <div class="font-black text-slate-950"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string) ($user['mail_pro'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-2 inline-flex rounded-full px-2 py-1 text-[11px] font-bold <?= !empty($user['active']) ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>"><?= !empty($user['active']) ? 'Actif' : 'Inactif' ?></div>
                                </td>
                                <?php foreach ($events as $eventKey => $event): ?>
                                    <?php
                                    $pref = $prefs[$uid][(string) $eventKey] ?? null;
                                    $emailOn = $pref ? (bool) $pref['email'] : true;
                                    $systemOn = $pref ? (bool) $pref['system'] : true;
                                    ?>
                                    <td class="border-b border-slate-100 px-3 py-4 align-top">
                                        <div class="grid gap-2">
                                            <form method="post" class="inline-flex" data-notif-async="1" data-notif-toggle="1" data-channel="email">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <input type="hidden" name="event_key" value="<?= htmlspecialchars((string) $eventKey, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="channel" value="email">
                                                <input type="hidden" name="value" value="<?= $emailOn ? '0' : '1' ?>">
                                                <button data-notif-button="1" class="inline-flex w-full items-center justify-between gap-2 rounded-2xl border px-3 py-2 text-xs font-black transition <?= $emailOn ? 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' : 'border-slate-200 bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">
                                                    <span>Email</span><span class="h-2.5 w-2.5 rounded-full <?= $emailOn ? 'bg-emerald-500' : 'bg-slate-400' ?>"></span>
                                                </button>
                                            </form>
                                            <form method="post" class="inline-flex" data-notif-async="1" data-notif-toggle="1" data-channel="system">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <input type="hidden" name="event_key" value="<?= htmlspecialchars((string) $eventKey, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="channel" value="system">
                                                <input type="hidden" name="value" value="<?= $systemOn ? '0' : '1' ?>">
                                                <button data-notif-button="1" class="inline-flex w-full items-center justify-between gap-2 rounded-2xl border px-3 py-2 text-xs font-black transition <?= $systemOn ? 'border-indigo-200 bg-indigo-50 text-indigo-800 hover:bg-indigo-100' : 'border-slate-200 bg-slate-100 text-slate-500 hover:bg-slate-200' ?>">
                                                    <span>Systeme</span><span class="h-2.5 w-2.5 rounded-full <?= $systemOn ? 'bg-indigo-500' : 'bg-slate-400' ?>"></span>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        function showToast(message, type) {
            var existing = document.querySelector('[data-notif-toast]');
            if (existing) {
                existing.remove();
            }
            var toast = document.createElement('div');
            toast.setAttribute('data-notif-toast', '1');
            toast.className = 'fixed bottom-5 right-5 z-[9999] max-w-sm rounded-2xl px-5 py-4 text-sm font-black shadow-2xl ' + (type === 'error' ? 'bg-rose-600 text-white' : 'bg-slate-950 text-white');
            toast.textContent = message || (type === 'error' ? 'Action impossible.' : 'Preference enregistree.');
            document.body.appendChild(toast);
            window.setTimeout(function() {
                toast.remove();
            }, 2800);
        }

        function setToggleState(form, isOn) {
            var channel = form.getAttribute('data-channel') || '';
            var button = form.querySelector('[data-notif-button]');
            var valueInput = form.querySelector('input[name="value"]');
            if (!button || !valueInput) {
                return;
            }
            valueInput.value = isOn ? '0' : '1';
            var dot = button.querySelector('span:last-child');
            button.className = 'inline-flex w-full items-center justify-between gap-2 rounded-2xl border px-3 py-2 text-xs font-black transition ';
            if (isOn && channel === 'email') {
                button.className += 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100';
                if (dot) dot.className = 'h-2.5 w-2.5 rounded-full bg-emerald-500';
                return;
            }
            if (isOn && channel === 'system') {
                button.className += 'border-indigo-200 bg-indigo-50 text-indigo-800 hover:bg-indigo-100';
                if (dot) dot.className = 'h-2.5 w-2.5 rounded-full bg-indigo-500';
                return;
            }
            button.className += 'border-slate-200 bg-slate-100 text-slate-500 hover:bg-slate-200';
            if (dot) dot.className = 'h-2.5 w-2.5 rounded-full bg-slate-400';
        }

        function setAllToggles(isOn, channel) {
            document.querySelectorAll('form[data-notif-toggle="1"]').forEach(function(form) {
                if (channel && form.getAttribute('data-channel') !== channel) {
                    return;
                }
                setToggleState(form, isOn);
            });
        }

        document.querySelectorAll('form[data-notif-async="1"]').forEach(function(form) {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                var confirmMessage = form.getAttribute('data-confirm-message') || '';
                if (confirmMessage && !window.confirm(confirmMessage)) {
                    return;
                }

                var button = form.querySelector('button');
                var originalHtml = button ? button.innerHTML : '';
                if (button) {
                    button.disabled = true;
                    button.classList.add('opacity-70', 'cursor-wait');
                }

                var data = new FormData(form);
                data.set('ajax', '1');

                fetch(window.location.href, {
                    method: 'POST',
                    body: data,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                })
                    .then(function(response) {
                        return response.json();
                    })
                    .then(function(payload) {
                        if (!payload || !payload.ok) {
                            throw new Error((payload && payload.message) ? payload.message : 'Action impossible.');
                        }
                        if (payload.scope === 'toggle') {
                            setToggleState(form, String(payload.value) === '1');
                        } else if (payload.scope === 'all') {
                            if (payload.email_value !== null && typeof payload.email_value !== 'undefined') {
                                setAllToggles(String(payload.email_value) === '1', 'email');
                            }
                            if (payload.system_value !== null && typeof payload.system_value !== 'undefined') {
                                setAllToggles(String(payload.system_value) === '1', 'system');
                            }
                        }
                        showToast(payload.message || 'Preference enregistree.', 'success');
                    })
                    .catch(function(error) {
                        showToast(error.message || 'Action impossible.', 'error');
                    })
                    .finally(function() {
                        if (button) {
                            button.disabled = false;
                            button.classList.remove('opacity-70', 'cursor-wait');
                            if (originalHtml) {
                                button.innerHTML = originalHtml;
                            }
                        }
                    });
            });
        });
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
