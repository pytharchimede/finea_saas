<?php
require_once 'model/Database.php';
require_once 'model/User.php';
require_once 'model/AuditTrail.php';

$pdo = Database::getConnection();
$userModel = new User($pdo);

if (isset($_GET['id'])) {
    $userId = $_GET['id'];
    $userModel->desactiverUtilisateur($userId);

    \AuditTrail::log('user_deactivated', [
        'module' => 'users',
        'action' => 'desactiver_utilisateur',
        'entity_type' => 'user',
        'entity_id' => (string) $userId,
        'entity_label' => 'user_' . $userId,
    ], $pdo);

    // Redirection après la désactivation
    header('Location: liste_utilisateur.php');
    exit;
}
