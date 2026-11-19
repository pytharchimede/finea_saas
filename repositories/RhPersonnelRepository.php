<?php

require_once __DIR__ . '/../decaissement/model/PersonnelDirectory.php';

class RhPersonnelRepository
{
    private PersonnelDirectory $directory;

    public function __construct(PDO $pdo)
    {
        $this->directory = new PersonnelDirectory($pdo);
    }

    public function model(): PersonnelDirectory
    {
        return $this->directory;
    }

    public function getFilters(): array
    {
        return $this->directory->getFilters();
    }

    public function getQuickStats(): array
    {
        return $this->directory->getQuickStats();
    }

    public function getPersonnelList(array $filters, int $page, int $perPage = 12): array
    {
        return $this->directory->getPersonnelList($filters, $page, $perPage);
    }

    public function __call(string $name, array $arguments)
    {
        return $this->directory->{$name}(...$arguments);
    }
}
