<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/FebValidationPolicy.php';
require_once __DIR__ . '/../services/FinancialPlanningSeparationService.php';

require_once __DIR__.'/../services/FebAssignmentBeneficiary.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = \Database::getConnection();
$manager = new \EmployeeLegalRequestManager($pdo);

$respond = static function (bool $ok, string $message, int $status = 200, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(false, 'Méthode non autorisée.', 405);
}

$csrf = (string)($_POST['feb_workflow_csrf'] ?? '');
if (empty($_SESSION['feb_workflow_csrf']) || !hash_equals((string)$_SESSION['feb_workflow_csrf'], $csrf)) {
    $respond(false, 'La session de validation a expiré. Rechargez la page.', 419);
}

$requestId = (int)($_POST['request_id'] ?? 0);
if ($requestId <= 0) $respond(false, 'FEB invalide.', 422);

$request = $manager->getRequestById($requestId);
if (!$request || ($request['request_type'] ?? '') !== 'feb' || ($request['assigned_team'] ?? '') !== 'finance') {
    $respond(false, 'FEB introuvable ou hors périmètre Finances.', 404);
}
if (!in_array((string)($request['status'] ?? ''), ['submitted', 'details_requested'], true)) {
    $respond(false, 'Cette FEB ne peut plus être modifiée.', 409);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$permissions = $userId > 0 ? \FebValidationPolicy::getUserServicePermissions($pdo, $userId) : ['all' => false, 'ids' => [], 'configured' => false, 'raw' => ''];
if (!\FebValidationPolicy::canValidateService($permissions, \FebValidationPolicy::requestServiceId($request))) {
    $respond(false, 'Vous n’êtes pas autorisé à modifier cette FEB.', 403);
}

$actor = ['user_id' => $userId, 'name' => trim((string)($_SESSION['user_name'] ?? ''))];
if ($actor['name'] === '') $actor['name'] = trim((string)(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));

$payload = (array)($request['payload'] ?? []);
$draft = is_array($payload['provisional_decaissement'] ?? null) ? (array)$payload['provisional_decaissement'] : [];
$chantierId = (int)($_POST['fd_chantier_id'] ?? 0);
$ligneDevisId = (int)($_POST['fd_ligne_devis_id'] ?? 0);
$debourseId = (int)($_POST['fd_debourse_id'] ?? 0);
$ligneDebourseId = (int)($_POST['fd_ligne_debourse_id'] ?? 0);
if ($chantierId <= 0 || $ligneDevisId <= 0 || $debourseId <= 0 || $ligneDebourseId <= 0) {
    $respond(false, 'Sélectionnez le chantier, la ligne de devis, le déboursé et la ligne de déboursé.', 422);
}

try {
    $official = \FinancialPlanningSeparationService::officialDeboursePredicate('db');
    $stmt = $pdo->prepare("SELECT c.id
        FROM chantier_banamur c
        INNER JOIN ligne_devis_banamur ld ON ld.id=:ligne_devis
        INNER JOIN debourse_banamur db ON db.id=:debourse AND db.ligne_devis_id=ld.id AND $official
        INNER JOIN ligne_debourse_banamur ldb ON ldb.id=:ligne_debourse AND ldb.debourse_id=db.id
        WHERE c.id=:chantier AND c.archived_at IS NULL
          AND (c.devis_id=db.devis_id OR EXISTS(
              SELECT 1 FROM devis_banamur chantier_devis
              INNER JOIN devis_banamur target_devis ON target_devis.id=db.devis_id
              WHERE chantier_devis.id=c.devis_id
                AND chantier_devis.offre_id IS NOT NULL
                AND chantier_devis.offre_id=target_devis.offre_id
          )) LIMIT 1");
    $stmt->execute(['chantier'=>$chantierId,'ligne_devis'=>$ligneDevisId,'debourse'=>$debourseId,'ligne_debourse'=>$ligneDebourseId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('L’affectation sélectionnée est incohérente. Vérifiez le chantier, la ligne de devis, le déboursé et le poste financier.');
    }

    $before = [
        'chantier_id'=>(int)($draft['chantier_id'] ?? 0),
        'ligne_devis_id'=>(int)($draft['ligne_devis_id'] ?? 0),
        'debourse_id'=>(int)($draft['debourse_id'] ?? 0),
        'ligne_debourse_id'=>(int)($draft['ligne_debourse_id'] ?? 0),
    ];
    $after = ['chantier_id'=>$chantierId,'ligne_devis_id'=>$ligneDevisId,'debourse_id'=>$debourseId,'ligne_debourse_id'=>$ligneDebourseId];
    $beneficiaryPayload = FebAssignmentBeneficiary::apply($pdo, $payload, $_POST);
    if ($before === $after && $beneficiaryPayload === $payload) $respond(true, 'Aucune modification d’affectation ou de bénéficiaire à enregistrer.', 200, ['changed'=>false]);
    $payload = $beneficiaryPayload;
    $draft = (array)($payload['provisional_decaissement'] ?? []);

    $draft = array_merge($draft, $after);
    $payload['provisional_decaissement'] = $draft;
    $payload['chantier_id'] = $chantierId;
    $note = sprintf('Affectation et bénéficiaire enregistrés sans approbation : chantier #%d, ligne de devis #%d, déboursé #%d, ligne de déboursé #%d.', $chantierId, $ligneDevisId, $debourseId, $ligneDebourseId);

    $pdo->beginTransaction();
    if (!$manager->updateFebBeforeDecision($requestId, $payload, $actor, $note, true)) {
        throw new RuntimeException('L’affectation de la FEB n’a pas pu être enregistrée.');
    }
    $manager->addRequestEvent($requestId, 'feb_assignment_adjusted_by_validator', $note, $actor);
    $pdo->commit();
    $respond(true, 'Affectation et bénéficiaire enregistrés sans approbation. Le statut de la FEB reste inchangé.', 200, ['changed'=>true,'assignment'=>$after]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $respond(false, $e->getMessage(), 422);
}
