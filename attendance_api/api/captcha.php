<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

try {
    // Captcha URL: /manage/login/checkCode.html?systype=2&t=<timestamp>
    $cfg = epordo_config();
    $ts = (string)round(microtime(true) * 1000);
    $url = epordo_backend_url('login/checkCode.html')
        . '?systype=' . urlencode((string)($cfg['systype'] ?? 2))
        . '&t=' . urlencode($ts);

    $resp = epordo_curl_request('GET', $url);

    // Forward content-type when available.
    $contentType = $resp['headers']['content-type'] ?? 'image/jpeg';
    header('Content-Type: ' . $contentType);
    header('Cache-Control: no-store');

    http_response_code($resp['status'] >= 200 && $resp['status'] < 400 ? 200 : 502);
    echo $resp['body'];
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Captcha error: ' . $e->getMessage();
}
