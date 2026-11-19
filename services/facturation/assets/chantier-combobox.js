(function () {
    'use strict';

    const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr').trim();

    document.querySelectorAll('.js-chantier-combobox').forEach(root => {
        const input = root.querySelector('.js-chantier-input');
        const select = root.querySelector('.js-chantier-select');
        const results = root.querySelector('.js-chantier-results');
        const toggle = root.querySelector('.js-chantier-toggle');
        if (!input || !select || !results) return;

        const options = Array.from(select.options).filter(option => option.value).map(option => ({
            value: option.value,
            label: option.text.trim(),
            search: normalize(option.text),
            option
        }));
        let visible = [];
        let activeIndex = -1;

        const selected = options.find(item => item.value === select.value);
        if (selected) input.value = selected.label;

        function close() {
            results.classList.add('hidden');
            input.setAttribute('aria-expanded', 'false');
            activeIndex = -1;
        }

        function choose(item) {
            select.value = item.value;
            input.value = item.label;
            input.setCustomValidity('');
            select.dispatchEvent(new Event('change', { bubbles: true }));
            const po = document.getElementById('clientPurchaseOrderId');
            if (po) po.value = item.option.dataset.clientPoId || '';
            close();
        }

        function render(query) {
            const needle = normalize(query);
            visible = options.filter(item => !needle || item.search.includes(needle)).slice(0, 80);
            activeIndex = visible.length ? 0 : -1;
            results.innerHTML = '';
            if (!visible.length) {
                results.innerHTML = '<div class="px-4 py-5 text-center text-sm text-slate-500">Aucun chantier actif trouvé</div>';
            } else {
                visible.forEach((item, index) => {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'js-chantier-option flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm text-slate-700 hover:bg-indigo-50 hover:text-indigo-900';
                    button.dataset.index = String(index);
                    button.innerHTML = '<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"><i class="fas fa-building"></i></span><span class="min-w-0 flex-1 truncate"></span><i class="fas fa-check hidden text-emerald-600"></i>';
                    button.querySelector('span.min-w-0').textContent = item.label;
                    if (item.value === select.value) button.lastElementChild.classList.remove('hidden');
                    button.addEventListener('mousedown', event => { event.preventDefault(); choose(item); });
                    results.appendChild(button);
                });
            }
            results.classList.remove('hidden');
            input.setAttribute('aria-expanded', 'true');
            paintActive();
        }

        function paintActive() {
            results.querySelectorAll('.js-chantier-option').forEach((button, index) => {
                button.classList.toggle('bg-indigo-50', index === activeIndex);
                button.classList.toggle('text-indigo-900', index === activeIndex);
            });
        }

        input.addEventListener('focus', () => {
            const current = options.find(item => item.value === select.value);
            render(current && input.value === current.label ? '' : input.value);
        });
        input.addEventListener('input', () => {
            if (select.selectedOptions[0]?.text.trim() !== input.value.trim()) select.value = '';
            input.setCustomValidity('');
            render(input.value);
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (results.classList.contains('hidden')) render(input.value);
                if (visible.length) activeIndex = (activeIndex + (event.key === 'ArrowDown' ? 1 : -1) + visible.length) % visible.length;
                paintActive();
            } else if (event.key === 'Enter' && !results.classList.contains('hidden') && activeIndex >= 0) {
                event.preventDefault(); choose(visible[activeIndex]);
            } else if (event.key === 'Escape') close();
        });
        toggle?.addEventListener('click', () => results.classList.contains('hidden') ? (input.focus(), render('')) : close());
        document.addEventListener('mousedown', event => { if (!root.contains(event.target)) close(); });
        root.closest('form')?.addEventListener('submit', event => {
            if (select.value) return;
            event.preventDefault();
            input.setCustomValidity('Sélectionnez un chantier actif dans la liste.');
            input.reportValidity();
            render(input.value);
        });
    });
})();
