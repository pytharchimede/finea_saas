<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

epordo_session_start();

$cfg = epordo_config();

$jarPath = epordo_cookie_jar_path();
$jarInfo = [
    'path' => basename($jarPath),
    'exists' => is_file($jarPath),
    'size' => is_file($jarPath) ? filesize($jarPath) : null,
    'mtime' => is_file($jarPath) ? filemtime($jarPath) : null,
    'cookies' => [],
];

if (is_file($jarPath)) {
    $lines = @file($jarPath, FILE_IGNORE_NEW_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Netscape cookie file format: domain\tflag\tpath\tsecure\texpiration\tname\tvalue
            $parts = explode("\t", $line);
            if (count($parts) >= 7) {
                $domain = $parts[0];
                $name = $parts[5];
                if ($name !== '') {
                    $jarInfo['cookies'][] = [
                        'domain' => $domain,
                        'name' => $name,
                    ];
                }
            }
        }
    }
}

epordo_json_response([
    'ok' => true,
    'hasToken' => epordo_get_token() !== null,
    'sessionId' => session_id(),
    'cookieJar' => $jarInfo,
    'defaults' => [
        'backendBaseUrl' => $cfg['backendBaseUrl'],
        'lang' => $cfg['lang'],
        'defaultUsername' => $cfg['defaultUsername'],
        // Never return defaultPassword.
    ],
]);
