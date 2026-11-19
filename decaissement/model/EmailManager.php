<?php

require_once __DIR__ . '/../../services/NotificationEmailChannelService.php';

class EmailManager
{
    private NotificationEmailChannelService $emailChannel;

    public function __construct()
    {
        $this->emailChannel = new NotificationEmailChannelService();
    }

    public function sendEmail($subject, $body, $recipients = [], $cc = [], $bcc = [])
    {
        $recipientList = $this->normalizeRecipients((array) $recipients);
        if ($recipientList === []) {
            return false;
        }

        $ccList = $this->normalizeRecipients((array) $cc);
        $bccList = $this->normalizeRecipients((array) $bcc);

        $primary = array_shift($recipientList);
        $htmlBody = $this->looksLikeHtml((string) $body)
            ? (string) $body
            : nl2br(htmlspecialchars((string) $body, ENT_QUOTES, 'UTF-8'));
        $plainBody = trim(strip_tags((string) $body));

        return $this->emailChannel->send(
            (string) ($primary['email'] ?? ''),
            (string) ($primary['name'] ?? ''),
            (string) $subject,
            $htmlBody,
            $plainBody,
            [
                'cc' => array_merge($recipientList, $ccList),
                'bcc' => $bccList,
                'from_name' => 'SUPPORT FIDEST',
            ]
        );
    }

    private function normalizeRecipients(array $items): array
    {
        $normalized = [];
        foreach ($items as $email => $name) {
            if (is_array($name)) {
                $address = trim((string) ($name['email'] ?? ''));
                if ($address === '') {
                    continue;
                }
                $normalized[] = [
                    'email' => $address,
                    'name' => trim((string) ($name['name'] ?? '')),
                ];
                continue;
            }

            $address = is_string($email) ? trim($email) : '';
            if ($address === '' && is_string($name) && strpos($name, '@') !== false) {
                $address = trim($name);
                $name = '';
            }
            if ($address === '') {
                continue;
            }
            $normalized[] = [
                'email' => $address,
                'name' => is_string($name) ? trim($name) : '',
            ];
        }

        return $normalized;
    }

    private function looksLikeHtml(string $body): bool
    {
        return $body !== strip_tags($body);
    }
}
