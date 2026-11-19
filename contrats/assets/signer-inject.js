document.addEventListener('DOMContentLoaded', () => {
  const template = document.getElementById('pvSignerTemplate');
  const form = document.querySelector('form[enctype="multipart/form-data"]');
  if (!template || !form) return;
  form.insertBefore(template.content.cloneNode(true), form.querySelector('section'));
  template.remove();

  const dataNode = document.getElementById('pvPersonnelData');
  const root = document.getElementById('pvSignerCombobox');
  const search = document.getElementById('pvSignerSearch');
  const hidden = document.getElementById('pvSignerId');
  const results = document.getElementById('pvSignerResults');
  const help = document.getElementById('pvSignerHelp');
  if (!dataNode || !root || !search || !hidden || !results) return;

  let people = [];
  try { people = JSON.parse(dataNode.textContent || '[]'); } catch (_) { people = []; }
  let visible = [];
  let activeIndex = -1;
  const normalize = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const label = (person) => `${person.nom} · ${person.fonction}`;
  const close = () => { results.classList.add('hidden'); search.setAttribute('aria-expanded', 'false'); activeIndex = -1; };
  const choose = (person) => {
    hidden.value = String(person.id);
    search.value = label(person);
    search.setCustomValidity('');
    search.classList.remove('border-rose-500', 'ring-4', 'ring-rose-100');
    if (help) help.textContent = person.email ? `Notification destinée à ${person.email}` : 'Aucune adresse e-mail trouvée : une notification Finéa sera créée si un compte est lié.';
    close();
  };
  const render = () => {
    const query = normalize(search.value);
    visible = people.filter((person) => normalize(`${person.nom} ${person.fonction} ${person.matricule || ''} ${person.email || ''}`).includes(query)).slice(0, 20);
    results.replaceChildren();
    if (!visible.length) {
      const empty = document.createElement('p');
      empty.className = 'rounded-xl bg-slate-50 p-4 text-sm text-slate-500';
      empty.textContent = 'Aucun membre du personnel ne correspond à cette recherche.';
      results.appendChild(empty);
    } else visible.forEach((person, index) => {
      const option = document.createElement('button');
      option.type = 'button'; option.role = 'option';
      option.className = 'block w-full rounded-xl px-4 py-3 text-left transition hover:bg-amber-50 focus:bg-amber-50 focus:outline-none';
      option.innerHTML = '<span class="block font-black text-slate-950"></span><span class="mt-1 block text-xs text-slate-500"></span>';
      option.children[0].textContent = person.nom;
      option.children[1].textContent = [person.fonction, person.matricule ? `Matricule ${person.matricule}` : '', person.email || 'E-mail non renseigné'].filter(Boolean).join(' · ');
      option.addEventListener('mouseenter', () => { activeIndex = index; });
      option.addEventListener('click', () => choose(person));
      results.appendChild(option);
    });
    results.classList.remove('hidden'); search.setAttribute('aria-expanded', 'true');
  };

  const selected = people.find((person) => String(person.id) === String(hidden.value));
  if (selected) choose(selected);
  search.addEventListener('focus', () => { search.select(); render(); });
  search.addEventListener('input', () => { hidden.value = ''; search.setCustomValidity(''); render(); });
  search.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') return close();
    if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return;
    event.preventDefault();
    if (results.classList.contains('hidden')) render();
    if (event.key === 'ArrowDown') activeIndex = Math.min(activeIndex + 1, visible.length - 1);
    if (event.key === 'ArrowUp') activeIndex = Math.max(activeIndex - 1, 0);
    if (event.key === 'Enter' && visible[activeIndex]) return choose(visible[activeIndex]);
    [...results.querySelectorAll('[role="option"]')].forEach((option, index) => option.classList.toggle('bg-amber-50', index === activeIndex));
  });
  document.addEventListener('click', (event) => { if (!root.contains(event.target)) close(); });
  form.addEventListener('submit', (event) => {
    if (hidden.value) return;
    event.preventDefault();
    search.setCustomValidity('Sélectionnez le signataire dans la liste proposée.');
    search.classList.add('border-rose-500', 'ring-4', 'ring-rose-100');
    search.reportValidity(); search.focus(); render();
  });
});
