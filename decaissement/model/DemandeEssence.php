<?php

class DemandeEssence
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    // Récupérer une demande par son ID
    public function getById($id)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM demande_essence WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Ajouter une nouvelle demande
    public function create($data)
    {
        $this->ensureTable();
        $sql = "INSERT INTO demande_essence (num_fiche, code_bon, nom_beneficiaire, vehicule, quantite, montant, date_demande, motif, dg_nom)
                VALUES (:num_fiche, :code_bon, :nom_beneficiaire, :vehicule, :quantite, :montant, :date_demande, :motif, :dg_nom)";
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute([
            ':num_fiche'        => $data['num_fiche'],
            ':code_bon'         => $data['code_bon'],
            ':nom_beneficiaire' => $data['nom_beneficiaire'],
            ':vehicule'         => $data['vehicule'] ?? '',
            ':quantite'         => $data['quantite'] ?? 0,
            ':montant'          => $data['montant'] ?? 0,
            ':date_demande'     => $data['date_demande'],
            ':motif'            => $data['motif'] ?? null,
            ':dg_nom'           => $data['dg_nom'] ?? null
        ]);
        if ($ok) {
            return (int)$this->pdo->lastInsertId();
        }
        return 0;
    }

    // Lister toutes les demandes
    public function getAll()
    {
        $stmt = $this->pdo->query("SELECT * FROM demande_essence ORDER BY date_demande DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getByCodeBon(string $code_bon)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM demande_essence WHERE code_bon = ?");
        $stmt->execute([$code_bon]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Upsert par code_bon pour réplication
    public function upsertByCodeBon(array $data): bool
    {
        $this->ensureTable();
        // Tente un insert, sinon met à jour
        $sql = "INSERT INTO demande_essence (num_fiche, code_bon, nom_beneficiaire, vehicule, quantite, montant, date_demande, motif, dg_nom)
                VALUES (:num_fiche, :code_bon, :nom_beneficiaire, :vehicule, :quantite, :montant, :date_demande, :motif, :dg_nom)
                ON DUPLICATE KEY UPDATE
                  num_fiche=VALUES(num_fiche),
                  nom_beneficiaire=VALUES(nom_beneficiaire),
                  vehicule=VALUES(vehicule),
                  quantite=VALUES(quantite),
                  montant=VALUES(montant),
                  date_demande=VALUES(date_demande),
                  motif=VALUES(motif),
                  dg_nom=VALUES(dg_nom)";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            ':num_fiche' => $data['num_fiche'] ?? null,
            ':code_bon' => $data['code_bon'],
            ':nom_beneficiaire' => $data['nom_beneficiaire'] ?? '',
            ':vehicule' => $data['vehicule'] ?? '',
            ':quantite' => $data['quantite'] ?? 0,
            ':montant' => $data['montant'] ?? 0,
            ':date_demande' => $data['date_demande'] ?? date('Y-m-d'),
            ':motif' => $data['motif'] ?? null,
            ':dg_nom' => $data['dg_nom'] ?? null,
        ]);
    }

    public function setApprovedByCode(string $code_bon, string $who = 'SYSTEM', ?string $approverPhone = null): bool
    {
        $this->ensureTable();
        $sql = "UPDATE demande_essence SET validation_status='approved', validated_by=:by, validated_at=NOW(), approver_phone = COALESCE(:ph, approver_phone) WHERE code_bon=:code";
        $st = $this->pdo->prepare($sql);
        return $st->execute([':by' => $who, ':ph' => $approverPhone, ':code' => $code_bon]);
    }

    public function updateRecuByCodeBon(string $code_bon, string $num_recu, ?string $img): bool
    {
        $this->ensureTable();
        $sql = "UPDATE demande_essence SET num_recu_station=:nr, img_recu_station=:img WHERE code_bon=:c";
        $st = $this->pdo->prepare($sql);
        return $st->execute([':nr' => $num_recu, ':img' => $img, ':c' => $code_bon]);
    }

    private function ensureTable(): void
    {
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS demande_essence (
                id INT NOT NULL AUTO_INCREMENT,
                num_fiche VARCHAR(50) NULL,
                code_bon VARCHAR(50) NULL,
                nom_beneficiaire VARCHAR(100) NOT NULL,
                vehicule VARCHAR(100) NOT NULL,
                quantite DECIMAL(8,2) NOT NULL DEFAULT 0,
                montant DECIMAL(12,2) NOT NULL DEFAULT 0,
                date_demande DATE NOT NULL,
                motif VARCHAR(255) DEFAULT NULL,
                dg_nom VARCHAR(100) DEFAULT NULL,
                num_recu_station VARCHAR(50) DEFAULT NULL,
                img_recu_station VARCHAR(255) DEFAULT NULL,
                validation_status VARCHAR(20) DEFAULT NULL,
                validated_by VARCHAR(100) DEFAULT NULL,
                validated_at DATETIME DEFAULT NULL,
                approver_phone VARCHAR(30) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_code_bon (code_bon),
                INDEX(num_fiche)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Ajout/altérations selon présence effective des colonnes (compatible MySQL 5.7)
            $dbName = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $needs = [
                'num_fiche' => 'ALTER TABLE demande_essence ADD COLUMN num_fiche VARCHAR(50) NULL',
                'code_bon' => 'ALTER TABLE demande_essence ADD COLUMN code_bon VARCHAR(50) NULL',
                'nom_beneficiaire' => 'ALTER TABLE demande_essence ADD COLUMN nom_beneficiaire VARCHAR(100) NOT NULL',
                'vehicule' => 'ALTER TABLE demande_essence ADD COLUMN vehicule VARCHAR(100) NOT NULL',
                'quantite' => 'ALTER TABLE demande_essence ADD COLUMN quantite DECIMAL(8,2) NOT NULL DEFAULT 0',
                'montant' => 'ALTER TABLE demande_essence ADD COLUMN montant DECIMAL(12,2) NOT NULL DEFAULT 0',
                'date_demande' => 'ALTER TABLE demande_essence ADD COLUMN date_demande DATE NOT NULL',
                'motif' => 'ALTER TABLE demande_essence ADD COLUMN motif VARCHAR(255) DEFAULT NULL',
                'dg_nom' => 'ALTER TABLE demande_essence ADD COLUMN dg_nom VARCHAR(100) DEFAULT NULL',
                'num_recu_station' => 'ALTER TABLE demande_essence ADD COLUMN num_recu_station VARCHAR(50) DEFAULT NULL',
                'img_recu_station' => 'ALTER TABLE demande_essence ADD COLUMN img_recu_station VARCHAR(255) DEFAULT NULL',
                'validation_status' => 'ALTER TABLE demande_essence ADD COLUMN validation_status VARCHAR(20) DEFAULT NULL',
                'validated_by' => 'ALTER TABLE demande_essence ADD COLUMN validated_by VARCHAR(100) DEFAULT NULL',
                'validated_at' => 'ALTER TABLE demande_essence ADD COLUMN validated_at DATETIME DEFAULT NULL',
                'approver_phone' => 'ALTER TABLE demande_essence ADD COLUMN approver_phone VARCHAR(30) DEFAULT NULL',
                'created_at' => 'ALTER TABLE demande_essence ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP',
            ];
            foreach ($needs as $col => $ddl) {
                try {
                    $st = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'demande_essence' AND COLUMN_NAME = ?");
                    $st->execute([$dbName, $col]);
                    if ((int)$st->fetchColumn() === 0) {
                        $this->pdo->exec($ddl);
                    }
                } catch (\Throwable $e) {
                    // silencieux
                }
            }
            // Assurer index unique sur code_bon
            try {
                $st = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'demande_essence' AND INDEX_NAME = 'uniq_code_bon'");
                $st->execute([$dbName]);
                if ((int)$st->fetchColumn() === 0) {
                    $this->pdo->exec("ALTER TABLE demande_essence ADD UNIQUE INDEX uniq_code_bon (code_bon)");
                }
            } catch (\Throwable $e) {
                // silencieux
            }
        } catch (\Throwable $e) {
            // silencieux
        }
    }
}
