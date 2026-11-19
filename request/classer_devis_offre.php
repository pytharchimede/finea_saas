<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/DevisOfferProjectController.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../services/ActiveChantierEditPermissionService.php';

AccessManager::requirePageAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$token = (string) ($_POST['_csrf'] ?? '');
if (empty($_SESSION['devis_offer_csrf']) || !hash_equals((string) $_SESSION['devis_offer_csrf'], $token)) {
    http_response_code(403);
    $_SESSION['devis_offer_flash_error'] = 'Jeton CSRF invalide.';
    header('Location: ../devis/liste.php');
    exit;
}

try {
    ActiveChantierEditPermissionService::assertCanEdit(Database::getConnection(), (int) ($_POST['devis_id'] ?? 0));
    $result = (new DevisOfferProjectController(Database::getConnection()))->classify(
        (int) ($_POST['devis_id'] ?? 0),
        (string) ($_POST['devis_type'] ?? ''),
        (int) $_SESSION['user_id']
    );
    AuditTrail::log('devis_offer_classified', [
        'module' => 'devis',
        'action' => 'devis_offer_classified',
        'entity_type' => 'devis',
        'entity_id' => (string) $result['devis_id'],
        'result' => 'success',
        'details' => $result,
    ]);
    $_SESSION['devis_offer_flash_success'] = 'Devis classé ' . $result['type'] . ' dans son Offre/Projet.';
} catch (Throwable $exception) {
    $_SESSION['devis_offer_flash_error'] = $exception->getMessage();
}

header('Location: ../devis/liste.php');
exit;
