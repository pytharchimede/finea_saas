<?php

require_once __DIR__ . '/../repositories/AiTemplateRepository.php';
require_once __DIR__ . '/../repositories/AiRequestRepository.php';
require_once __DIR__ . '/../repositories/AiFeedbackRepository.php';
require_once __DIR__ . '/../repositories/AiUsageLogRepository.php';
require_once __DIR__ . '/../repositories/AiSensitiveReplacementRepository.php';
require_once __DIR__ . '/../repositories/AiContextRepository.php';
require_once __DIR__ . '/../services/AiTemplateService.php';
require_once __DIR__ . '/../services/AiAnonymizerService.php';
require_once __DIR__ . '/../services/AiExternalProviderService.php';
require_once __DIR__ . '/../services/AiAssistantService.php';
require_once __DIR__ . '/../services/AiAssistantMigrationService.php';

class AiAssistantController
{
    private PDO $pdo;
    private AiAssistantService $assistantService;
    private AiContextRepository $contextRepository;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        (new AiAssistantMigrationService($pdo))->ensureSchema();

        $templateRepository = new AiTemplateRepository($pdo);
        $this->contextRepository = new AiContextRepository($pdo);
        $this->assistantService = new AiAssistantService(
            new AiTemplateService($templateRepository),
            new AiExternalProviderService(require __DIR__ . '/../config/ai.php', new AiAnonymizerService()),
            new AiRequestRepository($pdo),
            new AiFeedbackRepository($pdo),
            new AiUsageLogRepository($pdo),
            new AiSensitiveReplacementRepository($pdo)
        );
    }

    public function viewData(int $userId, array $permissions = []): array
    {
        return [
            'csrf_token' => $this->csrfToken(),
            'options' => $this->assistantService->options(),
            'history' => $this->assistantService->recentHistory($userId),
            'user_profile' => $this->contextRepository->getCurrentUserProfile($userId),
            'existing_requests' => $this->contextRepository->listSelectableRequests($userId, $permissions, 40),
        ];
    }

    public function handlePost(array $post, int $userId, array $server): array
    {
        $this->assertCsrf((string)($post['csrf_token'] ?? ''));
        $action = trim((string)($post['action'] ?? 'generate'));

        return match ($action) {
            'generate' => $this->assistantService->generate($post, $userId, $server),
            'improve_external' => $this->assistantService->improveWithExternalAi($post, $userId, $server),
            'field_generate' => $this->assistantService->generateFieldDraft($post, $userId, $server),
            'feedback' => $this->assistantService->saveFeedback($post, $userId, $server),
            'save_template' => $this->assistantService->saveValidatedTemplate($post, $userId, $server),
            default => throw new RuntimeException('Action assistant inconnue.'),
        };
    }

    public function csrfToken(): string
    {
        if (empty($_SESSION['ai_assistant_csrf'])) {
            $_SESSION['ai_assistant_csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['ai_assistant_csrf'];
    }

    private function assertCsrf(string $token): void
    {
        if ($token === '' || empty($_SESSION['ai_assistant_csrf']) || !hash_equals((string)$_SESSION['ai_assistant_csrf'], $token)) {
            throw new RuntimeException('Session expiree. Rechargez la page.');
        }
    }
}
