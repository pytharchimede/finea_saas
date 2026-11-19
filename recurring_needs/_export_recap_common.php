<?php
require_once __DIR__.'/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/repositories/RecurringNeedRepository.php';
require_once __DIR__.'/_recurring_needs_common.php';

$pdo=\Database::getConnection();
$userId=(int)($_SESSION['user_id']??0);
$canSeeAll=!empty($_SESSION['permissions']['voir_tous_besoins_recurrents'])||!empty($_SESSION['permissions']['acces_admin']);
$filters=[
    'user_id'=>$userId,'all'=>$canSeeAll,
    'archive_mode'=>!empty($_GET['archives']),
    'q'=>$_GET['q']??'','q_mode'=>$_GET['q_mode']??'include','requester'=>$_GET['requester']??[],'requester_mode'=>$_GET['requester_mode']??'include','beneficiary'=>$_GET['beneficiary']??[],'beneficiary_mode'=>$_GET['beneficiary_mode']??'include',
    'assignment'=>$_GET['assignment']??[],'assignment_mode'=>$_GET['assignment_mode']??'include','status'=>$_GET['status']??'','frequency_type'=>$_GET['frequency_type']??'',
    'priority'=>$_GET['priority']??'','due_from'=>$_GET['due_from']??'','due_to'=>$_GET['due_to']??'',
];
$listFilters=$filters;unset($listFilters['due_from'],$listFilters['due_to']);
$recurringExportTemplates=(new RecurringNeedRepository($pdo))->list($listFilters);
$windowStart=trim((string)($filters['due_from']??''))?:date('Y-01-01');
$windowEnd=trim((string)($filters['due_to']??''))?:date('Y-12-31');
if($windowEnd<$windowStart)[$windowStart,$windowEnd]=[$windowEnd,$windowStart];
$generationMap=[];$templateIds=array_values(array_filter(array_map(static fn(array$row):int=>(int)($row['id']??0),$recurringExportTemplates)));
if($templateIds){$holders=implode(',',array_fill(0,count($templateIds),'?'));$stmt=$pdo->prepare("SELECT recurring_need_id,scheduled_due_date,generation_date,generated_feb_id,status FROM finea_recurring_need_generations WHERE recurring_need_id IN ($holders) AND scheduled_due_date BETWEEN ? AND ? ORDER BY scheduled_due_date");$stmt->execute([...$templateIds,$windowStart,$windowEnd]);foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[]as$generation)$generationMap[(int)$generation['recurring_need_id']][(string)$generation['scheduled_due_date']]=$generation;}
$advance=static function(DateTimeImmutable$date,array$row):DateTimeImmutable{$interval=max(1,(int)($row['frequency_interval']??1));return match((string)($row['frequency_type']??'monthly')){'daily'=>$date->modify("+$interval day"),'weekly'=>$date->modify("+$interval week"),'quarterly'=>$date->modify('+'.(3*$interval).' month'),'semesterly'=>$date->modify('+'.(6*$interval).' month'),'yearly'=>$date->modify("+$interval year"),default=>$date->modify("+$interval month")};};
$recurringExportRows=[];
foreach($recurringExportTemplates as$template){$start=(string)($template['start_date']??$template['next_due_date']??'');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start))continue;$cursor=new DateTimeImmutable($start);$guard=0;$occurrenceIndex=0;while($cursor->format('Y-m-d')<$windowStart&&$guard++<500){$cursor=$advance($cursor,$template);$occurrenceIndex++;}$templateEnd=trim((string)($template['end_date']??''));$maxOccurrences=(int)($template['max_occurrences']??0);while($cursor->format('Y-m-d')<=$windowEnd&&$guard++<1000){$due=$cursor->format('Y-m-d');if($templateEnd!==''&&$due>$templateEnd)break;if($maxOccurrences>0&&$occurrenceIndex>=$maxOccurrences)break;$generation=$generationMap[(int)$template['id']][$due]??null;$row=$template;$row['occurrence_due_date']=$due;$row['generation_date']=$generation['generation_date']??null;$row['generated_feb_id']=$generation['generated_feb_id']??null;$row['occurrence_status']=$generation?((string)$generation['status']==='generated'?'Générée':'Échec de génération'):'Planifiée';$recurringExportRows[]=$row;$cursor=$advance($cursor,$template);$occurrenceIndex++;}}
usort($recurringExportRows,static fn(array$a,array$b):int=>strcmp((string)$a['occurrence_due_date'],(string)$b['occurrence_due_date']));
$recurringExportTotal=array_sum(array_map(static fn(array $row): float=>(float)($row['amount_estimated']??0),$recurringExportRows));
$recurringExportId='FIN-BR-REC-'.date('Ymd-His').'-U'.$userId;
$recurringExportPeriod=$windowStart.' au '.$windowEnd;
