<?php

declare(strict_types=1);

namespace Formation\Services;

use Formation\Repositories\FormationAcademyRepository;

class FormationAcademyService
{
    public function __construct(private FormationAcademyRepository $repository)
    {
    }

    public function dashboard(int $userId, array $permissions, bool $isAdmin = false): array
    {
        $courses = $this->repository->visibleCourses($permissions, $isAdmin);
        $progress = $this->repository->userProgressMap($userId);
        $stats = $this->repository->masteryStats($userId, $permissions, $isAdmin);

        foreach ($courses as &$course) {
            $row = $progress[(int) $course['id']] ?? null;
            $course['progress_percent'] = (float) ($row['progress_percent'] ?? 0);
            $course['best_score'] = (float) ($row['best_score'] ?? 0);
            $course['status'] = (string) ($row['status'] ?? 'not_started');
            $course['level'] = FormationAcademyRepository::levelForScore((float) $course['best_score']);
        }
        unset($course);

        return [
            'courses' => $courses,
            'stats' => $stats,
        ];
    }

    public function courseDetail(string $slug): ?array
    {
        $course = $this->repository->courseBySlug($slug);
        if (!$course) {
            return null;
        }

        $lessons = $this->repository->lessonsForCourse((int) $course['id']);
        foreach ($lessons as &$lesson) {
            $lesson['blocks'] = $this->repository->lessonBlocks((int) $lesson['id']);
        }
        unset($lesson);

        return [
            'course' => $course,
            'lessons' => $lessons,
            'quiz' => $this->repository->finalQuizForCourse((int) $course['id']),
        ];
    }

    public function quizPayload(int $quizId): array
    {
        return $this->repository->quizQuestionsWithAnswers($quizId);
    }

    public function gradeQuiz(int $userId, int $courseId, int $quizId, array $submitted): array
    {
        $questions = $this->repository->quizQuestionsWithAnswers($quizId);
        $totalPoints = 0;
        $earnedPoints = 0.0;
        $rows = [];

        foreach ($questions as $question) {
            $questionId = (int) $question['id'];
            $points = (int) $question['points'];
            $totalPoints += $points;
            $selected = array_map('intval', (array) ($submitted['question_' . $questionId] ?? []));
            sort($selected);

            $correct = [];
            foreach ($question['answers'] as $answer) {
                if (!empty($answer['is_correct'])) {
                    $correct[] = (int) $answer['id'];
                }
            }
            sort($correct);

            $isCorrect = ($selected === $correct);
            $earned = $isCorrect ? $points : 0;
            $earnedPoints += $earned;
            $rows[] = [
                'question_id' => $questionId,
                'selected_answer_ids' => $selected,
                'is_correct' => $isCorrect,
                'earned_points' => $earned,
            ];
        }

        $score = $totalPoints > 0 ? round(($earnedPoints / $totalPoints) * 100, 2) : 0.0;
        $quiz = $this->repository->quizById($quizId);
        $requiredScore = (int) ($quiz['min_score'] ?? 75);
        $passed = $score >= $requiredScore;
        $attemptId = $this->repository->saveAttempt($userId, $courseId, $quizId, $score, $earnedPoints, $totalPoints, $passed, $rows);

        $certificate = null;
        if ($passed) {
            $certificate = $this->repository->createCertificate($userId, $courseId, $score, FormationAcademyRepository::levelForScore($score));
        }

        return [
            'attempt_id' => $attemptId,
            'score' => $score,
            'earned_points' => $earnedPoints,
            'total_points' => $totalPoints,
            'passed' => $passed,
            'level' => FormationAcademyRepository::levelForScore($score),
            'certificate' => $certificate,
        ];
    }


    public function adminHome(): array
    {
        return [
            'stats' => $this->repository->adminStats(),
            'courses' => $this->repository->adminCourses(),
            'attempts' => $this->repository->adminAttempts(12),
            'certificates' => $this->repository->adminCertificates(12),
        ];
    }

    public function adminCourseEditor(int $courseId = 0): array
    {
        $course = $courseId > 0 ? $this->repository->courseById($courseId) : null;
        return [
            'course' => $course,
            'courses' => $this->repository->adminCourses(),
            'lessons' => $courseId > 0 ? $this->repository->lessonsForCourse($courseId) : [],
            'permissions' => $courseId > 0 ? $this->repository->permissionsForCourse($courseId) : [],
            'requirements' => $courseId > 0 ? $this->repository->requirementsForCourse($courseId) : [],
        ];
    }

    public function saveAdminCourse(array $post): int
    {
        $courseId = $this->repository->saveCourse($post);
        $rawPermissions = preg_split('/[\r\n,;]+/', (string) ($post['permissions_text'] ?? '')) ?: [];
        $this->repository->setCoursePermissions($courseId, $rawPermissions);
        return $courseId;
    }

    public function saveAdminRequirement(array $post): int
    {
        return $this->repository->saveRequirement($post);
    }

    public function saveAdminLesson(array $post): int
    {
        return $this->repository->saveLesson($post);
    }

    public function saveAdminLessonBlock(array $post): int
    {
        return $this->repository->saveLessonBlock($post);
    }

    public function adminExportRows(string $type): array
    {
        return $this->repository->adminExportRows($type);
    }

    public function markLessonCompleted(int $userId, int $courseId, int $lessonId, int $seconds): void
    {
        $this->repository->markLessonCompleted($userId, $courseId, $lessonId, $seconds);
    }
}
