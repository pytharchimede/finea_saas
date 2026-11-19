<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $rows=Database::getConnection()->query("SELECT id_client,logo_client FROM client_banamur WHERE logo_client IS NOT NULL AND TRIM(logo_client)<>''")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $logos=[];foreach($rows as $row)$logos[(string)$row['id_client']]=(string)$row['logo_client'];
    echo json_encode(['ok'=>true,'logos'=>$logos],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    http_response_code(503);echo json_encode(['ok'=>false,'logos'=>[]]);
}
