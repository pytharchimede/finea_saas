<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/ProjectPlanningImport.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

class PDFProjectReunionSheet extends TCPDF
{
    public function Header()
    {
        $this->SetAutoPageBreak(true, 10);
    }

    public function Footer()
    {
        $this->SetY(-9);
        $this->SetDrawColor(203, 213, 225);
        $this->Line(8, $this->GetY(), $this->getPageWidth() - 8, $this->GetY());
        $this->Ln(1.5);
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 3.5, 'Fiche imprimable de pointage reunion planning Project', 0, 0, 'L');
        $this->Cell(0, 3.5, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

function project_pdf_safe_text($value, string $fallback = '—'): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : $fallback;
}

function project_pdf_parse_date(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    $rawValue = trim($value);
    $date = DateTimeImmutable::createFromFormat('Y-m-d', $rawValue) ?: null;

    if (!$date instanceof DateTimeImmutable) {
        try {
            $date = new DateTimeImmutable($rawValue);
        } catch (Throwable $e) {
            return null;
        }
    }

    $year = (int)$date->format('Y');
    if ($year >= 0 && $year < 1000) {
        $correctedDate = $date->setDate(
            2000 + $year,
            (int)$date->format('m'),
            (int)$date->format('d')
        );
        if ($correctedDate instanceof DateTimeImmutable) {
            $date = $correctedDate;
        }
    } elseif ($year >= 1900 && $year < 2000) {
        $shiftedDate = $date->modify('+100 years');
        if ($shiftedDate instanceof DateTimeImmutable) {
            $date = $shiftedDate;
        }
    }

    return $date;
}

function project_pdf_format_date(?string $value): string
{
    if (!$value) {
        return '—';
    }

    $date = project_pdf_parse_date($value);
    return $date instanceof DateTimeImmutable ? $date->format('d/m/Y') : (string)$value;
}

function project_pdf_format_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }

    $date = project_pdf_parse_date($value);
    return $date instanceof DateTimeImmutable ? $date->format('d/m/Y H:i') : (string)$value;
}

function project_pdf_format_percent($value): string
{
    return number_format((float)$value, 1, ',', ' ') . '%';
}

function project_pdf_status_meta(?string $statusKey): array
{
    $palette = [
        'non_demarre' => ['label' => 'Non demarre', 'fill' => [241, 245, 249], 'text' => [51, 65, 85]],
        'en_cours' => ['label' => 'En cours', 'fill' => [224, 242, 254], 'text' => [3, 105, 161]],
        'bloque' => ['label' => 'Bloque', 'fill' => [255, 228, 230], 'text' => [190, 24, 93]],
        'termine' => ['label' => 'Termine', 'fill' => [220, 252, 231], 'text' => [22, 101, 52]],
    ];

    return $palette[$statusKey ?? ''] ?? ['label' => 'Non defini', 'fill' => [241, 245, 249], 'text' => [71, 85, 105]];
}

function project_pdf_normalize_date(?string $value): ?DateTimeImmutable
{
    return project_pdf_parse_date($value);
}

function project_pdf_normalize_text(string $value): string
{
    $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $value);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function project_pdf_measure_row_height(TCPDF $pdf, array $cells, float $defaultMinHeight = 9.0): float
{
    $rowHeight = $defaultMinHeight;
    foreach ($cells as $cell) {
        $width = (float)($cell['width'] ?? 0);
        if ($width <= 0) {
            continue;
        }

        $text = (string)($cell['text'] ?? '');
        $minHeight = (float)($cell['minHeight'] ?? $defaultMinHeight);
        $height = max($minHeight, $pdf->getStringHeight(max(1.0, $width - 2), $text) + 2);
        if ($height > $rowHeight) {
            $rowHeight = $height;
        }
    }

    return $rowHeight;
}

function project_pdf_draw_table_row(TCPDF $pdf, array $cells, ?float $rowHeight = null): float
{
    $startX = $pdf->GetX();
    $startY = $pdf->GetY();
    $rowHeight = $rowHeight ?? project_pdf_measure_row_height($pdf, $cells);
    $cursorX = $startX;

    foreach ($cells as $cell) {
        $width = (float)($cell['width'] ?? 0);
        if ($width <= 0) {
            continue;
        }

        $text = (string)($cell['text'] ?? '');
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
$meetingDate = trim((string)($_GET['meeting_date'] ?? ''));

if ($chantierId <= 0) {
    http_response_code(400);
    echo 'chantier_id invalide';
    exit;
}

$meetingDateLabel = '................................';
if ($meetingDate !== '') {
    $meetingDateObject = project_pdf_normalize_date($meetingDate);
    if ($meetingDateObject instanceof DateTimeImmutable) {
        $meetingDate = $meetingDateObject->format('Y-m-d');
        $meetingDateLabel = $meetingDateObject->format('d/m/Y');
    }
}

try {
    $pdo = Database::getConnection();
    $projectPlanningModel = new ProjectPlanningImport($pdo);
    $projectImport = $projectPlanningModel->getActiveImportByChantier($chantierId);

    if (!$projectImport || empty($projectImport['tasks'])) {
        throw new RuntimeException('Aucun planning Project actif pour ce chantier.');
    }

    $chantierStmt = $pdo->prepare(
        "SELECT
            id,
            code,
            titre,
            COALESCE(NULLIF(TRIM(titre), ''), NULLIF(TRIM(code), ''), CONCAT('Chantier #', id)) AS libelle
         FROM chantier_banamur
         WHERE id = :id
         LIMIT 1"
    );
    $chantierStmt->execute(['id' => $chantierId]);
    $chantier = $chantierStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$chantier) {
        throw new RuntimeException('Chantier introuvable.');
    }

    if ($meetingDate !== '') {
        $meetingUpdates = $projectPlanningModel->getRealtimeUpdatesByMeetingDate((int)$projectImport['id'], $meetingDate);
        $meetingDateObject = project_pdf_normalize_date($meetingDate);
        $meetingDateLabel = $meetingDateObject instanceof DateTimeImmutable ? $meetingDateObject->format('d/m/Y') : project_pdf_format_date($meetingDate);

        $totalProgress = 0.0;
        $authors = [];
        $statusStats = [
            'non_demarre' => 0,
            'en_cours' => 0,
            'bloque' => 0,
            'termine' => 0,
        ];

        foreach ($meetingUpdates as $meetingUpdate) {
            $totalProgress += (float)($meetingUpdate['progress_percent'] ?? 0);
            $authorName = trim((string)($meetingUpdate['updated_by'] ?? ''));
            if ($authorName !== '') {
                $authors[$authorName] = true;
            }

            $statusKey = (string)($meetingUpdate['status_code'] ?? '');
            if (array_key_exists($statusKey, $statusStats)) {
                $statusStats[$statusKey]++;
            }
        }

        $averageProgress = !empty($meetingUpdates) ? round($totalProgress / count($meetingUpdates), 1) : 0.0;
        $latestEntry = !empty($meetingUpdates) ? $meetingUpdates[0] : null;

        $pdf = new PDFProjectReunionSheet('P', 'mm', 'A4');
        $pdf->SetCreator('Banamur');
        $pdf->SetAuthor('Banamur');
        $pdf->SetTitle('Point de reunion planning - chantier ' . $chantierId . ' - ' . $meetingDate);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();

        $contentWidth = $pdf->getPageWidth() - 24;

        $pdf->SetFillColor(15, 23, 42);
        $pdf->RoundedRect(12, 12, $contentWidth, 31, 4, '1111', 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetXY(16, 16);
        $pdf->Cell(124, 7, 'Point de reunion planning', 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(226, 232, 240);
        $pdf->SetX(16);
        $pdf->MultiCell(124, 5, project_pdf_safe_text((string)($chantier['libelle'] ?? 'Chantier')), 0, 'L', false, 1, null, null, true, 0, false, true, 5, 'M');
        $pdf->SetX(16);
        $pdf->MultiCell(124, 5, 'Source: ' . project_pdf_safe_text((string)($projectImport['source_label'] ?? 'Planning Project')), 0, 'L', false, 1, null, null, true, 0, false, true, 5, 'M');

        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect(150, 17, 47, 9, 2, '1111', 'F');
        $pdf->SetXY(150, 19.4);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->Cell(47, 4, $meetingDateLabel, 0, 0, 'C');

        $pdf->SetDrawColor(255, 255, 255);
        $pdf->SetTextColor(226, 232, 240);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY(150, 29.5);
        $pdf->Cell(47, 4, 'Seance filtree par date', 0, 0, 'C');

        $topY = 50;
        $cardWidth = ($contentWidth - 8) / 3;
        $cards = [
            ['label' => 'Points saisis', 'value' => number_format(count($meetingUpdates), 0, ',', ' ')],
            ['label' => 'Progression moyenne', 'value' => project_pdf_format_percent($averageProgress)],
            ['label' => 'Derniere saisie', 'value' => $latestEntry ? project_pdf_format_datetime((string)($latestEntry['created_at'] ?? '')) : 'Aucune'],
        ];

        foreach ($cards as $index => $card) {
            $x = 12 + (($cardWidth + 4) * $index);
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->RoundedRect($x, $topY, $cardWidth, 16, 3, '1111', 'DF');
            $pdf->SetXY($x + 3, $topY + 2.4);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('dejavusans', '', 7.4);
            $pdf->Cell($cardWidth - 6, 3.8, $card['label'], 0, 1, 'L');
            $pdf->SetX($x + 3);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->SetFont('dejavusans', 'B', 9.2);
            $pdf->MultiCell($cardWidth - 6, 5, project_pdf_safe_text($card['value']), 0, 'L', false, 1, null, null, true, 0, false, true, 5, 'M');
        }

        $statusSummary = sprintf(
            'Participants: %s   |   Non demarre: %s   |   En cours: %s   |   Bloques: %s   |   Termines: %s',
            number_format(count($authors), 0, ',', ' '),
            number_format($statusStats['non_demarre'], 0, ',', ' '),
            number_format($statusStats['en_cours'], 0, ',', ' '),
            number_format($statusStats['bloque'], 0, ',', ' '),
            number_format($statusStats['termine'], 0, ',', ' ')
        );

        $pdf->SetXY(12, 71);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->MultiCell($contentWidth, 4.8, "Compte rendu de seance genere a partir des points reels saisis pour la reunion du {$meetingDateLabel}. Le document n'affiche que les points enregistres pour cette date avec tous les details disponibles.", 0, 'L', false, 1, null, null, true, 0, false, true, 4.8, 'M');
        $pdf->SetTextColor(51, 65, 85);
        $pdf->MultiCell($contentWidth, 4.8, $statusSummary, 0, 'L', false, 1, null, null, true, 0, false, true, 4.8, 'M');

        $currentY = 84;
        if (empty($meetingUpdates)) {
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->RoundedRect(12, $currentY, $contentWidth, 24, 3, '1111', 'DF');
            $pdf->SetXY(16, $currentY + 6);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->Cell($contentWidth - 8, 6, 'Aucun point saisi pour cette seance', 0, 1, 'L');
            $pdf->SetX(16);
            $pdf->SetFont('dejavusans', '', 8.6);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->MultiCell($contentWidth - 8, 5, 'Aucune mise a jour temps reel n a ete enregistree a la date selectionnee. Choisis une autre date de reunion ou saisis des points avant de relancer cet export.', 0, 'L', false, 1, null, null, true, 0, false, true, 5, 'M');
        } else {
            foreach ($meetingUpdates as $index => $meetingUpdate) {
                $taskName = project_pdf_normalize_text((string)($meetingUpdate['task_name'] ?? 'Point du planning'));
                $groupLabel = project_pdf_safe_text((string)($meetingUpdate['group_label'] ?? ''), 'Planning Project');
                $plannedStart = project_pdf_format_date((string)($meetingUpdate['start_date'] ?? ''));
                $plannedEnd = project_pdf_format_date((string)($meetingUpdate['end_date'] ?? ''));
                $durationLabel = project_pdf_safe_text((string)($meetingUpdate['duration_label'] ?? ''), '—');
                $predecessorsLabel = project_pdf_safe_text((string)($meetingUpdate['predecessors_label'] ?? ''), '—');
                $authorLabel = project_pdf_safe_text((string)($meetingUpdate['updated_by'] ?? ''), 'SYSTEM');
                $createdAtLabel = project_pdf_format_datetime((string)($meetingUpdate['created_at'] ?? ''));
                $commentText = project_pdf_safe_text((string)($meetingUpdate['comment_text'] ?? ''), 'Aucun commentaire saisi.');
                $statusMeta = project_pdf_status_meta((string)($meetingUpdate['status_code'] ?? ''));

                $titleText = ($index + 1) . '. ' . $taskName;
                $titleHeight = max(6.0, $pdf->getStringHeight(118, $titleText));
                $metaText = "Planning: {$groupLabel}\nPeriode prevue: {$plannedStart} au {$plannedEnd}\nDuree: {$durationLabel}   |   Pred.: {$predecessorsLabel}\nSaisi le {$createdAtLabel} par {$authorLabel}";
                $metaHeight = max(16.0, $pdf->getStringHeight(118, $metaText));
                $commentHeight = max(14.0, $pdf->getStringHeight($contentWidth - 10, $commentText) + 4);
                $cardHeight = 14 + $titleHeight + $metaHeight + $commentHeight;

                if ($currentY + $cardHeight > 278) {
                    $pdf->AddPage();
                    $currentY = 14;
                }

                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetDrawColor(226, 232, 240);
                $pdf->RoundedRect(12, $currentY, $contentWidth, $cardHeight, 3, '1111', 'DF');

                $pdf->SetXY(16, $currentY + 4);
                $pdf->SetFont('dejavusans', 'B', 10.5);
                $pdf->SetTextColor(15, 23, 42);
                $pdf->MultiCell(118, 6, $titleText, 0, 'L', false, 1, null, null, true, 0, false, true, 6, 'M');

                $pdf->SetFillColor(248, 113, 113);
                $pdf->RoundedRect(155, $currentY + 4, 40, 9, 2, '1111', 'F');
                $pdf->SetXY(155, $currentY + 6.3);
                $pdf->SetFont('dejavusans', 'B', 8.5);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->Cell(40, 4, project_pdf_format_percent($meetingUpdate['progress_percent'] ?? 0), 0, 0, 'C');

                $pdf->SetFillColor($statusMeta['fill'][0], $statusMeta['fill'][1], $statusMeta['fill'][2]);
                $pdf->RoundedRect(155, $currentY + 15, 40, 9, 2, '1111', 'F');
                $pdf->SetXY(155, $currentY + 17.3);
                $pdf->SetTextColor($statusMeta['text'][0], $statusMeta['text'][1], $statusMeta['text'][2]);
                $pdf->Cell(40, 4, $statusMeta['label'], 0, 0, 'C');

                $pdf->SetXY(16, $currentY + 11 + $titleHeight);
                $pdf->SetFont('dejavusans', '', 8.2);
                $pdf->SetTextColor(71, 85, 105);
                $pdf->MultiCell(118, 4.8, $metaText, 0, 'L', false, 1, null, null, true, 0, false, true, 4.8, 'M');

                $commentY = $currentY + 11 + $titleHeight + $metaHeight;
                $pdf->SetFillColor(248, 250, 252);
                $pdf->RoundedRect(16, $commentY, $contentWidth - 8, $commentHeight, 2, '1111', 'F');
                $pdf->SetXY(19, $commentY + 2.5);
                $pdf->SetFont('dejavusans', 'B', 8.2);
                $pdf->SetTextColor(30, 41, 59);
                $pdf->Cell($contentWidth - 14, 4, 'Decision / commentaire de reunion', 0, 1, 'L');
                $pdf->SetX(19);
                $pdf->SetFont('dejavusans', '', 8.2);
                $pdf->SetTextColor(51, 65, 85);
                $pdf->MultiCell($contentWidth - 14, $commentHeight - 5, $commentText, 0, 'L', false, 1, null, null, true, 0, false, true, $commentHeight - 5, 'T');

                $currentY += $cardHeight + 5;
            }
        }

        $fileName = 'point_reunion_planning_chantier_' . $chantierId . '_' . str_replace('-', '', $meetingDate) . '.pdf';
        \AuditTrail::logFileEvent('export', [
            'module' => 'planning',
            'action' => 'planning_meeting_export',
            'entity_type' => 'planning_meeting',
            'entity_id' => (string)$chantierId,
            'entity_label' => 'Point réunion planning chantier',
            'format' => 'pdf',
            'details' => [
                'meeting_date' => $meetingDate,
                'row_count' => is_countable($meetingUpdates ?? null) ? count($meetingUpdates) : null,
                'import_id' => $projectImport['id'] ?? null,
            ],
        ], $pdo);
        $pdf->Output($fileName, 'I');
        exit;
    }

    $today = new DateTimeImmutable('today');
    $planningStart = null;
    $planningEnd = null;
    $rows = [];

    foreach ($projectImport['tasks'] as $index => $taskRow) {
        $startDate = project_pdf_normalize_date((string)($taskRow['start_date'] ?? ''));
        $endDate = project_pdf_normalize_date((string)($taskRow['end_date'] ?? ''));
        if ($startDate && (!$planningStart || $startDate < $planningStart)) {
            $planningStart = $startDate;
        }
        if ($endDate && (!$planningEnd || $endDate > $planningEnd)) {
            $planningEnd = $endDate;
        }

        $plannedProgress = 0.0;
        if ($startDate && $endDate) {
            $durationDays = max(1, (int)$startDate->diff($endDate)->days + 1);
            if ($today < $startDate) {
                $plannedProgress = 0.0;
            } elseif ($today > $endDate) {
                $plannedProgress = 100.0;
            } else {
                $elapsedDays = max(1, (int)$startDate->diff($today)->days + 1);
                $plannedProgress = min(100.0, round(($elapsedDays / $durationDays) * 100, 1));
            }
        }

        $indent = str_repeat('   ', max(0, ((int)($taskRow['outline_level'] ?? 1)) - 1));
        $typeLabel = !empty($taskRow['is_summary']) ? 'Synth.' : 'Point';
        $rows[] = [
            'numero' => $index + 1,
            'type' => $typeLabel,
            'point' => $indent . project_pdf_normalize_text((string)($taskRow['task_name'] ?? 'Point du planning')),
            'debut' => $startDate ? $startDate->format('d/m/Y') : '—',
            'fin' => $endDate ? $endDate->format('d/m/Y') : '—',
            'duree' => project_pdf_safe_text((string)($taskRow['duration_label'] ?? ''), '—'),
            'predecesseurs' => project_pdf_safe_text((string)($taskRow['predecessors_label'] ?? ''), '—'),
            'planifie' => number_format($plannedProgress, 1, ',', ' ') . '%',
            'progression_reelle' => '',
            'statut_reel' => '',
            'commentaire' => '',
        ];
    }

    $pdf = new PDFProjectReunionSheet('L', 'mm', 'A4');
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Fiche reunion planning - chantier ' . $chantierId);
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage();

    $contentWidth = $pdf->getPageWidth() - 16;

    $pdf->SetFillColor(15, 23, 42);
    $pdf->RoundedRect(8, 8, $contentWidth, 23, 3, '1111', 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 15);
    $pdf->SetXY(12, 11);
    $pdf->Cell(160, 6, 'Fiche imprimable de pointage reunion planning', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->SetTextColor(226, 232, 240);
    $pdf->SetX(12);
    $pdf->Cell(160, 4.5, project_pdf_safe_text((string)($chantier['libelle'] ?? 'Chantier')), 0, 1, 'L');
    $pdf->SetX(12);
    $pdf->Cell(160, 4.5, 'Source: ' . project_pdf_safe_text((string)($projectImport['source_label'] ?? 'Planning Project')), 0, 1, 'L');

    $pdf->SetFillColor(255, 255, 255);
    $pdf->RoundedRect(215, 11, 66, 15, 2, '1111', 'F');
    $pdf->SetFont('dejavusans', 'B', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetXY(218, 13);
    $pdf->Cell(60, 4, 'Date de reunion', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 11);
    $pdf->SetXY(218, 17.2);
    $pdf->Cell(60, 5, $meetingDateLabel, 0, 1, 'L');

    $infoY = 35;
    $cardWidth = ($contentWidth - 8) / 3;
    $cards = [
        ['label' => 'Edition', 'value' => $today->format('d/m/Y')],
        ['label' => 'Horizon planning', 'value' => ($planningStart ? $planningStart->format('d/m/Y') : '—') . ' au ' . ($planningEnd ? $planningEnd->format('d/m/Y') : '—')],
        ['label' => 'Points listes', 'value' => number_format(count($rows), 0, ',', ' ')],
    ];

    foreach ($cards as $index => $card) {
        $x = 8 + (($cardWidth + 4) * $index);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($x, $infoY, $cardWidth, 14, 2.5, '1111', 'DF');
        $pdf->SetXY($x + 3, $infoY + 2.2);
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($cardWidth - 6, 3.5, $card['label'], 0, 1, 'L');
        $pdf->SetX($x + 3);
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->MultiCell($cardWidth - 6, 4.5, project_pdf_safe_text($card['value']), 0, 'L', false, 1, null, null, true, 0, false, true, 4.5, 'M');
    }

    $pdf->SetFont('dejavusans', '', 7.1);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetXY(8, 52);
    $pdf->MultiCell($contentWidth, 4.5, "Document imprime pour la reunion de chantier. L'operateur renseigne a la main les colonnes Date de reunion, Progression reelle, Statut reel et Decision / commentaire, puis reporte ces informations dans le logiciel.", 0, 'L', false, 1, null, null, true, 0, false, true, 4.5, 'M');

    $startTableY = 61;
    $pdf->SetY($startTableY);
    $pdf->SetFont('dejavusans', 'B', 7.1);
    $pdf->SetFillColor(226, 232, 240);
    $pdf->SetTextColor(15, 23, 42);

    $columns = [
        ['key' => 'numero', 'label' => 'N°', 'width' => 10, 'align' => 'C'],
        ['key' => 'type', 'label' => 'Type', 'width' => 16, 'align' => 'C'],
        ['key' => 'point', 'label' => 'Point du planning', 'width' => 75, 'align' => 'L'],
        ['key' => 'debut', 'label' => 'Debut', 'width' => 16, 'align' => 'C'],
        ['key' => 'fin', 'label' => 'Fin', 'width' => 16, 'align' => 'C'],
        ['key' => 'duree', 'label' => 'Duree', 'width' => 18, 'align' => 'C'],
        ['key' => 'predecesseurs', 'label' => 'Pred.', 'width' => 18, 'align' => 'C'],
        ['key' => 'planifie', 'label' => 'Avanc. planifie', 'width' => 18, 'align' => 'C'],
        ['key' => 'progression_reelle', 'label' => 'Progression reelle', 'width' => 22, 'align' => 'C'],
        ['key' => 'statut_reel', 'label' => 'Statut reel', 'width' => 24, 'align' => 'C'],
        ['key' => 'commentaire', 'label' => 'Decision / commentaire reunion', 'width' => 44, 'align' => 'L'],
    ];

    $headerCells = [];
    foreach ($columns as $column) {
        $headerCells[] = [
            'width' => $column['width'],
            'text' => $column['label'],
            'align' => $column['align'],
            'fill' => true,
            'minHeight' => 10,
        ];
    }
    project_pdf_draw_table_row($pdf, $headerCells, 10);

    $pdf->SetFont('dejavusans', '', 6.9);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->SetFillColor(255, 255, 255);

    foreach ($rows as $rowIndex => $row) {
        $cellDefinitions = [];
        foreach ($columns as $column) {
            $text = (string)($row[$column['key']] ?? '');
            $minHeight = $column['key'] === 'commentaire' ? 12 : 9;
            if ($column['key'] === 'progression_reelle' || $column['key'] === 'statut_reel' || $column['key'] === 'commentaire') {
                $text = ' ';
            }
            $cellDefinitions[] = [
                'width' => $column['width'],
                'text' => $text,
                'align' => $column['align'],
                'fill' => false,
                'minHeight' => $minHeight,
            ];
        }

        $rowHeight = project_pdf_measure_row_height($pdf, $cellDefinitions, 10.5);
        if ($pdf->GetY() + $rowHeight > ($pdf->getPageHeight() - 12)) {
            $pdf->AddPage();
            $pdf->SetFont('dejavusans', 'B', 7.1);
            $pdf->SetFillColor(226, 232, 240);
            $pdf->SetTextColor(15, 23, 42);
            project_pdf_draw_table_row($pdf, $headerCells, 10);
            $pdf->SetFont('dejavusans', '', 6.9);
            $pdf->SetTextColor(30, 41, 59);
        }

        project_pdf_draw_table_row($pdf, $cellDefinitions, $rowHeight);
    }

    $pdf->Ln(3);
    $pdf->SetFont('dejavusans', '', 7);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->MultiCell($contentWidth, 4, 'Conseil d usage: imprimer en paysage sur A4. Les colonnes vides sont volontairement reservees a la saisie manuelle de l operateur avant report dans le logiciel.', 0, 'L', false, 1, null, null, true, 0, false, true, 4, 'M');

    $fileName = 'fiche_reunion_planning_chantier_' . $chantierId . ($meetingDate !== '' ? '_' . str_replace('-', '', $meetingDate) : '') . '.pdf';
    \AuditTrail::logFileEvent('export', [
        'module' => 'planning',
        'action' => 'planning_sheet_export',
        'entity_type' => 'planning_sheet',
        'entity_id' => (string)$chantierId,
        'entity_label' => 'Fiche réunion planning chantier',
        'format' => 'pdf',
        'details' => [
            'meeting_date' => $meetingDate,
            'row_count' => is_countable($rows ?? null) ? count($rows) : null,
            'import_id' => $projectImport['id'] ?? null,
        ],
    ], $pdo);
    $pdf->Output($fileName, 'I');
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur export PDF: ' . $e->getMessage();
}
