<?php

declare(strict_types=1);

require_once __DIR__ . '/AuditTrail.php';

class FebOcrSettings
{
    private const STORAGE_RELATIVE_PATH = 'storage/feb/ocr_settings.json';

    public function isEnabled(): bool
    {
        $data = $this->load();
        return !empty($data['enabled']);
    }

    public function mode(): string
    {
        return $this->effectiveMode();
    }

    public function effectiveMode(?DateTimeImmutable $now = null): string
    {
        $settings = $this->load();
        if (!empty($settings['auto_accept_enabled']) && $settings['auto_accept_days']) {
            $now ??= new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan'));
            $day = (int)$now->format('N');
            $minute = (int)$now->format('G') * 60 + (int)$now->format('i');
            $start = $this->minutes((string)$settings['auto_accept_start']);
            $end = $this->minutes((string)$settings['auto_accept_end']);
            if (in_array($day, $settings['auto_accept_days'], true) && $start <= $minute && $minute < $end) {
                return 'allow_all';
            }
        }
        return (string)$settings['verification_mode'];
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));
        return $hours * 60 + $minutes;
    }

    public function load(): array
    {
        $defaults = $this->getDefaults();
        $filePath = $this->getStoragePath();

        if (!is_file($filePath)) {
            return $defaults;
        }

        $payload = @file_get_contents($filePath);
        if (!is_string($payload) || trim($payload) === '') {
            return $defaults;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        return array_replace($defaults, $this->sanitize($decoded));
    }

    public function save(array $data, array $actor = []): bool
    {
        $directory = dirname($this->getStoragePath());
        if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
            return false;
        }

        $sanitized = $this->sanitize($data);
        $sanitized['updated_at'] = date('c');

        $payload = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        $ok = @file_put_contents($this->getStoragePath(), $payload) !== false;
        if ($ok) {
            \AuditTrail::log('feb_ocr_settings_updated', [
                'module' => 'admin',
                'action' => 'feb_ocr_settings',
                'entity_type' => 'settings',
                'entity_label' => 'feb_ocr',
                'user_id' => isset($actor['user_id']) ? (int) $actor['user_id'] : null,
                'user_name' => isset($actor['user_name']) ? (string) $actor['user_name'] : null,
                'result' => 'success',
                'details' => [
                    'enabled' => !empty($sanitized['enabled']),
                    'verification_mode' => (string)$sanitized['verification_mode'],
                    'human_reviewer_ids' => $sanitized['human_reviewer_ids'],
                    'auto_accept_enabled' => $sanitized['auto_accept_enabled'],
                    'auto_accept_days' => $sanitized['auto_accept_days'],
                    'auto_accept_start' => $sanitized['auto_accept_start'],
                    'auto_accept_end' => $sanitized['auto_accept_end'],
                ],
            ]);
        }

        return $ok;
    }

    private function getStoragePath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::STORAGE_RELATIVE_PATH);
    }

    private function getDefaults(): array
    {
        return [
            'enabled' => true,
            'verification_mode' => 'ai',
            'human_reviewer_ids' => [],
            'auto_accept_enabled' => false,
            'auto_accept_days' => [],
            'auto_accept_start' => '08:00',
            'auto_accept_end' => '18:00',
            'updated_at' => null,
        ];
    }

    private function sanitize(array $data): array
    {
        $mode = (string)($data['verification_mode'] ?? 'ai');
        if (!in_array($mode, ['ai', 'human', 'allow_all'], true)) $mode = 'ai';
        $start = (string)($data['auto_accept_start'] ?? '08:00');
        $end = (string)($data['auto_accept_end'] ?? '18:00');
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $start)) $start = '08:00';
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $end)) $end = '18:00';
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($data['auto_accept_days'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7)));
        sort($days);
        return [
            'enabled' => !empty($data['enabled']),
            'verification_mode' => $mode,
            'human_reviewer_ids' => array_values(array_unique(array_filter(array_map('intval', (array)($data['human_reviewer_ids'] ?? [])), static fn(int $id): bool => $id > 0))),
            'auto_accept_enabled' => !empty($data['auto_accept_enabled']) && $days && $this->minutes($start) < $this->minutes($end),
            'auto_accept_days' => $days,
            'auto_accept_start' => $start,
            'auto_accept_end' => $end,
            'updated_at' => isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        ];
    }
}
