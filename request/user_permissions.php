<?php
session_start();
header('Content-Type: application/json');
require_once '../model/Database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Non authentifié']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("SELECT id, nom, prenom, mail_pro, modifier_devis, visualiser_devis, soumettre_devis, masquer_devis, envoyer_devis, valider_devis FROM user_devis_banamur WHERE id=:id AND active=1");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['status' => 'error', 'message' => 'Utilisateur introuvable']);
        exit;
    }
    $permissions = [
        'modifier_devis' => (int)$user['modifier_devis'],
        'visualiser_devis' => (int)$user['visualiser_devis'],
        'soumettre_devis' => (int)$user['soumettre_devis'],
        'masquer_devis' => (int)$user['masquer_devis'],
        'envoyer_devis' => (int)$user['envoyer_devis'],
        'valider_devis' => (int)$user['valider_devis'],
    ];
    echo json_encode(['status' => 'success', 'permissions' => $permissions, 'user' => [
        'id' => $user['id'],
        'nom' => $user['nom'],
        'prenom' => $user['prenom'],
        'mail' => $user['mail_pro']
    ]]);
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Erreur serveur']);
}
