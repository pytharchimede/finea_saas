<?php
declare(strict_types=1);

function pc_render_module_assets(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ?>
    <link rel="stylesheet" href="assets/css/planning.css?v=20261126-contrast">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <?php
}

function pc_render_fallback_header(string $title = 'Planning Chantier'): void
{
    ?>
    <!doctype html>
    <html lang="fr">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= pc_e($title) ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <?php pc_render_module_assets(); ?>
    </head>
    <body class="bg-slate-50">
    <?php
}

function pc_render_page_start(string $title, string $subtitle = '', string $eyebrow = 'Module planning chantier'): void
{
    pc_render_module_assets();
    $flash = pc_flash_get();
    ?>
    <div class="pc-workspace min-h-screen pb-12">
        <div class="container mx-auto px-4 py-8">
            <?php if ($flash): ?>
                <div class="mb-5 rounded-2xl border <?= ($flash['type'] ?? '') === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?> px-5 py-4 font-semibold shadow-sm">
                    <?= pc_e((string)$flash['message']) ?>
                </div>
            <?php endif; ?>

            <section class="pc-page-hero relative overflow-hidden rounded-[32px] border border-slate-900/10 pc-brand-banner px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
                <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <div class="mb-3 flex flex-wrap items-center gap-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.35em] text-amber-200"><?= pc_e($eyebrow) ?></p>
                            
                        </div>
                        <h1 class="text-3xl font-semibold tracking-tight md:text-5xl"><?= pc_e($title) ?></h1>
                        <?php if ($subtitle): ?>
                            <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base"><?= pc_e($subtitle) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="dashboard.php" class="pc-hero-dashboard inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-amber-50">
                            <i class="fas fa-chart-line mr-2 text-orange-600"></i>Vue globale
                        </a>
                        <a href="reunion.php" class="inline-flex items-center rounded-2xl bg-[#fabd02] px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-500/20 transition hover:-translate-y-0.5 hover:bg-amber-300">
                            <i class="fas fa-users mr-2"></i>Réunion
                        </a>
                        <a href="preparation_reunion.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                            <i class="fas fa-clipboard-list mr-2"></i>Préparer
                        </a>
                        <a href="task_form.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                            <i class="fas fa-plus mr-2"></i>Nouvelle tâche
                        </a>
                        
                    </div>
                </div>
            </section>

            <nav class="pc-module-nav mt-5 flex flex-wrap gap-3">
                <?php foreach ([
                    ['dashboard.php','fa-gauge-high','Vue globale'],
                    ['reunion.php','fa-people-group','Réunion'],
                    ['plannings.php','fa-chart-gantt','Plannings & derniers CR'],
                    ['work_progress.php','fa-chart-column','Avancement travaux'],
                    ['preparation_reunion.php','fa-clipboard-list','Préparation'],
                    ['meeting_archives.php','fa-box-archive','Archives CR'],
                    ['meeting_followups.php','fa-bell','Suivi & relances'],
                    ['tasks.php','fa-list-check','Tâches'],
                    ['blockages.php','fa-triangle-exclamation','Blocages'],
                    ['task_form.php','fa-circle-plus','Ajouter']
                ] as $item): ?>
                    <a href="<?= pc_e($item[0]) ?>" aria-current="<?= basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === $item[0] ? 'page' : 'false' ?>" class="inline-flex items-center rounded-2xl border border-white/70 bg-white/85 px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:-translate-y-0.5 hover:border-orange-200 hover:bg-white">
                        <i class="fas <?= pc_e($item[1]) ?> mr-2 text-orange-600"></i><?= pc_e($item[2]) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
    <?php
}

function pc_render_page_end(): void
{
    ?>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="assets/js/planning.js?v=20260721-progress-invariants"></script>
    </body></html>
    <?php
}

function pc_badge(string $status): string
{
    return '<span class="' . pc_status_class($status) . '">' . pc_e(pc_status_label($status)) . '</span>';
}

function pc_priority_badge(string $priority): string
{
    return pc_badge($priority);
}
