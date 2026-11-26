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
        curl_setopt($ch, CURLOPT_USERAGENT, 'finea-admin/1.0');
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
            'header' => "User-Agent: finea-admin/1.0\r\n",
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

<link rel="stylesheet" href="dashboard-admin.css?v=<?= filemtime(__DIR__.'/dashboard-admin.css') ?>">
<div class="admin-hub">
<div class="admin-hub-inner">
<header class="admin-hub-header">
<div class="admin-identity"><?php $adminLogo = \CompanyBranding::logoUrl(rtrim(\AccessManager::getApplicationBasePath(),'/').'/'); if($adminLogo): ?><img src="<?= htmlspecialchars($adminLogo,ENT_QUOTES,'UTF-8') ?>" alt="Logo entreprise"><?php endif; ?><div><span class="admin-eyebrow">FINEA · Administration système</span><h1>Tableau de bord</h1><p>Supervision des comptes, accès et paramètres de l’entreprise.</p></div></div>
<div class="admin-header-actions"><a href="../selection_portail.php" class="admin-action"><i class="fas fa-th-large"></i> Portail</a><a href="add_user.php" class="admin-action admin-primary"><i class="fas fa-user-plus"></i> Nouvel utilisateur</a></div>
</header>
<section class="admin-kpis" aria-label="Indicateurs utilisateurs">
<article><i class="fas fa-users"></i><span>Utilisateurs</span><strong><?= number_format($totalUsers) ?></strong></article>
<article><i class="fas fa-user-check"></i><span>Comptes actifs</span><strong><?= number_format($activeUsers) ?></strong></article>
<article><i class="fas fa-user-slash"></i><span>Comptes inactifs</span><strong><?= number_format($inactiveUsers) ?></strong></article>
<article><i class="fas fa-calendar-plus"></i><span>Nouveaux ce mois</span><strong><?= number_format($newUsersThisMonth) ?></strong></article>
</section>
<section class="admin-directory"><div class="admin-section-heading"><div><h2>Centre de gestion</h2><p>Ouvrez une catégorie ou recherchez une fonction.</p></div><input id="admin-tool-search" type="search" placeholder="Rechercher un outil…" aria-label="Rechercher un outil"></div>
<div class="admin-groups"><details class="admin-group"><summary><i class="fas fa-user-shield"></i><span>Utilisateurs et sécurité<small>7 fonctions</small></span><i class="fas fa-chevron-down admin-chevron"></i></summary><div class="admin-links"><a href="liste_users.php"><span><strong>Utilisateurs</strong><small>Voir, modifier et activer/désactiver des comptes.</small></span><i class="fas fa-angle-right"></i></a><a href="add_user.php"><span><strong>Ajouter</strong><small>Créer un nouveau compte utilisateur.</small></span><i class="fas fa-angle-right"></i></a><a href="gestion_acces.php"><span><strong>Permissions</strong><small>Gérer les droits d'accès par utilisateur.</small></span><i class="fas fa-angle-right"></i></a><a href="access_matrix.php"><span><strong>Matrice globale des accès</strong><small>Visualiser, modifier et exporter tous les droits de tous les utilisateurs.</small></span><i class="fas fa-angle-right"></i></a><a href="audit_acl.php"><span><strong>Audit ACL</strong><small>Croiser les pages, cartes du portail, permissions et comptes incoherents.</small></span><i class="fas fa-angle-right"></i></a><a href="audit_erp.php"><span><strong>Audit ERP</strong><small>Consulter la traçabilité transversale des accès, exports et actions métier.</small></span><i class="fas fa-angle-right"></i></a><a href="access_request_support_settings.php"><span><strong>Support accès</strong><small>Choisir quels employés du service informatique reçoivent les demandes d'accès et suivre les dernières soumissions.</small></span><i class="fas fa-angle-right"></i></a></div></details><details class="admin-group"><summary><i class="fas fa-palette"></i><span>Portail et identité<small>5 fonctions</small></span><i class="fas fa-chevron-down admin-chevron"></i></summary><div class="admin-links"><a href="portal_modules.php"><span><strong>Modules du portail</strong></span><i class="fas fa-angle-right"></i></a><a href="branding_settings.php"><span><strong>Branding de l’entreprise</strong><small>Modifier le logo, l’icône et les informations légales utilisées partout dans FINEA et les PDF.</small></span><i class="fas fa-angle-right"></i></a><a href="portal_badges_settings.php"><span><strong>Paramétrage badges portail</strong><small>Régler ce qui est compté, les textes affichés aux utilisateurs et les seuils d’alerte par module.</small></span><i class="fas fa-angle-right"></i></a><a href="portal_badges_debug.php"><span><strong>Debug badges portail</strong><small>Contrôler les signaux métier, seuils et compteurs qui alimentent les badges du portail.</small></span><i class="fas fa-angle-right"></i></a><a href="popup_notifications.php"><span><strong>Pop-up nouveautés</strong><small>Créer des annonces de nouveaux modules, corrections et mises à jour avec accusé de lecture.</small></span><i class="fas fa-angle-right"></i></a></div></details><details class="admin-group"><summary><i class="fas fa-bell"></i><span>Notifications et messagerie<small>5 fonctions</small></span><i class="fas fa-chevron-down admin-chevron"></i></summary><div class="admin-links"><a href="notifications_settings.php"><span><strong>Notifications</strong><small>Configurer évènements, destinataires et canaux (système/email).</small></span><i class="fas fa-angle-right"></i></a><a href="notification_matrix.php"><span><strong>Matrice notifications personnel</strong><small>Desactiver en un clic toutes les notifications FINEA et piloter Email/Systeme par personne.</small></span><i class="fas fa-angle-right"></i></a><a href="email_logs.php"><span><strong>Emails système</strong><small>Consulter les emails envoyés, erreurs SMTP et ouvertures.</small></span><i class="fas fa-angle-right"></i></a><a href="notification_email_tester.php"><span><strong>Test notifications email</strong><small>Prévisualiser les variables dynamiques, choisir un destinataire test et rejouer l’envoi réel pour contrôle anti-spam.</small></span><i class="fas fa-angle-right"></i></a><a href="whatsapp_templates.php"><span><strong>Console WhatsApp · modèles et tests</strong><small>Créer les modèles, suivre leur validation Meta, envoyer des tests et consulter les journaux.</small></span><i class="fas fa-angle-right"></i></a></div></details><details class="admin-group"><summary><i class="fas fa-sliders-h"></i><span>Paramètres métiers<small>10 fonctions</small></span><i class="fas fa-chevron-down admin-chevron"></i></summary><div class="admin-links"><a href="fne_tools.php"><span><strong>Outils FNE</strong><small>Tester signature, avoir, verify facture &amp; contrôle décaissement.</small></span><i class="fas fa-angle-right"></i></a><a href="workflow_fiches_admin.php"><span><strong>Workflow fiches</strong><small>Repositionner une fiche, convertir un total en partiel et suivre la famille parent/enfant.</small></span><i class="fas fa-angle-right"></i></a><a href="feb_ocr_settings.php"><span><strong>OCR FEB</strong></span><i class="fas fa-angle-right"></i></a><a href="relink_fiches_debourse.php"><span><strong>Reliaison fiches</strong><small>Lister les fiches non reliées et choisir manuellement la bonne ligne de déboursé.</small></span><i class="fas fa-angle-right"></i></a><a href="logistics_fuel_tools.php"><span><strong>Outils carburant logistique</strong><small>Generation des lignes carburant et rattrapage admin des bons manquants.</small></span><i class="fas fa-angle-right"></i></a><a href="attendance_control_center.php"><span><strong>Centre contrôle pointage</strong></span><i class="fas fa-angle-right"></i></a><a href="remaniement_matricules.php"><span><strong>Remaniement matricules RH</strong></span><i class="fas fa-angle-right"></i></a><a href="planning_meeting_settings.php"><span><strong>Paramètres des réunions</strong><small>Définir le chantier Bureau utilisé pour organiser les réunions par service.</small></span><i class="fas fa-angle-right"></i></a><a href="positioning_settings.php"><span><strong>Paramétrage géolocalisation</strong><small>Ouvrir directement les réglages GPS fournisseurs et lancer le géocodage des adresses existantes.</small></span><i class="fas fa-angle-right"></i></a><a href="../decaissement/migration_fiches_ancien_systeme.php"><span><strong>Migration fiches</strong><small>Transférer les fiches de décaissement (ancien → nouveau).</small></span><i class="fas fa-angle-right"></i></a></div></details><details class="admin-group"><summary><i class="fas fa-server"></i><span>Système et intelligence<small>6 fonctions</small></span><i class="fas fa-chevron-down admin-chevron"></i></summary><div class="admin-links"><a href="db_tools.php"><span><strong>Base de données</strong><small>Statistiques et export SQL (admin).</small></span><i class="fas fa-angle-right"></i></a><a href="version_finea.php"><span><strong>Version &amp; roadmap</strong><small>Consulter la version deployee, les evolutions et la trajectoire FINEA.</small></span><i class="fas fa-angle-right"></i></a><a href="search_engine_training.php"><span><strong>Entraîner le moteur</strong></span><i class="fas fa-angle-right"></i></a><a href="ai_evolution.php"><span><strong>Evolution IA</strong><small>Voir l'apprentissage IA, les signaux chantiers et les projections de decaissement.</small></span><i class="fas fa-angle-right"></i></a><a href="ai_insights_360.php"><span><strong>Intelligence 360°</strong></span><i class="fas fa-angle-right"></i></a><a href="../dashboard_dg.php"><span><strong>Dashboard DG</strong></span><i class="fas fa-angle-right"></i></a></div></details></div><p id="admin-no-tools" hidden>Aucun outil correspondant.</p>
</section>
<details class="admin-activity" id="admin-activity" <?= !empty($_GET) ? 'open' : '' ?>><summary><span><i class="fas fa-chart-line"></i> Activité des connexions</span><span class="admin-activity-count"><?= number_format($loginsToday) ?> aujourd’hui <i class="fas fa-chevron-down"></i></span></summary>
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

        </details>
        <div class="admin-bottom"><a href="../deconnex.php">Déconnexion</a></div>
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
        <script src="dashboard-admin.js?v=<?= filemtime(__DIR__.'/dashboard-admin.js') ?>"></script>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>

