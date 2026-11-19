<?php

require_once __DIR__ . '/../repositories/AoAlertPreferenceRepository.php';
require_once __DIR__ . '/AoScoringService.php';
require_once __DIR__ . '/../repositories/AoDigestRepository.php';
require_once __DIR__ . '/NotificationEmailChannelService.php';
require_once __DIR__ . '/../repositories/NotificationRepository.php';
require_once __DIR__ . '/../model/AccessManager.php';
require_once __DIR__ . '/../model/AuditTrail.php';

class AoAlertService
{
    private PDO $pdo;
    private AoAlertPreferenceRepository $preferences;
    private AoScoringService $scoring;
    private NotificationEmailChannelService $email;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->preferences = new AoAlertPreferenceRepository($pdo);
        $this->scoring = new AoScoringService();
        $this->email = new NotificationEmailChannelService();
        new NotificationRepository($pdo);
    }

    public function alertNewOpportunity(array $opportunity): array
    {
        $stats = ['system' => 0, 'email' => 0, 'skipped' => 0];
        if ((string) ($opportunity['statut_interne'] ?? 'nouveau') !== 'nouveau'
            || (string) ($opportunity['dg_decision'] ?? 'pending') !== 'pending'
            || !empty($opportunity['alerts_disabled'])) {
            $stats['skipped']++;
            return $stats;
        }
        $deadline = !empty($opportunity['date_limite']) ? strtotime((string) $opportunity['date_limite']) : false;
        if ($deadline === false || $deadline < strtotime(date('Y-m-d'))) {
            $stats['skipped']++;
            return $stats;
        }
        foreach ($this->preferences->listSubscribedUsers() as $user) {
            $personalScore = max(
                (int) ($opportunity['score_pertinence'] ?? 0),
                $this->scoring->score($opportunity, $this->scoring->splitKeywords((string) ($user['keywords'] ?? '')))
            );
            if ($personalScore < (int) ($user['min_score'] ?? 60) ||
                $this->scoring->containsExcludedKeyword($opportunity, (string) ($user['excluded_keywords'] ?? ''))) {
                $stats['skipped']++;
                continue;
            }

            if (!empty($user['alerts_system_enabled']) && $this->claimLog((int) $opportunity['id'], (int) $user['user_id'], 'system')) {
                try {
                    $this->createSystemNotification($opportunity, $user, $personalScore);
                    $this->completeLog((int) $opportunity['id'], (int) $user['user_id'], 'system', 'sent');
                    $stats['system']++;
                } catch (Throwable $exception) {
                    $this->completeLog((int) $opportunity['id'], (int) $user['user_id'], 'system', 'failed', $exception->getMessage());
                }
            }

            if (!empty($user['alerts_email_enabled']) && ($user['frequency'] ?? '') === 'instant' &&
                trim((string) ($user['mail_pro'] ?? '')) !== '' &&
                $this->claimLog((int) $opportunity['id'], (int) $user['user_id'], 'email')) {
                $sent = false;
                $error = '';
                try {
                    $sent = $this->sendEmail($opportunity, $user, $personalScore);
                    if (!$sent) {
                        $error = 'Le canal email a refuse ou echoue lors de l envoi.';
                    }
                } catch (Throwable $exception) {
                    $error = $exception->getMessage();
                }
                $this->completeLog((int) $opportunity['id'], (int) $user['user_id'], 'email', $sent ? 'sent' : 'failed', $error);
                $stats['email'] += $sent ? 1 : 0;
            }
        }
        return $stats;
    }

    public function sendPendingDigests(array $opportunities): array
    {
        $stats = ['daily_digest' => 0, 'weekly_digest' => 0, 'items' => 0, 'failed' => 0];
        if ($opportunities === []) {
            return $stats;
        }

        $collectiveRepository = new AoDigestRepository($this->pdo);
        $collectiveSettings = $collectiveRepository->settings();
        $collectiveIds = !empty($collectiveSettings['enabled']) ? array_column($collectiveRepository->recipients($collectiveSettings), 'user_id') : [];
        foreach ($this->preferences->listSubscribedUsers() as $user) {
            if (in_array((int) $user['user_id'], $collectiveIds, true)) continue;
            $frequency = (string) ($user['frequency'] ?? '');
            if (empty($user['alerts_email_enabled']) || !in_array($frequency, ['daily_digest', 'weekly_digest'], true)) {
                continue;
            }
            $lastSent = !empty($user['last_email_sent_at']) ? strtotime((string) $user['last_email_sent_at']) : false;
            if ($frequency === 'daily_digest' && $lastSent !== false && date('Y-m-d', $lastSent) === date('Y-m-d')) {
                continue;
            }
            if ($frequency === 'weekly_digest' && $lastSent !== false && $lastSent > time() - 7 * 86400) {
                continue;
            }

            $claimed = [];
            foreach ($opportunities as $opportunity) {
                if ((string) ($opportunity['statut_interne'] ?? 'nouveau') !== 'nouveau'
                    || (string) ($opportunity['dg_decision'] ?? 'pending') !== 'pending'
                    || !empty($opportunity['alerts_disabled'])) {
                    continue;
                }
                $personalScore = max(
                    (int) ($opportunity['score_pertinence'] ?? 0),
                    $this->scoring->score($opportunity, $this->scoring->splitKeywords((string) ($user['keywords'] ?? '')))
                );
                if ($personalScore < (int) ($user['min_score'] ?? 60)
                    || $this->scoring->containsExcludedKeyword($opportunity, (string) ($user['excluded_keywords'] ?? ''))) {
                    continue;
                }
                if ($this->claimLog((int) $opportunity['id'], (int) $user['user_id'], 'email')) {
                    $opportunity['personal_score'] = $personalScore;
                    $claimed[] = $opportunity;
                }
            }
            if ($claimed === []) {
                continue;
            }

            $sent = $this->sendDigestEmail($claimed, $user);
            foreach ($claimed as $opportunity) {
                $this->completeLog(
                    (int) $opportunity['id'],
                    (int) $user['user_id'],
                    'email',
                    $sent ? 'sent' : 'failed',
                    $sent ? '' : 'Echec de l envoi du resume AO.'
                );
            }
            if ($sent) {
                $this->preferences->markEmailSent((int) $user['user_id']);
                $stats[$frequency]++;
                $stats['items'] += count($claimed);
            } else {
                $stats['failed']++;
            }
        }
        return $stats;
    }

    private function createSystemNotification(array $ao, array $user, int $score): void
    {
        $existing = $this->pdo->prepare(
            "SELECT id FROM system_notifications
             WHERE event_key = 'ao_opportunity_detected'
               AND recipient_user_id = :user_id
               AND payload_json LIKE :ao_marker
             LIMIT 1"
        );
        $existing->execute([
            'user_id' => (int) $user['user_id'],
            'ao_marker' => '%\"ao_id\":' . (int) $ao['id'] . ',%',
        ]);
        if ($existing->fetchColumn()) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO system_notifications (
                created_at, event_key, sub_key, title, message, link_url,
                recipient_team, recipient_user_id, channels_json, payload_json
             ) VALUES (NOW(), 'ao_opportunity_detected', :sub_key, :title, :message, :link, NULL, :user_id, '[\"system\"]', :payload)"
        );
        $stmt->execute([
            'sub_key' => $score >= 80 ? 'high' : ($score >= 60 ? 'normal' : 'low'),
            'title' => 'Nouvel appel d offres detecte',
            'message' => mb_substr((string) $ao['titre'], 0, 220) . ' - score ' . $score . '%',
            'link' => 'devis/veille_ao_detail.php?id=' . (int) $ao['id'],
            'user_id' => (int) $user['user_id'],
            'payload' => json_encode(['ao_id' => (int) $ao['id'], 'score' => $score], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function sendEmail(array $ao, array $user, int $score): bool
    {
        $name = trim((string) ($user['prenom'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
        $url = rtrim(AccessManager::getApplicationBasePath(), '/') . '/devis/veille_ao_detail.php?id=' . (int) $ao['id'];
        $body = '<p>Bonjour ' . htmlspecialchars($name ?: 'collaborateur') . ',</p>'
            . '<p>Un nouvel appel d offres potentiellement pertinent pour BANAMUR a ete detecte.</p>'
            . '<p><strong>Titre :</strong><br>' . htmlspecialchars((string) $ao['titre']) . '</p>'
            . '<p><strong>Autorite contractante :</strong> ' . htmlspecialchars((string) ($ao['autorite_contractante'] ?? '-')) . '<br>'
            . '<strong>Date limite :</strong> ' . htmlspecialchars((string) ($ao['date_limite'] ?? '-')) . '<br>'
            . '<strong>Score :</strong> ' . $score . '%</p>';
        $html = $this->email->buildFineaEmail('Nouvel appel d offres pertinent detecte', $body, $url, ['action_label' => 'Voir l appel d offres']);
        return $this->email->send((string) $user['mail_pro'], $name, 'Nouvel appel d offres pertinent detecte - FINEA', $html, strip_tags($body), [
            'notification_user_id' => (int) $user['user_id'],
            'notification_preference_keys' => ['ao_opportunities'],
        ]);
    }

    private function sendDigestEmail(array $opportunities, array $user): bool
    {
        $name = trim((string) ($user['prenom'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
        $rows = '';
        foreach ($opportunities as $ao) {
            $url = rtrim(AccessManager::getApplicationBasePath(), '/') . '/devis/veille_ao_detail.php?id=' . (int) $ao['id'];
            $rows .= '<tr>'
                . '<td style="padding:10px;border-bottom:1px solid #e2e8f0;"><a href="' . htmlspecialchars($url) . '" style="color:#1d2b57;font-weight:700;">' . htmlspecialchars((string) $ao['titre']) . '</a><br><small>' . htmlspecialchars((string) ($ao['autorite_contractante'] ?? '-')) . '</small></td>'
                . '<td style="padding:10px;border-bottom:1px solid #e2e8f0;white-space:nowrap;">' . htmlspecialchars((string) ($ao['date_limite'] ?? '-')) . '</td>'
                . '<td style="padding:10px;border-bottom:1px solid #e2e8f0;font-weight:700;">' . (int) ($ao['personal_score'] ?? $ao['score_pertinence'] ?? 0) . '%</td>'
                . '</tr>';
        }
        $body = '<p>Bonjour ' . htmlspecialchars($name ?: 'collaborateur') . ',</p>'
            . '<p>Voici les appels d offres BTP encore ouverts qui attendent une décision.</p>'
            . '<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:#f1f5f9;"><th style="padding:10px;text-align:left;">Opportunité</th><th style="padding:10px;text-align:left;">Date limite</th><th style="padding:10px;text-align:left;">Score</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        $url = rtrim(AccessManager::getApplicationBasePath(), '/') . '/devis/veille_ao.php';
        $count = count($opportunities);
        $html = $this->email->buildFineaEmail('Veille AO FINEA - ' . $count . ' nouvelle(s) opportunité(s)', $body, $url, ['action_label' => 'Ouvrir la veille AO']);
        return $this->email->send(
            (string) $user['mail_pro'],
            $name,
            'Veille AO FINEA - ' . $count . ' nouvelle(s) opportunité(s)',
            $html,
            strip_tags($body),
            [
                'notification_user_id' => (int) $user['user_id'],
                'notification_preference_keys' => ['ao_opportunities'],
            ]
        );
    }

    private function claimLog(int $aoId, int $userId, string $channel): bool
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, status, last_attempt_at
                 FROM ao_alert_logs
                 WHERE ao_opportunity_id = :ao AND user_id = :user_id AND channel = :channel
                 FOR UPDATE'
            );
            $stmt->execute(['ao' => $aoId, 'user_id' => $userId, 'channel' => $channel]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($log && (string) $log['status'] === 'sent') {
                $this->pdo->commit();
                return false;
            }
            if ($log && (string) $log['status'] === 'processing' && !empty($log['last_attempt_at'])
                && strtotime((string) $log['last_attempt_at']) > time() - 600) {
                $this->pdo->commit();
                return false;
            }

            if ($log) {
                $update = $this->pdo->prepare(
                    "UPDATE ao_alert_logs
                     SET status = 'processing', error_message = NULL,
                         attempt_count = attempt_count + 1, last_attempt_at = NOW()
                     WHERE id = :id"
                );
                $update->execute(['id' => (int) $log['id']]);
            } else {
                $insert = $this->pdo->prepare(
                    "INSERT INTO ao_alert_logs
                        (ao_opportunity_id, user_id, channel, status, attempt_count, last_attempt_at)
                     VALUES (:ao, :user_id, :channel, 'processing', 1, NOW())"
                );
                $insert->execute(['ao' => $aoId, 'user_id' => $userId, 'channel' => $channel]);
            }
            $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function completeLog(int $aoId, int $userId, string $channel, string $status, string $error = ''): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ao_alert_logs
             SET status = :status,
                 sent_at = IF(:status = "sent", NOW(), sent_at),
                 error_message = NULLIF(:error, "")
             WHERE ao_opportunity_id = :ao AND user_id = :user_id AND channel = :channel'
        );
        $stmt->execute(['status' => $status, 'error' => mb_substr($error, 0, 2000), 'ao' => $aoId, 'user_id' => $userId, 'channel' => $channel]);
        AuditTrail::log('ao_alert_' . $status, [
            'module' => 'devis',
            'action' => 'ao_alert_' . $channel,
            'entity_type' => 'ao_opportunity',
            'entity_id' => (string) $aoId,
            'user_id' => $userId,
            'result' => $status === 'sent' ? 'success' : 'failed',
            'severity' => $status === 'sent' ? 'info' : 'warning',
            'details' => ['channel' => $channel, 'error' => $error],
        ], $this->pdo);
    }
}

