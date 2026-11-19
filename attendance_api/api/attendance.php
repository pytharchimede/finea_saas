<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

epordo_session_start();

function epordo_yyyymmdd_to_dashed(string $s): string
{
    $s = trim($s);
    if (preg_match('/^\d{8}$/', $s) !== 1) {
        return $s;
    }
    return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
}

/**
 * @param array<string, mixed> $payload
 * @return array{status:int, decoded: mixed, raw:string}
 */
function epordo_attendance_post(string $url, string $token, array $payload): array
{
    $formBody = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
    $resp = epordo_curl_request(
        'POST',
        $url,
        $formBody,
        [
            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            'Accept' => 'application/json, text/plain, */*',
            'token' => $token,
            'X-Requested-With' => 'XMLHttpRequest',
        ]
    );

    $decoded = json_decode($resp['body'], true);
    return [
        'status' => $resp['status'],
        'decoded' => $decoded ?? $resp['body'],
        'raw' => $resp['body'],
    ];
}

function epordo_extract_aaData(mixed $decoded): ?array
{
    if (!is_array($decoded)) {
        return null;
    }
    $aa = $decoded['aaData'] ?? null;
    return is_array($aa) ? $aa : null;
}

function epordo_yyyymm_to_dashed(string $s): string
{
    $s = trim($s);
    if (preg_match('/^\d{6}$/', $s) !== 1) {
        return $s;
    }
    return substr($s, 0, 4) . '-' . substr($s, 4, 2);
}

try {
    $cfg = epordo_config();
    $token = epordo_get_token();
    if ($token === null) {
        epordo_json_response([
            'ok' => false,
            'error' => 'Not logged in (missing token).',
        ], 401);
        exit;
    }

    $body = epordo_read_json_body();

    // Basic defaults; override via UI.
    $pageLength = (int)($body['iDisplayLength'] ?? 20);
    if ($pageLength <= 0 || $pageLength > 200) {
        $pageLength = 20;
    }

    $currentPage = (int)($body['currentpage'] ?? 1);
    if ($currentPage <= 0) {
        $currentPage = 1;
    }

    $tbmidRaw = $body['tbmid'] ?? '';

    // IMPORTANT: backend often expects form fields (request parameters), not JSON.
    // Keep a strict whitelist and always send all expected keys.
    $payload = [
        // Observed from official app; some deployments require these on every request.
        'type' => (int)($cfg['type'] ?? 2),
        'systype' => (int)($cfg['systype'] ?? 2),
        'systemtype' => (int)($cfg['systemtype'] ?? 2),
        'lang' => (string)($body['lang'] ?? ($cfg['lang'] ?? 'en_US')),
        'currentpage' => $currentPage,
        // DataTables parameter used by the web platform
        'sEcho' => (string)($body['sEcho'] ?? '1'),
        'iDisplayStart' => (int)($body['iDisplayStart'] ?? 0),
        'iDisplayLength' => $pageLength,
        // Platform sends page=0
        'page' => (int)($body['page'] ?? 0),
        's_yggh' => (string)($body['s_yggh'] ?? ''),
        's_ygxm' => (string)($body['s_ygxm'] ?? ''),
        'tbmmc' => (string)($body['tbmmc'] ?? ''),
        // If backend parses as integer, send 0 instead of empty.
        'tbmid' => ($tbmidRaw === '' || $tbmidRaw === null) ? '0' : (string)$tbmidRaw,
        // Platform includes tcj (empty)
        'tcj' => (string)($body['tcj'] ?? ''),
        's_kqyf' => (string)($body['s_kqyf'] ?? ''),
        'ksrq_search' => (string)($body['ksrq_search'] ?? ''),
        'jsrq_search' => (string)($body['jsrq_search'] ?? ''),
    ];

    // Allow injecting extra params via config for compatibility with specific deployments.
    $extra = $cfg['attendanceExtraParams'] ?? [];
    if (is_array($extra) && !empty($extra)) {
        foreach ($extra as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if (is_string($v) || is_int($v) || is_float($v) || is_bool($v)) {
                $payload[$k] = (string)$v;
            }
        }
    }

    // Web platform endpoint for monthly per-employee punch summary.
    $url = epordo_backend_url('queryQkjlyxx.do');

    $attempts = [];
    $final = null;

    // Attempt 1: as-is (primary)
    $res1 = epordo_attendance_post($url, $token, $payload);
    $attempts[] = [
        'name' => 'primary',
        'status' => $res1['status'],
        'sent' => $payload,
    ];
    $final = $res1;

    $aa1 = epordo_extract_aaData($res1['decoded']);
    $hasRows = is_array($aa1) && count($aa1) > 0;

    // Attempt 2: dashed dates + also provide ksrq/jsrq keys
    if (!$hasRows) {
        $payload2 = $payload;
        $payload2['ksrq_search'] = epordo_yyyymmdd_to_dashed((string)($payload['ksrq_search'] ?? ''));
        $payload2['jsrq_search'] = epordo_yyyymmdd_to_dashed((string)($payload['jsrq_search'] ?? ''));
        $payload2['ksrq'] = (string)$payload2['ksrq_search'];
        $payload2['jsrq'] = (string)$payload2['jsrq_search'];

        // Also try month as YYYY-MM (observed on the web platform).
        $payload2['s_kqyf'] = epordo_yyyymm_to_dashed((string)($payload2['s_kqyf'] ?? ''));

        $res2 = epordo_attendance_post($url, $token, $payload2);
        $attempts[] = [
            'name' => 'dashed-dates',
            'status' => $res2['status'],
            'sent' => $payload2,
        ];
        $final = $res2;
        $aa2 = epordo_extract_aaData($res2['decoded']);
        $hasRows = is_array($aa2) && count($aa2) > 0;
    }

    // Attempt 2b: remove employee filters entirely (platform often sends yggh empty)
    if (!$hasRows) {
        $payload2b = $payload;
        $payload2b['s_kqyf'] = epordo_yyyymm_to_dashed((string)($payload2b['s_kqyf'] ?? ''));
        $payload2b['s_yggh'] = '';
        $payload2b['s_ygxm'] = '';
        $res2b = epordo_attendance_post($url, $token, $payload2b);
        $attempts[] = [
            'name' => 'no-employee-filter',
            'status' => $res2b['status'],
            'sent' => $payload2b,
        ];
        $final = $res2b;
        $aa2b = epordo_extract_aaData($res2b['decoded']);
        $hasRows = is_array($aa2b) && count($aa2b) > 0;
    }

    if (!$hasRows) {
        $payload2c = $payload;
        $payload2c['s_kqyf'] = epordo_yyyymm_to_dashed((string)($payload2c['s_kqyf'] ?? ''));
        unset($payload2c['s_yggh'], $payload2c['s_ygxm']);
        $res2c = epordo_attendance_post($url, $token, $payload2c);
        $attempts[] = [
            'name' => 'employee-filter-omitted',
            'status' => $res2c['status'],
            'sent' => $payload2c,
        ];
        $final = $res2c;
        $aa2c = epordo_extract_aaData($res2c['decoded']);
        $hasRows = is_array($aa2c) && count($aa2c) > 0;
    }

    // Attempt 3: tbmid empty/omitted (0 can mean "none" on some servers)
    if (!$hasRows) {
        $payload3 = $payload;
        $payload3['s_kqyf'] = epordo_yyyymm_to_dashed((string)($payload3['s_kqyf'] ?? ''));
        $payload3['tbmid'] = '';
        $payload3['tbmmc'] = '';
        $res3 = epordo_attendance_post($url, $token, $payload3);
        $attempts[] = [
            'name' => 'tbmid-empty',
            'status' => $res3['status'],
            'sent' => $payload3,
        ];
        $final = $res3;
        $aa3 = epordo_extract_aaData($res3['decoded']);
        $hasRows = is_array($aa3) && count($aa3) > 0;
    }

    if (!$hasRows) {
        $payload4 = $payload;
        $payload4['s_kqyf'] = epordo_yyyymm_to_dashed((string)($payload4['s_kqyf'] ?? ''));
        unset($payload4['tbmid'], $payload4['tbmmc']);
        $res4 = epordo_attendance_post($url, $token, $payload4);
        $attempts[] = [
            'name' => 'tbmid-omitted',
            'status' => $res4['status'],
            'sent' => $payload4,
        ];
        $final = $res4;
        $aa4 = epordo_extract_aaData($res4['decoded']);
        $hasRows = is_array($aa4) && count($aa4) > 0;
    }

    // Attempt 5: remove date filters entirely (some servers ignore ksrq_search/jsrq_search keys)
    if (!$hasRows) {
        $payload5 = $payload;
        $payload5['s_kqyf'] = epordo_yyyymm_to_dashed((string)($payload5['s_kqyf'] ?? ''));
        $payload5['ksrq_search'] = '';
        $payload5['jsrq_search'] = '';
        unset($payload5['ksrq'], $payload5['jsrq']);
        $res5 = epordo_attendance_post($url, $token, $payload5);
        $attempts[] = [
            'name' => 'no-date-filters',
            'status' => $res5['status'],
            'sent' => $payload5,
        ];
        $final = $res5;
    }

    $finalStatus = is_array($final) ? (int)$final['status'] : 0;
    $finalDecoded = is_array($final) ? $final['decoded'] : null;

    epordo_json_response([
        'ok' => $finalStatus >= 200 && $finalStatus < 300,
        'status' => $finalStatus,
        'attempts' => $attempts,
        'data' => $finalDecoded,
    ]);
} catch (Throwable $e) {
    epordo_json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
