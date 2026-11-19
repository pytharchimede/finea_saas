<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';

function isPublicIp(?string $ip): bool
{
    if (!$ip) {
        return false;
    }
    $ip = trim($ip);
    if ($ip === '' || strtolower($ip) === 'unknown') {
        return false;
    }
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    return true;
}

function httpGetJson(string $url, int $timeoutSeconds = 2): ?array
{
    $timeoutSeconds = max(1, min(10, $timeoutSeconds));

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'banamur-admin/1.0');
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $code < 200 || $code >= 300) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: banamur-admin/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $ctx);
    if (!is_string($body) || trim($body) === '') {
        return null;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

$pdo = Database::getConnection();

// Statistiques utilisateurs
$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM user_devis_banamur")->fetchColumn();
$activeUsers = (int) $pdo->query("SELECT COUNT(*) FROM user_devis_banamur WHERE active = 1")->fetchColumn();
$inactiveUsers = $totalUsers - $activeUsers;

// Nouveaux utilisateurs
$newUsersThisMonth = (int) $pdo->query(
    "SELECT COUNT(*) FROM user_devis_banamur WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
)->fetchColumn();

$userFilterId = (int)($_GET['user_id'] ?? 0);
if ($userFilterId < 0) {
    $userFilterId = 0;
}

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

function isValidDateYmd(string $value): bool
{
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return $dt && $dt->format('Y-m-d') === $value;
}

// Journée / connexions (login_history)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        ip VARCHAR(45) NULL,
        user_agent TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ip_geo_cache (
        ip VARCHAR(45) NOT NULL PRIMARY KEY,
        country VARCHAR(100) NULL,
        region VARCHAR(100) NULL,
        city VARCHAR(100) NULL,
        lat DECIMAL(10,7) NULL,
        lon DECIMAL(10,7) NULL,
        isp VARCHAR(255) NULL,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $days = (int)($_GET['days'] ?? 30);
    if ($days < 1) {
        $days = 30;
    }
    if ($days > 365) {
        $days = 365;
    }

    $perPage = (int)($_GET['per_page'] ?? 25);
    $allowedPerPage = [10, 25, 50, 100];
    if (!in_array($perPage, $allowedPerPage, true)) {
        $perPage = 25;
    }

    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }

    $export = strtolower(trim((string)($_GET['export'] ?? '')));

    // Période filtrée (date précise / plage) ou fallback "X derniers jours"
    $useExplicitDates = (isValidDateYmd($dateFrom) || isValidDateYmd($dateTo));
    if ($useExplicitDates) {
        if (!isValidDateYmd($dateFrom) && isValidDateYmd($dateTo)) {
            $dateFrom = $dateTo;
        }
        if (!isValidDateYmd($dateTo) && isValidDateYmd($dateFrom)) {
            $dateTo = $dateFrom;
        }

        if (!isValidDateYmd($dateFrom) || !isValidDateYmd($dateTo)) {
            $useExplicitDates = false;
            $dateFrom = '';
            $dateTo = '';
        }
    }

    if ($useExplicitDates) {
        if ($dateFrom > $dateTo) {
            $tmp = $dateFrom;
            $dateFrom = $dateTo;
            $dateTo = $tmp;
        }
        $periodStartDate = $dateFrom;
        $periodEndDate = $dateTo;
        $periodStartDt = $periodStartDate . ' 00:00:00';
        $periodEndDt = $periodEndDate . ' 23:59:59';
        $periodLabel = 'Du ' . $periodStartDate . ' au ' . $periodEndDate;
    } else {
        $today = new DateTime('today');
        $startDay = (new DateTime('today'))->modify('-' . ($days - 1) . ' days');
        $periodStartDate = $startDay->format('Y-m-d');
        $periodEndDate = $today->format('Y-m-d');
        $periodStartDt = $periodStartDate . ' 00:00:00';
        $periodEndDt = (new DateTime())->format('Y-m-d H:i:s');
        $periodLabel = $days . ' jours';
    }

    $where = ['l.created_at >= :start_dt', 'l.created_at <= :end_dt'];
    $params = [
        ':start_dt' => $periodStartDt,
        ':end_dt' => $periodEndDt,
    ];
    if ($userFilterId > 0) {
        $where[] = 'l.user_id = :user_id';
        $params[':user_id'] = $userFilterId;
    }
    $whereSql = implode(' AND ', $where);

    $totalLogins = (int) $pdo->query("SELECT COUNT(*) FROM login_history")->fetchColumn();
    $loginsToday = (int) $pdo->query("SELECT COUNT(*) FROM login_history WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $loginsLast7Days = (int) $pdo->query("SELECT COUNT(*) FROM login_history WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_history l WHERE {$whereSql}");
    $stmt->execute($params);
    $totalInPeriod = (int)$stmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalInPeriod / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    // Exports (avant HTML)
    if ($export === 'csv') {
        \AuditTrail::log('login_history_export', [
            'module' => 'admin',
            'action' => 'export_connexions_csv',
            'entity_type' => 'login_history',
            'entity_label' => 'Historique des connexions',
            'details' => [
                'format' => 'csv',
                'period_start' => $periodStartDt,
                'period_end' => $periodEndDt,
                'user_filter_id' => $userFilterId > 0 ? $userFilterId : null,
            ],
        ], $pdo);
        $exportStmt = $pdo->prepare(
            "SELECT l.*, u.prenom, u.nom, u.mail_pro,
                    g.country, g.region, g.city, g.isp
             FROM login_history l
             LEFT JOIN user_devis_banamur u ON u.id = l.user_id
             LEFT JOIN ip_geo_cache g ON g.ip = l.ip
             WHERE {$whereSql}
             ORDER BY l.created_at DESC
             LIMIT 5000"
        );
        $exportStmt->execute($params);
        $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

        $filename = 'connexions_' . date('Ymd_His') . '_J' . $days . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date', 'Utilisateur', 'Email', 'IP', 'Localisation', 'FAI', 'User-Agent'], ';');
        foreach ($exportRows as $r) {
            $user = trim((string)($r['prenom'] ?? '') . ' ' . (string)($r['nom'] ?? ''));
            $email = (string)($r['mail_pro'] ?? '');
            $ip = (string)($r['ip'] ?? '');
            $locParts = array_filter([
                trim((string)($r['city'] ?? '')),
                trim((string)($r['region'] ?? '')),
                trim((string)($r['country'] ?? '')),
            ], fn($v) => $v !== '');
            $loc = $locParts ? implode(', ', $locParts) : '';
            fputcsv($out, [
                (string)($r['created_at'] ?? ''),
                $user !== '' ? $user : ('Utilisateur #' . (string)($r['user_id'] ?? '')),
                $email,
                $ip,
                $loc,
                (string)($r['isp'] ?? ''),
                (string)($r['user_agent'] ?? ''),
            ], ';');
        }
        fclose($out);
        exit;
    }

    if ($export === 'pdf') {
        \AuditTrail::log('login_history_export', [
            'module' => 'admin',
            'action' => 'export_connexions_pdf',
            'entity_type' => 'login_history',
            'entity_label' => 'Historique des connexions',
            'details' => [
                'format' => 'pdf',
                'period_start' => $periodStartDt,
                'period_end' => $periodEndDt,
                'user_filter_id' => $userFilterId > 0 ? $userFilterId : null,
            ],
        ], $pdo);
        require_once __DIR__ . '/../fpdf/fpdf.php';
        $exportStmt = $pdo->prepare(
            "SELECT l.*, u.prenom, u.nom, u.mail_pro,
                    g.country, g.region, g.city
             FROM login_history l
             LEFT JOIN user_devis_banamur u ON u.id = l.user_id
             LEFT JOIN ip_geo_cache g ON g.ip = l.ip
             WHERE {$whereSql}
             ORDER BY l.created_at DESC
             LIMIT 800"
        );
        $exportStmt->execute($params);
        $exportRows = $exportStmt->fetchAll(PDO::FETCH_ASSOC);

        $pdf = new FPDF('L', 'mm', 'A4');
        $pdf->AddPage();
        // Évite le style gras (certaines installations FPDF déclenchent "Undefined font: helvetica B")
        $pdf->SetFont('Helvetica', '', 14);
        $pdf->Cell(0, 8, utf8_decode('Activite des connexions - ' . $periodLabel), 0, 1);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(0, 6, utf8_decode('Export du ' . date('Y-m-d H:i:s')), 0, 1);
        $pdf->Ln(2);

        $pdf->SetFont('Helvetica', '', 9);
        $pdf->Cell(38, 7, utf8_decode('Date/Heure'), 1);
        $pdf->Cell(60, 7, utf8_decode('Utilisateur'), 1);
        $pdf->Cell(70, 7, utf8_decode('Email'), 1);
        $pdf->Cell(35, 7, utf8_decode('IP'), 1);
        $pdf->Cell(75, 7, utf8_decode('Localisation'), 1);
        $pdf->Ln();

        $pdf->SetFont('Helvetica', '', 8);
        foreach ($exportRows as $r) {
            $user = trim((string)($r['prenom'] ?? '') . ' ' . (string)($r['nom'] ?? ''));
            if ($user === '') {
                $user = 'Utilisateur #' . (string)($r['user_id'] ?? '');
            }
            $locParts = array_filter([
                trim((string)($r['city'] ?? '')),
                trim((string)($r['region'] ?? '')),
                trim((string)($r['country'] ?? '')),
            ], fn($v) => $v !== '');
            $loc = $locParts ? implode(', ', $locParts) : '—';

            $pdf->Cell(38, 6, utf8_decode((string)($r['created_at'] ?? '')), 1);
            $pdf->Cell(60, 6, utf8_decode(substr($user, 0, 40)), 1);
            $pdf->Cell(70, 6, utf8_decode(substr((string)($r['mail_pro'] ?? ''), 0, 55)), 1);
            $pdf->Cell(35, 6, utf8_decode(substr((string)($r['ip'] ?? ''), 0, 22)), 1);
            $pdf->Cell(75, 6, utf8_decode(substr($loc, 0, 60)), 1);
            $pdf->Ln();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="connexions_' . date('Ymd_His') . '.pdf"');
        $pdf->Output('I');
        exit;
    }

    $listSql = "SELECT l.*, u.prenom, u.nom, u.mail_pro,
            g.country, g.region, g.city, g.lat, g.lon, g.isp, g.updated_at AS geo_updated_at
        FROM login_history l
        LEFT JOIN user_devis_banamur u ON u.id = l.user_id
        LEFT JOIN ip_geo_cache g ON g.ip = l.ip
        WHERE {$whereSql}
        ORDER BY l.created_at DESC
        LIMIT :lim OFFSET :off";
    $stmt = $pdo->prepare($listSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $loginRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Résolution progressive des IP manquantes (cache)
    $cutoff = date('Y-m-d H:i:s', time() - (30 * 86400));
    $ipsToResolve = [];
    foreach ($loginRows as $r) {
        $ip = (string)($r['ip'] ?? '');
        if (!isPublicIp($ip)) {
            continue;
        }
        $hasGeo = trim((string)($r['country'] ?? '')) !== '' || trim((string)($r['city'] ?? '')) !== '';
        $stale = !empty($r['geo_updated_at']) && (string)$r['geo_updated_at'] < $cutoff;
        if (!$hasGeo || $stale) {
            $ipsToResolve[$ip] = true;
        }
    }
    $ipsToResolve = array_slice(array_keys($ipsToResolve), 0, 5);
    if ($ipsToResolve) {
        $ins = $pdo->prepare(
            "INSERT INTO ip_geo_cache (ip, country, region, city, lat, lon, isp, updated_at)
             VALUES (:ip, :country, :region, :city, :lat, :lon, :isp, NOW())
             ON DUPLICATE KEY UPDATE
                country=VALUES(country), region=VALUES(region), city=VALUES(city), lat=VALUES(lat), lon=VALUES(lon), isp=VALUES(isp), updated_at=NOW()"
        );

        foreach ($ipsToResolve as $ip) {
            $data = httpGetJson('https://ipapi.co/' . rawurlencode($ip) . '/json/', 2);
            if (!is_array($data) || !empty($data['error'])) {
                continue;
            }

            $country = trim((string)($data['country_name'] ?? ''));
            $region = trim((string)($data['region'] ?? ''));
            $city = trim((string)($data['city'] ?? ''));
            $lat = isset($data['latitude']) && is_numeric($data['latitude']) ? (float)$data['latitude'] : null;
            $lon = isset($data['longitude']) && is_numeric($data['longitude']) ? (float)$data['longitude'] : null;
            $isp = trim((string)($data['org'] ?? ''));

            $ins->execute([
                'ip' => $ip,
                'country' => ($country !== '' ? $country : null),
                'region' => ($region !== '' ? $region : null),
                'city' => ($city !== '' ? $city : null),
                'lat' => $lat,
                'lon' => $lon,
                'isp' => ($isp !== '' ? $isp : null),
            ]);
        }

        $stmt = $pdo->prepare($listSql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $loginRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $dailyStmt = $pdo->prepare(
        "SELECT DATE(l.created_at) AS d, COUNT(*) AS c
         FROM login_history l
         WHERE {$whereSql}
         GROUP BY DATE(l.created_at)
         ORDER BY d"
    );
    $dailyStmt->execute($params);
    $dailyRows = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

    $hourStmt = $pdo->prepare(
        "SELECT HOUR(l.created_at) AS h, COUNT(*) AS c
         FROM login_history l
         WHERE {$whereSql}
         GROUP BY HOUR(l.created_at)
         ORDER BY h"
    );
    $hourStmt->execute($params);
    $hourRows = $hourStmt->fetchAll(PDO::FETCH_ASSOC);

    $byUserStmt = $pdo->prepare(
        "SELECT l.user_id,
                COALESCE(NULLIF(TRIM(CONCAT(u.prenom, ' ', u.nom)), ''), CONCAT('Utilisateur #', l.user_id)) AS label,
                COUNT(*) AS c
         FROM login_history l
         LEFT JOIN user_devis_banamur u ON u.id = l.user_id
         WHERE {$whereSql}
         GROUP BY l.user_id
         ORDER BY c DESC
         LIMIT 10"
    );
    $byUserStmt->execute($params);
    $byUserRows = $byUserStmt->fetchAll(PDO::FETCH_ASSOC);

    $usersForFilter = $pdo->query(
        "SELECT id, prenom, nom, mail_pro
         FROM user_devis_banamur
         ORDER BY prenom ASC, nom ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $totalLogins = $loginsToday = $loginsLast7Days = 0;
    $days = 30;
    $perPage = 25;
    $page = 1;
    $totalInPeriod = 0;
    $totalPages = 1;
    $loginRows = [];
    $dailyRows = [];
    $hourRows = [];
    $byUserRows = [];
    $usersForFilter = [];
    $periodStartDate = '';
    $periodEndDate = '';
    $periodLabel = $days . ' jours';
}

$pageTitle = 'Tableau de bord Admin';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">
        <!-- En-tête -->
        <div class="text-center mb-12">
            <h1 class="text-5xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent drop-shadow-lg">
                🛡️ Tableau de bord Administrateur Système
                <p class="text-xl text-gray-600 mt-4">Gestion des utilisateurs, des rôles et des permissions.</p>

                <div class="flex flex-wrap justify-center gap-4 mt-8">
                    <a href="liste_users.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-500 to-purple-600 text-white rounded-xl hover:from-indigo-600 hover:to-purple-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-users mr-2"></i>
                        Liste utilisateurs
                    </a>
                    <a href="portal_modules.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-fuchsia-500 to-pink-600 text-white rounded-xl hover:from-fuchsia-600 hover:to-pink-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-puzzle-piece mr-2"></i>
                        Modules du portail
                    </a>
                    <a href="search_engine_training.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-amber-500 text-white rounded-xl hover:from-indigo-700 hover:to-amber-600 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-brain mr-2"></i>
                        Entraîner le moteur
                    </a>
                    <a href="notification_email_tester.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-sky-600 to-cyan-600 text-white rounded-xl hover:from-sky-700 hover:to-cyan-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-envelope-circle-check mr-2"></i>
                        Test notifications email
                    </a>
                    <a href="whatsapp_templates.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-emerald-600 to-green-500 text-white rounded-xl hover:from-emerald-700 hover:to-green-600 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fab fa-whatsapp mr-2"></i>
                        Console WhatsApp
                    </a>
                    <a href="notification_matrix.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-rose-600 to-orange-500 text-white rounded-xl hover:from-rose-700 hover:to-orange-600 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-bell-slash mr-2"></i>
                        Matrice notifications
                    </a>
                    <a href="gestion_acces.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-xl hover:from-emerald-600 hover:to-teal-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-user-shield mr-2"></i>
                        Droits & permissions
                    </a>
                    <a href="access_matrix.php" class="inline-flex items-center px-6 py-3 bg-slate-950 text-white rounded-xl hover:bg-slate-800 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-table-cells-large mr-2"></i>
                        Matrice des accès
                    </a>
                    <a href="audit_acl.php" class="inline-flex items-center px-6 py-3 bg-slate-900 text-white rounded-xl hover:bg-slate-800 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-shield-halved mr-2"></i>
                        Audit ACL
                    </a>
                    <a href="../dashboard_dg.php" class="inline-flex items-center px-6 py-3 bg-[#1d2b57] text-white rounded-xl hover:bg-[#26396f] transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-chart-pie mr-2"></i>
                        Dashboard DG
                    </a>
                    <a href="version_finea.php" class="inline-flex items-center px-6 py-3 bg-amber-400 text-slate-900 rounded-xl hover:bg-amber-300 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-code-branch mr-2"></i>
                        Version FINEA
                    </a>
                    <a href="fne_tools.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-blue-600 text-white rounded-xl hover:from-indigo-700 hover:to-blue-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-receipt mr-2"></i>
                        Outils FNE
                    </a>
                    <a href="workflow_fiches_admin.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-cyan-600 to-indigo-600 text-white rounded-xl hover:from-cyan-700 hover:to-indigo-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-code-branch mr-2"></i>
                        Pilotage workflow fiches
                    </a>
                    <a href="portal_badges_debug.php" class="inline-flex items-center px-5 py-3 bg-white text-slate-700 rounded-xl border border-slate-200 hover:bg-slate-50 transition-all shadow-sm hover:shadow-md">
                        <i class="fas fa-bug mr-2 text-slate-500"></i>
                        Debug badges portail
                    </a>
                    <a href="portal_badges_settings.php" class="inline-flex items-center px-5 py-3 bg-white text-slate-700 rounded-xl border border-slate-200 hover:bg-slate-50 transition-all shadow-sm hover:shadow-md">
                        <i class="fas fa-sliders-h mr-2 text-slate-500"></i>
                        Paramétrage badges portail
                    </a>
                    <a href="feb_ocr_settings.php" class="inline-flex items-center px-5 py-3 bg-white text-slate-700 rounded-xl border border-slate-200 hover:bg-slate-50 transition-all shadow-sm hover:shadow-md">
                        <i class="fas fa-file-signature mr-2 text-slate-500"></i>
                        OCR FEB
                    </a>
                    <a href="attendance_control_center.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-xl hover:from-emerald-600 hover:to-teal-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-user-clock mr-2"></i>
                        Centre contrôle pointage
                    </a>
                    <a href="remaniement_matricules.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-slate-800 to-slate-950 text-white rounded-xl hover:from-slate-900 hover:to-black transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-id-card mr-2"></i>
                        Remaniement matricules RH
                    </a>
                    <a href="ai_evolution.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-violet-600 to-indigo-600 text-white rounded-xl hover:from-violet-700 hover:to-indigo-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-brain mr-2"></i>
                        Evolution IA
                    </a>
                    <a href="ai_insights_360.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-cyan-600 to-blue-700 text-white rounded-xl hover:from-cyan-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1"><i class="fas fa-chart-line mr-2"></i>Intelligence 360°</a>
                    <a href="add_user.php" class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-yellow-500 to-orange-500 text-white rounded-xl hover:from-yellow-600 hover:to-orange-600 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-user-plus mr-2"></i>
                        Ajouter utilisateur
                    </a>
                    <a href="../selection_portail.php" class="inline-flex items-center px-6 py-3 bg-gray-800 text-white rounded-xl hover:bg-gray-900 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-th-large mr-2"></i>
                        Retour au Portail
                    </a>
                </div>
        </div>

        <!-- Indicateurs clés -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">
            <div class="bg-white rounded-2xl shadow-xl hover:shadow-2xl transition-all p-6 border border-indigo-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Total Utilisateurs</p>
                        <p class="text-3xl font-bold text-indigo-600"><?= number_format($totalUsers) ?></p>
                        <p class="text-xs text-gray-500 mt-1">Comptes créés</p>
                    </div>
                    <div class="bg-indigo-100 p-3 rounded-xl">
                        <i class="fas fa-users text-2xl text-indigo-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl hover:shadow-2xl transition-all p-6 border border-green-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Utilisateurs Actifs</p>
                        <p class="text-3xl font-bold text-green-600"><?= number_format($activeUsers) ?></p>
                        <p class="text-xs text-gray-500 mt-1">Actifs dans le système</p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-xl">
                        <i class="fas fa-user-check text-2xl text-green-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl hover:shadow-2xl transition-all p-6 border border-red-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Utilisateurs Inactifs</p>
                        <p class="text-3xl font-bold text-red-600"><?= number_format($inactiveUsers) ?></p>
                        <p class="text-xs text-gray-500 mt-1">Comptes désactivés</p>
                    </div>
                    <div class="bg-red-100 p-3 rounded-xl">
                        <i class="fas fa-user-slash text-2xl text-red-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl hover:shadow-2xl transition-all p-6 border border-yellow-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Nouveaux ce mois</p>
                        <p class="text-3xl font-bold text-yellow-600"><?= number_format($newUsersThisMonth) ?></p>
                        <p class="text-xs text-gray-500 mt-1">Créations récentes</p>
                    </div>
                    <div class="bg-yellow-100 p-3 rounded-xl">
                        <i class="fas fa-calendar-plus text-2xl text-yellow-600"></i>
                    </div>
                </div>
            </div>

            <a href="email_logs.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Emails système</h3>
                        <p class="text-sm text-gray-500 mt-2">Consulter les emails envoyés, erreurs SMTP et ouvertures.</p>
                    </div>
                    <div class="text-purple-600 text-3xl">
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
            </a>

            <a href="notification_email_tester.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Test notifications email</h3>
                        <p class="text-sm text-gray-500 mt-2">Prévisualiser les variables dynamiques, choisir un destinataire test et rejouer l’envoi réel pour contrôle anti-spam.</p>
                    </div>
                    <div class="text-sky-600 text-3xl">
                        <i class="fas fa-envelope-circle-check"></i>
                    </div>
                </div>
            </a>

            <a href="ai_evolution.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Evolution IA</h3>
                        <p class="text-sm text-gray-500 mt-2">Voir l'apprentissage IA, les signaux chantiers et les projections de decaissement.</p>
                    </div>
                    <div class="text-indigo-600 text-3xl">
                        <i class="fas fa-brain"></i>
                    </div>
                </div>
            </a>

            <a href="notifications_settings.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
                        <p class="text-sm text-gray-500 mt-2">Configurer évènements, destinataires et canaux (système/email).</p>
                    </div>
                    <div class="text-indigo-600 text-3xl">
                        <i class="fas fa-bell"></i>
                    </div>
                </div>
            </a>

            <a href="planning_meeting_settings.php" class="block p-6 rounded-2xl border border-amber-200 bg-amber-50/60 hover:shadow-lg transition">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Paramètres des réunions</h3>
                        <p class="text-sm text-gray-500 mt-2">Définir le chantier Bureau utilisé pour organiser les réunions par service.</p>
                    </div>
                    <div class="text-amber-600 text-3xl">
                        <i class="fas fa-people-group"></i>
                    </div>
                </div>
            </a>

            <a href="notification_matrix.php" class="block p-6 rounded-2xl border border-rose-200 bg-rose-50/60 hover:shadow-lg transition">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Matrice notifications personnel</h3>
                        <p class="text-sm text-gray-500 mt-2">Desactiver en un clic toutes les notifications FINEA et piloter Email/Systeme par personne.</p>
                    </div>
                    <div class="text-rose-600 text-3xl">
                        <i class="fas fa-bell-slash"></i>
                    </div>
                </div>
            </a>

            <a href="access_request_support_settings.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Support accès</h3>
                        <p class="text-sm text-gray-500 mt-2">Choisir quels employés du service informatique reçoivent les demandes d'accès et suivre les dernières soumissions.</p>
                    </div>
                    <div class="text-cyan-600 text-3xl">
                        <i class="fas fa-user-shield"></i>
                    </div>
                </div>
            </a>

            <a href="positioning_settings.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-800">Géolocalisation fournisseurs</h3>
                        <p class="text-sm text-gray-500 mt-2">Piloter l'API GPS, la carte Leaflet, l'autocomplétion Nominatim et le temps réel depuis l'administration.</p>
                    </div>
                    <div class="text-emerald-600 text-3xl">
                        <i class="fas fa-map-location-dot"></i>
                    </div>
                </div>
            </a>
        </div>

        <!-- Accès rapides -->
        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mb-12">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Accès rapides</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <a href="liste_users.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Utilisateurs</h3>
                            <p class="text-sm text-gray-500 mt-2">Voir, modifier et activer/désactiver des comptes.</p>
                        </div>
                        <div class="text-indigo-600 text-3xl">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </a>

                <a href="gestion_acces.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Permissions</h3>
                            <p class="text-sm text-gray-500 mt-2">Gérer les droits d'accès par utilisateur.</p>
                        </div>
                        <div class="text-emerald-600 text-3xl">
                            <i class="fas fa-user-shield"></i>
                        </div>
                    </div>
                </a>

                <a href="add_user.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Ajouter</h3>
                            <p class="text-sm text-gray-500 mt-2">Créer un nouveau compte utilisateur.</p>
                        </div>
                        <div class="text-yellow-600 text-3xl">
                            <i class="fas fa-user-plus"></i>
                        </div>
                    </div>
                </a>

                <a href="fne_tools.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Outils FNE</h3>
                            <p class="text-sm text-gray-500 mt-2">Tester signature, avoir, verify facture & contrôle décaissement.</p>
                        </div>
                        <div class="text-indigo-600 text-3xl">
                            <i class="fas fa-receipt"></i>
                        </div>
                    </div>
                </a>

                <a href="db_tools.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Base de données</h3>
                            <p class="text-sm text-gray-500 mt-2">Statistiques et export SQL (admin).</p>
                        </div>
                        <div class="text-indigo-600 text-3xl">
                            <i class="fas fa-database"></i>
                        </div>
                    </div>
                </a>

                <a href="relink_fiches_debourse.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Reliaison fiches</h3>
                            <p class="text-sm text-gray-500 mt-2">Lister les fiches non reliées et choisir manuellement la bonne ligne de déboursé.</p>
                        </div>
                        <div class="text-amber-600 text-3xl">
                            <i class="fas fa-link"></i>
                        </div>
                    </div>
                </a>

                <a href="logistics_fuel_tools.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Outils carburant logistique</h3>
                            <p class="text-sm text-gray-500 mt-2">Generation des lignes carburant et rattrapage admin des bons manquants.</p>
                        </div>
                        <div class="text-orange-600 text-3xl">
                            <i class="fas fa-gas-pump"></i>
                        </div>
                    </div>
                </a>

                <a href="workflow_fiches_admin.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Workflow fiches</h3>
                            <p class="text-sm text-gray-500 mt-2">Repositionner une fiche, convertir un total en partiel et suivre la famille parent/enfant.</p>
                        </div>
                        <div class="text-cyan-600 text-3xl">
                            <i class="fas fa-code-branch"></i>
                        </div>
                    </div>
                </a>

                <a href="positioning_settings.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Paramétrage géolocalisation</h3>
                            <p class="text-sm text-gray-500 mt-2">Ouvrir directement les réglages GPS fournisseurs et lancer le géocodage des adresses existantes.</p>
                        </div>
                        <div class="text-emerald-600 text-3xl">
                            <i class="fas fa-map-marked-alt"></i>
                        </div>
                    </div>
                </a>

                <a href="portal_badges_debug.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Debug badges portail</h3>
                            <p class="text-sm text-gray-500 mt-2">Contrôler les signaux métier, seuils et compteurs qui alimentent les badges du portail.</p>
                        </div>
                        <div class="text-slate-600 text-3xl">
                            <i class="fas fa-bug"></i>
                        </div>
                    </div>
                </a>

                <a href="portal_badges_settings.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Paramétrage badges portail</h3>
                            <p class="text-sm text-gray-500 mt-2">Régler ce qui est compté, les textes affichés aux utilisateurs et les seuils d’alerte par module.</p>
                        </div>
                        <div class="text-slate-700 text-3xl">
                            <i class="fas fa-sliders-h"></i>
                        </div>
                    </div>
                </a>

                <a href="branding_settings.php" class="block p-6 rounded-2xl border border-violet-200 bg-violet-50/50 hover:shadow-lg transition">
                    <div class="flex items-start justify-between"><div><h3 class="text-lg font-semibold text-gray-800">Branding de l’entreprise</h3><p class="text-sm text-gray-500 mt-2">Modifier le logo, l’icône et les informations légales utilisées partout dans FINEA et les PDF.</p></div><div class="text-violet-700 text-3xl"><i class="fas fa-palette"></i></div></div>
                </a>

                <a href="whatsapp_templates.php" class="block p-6 rounded-2xl border border-emerald-200 bg-emerald-50/50 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div><h3 class="text-lg font-semibold text-gray-800">Console WhatsApp · modèles et tests</h3><p class="text-sm text-gray-500 mt-2">Créer les modèles, suivre leur validation Meta, envoyer des tests et consulter les journaux.</p></div>
                        <div class="text-emerald-700 text-3xl"><i class="fab fa-whatsapp"></i></div>
                    </div>
                </a>

                <a href="audit_erp.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Audit ERP</h3>
                            <p class="text-sm text-gray-500 mt-2">Consulter la traçabilité transversale des accès, exports et actions métier.</p>
                        </div>
                        <div class="text-rose-600 text-3xl">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
                </a>

                <a href="access_matrix.php" class="block p-6 rounded-2xl border border-cyan-200 bg-cyan-50/50 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Matrice globale des accès</h3>
                            <p class="text-sm text-gray-500 mt-2">Visualiser, modifier et exporter tous les droits de tous les utilisateurs.</p>
                        </div>
                        <div class="text-cyan-700 text-3xl">
                            <i class="fas fa-table-cells-large"></i>
                        </div>
                    </div>
                </a>

                <a href="audit_acl.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Audit ACL</h3>
                            <p class="text-sm text-gray-500 mt-2">Croiser les pages, cartes du portail, permissions et comptes incoherents.</p>
                        </div>
                        <div class="text-amber-500 text-3xl"><i class="fas fa-shield-halved"></i></div>
                    </div>
                </a>

                <a href="version_finea.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Version & roadmap</h3>
                            <p class="text-sm text-gray-500 mt-2">Consulter la version deployee, les evolutions et la trajectoire FINEA.</p>
                        </div>
                        <div class="text-indigo-700 text-3xl"><i class="fas fa-code-branch"></i></div>
                    </div>
                </a>

                <a href="../decaissement/migration_fiches_ancien_systeme.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Migration fiches</h3>
                            <p class="text-sm text-gray-500 mt-2">Transférer les fiches de décaissement (ancien → nouveau).</p>
                        </div>
                        <div class="text-blue-600 text-3xl">
                            <i class="fas fa-file-import"></i>
                        </div>
                    </div>
                </a>

                <a href="../selection_portail.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Portail</h3>
                            <p class="text-sm text-gray-500 mt-2">Retourner à la sélection de modules.</p>
                        </div>
                        <div class="text-gray-700 text-3xl">
                            <i class="fas fa-th-large"></i>
                        </div>
                    </div>
                </a>

                <a href="../deconnex.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Déconnexion</h3>
                            <p class="text-sm text-gray-500 mt-2">Se déconnecter de l'espace administrateur.</p>
                        </div>
                        <div class="text-red-600 text-3xl">
                            <i class="fas fa-sign-out-alt"></i>
                        </div>
                    </div>
                </a>

                <a href="popup_notifications.php" class="block p-6 rounded-2xl border border-gray-200 hover:shadow-lg transition">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800">Pop-up nouveautés</h3>
                            <p class="text-sm text-gray-500 mt-2">Créer des annonces de nouveaux modules, corrections et mises à jour avec accusé de lecture.</p>
                        </div>
                        <div class="text-red-600 text-3xl">
                            <i class="fas fa-bullhorn"></i>
                        </div>
                    </div>
                </a>


            </div>
        </div>

        <!-- Connexions -->
        <div class="bg-white rounded-2xl shadow-xl p-6 mb-12 border border-gray-100">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Activité des connexions</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-indigo-50 rounded-xl p-5 border border-indigo-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total connexions</p>
                            <p class="text-3xl font-bold text-indigo-700"><?= number_format($totalLogins) ?></p>
                        </div>
                        <div class="bg-indigo-100 p-3 rounded-xl">
                            <i class="fas fa-sign-in-alt text-indigo-600 text-2xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-green-50 rounded-xl p-5 border border-green-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Aujourd'hui</p>
                            <p class="text-3xl font-bold text-green-700"><?= number_format($loginsToday) ?></p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-xl">
                            <i class="fas fa-calendar-day text-green-600 text-2xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-yellow-50 rounded-xl p-5 border border-yellow-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">7 derniers jours</p>
                            <p class="text-3xl font-bold text-yellow-700"><?= number_format($loginsLast7Days) ?></p>
                        </div>
                        <div class="bg-yellow-100 p-3 rounded-xl">
                            <i class="fas fa-calendar-week text-yellow-600 text-2xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            $daysOptions = [7, 30, 90, 365];
            $perPageOptions = [10, 25, 50, 100];
            $baseParams = [
                'days' => $days,
                'per_page' => $perPage,
                'user_id' => $userFilterId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ];
            $offsetForDisplay = ($page - 1) * $perPage;
            $fromRow = $totalInPeriod > 0 ? ($offsetForDisplay + 1) : 0;
            $toRow = $totalInPeriod > 0 ? min($offsetForDisplay + $perPage, $totalInPeriod) : 0;
            $urlCsv = '?' . http_build_query(array_merge($baseParams, ['export' => 'csv']));
            $urlPdf = '?' . http_build_query(array_merge($baseParams, ['export' => 'pdf']));
            $urlPrev = '?' . http_build_query(array_merge($baseParams, ['page' => max(1, $page - 1)]));
            $urlNext = '?' . http_build_query(array_merge($baseParams, ['page' => min($totalPages, $page + 1)]));
            ?>

            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-6">
                <form method="get" class="flex flex-wrap items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Personne</label>
                        <select name="user_id" class="border border-gray-300 rounded-lg px-3 py-2 text-sm min-w-[16rem]">
                            <option value="0" <?= $userFilterId === 0 ? 'selected' : '' ?>>Tous</option>
                            <?php foreach (($usersForFilter ?? []) as $u): ?>
                                <?php
                                $label = trim((string)($u['prenom'] ?? '') . ' ' . (string)($u['nom'] ?? ''));
                                if ($label === '') {
                                    $label = 'Utilisateur #' . (string)($u['id'] ?? '');
                                }
                                $email = trim((string)($u['mail_pro'] ?? ''));
                                ?>
                                <option value="<?= (int)($u['id'] ?? 0) ?>" <?= ((int)($u['id'] ?? 0) === (int)$userFilterId) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label . ($email !== '' ? (' — ' . $email) : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Date début</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Date fin</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Période</label>
                        <select name="days" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <?php foreach ($daysOptions as $opt): ?>
                                <option value="<?= (int)$opt ?>" <?= ((int)$days === (int)$opt) ? 'selected' : '' ?>><?= (int)$opt ?> jours</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Lignes / page</label>
                        <select name="per_page" class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
                            <?php foreach ($perPageOptions as $opt): ?>
                                <option value="<?= (int)$opt ?>" <?= ((int)$perPage === (int)$opt) ? 'selected' : '' ?>><?= (int)$opt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="page" value="1">
                    <button type="submit" class="inline-flex items-center px-4 py-2 bg-gray-800 text-white rounded-lg hover:bg-gray-900 transition text-sm">
                        <i class="fas fa-filter mr-2"></i>Appliquer
                    </button>
                    <div class="text-sm text-gray-600">
                        <?= number_format($totalInPeriod) ?> connexions (<?= htmlspecialchars($periodLabel) ?>)
                        <?php if ($totalInPeriod > 0): ?>
                            — affichées <?= number_format($fromRow) ?> à <?= number_format($toRow) ?>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="flex flex-wrap gap-3">
                    <a href="<?= htmlspecialchars($urlCsv) ?>" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg hover:shadow-sm transition text-sm">
                        <i class="fas fa-file-csv mr-2"></i>Export CSV (Excel)
                    </a>
                    <a href="<?= htmlspecialchars($urlPdf) ?>" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-lg hover:shadow-sm transition text-sm">
                        <i class="fas fa-file-pdf mr-2"></i>Export PDF
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-2xl shadow-sm p-4 border border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Évolution des connexions / jour</h3>
                    <div class="relative h-44">
                        <canvas id="chart-logins-daily" class="absolute inset-0 w-full h-full"></canvas>
                        <?php if (empty($dailyRows)): ?>
                            <div class="absolute inset-0 flex items-center justify-center text-xs text-gray-500">Aucune donnée sur la période</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="bg-white rounded-2xl shadow-sm p-4 border border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Pics de connexions (heure)</h3>
                    <div class="relative h-44">
                        <canvas id="chart-logins-hour" class="absolute inset-0 w-full h-full"></canvas>
                        <?php if (empty($hourRows)): ?>
                            <div class="absolute inset-0 flex items-center justify-center text-xs text-gray-500">Aucune donnée sur la période</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="bg-white rounded-2xl shadow-sm p-4 border border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Accès par personne (Top 10)</h3>
                    <div class="relative h-44">
                        <canvas id="chart-logins-user" class="absolute inset-0 w-full h-full"></canvas>
                        <?php if (empty($byUserRows)): ?>
                            <div class="absolute inset-0 flex items-center justify-center text-xs text-gray-500">Aucune donnée sur la période</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full bg-white rounded-2xl shadow-sm">
                    <thead>
                        <tr class="bg-gray-100 text-left text-sm">
                            <th class="p-3">Utilisateur</th>
                            <th class="p-3">Email</th>
                            <th class="p-3">IP</th>
                            <th class="p-3">Localisation</th>
                            <th class="p-3">FAI</th>
                            <th class="p-3">Agent</th>
                            <th class="p-3">Heure</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($loginRows)): ?>
                            <tr>
                                <td class="p-4 text-center text-gray-500" colspan="7">Aucune connexion enregistrée.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($loginRows as $log): ?>
                                <?php
                                $userLabel = trim((string)($log['prenom'] ?? '') . ' ' . (string)($log['nom'] ?? ''));
                                if ($userLabel === '') {
                                    $userLabel = 'Utilisateur #' . (string)($log['user_id'] ?? '');
                                }
                                $ip = (string)($log['ip'] ?? '');
                                $locParts = array_filter([
                                    trim((string)($log['city'] ?? '')),
                                    trim((string)($log['region'] ?? '')),
                                    trim((string)($log['country'] ?? '')),
                                ], fn($v) => $v !== '');
                                $loc = $locParts ? implode(', ', $locParts) : (isPublicIp($ip) ? '—' : 'IP privée');
                                ?>
                                <tr class="border-t">
                                    <td class="p-3"><?= htmlspecialchars($userLabel) ?></td>
                                    <td class="p-3"><?= htmlspecialchars($log['mail_pro'] ?? '-') ?></td>
                                    <td class="p-3"><?= htmlspecialchars($ip !== '' ? $ip : '-') ?></td>
                                    <td class="p-3"><?= htmlspecialchars($loc) ?></td>
                                    <td class="p-3"><?= htmlspecialchars(trim((string)($log['isp'] ?? '')) !== '' ? (string)$log['isp'] : '—') ?></td>
                                    <td class="p-3 truncate max-w-[24rem]" title="<?= htmlspecialchars($log['user_agent'] ?? '') ?>">
                                        <?= htmlspecialchars(substr((string)($log['user_agent'] ?? '-'), 0, 60)) ?>
                                    </td>
                                    <td class="p-3"><?= htmlspecialchars($log['created_at'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between mt-4 text-sm text-gray-700">
                <div>
                    Page <span class="font-semibold"><?= (int)$page ?></span> / <span class="font-semibold"><?= (int)$totalPages ?></span>
                </div>
                <div class="flex gap-2">
                    <?php if ($page > 1): ?>
                        <a href="<?= htmlspecialchars($urlPrev) ?>" class="px-3 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">&larr; Précédent</a>
                    <?php else: ?>
                        <span class="px-3 py-2 border border-gray-200 rounded-lg text-gray-400">&larr; Précédent</span>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= htmlspecialchars($urlNext) ?>" class="px-3 py-2 border border-gray-300 rounded-lg hover:bg-gray-50">Suivant &rarr;</a>
                    <?php else: ?>
                        <span class="px-3 py-2 border border-gray-200 rounded-lg text-gray-400">Suivant &rarr;</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <script>
            (function() {
                if (typeof window.Chart === 'undefined') {
                    return;
                }

                const chartFrom = <?= json_encode($periodStartDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const chartTo = <?= json_encode($periodEndDate, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const dailyRows = <?= json_encode($dailyRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const hourRows = <?= json_encode($hourRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                const byUserRows = <?= json_encode($byUserRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

                function formatDateLocal(date) {
                    const y = date.getFullYear();
                    const m = String(date.getMonth() + 1).padStart(2, '0');
                    const d = String(date.getDate()).padStart(2, '0');
                    return `${y}-${m}-${d}`;
                }

                // Daily
                const dailyMap = new Map(dailyRows.map(r => [String(r.d), parseInt(r.c, 10) || 0]));
                const dailyLabels = [];
                const dailyCounts = [];
                const from = (typeof chartFrom === 'string' && chartFrom) ? new Date(chartFrom + 'T00:00:00') : null;
                const to = (typeof chartTo === 'string' && chartTo) ? new Date(chartTo + 'T00:00:00') : null;
                if (from && to && !Number.isNaN(from.getTime()) && !Number.isNaN(to.getTime()) && from <= to) {
                    const maxDays = 366;
                    const current = new Date(from);
                    let guard = 0;
                    while (current <= to && guard < maxDays) {
                        const key = formatDateLocal(current);
                        dailyLabels.push(key);
                        dailyCounts.push(dailyMap.get(key) || 0);
                        current.setDate(current.getDate() + 1);
                        guard++;
                    }
                }
                const ctxDaily = document.getElementById('chart-logins-daily');
                if (ctxDaily) {
                    const existing = Chart.getChart(ctxDaily);
                    if (existing) {
                        existing.destroy();
                    }
                    new Chart(ctxDaily, {
                        type: 'line',
                        data: {
                            labels: dailyLabels,
                            datasets: [{
                                label: 'Connexions',
                                data: dailyCounts,
                                tension: 0.25,
                                fill: false,
                                borderWidth: 2,
                                pointRadius: 3,
                                pointHoverRadius: 5
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                x: {
                                    ticks: {
                                        maxTicksLimit: 10
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                }
                            }
                        }
                    });
                }

                // Hour
                const hourCounts = Array.from({
                    length: 24
                }, () => 0);
                for (const r of hourRows) {
                    const h = parseInt(r.h, 10);
                    if (!Number.isNaN(h) && h >= 0 && h < 24) {
                        hourCounts[h] = parseInt(r.c, 10) || 0;
                    }
                }
                const hourLabels = Array.from({
                    length: 24
                }, (_, i) => String(i).padStart(2, '0') + 'h');
                const ctxHour = document.getElementById('chart-logins-hour');
                if (ctxHour) {
                    const existing = Chart.getChart(ctxHour);
                    if (existing) {
                        existing.destroy();
                    }
                    new Chart(ctxHour, {
                        type: 'bar',
                        data: {
                            labels: hourLabels,
                            datasets: [{
                                label: 'Connexions',
                                data: hourCounts,
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                }
                            }
                        }
                    });
                }

                // By user
                const userLabels = byUserRows.map(r => String(r.label || ''));
                const userCounts = byUserRows.map(r => parseInt(r.c, 10) || 0);
                const ctxUser = document.getElementById('chart-logins-user');
                if (ctxUser) {
                    const existing = Chart.getChart(ctxUser);
                    if (existing) {
                        existing.destroy();
                    }
                    new Chart(ctxUser, {
                        type: 'bar',
                        data: {
                            labels: userLabels,
                            datasets: [{
                                label: 'Connexions',
                                data: userCounts,
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                },
                                x: {
                                    ticks: {
                                        autoSkip: false,
                                        maxRotation: 60,
                                        minRotation: 0
                                    }
                                }
                            }
                        }
                    });
                }
            })();
        </script>


    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>

