<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
$error='';
if (!empty($_SESSION['admin_id']) && $_SERVER['REQUEST_METHOD']!=='POST') { header('Location: dashboard.php',true,303); exit; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (!check_csrf()) {http_response_code(403);$error='Session expirée.';}
 elseif (($_POST['action']??'')==='login') {
  $now=time();
  if (($_SESSION['locked_until']??0)>$now) $error='Trop de tentatives. Réessaie dans quelques minutes.';
  else {
   try {
    $pdo=platform();$email=strtolower(trim(is_string($_POST['email']??null)?$_POST['email']:''));$password=is_string($_POST['password']??null)?$_POST['password']:'';
    $admin=null;
    if (strlen($email)<=190&&strlen($password)<=4096){$s=$pdo->prepare("SELECT id,password_hash FROM saas_admin_users WHERE email=? AND active=1 AND role='superadmin' LIMIT 1");$s->execute([$email]);$admin=$s->fetch(PDO::FETCH_ASSOC)?:null;}
    if($admin&&password_verify($password,$admin['password_hash'])){session_regenerate_id(true);$_SESSION['admin_id']=(int)$admin['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));unset($_SESSION['failures'],$_SESSION['locked_until']);$s=$pdo->prepare('UPDATE saas_admin_users SET last_login_at=NOW() WHERE id=?');$s->execute([$admin['id']]);header('Location: dashboard.php',true,303);exit;}
    $failures=(int)($_SESSION['failures']??0)+1;$_SESSION['failures']=$failures;if($failures>=5){$_SESSION['locked_until']=$now+300;$_SESSION['failures']=0;}$error='Identifiants incorrects ou accès temporairement bloqué.';
   }catch(Throwable $e){error_log('FINEA login: '.$e->getMessage());$error='Connexion momentanément indisponible.';}
  }
 }
 elseif (($_POST['action']??'')==='logout') {$_SESSION=[];session_regenerate_id(true);header('Location: index.php',true,303);exit;}
}
// Dashboard logout is processed before the authenticated redirect.
if (!empty($_SESSION['admin_id'])){header('Location: dashboard.php',true,303);exit;}
shell_top('Connexion administrateur','Accès réservé à la supervision Success\'Lab.','dashboard');
echo '<div class="panel" style="max-width:480px;margin:45px auto"><h2>Bienvenue sur FINEA Cloud</h2><p class="sub">Connectez-vous à votre espace de pilotage.</p>';
if($error)echo '<div class="notice error">'.esc($error).'</div>';
echo '<form method="post"><input type="hidden" name="csrf" value="'.esc(csrf_token()).'"><input type="hidden" name="action" value="login"><div class="field"><label for="email">Adresse email</label><input id="email" name="email" type="email" autocomplete="username" required></div><div class="field" style="margin-top:18px"><label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="current-password" required></div><div class="form-actions"><button class="btn primary" type="submit">Se connecter</button></div></form></div>';
shell_bottom();
