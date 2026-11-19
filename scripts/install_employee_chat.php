<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../model/EmployeeChat.php';

EmployeeChat::ensureTables(Database::getConnection());
fwrite(STDOUT, "Employee chat schema and service groups are ready.\n");
