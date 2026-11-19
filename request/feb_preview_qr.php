<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../phpqrcode/qrlib.php';

$data = trim((string)($_GET['data'] ?? ''));
if ($data === '') {
    $data = 'FINEA|FEB|BANAMUR';
}
if (mb_strlen($data) > 1200) {
    $data = mb_substr($data, 0, 1200);
}

header('Content-Type: image/png');
header('Cache-Control: private, max-age=300');
QRcode::png($data, false, QR_ECLEVEL_H, 4, 1);
