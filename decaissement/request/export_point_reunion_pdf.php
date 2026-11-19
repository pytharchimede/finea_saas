<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Dashboard.php';
require_once __DIR__ . '/../model/SuiviTravaux.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

class PDFPointReunion extends TCPDF
{
    public function Header()
    {
        $this->SetAutoPageBreak(true, 18);
    }

    public function Footer()
    {
        $this->SetY(-14);
        $this->SetDrawColor(203, 213, 225);
        $this->Line(12, $this->GetY(), $this->getPageWidth() - 12, $this->GetY());
        $this->Ln(2);
        $this->SetFont('dejavusans', '', 7.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 4, 'Point détaillé de réunion chantier', 0, 0, 'L');
        $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

function reunion_safe_text($value, string $fallback = '—'): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : $fallback;
}

function reunion_format_date(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTime($value))->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function reunion_format_percent($value): string
{
    return number_format((float)$value, 1, ',', ' ') . '%';
}

function reunion_normalize_text($value, string $fallback = '—'): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return $fallback;
    }

    return str_replace(["\r\n", "\r"], "\n", $text);
}

function reunion_status_meta(?string $key): array
{
    $palette = SuiviTravaux::statusOptions();
    return $palette[$key] ?? [
        'label' => 'Non défini',
        'color' => '#64748b',
    ];
}

function reunion_draw_block_title(TCPDF $pdf, float $x, float $y, float $w, string $title): void
{
    $pdf->SetXY($x, $y);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell($w, 6, $title, 0, 1, 'L');
}

function reunion_multiline_value(TCPDF $pdf, float $x, float $y, float $w, string $title, string $value, float $minHeight = 16): float
{
    reunion_draw_block_title($pdf, $x, $y, $w, $title);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(51, 65, 85);
    $height = max($minHeight, $pdf->getStringHeight($w, $value) + 4);
    $pdf->MultiCell($w, $height, $value, 1, 'L', false, 1, $x, $y + 6.5, true, 0, false, true, $height, 'T');
    return $height + 6.5;
}

function reunion_measure_row_height(TCPDF $pdf, array $cells, float $defaultMinHeight = 7.0): float
{
    $rowHeight = $defaultMinHeight;
    foreach ($cells as $cell) {
        $width = (float)($cell['width'] ?? 0);
        if ($width <= 0) {
            continue;
        }

        $text = reunion_normalize_text($cell['text'] ?? '', $cell['fallback'] ?? '—');
        $minHeight = (float)($cell['minHeight'] ?? $defaultMinHeight);
        $height = max($minHeight, $pdf->getStringHeight(max(1.0, $width - 2), $text) + 2);
        if ($height > $rowHeight) {
            $rowHeight = $height;
        }
    }

    return $rowHeight;
}

function reunion_draw_table_row(TCPDF $pdf, array $cells, ?float $rowHeight = null): float
{
    $startX = $pdf->GetX();
    $startY = $pdf->GetY();
    $rowHeight = $rowHeight ?? reunion_measure_row_height($pdf, $cells);
    $cursorX = $startX;

    foreach ($cells as $cell) {
        $width = (float)($cell['width'] ?? 0);
        if ($width <= 0) {
            continue;
        }

        $text = reunion_normalize_text($cell['text'] ?? '', $cell['fallback'] ?? '—');
        $align = (string)($cell['align'] ?? 'L');
        $fill = (bool)($cell['fill'] ?? false);
        $border = $cell['border'] ?? 1;

        $pdf->MultiCell($width, $rowHeight, $text, $border, $align, $fill, 0, $cursorX, $startY, true, 0, false, true, $rowHeight, 'M');
        $cursorX += $width;
    }

    $pdf->SetXY($startX, $startY + $rowHeight);
    return $rowHeight;
}

$chantierId = (int)($_GET['chantier_id'] ?? 0);
$ligneDebourseId = (int)($_GET['ligne_debourse_id'] ?? 0);
$dateSuivi = trim((string)($_GET['date_suivi'] ?? ''));

if ($chantierId <= 0 || $ligneDebourseId <= 0) {
    http_response_code(400);
    echo 'Paramètres chantier/tâche invalides.';
    exit;
}

try {
    $pdo = Database::getConnection();
    $dashboardModel = new Dashboard($pdo);
    $suiviTravauxModel = new SuiviTravaux($pdo);

    $detailsChantier = $dashboardModel->getDetailsBudgetaireChantier($chantierId);
    if (!$detailsChantier) {
        throw new RuntimeException('Chantier introuvable.');
    }

    $lineMetaById = [];
    foreach (($detailsChantier['lignes_devis'] ?? []) as $ligneDevis) {
        $lineMetaById[(int)($ligneDevis['id'] ?? 0)] = [
            'designation' => (string)($ligneDevis['designation'] ?? ''),
            'zone' => (string)($ligneDevis['zone'] ?? ''),
            'groupe' => (string)($ligneDevis['groupe'] ?? ''),
            'quantite' => is_numeric($ligneDevis['quantite'] ?? null) ? (float)$ligneDevis['quantite'] : 0.0,
            'unite' => (string)($ligneDevis['unite_libelle'] ?? ''),
        ];
    }

    $task = null;
    foreach (($detailsChantier['lignes_debourse'] ?? []) as $ligneDebourse) {
        if ((int)($ligneDebourse['id'] ?? 0) !== $ligneDebourseId) {
            continue;
        }
        $lineId = (int)($ligneDebourse['ligne_devis_source_id'] ?? 0);
        $lineMeta = $lineId > 0 && isset($lineMetaById[$lineId]) ? $lineMetaById[$lineId] : [];
        $task = [
            'label' => (string)($ligneDebourse['designation'] ?? 'Tâche'),
            'source' => (string)($ligneDebourse['designation_ligne_devis'] ?? ($lineMeta['designation'] ?? 'Non rattachée')),
            'zone' => trim((string)($ligneDebourse['zone_ligne_devis'] ?? ($lineMeta['zone'] ?? 'Hors zone'))),
            'category' => (string)($ligneDebourse['categorie'] ?? 'Autre'),
            'start' => (string)($ligneDebourse['date_debut'] ?? $ligneDebourse['date_debut_debourse'] ?? ''),
            'end' => (string)($ligneDebourse['date_fin'] ?? $ligneDebourse['date_fin_debourse'] ?? ''),
            'group' => (string)($lineMeta['groupe'] ?? ''),
            'quantite' => (float)($lineMeta['quantite'] ?? 0),
            'unite' => (string)($lineMeta['unite'] ?? ''),
        ];
        break;
    }

    if (!$task) {
        throw new RuntimeException('Ligne de travaux introuvable sur ce chantier.');
    }

    $point = $dateSuivi !== '' ? $suiviTravauxModel->getUpdateForTaskDate($chantierId, $ligneDebourseId, $dateSuivi) : null;
    $pointSource = 'Point sélectionné';
    if (!$point) {
        $point = $suiviTravauxModel->getLatestUpdateForTask($chantierId, $ligneDebourseId);
        $pointSource = 'Dernier point disponible';
    }
    if (!$point) {
        throw new RuntimeException('Aucun point terrain n’a encore été enregistré pour cette tâche.');
    }

    $assignment = $suiviTravauxModel->getAssignmentByTask($chantierId, $ligneDebourseId);
    $history = $suiviTravauxModel->getHistoryByTask($chantierId, $ligneDebourseId, 6);
    $statusMeta = reunion_status_meta($point['statut_reel'] ?? null);

    $pdf = new PDFPointReunion('P', 'mm', 'A4');
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Point réunion - chantier ' . $chantierId . ' - tâche ' . $ligneDebourseId);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    $contentWidth = $pdf->getPageWidth() - 24;

    $pdf->SetFillColor(15, 23, 42);
    $pdf->RoundedRect(12, 12, $contentWidth, 30, 4, '1111', 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->SetXY(16, 16);
    $pdf->Cell(110, 7, 'Point détaillé de réunion', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(226, 232, 240);
    $pdf->SetX(16);
    $pdf->MultiCell(134, 5, reunion_normalize_text($detailsChantier['chantier']['lib_chantier'] ?? 'Chantier'), 0, 'L', false, 1, null, null, true, 0, false, true, 5, 'M');
    $pdf->SetX(16);
    $pdf->MultiCell(134, 5, reunion_normalize_text(reunion_safe_text($task['zone'] ?: 'Hors zone') . ' • ' . reunion_safe_text($task['category'])), 0, 'L', false, 1, null, null, true, 0, false, true, 5, 'M');

    $pdf->SetFillColor(239, 68, 68);
    $pdf->RoundedRect(157, 17, 39, 9, 2, '1111', 'F');
    $pdf->SetXY(157, 19.2);
    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(39, 4, reunion_format_percent($point['progression_reelle'] ?? 0), 0, 0, 'C');

    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect(157, 28, 39, 9, 2, '1111', 'DF');
    $pdf->SetXY(157, 30.2);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(39, 4, reunion_safe_text($statusMeta['label']), 0, 0, 'C');

    $topY = 48;
    $cardWidth = ($contentWidth - 8) / 3;
    $cards = [
        ['label' => 'Date du point', 'value' => reunion_format_date($point['date_suivi'] ?? null)],
        ['label' => 'Renseigné par', 'value' => reunion_safe_text($point['updated_by'] ?? 'Utilisateur')],
        ['label' => 'Source export', 'value' => $pointSource],
    ];
    foreach ($cards as $index => $card) {
        $x = 12 + (($cardWidth + 4) * $index);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($x, $topY, $cardWidth, 16, 3, '1111', 'DF');
        $pdf->SetXY($x + 3, $topY + 2.5);
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($cardWidth - 6, 4, $card['label'], 0, 1, 'L');
        $pdf->SetX($x + 3);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell($cardWidth - 6, 5, reunion_safe_text($card['value']), 0, 0, 'L');
    }

    $leftX = 12;
    $rightX = 110;
    $currentY = 70;

    reunion_draw_block_title($pdf, $leftX, $currentY, 86, 'Tâche concernée');
    $pdf->SetXY($leftX, $currentY + 7);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->MultiCell(86, 6, reunion_safe_text($task['label']), 0, 'L', false, 1);
    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetX($leftX);
    $pdf->MultiCell(86, 5, 'Source: ' . reunion_safe_text($task['source']), 0, 'L', false, 1);
    $pdf->SetX($leftX);
    $pdf->MultiCell(86, 5, 'Période: ' . reunion_format_date($task['start'] ?: null) . ' au ' . reunion_format_date($task['end'] ?: null), 0, 'L', false, 1);
    $pdf->SetX($leftX);
    $quantityLabel = $task['quantite'] > 0 ? number_format((float)$task['quantite'], 2, ',', ' ') . ' ' . reunion_safe_text($task['unite'], '') : 'Non renseignée';
    $pdf->MultiCell(86, 5, 'Quantité: ' . trim($quantityLabel), 0, 'L', false, 1);

    reunion_draw_block_title($pdf, $rightX, $currentY, 86, 'Affectation');
    $pdf->SetXY($rightX, $currentY + 7);
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(51, 65, 85);
    $pdf->MultiCell(86, 5.5, 'Service: ' . reunion_safe_text($assignment['service_label'] ?? 'Non affecté'), 0, 'L', false, 1);
    $pilotNames = [];
    foreach (($assignment['pilots'] ?? []) as $pilot) {
        $pilotNames[] = reunion_safe_text($pilot['name'] ?? '') . (!empty($pilot['matricule']) ? ' (' . $pilot['matricule'] . ')' : '');
    }
    $pdf->SetX($rightX);
    $pdf->MultiCell(86, 5.5, 'Pilotes: ' . (!empty($pilotNames) ? implode(', ', $pilotNames) : 'Aucun pilote affecté'), 0, 'L', false, 1);
    $pdf->SetX($rightX);
    $pdf->MultiCell(86, 5.5, 'Affectation mise à jour par: ' . reunion_safe_text($assignment['updated_by'] ?? '—'), 0, 'L', false, 1);

    $currentY = 103;
    $block1Height = reunion_multiline_value($pdf, 12, $currentY, 184, 'Compte rendu', reunion_safe_text($point['commentaire'] ?? '', 'Aucun commentaire saisi.'), 18);
    $currentY += $block1Height + 3;
    $detailHeight = reunion_multiline_value($pdf, 12, $currentY, 184, 'Detail supplementaire de la ligne', reunion_safe_text($point['detail_ligne_debourse'] ?? '', 'Aucun detail supplementaire renseigne.'), 14);
    $currentY += $detailHeight + 3;
    $block2Height = reunion_multiline_value($pdf, 12, $currentY, 90, 'Blocage ou dépendance', reunion_safe_text($point['blocage_detail'] ?? '', 'Aucun blocage signalé.'), 16);
    $block3Height = reunion_multiline_value($pdf, 106, $currentY, 90, 'Prochaine action', reunion_safe_text($point['prochaine_action'] ?? '', 'Aucune prochaine action renseignée.'), 16);
    $currentY += max($block2Height, $block3Height) + 3;

    reunion_draw_block_title($pdf, 12, $currentY, 184, 'Historique récent de la tâche');
    $pdf->SetXY(12, $currentY + 7);
    $pdf->SetFont('dejavusans', 'B', 8);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(28, 7, 'Date', 1, 0, 'C', true);
    $pdf->Cell(26, 7, 'Progression', 1, 0, 'C', true);
    $pdf->Cell(38, 7, 'Statut', 1, 0, 'C', true);
    $pdf->Cell(40, 7, 'Auteur', 1, 0, 'C', true);
    $pdf->Cell(52, 7, 'Synthèse', 1, 1, 'C', true);

    $pdf->SetFont('dejavusans', '', 7.8);
    $pdf->SetTextColor(51, 65, 85);
    foreach ($history as $historyRow) {
        $statusRow = reunion_status_meta($historyRow['statut_reel'] ?? null);
        $summaryParts = [];
        if (trim((string)($historyRow['commentaire'] ?? '')) !== '') {
            $summaryParts[] = 'Commentaire: ' . trim((string)$historyRow['commentaire']);
        }
        if (trim((string)($historyRow['detail_ligne_debourse'] ?? '')) !== '') {
            $summaryParts[] = 'Détail: ' . trim((string)$historyRow['detail_ligne_debourse']);
        }
        if (trim((string)($historyRow['blocage_detail'] ?? '')) !== '') {
            $summaryParts[] = 'Blocage: ' . trim((string)$historyRow['blocage_detail']);
        }
        if (trim((string)($historyRow['prochaine_action'] ?? '')) !== '') {
            $summaryParts[] = 'Action: ' . trim((string)$historyRow['prochaine_action']);
        }
        $summary = !empty($summaryParts) ? implode("\n", $summaryParts) : 'Point saisi sans commentaire';

        $cells = [
            ['width' => 28, 'text' => reunion_format_date($historyRow['date_suivi'] ?? null), 'align' => 'C', 'fallback' => '—'],
            ['width' => 26, 'text' => reunion_format_percent($historyRow['progression_reelle'] ?? 0), 'align' => 'C', 'fallback' => '0,0%'],
            ['width' => 38, 'text' => reunion_safe_text($statusRow['label']), 'align' => 'C', 'fallback' => '—'],
            ['width' => 40, 'text' => (string)($historyRow['updated_by'] ?? 'Utilisateur'), 'align' => 'L', 'fallback' => 'Utilisateur'],
            ['width' => 52, 'text' => $summary, 'align' => 'L', 'fallback' => 'Point saisi sans commentaire'],
        ];
        $rowHeight = reunion_measure_row_height($pdf, $cells, 7.0);

        if (($pdf->GetY() + $rowHeight) > 265) {
            $pdf->AddPage();
            reunion_draw_block_title($pdf, 12, 12, 184, 'Historique récent de la tâche');
            $pdf->SetXY(12, 19);
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->SetFillColor(241, 245, 249);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell(28, 7, 'Date', 1, 0, 'C', true);
            $pdf->Cell(26, 7, 'Progression', 1, 0, 'C', true);
            $pdf->Cell(38, 7, 'Statut', 1, 0, 'C', true);
            $pdf->Cell(40, 7, 'Auteur', 1, 0, 'C', true);
            $pdf->Cell(52, 7, 'Synthèse', 1, 1, 'C', true);
            $pdf->SetFont('dejavusans', '', 7.8);
            $pdf->SetTextColor(51, 65, 85);
            $rowHeight = reunion_measure_row_height($pdf, $cells, 7.0);
        }

        reunion_draw_table_row($pdf, $cells, $rowHeight);
    }

    if (empty($history)) {
        $pdf->Cell(184, 8, 'Aucun historique disponible pour cette tâche.', 1, 1, 'C');
    }

    $fileName = 'point_reunion_chantier_' . $chantierId . '_tache_' . $ligneDebourseId . '.pdf';
    \AuditTrail::logFileEvent('export', [
        'module' => 'planning',
        'action' => 'reunion_point_export',
        'entity_type' => 'ligne_debourse',
        'entity_id' => (string)$ligneDebourseId,
        'entity_label' => (string)($task['label'] ?? 'Point réunion'),
        'format' => 'pdf',
        'details' => [
            'chantier_id' => $chantierId,
            'date_suivi' => $dateSuivi,
            'history_count' => is_countable($history ?? null) ? count($history) : null,
        ],
    ], $pdo);
    $pdf->Output($fileName, 'I');
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur export PDF: ' . $e->getMessage();
}
