<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

epordo_session_start();

try {
    $cfg = epordo_config();
    $body = epordo_read_json_body();

    $username = (string)($body['yhmc'] ?? '');
    $password = (string)($body['yhmm'] ?? '');
    $captcha = trim((string)($body['yzm'] ?? ''));
    $lang = (string)($body['lang'] ?? $cfg['lang']);

    if ($username === '' || $password === '' || $captcha === '') {
        epordo_json_response([
            'ok' => false,
            'error' => 'Missing yhmc/yhmm/yzm',
        ], 400);
        exit;
    }

    $payload = [
        'yhmc' => $username,
        'yhmm' => $password,
        'yzm' => $captcha,
        // Observed from official app
        'type' => (int)($cfg['type'] ?? 2),
        'systype' => (int)($cfg['systype'] ?? 2),
        'systemtype' => (int)($cfg['systemtype'] ?? 2),
        'lang' => $lang,
    ];

    $url = epordo_backend_url('login.do');

    $decoded = null;

    // 1) Try x-www-form-urlencoded
    $formBody = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    $resp = epordo_curl_request(
        'POST',
        $url,
        $formBody,
        [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept' => 'application/json, text/plain, */*',
        ]
    );
    $decoded = json_decode($resp['body'], true);

    // If backend says captcha missing (412), retry with multipart/form-data.
    if (is_array($decoded) && isset($decoded['code']) && (int)$decoded['code'] === 412) {
        $multipartPayload = $payload;
        // The helper currently only supports string bodies; so we implement multipart retry manually.
        // (Kept below via a raw cURL call so we don't break other uses.)
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to init cURL (multipart retry)');
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
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $multipartPayload,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
            ],
        ];
        $caInfo = (string)($cfg['curlCaInfo'] ?? '');
        if ($caInfo !== '') {
            $opts[CURLOPT_CAINFO] = $caInfo;
        }
        curl_setopt_array($ch, $opts);
        $body2 = curl_exec($ch);
        if ($body2 === false) {
            $err = curl_error($ch);
            $code = curl_errno($ch);
            if ((!array_key_exists('allowInsecureTlsFallback', $cfg) || (bool)$cfg['allowInsecureTlsFallback']) && $code === 60) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
                $body2 = curl_exec($ch);
            }
        }
        $status2 = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body2)) {
            $resp = ['status' => $status2, 'headers' => $responseHeaders, 'body' => $body2];
            $decoded = json_decode($resp['body'], true);
        }
    }

    // If backend returns HTML (rare) or can't be decoded, try JSON once.
    if (!is_array($decoded)) {
        $resp = epordo_curl_request(
            'POST',
            $url,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            [
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json, text/plain, */*',
            ]
        );
        $decoded = json_decode($resp['body'], true);
    }

    $token = null;

    if (is_array($decoded)) {
        // If backend uses {code, msg} pattern, expose it as a proper client error.
        if (isset($decoded['code']) && is_numeric($decoded['code']) && (int)$decoded['code'] !== 200) {
            $backendCode = (int)$decoded['code'];
            $msg = (string)($decoded['msg'] ?? 'Login error');

            // 412 from backend = captcha missing/invalid.
            $httpStatus = 400;
            if ($backendCode === 401 || $backendCode === 403) {
                $httpStatus = 401;
            }
            if ($backendCode === 412) {
                $httpStatus = 400;
            }

            epordo_json_response([
                'ok' => false,
                'error' => $msg,
                'backendCode' => $backendCode,
                'status' => $resp['status'],
                'raw' => $decoded,
            ], $httpStatus);
            exit;
        }

        // Try common locations.
        if (isset($decoded['token']) && is_string($decoded['token'])) {
            $token = $decoded['token'];
        } elseif (isset($decoded['data']['token']) && is_string($decoded['data']['token'])) {
            $token = $decoded['data']['token'];
        } elseif (isset($decoded['data']['Token']) && is_string($decoded['data']['Token'])) {
            $token = $decoded['data']['Token'];
        }
    }

    if (is_string($token) && $token !== '') {
        epordo_set_token($token);
        epordo_json_response([
            'ok' => true,
            'token' => $token,
            'raw' => $decoded ?? $resp['body'],
        ]);
        exit;
    }

    // No token found: return raw so user can inspect.
    epordo_json_response([
        'ok' => false,
        'error' => 'Login did not return a token (format not recognized).',
        'status' => $resp['status'],
        'raw' => $decoded ?? $resp['body'],
    ], 400);
} catch (Throwable $e) {
    epordo_json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
