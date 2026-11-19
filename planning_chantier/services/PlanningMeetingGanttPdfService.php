<?php
declare(strict_types=1);

final class PlanningMeetingGanttPdfService
{
    public function __construct(private PDO $pdo) {}

    public function output(int $preparationId, int $lineId, int $sectionId, int $debourseId=0, int $pointId=0, bool $all=false, int $chantierId=0): void
    {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        if (!class_exists('TCPDF')) throw new RuntimeException('Le moteur PDF est indisponible.');

        if($all&&$chantierId>0) [$title,$tasks,$fallbackStart,$fallbackEnd]=$this->wholeProject($preparationId,$chantierId);
        elseif($all&&$lineId>0) [$title,$tasks,$fallbackStart,$fallbackEnd]=$this->financialLine($preparationId,$lineId);
        elseif($all) [$title,$tasks,$fallbackStart,$fallbackEnd]=$this->wholeMeeting($preparationId);
        elseif($pointId>0) [$title,$tasks,$fallbackStart,$fallbackEnd]=$this->singlePoint($preparationId,$pointId);
        elseif($debourseId>0) [$title,$tasks,$fallbackStart,$fallbackEnd]=$this->debourse($preparationId,$debourseId);
        elseif($sectionId>0) [$title,$tasks,$fallbackStart,$fallbackEnd]=$this->customSection($preparationId,$sectionId);
        else [$title,$tasks,$fallbackStart,$fallbackEnd]=$this->financialLine($preparationId,$lineId);
        if (!$tasks) $tasks[] = ['title' => 'Aucune tâche planifiée', 'start' => $fallbackStart, 'end' => $fallbackEnd, 'progress' => 0, 'summary' => false];

        $dates=[];
        foreach ($tasks as $task) {
            if ($task['start']) $dates[]=$task['start'];
            if ($task['end']) $dates[]=$task['end'];
        }
        $start=new DateTimeImmutable($dates ? min($dates) : ($fallbackStart ?: 'today'));
        $end=new DateTimeImmutable($dates ? max($dates) : ($fallbackEnd ?: $start->format('Y-m-d')));
        if ($end < $start) [$start,$end]=[$end,$start];
        $start=$start->modify('-2 days'); $end=$end->modify('+2 days');
        $days=(int)$start->diff($end)->days+1;
        $left=105.0; $dayWidth=3.2;
        $pageWidth=max(297.0,min(900.0,$left+$days*$dayWidth+20));
        $timelineWidth=$pageWidth-20-$left;
        $dayWidth=$timelineWidth/$days;

        $pdf=new TCPDF('L','mm',[$pageWidth,210],true,'UTF-8',false);
        $pdf->SetCreator('FINEA Planning Chantier');
        $pdf->SetTitle('Gantt - '.$title);
        $pdf->SetMargins(10,10,10); $pdf->SetAutoPageBreak(false); $pdf->AddPage();
        $pdf->SetTextColor(15,23,42); $pdf->SetFont('dejavusans','B',12);
        $pdf->Cell($pageWidth-20,7,mb_strtoupper('PLANNING PRÉVISIONNEL — '.$title),0,1,'C');
        $pdf->SetFont('dejavusans','',7); $pdf->SetTextColor(100,116,139);
        $pdf->Cell($pageWidth-20,4,'Période du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y').' · Généré le '.date('d/m/Y H:i'),0,1,'C');
        $top=24.0; $header=13.0; $row=8.0; $usable=174.0; $perPage=max(1,(int)floor(($usable-$header-34)/$row));
        $chunks=$this->paginateGroupedTasks($tasks,$perPage);
        foreach ($chunks as $pageIndex=>$chunk) {
            if ($pageIndex>0) { $pdf->AddPage(); $top=14; }
            $this->drawHeader($pdf,$top,$left,$timelineWidth,$start,$days,$dayWidth);
            $y=$top+$header;
            foreach ($chunk as $i=>$task) {
                if(!isset($taskNumber))$taskNumber=0;if(empty($task['summary']))$taskNumber++;
                $this->drawRow($pdf,$y,$row,$left,$timelineWidth,$start,$end,$dayWidth,empty($task['summary'])?$taskNumber:0,$task);
                $y+=$row;
            }
            $this->drawLegend($pdf,$y+5,$pageWidth-20);
            $pdf->SetY(199); $pdf->SetFont('dejavusans','',6); $pdf->SetTextColor(100,116,139);
            $pdf->Cell($pageWidth-20,4,'BANAMUR · Planning Chantier FINEA                                      Page '.($pageIndex+1).' / '.count($chunks),0,0,'C');
        }
        $name=preg_replace('/[^a-z0-9_-]+/i','_',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$title) ?: 'point_reunion');
        $pdf->Output('gantt_'.$name.'.pdf','I');
    }

    private function customSection(int $preparationId,int $sectionId): array
    {
        $q=$this->pdo->prepare("SELECT title,start_date,end_date,payload_json FROM planning_chantier_meeting_custom_sections WHERE id=:id AND preparation_id=:p AND archived_at IS NULL");
        $q->execute(['id'=>$sectionId,'p'=>$preparationId]); $section=$q->fetch(PDO::FETCH_ASSOC);
        if(!$section) throw new DomainException('Point de réunion introuvable.');
        $payload=json_decode((string)$section['payload_json'],true); $tasks=[];
        foreach(is_array($payload)?$payload:[] as $task) $tasks[]=[
            'title'=>(string)($task['title']??'Tâche'), 'start'=>$task['old_deadline']?:$section['start_date'],
            'end'=>$task['new_deadline']?:($task['old_deadline']?:$section['end_date']),
            'progress'=>(float)($task['progress']??0), 'summary'=>false,
        ];
        return [(string)$section['title'],$tasks,$section['start_date'],$section['end_date']];
    }

    private function financialLine(int $preparationId,int $lineId): array
    {
        $q=$this->pdo->prepare("SELECT COALESCE(o.meeting_label,t.titre,CONCAT('Ligne #',t.source_id)) title
            FROM planning_chantier_tasks t
            LEFT JOIN planning_chantier_meeting_line_overrides o
              ON o.preparation_id=:p AND o.ligne_devis_id=t.source_id
            WHERE t.source_type='ligne_devis' AND t.source_id=:line AND t.statut<>'annule' LIMIT 1");
        $q->execute(['p'=>$preparationId,'line'=>$lineId]); $title=$q->fetchColumn();
        if($title===false) throw new DomainException('Ligne de réunion introuvable.');
        $q=$this->pdo->prepare("SELECT task_title,old_deadline,new_deadline,progress FROM planning_chantier_meeting_action_points WHERE preparation_id=:p AND ligne_devis_id=:line AND archived_at IS NULL AND status<>'cancelled' ORDER BY sort_order,id");
        $q->execute(['p'=>$preparationId,'line'=>$lineId]); $tasks=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $task) $tasks[]=['title'=>$task['task_title'],'start'=>$task['old_deadline'],'end'=>$task['new_deadline']?:$task['old_deadline'],'progress'=>(float)$task['progress'],'summary'=>false];
        return [(string)$title,$tasks,null,null];
    }

    private function singlePoint(int $preparationId,int $pointId): array
    {
        $q=$this->pdo->prepare("SELECT task_title,old_deadline,new_deadline,progress FROM planning_chantier_meeting_action_points WHERE id=:id AND preparation_id=:p AND archived_at IS NULL AND status<>'cancelled'");
        $q->execute(['id'=>$pointId,'p'=>$preparationId]); $task=$q->fetch(PDO::FETCH_ASSOC);
        if(!$task)throw new DomainException('Point de réunion introuvable.');
        return [(string)$task['task_title'],[['title'=>$task['task_title'],'start'=>$task['old_deadline'],'end'=>$task['new_deadline']?:$task['old_deadline'],'progress'=>(float)$task['progress'],'summary'=>false]],$task['old_deadline'],$task['new_deadline']];
    }

    private function debourse(int $preparationId,int $debourseId): array
    {
        $q=$this->pdo->prepare("SELECT titre FROM planning_chantier_tasks
            WHERE source_type='debourse' AND source_id=:id AND statut<>'annule' LIMIT 1");
        $q->execute(['id'=>$debourseId]);$title=$q->fetchColumn();
        if($title===false)throw new DomainException('Catégorie introuvable.');
        $q=$this->pdo->prepare("SELECT task_title,old_deadline,new_deadline,progress FROM planning_chantier_meeting_action_points WHERE preparation_id=:p AND debourse_id=:id AND archived_at IS NULL AND status<>'cancelled' ORDER BY sort_order,id");
        $q->execute(['p'=>$preparationId,'id'=>$debourseId]);
        return [(string)$title,$this->taskRows($q->fetchAll(PDO::FETCH_ASSOC)?:[]),null,null];
    }

    private function wholeMeeting(int $preparationId): array
    {
        $p=$this->pdo->prepare("SELECT titre,meeting_date FROM planning_chantier_meeting_preparations WHERE id=:id");$p->execute(['id'=>$preparationId]);$prep=$p->fetch(PDO::FETCH_ASSOC);
        if(!$prep)throw new DomainException('Réunion introuvable.');
        $q=$this->pdo->prepare("SELECT COALESCE(NULLIF(section_title,''),'Point de réunion') group_title,task_title,old_deadline,new_deadline,progress FROM planning_chantier_meeting_action_points WHERE preparation_id=:p AND archived_at IS NULL AND status<>'cancelled' ORDER BY ligne_devis_id,debourse_id,sort_order,id");
        $q->execute(['p'=>$preparationId]);$tasks=$this->groupedTaskRows($q->fetchAll(PDO::FETCH_ASSOC)?:[]);
        $q=$this->pdo->prepare("SELECT title,start_date,end_date,payload_json FROM planning_chantier_meeting_custom_sections WHERE preparation_id=:p AND archived_at IS NULL ORDER BY sort_order,id");$q->execute(['p'=>$preparationId]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $section){$payload=json_decode((string)$section['payload_json'],true);$sectionRows=[];foreach(is_array($payload)?$payload:[] as $task)$sectionRows[]=['group_title'=>$section['title'],'task_title'=>$task['title']??'Tâche','old_deadline'=>$task['old_deadline']?:$section['start_date'],'new_deadline'=>$task['new_deadline']?:($task['old_deadline']?:$section['end_date']),'progress'=>(float)($task['progress']??0)];$tasks=array_merge($tasks,$this->groupedTaskRows($sectionRows));}
        return ['Gantt général — '.((string)$prep['titre']?:'Réunion'),$tasks,$prep['meeting_date'],$prep['meeting_date']];
    }

    private function wholeProject(int $preparationId,int $chantierId):array
    {
        $p=$this->pdo->prepare("SELECT p.meeting_date,c.titre FROM planning_chantier_meeting_preparations p
            JOIN chantier_banamur c ON c.id=:chantier WHERE p.id=:preparation");
        $p->execute(['chantier'=>$chantierId,'preparation'=>$preparationId]);$context=$p->fetch(PDO::FETCH_ASSOC);
        if(!$context)throw new DomainException('Planning chantier introuvable.');
        $q=$this->pdo->prepare("SELECT COALESCE(NULLIF(section_title,''),'Point de réunion') group_title,task_title,old_deadline,new_deadline,progress
            FROM planning_chantier_meeting_action_points WHERE preparation_id=:preparation AND chantier_id=:chantier
              AND archived_at IS NULL AND status<>'cancelled' ORDER BY ligne_devis_id,debourse_id,sort_order,id");
        $q->execute(['preparation'=>$preparationId,'chantier'=>$chantierId]);$tasks=$this->groupedTaskRows($q->fetchAll(PDO::FETCH_ASSOC)?:[]);
        $q=$this->pdo->prepare("SELECT title,start_date,end_date,payload_json FROM planning_chantier_meeting_custom_sections
            WHERE preparation_id=:preparation AND chantier_id=:chantier AND archived_at IS NULL ORDER BY sort_order,id");
        $q->execute(['preparation'=>$preparationId,'chantier'=>$chantierId]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $section){
            $payload=json_decode((string)$section['payload_json'],true);
            $sectionRows=[];foreach(is_array($payload)?$payload:[] as $task)$sectionRows[]=['group_title'=>$section['title'],'task_title'=>$task['title']??'Tâche','old_deadline'=>$task['old_deadline']?:$section['start_date'],'new_deadline'=>$task['new_deadline']?:($task['old_deadline']?:$section['end_date']),'progress'=>(float)($task['progress']??0)];$tasks=array_merge($tasks,$this->groupedTaskRows($sectionRows));
        }
        return ['Planning chantier — '.$context['titre'],$tasks,$context['meeting_date'],$context['meeting_date']];
    }

    private function taskRows(array $rows):array
    {
        return array_map(static fn(array $task):array=>['title'=>$task['task_title'],'start'=>$task['old_deadline'],'end'=>$task['new_deadline']?:$task['old_deadline'],'progress'=>(float)$task['progress'],'summary'=>false],$rows);
    }

    private function groupedTaskRows(array$rows):array
    {
        $groups=[];$order=[];foreach($rows as$row){$group=trim((string)($row['group_title']??''))?:'Point de réunion';if(!isset($groups[$group])){$groups[$group]=[];$order[]=$group;}$groups[$group][]=$row;}$result=[];foreach($order as$group){$groupRows=$groups[$group];$starts=[];$ends=[];$progress=[];foreach($groupRows as$row){$start=trim((string)($row['old_deadline']??''));$end=trim((string)($row['new_deadline']??$start));if($start!=='')$starts[]=$start;if($end!=='')$ends[]=$end;$progress[]=(float)($row['progress']??0);}$result[]=['title'=>$group,'start'=>$starts?min($starts):null,'end'=>$ends?max($ends):($starts?max($starts):null),'progress'=>$progress?array_sum($progress)/count($progress):0,'summary'=>true,'task_count'=>count($groupRows)];foreach($groupRows as$row)$result[]=['title'=>(string)($row['task_title']??'Tâche'),'start'=>$row['old_deadline']??null,'end'=>($row['new_deadline']??null)?:($row['old_deadline']??null),'progress'=>(float)($row['progress']??0),'summary'=>false];}return$result;
    }

    private function paginateGroupedTasks(array$tasks,int$perPage):array
    {
        $chunks=[];$page=[];$i=0;$total=count($tasks);while($i<$total){$row=$tasks[$i];if(empty($row['summary'])){if(count($page)>=$perPage){$chunks[]=$page;$page=[];}$page[]=$row;$i++;continue;}$summary=$row;$children=[];$i++;while($i<$total&&empty($tasks[$i]['summary']))$children[]=$tasks[$i++];if($page&&$perPage-count($page)<2){$chunks[]=$page;$page=[];}$first=true;while($children){if(count($page)>=$perPage){$chunks[]=$page;$page=[];}$continued=$summary;if(!$first)$continued['title'].=' (suite)';$page[]=$continued;$capacity=$perPage-count($page);$take=array_splice($children,0,$capacity);foreach($take as$child)$page[]=$child;if($children){$chunks[]=$page;$page=[];}$first=false;}if(!$children&&$first)$page[]=$summary;}if($page)$chunks[]=$page;return$chunks?:[[]];
    }

    private function drawHeader(TCPDF $pdf,float $y,float $left,float $timelineWidth,DateTimeImmutable $start,int $days,float $dayWidth): void
    {
        $pdf->SetDrawColor(148,163,184); $pdf->SetFillColor(241,245,249); $pdf->Rect(10,$y,$left,13,'DF'); $pdf->Rect(10+$left,$y,$timelineWidth,13,'DF');
        $pdf->SetXY(10,$y+4); $pdf->SetFont('dejavusans','B',7); $pdf->SetTextColor(15,23,42);
        $pdf->Cell(8,5,'N°',0,0,'C'); $pdf->Cell(67,5,'Nom de la tâche',0,0,'L'); $pdf->Cell(15,5,'Début',0,0,'C'); $pdf->Cell(15,5,'Fin',0,0,'C');
        $cursor=$start; $lastMonth='';
        for($i=0;$i<$days;$i++,$cursor=$cursor->modify('+1 day')){
            $x=10+$left+$i*$dayWidth; $pdf->Line($x,$y+6,$x,$y+13);
            $month=$cursor->format('m/Y');
            if($month!==$lastMonth){ $pdf->SetXY($x,$y+.5); $pdf->SetFont('dejavusans','B',6); $pdf->Cell(min(22,$timelineWidth-$i*$dayWidth),4,$this->monthName($cursor),0,0,'L'); $lastMonth=$month; }
            if($dayWidth>=2.2||$cursor->format('N')==='1'){ $pdf->SetXY($x,$y+7); $pdf->SetFont('dejavusans','',5.5); $pdf->Cell($dayWidth,4,$cursor->format('d'),0,0,'C'); }
        }
    }

    private function drawRow(TCPDF $pdf,float $y,float $h,float $left,float $timelineWidth,DateTimeImmutable $rangeStart,DateTimeImmutable $rangeEnd,float $dayWidth,int $number,array $task): void
    {
        $summary=!empty($task['summary']);$pdf->SetDrawColor(203,213,225);if($summary){$pdf->SetFillColor(226,232,240);$pdf->Rect(10,$y,$left,$h,'DF');$pdf->SetFillColor(241,245,249);$pdf->Rect(10+$left,$y,$timelineWidth,$h,'DF');}else{$pdf->Rect(10,$y,$left,$h);$pdf->Rect(10+$left,$y,$timelineWidth,$h);}
        $pdf->SetTextColor(15,23,42); $pdf->SetFont('dejavusans',$summary?'B':'',6.2); $pdf->SetXY(10,$y+1.4); $pdf->Cell(8,5,$summary?'':(string)$number,0,0,'C');
        $pdf->SetXY($summary?18:21,$y+.7); $title=$summary?(string)$task['title'].' · '.(int)($task['task_count']??0).' tâche(s)':'↳ '.(string)$task['title'];$pdf->MultiCell($summary?67:64,3.1,$title,0,'L');
        $pdf->SetXY(85,$y+1.4); $pdf->Cell(15,5,$this->dateLabel($task['start']),0,0,'C'); $pdf->Cell(15,5,$this->dateLabel($task['end']),0,0,'C');
        if(!$task['start']&&!$task['end']) return;
        $s=new DateTimeImmutable($task['start']?:$task['end']); $e=new DateTimeImmutable($task['end']?:$task['start']); if($e<$s)[$s,$e]=[$e,$s];
        if($s<$rangeStart)$s=$rangeStart; if($e>$rangeEnd)$e=$rangeEnd;
        $offset=(int)$rangeStart->diff($s)->days; $length=(int)$s->diff($e)->days+1; $x=10+$left+$offset*$dayWidth; $w=max(1.2,$length*$dayWidth);
        $pdf->SetFillColor(...($summary?[71,85,105]:[126,181,232])); $pdf->Rect($x,$y+2.4,$w,3.2,'F');
        $done=$w*min(100,max(0,(float)$task['progress']))/100; if($done>0){$pdf->SetFillColor(25,146,157);$pdf->Rect($x,$y+2.4,$done,3.2,'F');}
        $pdf->SetDrawColor(15,23,42); $pdf->Line($x,$y+2,$x,$y+6); $pdf->Line($x+$w,$y+2,$x+$w,$y+6);
    }

    private function drawLegend(TCPDF $pdf,float $y,float $width): void
    {
        $pdf->SetDrawColor(148,163,184); $pdf->Rect(10,$y,$width,27); $pdf->SetFont('dejavusans','',5.8); $pdf->SetTextColor(15,23,42);
        $items=[
            ['Tâche',[126,181,232]],['Fractionnement',[126,181,232]],['Jalon',[71,85,105]],['Récapitulatif',[100,116,139]],['Récapitulatif du projet',[15,23,42]],
            ['Tâche inactive',[226,232,240]],['Jalon inactif',[203,213,225]],['Début uniquement',[25,146,157]],['Fin uniquement',[25,146,157]],['Tâches externes',[203,213,225]],
            ['Durée uniquement',[153,218,223]],['Échéance',[132,204,22]],['Report récapitulatif manuel',[25,146,157]],['Avancement',[25,146,157]],['Progression manuelle',[14,165,233]],
        ];
        $cell=max(48.0,$width/5); foreach($items as $index=>[$label,$color]){
            $col=$index%5; $row=(int)floor($index/5); $x=14+$col*$cell; $iy=$y+4+$row*7;
            $pdf->SetFillColor(...$color); $pdf->Rect($x,$iy+1.2,13,2.5,'F'); $pdf->SetXY($x+15,$iy); $pdf->Cell($cell-16,5,$label);
        }
    }

    private function dateLabel(?string $value): string { return $value ? (new DateTimeImmutable($value))->format('d/m/y') : '—'; }
    private function monthName(DateTimeImmutable $date): string { $m=['01'=>'Janvier','02'=>'Février','03'=>'Mars','04'=>'Avril','05'=>'Mai','06'=>'Juin','07'=>'Juillet','08'=>'Août','09'=>'Septembre','10'=>'Octobre','11'=>'Novembre','12'=>'Décembre']; return $m[$date->format('m')].' '.$date->format('Y'); }
}
