<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/CommercialProjectFinanceController.php';

AccessManager::requirePageAccess('request/classer_devis_offre.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
$token = (string) ($_POST['_csrf'] ?? '');
if (empty($_SESSION['devis_offer_csrf']) || !hash_equals((string) $_SESSION['devis_offer_csrf'], $token)) {
    $_SESSION['devis_offer_flash_error'] = 'Jeton CSRF invalide.';
    header('Location: ../devis/liste.php');
    exit;
}
try {
    $controller = new CommercialProjectFinanceController(Database::getConnection());
    $devisId = (int) ($_POST['devis_id'] ?? 0);
    $action = (string) ($_POST['archive_action'] ?? 'archive');
    if ($action === 'restore') {
        $controller->restoreQuote($devisId, (int) $_SESSION['user_id'], (string) ($_POST['note'] ?? ''));
        $_SESSION['devis_offer_flash_success'] = 'Devis restauré.';
    } else {
        $controller->archiveQuote($devisId, (int) $_SESSION['user_id'], (string) ($_POST['reason'] ?? ''));
        $_SESSION['devis_offer_flash_success'] = 'Devis archivé. Il reste restaurable depuis le filtre Archives.';
    }
} catch (Throwable $exception) {
    $_SESSION['devis_offer_flash_error'] = $exception->getMessage();
}
header('Location: ../devis/liste.php');
exit;
