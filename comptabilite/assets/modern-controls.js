'use strict';

// Local, progressively enhanced controls: native fields remain the submitted values.
window.AccountingControls = (() => {
    const instances = new WeakMap();
    let sequence = 0;
    let current = null;
    const normalize = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const fieldLabel = field => field.getAttribute('aria-label') || [...(field.labels || [])]
        .map(label => [...label.childNodes].filter(node => node.nodeType === Node.TEXT_NODE).map(node => node.textContent).join(' ').trim())
        .filter(Boolean).join(' ') || 'Rechercher et sélectionner';

    function enhanceSelect(select) {
        if (instances.has(select) || select.multiple) return;
        const required = select.required;
        const wrapper = element('span', 'search-select');
        const input = element('input', 'search-select-input');
        const icon = element('span', 'search-select-icon', '⌕');
        const chevron = element('span', 'search-select-chevron', '⌄');
        const menu = element('div', 'search-select-menu');
        const status = element('div', 'search-select-status');
        const list = element('div', 'search-select-options');
        const uid = 'accounting-select-' + (++sequence);
        list.id = uid;
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', fieldLabel(select));
        status.setAttribute('role', 'status');
        input.type = 'search';
        input.autocomplete = 'off';
        input.spellcheck = false;
        input.required = required;
        input.disabled = select.disabled;
        input.placeholder = 'Saisir pour rechercher…';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-label', fieldLabel(select));
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-controls', uid);
        input.setAttribute('aria-expanded', 'false');
        icon.setAttribute('aria-hidden', 'true');
        chevron.setAttribute('aria-hidden', 'true');
        select.before(wrapper);
        wrapper.append(select, icon, input, chevron);
        select.classList.add('enhanced-native-select');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');
        select.required = false;
        menu.append(status, list);
        menu.hidden = true;
        document.body.append(menu);
        const options = [...select.options].map(option => ({
            value: option.value, label: option.textContent.trim(),
            search: normalize(option.value + ' ' + option.textContent),
            disabled: option.disabled || (option.parentElement.tagName === 'OPTGROUP' && option.parentElement.disabled)
        }));
        let matches = [], active = -1;
        const refresh = () => {
            const option = select.selectedOptions[0];
            input.value = option && (option.value !== '' || !required) ? option.textContent.trim() : '';
            input.setCustomValidity(required && !select.value ? 'Choisissez une valeur dans la liste.' : '');
            input.removeAttribute('aria-invalid');
        };
        const close = () => {
            menu.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            if (current?.select === select) current = null;
        };
        const position = () => {
            if (menu.hidden) return;
            const rect = input.getBoundingClientRect();
            const viewport = window.visualViewport;
            const top = viewport?.offsetTop || 0;
            const bottom = top + (viewport?.height || window.innerHeight);
            const width = Math.min(Math.max(rect.width, 300), window.innerWidth - 16);
            const below = bottom - rect.bottom - 12;
            const above = rect.top - top - 12;
            const up = below < 190 && above > below;
            const height = Math.max(80, Math.min(330, up ? above : below));
            menu.style.width = width + 'px';
            menu.style.maxHeight = height + 'px';
            menu.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - width - 8)) + 'px';
            menu.style.top = (up ? Math.max(top + 8, rect.top - Math.min(menu.scrollHeight, height) - 5) : rect.bottom + 5) + 'px';
        };
        const highlight = index => {
            active = index;
            [...list.children].forEach((node, i) => node.classList.toggle('highlighted', i === index));
            if (list.children[index]) {
                input.setAttribute('aria-activedescendant', list.children[index].id);
                list.children[index].scrollIntoView({ block: 'nearest' });
            } else input.removeAttribute('aria-activedescendant');
        };
        const choose = option => {
            select.value = option.value;
            refresh(); close();
            select.dispatchEvent(new Event('input', { bubbles: true }));
            select.dispatchEvent(new Event('change', { bubbles: true }));
        };
        const render = query => {
            if (current && current.select !== select) current.close();
            current = { select, wrapper, menu, close, position };
            const words = normalize(query).trim().split(/\s+/).filter(Boolean);
            matches = options.filter(option => !option.disabled && words.every(word => option.search.includes(word)));
            list.replaceChildren();
            status.textContent = matches.length ? matches.length + ' résultat' + (matches.length > 1 ? 's' : '') : 'Aucun résultat — essayez un numéro ou un libellé';
            const fragment = document.createDocumentFragment();
            matches.forEach((option, i) => {
                const node = element('div', 'search-select-option');
                node.id = uid + '-' + i;
                node.setAttribute('role', 'option');
                node.setAttribute('aria-selected', String(option.value === select.value));
                const parts = option.label.split(' · ');
                if (parts.length > 1) {
                    node.append(element('strong', 'search-select-code', parts.shift()), element('span', '', parts.join(' · ')));
                } else node.append(element('span', '', option.label));
                node.addEventListener('pointerdown', event => { if (event.pointerType === 'mouse') event.preventDefault(); });
                node.addEventListener('click', () => choose(option));
                fragment.append(node);
            });
            list.append(fragment);
            menu.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            active = -1;
            input.removeAttribute('aria-activedescendant');
            position();
        };
        input.addEventListener('focus', () => { render(''); input.select(); });
        input.addEventListener('click', () => { if (menu.hidden) render(''); });
        input.addEventListener('input', () => {
            select.selectedIndex = -1;
            input.setCustomValidity(input.value || required ? 'Choisissez une valeur dans la liste.' : '');
            render(input.value);
        });
        input.addEventListener('keydown', event => {
            if (event.isComposing) return;
            if (event.key === 'Escape') { event.preventDefault(); close(); return; }
            if (event.key === 'Tab') { close(); return; }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (menu.hidden) render('');
                if (matches.length) highlight(event.key === 'ArrowDown' ? Math.min(active + 1, matches.length - 1) : (active < 0 ? matches.length - 1 : Math.max(0, active - 1)));
            }
            if (event.key === 'Enter' && !menu.hidden) {
                event.preventDefault();
                if (matches[active]) choose(matches[active]);
                else if (matches.length === 1) choose(matches[0]);
            }
        });
        input.addEventListener('invalid', () => { input.setAttribute('aria-invalid', 'true'); });
        select.addEventListener('change', refresh);
        const reset = () => setTimeout(() => { refresh(); close(); }, 0);
        select.form?.addEventListener('reset', reset);
        refresh();
        instances.set(select, { destroy() { close(); menu.remove(); select.form?.removeEventListener('reset', reset); } });
    }

    function enhanceFile(input) {
        if (instances.has(input)) return;
        const wrapper = element('span', 'file-drop');
        const zone = element('span', 'file-drop-zone');
        const hint = element('span', 'file-drop-hint');
        const preview = element('span', 'file-drop-preview');
        const error = element('span', 'file-drop-error');
        const clearError = element('button', 'file-drop-clear', 'Effacer cette sélection');
        clearError.type = 'button'; clearError.hidden = true;
        const csv = input.accept.includes('csv');
        const limit = (csv ? 1 : 8) * 1024 * 1024;
        const extensions = csv ? ['csv'] : ['pdf', 'jpg', 'jpeg', 'png'];
        const title = element('strong', '', 'Glissez votre fichier ici');
        error.id = 'accounting-file-' + (++sequence);
        error.setAttribute('role', 'alert');
        preview.setAttribute('aria-live', 'polite');
        input.setAttribute('aria-label', fieldLabel(input));
        input.setAttribute('aria-describedby', error.id);
        input.before(wrapper);
        wrapper.append(zone, preview, error, clearError);
        hint.append(title, element('span', '', 'ou cliquez pour parcourir'), element('small', '', (csv ? 'CSV UTF-8 · 1 Mo maximum' : 'PDF, JPEG ou PNG · 8 Mo maximum') + ' · 1 fichier'));
        zone.append(element('span', 'file-drop-icon', '↥'), hint, input);
        input.classList.add('file-drop-native');
        let url = null;
        const clearPreview = () => {
            if (url) URL.revokeObjectURL(url);
            url = null; preview.replaceChildren();
            wrapper.classList.remove('has-file');
        };
        const reject = message => {
            input.value = ''; clearPreview();
            error.textContent = message;
            input.setCustomValidity(message);
            wrapper.classList.add('invalid'); clearError.hidden = false;
        };
        const display = () => {
            clearPreview(); input.setCustomValidity(''); error.textContent = ''; wrapper.classList.remove('invalid'); clearError.hidden = true;
            const file = input.files[0];
            if (!file) return;
            const extension = file.name.split('.').pop().toLowerCase();
            if (!extensions.includes(extension)) { reject(csv ? 'Choisissez un fichier CSV.' : 'Formats acceptés : PDF, JPEG et PNG.'); return; }
            if (file.size === 0 || file.size > limit) { reject('Le fichier doit peser entre 1 octet et ' + (csv ? '1' : '8') + ' Mo.'); return; }
            const badge = element('span', 'file-drop-badge', extension.toUpperCase());
            if (['image/png', 'image/jpeg'].includes(file.type)) {
                url = URL.createObjectURL(file);
                const img = element('img', 'file-drop-thumbnail'); img.src = url; img.alt = 'Aperçu du justificatif sélectionné';
                badge.replaceChildren(img);
            }
            const info = element('span', 'file-drop-info');
            info.append(element('strong', '', file.name), element('small', '', new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 1 }).format(file.size / 1024) + ' Ko · Prêt à joindre'));
            const remove = element('button', 'file-drop-remove', '×');
            remove.type = 'button'; remove.setAttribute('aria-label', 'Retirer ' + file.name);
            remove.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); input.value = ''; display(); input.focus(); });
            preview.append(badge, info, remove); wrapper.classList.add('has-file');
        };
        clearError.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); input.value = ''; display(); });
        input.addEventListener('change', display);
        ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, event => {
            event.preventDefault(); if (input.disabled) return;
            zone.classList.add('drag-over'); if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
        }));
        zone.addEventListener('dragleave', event => { if (!zone.contains(event.relatedTarget)) zone.classList.remove('drag-over'); });
        zone.addEventListener('drop', event => {
            event.preventDefault(); zone.classList.remove('drag-over');
            if (input.disabled) return;
            const files = event.dataTransfer?.files;
            if (!files || files.length !== 1) { reject('Déposez un seul fichier dans cette zone.'); return; }
            const transfer = new DataTransfer(); transfer.items.add(files[0]); input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });
        input.addEventListener('invalid', () => { wrapper.classList.add('invalid'); error.textContent = input.validationMessage; });
        const reset = () => setTimeout(display, 0);
        input.form?.addEventListener('reset', reset);
        display();
        instances.set(input, { destroy() { clearPreview(); input.form?.removeEventListener('reset', reset); } });
    }
    document.addEventListener('pointerdown', event => {
        if (current && !current.wrapper.contains(event.target) && !current.menu.contains(event.target)) current.close();
    });
    document.addEventListener('focusin', event => {
        if (current && !current.wrapper.contains(event.target) && !current.menu.contains(event.target)) current.close();
    });
    document.addEventListener('scroll', event => { if (current && !current.menu.contains(event.target)) current.position(); }, true);
    window.addEventListener('resize', () => current?.position());
    window.visualViewport?.addEventListener('resize', () => current?.position());
    return {
        enhance(root) { root.querySelectorAll('select').forEach(enhanceSelect); root.querySelectorAll('input[type="file"]').forEach(enhanceFile); },
        destroy(root) { root.querySelectorAll('select,input[type="file"]').forEach(field => instances.get(field)?.destroy()); }
    };
})();
