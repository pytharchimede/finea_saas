<?php
require_once 'auth_check.php';
require_once 'model/Database.php';
require_once 'model/User.php';
require_once 'model/AuditTrail.php';

$pdo = Database::getConnection();
$userModel = new User($pdo);

if (isset($_GET['id'])) {
    $userId = $_GET['id'];
    $userModel->supprimerUtilisateur($userId);

    AuditTrail::log('user_deleted', [
        'module' => 'core',
        'action' => 'supprimer_utilisateur',
        'entity_type' => 'user',
        'entity_id' => (string)$userId,
        'entity_label' => 'Suppression utilisateur',
        'result' => 'success',
        'severity' => 'warning',
        'details' => [
            'view_mode' => 'action_endpoint',
        ],
    ], $pdo);

    header('Location: liste_utilisateur.php');
    exit;
}
