<?php

class RemoteAssistanceSettings
{
    private const STORAGE_RELATIVE_PATH = 'storage/remote_assistance/jitsi_settings.json';

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

    public function save(array $data): bool
    {
        $directory = dirname($this->getStoragePath());
        if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
            return false;
        }

        $payload = json_encode($this->sanitize($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return false;
        }

        return @file_put_contents($this->getStoragePath(), $payload) !== false;
    }

    public function getPublicScriptUrl(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);
        return 'https://' . $domain . '/external_api.js';
    }

    public function getPublicMeetingBaseUrl(string $domain): string
    {
        $domain = $this->normalizeDomain($domain);
        return 'https://' . $domain;
    }

    private function getStoragePath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::STORAGE_RELATIVE_PATH);
    }

    private function getDefaults(): array
    {
        return [
            'deployment_mode' => 'public',
            'jitsi_domain' => 'meet.jit.si',
            'room_prefix' => 'FINAEA',
            'brand_label' => 'Finéa Assistance',
            'subdomain' => 'meet.finea.ci',
            'github_repo' => 'jitsi/docker-jitsi-meet',
            'guide_notes' => 'Vous n avez pas besoin de VPS pour utiliser ce module. Gardez meet.jit.si, lancez vos salles directement dans Finéa, puis ne pensez a un serveur prive que plus tard si votre besoin grossit.',
            'start_audio_muted' => false,
            'start_video_muted' => false,
        ];
    }

    private function sanitize(array $data): array
    {
        return [
            'deployment_mode' => in_array((string) ($data['deployment_mode'] ?? 'public'), ['public', 'private'], true)
                ? (string) $data['deployment_mode']
                : 'public',
            'jitsi_domain' => $this->normalizeDomain((string) ($data['jitsi_domain'] ?? 'meet.jit.si')),
            'room_prefix' => $this->normalizeRoomPrefix((string) ($data['room_prefix'] ?? 'FINAEA')),
            'brand_label' => $this->normalizeText((string) ($data['brand_label'] ?? 'Finéa Assistance'), 80, 'Finéa Assistance'),
            'subdomain' => $this->normalizeDomain((string) ($data['subdomain'] ?? 'meet.finea.ci')),
            'github_repo' => $this->normalizeGithubRepo((string) ($data['github_repo'] ?? 'jitsi/docker-jitsi-meet')),
            'guide_notes' => $this->normalizeText((string) ($data['guide_notes'] ?? ''), 800, ''),
            'start_audio_muted' => !empty($data['start_audio_muted']),
            'start_video_muted' => !empty($data['start_video_muted']),
        ];
    }

    private function normalizeDomain(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('#^https?://#', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B/");
        $value = preg_replace('/[^a-z0-9.-]/', '', $value) ?? '';
        return $value !== '' ? $value : 'meet.jit.si';
    }

    private function normalizeRoomPrefix(string $value): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9-]/', '-', $value) ?? '';
        $value = trim(preg_replace('/-+/', '-', $value) ?? '', '-');
        return $value !== '' ? substr($value, 0, 24) : 'FINAEA';
    }

    private function normalizeGithubRepo(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('#^https?://github\.com/#i', '', $value) ?? $value;
        $value = trim($value, '/');
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $value)) {
            return 'jitsi/docker-jitsi-meet';
        }
        return $value;
    }

    private function normalizeText(string $value, int $maxLength, string $fallback): string
    {
        $value = trim($value);
        if ($value === '') {
            return $fallback;
        }
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
}
