<?php

session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
@require_once __DIR__ . '/config/app_config.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/DatabaseApp.php';
require_once __DIR__ . '/model/FicheDecaissement.php';
require_once __DIR__ . '/../services/NotificationWhatsAppChannelService.php';
require_once __DIR__ . '/model/DemandeEssence.php';

header('Content-Type: text/html; charset=utf-8');

if (empty($_GET['num_fiche'])) {
    http_response_code(400);
    echo "Numéro de fiche manquant.";
    exit;
}

$num_fiche = trim($_GET['num_fiche']);
$secur = $_SESSION['secur_projet'] ?? $_SESSION['secur_hop'] ?? 'SYSTEM';
$adresse_ip = $_SERVER['REMOTE_ADDR'] ?? '';
$port = (int)($_SERVER['REMOTE_PORT'] ?? 0);

// Connexion principale (projet)
$pdo = Database::getConnection();
$ficheModel = new FicheDecaissement($pdo);

// 1) Certifier conforme
$ficheModel->setConforme($num_fiche, $secur);

// 2) Approuver (endpoint dédié existe aussi). Ici on force approuve=1 si pas déjà.
$pdo->beginTransaction();
try {
    $st = $pdo->prepare("SELECT approuve, decaisse FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE");
    $st->execute(['n' => $num_fiche]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Fiche introuvable');
    if ((int)$row['decaisse'] === 1) throw new Exception('Fiche déjà décaissée');
    if ((int)$row['approuve'] !== 1) {
        $up = $pdo->prepare("UPDATE fiche_decaissement SET approuve=1, date_approbation=NOW(), secur_valid=:s WHERE num_fiche=:n");
        $up->execute(['s' => $secur, 'n' => $num_fiche]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    AuditTrail::log('carburant_fiche_approve_failed', [
        'module' => 'decaissement',
        'action' => 'valider_fiche_carburant',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num_fiche,
        'entity_label' => 'Validation fiche carburant',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'num_fiche' => $num_fiche,
            'error_message' => $e->getMessage(),
            'step' => 'approbation',
        ],
    ], $pdo);
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo "Erreur étape approbation: " . htmlspecialchars($e->getMessage());
    exit;
}

// 3) Valider achat
$ok = $ficheModel->validateAchat($num_fiche, $secur, $adresse_ip, $port);
if (!$ok) {
    AuditTrail::log('carburant_fiche_validate_failed', [
        'module' => 'decaissement',
        'action' => 'valider_fiche_carburant',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num_fiche,
        'entity_label' => 'Validation fiche carburant',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'num_fiche' => $num_fiche,
            'step' => 'validate_achat',
        ],
    ], $pdo);
    http_response_code(409);
    echo "Impossible de valider la fiche (prérequis non satisfaits ou déjà validée).";
    exit;
}

// Récup infos fiche pour construire le bon
$fiche = $ficheModel->getByNum($num_fiche);
if (!$fiche) {
    AuditTrail::log('carburant_fiche_validate_failed', [
        'module' => 'decaissement',
        'action' => 'valider_fiche_carburant',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num_fiche,
        'entity_label' => 'Validation fiche carburant',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'num_fiche' => $num_fiche,
            'step' => 'reload_fiche',
        ],
    ], $pdo);
    http_response_code(404);
    echo "Fiche introuvable après validation.";
    exit;
}

$benef = $fiche['beficiaire_fiche'] ?? '';
$tel = preg_replace('/\D+/', '', (string)($fiche['tel_beneficiaire_fiche'] ?? ''));
$whatsappNumber = $tel ? ('+225' . $tel) : '';
$designation = $fiche['designation_fiche'] ?? '';
$montant = (float)($fiche['montant_fiche'] ?? 0);
$date_crea = $fiche['date_creat_fiche'] ?? date('Y-m-d H:i:s');
$precision = $fiche['precision_fiche'] ?? '';

// Génération code_bon (BE-YYMM-xxxxx)
$dt = new DateTime($date_crea);
$seq = preg_replace('/\D+/', '', $num_fiche);
$seq = substr(str_pad($seq, 5, '0', STR_PAD_LEFT), -5);
$code_bon = 'BE-' . $dt->format('ym') . '-' . $seq;

// Connexion secondaire (APP) et création du bon
$pdoApp = DatabaseApp::getConnection();
$demandeEssence = new DemandeEssence($pdoApp);

// Si code déjà existant, suffixer pour unicité
$existing = $demandeEssence->getByCodeBon($code_bon);
if ($existing) {
    $i = 2;
    do {
        $candidate = $code_bon . '-' . $i;
        $exists = $demandeEssence->getByCodeBon($candidate);
        if (!$exists) {
            $code_bon = $candidate;
            break;
        }
        $i++;
    } while ($i < 100);
}

$data = [
    'num_fiche'        => $num_fiche,
    'code_bon'         => $code_bon,
    'nom_beneficiaire' => $benef,
    'vehicule'         => $designation,
    'quantite'         => 0, // si quantité connue ailleurs, remplacer
    'montant'          => $montant,
    'date_demande'     => (new DateTime($date_crea))->format('Y-m-d'),
    'motif'            => $precision,
    'dg_nom'           => 'Direction Générale'
];

$bonId = $demandeEssence->create($data);

// Envoi WhatsApp au demandeur avec code_bon
$sid = defined('TWILIO_SID') ? TWILIO_SID : (getenv('TWILIO_SID') ?: '');
$token = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : (getenv('TWILIO_TOKEN') ?: '');
$from = defined('TWILIO_WHATSAPP_FROM') ? TWILIO_WHATSAPP_FROM : (getenv('TWILIO_WHATSAPP_FROM') ?: '');
$wa = new NotificationWhatsAppChannelService($sid, $token, $from);
$sent = ['status' => 'error', 'message' => 'Numéro WhatsApp manquant'];
if ($whatsappNumber) {
    $sent = $wa->sendCarburantBon($whatsappNumber, $benef ?: 'Cher(e) collègue', $code_bon);
}

AuditTrail::log('carburant_fiche_validated', [
    'module' => 'decaissement',
    'action' => 'valider_fiche_carburant',
    'entity_type' => 'fiche_decaissement',
    'entity_id' => $num_fiche,
    'entity_label' => 'Validation fiche carburant ' . $num_fiche,
    'result' => 'success',
    'details' => [
        'num_fiche' => $num_fiche,
        'beneficiaire' => $benef,
        'montant' => $montant,
        'code_bon' => $code_bon,
        'bon_id' => $bonId ?: null,
        'whatsapp_status' => $sent['status'] ?? null,
    ],
], $pdo);

echo '<h2>Validation express carburant effectuée</h2>';
echo '<ul>';
echo '<li>Fiche: ' . htmlspecialchars($num_fiche) . ' (conforme + approuvée + validée)</li>';
echo '<li>Bon d\'essence: ' . htmlspecialchars($code_bon) . ($bonId ? ' (ID ' . (int)$bonId . ')' : '') . ' enregistré dans la base APP</li>';
echo '<li>WhatsApp: ' . htmlspecialchars($sent['status']) . (!empty($sent['message']) ? (' - ' . htmlspecialchars($sent['message'])) : '') . '</li>';
echo '</ul>';
