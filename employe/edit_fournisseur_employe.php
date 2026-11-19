<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../services/FournisseurWorkflowNotificationService.php';
require_once __DIR__ . '/../model/PositioningSettingsManager.php';
require_once __DIR__ . '/../model/EntityPositioningManager.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();
$model = new \FournisseurRepository($pdo);
$positioningSettingsManager = new \PositioningSettingsManager($pdo);
$entityPositioningManager = new \EntityPositioningManager($pdo);

$pageTitle = 'Modifier mon fournisseur';
$layoutRootBase = '../';
$activeEmployeeMenu = 'fournisseurs';
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$flashSuccess = '';
$flashError = '';
if (!empty($_SESSION['fournisseur_flash_success'])) {
    $flashSuccess = (string) $_SESSION['fournisseur_flash_success'];
    unset($_SESSION['fournisseur_flash_success']);
}
if (!empty($_SESSION['fournisseur_flash_error'])) {
    $flashError = (string) $_SESSION['fournisseur_flash_error'];
    unset($_SESSION['fournisseur_flash_error']);
}

$fournisseur = $id > 0 ? $model->getById($id) : null;
if ($fournisseur && (int) ($fournisseur['created_by_user_id'] ?? 0) !== $currentUserId) {
    $_SESSION['fournisseur_flash_error'] = 'Vous ne pouvez modifier que vos propres fournisseurs.';
    header('Location: mes_fournisseurs_employe.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$fournisseur) {
        $_SESSION['fournisseur_flash_error'] = 'Fournisseur introuvable.';
        header('Location: mes_fournisseurs_employe.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? 'update');
    if ($action === 'add_incident') {
        $result = $model->addIncident($id, $_POST, $currentUserId);
        $_SESSION['fournisseur_flash_' . (!empty($result['ok']) ? 'success' : 'error')] = !empty($result['ok'])
            ? 'Incident fournisseur enregistré.'
            : (string) ($result['error'] ?? 'Impossible d\'enregistrer l\'incident.');
        header('Location: edit_fournisseur_employe.php?id=' . $id . '#incidents');
        exit;
    }

    $result = $model->update($id, $_POST, $currentUserId, false, $_FILES);
    if (!empty($result['ok'])) {
        $updatedSupplier = $model->getById($id);
        if ($updatedSupplier) {
            try {
                $notifier = new \FournisseurWorkflowNotificationService($pdo);
                $notifier->notifySubmitted($updatedSupplier, [
                    'user_id' => $currentUserId,
                    'name' => (string) ($_SESSION['user_name'] ?? ''),
                ], 'updated');
            } catch (Throwable $e) {
            }
        }
    }
    $_SESSION['fournisseur_flash_' . (!empty($result['ok']) ? 'success' : 'error')] = !empty($result['ok'])
        ? ('Fournisseur mis à jour' . (!empty($result['code_fournisseur']) ? (' (Code: ' . (string) $result['code_fournisseur'] . ')') : '') . '. Le dossier repart en validation comptable.')
        : (string) ($result['error'] ?? 'Impossible de mettre à jour le fournisseur.');
    header('Location: edit_fournisseur_employe.php?id=' . $id);
    exit;
}

$fournisseur = $id > 0 ? $model->getById($id) : null;
$categoryOptions = $model->listCategories(false);
$legalFormOptions = $model->listLegalForms(false);
$incidents = $fournisseur ? $model->listIncidents((int) $fournisseur['id_fournisseur']) : [];
$supplierLocationPickerSettings = $positioningSettingsManager->get('supplier');
$supplierLocationPickerInitialPosition = $fournisseur ? $entityPositioningManager->getPosition('supplier', (int) ($fournisseur['id_fournisseur'] ?? 0)) : null;

function employeeSupplierStatusMetaEdit(string $status): array
{
    switch ($status) {
        case \FournisseurRepository::STATUS_APPROVED:
            return ['label' => 'Validé', 'class' => 'border-emerald-200 bg-emerald-50 text-emerald-700'];
        case \FournisseurRepository::STATUS_REJECTED:
            return ['label' => 'Rejeté', 'class' => 'border-rose-200 bg-rose-50 text-rose-700'];
        default:
            return ['label' => 'En attente', 'class' => 'border-amber-200 bg-amber-50 text-amber-700'];
    }
}

function employeeEditSupplierTypeLabel(string $type): string
{
    return $type === \FournisseurRepository::TYPE_INDIVIDUAL ? 'Personne physique' : 'Personne morale';
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <section class="supplier-overview">
            <?php supplierWorkspaceHeader('Normaliser mon fournisseur', 'Complétez les informations et les documents avant validation comptable.', (int)($id ?? 0)); ?>

            <?php if ($flashSuccess): ?>
                <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <?= htmlspecialchars($flashSuccess) ?>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <?= htmlspecialchars($flashError) ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$fournisseur): ?>
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-700 shadow-sm">
                Fournisseur introuvable.
            </section>
        <?php else: ?>
            <?php $statusMeta = employeeSupplierStatusMetaEdit((string) ($fournisseur['validation_status'] ?? \FournisseurRepository::STATUS_PENDING)); ?>
            <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Fiche</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950"><?= htmlspecialchars((string) ($fournisseur['raison_sociale'] ?? 'Fournisseur')) ?></h2>
                        <div class="mt-2 text-sm text-slate-600"><?= htmlspecialchars(employeeEditSupplierTypeLabel((string) ($fournisseur['supplier_type'] ?? 'morale'))) ?></div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="inline-flex rounded-full border px-3 py-1 font-semibold <?= $statusMeta['class'] ?>"><?= htmlspecialchars($statusMeta['label']) ?></span>
                        <?php if (!empty($fournisseur['validation_note'])): ?>
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-slate-600">Note comptable: <?= htmlspecialchars((string) $fournisseur['validation_note']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <?php
            $supplierCreateHeading = 'Modifier mon fournisseur';
            $supplierCreateEyebrow = 'Mise à jour';
            $supplierCreateDescription = 'Corrigez ou complétez votre fournisseur dans le même formulaire que la création.';
            $supplierCreateIntro = 'Le design et les règles visuelles sont alignés sur le formulaire d\'ajout. Toute mise à jour renvoie la fiche en validation comptable.';
            $supplierCreateSubmitLabel = 'Enregistrer le fournisseur';
            $supplierCreateCancelHref = 'mes_fournisseurs_employe.php';
            $supplierCreateCancelLabel = 'Retour à mes fournisseurs';
            $supplierCreateCodeValue = (string) ($fournisseur['code_fournisseur'] ?? '');
            $supplierCreateCodeHint = 'Référence verrouillée, conservée automatiquement.';
            $supplierCreateHiddenFields = [
                'id' => (string) $id,
                'action' => 'update',
            ];
            $supplierCreateOld = $fournisseur;
            $supplierCreateOld['category_ids'] = array_values(array_map('intval', $fournisseur['category_ids'] ?? []));
            $supplierCreateOld['location_latitude'] = (string) ($supplierLocationPickerInitialPosition['latitude'] ?? '');
            $supplierCreateOld['location_longitude'] = (string) ($supplierLocationPickerInitialPosition['longitude'] ?? '');
            $supplierCreateOld['location_source'] = (string) ($supplierLocationPickerInitialPosition['source'] ?? '');
            $supplierCreateOld['location_label'] = (string) ($supplierLocationPickerInitialPosition['location_label'] ?? '');
            $supplierCreateExistingFiles = [
                'supplier_logo' => ['path' => (string) ($fournisseur['logo_path'] ?? '')],
                'supplier_photo' => ['path' => (string) ($fournisseur['photo_path'] ?? '')],
                'supplier_cni' => ['path' => (string) ($fournisseur['cni_path'] ?? '')],
                'supplier_trade_register' => ['path' => (string) ($fournisseur['trade_register_path'] ?? '')],
                'supplier_tax_existence' => ['path' => (string) ($fournisseur['tax_existence_path'] ?? '')],
                'supplier_tax_clearance' => ['path' => (string) ($fournisseur['tax_clearance_path'] ?? '')],
                'supplier_social_clearance' => ['path' => (string) ($fournisseur['social_clearance_path'] ?? '')],
                'supplier_manager_cni' => ['path' => (string) ($fournisseur['manager_cni_path'] ?? '')],
            ];
            require __DIR__ . '/../partials/supplier_create_form.php';
            ?>

            <section id="incidents" class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Incidents</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Historique des incidents fournisseur</h2>
                    </div>
                    <div class="text-sm text-slate-600">Permet de tracer les problèmes rencontrés.</div>
                </div>

                <form method="post" class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <input type="hidden" name="id" value="<?= (int) $id ?>">
                    <input type="hidden" name="action" value="add_incident">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Date</label>
                            <input type="date" name="incident_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-200">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700">Gravité</label>
                            <select name="gravite" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-200">
                                <option value="low">Faible</option>
                                <option value="medium" selected>Moyenne</option>
                                <option value="high">Élevée</option>
                                <option value="critical">Critique</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700">Titre</label>
                            <input name="titre" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-200">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-slate-700">Description</label>
                            <textarea name="description" rows="3" required class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-200"></textarea>
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Enregistrer l'incident</button>
                    </div>
                </form>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-left text-slate-500">
                                <th class="py-3 pr-4">Date</th>
                                <th class="py-3 pr-4">Titre</th>
                                <th class="py-3 pr-4">Gravité</th>
                                <th class="py-3">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$incidents): ?>
                                <tr>
                                    <td colspan="4" class="py-6 text-center text-slate-500">Aucun incident enregistré.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($incidents as $incident): ?>
                                    <tr class="border-b border-slate-100 text-slate-800 last:border-b-0">
                                        <td class="py-3 pr-4 text-slate-600"><?= htmlspecialchars((string) ($incident['incident_date'] ?? '')) ?></td>
                                        <td class="py-3 pr-4 font-semibold text-slate-950"><?= htmlspecialchars((string) ($incident['titre'] ?? '')) ?></td>
                                        <td class="py-3 pr-4 text-slate-600"><?= htmlspecialchars((string) ($incident['gravite'] ?? 'medium')) ?></td>
                                        <td class="py-3 text-slate-600"><?= nl2br(htmlspecialchars((string) ($incident['description'] ?? ''))) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
