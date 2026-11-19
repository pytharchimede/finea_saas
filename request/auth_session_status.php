<?php
require_once __DIR__.'/../auth_check.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
