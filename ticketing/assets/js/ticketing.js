document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('textarea').forEach((textarea) => {
    textarea.addEventListener('input', () => {
      textarea.style.height = 'auto';
      textarea.style.height = `${textarea.scrollHeight}px`;
    });
  });

  const closeAll = (except = null) => {
    document.querySelectorAll('[data-ticket-select].is-open').forEach((select) => {
      if (select !== except) select.classList.remove('is-open');
    });
  };

  document.querySelectorAll('[data-ticket-select]').forEach((select) => {
    const options = JSON.parse(select.dataset.options || '[]');
    const selectedValues = new Set(JSON.parse(select.dataset.selected || '[]').map(String));
    const multiple = select.dataset.multiple === '1';
    const name = select.dataset.name || '';
    const labelName = select.dataset.labelName || '';
    const input = select.querySelector('input[type="text"]');
    const menu = select.querySelector('.ticket-search-menu');
    const values = select.querySelector('.ticket-search-values');
    const emptyText = select.dataset.emptyText || 'Aucun résultat';
    let selected = [];
    let activeIndex = 0;

    const normalize = (value) => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const optionLabel = (option) => String(option.label || option.name || option.value || '');
    const optionValue = (option) => String(option.value || option.id || optionLabel(option));
    selected = options.filter((option) => selectedValues.has(optionValue(option)));

    const syncHiddenInputs = () => {
      select.querySelectorAll('input[type="hidden"]').forEach((hidden) => hidden.remove());
      selected.forEach((option) => {
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = name;
        hidden.value = optionValue(option);
        select.appendChild(hidden);

        if (!multiple && labelName) {
          const labelHidden = document.createElement('input');
          labelHidden.type = 'hidden';
          labelHidden.name = labelName;
          labelHidden.value = option.name || optionLabel(option);
          select.appendChild(labelHidden);
        }
      });
    };

    const renderValues = () => {
      values.innerHTML = '';
      selected.forEach((option) => {
        const badge = document.createElement('button');
        badge.type = 'button';
        badge.className = 'ticket-search-badge';
        const text = document.createElement('span');
        text.textContent = optionLabel(option);
        const icon = document.createElement('i');
        icon.className = 'fa-solid fa-xmark';
        badge.append(text, icon);
        badge.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          selected = selected.filter((item) => optionValue(item) !== optionValue(option));
          renderValues();
          renderMenu();
          syncHiddenInputs();
          input.focus();
        });
        values.appendChild(badge);
      });
      input.value = !multiple && selected[0] ? optionLabel(selected[0]) : '';
    };

    const filteredOptions = () => {
      const query = normalize(input.value);
      const selectedValues = new Set(selected.map(optionValue));
      return options.filter((option) => {
        if (multiple && selectedValues.has(optionValue(option))) return false;
        if (query === '') return true;
        return normalize(optionLabel(option)).includes(query)
          || normalize(option.email).includes(query)
          || normalize(option.service).includes(query)
          || normalize(option.type).includes(query);
      }).slice(0, 60);
    };

    const choose = (option) => {
      if (multiple) selected.push(option);
      else {
        selected = [option];
        select.classList.remove('is-open');
      }
      renderValues();
      syncHiddenInputs();
      if (multiple) {
        input.focus();
        renderMenu();
      }
    };

    const renderMenu = () => {
      const rows = filteredOptions();
      activeIndex = Math.min(activeIndex, Math.max(rows.length - 1, 0));
      menu.innerHTML = '';
      if (!rows.length) {
        const empty = document.createElement('div');
        empty.className = 'ticket-search-empty';
        empty.textContent = emptyText;
        menu.appendChild(empty);
        return;
      }
      rows.forEach((option, index) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = `ticket-search-option${index === activeIndex ? ' is-active' : ''}`;
        const meta = [option.service, option.email, option.type].filter(Boolean).join(' · ');
        const title = document.createElement('strong');
        title.textContent = optionLabel(option);
        item.appendChild(title);
        if (meta) {
          const details = document.createElement('small');
          details.textContent = meta;
          item.appendChild(details);
        }
        item.addEventListener('mousedown', (event) => {
          event.preventDefault();
          choose(option);
        });
        menu.appendChild(item);
      });
    };

    input.addEventListener('focus', () => {
      closeAll(select);
      select.classList.add('is-open');
      renderMenu();
      if (!multiple && selected[0]) input.select();
    });
    input.addEventListener('input', () => {
      if (!multiple && selected[0] && input.value !== optionLabel(selected[0])) {
        selected = [];
        syncHiddenInputs();
        renderValues();
      }
      activeIndex = 0;
      select.classList.add('is-open');
      renderMenu();
    });
    input.addEventListener('keydown', (event) => {
      const rows = filteredOptions();
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex = Math.min(activeIndex + 1, Math.max(rows.length - 1, 0));
        renderMenu();
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        renderMenu();
      } else if (event.key === 'Enter' && rows[activeIndex]) {
        event.preventDefault();
        choose(rows[activeIndex]);
      } else if (event.key === 'Backspace' && multiple && input.value === '' && selected.length) {
        selected.pop();
        renderValues();
        renderMenu();
        syncHiddenInputs();
      } else if (event.key === 'Escape') {
        select.classList.remove('is-open');
      }
    });

    select.querySelector('.ticket-search-control').addEventListener('click', () => input.focus());
    renderValues();
    syncHiddenInputs();
  });

  document.addEventListener('mousedown', (event) => {
    if (!event.target.closest('[data-ticket-select]')) closeAll();
  });

  document.querySelectorAll('[data-ticket-dropzone]').forEach((dropzone) => {
    const input = dropzone.querySelector('input[type="file"]');
    const form = dropzone.closest('form');
    const preview = form ? form.querySelector('[data-ticket-file-preview]') : null;
    let files = [];

    const iconFor = (name) => {
      const ext = String(name).split('.').pop().toLowerCase();
      if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) return 'fa-file-image';
      if (ext === 'pdf') return 'fa-file-pdf';
      if (['xls', 'xlsx', 'csv'].includes(ext)) return 'fa-file-excel';
      if (['doc', 'docx'].includes(ext)) return 'fa-file-word';
      if (['zip', 'rar', '7z'].includes(ext)) return 'fa-file-zipper';
      return 'fa-file-lines';
    };

    const syncInput = () => {
      const transfer = new DataTransfer();
      files.forEach((file) => transfer.items.add(file));
      input.files = transfer.files;
    };

    const renderPreview = () => {
      if (!preview) return;
      preview.innerHTML = '';
      files.forEach((file, index) => {
        const item = document.createElement('div');
        item.className = 'ticket-file-chip';
        const icon = document.createElement('i');
        icon.className = `fa-solid ${iconFor(file.name)}`;
        const info = document.createElement('span');
        const title = document.createElement('b');
        title.textContent = file.name;
        const size = document.createElement('small');
        size.textContent = `${Math.max(1, Math.round(file.size / 1024)).toLocaleString('fr-FR')} Ko`;
        info.append(title, size);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.setAttribute('aria-label', 'Retirer');
        const removeIcon = document.createElement('i');
        removeIcon.className = 'fa-solid fa-xmark';
        remove.appendChild(removeIcon);
        item.append(icon, info, remove);
        item.querySelector('button').addEventListener('click', () => {
          files.splice(index, 1);
          syncInput();
          renderPreview();
        });
        preview.appendChild(item);
      });
    };

    const addFiles = (fileList) => {
      const existing = new Set(files.map((file) => `${file.name}-${file.size}-${file.lastModified}`));
      Array.from(fileList || []).forEach((file) => {
        const key = `${file.name}-${file.size}-${file.lastModified}`;
        if (!existing.has(key)) {
          existing.add(key);
          files.push(file);
        }
      });
      syncInput();
      renderPreview();
    };

    input.addEventListener('change', () => addFiles(input.files));
    ['dragenter', 'dragover'].forEach((eventName) => {
      dropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        dropzone.classList.add('is-dragover');
      });
    });
    ['dragleave', 'drop'].forEach((eventName) => {
      dropzone.addEventListener(eventName, (event) => {
        event.preventDefault();
        dropzone.classList.remove('is-dragover');
      });
    });
    dropzone.addEventListener('drop', (event) => addFiles(event.dataTransfer.files));
  });
});
