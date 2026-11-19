<?php
// Cron CLI — Envoi quotidien du listing des décaissements de la veille au boss et à la copie interne.
// Usage:
//   php cron/send_point_financier_daily_digest.php
//   php cron/send_point_financier_daily_digest.php --dry-run
//   php cron/send_point_financier_daily_digest.php --force --date=2026-04-06
// Variables utiles en CLI:
//   APP_BASE_URL=https://projet.fidest.ci

declare(strict_types=1);

require_once __DIR__ . '/../model/EnvConfig.php';
\EnvConfig::loadFromDirectory(__DIR__);
require_once __DIR__ . '/../controllers/CronController.php';
require_once __DIR__ . '/../model/Mailer.php';
require_once __DIR__ . '/../services/NotificationEmailChannelService.php';
require_once __DIR__ . '/../repositories/ScheduledEmailDispatchRepository.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../services/DailyDisbursementPointService.php';
require_once __DIR__ . '/../services/FebBudgetAutoRejectionService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

function pfd_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $message\n";
}

function pfd_ensure_dir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

function pfd_load_config(): array
{
    $path = __DIR__ . '/../config/point_financier_digest.php';
    if (!is_file($path)) {
        throw new RuntimeException('Config point_financier_digest.php introuvable.');
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Config point financier invalide.');
    }
    return $config;
}

function pfd_generate_daily_point_pdf(PDO $pdo,string $reportDate):array
{
    $service=new DailyDisbursementPointService($pdo);$point=$service->findByDate($reportDate);if(!$point)return['content'=>'','fileName'=>'','rowCount'=>0,'total'=>0,'paidTotal'=>0,'toPayTotal'=>0,'beneficiaryCount'=>0,'chantierCount'=>0,'statusMeta'=>['key'=>'absent','label'=>'Aucun point quotidien'],'pointId'=>0,'version'=>0];
    $_GET['id']=(int)$point['id'];define('DAILY_POINT_CRON_EXPORT',true);$dailyPointPdfReport=null;require __DIR__.'/../decaissement/request/export_daily_point_pdf.php';if(!is_array($dailyPointPdfReport)||empty($dailyPointPdfReport['content']))throw new RuntimeException('La génération du PDF ISO du point quotidien a échoué.');return$dailyPointPdfReport;
}

function pfd_parse_cli_options(array $argv): array
{
    $options = [
        'force' => false,
        'dry_run' => false,
        'date' => null,
    ];
    foreach ($argv as $arg) {
        if ($arg === '--force') {
            $options['force'] = true;
        } elseif ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif (strpos($arg, '--date=') === 0) {
            $options['date'] = substr($arg, 7);
        }
    }
    return $options;
}

function pfd_normalize_recipients(array $items): array
{
    $result = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $email = trim((string)($item['email'] ?? ''));
        if ($email === '') {
            continue;
        }
        $result[] = [
            'email' => $email,
            'name' => trim((string)($item['name'] ?? '')),
        ];
    }
    return $result;
}

function pfd_dedupe_recipients(array $recipients): array
{
    $seen = [];
    $result = [];

    foreach ($recipients as $recipient) {
        if (!is_array($recipient)) {
            continue;
        }

        $email = strtolower(trim((string)($recipient['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        if (isset($seen[$email])) {
            continue;
        }

        $seen[$email] = true;
        $result[] = [
            'email' => trim((string)$recipient['email']),
            'name' => trim((string)($recipient['name'] ?? '')),
        ];
    }

    return $result;
}

function pfd_format_recipients_for_log(array $recipients): string
{
    $emails = [];
    foreach ($recipients as $recipient) {
        $email = trim((string)($recipient['email'] ?? ''));
        if ($email !== '') {
            $emails[] = $email;
        }
    }
    return implode(', ', $emails);
}

function pfd_build_email_html(array $report, string $reportDate, string $baseUrl): string
{
    $label = (string)($report['statusMeta']['label'] ?? 'Point financier');
    $rowCount = (int)($report['rowCount'] ?? 0);
    $total = number_format((float)($report['total'] ?? 0), 0, ',', ' ') . ' CFA';
    $beneficiaries = (int)($report['beneficiaryCount'] ?? 0);
    $chantiers = (int)($report['chantierCount'] ?? 0);
    $pdfUrl = rtrim($baseUrl, '/') . '/decaissement/request/export_daily_point_pdf.php?id=' . (int)($report['pointId'] ?? 0);

    $cardsHtml = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 12px;"><tr>'
        . '<td width="25%" style="padding:0 6px 12px 0;"><div style="background:#eef2ff;border:1px solid #dbe4ff;border-radius:16px;padding:16px;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.14em;color:#64748b;font-weight:700;">Statut</div><div style="margin-top:8px;font-size:18px;font-weight:700;color:#1e293b;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div></div></td>'
        . '<td width="25%" style="padding:0 6px 12px 6px;"><div style="background:#ecfdf5;border:1px solid #ccefdc;border-radius:16px;padding:16px;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.14em;color:#64748b;font-weight:700;">Fiches</div><div style="margin-top:8px;font-size:18px;font-weight:700;color:#14532d;">' . $rowCount . '</div></div></td>'
        . '<td width="25%" style="padding:0 6px 12px 6px;"><div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:16px;padding:16px;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.14em;color:#64748b;font-weight:700;">Montant</div><div style="margin-top:8px;font-size:18px;font-weight:700;color:#9a3412;">' . htmlspecialchars($total, ENT_QUOTES, 'UTF-8') . '</div></div></td>'
        . '<td width="25%" style="padding:0 0 12px 6px;"><div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:16px;padding:16px;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:0.14em;color:#64748b;font-weight:700;">Bénéficiaires / Chantiers</div><div style="margin-top:8px;font-size:18px;font-weight:700;color:#6b21a8;">' . $beneficiaries . ' / ' . $chantiers . '</div></div></td>'
        . '</tr></table>';

    $bodyHtml = '<div style="border:1px solid #e2e8f0;border-radius:18px;background:#f8fafc;padding:22px;">'
        . '<div style="font-size:16px;font-weight:700;color:#0f172a;">Pièce jointe officielle</div>'
        . '<div style="margin-top:8px;font-size:14px;line-height:1.7;color:#475569;">Le document PDF joint est exactement le point quotidien de décaissement officiel de la veille : même gabarit ISO, mêmes montants, mêmes états et mêmes visas que dans l’application.</div>'
        . '</div>';

    return \Mailer::buildFineaNotificationEmail('Point quotidien de décaissement de la veille', $bodyHtml, $pdfUrl, [
        'subtitle' => 'Notification Banamur harmonisée avec la charte visuelle Finea pour une lecture plus claire sur ordinateur comme sur mobile.',
        'repere_title' => 'Repère',
        'repere_text' => 'Point V' . (int)($report['version'] ?? 0) . ' du ' . date('d/m/Y', strtotime($reportDate)) . ' avec le PDF ISO officiel en pièce jointe.',
        'cards_html' => $cardsHtml,
        'button_label' => 'Ouvrir l’export dans l’application',
        'footer_text' => 'BANAMUR • Diffusion automatique programmée à 10h00 • Généré le ' . date('d/m/Y H:i'),
    ]);
}

function pfd_build_email_text(array $report, string $reportDate): string
{
    return "Point quotidien de décaissement de la veille\n"
        . 'Date du reporting : ' . date('d/m/Y', strtotime($reportDate)) . "\n"
        . 'Statut : ' . (string)($report['statusMeta']['label'] ?? 'Décaissements') . "\n"
        . 'Fiches : ' . (int)($report['rowCount'] ?? 0) . "\n"
        . 'Montant total : ' . number_format((float)($report['total'] ?? 0), 0, ',', ' ') . " CFA\n"
        . 'Bénéficiaires : ' . (int)($report['beneficiaryCount'] ?? 0) . "\n"
        . 'Chantiers : ' . (int)($report['chantierCount'] ?? 0) . "\n\n"
        . 'Déjà payé : ' . number_format((float)($report['paidTotal'] ?? 0), 0, ',', ' ') . " CFA\n"
        . 'À payer : ' . number_format((float)($report['toPayTotal'] ?? 0), 0, ',', ' ') . " CFA\n\n"
        . 'Le PDF ISO officiel du point quotidien est joint à ce message.' . "\n";
}

try {
    $config = pfd_load_config();
    $options = pfd_parse_cli_options(array_slice($argv, 1));

    if (empty($config['enabled']) && !$options['dry_run']) {
        pfd_log('INFO: envoi désactivé dans config/point_financier_digest.php');
        exit(0);
    }

    $timezone = new DateTimeZone((string)($config['timezone'] ?? 'Africa/Abidjan'));
    $now = new DateTimeImmutable('now', $timezone);
    $reportDate = trim((string)($options['date'] ?? ''));
    if ($reportDate === '') {
        $reportDate = $now->modify('-1 day')->format('Y-m-d');
    }
    $reportDateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $reportDate, $timezone);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$reportDateObj || ($dateErrors !== false && ((int)$dateErrors['warning_count'] > 0 || (int)$dateErrors['error_count'] > 0))) {
        throw new RuntimeException('Format de date invalide. Utilisez --date=YYYY-MM-DD');
    }

    $toRecipients = pfd_dedupe_recipients(pfd_normalize_recipients((array)($config['to'] ?? [])));
    $configuredCcRecipients = pfd_dedupe_recipients(pfd_normalize_recipients((array)($config['cc'] ?? [])));

    if (!$toRecipients) {
        throw new RuntimeException('Aucun destinataire principal configuré dans config/point_financier_digest.php');
    }

    // PHPMailer accepte un seul destinataire principal dans notre wrapper Mailer::send().
    // Le premier destinataire reste en TO, tous les autres destinataires configurés sont ajoutés en CC.
    // Cela corrige le cas où le boss + vous êtes tous les deux dans "to" mais où seul le premier recevait le mail.
    $primaryRecipient = $toRecipients[0];
    $ccRecipients = pfd_dedupe_recipients(array_merge(array_slice($toRecipients, 1), $configuredCcRecipients));

    $pdo = (new CronController())->connection();
    $febAutoRejection = (new FebBudgetAutoRejectionService($pdo))->rejectExpired();
    pfd_log('FEB sans affectation budgétaire refusées après 72 h : ' . (int)$febAutoRejection['rejected'] . ' · erreurs : ' . (int)$febAutoRejection['errors']);
    $dispatchRepository = new ScheduledEmailDispatchRepository($pdo);
    $dispatchRepository->ensureTable();

    $jobKey = trim((string)($config['job_key'] ?? 'point_financier_daily_digest')) ?: 'point_financier_daily_digest';
    $existing = $dispatchRepository->get($jobKey, $reportDate);
    if ($existing && $existing['status'] === 'sent' && !$options['force']) {
        pfd_log('INFO: rapport déjà envoyé pour ' . $reportDate . ' ; arrêt pour éviter le spam.');
        exit(0);
    }

    $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $lockDir = $root . '/storage/locks';
    pfd_ensure_dir($lockDir);
    $lockPath = $lockDir . '/' . $jobKey . '.lock';
    $fp = @fopen($lockPath, 'c');
    if ($fp === false) {
        throw new RuntimeException('Lock impossible: ' . $lockPath);
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        pfd_log('INFO: autre exécution déjà en cours.');
        fclose($fp);
        exit(0);
    }

    $report = pfd_generate_daily_point_pdf($pdo,$reportDate);

    if ((int)($report['rowCount'] ?? 0) === 0) {
        $dispatchRepository->upsert($jobKey, $reportDate, [
            'status' => 'skipped',
            'subject' => ((string)($config['subject_prefix'] ?? 'Point financier')) . ' - ' . $reportDate,
            'recipient_email' => $primaryRecipient['email'],
            'row_count' => 0,
            'total_amount' => 0,
            'error_message' => 'Aucun décaissement la veille, envoi ignoré pour éviter un bruit inutile.',
            'sent_at' => null,
        ]);
        pfd_log('INFO: aucun point quotidien arrêté pour ' . $reportDate . ' ; email ignoré.');
        flock($fp, LOCK_UN);
        fclose($fp);
        exit(0);
    }

    $baseUrl = trim((string) \EnvConfig::get('APP_BASE_URL', ''));
    $baseUrl = rtrim($baseUrl, '/');
    if ($baseUrl === '') {
        $baseUrl = 'https://projet.fidest.ci';
    }

    $subject = trim((string)($config['subject_prefix'] ?? 'BANAMUR - Point des décaissements de la veille'))
        . ' - ' . $reportDateObj->format('d/m/Y');
    $html = pfd_build_email_html($report, $reportDate, $baseUrl);
    $plain = pfd_build_email_text($report, $reportDate);

    if ($options['dry_run']) {
        pfd_log('DRY RUN: aucun email envoyé.');
        pfd_log('Sujet: ' . $subject);
        pfd_log('PDF: ' . (string)$report['fileName'] . ' ; lignes=' . (int)$report['rowCount'] . ' ; total=' . number_format((float)$report['total'], 0, ',', ' ') . ' CFA');
        flock($fp, LOCK_UN);
        fclose($fp);
        exit(0);
    }

    $emailChannel = new \NotificationEmailChannelService();
    $sent = $emailChannel->send(
        $primaryRecipient['email'],
        $primaryRecipient['name'],
        $subject,
        $html,
        $plain,
        [
            'from_name' => (string)($config['from_name'] ?? 'Direction Financière BANAMUR'),
            'cc' => $ccRecipients,
            'attachments' => [[
                'name' => (string)$report['fileName'],
                'content' => (string)$report['content'],
                'type' => 'application/pdf',
            ]],
        ]
    );

    if (!$sent) {
        $dispatchRepository->upsert($jobKey, $reportDate, [
            'status' => 'failed',
            'subject' => $subject,
            'recipient_email' => $primaryRecipient['email'],
            'row_count' => (int)$report['rowCount'],
            'total_amount' => (float)$report['total'],
            'error_message' => 'NotificationEmailChannelService::send a retourné false',
            'sent_at' => null,
        ]);
        throw new RuntimeException('Échec d\'envoi du digest quotidien.');
    }

    $dispatchRepository->upsert($jobKey, $reportDate, [
        'status' => 'sent',
        'subject' => $subject,
        'recipient_email' => $primaryRecipient['email'],
        'row_count' => (int)$report['rowCount'],
        'total_amount' => (float)$report['total'],
        'error_message' => null,
        'sent_at' => date('Y-m-d H:i:s'),
    ]);

    \AuditTrail::log('point_financier_daily_digest_sent', [
        'module' => 'decaissement',
        'action' => 'point_financier_daily_digest',
        'entity_type' => 'point_financier',
        'entity_label' => 'Décaissements veille',
        'details' => [
            'report_date' => $reportDate,
            'row_count' => (int)$report['rowCount'],
            'total_amount' => (float)$report['total'],
            'to' => $primaryRecipient['email'],
            'cc' => pfd_format_recipients_for_log($ccRecipients),
            'cc_count' => count($ccRecipients),
        ],
    ], $pdo);

    pfd_log('OK: digest envoyé pour ' . $reportDate . ' à ' . $primaryRecipient['email'] . (count($ccRecipients) ? ' ; CC: ' . pfd_format_recipients_for_log($ccRecipients) : ''));
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERR: ' . $e->getMessage() . PHP_EOL);
    exit(10);
}
