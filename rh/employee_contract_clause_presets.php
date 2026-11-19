<?php
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess('contrats_employes_rh.php');
require_once __DIR__.'/../services/RhEmployeeContractClausePresetService.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$type=strtoupper(trim((string)($_GET['type']??'AUTRE')));
try{
    echo json_encode(['ok'=>true,'type'=>$type,'clauses'=>RhEmployeeContractClausePresetService::forType($type)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(Throwable $e){
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Impossible de charger les clauses par défaut.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
