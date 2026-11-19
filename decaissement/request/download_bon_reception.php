<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../model/BonReception.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

$num = trim((string)($_GET['num_fiche'] ?? ''));
if ($num === '') {
    http_response_code(400);
    echo 'num_fiche manquant';
    exit;
}

try {
    if (!isset($pdo) || !$pdo) {
        $pdo = Database::getConnection();
    }

    $ficheModel = new FicheDecaissement($pdo);
    $brModel = new BonReception($pdo);

    $baseNum = $num;
    $fiche = $ficheModel->getByNum($num);
    if ($fiche) {
        $candidate = (string)($fiche['parent_num_fiche'] ?? ($fiche['code_parentale'] ?? ($fiche['num_fiche'] ?? $num)));
        if (trim($candidate) !== '') {
            $baseNum = trim($candidate);
        }
    }

    $row = $brModel->getByNumFiche($baseNum);
    if (!$row) {
        http_response_code(404);
        echo 'Bon de réception introuvable';
        exit;
    }

    $root = realpath(__DIR__ . '/../../');
    if (!$root) {
        http_response_code(500);
        echo 'Racine projet introuvable';
        exit;
    }

    $rel = (string)($row['file_path'] ?? '');
    $rel = str_replace('\\', '/', $rel);
    $rel = ltrim($rel, '/');
    if ($rel === '' || strpos($rel, '..') !== false) {
        http_response_code(500);
        echo 'Chemin invalide';
        exit;
    }

    $full = $root . '/' . $rel;
    if (!is_file($full)) {
        http_response_code(404);
        echo 'Fichier manquant';
        exit;
    }

    $original = (string)($row['original_name'] ?? 'bon_reception_' . $baseNum);
    $original = basename($original);
    $original = preg_replace('/[^0-9A-Za-z._ -]/', '_', $original);
    if ($original === '') {
        $original = 'bon_reception_' . $baseNum;
    }

    $mime = (string)($row['mime_type'] ?? '');
    if ($mime === '') {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($full);
    }
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($full));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . $original . '"');

    \AuditTrail::logFileEvent('download', [
        'module' => 'decaissement',
        'action' => 'bon_reception_download',
        'entity_type' => 'bon_reception',
        'entity_id' => (string)($row['id'] ?? $baseNum),
        'entity_label' => (string)$original,
        'format' => strtolower((string)pathinfo($original, PATHINFO_EXTENSION)),
        'details' => [
            'num_fiche' => $baseNum,
            'mime_type' => $mime,
            'stored_path' => $row['file_path'] ?? null,
        ],
    ], $pdo);

    readfile($full);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
    exit;
}
