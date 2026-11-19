(() => {
  'use strict';
  const page = document.getElementById('list');
  if (!page) return;

  // L'action « Propositions » n'est plus exposée sur la validation Finance.
  // On conserve volontairement le workflow sous-jacent afin de ne pas créer
  // de régression sur les données ou les traitements existants.
  page.querySelectorAll('a,button').forEach(action => {
    if (action.textContent.trim() === 'Propositions') action.hidden = true;
  });

  const cardsContainer = page.querySelector('#febSelectionForm .grid.gap-6.sm\\:grid-cols-2.lg\\:grid-cols-3');
  const parseFrDate = value => {
    const match = String(value || '').match(/(\d{2})\/(\d{2})\/(\d{4})/);
    return match ? Date.UTC(Number(match[3]), Number(match[2]) - 1, Number(match[1])) : 0;
  };
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr').trim();
  const cardValue = (card, field) => {
    const checkbox = card.querySelector('.feb-select');
    if (field === 'amount') return Number.parseFloat(checkbox?.dataset.amount || '0') || 0;
    if (field === 'id') return Number.parseInt(checkbox?.value || '0', 10) || 0;
    const dates = card.querySelectorAll('.feb-card-date-value');
    if (field === 'planning_date') return parseFrDate(dates[0]?.textContent || '');
    if (field === 'submitted_at') return parseFrDate(dates[1]?.textContent || '');
    if (field === 'requester') return normalize(card.querySelector('.feb-card-header .text-lg')?.textContent || '');
    if (field === 'chantier') return normalize(card.querySelector('.feb-card-meta-item--wide .feb-card-meta-value')?.textContent || '');
    return 0;
  };
  const sortCards = (field, direction) => {
    if (!cardsContainer) return;
    const multiplier = direction === 'desc' ? -1 : 1;
    const cards = Array.from(cardsContainer.querySelectorAll(':scope > .feb-list-card'));
    cards.sort((a, b) => {
      const av = cardValue(a, field), bv = cardValue(b, field);
      let cmp = typeof av === 'string' ? av.localeCompare(String(bv), 'fr', {numeric:true,sensitivity:'base'}) : av - bv;
      if (cmp === 0) cmp = cardValue(a, 'id') - cardValue(b, 'id');
      return cmp * multiplier;
    });
    cards.forEach(card => cardsContainer.appendChild(card));
  };

  const filterForm = page.querySelector('form[method="get"].mt-5');
  if (filterForm && cardsContainer && !filterForm.querySelector('[name="sort_by"]')) {
    const params = new URLSearchParams(location.search);
    const field = document.createElement('label');
    field.className = 'block';
    field.innerHTML = '<span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Trier par</span><select name="sort_by" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm"><option value="planning_date">Date planning</option><option value="submitted_at">Date de soumission</option><option value="amount">Montant</option><option value="id">N° FEB</option><option value="requester">Demandeur / bénéficiaire</option><option value="chantier">Chantier</option></select>';
    const direction = document.createElement('label');
    direction.className = 'block';
    direction.innerHTML = '<span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Sens</span><select name="sort_dir" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm"><option value="asc">Ascendant</option><option value="desc">Descendant</option></select>';
    filterForm.insertBefore(direction, filterForm.lastElementChild);
    filterForm.insertBefore(field, direction);
    const fieldSelect = field.querySelector('select'), directionSelect = direction.querySelector('select');
    const requestedField = params.get('sort_by') || 'planning_date';
    fieldSelect.value = ['planning_date','submitted_at','amount','id','requester','chantier'].includes(requestedField) ? requestedField : 'planning_date';
    directionSelect.value = params.get('sort_dir') === 'desc' ? 'desc' : 'asc';
    const apply = () => sortCards(fieldSelect.value, directionSelect.value);
    apply(); fieldSelect.addEventListener('change', apply); directionSelect.addEventListener('change', apply);
  }

})();
