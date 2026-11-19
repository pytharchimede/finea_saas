<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../services/FrenchCompanyRegistry.php';
header('Content-Type: application/json; charset=utf-8');
if (!AccessManager::hasPermission('acces_fournisseur') && !AccessManager::hasPermission('acces_compta') && !AccessManager::hasPermission('acces_admin') && !AccessManager::hasPermission('acces_employe')) {
    http_response_code(403);
    echo json_encode(['ok'=>false, 'error'=>'Accès refusé.']);
    exit;
}
try {
    echo json_encode(['ok'=>true, 'supplier'=>(new FrenchCompanyRegistry())->lookup((string)($_GET['siret'] ?? ''))], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (DomainException $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false, 'error'=>'Vérification du registre français indisponible. Réessayez.']);
}

