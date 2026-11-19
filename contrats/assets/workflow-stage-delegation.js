(() => {
  if (!window.contractWorkflowEditable || window.contractWorkflowStage === 'direction') return;
  const form = document.querySelector('form[enctype="multipart/form-data"]');
  const select = form?.querySelector('select[name="signataire_personnel_id"]');
  const action = form?.querySelector('input[name="action"]');
  const save = form?.querySelector('button[type="submit"]');
  if (!form || !select || !action || !save) return;
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'mt-5 ml-2 rounded-xl border border-indigo-300 bg-indigo-50 px-5 py-3 font-black text-indigo-900';
  button.innerHTML = '<i class="fas fa-share-from-square mr-2"></i>Transmettre l’ordre de signer';
  button.onclick = () => {
    if (!select.value) { alert('Sélectionnez d’abord le collaborateur qui doit signer cette étape.'); select.focus(); return; }
    if (!confirm('Transmettre l’ordre de signature à ce collaborateur ? Une pré-signature existante sera remplacée.')) return;
    action.value = 'delegate_signer';
    form.requestSubmit();
  };
  save.insertAdjacentElement('afterend', button);
})();
