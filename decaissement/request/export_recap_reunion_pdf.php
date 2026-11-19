<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Dashboard.php';
require_once __DIR__ . '/../model/SuiviTravaux.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

class PDFRecapReunion extends TCPDF
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
        $this->Cell(0, 4, 'Recapitulatif complet des reunions chantier', 0, 0, 'L');
        $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

function recap_safe_text($value, string $fallback = '-'): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : $fallback;
}

function recap_format_date(?string $value, bool $withTime = false): string
{
    if (!$value) {
        return '-';
    }
    try {
        return (new DateTime($value))->format($withTime ? 'd/m/Y H:i' : 'd/m/Y');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function recap_format_percent($value): string
{
    return number_format((float)$value, 1, ',', ' ') . '%';
}

function recap_status_meta(?string $key): array
{
    $palette = SuiviTravaux::statusOptions();
    return $palette[$key] ?? [
        'label' => 'Non defini',
        'color' => '#64748b',
    ];
}

function recap_shorten(string $value, int $limit): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit - 1, 'UTF-8') . '...' : $text;
    }

    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function recap_normalize_text($value, string $fallback = '-'): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return $fallback;
    }

    return str_replace(["\r\n", "\r"], "\n", $text);
}

function recap_measure_row_height(TCPDF $pdf, array $cells, float $defaultMinHeight = 7.0): float
{
    $rowHeight = $defaultMinHeight;
    foreach ($cells as $cell) {
        $width = (float)($cell['width'] ?? 0);
        if ($width <= 0) {
            continue;
        }

        $text = recap_normalize_text($cell['text'] ?? '', $cell['fallback'] ?? '-');
        $minHeight = (float)($cell['minHeight'] ?? $defaultMinHeight);
        $height = max($minHeight, $pdf->getStringHeight(max(1.0, $width - 2), $text) + 2);
        if ($height > $rowHeight) {
            $rowHeight = $height;
        }
    }

    return $rowHeight;
}

function recap_draw_table_row(TCPDF $pdf, array $cells, ?float $rowHeight = null): float
{
    $startX = $pdf->GetX();
    $startY = $pdf->GetY();
    $rowHeight = $rowHeight ?? recap_measure_row_height($pdf, $cells);
    $cursorX = $startX;

    foreach ($cells as $cell) {
        $width = (float)($cell['width'] ?? 0);
        if ($width <= 0) {
            continue;
        }

        $text = recap_normalize_text($cell['text'] ?? '', $cell['fallback'] ?? '-');
        $align = (string)($cell['align'] ?? 'L');
        $fill = (bool)($cell['fill'] ?? false);
        $border = $cell['border'] ?? 1;

        $pdf->MultiCell($width, $rowHeight, $text, $border, $align, $fill, 0, $cursorX, $startY, true, 0, false, true, $rowHeight, 'M');
        $cursorX += $width;
    }

    $pdf->SetXY($startX, $startY + $rowHeight);
    return $rowHeight;
}

function recap_draw_section_title(TCPDF $pdf, string $title): void
{
    $pdf->Ln(2);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 7, $title, 0, 1, 'L');
}

function recap_draw_chantier_group(TCPDF $pdf, string $chantierLabel): void
{
    $pdf->SetFillColor(3, 105, 161);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->MultiCell(0, 8, 'Chantier: ' . recap_normalize_text($chantierLabel, 'Chantier'), 0, 'L', true, 1, null, null, true, 0, false, true, 8, 'M');
    $pdf->Ln(1);
}

$chantierId = (int)($_GET['chantier_id'] ?? 0);

if ($chantierId <= 0) {
    http_response_code(400);
    echo 'Parametre chantier invalide.';
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

    $latestDailyUpdates = $suiviTravauxModel->getLatestUpdatesByChantier($chantierId);
    $dailyHistory = $suiviTravauxModel->getHistoryByChantier($chantierId, 300);
    $taskAssignments = $suiviTravauxModel->getAssignmentsByChantier($chantierId);

    $toFloat = static function ($value): float {
        return is_numeric($value) ? (float)$value : 0.0;
    };

    $today = new DateTimeImmutable('today');
    $todayIso = $today->format('Y-m-d');

    $lineMetaById = [];
    foreach (($detailsChantier['lignes_devis'] ?? []) as $ligneDevis) {
        $lineMetaById[(int)($ligneDevis['id'] ?? 0)] = [
            'designation' => (string)($ligneDevis['designation'] ?? ''),
            'zone' => (string)($ligneDevis['zone'] ?? ''),
            'groupe' => (string)($ligneDevis['groupe'] ?? ''),
        ];
    }

    $statusPalette = [
        'pas_commencee' => ['label' => 'Pas commencee'],
        'en_cours' => ['label' => 'En cours'],
        'terminee' => ['label' => 'Terminee'],
        'attente_decaissement' => ['label' => 'Attente decaissement'],
        'attente_dependance' => ['label' => 'Attente autre tache'],
        'bloquee' => ['label' => 'Bloquee'],
        'suspendue' => ['label' => 'Suspendue'],
        'a_venir' => ['label' => 'A venir'],
        'termine_theorique' => ['label' => 'Fin theorique atteinte'],
        'non_planifie' => ['label' => 'Non planifiee'],
    ];

    $meetingStats = [
        'updated_today' => 0,
        'blocked' => 0,
        'waiting' => 0,
        'completed' => 0,
        'without_update' => 0,
        'late_vs_theory' => 0,
    ];

    $plannedDurationTotal = 0;
    $progressSum = 0.0;
    $hierarchy = [];
    $taskLookup = [];

    foreach (($detailsChantier['lignes_debourse'] ?? []) as $index => $ligneDebourse) {
        $ligneDebourseId = (int)($ligneDebourse['id'] ?? 0);
        $debourseId = (int)($ligneDebourse['debourse_id'] ?? 0);
        $lineId = (int)($ligneDebourse['ligne_devis_source_id'] ?? 0);
        $lineMeta = $lineId > 0 && isset($lineMetaById[$lineId]) ? $lineMetaById[$lineId] : [];
        $latestUpdate = $ligneDebourseId > 0 ? ($latestDailyUpdates[$ligneDebourseId] ?? null) : null;
        $taskAssignment = $ligneDebourseId > 0 ? ($taskAssignments[$ligneDebourseId] ?? null) : null;

        $zone = trim((string)($ligneDebourse['zone_ligne_devis'] ?? ($lineMeta['zone'] ?? '')));
        if ($zone === '') {
            $zone = 'Hors zone';
        }

        $designation = (string)($ligneDebourse['designation'] ?? ('Tache ' . ($index + 1)));
        $designationSource = (string)($ligneDebourse['designation_ligne_devis'] ?? ($lineMeta['designation'] ?? 'Non rattachee'));
        $debourseLabel = 'Debourse #' . number_format($debourseId, 0, ',', ' ');
        if (trim($designationSource) !== '') {
            $debourseLabel .= ' - ' . $designationSource;
        }

        $startRaw = (string)($ligneDebourse['date_debut'] ?? $ligneDebourse['date_debut_debourse'] ?? '');
        $endRaw = (string)($ligneDebourse['date_fin'] ?? $ligneDebourse['date_fin_debourse'] ?? '');

        if ($startRaw === '' && $endRaw !== '') {
            $startRaw = $endRaw;
        }
        if ($endRaw === '' && $startRaw !== '') {
            $endRaw = $startRaw;
        }

        $startDate = $startRaw !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $startRaw) ?: null : null;
        $endDate = $endRaw !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $endRaw) ?: null : null;
        if ($startDate && $endDate && $endDate < $startDate) {
            $endDate = $startDate;
            $endRaw = $startRaw;
        }

        $statusKey = 'non_planifie';
        $progressTheorique = 0.0;
        $durationDays = 0;

        if ($startDate && $endDate) {
            $durationDays = max(1, (int)$startDate->diff($endDate)->days + 1);
            if ($today < $startDate) {
                $statusKey = 'a_venir';
                $progressTheorique = 0;
            } elseif ($today > $endDate) {
                $statusKey = 'termine_theorique';
                $progressTheorique = 100;
            } else {
                $statusKey = 'en_cours';
                $elapsed = max(1, (int)$startDate->diff($today)->days + 1);
                $progressTheorique = min(100, round(($elapsed / $durationDays) * 100, 1));
            }
        }

        $actualProgress = $latestUpdate !== null ? round($toFloat($latestUpdate['progression_reelle'] ?? 0), 1) : null;
        $actualStatusKey = $latestUpdate['statut_reel'] ?? null;
        $displayProgress = $actualProgress !== null ? $actualProgress : $progressTheorique;
        $displayStatusKey = ($actualStatusKey && isset($statusPalette[$actualStatusKey])) ? $actualStatusKey : $statusKey;

        if ($latestUpdate && ($latestUpdate['date_suivi'] ?? '') === $todayIso) {
            $meetingStats['updated_today']++;
        }
        if (!$latestUpdate) {
            $meetingStats['without_update']++;
        }
        if (in_array($displayStatusKey, ['bloquee', 'suspendue'], true)) {
            $meetingStats['blocked']++;
        }
        if (in_array($displayStatusKey, ['attente_decaissement', 'attente_dependance'], true)) {
            $meetingStats['waiting']++;
        }
        if (in_array($displayStatusKey, ['terminee', 'termine_theorique'], true)) {
            $meetingStats['completed']++;
        }
        if ($actualProgress !== null && ($progressTheorique - $actualProgress) >= 15) {
            $meetingStats['late_vs_theory']++;
        }
        if ($durationDays > 0) {
            $plannedDurationTotal += $durationDays;
            $progressSum += $displayProgress * $durationDays;
        }

        $pilotNames = [];
        foreach (($taskAssignment['pilots'] ?? []) as $pilot) {
            $pilotNames[] = recap_safe_text($pilot['name'] ?? '');
        }

        $taskItem = [
            'id' => $ligneDebourseId,
            'debourse_id' => $debourseId,
            'debourse_label' => $debourseLabel,
            'zone' => $zone,
            'line_label' => $designation,
            'detail_ligne' => (string)($latestUpdate['detail_ligne_debourse'] ?? ''),
            'status' => $statusPalette[$displayStatusKey]['label'] ?? $displayStatusKey,
            'progress' => $displayProgress,
            'date_suivi' => (string)($latestUpdate['date_suivi'] ?? ''),
            'updated_by' => (string)($latestUpdate['updated_by'] ?? ''),
            'commentaire' => (string)($latestUpdate['commentaire'] ?? ''),
            'blocage' => (string)($latestUpdate['blocage_detail'] ?? ''),
            'service' => (string)($taskAssignment['service_label'] ?? ''),
            'pilotes' => implode(', ', array_filter($pilotNames)),
        ];

        if (!isset($hierarchy[$zone])) {
            $hierarchy[$zone] = [];
        }
        if (!isset($hierarchy[$zone][$debourseId])) {
            $hierarchy[$zone][$debourseId] = [
                'debourse_label' => $debourseLabel,
                'items' => [],
            ];
        }
        $hierarchy[$zone][$debourseId]['items'][] = $taskItem;
        $taskLookup[$ligneDebourseId] = $taskItem;
    }

    ksort($hierarchy, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($hierarchy as &$zoneDebourses) {
        ksort($zoneDebourses, SORT_NUMERIC);
    }
    unset($zoneDebourses);

    $globalProgress = $plannedDurationTotal > 0 ? round($progressSum / $plannedDurationTotal, 1) : 0.0;
    $chantierGroupLabel = trim((string)($detailsChantier['chantier']['titre'] ?? 'Chantier'));

    $chantierPrecisionParts = [];
    if (!empty($detailsChantier['chantier']['code'])) {
        $chantierPrecisionParts[] = 'Code: ' . (string)$detailsChantier['chantier']['code'];
    }
    if (!empty($detailsChantier['chantier']['nom_client'])) {
        $chantierPrecisionParts[] = 'Client: ' . (string)$detailsChantier['chantier']['nom_client'];
    }
    if (!empty($detailsChantier['chantier']['localisation_client'])) {
        $chantierPrecisionParts[] = 'Localisation: ' . (string)$detailsChantier['chantier']['localisation_client'];
    } elseif (!empty($detailsChantier['chantier']['commune_client'])) {
        $chantierPrecisionParts[] = 'Commune: ' . (string)$detailsChantier['chantier']['commune_client'];
    }
    if (!empty($detailsChantier['chantier']['numero_devis'])) {
        $chantierPrecisionParts[] = 'Devis: ' . (string)$detailsChantier['chantier']['numero_devis'];
    }
    $chantierPrecision = !empty($chantierPrecisionParts) ? implode(' | ', $chantierPrecisionParts) : 'Precision chantier non renseignee';

    $blockerCounts = [];
    foreach ($dailyHistory as $historyItem) {
        $blocker = trim((string)($historyItem['blocage_detail'] ?? ''));
        if ($blocker === '') {
            continue;
        }
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($blocker, 'UTF-8') : strtolower($blocker);
        if (!isset($blockerCounts[$normalized])) {
            $blockerCounts[$normalized] = [
                'label' => $blocker,
                'count' => 0,
            ];
        }
        $blockerCounts[$normalized]['count']++;
    }
    usort($blockerCounts, static function ($left, $right) {
        if (($right['count'] ?? 0) === ($left['count'] ?? 0)) {
            return strcmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
        }
        return ($right['count'] ?? 0) <=> ($left['count'] ?? 0);
    });
    $topBlockers = array_slice(array_values($blockerCounts), 0, 5);

    $pdf = new PDFRecapReunion('L', 'mm', 'A4');
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Recap reunion chantier ' . $chantierId);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    $contentWidth = $pdf->getPageWidth() - 24;

    $pdf->SetFillColor(15, 23, 42);
    $pdf->RoundedRect(12, 12, $contentWidth, 28, 4, '1111', 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 17);
    $pdf->SetXY(16, 16);
    $pdf->Cell(150, 7, 'Recapitulatif complet des reunions', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(226, 232, 240);
    $pdf->SetX(16);
    $pdf->MultiCell(250, 5, recap_normalize_text($detailsChantier['chantier']['lib_chantier'] ?? 'Chantier'), 0, 'L', false, 1, null, null, true, 0, false, true, 5, 'M');
    $pdf->SetX(16);
    $pdf->MultiCell(250, 5, recap_normalize_text($chantierPrecision, 'Precision chantier non renseignee'), 0, 'L', false, 1, null, null, true, 0, false, true, 5, 'M');
    $pdf->SetX(16);
    $pdf->Cell(170, 5, 'Edition du ' . date('d/m/Y H:i'), 0, 1, 'L');

    $cardY = 47;
    $cardGap = 4;
    $cardWidth = ($contentWidth - ($cardGap * 4)) / 5;
    $cards = [
        ['label' => 'Avancement global', 'value' => recap_format_percent($globalProgress)],
        ['label' => 'Points saisis aujourd hui', 'value' => number_format($meetingStats['updated_today'], 0, ',', ' ')],
        ['label' => 'Blocages actifs', 'value' => number_format($meetingStats['blocked'], 0, ',', ' ')],
        ['label' => 'En attente', 'value' => number_format($meetingStats['waiting'], 0, ',', ' ')],
        ['label' => 'Sans point reel', 'value' => number_format($meetingStats['without_update'], 0, ',', ' ')],
    ];
    foreach ($cards as $index => $card) {
        $x = 12 + (($cardWidth + $cardGap) * $index);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($x, $cardY, $cardWidth, 18, 3, '1111', 'DF');
        $pdf->SetXY($x + 3, $cardY + 2);
        $pdf->SetFont('dejavusans', '', 7.3);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($cardWidth - 6, 4, $card['label'], 0, 1, 'L');
        $pdf->SetX($x + 3);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell($cardWidth - 6, 7, $card['value'], 0, 0, 'L');
    }

    $pdf->SetY(72);
    recap_draw_section_title($pdf, 'Vue synthese');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(51, 65, 85);
    $summaryText = sprintf(
        'Taches suivies: %s | Taches terminees: %s | Taches en retard versus theorie: %s | Points journaliers enregistres: %s',
        number_format(count($taskLookup), 0, ',', ' '),
        number_format($meetingStats['completed'], 0, ',', ' '),
        number_format($meetingStats['late_vs_theory'], 0, ',', ' '),
        number_format(count($dailyHistory), 0, ',', ' ')
    );
    $pdf->MultiCell(0, 6, $summaryText, 0, 'L', false, 1);

    recap_draw_section_title($pdf, 'Blocages frequents');
    if (!empty($topBlockers)) {
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(14, 7, '#', 1, 0, 'C', true);
        $pdf->Cell(210, 7, 'Blocage / dependance', 1, 0, 'L', true);
        $pdf->Cell(38, 7, 'Occurrences', 1, 1, 'C', true);
        $rank = 1;
        foreach ($topBlockers as $blockerItem) {
            $cells = [
                ['width' => 14, 'text' => (string)$rank, 'align' => 'C', 'fallback' => '-'],
                ['width' => 210, 'text' => (string)($blockerItem['label'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                ['width' => 38, 'text' => number_format((int)($blockerItem['count'] ?? 0), 0, ',', ' '), 'align' => 'C', 'fallback' => '0'],
            ];
            $rowHeight = recap_measure_row_height($pdf, $cells, 7.0);
            if (($pdf->GetY() + $rowHeight) > 186) {
                $pdf->AddPage();
                recap_draw_section_title($pdf, 'Blocages frequents');
                $pdf->SetFont('dejavusans', '', 8.5);
                $pdf->SetFillColor(241, 245, 249);
                $pdf->SetTextColor(30, 41, 59);
                $pdf->Cell(14, 7, '#', 1, 0, 'C', true);
                $pdf->Cell(210, 7, 'Blocage / dependance', 1, 0, 'L', true);
                $pdf->Cell(38, 7, 'Occurrences', 1, 1, 'C', true);
            }
            recap_draw_table_row($pdf, $cells, $rowHeight);
            $rank++;
        }
    } else {
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->Cell(0, 7, 'Aucun blocage frequent releve dans les points enregistres.', 1, 1, 'C');
    }

    recap_draw_section_title($pdf, 'Structure chantier > zone > debourse > ligne > detail');
    recap_draw_chantier_group($pdf, $chantierGroupLabel);
    foreach ($hierarchy as $zoneName => $zoneDebourses) {
        if ($pdf->GetY() > 170) {
            $pdf->AddPage();
            recap_draw_section_title($pdf, 'Structure chantier > zone > debourse > ligne > detail');
            recap_draw_chantier_group($pdf, $chantierGroupLabel);
        }

        $pdf->SetFillColor(15, 23, 42);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->MultiCell(0, 8, 'Zone: ' . recap_normalize_text((string)$zoneName, 'Hors zone'), 0, 'L', true, 1, null, null, true, 0, false, true, 8, 'M');
        $pdf->Ln(1);

        foreach ($zoneDebourses as $debourseId => $debourseData) {
            if ($pdf->GetY() > 176) {
                $pdf->AddPage();
                recap_draw_section_title($pdf, 'Structure chantier > zone > debourse > ligne > detail');
                recap_draw_chantier_group($pdf, $chantierGroupLabel);
                $pdf->SetFillColor(15, 23, 42);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('dejavusans', 'B', 10);
                $pdf->MultiCell(0, 8, 'Zone: ' . recap_normalize_text((string)$zoneName, 'Hors zone'), 0, 'L', true, 1, null, null, true, 0, false, true, 8, 'M');
                $pdf->Ln(1);
            }

            $pdf->SetFillColor(241, 245, 249);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->SetFont('dejavusans', 'B', 8.6);
            $pdf->MultiCell(0, 7, recap_normalize_text((string)($debourseData['debourse_label'] ?? ('Debourse #' . $debourseId)), 'Debourse'), 1, 'L', true, 1, null, null, true, 0, false, true, 7, 'M');

            $pdf->SetFont('dejavusans', 'B', 7.1);
            $pdf->Cell(54, 7, 'Ligne debourse', 1, 0, 'L', true);
            $pdf->Cell(74, 7, 'Detail supplementaire', 1, 0, 'L', true);
            $pdf->Cell(18, 7, 'Avanc.', 1, 0, 'C', true);
            $pdf->Cell(30, 7, 'Statut', 1, 0, 'C', true);
            $pdf->Cell(24, 7, 'Date point', 1, 0, 'C', true);
            $pdf->Cell(30, 7, 'Service', 1, 0, 'L', true);
            $pdf->Cell(38, 7, 'Auteur', 1, 1, 'L', true);

            $pdf->SetFont('dejavusans', '', 7.0);
            foreach ($debourseData['items'] as $item) {
                $cells = [
                    ['width' => 54, 'text' => (string)($item['line_label'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                    ['width' => 74, 'text' => (string)($item['detail_ligne'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                    ['width' => 18, 'text' => recap_format_percent($item['progress'] ?? 0), 'align' => 'C', 'fallback' => '0,0%'],
                    ['width' => 30, 'text' => (string)($item['status'] ?? ''), 'align' => 'C', 'fallback' => '-'],
                    ['width' => 24, 'text' => recap_format_date(($item['date_suivi'] ?? '') !== '' ? (string)$item['date_suivi'] : null), 'align' => 'C', 'fallback' => '-'],
                    ['width' => 30, 'text' => (string)($item['service'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                    ['width' => 38, 'text' => (string)($item['updated_by'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                ];
                $rowHeight = recap_measure_row_height($pdf, $cells, 7.0);
                if ($pdf->GetY() > 182) {
                    $pdf->AddPage();
                    recap_draw_section_title($pdf, 'Structure chantier > zone > debourse > ligne > detail');
                    recap_draw_chantier_group($pdf, $chantierGroupLabel);
                    $pdf->SetFillColor(15, 23, 42);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFont('dejavusans', 'B', 10);
                    $pdf->MultiCell(0, 8, 'Zone: ' . recap_normalize_text((string)$zoneName, 'Hors zone'), 0, 'L', true, 1, null, null, true, 0, false, true, 8, 'M');
                    $pdf->Ln(1);
                    $pdf->SetFillColor(241, 245, 249);
                    $pdf->SetFont('dejavusans', 'B', 8.6);
                    $pdf->SetTextColor(15, 23, 42);
                    $pdf->MultiCell(0, 7, recap_normalize_text((string)($debourseData['debourse_label'] ?? ('Debourse #' . $debourseId)), 'Debourse'), 1, 'L', true, 1, null, null, true, 0, false, true, 7, 'M');
                    $pdf->SetFont('dejavusans', 'B', 7.1);
                    $pdf->Cell(54, 7, 'Ligne debourse', 1, 0, 'L', true);
                    $pdf->Cell(74, 7, 'Detail supplementaire', 1, 0, 'L', true);
                    $pdf->Cell(18, 7, 'Avanc.', 1, 0, 'C', true);
                    $pdf->Cell(30, 7, 'Statut', 1, 0, 'C', true);
                    $pdf->Cell(24, 7, 'Date point', 1, 0, 'C', true);
                    $pdf->Cell(30, 7, 'Service', 1, 0, 'L', true);
                    $pdf->Cell(38, 7, 'Auteur', 1, 1, 'L', true);
                    $pdf->SetFont('dejavusans', '', 7.0);
                }

                if (($pdf->GetY() + $rowHeight) > 190) {
                    $pdf->AddPage();
                    recap_draw_section_title($pdf, 'Structure chantier > zone > debourse > ligne > detail');
                    recap_draw_chantier_group($pdf, $chantierGroupLabel);
                    $pdf->SetFillColor(15, 23, 42);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFont('dejavusans', 'B', 10);
                    $pdf->MultiCell(0, 8, 'Zone: ' . recap_normalize_text((string)$zoneName, 'Hors zone'), 0, 'L', true, 1, null, null, true, 0, false, true, 8, 'M');
                    $pdf->Ln(1);
                    $pdf->SetFillColor(241, 245, 249);
                    $pdf->SetTextColor(15, 23, 42);
                    $pdf->SetFont('dejavusans', 'B', 8.6);
                    $pdf->MultiCell(0, 7, recap_normalize_text((string)($debourseData['debourse_label'] ?? ('Debourse #' . $debourseId)), 'Debourse'), 1, 'L', true, 1, null, null, true, 0, false, true, 7, 'M');
                    $pdf->SetFont('dejavusans', 'B', 7.1);
                    $pdf->Cell(54, 7, 'Ligne debourse', 1, 0, 'L', true);
                    $pdf->Cell(74, 7, 'Detail supplementaire', 1, 0, 'L', true);
                    $pdf->Cell(18, 7, 'Avanc.', 1, 0, 'C', true);
                    $pdf->Cell(30, 7, 'Statut', 1, 0, 'C', true);
                    $pdf->Cell(24, 7, 'Date point', 1, 0, 'C', true);
                    $pdf->Cell(30, 7, 'Service', 1, 0, 'L', true);
                    $pdf->Cell(38, 7, 'Auteur', 1, 1, 'L', true);
                    $pdf->SetFont('dejavusans', '', 7.0);
                    $rowHeight = recap_measure_row_height($pdf, $cells, 7.0);
                }

                recap_draw_table_row($pdf, $cells, $rowHeight);
            }

            $pdf->Ln(2);
        }
    }

    $pdf->AddPage();
    recap_draw_section_title($pdf, 'Journal complet des points saisis');
    $pdf->SetFont('dejavusans', 'B', 7.4);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Cell(18, 7, 'Date', 1, 0, 'C', true);
    $pdf->Cell(32, 7, 'Chantier', 1, 0, 'L', true);
    $pdf->Cell(22, 7, 'Zone', 1, 0, 'L', true);
    $pdf->Cell(34, 7, 'Debourse', 1, 0, 'L', true);
    $pdf->Cell(30, 7, 'Ligne', 1, 0, 'L', true);
    $pdf->Cell(38, 7, 'Detail ligne', 1, 0, 'L', true);
    $pdf->Cell(18, 7, 'Prog.', 1, 0, 'C', true);
    $pdf->Cell(28, 7, 'Statut', 1, 0, 'C', true);
    $pdf->Cell(28, 7, 'Auteur', 1, 0, 'L', true);
    $pdf->Cell(43, 7, 'Commentaire / blocage', 1, 1, 'L', true);

    $pdf->SetFont('dejavusans', '', 7.1);
    foreach ($dailyHistory as $historyItem) {
        $taskId = (int)($historyItem['ligne_debourse_id'] ?? 0);
        $taskMeta = $taskLookup[$taskId] ?? null;
        $statusMeta = recap_status_meta($historyItem['statut_reel'] ?? null);
        $commentLine = trim((string)($historyItem['commentaire'] ?? ''));
        $blockerLine = trim((string)($historyItem['blocage_detail'] ?? ''));
        $detailLine = trim((string)($historyItem['detail_ligne_debourse'] ?? ''));
        $joinedText = trim($commentLine . ($blockerLine !== '' ? ' | Blocage: ' . $blockerLine : ''));

        $cells = [
            ['width' => 18, 'text' => recap_format_date($historyItem['date_suivi'] ?? null), 'align' => 'C', 'fallback' => '-'],
            ['width' => 32, 'text' => $chantierGroupLabel, 'align' => 'L', 'fallback' => 'Chantier'],
            ['width' => 22, 'text' => (string)($taskMeta['zone'] ?? 'Hors zone'), 'align' => 'L', 'fallback' => 'Hors zone'],
            ['width' => 34, 'text' => (string)($taskMeta['debourse_label'] ?? ('Debourse #' . $taskId)), 'align' => 'L', 'fallback' => '-'],
            ['width' => 30, 'text' => (string)($taskMeta['line_label'] ?? ('Tache #' . $taskId)), 'align' => 'L', 'fallback' => '-'],
            ['width' => 38, 'text' => $detailLine, 'align' => 'L', 'fallback' => '-'],
            ['width' => 18, 'text' => recap_format_percent($historyItem['progression_reelle'] ?? 0), 'align' => 'C', 'fallback' => '0,0%'],
            ['width' => 28, 'text' => (string)($statusMeta['label'] ?? 'Non defini'), 'align' => 'C', 'fallback' => '-'],
            ['width' => 28, 'text' => (string)($historyItem['updated_by'] ?? 'Utilisateur'), 'align' => 'L', 'fallback' => 'Utilisateur'],
            ['width' => 43, 'text' => $joinedText !== '' ? $joinedText : 'Sans commentaire', 'align' => 'L', 'fallback' => 'Sans commentaire'],
        ];
        $rowHeight = recap_measure_row_height($pdf, $cells, 7.0);

        if (($pdf->GetY() + $rowHeight) > 190) {
            $pdf->AddPage();
            recap_draw_section_title($pdf, 'Journal complet des points saisis');
            $pdf->SetFont('dejavusans', 'B', 7.4);
            $pdf->SetFillColor(241, 245, 249);
            $pdf->Cell(18, 7, 'Date', 1, 0, 'C', true);
            $pdf->Cell(32, 7, 'Chantier', 1, 0, 'L', true);
            $pdf->Cell(22, 7, 'Zone', 1, 0, 'L', true);
            $pdf->Cell(34, 7, 'Debourse', 1, 0, 'L', true);
            $pdf->Cell(30, 7, 'Ligne', 1, 0, 'L', true);
            $pdf->Cell(38, 7, 'Detail ligne', 1, 0, 'L', true);
            $pdf->Cell(18, 7, 'Prog.', 1, 0, 'C', true);
            $pdf->Cell(28, 7, 'Statut', 1, 0, 'C', true);
            $pdf->Cell(28, 7, 'Auteur', 1, 0, 'L', true);
            $pdf->Cell(43, 7, 'Commentaire / blocage', 1, 1, 'L', true);
            $pdf->SetFont('dejavusans', '', 7.1);
            $rowHeight = recap_measure_row_height($pdf, $cells, 7.0);
        }

        recap_draw_table_row($pdf, $cells, $rowHeight);
    }

    if (empty($dailyHistory)) {
        $pdf->Cell(268, 8, 'Aucun point quotidien n a encore ete enregistre sur ce chantier.', 1, 1, 'C');
    }

    $fileName = 'recap_reunion_chantier_' . $chantierId . '.pdf';
    \AuditTrail::logFileEvent('export', [
        'module' => 'planning',
        'action' => 'reunion_recap_export',
        'entity_type' => 'chantier',
        'entity_id' => (string)$chantierId,
        'entity_label' => (string)($detailsChantier['chantier']['lib_chantier'] ?? 'Récap réunion chantier'),
        'format' => 'pdf',
        'details' => [
            'daily_history_count' => is_countable($dailyHistory ?? null) ? count($dailyHistory) : null,
            'assignment_count' => is_countable($taskAssignments ?? null) ? count($taskAssignments) : null,
        ],
    ], $pdo);
    $pdf->Output($fileName, 'I');
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur export PDF recap: ' . $e->getMessage();
}
