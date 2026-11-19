<?php

require_once __DIR__ . '/../repositories/AoOpportunityRepository.php';
require_once __DIR__ . '/../repositories/AoAlertPreferenceRepository.php';
require_once __DIR__ . '/../repositories/AoSyncLogRepository.php';
require_once __DIR__ . '/../services/AoScraperService.php';
require_once __DIR__ . '/../services/AoMultiSourceSyncService.php';
require_once __DIR__ . '/../services/AoScoringService.php';
require_once __DIR__ . '/../model/AuditTrail.php';

class AoController
{
    private PDO $pdo;
    private AoOpportunityRepository $opportunities;
    private AoAlertPreferenceRepository $preferences;
    private AoSyncLogRepository $logs;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->opportunities = new AoOpportunityRepository($pdo);
        $this->preferences = new AoAlertPreferenceRepository($pdo);
        $this->logs = new AoSyncLogRepository($pdo);
    }

    public function index(array $filters): array
    {
        return [
            'items' => $this->opportunities->search($filters, 300),
            'stats' => $this->opportunities->stats((string) ($filters['deadline_scope'] ?? 'active')),
            'filters' => $filters,
        ];
    }

    public function detail(int $id): ?array
    {
        $row = $this->opportunities->findById($id);
        if ($row) {
            $row['banamur_compatibility'] = (new AoScoringService())->analyzeBanamurCompatibility($row);
        }
        return $row;
    }

    public function updateStatus(int $id, string $status): void
    {
        $before = $this->opportunities->findById($id);
        $this->opportunities->updateStatus($id, $status);
        $this->audit('ao_status_changed', $id, $before, ['new_status' => $status]);
    }

    public function recordDgDecision(int $id, string $decision, int $userId, string $note = ''): void
    {
        $before = $this->opportunities->findById($id);
        $this->opportunities->recordDgDecision($id, $decision, $userId, $note);
        $this->audit('ao_dg_decision', $id, $before, [
            'decision' => $decision,
            'note' => trim($note),
            'alerts_disabled' => $decision === 'not_interested',
        ]);
    }

    public function sync(): array
    {
        return (new AoMultiSourceSyncService($this->pdo))->run();
    }

    public function getPreferences(int $userId): array
    {
        return $this->preferences->getForUser($userId);
    }

    public function savePreferences(int $userId, array $data): void
    {
        $before = $this->preferences->getForUser($userId);
        $this->preferences->save($userId, $data);
        AuditTrail::log('ao_alert_preferences_changed', [
            'module' => 'devis',
            'action' => 'save_ao_alert_preferences',
            'entity_type' => 'ao_alert_preferences',
            'entity_id' => (string) $userId,
            'user_id' => $userId,
            'details' => ['before' => $before, 'after' => $this->preferences->getForUser($userId)],
        ], $this->pdo);
    }

    public function syncLogs(): array
    {
        return $this->logs->latest(100);
    }

    public function createCrmOpportunity(int $aoId, int $userId): int
    {
        $ao = $this->detail($aoId);
        if (!$ao) {
            throw new RuntimeException('Appel d offres introuvable.');
        }
        if (empty($ao['date_limite']) || strtotime((string) $ao['date_limite']) < strtotime(date('Y-m-d'))) {
            throw new RuntimeException('Cet appel d offres est expiré ou ne possède pas de date limite exploitable.');
        }
        require_once __DIR__ . '/../model/CrmSchema.php';
        CrmSchema::ensureTables($this->pdo);
        $marker = '[AO#' . $aoId . ']';
        $check = $this->pdo->prepare('SELECT id FROM crm_opportunite_banamur WHERE notes LIKE :marker LIMIT 1');
        $check->execute(['marker' => '%' . $marker . '%']);
        $existing = (int) ($check->fetchColumn() ?: 0);
        if ($existing > 0) {
            $this->opportunities->linkCrmOpportunity($aoId, $existing);
            return $existing;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO crm_opportunite_banamur
                (linked_type, titre, etape, probabilite, notes, next_action_at, created_by)
             VALUES ('prospect', :titre, 'Nouveau', :probability, :notes, :next_action, :created_by)"
        );
        $deadline = !empty($ao['date_limite']) ? $ao['date_limite'] . ' 09:00:00' : null;
        $stmt->execute([
            'titre' => mb_substr((string) $ao['titre'], 0, 200),
            'probability' => max(10, min(90, (int) $ao['score_pertinence'])),
            'notes' => $marker
                . "\nReference: " . (($ao['numero_ao'] ?? '') ?: ($ao['reference'] ?: '-'))
                . "\nAutorite: " . ($ao['autorite_contractante'] ?: '-')
                . "\nType de marche: " . ($ao['type_marche'] ?: '-')
                . "\nDate limite: " . ($ao['date_limite'] ?: '-')
                . "\nSource: " . ($ao['source'] ?: '-')
                . "\nURL source: " . ($ao['url_source'] ?: '-')
                . "\nScore FINEA: " . (int) $ao['score_pertinence'] . '%',
            'next_action' => $deadline,
            'created_by' => $userId,
        ]);
        $crmId = (int) $this->pdo->lastInsertId();
        $this->opportunities->linkCrmOpportunity($aoId, $crmId);
        $this->audit('ao_crm_created', $aoId, $ao, ['crm_opportunity_id' => $crmId]);
        return $crmId;
    }

    private function audit(string $event, int $aoId, ?array $before, array $details): void
    {
        AuditTrail::log($event, [
            'module' => 'devis',
            'action' => $event,
            'entity_type' => 'ao_opportunity',
            'entity_id' => (string) $aoId,
            'entity_label' => (string) ($before['titre'] ?? ''),
            'details' => array_merge(['previous_status' => $before['statut_interne'] ?? null], $details),
        ], $this->pdo);
    }
}

