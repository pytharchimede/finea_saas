<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/SuiviTravaux.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $model = new SuiviTravaux($pdo);
    $userName = trim((string)($_SESSION['user_name'] ?? $_SESSION['username'] ?? ($_SESSION['prenom'] ?? '')));
    $nom = trim((string)($_SESSION['nom'] ?? ''));
    if ($userName === '' && $nom !== '') {
        $userName = $nom;
    }
    if ($userName === '') {
        $userName = 'SYSTEM';
    }

    $saved = $model->saveDailyUpdate([
        'chantier_id' => $_POST['chantier_id'] ?? 0,
        'ligne_debourse_id' => $_POST['ligne_debourse_id'] ?? 0,
        'date_suivi' => $_POST['date_suivi'] ?? '',
        'progression_reelle' => $_POST['progression_reelle'] ?? 0,
        'statut_reel' => $_POST['statut_reel'] ?? '',
        'commentaire' => $_POST['commentaire'] ?? '',
        'detail_ligne_debourse' => $_POST['detail_ligne_debourse'] ?? '',
        'blocage_detail' => $_POST['blocage_detail'] ?? '',
        'prochaine_action' => $_POST['prochaine_action'] ?? '',
        'updated_by' => $userName,
    ]);

    $assignment = $model->saveTaskAssignment([
        'chantier_id' => $_POST['chantier_id'] ?? 0,
        'ligne_debourse_id' => $_POST['ligne_debourse_id'] ?? 0,
        'service_id' => $_POST['service_id'] ?? null,
        'pilot_ids' => $_POST['pilot_ids'] ?? [],
        'updated_by' => $userName,
    ]);

    echo json_encode([
        'ok' => true,
        'item' => $saved,
        'assignment' => $assignment,
    ]);
    AuditTrail::log('suivi_travaux_saved', [
        'module' => 'decaissement',
        'action' => 'save_suivi_travaux',
        'entity_type' => 'suivi_travaux',
        'entity_id' => isset($_POST['ligne_debourse_id']) ? (string) $_POST['ligne_debourse_id'] : null,
        'entity_label' => 'Sauvegarde suivi travaux',
        'result' => 'success',
        'details' => [
            'chantier_id' => $_POST['chantier_id'] ?? null,
            'ligne_debourse_id' => $_POST['ligne_debourse_id'] ?? null,
            'date_suivi' => $_POST['date_suivi'] ?? null,
        ],
    ], $pdo);
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo instanceof PDO) {
        AuditTrail::log('suivi_travaux_save_failed', [
            'module' => 'decaissement',
            'action' => 'save_suivi_travaux',
            'entity_type' => 'suivi_travaux',
            'entity_id' => isset($_POST['ligne_debourse_id']) ? (string) $_POST['ligne_debourse_id'] : null,
            'entity_label' => 'Sauvegarde suivi travaux',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'chantier_id' => $_POST['chantier_id'] ?? null,
                'ligne_debourse_id' => $_POST['ligne_debourse_id'] ?? null,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
