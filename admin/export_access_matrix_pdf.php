<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/AccessMatrixService.php';

$pdo = Database::getConnection();
$query = trim((string) ($_GET['q'] ?? ''));
$statusInput = (string) ($_GET['status'] ?? 'all');
$status = in_array($statusInput, ['all', 'active', 'inactive'], true) ? $statusInput : 'all';
$group = trim((string) ($_GET['group'] ?? ''));
$catalog = AccessMatrixService::catalog($pdo);
if ($group !== '') {
    $catalog = array_filter($catalog, static fn(array $permission): bool => $permission['group'] === $group);
}
$users = AccessMatrixService::users($pdo, $query, $status);
$stats = AccessMatrixService::stats($users, $catalog);

class AccessMatrixPdf extends TCPDF
{
    public string $sectionTitle = '';

    public function Header(): void
    {
        $this->SetFillColor(15, 23, 42);
        $this->Rect(0, 0, $this->getPageWidth(), 25, 'F');
        $this->SetXY(10, 5);
        $this->SetFont('dejavusans', 'B', 15);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(90, 6, 'FINEA', 0, 1);
        $this->SetXY(10, 12);
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(165, 243, 252);
        $this->Cell(100, 5, 'Matrice globale des accès', 0, 0);
        $this->SetXY($this->getPageWidth() - 125, 7);
        $this->SetFont('dejavusans', 'B', 9);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(115, 5, $this->sectionTitle, 0, 1, 'R');
        $this->SetFillColor(34, 211, 238);
        $this->Rect(0, 25, $this->getPageWidth(), 1.2, 'F');
    }

    public function Footer(): void
    {
        $this->SetY(-12);
        $this->SetDrawColor(226, 232, 240);
        $this->Line(10, $this->GetY(), $this->getPageWidth() - 10, $this->GetY());
        $this->Ln(2);
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(120, 4, 'FINEA | Administration | Document confidentiel', 0, 0);
        $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

$pdf = new AccessMatrixPdf('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('FINEA');
$pdf->SetAuthor('FINEA Administration');
$pdf->SetTitle('Matrice globale des accès');
$pdf->SetMargins(10, 31, 10);
$pdf->SetAutoPageBreak(true, 16);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

$pdf->sectionTitle = 'Synthèse de gouvernance';
$pdf->AddPage();
$pdf->SetFont('dejavusans', 'B', 20);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(0, 10, 'Rapport des accès utilisateurs', 0, 1);
$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(71, 85, 105);
$scope = 'Édité le ' . date('d/m/Y à H:i')
    . ' | Périmètre : ' . ($status === 'all' ? 'tous les comptes' : ($status === 'active' ? 'comptes actifs' : 'comptes inactifs'))
    . ($group !== '' ? ' | Famille : ' . $group : '')
    . ($query !== '' ? ' | Recherche : ' . $query : '');
$pdf->MultiCell(0, 5, $scope, 0, 'L');
$pdf->Ln(5);

$kpis = [
    ['Comptes', (string) $stats['users'], [238, 242, 255], [55, 48, 163]],
    ['Comptes actifs', (string) $stats['active_users'], [236, 253, 245], [4, 120, 87]],
    ['Droits analysés', (string) $stats['permissions'], [236, 254, 255], [14, 116, 144]],
    ['Accès accordés', (string) $stats['enabled'], [255, 247, 237], [194, 65, 12]],
    ['Couverture', number_format($stats['coverage'], 1, ',', ' ') . ' %', [245, 243, 255], [109, 40, 217]],
];
$gap = 4;
$boxW = (277 - ($gap * 4)) / 5;
$x = 10;
$y = $pdf->GetY();
foreach ($kpis as [$label, $value, $fill, $text]) {
    $pdf->SetFillColor(...$fill);
    $pdf->RoundedRect($x, $y, $boxW, 23, 2.5, '1111', 'F');
    $pdf->SetXY($x + 3, $y + 4);
    $pdf->SetFont('dejavusans', '', 7);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell($boxW - 6, 4, $label, 0, 1);
    $pdf->SetX($x + 3);
    $pdf->SetFont('dejavusans', 'B', 14);
    $pdf->SetTextColor(...$text);
    $pdf->Cell($boxW - 6, 8, $value, 0, 0);
    $x += $boxW + $gap;
}
$pdf->SetY($y + 31);
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->SetTextColor(15, 23, 42);
$pdf->Cell(0, 6, 'Lecture du rapport', 0, 1);
$pdf->SetFont('dejavusans', '', 8.5);
$pdf->SetTextColor(71, 85, 105);
$pdf->MultiCell(0, 5, "Les pages suivantes présentent les droits par famille. ✓ signifie accordé, — signifie non accordé et ● identifie un accès commun obligatoire. Le découpage en blocs préserve la lisibilité même lorsque le catalogue comporte de nombreux droits.", 0, 'L');

$byGroup = [];
foreach ($catalog as $permission) {
    $byGroup[$permission['group']][] = $permission;
}

foreach ($byGroup as $groupName => $permissions) {
    foreach (array_chunk($permissions, 8) as $chunkIndex => $chunk) {
        $pdf->sectionTitle = $groupName . (count($permissions) > 8 ? ' | Bloc ' . ($chunkIndex + 1) : '');
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', 'B', 13);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 7, $pdf->sectionTitle, 0, 1);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(0, 5, count($users) . ' compte(s) | ' . count($chunk) . ' droit(s) dans ce bloc', 0, 1);
        $pdf->Ln(2);

        $nameW = 55;
        $statusW = 16;
        $permissionW = (277 - $nameW - $statusW) / max(1, count($chunk));
        $headerY = $pdf->GetY();
        $headerH = 24;
        $pdf->SetFillColor(30, 58, 138);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->MultiCell($nameW, $headerH, 'Utilisateur', 1, 'L', true, 0, '', '', true, 0, false, true, $headerH, 'M');
        $pdf->MultiCell($statusW, $headerH, 'Statut', 1, 'C', true, 0, '', '', true, 0, false, true, $headerH, 'M');
        foreach ($chunk as $permission) {
            $title = $permission['label'] . ($permission['universal'] ? "\n● Obligatoire" : '');
            $pdf->MultiCell($permissionW, $headerH, $title, 1, 'C', true, 0, '', '', true, 0, false, true, $headerH, 'M');
        }
        $pdf->SetXY(10, $headerY + $headerH);

        foreach ($users as $index => $user) {
            if ($pdf->GetY() + 9 > $pdf->getPageHeight() - 16) {
                $pdf->sectionTitle = $groupName . ' | Suite';
                $pdf->AddPage();
            }
            $fill = $index % 2 === 0 ? [248, 250, 252] : [255, 255, 255];
            $pdf->SetFillColor(...$fill);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('dejavusans', '', 7.2);
            $name = trim((string) ($user['prenom'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
            $pdf->MultiCell($nameW, 9, $name . "\n" . (string) ($user['mail_pro'] ?? ''), 1, 'L', true, 0, '', '', true, 0, false, true, 9, 'M');
            $active = !empty($user['active']);
            $pdf->SetTextColor($active ? 4 : 190, $active ? 120 : 24, $active ? 87 : 93);
            $pdf->MultiCell($statusW, 9, $active ? 'Actif' : 'Inactif', 1, 'C', true, 0, '', '', true, 0, false, true, 9, 'M');
            foreach ($chunk as $permission) {
                $enabled = !empty($user[$permission['key']]);
                $pdf->SetFillColor(...($permission['universal'] ? [255, 247, 237] : ($enabled ? [236, 253, 245] : $fill)));
                $pdf->SetTextColor(...($enabled ? [4, 120, 87] : [148, 163, 184]));
                $pdf->SetFont('dejavusans', 'B', 9);
                $pdf->MultiCell($permissionW, 9, $enabled ? '✓' : '—', 1, 'C', true, 0, '', '', true, 0, false, true, 9, 'M');
            }
            $pdf->Ln();
        }
    }
}

AuditTrail::log('access_matrix_export', [
    'module' => 'admin',
    'action' => 'export_access_matrix_pdf',
    'entity_type' => 'access_matrix',
    'result' => 'success',
    'details' => ['users' => count($users), 'permissions' => count($catalog)],
], $pdo);

while (ob_get_level() > 0) {
    ob_end_clean();
}
$fileName = 'finea_matrice_acces_' . date('Ymd_Hi') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $fileName . '"');
$pdf->Output($fileName, 'I');
exit;
