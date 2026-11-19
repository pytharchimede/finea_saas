document.addEventListener('DOMContentLoaded', function () {
    const root = document.getElementById('febRouteStep');
    if (!root || root.dataset.skip === '1') return;
    const parent = root.parentElement;
    const ordinary = document.getElementById('febChooseOrdinary');
    const contractual = document.getElementById('febChooseContract');
    const type = document.getElementById('febRouteType');
    const project = document.getElementById('febRouteProject');
    const contract = document.getElementById('febRouteContract');
    const projectSelect = document.getElementById('febContractProject');
    const contractSelect = document.getElementById('febContractSelect');
    const projectNext = document.getElementById('febProjectNext');
    const open = document.getElementById('febOpenContract');
    const status = document.getElementById('febContractStatus');
    const counter = document.getElementById('febRouteCounter');
    const title = document.getElementById('febRouteTitle');
    const formParts = Array.from(parent.children).filter(el => el !== root);
    formParts.forEach(el => { el.dataset.contractRouteHidden = '1'; el.style.display = 'none'; });

    const screen = name => {
        type.classList.toggle('hidden', name !== 'type');
        project.classList.toggle('hidden', name !== 'project');
        contract.classList.toggle('hidden', name !== 'contract');
        counter.textContent = name === 'type' ? '' : name === 'project' ? 'FEB sur contrat' : 'FEB sur contrat';
        title.textContent = name === 'type' ? 'Quel type de FEB souhaitez-vous créer ?' : name === 'project' ? 'À quel chantier le contrat est-il rattaché ?' : 'Quel contrat souhaitez-vous utiliser ?';
    };
    ordinary.addEventListener('click', () => {
        root.classList.add('hidden');
        parent.classList.remove('feb-route-pending');
        formParts.forEach(el => { if (el.dataset.contractRouteHidden === '1') el.style.removeProperty('display'); });
        document.getElementById('febProgress')?.scrollIntoView({behavior: 'smooth', block: 'center'});
    });
    contractual.addEventListener('click', () => screen('project'));
    document.querySelector('[data-route-back="type"]')?.addEventListener('click', () => screen('type'));
    document.querySelector('[data-route-back="project"]')?.addEventListener('click', () => screen('project'));
    projectSelect.addEventListener('change', () => { projectNext.disabled = !projectSelect.value; });
    projectNext.addEventListener('click', () => {
        Array.from(contractSelect.options).forEach((option, index) => { if (index) option.hidden = option.dataset.project !== projectSelect.value; });
        contractSelect.value = '';
        status.classList.add('hidden');
        open.classList.add('pointer-events-none','opacity-40');
        screen('contract');
    });
    contractSelect.addEventListener('change', () => {
        const option = contractSelect.selectedOptions[0];
        const active = option && option.value && option.dataset.status === 'actif';
        status.classList.toggle('hidden', !option?.value);
        status.className = 'mt-3 rounded-xl p-3 text-sm font-bold ' + (active ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800');
        status.textContent = active ? 'Contrat actif : vous pouvez établir sa FEB.' : option?.value ? 'Ce contrat doit être signé par les deux parties avant tout paiement.' : '';
        open.href = active ? `../contrats/paiement.php?id=${encodeURIComponent(option.value)}` : '#';
        open.classList.toggle('pointer-events-none', !active);
        open.classList.toggle('opacity-40', !active);
    });
    screen('type');
});
