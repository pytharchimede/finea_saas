<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Dashboard.php';
require_once __DIR__ . '/../model/SuiviTravaux.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

class PDFReunionMultiChantiers extends TCPDF
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
        $this->Cell(0, 4, 'Reunion multi-chantiers', 0, 0, 'L');
        $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

function multi_safe_text($value, string $fallback = '-'): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : $fallback;
}

function multi_format_date(?string $value, bool $withTime = false): string
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

function multi_format_percent($value): string
{
    return number_format((float)$value, 1, ',', ' ') . '%';
}

function multi_normalize_text($value, string $fallback = '-'): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return $fallback;
    }

    return str_replace(["\r\n", "\r"], "\n", $text);
}

function multi_status_meta(?string $key): array
{
    $palette = SuiviTravaux::statusOptions();
    return $palette[$key] ?? [
        'label' => 'Non defini',
        'color' => '#64748b',
    ];
}

function multi_measure_row_height(TCPDF $pdf, array $cells, float $defaultMinHeight = 7.0): float
{
    $rowHeight = $defaultMinHeight;
    foreach ($cells as $cell) {
        $width = (float)($cell['width'] ?? 0);
        if ($width <= 0) {
            continue;
        }

        $text = multi_normalize_text($cell['text'] ?? '', $cell['fallback'] ?? '-');
        $minHeight = (float)($cell['minHeight'] ?? $defaultMinHeight);
        $height = max($minHeight, $pdf->getStringHeight(max(1.0, $width - 2), $text) + 2);
        if ($height > $rowHeight) {
            $rowHeight = $height;
        }
    }

    return $rowHeight;
}

function multi_draw_table_row(TCPDF $pdf, array $cells, ?float $rowHeight = null): float
{
    $startX = $pdf->GetX();
    $startY = $pdf->GetY();
    $rowHeight = $rowHeight ?? multi_measure_row_height($pdf, $cells);
    $cursorX = $startX;

    foreach ($cells as $cell) {
        $width = (float)($cell['width'] ?? 0);
        if ($width <= 0) {
            continue;
        }

        $text = multi_normalize_text($cell['text'] ?? '', $cell['fallback'] ?? '-');
        $align = (string)($cell['align'] ?? 'L');
        $fill = (bool)($cell['fill'] ?? false);
        $border = $cell['border'] ?? 1;

        $pdf->MultiCell($width, $rowHeight, $text, $border, $align, $fill, 0, $cursorX, $startY, true, 0, false, true, $rowHeight, 'M');
        $cursorX += $width;
    }

    $pdf->SetXY($startX, $startY + $rowHeight);
    return $rowHeight;
}

function multi_draw_section_title(TCPDF $pdf, string $title): void
{
    $pdf->Ln(2);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 7, $title, 0, 1, 'L');
}

function multi_build_chantier_precision(array $chantier): string
{
    $parts = [];
    if (!empty($chantier['code'])) {
        $parts[] = 'Code: ' . (string)$chantier['code'];
    }
    if (!empty($chantier['nom_client'])) {
        $parts[] = 'Client: ' . (string)$chantier['nom_client'];
    }
    if (!empty($chantier['localisation_client'])) {
        $parts[] = 'Localisation: ' . (string)$chantier['localisation_client'];
    } elseif (!empty($chantier['commune_client'])) {
        $parts[] = 'Commune: ' . (string)$chantier['commune_client'];
    }
    if (!empty($chantier['numero_devis'])) {
        $parts[] = 'Devis: ' . (string)$chantier['numero_devis'];
    }

    return !empty($parts) ? implode(' | ', $parts) : 'Precision chantier non renseignee';
}

function multi_draw_chantier_banner(TCPDF $pdf, string $chantierLabel, string $precision): void
{
    $pdf->SetFillColor(15, 23, 42);
    $pdf->RoundedRect(12, 12, $pdf->getPageWidth() - 24, 24, 4, '1111', 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->SetXY(16, 16);
    $pdf->Cell(180, 6, multi_normalize_text($chantierLabel, 'Chantier'), 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 8.8);
    $pdf->SetTextColor(226, 232, 240);
    $pdf->SetX(16);
    $pdf->MultiCell(250, 4.8, multi_normalize_text($precision, 'Precision chantier non renseignee'), 0, 'L', false, 1, null, null, true, 0, false, true, 4.8, 'M');
}

function multi_draw_structure_header(TCPDF $pdf): void
{
    $pdf->SetFont('dejavusans', 'B', 7.1);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(50, 7, 'Ligne debourse', 1, 0, 'L', true);
    $pdf->Cell(74, 7, 'Detail supplementaire', 1, 0, 'L', true);
    $pdf->Cell(18, 7, 'Avanc.', 1, 0, 'C', true);
    $pdf->Cell(28, 7, 'Statut', 1, 0, 'C', true);
    $pdf->Cell(24, 7, 'Service', 1, 0, 'L', true);
    $pdf->Cell(30, 7, 'Pilotes', 1, 0, 'L', true);
    $pdf->Cell(25, 7, 'Auteur', 1, 0, 'L', true);
    $pdf->Cell(24, 7, 'Heure', 1, 1, 'C', true);
}

function multi_draw_detail_header(TCPDF $pdf): void
{
    $pdf->SetFont('dejavusans', 'B', 7.2);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(22, 7, 'Zone', 1, 0, 'L', true);
    $pdf->Cell(38, 7, 'Debourse', 1, 0, 'L', true);
    $pdf->Cell(36, 7, 'Ligne', 1, 0, 'L', true);
    $pdf->Cell(24, 7, 'Statut', 1, 0, 'C', true);
    $pdf->Cell(24, 7, 'Auteur', 1, 0, 'L', true);
    $pdf->Cell(129, 7, 'Commentaire / blocage / action', 1, 1, 'L', true);
}

$dateSuivi = trim((string)($_GET['date_suivi'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateSuivi)) {
    http_response_code(400);
    echo 'Parametre date_suivi invalide.';
    exit;
}

try {
    $pdo = Database::getConnection();
    $dashboardModel = new Dashboard($pdo);
    $suiviTravauxModel = new SuiviTravaux($pdo);

    $dailyHistory = $suiviTravauxModel->getHistoryByDate($dateSuivi, 3000);
    if (empty($dailyHistory)) {
        throw new RuntimeException('Aucun point de reunion n a ete enregistre pour cette date.');
    }

    $historyByChantier = [];
    $distinctAuthors = [];
    $globalStats = [
        'points' => 0,
        'blocked' => 0,
        'waiting' => 0,
        'completed' => 0,
        'progress_sum' => 0.0,
    ];
    $blockerCounts = [];

    foreach ($dailyHistory as $historyRow) {
        $chantierId = (int)($historyRow['chantier_id'] ?? 0);
        if ($chantierId <= 0) {
            continue;
        }

        if (!isset($historyByChantier[$chantierId])) {
            $historyByChantier[$chantierId] = [];
        }
        $historyByChantier[$chantierId][] = $historyRow;

        $globalStats['points']++;
        $globalStats['progress_sum'] += (float)($historyRow['progression_reelle'] ?? 0);

        $statusKey = (string)($historyRow['statut_reel'] ?? '');
        if (in_array($statusKey, ['bloquee', 'suspendue'], true)) {
            $globalStats['blocked']++;
        }
        if (in_array($statusKey, ['attente_decaissement', 'attente_dependance'], true)) {
            $globalStats['waiting']++;
        }
        if ($statusKey === 'terminee') {
            $globalStats['completed']++;
        }

        $updatedBy = trim((string)($historyRow['updated_by'] ?? ''));
        if ($updatedBy !== '') {
            $distinctAuthors[$updatedBy] = true;
        }

        $blocker = trim((string)($historyRow['blocage_detail'] ?? ''));
        if ($blocker !== '') {
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($blocker, 'UTF-8') : strtolower($blocker);
            if (!isset($blockerCounts[$normalized])) {
                $blockerCounts[$normalized] = [
                    'label' => $blocker,
                    'count' => 0,
                ];
            }
            $blockerCounts[$normalized]['count']++;
        }
    }

    $sections = [];
    foreach ($historyByChantier as $chantierId => $chantierHistory) {
        $detailsChantier = $dashboardModel->getDetailsBudgetaireChantier($chantierId);
        if (!$detailsChantier) {
            continue;
        }

        $taskAssignments = $suiviTravauxModel->getAssignmentsByChantier($chantierId);
        $lineMetaById = [];
        foreach (($detailsChantier['lignes_devis'] ?? []) as $ligneDevis) {
            $lineMetaById[(int)($ligneDevis['id'] ?? 0)] = [
                'designation' => (string)($ligneDevis['designation'] ?? ''),
                'zone' => (string)($ligneDevis['zone'] ?? ''),
            ];
        }

        $historyByLine = [];
        foreach ($chantierHistory as $historyRow) {
            $lineId = (int)($historyRow['ligne_debourse_id'] ?? 0);
            if ($lineId > 0) {
                $historyByLine[$lineId] = $historyRow;
            }
        }

        $hierarchy = [];
        $taskLookup = [];
        $sectionStats = [
            'points' => 0,
            'blocked' => 0,
            'waiting' => 0,
            'progress_sum' => 0.0,
        ];

        foreach (($detailsChantier['lignes_debourse'] ?? []) as $index => $ligneDebourse) {
            $ligneDebourseId = (int)($ligneDebourse['id'] ?? 0);
            if ($ligneDebourseId <= 0 || !isset($historyByLine[$ligneDebourseId])) {
                continue;
            }

            $historyRow = $historyByLine[$ligneDebourseId];
            $debourseId = (int)($ligneDebourse['debourse_id'] ?? 0);
            $lineId = (int)($ligneDebourse['ligne_devis_source_id'] ?? 0);
            $lineMeta = $lineId > 0 && isset($lineMetaById[$lineId]) ? $lineMetaById[$lineId] : [];
            $taskAssignment = $taskAssignments[$ligneDebourseId] ?? null;

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

            $pilotNames = [];
            foreach (($taskAssignment['pilots'] ?? []) as $pilot) {
                $pilotNames[] = multi_safe_text($pilot['name'] ?? '');
            }

            $taskItem = [
                'id' => $ligneDebourseId,
                'zone' => $zone,
                'debourse_label' => $debourseLabel,
                'line_label' => $designation,
                'detail_ligne' => (string)($historyRow['detail_ligne_debourse'] ?? ''),
                'status' => multi_status_meta($historyRow['statut_reel'] ?? null)['label'] ?? 'Non defini',
                'progress' => (float)($historyRow['progression_reelle'] ?? 0),
                'updated_by' => (string)($historyRow['updated_by'] ?? ''),
                'updated_at' => (string)($historyRow['updated_at'] ?? ''),
                'service' => (string)($taskAssignment['service_label'] ?? ''),
                'pilotes' => implode(', ', array_filter($pilotNames)),
                'commentaire' => (string)($historyRow['commentaire'] ?? ''),
                'blocage' => (string)($historyRow['blocage_detail'] ?? ''),
                'prochaine_action' => (string)($historyRow['prochaine_action'] ?? ''),
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

            $sectionStats['points']++;
            $sectionStats['progress_sum'] += (float)$taskItem['progress'];
            if (in_array((string)($historyRow['statut_reel'] ?? ''), ['bloquee', 'suspendue'], true)) {
                $sectionStats['blocked']++;
            }
            if (in_array((string)($historyRow['statut_reel'] ?? ''), ['attente_decaissement', 'attente_dependance'], true)) {
                $sectionStats['waiting']++;
            }
        }

        if (empty($taskLookup)) {
            continue;
        }

        ksort($hierarchy, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($hierarchy as &$zoneDebourses) {
            ksort($zoneDebourses, SORT_NUMERIC);
        }
        unset($zoneDebourses);

        usort($chantierHistory, static function ($left, $right) {
            return strcmp((string)($left['updated_at'] ?? ''), (string)($right['updated_at'] ?? ''));
        });

        $sections[] = [
            'chantier_id' => $chantierId,
            'chantier_label' => trim((string)($detailsChantier['chantier']['titre'] ?? $detailsChantier['chantier']['lib_chantier'] ?? ('Chantier #' . $chantierId))),
            'chantier_precision' => multi_build_chantier_precision((array)($detailsChantier['chantier'] ?? [])),
            'hierarchy' => $hierarchy,
            'history' => $chantierHistory,
            'task_lookup' => $taskLookup,
            'stats' => [
                'points' => $sectionStats['points'],
                'blocked' => $sectionStats['blocked'],
                'waiting' => $sectionStats['waiting'],
                'average_progress' => $sectionStats['points'] > 0 ? round($sectionStats['progress_sum'] / $sectionStats['points'], 1) : 0.0,
            ],
        ];
    }

    if (empty($sections)) {
        throw new RuntimeException('Aucun chantier exploitable n a ete trouve pour cette date.');
    }

    usort($sections, static function ($left, $right) {
        return strcmp((string)($left['chantier_label'] ?? ''), (string)($right['chantier_label'] ?? ''));
    });

    usort($blockerCounts, static function ($left, $right) {
        if (($right['count'] ?? 0) === ($left['count'] ?? 0)) {
            return strcmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
        }
        return ($right['count'] ?? 0) <=> ($left['count'] ?? 0);
    });
    $topBlockers = array_slice(array_values($blockerCounts), 0, 6);

    $averageProgress = $globalStats['points'] > 0 ? round($globalStats['progress_sum'] / $globalStats['points'], 1) : 0.0;

    $pdf = new PDFReunionMultiChantiers('L', 'mm', 'A4');
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Reunion multi-chantiers ' . $dateSuivi);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    $contentWidth = $pdf->getPageWidth() - 24;

    $pdf->SetFillColor(15, 23, 42);
    $pdf->RoundedRect(12, 12, $contentWidth, 30, 4, '1111', 'F');
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 17);
    $pdf->SetXY(16, 16);
    $pdf->Cell(170, 7, 'Point de reunion multi-chantiers', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(226, 232, 240);
    $pdf->SetX(16);
    $pdf->MultiCell(245, 5, 'Tous les chantiers renseignes pour la reunion du ' . multi_format_date($dateSuivi) . '.', 0, 'L', false, 1, null, null, true, 0, false, true, 5, 'M');
    $pdf->SetX(16);
    $pdf->Cell(170, 5, 'Edition du ' . date('d/m/Y H:i'), 0, 1, 'L');

    $cardY = 48;
    $cardGap = 4;
    $cardWidth = ($contentWidth - ($cardGap * 4)) / 5;
    $cards = [
        ['label' => 'Chantiers concernes', 'value' => number_format(count($sections), 0, ',', ' ')],
        ['label' => 'Points saisis', 'value' => number_format($globalStats['points'], 0, ',', ' ')],
        ['label' => 'Avancement moyen', 'value' => multi_format_percent($averageProgress)],
        ['label' => 'Blocages actifs', 'value' => number_format($globalStats['blocked'], 0, ',', ' ')],
        ['label' => 'Participants', 'value' => number_format(count($distinctAuthors), 0, ',', ' ')],
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

    $pdf->SetY(73);
    multi_draw_section_title($pdf, 'Synthese du point de reunion');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(51, 65, 85);
    $pdf->MultiCell(
        0,
        6,
        sprintf(
            'Date du point: %s | Chantiers structures: %s | Taches terminees: %s | Taches en attente: %s',
            multi_format_date($dateSuivi),
            number_format(count($sections), 0, ',', ' '),
            number_format($globalStats['completed'], 0, ',', ' '),
            number_format($globalStats['waiting'], 0, ',', ' ')
        ),
        0,
        'L',
        false,
        1
    );

    multi_draw_section_title($pdf, 'Blocages frequents de la reunion');
    if (!empty($topBlockers)) {
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(14, 7, '#', 1, 0, 'C', true);
        $pdf->Cell(219, 7, 'Blocage / dependance', 1, 0, 'L', true);
        $pdf->Cell(40, 7, 'Occurrences', 1, 1, 'C', true);

        $rank = 1;
        foreach ($topBlockers as $blockerItem) {
            $cells = [
                ['width' => 14, 'text' => (string)$rank, 'align' => 'C', 'fallback' => '-'],
                ['width' => 219, 'text' => (string)($blockerItem['label'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                ['width' => 40, 'text' => number_format((int)($blockerItem['count'] ?? 0), 0, ',', ' '), 'align' => 'C', 'fallback' => '0'],
            ];
            $rowHeight = multi_measure_row_height($pdf, $cells, 7.0);
            if (($pdf->GetY() + $rowHeight) > 188) {
                $pdf->AddPage();
                multi_draw_section_title($pdf, 'Blocages frequents de la reunion');
                $pdf->SetFont('dejavusans', '', 8.5);
                $pdf->SetFillColor(241, 245, 249);
                $pdf->SetTextColor(30, 41, 59);
                $pdf->Cell(14, 7, '#', 1, 0, 'C', true);
                $pdf->Cell(219, 7, 'Blocage / dependance', 1, 0, 'L', true);
                $pdf->Cell(40, 7, 'Occurrences', 1, 1, 'C', true);
            }
            multi_draw_table_row($pdf, $cells, $rowHeight);
            $rank++;
        }
    } else {
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->Cell(0, 7, 'Aucun blocage n a ete remonte pour cette reunion.', 1, 1, 'C');
    }

    foreach ($sections as $index => $section) {
        $pdf->AddPage();
        multi_draw_chantier_banner($pdf, (string)$section['chantier_label'], (string)$section['chantier_precision']);

        $sectionCardY = 40;
        $sectionCardGap = 4;
        $sectionCardWidth = ($contentWidth - ($sectionCardGap * 3)) / 4;
        $sectionCards = [
            ['label' => 'Points', 'value' => number_format((int)($section['stats']['points'] ?? 0), 0, ',', ' ')],
            ['label' => 'Avancement moyen', 'value' => multi_format_percent($section['stats']['average_progress'] ?? 0)],
            ['label' => 'Blocages', 'value' => number_format((int)($section['stats']['blocked'] ?? 0), 0, ',', ' ')],
            ['label' => 'En attente', 'value' => number_format((int)($section['stats']['waiting'] ?? 0), 0, ',', ' ')],
        ];
        foreach ($sectionCards as $cardIndex => $card) {
            $x = 12 + (($sectionCardWidth + $sectionCardGap) * $cardIndex);
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->RoundedRect($x, $sectionCardY, $sectionCardWidth, 16, 3, '1111', 'DF');
            $pdf->SetXY($x + 3, $sectionCardY + 2);
            $pdf->SetFont('dejavusans', '', 7.2);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($sectionCardWidth - 6, 4, $card['label'], 0, 1, 'L');
            $pdf->SetX($x + 3);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell($sectionCardWidth - 6, 5, $card['value'], 0, 0, 'L');
        }

        $pdf->SetY(60);
        multi_draw_section_title($pdf, 'Structure du point chantier > zone > debourse > ligne > detail');

        foreach (($section['hierarchy'] ?? []) as $zoneName => $zoneDebourses) {
            if ($pdf->GetY() > 172) {
                $pdf->AddPage();
                multi_draw_chantier_banner($pdf, (string)$section['chantier_label'], (string)$section['chantier_precision']);
                $pdf->SetY(40);
                multi_draw_section_title($pdf, 'Structure du point chantier > zone > debourse > ligne > detail');
            }

            $pdf->SetFillColor(3, 105, 161);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->MultiCell(0, 8, 'Zone: ' . multi_normalize_text((string)$zoneName, 'Hors zone'), 0, 'L', true, 1, null, null, true, 0, false, true, 8, 'M');
            $pdf->Ln(1);

            foreach ($zoneDebourses as $debourseData) {
                if ($pdf->GetY() > 178) {
                    $pdf->AddPage();
                    multi_draw_chantier_banner($pdf, (string)$section['chantier_label'], (string)$section['chantier_precision']);
                    $pdf->SetY(40);
                    multi_draw_section_title($pdf, 'Structure du point chantier > zone > debourse > ligne > detail');
                    $pdf->SetFillColor(3, 105, 161);
                    $pdf->SetTextColor(255, 255, 255);
                    $pdf->SetFont('dejavusans', 'B', 10);
                    $pdf->MultiCell(0, 8, 'Zone: ' . multi_normalize_text((string)$zoneName, 'Hors zone'), 0, 'L', true, 1, null, null, true, 0, false, true, 8, 'M');
                    $pdf->Ln(1);
                }

                $pdf->SetFillColor(241, 245, 249);
                $pdf->SetTextColor(15, 23, 42);
                $pdf->SetFont('dejavusans', 'B', 8.6);
                $pdf->MultiCell(0, 7, multi_normalize_text((string)($debourseData['debourse_label'] ?? 'Debourse'), 'Debourse'), 1, 'L', true, 1, null, null, true, 0, false, true, 7, 'M');
                multi_draw_structure_header($pdf);

                $pdf->SetFont('dejavusans', '', 7.0);
                foreach (($debourseData['items'] ?? []) as $item) {
                    $cells = [
                        ['width' => 50, 'text' => (string)($item['line_label'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                        ['width' => 74, 'text' => (string)($item['detail_ligne'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                        ['width' => 18, 'text' => multi_format_percent($item['progress'] ?? 0), 'align' => 'C', 'fallback' => '0,0%'],
                        ['width' => 28, 'text' => (string)($item['status'] ?? ''), 'align' => 'C', 'fallback' => '-'],
                        ['width' => 24, 'text' => (string)($item['service'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                        ['width' => 30, 'text' => (string)($item['pilotes'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                        ['width' => 25, 'text' => (string)($item['updated_by'] ?? ''), 'align' => 'L', 'fallback' => '-'],
                        ['width' => 24, 'text' => multi_format_date(($item['updated_at'] ?? '') !== '' ? (string)$item['updated_at'] : null, true), 'align' => 'C', 'fallback' => '-'],
                    ];
                    $rowHeight = multi_measure_row_height($pdf, $cells, 7.0);

                    if (($pdf->GetY() + $rowHeight) > 190) {
                        $pdf->AddPage();
                        multi_draw_chantier_banner($pdf, (string)$section['chantier_label'], (string)$section['chantier_precision']);
                        $pdf->SetY(40);
                        multi_draw_section_title($pdf, 'Structure du point chantier > zone > debourse > ligne > detail');
                        $pdf->SetFillColor(3, 105, 161);
                        $pdf->SetTextColor(255, 255, 255);
                        $pdf->SetFont('dejavusans', 'B', 10);
                        $pdf->MultiCell(0, 8, 'Zone: ' . multi_normalize_text((string)$zoneName, 'Hors zone'), 0, 'L', true, 1, null, null, true, 0, false, true, 8, 'M');
                        $pdf->Ln(1);
                        $pdf->SetFillColor(241, 245, 249);
                        $pdf->SetTextColor(15, 23, 42);
                        $pdf->SetFont('dejavusans', 'B', 8.6);
                        $pdf->MultiCell(0, 7, multi_normalize_text((string)($debourseData['debourse_label'] ?? 'Debourse'), 'Debourse'), 1, 'L', true, 1, null, null, true, 0, false, true, 7, 'M');
                        multi_draw_structure_header($pdf);
                        $pdf->SetFont('dejavusans', '', 7.0);
                        $rowHeight = multi_measure_row_height($pdf, $cells, 7.0);
                    }

                    multi_draw_table_row($pdf, $cells, $rowHeight);
                }

                $pdf->Ln(2);
            }
        }

        if ($pdf->GetY() > 140) {
            $pdf->AddPage();
            multi_draw_chantier_banner($pdf, (string)$section['chantier_label'], (string)$section['chantier_precision']);
            $pdf->SetY(40);
        }

        multi_draw_section_title($pdf, 'Commentaires, blocages et actions du chantier');
        multi_draw_detail_header($pdf);
        $pdf->SetFont('dejavusans', '', 7.0);

        foreach (($section['history'] ?? []) as $historyRow) {
            $taskMeta = ($section['task_lookup'] ?? [])[(int)($historyRow['ligne_debourse_id'] ?? 0)] ?? null;
            $detailParts = [];
            if (trim((string)($historyRow['detail_ligne_debourse'] ?? '')) !== '') {
                $detailParts[] = 'Detail: ' . trim((string)$historyRow['detail_ligne_debourse']);
            }
            if (trim((string)($historyRow['commentaire'] ?? '')) !== '') {
                $detailParts[] = 'Commentaire: ' . trim((string)$historyRow['commentaire']);
            }
            if (trim((string)($historyRow['blocage_detail'] ?? '')) !== '') {
                $detailParts[] = 'Blocage: ' . trim((string)$historyRow['blocage_detail']);
            }
            if (trim((string)($historyRow['prochaine_action'] ?? '')) !== '') {
                $detailParts[] = 'Action: ' . trim((string)$historyRow['prochaine_action']);
            }

            $cells = [
                ['width' => 22, 'text' => (string)($taskMeta['zone'] ?? 'Hors zone'), 'align' => 'L', 'fallback' => 'Hors zone'],
                ['width' => 38, 'text' => (string)($taskMeta['debourse_label'] ?? '-'), 'align' => 'L', 'fallback' => '-'],
                ['width' => 36, 'text' => (string)($taskMeta['line_label'] ?? '-'), 'align' => 'L', 'fallback' => '-'],
                ['width' => 24, 'text' => multi_status_meta($historyRow['statut_reel'] ?? null)['label'] ?? 'Non defini', 'align' => 'C', 'fallback' => '-'],
                ['width' => 24, 'text' => (string)($historyRow['updated_by'] ?? 'Utilisateur'), 'align' => 'L', 'fallback' => 'Utilisateur'],
                ['width' => 129, 'text' => !empty($detailParts) ? implode("\n", $detailParts) : 'Sans commentaire complementaire', 'align' => 'L', 'fallback' => 'Sans commentaire complementaire'],
            ];
            $rowHeight = multi_measure_row_height($pdf, $cells, 7.0);

            if (($pdf->GetY() + $rowHeight) > 190) {
                $pdf->AddPage();
                multi_draw_chantier_banner($pdf, (string)$section['chantier_label'], (string)$section['chantier_precision']);
                $pdf->SetY(40);
                multi_draw_section_title($pdf, 'Commentaires, blocages et actions du chantier');
                multi_draw_detail_header($pdf);
                $pdf->SetFont('dejavusans', '', 7.0);
                $rowHeight = multi_measure_row_height($pdf, $cells, 7.0);
            }

            multi_draw_table_row($pdf, $cells, $rowHeight);
        }
    }

    $fileName = 'reunion_multi_chantiers_' . str_replace('-', '_', $dateSuivi) . '.pdf';
    \AuditTrail::logFileEvent('export', [
        'module' => 'planning',
        'action' => 'reunion_multi_chantiers_export',
        'entity_type' => 'reunion_multi_chantiers',
        'entity_label' => 'Réunion multi-chantiers',
        'format' => 'pdf',
        'details' => [
            'date_suivi' => $dateSuivi,
            'chantier_count' => is_countable($sections ?? null) ? count($sections) : null,
            'points_count' => $globalStats['points'] ?? null,
        ],
    ], $pdo);
    $pdf->Output($fileName, 'I');
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur export PDF reunion multi-chantiers: ' . $e->getMessage();
}
