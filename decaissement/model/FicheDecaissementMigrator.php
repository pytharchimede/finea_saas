<?php

class FicheDecaissementMigrator
{
    private PDO $pdoNew;
    private PDO $pdoOld;

    // Si true: migrer uniquement les montants réellement payés (table decaissement.montant>0),
    // ignorer le fallback "fiche.decaisse=1 sans lignes" et NE PAS créer de fiches héritières "reste à décaisser".
    private bool $paidOnly = false;

    private bool $autoValidateMigDevis = false;
    private bool $autoPublishMigDevis = false;

    private ?array $lastMigDevisAction = null;

    private ?array $oldFicheCols = null;
    private ?array $oldDecaisseCols = null;
    private ?array $oldChantierCols = null;

    private ?array $newFicheCols = null;
    private bool $newFicheColsEnsured = false;

    private const DEFAULT_CLIENT_ID_SIR = 2;

    public function __construct(PDO $pdoNew, PDO $pdoOld, array $options = [])
    {
        $this->pdoNew = $pdoNew;
        $this->pdoOld = $pdoOld;

        $this->autoPublishMigDevis = !empty($options['autoPublishMigDevis']);
        $this->autoValidateMigDevis = !empty($options['autoValidateMigDevis']) || $this->autoPublishMigDevis;

        $this->paidOnly = !empty($options['paidOnly']);

        $this->pdoNew->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdoOld->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $this->pdoNew->exec('SET NAMES utf8mb4');
        } catch (Throwable $e) {
        }
        try {
            $this->pdoOld->exec('SET NAMES utf8mb4');
        } catch (Throwable $e) {
        }
    }

    public function previewByChantierCode(string $chantierCode, ?int $oldChantierId = null): array
    {
        $chantierCode = $this->normCode($chantierCode);
        if ($chantierCode === '') {
            return ['ok' => false, 'error' => 'Code chantier manquant'];
        }

        $oldChantier = $this->resolveOldChantier($chantierCode, $oldChantierId);
        if (!$oldChantier) {
            return ['ok' => false, 'error' => 'Chantier introuvable dans l\'ancienne base (code: ' . $chantierCode . ')'];
        }
        $newChantier = $this->resolveNewChantier($chantierCode);
        if (!$newChantier) {
            return ['ok' => false, 'error' => 'Chantier introuvable dans la nouvelle base (code: ' . $chantierCode . ')'];
        }

        $oldChantierIdResolved = (int)($oldChantier['__id'] ?? 0);
        if ($oldChantierIdResolved <= 0) {
            return ['ok' => false, 'error' => 'ID chantier ancien non résolu'];
        }

        $fiches = $this->listOldFichesByChantier($oldChantierIdResolved);
        $idToNum = $this->buildOldFicheIdToNum($fiches);
        $agg = $this->getOldPaymentsAggForFicheIdToNum($idToNum);

        $total = count($fiches);
        $withPayments = 0;
        $sumPaid = 0.0;

        $sample = [];
        foreach ($fiches as $f) {
            $num = $this->extractOldNumFiche($f);
            if ($num === '') continue;
            $oldId = $this->extractOldIdFiche($f);
            $a = ($oldId !== null && isset($agg[$oldId])) ? $agg[$oldId] : ['total' => 0.0, 'count' => 0, 'last_date' => null];
            $paid = (float)($a['total'] ?? 0);
            $count = (int)($a['count'] ?? 0);
            $last = $a['last_date'] ?? null;

            // Certains chantiers/versions marquent decaisse=1 sans lignes de table decaissement.
            if (!$this->paidOnly && $paid <= 0 && $this->isOldFicheDecaissed($f)) {
                $paid = max(0.0, $this->extractOldMontant($f));
                $count = $paid > 0 ? 1 : 0;
                $last = $this->extractOldDateDecaissement($f) ?? $last;
            }

            if ($paid > 0) {
                $withPayments++;
                $sumPaid += $paid;
            }

            if (count($sample) < 30) {
                $sample[] = [
                    'num_fiche' => $num,
                    'beneficiaire' => $this->extractOldBeneficiaire($f),
                    'montant_initial' => $this->extractOldMontant($f),
                    'total_decaisse' => $paid,
                    'nb_decaissements' => $count,
                    'dernier_decaissement' => $last,
                    'designation' => $this->extractOldDesignation($f),
                    'precision' => $this->extractOldPrecision($f),
                ];
            }
        }

        return [
            'ok' => true,
            'chantier_code' => $chantierCode,
            'old' => [
                'id' => $oldChantierIdResolved,
                'titre' => $oldChantier['__titre'] ?? '',
            ],
            'new' => [
                'id' => (int)$newChantier['id'],
                'titre' => $newChantier['titre'] ?? '',
                'devis_id' => $newChantier['devis_id'] ?? null,
            ],
            'stats' => [
                'total_fiches' => $total,
                'fiches_avec_decaissement' => $withPayments,
                'total_decaisse_fcfa' => $sumPaid,
            ],
            'sample' => $sample,
        ];
    }

    public function migrateBatchByChantierCode(string $chantierCode, int $offset, int $limit, bool $dryRun = false, ?int $oldChantierId = null): array
    {
        $this->lastMigDevisAction = null;

        $chantierCode = $this->normCode($chantierCode);
        if ($chantierCode === '') {
            return ['ok' => false, 'error' => 'Code chantier manquant'];
        }
        $offset = max(0, $offset);
        $limit = max(1, min(200, $limit));

        $oldChantier = $this->resolveOldChantier($chantierCode, $oldChantierId);
        if (!$oldChantier) {
            return ['ok' => false, 'error' => 'Chantier introuvable dans l\'ancienne base'];
        }
        $newChantier = $this->resolveNewChantier($chantierCode);
        if (!$newChantier) {
            return ['ok' => false, 'error' => 'Chantier introuvable dans la nouvelle base'];
        }

        $oldChantierId = (int)($oldChantier['__id'] ?? 0);
        $newChantierId = (int)($newChantier['id'] ?? 0);
        if ($oldChantierId <= 0 || $newChantierId <= 0) {
            return ['ok' => false, 'error' => 'IDs chantier non résolus'];
        }

        // Optionnel: auto-valider / publier le devis MIG déjà rattaché au chantier (sans dépendre de la création de lignes).
        if (!$dryRun) {
            $existingDevisId = (int)($newChantier['devis_id'] ?? 0);
            if ($existingDevisId > 0) {
                $this->maybeAutoValidateMigDevis($existingDevisId);
            }
        }

        $fiches = $this->listOldFichesByChantierPaged($oldChantierId, $offset, $limit);

        $idToNum = $this->buildOldFicheIdToNum($fiches);
        $agg = $this->getOldPaymentsAggForFicheIdToNum($idToNum);

        $results = [
            'processed' => 0,
            'migrated' => 0,
            'skipped_no_payments' => 0,
            'skipped_exists' => 0,
            'errors' => 0,
            'details' => [],
        ];

        foreach ($fiches as $f) {
            $results['processed']++;
            $numBase = $this->extractOldNumFiche($f);
            if ($numBase === '') {
                $results['errors']++;
                $results['details'][] = ['num_fiche' => '', 'status' => 'error', 'error' => 'Numéro fiche introuvable'];
                continue;
            }

            $oldId = $this->extractOldIdFiche($f);
            if ($oldId === null) {
                $results['errors']++;
                $results['details'][] = ['num_fiche' => $numBase, 'status' => 'error', 'error' => 'ID fiche introuvable'];
                continue;
            }

            $a = $agg[$oldId] ?? ['total' => 0.0, 'count' => 0];
            $totalPaid = (float)($a['total'] ?? 0);
            if (!$this->paidOnly && $totalPaid <= 0 && $this->isOldFicheDecaissed($f)) {
                $totalPaid = max(0.0, $this->extractOldMontant($f));
            }
            if ($totalPaid <= 0) {
                $results['skipped_no_payments']++;
                $results['details'][] = ['num_fiche' => $numBase, 'status' => 'skipped', 'reason' => 'aucun décaissement'];
                continue;
            }

            try {
                if ($this->newFicheExists($numBase)) {
                    $results['skipped_exists']++;
                    $results['details'][] = ['num_fiche' => $numBase, 'status' => 'skipped', 'reason' => 'déjà présent (num_fiche)'];
                    continue;
                }

                if ($dryRun) {
                    $results['migrated']++;
                    $results['details'][] = ['num_fiche' => $numBase, 'status' => 'dry_run'];
                    continue;
                }

                $this->migrateSingleOldFiche($newChantierId, $newChantier, $f);
                $results['migrated']++;
                $results['details'][] = ['num_fiche' => $numBase, 'status' => 'ok'];
            } catch (Throwable $e) {
                $results['errors']++;
                $results['details'][] = ['num_fiche' => $numBase, 'status' => 'error', 'error' => $e->getMessage()];
            }
        }

        return [
            'ok' => true,
            'chantier_code' => $chantierCode,
            'offset' => $offset,
            'limit' => $limit,
            'next_offset' => $offset + $limit,
            'count' => count($fiches),
            'results' => $results,
            'meta' => [
                'mig_devis_action' => $this->lastMigDevisAction,
            ],
        ];
    }

    private function maybeAutoValidateMigDevis(int $devisId): void
    {
        if (!$this->autoValidateMigDevis || $devisId <= 0) return;

        $st = $this->pdoNew->prepare('SELECT numero_devis, validation_technique, validation_generale, publier_devis FROM devis_banamur WHERE id = :id LIMIT 1');
        $st->execute(['id' => $devisId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $numero = (string)($row['numero_devis'] ?? '');
        if ($numero === '' || !str_starts_with($numero, 'MIG-')) {
            $this->lastMigDevisAction = [
                'applied' => false,
                'reason' => 'not_mig',
                'devis_id' => $devisId,
                'numero_devis' => $numero,
            ];
            return;
        }

        $needValidTech = (int)($row['validation_technique'] ?? 0) !== 1;
        $needValidGen = (int)($row['validation_generale'] ?? 0) !== 1;
        $needPublier = $this->autoPublishMigDevis && (int)($row['publier_devis'] ?? 0) !== 1;
        if (!$needValidTech && !$needValidGen && !$needPublier) {
            $this->lastMigDevisAction = [
                'applied' => false,
                'reason' => 'already_ok',
                'devis_id' => $devisId,
                'numero_devis' => $numero,
                'validated' => true,
                'published' => ((int)($row['publier_devis'] ?? 0) === 1),
            ];
            return;
        }

        $sql = 'UPDATE devis_banamur SET validation_technique = 1, validation_generale = 1';
        if ($this->autoPublishMigDevis) {
            $sql .= ', publier_devis = 1';
        }
        $sql .= ' WHERE id = :id';

        $up = $this->pdoNew->prepare($sql);
        $up->execute(['id' => $devisId]);

        $this->lastMigDevisAction = [
            'applied' => true,
            'devis_id' => $devisId,
            'numero_devis' => $numero,
            'validated' => true,
            'published' => $this->autoPublishMigDevis,
        ];
    }

    private function migrateSingleOldFiche(int $newChantierId, array $newChantierRow, array $oldFiche): void
    {
        $numBase = $this->extractOldNumFiche($oldFiche);
        if ($numBase === '') throw new RuntimeException('Numéro fiche manquant');

        $oldFicheId = $this->extractOldIdFiche($oldFiche);
        if ($oldFicheId === null) throw new RuntimeException('ID fiche manquant');

        $montantInitial = $this->extractOldMontant($oldFiche);
        $benef = $this->extractOldBeneficiaire($oldFiche);
        $tel = $this->extractOldTelephone($oldFiche);
        $designation = $this->extractOldDesignation($oldFiche);
        $precision = $this->extractOldPrecision($oldFiche);

        $personnelId = $this->resolvePersonnelIdForBeneficiaire($benef, $tel);

        $payments = $this->getOldPaymentsChronoByFiche($oldFiche);

        // Compat legacy: certaines bases marquent decaisse=1 sans table decaissement. Autorisé uniquement en mode "complet".
        if (!$this->paidOnly && !$payments && $this->isOldFicheDecaissed($oldFiche)) {
            $payments = [[
                'montant' => $montantInitial,
                'date' => $this->extractOldDateDecaissement($oldFiche),
            ]];
        }

        // Si aucun paiement >0, on stop (filtré normalement avant)
        if (!$payments) throw new RuntimeException('Aucun décaissement à migrer');

        $sumPaid = 0.0;
        $lastPaidDate = null;
        foreach ($payments as $p) {
            $sumPaid += max(0.0, (float)($p['montant'] ?? 0));
            if (!empty($p['date'])) {
                $lastPaidDate = (string)$p['date'];
            }
        }

        // Certaines données anciennes contiennent un montant_fiche incohérent (inférieur au total réellement décaissé)
        // En mode "payé uniquement", on ne migre que le payé (pas de reste). Sinon on garde la logique existante.
        $montantTotal = $this->paidOnly ? $sumPaid : max($montantInitial, $sumPaid);

        $ligneDebourseId = $this->resolveOrCreateLigneDebourseId((int)$newChantierRow['id'], $newChantierRow, $designation, $precision, $oldFiche);

        // Insertion base (montant total demandé) - puis split selon paiements
        $baseDateCreate = $this->extractOldDateCreate($oldFiche);
        $basePayload = [
            'num_fiche' => $numBase,
            'parent_num_fiche' => (string)($this->extractOldParentNum($oldFiche) ?? ''),
            'code_parentale' => (string)($this->extractOldCodeParental($oldFiche) ?? ''),

            'beficiaire_fiche' => $benef !== '' ? $benef : 'Inconnu',
            'tel_beneficiaire_fiche' => $tel,
            'montant_fiche' => $montantTotal,
            'montant_initial_fiche' => null,
            'date_creat_fiche' => $baseDateCreate,

            'chantier_id' => $newChantierId,
            'ligne_debourse_id' => $ligneDebourseId,
            'designation_fiche' => $designation,
            'precision_fiche' => $precision,
            'details_demande' => $this->extractOldDetails($oldFiche),
            'mode_paiement' => $this->extractOldModePaiement($oldFiche),

            'conforme' => 1,
            'approuve' => 1,
            'etat_fiche' => 1,
            'decaisse' => 0,
            'otp_valide' => 1,
            'sauvegarder' => 1,
            'secur_conforme' => 'MIGRATION',
            'secur_valid' => 'MIGRATION',

            'entreprise' => (string)($newChantierRow['entreprise'] ?? ''),
            'affectation_id' => $this->extractOldIdFiche($oldFiche),
            'personnel_id' => $personnelId,
        ];

        $this->insertNewFicheRow($basePayload);

        // Mode payé uniquement: une seule écriture = total payé (pas de split, pas de reste à décaisser).
        if ($this->paidOnly) {
            // Conserver une référence au montant initial (si disponible) sans créer d'héritière "reste".
            $mi = max($montantInitial, $sumPaid);
            $this->updateNewFicheAsPaid($numBase, $sumPaid, $mi, $lastPaidDate);
            return;
        }

        // Appliquer paiements dans l'ordre en reproduisant le split partiel
        $currentNum = $numBase;
        $reste = $montantTotal;
        $orig = $montantTotal;

        foreach ($payments as $index => $p) {
            $amount = (float)($p['montant'] ?? 0);
            if ($amount <= 0) continue;
            $datePaiement = $p['date'] ?? null;

            if ($amount >= $reste) {
                // Total sur la fiche courante
                $this->updateNewFicheAsPaid($currentNum, $reste, $orig, $datePaiement);
                $reste = 0.0;
                break;
            }

            // Partiel: payer une partie, créer enfant reste
            $this->updateNewFicheAsPaid($currentNum, $amount, $orig, $datePaiement);
            $reste -= $amount;

            // Créer enfant
            $childNum = $this->buildNextChildNum($currentNum);
            $nextDate = null;
            $next = $payments[$index + 1] ?? null;
            if ($next && !empty($next['date'])) {
                $nextDate = substr((string)$next['date'], 0, 10);
            }

            $childPayload = [
                'num_fiche' => $childNum,
                'parent_num_fiche' => $currentNum,
                'code_parentale' => (string)($this->extractOldCodeParental($oldFiche) ?? ''),

                'beficiaire_fiche' => $benef !== '' ? $benef : 'Inconnu',
                'tel_beneficiaire_fiche' => $tel,
                'montant_fiche' => $reste,
                'montant_initial_fiche' => $orig,
                'date_creat_fiche' => $datePaiement,

                'chantier_id' => $newChantierId,
                'ligne_debourse_id' => $ligneDebourseId,
                'designation_fiche' => $designation,
                'precision_fiche' => $precision,
                'details_demande' => $this->extractOldDetails($oldFiche),

                'conforme' => 1,
                'approuve' => 1,
                'etat_fiche' => 1,
                'decaisse' => 0,
                'otp_valide' => 1,
                'sauvegarder' => 1,
                'secur_conforme' => 'MIGRATION',
                'secur_valid' => 'MIGRATION',
                'date_decaissement_minimum' => $nextDate,
                'entreprise' => (string)($newChantierRow['entreprise'] ?? ''),
                'affectation_id' => $this->extractOldIdFiche($oldFiche),
                'personnel_id' => $personnelId,
            ];

            $this->insertNewFicheRow($childPayload);
            $currentNum = $childNum;
        }
    }

    private function resolveOrCreateLigneDebourseId(int $newChantierId, array $newChantierRow, string $designation, string $precision, array $oldFiche): ?int
    {
        $candidate = $precision !== '' ? $precision : $designation;

        // 1) Si l'ancien système a un id_ligne_debourse, tenter match direct
        $oldLigneId = $this->extractOldLigneDebourseId($oldFiche);
        if ($oldLigneId !== null) {
            $st = $this->pdoNew->prepare('SELECT id FROM ligne_debourse_banamur WHERE id = :id LIMIT 1');
            $st->execute(['id' => $oldLigneId]);
            $ok = $st->fetch(PDO::FETCH_ASSOC);
            if ($ok) return (int)$ok['id'];
        }

        // 2) Chercher une ligne existante dans le devis du chantier
        $devisId = $newChantierRow['devis_id'] ?? null;
        if ($devisId) {
            $st = $this->pdoNew->prepare(
                'SELECT ldb.id FROM ligne_debourse_banamur ldb ' .
                    'JOIN debourse_banamur db ON db.id = ldb.debourse_id ' .
                    'WHERE db.devis_id = :devis AND ldb.designation = :d LIMIT 1'
            );
            $st->execute(['devis' => $devisId, 'd' => $candidate]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) return (int)$row['id'];
        }

        // 3) Créer chaîne devis->ligne_devis->debourse->ligne_debourse si manquante
        $devisId = $this->ensureDevisForChantier($newChantierId, $newChantierRow);

        $ligneDevisId = $this->getOrCreateLigneDevis($devisId, $designation !== '' ? $designation : $candidate);
        $debourseId = $this->getOrCreateDebourse($devisId, $ligneDevisId);

        $categorie = $this->mapCategorie($this->extractOldCategorie($oldFiche));
        $ins = $this->pdoNew->prepare(
            'INSERT INTO ligne_debourse_banamur (debourse_id, categorie, designation, montant, date_debut, date_fin, created_at, updated_at) ' .
                'VALUES (:debourse, :cat, :des, :montant, NULL, NULL, NOW(), NOW())'
        );
        $ins->execute([
            'debourse' => $debourseId,
            'cat' => $categorie,
            'des' => $candidate !== '' ? $candidate : 'Dépense migrée',
            'montant' => 0,
        ]);
        return (int)$this->pdoNew->lastInsertId();
    }

    private function ensureDevisForChantier(int $chantierId, array $chantierRow): int
    {
        // 1) Toujours relire l'état en base (le tableau $chantierRow peut être stale pendant un batch)
        $stCur = $this->pdoNew->prepare('SELECT devis_id, client_id, code, titre FROM chantier_banamur WHERE id = :id LIMIT 1');
        $stCur->execute(['id' => $chantierId]);
        $cur = $stCur->fetch(PDO::FETCH_ASSOC) ?: null;

        $devisId = (int)($chantierRow['devis_id'] ?? 0);
        if ($cur) {
            $dbDevisId = (int)($cur['devis_id'] ?? 0);
            if ($dbDevisId > 0) {
                $this->maybeAutoValidateMigDevis($dbDevisId);
                return $dbDevisId;
            }
        }
        if ($devisId > 0) {
            $this->maybeAutoValidateMigDevis($devisId);
            return $devisId;
        }

        // Création devis placeholder (montants 0)
        $clientId = (int)($chantierRow['client_id'] ?? 0);
        if ($clientId <= 0 && $cur) {
            $clientId = (int)($cur['client_id'] ?? 0);
        }
        if ($clientId <= 0) {
            // Règle métier: client = SIR (id_client=2)
            $clientId = self::DEFAULT_CLIENT_ID_SIR;

            // Vérifier l'existence (PK = id_client)
            $stCl = $this->pdoNew->prepare('SELECT 1 FROM client_banamur WHERE id_client = :id LIMIT 1');
            $stCl->execute(['id' => $clientId]);
            if (!(bool)$stCl->fetchColumn()) {
                throw new RuntimeException('Client SIR introuvable (client_banamur.id_client=' . $clientId . ')');
            }

            $upClient = $this->pdoNew->prepare('UPDATE chantier_banamur SET client_id = :client WHERE id = :id');
            $upClient->execute(['client' => $clientId, 'id' => $chantierId]);
        }

        $offreId = $this->getDefaultOffreId();
        $code = (string)($chantierRow['code'] ?? ($cur['code'] ?? 'CHANTIER'));
        $titre = (string)($chantierRow['titre'] ?? ($cur['titre'] ?? ''));
        $numero = 'MIG-' . $this->normCode($code !== '' ? $code : 'CHANTIER') . '-' . date('ymdHis');

        $validationFlag = $this->autoValidateMigDevis ? 1 : 0;
        $publierFlag = ($this->autoValidateMigDevis && $this->autoPublishMigDevis) ? 1 : 0;

        $sql = 'INSERT INTO devis_banamur (client_id, offre_id, numero_devis, delai_livraison, date_emission, date_expiration, emis_par, destine_a, logo, termes_conditions, pied_de_page, total_ht, total_ttc, tva_facturable, publier_devis, tva, correspondant, masque, validation_technique, validation_generale, page_garde, description, delai, conditions, garantie, remise) ' .
            'VALUES (:client_id, :offre_id, :numero, :delai_livraison, CURDATE(), CURDATE(), :emis_par, :destine_a, :logo, :termes, :pied, 0, 0, 0, :publier, 0, :corresp, 0, :valid_tech, :valid_gen, NULL, NULL, NULL, NULL, NULL, 0)';

        $st = $this->pdoNew->prepare($sql);
        $st->execute([
            'client_id' => $clientId,
            'offre_id' => $offreId,
            'numero' => $numero,
            'delai_livraison' => '',
            'emis_par' => 'MIGRATION',
            'destine_a' => $titre,
            'logo' => null,
            'termes' => null,
            'pied' => null,
            'corresp' => 'MIGRATION',
            'publier' => $publierFlag,
            'valid_tech' => $validationFlag,
            'valid_gen' => $validationFlag,
        ]);

        $devisId = (int)$this->pdoNew->lastInsertId();
        $up = $this->pdoNew->prepare('UPDATE chantier_banamur SET devis_id = :devis WHERE id = :id');
        $up->execute(['devis' => $devisId, 'id' => $chantierId]);
        return $devisId;
    }

    private function getOrCreateLigneDevis(int $devisId, string $designation): int
    {
        $designation = trim($designation);
        if ($designation === '') $designation = 'Ligne devis migrée';

        $st = $this->pdoNew->prepare('SELECT id FROM ligne_devis_banamur WHERE devis_id = :devis AND designation = :d LIMIT 1');
        $st->execute(['devis' => $devisId, 'd' => $designation]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];

        $uniteId = $this->getDefaultUniteId();
        $ins = $this->pdoNew->prepare('INSERT INTO ligne_devis_banamur (devis_id, designation, prix, quantite, unite_id, total, groupe) VALUES (:devis, :d, 0, 1, :unite, 0, \'\')');
        $ins->execute(['devis' => $devisId, 'd' => $designation, 'unite' => $uniteId]);
        return (int)$this->pdoNew->lastInsertId();
    }

    private function getOrCreateDebourse(int $devisId, int $ligneDevisId): int
    {
        $st = $this->pdoNew->prepare('SELECT id FROM debourse_banamur WHERE devis_id = :devis AND ligne_devis_id = :ld LIMIT 1');
        $st->execute(['devis' => $devisId, 'ld' => $ligneDevisId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return (int)$row['id'];

        $ins = $this->pdoNew->prepare('INSERT INTO debourse_banamur (devis_id, ligne_devis_id, montant_debourse, responsable_id, date_debut, date_fin, motif_modif, date_modif, created_at, updated_at) VALUES (:devis, :ld, 0, 0, NULL, NULL, NULL, NULL, NOW(), NOW())');
        $ins->execute(['devis' => $devisId, 'ld' => $ligneDevisId]);
        return (int)$this->pdoNew->lastInsertId();
    }

    private function insertNewFicheRow(array $data): void
    {
        $this->ensureNewFicheColumns();
        $colsWanted = [
            'num_fiche',
            'parent_num_fiche',
            'code_parentale',
            'beficiaire_fiche',
            'montant_fiche',
            'montant_initial_fiche',
            'tel_beneficiaire_fiche',
            'personnel_id',
            'date_creat_fiche',
            'chantier_id',
            'serv_bureau_banamur_id',
            'affectation_id',
            'ligne_debourse_id',
            'designation_fiche',
            'precision_fiche',
            'details_demande',
            'mode_paiement',
            'entreprise',
            'approuve',
            'conforme',
            'decaisse',
            'otp_valide',
            'etat_fiche',
            'sauvegarder',
            'date_decaissement_minimum',
            'secur_conforme',
            'secur_valid',
        ];

        $cols = [];
        $set = array_fill_keys($this->newFicheCols ?? [], true);
        foreach ($colsWanted as $c) {
            if (isset($set[$c])) $cols[] = $c;
        }

        $placeholders = [];
        $params = [];
        foreach ($cols as $c) {
            $placeholders[] = ':' . $c;
            $params[$c] = $data[$c] ?? null;
        }

        $sql = 'INSERT INTO fiche_decaissement (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $st = $this->pdoNew->prepare($sql);
        $st->execute($params);
    }

    private function ensureNewFicheColumns(): void
    {
        if ($this->newFicheColsEnsured) return;
        $this->newFicheColsEnsured = true;

        if (!$this->tableExists($this->pdoNew, 'fiche_decaissement')) {
            throw new RuntimeException("Table 'fiche_decaissement' introuvable dans la nouvelle base");
        }

        $this->newFicheCols = $this->getColumns($this->pdoNew, 'fiche_decaissement');
        $set = array_fill_keys($this->newFicheCols, true);

        // Ajout colonne personnel_id (liaison RH) si absente
        if (!isset($set['personnel_id'])) {
            try {
                $this->pdoNew->exec('ALTER TABLE fiche_decaissement ADD personnel_id INT NULL AFTER tel_beneficiaire_fiche');
                $this->pdoNew->exec('CREATE INDEX idx_fiche_personnel_id ON fiche_decaissement (personnel_id)');
            } catch (Throwable $e) {
                // Si l'index existe déjà (ou droits insuffisants), on n'empêche pas la migration.
            }
            $this->newFicheCols = $this->getColumns($this->pdoNew, 'fiche_decaissement');
        }
    }

    private function resolvePersonnelIdForBeneficiaire(string $beneficiaireNom, string $telephone): ?int
    {
        $beneficiaireNom = trim($beneficiaireNom);
        $telephone = trim($telephone);

        if ($beneficiaireNom === '' && $telephone === '') return null;
        if (!$this->tableExists($this->pdoNew, 'personnel')) return null;

        $telNorm = $this->normalizePhone($telephone);
        if ($telNorm !== null) {
            $st = $this->pdoNew->prepare('SELECT id_personnel_soignant FROM personnel WHERE tel_personnel_soignant = :t LIMIT 1');
            $st->execute(['t' => $telNorm]);
            $id = (int)$st->fetchColumn();
            if ($id > 0) return $id;
        }

        if ($beneficiaireNom === '') return null;
        $nameNorm = $this->normalizeHumanName($beneficiaireNom);
        if ($nameNorm === '') return null;

        $token = $this->pickNameTokenForLike($nameNorm);
        if ($token === '') return null;

        $st = $this->pdoNew->prepare('SELECT id_personnel_soignant, nom_personnel_soignant FROM personnel WHERE nom_personnel_soignant LIKE :q LIMIT 50');
        $st->execute(['q' => '%' . $token . '%']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $cand = $this->normalizeHumanName((string)($r['nom_personnel_soignant'] ?? ''));
            if ($cand !== '' && $cand === $nameNorm) {
                $id = (int)($r['id_personnel_soignant'] ?? 0);
                if ($id > 0) return $id;
            }
        }

        return null;
    }

    private function normalizePhone(string $tel): ?string
    {
        $digits = preg_replace('/\D+/', '', $tel);
        $digits = (string)$digits;
        if ($digits === '') return null;
        // conserver les 10 derniers chiffres (format CI typique)
        if (strlen($digits) > 10) {
            $digits = substr($digits, -10);
        }
        if (strlen($digits) !== 10) return null;
        return $digits;
    }

    private function normalizeHumanName(string $name): string
    {
        $name = trim($name);
        if ($name === '') return '';
        $name = strtoupper($name);

        // Remplacer accents courants (sans dépendre de mbstring)
        $map = [
            'À' => 'A',
            'Â' => 'A',
            'Ä' => 'A',
            'Á' => 'A',
            'Ã' => 'A',
            'Ç' => 'C',
            'È' => 'E',
            'É' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Ì' => 'I',
            'Í' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ò' => 'O',
            'Ó' => 'O',
            'Ô' => 'O',
            'Ö' => 'O',
            'Õ' => 'O',
            'Ù' => 'U',
            'Ú' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ý' => 'Y',
            'Œ' => 'OE',
            '’' => ' ',
            "'" => ' ',
            '-' => ' ',
        ];
        $name = strtr($name, $map);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim((string)$name);
        return $name;
    }

    private function pickNameTokenForLike(string $normalizedName): string
    {
        $parts = preg_split('/\s+/', $normalizedName) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($v) => $v !== ''));
        if (!$parts) return '';
        usort($parts, fn($a, $b) => strlen($b) <=> strlen($a));
        return $parts[0];
    }

    private function updateNewFicheAsPaid(string $num, float $montantPaye, float $montantInitial, ?string $datePaiement): void
    {
        // Si montant_initial_fiche est NULL, on la fixe
        $upMi = $this->pdoNew->prepare('UPDATE fiche_decaissement SET montant_initial_fiche = COALESCE(montant_initial_fiche, :mi) WHERE num_fiche = :n');
        $upMi->execute(['mi' => $montantInitial, 'n' => $num]);

        $sql = 'UPDATE fiche_decaissement SET montant_fiche = :m, decaisse = 1, etat_fiche = 1, secur_decaisse = :s, date_decaisse = :d, updated_at = NOW() WHERE num_fiche = :n';
        $st = $this->pdoNew->prepare($sql);
        $st->execute([
            'm' => $montantPaye,
            's' => 'MIGRATION',
            'd' => $this->normDateTime($datePaiement) ?? date('Y-m-d H:i:s'),
            'n' => $num,
        ]);
    }

    private function buildNextChildNum(string $parentNum): string
    {
        $st = $this->pdoNew->prepare('SELECT COUNT(*) FROM fiche_decaissement WHERE parent_num_fiche = :p');
        $st->execute(['p' => $parentNum]);
        $seq = (int)$st->fetchColumn() + 1;
        return $parentNum . '-P' . $seq;
    }

    private function newFicheExists(string $num): bool
    {
        $st = $this->pdoNew->prepare('SELECT 1 FROM fiche_decaissement WHERE num_fiche = :n LIMIT 1');
        $st->execute(['n' => $num]);
        return (bool)$st->fetchColumn();
    }

    private function resolveNewChantier(string $code): ?array
    {
        $st = $this->pdoNew->prepare('SELECT id, titre, code, devis_id, client_id, entreprise FROM chantier_banamur WHERE code = :c LIMIT 1');
        $st->execute(['c' => $code]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private function resolveOldChantier(string $code, ?int $forcedId): ?array
    {
        if (!$this->tableExists($this->pdoOld, 'chantier')) {
            throw new RuntimeException("Table 'chantier' introuvable dans l'ancienne base");
        }

        $cols = $this->getOldChantierCols();
        $idCol = $this->pickFirstExisting($cols, ['id', 'id_chantier', 'chantier_id']);
        $codeCol = $this->pickFirstExisting($cols, ['code', 'code_chantier', 'chantier_code', 'num_chantier', 'numero_chantier', 'num']);
        $titreCol = $this->pickFirstExisting($cols, ['titre', 'titre_chantier', 'nom', 'libelle', 'lib_chantier']);

        if (!$idCol) throw new RuntimeException("Colonne id chantier introuvable (ancienne base)");

        if ($forcedId !== null && $forcedId > 0) {
            $sql = 'SELECT * FROM chantier WHERE ' . $idCol . ' = :id LIMIT 1';
            $st = $this->pdoOld->prepare($sql);
            $st->execute(['id' => $forcedId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            return [
                '__id' => (int)$row[$idCol],
                '__titre' => $titreCol ? (string)($row[$titreCol] ?? '') : '',
            ];
        }

        if (!$codeCol) {
            return null;
        }

        $codeNorm = $this->normCode($code);
        $codeNoSep = preg_replace('/[^A-Z0-9]/', '', $codeNorm);

        $candidates = [$codeNorm, $codeNoSep];
        if (preg_match('/\d+/', $codeNorm, $m)) {
            $digits = $m[0];
            $digitsNo0 = ltrim($digits, '0');
            if ($digitsNo0 === '') $digitsNo0 = '0';
            $candidates[] = $digits;
            $candidates[] = $digitsNo0;
            $candidates[] = 'CH' . $digits;
            $candidates[] = 'CH' . $digitsNo0;
        }
        $candidates = array_values(array_unique(array_filter(array_map('strval', $candidates))));

        // Tolérance : CH038 peut être stocké comme CH 038 / CH-038 / etc.
        $expr = 'TRIM(UPPER(' . $codeCol . '))';
        $exprNoSep = 'REPLACE(REPLACE(' . $expr . ", '-', ''), ' ', '')";

        $whereParts = [];
        $params = [];
        $i = 0;
        foreach ($candidates as $cand) {
            $i++;
            $p = 'c' . $i;
            $whereParts[] = '(' . $expr . ' = :' . $p . ' OR ' . $exprNoSep . ' = :' . $p . ')';
            $params[$p] = $cand;
        }

        $sql = 'SELECT * FROM chantier WHERE (' . implode(' OR ', $whereParts) . ') LIMIT 1';
        $st = $this->pdoOld->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        return [
            '__id' => (int)$row[$idCol],
            '__titre' => $titreCol ? (string)($row[$titreCol] ?? '') : '',
        ];
    }

    private function listOldFichesByChantier(int $oldChantierId): array
    {
        if (!$this->tableExists($this->pdoOld, 'fiche')) {
            throw new RuntimeException("Table 'fiche' introuvable dans l'ancienne base");
        }
        $cols = $this->getOldFicheCols();
        $chantierCol = $this->pickFirstExisting($cols, ['chantier_id', 'id_chantier', 'chantier']);
        if (!$chantierCol) throw new RuntimeException("Colonne chantier_id introuvable sur table fiche (ancienne base)");

        $sql = 'SELECT * FROM fiche WHERE ' . $chantierCol . ' = :cid ORDER BY 1 ASC';
        $st = $this->pdoOld->prepare($sql);
        $st->execute(['cid' => $oldChantierId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function listOldFichesByChantierPaged(int $oldChantierId, int $offset, int $limit): array
    {
        if (!$this->tableExists($this->pdoOld, 'fiche')) {
            throw new RuntimeException("Table 'fiche' introuvable dans l'ancienne base");
        }
        $cols = $this->getOldFicheCols();
        $chantierCol = $this->pickFirstExisting($cols, ['chantier_id', 'id_chantier', 'chantier']);
        if (!$chantierCol) throw new RuntimeException("Colonne chantier_id introuvable sur table fiche (ancienne base)");

        $sql = 'SELECT * FROM fiche WHERE ' . $chantierCol . ' = :cid ORDER BY 1 ASC LIMIT :lim OFFSET :off';
        $st = $this->pdoOld->prepare($sql);
        $st->bindValue(':cid', $oldChantierId, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit, PDO::PARAM_INT);
        $st->bindValue(':off', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function buildOldFicheIdToNum(array $fiches): array
    {
        $out = [];
        foreach ($fiches as $f) {
            if (!is_array($f)) continue;
            $id = $this->extractOldIdFiche($f);
            if ($id === null || $id <= 0) continue;
            $num = $this->extractOldNumFiche($f);
            $num = trim((string)$num);
            $out[$id] = $num;
        }
        return $out;
    }

    private function getOldPaymentsAggForFicheIdToNum(array $idToNum): array
    {
        // Retour: [oldFicheId => ['total'=>float,'count'=>int,'last_date'=>?string]]
        $idToNum = is_array($idToNum) ? $idToNum : [];
        if (!$idToNum) return [];

        $ids = array_keys($idToNum);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));

        $nums = array_values($idToNum);
        $nums = array_values(array_unique(array_filter(array_map('strval', $nums), fn($v) => trim($v) !== '')));

        // 1) Agg par IDs (ancien comportement) -> map key(string) => agg
        $byIdKey = $ids ? $this->queryOldPaymentsAggByKeys($ids) : [];

        // 2) Agg par num_fiche (si la table decaissement référence le numéro de fiche)
        $byNumKey = $nums ? $this->queryOldPaymentsAggByKeys($nums) : [];

        // Construire map finale par oldFicheId. Priorité au matching par num_fiche si dispo.
        $out = [];
        foreach ($idToNum as $oldId => $num) {
            $oldId = (int)$oldId;
            if ($oldId <= 0) continue;
            $num = trim((string)$num);

            if ($num !== '' && isset($byNumKey[$num])) {
                $out[$oldId] = $byNumKey[$num];
                continue;
            }

            $k = (string)$oldId;
            if (isset($byIdKey[$k])) {
                $out[$oldId] = $byIdKey[$k];
            }
        }

        return $out;
    }

    private function queryOldPaymentsAggByKeys(array $keys): array
    {
        $keys = array_values(array_unique(array_filter(array_map('strval', $keys), fn($v) => trim($v) !== '')));
        if (!$keys) return [];

        if (!$this->tableExists($this->pdoOld, 'decaissement')) {
            throw new RuntimeException("Table 'decaissement' introuvable dans l'ancienne base");
        }

        $cols = $this->getOldDecaisseCols();
        $numCol = $this->pickFirstExisting($cols, ['num_fiche', 'num', 'numero_fiche', 'num_fiche_decaissement']);
        $montantCol = $this->pickFirstExisting($cols, ['montant', 'montant_decaisse', 'montant_decaissé']);
        $dateCol = $this->pickFirstExisting($cols, ['date_decaissement', 'date_decaisse', 'date']);

        if (!$numCol || !$montantCol) {
            throw new RuntimeException("Colonnes décaissement introuvables (num/montant)");
        }

        $out = [];
        $chunks = array_chunk($keys, 200);
        foreach ($chunks as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $sql = 'SELECT ' . $numCol . ' AS k, SUM(' . $montantCol . ') AS total, COUNT(*) AS cnt' .
                ($dateCol ? (', MAX(' . $dateCol . ') AS last_date') : '') .
                ' FROM decaissement WHERE ' . $numCol . ' IN (' . $in . ') AND ' . $montantCol . ' > 0 GROUP BY ' . $numCol;

            $st = $this->pdoOld->prepare($sql);
            $st->execute($chunk);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $k = trim((string)($r['k'] ?? ''));
                if ($k === '') continue;
                $out[$k] = [
                    'total' => (float)($r['total'] ?? 0),
                    'count' => (int)($r['cnt'] ?? 0),
                    'last_date' => $this->normalizeOldPaymentDate($r['last_date'] ?? null),
                ];
            }
        }

        return $out;
    }

    private function getOldPaymentsAggForFicheIds(array $ficheIds): array
    {
        $ficheIds = array_values(array_unique(array_filter(array_map('intval', $ficheIds))));
        $ficheIds = array_values(array_filter($ficheIds, fn($v) => $v > 0));
        if (!$ficheIds) return [];

        if (!$this->tableExists($this->pdoOld, 'decaissement')) {
            throw new RuntimeException("Table 'decaissement' introuvable dans l'ancienne base");
        }

        $cols = $this->getOldDecaisseCols();
        $numCol = $this->pickFirstExisting($cols, ['num_fiche', 'num', 'numero_fiche', 'num_fiche_decaissement']);
        $montantCol = $this->pickFirstExisting($cols, ['montant', 'montant_decaisse', 'montant_decaissé']);
        $dateCol = $this->pickFirstExisting($cols, ['date_decaissement', 'date_decaisse', 'date']);

        if (!$numCol || !$montantCol) {
            throw new RuntimeException("Colonnes décaissement introuvables (num/montant)");
        }

        // Compat legacy: agrégation par clés = ids (cast en string) puis reconversion int.
        $byKey = $this->queryOldPaymentsAggByKeys($ficheIds);
        $out = [];
        foreach ($byKey as $k => $v) {
            $id = (int)$k;
            if ($id <= 0) continue;
            $out[$id] = $v;
        }
        return $out;
    }

    private function getOldPaymentsChronoByFiche(array $oldFiche): array
    {
        $num = $this->extractOldNumFiche($oldFiche);
        $num = trim((string)$num);

        // Priorité au numéro de fiche (cas fréquent: decaissement.num_fiche_decaissement = fiche.num_fiche)
        if ($num !== '') {
            $p = $this->getOldPaymentsChronoByKey($num);
            if ($p) return $p;
        }

        $oldFicheId = $this->extractOldIdFiche($oldFiche);
        if ($oldFicheId === null || $oldFicheId <= 0) return [];
        return $this->getOldPaymentsChronoByKey($oldFicheId);
    }

    private function getOldPaymentsChronoByKey($key): array
    {
        $keyStr = trim((string)$key);
        if ($keyStr === '') return [];
        if (!$this->tableExists($this->pdoOld, 'decaissement')) {
            throw new RuntimeException("Table 'decaissement' introuvable dans l'ancienne base");
        }

        $cols = $this->getOldDecaisseCols();
        $numCol = $this->pickFirstExisting($cols, ['num_fiche', 'num', 'numero_fiche', 'num_fiche_decaissement']);
        $montantCol = $this->pickFirstExisting($cols, ['montant', 'montant_decaisse', 'montant_decaissé']);
        $dateCol = $this->pickFirstExisting($cols, ['date_decaissement', 'date_decaisse', 'date']);
        $idCol = $this->pickFirstExisting($cols, ['id', 'id_decaissement']);

        if (!$numCol || !$montantCol) {
            throw new RuntimeException("Colonnes décaissement introuvables (num/montant)");
        }

        $order = [];
        if ($dateCol) $order[] = $dateCol . ' ASC';
        if ($idCol) $order[] = $idCol . ' ASC';
        if (!$order) $order[] = $montantCol . ' DESC';

        $sql = 'SELECT ' . $montantCol . ' AS montant' . ($dateCol ? (', ' . $dateCol . ' AS d') : '') .
            ' FROM decaissement WHERE ' . $numCol . ' = :k AND ' . $montantCol . ' > 0 ORDER BY ' . implode(',', $order);
        $st = $this->pdoOld->prepare($sql);
        $st->execute(['k' => $keyStr]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'montant' => (float)($r['montant'] ?? 0),
                'date' => $this->normalizeOldPaymentDate($r['d'] ?? null),
            ];
        }
        return $out;
    }

    private function normalizeOldPaymentDate($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '' || $s === '0000-00-00' || $s === '0000-00-00 00:00:00') return null;
        $dt = $this->normDateTime($s);
        return $dt ? substr($dt, 0, 10) : null;
    }

    private function extractOldNumFiche(array $row): string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['num_fiche', 'numero_fiche', 'num']);
        if (!$c) return '';
        return trim((string)($row[$c] ?? ''));
    }

    private function extractOldIdFiche(array $row): ?int
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['id_fiche', 'id', 'fiche_id']);
        if (!$c) return null;
        $v = (int)($row[$c] ?? 0);
        return $v > 0 ? $v : null;
    }

    private function extractOldMontant(array $row): float
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['montant_fiche', 'montant', 'somme', 'total']);
        if (!$c) return 0.0;
        return (float)($row[$c] ?? 0);
    }

    private function extractOldBeneficiaire(array $row): string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['beneficiaire_fiche', 'beficiaire_fiche', 'beneficiaire', 'demandeur', 'nom_beneficiaire']);
        if (!$c) return '';
        return trim((string)($row[$c] ?? ''));
    }

    private function extractOldTelephone(array $row): string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['tel_beneficiaire_fiche', 'tel_beneficiaire', 'telephone', 'tel', 'phone']);
        if (!$c) return '';
        $v = trim((string)($row[$c] ?? ''));
        return $v;
    }

    private function extractOldDesignation(array $row): string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['designation_fiche', 'designation', 'objet', 'libelle', 'motif']);
        if (!$c) return '';
        return trim((string)($row[$c] ?? ''));
    }

    private function extractOldPrecision(array $row): string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['precision_fiche', 'precision', 'details', 'commentaire']);
        if (!$c) return '';
        return trim((string)($row[$c] ?? ''));
    }

    private function extractOldDetails(array $row): string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['details_demande', 'details', 'commentaire', 'observation']);
        if (!$c) return '';
        return trim((string)($row[$c] ?? ''));
    }

    private function extractOldModePaiement(array $row): ?string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['mode_paiement', 'mode', 'type_paiement']);
        if (!$c) return null;
        $v = trim((string)($row[$c] ?? ''));
        return $v !== '' ? $v : null;
    }

    private function isOldFicheDecaissed(array $row): bool
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['decaisse', 'decaissé', 'decaiss', 'is_decaisse']);
        if (!$c) return false;
        return (int)($row[$c] ?? 0) === 1;
    }

    private function extractOldDateDecaissement(array $row): ?string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['date_decaissement', 'date_decaisse', 'date_paiement']);
        if (!$c) return null;
        $v = trim((string)($row[$c] ?? ''));
        if ($v === '' || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return null;
        // Préférer le format date si possible
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
        $dt = $this->normDateTime($v);
        return $dt ? substr($dt, 0, 10) : null;
    }

    private function extractOldCategorie(array $row): ?string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['categorie', 'cat', 'type_depense', 'type']);
        if (!$c) return null;
        $v = trim((string)($row[$c] ?? ''));
        return $v !== '' ? $v : null;
    }

    private function extractOldLigneDebourseId(array $row): ?int
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['ligne_debourse_id', 'id_ligne_debourse', 'ligne_debourse', 'ligne_id']);
        if (!$c) return null;
        $v = (int)($row[$c] ?? 0);
        return $v > 0 ? $v : null;
    }

    private function extractOldCodeParental(array $row): ?string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['code_parental', 'code_parentale', 'code_parent']);
        if (!$c) return null;
        $v = trim((string)($row[$c] ?? ''));
        return $v !== '' ? $v : null;
    }

    private function extractOldParentNum(array $row): ?string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['parent_num_fiche', 'parent_fiche', 'num_parent']);
        if (!$c) return null;
        $v = trim((string)($row[$c] ?? ''));
        return $v !== '' ? $v : null;
    }

    private function extractOldDateCreate(array $row): ?string
    {
        $cols = $this->getOldFicheCols();
        $c = $this->pickFirstExisting($cols, ['date_creat_fiche', 'date_creation', 'created_at', 'date_creat']);
        if (!$c) return null;
        return $this->normDateTime($row[$c] ?? null);
    }

    private function getOldFicheCols(): array
    {
        if ($this->oldFicheCols !== null) return $this->oldFicheCols;
        $this->oldFicheCols = $this->getColumns($this->pdoOld, 'fiche');
        return $this->oldFicheCols;
    }

    private function getOldDecaisseCols(): array
    {
        if ($this->oldDecaisseCols !== null) return $this->oldDecaisseCols;
        $this->oldDecaisseCols = $this->getColumns($this->pdoOld, 'decaissement');
        return $this->oldDecaisseCols;
    }

    private function getOldChantierCols(): array
    {
        if ($this->oldChantierCols !== null) return $this->oldChantierCols;
        $this->oldChantierCols = $this->getColumns($this->pdoOld, 'chantier');
        return $this->oldChantierCols;
    }

    private function getColumns(PDO $pdo, string $table): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new RuntimeException('Nom de table invalide');
        }
        $st = $pdo->query('SHOW COLUMNS FROM ' . $table);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $cols = [];
        foreach ($rows as $r) {
            $f = (string)($r['Field'] ?? '');
            if ($f !== '') $cols[] = $f;
        }
        return $cols;
    }

    private function pickFirstExisting(array $available, array $candidates): ?string
    {
        $set = array_fill_keys($available, true);
        foreach ($candidates as $c) {
            if (isset($set[$c])) return $c;
        }
        return null;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
        $st->execute(['t' => $table]);
        return (int)$st->fetchColumn() > 0;
    }

    private function getDefaultUniteId(): int
    {
        // Priorité aux tables existantes et à leurs colonnes
        $tables = ['unite_banamur', 'unite', 'unites', 'liste_unite'];
        foreach ($tables as $t) {
            if (!$this->tableExists($this->pdoNew, $t)) continue;
            $cols = $this->getColumns($this->pdoNew, $t);
            $idCol = $this->pickFirstExisting($cols, ['id', 'id_unite']);
            if (!$idCol) continue;
            $sql = 'SELECT MIN(' . $idCol . ') FROM ' . $t;
            $id = (int)$this->pdoNew->query($sql)->fetchColumn();
            if ($id > 0) return $id;
        }
        return 1;
    }

    private function getDefaultOffreId(): int
    {
        if (!$this->tableExists($this->pdoNew, 'offre_banamur')) {
            return 1;
        }
        $cols = $this->getColumns($this->pdoNew, 'offre_banamur');
        $idCol = $this->pickFirstExisting($cols, ['id_offre', 'id']);
        if (!$idCol) return 1;
        $id = (int)$this->pdoNew->query('SELECT MIN(' . $idCol . ') FROM offre_banamur')->fetchColumn();
        return $id > 0 ? $id : 1;
    }

    private function mapCategorie(?string $raw): string
    {
        $raw = trim((string)$raw);
        $v = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
        $v = str_replace(['œ', 'Œ', "'"], ['oe', 'oe', ''], $v);

        if (strpos($v, 'transport') !== false) return 'transport';
        if (strpos($v, 'epi') !== false) return 'EPI';
        if (strpos($v, 'main') !== false || strpos($v, 'mo') !== false) return "main d'œuvre";
        if (strpos($v, 'materiel') !== false || strpos($v, 'matériel') !== false) return 'matériel';
        if (strpos($v, 'materiau') !== false || strpos($v, 'matériau') !== false) return 'matériaux';

        return 'matériaux';
    }

    private function normCode(string $code): string
    {
        $code = trim($code);
        $code = strtoupper($code);
        return $code;
    }

    private function normDateTime($v): ?string
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;

        // accepte YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s . ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $s)) {
            return strlen($s) === 16 ? $s . ':00' : $s;
        }

        $ts = strtotime($s);
        if ($ts === false) return null;
        return date('Y-m-d H:i:s', $ts);
    }
}
