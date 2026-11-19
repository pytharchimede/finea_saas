<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/FebHumanDocumentReviewService.php';
require_once __DIR__ . '/../services/FebHumanReviewQuotePdfBuilder.php';

$reviewId = max(0, (int)($_GET['id'] ?? 0));
$userId = (int)($_SESSION['user_id'] ?? 0);
$review = $reviewId > 0 ? (new FebHumanDocumentReviewService(Database::getConnection()))->fileFor($reviewId, $userId) : null;
if (!$review) {
    http_response_code(404);
    exit('Devis FEB introuvable ou accès refusé.');
}
session_write_close();

$pdf = FebHumanReviewQuotePdfBuilder::build($review);
$pdf->Output('FEB-REV-' . $reviewId . '-devis-saisi-FINEA.pdf', 'I');
