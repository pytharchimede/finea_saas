<?php
session_start();

require_once __DIR__ . '/../model/AuditTrail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$kind = strtolower(trim((string)($_POST['kind'] ?? 'preview')));
if (!in_array($kind, ['preview', 'print'], true)) {
    http_response_code(400);
    exit;
}

$sourcePath = trim(str_replace('\\', '/', (string)($_POST['source_path'] ?? '')));
$sourcePath = ltrim($sourcePath, '/');

$details = [];
$detailsJson = trim((string)($_POST['details_json'] ?? ''));
if ($detailsJson !== '') {
    $decoded = json_decode($detailsJson, true);
    if (is_array($decoded)) {
        $details = $decoded;
    }
}

if (!isset($details['client_timestamp']) && isset($_POST['client_timestamp'])) {
    $details['client_timestamp'] = (string)$_POST['client_timestamp'];
}

AuditTrail::logVisualEvent($kind, [
    'module' => trim((string)($_POST['module'] ?? 'core')),
    'action' => trim((string)($_POST['action'] ?? ($kind === 'print' ? 'print_view' : 'browser_preview'))),
    'entity_type' => trim((string)($_POST['entity_type'] ?? 'document')),
    'entity_id' => trim((string)($_POST['entity_id'] ?? '')),
    'entity_label' => trim((string)($_POST['entity_label'] ?? '')),
    'request_path' => $sourcePath !== '' ? $sourcePath : null,
    'page_name' => $sourcePath !== '' ? basename($sourcePath) : null,
    'request_method' => 'CLIENT',
    'is_ajax' => 1,
    'details' => $details,
], null);

http_response_code(204);
exit;
