<?php

session_start();

require_once '../model/Database.php';
require_once '../../model/AuditTrail.php';

$pdo = Database::getConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function redirectSignature(string $status, string $message): void
{
    $mandatory = !empty($_POST['mandatory_setup']);
    $target = $mandatory ? '../../signature_setup.php' : '../profil.php';
    header('Location: ' . $target . '?status=' . rawurlencode($status) . '&message=' . rawurlencode($message) . ($mandatory ? '' : '#signature'));
    exit;
}

if ($userId <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirectSignature('error', 'Session invalide.');
}

$signature = trim((string) ($_POST['signature_base64'] ?? ''));
if ($signature === '' || !preg_match('#^data:image/png;base64,#', $signature)) {
    redirectSignature('error', 'Merci de capturer une signature valide.');
}

$encoded = substr($signature, strpos($signature, ',') + 1);
$binary = base64_decode($encoded, true);
if ($binary === false || strlen($binary) < 500 || strlen($binary) > 2 * 1024 * 1024) {
    redirectSignature('error', 'La signature est vide ou trop volumineuse.');
}
$imageInfo = @getimagesizefromstring($binary);
if (!is_array($imageInfo) || (int)($imageInfo[2] ?? 0) !== IMAGETYPE_PNG || substr($binary, 0, 8) !== "\x89PNG\r\n\x1a\n") {
    redirectSignature('error', 'La signature générée n’est pas un fichier PNG valide. Veuillez la capturer à nouveau.');
}

try {
    $columns = $pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('signature', $columns, true)) {
        $pdo->exec('ALTER TABLE user_devis_banamur ADD COLUMN signature VARCHAR(255) NULL AFTER photo');
    }

    $uploadDir = dirname(__DIR__, 2) . '/uploads/user_signatures';
    if (!is_dir($uploadDir)) {
        $previousUmask = umask(0002);
        $created = @mkdir($uploadDir, 0775, true);
        umask($previousUmask);
        if (!$created && !is_dir($uploadDir)) {
            throw new RuntimeException('Impossible de créer automatiquement le dossier des signatures.');
        }
    }
    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Le serveur web ne peut pas écrire dans le dossier des signatures.');
    }

    $filename = 'signature_user_' . $userId . '_' . time() . '.png';
    $destination = $uploadDir . '/' . $filename;
    $temporary = $destination . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporary, $binary, LOCK_EX) !== strlen($binary) || !@rename($temporary, $destination)) {
        @unlink($temporary);
        throw new RuntimeException('Impossible d’enregistrer le fichier de signature.');
    }
    if (!@chmod($destination, 0640)) {
        @unlink($destination);
        throw new RuntimeException('Impossible de rendre la signature lisible par le générateur PDF.');
    }
    if (!is_readable($destination) || @getimagesize($destination) === false) {
        @unlink($destination);
        throw new RuntimeException('Le fichier de signature créé n’est pas compatible avec le générateur PDF.');
    }

    $dbPath = 'uploads/user_signatures/' . $filename;
    $oldSignatureStmt = $pdo->prepare('SELECT signature FROM user_devis_banamur WHERE id=:id LIMIT 1');
    $oldSignatureStmt->execute(['id' => $userId]);
    $oldSignature = trim((string) $oldSignatureStmt->fetchColumn());
    if (!in_array('signature_data', $columns, true)) {
        $pdo->exec('ALTER TABLE user_devis_banamur ADD COLUMN signature_data MEDIUMTEXT NULL AFTER signature');
    }
    $stmt = $pdo->prepare('UPDATE user_devis_banamur SET signature = :signature,signature_data=:signature_data WHERE id = :id');
    $stmt->execute(['signature' => $dbPath,'signature_data'=>'data:image/png;base64,'.base64_encode($binary),'id' => $userId]);
    if ($oldSignature !== '' && $oldSignature !== $dbPath) {
        $oldFile = realpath(dirname(__DIR__, 2) . '/' . ltrim($oldSignature, '/'));
        $signatureRoot = realpath($uploadDir);
        if ($oldFile && $signatureRoot && strpos($oldFile, $signatureRoot . '/') === 0 && is_file($oldFile)) {
            @unlink($oldFile);
        }
    }
    $_SESSION['signature'] = $dbPath;
    $_SESSION['signature_requirement_checked'] = 'mandatory_profile_signature_v1';

    AuditTrail::log('user_signature_saved', [
        'module' => 'decaissement',
        'action' => 'save_profile_signature',
        'entity_type' => 'user_signature',
        'entity_id' => (string) $userId,
        'entity_label' => 'Signature utilisateur',
        'result' => 'success',
        'details' => ['signature_path' => $dbPath],
    ], $pdo);

    if (!empty($_POST['mandatory_setup'])) {
        $returnTo = (string)($_SESSION['signature_setup_return'] ?? '../../selection_portail.php');
        unset($_SESSION['signature_setup_return']);
        $basePath = parse_url($returnTo, PHP_URL_PATH);
        if (!is_string($basePath) || !str_starts_with($basePath, '/')) $returnTo = '../../selection_portail.php';
        header('Location: ' . $returnTo);
    } else {
        header('Location: ../profil.php?status=ok&message=' . rawurlencode('Signature enregistree.') . '#signature');
    }
    exit;
} catch (Throwable $e) {
    error_log('[save_profile_signature] ' . $e->getMessage());
    try {
        AuditTrail::log('user_signature_save_failed', [
            'module' => 'decaissement',
            'action' => 'save_profile_signature',
            'entity_type' => 'user_signature',
            'entity_id' => (string) $userId,
            'result' => 'failed',
            'severity' => 'warning',
            'details' => ['error' => $e->getMessage()],
        ], $pdo);
    } catch (Throwable $ignored) {
    }
    redirectSignature('error', $e instanceof RuntimeException ? $e->getMessage() : 'Signature non enregistrée.');
}
