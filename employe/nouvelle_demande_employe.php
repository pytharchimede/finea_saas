<?php
require_once __DIR__.'/../services/FebSubmissionContent.php';
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
$febAssetVersion = bin2hex(random_bytes(8));
$_SESSION['feb_preview_csrf'] ??= bin2hex(random_bytes(32));

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CompanyBranding.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/FebOcrSettings.php';
require_once __DIR__ . '/../model/FebAiSettings.php';
require_once __DIR__ . '/../controllers/AiAssistantController.php';
require_once __DIR__ . '/../services/UrgentDisbursementService.php';
require_once __DIR__ . '/../services/FebPriceChallengeService.php';
require_once __DIR__ . '/../services/FebQuoteLineService.php';
require_once __DIR__ . '/../services/FebWorkflowMigrationService.php';
require_once __DIR__ . '/../services/FebCategoryMigrationService.php';
require_once __DIR__ . '/../services/FinancialPlanningSeparationService.php';
require_once __DIR__ . '/../services/ChantierPauseService.php';
require_once __DIR__ . '/../services/FebAiDetectionService.php';
require_once __DIR__ . '/../services/FebDocumentComplianceService.php';
require_once __DIR__ . '/../services/FebHumanDocumentReviewService.php';
require_once __DIR__ . '/../services/DevisOfferProjectMigrationService.php';
require_once __DIR__ . '/../recurring_needs/services/RecurringNeedService.php';
require_once __DIR__ . '/../contrats/model/ContratSousTraitance.php';

$pdo = \Database::getConnection();
(new \DevisOfferProjectMigrationService($pdo))->ensureSchema();
// Prépare le schéma FEB dès l'ouverture du parcours employé après une mise en ligne.
\FebWorkflowMigrationService::ensure($pdo);
$manager = new \EmployeeLegalRequestManager($pdo);
$urgentDisbursementService = new \UrgentDisbursementService($pdo);
$urgentBlockedChantierMap = $urgentDisbursementService->blockedChantierMap();
$recurringNeedService = new \RecurringNeedService($pdo);

$febVerificationPolicy = new \FebOcrSettings();
$febVerificationSettings = $febVerificationPolicy->load();
$febOcrEnabled = !empty($febVerificationSettings['enabled']);
$febVerificationMode = $febVerificationPolicy->effectiveMode();
$febAutomatedVerificationEnabled = $febOcrEnabled && $febVerificationMode === 'ai';
$febHumanReviewers = $febVerificationMode === 'human' ? (new \FebHumanDocumentReviewService($pdo))->reviewers() : [];
$febAiSettings = (new \FebAiSettings())->load();
$febTextGenerationEnabled = !empty($febAiSettings['conversation_enabled']);
$febRejectUneditedGeneratedText = !empty($febAiSettings['reject_unedited_generated_text']);
$febAiDetectionEnabled = (string)($febAiSettings['detection_mode'] ?? 'off') !== 'off';
if ($febAiDetectionEnabled && empty($_SESSION['feb_ai_detection_csrf'])) $_SESSION['feb_ai_detection_csrf'] = bin2hex(random_bytes(32));
$febAiDetectionCsrf = (string)($_SESSION['feb_ai_detection_csrf'] ?? '');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userEmail = (string) ($_SESSION['mail_pro'] ?? '');
$personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
$recruitmentFunction = mb_strtolower(trim((string)($personnelProfile['fonction_label'] ?? '')));
$canRequestRecruitment = !empty($_SESSION['permissions']['acces_direction'])
    || !empty($_SESSION['permissions']['acces_admin'])
    || preg_match('/\b(responsable|directeur|directrice|chef(?:fe)?(?: de)? service|manager)\b/u', $recruitmentFunction) === 1;

$requester = [
    'user_id' => $userId,
    'name' => employeeCurrentUserName(),
    'email' => $userEmail,
];

$type = $manager->normalizeType((string) ($_GET['type'] ?? ''));
$def = $type !== '' ? $manager->getTypeDefinition($type) : null;
$urgentRegularizationId = max(0, (int)($_GET['urgent_id'] ?? $_POST['urgent_id'] ?? 0));
$urgentRegularization = $urgentRegularizationId > 0 ? $urgentDisbursementService->repository()->findById($urgentRegularizationId) : null;
$isUrgentFebRegularization = $type === 'feb' && $urgentRegularizationId > 0
    && is_array($urgentRegularization)
    && (int)($urgentRegularization['requester_user_id'] ?? 0) === $userId
    && in_array((string)($urgentRegularization['status'] ?? ''), ['decaissed', 'regularization_pending'], true);
$urgentBlockMessage = $urgentDisbursementService->blockMessageForUser($userId);
$openUrgentRegularizations = $urgentDisbursementService->listOpenRegularizationsForUser($userId);
if ($openUrgentRegularizations && !($type === 'feb' && $urgentRegularizationId > 0)) {
    if ($urgentBlockMessage === '') {
        $urgentBlockMessage = 'Regularisation requise: creez la FEB associee a votre decaissement urgent avant toute autre demande.';
    }
    $urgentRegularizationId = (int)($openUrgentRegularizations[0]['id'] ?? 0);
    $urgentRegularization = $urgentRegularizationId > 0 ? $openUrgentRegularizations[0] : null;
    $type = 'feb';
    $def = $manager->getTypeDefinition($type);
}
$febAiCsrfToken = '';
$febProofUploadCsrfToken = '';
if ($type === 'feb') {
    if (empty($_SESSION['feb_supplier_unblock_csrf'])) {
        $_SESSION['feb_supplier_unblock_csrf'] = bin2hex(random_bytes(32));
    }
    // La génération rédactionnelle est désactivée, mais les saisies validées
    // restent enregistrées comme exemples d'apprentissage passif.
    if ($febTextGenerationEnabled) {
        try {
            $febAiCsrfToken = (new \AiAssistantController($pdo))->csrfToken();
        } catch (Throwable $e) {
            $febAiCsrfToken = '';
        }
    }
    if (empty($_SESSION['feb_proof_upload_csrf'])) {
        $_SESSION['feb_proof_upload_csrf'] = bin2hex(random_bytes(24));
    }
    $febProofUploadCsrfToken = (string) $_SESSION['feb_proof_upload_csrf'];
}

$editRequestId = isset($_GET['request_id']) && is_numeric($_GET['request_id']) ? (int) $_GET['request_id'] : 0;
if ($editRequestId <= 0 && isset($_POST['request_id']) && is_numeric($_POST['request_id'])) {
    $editRequestId = (int) $_POST['request_id'];
}

$editingRequest = null;
$isEditMode = false;
if ($editRequestId > 0) {
    $candidate = $manager->getRequestById($editRequestId);
    if (
        is_array($candidate)
        && $manager->canRequesterEditRequest($candidate, $userId)
        && (string) ($candidate['request_type'] ?? '') === $type
        && in_array($type, ['permission_absence', 'conge', 'retard', 'reclamation_salariale', 'heures_supp', 'avance_salaire', 'recrutement', 'feb'], true)
    ) {
        $editingRequest = $candidate;
        $isEditMode = true;
    }
}

$existingPayload = is_array($editingRequest['payload'] ?? null) ? (array) $editingRequest['payload'] : [];

$typeIcons = [
    'permission_absence' => 'fa-calendar-minus',
    'conge' => 'fa-umbrella-beach',
    'retard' => 'fa-clock-rotate-left',
    'pret' => 'fa-hand-holding-usd',
    'avance_salaire' => 'fa-wallet',
    'heures_supp' => 'fa-clock',
    'reclamation_salariale' => 'fa-file-invoice-dollar',
    'recrutement' => 'fa-user-plus',
    'feb' => 'fa-clipboard-list',
];

$typeThemes = [
    'permission_absence' => [
        'labelText' => 'text-sky-600',
        'iconBg' => 'bg-sky-600',
        'btn' => 'bg-sky-600 hover:bg-sky-700 focus:ring-sky-300',
        'softBg' => 'bg-sky-50',
        'softBorder' => 'border-sky-200',
        'softText' => 'text-sky-700',
    ],
    'conge' => [
        'labelText' => 'text-cyan-600',
        'iconBg' => 'bg-cyan-600',
        'btn' => 'bg-cyan-600 hover:bg-cyan-700 focus:ring-cyan-300',
        'softBg' => 'bg-cyan-50',
        'softBorder' => 'border-cyan-200',
        'softText' => 'text-cyan-700',
    ],
    'retard' => [
        'labelText' => 'text-orange-600',
        'iconBg' => 'bg-orange-600',
        'btn' => 'bg-orange-600 hover:bg-orange-700 focus:ring-orange-300',
        'softBg' => 'bg-orange-50',
        'softBorder' => 'border-orange-200',
        'softText' => 'text-orange-700',
    ],
    'pret' => [
        'labelText' => 'text-amber-600',
        'iconBg' => 'bg-amber-600',
        'btn' => 'bg-amber-600 hover:bg-amber-700 focus:ring-amber-300',
        'softBg' => 'bg-amber-50',
        'softBorder' => 'border-amber-200',
        'softText' => 'text-amber-700',
    ],
    'avance_salaire' => [
        'labelText' => 'text-emerald-600',
        'iconBg' => 'bg-emerald-600',
        'btn' => 'bg-emerald-600 hover:bg-emerald-700 focus:ring-emerald-300',
        'softBg' => 'bg-emerald-50',
        'softBorder' => 'border-emerald-200',
        'softText' => 'text-emerald-700',
    ],
    'heures_supp' => [
        'labelText' => 'text-violet-600',
        'iconBg' => 'bg-violet-600',
        'btn' => 'bg-violet-600 hover:bg-violet-700 focus:ring-violet-300',
        'softBg' => 'bg-violet-50',
        'softBorder' => 'border-violet-200',
        'softText' => 'text-violet-700',
    ],
    'reclamation_salariale' => [
        'labelText' => 'text-rose-600',
        'iconBg' => 'bg-rose-600',
        'btn' => 'bg-rose-600 hover:bg-rose-700 focus:ring-rose-300',
        'softBg' => 'bg-rose-50',
        'softBorder' => 'border-rose-200',
        'softText' => 'text-rose-700',
    ],
    'feb' => [
        'labelText' => 'text-indigo-600',
        'iconBg' => 'bg-indigo-600',
        'btn' => 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-300',
        'softBg' => 'bg-indigo-50',
        'softBorder' => 'border-indigo-200',
        'softText' => 'text-indigo-700',
    ],
    'default' => [
        'labelText' => 'text-indigo-600',
        'iconBg' => 'bg-indigo-600',
        'btn' => 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-300',
        'softBg' => 'bg-slate-50',
        'softBorder' => 'border-slate-200',
        'softText' => 'text-slate-700',
    ],
];

$typeTheme = $typeThemes[$type] ?? $typeThemes['default'];

$pageTitle = $isEditMode ? 'Modifier demande' : 'Nouvelle demande';
$layoutRootBase = '../';
$activeEmployeeMenu = 'actes';

function normalizeNumber(?string $value): float
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0.0;
    }
    $value = str_replace([' ', ','], ['', '.'], $value);
    return (float) $value;
}


/**
 * Summary of febNormalizeUploadedFiles
 * @param array $files
 * @throws RuntimeException
 * @return array{error: int, name: string, size: int, tmp_name: string, type: string[]}
 * @phpstan-return array{error: int, name: string, size: int, tmp_name: string, type: string}
 * Sert à normaliser la structure des fichiers uploadés, que ce soit pour un champ simple ou multiple. 
 * Cela facilite le traitement ultérieur des fichiers sans se soucier de la structure d'origine de $_FILES.
 */
function febNormalizeUploadedFiles(array $files): array
{
    $out = [];
    if (!isset($files['name'])) {
        return $out;
    }
    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $out[] = [
                'name' => (string) ($files['name'][$i] ?? ''),
                'type' => (string) ($files['type'][$i] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
        }
        return $out;
    }

    $out[] = [
        'name' => (string) ($files['name'] ?? ''),
        'type' => (string) ($files['type'] ?? ''),
        'tmp_name' => (string) ($files['tmp_name'] ?? ''),
        'error' => (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE),
        'size' => (int) ($files['size'] ?? 0),
    ];

    return $out;
}

function febStoreUploadedDocument(array $file, string $dirAbs, string $dirRel, array $allowedExt, int $maxBytes, string $invalidPrefix, string $savePrefix): array
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException($savePrefix . ': ' . (string) ($file['name'] ?? ''));
    }

    $orig = trim((string) ($file['name'] ?? ''));
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $mime = (string) ($file['type'] ?? '');

    if ($orig === '' || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException($invalidPrefix . '.');
    }
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException($invalidPrefix . ' trop volumineux: ' . $orig);
    }

    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        throw new RuntimeException($invalidPrefix . ' au format non supporte: ' . $orig);
    }

    if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
        throw new RuntimeException($savePrefix . ': le serveur n’a pas pu créer automatiquement le dossier de stockage. Le fichier est correct.');
    }
    if (!is_writable($dirAbs)) {
        throw new RuntimeException($savePrefix . ': le dossier existe mais le serveur n’a pas le droit d’y enregistrer le fichier. Le fichier est correct.');
    }

    $newName = bin2hex(random_bytes(12)) . '.' . $ext;
    $targetAbs = $dirAbs . '/' . $newName;
    if (!move_uploaded_file($tmp, $targetAbs)) {
        throw new RuntimeException($savePrefix . ': ' . $orig);
    }

    $sha = '';
    try {
        $sha = hash_file('sha256', $targetAbs) ?: '';
    } catch (Throwable $e) {
        $sha = '';
    }

    return [
        'name' => $orig,
        'stored_name' => $newName,
        'url' => '/' . str_replace('\\', '/', $dirRel . '/' . $newName),
        'mime' => $mime,
        'size' => $size,
        'sha256' => $sha,
    ];
}


function febNormalizeDocumentReference(?string $value): string
{
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/\s+/', '', $value) ?? '';
    $value = str_replace(['_', '.', ',', ';', ':'], '-', $value);
    $value = preg_replace('/-+/', '-', $value) ?? '';
    return trim($value, '-');
}

function febEnsureDocumentRegistryTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS feb_document_registry (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id INT UNSIGNED NOT NULL,
        doc_type VARCHAR(40) NOT NULL,
        doc_type_label VARCHAR(120) NOT NULL,
        doc_number VARCHAR(120) NOT NULL,
        doc_number_normalized VARCHAR(140) NOT NULL,
        supplier_id INT UNSIGNED NULL,
        supplier_name VARCHAR(190) NULL,
        file_name VARCHAR(255) NULL,
        file_url VARCHAR(500) NULL,
        file_sha256 CHAR(64) NULL,
        requester_user_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_feb_doc_request (request_id),
        KEY idx_feb_doc_type_number (doc_type, doc_number_normalized),
        KEY idx_feb_doc_supplier (supplier_id, supplier_name),
        UNIQUE KEY uq_feb_invoice_supplier_number (doc_type, supplier_id, doc_number_normalized)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Sécurité pour les bases déjà en production : ajout automatique des colonnes/index manquants.
    $requiredColumns = [
        'request_id' => 'INT UNSIGNED NOT NULL',
        'doc_type' => 'VARCHAR(40) NOT NULL',
        'doc_type_label' => 'VARCHAR(120) NOT NULL',
        'doc_number' => 'VARCHAR(120) NOT NULL',
        'doc_number_normalized' => 'VARCHAR(140) NOT NULL',
        'supplier_id' => 'INT UNSIGNED NULL',
        'supplier_name' => 'VARCHAR(190) NULL',
        'file_name' => 'VARCHAR(255) NULL',
        'file_url' => 'VARCHAR(500) NULL',
        'file_sha256' => 'CHAR(64) NULL',
        'requester_user_id' => 'INT UNSIGNED NULL',
        'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];

    $existing = [];
    $stmt = $pdo->query('SHOW COLUMNS FROM feb_document_registry');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $existing[(string) ($col['Field'] ?? '')] = true;
    }
    foreach ($requiredColumns as $name => $definition) {
        if (empty($existing[$name])) {
            $pdo->exec('ALTER TABLE feb_document_registry ADD COLUMN `' . $name . '` ' . $definition);
        }
    }

    $indexes = [];
    $stmt = $pdo->query('SHOW INDEX FROM feb_document_registry');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
        $indexes[(string) ($idx['Key_name'] ?? '')] = true;
    }
    if (empty($indexes['idx_feb_doc_request'])) {
        $pdo->exec('ALTER TABLE feb_document_registry ADD INDEX idx_feb_doc_request (request_id)');
    }
    if (empty($indexes['idx_feb_doc_type_number'])) {
        $pdo->exec('ALTER TABLE feb_document_registry ADD INDEX idx_feb_doc_type_number (doc_type, doc_number_normalized)');
    }
    if (empty($indexes['idx_feb_doc_supplier'])) {
        $pdo->exec('ALTER TABLE feb_document_registry ADD INDEX idx_feb_doc_supplier (supplier_id, supplier_name)');
    }
    if (empty($indexes['uq_feb_invoice_supplier_number'])) {
        try {
            $pdo->exec('ALTER TABLE feb_document_registry ADD UNIQUE KEY uq_feb_invoice_supplier_number (doc_type, supplier_id, doc_number_normalized)');
        } catch (Throwable $e) {
            // Si des doublons historiques existent déjà, on ne bloque pas la création de FEB.
            // Les nouvelles demandes sont quand même contrôlées en PHP avant insertion.
        }
    }
}

function febFindDuplicateInvoiceNumber(PDO $pdo, int $supplierId, string $invoiceNumber, int $excludeRequestId = 0): ?array
{
    if ($supplierId <= 0 || trim($invoiceNumber) === '') {
        return null;
    }
    febEnsureDocumentRegistryTable($pdo);
    $sql = 'SELECT * FROM feb_document_registry WHERE doc_type = :doc_type AND supplier_id = :supplier_id AND doc_number_normalized = :doc_number_normalized';
    $params = [
        ':doc_type' => 'invoice',
        ':supplier_id' => $supplierId,
        ':doc_number_normalized' => febNormalizeDocumentReference($invoiceNumber),
    ];
    if ($excludeRequestId > 0) {
        $sql .= ' AND request_id <> :request_id';
        $params[':request_id'] = $excludeRequestId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function febRegisterDocument(PDO $pdo, int $requestId, string $docType, string $docTypeLabel, string $docNumber, ?int $supplierId, ?string $supplierName, array $file, int $requesterUserId): void
{
    $docNumber = trim($docNumber);
    if ($requestId <= 0 || $docType === '' || $docNumber === '') {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO feb_document_registry
        (request_id, doc_type, doc_type_label, doc_number, doc_number_normalized, supplier_id, supplier_name, file_name, file_url, file_sha256, requester_user_id)
        VALUES
        (:request_id, :doc_type, :doc_type_label, :doc_number, :doc_number_normalized, :supplier_id, :supplier_name, :file_name, :file_url, :file_sha256, :requester_user_id)
        ON DUPLICATE KEY UPDATE
            request_id = VALUES(request_id),
            doc_type_label = VALUES(doc_type_label),
            supplier_name = VALUES(supplier_name),
            file_name = VALUES(file_name),
            file_url = VALUES(file_url),
            file_sha256 = VALUES(file_sha256),
            requester_user_id = VALUES(requester_user_id),
            updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([
        ':request_id' => $requestId,
        ':doc_type' => $docType,
        ':doc_type_label' => $docTypeLabel,
        ':doc_number' => $docNumber,
        ':doc_number_normalized' => febNormalizeDocumentReference($docNumber),
        ':supplier_id' => $supplierId && $supplierId > 0 ? $supplierId : null,
        ':supplier_name' => $supplierName !== null && trim($supplierName) !== '' ? trim($supplierName) : null,
        ':file_name' => (string) ($file['name'] ?? ''),
        ':file_url' => (string) ($file['url'] ?? ''),
        ':file_sha256' => (string) ($file['sha256'] ?? ''),
        ':requester_user_id' => $requesterUserId > 0 ? $requesterUserId : null,
    ]);
}

function febRegisterPayloadDocuments(PDO $pdo, int $requestId, array $payload, int $requesterUserId): void
{
    if ($requestId <= 0) {
        return;
    }
    febEnsureDocumentRegistryTable($pdo);
    $pdo->prepare('DELETE FROM feb_document_registry WHERE request_id = :request_id')->execute([':request_id' => $requestId]);

    $pvNumber = trim((string) ($payload['pv_reception_number'] ?? ''));
    $pvFile = is_array($payload['pv_reception_document'] ?? null) ? (array) $payload['pv_reception_document'] : [];
    if ($pvNumber !== '') {
        febRegisterDocument($pdo, $requestId, 'pv_reception', 'PV de réception', $pvNumber, null, null, $pvFile, $requesterUserId);
    }

    $supportNumber = trim((string) ($payload['supporting_document_number'] ?? ''));
    $supportFiles = array_values(array_filter((array) ($payload['justificatifs'] ?? []), static fn($item): bool => is_array($item)));
    if ($supportNumber !== '') {
        foreach ($supportFiles as $idx => $file) {
            febRegisterDocument($pdo, $requestId, 'supporting_document', 'Justificatif externe', $supportNumber . (count($supportFiles) > 1 ? '-' . ($idx + 1) : ''), null, null, $file, $requesterUserId);
        }
    }

    $invoiceNumber = trim((string) ($payload['invoice_number'] ?? ''));
    $invoiceFile = is_array($payload['invoice_document'] ?? null) ? (array) $payload['invoice_document'] : [];
    $invoiceSupplier = is_array($payload['invoice_supplier'] ?? null) ? (array) $payload['invoice_supplier'] : [];
    if ($invoiceNumber !== '') {
        febRegisterDocument(
            $pdo,
            $requestId,
            'invoice',
            'Facture fournisseur',
            $invoiceNumber,
            (int) ($invoiceSupplier['id'] ?? 0),
            (string) ($invoiceSupplier['nom'] ?? ''),
            $invoiceFile,
            $requesterUserId
        );
    }

    $bciNumber = trim((string) ($payload['bci_number'] ?? ''));
    if ($bciNumber !== '') {
        febRegisterDocument($pdo, $requestId, 'bci', 'BCI - Bon de commande interne', $bciNumber, null, null, [], $requesterUserId);
    }
}

function splitEmployeeIdentity(array $personnelProfile): array
{
    $sessionNom = strtoupper(trim((string) ($_SESSION['nom'] ?? '')));
    $sessionPrenoms = strtoupper(trim((string) ($_SESSION['prenom'] ?? '')));
    if ($sessionNom !== '' || $sessionPrenoms !== '') {
        return [
            'nom' => $sessionNom,
            'prenoms' => $sessionPrenoms,
        ];
    }

    $fullName = preg_replace('/\s+/', ' ', trim((string) ($personnelProfile['full_name'] ?? '')));
    if ($fullName === '') {
        return ['nom' => '', 'prenoms' => ''];
    }

    $parts = explode(' ', $fullName, 2);
    return [
        'nom' => strtoupper((string) ($parts[0] ?? '')),
        'prenoms' => strtoupper((string) ($parts[1] ?? '')),
    ];
}

$errors = [];
$usedFebProofTokens = [];
$leaveSituation = null;
$leaveRequestBlocked = false;

if ($type === '' || !$def) {
    $errors[] = 'Type de demande invalide.';
}
if ($type === 'recrutement' && !$canRequestRecruitment) {
    $errors[] = 'Les demandes de recrutement sont réservées aux responsables et aux membres de la direction.';
}

if (in_array($type, ['permission_absence', 'conge', 'retard', 'pret', 'avance_salaire', 'heures_supp', 'reclamation_salariale', 'recrutement', 'feb'], true) && !$personnelProfile) {
    $errors[] = 'Profil RH introuvable pour votre compte (email). Merci de contacter la RH.';
}

if ($type === 'conge' && $personnelProfile) {
    $leaveHireDate = (string) (($personnelProfile['start_date'] ?? '') ?: ($personnelProfile['hire_date'] ?? ''));
    $leaveSituation = $manager->calculateLeaveSituation(
        (int) ($personnelProfile['personnel_id'] ?? 0),
        $leaveHireDate,
        $isEditMode ? $editRequestId : 0,
        $userId
    );
    $leaveRequestBlocked = empty($leaveSituation['can_request']);
}

$allServices = [];
$approvedFebSuppliers = [];
$approvedFebSupplierMap = [];
$febChantierOptions = [];
$febDebourseOptions = [];
$febLigneOptions = [];
$febLigneDevisOptions = [];
$febContractOptions = [];
if ($type === 'feb') {
    $febRegularizationChantierId = $isUrgentFebRegularization ? (int)($urgentRegularization['chantier_id'] ?? 0) : 0;
    $febRegularizationChantierSql = $febRegularizationChantierId > 0 ? ' OR active_c.id=' . $febRegularizationChantierId : '';
    try {
        $contractManager = new \ContratSousTraitance($pdo);
        $febContractOptions = $contractManager->all();
    } catch (Throwable $e) {
        $febContractOptions = [];
    }
    try {
        $stmt = $pdo->query('SELECT DISTINCT ld.id,ld.devis_id,ld.designation,ld.total,COALESCE(c.id,dcl.chantier_id) chantier_id FROM ligne_devis_banamur ld LEFT JOIN chantier_banamur c ON c.devis_id=ld.devis_id AND (c.archived_at IS NULL' . ($febRegularizationChantierId > 0 ? ' OR c.id=' . $febRegularizationChantierId : '') . ') LEFT JOIN devis_chantier_links dcl ON dcl.devis_id=ld.devis_id INNER JOIN chantier_banamur active_c ON active_c.id=COALESCE(c.id,dcl.chantier_id) AND ((active_c.archived_at IS NULL AND '.ChantierPauseService::activePredicate($pdo,'active_c.id').')' . $febRegularizationChantierSql . ') ORDER BY ld.designation LIMIT 10000');
        $febLigneDevisOptions = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        $febLigneDevisOptions = [];
    }

    try {
        // Referentiel RH: ce sont les services crees depuis le parametrage RH.
        $stmt = $pdo->query('SELECT id_service, lib_service FROM service ORDER BY lib_service ASC');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $r) {
            $sid = (int) ($r['id_service'] ?? 0);
            $lbl = trim((string) ($r['lib_service'] ?? ''));
            if ($sid > 0 && $lbl !== '') {
                $allServices[$sid] = $lbl;
            }
        }
    } catch (Throwable $e) {
        $allServices = [];
    }

    try {
        $supplierModelForFeb = new \FournisseurRepository($pdo);
        $approvedFebSuppliers = $supplierModelForFeb->listForSelect(true, true);
        foreach ($approvedFebSuppliers as $supplier) {
            $sid = (int) ($supplier['id_fournisseur'] ?? 0);
            if ($sid > 0) {
                $approvedFebSupplierMap[$sid] = $supplier;
            }
        }
    } catch (Throwable $e) {
        $approvedFebSuppliers = [];
        $approvedFebSupplierMap = [];
    }

    try {
        $stmt = $pdo->query('SELECT id, code, titre, devis_id FROM chantier_banamur WHERE archived_at IS NULL AND '.ChantierPauseService::activePredicate($pdo,'id').' ORDER BY code, titre LIMIT 2500');
        $febChantierOptions = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        // Le chantier à régulariser doit rester accessible même s'il est en pause ou archivé.
        if ($isUrgentFebRegularization) {
            $urgentChantierId = (int)($urgentRegularization['chantier_id'] ?? 0);
            if ($urgentChantierId > 0 && !in_array($urgentChantierId, array_map(static fn($row) => (int)$row['id'], $febChantierOptions), true)) {
                $urgentChantierStmt = $pdo->prepare('SELECT id, code, titre, devis_id FROM chantier_banamur WHERE id=:id LIMIT 1');
                $urgentChantierStmt->execute(['id' => $urgentChantierId]);
                $urgentChantier = $urgentChantierStmt->fetch(PDO::FETCH_ASSOC);
                if ($urgentChantier) $febChantierOptions[] = $urgentChantier;
            }
        }
    } catch (Throwable $e) {
        $febChantierOptions = [];
    }

    try {
        $officialDebourseSql = \FinancialPlanningSeparationService::officialDeboursePredicate('db');
        $stmt = $pdo->query('
            SELECT db.id, db.devis_id, db.ligne_devis_id, db.montant_debourse,c.id chantier_id,
                   COALESCE(NULLIF(TRIM(ld.designation), ""), CONCAT("Déboursé #", db.id)) AS label,
                   COUNT(ldb.id) AS sous_lignes,
                   CASE WHEN db.date_debut IS NOT NULL AND db.date_fin IS NOT NULL THEN 1 ELSE 0 END AS has_dates,
                   CASE WHEN db.montant_debourse > 0 THEN 1 ELSE 0 END AS has_amount
            FROM debourse_banamur db
            LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
            LEFT JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=db.id
            LEFT JOIN devis_chantier_links dcl ON dcl.devis_id=db.devis_id
            INNER JOIN chantier_banamur c ON (c.devis_id=db.devis_id OR c.id=dcl.chantier_id) AND (c.archived_at IS NULL' . ($febRegularizationChantierId > 0 ? ' OR c.id=' . $febRegularizationChantierId : '') . ')
            WHERE ' . $officialDebourseSql . '
            GROUP BY db.id,db.devis_id,db.ligne_devis_id,db.montant_debourse,c.id,ld.designation,db.date_debut,db.date_fin
            ORDER BY db.devis_id,db.ligne_devis_id,sous_lignes DESC,has_dates DESC,has_amount DESC,db.id DESC
            LIMIT 5000
        ');
        $allDebourseRows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        // Même règle canonique que debourse/gerer.php : un seul déboursé financier
        // par ligne de devis. Les structures auxiliaires de réunion ne doivent pas
        // apparaître comme des choix financiers concurrents dans une FEB.
        $canonicalDebourses = [];
        foreach ($allDebourseRows as $debourseRow) {
            $key=(int)($debourseRow['devis_id']??0).':'.(int)($debourseRow['ligne_devis_id']??0);
            if(!isset($canonicalDebourses[$key]))$canonicalDebourses[$key]=$debourseRow;
        }
        $febDebourseOptions = array_values($canonicalDebourses);
    } catch (Throwable $e) {
        $febDebourseOptions = [];
    }

    try {
        $canonicalIds=array_values(array_filter(array_map(static fn(array $row):int=>(int)($row['id']??0),$febDebourseOptions)));
        if($canonicalIds){
            $marks=implode(',',array_fill(0,count($canonicalIds),'?'));
            $stmt=$pdo->prepare("SELECT l.id,l.debourse_id,db.devis_id,db.ligne_devis_id,l.designation,l.categorie,l.montant,l.date_debut,l.date_fin,
                COALESCE(NULLIF(TRIM(ld.designation),''),CONCAT('Déboursé #',db.id)) debourse_label
                FROM ligne_debourse_banamur l
                INNER JOIN debourse_banamur db ON db.id=l.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
                WHERE l.debourse_id IN ($marks)
                ORDER BY db.devis_id,ld.designation,l.designation,l.id");
            $stmt->execute($canonicalIds);
            $febLigneOptions=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        }else $febLigneOptions=[];
    } catch (Throwable $e) {
        $febLigneOptions = [];
    }
}

function isValidDateYmd(string $date): bool
{
    $date = trim($date);
    if ($date === '') {
        return false;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $date;
}

function normalizeAiLearningText(string $text): string
{
    $text = mb_strtolower(trim($text));
    return preg_replace('/\s+/', ' ', $text) ?: '';
}

function febAiImplicitStatus(string $generatedText, string $finalText): string
{
    $generated = normalizeAiLearningText($generatedText);
    $final = normalizeAiLearningText($finalText);
    if ($final === '') {
        return 'rejected_implicit';
    }
    if ($generated === '' || $generated === $final) {
        return 'accepted_implicit';
    }

    $generatedSample = mb_substr($generated, 0, 700);
    $finalSample = mb_substr($final, 0, 700);
    similar_text($generatedSample, $finalSample, $percent);

    return $percent >= 82.0 ? 'accepted_implicit' : 'corrected_implicit';
}

function recordFebAiImplicitLearning(PDO $pdo, int $userId, array $post, array $payload): void
{
    $fields = [
        'feb_description' => 'description',
        'feb_impact' => 'impact',
    ];

    try {
        $requestRepository = new \AiRequestRepository($pdo);
        $feedbackRepository = new \AiFeedbackRepository($pdo);
    } catch (Throwable $e) {
        return;
    }

    foreach ($fields as $aiField => $payloadKey) {
        $requestId = (int) ($post[$aiField . '_request_id'] ?? 0);
        if ($requestId <= 0) {
            continue;
        }

        $generatedText = trim((string) ($post[$aiField . '_generated'] ?? ''));
        $finalText = trim((string) ($payload[$payloadKey] ?? ''));
        if ($generatedText === '' && $finalText === '') {
            continue;
        }

        try {
            $request = $requestRepository->findById($requestId);
            if (!$request) {
                continue;
            }
            $requestUserId = (int) ($request['user_id'] ?? 0);
            if ($requestUserId > 0 && $requestUserId !== $userId) {
                continue;
            }

            $status = febAiImplicitStatus($generatedText, $finalText);
            $requestRepository->updateFinalText($requestId, $finalText, $status);
            $feedbackRepository->create([
                'ai_request_id' => $requestId,
                'user_id' => $userId > 0 ? $userId : null,
                'rating' => $status === 'rejected_implicit' ? 1 : ($status === 'accepted_implicit' ? 5 : 4),
                'accepted' => $status !== 'rejected_implicit',
                'corrected_text' => $finalText,
                'comment' => 'Signal automatique FEB: ' . $status,
            ]);
        } catch (Throwable $e) {
            continue;
        }
    }
}

function recordFebPassiveLearning(PDO $pdo, int $userId, array $payload): void
{
    foreach (['feb_description' => 'description', 'feb_impact' => 'impact'] as $field => $payloadKey) {
        $finalText = trim((string)($payload[$payloadKey] ?? ''));
        if ($finalText === '') {
            continue;
        }

        try {
            // Lorsqu'un score de détection existe, ne nourrir le corpus qu'avec
            // les formulations dont l'humanité est majoritaire.
            try {
                $quality = $pdo->prepare('SELECT human_score FROM feb_ai_text_detections WHERE text_hash=:hash ORDER BY id DESC LIMIT 1');
                $quality->execute(['hash' => hash('sha256', $finalText)]);
                $humanScore = $quality->fetchColumn();
                if ($humanScore !== false && (float)$humanScore < 60.0) {
                    continue;
                }
            } catch (Throwable $e) {
                // Ancienne installation sans table de détection : apprentissage habituel.
            }
            $duplicate = $pdo->prepare(
                "SELECT id FROM ai_requests
                 WHERE module = 'FEB' AND task_type = 'generer_note' AND request_type = 'feb'
                   AND input_text LIKE :field_marker AND TRIM(COALESCE(final_text, '')) = :final_text
                   AND (:user_id = 0 OR user_id = :user_id_match)
                 LIMIT 1"
            );
            $duplicate->execute([
                'field_marker' => '%Champ cible : ' . $field . '%',
                'final_text' => $finalText,
                'user_id' => $userId,
                'user_id_match' => $userId,
            ]);
            if ($duplicate->fetchColumn()) {
                continue;
            }

            (new \AiRequestRepository($pdo))->create([
                'user_id' => $userId > 0 ? $userId : null,
                'module' => 'FEB',
                'task_type' => 'generer_note',
                'request_type' => 'feb',
                'tone' => 'saisie_utilisateur',
                'input_text' => "Champ cible : {$field}\nSource : saisie utilisateur validée sans génération",
                'generated_text' => '',
                'final_text' => $finalText,
                'provider' => 'internal',
                'used_external_ai' => false,
                'status' => 'accepted',
            ]);
        } catch (Throwable $e) {
            // L'apprentissage ne doit jamais empêcher la soumission d'une FEB.
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $type !== '' && $def) {
    $payload = [];
    $title = '';

    if ($type === 'permission_absence') {
        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));

        $dateDepart = (string) ($_POST['date_depart'] ?? '');
        $nombreJours = (int) ($_POST['nombre_jours'] ?? 0);
        $motifDemande = trim((string) ($_POST['motif_demande'] ?? ''));

        if ($dateDepart === '' || !isValidDateYmd($dateDepart)) {
            $errors[] = 'Veuillez renseigner une date de départ valide.';
        }
        if ($nombreJours < 1) {
            $errors[] = 'Veuillez renseigner un nombre de jours valide.';
        }
        if ($motifDemande === '') {
            $errors[] = 'Veuillez renseigner le motif de la demande.';
        }

        $dateRetour = '';
        if (!$errors) {
            $dt = new DateTimeImmutable($dateDepart);
            $dt = $dt->modify('+' . $nombreJours . ' day');
            $dateRetour = $dt ? $dt->format('Y-m-d') : '';
            if ($dateRetour === '') {
                $errors[] = 'Impossible de calculer la date de retour.';
            }
        }

        $payload = [
            'nom' => $nom,
            'matricule' => $matricule,
            'fonction' => $fonction,
            'service' => $service,
            'date_depart' => $dateDepart,
            'nombre_jours' => $nombreJours,
            'date_retour' => $dateRetour,
            'motif_demande' => $motifDemande,
        ];
        $title = 'Permission d\'absence: ' . $dateDepart . ' → ' . $dateRetour;
    } elseif ($type === 'conge') {
        $personnelId = (int) ($personnelProfile['personnel_id'] ?? 0);
        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));

        $dateDebut = trim((string) ($_POST['date_debut'] ?? ''));
        $dateFin = trim((string) ($_POST['date_fin'] ?? ''));
        $motif = trim((string) ($_POST['motif'] ?? ''));
        $nombreJours = 0;

        if ($dateDebut === '' || !isValidDateYmd($dateDebut)) {
            $errors[] = 'Veuillez renseigner une date de debut de conge valide.';
        }
        if ($dateFin === '' || !isValidDateYmd($dateFin)) {
            $errors[] = 'Veuillez renseigner une date de fin de conge valide.';
        }
        if (!$errors) {
            $debutObj = new DateTimeImmutable($dateDebut);
            $finObj = new DateTimeImmutable($dateFin);
            if ($finObj < $debutObj) {
                $errors[] = 'La date de fin doit etre posterieure ou egale a la date de debut.';
            } else {
                $nombreJours = (int) $debutObj->diff($finObj)->days + 1;
            }
        }
        if ($motif === '') {
            $errors[] = 'Veuillez renseigner le motif du conge.';
        }
        if (!$leaveSituation) {
            $leaveHireDate = (string) (($personnelProfile['start_date'] ?? '') ?: ($personnelProfile['hire_date'] ?? ''));
            $leaveSituation = $manager->calculateLeaveSituation($personnelId, $leaveHireDate, $isEditMode ? $editRequestId : 0, $userId);
        }
        if (empty($leaveSituation['can_request'])) {
            $errors[] = (string) ($leaveSituation['message'] ?? 'Votre situation de conge doit etre initialisee par les RH avant toute demande. Veuillez contacter le service RH.');
        }
        if (!$errors && empty($leaveSituation['requires_special_validation']) && $nombreJours > (float) ($leaveSituation['balance_days'] ?? 0)) {
            $errors[] = 'Solde de conge insuffisant pour cette demande.';
        }

        $payload = [
            'personnel_id' => $personnelId,
            'nom' => $nom,
            'matricule' => $matricule,
            'fonction' => $fonction,
            'service' => $service,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'nombre_jours' => $nombreJours,
            'motif' => $motif,
            'leave_calculation' => [
                'source' => (string) ($leaveSituation['source'] ?? ''),
                'reference_date' => (string) ($leaveSituation['reference_date'] ?? ''),
                'initial_balance_days' => (float) ($leaveSituation['initial_balance_days'] ?? 0),
                'accrued_days' => (float) ($leaveSituation['accrued_days'] ?? 0),
                'consumed_days' => (float) ($leaveSituation['consumed_days'] ?? 0),
                'balance_before_request' => (float) ($leaveSituation['balance_days'] ?? 0),
                'requested_days' => $nombreJours,
                'balance_after_request' => round((float) ($leaveSituation['balance_days'] ?? 0) - $nombreJours, 2),
                'requires_special_validation' => !empty($leaveSituation['requires_special_validation']),
                'message' => (string) ($leaveSituation['message'] ?? ''),
            ],
        ];
        $title = 'Demande de conge: ' . $dateDebut . ' - ' . $dateFin;
    } elseif ($type === 'retard') {
        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));

        $dateRetard = trim((string) ($_POST['date_retard'] ?? ''));
        $heureArrivee = trim((string) ($_POST['heure_arrivee'] ?? ''));
        $dureeRetard = trim((string) ($_POST['duree_retard'] ?? ''));
        $motif = trim((string) ($_POST['motif'] ?? ''));

        if ($dateRetard === '' || !isValidDateYmd($dateRetard)) {
            $errors[] = 'Veuillez renseigner une date de retard valide.';
        }
        if ($heureArrivee === '' || !preg_match('/^\d{2}:\d{2}$/', $heureArrivee)) {
            $errors[] = 'Veuillez renseigner une heure d\'arrivee valide.';
        }
        if ($motif === '') {
            $errors[] = 'Veuillez renseigner le motif du retard.';
        }

        $payload = [
            'nom' => $nom,
            'matricule' => $matricule,
            'fonction' => $fonction,
            'service' => $service,
            'date_retard' => $dateRetard,
            'heure_arrivee' => $heureArrivee,
            'duree_retard' => $dureeRetard,
            'motif' => $motif,
        ];
        $title = 'Declaration de retard: ' . $dateRetard;
    } elseif ($type === 'pret') {
        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
        $matricule = (string) ($personnelProfile['matricule'] ?? '');

        $designation = trim((string) ($_POST['designation_pret'] ?? ''));
        $montantDemande = normalizeNumber((string) ($_POST['montant_demande'] ?? ''));
        $montantRecouvrement = normalizeNumber((string) ($_POST['montant_recouvrement'] ?? ''));

        if ($designation === '') {
            $errors[] = 'Veuillez renseigner la désignation du prêt.';
        }
        if ($montantDemande <= 0) {
            $errors[] = 'Veuillez renseigner un montant demandé valide.';
        }
        if ($montantRecouvrement <= 0) {
            $errors[] = 'Veuillez renseigner un montant de recouvrement valide.';
        }

        $mensualites = 0;
        if (!$errors) {
            $mensualites = (int) ceil($montantDemande / $montantRecouvrement);
            if ($mensualites < 1) {
                $mensualites = 1;
            }
        }

        // Date de début de recouvrement: le 5 du mois suivant
        $dateDebut = '';
        $dateFin = '';
        if (!$errors) {
            $now = new DateTimeImmutable('now');
            $year = (int) $now->format('Y');
            $month = (int) $now->format('n') + 1;
            if ($month > 12) {
                $month = 1;
                $year++;
            }

            $dateDebutObj = DateTimeImmutable::createFromFormat('Y-n-j', $year . '-' . $month . '-5');
            if (!$dateDebutObj) {
                $errors[] = 'Impossible de calculer la date de début de recouvrement.';
            } else {
                $dateDebut = $dateDebutObj->format('Y-m-d');
                $dateFinObj = $dateDebutObj->modify('+' . $mensualites . ' month');
                $dateFin = $dateFinObj ? $dateFinObj->format('Y-m-d') : '';
                if ($dateFin === '') {
                    $errors[] = 'Impossible de calculer la date de fin de recouvrement.';
                }
            }
        }

        $payload = [
            'matricule' => (string) $matricule,
            'designation_pret' => $designation,
            'nom' => $nom,
            'montant_demande' => $montantDemande,
            'montant_recouvrement' => $montantRecouvrement,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'nombre_mensualites' => $mensualites,
        ];
        $title = 'Prêt: ' . $designation;
    } elseif ($type === 'avance_salaire') {
        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));

        $motif = trim((string) ($_POST['motif'] ?? ''));
        $montant = normalizeNumber((string) ($_POST['montant'] ?? ''));

        if ($motif === '') {
            $errors[] = 'Veuillez renseigner le motif.';
        }
        if ($montant <= 0) {
            $errors[] = 'Veuillez renseigner un montant valide.';
        }

        $payload = [
            'nom' => $nom,
            'matricule' => $matricule,
            'fonction' => $fonction,
            'service' => $service,
            'motif' => $motif,
            'montant' => $montant,
        ];
        $title = 'Avance sur salaire';
    } elseif ($type === 'heures_supp') {
        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));

        $motif = trim((string) ($_POST['motif'] ?? ''));
        $heures = (int) ($_POST['heures'] ?? 0);
        $dateEffective = trim((string) ($_POST['date_effective'] ?? ''));

        if ($motif === '') {
            $errors[] = 'Veuillez renseigner le motif.';
        }
        if ($heures < 1 || $heures > 1000) {
            $errors[] = 'Veuillez renseigner un nombre d\'heures valide.';
        }
        if ($dateEffective !== '' && !isValidDateYmd($dateEffective)) {
            $errors[] = 'Veuillez renseigner une date de prise effective valide.';
        }

        $payload = [
            'nom' => $nom,
            'matricule' => $matricule,
            'fonction' => $fonction,
            'service' => $service,
            'motif' => $motif,
            'heures' => $heures,
            'date_effective' => $dateEffective,
        ];
        $title = 'Heures supplémentaires' . ($dateEffective !== '' ? ' — Prise effective: ' . $dateEffective : '');
    } elseif ($type === 'recrutement') {
        $poste=trim((string)($_POST['poste_recherche']??''));$serviceDemandeur=trim((string)($_POST['service_demandeur']??($personnelProfile['service_label']??'')));
        $nombre=max(0,(int)($_POST['nombre_postes']??0));$contrat=trim((string)($_POST['type_contrat']??''));$dateSouhaitee=trim((string)($_POST['date_souhaitee']??''));
        $motif=trim((string)($_POST['motif_recrutement']??''));$profil=trim((string)($_POST['profil_recherche']??''));
        $impactEntrant=normalizeNumber((string)($_POST['impact_financier_entrant']??''));$impactSortant=normalizeNumber((string)($_POST['impact_financier_sortant']??''));
        $valeurAjoutee=trim((string)($_POST['valeur_ajoutee']??''));
        if($poste==='')$errors[]='Veuillez préciser le poste recherché.';
        if($serviceDemandeur==='')$errors[]='Veuillez préciser le service demandeur.';
        if($nombre<1||$nombre>100)$errors[]='Le nombre de postes doit être compris entre 1 et 100.';
        if(!in_array($contrat,['CDI','CDD','Stage','Temporaire','Consultant'],true))$errors[]='Veuillez sélectionner un type de contrat.';
        if($dateSouhaitee!==''&&!isValidDateYmd($dateSouhaitee))$errors[]='La date souhaitée est invalide.';
        if($motif==='')$errors[]='Veuillez expliquer le besoin de recrutement.';
        if($profil==='')$errors[]='Veuillez décrire le profil et les compétences recherchés.';
        if($impactSortant<=0)$errors[]='Veuillez préciser le coût financier estimé du recrutement.';
        if($valeurAjoutee==='')$errors[]='Veuillez expliquer la valeur ajoutée attendue.';
        $payload=['poste_recherche'=>$poste,'service_demandeur'=>$serviceDemandeur,'nombre_postes'=>$nombre,'type_contrat'=>$contrat,'date_souhaitee'=>$dateSouhaitee,'motif_recrutement'=>$motif,'profil_recherche'=>$profil,'impact_financier_entrant'=>$impactEntrant,'impact_financier_sortant'=>$impactSortant,'impact_financier_net'=>$impactEntrant-$impactSortant,'valeur_ajoutee'=>$valeurAjoutee,'demandeur'=>(string)($personnelProfile['full_name']??employeeCurrentUserName()),'fonction_demandeur'=>(string)($personnelProfile['fonction_label']??'')];
        $title='Recrutement : '.$nombre.' '.$poste;
    } elseif ($type === 'reclamation_salariale') {
        $identity = splitEmployeeIdentity((array) $personnelProfile);
        $nom = (string) ($identity['nom'] ?? '');
        $prenoms = (string) ($identity['prenoms'] ?? '');
        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));

        $dateDemande = trim((string) ($_POST['date_demande'] ?? date('Y-m-d')));
        $objetReclamation = strtolower(trim((string) ($_POST['objet_reclamation'] ?? '')));
        $moisConcerne = trim((string) ($_POST['mois_concerne'] ?? ''));
        $montantManquant = normalizeNumber((string) ($_POST['montant_manquant'] ?? ''));
        $detailsDifferend = trim((string) ($_POST['details_differend'] ?? ''));
        $claimObjects = \EmployeeLegalRequestManager::getSalaryClaimObjectOptions();

        if ($dateDemande === '' || !isValidDateYmd($dateDemande)) {
            $errors[] = 'Veuillez renseigner une date de demande valide.';
        }
        if (!isset($claimObjects[$objetReclamation])) {
            $errors[] = 'Veuillez sélectionner l\'objet de la réclamation.';
        }
        if (!preg_match('/^\d{4}-\d{2}$/', $moisConcerne)) {
            $errors[] = 'Veuillez renseigner le mois concerné.';
        }
        if ($montantManquant <= 0) {
            $errors[] = 'Veuillez renseigner un montant manquant valide.';
        }
        if ($detailsDifferend === '') {
            $errors[] = 'Veuillez détailler le différend salarial.';
        }

        $payload = [
            'date_demande' => $dateDemande,
            'nom' => $nom,
            'prenoms' => $prenoms,
            'matricule' => $matricule,
            'service' => $service,
            'fonction' => $fonction,
            'objet_reclamation' => $objetReclamation,
            'objet_reclamation_label' => (string) ($claimObjects[$objetReclamation] ?? ''),
            'mois_concerne' => $moisConcerne,
            'montant_manquant' => $montantManquant,
            'details_differend' => $detailsDifferend,
        ];
        $title = 'Réclamation salariale: ' . (string) ($claimObjects[$objetReclamation] ?? 'Demande') . ' / ' . $moisConcerne;
    } elseif ($type === 'feb') {
        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
        $serviceEmploye = strtoupper((string) ($personnelProfile['service_label'] ?? ''));

        $departementId = (int) ($_POST['departement_id'] ?? 0);
        $departementLabel = '';
        if ($departementId <= 0 || !isset($allServices[$departementId])) {
            $errors[] = 'Veuillez sélectionner le département/service bénéficiaire.';
        } else {
            $departementLabel = (string) $allServices[$departementId];
        }

        $description = trim((string) ($_POST['description'] ?? ''));
        $montant = normalizeNumber((string) ($_POST['montant'] ?? ''));
        $dateLivraison = (string) ($_POST['date_livraison'] ?? ($_POST['date'] ?? ''));
        $impact = trim((string) ($existingPayload['impact'] ?? '')); // Preserve historical text without requesting it again.
        $frequence = trim((string) ($_POST['frequence'] ?? ''));
        $quoteTitle = trim((string) ($_POST['feb_quote_title'] ?? ''));
        $globalSupplierId = (int) ($_POST['feb_global_supplier_id'] ?? 0);
        $globalSupplier = $globalSupplierId > 0 ? ($approvedFebSupplierMap[$globalSupplierId] ?? null) : null;
        if (!is_array($globalSupplier)) {
            $errors[] = 'Sélectionnez le fournisseur global de la FEB.';
        } elseif (!empty($globalSupplier['disbursement_selection_blocked'])) {
            $errors[] = 'Ce fournisseur est temporairement indisponible : '.(string)($globalSupplier['disbursement_block_reason'] ?? 'justificatifs à fournir').'.';
        }
        $globalSupplierName = is_array($globalSupplier) ? trim((string)($globalSupplier['supplier_display_name'] ?? $globalSupplier['nom_commercial'] ?? $globalSupplier['raison_sociale'] ?? '')) : '';
        $globalProof = is_array($existingPayload['global_justificatif'] ?? null) ? (array)$existingPayload['global_justificatif'] : [];
        if (!$globalProof && is_array(($existingPayload['besoins'] ?? [])[0]['justificatif_devis'] ?? null)) {
            $globalProof = (array)$existingPayload['besoins'][0]['justificatif_devis'];
        }
        if (!empty($_FILES['feb_global_justificatif']) && (int)($_FILES['feb_global_justificatif']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            try {
                $globalProof = febStoreUploadedDocument((array)$_FILES['feb_global_justificatif'], __DIR__ . '/../uploads/feb_line_quotes', 'uploads/feb_line_quotes', ['pdf','jpg','jpeg','png','webp'], 10 * 1024 * 1024, 'Justificatif global de la FEB invalide', 'Justificatif global de la FEB');
            } catch (Throwable $proofError) {
                $errors[] = $proofError->getMessage();
            }
        }
        if (!$globalProof) {
            $errors[] = 'Joignez le devis ou justificatif global de la FEB.';
        }
        $submissionVerificationMode = $febVerificationPolicy->effectiveMode();
        if (!$isUrgentFebRegularization && $submissionVerificationMode === 'human') {
            $humanReviewToken = trim((string)($_POST['feb_human_review_token'] ?? ''));
            try {
                $humanReviewStatus = (new \FebHumanDocumentReviewService($pdo))->status($humanReviewToken, $userId);
                if ((string)($humanReviewStatus['status'] ?? '') !== 'approved') $errors[] = 'Le justificatif doit être validé par un contrôleur humain avant la soumission.';
            } catch (Throwable $e) {
                $errors[] = 'Validation humaine du justificatif introuvable ou expirée.';
            }
        }
        // Une FEB possède une seule pièce justificative. Les anciennes FEB peuvent
        // encore contenir `fidest_ia_document`, mais toute nouvelle soumission fait
        // contrôler la pièce globale sans la recopier physiquement.
        $legacyComplianceDocument = is_array($existingPayload['fidest_ia_document'] ?? null)
            ? (array) $existingPayload['fidest_ia_document']
            : [];
        $complianceDocument = $globalProof ?: $legacyComplianceDocument;
        // L'ancien parcours interne/externe et documentaire n'est plus utilisé.
        $febNeedScope = '';
        $pvReceptionNumber = '';
        $bciNumber = '';
        $supportingDocumentNumber = '';
        $invoiceNumber = '';
        $invoiceSupplierId = 0;
        $invoiceSupplierRow = $invoiceSupplierId > 0 ? ($approvedFebSupplierMap[$invoiceSupplierId] ?? null) : null;
        $invoiceSupplierName = '';
        if (is_array($invoiceSupplierRow)) {
            $invoiceSupplierName = trim((string) ($invoiceSupplierRow['supplier_display_name'] ?? ''));
            if ($invoiceSupplierName === '') {
                $invoiceSupplierName = trim((string) ($invoiceSupplierRow['nom_commercial'] ?? ''));
            }
            if ($invoiceSupplierName === '') {
                $invoiceSupplierName = trim((string) ($invoiceSupplierRow['raison_sociale'] ?? ''));
            }
        }
        $cashflowNeed = '';
        $cashflowComment = '';
        $provisionalAmount = 0.0;
        $submittedBudgetPlanned = trim((string) ($_POST['budget_planned'] ?? ''));
        // Toute FEB doit désormais être rattachée à une ligne de déboursé existante.
        // Le parcours qui créait un déboursé « proposé » est volontairement fermé.
        $budgetPlanned = 'yes';
        $prepareDecaissement = true;
        if ($submittedBudgetPlanned !== '' && $submittedBudgetPlanned !== 'yes') {
            $errors[] = 'Les FEB non prévues au déboursé ne peuvent plus être créées. Sélectionnez une ligne de déboursé existante.';
        }
        $draftPersonnelId = (int) ($personnelProfile['personnel_id'] ?? 0);
        $draftChantierId = (int) ($_POST['draft_fd_chantier_id'] ?? 0);
        $draftLigneDevisId = (int) ($_POST['draft_fd_ligne_devis_id'] ?? 0);
        $draftDebourseId = (int) ($_POST['draft_fd_debourse_id'] ?? 0);
        $draftLigneId = (int) ($_POST['draft_fd_ligne_debourse_id'] ?? 0);
        $draftFournisseurId = (int) ($_POST['draft_fd_fournisseur_id'] ?? 0);
        $draftBeneficiaryChoice = strtolower(trim((string) ($_POST['draft_fd_beneficiary_choice'] ?? 'requester')));
        if (!in_array($draftBeneficiaryChoice, ['requester', 'supplier'], true)) {
            $draftBeneficiaryChoice = 'requester';
        }
        $draftBeneficiaryName = $draftBeneficiaryChoice === 'supplier' ? $globalSupplierName : $nom;
        // Un bénéficiaire personnel et un fournisseur sont deux destinations exclusives.
        // Ne jamais conserver un ancien fournisseur lorsque le demandeur se choisit lui-même.
        if ($draftBeneficiaryChoice === 'requester') {
            $draftFournisseurId = 0;
        } elseif ($draftBeneficiaryChoice === 'supplier') {
            // Le fournisseur bénéficiaire est le fournisseur global choisi une seule fois.
            $draftFournisseurId = $globalSupplierId;
        }
        if ($draftBeneficiaryChoice === 'supplier' && $draftFournisseurId <= 0) {
            $errors[] = 'Sélectionnez le fournisseur qui sera bénéficiaire des fiches liées à cette FEB.';
        } elseif ($draftBeneficiaryChoice === 'supplier') {
                            $beneficiarySupplier = $approvedFebSupplierMap[$draftFournisseurId] ?? null;
            if (!is_array($beneficiarySupplier)) {
                $errors[] = 'Le fournisseur bénéficiaire choisi est introuvable ou non validé.';
            } elseif (!empty($beneficiarySupplier['disbursement_selection_blocked'])) {
                $errors[] = 'Ce fournisseur est temporairement indisponible : '.(string)($beneficiarySupplier['disbursement_block_reason'] ?? 'justificatifs à fournir').' — fiche(s) '.(string)($beneficiarySupplier['disbursement_block_fiches'] ?? 'concernée(s)').'.';
            } else {
                $supplierUpdates=[];$supplierParams=['id'=>$draftFournisseurId];
                foreach(['nom_commercial'=>'draft_supplier_completion_name','telephone'=>'draft_supplier_completion_phone','email'=>'draft_supplier_completion_email','adresse'=>'draft_supplier_completion_address'] as $column=>$field){if(trim((string)($beneficiarySupplier[$column]??''))===''&&trim((string)($_POST[$field]??''))!==''){$supplierUpdates[]="$column=:$column";$supplierParams[$column]=trim((string)$_POST[$field]);}}
                if(trim((string)($beneficiarySupplier['photo_path']??''))===''&&trim((string)($beneficiarySupplier['logo_path']??''))===''&&!empty($_FILES['draft_supplier_completion_photo'])&&(int)($_FILES['draft_supplier_completion_photo']['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_OK){$ext=strtolower(pathinfo((string)$_FILES['draft_supplier_completion_photo']['name'],PATHINFO_EXTENSION));if(in_array($ext,['jpg','jpeg','png','webp'],true)){$dir=dirname(__DIR__).'/uploads/fournisseurs';if((is_dir($dir)||mkdir($dir,0775,true))){$fileName='supplier_'.$draftFournisseurId.'_'.bin2hex(random_bytes(6)).'.'.$ext;if(move_uploaded_file((string)$_FILES['draft_supplier_completion_photo']['tmp_name'],$dir.'/'.$fileName)){$supplierUpdates[]='photo_path=:photo_path';$supplierParams['photo_path']='uploads/fournisseurs/'.$fileName;}}}else{$errors[]='La photo fournisseur doit être une image JPG, PNG ou WebP.';}}
                if($supplierUpdates){$pdo->prepare('UPDATE fournisseur_banamur SET '.implode(',',$supplierUpdates).' WHERE id_fournisseur=:id')->execute($supplierParams);$refresh=$pdo->prepare('SELECT * FROM fournisseur_banamur WHERE id_fournisseur=:id');$refresh->execute(['id'=>$draftFournisseurId]);$beneficiarySupplier=$refresh->fetch(PDO::FETCH_ASSOC)?:$beneficiarySupplier;}
                $missingSupplierData = [];
                if (trim((string)($beneficiarySupplier['nom_commercial'] ?? '')) === '' && trim((string)($beneficiarySupplier['raison_sociale'] ?? '')) === '') $missingSupplierData[] = 'nom ou raison sociale';
                if (trim((string)($beneficiarySupplier['telephone'] ?? '')) === '') $missingSupplierData[] = 'téléphone';
                if (trim((string)($beneficiarySupplier['email'] ?? '')) === '') $missingSupplierData[] = 'e-mail';
                if (trim((string)($beneficiarySupplier['adresse'] ?? '')) === '') $missingSupplierData[] = 'adresse';
                if (trim((string)($beneficiarySupplier['photo_path'] ?? '')) === '' && trim((string)($beneficiarySupplier['logo_path'] ?? '')) === '') $missingSupplierData[] = 'photo ou logo';
                if ($missingSupplierData) $errors[] = 'Complétez la fiche du fournisseur bénéficiaire avant de soumettre la FEB : ' . implode(', ', $missingSupplierData) . '.';
            }
        }
        $draftMontantFiche = normalizeNumber((string) ($_POST['draft_fd_montant_fiche'] ?? ''));
        $draftModePaiement = trim((string) ($_POST['draft_fd_mode_paiement'] ?? ''));
        $febPaymentModes = ['Cash', 'Espèces', 'Orange Money', 'MTN Money', 'Moov Money', 'Wave', 'Djamo', 'Chèque', 'Virement bancaire', 'Virement', 'Carte bancaire', 'Mobile Money'];
        if ($draftModePaiement !== '' && !in_array($draftModePaiement, $febPaymentModes, true)) $errors[] = 'Le mode de paiement choisi est invalide.';
        $draftDetailsDemande = trim((string) ($_POST['draft_fd_details_demande'] ?? ''));
        if ($draftDetailsDemande === '') $draftDetailsDemande = $quoteTitle;
        $plannedStartDate = trim((string) ($_POST['planned_start_date'] ?? ''));
        $plannedEndDate = trim((string) ($_POST['planned_end_date'] ?? ''));
        $febPositionDate = '';
        if ($budgetPlanned === 'yes' && $draftLigneId > 0) {
            $scheduleStmt = $pdo->prepare('SELECT date_debut,date_fin FROM ligne_debourse_banamur WHERE id=:id AND debourse_id=:debourse_id LIMIT 1');
            $scheduleStmt->execute(['id' => $draftLigneId, 'debourse_id' => $draftDebourseId]);
            $lineSchedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $plannedStartDate = trim((string)($lineSchedule['date_debut'] ?? ''));
            $plannedEndDate = trim((string)($lineSchedule['date_fin'] ?? ''));
            if (isValidDateYmd($plannedStartDate)) {
                $dateLivraison = $plannedStartDate;
                $febPositionDate = (new DateTimeImmutable($plannedStartDate))->modify('-7 days')->format('Y-m-d');
            }
        } elseif (isValidDateYmd($plannedStartDate)) {
            $dateLivraison = $plannedStartDate;
            $febPositionDate = (new DateTimeImmutable($plannedStartDate))->modify('-7 days')->format('Y-m-d');
        }
        $proposedLigneDevisId = (int) ($_POST['proposed_ligne_devis_id'] ?? 0);
        $proposedLigneDevisLabel = trim((string) ($_POST['proposed_ligne_devis_label'] ?? ''));
        $proposedDebourseTitle = trim((string) ($_POST['proposed_debourse_title'] ?? ''));
        $proposedLineDesignations = (array) ($_POST['proposed_line_designation'] ?? []);
        $proposedLineCategories = (array) ($_POST['proposed_line_category'] ?? []);
        $proposedLineAmounts = (array) ($_POST['proposed_line_amount'] ?? []);
        $proposedDebourseLines = [];

        if ($urgentRegularizationId > 0) {
            if (!is_array($urgentRegularization)
                || (int)($urgentRegularization['requester_user_id'] ?? 0) !== $userId
                || !in_array((string)($urgentRegularization['status'] ?? ''), ['decaissed', 'regularization_pending'], true)
                || (int)($urgentRegularization['regularized_feb_request_id'] ?? 0) > 0) {
                $errors[] = 'Cette régularisation urgente est introuvable, déjà clôturée ou ne vous appartient pas.';
            } else {
                $urgentChantierId = (int)($urgentRegularization['chantier_id'] ?? 0);
                if ($urgentChantierId <= 0) {
                    $errors[] = 'Le chantier du décaissement urgent est introuvable.';
                } elseif ($draftChantierId <= 0) {
                    $draftChantierId = $urgentChantierId;
                } elseif ($draftChantierId !== $urgentChantierId) {
                    $errors[] = 'La FEB de régularisation doit être rattachée au chantier du décaissement urgent.';
                }
            }
        }
        if ($draftChantierId <= 0) {
            $errors[] = 'Veuillez sélectionner le chantier concerné.';
        } elseif (!$isUrgentFebRegularization || $draftChantierId !== (int)($urgentRegularization['chantier_id'] ?? 0)) {
            $activeChantierCheck = $pdo->prepare('SELECT 1 FROM chantier_banamur WHERE id=:id AND archived_at IS NULL AND '.ChantierPauseService::activePredicate($pdo,'id').' LIMIT 1');
            $activeChantierCheck->execute(['id' => $draftChantierId]);
            if (!$activeChantierCheck->fetchColumn()) {
                $errors[] = 'Ce chantier est terminé ou archivé et ne peut plus recevoir de FEB.';
            }
        }

        if ($dateLivraison === '' || !isValidDateYmd($dateLivraison)) {
            $errors[] = 'Veuillez renseigner une date de livraison valide.';
        }
        $allowedFreq = ['unique', 'hebdomadaire', 'mensuelle', 'trimestrielle', 'semestrielle', 'annuelle'];
        if (!in_array($frequence, $allowedFreq, true)) {
            $errors[] = 'Veuillez sélectionner une fréquence.';
        }

        $types = $_POST['besoin_type'] ?? [];
        $categories = $_POST['besoin_categorie'] ?? [];
        $libelles = $_POST['besoin_libelle'] ?? [];
        $quantites = $_POST['besoin_quantite'] ?? [];
        $fPrix = $_POST['besoin_fournisseur_prix'] ?? [];

        $besoins = [];
        $max = max(count($types), count($libelles), count($quantites));
        for ($i = 0; $i < $max; $i++) {
            $t = trim((string) ($types[$i] ?? ''));
            // Compatibilité avec les formulaires restés ouverts avant la migration :
            // les anciennes valeurs sont converties pendant la soumission, sans rechargement.
            $category = \FebCategoryMigrationService::normalize((string) ($categories[$i] ?? ''));
            $lib = trim((string) ($libelles[$i] ?? ''));
            $q = normalizeNumber((string) ($quantites[$i] ?? ''));

            // Autoriser des lignes vides (ex: suppression côté client)
            if ($t === '' && $lib === '' && $q <= 0) {
                continue;
            }

            if (!in_array($t, ['Bien', 'Service'], true)) {
                $errors[] = 'Type de besoin invalide (ligne ' . ($i + 1) . ').';
                continue;
            }
            if ($lib === '') {
                $errors[] = 'Libellé du besoin manquant (ligne ' . ($i + 1) . ').';
                continue;
            }
            if ($q <= 0 || floor($q) !== $q) {
                $errors[] = 'Quantité invalide (ligne ' . ($i + 1) . ').';
                continue;
            }

            $besoin = [
                'type' => $t,
                'categorie' => $category,
                'libelle' => $lib,
                'quantite' => $q,
                'has_fournisseur' => $globalSupplierId > 0,
            ];

            if (is_array($globalSupplier)) {
                $supplierPrice = normalizeNumber((string) ($fPrix[$i] ?? ''));
                if ($supplierPrice <= 0) {
                    $errors[] = 'Veuillez renseigner un prix unitaire valide (ligne ' . ($i + 1) . ').';
                    continue;
                }

                $besoin['fournisseur'] = [
                    'id' => $globalSupplierId,
                    'code' => trim((string) ($globalSupplier['code_fournisseur'] ?? '')),
                    'nom' => $globalSupplierName,
                    'prix_unitaire' => $supplierPrice,
                    'telephone' => trim((string) ($globalSupplier['telephone'] ?? '')),
                    'contact' => trim((string) ($globalSupplier['contact_nom'] ?? '')),
                    'email' => trim((string) ($globalSupplier['email'] ?? '')),
                ];
                if ($globalProof) $besoin['justificatif_devis'] = $globalProof;
            }

            $besoins[] = $besoin;
        }

        if (!$besoins) {
            $errors[] = 'Veuillez ajouter au moins un besoin.';
        }
        $description = FebSubmissionContent::description($quoteTitle, $besoins, (string)($_POST['description'] ?? ''), (string)($existingPayload['description'] ?? ''));
        try { $quoteTotal = FebSubmissionContent::total($besoins); }
        catch (DomainException $e) { $errors[] = $e->getMessage(); $quoteTotal = 0.0; }
        $montant = $quoteTotal;
        $draftMontantFiche = $quoteTotal;
        if ($montant <= 0) $errors[] = 'Le total des lignes du devis doit être supérieur à zéro.';
        if ($quoteTitle === '') {
            $errors[] = 'Veuillez renseigner le titre du devis joint à la FEB.';
        }

        $fidestIaCompliance = is_array($existingPayload['fidest_ia_compliance'] ?? null) ? (array)$existingPayload['fidest_ia_compliance'] : [];
        if ($isUrgentFebRegularization && $complianceDocument) {
            $fidestIaCompliance = ['configured'=>false,'accepted'=>true,'mode'=>'urgent_regularization','summary'=>'Justificatif de régularisation joint ; contrôle documentaire comparatif non applicable.','checked_at'=>date('c')];
        } elseif ($submissionVerificationMode === 'ai' && $febOcrEnabled && $complianceDocument && $montant > 0 && $globalSupplierName !== '') {
            $relativeProofPath = ltrim((string)($complianceDocument['url'] ?? ''), '/');
            $absoluteProofPath = dirname(__DIR__) . '/' . $relativeProofPath;
            try {
                $fidestIaService = new \FebDocumentComplianceService();
                if ($fidestIaService->isConfigured()) {
                    $proofHash = (string)($complianceDocument['sha256'] ?? (is_file($absoluteProofPath) ? hash_file('sha256', $absoluteProofPath) : ''));
                    $clientReference = $editRequestId > 0 ? 'FINEA-FEB-' . $editRequestId : 'FINEA-FEB-DRAFT-' . $userId . '-' . substr($proofHash, 0, 12);
                    $fidestIaCompliance = $fidestIaService->check($absoluteProofPath, $clientReference, $globalSupplierName, $montant, $besoins);
                    if (empty($fidestIaCompliance['accepted'])) {
                        foreach ((array)($fidestIaCompliance['reasons'] ?? []) as $reason) $errors[] = 'Contrôle FIDEST IA : ' . (string)$reason;
                    }
                } else {
                    $fidestIaCompliance = ['configured'=>false,'accepted'=>null,'summary'=>'Contrôle FIDEST IA en attente de configuration.','checked_at'=>date('c')];
                }
            } catch (\FidestIaException $e) {
                $requestSuffix = $e->requestId ? ' (requête ' . $e->requestId . ')' : '';
                $errors[] = 'Contrôle FIDEST IA indisponible : ' . $e->getMessage() . $requestSuffix;
                $fidestIaCompliance = ['configured'=>true,'accepted'=>false,'technical_error'=>$e->errorCode,'request_id'=>$e->requestId,'summary'=>$e->getMessage(),'checked_at'=>date('c')];
            } catch (Throwable $e) {
                $errors[] = 'Le contrôle FIDEST IA n’a pas pu être effectué. Réessayez avant de soumettre la FEB.';
            }
        } elseif ($complianceDocument) {
            $fidestIaCompliance = ['configured'=>false,'accepted'=>true,'mode'=>$submissionVerificationMode,'summary'=>$submissionVerificationMode==='human'?'Justificatif validé par un humain.':'Justificatif accepté automatiquement.','checked_at'=>date('c')];
        }

        if ($plannedStartDate === '' || !isValidDateYmd($plannedStartDate)) {
            $errors[] = $budgetPlanned === 'yes' ? 'Le planning de la ligne de déboursé est incomplet. Demandez au département technique de renseigner sa date de début ; le formulaire la détectera automatiquement sans perdre votre saisie.' : 'Veuillez renseigner la date de début prévue de la FEB.';
        }
        if ($plannedEndDate === '' || !isValidDateYmd($plannedEndDate)) {
            $errors[] = $budgetPlanned === 'yes' ? 'Le planning de la ligne de déboursé est incomplet. Demandez au département technique de renseigner sa date de fin ; le formulaire la détectera automatiquement sans perdre votre saisie.' : 'Veuillez renseigner la date de fin prévue de la FEB.';
        } elseif ($plannedStartDate !== '' && $plannedEndDate < $plannedStartDate) {
            $errors[] = 'La date de fin prévue doit être postérieure ou égale à la date de début.';
        }

        if ($prepareDecaissement) {
            if ($draftBeneficiaryChoice === 'requester' && $draftPersonnelId <= 0) {
                $errors[] = 'Impossible de préparer la fiche: profil personnel introuvable.';
            }
            foreach ([
                ['value' => $draftChantierId, 'label' => 'chantier de la fiche'],
                ['value' => $draftLigneDevisId, 'label' => 'ligne de devis de la fiche'],
                ['value' => $draftDebourseId, 'label' => 'déboursé de la fiche'],
                ['value' => $draftLigneId, 'label' => 'ligne de déboursé de la fiche'],
            ] as $requiredDraftField) {
                $value = (int) ($requiredDraftField['value'] ?? 0);
                $label = (string) ($requiredDraftField['label'] ?? 'champ de la fiche');
                if ((int) $value <= 0) {
                    $errors[] = 'Veuillez renseigner le ' . $label . '.';
                }
            }
            if ($draftMontantFiche <= 0) {
                $errors[] = 'Veuillez renseigner le montant de la fiche de décaissement.';
            }
            if ($draftModePaiement === '') {
                $errors[] = 'Veuillez renseigner le mode de paiement de la fiche de décaissement.';
            }
            if ($draftDetailsDemande === '') {
                $errors[] = 'Veuillez renseigner les détails de la fiche de décaissement.';
            }
            if (!$errors) {
                $relation = $pdo->prepare('SELECT COUNT(*) FROM debourse_banamur db INNER JOIN ligne_debourse_banamur ldb ON ldb.id=:ligne_debourse_id AND ldb.debourse_id=db.id INNER JOIN chantier_banamur c ON c.id=:chantier_id AND (c.archived_at IS NULL' . ($isUrgentFebRegularization ? ' OR c.id=' . (int)$urgentRegularization['chantier_id'] : '') . ') AND (c.devis_id=db.devis_id OR EXISTS(SELECT 1 FROM devis_chantier_links dcl WHERE dcl.devis_id=db.devis_id AND dcl.chantier_id=c.id)) WHERE db.id = :debourse_id AND db.ligne_devis_id = :ligne_devis_id AND ' . \FinancialPlanningSeparationService::officialDeboursePredicate('db'));
                $relation->execute(['debourse_id' => $draftDebourseId, 'ligne_devis_id' => $draftLigneDevisId, 'ligne_debourse_id'=>$draftLigneId, 'chantier_id' => $draftChantierId]);
                if ((int) $relation->fetchColumn() !== 1) {
                    $errors[] = 'Le déboursé sélectionné ne correspond pas à la ligne de devis choisie.';
                }
            }
        }

        $aiMap = [];
        if ($submissionVerificationMode === 'ai' && $febOcrEnabled) {
            $aiChecksJson = trim((string) ($_POST['ai_checks_json'] ?? ''));
            $aiChecks = null;
            if ($aiChecksJson !== '') {
                $decoded = json_decode($aiChecksJson, true);
                if (is_array($decoded)) {
                    $aiChecks = $decoded;
                }
            }

            if (is_array($aiChecks)) {
                foreach ($aiChecks as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $n = trim((string) ($item['name'] ?? ''));
                    $s = (int) ($item['size'] ?? 0);
                    $d = strtoupper(trim((string) ($item['decision'] ?? '')));
                    if ($n === '' || $s <= 0 || ($d !== 'ACCEPTE' && $d !== 'REFUSE')) {
                        continue;
                    }
                    $aiMap[$n . '__' . $s] = $item;
                }
            }
        }

        $existingPvDocument = is_array($existingPayload['pv_reception_document'] ?? null) ? (array) $existingPayload['pv_reception_document'] : [];
        $uploadedPvDocument = !empty($_POST['remove_existing_pv_reception_document']) ? [] : $existingPvDocument;

        if (false && $febNeedScope === 'internal' && !$errors) {
            $pvFiles = [];
            if (isset($_FILES['pv_reception_document']) && is_array($_FILES['pv_reception_document'])) {
                $pvFiles = febNormalizeUploadedFiles($_FILES['pv_reception_document']);
                $pvFiles = array_values(array_filter($pvFiles, static function (array $f): bool {
                    return (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                }));
            }

            if (count($pvFiles) > 1) {
                $errors[] = 'Veuillez joindre un seul PV de réception.';
            } elseif ($pvFiles) {
                $baseRel = 'uploads/employee_feb/' . date('Ymd');
                $token = bin2hex(random_bytes(8));
                $dirRel = $baseRel . '/' . $userId . '_' . $token;
                $dirAbs = dirname(__DIR__) . '/' . $dirRel;
                if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
                    $errors[] = 'Impossible de creer le dossier de stockage du PV de réception.';
                } else {
                    try {
                        $uploadedPvDocument = febStoreUploadedDocument(
                            $pvFiles[0],
                            $dirAbs,
                            $dirRel,
                            ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
                            20 * 1024 * 1024,
                            'Fichier PV de réception invalide',
                            'Impossible de sauvegarder le PV de réception'
                        );
                    } catch (Throwable $e) {
                        $errors[] = $e->getMessage();
                    }
                }
            }

            if (!$uploadedPvDocument) {
                $errors[] = 'Veuillez joindre le PV de réception.';
            }
        }

        $existingJustifs = array_values(array_filter((array) ($existingPayload['justificatifs'] ?? []), static fn($item): bool => is_array($item)));
        $removedJustifs = array_map('strval', (array) ($_POST['remove_existing_justificatifs'] ?? []));
        $keptJustifs = [];
        foreach ($existingJustifs as $existingIndex => $existingJustif) {
            if (!in_array((string) $existingIndex, $removedJustifs, true)) {
                $keptJustifs[] = $existingJustif;
            }
        }
        $existingInvoiceDocument = is_array($existingPayload['invoice_document'] ?? null) ? (array) $existingPayload['invoice_document'] : [];
        $uploadedInvoiceDocument = !empty($_POST['remove_existing_invoice_document']) ? [] : $existingInvoiceDocument;
        $uploadedJustifs = $keptJustifs;
        if (false && $febNeedScope === 'external' && $cashflowNeed === 'provisional_disbursement' && !$errors) {
            if (!isset($_FILES['justificatifs']) || !is_array($_FILES['justificatifs'])) {
                $errors[] = 'Veuillez joindre au moins une pièce justificative.';
            } else {
                $files = febNormalizeUploadedFiles($_FILES['justificatifs']);
                $files = array_values(array_filter($files, static function (array $f): bool {
                    return (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                }));

                if (!$files) {
                    $errors[] = 'Veuillez joindre au moins une pièce justificative.';
                } else {
                    if (count($files) > 10) {
                        $errors[] = 'Vous pouvez joindre au maximum 10 justificatifs.';
                    }

                    $baseRel = 'uploads/employee_feb/' . date('Ymd');
                    $token = bin2hex(random_bytes(8));
                    $dirRel = $baseRel . '/' . $userId . '_' . $token;
                    $dirAbs = dirname(__DIR__) . '/' . $dirRel;
                    if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
                        $errors[] = 'Impossible de créer le dossier de stockage des justificatifs.';
                    }

                    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff'];

                    foreach ($files as $f) {
                        if ($errors) {
                            break;
                        }
                        if ((int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                            $errors[] = 'Erreur upload justificatif: ' . (string) ($f['name'] ?? '');
                            break;
                        }

                        $orig = trim((string) ($f['name'] ?? ''));
                        $tmp = (string) ($f['tmp_name'] ?? '');
                        $size = (int) ($f['size'] ?? 0);
                        $mime = (string) ($f['type'] ?? '');

                        if ($orig === '' || $tmp === '' || !is_uploaded_file($tmp)) {
                            $errors[] = 'Fichier justificatif invalide.';
                            break;
                        }
                        if ($size <= 0 || $size > 12 * 1024 * 1024) {
                            $errors[] = 'Justificatif trop volumineux (max 12 Mo): ' . $orig;
                            break;
                        }

                        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
                            $errors[] = 'Format non supporté (images uniquement): ' . $orig;
                            break;
                        }

                        $key = $orig . '__' . $size;
                        if ($submissionVerificationMode === 'ai' && $febOcrEnabled) {
                            $decision = strtoupper(trim((string) ($aiMap[$key]['decision'] ?? '')));
                            if ($decision !== 'ACCEPTE') {
                                $errors[] = 'Justificatif refusé par le contrôle OCR: ' . $orig;
                                break;
                            }
                        }

                        $newName = bin2hex(random_bytes(12)) . '.' . $ext;
                        $targetAbs = $dirAbs . '/' . $newName;
                        if (!move_uploaded_file($tmp, $targetAbs)) {
                            $errors[] = 'Impossible de sauvegarder le justificatif: ' . $orig;
                            break;
                        }

                        $sha = '';
                        try {
                            $sha = hash_file('sha256', $targetAbs) ?: '';
                        } catch (Throwable $e) {
                            $sha = '';
                        }

                        $url = '/' . str_replace('\\', '/', $dirRel . '/' . $newName);
                        $uploadedJustifs[] = [
                            'name' => $orig,
                            'stored_name' => $newName,
                            'url' => $url,
                            'mime' => $mime,
                            'size' => $size,
                            'sha256' => $sha,
                            'ai' => [
                                'decision' => $submissionVerificationMode === 'ai' && $febOcrEnabled ? (string) ($aiMap[$key]['decision'] ?? '') : 'BYPASS',
                                'comment' => $submissionVerificationMode === 'ai' && $febOcrEnabled ? (string) ($aiMap[$key]['comment'] ?? '') : ($submissionVerificationMode === 'human' ? 'Contrôle humain validé en temps réel' : 'Acceptation automatique'),
                                'classification' => $submissionVerificationMode === 'ai' && $febOcrEnabled ? ($aiMap[$key]['classification'] ?? null) : ['class' => 'bypass', 'signals' => [$submissionVerificationMode === 'human' ? 'human_review' : 'auto_accept']],
                            ],
                        ];
                    }
                }
            }
        }

        if ($febNeedScope === 'external' && $cashflowNeed === 'provisional_disbursement') {
            if ($uploadedJustifs) {
                $errors = array_values(array_filter($errors, static function (string $message): bool {
                    return strpos($message, 'Veuillez joindre au moins une') === false;
                }));
            }
            if (count($uploadedJustifs) > 10) {
                $errors[] = 'Vous pouvez joindre au maximum 10 justificatifs.';
            }
        } else {
            $uploadedJustifs = [];
            $supportingDocumentNumber = '';
        }

        $invoiceFiles = [];
        if ($febNeedScope === 'external' && isset($_FILES['invoice_document']) && is_array($_FILES['invoice_document'])) {
            $invoiceFiles = febNormalizeUploadedFiles($_FILES['invoice_document']);
            $invoiceFiles = array_values(array_filter($invoiceFiles, static function (array $f): bool {
                return (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            }));
        }
        if (count($invoiceFiles) > 1) {
            $errors[] = 'Veuillez joindre une seule facture pour ce decaissement.';
        } elseif ($invoiceFiles) {
            $baseRel = 'uploads/employee_feb/' . date('Ymd');
            $token = bin2hex(random_bytes(8));
            $dirRel = $baseRel . '/' . $userId . '_' . $token;
            $dirAbs = dirname(__DIR__) . '/' . $dirRel;
            if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
                $errors[] = 'Impossible de creer le dossier de stockage de la facture.';
            } else {
                try {
                    $uploadedInvoiceDocument = febStoreUploadedDocument(
                        $invoiceFiles[0],
                        $dirAbs,
                        $dirRel,
                        ['pdf', 'jpg', 'jpeg', 'png', 'webp'],
                        20 * 1024 * 1024,
                        'Fichier facture invalide',
                        'Impossible de sauvegarder la facture'
                    );
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
        // Les documents FEB sont désormais facultatifs. Ils peuvent toujours être joints,
        // mais leur absence ne bloque plus la soumission de la demande.
        if ($febNeedScope !== 'external' || $cashflowNeed !== 'internal_purchase_order') {
            $uploadedInvoiceDocument = [];
        }

        $payload = [
            'nom' => $nom,
            'matricule' => $matricule,
            'fonction' => $fonction,
            'service_employe' => $serviceEmploye,
            'departement_id' => $departementId,
            'departement_label' => $departementLabel,
            'besoins' => $besoins,
            'feb_quote_title' => $quoteTitle,
            'global_fournisseur_id' => $globalSupplierId,
            'global_fournisseur_nom' => $globalSupplierName,
            'global_justificatif' => $globalProof,
            'fidest_ia_document' => $complianceDocument,
            'fidest_ia_compliance' => $fidestIaCompliance,
            'description' => $description,
            'montant' => $montant,
            'initial_requested_amount' => $montant,
            'date_livraison' => $dateLivraison,
            'impact' => $impact,
            'frequence' => $frequence,
            'chantier_id' => $draftChantierId,
            'personnel_id' => $draftPersonnelId,
            'budget_planned' => $budgetPlanned,
            'budget_planned_label' => $budgetPlanned === 'yes' ? 'Prévue au déboursé' : 'Non prévue au déboursé',
            'planned_start_date' => $plannedStartDate,
            'planned_end_date' => $plannedEndDate,
            'feb_position_date' => $febPositionDate,
            'disbursement_window_start' => $febPositionDate,
            'disbursement_window_end' => $plannedStartDate,
            'beneficiary_choice' => $draftBeneficiaryChoice,
            'fournisseur_id' => $draftFournisseurId,
            'proposed_debourse' => $budgetPlanned === 'no' ? [
                'status' => 'submitted_for_analysis',
                'ligne_devis_id' => $proposedLigneDevisId,
                'ligne_devis_proposal' => $proposedLigneDevisLabel,
                'title' => $proposedDebourseTitle,
                'lines' => $proposedDebourseLines,
                'total_amount' => array_sum(array_column($proposedDebourseLines, 'montant')),
            ] : [],
        ];
        if ($prepareDecaissement) {
            $payload['provisional_decaissement'] = [
                'active' => true,
                'beneficiary_mode' => $draftBeneficiaryChoice === 'supplier' ? 'supplier' : 'personnel',
                'beneficiary_choice' => $draftBeneficiaryChoice,
                'personnel_id' => $draftPersonnelId,
                'beneficiaire_fiche' => $draftBeneficiaryName,
                'beneficiary_name' => $draftBeneficiaryName,
                'chantier_id' => $draftChantierId,
                'ligne_devis_id' => $draftLigneDevisId,
                'debourse_id' => $draftDebourseId,
                'ligne_debourse_id' => $draftLigneId,
                'fournisseur_id' => $draftFournisseurId,
                'montant_fiche' => $draftMontantFiche,
                'mode_paiement' => $draftModePaiement,
                'details_demande' => $draftDetailsDemande,
                'date_decaissement_minimum' => $febPositionDate !== '' ? $febPositionDate : ($plannedStartDate !== '' ? $plannedStartDate : $dateLivraison),
                'prepared_by_user_id' => $userId,
                'prepared_at' => date('c'),
            ];
        }
        if ($urgentRegularizationId > 0 && is_array($urgentRegularization)) {
            $payload['urgent_decaissement_id'] = $urgentRegularizationId;
            $payload['urgent_decaissement_code'] = (string)($urgentRegularization['request_code'] ?? '');
            $payload['urgent_decaissement_amount'] = (float)($urgentRegularization['amount'] ?? 0);
            $payload['urgent_decaissement_chantier_id'] = (int)($urgentRegularization['chantier_id'] ?? 0);
            $payload['urgent_decaissement_chantier_label'] = (string)($urgentRegularization['chantier_label'] ?? '');
        }

        $title = 'FEB: ' . ($departementLabel !== '' ? $departementLabel : 'Demande') . ' (' . count($besoins) . ' besoin(s))';

        if ($febRejectUneditedGeneratedText) {
            foreach (['feb_description' => 'description'] as $aiField => $payloadKey) {
                $requestId = (int)($_POST[$aiField . '_request_id'] ?? 0);
                $generated = normalizeAiLearningText((string)($_POST[$aiField . '_generated'] ?? ''));
                $final = normalizeAiLearningText((string)($payload[$payloadKey] ?? ''));
                if ($requestId > 0 && $generated !== '' && hash_equals($generated, $final)) {
                    $errors[] = ($payloadKey === 'impact' ? 'L’impact' : 'La description') . ' a été généré intégralement par l’assistant. Modifiez et personnalisez ce texte avant de soumettre la FEB.';
                }
            }
        }
        if ($febAiDetectionEnabled && !empty($febAiSettings['block_on_detection'])) {
            try {
                $detector = new \FebAiDetectionService($pdo);
                foreach (array_filter(['feb_description' => (string)($_POST['description'] ?? '')]) as $field => $textToCheck) {
                    $detection = $detector->detect((string)$textToCheck, $field, $editRequestId > 0 ? $editRequestId : null, $userId);
                    if (!empty($detection['blocked'])) $errors[] = ($field === 'feb_impact' ? 'L’impact' : 'La description') . ' dépasse le seuil de texte généré par IA autorisé (' . (float)$detection['score'] . ' % IA). Personnalisez le texte avant de continuer.';
                }
            } catch (Throwable $e) {
                // Indisponibilité du fournisseur : ne pas perdre une FEB déjà saisie.
            }
        }
    }

    if (!$errors) {
        try {
            if ($isEditMode && $editRequestId > 0) {
                $ok = $manager->updateSubmittedRequest($editRequestId, $userId, $requester, $type, $payload, $title);
                if (!$ok) {
                    throw new RuntimeException('Modification refusée');
                }
                if ($type === 'feb') {
                    recordFebAiImplicitLearning($pdo, $userId, $_POST, $payload);
                    recordFebPassiveLearning($pdo, $userId, $payload);
                    febRegisterPayloadDocuments($pdo, $editRequestId, $payload, $userId);
                    (new \FebQuoteLineService($pdo))->sync($editRequestId, $payload);
                    (new \FebPriceChallengeService($pdo))->syncForFeb($editRequestId, $payload);
                }
                $recurringRedirect = '';
                if ($type === 'feb') {
                    try {
                        $recurringNeed = $recurringNeedService->syncFromFebRequest(
                            $editRequestId,
                            $payload,
                            $userId,
                            (string)($requester['name'] ?? ''),
                            (string)($requester['email'] ?? '')
                        );
                        if ($recurringNeed) {
                            $recurringRedirect = '&recurring_updated=' . urlencode((string)($recurringNeed['reference'] ?? $recurringNeed['id'] ?? '1'));
                        }
                    } catch (Throwable $recurringError) {
                        \AuditTrail::log('recurring_need_sync_failed', [
                            'module' => 'recurring_needs',
                            'action' => 'sync_from_feb_update',
                            'entity_type' => 'employee_request',
                            'entity_id' => (string)$editRequestId,
                            'result' => 'failed',
                            'severity' => 'warning',
                            'details' => ['error_message' => $recurringError->getMessage()],
                        ], $pdo);
                        $recurringRedirect = '&recurring_error=1';
                    }
                }
                \AuditTrail::log('employee_request_updated', [
                    'module' => 'employe',
                    'action' => 'nouvelle_demande_employe_update',
                    'entity_type' => 'employee_request',
                    'entity_id' => (string) $editRequestId,
                    'entity_label' => $title,
                    'result' => 'success',
                    'details' => [
                        'request_type' => $type,
                        'user_id' => $userId,
                    ],
                ], $pdo);
                header('Location: mes_demandes_employe.php?updated=' . urlencode((string) $editRequestId) . $recurringRedirect);
                exit();
            }

            // La FEB et son lien à l'urgence doivent être enregistrés ensemble.
            $urgentLinkTransaction = $type === 'feb' && $urgentRegularizationId > 0;
            if ($urgentLinkTransaction) {
                if ($pdo->inTransaction()) {
                    throw new RuntimeException('Transaction de régularisation déjà active.');
                }
                $pdo->beginTransaction();
            }
            try {
                $id = $manager->createRequest($userId, $requester, $type, $payload, $title);
                if ($urgentLinkTransaction) {
                    $urgentDisbursementService->registerFebForUrgency($urgentRegularizationId, (int)$id);
                    $pdo->commit();
                }
            } catch (Throwable $linkError) {
                if ($urgentLinkTransaction && $pdo->inTransaction()) $pdo->rollBack();
                throw $linkError;
            }
            if ($type === 'feb') {
                recordFebAiImplicitLearning($pdo, $userId, $_POST, $payload);
                recordFebPassiveLearning($pdo, $userId, $payload);
                febRegisterPayloadDocuments($pdo, (int) $id, $payload, $userId);
                (new \FebQuoteLineService($pdo))->sync((int) $id, $payload);
                (new \FebPriceChallengeService($pdo))->assignForFeb((int) $id, $payload);
            }
            $recurringRedirect = '';
            if ($type === 'feb') {
                try {
                    $recurringNeed = $recurringNeedService->syncFromFebRequest(
                        (int)$id,
                        $payload,
                        $userId,
                        (string)($requester['name'] ?? ''),
                        (string)($requester['email'] ?? '')
                    );
                    if ($recurringNeed) {
                        $recurringRedirect = '&recurring_created=' . urlencode((string)($recurringNeed['reference'] ?? $recurringNeed['id'] ?? '1'));
                    }
                } catch (Throwable $recurringError) {
                    \AuditTrail::log('recurring_need_sync_failed', [
                        'module' => 'recurring_needs',
                        'action' => 'sync_from_feb_creation',
                        'entity_type' => 'employee_request',
                        'entity_id' => (string)$id,
                        'result' => 'failed',
                        'severity' => 'warning',
                        'details' => ['error_message' => $recurringError->getMessage()],
                    ], $pdo);
                    $recurringRedirect = '&recurring_error=1';
                }
            }
            \AuditTrail::log('employee_request_created', [
                'module' => 'employe',
                'action' => 'nouvelle_demande_employe',
                'entity_type' => 'employee_request',
                'entity_id' => (string) $id,
                'entity_label' => $title,
                'result' => 'success',
                'details' => [
                    'request_type' => $type,
                    'user_id' => $userId,
                ],
            ], $pdo);
            foreach (array_unique($usedFebProofTokens) as $usedProofToken) {
                unset($_SESSION['feb_pending_line_proofs'][$usedProofToken]);
            }
            if ($type === 'feb') {
                header('Location: nouvelle_demande_employe.php?type=feb&' . (!empty($_POST['assistant_mode']) ? 'assistant_success' : 'flow_success') . '=' . urlencode((string) $id));
            } else {
                header('Location: mes_demandes_employe.php?created=' . urlencode((string) $id) . $recurringRedirect);
            }
            exit();
        } catch (Throwable $e) {
            \AuditTrail::log($isEditMode ? 'employee_request_update_failed' : 'employee_request_create_failed', [
                'module' => 'employe',
                'action' => $isEditMode ? 'nouvelle_demande_employe_update' : 'nouvelle_demande_employe',
                'entity_type' => 'employee_request',
                'entity_label' => $title !== '' ? $title : 'Nouvelle demande employe',
                'result' => 'failed',
                'severity' => 'warning',
                'details' => [
                    'request_type' => $type,
                    'user_id' => $userId,
                    'error_message' => $e->getMessage(),
                ],
            ], $pdo);
            $errors[] = $isEditMode
                ? 'Erreur lors de la modification de la demande. Merci de réessayer.'
                : 'Erreur lors de la création de la demande. Merci de réessayer.';
        }
    }

    if ($errors) {
        \AuditTrail::log($isEditMode ? 'employee_request_update_failed' : 'employee_request_create_failed', [
            'module' => 'employe',
            'action' => $isEditMode ? 'nouvelle_demande_employe_update' : 'nouvelle_demande_employe',
            'entity_type' => 'employee_request',
            'entity_label' => $title !== '' ? $title : 'Nouvelle demande employe',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'request_type' => $type,
                'user_id' => $userId,
                'validation_error' => (string) ($errors[0] ?? 'Validation invalide'),
            ],
        ], $pdo);
    }
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<style>
    .feb-select-search-wrap {
        position: relative;
        width: 100%;
    }

    .feb-select-search-native {
        display: none !important;
    }

    .feb-select-search-results {
        position: absolute;
        z-index: 40;
        top: calc(100% + 0.25rem);
        left: 0;
        right: 0;
        max-height: 16rem;
        overflow-y: auto;
        border: 1px solid #bbf7d0;
        border-radius: 1rem;
        background: #fff;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.14);
    }

    .feb-select-search-option {
        display: block;
        width: 100%;
        padding: 0.65rem 0.85rem;
        text-align: left;
        font-size: 0.875rem;
        color: #0f172a;
    }

    .feb-select-search-option:hover,
    .feb-select-search-option:focus {
        background: #ecfdf5;
        outline: none;
    }


/* =========================================================
   FEB UI refresh — présentation uniquement
   Tous les sélecteurs sont volontairement limités à #febFormPage
   afin de ne modifier ni les autres actes, ni la logique métier.
   ========================================================= */
#febFormPage {
    --feb-ink: #202124;
    --feb-muted: #5f6368;
    --feb-line: #e5e7eb;
    --feb-line-soft: #eef0f2;
    --feb-surface: #ffffff;
    --feb-surface-soft: #f8f9fa;
    --feb-blue: #1a73e8;
    --feb-blue-dark: #1557b0;
    --feb-blue-soft: #e8f0fe;
    --feb-green: #188038;
    --feb-green-soft: #e6f4ea;
    --feb-amber: #f9ab00;
    --feb-red: #d93025;
    color: var(--feb-ink);
    background:
        radial-gradient(circle at 50% -10%, rgba(26,115,232,.055), transparent 32rem),
        #f8f9fa !important;
}

#febFormPage .feb-page-shell {
    max-width: 1180px;
    margin: 0 auto;
    padding-top: 32px;
    padding-bottom: 72px;
}

#febFormPage .feb-form-surface {
    overflow: visible;
    border: 1px solid #e8eaed !important;
    border-radius: 28px !important;
    padding: 34px !important;
    background: rgba(255,255,255,.98) !important;
    box-shadow: 0 1px 2px rgba(60,64,67,.08), 0 8px 28px rgba(60,64,67,.06) !important;
}

#febFormPage .feb-form-surface > .pointer-events-none.absolute {
    display: none;
}

#febFormPage .feb-form-surface > .flex.items-start.justify-between {
    align-items: center;
    padding-bottom: 26px;
    border-bottom: 1px solid var(--feb-line-soft);
}

#febFormPage .feb-form-surface > .flex.items-start.justify-between > div:first-child > span {
    width: 46px !important;
    height: 46px !important;
    border-radius: 14px !important;
    background: var(--feb-blue-soft) !important;
    color: var(--feb-blue) !important;
}

#febFormPage .feb-form-surface h1 {
    font-size: clamp(1.55rem, 2vw, 2rem) !important;
    font-weight: 600 !important;
    letter-spacing: -.025em;
    color: var(--feb-ink) !important;
}

#febFormPage .feb-form-surface > .flex.items-start.justify-between p.uppercase {
    color: var(--feb-blue) !important;
    letter-spacing: .14em !important;
}

#febFormPage form#febMainForm {
    margin-top: 26px !important;
}

/* Pré-parcours FEB ordinaire / contrat */
#febFormPage #febRouteStep {
    border: 1px solid var(--feb-line) !important;
    border-radius: 24px !important;
    box-shadow: none !important;
    background: var(--feb-surface) !important;
}

#febFormPage #febRouteStep > .bg-black {
    background: var(--feb-surface) !important;
    color: var(--feb-ink) !important;
    padding: 22px 24px 16px !important;
    border-bottom: 1px solid var(--feb-line-soft);
}

#febFormPage #febRouteCounter {
    color: var(--feb-blue) !important;
    font-weight: 700 !important;
    letter-spacing: .13em !important;
}

#febFormPage #febRouteTitle {
    font-weight: 600 !important;
    letter-spacing: -.02em;
}

#febFormPage #febRouteType {
    padding: 20px 24px 24px !important;
}

#febFormPage #febRouteType button {
    border: 1px solid var(--feb-line) !important;
    border-radius: 18px !important;
    background: #fff !important;
    box-shadow: none !important;
}

#febFormPage #febRouteType button:hover {
    border-color: #c4c7c5 !important;
    box-shadow: 0 2px 8px rgba(60,64,67,.12) !important;
    transform: translateY(-1px);
}

#febFormPage #febRouteType button h3 {
    font-weight: 600 !important;
}

#febFormPage #febRouteType button span {
    background: var(--feb-blue-soft) !important;
    color: var(--feb-blue-dark) !important;
    border-radius: 999px !important;
    font-weight: 600 !important;
}

/* Assistant facultatif */
#febFormPage .feb-assistant-invite {
    border: 1px solid #d2e3fc !important;
    border-radius: 22px !important;
    background: linear-gradient(135deg,#f8fbff 0%,#eef4ff 100%) !important;
    color: var(--feb-ink) !important;
}

#febFormPage .feb-assistant-invite p,
#febFormPage .feb-assistant-invite h2 { color: inherit !important; }
#febFormPage .feb-assistant-invite p:first-child { color: var(--feb-blue) !important; }
#febFormPage .feb-assistant-invite button {
    background: var(--feb-blue) !important;
    color: #fff !important;
    border-radius: 999px !important;
    box-shadow: 0 1px 2px rgba(26,115,232,.25);
}
#febFormPage .feb-assistant-invite button:hover { background: var(--feb-blue-dark) !important; }

/* Navigation principale en 3 étapes */
#febFormPage .feb-step-navigation {
    position: sticky;
    top: 12px;
    z-index: 25;
    border: 1px solid #e8eaed !important;
    border-radius: 22px !important;
    background: rgba(255,255,255,.94) !important;
    backdrop-filter: blur(12px);
    box-shadow: 0 2px 10px rgba(60,64,67,.08);
    padding: 16px 18px !important;
}

#febFormPage .feb-step-navigation > .flex.items-start.gap-3 { display:none; }

#febFormPage .feb-step-navigation > .mt-4.h-2 {
    margin-top: 0 !important;
    height: 4px !important;
    border: 0 !important;
    background: #edf0f2 !important;
}

#febFormPage #febProgress {
    background: var(--feb-blue) !important;
    transition: width .25s ease;
}

#febFormPage .feb-step-navigation .grid.grid-cols-3 {
    margin-top: 14px !important;
    gap: 8px !important;
}

#febFormPage .feb-step-navigation [id^="febStepChip"] {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 38px;
    border: 0 !important;
    border-radius: 12px !important;
    background: transparent !important;
    color: var(--feb-muted) !important;
    padding: 8px 10px !important;
    font-weight: 600 !important;
}

#febFormPage .feb-step-navigation [id^="febStepChip"] i {
    width: 22px;
    height: 22px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: #f1f3f4;
    color: #80868b;
    font-size: 10px;
}

#febFormPage .feb-step-navigation [id^="febStepChip"].bg-indigo-600,
#febFormPage .feb-step-navigation [id^="febStepChip"].text-white,
#febFormPage .feb-step-navigation [id^="febStepChip"].border-indigo-600 {
    background: var(--feb-blue-soft) !important;
    color: var(--feb-blue-dark) !important;
}

#febFormPage .feb-step-navigation [id^="febStepChip"].bg-indigo-600 i,
#febFormPage .feb-step-navigation [id^="febStepChip"].text-white i {
    background: var(--feb-blue) !important;
    color: #fff !important;
}

/* Écrans du wizard */
#febFormPage .feb-step {
    max-width: 980px;
    margin: 26px auto 0;
}

#febFormPage .feb-step > section,
#febFormPage .feb-step > div.mb-4,
#febFormPage .feb-step > .flex.flex-col.gap-4 {
    border-color: var(--feb-line) !important;
}

#febFormPage .feb-step section,
#febFormPage .feb-quote-intro,
#febFormPage .feb-confirmation-summary {
    border-radius: 22px !important;
    box-shadow: none !important;
}

#febFormPage .feb-step section[class*="bg-white"],
#febFormPage .feb-quote-intro {
    background: #fff !important;
}

#febFormPage .feb-quote-intro {
    padding: 22px !important;
    border: 1px solid var(--feb-line) !important;
}

#febFormPage .feb-section-eyebrow,
#febFormPage .feb-step section > .text-xs.font-semibold.uppercase,
#febFormPage .feb-step span.text-xs.font-bold.uppercase,
#febFormPage .feb-step span.text-xs.font-semibold.uppercase {
    color: #6b7280 !important;
    letter-spacing: .1em !important;
    font-weight: 700 !important;
}

#febFormPage .feb-field-heading,
#febFormPage .feb-step label > .text-sm.font-semibold,
#febFormPage .feb-step label.block > span:first-child {
    color: #3c4043 !important;
    font-weight: 600 !important;
}

#febFormPage .feb-field-help,
#febFormPage .feb-step .text-xs.text-slate-500,
#febFormPage .feb-step .text-xs.text-slate-600 {
    color: var(--feb-muted) !important;
    line-height: 1.45;
}

/* Champs — look Google Forms/Workspace */
#febFormPage .feb-step input:not([type="hidden"]):not([type="radio"]):not([type="checkbox"]),
#febFormPage .feb-step select,
#febFormPage .feb-step textarea,
#febFormPage #febRouteStep select {
    min-height: 46px;
    border: 1px solid #dadce0 !important;
    border-radius: 12px !important;
    background: #fff !important;
    color: var(--feb-ink) !important;
    box-shadow: none !important;
    transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
}

#febFormPage .feb-step textarea { min-height: 110px; }

#febFormPage .feb-step input:focus,
#febFormPage .feb-step select:focus,
#febFormPage .feb-step textarea:focus,
#febFormPage #febRouteStep select:focus {
    outline: none !important;
    border-color: var(--feb-blue) !important;
    box-shadow: 0 0 0 1px var(--feb-blue) !important;
}

#febFormPage .feb-step input[readonly],
#febFormPage .feb-step input:disabled,
#febFormPage .feb-step select:disabled {
    background: #f8f9fa !important;
    color: #5f6368 !important;
}

#febFormPage .feb-step input[type="radio"],
#febFormPage .feb-step input[type="checkbox"] {
    accent-color: var(--feb-blue);
}

#febFormPage .feb-select-search-results {
    border-color: #dadce0 !important;
    border-radius: 14px !important;
    box-shadow: 0 8px 24px rgba(60,64,67,.18) !important;
}
#febFormPage .feb-select-search-option:hover,
#febFormPage .feb-select-search-option:focus { background:#f1f3f4 !important; }

/* Étape besoins */
#febFormPage #febDocumentZone {
    background: #f8fbff !important;
    border: 1px solid #d2e3fc !important;
    border-radius: 22px !important;
    padding: 20px !important;
}
#febFormPage .feb-step[data-step="1"] { display:flex; flex-direction:column; }
#febFormPage .feb-step[data-step="1"].hidden { display:none !important; }
#febFormPage #febNeedsBlock { order:1; }
#febFormPage #febDocumentZone { order:2; }
#febFormPage .feb-ai-loader-track { height:5px; overflow:hidden; border-radius:999px; background:#dbeafe; }
#febFormPage .feb-ai-loader-bar { width:38%; height:100%; border-radius:999px; background:#2563eb; animation:feb-ai-scan 1.15s ease-in-out infinite alternate; }
@keyframes feb-ai-scan { from { transform:translateX(-15%); } to { transform:translateX(180%); } }

#febFormPage #febAddBesoin {
    background: #fff !important;
    color: var(--feb-blue) !important;
    border: 1px solid #dadce0 !important;
    border-radius: 999px !important;
    box-shadow: none !important;
}
#febFormPage #febAddBesoin:hover { background:#f8fbff !important; border-color:#c6dafc !important; }

#febFormPage #febBesoinContainer > * {
    border-radius: 18px !important;
    border-color: var(--feb-line) !important;
    box-shadow: none !important;
}

#febFormPage #febBesoinSummary { line-height:1.5; }
#febFormPage .feb-step[data-step="1"] aside {
    position: sticky;
    top: 128px;
    align-self: flex-start;
    background: #f8f9fa !important;
    border-color: var(--feb-line) !important;
    border-radius: 18px !important;
}

/* Étape vérification */
#febFormPage .feb-confirmation-summary {
    background: var(--feb-green-soft) !important;
    border: 1px solid #ceead6 !important;
}
#febFormPage .feb-confirmation-summary h3 { color:#137333 !important; }
#febFormPage .feb-confirmation-summary p { color:#3c4043 !important; }

#febFormPage .feb-step[data-step="2"] section.mt-4.border-emerald-200 {
    background: #fff !important;
    border: 1px solid var(--feb-line) !important;
    padding: 22px !important;
}

#febFormPage .feb-step[data-step="2"] section.mt-4.border-emerald-200 > .text-sm.font-semibold:first-child {
    font-size: 1.05rem;
    font-weight: 600 !important;
}

#febFormPage #febBeneficiaryPreview,
#febFormPage #draftFdBudgetIndicator,
#febFormPage #febScheduleStatus {
    border-color: var(--feb-line) !important;
    border-radius: 12px !important;
}

#febFormPage .feb-step[data-step="2"] .bg-indigo-50,
#febFormPage .feb-step[data-step="2"] .bg-sky-50 {
    background: #f8fbff !important;
    border-color: #d2e3fc !important;
}

/* Fichiers / import */
#febFormPage input[type="file"]::file-selector-button {
    border: 0;
    border-radius: 999px;
    padding: 8px 14px;
    margin-right: 12px;
    background: #f1f3f4;
    color: #3c4043;
    font-weight: 600;
    cursor: pointer;
}
#febFormPage input[type="file"]::file-selector-button:hover { background:#e8eaed; }

#febFormPage .feb-import-tools {
    border-top: 1px solid var(--feb-line-soft);
    padding-top: 14px;
}
#febFormPage .feb-import-tools summary { color:var(--feb-blue); cursor:pointer; font-weight:600; }

/* Barre d’actions */
#febFormPage .feb-form-actions {
    position: sticky;
    bottom: 12px;
    z-index: 30;
    max-width: 980px;
    margin: 24px auto 0;
    padding: 12px !important;
    border: 1px solid #e8eaed;
    border-radius: 18px;
    background: rgba(255,255,255,.94);
    backdrop-filter: blur(12px);
    box-shadow: 0 4px 18px rgba(60,64,67,.14);
}

#febFormPage .feb-form-actions a,
#febFormPage .feb-form-actions button {
    min-height: 42px;
    border-radius: 999px !important;
    font-weight: 600 !important;
    padding-left: 18px !important;
    padding-right: 18px !important;
}

#febFormPage #febPrev,
#febFormPage .feb-form-actions > a {
    background:#fff !important;
    border:1px solid #dadce0 !important;
    color:#3c4043 !important;
}
#febFormPage #febPrev:hover,
#febFormPage .feb-form-actions > a:hover { background:#f8f9fa !important; }

#febFormPage #febNext,
#febFormPage #febSubmit {
    background: var(--feb-blue) !important;
    color:#fff !important;
    border:1px solid var(--feb-blue) !important;
}
#febFormPage #febNext:hover,
#febFormPage #febSubmit:hover { background:var(--feb-blue-dark) !important; }
#febFormPage #febSubmit:disabled { opacity:.45; cursor:not-allowed; }

/* Erreurs */
#febFormPage .border-rose-200.bg-rose-50 {
    border-color:#f6aea8 !important;
    background:#fce8e6 !important;
    color:#a50e0e !important;
}


/* =========================================================
   FEB wide layout — pleine largeur, présentation uniquement
   Aucun nom/id/champ/comportement n'est modifié.
   ========================================================= */
@media (min-width: 1024px) {
    #febFormPage .feb-page-shell {
        width: 100%;
        max-width: none;
        margin: 0;
        padding-left: clamp(20px, 2.25vw, 44px) !important;
        padding-right: clamp(20px, 2.25vw, 44px) !important;
    }

    #febFormPage .feb-form-surface {
        width: 100%;
        max-width: none;
        padding: clamp(28px, 2.4vw, 46px) !important;
    }

    #febFormPage form#febMainForm,
    #febFormPage .feb-step,
    #febFormPage .feb-form-actions {
        width: 100%;
        max-width: none;
    }

    #febFormPage .feb-step {
        margin-left: 0;
        margin-right: 0;
    }

    #febFormPage .feb-step-navigation {
        width: 100%;
    }

    /* Étape 1 : l'identification profite de la largeur disponible. */
    #febFormPage .feb-step[data-step="0"]:not(.hidden) {
        display: grid;
        grid-template-columns: minmax(320px, .82fr) minmax(520px, 1.45fr);
        gap: 20px;
        align-items: start;
    }

    #febFormPage .feb-step[data-step="0"]:not(.hidden) > section {
        margin-top: 0 !important;
        height: 100%;
    }

    #febFormPage .feb-step[data-step="1"] > .flex.flex-col.gap-4 {
        gap: 22px !important;
    }

    #febFormPage .feb-step[data-step="1"] aside {
        width: clamp(320px, 24vw, 420px) !important;
        flex: 0 0 clamp(320px, 24vw, 420px);
    }

    #febFormPage .feb-step[data-step="2"] > section {
        width: 100%;
    }

    #febFormPage .feb-form-actions {
        margin-left: 0;
        margin-right: 0;
    }
}

@media (min-width: 1440px) {
    #febFormPage .feb-page-shell {
        padding-left: clamp(32px, 3vw, 64px) !important;
        padding-right: clamp(32px, 3vw, 64px) !important;
    }

    #febFormPage .feb-form-surface {
        border-radius: 30px !important;
    }

    #febFormPage .feb-step-navigation {
        padding-left: 24px !important;
        padding-right: 24px !important;
    }

    #febFormPage .feb-step[data-step="0"]:not(.hidden) {
        grid-template-columns: minmax(380px, .78fr) minmax(640px, 1.55fr);
        gap: 26px;
    }

    #febFormPage #febDocumentZone,
    #febFormPage .feb-step[data-step="2"] > section,
    #febFormPage .feb-step[data-step="0"] > section {
        padding: 26px !important;
    }

    #febFormPage .feb-step[data-step="1"] aside {
        width: clamp(360px, 22vw, 460px) !important;
        flex-basis: clamp(360px, 22vw, 460px);
    }
}

@media (min-width: 1900px) {
    #febFormPage .feb-page-shell {
        padding-left: 72px !important;
        padding-right: 72px !important;
    }

    #febFormPage .feb-step[data-step="0"]:not(.hidden) {
        grid-template-columns: minmax(430px, .72fr) minmax(760px, 1.62fr);
        gap: 30px;
    }
}

@media (max-width: 768px) {
    #febFormPage .feb-page-shell { padding: 14px 10px 56px; }
    #febFormPage .feb-form-surface { padding: 18px 14px !important; border-radius:20px !important; }
    #febFormPage .feb-form-surface > .flex.items-start.justify-between { align-items:flex-start; }
    #febFormPage .feb-step-navigation { top:6px; padding:12px 10px !important; }
    #febFormPage .feb-step-navigation [id^="febStepChip"] { font-size:10px; padding:6px 4px !important; }
    #febFormPage .feb-step-navigation [id^="febStepChip"] span { line-height:1.2; }
    #febFormPage .feb-step { margin-top:18px; }
    #febFormPage .feb-form-actions { bottom:6px; border-radius:16px; }
    #febFormPage .feb-form-actions > div { width:100%; display:grid; grid-template-columns:1fr 1fr; }
    #febFormPage #febSubmit { grid-column:1/-1; }
    #febFormPage .feb-form-actions > a { width:100%; text-align:center; }
}

</style>

<div <?= $type === 'feb' ? 'id="febFormPage"' : '' ?> class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(240,249,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="<?= $type === 'feb' ? 'feb-page-shell w-full' : 'mx-auto max-w-[1400px]' ?> px-4 py-6 2xl:px-8">
        <div class="<?= $type === 'feb' ? 'feb-form-surface' : '' ?> relative rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-600 via-slate-900 to-emerald-500"></div>
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-start gap-4">
                    <?php
                    $defLabel = (is_array($def) ? (string) ($def['label'] ?? '') : '');
                    $teamKey = (is_array($def) && (($def['team'] ?? '') === 'finance')) ? 'finance' : 'rh';
                    $teamLabel = ($teamKey === 'finance') ? 'Finances' : 'RH';
                    $teamChipClass = ($teamKey === 'finance')
                        ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
                        : 'border-slate-200 bg-slate-50 text-slate-700';
                    $icon = $typeIcons[$type] ?? 'fa-file-alt';
                    ?>
                    <span class="mt-1 inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white <?= htmlspecialchars((string) ($typeTheme['iconBg'] ?? 'bg-indigo-600')) ?>">
                        <i class="fas <?= htmlspecialchars((string) $icon) ?>"></i>
                    </span>

                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] <?= htmlspecialchars((string) ($typeTheme['labelText'] ?? 'text-indigo-600')) ?>">Nouvelle demande</p>
                        <h1 class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars($isEditMode ? ('Modifier — ' . $defLabel) : $defLabel) ?></h1>
                        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                            <span>Validation</span>
                            <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold <?= $teamChipClass ?>">
                                <?= htmlspecialchars($teamLabel) ?>
                            </span>
                            <?php if ($isEditMode): ?>
                                <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">Modification avant validation RH</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <a href="actes_legaux_employe.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">Retour</a>
            </div>

            <?php if ($errors): ?>
                <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-900">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex h-9 w-9 items-center justify-center rounded-2xl border border-rose-200 bg-white text-rose-700">
                            <i class="fas fa-exclamation-triangle"></i>
                        </span>
                        <div>
                            <div class="font-semibold">Veuillez corriger :</div>
                            <ul class="mt-2 list-disc pl-5 space-y-1">
                                <?php foreach ($errors as $err): ?>
                                    <li><?= htmlspecialchars((string) $err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($type === '' || !$def): ?>
                <div class="mt-5 text-sm text-slate-700">Type de demande manquant. Retournez à la liste des actes.</div>
            <?php else: ?>
                <form id="<?= $type === 'feb' ? 'febMainForm' : 'employeeRequestForm' ?>" class="mt-6 space-y-5<?= ($type === 'feb' && $_SERVER['REQUEST_METHOD'] !== 'POST' && !$isEditMode) ? ' feb-route-pending' : '' ?>" method="POST" <?= ($type === 'feb') ? 'enctype="multipart/form-data" novalidate' : '' ?>>
                    <?php if ($isEditMode): ?>
                        <input type="hidden" name="request_id" value="<?= (int) $editRequestId ?>">
                    <?php endif; ?>
                    <?php if ($type === 'feb' && $urgentRegularizationId > 0): ?>
                        <input type="hidden" name="urgent_id" value="<?= (int) $urgentRegularizationId ?>">
                        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-900">
                            <div class="font-semibold">Regularisation de decaissement urgent</div>
                            <div class="mt-1">
                                <?= htmlspecialchars((string)($urgentRegularization['request_code'] ?? ('Urgence #' . $urgentRegularizationId))) ?>
                                <?php if (is_array($urgentRegularization)): ?>
                                    - <?= htmlspecialchars((string)($urgentRegularization['chantier_label'] ?? '')) ?>
                                    - <?= htmlspecialchars(number_format((float)($urgentRegularization['amount'] ?? 0), 0, ',', ' ')) ?> CFA
                                <?php endif; ?>
                            </div>
                            <div class="mt-2 text-xs">Cette régularisation suit le parcours FEB normal : sélectionnez le poste de déboursé concerné, puis la fiche associée sera créée après validation.</div>
                        </div>
                    <?php elseif ($urgentBlockMessage !== ''): ?>
                        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-4 text-sm text-rose-900">
                            <?= htmlspecialchars($urgentBlockMessage) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($type === 'permission_absence'): ?>
                        <?php
                        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
                        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
                        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
                        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));
                        ?>

                        <div class="space-y-5">
                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Identité</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">NOM ET PRENOM(S)</label>
                                        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($nom) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">N° Matricule</label>
                                        <input type="text" id="matricule" name="matricule" value="<?= htmlspecialchars($matricule) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">FONCTION</label>
                                        <input type="text" id="fonction" name="fonction" value="<?= htmlspecialchars($fonction) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">SERVICE</label>
                                        <input type="text" id="service" name="service" value="<?= htmlspecialchars($service) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-2xl border bg-white px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Détails de la demande</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Date de départ</label>
                                        <input type="date" id="date_depart" name="date_depart" value="<?= htmlspecialchars((string) ($_POST['date_depart'] ?? ($existingPayload['date_depart'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Nombre de jours</label>
                                        <input type="number" id="nombre_jours" name="nombre_jours" min="1" value="<?= htmlspecialchars((string) ($_POST['nombre_jours'] ?? ($existingPayload['nombre_jours'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Date de retour</label>
                                    <input type="date" id="date_retour" name="date_retour" value="<?= htmlspecialchars((string) ($_POST['date_retour'] ?? ($existingPayload['date_retour'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-slate-50" readonly required>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Motif de la demande</label>
                                    <textarea id="motif_demande" name="motif_demande" rows="5" class="mt-1 w-full rounded-[24px] border border-slate-200 bg-gradient-to-br from-white to-sky-50 px-4 py-3 text-slate-800 shadow-[inset_0_1px_0_rgba(255,255,255,0.9)] outline-none transition focus:border-sky-300 focus:ring-4 focus:ring-sky-100" placeholder="Expliquez brièvement la raison de votre absence, le contexte et toute précision utile pour la RH." required><?= htmlspecialchars((string) ($_POST['motif_demande'] ?? ($existingPayload['motif_demande'] ?? ''))) ?></textarea>
                                    <p class="mt-2 text-xs text-slate-500">Ce motif sera visible dans le suivi de la demande et transmis par email à la RH.</p>
                                </div>
                            </section>
                        </div>
                    <?php elseif ($type === 'conge'): ?>
                        <?php
                        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
                        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
                        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
                        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));
                        ?>

                        <div class="space-y-5">
                            <?php if (is_array($leaveSituation)): ?>
                                <?php
                                $leaveSource = (string) ($leaveSituation['source'] ?? '');
                                $leaveSourceLabel = match ($leaveSource) {
                                    'opening_balance' => 'Solde initial RH',
                                    'hire_date' => 'Date d\'embauche / prise de service',
                                    default => 'Situation a initialiser par les RH',
                                };
                                $leaveTone = !empty($leaveSituation['can_request']) ? 'border-cyan-200 bg-cyan-50 text-cyan-900' : 'border-amber-200 bg-amber-50 text-amber-900';
                                ?>
                                <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars($leaveTone) ?>">
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <div class="text-xs font-semibold uppercase tracking-[0.2em]">Situation conge</div>
                                            <div class="mt-2 text-sm"><?= htmlspecialchars((string) ($leaveSituation['message'] ?? '')) ?></div>
                                            <div class="mt-2 text-xs">Base de calcul : <span class="font-semibold"><?= htmlspecialchars($leaveSourceLabel) ?></span></div>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
                                            <div class="rounded-xl bg-white/70 px-3 py-2">
                                                <div class="text-[11px] uppercase tracking-wide opacity-70">Reference</div>
                                                <div class="font-semibold"><?= htmlspecialchars(employeeFormatDateFr((string) ($leaveSituation['reference_date'] ?? ''))) ?></div>
                                            </div>
                                            <div class="rounded-xl bg-white/70 px-3 py-2">
                                                <div class="text-[11px] uppercase tracking-wide opacity-70">Initial</div>
                                                <div class="font-semibold"><?= number_format((float) ($leaveSituation['initial_balance_days'] ?? 0), 2, ',', ' ') ?> j</div>
                                            </div>
                                            <div class="rounded-xl bg-white/70 px-3 py-2">
                                                <div class="text-[11px] uppercase tracking-wide opacity-70">Acquis ERP</div>
                                                <div class="font-semibold"><?= number_format((float) ($leaveSituation['accrued_days'] ?? 0), 2, ',', ' ') ?> j</div>
                                            </div>
                                            <div class="rounded-xl bg-white/70 px-3 py-2">
                                                <div class="text-[11px] uppercase tracking-wide opacity-70">Solde</div>
                                                <div class="font-semibold"><?= number_format((float) ($leaveSituation['balance_days'] ?? 0), 2, ',', ' ') ?> j</div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($leaveRequestBlocked): ?>
                                        <div class="mt-3 rounded-xl border border-amber-200 bg-white/70 px-3 py-2 text-sm font-semibold">
                                            Votre situation de conge doit etre initialisee par les RH avant toute demande. Veuillez contacter le service RH.
                                        </div>
                                    <?php endif; ?>
                                </section>
                            <?php endif; ?>

                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Identite</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">NOM ET PRENOM(S)</label>
                                        <input type="text" name="nom" value="<?= htmlspecialchars($nom) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">N° Matricule</label>
                                        <input type="text" name="matricule" value="<?= htmlspecialchars($matricule) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">FONCTION</label>
                                        <input type="text" name="fonction" value="<?= htmlspecialchars($fonction) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">SERVICE</label>
                                        <input type="text" name="service" value="<?= htmlspecialchars($service) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-2xl border bg-white px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Details du conge</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Date de debut</label>
                                        <input type="date" id="date_debut" name="date_debut" value="<?= htmlspecialchars((string) ($_POST['date_debut'] ?? ($existingPayload['date_debut'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Date de fin</label>
                                        <input type="date" id="date_fin" name="date_fin" value="<?= htmlspecialchars((string) ($_POST['date_fin'] ?? ($existingPayload['date_fin'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Motif</label>
                                    <textarea id="motif" name="motif" rows="4" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required><?= htmlspecialchars((string) ($_POST['motif'] ?? ($existingPayload['motif'] ?? ''))) ?></textarea>
                                    <p class="mt-2 text-xs text-slate-500">La duree sera calculee automatiquement a partir des dates saisies.</p>
                                </div>
                            </section>
                        </div>
                    <?php elseif ($type === 'retard'): ?>
                        <?php
                        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
                        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
                        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
                        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));
                        ?>

                        <div class="space-y-5">
                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Identite</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">NOM ET PRENOM(S)</label>
                                        <input type="text" name="nom" value="<?= htmlspecialchars($nom) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">N° Matricule</label>
                                        <input type="text" name="matricule" value="<?= htmlspecialchars($matricule) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">FONCTION</label>
                                        <input type="text" name="fonction" value="<?= htmlspecialchars($fonction) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">SERVICE</label>
                                        <input type="text" name="service" value="<?= htmlspecialchars($service) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-2xl border bg-white px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Details du retard</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Date du retard</label>
                                        <input type="date" id="date_retard" name="date_retard" value="<?= htmlspecialchars((string) ($_POST['date_retard'] ?? ($existingPayload['date_retard'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Heure d'arrivee</label>
                                        <input type="time" id="heure_arrivee" name="heure_arrivee" value="<?= htmlspecialchars((string) ($_POST['heure_arrivee'] ?? ($existingPayload['heure_arrivee'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Duree du retard (optionnel)</label>
                                    <input type="text" id="duree_retard" name="duree_retard" value="<?= htmlspecialchars((string) ($_POST['duree_retard'] ?? ($existingPayload['duree_retard'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" placeholder="Ex: 30 minutes">
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Motif</label>
                                    <textarea id="motif" name="motif" rows="4" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required><?= htmlspecialchars((string) ($_POST['motif'] ?? ($existingPayload['motif'] ?? ''))) ?></textarea>
                                </div>
                            </section>
                        </div>
                    <?php elseif ($type === 'pret'): ?>
                        <?php
                        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
                        $matricule = (string) ($personnelProfile['matricule'] ?? '');
                        ?>
                        <input type="hidden" name="matricule" value="<?= htmlspecialchars((string) $matricule) ?>">

                        <div class="space-y-5">
                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Identité</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">NOM ET PRENOM(S)</label>
                                        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($nom) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Matricule</label>
                                        <input type="text" value="<?= htmlspecialchars((string) $matricule) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-2xl border bg-white px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Détails du prêt</div>

                                <div class="mt-3">
                                    <label class="text-sm font-semibold text-slate-700">Désignation du prêt</label>
                                    <input type="text" id="designation_pret" name="designation_pret" value="<?= htmlspecialchars((string) ($_POST['designation_pret'] ?? '')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                </div>

                                <div class="mt-4 grid gap-4">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">MONTANT DEMANDÉ (en FCFA)</label>
                                        <input type="number" id="montant_demande" name="montant_demande" value="<?= htmlspecialchars((string) ($_POST['montant_demande'] ?? '')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">MONTANT DU RECOUVREMENT PARTIEL (en FCFA)</label>
                                        <input type="number" id="montant_recouvrement" name="montant_recouvrement" value="<?= htmlspecialchars((string) ($_POST['montant_recouvrement'] ?? '')) ?>" class="mt-1 w-full rounded-2xl border border-rose-200 px-4 py-2" required>
                                        <div class="mt-1 text-xs text-rose-700">Ce retranchement est irrévocable pendant la période de recouvrement</div>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Recouvrement</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">DATE DE DÉBUT DU RECOUVREMENT</label>
                                        <input type="date" id="date_debut" name="date_debut" value="<?= htmlspecialchars((string) ($_POST['date_debut'] ?? '')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">DATE DE FIN DU RECOUVREMENT</label>
                                        <input type="date" id="date_fin" name="date_fin" value="<?= htmlspecialchars((string) ($_POST['date_fin'] ?? '')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">NOMBRE DE MENSUALITÉS</label>
                                    <input type="text" id="nombre_mensualites" name="nombre_mensualites" value="<?= htmlspecialchars((string) ($_POST['nombre_mensualites'] ?? '')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly>
                                </div>
                            </section>
                        </div>
                    <?php elseif ($type === 'avance_salaire'): ?>
                        <?php
                        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
                        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
                        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
                        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));
                        ?>

                        <div class="space-y-5">
                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Identité</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">NOM ET PRENOM(S)</label>
                                        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($nom) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">N° Matricule</label>
                                        <input type="text" id="matricule" name="matricule" value="<?= htmlspecialchars($matricule) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">FONCTION</label>
                                        <input type="text" id="fonction" name="fonction" value="<?= htmlspecialchars($fonction) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">SERVICE</label>
                                        <input type="text" id="service" name="service" value="<?= htmlspecialchars($service) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-2xl border bg-white px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Détails de la demande</div>

                                <div class="mt-3">
                                    <label class="text-sm font-semibold text-slate-700">Motif</label>
                                    <textarea id="motif" name="motif" rows="3" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required><?= htmlspecialchars((string) ($_POST['motif'] ?? ($existingPayload['motif'] ?? ''))) ?></textarea>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">MONTANT (en FCFA)</label>
                                    <input type="number" id="montant" name="montant" value="<?= htmlspecialchars((string) ($_POST['montant'] ?? ($existingPayload['montant'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                </div>
                            </section>
                        </div>
                    <?php elseif ($type === 'heures_supp'): ?>
                        <?php
                        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
                        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
                        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
                        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));
                        ?>

                        <div class="space-y-5">
                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Identité</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">NOM ET PRENOM(S)</label>
                                        <input type="text" id="nom" name="nom" value="<?= htmlspecialchars($nom) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">N° Matricule</label>
                                        <input type="text" id="matricule" name="matricule" value="<?= htmlspecialchars($matricule) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">FONCTION</label>
                                        <input type="text" id="fonction" name="fonction" value="<?= htmlspecialchars($fonction) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">SERVICE</label>
                                        <input type="text" id="service" name="service" value="<?= htmlspecialchars($service) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly required>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-2xl border bg-white px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Détails de la demande</div>

                                <div class="mt-3">
                                    <label class="text-sm font-semibold text-slate-700">Motif</label>
                                    <textarea id="motif" name="motif" rows="3" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required><?= htmlspecialchars((string) ($_POST['motif'] ?? ($existingPayload['motif'] ?? ''))) ?></textarea>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Nombre d'heures supplémentaires</label>
                                    <input type="number" id="heures" name="heures" min="1" max="1000" value="<?= htmlspecialchars((string) ($_POST['heures'] ?? ($existingPayload['heures'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Date de prise effective (optionnel)</label>
                                    <input type="date" id="date_effective" name="date_effective" value="<?= htmlspecialchars((string) ($_POST['date_effective'] ?? ($existingPayload['date_effective'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2">
                                    <div class="mt-1 text-xs text-slate-500">Laissez vide si la date n'est pas encore déterminée</div>
                                </div>
                            </section>
                        </div>
                    <?php elseif ($type === 'recrutement'): ?>
                        <div class="space-y-5">
                            <section class="rounded-3xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-white p-5"><div class="flex items-start gap-4"><span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-indigo-600 text-xl text-white"><i class="fas fa-user-plus"></i></span><div><h2 class="text-lg font-black text-indigo-950">Besoin de recrutement</h2><p class="mt-1 text-sm leading-6 text-indigo-800">Décrivez le poste et le profil attendu. La demande sera transmise au service RH pour étude et décision.</p></div></div></section>
                            <section class="rounded-3xl border border-slate-200 bg-white p-5"><div class="grid gap-4 md:grid-cols-2">
                                <label class="text-sm font-semibold text-slate-700">Poste recherché<input name="poste_recherche" required value="<?=htmlspecialchars((string)($_POST['poste_recherche']??($existingPayload['poste_recherche']??'')))?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3" placeholder="Ex. Conducteur de travaux"></label>
                                <label class="text-sm font-semibold text-slate-700">Service demandeur<input name="service_demandeur" required value="<?=htmlspecialchars((string)($_POST['service_demandeur']??($existingPayload['service_demandeur']??($personnelProfile['service_label']??''))))?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3"></label>
                                <label class="text-sm font-semibold text-slate-700">Nombre de postes<input type="number" min="1" max="100" name="nombre_postes" required value="<?=htmlspecialchars((string)($_POST['nombre_postes']??($existingPayload['nombre_postes']??1)))?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3"></label>
                                <label class="text-sm font-semibold text-slate-700">Type de contrat<select name="type_contrat" required class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3"><option value="">Sélectionner…</option><?php $selectedContract=(string)($_POST['type_contrat']??($existingPayload['type_contrat']??''));foreach(['CDI','CDD','Stage','Temporaire','Consultant'] as $contractType):?><option value="<?=$contractType?>" <?=$selectedContract===$contractType?'selected':''?>><?=$contractType?></option><?php endforeach;?></select></label>
                                <label class="text-sm font-semibold text-slate-700 md:col-span-2">Date souhaitée de prise de poste<input type="date" name="date_souhaitee" value="<?=htmlspecialchars((string)($_POST['date_souhaitee']??($existingPayload['date_souhaitee']??'')))?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3"></label>
                                <label class="text-sm font-semibold text-slate-700 md:col-span-2">Motif du recrutement<textarea name="motif_recrutement" rows="4" required class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3" placeholder="Remplacement, augmentation d’activité, nouvelle compétence…"><?=htmlspecialchars((string)($_POST['motif_recrutement']??($existingPayload['motif_recrutement']??'')))?></textarea></label>
                                <label class="text-sm font-semibold text-slate-700 md:col-span-2">Profil et compétences recherchés<textarea name="profil_recherche" rows="5" required class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3" placeholder="Formation, expérience, compétences techniques et qualités attendues…"><?=htmlspecialchars((string)($_POST['profil_recherche']??($existingPayload['profil_recherche']??'')))?></textarea></label>
                                <label class="text-sm font-semibold text-slate-700">Impact financier entrant estimé (FCFA)<input type="number" min="0" step="1" name="impact_financier_entrant" value="<?=htmlspecialchars((string)($_POST['impact_financier_entrant']??($existingPayload['impact_financier_entrant']??0)))?>" class="mt-2 w-full rounded-2xl border border-emerald-200 px-4 py-3" placeholder="Recettes ou économies attendues"></label>
                                <label class="text-sm font-semibold text-slate-700">Impact financier sortant estimé (FCFA)<input type="number" min="1" step="1" required name="impact_financier_sortant" value="<?=htmlspecialchars((string)($_POST['impact_financier_sortant']??($existingPayload['impact_financier_sortant']??'')))?>" class="mt-2 w-full rounded-2xl border border-rose-200 px-4 py-3" placeholder="Salaire, charges, matériel, recrutement…"></label>
                                <label class="text-sm font-semibold text-slate-700 md:col-span-2">Valeur ajoutée attendue<textarea name="valeur_ajoutee" rows="4" required class="mt-2 w-full rounded-2xl border border-amber-200 px-4 py-3" placeholder="Gains de productivité, réduction des risques, développement du chiffre d’affaires, continuité de service…"><?=htmlspecialchars((string)($_POST['valeur_ajoutee']??($existingPayload['valeur_ajoutee']??'')))?></textarea></label>
                            </div></section>
                        </div>
                    <?php elseif ($type === 'reclamation_salariale'): ?>
                        <?php
                        $identity = splitEmployeeIdentity((array) $personnelProfile);
                        $nom = (string) ($identity['nom'] ?? '');
                        $prenoms = (string) ($identity['prenoms'] ?? '');
                        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
                        $fonction = strtoupper((string) ($personnelProfile['fonction_label'] ?? ''));
                        $service = strtoupper((string) ($personnelProfile['service_label'] ?? ''));
                        $claimObjects = \EmployeeLegalRequestManager::getSalaryClaimObjectOptions();
                        ?>

                        <div class="space-y-5">
                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Date de la demande</div>
                                <div class="mt-3 max-w-sm">
                                    <label class="text-sm font-semibold text-slate-700">Date</label>
                                    <input type="date" id="date_demande" name="date_demande" value="<?= htmlspecialchars((string) ($_POST['date_demande'] ?? ($existingPayload['date_demande'] ?? date('Y-m-d')))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" required>
                                </div>
                            </section>

                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Informations du salarié</div>
                                <div class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Nom</label>
                                        <input type="text" name="nom" value="<?= htmlspecialchars($nom) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Prénoms</label>
                                        <input type="text" name="prenoms" value="<?= htmlspecialchars($prenoms) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Matricule</label>
                                        <input type="text" name="matricule" value="<?= htmlspecialchars($matricule) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Service</label>
                                        <input type="text" name="service" value="<?= htmlspecialchars($service) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Fonction</label>
                                        <input type="text" name="fonction" value="<?= htmlspecialchars($fonction) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-white" readonly>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-2xl border bg-white px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Objet de la réclamation</div>
                                <div class="mt-3">
                                    <label class="text-sm font-semibold text-slate-700">Objet</label>
                                    <select id="objet_reclamation" name="objet_reclamation" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                        <option value="">Sélectionner...</option>
                                        <?php $selectedObject = (string) ($_POST['objet_reclamation'] ?? ($existingPayload['objet_reclamation'] ?? '')); ?>
                                        <?php foreach ($claimObjects as $claimKey => $claimLabel): ?>
                                            <option value="<?= htmlspecialchars((string) $claimKey) ?>" <?= $selectedObject === (string) $claimKey ? 'selected' : '' ?>><?= htmlspecialchars((string) $claimLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Mois concerné</label>
                                        <input type="month" id="mois_concerne" name="mois_concerne" value="<?= htmlspecialchars((string) ($_POST['mois_concerne'] ?? ($existingPayload['mois_concerne'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Montant manquant (FCFA)</label>
                                        <input type="number" id="montant_manquant" name="montant_manquant" min="1" step="0.01" value="<?= htmlspecialchars((string) ($_POST['montant_manquant'] ?? ($existingPayload['montant_manquant'] ?? ''))) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Détails du différend</label>
                                    <textarea id="details_differend" name="details_differend" rows="5" class="mt-1 w-full rounded-[24px] border border-slate-200 bg-gradient-to-br from-white to-rose-50 px-4 py-3 text-slate-800 outline-none transition focus:border-rose-300 focus:ring-4 focus:ring-rose-100" placeholder="Décrivez précisément le différend, la paie concernée et les vérifications déjà effectuées." required><?= htmlspecialchars((string) ($_POST['details_differend'] ?? ($existingPayload['details_differend'] ?? ''))) ?></textarea>
                                </div>
                            </section>

                            <section class="rounded-2xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> <?= htmlspecialchars((string) ($typeTheme['softBg'] ?? 'bg-slate-50')) ?>">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Signataires prévus</div>
                                <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4 text-sm text-slate-700">
                                    <div class="rounded-2xl border border-white/70 bg-white px-4 py-3"><span class="font-semibold">Demandeur</span>
                                        <div class="mt-1"><?= htmlspecialchars(trim($prenoms . ' ' . $nom)) ?></div>
                                    </div>
                                    <div class="rounded-2xl border border-white/70 bg-white px-4 py-3"><span class="font-semibold">RH</span>
                                        <div class="mt-1 text-slate-500">Visa à la validation</div>
                                    </div>
                                    <div class="rounded-2xl border border-white/70 bg-white px-4 py-3"><span class="font-semibold">DRH</span>
                                        <div class="mt-1 text-slate-500">Zone de signature</div>
                                    </div>
                                    <div class="rounded-2xl border border-white/70 bg-white px-4 py-3"><span class="font-semibold">DG</span>
                                        <div class="mt-1 text-slate-500">Zone de signature</div>
                                    </div>
                                </div>
                            </section>
                        </div>
                    <?php elseif ($type === 'feb'): ?>
                        <section id="febRouteStep" data-skip="<?= ($_SERVER['REQUEST_METHOD']==='POST'||$isEditMode)?'1':'0' ?>" class="mb-6 overflow-hidden rounded-3xl border-2 border-amber-300 bg-white shadow-sm">
                            <div class="bg-black px-5 py-4 text-white">
                                <p id="febRouteCounter" class="text-xs font-black uppercase tracking-widest text-amber-300"></p>
                                <h2 id="febRouteTitle" class="mt-1 text-xl font-black">Quel type de FEB souhaitez-vous créer ?</h2>
                                <div class="feb-route-lead">Choisissez le parcours adapté à votre dépense. Vous pourrez ensuite renseigner votre demande étape par étape.</div>
                            </div>
                            <div id="febRouteType" class="grid gap-4 p-5 lg:grid-cols-2">
                                <button type="button" id="febChooseOrdinary" class="rounded-2xl border border-slate-200 p-5 text-left transition hover:border-black hover:shadow-lg">
                                    <h3 class="font-black text-slate-950"><i class="fas fa-receipt mr-2 text-amber-600"></i>FEB ordinaire</h3>
                                    <p class="mt-2 text-sm text-slate-600">Pour un achat, une prestation ou un besoin ponctuel.</p>
                                </button>
                                <button type="button" id="febChooseContract" class="rounded-2xl border border-amber-300 bg-amber-50 p-5 text-left transition hover:border-black hover:shadow-lg">
                                    <h3 class="font-black text-slate-950"><i class="fas fa-file-signature mr-2 text-amber-700"></i>FEB sur contrat</h3>
                                    <p class="mt-2 text-sm text-slate-700">Pour une dépense rattachée à un contrat existant.</p>
                                </button>
                            </div>
                            <div id="febRouteProject" class="hidden p-5">
                                <label class="block font-black text-slate-950">Étape 2 — Sélectionnez le chantier
                                    <select id="febContractProject" class="mt-2 w-full rounded-xl border border-amber-300 bg-white p-3">
                                        <option value="">Choisir un chantier</option>
                                        <?php $seenContractProjects=[];foreach($febContractOptions as $contract):$cid=(int)$contract['chantier_id'];if(isset($seenContractProjects[$cid]))continue;$seenContractProjects[$cid]=true;?>
                                            <option value="<?=$cid?>"><?=htmlspecialchars((string)$contract['chantier_code'].' · '.(string)$contract['chantier_titre'])?></option>
                                        <?php endforeach;?>
                                    </select>
                                </label>
                                <div class="mt-4 flex gap-2"><button type="button" data-route-back="type" class="rounded-xl border px-4 py-2 font-bold">Retour</button><button type="button" id="febProjectNext" disabled class="rounded-xl bg-black px-5 py-2 font-black text-white disabled:opacity-40">Continuer</button></div>
                            </div>
                            <div id="febRouteContract" class="hidden p-5">
                                <label class="block font-black text-slate-950">Étape 3 — Sélectionnez le contrat
                                    <select id="febContractSelect" class="mt-2 w-full rounded-xl border border-amber-300 bg-white p-3">
                                        <option value="">Choisir un contrat</option>
                                        <?php foreach($febContractOptions as $contract):?>
                                            <option value="<?=(int)$contract['id']?>" data-project="<?=(int)$contract['chantier_id']?>" data-status="<?=htmlspecialchars((string)$contract['statut'])?>"><?=htmlspecialchars((string)$contract['reference'].' — '.(string)$contract['fournisseur_nom'].' — '.number_format((float)$contract['montant_ht'],0,',',' ').' FCFA')?></option>
                                        <?php endforeach;?>
                                    </select>
                                </label>
                                <p id="febContractStatus" class="mt-3 hidden rounded-xl p-3 text-sm font-bold"></p>
                                <div class="mt-4 flex gap-2"><button type="button" data-route-back="project" class="rounded-xl border px-4 py-2 font-bold">Retour</button><a id="febOpenContract" href="#" class="pointer-events-none rounded-xl bg-black px-5 py-2 font-black text-white opacity-40">Éditer la FEB contrat</a></div>
                            </div>
                        </section>
                        <script src="feb/js/contract-route.js?v=<?= htmlspecialchars($febAssetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
                        <link rel="stylesheet" href="feb/css/assistant.css?v=<?= htmlspecialchars($febAssetVersion, ENT_QUOTES, 'UTF-8') ?>">
                        <?php
                        $nom = strtoupper((string) ($personnelProfile['full_name'] ?? ''));
                        $matricule = strtoupper((string) ($personnelProfile['matricule'] ?? ''));
                        $serviceEmploye = strtoupper((string) ($personnelProfile['service_label'] ?? ''));
                        $departementPosted = array_key_exists('departement_id', $_POST) ? (int) $_POST['departement_id'] : (int) ($existingPayload['departement_id'] ?? 0);

                        $initBesoins = [];
                        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                            $typesPosted = $_POST['besoin_type'] ?? [];
                            $categoriesPosted = $_POST['besoin_categorie'] ?? [];
                            $libsPosted = $_POST['besoin_libelle'] ?? [];
                            $qtyPosted = $_POST['besoin_quantite'] ?? [];
                            $supplierIdsPosted = $_POST['besoin_fournisseur_id'] ?? [];
                            $proofTokensPosted = $_POST['besoin_justificatif_token'] ?? [];
                            $maxInit = max(count($typesPosted), count($libsPosted), count($qtyPosted), count($supplierIdsPosted));
                            for ($i = 0; $i < $maxInit; $i++) {
                                $t = (string) ($typesPosted[$i] ?? '');
                                $l = (string) ($libsPosted[$i] ?? '');
                                $q = (string) ($qtyPosted[$i] ?? '');
                                if (trim($t) === '' && trim($l) === '' && trim($q) === '') {
                                    continue;
                                }
                                $postedProofToken = trim((string) ($proofTokensPosted[$i] ?? ''));
                                $pendingProofEntry = is_array(($_SESSION['feb_pending_line_proofs'] ?? [])[$postedProofToken] ?? null)
                                    ? (array) $_SESSION['feb_pending_line_proofs'][$postedProofToken]
                                    : [];
                                $pendingProof = is_array($pendingProofEntry['proof'] ?? null) ? (array) $pendingProofEntry['proof'] : [];
                                $initBesoins[] = [
                                    'type' => $t,
                                    'categorie' => (string) ($categoriesPosted[$i] ?? ''),
                                    'libelle' => $l,
                                    'quantite' => $q,
                                    'has_fournisseur' => !empty(($_POST['besoin_has_fournisseur'][$i] ?? 0)),
                                    'fournisseur_id' => (string) ($supplierIdsPosted[$i] ?? ''),
                                    'fournisseur_nom' => (string) ($_POST['besoin_fournisseur_nom'][$i] ?? ''),
                                    'fournisseur_prix' => (string) ($_POST['besoin_fournisseur_prix'][$i] ?? ''),
                                    'fournisseur_tel' => (string) ($_POST['besoin_fournisseur_tel'][$i] ?? ''),
                                    'justificatif_token' => $postedProofToken,
                                    'justificatif_devis' => $pendingProof ?: (is_array(($existingPayload['besoins'][$i]['justificatif_devis'] ?? null)) ? (array) $existingPayload['besoins'][$i]['justificatif_devis'] : []),
                                ];
                            }
                        } else {
                            foreach ((array) ($existingPayload['besoins'] ?? []) as $existingBesoin) {
                                if (!is_array($existingBesoin)) {
                                    continue;
                                }
                                $existingSupplier = !empty($existingBesoin['fournisseur']) && is_array($existingBesoin['fournisseur']) ? (array) $existingBesoin['fournisseur'] : [];
                                $initBesoins[] = [
                                    'type' => (string) ($existingBesoin['type'] ?? ''),
                                    'categorie' => (string) ($existingBesoin['categorie'] ?? ''),
                                    'libelle' => (string) ($existingBesoin['libelle'] ?? ''),
                                    'quantite' => (string) ($existingBesoin['quantite'] ?? ''),
                                    'has_fournisseur' => !empty($existingBesoin['has_fournisseur']),
                                    'fournisseur_id' => (string) ($existingSupplier['id'] ?? ''),
                                    'fournisseur_nom' => (string) ($existingSupplier['nom'] ?? ''),
                                    'fournisseur_prix' => (string) ($existingSupplier['prix_unitaire'] ?? ''),
                                    'fournisseur_tel' => (string) ($existingSupplier['telephone'] ?? ''),
                                    'justificatif_token' => '',
                                    'justificatif_devis' => is_array($existingBesoin['justificatif_devis'] ?? null) ? (array) $existingBesoin['justificatif_devis'] : [],
                                ];
                            }
                        }
                        $febInit = [
                            'besoins' => $initBesoins,
                            'description' => (string) ($_POST['description'] ?? ($existingPayload['description'] ?? '')),
                            'montant' => (string) ($_POST['montant'] ?? ($existingPayload['montant'] ?? '')),
                            'date_livraison' => (string) ($_POST['date_livraison'] ?? ($_POST['date'] ?? ($existingPayload['date_livraison'] ?? ''))),
                            'impact' => (string) ($_POST['impact'] ?? ($existingPayload['impact'] ?? '')),
                            'frequence' => (string) ($_POST['frequence'] ?? ($existingPayload['frequence'] ?? '')),
                            'beneficiary_choice' => (string) ($_POST['draft_fd_beneficiary_choice'] ?? ($existingPayload['beneficiary_choice'] ?? 'requester')),
                            'budget_planned' => (string) ($_POST['budget_planned'] ?? ($existingPayload['budget_planned'] ?? '')),
                            'planned_start_date' => (string) ($_POST['planned_start_date'] ?? ($existingPayload['planned_start_date'] ?? $existingPayload['scheduled_due_date'] ?? $existingPayload['date_livraison'] ?? '')),
                            'planned_end_date' => (string) ($_POST['planned_end_date'] ?? ($existingPayload['planned_end_date'] ?? $existingPayload['scheduled_due_date'] ?? $existingPayload['date_livraison'] ?? '')),
                            'proposed_debourse' => is_array($existingPayload['proposed_debourse'] ?? null) ? (array) $existingPayload['proposed_debourse'] : [],
                            'feb_need_scope' => (string) ($_POST['feb_need_scope'] ?? ($existingPayload['feb_need_scope'] ?? '')),
                            'pv_reception_number' => (string) ($_POST['pv_reception_number'] ?? ($existingPayload['pv_reception_number'] ?? '')),
                            'bci_number' => (string) ($_POST['bci_number'] ?? ($existingPayload['bci_number'] ?? '')),
                            'pv_reception_document' => is_array($existingPayload['pv_reception_document'] ?? null) ? (array) $existingPayload['pv_reception_document'] : [],
                            'cashflow_need' => (string) ($_POST['cashflow_need'] ?? ($existingPayload['cashflow_need'] ?? '')),
                            'provisional_disbursement_amount' => (string) ($_POST['provisional_disbursement_amount'] ?? ($existingPayload['provisional_disbursement_amount'] ?? '')),
                            'cashflow_comment' => (string) ($_POST['cashflow_comment'] ?? ($existingPayload['cashflow_comment'] ?? '')),
                            'supporting_document_number' => (string) ($_POST['supporting_document_number'] ?? ($existingPayload['supporting_document_number'] ?? '')),
                            'invoice_number' => (string) ($_POST['invoice_number'] ?? ($existingPayload['invoice_number'] ?? '')),
                            'invoice_supplier_id' => (string) ($_POST['invoice_supplier_id'] ?? ((is_array($existingPayload['invoice_supplier'] ?? null) ? ($existingPayload['invoice_supplier']['id'] ?? '') : ''))),
                            'departement_id' => $departementPosted,
                            'justificatifs' => array_values(array_filter((array) ($existingPayload['justificatifs'] ?? []), static fn($item): bool => is_array($item))),
                            'invoice_document' => is_array($existingPayload['invoice_document'] ?? null) ? (array) $existingPayload['invoice_document'] : [],
                            'provisional_decaissement' => is_array($existingPayload['provisional_decaissement'] ?? null) ? (array) $existingPayload['provisional_decaissement'] : [],
                        ];
                        $febDraftDecaissement = is_array($febInit['provisional_decaissement'] ?? null) ? (array) $febInit['provisional_decaissement'] : [];
                        $febDraftActive = !empty($_POST['prepare_decaissement']) || !empty($febDraftDecaissement['active']);
                        $febAiContext = [
                            'demandeur' => $nom,
                            'matricule' => $matricule,
                            'service_employe' => $serviceEmploye,
                            'csrf_token' => $febAiCsrfToken,
                        ];
                        $febSuppliersForJs = [];
                        foreach ($approvedFebSuppliers as $supplier) {
                            $supplierName = trim((string) ($supplier['supplier_display_name'] ?? ''));
                            if ($supplierName === '') {
                                $supplierName = trim((string) ($supplier['nom_commercial'] ?? ''));
                            }
                            if ($supplierName === '') {
                                $supplierName = trim((string) ($supplier['raison_sociale'] ?? ''));
                            }
                            $febSuppliersForJs[] = [
                                'id' => (int) ($supplier['id_fournisseur'] ?? 0),
                                'code' => (string) ($supplier['code_fournisseur'] ?? ''),
                                'name' => $supplierName,
                                'legal_name' => (string) ($supplier['raison_sociale'] ?? ''),
                                'contact' => (string) ($supplier['contact_nom'] ?? ''),
                                'phone' => (string) ($supplier['telephone'] ?? ''),
                                'email' => (string) ($supplier['email'] ?? ''),
                                'address' => (string) ($supplier['adresse'] ?? ''),
                                'photo' => (string) ($supplier['photo_path'] ?? $supplier['logo_path'] ?? ''),
                                'categories' => (string) ($supplier['category_names'] ?? ''),
                                'blocked' => !empty($supplier['disbursement_selection_blocked']),
                                'block_reason' => (string) ($supplier['disbursement_block_reason'] ?? ''),
                                'block_fiches' => (string) ($supplier['disbursement_block_fiches'] ?? ''),
                            ];
                        }
                        ?>

                        <?php if ($febTextGenerationEnabled): ?>
                        <div class="feb-assistant-invite mb-5 rounded-2xl border border-indigo-200 bg-indigo-50 p-5">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-xs font-black uppercase tracking-[.2em] text-amber-300">Un coup de main ?</p>
                                    <h2 class="mt-2 text-xl font-bold">Besoin d’aide pour remplir votre FEB ?</h2>
                                    <p class="mt-1 text-sm text-indigo-100">Un accompagnement facultatif pour préparer votre FEB. Vos réponses restent modifiables.</p>
                                </div>
                                <button type="button" id="startFebConversation" class="shrink-0 rounded-2xl bg-amber-400 px-5 py-3 text-sm font-black text-slate-950 transition hover:bg-amber-300">
                                    <i class="fas fa-wand-magic-sparkles mr-2"></i>Me guider
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>

                        <input type="hidden" name="assistant_mode" id="febAssistantMode" value="">

                        <input type="hidden" name="ai_checks_json" id="ai_checks_json" value="<?= htmlspecialchars((string) ($_POST['ai_checks_json'] ?? '')) ?>">
                        <input type="hidden" name="feb_description_request_id" id="feb_description_request_id" value="<?= htmlspecialchars((string) ($_POST['feb_description_request_id'] ?? '')) ?>">
                        <input type="hidden" name="feb_impact_request_id" id="feb_impact_request_id" value="<?= htmlspecialchars((string) ($_POST['feb_impact_request_id'] ?? '')) ?>">
                        <textarea name="feb_description_generated" id="feb_description_generated" class="hidden"><?= htmlspecialchars((string) ($_POST['feb_description_generated'] ?? '')) ?></textarea>
                        <textarea name="feb_impact_generated" id="feb_impact_generated" class="hidden"><?= htmlspecialchars((string) ($_POST['feb_impact_generated'] ?? '')) ?></textarea>
                        <input type="hidden" id="feb_ocr_enabled" value="<?= $febAutomatedVerificationEnabled ? '1' : '0' ?>">
                        <input type="hidden" id="feb_document_verification_mode" value="<?= htmlspecialchars($febVerificationMode,ENT_QUOTES,'UTF-8') ?>">

                        <div class="feb-step-navigation rounded-3xl border px-4 py-4 <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> bg-gradient-to-r from-white to-indigo-50">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 inline-flex h-10 w-10 items-center justify-center rounded-2xl text-white <?= htmlspecialchars((string) ($typeTheme['iconBg'] ?? 'bg-indigo-600')) ?>">
                                    <i class="fas fa-clipboard-list"></i>
                                </span>
                                <div>
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Assistant FEB</div>
                                    <div class="mt-1 text-sm font-semibold text-slate-900">Suivez les étapes pour soumettre une demande complète</div>
                                </div>
                            </div>

                            <div class="mt-4 h-2 w-full rounded-full bg-white border <?= htmlspecialchars((string) ($typeTheme['softBorder'] ?? 'border-slate-200')) ?> overflow-hidden">
                                <div id="febProgress" class="h-full bg-indigo-600" style="width: 33.333%"></div>
                            </div>
                            <div class="mt-3 grid grid-cols-3 gap-2 text-xs font-semibold text-slate-600">
                                <div id="febStepChip0" class="rounded-full border border-slate-200 bg-white px-3 py-1 text-center transition"><i class="fas fa-pen" aria-hidden="true"></i><span>01 · Votre demande</span></div>
                                <div id="febStepChip1" class="rounded-full border border-slate-200 bg-white px-3 py-1 text-center transition"><i class="fas fa-list-check" aria-hidden="true"></i><span>02 · Devis et besoins</span></div>
                                <div id="febStepChip2" class="rounded-full border border-slate-200 bg-white px-3 py-1 text-center transition"><i class="fas fa-circle-check" aria-hidden="true"></i><span>03 · Vérifier et envoyer</span></div>
                            </div>
                        </div>

                        <div class="feb-step" data-step="0">
                            <section class="feb-quote-intro">
                                <span class="feb-section-eyebrow">Votre demande en quelques mots</span>
                                <label for="febQuoteTitle" class="feb-field-heading"><i class="fas fa-file-invoice" aria-hidden="true"></i> Titre du devis</label>
                                <input id="febQuoteTitle" type="text" name="feb_quote_title" value="<?= htmlspecialchars((string) ($_POST['feb_quote_title'] ?? $existingPayload['feb_quote_title'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-indigo-200 bg-white px-4 py-2" placeholder="Ex : Fourniture et pose du réseau électrique" required>
                                <p class="feb-field-help">Ce titre sera repris automatiquement dans les détails de la fiche de décaissement.</p>
                            </section>
                            <section class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Identification</div>

                                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Nom du salarié</label>
                                        <input type="text" value="<?= htmlspecialchars($nom) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-slate-50" readonly required>
                                    </div>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Matricule</label>
                                        <input type="text" value="<?= htmlspecialchars($matricule) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-slate-50" readonly required>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Service (employé)</label>
                                    <input type="text" value="<?= htmlspecialchars($serviceEmploye) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 bg-slate-50" readonly>
                                </div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700" for="departement_id">Service bénéficiaire</label>
                                    <select id="departement_id" name="departement_id" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                        <option value="">— Sélectionner —</option>
                                        <?php foreach ($allServices as $sid => $lbl): ?>
                                            <option value="<?= (int) $sid ?>" <?= ($departementPosted === (int) $sid) ? 'selected' : '' ?>><?= htmlspecialchars((string) $lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="mt-1 text-xs text-slate-500">Sélectionnez un service créé dans le module RH, pas nécessairement le vôtre.</div>
                                    <?php if (!$allServices): ?>
                                        <div class="mt-2 rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">Aucun service RH disponible. Créez d'abord les services dans RH &gt; Paramétrage.</div>
                                    <?php endif; ?>
                                </div>
                            </section>
                        </div>

                        <div class="feb-step hidden" data-step="1">
                            <div id="febDocumentZone" class="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                                <?php
                                $existingGlobalSupplierId = (int)($_POST['feb_global_supplier_id'] ?? $existingPayload['global_fournisseur_id'] ?? (($existingPayload['besoins'][0]['fournisseur']['id'] ?? 0)));
                                $existingGlobalProof = is_array($existingPayload['global_justificatif'] ?? null) ? (array)$existingPayload['global_justificatif'] : (is_array($existingPayload['besoins'][0]['justificatif_devis'] ?? null) ? (array)$existingPayload['besoins'][0]['justificatif_devis'] : []);
                                ?>
                                <div class="mt-4 grid gap-3 lg:grid-cols-2">
                                    <label class="block">
                                        <span class="text-xs font-bold uppercase tracking-[0.14em] text-indigo-800">Fournisseur global</span>
                                        <select id="febGlobalSupplier" name="feb_global_supplier_selector" required class="mt-1 w-full rounded-xl border border-indigo-200 bg-white px-4 py-2 text-sm">
                                            <option value="">Rechercher et sélectionner le fournisseur</option>
                                            <?php foreach ($approvedFebSuppliers as $supplier): $sid=(int)($supplier['id_fournisseur']??0); $sname=trim((string)($supplier['supplier_display_name']??$supplier['nom_commercial']??$supplier['raison_sociale']??'')); ?>
                                                <option value="<?= $sid ?>" <?= $existingGlobalSupplierId===$sid?'selected':'' ?> <?= !empty($supplier['disbursement_selection_blocked'])?'disabled':'' ?>><?= htmlspecialchars($sname,ENT_QUOTES,'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="hidden" id="febGlobalSupplierId" name="feb_global_supplier_id" value="<?= $existingGlobalSupplierId > 0 ? $existingGlobalSupplierId : '' ?>">
                                        <span class="mt-1 block text-xs text-indigo-700">Ce fournisseur s’applique à toutes les lignes et sera utilisé si vous choisissez « fournisseur » comme bénéficiaire.</span>
                                    </label>
                                    <label class="block">
                                        <span class="text-xs font-bold uppercase tracking-[0.14em] text-indigo-800">Justificatif</span>
                                        <input id="febQuoteDocument" type="file" name="feb_global_justificatif" accept=".pdf,.jpg,.jpeg,.png,.webp,image/*" <?= $existingGlobalProof?'':'required' ?> class="mt-1 block w-full rounded-xl border border-indigo-200 bg-white px-3 py-2 text-sm">
                                        <input id="febHumanReviewToken" type="hidden" name="feb_human_review_token" value="">
                                        <span class="mt-1 block text-xs text-indigo-700"><?= $existingGlobalProof?'Une pièce est déjà enregistrée. Sélectionnez un fichier uniquement pour la remplacer.':'Facture, devis, proforma ou autre document fournisseur autorisé.' ?></span>
                                    </label>
                                </div>
                                <div id="febFidestIaCheck" class="mt-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600" aria-live="polite">
                                    <div class="feb-document-status"><i class="fas fa-shield-halved" aria-hidden="true"></i><div><strong>Contrôle documentaire</strong><div id="febFidestIaSummary"><?= $febVerificationMode==='human'?'Le justificatif sera transmis aux validateurs humains sélectionnés.':($febVerificationMode==='allow_all'?'Acceptation automatique des justificatifs active.':($existingGlobalProof?'La pièce enregistrée sera contrôlée avant l’envoi.':'Fournisseur, total et contenu seront vérifiés automatiquement.')) ?></div></div><button type="button" id="febFidestIaExtractButton" class="hidden" title="Voir le texte OCR et les données analysées" aria-label="Voir le texte OCR et les données analysées" aria-expanded="false"><i class="fas fa-eye" aria-hidden="true"></i></button><button type="button" id="febFidestIaDetailsButton" title="Ce que FINEA vérifie" aria-label="Afficher le détail du contrôle"><i class="fas fa-circle-info" aria-hidden="true"></i></button></div>
                                    <div id="febFidestIaEvidence" class="feb-evidence-list hidden" aria-live="polite"></div>
                                    <div id="febFidestIaExtract" class="feb-inspection hidden"><div class="feb-inspection-head"><div><span>LECTURE DU DOCUMENT</span><strong>Texte OCR et données analysées</strong></div><button type="button" id="febFidestIaCopy"><i class="fas fa-copy" aria-hidden="true"></i> Tout copier</button></div><div class="feb-inspection-grid"><section><h6>Texte OCR extrait du fichier</h6><pre id="febFidestIaOcrText"></pre></section><section><h6>Données retenues pour le contrôle</h6><pre id="febFidestIaExtractText"></pre></section></div></div>
                                    <div id="febFidestIaDetails" class="mt-3 hidden border-t border-slate-100 pt-3 text-xs leading-5"><div class="font-semibold text-slate-800">Ce que nous recherchons</div><ul class="mt-1 list-disc pl-4"><li>le même fournisseur que celui sélectionné ;</li><li>un total conforme au montant des besoins ;</li><li>des produits/services, quantités et montants cohérents ;</li><li>la date et le numéro du document lorsqu’ils figurent sur la pièce.</li></ul><p class="mt-2 text-slate-500">Ce contrôle aide à détecter les incohérences et ne constitue pas une validation juridique d’authenticité.</p></div>
                                    <div id="febFidestIaLoader" class="mt-3 hidden" role="status"><div class="flex items-center gap-2 text-xs font-semibold text-indigo-700"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i><span><?= $febVerificationMode==='human'?'Validation humaine en attente':'Analyse FIDEST IA en cours' ?></span><span id="febFidestIaElapsed" class="ml-auto tabular-nums">0 s</span></div><div class="feb-ai-loader-track mt-2"><div class="feb-ai-loader-bar"></div></div><div class="mt-1 text-[11px] text-slate-500"><?= $febVerificationMode==='human'?'Cette étape se débloquera automatiquement dès la première validation.':'Lecture du fournisseur, du total et des lignes du document…' ?></div></div>
                                    <?php if($febVerificationMode==='human'):?><div id="febHumanReviewContacts" class="mt-3 rounded-xl border border-cyan-200 bg-cyan-50 p-3"><strong class="text-cyan-950">Validation humaine en temps réel</strong><p class="mt-1 text-xs text-cyan-800">Une seule validation suffit. Vous pouvez contacter :</p><div class="mt-2 grid gap-2"><?php foreach($febHumanReviewers as $reviewer):$phone=preg_replace('/[^0-9+]/','',(string)($reviewer['whatsapp']??''));?><div class="flex items-center justify-between rounded-lg bg-white px-3 py-2 text-sm"><span><?=htmlspecialchars((string)$reviewer['name'])?></span><?php if($phone):?><a class="font-bold text-emerald-700" target="_blank" rel="noopener" href="https://wa.me/<?=htmlspecialchars(ltrim($phone,'+'))?>"><i class="fab fa-whatsapp"></i> <?=htmlspecialchars($phone)?></a><?php else:?><span class="text-slate-400">WhatsApp non renseigné</span><?php endif;?></div><?php endforeach;?></div></div><?php endif;?>
                                </div>
                                <details class="feb-import-tools mt-4">
                                    <summary><i class="fas fa-file-excel" aria-hidden="true"></i> Importer les lignes d’un devis Excel <span>Facultatif</span></summary>
                                    <div class="feb-import-content">
                                        <a href="../request/feb_quote_template.php"><i class="fas fa-download" aria-hidden="true"></i> Télécharger le modèle Excel</a>
                                        <label class="block">Votre devis Excel<input id="febQuoteImportFile" type="file" accept=".xlsx,.xls"></label>
                                        <span id="febQuoteImportStatus" class="text-sm text-slate-600" aria-live="polite"></span>
                                    </div>
                                </details>
                            </div>
                            <div id="febNeedsBlock" class="flex flex-col gap-4 lg:flex-row">
                                <div class="flex-1">
                                    <div class="flex items-center justify-between">
                                        <div class="text-sm font-semibold text-slate-800">Besoins</div>
                                        <button type="button" id="febAddBesoin" class="rounded-2xl px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 <?= htmlspecialchars((string) ($typeTheme['btn'] ?? 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-300')) ?>">
                                            <i class="fas fa-plus mr-2"></i>Ajouter
                                        </button>
                                    </div>
                                    <div id="febBesoinContainer" class="mt-3 space-y-3"></div>
                                </div>
                                <aside class="lg:w-[320px] rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Résumé</div>
                                    <div class="mt-2 text-sm font-semibold text-slate-900">Besoins créés</div>
                                    <ul id="febBesoinSummary" class="mt-3 space-y-2 text-sm text-slate-700"></ul>
                                </aside>
                            </div>
                        </div>

                        <div class="feb-step hidden" data-step="2">
                            <section class="feb-confirmation-summary rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                                <h3 class="font-bold text-emerald-950">Vérifiez votre demande</h3>
                                <p class="mt-1 text-sm text-emerald-800">Le montant est calculé à partir des lignes du devis. Complétez le rattachement et la planification ci-dessous.</p>
                                <label class="mt-4 block text-sm font-semibold">Total de la FEB (FCFA)<input id="feb_montant" type="number" name="montant" value="<?= htmlspecialchars((string) ($febInit['montant'] ?? '')) ?>" class="mt-2 w-full rounded-xl border p-3" readonly required></label>
                                <input id="feb_date" type="hidden" name="date_livraison" value="<?= htmlspecialchars((string) ($febInit['date_livraison'] ?? '')) ?>">
                            </section>
                            <section class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Validation</div>

                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Fréquence du besoin</label>
                                    <select id="feb_frequence" name="frequence" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" required>
                                        <option value="">— Sélectionner —</option>
                                        <?php
                                        $freq = (string) ($febInit['frequence'] ?? '');
                                        $freqOptions = [
                                            'unique' => 'Unique',
                                            'hebdomadaire' => 'Hebdomadaire',
                                            'mensuelle' => 'Mensuelle',
                                            'trimestrielle' => 'Trimestrielle',
                                            'semestrielle' => 'Semestrielle',
                                            'annuelle' => 'Annuelle',
                                        ];
                                        ?>
                                        <?php foreach ($freqOptions as $k => $lbl): ?>
                                            <option value="<?= htmlspecialchars((string) $k) ?>" <?= ($freq === $k) ? 'selected' : '' ?>><?= htmlspecialchars((string) $lbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <?php if (false): // Ancien parcours justificatifs interne/externe retiré au profit de la logique budget/déboursé. ?>
                                <div class="mt-4">
                                    <label class="text-sm font-semibold text-slate-700">Ancien parcours documentaire</label>
                                    <?php $febNeedScope = (string) ($febInit['feb_need_scope'] ?? ''); ?>
                                    <div class="mt-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                        <label class="text-sm font-semibold text-slate-800">Nature du besoin</label>
                                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                                            <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-white bg-white px-4 py-3 text-sm shadow-sm">
                                                <input type="radio" name="feb_need_scope" value="internal" class="mt-1" <?= $febNeedScope === 'internal' ? 'checked' : '' ?> required>
                                                <span><span class="block font-semibold text-slate-900">Besoin interne</span><span class="mt-1 block text-xs text-slate-500">PV de réception obligatoire + numéro du PV.</span></span>
                                            </label>
                                            <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-white bg-white px-4 py-3 text-sm shadow-sm">
                                                <input type="radio" name="feb_need_scope" value="external" class="mt-1" <?= $febNeedScope === 'external' ? 'checked' : '' ?> required>
                                                <span><span class="block font-semibold text-slate-900">Demande externe</span><span class="mt-1 block text-xs text-slate-500">Justificatifs, avance provisoire ou facture selon le cas.</span></span>
                                            </label>
                                        </div>
                                    </div>

                                    <?php $existingPvDocument = is_array($febInit['pv_reception_document'] ?? null) ? (array) $febInit['pv_reception_document'] : []; ?>
                                    <div id="febPvBlock" class="mt-4 hidden rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4">
                                        <div class="text-sm font-semibold text-slate-900">PV de réception</div>
                                        <div class="mt-1 text-xs text-slate-500">Formats acceptés: PDF, JPG, PNG, WebP.</div>
                                        <div class="mt-3">
                                            <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Numéro du PV</label>
                                            <input id="febPvNumber" type="text" name="pv_reception_number" class="mt-1 w-full rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm" value="<?= htmlspecialchars((string) ($febInit['pv_reception_number'] ?? '')) ?>" placeholder="Ex: PV-REC-2026-001">
                                        </div>
                                        <div class="mt-3">
                                            <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Numéro BCI interne approuvé <span class="normal-case tracking-normal text-slate-400">(si disponible)</span></label>
                                            <input id="febBciNumber" type="text" name="bci_number" class="mt-1 w-full rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm" value="<?= htmlspecialchars((string) ($febInit['bci_number'] ?? '')) ?>" placeholder="Ex: BCI-2026-001">
                                        </div>
                                        <?php if ($existingPvDocument): ?>
                                            <?php $pvUrl = (string) ($existingPvDocument['url'] ?? ''); ?>
                                            <div class="mt-3 rounded-xl border border-emerald-200 bg-white px-3 py-3">
                                                <div class="flex flex-wrap items-center justify-between gap-3">
                                                    <div>
                                                        <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($existingPvDocument['name'] ?? 'PV de réception joint')) ?></div>
                                                        <div class="text-xs text-slate-500"><?= htmlspecialchars((string) round(((int) ($existingPvDocument['size'] ?? 0)) / 1024)) ?> Ko</div>
                                                    </div>
                                                    <div class="flex flex-wrap items-center gap-3">
                                                        <?php if (strpos($pvUrl, '/uploads/') === 0): ?>
                                                            <a href="<?= htmlspecialchars($pvUrl) ?>" target="_blank" rel="noopener" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">Ouvrir</a>
                                                        <?php endif; ?>
                                                        <label class="inline-flex items-center gap-2 text-xs font-semibold text-rose-700"><input type="checkbox" name="remove_existing_pv_reception_document" value="1" class="rounded border-slate-300"> Remplacer / retirer</label>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        <div class="mt-3"><input id="febPvFile" type="file" name="pv_reception_document" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" class="block w-full rounded-2xl border border-emerald-200 bg-white px-4 py-3 text-sm text-slate-700 file:mr-4 file:rounded-xl file:border-0 file:bg-emerald-100 file:px-4 file:py-2 file:font-semibold file:text-emerald-800 hover:file:bg-emerald-200"></div>
                                    </div>

                                    <div id="febExternalBlock" class="mt-4 hidden">
                                        <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4">
                                            <label class="text-sm font-semibold text-slate-800">Mode de traitement financier</label>
                                            <?php $cashflowNeed = (string) ($febInit['cashflow_need'] ?? ''); ?>
                                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                                <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-white bg-white px-4 py-3 text-sm shadow-sm">
                                                    <input type="radio" name="cashflow_need" value="provisional_disbursement" class="mt-1" <?= $cashflowNeed === 'provisional_disbursement' ? 'checked' : '' ?>>
                                                    <span>
                                                        <span class="block font-semibold text-slate-900">J'aurai besoin d'un decaissement provisoire</span>
                                                        <span class="mt-1 block text-xs text-slate-500">La premiere tranche sera plafonnee au montant indique ci-dessous.</span>
                                                    </span>
                                                </label>
                                                <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-white bg-white px-4 py-3 text-sm shadow-sm">
                                                    <input type="radio" name="cashflow_need" value="internal_purchase_order" class="mt-1" <?= $cashflowNeed === 'internal_purchase_order' ? 'checked' : '' ?>>
                                                    <span>
                                                        <span class="block font-semibold text-slate-900">Ce sera un decaissement unique sur facture</span>
                                                        <span class="mt-1 block text-xs text-slate-500">La facture sera obligatoire avant la certification conforme.</span>
                                                    </span>
                                                </label>
                                            </div>
                                            <div class="mt-3">
                                                <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Montant demande pour l'avance provisoire</label>
                                                <input type="number" min="0" step="0.01" name="provisional_disbursement_amount" class="mt-1 w-full rounded-2xl border border-amber-200 bg-white px-4 py-2 text-sm" value="<?= htmlspecialchars((string) ($febInit['provisional_disbursement_amount'] ?? '')) ?>" placeholder="Ex: 200000">
                                            </div>
                                            <div class="mt-3">
                                                <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Precision comptable facultative</label>
                                                <textarea name="cashflow_comment" rows="2" class="mt-1 w-full rounded-2xl border border-amber-200 bg-white px-4 py-2 text-sm" placeholder="Ex: avance fournisseur, paiement comptant, paiement apres livraison..."><?= htmlspecialchars((string) ($febInit['cashflow_comment'] ?? '')) ?></textarea>
                                            </div>
                                        </div>

                                        <?php $existingFebJustifs = array_values(array_filter((array) ($febInit['justificatifs'] ?? []), static fn($item): bool => is_array($item))); ?>
                                        <?php if ($existingFebJustifs): ?>
                                            <div class="mt-3 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Justificatifs deja joints</div>
                                                <ul class="mt-3 space-y-2">
                                                    <?php foreach ($existingFebJustifs as $existingIndex => $doc): ?>
                                                        <?php $docUrl = (string) ($doc['url'] ?? ''); ?>
                                                        <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3">
                                                            <div>
                                                                <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($doc['name'] ?? ('Justificatif ' . ($existingIndex + 1)))) ?></div>
                                                                <div class="text-xs text-slate-500"><?= htmlspecialchars((string) round(((int) ($doc['size'] ?? 0)) / 1024)) ?> Ko</div>
                                                            </div>
                                                            <div class="flex flex-wrap items-center gap-3">
                                                                <?php if (strpos($docUrl, '/uploads/') === 0): ?>
                                                                    <a href="<?= htmlspecialchars($docUrl) ?>" target="_blank" rel="noopener" class="text-sm font-semibold text-indigo-700 hover:text-indigo-800">Ouvrir</a>
                                                                <?php endif; ?>
                                                                <label class="inline-flex items-center gap-2 text-xs font-semibold text-rose-700">
                                                                    <input type="checkbox" name="remove_existing_justificatifs[]" value="<?= (int) $existingIndex ?>" class="rounded border-slate-300">
                                                                    Retirer
                                                                </label>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php $existingInvoiceDocument = is_array($febInit['invoice_document'] ?? null) ? (array) $febInit['invoice_document'] : []; ?>
                                        <div id="febInvoiceBlock" class="mt-4 hidden rounded-2xl border border-sky-200 bg-sky-50 px-4 py-4">
                                            <div class="text-sm font-semibold text-slate-900">Facture pour decaissement unique</div>
                                            <div class="mt-1 text-xs text-slate-500">Formats acceptes: PDF, JPG, PNG, WebP. Ce document suivra la FEB et la fiche de decaissement.</div>
                                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                                <div>
                                                    <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Numéro de facture</label>
                                                    <input id="febInvoiceNumber" type="text" name="invoice_number" class="mt-1 w-full rounded-2xl border border-sky-200 bg-white px-4 py-2 text-sm" value="<?= htmlspecialchars((string) ($febInit['invoice_number'] ?? '')) ?>" placeholder="Ex: FAC-2026-001">
                                                </div>
                                                <div>
                                                    <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Fournisseur de la facture</label>
                                                    <select id="febInvoiceSupplierId" name="invoice_supplier_id" class="mt-1 w-full rounded-2xl border border-sky-200 bg-white px-4 py-2 text-sm">
                                                        <option value="">-- Sélectionner le fournisseur --</option>
                                                        <?php foreach ($approvedFebSuppliers as $supplier): ?>
                                                            <?php
                                                            $sid = (int) ($supplier['id_fournisseur'] ?? 0);
                                                            $sname = trim((string) ($supplier['supplier_display_name'] ?? ''));
                                                            if ($sname === '') {
                                                                $sname = trim((string) ($supplier['nom_commercial'] ?? ''));
                                                            }
                                                            if ($sname === '') {
                                                                $sname = trim((string) ($supplier['raison_sociale'] ?? ''));
                                                            }
                                                            ?>
                                                            <option value="<?= $sid ?>" <?= ((string) ($febInit['invoice_supplier_id'] ?? '') === (string) $sid) ? 'selected' : '' ?>><?= htmlspecialchars($sname) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div class="mt-1 text-xs text-slate-500">Contrôle anti-doublon: même fournisseur + même numéro de facture interdit.</div>
                                                </div>
                                            </div>
                                            <?php if ($existingInvoiceDocument): ?>
                                                <?php $invoiceUrl = (string) ($existingInvoiceDocument['url'] ?? ''); ?>
                                                <div class="mt-3 rounded-xl border border-sky-200 bg-white px-3 py-3">
                                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                                        <div>
                                                            <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($existingInvoiceDocument['name'] ?? 'Facture jointe')) ?></div>
                                                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string) round(((int) ($existingInvoiceDocument['size'] ?? 0)) / 1024)) ?> Ko</div>
                                                        </div>
                                                        <div class="flex flex-wrap items-center gap-3">
                                                            <?php if (strpos($invoiceUrl, '/uploads/') === 0): ?>
                                                                <a href="<?= htmlspecialchars($invoiceUrl) ?>" target="_blank" rel="noopener" class="text-sm font-semibold text-indigo-700 hover:text-indigo-800">Ouvrir</a>
                                                            <?php endif; ?>
                                                            <label class="inline-flex items-center gap-2 text-xs font-semibold text-rose-700">
                                                                <input type="checkbox" name="remove_existing_invoice_document" value="1" class="rounded border-slate-300">
                                                                Remplacer / retirer
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <div class="mt-3">
                                                <input id="febInvoiceFile" type="file" name="invoice_document" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp" class="block w-full rounded-2xl border border-sky-200 bg-white px-4 py-3 text-sm text-slate-700 file:mr-4 file:rounded-xl file:border-0 file:bg-sky-100 file:px-4 file:py-2 file:font-semibold file:text-sky-800 hover:file:bg-sky-200">
                                            </div>
                                        </div>

                                        <div id="febAiSummary" class="mt-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                                            <div class="mb-2 text-xs font-bold uppercase tracking-[0.12em] text-slate-600">
                                                <i class="fas fa-shield-alt mr-2 text-indigo-600"></i>Contrôle IA des justificatifs
                                            </div>
                                            <div class="flex flex-wrap gap-4">
                                                <div><span class="font-semibold">Acceptées:</span> <span id="febAiAcc">0</span></div>
                                                <div><span class="font-semibold">Refusées:</span> <span id="febAiRef">0</span></div>
                                                <div><span class="font-semibold">En analyse:</span> <span id="febAiPend">0</span></div>
                                            </div>
                                            <div class="mt-2 text-xs text-slate-500">Justificatifs acceptés: photo de document (facture/reçu/devis) ou capture avec prix visible.</div>
                                        </div>

                                        <div id="febPreview" class="mt-3 space-y-3"></div>
                                    </div>

                                    <div id="febSupportingNumberBlock" class="mb-3 rounded-2xl border border-indigo-200 bg-white px-4 py-4">
                                        <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Numéro du justificatif externe</label>
                                        <input id="febSupportingDocumentNumber" type="text" name="supporting_document_number" class="mt-1 w-full rounded-2xl border border-indigo-200 bg-white px-4 py-2 text-sm" value="<?= htmlspecialchars((string) ($febInit['supporting_document_number'] ?? '')) ?>" placeholder="Ex: RECU-2026-001 / DEV-2026-001">
                                        <div class="mt-1 text-xs text-slate-500">Ce numéro permettra de retrouver plus tard le reçu, devis, capture ou pièce justificative liée à cette FEB.</div>
                                    </div>

                                    <div id="febDropzone" class="mt-1 rounded-2xl border border-dashed border-indigo-200 bg-indigo-50 px-4 py-6 text-center text-sm text-slate-700 cursor-pointer transition hover:bg-white hover:border-indigo-300">
                                        <div class="font-semibold text-slate-900"><i class="fas fa-cloud-upload-alt mr-2"></i>Glisser-déposer ou cliquer</div>
                                        <div class="mt-1 text-xs text-slate-500">Images uniquement (JPG/PNG/WebP) • max 12 Mo / fichier</div>
                                        <input id="febFiles" type="file" name="justificatifs[]" multiple accept="image/*" class="hidden">
                                    </div>

                                </div>
                            </section>
                            <?php endif; ?>

                            <?php
                            $urgentChantierIdForForm = $isUrgentFebRegularization ? (int)($urgentRegularization['chantier_id'] ?? 0) : 0;
                            $urgentAmountForForm = (float)($urgentRegularization['amount'] ?? 0);
                            $urgentDetailsForForm = trim((string)($urgentRegularization['details'] ?? ''));
                            $draftChantierPosted = $urgentChantierIdForForm > 0
                                ? (string) $urgentChantierIdForForm
                                : (string) ($_POST['draft_fd_chantier_id'] ?? ($febDraftDecaissement['chantier_id'] ?? ''));
                            $draftLigneDevisPosted = (string) ($_POST['draft_fd_ligne_devis_id'] ?? ($febDraftDecaissement['ligne_devis_id'] ?? ''));
                            $draftDeboursePosted = (string) ($_POST['draft_fd_debourse_id'] ?? ($febDraftDecaissement['debourse_id'] ?? ''));
                            $draftLignePosted = (string) ($_POST['draft_fd_ligne_debourse_id'] ?? ($febDraftDecaissement['ligne_debourse_id'] ?? ''));
                            $draftFournisseurPosted = (string) ($_POST['draft_fd_fournisseur_id'] ?? ($febDraftDecaissement['fournisseur_id'] ?? ''));
                            $draftBeneficiaryChoicePosted = strtolower(trim((string) ($_POST['draft_fd_beneficiary_choice'] ?? ($febDraftDecaissement['beneficiary_choice'] ?? $febInit['beneficiary_choice'] ?? 'requester'))));
                            if (!in_array($draftBeneficiaryChoicePosted, ['requester', 'supplier'], true)) $draftBeneficiaryChoicePosted = 'requester';
                            $draftMontantPosted = (string) ($_POST['draft_fd_montant_fiche'] ?? ($febDraftDecaissement['montant_fiche'] ?? ($febInit['provisional_disbursement_amount'] ?? $febInit['montant'] ?? ($urgentAmountForForm ?: ''))));
                            $draftModePosted = (string) ($_POST['draft_fd_mode_paiement'] ?? ($febDraftDecaissement['mode_paiement'] ?? ''));
                            $draftDetailsPosted = (string) ($_POST['draft_fd_details_demande'] ?? ($febDraftDecaissement['details_demande'] ?? ($existingPayload['feb_quote_title'] ?? $febInit['description'] ?? $urgentDetailsForForm)));
                            $budgetPlannedPosted = 'yes';
                            $plannedStartPosted = (string) ($_POST['planned_start_date'] ?? ($febDraftDecaissement['planned_start_date'] ?? $febInit['planned_start_date'] ?? ''));
                            $plannedEndPosted = (string) ($_POST['planned_end_date'] ?? ($febDraftDecaissement['planned_end_date'] ?? $febInit['planned_end_date'] ?? ''));
                            $existingProposal = is_array($febInit['proposed_debourse'] ?? null) ? (array) $febInit['proposed_debourse'] : [];
                            $proposedLigneDevisPosted = (string) ($_POST['proposed_ligne_devis_id'] ?? ($existingProposal['ligne_devis_id'] ?? ''));
                            $proposedLigneDevisLabelPosted = (string) ($_POST['proposed_ligne_devis_label'] ?? ($existingProposal['ligne_devis_proposal'] ?? ''));
                            $proposedDebourseTitlePosted = (string) ($_POST['proposed_debourse_title'] ?? ($existingProposal['title'] ?? ''));
                            $proposalLinesForJs = (array) ($existingProposal['lines'] ?? []);
                            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proposed_line_designation'])) {
                                $proposalLinesForJs = [];
                                foreach ((array) $_POST['proposed_line_designation'] as $proposalIndex => $proposalDesignation) {
                                    $proposalLinesForJs[] = [
                                        'designation' => (string) $proposalDesignation,
                                        'categorie' => (string) ($_POST['proposed_line_category'][$proposalIndex] ?? ''),
                                        'montant' => (string) ($_POST['proposed_line_amount'][$proposalIndex] ?? ''),
                                    ];
                                }
                            }
                            ?>
                            <section class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4">
                                <div class="text-sm font-semibold text-slate-900">Affectation chantier et budget</div>
                                <div class="mt-1 text-xs text-slate-600">Chaque FEB est obligatoirement rattachée à un poste de déboursé existant. La fiche de décaissement sera préparée avec cette affectation à la validation.</div>
                                <div class="mt-3 inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-800"><i class="fas fa-circle-check"></i> Prévue au déboursé</div>
                                <input type="hidden" name="budget_planned" value="yes">
                                <input type="hidden" name="prepare_decaissement" id="prepareDecaissement" value="1">

                                <div class="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-4">
                                    <div class="text-sm font-semibold text-indigo-950">Qui sera le bénéficiaire des fiches de décaissement liées à cette FEB ?</div>
                                    <div class="mt-3 flex flex-wrap gap-5">
                                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-800">
                                            <input type="radio" name="draft_fd_beneficiary_choice" value="requester" <?= $draftBeneficiaryChoicePosted !== 'supplier' ? 'checked' : '' ?>>
                                            Moi-même (le demandeur)
                                        </label>
                                        <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-800">
                                            <input type="radio" name="draft_fd_beneficiary_choice" value="supplier" <?= $draftBeneficiaryChoicePosted === 'supplier' ? 'checked' : '' ?>>
                                            Le fournisseur choisi
                                        </label>
                                    </div>
                                    <div class="mt-2 text-xs text-indigo-800">Sans choix explicite, le demandeur reste bénéficiaire par défaut. Pour « Fournisseur », son nom, son téléphone et sa photo enregistrés seront repris sur chaque fiche.</div>
                                    <div id="febBeneficiaryPreview" data-requester-name="<?=htmlspecialchars((string)($personnelProfile['full_name']??employeeCurrentUserName()),ENT_QUOTES,'UTF-8')?>" class="mt-4 rounded-xl border border-indigo-200 bg-white p-3 text-sm text-slate-700"></div>
                                    <div id="febBeneficiarySupplierBlock" class="mt-4 <?= $draftBeneficiaryChoicePosted === 'supplier' ? 'block' : 'hidden' ?> rounded-xl border border-indigo-200 bg-white px-3 py-2 text-xs font-semibold text-indigo-800">
                                        <i class="fas fa-link mr-2"></i>Le fournisseur global sélectionné à l’étape précédente sera automatiquement le bénéficiaire de la fiche de décaissement.
                                    </div>
                                    <div id="draftSupplierCompletion" class="mt-4"></div>
                                </div>

                                <label class="mt-4 block">
                                    <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Chantier concerné</span>
                                    <select id="draftFdChantier" name="draft_fd_chantier_id" required class="mt-1 w-full rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm">
                                        <option value="">Sélectionner</option>
                                        <?php foreach ($febChantierOptions as $chantier): ?>
                                            <?php $chantierId = (int) ($chantier['id'] ?? 0); $chantierLabel = trim((string) (($chantier['code'] ?? '') . ' - ' . ($chantier['titre'] ?? ''))); ?>
                                            <?php $blockingUrgency = $urgentBlockedChantierMap[$chantierId] ?? null; $isOwnUrgentRegularization = $isUrgentFebRegularization && $chantierId === $urgentChantierIdForForm; ?>
                                            <option value="<?= $chantierId ?>" data-devis-id="<?= (int) ($chantier['devis_id'] ?? 0) ?>" <?= $draftChantierPosted === (string) $chantierId ? 'selected' : '' ?> <?= !$isUrgentFebRegularization && $blockingUrgency ? 'disabled' : '' ?>><?= htmlspecialchars($chantierLabel . (!$isUrgentFebRegularization && $blockingUrgency ? ' — BLOQUÉ : urgence décaissée non régularisée' : ''), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($urgentBlockedChantierMap && !$isUrgentFebRegularization): ?><span class="mt-2 block text-xs font-semibold text-rose-700"><i class="fas fa-lock mr-1"></i>Les chantiers grisés sont bloqués par un décaissement urgent non régularisé. Seule la FEB de régularisation correspondante peut y être créée.</span><?php endif; ?>
                                </label>

                                <!-- Les FEB non prévues au déboursé ne sont plus proposées dans ce parcours. -->
                                <div id="employeeProposedDeboursePanel" class="hidden">
                                    <div class="font-semibold text-amber-950">Proposition de nouveau déboursé pour analyse</div>
                                    <div class="mt-1 text-xs text-amber-800">Cette proposition sera jointe à la FEB. Elle ne modifiera le budget qu'après analyse et validation.</div>
                                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                                        <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-600">Ligne de devis existante</span><select id="proposedLigneDevis" name="proposed_ligne_devis_id" data-selected="<?= htmlspecialchars($proposedLigneDevisPosted, ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-xl border border-amber-200 bg-white px-3 py-2"><option value="">Choisir ou proposer ci-contre</option></select></label>
                                        <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-600">Nouvelle ligne de devis proposée</span><input type="text" name="proposed_ligne_devis_label" value="<?= htmlspecialchars($proposedLigneDevisLabelPosted, ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-xl border border-amber-200 bg-white px-3 py-2" placeholder="Ex : Travaux complémentaires"></label>
                                    </div>
                                    <label class="mt-3 block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-600">Titre du déboursé proposé</span><input type="text" name="proposed_debourse_title" value="<?= htmlspecialchars($proposedDebourseTitlePosted, ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-xl border border-amber-200 bg-white px-3 py-2" placeholder="Ex : Fournitures et pose complémentaires"></label>
                                    <div class="mt-4 flex items-center justify-between"><div class="text-sm font-semibold text-slate-800">Lignes de déboursé et planning</div><button id="addProposedDebourseLine" type="button" class="rounded-xl bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-700"><i class="fas fa-plus mr-1"></i>Ajouter une ligne</button></div>
                                    <div id="proposedDebourseLines" class="mt-3 space-y-3"></div>
                                    <div class="mt-3 text-right text-sm font-bold text-amber-950">Total proposé : <span id="proposedDebourseTotal">0 FCFA</span></div>
                                </div>

                                <div id="employeeDecaissementDraftPanel" class="mt-4 grid gap-4">
                                    <div class="rounded-2xl border border-white bg-white/80 px-4 py-3 text-sm text-slate-700">
                                        Bénéficiaire proposé: <span class="font-semibold text-slate-900"><?= htmlspecialchars($nom, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>

                                    <div class="grid gap-3 lg:grid-cols-2">
                                        <label class="block">
                                            <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Ligne de devis</span>
                                            <select id="draftFdLigneDevis" name="draft_fd_ligne_devis_id" data-selected="<?= htmlspecialchars($draftLigneDevisPosted, ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm"><option value="">Choisir un chantier</option></select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Déboursé financier</span>
                                            <select id="draftFdDebourse" name="draft_fd_debourse_id" data-selected="<?= htmlspecialchars($draftDeboursePosted, ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm">
                                                <option value="">Choisir un chantier</option>
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Poste financier / ligne de déboursé</span>
                                            <select id="draftFdLigne" name="draft_fd_ligne_debourse_id" data-selected="<?= htmlspecialchars($draftLignePosted, ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm">
                                                <option value="">Choisir un déboursé</option>
                                            </select>
                                            <span class="mt-1 block text-xs text-slate-500">Vous pouvez rechercher directement un poste comme « Licences et Abonnement » ; son déboursé parent sera sélectionné automatiquement.</span>
                                        </label>
                                    </div>

                                    <div id="draftFdBudgetIndicator" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">Sélectionnez une ligne de déboursé et saisissez le montant pour contrôler le budget.</div>

                                    <div class="grid gap-3 lg:grid-cols-3">
                                        <label class="block">
                                            <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Montant fiche</span>
                                            <input id="draftFdMontant" type="number" min="0" step="0.01" name="draft_fd_montant_fiche" value="<?= htmlspecialchars($draftMontantPosted, ENT_QUOTES, 'UTF-8') ?>" readonly class="mt-1 w-full rounded-2xl border border-emerald-200 bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-700"><span class="mt-1 block text-xs text-slate-500">Repris automatiquement du montant total du devis renseigné à l’étape précédente.</span>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Mode paiement</span>
                                            <select id="draftFdModePaiement" name="draft_fd_mode_paiement" class="feb-payment-native">
                                                <?php foreach (['' => 'Sélectionner', 'Cash' => 'Cash', 'Espèces' => 'Espèces', 'Orange Money' => 'Orange Money', 'MTN Money' => 'MTN Money', 'Moov Money' => 'Moov Money', 'Wave' => 'Wave', 'Djamo' => 'Djamo', 'Chèque' => 'Chèque', 'Virement bancaire' => 'Virement bancaire', 'Virement' => 'Virement', 'Carte bancaire' => 'Carte bancaire', 'Mobile Money' => 'Mobile Money'] as $value => $label): ?>
                                                    <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $draftModePosted === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </div>

                                    <label class="block">
                                        <span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Détails fiche</span>
                                        <input id="febDraftDetails" type="text" name="draft_fd_details_demande" value="<?= htmlspecialchars($draftDetailsPosted, ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm">
                                    </label>
                                </div>

                                <div class="mt-4 rounded-2xl border border-sky-200 bg-sky-50 p-4">
                                    <div class="text-sm font-semibold text-sky-950">Planning automatique de la FEB</div>
                                    <div class="mt-1 text-xs text-sky-800">Ce planning apparaît en dernier car il dépend de la ligne de déboursé sélectionnée. La date de livraison reprend automatiquement le début prévu.</div>
                                    <div class="mt-3 grid gap-3 lg:grid-cols-2">
                                        <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-600">Début prévu</span><input id="febPlannedStart" type="date" name="planned_start_date" value="<?= htmlspecialchars($plannedStartPosted, ENT_QUOTES, 'UTF-8') ?>" required class="mt-1 w-full rounded-2xl border border-sky-200 bg-slate-100 px-4 py-2 text-sm text-slate-600"></label>
                                        <label class="block"><span class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-600">Fin prévue</span><input id="febPlannedEnd" type="date" name="planned_end_date" value="<?= htmlspecialchars($plannedEndPosted, ENT_QUOTES, 'UTF-8') ?>" required class="mt-1 w-full rounded-2xl border border-sky-200 bg-slate-100 px-4 py-2 text-sm text-slate-600"></label>
                                    </div>
                                    <div id="febScheduleStatus" class="mt-3 rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm text-sky-900">Sélectionnez la ligne de déboursé pour récupérer automatiquement son planning.</div>
                                </div>
                            </section>
                        </div>

                        <div class="feb-step hidden" data-step="3" id="febReviewStep">
                            <header class="feb-review-heading">
                                <h2>Tout est prêt</h2>
                                <p>Vérifiez votre demande avant de l’envoyer.</p>
                            </header>
                            <div class="feb-review-list">
                                <section><div><span>Demande</span><strong id="febReviewRequest">—</strong><small id="febReviewDepartment">—</small></div><button type="button" data-review-edit="request">Modifier</button></section>
                                <section><div><span>Besoins</span><strong id="febReviewNeeds">—</strong><small id="febReviewNeedsDetail">—</small></div><button type="button" data-review-edit="needs">Modifier</button></section>
                                <section><div><span>Fournisseur</span><strong id="febReviewSupplier">—</strong></div><button type="button" data-review-edit="supplier">Modifier</button></section>
                                <section><div><span>Justificatif</span><strong id="febReviewDocument">—</strong><small id="febReviewAi">Contrôle documentaire prêt</small></div><button type="button" data-review-edit="document">Modifier</button></section>
                                <section><div><span>Décaissement</span><strong id="febReviewAmount">—</strong><small id="febReviewPayment">—</small></div><button type="button" data-review-edit="disbursement">Modifier</button></section>
                            </div>
                        </div>

                        <div class="feb-form-actions pt-2 flex flex-wrap items-center justify-between gap-2">
                            <a href="actes_legaux_employe.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50"><i class="fas fa-times mr-2"></i>Annuler</a>
                            <div class="flex items-center gap-2">
                                <button type="button" id="febPrev" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50"><i class="fas fa-arrow-left mr-2"></i>Précédent</button>
                                <button type="button" id="febNext" class="rounded-2xl px-4 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 <?= htmlspecialchars((string) ($typeTheme['btn'] ?? 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-300')) ?>">Suivant<i class="fas fa-arrow-right ml-2"></i></button>
                                <button type="submit" id="febSubmit" class="rounded-2xl px-5 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 <?= htmlspecialchars((string) ($typeTheme['btn'] ?? 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-300')) ?>" disabled aria-disabled="true"><i class="fas fa-paper-plane mr-2"></i>Soumettre</button>
                            </div>
                        </div>

                        <div id="febAiModal" class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/70 px-4">
                            <div class="w-full max-w-3xl rounded-[28px] bg-white p-5 shadow-sm">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-sm font-semibold text-slate-900">Détails contrôle OCR</div>
                                    <button type="button" id="febAiModalClose" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">Fermer</button>
                                </div>
                                <div id="febAiModalBody" class="mt-4 text-sm text-slate-700"></div>
                            </div>
                        </div>

                        <script>
                            window.__FEB_INIT__ = <?= json_encode($febInit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
                            window.__FEB_SUPPLIERS__ = <?= json_encode($febSuppliersForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
                            window.__FEB_AI_CONTEXT__ = <?= json_encode($febAiContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
                            window.__FEB_DRAFT_DECAISSEMENT_DATA__ = <?= json_encode([
                                'debourses' => array_map(static fn(array $d): array => [
                                    'id' => (int) ($d['id'] ?? 0),
                                    'devis_id' => (int) ($d['devis_id'] ?? 0),
                                    'chantier_id' => (int) ($d['chantier_id'] ?? 0),
                                    'ligne_devis_id' => (int) ($d['ligne_devis_id'] ?? 0),
                                    'label' => (string) ($d['label'] ?? ''),
                                    'montant' => (float) ($d['montant_debourse'] ?? 0),
                                ], $febDebourseOptions),
                                'lignes_devis' => array_map(static fn(array $l): array => [
                                    'id' => (int) ($l['id'] ?? 0),
                                    'devis_id' => (int) ($l['devis_id'] ?? 0),
                                    'chantier_id' => (int) ($l['chantier_id'] ?? 0),
                                    'designation' => (string) ($l['designation'] ?? ''),
                                    'montant' => (float) ($l['total'] ?? 0),
                                ], $febLigneDevisOptions),
                                'lignes' => array_map(static fn(array $l): array => [
                                    'id' => (int) ($l['id'] ?? 0),
                                    'debourse_id' => (int) ($l['debourse_id'] ?? 0),
                                    'devis_id' => (int) ($l['devis_id'] ?? 0),
                                    'ligne_devis_id' => (int) ($l['ligne_devis_id'] ?? 0),
                                    'debourse_label' => (string) ($l['debourse_label'] ?? ''),
                                    'designation' => (string) ($l['designation'] ?? ''),
                                    'categorie' => (string) ($l['categorie'] ?? ''),
                                    'montant' => (float) ($l['montant'] ?? 0),
                                    'date_debut' => (string) ($l['date_debut'] ?? ''),
                                    'date_fin' => (string) ($l['date_fin'] ?? ''),
                                ], $febLigneOptions),
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
                            window.__FEB_PROPOSED_DEBOURSE_LINES__ = <?= json_encode($proposalLinesForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
                            window.__FEB_ASSISTANT_CONFIG__ = <?= json_encode([
                                'user_id' => $userId,
                                'success_id' => max(0, (int) ($_GET['assistant_success'] ?? 0)),
                                'auto_open' => !empty($_POST['assistant_mode']) && !empty($errors),
                                'server_errors' => !empty($_POST['assistant_mode']) ? array_values(array_map('strval', $errors)) : [],
                                'csv_template_url' => '../request/feb_quote_template_csv.php',
                                'csv_import_url' => '../request/import_feb_quote_csv.php',
                                'proof_upload_url' => '../request/upload_feb_line_proof.php',
                                'proof_upload_csrf' => $febProofUploadCsrfToken,
                                'pdf_url' => '../request/export_feb_submission_pdf.php?id=',
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}' ?>;
                        </script>
                    <?php endif; ?>

                    <?php if ($type !== 'feb'): ?>
                        <div class="pt-2 flex items-center justify-end gap-2">
                            <a href="actes_legaux_employe.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50"><i class="fas fa-times mr-2"></i>Annuler</a>
                            <button type="submit" <?= $leaveRequestBlocked ? 'disabled' : '' ?> class="rounded-2xl px-5 py-2 text-sm font-semibold text-white focus:outline-none focus:ring-2 <?= htmlspecialchars((string) ($typeTheme['btn'] ?? 'bg-indigo-600 hover:bg-indigo-700 focus:ring-indigo-300')) ?> <?= $leaveRequestBlocked ? 'cursor-not-allowed opacity-50' : '' ?>"><i class="fas <?= $isEditMode ? 'fa-pen-to-square' : 'fa-paper-plane' ?> mr-2"></i><?= $isEditMode ? 'Enregistrer et renvoyer' : 'Soumettre' ?></button>
                        </div>
                    <?php endif; ?>
                </form>

                <?php if ($type === 'permission_absence'): ?>
                    <script>
                        (function() {
                            function updateRetour() {
                                const departRaw = document.getElementById('date_depart')?.value || '';
                                const joursRaw = document.getElementById('nombre_jours')?.value || '';
                                const retourEl = document.getElementById('date_retour');
                                if (!retourEl) return;

                                const dateDepart = new Date(departRaw);
                                const nombreJours = parseInt(joursRaw, 10);

                                if (!isNaN(dateDepart.getTime()) && !isNaN(nombreJours)) {
                                    dateDepart.setDate(dateDepart.getDate() + nombreJours);
                                    const options = {
                                        year: 'numeric',
                                        month: '2-digit',
                                        day: '2-digit'
                                    };
                                    const fr = dateDepart.toLocaleDateString('fr-FR', options);
                                    retourEl.value = fr.split('/').reverse().join('-');
                                }
                            }

                            document.getElementById('date_depart')?.addEventListener('change', updateRetour);
                            document.getElementById('nombre_jours')?.addEventListener('change', updateRetour);
                            updateRetour();
                        })();
                    </script>
                <?php elseif ($type === 'pret'): ?>
                    <script>
                        (function() {
                            function pad2(n) {
                                return String(n).padStart(2, '0');
                            }

                            function formatDateInput(d) {
                                return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
                            }

                            function calculerDateDebutRecouvrement() {
                                let today = new Date();
                                let moisSuivant = today.getMonth() + 1;
                                let annee = today.getFullYear();
                                if (moisSuivant > 11) {
                                    moisSuivant = 0;
                                    annee++;
                                }
                                return new Date(annee, moisSuivant, 5);
                            }

                            function calculerMensualites() {
                                const montantDemande = parseFloat(document.getElementById('montant_demande')?.value || '');
                                const montantRecouvrement = parseFloat(document.getElementById('montant_recouvrement')?.value || '');
                                if (montantDemande && montantRecouvrement && montantRecouvrement > 0) {
                                    return Math.ceil(montantDemande / montantRecouvrement);
                                }
                                return 0;
                            }

                            function calculerDateFinRecouvrement(nombreMensualites) {
                                const dateDebut = calculerDateDebutRecouvrement();
                                const dateFin = new Date(dateDebut);
                                dateFin.setMonth(dateDebut.getMonth() + nombreMensualites);
                                return dateFin;
                            }

                            function updateRecouvrement() {
                                const nb = calculerMensualites();
                                const nbEl = document.getElementById('nombre_mensualites');
                                if (nbEl) nbEl.value = nb ? String(nb) : '';

                                const debut = calculerDateDebutRecouvrement();
                                const debutEl = document.getElementById('date_debut');
                                if (debutEl) debutEl.value = formatDateInput(debut);

                                const finEl = document.getElementById('date_fin');
                                if (!finEl) return;

                                if (nb > 0) {
                                    finEl.value = formatDateInput(calculerDateFinRecouvrement(nb));
                                } else {
                                    finEl.value = '';
                                }
                            }

                            document.getElementById('montant_demande')?.addEventListener('input', updateRecouvrement);
                            document.getElementById('montant_recouvrement')?.addEventListener('input', updateRecouvrement);
                            updateRecouvrement();
                        })();
                    </script>
                <?php elseif ($type === 'feb'): ?>
                    <script>window.FEB_BRAND_LOGO_URL = <?= json_encode(\CompanyBranding::logoUrl('../', $pdo), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
                    <script>window.FEB_PREVIEW_CSRF = <?= json_encode((string)$_SESSION['feb_preview_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
                    <link rel="stylesheet" href="feb/css/disbursement_flow.css?v=<?= htmlspecialchars($febAssetVersion, ENT_QUOTES, 'UTF-8') ?>">
                    <script src="feb/js/disbursement_flow.js?v=<?= htmlspecialchars($febAssetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
                    <script>
                        (function() {
                            const init = (window.__FEB_INIT__ || {});
                            const febServerErrors = <?= json_encode(array_values(array_map('strval', $errors)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]' ?>;
                            const submittedViaAssistant = <?= !empty($_POST['assistant_mode']) ? 'true' : 'false' ?>;
                            const steps = Array.from(document.querySelectorAll('.feb-step'));
                            const progress = document.getElementById('febProgress');
                            const chips = steps.map((_,i) => document.getElementById('febStepChip'+i));

                            const prevBtn = document.getElementById('febPrev');
                            const nextBtn = document.getElementById('febNext');
                            const submitBtn = document.getElementById('febSubmit');
                            const draftDecaissementData = window.__FEB_DRAFT_DECAISSEMENT_DATA__ || {};
                            const prepareDecaissement = document.getElementById('prepareDecaissement');
                            const draftPanel = document.getElementById('employeeDecaissementDraftPanel');
                            const draftChantier = document.getElementById('draftFdChantier');
                            const draftLigneDevis = document.getElementById('draftFdLigneDevis');
                            const draftDebourse = document.getElementById('draftFdDebourse');
                            const draftLigne = document.getElementById('draftFdLigne');
                            const draftFournisseur = document.getElementById('draftFdFournisseur');
                            const globalFournisseur = document.getElementById('febGlobalSupplier');
                            const globalFournisseurId = document.getElementById('febGlobalSupplierId');
                            const supplierBypassCsrf = <?= json_encode((string)($_SESSION['feb_supplier_unblock_csrf'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                            const draftModePaiement = document.getElementById('draftFdModePaiement');
                            const draftMontant = document.getElementById('draftFdMontant');
                            const draftBudgetIndicator = document.getElementById('draftFdBudgetIndicator');
                            const plannedStartInput = document.getElementById('febPlannedStart');
                            const plannedEndInput = document.getElementById('febPlannedEnd');
                            const deliveryInput = document.getElementById('feb_date');
                            const scheduleStatus = document.getElementById('febScheduleStatus');
                            const budgetRadios = Array.from(document.querySelectorAll('input[type="radio"][name="budget_planned"]'));
                            const isBudgetPlanned = () => budgetRadios.length === 0 || budgetRadios.some(radio => radio.checked && radio.value === 'yes');
                            const proposedPanel = document.getElementById('employeeProposedDeboursePanel');
                            const proposedLigneDevis = document.getElementById('proposedLigneDevis');
                            const proposedLinesContainer = document.getElementById('proposedDebourseLines');
                            const proposedTotal = document.getElementById('proposedDebourseTotal');
                            const addProposedLine = document.getElementById('addProposedDebourseLine');
                            const draftEnhancedSelects = new Map();
                            const draftMoney = value => Number(value || 0).toLocaleString('fr-FR') + ' FCFA';
                            const draftNormalize = value => String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                            const draftOptionLabel = option => option ? String(option.textContent || '').trim() : '';
                            function syncDraftAmountFromQuote() {
                                const quoteAmount = Number(document.getElementById('feb_montant')?.value || 0);
                                if (draftMontant && quoteAmount > 0) {
                                    draftMontant.value = String(quoteAmount);
                                    updateDraftBudgetIndicator();
                                }
                            }

                            function enhanceDraftSelectSearch(select, placeholder) {
                                if (!select || draftEnhancedSelects.has(select)) return;
                                select.classList.add('feb-select-search-native');

                                const wrap = document.createElement('div');
                                wrap.className = 'feb-select-search-wrap mt-1';
                                const input = document.createElement('input');
                                input.type = 'text';
                                input.className = 'w-full rounded-2xl border border-emerald-200 bg-white px-4 py-2 text-sm outline-none transition focus:border-emerald-400 focus:ring-4 focus:ring-emerald-100';
                                input.placeholder = placeholder || 'Rechercher...';
                                input.autocomplete = 'off';
                                const results = document.createElement('div');
                                results.className = 'feb-select-search-results';
                                results.hidden = true;

                                select.insertAdjacentElement('afterend', wrap);
                                wrap.appendChild(input);
                                wrap.appendChild(results);

                                const syncInput = () => {
                                    input.value = select.value ? draftOptionLabel(select.selectedOptions[0]) : '';
                                    if (select === globalFournisseur && globalFournisseurId) {
                                        globalFournisseurId.value = select.value || '';
                                    }
                                };
                                const render = () => {
                                    const q = draftNormalize(input.value);
                                    const options = Array.from(select.options).filter(option => option.value !== '');
                                    const filtered = options.filter(option => draftNormalize(draftOptionLabel(option)).includes(q)).slice(0, 50);
                                    results.innerHTML = '';
                                    if (!filtered.length) {
                                        const empty = document.createElement('div');
                                        empty.className = 'px-3 py-3 text-sm text-slate-500';
                                        empty.textContent = 'Aucun résultat';
                                        results.appendChild(empty);
                                    } else {
                                        filtered.forEach(option => {
                                            const btn = document.createElement('button');
                                            btn.type = 'button';
                                            btn.className = 'feb-select-search-option';
                                            btn.textContent = draftOptionLabel(option) + (option.disabled ? ' — INDISPONIBLE' : '');
                                            if (option.disabled) {
                                                btn.disabled = true;
                                                btn.classList.add('cursor-not-allowed','bg-slate-100','text-slate-400');
                                            }
                                            btn.addEventListener('click', () => {
                                                select.value = option.value;
                                                syncInput();
                                                results.hidden = true;
                                                select.dispatchEvent(new Event('change', { bubbles: true }));
                                            });
                                            results.appendChild(btn);
                                            if (select === globalFournisseur && option.disabled) {
                                                const bypass = document.createElement('button');
                                                bypass.type = 'button';
                                                bypass.className = 'mx-2 mb-2 w-[calc(100%-1rem)] rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-left text-xs font-black text-rose-700 hover:bg-rose-100';
                                                bypass.innerHTML = '<i class="fas fa-shield-halved mr-2"></i>Régulariser sans preuve <small class="mt-1 block font-semibold text-slate-500">Permission spéciale · action nominative et auditée</small>';
                                                bypass.addEventListener('click', async () => {
                                                    const reason = prompt('Motif obligatoire du bypass pour ce fournisseur :', '');
                                                    if (reason === null) return;
                                                    if (reason.trim().length < 8) { alert('Le motif doit contenir au moins 8 caractères.'); return; }
                                                    if (!confirm('Confirmer la régularisation sans preuve ? Votre identité et le motif seront enregistrés.')) return;
                                                    bypass.disabled = true;
                                                    bypass.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Régularisation en cours…';
                                                    try {
                                                        const body = new URLSearchParams({_csrf:supplierBypassCsrf,supplier_id:String(option.value),bypass_reason:reason.trim()});
                                                        const response = await fetch('../request/unblock_feb_supplier.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body});
                                                        const data = await response.json();
                                                        if (!response.ok || !data.ok) throw new Error(data.error || 'Bypass impossible.');
                                                        option.disabled = false;
                                                        const supplier = (window.__FEB_SUPPLIERS__ || []).find(item => String(item.id) === String(option.value));
                                                        if (supplier) { supplier.blocked=false; supplier.block_reason=''; supplier.block_fiches=''; }
                                                        select.value = option.value;
                                                        syncInput();
                                                        select.dispatchEvent(new Event('change',{bubbles:true}));
                                                        results.hidden = true;
                                                        alert(`Fournisseur régularisé et débloqué. ${Number(data.cancelled_regularizations||0)} fiche(s) clôturée(s) par bypass.`);
                                                    } catch (error) {
                                                        bypass.disabled = false;
                                                        bypass.innerHTML = '<i class="fas fa-shield-halved mr-2"></i>Régulariser sans preuve';
                                                        alert(error.message || 'Bypass impossible.');
                                                    }
                                                });
                                                results.appendChild(bypass);
                                            }
                                        });
                                    }
                                    results.hidden = false;
                                };

                                input.addEventListener('focus', render);
                                input.addEventListener('input', () => {
                                    select.value = '';
                                    if (select === globalFournisseur && globalFournisseurId) {
                                        globalFournisseurId.value = '';
                                    }
                                    if (select === draftChantier) {
                                        if (draftDebourse) draftDebourse.dataset.selected = '';
                                        if (draftLigne) draftLigne.dataset.selected = '';
                                        fillDraftDebourses();
                                    } else if (select === draftDebourse) {
                                        if (draftLigne) draftLigne.dataset.selected = '';
                                        fillDraftLignes();
                                    }
                                    render();
                                });
                                input.addEventListener('keydown', event => {
                                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                                        event.preventDefault();
                                        if (results.hidden) render();
                                        const choices = Array.from(results.querySelectorAll('button.feb-select-search-option:not(:disabled)'));
                                        const current = choices.findIndex(button => button.classList.contains('feb-keyboard-active'));
                                        const index = Math.max(0, Math.min(choices.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1)));
                                        choices.forEach((button, i) => button.classList.toggle('feb-keyboard-active', i === index));
                                        choices[index]?.scrollIntoView({block:'nearest'});
                                    }
                                    if (event.key === 'Enter' && !results.hidden) {
                                        event.preventDefault();
                                        (results.querySelector('button.feb-keyboard-active') || results.querySelector('button.feb-select-search-option:not(:disabled)'))?.click();
                                    }
                                    if (event.key === 'Escape') {
                                        results.hidden = true;
                                        input.blur();
                                    }
                                });
                                wrap.addEventListener('focusout', () => {
                                    setTimeout(() => {
                                        if (!wrap.contains(document.activeElement)) results.hidden = true;
                                    }, 120);
                                });
                                select.addEventListener('change', syncInput);
                                draftEnhancedSelects.set(select, { input, results, render, syncInput });
                                syncInput();
                            }

                            function refreshDraftSelectSearch(select) {
                                const enhanced = draftEnhancedSelects.get(select);
                                if (enhanced) enhanced.syncInput();
                            }

                            function updateDraftPanel() {
                                if (!draftPanel || !prepareDecaissement) return;
                                const planned = isBudgetPlanned();
                                prepareDecaissement.value = planned ? '1' : '';
                                draftPanel.classList.toggle('hidden', !planned);
                                if (proposedPanel) proposedPanel.classList.toggle('hidden', planned);
                                if (proposedPanel) {
                                    proposedPanel.querySelectorAll('input[name="proposed_debourse_title"], input[name="proposed_line_designation[]"], input[name="proposed_line_amount[]"]').forEach(field => field.required = !planned);
                                }
                                draftPanel.querySelectorAll('select[name], input[name]').forEach(field => {
                                    if (field.type !== 'hidden') field.required = planned;
                                });
                                [plannedStartInput, plannedEndInput].forEach(field => { if (field) { field.readOnly = planned; field.classList.toggle('bg-slate-100',planned); field.classList.toggle('bg-white',!planned); } });
                                syncLineSchedule();
                                refreshProposedTotal();
                            }

                            const formatScheduleDate = value => value ? new Date(value + 'T00:00:00').toLocaleDateString('fr-FR') : '';
                            function syncLineSchedule(lineOverride = null) {
                                const planned = isBudgetPlanned();
                                if (!planned) {
                                    if (scheduleStatus) scheduleStatus.innerHTML = '<i class="fas fa-pen mr-2"></i>Le déboursé sera créé pendant la validation. Les dates choisies ici deviendront celles de sa ligne et positionneront automatiquement la FEB sept jours avant le début.';
                                    if (deliveryInput && plannedStartInput) deliveryInput.value = plannedStartInput.value || '';
                                    return;
                                }
                                const line = lineOverride || (draftDecaissementData.lignes || []).find(row => Number(row.id || 0) === Number(draftLigne && draftLigne.value || 0));
                                const start = String(line && line.date_debut || ''), end = String(line && line.date_fin || '');
                                if (plannedStartInput) plannedStartInput.value = start;
                                if (plannedEndInput) plannedEndInput.value = end;
                                if (deliveryInput) deliveryInput.value = start;
                                if (!scheduleStatus) return;
                                if (start && end) {
                                    const position = new Date(start + 'T00:00:00'); position.setDate(position.getDate() - 7);
                                    scheduleStatus.className = 'mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900';
                                    scheduleStatus.innerHTML = `<i class="fas fa-circle-check mr-2"></i>Planning détecté. Décaissement prévu entre le <b>${position.toLocaleDateString('fr-FR')}</b> et le <b>${formatScheduleDate(start)}</b>. Date de livraison automatique : <b>${formatScheduleDate(start)}</b>.`;
                                } else if (line) {
                                    scheduleStatus.className = 'mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900';
                                    scheduleStatus.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Le planning de cette ligne manque. Demandez au département technique de renseigner les dates. Vérification automatique toutes les 30 secondes ; votre formulaire reste intact.';
                                } else {
                                    scheduleStatus.className = 'mt-3 rounded-xl border border-sky-200 bg-white px-4 py-3 text-sm text-sky-900';
                                    scheduleStatus.textContent = 'Sélectionnez la ligne de déboursé pour récupérer automatiquement son planning.';
                                }
                            }

                            async function pollSelectedLineSchedule() {
                                const planned = isBudgetPlanned();
                                const id = Number(draftLigne && draftLigne.value || 0);
                                if (!planned || !id) return;
                                try {
                                    const response = await fetch(`../request/get_feb_line_schedule.php?id=${encodeURIComponent(id)}`, { headers: { Accept: 'application/json' } });
                                    const data = await response.json();
                                    if (!response.ok || !data.ok) return;
                                    const line = (draftDecaissementData.lignes || []).find(row => Number(row.id || 0) === id);
                                    if (line) { line.date_debut = data.date_debut || ''; line.date_fin = data.date_fin || ''; }
                                    syncLineSchedule(line || data);
                                } catch (_) {}
                            }
                            window.setInterval(pollSelectedLineSchedule, 30000);

                            function fillProposedLignesDevis() {
                                if (!draftChantier || !proposedLigneDevis) return;
                                const option = draftChantier.selectedOptions[0] || null;
                                const devisId = option ? Number(option.dataset.devisId || 0) : 0;
                                const chantierId = Number(draftChantier.value || 0);
                                const wanted = String(proposedLigneDevis.dataset.selected || proposedLigneDevis.value || '');
                                const rows = (draftDecaissementData.lignes_devis || []).filter(row => Number(row.devis_id || 0) === devisId || Number(row.chantier_id || 0) === chantierId);
                                proposedLigneDevis.innerHTML = '<option value="">Choisir ou proposer une nouvelle ligne</option>' + rows.map(row => `<option value="${row.id}">${escapeHtml(row.designation || ('Ligne #' + row.id))} - ${draftMoney(row.montant)}</option>`).join('');
                                if (wanted) proposedLigneDevis.value = wanted;
                                proposedLigneDevis.dataset.selected = '';
                            }

                            function refreshProposedTotal() {
                                if (!proposedTotal || !proposedLinesContainer) return;
                                const total = Array.from(proposedLinesContainer.querySelectorAll('input[name="proposed_line_amount[]"]')).reduce((sum, input) => sum + Number(input.value || 0), 0);
                                proposedTotal.textContent = draftMoney(total);
                                const planned = isBudgetPlanned();
                                const febAmount = document.getElementById('feb_montant');
                                if (!planned && febAmount && total > 0) {
                                    febAmount.value = String(total);
                                    febAmount.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                            }

                            function appendProposedLine(data = {}) {
                                if (!proposedLinesContainer) return;
                                const row = document.createElement('div');
                                row.className = 'rounded-xl border border-amber-200 bg-white p-3';
                                row.innerHTML = `<div class="grid gap-3 md:grid-cols-2 lg:grid-cols-5">
                                    <label class="lg:col-span-2"><span class="text-xs font-semibold text-slate-600">Désignation</span><input name="proposed_line_designation[]" value="${escapeHtml(data.designation || '')}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" placeholder="Nature de la dépense"></label>
                                    <label><span class="text-xs font-semibold text-slate-600">Catégorie</span><select name="proposed_line_category[]" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" required>${['RH','Logistique','HSE','ACHAT','Bureau'].map(category => `<option value="${category}" ${String(data.categorie || '') === category ? 'selected' : ''}>${category}</option>`).join('')}</select></label>
                                    <label><span class="text-xs font-semibold text-slate-600">Montant</span><input type="number" min="0" step="0.01" name="proposed_line_amount[]" value="${escapeHtml(data.montant || '')}" class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></label>
                                    <button type="button" class="remove-proposed-line self-end rounded-lg border border-rose-200 px-3 py-2 text-rose-700"><i class="fas fa-trash"></i></button>
                                </div>`;
                                row.querySelector('.remove-proposed-line').addEventListener('click', () => { row.remove(); refreshProposedTotal(); });
                                row.querySelector('input[name="proposed_line_amount[]"]').addEventListener('input', refreshProposedTotal);
                                proposedLinesContainer.appendChild(row);
                                updateDraftPanel();
                                refreshProposedTotal();
                            }

                            function fillDraftLignesDevis() {
                                if (!draftChantier || !draftLigneDevis) return;
                                const selected = draftChantier.selectedOptions[0] || null;
                                const devisId = selected ? Number(selected.dataset.devisId || 0) : 0;
                                const chantierId = Number(draftChantier.value || 0);
                                const wanted = String(draftLigneDevis.dataset.selected || '');
                                const rows = (draftDecaissementData.lignes_devis || []).filter(row => Number(row.devis_id || 0) === devisId || Number(row.chantier_id || 0) === chantierId);
                                draftLigneDevis.innerHTML = '<option value="">Sélectionner</option>' + rows.map(row => `<option value="${row.id}">${row.designation || ('Ligne #' + row.id)} - ${draftMoney(row.montant)}</option>`).join('');
                                if (wanted) draftLigneDevis.value = wanted;
                                draftLigneDevis.dataset.selected = '';
                                refreshDraftSelectSearch(draftLigneDevis);
                            }

                            function fillDraftDebourses() {
                                if (!draftChantier || !draftDebourse || !draftLigne) return;
                                const selected = draftChantier.selectedOptions[0] || null;
                                const devisId = selected ? Number(selected.dataset.devisId || 0) : 0;
                                const chantierId = Number(draftChantier.value || 0);
                                const selectedDebourse = String(draftDebourse.dataset.selected || '');
                                const ligneDevisId = Number(draftLigneDevis && draftLigneDevis.value || 0);
                                const rows = (draftDecaissementData.debourses || []).filter(row => (Number(row.devis_id || 0) === devisId || Number(row.chantier_id || 0) === chantierId) && (!ligneDevisId || Number(row.ligne_devis_id || 0) === ligneDevisId));
                                draftDebourse.innerHTML = '<option value="">Sélectionner</option>' + rows.map(row => `<option value="${row.id}">${row.label || ('Déboursé #' + row.id)} - ${draftMoney(row.montant)}</option>`).join('');
                                if (selectedDebourse) draftDebourse.value = selectedDebourse;
                                draftDebourse.dataset.selected = '';
                                fillDraftLignes();
                                refreshDraftSelectSearch(draftDebourse);
                                refreshDraftSelectSearch(draftLigne);
                            }

                            function fillDraftLignes() {
                                if (!draftChantier || !draftDebourse || !draftLigne) return;
                                const debourseId = Number(draftDebourse.value || 0);
                                const chantierOption = draftChantier.selectedOptions[0] || null;
                                const devisId = chantierOption ? Number(chantierOption.dataset.devisId || 0) : 0;
                                const selectedLigne = String(draftLigne.dataset.selected || '');
                                const rows = (draftDecaissementData.lignes || []).filter(row => debourseId
                                    ? Number(row.debourse_id || 0) === debourseId
                                    : Number(row.devis_id || 0) === devisId);
                                draftLigne.innerHTML = '<option value="">Sélectionner</option>' + rows.map(row => {
                                    const parent = debourseId ? '' : `${row.debourse_label || ('Déboursé #' + row.debourse_id)} · `;
                                    return `<option value="${row.id}">${parent}${row.designation || ('Ligne #' + row.id)}${row.categorie ? ' - ' + row.categorie : ''} - ${draftMoney(row.montant)}</option>`;
                                }).join('');
                                if (selectedLigne) draftLigne.value = selectedLigne;
                                draftLigne.dataset.selected = '';
                                refreshDraftSelectSearch(draftLigne);
                                updateDraftBudgetIndicator();
                                syncLineSchedule();
                            }

                            function updateDraftBudgetIndicator() {
                                if (!draftBudgetIndicator) return;
                                const line = (draftDecaissementData.lignes || []).find(row => Number(row.id || 0) === Number(draftLigne && draftLigne.value || 0));
                                const budget = Number(line && line.montant || 0);
                                const asked = Number(draftMontant && draftMontant.value || 0);
                                if (!line || !asked) {
                                    draftBudgetIndicator.className = 'rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600';
                                    draftBudgetIndicator.textContent = 'Sélectionnez une ligne de déboursé et saisissez le montant pour contrôler le budget.';
                                    return;
                                }
                                const gap = budget - asked;
                                const exceeded = gap < 0;
                                draftBudgetIndicator.className = 'rounded-xl border px-4 py-3 text-sm font-semibold ' + (exceeded ? 'border-rose-300 bg-rose-50 text-rose-800' : 'border-emerald-300 bg-emerald-50 text-emerald-800');
                                draftBudgetIndicator.textContent = exceeded ? `Dépassement budgétaire de ${draftMoney(Math.abs(gap))} (budget ligne : ${draftMoney(budget)}).` : `Dans le budget — solde après demande : ${draftMoney(gap)} sur ${draftMoney(budget)}.`;
                            }

                            enhanceDraftSelectSearch(draftChantier, 'Rechercher un chantier...');
                            enhanceDraftSelectSearch(draftLigneDevis, 'Rechercher une ligne de devis...');
                            enhanceDraftSelectSearch(draftDebourse, 'Rechercher un déboursé...');
                            enhanceDraftSelectSearch(draftLigne, 'Rechercher une ligne...');
                            enhanceDraftSelectSearch(draftFournisseur, 'Rechercher un fournisseur...');
                            enhanceDraftSelectSearch(globalFournisseur, 'Rechercher le fournisseur global...');
                            // Le mode de paiement se choisit sur la grille de logos.
                            budgetRadios.forEach(radio => radio.addEventListener('change', updateDraftPanel));
                            draftChantier && draftChantier.addEventListener('change', () => {
                                if (draftLigneDevis) draftLigneDevis.dataset.selected = '';
                                if (draftDebourse) draftDebourse.dataset.selected = '';
                                if (draftLigne) draftLigne.dataset.selected = '';
                                fillDraftDebourses();
                                fillDraftLignesDevis();
                                fillProposedLignesDevis();
                                refreshDraftSelectSearch(draftChantier);
                            });
                            addProposedLine && addProposedLine.addEventListener('click', () => appendProposedLine({}));
                            const initialProposedLines = Array.isArray(window.__FEB_PROPOSED_DEBOURSE_LINES__) ? window.__FEB_PROPOSED_DEBOURSE_LINES__ : [];
                            (initialProposedLines.length ? initialProposedLines : [{}]).forEach(appendProposedLine);
                            draftLigneDevis && draftLigneDevis.addEventListener('change', () => {
                                if (draftDebourse) draftDebourse.dataset.selected = '';
                                if (draftLigne) draftLigne.dataset.selected = '';
                                fillDraftDebourses();
                                refreshDraftSelectSearch(draftLigneDevis);
                            });
                            draftDebourse && draftDebourse.addEventListener('change', () => {
                                if (draftLigne) draftLigne.dataset.selected = '';
                                fillDraftLignes();
                                refreshDraftSelectSearch(draftDebourse);
                            });
                            draftLigne && draftLigne.addEventListener('change', () => {
                                const selectedId = Number(draftLigne.value || 0);
                                const selectedRow = (draftDecaissementData.lignes || []).find(row => Number(row.id || 0) === selectedId);
                                if (selectedRow && Number(draftDebourse.value || 0) !== Number(selectedRow.debourse_id || 0)) {
                                    if (draftLigneDevis) draftLigneDevis.value = String(selectedRow.ligne_devis_id || '');
                                    draftDebourse.dataset.selected = String(selectedRow.debourse_id || '');
                                    draftLigne.dataset.selected = String(selectedRow.id || '');
                                    fillDraftDebourses();
                                    refreshDraftSelectSearch(draftLigneDevis);
                                }
                                refreshDraftSelectSearch(draftLigne);
                                updateDraftBudgetIndicator();
                                syncLineSchedule();
                            });
                            draftMontant && draftMontant.addEventListener('input', updateDraftBudgetIndicator);
                            plannedStartInput && plannedStartInput.addEventListener('change', syncLineSchedule);
                            plannedEndInput && plannedEndInput.addEventListener('change', syncLineSchedule);
                            draftFournisseur && draftFournisseur.addEventListener('change', () => refreshDraftSelectSearch(draftFournisseur));
                            draftModePaiement && draftModePaiement.addEventListener('change', () => refreshDraftSelectSearch(draftModePaiement));
                            document.addEventListener('click', event => {
                                if (!event.target.closest('.feb-select-search-wrap')) {
                                    draftEnhancedSelects.forEach(enhanced => {
                                        enhanced.results.hidden = true;
                                    });
                                }
                            });
                            updateDraftPanel();
                            fillDraftLignesDevis();
                            fillProposedLignesDevis();
                            fillDraftDebourses();

                            let currentStep = 0;

                            function setChipState(idx, active) {
                                const el = chips[idx];
                                if (!el) return;
                                if (active) {
                                    el.classList.add('bg-indigo-600', 'text-white', 'border-indigo-600');
                                    el.classList.remove('bg-white', 'text-slate-600', 'border-slate-200');
                                } else {
                                    el.classList.remove('bg-indigo-600', 'text-white', 'border-indigo-600');
                                    el.classList.add('bg-white', 'text-slate-600', 'border-slate-200');
                                }
                            }

                            function showStep(idx) {
                                currentStep = Math.max(0, Math.min(steps.length - 1, idx));
                                document.getElementById('febMainForm').dataset.currentStep = String(currentStep);
                                chips.forEach((chip, i) => { if (chip) chip.setAttribute('aria-current', i === currentStep ? 'step' : 'false'); });
                                steps.forEach((s, i) => {
                                    if (i === currentStep) s.classList.remove('hidden');
                                    else s.classList.add('hidden');
                                });

                                const pct = ((currentStep + 1) / steps.length) * 100;
                                if (progress) progress.style.width = pct + '%';

                                chips.forEach((_, i) => setChipState(i, i <= currentStep));

                                if (prevBtn) prevBtn.disabled = currentStep === 0;
                                if (nextBtn) nextBtn.style.display = currentStep === steps.length - 1 ? 'none' : 'inline-flex';
                                if (submitBtn) submitBtn.style.display = currentStep === steps.length - 1 ? 'inline-flex' : 'none';
                                syncDraftAmountFromQuote();
                                window.FebDisbursementFlow?.render(currentStep);
                                updateStepActions();
                            }

                            function isStepComplete(stepEl) {
                                if (!stepEl) return true;
                                const items = currentStep === 1 && typeof container !== 'undefined' && container ? Array.from(container.children) : [];
                                if (currentStep === 1 && !items.length) return false;
                                for (const field of Array.from(stepEl.querySelectorAll('input,select,textarea'))) {
                                    if (field.disabled || field.type === 'hidden' || field.offsetParent === null && !draftEnhancedSelects.has(field)) continue;
                                    if (draftEnhancedSelects.has(field)) {
                                        const enhanced = draftEnhancedSelects.get(field);
                                        if (enhanced.input.offsetParent !== null && field.required && !field.value) return false;
                                        continue;
                                    }
                                    if (field.validity && !field.validity.valid) return false;
                                }
                                return true;
                            }

                            function paintActionButton(button, blocked, finalButton = false) {
                                if (!button) return;
                                button.disabled = blocked;
                                button.setAttribute('aria-disabled', String(blocked));
                                button.classList.toggle('opacity-45', blocked);
                                button.classList.toggle('cursor-not-allowed', blocked);
                                button.title = blocked ? 'Complétez les champs obligatoires de cette étape.' : '';
                                button.innerHTML = blocked
                                    ? `<i class="fas fa-${finalButton ? 'paper-plane' : 'arrow-right'} mr-2"></i>${finalButton ? 'Envoyer la FEB' : 'Continuer'}`
                                    : finalButton ? '<i class="fas fa-paper-plane mr-2"></i>Soumettre' : 'Suivant<i class="fas fa-arrow-right ml-2"></i>';
                            }

                            function updateStepActions() {
                                const complete = isStepComplete(steps[currentStep]);
                                paintActionButton(nextBtn, !complete, false);
                                const aiReady = submitBtn ? submitBtn.dataset.aiReady !== '0' : true;
                                paintActionButton(submitBtn, !complete || !aiReady, true);
                                const chip = chips[currentStep];
                                if (chip) {
                                    chip.classList.toggle('ring-2', !complete);
                                    chip.classList.toggle('ring-rose-300', !complete);
                                    chip.title = complete ? 'Étape complète' : 'Étape incomplète';
                                }
                            }

                            if (prevBtn) prevBtn.addEventListener('click', () => showStep(currentStep - 1));

                            function validateCurrentStep() {
                                const stepEl = steps[currentStep];
                                if (!stepEl) return true;

                                // Étape 2 (index 1) : valider tous les besoins (même ceux repliés)
                                if (currentStep === 1) {
                                    const quoteTitle = stepEl.querySelector('input[name="feb_quote_title"]');
                                    if (quoteTitle && !quoteTitle.checkValidity()) {
                                        quoteTitle.reportValidity();
                                        quoteTitle.focus();
                                        return false;
                                    }
                                    if (!globalFournisseurId || !String(globalFournisseurId.value || '').trim()) {
                                        const enhancedGlobal = draftEnhancedSelects.get(globalFournisseur);
                                        if (enhancedGlobal) enhancedGlobal.input.focus();
                                        alert('Sélectionnez le fournisseur global de la FEB.');
                                        return false;
                                    }
                                    for (const globalField of Array.from(stepEl.querySelectorAll('[name="feb_global_supplier_selector"], [name="feb_global_justificatif"]'))) {
                                        if (typeof globalField.checkValidity === 'function' && !globalField.checkValidity()) {
                                            const enhancedGlobal = draftEnhancedSelects.get(globalField);
                                            if (enhancedGlobal) {
                                                enhancedGlobal.input.focus();alert('Sélectionnez le fournisseur global de la FEB.');
                                            } else {
                                                globalField.reportValidity();globalField.focus();
                                            }
                                            return false;
                                        }
                                    }

                                    if (!container) return true;
                                    const items = Array.from(container.children);
                                    if (!items.length) {
                                        alert('Veuillez ajouter au moins un besoin.');
                                        return false;
                                    }

                                    for (const node of items) {
                                        const fields = Array.from(node.querySelectorAll('select[name="besoin_type[]"], select[name="besoin_categorie[]"], input[name="besoin_libelle[]"], input[name="besoin_quantite[]"], input[name="besoin_fournisseur_prix[]"]'));
                                        for (const field of fields) {
                                            if (typeof field.checkValidity === 'function' && !field.checkValidity()) {
                                                setActiveBesoin(node);
                                                if (typeof field.reportValidity === 'function') field.reportValidity();
                                                return false;
                                            }
                                        }

                                        const supplierToggle = node.querySelector('.bf-toggle');
                                        if (supplierToggle && supplierToggle.checked) {
                                            const proofField = node.querySelector('input[name="besoin_justificatif[]"]');
                                            if (proofField && proofField.required && !proofField.files.length) {
                                                setActiveBesoin(node);
                                                proofField.reportValidity();
                                                proofField.focus();
                                                return false;
                                            }
                                            const supplierId = String(node.querySelector('.bf-supplier-id')?.value || '').trim();
                                            const priceField = node.querySelector('input[name="besoin_fournisseur_prix[]"]');
                                            if (!supplierId) {
                                                setActiveBesoin(node);
                                                alert('Veuillez sélectionner un fournisseur validé dans la liste.');
                                                return false;
                                            }
                                            if (!priceField || !priceField.value || Number(priceField.value) <= 0) {
                                                setActiveBesoin(node);
                                                if (priceField) {
                                                    priceField.required = true;
                                                    if (typeof priceField.reportValidity === 'function') priceField.reportValidity();
                                                } else {
                                                    alert('Veuillez renseigner le prix unitaire du fournisseur.');
                                                }
                                                return false;
                                            }
                                        }
                                    }
                                    return true;
                                }

                                const fields = Array.from(stepEl.querySelectorAll('input, select, textarea'));
                                for (const field of fields) {
                                    if (field.disabled) continue;
                                    if (field.type === 'hidden') continue;
                                    const enhanced = draftEnhancedSelects.get(field);
                                    // Les listes enrichies masquent le select natif : leur champ de
                                    // recherche visible sert alors de cible pour le message d'erreur.
                                    if (enhanced) {
                                        if (enhanced.input.offsetParent === null) continue;
                                        if (field.required && !field.value) {
                                            enhanced.input.focus();
                                            alert('Veuillez sélectionner une valeur dans la liste.');
                                            return false;
                                        }
                                        continue;
                                    }
                                    // Évite de bloquer sur les champs des panneaux conditionnels masqués.
                                    if (field.offsetParent === null) continue;
                                    if (typeof field.checkValidity === 'function' && !field.checkValidity()) {
                                        if (typeof field.reportValidity === 'function') field.reportValidity();
                                        return false;
                                    }
                                }
                                return true;
                            }

                            const fidestQuote = document.getElementById('febQuoteDocument');
                            const fidestProof = fidestQuote;
                            const fidestBox = document.getElementById('febFidestIaCheck');
                            const fidestSummary = document.getElementById('febFidestIaSummary');
                            const fidestDetails = document.getElementById('febFidestIaDetails');
                            const fidestDetailsButton = document.getElementById('febFidestIaDetailsButton');
                            const fidestExtractButton = document.getElementById('febFidestIaExtractButton');
                            const fidestExtractPanel = document.getElementById('febFidestIaExtract');
                            const fidestEvidence = document.getElementById('febFidestIaEvidence');
                            const fidestExtractText = document.getElementById('febFidestIaExtractText');
                            const fidestOcrText = document.getElementById('febFidestIaOcrText');
                            const fidestCopyButton = document.getElementById('febFidestIaCopy');
                            const fidestLoader = document.getElementById('febFidestIaLoader');
                            const fidestElapsed = document.getElementById('febFidestIaElapsed');
                            const febNeedsBlock = document.getElementById('febNeedsBlock');
                            const febDocumentZone = document.getElementById('febDocumentZone');
                            if (febNeedsBlock && febDocumentZone) febNeedsBlock.after(febDocumentZone);
                            let fidestLastFingerprint = '';
                            let fidestLastAccepted = null;
                            const febDocumentVerificationMode = document.getElementById('feb_document_verification_mode')?.value || 'ai';
                            let febHumanReviewToken = '';
                            let febHumanReviewTimer = 0;
                            const febHumanReviewTokenInput = document.getElementById('febHumanReviewToken');
                            if (fidestDetailsButton) fidestDetailsButton.addEventListener('click', () => fidestDetails?.classList.toggle('hidden'));
                            fidestExtractButton?.addEventListener('click', () => { const open = fidestExtractPanel?.classList.toggle('hidden') === false; fidestExtractButton.setAttribute('aria-expanded', String(open)); });
                            fidestCopyButton?.addEventListener('click', async () => {
                                const value = `Texte OCR extrait du fichier\n${fidestOcrText?.textContent || ''}\n\nDonnées retenues pour le contrôle\n${fidestExtractText?.textContent || ''}`;
                                if (!value) return;
                                try { await navigator.clipboard.writeText(value); fidestCopyButton.innerHTML = '<i class="fas fa-check mr-1" aria-hidden="true"></i>Copié'; window.setTimeout(() => { fidestCopyButton.innerHTML = '<i class="fas fa-copy mr-1" aria-hidden="true"></i>Tout copier'; }, 1600); }
                                catch (_) { fidestCopyButton.textContent = 'Copie impossible'; }
                            });
                            const resetFidestExtraction = () => { if (fidestExtractText) fidestExtractText.textContent = ''; if (fidestOcrText) fidestOcrText.textContent = ''; fidestExtractButton?.classList.add('hidden'); fidestExtractPanel?.classList.add('hidden'); fidestExtractButton?.setAttribute('aria-expanded', 'false'); fidestEvidence?.replaceChildren(); fidestEvidence?.classList.add('hidden'); fidestBox?.classList.remove('is-accepted', 'is-rejected', 'is-checking'); };
                            fidestQuote?.addEventListener('change', () => { fidestLastFingerprint=''; fidestLastAccepted=null; febHumanReviewToken=''; if(febHumanReviewTokenInput)febHumanReviewTokenInput.value=''; if(febHumanReviewTimer)window.clearTimeout(febHumanReviewTimer); resetFidestExtraction(); });

                            async function pollHumanDocumentReview() {
                                if (!febHumanReviewToken) return false;
                                try {
                                    const response=await fetch('../api/feb_human_document_review.php?token='+encodeURIComponent(febHumanReviewToken),{headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
                                    const data=await response.json();if(!data.ok)throw new Error(data.error||'Suivi indisponible');
                                    if(data.status==='approved'){
                                        if(febHumanReviewTokenInput)febHumanReviewTokenInput.value=febHumanReviewToken;
                                        fidestLastAccepted=true;fidestBox?.classList.remove('is-checking','is-rejected');fidestBox?.classList.add('is-accepted');fidestLoader?.classList.add('hidden');
                                        if(fidestSummary)fidestSummary.textContent='Justificatif validé par '+(data.decided_by_name||'un validateur')+'.';if(nextBtn)nextBtn.disabled=false;return true;
                                    }
                                    if(data.status==='rejected'){
                                        if(febHumanReviewTokenInput)febHumanReviewTokenInput.value='';
                                        fidestLastAccepted=false;fidestBox?.classList.remove('is-checking');fidestBox?.classList.add('is-rejected');fidestLoader?.classList.add('hidden');
                                        if(fidestSummary)fidestSummary.textContent='Justificatif refusé : '+(data.decision_reason||'aucune raison fournie')+'. Remplacez le fichier pour le resoumettre.';if(nextBtn)nextBtn.disabled=false;return false;
                                    }
                                    if(fidestSummary)fidestSummary.textContent='En attente de la validation humaine…';
                                    febHumanReviewTimer=window.setTimeout(pollHumanDocumentReview,5000);return false;
                                }catch(error){if(fidestSummary)fidestSummary.textContent=error.message||'Suivi momentanément indisponible.';febHumanReviewTimer=window.setTimeout(pollHumanDocumentReview,10000);return false;}
                            }

                            async function submitHumanDocumentReview(file,supplier,amount,expectedLines,fingerprint){
                                if(febHumanReviewToken)return pollHumanDocumentReview();
                                resetFidestExtraction();fidestBox?.classList.add('is-checking');fidestLoader?.classList.remove('hidden');if(nextBtn)nextBtn.disabled=true;
                                if(fidestSummary)fidestSummary.textContent='Transmission du justificatif aux validateurs…';
                                const body=new FormData();body.append('csrf_token',<?=json_encode($febProofUploadCsrfToken)?>);body.append('document',file);body.append('supplier',supplier);body.append('amount',String(amount));body.append('lines',JSON.stringify(expectedLines));
                                try{const response=await fetch('../api/feb_human_document_review.php',{method:'POST',body,headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});const data=await response.json();if(!data.ok)throw new Error(data.error||'Transmission impossible');febHumanReviewToken=String(data.token||'');fidestLastFingerprint=fingerprint;if(fidestSummary)fidestSummary.textContent='En attente de la validation humaine…';febHumanReviewTimer=window.setTimeout(pollHumanDocumentReview,3000);return false;}
                                catch(error){fidestBox?.classList.remove('is-checking');fidestLoader?.classList.add('hidden');if(nextBtn)nextBtn.disabled=false;if(fidestSummary)fidestSummary.textContent=error.message||'Transmission impossible.';return false;}
                            }

                            async function checkFidestDocument() {
                                const file = fidestProof?.files?.[0];
                                if (<?= json_encode($isUrgentFebRegularization) ?>) {
                                    if (!file && fidestQuote?.required) return false;
                                    fidestLastAccepted=true;
                                    fidestBox?.classList.add('is-accepted');
                                    if (fidestSummary) fidestSummary.textContent='Justificatif de régularisation joint. Le contrôle comparatif ne s’applique pas.';
                                    return true;
                                }
                                if (!file) return true;
                                try {
                                    const modeResponse = await fetch('../api/feb_human_document_review.php?action=mode', {headers:{Accept:'application/json'},cache:'no-store'});
                                    const modeData = await modeResponse.json();
                                    if (!modeData.ok || modeData.mode !== febDocumentVerificationMode) {
                                        if (fidestSummary) fidestSummary.textContent = 'Le mode de validation a changé. Rechargez cette page pour continuer sans contrôle inadapté.';
                                        return false;
                                    }
                                } catch (_) {
                                    if (fidestSummary) fidestSummary.textContent = 'Impossible de vérifier le mode de validation. Réessayez.';
                                    return false;
                                }
                                const supplier = globalFournisseur?.options?.[globalFournisseur.selectedIndex]?.textContent?.trim() || '';
                                const expectedLines = Array.from(document.querySelectorAll('#febBesoinContainer > *')).map(node => ({
                                    type: node.querySelector('[name="besoin_type[]"]')?.value || '',
                                    libelle: node.querySelector('[name="besoin_libelle[]"]')?.value || '',
                                    quantite: Number(node.querySelector('[name="besoin_quantite[]"]')?.value || 0),
                                    prix_unitaire: Number(node.querySelector('[name="besoin_fournisseur_prix[]"]')?.value || 0)
                                })).filter(line => line.libelle);
                                const amount = expectedLines.reduce((sum, line) => sum + line.quantite * line.prix_unitaire, 0);
                                const amountInput = document.getElementById('feb_montant');
                                if (amountInput) amountInput.value = amount > 0 ? String(amount) : '';
                                syncDraftAmountFromQuote();
                                if (amount <= 0) {
                                    resetFidestExtraction();
                                    if (fidestSummary) fidestSummary.textContent = 'Renseignez la quantité et le prix de chaque besoin avant le contrôle du justificatif.';
                                    return false;
                                }
                                const fingerprint = [file.name,file.size,file.lastModified,supplier,amount].join('|');
                                if (fingerprint === fidestLastFingerprint && fidestLastAccepted !== null) return fidestLastAccepted;
                                if (febDocumentVerificationMode === 'human') return submitHumanDocumentReview(file,supplier,amount,expectedLines,fingerprint);
                                if (febDocumentVerificationMode === 'allow_all') { fidestLastFingerprint=fingerprint;fidestLastAccepted=true;fidestBox?.classList.add('is-accepted');if(fidestSummary)fidestSummary.textContent='Justificatif accepté automatiquement.';return true; }
                                resetFidestExtraction();
                                fidestBox?.classList.add('is-checking');
                                if (fidestSummary) fidestSummary.textContent = 'Analyse du justificatif en cours…';
                                fidestLoader?.classList.remove('hidden');
                                const analysisStartedAt=Date.now();
                                if(fidestElapsed)fidestElapsed.textContent='0 s';
                                const elapsedTimer=window.setInterval(()=>{if(fidestElapsed)fidestElapsed.textContent=Math.floor((Date.now()-analysisStartedAt)/1000)+' s';},1000);
                                if (nextBtn) nextBtn.disabled = true;
                                const body = new FormData();
                                body.append('csrf_token', <?= json_encode($febProofUploadCsrfToken) ?>);
                                body.append('document', file); body.append('supplier', supplier); body.append('amount', String(amount));
                                body.append('lines', JSON.stringify(expectedLines));
                                try {
                                    const response = await fetch('../request/check_feb_document_fidest_ia.php', {method:'POST',body,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
                                    const data = await response.json();
                                    if (!data.ok) throw new Error(data.error || 'Contrôle indisponible');
                                    if (!data.configured) { if(fidestSummary) fidestSummary.textContent=data.summary; fidestLastAccepted=true; return true; }
                                    const result=data.result||{}, extracted=result.extracted||{};
                                    const extractedLines = Array.isArray(extracted.lines) ? extracted.lines : [];
                                    const extractedText = [
                                        `Fournisseur trouvé : ${extracted.supplier_detected || 'Non détecté'}`,
                                        `Champ fournisseur IA : ${extracted.supplier || 'Non détecté'}`,
                                        `Nom du fournisseur retrouvé dans le texte : ${extracted.matched_supplier_in_ocr ? 'Oui' : 'Non'}`,
                                        `Montant total : ${extracted.total_amount == null ? 'Non détecté' : Number(extracted.total_amount).toLocaleString('fr-FR') + ' FCFA'}`,
                                        `Montant FEB à comparer : ${extracted.expected_amount == null ? 'Non renseigné' : Number(extracted.expected_amount).toLocaleString('fr-FR') + ' FCFA'}`,
                                        `Montant proche retrouvé dans le texte : ${extracted.matched_total_in_ocr == null ? 'Non détecté' : Number(extracted.matched_total_in_ocr).toLocaleString('fr-FR') + ' FCFA'}`,
                                        `Date : ${extracted.document_date || 'Non détectée'}`,
                                        `Numéro : ${extracted.document_number || 'Non détecté'}`,
                                        ...extractedLines.map((line, index) => `Ligne ${index + 1} : ${line.designation || 'Sans désignation'}${line.quantity == null ? '' : ' · quantité ' + line.quantity}${line.unit_price == null ? '' : ' · prix unitaire ' + Number(line.unit_price).toLocaleString('fr-FR') + ' FCFA'}${line.total == null ? '' : ' · total ' + Number(line.total).toLocaleString('fr-FR') + ' FCFA'}`)
                                    ].join('\n');
                                    if (fidestExtractText) fidestExtractText.textContent = extractedText;
                                    if (fidestOcrText) fidestOcrText.textContent = String(result.ocr_text || '').trim() || 'Aucun texte OCR lisible n’a été extrait du fichier.';
                                    fidestExtractButton?.classList.remove('hidden');
                                    if (fidestEvidence) {
                                        fidestEvidence.replaceChildren();
                                        const evidence = result.evidence || {};
                                        const evidenceRows = [
                                            ['Fournisseur', evidence.supplier || 'Non confirmé', Boolean(evidence.supplier && evidence.supplier !== 'Non confirmé')],
                                            ['Montant', evidence.amount || 'Non confirmé', Boolean(evidence.amount && evidence.amount !== 'Non confirmé')],
                                            ['Confiance OCR', result.ocr_confidence == null ? 'Non fournie' : `${Math.round(Number(result.ocr_confidence) * (Number(result.ocr_confidence) <= 1 ? 100 : 1))} %`, result.ocr_confidence != null]
                                        ];
                                        evidenceRows.forEach(([label, value, positive]) => { const badge = document.createElement('span'); badge.className = `feb-evidence-chip${positive ? ' is-positive' : ''}`; badge.textContent = `${label} · ${value}`; fidestEvidence.appendChild(badge); });
                                        if (evidence.validation_overridden) { const badge = document.createElement('span'); badge.className = 'feb-evidence-chip is-review'; badge.textContent = 'Lecture OCR retenue malgré une divergence IA'; fidestEvidence.appendChild(badge); }
                                        fidestEvidence.classList.remove('hidden');
                                    }
                                    fidestLastFingerprint=fingerprint; fidestLastAccepted=!!result.accepted;
                                    if(fidestSummary) fidestSummary.textContent=result.summary||'';
                                    if(fidestDetails) {
                                        fidestDetails.replaceChildren();
                                        const detailTitle=document.createElement('div');detailTitle.className='font-semibold text-slate-800';detailTitle.textContent='Résultat détaillé';fidestDetails.appendChild(detailTitle);
                                        const lines=[`Fournisseur trouvé : ${extracted.supplier_detected||'Non détecté'}`,`Montant FEB : ${Number(extracted.expected_amount||0).toLocaleString('fr-FR')} FCFA`, `Montant du justificatif : ${extracted.matched_total_amount==null?'Non détecté':Number(extracted.matched_total_amount).toLocaleString('fr-FR')+' FCFA'}`,`Date : ${extracted.document_date||'Non détectée'} · Numéro : ${extracted.document_number||'Non détecté'}`];
                                        for(const line of lines){const div=document.createElement('div');div.textContent=line;fidestDetails.appendChild(div);}
                                        if((result.line_checks||[]).length){const title=document.createElement('div');title.className='mt-2 font-semibold text-slate-800';title.textContent='Contrôle des lignes';fidestDetails.appendChild(title);for(const check of result.line_checks){const div=document.createElement('div');div.textContent=`${check.matched?'✓':'✕'} ${check.expected}${(check.issues||[]).length?' — '+check.issues.join(', '):''}`;div.className=check.matched&&!(check.issues||[]).length?'text-emerald-700':'text-rose-700';fidestDetails.appendChild(div);}}
                                        if((result.reasons||[]).length){const ul=document.createElement('ul');ul.className='mt-2 list-disc pl-4 text-rose-700';for(const reason of result.reasons){const li=document.createElement('li');li.textContent=reason;ul.appendChild(li);}fidestDetails.appendChild(ul);}
                                    }
                                    fidestDetailsButton?.classList.remove('hidden');
                                    fidestBox?.classList.remove('border-slate-200','border-emerald-200','bg-emerald-50','border-rose-200','bg-rose-50','border-amber-200','bg-amber-50');
                                    fidestBox?.classList.remove('is-checking', 'is-accepted', 'is-rejected');
                                    fidestBox?.classList.add(result.accepted ? 'is-accepted' : 'is-rejected');
                                    fidestBox?.classList.add(result.accepted?'border-emerald-200':'border-rose-200',result.accepted?'bg-emerald-50':'bg-rose-50');
                                    return !!result.accepted;
                                } catch(error) {
                                    fidestLastAccepted=false;
                                    fidestBox?.classList.remove('is-checking');
                                    fidestBox?.classList.add('is-rejected');
                                    if(fidestSummary) fidestSummary.textContent=error instanceof Error?error.message:'Contrôle indisponible';
                                    fidestBox?.classList.add('border-amber-200','bg-amber-50'); return false;
                                } finally { const remaining=650-(Date.now()-analysisStartedAt);if(remaining>0)await new Promise(resolve=>window.setTimeout(resolve,remaining));window.clearInterval(elapsedTimer); fidestLoader?.classList.add('hidden'); if(nextBtn) nextBtn.disabled=false; }
                            }
                            fidestProof?.addEventListener('change',async()=>{fidestLastFingerprint='';fidestLastAccepted=null;if(fidestSummary)fidestSummary.textContent='Préparation du contrôle…';if(fidestProof.files?.length)await checkFidestDocument();});

                            if (nextBtn) nextBtn.addEventListener('click', async () => {
                                if (!validateCurrentStep()) return;
                                if (window.FebDisbursementFlow && !window.FebDisbursementFlow.validate(currentStep)) return;
                                if (currentStep === 1 && !(await checkFidestDocument())) return;
                                showStep(currentStep + 1);
                            });
                            const febForm = document.getElementById('febMainForm');
                            if (febForm) {
                                febForm.addEventListener('input', () => { syncDraftAmountFromQuote(); updateStepActions(); });
                                febForm.addEventListener('change', () => { syncDraftAmountFromQuote(); updateStepActions(); });
                            }

                            // ——————————————————————————
                            // Besoins dynamiques
                            // ——————————————————————————
                            const container = document.getElementById('febBesoinContainer');
                            const summary = document.getElementById('febBesoinSummary');
                            const addBtn = document.getElementById('febAddBesoin');
                            let besoinIdx = 0;
                            let activeBesoinIdx = null;
                            const suppliers = Array.isArray(window.__FEB_SUPPLIERS__) ? window.__FEB_SUPPLIERS__.filter(item => item && Number(item.id) > 0) : [];
                            const febAiBaseContext = window.__FEB_AI_CONTEXT__ || {};

                            function fieldValue(selector) {
                                const el = document.querySelector(selector);
                                return el ? String(el.value || '').trim() : '';
                            }

                            function selectedText(selector) {
                                const el = document.querySelector(selector);
                                if (!el || !el.options || el.selectedIndex < 0) return '';
                                return String(el.options[el.selectedIndex].textContent || '').trim();
                            }

                            function collectBesoinContext() {
                                return Array.from(container ? container.children : []).map(node => ({
                                    type: String(node.querySelector('select[name="besoin_type[]"]')?.value || '').trim(),
                                    categorie: String(node.querySelector('select[name="besoin_categorie[]"]')?.value || '').trim(),
                                    libelle: String(node.querySelector('input[name="besoin_libelle[]"]')?.value || '').trim(),
                                    quantite: String(node.querySelector('input[name="besoin_quantite[]"]')?.value || '').trim(),
                                    fournisseur_nom: String(node.querySelector('input[name="besoin_fournisseur_nom[]"]')?.value || '').trim(),
                                    fournisseur_prix: String(node.querySelector('input[name="besoin_fournisseur_prix[]"]')?.value || '').trim(),
                                    fournisseur_tel: String(node.querySelector('input[name="besoin_fournisseur_tel[]"]')?.value || '').trim()
                                })).filter(item => item.type || item.libelle || item.quantite || item.fournisseur_nom || item.fournisseur_prix);
                            }

                            function collectFebAiContext() {
                                return {
                                    ...febAiBaseContext,
                                    departement_id: fieldValue('#departement_id'),
                                    departement_label: selectedText('#departement_id').replace(/^[-â€”\s]+/, ''),
                                    description: fieldValue('#feb_description'),
                                    montant: fieldValue('#feb_montant'),
                                    date_livraison: fieldValue('#feb_date'),
                                    impact: fieldValue('#feb_impact'),
                                    frequence: selectedText('#feb_frequence') || fieldValue('#feb_frequence'),
                                    besoins: collectBesoinContext(),
                                    available_suppliers: suppliers.slice(0, 20)
                                };
                            }

                            function applyAiOptionStyle(btn) {
                                const active = String(btn.dataset.active || '0') === '1';
                                btn.classList.toggle('border-emerald-200', active);
                                btn.classList.toggle('bg-emerald-50', active);
                                btn.classList.toggle('text-emerald-700', active);
                                btn.classList.toggle('border-red-200', !active);
                                btn.classList.toggle('bg-red-50', !active);
                                btn.classList.toggle('text-red-700', !active);
                            }

                            function selectedAiOptions(button) {
                                const group = button ? button.parentElement?.querySelector('[data-feb-ai-options]') : null;
                                const source = group || document;
                                const options = {};
                                source.querySelectorAll('.feb-ai-option').forEach(btn => {
                                    const key = String(btn.dataset.aiOption || '').trim();
                                    if (key !== '') {
                                        options[key] = String(btn.dataset.active || '0') === '1';
                                    }
                                });
                                return options;
                            }

                            function filterFebAiContext(context, options) {
                                const filtered = {
                                    csrf_token: context.csrf_token,
                                    ai_options: options
                                };

                                if (options.identity) {
                                    filtered.demandeur = context.demandeur || '';
                                    filtered.matricule = context.matricule || '';
                                }
                                if (options.job) {
                                    filtered.service_employe = context.service_employe || '';
                                    filtered.departement_id = context.departement_id || '';
                                    filtered.departement_label = context.departement_label || '';
                                } else {
                                    filtered.departement_label = context.departement_label || '';
                                }
                                if (options.request) {
                                    filtered.description = context.description || '';
                                    filtered.montant = context.montant || '';
                                    filtered.date_livraison = context.date_livraison || '';
                                    filtered.impact = context.impact || '';
                                    filtered.frequence = context.frequence || '';
                                }
                                if (options.needs) {
                                    filtered.besoins = (context.besoins || []).map(item => {
                                        if (options.supplier) return item;
                                        return {
                                            type: item.type || '',
                                            libelle: item.libelle || '',
                                            quantite: item.quantite || ''
                                        };
                                    });
                                }
                                if (options.supplier) {
                                    filtered.available_suppliers = context.available_suppliers || [];
                                }

                                return filtered;
                            }

                            function setFieldAiStatus(field, message, isError) {
                                const status = document.getElementById(field + '_ai_status');
                                if (!status) return;
                                status.classList.remove('hidden', 'text-emerald-700', 'text-red-700', 'text-slate-500');
                                status.classList.add(isError ? 'text-red-700' : 'text-emerald-700');
                                status.textContent = message;
                            }

                            async function generateFebField(field, targetId, button) {
                                const target = document.getElementById(targetId);
                                if (!target) return;
                                if (target.value.trim() !== '' && !confirm('Remplacer le contenu actuel par une proposition IA ?')) {
                                    return;
                                }

                                const csrf = String(febAiBaseContext.csrf_token || '').trim();
                                if (!csrf) {
                                    setFieldAiStatus(field, 'Assistant IA indisponible sur cette session.', true);
                                    return;
                                }

                                const oldHtml = button ? button.innerHTML : '';
                                if (button) {
                                    button.disabled = true;
                                    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generation...';
                                }
                                setFieldAiStatus(field, 'Generation en cours...', false);

                                try {
                                    const fd = new FormData();
                                    fd.append('csrf_token', csrf);
                                    fd.append('action', 'field_generate');
                                    fd.append('field', field);
                                    const options = selectedAiOptions(button);
                                    fd.append('context_json', JSON.stringify(filterFebAiContext(collectFebAiContext(), options)));

                                    const res = await fetch('../ai_assistant/index.php', {
                                        method: 'POST',
                                        body: fd,
                                        headers: {
                                            'Accept': 'application/json'
                                        }
                                    });
                                    const json = await res.json().catch(() => null);
                                    if (!res.ok || !json || !json.success) {
                                        throw new Error((json && json.message) ? json.message : 'Generation impossible.');
                                    }

                                    const generatedText = String(json.generated_text || '').trim();
                                    target.value = generatedText;
                                    const requestInput = document.getElementById(field + '_request_id');
                                    const generatedInput = document.getElementById(field + '_generated');
                                    if (requestInput) requestInput.value = String(json.request_id || '');
                                    if (generatedInput) generatedInput.value = generatedText;
                                    target.dispatchEvent(new Event('input', {
                                        bubbles: true
                                    }));
                                    setFieldAiStatus(field, json.used_external_ai ? 'Texte genere avec IA externe.' : 'Texte genere avec le mode interne.', false);
                                } catch (e) {
                                    setFieldAiStatus(field, e && e.message ? e.message : 'Generation impossible.', true);
                                } finally {
                                    if (button) {
                                        button.disabled = false;
                                        button.innerHTML = oldHtml;
                                    }
                                }
                            }

                            document.querySelectorAll('.feb-ai-field-btn').forEach(btn => {
                                btn.addEventListener('click', () => {
                                    generateFebField(String(btn.dataset.aiField || ''), String(btn.dataset.aiTarget || ''), btn);
                                });
                            });

                            document.querySelectorAll('.feb-ai-option').forEach(btn => {
                                applyAiOptionStyle(btn);
                                btn.addEventListener('click', () => {
                                    btn.dataset.active = String(btn.dataset.active || '0') === '1' ? '0' : '1';
                                    applyAiOptionStyle(btn);
                                });
                            });

                            function escapeHtml(value) {
                                return String(value || '').replace(/[&<>"']/g, char => ({
                                    '&': '&amp;',
                                    '<': '&lt;',
                                    '>': '&gt;',
                                    '"': '&quot;',
                                    "'": '&#039;'
                                } [char] || char));
                            }

                            function supplierLabel(supplier) {
                                const name = String(supplier.name || '').trim() || ('Fournisseur #' + String(supplier.id || ''));
                                const code = String(supplier.code || '').trim();
                                const categories = String(supplier.categories || '').trim();
                                return name + (code ? ' - ' + code : '') + (categories ? ' (' + categories + ')' : '');
                            }

                            function supplierOptionsHtml(selectedId) {
                                const selected = String(selectedId || '');
                                let html = '<option value="">-- Selectionner un fournisseur valide --</option>';
                                suppliers.forEach(supplier => {
                                    const value = String(supplier.id || '');
                                    const blocked=Boolean(supplier.blocked);
                                    const compliance=blocked?` — INDISPONIBLE : ${supplier.block_reason||'justificatifs à fournir'}${supplier.block_fiches?' ('+supplier.block_fiches+')':''}`:'';
                                    html += `<option value="${escapeHtml(value)}"${selected === value ? ' selected' : ''}${blocked?' disabled':''}>${escapeHtml(supplierLabel(supplier)+compliance)}</option>`;
                                });
                                return html;
                            }

                            function setActiveBesoin(wrap) {
                                if (!container || !wrap) return;
                                const items = Array.from(container.children);
                                items.forEach(node => {
                                    node.classList.remove('ring-1', 'ring-indigo-300', 'bg-indigo-50');
                                    const body = node.querySelector('.feb-besoin-body');
                                    if (body) body.classList.add('hidden');
                                });

                                wrap.classList.add('ring-1', 'ring-indigo-300', 'bg-indigo-50');
                                const body = wrap.querySelector('.feb-besoin-body');
                                if (body) body.classList.remove('hidden');
                                activeBesoinIdx = wrap.dataset.idx || null;
                                refreshSummary();
                            }

                            function createBesoinItem(data) {
                                const idx = besoinIdx++;
                                const wrap = document.createElement('div');
                                wrap.className = 'rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300';
                                wrap.dataset.idx = String(idx);

                                const header = document.createElement('div');
                                header.className = 'flex items-start justify-between gap-3 cursor-pointer select-none';
                                const title = document.createElement('div');
                                title.className = 'text-sm font-semibold text-slate-900';
                                title.textContent = 'Besoin ' + (idx + 1);
                                const del = document.createElement('button');
                                del.type = 'button';
                                del.className = 'rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50';
                                del.innerHTML = '<i class="fas fa-trash"></i>';
                                header.appendChild(title);
                                header.appendChild(del);

                                const body = document.createElement('div');
                                body.className = 'mt-3 space-y-3 feb-besoin-body hidden';

                                body.innerHTML = `
                                <div class="grid gap-3 sm:grid-cols-4">
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Type</label>
                                        <select name="besoin_type[]" class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2" required>
                                            <option value="Bien">Bien</option>
                                            <option value="Service">Service</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Catégorie</label>
                                        <select name="besoin_categorie[]" class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2" required>
                                            <option value="">Sélectionner</option><option>RH</option><option>Logistique</option><option>HSE</option><option>ACHAT</option><option>Bureau</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Libellé du besoin</label>
                                        <input type="text" name="besoin_libelle[]" class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2" placeholder="Ex: Achat de matériel" required>
                                    </div>
                                </div>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Quantité</label>
                                        <input type="number" name="besoin_quantite[]" min="1" step="1" class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2" required>
                                    </div>
                                    <div>
                                        <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Prix unitaire estimé (XOF)</label>
                                        <input type="number" name="besoin_fournisseur_prix[]" min="1" step="0.01" required class="mt-1 w-full rounded-2xl border border-slate-200 px-3 py-2" placeholder="Ex: 25000">
                                        <div class="mt-1 text-xs font-semibold text-indigo-700">Montant ligne : <span class="bf-line-total">0 FCFA</span></div>
                                    </div>
                                </div>
                            `;

                                wrap.appendChild(header);
                                wrap.appendChild(body);

                                const selectType = wrap.querySelector('select[name="besoin_type[]"]');
                                const selectCategory = wrap.querySelector('select[name="besoin_categorie[]"]');
                                const inputLib = wrap.querySelector('input[name="besoin_libelle[]"]');
                                const inputQty = wrap.querySelector('input[name="besoin_quantite[]"]');
                                const chk = wrap.querySelector('#bf_' + idx);
                                const hiddenHasF = wrap.querySelector('input.bf-hidden');
                                const details = wrap.querySelector('#bf_details_' + idx);
                                const supplierSelect = wrap.querySelector('.bf-supplier-select');
                                const supplierIdInput = wrap.querySelector('.bf-supplier-id');
                                const supplierNameInput = wrap.querySelector('.bf-supplier-name');
                                const supplierPhoneInput = wrap.querySelector('.bf-supplier-phone');
                                const supplierDisplayName = wrap.querySelector('.bf-supplier-display-name');
                                const supplierDisplayPhone = wrap.querySelector('.bf-supplier-display-phone');
                                const lineProofInput = wrap.querySelector('input[name="besoin_justificatif[]"]');
                                if (lineProofInput && data && (data.justificatif_devis || data.justificatif_token)) {
                                    lineProofInput.required = false;
                                    const proofHint = lineProofInput.parentElement.querySelector('p');
                                    if (proofHint) proofHint.textContent = 'Un justificatif est déjà enregistré. Choisissez un fichier uniquement pour le remplacer.';
                                }
                                const supplierDisplayContact = wrap.querySelector('.bf-supplier-display-contact');
                                const supplierPriceInput = wrap.querySelector('input[name="besoin_fournisseur_prix[]"]');
                                const lineTotalOutput = wrap.querySelector('.bf-line-total');

                                if (selectType && data && data.type) selectType.value = data.type;
                                if (selectCategory && data && data.categorie) selectCategory.value = data.categorie;
                                if (inputLib && data && data.libelle) inputLib.value = data.libelle;
                                if (inputQty && data && data.quantite) inputQty.value = data.quantite;
                                if (chk) chk.checked = true;
                                if (hiddenHasF) hiddenHasF.value = (chk && chk.checked) ? '1' : '0';
                                if (details && chk && chk.checked) details.classList.remove('hidden');
                                if (supplierPriceInput && data && data.fournisseur_prix) supplierPriceInput.value = data.fournisseur_prix;
                                if (supplierSelect && data && data.fournisseur_id) supplierSelect.value = String(data.fournisseur_id);

                                function findSupplierById(value) {
                                    const id = String(value || '').trim();
                                    return suppliers.find(supplier => String(supplier.id || '') === id) || null;
                                }

                                function applySupplierToBesoin() {
                                    const supplier = supplierSelect ? findSupplierById(supplierSelect.value) : null;
                                    if (supplierIdInput) supplierIdInput.value = supplier ? String(supplier.id || '') : '';
                                    if (supplierNameInput) supplierNameInput.value = supplier ? String(supplier.name || '') : '';
                                    if (supplierPhoneInput) supplierPhoneInput.value = supplier ? String(supplier.phone || '') : '';
                                    if (supplierDisplayName) supplierDisplayName.value = supplier ? String(supplier.name || '') : '';
                                    if (supplierDisplayPhone) supplierDisplayPhone.value = supplier ? String(supplier.phone || '') : '';
                                    if (supplierDisplayContact) {
                                        const contactBits = supplier ? [supplier.contact || '', supplier.email || ''].map(v => String(v || '').trim()).filter(Boolean) : [];
                                        supplierDisplayContact.value = contactBits.join(' / ');
                                    }
                                }

                                function syncSupplierRequirement() {
                                    if (!chk) {
                                        if (supplierPriceInput) supplierPriceInput.required = true;
                                        return;
                                    }
                                    const active = !!(chk && chk.checked);
                                    if (hiddenHasF) hiddenHasF.value = active ? '1' : '0';
                                    if (details) details.classList.toggle('hidden', !active);
                                    if (supplierPriceInput) supplierPriceInput.required = active;
                                    if (!active) {
                                        if (supplierSelect) supplierSelect.value = '';
                                        if (supplierPriceInput) supplierPriceInput.value = '';
                                        applySupplierToBesoin();
                                    } else if (!suppliers.length) {
                                        if (chk) chk.checked = false;
                                        if (hiddenHasF) hiddenHasF.value = '0';
                                        if (details) details.classList.add('hidden');
                                        alert('Aucun fournisseur valide disponible. Faites valider le fournisseur en base avant de l’utiliser dans une FEB.');
                                    }
                                }
                                applySupplierToBesoin();
                                syncSupplierRequirement();

                                function refreshTitle() {
                                    const lib = (inputLib && inputLib.value || '').trim();
                                    const qty = (inputQty && inputQty.value || '').trim();
                                    title.textContent = lib ? (lib + (qty ? ` (x${qty})` : '')) : ('Besoin ' + (idx + 1));
                                    if (lineTotalOutput) lineTotalOutput.textContent = Number((Number(qty || 0) * Number(supplierPriceInput && supplierPriceInput.value || 0))).toLocaleString('fr-FR') + ' FCFA';
                                    refreshSummary();
                                }

                                if (inputLib) inputLib.addEventListener('input', refreshTitle);
                                if (inputQty) inputQty.addEventListener('input', refreshTitle);
                                if (supplierSelect) supplierSelect.addEventListener('change', () => { applySupplierToBesoin(); refreshSummary(); });
                                if (supplierPriceInput) supplierPriceInput.addEventListener('input', refreshTitle);
                                if (lineProofInput) lineProofInput.addEventListener('change', refreshSummary);
                                if (chk) chk.addEventListener('change', syncSupplierRequirement);

                                del.addEventListener('click', () => {
                                    wrap.remove();
                                    refreshSummary();

                                    // Si on supprime le besoin actif, activer le premier restant
                                    if (activeBesoinIdx === String(idx)) {
                                        const first = container ? container.firstElementChild : null;
                                        if (first) setActiveBesoin(first);
                                        else {
                                            activeBesoinIdx = null;
                                            refreshSummary();
                                        }
                                    }
                                });

                                header.addEventListener('click', (e) => {
                                    if (e.target && e.target.closest && e.target.closest('button')) return;
                                    setActiveBesoin(wrap);
                                });

                                wrap.addEventListener('focusin', () => setActiveBesoin(wrap));

                                refreshTitle();
                                return wrap;
                            }

                            function refreshSummary() {
                                if (!summary) return;
                                summary.innerHTML = '';
                                const items = Array.from(container ? container.children : []);
                                items.forEach((node, i) => {
                                    const li = document.createElement('li');
                                    const isActive = (node.dataset.idx || null) === activeBesoinIdx;
                                    li.className = 'rounded-xl border px-3 py-2 cursor-pointer ' + (isActive ? 'border-indigo-300 bg-indigo-50' : 'border-slate-200 bg-white');
                                    const libEl = node.querySelector('input[name="besoin_libelle[]"]');
                                    const qtyEl = node.querySelector('input[name="besoin_quantite[]"]');
                                    const lib = (libEl && libEl.value || '').trim() || ('Besoin ' + (i + 1));
                                    const qty = (qtyEl && qtyEl.value || '').trim();
                                    li.textContent = qty ? `${lib} (x${qty})` : lib;

                                    li.addEventListener('click', () => setActiveBesoin(node));
                                    summary.appendChild(li);
                                });
                                const total = items.reduce((sum, node) => {
                                    const qty = Number(node.querySelector('input[name="besoin_quantite[]"]')?.value || 0);
                                    const price = Number(node.querySelector('input[name="besoin_fournisseur_prix[]"]')?.value || 0);
                                    return sum + qty * price;
                                }, 0);
                                const amountInput = document.getElementById('feb_montant');
                                if (amountInput && total > 0) amountInput.value = String(total);
                            }

                            if (addBtn) {
                                addBtn.addEventListener('click', () => {
                                    if (!container) return;
                                    const item = createBesoinItem({});
                                    container.appendChild(item);
                                    setActiveBesoin(item);
                                    refreshSummary();
                                });
                            }

                            // Initialisation: besoins existants ou 1 par défaut
                            if (container) {
                                const initial = Array.isArray(init.besoins) ? init.besoins : [];
                                if (initial.length) {
                                    initial.forEach(b => container.appendChild(createBesoinItem(b || {})));
                                } else {
                                    container.appendChild(createBesoinItem({}));
                                }
                                const first = container.firstElementChild;
                                if (first) setActiveBesoin(first);
                                else refreshSummary();
                            }

                            const quoteImportFile = document.getElementById('febQuoteImportFile');
                            const quoteImportStatus = document.getElementById('febQuoteImportStatus');
                            quoteImportFile && quoteImportFile.addEventListener('change', async () => {
                                const file = quoteImportFile.files && quoteImportFile.files[0];
                                if (!file || !container) return;
                                if (quoteImportStatus) quoteImportStatus.textContent = 'Import en cours...';
                                try {
                                    const body = new FormData(); body.append('quote_file', file);
                                    const response = await fetch('../request/import_feb_quote.php', { method: 'POST', body, headers: { 'Accept': 'application/json' } });
                                    const json = await response.json();
                                    if (!response.ok || !json.ok) throw new Error(json.error || 'Import impossible');
                                    container.innerHTML = '';
                                    (json.lines || []).forEach(line => container.appendChild(createBesoinItem(line)));
                                    const importedSupplierId=String(json.lines?.[0]?.fournisseur_id||'');
                                    const globalSupplier=document.getElementById('febGlobalSupplier');
                                    if(globalSupplier&&importedSupplierId){globalSupplier.value=importedSupplierId;globalSupplier.dispatchEvent(new Event('change',{bubbles:true}));}
                                    const titleInput = document.querySelector('input[name="feb_quote_title"]');
                                    if (titleInput && json.title) titleInput.value = json.title;
                                    const first = container.firstElementChild; if (first) setActiveBesoin(first);
                                    refreshSummary();
                                    if (quoteImportStatus) quoteImportStatus.textContent = `${json.lines.length} ligne(s) importée(s)${Number(json.duplicates_ignored||0)>0?' · '+Number(json.duplicates_ignored)+' doublon(s) ignoré(s)':''}.`;
                                } catch (error) {
                                    if (quoteImportStatus) quoteImportStatus.textContent = error.message || 'Import impossible.';
                                } finally { quoteImportFile.value = ''; }
                            });

                            // ——————————————————————————
                            // Justificatifs + contrôle OCR
                            // ——————————————————————————
                            const dropzone = document.getElementById('febDropzone');
                            const fileInput = document.getElementById('febFiles');
                            const invoiceBlock = document.getElementById('febInvoiceBlock');
                            const invoiceInput = document.getElementById('febInvoiceFile');
                            const invoiceNumber = document.getElementById('febInvoiceNumber');
                            const invoiceSupplier = document.getElementById('febInvoiceSupplierId');
                            const supportingDocumentNumber = document.getElementById('febSupportingDocumentNumber');
                            const invoiceRemoveCheckbox = document.querySelector('input[name="remove_existing_invoice_document"]');
                            const externalBlock = document.getElementById('febExternalBlock');
                            const pvBlock = document.getElementById('febPvBlock');
                            const pvInput = document.getElementById('febPvFile');
                            const pvNumber = document.getElementById('febPvNumber');
                            const pvRemoveCheckbox = document.querySelector('input[name="remove_existing_pv_reception_document"]');
                            const preview = document.getElementById('febPreview');
                            const summaryBox = document.getElementById('febAiSummary');
                            const elAcc = document.getElementById('febAiAcc');
                            const elRef = document.getElementById('febAiRef');
                            const elPend = document.getElementById('febAiPend');
                            const aiHidden = document.getElementById('ai_checks_json');

                            const modal = document.getElementById('febAiModal');
                            const modalBody = document.getElementById('febAiModalBody');
                            const modalClose = document.getElementById('febAiModalClose');

                            const ocrFlagEl = document.getElementById('feb_ocr_enabled');
                            const OCR_ENABLED = !ocrFlagEl ? true : String(ocrFlagEl.value || '1') === '1';

                            const CHECK_URL = '../api/check_image_justificatif_feb.php';
                            const MAX_CONCURRENT = 2;
                            let inFlight = 0;
                            const queue = [];

                            const aiStatus = new Map(); // key -> { state, json }
                            let filesSelected = [];

                            const fileStore = (() => {
                                try {
                                    return new DataTransfer();
                                } catch (e) {
                                    return null;
                                }
                            })();

                            function syncInputFiles() {
                                if (!fileInput) return;
                                if (!fileStore) return;
                                fileInput.files = fileStore.files;
                            }

                            function selectedNeedScope() {
                                const checked = document.querySelector('input[name="feb_need_scope"]:checked');
                                return checked ? String(checked.value || '') : '';
                            }

                            function updateNeedScopeDisplay() {
                                const scope = selectedNeedScope();
                                const isInternal = scope === 'internal';
                                const isExternal = scope === 'external';

                                if (pvBlock) pvBlock.classList.toggle('hidden', !isInternal);
                                if (externalBlock) externalBlock.classList.toggle('hidden', !isExternal);

                                if (pvNumber) pvNumber.required = false;

                                if (pvInput) {
                                    const hasExistingPv = !!pvRemoveCheckbox;
                                    pvInput.required = false;
                                }

                                document.querySelectorAll('input[name="cashflow_need"]').forEach((radio) => {
                                    radio.required = isExternal;
                                });

                                updateInvoiceRequirement();
                                updateAiSummary();
                            }

                            function updateInvoiceRequirement() {
                                const scope = selectedNeedScope();
                                const checked = document.querySelector('input[name="cashflow_need"]:checked');

                                const needsInvoice = scope === 'external' &&
                                    !!checked &&
                                    checked.value === 'internal_purchase_order';

                                const hasExistingInvoice = !!invoiceRemoveCheckbox;

                                const mustUploadInvoice = false;

                                const supportingNumberBlock = document.getElementById('febSupportingNumberBlock');

                                // Le justificatif image est obligatoire dans tous les cas FEB.
                                if (supportingNumberBlock) {
                                    supportingNumberBlock.classList.remove('hidden');
                                }

                                if (dropzone) {
                                    dropzone.classList.remove('hidden');
                                }

                                if (fileInput) {
                                    fileInput.required = false;
                                }

                                if (supportingDocumentNumber) {
                                    supportingDocumentNumber.required = false;
                                }

                                // Facture uniquement pour demande externe + décaissement unique sur facture.
                                if (invoiceBlock) {
                                    invoiceBlock.classList.toggle('hidden', !needsInvoice);
                                }

                                if (invoiceInput) {
                                    invoiceInput.required = mustUploadInvoice;
                                }

                                if (invoiceNumber) invoiceNumber.required = false;
                                if (invoiceSupplier) invoiceSupplier.required = false;
                            }
                            document.querySelectorAll('input[name="cashflow_need"]').forEach((radio) => {
                                radio.addEventListener('change', () => {
                                    updateInvoiceRequirement();
                                    updateNeedScopeDisplay();
                                });
                            });
                            document.querySelectorAll('input[name="feb_need_scope"]').forEach((radio) => {
                                radio.addEventListener('change', updateNeedScopeDisplay);
                            });
                            if (invoiceRemoveCheckbox) {
                                invoiceRemoveCheckbox.addEventListener('change', updateInvoiceRequirement);
                            }
                            if (pvRemoveCheckbox) {
                                pvRemoveCheckbox.addEventListener('change', updateNeedScopeDisplay);
                            }
                            updateNeedScopeDisplay();

                            function canAddMoreFiles(countToAdd) {
                                const currentCount = fileStore ? fileStore.files.length : (fileInput ? fileInput.files.length : filesSelected.length);
                                return (currentCount + countToAdd) <= 10;
                            }

                            function keyOf(file) {
                                return `${file.name}__${file.size}`;
                            }

                            function updateAiSummary() {
                                let acc = 0,
                                    ref = 0,
                                    pend = 0;
                                aiStatus.forEach(v => {
                                    if (v.state === 'accept') acc++;
                                    else if (v.state === 'refuse') ref++;
                                    else pend++;
                                });
                                if (elAcc) elAcc.textContent = String(acc);
                                if (elRef) elRef.textContent = String(ref);
                                if (elPend) elPend.textContent = String(pend);
                                if (summaryBox) summaryBox.classList.remove('hidden');

                                // Les justificatifs ne sont plus obligatoires dans le nouveau parcours.
                                // S'ils sont ajoutés, attendre néanmoins la fin de leur contrôle.
                                const allow = ref === 0 && pend === 0;
                                if (submitBtn) {
                                    submitBtn.dataset.aiReady = allow ? '1' : '0';
                                    updateStepActions();
                                }
                            }

                            function openModal(html) {
                                if (!modal || !modalBody) return;
                                modalBody.innerHTML = html;
                                modal.classList.remove('hidden');
                                modal.classList.add('flex');
                                document.body.style.overflow = 'hidden';
                            }

                            function closeModal() {
                                if (!modal) return;
                                modal.classList.add('hidden');
                                modal.classList.remove('flex');
                                document.body.style.overflow = '';
                            }

                            if (modalClose) modalClose.addEventListener('click', closeModal);
                            if (modal) modal.addEventListener('click', (e) => {
                                if (e.target === modal) closeModal();
                            });

                            async function fetchJsonWithTimeout(url, options, timeoutMs = 45000) {
                                const controller = new AbortController();
                                const t = setTimeout(() => controller.abort(), timeoutMs);
                                try {
                                    const res = await fetch(url, {
                                        ...options,
                                        signal: controller.signal,
                                        headers: {
                                            ...(options.headers || {}),
                                            'Accept': 'application/json'
                                        }
                                    });
                                    if (!res.ok) {
                                        const txt = await res.text().catch(() => '');
                                        throw new Error(`HTTP ${res.status}: ${txt}`);
                                    }
                                    return await res.json();
                                } finally {
                                    clearTimeout(t);
                                }
                            }

                            async function runCheck(file, ui) {
                                if (!OCR_ENABLED) {
                                    const json = {
                                        ok: true,
                                        decision: 'ACCEPTE',
                                        comment: 'Contrôle OCR désactivé',
                                        reasons: [],
                                        classification: {
                                            class: 'bypass',
                                            signals: ['ocr_disabled']
                                        },
                                        ocr_text: ''
                                    };

                                    aiStatus.set(keyOf(file), {
                                        state: 'accept',
                                        json
                                    });
                                    updateAiSummary();

                                    ui.badge.classList.remove('bg-amber-100', 'text-amber-800', 'border-amber-200');
                                    ui.badge.classList.add('bg-emerald-100', 'text-emerald-800', 'border-emerald-200');
                                    ui.badge.textContent = 'Acceptée';
                                    ui.comment.textContent = 'Contrôle OCR désactivé.';
                                    ui.reasons.innerHTML = '';
                                    ui.detailsBtn.disabled = false;
                                    ui.detailsBtn.addEventListener('click', () => {
                                        const html = `
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                            <div><span class="font-semibold">Décision:</span> ACCEPTE</div>
                                            <div class="mt-1"><span class="font-semibold">Classe:</span> bypass</div>
                                            <div class="mt-2"><span class="font-semibold">Commentaire:</span> Contrôle OCR désactivé</div>
                                            <div class="mt-2 text-xs text-slate-500">Aucun texte OCR n'est calculé tant que ce réglage est désactivé.</div>
                                        </div>`;
                                        openModal(html);
                                    }, {
                                        once: true
                                    });
                                    return;
                                }

                                const fd = new FormData();
                                fd.append('doc_type', 'JUSTIFICATIF_FEB');
                                fd.append('image', file);

                                aiStatus.set(keyOf(file), {
                                    state: 'pending',
                                    json: null
                                });
                                updateAiSummary();

                                try {
                                    const json = await fetchJsonWithTimeout(CHECK_URL, {
                                        method: 'POST',
                                        body: fd
                                    }, 60000);
                                    const accepted = (String(json.decision || '').toUpperCase() === 'ACCEPTE');
                                    aiStatus.set(keyOf(file), {
                                        state: accepted ? 'accept' : 'refuse',
                                        json
                                    });

                                    ui.badge.classList.remove('bg-amber-100', 'text-amber-800', 'border-amber-200');
                                    if (accepted) {
                                        ui.badge.classList.add('bg-emerald-100', 'text-emerald-800', 'border-emerald-200');
                                        ui.badge.textContent = 'Acceptée';
                                    } else {
                                        ui.badge.classList.add('bg-slate-100', 'text-slate-700', 'border-slate-200');
                                        ui.badge.textContent = 'Refusée';
                                    }

                                    const reasons = Array.isArray(json.reasons) ? json.reasons : [];
                                    const comment = (String(json.comment || '')).trim();
                                    ui.comment.textContent = comment || (accepted ? 'Accepté' : 'Refusé');
                                    ui.reasons.innerHTML = reasons.length ? reasons.map(r => `<li>${String(r.message || '')}</li>`).join('') : '';

                                    ui.detailsBtn.disabled = false;
                                    ui.detailsBtn.addEventListener('click', () => {
                                        const cls = (json.classification && json.classification.class) ? String(json.classification.class) : 'autre';
                                        const signals = (json.classification && Array.isArray(json.classification.signals)) ? json.classification.signals.map(String) : [];
                                        const ocr = (String(json.ocr_text || '')).trim();
                                        const ocrHtml = ocr ? `<div class="mt-3">
                                        <div class="flex items-center justify-between"><div class="font-semibold">Texte OCR</div>
                                        <button type="button" id="copyOcr" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">Copier</button></div>
                                        <pre class="mt-2 max-h-64 overflow-auto rounded-2xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-800 whitespace-pre-wrap"></pre>
                                    </div>` : '';
                                        const html = `
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                            <div><span class="font-semibold">Décision:</span> ${accepted ? 'ACCEPTE' : 'REFUSE'}</div>
                                            <div class="mt-1"><span class="font-semibold">Classe:</span> ${cls}</div>
                                            ${signals.length ? `<div class="mt-2"><span class="font-semibold">Signaux:</span><ul class="mt-1 list-disc pl-5">${signals.map(s=>`<li>${s}</li>`).join('')}</ul></div>` : ''}
                                            ${comment ? `<div class="mt-2"><span class="font-semibold">Commentaire:</span> ${comment}</div>` : ''}
                                            ${reasons.length ? `<div class="mt-2"><span class="font-semibold">Raisons:</span><ul class="mt-1 list-disc pl-5">${reasons.map(r=>`<li>${String(r.message || '')}</li>`).join('')}</ul></div>` : ''}
                                        </div>
                                        ${ocrHtml}
                                    `;
                                        openModal(html);
                                        const pre = modalBody ? modalBody.querySelector('pre') : null;
                                        if (pre && ocr) pre.textContent = ocr;
                                        const btn = modalBody ? modalBody.querySelector('#copyOcr') : null;
                                        if (btn && ocr) {
                                            btn.addEventListener('click', async () => {
                                                try {
                                                    await navigator.clipboard.writeText(ocr);
                                                    btn.textContent = 'Copié';
                                                    setTimeout(() => btn.textContent = 'Copier', 1200);
                                                } catch (e) {
                                                    // ignore
                                                }
                                            });
                                        }
                                    }, {
                                        once: true
                                    });
                                } catch (e) {
                                    aiStatus.set(keyOf(file), {
                                        state: 'refuse',
                                        json: {
                                            decision: 'REFUSE',
                                            comment: 'Erreur OCR/API',
                                            reasons: [{
                                                message: 'Impossible d\'analyser cette image. Réessayez.'
                                            }]
                                        }
                                    });
                                    ui.badge.classList.remove('bg-amber-100', 'text-amber-800', 'border-amber-200');
                                    ui.badge.classList.add('bg-slate-100', 'text-slate-700', 'border-slate-200');
                                    ui.badge.textContent = 'Erreur';
                                    ui.comment.textContent = 'Analyse impossible.';
                                    ui.reasons.innerHTML = '<li>Erreur OCR/API</li>';
                                }

                                updateAiSummary();
                            }

                            function pumpQueue() {
                                while (inFlight < MAX_CONCURRENT && queue.length) {
                                    const job = queue.shift();
                                    inFlight++;
                                    runCheck(job.file, job.ui).finally(() => {
                                        inFlight--;
                                        pumpQueue();
                                    });
                                }
                            }

                            function enqueue(file, ui) {
                                queue.push({
                                    file,
                                    ui
                                });
                                pumpQueue();
                            }

                            function addFile(file) {
                                if (!preview) return;

                                const item = document.createElement('div');
                                item.className = 'rounded-2xl border border-slate-200 bg-white px-4 py-4';

                                const top = document.createElement('div');
                                top.className = 'flex items-start justify-between gap-3';
                                const left = document.createElement('div');
                                left.className = 'flex items-center gap-3';
                                const img = document.createElement('img');
                                img.className = 'h-16 w-20 rounded-xl border border-slate-200 object-cover bg-slate-50';
                                const meta = document.createElement('div');
                                meta.innerHTML = `<div class="text-sm font-semibold text-slate-900"></div><div class="text-xs text-slate-500"></div>`;
                                meta.children[0].textContent = file.name;
                                meta.children[1].textContent = `${Math.round(file.size / 1024)} Ko`;
                                left.appendChild(img);
                                left.appendChild(meta);

                                const right = document.createElement('div');
                                right.className = 'flex items-center gap-2';
                                const badge = document.createElement('span');
                                badge.className = 'inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold bg-amber-100 text-amber-800 border-amber-200';
                                badge.textContent = 'Analyse...';
                                const detailsBtn = document.createElement('button');
                                detailsBtn.type = 'button';
                                detailsBtn.disabled = true;
                                detailsBtn.className = 'rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 disabled:opacity-50';
                                detailsBtn.textContent = 'Détails';
                                const delBtn = document.createElement('button');
                                delBtn.type = 'button';
                                delBtn.className = 'rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50';
                                delBtn.innerHTML = '<i class="fas fa-trash"></i>';
                                right.appendChild(badge);
                                right.appendChild(detailsBtn);
                                right.appendChild(delBtn);

                                top.appendChild(left);
                                top.appendChild(right);

                                const comment = document.createElement('div');
                                comment.className = 'mt-3 text-sm text-slate-700';
                                const reasonsWrap = document.createElement('ul');
                                reasonsWrap.className = 'mt-2 list-disc pl-5 text-xs text-slate-500';

                                item.appendChild(top);
                                item.appendChild(comment);
                                item.appendChild(reasonsWrap);
                                preview.appendChild(item);

                                // Preview image
                                const reader = new FileReader();
                                reader.onload = (e) => {
                                    img.src = String(e.target && e.target.result || '');
                                };
                                reader.readAsDataURL(file);

                                const ui = {
                                    badge,
                                    detailsBtn,
                                    comment,
                                    reasons: reasonsWrap
                                };

                                delBtn.addEventListener('click', () => {
                                    const k = keyOf(file);
                                    aiStatus.delete(k);
                                    filesSelected = filesSelected.filter(f => !(f.name === file.name && f.size === file.size));

                                    if (fileStore) {
                                        for (let i = 0; i < fileStore.items.length; i++) {
                                            const f = fileStore.items[i].getAsFile();
                                            if (!f) continue;
                                            if (f.name === file.name && f.size === file.size) {
                                                fileStore.items.remove(i);
                                                break;
                                            }
                                        }
                                        syncInputFiles();
                                    }

                                    item.remove();
                                    updateAiSummary();
                                });

                                enqueue(file, ui);
                            }

                            function handleFiles(fileList) {
                                const arr = Array.from(fileList || []).filter(f => f && f.type && f.type.startsWith('image/'));
                                if (!arr.length) return;

                                // Limites client (serveur refait aussi les checks)
                                const maxSize = 12 * 1024 * 1024;
                                const valid = arr.filter(f => f.size <= maxSize);
                                if (!valid.length) {
                                    alert('Aucun fichier valide (max 12 Mo / image).');
                                    return;
                                }

                                const canAccumulate = !!fileStore;
                                if (!canAccumulate) {
                                    // Mode dégradé: pas d'accumulation fiable -> la sélection remplace l'existant
                                    filesSelected = [];
                                    aiStatus.clear();
                                    if (preview) preview.innerHTML = '';
                                }

                                const unique = valid.filter(f => !filesSelected.some(x => x.name === f.name && x.size === f.size));
                                if (!unique.length) return;

                                if (!canAddMoreFiles(unique.length)) {
                                    alert('Maximum 10 justificatifs.');
                                    return;
                                }

                                unique.forEach(f => {
                                    filesSelected.push(f);

                                    if (fileStore) {
                                        fileStore.items.add(f);
                                        syncInputFiles();
                                    }

                                    addFile(f);
                                });
                                updateAiSummary();
                            }

                            if (dropzone && fileInput) {
                                dropzone.addEventListener('click', () => {
                                    // Permet de re-sélectionner le même fichier et force l'événement change.
                                    // IMPORTANT: ne pas vider après le change sinon le submit n'envoie plus rien.
                                    if (fileStore) fileInput.value = '';
                                    fileInput.click();
                                });
                                dropzone.addEventListener('dragover', (e) => {
                                    e.preventDefault();
                                    dropzone.classList.add('border-indigo-300');
                                });
                                dropzone.addEventListener('dragleave', () => dropzone.classList.remove('border-indigo-300'));
                                dropzone.addEventListener('drop', (e) => {
                                    e.preventDefault();
                                    dropzone.classList.remove('border-indigo-300');
                                    if (!fileStore) {
                                        alert('Glisser-déposer non supporté sur ce navigateur. Utilisez le clic pour choisir les fichiers.');
                                        return;
                                    }
                                    handleFiles(e.dataTransfer.files);
                                });
                                fileInput.addEventListener('change', (e) => {
                                    handleFiles(e.target.files);
                                    // Ne pas vider ici: sinon le navigateur n'envoie plus les fichiers au submit.
                                });
                            }

                            // Au submit: sérialiser les checks OCR acceptés
                            const form = submitBtn ? submitBtn.closest('form') : null;

                            if (form) {
                                form.addEventListener('submit', (e) => {
                                    const assistantSubmission = document.getElementById('febAssistantMode')?.value === '1';
                                    if (assistantSubmission) {
                                        // L’assistant effectue son propre audit exhaustif et doit rester
                                        // maître de l’interface. Ne pas faire défiler le wizard classique
                                        // ni afficher ses alertes derrière la fenêtre conversationnelle.
                                        syncInputFiles();
                                        if (aiHidden && !filesSelected.length) aiHidden.value = '[]';
                                        return;
                                    }
                                    // La validation native ne sait pas focaliser les champs des autres
                                    // étapes ni les selects natifs remplacés par une recherche visuelle.
                                    // Valider chaque écran explicitement et ouvrir celui qui contient
                                    // la première erreur.
                                    for (let stepIndex = 0; stepIndex < steps.length; stepIndex++) {
                                        showStep(stepIndex);
                                        if (!validateCurrentStep() || (window.FebDisbursementFlow && !window.FebDisbursementFlow.validate(stepIndex))) {
                                            e.preventDefault();
                                            return;
                                        }
                                    }

                                    syncInputFiles();

                                    // Le nouveau workflow FEB ne demande aucun document.
                                    if (!filesSelected.length) {
                                        if (aiHidden) aiHidden.value = '[]';
                                        return;
                                    }

                                    const scope = selectedNeedScope();

                                    let pend = 0;
                                    let ref = 0;
                                    let acc = 0;

                                    aiStatus.forEach(v => {
                                        if (v.state === 'pending') pend++;
                                        else if (v.state === 'refuse') ref++;
                                        else acc++;
                                    });

                                    if (pend > 0) {
                                        e.preventDefault();
                                        alert('Analyse OCR en cours : patientez...');
                                        return;
                                    }

                                    if (ref > 0) {
                                        e.preventDefault();
                                        alert('Un ou plusieurs justificatifs sont refusés.');
                                        return;
                                    }

                                    if (acc === 0) {
                                        e.preventDefault();
                                        alert('Aucun justificatif accepté.');
                                        return;
                                    }

                                    const payload = [];

                                    aiStatus.forEach((v, k) => {
                                        const json = v.json || {};
                                        const parts = k.split('__');
                                        const name = parts.slice(0, parts.length - 1).join('__');
                                        const size = parseInt(parts[parts.length - 1] || '0', 10) || 0;

                                        payload.push({
                                            name,
                                            size,
                                            decision: String(json.decision || '').toUpperCase(),
                                            comment: String(json.comment || ''),
                                            classification: json.classification || null
                                        });
                                    });

                                    if (aiHidden) {
                                        aiHidden.value = JSON.stringify(payload);
                                    }
                                });
                            }

                            function classicCorrectionStep(errorList) {
                                const message = String((errorList && errorList[0]) || '').toLowerCase();
                                if (/titre.*devis/.test(message)) return 0;
                                if (/bénéficiaire|beneficiaire/.test(message)) return 3;
                                if (/budget|chantier|débours|debours|ligne de devis/.test(message)) return 4;
                                if (/mode de paiement|paiement/.test(message)) return 5;
                                if (/planning|date/.test(message)) return 6;
                                if (/fréquence|frequence|montant/.test(message)) return 2;
                                if (/besoin|devis|fournisseur|prix|quantit|justificatif/.test(message)) return 1;
                                if (/description|livraison/.test(message)) return 2;
                                return 0;
                            }

                            // Conserver le mode choisi : le classique reste dans son wizard et
                            // ouvre directement l’écran portant la première erreur du serveur.
                            showStep(febServerErrors.length && !submittedViaAssistant ? classicCorrectionStep(febServerErrors) : 0);
                            updateAiSummary();
                        })();
                    </script>
                    <script>
                        (() => {
                            const choices = Array.from(document.querySelectorAll('input[name="draft_fd_beneficiary_choice"]'));
                            const supplierBlock = document.getElementById('febBeneficiarySupplierBlock');
                            const supplierSelect = document.getElementById('draftFdFournisseur');
                            const globalSupplierSelect = document.getElementById('febGlobalSupplier');
                            const beneficiaryPreview = document.getElementById('febBeneficiaryPreview');
                            const completion = document.getElementById('draftSupplierCompletion');
                            const suppliers = window.__FEB_SUPPLIERS__ || [];
                            const renderBeneficiaryPreview = () => {
                                if (!beneficiaryPreview) return;
                                if (window.FebDisbursementFlow) { window.FebDisbursementFlow.render(3); return; }
                                const supplierMode = choices.find(input => input.checked)?.value === 'supplier';
                                const supplier = suppliers.find(item => String(item.id) === String(globalSupplierSelect?.value || ''));
                                const requesterName = beneficiaryPreview.dataset.requesterName || 'Demandeur';
                                const name = supplierMode ? (supplier?.name || 'Fournisseur à sélectionner') : requesterName;
                                const role = supplierMode ? 'Fournisseur bénéficiaire de la FEB' : 'Demandeur et bénéficiaire de la FEB';
                                beneficiaryPreview.replaceChildren();
                                const row = document.createElement('div'); row.className = 'flex items-center gap-3';
                                if (supplierMode && supplier?.photo) { const image = document.createElement('img'); image.src = supplier.photo; image.alt = 'Photo du fournisseur'; image.className = 'h-12 w-12 rounded-xl border object-cover'; image.onerror = () => image.remove(); row.appendChild(image); }
                                else { const avatar = document.createElement('span'); avatar.className = 'flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-100 font-black text-indigo-700'; avatar.textContent = (name || '?').charAt(0).toUpperCase(); row.appendChild(avatar); }
                                const text = document.createElement('span'); const strong = document.createElement('strong'); strong.className = 'block text-slate-950'; strong.textContent = name; const small = document.createElement('small'); small.className = 'block text-xs text-slate-500'; small.textContent = role; text.append(strong, small); row.appendChild(text); beneficiaryPreview.appendChild(row);
                            };
                            const renderCompletion = () => {
                                if (!completion) return;
                                const supplierMode = choices.find(input => input.checked)?.value === 'supplier';
                                if (!supplierMode) { completion.innerHTML=''; return; }
                                const supplier=suppliers.find(item=>String(item.id)===String(globalSupplierSelect?.value||''));
                                if(!supplier){completion.innerHTML='';return;}
                                const missing=[];
                                if(!String(supplier.name||supplier.legal_name||'').trim())missing.push(['draft_supplier_completion_name','Nom commercial','text']);
                                if(!String(supplier.phone||'').trim())missing.push(['draft_supplier_completion_phone','Téléphone','tel']);
                                if(!String(supplier.email||'').trim())missing.push(['draft_supplier_completion_email','E-mail','email']);
                                if(!String(supplier.address||'').trim())missing.push(['draft_supplier_completion_address','Adresse','text']);
                                if(!String(supplier.photo||'').trim())missing.push(['draft_supplier_completion_photo','Photo ou logo','file']);
                                completion.innerHTML=missing.length?`<span class="block rounded-xl border border-amber-200 bg-amber-50 p-3"><strong class="text-sm text-amber-950">Compléter le fournisseur</strong><span class="mt-1 block text-xs text-amber-800">Les informations seront enregistrées dans sa fiche fournisseur lors de la soumission.</span><span class="mt-3 grid gap-2">${missing.map(([name,label,type])=>`<label class="text-xs font-bold text-slate-600">${label}<input name="${name}" type="${type}" ${type==='file'?'accept="image/jpeg,image/png,image/webp"':''} required class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm"></label>`).join('')}</span></span>`:'';
                            };
                            const syncBeneficiaryChoice = () => {
                                const selected = choices.find(input => input.checked)?.value || 'requester';
                                const supplierMode = selected === 'supplier';
                                supplierBlock?.classList.toggle('hidden', !supplierMode);
                                supplierBlock?.classList.toggle('block', supplierMode);
                                if (supplierSelect) {
                                    supplierSelect.required = supplierMode;
                                    supplierSelect.disabled = !supplierMode;
                                    if (!supplierMode) supplierSelect.value = '';
                                }
                                renderCompletion();
                                renderBeneficiaryPreview();
                            };
                            choices.forEach(input => input.addEventListener('change', syncBeneficiaryChoice));
                            supplierSelect?.addEventListener('change',renderCompletion);
                            globalSupplierSelect?.addEventListener('change', () => { renderCompletion(); renderBeneficiaryPreview(); });
                            syncBeneficiaryChoice();
                        })();
                    </script>
                    <?php if ((int)($_GET['flow_success'] ?? 0) > 0): ?>
                    <div class="feb-flow-success" role="dialog" aria-modal="true" aria-labelledby="febFlowSuccessTitle">
                        <div class="feb-flow-success-card">
                            <div class="feb-flow-success-icon"><i class="fas fa-check"></i></div>
                            <p class="feb-flow-success-kicker">Demande transmise</p>
                            <h1 id="febFlowSuccessTitle">Votre FEB #<?= (int)$_GET['flow_success'] ?> est envoyée</h1>
                            <p>Le service Finances peut maintenant examiner votre demande. Vous pouvez conserver une copie PDF ou poursuivre votre travail.</p>
                            <div class="feb-flow-success-actions">
                                <a href="../request/export_feb_submission_pdf.php?id=<?= (int)$_GET['flow_success'] ?>" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Voir le PDF</a>
                                <a href="nouvelle_demande_employe.php?type=feb&fresh=1"><i class="fas fa-plus"></i> Soumettre une autre FEB</a>
                                <a href="dashboard_employe.php"><i class="fas fa-house"></i> Espace employé</a>
                                <a href="../selection_portail.php"><i class="fas fa-table-cells"></i> Portail</a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($type === 'feb' && $febTextGenerationEnabled): ?>
                    <script src="feb/js/assistant.js?v=<?= htmlspecialchars($febAssetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
                <?php endif; ?>
                <?php if ($type === 'feb' && $febAiDetectionEnabled): ?>
                <script>
                (()=>{
                    const csrf=<?= json_encode($febAiDetectionCsrf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
                    const fields=[['feb_description','feb_description'],['feb_impact','feb_impact']];
                    const blocked=new Map(); const timers=new Map();
                    const syncBlock=()=>{const stop=[...blocked.values()].some(Boolean);const next=document.getElementById('febNext');if(next){next.disabled=stop;next.classList.toggle('opacity-40',stop);next.title=stop?'Texte détecté comme généré par IA au-dessus du seuil configuré.':'';}const form=document.getElementById('febMainForm');if(form&&!form.dataset.aiDetectionBound){form.dataset.aiDetectionBound='1';form.addEventListener('submit',e=>{if([...blocked.values()].some(Boolean)){e.preventDefault();alert('La soumission est bloquée : modifiez les textes signalés comme générés par IA.');}})}};
                    const check=async(id,field)=>{const input=document.getElementById(id),meter=document.querySelector(`[data-ai-meter="${field}"]`);if(!input||!meter)return;const label=meter.querySelector('[data-ai-meter-label]'),bar=meter.querySelector('[data-ai-meter-bar]');const text=input.value.trim();if(text.length<40){label.textContent='Texte trop court';bar.style.width='0%';blocked.set(field,false);syncBlock();return;}label.textContent='Analyse…';try{const body=new URLSearchParams({csrf_token:csrf,text,field});const response=await fetch('feb_ai_detection.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Analyse impossible');const human=Number(data.human_score);label.textContent=`${human.toFixed(1)} % humain · ${Number(data.score).toFixed(1)} % IA${data.mode==='local'?' · local gratuit':''}`;bar.style.width=`${human}%`;bar.className=`h-full transition-all ${data.blocked?'bg-rose-500':human>=60?'bg-emerald-500':'bg-amber-500'}`;meter.classList.toggle('border-rose-300',!!data.blocked);meter.title=data.message||'';blocked.set(field,!!data.blocked);syncBlock();}catch(error){label.textContent=error.message;blocked.set(field,false);syncBlock();}};
                    fields.forEach(([id,field])=>{const input=document.getElementById(id);if(!input)return;input.addEventListener('input',()=>{clearTimeout(timers.get(field));timers.set(field,setTimeout(()=>check(id,field),900));});if(input.value.trim().length>=40)check(id,field);});
                })();
                </script>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
window.addEventListener('pageshow', (event) => {
    if (event.persisted) window.location.reload();
});
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
