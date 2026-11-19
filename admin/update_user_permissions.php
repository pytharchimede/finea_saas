<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/AuditTrail.php';

$pdo = Database::getConnection();

$userId = (int)$_POST['id'];
$checkedPermissions = $_POST['permissions'] ?? [];
if (!is_array($checkedPermissions)) {
    $checkedPermissions = [];
}

$stmt = $pdo->prepare("SHOW COLUMNS FROM user_devis_banamur");
$stmt->execute();
$columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

$beforeStmt = $pdo->prepare("SELECT * FROM user_devis_banamur WHERE id = :id LIMIT 1");
$beforeStmt->execute(['id' => $userId]);
$before = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$permissionColumns = array_values(array_filter($columns, static fn($col): bool => AccessManager::isPermissionColumn((string) $col)));
$universalPermissions = AccessManager::universalPermissions($pdo);

$updates = [];
$params = ['id' => $userId];

foreach ($permissionColumns as $col) {
    $isChecked = in_array($col, $universalPermissions, true)
        || in_array($col, $checkedPermissions, true)
        || isset($_POST[$col]);
    $value = $isChecked ? 1 : 0;
    $updates[] = "$col = :$col";
    $params[$col] = $value;
}

$sql = "UPDATE user_devis_banamur SET " . implode(', ', $updates) . " WHERE id = :id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$changed = [];
foreach ($permissionColumns as $col) {
    $oldValue = isset($before[$col]) ? (int)$before[$col] : null;
    $newValue = (int)($params[$col] ?? 0);
    if ($oldValue !== $newValue) {
        $changed[$col] = ['from' => $oldValue, 'to' => $newValue];
    }
}

AuditTrail::log('permissions_updated', [
    'module' => 'admin',
    'action' => 'permissions_updated',
    'entity_type' => 'user',
    'entity_id' => (string)$userId,
    'entity_label' => trim((string)(($before['prenom'] ?? '') . ' ' . ($before['nom'] ?? ''))),
    'result' => 'success',
    'old_value' => array_map(static fn(array $change) => $change['from'] ?? null, $changed),
    'new_value' => array_map(static fn(array $change) => $change['to'] ?? null, $changed),
    'comment' => 'Mise a jour des permissions utilisateur.',
    'details' => [
        'changed_permissions' => $changed,
        'changed_count' => count($changed),
    ],
], $pdo);

AccessManager::refreshSessionPermissions();

header('Location: liste_users.php');
exit;
