<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditTrail.php';
require_once __DIR__ . '/../services/NotificationEmailChannelService.php';
require_once __DIR__ . '/AccessRequestSupportSettings.php';

class AccessRequestManager
{
    private PDO $pdo;
    private NotificationEmailChannelService $emailChannel;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->emailChannel = new NotificationEmailChannelService();
        $this->ensureTable();
    }

    public static function connect(): self
    {
        return new self(\Database::getConnection());
    }

    public function createRequest(array $payload): array
    {
        $requesterUserId = (int)($payload['requester_user_id'] ?? 0);
        if ($requesterUserId <= 0) {
            throw new RuntimeException('Utilisateur demandeur invalide');
        }

        $primaryPermission = trim((string)($payload['primary_permission'] ?? ''));
        if ($primaryPermission === '') {
            throw new RuntimeException('Permission principale manquante');
        }

        $requester = $this->findActiveUserById($requesterUserId);
        if (!$requester) {
            throw new RuntimeException('Utilisateur demandeur introuvable');
        }

        $adminToken = bin2hex(random_bytes(32));
        $adminTokenHash = hash('sha256', $adminToken);
        $requestedPage = trim((string)($payload['requested_page'] ?? ''));
        $requestedPageUrl = trim((string)($payload['requested_page_url'] ?? ''));
        $originUrl = trim((string)($payload['origin_url'] ?? ''));
        $alternativePermissions = array_values(array_filter((array)($payload['alternative_permissions'] ?? []), static function ($item): bool {
            return is_string($item) && trim($item) !== '';
        }));

        $stmt = $this->pdo->prepare(
            'INSERT INTO access_requests (
                requester_user_id, requester_name, requester_email,
                requested_page, requested_page_url, origin_url,
                primary_permission, primary_permission_label,
                alternative_permissions_json, access_type_label, module_label,
                status, admin_token_hash, admin_token_expires_at,
                created_at, updated_at
            ) VALUES (
                :requester_user_id, :requester_name, :requester_email,
                :requested_page, :requested_page_url, :origin_url,
                :primary_permission, :primary_permission_label,
                :alternative_permissions_json, :access_type_label, :module_label,
                :status, :admin_token_hash, :admin_token_expires_at,
                NOW(), NOW()
            )'
        );

        $stmt->execute([
            ':requester_user_id' => $requesterUserId,
            ':requester_name' => (string)$requester['display_name'],
            ':requester_email' => (string)$requester['email'],
            ':requested_page' => $requestedPage,
            ':requested_page_url' => $requestedPageUrl,
            ':origin_url' => $originUrl,
            ':primary_permission' => $primaryPermission,
            ':primary_permission_label' => (string)($payload['primary_permission_label'] ?? $primaryPermission),
            ':alternative_permissions_json' => json_encode($alternativePermissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':access_type_label' => (string)($payload['access_type_label'] ?? ''),
            ':module_label' => (string)($payload['module_label'] ?? ''),
            ':status' => 'pending',
            ':admin_token_hash' => $adminTokenHash,
            ':admin_token_expires_at' => date('Y-m-d H:i:s', time() + (7 * 24 * 3600)),
        ]);

        $requestId = (int)$this->pdo->lastInsertId();
        $request = $this->getRequestById($requestId);
        if (!$request) {
            throw new RuntimeException('Impossible de relire la demande d\'accès');
        }

        $request['admin_token'] = $adminToken;
        return $request;
    }

    public function sendSupportEmail(array $request): bool
    {
        $adminToken = trim((string)($request['admin_token'] ?? ''));
        if ($adminToken === '') {
            throw new RuntimeException('Jeton admin manquant pour l\'email');
        }

        $approveUrl = $this->buildAbsoluteUrl('admin/access_request_review.php?token=' . urlencode($adminToken) . '&intent=approve');
        $refuseUrl = $this->buildAbsoluteUrl('admin/access_request_review.php?token=' . urlencode($adminToken) . '&intent=refuse');
        $dashboardUrl = $this->buildAbsoluteUrl('admin/dashboard_admin.php');
        $requestedPage = (string)($request['requested_page'] ?? '');
        $primaryLabel = (string)($request['primary_permission_label'] ?? 'Accès à attribuer');
        $primaryCode = (string)($request['primary_permission'] ?? '');
        $alternatives = [];
        foreach ((array)($request['alternative_permissions'] ?? []) as $permission) {
            $alternatives[] = htmlspecialchars((string)$permission, ENT_QUOTES, 'UTF-8');
        }

        $alternativesHtml = $alternatives
            ? '<p style="margin:14px 0 0; color:#475569; line-height:1.6;"><strong>Accès alternatifs possibles :</strong> ' . implode(', ', $alternatives) . '</p>'
            : '';

        $html = '<p style="margin:0 0 16px; color:#334155; line-height:1.75;">'
            . 'Une demande d\'accès a été soumise sur BANAMUR. Vous pouvez accorder ou refuser cet accès directement depuis les boutons ci-dessous.'
            . '</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width:100%; border-collapse:separate; border-spacing:0 10px;">'
            . '<tr><td style="padding:14px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px;"><strong>Utilisateur</strong><br />' . htmlspecialchars((string)($request['requester_name'] ?? ''), ENT_QUOTES, 'UTF-8') . ' &lt;' . htmlspecialchars((string)($request['requester_email'] ?? ''), ENT_QUOTES, 'UTF-8') . '&gt;</td></tr>'
            . '<tr><td style="padding:14px 16px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:16px;"><strong>Page demandée</strong><br />' . htmlspecialchars($requestedPage, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            . '<tr><td style="padding:14px 16px; background:#ecfeff; border:1px solid #a5f3fc; border-radius:16px;"><strong>Accès recommandé</strong><br />' . htmlspecialchars($primaryLabel, ENT_QUOTES, 'UTF-8') . ($primaryCode !== '' ? ' <span style="color:#475569;">(' . htmlspecialchars($primaryCode, ENT_QUOTES, 'UTF-8') . ')</span>' : '') . '</td></tr>'
            . '</table>'
            . $alternativesHtml
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:22px;"><tr>'
            . '<td style="padding-right:12px;"><a href="' . htmlspecialchars($approveUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block; padding:14px 20px; border-radius:14px; background:linear-gradient(135deg,#0f766e,#2563eb); color:#ffffff; text-decoration:none; font-weight:700;">Accorder l\'accès</a></td>'
            . '<td style="padding-right:12px;"><a href="' . htmlspecialchars($refuseUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block; padding:14px 20px; border-radius:14px; background:linear-gradient(135deg,#dc2626,#f97316); color:#ffffff; text-decoration:none; font-weight:700;">Refuser la demande</a></td>'
            . '<td><a href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block; padding:14px 20px; border-radius:14px; background:#ffffff; border:1px solid #cbd5e1; color:#0f172a; text-decoration:none; font-weight:700;">Ouvrir le dashboard admin</a></td>'
            . '</tr></table>'
            . '<p style="margin:22px 0 0; color:#64748b; line-height:1.65; font-size:13px;">Le lien ouvre une page sécurisée. Si vous n\'êtes pas connecté, la redirection après authentification vous ramènera directement sur la demande.</p>';

        $subject = 'Demande d\'accès BANAMUR - ' . ((string)($request['requester_name'] ?? 'Utilisateur'));
        $plain = "Nouvelle demande d'accès\n\n"
            . 'Utilisateur : ' . (string)($request['requester_name'] ?? '') . "\n"
            . 'Email : ' . (string)($request['requester_email'] ?? '') . "\n"
            . 'Page demandée : ' . $requestedPage . "\n"
            . 'Accès recommandé : ' . $primaryLabel . ($primaryCode !== '' ? ' (' . $primaryCode . ')' : '') . "\n"
            . ($alternatives ? 'Alternatives : ' . implode(', ', (array)($request['alternative_permissions'] ?? [])) . "\n" : '')
            . "\nAccorder : " . $approveUrl . "\n"
            . 'Refuser : ' . $refuseUrl . "\n"
            . 'Dashboard : ' . $dashboardUrl;

        $supportSettings = new \AccessRequestSupportSettings($this->pdo);
        $recipients = $supportSettings->getSelectedRecipients();
        if ($recipients === []) {
            throw new RuntimeException('Aucun destinataire support informatique n\'est configuré.');
        }

        $primaryRecipient = array_shift($recipients);
        $ccRecipients = [];
        foreach ($recipients as $recipient) {
            $ccRecipients[] = [
                'email' => (string)($recipient['email'] ?? ''),
                'name' => (string)($recipient['name'] ?? ''),
            ];
        }

        $sent = $this->emailChannel->send(
            (string)($primaryRecipient['email'] ?? ''),
            (string)($primaryRecipient['name'] ?? 'Service informatique'),
            $subject,
            $this->renderEmailLayout('Demande d\'accès utilisateur', 'Une demande d\'accès attend une décision informatique.', $html),
            $plain,
            ['cc' => $ccRecipients]
        );
        if ($sent) {
            $this->pdo->prepare('UPDATE access_requests SET support_email_sent_at = NOW(), updated_at = NOW() WHERE id = :id')
                ->execute([':id' => (int)$request['id']]);
        }

        return $sent;
    }

    public function getRequestById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM access_requests WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRequest($row) : null;
    }

    public function getRequestForRequester(int $id, int $requesterUserId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM access_requests WHERE id = :id AND requester_user_id = :requester_user_id LIMIT 1');
        $stmt->execute([':id' => $id, ':requester_user_id' => $requesterUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRequest($row) : null;
    }

    public function getRequestByAdminToken(string $token): ?array
    {
        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare('SELECT * FROM access_requests WHERE admin_token_hash = :hash LIMIT 1');
        $stmt->execute([':hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRequest($row) : null;
    }

    public function resolveByAdminToken(string $token, string $decision, int $adminUserId, string $adminName, ?string $decisionMessage = null): array
    {
        $request = $this->getRequestByAdminToken($token);
        if (!$request) {
            throw new RuntimeException('Demande introuvable ou lien invalide');
        }

        if (!in_array($decision, ['granted', 'refused'], true)) {
            throw new RuntimeException('Décision invalide');
        }

        if ((string)$request['status'] !== 'pending') {
            return $request;
        }

        $this->pdo->beginTransaction();
        try {
            $grantedPermission = null;
            if ($decision === 'granted') {
                $grantedPermission = (string)$request['primary_permission'];
                $this->grantPermissionToUser((int)$request['requester_user_id'], $grantedPermission);
            }

            $stmt = $this->pdo->prepare(
                'UPDATE access_requests
                 SET status = :status,
                     granted_permission = :granted_permission,
                     decision_message = :decision_message,
                     decided_at = NOW(),
                     admin_user_id = :admin_user_id,
                     admin_user_name = :admin_user_name,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':status' => $decision,
                ':granted_permission' => $grantedPermission,
                ':decision_message' => $decisionMessage,
                ':admin_user_id' => $adminUserId,
                ':admin_user_name' => $adminName,
                ':id' => (int)$request['id'],
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $updated = $this->getRequestById((int)$request['id']);
        if (!$updated) {
            throw new RuntimeException('Impossible de relire la demande après décision');
        }

        $this->notifyRequester($updated);
        \AuditTrail::log('access_request_' . $decision, [
            'module' => 'security',
            'action' => 'access_request_' . $decision,
            'entity_type' => 'access_request',
            'entity_id' => (int)$updated['id'],
            'entity_label' => (string)$updated['requested_page'],
            'user_id' => $adminUserId,
            'user_name' => $adminName,
            'result' => 'success',
            'details' => [
                'requester_user_id' => (int)$updated['requester_user_id'],
                'granted_permission' => $updated['granted_permission'] ?? null,
                'decision_message' => $decisionMessage,
            ],
        ], $this->pdo);

        return $updated;
    }

    public function buildRequesterStatusPayload(array $request): array
    {
        return [
            'id' => (int)$request['id'],
            'status' => (string)$request['status'],
            'requested_page' => (string)$request['requested_page'],
            'requested_page_url' => (string)($request['requested_page_url'] ?? ''),
            'origin_url' => (string)($request['origin_url'] ?? ''),
            'primary_permission_label' => (string)($request['primary_permission_label'] ?? ''),
            'primary_permission' => (string)($request['primary_permission'] ?? ''),
            'decision_message' => (string)($request['decision_message'] ?? ''),
            'decided_at' => (string)($request['decided_at'] ?? ''),
            'support_email_sent_at' => (string)($request['support_email_sent_at'] ?? ''),
            'admin_user_name' => (string)($request['admin_user_name'] ?? ''),
        ];
    }

    private function notifyRequester(array $request): void
    {
        $email = trim((string)($request['requester_email'] ?? ''));
        if ($email === '') {
            return;
        }

        $status = (string)$request['status'];
        $isGranted = $status === 'granted';
        $targetUrl = $isGranted
            ? (string)($request['requested_page_url'] ?? $this->buildAbsoluteUrl('selection_portail.php'))
            : (string)($request['origin_url'] ?? $this->buildAbsoluteUrl('selection_portail.php'));
        if ($targetUrl === '') {
            $targetUrl = $this->buildAbsoluteUrl('selection_portail.php');
        }

        $title = $isGranted ? 'Accès accordé' : 'Demande d\'accès refusée';
        $body = '<p style="margin:0 0 16px; color:#334155; line-height:1.75;">'
            . ($isGranted
                ? 'Votre demande d\'accès a été acceptée. Vous pouvez désormais ouvrir la page concernée.'
                : 'Votre demande d\'accès a été refusée pour le moment. Consultez le message du support pour la suite.')
            . '</p>'
            . '<p style="margin:0 0 16px; color:#334155; line-height:1.75;"><strong>Page :</strong> ' . htmlspecialchars((string)$request['requested_page'], ENT_QUOTES, 'UTF-8') . '</p>'
            . (!empty($request['decision_message']) ? '<p style="margin:0 0 16px; color:#334155; line-height:1.75;"><strong>Message :</strong> ' . nl2br(htmlspecialchars((string)$request['decision_message'], ENT_QUOTES, 'UTF-8')) . '</p>' : '')
            . '<p style="margin:22px 0 0;"><a href="' . htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block; padding:14px 20px; border-radius:14px; background:' . ($isGranted ? 'linear-gradient(135deg,#0f766e,#2563eb)' : 'linear-gradient(135deg,#dc2626,#f97316)') . '; color:#ffffff; text-decoration:none; font-weight:700;">' . ($isGranted ? 'Ouvrir la page' : 'Revenir à l\'écran précédent') . '</a></p>';

        try {
            $this->emailChannel->send($email, (string)($request['requester_name'] ?? ''), 'Statut de votre demande d\'accès', $this->renderEmailLayout($title, 'Le support informatique a traité votre demande d\'accès.', $body));
        } catch (Throwable $e) {
        }
    }

    private function renderEmailLayout(string $title, string $intro, string $bodyHtml): string
    {
        return $this->emailChannel->buildFineaEmail($title, $bodyHtml, '', [
            'repere_title' => 'Repere',
            'repere_text' => $intro,
            'show_default_button' => false,
            'footer_text' => '© ' . date('Y') . ' Banamur. Message automatique du support interne.',
        ]);
    }

    private function findActiveUserById(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, prenom, nom, mail_pro, active FROM user_devis_banamur WHERE id = :id AND active = 1 LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $displayName = trim((string)(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
        if ($displayName === '') {
            $displayName = 'Utilisateur #' . (int)$row['id'];
        }

        return [
            'id' => (int)$row['id'],
            'display_name' => $displayName,
            'email' => (string)($row['mail_pro'] ?? ''),
        ];
    }

    private function grantPermissionToUser(int $userId, string $permission): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $permission)) {
            throw new RuntimeException('Permission invalide');
        }

        $columns = $this->pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        if (!in_array($permission, $columns, true)) {
            throw new RuntimeException('La colonne de permission ' . $permission . ' est absente de la table utilisateur');
        }

        $sql = 'UPDATE user_devis_banamur SET ' . $permission . ' = 1 WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $userId]);
    }

    private function hydrateRequest(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['requester_user_id'] = (int)($row['requester_user_id'] ?? 0);
        $row['admin_user_id'] = isset($row['admin_user_id']) ? (int)$row['admin_user_id'] : null;
        $alternatives = json_decode((string)($row['alternative_permissions_json'] ?? '[]'), true);
        $row['alternative_permissions'] = is_array($alternatives) ? array_values(array_filter($alternatives, 'is_string')) : [];
        return $row;
    }

    private function buildAbsoluteUrl(string $path = ''): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        if ($host === '') {
            $host = 'localhost';
        }

        $basePath = '';
        if (class_exists('AccessManager') && method_exists('AccessManager', 'getApplicationBasePath')) {
            $basePath = \AccessManager::getApplicationBasePath();
        } else {
            $projectRoot = str_replace('\\', '/', dirname(__DIR__));
            $documentRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), "\\/"));
            if ($documentRoot !== '' && str_starts_with(strtolower($projectRoot), strtolower($documentRoot))) {
                $relativePath = substr($projectRoot, strlen($documentRoot));
                $relativePath = '/' . ltrim(str_replace('\\', '/', (string)$relativePath), '/');
                $basePath = $relativePath === '/' ? '' : rtrim($relativePath, '/');
            }
        }

        return $scheme . '://' . $host . $basePath . '/' . ltrim($path, '/');
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS access_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                requester_user_id INT NOT NULL,
                requester_name VARCHAR(190) NOT NULL,
                requester_email VARCHAR(190) NOT NULL,
                requested_page VARCHAR(255) NOT NULL,
                requested_page_url TEXT NULL,
                origin_url TEXT NULL,
                primary_permission VARCHAR(120) NOT NULL,
                primary_permission_label VARCHAR(190) NOT NULL,
                alternative_permissions_json LONGTEXT NULL,
                access_type_label VARCHAR(120) NULL,
                module_label VARCHAR(190) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "pending",
                granted_permission VARCHAR(120) NULL,
                decision_message TEXT NULL,
                admin_token_hash CHAR(64) NOT NULL,
                admin_token_expires_at DATETIME NULL,
                support_email_sent_at DATETIME NULL,
                decided_at DATETIME NULL,
                admin_user_id INT NULL,
                admin_user_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_requester_user_id (requester_user_id),
                INDEX idx_status (status),
                INDEX idx_admin_token_hash (admin_token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
