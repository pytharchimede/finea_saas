<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';
require_once __DIR__ . '/../model/NotificationManager.php';
require_once __DIR__ . '/NotificationEmailChannelService.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class RhAttendanceClosingNotificationService
{
    private NotificationEmailChannelService $email;

    public function __construct(private PDO $pdo)
    {
        $this->email = new NotificationEmailChannelService();
        new NotificationManager($pdo);
        $this->ensureTable();
    }

    public function distribute(string $period, string $startDate, string $endDate): array
    {
        $matrix = (new RhPayrollController($this->pdo))->service()
            ->getMonthlyAttendanceMatrix($period, true, $startDate, $endDate);
        $sent = 0;
        $system = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($matrix['items'] as $item) {
            $employee = (array) ($item['employee'] ?? []);
            $personnelId = (int) ($employee['id'] ?? 0);
            if ($personnelId <= 0) {
                continue;
            }
            $existing = $this->findDelivery($period, $personnelId);
            if ($existing && (string) ($existing['cutoff_date'] ?? '') === $endDate && (string) ($existing['delivery_status'] ?? '') === 'sent') {
                $skipped++;
                continue;
            }

            $recipient = $this->recipient($personnelId);
            $snapshot = $this->buildSnapshot($period, $startDate, $endDate, $item, (array) ($matrix['days'] ?? []));
            $deliveryId = $this->saveSnapshot($period, $personnelId, $endDate, $snapshot, $existing);
            if ($deliveryId <= 0) {
                $failed++;
                continue;
            }

            if ((int) ($recipient['user_id'] ?? 0) > 0 && empty($existing['system_sent_at'])) {
                $this->createSystemNotification($deliveryId, $recipient, $snapshot);
                $system++;
            }

            $emailAddress = trim((string) ($recipient['email'] ?? ''));
            $emailSent = false;
            if ($emailAddress !== '' && filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
                $content = $this->renderWorkbook($snapshot);
                $subject = 'Relevé de pointage retenu pour la paie - ' . $period;
                $body = '<p>Votre période de pointage a été arrêtée par les Ressources humaines.</p>'
                    . '<p><strong>Période retenue :</strong> ' . htmlspecialchars(date('d/m/Y', strtotime($startDate))) . ' au ' . htmlspecialchars(date('d/m/Y', strtotime($endDate))) . '<br>'
                    . '<strong>Heures travaillées :</strong> ' . htmlspecialchars(number_format((float) ($snapshot['totals']['work_hours'] ?? 0), 2, ',', ' ')) . ' h<br>'
                    . '<strong>Heures supplémentaires :</strong> ' . htmlspecialchars(number_format((float) ($snapshot['totals']['overtime_hours'] ?? 0), 2, ',', ' ')) . ' h</p>'
                    . '<p>Le fichier joint contient le détail journalier, les absences et les justificatifs connus. Ce relevé constitue la base de pointage transmise au calcul de votre salaire.</p>';
                $html = $this->email->buildFineaEmail($subject, $body, 'employe/pointage_employe.php', [
                    'subtitle' => 'Clôture mensuelle du pointage',
                    'button_label' => 'Consulter mon pointage',
                ]);
                $emailSent = $this->email->send(
                    $emailAddress,
                    (string) ($recipient['name'] ?? ''),
                    $subject,
                    $html,
                    null,
                    [
                        'notification_user_id' => (int) ($recipient['user_id'] ?? 0),
                        'notification_preference_keys' => ['attendance_period_closed'],
                        'attachments' => [[
                            'name' => $this->fileName($snapshot),
                            'content' => $content,
                            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ]],
                    ]
                );
            }

            $this->markDelivery($deliveryId, $emailSent ? 'sent' : 'system_only', $emailSent);
            $emailSent ? $sent++ : $failed++;
        }

        return ['email_sent' => $sent, 'system_sent' => $system, 'skipped' => $skipped, 'failed' => $failed];
    }

    public function getAuthorizedDelivery(int $deliveryId, int $userId, bool $canManage): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, u.id recipient_user_id
             FROM rh_attendance_period_deliveries d
             LEFT JOIN user_devis_banamur u ON u.personnel_id=d.personnel_id
             WHERE d.id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (!$canManage && (int) ($row['recipient_user_id'] ?? 0) !== $userId)) {
            return null;
        }
        $snapshot = json_decode((string) ($row['snapshot_json'] ?? ''), true);
        if (!is_array($snapshot)) {
            return null;
        }
        $row['snapshot'] = $snapshot;
        return $row;
    }

    public function renderWorkbook(array $snapshot): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Relevé retenu');
        $employee = (array) ($snapshot['employee'] ?? []);
        $sheet->setCellValue('A1', 'Relevé de pointage retenu pour la paie');
        $sheet->mergeCells('A1:K1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(15);
        $sheet->setCellValue('A2', (string) ($employee['full_name'] ?? '') . ' (' . (string) ($employee['matricule'] ?? '-') . ')');
        $sheet->setCellValue('A3', 'Période : ' . date('d/m/Y', strtotime((string) $snapshot['start_date'])) . ' au ' . date('d/m/Y', strtotime((string) $snapshot['end_date'])));
        $headers = ['Date', 'Jour', 'Statut', 'Arrivée', 'Départ', 'Heures', 'Jours', 'HS', 'Justification', 'Statut justificatif', 'Pièces justificatives'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 5], $header);
        }
        $sheet->getStyle('A5:K5')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A5:K5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF0F172A');
        $sheet->getStyle('A5:K5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row = 6;
        foreach ((array) ($snapshot['days'] ?? []) as $day) {
            $values = [
                date('d/m/Y', strtotime((string) $day['date'])),
                (string) ($day['weekday'] ?? ''),
                (string) ($day['status_label'] ?? ''),
                (string) ($day['check_in_time'] ?? ''),
                (string) ($day['check_out_time'] ?? ''),
                (float) ($day['worked_hours'] ?? 0),
                (float) ($day['worked_days'] ?? 0),
                (float) ($day['overtime_hours'] ?? 0),
                (string) ($day['justification_note'] ?? ''),
                (string) ($day['justification_status'] ?? ''),
                implode(', ', (array) ($day['justification_files'] ?? [])),
            ];
            foreach ($values as $index => $value) {
                $sheet->setCellValue([$index + 1, $row], $value);
            }
            $row++;
        }
        $sheet->setAutoFilter('A5:K' . max(5, $row - 1));
        $sheet->freezePane('A6');
        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $temp = tempnam(sys_get_temp_dir(), 'attendance_');
        if ($temp === false) {
            throw new RuntimeException('Impossible de générer le relevé de pointage.');
        }
        try {
            (new Xlsx($book))->save($temp);
            return (string) file_get_contents($temp);
        } finally {
            @unlink($temp);
        }
    }

    public function fileName(array $snapshot): string
    {
        $matricule = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($snapshot['employee']['matricule'] ?? 'personnel'));
        return 'pointage_retenu_' . $matricule . '_' . (string) ($snapshot['period'] ?? '') . '.xlsx';
    }

    private function buildSnapshot(string $period, string $startDate, string $endDate, array $item, array $dates): array
    {
        $employee = (array) ($item['employee'] ?? []);
        $justifications = $this->justifications((int) ($employee['id'] ?? 0), $startDate, $endDate);
        $dayMap = (array) ($item['days'] ?? []);
        $hoursPerDay = (float) ($item['hours_per_day'] ?? 8);
        $days = [];
        foreach ($dates as $date) {
            $attendance = is_array($dayMap[$date] ?? null) ? $dayMap[$date] : [];
            $in = trim((string) ($attendance['check_in_time'] ?? ''));
            $out = trim((string) ($attendance['check_out_time'] ?? ''));
            $hours = (float) ($attendance['worked_days'] ?? 0) * $hoursPerDay;
            if ($in !== '' && $out !== '') {
                $inTs = strtotime('1970-01-01 ' . $in);
                $outTs = strtotime('1970-01-01 ' . $out);
                if ($inTs !== false && $outTs !== false) {
                    if ($outTs < $inTs) {
                        $outTs += 86400;
                    }
                    $hours = ($outTs - $inTs) / 3600;
                }
            }
            $justification = (array) ($justifications[$date] ?? []);
            $status = (string) ($attendance['attendance_status'] ?? '');
            $days[] = [
                'date' => $date,
                'weekday' => ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'][(int) date('w', strtotime($date))],
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'check_in_time' => $in,
                'check_out_time' => $out,
                'worked_hours' => round($hours, 2),
                'worked_days' => round((float) ($attendance['worked_days'] ?? 0), 2),
                'overtime_hours' => round((float) ($attendance['overtime_hours'] ?? 0), 2),
                'notes' => (string) ($attendance['notes'] ?? ''),
                'justification_note' => (string) ($justification['note'] ?? ''),
                'justification_status' => (string) ($justification['status'] ?? ''),
                'justification_files' => (array) ($justification['files'] ?? []),
            ];
        }
        return [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'employee' => $employee,
            'totals' => (array) ($item['totals'] ?? []),
            'days' => $days,
            'generated_at' => date('c'),
        ];
    }

    public function justifications(int $personnelId, string $startDate, string $endDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.status, r.payload_json
             FROM employee_legal_requests r
             INNER JOIN user_devis_banamur u ON u.id=r.requester_user_id
             WHERE u.personnel_id=:personnel_id AND r.request_type="regularisation_pointage"
             ORDER BY r.submitted_at DESC, r.id DESC'
        );
        $stmt->execute(['personnel_id' => $personnelId]);
        $mapped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $from = (string) ($payload['date_debut_justificatif'] ?? $payload['attendance_date'] ?? '');
            $to = (string) ($payload['date_fin_justificatif'] ?? $from);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $startDate || $from > $endDate) {
                continue;
            }
            $files = [];
            foreach ((array) ($payload['justificatifs'] ?? []) as $file) {
                if (is_array($file)) {
                    $files[] = (string) ($file['original_name'] ?? $file['name'] ?? basename((string) ($file['path'] ?? '')));
                } elseif (is_string($file)) {
                    $files[] = basename($file);
                }
            }
            for ($cursor = new DateTimeImmutable(max($from, $startDate)), $last = new DateTimeImmutable(min($to, $endDate)); $cursor <= $last; $cursor = $cursor->modify('+1 day')) {
                $date = $cursor->format('Y-m-d');
                if (!isset($mapped[$date])) {
                    $mapped[$date] = [
                        'note' => (string) ($payload['note'] ?? ''),
                        'status' => (string) ($row['status'] ?? ''),
                        'files' => array_values(array_filter($files)),
                    ];
                }
            }
        }
        return $mapped;
    }

    private function recipient(int $personnelId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.nom_personnel_soignant name, p.email_personnel_soignant personnel_email,
                    u.id user_id, u.mail_pro user_email
             FROM personnel p
             LEFT JOIN user_devis_banamur u ON u.personnel_id=p.id_personnel_soignant AND u.active=1
             WHERE p.id_personnel_soignant=:id ORDER BY u.id ASC LIMIT 1'
        );
        $stmt->execute(['id' => $personnelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $row['email'] = trim((string) (($row['personnel_email'] ?? '') ?: ($row['user_email'] ?? '')));
        return $row;
    }

    private function saveSnapshot(string $period, int $personnelId, string $cutoffDate, array $snapshot, ?array $existing): int
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO rh_attendance_period_deliveries(period_key, personnel_id, cutoff_date, snapshot_json, snapshot_hash, delivery_status)
             VALUES(:period, :personnel, :cutoff, :snapshot, :hash, "pending")
             ON DUPLICATE KEY UPDATE cutoff_date=VALUES(cutoff_date), snapshot_json=VALUES(snapshot_json),
                snapshot_hash=VALUES(snapshot_hash), delivery_status="pending", updated_at=CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'period' => $period, 'personnel' => $personnelId, 'cutoff' => $cutoffDate,
            'snapshot' => $json, 'hash' => hash('sha256', $json),
        ]);
        return $existing ? (int) $existing['id'] : (int) $this->pdo->lastInsertId();
    }

    private function createSystemNotification(int $deliveryId, array $recipient, array $snapshot): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_user_id,channels_json,payload_json)
             VALUES(NOW(),"attendance_period_closed",:period,:title,:message,:link,:user_id,:channels,:payload)'
        );
        $stmt->execute([
            'period' => (string) $snapshot['period'],
            'title' => 'Votre pointage mensuel a été arrêté',
            'message' => 'Le relevé retenu pour le calcul de votre salaire est disponible avec le détail des heures, HS, absences et justificatifs.',
            'link' => 'request/download_attendance_statement.php?id=' . $deliveryId,
            'user_id' => (int) $recipient['user_id'],
            'channels' => '["system","email"]',
            'payload' => json_encode(['delivery_id' => $deliveryId, 'period' => $snapshot['period']], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function findDelivery(string $period, int $personnelId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rh_attendance_period_deliveries WHERE period_key=:period AND personnel_id=:personnel LIMIT 1');
        $stmt->execute(['period' => $period, 'personnel' => $personnelId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function markDelivery(int $id, string $status, bool $emailSent): void
    {
        $stmt = $this->pdo->prepare('UPDATE rh_attendance_period_deliveries SET delivery_status=:status, email_sent_at=:sent, system_sent_at=NOW() WHERE id=:id');
        $stmt->execute(['status' => $status, 'sent' => $emailSent ? date('Y-m-d H:i:s') : null, 'id' => $id]);
    }

    private function statusLabel(string $status): string
    {
        return ['present'=>'Présent','mission'=>'Mission','half_day'=>'Demi-journée','conge'=>'Congé','repos'=>'Repos','absence'=>'Absent','maladie'=>'Maladie'][$status] ?? ($status !== '' ? ucfirst($status) : 'Non saisi');
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS rh_attendance_period_deliveries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                period_key VARCHAR(7) NOT NULL,
                personnel_id INT NOT NULL,
                cutoff_date DATE NOT NULL,
                snapshot_json LONGTEXT NOT NULL,
                snapshot_hash CHAR(64) NOT NULL,
                delivery_status VARCHAR(30) NOT NULL DEFAULT "pending",
                email_sent_at DATETIME NULL,
                system_sent_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_attendance_delivery_period_personnel(period_key, personnel_id),
                KEY idx_attendance_delivery_status(delivery_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
