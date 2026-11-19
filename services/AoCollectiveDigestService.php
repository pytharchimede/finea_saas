<?php
require_once __DIR__ . '/../repositories/AoDigestRepository.php';
require_once __DIR__ . '/NotificationEmailChannelService.php';

final class AoCollectiveDigestService
{
    private AoDigestRepository $repository;
    public function __construct(private PDO $pdo) { $this->repository = new AoDigestRepository($pdo); }

    public function preview(): array
    {
        $settings = $this->repository->settings();
        $rows = $this->repository->opportunities((int) $settings['min_score']);
        $recipients = $this->repository->recipients($settings);
        $error = '';
        try { $envelope = AoDigestPolicy::envelope($recipients); }
        catch (Throwable $e) { $envelope = null; $error = $e->getMessage(); }
        return ['settings' => $settings, 'recipients' => $recipients, 'opportunities' => $rows, 'envelope' => $envelope, 'error' => $error];
    }

    public function run(bool $sendNow = false): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan'));
        $settings = $this->repository->settings();
        if (empty($settings['enabled'])) return ['status' => 'disabled', 'emails' => 0];
        if (!$sendNow && !AoDigestPolicy::isDue($now)) return ['status' => 'not_due', 'emails' => 0];
        // Verrou MySQL par connexion : évite deux envois par des crons concurrents.
        $lock = 'finea_ao_digest_' . substr(hash('sha256', (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 24);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $stmt->execute(['name' => $lock]);
        if ((int) $stmt->fetchColumn() !== 1) return ['status' => 'busy', 'emails' => 0];
        try { return $this->sendLocked($now, $settings); }
        finally {
            $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $stmt->execute(['name' => $lock]);
        }
    }

    private function sendLocked(DateTimeImmutable $now, array $settings): array
    {
        $day = $now->format('Y-m-d');
        $stmt = $this->pdo->prepare('SELECT status FROM ao_digest_runs WHERE run_date = :day');
        $stmt->execute(['day' => $day]);
        $existing = $stmt->fetchColumn();
        // Un envoi interrompu reste indéterminé : pas de renvoi automatique qui pourrait doubler le mail.
        if ($existing && in_array($existing, ['sent', 'sending', 'uncertain'], true)) return ['status' => $existing, 'emails' => 0];
        $recipients = $this->repository->recipients($settings);
        $envelope = AoDigestPolicy::envelope($recipients);
        $rows = AoDigestPolicy::selectOpportunities($this->repository->opportunities((int) $settings['min_score']), (int) $settings['min_score'], $now);
        $stmt = $this->pdo->prepare("INSERT INTO ao_digest_runs (run_date, status, opportunity_count, recipients_json)
            VALUES (:day, 'preparing', :count, :recipients) ON DUPLICATE KEY UPDATE
            status = 'preparing', opportunity_count = VALUES(opportunity_count), recipients_json = VALUES(recipients_json),
            error_message = NULL, email_log_id = NULL, started_at = NOW(), finished_at = NULL");
        $stmt->execute(['day' => $day, 'count' => count($rows), 'recipients' => json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        $attempted = false;
        $channel = new NotificationEmailChannelService();
        try {
            require_once __DIR__ . '/AoReportBuilder.php';
            $filters = ['q' => '', 'status' => '', 'min_score' => (string) $settings['min_score'], 'deadline_scope' => 'active'];
            $attachments = [];
            foreach (['pdf' => 'application/pdf', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] as $format => $type) {
                $attachments[] = ['name' => 'bilan_appels_offres_' . $day . '.' . $format, 'type' => $type,
                    'content' => AoReportBuilder::build($rows, $format, $filters)];
            }
            $subject = 'FINEA · Bilan des appels d’offres · ' . $now->format('d/m/Y') . ' · ' . count($rows) . ' offre(s) ouverte(s)';
            $body = '<p>Bonjour,</p><p>Le bilan commun du lundi et du vendredi regroupe les appels d’offres encore ouverts, avec un score d’au moins '
                . (int) $settings['min_score'] . '/100. Les dossiers écartés sont exclus.</p><p><strong>' . count($rows)
                . ' offre(s) à examiner.</strong> Le PDF et le fichier Excel joints présentent la même sélection, classée par date limite de dépôt.</p>';
            if (!$rows) $body .= '<p>Aucune offre ne satisfait actuellement ces critères.</p>';
            $body .= '<table style="width:100%;border-collapse:collapse"><tr><th align="left">Offre</th><th>Date limite</th><th>Score</th></tr>';
            foreach (array_slice($rows, 0, 20) as $row) {
                $body .= '<tr><td style="padding:8px;border-bottom:1px solid #e2e8f0">' . htmlspecialchars($row['titre'], ENT_QUOTES, 'UTF-8')
                    . '</td><td>' . htmlspecialchars((string) $row['date_limite'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $row['score_pertinence'] . '/100</td></tr>';
            }
            $body .= '</table>';
            if (count($rows) > 20) $body .= '<p>Les pièces jointes contiennent les ' . count($rows) . ' offres de la sélection complète.</p>';
            $html = $channel->buildFineaEmail($subject, $body, '/devis/veille_ao.php', [
                'header_badge' => 'Veille appels d’offres', 'subtitle' => 'Bilan collectif · lundi et vendredi à 8 h · heure d’Abidjan',
                'repere_title' => 'Diffusion départementale', 'repere_text' => 'Responsable commercial en destinataire principal. Équipes commerciale et technique, direction et administrateurs abonnés en copie.',
                'button_label' => 'Consulter la veille']);
            $stmt = $this->pdo->prepare("UPDATE ao_digest_runs SET status = 'sending' WHERE run_date = :day");
            $stmt->execute(['day' => $day]);
            $attempted = true;
            // Le bilan obligatoire des départements est distinct des alertes AO personnelles.
            // Les administrateurs facultatifs ont été filtrés individuellement avant la constitution des CC.
            $sent = $channel->send($envelope['to']['email'], $envelope['to']['name'], $subject, $html, strip_tags($body), [
                'cc' => $envelope['cc'], 'attachments' => $attachments]);
            $this->finish($day, $sent ? 'sent' : 'uncertain', $channel->lastEmailLogId(), $sent ? '' : 'Envoi non confirmé : consulter les journaux SMTP avant tout renvoi.');
            return ['status' => $sent ? 'sent' : 'uncertain', 'emails' => $sent ? 1 : 0, 'opportunities' => count($rows), 'cc_count' => count($envelope['cc'])];
        } catch (Throwable $e) {
            $this->finish($day, $attempted ? 'uncertain' : 'failed', $channel->lastEmailLogId(), 'Préparation ou envoi interrompu. Vérifiez les dépendances et les journaux email.');
            throw $e;
        }
    }

    private function finish(string $day, string $status, ?int $logId, string $error): void
    {
        $stmt = $this->pdo->prepare('UPDATE ao_digest_runs SET status = :status, email_log_id = :log, error_message = :error, finished_at = NOW() WHERE run_date = :day');
        $stmt->execute(['day' => $day, 'status' => $status, 'log' => $logId, 'error' => $error ?: null]);
    }
}
