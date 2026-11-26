<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
if (!hasPermission('acces_admin')) { http_response_code(403); exit('Accès refusé'); }
require_once __DIR__.'/../model/CompanyBranding.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/SiteContent.php';
$db=Database::getConnection();$brand=CompanyBranding::get($db);SiteContent::install($db);
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$_SESSION['website_csrf']??=bin2hex(random_bytes(32));
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!hash_equals($_SESSION['website_csrf'],(string)($_POST['csrf']??''))){http_response_code(403);exit('Session invalide');}
 try{$action=(string)($_POST['action']??'');if($action==='unpublish'){$db->exec('UPDATE finea_website_content SET published_json=NULL,published_at=NULL WHERE id=1');}elseif(in_array($action,['save','publish'],true)){SiteContent::save($db,SiteContent::fromPost($_POST),$action==='publish');}else{throw new InvalidArgumentException('Action inconnue.');}$_SESSION['website_flash']='Modification enregistrée.';header('Location: index.php');exit;}catch(InvalidArgumentException $e){$error=$e->getMessage();}
}
$row=SiteContent::read($db);$data=SiteContent::decode($row['draft_json']??null,$brand);$published=!empty($row['published_json']);$flash=$_SESSION['website_flash']??'';unset($_SESSION['website_flash']);
$logo=CompanyBranding::logoUrl('../',$db);
?>
<?php
$activeMenu='website';
$pageTitle='Site web institutionnel';
require_once __DIR__.'/../decaissement/includes/header.php';
require_once __DIR__.'/../decaissement/includes/navbar.php';
?>
<link rel="stylesheet" href="studio.css?v=<?= filemtime(__DIR__.'/studio.css') ?>">
<div class="website-studio">
<div class="ws-banner"><div class="ws-identity"><?php if($logo):?><img src="<?=$h($logo)?>" alt="Logo entreprise"><?php else:?><i class="fas fa-globe"></i><?php endif;?><div><small>FINEA · Communication institutionnelle</small><h1>Site web</h1><p><?=$h($brand['company_name'])?> · Gestion et publication du site vitrine</p></div></div><a class="ws-button ws-accent" href="public.php" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> Visiter le site web</a></div>
<nav class="ws-nav" aria-label="Menu du module Site web"><a href="index.php" aria-current="page"><i class="fas fa-home"></i> Tableau de bord</a><a href="#website-home"><i class="fas fa-file-alt"></i> Pages et contenus</a><a href="#website-services"><i class="fas fa-briefcase"></i> Activités</a><a href="#website-projects"><i class="fas fa-building"></i> Réalisations</a><a href="#website-news"><i class="fas fa-newspaper"></i> Actualités</a><a href="#website-seo"><i class="fas fa-sliders-h"></i> Contact et SEO</a></nav>
<main class="studio-wrap">
<section class="ws-quick" aria-label="Accès rapides"><a href="#website-home"><i class="fas fa-pen"></i><span><strong>Modifier les contenus</strong><small>Accueil et présentation</small></span></a><a href="public.php?preview=1" target="_blank" rel="noopener"><i class="fas fa-eye"></i><span><strong>Aperçu privé</strong><small>Dernier brouillon enregistré</small></span></a><a href="#website-publication"><i class="fas fa-globe"></i><span><strong>Publication</strong><small><?=$published?'Site en ligne':'Site non publié'?></small></span></a><a href="../admin/branding_settings.php"><i class="fas fa-palette"></i><span><strong>Identité visuelle</strong><small>Branding de l’entreprise</small></span></a></section>
<section class="panel status" id="website-publication"><strong><?=$published?'Site publié':'Site non publié'?></strong><p><?=$published?'Dernière publication : '.$h($row['published_at']):'Le site public affiche une page d’attente jusqu’à la première publication.'?></p><p>Adresse publique : <a href="public.php" target="_blank" rel="noopener">website/public.php</a></p><?php if($published):?><form method="post"><input type="hidden" name="csrf" value="<?=$h($_SESSION['website_csrf'])?>"><button name="action" value="unpublish">Retirer la publication</button></form><?php endif;?></section>
<?php if($error||$flash):?><p role="status" class="panel"><?=$h($error?:$flash)?></p><?php endif;?>
<form method="post" class="editor"><input type="hidden" name="csrf" value="<?=$h($_SESSION['website_csrf'])?>">
<section class="panel" id="website-home"><h2>Accueil et entreprise</h2><label>Titre principal<input name="headline" maxlength="190" required value="<?=$h($data['headline'])?>"></label><label>Introduction<textarea name="intro" maxlength="1000"><?=$h($data['intro'])?></textarea></label><label>À propos<textarea name="about" rows="6" maxlength="10000"><?=$h($data['about'])?></textarea></label></section>
<?php foreach(['services'=>'Services et activités','projects'=>'Réalisations et chantiers','news'=>'Actualités'] as $key=>$label):?><section class="panel" id="website-<?=$h($key)?>"><h2><?=$h($label)?></h2><p>Une entrée par ligne : titre | description.</p><textarea aria-label="<?=$h($label)?>" name="<?=$key?>" rows="6" maxlength="20000"><?=$h(implode("\n",array_map(static fn($item)=>$item['title'].' | '.$item['body'],$data[$key])))?></textarea></section><?php endforeach;?>
<section class="panel" id="website-seo"><h2>Contact et référencement</h2><label class="check"><input type="checkbox" name="show_contact" value="1" <?=$data['show_contact']?'checked':''?>> Publier les coordonnées du branding de l’entreprise</label><p>Vérifiez les coordonnées avant publication. <a href="../admin/branding_settings.php">Modifier le branding</a></p><label>Titre SEO<input name="seo_title" maxlength="190" value="<?=$h($data['seo_title'])?>"></label><label>Description SEO<textarea name="seo_description" maxlength="320"><?=$h($data['seo_description'])?></textarea></label></section>
<div class="savebar"><button name="action" value="save">Enregistrer le brouillon</button><button class="primary" name="action" value="publish">Publier le site</button></div></form></main></div>
<?php require_once __DIR__.'/../decaissement/includes/footer.php'; ?>
