<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
if (!hasPermission('acces_admin')) { http_response_code(403); exit('Accès refusé'); }
require_once __DIR__.'/../model/CompanyBranding.php';
$brand=CompanyBranding::get();
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Site web | <?=$h($brand['company_name'])?></title><link rel="stylesheet" href="../branding_theme.php"><style>
body{margin:0;background:#f4f6fa;font:16px system-ui,sans-serif}header{background:var(--brand-primary);color:var(--brand-on-primary);padding:24px}header a{color:inherit}main{max-width:1100px;margin:32px auto;padding:0 20px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px}article{background:white;border:1px solid #dce3ec;border-radius:16px;padding:24px}h2{font-size:18px}small{color:#64748b}
</style></head><body><header><a href="../selection_portail.php">← Portail</a><h1>Gestion du site web · <?=$h($brand['company_name'])?></h1></header><main><h2>Préparation du site institutionnel</h2><p>Les fonctionnalités ci-dessous sont prévues pour le futur gestionnaire de contenus. Aucune publication publique n'est encore active.</p><div class="cards"><?php foreach(['Pages et menus','Services et activités','Réalisations et chantiers','Actualités','Médiathèque','Demandes de contact','Référencement SEO','Publication et aperçu'] as $label):?><article><h2><?=$h($label)?></h2><small>À développer</small></article><?php endforeach;?></div></main></body></html>
