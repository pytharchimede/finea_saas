(() => {
  if (window.contractWorkflowStage !== 'technique') return;
  const input = document.querySelector('input[name="planning_file"]');
  if (!input) return;
  if (document.getElementById('planningDropZone')) return;
  const label = input.closest('label');
  if (!label) return;
  const existingLink = label.querySelector('a[href]');
  if (!window.contractWorkflowEditable) {
    if (!existingLink) return;
    const extension = existingLink.href.split('?')[0].split('.').pop().toLowerCase();
    const viewer = document.createElement('section');
    viewer.className = 'mt-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-5';
    viewer.innerHTML = '<h3 class="font-black text-slate-950">Aperçu du planning transmis</h3>';
    if (extension === 'pdf') viewer.innerHTML += `<iframe title="Planning transmis" src="${existingLink.href}" class="mt-3 h-96 w-full rounded-xl border bg-white"></iframe>`;
    else if (['jpg', 'jpeg', 'png'].includes(extension)) viewer.innerHTML += `<img alt="Planning transmis" src="${existingLink.href}" class="mt-3 max-h-96 rounded-xl border bg-white object-contain">`;
    else viewer.innerHTML += '<p class="mt-2 text-sm text-slate-600">Ce fichier Excel peut être téléchargé depuis le lien ci-dessus.</p>';
    label.insertAdjacentElement('afterend', viewer);
    return;
  }
  input.classList.add('sr-only');
  const zone = document.createElement('section');
  zone.className = 'mt-3 rounded-2xl border-2 border-dashed border-indigo-300 bg-indigo-50 p-6 text-center transition';
  zone.innerHTML = '<i class="fas fa-cloud-arrow-up text-3xl text-indigo-700"></i><h3 class="mt-2 font-black text-slate-950">Déposez le planning ici</h3><p class="mt-1 text-sm text-slate-600">PDF, image ou fichier Excel · cliquez également pour choisir un fichier.</p><div data-preview class="mt-4"></div>';
  label.insertAdjacentElement('afterend', zone);
  const preview = zone.querySelector('[data-preview]');
  let objectUrl = '';
  const clearPreview = () => { if (objectUrl) URL.revokeObjectURL(objectUrl); objectUrl = ''; preview.innerHTML = ''; };
  const showFile = file => {
    clearPreview();
    if (!file) return;
    const name = String(file.name || 'Fichier sélectionné');
    const size = Math.round((file.size || 0) / 1024).toLocaleString('fr-FR') + ' Ko';
    objectUrl = URL.createObjectURL(file);
    const info = `<p class="mb-3 rounded-xl bg-white p-3 text-sm font-bold text-slate-800"><i class="fas fa-paperclip mr-2 text-indigo-700"></i>${name} · ${size}</p>`;
    if (file.type === 'application/pdf') preview.innerHTML = info + `<iframe title="Aperçu du planning" src="${objectUrl}" class="h-80 w-full rounded-xl border bg-white"></iframe>`;
    else if (file.type.startsWith('image/')) preview.innerHTML = info + `<img alt="Aperçu du planning" src="${objectUrl}" class="mx-auto max-h-80 rounded-xl border bg-white object-contain">`;
    else preview.innerHTML = info + '<p class="rounded-xl border border-indigo-200 bg-white p-3 text-sm text-slate-600">Le fichier Excel sera transmis avec le contrat. Son aperçu n’est pas rendu dans le navigateur.</p>';
  };
  const assign = file => {
    if (!file) return;
    const allowed = ['application/pdf', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'image/jpeg', 'image/png'];
    const extension = String(file.name || '').split('.').pop().toLowerCase();
    if (!allowed.includes(file.type) && !['pdf', 'xlsx', 'jpg', 'jpeg', 'png'].includes(extension)) { alert('Planning PDF, XLSX, JPG ou PNG attendu.'); return; }
    if (file.size > 15 * 1024 * 1024) { alert('Le planning doit faire moins de 15 Mo.'); return; }
    const transfer = new DataTransfer(); transfer.items.add(file); input.files = transfer.files; showFile(file);
  };
  zone.addEventListener('click', () => input.click());
  input.addEventListener('change', () => assign(input.files?.[0]));
  ['dragenter', 'dragover'].forEach(eventName => zone.addEventListener(eventName, event => { event.preventDefault(); zone.classList.add('border-indigo-600', 'bg-indigo-100'); }));
  ['dragleave', 'drop'].forEach(eventName => zone.addEventListener(eventName, event => { event.preventDefault(); zone.classList.remove('border-indigo-600', 'bg-indigo-100'); }));
  zone.addEventListener('drop', event => assign(event.dataTransfer?.files?.[0]));
})();
