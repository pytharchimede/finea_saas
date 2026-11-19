<?php
// À lancer chaque jour en CLI : php /chemin/banamur_project_app/recurring_needs/cron_generate.php
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../auth_check.php';
    \AccessManager::requirePageAccess();
    if (empty($_SESSION['permissions']['acces_admin'])) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Accès administrateur requis.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/services/RecurringNeedService.php';

$pdo = \Database::getConnection();
$service = new RecurringNeedService($pdo);
$today = new DateTimeImmutable('today');
$reminders = $service->runReminders($today);
$results = $service->runAutomaticGeneration($today);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'date' => date('Y-m-d'), 'reminders' => $reminders, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
