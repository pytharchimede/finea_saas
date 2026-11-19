<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeChat.php';

header('Content-Type: application/json; charset=utf-8');

function employee_chat_parse_ini_size_to_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    switch ($unit) {
        case 'g':
            return (int) ($number * 1024 * 1024 * 1024);
        case 'm':
            return (int) ($number * 1024 * 1024);
        case 'k':
            return (int) ($number * 1024);
        default:
            return (int) $number;
    }
}

function employee_chat_normalize_files_array(array $fileField): array
{
    $out = [];
    $names = $fileField['name'] ?? [];
    $types = $fileField['type'] ?? [];
    $tmps = $fileField['tmp_name'] ?? [];
    $errors = $fileField['error'] ?? [];
    $sizes = $fileField['size'] ?? [];

    if (!is_array($names)) {
        $names = [$names];
        $types = [$types];
        $tmps = [$tmps];
        $errors = [$errors];
        $sizes = [$sizes];
    }

    $count = count($names);
    for ($index = 0; $index < $count; $index++) {
        $out[] = [
            'name' => (string) ($names[$index] ?? ''),
            'type' => (string) ($types[$index] ?? ''),
            'tmp_name' => (string) ($tmps[$index] ?? ''),
            'error' => (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($sizes[$index] ?? 0),
        ];
    }

    return $out;
}

function employee_chat_store_uploaded_files(array $files): array
{
    $normalized = array_values(array_filter(employee_chat_normalize_files_array($files), static function (array $file): bool {
        return !empty($file['name']) || ((int) ($file['error'] ?? 0) !== UPLOAD_ERR_NO_FILE);
    }));

    if (!$normalized) {
        return [];
    }

    $baseDir = __DIR__ . '/../storage/employee_chat/' . date('Ym');
    if (!is_dir($baseDir) && !@mkdir($baseDir, 0755, true)) {
        throw new RuntimeException('Impossible de creer le dossier des pieces jointes.');
    }

    $attachments = [];
    foreach ($normalized as $file) {
        $uploadError = (int) ($file['error'] ?? 0);
        if ($uploadError !== UPLOAD_ERR_OK) {
            switch ($uploadError) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    throw new RuntimeException('La piece jointe depasse la taille autorisee par le serveur.');
                case UPLOAD_ERR_PARTIAL:
                    throw new RuntimeException('La piece jointe n\'a ete telechargee que partiellement.');
                case UPLOAD_ERR_NO_FILE:
                    throw new RuntimeException('Aucun fichier n\'a ete transmis.');
                default:
                    throw new RuntimeException('Echec de l\'upload d\'une piece jointe.');
            }
        }

        $ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $storedName = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
        $absolutePath = $baseDir . '/' . $storedName;
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $stored = move_uploaded_file($tmpName, $absolutePath);
        if (!$stored && $tmpName !== '' && is_file($tmpName)) {
            $stored = @rename($tmpName, $absolutePath);
            if (!$stored) {
                $stored = @copy($tmpName, $absolutePath);
                if ($stored) {
                    @unlink($tmpName);
                }
            }
        }
        if (!$stored) {
            throw new RuntimeException('Impossible de stocker la piece jointe sur le serveur.');
        }

        $attachments[] = [
            'stored_rel' => 'employee_chat/' . date('Ym') . '/' . $storedName,
            'original_name' => (string) $file['name'],
            'mime' => (string) ($file['type'] ?? 'application/octet-stream'),
            'size' => (int) ($file['size'] ?? 0),
        ];
    }

    return $attachments;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Methode non autorisee.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxSizeBytes = employee_chat_parse_ini_size_to_bytes((string) ini_get('post_max_size'));
if ($contentLength > 0 && $postMaxSizeBytes > 0 && $contentLength > $postMaxSizeBytes && empty($_POST) && empty($_FILES)) {
    http_response_code(413);
    echo json_encode([
        'ok' => false,
        'error' => 'La vidéo ou pièce jointe dépasse la taille maximale autorisée par le serveur (' . ini_get('post_max_size') . ').',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
    session_write_close();
    $threadType = (string) ($payload['thread_type'] ?? 'peer');
    $threadId = $threadType === 'group'
        ? (int) ($payload['group_id'] ?? $payload['thread_id'] ?? 0)
        : (int) ($payload['peer_user_id'] ?? $payload['thread_id'] ?? 0);
    $body = (string) ($payload['body'] ?? '');
    $attachments = !empty($_FILES['attachments']) ? employee_chat_store_uploaded_files($_FILES['attachments']) : [];
    $pdo = \Database::getConnection();

    $result = \EmployeeChat::sendMessage($pdo, $threadType, $currentUserId, $threadId, $body, $attachments);
    if (empty($result['ok'])) {
        http_response_code(400);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
