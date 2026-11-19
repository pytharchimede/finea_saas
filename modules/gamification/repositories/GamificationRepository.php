<?php

if (!class_exists('GamificationRepository')) {
    class GamificationRepository
    {
        private PDO $pdo;

        public function __construct(PDO $pdo)
        {
            $this->pdo = $pdo;
        }

        public function ensureSchema(): void
        {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gam_users (
                user_id INT PRIMARY KEY,
                total_points INT NOT NULL DEFAULT 0,
                level INT NOT NULL DEFAULT 1,
                experience INT NOT NULL DEFAULT 0,
                last_activity DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gam_points_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                points INT NOT NULL,
                source VARCHAR(100) NOT NULL,
                reference_id INT NULL,
                description TEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_user_source_created (user_id, source, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gam_badges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                icon VARCHAR(255) NULL,
                condition_type VARCHAR(50) NOT NULL,
                condition_value INT NOT NULL,
                INDEX idx_condition (condition_type, condition_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gam_user_badges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                badge_id INT NOT NULL,
                awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_badge (user_id, badge_id),
                INDEX idx_user_awarded (user_id, awarded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gam_challenges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(150) NOT NULL,
                description TEXT NULL,
                type VARCHAR(50) NOT NULL,
                target INT NOT NULL,
                reward_points INT NOT NULL,
                start_date DATE NULL,
                end_date DATE NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                INDEX idx_active_window (active, start_date, end_date),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gam_user_challenges (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                challenge_id INT NOT NULL,
                progress INT NOT NULL DEFAULT 0,
                completed TINYINT(1) NOT NULL DEFAULT 0,
                completed_at DATETIME NULL,
                UNIQUE KEY uq_user_challenge (user_id, challenge_id),
                INDEX idx_user_completed (user_id, completed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gam_leaderboard_cache (
                user_id INT NOT NULL,
                period VARCHAR(20) NOT NULL,
                points INT NOT NULL,
                rank_position INT NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, period),
                INDEX idx_period_rank (period, rank_position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS gam_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_key VARCHAR(100) NOT NULL,
                points INT NOT NULL,
                max_per_day INT DEFAULT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uq_event_key (event_key),
                INDEX idx_active (active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        public function getActiveRule(string $eventKey): ?array
        {
            $stmt = $this->pdo->prepare('SELECT * FROM gam_rules WHERE event_key = :event_key AND active = 1 LIMIT 1');
            $stmt->execute(['event_key' => $eventKey]);
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);

            return $rule ?: null;
        }

        public function countEarnedToday(int $userId, string $eventKey): int
        {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM gam_points_log WHERE user_id = :user_id AND source = :source AND created_at >= CURDATE()');
            $stmt->execute([
                'user_id' => $userId,
                'source' => $eventKey,
            ]);

            return (int) $stmt->fetchColumn();
        }

        public function ensureUser(int $userId): void
        {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO gam_users (user_id, total_points, level, experience) VALUES (:user_id, 0, 1, 0)');
            $stmt->execute(['user_id' => $userId]);
        }

        public function addPointsLog(int $userId, int $points, string $eventKey, ?int $referenceId, ?string $description): int
        {
            $stmt = $this->pdo->prepare('INSERT INTO gam_points_log (user_id, points, source, reference_id, description) VALUES (:user_id, :points, :source, :reference_id, :description)');
            $stmt->execute([
                'user_id' => $userId,
                'points' => $points,
                'source' => $eventKey,
                'reference_id' => $referenceId,
                'description' => $description,
            ]);

            return (int) $this->pdo->lastInsertId();
        }

        public function incrementUserPoints(int $userId, int $points): array
        {
            $this->ensureUser($userId);

            $stmt = $this->pdo->prepare('UPDATE gam_users
                SET total_points = total_points + :points,
                    experience = experience + :points,
                    last_activity = NOW()
                WHERE user_id = :user_id');
            $stmt->execute([
                'points' => $points,
                'user_id' => $userId,
            ]);

            $user = $this->getGamUser($userId);
            $totalPoints = (int) ($user['total_points'] ?? 0);
            $level = (int) floor($totalPoints / 1000) + 1;

            $stmt = $this->pdo->prepare('UPDATE gam_users SET level = :level WHERE user_id = :user_id');
            $stmt->execute([
                'level' => $level,
                'user_id' => $userId,
            ]);

            return $this->getGamUser($userId);
        }

        public function getGamUser(int $userId): array
        {
            $this->ensureUser($userId);
            $stmt = $this->pdo->prepare('SELECT * FROM gam_users WHERE user_id = :user_id LIMIT 1');
            $stmt->execute(['user_id' => $userId]);

            return (array) ($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        }

        public function listPointsHistory(int $userId, int $limit = 200): array
        {
            $limit = max(1, min(1000, $limit));
            $stmt = $this->pdo->prepare('SELECT id, user_id, points, source, reference_id, description, created_at FROM gam_points_log WHERE user_id = :user_id ORDER BY id DESC LIMIT ' . $limit);
            $stmt->execute(['user_id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        public function listBadges(): array
        {
            $stmt = $this->pdo->query('SELECT id, name, description, icon, condition_type, condition_value FROM gam_badges ORDER BY id ASC');
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        public function listUserBadges(int $userId): array
        {
            $stmt = $this->pdo->prepare('SELECT b.id, b.name, b.description, b.icon, ub.awarded_at
                FROM gam_user_badges ub
                INNER JOIN gam_badges b ON b.id = ub.badge_id
                WHERE ub.user_id = :user_id
                ORDER BY ub.awarded_at DESC');
            $stmt->execute(['user_id' => $userId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        public function hasBadge(int $userId, int $badgeId): bool
        {
            $stmt = $this->pdo->prepare('SELECT 1 FROM gam_user_badges WHERE user_id = :user_id AND badge_id = :badge_id LIMIT 1');
            $stmt->execute(['user_id' => $userId, 'badge_id' => $badgeId]);
            return (bool) $stmt->fetchColumn();
        }

        public function awardBadge(int $userId, int $badgeId): void
        {
            $stmt = $this->pdo->prepare('INSERT IGNORE INTO gam_user_badges (user_id, badge_id) VALUES (:user_id, :badge_id)');
            $stmt->execute(['user_id' => $userId, 'badge_id' => $badgeId]);
        }

        public function listActiveChallenges(): array
        {
            $stmt = $this->pdo->query("SELECT id, title, description, type, target, reward_points, start_date, end_date, active
                FROM gam_challenges
                WHERE active = 1
                  AND (start_date IS NULL OR start_date <= CURDATE())
                  AND (end_date IS NULL OR end_date >= CURDATE())
                ORDER BY id ASC");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        public function listUserChallenges(int $userId): array
        {
            $stmt = $this->pdo->prepare('SELECT c.id, c.title, c.description, c.type, c.target, c.reward_points, c.start_date, c.end_date, c.active,
                       uc.progress, uc.completed, uc.completed_at
                FROM gam_challenges c
                LEFT JOIN gam_user_challenges uc
                    ON uc.challenge_id = c.id AND uc.user_id = :user_id
                WHERE c.active = 1
                  AND (c.start_date IS NULL OR c.start_date <= CURDATE())
                  AND (c.end_date IS NULL OR c.end_date >= CURDATE())
                ORDER BY c.id ASC');
            $stmt->execute(['user_id' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as &$row) {
                $row['progress'] = (int) ($row['progress'] ?? 0);
                $row['completed'] = !empty($row['completed']);
            }

            return $rows;
        }

        public function incrementUserChallengeProgress(int $userId, int $challengeId, int $delta): array
        {
            $delta = max(0, $delta);
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare('INSERT INTO gam_user_challenges (user_id, challenge_id, progress, completed)
                    VALUES (:user_id, :challenge_id, :progress, 0)
                    ON DUPLICATE KEY UPDATE progress = progress + VALUES(progress)');
                $stmt->execute([
                    'user_id' => $userId,
                    'challenge_id' => $challengeId,
                    'progress' => $delta,
                ]);

                $stmt = $this->pdo->prepare('SELECT * FROM gam_user_challenges WHERE user_id = :user_id AND challenge_id = :challenge_id LIMIT 1');
                $stmt->execute(['user_id' => $userId, 'challenge_id' => $challengeId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

                $this->pdo->commit();
                return (array) $row;
            } catch (Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        }

        public function markChallengeCompleted(int $userId, int $challengeId): void
        {
            $stmt = $this->pdo->prepare('UPDATE gam_user_challenges SET completed = 1, completed_at = NOW() WHERE user_id = :user_id AND challenge_id = :challenge_id');
            $stmt->execute(['user_id' => $userId, 'challenge_id' => $challengeId]);
        }

        public function getPeriodStart(string $period): string
        {
            $period = strtolower(trim($period));
            if ($period === 'daily') {
                return date('Y-m-d 00:00:00');
            }
            if ($period === 'weekly') {
                $day = (int) date('N');
                $monday = new DateTimeImmutable('today');
                $monday = $monday->modify('-' . ($day - 1) . ' days')->setTime(0, 0, 0);
                return $monday->format('Y-m-d H:i:s');
            }
            if ($period === 'monthly') {
                $first = new DateTimeImmutable(date('Y-m-01 00:00:00'));
                return $first->format('Y-m-d H:i:s');
            }

            return date('Y-m-d 00:00:00');
        }

        public function getUserPointsForPeriod(int $userId, string $period): int
        {
            $start = $this->getPeriodStart($period);
            $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM gam_points_log WHERE user_id = :user_id AND created_at >= :start');
            $stmt->execute(['user_id' => $userId, 'start' => $start]);

            return (int) $stmt->fetchColumn();
        }

        public function getLeaderboard(string $period, int $limit = 20): array
        {
            $limit = max(1, min(200, $limit));
            $start = $this->getPeriodStart($period);

            $sql = "
                SELECT t.user_id,
                       t.points,
                       u.prenom,
                       u.nom
                FROM (
                    SELECT user_id, COALESCE(SUM(points), 0) AS points
                    FROM gam_points_log
                    WHERE created_at >= :start
                    GROUP BY user_id
                ) t
                LEFT JOIN user_devis_banamur u ON u.id = t.user_id
                ORDER BY t.points DESC
                LIMIT {$limit}
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['start' => $start]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $rank = 0;
            foreach ($rows as &$row) {
                $rank += 1;
                $row['rank'] = $rank;
                $row['points'] = (int) ($row['points'] ?? 0);
            }

            return $rows;
        }

        public function getUserRank(string $period, int $userId): int
        {
            $start = $this->getPeriodStart($period);
            $userPoints = $this->getUserPointsForPeriod($userId, $period);

            $sql = "
                SELECT 1 + COUNT(*) AS rank_position
                FROM (
                    SELECT user_id, SUM(points) AS pts
                    FROM gam_points_log
                    WHERE created_at >= :start
                    GROUP BY user_id
                    HAVING pts > :user_pts
                ) t
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'start' => $start,
                'user_pts' => $userPoints,
            ]);

            $rank = (int) $stmt->fetchColumn();
            return $rank > 0 ? $rank : 1;
        }
    }
}
