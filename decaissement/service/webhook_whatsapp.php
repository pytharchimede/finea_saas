<?php
require_once __DIR__ . '/../../services/NotificationWhatsAppChannelService.php';
require_once '../model/Database.php'; // Classe Database pour la connexion à la base de données
require_once '../model/DemandeEssence.php'; // Classe DemandeEssence pour gérer les demandes d'essence

file_put_contents(__DIR__ . '/webhook_log.txt', date('Y-m-d H:i:s') . ' - ' . print_r($_POST, true) . PHP_EOL, FILE_APPEND);
// Connexion à la base de données
$databaseObj = new Database();
$pdo = $databaseObj->getConnection(); // Obtenir la connexion PDO

// Vérifier si la connexion a réussi    
if (!$pdo) {
    http_response_code(500); // Erreur interne du serveur
    error_log("Erreur de connexion à la base de données.");
    echo "Erreur de connexion à la base de données.";
    exit;
}

$demandeObj = new DemandeEssence($pdo); // Instance de DemandeEssence



// Configuration Twilio
$sid = "ACded19f6cd55b2ba3d18c13f438f1e878"; // SID Twilio
$token = "fea6684286c6b923e8ce0ce19bc48cb4"; // AuthToken Twilio
$from = "whatsapp:+2250711048002"; // Numéro WhatsApp Twilio
$whatsapp = new NotificationWhatsAppChannelService($sid, $token, $from); // Instance WhatsAppSMS

// Récupérer les paramètres envoyés par Twilio dans le webhook
$sender = isset($_POST['From']) ? $_POST['From'] : null; // Numéro de l'utilisateur (WhatsApp)
$body = isset($_POST['Body']) ? trim(strtolower($_POST['Body'])) : null;
$recipientNumber = isset($_POST['To']) ? $_POST['To'] : null; // Numéro du destinataire
$waId = isset($_POST['WaId']) ? $_POST['WaId'] : null; // ID WhatsApp (WaId)

// Vérifier que les paramètres requis sont présents
if (!$sender || !$body || !$recipientNumber) {
    http_response_code(400); // Mauvaise requête
    error_log("Requête invalide. Paramètres manquants : " . json_encode($_POST));
    echo "Requête invalide. Paramètres manquants.";
    exit;
}


// Extraire le numéro sans le préfixe "whatsapp:"
$senderNumber = str_replace("whatsapp:", "", $sender);  // Retirer le "whatsapp:" du début du numéro
$senderNumberWithoutPrefix = '07' . substr($senderNumber, 4); // Retirer le préfixe pays "+225" si nécessaire

// Vérifier que le numéro est correct
if (empty($senderNumberWithoutPrefix)) {
    http_response_code(400); // Mauvaise requête
    error_log("Numéro invalide : " . $sender);
    echo "Numéro invalide.";
    exit;
}

// Exemple : données à récupérer ou à adapter selon ton besoin
if ($body === "approuver") {
    // Exemple de récupération d'infos (à adapter selon ton workflow)
    $data = [
        'nom_beneficiaire' => 'Nom à définir', // À récupérer selon le contexte
        'vehicule'         => 'Véhicule à définir',
        'quantite'         => 50, // À définir
        'montant'          => 50000, // À définir
        'date_demande'     => date('Y-m-d'),
        'motif'            => 'Demande validée via WhatsApp',
        'dg_nom'           => 'Alex BRAUD' // À définir
    ];
    $demandeObj->create($data);
    $body = "Votre demande de carburant a été approuvée et le bon d'essence est généré.";
}

// Envoi de la réponse via WhatsApp
$num_recepteur_reponse = '+225' . $senderNumberWithoutPrefix;
try {
    $whatsapp->sendMessage($num_recepteur_reponse, $body); // Répondre à l'expéditeur
    echo "Message envoyé avec succès.";
} catch (Exception $e) {
    error_log("Erreur lors de l'envoi du message WhatsApp : " . $e->getMessage());
    echo "Erreur lors de l'envoi du message.";
}

http_response_code(200); // Réponse réussie
