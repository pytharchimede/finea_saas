<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
$pdo=require_admin();
$stats=$pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status='active'),0) AS active, COALESCE(SUM(status='pending'),0) AS pending FROM saas_tenants")->fetch(PDO::FETCH_ASSOC);
$tenants=$pdo->query('SELECT t.id,t.company_name,t.slug,t.status,t.created_at,b.logo_path FROM saas_tenants t LEFT JOIN saas_tenant_branding b ON b.tenant_id=t.id ORDER BY t.id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
shell_top('Portefeuille clients','Gérez vos entreprises, leurs responsables et leurs environnements FINEA.','dashboard');
echo '<div class="actions" style="margin-bottom:22px"><a class="btn primary" href="create.php">+ Nouvelle entreprise</a><form method="post" action="index.php"><input type="hidden" name="csrf" value="'.esc(csrf_token()).'"><input type="hidden" name="action" value="logout"><button class="btn" type="submit">Déconnexion</button></form></div>';
echo '<section class="stats">';
foreach([['Entreprises enregistrées','total','Portefeuille SaaS'],['Entreprises actives','active','En production'],['En attente','pending','À provisionner']] as [$label,$key,$hint]) echo '<div class="stat"><div class="label">'.esc($label).'</div><div class="value">'.(int)$stats[$key].'</div><div class="hint">'.esc($hint).'</div></div>';
echo '</section><section class="panel" id="companies"><h2>Entreprises clientes</h2><p class="sub">Configuration, administrateurs et accès à l’application métier.</p><div class="company-list">';
if (!$tenants) echo '<div class="notice">Aucune entreprise enregistrée.</div>';
foreach($tenants as $t){$id=(int)$t['id'];$active=$t['status']==='active';echo '<article class="company-row"><div class="company-logo">'.(!empty($t['logo_path'])?'<img src="'.esc($t['logo_path']).'" alt="">':esc(mb_strtoupper(mb_substr($t['company_name'],0,1)))).'</div><div class="company-info"><strong>'.esc($t['company_name']).'</strong><small>'.esc($t['slug']).' · '.esc($t['created_at']).'</small></div><span class="badge '.($active?'active':'').'">'.($active?'Active':esc($t['status'])).'</span><div class="links"><a class="btn" href="company.php?id='.$id.'">Configurer</a><a class="btn" href="company_admin.php?id='.$id.'">Premier admin</a></div></article>';}
echo '</div></section><div class="notice">L’accès ERP est indisponible tant que le provisionnement réel de l’entreprise n’est pas terminé.</div>';
shell_bottom();
