<?php

declare(strict_types=1);

namespace EmploiDuTemps\Repositories;

require_once dirname(__DIR__, 2) . '/model/Database.php';
require_once dirname(__DIR__, 2) . '/model/EmployeeSchedule.php';
require_once dirname(__DIR__, 2) . '/model/MicrosoftToDo.php';

use PDO;

class EmployeeScheduleModuleRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? \Database::getConnection();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function schedule(): \EmployeeSchedule
    {
        return new \EmployeeSchedule($this->pdo);
    }

    public function microsoftToDo(): \MicrosoftToDo
    {
        return new \MicrosoftToDo($this->pdo);
    }
}
