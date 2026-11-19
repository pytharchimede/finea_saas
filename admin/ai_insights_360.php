<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';

$pdo = Database::getConnection();
$days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90, 180, 365], true) ? (int)$_GET['days'] : 30;
$dateTo = date('Y-m-d');
$dateFrom = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

function ai360h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function ai360money($value): string { return number_format((float)$value, 0, ',', ' ') . ' FCFA'; }
function ai360number($value, int $decimals = 0): string { return number_format((float)$value, $decimals, ',', ' '); }
function ai360table(PDO $pdo, string $table): bool {
    try { $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');$stmt->execute(['table'=>$table]);return (int)$stmt->fetchColumn()>0; } catch(Throwable $e){return false;}
}
function ai360one(PDO $pdo, string $sql, array $params = []): array {
    try {$stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){return [];}
}
function ai360rows(PDO $pdo, string $sql, array $params = []): array {
    try {$stmt=$pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){return [];}
}
function ai360signal(string $domain, string $level, string $title, string $finding, string $action, array $evidence): array {
    return compact('domain','level','title','finding','action','evidence');
}

$params=['from'=>$dateFrom,'to'=>$dateTo];
$coverage=['finance'=>ai360table($pdo,'fiche_decaissement'),'rh'=>ai360table($pdo,'personnel'),'attendance'=>ai360table($pdo,'rh_attendance_daily'),'logistics'=>ai360table($pdo,'logistics_fuel_logs'),'stock'=>ai360table($pdo,'logistics_stock_items'),'feb'=>ai360table($pdo,'employee_legal_requests')];

$finance=$coverage['finance']?ai360one($pdo,"SELECT
 COUNT(*) total_fiches,
 COALESCE(SUM(CASE WHEN decaisse=1 AND DATE(COALESCE(date_decaisse,date_valid,date_creat_fiche)) BETWEEN :from AND :to THEN montant_fiche ELSE 0 END),0) paid_period,
 COALESCE(SUM(CASE WHEN COALESCE(decaisse,0)=0 THEN montant_fiche ELSE 0 END),0) outstanding,
 SUM(CASE WHEN COALESCE(decaisse,0)=0 THEN 1 ELSE 0 END) outstanding_count,
 COALESCE(SUM(CASE WHEN COALESCE(decaisse,0)=0 AND date_decaissement_minimum<CURDATE() THEN montant_fiche ELSE 0 END),0) overdue,
 SUM(CASE WHEN COALESCE(decaisse,0)=0 AND date_decaissement_minimum<CURDATE() THEN 1 ELSE 0 END) overdue_count,
 COALESCE(SUM(CASE WHEN COALESCE(decaisse,0)=0 AND date_decaissement_minimum BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN montant_fiche ELSE 0 END),0) next_30
 FROM fiche_decaissement",$params):[];
$feb=$coverage['feb']?ai360one($pdo,"SELECT COUNT(*) total,
 SUM(status IN ('submitted','pending')) pending_count,
 SUM(status='approved') approved_count,
 SUM(request_type='feb' AND status IN ('submitted','pending') AND COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.validation_deferred_until')),'null'),'')>CURDATE()) deferred_count
 FROM employee_legal_requests WHERE request_type='feb' AND DATE(created_at) BETWEEN :from AND :to",$params):[];
$hr=$coverage['rh']?ai360one($pdo,'SELECT COUNT(*) personnel_count FROM personnel'):[];
$attendance=$coverage['attendance']?ai360one($pdo,"SELECT COUNT(*) records,
 SUM(attendance_status IN ('absent','absence','unjustified_absence')) absence_count,
 SUM(check_in_time>'08:00:00' AND attendance_status IN ('present','half_day')) late_count,
 COALESCE(SUM(overtime_hours),0) overtime_hours,
 COALESCE(SUM(worked_days),0) worked_days
 FROM rh_attendance_daily WHERE attendance_date BETWEEN :from AND :to",$params):[];
$fuel=$coverage['logistics']?ai360one($pdo,"SELECT COUNT(*) fuel_entries,COALESCE(SUM(quantity),0) liters,COALESCE(SUM(total_cost),0) fuel_cost,SUM(COALESCE(is_anomaly,0)=1) anomalies FROM logistics_fuel_logs WHERE DATE(fuel_date) BETWEEN :from AND :to",$params):[];
$stock=$coverage['stock']?ai360one($pdo,"SELECT COUNT(*) items,SUM(current_quantity<=minimum_quantity) low_stock,SUM(current_quantity=0) stockout FROM logistics_stock_items WHERE status IN ('actif','active') OR status IS NULL"):[];
$tasks=ai360table($pdo,'employee_schedule_event')?ai360one($pdo,"SELECT COUNT(*) tasks,SUM(status_code IN ('completed','terminee','done')) completed,SUM(end_at<NOW() AND status_code NOT IN ('completed','terminee','done','cancelled')) overdue FROM employee_schedule_event WHERE DATE(start_at) BETWEEN :from AND :to",$params):[];

$signals=[];
if($coverage['finance']){
    $overdue=(float)($finance['overdue']??0);$outstanding=(float)($finance['outstanding']??0);$ratio=$outstanding>0?$overdue/$outstanding:0;
    $signals[]=ai360signal('Finances',$ratio>=.30?'critical':($ratio>=.10?'warning':'positive'),'Pression sur les décaissements',$outstanding>0?ai360money($outstanding).' restent à décaisser, dont '.ai360money($overdue).' en retard.':'Aucun décaissement restant détecté.',$ratio>=.10?'Revoir en priorité les fiches échues et confirmer leurs dates de trésorerie.':'Maintenir le suivi du calendrier de trésorerie.',[(int)($finance['outstanding_count']??0).' fiches ouvertes',(int)($finance['overdue_count']??0).' fiches échues']);
}
if($coverage['feb']){
    $pending=(int)($feb['pending_count']??0);
    $signals[]=ai360signal('FEB',$pending>20?'warning':'neutral','Charge du circuit FEB',$pending.' FEB de la période sont encore en attente et '.(int)($feb['deferred_count']??0).' sont reportées.',$pending>20?'Répartir le traitement par lots et par urgence de planning.':'Poursuivre le traitement selon la période planifiée.',[(int)($feb['total']??0).' FEB sur la période',(int)($feb['approved_count']??0).' approuvées']);
}
if($coverage['attendance']){
    $records=max(1,(int)($attendance['records']??0));$issues=(int)($attendance['absence_count']??0)+(int)($attendance['late_count']??0);$rate=$issues/$records;
    $signals[]=ai360signal('Ressources humaines',$rate>=.15?'warning':($rate<=.04?'positive':'neutral'),'Présence et ponctualité',ai360number($attendance['worked_days']??0,1).' jours travaillés enregistrés ; '.(int)($attendance['absence_count']??0).' absences et '.(int)($attendance['late_count']??0).' retards.',$rate>=.15?'Analyser les services et personnes concentrant les écarts avant toute décision RH.':'Maintenir le suivi et contrôler la complétude des pointages.',[ai360number($attendance['overtime_hours']??0,1).' h supplémentaires',$records.' pointages analysés']);
}
if($coverage['logistics']){
    $anomalies=(int)($fuel['anomalies']??0);
    $signals[]=ai360signal('Logistique',$anomalies>0?'warning':'positive','Consommation carburant',ai360number($fuel['liters']??0,1).' litres consommés pour '.ai360money($fuel['fuel_cost']??0).'.',$anomalies>0?'Contrôler les consommations signalées avant rapprochement financier.':'Aucune anomalie explicitement signalée ; surveiller le coût moyen par litre.',[(int)($fuel['fuel_entries']??0).' opérations',$anomalies.' anomalies']);
}
if($coverage['stock']){
    $low=(int)($stock['low_stock']??0);$out=(int)($stock['stockout']??0);
    $signals[]=ai360signal('Stocks',$out>0?'critical':($low>0?'warning':'positive'),'Disponibilité du stock',$low.' article(s) sont au seuil minimum, dont '.$out.' en rupture.',$low>0?'Préparer le réapprovisionnement en regroupant les besoins par fournisseur et urgence.':'Aucun seuil critique détecté.',[(int)($stock['items']??0).' articles suivis']);
}
if($tasks){$signals[]=ai360signal('Organisation',(int)($tasks['overdue']??0)>0?'warning':'neutral','Exécution des activités',(int)($tasks['completed']??0).' activité(s) terminée(s) sur '.(int)($tasks['tasks']??0).'.',(int)($tasks['overdue']??0)>0?'Replanifier ou clôturer les activités dépassées.':'Continuer le suivi du planning.',[(int)($tasks['overdue']??0).' activités dépassées']);}

$priority=['critical'=>4,'warning'=>3,'neutral'=>2,'positive'=>1];
usort($signals,static fn(array $a,array $b):int=>($priority[$b['level']]??0)<=>($priority[$a['level']]??0));
$critical=count(array_filter($signals,static fn($s)=>$s['level']==='critical'));
$warnings=count(array_filter($signals,static fn($s)=>$s['level']==='warning'));
$headline=$critical>0?'Des actions immédiates sont nécessaires sur '.$critical.' sujet(s) critique(s).':($warnings>0?'La situation est exploitable, avec '.$warnings.' point(s) de vigilance à traiter.':'Aucun signal critique n’est détecté sur les sources disponibles.');

$pageTitle='Intelligence 360°';$activeMenu='admin';
require_once __DIR__.'/../decaissement/includes/header.php';require_once __DIR__.'/../decaissement/includes/navbar.php';
$styles=['critical'=>'border-rose-200 bg-rose-50 text-rose-900','warning'=>'border-amber-200 bg-amber-50 text-amber-950','neutral'=>'border-sky-200 bg-sky-50 text-sky-950','positive'=>'border-emerald-200 bg-emerald-50 text-emerald-950'];
?>
<div class="min-h-screen bg-slate-50 pb-12"><main class="mx-auto max-w-7xl px-4 py-8">
 <section class="rounded-[30px] bg-gradient-to-br from-slate-950 via-indigo-950 to-violet-900 p-8 text-white shadow-xl">
  <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between"><div><p class="text-xs font-bold uppercase tracking-[.3em] text-cyan-200">Intelligence décisionnelle explicable</p><h1 class="mt-3 text-3xl font-bold md:text-4xl">Ce que l’IA comprend de l’entreprise</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-200"><?= ai360h($headline) ?> Chaque conclusion ci-dessous cite les données utilisées.</p></div>
  <div class="flex flex-wrap gap-2"><a href="ai_evolution.php" class="rounded-xl border border-white/20 px-4 py-2 text-sm font-bold">Évolution IA</a><button onclick="window.print()" class="rounded-xl bg-white px-4 py-2 text-sm font-bold text-slate-950">Imprimer la synthèse</button></div></div>
 </section>
 <form method="get" class="mt-5 flex flex-wrap items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4"><strong class="text-sm">Période analysée</strong><select name="days" class="rounded-xl border border-slate-200 px-4 py-2 text-sm"><?php foreach([7=>'7 jours',30=>'30 jours',90=>'Trimestre',180=>'Semestre',365=>'Année'] as $value=>$label):?><option value="<?= $value ?>" <?= $days===$value?'selected':'' ?>><?= ai360h($label) ?></option><?php endforeach;?></select><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white">Actualiser l’analyse</button><span class="ml-auto text-xs text-slate-500">Du <?= ai360h(date('d/m/Y',strtotime($dateFrom))) ?> au <?= ai360h(date('d/m/Y',strtotime($dateTo))) ?></span></form>
 <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4"><div class="rounded-2xl border bg-white p-5"><div class="text-xs text-slate-500">Décaissements sur la période</div><div class="mt-2 text-2xl font-bold"><?= ai360money($finance['paid_period']??0) ?></div></div><div class="rounded-2xl border bg-white p-5"><div class="text-xs text-slate-500">Personnel référencé</div><div class="mt-2 text-2xl font-bold"><?= ai360number($hr['personnel_count']??0) ?></div></div><div class="rounded-2xl border bg-white p-5"><div class="text-xs text-slate-500">Carburant sur la période</div><div class="mt-2 text-2xl font-bold"><?= ai360money($fuel['fuel_cost']??0) ?></div></div><div class="rounded-2xl border bg-white p-5"><div class="text-xs text-slate-500">Sorties prévues à 30 jours</div><div class="mt-2 text-2xl font-bold"><?= ai360money($finance['next_30']??0) ?></div></div></div>
 <section class="mt-7"><div class="flex items-end justify-between"><div><p class="text-xs font-bold uppercase tracking-[.2em] text-indigo-600">Synthèse générée</p><h2 class="mt-2 text-2xl font-bold">Constats et recommandations prioritaires</h2></div><span class="text-xs text-slate-500"><?= count($signals) ?> domaines analysés</span></div>
 <div class="mt-4 grid gap-4 lg:grid-cols-2"><?php foreach($signals as $signal):?><article class="rounded-2xl border p-5 <?= $styles[$signal['level']]??$styles['neutral'] ?>"><div class="flex items-start justify-between gap-3"><div><div class="text-xs font-black uppercase tracking-[.18em] opacity-70"><?= ai360h($signal['domain']) ?></div><h3 class="mt-2 text-lg font-bold"><?= ai360h($signal['title']) ?></h3></div><span class="rounded-full bg-white/70 px-3 py-1 text-xs font-bold"><?= ai360h($signal['level']) ?></span></div><p class="mt-3 text-sm leading-6"><?= ai360h($signal['finding']) ?></p><div class="mt-4 rounded-xl bg-white/70 p-3 text-sm"><strong>Action suggérée :</strong> <?= ai360h($signal['action']) ?></div><div class="mt-3 flex flex-wrap gap-2"><?php foreach($signal['evidence'] as $evidence):?><span class="rounded-full border border-current/10 bg-white/60 px-3 py-1 text-xs"><?= ai360h($evidence) ?></span><?php endforeach;?></div></article><?php endforeach;?></div></section>
 <section class="mt-7 rounded-2xl border border-slate-200 bg-white p-6"><h2 class="text-xl font-bold">Ce que l’analyse sait — et ne sait pas</h2><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><?php foreach(['Finance'=>'finance','FEB'=>'feb','Personnel'=>'rh','Pointage'=>'attendance','Carburant'=>'logistics','Stocks'=>'stock'] as $label=>$key):?><div class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 text-sm"><span><?= ai360h($label) ?></span><strong class="<?= $coverage[$key]?'text-emerald-700':'text-amber-700' ?>"><?= $coverage[$key]?'Source disponible':'Source absente' ?></strong></div><?php endforeach;?></div><p class="mt-4 text-xs leading-5 text-slate-500">Cette page produit des analyses explicables à partir des données ERP disponibles. Elle ne remplace pas une décision humaine et ne fabrique pas de conclusion lorsqu’une source manque.</p></section>
</main></div>
<?php require_once __DIR__.'/../decaissement/includes/footer.php'; ?>
