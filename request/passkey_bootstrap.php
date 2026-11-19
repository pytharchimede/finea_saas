<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/User.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../repositories/PasskeyRepository.php';
require_once __DIR__ . '/../repositories/MobilePasskeyLoginRepository.php';
require_once __DIR__ . '/../services/PasskeyService.php';
require_once __DIR__ . '/../services/AuthSessionService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function passkeyJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function passkeyBody(): string
{
    $body = file_get_contents('php://input');
    if (!is_string($body) || $body === '' || strlen($body) > 131072) {
        throw new RuntimeException('Requête invalide.');
    }
    return $body;
}

function passkeyRequirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        passkeyJson(['status' => 'error', 'message' => 'Méthode non autorisée.'], 405);
    }
}

function passkeyRequireSameOrigin(array $config): void
{
    $origin = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''), '/');
    if ($origin !== '' && !hash_equals((string) $config['origin'], $origin)) {
        passkeyJson(['status' => 'error', 'message' => 'Origine non autorisée.'], 403);
    }
    if (str_starts_with((string) $config['origin'], 'https://') === false && $config['rp_id'] !== 'localhost') {
        passkeyJson(['status' => 'error', 'message' => 'Les Passkeys exigent HTTPS.'], 403);
    }
}

function passkeyConsumeChallenge(string $purpose): array
{
    $entry = $_SESSION['webauthn_challenge'] ?? null;
    unset($_SESSION['webauthn_challenge']);
    if (!is_array($entry) || ($entry['purpose'] ?? '') !== $purpose || (int) ($entry['expires_at'] ?? 0) < time()) {
        throw new RuntimeException('La demande a expiré. Veuillez recommencer.');
    }
    return $entry;
}

$passkeyConfig = require __DIR__ . '/../config/webauthn.php';
passkeyRequireSameOrigin($passkeyConfig);
$passkeyPdo = Database::getConnection();
$passkeyService = new PasskeyService($passkeyConfig);
$passkeyRepository = new PasskeyRepository($passkeyPdo, $passkeyService->serializer());
$mobilePasskeyRepository = new MobilePasskeyLoginRepository($passkeyPdo);
