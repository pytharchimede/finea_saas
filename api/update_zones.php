<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../services/ActiveChantierEditPermissionService.php';

header('Content-Type: application/json');

try {
    $pdo = Database::getConnection();

    // Récupérer les données POST
    $input = json_decode(file_get_contents('php://input'), true);

    error_log("Update zones input: " . json_encode($input));

    if (!$input || !isset($input['devis_id']) || !isset($input['affectations'])) {
        throw new Exception('Données invalides - devis_id ou affectations manquants');
    }

    $devisId = $input['devis_id'];
    ActiveChantierEditPermissionService::assertCanEdit($pdo, (int) $devisId);
    $affectations = $input['affectations'];
    // si la page a envoyé la liste complète des zones visibles
    $allZones = $input['allZones'] ?? [];
    if (!empty($allZones)) {
        // sauvegarder en session pour persister les zones vides
        $_SESSION['zones_' . $devisId] = $allZones;
    }
    // $zoneRenames = $input['zoneRenames'] ?? []; // non utilisé, les affectations couvrent les renommages

    // Vérifier que le devis existe
    $stmt = $pdo->prepare("SELECT id FROM devis_banamur WHERE id = ?");
    $stmt->execute([(int)$devisId]);
    if (!$stmt->fetch()) {
        throw new Exception('Devis non trouvé (ID: ' . $devisId . ')');
    }

    // (debugging removed – assume schema correct and focus on updates)
    // enregistrement du devis traité (utile pour debugging léger)
    error_log("Traitement devis $devisId avec " . count($affectations) . " affectations");

    $pdo->beginTransaction();

    // (plus besoin de gérer explicitement les renommages :
    // chaque ligne est mise à jour ci‑dessous avec sa zone actuelle)

    // Mettre à jour les affectations individuelles des lignes
    $updateCount = 0;
    foreach ($affectations as $ligneId => $zone) {
        $stmt = $pdo->prepare("UPDATE ligne_devis_banamur SET zone = ? WHERE id = ? AND devis_id = ?");
        $result = $stmt->execute([$zone, (int)$ligneId, (int)$devisId]);
        $rowCount = $stmt->rowCount();
        $updateCount += $rowCount;
        if ($rowCount === 0) {
            error_log("⚠️ AUCUNE ligne affectée pour: ligne_id=$ligneId, devis_id=$devisId, zone='$zone'");
        } else {
            error_log("✓ Affectation ligne $ligneId à zone '$zone': $rowCount ligne(s) mise(s) à jour");
        }
    }

    $pdo->commit();
    error_log("Transaction commitée - Total: $updateCount ligne(s) mise(s) à jour");

    AuditTrail::log('devis_zones_updated', [
        'module' => 'api',
        'action' => 'update_zones',
        'entity_type' => 'devis',
        'entity_id' => (string) ((int) $devisId),
        'entity_label' => 'Affectations des zones devis',
        'result' => 'success',
        'details' => [
            'devis_id' => (int) $devisId,
            'affectation_count' => is_array($affectations) ? count($affectations) : 0,
            'updated_lines' => $updateCount,
            'all_zones_count' => is_array($allZones) ? count($allZones) : 0,
        ],
    ], $pdo);

    echo json_encode([
        'success' => true,
        'message' => "Zones mises à jour avec succès ($updateCount lignes)"
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
        error_log("Transaction rollback suite à l'erreur");
    }

    error_log('Erreur update_zones: ' . $e->getMessage());

    if (isset($pdo) && $pdo instanceof PDO) {
        AuditTrail::log('devis_zones_update_failed', [
            'module' => 'api',
            'action' => 'update_zones',
            'entity_type' => 'devis',
            'entity_id' => isset($devisId) ? (string) ((int) $devisId) : null,
            'entity_label' => 'Affectations des zones devis',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'affectation_count' => isset($affectations) && is_array($affectations) ? count($affectations) : 0,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }

    echo json_encode([
        'success' => false,
        'message' => 'Erreur: ' . $e->getMessage()
    ]);
}
