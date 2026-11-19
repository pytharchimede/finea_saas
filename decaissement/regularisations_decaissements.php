<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/DisbursementRegularizationManager.php';

$pdo = Database::getConnection();
$manager = new DisbursementRegularizationManager($pdo);
$userId = (int)($_SESSION['user_id'] ?? 0);
$canViewAll = hasPermission('acces_compta') || hasPermission('acces_tresorerie') || hasPermission('acces_direction') || hasPermission('acces_admin');
$canBulkApprove = hasPermission('acces_compta') || hasPermission('acces_tresorerie') || hasPermission('acces_admin');
$csrf = $_SESSION['disbursement_regularizations_csrf'] ??= bin2hex(random_bytes(24));
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canBulkApprove) {
    try {
        if (!hash_equals((string)$csrf, (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Session expirée. Rechargez la page.');
        if ((string)($_POST['action'] ?? '') !== 'bulk_approve') throw new RuntimeException('Action invalide.');
        $signedForOrder = !empty($_POST['sign_for_order']);
        $count = $manager->bulkApprove((array)($_POST['selected'] ?? []), $userId, (string)($_SESSION['username'] ?? 'Comptabilité'), (string)($_POST['accounting_note'] ?? ''), $signedForOrder, $signedForOrder ? 'profile' : '', '');
        $flash = $count . ' régularisation(s) approuvée(s)' . ($signedForOrder ? ' et signée(s) pour ordre.' : '.');
    } catch (Throwable $e) {
        $flash = 'Erreur : ' . $e->getMessage();
    }
}
$status = trim((string)($_GET['status'] ?? ''));
$rows = $manager->listForViewer($userId, $canViewAll, $status);
$statusLabels = [
    'not_submitted' => ['À transmettre', 'bg-amber-100 text-amber-800'],
    'pending' => ['À valider', 'bg-indigo-100 text-indigo-800'],
    'approved' => ['Validée', 'bg-emerald-100 text-emerald-800'],
    'rejected' => ['À corriger', 'bg-rose-100 text-rose-800'],
];
$counts = $manager->countsForViewer($userId, $canViewAll);
$profileSignature = trim((string)(($manager->getUserIdentity($userId))['signature'] ?? '')) !== '';
$pageTitle = 'Régularisation des décaissements';
$activeMenu = 'regularisation_decaissements';
$activeEmployeeMenu = 'regularisation_decaissements';
$layoutRootBase = '../';
require __DIR__ . '/includes/header.php';
if ($canViewAll) {
    require __DIR__ . '/includes/navbar.php';
} else {
    require __DIR__ . '/../employe/includes/navbar.php';
}
?>
<div class="min-h-screen bg-slate-50 px-4 py-8">
    <div class="mx-auto max-w-7xl">
        <div class="rounded-3xl bg-slate-950 p-7 text-white shadow-xl">
            <p class="text-xs font-bold uppercase tracking-[.25em] text-indigo-300">Contrôle après paiement</p>
            <h1 class="mt-2 text-3xl font-bold">Régularisation des décaissements</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-300"><?= $canViewAll ? 'Cette file contient uniquement les fiches déjà décaissées. Contrôlez ensuite les PV signés et les preuves de paiement transmis.' : 'Cette file contient uniquement vos fiches déjà décaissées. Complétez-les avec le PV signé et la preuve de paiement.' ?></p>
        </div>
        <div class="mt-6 flex flex-wrap gap-2">
            <a href="regularisations_decaissements.php" class="rounded-xl border bg-white px-4 py-2 text-sm font-semibold">Toutes</a>
            <?php foreach ($statusLabels as $key => $meta): ?><a href="?status=<?= $key ?>" class="rounded-xl border bg-white px-4 py-2 text-sm font-semibold"><?= $meta[0] ?> <span class="ml-1 rounded-full px-2 py-0.5 <?= $meta[1] ?>"><?= (int)$counts[$key] ?></span></a><?php endforeach ?>
        </div>
        <?php if (!empty($_GET['submitted'])): ?>
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">PV signé et preuve de paiement transmis à la comptabilité. Toutes vos régularisations à transmettre sont terminées.</div>
        <?php endif ?>
        <?php if ($flash): ?><div class="mt-6 rounded-2xl border <?= str_starts_with($flash, 'Erreur') ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?> p-4 text-sm font-semibold"><?= htmlspecialchars($flash) ?></div><?php endif ?>
        <?php if (!$canViewAll): ?>
            <div class="mt-6 grid gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-5 text-sm text-indigo-950 md:grid-cols-3">
                <div><b>1. Ouvrir</b><br><span class="text-indigo-700">Cliquez sur « Régulariser » devant la fiche décaissée.</span></div>
                <div><b>2. Compléter et signer</b><br><span class="text-indigo-700">Renseignez le PV, signez puis joignez le reçu ou la preuve de paiement.</span></div>
                <div><b>3. Suivre le visa</b><br><span class="text-indigo-700">La comptabilité valide les documents ou vous demande une correction.</span></div>
            </div>
        <?php endif ?>
        <form method="post" class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm" id="bulkApprovalForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrf) ?>"><input type="hidden" name="action" value="bulk_approve">
            <?php if ($canBulkApprove): ?><div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-200 bg-indigo-50 px-5 py-4">
                    <div class="text-sm text-indigo-950"><b>Validation groupée</b><br><span class="text-xs text-indigo-700">Seules les fiches « À valider » sélectionnées seront approuvées.</span></div>
                    <div class="flex flex-wrap items-center gap-3"><input name="accounting_note" class="rounded-xl border-indigo-200 text-sm" placeholder="Observation commune (facultatif)"><label class="text-xs font-semibold text-indigo-900"><input type="checkbox" name="sign_for_order" value="1" <?= $profileSignature ? '' : 'disabled' ?>> Signer en PO avec ma signature de profil</label><button class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white" onclick="return confirm('Approuver les régularisations sélectionnées ?')">Approuver la sélection</button></div>
                </div><?php if (!$profileSignature): ?><div class="border-b border-amber-200 bg-amber-50 px-5 py-2 text-xs text-amber-900">La signature PO en masse requiert une signature enregistrée dans votre profil. L’approbation en masse reste disponible.</div><?php endif ?><?php endif ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                                <tr><?php if ($canBulkApprove): ?><th class="px-5 py-4"><input type="checkbox" id="selectPending" title="Sélectionner les fiches à valider"></th><?php endif ?><th class="px-5 py-4">Fiche</th>
                                    <th class="px-5 py-4">Projet / objet</th>
                                    <th class="px-5 py-4">Montant</th>
                                    <th class="px-5 py-4">Paiement</th>
                                    <th class="px-5 py-4">Régularisation</th>
                                    <th class="px-5 py-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$rows): ?><tr>
                                        <td colspan="<?= $canBulkApprove ? 7 : 6 ?>" class="px-5 py-10 text-center text-slate-500">Aucun décaissement dans cette vue.</td>
                                    </tr><?php endif ?>
                                <?php foreach ($rows as $row):
                                    $key = empty($row['submitted_at']) ? 'not_submitted' : (string)($row['accounting_status'] ?? 'pending');
                                    $meta = $statusLabels[$key] ?? $statusLabels['pending'];
                                ?>
                                    <tr class="border-t border-slate-100">
                                        <?php if ($canBulkApprove): ?><td class="px-5 py-4"><?php if ($key === 'pending'): ?><input type="checkbox" name="selected[]" value="<?= htmlspecialchars((string)$row['num_fiche']) ?>" class="js-pending-row"><?php endif ?></td><?php endif ?>
                                        <td class="px-5 py-4">
                                            <div class="font-bold text-slate-950"><?= htmlspecialchars((string)$row['num_fiche']) ?></div>
                                            <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string)$row['date_decaissement']) ?></div>
                                        </td>
                                        <td class="px-5 py-4">
                                            <div class="font-semibold text-slate-800"><?= htmlspecialchars((string)($row['chantier_titre'] ?: $row['designation_fiche'])) ?></div>
                                            <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string)$row['beficiaire_fiche']) ?></div>
                                        </td>
                                        <td class="px-5 py-4 font-bold"><?= number_format((float)$row['montant_fiche'], 0, ',', ' ') ?> FCFA</td>
                                        <td class="px-5 py-4"><?= htmlspecialchars((string)($row['mode_paiement'] ?: '—')) ?></td>
                                        <td class="px-5 py-4"><span class="rounded-full px-3 py-1 text-xs font-bold <?= $meta[1] ?>"><?= $meta[0] ?></span></td>
                                        <td class="px-5 py-4 text-right"><a class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700" href="regularisation_decaissement.php?num_fiche=<?= rawurlencode((string)$row['num_fiche']) ?>"><?= $canViewAll ? 'Mettre à jour / contrôler' : 'Régulariser' ?></a></td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
<script>
    document.getElementById('selectPending')?.addEventListener('change', function() {
        document.querySelectorAll('.js-pending-row').forEach(box => box.checked = this.checked);
    });
</script>