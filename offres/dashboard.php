<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess('liste_offre.php');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Offre.php';
$pdo = Database::getConnection();
require_once __DIR__ . '/../services/DevisOfferProjectMigrationService.php';
(new DevisOfferProjectMigrationService($pdo))->ensureSchema();

$stats = ['projects'=>0,'quotes'=>0,'principal'=>0,'ts'=>0,'sites'=>0,'value'=>0.0,'documents'=>0];
$recent = [];
foreach ([
    'projects'=>'SELECT COUNT(*) FROM offre_banamur',
    'quotes'=>'SELECT COUNT(*) FROM devis_banamur d WHERE COALESCE(d.masque,0)=0 AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL))',
    'principal'=>'SELECT COUNT(*) FROM devis_offer_classifications WHERE devis_type="PRINCIPAL"',
    'ts'=>'SELECT COUNT(*) FROM devis_offer_classifications WHERE devis_type="TS"',
    'sites'=>'SELECT COUNT(DISTINCT ch.id) FROM chantier_banamur ch INNER JOIN devis_banamur d ON d.id=ch.devis_id WHERE ch.archived_at IS NULL',
    'value'=>'SELECT COALESCE(SUM(d.total_ttc),0) FROM devis_banamur d WHERE COALESCE(d.masque,0)=0 AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL))',
    'documents'=>'SELECT COUNT(*) FROM offre_fichiers_banamur',
] as $key=>$sql) { try { $stats[$key] = $key==='value' ? (float)$pdo->query($sql)->fetchColumn() : (int)$pdo->query($sql)->fetchColumn(); } catch(Throwable $e) {} }
try {
    $recent = $pdo->query('SELECT o.*,cl.nom_client,cl.ncc,(SELECT COUNT(*) FROM devis_banamur d WHERE d.offre_id=o.id_offre AND COALESCE(d.masque,0)=0 AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur ch WHERE ch.devis_id=d.id AND ch.archived_at IS NULL))) quote_count,(SELECT COUNT(*) FROM chantier_banamur ch INNER JOIN devis_banamur d ON d.id=ch.devis_id WHERE d.offre_id=o.id_offre AND ch.archived_at IS NULL) site_count,(SELECT COALESCE(SUM(d.total_ttc),0) FROM devis_banamur d WHERE d.offre_id=o.id_offre AND COALESCE(d.masque,0)=0 AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur ch WHERE ch.devis_id=d.id AND ch.archived_at IS NULL))) project_value FROM offre_banamur o LEFT JOIN client_banamur cl ON cl.id_client=o.client_id ORDER BY o.id_offre DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {
    try { $recent=$pdo->query('SELECT o.*,cl.nom_client,cl.ncc,0 quote_count,0 site_count,0 project_value FROM offre_banamur o LEFT JOIN client_banamur cl ON cl.id_client=o.client_id ORDER BY o.id_offre DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC)?:[]; } catch(Throwable $ignored) {}
}
if (!$recent) {
    try {
        $fallbackProjects = array_reverse((new Offre($pdo))->getAllOffres());
        foreach (array_slice($fallbackProjects, 0, 6) as $project) $recent[] = $project + ['quote_count'=>0,'site_count'=>0,'project_value'=>0];
    } catch(Throwable $ignored) {}
}
$projectClients=[];foreach($recent as $project)$projectClients[(int)($project['id_offre']??0)]=['name'=>(string)($project['nom_client']??'Client non rattaché'),'ncc'=>(string)($project['ncc']??'')];

$pageTitle='Pilotage des projets';$activeMenu='module_offres';$layoutRootBase='../';
require __DIR__.'/../decaissement/includes/header.php';require __DIR__.'/../decaissement/includes/navbar.php';
?>
<style>
 .offres-dashboard { --offres-ink:#172b4d; --offres-muted:#52627a; --offres-line:#d7e0ec; --offres-navy:#1d2b57; --offres-gold:#f4b400; --offres-green:#16805b; --offres-orange:#c66a16; color:var(--offres-ink); }
 .offres-dashboard .offres-hero { border:1px solid #d9e4f0 !important; background:linear-gradient(135deg,#ffffff 0%,#f7fbff 62%,#fff8e7 100%) !important; color:#172b4d !important; box-shadow:0 18px 42px rgba(29,43,87,.10); }
 .offres-dashboard .offres-hero h1 { max-width:720px; color:#172b4d !important; letter-spacing:-.025em; }
 .offres-dashboard .offres-hero p { color:#52627a !important; }
 .offres-dashboard .offres-hero > div:first-child::after { content:""; display:block; width:74px; height:4px; margin-top:24px; border-radius:99px; background:linear-gradient(90deg,#1d2b57 0 58%,#f4b400 58% 100%); }
 .offres-dashboard > div > aside > section, .offres-dashboard > div > section, .offres-dashboard article { border-color:var(--offres-line) !important; background:#fff !important; color:var(--offres-ink); box-shadow:0 14px 32px rgba(29,43,87,.08); }
 .offres-dashboard > div > aside > section, .offres-dashboard > div > section, .offres-dashboard article { position:relative; overflow:hidden; }
 .offres-dashboard > div > aside > section::before, .offres-dashboard > div > section::before, .offres-dashboard article::before { content:""; display:block; position:absolute; inset:0 0 auto; height:4px; background:var(--offres-navy); }
 .offres-dashboard article { --card-accent:#3267b1; --card-soft:#eef4ff; background:linear-gradient(145deg,#ffffff 30%,var(--card-soft) 100%) !important; border-color:#c9d9ee !important; box-shadow:0 12px 28px color-mix(in srgb,var(--card-accent) 13%,transparent); }
 .offres-dashboard article::before { inset:0 auto 0 0; width:5px; height:auto; background:var(--card-accent); }
 .offres-dashboard article:nth-child(3n+2) { --card-accent:#16805b; --card-soft:#edf9f3; border-color:#b9e3cf !important; }
 .offres-dashboard article:nth-child(3n) { --card-accent:#c66a16; --card-soft:#fff7e5; border-color:#f2d28d !important; }
 .offres-dashboard article > div:nth-child(4) > div { box-shadow:0 4px 10px rgba(29,43,87,.04); }
 .offres-dashboard .offres-kpi { position:relative; min-height:104px; overflow:hidden; border:1px solid var(--offres-line); border-radius:16px; background:#f7f9fc !important; color:var(--offres-ink) !important; box-shadow:0 8px 18px rgba(29,43,87,.07); }
 .offres-dashboard .offres-kpi::before { content:""; position:absolute; inset:0 auto 0 0; width:5px; background:var(--offres-navy); }
 .offres-dashboard .offres-kpi:nth-child(2) { background:#eef4ff !important; border-color:#bfd2f2; }
 .offres-dashboard .offres-kpi:nth-child(2)::before { background:#3275c7; }
 .offres-dashboard .offres-kpi:nth-child(3) { background:#edf9f3 !important; border-color:#b9e3cf; }
 .offres-dashboard .offres-kpi:nth-child(3)::before { background:var(--offres-green); }
 .offres-dashboard .offres-kpi:nth-child(4) { background:#fff7e5 !important; border-color:#f2d28d; }
 .offres-dashboard .offres-kpi:nth-child(4)::before { background:var(--offres-orange); }
 .offres-dashboard > div > aside > section:nth-child(2) { background:#eef4ff !important; border-color:#bfd2f2 !important; }
 .offres-dashboard .offres-muted { color:var(--offres-muted) !important; }
 .offres-dashboard .text-slate-500, .offres-dashboard .text-slate-600 { color:var(--offres-muted) !important; }
 .offres-dashboard h1, .offres-dashboard h2, .offres-dashboard h3, .offres-dashboard strong { color:var(--offres-ink) !important; }
 .offres-dashboard article > div:first-child > span:first-child { background:#e8f0ff !important; color:#2458a6 !important; }
 .offres-dashboard article > div:first-child > span:last-child, .offres-dashboard article p, .offres-dashboard article span.text-slate-500 { color:var(--offres-muted) !important; }
 .offres-dashboard article > div:nth-child(4) > div { background:#f5f7fa !important; color:var(--offres-ink) !important; border:1px solid #e3e8ef; }
 .offres-dashboard a { transition:background-color .2s ease, border-color .2s ease, color .2s ease, transform .2s ease, box-shadow .2s ease; }
 .offres-dashboard a[href*="create"] { background:var(--offres-gold) !important; color:#172033 !important; border-color:#d99f00 !important; box-shadow:0 6px 14px rgba(244,180,0,.22); }
 .offres-dashboard a[href*="create"]:hover { background:#ffc928 !important; transform:translateY(-2px); box-shadow:0 9px 18px rgba(244,180,0,.30); }
 .offres-dashboard a[href="liste.php"] { background:var(--offres-navy) !important; color:#fff !important; border-color:var(--offres-navy) !important; box-shadow:0 6px 14px rgba(29,43,87,.18); }
 .offres-dashboard a[href="liste.php"]:hover { background:#2d4278 !important; transform:translateY(-2px); }
 .offres-dashboard aside nav a:not([href*="create"]):not([href="liste.php"]) { background:#edf1f7 !important; color:#263b61 !important; border:1px solid #d5deeb; }
 .offres-dashboard aside nav a:not([href*="create"]):not([href="liste.php"]):hover { background:#dfe8f5 !important; border-color:#aebfd8; }
 .offres-dashboard article > a { background:#f0f7f5 !important; color:#12664c !important; border-color:#9dd2bf !important; }
 .offres-dashboard article > a:hover { background:#dff1ea !important; border-color:#5eaf91 !important; transform:translateY(-2px); }
 .offres-dashboard .offres-action { background:var(--offres-navy) !important; color:#fff !important; }
 .offres-dashboard .offres-action-gold { background:var(--offres-gold) !important; color:#172033 !important; }
 html.finea-dark-mode .offres-dashboard { --offres-ink:#f4f7fb; --offres-muted:#c4cfdf; --offres-line:#3b4d67; }
 html.finea-dark-mode .offres-dashboard .offres-hero { border-color:#3b4d67 !important; background:linear-gradient(135deg,#152238 0%,#192c49 62%,#3b2d19 100%) !important; color:#f4f7fb !important; box-shadow:0 18px 42px rgba(0,0,0,.32); }
 html.finea-dark-mode .offres-dashboard .offres-hero h1 { color:#f4f7fb !important; }
 html.finea-dark-mode .offres-dashboard .offres-hero p { color:#c4cfdf !important; }
 html.finea-dark-mode .offres-dashboard > div > aside > section, html.finea-dark-mode .offres-dashboard > div > section, html.finea-dark-mode .offres-dashboard article { background:#152238 !important; color:var(--offres-ink) !important; border-color:var(--offres-line) !important; box-shadow:0 16px 38px rgba(0,0,0,.3); }
 html.finea-dark-mode .offres-dashboard article { background:linear-gradient(145deg,#152238 30%,color-mix(in srgb,var(--card-accent) 16%,#152238) 100%) !important; border-color:color-mix(in srgb,var(--card-accent) 48%,#3b4d67) !important; }
 html.finea-dark-mode .offres-dashboard .offres-kpi { background:#1c2b42 !important; border-color:#405675; }
 html.finea-dark-mode .offres-dashboard .offres-kpi:nth-child(2) { background:#1d3558 !important; border-color:#466a99; }
 html.finea-dark-mode .offres-dashboard .offres-kpi:nth-child(3) { background:#173b38 !important; border-color:#2d7160; }
 html.finea-dark-mode .offres-dashboard .offres-kpi:nth-child(4) { background:#49351c !important; border-color:#8e6830; }
 html.finea-dark-mode .offres-dashboard > div > aside > section:nth-child(2) { background:#1d3558 !important; border-color:#466a99 !important; }
 html.finea-dark-mode .offres-dashboard .text-slate-950, html.finea-dark-mode .offres-dashboard .text-slate-800, html.finea-dark-mode .offres-dashboard .text-slate-700 { color:#f4f7fb !important; }
 html.finea-dark-mode .offres-dashboard .text-slate-600, html.finea-dark-mode .offres-dashboard .text-slate-500 { color:var(--offres-muted) !important; }
 html.finea-dark-mode .offres-dashboard h1, html.finea-dark-mode .offres-dashboard h2, html.finea-dark-mode .offres-dashboard h3, html.finea-dark-mode .offres-dashboard strong { color:#f4f7fb !important; }
 html.finea-dark-mode .offres-dashboard article > div:first-child > span:first-child { background:#263f69 !important; color:#b9d5ff !important; }
 html.finea-dark-mode .offres-dashboard article > div:nth-child(4) > div { background:#203049 !important; color:#f4f7fb !important; border-color:#3e5573; }
 html.finea-dark-mode .offres-dashboard aside nav a:not([href*="create"]):not([href="liste.php"]) { background:#24344c !important; color:#dbe7f6 !important; border-color:#465b78 !important; }
 html.finea-dark-mode .offres-dashboard aside nav a:not([href*="create"]):not([href="liste.php"]):hover { background:#2d4565 !important; }
 html.finea-dark-mode .offres-dashboard article > a { background:#183d38 !important; color:#9ee2c7 !important; border-color:#397c6c !important; }
 html.finea-dark-mode .offres-dashboard article > a:hover { background:#205247 !important; }
 @media (prefers-reduced-motion:no-preference) { .offres-dashboard article { transition:transform .2s ease, box-shadow .2s ease; } .offres-dashboard article:hover { transform:translateY(-4px); } }
</style>
<main class="offres-dashboard mx-auto max-w-[1500px] px-4 py-7 md:px-6">
 <section class="offres-hero overflow-hidden rounded-[2rem] p-7 md:p-9">
  <div class="grid gap-7 lg:grid-cols-[1fr_auto] lg:items-end"><div><div class="text-xs font-black uppercase tracking-[.22em] text-indigo-600">Centre projets Finéa</div><h1 class="mt-3 text-3xl font-black md:text-4xl">Piloter les projets, des devis aux chantiers</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600 dark:text-slate-300">Un projet regroupe son devis principal, ses travaux supplémentaires, ses pièces commerciales et les chantiers actifs qui en découlent.</p></div><div class="flex flex-wrap gap-3"><a href="liste.php?create=1" class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-black text-slate-950 shadow-lg">+ Nouveau projet</a><a href="liste.php" class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white">Voir le listing</a></div></div>
    <div class="mt-7 grid grid-cols-2 gap-3 md:grid-cols-4"><div class="offres-kpi rounded-2xl p-4"><div class="text-xs offres-muted">Projets</div><div class="mt-1 text-3xl font-black"><?=$stats['projects']?></div></div><div class="offres-kpi rounded-2xl p-4"><div class="text-xs offres-muted">Devis actifs</div><div class="mt-1 text-3xl font-black"><?=$stats['quotes']?></div></div><div class="offres-kpi rounded-2xl p-4"><div class="text-xs text-emerald-700 dark:text-emerald-300">Chantiers actifs</div><div class="mt-1 text-3xl font-black text-emerald-800 dark:text-emerald-200"><?=$stats['sites']?></div></div><div class="offres-kpi rounded-2xl p-4"><div class="text-xs text-amber-700 dark:text-amber-300">Valeur des devis</div><div class="mt-1 text-xl font-black"><?=number_format($stats['value'],0,',',' ')?> FCFA</div></div></div>
 </section>

 <div class="mt-6 grid gap-6 xl:grid-cols-[280px_minmax(0,1fr)]">
  <aside class="space-y-4"><section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900"><div class="text-xs font-black uppercase tracking-[.16em] text-slate-500">Accès rapides</div><nav class="mt-4 grid gap-2"><a href="liste.php?create=1" class="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white">Créer un projet</a><a href="liste.php" class="rounded-xl bg-slate-100 px-4 py-3 text-sm font-bold text-slate-800 dark:bg-slate-800 dark:text-slate-100">Tous les projets</a><a href="../devis/generer.php" class="rounded-xl bg-slate-100 px-4 py-3 text-sm font-bold text-slate-800 dark:bg-slate-800 dark:text-slate-100">Créer un devis</a><a href="parametrage.php" class="rounded-xl bg-slate-100 px-4 py-3 text-sm font-bold text-slate-800 dark:bg-slate-800 dark:text-slate-100">Paramétrage</a></nav></section><section class="rounded-3xl border border-indigo-100 bg-indigo-50 p-5 dark:border-indigo-900 dark:bg-indigo-950/40"><div class="font-black text-indigo-950 dark:text-indigo-100">Structure du portefeuille</div><div class="mt-4 space-y-3 text-sm"><div class="flex justify-between"><span>Devis principaux</span><strong><?=$stats['principal']?></strong></div><div class="flex justify-between"><span>Devis TS</span><strong><?=$stats['ts']?></strong></div><div class="flex justify-between"><span>Documents joints</span><strong><?=$stats['documents']?></strong></div></div></section></aside>
  <section class="min-w-0"><div class="flex flex-wrap items-end justify-between gap-3"><div><div class="text-xs font-black uppercase tracking-[.16em] text-indigo-600">Activité récente</div><h2 class="mt-1 text-2xl font-black text-slate-950 dark:text-white">Derniers projets enregistrés</h2></div><a href="liste.php" class="text-sm font-black text-indigo-700 dark:text-indigo-300">Afficher tout →</a></div><div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3"><?php foreach($recent as $project):?><article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-1 hover:shadow-xl dark:border-slate-700 dark:bg-slate-900"><div class="flex items-start justify-between gap-3"><span class="rounded-full bg-indigo-50 px-3 py-1 text-xs font-black text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200"><?=htmlspecialchars((string)$project['num_offre'])?></span><span class="text-xs text-slate-500"><?=htmlspecialchars((string)$project['date_offre'])?></span></div><h3 class="mt-4 text-lg font-black text-slate-950 dark:text-white"><?=htmlspecialchars((string)$project['reference_offre'])?></h3><p class="mt-1 text-sm text-slate-500"><?=htmlspecialchars((string)$project['commercial_dedie'])?></p><div class="mt-5 grid grid-cols-3 gap-2 text-center"><div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800"><strong class="block"><?=(int)$project['quote_count']?></strong><span class="text-[11px] text-slate-500">Devis</span></div><div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800"><strong class="block"><?=(int)$project['site_count']?></strong><span class="text-[11px] text-slate-500">Chantiers</span></div><div class="rounded-xl bg-slate-50 p-2 dark:bg-slate-800"><strong class="block"><?=number_format((float)$project['project_value']/1000000,1,',',' ')?> M</strong><span class="text-[11px] text-slate-500">FCFA</span></div></div><a href="modifier.php?id=<?=(int)$project['id_offre']?>" class="mt-5 block rounded-xl border border-slate-200 px-4 py-2 text-center text-sm font-black text-slate-700 dark:border-slate-700 dark:text-slate-200">Ouvrir le projet</a></article><?php endforeach;?><?php if(!$recent):?><div class="col-span-full rounded-3xl border border-dashed border-slate-300 p-10 text-center text-slate-500">Aucun projet enregistré.</div><?php endif;?></div></section>
 </div>
</main>
<script>
(() => {
    const clients = <?=json_encode($projectClients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
    document.querySelectorAll('.offres-dashboard a[href^="modifier.php?id="]').forEach((link) => {
        const match = link.getAttribute('href').match(/id=(\d+)/);
        const client = match ? clients[match[1]] : null;
        if (!client) return;
        const card = link.closest('article');
        const title = card?.querySelector('h3');
        if (!card || !title) return;
        const badge = document.createElement('div');
        badge.className = 'mt-3 flex items-center gap-2 rounded-xl bg-sky-50 px-3 py-2 text-sm font-bold text-sky-900 dark:bg-sky-950/40 dark:text-sky-100';
        badge.innerHTML = '<i class="fa-solid fa-building"></i><span></span>';
        badge.querySelector('span').textContent = client.name || 'Client non rattaché';
        if (client.ncc) {
            const ncc = document.createElement('small');
            ncc.className = 'ml-auto font-mono text-xs font-normal';
            ncc.textContent = client.ncc;
            badge.appendChild(ncc);
        }
        title.insertAdjacentElement('afterend', badge);
    });
})();
</script>
<?php require __DIR__.'/../decaissement/includes/footer.php'; ?>
