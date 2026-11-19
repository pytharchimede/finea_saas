<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
$supplierCreateHideHeader=true;
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../services/FournisseurWorkflowNotificationService.php';
require_once __DIR__ . '/../model/PositioningSettingsManager.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();
$model = new \FournisseurRepository($pdo);
$positioningSettingsManager = new \PositioningSettingsManager($pdo);

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$pageTitle = 'Ajouter un fournisseur';
$layoutRootBase = '../';
$activeEmployeeMenu = 'fournisseurs';

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$model->isReady()) {
        $flashError = $model->getInitError() ?: 'Module fournisseurs indisponible.';
    } else {
        $result = $model->create($_POST, $currentUserId, $_FILES);
        if (!empty($result['ok']) && !empty($result['id'])) {
            $createdSupplier = $model->getById((int) $result['id']);
            if ($createdSupplier) {
                try {
                    $notifier = new \FournisseurWorkflowNotificationService($pdo);
                    $notifier->notifySubmitted($createdSupplier, [
                        'user_id' => $currentUserId,
                        'name' => (string) ($_SESSION['user_name'] ?? ''),
                    ], 'created');
                } catch (Throwable $e) {
                }
            }

            $_SESSION['fournisseur_flash_success'] = 'Fournisseur créé' . (!empty($result['code_fournisseur']) ? (' (Code: ' . (string) $result['code_fournisseur'] . ')') : '') . ' et envoyé en validation comptable.';
            header('Location: mes_fournisseurs_employe.php');
            exit;
        }

        $flashError = (string) ($result['error'] ?? 'Impossible de créer le fournisseur.');
    }
}

$categoryOptions = $model->listCategories(false);
$legalFormOptions = $model->listLegalForms(false);
$supplierLocationPickerSettings = $positioningSettingsManager->get('supplier');
$supplierLocationPickerInitialPosition = null;

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$supplierCreateTheme = [
    'accent_label_class' => 'text-cyan-600',
    'accent_panel_class' => 'border-cyan-200 bg-cyan-50 text-cyan-900',
    'accent_ring_class' => 'focus:ring-cyan-200',
    'accent_button_class' => 'bg-cyan-600 hover:bg-cyan-700 text-white',
    'muted_button_class' => 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50',
];
$supplierCreateHeading = 'Ajouter un fournisseur';
$supplierCreateEyebrow = 'Création';
$supplierCreateDescription = 'Préparez un dossier fournisseur à envoyer en validation comptable.';
$supplierCreateIntro = 'Créez le fournisseur ici, puis la comptabilité le validera avant qu\'il puisse être utilisé dans un décaissement.';
$supplierCreateSubmitLabel = 'Créer le fournisseur';
$supplierCreateCancelHref = 'mes_fournisseurs_employe.php';
$supplierCreateCancelLabel = 'Retour à mes fournisseurs';
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <?php supplierWorkspaceHeader('Nouveau fournisseur', 'Vérifiez le NCC via FNE, puis complétez les coordonnées et les documents.'); ?>
        <?php if ($flashSuccess): ?>
            <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                <?= htmlspecialchars($flashSuccess) ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <?= htmlspecialchars($flashError) ?>
            </div>
        <?php endif; ?>

        <?php if (!$model->isReady()): ?>
            <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <?= htmlspecialchars($model->getInitError() ?: 'Le module fournisseurs n\'a pas pu s\'initialiser.') ?>
            </div>
        <?php endif; ?>

        <?php require __DIR__ . '/../partials/supplier_create_form.php'; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
