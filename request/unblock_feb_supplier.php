<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../model/EmployeeLegalRequestManager.php';
require_once __DIR__.'/../model/FebValidationPolicy.php';
require_once __DIR__.'/../services/SupplierDisbursementUnblockService.php';
header('Content-Type: application/json; charset=utf-8');
try{
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('Méthode refusée.');
    $userId=(int)($_SESSION['user_id']??0);
    // Relire le droit au moment précis de l'action : une attribution faite dans
    // la matrice d'accès prend effet sans reconnexion ni rechargement du formulaire.
    AccessManager::refreshSessionPermissions();
    if(!AccessManager::hasPermission('regulariser_fournisseur_sans_preuve'))throw new RuntimeException('La permission « Régulariser un fournisseur sans preuve » est requise.');
    if(empty($_SESSION['feb_supplier_unblock_csrf'])||!hash_equals((string)$_SESSION['feb_supplier_unblock_csrf'],(string)($_POST['_csrf']??'')))throw new RuntimeException('Session expirée. Rechargez la page.');
    $name=trim((string)($_SESSION['user_name']??''))?:trim((string)(($_SESSION['prenom']??'').' '.($_SESSION['nom']??'')));
    $result=(new SupplierDisbursementUnblockService(Database::getConnection()))->unblock((int)($_POST['supplier_id']??0),$userId,$name,(string)($_POST['bypass_reason']??''));
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
