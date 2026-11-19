<?php

require_once __DIR__ . '/EnvConfig.php';
require_once __DIR__ . '/../decaissement/config/smtp_config.php';
\EnvConfig::loadFromDirectory(__DIR__);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../repositories/EmailLogRepository.php';

// Charger la librairie PHPMailer incluse dans le projet
require_once __DIR__ . '/../decaissement/phpmailer/src/Exception.php';
require_once __DIR__ . '/../decaissement/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../decaissement/phpmailer/src/SMTP.php';

/**
 * Simple wrapper pour l'envoi d'emails via SMTP.
 */
class Mailer
{
    private const SMTP_AUTH_FAILURE_COOLDOWN_SECONDS = 300;
    private static ?int $lastEmailLogId = null;

    private static function buildAbsoluteUrl(string $path = ''): string
    {
        $baseUrl = self::resolveBaseUrl();
        if ($path === '') {
            return $baseUrl . '/';
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }

    private static function renderEmailHeaderBadge(string $label = 'Notification securisee'): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">'
            . '<tr><td style="padding:8px 14px; border:1px solid #d6b25e; background:#fff9eb; border-radius:999px;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
            . '<tr>'
            . '<td style="vertical-align:middle; padding-right:10px;"><span style="display:inline-block; width:10px; height:10px; border-radius:999px; background:#b38728;"></span></td>'
            . '<td style="vertical-align:middle; font-size:11px; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; color:#7c5a10;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
            . '</td>'
            . '</tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>';
    }

    private static function renderSecurityEmailLayout(string $title, string $intro, string $bodyHtml): string
    {
        $homeUrl = self::buildAbsoluteUrl();
        $brandWordmark = '<span style="display:inline-block; padding:10px 18px; border-radius:999px; background:#0f172a; color:#f6d37a; font-size:22px; font-weight:800; letter-spacing:0.12em;">BANAMUR</span>';

        return '<!DOCTYPE html>'
            . '<html lang="fr">'
            . '<head>'
            . '  <meta charset="UTF-8" />'
            . '  <meta name="viewport" content="width=device-width, initial-scale=1.0" />'
            . '  <title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
            . '</head>'
            . '<body style="margin:0; padding:0; background:#f4f6f8; font-family:Arial, sans-serif; color:#111827;">'
            . '  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f8;">'
            . '    <tr><td align="center" style="padding:30px 16px;">'
            . '      <table width="640" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff; border-radius:24px; overflow:hidden; border:1px solid #e2e8f0;">'
            . '        <tr><td style="padding:0; background:#c89a2b; height:6px; line-height:6px; font-size:0;">&nbsp;</td></tr>'
            . '        <tr><td style="background:#fffaf0; padding:28px 32px 26px; border-bottom:1px solid #efe3bf;">'
            . '          <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">'
            . '            <tr>'
            . '              <td style="vertical-align:top;">'
            . '                <a href="' . htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="display:inline-block; text-decoration:none;">' . $brandWordmark . '</a>'
            . '                <div style="margin-top:12px; font-size:12px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#7c5a10;">Plateforme interne</div>'
            . '                <div style="margin-top:10px; max-width:430px; font-size:14px; color:#475569; line-height:1.7;">' . $intro . '</div>'
            . '              </td>'
            . '              <td style="vertical-align:top; text-align:right; white-space:nowrap;">'
            . '                <div style="display:inline-block; padding:10px 14px; border-radius:16px; background:#ffffff; border:1px solid #ead9ac; font-size:11px; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; color:#7c5a10;">Support BANAMUR</div>'
            . '              </td>'
            . '            </tr>'
            . '          </table>'
            . '        </td></tr>'
            . '        <tr><td style="padding:30px 40px 28px 40px;">'
            .              self::renderEmailHeaderBadge()
            . '          <h1 style="font-size:24px; margin:0 0 14px; color:#0f172a; font-weight:800; line-height:1.3;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '          <div style="height:1px; background:#edf2f7; margin:0 0 22px;"></div>'
            .              $bodyHtml
            . '          <p style="margin:24px 0 0; color:#6b7280; line-height:1.6; font-size:13px;">© ' . date('Y') . ' BANAMUR. Message automatique du support interne.</p>'
            . '        </td></tr>'
            . '      </table>'
            . '    </td></tr>'
            . '  </table>'
            . '</body>'
            . '</html>';
    }

    public static function buildFineaNotificationEmail(string $subject, string $bodyHtml, string $link = '', array $options = []): string
    {
        $resolvedUrl = '';
        if (trim($link) !== '') {
            $resolvedUrl = preg_match('#^https?://#i', $link) === 1
                ? $link
                : self::buildAbsoluteUrl(ltrim($link, '/'));
        }

        $logoUrl = self::buildAbsoluteUrl('branding_asset.php?type=logo');
        $subtitle = trim((string) ($options['subtitle'] ?? 'Notification Banamur harmonisee avec la charte visuelle Finea pour une lecture plus claire sur ordinateur comme sur mobile.'));
        $repereTitle = trim((string) ($options['repere_title'] ?? 'Repere'));
        $repereText = trim((string) ($options['repere_text'] ?? 'Vous recevez cet email car une notification systeme a ete emise dans votre espace de travail Banamur.'));
        $headerBadge = trim((string) ($options['header_badge'] ?? 'Signature Finea'));
        $buttonLabel = trim((string) ($options['button_label'] ?? 'Ouvrir la notification'));
        $footerText = trim((string) ($options['footer_text'] ?? ('© ' . date('Y') . ' Banamur. Notification automatique au format Finea.')));
        $cardsHtml = (string) ($options['cards_html'] ?? '');
        $showDefaultButton = array_key_exists('show_default_button', $options) ? (bool) $options['show_default_button'] : true;

        $buttonHtml = '';
        if ($showDefaultButton && $resolvedUrl !== '') {
            $buttonHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;">'
                . '<tr>'
                . '<td style="border-radius:14px; background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);">'
                . '<a href="' . htmlspecialchars($resolvedUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="display:inline-block; padding:14px 22px; color:#ffffff; text-decoration:none; font-size:14px; font-weight:700;">' . htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') . '</a>'
                . '</td>'
                . '</tr>'
                . '</table>';
        }

        return '<!DOCTYPE html>'
            . '<html lang="fr">'
            . '<head>'
            . '  <meta charset="UTF-8" />'
            . '  <meta name="viewport" content="width=device-width, initial-scale=1.0" />'
            . '  <title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title>'
            . '</head>'
            . '<body style="margin:0; padding:0; background:#eef2f7; font-family:Arial,sans-serif; color:#0f172a;">'
            . '  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:radial-gradient(circle at top,#f8fafc 0%,#eef2f7 52%,#e2e8f0 100%);">'
            . '    <tr>'
            . '      <td align="center" style="padding:28px 16px;">'
            . '        <table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="max-width:680px; width:100%; background:#ffffff; border-radius:26px; overflow:hidden; border:1px solid #dbe4ee; box-shadow:0 20px 55px rgba(15,23,42,0.14);">'
            . '          <tr>'
            . '            <td style="padding:26px 30px; background:linear-gradient(135deg,#020617 0%,#111827 60%,#1d4ed8 100%);">'
            . '              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '                <tr>'
            . '                  <td style="vertical-align:top;">'
            . '                    <div style="display:inline-block; padding:7px 12px; border-radius:999px; background:rgba(255,255,255,0.10); border:1px solid rgba(255,255,255,0.18); font-size:11px; letter-spacing:0.16em; text-transform:uppercase; font-weight:700; color:#f8fafc;">' . htmlspecialchars($headerBadge, ENT_QUOTES, 'UTF-8') . '</div>'
            . '                    <h1 style="margin:16px 0 8px; font-size:25px; line-height:1.25; color:#ffffff;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '                    <p style="margin:0; color:#cbd5e1; font-size:13px; line-height:1.7;">' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>'
            . '                  </td>'
            . '                  <td align="right" style="vertical-align:top; padding-left:18px;">'
            . '                    <img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Finea" style="display:block; height:38px; width:auto; max-width:160px;" />'
            . '                  </td>'
            . '                </tr>'
            . '              </table>'
            . '            </td>'
            . '          </tr>'
            . '          <tr>'
            . '            <td style="padding:30px;">'
            . '              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">'
            . '                <tr>'
            . '                  <td style="padding:14px 16px; border:1px solid #e2e8f0; border-radius:18px; background:#f8fafc;">'
            . '                    <div style="font-size:12px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#7c3aed; margin-bottom:6px;">' . htmlspecialchars($repereTitle, ENT_QUOTES, 'UTF-8') . '</div>'
            . '                    <div style="font-size:14px; line-height:1.7; color:#475569;">' . htmlspecialchars($repereText, ENT_QUOTES, 'UTF-8') . '</div>'
            . '                  </td>'
            . '                </tr>'
            . '              </table>'
            .                $cardsHtml
            .                $bodyHtml
            .                $buttonHtml
            . '              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;">'
            . '                <tr>'
            . '                  <td style="padding-top:18px; border-top:1px solid #e2e8f0; font-size:12px; color:#64748b; line-height:1.7;">'
            . '                    Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br />'
            .                      ($resolvedUrl !== '' ? htmlspecialchars($resolvedUrl, ENT_QUOTES, 'UTF-8') : 'Lien non disponible pour cette notification.')
            . '                  </td>'
            . '                </tr>'
            . '              </table>'
            . '            </td>'
            . '          </tr>'
            . '        </table>'
            . '        <div style="max-width:680px; margin:14px auto 0; font-size:12px; color:#64748b; line-height:1.6;">' . htmlspecialchars($footerText, ENT_QUOTES, 'UTF-8') . '</div>'
            . '      </td>'
            . '    </tr>'
            . '  </table>'
            . '</body>'
            . '</html>';
    }

    /**
     * Envoie un email via SMTP.
     *
     * Options supportées:
     * - cc: [['email' => 'a@b.c', 'name' => 'Nom'], ...]
     * - bcc: même format que cc
     * - attachments: [['name' => 'fichier.pdf', 'content' => $bytes, 'type' => 'application/pdf'], ['path' => '/abs/path/file.pdf']]
     * - disable_tracking: true pour ne pas injecter le pixel
     * - from_name: nom d'expéditeur personnalisé
     */
    public static function send(string $to, string $toName, string $subject, string $htmlBody, ?string $plainTextBody = null, array $options = []): bool
    {
        self::$lastEmailLogId = null;
        self::ensureLogTable();

        $smtpHost = defined('SMTP_HOST') ? (string) SMTP_HOST : 'smtp.ionos.fr';
        $smtpPort = defined('SMTP_PORT') ? (int) SMTP_PORT : 465;
        $smtpUser = self::normalizeConfiguredEmail(defined('SMTP_USER') ? (string) SMTP_USER : '');
        $smtpPass = defined('SMTP_PASS') ? (string) SMTP_PASS : '';
        $smtpFrom = self::normalizeConfiguredEmail(
            defined('SMTP_FROM') && trim((string) SMTP_FROM) !== '' ? (string) SMTP_FROM : $smtpUser
        );
        if ($smtpFrom === '') {
            $smtpFrom = $smtpUser;
        }
        $smtpFromName = defined('SMTP_DEFAULT_FROM_NAME') && trim((string) SMTP_DEFAULT_FROM_NAME) !== ''
            ? (string) SMTP_DEFAULT_FROM_NAME
            : 'Banamur Support';
        $smtpSecure = defined('SMTP_SECURE') ? (string) SMTP_SECURE : 'ssl';
        $smtpAuth = !defined('SMTP_AUTH') || (bool) SMTP_AUTH;

        $trackingToken = bin2hex(random_bytes(16));

        if ($smtpAuth && ($smtpUser === '' || $smtpPass === '')) {
            self::logFailedEmailWithoutSmtp($to, $toName, $subject, $htmlBody, $plainTextBody, $trackingToken, 'Configuration SMTP incomplete: SMTP_USER ou SMTP_PASS manquant.');
            return false;
        }

        if (self::isSmtpAuthCooldownActive()) {
            self::logFailedEmailWithoutSmtp($to, $toName, $subject, $htmlBody, $plainTextBody, $trackingToken, 'SMTP temporairement suspendu apres une erreur d authentification recente.');
            return false;
        }

        $mail = new PHPMailer(true);

        try {

            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->SMTPAuth = $smtpAuth;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = $smtpSecure !== '' ? $smtpSecure : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $smtpPort;

            $mail->CharSet = 'UTF-8';
            $mail->Encoding = PHPMailer::ENCODING_BASE64;

            $mail->setFrom($smtpFrom, trim((string) ($options['from_name'] ?? $smtpFromName)) ?: $smtpFromName);
            $mail->addAddress($to, $toName);
            foreach (self::normalizeRecipientList($options['cc'] ?? []) as $recipient) {
                $mail->addCC($recipient['email'], $recipient['name']);
            }
            foreach (self::normalizeRecipientList($options['bcc'] ?? []) as $recipient) {
                $mail->addBCC($recipient['email'], $recipient['name']);
            }
            foreach ((array)($options['attachments'] ?? []) as $attachment) {
                if (!is_array($attachment)) {
                    continue;
                }
                $attachmentName = trim((string)($attachment['name'] ?? 'piece-jointe'));
                $attachmentType = trim((string)($attachment['type'] ?? 'application/octet-stream'));
                if (!empty($attachment['content'])) {
                    $mail->addStringAttachment((string)$attachment['content'], $attachmentName, PHPMailer::ENCODING_BASE64, $attachmentType);
                    continue;
                }
                $attachmentPath = trim((string)($attachment['path'] ?? ''));
                if ($attachmentPath !== '' && is_file($attachmentPath)) {
                    $mail->addAttachment($attachmentPath, $attachmentName !== '' ? $attachmentName : basename($attachmentPath));
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;

            if (empty($options['disable_tracking'])) {
                $trackingUrl = self::resolveBaseUrl() . '/tracking_pixel.php?t=' . $trackingToken;
                $htmlBody .= '<img src="' . $trackingUrl . '" width="1" height="1" style="display:none;">';
            }

            $mail->Body = $htmlBody;
            $mail->AltBody = $plainTextBody ?? strip_tags($htmlBody);

            $sent = $mail->send();

            self::logEmail([

                'recipient_email' => $to,
                'recipient_name' => $toName,
                'subject' => $subject,
                'body_html' => $htmlBody,
                'body_plain' => $plainTextBody,
                'status' => $sent ? 'sent' : 'failed',
                'error_message' => null,
                'sent_at' => date('Y-m-d H:i:s'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'tracking_token' => $trackingToken

            ]);

            return $sent;
        } catch (Exception $e) {
            if (stripos($e->getMessage(), 'authenticate') !== false) {
                self::rememberSmtpAuthFailure();
            }

            self::logEmail([

                'recipient_email' => $to,
                'recipient_name' => $toName,
                'subject' => $subject,
                'body_html' => $htmlBody,
                'body_plain' => $plainTextBody,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'sent_at' => date('Y-m-d H:i:s'),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'tracking_token' => $trackingToken

            ]);

            error_log('Mailer error: ' . $e->getMessage());

            return false;
        }
    }

    public static function getLastEmailLogId(): ?int
    {
        return self::$lastEmailLogId;
    }

    private static function normalizeConfiguredEmail(string $value): string
    {
        $value = trim($value, " \t\n\r\0\x0B\"'`*<>");
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $value;
        }

        return '';
    }

    private static function logFailedEmailWithoutSmtp(string $to, string $toName, string $subject, string $htmlBody, ?string $plainTextBody, string $trackingToken, string $errorMessage): void
    {
        self::logEmail([
            'recipient_email' => $to,
            'recipient_name' => $toName,
            'subject' => $subject,
            'body_html' => $htmlBody,
            'body_plain' => $plainTextBody,
            'status' => 'failed',
            'error_message' => $errorMessage,
            'sent_at' => date('Y-m-d H:i:s'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'tracking_token' => $trackingToken,
        ]);
    }

    private static function isSmtpAuthCooldownActive(): bool
    {
        $path = self::smtpAuthCooldownPath();
        if ($path === '' || !is_file($path)) {
            return false;
        }

        $lastFailureAt = (int) trim((string) @file_get_contents($path));
        if ($lastFailureAt <= 0) {
            return false;
        }

        return (time() - $lastFailureAt) < self::SMTP_AUTH_FAILURE_COOLDOWN_SECONDS;
    }

    private static function rememberSmtpAuthFailure(): void
    {
        $path = self::smtpAuthCooldownPath();
        if ($path === '') {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        @file_put_contents($path, (string) time(), LOCK_EX);
    }

    private static function smtpAuthCooldownPath(): string
    {
        $dir = dirname(__DIR__) . '/storage/locks';
        return $dir . '/smtp_auth_failure.lock';
    }

    private static function resolveBaseUrl(): string
    {
        $baseUrl = trim((string) \EnvConfig::get('APP_BASE_URL', ''));
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        if ($host === '') {
            $host = 'localhost';
        }

        return $scheme . '://' . $host . self::resolveApplicationBasePath();
    }

    private static function resolveApplicationBasePath(): string
    {
        $documentRoot = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $projectRoot = dirname(__DIR__);

        if ($documentRoot === '' || $projectRoot === '') {
            return '';
        }

        $normalizedDocumentRoot = rtrim(str_replace('\\', '/', (string) realpath($documentRoot)), '/');
        $normalizedProjectRoot = rtrim(str_replace('\\', '/', (string) realpath($projectRoot)), '/');

        if ($normalizedDocumentRoot === '' || $normalizedProjectRoot === '') {
            return '';
        }

        if (stripos($normalizedProjectRoot, $normalizedDocumentRoot) !== 0) {
            return '';
        }

        $relativePath = trim(substr($normalizedProjectRoot, strlen($normalizedDocumentRoot)), '/');
        return $relativePath === '' ? '' : ('/' . $relativePath);
    }

    private static function normalizeRecipientList(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $email = trim($item);
                if ($email !== '') {
                    $normalized[] = ['email' => $email, 'name' => ''];
                }
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            $email = trim((string)($item['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $normalized[] = [
                'email' => $email,
                'name' => trim((string)($item['name'] ?? '')),
            ];
        }
        return $normalized;
    }

    /**
     * Envoie un email de bienvenue à un nouvel utilisateur.
     *
     * @param array $user
     * @param string $plainPassword
     * @return bool
     */
    public static function sendWelcomeEmail(array $user, string $plainPassword): bool
    {
        $to = $user['mail_pro'] ?? '';
        $name = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
        $subject = 'Bienvenue sur l’outil BANAMUR';

        $loginUrl = self::buildAbsoluteUrl('login.php');
        $homeUrl = self::buildAbsoluteUrl();

        // Tracking pixel (optionnel)
        $trackingUrl = self::buildAbsoluteUrl('tracking_pixel.php?e=' . urlencode($to));

        $html = self::renderSecurityEmailLayout(
            'Bienvenue dans votre espace',
            'Vos informations d\'accès à l\'espace BANAMUR sont prêtes. Vous pouvez vous connecter et finaliser votre première activation.',
            '          <p style="margin:0 0 14px; color:#333; line-height:1.6;">Bonjour ' . htmlspecialchars($name !== '' ? $name : $to, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '          <p style="margin:0 0 18px; color:#333; line-height:1.6;">Votre compte a été créé avec succès. Voici vos informations de connexion initiales.</p>'
                . '          <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0 22px;">'
                . '            <tr>'
                . '              <td style="padding:16px 18px; background:#eff6ff; border-radius:16px; border:1px solid rgba(59,130,246,0.18);">'
                . '                <div style="font-size:14px; color:#1f2937; line-height:1.6;">'
                . '                  <strong style="display:inline-block; min-width:96px;">Email :</strong> ' . htmlspecialchars($to, ENT_QUOTES, 'UTF-8') . '<br />'
                . '                  <strong style="display:inline-block; min-width:96px;">Mot de passe :</strong> ' . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8')
                . '                </div>'
                . '              </td>'
                . '            </tr>'
                . '          </table>'
                . '          <p style="margin:0 0 16px; color:#333; line-height:1.6;">Cliquez sur le bouton ci-dessous pour vous connecter.</p>'
                . '          <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto 26px;">'
                . '            <tr>'
                . '              <td align="center" bgcolor="#111827" style="border-radius:14px;">'
                . '                <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="display:inline-block; padding:15px 26px; color:#ffffff; text-decoration:none; font-weight:700; font-size:15px; line-height:1.2; letter-spacing:0.02em;">Se connecter</a>'
                . '              </td>'
                . '            </tr>'
                . '          </table>'
                . '          <p style="margin:0 0 14px; color:#475569; line-height:1.6;">Le bouton ouvre directement votre page de connexion sécurisée BANAMUR.</p>'
                . '          <p style="margin:0 0 14px; color:#4b5563; line-height:1.6;">Ce mot de passe est valable 24 heures. Pour votre sécurité, pensez à le modifier après votre première connexion.</p>'
                . '          <p style="margin:0 0 22px; color:#4b5563; line-height:1.6;">Besoin d’aide ? Contactez-nous : <a href="mailto:ulrich@banamur.com" style="color:#0f172a; font-weight:600; text-decoration:none;">ulrich@banamur.com</a></p>'
        ) . '<img src="' . htmlspecialchars($trackingUrl, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" style="display:block; border:0; margin:0; padding:0;" alt="" />';

        $plain = "Bienvenue, " . trim($name) . "\n" .
            "Votre compte a été créé avec succès. Voici vos informations de connexion :\n\n" .
            "Email : " . $to . "\n" .
            "Mot de passe : " . $plainPassword . "\n\n" .
            "Connectez-vous ici : " . $loginUrl . "\n\n" .
            "Ce mot de passe est valable 24 heures. Pour votre sécurité, pensez à le modifier après votre première connexion.\n\n" .
            "Bonne utilisation !\nSupport BANAMUR\n";

        return self::send($to, $name, $subject, $html, $plain);
    }


    /**
     * Envoie un email de réinitialisation de mot de passe.
     *
     * @param array $user
     * @param string $resetUrl
     * @param int $expiresInMinutes
     * @return bool
     */
    public static function sendPasswordResetEmail(array $user, string $resetUrl, int $expiresInMinutes = 60): bool
    {
        $to = $user['mail_pro'] ?? '';
        $name = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
        $subject = 'Réinitialisation de votre mot de passe - BANAMUR';

        $expiresLabel = (int)$expiresInMinutes . ' minute' . ((int)$expiresInMinutes > 1 ? 's' : '');

        $html = self::renderSecurityEmailLayout(
            'Réinitialisation de mot de passe',
            'Une demande de réinitialisation a été enregistrée. Utilisez le lien ci-dessous pour définir un nouveau mot de passe.',
            '          <p style="margin:0 0 14px; color:#333; line-height:1.6;">Bonjour ' . htmlspecialchars($name !== '' ? $name : $to, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '          <p style="margin:0 0 18px; color:#333; line-height:1.6;">Nous avons reçu une demande de réinitialisation de votre mot de passe. Cliquez sur le bouton ci-dessous pour définir un nouveau mot de passe.</p>'
                . '          <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto 20px;">'
                . '            <tr>'
                . '              <td align="center" bgcolor="#111827" style="border-radius:14px;">'
                . '                <a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="display:inline-block; padding:15px 26px; color:#ffffff; text-decoration:none; font-weight:700; font-size:15px; line-height:1.2; letter-spacing:0.02em;">Réinitialiser mon mot de passe</a>'
                . '              </td>'
                . '            </tr>'
                . '          </table>'
                . '          <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 18px;">'
                . '            <tr>'
                . '              <td style="padding:14px 16px; background:#eff6ff; border-radius:16px; border:1px solid rgba(59,130,246,0.18); font-size:13px; color:#1f2937; line-height:1.5;">'
                . '                Ce lien est valable <strong>' . htmlspecialchars($expiresLabel, ENT_QUOTES, 'UTF-8') . '</strong> et ne peut être utilisé qu\'une seule fois.'
                . '              </td>'
                . '            </tr>'
                . '          </table>'
                . '          <p style="margin:0 0 14px; color:#475569; line-height:1.6; font-size:13px;">Le lien ouvre la page sécurisée BANAMUR de mise à jour du mot de passe.</p>'
                . '          <p style="margin:0 0 14px; color:#4b5563; line-height:1.6; font-size:13px;">Si vous n\'êtes pas à l\'origine de cette demande, vous pouvez ignorer cet e-mail. Votre mot de passe ne sera pas modifié.</p>'
                . '          <p style="margin:0 0 22px; color:#4b5563; line-height:1.6;">Besoin d’aide ? Contactez-nous : <a href="mailto:ulrich@banamur.com" style="color:#0f172a; font-weight:600; text-decoration:none;">ulrich@banamur.com</a></p>'
        );

        $plain = "Bonjour " . trim($name !== '' ? $name : $to) . "\n\n" .
            "Pour réinitialiser votre mot de passe, ouvrez ce lien (valable " . $expiresLabel . ") :\n" .
            $resetUrl . "\n\n" .
            "Si vous n'êtes pas à l'origine de cette demande, ignorez cet e-mail.\n";

        return self::send($to, $name, $subject, $html, $plain);
    }


    /**
     * Envoie un email de déblocage de compte après trop d'essais de connexion.
     */
    public static function sendLoginUnlockEmail(array $user, string $unlockUrl, int $expiresInMinutes = 60): bool
    {
        $to = $user['mail_pro'] ?? '';
        $name = trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
        $subject = 'Déblocage de votre compte - BANAMUR';

        $expiresLabel = (int)$expiresInMinutes . ' minute' . ((int)$expiresInMinutes > 1 ? 's' : '');

        $html = self::renderSecurityEmailLayout(
            'Déblocage de compte',
            'Votre compte nécessite une confirmation par email avant de pouvoir être réutilisé.',
            '          <p style="margin:0 0 14px; color:#333; line-height:1.6;">Bonjour ' . htmlspecialchars($name !== '' ? $name : $to, ENT_QUOTES, 'UTF-8') . ',</p>'
                . '          <p style="margin:0 0 18px; color:#333; line-height:1.6;">Votre compte a été bloqué suite à trop de tentatives de connexion erronées. Pour des raisons de sécurité, vous devez confirmer par e-mail pour le débloquer.</p>'
                . '          <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin:0 auto 20px;">'
                . '            <tr>'
                . '              <td align="center" bgcolor="#111827" style="border-radius:14px;">'
                . '                <a href="' . htmlspecialchars($unlockUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="display:inline-block; padding:15px 26px; color:#ffffff; text-decoration:none; font-weight:700; font-size:15px; line-height:1.2; letter-spacing:0.02em;">Débloquer mon compte</a>'
                . '              </td>'
                . '            </tr>'
                . '          </table>'
                . '          <table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:0 0 18px;">'
                . '            <tr>'
                . '              <td style="padding:14px 16px; background:#fef2f2; border-radius:16px; border:1px solid rgba(239,68,68,0.18); font-size:13px; color:#1f2937; line-height:1.5;">'
                . '                Ce lien est valable <strong>' . htmlspecialchars($expiresLabel, ENT_QUOTES, 'UTF-8') . '</strong> et ne peut être utilisé qu\'une seule fois.'
                . '              </td>'
                . '            </tr>'
                . '          </table>'
                . '          <p style="margin:0 0 14px; color:#475569; line-height:1.6; font-size:13px;">Le bouton ouvre directement la page sécurisée de déblocage BANAMUR.</p>'
                . '          <p style="margin:0 0 14px; color:#4b5563; line-height:1.6; font-size:13px;">Si ce n\'était pas vous, nous vous conseillons de contacter l\'administrateur et de changer votre mot de passe.</p>'
                . '          <p style="margin:0 0 22px; color:#4b5563; line-height:1.6;">Besoin d’aide ? Contactez-nous : <a href="mailto:ulrich@banamur.com" style="color:#0f172a; font-weight:600; text-decoration:none;">ulrich@banamur.com</a></p>'
        );

        $plain = "Bonjour " . trim($name !== '' ? $name : $to) . "\n\n" .
            "Votre compte a été bloqué suite à trop de tentatives de connexion erronées.\n" .
            "Pour le débloquer, ouvrez ce lien (valable " . $expiresLabel . ") :\n" .
            $unlockUrl . "\n";

        return self::send($to, $name, $subject, $html, $plain);
    }


    /**
     * Summary of ensureLogTable
     * @return void
     */
    private static function ensureLogTable()
    {
        (new EmailLogRepository())->ensureLogTable();
    }

    private static function logEmail($data)
    {
        self::$lastEmailLogId = (new EmailLogRepository())->create($data);
    }
}
