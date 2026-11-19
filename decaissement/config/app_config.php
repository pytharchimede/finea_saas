<?php
// Charge la configuration applicative (Twilio & co) depuis un override local ou les variables d'environnement.
require_once __DIR__ . '/../../model/EnvConfig.php';
\EnvConfig::loadFromDirectory(__DIR__);

// 1) Override local optionnel (non versionné recommandé): decaissement/config/twilio.local.php
//    Copiez le fichier twilio.example.php en twilio.local.php et remplissez vos identifiants.
$twilioLocal = __DIR__ . '/twilio.local.php';
if (is_file($twilioLocal)) {
    // Le fichier local peut définir les constantes: TWILIO_SID, TWILIO_TOKEN, TWILIO_WHATSAPP_FROM, DG_PHONE
    require_once $twilioLocal;
}

// 2) Fallback: variables d'environnement (si les constantes ne sont pas encore définies)
if (!defined('TWILIO_SID')) {
    $v = \EnvConfig::get('TWILIO_SID');
    if ($v !== false && $v !== '') define('TWILIO_SID', $v);
}
if (!defined('TWILIO_TOKEN')) {
    $v = \EnvConfig::get('TWILIO_TOKEN');
    if ($v !== false && $v !== '') define('TWILIO_TOKEN', $v);
}
if (!defined('TWILIO_WHATSAPP_FROM')) {
    $v = \EnvConfig::get('TWILIO_WHATSAPP_FROM');
    if ($v !== false && $v !== '') define('TWILIO_WHATSAPP_FROM', $v);
}
if (!defined('DG_PHONE')) {
    $v = \EnvConfig::get('DG_PHONE');
    define('DG_PHONE', ($v !== false && $v !== '') ? $v : '0544577666');
}

// Remarque: Assurez-vous que TWILIO_WHATSAPP_FROM a le format: whatsapp:+225XXXXXXXXX
// Exemple: whatsapp:+2250711048002
