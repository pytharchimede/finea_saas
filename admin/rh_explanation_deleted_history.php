<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/RhExplanationRequestManager.php';

if (!function_exists('hasPermission') || !hasPermission('acces_admin')) {
    header('Location: ../acces_refuse.php');
    exit;
}

$pdo = \Database::getConnection();
$manager = new \RhExplanationRequestManager($pdo);

$search = trim((string) ($_GET['q'] ?? ''));
$archives = $manager->listDeletedRequestArchives(200, $search);

$pageTitle = 'Demandes supprimées';
$activeMenu = 'admin';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-rose-50">
    <div class="container mx-auto px-4 py-10">
        <div class="mb-8 rounded-[28px] border border-slate-200 bg-white px-8 py-8 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.32em] text-rose-700">Administration</p>
                    <h1 class="mt-3 text-3xl font-semibold text-slate-950">Historique des demandes supprimées</h1>
                    <p class="mt-3 max-w-3xl text-sm text-slate-600">Consultez les dossiers RH supprimés par un administrateur. Les demandes actives ne sont plus présentes, mais un snapshot complet est conservé pour audit.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="dashboard_admin.php" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:border-slate-400"><i class="fas fa-arrow-left mr-2"></i>Retour admin</a>
                    <a href="../rh/demandes_explication_rh.php" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800"><i class="fas fa-folder-open mr-2"></i>Demandes RH</a>
                </div>
            </div>
        </div>

        <div class="mb-6 rounded-[24px] border border-slate-200 bg-white p-5 shadow-sm">
            <form method="get" class="flex flex-col gap-3 md:flex-row">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-rose-400 focus:bg-white" placeholder="Rechercher par référence, collaborateur, motif ou contenu archivé">
                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-rose-700 px-5 py-3 text-sm font-semibold text-white hover:bg-rose-800"><i class="fas fa-search mr-2"></i>Rechercher</button>
            </form>
        </div>

        <div class="grid gap-4">
            <?php if (!$archives): ?>
                <div class="rounded-[24px] border border-dashed border-slate-300 bg-white px-8 py-14 text-center text-slate-500">Aucune suppression archivée pour le moment.</div>
            <?php else: ?>
                <?php foreach ($archives as $archive): ?>
                    <?php $snapshot = (array) ($archive['request_snapshot'] ?? []); ?>
                    <details class="rounded-[24px] border border-slate-200 bg-white shadow-sm">
                        <summary class="flex cursor-pointer list-none flex-col gap-4 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-500"><?= htmlspecialchars((string) ($archive['request_ref'] ?? '')) ?></div>
                                <h2 class="mt-2 text-xl font-semibold text-slate-950"><?= htmlspecialchars((string) ($archive['subject'] ?? 'Demande supprimée')) ?></h2>
                                <p class="mt-2 text-sm text-slate-600">Collaborateur: <?= htmlspecialchars((string) ($archive['target_name'] ?? 'Non renseigné')) ?></p>
                            </div>
                            <div class="space-y-2 text-sm text-slate-600 lg:text-right">
                                <div>Supprimée le <strong class="text-slate-900"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($archive['deleted_at'] ?? 'now')))) ?></strong></div>
                                <div>Par <strong class="text-slate-900"><?= htmlspecialchars((string) ($archive['deleted_by_name'] ?? 'Admin')) ?></strong></div>
                                <div><?= (int) ($archive['messages_count'] ?? 0) ?> message(s) archivé(s)</div>
                            </div>
                        </summary>
                        <div class="border-t border-slate-200 px-6 py-6">
                            <div class="grid gap-4 lg:grid-cols-3">
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Référence</div>
                                    <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($archive['request_ref'] ?? '')) ?></div>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Statut au moment de la suppression</div>
                                    <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($snapshot['status_label'] ?? ($snapshot['status'] ?? 'Non renseigné'))) ?></div>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Motif admin</div>
                                    <div class="mt-2 text-sm text-slate-800"><?= nl2br(htmlspecialchars((string) ($archive['delete_reason'] ?? ''))) ?></div>
                                </div>
                            </div>

                            <div class="mt-5 grid gap-4 lg:grid-cols-2">
                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Dossier archivé</div>
                                    <div class="mt-3 space-y-2 text-sm text-slate-700">
                                        <div><strong>Collaborateur:</strong> <?= htmlspecialchars((string) ($snapshot['target_name'] ?? '')) ?></div>
                                        <div><strong>Email:</strong> <?= htmlspecialchars((string) ($snapshot['target_email'] ?? '')) ?></div>
                                        <div><strong>Service:</strong> <?= htmlspecialchars((string) ($snapshot['target_service_label'] ?? '')) ?></div>
                                        <div><strong>Objet:</strong> <?= htmlspecialchars((string) ($snapshot['subject'] ?? '')) ?></div>
                                        <div><strong>Créée le:</strong> <?= !empty($snapshot['created_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $snapshot['created_at']))) : 'Non renseigné' ?></div>
                                    </div>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Contenu archivé</div>
                                    <div class="prose prose-sm mt-3 max-w-none text-slate-800"><?= (string) ($snapshot['request_html'] ?? nl2br(htmlspecialchars((string) ($snapshot['request_text'] ?? '')))) ?></div>
                                </div>
                            </div>

                            <?php if (!empty($archive['messages_snapshot'])): ?>
                                <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Chronologie archivée</div>
                                    <div class="mt-4 space-y-3">
                                        <?php foreach ((array) $archive['messages_snapshot'] as $message): ?>
                                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                                <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                                    <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($message['author_name'] ?? 'Utilisateur')) ?></div>
                                                    <div class="text-xs text-slate-500"><?= !empty($message['created_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $message['created_at']))) : '' ?></div>
                                                </div>
                                                <div class="mt-1 text-xs uppercase tracking-[0.22em] text-slate-500"><?= htmlspecialchars((string) ($message['message_kind'] ?? 'message')) ?></div>
                                                <div class="prose prose-sm mt-2 max-w-none text-slate-800"><?= (string) ($message['body_html'] ?? '') ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>