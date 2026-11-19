<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Communication.php';

$pdo = \Database::getConnection();
\Communication::ensureTables($pdo);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$dashboard = \Communication::getTaskDashboard($pdo, $currentUserId);
$stats = $dashboard['stats'];
$topAssignees = $dashboard['top_assignees'];

$pageTitle = 'Dashboard Mes taches';
$activeMenu = 'communication';
$page = 'communication';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(224,231,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(186,230,253,0.85),_transparent_34%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-indigo-100 bg-gradient-to-r from-slate-950 via-sky-950 to-cyan-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-cyan-200">Mes tâches</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Piloter les tâches du personnel depuis une interface unique</h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">Centralisez le suivi des tâches du personnel, les échéances, les commentaires d'équipe et les pièces jointes dans un seul espace opérationnel.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="mes_taches_communication.php" class="inline-flex items-center rounded-2xl bg-cyan-400 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg transition hover:-translate-y-0.5 hover:bg-cyan-300"><i class="fas fa-check-double mr-2 text-slate-900"></i>Mes tâches</a>
                    <a href="taches_communication.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-lg transition hover:-translate-y-0.5 hover:bg-cyan-50"><i class="fas fa-list-check mr-2 text-cyan-600"></i>Tableau des tâches</a>
                    <a href="messagerie_communication.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10"><i class="fas fa-comments mr-2"></i>Messagerie des tâches</a>
                    <a href="pieces_jointes_communication.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10"><i class="fas fa-paperclip mr-2"></i>Pièces jointes</a>
                </div>
            </div>
        </div>

        <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-6">
            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <p class="text-sm font-medium text-slate-500">Total tâches</p>
                <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int)$stats['total_tasks'], 0, ',', ' ') ?></p>
                <p class="mt-2 text-sm text-slate-500">Toutes les tâches ouvertes et terminées</p>
            </div>
            <div class="rounded-[28px] border border-amber-100 bg-gradient-to-br from-amber-50 to-white p-6 shadow-[0_16px_50px_rgba(245,158,11,0.10)]">
                <p class="text-sm font-medium text-amber-700">À faire</p>
                <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int)$stats['todo_tasks'], 0, ',', ' ') ?></p>
                <p class="mt-2 text-sm text-slate-500">Demandent une prise en charge</p>
            </div>
            <div class="rounded-[28px] border border-sky-100 bg-gradient-to-br from-sky-50 to-white p-6 shadow-[0_16px_50px_rgba(14,165,233,0.10)]">
                <p class="text-sm font-medium text-sky-700">En cours</p>
                <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int)$stats['in_progress_tasks'], 0, ',', ' ') ?></p>
                <p class="mt-2 text-sm text-slate-500">Travaux actifs</p>
            </div>
            <div class="rounded-[28px] border border-rose-100 bg-gradient-to-br from-rose-50 to-white p-6 shadow-[0_16px_50px_rgba(244,63,94,0.10)]">
                <p class="text-sm font-medium text-rose-700">En retard</p>
                <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int)$stats['late_tasks'], 0, ',', ' ') ?></p>
                <p class="mt-2 text-sm text-slate-500">Deadline dépassée</p>
            </div>
            <div class="rounded-[28px] border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-6 shadow-[0_16px_50px_rgba(16,185,129,0.10)]">
                <p class="text-sm font-medium text-emerald-700">Terminées</p>
                <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int)$stats['done_tasks'], 0, ',', ' ') ?></p>
                <p class="mt-2 text-sm text-slate-500">Actions clôturées</p>
            </div>
            <div class="rounded-[28px] border border-slate-200 bg-gradient-to-br from-slate-100 to-white p-6 shadow-[0_16px_50px_rgba(100,116,139,0.10)]">
                <p class="text-sm font-medium text-slate-700">Annulées</p>
                <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int)$stats['cancelled_tasks'], 0, ',', ' ') ?></p>
                <p class="mt-2 text-sm text-slate-500">Sorties du flux actif</p>
            </div>
        </div>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Navigation</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Sous-pages du module</h2>
                    </div>
                </div>
                <div class="mt-6 grid gap-4 md:grid-cols-4">
                    <a href="mes_taches_communication.php" class="rounded-2xl border border-sky-100 bg-sky-50/80 p-5 transition hover:-translate-y-0.5 hover:shadow-lg">
                        <div class="font-semibold text-slate-950">Mes tâches</div>
                        <p class="mt-2 text-sm text-slate-600">Suivi personnel façon To Do avec sections Aujourd'hui, planifié, en retard et terminé récemment.</p>
                    </a>
                    <a href="taches_communication.php" class="rounded-2xl border border-cyan-100 bg-cyan-50/80 p-5 transition hover:-translate-y-0.5 hover:shadow-lg">
                        <div class="font-semibold text-slate-950">Tâches</div>
                        <p class="mt-2 text-sm text-slate-600">Sélectionner un collaborateur, filtrer, rechercher et créer une tâche.</p>
                    </a>
                    <a href="messagerie_communication.php" class="rounded-2xl border border-indigo-100 bg-indigo-50/80 p-5 transition hover:-translate-y-0.5 hover:shadow-lg">
                        <div class="font-semibold text-slate-950">Messagerie</div>
                        <p class="mt-2 text-sm text-slate-600">Suivre les commentaires et échanges par tâche.</p>
                    </a>
                    <a href="pieces_jointes_communication.php" class="rounded-2xl border border-emerald-100 bg-emerald-50/80 p-5 transition hover:-translate-y-0.5 hover:shadow-lg">
                        <div class="font-semibold text-slate-950">Pièces jointes</div>
                        <p class="mt-2 text-sm text-slate-600">Retrouver rapidement tous les fichiers déposés.</p>
                    </a>
                </div>
            </section>

            <aside class="rounded-[30px] border border-slate-200 bg-slate-950 p-7 text-white shadow-[0_22px_70px_rgba(15,23,42,0.28)]">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-300">Charge par collaborateur</p>
                <h2 class="mt-2 text-2xl font-semibold">Top affectations</h2>
                <div class="mt-6 space-y-3">
                    <?php if ($topAssignees): ?>
                        <?php foreach ($topAssignees as $assignee): ?>
                            <a href="taches_communication.php?assignee_user_id=<?= urlencode((string)$assignee['assignee_user_id']) ?>" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="font-semibold"><?= htmlspecialchars((string)$assignee['display'], ENT_QUOTES, 'UTF-8') ?></div><span class="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-cyan-200"><?= (int)$assignee['total'] ?> tâche(s)</span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="rounded-2xl border border-white/10 bg-white/5 px-4 py-4 text-sm text-slate-300">Aucune tâche enregistrée pour le moment.</div>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>