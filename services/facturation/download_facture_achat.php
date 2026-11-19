<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FacturationController.php';

$pdo = Database::getConnection();
$model = (new FacturationController($pdo))->achats();

$id = (int)($_GET['id'] ?? 0);
$facture = $id > 0 ? $model->getById($id) : null;

if (!$facture || empty($facture['file_path'])) {
    http_response_code(404);
    echo 'Fichier introuvable.';
    exit;
}

$rel = (string)$facture['file_path'];
$rel = str_replace('\\', '/', $rel);

$uploadsAbs = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
$targetAbs = realpath(__DIR__ . '/../' . $rel);

if (!is_string($targetAbs) || $targetAbs === '' || !is_file($targetAbs)) {
    http_response_code(404);
    echo 'Fichier introuvable.';
    exit;
}

$baseAllowed = realpath($uploadsAbs . DIRECTORY_SEPARATOR . 'factures_achat');
if (!is_string($baseAllowed) || $baseAllowed === '') {
    http_response_code(500);
    echo 'Dossier uploads indisponible.';
    exit;
}

$baseAllowedPrefix = rtrim($baseAllowed, "\\/") . DIRECTORY_SEPARATOR;
$targetPrefixCheck = rtrim($targetAbs, "\\/") . DIRECTORY_SEPARATOR;
if (stripos($targetPrefixCheck, $baseAllowedPrefix) !== 0) {
    http_response_code(403);
    echo 'Accès refusé.';
    exit;
}

$orig = (string)($facture['file_original_name'] ?? basename($targetAbs));
$mime = (string)($facture['file_mime'] ?? 'application/octet-stream');

\AuditTrail::logFileEvent('download', [
    'module' => 'facturation',
    'action' => 'facture_achat_download',
    'entity_type' => 'facture_achat',
    'entity_id' => (string)$id,
    'entity_label' => (string)($facture['numero_facture'] ?? $orig),
    'format' => pathinfo($orig, PATHINFO_EXTENSION),
    'details' => [
        'mime_type' => $mime,
        'stored_path' => $facture['file_path'] ?? null,
    ],
], $pdo);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($targetAbs));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $orig) . '"');

readfile($targetAbs);
exit;
