<?php

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

require_once __DIR__ . '/passkey_bootstrap.php';
passkeyRequirePost();

try {
    $attempt = $mobilePasskeyRepository->create(session_id(), (int)$passkeyConfig['challenge_ttl']);
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/request/mobile_passkey_start.php'));
    $basePath = rtrim(dirname(dirname($script)), '/.');
    $mobileUrl = rtrim((string)$passkeyConfig['origin'], '/') . ($basePath !== '' ? '/' . ltrim($basePath, '/') : '')
        . '/mobile_passkey_login.php?token=' . rawurlencode($attempt['token']);

    $qr = QrCode::create($mobileUrl)->setSize(300)->setMargin(12);
    $qrDataUri = (new SvgWriter())->write($qr)->getDataUri();

    passkeyJson([
        'status'=>'success',
        'public_id'=>$attempt['public_id'],
        'expires_in'=>$attempt['expires_in'],
        'mobile_url'=>$mobileUrl,
        'qr_data_uri'=>$qrDataUri,
    ]);
} catch (Throwable $e) {
    error_log('[mobile_passkey_start] '.$e->getMessage());
    passkeyJson(['status'=>'error','message'=>'Impossible de préparer la connexion par téléphone.'], 500);
}
