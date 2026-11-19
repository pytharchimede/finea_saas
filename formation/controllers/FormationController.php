<?php

declare(strict_types=1);

namespace Formation\Controllers;

require_once dirname(__DIR__) . '/services/FormationService.php';

use Formation\Repositories\FormationRepository;
use Formation\Services\FormationService;

class FormationController
{
    private FormationService $service;

    public function __construct(string $projectRoot)
    {
        $this->service = new FormationService(new FormationRepository($projectRoot));
    }

    /**
     * @return array<string,mixed>
     */
    public function moduleContext(): array
    {
        return $this->service->buildModuleContext();
    }

    public function saveMarkdownDocument(string $path, string $content): bool
    {
        return $this->service->saveMarkdownDocument($path, $content);
    }
}
