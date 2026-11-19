<?php
require_once __DIR__.'/../../auth_check.php';require_once __DIR__.'/../model/Database.php';require_once __DIR__.'/../../services/ManualTreasuryService.php';header('Content-Type: application/json; charset=UTF-8');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new RuntimeException('Méthode refusée.');
    }
    if (
        empty($_SESSION['daily_point_csrf'])
        || !hash_equals((string) $_SESSION['daily_point_csrf'], (string) ($_POST['_csrf'] ?? ''))
    ) {
        throw new RuntimeException('Session expirée.');
    }

    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $allowed = AccessManager::hasPermission('acces_admin')
        || AccessManager::hasPermission('acces_tresorerie')
        || AccessManager::hasPermission('acces_recommandations_tresorerie');

    if (!$allowed && $userId > 0) {
        $permission = Database::getConnection()->prepare(
            'SELECT acces_recommandations_tresorerie
             FROM user_devis_banamur
             WHERE id=:id AND active=1'
        );
        $permission->execute(['id' => $userId]);
        $allowed = (bool) $permission->fetchColumn();
    }
    if (!$allowed) {
        throw new RuntimeException('Droit de transfert vers le disponible requis.');
    }

    $service = new ManualTreasuryService(Database::getConnection());
    $requiredAmount = max(0, (float) ($_POST['amount'] ?? 0));
    $action = (string) ($_POST['action'] ?? 'suggest');

    if ($action === 'transfer_available') {
        $service->transferToAvailable(
            max(0, (float) ($_POST['transfer_amount'] ?? 0)),
            date('Y-m-d'),
            trim((string) ($_POST['reference'] ?? '')),
            trim((string) ($_POST['note'] ?? '')) ?: 'Transfert demandé depuis la validation achat',
            $userId
        );
    } elseif ($action !== 'suggest') {
        throw new RuntimeException('Action de trésorerie inconnue.');
    }

    $snapshot = $service->dashboard(date('Y-m-d'), date('Y-m-d'));
    echo json_encode([
        'ok' => true,
        'balance' => (float) $snapshot['realBalance'],
        'real_entries_balance' => (float) $snapshot['realEntriesBalance'],
        'suggested_date' => $service->suggestPaymentDate($requiredAmount),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
