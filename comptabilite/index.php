<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
$views=['dashboard'=>'Vue d’ensemble','disbursements'=>'Décaissements à comptabiliser','entries'=>'Écritures','journal'=>'Journal','ledger'=>'Grand livre','balance'=>'Balance','accounts'=>'Plan comptable','settings'=>'Exercices & journaux'];
$view=(string)($_GET['view']??(accountingView('dashboard')?'dashboard':(accountingView('entries')?'entries':'accounts')));
if (!isset($views[$view]) && !in_array($view,['edit','detail','point','source'],true)) { $view='dashboard'; }
accountingRequire(accountingView($view));
$views=array_filter($views,fn($label,$key)=>accountingView($key),ARRAY_FILTER_USE_BOTH);
$error=''; $formData=null;
$flash=(string)($_SESSION['accounting_flash']??''); unset($_SESSION['accounting_flash']);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        accountingRequire(accountingAction((string)($_POST['action']??'')));
        if (!is_string($_POST['csrf']??null) || !hash_equals($csrf,$_POST['csrf'])) { throw new DomainException('Session de formulaire expirée. Rechargez la page.'); }
        $action=(string)($_POST['action']??''); $redirect='index.php'; $message='Enregistrement effectué.';
        switch ($action) {
            case 'prepare_source':
                $uploads=[];
                foreach (['payment','expense'] as $category) {
                    $file=$_FILES[$category]??[];
                    if (($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
                    if (($file['error']??-1)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size']>8*1024*1024) throw new DomainException('Justificatif invalide ou supérieur à 8 Mo.');
                    $uploads[$category]=AccountingService::validateDocument((string)$file['name'],(string)file_get_contents($file['tmp_name']));
                }
                $id=$accounting->saveDraft($_POST,$userId,null,['id'=>(int)$_POST['source_id'],'checks'=>$_POST,'uploads'=>$uploads]);
                $redirect='index.php?view=detail&id='.$id;
                $message='Décaissement contrôlé : le brouillon et ses justificatifs sont prêts pour validation.'; break;
            case 'save':
                $document=null;
                if (isset($_FILES['document']) && ($_FILES['document']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                    $file=$_FILES['document'];
                    if ($file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || (int)$file['size']>8*1024*1024) { throw new DomainException('Échec du téléversement : justificatif limité à 8 Mo.'); }
                    $document=AccountingService::validateDocument((string)$file['name'],(string)file_get_contents($file['tmp_name']));
                }
                $id=$accounting->saveDraft($_POST,$userId,$document);
                $redirect='index.php?view=detail&id='.$id; $message='Brouillon enregistré. Vérifiez les lignes puis validez pour passer l’écriture.';
                break;
            case 'post':
                $accounting->post((int)$_POST['id'],(int)$_POST['version'],$userId);
                $redirect='index.php?view=detail&id='.(int)$_POST['id']; $message='Écriture validée et inscrite dans les livres comptables.'; break;
            case 'cancel':
                $accounting->cancelDraft((int)$_POST['id'],(int)$_POST['version'],$userId);
                $redirect='index.php?view=detail&id='.(int)$_POST['id']; $message='Brouillon annulé ; son historique est conservé.'; break;
            case 'reverse':
                $id=$accounting->reverse((int)$_POST['id'],(int)$_POST['exercise_id'],(string)$_POST['entry_date'],(string)$_POST['reason'],$userId);
                $redirect='index.php?view=detail&id='.$id; $message='Contrepassation validée ; l’écriture initiale est conservée.'; break;
            case 'exercise':
                $id=$accounting->createExercise((string)$_POST['label'],(string)$_POST['starts_on'],(string)$_POST['ends_on'],$userId);
                $redirect='index.php?view=settings&exercise='.$id; break;
            case 'close':
                if (($_POST['confirm_close']??'')!=='1') { throw new DomainException('Confirmez le verrouillage définitif de la période.'); }
                $accounting->closePeriod((int)$_POST['exercise_id'],(string)$_POST['through'],$userId);
                $redirect='index.php?view=settings&exercise='.(int)$_POST['exercise_id']; $message='Période clôturée et verrouillée.'; break;
            case 'journal':
                $accounting->addJournal((string)$_POST['code'],(string)$_POST['label'],(string)$_POST['kind'],$userId);
                $redirect='index.php?view=settings'; break;
            case 'account':
                $accounting->importAccounts([['code'=>$_POST['code'],'label'=>$_POST['label']]],$userId);
                $redirect='index.php?view=accounts'; break;
            case 'import_accounts':
                $file=$_FILES['csv']??[];
                if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name']) || $file['size']>1024*1024) { throw new DomainException('Fichier CSV obligatoire, maximum 1 Mo.'); }
                $handle=fopen($file['tmp_name'],'r'); $accounts=[];
                try {
                    $header=fgetcsv($handle,0,';','"','');
                    if (!$header || array_map(fn($v)=>trim((string)$v,"\xEF\xBB\xBF \t\r\n"),$header)!==['code','libelle']) { throw new DomainException('En-tête attendu : code;libelle (CSV UTF-8, séparateur point-virgule).'); }
                    while (($row=fgetcsv($handle,0,';','"',''))!==false) {
                        if ($row===[null]) { continue; }
                        if (count($row)!==2 || count($accounts)>=3000) { throw new DomainException('CSV invalide ou supérieur à 3 000 lignes.'); }
                        $accounts[]=['code'=>$row[0],'label'=>$row[1]];
                    }
                } finally { fclose($handle); }
                $count=$accounting->importAccounts($accounts,$userId);
                $redirect='index.php?view=accounts'; $message=$count.' compte(s) ajouté(s).'; break;
            default: throw new DomainException('Action inconnue.');
        }
        $_SESSION['accounting_flash']=$message;
        header('Location: '.$redirect, true, 303); exit;
    } catch (DomainException $e) { $error=$e->getMessage(); }
    catch (Throwable $e) { error_log('[accounting] '.$e->getMessage()); $error='L’opération a échoué. Aucune modification comptable n’a été enregistrée.'; }
    if (($_POST['action']??'')==='prepare_source') { $formData=$_POST; $view='source'; }
    if (($_POST['action']??'')==='save') { $formData=$_POST; $view='edit'; }
}
$exercises=$accounting->exercises(); $journals=$accounting->journals(); $accounts=$accounting->accounts();
// Preserve the supplied short labels while showing the hierarchy that gives them meaning.
$accountsByCode=array_column($accounts,null,'code');
foreach ($accounts as &$account) {
    $account['parent_context']='';
    $code=(string)$account['code'];
    for ($length=strlen($code)-1; $length>=2; $length--) {
        $parent=substr($code,0,$length);
        if (isset($accountsByCode[$parent])) {
            $account['parent_context']=$parent.' · '.$accountsByCode[$parent]['label'];
            break;
        }
    }
    $account['display_label']=$account['label'].($account['parent_context']!=='' ? ' — '.$account['parent_context'] : '');
}
unset($account);
$exerciseId=(int)($_GET['exercise']??0); $exercise=null;
foreach ($exercises as $candidate) { if ((int)$candidate['id']===$exerciseId) { $exercise=$candidate; } }
if (!$exercise && $exercises) {
    $exercise=$exercises[0];
    foreach ($exercises as $candidate) {
        if ($candidate['starts_on']<=date('Y-m-d') && $candidate['ends_on']>=date('Y-m-d')) {
            $exercise=$candidate; break;
        }
    }
    $exerciseId=(int)$exercise['id'];
}
$from=(string)($_GET['from']??($exercise['starts_on']??date('Y-01-01')));
$to=(string)($_GET['to']??($exercise['ends_on']??date('Y-12-31')));
$journalFilter=(string)($_GET['journal']??''); $accountFilter=(string)($_GET['account']??'');
$report=['rows'=>[],'balances'=>[]]; $entry=null; $source=null; $point=null; $points=[]; $sourceAvailable=$sourceBridge->available();
try {
    if (in_array($view,['disbursements','point','source'],true)) {
        if (!$sourceAvailable) throw new DomainException('Les tables des points de décaissement ne sont pas encore disponibles.');
        if ($view==='disbursements') $points=$sourceBridge->points(max(1,(int)($_GET['page']??1)));
        if ($view==='point') $point=$sourceBridge->point((int)($_GET['id']??0));
        if ($view==='source') $source=$sourceBridge->item((int)($_GET['id']??$_POST['source_id']??0));
    }
    if (in_array($view,['journal','ledger','balance','dashboard'],true) && $exercise) { $report=$accounting->report($exerciseId,$from,$to,$journalFilter,$accountFilter); }
    if (in_array($view,['edit','detail'],true) && (int)($_GET['id']??0)>0) { $entry=$accounting->entry((int)$_GET['id']); }
} catch (DomainException $e) { $error=$e->getMessage(); }
if (isset($_GET['export']) && $error==='') {
    accountingRequire(accountingCan('compta_exporter'));
    $export=(string)$_GET['export'];
    accountingRequire(accountingView($export));
    if (!in_array($export,['accounts','journal','ledger','balance'],true) || ($export!=='accounts' && (!$exercise || $view!==$export))) { http_response_code(400); exit('Export invalide.'); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="comptabilite-'.$export.'.csv"');
    header('Cache-Control: private, no-store');
    $out=fopen('php://output','w'); fwrite($out,"\xEF\xBB\xBF");
    $csv=static function (array $row) use ($out): void { fputcsv($out,array_map('accountingCsvCell',$row),';','"',''); };
    if ($export==='accounts') { $csv(['code','libelle']); foreach ($accounts as $a) { if (strlen((string)$a['code'])>=3) { $csv([$a['code'],$a['label']]); } } }
    elseif ($export==='balance') {
        $csv(['Compte','Libellé','Ouverture débit','Ouverture crédit','Débit période','Crédit période','Solde débit','Solde crédit']);
        foreach ($report['balances'] as $b) { $csv([$b['code'],$b['label'],AccountingService::decimal(max(0,$b['opening'])),AccountingService::decimal(max(0,-$b['opening'])),AccountingService::decimal($b['debit']),AccountingService::decimal($b['credit']),AccountingService::decimal(max(0,$b['closing'])),AccountingService::decimal(max(0,-$b['closing']))]); }
    } else {
        $csv(['Date','Journal','Numéro','Référence','Compte','Libellé','Tiers','Débit','Crédit','Solde cumulé (débit - crédit)']);
        $lines=$report['rows']; $running=array_column($report['balances'],'opening','code');
        if ($export==='ledger') {
            usort($lines,fn($a,$b)=>strcmp($a['account_code'],$b['account_code']));
            foreach ($report['balances'] as $b) { $csv([$from,'','','',$b['code'],'Solde à l’ouverture de la période','','','',AccountingService::decimal($b['opening'])]); }
        }
        foreach ($lines as $line) {
            $running[$line['account_code']]+=(int)$line['debit']-(int)$line['credit'];
            $csv([$line['entry_date'],$line['journal_code'],$line['entry_number'],$line['reference'],$line['account_code'],$line['label'],$line['third_party'],AccountingService::decimal((int)$line['debit']),AccountingService::decimal((int)$line['credit']),$export==='ledger'?AccountingService::decimal($running[$line['account_code']]):'']);
        }
    }
    fclose($out); exit;
}
$baseQuery=['view'=>$view,'exercise'=>$exerciseId,'from'=>$from,'to'=>$to,'journal'=>$journalFilter,'account'=>$accountFilter];
$statusLabels=['draft'=>'Brouillon','posted'=>'Validée','cancelled'=>'Annulée'];
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gestion comptable · FINEA</title><link rel="stylesheet" href="assets/accounting.css?v=<?=filemtime(__DIR__.'/assets/accounting.css')?>"></head>
<body><div class="app-shell"><aside class="sidebar"><a class="brand accounting-brand" href="index.php" aria-label="Banamur — accueil comptabilité"><img src="../branding_asset.php?type=logo" alt="Banamur"><span class="brand-module">Comptabilité</span></a><div class="workspace-label">FINANCE & COMPTABILITÉ</div><nav aria-label="Navigation comptable"><?php foreach ($views as $key=>$label): ?><a class="<?=($key===$view || ($key==='entries' && in_array($view,['edit','detail'],true)) || ($key==='disbursements' && in_array($view,['point','source'],true)))?'active':''?>" href="?view=<?=ah($key)?>&exercise=<?=$exerciseId?>"><span class="nav-icon"><?=ah(['dashboard'=>'◫','disbursements'=>'↳','entries'=>'✎','journal'=>'▤','ledger'=>'▥','balance'=>'≡','accounts'=>'#','settings'=>'⚙'][$key])?></span><?=ah($label)?></a><?php endforeach; ?></nav><div class="sidebar-bottom"><span class="status-dot"></span> SYSCOHADA révisé<br><small>Comptabilité générale · XOF</small></div><a class="portal-return" href="../selection_portail.php"><span aria-hidden="true">←</span><span><strong>Retour au portail</strong><small>Accéder aux autres modules</small></span></a></aside>
<main><header class="topbar"><div><span class="eyebrow">ESPACE FINANCE</span><strong>Comptabilité</strong></div><span class="access-label"><?=!empty($_SESSION['permissions']['acces_admin'])?'Administrateur':'Accès personnalisé'?></span></header>
<div class="content"><div class="page-heading"><div><div class="eyebrow">COMPTABILITÉ GÉNÉRALE</div><h1><?=ah($views[$view]??(['edit'=>'Saisir une écriture','detail'=>'Détail de l’écriture','point'=>'Contrôler un point payé','source'=>'Comptabiliser un décaissement'][$view]??'Gestion comptable'))?></h1><p>Des écritures justifiées, des comptes traçables.</p></div><?php if (accountingAction('prepare_source') && $exercise): ?><a class="button primary" href="?view=disbursements&exercise=<?=$exerciseId?>">+ Comptabiliser un décaissement</a><?php endif; ?></div>
<?php if ($flash): ?><div class="notice success" role="status"><?=ah($flash)?></div><?php endif; ?>
<?php if ($error): ?><div class="notice error" role="alert"><?=ah($error)?></div><?php endif; ?>
<?php if (!$exercise): ?><div class="notice">Commencez par créer votre exercice dans <a href="?view=settings">Exercices & journaux</a>.</div><?php endif; ?>
<?php if ($exercise && !in_array($view,['edit','detail','accounts','disbursements','point','source'],true)): ?>
<form method="get" class="filters"><input type="hidden" name="view" value="<?=ah($view)?>"><label>Exercice<select name="exercise" id="exercise-filter"><?php foreach ($exercises as $e): ?><option value="<?=(int)$e['id']?>" <?=((int)$e['id']===$exerciseId)?'selected':''?>><?=ah($e['label'])?><?=$e['closed_at']?' · clôturé':''?></option><?php endforeach; ?></select></label>
<?php if (in_array($view,['journal','ledger','balance','dashboard'],true)): ?><label>Du<input type="date" name="from" value="<?=ah($from)?>" required></label><label>Au<input type="date" name="to" value="<?=ah($to)?>" required></label><label>Journal<select name="journal"><option value="">Tous les journaux</option><?php foreach ($journals as $j): ?><option value="<?=ah($j['code'])?>" <?=$journalFilter===$j['code']?'selected':''?>><?=ah($j['code'].' · '.$j['label'])?></option><?php endforeach; ?></select></label><?php if ($view!=='dashboard'): ?><label>Compte<select name="account"><option value="">Tous les comptes</option><?php foreach ($accounts as $a): if (!$a['postable']) continue; ?><option value="<?=ah($a['code'])?>" <?=$accountFilter===$a['code']?'selected':''?>><?=ah($a['code'].' · '.$a['display_label'])?></option><?php endforeach; ?></select></label><?php endif; ?><?php endif; ?><button class="button">Afficher</button></form>
<?php endif; ?>
<?php require __DIR__.'/views.php'; ?>
<footer>Montants en XOF · Les livres ne comprennent que les écritures validées. La classe 9 se consulte en sélectionnant un journal hors bilan / analytique. Les soldes d’ouverture proviennent des écritures antérieures de l’exercice, y compris les à-nouveaux saisis.</footer>
</div></main></div><script src="assets/modern-controls.js?v=<?=filemtime(__DIR__.'/assets/modern-controls.js')?>" defer></script><script src="assets/accounting.js?v=<?=filemtime(__DIR__.'/assets/accounting.js')?>" defer></script></body></html>
