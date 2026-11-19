<?php

declare(strict_types=1);

// Basic bootstrap for this small demo app.
// - Uses PHP session to persist token + cookie jar.
// - Uses cURL to call bnetlink backend.

function epordo_config(): array
{
    $default = [
        // Backend base URL. Examples:
        // - https://bnetlink.com/manage
        // - https://24hoursonlinesys.com/manage/manage
        'backendBaseUrl' => 'https://bnetlink.com/manage',

        // Params observed from official app / docs.
        // systype is used by captcha endpoint.
        'systype' => 2,
        // systemtype is used by login payload.
        'systemtype' => 2,
        // Some deployments require an extra 'type' field.
        'type' => 2,
        'lang' => 'en',
        // Optional defaults; prefer leaving empty and entering in UI.
        'defaultUsername' => '',
        'defaultPassword' => '',

        // Optional: some deployments require extra POST params for attendance queries.
        // Example: ['qyid' => 251]
        'attendanceExtraParams' => [],

        // TLS/CURL advanced options
        // 'curlCaInfo' => __DIR__ . '/cacert.pem',
        // 'allowInsecureTlsFallback' => true,
    ];

    $localPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.local.php';
    if (is_file($localPath)) {
        /** @var mixed $local */
        $local = require $localPath;
        if (is_array($local)) {
            return array_replace($default, $local);
        }
    }

    return $default;
}

function epordo_session_start(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $cfg = epordo_config();

        // Keep the session alive longer so the user stays connected.
        // Default: 12 hours. Override in config.local.php with 'sessionLifetimeSeconds'.
        $lifetime = (int)($cfg['sessionLifetimeSeconds'] ?? 43200);
        if ($lifetime > 0) {
            @ini_set('session.gc_maxlifetime', (string)$lifetime);
            @ini_set('session.cookie_lifetime', (string)$lifetime);

            // Must be called before session_start().
            if (PHP_VERSION_ID >= 70300) {
                session_set_cookie_params([
                    'lifetime' => $lifetime,
                    'path' => '/',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            } else {
                session_set_cookie_params($lifetime, '/; samesite=Lax', '', false, true);
            }
        }

        // This app is meant for local usage. Cookies are default.
        session_start();
    }
}

function epordo_cookie_jar_path(): string
{
    epordo_session_start();

    $tmpDir = sys_get_temp_dir();
    $sessionId = session_id();
    $path = $tmpDir . DIRECTORY_SEPARATOR . 'epordo_bnetlink_' . $sessionId . '.cookie';

    // Ensure file exists (cURL can create it, but it's safer).
    if (!is_file($path)) {
        @file_put_contents($path, '');
    }

    return $path;
}

function epordo_json_response(mixed $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function epordo_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array{status:int, headers:array<string,string>, body:string}
 */
function epordo_curl_request(string $method, string $url, ?string $body = null, array $headers = []): array
{
    $cfg = epordo_config();

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to init cURL');
    }

    $cookieJar = epordo_cookie_jar_path();

    $responseHeaders = [];

    $headerFn = static function ($curl, string $headerLine) use (&$responseHeaders): int {
        $len = strlen($headerLine);
        $parts = explode(':', $headerLine, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if ($name !== '') {
                $responseHeaders[$name] = $value;
            }
        }
        return $len;
    };

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => $headerFn,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'epordo-local/1.0',
    ];

    // Windows setups often lack an OS CA bundle in PHP, causing cURL error 60.
    // We keep verification ON by default and only fallback to insecure mode on that specific error.
    $allowInsecureTlsFallback = !array_key_exists('allowInsecureTlsFallback', $cfg) ? true : (bool)$cfg['allowInsecureTlsFallback'];
    $caInfo = (string)($cfg['curlCaInfo'] ?? '');
    if ($caInfo !== '') {
        $opts[CURLOPT_CAINFO] = $caInfo;
    }

    $methodUpper = strtoupper($method);
    if ($methodUpper === 'POST') {
        $opts[CURLOPT_POST] = true;
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $methodUpper;
    }

    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }

    if (!empty($headers)) {
        $normalized = [];
        foreach ($headers as $k => $v) {
            $normalized[] = $k . ': ' . $v;
        }
        $opts[CURLOPT_HTTPHEADER] = $normalized;
    }

    curl_setopt_array($ch, $opts);

    $respBody = curl_exec($ch);
    if ($respBody === false) {
        $err = curl_error($ch);
        $code = curl_errno($ch);

        // Retry once in insecure mode if SSL verification fails (error 60).
        if ($allowInsecureTlsFallback && $code === 60) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $respBody = curl_exec($ch);
            if ($respBody !== false) {
                $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                return [
                    'status' => $status,
                    'headers' => $responseHeaders,
                    'body' => $respBody,
                ];
            }

            // Update error after retry.
            $err = curl_error($ch);
            $code = curl_errno($ch);
        }

        curl_close($ch);
        throw new RuntimeException('cURL error (' . $code . '): ' . $err);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => $respBody,
    ];
}

function epordo_backend_url(string $path): string
{
    $cfg = epordo_config();
    $base = rtrim((string)$cfg['backendBaseUrl'], '/');
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

function epordo_set_token(string $token): void
{
    epordo_session_start();
    $_SESSION['bnetlink_token'] = $token;
}

function epordo_get_token(): ?string
{
    epordo_session_start();
    $t = $_SESSION['bnetlink_token'] ?? null;
    return is_string($t) && $t !== '' ? $t : null;
}
