<?php

require_once __DIR__ . '/../model/EnvConfig.php';

class ConfigRepository
{
    public function __construct()
    {
        \EnvConfig::loadFromDirectory(__DIR__ . '/../config');
    }

    public function ai(): array
    {
        return [
            'AI_EXTERNAL_ENABLED' => filter_var(\EnvConfig::get('AI_EXTERNAL_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
            'AI_PROVIDER' => \EnvConfig::get('AI_PROVIDER', 'openrouter'),
            'AI_API_KEY' => \EnvConfig::get('AI_API_KEY', ''),
            'AI_MODEL' => \EnvConfig::get('AI_MODEL', ''),
            'AI_ENDPOINT' => \EnvConfig::get('AI_ENDPOINT', ''),
            'AI_TIMEOUT_SECONDS' => (int)(\EnvConfig::get('AI_TIMEOUT_SECONDS', '25') ?: 25),
        ];
    }

    public function fne(): array
    {
        return [
            'base_url' => \EnvConfig::get('FNE_BASE_URL', 'http://54.247.95.108/ws'),
            'api_key' => \EnvConfig::get('FNE_API_KEY', '') ?: '',
            'connect_timeout' => (int)\EnvConfig::get('FNE_CONNECT_TIMEOUT', '10'),
            'timeout' => (int)\EnvConfig::get('FNE_TIMEOUT', '20'),
        ];
    }

    public function microsoftTodo(): array
    {
        return [
            'tenant_id' => \EnvConfig::get('MS_TODO_TENANT_ID', '') ?: '',
            'client_id' => \EnvConfig::get('MS_TODO_CLIENT_ID', '') ?: '',
            'client_secret' => \EnvConfig::get('MS_TODO_CLIENT_SECRET', '') ?: '',
        ];
    }

    public function documentationSecurity(): array
    {
        return [
            'technical_executable_extensions' => ['exe', 'msi', 'bat', 'cmd', 'ps1'],
            'shareable_executable_extensions' => ['exe', 'msi'],
            'technical_share_badge_label' => 'Partage technique',
        ];
    }

    public function pointFinancierDigest(): array
    {
        return [
            'enabled' => true,
            'timezone' => 'Africa/Abidjan',
            'schedule_time' => '08:00',
            'status_key' => 'decaissements',
            'send_when_empty' => false,
            'from_name' => 'Direction Financière BANAMUR',
            'to' => [
                ['email' => ' braud@banamur.com', 'name' => 'Direction Générale - Paul-Alex BRAUD'],
            ],
            'cc' => [
                ['email' => 'ulrich@banamur.com', 'name' => 'Ulrich AMANI'],
                ['email' => 'baby@banamur.com', 'name' => 'Miss Melissa Baby'],
            ],
            'subject_prefix' => 'BANAMUR - Point des décaissements de la veille',
            'job_key' => 'point_financier_daily_digest',
        ];
    }

    public function defaultTaxRates(): array
    {
        return [
            'TVA' => 0.18,
            'TVAB' => 0.00,
            'TVAC' => 0.00,
            'TVAD' => 0.00,
        ];
    }
}
