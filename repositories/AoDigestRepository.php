<?php

require_once __DIR__ . '/../services/AoDigestPolicy.php';

final class AoDigestRepository
{
    public function __construct(private PDO $pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ao_digest_settings (
            id TINYINT NOT NULL PRIMARY KEY, settings_json TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ao_digest_admin_preferences (
            user_id INT NOT NULL PRIMARY KEY, enabled TINYINT NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ao_digest_runs (
            id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, run_date DATE NOT NULL,
            status VARCHAR(30) NOT NULL, opportunity_count INT NOT NULL DEFAULT 0,
            recipients_json LONGTEXT NULL, email_log_id BIGINT NULL, error_message TEXT NULL,
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, finished_at DATETIME NULL,
            UNIQUE KEY uq_ao_digest_date (run_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function services(): array
    {
        return $this->pdo->query('SELECT id_service id, lib_service label FROM service ORDER BY lib_service')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function settings(): array
    {
        $defaults = ['enabled' => false, 'min_score' => 60, 'commercial_service_ids' => [], 'technical_service_ids' => [], 'primary_user_id' => 0];
        foreach ($this->services() as $service) {
            $label = mb_strtolower((string) $service['label']);
            if (str_contains($label, 'commercial')) $defaults['commercial_service_ids'][] = (int) $service['id'];
            if (str_contains($label, 'technique')) $defaults['technical_service_ids'][] = (int) $service['id'];
        }
        $json = $this->pdo->query('SELECT settings_json FROM ao_digest_settings WHERE id = 1')->fetchColumn();
        return array_replace($defaults, $json ? (json_decode($json, true, 512, JSON_THROW_ON_ERROR) ?: []) : []);
    }

    public function saveSettings(array $input): void
    {
        $known = array_map('intval', array_column($this->services(), 'id'));
        $data = ['enabled' => !empty($input['enabled']), 'min_score' => max(0, min(100, (int) ($input['min_score'] ?? 60))),
            'primary_user_id' => max(0, (int) ($input['primary_user_id'] ?? 0))];
        foreach (['commercial_service_ids', 'technical_service_ids'] as $key) {
            $data[$key] = array_values(array_intersect($known, array_unique(array_map('intval', (array) ($input[$key] ?? [])))));
        }
        if (!$data['commercial_service_ids'] || !$data['technical_service_ids']) {
            throw new RuntimeException('Sélectionnez les départements commercial et technique.');
        }
        if ($data['enabled']) AoDigestPolicy::envelope($this->recipients($data));
        $stmt = $this->pdo->prepare('INSERT INTO ao_digest_settings (id, settings_json) VALUES (1, :json) ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json)');
        $stmt->execute(['json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    public function setAdminPreference(int $userId, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('SELECT acces_admin, ao_admin FROM user_devis_banamur WHERE id = :id AND active = 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (empty($user['acces_admin']) && empty($user['ao_admin']))) throw new RuntimeException('Préférence réservée aux administrateurs.');
        $stmt = $this->pdo->prepare('INSERT INTO ao_digest_admin_preferences (user_id, enabled) VALUES (:id, :enabled) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)');
        $stmt->execute(['id' => $userId, 'enabled' => (int) $enabled]);
    }

    public function recipients(?array $settings = null): array
    {
        $settings ??= $this->settings();
        $users = $this->pdo->query("SELECT u.id user_id, u.personnel_id, u.prenom, u.nom, u.mail_pro,
            u.acces_direction, u.ao_dg_decision, u.acces_admin, u.ao_admin,
            p.service_id, s.lib_service service_label, p.email_personnel_soignant personnel_email,
            pref.enabled admin_enabled
            FROM user_devis_banamur u
            LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN ao_digest_admin_preferences pref ON pref.user_id = u.id
            WHERE u.active = 1 AND (p.id_personnel_soignant IS NULL OR p.date_sortie IS NULL OR p.date_sortie = '0000-00-00')
            ORDER BY u.prenom, u.nom, u.id")->fetchAll(PDO::FETCH_ASSOC);
        $rows = [];
        $linked = [];
        foreach ($users as $user) {
            if (!empty($user['personnel_id'])) $linked[(int) $user['personnel_id']] = true;
            $sid = (int) ($user['service_id'] ?? 0);
            $commercial = in_array($sid, $settings['commercial_service_ids'], true);
            $technical = in_array($sid, $settings['technical_service_ids'], true);
            $direction = !empty($user['ao_dg_decision']) || !empty($user['acces_direction']);
            $admin = !empty($user['acces_admin']) || !empty($user['ao_admin']);
            if (!$commercial && !$technical && !$direction && !$admin) continue;
            $required = $commercial || $technical || $direction;
            $email = trim((string) ($user['mail_pro'] ?: ($user['personnel_email'] ?? '')));
            $rows[] = ['user_id' => (int) $user['user_id'], 'personnel_id' => (int) ($user['personnel_id'] ?? 0),
                'name' => trim($user['prenom'] . ' ' . $user['nom']), 'email' => $email,
                'service_id' => $sid, 'service' => (string) ($user['service_label'] ?? ''),
                'commercial' => $commercial, 'required' => $required, 'admin' => $admin,
                'role' => $commercial && (int) $settings['primary_user_id'] === (int) $user['user_id'] ? 'to' : 'cc',
                'reason' => implode(', ', array_filter([$commercial ? 'Commercial' : '', $technical ? 'Technique' : '', $direction ? 'Direction / DG' : '', $admin ? 'Administrateur' : ''])),
                'status' => !filter_var($email, FILTER_VALIDATE_EMAIL) ? 'invalid_email' : (!$required && $user['admin_enabled'] !== null && !(int) $user['admin_enabled'] ? 'disabled' : 'included')];
        }
        // Les collaborateurs sans compte FINEA doivent aussi figurer dans le bilan.
        $staff = $this->pdo->query("SELECT p.id_personnel_soignant id, p.nom_personnel_soignant name,
            p.email_personnel_soignant email, p.service_id, s.lib_service service_label
            FROM personnel p LEFT JOIN service s ON s.id_service = p.service_id
            WHERE p.date_sortie IS NULL OR p.date_sortie = '0000-00-00'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($staff as $person) {
            $sid = (int) $person['service_id'];
            $commercial = in_array($sid, $settings['commercial_service_ids'], true);
            $technical = in_array($sid, $settings['technical_service_ids'], true);
            if (isset($linked[(int) $person['id']]) || (!$commercial && !$technical)) continue;
            $email = trim((string) $person['email']);
            $rows[] = ['user_id' => 0, 'personnel_id' => (int) $person['id'], 'name' => $person['name'], 'email' => $email,
                'service_id' => $sid, 'service' => (string) $person['service_label'], 'commercial' => $commercial,
                'required' => true, 'admin' => false, 'role' => 'cc', 'reason' => ($commercial ? 'Commercial' : 'Technique') . ' · sans compte FINEA',
                'status' => filter_var($email, FILTER_VALIDATE_EMAIL) ? 'included' : 'invalid_email'];
        }
        // Responsable principal issu de la hiérarchie RH quand aucun remplacement n'est configuré.
        if (!(int) $settings['primary_user_id'] && $settings['commercial_service_ids']) {
            $exists = $this->pdo->query("SHOW TABLES LIKE 'rh_service_hierarchy'")->fetchColumn();
            $heads = [];
            if ($exists) {
                $ids = implode(',', array_map('intval', $settings['commercial_service_ids']));
                $heads = array_values(array_unique(array_filter(array_map('intval', $this->pdo->query('SELECT COALESCE(department_head_user_id, direct_manager_user_id) FROM rh_service_hierarchy WHERE service_id IN (' . $ids . ')')->fetchAll(PDO::FETCH_COLUMN)))));
            }
            if (count($heads) === 1) foreach ($rows as &$row) {
                if ($row['commercial'] && $row['user_id'] === $heads[0]) $row['role'] = 'to';
            }
            unset($row);
        }
        usort($rows, static fn($a, $b) => strcmp($a['name'], $b['name']));
        // Le listing montre les adresses en double et celle qui sera réellement retenue.
        usort($rows, static fn($a, $b) => ($a['role'] === 'to' ? 0 : 1) <=> ($b['role'] === 'to' ? 0 : 1));
        $seen = [];
        foreach ($rows as &$row) {
            if ($row['status'] !== 'included') continue;
            $key = strtolower($row['email']);
            if (isset($seen[$key])) $row['status'] = 'duplicate';
            else $seen[$key] = true;
        }
        unset($row);
        return $rows;
    }

    public function opportunities(int $score): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM ao_opportunities WHERE date_limite >= :today
            AND score_pertinence >= :score AND alerts_disabled = 0
            AND dg_decision <> 'not_interested' AND statut_interne <> 'non_interessant'
            ORDER BY date_limite ASC, score_pertinence DESC, id DESC");
        $stmt->execute(['today' => (new DateTimeImmutable('now', new DateTimeZone('Africa/Abidjan')))->format('Y-m-d'), 'score' => $score]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function history(): array
    {
        return $this->pdo->query('SELECT * FROM ao_digest_runs ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function pdo(): PDO { return $this->pdo; }
}
