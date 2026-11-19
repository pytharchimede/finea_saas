<?php

session_start();

require_once '../model/Database.php';
require_once '../../model/AuditTrail.php';

$pdo = Database::getConnection();
$userId = (int) ($_SESSION['user_id'] ?? 0);

function redirectPreferences(string $status, string $message): void
{
    header('Location: ../profil.php?status=' . rawurlencode($status) . '&message=' . rawurlencode($message) . '#parametres');
    exit;
}

function allowedNotificationEvents(array $user): array
{
    $canFinance = !empty($user['acces_decaissement']) || !empty($user['acces_fiches_decaissement']) || !empty($user['acces_compta']) || !empty($user['acces_direction']);
    $canRh = !empty($user['acces_rh']) || !empty($user['acces_admin']);
    $canSupplier = !empty($user['acces_fournisseur']) || !empty($user['acces_facturation']) || !empty($user['acces_admin']);
    $canPlanning = !empty($user['acces_planning']) || !empty($user['acces_logistique']) || !empty($user['acces_hse']);
    $canLogistique = !empty($user['acces_logistique']) || !empty($user['acces_admin']);

    $events = [
        'employee_absences.absence_marked' => $canRh,
        'employee_absences.absence_reminder_24h' => $canRh,
        'employee_absences.absence_threshold_3' => $canRh,
        'legal_request_submitted.permission_absence' => $canRh,
        'legal_request_submitted.demande_explication' => $canRh,
        'legal_request_submitted.feb' => $canFinance || !empty($user['acces_direction']) || !empty($user['acces_rh']),
        'legal_request_processed.approved' => true,
        'legal_request_processed.rejected' => true,
        'decaissement_submitted.default' => $canFinance,
        'decaissement_submitted.epi' => $canFinance || !empty($user['acces_hse']),
        'decaissement_processed.approved' => true,
        'decaissement_processed.rejected' => true,
        'mailbox' => !empty($user['acces_mail']),
        'planning_operations' => $canPlanning,
        'logistics_operations' => $canLogistique,
        'rh_management' => $canRh,
        'supplier_portal' => $canSupplier,
    ];

    return array_keys(array_filter($events));
}

if ($userId <= 0 || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirectPreferences('error', 'Session invalide.');
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_notification_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            event_key VARCHAR(80) NOT NULL,
            channel_email TINYINT(1) NOT NULL DEFAULT 0,
            channel_system TINYINT(1) NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_event (user_id, event_key),
            KEY idx_user_notification_preferences_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare('SELECT * FROM user_devis_banamur WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        redirectPreferences('error', 'Utilisateur introuvable.');
    }

    $allowedEvents = allowedNotificationEvents($user);
    $preferences = is_array($_POST['preferences'] ?? null) ? $_POST['preferences'] : [];
    $stmt = $pdo->prepare("
        INSERT INTO user_notification_preferences (user_id, event_key, channel_email, channel_system, updated_at)
        VALUES (:user_id, :event_key, :channel_email, :channel_system, NOW())
        ON DUPLICATE KEY UPDATE
            channel_email = VALUES(channel_email),
            channel_system = VALUES(channel_system),
            updated_at = NOW()
    ");

    foreach ($allowedEvents as $eventKey) {
        $row = is_array($preferences[$eventKey] ?? null) ? $preferences[$eventKey] : [];
        $stmt->execute([
            'user_id' => $userId,
            'event_key' => $eventKey,
            'channel_email' => !empty($row['email']) ? 1 : 0,
            'channel_system' => !empty($row['system']) ? 1 : 0,
        ]);
    }

    AuditTrail::log('user_notification_preferences_updated', [
        'module' => 'decaissement',
        'action' => 'update_notification_preferences',
        'entity_type' => 'user_account',
        'entity_id' => (string) $userId,
        'entity_label' => 'Preferences notifications',
        'result' => 'success',
        'details' => ['events' => $allowedEvents],
    ], $pdo);

    redirectPreferences('ok', 'Preferences de notifications enregistrees.');
} catch (Throwable $e) {
    error_log('[update_notification_preferences] ' . $e->getMessage());
    AuditTrail::log('user_notification_preferences_update_failed', [
        'module' => 'decaissement',
        'action' => 'update_notification_preferences',
        'entity_type' => 'user_account',
        'entity_id' => (string) $userId,
        'result' => 'failed',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], $pdo);
    redirectPreferences('error', 'Preferences non enregistrees.');
}
