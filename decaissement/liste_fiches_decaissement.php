<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
// Page legacy (anciennement Décaissement). Redirigée vers point_financier.php pour le nouveau nom.
if (!isset($_GET['legacy'])) {
    header('Location: point_financier.php?legacy=1');
    exit;
}

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'liste_fiches_decaissement_view',
    'entity_type' => 'fiche_decaissement_list',
    'entity_label' => 'Liste fiches decaissement',
    'page_name' => 'liste_fiches_decaissement.php',
    'details' => [
        'legacy' => 1,
        'beneficiaire' => $_GET['beneficiaire'] ?? null,
        'date_min' => $_GET['date_min'] ?? null,
        'date_max' => $_GET['date_max'] ?? null,
        'view_mode' => 'list',
    ],
]);

$pageTitle = 'Point financier - DécaissApp (Ancien Décaissement)';
$activeMenu = 'pointfinancier';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<div class="container mx-auto py-10 px-4">
    <h1 class="text-3xl font-bold text-indigo-700 mb-6">Point financier (ex Décaissement)</h1>

    <form id="searchForm" class="bg-white rounded-xl shadow p-6 mb-8 flex flex-wrap gap-4 items-end">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">Bénéficiaire</label>
            <input name="beneficiaire" class="form-input rounded border-gray-300" placeholder="Nom..." />
        </div>
        <div class="flex gap-2">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Date min</label>
                <input type="date" name="date_min" class="form-input rounded border-gray-300" />
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">Date max</label>
                <input type="date" name="date_max" class="form-input rounded border-gray-300" />
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Rechercher</button>
        <button type="button" id="resetBtn" class="btn btn-ghost">Réinitialiser</button>
    </form>

    <div class="flex flex-wrap items-center justify-between mb-4">
        <div>
            <span class="font-bold text-indigo-700" id="nbFiches">0</span> résultat(s)
            &nbsp;|&nbsp;
            Montant total : <span class="font-bold text-green-600" id="totalMontant">0</span> FCFA
        </div>
    </div>

    <div id="cards-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8"></div>
</div>
<script>
    const isDemo = (typeof getQueryParam === 'function' && getQueryParam('demo') === '1');
    window.currencyFormatCFA = window.currencyFormatCFA || function(n) {
        try {
            return (Number(n) || 0).toLocaleString('fr-FR') + ' CFA';
        } catch {
            return n + ' CFA';
        }
    };

    function setLoading(state) {
        const wrap = document.getElementById('cards-container');
        if (!state) {
            wrap.querySelectorAll('.skeleton').forEach(el => el.remove());
            return;
        }
        wrap.innerHTML = '';
        for (let i = 0; i < 8; i++) {
            const sk = document.createElement('div');
            sk.className = 'card p-6 bg-white rounded-2xl shadow skeleton';
            sk.style.height = '190px';
            wrap.appendChild(sk);
        }
    }
    async function fetchAndRender() {
        const form = document.getElementById('searchForm');
        const params = new URLSearchParams(new FormData(form));
        const endpoint = isDemo ? ('request/demo_fiches.php?type=decaissement&' + params.toString()) : ('request/fiches_decaissement_list.php?' + params.toString());
        setLoading(true);
        const res = await fetch(endpoint);
        const json = await res.json();
        if (!json.ok) {
            alert('Erreur: ' + (json.error || ''));
            return;
        }
        document.getElementById('nbFiches').textContent = json.count || (json.items?.length || 0);
        document.getElementById('totalMontant').textContent = (json.total || 0).toLocaleString('fr-FR');
        const wrap = document.getElementById('cards-container');
        wrap.innerHTML = (json.items || []).map(cardTemplate).join('');
        bindEvents(wrap);
        setLoading(false);
    }

    function cardTemplate(f) {
        const badgeClass = f.badge ? ('badge badge-' + f.badge) : 'badge badge-gray';
        const badge = f.status ? `<span class="${badgeClass}">${f.status}</span>` : '';
        const mode = f.mode ? `<span class="badge badge-indigo">${f.mode}</span>` : '';
        return `
        <div class="card p-6">
            <div class="card-header">
                <div class="card-title">Fiche N° ${escapeHtml(f.num_fiche||'')}</div>
                <div class="flex gap-2 items-center">${mode}${badge}</div>
            </div>
            <div class="card-subtitle mb-2">${escapeHtml(f.beficiaire_fiche||'')}</div>
            <div class="text-sm text-gray-700">
                <div><strong>Ligne déboursé:</strong> ${escapeHtml(f.ligne_debourse_label||'')}</div>
                <div><strong>Ligne devis:</strong> ${escapeHtml(f.ligne_devis_label||'')}</div>
                <div><strong>Précision:</strong> ${escapeHtml(f.precision||'')}</div>
                <div><strong>Désignation:</strong> ${escapeHtml(f.designation||'')}</div>
            </div>
            <div class="mt-3 flex items-center justify-between">
                <div class="text-green-600 font-bold">${currencyFormatCFA(f.montant_fiche)}</div>
                <div class="text-xs text-gray-400">${formatDateFR(f.date_creat_fiche)}</div>
            </div>
            <div class="mt-4 flex gap-2 justify-end">
                <button data-id="${f.id_fiche}" class="js-decaisser btn btn-primary text-xs">Décaisser</button>
                <button data-id="${f.id_fiche}" class="js-partiel btn btn-ghost text-xs">Partiel</button>
            </div>
        </div>`;
    }

    function bindEvents(scope = document) {
        scope.querySelectorAll('.js-decaisser').forEach(b => b.addEventListener('click', () => fakeAction(b, 'decaisse')));
        scope.querySelectorAll('.js-partiel').forEach(b => b.addEventListener('click', () => fakeAction(b, 'partiel')));
    }
    async function fakeAction(btn, action) {
        btn.disabled = true;
        btn.classList.add('opacity-60');
        setTimeout(() => {
            btn.closest('.card')?.remove();
            btn.disabled = false;
            btn.classList.remove('opacity-60');
            if (typeof showToast === 'function') showToast('Action ' + action + ' enregistrée (démo)', 'success');
        }, 600);
    }

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, s => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            '\'': '&#39;'
        } [s]));
    }

    function formatDateFR(iso) {
        try {
            const d = new Date(iso);
            return d.toLocaleDateString('fr-FR', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        } catch {
            return iso;
        }
    }

    document.getElementById('searchForm').addEventListener('submit', e => {
        e.preventDefault();
        fetchAndRender();
    });
    document.getElementById('resetBtn').onclick = () => {
        document.getElementById('searchForm').reset();
        fetchAndRender();
    };
    document.addEventListener('DOMContentLoaded', () => {
        fetchAndRender();
        if (isDemo && typeof showToast === 'function') showToast('Mode démo: Décaissement', 'info');
    });
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>