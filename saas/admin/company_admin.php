<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
$pdo=require_admin(); $t=tenant($pdo); $id=(int)$t['id'];
$err=''; $ok='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (!check_csrf()) { http_response_code(403); $err='Session expirée. Recharge la page.'; }
 else {
  $name=trim(is_string($_POST['full_name']??null)?$_POST['full_name']:'');
  $email=strtolower(trim(is_string($_POST['email']??null)?$_POST['email']:''));
  $password=is_string($_POST['password']??null)?$_POST['password']:'';
  $confirm=is_string($_POST['password_confirm']??null)?$_POST['password_confirm']:'';
  if ($name==='' || mb_strlen($name)>190 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190) $err='Nom ou adresse email invalide.';
  elseif ($password!=='' && (strlen($password)<8 || strlen($password)>4096)) $err='Le mot de passe initial doit comporter au moins 8 caractères.';
  elseif ($password!==$confirm) $err='Les deux mots de passe ne correspondent pas.';
  else {
   try {
    $hash=$password!==''?password_hash($password,PASSWORD_DEFAULT):null;
    unset($password,$confirm);
    $s=$pdo->prepare("INSERT INTO saas_tenant_admin_invites (tenant_id,full_name,email,initial_password_hash,status) VALUES (?,?,?,?,'pending') ON DUPLICATE KEY UPDATE full_name=VALUES(full_name),email=VALUES(email),initial_password_hash=COALESCE(VALUES(initial_password_hash),initial_password_hash),status='pending',updated_at=NOW()");
    $s->execute([$id,$name,$email,$hash]);
    header('Location: company_admin.php?id='.$id.'&saved=1',true,303);exit;
   } catch(Throwable $e){error_log('FINEA tenant admin: '.$e->getMessage());$err='Impossible d’enregistrer le responsable. Vérifie la migration 003.';}
  }
 }
}
$s=$pdo->prepare('SELECT full_name,email,status,updated_at,(initial_password_hash IS NOT NULL) AS password_set FROM saas_tenant_admin_invites WHERE tenant_id=?');
try {$s->execute([$id]);$admin=$s->fetch(PDO::FETCH_ASSOC)?:null;}catch(Throwable $e){error_log($e->getMessage());http_response_code(503);exit('Migration 003 requise.');}
if (isset($_GET['saved'])) $ok='Premier administrateur enregistré. Son compte ERP reste à provisionner.';
shell_top('Premier administrateur','Désigner le responsable initial de '.(string)$t['company_name']);
echo '<div class="actions" style="margin-bottom:20px"><a class="btn" href="company.php?id='.$id.'">← Fiche entreprise</a><a class="btn" href="index.php">Vue globale</a></div>';
if ($err) echo '<div class="notice error">'.esc($err).'</div>';
if ($ok) echo '<div class="notice success">'.esc($ok).'</div>';
echo '<div class="split"><section class="panel"><h2>Responsable de '.esc($t['company_name']).'</h2><p class="sub">Ces informations seront utilisées pour préparer son invitation sécurisée à FINEA.</p><form method="post"><input type="hidden" name="csrf" value="'.esc(csrf_token()).'"><div class="form-grid"><div class="field full"><label for="full_name">Nom complet *</label><input id="full_name" name="full_name" maxlength="190" required value="'.esc($_POST['full_name']??$admin['full_name']??'').'"></div><div class="field full"><label for="email">Adresse email professionnelle *</label><input type="email" id="email" name="email" maxlength="190" required value="'.esc($_POST['email']??$admin['email']??'').'"></div><div class="field full"><label for="password">Mot de passe initial (laisser vide pour conserver l’existant)</label><input type="password" id="password" name="password" autocomplete="new-password" minlength="8" placeholder="8 caractères minimum"></div><div class="field full"><label for="password_confirm">Confirmer le mot de passe initial</label><input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password"></div></div><div class="form-actions"><button class="btn primary" type="submit">Enregistrer le responsable</button></div></form></section><aside class="panel"><h2>État de l’accès</h2><div class="keyval"><span>Entreprise</span><span>'.esc($t['company_name']).'</span></div><div class="keyval"><span>Provisionnement</span><span>'.esc($t['status']).'</span></div><div class="keyval"><span>Responsable</span><span>'.($admin?'Renseigné':'Non renseigné').'</span></div><div class="keyval"><span>Mot de passe initial</span><span>'.($admin && $admin['password_set']?'Défini (haché)':'Non défini').'</span></div><div class="notice">Le mot de passe initial est stocké uniquement sous forme de hash. Il ne permet pas encore de se connecter à l’ERP : le compte doit être créé dans la base métier lors du provisionnement, avec changement obligatoire au premier accès. Transmets le secret initial par un canal sécurisé distinct.</div></aside></div>';
shell_bottom();
