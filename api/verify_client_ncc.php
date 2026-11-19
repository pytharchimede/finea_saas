<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/ClientIdentityService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $ncc = trim((string)($_GET['ncc'] ?? ''));
    if ($ncc === '') {
        throw new DomainException('Saisissez un NCC.');
    }

    $service = new ClientIdentityService(Database::getConnection());
    $data = $service->preview($ncc, (int)($_GET['except'] ?? 0));
    echo json_encode(['ok' => true, 'client' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (DomainException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Le service FNE est indisponible. Réessayez ultérieurement.'], JSON_UNESCAPED_UNICODE);
}
