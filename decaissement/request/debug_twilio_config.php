<?php
// Petit endpoint de diagnostic (à restreindre) qui indique si la config Twilio est détectée sans afficher les secrets.
header('Content-Type: application/json');
@require_once __DIR__ . '/../config/app_config.php';

function mask($v)
{
    if (!$v) return null;
    $len = strlen($v);
    if ($len <= 6) return str_repeat('*', $len);
    return substr($v, 0, 3) . str_repeat('*', max(0, $len - 6)) . substr($v, -3);
}

$sid = defined('TWILIO_SID') ? TWILIO_SID : (getenv('TWILIO_SID') ?: '');
$token = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : (getenv('TWILIO_TOKEN') ?: '');
$from = defined('TWILIO_WHATSAPP_FROM') ? TWILIO_WHATSAPP_FROM : (getenv('TWILIO_WHATSAPP_FROM') ?: '');
$dg = defined('DG_PHONE') ? DG_PHONE : (getenv('DG_PHONE') ?: '');

echo json_encode([
    'twilio' => [
        'sid_present' => (bool)$sid,
        'token_present' => (bool)$token,
        'from_present' => (bool)$from,
        'sid_masked' => mask($sid),
        'from_value' => $from ? (strpos($from, 'whatsapp:+') === 0 ? 'OK_FORMAT' : 'INVALID_FORMAT') : null,
    ],
    'dg_phone_present' => (bool)$dg,
    'hint' => 'Placez vos identifiants dans decaissement/config/twilio.local.php (voir twilio.example.php) ou via variables d\'environnement PHP/Apache.',
]);
