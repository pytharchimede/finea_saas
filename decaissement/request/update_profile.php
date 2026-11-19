<?php

session_start();

require_once '../model/Database.php';
require_once '../../model/AuditTrail.php';

$pdo = Database::getConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function redirectProfile(string $status, string $message): void
{
    header('Location: ../profil.php?status=' . rawurlencode($status) . '&message=' . rawurlencode($message));
    exit;
}

if ($userId <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirectProfile('error', 'Session invalide.');
}

$prenom = trim((string) ($_POST['prenom'] ?? ''));
$nom = trim((string) ($_POST['nom'] ?? ''));
$mail = strtolower(trim((string) ($_POST['mail_pro'] ?? '')));

if ($prenom === '' || $nom === '' || $mail === '') {
    redirectProfile('error', 'Le nom, le prenom et l email sont obligatoires.');
}

if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
    redirectProfile('error', 'Adresse email invalide.');
}

try {
    $stmt = $pdo->prepare('SELECT id FROM user_devis_banamur WHERE LOWER(mail_pro) = LOWER(:mail) AND id <> :id LIMIT 1');
    $stmt->execute(['mail' => $mail, 'id' => $userId]);
    if ($stmt->fetchColumn()) {
        redirectProfile('error', 'Cette adresse email est deja utilisee par un autre compte.');
    }

    $stmt = $pdo->prepare('UPDATE user_devis_banamur SET prenom = :prenom, nom = :nom, mail_pro = :mail WHERE id = :id');
    $stmt->execute([
        'prenom' => $prenom,
        'nom' => $nom,
        'mail' => $mail,
        'id' => $userId,
    ]);

    $_SESSION['prenom'] = $prenom;
    $_SESSION['nom'] = $nom;
    $_SESSION['mail_pro'] = $mail;

    AuditTrail::log('user_profile_updated', [
        'module' => 'decaissement',
        'action' => 'update_profile',
        'entity_type' => 'user_account',
        'entity_id' => (string) $userId,
        'entity_label' => $prenom . ' ' . $nom,
        'result' => 'success',
        'details' => ['mail_pro' => $mail],
    ], $pdo);

    redirectProfile('ok', 'Profil mis a jour.');
} catch (Throwable $e) {
    error_log('[update_profile] ' . $e->getMessage());
    AuditTrail::log('user_profile_update_failed', [
        'module' => 'decaissement',
        'action' => 'update_profile',
        'entity_type' => 'user_account',
        'entity_id' => (string) $userId,
        'result' => 'failed',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], $pdo);
    redirectProfile('error', 'Mise a jour impossible.');
}
