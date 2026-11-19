<?php
require_once __DIR__.'/../auth_check.php'; AccessManager::requirePageAccess();
if(!hasPermission('acces_feb')&&!hasPermission('acces_admin')){http_response_code(403);exit('Accès refusé');}
require_once __DIR__.'/../model/Database.php';require_once __DIR__.'/../services/FebPriceChallengeService.php';
$id=(int)($_POST['challenge_id']??0);$return=(int)($_POST['return_id']??0);
try{if(!hash_equals((string)($_SESSION['feb_challenge_review_csrf']??''),(string)($_POST['csrf_token']??'')))throw new RuntimeException('Session expirée.');(new FebPriceChallengeService(Database::getConnection()))->retain($id,(int)($_SESSION['user_id']??0));header('Location: ../validation_feb_detail.php?id='.$return.'&challenge_retained=1');}catch(Throwable $e){http_response_code(422);echo htmlspecialchars($e->getMessage());}
