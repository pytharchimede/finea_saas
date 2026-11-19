<?php
require_once __DIR__ . '/auth_check.php';

$pageTitle = 'Moteur Finéa';
$activeMenu = 'moteur';
$initialQuery = trim((string) ($_GET['q'] ?? ''));
$initialType = strtolower(trim((string) ($_GET['type'] ?? 'all')));
$allowedTypes = ['all', 'navigation', 'forms', 'requests', 'documents', 'people', 'organizations', 'projects', 'invoices', 'locations'];
if (!in_array($initialType, $allowedTypes, true)) {
    $initialType = 'all';
}

require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/decaissement/includes/navbar.php';
?>
<style>
    .search-engine-page{min-height:calc(100vh - 8rem);width:100%;max-width:100%;overflow-x:clip;background:radial-gradient(circle at 50% 0,rgba(250,189,2,.13),transparent 24rem),linear-gradient(180deg,#fff 0,#f8fafc 100%);color:#0f172a}
    .search-engine-shell{width:min(1480px,calc(100% - 2rem));margin:0 auto;padding:clamp(3.5rem,9vh,7rem) 0 5rem}
    .search-engine-home{text-align:center}
    .search-engine-brand{display:inline-flex;align-items:center;gap:.85rem;margin-bottom:1.8rem}
    .search-engine-logo{width:3.5rem;height:3.5rem;border-radius:1.25rem;background:#fff;padding:.62rem;box-shadow:0 18px 45px rgba(15,23,42,.12);border:1px solid #e2e8f0}
    .search-engine-title{font-size:clamp(2rem,5vw,3.55rem);font-weight:900;letter-spacing:-.055em;color:#1d2b57}
    .search-engine-title span{color:#fabd02}
    .search-engine-tagline{margin:-.8rem auto 2rem;max-width:44rem;color:#64748b;font-size:.98rem}
    .search-engine-form{position:relative;width:min(760px,100%);margin:0 auto}
    .search-engine-input{width:100%;height:4.15rem;border:1px solid #cbd5e1;border-radius:999px;background:#fff;padding:0 9rem 0 3.8rem;font-size:1.05rem;color:#0f172a;box-shadow:0 14px 35px rgba(15,23,42,.08);outline:none;transition:.2s}
    .search-engine-input:focus{border-color:#1d2b57;box-shadow:0 18px 42px rgba(29,43,87,.16),0 0 0 4px rgba(29,43,87,.08)}
    .search-engine-icon{position:absolute;left:1.45rem;top:50%;transform:translateY(-50%);color:#64748b;font-size:1.1rem}
    .search-engine-submit{position:absolute;right:.48rem;top:.48rem;height:3.2rem;border:0;border-radius:999px;padding:0 1.35rem;background:#1d2b57;color:#fff;font-weight:800;cursor:pointer}
    .search-engine-tabs{display:flex;flex-wrap:wrap;justify-content:center;gap:.55rem;margin:2.1rem auto 0;width:100%;max-width:1320px;padding:.25rem .1rem .7rem}
    .search-engine-tab{white-space:nowrap;border:1px solid #e2e8f0;border-radius:999px;background:#fff;color:#475569;padding:.65rem 1rem;font-size:.82rem;font-weight:800;text-decoration:none;transition:.18s}
    .search-engine-tab:hover,.search-engine-tab.is-active{border-color:#1d2b57;background:#1d2b57;color:#fff}
    .search-engine-summary{display:none;width:min(1100px,100%);margin:2rem auto 1rem;text-align:left;color:#64748b;font-size:.9rem}
    .search-engine-results{display:grid;width:min(1100px,100%);margin:0 auto;gap:.7rem;text-align:left}
    .search-engine-result{display:grid;grid-template-columns:3rem minmax(0,1fr) auto;gap:1rem;align-items:start;padding:1.15rem;border:1px solid #e2e8f0;border-radius:1.25rem;background:rgba(255,255,255,.92);text-decoration:none;color:inherit;box-shadow:0 8px 24px rgba(15,23,42,.04);transition:.18s}
    .search-engine-result:hover{transform:translateY(-2px);border-color:#a5b4fc;box-shadow:0 16px 38px rgba(15,23,42,.09)}
    .search-result-icon{width:3rem;height:3rem;border-radius:1rem;display:grid;place-items:center;background:#eef2ff;color:#1d2b57}
    .search-result-title{font-size:1.02rem;font-weight:850;color:#1e3a8a;line-height:1.35}
    .search-result-subtitle{margin-top:.3rem;color:#64748b;font-size:.88rem;line-height:1.55;overflow-wrap:anywhere}
    .search-result-meta{align-self:center;border-radius:999px;background:#f1f5f9;color:#475569;padding:.4rem .65rem;font-size:.72rem;font-weight:800}
    .search-engine-state{display:none;width:min(1100px,100%);margin:2rem auto 0;border:1px dashed #cbd5e1;border-radius:1.5rem;background:#fff;padding:2.5rem 1rem;text-align:center;color:#64748b}
    .search-engine-state i{display:block;margin-bottom:.8rem;font-size:1.8rem;color:#94a3b8}
    .search-engine-loader{display:none;margin:2.3rem auto;width:2.4rem;height:2.4rem;border:.24rem solid #e2e8f0;border-top-color:#1d2b57;border-radius:50%;animation:search-spin .75s linear infinite}
    @keyframes search-spin{to{transform:rotate(360deg)}}
    mark{background:#fef3c7;color:inherit;border-radius:.2rem;padding:0 .08rem}
    @media(max-width:640px){.search-engine-shell{width:calc(100% - 1rem);padding-top:2.2rem}.search-engine-tabs{justify-content:flex-start}.search-engine-tab{white-space:normal;text-align:center;flex:1 1 calc(50% - .55rem)}.search-engine-input{height:3.8rem;padding-right:3.8rem}.search-engine-submit{width:2.9rem;height:2.9rem;padding:0;font-size:0}.search-engine-submit i{font-size:1rem}.search-engine-result{grid-template-columns:2.65rem minmax(0,1fr)}.search-result-icon{width:2.65rem;height:2.65rem}.search-result-meta{grid-column:2;justify-self:start}}
</style>

<main class="search-engine-page">
    <div class="search-engine-shell">
        <header class="search-engine-home">
            <div class="search-engine-brand">
                <?php if (is_file(__DIR__ . '/img/app_icon.png')): ?>
                    <img class="search-engine-logo" src="img/app_icon.png" alt="Finéa">
                <?php endif; ?>
                <h1 class="search-engine-title">Moteur <span>Finéa</span></h1>
            </div>
            <p class="search-engine-tagline">Recherchez avec vos mots, même avec une faute, ou posez une question naturelle sur les données auxquelles vous avez accès.</p>
            <form id="fineaSearchForm" class="search-engine-form" role="search">
                <i class="fa-solid fa-magnifying-glass search-engine-icon"></i>
                <input id="fineaSearchInput" class="search-engine-input" name="q" type="search" value="<?= htmlspecialchars($initialQuery, ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex. argent, tresorie, devis du chantier SIR…" autocomplete="off" autofocus>
                <button class="search-engine-submit" type="submit"><i class="fa-solid fa-arrow-right mr-2"></i>Rechercher</button>
            </form>
        </header>

        <?php
        $tabs = [
            'all' => ['Tout', 'fa-border-all'],
            'navigation' => ['Modules et pages', 'fa-compass'],
            'forms' => ['Formulaires', 'fa-pen-to-square'],
            'requests' => ['Demandes et tickets', 'fa-inbox'],
            'documents' => ['Documents', 'fa-file-lines'],
            'people' => ['Personnes', 'fa-users'],
            'organizations' => ['Entreprises', 'fa-building'],
            'projects' => ['Chantiers et devis', 'fa-helmet-safety'],
            'invoices' => ['Factures', 'fa-file-invoice-dollar'],
            'locations' => ['Localisations', 'fa-location-dot'],
        ];
        ?>
        <nav class="search-engine-tabs" aria-label="Catégories de recherche">
            <?php foreach ($tabs as $type => [$label, $icon]): ?>
                <a href="?type=<?= urlencode($type) ?>&q=<?= urlencode($initialQuery) ?>" data-search-type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>" class="search-engine-tab<?= $initialType === $type ? ' is-active' : '' ?>">
                    <i class="fa-solid <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> mr-1"></i><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div id="fineaSearchSummary" class="search-engine-summary"></div>
        <div id="fineaSearchLoader" class="search-engine-loader" aria-label="Recherche en cours"></div>
        <section id="fineaSearchResults" class="search-engine-results" aria-live="polite"></section>
        <div id="fineaSearchState" class="search-engine-state">
            <i class="fa-solid fa-magnifying-glass"></i>
            <strong>Saisissez au moins deux caractères</strong>
            <p class="mt-2">Les résultats sont automatiquement limités à vos droits d’accès.</p>
        </div>
    </div>
</main>

<script>
(() => {
    const form = document.getElementById('fineaSearchForm');
    const input = document.getElementById('fineaSearchInput');
    const results = document.getElementById('fineaSearchResults');
    const summary = document.getElementById('fineaSearchSummary');
    const state = document.getElementById('fineaSearchState');
    const loader = document.getElementById('fineaSearchLoader');
    const tabs = Array.from(document.querySelectorAll('[data-search-type]'));
    let type = <?= json_encode($initialType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let controller = null;

    const icons = {
        module:'fa-cubes', page:'fa-window-maximize', form:'fa-pen-to-square', request:'fa-file-signature',
        document:'fa-file-lines', person:'fa-user', client:'fa-building',
        supplier:'fa-truck-field', chantier:'fa-helmet-safety', devis:'fa-file-invoice-dollar',
        ticket:'fa-ticket', invoice:'fa-receipt', location:'fa-location-dot',
        user:'fa-user-gear', role:'fa-user-shield', asset:'fa-truck-moving',
        stock:'fa-boxes-stacked', contract:'fa-file-contract'
    };
    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    const highlight = (value, query) => {
        let safe = escapeHtml(value);
        query.trim().split(/\s+/).filter(Boolean).forEach(token => {
            const escaped = token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            safe = safe.replace(new RegExp(`(${escaped})`, 'ig'), '<mark>$1</mark>');
        });
        return safe;
    };

    async function search(pushHistory = true) {
        const query = input.value.trim();
        results.innerHTML = '';
        summary.style.display = 'none';
        state.style.display = 'none';
        if (query.length < 2) {
            loader.style.display = 'none';
            state.style.display = 'block';
            return;
        }
        if (controller) controller.abort();
        controller = new AbortController();
        loader.style.display = 'block';
        if (pushHistory) {
            const url = new URL(window.location.href);
            url.searchParams.set('q', query);
            url.searchParams.set('type', type);
            history.replaceState({}, '', url);
        }
        try {
            const response = await fetch(`api/portal_global_search.php?q=${encodeURIComponent(query)}&type=${encodeURIComponent(type)}`, {
                headers:{Accept:'application/json'}, signal:controller.signal
            });
            const payload = await response.json();
            const rows = Array.isArray(payload.results) ? payload.results : [];
            const interpretation = String(payload.interpretation || '').trim();
            const normalizedQuery = query.toLocaleLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            const showInterpretation = interpretation && interpretation !== normalizedQuery;
            summary.textContent = `${rows.length} résultat${rows.length > 1 ? 's' : ''} pour « ${query} »${showInterpretation ? ` · Compris comme : ${interpretation}` : ''}`;
            summary.style.display = 'block';
            if (!rows.length) {
                state.innerHTML = '<i class="fa-regular fa-face-frown-open"></i><strong>Aucun résultat accessible</strong><p class="mt-2">Essayez un nom, un numéro, une référence ou une localisation différente.</p>';
                state.style.display = 'block';
                return;
            }
            results.innerHTML = rows.map(row => `
                <a class="search-engine-result" href="${escapeHtml(row.target || '#')}">
                    <span class="search-result-icon"><i class="fa-solid ${icons[row.type] || 'fa-magnifying-glass'}"></i></span>
                    <span>
                        <span class="search-result-title">${highlight(row.label, query)}</span>
                        <span class="search-result-subtitle">${highlight(row.subtitle || 'Ouvrir cette ressource', query)}</span>
                    </span>
                    <span class="search-result-meta">${escapeHtml(row.meta || row.type)}</span>
                </a>`).join('');
        } catch (error) {
            if (error.name === 'AbortError') return;
            state.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><strong>Recherche momentanément indisponible</strong><p class="mt-2">Veuillez réessayer dans quelques instants.</p>';
            state.style.display = 'block';
        } finally {
            loader.style.display = 'none';
        }
    }

    form.addEventListener('submit', event => { event.preventDefault(); search(); });
    tabs.forEach(tab => tab.addEventListener('click', event => {
        event.preventDefault();
        type = tab.dataset.searchType || 'all';
        tabs.forEach(item => item.classList.toggle('is-active', item === tab));
        search();
    }));
    if (input.value.trim().length >= 2) search(false); else state.style.display = 'block';
})();
</script>
<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>
