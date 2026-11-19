<?php

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../model/EntityPositioningManager.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.']);
    exit;
}

$entityType = trim((string) ($_POST['entity_type'] ?? 'supplier'));
$entityId = (int) ($_POST['entity_id'] ?? 0);
if ($entityType !== 'supplier' || $entityId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Paramètres invalides.']);
    exit;
}

$pdo = \Database::getConnection();
$supplierModel = new \FournisseurRepository($pdo);
$supplier = $supplierModel->getById($entityId);
if (!$supplier) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Fournisseur introuvable.']);
    exit;
}

$isValidator = \AccessManager::hasPermission('acces_compta') || \AccessManager::hasPermission('acces_admin') || \AccessManager::hasPermission('acces_fournisseur');
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
if (!$isValidator && (int) ($supplier['created_by_user_id'] ?? 0) !== $currentUserId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Action non autorisée sur ce fournisseur.']);
    exit;
}

$positioning = new \EntityPositioningManager($pdo);
$settings = $positioning->getSettings('supplier');
if (empty($settings['api_enabled']) || empty($settings['allow_manual_override'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Les mises à jour manuelles GPS sont désactivées.']);
    exit;
}

$latitude = $_POST['latitude'] ?? null;
$longitude = $_POST['longitude'] ?? null;
if (!is_numeric($latitude) || !is_numeric($longitude)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Latitude/longitude invalides.']);
    exit;
}

$positioning->upsertPosition('supplier', $entityId, [
    'location_label' => (string) ($_POST['location_label'] ?? ($supplier['supplier_display_name'] ?? 'Fournisseur')),
    'address_snapshot' => (string) ($_POST['address_snapshot'] ?? ($supplier['adresse'] ?? '')),
    'latitude' => (float) $latitude,
    'longitude' => (float) $longitude,
    'accuracy_meters' => $_POST['accuracy_meters'] ?? null,
    'source' => !empty($_POST['source']) ? (string) $_POST['source'] : 'browser',
], $currentUserId);

$position = $positioning->getPosition('supplier', $entityId);
echo json_encode(['ok' => true, 'position' => $position], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
