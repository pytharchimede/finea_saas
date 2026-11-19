(() => {
  'use strict';
  const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  document.querySelectorAll('[data-rh-search-select]').forEach(select => {
    if (select.disabled) return;
    const options = Array.from(select.options).filter(option => option.value).map(option => ({ value: option.value, label: option.textContent.trim() }));
    if (!options.length) return;
    const wrapper = document.createElement('div'); wrapper.className = 'rh-combobox';
    const input = document.createElement('input'); input.type = 'text'; input.id = select.id + '-search';
    input.placeholder = 'Saisir un nom ou un e-mail…'; input.autocomplete = 'off'; input.spellcheck = false;
    input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false'); input.required = select.required;
    const list = document.createElement('div'); list.className = 'rh-combobox-list'; list.id = input.id + '-list'; list.setAttribute('role', 'listbox'); list.hidden = true;
    input.setAttribute('aria-controls', list.id);
    const label = document.querySelector('label[for="' + select.id + '"]'); if (label) label.htmlFor = input.id;
    const chosen = options.find(option => option.value === select.value); input.value = chosen ? chosen.label : '';
    select.dataset.searchEnhanced = 'rh';
    select.before(wrapper); wrapper.append(input, list); select.hidden = true; select.required = false; select.tabIndex = -1;
    let visible = [], active = -1;
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); active = -1; };
    const choose = option => { select.value = option.value; input.value = option.label; input.setCustomValidity(''); select.dispatchEvent(new Event('change', { bubbles: true })); close(); };
    const highlight = index => {
      active = index; const items = list.querySelectorAll('[role="option"]');
      items.forEach((item, i) => { item.setAttribute('aria-selected', String(i === active)); });
      if (items[active]) { input.setAttribute('aria-activedescendant', items[active].id); items[active].scrollIntoView({ block: 'nearest' }); }
    };
    const render = (showAll = false) => {
      const query = normalize(input.value); visible = showAll ? options : options.filter(option => normalize(option.label).includes(query));
      list.replaceChildren(); active = -1; input.removeAttribute('aria-activedescendant');
      visible.forEach((option, index) => {
        const item = document.createElement('div'); item.id = list.id + '-' + index; item.className = 'rh-combobox-option'; item.setAttribute('role', 'option'); item.setAttribute('aria-selected', 'false'); item.textContent = option.label;
        item.addEventListener('mousedown', event => event.preventDefault()); item.addEventListener('click', () => choose(option)); list.append(item);
      });
      if (!visible.length) { const empty = document.createElement('div'); empty.className = 'rh-combobox-empty'; empty.textContent = 'Aucun compte correspondant'; empty.setAttribute('role','status'); list.append(empty); }
      list.hidden = false; input.setAttribute('aria-expanded', 'true');
    };
    input.addEventListener('focus', () => { if (select.value) input.select(); render(Boolean(select.value)); });
    input.addEventListener('input', () => { select.value = ''; input.setCustomValidity('Sélectionnez un compte dans la liste.'); render(); });
    input.addEventListener('keydown', event => {
      if (event.key === 'Escape') { close(); return; }
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') { event.preventDefault(); if (list.hidden) render(); highlight(Math.max(0, Math.min(visible.length - 1, active + (event.key === 'ArrowDown' ? 1 : -1)))); }
      if (event.key === 'Enter' && !list.hidden) { event.preventDefault(); if (visible[active]) choose(visible[active]); else if (visible.length === 1) choose(visible[0]); }
      if (event.key === 'Tab') close();
    });
    input.addEventListener('blur', () => { close(); if (!select.value) input.setCustomValidity('Sélectionnez un compte dans la liste.'); });
    document.addEventListener('click', event => { if (!wrapper.contains(event.target)) close(); });
    select.form?.addEventListener('submit', event => { if (!select.value) { event.preventDefault(); input.setCustomValidity('Sélectionnez un compte dans la liste.'); input.reportValidity(); input.focus(); } });
  });
})();
