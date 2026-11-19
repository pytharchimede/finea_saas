<?php
declare(strict_types=1);
require_once __DIR__.'/../../services/ContractWorkflowPolicy.php';

final class ContratSousTraitance
{
    public function __construct(private PDO $pdo) { $this->ensureSchema(); }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS contrat_sous_traitance (
          id INT AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(50) NOT NULL UNIQUE, document_id VARCHAR(90) NOT NULL UNIQUE,
          chantier_id INT NOT NULL, fournisseur_id INT NOT NULL, objet VARCHAR(255) NOT NULL, montant_ht DECIMAL(18,2) NOT NULL,
          taux_acompte DECIMAL(7,2) NOT NULL DEFAULT 0, montant_acompte DECIMAL(18,2) NOT NULL DEFAULT 0,
          retenue_garantie_taux DECIMAL(7,2) NOT NULL DEFAULT 0, date_signature DATE NULL, date_debut DATE NULL, date_fin DATE NULL,
          delai_jours INT NULL, statut ENUM('brouillon','a_signer','actif','suspendu','solde','resilie') NOT NULL DEFAULT 'brouillon',
          clauses_particulieres TEXT NULL, signataire_banamur_personnel_id INT NULL, signature_banamur LONGTEXT NULL, signature_prestataire LONGTEXT NULL,
          signe_banamur_at DATETIME NULL, signe_prestataire_at DATETIME NULL, created_by INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_contrat_chantier(chantier_id), INDEX idx_contrat_fournisseur(fournisseur_id), INDEX idx_contrat_statut(statut)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS contrat_sous_traitance_ligne (
          id INT AUTO_INCREMENT PRIMARY KEY, contrat_id INT NOT NULL, ligne_devis_id INT NOT NULL, montant_contractuel DECIMAL(18,2) NOT NULL,
          poids DECIMAL(9,4) NOT NULL DEFAULT 0, avancement DECIMAL(7,2) NOT NULL DEFAULT 0, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_contrat_ligne(ligne_devis_id), INDEX idx_csl_contrat(contrat_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS contrat_sous_traitance_paiement (
          id INT AUTO_INCREMENT PRIMARY KEY, contrat_id INT NOT NULL, fiche_id INT NULL, type_paiement ENUM('acompte','avancement','solde') NOT NULL,
          avancement_cumule DECIMAL(7,2) NOT NULL DEFAULT 0, montant_brut DECIMAL(18,2) NOT NULL DEFAULT 0,
          recuperation_acompte DECIMAL(18,2) NOT NULL DEFAULT 0, retenue_garantie DECIMAL(18,2) NOT NULL DEFAULT 0, montant_net DECIMAL(18,2) NOT NULL DEFAULT 0,
          pv_path VARCHAR(500) NULL, facture_fne_path VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_csp_contrat(contrat_id), INDEX idx_csp_fiche(fiche_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS contrat_reception_pv (
          id INT AUTO_INCREMENT PRIMARY KEY, paiement_id INT NOT NULL UNIQUE,
          decision ENUM('sans_reserve','avec_reserves','refuse') NOT NULL DEFAULT 'sans_reserve', observations TEXT NULL, travaux_json LONGTEXT NULL,
          preuves_json LONGTEXT NULL, signataire_banamur_personnel_id INT NULL, signature_banamur LONGTEXT NULL, signature_sous_traitant LONGTEXT NULL,
          signe_banamur_at DATETIME NULL, signe_sous_traitant_at DATETIME NULL, updated_by INT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX idx_reception_paiement(paiement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS contrat_signature_historique (
          id INT AUTO_INCREMENT PRIMARY KEY, document_type ENUM('contrat','pv') NOT NULL, document_id INT NOT NULL,
          action VARCHAR(50) NOT NULL, signataire_personnel_id INT NULL, acteur_user_id INT NULL, details_json TEXT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_signature_document(document_type,document_id), INDEX idx_signature_signataire(signataire_personnel_id), INDEX idx_signature_date(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS contrat_workflow_etape (
          id INT AUTO_INCREMENT PRIMARY KEY, contrat_id INT NOT NULL, etape ENUM('rh','juridique','technique','qhse','comptabilite','logistique') NOT NULL,
          ordre TINYINT NOT NULL, statut ENUM('en_attente','en_cours','validee') NOT NULL DEFAULT 'en_attente', donnees_json LONGTEXT NULL,
          signataire_personnel_id INT NULL, signature LONGTEXT NULL, signe_par_user_id INT NULL, signe_at DATETIME NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uq_contrat_workflow_etape(contrat_id,etape), INDEX idx_workflow_statut(statut,ordre)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try{$this->pdo->exec('ALTER TABLE contrat_reception_pv ADD COLUMN travaux_json LONGTEXT NULL AFTER observations');}catch(Throwable){}
        try{$this->pdo->exec('ALTER TABLE contrat_sous_traitance ADD COLUMN signataire_banamur_personnel_id INT NULL AFTER clauses_particulieres');}catch(Throwable){}
        try{$this->pdo->exec('ALTER TABLE contrat_sous_traitance ADD COLUMN dg_edit_opened_at DATETIME NULL AFTER signature_prestataire');}catch(Throwable){}
        try{$this->pdo->exec('ALTER TABLE contrat_reception_pv ADD COLUMN signataire_banamur_personnel_id INT NULL AFTER preuves_json');}catch(Throwable){}
        try{$this->pdo->exec("ALTER TABLE contrat_sous_traitance_paiement ADD COLUMN montant_decaisse_effectif DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER montant_net");}catch(Throwable){}
        try{$this->pdo->exec("ALTER TABLE contrat_sous_traitance_paiement ADD COLUMN statut_financier ENUM('soumis','en_circuit','refuse','partiel','decaisse') NOT NULL DEFAULT 'soumis' AFTER montant_decaisse_effectif");}catch(Throwable){}
        try{$this->pdo->exec('ALTER TABLE contrat_sous_traitance_paiement ADD COLUMN restitution_retenue DECIMAL(18,2) NOT NULL DEFAULT 0 AFTER retenue_garantie');}catch(Throwable){}
        try{$this->pdo->exec("ALTER TABLE contrat_workflow_etape MODIFY etape ENUM('rh','juridique','technique','qhse','comptabilite','logistique') NOT NULL");}catch(Throwable){}
        foreach ([
            'contract_id' => 'INT NULL', 'contract_payment_id' => 'INT NULL', 'contract_payment_type' => "VARCHAR(20) NULL",
            'contract_progress_rate' => 'DECIMAL(7,2) NULL', 'contract_pv_path' => 'VARCHAR(500) NULL',
            'contract_fne_path' => 'VARCHAR(500) NULL',
            'facture_reference' => 'VARCHAR(500) NULL',
            'pv_reception_reference' => 'TEXT NULL'
        ] as $column => $definition) {
            try { $this->pdo->exec("ALTER TABLE fiche_decaissement ADD COLUMN {$column} {$definition}"); } catch (Throwable) {}
        }
        try{$this->pdo->exec('UPDATE fiche_decaissement f JOIN contrat_sous_traitance_paiement p ON p.fiche_id=f.id_fiche SET f.contract_payment_id=p.id WHERE f.contract_payment_id IS NULL');}catch(Throwable){}
        try{$this->pdo->exec("UPDATE contrat_sous_traitance_paiement p SET p.montant_decaisse_effectif=(SELECT COALESCE(SUM(f.montant_fiche),0) FROM fiche_decaissement f WHERE f.contract_payment_id=p.id AND f.decaisse=1),p.statut_financier=CASE WHEN EXISTS(SELECT 1 FROM fiche_decaissement f WHERE f.contract_payment_id=p.id AND f.decaisse=1) THEN CASE WHEN (SELECT COALESCE(SUM(f.montant_fiche),0) FROM fiche_decaissement f WHERE f.contract_payment_id=p.id AND f.decaisse=1)+0.01>=p.montant_net THEN 'decaisse' ELSE 'partiel' END WHEN EXISTS(SELECT 1 FROM fiche_decaissement f WHERE f.contract_payment_id=p.id AND (f.approuve=2 OR f.etat_fiche=2)) THEN 'refuse' ELSE 'en_circuit' END");}catch(Throwable){}
        $workflowOrder=['rh'=>1,'juridique'=>2,'technique'=>3,'logistique'=>4,'qhse'=>5,'comptabilite'=>6];
        foreach($workflowOrder as $stage=>$order){$q=$this->pdo->prepare("INSERT IGNORE INTO contrat_workflow_etape(contrat_id,etape,ordre,statut) SELECT id,?,?,CASE WHEN statut='brouillon' AND ?='rh' THEN 'en_cours' WHEN statut='brouillon' THEN 'en_attente' ELSE 'validee' END FROM contrat_sous_traitance");$q->execute([$stage,$order,$stage]);}
        $this->pdo->exec("UPDATE contrat_workflow_etape SET ordre=CASE etape WHEN 'rh' THEN 1 WHEN 'juridique' THEN 2 WHEN 'technique' THEN 3 WHEN 'logistique' THEN 4 WHEN 'qhse' THEN 5 WHEN 'comptabilite' THEN 6 END");
        try{$this->synchronizeExistingContractProgress();}catch(Throwable $e){error_log('[contrats] Synchronisation des avancements historiques impossible : '.$e->getMessage());}
        // Une FEB racine n'a, par définition, ni fiche parente ni ancienne
        // information de modification. Ces champs historiques doivent donc
        // accepter NULL, y compris pour les FEB créées depuis un contrat.
        foreach ([
            'parent_num_fiche' => 'TEXT NULL',
            'code_parentale' => 'TEXT NULL',
            'date_last_modif' => 'DATETIME NULL',
            'secur_last_modif' => 'TEXT NULL',
        ] as $column => $definition) {
            try {
                $check=$this->pdo->prepare('SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'fiche_decaissement\' AND COLUMN_NAME=?');
                $check->execute([$column]);
                if($check->fetchColumn()==='NO'){
                    $this->pdo->exec("ALTER TABLE fiche_decaissement MODIFY COLUMN {$column} {$definition}");
                }
            } catch (Throwable $e) {
                error_log('[contrats] Migration automatique FEB impossible pour '.$column.' : '.$e->getMessage());
            }
        }
    }

    /** Met à niveau les contrats existants depuis leurs PV, sans script SQL manuel. */
    private function synchronizeExistingContractProgress(): void
    {
        $rows=$this->pdo->query("SELECT p.id paiement_id,p.contrat_id,p.type_paiement,r.travaux_json FROM contrat_sous_traitance_paiement p LEFT JOIN contrat_reception_pv r ON r.paiement_id=p.id WHERE r.travaux_json IS NOT NULL AND r.travaux_json<>'' ORDER BY p.id")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $updateLine=$this->pdo->prepare('UPDATE contrat_sous_traitance_ligne SET avancement=? WHERE id=? AND contrat_id=?');
        $updatePayment=$this->pdo->prepare('UPDATE contrat_sous_traitance_paiement SET avancement_cumule=? WHERE id=?');
        foreach($rows as $row){$works=json_decode((string)$row['travaux_json'],true);if(!is_array($works))continue;$weighted=0.0;foreach($works as $work){$lineId=(int)($work['contrat_ligne_id']??0);$progress=max(0,min(100,(float)($work['avancement']??0)));$weight=max(0,(float)($work['poids']??0));if($lineId>0)$updateLine->execute([$progress,$lineId,(int)$row['contrat_id']]);$weighted+=$weight*$progress/100;}if($row['type_paiement']==='avancement')$updatePayment->execute([$weighted,(int)$row['paiement_id']]);}
        // Les soldes encore sans FEB sont corrigés automatiquement. Les pièces
        // déjà envoyées/décaissées ne sont jamais réécrites.
        $this->pdo->exec("UPDATE contrat_sous_traitance_paiement p JOIN contrat_sous_traitance c ON c.id=p.contrat_id SET p.restitution_retenue=ROUND(c.montant_ht*c.retenue_garantie_taux/100,2),p.montant_net=p.montant_net+ROUND(c.montant_ht*c.retenue_garantie_taux/100,2) WHERE p.type_paiement='solde' AND p.fiche_id IS NULL AND p.restitution_retenue=0 AND c.retenue_garantie_taux>0");
    }

    public function create(array $data, array $lineIds, int $userId): int
    {
        $lineIds = array_values(array_unique(array_filter(array_map('intval', $lineIds))));
        if (!$lineIds) throw new InvalidArgumentException('Sélectionnez au moins une ligne de devis.');
        $chantierId=(int)$data['chantier_id']; $supplierId=(int)$data['fournisseur_id'];
        $this->pdo->beginTransaction();
        try {
            $ph=implode(',',array_fill(0,count($lineIds),'?'));
            $q=$this->pdo->prepare("SELECT ld.id,ld.designation,COALESCE(NULLIF((SELECT SUM(ldb.montant) FROM debourse_banamur db JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=db.id WHERE db.ligne_devis_id=ld.id),0),(SELECT SUM(db.montant_debourse) FROM debourse_banamur db WHERE db.ligne_devis_id=ld.id),0) AS montant_contrat FROM ligne_devis_banamur ld JOIN chantier_banamur c ON c.devis_id=ld.devis_id WHERE c.id=? AND c.archived_at IS NULL AND ld.id IN ($ph) FOR UPDATE");
            $q->execute(array_merge([$chantierId],$lineIds)); $lines=$q->fetchAll(PDO::FETCH_ASSOC);
            if(count($lines)!==count($lineIds)) throw new RuntimeException('Une ligne ne dépend pas de ce chantier.');
            $lock=$this->pdo->prepare("SELECT COUNT(*) FROM contrat_sous_traitance_ligne cl JOIN contrat_sous_traitance c ON c.id=cl.contrat_id WHERE cl.ligne_devis_id IN ($ph) AND c.statut NOT IN ('resilie','solde')");
            $lock->execute($lineIds); if((int)$lock->fetchColumn()>0) throw new RuntimeException('Une ligne sélectionnée est déjà sous contrat actif.');
            $unchiffrées=array_filter($lines,fn($l)=>(float)$l['montant_contrat']<=0);
            if($unchiffrées)throw new RuntimeException('Chaque ligne sélectionnée doit posséder un déboursé chiffré. Ligne concernée : '.reset($unchiffrées)['designation']);
            $total=array_sum(array_map(fn($l)=>(float)$l['montant_contrat'],$lines));
            $year=date('Y'); $seq=(int)$this->pdo->query("SELECT COUNT(*)+1 FROM contrat_sous_traitance WHERE YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
            $reference='CST-'.$year.'-'.str_pad((string)$seq,4,'0',STR_PAD_LEFT); $document='FIN-CST-'.$year.'-'.str_pad((string)$seq,6,'0',STR_PAD_LEFT);
            $rate=max(0,min(100,(float)($data['taux_acompte']??0))); $advance=round($total*$rate/100,2);
            $startDate=trim((string)($data['date_debut']??date('Y-m-d')));
            $duration=max(1,(int)($data['delai_jours']??1));
            $start=DateTimeImmutable::createFromFormat('!Y-m-d',$startDate);
            if(!$start||$start->format('Y-m-d')!==$startDate)throw new InvalidArgumentException('La date de début est invalide.');
            $endDate=$start->modify('+'.$duration.' days')->format('Y-m-d');
            $s=$this->pdo->prepare("INSERT INTO contrat_sous_traitance(reference,document_id,chantier_id,fournisseur_id,objet,montant_ht,taux_acompte,montant_acompte,retenue_garantie_taux,date_signature,date_debut,date_fin,delai_jours,statut,clauses_particulieres,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'brouillon',?,?)");
            $object=trim((string)($data['objet']??''))?:'Objet à définir par le département technique';
            $s->execute([$reference,$document,$chantierId,$supplierId,$object,$total,$rate,$advance,max(0,min(100,(float)($data['retenue_garantie_taux']??0))),!empty($data['date_signature'])?$data['date_signature']:null,$startDate,$endDate,$duration,trim((string)($data['clauses_particulieres']??''))?:null,$userId]);
            $id=(int)$this->pdo->lastInsertId(); $li=$this->pdo->prepare('INSERT INTO contrat_sous_traitance_ligne(contrat_id,ligne_devis_id,montant_contractuel,poids) VALUES(?,?,?,?)');
            foreach($lines as $l)$li->execute([$id,$l['id'],$l['montant_contrat'],round((float)$l['montant_contrat']/$total*100,4)]);foreach(['rh'=>1,'juridique'=>2,'technique'=>3,'logistique'=>4,'qhse'=>5,'comptabilite'=>6] as $stage=>$order){$w=$this->pdo->prepare("INSERT INTO contrat_workflow_etape(contrat_id,etape,ordre,statut) VALUES(?,?,?,?)");$w->execute([$id,$stage,$order,$stage==='rh'?'en_cours':'en_attente']);}
            $this->pdo->commit(); return $id;
        } catch(Throwable $e){$this->pdo->rollBack();throw $e;}
    }

    public function all(): array { return $this->pdo->query("SELECT c.*,ch.code chantier_code,ch.titre chantier_titre,COALESCE(NULLIF(f.nom_commercial,''),f.raison_sociale,CONCAT(f.person_first_name,' ',f.person_last_name)) fournisseur_nom,(SELECT COALESCE(SUM(fd.montant_fiche),0) FROM fiche_decaissement fd WHERE fd.contract_id=c.id AND fd.decaisse=1) total_paye FROM contrat_sous_traitance c JOIN chantier_banamur ch ON ch.id=c.chantier_id JOIN fournisseur_banamur f ON f.id_fournisseur=c.fournisseur_id ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC); }
    public function logSignatureHistory(string $documentType,int $documentId,string $action,?int $signerPersonnelId,int $actorUserId,array $details=[]): void
    {
        if(!in_array($documentType,['contrat','pv'],true)||$documentId<1)return;
        $q=$this->pdo->prepare('INSERT INTO contrat_signature_historique(document_type,document_id,action,signataire_personnel_id,acteur_user_id,details_json) VALUES(?,?,?,?,?,?)');
        $q->execute([$documentType,$documentId,substr($action,0,50),$signerPersonnelId&&$signerPersonnelId>0?$signerPersonnelId:null,$actorUserId>0?$actorUserId:null,json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
    /** Modèle légal disponible immédiatement pour tous les contrats, y compris historiques. */
    public static function legalArticleTemplate(string $object=''): array
    {
        return [
            ['titre'=>'ARTICLE 1 — OBJET DU CONTRAT','sous_titre'=>'','contenu'=>'Le présent contrat a pour objet la sous-traitance des travaux suivants : '.($object?:'à préciser dans le dossier contractuel.').' Ces travaux s’inscrivent dans le cadre du projet concerné.','enumerations'=>[]],
            ['titre'=>'ARTICLE 2 — DURÉE DU CONTRAT','sous_titre'=>'','contenu'=>'Le contrat est conclu pour la durée déterminée correspondant à la période d’exécution des travaux définie au planning contractuel.','enumerations'=>[]],
            ['titre'=>'ARTICLE 3 — PLANNING DES TRAVAUX','sous_titre'=>'','contenu'=>'Le Sous-traitant respecte strictement le planning, les dates de début et de fin ainsi que les délais validés dans la partie Technique.','enumerations'=>['Tout retard non justifié peut entraîner les pénalités prévues au présent contrat.']],
            ['titre'=>'ARTICLE 4 — OBLIGATIONS DU SOUS-TRAITANT','sous_titre'=>'','contenu'=>'Le Sous-traitant exécute les travaux conformément aux règles de l’art, aux normes de qualité et de sécurité applicables.','enumerations'=>['Respecter le planning contractuel.','Fournir la main-d’œuvre qualifiée et le matériel nécessaire.','Informer immédiatement le Donneur d’Ordre de toute difficulté.']],
            ['titre'=>'ARTICLE 5 — OBLIGATIONS DU DONNEUR D’ORDRE','sous_titre'=>'','contenu'=>'Le Donneur d’Ordre met le chantier à disposition dans les délais, fournit les informations techniques nécessaires et règle les paiements selon les modalités prévues.','enumerations'=>[]],
            ['titre'=>'ARTICLE 6 — PRIX ET MODALITÉS DE PAIEMENT','sous_titre'=>'','contenu'=>'Le montant total, l’acompte, les avancements réels, la récupération d’acompte et la retenue de garantie sont ceux validés par la Comptabilité dans l’échéancier contractuel.','enumerations'=>[]],
            ['titre'=>'ARTICLE 7 — RÉCEPTION DES TRAVAUX','sous_titre'=>'','contenu'=>'La réception des travaux est réalisée selon le planning par procès-verbal signé par les deux parties. Les éventuelles réserves sont levées avant solde.','enumerations'=>[]],
            ['titre'=>'ARTICLE 8 — PÉNALITÉS DE RETARD','sous_titre'=>'','contenu'=>'Tout retard imputable au Sous-traitant peut entraîner les pénalités validées dans les conditions financières, sans préjudice des dommages et intérêts applicables.','enumerations'=>[]],
            ['titre'=>'ARTICLE 9 — RÉSILIATION','sous_titre'=>'','contenu'=>'Le contrat peut être résilié de plein droit en cas de manquement grave de l’une des parties, après mise en demeure restée sans effet dans le délai contractuellement prévu.','enumerations'=>[]],
            ['titre'=>'ARTICLE 10 — EPI ET MODALITÉS FINANCIÈRES','sous_titre'=>'','contenu'=>'Les EPI, le logement et le transport sont à la charge du Sous-traitant. Tout EPI fourni par le Donneur d’Ordre peut être déduit du montant du contrat selon l’annexe EPI.','enumerations'=>['Le Sous-traitant fournit, renouvelle et entretient les EPI de son personnel.','Les spécifications et montants éventuellement déductibles figurent en Annexe 1.']],
            ['titre'=>'ARTICLE 11 — ORGANISATION DU PRESTATAIRE ET ORGANIGRAMME','sous_titre'=>'','contenu'=>'Le Prestataire affecte un personnel qualifié, expérimenté et habilité, et communique son organigramme fonctionnel et nominatif dédié au projet.','enumerations'=>['L’organigramme du Prestataire figure en Annexe 2.']],
            ['titre'=>'ARTICLE 12 — MODE OPÉRATOIRE, DROIT APPLICABLE ET LITIGES','sous_titre'=>'','contenu'=>'Le mode opératoire couvre l’installation du chantier, terrassements, fondations, élévation, charpente, dallage, plomberie, sécurité, contrôle qualité et réception. Le présent contrat est soumis au droit ivoirien ; tout litige relève de la juridiction compétente après recherche d’une solution amiable.','enumerations'=>['Installation et sécurisation du chantier.','Exécution suivant les plans, contrôles qualité et règles HSE.','Réception provisoire, levée des réserves et réception finale.']]
        ];
    }
    public function workflow(int $contractId): array
    {
        $q=$this->pdo->prepare("SELECT w.*,p.nom_personnel_soignant signataire_nom,COALESCE(f.lib_fonction,'') signataire_fonction FROM contrat_workflow_etape w LEFT JOIN personnel p ON p.id_personnel_soignant=w.signataire_personnel_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id WHERE w.contrat_id=? ORDER BY w.ordre");$q->execute([$contractId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){$row['donnees']=json_decode((string)($row['donnees_json']??'{}'),true)?:[];if($row['etape']==='juridique'&&empty($row['donnees']['articles_juridiques']))$row['donnees']['articles_juridiques']=json_encode(self::legalArticleTemplate(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}unset($row);return $rows;
    }
    /** Empêche une FEB de contourner les visas internes du contrat. */
    public function assertPaymentEligible(int $contractId): void
    {
        $q=$this->pdo->prepare('SELECT statut FROM contrat_sous_traitance WHERE id=?');$q->execute([$contractId]);
        if((string)$q->fetchColumn()!=='actif')throw new RuntimeException('Le contrat doit être signé par la Direction générale et le prestataire avant toute demande de décaissement.');
        $q=$this->pdo->prepare("SELECT COUNT(*) FROM contrat_workflow_etape WHERE contrat_id=? AND statut='validee' AND signature IS NOT NULL AND signature<>''");$q->execute([$contractId]);
        if((int)$q->fetchColumn()!==6)throw new RuntimeException('Les six signatures internes du contrat sont requises avant de créer ou soumettre une FEB. Réouvrez le circuit interne si ce contrat est historique.');
    }
    private function workflowSignerEligible(string $stage,int $personnelId): bool
    {
        if(!in_array($stage,['rh','juridique','technique','logistique','qhse','comptabilite','direction'],true)||$personnelId<1)return false;
        $q=$this->pdo->prepare("SELECT UPPER(COALESCE(f.lib_fonction,'')) fonction FROM personnel p JOIN user_devis_banamur u ON u.personnel_id=p.id_personnel_soignant AND u.active=1 LEFT JOIN fonction f ON f.id_fonction=p.fonction_id WHERE p.id_personnel_soignant=? AND (p.date_sortie IS NULL OR p.date_sortie='0000-00-00') LIMIT 1");$q->execute([$personnelId]);$function=$q->fetchColumn();if($function===false)return false;return $stage!=='direction'||str_contains((string)$function,'DIRECTEUR GENERAL');
    }
    public function assignWorkflowSigners(int $contractId,array $signers,bool $drhOverride=false): void
    {
        if(!$drhOverride)throw new RuntimeException('Réaffectation réservée aux RH et administrateurs.');
        $labels=['rh'=>'RH','juridique'=>'Juridique','technique'=>'Technique','logistique'=>'Logistique','qhse'=>'QHSE','comptabilite'=>'Comptabilité','direction'=>'Direction générale'];
        foreach($labels as $stage=>$label)if(!$this->workflowSignerEligible($stage,(int)($signers[$stage]??0)))throw new InvalidArgumentException('Signataire actif requis pour '.$label.'.');
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT statut,signature_banamur,signature_prestataire FROM contrat_sous_traitance WHERE id=? FOR UPDATE');$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);
            if(!$contract||$contract['statut']==='resilie'||!empty($contract['signature_banamur'])||!empty($contract['signature_prestataire']))throw new RuntimeException('Les signataires ne peuvent plus être modifiés sur ce contrat.');
            $q=$this->pdo->prepare("UPDATE contrat_workflow_etape SET signataire_personnel_id=?,signature=NULL,signe_par_user_id=NULL,signe_at=NULL WHERE contrat_id=? AND etape=? AND statut<>'validee' AND COALESCE(signataire_personnel_id,0)<>?");
            foreach(array_keys($labels) as $stage)if($stage!=='direction')$q->execute([(int)$signers[$stage],$contractId,$stage,(int)$signers[$stage]]);
            $this->pdo->prepare('UPDATE contrat_sous_traitance SET signataire_banamur_personnel_id=? WHERE id=?')->execute([(int)$signers['direction'],$contractId]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    /**
     * Enregistre une étape et annule les visas devenus obsolètes lorsqu’un contenu déjà signé est modifié.
     * @return array{signature_reset:bool,reset_stages:array<int,string>}
     */
    public function saveWorkflowStage(int $contractId,string $stage,array $data,int $signerId,bool $drhOverride=false,int $actorUserId=0): array
    {
        if(!in_array($stage,['rh','juridique','technique','qhse','comptabilite','logistique'],true))throw new InvalidArgumentException('Étape contractuelle invalide.');
        if($signerId<1)throw new InvalidArgumentException('Sélectionnez un signataire pour cette étape.');
        if(!$this->workflowSignerEligible($stage,$signerId))throw new InvalidArgumentException('Le signataire sélectionné est introuvable, inactif ou ne possède pas de compte utilisateur actif.');
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT statut,signature_banamur FROM contrat_sous_traitance WHERE id=? FOR UPDATE');$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);
            if(!$contract)throw new RuntimeException('Contrat introuvable.');
            if(($contract['statut']??'')==='resilie')throw new RuntimeException('Un contrat annulé ne peut plus être modifié.');
            if(!empty($contract['signature_banamur']))throw new RuntimeException('Le contrat ne peut plus être modifié après la signature du Directeur général.');
            $q=$this->pdo->prepare('SELECT id,ordre,statut,signature,signataire_personnel_id,donnees_json FROM contrat_workflow_etape WHERE contrat_id=? AND etape=? FOR UPDATE');$q->execute([$contractId,$stage]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new RuntimeException('Cette étape n’est pas ouverte à la modification.');
            $serializedData=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if($serializedData===false)throw new RuntimeException('Les informations de cette étape sont invalides.');
            $hasChanged=$serializedData!==(string)($row['donnees_json']??'') || $signerId!==(int)($row['signataire_personnel_id']??0);
            $signatureReset=$hasChanged&&((string)$row['statut']==='validee'||!empty($row['signature']));$resetStages=[];
            if($signatureReset){
                $q=$this->pdo->prepare('UPDATE contrat_workflow_etape SET donnees_json=?,signataire_personnel_id=?,signature=NULL,signe_par_user_id=NULL,signe_at=NULL,statut=CASE WHEN statut=\'validee\' THEN \'en_cours\' ELSE statut END WHERE id=?');$q->execute([$serializedData,$signerId,(int)$row['id']]);
            }else{$q=$this->pdo->prepare('UPDATE contrat_workflow_etape SET donnees_json=?,signataire_personnel_id=? WHERE id=?');$q->execute([$serializedData,$signerId,(int)$row['id']]);}
            if($signatureReset&&(string)$row['statut']==='validee'){
                $later=$this->pdo->prepare('SELECT etape FROM contrat_workflow_etape WHERE contrat_id=? AND ordre>? ORDER BY ordre');$later->execute([$contractId,(int)$row['ordre']]);$resetStages=array_merge([$stage],array_map(static fn(array $item): string=>(string)$item['etape'],$later->fetchAll(PDO::FETCH_ASSOC)?:[]));
                $q=$this->pdo->prepare("UPDATE contrat_workflow_etape SET statut='en_attente',signature=NULL,signe_par_user_id=NULL,signe_at=NULL WHERE contrat_id=? AND ordre>?");$q->execute([$contractId,(int)$row['ordre']]);
                $this->pdo->prepare("UPDATE contrat_sous_traitance SET statut='brouillon',signature_prestataire=NULL,signe_prestataire_at=NULL WHERE id=?")->execute([$contractId]);
            }
            // Les fractions financières constituent aussi l’avancement des phases techniques.
            // Toute correction comptable est donc reportée sur le planning, puis les visas
            // Technique et suivants sont rouverts pour contrôle et nouvelle signature.
            if($stage==='comptabilite'&&is_array($data['planning_avancement']??null)){
                $progressByPhase=[];
                foreach($data['planning_avancement'] as $progressKey=>$progressValue)if(preg_match('/_(\d+)$/',(string)$progressKey,$matches)){
                    $phaseIndex=(int)$matches[1];$progress=max(0,min(100,(float)$progressValue));
                    if(isset($progressByPhase[$phaseIndex])&&abs($progressByPhase[$phaseIndex]-$progress)>0.01)throw new RuntimeException('L’avancement financier doit être identique pour une même phase technique sur tous les déboursés.');
                    $progressByPhase[$phaseIndex]=$progress;
                }
                ksort($progressByPhase);$technicalProgress=array_values($progressByPhase);
                $q=$this->pdo->prepare("SELECT id,ordre,statut,signature,donnees_json FROM contrat_workflow_etape WHERE contrat_id=? AND etape='technique' FOR UPDATE");$q->execute([$contractId]);$technicalRow=$q->fetch(PDO::FETCH_ASSOC);
                if($technicalRow){$technicalData=json_decode((string)($technicalRow['donnees_json']??'{}'),true)?:[];$technicalData['planning_avancement']=$technicalProgress;$technicalJson=json_encode($technicalData,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$technicalChanged=$technicalJson!==(string)($technicalRow['donnees_json']??'');if($technicalChanged){$this->pdo->prepare('UPDATE contrat_workflow_etape SET donnees_json=? WHERE id=?')->execute([$technicalJson,(int)$technicalRow['id']]);if((string)$technicalRow['statut']==='validee'||!empty($technicalRow['signature'])){$later=$this->pdo->prepare('SELECT etape FROM contrat_workflow_etape WHERE contrat_id=? AND ordre>=? ORDER BY ordre');$later->execute([$contractId,(int)$technicalRow['ordre']]);$resetStages=array_map(static fn(array $item): string=>(string)$item['etape'],$later->fetchAll(PDO::FETCH_ASSOC)?:[]);$this->pdo->prepare("UPDATE contrat_workflow_etape SET statut='en_cours',signature=NULL,signe_par_user_id=NULL,signe_at=NULL WHERE id=?")->execute([(int)$technicalRow['id']]);$this->pdo->prepare("UPDATE contrat_workflow_etape SET statut='en_attente',signature=NULL,signe_par_user_id=NULL,signe_at=NULL WHERE contrat_id=? AND ordre>?")->execute([$contractId,(int)$technicalRow['ordre']]);$this->pdo->prepare("UPDATE contrat_sous_traitance SET statut='brouillon',signature_prestataire=NULL,signe_prestataire_at=NULL WHERE id=?")->execute([$contractId]);$signatureReset=true;}}}
            }
            if($stage==='juridique')$this->pdo->prepare('UPDATE contrat_sous_traitance SET clauses_particulieres=? WHERE id=?')->execute([$data['clauses_particulieres']??null,$contractId]);
            elseif($stage==='technique'){
                ContractWorkflowPolicy::assertComplete($stage,$data);
                $start=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$data['date_debut']);$duration=(int)$data['delai_jours'];
                $this->pdo->prepare('UPDATE contrat_sous_traitance SET objet=?,date_debut=?,delai_jours=?,date_fin=? WHERE id=?')->execute([$data['objet_contrat'],$data['date_debut'],$duration,$start->modify('+'.$duration.' days')->format('Y-m-d'),$contractId]);
            }elseif($stage==='comptabilite'){
                ContractWorkflowPolicy::assertComplete($stage,$data);
                $this->pdo->prepare('UPDATE contrat_sous_traitance SET taux_acompte=?,montant_acompte=montant_ht*?/100,retenue_garantie_taux=? WHERE id=?')->execute([(float)$data['taux_acompte'],(float)$data['taux_acompte'],(float)$data['retenue_garantie'],$contractId]);
            }
            if($signatureReset)$this->logSignatureHistory('contrat',$contractId,'etape_modifiee_a_resigner',$signerId,$actorUserId,['etape'=>$stage,'etapes_reinitialisees'=>$resetStages]);
            $this->pdo->commit();
            return ['signature_reset'=>$signatureReset,'reset_stages'=>$resetStages];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    /** Délègue une étape à un collaborateur actif ; une signature antérieure est invalidée en cas de changement. */
    public function delegateWorkflowStageSigner(int $contractId,string $stage,int $personnelId,int $userId,bool $allowValidatedWithoutSignature=false): void
    {
        if(!in_array($stage,['rh','juridique','technique','logistique','qhse','comptabilite'],true)||$personnelId<1)throw new InvalidArgumentException('Étape ou signataire invalide.');
        if(!$this->workflowSignerEligible($stage,$personnelId))throw new InvalidArgumentException('Le collaborateur choisi est introuvable, inactif ou ne possède pas de compte utilisateur actif.');
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT statut,signature_banamur FROM contrat_sous_traitance WHERE id=? FOR UPDATE');$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);
            if(!$contract)throw new RuntimeException('Contrat introuvable.');
            if($contract['statut']==='resilie'||!empty($contract['signature_banamur']))throw new RuntimeException('Cette délégation n’est plus possible sur ce contrat.');
            $q=$this->pdo->prepare('SELECT signataire_personnel_id,statut,signature FROM contrat_workflow_etape WHERE contrat_id=? AND etape=? FOR UPDATE');$q->execute([$contractId,$stage]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Étape introuvable.');$previous=(int)$row['signataire_personnel_id'];
            if($previous===$personnelId){$this->pdo->commit();return;}
            if($row['statut']==='validee'&&(!$allowValidatedWithoutSignature||!empty($row['signature'])))throw new RuntimeException('Une étape déjà validée avec signature ne peut pas être déléguée.');
            $q=$this->pdo->prepare('UPDATE contrat_workflow_etape SET signataire_personnel_id=?,signature=NULL,signe_par_user_id=NULL,signe_at=NULL WHERE contrat_id=? AND etape=?');$q->execute([$personnelId,$contractId,$stage]);
            $this->logSignatureHistory('contrat',$contractId,'delegation_signataire_'.$stage,$personnelId,$userId,['etape'=>$stage,'ancien_signataire_personnel_id'=>$previous?:null]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    /** Réouverture DG avant sa propre signature : aucune migration manuelle n’est requise. */
    public function reopenForDgEdit(int $contractId,int $userId): void
    {
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT reference,statut,signature_banamur FROM contrat_sous_traitance WHERE id=? FOR UPDATE');$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);
            if(!$contract)throw new RuntimeException('Contrat introuvable.');
            if($contract['statut']==='resilie')throw new RuntimeException('Un contrat annulé ne peut pas être rouvert.');
            if(!empty($contract['signature_banamur']))throw new RuntimeException('La réouverture est bloquée après la signature du Directeur général.');
            $q=$this->pdo->prepare("UPDATE contrat_sous_traitance SET statut='a_signer',dg_edit_opened_at=NOW() WHERE id=?");$q->execute([$contractId]);
            $this->logSignatureHistory('contrat',$contractId,'reouvert_par_dg',null,$userId,['reference'=>$contract['reference']]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    /** Repart du RH en conservant les informations déjà saisies ; les six visas et la signature finale sont à refaire. */
    public function restartInternalWorkflow(int $contractId,int $userId): void
    {
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT reference,statut FROM contrat_sous_traitance WHERE id=? FOR UPDATE');$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);
            if(!$contract)throw new RuntimeException('Contrat introuvable.');
            if($contract['statut']==='resilie')throw new RuntimeException('Un contrat annulé ne peut pas être remis en édition.');
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM contrat_sous_traitance_paiement WHERE contrat_id=?');$q->execute([$contractId]);
            if((int)$q->fetchColumn()>0)throw new RuntimeException('La réouverture complète est bloquée car ce contrat possède déjà un dossier de paiement ou une FEB.');
            $q=$this->pdo->prepare("UPDATE contrat_sous_traitance SET statut='brouillon',signature_banamur=NULL,signature_prestataire=NULL,signe_banamur_at=NULL,signe_prestataire_at=NULL,dg_edit_opened_at=NOW() WHERE id=?");$q->execute([$contractId]);
            $q=$this->pdo->prepare("UPDATE contrat_workflow_etape SET statut=CASE WHEN etape='rh' THEN 'en_cours' ELSE 'en_attente' END,signature=NULL,signe_par_user_id=NULL,signe_at=NULL WHERE contrat_id=?");$q->execute([$contractId]);
            $this->logSignatureHistory('contrat',$contractId,'circuit_interne_reouvert',null,$userId,['reference'=>$contract['reference'],'motif'=>'regularisation_signatures_historiques']);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function signWorkflowStage(int $contractId,string $stage,string $signature,int $userId,int $personnelId,bool $drhOverride=false,bool $allowUnassignedSigner=false,string $expectedHash='',array $signatureProof=[]): void
    {
        ContractWorkflowPolicy::assertSignatureImage($signature);
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT statut,signature_banamur FROM contrat_sous_traitance WHERE id=? FOR UPDATE');$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);
            $contractStatus=(string)($contract['statut']??'');
            if($contractStatus==='')throw new RuntimeException('Contrat introuvable.');
            if($contractStatus==='resilie')throw new RuntimeException('Un contrat annulé ne peut plus être signé.');
            if(!empty($contract['signature_banamur']))throw new RuntimeException('Le contrat ne peut plus être signé après la signature du Directeur général.');
            $q=$this->pdo->prepare("SELECT * FROM contrat_workflow_etape WHERE contrat_id=? AND etape=? FOR UPDATE");$q->execute([$contractId,$stage]);$row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row||$expectedHash===''||!hash_equals(ContractWhatsAppCatalog::stageHash($row),$expectedHash))throw new RuntimeException('Cette étape a été modifiée. Rechargez-la et contrôlez les données avant de signer.');
            if(!$row||(!$drhOverride&&!in_array($row['statut'],['en_cours','en_attente'],true))||($drhOverride&&$row['statut']==='validee'&&!empty($row['signature'])))throw new RuntimeException('Cette étape n’est pas disponible pour signature.');
            if(!$drhOverride&&(int)$row['signataire_personnel_id']!==$personnelId&&!($allowUnassignedSigner&&(int)$row['signataire_personnel_id']===0))throw new RuntimeException('Seul le signataire désigné peut valider cette étape.');
            if(!$this->workflowSignerEligible($stage,$personnelId))throw new RuntimeException('Votre compte personnel n’est plus actif : cette étape ne peut pas être signée.');
            ContractWorkflowPolicy::assertComplete($stage,json_decode((string)$row['donnees_json'],true,512,JSON_THROW_ON_ERROR));
            if($row['statut']==='en_cours'){ $before=$this->pdo->prepare("SELECT COUNT(*) FROM contrat_workflow_etape WHERE contrat_id=? AND ordre<? AND (statut<>'validee' OR signature IS NULL OR signature='')");$before->execute([$contractId,(int)$row['ordre']]);if((int)$before->fetchColumn()>0)throw new RuntimeException('Les signatures des étapes précédentes doivent être complétées.'); }
            $effectiveSignerId=($drhOverride||($allowUnassignedSigner&&(int)$row['signataire_personnel_id']===0))?$personnelId:(int)$row['signataire_personnel_id'];
            $this->logSignatureHistory('contrat',$contractId,'visa_'.$stage,$effectiveSignerId,$userId,['etape'=>$stage,'empreinte_etape'=>$expectedHash,'passkey_credential_id'=>$signatureProof['credential_id']??null,'passkey_verified_at'=>$signatureProof['verified_at']??null,'pre_signature'=>$row['statut']==='en_attente']);
            if($row['statut']==='validee'){$q=$this->pdo->prepare("UPDATE contrat_workflow_etape SET signature=?,signe_par_user_id=?,signe_at=NOW(),signataire_personnel_id=? WHERE id=?");$q->execute([$signature,$userId,$effectiveSignerId,(int)$row['id']]);$this->pdo->commit();return;}
            if($row['statut']==='en_attente'){$q=$this->pdo->prepare("UPDATE contrat_workflow_etape SET signature=?,signe_par_user_id=?,signe_at=NOW(),signataire_personnel_id=? WHERE id=?");$q->execute([$signature,$userId,$effectiveSignerId,(int)$row['id']]);$this->pdo->commit();return;}
            if(trim((string)$row['donnees_json'])===''||$row['donnees_json']==='{}')throw new RuntimeException('Complétez et enregistrez les informations avant de transmettre cette étape.');
            $q=$this->pdo->prepare("UPDATE contrat_workflow_etape SET statut='validee',signature=?,signe_par_user_id=?,signe_at=NOW(),signataire_personnel_id=? WHERE id=?");$q->execute([$signature,$userId,$effectiveSignerId,(int)$row['id']]);
            $q=$this->pdo->prepare("UPDATE contrat_workflow_etape SET statut='en_cours' WHERE contrat_id=? AND ordre=? AND statut='en_attente'");$q->execute([$contractId,(int)$row['ordre']+1]);
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM contrat_workflow_etape WHERE contrat_id=? AND statut<>'validee'");$q->execute([$contractId]);
            if((int)$q->fetchColumn()===0){$q=$this->pdo->prepare("UPDATE contrat_sous_traitance SET statut='a_signer' WHERE id=?");$q->execute([$contractId]);}
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        if(class_exists('ContratWorkflowNotifier')){try{(new ContratWorkflowNotifier($this->pdo))->notifyNext($contractId,$stage,$userId);}catch(Throwable $notificationError){error_log('[contrats] Notification de workflow impossible : '.$notificationError->getMessage());}}
    }
    public function setFinalSigner(int $id,int $signerId,int $actor,bool $canManage):void
    {
        if(!$canManage)throw new RuntimeException('La désignation du DG est réservée aux RH et administrateurs.');
        if(!$this->workflowSignerEligible('direction',$signerId))throw new RuntimeException('Désignez un Directeur général actif lié à un compte.');
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT * FROM contrat_sous_traitance WHERE id=? FOR UPDATE');$q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);
            if(!$c||!empty($c['signature_banamur'])||!empty($c['signature_prestataire'])||!in_array($c['statut'],['brouillon','a_signer'],true))throw new RuntimeException('Le signataire ne peut plus être modifié.');
            $this->pdo->prepare('UPDATE contrat_sous_traitance SET signataire_banamur_personnel_id=? WHERE id=?')->execute([$signerId,$id]);
            $this->logSignatureHistory('contrat',$id,'signataire_designe',$signerId,$actor,['ancien_signataire_personnel_id'=>$c['signataire_banamur_personnel_id']]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function signFinal(int $id,string $party,string $signature,int $actor,int $personnelId,bool $canCaptureSupplier,string $expectedHash,array $signatureProof=[]):void
    {
        if(!in_array($party,['banamur','prestataire'],true))throw new RuntimeException('Signature invalide.');
        ContractWorkflowPolicy::assertSignatureImage($signature);
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT * FROM contrat_sous_traitance WHERE id=? FOR UPDATE');$q->execute([$id]);$c=$q->fetch(PDO::FETCH_ASSOC);
            if(!$c||$c['statut']!=='a_signer')throw new RuntimeException('Ce contrat n’est pas ouvert à la signature finale.');
            $stages=$this->workflow($id);ContractWorkflowPolicy::assertInternalSigned($stages);
            if(!hash_equals(ContractWorkflowPolicy::finalHash($c,$stages),$expectedHash))throw new RuntimeException('Le contrat a changé : rechargez et contrôlez le document avant de signer.');
            if($party==='banamur'){
                if(!empty($c['signature_banamur'])||$personnelId<1||$personnelId!==(int)$c['signataire_banamur_personnel_id']||!$this->workflowSignerEligible('direction',$personnelId))throw new RuntimeException('Seul le DG désigné peut signer ce contrat.');
            }elseif(!$canCaptureSupplier||empty($c['signature_banamur'])||!empty($c['signature_prestataire']))throw new RuntimeException('La signature du prestataire doit être recueillie par les RH après celle du DG.');
            $column=$party==='banamur'?'signature_banamur':'signature_prestataire';$date=$party==='banamur'?'signe_banamur_at':'signe_prestataire_at';
            $this->pdo->prepare("UPDATE contrat_sous_traitance SET $column=?,$date=NOW(),statut=? WHERE id=?")->execute([$signature,$party==='prestataire'?'actif':'a_signer',$id]);
            $this->logSignatureHistory('contrat',$id,'signe_'.$party,$party==='banamur'?$personnelId:null,$actor,['empreinte_document'=>$expectedHash,'passkey_credential_id'=>$signatureProof['credential_id']??null,'passkey_verified_at'=>$signatureProof['verified_at']??null,'mode'=>$party==='prestataire'?'signature_prestataire_recueillie_par_rh':'passkey_dg']);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function cancelByDrh(int $contractId,int $userId): void
    {
        $this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT reference,signature_banamur FROM contrat_sous_traitance WHERE id=? FOR UPDATE');$q->execute([$contractId]);$contract=$q->fetch(PDO::FETCH_ASSOC);
            if(!$contract)throw new RuntimeException('Contrat introuvable.');
            if(!empty($contract['signature_banamur']))throw new RuntimeException('Le contrat ne peut plus être annulé après la signature du Directeur général.');
            $q=$this->pdo->prepare("UPDATE contrat_sous_traitance SET statut='resilie' WHERE id=?");$q->execute([$contractId]);
            $this->logSignatureHistory('contrat',$contractId,'annule_par_rh',null,$userId,['reference'=>$contract['reference']]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    public function get(int $id): ?array { $s=$this->pdo->prepare("SELECT c.*,ch.code chantier_code,ch.titre chantier_titre,ch.entreprise,COALESCE(NULLIF(f.nom_commercial,''),f.raison_sociale,CONCAT(f.person_first_name,' ',f.person_last_name)) fournisseur_nom,f.telephone,f.email,f.adresse,f.ncc,f.rne FROM contrat_sous_traitance c JOIN chantier_banamur ch ON ch.id=c.chantier_id JOIN fournisseur_banamur f ON f.id_fournisseur=c.fournisseur_id WHERE c.id=?");$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return null;$q=$this->pdo->prepare('SELECT cl.*,ld.designation,ld.quantite,ld.prix,ld.total,u.symbole unite FROM contrat_sous_traitance_ligne cl JOIN ligne_devis_banamur ld ON ld.id=cl.ligne_devis_id LEFT JOIN unites_mesure u ON u.id=ld.unite_id WHERE cl.contrat_id=? ORDER BY cl.id');$q->execute([$id]);$r['lignes']=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        // Les déboursés sont relus à chaque affichage : les contrats historiques
        // récupèrent donc immédiatement les lignes créées après leur signature.
        $q=$this->pdo->prepare('SELECT db.ligne_devis_id,db.id debourse_id,ldb.id ligne_debourse_id,ldb.designation,ldb.categorie,ldb.montant,ldb.date_debut,ldb.date_fin FROM contrat_sous_traitance_ligne cl JOIN debourse_banamur db ON db.ligne_devis_id=cl.ligne_devis_id JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=db.id WHERE cl.contrat_id=? ORDER BY cl.id,db.id,ldb.id');$q->execute([$id]);$debourseByQuote=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $debourseLine)$debourseByQuote[(int)$debourseLine['ligne_devis_id']][]=$debourseLine;foreach($r['lignes'] as &$line){$line['debourse_lignes']=$debourseByQuote[(int)$line['ligne_devis_id']]??[];}unset($line);
        $q=$this->pdo->prepare("SELECT p.*,f.num_fiche,f.conforme,f.approuve,f.etat_fiche,f.decaisse,COALESCE((SELECT SUM(fd.montant_fiche) FROM fiche_decaissement fd WHERE fd.contract_payment_id=p.id AND fd.decaisse=1),CASE WHEN f.decaisse=1 THEN f.montant_fiche ELSE 0 END,0) montant_decaisse_effectif_live,(SELECT COUNT(*) FROM fiche_decaissement fd WHERE fd.contract_payment_id=p.id AND fd.decaisse=0 AND fd.approuve<>2 AND fd.etat_fiche<>2) elements_en_attente,(SELECT COUNT(*) FROM fiche_decaissement fd WHERE fd.contract_payment_id=p.id AND (fd.approuve=2 OR fd.etat_fiche=2)) elements_refuses FROM contrat_sous_traitance_paiement p LEFT JOIN fiche_decaissement f ON f.id_fiche=p.fiche_id WHERE p.contrat_id=? ORDER BY p.id");$q->execute([$id]);$r['paiements']=$q->fetchAll(PDO::FETCH_ASSOC);foreach($r['paiements'] as &$payment){$actual=(float)$payment['montant_decaisse_effectif_live'];$payment['statut_financier']=$actual>0?($actual+0.01>=(float)$payment['montant_net']?'decaisse':'partiel'):(((int)$payment['elements_refuses']>0&&(int)$payment['elements_en_attente']===0)?'refuse':'en_circuit');}unset($payment);return $r; }
    public function lockedLine(int $ligneDebourseId): ?array { $s=$this->pdo->prepare("SELECT c.id,c.reference,c.fournisseur_id,c.statut FROM ligne_debourse_banamur ldb JOIN debourse_banamur db ON db.id=ldb.debourse_id JOIN contrat_sous_traitance_ligne cl ON cl.ligne_devis_id=db.ligne_devis_id JOIN contrat_sous_traitance c ON c.id=cl.contrat_id WHERE ldb.id=? AND c.statut NOT IN ('resilie','solde') LIMIT 1");$s->execute([$ligneDebourseId]);return $s->fetch(PDO::FETCH_ASSOC)?:null; }
    public function paymentPreview(int $id,string $type,float $progress): array
    {
        $c=$this->get($id);
        if(!$c)throw new RuntimeException('Contrat introuvable.');
        if(($c['statut']??'')!=='actif')throw new RuntimeException('Le contrat doit être actif avant toute demande de paiement.');
        $total=(float)$c['montant_ht'];$advancePaid=0;$progressPaid=0;$recovered=0;$retained=0;$last=0;
        foreach($c['paiements'] as $p){
            if(empty($p['fiche_id']))throw new RuntimeException('Un dossier contractuel attend encore son PV signé et sa soumission en FEB.');
            $actual=(float)($p['montant_decaisse_effectif_live']??0);$ratio=(float)$p['montant_net']>0?min(1,$actual/(float)$p['montant_net']):0;
            if($p['type_paiement']==='acompte')$advancePaid+=$actual;else $progressPaid+=$actual;
            $recovered+=(float)$p['recuperation_acompte']*$ratio;$retained+=(float)$p['retenue_garantie']*$ratio;
            if((int)($p['elements_en_attente']??0)>0)throw new RuntimeException('Une FEB de ce contrat est encore dans le circuit de validation ou de décaissement. Traitez-la avant de créer une nouvelle demande.');
            if($actual>0)$last=max($last,(float)$p['avancement_cumule']);
        }
        $progress=max(0,min(100,$progress));
        if($type==='acompte')return ['brut'=>(float)$c['montant_acompte'],'recuperation'=>0,'retenue'=>0,'restitution'=>0,'net'=>round(max(0,(float)$c['montant_acompte']-$advancePaid),2),'previous_progress'=>$last];
        $targetGross=$total*$progress/100;$targetRecovery=min((float)$c['montant_acompte'],(float)$c['montant_acompte']*$progress/100);$targetRetention=$targetGross*(float)$c['retenue_garantie_taux']/100;$targetNet=max(0,$targetGross-$targetRecovery-$targetRetention);$brut=max(0,$targetGross-($progressPaid+$recovered+$retained));$recovery=max(0,$targetRecovery-$recovered);$retention=max(0,$targetRetention-$retained);$net=max(0,$targetNet-$progressPaid);
        $restitution=$type==='solde'?min($targetRetention,$retained+$retention):0;
        if($type==='solde')$net=max(0,$net+$restitution);
        return ['brut'=>round($brut,2),'recuperation'=>round($recovery,2),'retenue'=>round($retention,2),'restitution'=>round($restitution,2),'net'=>round($net,2),'previous_progress'=>$last];
    }
}

