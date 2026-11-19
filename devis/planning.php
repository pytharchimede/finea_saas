<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';require_once __DIR__.'/../services/ChantierFinancialComplianceService.php';
require_once __DIR__.'/../services/ActiveChantierEditPermissionService.php';
require_once __DIR__.'/../services/ChantierWorkPeriodService.php';
$pdo=Database::getConnection();$_SESSION['devis_planning_csrf']=$_SESSION['devis_planning_csrf']??bin2hex(random_bytes(32));$devisId=(int)($_GET['devisId']??$_POST['devis_id']??0);$message='';$error='';
$stmt=$pdo->prepare('SELECT d.id,d.numero_devis,d.date_emission,d.project_start_date,d.project_end_date,d.total_ttc,c.id chantier_id,c.code,c.titre FROM devis_banamur d JOIN chantier_banamur c ON c.devis_id=d.id AND c.archived_at IS NULL WHERE d.id=:id LIMIT 1');$stmt->execute(['id'=>$devisId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$row){http_response_code(404);exit('Devis ou chantier actif introuvable.');}
if($_SERVER['REQUEST_METHOD']==='POST'&&!hash_equals((string)$_SESSION['devis_planning_csrf'],(string)($_POST['_csrf']??''))){$error='Session expirée. Rechargez la page.';$_SERVER['REQUEST_METHOD']='GET';}
if ($_SERVER['REQUEST_METHOD']==='POST') try {
    if (empty($_SESSION['permissions'][ChantierWorkPeriodService::PERMISSION])) throw new DomainException('Droit de modification des dates des travaux requis.');
    $start=(string)($_POST['project_start_date']??'');
    $end=(string)($_POST['project_end_date']??'');
    $pdo->beginTransaction();
    ChantierWorkPeriodService::save($pdo,$devisId,$start,$end);
    $pdo->commit();
    $assessment=(new ChantierFinancialComplianceService($pdo))->auditOne((int)$row['chantier_id'],$devisId);
    $row['project_start_date']=$start;$row['project_end_date']=$end;
    $message='Dates des travaux enregistrées. Repositionnez les déboursés dans cette période.';
    if (($assessment['status']??'')==='incomplete') $message.=' Contrôle du chantier : '.(string)($assessment['issue']??'données à régulariser.');
} catch(Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error=$e->getMessage();
}
$canChangeDates=!empty($_SESSION['permissions'][ChantierWorkPeriodService::PERMISSION]);
$pageTitle='Planning du devis';$activeMenu='';require_once __DIR__.'/../decaissement/includes/header.php';require_once __DIR__.'/../decaissement/includes/navbar.php';
?>
<main class="min-h-screen bg-slate-50 px-4 py-8"><div class="mx-auto max-w-4xl"><header class="rounded-3xl bg-gradient-to-br from-slate-950 to-indigo-800 p-7 text-white shadow-xl"><p class="text-xs font-black uppercase tracking-[.2em] text-amber-300">FINEA · Centre devis</p><h1 class="mt-2 text-3xl font-black">Planning du devis</h1><p class="mt-2 text-indigo-100"><?=htmlspecialchars((string)$row['numero_devis'])?> · <?=htmlspecialchars((string)$row['code'])?> — <?=htmlspecialchars((string)$row['titre'])?></p></header><?php if($message):?><div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-900"><?=htmlspecialchars($message)?></div><?php endif;?><?php if($error):?><div class="mt-5 rounded-xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-900"><?=htmlspecialchars($error)?></div><?php endif;?><section id="planning-devis" class="mt-6 rounded-2xl border bg-white p-6 shadow-sm"><div class="flex items-start gap-4"><span class="grid h-12 w-12 place-items-center rounded-xl bg-amber-400 text-slate-950"><i class="fas fa-calendar-days"></i></span><div><h2 class="text-xl font-black text-slate-950">Période générale d’exécution</h2><p class="mt-1 text-sm text-slate-500">Elle encadre les déboursés, les travaux et les prévisions du chantier.</p></div></div><form method="post" class="mt-6"><input type="hidden" name="devis_id" value="<?=$devisId?>"><div class="grid gap-4 sm:grid-cols-2"><label class="text-sm font-bold text-slate-700">Début prévu<input type="date" name="project_start_date" required <?= $canChangeDates ? '' : 'disabled' ?> min="<?=htmlspecialchars((string)$row['date_emission'])?>" value="<?=htmlspecialchars((string)$row['project_start_date'])?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label><label class="text-sm font-bold text-slate-700">Fin prévue<input type="date" name="project_end_date" required <?= $canChangeDates ? '' : 'disabled' ?> value="<?=htmlspecialchars((string)$row['project_end_date'])?>" class="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3"></label></div><div class="mt-6 flex flex-wrap gap-3"><?php if(!empty($_SESSION['permissions'][ChantierWorkPeriodService::PERMISSION])):?><button class="rounded-xl bg-indigo-700 px-5 py-3 font-black text-white"><i class="fas fa-check mr-2"></i>Enregistrer le planning</button><?php else:?><span class="text-sm font-semibold text-rose-700">Droit de modification des dates des travaux requis.</span><?php endif;?><a href="../chantiers/rapport_regularisation.php" class="rounded-xl border px-5 py-3 font-bold">Retour au rapport</a></div></form></section></div></main>
<script>(()=>{const form=document.querySelector('form[method="post"]'),csrf=document.createElement('input');csrf.type='hidden';csrf.name='_csrf';csrf.value=<?=json_encode((string)$_SESSION['devis_planning_csrf'])?>;form.appendChild(csrf);const start=document.querySelector('[name="project_start_date"]'),end=document.querySelector('[name="project_end_date"]');const sync=()=>{end.min=start.value;if(end.value&&end.value<start.value)end.value=''};start.addEventListener('change',sync);sync()})();</script>
<?php require_once __DIR__.'/../decaissement/includes/footer.php';?>
