document.addEventListener('DOMContentLoaded', () => {
  if (window.contractWorkflowStage !== 'direction') return;
  const main = document.querySelector('main');
  if (!main) return;
  const sections = [...main.children].filter(element => element.tagName === 'SECTION');
  const gantt = sections.find(section => section.textContent.includes('Diagramme de Gantt'));
  const direction = sections.find(section => section.textContent.includes('Contrat consolidé pour la Direction générale'));
  if (!gantt || !direction || gantt === direction) return;
  const prestationsHeading = [...direction.querySelectorAll('h3')].find(title => title.textContent.trim() === 'Prestations contractualisées');
  const prestationsTable = prestationsHeading?.nextElementSibling;
  if (!prestationsTable) return;
  prestationsTable.insertAdjacentElement('afterend', gantt);
  gantt.classList.remove('mt-6', 'shadow-xl');
  gantt.classList.add('m-6', 'mb-2');
  gantt.style.margin = '1.75rem 0 .5rem';
});
