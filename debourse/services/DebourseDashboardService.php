<?php

declare(strict_types=1);

namespace Debourse\Services;

use Debourse\Repositories\DebourseRepository;

class DebourseDashboardService
{
    private DebourseRepository $repository;

    public function __construct(DebourseRepository $repository)
    {
        $this->repository = $repository;
    }

    public function dashboard(): array
    {
        $stats = $this->repository->dashboardStats();
        $coverage = $stats['debourses'] > 0
            ? min(100, round(($stats['linked'] / max(1, $stats['sous_lignes'])) * 100))
            : 0;

        return [
            'stats' => $stats,
            'coverage' => $coverage,
            'recent' => $this->repository->recentDebourses(),
            'orphans' => $this->repository->orphanDebourses(),
        ];
    }

    public function listing(?int $chantierId, string $search, string $client): array
    {
        $rows = $this->repository->listDebourses($chantierId, $search, $client);
        $total = array_sum(array_map(static fn($row): float => (float)($row['montant_debourse'] ?? 0), $rows));
        $linkedRows = array_filter($rows, static fn($row): bool => (int)($row['sous_lignes'] ?? 0) > 0);

        return [
            'rows' => $rows,
            'chantiers' => $this->repository->activeChantiers(),
            'clients' => $this->repository->activeClients(),
            'total' => $total,
            'linked_count' => count($linkedRows),
        ];
    }
}
