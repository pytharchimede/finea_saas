<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../services/FneNccRegistry.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    respond(['ok' => false, 'message' => 'Session expirée.'], 401);
}

$raw = file_get_contents('php://input');
$input = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($input)) {
    respond(['ok' => false, 'message' => 'Charge utile invalide.'], 400);
}

$csrf = (string)($input['_csrf'] ?? '');
$sessionCsrf = (string)($_SESSION['dgi_ncc_batch_csrf'] ?? '');
if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    respond(['ok' => false, 'message' => 'Jeton CSRF invalide.'], 403);
}

// Garde-fou serveur : même si le JavaScript est contourné, une session ne peut
// pas enchaîner les appels plus vite qu'environ un appel toutes les 2,5 secondes.
$minIntervalMs = 2500;
$now = microtime(true);
$last = (float)($_SESSION['dgi_ncc_last_request_at'] ?? 0.0);
$elapsedMs = (int)round(($now - $last) * 1000);
if ($last > 0 && $elapsedMs < $minIntervalMs) {
    respond([
        'ok' => false,
        'rate_limited' => true,
        'retry_after_ms' => max(250, $minIntervalMs - $elapsedMs),
        'message' => 'Rythme de consultation trop rapide.'
    ], 429);
}
$_SESSION['dgi_ncc_last_request_at'] = $now;

$ncc = trim((string)($input['ncc'] ?? ''));
if ($ncc === '') {
    respond(['ok' => false, 'message' => 'NCC manquant.'], 422);
}

try {
    $registry = new FneNccRegistry();
    $company = $registry->lookup($ncc);

    respond([
        'ok' => true,
        'found' => true,
        'company' => $company,
    ]);
} catch (DomainException $e) {
    // Les NCC absents/non inscrits sont des résultats fonctionnels négatifs,
    // pas des pannes : ils ne sont pas affichés dans la liste positive.
    respond([
        'ok' => true,
        'found' => false,
        'message' => $e->getMessage(),
    ]);
} catch (RuntimeException $e) {
    $message = $e->getMessage();
    $isRemoteRateLimit = str_contains(mb_strtolower($message), 'trop de demandes');

    respond([
        'ok' => false,
        'remote_rate_limited' => $isRemoteRateLimit,
        'retry_after_ms' => $isRemoteRateLimit ? 30000 : null,
        'message' => $message,
    ], $isRemoteRateLimit ? 429 : 503);
} catch (Throwable $e) {
    error_log('[DGI NCC batch] ' . $e->getMessage());
    respond([
        'ok' => false,
        'message' => 'Erreur interne lors de la vérification du NCC.'
    ], 500);
}
