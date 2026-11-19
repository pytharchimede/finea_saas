document.addEventListener('DOMContentLoaded', () => {
  const config = window.cashPointConfig || {};
  const form = document.querySelector('form[method="post"]');
  if (!form) return;
  form.id = 'dailyPointPaymentForm';
  const items = [...document.querySelectorAll('.payment-item')];
  const money = value => new Intl.NumberFormat('fr-FR').format(value) + ' FCFA';
  form.insertAdjacentHTML('beforeend', `<div id="cashOverrunModal" class="fixed inset-0 z-[10000] hidden items-center justify-center bg-slate-950/70 p-4"><section class="max-h-[92vh] w-full max-w-3xl overflow-auto rounded-[28px] bg-white p-6 shadow-2xl"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-black uppercase text-rose-700">Dépassement de trésorerie</p><h2 class="text-2xl font-black">Fonds insuffisants pour cette sélection</h2></div><button type="button" data-cash-close class="h-10 w-10 rounded-full bg-slate-100">×</button></div><div class="mt-5 grid grid-cols-3 gap-2 text-center"><div class="rounded-xl bg-slate-100 p-3"><small>Solde réel</small><b class="block">${money(Number(config.balance || 0))}</b></div><div class="rounded-xl bg-rose-50 p-3"><small>Sélection</small><b id="cashModalSelected" class="block text-rose-800">0 FCFA</b></div><div class="rounded-xl bg-amber-50 p-3"><small>Manque</small><b id="cashModalDeficit" class="block text-amber-800">0 FCFA</b></div></div><div class="mt-5 rounded-2xl bg-emerald-50 p-4"><h3 class="font-black text-emerald-950">Créditer une entrée réelle aujourd’hui</h3><div class="mt-3 grid gap-2 sm:grid-cols-2"><input name="entry_label" value="Alimentation complémentaire du jour" class="rounded-xl border p-3"><input id="cashEntryAmount" name="entry_amount" type="number" min="1" placeholder="Montant" class="rounded-xl border p-3"><select name="entry_category" class="rounded-xl border p-3"><option value="apport">Apport de trésorerie</option><option value="encaissement_facture">Encaissement facture</option><option value="pret">Prêt reçu</option><option value="autre">Autre</option></select><input name="entry_reference" placeholder="Référence" class="rounded-xl border p-3"></div><button formnovalidate name="action" value="add_real_entry" class="mt-3 rounded-xl bg-emerald-700 px-4 py-3 font-black text-white">Ajouter l’entrée et recalculer</button></div><div class="mt-4 rounded-2xl bg-amber-50 p-4"><h3 class="font-black text-amber-950">Reporter faute de fonds</h3><p class="mt-1 text-sm">${config.suggested ? 'Date solvable suggérée : ' + new Date(config.suggested + 'T00:00:00').toLocaleDateString('fr-FR') : 'Aucune date solvable détectée sur les 12 prochains mois ; un report provisoire reste possible.'}</p><input name="deferral_reason" value="Insuffisance de trésorerie" class="mt-3 w-full rounded-xl border p-3"><div class="mt-3 flex flex-wrap gap-2"><button formnovalidate name="action" value="defer_processing" class="rounded-xl bg-slate-800 px-4 py-3 font-black text-white">Report pour traitement</button><input name="deferred_until" type="date" min="${config.tomorrow}" value="${config.suggested || ''}" class="rounded-xl border p-3"><button formnovalidate name="action" value="defer_payment" class="rounded-xl bg-amber-600 px-4 py-3 font-black text-white">Report pour décaissement</button></div></div></section></div>`);
  const modal = document.getElementById('cashOverrunModal');
  const selectedAmount = () => items.filter(item => item.checked).reduce((sum, item) => sum + Number(item.dataset.amount || 0), 0);
  const refresh = () => {
    const amount = selectedAmount(), remaining = Number(config.balance || 0) - amount, over = remaining < 0;
    document.getElementById('cashSelectionTotal').textContent = money(amount);
    document.getElementById('cashSelectionState').textContent = items.filter(i => i.checked).length + ' fiche(s) sélectionnée(s)';
    document.getElementById('cashRemaining').textContent = money(remaining);
    document.getElementById('cashAdvice').textContent = over ? 'Insuffisant : alimentez la trésorerie ou reportez.' : 'Solde suffisant pour la sélection.';
    document.getElementById('cashModalSelected').textContent = money(amount);
    document.getElementById('cashModalDeficit').textContent = money(Math.max(0, -remaining));
    document.getElementById('cashEntryAmount').value = Math.max(0, -remaining) || '';
    if (over && amount > 0) { modal.classList.remove('hidden'); modal.classList.add('flex'); }
  };
  items.forEach(item => item.addEventListener('change', () => setTimeout(refresh)));
  document.querySelectorAll('#selectAll,.point-toggle').forEach(item => item.addEventListener('change', () => setTimeout(refresh)));
  document.querySelector('[data-cash-close]').addEventListener('click', () => { modal.classList.add('hidden'); modal.classList.remove('flex'); });
  const pay = [...form.querySelectorAll('button')].find(button => button.textContent.includes('Effectuer le décaissement'));
  if (pay) { pay.name = 'action'; pay.value = 'pay'; pay.addEventListener('click', event => { if (selectedAmount() > Number(config.balance || 0)) { event.preventDefault(); refresh(); } }); }
  refresh();
  if (config.force) { modal.classList.remove('hidden'); modal.classList.add('flex'); }
});
