<?php

require_once __DIR__ . '/../repositories/UrgentDisbursementRepository.php';
require_once __DIR__ . '/../model/NotificationManager.php';
require_once __DIR__ . '/../employe/includes/helpers.php';

class UrgentDisbursementService
{
    private PDO $pdo;
    private UrgentDisbursementRepository $repository;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->repository = new UrgentDisbursementRepository($pdo);
    }

    public function repository(): UrgentDisbursementRepository
    {
        return $this->repository;
    }

    public function createFromEmployee(array $post, array $file, array $requester): int
    {
        $chantierId = (int)($post['chantier_id'] ?? 0);
        $libelle = trim((string)($post['libelle'] ?? ''));
        $details = trim((string)($post['details'] ?? ''));
        $amount = $this->money((string)($post['amount'] ?? ''));

        if ($chantierId <= 0) {
            throw new RuntimeException('Veuillez selectionner le chantier concerne.');
        }
        if ($libelle === '') {
            throw new RuntimeException('Veuillez renseigner le libelle de l urgence.');
        }
        if ($details === '') {
            throw new RuntimeException('Veuillez renseigner le detail de la demande.');
        }
        if ($amount <= 0) {
            throw new RuntimeException('Veuillez renseigner un montant valide.');
        }

        $chantier = $this->findChantier($chantierId);
        if (!$chantier) {
            throw new RuntimeException('Chantier introuvable.');
        }

        $activeUrgency = $this->repository->findActiveUrgencyForChantier($chantierId);
        if ($activeUrgency) {
            $status = (string)($activeUrgency['status'] ?? 'submitted');
            $statusLabel = match ($status) {
                'decaissed' => 'décaissée et en attente de régularisation',
                'regularization_pending' => 'en cours de régularisation',
                default => 'en attente de traitement',
            };
            throw new RuntimeException(
                'Une urgence existe déjà sur ce chantier : '
                . (string)($activeUrgency['request_code'] ?? '') . ' (' . $statusLabel . '). '
                . 'Aucune nouvelle urgence ne peut être créée sur le même chantier avant sa clôture ou sa régularisation.'
            );
        }

        $upload = $this->storeOptionalJustification($file, (int)($requester['user_id'] ?? 0));
        $id = $this->repository->create([
            'requester_user_id' => (int)($requester['user_id'] ?? 0),
            'requester_name' => (string)($requester['name'] ?? ''),
            'requester_email' => (string)($requester['email'] ?? ''),
            'requester_personnel_id' => (int)($requester['personnel_id'] ?? 0),
            'chantier_id' => $chantierId,
            'chantier_code' => (string)($chantier['code'] ?? ''),
            'chantier_label' => trim((string)(($chantier['code'] ?? '') . ' - ' . ($chantier['titre'] ?? ''))),
            'libelle' => $libelle,
            'details' => $details,
            'amount' => $amount,
            'justification_path' => (string)($upload['path'] ?? ''),
            'justification_name' => (string)($upload['name'] ?? ''),
            'justification_mime' => (string)($upload['mime'] ?? ''),
            'justification_size' => (int)($upload['size'] ?? 0),
        ]);

        $this->notifyAccounting($id);
        return $id;
    }

    public function markDecaissed(int $id, int $actorUserId, string $actorName, string $note = ''): bool
    {
        $ok = $this->repository->markDecaissed($id, $actorUserId, $actorName, $note);
        if ($ok) {
            $row = $this->repository->findById($id);
            if ($row) {
                $this->notifyRequesterDecaissed($row);
            }
        }
        return $ok;
    }

    public function reject(int $id, int $actorUserId, string $actorName, string $note = ''): bool
    {
        return $this->repository->reject($id, $actorUserId, $actorName, $note);
    }

    public function blockMessageForUser(int $userId): string
    {
        $rows = $this->repository->listExpiredUnregularizedForUser($userId);
        if (!$rows) {
            return '';
        }

        $first = $rows[0];
        return 'Fiche de ' . (string)($first['requester_name'] ?? 'demandeur') . ' non regularisee sur le chantier '
            . (string)($first['chantier_label'] ?? '') . '. Creez la FEB associee puis la fiche avant de continuer.';
    }

    public function listBlockedForUser(int $userId): array
    {
        return $this->repository->listExpiredUnregularizedForUser($userId);
    }

    public function listOpenRegularizationsForUser(int $userId): array
    {
        return $this->repository->listOpenUnregularizedForUser($userId);
    }

    public function blockingForChantier(int $chantierId, int $exceptUrgentId = 0): ?array
    {
        return $this->repository->findBlockingForChantier($chantierId, $exceptUrgentId);
    }

    public function blockedChantierMap(): array
    {
        $map = [];
        foreach ($this->repository->listBlockedChantiers() as $row) {
            $chantierId = (int)($row['chantier_id'] ?? 0);
            if ($chantierId > 0 && !isset($map[$chantierId])) {
                $map[$chantierId] = $row;
            }
        }
        return $map;
    }

    public function chantierBlockReason(array $row): string
    {
        $code = trim((string)($row['request_code'] ?? ''));
        $requester = trim((string)($row['requester_name'] ?? 'demandeur'));
        $label = trim((string)($row['libelle'] ?? 'décaissement urgent'));
        return 'Chantier bloqué : l\'urgence ' . ($code !== '' ? $code . ' ' : '')
            . '(' . $label . '), décaissée pour ' . $requester
            . ', n\'est pas encore régularisée par sa FEB et sa fiche de décaissement.';
    }

    public function assertChantierAvailable(int $chantierId, int $exceptUrgentId = 0): void
    {
        if ($chantierId > 0) {
            // Validation métier uniquement : aucune migration/DDL ne doit être exécutée ici.
            // Les colonnes de cycle de vie sont préparées par le bootstrap/migration ou,
            // pour les anciens déploiements, avant l'ouverture de la transaction appelante.
            $state = $this->pdo->prepare('SELECT archived_at,paused_at FROM chantier_banamur WHERE id=:id LIMIT 1');
            $state->execute(['id' => $chantierId]);
            $row = $state->fetch(PDO::FETCH_ASSOC);
            if (!$row || !empty($row['archived_at'])) throw new DomainException('Ce chantier est terminé ou introuvable.');
            if (!empty($row['paused_at']) && $exceptUrgentId <= 0) throw new DomainException('Ce chantier est en pause. Reprenez-le avant de créer une nouvelle demande.');
        }
        $blocking = $this->blockingForChantier($chantierId, $exceptUrgentId);
        if ($blocking) {
            throw new RuntimeException($this->chantierBlockReason($blocking));
        }
    }

    public function urgentIdForFebCode(int $chantierId, string $febCode): int
    {
        $febCode = trim($febCode);
        if ($chantierId <= 0 || $febCode === '') {
            return 0;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT u.id FROM urgent_disbursement_requests u INNER JOIN employee_legal_requests r ON r.id=u.regularized_feb_request_id WHERE u.chantier_id=:chantier_id AND (JSON_UNQUOTE(JSON_EXTRACT(r.payload_json, "$.code_autorisation_feb"))=:code OR JSON_UNQUOTE(JSON_EXTRACT(r.payload_json, "$.feb_authorization_code"))=:code) LIMIT 1');
            $stmt->execute(['chantier_id' => $chantierId, 'code' => $febCode]);
            return (int)($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function forcedRegularizationRedirectForUser(int $userId, string $basePath = ''): string
    {
        $rows = $this->listOpenRegularizationsForUser($userId);
        if (!$rows) {
            return '';
        }

        $row = $rows[0];
        $urgentId = (int)($row['id'] ?? 0);
        if ($urgentId <= 0) {
            return '';
        }

        $prefix = rtrim($basePath, '/');
        $febId = (int)($row['regularized_feb_request_id'] ?? 0);
        if ($febId <= 0) {
            return $prefix . '/employe/nouvelle_demande_employe.php?' . http_build_query([
                'type' => 'feb',
                'urgent_id' => $urgentId,
                'urgent_lock' => 1,
            ]);
        }

        $febStatus = $this->findFebStatus($febId);
        if ($febStatus === 'details_requested') {
            return $prefix . '/employe/nouvelle_demande_employe.php?' . http_build_query([
                'type' => 'feb',
                'request_id' => $febId,
                'urgent_id' => $urgentId,
                'urgent_lock' => 1,
            ]);
        }

        $febCode = $this->findFebAuthorizationCode($febId);
        if ($febCode !== '') {
            return $prefix . '/decaissement/formulaire_demande_decaissement.php?' . http_build_query([
                'code_autorisation_feb' => $febCode,
                'urgent_id' => $urgentId,
                'urgent_lock' => 1,
            ]);
        }

        return $prefix . '/employe/details_demande_employe.php?' . http_build_query([
            'id' => $febId,
            'urgent_id' => $urgentId,
            'urgent_lock' => 1,
        ]);
    }

    public function registerFebForUrgency(int $urgentId, int $febRequestId): void
    {
        if ($urgentId <= 0 || $febRequestId <= 0) {
            throw new InvalidArgumentException('Identifiants de régularisation invalides.');
        }
        if (!$this->repository->setRegularizationPending($urgentId, $febRequestId)) {
            throw new RuntimeException('Impossible de rattacher la FEB à la demande urgente. Vérifiez la régularisation avant de recommencer.');
        }
    }

    private function notifyAccounting(int $id): void
    {
        try {
            $row = $this->repository->findById($id);
            if (!$row) {
                return;
            }
            new NotificationManager($this->pdo);
            $this->insertNotification(
                'urgent_disbursement',
                'submitted',
                'Nouveau decaissement urgent',
                (string)$row['requester_name'] . ' demande ' . number_format((float)$row['amount'], 0, ',', ' ') . ' CFA pour ' . (string)$row['chantier_label'],
                'decaissement/liste_decaissements_urgents.php',
                'finance',
                null,
                ['system'],
                ['urgent_disbursement_id' => $id]
            );
        } catch (Throwable $e) {
        }
    }

    private function notifyRequesterDecaissed(array $row): void
    {
        try {
            new NotificationManager($this->pdo);
            $this->insertNotification(
                'urgent_disbursement',
                'decaissed',
                'Urgence decaissee - regularisation requise',
                'Votre decaissement urgent ' . (string)$row['request_code'] . ' est decaisse. Vous avez 3 jours pour creer la FEB puis la fiche associee.',
                'employe/nouvelle_demande_employe.php?type=feb&urgent_id=' . (int)$row['id'],
                null,
                (int)$row['requester_user_id'],
                ['system'],
                ['urgent_disbursement_id' => (int)$row['id']]
            );
            $managerUserId = $this->findRequesterManagerUserId((int)($row['requester_personnel_id'] ?? 0));
            if ($managerUserId > 0) {
                $this->insertNotification(
                    'urgent_disbursement',
                    'regularization_watch',
                    'Regularisation urgence a surveiller',
                    (string)$row['requester_name'] . ' doit regulariser le decaissement urgent ' . (string)$row['request_code'] . ' sous 3 jours.',
                    'decaissement/liste_decaissements_urgents.php',
                    null,
                    $managerUserId,
                    ['system'],
                    ['urgent_disbursement_id' => (int)$row['id']]
                );
            }
        } catch (Throwable $e) {
        }
    }

    private function findChantier(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, code, titre, devis_id FROM chantier_banamur WHERE id = :id AND archived_at IS NULL LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    private function findFebAuthorizationCode(int $febRequestId): string
    {
        if ($febRequestId <= 0) {
            return '';
        }
        try {
            $stmt = $this->pdo->prepare('SELECT payload_json FROM employee_legal_requests WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $febRequestId]);
            $payload = json_decode((string)($stmt->fetchColumn() ?: ''), true);
            if (!is_array($payload)) {
                return '';
            }
            foreach (['numero_bon_commande_interne', 'code_autorisation_feb', 'bci_number', 'numero_bci', 'code_autorisation'] as $key) {
                $code = trim((string)($payload[$key] ?? ''));
                if ($code !== '') {
                    return $code;
                }
            }
        } catch (Throwable $e) {
        }
        return '';
    }

    private function findFebStatus(int $febRequestId): string
    {
        if ($febRequestId <= 0) {
            return '';
        }
        try {
            $stmt = $this->pdo->prepare('SELECT status FROM employee_legal_requests WHERE id = :id AND request_type = "feb" LIMIT 1');
            $stmt->execute(['id' => $febRequestId]);
            return strtolower(trim((string)($stmt->fetchColumn() ?: '')));
        } catch (Throwable $e) {
        }
        return '';
    }

    private function insertNotification(
        string $eventKey,
        string $subKey,
        string $title,
        string $message,
        string $linkUrl,
        ?string $recipientTeam,
        ?int $recipientUserId,
        array $channels,
        array $payload
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO system_notifications (
                created_at, event_key, sub_key, title, message, link_url,
                recipient_team, recipient_user_id, channels_json, payload_json
            ) VALUES (
                NOW(), :event_key, :sub_key, :title, :message, :link_url,
                :recipient_team, :recipient_user_id, :channels_json, :payload_json
            )
        ');
        $stmt->execute([
            'event_key' => $eventKey,
            'sub_key' => $subKey,
            'title' => $title,
            'message' => $message,
            'link_url' => $linkUrl,
            'recipient_team' => $recipientTeam,
            'recipient_user_id' => $recipientUserId && $recipientUserId > 0 ? $recipientUserId : null,
            'channels_json' => json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function findRequesterManagerUserId(int $personnelId): int
    {
        if ($personnelId <= 0) {
            return 0;
        }
        try {
            $stmt = $this->pdo->prepare('
                SELECT h.direct_manager_user_id, h.department_head_user_id
                FROM personnel p
                INNER JOIN rh_service_hierarchy h ON h.service_id = p.service_id
                WHERE p.id_personnel_soignant = :personnel_id
                LIMIT 1
            ');
            $stmt->execute(['personnel_id' => $personnelId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return (int)($row['direct_manager_user_id'] ?? $row['department_head_user_id'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function storeOptionalJustification(array $file, int $userId): array
    {
        if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Justificatif non charge correctement.');
        }
        $name = trim((string)($file['name'] ?? ''));
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($name === '' || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Justificatif invalide.');
        }
        if ($size > 10 * 1024 * 1024) {
            throw new RuntimeException('Justificatif trop volumineux (10 Mo max).');
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            throw new RuntimeException('Justificatif au format non supporte.');
        }
        $dirRel = 'uploads/urgent_disbursements/' . date('Ymd') . '/' . max(0, $userId);
        $dirAbs = dirname(__DIR__) . '/' . $dirRel;
        if (!is_dir($dirAbs) && !mkdir($dirAbs, 0775, true) && !is_dir($dirAbs)) {
            throw new RuntimeException('Impossible de creer le dossier des justificatifs.');
        }
        $stored = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $dirAbs . '/' . $stored)) {
            throw new RuntimeException('Impossible de sauvegarder le justificatif.');
        }
        return [
            'path' => $dirRel . '/' . $stored,
            'name' => $name,
            'mime' => (string)($file['type'] ?? ''),
            'size' => $size,
        ];
    }

    private function money(string $value): float
    {
        $value = str_replace([" ", "\xc2\xa0"], '', trim($value));
        $value = str_replace(',', '.', $value);
        return (float)$value;
    }
}
