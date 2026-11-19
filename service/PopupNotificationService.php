<?php

require_once __DIR__ . '/../repository/PopupNotificationRepository.php';

class PopupNotificationService
{
    private PopupNotificationRepository $repository;

    public function __construct(PopupNotificationRepository $repository)
    {
        $this->repository = $repository;
        $this->repository->ensureTables();
    }

    public function autoCreateTablesIfMissing(): void
    {
        $this->repository->ensureTables();
    }

    public function listNotifications(array $filters = []): array
    {
        return $this->repository->listNotifications($filters);
    }

    public function getNotification(int $id): ?array
    {
        return $this->repository->getNotificationById($id);
    }

    public function getFormOptions(): array
    {
        return [
            'types' => PopupNotification::TYPE_OPTIONS,
            'priorities' => PopupNotification::PRIORITY_OPTIONS,
            'targetScopes' => PopupNotification::TARGET_SCOPE_OPTIONS,
            'users' => $this->repository->getActiveUsersForSelection(),
            'services' => $this->repository->getAvailableServices(),
            'modules' => $this->repository->getAvailableModules(),
        ];
    }

    public function saveFromPost(array $post, int $actorUserId): int
    {
        $scope = PopupNotification::normalizeTargetScope((string) ($post['target_scope'] ?? 'all'));
        $targets = [];

        if ($scope === 'users') {
            $selectedUsers = is_array($post['target_users'] ?? null) ? (array) $post['target_users'] : [];
            foreach ($selectedUsers as $userId) {
                $userId = (int) $userId;
                if ($userId > 0) {
                    $targets[] = ['target_type' => 'user', 'target_value' => (string) $userId];
                }
            }
            if (!$targets) {
                throw new RuntimeException('Sélectionnez au moins un utilisateur ciblé.');
            }
        } elseif ($scope === 'services') {
            $selectedServices = is_array($post['target_services'] ?? null) ? (array) $post['target_services'] : [];
            foreach ($selectedServices as $serviceValue) {
                $serviceValue = trim((string) $serviceValue);
                if ($serviceValue !== '') {
                    $targets[] = ['target_type' => 'service', 'target_value' => $serviceValue];
                }
            }
            if (!$targets) {
                $manualService = trim((string) ($post['target_service_manual'] ?? ''));
                if ($manualService !== '') {
                    $targets[] = ['target_type' => 'service', 'target_value' => $manualService];
                }
            }
            if (!$targets) {
                throw new RuntimeException('Sélectionnez ou saisissez au moins un service ciblé.');
            }
        } elseif ($scope === 'module_access') {
            $selectedModules = is_array($post['target_modules'] ?? null) ? (array) $post['target_modules'] : [];
            foreach ($selectedModules as $moduleKey) {
                $moduleKey = trim((string) $moduleKey);
                if ($moduleKey !== '') {
                    $targets[] = ['target_type' => 'module_access', 'target_value' => $moduleKey];
                }
            }
            if (!$targets) {
                throw new RuntimeException('Sélectionnez au moins un module pour le ciblage par accès.');
            }
        }

        $actions = $this->normalizeActions(is_array($post['actions'] ?? null) ? $post['actions'] : []);
        $type = PopupNotification::normalizeType((string) ($post['type'] ?? 'information'));
        if ($type === 'sondage') {
            $surveyChoices = array_filter($actions, static fn(array $action): bool => $action['action_type'] === 'survey_choice');
            if (count($surveyChoices) < 2) {
                throw new RuntimeException('Un sondage doit contenir au moins deux choix de réponse.');
            }
        }

        $requiresReadValidation = !empty($post['requires_read_validation']);
        $questions = $this->normalizeReadValidationQuestions(is_array($post['questions'] ?? null) ? $post['questions'] : [], $requiresReadValidation);

        return $this->repository->saveNotification([
            'id' => (int) ($post['id'] ?? 0),
            'title' => (string) ($post['title'] ?? ''),
            'type' => $type,
            'short_message' => (string) ($post['short_message'] ?? ''),
            'body' => (string) ($post['body'] ?? ''),
            'module_key' => (string) ($post['module_key'] ?? ''),
            'priority' => (string) ($post['priority'] ?? 'normal'),
            'is_active' => !empty($post['is_active']),
            'requires_read_validation' => $requiresReadValidation,
            'starts_at' => (string) ($post['starts_at'] ?? ''),
            'ends_at' => (string) ($post['ends_at'] ?? ''),
            'actions' => $actions,
            'questions' => $questions,
            'target_scope' => $scope,
            'created_by' => $actorUserId > 0 ? $actorUserId : null,
        ], $targets);
    }


    public function submitReadValidation(
        int $notificationId,
        int $userId,
        array $answers,
        ?string $ip,
        ?string $userAgent
    ): array {
        if (!$this->repository->userCanReadNotification($notificationId, $userId)) {
            throw new RuntimeException('Cette notification ne vous est pas destinée.');
        }

        $notification = $this->repository->getNotificationById($notificationId);
        if (!$notification) {
            throw new RuntimeException('Notification introuvable.');
        }

        if ((int)($notification['requires_read_validation'] ?? 0) !== 1) {
            return [
                'success' => true,
                'passed' => true,
                'score' => 0,
                'total' => 0,
                'message' => 'Cette notification ne nécessite pas de QCM.',
            ];
        }

        $questions = $this->repository->getQuestionsForNotification($notificationId, true);
        if (!$questions) {
            throw new RuntimeException('Aucune question de validation n’est configurée pour cette notification.');
        }

        $score = 0;
        $total = count($questions);

        foreach ($questions as $question) {
            $questionId = (int)($question['id'] ?? 0);
            $selectedChoiceId = (int)($answers[$questionId] ?? 0);
            $correctChoiceId = 0;

            foreach (($question['choices'] ?? []) as $choice) {
                if ((int)($choice['is_correct'] ?? 0) === 1) {
                    $correctChoiceId = (int)($choice['id'] ?? 0);
                    break;
                }
            }

            if ($selectedChoiceId > 0 && $selectedChoiceId === $correctChoiceId) {
                $score++;
            }
        }

        $passed = $score === $total;
        $this->repository->saveReadValidationAttempt($notificationId, $userId, $score, $total, $passed);

        if ($passed) {
            $this->repository->markAsRead($notificationId, $userId, $ip, $userAgent);
        }

        return [
            'success' => true,
            'passed' => $passed,
            'score' => $score,
            'total' => $total,
            'message' => $passed
                ? 'Lecture validée. Vous pouvez maintenant confirmer votre action.'
                : 'Veuillez relire la notification et corriger vos réponses.',
        ];
    }

    public function submitResponse(
        int $notificationId,
        int $userId,
        int $actionId,
        ?string $ip,
        ?string $userAgent
    ): array {
        if (!$this->repository->userCanReadNotification($notificationId, $userId)) {
            throw new RuntimeException('Cette notification ne vous est pas destinée.');
        }

        $notification = $this->repository->getNotificationById($notificationId);
        if ($notification && (int)($notification['requires_read_validation'] ?? 0) === 1) {
            $validation = $this->repository->getReadValidationForUser($notificationId, $userId);
            if (!$validation || (int)($validation['is_success'] ?? 0) !== 1) {
                throw new RuntimeException('Veuillez d’abord valider la lecture par QCM.');
            }
        }

        $actions = $this->repository->getActionsForNotification($notificationId);
        $selectedAction = null;
        foreach ($actions as $action) {
            if ((int) ($action['id'] ?? 0) === $actionId) {
                $selectedAction = $action;
                break;
            }
        }

        if ($actions !== [] && $selectedAction === null) {
            throw new RuntimeException('Veuillez sélectionner l’un des choix proposés.');
        }
        if ($actions === [] && $actionId > 0) {
            throw new RuntimeException('Action de notification invalide.');
        }

        $responseValue = 'acknowledged';
        if ($selectedAction !== null) {
            $responseValue = trim((string) ($selectedAction['action_value'] ?? ''));
            if ($responseValue === '') {
                $responseValue = trim((string) ($selectedAction['label'] ?? 'acknowledged'));
            }
        }

        $saved = $this->repository->saveResponse(
            $notificationId,
            $userId,
            $selectedAction !== null ? (int) $selectedAction['id'] : null,
            $responseValue,
            $ip,
            $userAgent
        );

        return [
            'success' => $saved,
            'action_type' => (string) ($selectedAction['action_type'] ?? 'ack'),
            'action_value' => (string) ($selectedAction['action_value'] ?? ''),
        ];
    }


    private function normalizeReadValidationQuestions(array $questions, bool $required): array
    {
        $normalized = [];

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $questionText = trim((string)($question['question_text'] ?? ''));
            if ($questionText === '') {
                continue;
            }

            $choices = [];
            $correctCount = 0;
            $correctChoiceIndex = isset($question['correct_choice']) ? (int)$question['correct_choice'] : null;
            $rawChoices = is_array($question['choices'] ?? null) ? $question['choices'] : [];

            foreach ($rawChoices as $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $choiceText = trim((string)($choice['choice_text'] ?? ''));
                if ($choiceText === '') {
                    continue;
                }

                $isCorrect = ($correctChoiceIndex !== null && $correctChoiceIndex === count($choices))
                    ? 1
                    : (!empty($choice['is_correct']) ? 1 : 0);
                if ($isCorrect === 1) {
                    $correctCount++;
                }

                $choices[] = [
                    'choice_text' => $choiceText,
                    'is_correct' => $isCorrect,
                ];
            }

            $normalized[] = [
                'question_text' => $questionText,
                'choices' => $choices,
                '_correct_count' => $correctCount,
            ];
        }

        if (!$required) {
            return [];
        }

        if (count($normalized) < 1) {
            throw new RuntimeException('Ajoutez au moins une question de validation de lecture.');
        }

        if (count($normalized) > 3) {
            throw new RuntimeException('La validation de lecture est limitée à 3 questions maximum.');
        }

        foreach ($normalized as $index => $question) {
            if (count($question['choices']) < 2) {
                throw new RuntimeException('Chaque question de validation doit proposer au moins deux choix.');
            }

            if ((int)$question['_correct_count'] !== 1) {
                throw new RuntimeException('Chaque question de validation doit avoir exactement une bonne réponse.');
            }

            unset($normalized[$index]['_correct_count']);
        }

        return $normalized;
    }

    private function normalizeActions(array $actions): array
    {
        $normalized = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $label = trim((string) ($action['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $actionType = (string) ($action['action_type'] ?? 'ack');
            if (!in_array($actionType, ['link', 'ack', 'survey_choice', 'custom'], true)) {
                $actionType = 'ack';
            }
            $normalized[] = [
                'label' => $label,
                'action_type' => $actionType,
                'action_value' => trim((string) ($action['action_value'] ?? '')),
                'is_primary' => !empty($action['is_primary']) ? 1 : 0,
            ];
        }

        if ($normalized === []) {
            $normalized[] = [
                'label' => 'J’ai compris',
                'action_type' => 'ack',
                'action_value' => 'acknowledged',
                'is_primary' => 1,
            ];
        }
        if (!array_filter($normalized, static fn(array $action): bool => !empty($action['is_primary']))) {
            $normalized[0]['is_primary'] = 1;
        }
        return $normalized;
    }

    public function deleteNotification(int $id): void
    {
        $this->repository->deleteNotification($id);
    }

    public function setActive(int $id, bool $isActive): void
    {
        $this->repository->setActive($id, $isActive);
    }

    public function getReadsDashboard(int $notificationId): array
    {
        $notification = $this->repository->getNotificationById($notificationId);
        if (!$notification) {
            throw new RuntimeException('Notification introuvable.');
        }

        $audienceUsers = $this->repository->getAudienceUsersForNotification($notification);
        $reads = $this->repository->listReadReceipts($notificationId);
        $totalAudience = count($audienceUsers);
        $totalReads = count($reads);

        return [
            'notification' => $notification,
            'audience_users' => $audienceUsers,
            'reads' => $reads,
            'audience_total' => $totalAudience,
            'reads_total' => $totalReads,
            'read_rate' => $totalAudience > 0 ? round(($totalReads / $totalAudience) * 100, 1) : 0.0,
        ];
    }

    public function getPendingForUser(int $userId): ?array
    {
        return $this->repository->getPendingNotificationForUser($userId);
    }

    public function markRead(int $notificationId, int $userId, ?string $ip, ?string $userAgent): bool
    {
        if (!$this->repository->userCanReadNotification($notificationId, $userId)) {
            return false;
        }
        return $this->repository->markAsRead($notificationId, $userId, $ip, $userAgent);
    }

    public function getNotificationReport(int $notificationId): array
    {
        $notification = $this->repository->getNotificationById($notificationId);

        if (!$notification) {
            throw new RuntimeException('Notification introuvable.');
        }

        $reads = $this->repository->getNotificationReads($notificationId);
        $responses = $this->repository->getNotificationResponses($notificationId);
        $actionStats = $this->repository->getNotificationActionStats($notificationId);
        $readValidations = $this->repository->getReadValidationStats($notificationId);
        $totalTargeted = $this->repository->countTargetedUsers($notification);

        $totalReads = count($reads);
        $totalResponses = count($responses);

        return [
            'notification' => $notification,
            'reads' => $reads,
            'responses' => $responses,
            'actionStats' => $actionStats,
            'readValidations' => $readValidations,
            'totalReads' => $totalReads,
            'totalResponses' => $totalResponses,
            'totalTargeted' => $totalTargeted,
            'readRate' => $totalTargeted > 0 ? round(($totalReads / $totalTargeted) * 100, 1) : null,
        ];
    }
}
