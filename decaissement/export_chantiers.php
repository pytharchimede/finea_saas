<?php

require_once __DIR__ . '/../auth_check.php';

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/Dashboard.php';

$format = strtolower(trim((string)($_GET['format'] ?? 'pdf')));
$chantierId = isset($_GET['chantier_id']) ? (int)$_GET['chantier_id'] : 0;

$pdo = Database::getConnection();
$dashboardModel = new Dashboard($pdo);

$donneesDashboard = $dashboardModel->getDonneesDashboard();
$chantiers = $donneesDashboard['chantiers_budgets'] ?? [];

if ($chantierId > 0) {
    $chantiers = array_values(array_filter($chantiers, function ($c) use ($chantierId) {
        return (int)($c['id_chantier'] ?? 0) === $chantierId;
    }));
}

// Réutiliser le calcul partagé du modèle pour les exports
foreach ($chantiers as &$c) {
    $budgetInterne = (float)($c['budget_interne_calc'] ?? $c['budget_total'] ?? 0);
    $c['budget_interne_calc'] = $budgetInterne;

    if ($budgetInterne > 0 && isset($c['total_depenses'])) {
        $c['pourcentage_consomme'] = ((float)$c['total_depenses'] / max($budgetInterne, 1)) * 100;
    }
}
unset($c);

if ($format === 'excel') {
    $fileBase = ($chantierId > 0 ? 'chantier_' . $chantierId : 'chantiers');
    $filename = $fileBase . '_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "<html><head><meta charset=\"utf-8\"></head><body>";
    echo "<table border=\"1\" cellspacing=\"0\" cellpadding=\"4\">";
    echo "<tr>";
    echo "<th>Chantier</th>";
    echo "<th>Code</th>";
    echo "<th>Client</th>";
    echo "<th>Budget interne (F)</th>";
    echo "<th>Total d\u00e9pens\u00e9 (F)</th>";
    echo "<th>Reste (F)</th>";
    echo "<th>Progression (%)</th>";
    echo "<th>Fiches</th>";
    echo "</tr>";

    foreach ($chantiers as $c) {
        $budgetInterne = (float)($c['budget_interne_calc'] ?? $c['budget_total'] ?? 0);
        $totalDepenses = (float)($c['total_depenses'] ?? 0);
        $reste = $budgetInterne - $totalDepenses;
        $pct = (float)($c['pourcentage_consomme'] ?? 0);

        echo "<tr>";
        echo "<td>" . htmlspecialchars((string)($c['lib_chantier'] ?? ''), ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars((string)($c['num_chantier'] ?? ''), ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td>" . htmlspecialchars((string)($c['nom_client'] ?? ''), ENT_QUOTES, 'UTF-8') . "</td>";
        echo "<td align=\"right\">" . number_format($budgetInterne, 0, ',', ' ') . "</td>";
        echo "<td align=\"right\">" . number_format($totalDepenses, 0, ',', ' ') . "</td>";
        echo "<td align=\"right\">" . number_format($reste, 0, ',', ' ') . "</td>";
        echo "<td align=\"right\">" . number_format($pct, 1, ',', ' ') . "</td>";
        echo "<td align=\"center\">" . (int)($c['nombre_fiches'] ?? 0) . "</td>";
        echo "</tr>";
    }

    echo "</table>";
    echo "</body></html>";
    exit;
}

if ($format === 'pdf') {
    require_once __DIR__ . '/../fpdf186/fpdf.php';

    class PDF_Chantiers extends FPDF
    {
        public function Header()
        {
            $this->SetFillColor(79, 70, 229); // indigo
            $this->Rect(0, 0, 297, 18, 'F');
            $this->SetFont('Arial', 'B', 14);
            $this->SetTextColor(255, 255, 255);
            $this->Cell(0, 10, mb_convert_encoding('Export Chantiers', 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
            $this->SetFont('Arial', '', 9);
            $this->Cell(0, 6, mb_convert_encoding('Date : ' . date('d/m/Y H:i'), 'ISO-8859-1', 'UTF-8'), 0, 1, 'C');
            $this->Ln(3);
        }
    }

    $pdf = new PDF_Chantiers('L', 'mm', 'A4');
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetFillColor(239, 246, 255); // indigo-50
    $pdf->SetTextColor(17, 24, 39);

    // Largeurs colonnes (A4 landscape ~ 297mm)
    $w = [70, 24, 42, 30, 30, 28, 22, 16];

    $headers = [
        'Chantier',
        'Code',
        'Client',
        'Budget (F)',
        'D\u00e9pens\u00e9 (F)',
        'Reste (F)',
        'Progression',
        'Fiches'
    ];

    foreach ($headers as $i => $h) {
        $pdf->Cell($w[$i], 7, mb_convert_encoding($h, 'ISO-8859-1', 'UTF-8'), 1, 0, 'C', true);
    }
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetFillColor(255, 255, 255);

    $totalBudget = 0.0;
    $totalDep = 0.0;

    foreach ($chantiers as $c) {
        $budgetInterne = (float)($c['budget_interne_calc'] ?? $c['budget_total'] ?? 0);
        $totalDepenses = (float)($c['total_depenses'] ?? 0);
        $reste = $budgetInterne - $totalDepenses;
        $pct = (float)($c['pourcentage_consomme'] ?? 0);
        $nbFiches = (int)($c['nombre_fiches'] ?? 0);

        $totalBudget += $budgetInterne;
        $totalDep += $totalDepenses;

        $pdf->Cell($w[0], 7, mb_convert_encoding((string)($c['lib_chantier'] ?? ''), 'ISO-8859-1', 'UTF-8'), 1, 0, 'L');
        $pdf->Cell($w[1], 7, mb_convert_encoding((string)($c['num_chantier'] ?? ''), 'ISO-8859-1', 'UTF-8'), 1, 0, 'L');
        $pdf->Cell($w[2], 7, mb_convert_encoding((string)($c['nom_client'] ?? ''), 'ISO-8859-1', 'UTF-8'), 1, 0, 'L');
        $pdf->Cell($w[3], 7, number_format($budgetInterne, 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell($w[4], 7, number_format($totalDepenses, 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell($w[5], 7, number_format($reste, 0, ',', ' '), 1, 0, 'R');
        $pdf->Cell($w[6], 7, number_format($pct, 1, ',', ' ') . '%', 1, 0, 'R');
        $pdf->Cell($w[7], 7, (string)$nbFiches, 1, 1, 'C');
    }

    // Totaux
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetFillColor(243, 244, 246); // gray-100

    $pdf->Cell($w[0] + $w[1] + $w[2], 8, mb_convert_encoding('TOTAL', 'ISO-8859-1', 'UTF-8'), 1, 0, 'R', true);
    $pdf->Cell($w[3], 8, number_format($totalBudget, 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($w[4], 8, number_format($totalDep, 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($w[5], 8, number_format($totalBudget - $totalDep, 0, ',', ' '), 1, 0, 'R', true);
    $pdf->Cell($w[6], 8, '', 1, 0, 'C', true);
    $pdf->Cell($w[7], 8, '', 1, 1, 'C', true);

    $fileBase = ($chantierId > 0 ? 'chantier_' . $chantierId : 'chantiers');
    $filename = $fileBase . '_' . date('Ymd_His') . '.pdf';

    $pdf->Output('D', $filename);
    exit;
}

http_response_code(400);
header('Content-Type: text/plain; charset=utf-8');
echo "Format invalide. Utiliser ?format=pdf ou ?format=excel";
