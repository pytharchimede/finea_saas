<?php

session_start();

require_once '../model/Database.php';
require_once '../model/User.php';
require_once '../../model/AuditTrail.php';

$pdo = Database::getConnection();
$userModel = new User($pdo);
$userId = (int) ($_SESSION['user_id'] ?? 0);

function redirectPassword(string $status, string $message): void
{
    header('Location: ../profil.php?status=' . rawurlencode($status) . '&message=' . rawurlencode($message) . '#securite');
    exit;
}

if ($userId <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirectPassword('error', 'Session invalide.');
}

$current = (string) ($_POST['current_password'] ?? '');
$new = (string) ($_POST['new_password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');

if ($new === '' || strlen($new) < 6) {
    redirectPassword('error', 'Le nouveau mot de passe doit contenir au moins 6 caracteres.');
}

if ($new !== $confirm) {
    redirectPassword('error', 'La confirmation du mot de passe ne correspond pas.');
}

$user = $userModel->getUserById($userId);
if (!$user || hash('sha512', $current) !== (string) ($user['password'] ?? '')) {
    AuditTrail::log('user_password_change_failed', [
        'module' => 'decaissement',
        'action' => 'change_password',
        'entity_type' => 'user_account',
        'entity_id' => (string) $userId,
        'entity_label' => 'Changement mot de passe',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'current_password_mismatch',
        ],
    ], $pdo);
    redirectPassword('error', 'Mot de passe actuel incorrect.');
}

$hashed = hash('sha512', $new);
$stmt = $pdo->prepare('UPDATE user_devis_banamur SET password = :password WHERE id = :id');
$stmt->execute([
    'password' => $hashed,
    'id' => $userId,
]);

AuditTrail::log('user_password_changed', [
    'module' => 'decaissement',
    'action' => 'change_password',
    'entity_type' => 'user_account',
    'entity_id' => (string) $userId,
    'entity_label' => 'Changement mot de passe',
    'result' => 'success',
    'details' => [
        'user_id' => $userId,
    ],
], $pdo);

redirectPassword('ok', 'Mot de passe change.');
