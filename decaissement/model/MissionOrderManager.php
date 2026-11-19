<?php

class MissionOrderManager
{
    private PDO $pdo;

    private array $statusLabels = [
        'draft' => 'Brouillon',
        'submitted' => 'Soumis',
        'approved' => 'Valide',
        'departed' => 'Depart',
        'returned' => 'Retour',
        'closed' => 'Cloture',
        'cancelled' => 'Annule',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSupportTables();
    }

    public function getStatusLabels(): array
    {
        return $this->statusLabels;
    }

    public function listMissions(array $filters = [], int $page = 1, int $perPage = 15): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        [$whereSql, $params] = $this->buildWhereClause($filters);

        $countSql = '
            SELECT COUNT(*)
            FROM rh_mission_orders mo
            LEFT JOIN personnel p ON p.id_personnel_soignant = mo.personnel_id
            ' . $whereSql;
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;

        $sql = '
            SELECT
                mo.*, 
                p.nom_personnel_soignant AS personnel_name,
                p.matricule_personnel_soignant AS personnel_matricule,
                COALESCE(NULLIF(TRIM(s.lib_service), \'\'), \'Service non renseigne\') AS service_label,
                COALESCE(NULLIF(TRIM(f.lib_fonction), \'\'), \'Fonction non renseignee\') AS fonction_label
            FROM rh_mission_orders mo
            LEFT JOIN personnel p ON p.id_personnel_soignant = mo.personnel_id
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            ' . $whereSql . '
            ORDER BY mo.created_at DESC, mo.id DESC
            LIMIT :limit OFFSET :offset
        ';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function getMission(int $missionId): ?array
    {
        if ($missionId <= 0) {
            return null;
        }

        $sql = '
            SELECT
                mo.*, 
                p.nom_personnel_soignant AS personnel_name,
                p.matricule_personnel_soignant AS personnel_matricule,
                p.email_personnel_soignant AS personnel_email,
                COALESCE(NULLIF(TRIM(s.lib_service), \'\'), \'Service non renseigne\') AS service_label,
                COALESCE(NULLIF(TRIM(f.lib_fonction), \'\'), \'Fonction non renseignee\') AS fonction_label,
                du.signature_data AS drh_profile_signature_data,
                du.signature AS drh_profile_signature_path,
                TRIM(CONCAT(COALESCE(du.prenom, \'\'), \' \', COALESCE(du.nom, \'\'))) AS drh_profile_name,
                eu.signature_data AS employee_profile_signature_data,
                eu.signature AS employee_profile_signature_path,
                TRIM(CONCAT(COALESCE(eu.prenom, \'\'), \' \', COALESCE(eu.nom, \'\'))) AS employee_profile_name,
                pu.signature_data AS personnel_profile_signature_data,
                pu.signature AS personnel_profile_signature_path,
                au.signature_data AS approver_profile_signature_data,
                au.signature AS approver_profile_signature_path,
                TRIM(CONCAT(COALESCE(au.prenom, \'\'), \' \', COALESCE(au.nom, \'\'))) AS approver_profile_name,
                hierarchy_drh.signature_data AS hierarchy_drh_signature_data,
                hierarchy_drh.signature AS hierarchy_drh_signature_path,
                hierarchy_drh.signer_name AS hierarchy_drh_name,
                hierarchy_drh.id AS hierarchy_drh_user_id,
                default_drh.signature_data AS default_drh_signature_data,
                default_drh.signature AS default_drh_signature_path,
                default_drh.signer_name AS default_drh_name,
                default_drh.id AS default_drh_user_id,
                feb.status AS feb_status,
                feb.title AS feb_title
            FROM rh_mission_orders mo
            LEFT JOIN personnel p ON p.id_personnel_soignant = mo.personnel_id
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            LEFT JOIN user_devis_banamur du ON du.id = mo.drh_signed_by_user_id
            LEFT JOIN user_devis_banamur eu ON eu.id = mo.employee_signed_by_user_id
            LEFT JOIN user_devis_banamur pu ON pu.personnel_id = mo.personnel_id AND pu.active = 1
            LEFT JOIN user_devis_banamur au ON au.id = mo.approved_by_user_id
            LEFT JOIN (
                SELECT u.id,u.signature,u.signature_data,TRIM(CONCAT(COALESCE(u.prenom, \'\'), \' \',COALESCE(u.nom, \'\'))) signer_name
                FROM rh_service_hierarchy h
                INNER JOIN service hs ON hs.id_service = h.service_id
                INNER JOIN user_devis_banamur u ON u.id = h.contract_signer_user_id AND u.active = 1
                WHERE UPPER(COALESCE(hs.lib_service, \'\')) LIKE \'%RESSOURCES HUMAINES%\'
                ORDER BY h.updated_at DESC, h.service_id DESC
                LIMIT 1
            ) hierarchy_drh ON 1=1
            LEFT JOIN (
                SELECT u.id,u.signature,u.signature_data,TRIM(CONCAT(COALESCE(u.prenom, \'\'), \' \',COALESCE(u.nom, \'\'))) signer_name
                FROM user_devis_banamur u
                LEFT JOIN personnel dp ON dp.id_personnel_soignant=u.personnel_id
                LEFT JOIN fonction df ON df.id_fonction=dp.fonction_id
                WHERE u.active=1 AND (UPPER(COALESCE(df.lib_fonction, \'\')) LIKE \'%DIRECTEUR%RESSOURCES HUMAINES%\' OR UPPER(COALESCE(df.lib_fonction, \'\')) LIKE \'%DRH%\')
                ORDER BY u.id LIMIT 1
            ) default_drh ON 1=1
            LEFT JOIN employee_legal_requests feb ON feb.id = mo.feb_request_id AND feb.request_type = \'feb\'
            WHERE mo.id = :id
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $missionId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getMissionEvents(int $missionId): array
    {
        if ($missionId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('
            SELECT *
            FROM rh_mission_order_events
            WHERE mission_id = :mission_id
            ORDER BY event_at DESC, id DESC
        ');
        $stmt->bindValue(':mission_id', $missionId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMissionExpenses(int $missionId): array
    {
        if ($missionId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('
            SELECT *
            FROM rh_mission_order_expenses
            WHERE mission_id = :mission_id
            ORDER BY line_no ASC, id ASC
        ');
        $stmt->bindValue(':mission_id', $missionId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function replaceMissionExpenses(int $missionId, array $lines, array $actor = []): void
    {
        if ($missionId <= 0) {
            throw new RuntimeException('Mission invalide (frais).');
        }

        $mission = $this->getMission($missionId);
        if ($mission === null) {
            throw new RuntimeException('Mission introuvable (frais).');
        }

        $status = (string) ($mission['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'submitted'], true) && !$this->isMissionIncomplete($mission)) {
            throw new RuntimeException('Modification des frais interdite: la mission est deja validee ou en cours.');
        }

        $normalized = [];
        foreach ($lines as $line) {
            $designation = trim((string) ($line['designation'] ?? ''));
            if ($designation === '') {
                continue;
            }

            $unit = trim((string) ($line['unit'] ?? ''));
            $quantity = (float) str_replace(',', '.', (string) ($line['quantity'] ?? 0));
            $unitPrice = (float) str_replace(',', '.', (string) ($line['unit_price'] ?? 0));
            $quantity = max(0.0, $quantity);
            $unitPrice = max(0.0, $unitPrice);
            $lineTotal = round($quantity * $unitPrice, 2);

            $normalized[] = [
                'designation' => $designation,
                'unit' => $unit,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $total = 0.0;
        foreach ($normalized as $line) {
            $total += (float) ($line['line_total'] ?? 0);
        }
        $total = round($total, 2);

        $this->pdo->beginTransaction();

        $deleteStmt = $this->pdo->prepare('DELETE FROM rh_mission_order_expenses WHERE mission_id = :mission_id');
        $deleteStmt->bindValue(':mission_id', $missionId, PDO::PARAM_INT);
        $deleteStmt->execute();

        if (!empty($normalized)) {
            $insertStmt = $this->pdo->prepare('
                INSERT INTO rh_mission_order_expenses (
                    mission_id,
                    line_no,
                    designation,
                    unit,
                    quantity,
                    unit_price,
                    line_total
                ) VALUES (
                    :mission_id,
                    :line_no,
                    :designation,
                    :unit,
                    :quantity,
                    :unit_price,
                    :line_total
                )
            ');

            $lineNo = 1;
            foreach ($normalized as $line) {
                $insertStmt->bindValue(':mission_id', $missionId, PDO::PARAM_INT);
                $insertStmt->bindValue(':line_no', $lineNo, PDO::PARAM_INT);
                $insertStmt->bindValue(':designation', $line['designation']);
                $insertStmt->bindValue(':unit', $line['unit'] !== '' ? $line['unit'] : null, $line['unit'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $insertStmt->bindValue(':quantity', (string) $line['quantity']);
                $insertStmt->bindValue(':unit_price', (string) $line['unit_price']);
                $insertStmt->bindValue(':line_total', (string) $line['line_total']);
                $insertStmt->execute();
                $lineNo++;
            }
        }

        $touchStmt = $this->pdo->prepare('
            UPDATE rh_mission_orders
            SET updated_at = CURRENT_TIMESTAMP,
                updated_by_user_id = :actor_user_id,
                updated_by_name = :actor_name
            WHERE id = :id
        ');
        $actorUserId = $actor['user_id'] ?? null;
        $actorName = $this->normalizeNullableString($actor['user_name'] ?? null);
        $touchStmt->bindValue(':actor_user_id', $actorUserId, $actorUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $touchStmt->bindValue(':actor_name', $actorName, $actorName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $touchStmt->bindValue(':id', $missionId, PDO::PARAM_INT);
        $touchStmt->execute();

        $this->pdo->commit();

        $this->insertEvent($missionId, 'expenses_updated', 'Mise a jour des frais', [
            'total' => $total,
            'lines' => count($normalized),
        ], $actor);
    }

    public function createMission(array $data, array $actor = []): int
    {
        $payload = $this->normalizeMissionPayload($data);

        if ($payload['personnel_id'] <= 0) {
            throw new RuntimeException('Le collaborateur est obligatoire pour creer un ordre de mission.');
        }

        if ($payload['mission_title'] === '') {
            throw new RuntimeException('L\'objet (titre) de la mission est obligatoire.');
        }

        if ($payload['start_date'] === null) {
            throw new RuntimeException('La date de debut prevue est obligatoire.');
        }

        if ($payload['end_date'] === null) {
            throw new RuntimeException('La date de fin prevue est obligatoire.');
        }

        if ($payload['end_date'] < $payload['start_date']) {
            throw new RuntimeException('La date de fin prevue doit etre >= a la date de debut prevue.');
        }

        if ($payload['link_type'] === 'chantier' && $payload['chantier_id'] <= 0) {
            throw new RuntimeException('Veuillez selectionner un chantier pour la liaison chantier.');
        }

        if ($payload['link_type'] === 'autre' && $payload['link_label'] === '') {
            throw new RuntimeException('Veuillez preciser la nature (autre) de la mission.');
        }

        if ($payload['link_type'] === 'chantier' && $payload['chantier_id'] <= 0) {
            throw new RuntimeException('Veuillez selectionner un chantier pour la liaison chantier.');
        }

        if ($payload['link_type'] === 'autre' && $payload['link_label'] === '') {
            throw new RuntimeException('Veuillez preciser la nature (autre) de la mission.');
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO rh_mission_orders (
                mission_code,
                personnel_id,
                status,
                mission_title,
                mission_purpose,
                destination,
                link_type,
                chantier_id,
                link_label,
                start_date,
                end_date,
                notes,
                created_by_user_id,
                created_by_name,
                updated_by_user_id,
                updated_by_name
            ) VALUES (
                NULL,
                :personnel_id,
                :status,
                :mission_title,
                :mission_purpose,
                :destination,
                :link_type,
                :chantier_id,
                :link_label,
                :start_date,
                :end_date,
                :notes,
                :created_by_user_id,
                :created_by_name,
                :updated_by_user_id,
                :updated_by_name
            )
        ');

        $stmt->bindValue(':personnel_id', $payload['personnel_id'], PDO::PARAM_INT);
        $stmt->bindValue(':status', 'draft');
        $stmt->bindValue(':mission_title', $payload['mission_title']);
        $stmt->bindValue(':mission_purpose', $payload['mission_purpose'] !== '' ? $payload['mission_purpose'] : null, $payload['mission_purpose'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':destination', $payload['destination'] !== '' ? $payload['destination'] : null, $payload['destination'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':link_type', $payload['link_type'] !== '' ? $payload['link_type'] : null, $payload['link_type'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':chantier_id', $payload['chantier_id'] > 0 ? $payload['chantier_id'] : null, $payload['chantier_id'] > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':link_label', $payload['link_label'] !== '' ? $payload['link_label'] : null, $payload['link_label'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':start_date', $payload['start_date']);
        $stmt->bindValue(':end_date', $payload['end_date']);
        $stmt->bindValue(':notes', $payload['notes'] !== '' ? $payload['notes'] : null, $payload['notes'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $stmt->bindValue(':created_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $createdByName = $this->normalizeNullableString($actor['user_name'] ?? null);
        $stmt->bindValue(':created_by_name', $createdByName, $createdByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $stmt->bindValue(':updated_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $updatedByName = $this->normalizeNullableString($actor['user_name'] ?? null);
        $stmt->bindValue(':updated_by_name', $updatedByName, $updatedByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $stmt->execute();
        $missionId = (int) $this->pdo->lastInsertId();
        if ($missionId <= 0) {
            throw new RuntimeException('Echec creation ordre de mission.');
        }

        $missionCode = $this->buildMissionCode($missionId);
        $updateStmt = $this->pdo->prepare('UPDATE rh_mission_orders SET mission_code = :code WHERE id = :id');
        $updateStmt->bindValue(':code', $missionCode);
        $updateStmt->bindValue(':id', $missionId, PDO::PARAM_INT);
        $updateStmt->execute();

        $this->insertEvent($missionId, 'created', 'Creation du brouillon', null, $actor);

        return $missionId;
    }

    public function updateMission(int $missionId, array $data, array $actor = []): bool
    {
        if ($missionId <= 0) {
            return false;
        }

        $mission = $this->getMission($missionId);
        if ($mission === null) {
            return false;
        }

        $status = (string) ($mission['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'submitted'], true) && !$this->isMissionIncomplete($mission)) {
            throw new RuntimeException('Modification interdite: la mission est deja validee ou en cours.');
        }

        $payload = $this->normalizeMissionPayload($data);

        if ($payload['personnel_id'] <= 0) {
            throw new RuntimeException('Le collaborateur est obligatoire.');
        }
        if ($payload['mission_title'] === '') {
            throw new RuntimeException('L\'objet (titre) est obligatoire.');
        }
        if ($payload['start_date'] === null || $payload['end_date'] === null) {
            throw new RuntimeException('Les dates prevues (debut/fin) sont obligatoires.');
        }
        if ($payload['end_date'] < $payload['start_date']) {
            throw new RuntimeException('La date de fin prevue doit etre >= a la date de debut prevue.');
        }

        $stmt = $this->pdo->prepare('
            UPDATE rh_mission_orders
            SET
                personnel_id = :personnel_id,
                mission_title = :mission_title,
                mission_purpose = :mission_purpose,
                destination = :destination,
                link_type = :link_type,
                chantier_id = :chantier_id,
                link_label = :link_label,
                start_date = :start_date,
                end_date = :end_date,
                notes = :notes,
                updated_by_user_id = :updated_by_user_id,
                updated_by_name = :updated_by_name,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');

        $stmt->bindValue(':personnel_id', $payload['personnel_id'], PDO::PARAM_INT);
        $stmt->bindValue(':mission_title', $payload['mission_title']);
        $stmt->bindValue(':mission_purpose', $payload['mission_purpose'] !== '' ? $payload['mission_purpose'] : null, $payload['mission_purpose'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':destination', $payload['destination'] !== '' ? $payload['destination'] : null, $payload['destination'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':link_type', $payload['link_type'] !== '' ? $payload['link_type'] : null, $payload['link_type'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':chantier_id', $payload['chantier_id'] > 0 ? $payload['chantier_id'] : null, $payload['chantier_id'] > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':link_label', $payload['link_label'] !== '' ? $payload['link_label'] : null, $payload['link_label'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':start_date', $payload['start_date']);
        $stmt->bindValue(':end_date', $payload['end_date']);
        $stmt->bindValue(':notes', $payload['notes'] !== '' ? $payload['notes'] : null, $payload['notes'] !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $stmt->bindValue(':updated_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $updatedByName = $this->normalizeNullableString($actor['user_name'] ?? null);
        $stmt->bindValue(':updated_by_name', $updatedByName, $updatedByName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':id', $missionId, PDO::PARAM_INT);

        $ok = $stmt->execute();
        if ($ok) {
            $this->insertEvent($missionId, 'updated', 'Mise a jour du brouillon', null, $actor);
        }

        return (bool) $ok;
    }

    public function submitMission(int $missionId, array $actor = []): bool
    {
        $mission=$this->getMission($missionId);
        if(!$mission) throw new RuntimeException('Ordre de mission introuvable.');
        // La nouvelle règle s'applique aux soumissions effectuées à compter du 24/08/2026.
        if(date('Y-m-d')>='2026-08-24') $this->assertFinancialAllocation(array_merge($mission,['submitted_at'=>date('Y-m-d H:i:s')]));
        return $this->transition($missionId, 'submitted', 'Soumission', [
            'from' => ['draft'],
            'set' => [
                'submitted_at = CURRENT_TIMESTAMP',
                'submitted_by_user_id = :actor_user_id',
                'submitted_by_name = :actor_name',
            ],
        ], $actor);
    }

    public function approveMission(int $missionId, array $actor = []): bool
    {
        $mission = $this->getMission($missionId);
        if (!$mission) {
            throw new RuntimeException('Ordre de mission introuvable.');
        }
        if (trim((string)($mission['drh_signature_data'] ?? '')) === '') {
            throw new RuntimeException('La signature du DRH est obligatoire avant la validation.');
        }
        $this->assertFinancialAllocation($mission);
        return $this->transition($missionId, 'approved', 'Validation', [
            'from' => ['submitted'],
            'set' => [
                'approved_at = CURRENT_TIMESTAMP',
                'approved_by_user_id = :actor_user_id',
                'approved_by_name = :actor_name',
            ],
        ], $actor);
    }

    public function canReopenApprovedMission(array $mission): bool
    {
        if((string)($mission['status']??'')!=='approved')return false;
        // Les dossiers historiques peuvent recevoir leur signature aujourd'hui :
        // c'est leur incomplétude actuelle, et non leur ancienne date, qui autorise la reprise.
        if((int)($mission['feb_request_id']??0)<=0)return true;
        if(trim((string)($mission['drh_signature_data']??''))==='')return true;
        if(trim((string)($mission['employee_signature_data']??''))==='')return true;
        foreach(['chantier_id','ligne_devis_id','debourse_id','ligne_debourse_id','planning_id','fournisseur_id'] as $key)if((int)($mission[$key]??0)<=0)return true;
        return false;
    }

    public function isMissionIncomplete(array $mission): bool
    {
        if(trim((string)($mission['drh_signature_data']??''))==='')return true;
        if(trim((string)($mission['employee_signature_data']??''))==='')return true;
        foreach(['chantier_id','ligne_devis_id','debourse_id','ligne_debourse_id','planning_id','fournisseur_id']as$key)if((int)($mission[$key]??0)<=0)return true;
        return false;
    }

    public function reopenApprovedMission(int $missionId,array $actor=[]): bool
    {
        $mission=$this->getMission($missionId);if(!$mission)throw new RuntimeException('Ordre de mission introuvable.');
        if(!$this->canReopenApprovedMission($mission))throw new RuntimeException('Seuls les ordres validés mais encore incomplets peuvent être rouverts.');
        $stmt=$this->pdo->prepare("UPDATE rh_mission_orders SET status='submitted',approved_at=NULL,approved_by_user_id=NULL,approved_by_name=NULL,updated_at=NOW(),updated_by_user_id=:user,updated_by_name=:name WHERE id=:id AND status='approved'");
        $stmt->execute(['user'=>$actor['user_id']??null,'name'=>$actor['user_name']??null,'id'=>$missionId]);
        if(!$stmt->rowCount())throw new RuntimeException('La réouverture de cet ordre a échoué.');
        $this->insertEvent($missionId,'reopened_for_completion','Réouverture pour compléter et soumettre à nouveau',['previous_status'=>'approved','signature_preserved'=>trim((string)($mission['drh_signature_data']??''))!==''],$actor);
        return true;
    }

    public function saveFinancialAllocation(int $missionId, array $data, array $actor = []): void
    {
        $mission = $this->getMission($missionId);
        if (!$mission) throw new RuntimeException('Ordre de mission introuvable.');
        if (!in_array((string)$mission['status'], ['draft','submitted','approved'], true) && !$this->isMissionIncomplete($mission)) {
            throw new RuntimeException('Les détails financiers ne peuvent plus être modifiés à cette étape.');
        }
        $ids=[]; foreach (['chantier_id','ligne_devis_id','debourse_id','ligne_debourse_id','planning_id','fournisseur_id'] as $key) $ids[$key]=max(0,(int)($data[$key]??0));
        if ($ids['chantier_id'] > 0) {
            $check=$this->pdo->prepare("SELECT COUNT(*) FROM chantier_banamur c JOIN ligne_devis_banamur ld ON ld.devis_id=c.devis_id JOIN debourse_banamur d ON d.ligne_devis_id=ld.id JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=d.id LEFT JOIN planning_chantier_projects p ON p.chantier_id=c.id WHERE c.id=:chantier AND ld.id=:ligne_devis AND d.id=:debourse AND ldb.id=:ligne_debourse AND (:planning=0 OR p.id=:planning)");
            $check->execute(['chantier'=>$ids['chantier_id'],'ligne_devis'=>$ids['ligne_devis_id'],'debourse'=>$ids['debourse_id'],'ligne_debourse'=>$ids['ligne_debourse_id'],'planning'=>$ids['planning_id']]);
            if (!(int)$check->fetchColumn()) throw new RuntimeException('La chaîne financière sélectionnée est incohérente.');
        }
        if ($ids['fournisseur_id'] > 0) {
            $s=$this->pdo->prepare("SELECT COUNT(*) FROM fournisseur_banamur WHERE id_fournisseur=:id AND actif=1 AND validation_status='approved'");$s->execute(['id'=>$ids['fournisseur_id']]);
            if (!(int)$s->fetchColumn()) throw new RuntimeException('Le fournisseur doit être actif et validé.');
        }
        $sql='UPDATE rh_mission_orders SET chantier_id=:chantier_id,ligne_devis_id=:ligne_devis_id,debourse_id=:debourse_id,ligne_debourse_id=:ligne_debourse_id,planning_id=:planning_id,fournisseur_id=:fournisseur_id,financial_completed_at=IF(:complete=1,NOW(),NULL),updated_at=NOW() WHERE id=:id';
        $stmt=$this->pdo->prepare($sql);$stmt->execute($ids+['complete'=>(int)!in_array(0,$ids,true),'id'=>$missionId]);
        $this->insertEvent($missionId,'financial_allocation_updated','Affectation financière mise à jour',$ids,$actor);
    }

    public function signMission(int $missionId, string $role, string $mode, string $drawnData, array $actor, bool $asPo=false): void
    {
        if (!in_array($role,['drh','employee'],true)) throw new RuntimeException('Rôle de signature invalide.');
        $userId=(int)($actor['user_id']??0); if($userId<=0) throw new RuntimeException('Utilisateur non identifié.');
        $mode=in_array($mode,['profile','drawn'],true)?$mode:'drawn';
        $signature=$mode==='profile'?$this->profileSignatureData($userId):trim($drawnData);
        if(!preg_match('#^data:image/(png|jpeg);base64,#',$signature)) throw new RuntimeException('Signature électronique invalide ou absente.');
        $prefix=$role==='drh'?'drh':'employee';
        $stmt=$this->pdo->prepare("UPDATE rh_mission_orders SET {$prefix}_signature_data=:signature,{$prefix}_signed_by_user_id=:user_id,{$prefix}_signed_by_name=:name,{$prefix}_signature_mode=:mode,{$prefix}_signed_at=NOW(),employee_signed_po=IF(:employee=1,:po,employee_signed_po),updated_at=NOW() WHERE id=:id");
        $stmt->execute(['signature'=>$signature,'user_id'=>$userId,'name'=>$actor['user_name']??'Utilisateur','mode'=>$asPo?'po_'.$mode:$mode,'employee'=>$role==='employee'?1:0,'po'=>$asPo?1:0,'id'=>$missionId]);
        // Une signature dessinée devient également la signature de profil du
        // signataire. Elle reste ainsi visible dans Profil et réutilisable.
        if($mode==='drawn')$this->pdo->prepare('UPDATE user_devis_banamur SET signature_data=:signature WHERE id=:id')->execute(['signature'=>$signature,'id'=>$userId]);
        $this->insertEvent($missionId,$prefix.'_signed',$role==='drh'?'Signature du DRH':'Signature du collaborateur'.($asPo?' (PO)':''),['mode'=>$mode,'po'=>$asPo],$actor);
    }

    public function requestPoSignature(int $missionId, int $signerUserId, string $role, array $actor): void
    {
        if($signerUserId<=0||!in_array($role,['drh','employee'],true)) throw new RuntimeException('Signataire PO invalide.');
        $stmt=$this->pdo->prepare("INSERT INTO rh_mission_signature_requests(mission_id,role,requested_user_id,requested_by_user_id,requested_by_name,status) VALUES(:mission,:role,:user,:by,:name,'pending') ON DUPLICATE KEY UPDATE requested_by_user_id=VALUES(requested_by_user_id),requested_by_name=VALUES(requested_by_name),status='pending',signed_at=NULL,updated_at=NOW()");
        $stmt->execute(['mission'=>$missionId,'role'=>$role,'user'=>$signerUserId,'by'=>$actor['user_id']??null,'name'=>$actor['user_name']??null]);
        $this->insertEvent($missionId,'signature_requested','Demande de signature PO transmise',['role'=>$role,'requested_user_id'=>$signerUserId],$actor);
    }

    public function getSignatureRequests(int $missionId): array
    { $s=$this->pdo->prepare("SELECT r.*,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) signer_name FROM rh_mission_signature_requests r LEFT JOIN user_devis_banamur u ON u.id=r.requested_user_id WHERE r.mission_id=:id ORDER BY r.id DESC");$s->execute(['id'=>$missionId]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; }

    public function assignedSignatures(int $userId): array
    { $s=$this->pdo->prepare("SELECT r.*,r.status request_status,mo.mission_code,mo.mission_title,mo.destination,mo.start_date,mo.end_date,mo.status mission_status,mo.personnel_id,p.nom_personnel_soignant personnel_name FROM rh_mission_signature_requests r JOIN rh_mission_orders mo ON mo.id=r.mission_id LEFT JOIN personnel p ON p.id_personnel_soignant=mo.personnel_id WHERE r.requested_user_id=:user ORDER BY r.created_at DESC");$s->execute(['user'=>$userId]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[]; }

    public function signAssignedRequest(int $requestId,string $mode,string $data,array $actor): void
    { $s=$this->pdo->prepare("SELECT * FROM rh_mission_signature_requests WHERE id=:id AND requested_user_id=:user AND status='pending'");$s->execute(['id'=>$requestId,'user'=>(int)($actor['user_id']??0)]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Demande de signature introuvable ou déjà traitée.');$this->signMission((int)$r['mission_id'],(string)$r['role'],$mode,$data,$actor,true);$this->pdo->prepare("UPDATE rh_mission_signature_requests SET status='signed',signed_at=NOW() WHERE id=:id")->execute(['id'=>$requestId]); }

    public function financialChoices(): array
    {
        $rows=$this->pdo->query("SELECT c.id chantier_id,c.code chantier_code,c.titre chantier_title,ld.id ligne_devis_id,ld.designation ligne_devis_label,d.id debourse_id,ldb.id ligne_debourse_id,ldb.designation ligne_debourse_label,p.id planning_id,p.libelle planning_label FROM chantier_banamur c JOIN ligne_devis_banamur ld ON ld.devis_id=c.devis_id JOIN debourse_banamur d ON d.ligne_devis_id=ld.id JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=d.id LEFT JOIN planning_chantier_projects p ON p.chantier_id=c.id AND p.statut<>'archive' WHERE c.archived_at IS NULL ORDER BY c.code,ld.designation,ldb.designation LIMIT 10000")->fetchAll(PDO::FETCH_ASSOC)?:[];
        return $rows;
    }

    public function activeSuppliers(): array
    { return $this->pdo->query("SELECT id_fournisseur id,COALESCE(NULLIF(TRIM(nom_commercial),''),raison_sociale) label,code_fournisseur code FROM fournisseur_banamur WHERE actif=1 AND validation_status='approved' ORDER BY label LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC)?:[]; }

    public function activeUsers(): array
    { return $this->pdo->query("SELECT id,TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) label,mail_pro FROM user_devis_banamur WHERE active=1 ORDER BY prenom,nom")->fetchAll(PDO::FETCH_ASSOC)?:[]; }

    public function isOfficialDrhSigner(int $userId): bool
    {
        if($userId<=0)return false;
        try{$s=$this->pdo->prepare("SELECT COUNT(*) FROM rh_service_hierarchy h JOIN service sv ON sv.id_service=h.service_id WHERE h.contract_signer_user_id=:user AND UPPER(COALESCE(sv.lib_service,'')) LIKE '%RESSOURCES HUMAINES%'");$s->execute(['user'=>$userId]);if((int)$s->fetchColumn()>0)return true;}catch(Throwable $e){}
        $s=$this->pdo->prepare("SELECT COUNT(*) FROM user_devis_banamur u LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id WHERE u.id=:user AND u.active=1 AND (UPPER(COALESCE(f.lib_fonction,'')) LIKE '%DIRECTEUR%RESSOURCES HUMAINES%' OR UPPER(COALESCE(f.lib_fonction,'')) LIKE '%DRH%')");$s->execute(['user'=>$userId]);return(int)$s->fetchColumn()>0;
    }

    public function createFebForApprovedMission(int $missionId, string $proofPath, array $actor): int
    {
        $mission=$this->getMission($missionId); if(!$mission) throw new RuntimeException('Ordre de mission introuvable.');
        if(!in_array((string)$mission['status'],['approved','departed','returned','closed'],true)) throw new RuntimeException('La FEB ne peut être créée qu’après validation DRH.');
        if((int)($mission['feb_request_id']??0)>0) return (int)$mission['feb_request_id'];
        $this->assertFinancialAllocation($mission);
        require_once dirname(__DIR__,2).'/model/EmployeeLegalRequestManager.php';
        $supplier=$this->pdo->prepare("SELECT COALESCE(NULLIF(TRIM(nom_commercial),''),raison_sociale) FROM fournisseur_banamur WHERE id_fournisseur=:id");$supplier->execute(['id'=>(int)$mission['fournisseur_id']]);$supplierName=(string)$supplier->fetchColumn();
        $totalStmt=$this->pdo->prepare('SELECT COALESCE(SUM(line_total),0) FROM rh_mission_order_expenses WHERE mission_id=:id');$totalStmt->execute(['id'=>$missionId]);$total=(float)$totalStmt->fetchColumn();
        $userId=(int)($actor['user_id']??0);$u=$this->pdo->prepare("SELECT TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) name,mail_pro email FROM user_devis_banamur WHERE id=:id");$u->execute(['id'=>$userId]);$requester=$u->fetch(PDO::FETCH_ASSOC)?:['name'=>$actor['user_name']??'DRH','email'=>''];
        $drhUserId=(int)($mission['hierarchy_drh_user_id']??0);if($drhUserId<=0)$drhUserId=(int)($mission['default_drh_user_id']??0);if($drhUserId<=0&&$this->isOfficialDrhSigner((int)($mission['drh_signed_by_user_id']??0)))$drhUserId=(int)$mission['drh_signed_by_user_id'];if($drhUserId<=0)throw new RuntimeException('Aucun Directeur des Ressources humaines bénéficiaire n’est configuré.');
        $b=$this->pdo->prepare("SELECT TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) name,u.personnel_id,p.matricule_personnel_soignant matricule,COALESCE(f.lib_fonction,'Directeur des Ressources humaines') fonction,COALESCE(s.lib_service,'Ressources humaines') service FROM user_devis_banamur u LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id LEFT JOIN service s ON s.id_service=p.service_id WHERE u.id=:id AND u.active=1");$b->execute(['id'=>$drhUserId]);$beneficiary=$b->fetch(PDO::FETCH_ASSOC);if(!$beneficiary)throw new RuntimeException('Le profil du DRH bénéficiaire est introuvable ou inactif.');
        $proof=['name'=>'Ordre de mission '.($mission['mission_code']??$missionId).'.pdf','path'=>$proofPath,'url'=>$proofPath,'type'=>'application/pdf'];
        $payload=['nom'=>$beneficiary['name'],'personnel_id'=>(int)($beneficiary['personnel_id']??0),'mission_collaborateur_nom'=>$mission['personnel_name']??'','mission_collaborateur_id'=>(int)$mission['personnel_id'],'matricule'=>$beneficiary['matricule']??'','fonction'=>$beneficiary['fonction']??'Directeur des Ressources humaines','service_employe'=>$beneficiary['service']??'Ressources humaines','description'=>'Frais de mission · '.($mission['mission_title']??'').' · Collaborateur : '.($mission['personnel_name']??''),'montant'=>$total,'initial_requested_amount'=>$total,'date_livraison'=>$mission['start_date'],'chantier_id'=>(int)$mission['chantier_id'],'budget_planned'=>'yes','budget_planned_label'=>'Prévue au déboursé','planned_start_date'=>$mission['start_date'],'planned_end_date'=>$mission['end_date'],'fournisseur_id'=>(int)$mission['fournisseur_id'],'global_fournisseur_id'=>(int)$mission['fournisseur_id'],'global_fournisseur_nom'=>$supplierName,'ligne_devis_id'=>(int)$mission['ligne_devis_id'],'debourse_id'=>(int)$mission['debourse_id'],'ligne_debourse_id'=>(int)$mission['ligne_debourse_id'],'planning_id'=>(int)$mission['planning_id'],'source'=>'ordre_mission_rh','mission_order_id'=>$missionId,'mission_order_code'=>$mission['mission_code'],'beneficiary_role'=>'drh','beneficiary_user_id'=>$drhUserId,'global_justificatif'=>$proof,'justificatifs'=>[$proof],'besoins'=>[['libelle'=>'Frais de mission · '.($mission['mission_title']??''),'designation'=>'Frais de mission','categorie'=>'RH','quantite'=>1,'prix_unitaire'=>$total,'montant'=>$total,'fournisseur_id'=>(int)$mission['fournisseur_id'],'justificatif_devis'=>$proof]]];
        $manager=new EmployeeLegalRequestManager($this->pdo);$febId=$manager->createRequest($userId,$requester,'feb',$payload,'FEB · '.($mission['mission_code']??$missionId).' · '.($mission['mission_title']??''));
        $this->pdo->prepare('UPDATE rh_mission_orders SET feb_request_id=:feb,updated_at=NOW() WHERE id=:id')->execute(['feb'=>$febId,'id'=>$missionId]);
        $this->insertEvent($missionId,'feb_created','FEB générée et positionnée en validation',['feb_request_id'=>$febId],$actor);return $febId;
    }

    public function normalizeMissionFebForRegeneration(int $missionId,array $actor=[]): ?int
    {
        $mission=$this->getMission($missionId);if(!$mission)throw new RuntimeException('Ordre de mission introuvable.');
        $febId=(int)($mission['feb_request_id']??0);if($febId<=0)return null;
        $startedHere=!$this->pdo->inTransaction();if($startedHere)$this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare("SELECT id,status,request_type FROM employee_legal_requests WHERE id=:id FOR UPDATE");$s->execute(['id'=>$febId]);$feb=$s->fetch(PDO::FETCH_ASSOC);
            if(!$feb||($feb['request_type']??'')!=='feb')throw new RuntimeException('La FEB liée à cet ordre est introuvable.');
            $status=(string)($feb['status']??'');
            if($status==='approved')throw new RuntimeException('Normalisation impossible : la FEB liée est déjà approuvée. Elle doit être traitée manuellement.');
            $linked=$this->pdo->prepare('SELECT COUNT(*) FROM fiche_decaissement WHERE feb_request_id=:id');$linked->execute(['id'=>$febId]);if((int)$linked->fetchColumn()>0)throw new RuntimeException('Normalisation impossible : la FEB est déjà rattachée à une fiche de décaissement.');
            $note='Annulation automatique : normalisation post-validation de l’ordre de mission '.($mission['mission_code']??('#'.$missionId)).'.';
            if($status==='submitted'){
                $this->pdo->prepare("UPDATE employee_legal_requests SET status='cancelled',current_approval_stage=NULL,cancelled_at=NOW(),decision_note=:note,updated_at=NOW() WHERE id=:id AND status='submitted'")->execute(['note'=>$note,'id'=>$febId]);
                $this->pdo->prepare("INSERT INTO employee_legal_request_events(request_id,event_type,event_note,event_by_user_id,event_by_name,created_at) VALUES(:request,'cancelled',:note,:user,:name,NOW())")->execute(['request'=>$febId,'note'=>$note,'user'=>$actor['user_id']??null,'name'=>$actor['user_name']??null]);
            }elseif(!in_array($status,['cancelled','rejected'],true))throw new RuntimeException('Normalisation impossible au statut actuel de la FEB ('.$status.').');
            $this->pdo->prepare('UPDATE rh_mission_orders SET feb_request_id=NULL,updated_at=NOW(),updated_by_user_id=:user,updated_by_name=:name WHERE id=:id')->execute(['user'=>$actor['user_id']??null,'name'=>$actor['user_name']??null,'id'=>$missionId]);
            $this->insertEvent($missionId,'feb_normalized','FEB précédente annulée pour normalisation post-validation',['cancelled_feb_request_id'=>$febId,'previous_status'=>$status],$actor);
            if($startedHere)$this->pdo->commit();return$febId;
        }catch(Throwable $e){if($startedHere&&$this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    private function profileSignatureData(int $userId): string
    {
        $s=$this->pdo->prepare('SELECT signature_data,signature FROM user_devis_banamur WHERE id=:id');
        $s->execute(['id'=>$userId]);$profile=$s->fetch(PDO::FETCH_ASSOC)?:[];
        $data=trim((string)($profile['signature_data']??''));
        if(preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,#i',$data))return $data;
        $path=trim((string)($profile['signature']??''));if($path==='')return'';
        $root=realpath(dirname(__DIR__,2));if(!$root)return'';
        $urlPath=parse_url($path,PHP_URL_PATH);if(is_string($urlPath)&&$urlPath!=='')$path=rawurldecode($urlPath);
        $relative=ltrim(str_replace('\\','/',$path),'/');$marker=basename($root).'/';$position=strpos($relative,$marker);if($position!==false)$relative=substr($relative,$position+strlen($marker));$base=basename($relative);
        foreach(array_unique([$root.'/'.$relative,$root.'/uploads/user_signatures/'.$base,$root.'/decaissement/uploads/signatures/'.$base,$root.'/uploads/signatures/'.$base,$root.'/signatures/'.$base])as$candidate){$file=realpath($candidate);if(!$file||!str_starts_with(str_replace('\\','/',$file),rtrim(str_replace('\\','/',$root),'/').'/')||!is_file($file)||!is_readable($file)||filesize($file)>2*1024*1024)continue;$binary=@file_get_contents($file);$info=is_string($binary)?@getimagesizefromstring($binary):false;$mime=is_array($info)?(string)($info['mime']??''):'';if(!is_string($binary)||!in_array($mime,['image/png','image/jpeg','image/webp'],true))continue;$data='data:'.$mime.';base64,'.base64_encode($binary);try{$this->pdo->prepare('UPDATE user_devis_banamur SET signature_data=:data WHERE id=:id')->execute(['data'=>$data,'id'=>$userId]);}catch(Throwable $e){}return$data;}
        return'';
    }

    private function assertFinancialAllocation(array $mission): void
    {
        foreach(['chantier_id','ligne_devis_id','debourse_id','ligne_debourse_id','planning_id','fournisseur_id'] as $key)if((int)($mission[$key]??0)<=0)throw new RuntimeException('Complétez toute l’affectation financière et le fournisseur avant la validation DRH.');
        $check=$this->pdo->prepare("SELECT COUNT(*) FROM chantier_banamur c JOIN ligne_devis_banamur ld ON ld.devis_id=c.devis_id JOIN debourse_banamur d ON d.ligne_devis_id=ld.id JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=d.id JOIN planning_chantier_projects p ON p.chantier_id=c.id LEFT JOIN fournisseur_banamur f ON f.id_fournisseur=:fournisseur WHERE c.id=:chantier AND ld.id=:ligne_devis AND d.id=:debourse AND ldb.id=:ligne_debourse AND p.id=:planning AND f.id_fournisseur IS NOT NULL AND f.actif=1 AND f.validation_status='approved'");$check->execute(['chantier'=>(int)$mission['chantier_id'],'ligne_devis'=>(int)$mission['ligne_devis_id'],'debourse'=>(int)$mission['debourse_id'],'ligne_debourse'=>(int)$mission['ligne_debourse_id'],'planning'=>(int)$mission['planning_id'],'fournisseur'=>(int)$mission['fournisseur_id']]);if(!(int)$check->fetchColumn())throw new RuntimeException('L’affectation financière est incohérente : vérifiez le chantier, le planning, les lignes budgétaires et le fournisseur.');
    }

    public function markDeparture(int $missionId, array $actor = []): bool
    {
        return $this->transition($missionId, 'departed', 'Depart mission', [
            'from' => ['approved'],
            'set' => [
                'departure_at = CURRENT_TIMESTAMP',
            ],
        ], $actor);
    }

    public function markReturn(int $missionId, array $actor = []): bool
    {
        return $this->transition($missionId, 'returned', 'Retour mission', [
            'from' => ['departed'],
            'set' => [
                'return_at = CURRENT_TIMESTAMP',
            ],
        ], $actor);
    }

    public function closeMission(int $missionId, array $actor = []): bool
    {
        return $this->transition($missionId, 'closed', 'Cloture', [
            'from' => ['returned'],
            'set' => [
                'closed_at = CURRENT_TIMESTAMP',
            ],
        ], $actor);
    }

    public function cancelMission(int $missionId, array $actor = []): bool
    {
        return $this->transition($missionId, 'cancelled', 'Annulation', [
            // Un ordre peut être annulé tant qu'il n'est pas définitivement
            // clôturé. L'événement conserve son ancien statut dans l'historique.
            'from' => ['draft', 'submitted', 'approved', 'departed', 'returned'],
            'set' => [
                'cancelled_at = CURRENT_TIMESTAMP',
            ],
        ], $actor);
    }

    private function transition(int $missionId, string $toStatus, string $eventTitle, array $rules, array $actor): bool
    {
        if ($missionId <= 0) {
            return false;
        }

        $mission = $this->getMission($missionId);
        if ($mission === null) {
            return false;
        }

        $fromStatus = (string) ($mission['status'] ?? 'draft');
        $allowedFrom = $rules['from'] ?? [];
        if (!in_array($fromStatus, $allowedFrom, true)) {
            throw new RuntimeException('Transition invalide (' . $fromStatus . ' -> ' . $toStatus . ').');
        }

        $sets = $rules['set'] ?? [];
        $setSql = array_merge([
            'status = :status',
            'updated_at = CURRENT_TIMESTAMP',
            'updated_by_user_id = :actor_user_id',
            'updated_by_name = :actor_name',
        ], $sets);

        $stmt = $this->pdo->prepare('UPDATE rh_mission_orders SET ' . implode(', ', $setSql) . ' WHERE id = :id');
        $stmt->bindValue(':status', $toStatus);
        $stmt->bindValue(':id', $missionId, PDO::PARAM_INT);

        $actorUserId = $actor['user_id'] ?? null;
        $actorName = $this->normalizeNullableString($actor['user_name'] ?? null);
        $stmt->bindValue(':actor_user_id', $actorUserId, $actorUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':actor_name', $actorName, $actorName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $ok = $stmt->execute();
        if ($ok) {
            $this->insertEvent($missionId, 'status:' . $toStatus, $eventTitle, [
                'from' => $fromStatus,
                'to' => $toStatus,
            ], $actor);
        }

        return (bool) $ok;
    }

    private function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $params = [];

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $conditions[] = '(mo.mission_code LIKE :q OR mo.mission_title LIKE :q OR mo.destination LIKE :q OR p.nom_personnel_soignant LIKE :q OR p.matricule_personnel_soignant LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        $status = trim((string) ($filters['status'] ?? ''));
        $statuses=(array)($filters['statuses']??($status!==''?[$status]:[]));$statuses=array_values(array_filter(array_unique(array_map('strval',$statuses)),fn($v)=>isset($this->statusLabels[$v])));$this->appendInFilter($conditions,$params,'mo.status','status',$statuses);

        $this->appendInFilter($conditions,$params,'mo.chantier_id','chantier',array_values(array_filter(array_map('intval',(array)($filters['chantier_ids']??[])))));
        $this->appendInFilter($conditions,$params,'mo.personnel_id','personnel',array_values(array_filter(array_map('intval',(array)($filters['personnel_ids']??[])))));
        $this->appendInFilter($conditions,$params,'mo.approved_by_user_id','validator',array_values(array_filter(array_map('intval',(array)($filters['validator_ids']??[])))));

        $personnelId = (int) ($filters['personnel_id'] ?? 0);
        if ($personnelId > 0) {
            $conditions[] = 'mo.personnel_id = :personnel_id';
            $params[':personnel_id'] = $personnelId;
        }

        $from = trim((string) ($filters['from'] ?? ''));
        $to = trim((string) ($filters['to'] ?? ''));
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $conditions[] = 'mo.start_date >= :from';
            $params[':from'] = $from;
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $conditions[] = 'mo.end_date <= :to';
            $params[':to'] = $to;
        }

        $whereSql = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        // Join personnel only when needed for q clause.
        // For simplicity (and to keep one query), we always keep joins in list query.
        return [$whereSql, $params];
    }

    private function appendInFilter(array &$conditions,array &$params,string $column,string $prefix,array $values): void
    { if(!$values)return;$holders=[];foreach(array_values(array_unique($values)) as $i=>$value){$key=':'.$prefix.'_'.$i;$holders[]=$key;$params[$key]=$value;}$conditions[]=$column.' IN ('.implode(',',$holders).')'; }

    private function normalizeMissionPayload(array $data): array
    {
        $personnelId = (int) ($data['personnel_id'] ?? 0);

        $missionTitle = trim((string) ($data['mission_title'] ?? ''));
        $missionPurpose = trim((string) ($data['mission_purpose'] ?? ''));
        $destination = trim((string) ($data['destination'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        $linkType = trim((string) ($data['link_type'] ?? ''));
        if (!in_array($linkType, ['', 'chantier', 'prospection', 'autre'], true)) {
            $linkType = '';
        }

        $chantierId = (int) ($data['chantier_id'] ?? 0);
        $linkLabel = trim((string) ($data['link_label'] ?? ''));

        if ($linkType !== 'chantier') {
            $chantierId = 0;
        }

        if ($linkType === 'chantier') {
            $linkLabel = '';
        }

        $startDate = $this->normalizeDate((string) ($data['start_date'] ?? ''));
        $endDate = $this->normalizeDate((string) ($data['end_date'] ?? ''));

        return [
            'personnel_id' => $personnelId,
            'mission_title' => $missionTitle,
            'mission_purpose' => $missionPurpose,
            'destination' => $destination,
            'link_type' => $linkType,
            'chantier_id' => $chantierId,
            'link_label' => $linkLabel,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'notes' => $notes,
        ];
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '' || $date === '0000-00-00') {
            return null;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        return $date;
    }

    private function normalizeNullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function buildMissionCode(int $missionId): string
    {
        return 'OM-' . date('Ym') . '-' . str_pad((string) $missionId, 5, '0', STR_PAD_LEFT);
    }

    private function insertEvent(int $missionId, string $eventType, string $title, ?array $meta, array $actor): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO rh_mission_order_events (
                mission_id,
                event_type,
                title,
                metadata_json,
                created_by_user_id,
                created_by_name
            ) VALUES (
                :mission_id,
                :event_type,
                :title,
                :metadata_json,
                :created_by_user_id,
                :created_by_name
            )
        ');

        $stmt->bindValue(':mission_id', $missionId, PDO::PARAM_INT);
        $stmt->bindValue(':event_type', $eventType);
        $stmt->bindValue(':title', $title);

        $metadataJson = $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt->bindValue(':metadata_json', $metadataJson, $metadataJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $actorUserId = $actor['user_id'] ?? null;
        $actorName = $this->normalizeNullableString($actor['user_name'] ?? null);
        $stmt->bindValue(':created_by_user_id', $actorUserId, $actorUserId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $stmt->bindValue(':created_by_name', $actorName, $actorName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $stmt->execute();
    }

    private function ensureSupportTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_mission_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mission_code VARCHAR(32) NULL UNIQUE,
                personnel_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'draft\',
                mission_title VARCHAR(200) NOT NULL,
                mission_purpose TEXT NULL,
                destination VARCHAR(200) NULL,
                link_type VARCHAR(20) NULL,
                chantier_id INT NULL,
                link_label VARCHAR(200) NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                submitted_at DATETIME NULL,
                submitted_by_user_id INT NULL,
                submitted_by_name VARCHAR(150) NULL,
                approved_at DATETIME NULL,
                approved_by_user_id INT NULL,
                approved_by_name VARCHAR(150) NULL,
                departure_at DATETIME NULL,
                return_at DATETIME NULL,
                closed_at DATETIME NULL,
                cancelled_at DATETIME NULL,
                notes TEXT NULL,
                created_by_user_id INT NULL,
                created_by_name VARCHAR(150) NULL,
                updated_by_user_id INT NULL,
                updated_by_name VARCHAR(150) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rh_mission_orders_personnel (personnel_id),
                KEY idx_rh_mission_orders_status (status),
                KEY idx_rh_mission_orders_dates (start_date, end_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_mission_order_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mission_id INT NOT NULL,
                event_type VARCHAR(50) NOT NULL,
                title VARCHAR(200) NOT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                created_by_user_id INT NULL,
                created_by_name VARCHAR(150) NULL,
                event_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rh_mission_events_mission (mission_id),
                KEY idx_rh_mission_events_type (event_type),
                KEY idx_rh_mission_events_date (event_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_mission_order_expenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mission_id INT NOT NULL,
                line_no INT NOT NULL DEFAULT 1,
                designation VARCHAR(255) NOT NULL,
                unit VARCHAR(50) NULL,
                quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
                unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_rh_mission_expenses_mission (mission_id),
                KEY idx_rh_mission_expenses_line (mission_id, line_no)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        // Best-effort: si la table existait deja avant l'ajout des colonnes de liaison.
        $this->ensureColumn('rh_mission_orders', 'link_type', "ALTER TABLE rh_mission_orders ADD COLUMN link_type VARCHAR(20) NULL AFTER destination");
        $this->ensureColumn('rh_mission_orders', 'chantier_id', "ALTER TABLE rh_mission_orders ADD COLUMN chantier_id INT NULL AFTER link_type");
        $this->ensureColumn('rh_mission_orders', 'link_label', "ALTER TABLE rh_mission_orders ADD COLUMN link_label VARCHAR(200) NULL AFTER chantier_id");
        foreach ([
            'ligne_devis_id'=>'INT NULL','debourse_id'=>'INT NULL','ligne_debourse_id'=>'INT NULL','planning_id'=>'INT NULL','fournisseur_id'=>'INT NULL','feb_request_id'=>'INT NULL','financial_completed_at'=>'DATETIME NULL',
            'drh_signature_data'=>'MEDIUMTEXT NULL','drh_signed_by_user_id'=>'INT NULL','drh_signed_by_name'=>'VARCHAR(150) NULL','drh_signature_mode'=>'VARCHAR(30) NULL','drh_signed_at'=>'DATETIME NULL',
            'employee_signature_data'=>'MEDIUMTEXT NULL','employee_signed_by_user_id'=>'INT NULL','employee_signed_by_name'=>'VARCHAR(150) NULL','employee_signature_mode'=>'VARCHAR(30) NULL','employee_signed_at'=>'DATETIME NULL','employee_signed_po'=>'TINYINT(1) NOT NULL DEFAULT 0'
        ] as $column=>$definition) $this->ensureColumn('rh_mission_orders',$column,"ALTER TABLE rh_mission_orders ADD COLUMN {$column} {$definition}");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rh_mission_signature_requests(id INT AUTO_INCREMENT PRIMARY KEY,mission_id INT NOT NULL,role ENUM('drh','employee') NOT NULL,requested_user_id INT NOT NULL,requested_by_user_id INT NULL,requested_by_name VARCHAR(150) NULL,status ENUM('pending','signed','cancelled') NOT NULL DEFAULT 'pending',signed_at DATETIME NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_mission_role_user(mission_id,role,requested_user_id),KEY idx_signer(requested_user_id,status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :col");
        $stmt->bindValue(':col', $column);
        $stmt->execute();
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        if (!$this->columnExists($table, $column)) {
            $this->pdo->exec($alterSql);
        }
    }
}
