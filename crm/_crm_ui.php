<?php

function crm_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function crm_page_open(): string
{
    return '<link rel="stylesheet" href="../assets/css/crm-theme.css?v=20261126"><div class="crm-page min-h-screen pb-12"><div class="container mx-auto px-4 py-8">';
}

function crm_page_close(): string
{
    return '</div></div>';
}

function crm_hero(string $eyebrow, string $title, string $subtitle, array $actions = []): string
{
    $html = '<section class="crm-hero relative overflow-hidden rounded-[32px] border border-slate-900/10 bg-gradient-to-r from-slate-950 via-[#1d2b57] to-slate-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">';
    $html .= '<div class="absolute -right-16 -top-16 h-52 w-52 rounded-full bg-[#fabd02]/20 blur-2xl"></div>';
    $html .= '<div class="absolute bottom-0 right-28 h-28 w-28 rounded-full bg-sky-300/20 blur-2xl"></div>';
    $html .= '<div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">';
    $html .= '<div class="max-w-3xl"><p class="text-xs font-semibold uppercase tracking-[0.35em] text-amber-200">' . crm_h($eyebrow) . '</p>';
    $html .= '<h1 class="mt-3 text-3xl font-semibold tracking-tight md:text-5xl">' . crm_h($title) . '</h1>';
    $html .= '<p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">' . crm_h($subtitle) . '</p></div>';
    if ($actions) {
        $html .= '<div class="flex flex-wrap gap-3">';
        foreach ($actions as $action) {
            $variant = (string)($action['variant'] ?? 'ghost');
            $class = $variant === 'primary'
                ? 'crm-accent shadow-lg'
                : ($variant === 'light'
                    ? 'crm-light shadow-lg'
                    : 'crm-ghost border');
            $html .= '<a href="' . crm_h($action['href'] ?? '#') . '" class="inline-flex items-center rounded-2xl px-5 py-3 text-sm font-semibold transition hover:-translate-y-0.5 ' . $class . '">';
            if (!empty($action['icon'])) {
                $html .= '<i class="fas ' . crm_h($action['icon']) . ' mr-2"></i>';
            }
            $html .= crm_h($action['label'] ?? 'Ouvrir') . '</a>';
        }
        $html .= '</div>';
    }
    return $html . '</div></section>';
}

function crm_module_tabs(string $active): string
{
    $tabs = [
        'dashboard' => ['href' => 'dashboard_crm.php', 'label' => 'Pilotage', 'icon' => 'fa-gauge-high', 'desc' => 'KPI, relances et agenda.'],
        'prospects' => ['href' => 'prospects.php', 'label' => 'Prospects', 'icon' => 'fa-user-plus', 'desc' => 'Qualification commerciale.'],
        'opportunites' => ['href' => 'opportunites.php', 'label' => 'Pipeline', 'icon' => 'fa-chart-line', 'desc' => 'Kanban et prochaines actions.'],
        'clients' => ['href' => 'clients.php', 'label' => 'Clients', 'icon' => 'fa-building-user', 'desc' => 'Portefeuille client.'],
        'rdv' => ['href' => 'rdv.php', 'label' => 'RDV', 'icon' => 'fa-calendar-check', 'desc' => 'Rendez-vous et agenda.'],
    ];
    $html = '<section class="mt-6 mb-8 grid gap-4 md:grid-cols-2 xl:grid-cols-5">';
    foreach ($tabs as $key => $tab) {
        $isActive = $active === $key;
        $cardClass = $isActive ? 'crm-tab crm-tab-active' : 'crm-tab';
        $iconClass = $isActive ? 'crm-tab-icon' : 'crm-tab-icon';
        $descClass = $isActive ? 'crm-tab-desc' : 'crm-tab-desc';
        $html .= '<a href="' . crm_h($tab['href']) . '" class="rounded-[26px] border p-5 shadow-[0_16px_45px_rgba(15,23,42,0.05)] transition hover:-translate-y-0.5 ' . $cardClass . '">';
        $html .= '<div class="flex items-start gap-4"><span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl ' . $iconClass . '"><i class="fas ' . crm_h($tab['icon']) . '"></i></span>';
        $html .= '<span><span class="block font-semibold">' . crm_h($tab['label']) . '</span><span class="mt-1 block text-sm ' . $descClass . '">' . crm_h($tab['desc']) . '</span></span></div></a>';
    }
    return $html . '</section>';
}

function crm_flash_box(string $message, string $type = 'success'): string
{
    if ($message === '') {
        return '';
    }
    $tone = $type === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800';
    return '<div class="mb-5 rounded-2xl border px-5 py-4 text-sm font-semibold shadow-sm ' . $tone . '">' . crm_h($message) . '</div>';
}

function crm_card_class(): string
{
    return 'crm-card rounded-[30px] border p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]';
}

function crm_action_button(string $href, string $label, string $icon = 'fa-plus', string $variant = 'primary'): string
{
    $class = $variant === 'light'
        ? 'crm-light border'
        : ($variant === 'dark'
            ? 'crm-primary'
            : 'crm-accent');
    return '<a href="' . crm_h($href) . '" class="inline-flex items-center rounded-2xl px-5 py-3 text-sm font-semibold shadow-sm transition hover:-translate-y-0.5 ' . $class . '"><i class="fas ' . crm_h($icon) . ' mr-2"></i>' . crm_h($label) . '</a>';
}

function crm_export_buttons(string $dataset): string
{
    $dataset = rawurlencode($dataset);
    return '<div class="flex flex-wrap gap-2">'
        . crm_action_button('export_crm.php?dataset=' . $dataset . '&format=pdf', 'PDF direction', 'fa-file-pdf', 'dark')
        . crm_action_button('export_crm.php?dataset=' . $dataset . '&format=xlsx', 'Excel', 'fa-file-excel', 'light')
        . crm_action_button('export_crm.php?dataset=' . $dataset . '&format=csv', 'CSV', 'fa-file-csv', 'light')
        . '</div>';
}

function crm_interaction_assets(): string
{
    return <<<'HTML'
<style>
.crm-select-search{position:relative}
.crm-select-search input{width:100%;border:1px solid #e2e8f0;border-radius:16px;padding:.72rem .9rem;background:#fff;outline:none}
.crm-select-search input:focus{box-shadow:0 0 0 4px rgba(250,189,2,.18);border-color:#f59e0b}
.crm-select-search-list{position:absolute;z-index:50;left:0;right:0;top:calc(100% + .35rem);max-height:260px;overflow:auto;border:1px solid #e2e8f0;border-radius:18px;background:#fff;box-shadow:0 20px 55px rgba(15,23,42,.16);padding:.35rem}
.crm-select-search-list[hidden]{display:none}
.crm-select-search-option{display:block;width:100%;text-align:left;border:0;background:#fff;border-radius:14px;padding:.7rem .8rem;color:#0f172a}
.crm-select-search-option:hover,.crm-select-search-option.is-active{background:#fff7ed;color:#9a3412}
.crm-kanban-card{cursor:grab}
.crm-kanban-card:active{cursor:grabbing}
.crm-kanban-column.is-drop-target{outline:3px solid rgba(250,189,2,.55);outline-offset:4px;background:rgba(255,247,237,.95)}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-crm-select-search]').forEach(function (wrap) {
    const input = wrap.querySelector('[data-crm-select-input]');
    const hidden = wrap.querySelector('input[type="hidden"]');
    const list = wrap.querySelector('[data-crm-select-list]');
    const options = Array.from(wrap.querySelectorAll('[data-crm-select-option]'));
    if (!input || !hidden || !list) return;
    const open = () => { list.hidden = false; };
    const close = () => { setTimeout(() => { list.hidden = true; }, 120); };
    const filter = () => {
      const q = input.value.trim().toLowerCase();
      options.forEach(btn => { btn.hidden = q && !btn.textContent.toLowerCase().includes(q); });
      open();
    };
    options.forEach(btn => btn.addEventListener('click', () => {
      hidden.value = btn.dataset.value || '';
      input.value = btn.textContent.trim();
      list.hidden = true;
      input.dispatchEvent(new Event('change', {bubbles:true}));
    }));
    input.addEventListener('focus', open);
    input.addEventListener('input', filter);
    input.addEventListener('blur', close);
  });

  document.querySelectorAll('[data-crm-draggable-id]').forEach(card => {
    card.draggable = true;
    card.addEventListener('dragstart', ev => {
      ev.dataTransfer.setData('text/plain', card.dataset.crmDraggableId);
      ev.dataTransfer.effectAllowed = 'move';
      card.classList.add('opacity-60');
    });
    card.addEventListener('dragend', () => card.classList.remove('opacity-60'));
  });
  document.querySelectorAll('[data-crm-drop-stage]').forEach(column => {
    column.addEventListener('dragover', ev => { ev.preventDefault(); column.classList.add('is-drop-target'); });
    column.addEventListener('dragleave', () => column.classList.remove('is-drop-target'));
    column.addEventListener('drop', ev => {
      ev.preventDefault();
      column.classList.remove('is-drop-target');
      const id = ev.dataTransfer.getData('text/plain');
      const stage = column.dataset.crmDropStage;
      const form = document.querySelector('[data-crm-stage-form="' + id + '"]');
      if (!form) return;
      const select = form.querySelector('select[name="etape"]');
      if (select) select.value = stage;
      form.submit();
    });
  });
});
</script>
HTML;
}
