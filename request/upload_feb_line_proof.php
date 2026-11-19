<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

header('Content-Type: application/json; charset=UTF-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Méthode non autorisée.');
    }
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $expectedCsrf = (string) ($_SESSION['feb_proof_upload_csrf'] ?? '');
    if ($expectedCsrf === '' || !hash_equals($expectedCsrf, $csrf)) {
        throw new RuntimeException('La session a expiré. Rechargez la page puis réessayez.');
    }
    if (!isset($_FILES['proof']) || !is_array($_FILES['proof'])) {
        throw new RuntimeException('Choisissez une image ou un PDF.');
    }

    $file = (array) $_FILES['proof'];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Le fichier n’a pas été reçu complètement. Réessayez.');
    }
    $name = trim((string) ($file['name'] ?? ''));
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($name === '' || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Le fichier reçu est invalide.');
    }
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Le justificatif doit faire moins de 10 Mo.');
    }
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new RuntimeException('Format non accepté. Utilisez PDF, JPG, PNG ou WebP.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $allowedMimes = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];
    if (!in_array($mime, $allowedMimes[$ext] ?? [], true)) {
        throw new RuntimeException('Le contenu du fichier ne correspond pas à son extension.');
    }

    $dir = __DIR__ . '/../uploads/feb_line_quotes';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Le serveur n’a pas pu créer automatiquement le dossier des justificatifs. Le fichier est correct; contactez le support.');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('Le serveur ne peut pas enregistrer les justificatifs pour le moment. Le fichier est correct; contactez le support.');
    }
    $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $target = $dir . '/' . $storedName;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Le serveur a reçu le fichier mais n’a pas pu le conserver. Réessayez ou contactez le support.');
    }

    $_SESSION['feb_pending_line_proofs'] ??= [];
    foreach ((array) $_SESSION['feb_pending_line_proofs'] as $oldToken => $oldEntry) {
        if (time() - (int) ($oldEntry['created_at'] ?? 0) <= 24 * 60 * 60) {
            continue;
        }
        $oldStoredName = basename((string) (($oldEntry['proof']['stored_name'] ?? '')));
        if ($oldStoredName !== '') {
            @unlink($dir . '/' . $oldStoredName);
        }
        unset($_SESSION['feb_pending_line_proofs'][$oldToken]);
    }
    $token = bin2hex(random_bytes(24));
    $_SESSION['feb_pending_line_proofs'][$token] = [
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'created_at' => time(),
        'proof' => [
            'name' => $name,
            'stored_name' => $storedName,
            'url' => '/uploads/feb_line_quotes/' . $storedName,
            'mime' => $mime,
            'size' => $size,
            'sha256' => hash_file('sha256', $target) ?: '',
        ],
    ];

    echo json_encode([
        'ok' => true,
        'token' => $token,
        'name' => $name,
        'size' => $size,
        'message' => 'Justificatif vérifié et conservé.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
