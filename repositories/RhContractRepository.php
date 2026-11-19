<?php

require_once __DIR__ . '/../decaissement/model/RhContractManager.php';

class RhContractRepository
{
    private RhContractManager $contracts;

    public function __construct(PDO $pdo)
    {
        $this->contracts = new RhContractManager($pdo);
    }

    public function model(): RhContractManager
    {
        return $this->contracts;
    }

    public function __call(string $name, array $arguments)
    {
        return $this->contracts->{$name}(...$arguments);
    }
}
