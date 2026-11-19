<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';

$pdo = \Database::getConnection();
$manager = new \EmployeeLegalRequestManager($pdo);

$requestId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$role = strtolower(trim((string) ($_GET['role'] ?? 'employee')));
if ($requestId <= 0) {
    http_response_code(400);
    echo 'Identifiant invalide.';
    exit;
}

if (!in_array($role, ['employee', 'rh', 'drh', 'dg'], true)) {
    http_response_code(400);
    echo 'Rôle invalide.';
    exit;
}

$request = $manager->getRequestById($requestId);
if (!$request) {
    http_response_code(404);
    echo 'Dossier introuvable.';
    exit;
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$canApprovalView = !empty($_SESSION['permissions']['acces_rh'] ?? 0)
    || !empty($_SESSION['permissions']['acces_direction'] ?? 0)
    || !empty($_SESSION['permissions']['acces_admin'] ?? 0);
$isOwner = (int) ($request['requester_user_id'] ?? 0) === $currentUserId;

if (!$canApprovalView && !$isOwner) {
    http_response_code(403);
    echo 'Accès refusé.';
    exit;
}

$absolutePath = $manager->getRequestSignatureAbsolutePath($requestId, $role);
if ($absolutePath === null || !is_file($absolutePath)) {
    http_response_code(404);
    echo 'Signature introuvable.';
    exit;
}

$imageSize = @getimagesize($absolutePath);
$mimeType = is_array($imageSize) && !empty($imageSize['mime']) ? (string) $imageSize['mime'] : 'application/octet-stream';
if (!in_array($mimeType, ['image/png', 'image/jpeg'], true)) {
    http_response_code(415);
    echo 'Format de signature non pris en charge.';
    exit;
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($absolutePath));
header('Content-Disposition: inline; filename="signature-' . $role . '-' . $requestId . '"');
readfile($absolutePath);
exit;
