<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/model/CompanyExportBranding.php';


final class PlanningMeetingPdfService
{
    public function __construct(private PlanningMeetingRepository $meetings) {}

    public function renderHtml(int $meetingId): string
    {
        $data = $this->buildData($meetingId);
        $meeting = $data['meeting'];
        $items = $data['items'];
        $actionPoints = $this->groupPointsBySection($data['action_points'],$this->isOffice($meeting));
        $participants = $data['participants'];
        $performance = $data['performance'];
        $changes = $data['changes'];
        $reference = $this->reference($meeting);
        $meetingTitle=trim((string)($meeting['titre']??''))?:'Réunion quotidienne de 12h';
        $documentTitle=$this->documentTitle($meeting);
        $scopeLabel=$this->isOffice($meeting)?'Service':'Chantier';
        $subjectColumn=$this->isOffice($meeting)?'Sujet / service':'Sujet / chantier';
        $moduleLabel=$this->isOffice($meeting)?'Réunions des services FINEA':'Planning Chantier FINEA';

        $operationalRows='';
        $sectionLayout=$this->sectionLayout($actionPoints,$this->isOffice($meeting));
        foreach($actionPoints as $index=>$point){
            $late=$this->isLate($point);
            $sectionCell='';
            if($sectionLayout[$index]!==null)$sectionCell='<td rowspan="'.(int)$sectionLayout[$index]['span'].'" style="font-size:13px;font-weight:bold;line-height:1.35;overflow-wrap:anywhere"><strong>'.$this->h($sectionLayout[$index]['label']).'</strong></td>';
            $operationalRows.='<tr><td class="num">'.($index+1).'</td>'.$sectionCell.
                '<td style="overflow-wrap:anywhere;'.($late?'background:#fff1f2;color:#b91c1c;':'').'"><strong style="font-size:12px;font-weight:800;line-height:1.35">'.nl2br($this->h((string)($point['task_title']??''))).($late?' <span style="font-size:10px">(En retard)</span>':'').'</strong>'.$this->checklistHtml((string)($point['checklist_text']??''),true).'</td>'.
                '<td>'.$this->h((string)($point['pilot_name']??'')).'</td><td>'.$this->h((string)($point['copilot_name']??'')).'</td>'.
                '<td'.($late?' style="color:#b91c1c;font-weight:bold"':'').'>'.$this->formatDate($point['new_deadline']??null,'-').'</td><td>'.number_format((float)($point['progress']??0),0,',',' ').'%</td></tr>';
        }

        $itemRows = '';
        foreach ($items as $index => $item) {
            $itemRows .= '<tr>
                <td class="num">'.($index + 1).'</td>
                <td><strong>'.$this->h((string)$item['sujet']).'</strong><br><span>'.$this->h((string)($item['project_label'] ?? '')).'</span>'.($item['task_title'] ? '<br><small>Tâche : '.$this->h((string)$item['task_title']).'</small>' : '').'</td>
                <td>'.nl2br($this->h((string)($item['constat'] ?? ''))).'</td>
                <td>'.nl2br($this->h((string)($item['decision_text'] ?? ''))).'</td>
                <td>'.$this->formatDate($item['echeance'] ?? null, '-').'</td>
                <td><span class="status">'.$this->h($this->statusLabel((string)($item['statut'] ?? 'ouvert'))).'</span></td>
            </tr>';
        }
        if ($itemRows === '') {
            $itemRows = '<tr><td colspan="6" class="empty">Aucun point détaillé.</td></tr>';
        }

        $participantRows = '';
        foreach ($participants as $p) {
            $participantRows .= '<tr><td>'.$this->h((string)$p['participant_name']).'</td><td>'.$this->h((string)($p['participant_email'] ?? '')).'</td><td>'.$this->h($this->participantStatus((string)($p['attendance_status'] ?? 'present'))).'</td></tr>';
        }
        if ($participantRows === '') {
            $participantRows = '<tr><td colspan="3" class="empty">Aucun participant renseigné.</td></tr>';
        }
        $performanceRows='';
        foreach($performance as $person){
            $rating=round(((float)($person['stars']??0))*2)/2;
            $full=(int)floor($rating);
            $half=($rating-$full)>=.5;
            $stars=str_repeat('★',$full).($half?'◐':'').str_repeat('☆',5-$full-($half?1:0));
            $evaluated=(int)($person['tasks_count']??0)>0&&($person['evaluated']??true)!==false;
            $performanceRows.='<tr><td><strong>'.$this->h((string)($person['participant_name']??'Participant')).'</strong></td><td>'.$this->h($stars).'</td><td>'.($evaluated?number_format((float)($person['score']??0),1,',',' ').'/100':'Non évalué').'</td><td>'.(int)($person['tasks_count']??0).' tâche(s)</td></tr>';
        }
        if($performanceRows==='')$performanceRows='<tr><td colspan="4" class="empty">Aucune performance calculable.</td></tr>';

        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,Arial,sans-serif;color:#0f172a;margin:0;background:#f8fafc;font-size:12px}
            .page{padding:24px;background:#fff;max-width:980px;margin:0 auto}
            .top{border-bottom:1px solid #cbd5e1;padding-bottom:12px;margin-bottom:16px;display:flex;justify-content:space-between;gap:20px}
            .brand{font-size:22px;font-weight:900;color:#1d2b57;letter-spacing:.05em}.sub{color:#64748b;font-size:11px;margin-top:2px}.doc{font-size:26px;font-weight:900;margin-top:14px;color:#0f172a}.ref{text-align:right;color:#475569;font-size:11px}.tag{display:inline-block;background:#fabd02;color:#111827;font-weight:900;border-radius:999px;padding:6px 10px;margin-top:8px}
            .hero{border:1px solid #e2e8f0;border-left:8px solid #1d2b57;border-radius:18px;background:#f8fafc;padding:18px;margin:18px 0}.hero h1{font-size:22px;margin:0 0 6px}.meta{color:#475569;line-height:1.7}.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin:14px 0}.kpi{border:1px solid #e2e8f0;border-radius:14px;background:white;padding:12px}.kpi .label{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:.08em;font-weight:800}.kpi .value{font-size:16px;color:#0f172a;font-weight:900;margin-top:5px}
            .box{border:1px solid #e2e8f0;border-radius:16px;padding:14px;margin:14px 0;background:#fff}h2{font-size:15px;margin:0 0 8px;color:#1d2b57}.muted{color:#64748b}.empty{text-align:center;color:#64748b;padding:20px}
            table{width:100%;border-collapse:collapse;margin-top:8px;background:white}th{background:#1d2b57;color:white;text-align:left;padding:9px;font-size:10px;text-transform:uppercase;letter-spacing:.08em;border:1px solid #1d2b57}td{border:1px solid #e2e8f0;padding:9px;vertical-align:top}.num{width:28px;text-align:center;font-weight:900;color:#ea580c}.status{display:inline-block;border-radius:999px;background:#fef3c7;color:#92400e;padding:3px 8px;font-weight:800}.footer{margin-top:18px;border-top:1px solid #e2e8f0;padding-top:10px;text-align:center;color:#64748b;font-size:10px}
        </style></head><body><div class="page">
            <div class="top"><div><div class="brand">'.$this->h((string)CompanyBranding::get()['company_name']).'</div><div class="sub">Direction exploitation - Document officiel</div></div><div class="ref"><strong>'.$this->h($reference).'</strong><br>Généré le '.$this->h(date('d/m/Y H:i')).'</div></div>
            <div class="hero"><h1>'.$this->h($documentTitle).'</h1><div class="meta">Date : <strong>'.$this->formatDate((string)$meeting['meeting_date']).'</strong> · Statut : <strong>'.$this->h($this->statusLabel((string)($meeting['status'] ?? 'draft'))).'</strong><br>Module : '.$this->h($moduleLabel).'</div><span class="tag">'.$this->h($meetingTitle).'</span></div>
            <div class="kpis"><div class="kpi"><div class="label">Points</div><div class="value">'.count($items).'</div></div><div class="kpi"><div class="label">Participants</div><div class="value">'.count($participants).'</div></div><div class="kpi"><div class="label">'.$scopeLabel.'</div><div class="value">'.$this->h((string)($meeting['project_label'] ?? 'Tous')).'</div></div><div class="kpi"><div class="label">Référence</div><div class="value">'.$this->h($reference).'</div></div></div>
            '.($operationalRows!==''?'<h2>Organisation et planning décidés en réunion</h2><table><thead><tr><th>#</th><th>Section</th><th>Tâche</th><th>Pilote</th><th>Copilote</th><th>Échéance</th><th>AV</th></tr></thead><tbody>'.$operationalRows.'</tbody></table>':'').'
            <h2>Points traités</h2><table><thead><tr><th>#</th><th>'.$subjectColumn.'</th><th>Constat</th><th>Décision / action</th><th>Échéance</th><th>Statut</th></tr></thead><tbody>'.$itemRows.'</tbody></table>
            '.$this->changesHtml($changes).'
            <h2 style="margin-top:16px">Participants</h2><table><thead><tr><th>Nom</th><th>Email</th><th>Présence</th></tr></thead><tbody>'.$participantRows.'</tbody></table>
            <div class="box"><h2>Résumé de réunion</h2><div class="muted">'.nl2br($this->h((string)($meeting['resume'] ?? 'Aucun résumé renseigné.'))).'</div></div>
            <div class="box"><h2>Décisions générales</h2><div class="muted">'.nl2br($this->h((string)($meeting['decisions'] ?? 'Aucune décision générale renseignée.'))).'</div></div>
            <h2 style="margin-top:16px">Évaluation des participants</h2><table><thead><tr><th>Participant</th><th>Note</th><th>Score</th><th>Tâches confiées</th></tr></thead><tbody>'.$performanceRows.'</tbody></table>
            <div class="footer">'.$this->h((string)CompanyBranding::get()['company_name']).' - Document généré par FINEA Planning Chantier</div>
        </div></body></html>';
    }

    public function output(int $meetingId, string $destination = 'I'): void
    {
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        if (class_exists('TCPDF')) {
            $this->ensureTcpdfDocumentClass();
            $meeting=$this->meetings->findIncludingArchived($meetingId);
            $filename='point_reunion_'.$this->safeReference((string)($meeting['report_code']??('CR-'.$meetingId))).'.pdf';
            $this->buildTcpdf($meetingId)->Output($filename, $destination);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        echo $this->renderHtml($meetingId);
    }

    public function savePdfOrHtml(int $meetingId, string $directory): string
    {
        $directory=$this->writableOutputDirectory($directory);
        $meeting=$this->meetings->findIncludingArchived($meetingId);
        if(!$meeting)throw new RuntimeException('Réunion introuvable');
        $base=rtrim($directory,'/\\').'/point_reunion_'.$this->safeReference((string)($meeting['report_code']??('CR-'.$meetingId)));

        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }

        if (class_exists('TCPDF')) {
            $this->ensureTcpdfDocumentClass();
            $path = $base . '.pdf';
            $this->buildTcpdf($meetingId)->Output($path, 'F');
            if(!is_file($path)||filesize($path)===0)throw new RuntimeException('Le compte rendu PDF n’a pas pu être enregistré.');
            @chmod($path,0640);
            return $path;
        }

        $path = $base . '.html';
        if(file_put_contents($path,$this->renderHtml($meetingId))===false)throw new RuntimeException('Le compte rendu HTML n’a pas pu être enregistré.');
        @chmod($path,0640);
        return $path;
    }

    private function writableOutputDirectory(string $preferred):string
    {
        $appRoot=dirname(__DIR__,2);
        $directories=array_values(array_unique([
            rtrim($preferred,'/\\'),
            $appRoot.'/uploads/planning_chantier/generated_reports',
            $appRoot.'/uploads/disbursement_proofs/planning_chantier_generated_reports',
            $appRoot.'/decaissement/uploads/planning_chantier/generated_reports',
        ]));
        foreach($directories as $directory){
            if($directory==='')continue;
            if(!is_dir($directory)){
                $previous=umask(0002);
                $created=@mkdir($directory,0775,true);
                umask($previous);
                if(!$created&&!is_dir($directory))continue;
            }
            @chmod($directory,0775);
            if(is_dir($directory)&&is_writable($directory))return $directory;
        }
        throw new RuntimeException('Impossible de préparer un dossier inscriptible pour le compte rendu PDF.');
    }


    private function ensureTcpdfDocumentClass(): void
    {
        if (!class_exists('TCPDF')) {
            return;
        }

        if (!class_exists('PlanningMeetingTcpdfDocument')) {
            require_once __DIR__ . '/PlanningMeetingTcpdfDocument.php';
        }
    }

    private function buildTcpdf(int $meetingId): TCPDF
    {
        $this->ensureTcpdfDocumentClass();
        $data = $this->buildData($meetingId);
        $meeting = $data['meeting'];
        $items = $data['items'];
        $actionPoints = $this->groupPointsBySection($data['action_points'],$this->isOffice($meeting));
        $participants = $data['participants'];
        $performance = $data['performance'];
        $changes = $data['changes'];
        $reference = $this->reference($meeting);
        $meetingTitle=trim((string)($meeting['titre']??''))?:'Réunion quotidienne de 12h';
        $documentTitle=$this->documentTitle($meeting);
        $isOffice=$this->isOffice($meeting);
        $generatedAt = date('d/m/Y H:i');
        $projectLabel = trim((string)($meeting['project_label'] ?? '')) ?: 'Tous les chantiers';
        $qrPayload = implode("\n", [
            CompanyExportBranding::creator().' - '.$documentTitle,
            'Reference: ' . $reference,
            'Date: ' . $this->formatDate((string)$meeting['meeting_date']),
            'Points: ' . (count($items)+count($actionPoints)),
            'Participants: ' . count($participants),
            'Genere le: ' . $generatedAt,
        ]);

        $pdf = new PlanningMeetingTcpdfDocument('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->companyName = (string)CompanyBranding::get()['company_name'];
        $pdf->documentTitle = $documentTitle;
        $pdf->moduleLabel = $isOffice?'Réunions des services FINEA':'Module Planning Chantier FINEA';
        $pdf->logoPath = (string)CompanyBranding::logoFile();
        $pdf->SetCreator(CompanyExportBranding::creator());
        $pdf->SetAuthor(CompanyExportBranding::creator());
        $pdf->SetTitle($documentTitle);
        $pdf->SetSubject($isOffice?'Réunion du service '.$projectLabel:'Réunion quotidienne de chantier');
        $pdf->SetMargins(12, 30, 12);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->AddPage();

        $pageWidth = $pdf->getPageWidth() - 24;
        $heroY = 33;
        $heroHeight = 32;
        $heroWidth = $pageWidth - 37;
        $qrBoxX = 12 + $heroWidth + 5;
        $qrBoxWidth = 32;

        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect(12, $heroY, $heroWidth, $heroHeight, 4, '1111', 'DF');
        $pdf->SetFillColor(29, 43, 87);
        $pdf->RoundedRect(12, $heroY, 4, $heroHeight, 4, '1101', 'F');
        $pdf->SetXY(19, 37);
        $pdf->SetFont('dejavusans', 'B', 15.2);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell($heroWidth - 16, 5.8, $documentTitle, 0, 1, 'L');
        $pdf->SetFont('dejavusans', '', 9.1);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->SetX(19);
        $pdf->MultiCell($heroWidth - 18, 4.7, $meetingTitle.' - suivi des avancements, blocages, décisions et actions correctives.', 0, 'L', false, 1);
        $pdf->SetX(19);
        $pdf->SetFont('dejavusans', '', 8.2);
        $pdf->Cell($heroWidth - 18, 4.3, 'Référence : ' . $reference . '   |   Date : ' . $this->formatDate((string)$meeting['meeting_date']), 0, 1, 'L');

        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($qrBoxX, $heroY, $qrBoxWidth, $heroHeight, 4, '1111', 'DF');
        $pdf->SetXY($qrBoxX + 3, $heroY + 2.4);
        $pdf->SetFont('dejavusans', 'B', 7.2);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell($qrBoxWidth - 6, 4, 'QR POINT', 0, 1, 'C');
        $pdf->write2DBarcode($qrPayload, 'QRCODE,H', $qrBoxX + 6.1, $heroY + 7.2, 19, 19, [], 'N');

        $kpiY = 70;
        $gap = 4;
        $boxWidth = ($pageWidth - ($gap * 3)) / 4;
        $cards = [
            ['label' => 'Points traités', 'value' => (string)(count($items)+count($actionPoints))],
            ['label' => 'Participants', 'value' => (string)count($participants)],
            ['label' => $isOffice?'Service':'Chantier', 'value' => $projectLabel],
            ['label' => 'Généré le', 'value' => $generatedAt],
        ];
        foreach ($cards as $index => $card) {
            $x = 12 + (($boxWidth + $gap) * $index);
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->RoundedRect($x, $kpiY, $boxWidth, 16, 3, '1111', 'DF');
            $pdf->SetXY($x + 3.2, $kpiY + 2.2);
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($boxWidth - 6.4, 3.8, $card['label'], 0, 2, 'L');
            $pdf->SetX($x + 3.2);
            $pdf->SetFont('dejavusans', 'B', 8.3);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->MultiCell($boxWidth - 6.4, 4.2, $card['value'], 0, 'L', false, 0);
        }

        $pdf->SetY(92);

        if($actionPoints){
            $pdf->Ln(2);
            $pdf->SetFont('dejavusans','B',11);
            $pdf->SetTextColor(15,23,42);
            $pdf->Cell(0,6,'Tableau de suivi opérationnel',0,1,'L');
            $officeHtml='<table cellpadding="4" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:6.7px;"><thead><tr>';
            $headers=[['Section','18%'],['Tâche','30%'],['Pilote','11%'],['Copilote','11%'],['A. délai','11%'],['N. délai','11%'],['AV','8%']];
            foreach($headers as [$header,$width])$officeHtml.='<th style="width:'.$width.';background-color:#1d2b57;color:#fff;border:1px solid #1d2b57;font-weight:bold;text-align:center;">'.$this->h($header).'</th>';
            $officeHtml.='</tr></thead><tbody>';
            $sectionLayout=$this->sectionLayout($actionPoints,$isOffice);
            foreach($actionPoints as $i=>$point){
                $bg=$i%2===0?'#ffffff':'#f8fafc';
                $late=$this->isLate($point);
                $sectionLabel='';
                if($sectionLayout[$i]!==null){
                    $sectionLabel='<b>'.$this->h($sectionLayout[$i]['label']).'</b>';
                }
                // Ne jamais utiliser rowspan dans un tableau TCPDF multipage : le
                // moteur recopie la cellule au saut de page et superpose son texte
                // aux lignes suivantes. Une cellule présente sur chaque ligne,
                // laissée vide après le premier libellé, garde la fusion visuelle
                // tout en garantissant une pagination stable.
                $sectionCell='<td style="background-color:#ffffff;border:1px solid #e2e8f0;width:18%;vertical-align:middle;font-size:7.8px;font-weight:bold;line-height:1.35">'.$sectionLabel.'</td>';
                $officeHtml.='<tr nobr="true">'.
                    $sectionCell.
                    '<td style="background-color:'.($late?'#fff1f2':$bg).';border:1px solid '.($late?'#fecdd3':'#e2e8f0').';width:30%;line-height:1.35;color:'.($late?'#b91c1c':'#0f172a').'"><b style="font-size:7.4px">'.nl2br($this->h((string)$point['task_title'])).($late?' (EN RETARD)':'').'</b>'.$this->checklistHtml((string)($point['checklist_text']??''),false).'</td>'.
                    '<td style="background-color:'.$bg.';border:1px solid #e2e8f0;width:11%">'.$this->h((string)($point['pilot_name']??'')).'</td>'.
                    '<td style="background-color:'.$bg.';border:1px solid #e2e8f0;width:11%">'.$this->h((string)($point['copilot_name']??'')).'</td>'.
                    '<td style="background-color:'.$bg.';border:1px solid #e2e8f0;width:11%">'.$this->formatDate($point['old_deadline']??null,'-').'</td>'.
                    '<td style="background-color:'.($late?'#fff1f2':$bg).';border:1px solid '.($late?'#fecdd3':'#e2e8f0').';width:11%;color:'.($late?'#b91c1c':'#0f172a').';font-weight:'.($late?'bold':'normal').'">'.$this->formatDate($point['new_deadline']??null,'-').'</td>'.
                    '<td style="background-color:'.$bg.';border:1px solid #e2e8f0;width:8%;font-weight:bold;color:#166534">'.number_format((float)$point['progress'],0,',',' ').'%</td></tr>';
            }
            $officeHtml.='</tbody></table>';
            $pdf->writeHTML($officeHtml,true,false,true,false,'');
        }

        if($items||!$actionPoints){
            $pdf->Ln(2);
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(0, 6, 'Points traités', 0, 1, 'L');

            $html = '<table cellpadding="5" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:7.2px;">';
        $html .= '<thead><tr>';
        foreach (['#',$isOffice?'Sujet / service':'Sujet / chantier','Constat','Décision / action','Échéance','Statut'] as $header) {
            $html .= '<th style="background-color:#1d2b57;color:#ffffff;border:1px solid #1d2b57;font-weight:bold;">' . $this->h($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        if ($items) {
            foreach ($items as $i => $item) {
                $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
                $subject = '<b>' . $this->h((string)$item['sujet']) . '</b><br><span style="color:#64748b;">' . $this->h((string)($item['project_label'] ?? '')) . '</span>';
                if (!empty($item['task_title'])) {
                    $subject .= '<br><span style="color:#64748b;">Tâche : ' . $this->h((string)$item['task_title']) . '</span>';
                }
                $html .= '<tr>';
                $html .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#ea580c;font-weight:bold;text-align:center;width:5%;">' . ($i + 1) . '</td>';
                $html .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#0f172a;width:24%;">' . $subject . '</td>';
                $html .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#0f172a;width:21%;">' . nl2br($this->h((string)($item['constat'] ?? ''))) . '</td>';
                $html .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#0f172a;width:24%;">' . nl2br($this->h((string)($item['decision_text'] ?? ''))) . '</td>';
                $html .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#0f172a;width:12%;">' . $this->formatDate($item['echeance'] ?? null, '-') . '</td>';
                $html .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#0f172a;width:14%;">' . $this->h($this->statusLabel((string)($item['statut'] ?? 'ouvert'))) . '</td>';
                $html .= '</tr>';
            }
        } else {
            $html .= '<tr><td colspan="6" style="border:1px solid #e2e8f0;background-color:#f8fafc;color:#64748b;text-align:center;">Aucun point détaillé.</td></tr>';
        }
        $html .= '</tbody></table>';
            $pdf->writeHTML($html, true, false, true, false, '');
        }

        if(!empty($changes['removed'])||!empty($changes['renamed'])){
            $pdf->Ln(3);
            $pdf->SetFont('dejavusans','B',9.5);
            $pdf->SetTextColor(71,85,105);
            $pdf->Cell(0,5,'Évolutions depuis la réunion précédente',0,1,'L');
            $pdf->SetFont('dejavusans','',7.2);
            $pdf->SetTextColor(100,116,139);
            $pdf->Cell(0,4,'Information de suivi · comparaison avec le compte rendu du '.$this->formatDate((string)(($changes['previous']??[])['meeting_date']??''),'—').'.',0,1,'L');
            $history='<table cellpadding="3" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:6.8px;">';
            foreach($changes['renamed']??[] as $change)$history.='<tr><td style="width:18%;background-color:#f8fafc;border:1px solid #e2e8f0;color:#64748b;">Libellé modifié</td><td style="background-color:#ffffff;border:1px solid #e2e8f0;"><b>'.$this->h((string)$change['section_title']).'</b> · <span style="color:#94a3b8;text-decoration:line-through;">'.$this->h((string)$change['old_title']).'</span> → '.$this->h((string)$change['new_title']).'</td></tr>';
            foreach($changes['removed']??[] as $change)$history.='<tr><td style="width:18%;background-color:#f8fafc;border:1px solid #e2e8f0;color:#64748b;">Point supprimé</td><td style="background-color:#ffffff;border:1px solid #e2e8f0;"><b>'.$this->h((string)$change['section_title']).'</b> · '.$this->h((string)$change['task_title']).'</td></tr>';
            $pdf->writeHTML($history.'</table>',true,false,true,false,'');
        }

        $pdf->Ln(2);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 6, 'Participants', 0, 1, 'L');
        $pHtml = '<table cellpadding="5" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:7.8px;"><thead><tr>';
        foreach (['Nom','Email','Présence','Notification'] as $header) {
            $pHtml .= '<th style="background-color:#1d2b57;color:#ffffff;border:1px solid #1d2b57;font-weight:bold;">' . $this->h($header) . '</th>';
        }
        $pHtml .= '</tr></thead><tbody>';
        if ($participants) {
            foreach ($participants as $i => $p) {
                $bg = $i % 2 === 0 ? '#ffffff' : '#f8fafc';
                $pHtml .= '<tr>';
                $pHtml .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#0f172a;">' . $this->h((string)$p['participant_name']) . '</td>';
                $pHtml .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#0f172a;">' . $this->h((string)($p['participant_email'] ?? '')) . '</td>';
                $pHtml .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#0f172a;">' . $this->h($this->participantStatus((string)($p['attendance_status'] ?? 'present'))) . '</td>';
                $pHtml .= '<td style="background-color:' . $bg . ';border:1px solid #e2e8f0;color:#0f172a;">' . (!empty($p['notify_email']) ? 'Oui' : 'Non') . '</td>';
                $pHtml .= '</tr>';
            }
        } else {
            $pHtml .= '<tr><td colspan="4" style="border:1px solid #e2e8f0;background-color:#f8fafc;color:#64748b;text-align:center;">Aucun participant renseigné.</td></tr>';
        }
        $pHtml .= '</tbody></table>';
        $pdf->writeHTML($pHtml, true, false, true, false, '');
        if(($pdf->getPageHeight()-$pdf->GetY())<55)$pdf->AddPage();
        $pdf->Ln(3);
        $this->sectionBox($pdf, 'Résumé de réunion', trim((string)($meeting['resume'] ?? '')) ?: 'Aucun résumé renseigné.');
        $this->sectionBox($pdf, 'Décisions générales', trim((string)($meeting['decisions'] ?? '')) ?: 'Aucune décision générale renseignée.');
        $this->performanceSection($pdf,$performance);

        return $pdf;
    }

    private function sectionBox(TCPDF $pdf, string $title, string $content): void
    {
        $x = 12;
        $w = $pdf->getPageWidth() - 24;
        $y = $pdf->GetY();
        $pdf->SetFont('dejavusans', '', 8.2);
        $h = max(18, 13 + $pdf->getStringHeight($w - 8, $content, false, true, '', 1));
        if($y+$h>$pdf->getPageHeight()-18){$pdf->AddPage();$y=$pdf->GetY();}
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($x, $y, $w, $h, 3, '1111', 'DF');
        $pdf->SetXY($x + 4, $y + 3);
        $pdf->SetFont('dejavusans', 'B', 9.5);
        $pdf->SetTextColor(29, 43, 87);
        $pdf->Cell($w - 8, 4.5, $title, 0, 1, 'L');
        $pdf->SetX($x + 4);
        $pdf->SetFont('dejavusans', '', 8.2);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->MultiCell($w - 8, 4.2, $content, 0, 'L', false, 1);
        $pdf->SetY($y + $h + 4);
    }

    private function isLate(array $point): bool
    {
        $deadline=trim((string)($point['new_deadline']??''));
        return $deadline!==''&&$deadline<date('Y-m-d')&&(float)($point['progress']??0)<100;
    }

    private function buildData(int $meetingId): array
    {
        $meeting = $this->meetings->findIncludingArchived($meetingId);
        if (!$meeting) {
            throw new RuntimeException('Réunion introuvable');
        }
        return [
            'meeting' => $meeting,
            'items' => $this->meetings->items($meetingId),
            'participants' => $this->meetings->participants($meetingId),
            'action_points' => $this->meetings->actionPoints($meetingId),
            'performance' => $this->meetings->performanceForMeeting($meetingId),
            'changes' => $this->meetings->actionPointChangesFromPrevious($meetingId),
        ];
    }

    private function changesHtml(array $changes): string
    {
        if(empty($changes['removed'])&&empty($changes['renamed']))return '';
        $previous=$changes['previous']??[];
        $html='<div class="box changes"><h2>Évolutions depuis la réunion précédente</h2><div class="muted">Comparaison avec le compte rendu du '.$this->formatDate((string)($previous['meeting_date']??''),'—').'.</div><ul>';
        foreach($changes['renamed']??[] as $change)$html.='<li><b>Libellé modifié</b> · '.$this->h((string)$change['section_title']).' : <s>'.$this->h((string)$change['old_title']).'</s> → <strong>'.$this->h((string)$change['new_title']).'</strong></li>';
        foreach($changes['removed']??[] as $change)$html.='<li><b>Point supprimé</b> · '.$this->h((string)$change['section_title']).' : '.$this->h((string)$change['task_title']).'</li>';
        return $html.'</ul></div>';
    }

    private function sectionLayout(array $points,bool $isOffice):array
    {
        $layout=[];$count=count($points);
        for($index=0;$index<$count;){
            $section=trim((string)($points[$index]['meeting_section_title']??''))?:'Point';
            $key=($isOffice?'office':(string)($points[$index]['chantier_id']??0)).'|'.mb_strtolower($section);
            $end=$index+1;
            while($end<$count){
                $next=trim((string)($points[$end]['meeting_section_title']??''))?:'Point';
                $nextKey=($isOffice?'office':(string)($points[$end]['chantier_id']??0)).'|'.mb_strtolower($next);
                if($nextKey!==$key)break;
                $end++;
            }
            $layout[$index]=['span'=>$end-$index,'label'=>$isOffice?$section:'Point : '.$section];
            for($hidden=$index+1;$hidden<$end;$hidden++)$layout[$hidden]=null;
            $index=$end;
        }
        return $layout;
    }

    private function groupPointsBySection(array $points,bool $isOffice):array
    {
        $groups=[];$order=[];
        foreach($points as $point){
            $section=trim((string)($point['meeting_section_title']??''))?:'Point';
            $key=($isOffice?'office':(string)($point['chantier_id']??0)).'|'.mb_strtolower($section);
            if(!isset($groups[$key]))$order[]=$key;
            $groups[$key][]=$point;
        }
        $grouped=[];
        foreach($order as $key)foreach($groups[$key] as $point)$grouped[]=$point;
        return $grouped;
    }

    private function checklistHtml(string $text,bool $fontAwesome):string
    {
        if(trim($text)==='')return '';
        $html='<div style="margin-top:4px;font-size:'.($fontAwesome?'10px':'5.7px').';font-weight:normal;line-height:1.3">';
        foreach(preg_split('/\R/',$text)?:[] as $line){
            if(trim($line)==='')continue;
            $done=str_starts_with(trim($line),'[x]');
            $label=preg_replace('/^\s*\[(?:x| )\]\s*/i','',$line);
            $color=$done?'#15803d':'#b45309';
            $icon=$fontAwesome
                ?'<i class="fas '.($done?'fa-circle-check':'fa-clock').'" aria-hidden="true"></i>'
                :($done?'&#10004;':'&#9679;');
            $html.='<div style="color:'.$color.';margin-top:2px">'.$icon.'&nbsp; '.$this->h((string)$label).'</div>';
        }
        return $html.'</div>';
    }

    private function performanceSection(TCPDF $pdf,array $performance):void
    {
        // Une page dédiée évite que les SetXY des cartes d'évaluation soient
        // répartis par TCPDF sur plusieurs pages lorsque la page précédente
        // se termine à quelques millimètres de la marge basse.
        $pdf->AddPage();
        $pdf->SetY(33);
        $pdf->SetFont('dejavusans','B',11);
        $pdf->SetTextColor(29,43,87);
        $pdf->Cell(0,6,'Évaluation des exécutants',0,1,'L');
        $pdf->SetFont('dejavusans','',7.2);
        $pdf->SetTextColor(100,116,139);
        $pdf->MultiCell(0,4,'Notation sur 5 des pilotes et copilotes effectivement affectés aux tâches de cette réunion, selon l’avancement et le respect des échéances.',0,'L',false,1);
        if(!$performance){
            $pdf->SetFont('dejavusans','I',8);
            $pdf->Cell(0,8,'Aucun pilote ou copilote n’a encore été affecté à une tâche de cette réunion.',0,1,'L');
            return;
        }
        foreach($performance as $person){
            if(($pdf->getPageHeight()-$pdf->GetY())<20)$pdf->AddPage();
            $y=$pdf->GetY();
            $pdf->SetFillColor(248,250,252);
            $pdf->SetDrawColor(226,232,240);
            $pdf->RoundedRect(12,$y,186,13,2,'1111','DF');
            $pdf->SetXY(16,$y+2.1);
            $pdf->SetFont('dejavusans','B',8.2);
            $pdf->SetTextColor(15,23,42);
            $pdf->Cell(58,4,(string)($person['participant_name']??'Participant'),0,0,'L');
            $rating=max(0,min(5,round(((float)($person['stars']??0))*2)/2));
            $this->drawStars($pdf,82,$y+6.5,$rating);
            $pdf->SetXY(125,$y+2);
            $pdf->SetFont('dejavusans','B',8);
            $pdf->SetTextColor(29,43,87);
            $evaluated=(int)($person['tasks_count']??0)>0&&($person['evaluated']??true)!==false;
            $pdf->Cell(30,4,$evaluated?number_format((float)($person['score']??0),1,',',' ').'/100':'Non évalué',0,0,'R');
            $pdf->SetXY(159,$y+2);
            $pdf->SetFont('dejavusans','',7.2);
            $pdf->SetTextColor(100,116,139);
            $pdf->Cell(35,4,(int)($person['tasks_count']??0).' tâche(s)',0,0,'R');
            $pdf->SetXY(16,$y+7);
            $pdf->SetFont('dejavusans','',6.8);
            $pdf->SetTextColor(100,116,139);
            $pdf->Cell(178,3.4,(int)($person['done_count']??0).' terminée(s) · '.(int)($person['in_progress_count']??0).' en cours · '.(int)($person['late_count']??0).' en retard',0,0,'L');
            $pdf->SetY($y+15);
        }
    }

    private function drawStars(TCPDF $pdf,float $x,float $y,float $rating):void
    {
        $radius=2.35;
        for($index=0;$index<5;$index++){
            $cx=$x+($index*7);
            $fraction=max(0,min(1,$rating-$index));
            if($fraction>0){
                $pdf->StartTransform();
                $pdf->Rect($cx-$radius,$y-$radius,($radius*2)*$fraction,$radius*2,'CNZ');
                $pdf->StarPolygon($cx,$y,$radius,5,2,180,false,'F',[],[250,189,2]);
                $pdf->StopTransform();
            }
            $pdf->StarPolygon($cx,$y,$radius,5,2,180,false,'D',['width'=>.25,'color'=>[148,163,184]],[]);
        }
    }

    private function reference(array $meeting): string
    {
        $stored=trim((string)($meeting['report_code']??''));
        if($stored!=='')return $stored;
        $id = (int)($meeting['id'] ?? 0);
        $date = preg_replace('/\D+/', '', (string)($meeting['meeting_date'] ?? date('Y-m-d')));
        return 'PLC-' . ($date !== '' ? $date : date('Ymd')) . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    }

    private function safeReference(string $value):string
    {
        $safe=preg_replace('/[^A-Za-z0-9_-]+/','-',trim($value))?:'compte-rendu';
        return trim($safe,'-');
    }

    private function isOffice(array $meeting):bool
    {
        return (int)($meeting['office_service_line_id']??0)>0;
    }

    private function documentTitle(array $meeting):string
    {
        if(!$this->isOffice($meeting))return 'Point de réunion chantier';
        $service=trim((string)($meeting['project_label']??''));
        $subject=preg_replace('/^service\s+/iu','',$service)?:$service;
        return 'Point de réunion '.mb_strtolower($subject!==''?$subject:'service','UTF-8');
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function formatDate(?string $date, string $fallback = 'Non renseigné'): string
    {
        if (!$date) {
            return $fallback;
        }
        $ts = strtotime($date);
        return $ts ? date('d/m/Y', $ts) : $fallback;
    }

    private function statusLabel(string $status): string
    {
        return [
            'draft' => 'Brouillon',
            'closed' => 'Clôturée',
            'ouvert' => 'Ouvert',
            'en_cours' => 'En cours',
            'traite' => 'Traité',
            'a_suivre' => 'À suivre',
        ][$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    private function participantStatus(string $status): string
    {
        return [
            'present' => 'Présent',
            'absent' => 'Absent',
            'invite' => 'Invité',
            'excuse' => 'Excusé',
        ][$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}
