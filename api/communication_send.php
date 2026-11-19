<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Communication.php';

header('Content-Type: application/json; charset=utf-8');

function comm_normalize_files_array(array $fileField): array
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
    for ($i = 0; $i < $count; $i++) {
        $out[] = [
            'name' => (string)($names[$i] ?? ''),
            'type' => (string)($types[$i] ?? ''),
            'tmp_name' => (string)($tmps[$i] ?? ''),
            'error' => (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($sizes[$i] ?? 0),
        ];
    }

    return $out;
}

function comm_parse_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    switch ($unit) {
        case 'g':
            $number *= 1024;
        case 'm':
            $number *= 1024;
        case 'k':
            $number *= 1024;
            break;
    }

    return (int)round($number);
}

function comm_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 Ko';
    }
    if ($bytes >= 1024 * 1024 * 1024) {
        return rtrim(rtrim(number_format($bytes / (1024 * 1024 * 1024), 1, '.', ''), '0'), '.') . ' Go';
    }
    if ($bytes >= 1024 * 1024) {
        return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.') . ' Mo';
    }
    return max(1, (int)ceil($bytes / 1024)) . ' Ko';
}

function comm_upload_error_message(array $file): string
{
    $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $fileName = trim((string)($file['name'] ?? 'fichier'));
    $maxUploadBytes = comm_parse_ini_bytes((string)ini_get('upload_max_filesize'));
    $maxUploadLabel = comm_format_bytes($maxUploadBytes);

    switch ($errorCode) {
        case UPLOAD_ERR_OK:
            return '';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'Le fichier ' . $fileName . ' dépasse la limite autorisée (' . $maxUploadLabel . ').';
        case UPLOAD_ERR_PARTIAL:
            return 'Le fichier ' . $fileName . ' n\'a été envoyé que partiellement.';
        case UPLOAD_ERR_NO_FILE:
            return 'Aucun fichier n\'a été transmis.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Le dossier temporaire de téléversement est introuvable.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Impossible d\'écrire le fichier ' . $fileName . ' sur le serveur.';
        case UPLOAD_ERR_EXTENSION:
            return 'Le téléversement du fichier ' . $fileName . ' a été interrompu par une extension serveur.';
        default:
            return 'Erreur upload pièce jointe pour ' . $fileName . '.';
    }
}

try {
    $pdo = \Database::getConnection();
    \Communication::ensureTables($pdo);

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $action = trim((string)($_POST['action'] ?? 'create_task'));
    $canManageAll = !empty($_SESSION['permissions']['acces_admin'] ?? 0);

    if ($action === 'update_status') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        $applyToGroup = !empty($_POST['apply_to_group']);
        echo json_encode(\Communication::updateTaskStatus($pdo, $taskId, $status, $userId, $canManageAll, true, $applyToGroup), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'toggle_pin') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $isPinned = (int)($_POST['is_pinned'] ?? 0) === 1;
        echo json_encode(\Communication::updateUserTaskPreference($pdo, $userId, $taskId, $isPinned, null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'save_task_order') {
        $orderedTaskIds = json_decode((string)($_POST['ordered_task_ids'] ?? '[]'), true);
        if (!is_array($orderedTaskIds)) {
            $orderedTaskIds = [];
        }
        echo json_encode(\Communication::saveUserTaskOrdering($pdo, $userId, $orderedTaskIds), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'delete_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        echo json_encode(\Communication::deleteTask($pdo, $taskId, $userId, $canManageAll), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'upload_attachments') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $existingTask = \Communication::getTaskById($pdo, $taskId);
        if (!$existingTask) {
            echo json_encode(['ok' => false, 'error' => 'Tâche introuvable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if (!$canManageAll && !in_array($userId, [(int)($existingTask['created_by_user_id'] ?? 0), (int)($existingTask['assignee_user_id'] ?? 0)], true)) {
            echo json_encode(['ok' => false, 'error' => 'Ajout de pièces jointes non autorisé.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $payload = [
        'assignee_user_id' => $_POST['assignee_user_id'] ?? 0,
        'assignee_user_ids' => $_POST['assignee_user_ids'] ?? [],
        'target_scope' => $_POST['target_scope'] ?? 'single',
        'service_id' => $_POST['service_id'] ?? 0,
        'title' => $_POST['title'] ?? '',
        'description' => $_POST['description'] ?? '',
        'deadline' => $_POST['deadline'] ?? '',
        'duration_minutes' => $_POST['duration_minutes'] ?? 60,
        'priority' => $_POST['priority'] ?? 'normal',
        'status' => $_POST['status'] ?? 'todo',
        'apply_to_group' => !empty($_POST['apply_to_group']),
    ];
    $taskId = (int)($_POST['task_id'] ?? 0);

    if ($action === 'update_task') {
        $existingTask = \Communication::getTaskById($pdo, $taskId);
        if (!$existingTask) {
            echo json_encode(['ok' => false, 'error' => 'Tâche introuvable.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if (!$canManageAll && !in_array($userId, [(int)($existingTask['created_by_user_id'] ?? 0), (int)($existingTask['assignee_user_id'] ?? 0)], true)) {
            echo json_encode(['ok' => false, 'error' => 'Modification non autorisée.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $targetScope = trim((string)($payload['target_scope'] ?? 'single'));
    if ($action !== 'update_task') {
        if ($targetScope === '' || !in_array($targetScope, ['single', 'selected', 'service', 'team'], true)) {
            $targetScope = 'single';
            $payload['target_scope'] = 'single';
        }

        if ($targetScope === 'single' && (int)($payload['assignee_user_id'] ?? 0) <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Sélectionnez d\'abord un collaborateur.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($targetScope === 'selected') {
            $selectedIds = array_values(array_filter(array_map('intval', (array)($payload['assignee_user_ids'] ?? [])), static function (int $value): bool {
                return $value > 0;
            }));
            if ($selectedIds === []) {
                echo json_encode(['ok' => false, 'error' => 'Cochez au moins un collaborateur.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            $payload['assignee_user_ids'] = $selectedIds;
        }

        if ($targetScope === 'service' && (int)($payload['service_id'] ?? 0) <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Sélectionnez d\'abord un service.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    } elseif ((int)($payload['assignee_user_id'] ?? 0) <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Sélectionnez d\'abord un collaborateur.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (trim((string)($payload['title'] ?? '')) === '') {
        echo json_encode(['ok' => false, 'error' => 'Le titre de la tâche est obligatoire.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (trim((string)($payload['deadline'] ?? '')) === '') {
        echo json_encode(['ok' => false, 'error' => 'La deadline est obligatoire.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $attachments = [];
    if (!empty($_FILES['attachments'])) {
        $files = array_values(array_filter(comm_normalize_files_array($_FILES['attachments']), static function ($file) {
            return !empty($file['name']) || ((int)($file['error'] ?? 0) !== UPLOAD_ERR_NO_FILE);
        }));

        if ($files) {
            $baseDir = __DIR__ . '/../storage/communication_tasks/' . date('Ym');
            if (!is_dir($baseDir) && !@mkdir($baseDir, 0755, true)) {
                throw new RuntimeException('Impossible de créer le dossier des pièces jointes.');
            }

            foreach ($files as $file) {
                if ((int)($file['error'] ?? 0) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException(comm_upload_error_message($file));
                }
                $ext = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                $storedName = bin2hex(random_bytes(12)) . ($ext !== '' ? '.' . $ext : '');
                $absolutePath = $baseDir . '/' . $storedName;
                if (!move_uploaded_file((string)$file['tmp_name'], $absolutePath)) {
                    throw new RuntimeException('Impossible de stocker la pièce jointe.');
                }

                $attachments[] = [
                    'stored_rel' => 'communication_tasks/' . date('Ym') . '/' . $storedName,
                    'original_name' => (string)$file['name'],
                    'mime' => (string)($file['type'] ?? 'application/octet-stream'),
                    'size' => (int)($file['size'] ?? 0),
                ];
            }
        }
    }

    if ($action === 'update_task') {
        echo json_encode(\Communication::updateTask($pdo, $taskId, $userId, $payload, $attachments, $canManageAll), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'upload_attachments') {
        if (!$attachments) {
            echo json_encode(['ok' => false, 'error' => 'Sélectionnez au moins un fichier.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        \Communication::storeAttachments($pdo, $taskId, $userId, $attachments);
        \Communication::addComment($pdo, $taskId, $userId, 'Pieces jointes ajoutees a la tache.');
        echo json_encode(['ok' => true, 'task_id' => $taskId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode(\Communication::createTasksForTargets($pdo, $userId, $payload, $attachments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
