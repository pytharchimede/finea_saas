<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/User.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CompanyBranding.php';

$pdo = Database::getConnection();

$userModel = new User($pdo);
$userModel->ensurePersonnelLinkSupport();

// ── Vérification colonne feb ─────────────────────────────────────────────────
$hasFebColumn = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM user_devis_banamur LIKE 'feb_validation_services'")->fetch(PDO::FETCH_ASSOC);
    $hasFebColumn = !empty($col);
} catch (Throwable $e) {
    $hasFebColumn = false;
}

// ── Libellés services ────────────────────────────────────────────────────────
$serviceLabelById = [];
try {
    $svcStmt = $pdo->query("SELECT id_service, lib_service FROM service ORDER BY lib_service ASC");
    while ($row = $svcStmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)($row['id_service'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $label = trim((string)($row['lib_service'] ?? ''));
        $serviceLabelById[$id] = $label !== '' ? $label : ('#' . $id);
    }
} catch (Throwable $e) {
    // Ignoré
}

// ── Rôles ────────────────────────────────────────────────────────────────────
$rolesById = [];
try {
    $roleStmt = $pdo->query("SELECT id_role_devis, lib_role_devis FROM role_devis_banamur");
    while ($row = $roleStmt->fetch(PDO::FETCH_ASSOC)) {
        $rolesById[(int)$row['id_role_devis']] = trim((string)$row['lib_role_devis']);
    }
} catch (Throwable $e) {
    // Ignoré
}

// ── Filtres GET ──────────────────────────────────────────────────────────────
$fQ       = trim((string)($_GET['q'] ?? ''));
$fStatus  = $_GET['status'] ?? 'all';
$fLinked  = $_GET['linked_personnel'] ?? 'all';
$fAdmin   = $_GET['admin'] ?? 'all';
$fFebMode = $_GET['feb_mode'] ?? 'all';
$fRoleId  = isset($_GET['role_id']) && is_numeric($_GET['role_id']) ? (int)$_GET['role_id'] : 0;

if (!in_array($fStatus, ['all', 'active', 'inactive'], true)) {
    $fStatus = 'all';
}
if (!in_array($fLinked, ['all', 'yes', 'no'], true)) {
    $fLinked = 'all';
}
if (!in_array($fAdmin, ['all', 'yes', 'no'], true)) {
    $fAdmin = 'all';
}
if (!in_array($fFebMode, ['all', 'configured', 'global', 'specific', 'empty'], true)) {
    $fFebMode = 'all';
}

$hasActiveFilter = $fQ !== ''
    || $fStatus !== 'all'
    || $fLinked !== 'all'
    || $fAdmin !== 'all'
    || $fFebMode !== 'all'
    || $fRoleId > 0;

// ── Requête filtrée ──────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($fQ !== '') {
    $where[] = "(u.prenom LIKE :q OR u.nom LIKE :q OR u.mail_pro LIKE :q
                 OR p.nom_personnel_soignant LIKE :q
                 OR s.lib_service LIKE :q
                 OR r.lib_role_devis LIKE :q)";
    $params['q'] = '%' . $fQ . '%';
}

if ($fStatus === 'active') {
    $where[] = "u.active = 1";
} elseif ($fStatus === 'inactive') {
    $where[] = "u.active = 0";
}

if ($fLinked === 'yes') {
    $where[] = "u.personnel_id IS NOT NULL";
} elseif ($fLinked === 'no') {
    $where[] = "(u.personnel_id IS NULL OR u.personnel_id = 0)";
}

if ($fAdmin === 'yes') {
    $where[] = "u.acces_admin = 1";
} elseif ($fAdmin === 'no') {
    $where[] = "(u.acces_admin IS NULL OR u.acces_admin = 0)";
}

if ($hasFebColumn && $fFebMode !== 'all') {
    if ($fFebMode === 'empty') {
        $where[] = "(u.feb_validation_services IS NULL OR TRIM(u.feb_validation_services) = '')";
    } elseif ($fFebMode === 'global') {
        $where[] = "LOWER(TRIM(u.feb_validation_services)) IN ('*','all','tous')";
    } elseif ($fFebMode === 'specific') {
        $where[] = "(u.feb_validation_services IS NOT NULL AND TRIM(u.feb_validation_services) != ''
                     AND LOWER(TRIM(u.feb_validation_services)) NOT IN ('*','all','tous'))";
    } elseif ($fFebMode === 'configured') {
        $where[] = "(u.feb_validation_services IS NOT NULL AND TRIM(u.feb_validation_services) != '')";
    }
}

if ($fRoleId > 0) {
    $where[] = "u.role_id = :role_id";
    $params['role_id'] = $fRoleId;
}

$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$febSelect   = $hasFebColumn ? "u.feb_validation_services" : "NULL";

$sql = "SELECT
    u.id, u.prenom, u.nom, u.mail_pro, u.active, u.acces_admin,
    {$febSelect} AS feb_validation_services,
    u.personnel_id,
    u.role_id,
    r.lib_role_devis AS role_label,
    p.nom_personnel_soignant AS personnel_full_name,
    COALESCE(NULLIF(TRIM(s.lib_service), ''), '') AS personnel_service_label
FROM user_devis_banamur u
LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
LEFT JOIN service s ON s.id_service = p.service_id
LEFT JOIN role_devis_banamur r ON r.id_role_devis = u.role_id
{$whereClause}
ORDER BY u.nom ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ── KPIs sur le jeu filtré ───────────────────────────────────────────────────
$kpiTotal     = count($users);
$kpiActifs    = 0;
$kpiInactifs  = 0;
$kpiPersonnel = 0;
$kpiAdmins    = 0;
$kpiFebConf   = 0;

foreach ($users as $u) {
    if ($u['active']) {
        $kpiActifs++;
    } else {
        $kpiInactifs++;
    }
    if (!empty($u['personnel_id'])) {
        $kpiPersonnel++;
    }
    if ($u['acces_admin']) {
        $kpiAdmins++;
    }
    $febVal = trim((string)($u['feb_validation_services'] ?? ''));
    if ($febVal !== '') {
        $kpiFebConf++;
    }
}

// ── Helper FEB ───────────────────────────────────────────────────────────────
function pdf_feb_label(?string $raw, array $serviceLabelById): string
{
    $value = trim((string)$raw);
    $lower = strtolower($value);

    if ($value === '') {
        return 'Non configuré';
    }
    if (in_array($lower, ['*', 'all', 'tous'], true)) {
        return 'Tous les services';
    }

    $parts = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
    $labels = [];
    foreach ($parts as $p) {
        if (!is_numeric($p)) {
            continue;
        }
        $id = (int)$p;
        if ($id > 0) {
            $labels[] = $serviceLabelById[$id] ?? ('#' . $id);
        }
    }
    if (!$labels) {
        return 'Liste vide';
    }
    if (count($labels) > 3) {
        return implode(', ', array_slice($labels, 0, 3)) . ' (+' . (count($labels) - 3) . ')';
    }
    return implode(', ', $labels);
}

// ── Résumé filtres ───────────────────────────────────────────────────────────
$filterSummaryParts = [];
if ($fQ !== '') {
    $filterSummaryParts[] = 'Recherche : "' . $fQ . '"';
}
if ($fStatus !== 'all') {
    $filterSummaryParts[] = 'Statut : ' . ($fStatus === 'active' ? 'Actif' : 'Inactif');
}
if ($fLinked !== 'all') {
    $filterSummaryParts[] = 'Lié au personnel : ' . ($fLinked === 'yes' ? 'Oui' : 'Non');
}
if ($fAdmin !== 'all') {
    $filterSummaryParts[] = 'Admin : ' . ($fAdmin === 'yes' ? 'Oui' : 'Non');
}
if ($fFebMode !== 'all') {
    $febModeLabels = [
        'configured' => 'Configuré',
        'global'     => 'Global (tous)',
        'specific'   => 'Services spécifiques',
        'empty'      => 'Non configuré',
    ];
    $filterSummaryParts[] = 'FEB : ' . ($febModeLabels[$fFebMode] ?? $fFebMode);
}
if ($fRoleId > 0) {
    $roleName = $rolesById[$fRoleId] ?? ('#' . $fRoleId);
    $filterSummaryParts[] = 'Rôle : ' . $roleName;
}
if (!$filterSummaryParts) {
    $filterSummaryParts[] = 'Aucun filtre — liste complète';
}
$filterSummaryParts[] = 'Exporté le : ' . date('d/m/Y à H:i');

// ── Classe PDF FINAEA-style ──────────────────────────────────────────────────
if (!class_exists('PDFUsersFinaea')) {
    class PDFUsersFinaea extends \TCPDF
    {
        public string $logoPath = '';
        public string $exportedAt = '';
        public string $reportTitle = 'Liste des utilisateurs';

        public function Header(): void
        {
            // Barre de fond blanche
            $this->SetFillColor(255, 255, 255);
            $this->Rect(0, 0, $this->getPageWidth(), 28, 'F');

            // Ligne de séparation slate
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.3);
            $this->Line(10, 27, $this->getPageWidth() - 10, 27);

            // Accent indigo
            $this->SetFillColor(99, 102, 241);
            $this->Rect(10, 27, 35, 1.1, 'F');

            // Logo si disponible
            if ($this->logoPath !== '' && is_file($this->logoPath)) {
                $this->Image(
                    $this->logoPath,
                    10,
                    5.5,
                    26,
                    0,
                    '',
                    '',
                    '',
                    false,
                    300,
                    '',
                    false,
                    false,
                    0,
                    false,
                    false,
                    false
                );
                $titleX = 40;
            } else {
                $titleX = 10;
            }

            // Nom de l'app
            $this->SetXY($titleX, 6);
            $this->SetFont('dejavusans', 'B', 13);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(0, 5.5, 'Finéa', 0, 1, 'L');

            // Slogan
            $this->SetXY($titleX, 12.5);
            $this->SetFont('dejavusans', '', 7.5);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 4, 'Gestion & Planification', 0, 1, 'L');

            // Titre du rapport (côté droit)
            $this->SetXY($this->getPageWidth() - 100, 6.5);
            $this->SetFont('dejavusans', 'B', 9);
            $this->SetTextColor(99, 102, 241);
            $this->Cell(90, 4.5, $this->reportTitle, 0, 1, 'R');

            // Badge "Administration"
            $badgeW = 42;
            $badgeX = $this->getPageWidth() - $badgeW - 10;
            $this->SetFillColor(238, 242, 255);
            $this->RoundedRect($badgeX, 13, $badgeW, 6.5, 2, '1111', 'F');
            $this->SetXY($badgeX, 14.2);
            $this->SetFont('dejavusans', 'B', 6.8);
            $this->SetTextColor(99, 102, 241);
            $this->Cell($badgeW, 3.5, 'Administration', 0, 0, 'C');
        }

        public function Footer(): void
        {
            $this->SetY(-15);
            $this->SetDrawColor(226, 232, 240);
            $this->SetLineWidth(0.3);
            $this->Line(10, $this->GetY(), $this->getPageWidth() - 10, $this->GetY());
            $this->Ln(2.5);
            $this->SetFont('dejavusans', '', 7.2);
            $this->SetTextColor(148, 163, 184);

            $left = $this->exportedAt !== '' ? $this->exportedAt : 'Export — Finéa';
            $right = 'Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages();
            $this->Cell(($this->getPageWidth() - 20) / 2, 4, $left, 0, 0, 'L');
            $this->Cell(($this->getPageWidth() - 20) / 2, 4, $right, 0, 0, 'R');
        }
    }
}

// ── Construction du PDF ──────────────────────────────────────────────────────
$pdf = new PDFUsersFinaea('L', 'mm', 'A4');
$pdf->SetCreator('Finéa / Banamur');
$pdf->SetAuthor('Finéa');
$pdf->SetTitle('Liste des utilisateurs');
$pdf->SetMargins(10, 32, 10);
$pdf->SetAutoPageBreak(true, 16);

// Chercher le logo
$logoPath = CompanyBranding::logoFile($pdo);
if (!is_file($logoPath)) {
    $logoPath = __DIR__ . '/../logo/default_logo.jpg';
}
if (!is_file($logoPath)) {
    $logoPath = __DIR__ . '/../img/logo_veritas.jpg';
}
$pdf->logoPath     = is_file($logoPath) ? $logoPath : '';
$pdf->exportedAt   = 'Export liste utilisateurs — ' . date('d/m/Y H:i');
$pdf->reportTitle  = $hasActiveFilter ? 'Utilisateurs filtrés' : 'Tous les utilisateurs';

$pdf->AddPage();
$pageW = $pdf->getPageWidth();
$innerW = $pageW - 20; // marges 10mm de chaque côté

// ── Section filtres ──────────────────────────────────────────────────────────
$pdf->SetFont('dejavusans', 'B', 8.5);
$pdf->SetTextColor(99, 102, 241);
$pdf->Cell(0, 5, 'Filtres appliqués', 0, 1, 'L');
$pdf->SetLineWidth(0.25);
$pdf->SetDrawColor(199, 210, 254);
$pdf->Line(10, $pdf->GetY(), $pageW - 10, $pdf->GetY());
$pdf->Ln(2);

$pdf->SetFont('dejavusans', '', 8);
$pdf->SetTextColor(71, 85, 105);
foreach ($filterSummaryParts as $fLine) {
    $pdf->MultiCell(0, 4.5, $fLine, 0, 'L', false, 1);
}
$pdf->Ln(3);

// ── KPI boxes ────────────────────────────────────────────────────────────────
$kpis = [
    ['label' => 'Total',           'value' => (string)$kpiTotal,     'fill' => [238, 242, 255], 'text' => [67,  56, 202]],
    ['label' => 'Actifs',          'value' => (string)$kpiActifs,    'fill' => [209, 250, 229], 'text' => [6,  95,  70]],
    ['label' => 'Inactifs',        'value' => (string)$kpiInactifs,  'fill' => [254, 226, 226], 'text' => [153, 27, 27]],
    ['label' => 'Liés personnel',  'value' => (string)$kpiPersonnel, 'fill' => [240, 249, 255], 'text' => [3,  105, 161]],
    ['label' => 'Admins',          'value' => (string)$kpiAdmins,    'fill' => [245, 243, 255], 'text' => [109, 40, 217]],
    ['label' => 'FEB configuré',   'value' => (string)$kpiFebConf,   'fill' => [255, 247, 237], 'text' => [154, 52,  18]],
];

$nKpi    = count($kpis);
$kpiGap  = 3;
$kpiH    = 18;
$kpiW    = ($innerW - ($kpiGap * ($nKpi - 1))) / $nKpi;
$kpiY    = $pdf->GetY();
$kpiX    = 10;

foreach ($kpis as $i => $kpi) {
    $x = $kpiX + $i * ($kpiW + $kpiGap);
    $pdf->SetFillColor($kpi['fill'][0], $kpi['fill'][1], $kpi['fill'][2]);
    $pdf->SetDrawColor(220, 220, 230);
    $pdf->SetLineWidth(0.25);
    $pdf->RoundedRect($x, $kpiY, $kpiW, $kpiH, 2.5, '1111', 'DF');

    $pdf->SetXY($x + 3, $kpiY + 3);
    $pdf->SetFont('dejavusans', '', 7);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell($kpiW - 6, 4, $kpi['label'], 0, 2, 'L');

    $pdf->SetX($x + 3);
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->SetTextColor($kpi['text'][0], $kpi['text'][1], $kpi['text'][2]);
    $pdf->Cell($kpiW - 6, 7, $kpi['value'], 0, 0, 'L');
}

$pdf->SetXY(10, $kpiY + $kpiH + 5);

// ── Tableau ──────────────────────────────────────────────────────────────────
$cols = [
    ['label' => 'Nom / Prénom',         'w' => 38, 'align' => 'L'],
    ['label' => 'Email',                'w' => 48, 'align' => 'L'],
    ['label' => 'Rôle',                 'w' => 30, 'align' => 'L'],
    ['label' => 'Personnel / Service',  'w' => 44, 'align' => 'L'],
    ['label' => 'Statut',               'w' => 18, 'align' => 'C'],
    ['label' => 'Admin',                'w' => 16, 'align' => 'C'],
    ['label' => 'Validation FEB',       'w' => 63, 'align' => 'L'],
];

// Ajuster les largeurs pour remplir exactement la page
$totalColW = array_sum(array_column($cols, 'w'));
$scaleFactor = $innerW / $totalColW;
foreach ($cols as &$col) {
    $col['w'] = round($col['w'] * $scaleFactor, 2);
}
unset($col);

$drawTableHeader = static function () use ($pdf, $cols): void {
    $pdf->SetFillColor(238, 242, 255);
    $pdf->SetDrawColor(199, 210, 254);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->SetFont('dejavusans', 'B', 7.5);
    $pdf->SetLineWidth(0.3);

    $heights = [];
    foreach ($cols as $col) {
        $heights[] = $pdf->getStringHeight($col['w'], $col['label']);
    }
    $headerH = max(max($heights), 7);

    foreach ($cols as $col) {
        $pdf->MultiCell($col['w'], $headerH, $col['label'], 'B', $col['align'], true, 0, null, null, true, 0, false, true, $headerH, 'M');
    }
    $pdf->Ln();
};

$drawTableHeader();

$pdf->SetFont('dejavusans', '', 8);
$pdf->SetDrawColor(226, 232, 240);
$pdf->SetLineWidth(0.2);

$altRow = false;
foreach ($users as $u) {
    $name      = trim($u['prenom'] . ' ' . $u['nom']);
    $email     = trim((string)($u['mail_pro'] ?? ''));
    $role      = trim((string)($u['role_label'] ?? '—'));
    $personnel = trim((string)($u['personnel_full_name'] ?? ''));
    $service   = trim((string)($u['personnel_service_label'] ?? ''));
    if ($personnel === '' && !empty($u['personnel_id'])) {
        $personnel = '#' . $u['personnel_id'];
    }
    $personnelCell = $personnel !== '' ? ($personnel . ($service !== '' ? "\n" . $service : '')) : '—';
    $statut    = $u['active'] ? 'Actif' : 'Inactif';
    $admin     = $u['acces_admin'] ? 'Oui' : '—';
    $feb       = pdf_feb_label($u['feb_validation_services'] ?? null, $serviceLabelById);

    $rowData = [
        $name,
        $email,
        $role !== '' ? $role : '—',
        $personnelCell,
        $statut,
        $admin,
        $feb,
    ];

    // Calcul hauteur de ligne
    $heights = [];
    foreach ($cols as $idx => $col) {
        $heights[] = $pdf->getStringHeight($col['w'], $rowData[$idx]);
    }
    $rowH = max(max($heights), 7);

    // Saut de page automatique
    if ($pdf->GetY() + $rowH > ($pdf->getPageHeight() - 18)) {
        $pdf->AddPage();
        $drawTableHeader();
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetDrawColor(226, 232, 240);
        $altRow = false;
    }

    // Fond alterné — capturer avant le flip pour une restauration claire
    $baseRowFill = $altRow ? [248, 250, 252] : [255, 255, 255];
    $pdf->SetFillColor($baseRowFill[0], $baseRowFill[1], $baseRowFill[2]);
    $altRow = !$altRow;

    // Couleur de la cellule statut
    foreach ($cols as $idx => $col) {
        $cellText  = $rowData[$idx];
        $fillColor = null;
        $textColor = [55, 65, 81];

        if ($col['label'] === 'Statut') {
            if ($cellText === 'Actif') {
                $fillColor = [209, 250, 229];
                $textColor = [6, 95, 70];
            } else {
                $fillColor = [254, 226, 226];
                $textColor = [153, 27, 27];
            }
        } elseif ($col['label'] === 'Admin' && $cellText === 'Oui') {
            $fillColor = [238, 242, 255];
            $textColor = [67, 56, 202];
        }

        if ($fillColor) {
            $pdf->SetFillColor($fillColor[0], $fillColor[1], $fillColor[2]);
        } else {
            $pdf->SetFillColor($baseRowFill[0], $baseRowFill[1], $baseRowFill[2]);
        }

        $pdf->SetTextColor($textColor[0], $textColor[1], $textColor[2]);
        $pdf->MultiCell(
            $col['w'],
            $rowH,
            $cellText,
            'B',
            $col['align'],
            true,
            0,
            null,
            null,
            true,
            0,
            false,
            true,
            $rowH,
            'M'
        );
    }
    $pdf->Ln();
}

// ── Sortie ───────────────────────────────────────────────────────────────────
$exportedAt = date('Ymd_Hi');
$scopeLabel = $hasActiveFilter ? 'filtres' : 'complet';
$fileName   = 'utilisateurs_' . $scopeLabel . '_' . $exportedAt . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));

$pdf->Output($fileName, 'I');
exit;
