<?php

require_once __DIR__ . '/../repositories/DocumentationRepository.php';

class DocumentationService
{
    private DocumentationRepository $repository;

    public function __construct(DocumentationRepository $repository)
    {
        $this->repository = $repository;
    }

    public function boot(int $userId, array $permissions): array
    {
        $manager = $this->repository->manager();
        $context = $this->repository->userContext($userId, $permissions);

        return [
            'manager' => $manager,
            'context' => $context,
            'canUpload' => !empty($permissions['document_upload']) || !empty($context['is_document_admin']) || !empty($context['is_global_admin']),
            'canEdit' => !empty($permissions['document_edit']) || !empty($context['is_document_admin']) || !empty($context['is_global_admin']),
            'canArchive' => !empty($permissions['document_archive']) || !empty($context['is_document_admin']) || !empty($context['is_global_admin']),
            'canDownload' => !empty($permissions['document_download']) || !empty($permissions['document_export']) || !empty($context['is_document_admin']) || !empty($context['is_global_admin']),
        ];
    }
}
