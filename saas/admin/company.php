<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
$pdo=require_admin();$t=tenant($pdo);$id=(int)$t['id'];$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 if (!check_csrf()) {http_response_code(403);$err='Session expirée. Recharge la page.';}
 else {
  $fields=['company_name','legal_name','address','phone','email','rccm','tax_number','capital','primary_color','accent_color','surface_color','text_color','muted_color'];$v=[];
  foreach($fields as $f) $v[$f]=trim(is_string($_POST[$f]??null)?$_POST[$f]:'');
  if ($v['company_name']===''||mb_strlen($v['company_name'])>190||mb_strlen($v['legal_name'])>255||strlen($v['email'])>190||($v['email']!==''&&!filter_var($v['email'],FILTER_VALIDATE_EMAIL))||!preg_match('/^#[0-9a-fA-F]{6}$/',$v['primary_color'])||!preg_match('/^#[0-9a-fA-F]{6}$/',$v['accent_color'])||!preg_match('/^#[0-9a-fA-F]{6}$/',$v['surface_color'])||!preg_match('/^#[0-9a-fA-F]{6}$/',$v['text_color'])||!preg_match('/^#[0-9a-fA-F]{6}$/',$v['muted_color'])||mb_strlen($v['address'])>500||mb_strlen($v['capital'])>190||mb_strlen($v['phone'])>100||mb_strlen($v['rccm'])>190||mb_strlen($v['tax_number'])>190) $err='Vérifie le nom, l’email et les couleurs.';
  else {
   try {
    // Image uploads are validated and staged before the SQL transaction.
    $uploads=[];
    foreach (['logo'=>'logo_path','favicon'=>'icon_path'] as $field=>$column) {
      $file=$_FILES[$field]??null;
      if (!$file || $file['error']===UPLOAD_ERR_NO_FILE) continue;
      if (!is_array($file) || $file['error']!==UPLOAD_ERR_OK || $file['size']>2097152 || $file['size']<1 || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Image invalide ou supérieure à 2 Mo.');
      $info=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
      $ext=match($info){'image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp',default=>null};
      if (!$ext || !@getimagesize($file['tmp_name'])) throw new RuntimeException('Formats acceptés : PNG, JPEG, WebP.');
      $dir=__DIR__.'/assets/tenant-media';
      if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) throw new RuntimeException('Dossier média indisponible.');
      $name='tenant-'.$id.'-'.$field.'-'.bin2hex(random_bytes(12)).'.'.$ext;
      if (!move_uploaded_file($file['tmp_name'],$dir.'/'.$name)) throw new RuntimeException('Échec du transfert de l’image.');
      chmod($dir.'/'.$name,0644);
      $uploads[$column]='assets/tenant-media/'.$name;
    }
    $pdo->beginTransaction();
    $s=$pdo->prepare('UPDATE saas_tenants SET company_name=?,legal_name=? WHERE id=?');$s->execute([$v['company_name'],$v['legal_name']?:null,$id]);
    $s=$pdo->prepare('UPDATE saas_tenant_branding SET company_name=?,legal_name=?,address=?,phone=?,email=?,rccm=?,tax_number=?,capital=?,primary_color=?,accent_color=?,surface_color=?,text_color=?,muted_color=?,logo_path=COALESCE(?,logo_path),icon_path=COALESCE(?,icon_path) WHERE tenant_id=?');
    $s->execute([$v['company_name'],$v['legal_name']?:null,$v['address']?:null,$v['phone']?:null,$v['email']?:null,$v['rccm']?:null,$v['tax_number']?:null,$v['capital']?:null,$v['primary_color'],$v['accent_color'],$v['surface_color'],$v['text_color'],$v['muted_color'],$uploads['logo_path']??null,$uploads['icon_path']??null,$id]);
    if ($s->rowCount()===0) { /* Un UPDATE sans changement renvoie aussi 0 : la ligne est déjà créée par TenantService. */ }
    $pdo->commit();header('Location: company.php?id='.$id.'&saved=1',true,303);exit;
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('FINEA company: '.$e->getMessage());$err=$e instanceof RuntimeException ? $e->getMessage() : 'Enregistrement impossible.';}
  }
 }
}
shell_top('Fiche entreprise','Personnalisation et gouvernance de '.(string)$t['company_name']);
echo '<div class="actions" style="margin-bottom:20px"><a class="btn" href="index.php">← Entreprises</a><a class="btn primary" href="company_admin.php?id='.$id.'">Configurer le premier admin</a><a class="btn" href="install.php?id='.$id.'">Assistant d’installation</a></div>';
if($err)echo '<div class="notice error">'.esc($err).'</div>';
if(isset($_GET['saved']))echo '<div class="notice success">Informations de l’entreprise enregistrées.</div>';
echo '<div class="split"><section class="panel"><h2>Identité et coordonnées</h2><p class="sub">Ces paramètres sont propres à cette entreprise.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="'.esc(csrf_token()).'"><div class="form-grid">';
$inputs=[['company_name','Nom commercial *','text'],['legal_name','Raison sociale','text'],['address','Adresse','text'],['phone','Téléphone','text'],['email','Email','email'],['rccm','RCCM','text'],['tax_number','NCC / Numéro fiscal','text'],['capital','Capital social','text'],['surface_color','Fond des pages','color'],['text_color','Couleur du texte','color'],['muted_color','Texte secondaire','color'],['primary_color','Couleur principale','color'],['accent_color','Couleur secondaire','color']];
foreach($inputs as [$key,$label,$type]){
 $value=$_POST[$key]??($key==='company_name'?$t['company_name']:($key==='legal_name'?$t['legal_name']:($t[$key]??'')));
 if($type==='color'&&!preg_match('/^#[0-9a-fA-F]{6}$/',(string)$value))$value=['primary_color'=>'#173B68','accent_color'=>'#D9A441','surface_color'=>'#FFFFFF','text_color'=>'#172033','muted_color'=>'#64748B'][$key]??'#FFFFFF';
 echo '<div class="field '.(in_array($key,['company_name','legal_name','address'],true)?'full':'').'"><label for="'.esc($key).'">'.esc($label).'</label><input id="'.esc($key).'" name="'.esc($key).'" type="'.$type.'" value="'.esc($value).'" '.($key==='company_name'?'required maxlength="190"':'').'></div>';
}
echo '</div><div class="form-grid" style="margin-top:20px"><div class="field"><label for="logo">Logo (PNG, JPG, WebP · 2 Mo max)</label><input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp"></div><div class="field"><label for="favicon">Favicon (PNG, JPG, WebP · 2 Mo max)</label><input type="file" id="favicon" name="favicon" accept="image/png,image/jpeg,image/webp"></div></div><div class="form-actions"><button class="btn primary" type="submit">Enregistrer les modifications</button></div></form></section><aside class="panel"><h2>Environnement FINEA</h2><div class="keyval"><span>Identifiant</span><span>'.esc($t['slug']).'</span></div><div class="keyval"><span>UUID</span><span>'.esc($t['uuid']).'</span></div><div class="keyval"><span>Base prévue</span><span>'.esc($t['database_name']).'</span></div><div class="keyval"><span>Statut</span><span>'.esc($t['status']).'</span></div><div class="notice">L’accès à FINEA sera activé uniquement après création de la base métier, installation de l’application et configuration du routage de ce tenant.</div><div class="footer-actions"><a class="btn" href="company_admin.php?id='.$id.'">Premier administrateur</a><a class="btn" href="install.php?id='.$id.'">Installer FINEA</a><button class="btn" disabled>Ouvrir FINEA · Non installé</button></div></aside></div>';
echo '<section class="panel"><h2>Aperçu du branding de '.esc($t['company_name']).'</h2><p class="sub">Les changements sont visibles après enregistrement. Cet aperçu utilise les données de la plateforme, pas encore le thème ERP.</p>';
$preview=$pdo->prepare('SELECT * FROM saas_tenant_branding WHERE tenant_id=?');$preview->execute([$id]);$b=$preview->fetch(PDO::FETCH_ASSOC)?:[];
$bg=preg_match('/^#[0-9a-fA-F]{6}$/',(string)($b['surface_color']??''))?$b['surface_color']:'#FFFFFF';
$fg=preg_match('/^#[0-9a-fA-F]{6}$/',(string)($b['text_color']??''))?$b['text_color']:'#172033';
$primary=preg_match('/^#[0-9a-fA-F]{6}$/',(string)($b['primary_color']??''))?$b['primary_color']:'#173B68';
$accent=preg_match('/^#[0-9a-fA-F]{6}$/',(string)($b['accent_color']??''))?$b['accent_color']:'#D9A441';
echo '<div class="brand-preview" style="background:'.esc($bg).';color:'.esc($fg).'"><div class="brand-preview-head" style="background:'.esc($primary).'">';
if(!empty($b['logo_path']))echo '<img class="brand-preview-logo" src="'.esc($b['logo_path']).'" alt="Logo entreprise">';
echo '<strong>'.esc($b['company_name']??$t['company_name']).'</strong></div><div class="brand-preview-body"><h3>Bienvenue dans votre espace FINEA</h3><p>'.esc($b['legal_name']??'').'</p><span class="brand-preview-button" style="background:'.esc($accent).';color:#172033">Tableau de bord</span></div></div>';
if(!empty($b['logo_path']))echo '<p class="sub" style="margin-top:12px">Logo enregistré : <a href="'.esc($b['logo_path']).'" target="_blank" rel="noopener">Voir le fichier</a></p>';
echo '</section>';
shell_bottom();
