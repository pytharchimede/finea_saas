(() => {
  if (window.contractWorkflowStage !== 'juridique') return;
  const source = document.querySelector('textarea[name="data[articles_juridiques]"]');
  if (!source) return;
  const original = source.closest('label');
  if (!original) return;
  let articles = [];
  try { articles = JSON.parse(source.value || '[]'); } catch (_) { articles = [{ titre: 'ARTICLE — CLAUSE PARTICULIÈRE', sous_titre: '', contenu: source.value || '', enumerations: [] }]; }
  if (!Array.isArray(articles)) articles = [];
  original.classList.add('hidden');
  const editor = document.createElement('section');
  editor.className = 'md:col-span-2 rounded-3xl border border-indigo-200 bg-indigo-50 p-5';
  editor.innerHTML = '<div class="flex flex-wrap items-center justify-between gap-3"><div><h3 class="font-black text-slate-950">Articles et clauses structurés</h3><p class="mt-1 text-sm text-slate-600">Ajoutez, supprimez ou réordonnez les articles, sous-titres et énumérations avant signature.</p></div><button type="button" data-add-article class="rounded-xl bg-indigo-700 px-4 py-2 font-bold text-white">Ajouter un article</button></div><div class="mt-5 space-y-4" data-articles></div>';
  const list = editor.querySelector('[data-articles]');
  const escape = value => String(value || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const draw = () => {
    list.innerHTML = '';
    articles.forEach((article, index) => {
      const card = document.createElement('article');
      card.className = 'rounded-2xl border border-indigo-200 bg-white p-4';
      const items = Array.isArray(article.enumerations) ? article.enumerations : [];
      card.innerHTML = `<div class="flex justify-between gap-3"><b class="text-indigo-900">Article ${index + 1}</b><button type="button" data-remove class="text-sm font-bold text-rose-700">Supprimer</button></div><label class="mt-3 block text-sm font-bold">Titre<input data-key="titre" class="mt-1 w-full rounded-xl border p-2" value="${escape(article.titre)}"></label><label class="mt-3 block text-sm font-bold">Sous-titre <span class="font-medium text-slate-400">(optionnel)</span><input data-key="sous_titre" class="mt-1 w-full rounded-xl border p-2" value="${escape(article.sous_titre)}"></label><label class="mt-3 block text-sm font-bold">Texte<textarea data-key="contenu" rows="4" class="mt-1 w-full rounded-xl border p-2">${escape(article.contenu)}</textarea></label><div class="mt-3" data-enums><b class="text-sm">Énumérations <span class="font-medium text-slate-400">(optionnelles)</span></b></div><button type="button" data-add-enum class="mt-3 rounded-lg border px-3 py-2 text-sm font-bold">Ajouter une énumération</button>`;
      const enumArea = card.querySelector('[data-enums]');
      items.forEach((item, enumIndex) => { const row = document.createElement('div'); row.className = 'mt-2 flex gap-2'; row.innerHTML = `<input class="min-w-0 flex-1 rounded-xl border p-2" data-enum="${enumIndex}" value="${escape(item)}"><button type="button" class="rounded-lg border px-3 text-rose-700" data-delete-enum="${enumIndex}">×</button>`; enumArea.appendChild(row); });
      card.addEventListener('input', event => { const key = event.target.dataset.key; if (key) articles[index][key] = event.target.value; const enumIndex = event.target.dataset.enum; if (enumIndex !== undefined) articles[index].enumerations[enumIndex] = event.target.value; });
      card.querySelector('[data-remove]').onclick = () => { articles.splice(index, 1); draw(); };
      card.querySelector('[data-add-enum]').onclick = () => { articles[index].enumerations = items.concat(['']); draw(); };
      card.querySelectorAll('[data-delete-enum]').forEach(button => button.onclick = () => { articles[index].enumerations.splice(Number(button.dataset.deleteEnum), 1); draw(); });
      list.appendChild(card);
    });
  };
  editor.querySelector('[data-add-article]').onclick = () => { articles.push({ titre: 'ARTICLE — NOUVELLE CLAUSE', sous_titre: '', contenu: '', enumerations: [] }); draw(); };
  original.insertAdjacentElement('afterend', editor);
  source.form?.addEventListener('submit', () => {
    source.value = JSON.stringify(articles.map(article => ({
      ...article,
      sous_titre: String(article.sous_titre || '').trim(),
      enumerations: (Array.isArray(article.enumerations) ? article.enumerations : []).map(item => String(item || '').trim()).filter(Boolean)
    })));
  });
  draw();
})();
