<?php

declare(strict_types=1);

require_once __DIR__ . '/AccessManager.php';

final class AccessMatrixService
{
    public static function catalog(PDO $pdo): array
    {
        AccessManager::ensureOptionalPermissionColumns($pdo);
        $universal = array_flip(AccessManager::universalPermissions($pdo));
        $catalog = [];

        foreach (AccessManager::permissionColumns($pdo) as $key) {
            $catalog[$key] = [
                'key' => $key,
                'label' => self::label($key),
                'group' => self::group($key),
                'universal' => isset($universal[$key]),
            ];
        }

        uasort($catalog, static function (array $left, array $right): int {
            return [$left['group'], $left['label']] <=> [$right['group'], $right['label']];
        });

        return $catalog;
    }

    public static function users(PDO $pdo, string $query = '', string $status = 'all'): array
    {
        $where = [];
        $params = [];
        if ($query !== '') {
            $where[] = "CONCAT(COALESCE(u.prenom,''), ' ', COALESCE(u.nom,''), ' ', COALESCE(u.mail_pro,''), ' ', COALESCE(r.lib_role_devis,'')) LIKE :query";
            $params['query'] = '%' . $query . '%';
        }
        if ($status === 'active') {
            $where[] = 'u.active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'u.active = 0';
        }

        $sql = "
            SELECT u.*, COALESCE(r.lib_role_devis, '') AS role_label
            FROM user_devis_banamur u
            LEFT JOIN role_devis_banamur r ON r.id_role_devis = u.role_id
        ";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY u.active DESC, u.nom, u.prenom, u.id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function setPermission(PDO $pdo, int $userId, string $permission, bool $enabled): array
    {
        $catalog = self::catalog($pdo);
        if ($userId <= 0 || !isset($catalog[$permission])) {
            throw new InvalidArgumentException('Utilisateur ou permission invalide.');
        }
        if (!empty($catalog[$permission]['universal']) && !$enabled) {
            throw new RuntimeException('Cet accès est commun et obligatoire.');
        }

        $stmt = $pdo->prepare('UPDATE user_devis_banamur SET `' . $permission . '` = :enabled WHERE id = :user_id');
        $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'user_id' => $userId,
        ]);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM user_devis_banamur WHERE id = :user_id');
            $exists->execute(['user_id' => $userId]);
            if (!(int) $exists->fetchColumn()) {
                throw new RuntimeException('Compte utilisateur introuvable.');
            }
        }

        return $catalog[$permission];
    }

    public static function stats(array $users, array $catalog): array
    {
        $enabled = 0;
        $possible = count($users) * count($catalog);
        foreach ($users as $user) {
            foreach ($catalog as $permission) {
                if (!empty($user[$permission['key']])) {
                    $enabled++;
                }
            }
        }

        return [
            'users' => count($users),
            'active_users' => count(array_filter($users, static fn(array $user): bool => !empty($user['active']))),
            'permissions' => count($catalog),
            'enabled' => $enabled,
            'coverage' => $possible > 0 ? round(($enabled / $possible) * 100, 1) : 0.0,
        ];
    }

    public static function label(string $key): string
    {
        $labels = [
            'gestion_utilisateur' => 'Gestion des utilisateurs',
            'module_logistique' => 'Module logistique',
            'modifier_debourse' => 'Modifier les déboursés',
            'ajuster_beneficiaire_fournisseur_fiche' => 'Ajuster bénéficiaire/fournisseur après validation',
            'regulariser_fournisseur_sans_preuve' => 'Régulariser un fournisseur sans preuve (bypass audité)',
            'modifier_devis' => 'Modifier les devis',
            'visualiser_devis' => 'Visualiser les devis',
            'soumettre_devis' => 'Soumettre les devis',
            'masquer_devis' => 'Masquer les devis',
            'envoyer_devis' => 'Envoyer les devis',
            'valider_devis' => 'Valider les devis',
            'valider_archivage_chantier' => 'Archiver un chantier',
            'document_upload' => 'Déposer des documents',
            'document_edit' => 'Modifier les documents',
            'document_archive' => 'Archiver les documents',
            'document_admin' => 'Administrer les documents',
            'formation_admin' => 'Administrer les formations',
            'acces_parc_informatique' => 'Gérer le parc informatique',
            'ao_view' => 'AO : consulter',
            'ao_export' => 'AO : exporter',
            'ao_manage' => 'AO : administrer',
            'ao_mark_interesting' => 'AO : marquer intéressant',
            'ao_mark_not_interested' => 'AO : marquer non intéressant',
            'ao_create_crm' => 'AO : créer dans le CRM',
            'ao_manage_alerts' => 'AO : gérer les alertes',
            'ao_manage_diffusion' => 'AO : gérer la diffusion collective et les destinataires',
            'ao_admin' => 'AO : administration',
            'ao_dg_decision' => 'AO : décision DG',
            'acces_contrat_etape_rh' => 'Contrats : étape RH',
            'acces_contrat_etape_juridique' => 'Contrats : étape Juridique',
            'acces_contrat_etape_technique' => 'Contrats : étape Technique',
            'acces_contrat_etape_logistique' => 'Contrats : étape Logistique',
            'acces_contrat_etape_qhse' => 'Contrats : étape QHSE',
            'acces_contrat_etape_comptabilite' => 'Contrats : étape Comptabilité',
        ];
        if (isset($labels[$key])) {
            return $labels[$key];
        }

        $label = preg_replace('/^(acces_|logistique_)/', '', $key);
        $label = str_replace('_', ' ', (string) $label);
        return ucfirst($label);
    }

    public static function group(string $key): string
    {
        if (str_starts_with($key, 'acces_contrat_etape_')) {
            return 'Contrats';
        }
        if (str_starts_with($key, 'ao_') || $key === 'acces_veille_ao') {
            return 'Appels d’offres';
        }
        if (str_starts_with($key, 'acces_crm')) {
            return 'CRM';
        }
        if (str_starts_with($key, 'logistique_') || in_array($key, ['module_logistique', 'acces_logistique', 'acces_recap_carburant', 'acces_operations_carburant'], true)) {
            return 'Logistique';
        }
        if (str_starts_with($key, 'document_') || in_array($key, ['acces_documentation', 'acces_formation', 'formation_admin'], true)) {
            return 'Documents & formation';
        }
        if ($key === 'acces_parc_informatique') {
            return 'Système d’information';
        }
        if (str_contains($key, 'devis') || str_contains($key, 'offre') || str_contains($key, 'client') || str_contains($key, 'unite')) {
            return 'Devis & commercial';
        }
        if (str_contains($key, 'chantier') || str_contains($key, 'planning')) {
            return 'Chantiers & planning';
        }
        if (str_contains($key, 'decaissement') || str_contains($key, 'fiche') || str_contains($key, 'feb') || str_contains($key, 'tresorerie') || str_contains($key, 'compta') || str_contains($key, 'achat') || str_contains($key, 'factur') || str_contains($key, 'debourse') || str_contains($key, 'depenses') || str_contains($key, 'fournisseur')) {
            return 'Finance';
        }
        if (str_contains($key, 'rh') || str_contains($key, 'employe') || str_contains($key, 'hse')) {
            return 'RH & collaborateurs';
        }
        if (str_contains($key, 'mail') || str_contains($key, 'communication') || str_contains($key, 'carnet') || str_contains($key, 'assistance')) {
            return 'Communication';
        }
        if (str_contains($key, 'admin') || str_contains($key, 'gestion') || str_contains($key, 'profil') || str_contains($key, 'otp') || str_contains($key, 'conformite')) {
            return 'Administration';
        }
        return 'Général';
    }
}

