<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/ManualTreasuryService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$canCancel=AccessManager::hasPermission('acces_facturation')
    || AccessManager::hasPermission('acces_direction')
    || AccessManager::hasPermission('acces_admin');
if (!$canCancel) { http_response_code(403); exit('Accès réservé à la Facturation, à la Direction ou à l’administration.'); }
$back='../liste_devis.php?archive_status='.urlencode((string)($_POST['archive_status']??'all'));
if (empty($_SESSION['devis_offer_csrf']) || !hash_equals((string)$_SESSION['devis_offer_csrf'], (string)($_POST['_csrf'] ?? ''))) {
    $_SESSION['devis_offer_flash_error']='Session expirée. Rechargez la page.';header('Location: '.$back);exit;
}
try {
    $pdo=Database::getConnection();$service=new ManualTreasuryService($pdo);$devisId=(int)($_POST['devis_id']??0);$reason=trim((string)($_POST['reason']??''));
    if ($devisId<=0) throw new DomainException('Devis invalide.');
    $paid=$pdo->prepare("SELECT COUNT(*) FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id WHERE c.devis_id=:id AND LOWER(TRIM(COALESCE(fv.paiement_statut,'emise')))='payee'");
    $paid->execute(['id'=>$devisId]);
    if ((int)$paid->fetchColumn()>0) throw new DomainException('Annulation refusée : au moins une facture de ce devis est déjà encaissée.');
    $query=$pdo->prepare("SELECT fv.id_facture_vente FROM facture_vente_banamur fv JOIN chantier_banamur c ON c.id=fv.chantier_id LEFT JOIN treasury_invoice_collection_cancellations tic ON tic.invoice_id=fv.id_facture_vente WHERE c.devis_id=:id AND LOWER(TRIM(COALESCE(fv.paiement_statut,'emise')))='emise' AND tic.invoice_id IS NULL");
    $query->execute(['id'=>$devisId]);$ids=array_map('intval',$query->fetchAll(PDO::FETCH_COLUMN)?:[]);
    if (!$ids) throw new DomainException('Aucune facturation active à annuler pour ce devis.');
    $user=(int)($_SESSION['user_id']??0);
    foreach($ids as $invoiceId)$service->cancelInvoiceCollection($invoiceId,$reason?:'Facturation annulée depuis la liste des devis',$user);
    $_SESSION['devis_offer_flash_success']=count($ids).' facture(s) non encaissée(s) annulée(s). L’historique reste consultable.';
} catch (Throwable $e) { $_SESSION['devis_offer_flash_error']=$e->getMessage(); }
header('Location: '.$back);
exit;
