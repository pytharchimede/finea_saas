<?php

declare(strict_types=1);

require_once __DIR__ . '/../model/AttendanceApiClient.php';

function usage(): void
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php tools/attendance_api_probe.php today --employee_code=45 [--limit=200]\n");
    fwrite(STDERR, "  php tools/attendance_api_probe.php records --employee_code=45 --date=2026-04-18 [--limit=500]\n");
    fwrite(STDERR, "  php tools/attendance_api_probe.php today --search=Ulrich [--limit=200]\n");
    fwrite(STDERR, "\nOptions:\n");
    fwrite(STDERR, "  --employee_code=CODE\n");
    fwrite(STDERR, "  --search=TEXT\n");
    fwrite(STDERR, "  --event_code=MAJOR/MINOR (ex: 5/38)\n");
    fwrite(STDERR, "  --date=YYYY-MM-DD (records only; default=today)\n");
    fwrite(STDERR, "  --limit=N (default 200; max 500)\n");
}

function parseArgs(array $argv): array
{
    $out = [
        'cmd' => null,
        'employee_code' => null,
        'search' => null,
        'event_code' => null,
        'date' => null,
        'limit' => 200,
    ];

    $out['cmd'] = $argv[1] ?? null;

    foreach ($argv as $arg) {
        if (strpos($arg, '--employee_code=') === 0) {
            $out['employee_code'] = trim(substr($arg, strlen('--employee_code=')));
        } elseif (strpos($arg, '--search=') === 0) {
            $out['search'] = trim(substr($arg, strlen('--search=')));
        } elseif (strpos($arg, '--event_code=') === 0) {
            $out['event_code'] = trim(substr($arg, strlen('--event_code=')));
        } elseif (strpos($arg, '--date=') === 0) {
            $out['date'] = trim(substr($arg, strlen('--date=')));
        } elseif (strpos($arg, '--limit=') === 0) {
            $v = trim(substr($arg, strlen('--limit=')));
            if (is_numeric($v)) {
                $out['limit'] = (int) $v;
            }
        }
    }

    $out['limit'] = max(1, min(500, (int)$out['limit']));
    return $out;
}

function printRecords(array $payload, int $max = 30): void
{
    $records = $payload['records'] ?? null;
    if (!is_array($records)) {
        echo "records: (missing or invalid)\n";
        return;
    }

    echo 'records_count=', count($records), "\n";
    $slice = array_slice($records, 0, $max);

    foreach ($slice as $r) {
        if (!is_array($r)) {
            continue;
        }
        echo '  - ', (string)($r['occurred_at'] ?? ''),
        ' | code=', (string)($r['employee_code'] ?? ''),
        ' | name=', (string)($r['employee_name'] ?? ''),
        ' | event=', (string)($r['event_code'] ?? ''),
        ' | uid=', (string)($r['event_uid'] ?? ''),
        "\n";
    }
}

$args = parseArgs($argv);
$cmd = strtolower(trim((string)($args['cmd'] ?? '')));
if (!in_array($cmd, ['today', 'records', 'health'], true)) {
    usage();
    exit(2);
}

$client = new AttendanceApiClient();

echo "Base URL: ", $client->getBaseUrl(), "\n";

echo "Command: ", $cmd, "\n";

try {
    if ($cmd === 'health') {
        $payload = $client->health();
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
        exit(0);
    }

    $params = [
        'limit' => $args['limit'],
    ];

    if (($args['employee_code'] ?? '') !== '') {
        $params['employee_code'] = $args['employee_code'];
    }
    if (($args['search'] ?? '') !== '') {
        $params['search'] = $args['search'];
    }
    if (($args['event_code'] ?? '') !== '') {
        $params['event_code'] = $args['event_code'];
    }

    if ($cmd === 'today') {
        $payload = $client->today($params);
        if (isset($payload['meta'])) {
            echo 'meta=', json_encode($payload['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        }
        printRecords($payload);
        exit(0);
    }

    // records
    $date = (string)($args['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        fwrite(STDERR, "Invalid --date (expected YYYY-MM-DD)\n");
        exit(2);
    }
    $params['from'] = $date . ' 00:00:00';
    $params['to'] = $date . ' 23:59:59';

    $payload = $client->records($params);
    if (isset($payload['meta'])) {
        echo 'meta=', json_encode($payload['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
    printRecords($payload);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}
