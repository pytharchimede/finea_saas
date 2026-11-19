<?php
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

// Helpers RH (résolution URL des médias si disponibles)
@require_once __DIR__ . '/../../rh/includes/personnel_helpers.php';

header('Content-Type: application/json; charset=utf-8');

function resolveMediaUrlSafe(?string $path): ?string
{
    if ($path === null) {
        return null;
    }
    $path = trim($path);
    if ($path === '') {
        return null;
    }

    if (function_exists('rhResolveMediaUrl')) {
        return rhResolveMediaUrl($path);
    }

    // Fallback très simple : si le chemin ressemble à une URL
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }

    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        AuditTrail::log('decaissement_personnel_details_failed', [
            'module' => 'decaissement',
            'action' => 'get_personnel_details',
            'entity_type' => 'personnel',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'invalid_method'],
        ]);
        echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
        exit;
    }

    $personnelId = (int)($_POST['personnel_id'] ?? 0);
    if ($personnelId <= 0) {
        AuditTrail::log('decaissement_personnel_details_failed', [
            'module' => 'decaissement',
            'action' => 'get_personnel_details',
            'entity_type' => 'personnel',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'missing_personnel_id'],
        ]);
        echo json_encode(['success' => false, 'message' => 'ID personnel requis']);
        exit;
    }

    $pdo = Database::getConnection();

    $columns = $pdo->query('SHOW COLUMNS FROM personnel')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $colSet = [];
    foreach ($columns as $col) {
        $name = (string)($col['Field'] ?? '');
        if ($name !== '') {
            $colSet[$name] = true;
        }
    }

    $selectParts = [
        'p.id_personnel_soignant AS id',
        'p.nom_personnel_soignant AS full_name',
    ];

    if (isset($colSet['tel_personnel_soignant'])) {
        $selectParts[] = 'p.tel_personnel_soignant AS phone';
    } else {
        $selectParts[] = "'' AS phone";
    }

    if (isset($colSet['photo_personnel_soignant'])) {
        $selectParts[] = 'p.photo_personnel_soignant AS photo_path';
    } else {
        $selectParts[] = "'' AS photo_path";
    }

    // Pièce d'identité : selon schéma, souvent piece_identite_path
    if (isset($colSet['piece_identite_path'])) {
        $selectParts[] = 'p.piece_identite_path AS identity_path';
    } elseif (isset($colSet['identity_document_path'])) {
        $selectParts[] = 'p.identity_document_path AS identity_path';
    } else {
        $selectParts[] = "'' AS identity_path";
    }

    $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM personnel p WHERE p.id_personnel_soignant = :id LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $personnelId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        AuditTrail::log('decaissement_personnel_details_failed', [
            'module' => 'decaissement',
            'action' => 'get_personnel_details',
            'entity_type' => 'personnel',
            'entity_id' => (string)$personnelId,
            'entity_label' => 'personnel_' . $personnelId,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'personnel_not_found'],
        ], $pdo);
        echo json_encode(['success' => false, 'message' => 'Personnel introuvable']);
        exit;
    }

    $photoUrl = resolveMediaUrlSafe((string)($row['photo_path'] ?? ''));
    $cniUrl = resolveMediaUrlSafe((string)($row['identity_path'] ?? ''));

    echo json_encode([
        'success' => true,
        'personnel' => [
            'id' => (int)($row['id'] ?? 0),
            'full_name' => (string)($row['full_name'] ?? ''),
            'phone' => (string)($row['phone'] ?? ''),
            'photo_url' => $photoUrl,
            'cni_url' => $cniUrl,
            'has_photo' => !empty($photoUrl),
            'has_cni' => !empty($cniUrl),
        ],
    ]);
} catch (Throwable $e) {
    AuditTrail::log('decaissement_personnel_details_failed', [
        'module' => 'decaissement',
        'action' => 'get_personnel_details',
        'entity_type' => 'personnel',
        'entity_id' => isset($personnelId) && $personnelId > 0 ? (string)$personnelId : null,
        'entity_label' => isset($personnelId) && $personnelId > 0 ? ('personnel_' . $personnelId) : 'get_personnel_details',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

if (isset($personnelId) && $personnelId > 0 && isset($pdo) && $pdo instanceof PDO && isset($row) && $row) {
    AuditTrail::log('decaissement_personnel_details_loaded', [
        'module' => 'decaissement',
        'action' => 'get_personnel_details',
        'entity_type' => 'personnel',
        'entity_id' => (string)$personnelId,
        'entity_label' => (string)($row['full_name'] ?? ('personnel_' . $personnelId)),
        'details' => [
            'has_photo' => !empty($photoUrl),
            'has_cni' => !empty($cniUrl),
        ],
    ], $pdo);
}
