<?php
require_once __DIR__.'/../../services/FinancialWorkflowProgress.php';

require_once __DIR__ . '/../includes/fiche_label_helper.php';
require_once __DIR__ . '/../../services/FebWorkflowGuard.php';
require_once __DIR__ . '/../../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../../services/ChantierPauseService.php';

/**
 * Modèle FicheDecaissement - remplace progressivement l'ancien modèle Fiche.
 * Table: fiche_decaissement
 */
class FicheDecaissement
{
    private function activeChantierScope(string $alias='f'): string
    {
        return "NOT EXISTS(SELECT 1 FROM chantier_banamur c_active_scope WHERE c_active_scope.id={$alias}.chantier_id AND c_active_scope.archived_at IS NOT NULL) AND ".ChantierPauseService::activePredicate($this->pdo, "{$alias}.chantier_id");
    }
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /** Synchronise le contrat sur les montants réellement décaissés de la famille FEB. */
    public function syncContractPayment(string $numFiche): void
    {
        $numFiche=trim($numFiche);if($numFiche==='')return;
        try{
            $cols=$this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN,0);
            if(!in_array('contract_payment_id',$cols,true)||!in_array('contract_id',$cols,true))return;
            $q=$this->pdo->prepare('SELECT * FROM fiche_decaissement WHERE num_fiche=? LIMIT 1');$q->execute([$numFiche]);$fiche=$q->fetch(PDO::FETCH_ASSOC);if(!$fiche)return;
            $paymentId=(int)($fiche['contract_payment_id']??0);
            if($paymentId<1){$parent=trim((string)($fiche['parent_num_fiche']??$fiche['code_parentale']??''));if($parent!==''){$q=$this->pdo->prepare('SELECT contract_payment_id,contract_id,contract_payment_type,contract_progress_rate,contract_pv_path,contract_fne_path FROM fiche_decaissement WHERE num_fiche=? LIMIT 1');$q->execute([$parent]);$root=$q->fetch(PDO::FETCH_ASSOC)?:[];$paymentId=(int)($root['contract_payment_id']??0);if($paymentId>0){$q=$this->pdo->prepare('UPDATE fiche_decaissement SET contract_payment_id=?,contract_id=COALESCE(contract_id,?),contract_payment_type=COALESCE(contract_payment_type,?),contract_progress_rate=COALESCE(contract_progress_rate,?),contract_pv_path=COALESCE(contract_pv_path,?),contract_fne_path=COALESCE(contract_fne_path,?) WHERE id_fiche=?');$q->execute([$paymentId,$root['contract_id']??null,$root['contract_payment_type']??null,$root['contract_progress_rate']??null,$root['contract_pv_path']??null,$root['contract_fne_path']??null,(int)$fiche['id_fiche']]);}}}
            if($paymentId<1)return;
            $q=$this->pdo->prepare("SELECT p.contrat_id,p.montant_net,COALESCE(SUM(CASE WHEN f.decaisse=1 THEN f.montant_fiche ELSE 0 END),0) effectif,SUM(CASE WHEN f.decaisse=0 AND f.approuve<>2 AND f.etat_fiche<>2 THEN 1 ELSE 0 END) en_attente,SUM(CASE WHEN f.approuve=2 OR f.etat_fiche=2 THEN 1 ELSE 0 END) refuses FROM contrat_sous_traitance_paiement p LEFT JOIN fiche_decaissement f ON f.contract_payment_id=p.id WHERE p.id=? GROUP BY p.id,p.contrat_id,p.montant_net");$q->execute([$paymentId]);$state=$q->fetch(PDO::FETCH_ASSOC);if(!$state)return;$actual=(float)$state['effectif'];$requested=(float)$state['montant_net'];$pending=(int)$state['en_attente'];$refused=(int)$state['refuses'];$status=$actual>0?($actual+0.01>=$requested?'decaisse':'partiel'):(($refused>0&&$pending===0)?'refuse':'en_circuit');
            $q=$this->pdo->prepare('UPDATE contrat_sous_traitance_paiement SET montant_decaisse_effectif=?,statut_financier=? WHERE id=?');$q->execute([$actual,$status,$paymentId]);
            $contractId=(int)$state['contrat_id'];$q=$this->pdo->prepare("SELECT c.montant_ht,COALESCE(SUM(p.montant_decaisse_effectif),0) total FROM contrat_sous_traitance c LEFT JOIN contrat_sous_traitance_paiement p ON p.contrat_id=c.id WHERE c.id=? GROUP BY c.id,c.montant_ht");$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);if($contract&&((float)$contract['total']+0.01)>=(float)$contract['montant_ht']){$q=$this->pdo->prepare("UPDATE contrat_sous_traitance SET statut='solde' WHERE id=?");$q->execute([$contractId]);}
        }catch(Throwable $e){error_log('[contrats] Synchronisation paiement impossible pour '.$numFiche.' : '.$e->getMessage());}
    }

    private function ensureFebCashflowColumns(): void
    {
        $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        $need = [];
        if (!in_array('facture_reference', $cols, true)) {
            $after = in_array('num_piece', $cols, true) ? ' AFTER num_piece' : '';
            $need[] = "ADD COLUMN facture_reference VARCHAR(150) NULL" . $after;
        }
        if (!in_array('pv_reception_reference', $cols, true)) {
            $need[] = "ADD COLUMN pv_reception_reference TEXT NULL AFTER facture_reference";
        }
        if (!in_array('pv_validator_one', $cols, true)) {
            $need[] = "ADD COLUMN pv_validator_one VARCHAR(150) NULL AFTER pv_reception_reference";
        }
        if (!in_array('pv_validator_two', $cols, true)) {
            $need[] = "ADD COLUMN pv_validator_two VARCHAR(150) NULL AFTER pv_validator_one";
        }
        if (!in_array('progress_rate', $cols, true)) {
            $need[] = "ADD COLUMN progress_rate DECIMAL(7,2) NULL AFTER pv_validator_two";
        }
        if (!in_array('contract_payment_id', $cols, true)) {
            $need[] = "ADD COLUMN contract_payment_id INT NULL";
        }
        foreach ($need as $alterClause) {
            $this->pdo->exec("ALTER TABLE fiche_decaissement " . $alterClause);
        }
    }

    private function getFebCashflowPolicyForFiche(array $fiche): array
    {
        $code = trim((string)($fiche['code_autorisation_feb'] ?? ''));
        if ($code === '') {
            $root = $this->resolveWorkflowRootNum((string)($fiche['num_fiche'] ?? ''));
            if ($root) {
                $rootFiche = $this->getByNum($root);
                $code = trim((string)($rootFiche['code_autorisation_feb'] ?? ''));
            }
        }

        $policy = [
            'code_autorisation_feb' => $code,
            'cashflow_need' => '',
            'cashflow_need_label' => '',
            'total_amount' => (float)($fiche['montant_initial_fiche'] ?? $fiche['montant_fiche'] ?? 0),
            'provisional_amount' => 0.0,
            'requires_invoice_before_conformity' => false,
            'document_reference' => '',
        ];

        if ($code === '' || !class_exists('EmployeeLegalRequestManager')) {
            return $policy;
        }

        try {
            $manager = new EmployeeLegalRequestManager($this->pdo);
            $request = $manager->findApprovedFebByAuthorizationCode($code);
            if (!$request) {
                return $policy;
            }
            $display = (array)($request['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($request));
            $payload = (array)($request['payload'] ?? []);
            $need = trim((string)($display['cashflow_need'] ?? $payload['cashflow_need'] ?? ''));
            $total = (float)($display['montant'] ?? $payload['montant'] ?? $policy['total_amount']);
            $provisional = (float)($display['provisional_disbursement_amount'] ?? $payload['provisional_disbursement_amount'] ?? 0);
            $documentCandidates = [
                $payload['invoice_number'] ?? '',
                $payload['supporting_document_number'] ?? '',
                $payload['facture_reference'] ?? '',
                $payload['numero_facture'] ?? '',
                $payload['numero_justificatif'] ?? '',
                $display['invoice_number'] ?? '',
                $display['supporting_document_number'] ?? '',
            ];
            foreach ($documentCandidates as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    $policy['document_reference'] = $candidate;
                    break;
                }
            }

            $policy['cashflow_need'] = $need;
            $policy['cashflow_need_label'] = trim((string)($display['cashflow_need_label'] ?? $payload['cashflow_need_label'] ?? ''));
            $policy['total_amount'] = $total > 0 ? $total : $policy['total_amount'];
            $policy['provisional_amount'] = max(0.0, $provisional);
            $policy['requires_invoice_before_conformity'] = $need !== '' && $need !== 'provisional_disbursement';
        } catch (\Throwable $e) {
            return $policy;
        }

        return $policy;
    }

    public function getFebCashflowPolicy(string $num_fiche): array
    {
        $fiche = $this->getByNum($num_fiche);
        return $fiche ? $this->getFebCashflowPolicyForFiche($fiche) : [];
    }

    private function getWorkflowAlreadyDisbursedAmount(string $num_fiche): float
    {
        $family = $this->getWorkflowFamilyRows($num_fiche);
        $sum = 0.0;
        foreach (($family['items'] ?? []) as $row) {
            if ((int)($row['decaisse'] ?? 0) === 1) {
                $sum += (float)($row['montant_fiche'] ?? 0);
            }
        }
        return $sum;
    }

    public function getAdvanceAmortizationSuggestion(string $num_fiche, ?float $progressRate = null): array
    {
        $fiche=$this->getByNum($num_fiche);
        if (!$fiche) return [];
        $policy=$this->getFebCashflowPolicyForFiche($fiche);

        $family=$this->getWorkflowFamilyRows($num_fiche);
        $items=(array)($family['items'] ?? []);
        $rootNumber=trim((string)($family['root'] ?? $num_fiche));
        $rootRow=$items[0] ?? $fiche;
        $rootInitial=(float)($rootRow['montant_initial_fiche'] ?? 0);
        $rootPaid=(float)($rootRow['montant_fiche'] ?? 0);
        $isPartialAdvanceSource=(int)($rootRow['decaisse'] ?? 0)===1 && $rootInitial>0 && $rootPaid>0 && $rootPaid<($rootInitial-0.01);
        $isAdvancePv=false;
        try {
            $advanceStmt=$this->pdo->prepare("SELECT 1 FROM disbursement_regularizations
                WHERE num_fiche=:num AND submitted_at IS NOT NULL AND completion_percentage<=0.01 LIMIT 1");
            $advanceStmt->execute(['num'=>$rootNumber]);
            $isAdvancePv=(bool)$advanceStmt->fetchColumn();
        } catch (\Throwable $ignored) {
        }
        if (($policy['cashflow_need'] ?? '') !== 'provisional_disbursement' && !$isAdvancePv && !$isPartialAdvanceSource) return [];
        $alreadyPaid=0.0;
        $advancePaid=0.0;
        $knownProgress=0.0;
        $familyNumbers=[];
        foreach ($items as $row) {
            $number=trim((string)($row['num_fiche'] ?? ''));
            if ($number !== '') $familyNumbers[]=$number;
            if ((int)($row['decaisse'] ?? 0) === 1) {
                $paid=max(0.0,(float)($row['montant_fiche'] ?? 0));
                $alreadyPaid+=$paid;
                if ($advancePaid <= 0) $advancePaid=$paid;
                if (!$isPartialAdvanceSource || $number!==$rootNumber) {
                    $knownProgress=max($knownProgress,(float)($row['progress_rate'] ?? 0));
                }
            }
        }
        $progressNumbers=$isPartialAdvanceSource
            ? array_values(array_filter($familyNumbers,static fn(string $number): bool => $number!==$rootNumber))
            : $familyNumbers;
        if ($progressNumbers !== []) {
            $placeholders=implode(',',array_fill(0,count($progressNumbers),'?'));
            try {
                $stmt=$this->pdo->prepare("SELECT MAX(completion_percentage) FROM disbursement_regularizations WHERE num_fiche IN ($placeholders)");
                $stmt->execute($progressNumbers);
                $knownProgress=max($knownProgress,(float)$stmt->fetchColumn());
            } catch (\Throwable $ignored) {
            }
        }
        $total=max(0.0,(float)($policy['total_amount'] ?? 0));
        if ($advancePaid <= 0) $advancePaid=max(0.0,(float)($policy['provisional_amount'] ?? 0));
        $progress=max(0.0,min(100.0,$progressRate ?? $knownProgress));
        $targetPaid=$advancePaid+max(0.0,$total-$advancePaid)*($progress/100);
        $suggested=max(0.0,$targetPaid-$alreadyPaid);
        return [
            'is_provisional'=>true,
            'source_reason'=>$isAdvancePv ? 'pv_zero_percent' : ($isPartialAdvanceSource ? 'partial_source_advance' : 'feb_provisional_disbursement'),
            'contract_total'=>round($total,2),
            'advance_paid'=>round($advancePaid,2),
            'already_paid'=>round($alreadyPaid,2),
            'progress_rate'=>round($progress,2),
            'gross_earned'=>round($total*($progress/100),2),
            'advance_recovered'=>round($advancePaid*($progress/100),2),
            'target_cumulative_paid'=>round($targetPaid,2),
            'suggested_next_payment'=>round($suggested,2),
            'remaining_contract_balance'=>round(max(0.0,$total-$alreadyPaid),2),
            'explanation'=>'Suggestion non bloquante : avance + (total - avance) × avancement, diminuée du cumul déjà payé.',
        ];
    }

    /**
     * Retourne une fiche par son numéro.
     */
    public function getByNum($num_fiche)
    {
        $sql = "SELECT f.* FROM fiche_decaissement f WHERE f.num_fiche = :num AND ".$this->activeChantierScope('f');
        $st = $this->pdo->prepare($sql);
        $st->execute(['num' => $num_fiche]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Détails enrichis (jointures principales) pour modification / analyse.
     */
    public function getDetailsByNum($num_fiche)
    {
        require_once __DIR__ . '/../../services/FebBeneficiarySyncService.php';
        $feb = $this->pdo->prepare('SELECT feb_request_id FROM fiche_decaissement WHERE num_fiche=:num LIMIT 1');
        $feb->execute(['num' => $num_fiche]);
        FebBeneficiarySyncService::syncFiche($this->pdo, (int) $feb->fetchColumn());
        $sql = "SELECT f.*, p.details_demande AS parent_details_demande,
                  c.titre AS chantier_titre, c.code AS chantier_code, c.entreprise AS entreprise, c.devis_id AS chantier_devis_id,
                       cl.nom_client,
                       ldb.id AS linked_id, ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ldb.montant AS ligne_montant,
                       db.id AS debourse_id, db.devis_id AS linked_devis_id, db.montant_debourse AS debourse_montant,
                       ld.id AS ligne_devis_id, ld.designation AS devis_designation
                FROM fiche_decaissement f
              LEFT JOIN fiche_decaissement p ON p.num_fiche = COALESCE(NULLIF(f.parent_num_fiche, ''), NULLIF(f.code_parentale, ''))
                LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
                LEFT JOIN client_banamur cl ON cl.id_client = c.client_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
                WHERE f.num_fiche = :num AND ".$this->activeChantierScope('f');
        $st = $this->pdo->prepare($sql);
        $st->execute(['num' => $num_fiche]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ? enrich_fiche_labels($row) : null;
    }

    private function normalizeDebourseMatchText(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false && $converted !== '') {
                $value = $converted;
            }
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function getRelinkCandidateFromIndexes(array $fiche, array $indexes): ?array
    {
        $designationKey = $this->normalizeDebourseMatchText($fiche['designation_fiche'] ?? '');
        $precisionKey = $this->normalizeDebourseMatchText($fiche['precision_fiche'] ?? '');

        if ($designationKey !== '' && $precisionKey !== '') {
            $exactKey = $designationKey . '|' . $precisionKey;
            $exactBoth = $indexes['exact_both'][$exactKey] ?? [];
            if (count($exactBoth) === 1) {
                return $exactBoth[0];
            }

            $precisionCandidates = $indexes['precision'][$precisionKey] ?? [];
            if (count($precisionCandidates) === 1) {
                $candidateDesignation = $precisionCandidates[0]['devis_key'] ?? '';
                if ($candidateDesignation === $designationKey || $candidateDesignation === '') {
                    return $precisionCandidates[0];
                }
                if (strpos($candidateDesignation, $designationKey) !== false || strpos($designationKey, $candidateDesignation) !== false) {
                    return $precisionCandidates[0];
                }
            }
        }

        return null;
    }

    public function repairMissingDebourseLinksForChantier(int $chantierId): array
    {
        if ($chantierId <= 0) {
            return ['scanned' => 0, 'updated' => 0, 'remaining' => 0];
        }

        $chantierStmt = $this->pdo->prepare('SELECT id, devis_id FROM chantier_banamur WHERE id = :id AND archived_at IS NULL LIMIT 1');
        $chantierStmt->execute(['id' => $chantierId]);
        $chantier = $chantierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $devisId = (int)($chantier['devis_id'] ?? 0);
        if ($devisId <= 0) {
            return ['scanned' => 0, 'updated' => 0, 'remaining' => 0];
        }

        $candidatesStmt = $this->pdo->prepare(
            "SELECT
                ldb.id,
                ldb.designation,
                ldb.categorie,
                db.id AS debourse_id,
                db.ligne_devis_id,
                ld.designation AS devis_designation
             FROM ligne_debourse_banamur ldb
             INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
             LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
             WHERE db.devis_id = :devis_id"
        );
        $candidatesStmt->execute(['devis_id' => $devisId]);
        $candidates = $candidatesStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$candidates) {
            return ['scanned' => 0, 'updated' => 0, 'remaining' => 0];
        }

        $indexes = [
            'exact_both' => [],
            'precision' => [],
        ];

        foreach ($candidates as $candidate) {
            $designationKey = $this->normalizeDebourseMatchText($candidate['devis_designation'] ?? '');
            $precisionKey = $this->normalizeDebourseMatchText($candidate['designation'] ?? '');
            $candidate['devis_key'] = $designationKey;
            $candidate['precision_key'] = $precisionKey;

            if ($designationKey !== '' && $precisionKey !== '') {
                $indexes['exact_both'][$designationKey . '|' . $precisionKey][] = $candidate;
            }
            if ($precisionKey !== '') {
                $indexes['precision'][$precisionKey][] = $candidate;
            }
        }

        $orphansStmt = $this->pdo->prepare(
            "SELECT
                f.id_fiche,
                f.num_fiche,
                f.designation_fiche,
                f.precision_fiche,
                f.ligne_debourse_id,
                ldb.id AS linked_id,
                db.devis_id AS linked_devis_id
             FROM fiche_decaissement f
             LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
             LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
             WHERE f.chantier_id = :chantier_id
               AND (
                    f.ligne_debourse_id IS NULL
                    OR f.ligne_debourse_id = 0
                    OR ldb.id IS NULL
                    OR db.devis_id IS NULL
                    OR db.devis_id <> :devis_id
               )"
        );
        $orphansStmt->execute([
            'chantier_id' => $chantierId,
            'devis_id' => $devisId,
        ]);
        $orphans = $orphansStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$orphans) {
            return ['scanned' => 0, 'updated' => 0, 'remaining' => 0];
        }

        $updated = 0;
        $updateStmt = $this->pdo->prepare(
            'UPDATE fiche_decaissement SET ligne_debourse_id = :ligne_id, updated_at = NOW() WHERE id_fiche = :id_fiche'
        );

        FinancialWorkflowProgress::begin($this->pdo);
        try {
            foreach ($orphans as $fiche) {
                $candidate = $this->getRelinkCandidateFromIndexes($fiche, $indexes);
                if (!$candidate) {
                    continue;
                }

                $ligneDebourseId = (int)($candidate['id'] ?? 0);
                if ($ligneDebourseId <= 0) {
                    continue;
                }

                $updateStmt->execute([
                    'ligne_id' => $ligneDebourseId,
                    'id_fiche' => (int)$fiche['id_fiche'],
                ]);
                if ($updateStmt->rowCount() > 0) {
                    $updated++;
                    $this->traceAction(
                        (string)($fiche['num_fiche'] ?? ''),
                        'RELINK_LIGNE_DEBOURSE',
                        'SYSTEM',
                        '',
                        0,
                        'old=' . (string)($fiche['ligne_debourse_id'] ?? '') . ';new=' . $ligneDebourseId
                    );
                }
            }
            if ($this->pdo->inTransaction()) {
                FinancialWorkflowProgress::commit($this->pdo);
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                FinancialWorkflowProgress::rollback($this->pdo);
            }
            throw $e;
        }

        return [
            'scanned' => count($orphans),
            'updated' => $updated,
            'remaining' => max(count($orphans) - $updated, 0),
        ];
    }

    private function loadRelinkCandidatesForDevis(int $devisId): array
    {
        if ($devisId <= 0) {
            return ['candidates' => [], 'indexes' => ['exact_both' => [], 'precision' => [], 'designation' => []]];
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                ldb.id,
                ldb.designation,
                ldb.categorie,
                ldb.montant,
                db.id AS debourse_id,
                db.ligne_devis_id,
                ld.designation AS devis_designation
             FROM ligne_debourse_banamur ldb
             INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
             LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
             WHERE db.devis_id = :devis_id
             ORDER BY ld.designation, ldb.designation, ldb.id"
        );
        $stmt->execute(['devis_id' => $devisId]);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $indexes = [
            'exact_both' => [],
            'precision' => [],
            'designation' => [],
        ];

        foreach ($candidates as &$candidate) {
            $designationKey = $this->normalizeDebourseMatchText($candidate['devis_designation'] ?? '');
            $precisionKey = $this->normalizeDebourseMatchText($candidate['designation'] ?? '');
            $candidate['devis_key'] = $designationKey;
            $candidate['precision_key'] = $precisionKey;
            $candidate['label'] = trim(
                '#' . (string)($candidate['debourse_id'] ?? '')
                    . ' - '
                    . (string)($candidate['devis_designation'] ?? 'Sans ligne devis')
                    . ' -> '
                    . (string)($candidate['designation'] ?? 'Sans désignation')
            );

            if ($designationKey !== '' && $precisionKey !== '') {
                $indexes['exact_both'][$designationKey . '|' . $precisionKey][] = $candidate;
            }
            if ($precisionKey !== '') {
                $indexes['precision'][$precisionKey][] = $candidate;
            }
            if ($designationKey !== '') {
                $indexes['designation'][$designationKey][] = $candidate;
            }
        }
        unset($candidate);

        return ['candidates' => $candidates, 'indexes' => $indexes];
    }

    private function buildRelinkCandidateSuggestions(array $fiche, array $candidateSet, int $limit = 8): array
    {
        $designationKey = $this->normalizeDebourseMatchText($fiche['designation_fiche'] ?? '');
        $precisionKey = $this->normalizeDebourseMatchText($fiche['precision_fiche'] ?? '');
        $suggestions = [];

        foreach ($candidateSet['candidates'] ?? [] as $candidate) {
            $candidateDesignation = (string)($candidate['devis_key'] ?? '');
            $candidatePrecision = (string)($candidate['precision_key'] ?? '');
            $score = 0;
            $reasons = [];

            if ($designationKey !== '' && $candidateDesignation === $designationKey) {
                $score += 70;
                $reasons[] = 'designation exacte';
            } elseif ($designationKey !== '' && $candidateDesignation !== '' && (strpos($candidateDesignation, $designationKey) !== false || strpos($designationKey, $candidateDesignation) !== false)) {
                $score += 30;
                $reasons[] = 'designation proche';
            }

            if ($precisionKey !== '' && $candidatePrecision === $precisionKey) {
                $score += 100;
                $reasons[] = 'precision exacte';
            } elseif ($precisionKey !== '' && $candidatePrecision !== '' && (strpos($candidatePrecision, $precisionKey) !== false || strpos($precisionKey, $candidatePrecision) !== false)) {
                $score += 45;
                $reasons[] = 'precision proche';
            }

            if ($score <= 0) {
                continue;
            }

            $candidate['score'] = $score;
            $candidate['reasons'] = $reasons;
            $suggestions[] = $candidate;
        }

        usort($suggestions, static function (array $left, array $right): int {
            if (($left['score'] ?? 0) === ($right['score'] ?? 0)) {
                return [
                    (string)($left['devis_designation'] ?? ''),
                    (string)($left['designation'] ?? ''),
                    (int)($left['id'] ?? 0),
                ] <=> [
                    (string)($right['devis_designation'] ?? ''),
                    (string)($right['designation'] ?? ''),
                    (int)($right['id'] ?? 0),
                ];
            }

            return (int)($right['score'] ?? 0) <=> (int)($left['score'] ?? 0);
        });

        $seen = [];
        $result = [];
        foreach ($suggestions as $candidate) {
            $candidateId = (int)($candidate['id'] ?? 0);
            if ($candidateId <= 0 || isset($seen[$candidateId])) {
                continue;
            }
            $seen[$candidateId] = true;
            $result[] = [
                'id' => $candidateId,
                'debourse_id' => (int)($candidate['debourse_id'] ?? 0),
                'ligne_devis_id' => (int)($candidate['ligne_devis_id'] ?? 0),
                'devis_designation' => (string)($candidate['devis_designation'] ?? ''),
                'designation' => (string)($candidate['designation'] ?? ''),
                'categorie' => (string)($candidate['categorie'] ?? ''),
                'montant' => (float)($candidate['montant'] ?? 0),
                'score' => (int)($candidate['score'] ?? 0),
                'reasons' => $candidate['reasons'] ?? [],
                'label' => (string)($candidate['label'] ?? ''),
            ];
            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function getDebourseLineOptionsForDevis(int $devisId): array
    {
        $candidateSet = $this->loadRelinkCandidatesForDevis($devisId);
        $options = [];

        foreach ($candidateSet['candidates'] as $candidate) {
            $options[] = [
                'id' => (int)($candidate['id'] ?? 0),
                'debourse_id' => (int)($candidate['debourse_id'] ?? 0),
                'ligne_devis_id' => (int)($candidate['ligne_devis_id'] ?? 0),
                'devis_designation' => (string)($candidate['devis_designation'] ?? ''),
                'designation' => (string)($candidate['designation'] ?? ''),
                'categorie' => (string)($candidate['categorie'] ?? ''),
                'montant' => (float)($candidate['montant'] ?? 0),
                'label' => (string)($candidate['label'] ?? ''),
            ];
        }

        return $options;
    }

    public function listUnresolvedDebourseLinks(array $filters = []): array
    {
        $where = [
            'f.chantier_id IS NOT NULL',
            $this->activeChantierScope('f'),
            '(f.decaisse = 1 OR f.approuve = 1 OR f.conforme = 1)',
            '(f.ligne_debourse_id IS NULL OR f.ligne_debourse_id = 0 OR ldb.id IS NULL OR db.devis_id IS NULL OR (c.devis_id IS NOT NULL AND c.devis_id > 0 AND db.devis_id <> c.devis_id))',
        ];
        $params = [];

        if (!empty($filters['chantier_id'])) {
            $where[] = 'f.chantier_id = :chantier_id';
            $params['chantier_id'] = (int)$filters['chantier_id'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(f.num_fiche LIKE :search OR f.designation_fiche LIKE :search OR f.precision_fiche LIKE :search OR c.code LIKE :search OR c.titre LIKE :search)';
            $params['search'] = '%' . trim((string)$filters['search']) . '%';
        }

        $sql = "SELECT
                    f.id_fiche,
                    f.num_fiche,
                    f.chantier_id,
                    c.code AS chantier_code,
                    c.titre AS chantier_titre,
                    c.devis_id,
                    f.designation_fiche,
                    f.precision_fiche,
                    f.montant_fiche,
                    f.ligne_debourse_id,
                    ldb.id AS linked_id,
                    db.id AS linked_debourse_id,
                    db.devis_id AS linked_devis_id,
                    CASE
                        WHEN f.ligne_debourse_id IS NULL OR f.ligne_debourse_id = 0 THEN 'absent'
                        WHEN ldb.id IS NULL THEN 'ligne_supprimee'
                        WHEN db.devis_id IS NULL THEN 'debourse_supprime'
                        WHEN c.devis_id IS NOT NULL AND c.devis_id > 0 AND db.devis_id <> c.devis_id THEN 'mauvais_devis'
                        ELSE 'invalide'
                    END AS invalid_reason
                FROM fiche_decaissement f
                LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.code, f.num_fiche";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $candidateCache = [];
        $results = [];
        $manualOnly = !empty($filters['manual_only']);

        foreach ($rows as $row) {
            $devisId = (int)($row['devis_id'] ?? 0);
            if (!isset($candidateCache[$devisId])) {
                $candidateCache[$devisId] = $this->loadRelinkCandidatesForDevis($devisId);
            }

            $candidateSet = $candidateCache[$devisId];
            $autoCandidate = $this->getRelinkCandidateFromIndexes($row, $candidateSet['indexes'] ?? []);
            $suggestions = $this->buildRelinkCandidateSuggestions($row, $candidateSet, 6);

            if ($manualOnly && $autoCandidate) {
                continue;
            }

            $results[] = [
                'id_fiche' => (int)($row['id_fiche'] ?? 0),
                'num_fiche' => (string)($row['num_fiche'] ?? ''),
                'chantier_id' => (int)($row['chantier_id'] ?? 0),
                'chantier_code' => (string)($row['chantier_code'] ?? ''),
                'chantier_titre' => (string)($row['chantier_titre'] ?? ''),
                'devis_id' => $devisId,
                'designation_fiche' => (string)($row['designation_fiche'] ?? ''),
                'precision_fiche' => (string)($row['precision_fiche'] ?? ''),
                'montant_fiche' => (float)($row['montant_fiche'] ?? 0),
                'ligne_debourse_id' => (int)($row['ligne_debourse_id'] ?? 0),
                'invalid_reason' => (string)($row['invalid_reason'] ?? 'invalide'),
                'auto_candidate' => $autoCandidate ? [
                    'id' => (int)($autoCandidate['id'] ?? 0),
                    'debourse_id' => (int)($autoCandidate['debourse_id'] ?? 0),
                    'ligne_devis_id' => (int)($autoCandidate['ligne_devis_id'] ?? 0),
                    'devis_designation' => (string)($autoCandidate['devis_designation'] ?? ''),
                    'designation' => (string)($autoCandidate['designation'] ?? ''),
                    'categorie' => (string)($autoCandidate['categorie'] ?? ''),
                    'montant' => (float)($autoCandidate['montant'] ?? 0),
                    'label' => (string)($autoCandidate['label'] ?? ''),
                ] : null,
                'candidate_suggestions' => $suggestions,
            ];
        }

        return $results;
    }

    public function relinkFicheDebourseManually(int $ficheId, int $ligneDebourseId, string $user, string $ip = '', int $port = 0, string $note = ''): array
    {
        if ($ficheId <= 0 || $ligneDebourseId <= 0) {
            throw new InvalidArgumentException('Paramètres de reliaison invalides.');
        }

        // Evite toute DDL implicite sur trace_fiche pendant une transaction active.
        $this->ensureTraceTable();

        $ficheStmt = $this->pdo->prepare(
            "SELECT f.id_fiche, f.num_fiche, f.chantier_id, f.ligne_debourse_id, c.devis_id
             FROM fiche_decaissement f
             LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
             WHERE f.id_fiche = :id_fiche
             LIMIT 1"
        );
        $ficheStmt->execute(['id_fiche' => $ficheId]);
        $fiche = $ficheStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$fiche) {
            throw new RuntimeException('Fiche introuvable.');
        }

        $ligneStmt = $this->pdo->prepare(
            "SELECT ldb.id, ldb.designation, db.id AS debourse_id, db.devis_id, ld.designation AS devis_designation
             FROM ligne_debourse_banamur ldb
             INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
             LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
             WHERE ldb.id = :ligne_id
             LIMIT 1"
        );
        $ligneStmt->execute(['ligne_id' => $ligneDebourseId]);
        $ligne = $ligneStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$ligne) {
            throw new RuntimeException('Ligne de déboursé introuvable.');
        }

        $chantierDevisId = (int)($fiche['devis_id'] ?? 0);
        $ligneDevisId = (int)($ligne['devis_id'] ?? 0);
        if ($chantierDevisId > 0 && $ligneDevisId > 0 && $chantierDevisId !== $ligneDevisId) {
            throw new RuntimeException('La ligne choisie ne correspond pas au devis du chantier.');
        }

        $oldLigneId = (int)($fiche['ligne_debourse_id'] ?? 0);
        if ($oldLigneId === $ligneDebourseId) {
            return [
                'changed' => false,
                'num_fiche' => (string)($fiche['num_fiche'] ?? ''),
                'old_ligne_id' => $oldLigneId,
                'new_ligne_id' => $ligneDebourseId,
                'debourse_id' => (int)($ligne['debourse_id'] ?? 0),
            ];
        }

        $updateStmt = $this->pdo->prepare('UPDATE fiche_decaissement SET ligne_debourse_id = :ligne_id, updated_at = NOW() WHERE id_fiche = :id_fiche');
        $updateStmt->execute([
            'ligne_id' => $ligneDebourseId,
            'id_fiche' => $ficheId,
        ]);

        $details = 'manual old=' . $oldLigneId . ';new=' . $ligneDebourseId;
        $note = trim($note);
        if ($note !== '') {
            $details .= ';note=' . preg_replace('/\s+/', ' ', $note);
        }
        $this->traceAction((string)($fiche['num_fiche'] ?? ''), 'MANUAL_RELINK_LIGNE_DEBOURSE', $user, $ip, $port, $details);

        return [
            'changed' => $updateStmt->rowCount() > 0,
            'num_fiche' => (string)($fiche['num_fiche'] ?? ''),
            'old_ligne_id' => $oldLigneId,
            'new_ligne_id' => $ligneDebourseId,
            'debourse_id' => (int)($ligne['debourse_id'] ?? 0),
            'devis_designation' => (string)($ligne['devis_designation'] ?? ''),
            'designation' => (string)($ligne['designation'] ?? ''),
        ];
    }

    public function relinkFichesDebourseByMotif(int $chantierId, string $designationFiche, string $precisionFiche, int $ligneDebourseId, string $user, string $ip = '', int $port = 0, string $note = ''): array
    {
        $designationFiche = trim($designationFiche);
        $precisionFiche = trim($precisionFiche);

        if ($chantierId <= 0 || $ligneDebourseId <= 0 || $designationFiche === '' || $precisionFiche === '') {
            throw new InvalidArgumentException('Paramètres de reliaison de masse invalides.');
        }

        // Evite qu'une création/altération tardive de trace_fiche annule implicitement la transaction PDO.
        $this->ensureTraceTable();

        $chantierStmt = $this->pdo->prepare('SELECT id, devis_id, code, titre FROM chantier_banamur WHERE id = :id AND archived_at IS NULL LIMIT 1');
        $chantierStmt->execute(['id' => $chantierId]);
        $chantier = $chantierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$chantier) {
            throw new RuntimeException('Chantier introuvable.');
        }

        $ligneStmt = $this->pdo->prepare(
            "SELECT ldb.id, ldb.designation, db.id AS debourse_id, db.devis_id, ld.designation AS devis_designation
             FROM ligne_debourse_banamur ldb
             INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
             LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
             WHERE ldb.id = :ligne_id
             LIMIT 1"
        );
        $ligneStmt->execute(['ligne_id' => $ligneDebourseId]);
        $ligne = $ligneStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$ligne) {
            throw new RuntimeException('Ligne de déboursé introuvable.');
        }

        $chantierDevisId = (int)($chantier['devis_id'] ?? 0);
        $ligneDevisId = (int)($ligne['devis_id'] ?? 0);
        if ($chantierDevisId > 0 && $ligneDevisId > 0 && $chantierDevisId !== $ligneDevisId) {
            throw new RuntimeException('La ligne choisie ne correspond pas au devis du chantier.');
        }

        $targetStmt = $this->pdo->prepare(
            "SELECT f.id_fiche, f.num_fiche, f.ligne_debourse_id
             FROM fiche_decaissement f
             LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
             LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
             WHERE f.chantier_id = :chantier_id
               AND f.designation_fiche = :designation_fiche
               AND f.precision_fiche = :precision_fiche
               AND (f.decaisse = 1 OR f.approuve = 1 OR f.conforme = 1)
               AND (
                    f.ligne_debourse_id IS NULL
                    OR f.ligne_debourse_id = 0
                    OR ldb.id IS NULL
                    OR db.devis_id IS NULL
                    OR (:chantier_devis_id > 0 AND db.devis_id <> :chantier_devis_id)
               )
             ORDER BY f.num_fiche"
        );
        $targetStmt->execute([
            'chantier_id' => $chantierId,
            'designation_fiche' => $designationFiche,
            'precision_fiche' => $precisionFiche,
            'chantier_devis_id' => $chantierDevisId,
        ]);
        $targets = $targetStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($targets === []) {
            throw new RuntimeException('Aucune fiche non reliée ne correspond à ce motif.');
        }

        $updateStmt = $this->pdo->prepare('UPDATE fiche_decaissement SET ligne_debourse_id = :ligne_id, updated_at = NOW() WHERE id_fiche = :id_fiche');
        $updated = 0;
        $unchanged = 0;
        $updatedNums = [];

        FinancialWorkflowProgress::begin($this->pdo);
        try {
            foreach ($targets as $target) {
                $oldLigneId = (int)($target['ligne_debourse_id'] ?? 0);
                if ($oldLigneId === $ligneDebourseId) {
                    $unchanged++;
                    continue;
                }

                $updateStmt->execute([
                    'ligne_id' => $ligneDebourseId,
                    'id_fiche' => (int)$target['id_fiche'],
                ]);

                if ($updateStmt->rowCount() > 0) {
                    $updated++;
                    $updatedNums[] = (string)($target['num_fiche'] ?? '');
                    $details = 'bulk old=' . $oldLigneId . ';new=' . $ligneDebourseId . ';motif=' . preg_replace('/\s+/', ' ', $designationFiche . ' / ' . $precisionFiche);
                    $cleanNote = trim($note);
                    if ($cleanNote !== '') {
                        $details .= ';note=' . preg_replace('/\s+/', ' ', $cleanNote);
                    }
                    $this->traceAction((string)($target['num_fiche'] ?? ''), 'BULK_MANUAL_RELINK_LIGNE_DEBOURSE', $user, $ip, $port, $details);
                }
            }
            if ($this->pdo->inTransaction()) {
                FinancialWorkflowProgress::commit($this->pdo);
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                FinancialWorkflowProgress::rollback($this->pdo);
            }
            throw $e;
        }

        return [
            'updated' => $updated,
            'unchanged' => $unchanged,
            'matched' => count($targets),
            'updated_nums' => $updatedNums,
            'chantier_code' => (string)($chantier['code'] ?? ''),
            'chantier_titre' => (string)($chantier['titre'] ?? ''),
            'devis_designation' => (string)($ligne['devis_designation'] ?? ''),
            'designation' => (string)($ligne['designation'] ?? ''),
            'debourse_id' => (int)($ligne['debourse_id'] ?? 0),
            'new_ligne_id' => $ligneDebourseId,
            'designation_fiche' => $designationFiche,
            'precision_fiche' => $precisionFiche,
        ];
    }

    /**
     * Liste des fiches à certifier conformes (nouvelle logique) :
     * - conforme = 0
     * - approuve = 0
     * - decaisse = 0
     * (On peut ajouter d'autres conditions au besoin)
     */
    public function listAcertifier($filters = [])
    {
        $this->ensureFebCashflowColumns();
        $where = ["f.conforme = 0", "f.approuve = 0", "f.decaisse = 0",$this->activeChantierScope('f')];
        $params = [];

        if (!empty($filters['date_debut'])) {
            $where[] = 'f.date_creat_fiche >= :date_debut';
            $params['date_debut'] = $filters['date_debut'] . ' 00:00:00';
        }
        if (!empty($filters['date_fin'])) {
            $where[] = 'f.date_creat_fiche <= :date_fin';
            $params['date_fin'] = $filters['date_fin'] . ' 23:59:59';
        }
        if (!empty($filters['demandeur'])) {
            $where[] = 'f.beficiaire_fiche LIKE :benef';
            $params['benef'] = '%' . $filters['demandeur'] . '%';
        }
        if (!empty($filters['chantier'])) {
            $where[] = '(c.titre LIKE :chantier OR c.code LIKE :chantier)';
            $params['chantier'] = '%' . $filters['chantier'] . '%';
        }
        if (!empty($filters['precision'])) {
            $where[] = '(f.precision_fiche LIKE :precision
                        OR ldb.designation LIKE :precision
                        OR ldb.categorie LIKE :precision)';
            $params['precision'] = '%' . $filters['precision'] . '%';
        }
        if (!empty($filters['designation'])) {
            $where[] = 'f.designation_fiche LIKE :designation';
            $params['designation'] = '%' . $filters['designation'] . '%';
        }
        if (!empty($filters['montant_min'])) {
            $where[] = 'f.montant_fiche >= :montant_min';
            $params['montant_min'] = (float)$filters['montant_min'];
        }
        if (!empty($filters['montant_max'])) {
            $where[] = 'f.montant_fiche <= :montant_max';
            $params['montant_max'] = (float)$filters['montant_max'];
        }

        $sql = "SELECT f.*, c.titre AS chantier_titre, c.code AS chantier_code,
             ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie,
             ld.designation AS devis_designation
        FROM fiche_decaissement f
        LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
        LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
         LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
         LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY f.date_creat_fiche DESC";
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = array_map('enrich_fiche_labels', $st->fetchAll(PDO::FETCH_ASSOC));
        foreach ($rows as &$row) {
            $policy = $this->getFebCashflowPolicyForFiche($row);
            $row['feb_cashflow_need'] = $policy['cashflow_need'] ?? '';
            $row['feb_cashflow_need_label'] = $policy['cashflow_need_label'] ?? '';
            $row['feb_requires_invoice_before_conformity'] = !empty($policy['requires_invoice_before_conformity']);
            $row['feb_provisional_amount'] = (float)($policy['provisional_amount'] ?? 0);
            $row['feb_total_amount'] = (float)($policy['total_amount'] ?? 0);
            $advanceSuggestion=$this->getAdvanceAmortizationSuggestion((string)($row['num_fiche'] ?? ''));
            $row['advance_amortization'] = $advanceSuggestion;
            $row['conformity_blocker'] = '';
            if (!empty($row['feb_requires_invoice_before_conformity']) && trim((string)($row['facture_reference'] ?? '')) === '') {
                $row['conformity_blocker'] = 'Facture obligatoire avant certification conforme.';
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * Met à jour statut conforme.
     */
    public function setConforme($num_fiche, $secur_user, ?string $factureReference = null)
    {
        $this->ensureFebCashflowColumns();
        // Ne rien faire si déjà conforme
        $check = $this->getByNum($num_fiche);
        if (!$check) return false;
        if (!empty($check['conforme'])) {
            return true; // déjà conforme
        }
        $factureReference = trim((string)($factureReference ?? ''));
        $policy = $this->getFebCashflowPolicyForFiche($check);
        if ($factureReference === '') {
            $factureReference = trim((string)($policy['document_reference'] ?? ''));
        }
        if ($factureReference !== '') {
            $upFacture = $this->pdo->prepare('UPDATE fiche_decaissement SET facture_reference = :facture WHERE num_fiche = :num');
            $upFacture->execute(['facture' => $factureReference, 'num' => $num_fiche]);
            $check['facture_reference'] = $factureReference;
        }
        if (!empty($policy['requires_invoice_before_conformity']) && trim((string)($check['facture_reference'] ?? '')) === '') {
            throw new \RuntimeException('Reference facture ou justificatif introuvable dans la FEB. Veuillez completer la FEB avant certification conforme.');
        }

        $sql = "UPDATE fiche_decaissement SET conforme = 1, date_conforme = NOW(), secur_conforme = :secur WHERE num_fiche = :num";
        $st = $this->pdo->prepare($sql);
        if (!$st->execute(['secur' => $secur_user, 'num' => $num_fiche])) return false;
        $this->syncContractPayment((string)$num_fiche);
        return $st->rowCount() > 0;
    }

    /**
     * Analyse budgétaire d'une fiche : compare la somme déjà décaissée + montant de la fiche
     * au montant prévu de la ligne_debourse.
     * Retourne structure avec: montant_ligne, total_deja_decaisse, total_en_attente, total_projetee (incl fiche), ecart, ratio, niveau, conseils.
     */
    public function analyseBudgetFiche($num_fiche)
    {
        $fiche = $this->getByNum($num_fiche);
        if (!$fiche) return null;
        $ligneId = $fiche['ligne_debourse_id'];
        if (!$ligneId) return null;

        // Montant ligne prévue
        $stmt = $this->pdo->prepare("SELECT montant, designation, categorie FROM ligne_debourse_banamur WHERE id = ?");
        $stmt->execute([$ligneId]);
        $ligne = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ligne) return null;
        $montantLigne = (float)$ligne['montant'];

        // Totaux existants (autres fiches)
        $sql = "SELECT 
                   SUM(CASE WHEN decaisse=1 THEN montant_fiche ELSE 0 END) AS total_decaisse,
                   SUM(CASE WHEN decaisse=0 THEN montant_fiche ELSE 0 END) AS total_non_decaisse
                FROM fiche_decaissement
                WHERE ligne_debourse_id = :ligne AND num_fiche <> :num";
        $st = $this->pdo->prepare($sql);
        $st->execute(['ligne' => $ligneId, 'num' => $num_fiche]);
        $agg = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalDecaisse = (float)($agg['total_decaisse'] ?? 0);
        $totalNonDecaisse = (float)($agg['total_non_decaisse'] ?? 0);

        $montantFiche = (float)$fiche['montant_fiche'];
        $projection = $totalDecaisse + $montantFiche; // On compare ce qui serait décaissé si acceptée
        $ecart = $montantLigne - $projection; // positif => marge restante
        $ratio = $montantLigne > 0 ? $projection / $montantLigne : 0;

        // Classification
        if ($montantLigne <= 0) {
            $niveau = 'invalide';
        } elseif ($ratio < 0.75) {
            $niveau = 'large_marge';
        } elseif ($ratio < 0.95) {
            $niveau = 'marge';
        } elseif ($ratio <= 1.0) {
            $niveau = 'limite';
        } elseif ($ratio <= 1.1) {
            $niveau = 'depasse_legere';
        } else {
            $niveau = 'depasse_forte';
        }

        switch ($niveau) {
            case 'large_marge':
                $conseil = "Budget confortable. Aucun risque immédiat.";
                break;
            case 'marge':
                $conseil = "Surveillance recommandée à l'approche de la limite.";
                break;
            case 'limite':
                $conseil = "Très proche du plafond: valider uniquement si prioritaire.";
                break;
            case 'depasse_legere':
                $conseil = "Dépassement léger: justification nécessaire.";
                break;
            case 'depasse_forte':
                $conseil = "Dépassement important: exiger validation supérieure.";
                break;
            default:
                $conseil = "Montant de ligne invalide ou données insuffisantes.";
                break;
        }

        return [
            'fiche_num' => $num_fiche,
            'ligne_id' => $ligneId,
            'ligne_designation' => $ligne['designation'] ?? '',
            'ligne_categorie' => $ligne['categorie'] ?? '',
            'montant_ligne' => $montantLigne,
            'total_deja_decaisse' => $totalDecaisse,
            'total_non_decaisse' => $totalNonDecaisse,
            'montant_fiche' => $montantFiche,
            'projection_decaisse' => $projection,
            'ecart_restant' => $ecart,
            'ratio' => $ratio,
            'niveau' => $niveau,
            'conseil' => $conseil
        ];
    }

    /**
     * Liste des fiches prêtes pour validation achat (nouvelle logique unifiée)
     * Conditions business (ajuster si besoin):
     *  - conforme = 1 (certifiée conforme)
     *  - approuve = 1 (approuvée)
     *  - etat_fiche = 0 (pas encore validée achat)
     *  - decaisse = 0 (non décaissée)
     *  - date_decaissement_minimum <= CURDATE() (si renseignée)
     */
    public function listAchatReady(array $filters = []): array
    {
        $cols = [];
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            $cols = [];
        }

        $parentExpr = 'NULL';
        if (in_array('parent_num_fiche', $cols, true) && in_array('code_parentale', $cols, true)) {
            $parentExpr = 'COALESCE(f.parent_num_fiche, f.code_parentale)';
        } elseif (in_array('parent_num_fiche', $cols, true)) {
            $parentExpr = 'f.parent_num_fiche';
        } elseif (in_array('code_parentale', $cols, true)) {
            $parentExpr = 'f.code_parentale';
        }
        $baseExpr = 'COALESCE(' . $parentExpr . ', f.num_fiche)';

        $where = [
            $this->activeChantierScope('f'),
            'f.conforme = 1',
            'f.approuve = 1',
            'f.etat_fiche = 0',
            'f.decaisse = 0',
            '(f.date_decaissement_minimum IS NULL OR f.date_decaissement_minimum <= CURDATE())'
        ];
        $params = [];
        $multiTextFilter=static function(string $column,string $key,$raw,string $mode)use(&$where,&$params):void{$values=is_array($raw)?$raw:explode(',',(string)$raw);$values=array_values(array_filter(array_map('trim',$values)));if(!$values)return;$parts=[];foreach($values as $i=>$value){$param=$key.'_'.$i;$parts[]=$column.' LIKE :'.$param;$params[$param]='%'.$value.'%';}$clause='('.implode(' OR ',$parts).')';$where[]=($mode==='exclude'?'NOT ':'').$clause;};
        $multiTextFilter('f.beficiaire_fiche','beneficiaire',$filters['beneficiaire']??[],(string)($filters['beneficiaire_mode']??'include'));
        $multiTextFilter('f.designation_fiche','designation',$filters['designation']??[],(string)($filters['designation_mode']??'include'));
        if (!empty($filters['montant_min'])) {
            $where[] = 'f.montant_fiche >= :montant_min';
            $params['montant_min'] = (float)$filters['montant_min'];
        }
        if (!empty($filters['montant_max'])) {
            $where[] = 'f.montant_fiche <= :montant_max';
            $params['montant_max'] = (float)$filters['montant_max'];
        }
        if (!empty($filters['date_min'])) {
            $where[] = 'DATE(f.date_creat_fiche) >= :date_min';
            $params['date_min'] = $filters['date_min'];
        }
        if (!empty($filters['date_max'])) {
            $where[] = 'DATE(f.date_creat_fiche) <= :date_max';
            $params['date_max'] = $filters['date_max'];
        }
        if (!empty($filters['chantier'])) {$values=is_array($filters['chantier'])?$filters['chantier']:explode(',',(string)$filters['chantier']);$parts=[];foreach(array_values(array_filter(array_map('trim',$values))) as $i=>$value){$param='chantier_'.$i;$parts[]='(c.titre LIKE :'.$param.' OR c.code LIKE :'.$param.')';$params[$param]='%'.$value.'%';}if($parts)$where[]=((string)($filters['chantier_mode']??'include')==='exclude'?'NOT ':'').'('.implode(' OR ',$parts).')';}
        if (!empty($filters['affectation'])) {$values=is_array($filters['affectation'])?$filters['affectation']:explode(',',(string)$filters['affectation']);$map=['chantier'=>'f.chantier_id IS NOT NULL','bureau'=>'f.serv_bureau_banamur_id IS NOT NULL AND f.chantier_id IS NULL','autre'=>'f.chantier_id IS NULL AND f.serv_bureau_banamur_id IS NULL'];$parts=[];foreach($values as $value)if(isset($map[trim($value)]))$parts[]='('.$map[trim($value)].')';if($parts)$where[]=((string)($filters['affectation_mode']??'include')==='exclude'?'NOT ':'').'('.implode(' OR ',$parts).')';}

        $hasBonCommandeTable = false;
        try {
            $hasBonCommandeTable = (bool)$this->pdo->query("SHOW TABLES LIKE 'bon_commande'")->fetchColumn();
        } catch (\Throwable $e) {
            $hasBonCommandeTable = false;
        }
        $hasBonReceptionTable = false;
        try {
            $hasBonReceptionTable = (bool)$this->pdo->query("SHOW TABLES LIKE 'bon_reception'")->fetchColumn();
        } catch (\Throwable $e) {
            $hasBonReceptionTable = false;
        }

        $select = 'SELECT f.*, ' . $baseExpr . ' AS workflow_base_num_fiche, c.titre AS chantier_titre, c.code AS chantier_code,
                  ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ldb.montant AS ligne_montant,
                  ld.designation AS devis_designation, db.id AS debourse_id';
        $from = ' FROM fiche_decaissement f
              LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
              LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
              LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
              LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id';

        if ($hasBonCommandeTable) {
            $select .= ', bc.id AS bc_id, bc.bc_number AS bc_number, bc.bc_date AS bc_date';
            $from .= ' LEFT JOIN bon_commande bc ON bc.num_fiche = ' . $baseExpr;
        } else {
            $select .= ', NULL AS bc_id, NULL AS bc_number, NULL AS bc_date';
        }

        if ($hasBonReceptionTable) {
            $select .= ', br.id AS br_id';
            $from .= ' LEFT JOIN bon_reception br ON br.num_fiche = ' . $baseExpr;
        } else {
            $select .= ', NULL AS br_id';
        }

        $sql = $select . $from . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY f.date_creat_fiche DESC';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return array_map('enrich_fiche_labels', $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Valide une fiche (étape achat) : met etat_fiche=1 et trace secur/date
     * Gère automatiquement l'ajout des colonnes date_valide / secur_valide si absentes (migration progressive).
     */
    public function getAchatValidationError(string $num_fiche): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT conforme, approuve, decaisse, etat_fiche, montant_fiche
             FROM fiche_decaissement
             WHERE num_fiche=:num
             LIMIT 1'
        );
        $stmt->execute(['num' => $num_fiche]);
        $fiche = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$fiche) {
            return 'Fiche introuvable.';
        }
        if ((int) $fiche['conforme'] !== 1 || (int) $fiche['approuve'] !== 1) {
            return 'Cette fiche n’est pas encore prête pour la validation achat.';
        }
        if ((int) $fiche['decaisse'] === 1) {
            return 'Cette fiche est déjà décaissée.';
        }
        if ((int) $fiche['etat_fiche'] === 1) {
            return null;
        }

        $amount = (float) $fiche['montant_fiche'];
        $availabilityError = $this->getTreasuryAvailabilityError($amount);
        if ($availabilityError !== null) {
            return $availabilityError;
        }

        try {
            require_once __DIR__ . '/../../services/ManualTreasuryService.php';
            $snapshot = (new \ManualTreasuryService($this->pdo))->dashboard(date('Y-m-d'), date('Y-m-d'));
            $available = max(0.0, (float) ($snapshot['available'] ?? 0));
            $reservedStmt = $this->pdo->query(
                "SELECT COALESCE(SUM(montant_fiche),0)
                 FROM fiche_decaissement
                 WHERE conforme=1
                   AND approuve=1
                   AND etat_fiche=1
                   AND decaisse=0
                   AND (date_decaissement_minimum IS NULL OR date_decaissement_minimum<=CURDATE())"
            );
            $alreadyCommitted = max(0.0, (float) $reservedStmt->fetchColumn());
            $remainingForValidation = max(0.0, $available - $alreadyCommitted);
        } catch (\Throwable $e) {
            return 'Validation achat bloquée : le disponible réel et les fiches déjà engagées ne peuvent pas être contrôlés.';
        }

        if ($amount > $remainingForValidation + 0.001) {
            return sprintf(
                'Validation achat bloquée : %s FCFA restent mobilisables sur %s FCFA disponibles, après %s FCFA déjà engagés par des fiches validées.',
                number_format($remainingForValidation, 0, ',', ' '),
                number_format($available, 0, ',', ' '),
                number_format($alreadyCommitted, 0, ',', ' ')
            );
        }

        return null;
    }

    public function validateAchat(string $num_fiche, string $user, string $ip = '', int $port = 0): bool
    {
        if(!$this->getByNum($num_fiche)) return false;
        if ($this->getAchatValidationError($num_fiche) !== null) return false;
        // Vérifier / créer colonnes workflow achat si absentes
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!in_array('date_valid', $cols, true)) {
                $this->pdo->exec("ALTER TABLE fiche_decaissement ADD COLUMN date_valid DATETIME NULL AFTER date_decaissement");
            }
            if (!in_array('secur_refus', $cols, true)) {
                $this->pdo->exec("ALTER TABLE fiche_decaissement ADD COLUMN secur_refus VARCHAR(100) NULL AFTER secur_valid");
            }
            if (!in_array('date_refuse', $cols, true)) {
                $this->pdo->exec("ALTER TABLE fiche_decaissement ADD COLUMN date_refuse DATETIME NULL AFTER date_valid");
            }
        } catch (\Throwable $e) {
            // Ignorer si absence de droits d'ALTER dans l'environnement; on continuera avec secur_valid existant
        }

        // Conditions préalables: conforme & approuve & non décaisse & non déjà validée
        $st = $this->pdo->prepare("SELECT id_fiche, conforme, approuve, decaisse, etat_fiche, montant_fiche FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE");
        if (!$st->execute(['n' => $num_fiche])) return false;
        $fiche = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fiche) return false;
        if ((int)$fiche['conforme'] !== 1 || (int)$fiche['approuve'] !== 1) return false; // pas encore prête
        if ((int)$fiche['decaisse'] === 1) return false; // déjà décaissée
        if ((int)$fiche['etat_fiche'] === 1) return true; // déjà validée
        if ($this->getTreasuryAvailabilityError((float) $fiche['montant_fiche']) !== null) return false;

        // Mise à jour
        $sql = "UPDATE fiche_decaissement SET etat_fiche=1, secur_valid=:s, date_valid=NOW(), updated_at=NOW() WHERE num_fiche=:n";
        $up = $this->pdo->prepare($sql);
        $ok = $up->execute([
            's' => $user,
            'n' => $num_fiche
        ]);
        if (!$ok) return false;
        $this->syncContractPayment($num_fiche);

        // Trace minimale (table trace_fiche si existe)
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS trace_fiche (
                id INT AUTO_INCREMENT PRIMARY KEY,
                num_fiche VARCHAR(50) NOT NULL,
                action TEXT NOT NULL,
                date_action DATETIME DEFAULT CURRENT_TIMESTAMP,
                utilisateur VARCHAR(100) NULL,
                adresse_ip VARCHAR(45) NULL,
                details TEXT NULL,
                INDEX(num_fiche), INDEX(date_action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $action = 'VALIDATION_ACHAT';
            $adresse = $ip ? ('IP:' . $ip . ' Port:' . $port) : null;
            $ins = $this->pdo->prepare("INSERT INTO trace_fiche (num_fiche, action, utilisateur, adresse_ip, details) VALUES (:n,:a,:u,:ip,:d)");
            $ins->execute([
                'n' => $num_fiche,
                'a' => $action,
                'u' => $user,
                'ip' => $adresse,
                'd' => 'Validation achat réalisée'
            ]);
        } catch (\Throwable $e) {
            // Silencieux
        }
        return true;
    }

    /**
     * Refuse une fiche au stade achat: etat_fiche=2 + secur_refus/date_refuse + motif dans details_demande.
     */
    public function refuseAchat(string $num_fiche, string $user, string $motif, string $ip = '', int $port = 0): bool
    {
        $this->ensureAchatColumns();
        $st = $this->pdo->prepare("SELECT etat_fiche, decaisse, details_demande FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE");
        if (!$st->execute(['n' => $num_fiche])) return false;
        $fiche = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fiche) return false;
        if ((int)$fiche['decaisse'] === 1) return false; // déjà décaissée
        if ((int)$fiche['etat_fiche'] === 2) return true; // déjà refusée
        $details = $fiche['details_demande'] ?? '';
        $tag = '[REFUS_ACHAT ' . date('Y-m-d H:i') . ' ' . $user . '] ' . $motif;
        $details = trim($details . "\n" . $tag);
        $up = $this->pdo->prepare("UPDATE fiche_decaissement SET etat_fiche=2, secur_refus=:s, date_refuse=NOW(), details_demande=:d, updated_at=NOW() WHERE num_fiche=:n");
        $ok = $up->execute(['s' => $user, 'd' => $details, 'n' => $num_fiche]);
        if (!$ok) return false;
        $this->syncContractPayment($num_fiche);
        $this->traceAction($num_fiche, 'REFUS_ACHAT', $user, $ip, $port, $motif);
        return true;
    }

    /** Reporter une fiche (déplacer date minimale de décaissement) */
    public function reporterAchat(string $num_fiche, string $user, string $nouvelleDate, string $ip = '', int $port = 0): bool
    {
        // Validation date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nouvelleDate)) return false;
        if ($nouvelleDate < date('Y-m-d')) return false;
        $this->ensureAchatColumns();
        $st = $this->pdo->prepare("SELECT decaisse FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE");
        $st->execute(['n' => $num_fiche]);
        $fiche = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fiche) return false;
        if ((int)$fiche['decaisse'] === 1) return false;
        $up = $this->pdo->prepare("UPDATE fiche_decaissement SET date_decaissement_minimum=:d, updated_at=NOW() WHERE num_fiche=:n");
        $ok = $up->execute(['d' => $nouvelleDate, 'n' => $num_fiche]);
        if (!$ok) return false;
        $this->traceAction($num_fiche, 'REPORT_ACHAT', $user, $ip, $port, 'Nouvelle date ' . $nouvelleDate);
        return true;
    }

    /** Modifier montant / bénéficiaire avant validation achat (approuvé mais etat_fiche=0) */
    public function modifierAvantValidation(string $num_fiche, string $user, string $benef, float $montant): bool
    {
        $st = $this->pdo->prepare("SELECT decaisse, etat_fiche FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE");
        $st->execute(['n' => $num_fiche]);
        $fiche = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fiche) return false;
        if ((int)$fiche['decaisse'] === 1) return false; // plus modifiable
        if ((int)$fiche['etat_fiche'] !== 0) return false; // déjà validée ou refusée
        $up = $this->pdo->prepare("UPDATE fiche_decaissement SET beficiaire_fiche=:b, montant_fiche=:m, date_last_modif=NOW(), secur_last_modif=:s WHERE num_fiche=:n");
        return $up->execute(['b' => $benef, 'm' => $montant, 's' => $user, 'n' => $num_fiche]);
    }

    private function ensureAchatColumns(): void
    {
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!in_array('date_valid', $cols, true)) {
                $this->pdo->exec("ALTER TABLE fiche_decaissement ADD COLUMN date_valid DATETIME NULL AFTER date_decaissement");
            }
            if (!in_array('secur_refus', $cols, true)) {
                $this->pdo->exec("ALTER TABLE fiche_decaissement ADD COLUMN secur_refus VARCHAR(100) NULL AFTER secur_valid");
            }
            if (!in_array('date_refuse', $cols, true)) {
                $this->pdo->exec("ALTER TABLE fiche_decaissement ADD COLUMN date_refuse DATETIME NULL AFTER date_valid");
            }
        } catch (\Throwable $e) { /* ignore */
        }
    }

    private function ensureTraceTable(): void
    {
        if ($this->pdo->inTransaction()) return;
        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS trace_fiche (
                id INT AUTO_INCREMENT PRIMARY KEY,
                num_fiche VARCHAR(50) NOT NULL,
                action TEXT NOT NULL,
                date_action DATETIME DEFAULT CURRENT_TIMESTAMP,
                utilisateur VARCHAR(100) NULL,
                adresse_ip VARCHAR(45) NULL,
                details TEXT NULL,
                INDEX(num_fiche), INDEX(date_action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $cols = $this->pdo->query("SHOW COLUMNS FROM trace_fiche")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!in_array('details', $cols, true)) {
                $this->pdo->exec("ALTER TABLE trace_fiche ADD COLUMN details TEXT NULL AFTER adresse_ip");
            }
        } catch (\Throwable $e) { /* ignore */
        }
    }

    private function traceAction(string $num, string $action, string $user, string $ip, int $port, string $details = ''): void
    {
        try {
            $this->ensureTraceTable();
            $adresse = $ip ? ('IP:' . $ip . ' Port:' . $port) : null;
            $cols = $this->pdo->query("SHOW COLUMNS FROM trace_fiche")->fetchAll(PDO::FETCH_COLUMN, 0);
            $hasDetails = in_array('details', $cols, true);
            if ($hasDetails) {
                $ins = $this->pdo->prepare("INSERT INTO trace_fiche (num_fiche, action, utilisateur, adresse_ip, details) VALUES (:n,:a,:u,:ip,:d)");
                $ins->execute(['n' => $num, 'a' => $action, 'u' => $user, 'ip' => $adresse, 'd' => $details]);
                return;
            }

            $ins = $this->pdo->prepare("INSERT INTO trace_fiche (num_fiche, action, utilisateur, adresse_ip) VALUES (:n,:a,:u,:ip)");
            $ins->execute(['n' => $num, 'a' => $action, 'u' => $user, 'ip' => $adresse]);
        } catch (\Throwable $e) { /* ignore */
        }
    }

    /* ========================== PHASE COMPTA ============================= */
    private function ensureComptaColumns(): void
    {
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
            $need = [];
            if (!in_array('secur_decaisse', $cols, true)) $need[] = "ADD COLUMN secur_decaisse VARCHAR(100) NULL AFTER secur_valid";
            // Harmonisation: on ajoute date_decaisse (distincte de date_decaissement déjà existante)
            if (!in_array('date_decaisse', $cols, true)) $need[] = "ADD COLUMN date_decaisse DATETIME NULL AFTER secur_decaisse";
            // Politique: possibilité de rendre un décaissement définitif (annulation impossible)
            if (!in_array('decaisse_definitif', $cols, true)) $need[] = "ADD COLUMN decaisse_definitif TINYINT(1) NOT NULL DEFAULT 0 AFTER date_decaisse";
            // Colonne parent : préférer code_parentale, conserver parent_num_fiche si déjà présent
            if (!in_array('parent_num_fiche', $cols, true) && !in_array('code_parentale', $cols, true)) {
                $need[] = "ADD COLUMN code_parentale VARCHAR(50) NULL AFTER num_fiche";
            }
            if (!in_array('montant_initial_fiche', $cols, true)) $need[] = "ADD COLUMN montant_initial_fiche DECIMAL(18,2) NULL AFTER montant_fiche";
            if (!in_array('date_decaissement_minimum', $cols, true)) $need[] = "ADD COLUMN date_decaissement_minimum DATE NULL AFTER date_refuse";
            if ($need) {
                $this->pdo->exec("ALTER TABLE fiche_decaissement " . implode(',', $need));
            }
        } catch (\Throwable $e) { /* ignore */
        }
    }

    private function getDecaisseDateExpr(array $cols): ?string
    {
        if (in_array('date_decaisse', $cols, true)) return 'f.date_decaisse';
        if (in_array('date_decaissement', $cols, true)) return 'f.date_decaissement';
        return null;
    }

    private function getComptaVisibilityWhereClause(array $cols): string
    {
        // Page compta: afficher uniquement les fiches non décaissées OU les fiches décaissées il y a moins de 24h.
        $dateExpr = $this->getDecaisseDateExpr($cols);
        if (!$dateExpr) {
            return '(f.decaisse = 0)';
        }
        $hasDef = in_array('decaisse_definitif', $cols, true);
        // Si définitif: ne pas afficher sur la page compta (objectif: uniquement Point financier)
        if ($hasDef) {
            return "(f.decaisse = 0 OR (f.decaisse = 1 AND {$dateExpr} IS NOT NULL AND {$dateExpr} >= (NOW() - INTERVAL 24 HOUR) AND f.decaisse_definitif = 0))";
        }
        return "(f.decaisse = 0 OR (f.decaisse = 1 AND {$dateExpr} IS NOT NULL AND {$dateExpr} >= (NOW() - INTERVAL 24 HOUR)))";
    }

    /** Liste des fiches prêtes pour la trésorerie (conforme+approuve+etat_fiche=1 + decaisse=0 + date_min atteinte) */
    public function listComptaReady(array $filters = [], ?int $limit = null, ?int $offset = null): array
    {
        $this->ensureComptaColumns();
        $cols = [];
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            $cols = [];
        }

        $parentExpr = 'NULL';
        if (in_array('parent_num_fiche', $cols, true) && in_array('code_parentale', $cols, true)) {
            $parentExpr = 'COALESCE(f.parent_num_fiche, f.code_parentale)';
        } elseif (in_array('parent_num_fiche', $cols, true)) {
            $parentExpr = 'f.parent_num_fiche';
        } elseif (in_array('code_parentale', $cols, true)) {
            $parentExpr = 'f.code_parentale';
        }
        $baseExpr = 'COALESCE(' . $parentExpr . ', f.num_fiche)';

        $hasBonCommandeTable = false;
        try {
            $hasBonCommandeTable = (bool)$this->pdo->query("SHOW TABLES LIKE 'bon_commande'")->fetchColumn();
        } catch (\Throwable $e) {
            $hasBonCommandeTable = false;
        }
        $hasBonReceptionTable = false;
        try {
            $hasBonReceptionTable = (bool)$this->pdo->query("SHOW TABLES LIKE 'bon_reception'")->fetchColumn();
        } catch (\Throwable $e) {
            $hasBonReceptionTable = false;
        }

        // La trésorerie (liste "prêtes") ne doit voir que les fiches réellement à décaisser.
        // IMPORTANT: une fiche mère peut être décaissée (partiellement) sans que ses héritières le soient.
        // Les parents partiels restent consultables via mode=all + filtre status_filter=partiels_parents.
        $decaisseClause = 'f.decaisse = 0';
        $where = [
            $this->activeChantierScope('f'),
            'f.conforme = 1',
            'f.approuve = 1',
            $decaisseClause,
            '(f.date_decaissement_minimum IS NULL OR f.date_decaissement_minimum <= CURDATE())',
            'f.etat_fiche = 1',
            $this->getComptaVisibilityWhereClause($cols)
        ];
        $params = [];
        if (!empty($filters['beneficiaire'])) {
            $where[] = 'f.beficiaire_fiche LIKE :benef';
            $params['benef'] = '%' . $filters['beneficiaire'] . '%';
        }
        if (!empty($filters['num_fiche'])) {
            $where[] = 'f.num_fiche LIKE :nf';
            $params['nf'] = '%' . $filters['num_fiche'] . '%';
        }
        if (!empty($filters['designation'])) {
            $where[] = 'f.designation_fiche LIKE :des';
            $params['des'] = '%' . $filters['designation'] . '%';
        }
        if (!empty($filters['precision'])) {
            $where[] = '(f.precision_fiche LIKE :prec)';
            $params['prec'] = '%' . $filters['precision'] . '%';
        }
        if (!empty($filters['montant_min'])) {
            $where[] = 'f.montant_fiche >= :mmin';
            $params['mmin'] = (float)$filters['montant_min'];
        }
        if (!empty($filters['montant_max'])) {
            $where[] = 'f.montant_fiche <= :mmax';
            $params['mmax'] = (float)$filters['montant_max'];
        }
        if (!empty($filters['date_min'])) {
            $where[] = 'DATE(f.date_creat_fiche) >= :dmin';
            $params['dmin'] = $filters['date_min'];
        }
        if (!empty($filters['date_max'])) {
            $where[] = 'DATE(f.date_creat_fiche) <= :dmax';
            $params['dmax'] = $filters['date_max'];
        }
        if (!empty($filters['date_valid_min'])) {
            $where[] = 'DATE(f.date_valid) >= :dvmin';
            $params['dvmin'] = $filters['date_valid_min'];
        }
        if (!empty($filters['date_valid_max'])) {
            $where[] = 'DATE(f.date_valid) <= :dvmax';
            $params['dvmax'] = $filters['date_valid_max'];
        }
        if (!empty($filters['chantier'])) {
            $where[] = '(c.titre LIKE :chantier OR c.code LIKE :chantier)';
            $params['chantier'] = '%' . $filters['chantier'] . '%';
        }
        if (!empty($filters['entreprise'])) {
            $where[] = 'f.entreprise = :ent';
            $params['ent'] = $filters['entreprise'];
        }
        if (!empty($filters['ligne'])) {
            $where[] = 'f.ligne_debourse_id = :ligne';
            $params['ligne'] = (int)$filters['ligne'];
        }
        if (!empty($filters['affectation'])) {
            if ($filters['affectation'] === 'chantier') {
                $where[] = 'f.chantier_id IS NOT NULL';
            } elseif ($filters['affectation'] === 'bureau') {
                $where[] = 'f.serv_bureau_banamur_id IS NOT NULL AND f.chantier_id IS NULL';
            } elseif ($filters['affectation'] === 'autre') {
                $where[] = 'f.chantier_id IS NULL AND f.serv_bureau_banamur_id IS NULL';
            }
        }
        $sql = "SELECT f.*,\n" .
            "    " . $parentExpr . " AS parent_code,\n" .
            "    " . $baseExpr . " AS workflow_base_num_fiche,\n" .
            "    c.titre AS chantier_titre, c.code AS chantier_code,\n" .
            ($hasBonCommandeTable ? "    bc.id AS bc_id, bc.bc_number AS bc_number, bc.bc_date AS bc_date,\n" : "    NULL AS bc_id, NULL AS bc_number, NULL AS bc_date,\n") .
            ($hasBonReceptionTable ? "    br.id AS br_id,\n" : "    NULL AS br_id,\n") .
            "    (SELECT t.action FROM trace_fiche t WHERE t.num_fiche COLLATE utf8mb4_unicode_ci = f.num_fiche COLLATE utf8mb4_unicode_ci ORDER BY t.id DESC LIMIT 1) AS last_action,\n" .
            "    (SELECT t.date_action FROM trace_fiche t WHERE t.num_fiche COLLATE utf8mb4_unicode_ci = f.num_fiche COLLATE utf8mb4_unicode_ci ORDER BY t.id DESC LIMIT 1) AS last_action_date,\n" .
            "    '' AS last_action_details\n" .
            "FROM fiche_decaissement f\n" .
            "LEFT JOIN chantier_banamur c ON c.id = f.chantier_id\n" .
            ($hasBonCommandeTable ? ("LEFT JOIN bon_commande bc ON bc.num_fiche = " . $baseExpr . "\n") : '') .
            ($hasBonReceptionTable ? ("LEFT JOIN bon_reception br ON br.num_fiche = " . $baseExpr . "\n") : '') .
            "WHERE " . implode(' AND ', $where) . "\n" .
            "ORDER BY f.date_creat_fiche DESC";
        if ($limit !== null) {
            $l = max(1, (int)$limit);
            $o = max(0, (int)($offset ?? 0));
            $sql .= " LIMIT {$l} OFFSET {$o}";
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Liste brute de toutes les fiches ("fiches réelles") pour la comptabilité sans restreindre aux seules fiches prêtes.
     * Permet d'inclure aussi celles déjà décaissées, non encore conformes/approuvées, refusées, etc.
     * Un filtre status_filter supplémentaire est interprété ainsi (si fourni):
     *  - reporte : date_decaissement_minimum > CURDATE()
     *  - pre_achat : etat_fiche=0
     *  - decaissees : decaisse=1
     *  - en_attente : decaisse=0
     */
    public function listComptaAll(array $filters = [], ?int $limit = null, ?int $offset = null): array
    {
        $this->ensureComptaColumns();
        $cols = [];
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            $cols = [];
        }

        $parentExpr = 'NULL';
        if (in_array('parent_num_fiche', $cols, true) && in_array('code_parentale', $cols, true)) {
            $parentExpr = 'COALESCE(f.parent_num_fiche, f.code_parentale)';
        } elseif (in_array('parent_num_fiche', $cols, true)) {
            $parentExpr = 'f.parent_num_fiche';
        } elseif (in_array('code_parentale', $cols, true)) {
            $parentExpr = 'f.code_parentale';
        }
        $baseExpr = 'COALESCE(' . $parentExpr . ', f.num_fiche)';

        $hasBonCommandeTable = false;
        try {
            $hasBonCommandeTable = (bool)$this->pdo->query("SHOW TABLES LIKE 'bon_commande'")->fetchColumn();
        } catch (\Throwable $e) {
            $hasBonCommandeTable = false;
        }
        $hasBonReceptionTable = false;
        try {
            $hasBonReceptionTable = (bool)$this->pdo->query("SHOW TABLES LIKE 'bon_reception'")->fetchColumn();
        } catch (\Throwable $e) {
            $hasBonReceptionTable = false;
        }

        $where = ['1=1', $this->activeChantierScope('f'), $this->getComptaVisibilityWhereClause($cols)];
        $params = [];
        if (!empty($filters['beneficiaire'])) {
            $where[] = 'f.beficiaire_fiche LIKE :benef';
            $params['benef'] = '%' . $filters['beneficiaire'] . '%';
        }
        if (!empty($filters['num_fiche'])) {
            $where[] = 'f.num_fiche LIKE :nf';
            $params['nf'] = '%' . $filters['num_fiche'] . '%';
        }
        if (!empty($filters['designation'])) {
            $where[] = 'f.designation_fiche LIKE :des';
            $params['des'] = '%' . $filters['designation'] . '%';
        }
        if (!empty($filters['precision'])) {
            $where[] = 'f.precision_fiche LIKE :prec';
            $params['prec'] = '%' . $filters['precision'] . '%';
        }
        if (!empty($filters['montant_min'])) {
            $where[] = 'f.montant_fiche >= :mmin';
            $params['mmin'] = (float)$filters['montant_min'];
        }
        if (!empty($filters['montant_max'])) {
            $where[] = 'f.montant_fiche <= :mmax';
            $params['mmax'] = (float)$filters['montant_max'];
        }
        if (!empty($filters['date_min'])) {
            $where[] = 'DATE(f.date_creat_fiche) >= :dmin';
            $params['dmin'] = $filters['date_min'];
        }
        if (!empty($filters['date_max'])) {
            $where[] = 'DATE(f.date_creat_fiche) <= :dmax';
            $params['dmax'] = $filters['date_max'];
        }
        if (!empty($filters['date_valid_min'])) {
            $where[] = 'DATE(f.date_valid) >= :dvmin';
            $params['dvmin'] = $filters['date_valid_min'];
        }
        if (!empty($filters['date_valid_max'])) {
            $where[] = 'DATE(f.date_valid) <= :dvmax';
            $params['dvmax'] = $filters['date_valid_max'];
        }
        if (!empty($filters['chantier'])) {
            $where[] = '(c.titre LIKE :chantier OR c.code LIKE :chantier)';
            $params['chantier'] = '%' . $filters['chantier'] . '%';
        }
        if (!empty($filters['entreprise'])) {
            $where[] = 'f.entreprise = :ent';
            $params['ent'] = $filters['entreprise'];
        }
        if (!empty($filters['ligne'])) {
            $where[] = 'f.ligne_debourse_id = :ligne';
            $params['ligne'] = (int)$filters['ligne'];
        }
        if (!empty($filters['affectation'])) {
            if ($filters['affectation'] === 'chantier') {
                $where[] = 'f.chantier_id IS NOT NULL';
            } elseif ($filters['affectation'] === 'bureau') {
                $where[] = 'f.serv_bureau_banamur_id IS NOT NULL AND f.chantier_id IS NULL';
            } elseif ($filters['affectation'] === 'autre') {
                $where[] = 'f.chantier_id IS NULL AND f.serv_bureau_banamur_id IS NULL';
            }
        }
        if (!empty($filters['status_filter'])) {
            switch ($filters['status_filter']) {
                case 'reporte':
                    $where[] = 'f.date_decaissement_minimum IS NOT NULL AND f.date_decaissement_minimum > CURDATE()';
                    break;
                case 'pre_achat':
                    $where[] = 'f.etat_fiche = 0';
                    break;
                case 'decaissees':
                    $where[] = 'f.decaisse = 1';
                    break;
                case 'en_attente':
                    $where[] = 'f.decaisse = 0';
                    break;
                case 'partiels_parents':
                    // Parents partiellement décaissés: decaisse=1 et montant_initial_fiche>montant_fiche
                    $where[] = '(f.decaisse = 1 AND f.montant_initial_fiche IS NOT NULL AND f.montant_initial_fiche > f.montant_fiche)';
                    break;
            }
        }
        $sql = "SELECT f.*,\n" .
            "    " . $parentExpr . " AS parent_code,\n" .
            "    " . $baseExpr . " AS workflow_base_num_fiche,\n" .
            "    c.titre AS chantier_titre, c.code AS chantier_code,\n" .
            ($hasBonCommandeTable ? "    bc.id AS bc_id, bc.bc_number AS bc_number, bc.bc_date AS bc_date,\n" : "    NULL AS bc_id, NULL AS bc_number, NULL AS bc_date,\n") .
            ($hasBonReceptionTable ? "    br.id AS br_id,\n" : "    NULL AS br_id,\n") .
            "    (SELECT t.action FROM trace_fiche t WHERE t.num_fiche COLLATE utf8mb4_unicode_ci = f.num_fiche COLLATE utf8mb4_unicode_ci ORDER BY t.id DESC LIMIT 1) AS last_action,\n" .
            "    (SELECT t.date_action FROM trace_fiche t WHERE t.num_fiche COLLATE utf8mb4_unicode_ci = f.num_fiche COLLATE utf8mb4_unicode_ci ORDER BY t.id DESC LIMIT 1) AS last_action_date,\n" .
            "    '' AS last_action_details\n" .
            "FROM fiche_decaissement f\n" .
            "LEFT JOIN chantier_banamur c ON c.id = f.chantier_id\n" .
            ($hasBonCommandeTable ? ("LEFT JOIN bon_commande bc ON bc.num_fiche = " . $baseExpr . "\n") : '') .
            ($hasBonReceptionTable ? ("LEFT JOIN bon_reception br ON br.num_fiche = " . $baseExpr . "\n") : '') .
            "WHERE " . implode(' AND ', $where) . "\n" .
            "ORDER BY f.date_creat_fiche DESC";
        if ($limit !== null) {
            $l = max(1, (int)$limit);
            $o = max(0, (int)($offset ?? 0));
            $sql .= " LIMIT {$l} OFFSET {$o}";
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function summaryComptaReady(array $filters = []): array
    {
        $this->ensureComptaColumns();
        $cols = [];
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            $cols = [];
        }
        // Même règle que listComptaReady(): "prêtes" = uniquement non décaissées.
        $decaisseClause = 'f.decaisse = 0';
        $where = [
            $this->activeChantierScope('f'),
            'f.conforme = 1',
            'f.approuve = 1',
            $decaisseClause,
            '(f.date_decaissement_minimum IS NULL OR f.date_decaissement_minimum <= CURDATE())',
            'f.etat_fiche = 1',
            $this->getComptaVisibilityWhereClause($cols)
        ];
        $params = [];
        if (!empty($filters['beneficiaire'])) {
            $where[] = 'f.beficiaire_fiche LIKE :benef';
            $params['benef'] = '%' . $filters['beneficiaire'] . '%';
        }
        if (!empty($filters['num_fiche'])) {
            $where[] = 'f.num_fiche LIKE :nf';
            $params['nf'] = '%' . $filters['num_fiche'] . '%';
        }
        if (!empty($filters['designation'])) {
            $where[] = 'f.designation_fiche LIKE :des';
            $params['des'] = '%' . $filters['designation'] . '%';
        }
        if (!empty($filters['precision'])) {
            $where[] = '(f.precision_fiche LIKE :prec)';
            $params['prec'] = '%' . $filters['precision'] . '%';
        }
        if (!empty($filters['montant_min'])) {
            $where[] = 'f.montant_fiche >= :mmin';
            $params['mmin'] = (float)$filters['montant_min'];
        }
        if (!empty($filters['montant_max'])) {
            $where[] = 'f.montant_fiche <= :mmax';
            $params['mmax'] = (float)$filters['montant_max'];
        }
        if (!empty($filters['date_min'])) {
            $where[] = 'DATE(f.date_creat_fiche) >= :dmin';
            $params['dmin'] = $filters['date_min'];
        }
        if (!empty($filters['date_max'])) {
            $where[] = 'DATE(f.date_creat_fiche) <= :dmax';
            $params['dmax'] = $filters['date_max'];
        }
        if (!empty($filters['date_valid_min'])) {
            $where[] = 'DATE(f.date_valid) >= :dvmin';
            $params['dvmin'] = $filters['date_valid_min'];
        }
        if (!empty($filters['date_valid_max'])) {
            $where[] = 'DATE(f.date_valid) <= :dvmax';
            $params['dvmax'] = $filters['date_valid_max'];
        }
        $join = "";
        if (!empty($filters['chantier'])) {
            $join = "LEFT JOIN chantier_banamur c ON c.id = f.chantier_id\n";
            $where[] = '(c.titre LIKE :chantier OR c.code LIKE :chantier)';
            $params['chantier'] = '%' . $filters['chantier'] . '%';
        }
        if (!empty($filters['entreprise'])) {
            $where[] = 'f.entreprise = :ent';
            $params['ent'] = $filters['entreprise'];
        }
        if (!empty($filters['ligne'])) {
            $where[] = 'f.ligne_debourse_id = :ligne';
            $params['ligne'] = (int)$filters['ligne'];
        }
        if (!empty($filters['affectation'])) {
            if ($filters['affectation'] === 'chantier') {
                $where[] = 'f.chantier_id IS NOT NULL';
            } elseif ($filters['affectation'] === 'bureau') {
                $where[] = 'f.serv_bureau_banamur_id IS NOT NULL AND f.chantier_id IS NULL';
            } elseif ($filters['affectation'] === 'autre') {
                $where[] = 'f.chantier_id IS NULL AND f.serv_bureau_banamur_id IS NULL';
            }
        }

        $sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(f.montant_fiche),0) AS total\n" .
            "FROM fiche_decaissement f\n" .
            $join .
            "WHERE " . implode(' AND ', $where);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['count' => (int)($row['cnt'] ?? 0), 'total' => (float)($row['total'] ?? 0)];
    }

    /**
     * Marque en "définitif" toutes les fiches déjà décaissées sur un périmètre (chantier/bénéficiaire/date/entreprise).
     * Utilisé pour le bulk "définitif" afin de verrouiller l'annulation sur tout un chantier.
     */
    public function markDecaissementsDefinitifsScope(array $filters = []): int
    {
        $this->ensureComptaColumns();
        $cols = [];
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            $cols = [];
        }

        if (!in_array('decaisse_definitif', $cols, true)) {
            throw new RuntimeException("La colonne 'decaisse_definitif' est absente (droits ALTER TABLE manquants ?)");
        }

        $where = [
            'f.decaisse = 1',
            'f.decaisse_definitif = 0',
        ];
        $params = [];

        $join = '';
        if (!empty($filters['chantier'])) {
            $join = "LEFT JOIN chantier_banamur c ON c.id = f.chantier_id\n";
            $where[] = '(c.titre LIKE :chantier OR c.code LIKE :chantier)';
            $params['chantier'] = '%' . $filters['chantier'] . '%';
        }
        if (!empty($filters['beneficiaire'])) {
            $where[] = 'f.beficiaire_fiche LIKE :benef';
            $params['benef'] = '%' . $filters['beneficiaire'] . '%';
        }
        if (!empty($filters['date_min'])) {
            $where[] = 'DATE(f.date_creat_fiche) >= :dmin';
            $params['dmin'] = $filters['date_min'];
        }
        if (!empty($filters['date_max'])) {
            $where[] = 'DATE(f.date_creat_fiche) <= :dmax';
            $params['dmax'] = $filters['date_max'];
        }
        if (!empty($filters['entreprise'])) {
            $where[] = 'f.entreprise = :ent';
            $params['ent'] = $filters['entreprise'];
        }

        $sql = "UPDATE fiche_decaissement f\n" .
            $join .
            "SET f.decaisse_definitif = 1\n" .
            "WHERE " . implode(' AND ', $where);

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return (int)$st->rowCount();
    }

    public function summaryComptaAll(array $filters = []): array
    {
        $this->ensureComptaColumns();
        $cols = [];
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            $cols = [];
        }
        $where = ['1=1', $this->activeChantierScope('f'), $this->getComptaVisibilityWhereClause($cols)];
        $params = [];
        if (!empty($filters['beneficiaire'])) {
            $where[] = 'f.beficiaire_fiche LIKE :benef';
            $params['benef'] = '%' . $filters['beneficiaire'] . '%';
        }
        if (!empty($filters['num_fiche'])) {
            $where[] = 'f.num_fiche LIKE :nf';
            $params['nf'] = '%' . $filters['num_fiche'] . '%';
        }
        if (!empty($filters['designation'])) {
            $where[] = 'f.designation_fiche LIKE :des';
            $params['des'] = '%' . $filters['designation'] . '%';
        }
        if (!empty($filters['precision'])) {
            $where[] = 'f.precision_fiche LIKE :prec';
            $params['prec'] = '%' . $filters['precision'] . '%';
        }
        if (!empty($filters['montant_min'])) {
            $where[] = 'f.montant_fiche >= :mmin';
            $params['mmin'] = (float)$filters['montant_min'];
        }
        if (!empty($filters['montant_max'])) {
            $where[] = 'f.montant_fiche <= :mmax';
            $params['mmax'] = (float)$filters['montant_max'];
        }
        if (!empty($filters['date_min'])) {
            $where[] = 'DATE(f.date_creat_fiche) >= :dmin';
            $params['dmin'] = $filters['date_min'];
        }
        if (!empty($filters['date_max'])) {
            $where[] = 'DATE(f.date_creat_fiche) <= :dmax';
            $params['dmax'] = $filters['date_max'];
        }
        if (!empty($filters['date_valid_min'])) {
            $where[] = 'DATE(f.date_valid) >= :dvmin';
            $params['dvmin'] = $filters['date_valid_min'];
        }
        if (!empty($filters['date_valid_max'])) {
            $where[] = 'DATE(f.date_valid) <= :dvmax';
            $params['dvmax'] = $filters['date_valid_max'];
        }
        $join = '';
        if (!empty($filters['chantier'])) {
            $join = "LEFT JOIN chantier_banamur c ON c.id = f.chantier_id\n";
            $where[] = '(c.titre LIKE :chantier OR c.code LIKE :chantier)';
            $params['chantier'] = '%' . $filters['chantier'] . '%';
        }
        if (!empty($filters['entreprise'])) {
            $where[] = 'f.entreprise = :ent';
            $params['ent'] = $filters['entreprise'];
        }
        if (!empty($filters['ligne'])) {
            $where[] = 'f.ligne_debourse_id = :ligne';
            $params['ligne'] = (int)$filters['ligne'];
        }
        if (!empty($filters['affectation'])) {
            if ($filters['affectation'] === 'chantier') {
                $where[] = 'f.chantier_id IS NOT NULL';
            } elseif ($filters['affectation'] === 'bureau') {
                $where[] = 'f.serv_bureau_banamur_id IS NOT NULL AND f.chantier_id IS NULL';
            } elseif ($filters['affectation'] === 'autre') {
                $where[] = 'f.chantier_id IS NULL AND f.serv_bureau_banamur_id IS NULL';
            }
        }
        if (!empty($filters['status_filter'])) {
            switch ($filters['status_filter']) {
                case 'reporte':
                    $where[] = 'f.date_decaissement_minimum IS NOT NULL AND f.date_decaissement_minimum > CURDATE()';
                    break;
                case 'pre_achat':
                    $where[] = 'f.etat_fiche = 0';
                    break;
                case 'decaissees':
                    $where[] = 'f.decaisse = 1';
                    break;
                case 'en_attente':
                    $where[] = 'f.decaisse = 0';
                    break;
                case 'partiels_parents':
                    $where[] = '(f.decaisse = 1 AND f.montant_initial_fiche IS NOT NULL AND f.montant_initial_fiche > f.montant_fiche)';
                    break;
            }
        }

        $sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(f.montant_fiche),0) AS total\n" .
            "FROM fiche_decaissement f\n" .
            $join .
            "WHERE " . implode(' AND ', $where);
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['count' => (int)($row['cnt'] ?? 0), 'total' => (float)($row['total'] ?? 0)];
    }

    /** Décaissement complet */
    private function getTreasuryAvailabilityError(float $amount): ?string
    {
        if ($amount <= 0) {
            return 'Montant de décaissement invalide.';
        }

        try {
            require_once __DIR__ . '/../../services/ManualTreasuryService.php';
            $snapshot = (new \ManualTreasuryService($this->pdo))->dashboard(date('Y-m-d'), date('Y-m-d'));
            $available = max(0.0, (float) ($snapshot['available'] ?? 0));
        } catch (\Throwable $e) {
            return 'Décaissement bloqué : le disponible réel du jour ne peut pas être contrôlé.';
        }

        if ($amount > $available + 0.001) {
            return sprintf(
                'Décaissement bloqué : %s FCFA demandés pour seulement %s FCFA disponibles aujourd’hui. Aucun solde négatif n’est autorisé.',
                number_format($amount, 0, ',', ' '),
                number_format($available, 0, ',', ' ')
            );
        }

        return null;
    }

    private function withPaymentLock(callable $operation): mixed
    {
        if ($this->pdo->inTransaction()) throw new LogicException('Le décaissement doit démarrer hors transaction.');
        $lock = $this->pdo->query("SELECT GET_LOCK('finea_treasury_disbursement', 5)");
        if ((int)$lock->fetchColumn() !== 1) throw new RuntimeException('Un décaissement est en cours. Réessayez dans quelques instants.');
        try { return $operation(); }
        finally { $this->pdo->query("SELECT RELEASE_LOCK('finea_treasury_disbursement')"); }
    }

    public function decaisserCompta(string $num_fiche, string $user, string $ip = '', int $port = 0, bool $definitif = false): bool
    {
        $ok=$this->withPaymentLock(fn() => $this->executeTotalPayment($num_fiche, $user, $ip, $port, $definitif));
        if ($ok) {
            try {
                require_once __DIR__.'/../../services/RhFinancialSettlementService.php';
                (new RhFinancialSettlementService($this->pdo))->sync($num_fiche,'paid');
                require_once __DIR__.'/../../services/DisbursementBeneficiaryNotificationService.php';
                (new DisbursementBeneficiaryNotificationService($this->pdo))->sync($num_fiche,'paid');
            } catch (Throwable $e) { error_log('RH financial settlement pending for '.$num_fiche.': '.$e->getMessage()); }
        }
        return $ok;
    }

    private function executeTotalPayment(string $num_fiche, string $user, string $ip = '', int $port = 0, bool $definitif = false): bool
    {
        if(!$this->getByNum($num_fiche)) return false;
        $this->ensureComptaColumns();
        $cols = [];
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            $cols = [];
        }
        $this->ensureTraceTable();
        require_once __DIR__ . '/BonReception.php';
        new BonReception($this->pdo);
        require_once __DIR__ . '/../../services/ManualTreasuryService.php';
        new \ManualTreasuryService($this->pdo);
        FinancialWorkflowProgress::begin($this->pdo);
        try {
        $hasDef = in_array('decaisse_definitif', $cols, true);
        $select = 'SELECT * FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE';
        $st = $this->pdo->prepare($select);
        $st->execute(['n' => $num_fiche]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) { FinancialWorkflowProgress::rollback($this->pdo); return false; }
        if ((int)$f['decaisse'] === 1) { FinancialWorkflowProgress::commit($this->pdo); return true; } // déjà fait
        $readyError = $this->getComptaReadinessError($f, true);
        if ($readyError !== null) { FinancialWorkflowProgress::rollback($this->pdo); return false; }
        if ($this->getTreasuryAvailabilityError((float) $f['montant_fiche']) !== null) { FinancialWorkflowProgress::rollback($this->pdo); return false; }
        if ($hasDef) {
            $up = $this->pdo->prepare('UPDATE fiche_decaissement SET decaisse=1, etat_fiche=1, secur_decaisse=:s, date_decaisse=NOW(), decaisse_definitif=:d, updated_at=NOW() WHERE num_fiche=:n');
            $ok = $up->execute(['s' => $user, 'n' => $num_fiche, 'd' => $definitif ? 1 : 0]);
        } else {
            $up = $this->pdo->prepare('UPDATE fiche_decaissement SET decaisse=1, etat_fiche=1, secur_decaisse=:s, date_decaisse=NOW(), updated_at=NOW() WHERE num_fiche=:n');
            $ok = $up->execute(['s' => $user, 'n' => $num_fiche]);
        }
        if ($ok){$this->syncContractPayment($num_fiche);$this->traceAction($num_fiche, $definitif ? 'DECAISSEMENT_TOTAL_DEFINITIF' : 'DECAISSEMENT_TOTAL', $user, $ip, $port, $definitif ? 'Décaissement total (définitif)' : 'Décaissement total');}
        if (!$this->pdo->inTransaction()) throw new RuntimeException('Transaction de décaissement interrompue.');
        FinancialWorkflowProgress::commit($this->pdo);
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) FinancialWorkflowProgress::rollback($this->pdo);
            throw $e;
        }
        if ($ok) {
            try {
                require_once __DIR__ . '/../../services/FuelLogisticsService.php';
                (new \FuelLogisticsService($this->pdo))->registerDecaissedFicheConsumption($num_fiche, (int)($_SESSION['user_id'] ?? 0));
            } catch (\Throwable $e) {
            }
            try {
                require_once __DIR__ . '/../../model/DisbursementRegularizationManager.php';
                (new \DisbursementRegularizationManager($this->pdo))->openAfterDisbursement($num_fiche);
            } catch (\Throwable $e) {
            }
        }
        return $ok;
    }

    public function getDecaissementTotalError(string $num_fiche): ?string
    {
        $this->ensureComptaColumns();
        $fiche = $this->getByNum($num_fiche);
        $readinessError = $this->getComptaReadinessError($fiche, true);
        if ($readinessError !== null) {
            return $readinessError;
        }

        return $this->getTreasuryAvailabilityError((float) ($fiche['montant_fiche'] ?? 0));
    }

    /** Reporter (mise à jour date décaissement mini) */
    public function reporterCompta(string $num_fiche, string $user, string $date, string $ip = '', int $port = 0): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        if ($date < date('Y-m-d')) return false;
        $this->pdo->exec('ALTER TABLE fiche_decaissement ADD COLUMN IF NOT EXISTS feb_request_id INT NULL AFTER code_autorisation_feb');
        $st = $this->pdo->prepare('SELECT decaisse, feb_request_id, code_autorisation_feb FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE');
        $st->execute(['n' => $num_fiche]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return false;
        if ((int)$f['decaisse'] === 1) return false;
        $bci = trim((string) ($f['code_autorisation_feb'] ?? ''));
        $febRequestId = (int) ($f['feb_request_id'] ?? 0);
        if ($febRequestId > 0) {
            require_once __DIR__ . '/../../model/EmployeeLegalRequestManager.php';
            $result = (new \EmployeeLegalRequestManager($this->pdo))->generateFebBciForScheduledDisbursement($febRequestId, $date, [
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'name' => $user,
            ]);
            $bci = trim((string) ($result['code'] ?? $bci));
            if ($bci === '') return false;
        }
        $up = $this->pdo->prepare('UPDATE fiche_decaissement SET date_decaissement_minimum=:d, code_autorisation_feb=COALESCE(NULLIF(:bci, ""), code_autorisation_feb), updated_at=NOW() WHERE num_fiche=:n');
        $ok = $up->execute(['d' => $date, 'bci' => $bci, 'n' => $num_fiche]);
        if ($ok) $this->traceAction($num_fiche, 'REPORT_COMPTA', $user, $ip, $port, 'Nouvelle date ' . $date);
        return $ok;
    }

    /** Annuler un décaissement et renvoyer la fiche au cycle approbation (remettre approuve=0, conforme inchangé) */
    public function annulerDecaissementRetourApprobation(string $num_fiche, string $user, string $motif = '', string $ip = '', int $port = 0): bool
    {
        $this->ensureComptaColumns();
        $cols = [];
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            $cols = [];
        }
        $dateExpr = $this->getDecaisseDateExpr($cols);
        $hasDef = in_array('decaisse_definitif', $cols, true);
        $select = 'SELECT decaisse' . ($dateExpr ? (', ' . $dateExpr . ' AS date_decaisse_any') : '') . ($hasDef ? ', decaisse_definitif' : '') . ' FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE';
        $st = $this->pdo->prepare($select);
        FinancialWorkflowProgress::begin($this->pdo);
        try {
            $st->execute(['n' => $num_fiche]);
            $f = $st->fetch(PDO::FETCH_ASSOC);
            if (!$f) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return false;
            }
            // On autorise annulation seulement si déjà décaissée
            if ((int)$f['decaisse'] !== 1) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return false;
            }
            // Politique: si décaissement définitif -> annulation impossible
            if ($hasDef && (int)($f['decaisse_definitif'] ?? 0) === 1) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return false;
            }
            // Politique: annulation seulement dans les 24h après date_decaisse
            $dateDecaisse = $f['date_decaisse_any'] ?? null;
            if (empty($dateDecaisse)) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return false;
            }
            $cutoff = $this->pdo->query("SELECT (NOW() - INTERVAL 24 HOUR) AS dt")->fetch(PDO::FETCH_ASSOC);
            $dtCut = $cutoff['dt'] ?? null;
            if ($dtCut && $dateDecaisse < $dtCut) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return false;
            }
            if ($hasDef) {
                $up = $this->pdo->prepare('UPDATE fiche_decaissement SET decaisse=0, date_decaisse=NULL, secur_decaisse=NULL, decaisse_definitif=0, approuve=0, etat_fiche=0, updated_at=NOW() WHERE num_fiche=:n');
                $ok = $up->execute(['n' => $num_fiche]);
            } else {
                $up = $this->pdo->prepare('UPDATE fiche_decaissement SET decaisse=0, date_decaisse=NULL, secur_decaisse=NULL, approuve=0, etat_fiche=0, updated_at=NOW() WHERE num_fiche=:n');
                $ok = $up->execute(['n' => $num_fiche]);
            }
            if ($ok) {
                $this->traceAction($num_fiche, 'ANNULATION_DECAISSEMENT', $user, $ip, $port, 'Retour approbation ' . ($motif ? ('Motif: ' . $motif) : ''));
                FinancialWorkflowProgress::commit($this->pdo);
                return true;
            }
            FinancialWorkflowProgress::rollback($this->pdo);
            return false;
        } catch (\Throwable $e) {
            FinancialWorkflowProgress::rollback($this->pdo);
            throw $e;
        }
    }

    public function getAnnulationDecaissementError(string $num_fiche): ?string
    {
        $this->ensureComptaColumns();
        $cols = [];
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (\Throwable $e) {
            $cols = [];
        }
        $dateExpr = $this->getDecaisseDateExpr($cols);
        $hasDef = in_array('decaisse_definitif', $cols, true);
        $select = 'SELECT decaisse' . ($dateExpr ? (', ' . $dateExpr . ' AS date_decaisse_any') : '') . ($hasDef ? ', decaisse_definitif' : '') . ' FROM fiche_decaissement WHERE num_fiche=:n';
        $st = $this->pdo->prepare($select);
        $st->execute(['n' => $num_fiche]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) return 'Fiche introuvable';
        if ((int)($f['decaisse'] ?? 0) !== 1) return 'Fiche non décaissée';
        if ($hasDef && (int)($f['decaisse_definitif'] ?? 0) === 1) return 'Décaissement définitif: annulation impossible';
        $dateDecaisse = $f['date_decaisse_any'] ?? null;
        if (empty($dateDecaisse)) return 'Date décaissement inconnue';
        $cutoff = $this->pdo->query("SELECT (NOW() - INTERVAL 24 HOUR) AS dt")->fetch(PDO::FETCH_ASSOC);
        $dtCut = $cutoff['dt'] ?? null;
        if ($dtCut && $dateDecaisse < $dtCut) return 'Délai dépassé: annulation possible uniquement dans les 24h';
        return null;
    }

    /** Décaissement partiel : transforme la fiche en part décaisse, crée une héritière pour le reste. */
    private function isWorkflowRefused(array $row): bool
    {
        return (int)($row['approuve'] ?? 0) === 2 || (int)($row['etat_fiche'] ?? 0) === 2;
    }

    private function resolveWorkflowStageCode(array $row): string
    {
        if ((int)($row['decaisse'] ?? 0) === 1) {
            return 'decaissee';
        }
        if ($this->isWorkflowRefused($row)) {
            return 'refusee';
        }
        if ((int)($row['etat_fiche'] ?? 0) === 1) {
            return 'tresorerie';
        }
        if ((int)($row['approuve'] ?? 0) === 1) {
            return 'validation_achat';
        }
        if ((int)($row['conforme'] ?? 0) === 1) {
            return 'approbation';
        }
        return 'conformite';
    }

    private function resolveWorkflowStageLabel(string $stage): string
    {
        $map = [
            'conformite' => 'Conformité',
            'approbation' => 'Approbation',
            'validation_achat' => 'Validation achat',
            'tresorerie' => 'Traitement comptable',
            'refusee' => 'Refusée',
            'decaissee' => 'Décaissée',
        ];

        return $map[$stage] ?? ucfirst(str_replace('_', ' ', $stage));
    }

    public function adminMoveToStage(string $num_fiche, string $targetStage, string $user, string $reason = '', string $ip = '', int $port = 0): array
    {
        $this->ensureAchatColumns();
        $this->ensureComptaColumns();
        $this->ensureTraceTable();

        $targetStage = strtolower(trim($targetStage));
        $allowedStages = ['conformite', 'approbation', 'validation_achat', 'tresorerie', 'decaissee'];
        if (!in_array($targetStage, $allowedStages, true)) {
            return ['ok' => false, 'error' => 'Étape cible invalide'];
        }

        FinancialWorkflowProgress::begin($this->pdo);
        try {
            $st = $this->pdo->prepare('SELECT * FROM fiche_decaissement WHERE num_fiche = :n FOR UPDATE');
            $st->execute(['n' => $num_fiche]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return ['ok' => false, 'error' => 'Fiche introuvable'];
            }

            $cols = $this->pdo->query('SHOW COLUMNS FROM fiche_decaissement')->fetchAll(PDO::FETCH_COLUMN, 0);
            $hasColumn = static function (string $column) use ($cols): bool {
                return in_array($column, $cols, true);
            };

            $setSql = [];
            $params = ['n' => $num_fiche];

            $assignValue = static function (string $column, $value) use (&$setSql, &$params, $hasColumn): void {
                if (!$hasColumn($column)) {
                    return;
                }
                $placeholder = 'p_' . $column;
                $setSql[$column] = $column . ' = :' . $placeholder;
                $params[$placeholder] = $value;
            };

            $assignNull = static function (string $column) use (&$setSql, $hasColumn): void {
                if (!$hasColumn($column)) {
                    return;
                }
                $setSql[$column] = $column . ' = NULL';
            };

            $assignExpr = static function (string $column, string $expression) use (&$setSql, $hasColumn): void {
                if (!$hasColumn($column)) {
                    return;
                }
                $setSql[$column] = $column . ' = ' . $expression;
            };

            $clearDownstream = static function () use ($assignNull): void {
                $assignNull('date_approbation');
                $assignNull('date_valid');
                $assignNull('date_refuse');
                $assignNull('date_decaisse');
                $assignNull('secur_valid');
                $assignNull('secur_refus');
                $assignNull('secur_decaisse');
            };

            $beforeStage = $this->resolveWorkflowStageCode($row);

            switch ($targetStage) {
                case 'conformite':
                    $assignValue('conforme', 0);
                    $assignValue('approuve', 0);
                    $assignValue('etat_fiche', 0);
                    $assignValue('decaisse', 0);
                    $assignValue('decaisse_definitif', 0);
                    $assignNull('date_conforme');
                    $assignNull('secur_conforme');
                    $clearDownstream();
                    $assignNull('date_decaissement_minimum');
                    break;

                case 'approbation':
                    $assignValue('conforme', 1);
                    $assignValue('approuve', 0);
                    $assignValue('etat_fiche', 0);
                    $assignValue('decaisse', 0);
                    $assignValue('decaisse_definitif', 0);
                    if (empty($row['date_conforme'])) {
                        $assignExpr('date_conforme', 'NOW()');
                    }
                    if (empty($row['secur_conforme'])) {
                        $assignValue('secur_conforme', $user);
                    }
                    $clearDownstream();
                    $assignNull('date_decaissement_minimum');
                    break;

                case 'validation_achat':
                    $assignValue('conforme', 1);
                    $assignValue('approuve', 1);
                    $assignValue('etat_fiche', 0);
                    $assignValue('decaisse', 0);
                    $assignValue('decaisse_definitif', 0);
                    if (empty($row['date_conforme'])) {
                        $assignExpr('date_conforme', 'NOW()');
                    }
                    if (empty($row['secur_conforme'])) {
                        $assignValue('secur_conforme', $user);
                    }
                    if (empty($row['date_approbation'])) {
                        $assignExpr('date_approbation', 'NOW()');
                    }
                    if (empty($row['secur_valid'])) {
                        $assignValue('secur_valid', $user);
                    }
                    $assignNull('date_valid');
                    $assignNull('date_refuse');
                    $assignNull('date_decaisse');
                    $assignNull('secur_refus');
                    $assignNull('secur_decaisse');
                    $assignExpr('date_decaissement_minimum', 'CURDATE()');
                    break;

                case 'tresorerie':
                    $assignValue('conforme', 1);
                    $assignValue('approuve', 1);
                    $assignValue('etat_fiche', 1);
                    $assignValue('decaisse', 0);
                    $assignValue('decaisse_definitif', 0);
                    if (empty($row['date_conforme'])) {
                        $assignExpr('date_conforme', 'NOW()');
                    }
                    if (empty($row['secur_conforme'])) {
                        $assignValue('secur_conforme', $user);
                    }
                    if (empty($row['date_approbation'])) {
                        $assignExpr('date_approbation', 'NOW()');
                    }
                    if (empty($row['secur_valid'])) {
                        $assignValue('secur_valid', $user);
                    }
                    if (empty($row['date_valid'])) {
                        $assignExpr('date_valid', 'NOW()');
                    }
                    $assignNull('date_refuse');
                    $assignNull('date_decaisse');
                    $assignNull('secur_refus');
                    $assignNull('secur_decaisse');
                    $assignExpr('date_decaissement_minimum', 'CURDATE()');
                    break;

                case 'decaissee':
                    $assignValue('conforme', 1);
                    $assignValue('approuve', 1);
                    $assignValue('etat_fiche', 1);
                    $assignValue('decaisse', 1);
                    if (empty($row['date_conforme'])) {
                        $assignExpr('date_conforme', 'NOW()');
                    }
                    if (empty($row['secur_conforme'])) {
                        $assignValue('secur_conforme', $user);
                    }
                    if (empty($row['date_approbation'])) {
                        $assignExpr('date_approbation', 'NOW()');
                    }
                    if (empty($row['secur_valid'])) {
                        $assignValue('secur_valid', $user);
                    }
                    if (empty($row['date_valid'])) {
                        $assignExpr('date_valid', 'NOW()');
                    }
                    if (empty($row['date_decaisse'])) {
                        $assignExpr('date_decaisse', 'NOW()');
                    }
                    if (empty($row['secur_decaisse'])) {
                        $assignValue('secur_decaisse', $user);
                    }
                    $assignNull('date_refuse');
                    $assignNull('secur_refus');
                    $assignExpr('date_decaissement_minimum', 'CURDATE()');
                    break;
            }

            $setSql['updated_at'] = 'updated_at = NOW()';
            $sql = 'UPDATE fiche_decaissement SET ' . implode(', ', array_values($setSql)) . ' WHERE num_fiche = :n';
            $up = $this->pdo->prepare($sql);
            $up->execute($params);

            $traceDetails = 'Étape: ' . $this->resolveWorkflowStageLabel($beforeStage) . ' -> ' . $this->resolveWorkflowStageLabel($targetStage);
            if ($reason !== '') {
                $traceDetails .= ' | Motif admin: ' . $reason;
            }
            $this->traceAction($num_fiche, 'ADMIN_WORKFLOW_STAGE_CHANGE', $user, $ip, $port, $traceDetails);

            FinancialWorkflowProgress::commit($this->pdo);
            return [
                'ok' => true,
                'before_stage' => $beforeStage,
                'after_stage' => $targetStage,
                'before_label' => $this->resolveWorkflowStageLabel($beforeStage),
                'after_label' => $this->resolveWorkflowStageLabel($targetStage),
            ];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                FinancialWorkflowProgress::rollback($this->pdo);
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function adminConvertDecaissementToPartial(string $num_fiche, float $montantDecaisse, string $nextDate, string $user, string $reason = '', string $ip = '', int $port = 0): array
    {
        $this->ensureComptaColumns();
        $this->ensureTraceTable();
        if ($montantDecaisse <= 0) {
            return ['ok' => false, 'error' => 'Montant partiel invalide'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextDate)) {
            return ['ok' => false, 'error' => 'Date de reprise invalide'];
        }

        FinancialWorkflowProgress::begin($this->pdo);
        try {
            $st = $this->pdo->prepare('SELECT * FROM fiche_decaissement WHERE num_fiche = :n FOR UPDATE');
            $st->execute(['n' => $num_fiche]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return ['ok' => false, 'error' => 'Fiche introuvable'];
            }
            if ((int)($row['decaisse'] ?? 0) !== 1) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return ['ok' => false, 'error' => 'La fiche doit déjà être décaissée pour être rebasculée en partiel'];
            }

            $cols = $this->pdo->query('SHOW COLUMNS FROM fiche_decaissement')->fetchAll(PDO::FETCH_COLUMN, 0);
            $parentCol = in_array('parent_num_fiche', $cols, true) ? 'parent_num_fiche' : (in_array('code_parentale', $cols, true) ? 'code_parentale' : null);
            if ($parentCol === null) {
                throw new \RuntimeException('Aucune colonne parent disponible');
            }

            $childCountStmt = $this->pdo->prepare("SELECT COUNT(*) FROM fiche_decaissement WHERE {$parentCol} = :parent");
            $childCountStmt->execute(['parent' => $num_fiche]);
            if ((int)$childCountStmt->fetchColumn() > 0) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return ['ok' => false, 'error' => 'Cette fiche possède déjà une héritière. Conversion total → partiel impossible.'];
            }

            $montantTotal = (float)($row['montant_fiche'] ?? 0);
            if ($montantTotal <= 0 || $montantDecaisse >= $montantTotal) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return ['ok' => false, 'error' => 'Le montant payé doit être strictement inférieur au montant décaissé actuel'];
            }
            $reste = $montantTotal - $montantDecaisse;

            if (!isset($row['montant_initial_fiche']) || $row['montant_initial_fiche'] === '' || $row['montant_initial_fiche'] === null) {
                $this->pdo->prepare('UPDATE fiche_decaissement SET montant_initial_fiche = :mi WHERE id_fiche = :id')->execute([
                    'mi' => $montantTotal,
                    'id' => $row['id_fiche'],
                ]);
            }

            $parentUpdate = $this->pdo->prepare('UPDATE fiche_decaissement SET montant_fiche = :m, updated_at = NOW() WHERE num_fiche = :n');
            $parentUpdate->execute([
                'm' => $montantDecaisse,
                'n' => $num_fiche,
            ]);

            $childCountStmt->execute(['parent' => $num_fiche]);
            $seq = (int)$childCountStmt->fetchColumn() + 1;
            $childNum = $num_fiche . '-P' . $seq;

            $hasDetails = in_array('details_demande', $cols, true);
            $insertColumns = [
                'num_fiche',
                $parentCol,
                'beficiaire_fiche',
                'photo_beneficiaire',
                'montant_fiche',
                'precision_fiche',
                'designation_fiche',
                'chantier_id',
                'serv_bureau_banamur_id',
                'ligne_debourse_id',
                'conforme',
                'approuve',
                'etat_fiche',
                'decaisse',
                'date_decaissement_minimum',
                'date_creat_fiche',
                'montant_initial_fiche',
            ];
            $insertValues = [
                ':num',
                ':parent',
                ':benef',
                ':photo',
                ':montant',
                ':prec',
                ':desig',
                ':chantier',
                ':bureau',
                ':ligne',
                ':conforme',
                ':approuve',
                '0',
                '0',
                ':date_min',
                'NOW()',
                ':mi',
            ];
            if ($hasDetails) {
                $insertColumns[] = 'details_demande';
                $insertValues[] = ':details';
            }
            if (in_array('code_autorisation_feb', $cols, true)) {
                $insertColumns[] = 'code_autorisation_feb';
                $insertValues[] = ':code_feb';
            }

            $insertSql = 'INSERT INTO fiche_decaissement (' . implode(',', $insertColumns) . ') VALUES (' . implode(',', $insertValues) . ')';
            $ins = $this->pdo->prepare($insertSql);
            $payload = [
                'num' => $childNum,
                'parent' => $num_fiche,
                'benef' => $row['beficiaire_fiche'] ?? null,
                'photo' => $row['photo_beneficiaire'] ?? null,
                'montant' => $reste,
                'prec' => $row['precision_fiche'] ?? null,
                'desig' => $row['designation_fiche'] ?? null,
                'chantier' => $row['chantier_id'] ?? null,
                'bureau' => $row['serv_bureau_banamur_id'] ?? null,
                'ligne' => $row['ligne_debourse_id'] ?? null,
                'conforme' => 0,
                'approuve' => 0,
                'date_min' => $nextDate,
                'mi' => $montantTotal,
            ];
            if ($hasDetails) {
                $payload['details'] = $row['details_demande'] ?? null;
            }
            if (in_array('code_autorisation_feb', $cols, true)) {
                $payload['code_feb'] = $row['code_autorisation_feb'] ?? null;
            }
            $ins->execute($payload);

            $detail = 'Parent reduit a ' . $montantDecaisse . ' | reliquat ' . $reste . ' | enfant ' . $childNum . ' remis en conformite au ' . $nextDate;
            if ($reason !== '') {
                $detail .= ' | Motif admin: ' . $reason;
            }
            $this->traceAction($num_fiche, 'ADMIN_DECAISSEMENT_TOTAL_VERS_PARTIEL', $user, $ip, $port, $detail);
            $this->traceAction($childNum, 'CREATION_ENFANT_PARTIEL_ADMIN', $user, $ip, $port, 'Parent ' . $num_fiche . ($reason !== '' ? ' | Motif admin: ' . $reason : ''));

            FinancialWorkflowProgress::commit($this->pdo);
            try {
                require_once __DIR__ . '/../../model/DisbursementRegularizationManager.php';
                (new \DisbursementRegularizationManager($this->pdo))->openAfterDisbursement($num_fiche);
            } catch (\Throwable $e) {
            }
            return ['ok' => true, 'child' => $childNum, 'reste' => $reste];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                FinancialWorkflowProgress::rollback($this->pdo);
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function decaisserPartiel(string $num_fiche, float $montantDecaisse, string $nextDate, string $user, string $ip = '', int $port = 0, array $options = []): array
    {
        require_once __DIR__.'/../../services/RhFinancialSettlementService.php';
        if (RhFinancialSettlementService::isRhFiche($this->pdo,$this->getByNum($num_fiche) ?: [])) return ['ok'=>false,'error'=>'Un point RH se décaisse intégralement pour respecter les montants individuels signés. Faites réviser le point avant paiement.'];
        return $this->withPaymentLock(fn() => $this->executePartialPayment($num_fiche, $montantDecaisse, $nextDate, $user, $ip, $port, $options));
    }

    private function executePartialPayment(string $num_fiche, float $montantDecaisse, string $nextDate, string $user, string $ip = '', int $port = 0, array $options = []): array
    {
        if(!$this->getByNum($num_fiche)) throw new RuntimeException('Fiche indisponible ou chantier archivé.');
        $this->ensureComptaColumns();
        $this->ensureFebCashflowColumns();
        $this->ensureTraceTable();
        if (!is_finite($montantDecaisse) || $montantDecaisse <= 0) return ['ok' => false, 'error' => 'Montant partiel invalide'];
        if (!\FebWorkflowGuard::validDate($nextDate)) return ['ok' => false, 'error' => 'Date suivante invalide'];
        require_once __DIR__ . '/BonReception.php';
        new BonReception($this->pdo);
        require_once __DIR__ . '/../../services/ManualTreasuryService.php';
        new \ManualTreasuryService($this->pdo);
        FinancialWorkflowProgress::begin($this->pdo);
        try {
            $st = $this->pdo->prepare('SELECT * FROM fiche_decaissement WHERE num_fiche=:n FOR UPDATE');
            $st->execute(['n' => $num_fiche]);
            $f = $st->fetch(PDO::FETCH_ASSOC);
            if (!$f) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return ['ok' => false, 'error' => 'Fiche introuvable'];
            }
            $readyError = $this->getComptaReadinessError($f, false, $montantDecaisse);
            if ($readyError !== null) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return ['ok' => false, 'error' => $readyError];
            }
            $treasuryError = $this->getTreasuryAvailabilityError($montantDecaisse);
            if ($treasuryError !== null) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return ['ok' => false, 'error' => $treasuryError];
            }
            $policy = $this->getFebCashflowPolicyForFiche($f);
            if (($policy['cashflow_need'] ?? '') === 'provisional_disbursement') {
                $alreadyDisbursed = $this->getWorkflowAlreadyDisbursedAmount($num_fiche);
                $provisionalCap = (float)($policy['provisional_amount'] ?? 0);
                $totalFeb = (float)($policy['total_amount'] ?? 0);
                if ($alreadyDisbursed <= 0.01) {
                    if ($provisionalCap > 0 && $montantDecaisse > ($provisionalCap + 0.01)) {
                        FinancialWorkflowProgress::rollback($this->pdo);
                        return ['ok' => false, 'error' => 'Le premier decaissement provisoire ne peut pas depasser ' . number_format($provisionalCap, 0, ',', ' ') . ' FCFA.'];
                    }
                } else {
                    $progressRate = (float)($options['progress_rate'] ?? 0);
                    $pvReference = trim((string)($options['pv_reception_reference'] ?? ''));
                    $validatorOne = trim((string)($options['pv_validator_one'] ?? ''));
                    $validatorTwo = trim((string)($options['pv_validator_two'] ?? ''));
                    if ($progressRate <= 0 || $progressRate > 100) {
                        FinancialWorkflowProgress::rollback($this->pdo);
                        return ['ok' => false, 'error' => 'Taux d avancement obligatoire entre 1 et 100% a partir du deuxieme decaissement.'];
                    }
                    if ($pvReference === '') {
                        FinancialWorkflowProgress::rollback($this->pdo);
                        return ['ok' => false, 'error' => 'PV de reception obligatoire a partir du deuxieme decaissement.'];
                    }
                    if ($validatorOne === '' || $validatorTwo === '' || strcasecmp($validatorOne, $validatorTwo) === 0) {
                        FinancialWorkflowProgress::rollback($this->pdo);
                        return ['ok' => false, 'error' => 'Deux validateurs distincts doivent attester le PV de reception.'];
                    }
                    // Le montant issu de l'avancement est désormais une aide à
                    // la décision. Direction et comptabilité conservent la main
                    // sur le montant effectivement retenu.
                }
            }
            $montantTotal = (float)$f['montant_fiche'];
            if ($montantDecaisse >= $montantTotal) {
                FinancialWorkflowProgress::rollback($this->pdo);
                return ['ok' => false, 'error' => 'Montant partiel doit être < montant fiche'];
            }
            $reste = $montantTotal - $montantDecaisse;
            // Mise à jour parent (on conserve montant_initial_fiche si non défini)
            if (!isset($f['montant_initial_fiche']) || $f['montant_initial_fiche'] === '' || $f['montant_initial_fiche'] === null) {
                $this->pdo->prepare('UPDATE fiche_decaissement SET montant_initial_fiche=:mi WHERE id_fiche=:id')->execute(['mi' => $montantTotal, 'id' => $f['id_fiche']]);
            }
            $up = $this->pdo->prepare('UPDATE fiche_decaissement SET montant_fiche=:m, decaisse=1, etat_fiche=1, secur_decaisse=:s, date_decaisse=NOW(), updated_at=NOW() WHERE num_fiche=:n');
            $up->execute(['m' => $montantDecaisse, 's' => $user, 'n' => $num_fiche]);
            if (!empty($options['pv_reception_reference']) || !empty($options['progress_rate'])) {
                $meta = $this->pdo->prepare('UPDATE fiche_decaissement SET pv_reception_reference=:pv, pv_validator_one=:v1, pv_validator_two=:v2, progress_rate=:progress WHERE num_fiche=:n');
                $meta->execute([
                    'pv' => trim((string)($options['pv_reception_reference'] ?? '')),
                    'v1' => trim((string)($options['pv_validator_one'] ?? '')),
                    'v2' => trim((string)($options['pv_validator_two'] ?? '')),
                    'progress' => (float)($options['progress_rate'] ?? 0),
                    'n' => $num_fiche,
                ]);
            }
            // Détermination colonne parent utilisée
            $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
            $parentCol = in_array('parent_num_fiche', $cols, true) ? 'parent_num_fiche' : (in_array('code_parentale', $cols, true) ? 'code_parentale' : null);
            if (!$parentCol) {
                throw new \RuntimeException('Aucune colonne parent disponible');
            }
            // Génération numéro enfant + comptage existants
            $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM fiche_decaissement WHERE $parentCol = :p");
            $countStmt->execute(['p' => $num_fiche]);
            $seq = (int)$countStmt->fetchColumn() + 1;
            $childNum = $num_fiche . '-P' . $seq;
            // Copie
            // Ajout de la copie de la photo bénéficiaire dans l'enfant (demande utilisateur)
            $ins = $this->pdo->prepare("INSERT INTO fiche_decaissement (num_fiche,parent_num_fiche,code_parentale,beficiaire_fiche,tel_beneficiaire_fiche,photo_beneficiaire,personnel_id,fournisseur_id,mode_paiement,details_demande,created_by_user_id,feb_request_id,montant_fiche,precision_fiche,designation_fiche,chantier_id,serv_bureau_banamur_id,ligne_debourse_id,code_autorisation_feb,contract_id,contract_payment_id,contract_payment_type,contract_progress_rate,contract_pv_path,contract_fne_path,conforme,approuve,etat_fiche,decaisse,date_decaissement_minimum,date_creat_fiche,date_last_modif,secur_last_modif,montant_initial_fiche) VALUES (:num,:parent,:parent_code,:benef,:phone,:photo,:personnel,:supplier,:payment_mode,:details,:created_by,:feb_request,:montant,:prec,:desig,:chantier,:bureau,:ligne,:code_feb,:contract_id,:contract_payment_id,:contract_payment_type,:contract_progress_rate,:contract_pv_path,:contract_fne_path,0,0,0,0,:date_min,NOW(),NOW(),:modified_by,:mi)");
            $ins->execute([
                'num' => $childNum,
                'parent' => $num_fiche,
                'parent_code' => $num_fiche,
                'benef' => $f['beficiaire_fiche'],
                'phone' => (string)($f['tel_beneficiaire_fiche'] ?? ''),
                'photo' => $f['photo_beneficiaire'] ?? null,
                'personnel' => !empty($f['personnel_id']) ? (int)$f['personnel_id'] : null,
                'supplier' => !empty($f['fournisseur_id']) ? (int)$f['fournisseur_id'] : null,
                'payment_mode' => $f['mode_paiement'] ?? null,
                'details' => $f['details_demande'] ?? null,
                'created_by' => !empty($f['created_by_user_id']) ? (int)$f['created_by_user_id'] : null,
                'feb_request' => !empty($f['feb_request_id']) ? (int)$f['feb_request_id'] : null,
                'montant' => $reste,
                'prec' => $f['precision_fiche'],
                'desig' => $f['designation_fiche'],
                'chantier' => $f['chantier_id'],
                'bureau' => $f['serv_bureau_banamur_id'],
                'ligne' => $f['ligne_debourse_id'],
                'code_feb' => $f['code_autorisation_feb'] ?? null,
                'contract_id' => !empty($f['contract_id']) ? (int)$f['contract_id'] : null,
                'contract_payment_id' => !empty($f['contract_payment_id']) ? (int)$f['contract_payment_id'] : null,
                'contract_payment_type' => $f['contract_payment_type'] ?? null,
                'contract_progress_rate' => $f['contract_progress_rate'] ?? null,
                'contract_pv_path' => $f['contract_pv_path'] ?? null,
                'contract_fne_path' => $f['contract_fne_path'] ?? null,
                'date_min' => $nextDate,
                'modified_by' => $user,
                'mi' => $montantTotal
            ]);
            $this->traceAction($num_fiche, 'DECAISSEMENT_PARTIEL_PARENT', $user, $ip, $port, 'Partiel: ' . $montantDecaisse . ' reste ' . $reste . ' enfant ' . $childNum);
            $this->traceAction($childNum, 'CREATION_ENFANT_PARTIEL', $user, $ip, $port, 'Parent ' . $num_fiche . ' | reliquat renvoye en conformite');
            $this->syncContractPayment($num_fiche);
            if ($this->pdo->inTransaction()) {
                FinancialWorkflowProgress::commit($this->pdo);
            }
            try {
                require_once __DIR__ . '/../../model/DisbursementRegularizationManager.php';
                (new \DisbursementRegularizationManager($this->pdo))->openAfterDisbursement($num_fiche);
            } catch (\Throwable $e) {
            }
            return ['ok' => true, 'child' => $childNum, 'reste' => $reste];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                FinancialWorkflowProgress::rollback($this->pdo);
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function getComptaReadinessError(?array $fiche, bool $allowAlreadyDecaisse, ?float $paymentAmount = null): ?string
    {
        FinancialWorkflowProgress::step('Vérification des conditions de paiement, dates et approbations.');
        if (!$fiche) {
            return 'Fiche introuvable';
        }
        try {
            require_once __DIR__.'/../../services/RhFinancialSettlementService.php';
            RhFinancialSettlementService::assertFiche($this->pdo,$fiche);
        } catch (DomainException $e) { return $e->getMessage(); }
        if ((int)($fiche['decaisse'] ?? 0) === 1) {
            return $allowAlreadyDecaisse ? null : 'Déjà décaissée';
        }
        try {
            require_once __DIR__.'/../../services/SupplierPaymentGuard.php';
            SupplierPaymentGuard::assertFiche($this->pdo,$fiche);
            require_once __DIR__.'/../../services/DailyPointPaymentPolicy.php';
            DailyPointPaymentPolicy::assertPayment($this->pdo, $fiche, $paymentAmount ?? (float)$fiche['montant_fiche']);
        } catch (DomainException $e) { return $e->getMessage(); }
        catch (Throwable $e) { error_log('Supplier payment identity check unavailable'); return 'Décaissement bloqué : contrôle fournisseur indisponible. Réessayez après régularisation.'; }
        if (!empty($fiche['date_decaissement_minimum']) && $fiche['date_decaissement_minimum'] > date('Y-m-d')) {
            return 'Date minimale de décaissement non atteinte';
        }
        if ((int)($fiche['conforme'] ?? 0) !== 1 || (int)($fiche['approuve'] ?? 0) !== 1) {
            return 'Fiche non conforme ou non approuvée';
        }
        if (!in_array((int)($fiche['etat_fiche'] ?? 0), [0, 1], true)) {
            return 'Fiche non prête pour décaissement';
        }

        // Règle métier: si un Bon de commande existe, un Bon de réception signé est obligatoire avant décaissement.
        $num = trim((string)($fiche['num_fiche'] ?? ''));
        if ($num !== '') {
            $baseNum = trim((string)($fiche['parent_num_fiche'] ?? ($fiche['code_parentale'] ?? $num)));
            if ($baseNum === '') {
                $baseNum = $num;
            }

            $hasBc = false;
            try {
                $stmt = $this->pdo->prepare('SELECT 1 FROM bon_commande WHERE num_fiche = :n LIMIT 1');
                $stmt->execute(['n' => $baseNum]);
                $hasBc = (bool)$stmt->fetchColumn();
            } catch (\Throwable $e) {
                $hasBc = false;
            }

            if ($hasBc) {
                require_once __DIR__ . '/BonReception.php';
                try {
                    $br = new BonReception($this->pdo);
                    if (!$br->isReady()) {
                        return 'Bon de réception signé requis avant décaissement (module indisponible).';
                    }
                    $receipt = $br->getByNumFiche($baseNum);
                    if (!$receipt) {
                        return 'Bon de réception signé manquant. Uploader : bon_reception.php?num_fiche=' . $baseNum;
                    }
                } catch (\Throwable $e) {
                    return 'Bon de réception signé requis avant décaissement.';
                }
            }
        }
        return null;
    }

    private function getParentValue(array $row): ?string
    {
        $parent = trim((string)($row['parent_num_fiche'] ?? ''));
        if ($parent !== '') {
            return $parent;
        }
        $parent = trim((string)($row['code_parentale'] ?? ''));
        return $parent !== '' ? $parent : null;
    }

    private function getParentExpression(array $cols, string $alias = ''): ?string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        if (in_array('parent_num_fiche', $cols, true) && in_array('code_parentale', $cols, true)) {
            return 'COALESCE(' . $prefix . 'parent_num_fiche, ' . $prefix . 'code_parentale)';
        }
        if (in_array('parent_num_fiche', $cols, true)) {
            return $prefix . 'parent_num_fiche';
        }
        if (in_array('code_parentale', $cols, true)) {
            return $prefix . 'code_parentale';
        }
        return null;
    }

    private function resolveWorkflowRootNum(string $num): ?string
    {
        $current = $this->getByNum($num);
        if (!$current) {
            return null;
        }

        $seen = [];
        while ($current) {
            $currentNum = trim((string)($current['num_fiche'] ?? ''));
            if ($currentNum === '' || isset($seen[$currentNum])) {
                break;
            }
            $seen[$currentNum] = true;

            $parentNum = $this->getParentValue($current);
            if ($parentNum === null) {
                return $currentNum;
            }

            $parent = $this->getByNum($parentNum);
            if (!$parent) {
                return $currentNum;
            }
            $current = $parent;
        }

        return trim((string)($current['num_fiche'] ?? $num)) ?: $num;
    }

    private function getWorkflowFamilyRows(string $num): array
    {
        $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        $parentExpr = $this->getParentExpression($cols);
        $root = $this->resolveWorkflowRootNum($num);
        if ($root === null) {
            return ['root' => null, 'items' => []];
        }

        $rootFiche = $this->getByNum($root);
        if (!$rootFiche) {
            return ['root' => null, 'items' => []];
        }

        if ($parentExpr === null) {
            return ['root' => $root, 'items' => [$rootFiche]];
        }

        $family = [$root => $rootFiche];
        $queue = [$root];
        $childrenStmt = $this->pdo->prepare("SELECT * FROM fiche_decaissement WHERE {$parentExpr} = :parent ORDER BY date_creat_fiche ASC, id_fiche ASC");

        while ($queue) {
            $current = array_shift($queue);
            $childrenStmt->execute(['parent' => $current]);
            $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($children as $child) {
                $childNum = trim((string)($child['num_fiche'] ?? ''));
                if ($childNum === '' || isset($family[$childNum])) {
                    continue;
                }
                $family[$childNum] = $child;
                $queue[] = $childNum;
            }
        }

        $items = array_values($family);
        usort($items, static function (array $left, array $right): int {
            $leftDate = (string)($left['date_creat_fiche'] ?? '');
            $rightDate = (string)($right['date_creat_fiche'] ?? '');
            if ($leftDate === $rightDate) {
                return strcmp((string)($left['num_fiche'] ?? ''), (string)($right['num_fiche'] ?? ''));
            }
            return strcmp($leftDate, $rightDate);
        });

        return ['root' => $root, 'items' => $items];
    }

    private function getWorkflowStageLabel(array $row): string
    {
        if ((int)($row['decaisse'] ?? 0) === 1) {
            return 'Décaissée';
        }
        if ($this->isWorkflowRefused($row)) {
            return 'Refusée';
        }
        $etat = (int)($row['etat_fiche'] ?? 0);
        if ($etat === 1) {
            return 'Validée achat';
        }
        return 'Pré-achat';
    }

    private function buildSyntheticWorkflowTraces(array $items): array
    {
        $events = [];
        foreach ($items as $row) {
            $num = trim((string)($row['num_fiche'] ?? ''));
            if ($num === '') {
                continue;
            }

            $createdAt = trim((string)($row['date_creat_fiche'] ?? ''));
            if ($createdAt !== '') {
                $events[] = [
                    'num_fiche' => $num,
                    'action' => 'CREATION_FICHE',
                    'utilisateur' => trim((string)($row['secur_ajout'] ?? $row['user_create'] ?? 'Système')),
                    'date_action' => $createdAt,
                    'details' => 'Création de la fiche workflow',
                    'is_synthetic' => true,
                ];
            }

            $dateConforme = trim((string)($row['date_conforme'] ?? ''));
            if ((int)($row['conforme'] ?? 0) === 1 && $dateConforme !== '') {
                $events[] = [
                    'num_fiche' => $num,
                    'action' => 'CERTIFICATION_CONFORME',
                    'utilisateur' => trim((string)($row['secur_conforme'] ?? 'Système')),
                    'date_action' => $dateConforme,
                    'details' => 'Fiche certifiée conforme',
                    'is_synthetic' => true,
                ];
            }

            $dateValid = trim((string)($row['date_valid'] ?? ''));
            if ((int)($row['etat_fiche'] ?? 0) === 1 && $dateValid !== '') {
                $events[] = [
                    'num_fiche' => $num,
                    'action' => 'VALIDATION_ACHAT',
                    'utilisateur' => trim((string)($row['secur_valid'] ?? 'Système')),
                    'date_action' => $dateValid,
                    'details' => 'Validation achat réalisée',
                    'is_synthetic' => true,
                ];
            }

            $dateRefuse = trim((string)($row['date_refuse'] ?? ''));
            if ($this->isWorkflowRefused($row) && $dateRefuse !== '') {
                $events[] = [
                    'num_fiche' => $num,
                    'action' => 'REFUS_ACHAT',
                    'utilisateur' => trim((string)($row['secur_refus'] ?? $row['secur_valid'] ?? 'Système')),
                    'date_action' => $dateRefuse,
                    'details' => 'Fiche refusée au stade achat',
                    'is_synthetic' => true,
                ];
            }

            $dateDecaisse = trim((string)($row['date_decaisse'] ?? $row['date_decaissement'] ?? ''));
            if ((int)($row['decaisse'] ?? 0) === 1 && $dateDecaisse !== '') {
                $events[] = [
                    'num_fiche' => $num,
                    'action' => 'DECAISSEMENT',
                    'utilisateur' => trim((string)($row['secur_decaisse'] ?? 'Système')),
                    'date_action' => $dateDecaisse,
                    'details' => 'Fiche décaissée',
                    'is_synthetic' => true,
                ];
            }

            $dateMin = trim((string)($row['date_decaissement_minimum'] ?? ''));
            if ($dateMin !== '') {
                $events[] = [
                    'num_fiche' => $num,
                    'action' => 'REPORT_PLANIFIE',
                    'utilisateur' => 'Système',
                    'date_action' => $dateMin . ' 00:00:00',
                    'details' => 'Date minimale de décaissement fixée au ' . $dateMin,
                    'is_synthetic' => true,
                ];
            }
        }

        usort($events, static function (array $left, array $right): int {
            $cmp = strcmp((string)($right['date_action'] ?? ''), (string)($left['date_action'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string)($right['num_fiche'] ?? ''), (string)($left['num_fiche'] ?? ''));
        });

        return $events;
    }

    private function getTraceSemanticType(string $action): string
    {
        $raw = strtoupper(trim($action));
        if ($raw === '') {
            return 'autres';
        }
        if (strpos($raw, 'CREATION') !== false || strpos($raw, 'AUTO') !== false) {
            return 'creation';
        }
        if (strpos($raw, 'VALID') !== false || strpos($raw, 'APPROUVE') !== false || strpos($raw, 'CONFORME') !== false) {
            return 'validation';
        }
        if (strpos($raw, 'DECAISSE') !== false) {
            return 'decaissement';
        }
        if (strpos($raw, 'REPORT') !== false) {
            return 'report';
        }
        if (strpos($raw, 'REFUS') !== false || strpos($raw, 'ANNUL') !== false) {
            return 'refus';
        }
        return strtolower($raw);
    }

    private function mergeWorkflowTraces(array $realRows, array $syntheticRows, int $limit): array
    {
        $merged = [];
        $seenSemantic = [];

        foreach ($realRows as $row) {
            $row['is_synthetic'] = false;
            $merged[] = $row;
            $semanticKey = trim((string)($row['num_fiche'] ?? '')) . '|' . $this->getTraceSemanticType((string)($row['action'] ?? ''));
            $seenSemantic[$semanticKey] = true;
        }

        foreach ($syntheticRows as $row) {
            $semanticKey = trim((string)($row['num_fiche'] ?? '')) . '|' . $this->getTraceSemanticType((string)($row['action'] ?? ''));
            if (isset($seenSemantic[$semanticKey])) {
                continue;
            }
            $merged[] = $row;
            $seenSemantic[$semanticKey] = true;
        }

        usort($merged, static function (array $left, array $right): int {
            $cmp = strcmp((string)($right['date_action'] ?? ''), (string)($left['date_action'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            if (($left['is_synthetic'] ?? false) !== ($right['is_synthetic'] ?? false)) {
                return ($left['is_synthetic'] ?? false) ? 1 : -1;
            }
            return strcmp((string)($right['num_fiche'] ?? ''), (string)($left['num_fiche'] ?? ''));
        });

        return array_slice($merged, 0, max(1, $limit));
    }

    /** Traces du workflow complet d'une fiche */
    public function getWorkflowFamily(string $num): array
    {
        try {
            $family = $this->getWorkflowFamilyRows($num);
            $root = $family['root'] ?? null;
            $items = [];
            foreach (($family['items'] ?? []) as $row) {
                $row['workflow_stage_code'] = $this->resolveWorkflowStageCode($row);
                $row['workflow_stage_label'] = $this->resolveWorkflowStageLabel($row['workflow_stage_code']);
                $row['is_root'] = $root !== null && (string)($row['num_fiche'] ?? '') === (string)$root;
                $items[] = $row;
            }
            return ['root' => $root, 'items' => $items];
        } catch (\Throwable $e) {
            return ['root' => null, 'items' => []];
        }
    }

    public function getTraces(string $num, int $limit = 50): array
    {
        try {
            $family = $this->getWorkflowFamilyRows($num);
            $items = $family['items'] ?? [];
            $nums = array_values(array_filter(array_map(static function (array $row): string {
                return trim((string)($row['num_fiche'] ?? ''));
            }, $items)));

            if (!$nums) {
                return [];
            }

            $placeholders = implode(',', array_fill(0, count($nums), '?'));
            $traceCols = $this->pdo->query("SHOW COLUMNS FROM trace_fiche")->fetchAll(PDO::FETCH_COLUMN, 0);
            $detailsExpr = in_array('details', $traceCols, true)
                ? 'details'
                : (in_array('user_agent', $traceCols, true) ? 'user_agent AS details' : 'NULL AS details');
            $sql = "SELECT num_fiche, action, utilisateur, date_action, " . $detailsExpr . " FROM trace_fiche WHERE num_fiche IN ($placeholders) ORDER BY date_action DESC, id DESC LIMIT " . max(1, (int)$limit);
            $st = $this->pdo->prepare($sql);
            foreach ($nums as $index => $value) {
                $st->bindValue($index + 1, $value, PDO::PARAM_STR);
            }
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $syntheticRows = $this->buildSyntheticWorkflowTraces($items);

            if (!$rows) {
                return array_slice($syntheticRows, 0, max(1, (int)$limit));
            }

            return $this->mergeWorkflowTraces($rows, $syntheticRows, (int)$limit);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Hiérarchie parent/enfants + agrégats sur tout le workflow */
    public function getHierarchy(string $num): array
    {
        $fiche = $this->getByNum($num);
        if (!$fiche) {
            return [];
        }

        $family = $this->getWorkflowFamilyRows($num);
        $root = $family['root'] ?? null;
        $items = $family['items'] ?? [];
        if ($root === null || !$items) {
            return [];
        }

        $total = 0.0;
        $totalDec = 0.0;
        $reste = 0.0;
        foreach ($items as $row) {
            $montant = (float)($row['montant_fiche'] ?? 0);
            $total += $montant;
            if ((int)($row['decaisse'] ?? 0) === 1) {
                $totalDec += $montant;
            } else {
                $reste += $montant;
            }
        }

        return [
            'root' => $root,
            'fiche_courante' => $fiche['num_fiche'],
            'children_count' => max(0, count($items) - 1),
            'total_montant' => $total,
            'total_decaissé' => $totalDec,
            'total_restant' => $reste,
            'items' => array_map(function (array $row) use ($num) {
                return [
                    'num_fiche' => $row['num_fiche'],
                    'parent_num_fiche' => $this->getParentValue($row),
                    'montant_fiche' => $row['montant_fiche'],
                    'decaisse' => $row['decaisse'],
                    'date_decaisse' => $row['date_decaisse'] ?? $row['date_decaissement'] ?? null,
                    'date_min' => $row['date_decaissement_minimum'] ?? null,
                    'etat_fiche' => $row['etat_fiche'],
                    'etape_label' => $this->getWorkflowStageLabel($row),
                    'is_current' => ((string)($row['num_fiche'] ?? '') === $num),
                ];
            }, $items)
        ];
    }

    /**
     * Crée (si non existantes) deux fiches liées à une fiche carburant: "Frais de route" et "Location/Voyage".
     * - Lien via colonne parent (préférence: code_parentale)
     * - Copie chantier/ligne/bénéficiaire/mode de paiement du parent
     * - Montants: Frais de route = 20000; Location/Voyage = 146250
     * - Marqueur dans details_demande: [AUTO] ... lié à <num parent>
     * Retourne les numéros créés (ou trouvés existants) sous la forme ['frais_route' => ..., 'location_voyage' => ...]
     */
    public function createChildrenForCarburant(string $parentNum, float $montantFraisRoute = 20000.0, float $montantLocationVoyage = 146250.0, string $user = 'SYSTEM'): array
    {
        // Récup parent
        $parent = $this->getDetailsByNum($parentNum);
        if (!$parent) {
            throw new \RuntimeException('Fiche parent introuvable: ' . $parentNum);
        }

        // S'assurer des colonnes nécessaires (dont code_parentale)
        $this->ensureComptaColumns();
        $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        $parentCol = in_array('code_parentale', $cols, true) ? 'code_parentale' : (in_array('parent_num_fiche', $cols, true) ? 'parent_num_fiche' : null);
        if (!$parentCol) {
            // Dernière tentative: ajouter code_parentale
            $this->pdo->exec("ALTER TABLE fiche_decaissement ADD COLUMN code_parentale VARCHAR(50) NULL AFTER num_fiche");
            $parentCol = 'code_parentale';
        }

        // Vérifier existence déjà créée (basée sur marqueur AUTO + parent)
        $existsFR = $this->childExistsByMarker($parentNum, 'Frais de route');
        $existsLV = $this->childExistsByMarker($parentNum, 'Location/Voyage');

        $created = ['frais_route' => null, 'location_voyage' => null];

        // Helper pour générer un numéro enfant unique par suffixe
        $genNum = function (string $suffix) use ($parentNum): string {
            $base = $parentNum . '-' . $suffix;
            $num = $base;
            $i = 2;
            while ($this->getByNum($num)) {
                $num = $base . $i;
                $i++;
                if ($i > 99) break; // garde-fou
            }
            return $num;
        };

        // Préparer INSERT custom (etat_fiche=0, decaisse=0)
        $sql = "INSERT INTO fiche_decaissement (num_fiche,$parentCol,beficiaire_fiche,tel_beneficiaire_fiche,photo_beneficiaire,montant_fiche,precision_fiche,designation_fiche,details_demande,chantier_id,serv_bureau_banamur_id,ligne_debourse_id,mode_paiement,conforme,approuve,etat_fiche,decaisse,date_creat_fiche,montant_initial_fiche) VALUES (:num,:parent,:benef,:tel,:photo,:montant,:prec,:desig,:details,:chantier,:bureau,:ligne,:mode,0,0,0,0,NOW(),:mi)";
        $ins = $this->pdo->prepare($sql);

        // Frais de route
        if (!$existsFR) {
            $numFR = $genNum('FR');
            $ok = $ins->execute([
                'num' => $numFR,
                'parent' => $parentNum,
                'benef' => $parent['beficiaire_fiche'],
                'tel' => $parent['tel_beneficiaire_fiche'] ?? null,
                'photo' => $parent['photo_beneficiaire'] ?? null,
                'montant' => $montantFraisRoute,
                'prec' => $parent['precision_fiche'],
                'desig' => $parent['designation_fiche'],
                'details' => '[AUTO] Frais de route lié à ' . $parentNum,
                'chantier' => $parent['chantier_id'],
                'bureau' => $parent['serv_bureau_banamur_id'] ?? null,
                'ligne' => $parent['ligne_debourse_id'],
                'mode' => $parent['mode_paiement'] ?? null,
                'mi' => $montantFraisRoute,
            ]);
            if ($ok) {
                $created['frais_route'] = $numFR;
                $this->traceAction($numFR, 'CREATION_AUTO_FRAIS_ROUTE', $user, '', 0, 'Parent ' . $parentNum);
            }
        } else {
            $created['frais_route'] = $this->findExistingChildNumByMarker($parentNum, 'Frais de route') ?: null;
        }

        // Location/Voyage
        if (!$existsLV) {
            $numLV = $genNum('LV');
            $ok = $ins->execute([
                'num' => $numLV,
                'parent' => $parentNum,
                'benef' => $parent['beficiaire_fiche'],
                'tel' => $parent['tel_beneficiaire_fiche'] ?? null,
                'photo' => $parent['photo_beneficiaire'] ?? null,
                'montant' => $montantLocationVoyage,
                'prec' => $parent['precision_fiche'],
                'desig' => $parent['designation_fiche'],
                'details' => '[AUTO] Location/Voyage lié à ' . $parentNum,
                'chantier' => $parent['chantier_id'],
                'bureau' => $parent['serv_bureau_banamur_id'] ?? null,
                'ligne' => $parent['ligne_debourse_id'],
                'mode' => $parent['mode_paiement'] ?? null,
                'mi' => $montantLocationVoyage,
            ]);
            if ($ok) {
                $created['location_voyage'] = $numLV;
                $this->traceAction($numLV, 'CREATION_AUTO_LOCATION_VOYAGE', $user, '', 0, 'Parent ' . $parentNum);
            }
        } else {
            $created['location_voyage'] = $this->findExistingChildNumByMarker($parentNum, 'Location/Voyage') ?: null;
        }

        // Trace globale
        $this->traceAction($parentNum, 'LIEN_FRAIS_ROUTE_LOCATION_VOYAGE', $user, '', 0, json_encode($created));
        return $created;
    }

    private function childExistsByMarker(string $parentNum, string $label): bool
    {
        $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        $parentCol = in_array('code_parentale', $cols, true) ? 'code_parentale' : (in_array('parent_num_fiche', $cols, true) ? 'parent_num_fiche' : null);
        if (!$parentCol) return false;
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM fiche_decaissement WHERE $parentCol = :p AND details_demande LIKE :m");
        $st->execute(['p' => $parentNum, 'm' => '%[AUTO] ' . $label . '%']);
        return ((int)$st->fetchColumn()) > 0;
    }

    private function findExistingChildNumByMarker(string $parentNum, string $label): ?string
    {
        $cols = $this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
        $parentCol = in_array('code_parentale', $cols, true) ? 'code_parentale' : (in_array('parent_num_fiche', $cols, true) ? 'parent_num_fiche' : null);
        if (!$parentCol) return null;
        $st = $this->pdo->prepare("SELECT num_fiche FROM fiche_decaissement WHERE $parentCol = :p AND details_demande LIKE :m ORDER BY date_creat_fiche ASC LIMIT 1");
        $st->execute(['p' => $parentNum, 'm' => '%[AUTO] ' . $label . '%']);
        $num = $st->fetchColumn();
        return $num ?: null;
    }

    /**
     * Crée une fiche transport "part" autonome (carburant / frais / solde) selon les paramètres fournis.
     * - Génère un num_fiche unique au format FD-ym-xxxxx
     * - Résout chantier_id à partir de chantier_code (exact), sinon null
     * - Insère la fiche avec approuve=1 (auto-approbation) et conforme=0 (sera mis à 1 ensuite par setConforme)
     * - Renseigne montant_initial_fiche = montant
     * Retourne le num_fiche créé ou null en cas d'échec
     */
    public function createFicheTransportPart(string $beneficiaire, ?string $tel, string $chantier_code, int $ligne_debourse_id, string $designation, float $montant, ?string $precision = null): ?string
    {
        try {
            if ($montant <= 0) return null;

            // Assurer colonnes utilisées (montant_initial_fiche, etc.)
            $this->ensureComptaColumns();

            // Résoudre chantier_id via code
            $chantierId = null;
            if (!empty($chantier_code)) {
                $stC = $this->pdo->prepare("SELECT id FROM chantier_banamur WHERE code = :c LIMIT 1");
                $stC->execute(['c' => $chantier_code]);
                $cid = $stC->fetchColumn();
                if ($cid) {
                    $chantierId = (int)$cid;
                } elseif (ctype_digit((string)$chantier_code)) {
                    // fallback si on passe un id directement
                    $chantierId = (int)$chantier_code;
                }
            }

            // Déterminer precision par défaut si non fournie
            if ($precision === null) {
                $precision = (stripos($designation, 'carburant') !== false) ? 'carburant' : null;
            }

            // Générer un num_fiche unique
            $num = null;
            $tries = 0;
            do {
                $tries++;
                $candidate = 'FD-' . date('ym') . '-' . str_pad((string)random_int(1, 99999), 5, '0', STR_PAD_LEFT);
                $chk = $this->pdo->prepare("SELECT COUNT(*) FROM fiche_decaissement WHERE num_fiche = :n");
                $chk->execute(['n' => $candidate]);
                if ((int)$chk->fetchColumn() === 0) {
                    $num = $candidate;
                    break;
                }
            } while ($tries < 5);
            if ($num === null) return null;

            // Insertion
            $sql = "INSERT INTO fiche_decaissement (
                        num_fiche,
                        beficiaire_fiche,
                        tel_beneficiaire_fiche,
                        photo_beneficiaire,
                        montant_fiche,
                        precision_fiche,
                        designation_fiche,
                        details_demande,
                        chantier_id,
                        serv_bureau_banamur_id,
                        ligne_debourse_id,
                        mode_paiement,
                        conforme,
                        approuve,
                        etat_fiche,
                        decaisse,
                        date_creat_fiche,
                        montant_initial_fiche
                    ) VALUES (
                        :num,
                        :benef,
                        :tel,
                        :photo,
                        :montant,
                        :prec,
                        :desig,
                        :details,
                        :chantier,
                        :bureau,
                        :ligne,
                        :mode,
                        0,
                        1,
                        0,
                        0,
                        NOW(),
                        :mi
                    )";
            $ins = $this->pdo->prepare($sql);
            $ok = $ins->execute([
                'num' => $num,
                'benef' => $beneficiaire,
                'tel' => $tel,
                'photo' => null,
                'montant' => $montant,
                'prec' => $precision,
                'desig' => $designation,
                'details' => '[AUTO] Transport part',
                'chantier' => $chantierId,
                'bureau' => null,
                'ligne' => $ligne_debourse_id,
                'mode' => null,
                'mi' => $montant,
            ]);
            if (!$ok) return null;

            // Trace
            $this->traceAction($num, 'CREATION_FICHE_TRANSPORT_PART', 'SYSTEM', '', 0, 'montant=' . $montant);
            return $num;
        } catch (\Throwable $e) {
            return null;
        }
    }



    // Méthode pour lire une fiche par son ID
    public function getByAuthCode($code_autorisation_feb)
    {
        $sql = "SELECT f.* FROM fiche_decaissement f WHERE f.code_autorisation_feb = :code_autorisation_feb AND ".$this->activeChantierScope('f');
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':code_autorisation_feb', $code_autorisation_feb, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}



