<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../services/InternationalBusinessIdentity.php';
header('Content-Type: application/json; charset=utf-8');
if (!AccessManager::hasPermission('acces_liste_client') && !AccessManager::hasPermission('acces_fournisseur') && !AccessManager::hasPermission('acces_compta') && !AccessManager::hasPermission('acces_admin') && !AccessManager::hasPermission('acces_employe')) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès refusé.']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {http_response_code(405); exit;}
try {
    // Preview never accepts documentary approval or writes to the database.
    $result=(new InternationalBusinessIdentity())->resolve($_POST);
    echo json_encode(['ok'=>true,'identity'=>$result],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (DomainException $e) {http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
catch (Throwable $e) {http_response_code(503); echo json_encode(['ok'=>false,'error'=>'Le registre est indisponible. Réessayez ultérieurement.']);}
