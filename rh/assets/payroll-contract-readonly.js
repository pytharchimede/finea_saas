document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('payroll-wizard-form');
  if (!form) return;

  const personnelSelect = form.querySelector('select[name="personnel_id"]');
  const periodInput = form.querySelector('input[name="period"]');
  const activeContractInput = form.querySelector('input[name="contract_id"]');
  const contractSelect = document.getElementById('contract-code-select');
  const presetCards = Array.from(form.querySelectorAll('.contract-preset-card'));
  const hasActiveContract = !!activeContractInput && Number(activeContractInput.value || 0) > 0;
  const selectedContractCode = String(contractSelect?.value || '').trim();

  const contractUrl = () => {
    const params = new URLSearchParams();
    const personnelId = String(personnelSelect?.value || '').trim();
    const period = String(periodInput?.value || '').trim();
    if (personnelId) params.set('personnel_id', personnelId);
    if (period) params.set('period', period);
    return `contrats_employes_rh.php${params.toString() ? `?${params.toString()}` : ''}`;
  };

  const replaceWithContractCta = (node, title, text) => {
    if (!node) return;
    node.innerHTML = `
      <div class="rounded-[26px] border border-amber-200 bg-amber-50 px-6 py-6">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p class="text-xs font-black uppercase tracking-[.22em] text-amber-700">Contrat requis</p>
            <h3 class="mt-2 text-xl font-black text-slate-950">${title}</h3>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-amber-900">${text}</p>
          </div>
          <a href="${contractUrl()}" class="inline-flex shrink-0 items-center justify-center rounded-2xl bg-slate-950 px-5 py-3 font-black text-white">
            <i class="fas fa-file-signature mr-2"></i>Définir le contrat salarié
          </a>
        </div>
      </div>`;
  };

  const contractPanel = form.querySelector('[data-step-panel="2"]');
  const gainsPanel = form.querySelector('[data-step-panel="3"]');

  if (hasActiveContract) {
    /* Le contrat associé est une donnée source de la paie : jamais modifiable ici. */
    presetCards.forEach((card) => {
      const isAssociated = card.dataset.contractCode === selectedContractCode;
      card.classList.toggle('hidden', !isAssociated);
      if (isAssociated) {
        card.disabled = true;
        card.classList.remove('hover:border-rose-300', 'hover:bg-rose-50');
        card.classList.add('cursor-default', 'border-emerald-200', 'bg-emerald-50');
      }
    });

    if (contractSelect) {
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'contract_code';
      hidden.value = selectedContractCode;
      contractSelect.name = '';
      contractSelect.disabled = true;
      contractSelect.classList.add('cursor-not-allowed', 'bg-slate-100', 'text-slate-600');
      contractSelect.insertAdjacentElement('afterend', hidden);
    }

    const editDetails = Array.from(form.querySelectorAll('details')).find((node) =>
      node.querySelector('summary')?.textContent?.toLowerCase().includes('modifier la remuneration contractuelle')
    );
    editDetails?.remove();

    const recurringEditor = Array.from(form.querySelectorAll('section')).find((node) =>
      node.textContent?.includes('Ajouter ou modifier une prime recurrente')
    );
    recurringEditor?.remove();

    if (contractPanel) {
      const heading = contractPanel.querySelector('h2');
      const eyebrow = contractPanel.querySelector('p');
      if (heading) heading.textContent = 'Contrat associé au salarié';
      if (eyebrow) eyebrow.textContent = 'Contrat en lecture seule';

      contractPanel.querySelectorAll('a[href="parametrage_paie_rh.php"]').forEach((link) => {
        link.href = contractUrl();
        link.innerHTML = '<i class="fas fa-folder-open mr-2"></i>Ouvrir le dossier contrat';
      });
    }
  } else {
    /* Aucun contrat : la paie ne doit pas servir d'écran de création de contrat. */
    if (contractPanel) {
      const presetsGrid = presetCards[0]?.parentElement;
      if (presetsGrid) presetsGrid.remove();
      const contractBlocks = contractPanel.querySelector('.grid.gap-6.xl\\:grid-cols-2');
      if (contractBlocks) {
        replaceWithContractCta(
          contractBlocks,
          'Aucun contrat actif ne couvre cette période',
          'La paie ne peut pas être préparée tant que le dossier salarié ne contient pas un contrat valide pour la période sélectionnée. Définissez d’abord le contrat dans le module Contrats des employés.'
        );
      }
    }

    if (gainsPanel) {
      const missingAlert = Array.from(gainsPanel.querySelectorAll('div')).find((node) =>
        node.textContent?.includes('Aucun contrat actif trouve')
      );
      if (missingAlert) {
        const createSection = missingAlert.nextElementSibling;
        replaceWithContractCta(
          createSection,
          'Contrat salarié obligatoire',
          'Le salaire catégoriel, le sursalaire, les avantages et les clauses doivent être définis dans le contrat salarié, puis seulement repris en lecture seule par la paie.'
        );
      }
    }

    form.querySelectorAll('[data-step-pill="3"], [data-step-pill="4"]').forEach((button) => {
      button.disabled = true;
      button.classList.add('cursor-not-allowed', 'opacity-40');
      button.title = 'Définissez d’abord un contrat actif pour ce salarié.';
    });

    const nextButton = document.getElementById('wizard-next');
    nextButton?.addEventListener('click', (event) => {
      const contractStepVisible = contractPanel && !contractPanel.classList.contains('hidden');
      if (!contractStepVisible) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      window.alert('Définissez d’abord un contrat actif pour ce salarié avant de poursuivre la paie.');
    }, true);

    form.addEventListener('submit', (event) => {
      const action = event.submitter?.value || '';
      if (!['calculate', 'save', 'save_employee_contract', 'save_contract_salary_component'].includes(action)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      window.alert('Aucun contrat actif ne couvre cette période. Ouvrez le dossier contrat du salarié pour en définir un.');
    }, true);
  }
});
