<?php
require_once __DIR__.'/../auth_check.php';
// Same consultation permissions as the existing financial points page.
AccessManager::requirePageAccess('points_financiers_rh.php');
require_once __DIR__.'/../services/RhFinancialPointHistoryService.php';
require_once __DIR__.'/../services/RhFinancialPointService.php';
require_once __DIR__.'/../services/RhFinancialSchema.php';
$pdo=Database::getConnection();$history=new RhFinancialPointHistoryService($pdo);
$result=['filters'=>['scope'=>'stopped','kind'=>'','status'=>'','q'=>'','date_from'=>'','date_to'=>'','sort'=>'recent'],'rows'=>[],'total'=>0,'page'=>1,'pages'=>1];
$error='';$message=is_string($_SESSION['rh_financial_notice'] ?? null)?$_SESSION['rh_financial_notice']:'';unset($_SESSION['rh_financial_notice']);$point=null;$versions=[];
$canRh=AccessManager::hasPermission('acces_rh')||AccessManager::hasPermission('acces_admin');
$_SESSION['rh_financial_csrf']??=bin2hex(random_bytes(32));$csrf=$_SESSION['rh_financial_csrf'];
try {
    if (RhFinancialSchema::missing($pdo,false)) throw new DomainException('Le module financier RH doit être installé pour consulter les points.');
    if(($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        if(!$canRh)throw new DomainException('Action réservée aux RH.');
        if(!is_string($_POST['_csrf'] ?? null) || !hash_equals($csrf,$_POST['_csrf']))throw new DomainException('Session expirée. Rechargez la page.');
        if(($_POST['action'] ?? '')!=='resend')throw new DomainException('Action inconnue.');
        (new RhFinancialPointService($pdo))->notify((int)($_POST['point_id'] ?? 0),(int)($_POST['revision'] ?? 0),(int)$_SESSION['user_id'],true);
        $_SESSION['rh_financial_notice']='Invitation acceptée par Twilio. Le nouveau lien est valable 24 heures et remplace le précédent.';
        $returnQuery=array_intersect_key($_GET,array_flip(['scope','kind','status','q','date_from','date_to','sort','page','id']));
        header('Location: historique_points_financiers_rh.php'.($returnQuery?'?'.http_build_query($returnQuery):''));exit;
    }
    $result=$history->search($_GET);
    $id=is_scalar($_GET['id'] ?? null)?(int)$_GET['id']:0;
    if ($id>0) {$point=(new RhFinancialPointService($pdo))->get($id);$versions=$history->versions($id);}
} catch (PDOException $e) {error_log('RH history: '.$e->getMessage());$error='Le suivi est momentanément indisponible. Réessayez après avoir actualisé la page.';}
catch (Throwable $e) {$error=$e instanceof DomainException?$e->getMessage():'Impossible de charger l’historique des points.';}
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$money=static fn($v)=>number_format((float)$v,0,',',' ').' FCFA';
$date=static fn($v)=>$v?date('d/m/Y H:i',strtotime($v)):'En attente';
$filters=$result['filters'];
$url=static fn(array $changes)=>'historique_points_financiers_rh.php?'.http_build_query(array_replace($filters,['page'=>$result['page']],$changes));
$febLabels=['submitted'=>'Soumise aux Finances','approved'=>'Approuvée','rejected'=>'Refusée','cancelled'=>'Annulée','draft'=>'Brouillon'];
$pageTitle='Points RH arrêtés et suivi';$activeMenu='rh';
require_once __DIR__.'/../decaissement/includes/header.php';
require_once __DIR__.'/../decaissement/includes/navbar.php';
?>
<link rel="stylesheet" href="../css/rh-financial.css?v=20261014-flow">
<main class="rh-points">
<header class="rh-hero"><p class="rh-eyebrow">FINEA · Ressources humaines</p><h1>Points RH arrêtés et suivi</h1><p>Consultez les signatures, les invitations au DG et l’avancement financier des points.</p><a class="rh-btn" href="points_financiers_rh.php">Préparer un point</a><a class="rh-btn" href="dashboard_rh.php">Tableau de bord RH</a></header>
<?php if($message):?><div class="rh-banner rh-success" role="status"><?=$h($message)?></div><?php endif?>
<?php if($error):?><div class="rh-banner" role="alert"><?=$h($error)?></div><?php endif?>
<section><h2>Rechercher un point</h2>
<form method="get" class="rh-form-grid">
<label>Points à afficher<select name="scope"><option value="stopped" <?=$filters['scope']==='stopped'?'selected':''?>>Points déjà arrêtés, y compris réouverts</option><option value="all" <?=$filters['scope']==='all'?'selected':''?>>Tous les points</option></select></label>
<label>Type<select name="kind"><option value="">Tous les types</option><?php foreach(RhFinancialPointPolicy::KINDS as $key=>$label):?><option value="<?=$h($key)?>" <?=$filters['kind']===$key?'selected':''?>><?=$h($label)?></option><?php endforeach?></select></label>
<label>État du point<select name="status"><option value="">Tous les états</option><?php foreach(RhFinancialPointHistoryService::STATES as $key=>$label):?><option value="<?=$h($key)?>" <?=$filters['status']===$key?'selected':''?>><?=$h($label)?></option><?php endforeach?></select></label>
<label>Trier les points<select name="sort"><?php foreach(RhFinancialPointHistoryService::SORTS as $key=>$label):?><option value="<?=$h($key)?>" <?=$filters['sort']===$key?'selected':''?>><?=$h($label)?></option><?php endforeach?></select></label>
<label>Numéro du point ou porteur RH<input name="q" value="<?=$h($filters['q'])?>" placeholder="Ex. #1 ou nom du porteur"></label>
<label>Créé à partir du<input type="date" name="date_from" value="<?=$h($filters['date_from'])?>"></label>
<label>Créé jusqu’au<input type="date" name="date_to" value="<?=$h($filters['date_to'])?>"></label>
<div class="rh-form-action"><button>Rechercher</button><a class="rh-btn rh-btn-outline" href="historique_points_financiers_rh.php">Réinitialiser</a><a class="rh-btn rh-btn-outline" href="<?=$h($url([]))?>">Actualiser les états</a></div>
</form></section>
<?php if($point):?><section><h2>Versions du point #<?=(int)$point['id']?></h2><p>Porteur RH : <b><?=$h($point['stopped_name'])?></b> · État actuel : <b><?=$h(RhFinancialPointHistoryService::STATES[$point['status']] ?? $point['status'])?></b></p><a class="rh-btn" href="points_financiers_rh.php?id=<?=(int)$point['id']?>">Consulter le détail actuel</a>
<div class="rh-scroll"><table><thead><tr><th>Version</th><th>Signature RH</th><th>Signature DG</th><th>Invitation WhatsApp</th><th>Documents</th></tr></thead><tbody>
<?php foreach($versions as $version):?><tr><td>v<?=(int)$version['revision']?> · <?=(int)$version['revision']===(int)$point['revision']?'Actuelle':'Remplacée'?></td><td><?=$h($date($version['rh_signed_at']))?></td><td><?=$h($date($version['dg_signed_at']))?><br><small><?=$h($version['dg_name'])?></small></td><td><?=$h(RhFinancialPointHistoryService::WHATSAPP[$version['whatsapp_status']] ?? $version['whatsapp_status'])?></td><td><a href="export_point_financier_pdf.php?id=<?=(int)$point['id']?>&amp;revision=<?=(int)$version['revision']?>">Consulter PDF v<?=(int)$version['revision']?></a><br><a href="export_point_financier_pdf.php?id=<?=(int)$point['id']?>&amp;revision=<?=(int)$version['revision']?>&amp;download=1">Télécharger PDF</a><?php if($version['feb_request_id']):?><br><a href="../validation_demandes_finances.php?id=<?=(int)$version['feb_request_id']?>&amp;screen=detail">FEB #<?=(int)$version['feb_request_id']?></a><?php endif?></td></tr><?php endforeach?>
</tbody></table></div><p><small>Les versions remplacées restent consultables ; seule la version actuelle représente le point en cours.</small></p></section><?php endif?>
<section><h2><?=$filters['scope']==='stopped'?'Points arrêtés':'Tous les points'?> · <?=(int)$result['total']?> résultat(s)</h2>
<div class="rh-scroll"><table><thead><tr><th>Point / type</th><th>Porteur RH</th><th>Montant retenu</th><th>Signatures</th><th>État actuel</th><th>Finances</th><th>Actions / export</th></tr></thead><tbody>
<?php if(!$error && !$result['rows']):?><tr><td colspan="7" class="rh-empty">Aucun point ne correspond aux filtres.</td></tr><?php endif?>
<?php foreach($result['rows'] as $row):?><tr>
<td><b>#<?=(int)$row['id']?> · v<?=(int)$row['revision']?></b><br><?=$h(RhFinancialPointPolicy::KINDS[$row['kind']])?><br><small><?=$h($row['period'] ?: 'Période courante')?> · Créé le <?=$h($date($row['created_at']))?></small></td>
<td><?=$h($row['stopped_name'])?></td><td><?=$money($row['total'])?><br><small><?=(int)$row['line_count']?> ligne(s)</small></td>
<td>RH : <?=$h($date($row['rh_signed_at']))?><br>DG : <?=$h($date($row['dg_signed_at']))?></td>
<td><span class="rh-status <?=$row['status']==='dg_signed'?'rh-status-approved':($row['status']==='cancelled'?'rh-status-rejected':'rh-status-pending')?>"><?=$h(RhFinancialPointHistoryService::STATES[$row['status']] ?? $row['status'])?></span><br><small>WhatsApp : <?=$h(RhFinancialPointHistoryService::WHATSAPP[$row['whatsapp_status']] ?? $row['whatsapp_status'])?></small></td>
<td><?php if($row['feb_request_id']):?><a href="../validation_demandes_finances.php?id=<?=(int)$row['feb_request_id']?>&amp;screen=detail">FEB #<?=(int)$row['feb_request_id']?></a><br><?=$h($febLabels[$row['feb_status']] ?? ($row['feb_status'] ?: 'État indisponible'))?><br><small><?=$row['has_disbursement']?'Décaissement enregistré':'Aucun décaissement enregistré'?></small><?php else:?><?=$row['status']==='dg_signed'?'Signé sans FEB':'FEB non créée'?><?php endif?></td>
<td><a href="points_financiers_rh.php?id=<?=(int)$row['id']?>">Détail</a><br><a class="rh-btn rh-btn-outline" href="export_point_financier_pdf.php?id=<?=(int)$row['id']?>&amp;download=1">Télécharger PDF</a><br><a href="<?=$h($url(['id'=>(int)$row['id']]))?>">Versions et états</a><?php if($canRh && $row['status']==='rh_signed'):?><form method="post" action="<?=$h($url([]))?>" onsubmit="return confirm('Renvoyer le lien de signature au DG ? L’ancienne invitation sera remplacée.');"><input type="hidden" name="_csrf" value="<?=$h($csrf)?>"><input type="hidden" name="action" value="resend"><input type="hidden" name="point_id" value="<?=(int)$row['id']?>"><input type="hidden" name="revision" value="<?=(int)$row['revision']?>"><button <?=$row['whatsapp_status']==='sending'?'disabled':''?>>Renvoyer le lien au DG</button></form><?php endif?></td>
</tr><?php endforeach?></tbody></table></div>
<nav aria-label="Pagination des points RH"><p>Page <?=(int)$result['page']?> / <?=(int)$result['pages']?> · 25 points par page</p><?php if($result['page']>1):?><a class="rh-btn rh-btn-outline" href="<?=$h($url(['page'=>$result['page']-1]))?>">Précédente</a><?php endif?><?php if($result['page']<$result['pages']):?><a class="rh-btn rh-btn-outline" href="<?=$h($url(['page'=>$result['page']+1]))?>">Suivante</a><?php endif?></nav>
</section></main>
<?php require_once __DIR__.'/../decaissement/includes/footer.php';?>

