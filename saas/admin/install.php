<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
$pdo=require_admin(); $t=tenant($pdo); $id=(int)$t['id'];
$err=''; $ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (!check_csrf()) { http_response_code(403); $err='Session expirée.'; }
 else {
  $url=trim(is_string($_POST['target_url']??null)?$_POST['target_url']:'');
  $mode=is_string($_POST['deployment_mode']??null)?$_POST['deployment_mode']:'';
  $parts=parse_url($url);
  $host=is_array($parts)?($parts['host']??''):'';
  $scheme=is_array($parts)?($parts['scheme']??''):'';
  $local=in_array(strtolower($host),['localhost','127.0.0.1'],true);
  if (!in_array($mode,['local','external'],true) || !in_array($scheme,['http','https'],true) || !$host || strlen($url)>500 || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($scheme!=='https'&&!($mode==='local'&&$local))) {
   $err='URL invalide : HTTPS obligatoire sauf localhost en mode local.';
  } else {
   try {
    $s=$pdo->prepare("INSERT INTO saas_tenant_deployments (tenant_id,target_url,deployment_mode,status) VALUES (?,?,?,'prepared') ON DUPLICATE KEY UPDATE target_url=VALUES(target_url),deployment_mode=VALUES(deployment_mode),status='prepared',verified_at=NULL");
    $s->execute([$id,$url,$mode]);
    header('Location: install.php?id='.$id.'&saved=1',true,303);exit;
   } catch(Throwable $e){error_log('FINEA install: '.$e->getMessage());$err='Enregistrement impossible. Vérifie la migration 005.';}
  }
 }
}
try {$s=$pdo->prepare('SELECT * FROM saas_tenant_deployments WHERE tenant_id=?');$s->execute([$id]);$d=$s->fetch(PDO::FETCH_ASSOC)?:null;}catch(Throwable $e){error_log($e->getMessage());http_response_code(503);exit('Migration 005 requise.');}
try {$s=$pdo->prepare('SELECT email,initial_password_hash FROM saas_tenant_admin_invites WHERE tenant_id=?');$s->execute([$id]);$a=$s->fetch(PDO::FETCH_ASSOC)?:null;}catch(Throwable $e){$a=null;}
shell_top('Assistant d’installation','Préparer le déploiement de '.(string)$t['company_name']);
echo '<div class="actions" style="margin-bottom:20px"><a class="btn" href="company.php?id='.$id.'">← Fiche entreprise</a><a class="btn" href="company_admin.php?id='.$id.'">Premier administrateur</a></div>';
if ($err) echo '<div class="notice error">'.esc($err).'</div>';
if (isset($_GET['saved'])) echo '<div class="notice success">Configuration de destination enregistrée. Installation non encore exécutée.</div>';
echo '<div class="split"><section class="panel"><h2>01 · Destination du serveur</h2><p class="sub">L’assistant prépare la configuration. Il ne déploie pas encore le code ni la base de données.</p><form method="post"><input type="hidden" name="csrf" value="'.esc(csrf_token()).'"><div class="field"><label for="deployment_mode">Type de serveur</label><select id="deployment_mode" name="deployment_mode"><option value="local"'.(($d['deployment_mode']??'local')==='local'?' selected':'').'>Local / développement</option><option value="external"'.(($d['deployment_mode']??'')==='external'?' selected':'').'>Serveur externe / hébergement</option></select></div><div class="field" style="margin-top:16px"><label for="target_url">URL prévue de l’ERP</label><input type="url" id="target_url" name="target_url" maxlength="500" required placeholder="https://erp.exemple.ci" value="'.esc($d['target_url']??'').'"></div><div class="form-actions"><button class="btn primary" type="submit">Enregistrer la destination</button></div></form><div class="notice">Ne saisis aucun mot de passe SSH, FTP ou MariaDB dans ce formulaire.</div></section><aside class="panel"><h2>Étapes de déploiement</h2><div class="keyval"><span>01 · Entreprise</span><span>Créée</span></div><div class="keyval"><span>02 · Premier administrateur</span><span>'.($a&&!empty($a['initial_password_hash'])?'Préparé':'À configurer').'</span></div><div class="keyval"><span>03 · Destination</span><span>'.($d?'Préparée':'À définir').'</span></div><div class="keyval"><span>04 · Base métier isolée</span><span>À installer</span></div><div class="keyval"><span>05 · Migration ERP et fichiers</span><span>À installer</span></div><div class="keyval"><span>06 · Compte administrateur ERP</span><span>À créer</span></div><div class="keyval"><span>07 · Tests et activation</span><span>À vérifier</span></div><div class="notice">Le lien « Ouvrir FINEA » sera disponible seulement après vérification réelle de l’installation, du routage tenant et de l’authentification ERP.</div></aside></div>';
shell_bottom();
