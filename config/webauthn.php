<?php

require_once __DIR__ . '/../model/EnvConfig.php';

\EnvConfig::loadFromDirectory(dirname(__DIR__));

$rpId = trim((string) \EnvConfig::get('WEBAUTHN_RP_ID', ''));
$origin = rtrim(trim((string) \EnvConfig::get('WEBAUTHN_ORIGIN', '')), '/');
$rpName = trim((string) \EnvConfig::get('WEBAUTHN_RP_NAME', 'FINEA'));

if ($rpId === '') {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $rpId = preg_replace('/:\d+$/', '', $host) ?: 'localhost';
}

if ($origin === '') {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? $rpId);
    $origin = ($isHttps ? 'https' : 'http') . '://' . $host;
}

return [
    'rp_id' => $rpId,
    'rp_name' => $rpName !== '' ? $rpName : 'FINEA',
    'origin' => $origin,
    'challenge_ttl' => 300,
    'timeout_ms' => 120000,
];
