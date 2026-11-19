<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';AccessManager::requirePageAccess();
if(!hasPermission('acces_feb')&&!hasPermission('acces_admin')){http_response_code(403);exit('Accès refusé');}
require_once __DIR__.'/../model/Database.php';require_once __DIR__.'/../services/FebPriceChallengeService.php';
$requestId=(int)($_POST['request_id']??0);$lineIndex=(int)($_POST['line_index']??-1);
try{
 if(!hash_equals((string)($_SESSION['feb_challenge_review_csrf']??''),(string)($_POST['csrf_token']??'')))throw new RuntimeException('Session expirée.');
 (new FebPriceChallengeService(Database::getConnection()))->retainInitial($requestId,$lineIndex,(int)($_SESSION['user_id']??0));
 header('Location: ../validation_feb_detail.php?id='.$requestId.'&initial_quote_retained=1#comparaison-prix');
}catch(Throwable $e){http_response_code(422);echo htmlspecialchars($e->getMessage());}
