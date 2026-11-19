<?php

@require_once __DIR__ . '/../decaissement/config/app_config.php';
require_once __DIR__ . '/../decaissement/model/Database.php';
require_once __DIR__ . '/../decaissement/model/DatabaseApp.php';
require_once __DIR__ . '/../decaissement/model/DemandeEssence.php';
require_once __DIR__ . '/../repositories/FuelVoucherReplicationRepository.php';

class FuelVoucherReplicationController
{
    private PDO $projectPdo;
    private PDO $appPdo;

    public function __construct(?PDO $projectPdo = null, ?PDO $appPdo = null)
    {
        $this->projectPdo = $projectPdo ?: Database::getConnection();
        $this->appPdo = $appPdo ?: DatabaseApp::getConnection();
    }

    public function projectConnection(): PDO
    {
        return $this->projectPdo;
    }

    public function appConnection(): PDO
    {
        return $this->appPdo;
    }

    public function projectDemandes(): DemandeEssence
    {
        return new DemandeEssence($this->projectPdo);
    }

    public function appDemandes(): DemandeEssence
    {
        return new DemandeEssence($this->appPdo);
    }

    public function repository(): FuelVoucherReplicationRepository
    {
        return new FuelVoucherReplicationRepository($this->projectPdo, $this->appPdo);
    }
}
