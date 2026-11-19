<?php

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../model/EntityPositioningManager.php';

header('Content-Type: application/json; charset=utf-8');

$entityType = trim((string) ($_GET['entity_type'] ?? 'supplier'));
if ($entityType !== 'supplier') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Type d\'entité non supporté.']);
    exit;
}

$pdo = \Database::getConnection();
$supplierModel = new \FournisseurRepository($pdo);
$positioning = new \EntityPositioningManager($pdo);
$settings = $positioning->getSettings('supplier');

$isValidator = \AccessManager::hasPermission('acces_compta') || \AccessManager::hasPermission('acces_admin') || \AccessManager::hasPermission('acces_fournisseur');
$isEmployeeOnly = \AccessManager::hasPermission('acces_employe') && !$isValidator;
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

$options = [];
if ($isEmployeeOnly) {
    $options['created_by_user_id'] = $currentUserId;
}
$suppliers = $supplierModel->listLatest(500, false, $options);
$positions = $positioning->listPositions('supplier', array_map(static fn(array $row): int => (int) ($row['id_fournisseur'] ?? 0), $suppliers));

$items = [];
foreach ($suppliers as $supplier) {
    $supplierId = (int) ($supplier['id_fournisseur'] ?? 0);
    $position = $positions[$supplierId] ?? null;
    $items[] = [
        'id' => $supplierId,
        'name' => (string) ($supplier['supplier_display_name'] ?? $supplier['nom_commercial'] ?? $supplier['raison_sociale'] ?? 'Fournisseur'),
        'type' => (string) ($supplier['supplier_type'] ?? 'morale'),
        'type_label' => (string) ($supplier['supplier_type_label'] ?? 'Personne morale'),
        'legal_name' => (string) ($supplier['raison_sociale'] ?? ''),
        'contact' => (string) ($supplier['contact_nom'] ?? ''),
        'telephone' => (string) ($supplier['telephone'] ?? ''),
        'email' => (string) ($supplier['email'] ?? ''),
        'address' => (string) ($supplier['adresse'] ?? ''),
        'categories' => array_values($supplier['category_labels'] ?? []),
        'document_completion_percent' => (int) ($supplier['document_completion_percent'] ?? 0),
        'document_completion_label' => (string) ($supplier['document_completion_label'] ?? ''),
        'document_is_complete' => !empty($supplier['document_is_complete']),
        'missing_document_labels' => array_values($supplier['missing_document_labels'] ?? []),
        'validation_status' => (string) ($supplier['validation_status'] ?? 'pending'),
        'visual_path' => !empty($supplier['visual_path']) ? '../' . ltrim((string) $supplier['visual_path'], '/') : '',
        'position' => $position,
    ];
}

echo json_encode([
    'ok' => true,
    'settings' => $settings,
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
