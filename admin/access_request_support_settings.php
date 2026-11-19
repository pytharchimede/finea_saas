<?php

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AccessRequestSupportSettings.php';

$pdo = \Database::getConnection();
$settings = new \AccessRequestSupportSettings($pdo);

$flash = null;
$flashType = 'success';
$actor = [
    'user_id' => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
    'user_name' => isset($_SESSION['user_name']) ? (string)$_SESSION['user_name'] : null,
];

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    try {
        $selectedUserIds = is_array($_POST['support_user_ids'] ?? null) ? array_map('intval', (array)$_POST['support_user_ids']) : [];
        $settings->saveSelection($selectedUserIds, $actor);
        $flash = 'La liste support informatique a été mise à jour.';
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$candidates = $settings->getItCandidates();
$itCandidateCount = count($candidates);
$selectedLookup = array_fill_keys($settings->getSelectedUserIds(), true);
$recentRequests = $settings->getRecentRequests(18);

$pageTitle = 'Support demandes d\'accès';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(219,234,254,0.95),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.82),_transparent_34%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <section class="overflow-hidden rounded-[32px] border border-slate-200/80 bg-white/90 shadow-[0_24px_80px_rgba(15,23,42,0.08)] backdrop-blur">
            <div class="bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-900 px-8 py-8 text-white">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.34em] text-cyan-200">Administration support</p>
                        <h1 class="mt-3 text-3xl font-semibold tracking-tight md:text-4xl">Destinataires des demandes d'accès</h1>
                        <p class="mt-4 text-sm text-slate-200 md:text-base">Seuls les comptes rattachés au service informatique peuvent être sélectionnés. Cette liste pilote les mails envoyés depuis l'écran d'accès refusé.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="dashboard_admin.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-cyan-50"><i class="fas fa-gauge-high mr-2 text-cyan-600"></i>Dashboard admin</a>
                        <a href="notifications_settings.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10"><i class="fas fa-bell mr-2"></i>Notifications</a>
                    </div>
                </div>
            </div>

            <div class="px-6 py-6 md:px-8 md:py-8">
                <?php if ($flash !== null): ?>
                    <div class="mb-6 rounded-2xl border px-4 py-4 text-sm font-semibold <?= $flashType === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>">
                        <?= htmlspecialchars($flash) ?>
                    </div>
                <?php endif; ?>

                <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                    <section class="rounded-[28px] border border-slate-200 bg-slate-50/70 p-6">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-indigo-600">Service informatique</p>
                                <h2 class="mt-2 text-2xl font-semibold text-slate-950">Sélection des destinataires</h2>
                            </div>
                            <span class="rounded-full bg-white px-4 py-2 text-xs font-semibold text-slate-600 shadow-sm"><?= $itCandidateCount ?> compte(s) IT</span>
                        </div>

                        <div class="mt-4 rounded-2xl border border-cyan-200 bg-cyan-50 px-4 py-4 text-sm text-cyan-900">
                            <div class="font-semibold">Périmètre appliqué</div>
                            <div class="mt-1">Seuls les comptes rattachés au service <strong>INFORMATIQUE</strong> <span class="text-cyan-700">(id_service = 2)</span> peuvent apparaître dans cette liste.</div>
                            <div class="mt-2 inline-flex rounded-full bg-white px-3 py-1 text-xs font-semibold text-cyan-800 shadow-sm"><?= $itCandidateCount ?> compte(s) trouvé(s) dans le service INFORMATIQUE</div>
                        </div>

                        <form method="post" class="mt-6 space-y-4">
                            <?php if (empty($candidates)): ?>
                                <div class="rounded-2xl border border-dashed border-amber-300 bg-amber-50 px-5 py-6 text-sm text-amber-800">
                                    <div class="font-semibold">Aucun agent IT rattaché pour le moment.</div>
                                    <div class="mt-2">Aucun compte actif avec email professionnel n'est actuellement lié au service <strong>INFORMATIQUE</strong> <span class="text-amber-700">(id_service = 2)</span>. Vérifiez le rattachement du personnel et du compte utilisateur avant de configurer la liste support.</div>
                                </div>
                            <?php else: ?>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <?php foreach ($candidates as $candidate): ?>
                                        <label class="flex items-start gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-sm transition hover:border-indigo-300 hover:shadow-md">
                                            <input type="checkbox" name="support_user_ids[]" value="<?= (int)$candidate['id'] ?>" class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" <?= !empty($selectedLookup[(int)$candidate['id']]) ? 'checked' : '' ?>>
                                            <span class="min-w-0 flex-1">
                                                <span class="block text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)$candidate['name']) ?></span>
                                                <span class="mt-1 block break-all text-sm text-slate-600"><?= htmlspecialchars((string)$candidate['email']) ?></span>
                                                <span class="mt-2 inline-flex rounded-full bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700"><?= htmlspecialchars((string)$candidate['service_label']) ?></span>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="flex flex-wrap gap-3">
                                <button type="submit" class="inline-flex items-center rounded-2xl bg-gradient-to-r from-cyan-600 to-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-cyan-900/20 transition hover:-translate-y-0.5 hover:from-cyan-700 hover:to-indigo-700"><i class="fas fa-save mr-2"></i>Enregistrer la liste support</button>
                            </div>
                        </form>
                    </section>

                    <section class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_16px_50px_rgba(15,23,42,0.05)]">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Consigne métier</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Règles appliquées</h2>
                        <div class="mt-5 space-y-3 text-sm text-slate-600">
                            <div class="rounded-2xl bg-slate-50 px-4 py-4">La liste est exclusivement composée d'employés du service informatique. Aucun destinataire externe ou hors IT ne peut être enregistré depuis cet écran.</div>
                            <div class="rounded-2xl bg-slate-50 px-4 py-4">Si aucune sélection n'est sauvegardée, le système utilise par défaut tous les comptes IT actifs détectés.</div>
                            <div class="rounded-2xl bg-slate-50 px-4 py-4">Les décisions accord/refus restent traitées sur le lien sécurisé reçu par email et journalisées dans l'audit applicatif.</div>
                        </div>
                    </section>
                </div>

                <section class="mt-6 rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_16px_50px_rgba(15,23,42,0.05)]">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500">Suivi</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Dernières demandes d'accès</h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-600"><?= count($recentRequests) ?> ligne(s)</span>
                    </div>

                    <div class="mt-6 overflow-x-auto rounded-3xl border border-slate-200 bg-slate-50">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-white text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left font-semibold">Date</th>
                                    <th class="px-4 py-3 text-left font-semibold">Utilisateur</th>
                                    <th class="px-4 py-3 text-left font-semibold">Page</th>
                                    <th class="px-4 py-3 text-left font-semibold">Accès</th>
                                    <th class="px-4 py-3 text-left font-semibold">Statut</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 bg-white text-slate-700">
                                <?php if (empty($recentRequests)): ?>
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center text-sm text-slate-500">Aucune demande d'accès n'a encore été enregistrée.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($recentRequests as $request): ?>
                                        <?php $status = (string)($request['status'] ?? 'pending'); ?>
                                        <tr>
                                            <td class="px-4 py-3"><?= htmlspecialchars((string)($request['created_at'] ?? '')) ?></td>
                                            <td class="px-4 py-3">
                                                <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($request['requester_name'] ?? '')) ?></div>
                                                <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($request['requester_email'] ?? '')) ?></div>
                                            </td>
                                            <td class="px-4 py-3"><?= htmlspecialchars((string)($request['requested_page'] ?? '')) ?></td>
                                            <td class="px-4 py-3"><?= htmlspecialchars((string)($request['primary_permission_label'] ?? '')) ?></td>
                                            <td class="px-4 py-3">
                                                <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $status === 'granted' ? 'bg-emerald-100 text-emerald-700' : ($status === 'refused' ? 'bg-rose-100 text-rose-700' : 'bg-indigo-100 text-indigo-700') ?>">
                                                    <?= htmlspecialchars($status === 'granted' ? 'Accordée' : ($status === 'refused' ? 'Refusée' : 'En attente')) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </section>
    </div>
</div>