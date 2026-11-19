<?php

require_once __DIR__ . '/../repositories/GamificationRepository.php';

if (!class_exists('GamificationService')) {
    class GamificationService
    {
        private GamificationRepository $repo;

        public function __construct(GamificationRepository $repo)
        {
            $this->repo = $repo;
        }

        public function trigger(string $eventKey, int $userId, ?int $referenceId = null, ?string $description = null): array
        {
            $eventKey = trim((string) $eventKey);
            if ($eventKey === '' || $userId <= 0) {
                return [
                    'awarded' => false,
                    'reason' => 'invalid_input',
                ];
            }

            $this->repo->ensureSchema();

            $rule = $this->repo->getActiveRule($eventKey);
            if (!$rule) {
                return [
                    'awarded' => false,
                    'reason' => 'no_rule',
                ];
            }

            $points = (int) ($rule['points'] ?? 0);
            if ($points === 0) {
                return [
                    'awarded' => false,
                    'reason' => 'zero_points',
                ];
            }

            $maxPerDay = $rule['max_per_day'] === null ? null : (int) $rule['max_per_day'];
            if ($maxPerDay !== null && $maxPerDay > 0) {
                $count = $this->repo->countEarnedToday($userId, $eventKey);
                if ($count >= $maxPerDay) {
                    return [
                        'awarded' => false,
                        'reason' => 'max_per_day',
                        'max_per_day' => $maxPerDay,
                    ];
                }
            }

            $description = $description !== null ? trim($description) : '';
            if ($description === '') {
                $description = 'Points attribués pour ' . $eventKey;
            }

            $logId = $this->repo->addPointsLog($userId, $points, $eventKey, $referenceId, $description);
            $user = $this->repo->incrementUserPoints($userId, $points);

            $newBadges = $this->checkAndAwardBadges($userId);
            $challengeUpdate = $this->applyChallenges($userId, $eventKey);

            return [
                'awarded' => true,
                'log_id' => $logId,
                'points' => $points,
                'user' => [
                    'user_id' => (int) ($user['user_id'] ?? $userId),
                    'total_points' => (int) ($user['total_points'] ?? 0),
                    'level' => (int) ($user['level'] ?? 1),
                    'experience' => (int) ($user['experience'] ?? 0),
                ],
                'new_badges' => $newBadges,
                'challenges' => $challengeUpdate,
            ];
        }

        public function getUserDashboard(int $userId, string $period = 'monthly'): array
        {
            $this->repo->ensureSchema();

            $user = $this->repo->getGamUser($userId);
            $badges = $this->repo->listUserBadges($userId);
            $challenges = $this->repo->listUserChallenges($userId);
            $rank = $this->repo->getUserRank($period, $userId);
            $periodPoints = $this->repo->getUserPointsForPeriod($userId, $period);

            return [
                'points' => (int) ($user['total_points'] ?? 0),
                'period_points' => (int) $periodPoints,
                'level' => (int) ($user['level'] ?? 1),
                'badges' => $badges,
                'rank' => (int) $rank,
                'challenges' => $challenges,
            ];
        }

        private function checkAndAwardBadges(int $userId): array
        {
            $badges = $this->repo->listBadges();
            if (!$badges) {
                return [];
            }

            $user = $this->repo->getGamUser($userId);
            $totalPoints = (int) ($user['total_points'] ?? 0);
            $actionCount = count($this->repo->listPointsHistory($userId, 1000));

            $newBadges = [];

            foreach ($badges as $badge) {
                $badgeId = (int) ($badge['id'] ?? 0);
                if ($badgeId <= 0) {
                    continue;
                }

                if ($this->repo->hasBadge($userId, $badgeId)) {
                    continue;
                }

                $type = (string) ($badge['condition_type'] ?? '');
                $value = (int) ($badge['condition_value'] ?? 0);

                $earned = false;

                if ($type === 'points') {
                    $earned = $totalPoints >= $value;
                } elseif ($type === 'action_count') {
                    $earned = $actionCount >= $value;
                }

                if ($earned) {
                    $this->repo->awardBadge($userId, $badgeId);
                    $newBadges[] = [
                        'id' => $badgeId,
                        'name' => (string) ($badge['name'] ?? ''),
                        'description' => (string) ($badge['description'] ?? ''),
                        'icon' => (string) ($badge['icon'] ?? ''),
                    ];
                }
            }

            return $newBadges;
        }

        private function applyChallenges(int $userId, string $eventKey): array
        {
            $challenges = $this->repo->listActiveChallenges();
            if (!$challenges) {
                return [
                    'updated' => [],
                    'completed' => [],
                ];
            }

            $updated = [];
            $completed = [];

            foreach ($challenges as $challenge) {
                $challengeId = (int) ($challenge['id'] ?? 0);
                $type = (string) ($challenge['type'] ?? '');
                if ($challengeId <= 0 || !$this->matchesChallengeType($eventKey, $type)) {
                    continue;
                }

                $row = $this->repo->incrementUserChallengeProgress($userId, $challengeId, 1);
                $progress = (int) ($row['progress'] ?? 0);
                $isCompleted = !empty($row['completed']);
                $target = (int) ($challenge['target'] ?? 0);

                $updated[] = [
                    'challenge_id' => $challengeId,
                    'progress' => $progress,
                    'target' => $target,
                ];

                if (!$isCompleted && $target > 0 && $progress >= $target) {
                    $this->repo->markChallengeCompleted($userId, $challengeId);
                    $rewardPoints = (int) ($challenge['reward_points'] ?? 0);
                    if ($rewardPoints !== 0) {
                        $this->repo->addPointsLog(
                            $userId,
                            $rewardPoints,
                            'challenge_completed',
                            $challengeId,
                            'Défi complété : ' . (string) ($challenge['title'] ?? ('#' . $challengeId))
                        );
                        $this->repo->incrementUserPoints($userId, $rewardPoints);
                    }

                    $completed[] = [
                        'challenge_id' => $challengeId,
                        'reward_points' => $rewardPoints,
                    ];
                }
            }

            return [
                'updated' => $updated,
                'completed' => $completed,
            ];
        }

        private function matchesChallengeType(string $eventKey, string $type): bool
        {
            $eventKey = strtolower(trim($eventKey));
            $type = strtolower(trim($type));

            if ($eventKey === '' || $type === '') {
                return false;
            }

            if ($eventKey === $type) {
                return true;
            }

            return str_starts_with($eventKey, $type . '_');
        }
    }
}
