<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
require_once __DIR__ . '/../model/AuditTrail.php';

$targetDir = __DIR__ . '/../uploads/ckeditor/';
$publicDir = 'uploads/ckeditor/';

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

if (
    isset($_FILES['upload']) &&
    is_uploaded_file($_FILES['upload']['tmp_name']) &&
    !empty($_FILES['upload']['name'])
) {
    $ext = strtolower(pathinfo($_FILES['upload']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed)) {
        http_response_code(400);
        \AuditTrail::log('ckeditor_image_upload_failed', [
            'module' => 'request',
            'action' => 'ckeditor_upload',
            'entity_type' => 'ckeditor_image',
            'entity_label' => (string) $_FILES['upload']['name'],
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'unsupported_extension', 'extension' => $ext],
        ], null);
        echo json_encode(["error" => ["message" => "Extension non autorisée"]]);
        exit;
    }
    $fileName = uniqid('img_') . '.' . $ext;
    $targetFile = $targetDir . $fileName;
    if (move_uploaded_file($_FILES['upload']['tmp_name'], $targetFile)) {
        \AuditTrail::log('ckeditor_image_uploaded', [
            'module' => 'request',
            'action' => 'ckeditor_upload',
            'entity_type' => 'ckeditor_image',
            'entity_label' => $fileName,
            'details' => [
                'original_name' => (string) $_FILES['upload']['name'],
                'extension' => $ext,
                'public_url' => $publicDir . $fileName,
            ],
        ], null);
        echo json_encode([
            "url" => $publicDir . $fileName
        ]);
        exit;
    }
}

http_response_code(400);
\AuditTrail::log('ckeditor_image_upload_failed', [
    'module' => 'request',
    'action' => 'ckeditor_upload',
    'entity_type' => 'ckeditor_image',
    'entity_label' => isset($_FILES['upload']['name']) ? (string) $_FILES['upload']['name'] : 'ckeditor_upload',
    'result' => 'failure',
    'severity' => 'warning',
    'details' => ['reason' => 'upload_failed'],
], null);
echo json_encode(["error" => ["message" => "Erreur upload image"]]);
