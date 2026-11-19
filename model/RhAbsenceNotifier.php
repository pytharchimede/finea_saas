<?php

require_once __DIR__ . '/../services/NotificationEmailChannelService.php';

class RhAbsenceNotifier
{
    private PDO $pdo;
    private NotificationEmailChannelService $emailChannel;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->emailChannel = new NotificationEmailChannelService();
        $this->ensureTables();
    }

    public function notifyAbsenceMarked(int $personnelId, string $date, array $actor = []): void
    {
        if ($personnelId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return;
        }

        if ($this->isPointageExempt($personnelId)) {
            return;
        }

        $employee = $this->getEmployee($personnelId);
        if ($employee === null) {
            return;
        }

        $sentKey = 'absence:' . $personnelId . ':' . $date;
        if ($this->wasSent($sentKey)) {
            $this->checkThreeAbsenceAlert($personnelId, $date, $employee);
            return;
        }

        $employeeEmail = trim((string) ($employee['email'] ?? ''));
        if ($employeeEmail === '' || !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $cc = $this->getAbsenceStakeholders($employeeEmail, 'absence_marked');
        $subject = 'Justification d absence requise sous 48h - ' . (string) $employee['full_name'];
        $body = '<p style="margin:0 0 14px; line-height:1.7;">Une absence a ete detectee dans votre pointage.</p>'
            . '<p style="margin:0 0 14px; line-height:1.7;"><strong>Collaborateur :</strong> ' . htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Matricule :</strong> ' . htmlspecialchars((string) ($employee['matricule'] ?: '-'), ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Date :</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0; line-height:1.7;"><strong>Delai :</strong> merci de transmettre un justificatif dans les 48h. Passe ce delai, la journee pourra etre consideree comme absence non justifiee et traitee en paie selon les conditions de votre contrat.</p>';
        $html = $this->emailChannel->buildFineaEmail($subject, $body, 'employe/pointage_employe.php', [
            'subtitle' => 'Notification automatique de pointage.',
            'repere_title' => 'Justification attendue',
            'repere_text' => 'Delai de justification : 48h a compter de cette notification.',
            'button_label' => 'Justifier mon absence',
        ]);

        if ($this->emailChannel->send($employeeEmail, (string) $employee['full_name'], $subject, $html, null, ['cc' => $cc])) {
            $this->markSent($sentKey, 'absence_marked', $personnelId, $date);
        }

        $this->checkThreeAbsenceAlert($personnelId, $date, $employee);
    }

    public function processDueReminders(int $limit = 100): int
    {
        $stmt = $this->pdo->prepare('
            SELECT personnel_id, attendance_date, sent_at
            FROM rh_absence_notifications
            WHERE notification_kind = "absence_marked"
              AND sent_at <= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY sent_at ASC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        $sent = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $personnelId = (int) ($row['personnel_id'] ?? 0);
            $date = (string) ($row['attendance_date'] ?? '');
            if ($personnelId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            if ($this->wasSent('absence_reminder_24h:' . $personnelId . ':' . $date) || $this->hasRegularizationSubmitted($personnelId, $date)) {
                continue;
            }
            if ($this->sendReminder($personnelId, $date)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function scanMissingPointages(?string $period = null, int $limit = 250): int
    {
        $period = $period && preg_match('/^\d{4}-\d{2}$/', $period) ? $period : date('Y-m');
        $startDate = $period . '-01';
        $endDate = min(date('Y-m-d', strtotime('yesterday')), date('Y-m-t', strtotime($startDate)));
        if ($endDate < $startDate) {
            return 0;
        }

        $holidays = $this->getHolidayDateSet($startDate, $endDate);
        $personnelRows = $this->pdo->query('
            SELECT id_personnel_soignant AS id, date_recrutement, date_prise_service, date_sortie
            FROM personnel
            WHERE email_personnel_soignant IS NOT NULL
              AND TRIM(email_personnel_soignant) <> ""
              AND (date_sortie IS NULL OR date_sortie = "0000-00-00" OR date_sortie >= CURDATE())
            ORDER BY id_personnel_soignant ASC
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sent = 0;
        foreach ($personnelRows as $personnel) {
            if ($sent >= $limit) {
                break;
            }

            $personnelId = (int) ($personnel['id'] ?? 0);
            if ($personnelId <= 0 || $this->isPointageExempt($personnelId)) {
                continue;
            }

            $employeeStart = $this->normalizeDate((string) (($personnel['date_prise_service'] ?? '') ?: ($personnel['date_recrutement'] ?? '')));
            $employeeEnd = $this->normalizeDate((string) ($personnel['date_sortie'] ?? ''));
            foreach ($this->listWorkingDates($startDate, $endDate, $holidays) as $date) {
                if ($employeeStart !== null && $date < $employeeStart) {
                    continue;
                }
                if ($employeeEnd !== null && $date > $employeeEnd) {
                    continue;
                }
                if ($this->hasAttendanceRow($personnelId, $date) || $this->hasRegularizationSubmitted($personnelId, $date)) {
                    continue;
                }
                if ($this->wasSent('absence:' . $personnelId . ':' . $date)) {
                    continue;
                }
                $before = $sent;
                $this->notifyAbsenceMarked($personnelId, $date, ['user_name' => 'Detection automatique']);
                if (!$this->wasSent('absence:' . $personnelId . ':' . $date)) {
                    continue;
                }
                if ($sent === $before) {
                    $sent++;
                }
                if ($sent >= $limit) {
                    break;
                }
            }
        }

        return $sent;
    }

    private function checkThreeAbsenceAlert(int $personnelId, string $date, array $employee): void
    {
        $period = substr($date, 0, 7);
        $sentKey = 'absence_3:' . $personnelId . ':' . $period;
        if ($this->wasSent($sentKey)) {
            return;
        }

        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM rh_attendance_daily
            WHERE personnel_id = :personnel_id
                AND attendance_status = "absence"
                AND attendance_date BETWEEN :start_date AND :end_date
        ');
        $stmt->execute([
            ':personnel_id' => $personnelId,
            ':start_date' => $period . '-01',
            ':end_date' => (new DateTimeImmutable($period . '-01'))->modify('last day of this month')->format('Y-m-d'),
        ]);
        $count = (int) $stmt->fetchColumn();
        if ($count < 3) {
            return;
        }

        $rhRecipients = $this->getRhRecipients('absence_threshold_3');
        if ($rhRecipients === []) {
            return;
        }

        $subject = 'Alerte RH - 3 absences non justifiees';
        $body = '<p style="margin:0 0 14px; line-height:1.7;">Le seuil de 3 absences enregistrees est atteint pour le mois ' . htmlspecialchars($period, ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p style="margin:0; line-height:1.7;"><strong>Collaborateur :</strong> ' . htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Matricule :</strong> ' . htmlspecialchars((string) ($employee['matricule'] ?: '-'), ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Action attendue :</strong> etablir une demande d explications si les absences restent non justifiees.</p>';
        $html = $this->emailChannel->buildFineaEmail($subject, $body, 'rh/demandes_explication_rh.php', [
            'subtitle' => 'Suivi disciplinaire automatique.',
            'repere_title' => 'Seuil atteint',
            'repere_text' => $count . ' absence(s) enregistree(s) sur la periode.',
            'button_label' => 'Ouvrir les demandes d explications',
        ]);

        $sent = 0;
        foreach ($rhRecipients as $recipient) {
            if ($this->emailChannel->send((string) $recipient['email'], (string) $recipient['name'], $subject, $html)) {
                $sent++;
            }
        }

        if ($sent > 0) {
            $this->markSent($sentKey, 'absence_threshold_3', $personnelId, $date);
        }
    }

    private function getEmployee(int $personnelId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                id_personnel_soignant AS id,
                matricule_personnel_soignant AS matricule,
                nom_personnel_soignant AS full_name,
                email_personnel_soignant AS email
            FROM personnel
            WHERE id_personnel_soignant = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $personnelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function getRhRecipients(string $notificationKey = 'absence_marked'): array
    {
        $preferenceKey = 'employee_absences.' . strtolower(trim($notificationKey));
        $stmt = $this->pdo->prepare('
            SELECT
                u.id AS user_id,
                u.mail_pro AS email,
                TRIM(CONCAT(COALESCE(u.prenom, ""), " ", COALESCE(u.nom, ""))) AS name
            FROM user_devis_banamur u
            LEFT JOIN user_notification_preferences up_exact
                ON up_exact.user_id = u.id
               AND up_exact.event_key = :preference_key
            WHERE u.active = 1
              AND u.acces_rh = 1
              AND u.mail_pro IS NOT NULL
              AND TRIM(u.mail_pro) <> ""
              AND COALESCE(up_exact.channel_email, 0) = 1
            ORDER BY u.prenom ASC, u.nom ASC
        ');
        $stmt->execute(['preference_key' => $preferenceKey]);

        $recipients = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $email = trim((string) ($row['email'] ?? ''));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[strtolower($email)] = ['email' => $email, 'name' => trim((string) ($row['name'] ?? '')) ?: $email];
            }
        }

        return array_values($recipients);
    }

    private function getAbsenceStakeholders(string $employeeEmail = '', string $notificationKey = 'absence_marked'): array
    {
        $recipients = [];
        foreach ($this->getRhRecipients($notificationKey) as $recipient) {
            $email = strtolower(trim((string) ($recipient['email'] ?? '')));
            if ($email !== '' && $email !== strtolower($employeeEmail)) {
                $recipients[$email] = $recipient;
            }
        }

        return array_values($recipients);
    }

    private function sendReminder(int $personnelId, string $date): bool
    {
        if ($this->isPointageExempt($personnelId)) {
            return false;
        }

        $employee = $this->getEmployee($personnelId);
        if ($employee === null) {
            return false;
        }

        $employeeEmail = trim((string) ($employee['email'] ?? ''));
        if ($employeeEmail === '' || !filter_var($employeeEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $sentKey = 'absence_reminder_24h:' . $personnelId . ':' . $date;
        $subject = 'Rappel justification absence - ' . $date;
        $body = '<p style="margin:0 0 14px; line-height:1.7;">Sauf erreur, aucun justificatif n a encore ete soumis pour votre absence du '
            . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '.</p>'
            . '<p style="margin:0 0 14px; line-height:1.7;"><strong>Collaborateur :</strong> ' . htmlspecialchars((string) $employee['full_name'], ENT_QUOTES, 'UTF-8') . '<br>'
            . '<strong>Matricule :</strong> ' . htmlspecialchars((string) ($employee['matricule'] ?: '-'), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0; line-height:1.7;"><strong>Rappel :</strong> merci de transmettre votre justificatif avant l expiration du delai de 48h. Passe ce delai, la journee concernee sera consideree comme absence et sera retranchable ou non en paie selon les conditions de votre contrat.</p>';
        $html = $this->emailChannel->buildFineaEmail($subject, $body, 'employe/pointage_employe.php', [
            'subtitle' => 'Rappel automatique apres 24h.',
            'repere_title' => 'Rappel justification',
            'repere_text' => 'La regularisation reste soumise a validation RH.',
            'button_label' => 'Soumettre le justificatif',
        ]);

        if ($this->emailChannel->send($employeeEmail, (string) $employee['full_name'], $subject, $html, null, ['cc' => $this->getAbsenceStakeholders($employeeEmail, 'absence_reminder_24h')])) {
            $this->markSent($sentKey, 'absence_reminder_24h', $personnelId, $date);
            return true;
        }

        return false;
    }

    public function isPointageExempt(int $personnelId): bool
    {
        if ($personnelId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rh_pointage_exemptions WHERE personnel_id = :personnel_id AND is_active = 1');
        $stmt->execute([':personnel_id' => $personnelId]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function listPointageExemptions(): array
    {
        $stmt = $this->pdo->query('
            SELECT e.*, p.matricule_personnel_soignant AS matricule, p.nom_personnel_soignant AS full_name, p.email_personnel_soignant AS email
            FROM rh_pointage_exemptions e
            INNER JOIN personnel p ON p.id_personnel_soignant = e.personnel_id
            WHERE e.is_active = 1
            ORDER BY p.nom_personnel_soignant ASC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addPointageExemption(int $personnelId, string $reason, array $actor = []): void
    {
        if ($personnelId <= 0) {
            throw new RuntimeException('Personnel invalide.');
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO rh_pointage_exemptions (personnel_id, reason, is_active, created_by_user_id, created_by_name)
            VALUES (:personnel_id, :reason, 1, :created_by_user_id, :created_by_name)
            ON DUPLICATE KEY UPDATE reason = VALUES(reason), is_active = 1, updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            ':personnel_id' => $personnelId,
            ':reason' => trim($reason),
            ':created_by_user_id' => (int) ($actor['user_id'] ?? 0) ?: null,
            ':created_by_name' => trim((string) ($actor['name'] ?? $actor['user_name'] ?? '')),
        ]);
    }

    public function removePointageExemption(int $personnelId): void
    {
        $stmt = $this->pdo->prepare('UPDATE rh_pointage_exemptions SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE personnel_id = :personnel_id');
        $stmt->execute([':personnel_id' => $personnelId]);
    }

    private function hasRegularizationSubmitted(int $personnelId, string $date): bool
    {
        $needleNumeric = '%"personnel_id":' . $personnelId . '%';
        $needleString = '%"personnel_id":"' . $personnelId . '"%';
        $dateNeedle = '%"attendance_date":"' . $date . '"%';
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM employee_legal_requests
            WHERE request_type = "regularisation_pointage"
              AND status IN ("submitted", "approved")
              AND payload_json LIKE :date_needle
              AND (payload_json LIKE :needle_numeric OR payload_json LIKE :needle_string)
        ');
        $stmt->execute([
            ':date_needle' => $dateNeedle,
            ':needle_numeric' => $needleNumeric,
            ':needle_string' => $needleString,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function hasAttendanceRow(int $personnelId, string $date): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rh_attendance_daily WHERE personnel_id = :personnel_id AND attendance_date = :attendance_date');
        $stmt->execute([':personnel_id' => $personnelId, ':attendance_date' => $date]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function getHolidayDateSet(string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare('SELECT holiday_date FROM rh_holidays WHERE holiday_date BETWEEN :start_date AND :end_date');
        $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
        $set = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $date = $this->normalizeDate((string) ($row['holiday_date'] ?? ''));
            if ($date !== null) {
                $set[$date] = true;
            }
        }
        return $set;
    }

    private function listWorkingDates(string $startDate, string $endDate, array $holidays): array
    {
        $dates = [];
        $cursor = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            if ((int) $cursor->format('N') < 6 && !isset($holidays[$date])) {
                $dates[] = $date;
            }
            $cursor = $cursor->modify('+1 day');
        }
        return $dates;
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '' || $date === '0000-00-00') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt ? $dt->format('Y-m-d') : null;
    }

    private function wasSent(string $sentKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rh_absence_notifications WHERE sent_key = :sent_key');
        $stmt->execute([':sent_key' => $sentKey]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function markSent(string $sentKey, string $kind, int $personnelId, string $date): void
    {
        $stmt = $this->pdo->prepare('
            INSERT IGNORE INTO rh_absence_notifications (sent_key, notification_kind, personnel_id, attendance_date)
            VALUES (:sent_key, :notification_kind, :personnel_id, :attendance_date)
        ');
        $stmt->execute([
            ':sent_key' => $sentKey,
            ':notification_kind' => $kind,
            ':personnel_id' => $personnelId,
            ':attendance_date' => $date,
        ]);
    }

    private function ensureTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_absence_notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                sent_key VARCHAR(120) NOT NULL,
                notification_kind VARCHAR(60) NOT NULL,
                personnel_id INT NOT NULL,
                attendance_date DATE NOT NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rh_absence_notifications_key (sent_key),
                KEY idx_rh_absence_notifications_personnel (personnel_id),
                KEY idx_rh_absence_notifications_date (attendance_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_pointage_exemptions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                reason TEXT DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_by_user_id INT DEFAULT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_rh_pointage_exemption_personnel (personnel_id),
                KEY idx_rh_pointage_exemption_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS user_notification_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                event_key VARCHAR(80) NOT NULL,
                channel_email TINYINT(1) NOT NULL DEFAULT 0,
                channel_system TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_event (user_id, event_key),
                KEY idx_user_notification_preferences_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
