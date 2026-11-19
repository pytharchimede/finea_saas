<?php
declare(strict_types=1);

/** Le bénéficiaire explicite de la FEB est la référence des fiches liées. */
final class FebBeneficiarySyncService
{
    public static function selected(PDO $pdo, array $payload): ?array
    {
        $draft = is_array($payload['provisional_decaissement'] ?? null) ? $payload['provisional_decaissement'] : [];
        $mode = strtolower(trim((string) ($draft['beneficiary_mode'] ?? '')));
        if ($mode === '') {
            $choice = strtolower(trim((string) ($draft['beneficiary_choice'] ?? $payload['beneficiary_choice'] ?? '')));
            $mode = $choice === 'supplier' ? 'supplier' : ($choice === 'requester' ? 'personnel' : (trim((string) ($draft['beneficiaire_fiche'] ?? $draft['beneficiary_name'] ?? '')) !== '' ? 'external' : ''));
        }
        if ($mode === 'personnel') {
            $id = (int) ($draft['personnel_id'] ?? $payload['personnel_id'] ?? 0);
            if ($id <= 0) {
                $name = trim((string) ($draft['beneficiaire_fiche'] ?? $draft['beneficiary_name'] ?? ''));
                return $name === '' ? null : ['mode' => $mode, 'name' => $name, 'phone' => trim((string) ($draft['tel_beneficiaire_fiche'] ?? '')), 'personnel_id' => null, 'fournisseur_id' => null];
            }
            $q = $pdo->prepare('SELECT nom_personnel_soignant name,tel_personnel_soignant phone FROM personnel WHERE id_personnel_soignant=:id LIMIT 1');
            $q->execute(['id' => $id]);
            $person = $q->fetch(PDO::FETCH_ASSOC);
            if (!$person || trim((string) $person['name']) === '') return null;
            return ['mode' => $mode, 'name' => trim((string) $person['name']), 'phone' => trim((string) ($person['phone'] ?? '')), 'personnel_id' => $id, 'fournisseur_id' => null];
        }
        if ($mode === 'supplier') {
            $id = (int) ($draft['fournisseur_id'] ?? $payload['fournisseur_id'] ?? $payload['global_fournisseur_id'] ?? 0);
            if ($id <= 0) return null;
            $q = $pdo->prepare('SELECT nom_commercial,raison_sociale,contact_nom,telephone FROM fournisseur_banamur WHERE id_fournisseur=:id LIMIT 1');
            $q->execute(['id' => $id]);
            $supplier = $q->fetch(PDO::FETCH_ASSOC);
            if (!$supplier) return null;
            $name = trim((string) ($supplier['nom_commercial'] ?: $supplier['raison_sociale'] ?: $supplier['contact_nom']));
            if ($name === '') return null;
            return ['mode' => $mode, 'name' => $name, 'phone' => trim((string) ($supplier['telephone'] ?? '')), 'personnel_id' => null, 'fournisseur_id' => $id];
        }
        if ($mode === 'external') {
            $name = trim((string) ($draft['beneficiaire_fiche'] ?? $draft['beneficiary_name'] ?? ''));
            if ($name === '') return null;
            return ['mode' => $mode, 'name' => $name, 'phone' => trim((string) ($draft['tel_beneficiaire_fiche'] ?? '')), 'personnel_id' => null, 'fournisseur_id' => null];
        }
        return null;
    }

    public static function syncFiche(PDO $pdo, int $febId): void
    {
        if ($febId <= 0) return;
        $q = $pdo->prepare("SELECT payload_json FROM employee_legal_requests WHERE id=:id AND request_type='feb' AND status='approved' LIMIT 1");
        $q->execute(['id' => $febId]);
        $payload = json_decode((string) $q->fetchColumn(), true);
        if (!is_array($payload)) return;
        $selected = self::selected($pdo, $payload);
        if (!$selected) return;
        $hasPoints = (bool)$pdo->query("SHOW TABLES LIKE 'daily_disbursement_points'")->fetchColumn();
        $unreserved = $hasPoints ? "NOT EXISTS(SELECT 1 FROM daily_disbursement_point_items dpi JOIN daily_disbursement_points dp ON dp.id=dpi.point_id WHERE dpi.fiche_number=fiche_decaissement.num_fiche AND dp.status IN ('stopped','certified'))" : '1=1';
        $pdo->prepare('UPDATE fiche_decaissement SET beficiaire_fiche=:name,tel_beneficiaire_fiche=CASE WHEN :phone=\'\' THEN tel_beneficiaire_fiche ELSE :phone_value END,personnel_id=:personnel WHERE COALESCE(decaisse,0)=0 AND feb_request_id=:feb AND '.$unreserved.' AND (COALESCE(beficiaire_fiche,\'\')<>:name_check OR (:phone_check<>\'\' AND COALESCE(tel_beneficiaire_fiche,\'\')<>:phone_compare) OR COALESCE(personnel_id,0)<>:personnel_check)')
            ->execute(['name' => $selected['name'], 'phone' => $selected['phone'], 'phone_value' => $selected['phone'], 'personnel' => $selected['personnel_id'], 'feb' => $febId, 'name_check' => $selected['name'], 'phone_check' => $selected['phone'], 'phone_compare' => $selected['phone'], 'personnel_check' => $selected['personnel_id'] ?? 0]);
    }

    public static function syncApproved(PDO $pdo): void
    {
        $key = 'feb_beneficiary_from_selection_v1_20260916';
        $pdo->exec('CREATE TABLE IF NOT EXISTS finea_data_migrations (migration_key VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, details_json LONGTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $check = $pdo->prepare('SELECT 1 FROM finea_data_migrations WHERE migration_key=:key LIMIT 1');
        $check->execute(['key' => $key]);
        if ($check->fetchColumn()) return;
        $ids = $pdo->query("SELECT DISTINCT r.id FROM employee_legal_requests r JOIN fiche_decaissement f ON f.feb_request_id=r.id WHERE r.request_type='feb' AND r.status='approved'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) self::syncFiche($pdo, (int) $id);
        $pdo->prepare('INSERT INTO finea_data_migrations(migration_key,details_json) VALUES(:key,:details)')->execute(['key' => $key, 'details' => json_encode(['febs' => count($ids)])]);
    }
}

