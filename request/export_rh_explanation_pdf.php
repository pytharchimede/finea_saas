<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/RhExplanationRequestManager.php';

$pdo = \Database::getConnection();
$manager = new \RhExplanationRequestManager($pdo);

$requestId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($requestId <= 0) {
    http_response_code(400);
    echo 'Identifiant invalide.';
    exit;
}

$request = $manager->getRequestById($requestId);
if (!$request) {
    http_response_code(404);
    echo 'Dossier introuvable.';
    exit;
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentEmail = strtolower(trim((string) ($_SESSION['mail_pro'] ?? '')));
$canRh = !empty($_SESSION['permissions']['acces_rh'] ?? 0);
$isOwner = ((int) ($request['target_user_id'] ?? 0) === $currentUserId)
    || ($currentEmail !== '' && strtolower(trim((string) ($request['target_email'] ?? ''))) === $currentEmail);

if (!$canRh && !$isOwner) {
    http_response_code(403);
    echo 'Accès refusé.';
    exit;
}

$pdf = $manager->getRequestPdfDocument($requestId);
if (!$pdf || empty($pdf['content'])) {
    http_response_code(500);
    echo 'Impossible de générer le PDF.';
    exit;
}

$fileName = (string) ($pdf['fileName'] ?? ('demande_explication_' . $requestId . '.pdf'));
$download = !empty($_GET['download']);

\AuditTrail::logFileEvent('export', [
    'module' => $canRh ? 'rh' : 'employe',
    'action' => 'export_rh_explanation_pdf',
    'entity_type' => 'rh_explanation_request',
    'entity_id' => (string) $requestId,
    'entity_label' => (string) ($request['request_ref'] ?? ''),
    'format' => 'pdf',
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
    'details' => [
        'download_mode' => $download ? 'attachment' : 'inline',
        'filename' => $fileName,
    ],
], $pdo);

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen((string) $pdf['content']));
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . str_replace('"', '', $fileName) . '"');
echo $pdf['content'];
exit;
