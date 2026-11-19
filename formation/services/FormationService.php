<?php

declare(strict_types=1);

namespace Formation\Services;

require_once dirname(__DIR__) . '/repositories/FormationRepository.php';

use Formation\Repositories\FormationRepository;

class FormationService
{
    private FormationRepository $repository;

    public function __construct(FormationRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @return array<string,mixed>
     */
    public function buildModuleContext(): array
    {
        return [
            'projectRoot' => $this->repository->projectRoot(),
            'docsRoot' => $this->repository->docsRoot(),
        ];
    }

    public function saveMarkdownDocument(string $path, string $content): bool
    {
        return $this->repository->writeDocument($path, $content);
    }
}
