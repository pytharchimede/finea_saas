<?php
// Endpoint d'approbation des bons d'essence via lien signé
// Usage (GET): approval.php?code_bon=BE-YYMM-XXXXX[(-N)]&ts=UNIX_EPOCH&sig=HMAC_SHA256&phone=%2B22507XXXXXXX
// Fallback possible en dev: approval.php?code_bon=...&token=APPROVAL_TOKEN

require_once __DIR__ . '/../../model/EnvConfig.php';
\EnvConfig::loadFromDirectory(__DIR__);

header('Content-Type: text/html; charset=utf-8');

@require_once __DIR__ . '/../config/app_config.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/DatabaseApp.php';
require_once __DIR__ . '/../model/DemandeEssence.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../services/NotificationWhatsAppChannelService.php';

// Petites utilitaires
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Lecture des paramètres
$code_bon = trim($_GET['code_bon'] ?? ($_GET['id_bon'] ?? ''));
$ts = isset($_GET['ts']) ? (int)$_GET['ts'] : 0;
$sig = $_GET['sig'] ?? '';
$token = $_GET['token'] ?? '';
$approverPhone = trim($_GET['phone'] ?? ''); // format international sans "whatsapp:"

// Vérification signature/token
$secret = \EnvConfig::get('BON_APPROVAL_SECRET', '') ?: '';
$approvalToken = \EnvConfig::get('APPROVAL_TOKEN', '') ?: '';
$now = time();
$ttl = 60 * 60 * 48; // 48h

$sigOk = false;
if ($code_bon !== '' && $secret !== '' && $ts > 0 && ($now - $ts) <= $ttl) {
    $base = $code_bon . '|' . $ts;
    $calc = hash_hmac('sha256', $base, $secret);
    if (hash_equals($calc, $sig)) $sigOk = true;
}
if (!$sigOk && $approvalToken !== '' && $token !== '' && hash_equals($approvalToken, $token)) {
    $sigOk = true; // fallback dev/token simple
}

if ($code_bon === '' || !$sigOk) {
    http_response_code(403);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Accès refusé</title></head><body>';
    echo '<h3>Accès refusé</h3><p>Le lien est invalide ou expiré.</p></body></html>';
    exit;
}

// Connexions BDD
$pdoProj = \Database::getConnection(); // Projet (nouveau système)
$pdoApp = \DatabaseApp::getConnection(); // Legacy APP

$demProj = new \DemandeEssence($pdoProj);
$demApp  = new \DemandeEssence($pdoApp);
$ficheRepo = new \FicheDecaissement($pdoProj);

// Mettre à jour statut d'approbation sur les deux bases (idempotent)
$demApp->setApprovedByCode($code_bon, 'LINK', ($approverPhone !== '' ? $approverPhone : null));
$demProj->setApprovedByCode($code_bon, 'LINK', ($approverPhone !== '' ? $approverPhone : null));

// Récup info fiche pour notifications
$dem = $demApp->getByCodeBon($code_bon) ?: $demProj->getByCodeBon($code_bon);
$num_fiche = $dem['num_fiche'] ?? null;
$fiche = $num_fiche ? $ficheRepo->getByNum($num_fiche) : null;

// Préparer WhatsApp
$sid = defined('TWILIO_SID') ? TWILIO_SID : (\EnvConfig::get('TWILIO_SID', '') ?: '');
$tokenTw = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : (\EnvConfig::get('TWILIO_TOKEN', '') ?: '');
$from = defined('TWILIO_WHATSAPP_FROM') ? TWILIO_WHATSAPP_FROM : (\EnvConfig::get('TWILIO_WHATSAPP_FROM', '') ?: '');
$wa = ($sid && $tokenTw && $from) ? new \NotificationWhatsAppChannelService($sid, $tokenTw, $from) : null;

$dgPhone = defined('DG_PHONE') ? DG_PHONE : (\EnvConfig::get('DG_PHONE', '') ?: '');
// STATION_PHONE n'est pas une constante standard: on lit uniquement via env si présent
$stationPhone = \EnvConfig::get('STATION_PHONE', '') ?: '';

// Envoi notifications (best effort, silencieux en cas d'erreur)
try {
    if ($wa && $fiche) {
        $benef = $fiche['beficiaire_fiche'] ?? ($dem['nom_beneficiaire'] ?? 'Bénéficiaire');
        $montant = (string)($fiche['montant_fiche'] ?? ($dem['montant'] ?? 0));
        $date_str = isset($fiche['date_creat_fiche']) ? (new DateTime($fiche['date_creat_fiche']))->format('d/m/Y') : ((new DateTime($dem['date_demande'] ?? date('Y-m-d')))->format('d/m/Y'));

        // 1) Notification au Directeur Logistique (DG_PHONE): confirmation d'approbation
        if ($dgPhone) {
            $to = '+225' . preg_replace('/\D+/', '', $dgPhone);
            // nom_demandeur, num_fiche, nom_validateur
            $wa->sendConfirmationApprobation($to, $benef, ($num_fiche ?: $code_bon), 'Lien WhatsApp');
        }

        // 2) Notification Station (si configurée): call-to-action gérer/servir
        if ($stationPhone) {
            $to = '+225' . preg_replace('/\D+/', '', $stationPhone);
            $wa->sendNotifCreatToGerant($to, $code_bon, $benef, $montant, $date_str, /* id_bon_view */ $code_bon, /* id_bon_confirm */ $code_bon);
        }

        // 3) Optionnel: notifier l'approbateur si phone fourni
        if ($approverPhone !== '') {
            $to = (strpos($approverPhone, '+') === 0) ? $approverPhone : ('+225' . preg_replace('/\D+/', '', $approverPhone));
            $wa->sendConfirmationApprobation($to, $benef, ($num_fiche ?: $code_bon), 'Vous');
        }
    }
} catch (\Throwable $e) {
    // silencieux
}

// Sortie simple (HTML léger + lien bon imprimable)
$bonUrl = '../bon/bon_essence.php?id_bon=' . rawurlencode($code_bon);
echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<title>Bon approuvé</title><style>body{font-family:Arial,sans-serif;padding:24px} .card{max-width:560px;margin:0 auto;border:1px solid #ddd;border-radius:8px;padding:16px} a.btn{display:inline-block;padding:10px 14px;border-radius:6px;background:#0d6efd;color:#fff;text-decoration:none}</style></head><body>';
echo '<div class="card">';
echo '<h2>Approbation confirmée</h2>';
echo '<p>Le bon <strong>' . h($code_bon) . '</strong> a été marqué comme approuvé.</p>';
if ($num_fiche) echo '<p>Fiche liée: <strong>' . h($num_fiche) . '</strong></p>';
echo '<p><a class="btn" href="' . h($bonUrl) . '" target="_blank">Ouvrir le bon d\'essence</a></p>';
echo '<p style="color:#666;font-size:12px">Vous pouvez fermer cette page.</p>';
echo '</div></body></html>';
