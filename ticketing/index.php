<?php
session_start();
$rootAuth = __DIR__ . '/../auth_check.php';
if (is_file($rootAuth)) {
    require_once $rootAuth;
    if (class_exists('AccessManager')) {
        AccessManager::requirePageAccess('ticketing/index.php');
    }
}
require __DIR__ . '/autoload.php';

use Ticketing\Database\Connection;
use Ticketing\Database\Migration;
use Ticketing\Repositories\TicketRepository;
use Ticketing\Services\TicketService;
use Ticketing\Controllers\TicketController;

$pdo = Connection::get();
Migration::run($pdo);
$repo = new TicketRepository($pdo);
try { $user=$repo->actor((int)($_SESSION['user_id'] ?? 0)); }
catch (\DomainException $e) { http_response_code(403); exit('Session utilisateur invalide.'); }
$service = new TicketService($repo);
$controller = new TicketController($repo, $service, $user);
$controller->handle();
