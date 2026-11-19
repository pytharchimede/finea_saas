<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../services/FebDocumentComplianceService.php';
require_once __DIR__ . '/../model/FebOcrSettings.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

try {
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');

    if ($method === 'GET' && (string) ($_GET['action'] ?? '') === 'ocr_status') {
        $service = new FebDocumentComplianceService();
        if (!$service->isConfigured()) {
            echo json_encode([
                'ok' => true,
                'configured' => false,
                'ocr' => [
                    'status' => 'unavailable',
                    'available' => false,
                    'busy' => false,
                    'message' => 'Service OCR temporairement indisponible.',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $client = new FidestIaClient();
        echo json_encode([
            'ok' => true,
            'configured' => true,
            'ocr' => $client->ocrStatus(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        throw new RuntimeException('Méthode non autorisée.');
    }

    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $expected = (string) ($_SESSION['feb_proof_upload_csrf'] ?? '');
    if ($expected === '' || !hash_equals($expected, $csrf)) throw new RuntimeException('La session a expiré. Rechargez la page.');

    // Une page restée ouverte ne doit jamais déclencher l'OCR si le mode a changé.
    $activeMode = (new FebOcrSettings())->effectiveMode();
    if ($activeMode !== 'ai') {
        echo json_encode(['ok' => true, 'configured' => false, 'verification_mode' => $activeMode,
            'summary' => $activeMode === 'human' ? 'Validation humaine requise.' : 'Acceptation automatique active.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($_FILES['document']) || !is_array($_FILES['document']) || (int) ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Choisissez le document justificatif à contrôler.');
    }

    $tmp = (string) $_FILES['document']['tmp_name'];
    if (!is_uploaded_file($tmp)) throw new RuntimeException('Le fichier reçu est invalide.');

    $size = (int) ($_FILES['document']['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) throw new RuntimeException('Le document doit faire moins de 10 Mo.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException('Format non accepté. Utilisez PDF, JPG, PNG ou WebP.');
    }

    $service = new FebDocumentComplianceService();
    if (!$service->isConfigured()) {
        echo json_encode(['ok' => true, 'configured' => false, 'summary' => 'Contrôle FIDEST IA en attente de configuration.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $lines = json_decode((string) ($_POST['lines'] ?? '[]'), true);
    if (!is_array($lines)) $lines = [];

    $result = $service->check(
        $tmp,
        'FINEA-FEB-DRAFT-' . (int) ($_SESSION['user_id'] ?? 0),
        trim((string) ($_POST['supplier'] ?? '')),
        (float) ($_POST['amount'] ?? 0),
        $lines
    );

    echo json_encode(['ok' => true, 'configured' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (FidestIaException $e) {
    $knownStatus = in_array($e->httpStatus, [401, 403, 409, 422, 429], true) ? $e->httpStatus : 503;
    http_response_code($knownStatus);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'code' => $e->errorCode,
        'request_id' => $e->requestId,
        'ocr_busy' => $knownStatus === 409 && $e->errorCode === 'OCR_BUSY',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
