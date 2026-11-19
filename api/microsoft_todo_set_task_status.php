<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/MicrosoftToDo.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Méthode invalide.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = null;
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if (!is_array($payload)) {
    $payload = $_POST;
}

$listId = trim((string)($payload['list_id'] ?? ''));
$taskId = trim((string)($payload['task_id'] ?? ''));
$status = trim((string)($payload['status'] ?? ''));
$etag = array_key_exists('etag', $payload) ? (string)($payload['etag'] ?? '') : null;

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Utilisateur non identifié.']);
    exit;
}

if ($listId === '' || $taskId === '' || $status === '') {
    echo json_encode(['ok' => false, 'error' => 'Paramètres manquants.']);
    exit;
}

$msConfigPath = __DIR__ . '/../config/microsoft_todo.php';
$msConfig = [];
if (is_file($msConfigPath)) {
    $loaded = require $msConfigPath;
    if (is_array($loaded)) {
        $msConfig = $loaded;
    }
}
$tenantId = trim((string)($msConfig['tenant_id'] ?? ''));
$clientId = trim((string)($msConfig['client_id'] ?? ''));
$clientSecret = trim((string)($msConfig['client_secret'] ?? ''));

if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
    echo json_encode(['ok' => false, 'error' => 'Configuration Microsoft manquante.']);
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');

// On est dans /.../api/*.php -> on remonte d'un niveau pour reconstruire l'URL de emploi_du_temps.php
$dir = str_replace('\\', '/', rtrim(dirname($scriptName), '/'));
$base = preg_replace('~/api$~', '', $dir);
if ($base === null) {
    $base = '';
}
if ($base === '') {
    $base = '/';
}

$redirectUri = $scheme . '://' . $host . rtrim($base, '/') . '/emploi_du_temps.php?action=microsoft_callback';

try {
    $pdo = Database::getConnection();
    $msTodo = new MicrosoftToDo($pdo);

    if (!$msTodo->isReady()) {
        echo json_encode(['ok' => false, 'error' => $msTodo->getInitError() ?: 'Module Microsoft indisponible.']);
        exit;
    }

    $tokenRow = $msTodo->getTokenRow($userId);
    if (!$tokenRow || empty($tokenRow['access_token'])) {
        echo json_encode(['ok' => false, 'error' => 'Microsoft To Do non connecté.']);
        exit;
    }

    $accessToken = (string)$tokenRow['access_token'];

    $expiresAt = (string)($tokenRow['expires_at'] ?? '');
    $refreshToken = (string)($tokenRow['refresh_token'] ?? '');
    if ($accessToken !== '' && $msTodo->isExpired($expiresAt) && $refreshToken !== '') {
        $refresh = $msTodo->refreshAccessToken($tenantId, $clientId, $clientSecret, $redirectUri, $refreshToken);
        if (!empty($refresh['ok']) && !empty($refresh['token']) && is_array($refresh['token'])) {
            $msTodo->saveTokenRow($userId, $refresh['token']);
            $accessToken = (string)($refresh['token']['access_token'] ?? $accessToken);
        } else {
            echo json_encode(['ok' => false, 'error' => (string)($refresh['error'] ?? 'Refresh Microsoft impossible.')]);
            exit;
        }
    }

    $res = $msTodo->updateTaskStatus($accessToken, $listId, $taskId, $status, $etag);
    if (empty($res['ok'])) {
        AuditTrail::log('microsoft_todo_task_status_update_failed', [
            'module' => 'api',
            'action' => 'microsoft_todo_set_task_status',
            'entity_type' => 'microsoft_todo_task',
            'entity_id' => $taskId,
            'entity_label' => 'Mise à jour statut tâche Microsoft To Do',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'list_id' => $listId,
                'status' => $status,
                'etag' => $etag,
                'error_message' => (string)($res['error'] ?? 'Mise à jour Microsoft impossible.'),
            ],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Mise à jour Microsoft impossible.')]);
        exit;
    }

    AuditTrail::log('microsoft_todo_task_status_updated', [
        'module' => 'api',
        'action' => 'microsoft_todo_set_task_status',
        'entity_type' => 'microsoft_todo_task',
        'entity_id' => $taskId,
        'entity_label' => 'Mise à jour statut tâche Microsoft To Do',
        'result' => 'success',
        'details' => [
            'list_id' => $listId,
            'status' => $status,
            'etag' => $etag,
        ],
    ], $pdo);

    echo json_encode(['ok' => true]);
    exit;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
