<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try{
    if(class_exists('AccessManager')&&!AccessManager::hasPageAccess('planning_chantier/reunion.php'))throw new RuntimeException('Accès refusé.');
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$input=$_POST;
    if(!hash_equals((string)($_SESSION['pc_financial_csrf']??''),(string)($input['csrf']??'')))throw new RuntimeException('Session expirée.');
    throw new DomainException('La création de catégories financières depuis une réunion est désactivée. Utilisez les déboursés officiels dans le module Déboursé.');
}catch(Throwable $e){
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
