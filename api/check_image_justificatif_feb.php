<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/EnvConfig.php';
require_once __DIR__ . '/../model/FebOcrSettings.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    \AuditTrail::log('feb_justificatif_check_failed', [
        'module' => 'api',
        'action' => 'check_image_justificatif_feb',
        'entity_type' => 'justificatif_image',
        'entity_label' => 'verification_justificatif_feb',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Méthode invalide.']);
    exit;
}

$activeFebMode = (new FebOcrSettings())->effectiveMode();
if ($activeFebMode !== 'ai') {
    echo json_encode(['ok'=>true,'decision'=>'ACCEPTE','comment'=>$activeFebMode==='human'?'Contrôle OCR remplacé par la validation humaine.':'Acceptation automatique active.','reasons'=>[],'classification'=>['class'=>'bypass','signals'=>[$activeFebMode]],'ocr_text'=>''],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$docType = strtoupper(trim((string)($_POST['doc_type'] ?? '')));
if ($docType === '') {
    $docType = 'JUSTIFICATIF_FEB';
}

if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
    \AuditTrail::log('feb_justificatif_check_failed', [
        'module' => 'api',
        'action' => 'check_image_justificatif_feb',
        'entity_type' => 'justificatif_image',
        'entity_label' => 'verification_justificatif_feb',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_file', 'doc_type' => $docType],
    ]);
    echo json_encode([
        'ok' => false,
        'decision' => 'REFUSE',
        'comment' => 'Fichier manquant.',
        'reasons' => [['message' => 'Aucun fichier reçu.']],
        'classification' => ['class' => 'autre', 'signals' => ['no_file']],
        'ocr_text' => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$file = $_FILES['image'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    \AuditTrail::log('feb_justificatif_check_failed', [
        'module' => 'api',
        'action' => 'check_image_justificatif_feb',
        'entity_type' => 'justificatif_image',
        'entity_label' => (string)($file['name'] ?? 'verification_justificatif_feb'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'upload_error', 'upload_error_code' => (int)($file['error'] ?? -1), 'doc_type' => $docType],
    ]);
    echo json_encode([
        'ok' => false,
        'decision' => 'REFUSE',
        'comment' => 'Erreur upload.',
        'reasons' => [['message' => 'Erreur upload (code ' . (int)($file['error'] ?? -1) . ').']],
        'classification' => ['class' => 'autre', 'signals' => ['upload_error']],
        'ocr_text' => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$tmpPath = (string)($file['tmp_name'] ?? '');
$origName = (string)($file['name'] ?? '');
$mime = (string)($file['type'] ?? '');
$size = (int)($file['size'] ?? 0);

if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    \AuditTrail::log('feb_justificatif_check_failed', [
        'module' => 'api',
        'action' => 'check_image_justificatif_feb',
        'entity_type' => 'justificatif_image',
        'entity_label' => $origName !== '' ? $origName : 'verification_justificatif_feb',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_temp_file', 'doc_type' => $docType],
    ]);
    echo json_encode([
        'ok' => false,
        'decision' => 'REFUSE',
        'comment' => 'Fichier temporaire invalide.',
        'reasons' => [['message' => 'Fichier temporaire invalide.']],
        'classification' => ['class' => 'autre', 'signals' => ['tmp_invalid']],
        'ocr_text' => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Limites basiques (sécurité & perf)
if ($size <= 0 || $size > 12 * 1024 * 1024) {
    \AuditTrail::log('feb_justificatif_check_failed', [
        'module' => 'api',
        'action' => 'check_image_justificatif_feb',
        'entity_type' => 'justificatif_image',
        'entity_label' => $origName !== '' ? $origName : 'verification_justificatif_feb',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'size_limit', 'doc_type' => $docType, 'size' => $size],
    ]);
    echo json_encode([
        'ok' => false,
        'decision' => 'REFUSE',
        'comment' => 'Fichier trop volumineux.',
        'reasons' => [['message' => 'Taille de fichier invalide (max 12 Mo).']],
        'classification' => ['class' => 'autre', 'signals' => ['size_limit']],
        'ocr_text' => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'bmp', 'tif', 'tiff'];
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
if ($ext === '' || !in_array($ext, $allowedExt, true)) {
    \AuditTrail::log('feb_justificatif_check_failed', [
        'module' => 'api',
        'action' => 'check_image_justificatif_feb',
        'entity_type' => 'justificatif_image',
        'entity_label' => $origName !== '' ? $origName : 'verification_justificatif_feb',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'extension_not_allowed', 'doc_type' => $docType, 'extension' => $ext, 'mime' => $mime],
    ]);
    echo json_encode([
        'ok' => false,
        'decision' => 'REFUSE',
        'comment' => 'Format non supporté. (Images uniquement)',
        'reasons' => [['message' => 'Format non supporté: ' . ($ext ?: 'inconnu') . '.']],
        'classification' => ['class' => 'autre', 'signals' => ['ext_not_allowed', 'mime:' . $mime]],
        'ocr_text' => '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_ocr_text(string $text): string
{
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

function ocr_error_contains(string $haystack, array $needles): bool
{
    $h = mb_strtolower($haystack);
    foreach ($needles as $n) {
        $n = mb_strtolower((string) $n);
        if ($n !== '' && strpos($h, $n) !== false) {
            return true;
        }
    }
    return false;
}

function is_php_function_disabled(string $fn): bool
{
    $fn = strtolower(trim($fn));
    if ($fn === '') {
        return true;
    }
    $disabled = (string) ini_get('disable_functions');
    if ($disabled === '') {
        return false;
    }
    $parts = array_filter(array_map('trim', explode(',', strtolower($disabled))));
    return in_array($fn, $parts, true);
}

function is_tesseract_not_found_message(string $text): bool
{
    return ocr_error_contains($text, [
        'is not recognized as an internal or external command',
        'not recognized as an internal or external command',
        "n'est pas reconnu en tant que commande",
        "n’est pas reconnu en tant que commande",
        'command not found',
        'not found',
        'introuvable',
        'le chemin d\'accès spécifié est introuvable',
        'le chemin d’accès spécifié est introuvable',
    ]);
}

function is_tesseract_language_missing_message(string $text): bool
{
    return ocr_error_contains($text, [
        'failed loading language',
        'error opening data file',
        'could not initialize tesseract',
        'tesseract couldn\'t load any languages',
        'tesseract could not load any languages',
    ]);
}

function is_tesseract_image_read_error_message(string $text): bool
{
    return ocr_error_contains($text, [
        'error in pixread',
        'pixread',
        'image file not found',
        'no such file or directory',
        'cannot open input file',
        'error opening image',
        'unsupported image format',
    ]);
}

function get_tesseract_bin(): string
{
    $binEnv = \EnvConfig::get('TESSERACT_BIN') ?? \EnvConfig::get('TESSERACT_PATH');
    if (is_string($binEnv) && trim($binEnv) !== '') {
        $bin = trim($binEnv);
        $bin = trim($bin, "\"'");
        return $bin !== '' ? $bin : 'tesseract';
    }

    // Auto-détection Windows (fréquent: Apache n'hérite pas du PATH utilisateur)
    if (PHP_OS_FAMILY === 'Windows') {
        $candidates = [
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe',
            'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
    }

    return 'tesseract';
}

function run_command_capture(string $cmd, int &$exitCode, string &$stderr): string
{
    $exitCode = -1;
    $stderr = '';

    // 1) proc_open (meilleure capture stdout/stderr)
    if (function_exists('proc_open') && !is_php_function_disabled('proc_open')) {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        try {
            $process = @proc_open($cmd, $descriptorspec, $pipes);
            if (!is_resource($process)) {
                $stderr = 'proc_open_failed';
                return '';
            }

            try {
                if (isset($pipes[0]) && is_resource($pipes[0])) {
                    fclose($pipes[0]);
                }
                $stdout = '';
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    $stdout = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[2]);
                }
                return is_string($stdout) ? $stdout : '';
            } finally {
                $exitCode = proc_close($process);
            }
        } catch (Throwable $e) {
            $stderr = 'proc_open_exception:' . get_class($e);
            $exitCode = -1;
            return '';
        }
    }

    // 2) shell_exec (simple)
    if (function_exists('shell_exec') && !is_php_function_disabled('shell_exec')) {
        try {
            $out = @shell_exec($cmd . ' 2>&1');
            if (!is_string($out)) {
                $stderr = 'shell_exec_no_output';
                $exitCode = -1;
                return '';
            }
            $exitCode = 0;
            return $out;
        } catch (Throwable $e) {
            $stderr = 'shell_exec_exception:' . get_class($e);
            $exitCode = -1;
            return '';
        }
    }

    // 3) exec (fallback: stdout + stderr mélangés)
    if (function_exists('exec') && !is_php_function_disabled('exec')) {
        try {
            $lines = [];
            @exec($cmd . ' 2>&1', $lines, $exitCode);
            return implode("\n", is_array($lines) ? $lines : []);
        } catch (Throwable $e) {
            $stderr = 'exec_exception:' . get_class($e);
            $exitCode = -1;
            return '';
        }
    }

    $stderr = 'command_execution_disabled';
    $exitCode = -1;
    return '';
}

function build_decision_from_ocr(string $ocrText, string $docType, array &$signals, array &$reasons): array
{
    $hasSystemOcrError = false;
    foreach ($signals as $s) {
        $s = (string) $s;
        if ($s === 'tmp_copy_failed' || $s === 'tesseract_not_found' || $s === 'tesseract_failed' || $s === 'tesseract_image_read_error' || $s === 'tesseract_exception') {
            $hasSystemOcrError = true;
            break;
        }
    }

    if ($hasSystemOcrError) {
        $classification = 'autre';
        $comment = "Refusé : contrôle OCR indisponible. Merci de réessayer ou de contacter le support.";
        foreach ($signals as $s) {
            if ($s === 'tesseract_image_read_error') {
                $comment = "Refusé : image illisible (format non supporté ou fichier corrompu).";
                break;
            }
        }
        return [false, $classification, $comment];
    }

    $t = mb_strtolower($ocrText);

    $hasCurrency = (bool)preg_match('/\b(fcfa|xof|cfa)\b/u', $t);
    $hasWeb = (bool)preg_match('/\b(www\.|https?:\/\/|\.com\b|\.ci\b|\.net\b)\b/u', $t);
    $hasDocWords = (bool)preg_match('/\b(facture|re[cç]u|ticket|proforma|devis|bon\s+de\s+commande|commande|paiement|total|montant|prix|tva|qt[eé]|quantit[eé])\b/u', $t);
    // Tolère les montants du type "1 500" ou "99,90" ou "2500"
    $hasNumbers = (bool)preg_match('/\d{2,}/', $t) || (bool)preg_match('/\b\d[\d\s\.,]{2,}\b/u', $t);

    $len = mb_strlen($t);
    $signals[] = 'ocr_len:' . $len;

    if ($hasCurrency) $signals[] = 'has_currency';
    if ($hasWeb) $signals[] = 'has_web';
    if ($hasDocWords) $signals[] = 'has_doc_words';
    if ($hasNumbers) $signals[] = 'has_numbers';

    $classification = 'autre';
    if ($hasWeb && ($hasCurrency || $hasNumbers)) {
        $classification = 'capture_ecran';
    } elseif ($hasDocWords && ($hasCurrency || $hasNumbers)) {
        $classification = 'document';
    } elseif ($hasCurrency && $hasNumbers) {
        $classification = 'document';
    }

    $accepted = false;

    // Heuristique: on accepte si OCR contient des indicateurs de prix / justificatif.
    if (($hasDocWords && $hasNumbers) || ($hasCurrency && $hasNumbers) || ($hasWeb && $hasNumbers)) {
        $accepted = true;
    }

    // OCR très court => refuse (sauf si présence d'un signal fort)
    if ($len < 20 && !$hasCurrency && !$hasWeb) {
        $accepted = false;
        $reasons[] = ['message' => "Texte OCR trop court / illisible."];
        $signals[] = 'too_short';
    }

    if (!$accepted) {
        if (!$reasons) {
            $reasons[] = ['message' => "Le justificatif semble incomplet : prix/total non lisible ou document non identifié."];
        }
        $comment = "Refusé : merci d'envoyer une photo nette d'un document (facture/reçu/devis) ou une capture d'écran avec le prix visible.";
    } else {
        $comment = "Accepté : justificatif lisible (prix / document détecté).";
    }

    return [$accepted, $classification, $comment];
}

$signals = ['api_rev:2026-04-22a', 'doc_type:' . $docType, 'file:' . ($ext ?: 'unknown')];
$reasons = [];
$ocrText = '';

$tesseractExit = null;
$tesseractStdErr = '';
$tesseractStdOut = '';

// Crée une copie du fichier uploadé avec extension (le tmp PHP n'a souvent pas d'extension)
$workPath = '';
try {
    $tmpBase = @tempnam(sys_get_temp_dir(), 'febocr_');
    if (is_string($tmpBase) && $tmpBase !== '') {
        @unlink($tmpBase);
        $workPath = $tmpBase . '.' . $ext;
        if (!@copy($tmpPath, $workPath)) {
            $workPath = '';
        }
    }
} catch (Throwable $e) {
    $workPath = '';
}

if ($workPath === '') {
    $signals[] = 'tmp_copy_failed';
    $reasons[] = ['message' => 'Impossible de préparer le fichier pour l\'OCR.'];
}

// OCR via Tesseract (stdout)
try {
    if ($workPath !== '') {
        // Certains environnements Windows préfèrent la locale par défaut; on force une langue si disponible.
        $lang = (string) (\EnvConfig::get('TESSERACT_LANG') ?? 'fra');
        $lang = trim($lang);
        if ($lang === '') {
            $lang = 'fra';
        }

        $tesseractBin = get_tesseract_bin();
        $signals[] = 'tesseract_bin:' . basename(str_replace('\\', '/', $tesseractBin));

        // --psm 6 marche bien pour documents.
        $cmd = escapeshellarg($tesseractBin) . ' ' . escapeshellarg($workPath) . ' stdout -l ' . escapeshellarg($lang) . ' --psm 6';

        // Timeout best-effort
        @set_time_limit(60);

        $tesseractStdOut = run_command_capture($cmd, $exitCode, $stderr);
        $tesseractExit = $exitCode;
        $tesseractStdErr = $stderr;

        $signals[] = 'tesseract_exit:' . (string) $exitCode;

        $errAll = trim((string) $stderr);
        $outAll = trim((string) $tesseractStdOut);

        if ($exitCode === -1 && ($errAll === 'command_execution_disabled' || str_starts_with($errAll, 'proc_open_exception') || str_starts_with($errAll, 'shell_exec_exception') || str_starts_with($errAll, 'exec_exception') || $errAll === 'proc_open_failed')) {
            $signals[] = 'cmd_exec_disabled';
            $reasons[] = ['message' => 'OCR indisponible (exécution de commandes désactivée côté serveur).'];
            $ocrText = '';
        }

        // Fallback si la langue n'est pas dispo
        if ($lang !== 'eng' && (is_tesseract_language_missing_message($errAll) || is_tesseract_language_missing_message($outAll))) {
            $signals[] = 'tesseract_lang_fallback';
            $cmd2 = escapeshellarg($tesseractBin) . ' ' . escapeshellarg($workPath) . ' stdout -l eng --psm 6';
            $tesseractStdOut2 = run_command_capture($cmd2, $exitCode2, $stderr2);
            $signals[] = 'tesseract_exit_eng:' . (string) $exitCode2;
            if (is_string($tesseractStdOut2) && $tesseractStdOut2 !== '') {
                $tesseractStdOut = $tesseractStdOut2;
                $tesseractStdErr = (string) $stderr2;
                $tesseractExit = $exitCode2;
            }
        }

        $combined = ($tesseractStdErr !== '' ? $tesseractStdErr . "\n" : '') . (string) $tesseractStdOut;

        if (!in_array('cmd_exec_disabled', $signals, true) && is_tesseract_not_found_message($combined)) {
            $signals[] = 'tesseract_not_found';
            $reasons[] = ['message' => 'OCR indisponible (Tesseract introuvable côté serveur).'];
            $ocrText = '';
        } elseif (!in_array('cmd_exec_disabled', $signals, true) && $tesseractExit !== null && (int) $tesseractExit !== 0) {
            if (is_tesseract_image_read_error_message($combined)) {
                $signals[] = 'tesseract_image_read_error';
                $reasons[] = ['message' => 'Impossible de lire l\'image (format ou fichier illisible).'];
            } else {
                $signals[] = 'tesseract_failed';
                $reasons[] = ['message' => 'Erreur OCR (exécution Tesseract).'];
            }
            $ocrText = '';
        } elseif (!in_array('cmd_exec_disabled', $signals, true)) {
            $ocrText = normalize_ocr_text((string) $tesseractStdOut);
            if ($ocrText === '') {
                $signals[] = 'ocr_empty';
                $reasons[] = ['message' => 'Texte OCR vide (document flou, trop sombre, ou non textuel).'];
            }
        }
    }
} catch (Throwable $e) {
    $signals[] = 'tesseract_exception';
    $signals[] = 'tesseract_exception_type:' . get_class($e);
    $reasons[] = ['message' => 'Erreur OCR.'];
} finally {
    if ($workPath !== '') {
        @unlink($workPath);
    }
}

[$accepted, $class, $comment] = build_decision_from_ocr($ocrText, $docType, $signals, $reasons);

$response = [
    'ok' => true,
    'decision' => $accepted ? 'ACCEPTE' : 'REFUSE',
    'comment' => $comment,
    'reasons' => $reasons,
    'classification' => [
        'class' => $class,
        'signals' => $signals,
    ],
    'ocr_text' => $ocrText,
];

\AuditTrail::log('feb_justificatif_checked', [
    'module' => 'api',
    'action' => 'check_image_justificatif_feb',
    'entity_type' => 'justificatif_image',
    'entity_label' => $origName !== '' ? $origName : 'verification_justificatif_feb',
    'result' => $accepted ? 'success' : 'refused',
    'severity' => $accepted ? 'info' : 'warning',
    'details' => [
        'doc_type' => $docType,
        'decision' => $response['decision'],
        'classification' => $class,
        'file_extension' => $ext,
        'file_size' => $size,
        'signals' => $signals,
        'reason_count' => count($reasons),
    ],
]);

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
