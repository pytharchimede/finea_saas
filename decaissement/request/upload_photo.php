<?php

session_start();

require_once '../model/Database.php';
require_once '../../model/AuditTrail.php';

$pdo = Database::getConnection();

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== 0) {
    AuditTrail::log('user_photo_upload_failed', [
        'module' => 'decaissement',
        'action' => 'upload_photo',
        'entity_type' => 'user_profile_photo',
        'entity_id' => isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null,
        'entity_label' => 'Upload photo profil',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'upload_error',
            'upload_error_code' => $_FILES['photo']['error'] ?? null,
        ],
    ], $pdo);
    die("Erreur upload fichier");
}

$file = $_FILES['photo'];

$allowed = ['image/jpeg', 'image/png', 'image/webp'];

if (!in_array($file['type'], $allowed)) {
    AuditTrail::log('user_photo_upload_failed', [
        'module' => 'decaissement',
        'action' => 'upload_photo',
        'entity_type' => 'user_profile_photo',
        'entity_id' => isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null,
        'entity_label' => 'Upload photo profil',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'invalid_mime_type',
            'mime_type' => $file['type'] ?? null,
        ],
    ], $pdo);
    die("Format non autorisé");
}

$uploadDir = __DIR__ . "/../../uploads/users/";

/* créer le dossier si inexistant */
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);

$filename = "user_" . $_SESSION['user_id'] . "_" . time() . "." . $ext;

$destination = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    AuditTrail::log('user_photo_upload_failed', [
        'module' => 'decaissement',
        'action' => 'upload_photo',
        'entity_type' => 'user_profile_photo',
        'entity_id' => isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null,
        'entity_label' => 'Upload photo profil',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'move_uploaded_file_failed',
            'filename' => $filename,
        ],
    ], $pdo);
    die("Erreur déplacement fichier");
}

/* chemin enregistré en base */
$dbPath = "uploads/users/" . $filename;

$stmt = $pdo->prepare("
UPDATE user_devis_banamur
SET photo = :photo
WHERE id = :id
");

$stmt->execute([
    'photo' => $dbPath,
    'id' => $_SESSION['user_id']
]);

AuditTrail::log('user_photo_uploaded', [
    'module' => 'decaissement',
    'action' => 'upload_photo',
    'entity_type' => 'user_profile_photo',
    'entity_id' => isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null,
    'entity_label' => 'Upload photo profil',
    'result' => 'success',
    'details' => [
        'photo_path' => $dbPath,
        'mime_type' => $file['type'] ?? null,
        'file_size' => $file['size'] ?? null,
    ],
], $pdo);

header("Location: ../profil.php");
exit;
