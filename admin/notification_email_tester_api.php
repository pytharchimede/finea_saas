<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../model/AuditTrail.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST uniquement']);
    exit;
}

function decode_json_object_payload(string $raw, string $label): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException($label . ' invalide');
    }
    $expectedKey = 0;
    foreach (array_keys($decoded) as $key) {
        if ($key !== $expectedKey) {
            return $decoded;
        }
        $expectedKey++;
    }
    if ($decoded !== []) {
        throw new RuntimeException($label . ' invalide');
    }
    return $decoded;
}

$action = trim((string)($_POST['action'] ?? 'preview'));
$scenario = trim((string)($_POST['scenario'] ?? ''));

if ($scenario === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Scénario requis']);
    exit;
}

$pdo = null;

try {
    $pdo = Database::getConnection();
    $manager = new NotificationController($pdo);
    $payload = decode_json_object_payload((string)($_POST['payload'] ?? ''), 'Payload');
    $userName = (string)($_SESSION['secur_projet'] ?? ($_SESSION['username'] ?? 'ADMIN'));

    if ($action === 'preview') {
        $preview = $manager->buildAdminEmailTestPreview($scenario, $payload);
        echo json_encode(['ok' => true, 'preview' => $preview]);
        exit;
    }

    if ($action === 'send') {
        $recipient = decode_json_object_payload((string)($_POST['recipient'] ?? ''), 'Destinataire');
        $recipientEmail = trim((string)($recipient['recipient_email'] ?? ''));
        $recipientName = trim((string)($recipient['recipient_name'] ?? ''));
        if (($recipient['recipient_mode'] ?? '') === 'user') {
            $userId = (int)($recipient['recipient_user_id'] ?? 0);
            if ($userId <= 0) {
                throw new RuntimeException('Utilisateur destinataire invalide');
            }
            $stmt = $pdo->prepare('SELECT prenom, nom, mail_pro FROM user_devis_banamur WHERE id = :id AND active = 1 LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                throw new RuntimeException('Utilisateur destinataire introuvable');
            }
            $recipientEmail = trim((string)($row['mail_pro'] ?? ''));
            $recipientName = trim((string)(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
        }

        $result = $manager->sendAdminEmailTest($scenario, $payload, $recipientEmail, $recipientName);

        AuditTrail::log('admin_notification_email_test_sent', [
            'module' => 'admin',
            'action' => 'notification_email_tester',
            'entity_type' => 'email_test',
            'entity_label' => 'Test notification email ' . $scenario,
            'user_name' => $userName,
            'result' => !empty($result['sent']) ? 'success' : 'failure',
            'severity' => !empty($result['sent']) ? 'info' : 'warning',
            'details' => [
                'scenario' => $scenario,
                'recipient_email' => $result['recipient_email'] ?? $recipientEmail,
                'recipient_name' => $result['recipient_name'] ?? $recipientName,
            ],
        ], $pdo);

        echo json_encode([
            'ok' => true,
            'preview' => $result['preview'],
            'recipient_email' => $result['recipient_email'],
            'recipient_name' => $result['recipient_name'],
        ]);
        exit;
    }

    throw new RuntimeException('Action inconnue');
} catch (Throwable $e) {
    if ($pdo instanceof PDO) {
        AuditTrail::log('admin_notification_email_test_failed', [
            'module' => 'admin',
            'action' => 'notification_email_tester',
            'entity_type' => 'email_test',
            'entity_label' => 'Test notification email',
            'user_name' => (string)($_SESSION['secur_projet'] ?? ($_SESSION['username'] ?? 'ADMIN')),
            'result' => 'failure',
            'severity' => 'warning',
            'details' => [
                'scenario' => $scenario !== '' ? $scenario : null,
                'error' => $e->getMessage(),
            ],
        ], $pdo);
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
