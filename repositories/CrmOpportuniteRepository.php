<?php

require_once __DIR__ . '/../model/Database.php';

class CrmOpportuniteRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    private function getTableColumns(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $cols = $this->pdo->query('SHOW COLUMNS FROM crm_opportunite_banamur')->fetchAll(PDO::FETCH_COLUMN, 0);
            if (is_array($cols)) {
                return $cached = $cols;
            }
        } catch (Throwable $e) {
        }

        return $cached = [];
    }

    private function hasColumn(string $name): bool
    {
        return in_array($name, $this->getTableColumns(), true);
    }

    public function create(array $data): int
    {
        $prob = max(0, min(100, (int) ($data['probabilite'] ?? 10)));
        $montant = $data['montant_xof'] ?? null;
        $montant = ($montant !== null && $montant !== '') ? max(0, (int) $montant) : null;

        $columns = ['linked_type', 'prospect_id', 'client_id', 'titre', 'etape', 'montant_xof', 'probabilite', 'notes', 'created_by'];
        $params = [
            'linked_type' => $data['linked_type'],
            'prospect_id' => $data['prospect_id'],
            'client_id' => $data['client_id'],
            'titre' => $data['titre'],
            'etape' => $data['etape'],
            'montant_xof' => $montant,
            'probabilite' => $prob,
            'notes' => $data['notes'],
            'created_by' => $data['created_by'],
        ];

        if ($this->hasColumn('next_action_at')) {
            $columns[] = 'next_action_at';
            $params['next_action_at'] = $this->normalizeNextActionAt($data['next_action_at'] ?? null);
        }
        if ($this->hasColumn('next_action_duration_min')) {
            $columns[] = 'next_action_duration_min';
            $params['next_action_duration_min'] = $this->normalizeDuration(isset($data['next_action_duration_min']) ? (int) $data['next_action_duration_min'] : null);
        }

        $placeholders = array_map(static fn($c) => ':' . $c, $columns);
        $stmt = $this->pdo->prepare('INSERT INTO crm_opportunite_banamur (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $stmt->execute($params);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateEtape(int $id, string $etape, ?int $probabilite = null): void
    {
        $etape = trim($etape);
        if ($id <= 0 || $etape === '') {
            return;
        }

        if ($probabilite !== null) {
            $probabilite = max(0, min(100, $probabilite));
            $stmt = $this->pdo->prepare('UPDATE crm_opportunite_banamur SET etape = :etape, probabilite = :probabilite WHERE id = :id');
            $stmt->execute(['id' => $id, 'etape' => $etape, 'probabilite' => $probabilite]);
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE crm_opportunite_banamur SET etape = :etape WHERE id = :id');
        $stmt->execute(['id' => $id, 'etape' => $etape]);
    }

    public function listRecent(int $limit = 120): array
    {
        $limit = max(1, min(500, $limit));
        $select = [
            'o.id',
            'o.linked_type',
            'o.prospect_id',
            'o.client_id',
            'o.titre',
            'o.etape',
            'o.montant_xof',
            'o.probabilite',
            'o.notes',
            'o.created_at',
            'o.updated_at',
            ($this->hasColumn('next_action_at') ? 'o.next_action_at' : 'NULL AS next_action_at'),
            ($this->hasColumn('next_action_duration_min') ? 'o.next_action_duration_min' : 'NULL AS next_action_duration_min'),
            ($this->hasColumn('next_action_event_id') ? 'o.next_action_event_id' : 'NULL AS next_action_event_id'),
            ($this->hasColumn('reminder_24h_sent_at') ? 'o.reminder_24h_sent_at' : 'NULL AS reminder_24h_sent_at'),
            ($this->hasColumn('reminder_1h_sent_at') ? 'o.reminder_1h_sent_at' : 'NULL AS reminder_1h_sent_at'),
            'COALESCE(p.nom, c.nom_client) AS linked_name',
        ];

        $stmt = $this->pdo->query(
            'SELECT ' . implode(",\n                ", $select) .
            "\n             FROM crm_opportunite_banamur o
             LEFT JOIN crm_prospect_banamur p ON p.id = o.prospect_id
             LEFT JOIN client_banamur c ON c.id_client = o.client_id
             ORDER BY o.updated_at DESC, o.created_at DESC
             LIMIT {$limit}"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findNextAction(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, titre, next_action_at, next_action_duration_min, next_action_event_id FROM crm_opportunite_banamur WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function setNextAction(int $id, ?string $nextActionAt, ?int $durationMin, ?int $eventId = null): void
    {
        if ($id <= 0) {
            return;
        }

        $cols = $this->getTableColumns();
        if (!in_array('next_action_at', $cols, true) && !in_array('next_action_duration_min', $cols, true) && !in_array('next_action_event_id', $cols, true)) {
            return;
        }

        $sets = [];
        $params = ['id' => $id];
        if (in_array('next_action_at', $cols, true)) {
            $sets[] = 'next_action_at = :na';
            $params['na'] = $this->normalizeNextActionAt($nextActionAt);
        }
        if (in_array('next_action_duration_min', $cols, true)) {
            $sets[] = 'next_action_duration_min = :dur';
            $params['dur'] = $this->normalizeDuration($durationMin);
        }
        if (in_array('next_action_event_id', $cols, true)) {
            $sets[] = 'next_action_event_id = :eid';
            $params['eid'] = ($eventId !== null && $eventId > 0) ? $eventId : null;
        }
        if (in_array('reminder_24h_sent_at', $cols, true)) {
            $sets[] = 'reminder_24h_sent_at = NULL';
        }
        if (in_array('reminder_1h_sent_at', $cols, true)) {
            $sets[] = 'reminder_1h_sent_at = NULL';
        }
        if (!$sets) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE crm_opportunite_banamur SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    private function normalizeDuration(?int $minutes): ?int
    {
        if ($minutes === null || $minutes <= 0) {
            return null;
        }
        return max(5, min(480, $minutes));
    }

    private function normalizeNextActionAt(?string $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value) ?: new DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}
