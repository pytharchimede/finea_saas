<?php
declare(strict_types=1);
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/ClientProjectCompletenessAlertService.php';
$result=(new ClientProjectCompletenessAlertService(Database::getConnection()))->dispatch();
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
