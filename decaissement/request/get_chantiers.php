<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/Chantier.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../services/UrgentDisbursementService.php';
require_once __DIR__ . '/../../services/ChantierPauseService.php';

header('Content-Type: application/json');

try {
    $pdo = Database::getConnection();

    // Auto-migration: ajoute les colonnes d'archivage si absentes.
    Chantier::ensureArchiveColumns($pdo);

    // Récupérer l'entreprise depuis les paramètres POST ou GET
    $entreprise_filter = null;
    if (isset($_POST['entreprise']) && !empty($_POST['entreprise'])) {
        $entreprise_filter = $_POST['entreprise'];
    } elseif (isset($_GET['entreprise']) && !empty($_GET['entreprise'])) {
        $entreprise_filter = $_GET['entreprise'];
    }

    // Construire la requête avec ou sans filtre d'entreprise
    $query = "
        SELECT 
            c.id,
            c.titre,
            c.code,
            c.date_creation,
            c.entreprise,
            cl.nom_client
        FROM chantier_banamur c
        LEFT JOIN client_banamur cl ON cl.id_client = c.client_id
    ";

    $params = [];
    $where = [];

    // Filtrer selon l'entreprise sélectionnée
    if ($entreprise_filter) {
        if (strtoupper($entreprise_filter) === 'BANAMUR') {
            $where[] = "c.entreprise LIKE '%banamur%'";
        } elseif (strtoupper($entreprise_filter) === 'FIDEST') {
            $where[] = "c.entreprise LIKE '%fidest%'";
        }
    }

    // Exclure les chantiers archivés par défaut
    $where[] = 'c.archived_at IS NULL';
    $where[] = ChantierPauseService::activePredicate($pdo, 'c.id');

    if (!empty($where)) {
        $query .= ' WHERE ' . implode(' AND ', $where);
    }

    $query .= " ORDER BY c.date_creation DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $chantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $urgentService = new \UrgentDisbursementService($pdo);
    $blockedMap = $urgentService->blockedChantierMap();
    foreach ($chantiers as &$chantier) {
        $blocked = $blockedMap[(int)($chantier['id'] ?? 0)] ?? null;
        $chantier['disbursement_blocked'] = $blocked ? 1 : 0;
        $chantier['disbursement_block_reason'] = $blocked ? $urgentService->chantierBlockReason($blocked) : '';
    }
    unset($chantier);

    AuditTrail::log('decaissement_chantiers_listed', [
        'module' => 'decaissement',
        'action' => 'get_chantiers',
        'entity_type' => 'chantier_list',
        'entity_label' => $entreprise_filter !== null ? (string)$entreprise_filter : 'all_chantiers',
        'details' => [
            'entreprise_filter' => $entreprise_filter,
            'chantier_count' => count($chantiers),
        ],
    ], $pdo);

    echo json_encode([
        'success' => true,
        'chantiers' => $chantiers,
        'entreprise_filter' => $entreprise_filter
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Exception $e) {
    AuditTrail::log('decaissement_chantiers_list_failed', [
        'module' => 'decaissement',
        'action' => 'get_chantiers',
        'entity_type' => 'chantier_list',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
