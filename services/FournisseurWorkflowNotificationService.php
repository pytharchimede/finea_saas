<?php

require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/NotificationEmailChannelService.php';
require_once __DIR__ . '/../repositories/FournisseurWorkflowNotificationRepository.php';

class FournisseurWorkflowNotificationService
{
    private FournisseurWorkflowNotificationRepository $repository;
    private \NotificationController $notificationManager;
    private NotificationEmailChannelService $emailChannel;

    public function __construct(PDO $pdo, ?FournisseurWorkflowNotificationRepository $repository = null)
    {
        $this->repository = $repository ?: new FournisseurWorkflowNotificationRepository($pdo);
        $this->notificationManager = new \NotificationController($pdo);
        $this->emailChannel = new NotificationEmailChannelService();
        $this->notificationManager->ensureDefaultRulesForSupplierWorkflow();
    }

    public function notifySubmitted(array $supplier, array $actor, string $reason = 'created'): void
    {
        $supplierInfo = $this->normalizeSupplier($supplier);
        if ($supplierInfo['id'] <= 0) {
            return;
        }

        $actorName = $this->resolveActorName($actor);
        $verb = $reason === 'updated' ? 'a mis à jour' : 'a créé';
        $title = 'Fournisseur à valider : ' . $supplierInfo['name'];
        $message = $actorName !== ''
            ? ($actorName . ' ' . $verb . ' le fournisseur ' . $supplierInfo['name'] . ' et l\'a envoyé en validation.')
            : ('Le fournisseur ' . $supplierInfo['name'] . ' a été envoyé en validation.');

        $payload = [
            'supplier_id' => $supplierInfo['id'],
            'supplier_code' => $supplierInfo['code'],
            'supplier_status' => 'pending',
            'reason' => $reason,
            'actor_user_id' => (int) ($actor['user_id'] ?? 0),
            'actor_name' => $actorName,
        ];

        $this->dispatchOversight(
            'supplier_workflow',
            'submitted',
            $title,
            $message,
            $this->buildSupplierLink($supplierInfo['id']),
            $supplierInfo,
            $payload,
            '',
            [
                'supplier_name' => $supplierInfo['name'],
                'supplier_code' => $supplierInfo['code'],
                'actor_name' => $actorName,
                'validation_note' => '',
                'decision_label' => 'soumis',
            ]
        );
    }

    public function notifyProcessed(array $supplier, string $decision, array $actor, string $note = ''): void
    {
        $supplierInfo = $this->normalizeSupplier($supplier);
        if ($supplierInfo['id'] <= 0) {
            return;
        }

        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approve', 'approved', 'reject', 'rejected'], true)) {
            return;
        }

        $isApproved = in_array($decision, ['approve', 'approved'], true);
        $statusKey = $isApproved ? 'approved' : 'rejected';
        $statusLabel = $isApproved ? 'validé' : 'rejeté';
        $actorName = $this->resolveActorName($actor);

        $title = 'Fournisseur ' . $statusLabel . ' : ' . $supplierInfo['name'];
        $message = $actorName !== ''
            ? ($actorName . ' a ' . $statusLabel . ' le fournisseur ' . $supplierInfo['name'] . '.')
            : ('Le fournisseur ' . $supplierInfo['name'] . ' a été ' . $statusLabel . '.');
        if (trim($note) !== '') {
            $message .= ' Motif : ' . trim($note);
        }

        $payload = [
            'supplier_id' => $supplierInfo['id'],
            'supplier_code' => $supplierInfo['code'],
            'supplier_status' => $statusKey,
            'decision' => $statusKey,
            'validation_note' => trim($note),
            'actor_user_id' => (int) ($actor['user_id'] ?? 0),
            'actor_name' => $actorName,
        ];

        $link = $this->buildSupplierLink($supplierInfo['id']);
        $ruleContext = [
            'supplier_name' => $supplierInfo['name'],
            'supplier_code' => $supplierInfo['code'],
            'actor_name' => $actorName,
            'validation_note' => trim($note),
            'decision_label' => $isApproved ? 'validé' : 'rejeté',
        ];

        $this->dispatchOversight('supplier_workflow', $statusKey, $title, $message, $link, $supplierInfo, $payload, trim($note), $ruleContext);

        $creator = $this->getUserById((int) ($supplierInfo['created_by_user_id'] ?? 0));
        if ($creator) {
            $requesterTitle = 'Votre fournisseur est ' . ($isApproved ? 'validé' : 'rejeté') . ' : ' . $supplierInfo['name'];
            $requesterMessage = $isApproved
                ? ('Votre fournisseur ' . $supplierInfo['name'] . ' a été validé.')
                : ('Votre fournisseur ' . $supplierInfo['name'] . ' a été rejeté.' . (trim($note) !== '' ? (' Motif : ' . trim($note)) : ''));

            $rule = $this->notificationManager->getRule('supplier_workflow', $statusKey);
            if ($rule['is_enabled']) {
                [$requesterTitle, $requesterMessage] = $this->resolveRuleContent($rule, $requesterTitle, $requesterMessage, $ruleContext);
                $channels = (array) ($rule['channels'] ?? ['system']);
                $excludedEmails = (array) ($rule['excluded_emails'] ?? []);

                if (in_array('system', $channels, true)) {
                    $this->createSystemNotification('supplier_workflow', $statusKey, $requesterTitle, $requesterMessage, $link, (int) $creator['user_id'], $payload + ['recipient_scope' => 'requester'], $channels);
                }
                if (in_array('email', $channels, true)) {
                    $this->sendEmailNotification($creator, $requesterTitle, $requesterMessage, $link, $supplierInfo, trim($note), 'Demandeur', $excludedEmails);
                }
            }
        }
    }

    private function dispatchOversight(string $eventKey, string $subKey, string $title, string $message, string $link, array $supplierInfo, array $payload, string $note, array $ruleContext = []): void
    {
        $rule = $this->notificationManager->getRule($eventKey, $subKey);
        if (!$rule['is_enabled']) {
            return;
        }

        [$title, $message] = $this->resolveRuleContent($rule, $title, $message, $ruleContext);
        $channels = (array) ($rule['channels'] ?? ['system']);
        $excludedEmails = (array) ($rule['excluded_emails'] ?? []);

        foreach ($this->listOversightRecipients() as $recipient) {
            $scopeLabel = $this->humanizeScopeLabel((string) ($recipient['scope'] ?? 'notification'));
            if (in_array('system', $channels, true)) {
                $this->createSystemNotification(
                    $eventKey,
                    $subKey,
                    $title,
                    $message,
                    $link,
                    (int) ($recipient['user_id'] ?? 0),
                    $payload + ['recipient_scope' => (string) ($recipient['scope'] ?? '')],
                    $channels
                );
            }
            if (in_array('email', $channels, true)) {
                $this->sendEmailNotification($recipient, $title, $message, $link, $supplierInfo, $note, $scopeLabel, $excludedEmails);
            }
        }
    }

    private function createSystemNotification(string $eventKey, string $subKey, string $title, string $message, string $link, int $recipientUserId, array $payload, array $channels = ['system']): void
    {
        $this->repository->createSystemNotification($eventKey, $subKey, $title, $message, $link, $recipientUserId, $payload, $channels);
    }

    private function sendEmailNotification(array $recipient, string $subject, string $message, string $link, array $supplierInfo, string $note, string $scopeLabel, array $excludedEmails = []): void
    {
        $email = trim((string) ($recipient['email'] ?? ''));
        if ($email === '' || $this->isEmailExcluded($email, $excludedEmails)) {
            return;
        }

        $bodyHtml = $this->buildEmailBody($message, $supplierInfo, $note, $scopeLabel);
        $html = $this->emailChannel->buildFineaEmail(
            $subject,
            $bodyHtml,
            $link,
            [
                'header_badge' => 'Workflow fournisseur',
                'repere_title' => 'Circuit de validation',
                'repere_text' => 'Cette alerte suit le workflow fournisseur entre la comptabilité, l\'administration et la direction générale.',
                'button_label' => 'Ouvrir le fournisseur',
                'subtitle' => 'Notification fournisseur envoyée automatiquement par BANAMUR.',
            ]
        );

        try {
            $this->emailChannel->send($email, (string) ($recipient['name'] ?? $email), $subject, $html, strip_tags($message), [
                'notification_user_id' => (int) ($recipient['user_id'] ?? 0),
                'notification_preference_keys' => ['supplier_portal'],
            ]);
        } catch (Throwable $e) {
        }
    }

    private function buildEmailBody(string $message, array $supplierInfo, string $note, string $scopeLabel): string
    {
        $lines = [];
        $lines[] = '<p style="margin:0 0 14px; font-size:14px; line-height:1.7; color:#334155;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        $lines[] = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px; border-collapse:separate; border-spacing:0 10px;">';
        foreach ($this->buildEmailFacts($supplierInfo, $scopeLabel, $note) as $label => $value) {
            $lines[] = '<tr>'
                . '<td style="width:170px; padding:10px 14px; border-radius:14px 0 0 14px; background:#eff6ff; color:#1e3a8a; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td style="padding:10px 14px; border-radius:0 14px 14px 0; background:#f8fafc; color:#0f172a; font-size:14px;">' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }
        $lines[] = '</table>';

        return implode('', $lines);
    }

    private function buildEmailFacts(array $supplierInfo, string $scopeLabel, string $note): array
    {
        $facts = [
            'Périmètre' => $scopeLabel,
            'Fournisseur' => $supplierInfo['name'],
        ];

        if ($supplierInfo['code'] !== '') {
            $facts['Code'] = $supplierInfo['code'];
        }
        if ($supplierInfo['type_label'] !== '') {
            $facts['Type'] = $supplierInfo['type_label'];
        }
        if ($supplierInfo['categories_text'] !== '') {
            $facts['Catégories'] = $supplierInfo['categories_text'];
        }
        if ($note !== '') {
            $facts['Motif'] = $note;
        }

        return $facts;
    }

    private function listOversightRecipients(): array
    {
        $recipients = [];

        foreach ($this->listUsersByPermissionColumns(['acces_compta', 'acces_point_financier', 'acces_relink_fiches_debourse'], 'finance') as $recipient) {
            $recipients[$this->buildRecipientKey($recipient)] = $recipient;
        }
        foreach ($this->listUsersByPermissionColumns(['acces_admin'], 'admin') as $recipient) {
            $recipients[$this->buildRecipientKey($recipient)] = $recipient;
        }
        foreach ($this->listUsersByPermissionColumns(['acces_direction'], 'direction') as $recipient) {
            $recipients[$this->buildRecipientKey($recipient)] = $recipient;
        }
        foreach ($this->listConfiguredDirectionEmails() as $recipient) {
            $key = $this->buildRecipientKey($recipient);
            if (!isset($recipients[$key])) {
                $recipients[$key] = $recipient;
            }
        }

        return array_values($recipients);
    }

    private function buildRecipientKey(array $recipient): string
    {
        $email = strtolower(trim((string) ($recipient['email'] ?? '')));
        if ($email !== '') {
            return 'email:' . $email;
        }

        $userId = (int) ($recipient['user_id'] ?? 0);
        if ($userId > 0) {
            return 'user:' . $userId;
        }

        return 'email:unknown';
    }

    private function listUsersByPermissionColumns(array $columns, string $scope): array
    {
        return $this->repository->listUsersByPermissionColumns($columns, $scope);
    }

    private function listConfiguredDirectionEmails(): array
    {
        $configFile = __DIR__ . '/../config/point_financier_digest.php';
        if (!is_file($configFile)) {
            return [];
        }

        $config = require $configFile;
        if (!is_array($config)) {
            return [];
        }

        $rawRecipients = array_merge((array) ($config['to'] ?? []), (array) ($config['cc'] ?? []));
        $recipients = [];
        foreach ($rawRecipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            if ($email === '') {
                continue;
            }

            $recipients[] = [
                'user_id' => 0,
                'email' => $email,
                'name' => trim((string) ($recipient['name'] ?? $email)) ?: $email,
                'scope' => 'direction',
            ];
        }

        return $recipients;
    }

    private function getUserById(int $userId): ?array
    {
        return $this->repository->getUserById($userId);
    }

    private function normalizeSupplier(array $supplier): array
    {
        $type = strtolower(trim((string) ($supplier['supplier_type'] ?? '')));
        $typeLabel = $type === 'physique' ? 'Personne physique' : ($type === 'morale' ? 'Personne morale' : '');

        return [
            'id' => (int) ($supplier['id_fournisseur'] ?? $supplier['supplier_id'] ?? 0),
            'name' => trim((string) ($supplier['raison_sociale'] ?? $supplier['supplier_name'] ?? 'Fournisseur')),
            'code' => trim((string) ($supplier['code_fournisseur'] ?? '')),
            'type_label' => $typeLabel,
            'categories_text' => trim((string) ($supplier['category_names'] ?? $supplier['supplier_categories_text'] ?? '')),
            'created_by_user_id' => (int) ($supplier['created_by_user_id'] ?? 0),
        ];
    }

    private function buildSupplierLink(int $supplierId): string
    {
        return '/fournisseur/edit_fournisseur.php?id=' . $supplierId;
    }

    private function resolveActorName(array $actor): string
    {
        $name = trim((string) ($actor['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $firstName = trim((string) ($actor['prenom'] ?? ''));
        $lastName = trim((string) ($actor['nom'] ?? ''));
        return trim($firstName . ' ' . $lastName);
    }

    private function humanizeScopeLabel(string $scope): string
    {
        switch ($scope) {
            case 'finance':
                return 'Département comptable';
            case 'admin':
                return 'Administration';
            case 'direction':
                return 'Direction générale';
            case 'requester':
                return 'Demandeur';
            default:
                return 'Notification fournisseur';
        }
    }

    private function resolveRuleContent(array $rule, string $fallbackTitle, string $fallbackMessage, array $context): array
    {
        $titleTemplate = trim((string) ($rule['title_template'] ?? ''));
        $messageTemplate = trim((string) ($rule['message_template'] ?? ''));

        $title = $titleTemplate !== '' ? trim($this->renderTemplate($titleTemplate, $context)) : trim($fallbackTitle);
        $message = $messageTemplate !== '' ? trim($this->renderTemplate($messageTemplate, $context)) : trim($fallbackMessage);

        if ($title === '') {
            $title = trim($fallbackTitle);
        }
        if ($message === '') {
            $message = trim($fallbackMessage);
        }

        return [$title, $message];
    }

    private function renderTemplate(string $template, array $context): string
    {
        return (string) preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', static function (array $matches) use ($context): string {
            $key = (string) ($matches[1] ?? '');
            $value = $context[$key] ?? '';
            return is_scalar($value) || $value === null ? (string) ($value ?? '') : '';
        }, $template);
    }

    private function isEmailExcluded(string $email, array $excludedEmails): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        $normalized = [];
        foreach ($excludedEmails as $excludedEmail) {
            $candidate = strtolower(trim((string) $excludedEmail));
            if ($candidate !== '') {
                $normalized[] = $candidate;
            }
        }

        return in_array($email, array_values(array_unique($normalized)), true);
    }
}
