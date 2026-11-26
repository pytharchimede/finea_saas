<?php
declare(strict_types=1);
$preview=isset($_GET['preview']);
if($preview){require_once __DIR__.'/../auth_check.php';if(!hasPermission('acces_admin')){http_response_code(403);exit('Accès refusé');}}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../model/CompanyBranding.php';
require_once __DIR__.'/SiteContent.php';
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
$db=Database::getConnection();$brand=CompanyBranding::get($db);
try{$row=SiteContent::read($db);}catch(PDOException $e){$row=[];}
$json=$row[$preview?'draft_json':'published_json']??null;$available=$preview||!empty($json);
$data=SiteContent::decode($json,$brand);$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$logo=CompanyBranding::logoUrl('../',$db);$icon=CompanyBranding::iconUrl('../',$db);
$sections=['about'=>'À propos','services'=>'Nos activités','projects'=>'Nos réalisations','news'=>'Actualités'];
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=$h($available?($data['seo_title']?:$brand['company_name']):'Site en préparation')?></title><?php if($preview||!$available):?><meta name="robots" content="noindex,nofollow"><?php else:?><meta name="description" content="<?=$h($data['seo_description'])?>"><?php endif;?><?php if($icon):?><link rel="icon" href="<?=$h($icon)?>"><?php endif;?><link rel="stylesheet" href="../branding_theme.php"><link rel="stylesheet" href="site.css"></head><body class="public-site"><?php if($preview):?><div class="preview-banner">Aperçu privé du brouillon · <a href="index.php">Retour au studio</a></div><?php endif;?>
<header class="public-nav"><div class="public-inner nav-inner"><a class="public-brand" href="#accueil"><?php if($logo):?><img src="<?=$h($logo)?>" alt=""><?php endif;?><strong><?=$h($brand['company_name'])?></strong></a><?php if($available):?><nav aria-label="Navigation"><?php foreach($sections as $key=>$label):if(empty($data[$key]))continue;?><a href="#<?=$key?>"><?=$h($label)?></a><?php endforeach;?><?php if($data['show_contact']):?><a href="#contact">Contact</a><?php endif;?></nav><?php endif;?></div></header>
<main id="accueil"><section class="hero"><div class="public-inner"><span class="kicker"><?=$h($brand['company_name'])?></span><h1><?=$h($available?$data['headline']:'Notre site est en préparation')?></h1><p><?=$h($available?$data['intro']:'Nous vous invitons à revenir prochainement.')?></p><?php if($available&&$data['show_contact']):?><a class="button accent" href="#contact">Nous contacter</a><?php endif;?></div></section>
<?php if($available):?><?php foreach($sections as $key=>$label):if(empty($data[$key]))continue;?><section class="public-section public-inner" id="<?=$key?>"><h2><?=$h($label)?></h2><?php if($key==='about'):?><p class="prose"><?=nl2br($h($data[$key]))?></p><?php else:?><div class="public-grid"><?php foreach($data[$key] as $item):?><article><h3><?=$h($item['title'])?></h3><p><?=$h($item['body'])?></p></article><?php endforeach;?></div><?php endif;?></section><?php endforeach;?>
<?php if($data['show_contact']):?><section id="contact" class="contact"><div class="public-inner"><h2>Contactez-nous</h2><?php foreach(['address'=>'Adresse','phone'=>'Téléphone','email'=>'Email'] as $key=>$label):if(empty($brand[$key]))continue;?><p><strong><?=$label?> :</strong> <?=$h($brand[$key])?></p><?php endforeach;?></div></section><?php endif;?><?php endif;?></main><footer class="public-inner">© <?=date('Y')?> <?=$h($brand['company_name'])?> · Tous droits réservés.</footer></body></html>
