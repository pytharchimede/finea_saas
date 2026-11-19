<?php
// model/User.php
require_once __DIR__ . '/AccessManager.php';

class User
{
    private $pdo;
    private ?string $lastError = null;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function setLastError(?string $message): void
    {
        $this->lastError = $message;
    }

    private function ensurePersonnelLinkColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM user_devis_banamur")->fetchAll(PDO::FETCH_COLUMN, 0);
            $alter = [];

            foreach (['acces_signataire_point_jour', 'acces_arreter_point_jour', 'acces_decaisser_point_jour', 'acces_recommandations_tresorerie', 'acces_tresorerie_lecture'] as $pointPermission) {
                if (!in_array($pointPermission, $columns, true)) {
                    $alter[] = "ADD COLUMN {$pointPermission} TINYINT(1) NOT NULL DEFAULT 0";
                }
            }

            if (!in_array('personnel_id', $columns, true)) {
                $alter[] = "ADD COLUMN personnel_id INT NULL DEFAULT NULL";
            }

            $indexes = $this->pdo->query("SHOW INDEX FROM user_devis_banamur")->fetchAll(PDO::FETCH_ASSOC);
            $hasUnique = false;
            foreach ($indexes as $idx) {
                if (($idx['Key_name'] ?? null) === 'uniq_user_personnel_id') {
                    $hasUnique = true;
                    break;
                }
            }

            if (!$hasUnique) {
                $alter[] = "ADD UNIQUE KEY uniq_user_personnel_id (personnel_id)";
            }

            if ($alter) {
                $this->pdo->exec('ALTER TABLE user_devis_banamur ' . implode(', ', $alter));
            }
        } catch (Throwable $e) {
            // Migration possible manuellement si nécessaire.
        }

        $done = true;
    }

    public function ensurePersonnelLinkSupport(): void
    {
        $this->ensurePersonnelLinkColumns();
    }

    private function normalizePersonnelId($value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $id = (int) $value;
        return $id > 0 ? $id : null;
    }

    private function isPersonnelAlreadyLinked(int $personnelId, ?int $excludeUserId = null): bool
    {
        $this->ensurePersonnelLinkColumns();

        $sql = 'SELECT id FROM user_devis_banamur WHERE personnel_id = :pid';
        $params = ['pid' => $personnelId];
        if ($excludeUserId !== null) {
            $sql .= ' AND id <> :excludeId';
            $params['excludeId'] = $excludeUserId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }

    private function ensureOptionalPermissionColumns(): void
    {
        AccessManager::ensureOptionalPermissionColumns($this->pdo);
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM user_devis_banamur")->fetchAll(PDO::FETCH_COLUMN, 0);
            $alter = [];

            if (!in_array('acces_rh', $columns, true)) {
                $alter[] = "ADD COLUMN acces_rh TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_logistique', $columns, true)) {
                $alter[] = "ADD COLUMN acces_logistique TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_hse', $columns, true)) {
                $alter[] = "ADD COLUMN acces_hse TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_contrats', $columns, true)) {
                $alter[] = "ADD COLUMN acces_contrats TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_direction', $columns, true)) {
                $alter[] = "ADD COLUMN acces_direction TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_planning', $columns, true)) {
                $alter[] = "ADD COLUMN acces_planning TINYINT(1) NOT NULL DEFAULT 0";
            }
            // Historiquement, l'emploi du temps était accessible à tous.
            // On le rend maintenant paramétrable sans casser l'existant (valeur par défaut = 1).
            if (!in_array('acces_emploi_du_temps', $columns, true)) {
                $alter[] = "ADD COLUMN acces_emploi_du_temps TINYINT(1) NOT NULL DEFAULT 1";
            }

            // Espace employé : self-service (par défaut accessible à tous les comptes actifs)
            if (!in_array('acces_dashboard_stat_taches', $columns, true)) {
                $alter[] = "ADD COLUMN acces_dashboard_stat_taches TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_ticketing', $columns, true)) {
                $alter[] = "ADD COLUMN acces_ticketing TINYINT(1) NOT NULL DEFAULT 1";
            }
            if (!in_array('acces_planning_chantier', $columns, true)) {
                $alter[] = "ADD COLUMN acces_planning_chantier TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_employe', $columns, true)) {
                $alter[] = "ADD COLUMN acces_employe TINYINT(1) NOT NULL DEFAULT 1";
            }

            // Module Emails / Mailing
            if (!in_array('acces_mail', $columns, true)) {
                $alter[] = "ADD COLUMN acces_mail TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Module Fournisseurs
            if (!in_array('acces_fournisseur', $columns, true)) {
                $alter[] = "ADD COLUMN acces_fournisseur TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Module Facturation
            if (!in_array('acces_facturation', $columns, true)) {
                $alter[] = "ADD COLUMN acces_facturation TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_relink_fiches_debourse', $columns, true)) {
                $alter[] = "ADD COLUMN acces_relink_fiches_debourse TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('ajuster_beneficiaire_fournisseur_fiche', $columns, true)) {
                $alter[] = "ADD COLUMN ajuster_beneficiaire_fournisseur_fiche TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('regulariser_fournisseur_sans_preuve', $columns, true)) {
                $alter[] = "ADD COLUMN regulariser_fournisseur_sans_preuve TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_veille_ao', $columns, true)) {
                $alter[] = "ADD COLUMN acces_veille_ao TINYINT(1) NOT NULL DEFAULT 0";
            }
            foreach (['ao_view', 'ao_export', 'ao_manage', 'ao_mark_interesting', 'ao_mark_not_interested', 'ao_create_crm', 'ao_manage_alerts', 'ao_manage_diffusion', 'ao_admin', 'ao_dg_decision'] as $aoPermission) {
                if (!in_array($aoPermission, $columns, true)) {
                    $alter[] = "ADD COLUMN {$aoPermission} TINYINT(1) NOT NULL DEFAULT 0";
                }
            }
            if (!in_array('acces_crm', $columns, true)) {
                $alter[] = "ADD COLUMN acces_crm TINYINT(1) NOT NULL DEFAULT 0";
            }
            foreach (['acces_crm_dashboard', 'acces_crm_prospects', 'acces_crm_clients', 'acces_crm_opportunites', 'acces_crm_rdv', 'acces_crm_admin'] as $crmPermission) {
                if (!in_array($crmPermission, $columns, true)) {
                    $alter[] = "ADD COLUMN {$crmPermission} TINYINT(1) NOT NULL DEFAULT 0";
                }
            }
            if (!in_array('acces_feb', $columns, true)) {
                $alter[] = "ADD COLUMN acces_feb TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_depenses_planifiees', $columns, true)) {
                $alter[] = "ADD COLUMN acces_depenses_planifiees TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_tresorerie', $columns, true)) {
                $alter[] = "ADD COLUMN acces_tresorerie TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_signataire_point_jour', $columns, true)) {
                $alter[] = "ADD COLUMN acces_signataire_point_jour TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Module Communication (remplace WhatsApp)
            if (!in_array('acces_communication', $columns, true)) {
                $alter[] = "ADD COLUMN acces_communication TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_documentation', $columns, true)) {
                $alter[] = "ADD COLUMN acces_documentation TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('document_upload', $columns, true)) {
                $alter[] = "ADD COLUMN document_upload TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('document_edit', $columns, true)) {
                $alter[] = "ADD COLUMN document_edit TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('document_archive', $columns, true)) {
                $alter[] = "ADD COLUMN document_archive TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('document_admin', $columns, true)) {
                $alter[] = "ADD COLUMN document_admin TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_formation', $columns, true)) {
                $alter[] = "ADD COLUMN acces_formation TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_carnet_adresse', $columns, true)) {
                $alter[] = "ADD COLUMN acces_carnet_adresse TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_assistance_distance', $columns, true)) {
                $alter[] = "ADD COLUMN acces_assistance_distance TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('formation_admin', $columns, true)) {
                $alter[] = "ADD COLUMN formation_admin TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Permission métier: terminer/archiver un chantier (par défaut interdit)
            if (!in_array('valider_archivage_chantier', $columns, true)) {
                $alter[] = "ADD COLUMN valider_archivage_chantier TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Validation FEB par service (liste de services autorisés)
            if (!in_array('feb_validation_services', $columns, true)) {
                $alter[] = "ADD COLUMN feb_validation_services TEXT DEFAULT NULL";
            }

            if ($alter) {
                $this->pdo->exec('ALTER TABLE user_devis_banamur ' . implode(', ', $alter));
            }
        } catch (Throwable $e) {
            // La migration SQL peut aussi être exécutée manuellement si nécessaire.
        }

        $done = true;
    }

    /**
     * Génère un mot de passe aléatoire.
     */
    public function generateRandomPassword(int $length = 10): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_-+=';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    /**
     * Crée un nouvel utilisateur et retourne son ID, ou false en cas d'erreur.
     */
    // public function createUser(array $data)
    // {
    //     // Hash du mot de passe
    //     $plainPassword = $data['password'] ?? $this->generateRandomPassword(12);
    //     $hashedPassword = hash('sha512', $plainPassword);

    //     $sql = "INSERT INTO user_devis_banamur (mail_pro, password, nom, prenom, modifier_debourse, modifier_devis, visualiser_devis, soumettre_devis, masquer_devis, envoyer_devis, valider_devis, active) 
    //         VALUES (:mail_pro, :password, :nom, :prenom, :modifier_debourse, :modifier_devis, :visualiser_devis, :soumettre_devis, :masquer_devis, :envoyer_devis, :valider_devis, 1)";

    //     $stmt = $this->pdo->prepare($sql);
    //     $params = [
    //         'mail_pro' => $data['mail_pro'] ?? '',
    //         'password' => $hashedPassword,
    //         'nom' => $data['nom'] ?? '',
    //         'prenom' => $data['prenom'] ?? '',
    //         'modifier_debourse' => !empty($data['modifier_debourse']) ? 1 : 0,
    //         'modifier_devis' => !empty($data['modifier_devis']) ? 1 : 0,
    //         'visualiser_devis' => !empty($data['visualiser_devis']) ? 1 : 0,
    //         'soumettre_devis' => !empty($data['soumettre_devis']) ? 1 : 0,
    //         'masquer_devis' => !empty($data['masquer_devis']) ? 1 : 0,
    //         'envoyer_devis' => !empty($data['envoyer_devis']) ? 1 : 0,
    //         'valider_devis' => !empty($data['valider_devis']) ? 1 : 0,
    //     ];

    //     $ok = $stmt->execute($params);
    //     if (!$ok) {
    //         return false;
    //     }

    //     $userId = (int)$this->pdo->lastInsertId();

    //     // Retourner l'ID et le mot de passe généré pour envoi d'email
    //     return ['id' => $userId, 'password' => $plainPassword];
    // }

    /**
     * Met à jour un utilisateur, sans réinitialiser le mot de passe si vide.
     */
    // public function updateUser(int $id, array $data): bool
    // {
    //     $user = $this->getUserById($id);
    //     if (!$user) {
    //         return false;
    //     }

    //     // si mot de passe fourni, on le hache
    //     $password = $user['password'];
    //     if (!empty($data['password'])) {
    //         $password = hash('sha512', $data['password']);
    //     }

    //     $sql = "UPDATE user_devis_banamur SET 
    //         mail_pro = :mail_pro,
    //         password = :password,
    //         nom = :nom,
    //         prenom = :prenom,
    //         modifier_debourse = :modifier_debourse,
    //         modifier_devis = :modifier_devis,
    //         visualiser_devis = :visualiser_devis,
    //         soumettre_devis = :soumettre_devis,
    //         masquer_devis = :masquer_devis,
    //         envoyer_devis = :envoyer_devis,
    //         valider_devis = :valider_devis
    //         WHERE id = :id";

    //     $stmt = $this->pdo->prepare($sql);
    //     return $stmt->execute([
    //         'mail_pro' => $data['mail_pro'] ?? $user['mail_pro'],
    //         'password' => $password,
    //         'nom' => $data['nom'] ?? $user['nom'],
    //         'prenom' => $data['prenom'] ?? $user['prenom'],
    //         'modifier_debourse' => !empty($data['modifier_debourse']) ? 1 : 0,
    //         'modifier_devis' => !empty($data['modifier_devis']) ? 1 : 0,
    //         'visualiser_devis' => !empty($data['visualiser_devis']) ? 1 : 0,
    //         'soumettre_devis' => !empty($data['soumettre_devis']) ? 1 : 0,
    //         'masquer_devis' => !empty($data['masquer_devis']) ? 1 : 0,
    //         'envoyer_devis' => !empty($data['envoyer_devis']) ? 1 : 0,
    //         'valider_devis' => !empty($data['valider_devis']) ? 1 : 0,
    //         'id' => $id,
    //     ]);
    // }

    /**
     * Active / désactive un utilisateur.
     */
    // public function setActive(int $id, bool $active): bool
    // {
    //     $stmt = $this->pdo->prepare("UPDATE user_devis_banamur SET active = :active WHERE id = :id");
    //     return $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    // }

    /**
     * Envoie un email de notification à l'utilisateur après création ou changement de mot de passe.
     */
    public function sendNotificationEmail(array $user, string $subject, string $message): bool
    {
        require_once __DIR__ . '/../services/NotificationEmailChannelService.php';
        $emailChannel = new \NotificationEmailChannelService();
        return $emailChannel->send($user['mail_pro'], trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')), $subject, $message);
    }

    public function ajouterUtilisateur($data)
    {
        $sql = "INSERT INTO user_devis_banamur (mail_pro, password, nom, prenom, modifier_debourse, modifier_devis, visualiser_devis, soumettre_devis, masquer_devis, envoyer_devis, valider_devis, active)
            VALUES (:mail_pro, :password, :nom, :prenom, :modifier_debourse, :modifier_devis, :visualiser_devis, :soumettre_devis, :masquer_devis, :envoyer_devis, :valider_devis, 1)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($data);
    }

    public function findUserByEmail($mail_pro)
    {
        $mail_pro = strtolower(trim((string)$mail_pro));
        $stmt = $this->pdo->prepare("
            SELECT u.*
            FROM user_devis_banamur u
            LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
            WHERE u.mail_pro = :mail_pro
                AND u.active = 1
                AND (
                    u.personnel_id IS NULL
                    OR p.id_personnel_soignant IS NULL
                    OR p.date_sortie IS NULL
                    OR p.date_sortie = '0000-00-00'
                )
        ");
        $stmt->execute(['mail_pro' => $mail_pro]);
        return $stmt->fetch();
    }

    public function verifyPassword($mail_pro, $password)
    {
        $user = $this->findUserByEmail($mail_pro);

        if (!$user) {
            return false;
        }

        $storedHash = (string) ($user['password'] ?? '');

        // Nouveaux comptes : hachage moderne.
        if (password_verify($password, $storedHash)) {
            return $user;
        }

        // Compatibilité temporaire avec les anciens comptes SHA-512.
        if (
            preg_match('/^[a-f0-9]{128}$/i', $storedHash)
            && hash_equals($storedHash, hash('sha512', $password))
        ) {
            $newHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $this->pdo->prepare(
                'UPDATE user_devis_banamur
                 SET password = ?
                 WHERE id = ? AND password = ?'
            );

            $stmt->execute([
                $newHash,
                $user['id'],
                $storedHash
            ]);

            $user['password'] = $newHash;
            return $user;
        }

        return false;
    }


    // Vérifier les droits spécifiques de l'utilisateur
    public function hasPermission($user, $permission)
    {
        return isset($user[$permission]) && $user[$permission] == 1;
    }

    public function findDirecteurTechnique()
    {
        $stmt = $this->pdo->prepare("
        SELECT u.* 
        FROM user_devis_banamur u
        INNER JOIN role_devis_banamur r ON u.role_id = r.id_role_devis
        WHERE r.lib_role_devis = :libelle AND u.active = 1
    ");
        $stmt->execute(['libelle' => 'directeur technique']);
        return $stmt->fetch();
    }

    public function findDirecteurGeneral()
    {
        $stmt = $this->pdo->prepare("
        SELECT u.* 
        FROM user_devis_banamur u
        INNER JOIN role_devis_banamur r ON u.role_id = r.id_role_devis
        WHERE r.lib_role_devis = :libelle AND u.active = 1
    ");
        $stmt->execute(['libelle' => 'directeur general']);
        return $stmt->fetch();
    }

    public function desactiverUtilisateur($id)
    {
        $sql = "UPDATE user_devis_banamur SET active = 0 WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function getUserById($id)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM user_devis_banamur WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findActiveUserById(int $id)
    {
        $stmt = $this->pdo->prepare("SELECT u.*
            FROM user_devis_banamur u
            LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
            WHERE u.id = :id AND u.active = 1
              AND (u.personnel_id IS NULL OR p.id_personnel_soignant IS NULL OR p.date_sortie IS NULL OR p.date_sortie = '0000-00-00')
            LIMIT 1");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public function modifierUtilisateur($id, $data)
    {
        $this->setLastError(null);
        $this->ensurePersonnelLinkColumns();

        // Récupère l'utilisateur actuel
        $user = $this->getUserById($id);
        if (!$user) return false;

        // Normalisation booléenne (pour valeurs venant de checkbox: on/true/1)
        $toBool = function ($v) {
            if (is_bool($v)) return $v ? 1 : 0;
            if (is_numeric($v)) return ((int)$v) ? 1 : 0;
            $s = strtolower((string)$v);
            return in_array($s, ['1', 'true', 'on', 'yes', 'y'], true) ? 1 : 0;
        };

        // Clés de permissions gérées par checkbox dans le formulaire
        $permKeys = [
            'modifier_debourse',
            'modifier_devis',
            'visualiser_devis',
            'soumettre_devis',
            'masquer_devis',
            'envoyer_devis',
            'valider_devis',
        ];

        // Si au moins une clé de permission est présente dans $data, on considère que
        // le formulaire de droits a été soumis: toute clé absente => 0 (non cochée)
        // Sinon, on conserve les valeurs existantes en base
        $hasAnyPermKey = false;
        foreach ($permKeys as $k) {
            if (array_key_exists($k, $data)) {
                $hasAnyPermKey = true;
                break;
            }
        }

        // On garde les anciennes valeurs si non fournies dans $data
        $params = [
            'mail_pro' => strtolower(trim((string)($data['mail_pro'] ?? $user['mail_pro']))),
            'password' => $data['password'] ?? $user['password'],
            'nom' => $data['nom'] ?? $user['nom'],
            'prenom' => $data['prenom'] ?? $user['prenom'],
            'photo' => $data['photo'] ?? $user['photo'],
            'personnel_id' => array_key_exists('personnel_id', $data)
                ? $this->normalizePersonnelId($data['personnel_id'])
                : ($user['personnel_id'] ?? null),
            'modifier_debourse' => array_key_exists('modifier_debourse', $data)
                ? $toBool($data['modifier_debourse'])
                : ($hasAnyPermKey ? 0 : (int)($user['modifier_debourse'] ?? 0)),
            'modifier_devis' => array_key_exists('modifier_devis', $data)
                ? $toBool($data['modifier_devis'])
                : ($hasAnyPermKey ? 0 : (int)$user['modifier_devis']),
            'visualiser_devis' => array_key_exists('visualiser_devis', $data)
                ? $toBool($data['visualiser_devis'])
                : ($hasAnyPermKey ? 0 : (int)$user['visualiser_devis']),
            'soumettre_devis' => array_key_exists('soumettre_devis', $data)
                ? $toBool($data['soumettre_devis'])
                : ($hasAnyPermKey ? 0 : (int)$user['soumettre_devis']),
            'masquer_devis' => array_key_exists('masquer_devis', $data)
                ? $toBool($data['masquer_devis'])
                : ($hasAnyPermKey ? 0 : (int)$user['masquer_devis']),
            'envoyer_devis' => array_key_exists('envoyer_devis', $data)
                ? $toBool($data['envoyer_devis'])
                : ($hasAnyPermKey ? 0 : (int)$user['envoyer_devis']),
            'valider_devis' => array_key_exists('valider_devis', $data)
                ? $toBool($data['valider_devis'])
                : ($hasAnyPermKey ? 0 : (int)$user['valider_devis']),
            'id' => $id
        ];

        if (!empty($params['personnel_id']) && $this->isPersonnelAlreadyLinked((int)$params['personnel_id'], (int)$id)) {
            $this->setLastError('Ce membre du personnel est déjà lié à un autre compte.');
            return false;
        }

        $sql = "UPDATE user_devis_banamur SET 
            mail_pro = :mail_pro, 
            password = :password, 
            nom = :nom, 
            prenom = :prenom, 
            photo = :photo,
            personnel_id = :personnel_id,
            modifier_debourse = :modifier_debourse,
            modifier_devis = :modifier_devis, 
            visualiser_devis = :visualiser_devis, 
            soumettre_devis = :soumettre_devis, 
            masquer_devis = :masquer_devis, 
            envoyer_devis = :envoyer_devis, 
            valider_devis = :valider_devis 
            WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute($params);
        if (!$ok) {
            $this->setLastError('Erreur lors de la mise à jour.');
        }
        return $ok;
    }
    public function reactiverUtilisateur($id)
    {
        $sql = "UPDATE user_devis_banamur SET active = 1 WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    public function supprimerUtilisateur($id)
    {
        $sql = "DELETE FROM user_devis_banamur WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute(['id' => $id]);
    }

    // Récupère tous les utilisateurs (pour la liste des responsables)
    public function getUtilisateurs()
    {
        $stmt = $this->pdo->prepare("SELECT * FROM user_devis_banamur");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPermissions($userId)
    {
        $stmt = $this->pdo->prepare("
        SELECT *
        FROM user_devis_banamur
        WHERE id = :id
    ");

        $stmt->execute(['id' => $userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function userCan($userId, $permission)
    {
        $user = $this->getUserById($userId);

        return !empty($user[$permission]);
    }

    public function loadUserSession($user)
    {
        foreach ($user as $key => $value) {
            $_SESSION[$key] = $value;
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = trim($user['prenom'] . ' ' . $user['nom']);
    }

    /**
     * Génère un mot de passe aléatoire sécurisé.
     */
    // public static function generateRandomPassword(int $length = 12): string
    // {
    //     $bytes = random_bytes($length);
    //     return substr(str_replace(['/', '+', '='], '', base64_encode($bytes)), 0, $length);
    // }

    /**
     * Hash un mot de passe pour stockage.
     */
    public static function hashPassword(string $password): string
    {
        return hash('sha512', $password);
    }

    /**
     * Crée un nouvel utilisateur et retourne son ID ou null en cas d'échec.
     */
    public function createUser(array $data): ?int
    {
        $this->setLastError(null);
        $this->ensureOptionalPermissionColumns();
        $this->ensurePersonnelLinkColumns();

        $data['mail_pro'] = strtolower(trim((string)($data['mail_pro'] ?? '')));

        $personnelId = $this->normalizePersonnelId($data['personnel_id'] ?? null);
        if ($personnelId !== null && $this->isPersonnelAlreadyLinked($personnelId)) {
            $this->setLastError('Ce membre du personnel est déjà lié à un autre compte.');
            return null;
        }

        $password = (isset($data['password']) && trim($data['password']) !== '')
            ? $data['password']
            : $this->generateRandomPassword(10);

        $hash = self::hashPassword($password);

        $sql = "INSERT INTO user_devis_banamur (

        mail_pro,
        password,
        nom,
        prenom,
        personnel_id,
        photo,
        signature,

        modifier_debourse,
        modifier_devis,
        modifier_devis_chantier_actif,
        modifier_dates_travaux,
        visualiser_devis,
        soumettre_devis,
        masquer_devis,
        envoyer_devis,
        valider_devis,

        valider_archivage_chantier,

        acces_decaissement,
        gestion_utilisateur,
        acces_devis,
        acces_conformite,
        acces_dashboard,
        acces_detail_chantier,
        acces_liste_chantier,
        acces_liste_client,
        acces_liste_facture,
        acces_liste_offre,
        acces_generer_devis,
        acces_profil,
        acces_gestion_acces,
        acces_dec_chantiers,
        acces_budgets_chantiers,
        acces_approbation,
        acces_achat,
        acces_feb,
        acces_depenses_planifiees,
        acces_tresorerie,
        acces_tresorerie_lecture,
        acces_compta,
        acces_fiches_decaissement,
        acces_otp,
        acces_point_financier,
        acces_relink_fiches_debourse,
        acces_historique_fiches,
        acces_fiche_details,
        acces_recap_carburant,
        acces_operations_carburant,
        acces_creation_fiche_decaissement,

        acces_direction,
        acces_planning,
        acces_emploi_du_temps,
        acces_dashboard_stat_taches,

        acces_mail,

        acces_fournisseur,
        acces_facturation,

        acces_communication,
        acces_documentation,
        document_upload,
        document_edit,
        document_archive,
        document_admin,
        acces_formation,
        acces_carnet_adresse,
        acces_assistance_distance,
        formation_admin,

        acces_admin,
        acces_contrats,
        acces_rh,
        acces_logistique,
        acces_hse,
        feb_validation_services,
        active,
        role_id

    ) VALUES (

        :mail_pro,
        :password,
        :nom,
        :prenom,
        :personnel_id,
        :photo,
        :signature,

        :modifier_debourse,
        :modifier_devis,
        :modifier_devis_chantier_actif,
        :modifier_dates_travaux,
        :visualiser_devis,
        :soumettre_devis,
        :masquer_devis,
        :envoyer_devis,
        :valider_devis,

        :valider_archivage_chantier,

        :acces_decaissement,
        :gestion_utilisateur,
        :acces_devis,
        :acces_conformite,
        :acces_dashboard,
        :acces_detail_chantier,
        :acces_liste_chantier,
        :acces_liste_client,
        :acces_liste_facture,
        :acces_liste_offre,
        :acces_generer_devis,
        :acces_profil,
        :acces_gestion_acces,
        :acces_dec_chantiers,
        :acces_budgets_chantiers,
        :acces_approbation,
        :acces_achat,
        :acces_feb,
        :acces_depenses_planifiees,
        :acces_tresorerie,
        :acces_tresorerie_lecture,
        :acces_compta,
        :acces_fiches_decaissement,
        :acces_otp,
        :acces_point_financier,
        :acces_relink_fiches_debourse,
        :acces_historique_fiches,
        :acces_fiche_details,
        :acces_recap_carburant,
        :acces_operations_carburant,
        :acces_creation_fiche_decaissement,

        :acces_direction,
        :acces_planning,
        :acces_emploi_du_temps,
        :acces_dashboard_stat_taches,

        :acces_mail,

        :acces_fournisseur,
        :acces_facturation,

        :acces_communication,
        :acces_documentation,
        :document_upload,
        :document_edit,
        :document_archive,
        :document_admin,
        :acces_formation,
        :acces_carnet_adresse,
        :acces_assistance_distance,
        :formation_admin,

        :acces_admin,
        :acces_contrats,
        :acces_rh,
        :acces_logistique,
        :acces_hse,
        :feb_validation_services,
        :active,
        :role_id
    )";

        $stmt = $this->pdo->prepare($sql);

        $params = [

            'mail_pro' => $data['mail_pro'] ?? '',
            'password' => $hash,
            'nom' => $data['nom'] ?? '',
            'prenom' => $data['prenom'] ?? '',
            'personnel_id' => $personnelId,
            'photo' => '',
            'signature' => '',

            'modifier_debourse' => !empty($data['modifier_debourse']) ? 1 : 0,
            'modifier_devis' => !empty($data['modifier_devis']) ? 1 : 0,
            'modifier_devis_chantier_actif' => !empty($data['modifier_devis_chantier_actif']) ? 1 : 0,
            'modifier_dates_travaux' => !empty($data['modifier_dates_travaux']) ? 1 : 0,
            'visualiser_devis' => !empty($data['visualiser_devis']) ? 1 : 0,
            'soumettre_devis' => !empty($data['soumettre_devis']) ? 1 : 0,
            'masquer_devis' => !empty($data['masquer_devis']) ? 1 : 0,
            'envoyer_devis' => !empty($data['envoyer_devis']) ? 1 : 0,
            'valider_devis' => !empty($data['valider_devis']) ? 1 : 0,

            'valider_archivage_chantier' => !empty($data['valider_archivage_chantier']) ? 1 : 0,

            'acces_decaissement' => !empty($data['acces_decaissement']) ? 1 : 0,
            'gestion_utilisateur' => !empty($data['gestion_utilisateur']) ? 1 : 0,
            'acces_devis' => !empty($data['acces_devis']) ? 1 : 0,
            'acces_conformite' => !empty($data['acces_conformite']) ? 1 : 0,
            'acces_dashboard' => !empty($data['acces_dashboard']) ? 1 : 0,
            'acces_detail_chantier' => !empty($data['acces_detail_chantier']) ? 1 : 0,
            'acces_liste_chantier' => !empty($data['acces_liste_chantier']) ? 1 : 0,
            'acces_liste_client' => !empty($data['acces_liste_client']) ? 1 : 0,
            'acces_liste_facture' => !empty($data['acces_liste_facture']) ? 1 : 0,
            'acces_liste_offre' => !empty($data['acces_liste_offre']) ? 1 : 0,
            'acces_generer_devis' => !empty($data['acces_generer_devis']) ? 1 : 0,
            'acces_profil' => !empty($data['acces_profil']) ? 1 : 0,
            'acces_gestion_acces' => !empty($data['acces_gestion_acces']) ? 1 : 0,
            'acces_dec_chantiers' => !empty($data['acces_dec_chantiers']) ? 1 : 0,
            'acces_budgets_chantiers' => !empty($data['acces_budgets_chantiers']) ? 1 : 0,
            'acces_approbation' => !empty($data['acces_approbation']) ? 1 : 0,
            'acces_achat' => !empty($data['acces_achat']) ? 1 : 0,
            'acces_feb' => !empty($data['acces_feb']) ? 1 : 0,
            'acces_depenses_planifiees' => !empty($data['acces_depenses_planifiees']) ? 1 : 0,
            'acces_tresorerie' => !empty($data['acces_tresorerie']) ? 1 : 0,
            'acces_tresorerie_lecture' => !empty($data['acces_tresorerie_lecture']) ? 1 : 0,
            'acces_compta' => !empty($data['acces_compta']) ? 1 : 0,
            'acces_fiches_decaissement' => !empty($data['acces_fiches_decaissement']) ? 1 : 0,
            'acces_otp' => !empty($data['acces_otp']) ? 1 : 0,
            'acces_point_financier' => !empty($data['acces_point_financier']) ? 1 : 0,
            'acces_relink_fiches_debourse' => !empty($data['acces_relink_fiches_debourse']) ? 1 : 0,
            'acces_historique_fiches' => !empty($data['acces_historique_fiches']) ? 1 : 0,
            'acces_fiche_details' => !empty($data['acces_fiche_details']) ? 1 : 0,
            'acces_recap_carburant' => !empty($data['acces_recap_carburant']) ? 1 : 0,
            'acces_operations_carburant' => !empty($data['acces_operations_carburant']) ? 1 : 0,
            'acces_creation_fiche_decaissement' => !empty($data['acces_creation_fiche_decaissement']) ? 1 : 0,

            'acces_direction' => !empty($data['acces_direction']) ? 1 : 0,
            'acces_planning' => !empty($data['acces_planning']) ? 1 : 0,
            'acces_emploi_du_temps' => array_key_exists('acces_emploi_du_temps', $data)
                ? (!empty($data['acces_emploi_du_temps']) ? 1 : 0)
                : 1,
            'acces_dashboard_stat_taches' => !empty($data['acces_dashboard_stat_taches']) ? 1 : 0,

            'acces_mail' => !empty($data['acces_mail']) ? 1 : 0,

            'acces_fournisseur' => !empty($data['acces_fournisseur']) ? 1 : 0,
            'acces_facturation' => !empty($data['acces_facturation']) ? 1 : 0,

            'acces_communication' => !empty($data['acces_communication']) ? 1 : 0,
            'acces_documentation' => !empty($data['acces_documentation']) ? 1 : 0,
            'document_upload' => !empty($data['document_upload']) ? 1 : 0,
            'document_edit' => !empty($data['document_edit']) ? 1 : 0,
            'document_archive' => !empty($data['document_archive']) ? 1 : 0,
            'document_admin' => !empty($data['document_admin']) ? 1 : 0,
            'acces_formation' => !empty($data['acces_formation']) ? 1 : 0,
            'acces_carnet_adresse' => !empty($data['acces_carnet_adresse']) ? 1 : 0,
            'acces_assistance_distance' => !empty($data['acces_assistance_distance']) ? 1 : 0,
            'formation_admin' => !empty($data['formation_admin']) ? 1 : 0,

            'acces_admin' => !empty($data['acces_admin']) ? 1 : 0,
            'acces_contrats' => !empty($data['acces_contrats']) ? 1 : 0,
            'acces_rh' => !empty($data['acces_rh']) ? 1 : 0,
            'acces_logistique' => !empty($data['acces_logistique']) ? 1 : 0,
            'acces_hse' => !empty($data['acces_hse']) ? 1 : 0,
            'feb_validation_services' => array_key_exists('feb_validation_services', $data) ? $data['feb_validation_services'] : null,
            'active' => isset($data['active']) ? (int)$data['active'] : 1,
            'role_id' => isset($data['role_id']) ? (int)$data['role_id'] : 0,

        ];

        //Vérifier doublon mail pro
        $existingUser = $this->getUserByEmail($data['mail_pro']);
        if ($existingUser) {
            // Si l'utilisateur existe déjà et est actif, on retourne un message d'explication
            $this->setLastError('Un compte actif existe déjà avec cet email professionnel.');
            return null;
        }



        $success = $stmt->execute($params);

        if (!$success) {
            $this->setLastError('Erreur lors de la création de l\'utilisateur.');
            return null;
        }

        $insertId = (int)$this->pdo->lastInsertId();

        $permissionColumns = AccessManager::permissionColumns($this->pdo);
        $universalPermissions = AccessManager::universalPermissions($this->pdo);
        $sets = [];
        $permissionParams = ['id' => $insertId];
        foreach ($permissionColumns as $permission) {
            if (!array_key_exists($permission, $data) && !in_array($permission, $universalPermissions, true)) {
                continue;
            }
            $sets[] = $permission . ' = :' . $permission;
            $permissionParams[$permission] = in_array($permission, $universalPermissions, true)
                ? 1
                : (!empty($data[$permission]) ? 1 : 0);
        }
        if ($sets) {
            $this->pdo->prepare('UPDATE user_devis_banamur SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($permissionParams);
        }

        if (!empty($data['send_welcome_email'])) {
            $user = $this->getUserById($insertId);
            if ($user) {
                self::sendWelcomeEmail($user, $password);
            }
        }

        return $insertId;
    }


    /**
     * Récupère un utilisateur actif par son email professionnel.
     */
    public function getUserByEmail($mail_pro)
    {
        $mail_pro = strtolower(trim((string)$mail_pro));
        $stmt = $this->pdo->prepare("
            SELECT u.*
            FROM user_devis_banamur u
            LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
            WHERE u.mail_pro = :mail_pro
                AND u.active = 1
                AND (
                    u.personnel_id IS NULL
                    OR p.id_personnel_soignant IS NULL
                    OR p.date_sortie IS NULL
                    OR p.date_sortie = '0000-00-00'
                )
        ");
        $stmt->execute(['mail_pro' => $mail_pro]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Met à jour un utilisateur existant.
     */
    public function updateUser(int $id, array $data): bool
    {
        $this->setLastError(null);
        $this->ensureOptionalPermissionColumns();
        $this->ensurePersonnelLinkColumns();

        if (array_key_exists('mail_pro', $data)) {
            $data['mail_pro'] = strtolower(trim((string)$data['mail_pro']));
        }

        if (array_key_exists('personnel_id', $data)) {
            $personnelId = $this->normalizePersonnelId($data['personnel_id']);
            $data['personnel_id'] = $personnelId;

            if ($personnelId !== null && $this->isPersonnelAlreadyLinked($personnelId, $id)) {
                $this->setLastError('Ce membre du personnel est déjà lié à un autre compte.');
                return false;
            }
        }

        // Si un nouveau mot de passe est fourni
        $newPassword = $data['password'] ?? null;
        if ($newPassword) {
            $data['password'] = self::hashPassword($newPassword);
        } else {
            unset($data['password']);
        }

        $fields = [

            'mail_pro',
            'nom',
            'prenom',
            'photo',

            'personnel_id',

            'modifier_debourse',
            'modifier_devis',
            'modifier_devis_chantier_actif',
            'modifier_dates_travaux',
            'visualiser_devis',
            'soumettre_devis',
            'masquer_devis',
            'envoyer_devis',
            'valider_devis',

            'valider_archivage_chantier',

            'acces_decaissement',
            'gestion_utilisateur',
            'acces_devis',
            'acces_conformite',
            'acces_dashboard',
            'acces_detail_chantier',
            'acces_liste_chantier',
            'acces_liste_client',
            'acces_liste_facture',
            'acces_liste_offre',
            'acces_generer_devis',
            'acces_profil',
            'acces_gestion_acces',
            'acces_dec_chantiers',
            'acces_budgets_chantiers',
            'acces_approbation',
            'acces_achat',
            'acces_feb',
            'acces_depenses_planifiees',
            'acces_tresorerie',
            'acces_tresorerie_lecture',
            'acces_compta',
            'acces_fiches_decaissement',
            'acces_otp',
            'acces_point_financier',
            'acces_relink_fiches_debourse',
            'acces_veille_ao',
            'ao_view',
            'ao_export',
            'ao_manage',
            'ao_mark_interesting',
            'ao_mark_not_interested',
            'ao_create_crm',
            'ao_manage_alerts',
            'ao_manage_diffusion',
            'ao_admin',
            'ao_dg_decision',
            'acces_historique_fiches',
            'acces_fiche_details',
            'acces_recap_carburant',
            'acces_operations_carburant',
            'acces_creation_fiche_decaissement',
            'ajuster_beneficiaire_fournisseur_fiche',
            'regulariser_fournisseur_sans_preuve',

            'acces_direction',
            'acces_crm',
            'acces_crm_dashboard',
            'acces_crm_prospects',
            'acces_crm_clients',
            'acces_crm_opportunites',
            'acces_crm_rdv',
            'acces_crm_admin',
            'acces_planning',
            'acces_emploi_du_temps',
            'acces_dashboard_stat_taches',

            'acces_mail',

            'acces_fournisseur',
            'acces_facturation',

            'acces_communication',
            'acces_documentation',
            'document_upload',
            'document_edit',
            'document_archive',
            'document_admin',
            'acces_formation',
            'acces_carnet_adresse',
            'acces_assistance_distance',
            'formation_admin',

            'acces_admin',
            'acces_contrats',
            'acces_rh',
            'acces_logistique',
            'acces_hse',
            'feb_validation_services',
            'active',
            'role_id'

        ];
        $fields = array_values(array_unique(array_merge($fields, AccessManager::permissionColumns($this->pdo))));
        foreach (AccessManager::universalPermissions($this->pdo) as $permission) {
            $data[$permission] = 1;
        }

        $updates = [];
        $params = ['id' => $id];

        foreach ($fields as $field) {

            if (array_key_exists($field, $data)) {

                $updates[] = "$field = :$field";

                $params[$field] = is_bool($data[$field])
                    ? (int)$data[$field]
                    : $data[$field];
            }
        }

        if (isset($data['password'])) {
            $updates[] = "password = :password";
            $params['password'] = $data['password'];
        }

        if (empty($updates)) {
            return true;
        }

        $sql = "UPDATE user_devis_banamur SET " . implode(', ', $updates) . " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);

        $ok = $stmt->execute($params);
        if (!$ok) {
            $this->setLastError('Erreur lors de la mise à jour.');
        }
        return $ok;
    }

    /**
     * Active / désactive un utilisateur.
     */
    public function setActive(int $id, bool $active): bool
    {
        $stmt = $this->pdo->prepare("UPDATE user_devis_banamur SET active = :active WHERE id = :id");
        return $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    /**
     * Envoie un email de bienvenue à un nouvel utilisateur.
     */
    public static function sendWelcomeEmail(array $user, string $plainPassword): bool
    {
        require_once __DIR__ . '/../services/NotificationEmailChannelService.php';
        $emailChannel = new \NotificationEmailChannelService();
        return $emailChannel->sendWelcomeEmail($user, $plainPassword);
    }
}

