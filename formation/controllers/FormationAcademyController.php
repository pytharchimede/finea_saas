<?php

declare(strict_types=1);

namespace Formation\Controllers;

require_once dirname(__DIR__) . '/repositories/FormationAcademyRepository.php';
require_once dirname(__DIR__) . '/services/FormationAcademyService.php';

use Formation\Repositories\FormationAcademyRepository;
use Formation\Services\FormationAcademyService;
use PDO;

class FormationAcademyController
{
    private FormationAcademyService $service;

    public function __construct(PDO $pdo)
    {
        $repository = new FormationAcademyRepository($pdo);
        $repository->ensureAcademyTables();
        $this->service = new FormationAcademyService($repository);
    }

    public function dashboard(int $userId, array $permissions, bool $isAdmin = false): array
    {
        return $this->service->dashboard($userId, $permissions, $isAdmin);
    }

    public function course(string $slug): ?array
    {
        return $this->service->courseDetail($slug);
    }

    public function quiz(int $quizId): array
    {
        return $this->service->quizPayload($quizId);
    }

    public function submitQuiz(int $userId, int $courseId, int $quizId, array $post): array
    {
        return $this->service->gradeQuiz($userId, $courseId, $quizId, $post);
    }


    public function adminHome(): array
    {
        return $this->service->adminHome();
    }

    public function adminCourseEditor(int $courseId = 0): array
    {
        return $this->service->adminCourseEditor($courseId);
    }

    public function saveAdminCourse(array $post): int
    {
        return $this->service->saveAdminCourse($post);
    }

    public function saveAdminRequirement(array $post): int
    {
        return $this->service->saveAdminRequirement($post);
    }

    public function saveAdminLesson(array $post): int
    {
        return $this->service->saveAdminLesson($post);
    }

    public function saveAdminLessonBlock(array $post): int
    {
        return $this->service->saveAdminLessonBlock($post);
    }

    public function adminExportRows(string $type): array
    {
        return $this->service->adminExportRows($type);
    }

    public function markLessonCompleted(int $userId, int $courseId, int $lessonId, int $seconds): void
    {
        $this->service->markLessonCompleted($userId, $courseId, $lessonId, $seconds);
    }
}
