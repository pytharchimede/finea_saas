<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../model/Devis.php';
require_once __DIR__.'/../repositories/CommercialProjectFinanceRepository.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!AccessManager::hasPermission('modifier_classement_devis')) { http_response_code(403); exit('Accès refusé.'); }
if (empty($_SESSION['devis_offer_csrf']) || !hash_equals((string)$_SESSION['devis_offer_csrf'], (string)($_POST['_csrf'] ?? ''))) {
    $_SESSION['devis_offer_flash_error']='Session expirée. Rechargez la page.';
    header('Location: ../liste_devis.php?archive_status=masked'); exit;
}
try {
    $pdo=Database::getConnection();$id=(int)($_POST['devis_id']??0);
    if ($id<=0) throw new DomainException('Devis invalide.');
    (new Devis($pdo))->restaurerDevisMasque($id);
    // Un devis peut avoir été masqué et archivé simultanément : une restauration doit être complète.
    (new CommercialProjectFinanceRepository($pdo))->restore($id,(int)($_SESSION['user_id']??0),'Restauration depuis la liste des devis');
    $_SESSION['devis_offer_flash_success']='Le devis est de nouveau visible dans les devis actifs.';
} catch (Throwable $e) { $_SESSION['devis_offer_flash_error']=$e->getMessage(); }
header('Location: ../liste_devis.php?archive_status=active');
exit;
