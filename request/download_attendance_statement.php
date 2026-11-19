<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/RhAttendanceClosingNotificationService.php';

$deliveryId = max(0, (int) ($_GET['id'] ?? 0));
$userId = (int) ($_SESSION['user_id'] ?? 0);
$canManage = AccessManager::hasPermission('acces_rh')
    || AccessManager::hasPermission('acces_direction')
    || AccessManager::hasPermission('acces_admin');
$service = new RhAttendanceClosingNotificationService(Database::getConnection());
$delivery = $service->getAuthorizedDelivery($deliveryId, $userId, $canManage);
if (!$delivery) {
    http_response_code(404);
    exit('Relevé de pointage introuvable ou inaccessible.');
}

$snapshot = (array) $delivery['snapshot'];
$content = $service->renderWorkbook($snapshot);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $service->fileName($snapshot) . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: private, no-store, max-age=0');
echo $content;
exit;
