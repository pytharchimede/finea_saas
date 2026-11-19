<?php
require_once __DIR__.'/../../services/FinancialWorkflowProgress.php';

class BonCommande
{
    private PDO $pdo;
    private bool $ready = true;
    private ?string $initError = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS bon_commande (
            id INT NOT NULL AUTO_INCREMENT,
            num_fiche VARCHAR(60) NOT NULL,
            bc_number VARCHAR(80) NULL,
            bc_date DATE NOT NULL,

            fournisseur_nom VARCHAR(255) NOT NULL,
            fournisseur_tel VARCHAR(60) NULL,
            fournisseur_email VARCHAR(190) NULL,
            fournisseur_adresse TEXT NULL,

            objet VARCHAR(255) NULL,

            article_description TEXT NOT NULL,
            article_quantite DECIMAL(12,2) NOT NULL DEFAULT 1,
            article_unite VARCHAR(30) NOT NULL DEFAULT 'U',
            article_prix_unitaire DECIMAL(14,2) NOT NULL DEFAULT 0,
            montant_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            devise VARCHAR(10) NOT NULL DEFAULT 'FCFA',

            lieu_livraison VARCHAR(255) NULL,
            delai_livraison VARCHAR(100) NULL,
            conditions_paiement VARCHAR(255) NULL,
            notes TEXT NULL,

            sign_acheteur_nom VARCHAR(255) NULL,
            sign_fournisseur_nom VARCHAR(255) NULL,

            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uniq_bc_num_fiche (num_fiche),
            UNIQUE KEY uniq_bc_number (bc_number),
            KEY idx_bc_date (bc_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            $this->ready = false;
            $this->initError = 'Initialisation SQL du bon de commande impossible (droits insuffisants ?)';
        }
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }

    public function buildDefaultBcNumber(string $numFiche, ?DateTimeInterface $date = null): string
    {
        $date = $date ?: new DateTimeImmutable('today');
        $clean = preg_replace('/[^0-9A-Za-z]/', '', $numFiche);
        if ($clean === '' || $clean === null) {
            $clean = 'FICHE';
        }
        return 'BC-' . $date->format('Y') . '-' . $clean;
    }

    public function getByNumFiche(string $numFiche): ?array
    {
        if (!$this->ready) {
            return null;
        }
        $numFiche = trim($numFiche);
        if ($numFiche === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM bon_commande WHERE num_fiche = :num_fiche LIMIT 1');
        $stmt->execute(['num_fiche' => $numFiche]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsertByNumFiche(string $numFiche, array $data, ?int $userId = null): array
    {
        FinancialWorkflowProgress::step('Contrôle et enregistrement du bon de commande.');
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $numFiche = trim($numFiche);
        if ($numFiche === '') {
            return ['ok' => false, 'error' => 'Numéro de fiche manquant.'];
        }

        $bcDate = trim((string)($data['bc_date'] ?? ''));
        $bcDateObj = DateTimeImmutable::createFromFormat('Y-m-d', $bcDate);
        if (!$bcDateObj) {
            $bcDateObj = new DateTimeImmutable('today');
        }
        $bcDate = $bcDateObj->format('Y-m-d');

        $fournisseurNom = trim((string)($data['fournisseur_nom'] ?? ''));
        if ($fournisseurNom === '') {
            return ['ok' => false, 'error' => 'Le nom du fournisseur est requis.'];
        }

        $articleDescription = trim((string)($data['article_description'] ?? ''));
        if ($articleDescription === '') {
            return ['ok' => false, 'error' => 'La description est requise.'];
        }

        $quantite = (float)($data['article_quantite'] ?? 1);
        if ($quantite <= 0) {
            $quantite = 1;
        }

        $unite = trim((string)($data['article_unite'] ?? 'U'));
        if ($unite === '') {
            $unite = 'U';
        }

        $prixUnitaire = (float)($data['article_prix_unitaire'] ?? 0);
        if ($prixUnitaire < 0) {
            $prixUnitaire = 0;
        }

        $montantTotal = round($quantite * $prixUnitaire, 2);

        $bcNumber = trim((string)($data['bc_number'] ?? ''));
        if ($bcNumber === '') {
            $bcNumber = $this->buildDefaultBcNumber($numFiche, $bcDateObj);
        }

        $payload = [
            'num_fiche' => $numFiche,
            'bc_number' => $bcNumber,
            'bc_date' => $bcDate,

            'fournisseur_nom' => $fournisseurNom,
            'fournisseur_tel' => trim((string)($data['fournisseur_tel'] ?? '')) ?: null,
            'fournisseur_email' => trim((string)($data['fournisseur_email'] ?? '')) ?: null,
            'fournisseur_adresse' => trim((string)($data['fournisseur_adresse'] ?? '')) ?: null,

            'objet' => trim((string)($data['objet'] ?? '')) ?: null,

            'article_description' => $articleDescription,
            'article_quantite' => $quantite,
            'article_unite' => $unite,
            'article_prix_unitaire' => $prixUnitaire,
            'montant_total' => $montantTotal,
            'devise' => trim((string)($data['devise'] ?? 'FCFA')) ?: 'FCFA',

            'lieu_livraison' => trim((string)($data['lieu_livraison'] ?? '')) ?: null,
            'delai_livraison' => trim((string)($data['delai_livraison'] ?? '')) ?: null,
            'conditions_paiement' => trim((string)($data['conditions_paiement'] ?? '')) ?: null,
            'notes' => trim((string)($data['notes'] ?? '')) ?: null,

            'sign_acheteur_nom' => trim((string)($data['sign_acheteur_nom'] ?? '')) ?: null,
            'sign_fournisseur_nom' => trim((string)($data['sign_fournisseur_nom'] ?? '')) ?: null,
            'created_by_user_id' => $userId,
        ];

        // Validation "soft" avant écriture : éviter les erreurs SQL de doublon BC.
        try {
            $dupStmt = $this->pdo->prepare('SELECT num_fiche FROM bon_commande WHERE bc_number = :bc_number AND num_fiche <> :num_fiche LIMIT 1');
            $dupStmt->execute(['bc_number' => $bcNumber, 'num_fiche' => $numFiche]);
            $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
            if ($dup) {
                return ['ok' => false, 'error' => 'Ce numéro de bon de commande est déjà utilisé par une autre fiche.'];
            }
        } catch (Throwable $e) {
            // Ne bloque pas l'enregistrement si la validation échoue; la contrainte SQL s'appliquera.
        }

        $existing = $this->getByNumFiche($numFiche);

        if ($existing) {
            $sql = 'UPDATE bon_commande SET
                bc_number = :bc_number,
                bc_date = :bc_date,

                fournisseur_nom = :fournisseur_nom,
                fournisseur_tel = :fournisseur_tel,
                fournisseur_email = :fournisseur_email,
                fournisseur_adresse = :fournisseur_adresse,

                objet = :objet,

                article_description = :article_description,
                article_quantite = :article_quantite,
                article_unite = :article_unite,
                article_prix_unitaire = :article_prix_unitaire,
                montant_total = :montant_total,
                devise = :devise,

                lieu_livraison = :lieu_livraison,
                delai_livraison = :delai_livraison,
                conditions_paiement = :conditions_paiement,
                notes = :notes,

                sign_acheteur_nom = :sign_acheteur_nom,
                sign_fournisseur_nom = :sign_fournisseur_nom
            WHERE num_fiche = :num_fiche';

            // IMPORTANT: n'envoyer que les paramètres utilisés dans la requête.
            $payloadUpdate = $payload;
            unset($payloadUpdate['created_by_user_id']);

            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($payloadUpdate);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (strpos($msg, 'uniq_bc_number') !== false || strpos($msg, 'bc_number') !== false || strpos($msg, '1062') !== false) {
                    return ['ok' => false, 'error' => 'Ce numéro de bon de commande est déjà utilisé.'];
                }
                if (strpos($msg, 'HY093') !== false) {
                    return ['ok' => false, 'error' => 'Enregistrement impossible (paramètres invalides).'];
                }
                return ['ok' => false, 'error' => 'Erreur SQL lors de la mise à jour du bon de commande.'];
            }
            return ['ok' => true, 'id' => (int)($existing['id'] ?? 0), 'bc_number' => $bcNumber, 'montant_total' => $montantTotal];
        }

        $sql = 'INSERT INTO bon_commande (
            num_fiche, bc_number, bc_date,
            fournisseur_nom, fournisseur_tel, fournisseur_email, fournisseur_adresse,
            objet,
            article_description, article_quantite, article_unite, article_prix_unitaire,
            montant_total, devise,
            lieu_livraison, delai_livraison, conditions_paiement, notes,
            sign_acheteur_nom, sign_fournisseur_nom,
            created_by_user_id
        ) VALUES (
            :num_fiche, :bc_number, :bc_date,
            :fournisseur_nom, :fournisseur_tel, :fournisseur_email, :fournisseur_adresse,
            :objet,
            :article_description, :article_quantite, :article_unite, :article_prix_unitaire,
            :montant_total, :devise,
            :lieu_livraison, :delai_livraison, :conditions_paiement, :notes,
            :sign_acheteur_nom, :sign_fournisseur_nom,
            :created_by_user_id
        )';

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($payload);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'uniq_bc_number') !== false || strpos($msg, 'bc_number') !== false || strpos($msg, '1062') !== false) {
                return ['ok' => false, 'error' => 'Ce numéro de bon de commande est déjà utilisé.'];
            }
            if (strpos($msg, 'uniq_bc_num_fiche') !== false) {
                return ['ok' => false, 'error' => 'Un bon de commande existe déjà pour cette fiche.'];
            }
            return ['ok' => false, 'error' => 'Erreur SQL lors de la création du bon de commande.'];
        }

        return ['ok' => true, 'id' => (int)$this->pdo->lastInsertId(), 'bc_number' => $bcNumber, 'montant_total' => $montantTotal];
    }
}

