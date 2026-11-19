<?php
declare(strict_types=1);

require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/DevisOfferProjectMigrationService.php';
require_once __DIR__.'/../repositories/DevisOfferProjectRepository.php';
require_once __DIR__.'/../model/AuditTrail.php';

AccessManager::requirePageAccess();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$token = (string)($_POST['_csrf'] ?? '');
if (empty($_SESSION['devis_offer_csrf']) || !hash_equals((string)$_SESSION['devis_offer_csrf'], $token)) {
    http_response_code(403);
    $_SESSION['devis_offer_flash_error'] = 'Jeton CSRF invalide.';
    header('Location: ../devis/liste.php');
    exit;
}

$devisId = max(0, (int)($_POST['devis_id'] ?? 0));
if ($devisId < 1) {
    $_SESSION['devis_offer_flash_error'] = 'Devis TS invalide.';
    header('Location: ../devis/liste.php');
    exit;
}

try {
    $pdo = Database::getConnection();
    (new DevisOfferProjectMigrationService($pdo))->ensureSchema();

    $stmt = $pdo->prepare(
        "SELECT d.id,d.offre_id,d.numero_devis,c.devis_type
         FROM devis_banamur d
         INNER JOIN devis_offer_classifications c ON c.devis_id=d.id
         WHERE d.id=:id LIMIT 1"
    );
    $stmt->execute(['id'=>$devisId]);
    $devis = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$devis || strtoupper((string)($devis['devis_type'] ?? '')) !== 'TS') {
        throw new RuntimeException('Ce devis n’est pas classé comme travaux supplémentaires (TS).');
    }

    $offerId = (int)($devis['offre_id'] ?? 0);
    if ($offerId < 1) {
        throw new RuntimeException('Le TS n’est rattaché à aucune offre/projet.');
    }

    $repo = new DevisOfferProjectRepository($pdo);
    $principalDevisId = $repo->principalForOffer($offerId);
    if ($principalDevisId < 1) {
        throw new RuntimeException('Aucun devis principal n’est défini pour cette offre/projet.');
    }

    $chantierId = $repo->linkTsToPrincipalChantier($devisId, $offerId);
    if (!$chantierId) {
        throw new RuntimeException('Le chantier du devis principal n’existe pas encore. Démarrez uniquement le chantier principal avant d’activer ses TS.');
    }

    AuditTrail::log('devis_ts_activated', [
        'module'=>'devis',
        'action'=>'devis_ts_activated',
        'entity_type'=>'devis',
        'entity_id'=>(string)$devisId,
        'result'=>'success',
        'details'=>[
            'devis_id'=>$devisId,
            'numero_devis'=>$devis['numero_devis'] ?? null,
            'offre_id'=>$offerId,
            'principal_devis_id'=>$principalDevisId,
            'chantier_id'=>$chantierId,
        ],
    ]);

    $_SESSION['devis_offer_flash_success'] = 'TS activé sur le chantier existant. Aucun nouveau chantier n’a été créé.';
} catch (Throwable $e) {
    $_SESSION['devis_offer_flash_error'] = $e->getMessage();
}

header('Location: ../devis/liste.php#devis-'.$devisId);
exit;
