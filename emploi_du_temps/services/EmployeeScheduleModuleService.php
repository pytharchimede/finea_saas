<?php

declare(strict_types=1);

namespace EmploiDuTemps\Services;

require_once dirname(__DIR__) . '/repositories/EmployeeScheduleModuleRepository.php';

use EmploiDuTemps\Repositories\EmployeeScheduleModuleRepository;

class EmployeeScheduleModuleService
{
    private EmployeeScheduleModuleRepository $repository;
    private string $projectRoot;

    public function __construct(EmployeeScheduleModuleRepository $repository, string $projectRoot)
    {
        $this->repository = $repository;
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    /**
     * Conserve les variables attendues par la page historique tout en isolant
     * l'initialisation technique du module.
     *
     * @return array<string,mixed>
     */
    public function buildPageContext(): array
    {
        $pdo = $this->repository->connection();
        $schedule = $this->repository->schedule();
        $msTodo = $this->repository->microsoftToDo();
        $msConfig = $this->loadMicrosoftConfig();

        $msTenantId = trim((string)($msConfig['tenant_id'] ?? ''));
        $msClientId = trim((string)($msConfig['client_id'] ?? ''));
        $msClientSecret = trim((string)($msConfig['client_secret'] ?? ''));

        return [
            'pdo' => $pdo,
            'schedule' => $schedule,
            'scheduleReady' => $schedule->isReady(),
            'msTodo' => $msTodo,
            'msConfigPath' => $this->projectRoot . '/config/microsoft_todo.php',
            'msConfig' => $msConfig,
            'msTenantId' => $msTenantId,
            'msClientId' => $msClientId,
            'msClientSecret' => $msClientSecret,
            'msConfigured' => ($msTenantId !== '' && $msClientId !== '' && $msClientSecret !== ''),
            'msUserId' => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0,
            'msRedirectUri' => $this->buildMicrosoftRedirectUri(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function loadMicrosoftConfig(): array
    {
        $configPath = $this->projectRoot . '/config/microsoft_todo.php';
        if (!is_file($configPath)) {
            return [];
        }

        $loaded = require $configPath;
        return is_array($loaded) ? $loaded : [];
    }

    private function buildMicrosoftRedirectUri(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/emploi_du_temps/index.php');

        return $scheme . '://' . $host . $path . '?action=microsoft_callback';
    }
}
