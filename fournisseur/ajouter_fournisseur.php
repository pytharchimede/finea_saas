<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
$supplierCreateHideHeader=true;
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__.'/../services/SupplierExceptionPolicy.php';
if (!SupplierExceptionPolicy::allowed()) \AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FournisseurController.php';

$pageTitle = 'Ajouter un fournisseur';
$activeMenu = 'fournisseur';

$pdo = \Database::getConnection();
$controller = new \FournisseurController($pdo);
$model = $controller->model();

$isValidator = SupplierExceptionPolicy::allowed() || \AccessManager::hasPermission('acces_compta') || \AccessManager::hasPermission('acces_admin') || \AccessManager::hasPermission('acces_fournisseur');
$isEmployeeOnly = \AccessManager::hasPermission('acces_employe') && !$isValidator;
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

if ($isEmployeeOnly) {
    header('Location: ../employe/ajouter_fournisseur_employe.php');
    exit;
}

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$model->isReady()) {
        $flashError = $model->getInitError() ?: 'Module indisponible.';
    } else {
        $res = $controller->handleCreate($_POST, $_FILES, $currentUserId);
        if (!empty($res['ok'])) {
            \AuditTrail::log('supplier_created', [
                'module' => 'fournisseur',
                'action' => 'create_fournisseur',
                'entity_type' => 'supplier',
                'entity_id' => isset($res['id']) ? (string) $res['id'] : null,
                'entity_label' => (string) ($_POST['raison_sociale'] ?? 'Nouveau fournisseur'),
                'result' => 'success',
                'details' => [
                    'code_fournisseur' => $res['code_fournisseur'] ?? null,
                    'identity_method'=>$_POST['identity_method']??'fne',
                    'exception_reason'=>($_POST['identity_method']??'')==='exception'?($_POST['exception_reason']??null):null,
                ],
            ], $pdo);

            $_SESSION['fournisseur_flash_success'] = 'Fournisseur créé' . (!empty($res['code_fournisseur']) ? (' (Code: ' . (string) $res['code_fournisseur'] . ')') : '') . (($_POST['identity_method']??'')==='exception' ? ' — dérogation autorisée.' : ' et envoyé en validation.');
            header('Location: fournisseurs.php');
            exit;
        }

        \AuditTrail::log('supplier_create_failed', [
            'module' => 'fournisseur',
            'action' => 'create_fournisseur',
            'entity_type' => 'supplier',
            'entity_label' => (string) ($_POST['raison_sociale'] ?? 'Nouveau fournisseur'),
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'model_create_failed',
                'error_message' => $res['error'] ?? null,
            ],
        ], $pdo);

        $flashError = (string) ($res['error'] ?? 'Impossible de créer le fournisseur.');
    }
}

$viewData = $controller->createView();
$categoryOptions = $viewData['categoryOptions'];
$legalFormOptions = $viewData['legalFormOptions'];
$supplierLocationPickerSettings = $viewData['supplierLocationPickerSettings'];
$supplierLocationPickerInitialPosition = null;

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

$supplierCreateTheme = [
    'accent_label_class' => 'text-indigo-600',
    'accent_panel_class' => 'border-sky-200 bg-sky-50 text-sky-900',
    'accent_ring_class' => 'focus:ring-indigo-200',
    'accent_button_class' => 'bg-indigo-600 hover:bg-indigo-700 text-white',
    'muted_button_class' => 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50',
];
$supplierCreateHeading = 'Ajouter un fournisseur';
$supplierCreateEyebrow = 'Fournisseurs';
$supplierCreateDescription = 'Création séparée du listing pour une saisie plus calme et plus propre.';
$supplierCreateIntro = 'Préparez ici la fiche fournisseur. Après création, elle partira en validation comptable avant usage dans les décaissements.';
$supplierCreateSubmitLabel = 'Créer';
$supplierCreateCancelHref = 'fournisseurs.php';
$supplierCreateCancelLabel = 'Retour au listing';
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <?php supplierWorkspaceHeader('Nouveau fournisseur', 'Choisissez le pays et le mode de contrôle. L’identifiant recherché s’adapte automatiquement.'); ?>
        <?php if ($flashSuccess): ?>
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                <?= htmlspecialchars($flashSuccess) ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($flashError) ?>
            </div>
        <?php endif; ?>

        <?php if (!$model->isReady()): ?>
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <?= htmlspecialchars($model->getInitError() ?: 'Le module fournisseurs n\'a pas pu initialiser ses tables.') ?>
            </div>
        <?php endif; ?>

        <?php require __DIR__ . '/../partials/supplier_create_form.php'; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>

