<?php

declare(strict_types=1);

require_once __DIR__ . '/../model/EnvConfig.php';
require_once __DIR__ . '/../model/NotificationManager.php';
require_once __DIR__ . '/../model/AuditTrail.php';

final class AttendanceDeviceAlertService
{
    private PDO $pdo;
    private NotificationManager $notifications;

    public function __construct(PDO $pdo, ?NotificationManager $notifications = null)
    {
        $this->pdo = $pdo;
        $this->notifications = $notifications ?: new NotificationManager($pdo);
        $this->notifications->ensureDefaultRulesForAttendanceDeviceAlert();
    }

    public function inspect(?array $remoteControl, ?string $remoteControlError = null): array
    {
        \EnvConfig::loadFromDirectory(__DIR__ . '/../model');
        $offlineMinutes = max(1, (int) (\EnvConfig::get('ATTENDANCE_DEVICE_OFFLINE_AFTER_MINUTES', '5') ?? '5'));
        $driftThreshold = max(30, (int) (\EnvConfig::get('ATTENDANCE_DEVICE_CLOCK_DRIFT_ALERT_SECONDS', '300') ?? '300'));
        $alerts = [];

        if ($remoteControlError !== null && trim($remoteControlError) !== '') {
            $payload = [
                'fingerprint' => 'api_unreachable:' . sha1(trim($remoteControlError)),
                'detail' => trim($remoteControlError),
                'threshold_label' => $offlineMinutes . ' min',
                'dedupe_hours' => 1,
            ];
            $this->notifications->notifyAttendanceDeviceAlert('api_unreachable', $payload);
            $this->audit('api_unreachable', $payload);
            $alerts[] = ['type' => 'api_unreachable', 'payload' => $payload];
            return $alerts;
        }

        if (!is_array($remoteControl)) {
            return $alerts;
        }

        foreach ($this->extractDeviceRows($remoteControl) as $row) {
            $label = $this->firstString($row, ['device_name', 'name', 'label', 'terminal_name', 'collector_name', 'hostname', 'host', 'pc_name', 'device_sn', 'sn', 'serial_number', 'id']);
            if ($label === '') {
                $label = 'Badgeuse';
            }
            $serial = $this->firstString($row, ['device_sn', 'sn', 'serial_number', 'terminal_sn', 'collector_id', 'id']);
            $status = strtolower($this->firstString($row, ['connection_status', 'device_status', 'status', 'state', 'health', 'level']));
            $clockStatus = strtolower($this->firstString($row, ['clock_status', 'clock_state', 'time_status']));
            $lastSeen = $this->firstString($row, ['last_seen_at', 'last_contact_at', 'last_heartbeat_at', 'last_sent_at', 'last_ok_at', 'last_push_at', 'updated_at']);
            $deviceTime = $this->firstString($row, ['last_device_time_at', 'device_time', 'last_punch_time', 'punch_time']);
            $driftSeconds = $this->firstNumeric($row, ['clock_drift_seconds', 'drift_seconds', 'time_drift_seconds', 'clock_offset_seconds']);
            $directClockRead = $this->hasDirectClockRead($row);
            $ageSeconds = $this->ageSeconds($lastSeen);

            $isOffline = str_contains($status, 'offline')
                || str_contains($status, 'never_seen')
                || str_contains($status, 'deconnect')
                || str_contains($status, 'déconnect')
                || ($ageSeconds !== null && $ageSeconds > ($offlineMinutes * 60));

            if ($isOffline) {
                $payload = [
                    'fingerprint' => 'offline:' . ($serial !== '' ? $serial : $label),
                    'device_label' => $label,
                    'device_sn' => $serial,
                    'last_seen_at' => $lastSeen,
                    'last_seen_label' => $lastSeen !== '' ? $lastSeen : 'jamais vue',
                    'threshold_label' => $offlineMinutes . ' min',
                    'dedupe_hours' => 1,
                ];
                $this->notifications->notifyAttendanceDeviceAlert('offline', $payload);
                $this->audit('offline', $payload);
                $alerts[] = ['type' => 'offline', 'payload' => $payload];
            }

            $isClockOut = $directClockRead && (
                str_contains($clockStatus, 'out_of_sync')
                || str_contains($clockStatus, 'drift')
                || ($driftSeconds !== null && abs($driftSeconds) > $driftThreshold)
            );

            if ($isClockOut) {
                $payload = [
                    'fingerprint' => 'clock_drift:' . ($serial !== '' ? $serial : $label),
                    'device_label' => $label,
                    'device_sn' => $serial,
                    'device_time' => $deviceTime !== '' ? $deviceTime : 'inconnue',
                    'clock_drift_seconds' => $driftSeconds,
                    'drift_label' => $driftSeconds !== null ? $this->formatSeconds((int) $driftSeconds) : 'ecart non precise',
                    'threshold_label' => $this->formatSeconds($driftThreshold),
                    'dedupe_hours' => 1,
                ];
                $this->notifications->notifyAttendanceDeviceAlert('clock_drift', $payload);
                $this->audit('clock_drift', $payload);
                $alerts[] = ['type' => 'clock_drift', 'payload' => $payload];
            }
        }

        return $alerts;
    }

    public function inspectHealth(?array $health): array
    {
        \EnvConfig::loadFromDirectory(__DIR__ . '/../model');
        $threshold = max(1, (int) (\EnvConfig::get('ATTENDANCE_SERVER_TIME_DRIFT_ALERT_SECONDS', '30') ?? '30'));
        $alerts = [];

        if (!is_array($health)) {
            return $alerts;
        }

        $timeSources = $health['time_sources'] ?? null;
        if (!is_array($timeSources)) {
            return $alerts;
        }

        $delta = $this->firstNumeric($timeSources, ['mysql_php_delta_seconds', 'delta_seconds', 'php_mysql_delta_seconds']);
        if ($delta === null || abs($delta) <= $threshold) {
            return $alerts;
        }

        $payload = [
            'fingerprint' => 'server_time_mismatch',
            'device_label' => 'Serveur pointage',
            'php_gmt' => $this->firstString($timeSources, ['php_gmt', 'php_utc', 'application_gmt']),
            'mysql_utc' => $this->firstString($timeSources, ['mysql_utc', 'database_utc']),
            'clock_drift_seconds' => $delta,
            'drift_label' => $this->formatSeconds($delta),
            'threshold_label' => $this->formatSeconds($threshold),
            'dedupe_hours' => 1,
        ];
        $this->notifications->notifyAttendanceDeviceAlert('server_time_mismatch', $payload);
        $this->audit('server_time_mismatch', $payload);
        $alerts[] = ['type' => 'server_time_mismatch', 'payload' => $payload];

        return $alerts;
    }

    private function extractDeviceRows(array $payload): array
    {
        $rows = [];
        foreach (['devices', 'attendance_devices', 'terminals', 'collectors', 'collector_list'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $rows = array_merge($rows, $payload[$key]);
            }
            if (isset($payload['data'][$key]) && is_array($payload['data'][$key])) {
                $rows = array_merge($rows, $payload['data'][$key]);
            }
        }
        if (empty($rows) && isset($payload['data']) && is_array($payload['data']) && $this->isList($payload['data'])) {
            $rows = $payload['data'];
        }

        return array_values(array_filter($rows, static fn($row): bool => is_array($row)));
    }

    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private function hasDirectClockRead(array $row): bool
    {
        $source = strtolower($this->firstString($row, ['clock_source', 'time_source', 'clock_read_source']));
        if (in_array($source, ['direct', 'device_direct', 'zkteco_direct'], true)) {
            return true;
        }

        foreach (['clock_read_at', 'device_clock_read_at', 'direct_clock_read_at', 'clock_drift_is_direct'] as $key) {
            if (!empty($row[$key])) {
                return true;
            }
        }

        return false;
    }

    private function firstString(array $row, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }
        return '';
    }

    private function firstNumeric(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && is_numeric($row[$key])) {
                return (int) round((float) $row[$key]);
            }
        }
        return null;
    }

    private function ageSeconds(string $date): ?int
    {
        if (trim($date) === '') {
            return null;
        }
        try {
            return max(0, time() - (new DateTimeImmutable($date))->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }

    private function formatSeconds(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '';
        $seconds = abs($seconds);
        if ($seconds >= 3600) {
            return $sign . round($seconds / 3600, 2) . ' h';
        }
        if ($seconds >= 60) {
            return $sign . round($seconds / 60, 1) . ' min';
        }
        return $sign . $seconds . ' s';
    }

    private function audit(string $type, array $payload): void
    {
        try {
            \AuditTrail::log('attendance_device_alert_' . $type, [
                'module' => 'rh',
                'action' => 'attendance_device_alert',
                'entity_type' => 'attendance_device',
                'entity_label' => (string) ($payload['device_label'] ?? $type),
                'details' => $payload,
            ], $this->pdo);
        } catch (Throwable) {
        }
    }
}
