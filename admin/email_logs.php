<?php

require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';

$pdo = Database::getConnection();

$pdo->exec("
CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(255),
    recipient_name VARCHAR(255),
    subject VARCHAR(255),
    body_html MEDIUMTEXT,
    body_plain MEDIUMTEXT,
    status VARCHAR(20),
    error_message TEXT,
    sent_at DATETIME,
    opened_at DATETIME NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    tracking_token VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

function emailLogsValue(string $key): string
{
    return trim((string)($_GET[$key] ?? ''));
}

function emailLogsDateExpr(): string
{
    return 'COALESCE(e.sent_at, e.created_at)';
}

function emailLogsNormalizePersonnelSearch(string $value): string
{
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $matches)) {
        return $matches[0];
    }

    return $value;
}

function emailLogsBuildWhere(): array
{
    $where = [];
    $params = [];
    $dateExpr = emailLogsDateExpr();

    $exactDate = emailLogsValue('exact_date');
    if ($exactDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $exactDate)) {
        $where[] = "DATE($dateExpr) = :exact_date";
        $params[':exact_date'] = $exactDate;
    }

    $dateFrom = emailLogsValue('date_from');
    if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
        $where[] = "$dateExpr >= :date_from";
        $params[':date_from'] = $dateFrom . ' 00:00:00';
    }

    $dateTo = emailLogsValue('date_to');
    if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        $where[] = "$dateExpr <= :date_to";
        $params[':date_to'] = $dateTo . ' 23:59:59';
    }

    $subject = emailLogsValue('subject');
    if ($subject !== '') {
        $where[] = 'e.subject LIKE :subject';
        $params[':subject'] = '%' . $subject . '%';
    }

    $personnel = emailLogsNormalizePersonnelSearch(emailLogsValue('personnel'));
    if ($personnel !== '') {
        $where[] = "(
            e.recipient_email LIKE :personnel
            OR e.recipient_name LIKE :personnel
            OR EXISTS (
                SELECT 1
                FROM personnel p
                WHERE LOWER(TRIM(p.email_personnel_soignant)) = LOWER(TRIM(e.recipient_email))
                  AND (p.nom_personnel_soignant LIKE :personnel OR p.email_personnel_soignant LIKE :personnel)
            )
        )";
        $params[':personnel'] = '%' . $personnel . '%';
    }

    $serviceId = emailLogsValue('service_id');
    if ($serviceId !== '' && ctype_digit($serviceId)) {
        $where[] = "EXISTS (
            SELECT 1
            FROM personnel p
            WHERE LOWER(TRIM(p.email_personnel_soignant)) = LOWER(TRIM(e.recipient_email))
              AND p.service_id = :service_id
        )";
        $params[':service_id'] = (int)$serviceId;
    }

    $openStatus = emailLogsValue('open_status');
    if ($openStatus === 'opened') {
        $where[] = 'e.opened_at IS NOT NULL';
    } elseif ($openStatus === 'unopened') {
        $where[] = 'e.opened_at IS NULL';
    }

    $deliveryStatus = emailLogsValue('delivery_status');
    if (in_array($deliveryStatus, ['sent', 'failed'], true)) {
        $where[] = 'e.status = :delivery_status';
        $params[':delivery_status'] = $deliveryStatus;
    }

    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}

function emailLogsFetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function emailLogsFetchOne(PDO $pdo, string $sql, array $params = []): array
{
    $rows = emailLogsFetchAll($pdo, $sql, $params);
    return $rows[0] ?? [];
}

[$whereSql, $filterParams] = emailLogsBuildWhere();
$dateExpr = emailLogsDateExpr();

$stats = emailLogsFetchOne($pdo, "
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN e.status = 'sent' THEN 1 ELSE 0 END) AS sent_total,
        SUM(CASE WHEN e.status = 'failed' THEN 1 ELSE 0 END) AS failed_total,
        SUM(CASE WHEN e.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened_total,
        SUM(CASE WHEN e.opened_at IS NULL THEN 1 ELSE 0 END) AS unopened_total,
        SUM(CASE WHEN DATE($dateExpr) = CURDATE() THEN 1 ELSE 0 END) AS today_total,
        SUM(CASE WHEN $dateExpr >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS last_7_days_total
    FROM email_logs e
    $whereSql
", $filterParams);

$totalEmails = (int)($stats['total'] ?? 0);
$sentEmails = (int)($stats['sent_total'] ?? 0);
$failedEmails = (int)($stats['failed_total'] ?? 0);
$openedEmails = (int)($stats['opened_total'] ?? 0);
$unopenedEmails = (int)($stats['unopened_total'] ?? 0);
$todayEmails = (int)($stats['today_total'] ?? 0);
$lastSevenDaysEmails = (int)($stats['last_7_days_total'] ?? 0);
$openRate = $sentEmails > 0 ? round(($openedEmails / $sentEmails) * 100, 1) : 0;
$failureRate = $totalEmails > 0 ? round(($failedEmails / $totalEmails) * 100, 1) : 0;

$emailsPerDay = emailLogsFetchAll($pdo, "
    SELECT DATE($dateExpr) AS day, COUNT(*) AS total, SUM(CASE WHEN e.status = 'sent' THEN 1 ELSE 0 END) AS sent_total
    FROM email_logs e
    $whereSql
    GROUP BY DATE($dateExpr)
    ORDER BY day ASC
", $filterParams);

$errorsPerDay = emailLogsFetchAll($pdo, "
    SELECT DATE($dateExpr) AS day, SUM(CASE WHEN e.status = 'failed' THEN 1 ELSE 0 END) AS total
    FROM email_logs e
    $whereSql
    GROUP BY DATE($dateExpr)
    ORDER BY day ASC
", $filterParams);

$statusStats = emailLogsFetchAll($pdo, "
    SELECT COALESCE(NULLIF(e.status, ''), 'unknown') AS status_label, COUNT(*) AS total
    FROM email_logs e
    $whereSql
    GROUP BY COALESCE(NULLIF(e.status, ''), 'unknown')
    ORDER BY total DESC
", $filterParams);

$topSubjects = emailLogsFetchAll($pdo, "
    SELECT COALESCE(NULLIF(e.subject, ''), 'Sans sujet') AS subject, COUNT(*) AS total
    FROM email_logs e
    $whereSql
    GROUP BY COALESCE(NULLIF(e.subject, ''), 'Sans sujet')
    ORDER BY total DESC, subject ASC
    LIMIT 8
", $filterParams);

$emails = emailLogsFetchAll($pdo, "
    SELECT
        e.*,
        (
            SELECT p.nom_personnel_soignant
            FROM personnel p
            WHERE LOWER(TRIM(p.email_personnel_soignant)) = LOWER(TRIM(e.recipient_email))
            ORDER BY p.id_personnel_soignant DESC
            LIMIT 1
        ) AS personnel_name,
        (
            SELECT s.lib_service
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            WHERE LOWER(TRIM(p.email_personnel_soignant)) = LOWER(TRIM(e.recipient_email))
            ORDER BY p.id_personnel_soignant DESC
            LIMIT 1
        ) AS service_label
    FROM email_logs e
    $whereSql
    ORDER BY $dateExpr DESC, e.id DESC
", $filterParams);

$subjects = emailLogsFetchAll($pdo, "
    SELECT DISTINCT subject
    FROM email_logs
    WHERE subject IS NOT NULL AND TRIM(subject) <> ''
    ORDER BY subject ASC
");

$personnelChoices = emailLogsFetchAll($pdo, "
    SELECT DISTINCT
        COALESCE(NULLIF(TRIM(p.nom_personnel_soignant), ''), NULLIF(TRIM(e.recipient_name), ''), e.recipient_email) AS label,
        e.recipient_email AS email
    FROM email_logs e
    LEFT JOIN personnel p ON LOWER(TRIM(p.email_personnel_soignant)) = LOWER(TRIM(e.recipient_email))
    WHERE e.recipient_email IS NOT NULL AND TRIM(e.recipient_email) <> ''
    ORDER BY label ASC, email ASC
");

$services = emailLogsFetchAll($pdo, "
    SELECT DISTINCT s.id_service AS id, s.lib_service AS label
    FROM email_logs e
    INNER JOIN personnel p ON LOWER(TRIM(p.email_personnel_soignant)) = LOWER(TRIM(e.recipient_email))
    INNER JOIN service s ON s.id_service = p.service_id
    ORDER BY s.lib_service ASC
");

$openStats = [
    'opened' => $openedEmails,
    'unopened' => $unopenedEmails,
    'total' => $totalEmails,
];

$pageTitle = "Centre de messagerie";
$activeMenu = "";

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

?>

<div class="min-h-screen bg-slate-50 pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col gap-4 border-b border-slate-200 pb-6 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-indigo-600">Administration</p>
                <h1 class="mt-2 text-3xl font-bold text-slate-950">Centre de messagerie</h1>
                <p class="mt-2 text-sm text-slate-600">Listing complet des emails systeme et suivi des ouvertures.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="dashboard_admin.php" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard Admin
                </a>
                <a href="notification_email_tester.php" class="inline-flex items-center gap-2 rounded-lg border border-sky-200 bg-white px-4 py-2.5 text-sm font-semibold text-sky-700 shadow-sm transition hover:border-sky-300 hover:bg-sky-50">
                    <i class="fas fa-envelope-circle-check"></i>
                    Tester notifications
                </a>
            </div>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Total logs</p>
                <p class="mt-2 text-3xl font-bold text-slate-950"><?= number_format($totalEmails, 0, ',', ' ') ?></p>
                <p class="mt-1 text-xs text-slate-500">Filtres inclus</p>
            </div>
            <div class="rounded-lg border border-emerald-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Envoyes</p>
                <p class="mt-2 text-3xl font-bold text-emerald-700"><?= number_format($sentEmails, 0, ',', ' ') ?></p>
                <p class="mt-1 text-xs text-slate-500">Status SMTP sent</p>
            </div>
            <div class="rounded-lg border border-red-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Echoues</p>
                <p class="mt-2 text-3xl font-bold text-red-700"><?= number_format($failedEmails, 0, ',', ' ') ?></p>
                <p class="mt-1 text-xs text-slate-500"><?= $failureRate ?>% du volume</p>
            </div>
            <div class="rounded-lg border border-indigo-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Ouverts</p>
                <p class="mt-2 text-3xl font-bold text-indigo-700"><?= number_format($openedEmails, 0, ',', ' ') ?></p>
                <p class="mt-1 text-xs text-slate-500"><?= $openRate ?>% des envoyes</p>
            </div>
            <div class="rounded-lg border border-amber-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Aujourd'hui</p>
                <p class="mt-2 text-3xl font-bold text-amber-700"><?= number_format($todayEmails, 0, ',', ' ') ?></p>
                <p class="mt-1 text-xs text-slate-500">Selon date envoi/log</p>
            </div>
            <div class="rounded-lg border border-cyan-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">7 derniers jours</p>
                <p class="mt-2 text-3xl font-bold text-cyan-700"><?= number_format($lastSevenDaysEmails, 0, ',', ' ') ?></p>
                <p class="mt-1 text-xs text-slate-500">Activite recente</p>
            </div>
        </div>

        <form method="get" class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid gap-4 lg:grid-cols-4">
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Date precise</span>
                    <input type="date" name="exact_date" value="<?= htmlspecialchars(emailLogsValue('exact_date'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Debut intervalle</span>
                    <input type="date" name="date_from" value="<?= htmlspecialchars(emailLogsValue('date_from'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Fin intervalle</span>
                    <input type="date" name="date_to" value="<?= htmlspecialchars(emailLogsValue('date_to'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Statut ouverture</span>
                    <select name="open_status" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                        <option value="">Tous</option>
                        <option value="opened" <?= emailLogsValue('open_status') === 'opened' ? 'selected' : '' ?>>Ouvert</option>
                        <option value="unopened" <?= emailLogsValue('open_status') === 'unopened' ? 'selected' : '' ?>>Non ouvert</option>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Sujet</span>
                    <input list="subjectOptions" name="subject" value="<?= htmlspecialchars(emailLogsValue('subject'), ENT_QUOTES, 'UTF-8') ?>" placeholder="Rechercher ou selectionner un sujet" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                    <datalist id="subjectOptions">
                        <?php foreach ($subjects as $row): ?>
                            <option value="<?= htmlspecialchars((string)$row['subject'], ENT_QUOTES, 'UTF-8') ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Personnel</span>
                    <input list="personnelOptions" name="personnel" value="<?= htmlspecialchars(emailLogsValue('personnel'), ENT_QUOTES, 'UTF-8') ?>" placeholder="Nom ou email" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                    <datalist id="personnelOptions">
                        <?php foreach ($personnelChoices as $row): ?>
                            <?php $label = trim((string)$row['label'] . ' - ' . (string)$row['email']); ?>
                            <option value="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"></option>
                            <option value="<?= htmlspecialchars((string)$row['email'], ENT_QUOTES, 'UTF-8') ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Service</span>
                    <select name="service_id" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                        <option value="">Tous les services</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= (int)$service['id'] ?>" <?= emailLogsValue('service_id') === (string)(int)$service['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string)$service['label'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Statut envoi</span>
                    <select name="delivery_status" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                        <option value="">Tous</option>
                        <option value="sent" <?= emailLogsValue('delivery_status') === 'sent' ? 'selected' : '' ?>>Envoye</option>
                        <option value="failed" <?= emailLogsValue('delivery_status') === 'failed' ? 'selected' : '' ?>>Erreur</option>
                    </select>
                </label>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">
                    <i class="fas fa-search"></i>
                    Rechercher
                </button>
                <a href="email_logs.php" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    <i class="fas fa-rotate-left"></i>
                    Reinitialiser
                </a>
                <span class="text-sm text-slate-500"><?= number_format(count($emails), 0, ',', ' ') ?> mail(s) affiches</span>
            </div>
        </form>

        <div class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-slate-950">Statistiques emails</h2>
                    <p class="text-sm text-slate-500">Graphiques calcules depuis `email_logs` avec les filtres actifs.</p>
                </div>
            </div>
            <div class="mt-5 grid gap-6 xl:grid-cols-3">
                <div class="rounded-lg border border-slate-100 p-4">
                    <h3 class="text-sm font-semibold text-slate-700">Volume par jour</h3>
                    <div class="mt-3 h-64"><canvas id="emailsChart"></canvas></div>
                </div>
                <div class="rounded-lg border border-slate-100 p-4">
                    <h3 class="text-sm font-semibold text-slate-700">Erreurs SMTP par jour</h3>
                    <div class="mt-3 h-64"><canvas id="errorsChart"></canvas></div>
                </div>
                <div class="rounded-lg border border-slate-100 p-4">
                    <h3 class="text-sm font-semibold text-slate-700">Ouverture</h3>
                    <div class="mt-3 h-64"><canvas id="openRateChart"></canvas></div>
                </div>
            </div>
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <div class="rounded-lg border border-slate-100 p-4">
                    <h3 class="text-sm font-semibold text-slate-700">Repartition envoi</h3>
                    <div class="mt-3 h-60"><canvas id="statusChart"></canvas></div>
                </div>
                <div class="rounded-lg border border-slate-100 p-4">
                    <h3 class="text-sm font-semibold text-slate-700">Sujets les plus frequents</h3>
                    <div class="mt-3 space-y-3">
                        <?php if (!$topSubjects): ?>
                            <p class="text-sm text-slate-500">Aucune donnee.</p>
                        <?php endif; ?>
                        <?php foreach ($topSubjects as $row): ?>
                            <?php $width = $totalEmails > 0 ? min(100, round(((int)$row['total'] / $totalEmails) * 100)) : 0; ?>
                            <div>
                                <div class="flex justify-between gap-3 text-sm">
                                    <span class="truncate font-medium text-slate-700"><?= htmlspecialchars((string)$row['subject'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-slate-500"><?= (int)$row['total'] ?></span>
                                </div>
                                <div class="mt-1 h-2 rounded-full bg-slate-100">
                                    <div class="h-2 rounded-full bg-indigo-500" style="width: <?= $width ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-2 border-b border-slate-100 p-5 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-xl font-bold text-slate-950">Historique complet des emails</h2>
                    <p class="text-sm text-slate-500">Tous les mails correspondant aux filtres sont listés, sans limite SQL.</p>
                </div>
                <div class="text-sm font-semibold text-slate-700"><?= number_format(count($emails), 0, ',', ' ') ?> resultat(s)</div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-[0.12em] text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">Destinataire</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Service</th>
                            <th class="px-4 py-3">Sujet</th>
                            <th class="px-4 py-3">Envoi</th>
                            <th class="px-4 py-3">Ouverture</th>
                            <th class="px-4 py-3">Erreur</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (!$emails): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-8 text-center text-slate-500">Aucun email trouve.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($emails as $mail): ?>
                            <?php
                            $displayName = trim((string)($mail['personnel_name'] ?: $mail['recipient_name'] ?: '-'));
                            $sentAt = (string)($mail['sent_at'] ?: $mail['created_at'] ?: '');
                            $error = trim((string)($mail['error_message'] ?? ''));
                            ?>
                            <tr class="align-top transition hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-3 text-slate-600"><?= htmlspecialchars($sentAt, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-3 font-medium text-slate-900"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)$mail['recipient_email'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)($mail['service_label'] ?: '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="min-w-[260px] px-4 py-3 text-slate-800"><?= htmlspecialchars((string)($mail['subject'] ?: 'Sans sujet'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-3">
                                    <?php if (($mail['status'] ?? '') === 'sent'): ?>
                                        <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">Envoye</span>
                                    <?php elseif (($mail['status'] ?? '') === 'failed'): ?>
                                        <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700">Erreur</span>
                                    <?php else: ?>
                                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?= htmlspecialchars((string)($mail['status'] ?: 'Inconnu'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if (!empty($mail['opened_at'])): ?>
                                        <span class="inline-flex rounded-full bg-indigo-100 px-3 py-1 text-xs font-semibold text-indigo-700">Ouvert</span>
                                        <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string)$mail['opened_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php else: ?>
                                        <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Non ouvert</span>
                                    <?php endif; ?>
                                </td>
                                <td class="max-w-[320px] px-4 py-3 text-xs text-red-700">
                                    <?= $error !== '' ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '<span class="text-slate-400">-</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    const emailDays = <?= json_encode(array_column($emailsPerDay, 'day'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const emailCounts = <?= json_encode(array_map('intval', array_column($emailsPerDay, 'total')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const sentCounts = <?= json_encode(array_map('intval', array_column($emailsPerDay, 'sent_total')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const errorCounts = <?= json_encode(array_map('intval', array_column($errorsPerDay, 'total')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const openStats = <?= json_encode($openStats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const statusLabels = <?= json_encode(array_column($statusStats, 'status_label'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const statusCounts = <?= json_encode(array_map('intval', array_column($statusStats, 'total')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                labels: {
                    boxWidth: 12
                }
            }
        }
    };

    new Chart(document.getElementById('emailsChart'), {
        type: 'line',
        data: {
            labels: emailDays,
            datasets: [{
                label: 'Total logs',
                data: emailCounts,
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79,70,229,0.14)',
                tension: 0.25,
                fill: true
            }, {
                label: 'Envoyes',
                data: sentCounts,
                borderColor: '#059669',
                backgroundColor: 'rgba(5,150,105,0.10)',
                tension: 0.25
            }]
        },
        options: chartDefaults
    });

    new Chart(document.getElementById('errorsChart'), {
        type: 'bar',
        data: {
            labels: emailDays,
            datasets: [{
                label: 'Erreurs',
                data: errorCounts,
                backgroundColor: '#dc2626'
            }]
        },
        options: chartDefaults
    });

    new Chart(document.getElementById('openRateChart'), {
        type: 'doughnut',
        data: {
            labels: ['Ouverts', 'Non ouverts'],
            datasets: [{
                data: [Number(openStats.opened || 0), Number(openStats.unopened || 0)],
                backgroundColor: ['#4f46e5', '#e2e8f0']
            }]
        },
        options: chartDefaults
    });

    new Chart(document.getElementById('statusChart'), {
        type: 'bar',
        data: {
            labels: statusLabels,
            datasets: [{
                label: 'Emails',
                data: statusCounts,
                backgroundColor: ['#059669', '#dc2626', '#64748b', '#f59e0b']
            }]
        },
        options: chartDefaults
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
