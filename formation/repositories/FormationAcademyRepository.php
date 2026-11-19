<?php

declare(strict_types=1);

namespace Formation\Repositories;

use PDO;
use RuntimeException;

class FormationAcademyRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }


    /**
     * Auto-migration FINEA Academy.
     * Appelée automatiquement par le contrôleur : aucune exécution SQL manuelle n'est requise.
     * La création des tables est idempotente. Les données de démonstration ne sont injectées
     * qu'une seule fois si les parcours de base n'existent pas encore.
     */
    public function ensureAcademyTables(): void
    {
        static $alreadyEnsured = false;
        if ($alreadyEnsured) {
            return;
        }

        foreach ($this->splitSqlStatements($this->academySchemaSql()) as $statement) {
            $this->pdo->exec($statement);
        }

        if (!$this->demoCoursesAlreadySeeded()) {
            foreach ($this->splitSqlStatements($this->academySeedSql()) as $statement) {
                $this->pdo->exec($statement);
            }
        }

        $this->reconcileAcademyPortalMappings();

        $alreadyEnsured = true;
    }

    private function reconcileAcademyPortalMappings(): void
    {
        try {
            $this->pdo->exec("UPDATE formation_courses SET module_slug = 'ticketing' WHERE slug = 'centre-tickets' AND module_slug IN ('tickets','centre-tickets')");
            $this->pdo->exec("DELETE FROM formation_course_permissions WHERE permission_key = 'acces_tickets' AND course_id IN (SELECT id FROM formation_courses WHERE slug = 'centre-tickets')");
            $this->pdo->exec("INSERT IGNORE INTO formation_course_permissions (course_id, permission_key) SELECT id, 'acces_ticketing' FROM formation_courses WHERE slug = 'centre-tickets'");
            $this->pdo->exec("INSERT IGNORE INTO formation_course_permissions (course_id, permission_key) SELECT id, 'acces_portail' FROM formation_courses WHERE slug IN ('bien-demarrer-finea','notifications-popup')");
        } catch (\Throwable $e) {
            // Ne bloque jamais l'ouverture de Formation pour une correction de mapping.
        }
    }

    private function demoCoursesAlreadySeeded(): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT COUNT(*) AS total FROM formation_courses WHERE slug IN ('bien-demarrer-finea','centre-tickets','notifications-popup','maitriser-feb')");
            return (int) ($stmt->fetch()['total'] ?? 0) >= 4;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** @return list<string> */
    private function splitSqlStatements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = array_map('trim', explode(';', $sql));
        return array_values(array_filter($parts, static fn(string $statement): bool => $statement !== ''));
    }

    private function academySchemaSql(): string
    {
        return <<<'SQL'
-- FINEA Academy - MOOC interne
-- Migration sûre : compatible MySQL/MariaDB, à exécuter une seule fois.

CREATE TABLE IF NOT EXISTS formation_courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) NOT NULL UNIQUE,
    module_slug VARCHAR(120) NOT NULL,
    title VARCHAR(190) NOT NULL,
    description TEXT NULL,
    audience ENUM('utilisateur','technique','mixte') NOT NULL DEFAULT 'utilisateur',
    required_score TINYINT UNSIGNED NOT NULL DEFAULT 75,
    estimated_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_formation_courses_module (module_slug),
    INDEX idx_formation_courses_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_course_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    permission_key VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_permission (course_id, permission_key),
    CONSTRAINT fk_fcp_course FOREIGN KEY (course_id) REFERENCES formation_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_lessons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    slug VARCHAR(120) NOT NULL,
    title VARCHAR(190) NOT NULL,
    lesson_type ENUM('lecture','video','interactive','quiz') NOT NULL DEFAULT 'lecture',
    markdown_path VARCHAR(255) NULL,
    video_url VARCHAR(500) NULL,
    estimated_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_lesson_course_slug (course_id, slug),
    INDEX idx_lessons_course (course_id, sort_order),
    CONSTRAINT fk_lessons_course FOREIGN KEY (course_id) REFERENCES formation_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_lesson_blocks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lesson_id INT UNSIGNED NOT NULL,
    block_type ENUM('text','info','warning','image','video','step','exercise') NOT NULL DEFAULT 'text',
    title VARCHAR(190) NULL,
    content MEDIUMTEXT NULL,
    asset_path VARCHAR(500) NULL,
    payload_json JSON NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_blocks_lesson (lesson_id, sort_order),
    CONSTRAINT fk_blocks_lesson FOREIGN KEY (lesson_id) REFERENCES formation_lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_quizzes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    min_score TINYINT UNSIGNED NOT NULL DEFAULT 75,
    max_attempts SMALLINT UNSIGNED NULL,
    is_final TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_quizzes_course (course_id, is_final),
    CONSTRAINT fk_quizzes_course FOREIGN KEY (course_id) REFERENCES formation_courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_quizzes_lesson FOREIGN KEY (lesson_id) REFERENCES formation_lessons(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_quiz_questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT UNSIGNED NOT NULL,
    question_type ENUM('single','multiple','true_false','ordering') NOT NULL DEFAULT 'single',
    question_text TEXT NOT NULL,
    explanation TEXT NULL,
    image_path VARCHAR(500) NULL,
    points SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_questions_quiz (quiz_id, sort_order),
    CONSTRAINT fk_questions_quiz FOREIGN KEY (quiz_id) REFERENCES formation_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_quiz_answers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    answer_text TEXT NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_answers_question (question_id, sort_order),
    CONSTRAINT fk_answers_question FOREIGN KEY (question_id) REFERENCES formation_quiz_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    quiz_id INT UNSIGNED NOT NULL,
    score DECIMAL(5,2) NOT NULL DEFAULT 0,
    total_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    earned_points DECIMAL(8,2) NOT NULL DEFAULT 0,
    passed TINYINT(1) NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    INDEX idx_attempts_user_course (user_id, course_id),
    INDEX idx_attempts_quiz (quiz_id),
    CONSTRAINT fk_attempts_course FOREIGN KEY (course_id) REFERENCES formation_courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempts_quiz FOREIGN KEY (quiz_id) REFERENCES formation_quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_attempt_answers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    selected_answer_ids JSON NULL,
    ordering_payload JSON NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    earned_points DECIMAL(8,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attempt_question (attempt_id, question_id),
    CONSTRAINT fk_attempt_answers_attempt FOREIGN KEY (attempt_id) REFERENCES formation_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempt_answers_question FOREIGN KEY (question_id) REFERENCES formation_quiz_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_user_progress (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    lesson_id INT UNSIGNED NULL,
    status ENUM('not_started','in_progress','completed','certified') NOT NULL DEFAULT 'not_started',
    progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    best_score DECIMAL(5,2) NOT NULL DEFAULT 0,
    time_spent_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    last_seen_at DATETIME NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_progress_user_course_lesson (user_id, course_id, lesson_id),
    INDEX idx_progress_user_course (user_id, course_id),
    CONSTRAINT fk_progress_course FOREIGN KEY (course_id) REFERENCES formation_courses(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_lesson FOREIGN KEY (lesson_id) REFERENCES formation_lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_certificates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    certificate_ref VARCHAR(80) NOT NULL UNIQUE,
    score DECIMAL(5,2) NOT NULL,
    level_label VARCHAR(80) NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    verification_code VARCHAR(120) NOT NULL UNIQUE,
    pdf_path VARCHAR(500) NULL,
    INDEX idx_certificates_user (user_id),
    INDEX idx_certificates_course (course_id),
    CONSTRAINT fk_certificates_course FOREIGN KEY (course_id) REFERENCES formation_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS formation_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    module_slug VARCHAR(120) NOT NULL,
    permission_key VARCHAR(120) NULL,
    requirement_type ENUM('recommended','mandatory','blocking') NOT NULL DEFAULT 'recommended',
    blocking_action VARCHAR(120) NULL,
    required_score TINYINT UNSIGNED NOT NULL DEFAULT 75,
    deadline_days SMALLINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_requirements_module (module_slug, is_active),
    INDEX idx_requirements_permission (permission_key),
    CONSTRAINT fk_requirements_course FOREIGN KEY (course_id) REFERENCES formation_courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    private function academySeedSql(): string
    {
        return <<<'SQL'
-- Données de démonstration minimales
INSERT IGNORE INTO formation_courses (slug, module_slug, title, description, audience, required_score, estimated_minutes, sort_order) VALUES
('bien-demarrer-finea', 'presentation_finea', 'Bien démarrer avec FINEA', 'Comprendre le portail, les modules, les notifications et les bons réflexes.', 'utilisateur', 75, 20, 10),
('centre-tickets', 'ticketing', 'Utiliser le centre de tickets', 'Créer, suivre et relancer une demande de service dans FINEA.', 'utilisateur', 75, 25, 20),
('notifications-popup', 'notifications', 'Comprendre les notifications popup', 'Lire, répondre et valider correctement les notifications interactives.', 'utilisateur', 75, 15, 30),
('maitriser-feb', 'decaissement', 'Maîtriser les FEB', 'Créer et suivre une fiche de décaissement sans erreur de justificatif.', 'utilisateur', 80, 35, 40);

INSERT IGNORE INTO formation_course_permissions (course_id, permission_key)
SELECT id, 'acces_portail' FROM formation_courses WHERE slug = 'bien-demarrer-finea';
INSERT IGNORE INTO formation_course_permissions (course_id, permission_key)
SELECT id, 'acces_ticketing' FROM formation_courses WHERE slug = 'centre-tickets';
INSERT IGNORE INTO formation_course_permissions (course_id, permission_key)
SELECT id, 'acces_portail' FROM formation_courses WHERE slug = 'notifications-popup';
INSERT IGNORE INTO formation_course_permissions (course_id, permission_key)
SELECT id, 'acces_decaissement' FROM formation_courses WHERE slug = 'maitriser-feb';


-- Leçons de démonstration
INSERT IGNORE INTO formation_lessons (course_id, slug, title, lesson_type, estimated_minutes, sort_order)
SELECT id, 'introduction', 'Comprendre l’objectif du parcours', 'lecture', 5, 10 FROM formation_courses WHERE slug = 'bien-demarrer-finea';
INSERT IGNORE INTO formation_lessons (course_id, slug, title, lesson_type, estimated_minutes, sort_order)
SELECT id, 'bons-reflexes', 'Les bons réflexes dans le portail', 'interactive', 10, 20 FROM formation_courses WHERE slug = 'bien-demarrer-finea';

INSERT IGNORE INTO formation_lessons (course_id, slug, title, lesson_type, estimated_minutes, sort_order)
SELECT id, 'creer-ticket', 'Créer un ticket exploitable', 'lecture', 8, 10 FROM formation_courses WHERE slug = 'centre-tickets';
INSERT IGNORE INTO formation_lessons (course_id, slug, title, lesson_type, estimated_minutes, sort_order)
SELECT id, 'suivi-relance', 'Suivre la lecture et décider de relancer', 'interactive', 8, 20 FROM formation_courses WHERE slug = 'centre-tickets';

INSERT IGNORE INTO formation_lessons (course_id, slug, title, lesson_type, estimated_minutes, sort_order)
SELECT id, 'popup-qcm', 'Comprendre une popup avec QCM', 'interactive', 8, 10 FROM formation_courses WHERE slug = 'notifications-popup';

INSERT IGNORE INTO formation_lessons (course_id, slug, title, lesson_type, estimated_minutes, sort_order)
SELECT id, 'type-feb', 'Choisir le bon type de FEB', 'lecture', 10, 10 FROM formation_courses WHERE slug = 'maitriser-feb';
INSERT IGNORE INTO formation_lessons (course_id, slug, title, lesson_type, estimated_minutes, sort_order)
SELECT id, 'justificatifs-feb', 'Joindre les bons justificatifs', 'interactive', 10, 20 FROM formation_courses WHERE slug = 'maitriser-feb';

-- Blocs de démonstration
INSERT IGNORE INTO formation_lesson_blocks (lesson_id, block_type, title, content, sort_order)
SELECT l.id, 'info', 'Principe', 'FINEA Academy transforme les guides en parcours évalués. Chaque utilisateur voit les formations liées à ses permissions.', 10
FROM formation_lessons l INNER JOIN formation_courses c ON c.id = l.course_id WHERE c.slug='bien-demarrer-finea' AND l.slug='introduction';

INSERT IGNORE INTO formation_lesson_blocks (lesson_id, block_type, title, content, sort_order)
SELECT l.id, 'step', 'Réflexe utilisateur', 'Avant de cliquer sur “J’ai compris”, vérifiez si une validation QCM est demandée. Si oui, validez d’abord la réponse.', 10
FROM formation_lessons l INNER JOIN formation_courses c ON c.id = l.course_id WHERE c.slug='notifications-popup' AND l.slug='popup-qcm';

INSERT IGNORE INTO formation_lesson_blocks (lesson_id, block_type, title, content, sort_order)
SELECT l.id, 'warning', 'Erreur à éviter', 'Un ticket sans destinataire clair, sans contexte ou sans pièce jointe utile augmente les relances inutiles.', 10
FROM formation_lessons l INNER JOIN formation_courses c ON c.id = l.course_id WHERE c.slug='centre-tickets' AND l.slug='creer-ticket';

INSERT IGNORE INTO formation_lesson_blocks (lesson_id, block_type, title, content, sort_order)
SELECT l.id, 'warning', 'FEB externe', 'Pour une demande externe, le justificatif doit correspondre au scénario : avance provisoire avec justificatif image ou décaissement unique sur facture avec PDF.', 10
FROM formation_lessons l INNER JOIN formation_courses c ON c.id = l.course_id WHERE c.slug='maitriser-feb' AND l.slug='justificatifs-feb';

-- QCM finaux de démonstration
INSERT IGNORE INTO formation_quizzes (course_id, title, min_score, is_final)
SELECT id, 'Validation - Bien démarrer avec FINEA', required_score, 1 FROM formation_courses WHERE slug='bien-demarrer-finea';
INSERT IGNORE INTO formation_quizzes (course_id, title, min_score, is_final)
SELECT id, 'Validation - Centre de tickets', required_score, 1 FROM formation_courses WHERE slug='centre-tickets';
INSERT IGNORE INTO formation_quizzes (course_id, title, min_score, is_final)
SELECT id, 'Validation - Notifications popup', required_score, 1 FROM formation_courses WHERE slug='notifications-popup';
INSERT IGNORE INTO formation_quizzes (course_id, title, min_score, is_final)
SELECT id, 'Validation - FEB', required_score, 1 FROM formation_courses WHERE slug='maitriser-feb';

-- Questions + réponses : notifications
INSERT IGNORE INTO formation_quiz_questions (quiz_id, question_type, question_text, explanation, points, sort_order)
SELECT q.id, 'single', 'Que doit faire l’utilisateur avant de cliquer sur un bouton d’action après un QCM ?', 'Il doit valider sa réponse au QCM pour déclencher la correction.', 1, 10
FROM formation_quizzes q INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='notifications-popup';

INSERT IGNORE INTO formation_quiz_answers (question_id, answer_text, is_correct, sort_order)
SELECT qq.id, 'Cliquer directement sur “J’ai compris”.', 0, 10 FROM formation_quiz_questions qq INNER JOIN formation_quizzes q ON q.id=qq.quiz_id INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='notifications-popup' AND qq.sort_order=10;
INSERT IGNORE INTO formation_quiz_answers (question_id, answer_text, is_correct, sort_order)
SELECT qq.id, 'Choisir une réponse puis cliquer sur “Valider ma réponse”.', 1, 20 FROM formation_quiz_questions qq INNER JOIN formation_quizzes q ON q.id=qq.quiz_id INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='notifications-popup' AND qq.sort_order=10;

-- Questions + réponses : tickets
INSERT IGNORE INTO formation_quiz_questions (quiz_id, question_type, question_text, explanation, points, sort_order)
SELECT q.id, 'single', 'Quel ticket est le plus exploitable ?', 'Un ticket exploitable contient un objet clair, un contexte, un destinataire et les pièces utiles.', 1, 10
FROM formation_quizzes q INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='centre-tickets';

INSERT IGNORE INTO formation_quiz_answers (question_id, answer_text, is_correct, sort_order)
SELECT qq.id, '“Problème urgent” sans détail.', 0, 10 FROM formation_quiz_questions qq INNER JOIN formation_quizzes q ON q.id=qq.quiz_id INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='centre-tickets' AND qq.sort_order=10;
INSERT IGNORE INTO formation_quiz_answers (question_id, answer_text, is_correct, sort_order)
SELECT qq.id, 'Objet clair, chantier, description, destinataire et pièce jointe si nécessaire.', 1, 20 FROM formation_quiz_questions qq INNER JOIN formation_quizzes q ON q.id=qq.quiz_id INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='centre-tickets' AND qq.sort_order=10;

-- Questions + réponses : FEB
INSERT IGNORE INTO formation_quiz_questions (quiz_id, question_type, question_text, explanation, points, sort_order)
SELECT q.id, 'single', 'Pour une demande externe sur facture, quelle pièce est attendue ?', 'Une demande externe sur facture doit s’appuyer sur une facture PDF.', 1, 10
FROM formation_quizzes q INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='maitriser-feb';

INSERT IGNORE INTO formation_quiz_answers (question_id, answer_text, is_correct, sort_order)
SELECT qq.id, 'Une facture PDF.', 1, 10 FROM formation_quiz_questions qq INNER JOIN formation_quizzes q ON q.id=qq.quiz_id INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='maitriser-feb' AND qq.sort_order=10;
INSERT IGNORE INTO formation_quiz_answers (question_id, answer_text, is_correct, sort_order)
SELECT qq.id, 'Aucune pièce si le montant est faible.', 0, 20 FROM formation_quiz_questions qq INNER JOIN formation_quizzes q ON q.id=qq.quiz_id INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='maitriser-feb' AND qq.sort_order=10;

-- Questions + réponses : démarrage
INSERT IGNORE INTO formation_quiz_questions (quiz_id, question_type, question_text, explanation, points, sort_order)
SELECT q.id, 'single', 'Sur quoi doit se baser le score global de maîtrise FINEA ?', 'Le score global doit tenir compte uniquement des parcours liés aux droits de l’utilisateur.', 1, 10
FROM formation_quizzes q INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='bien-demarrer-finea';

INSERT IGNORE INTO formation_quiz_answers (question_id, answer_text, is_correct, sort_order)
SELECT qq.id, 'Tous les modules FINEA, même ceux hors métier.', 0, 10 FROM formation_quiz_questions qq INNER JOIN formation_quizzes q ON q.id=qq.quiz_id INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='bien-demarrer-finea' AND qq.sort_order=10;
INSERT IGNORE INTO formation_quiz_answers (question_id, answer_text, is_correct, sort_order)
SELECT qq.id, 'Uniquement les modules auxquels l’utilisateur a accès.', 1, 20 FROM formation_quiz_questions qq INNER JOIN formation_quizzes q ON q.id=qq.quiz_id INNER JOIN formation_courses c ON c.id=q.course_id WHERE c.slug='bien-demarrer-finea' AND qq.sort_order=10;

-- Règles obligatoires de démonstration
INSERT IGNORE INTO formation_requirements (course_id, module_slug, permission_key, requirement_type, blocking_action, required_score, deadline_days)
SELECT id, module_slug, 'acces_decaissement', 'blocking', 'create_feb', required_score, 7 FROM formation_courses WHERE slug='maitriser-feb';
INSERT IGNORE INTO formation_requirements (course_id, module_slug, permission_key, requirement_type, blocking_action, required_score, deadline_days)
SELECT id, module_slug, 'acces_tickets', 'mandatory', 'create_ticket', required_score, 7 FROM formation_courses WHERE slug='centre-tickets';
SQL;
    }

    public function visibleCourses(array $permissions, bool $isAdmin = false): array
    {
        if ($isAdmin) {
            return $this->pdo->query("SELECT c.* FROM formation_courses c WHERE c.is_active = 1 ORDER BY c.sort_order, c.title")->fetchAll();
        }

        $keys = array_keys(array_filter($permissions));
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $sql = "SELECT DISTINCT c.*
                FROM formation_courses c
                LEFT JOIN formation_course_permissions cp ON cp.course_id = c.id
                WHERE c.is_active = 1 AND (cp.permission_key IN ($placeholders) OR cp.permission_key IS NULL)
                ORDER BY c.sort_order, c.title";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($keys);
        return $stmt->fetchAll();
    }

    public function courseBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_courses WHERE slug = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lessonsForCourse(int $courseId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_lessons WHERE course_id = ? AND is_active = 1 ORDER BY sort_order, id');
        $stmt->execute([$courseId]);
        return $stmt->fetchAll();
    }

    public function lessonBlocks(int $lessonId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_lesson_blocks WHERE lesson_id = ? ORDER BY sort_order, id');
        $stmt->execute([$lessonId]);
        return $stmt->fetchAll();
    }

    public function finalQuizForCourse(int $courseId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_quizzes WHERE course_id = ? AND is_active = 1 ORDER BY is_final DESC, id DESC LIMIT 1');
        $stmt->execute([$courseId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }


    public function quizById(int $quizId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_quizzes WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$quizId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function quizQuestionsWithAnswers(int $quizId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_quiz_questions WHERE quiz_id = ? ORDER BY sort_order, id');
        $stmt->execute([$quizId]);
        $questions = $stmt->fetchAll();

        $answerStmt = $this->pdo->prepare('SELECT id, question_id, answer_text, is_correct, sort_order FROM formation_quiz_answers WHERE question_id = ? ORDER BY sort_order, id');
        foreach ($questions as &$question) {
            $answerStmt->execute([(int) $question['id']]);
            $question['answers'] = $answerStmt->fetchAll();
        }
        unset($question);

        return $questions;
    }

    public function markLessonCompleted(int $userId, int $courseId, int $lessonId, int $timeSpentSeconds = 0): void
    {
        $sql = "INSERT INTO formation_user_progress
                    (user_id, course_id, lesson_id, status, progress_percent, time_spent_seconds, last_seen_at, completed_at)
                VALUES (?, ?, ?, 'completed', 100, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    status = 'completed', progress_percent = 100,
                    time_spent_seconds = time_spent_seconds + VALUES(time_spent_seconds),
                    last_seen_at = NOW(), completed_at = COALESCE(completed_at, NOW())";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId, $courseId, $lessonId, max(0, $timeSpentSeconds)]);
    }

    public function saveAttempt(int $userId, int $courseId, int $quizId, float $score, float $earnedPoints, int $totalPoints, bool $passed, array $answers): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO formation_attempts
                (user_id, course_id, quiz_id, score, total_points, earned_points, passed, completed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$userId, $courseId, $quizId, $score, $totalPoints, $earnedPoints, $passed ? 1 : 0]);
            $attemptId = (int) $this->pdo->lastInsertId();

            $answerStmt = $this->pdo->prepare("INSERT INTO formation_attempt_answers
                (attempt_id, question_id, selected_answer_ids, ordering_payload, is_correct, earned_points)
                VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($answers as $row) {
                $answerStmt->execute([
                    $attemptId,
                    (int) $row['question_id'],
                    json_encode(array_values($row['selected_answer_ids'] ?? []), JSON_UNESCAPED_UNICODE),
                    isset($row['ordering_payload']) ? json_encode($row['ordering_payload'], JSON_UNESCAPED_UNICODE) : null,
                    !empty($row['is_correct']) ? 1 : 0,
                    (float) ($row['earned_points'] ?? 0),
                ]);
            }

            $status = $passed ? 'completed' : 'in_progress';
            $progress = $passed ? 100 : 50;
            $progressStmt = $this->pdo->prepare("INSERT INTO formation_user_progress
                    (user_id, course_id, lesson_id, status, progress_percent, best_score, last_seen_at, completed_at)
                VALUES (?, ?, NULL, ?, ?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE
                    status = IF(VALUES(best_score) >= best_score, VALUES(status), status),
                    progress_percent = GREATEST(progress_percent, VALUES(progress_percent)),
                    best_score = GREATEST(best_score, VALUES(best_score)),
                    last_seen_at = NOW(),
                    completed_at = IF(VALUES(best_score) >= 75, COALESCE(completed_at, NOW()), completed_at)");
            $progressStmt->execute([$userId, $courseId, $status, $progress, $score, $passed ? date('Y-m-d H:i:s') : null]);

            $this->pdo->commit();
            return $attemptId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function userProgressMap(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_user_progress WHERE user_id = ? AND lesson_id IS NULL');
        $stmt->execute([$userId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(int) $row['course_id']] = $row;
        }
        return $map;
    }

    public function existingCertificate(int $userId, int $courseId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_certificates WHERE user_id = ? AND course_id = ? ORDER BY issued_at DESC LIMIT 1');
        $stmt->execute([$userId, $courseId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createCertificate(int $userId, int $courseId, float $score, string $level): array
    {
        $existing = $this->existingCertificate($userId, $courseId);
        if ($existing) {
            return $existing;
        }

        $ref = 'FINEA-ACAD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $verification = hash('sha256', $ref . '|' . $userId . '|' . $courseId . '|' . microtime(true));
        $stmt = $this->pdo->prepare('INSERT INTO formation_certificates
            (user_id, course_id, certificate_ref, score, level_label, verification_code)
            VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $courseId, $ref, $score, $level, $verification]);
        return $this->existingCertificate($userId, $courseId) ?? throw new RuntimeException('Certificat non créé.');
    }

    public function masteryStats(int $userId, array $permissions, bool $isAdmin = false): array
    {
        $courses = $this->visibleCourses($permissions, $isAdmin);
        $progress = $this->userProgressMap($userId);
        $total = count($courses);
        $sum = 0.0;
        foreach ($courses as $course) {
            $sum += (float) ($progress[(int) $course['id']]['best_score'] ?? 0);
        }
        $score = $total > 0 ? round($sum / $total, 2) : 0.0;
        return ['score' => $score, 'total_courses' => $total, 'level' => self::levelForScore($score)];
    }


    public function adminStats(): array
    {
        return [
            'courses' => (int) $this->pdo->query("SELECT COUNT(*) FROM formation_courses")->fetchColumn(),
            'active_courses' => (int) $this->pdo->query("SELECT COUNT(*) FROM formation_courses WHERE is_active = 1")->fetchColumn(),
            'certificates' => (int) $this->pdo->query("SELECT COUNT(*) FROM formation_certificates")->fetchColumn(),
            'attempts' => (int) $this->pdo->query("SELECT COUNT(*) FROM formation_attempts")->fetchColumn(),
            'avg_score' => (float) ($this->pdo->query("SELECT COALESCE(ROUND(AVG(score),2),0) FROM formation_attempts")->fetchColumn() ?: 0),
        ];
    }

    public function adminCourses(): array
    {
        $sql = "SELECT c.*, COUNT(DISTINCT l.id) AS lessons_count, COUNT(DISTINCT q.id) AS quizzes_count,
                       COUNT(DISTINCT cp.id) AS permissions_count, COUNT(DISTINCT r.id) AS requirements_count
                FROM formation_courses c
                LEFT JOIN formation_lessons l ON l.course_id = c.id
                LEFT JOIN formation_quizzes q ON q.course_id = c.id
                LEFT JOIN formation_course_permissions cp ON cp.course_id = c.id
                LEFT JOIN formation_requirements r ON r.course_id = c.id AND r.is_active = 1
                GROUP BY c.id
                ORDER BY c.sort_order, c.title";
        return $this->pdo->query($sql)->fetchAll();
    }

    public function courseById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_courses WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function permissionsForCourse(int $courseId): array
    {
        $stmt = $this->pdo->prepare('SELECT permission_key FROM formation_course_permissions WHERE course_id = ? ORDER BY permission_key');
        $stmt->execute([$courseId]);
        return array_column($stmt->fetchAll(), 'permission_key');
    }

    public function requirementsForCourse(int $courseId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM formation_requirements WHERE course_id = ? ORDER BY id DESC');
        $stmt->execute([$courseId]);
        return $stmt->fetchAll();
    }

    public function saveCourse(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $slug = trim((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = $this->slugify((string) ($data['title'] ?? 'cours'));
        }
        $values = [
            'slug' => $slug,
            'module_slug' => trim((string) ($data['module_slug'] ?? 'general')) ?: 'general',
            'title' => trim((string) ($data['title'] ?? 'Nouveau parcours')) ?: 'Nouveau parcours',
            'description' => trim((string) ($data['description'] ?? '')),
            'audience' => in_array(($data['audience'] ?? 'utilisateur'), ['utilisateur','technique','mixte'], true) ? $data['audience'] : 'utilisateur',
            'required_score' => max(1, min(100, (int) ($data['required_score'] ?? 75))),
            'estimated_minutes' => max(1, (int) ($data['estimated_minutes'] ?? 15)),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
        if ($id > 0) {
            $stmt = $this->pdo->prepare("UPDATE formation_courses SET slug=:slug, module_slug=:module_slug, title=:title, description=:description, audience=:audience, required_score=:required_score, estimated_minutes=:estimated_minutes, is_active=:is_active, sort_order=:sort_order WHERE id=:id");
            $values['id'] = $id;
            $stmt->execute($values);
            return $id;
        }
        $stmt = $this->pdo->prepare("INSERT INTO formation_courses (slug, module_slug, title, description, audience, required_score, estimated_minutes, is_active, sort_order) VALUES (:slug, :module_slug, :title, :description, :audience, :required_score, :estimated_minutes, :is_active, :sort_order)");
        $stmt->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function setCoursePermissions(int $courseId, array $permissions): void
    {
        $this->pdo->prepare('DELETE FROM formation_course_permissions WHERE course_id = ?')->execute([$courseId]);
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO formation_course_permissions (course_id, permission_key) VALUES (?, ?)');
        foreach ($permissions as $permission) {
            $permission = trim((string) $permission);
            if ($permission !== '') {
                $stmt->execute([$courseId, $permission]);
            }
        }
    }

    public function saveRequirement(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $values = [
            'course_id' => (int) ($data['course_id'] ?? 0),
            'module_slug' => trim((string) ($data['module_slug'] ?? 'general')) ?: 'general',
            'permission_key' => trim((string) ($data['permission_key'] ?? '')) ?: null,
            'requirement_type' => in_array(($data['requirement_type'] ?? 'recommended'), ['recommended','mandatory','blocking'], true) ? $data['requirement_type'] : 'recommended',
            'blocking_action' => trim((string) ($data['blocking_action'] ?? '')) ?: null,
            'required_score' => max(1, min(100, (int) ($data['required_score'] ?? 75))),
            'deadline_days' => ($data['deadline_days'] ?? '') === '' ? null : max(1, (int) $data['deadline_days']),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
        if ($values['course_id'] <= 0) {
            throw new RuntimeException('Cours obligatoire pour la règle.');
        }
        if ($id > 0) {
            $values['id'] = $id;
            $stmt = $this->pdo->prepare("UPDATE formation_requirements SET course_id=:course_id, module_slug=:module_slug, permission_key=:permission_key, requirement_type=:requirement_type, blocking_action=:blocking_action, required_score=:required_score, deadline_days=:deadline_days, is_active=:is_active WHERE id=:id");
            $stmt->execute($values);
            return $id;
        }
        $stmt = $this->pdo->prepare("INSERT INTO formation_requirements (course_id, module_slug, permission_key, requirement_type, blocking_action, required_score, deadline_days, is_active) VALUES (:course_id, :module_slug, :permission_key, :requirement_type, :blocking_action, :required_score, :deadline_days, :is_active)");
        $stmt->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function adminAttempts(int $limit = 40): array
    {
        $stmt = $this->pdo->prepare("SELECT a.*, c.title AS course_title, c.module_slug FROM formation_attempts a INNER JOIN formation_courses c ON c.id = a.course_id ORDER BY a.completed_at DESC, a.id DESC LIMIT ?");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function adminCertificates(int $limit = 40): array
    {
        $stmt = $this->pdo->prepare("SELECT cert.*, c.title AS course_title, c.module_slug FROM formation_certificates cert INNER JOIN formation_courses c ON c.id = cert.course_id ORDER BY cert.issued_at DESC LIMIT ?");
        $stmt->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function saveLesson(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $values = [
            'course_id' => (int) ($data['course_id'] ?? 0),
            'slug' => trim((string) ($data['slug'] ?? '')) ?: $this->slugify((string) ($data['title'] ?? 'lecon')),
            'title' => trim((string) ($data['title'] ?? 'Nouvelle leçon')) ?: 'Nouvelle leçon',
            'lesson_type' => in_array(($data['lesson_type'] ?? 'lecture'), ['lecture','video','interactive','quiz'], true) ? $data['lesson_type'] : 'lecture',
            'markdown_path' => trim((string) ($data['markdown_path'] ?? '')) ?: null,
            'video_url' => trim((string) ($data['video_url'] ?? '')) ?: null,
            'estimated_minutes' => max(1, (int) ($data['estimated_minutes'] ?? 5)),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];
        if ($values['course_id'] <= 0) throw new RuntimeException('Cours obligatoire.');
        if ($id > 0) {
            $values['id'] = $id;
            $stmt = $this->pdo->prepare("UPDATE formation_lessons SET course_id=:course_id, slug=:slug, title=:title, lesson_type=:lesson_type, markdown_path=:markdown_path, video_url=:video_url, estimated_minutes=:estimated_minutes, sort_order=:sort_order, is_active=:is_active WHERE id=:id");
            $stmt->execute($values);
            return $id;
        }
        $stmt = $this->pdo->prepare("INSERT INTO formation_lessons (course_id, slug, title, lesson_type, markdown_path, video_url, estimated_minutes, sort_order, is_active) VALUES (:course_id, :slug, :title, :lesson_type, :markdown_path, :video_url, :estimated_minutes, :sort_order, :is_active)");
        $stmt->execute($values);
        return (int) $this->pdo->lastInsertId();
    }

    public function saveLessonBlock(array $data): int
    {
        $lessonId = (int) ($data['lesson_id'] ?? 0);
        if ($lessonId <= 0) {
            throw new RuntimeException('Leçon obligatoire pour le bloc.');
        }
        $blockType = (string) ($data['block_type'] ?? 'image');
        if (!in_array($blockType, ['text','info','warning','image','video','step','exercise'], true)) {
            $blockType = 'image';
        }
        $stmt = $this->pdo->prepare("INSERT INTO formation_lesson_blocks (lesson_id, block_type, title, content, asset_path, payload_json, sort_order) VALUES (:lesson_id, :block_type, :title, :content, :asset_path, :payload_json, :sort_order)");
        $stmt->execute([
            'lesson_id' => $lessonId,
            'block_type' => $blockType,
            'title' => trim((string) ($data['title'] ?? '')) ?: null,
            'content' => trim((string) ($data['content'] ?? '')) ?: null,
            'asset_path' => trim((string) ($data['asset_path'] ?? '')) ?: null,
            'payload_json' => isset($data['payload_json']) && $data['payload_json'] !== '' ? (string) $data['payload_json'] : null,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function adminExportRows(string $type): array
    {
        if ($type === 'attempts') {
            return $this->adminAttempts(5000);
        }
        if ($type === 'certificates') {
            return $this->adminCertificates(5000);
        }
        return $this->adminCourses();
    }

    private function slugify(string $text): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        $text = preg_replace('~[^\\pL\\d]+~u', '-', $text) ?: $text;
        $text = trim($text, '-');
        $text = strtolower($text);
        $text = preg_replace('~[^-a-z0-9]+~', '', $text) ?: 'item';
        return $text ?: 'item';
    }

    public static function levelForScore(float $score): string
    {
        if ($score >= 90) return 'Référent FINEA';
        if ($score >= 75) return 'Confirmé';
        if ($score >= 60) return 'Opérationnel';
        if ($score >= 40) return 'En apprentissage';
        return 'Débutant';
    }
}
