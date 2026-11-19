(() => {
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

  document.querySelectorAll('main select').forEach((select, index) => {
    if (select.dataset.searchReady === '1') return;
    select.dataset.searchReady = '1';

    const options = [...select.options].map(option => ({
      value: option.value,
      label: option.textContent.trim(),
      disabled: option.disabled
    }));
    const originalRequired = select.required;
    select.required = false;
    select.style.position = 'absolute';
    select.style.width = '1px';
    select.style.height = '1px';
    select.style.opacity = '0';
    select.style.pointerEvents = 'none';
    select.style.overflow = 'hidden';
    select.setAttribute('tabindex', '-1');

    const wrapper = document.createElement('div');
    wrapper.className = 'relative mt-2';
    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select);

    const input = document.createElement('input');
    input.type = 'search';
    input.autocomplete = 'off';
    input.disabled = select.disabled;
    // La validation native d'un champ de recherche sans nom peut empêcher la
    // soumission avant que le contrôle personnalisé ne puisse désigner la
    // liste concernée. La validation est gérée explicitement au submit.
    input.required = false;
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.placeholder = 'Rechercher et sélectionner…';
    input.className = 'w-full rounded-2xl border border-slate-300 bg-white py-3 pl-11 pr-12 font-semibold outline-none transition focus:border-amber-500 focus:ring-4 focus:ring-amber-100 disabled:bg-slate-100 disabled:text-slate-500';

    const icon = document.createElement('i');
    icon.className = 'fas fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-amber-700';
    const chevron = document.createElement('i');
    chevron.className = 'fas fa-chevron-down pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-slate-400';
    const list = document.createElement('div');
    list.id = `workflowSelectResults${index}`;
    list.setAttribute('role', 'listbox');
    list.className = 'workflow-combobox-list mt-2 max-h-64 w-full overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl';
    list.style.display = 'none';
    input.setAttribute('aria-controls', list.id);
    wrapper.append(icon, input, chevron, list);

    const selectedLabel = () => select.selectedOptions[0]?.value ? select.selectedOptions[0].textContent.trim() : '';
    input.value = selectedLabel();

    const close = () => {
      list.style.display = 'none';
      input.setAttribute('aria-expanded', 'false');
      input.value = selectedLabel();
    };
    const choose = option => {
      select.value = option.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      input.value = option.value ? option.label : '';
      input.setCustomValidity(originalRequired && !option.value ? 'Veuillez sélectionner une valeur.' : '');
      close();
    };
    const render = query => {
      const needle = normalize(query);
      const matches = options.filter(option => !option.disabled && (!needle || normalize(option.label).includes(needle)));
      list.replaceChildren();
      matches.forEach(option => {
        const button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('role', 'option');
        button.className = `block w-full rounded-xl px-4 py-3 text-left transition hover:bg-amber-50 ${option.value === select.value ? 'bg-amber-100 font-black text-amber-950' : 'font-semibold text-slate-700'}`;
        button.textContent = option.label;
        button.addEventListener('mousedown', event => { event.preventDefault(); choose(option); });
        button.addEventListener('click', () => choose(option));
        list.appendChild(button);
      });
      if (!matches.length) {
        const empty = document.createElement('p');
        empty.className = 'p-4 text-center text-sm text-slate-500';
        empty.textContent = 'Aucun résultat';
        list.appendChild(empty);
      }
      list.style.display = 'block';
      input.setAttribute('aria-expanded', 'true');
    };

    input.addEventListener('focus', () => { input.select(); render(''); });
    input.addEventListener('input', () => { select.value = ''; input.setCustomValidity(''); render(input.value); });
    input.addEventListener('keydown', event => {
      const buttons = [...list.querySelectorAll('button')];
      if (event.key === 'Escape') close();
      if (event.key === 'ArrowDown' && buttons.length) { event.preventDefault(); buttons[0].focus(); }
      if (event.key === 'Enter' && buttons.length && list.style.display !== 'none') { event.preventDefault(); buttons[0].dispatchEvent(new MouseEvent('mousedown')); }
    });
    wrapper.addEventListener('keydown', event => {
      if (!['ArrowDown', 'ArrowUp'].includes(event.key) || event.target === input) return;
      const buttons = [...list.querySelectorAll('button')];
      const position = buttons.indexOf(event.target);
      const next = event.key === 'ArrowDown' ? position + 1 : position - 1;
      if (buttons[next]) { event.preventDefault(); buttons[next].focus(); }
    });
    document.addEventListener('mousedown', event => { if (!wrapper.contains(event.target)) close(); });
    select.closest('form')?.addEventListener('submit', event => {
      if (originalRequired && !select.value) {
        event.preventDefault();
        input.setCustomValidity('Veuillez sélectionner une valeur dans la liste.');
        input.reportValidity();
        input.focus();
      }
    });
  });
})();
