document.addEventListener('DOMContentLoaded', function () {
  const container = document.getElementById('clauses');
  const typeSelect = document.querySelector('select[name="contract_reference"]');
  const addButton = document.getElementById('add-clause');
  if (!container || !typeSelect) return;

  const legacyTitles = new Set([
    'Missions et responsabilites',
    'Confidentialite',
    'Presence, pointage et discipline',
    'Mobilite et affectation',
    'Fin de contrat et preavis'
  ]);

  function currentRows() {
    return Array.from(container.querySelectorAll('.clause'));
  }

  function isBlankOrLegacy() {
    const rows = currentRows();
    if (!rows.length) return true;
    const titles = rows.map(function (row) {
      return String(row.querySelector('input[name="clause_titles[]"]')?.value || '').trim();
    }).filter(Boolean);
    if (!titles.length) return true;
    return titles.length === 5 && titles.every(function (title) { return legacyTitles.has(title); });
  }

  function buildRow(clause) {
    const row = document.createElement('div');
    row.className = 'clause rounded-2xl border bg-slate-50 p-4';

    const top = document.createElement('div');
    top.className = 'grid gap-3 md:grid-cols-[1fr_auto]';

    const title = document.createElement('input');
    title.name = 'clause_titles[]';
    title.value = clause.title || '';
    title.placeholder = 'Titre';
    title.className = 'rounded-xl border bg-white p-3';

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'remove-clause rounded-xl border border-rose-200 px-4 text-rose-700';
    remove.textContent = 'Retirer';
    remove.addEventListener('click', function () { row.remove(); });

    const body = document.createElement('textarea');
    body.name = 'clause_bodies[]';
    body.rows = 4;
    body.className = 'mt-3 w-full rounded-xl border bg-white p-3';
    body.placeholder = 'Contenu';
    body.value = clause.body || '';

    top.append(title, remove);
    row.append(top, body);
    return row;
  }

  async function loadDefaults(force) {
    if (!force && !isBlankOrLegacy()) return;
    const type = String(typeSelect.value || 'AUTRE').toUpperCase();
    try {
      const response = await fetch('employee_contract_clause_presets.php?type=' + encodeURIComponent(type), {
        headers: { Accept: 'application/json' }, cache: 'no-store'
      });
      const data = await response.json();
      if (!response.ok || !data.ok || !Array.isArray(data.clauses)) throw new Error(data.message || 'Erreur');
      container.innerHTML = '';
      data.clauses.forEach(function (clause) { container.appendChild(buildRow(clause)); });
    } catch (error) {
      console.error('[FINEA RH] Chargement clauses par défaut impossible', error);
    }
  }

  const toolbar = addButton?.parentElement;
  if (toolbar && !document.getElementById('load-default-clauses')) {
    const reload = document.createElement('button');
    reload.type = 'button';
    reload.id = 'load-default-clauses';
    reload.className = 'rounded-xl border border-indigo-200 bg-white px-4 py-2 font-bold text-indigo-700';
    reload.textContent = 'Charger les clauses par défaut';
    reload.addEventListener('click', function () { loadDefaults(true); });
    toolbar.appendChild(reload);
  }

  typeSelect.addEventListener('change', function () {
    if (isBlankOrLegacy()) loadDefaults(true);
  });

  loadDefaults(false);
});
