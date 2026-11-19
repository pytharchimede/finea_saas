(() => {
  if (window.contractWorkflowStage !== 'comptabilite') return;
  const target = document.getElementById('contractFinanceSchedule');
  const planning = Array.isArray(window.contractFinancePlanning) ? window.contractFinancePlanning : [];
  if (!target) return;

  const form = target.closest('form');
  const input = name => form?.querySelector(`[name="data[${name}]"]`);
  const percentage = name => Math.max(0, Math.min(100, Number(input(name)?.value || 0)));
  const money = value => Math.round(Number(value) || 0).toLocaleString('fr-FR') + ' FCFA';
  const safe = value => String(value ?? '').replace(/[&<>]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[char]));
  const saved = (() => {
    try { return JSON.parse(document.getElementById('workflowStageData')?.textContent || '{}').planning_poids || {}; } catch (_) { return {}; }
  })();
  const safeAttribute = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));

  // Les scripts historiques écoutent encore « input ». On les neutralise pour
  // préserver la saisie en cours : le calcul se fait uniquement à la sortie du champ.
  document.addEventListener('input', event => {
    if (event.target instanceof HTMLInputElement && (event.target.name === 'data[taux_acompte]' || event.target.name === 'data[retenue_garantie]' || event.target.name === 'data[pourcentage_demarrage]' || event.target.name.startsWith('data[planning_poids]'))) event.stopImmediatePropagation();
  }, true);

  const scheduleDescription = target.closest('section')?.querySelector('p');
  if (scheduleDescription) scheduleDescription.textContent = 'Chaque grand titre correspond à un déboursé. La comptabilité répartit ensuite ce déboursé à 100 % entre les rubriques du planning technique ; le net à payer est calculé pour chaque fraction.';

  const renderWhenNoFieldHasFocus = () => window.setTimeout(() => {
    if (form?.contains(document.activeElement) && document.activeElement instanceof HTMLInputElement) return;
    render();
  }, 0);

  const render = () => {
    const header = target.closest('table')?.querySelector('thead tr');
    if (header) header.innerHTML = '<th class="p-3 text-left">Déboursé / rubrique du planning</th><th class="p-3 text-right">Fraction de paiement</th><th class="p-3 text-right">Brut</th><th class="p-3 text-right">Récup. acompte</th><th class="p-3 text-right">Retenue garantie</th><th class="p-3 text-right">Net à payer</th>';
    if (!planning.length) {
      target.innerHTML = '<tr><td colspan="6" class="p-5 text-center font-semibold text-amber-800">Le département technique doit d’abord renseigner les rubriques du planning d’exécution. Elles apparaîtront ici comme fractions de paiement.</td></tr>';
      return;
    }

    const advance = percentage('taux_acompte') / 100;
    const guarantee = percentage('retenue_garantie') / 100;
    const groups = new Map();
    planning.forEach(item => { if (!groups.has(item.group_id)) groups.set(item.group_id, []); groups.get(item.group_id).push(item); });
    const contractTotal = [...groups.values()].reduce((sum, items) => sum + Number(items[0]?.group_total || 0), 0);
    const advanceAmount = contractTotal * advance;
    const rows = [];
    let grossTotal = 0, recoveryTotal = 0, retentionTotal = 0, netTotal = 0;

    if (advanceAmount > 0) rows.push(`<tr class="border-b bg-amber-50"><td colspan="2" class="p-3 font-black">Acompte contractuel (${(advance * 100).toFixed(2)} %)</td><td class="p-3 text-right">${money(advanceAmount)}</td><td class="p-3 text-right">—</td><td class="p-3 text-right">—</td><td class="p-3 text-right font-black text-emerald-700">${money(advanceAmount)}</td></tr>`);

    for (const [, items] of groups) {
      const groupTotal = Number(items[0].group_total || 0);
      const weightTotal = items.reduce((sum, item) => sum + Math.max(0, Number(saved[item.id] ?? (100 / items.length))), 0);
      const validWeight = Math.abs(weightTotal - 100) < 0.01;
      rows.push(`<tr class="bg-indigo-100 text-indigo-950"><td colspan="2" class="p-3 font-black">${safe(items[0].group)}</td><td class="p-3 text-right font-black">${money(groupTotal)}</td><td colspan="3" class="p-3 text-right font-bold ${validWeight ? '' : 'text-rose-700'}">Fractions : ${weightTotal.toLocaleString('fr-FR')} %${validWeight ? '' : ' · à équilibrer à 100 %'}</td></tr>`);
      let groupNet = 0;

      items.forEach(item => {
        const weight = Math.max(0, Number(saved[item.id] ?? (100 / items.length)));
        const gross = groupTotal * weight / 100;
        const recovery = gross * advance;
        const retention = gross * guarantee;
        const net = Math.max(0, gross - recovery - retention);
        grossTotal += gross; recoveryTotal += recovery; retentionTotal += retention; netTotal += net; groupNet += net;
        const dates = item.start || item.end ? `<small class="block font-medium text-slate-500">${safe(item.start || 'Date à préciser')} → ${safe(item.end || 'Date à préciser')}</small>` : '';
        rows.push(`<tr class="border-b bg-white"><td class="p-3 pl-7"><span class="mr-2 text-indigo-500">↳</span><b>${safe(item.title)}</b>${dates}<span class="mt-2 block text-xs font-semibold text-slate-600">Avancement technique et financier : <b class="text-indigo-800">${weight.toLocaleString('fr-FR')} %</b></span><input type="hidden" data-progress-key="${safeAttribute(item.id)}" name="data[planning_avancement][${safeAttribute(item.id)}]" value="${weight}"></td><td class="p-3 text-right"><input type="number" min="0" max="100" step="0.01" data-weight-group="${safeAttribute(item.group_id)}" data-weight-index="${items.indexOf(item)}" name="data[planning_poids][${safeAttribute(item.id)}]" value="${weight}" class="w-24 rounded-lg border px-2 py-1 text-right"> %</td><td class="p-3 text-right">${money(gross)}</td><td class="p-3 text-right text-rose-700">− ${money(recovery)}</td><td class="p-3 text-right text-rose-700">− ${money(retention)}</td><td class="p-3 text-right font-black text-emerald-700"><button type="button" data-net-editor data-weight-key="${safeAttribute(item.id)}" class="rounded-lg px-2 py-1 text-emerald-700 hover:bg-emerald-50 hover:ring-1 hover:ring-emerald-200" title="Cliquer pour ajuster le net à payer">${money(net)}</button></td></tr>`);
      });

      rows.push(`<tr class="bg-slate-50"><td colspan="2" class="p-3 font-black">Sous-total · ${safe(items[0].group)}</td><td class="p-3 text-right font-black">${money(groupTotal)}</td><td colspan="2" class="p-3 text-right">Net prévisionnel</td><td class="p-3 text-right font-black text-emerald-700">${money(groupNet)}</td></tr>`);
    }

    if (retentionTotal > 0) rows.push(`<tr class="bg-emerald-50"><td colspan="5" class="p-3 font-black">Dernière fraction · restitution de la retenue de garantie</td><td class="p-3 text-right font-black text-emerald-700">+ ${money(retentionTotal)}</td></tr>`);
    rows.push(`<tr class="bg-slate-950 text-white"><td colspan="2" class="p-3 font-black">TOTAL NET À PAYER</td><td class="p-3 text-right">${money(grossTotal + advanceAmount)}</td><td class="p-3 text-right">− ${money(recoveryTotal)}</td><td class="p-3 text-right">− ${money(retentionTotal)}</td><td class="p-3 text-right text-lg font-black text-emerald-300">${money(netTotal + retentionTotal + advanceAmount)}</td></tr>`);
    target.innerHTML = rows.join('');

    const groupFields = groupId => [...target.querySelectorAll('input[data-weight-group]')].filter(candidate => candidate.dataset.weightGroup === String(groupId)).sort((left, right) => Number(left.dataset.weightIndex) - Number(right.dataset.weightIndex));
    const saveField = field => {
      const key = field.name.match(/\[([^\]]+)\]$/)?.[1] || '';
      saved[key] = field.value;
      const progress = [...target.querySelectorAll('input[data-progress-key]')].find(candidate => candidate.dataset.progressKey === key);
      if (progress) progress.value = field.value;
    };
    const adjustFollowingWeights = field => {
      const fields = groupFields(field.dataset.weightGroup);
      const changedIndex = fields.indexOf(field);
      if (changedIndex < 0) return;
      let used = 0;
      fields.slice(0, changedIndex).forEach(previous => {
        const value = Math.max(0, Math.min(100, Number(previous.value) || 0));
        previous.value = value;
        used += value;
        saveField(previous);
      });
      const allowed = Math.max(0, 100 - used);
      const value = Math.max(0, Math.min(allowed, Number(field.value) || 0));
      field.value = value;
      saveField(field);
      const following = fields.slice(changedIndex + 1);
      const remainder = Math.max(0, 100 - used - value);
      let remainingToAssign = remainder;
      following.forEach((next, index) => {
        const nextValue = index === following.length - 1 ? remainingToAssign : Math.round((remainingToAssign / (following.length - index)) * 100) / 100;
        next.value = Math.max(0, nextValue);
        remainingToAssign -= next.value;
        saveField(next);
      });
    };

    target.querySelectorAll('input[name^="data[planning_poids]"]').forEach(field => {
      field.addEventListener('input', () => adjustFollowingWeights(field));
      field.addEventListener('blur', () => { adjustFollowingWeights(field); renderWhenNoFieldHasFocus(); });
    });

    target.querySelectorAll('[data-net-editor]').forEach(editor => {
      editor.addEventListener('click', () => {
        const key = editor.dataset.weightKey || '';
        const weightField = [...target.querySelectorAll('input[name^="data[planning_poids]"]')].find(field => field.name.match(/\[([^\]]+)\]$/)?.[1] === key);
        if (!weightField) return;
        const groupTotal = Number(planning.find(item => item.id === key)?.group_total || 0);
        const factor = 1 - advance - guarantee;
        if (groupTotal <= 0 || factor <= 0) return;
        const netInput = document.createElement('input');
        netInput.type = 'number'; netInput.min = '0'; netInput.step = '1'; netInput.value = String(Math.round(groupTotal * (Number(weightField.value) || 0) / 100 * factor));
        netInput.className = 'w-28 rounded-lg border border-emerald-400 px-2 py-1 text-right font-black text-emerald-700 outline-none ring-2 ring-emerald-100';
        editor.replaceWith(netInput); netInput.focus(); netInput.select();
        const commit = () => {
          weightField.value = Math.max(0, Math.min(100, (Number(netInput.value) || 0) / factor * 100 / groupTotal));
          adjustFollowingWeights(weightField);
          renderWhenNoFieldHasFocus();
        };
        netInput.addEventListener('blur', commit, { once: true });
        netInput.addEventListener('keydown', event => {
          if (event.key === 'Enter') { event.preventDefault(); netInput.blur(); }
          if (event.key === 'Escape') { event.preventDefault(); render(); }
        });
      });
    });
  };

  ['taux_acompte', 'retenue_garantie'].forEach(name => input(name)?.addEventListener('blur', renderWhenNoFieldHasFocus));
  render();
})();
