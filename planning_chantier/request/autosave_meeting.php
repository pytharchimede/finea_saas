<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try{
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$input=$_POST;
    if(!hash_equals((string)($_SESSION['pc_financial_csrf']??''),(string)($input['csrf']??'')))throw new RuntimeException('Session expirée.');
    $preparationId=(int)($input['preparation_id']??0);
    if(!(new PlanningMeetingPreparationRepository($pdo))->findReady($preparationId))throw new RuntimeException('Préparation inactive.');
    (new PlanningMeetingRepository($pdo))->autosaveDraft($preparationId,(string)($input['resume']??''),(string)($input['decisions']??''));
    echo json_encode(['ok'=>true,'saved_at'=>date('H:i:s')]);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
