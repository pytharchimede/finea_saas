<?php

require_once __DIR__ . '/../repositories/RecurringNeedRepository.php';
require_once __DIR__ . '/../../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../../model/NotificationManager.php';
require_once __DIR__ . '/../../services/DisbursementPlanningIntegrityService.php';

if (is_file(__DIR__ . '/../../repositories/FournisseurRepository.php')) {
    require_once __DIR__ . '/../../repositories/FournisseurRepository.php';
}

if (is_file(__DIR__ . '/../../model/AuditTrail.php')) {
    require_once __DIR__ . '/../../model/AuditTrail.php';
}

class RecurringNeedService
{
    private PDO $pdo;
    private RecurringNeedRepository $repo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        DisbursementPlanningIntegrityService::ensure($pdo);
        $this->repo = new RecurringNeedRepository($pdo);
        $this->normalizeExistingTemplates();
        $this->normalizeExistingGeneratedFebPlanning();
    }

    /** Migration applicative automatique des modèles récurrents créés avant le fournisseur global. */
    private function normalizeExistingTemplates(): void
    {
        try {
            $rows=$this->pdo->query("SELECT id,fournisseur_id,fournisseur_name,feb_template_json FROM finea_recurring_needs WHERE feb_template_json IS NOT NULL AND feb_template_json<>''")->fetchAll(PDO::FETCH_ASSOC)?:[];
            $update=$this->pdo->prepare('UPDATE finea_recurring_needs SET feb_template_json=:payload,updated_at=NOW() WHERE id=:id');
            foreach($rows as$row){
                $template=json_decode((string)$row['feb_template_json'],true);if(!is_array($template)||($template['recurring_template_schema']??0)>=3)continue;
                $first=is_array(($template['besoins']??[])[0]??null)?(array)$template['besoins'][0]:[];$supplier=is_array($first['fournisseur']??null)?(array)$first['fournisseur']:[];
                $supplierId=(int)($template['global_fournisseur_id']??$row['fournisseur_id']??$supplier['id']??0);
                $supplierName=trim((string)($template['global_fournisseur_nom']??$row['fournisseur_name']??$supplier['nom']??''));
                $template['global_fournisseur_id']=$supplierId;$template['global_fournisseur_nom']=$supplierName;
                if(!isset($template['global_justificatif']))$template['global_justificatif']=is_array($first['justificatif_devis']??null)?(array)$first['justificatif_devis']:[];
                $template['beneficiary_choice']=(string)($template['beneficiary_choice']??($supplierId>0?'supplier':'requester'));
                $budgetMode=(string)($template['budget_planned']??'');
                if(!in_array($budgetMode,['yes','no'],true))$budgetMode=!empty($template['proposed_debourse'])?'no':(((int)($template['debourse_id']??0)>0||(int)($template['ligne_debourse_id']??0)>0)?'yes':'no');
                $template['budget_planned']=$budgetMode;
                $template['budget_planned_label']=$budgetMode==='yes'?'Prévue au déboursé':'Non prévue au déboursé - proposition à négocier';
                $template['recurring_template_schema']=3;
                $update->execute(['payload'=>json_encode($template,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>(int)$row['id']]);
            }
        } catch(Throwable $ignored) {}
    }

    /** Régularise une fois les FEB récurrentes historiques sans toucher aux paiements. */
    private function normalizeExistingGeneratedFebPlanning(): void
    {
        $migration='recurring_generated_feb_planning_v1_20260809';
        try{
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS finea_data_migrations(migration_key VARCHAR(190) PRIMARY KEY,applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,details_json LONGTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $check=$this->pdo->prepare('SELECT 1 FROM finea_data_migrations WHERE migration_key=:key LIMIT 1');$check->execute(['key'=>$migration]);if($check->fetchColumn())return;
            $rows=$this->pdo->query("SELECT r.id request_id,r.payload_json,n.* FROM employee_legal_requests r JOIN finea_recurring_needs n ON n.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.recurring_need_id')) AS UNSIGNED) WHERE r.request_type='feb' AND JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.source'))='recurring_needs'")->fetchAll(PDO::FETCH_ASSOC)?:[];
            $updateRequest=$this->pdo->prepare('UPDATE employee_legal_requests SET title=:title,payload_json=:payload,updated_at=NOW() WHERE id=:id');
            $updateFiche=$this->pdo->prepare('UPDATE fiche_decaissement SET planned_start_date=:start,planned_end_date=:end,date_decaissement_minimum=COALESCE(date_decaissement_minimum,:due),updated_at=NOW() WHERE feb_request_id=:feb');
            $normalized=0;
            foreach($rows as$row){$payload=json_decode((string)$row['payload_json'],true)?:[];$due=trim((string)($payload['scheduled_due_date']??$payload['planned_disbursement_date']??$payload['date_livraison']??''));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due))continue;$period=$this->occurrencePeriod($row,$due);$periodTitle=trim((string)($row['title']??'Besoin récurrent')).' — '.$period['label'];
                $payload['planned_start_date']=$period['start'];$payload['planned_end_date']=$period['end'];$payload['planned_disbursement_date']=$due;$payload['feb_quote_title']=$periodTitle;$payload['occurrence_period']=$period;
                foreach((array)($payload['besoins']??[])as$i=>$line)if(is_array($line)){$payload['besoins'][$i]['planned_start_date']=$period['start'];$payload['besoins'][$i]['planned_end_date']=$period['end'];}
                if(is_array($payload['provisional_decaissement']??null)){$payload['provisional_decaissement']['planned_start_date']=$period['start'];$payload['provisional_decaissement']['planned_end_date']=$period['end'];$payload['provisional_decaissement']['date_decaissement_minimum']=$due;}
                if(is_array($payload['proposed_debourse']??null)){$payload['proposed_debourse']['title']=$periodTitle;$payload['proposed_debourse']['planned_start_date']=$period['start'];$payload['proposed_debourse']['planned_end_date']=$period['end'];foreach((array)($payload['proposed_debourse']['lines']??[])as$i=>$line)if(is_array($line)){$payload['proposed_debourse']['lines'][$i]['planned_start_date']=$period['start'];$payload['proposed_debourse']['lines'][$i]['planned_end_date']=$period['end'];}}
                $updateRequest->execute(['title'=>'FEB AUTO: '.$periodTitle,'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>(int)$row['request_id']]);$updateFiche->execute(['start'=>$period['start'],'end'=>$period['end'],'due'=>$due,'feb'=>(int)$row['request_id']]);
                $link=$this->pdo->prepare('SELECT debourse_id,ligne_debourse_id FROM feb_disbursement_links WHERE feb_request_id=:feb LIMIT 1');$link->execute(['feb'=>(int)$row['request_id']]);$budget=$link->fetch(PDO::FETCH_ASSOC)?:[];$debourseId=(int)($budget['debourse_id']??0);$lineId=(int)($budget['ligne_debourse_id']??0);
                if($debourseId>0){$this->pdo->prepare('UPDATE debourse_banamur SET date_debut=CASE WHEN date_debut IS NULL OR date_debut>:start THEN :start2 ELSE date_debut END,date_fin=CASE WHEN date_fin IS NULL OR date_fin<:end THEN :end2 ELSE date_fin END,motif_modif=:reason WHERE id=:id')->execute(['start'=>$period['start'],'start2'=>$period['start'],'end'=>$period['end'],'end2'=>$period['end'],'reason'=>'Planning régularisé depuis la FEB récurrente #'.(int)$row['request_id'],'id'=>$debourseId]);if($lineId>0)$this->pdo->prepare('UPDATE ligne_debourse_banamur SET date_debut=CASE WHEN date_debut IS NULL OR date_debut>:start THEN :start2 ELSE date_debut END,date_fin=CASE WHEN date_fin IS NULL OR date_fin<:end THEN :end2 ELSE date_fin END WHERE id=:id AND debourse_id=:debourse')->execute(['start'=>$period['start'],'start2'=>$period['start'],'end'=>$period['end'],'end2'=>$period['end'],'id'=>$lineId,'debourse'=>$debourseId]);}
                $normalized++;
            }
            $insert=$this->pdo->prepare('INSERT INTO finea_data_migrations(migration_key,details_json) VALUES(:key,:details)');$insert->execute(['key'=>$migration,'details'=>json_encode(['normalized_febs'=>$normalized])]);
        }catch(Throwable $ignored){}
    }

    public function dashboard(int $userId, bool $canSeeAll, array $filters = []): array
    {
        $filters['user_id'] = $userId;
        $filters['all'] = $canSeeAll;
        $listFilters=$filters;unset($listFilters['due_from'],$listFilters['due_to']);$items=$this->repo->list($listFilters);
        $from=trim((string)($filters['due_from']??''));$to=trim((string)($filters['due_to']??''));if($from!==''||$to!==''){$from=$from!==''?$from:'1900-01-01';$to=$to!==''?$to:'2999-12-31';$items=array_values(array_filter($items,fn(array$need):bool=>$this->hasOccurrenceBetween($need,$from,$to)));}
        return [
            'stats' => $this->repo->stats($userId, $canSeeAll),
            'items' => $items,
            'generations' => $this->repo->recentGenerations($userId, $canSeeAll),
            'filter_options' => $this->repo->filterOptions($userId, $canSeeAll),
            'exposure' => $this->exposureSummary($userId,$canSeeAll),
        ];
    }

    private function hasOccurrenceBetween(array$need,string$from,string$to):bool
    {
        if($to<$from)[$from,$to]=[$to,$from];$start=trim((string)($need['start_date']??$need['next_due_date']??''));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start))return false;$cursor=new DateTimeImmutable($start);$end=trim((string)($need['end_date']??''));$max=(int)($need['max_occurrences']??0);$index=0;$guard=0;while($cursor->format('Y-m-d')<$from&&$guard++<1500){$cursor=$this->computeNextDueDate($need,$cursor);$index++;}$date=$cursor->format('Y-m-d');return $date<=$to&&($end===''||$date<=$end)&&($max<=0||$index<$max);
    }

    private function exposureSummary(int$userId,bool$canSeeAll):array
    {
        $active=$this->repo->list(['user_id'=>$userId,'all'=>$canSeeAll,'status'=>'active']);$today=new DateTimeImmutable('today');$weekStart=$today->modify('monday this week');$weekEnd=$weekStart->modify('+6 days');$monthStart=$today->modify('first day of this month');$monthEnd=$today->modify('last day of this month');$quarterMonth=((int)floor(((int)$today->format('n')-1)/3)*3)+1;$quarterStart=$today->setDate((int)$today->format('Y'),$quarterMonth,1);$quarterEnd=$quarterStart->modify('+3 months -1 day');$yearStart=$today->setDate((int)$today->format('Y'),1,1);$yearEnd=$today->setDate((int)$today->format('Y'),12,31);
        $windows=['today'=>[$today,$today],'week'=>[$weekStart,$weekEnd],'month'=>[$monthStart,$monthEnd],'quarter'=>[$quarterStart,$quarterEnd],'year'=>[$yearStart,$yearEnd]];$result=['global'=>['amount'=>0.0,'count'=>count($active),'from'=>'','to'=>'']];foreach($windows as$key=>[$from,$to])$result[$key]=['amount'=>0.0,'count'=>0,'from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d')];
        foreach($active as$need){$amount=(float)($need['amount_estimated']??0);$result['global']['amount']+=$amount;$start=trim((string)($need['start_date']??$need['next_due_date']??''));if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start))continue;$end=trim((string)($need['end_date']??''));$max=(int)($need['max_occurrences']??0);foreach($windows as$key=>[$from,$to]){$cursor=new DateTimeImmutable($start);$index=0;$guard=0;while($cursor<$from&&$guard++<1000){$cursor=$this->computeNextDueDate($need,$cursor);$index++;}while($cursor<=$to&&$guard++<1500){$due=$cursor->format('Y-m-d');if(($end!==''&&$due>$end)||($max>0&&$index>=$max))break;$result[$key]['amount']+=$amount;$result[$key]['count']++;$cursor=$this->computeNextDueDate($need,$cursor);$index++;}}}
        return $result;
    }

    public function createFromRequest(array $post, int $userId, string $userName, string $userEmail = ''): int
    {
        $data = $this->buildNeedDataFromPost($post, $userId, $userName, $userEmail, true);
        $id = $this->repo->create($data);

        $this->audit('recurring_need_created', [
            'entity_id' => (string)$id,
            'entity_label' => $data['title'],
            'details' => [
                'requester_user_id' => $userId,
                'amount_estimated' => $data['amount_estimated'],
                'frequency_type' => $data['frequency_type'],
                'next_due_date' => $data['next_due_date'],
            ],
        ]);

        return $id;
    }

    public function syncFromFebRequest(int $requestId, array $payload, int $userId, string $userName, string $userEmail = ''): ?array
    {
        if ($requestId <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('FEB source invalide.');
        }

        $sourceRequestId = (int)($payload['source_feb_request_id'] ?? 0);
        if ($sourceRequestId <= 0) {
            $sourceRequestId = $requestId;
        }
        $frequency = trim((string)($payload['frequence'] ?? 'unique'));
        $existing = $this->repo->findBySourceFebRequestId($sourceRequestId);
        if ($frequency === 'unique') {
            if ($existing && !in_array((string)$existing['status'], ['cancelled', 'expired'], true)) {
                $this->repo->updateStatus((int)$existing['id'], 'cancelled', $userId, 'La FEB source est désormais à fréquence unique.');
            }
            return null;
        }

        $frequencyType = $this->frequencyFromFeb($frequency);
        $currentDue = $this->validDate((string)($payload['date_livraison'] ?? date('Y-m-d')), 'Date de livraison FEB invalide.');
        $nextDue = $this->computeNextDueDate(['frequency_type' => $frequencyType, 'frequency_interval' => 1], $currentDue);
        $supplier = $this->firstSupplierFromFebPayload($payload);
        $needData = [
            'title' => trim((string)($payload['description'] ?? '')) ?: ('FEB récurrente #' . $requestId),
            'description' => trim((string)($payload['description'] ?? '')),
            'requester_user_id' => $userId,
            'requester_name' => $userName,
            'requester_email' => $userEmail,
            'source_feb_request_id' => $sourceRequestId,
            'departement_id' => (int)($payload['departement_id'] ?? 0) ?: null,
            'departement_label' => trim((string)($payload['departement_label'] ?? '')),
            'chantier_id' => (int)($payload['provisional_decaissement']['chantier_id'] ?? 0) ?: null,
            'fournisseur_id' => $supplier['id'],
            'fournisseur_name' => $supplier['name'],
            'need_type' => (string)($payload['feb_need_scope'] ?? 'internal') === 'external' ? 'externe' : 'interne',
            'financial_mode' => match ((string)($payload['cashflow_need'] ?? '')) {
                'internal_purchase_order' => 'decaissement_unique',
                'provisional_disbursement' => 'avance_provisoire',
                default => 'sans_decaissement',
            },
            'amount_estimated' => max(0, (float)($payload['montant'] ?? 0)),
            'currency' => 'XOF',
            'priority' => 'normale',
            'frequency_type' => $frequencyType,
            'frequency_interval' => 1,
            'day_of_month' => null,
            'day_of_week' => null,
            'start_date' => $currentDue->format('Y-m-d'),
            'end_date' => null,
            'next_due_date' => $nextDue->format('Y-m-d'),
            'generate_days_before' => 7,
            'reminder_days_before' => 10,
            'max_occurrences' => null,
            'status' => 'active',
            'feb_template' => array_replace($payload, [
                'source' => 'recurring_needs',
                'source_feb_request_id' => $sourceRequestId,
                'auto_generated' => false,
            ]),
            'created_by' => $userId,
        ];

        if ($existing) {
            $this->repo->update((int)$existing['id'], $needData, $userId);
            $this->repo->updateStatus((int)$existing['id'], 'active', $userId);
            $needId = (int)$existing['id'];
            $created = false;
        } else {
            $needId = $this->repo->create($needData);
            $created = true;
        }
        $need = $this->repo->find($needId) ?: ['id' => $needId, 'reference' => ''];
        $title = $created ? 'Besoin récurrent créé' : 'Besoin récurrent mis à jour';
        $message = 'La FEB sera générée automatiquement 7 jour(s) avant la prochaine échéance du '
            . $nextDue->format('d/m/Y') . '. Vous pourrez la modifier ou l’annuler depuis Mes demandes.';
        $this->repo->createNotification($needId, null, $userId, $created ? 'created_from_feb' : 'updated_from_feb', $title, $message);
        $this->notifyUser($userId, 'created', $title, $message, $this->appLink('/recurring_needs/index.php'), [
            'recurring_need_id' => $needId,
            'source_feb_request_id' => $sourceRequestId,
        ]);

        return $need + ['created_now' => $created, 'next_due_date' => $nextDue->format('Y-m-d')];
    }

    public function findForUser(int $id, int $userId, bool $canSeeAll = false): ?array
    {
        $need = $this->repo->find($id);
        if (!$need) {
            return null;
        }
        if (!$canSeeAll && (int)($need['requester_user_id'] ?? 0) !== $userId) {
            return null;
        }
        return $need;
    }

    public function updateFromRequest(int $id, array $post, int $userId, bool $canSeeAll = false): void
    {
        $existing = $this->findForUser($id, $userId, $canSeeAll);
        if (!$existing) {
            throw new RuntimeException('Besoin récurrent introuvable ou non autorisé.');
        }

        $data = $this->buildNeedDataFromPost(
            $post,
            (int)($existing['requester_user_id'] ?? $userId),
            (string)($existing['requester_name'] ?? ''),
            (string)($existing['requester_email'] ?? ''),
            false,
            (string)($existing['status'] ?? 'pending_validation')
        );

        $this->repo->update($id, $data, $userId);

        $this->audit('recurring_need_updated', [
            'entity_id' => (string)$id,
            'entity_label' => $data['title'],
            'details' => [
                'updated_by' => $userId,
                'amount_estimated' => $data['amount_estimated'],
                'frequency_type' => $data['frequency_type'],
                'next_due_date' => $data['next_due_date'],
            ],
        ]);
    }

    private function buildNeedDataFromPost(array $post, int $userId, string $userName, string $userEmail = '', bool $isCreate = true, string $currentStatus = 'pending_validation'): array
    {
        $title = trim((string)($post['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Le libellé du besoin est obligatoire.');
        }

        $start = $this->validDate((string)($post['start_date'] ?? date('Y-m-d')), 'Date de début invalide.');
        $firstDue = $this->validDate((string)($post['next_due_date'] ?? $start->format('Y-m-d')), 'Première échéance invalide.');
        if ($firstDue < $start) {
            $firstDue = $start;
        }

        $amount = $this->money((string)($post['amount_estimated'] ?? '0'));
        if ($amount <= 0) {
            throw new InvalidArgumentException('Veuillez renseigner un montant estimatif supérieur à 0.');
        }

        $status = $currentStatus;
        if ($isCreate) {
            $status = !empty($post['save_as_draft']) ? 'draft' : 'pending_validation';
        } elseif (in_array($currentStatus, ['draft', 'pending_validation'], true)) {
            $status = !empty($post['save_as_draft']) ? 'draft' : 'pending_validation';
        }

        $fournisseurId = (int)($post['fournisseur_id'] ?? 0);
        $fournisseurName = $this->resolveSupplierName($fournisseurId);
        if ($fournisseurName === '') {
            $fournisseurName = trim((string)($post['fournisseur_name'] ?? ''));
        }

        return [
            'title' => $title,
            'description' => trim((string)($post['description'] ?? '')),
            'requester_user_id' => $userId,
            'requester_name' => $userName,
            'requester_email' => $userEmail,
            'chantier_id' => $post['chantier_id'] ?? null,
            'fournisseur_id' => $fournisseurId > 0 ? $fournisseurId : null,
            'fournisseur_name' => $fournisseurName,
            'need_type' => $this->in((string)($post['need_type'] ?? 'interne'), ['interne','externe'], 'interne'),
            'financial_mode' => $this->in((string)($post['financial_mode'] ?? 'avance_provisoire'), ['avance_provisoire','decaissement_unique','assignation_logistique','sans_decaissement'], 'avance_provisoire'),
            'amount_estimated' => $amount,
            'currency' => 'XOF',
            'priority' => $this->in((string)($post['priority'] ?? 'normale'), ['basse','normale','haute','critique'], 'normale'),
            'frequency_type' => $this->in((string)($post['frequency_type'] ?? 'monthly'), ['daily','weekly','monthly','quarterly','semesterly','yearly','custom'], 'monthly'),
            'frequency_interval' => max(1, (int)($post['frequency_interval'] ?? 1)),
            'day_of_month' => $post['day_of_month'] ?? null,
            'day_of_week' => $post['day_of_week'] ?? null,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => trim((string)($post['end_date'] ?? '')),
            'next_due_date' => $firstDue->format('Y-m-d'),
            'generate_days_before' => (int)($post['generate_days_before'] ?? 7),
            'reminder_days_before' => (int)($post['reminder_days_before'] ?? 10),
            'max_occurrences' => $post['max_occurrences'] ?? null,
            'status' => $status,
            'feb_template' => [
                'source' => 'recurring_needs',
                'title' => $title,
                'description' => trim((string)($post['description'] ?? '')),
                'amount_estimated' => $amount,
                'need_type' => $post['need_type'] ?? 'interne',
                'financial_mode' => $post['financial_mode'] ?? 'avance_provisoire',
                'priority' => $post['priority'] ?? 'normale',
                'fournisseur_id' => $fournisseurId > 0 ? $fournisseurId : null,
                'fournisseur_name' => $fournisseurName,
            ],
            'created_by' => $userId,
        ];
    }

    public function approve(int $id, int $userId): void
    {
        $this->repo->updateStatus($id, 'active', $userId);
        $this->audit('recurring_need_approved', [
            'entity_id' => (string)$id,
            'details' => ['approved_by' => $userId],
        ]);
    }

    public function suspend(int $id, int $userId, string $reason = ''): void
    {
        $this->repo->updateStatus($id, 'suspended', $userId, $reason ?: 'Suspension manuelle');
        $this->audit('recurring_need_suspended', [
            'entity_id' => (string)$id,
            'details' => ['suspended_by' => $userId, 'reason' => $reason],
        ]);
    }

    public function cancel(int $id, int $userId, bool $canManageAll = false): void
    {
        $need = $this->findForUser($id, $userId, $canManageAll);
        if (!$need) {
            throw new RuntimeException('Besoin récurrent introuvable ou non autorisé.');
        }
        $this->repo->updateStatus($id, 'cancelled', $userId, 'Annulation manuelle');
        $this->audit('recurring_need_cancelled', [
            'entity_id' => (string)$id,
            'details' => ['cancelled_by' => $userId],
        ]);
    }

    public function restore(int $id,int $userId,bool $canManageAll=false): void
    {
        $need=$this->findForUser($id,$userId,$canManageAll);if(!$need||!in_array((string)($need['status']??''),['cancelled','expired'],true))throw new RuntimeException('Récurrence archivée introuvable ou non autorisée.');
        $today=new DateTimeImmutable('today');$next=$this->validDate((string)($need['next_due_date']??$today->format('Y-m-d')),'Échéance de restauration invalide.');$guard=0;while($next<$today&&$guard++<500)$next=$this->computeNextDueDate($need,$next);
        $stmt=$this->pdo->prepare("UPDATE finea_recurring_needs SET status='active',next_due_date=:next,end_date=CASE WHEN status='expired' AND end_date<CURDATE() THEN NULL ELSE end_date END,updated_by=:actor,validated_by=:actor,validated_at=NOW(),suspended_by=NULL,suspended_at=NULL,suspension_reason=NULL WHERE id=:id");$stmt->execute(['next'=>$next->format('Y-m-d'),'actor'=>$userId,'id'=>$id]);
        $this->audit('recurring_need_restored',['entity_id'=>(string)$id,'details'=>['restored_by'=>$userId,'next_due_date'=>$next->format('Y-m-d')]]);
    }


    public function runReminders(DateTimeImmutable $today): array
    {
        $results = [];

        foreach ($this->repo->dueForReminder($today) as $need) {
            $needId = (int)($need['id'] ?? 0);
            $dueDate = (string)($need['next_due_date'] ?? '');

            if ($needId <= 0 || $dueDate === '') {
                continue;
            }

            if ($this->repo->reminderSentToday($needId, $dueDate, $today)) {
                $results[] = [
                    'need_id' => $needId,
                    'reference' => (string)($need['reference'] ?? ''),
                    'status' => 'skipped',
                    'message' => 'Rappel déjà envoyé aujourd’hui pour cette échéance.',
                ];
                continue;
            }

            $daysBefore = (int)($need['reminder_days_before'] ?? 0);
            $title = 'Rappel besoin récurrent';
            $message = 'Le besoin récurrent ' . (string)($need['reference'] ?? '')
                . ' arrive à échéance ' . $dueDate
                . '. La FEB sera générée automatiquement ' . (int)($need['generate_days_before'] ?? 7)
                . ' jour(s) avant cette échéance.';

            $this->repo->createNotification(
                $needId,
                null,
                (int)($need['requester_user_id'] ?? 0),
                'reminder_due',
                $title,
                $message
            );

            $this->audit('recurring_need_reminder_sent', [
                'entity_id' => (string)$needId,
                'entity_label' => (string)($need['title'] ?? ''),
                'details' => [
                    'recurring_need_reference' => (string)($need['reference'] ?? ''),
                    'scheduled_due_date' => $dueDate,
                    'reminder_days_before' => $daysBefore,
                ],
            ]);

            $results[] = [
                'need_id' => $needId,
                'reference' => (string)($need['reference'] ?? ''),
                'status' => 'sent',
                'due_date' => $dueDate,
            ];
        }

        return $results;
    }

    public function runAutomaticGeneration(DateTimeImmutable $today): array
    {
        $results = [];
        foreach ($this->repo->dueForGeneration($today) as $need) {
            $dueDate = (string)$need['next_due_date'];

            if ($this->repo->generationExists((int)$need['id'], $dueDate)) {
                $results[] = [
                    'need_id' => (int)$need['id'],
                    'reference' => (string)$need['reference'],
                    'status' => 'skipped',
                    'message' => 'Déjà généré pour cette échéance.',
                ];
                continue;
            }

            try {
                $generationDate = $today->format('Y-m-d');
                $febId = $this->createFebDraftFromNeed($need, $dueDate, $generationDate);

                $nextDue = $this->computeNextDueDate($need, new DateTimeImmutable($dueDate))->format('Y-m-d');
                $generationId = $this->repo->logGeneration(
                    (int)$need['id'],
                    $dueDate,
                    $today->format('Y-m-d'),
                    'generated',
                    $febId,
                    'FEB soumise automatiquement au circuit normal de validation. La fiche et le BC seront créés après approbation, sans marquer le paiement.',
                    [
                        'generated_employee_legal_request_id' => $febId,
                        'next_due_date' => $nextDue,
                    ]
                );

                $this->repo->markGenerated((int)$need['id'], $nextDue);
                $this->repo->createNotification(
                    (int)$need['id'],
                    $generationId,
                    (int)$need['requester_user_id'],
                    'generated',
                    'FEB récurrente soumise',
                    'Une FEB a été soumise automatiquement pour le besoin récurrent ' . (string)$need['reference'] . '. Elle suit désormais les validations normales avant création de la fiche planifiée et du BC.'
                );
                $this->notifyUser(
                    (int)$need['requester_user_id'],
                    'generated',
                    'FEB récurrente soumise',
                    'La FEB #' . $febId . ' a été positionnée pour ' . (string)$need['reference'] . '. Aucun paiement n’est effectué : la fiche et le BC seront créés seulement après les validations requises.',
                    $this->appLink('/employe/nouvelle_demande_employe.php?type=feb&request_id=' . $febId),
                    ['recurring_need_id' => (int)$need['id'], 'generated_feb_id' => $febId]
                );

                $this->audit('recurring_need_feb_generated', [
                    'entity_type' => 'employee_request',
                    'entity_id' => (string)$febId,
                    'entity_label' => 'FEB automatique - ' . (string)$need['title'],
                    'details' => [
                        'recurring_need_id' => (int)$need['id'],
                        'recurring_need_reference' => (string)$need['reference'],
                        'scheduled_due_date' => $dueDate,
                        'generation_date' => $generationDate,
                    ],
                ]);

                $results[] = [
                    'need_id' => (int)$need['id'],
                    'reference' => (string)$need['reference'],
                    'status' => 'generated',
                    'feb_id' => $febId,
                    'next_due_date' => $nextDue,
                ];
            } catch (Throwable $e) {
                $this->repo->logGeneration(
                    (int)$need['id'],
                    $dueDate,
                    $today->format('Y-m-d'),
                    'failed',
                    null,
                    $e->getMessage()
                );

                $this->audit('recurring_need_feb_generation_failed', [
                    'entity_id' => (string)$need['id'],
                    'entity_label' => (string)$need['title'],
                    'result' => 'failed',
                    'severity' => 'warning',
                    'details' => [
                        'scheduled_due_date' => $dueDate,
                        'error_message' => $e->getMessage(),
                    ],
                ]);

                $results[] = [
                    'need_id' => (int)$need['id'],
                    'reference' => (string)$need['reference'],
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private function createFebDraftFromNeed(array $need, string $dueDate, string $generationDate): int
    {
        $manager = new EmployeeLegalRequestManager($this->pdo);

        $requesterUserId = (int)($need['requester_user_id'] ?? 0);
        if ($requesterUserId <= 0) {
            throw new RuntimeException('Utilisateur demandeur introuvable pour le besoin récurrent.');
        }

        $requester = [
            'user_id' => $requesterUserId,
            'name' => (string)($need['requester_name'] ?? ''),
            'email' => (string)($need['requester_email'] ?? ''),
        ];

        $payload = $this->buildFebPayload($need, $dueDate, $generationDate);
        $period = $this->occurrencePeriod($need, $dueDate);
        $title = 'FEB AUTO: ' . (string)($need['title'] ?? 'Besoin récurrent') . ' — ' . $period['label'];

        return $manager->createRequest($requesterUserId, $requester, 'feb', $payload, $title);
    }

    private function buildFebPayload(array $need, string $dueDate, string $generationDate): array
    {
        $generationDate = $this->validDate($generationDate, 'Date de génération du besoin récurrent invalide.')->format('Y-m-d');
        $period = $this->occurrencePeriod($need, $dueDate);
        $period['generation_date'] = $generationDate;
        $periodTitle = trim((string)($need['title'] ?? 'Besoin récurrent')) . ' — ' . $period['label'];
        $template = json_decode((string)($need['feb_template_json'] ?? ''), true);
        if (is_array($template) && !empty($template['source_feb_request_id'])) {
            $template['auto_generated'] = true;
            $template['source'] = 'recurring_needs';
            $template['recurring_need_id'] = (int)($need['id'] ?? 0);
            $template['recurring_need_reference'] = (string)($need['reference'] ?? '');
            $template['scheduled_due_date'] = $dueDate;
            $template['generation_date'] = $generationDate;
            $template['generated_at'] = $generationDate;
            $template['date_livraison'] = $dueDate;
            $template['planned_start_date'] = $period['start'];
            $template['planned_end_date'] = $period['end'];
            $template['planned_disbursement_date'] = $dueDate;
            $template['feb_quote_title'] = $periodTitle;
            $budgetMode=(string)($template['budget_planned']??'');
            if(!in_array($budgetMode,['yes','no'],true))$budgetMode=!empty($template['proposed_debourse'])?'no':(((int)($template['debourse_id']??0)>0||(int)($template['ligne_debourse_id']??0)>0)?'yes':'no');
            $template['budget_planned']=$budgetMode;
            $template['budget_planned_label']=$budgetMode==='yes'?'Prévue au déboursé':'Non prévue au déboursé - proposition à négocier';
            foreach ((array)($template['besoins'] ?? []) as $lineIndex => $line) {
                if (!is_array($line)) continue;
                $template['besoins'][$lineIndex]['planned_start_date'] = $period['start'];
                $template['besoins'][$lineIndex]['planned_end_date'] = $period['end'];
            }
            if (is_array($template['provisional_decaissement'] ?? null)) {
                $template['provisional_decaissement']['date_decaissement_minimum'] = $dueDate;
                $template['provisional_decaissement']['planned_start_date'] = $period['start'];
                $template['provisional_decaissement']['planned_end_date'] = $period['end'];
            }
            if ((string)($need['financial_mode']??'') !== 'sans_decaissement') {
                $draft=is_array($template['provisional_decaissement']??null)?(array)$template['provisional_decaissement']:[];
                $supplierId=max((int)($template['global_fournisseur_id']??0),(int)($need['fournisseur_id']??0),(int)($draft['fournisseur_id']??0));
                $personnelId=(int)($draft['personnel_id']??$template['personnel_id']??0);
                if($personnelId<=0){try{$personnel=$this->pdo->prepare('SELECT personnel_id FROM user_devis_banamur WHERE id=:id LIMIT 1');$personnel->execute(['id'=>(int)($need['requester_user_id']??0)]);$personnelId=(int)$personnel->fetchColumn();}catch(Throwable $ignored){}}
                $beneficiaryChoice=(string)($template['beneficiary_choice']??($supplierId>0?'supplier':'requester'));
                $template['provisional_decaissement']=array_merge($draft,[
                    'active'=>true,'beneficiary_choice'=>$beneficiaryChoice,'beneficiary_mode'=>$beneficiaryChoice==='supplier'?'supplier':'personnel',
                    'personnel_id'=>$beneficiaryChoice==='supplier'?0:$personnelId,'fournisseur_id'=>$supplierId,
                    'chantier_id'=>max((int)($draft['chantier_id']??0),(int)($template['chantier_id']??0),(int)($need['chantier_id']??0)),
                    'montant_fiche'=>(float)($template['montant']??$need['amount_estimated']??0),
                    'mode_paiement'=>trim((string)($draft['mode_paiement']??''))?:($supplierId>0?'Virement':'Espèces'),
                    'details_demande'=>trim((string)($draft['details_demande']??$template['description']??$need['description']??$need['title']??'')),
                    'date_decaissement_minimum'=>$dueDate,'planned_start_date'=>$period['start'],'planned_end_date'=>$period['end'],
                ]);
            }
            if (is_array($template['proposed_debourse'] ?? null)) {
                $template['proposed_debourse']['title'] = $periodTitle;
                $template['proposed_debourse']['planned_start_date'] = $period['start'];
                $template['proposed_debourse']['planned_end_date'] = $period['end'];
                foreach ((array)($template['proposed_debourse']['lines'] ?? []) as $index => $line) {
                    if (!is_array($line)) continue;
                    $template['proposed_debourse']['lines'][$index]['planned_start_date'] = $period['start'];
                    $template['proposed_debourse']['lines'][$index]['planned_end_date'] = $period['end'];
                }
            }
            if($budgetMode==='no'&&empty($template['proposed_debourse'])){
                $amount=(float)($template['montant']??$need['amount_estimated']??0);
                $designation=trim((string)($need['title']??$template['description']??'Besoin récurrent'))?:'Besoin récurrent';
                $template['proposed_debourse']=['status'=>'submitted_for_analysis','ligne_devis_id'=>0,'ligne_devis_proposal'=>$designation,'title'=>$periodTitle,'planned_start_date'=>$period['start'],'planned_end_date'=>$period['end'],'lines'=>[['designation'=>$designation,'categorie'=>'service','montant'=>$amount,'planned_start_date'=>$period['start'],'planned_end_date'=>$period['end']]],'total_amount'=>$amount];
            }
            $template['generation_note'] = 'FEB créée automatiquement par le cycle des besoins récurrents.';
            $template['occurrence_period'] = $period;
            return $template;
        }

        $scope = ((string)($need['need_type'] ?? 'interne') === 'externe') ? 'external' : 'internal';
        $financialMode = (string)($need['financial_mode'] ?? 'avance_provisoire');
        $amount = (float)($need['amount_estimated'] ?? 0);
        $fournisseurId = (int)($need['fournisseur_id'] ?? 0);
        $fournisseurName = trim((string)($need['fournisseur_name'] ?? ''));
        $requesterPersonnelId=0;
        try{$personnel=$this->pdo->prepare('SELECT personnel_id FROM user_devis_banamur WHERE id=:id LIMIT 1');$personnel->execute(['id'=>(int)($need['requester_user_id']??0)]);$requesterPersonnelId=(int)$personnel->fetchColumn();}catch(Throwable $ignored){}

        $cashflowNeed = match ($financialMode) {
            'decaissement_unique' => 'internal_purchase_order',
            'avance_provisoire' => 'provisional_disbursement',
            default => 'none',
        };

        $description = trim((string)($need['description'] ?? ''));
        if ($description === '') {
            $description = 'Demande générée automatiquement depuis le module Besoins récurrents.';
        }

        return [
            'auto_generated' => true,
            'source' => 'recurring_needs',
            'recurring_need_id' => (int)($need['id'] ?? 0),
            'recurring_need_reference' => (string)($need['reference'] ?? ''),
            'scheduled_due_date' => $dueDate,
            'generation_date' => $generationDate,
            'generated_at' => $generationDate,
            'generation_note' => 'FEB créée automatiquement par le cron des besoins récurrents.',
            'nom' => strtoupper((string)($need['requester_name'] ?? '')),
            'matricule' => '',
            'fonction' => '',
            'service_employe' => '',
            'departement_id' => 0,
            'departement_label' => 'Génération automatique',
            'chantier_id' => (int)($need['chantier_id'] ?? 0),
            'besoins' => [
                [
                    'type' => $scope === 'external' ? 'Service' : 'Bien',
                    'libelle' => (string)($need['title'] ?? 'Besoin récurrent'),
                    'quantite' => 1,
                    'has_fournisseur' => $fournisseurId > 0 || $fournisseurName !== '',
                    'fournisseur_id' => $fournisseurId > 0 ? $fournisseurId : null,
                    'fournisseur_nom' => $fournisseurName,
                    'fournisseur_prix' => $amount,
                    'fournisseur_tel' => '',
                    'fournisseur' => ['id'=>$fournisseurId>0?$fournisseurId:null,'nom'=>$fournisseurName,'prix_unitaire'=>$amount,'telephone'=>''],
                    'planned_start_date' => $period['start'],
                    'planned_end_date' => $period['end'],
                ],
            ],
            'description' => $description,
            'feb_quote_title' => $periodTitle,
            'montant' => $amount,
            'date_livraison' => $dueDate,
            'planned_start_date' => $period['start'],
            'planned_end_date' => $period['end'],
            'planned_disbursement_date' => $dueDate,
            'budget_planned' => 'no',
            'budget_planned_label' => 'Non prévue au déboursé - intégration à valider',
            'beneficiary_choice' => $fournisseurId > 0 ? 'supplier' : 'requester',
            'fournisseur_id' => $fournisseurId > 0 ? $fournisseurId : 0,
            'global_fournisseur_id' => $fournisseurId > 0 ? $fournisseurId : 0,
            'global_fournisseur_nom' => $fournisseurName,
            'global_justificatif' => [],
            'occurrence_period' => $period,
            'provisional_decaissement' => $financialMode !== 'sans_decaissement' ? [
                'active' => true,
                'beneficiary_choice' => $fournisseurId > 0 ? 'supplier' : 'requester',
                'beneficiary_mode' => $fournisseurId > 0 ? 'supplier' : 'personnel',
                'personnel_id' => $fournisseurId > 0 ? 0 : $requesterPersonnelId,
                'fournisseur_id' => $fournisseurId > 0 ? $fournisseurId : 0,
                'chantier_id' => (int)($need['chantier_id'] ?? 0),
                'montant_fiche' => $amount,
                'mode_paiement' => $fournisseurId > 0 ? 'Virement' : 'Espèces',
                'details_demande' => $description,
                'date_decaissement_minimum' => $dueDate,
                'planned_start_date' => $period['start'],
                'planned_end_date' => $period['end'],
            ] : [],
            'proposed_debourse' => [
                'status' => 'submitted_for_analysis',
                'ligne_devis_id' => 0,
                'ligne_devis_proposal' => (string)($need['title'] ?? 'Besoin récurrent'),
                'title' => $periodTitle,
                'planned_start_date' => $period['start'],
                'planned_end_date' => $period['end'],
                'lines' => [[
                    'designation' => (string)($need['title'] ?? 'Besoin récurrent'),
                    'categorie' => 'service',
                    'montant' => $amount,
                    'planned_start_date' => $period['start'],
                    'planned_end_date' => $period['end'],
                ]],
                'total_amount' => $amount,
            ],
            'impact' => 'Besoin récurrent programmé. À contrôler et valider selon le circuit habituel.',
            'frequence' => $this->mapFrequencyForFeb((string)($need['frequency_type'] ?? 'monthly')),
            'feb_need_scope' => $scope,
            'feb_need_scope_label' => $scope === 'internal' ? 'Besoin interne' : 'Demande externe',
            'pv_reception_number' => '',
            'pv_reception_document' => null,
            'bci_number' => '',
            'cashflow_need' => $cashflowNeed,
            'cashflow_need_label' => $cashflowNeed === 'provisional_disbursement'
                ? 'Decaissement provisoire demande'
                : ($cashflowNeed === 'internal_purchase_order' ? 'Decaissement unique sur facture' : 'Aucun décaissement automatique'),
            'provisional_disbursement_amount' => $cashflowNeed === 'provisional_disbursement' ? $amount : 0,
            'cashflow_comment' => 'Généré automatiquement  ' . (int)($need['generate_days_before'] ?? 7) . ' jour(s) avant l’échéance.',
            'supporting_document_number' => '',
            'justificatifs' => [],
            'invoice_number' => '',
            'invoice_supplier' => [
                'id' => $fournisseurId > 0 ? $fournisseurId : 0,
                'nom' => $fournisseurName,
            ],
            'invoice_document' => null,
        ];
    }

    /** Retourne la fenêtre budgétaire exacte de l'occurrence contenant l'échéance. */
    private function occurrencePeriod(array $need, string $dueDate): array
    {
        $due = new DateTimeImmutable($dueDate);
        $frequency = (string)($need['frequency_type'] ?? 'monthly');
        if ($frequency === 'weekly') {
            $start = $due->modify('monday this week');$end = $start->modify('+6 days');
            $label = 'semaine du '.$start->format('d/m/Y').' au '.$end->format('d/m/Y');
        } elseif ($frequency === 'quarterly') {
            $month = ((int)floor(((int)$due->format('n') - 1) / 3) * 3) + 1;
            $start = $due->setDate((int)$due->format('Y'),$month,1);$end = $start->modify('+3 months -1 day');
            $label = 'T'.((int)floor(($month-1)/3)+1).' '.$due->format('Y');
        } elseif ($frequency === 'semesterly') {
            $month = (int)$due->format('n') <= 6 ? 1 : 7;
            $start = $due->setDate((int)$due->format('Y'),$month,1);$end = $start->modify('+6 months -1 day');
            $label = ($month===1?'S1 ':'S2 ').$due->format('Y');
        } elseif ($frequency === 'yearly') {
            $start = $due->setDate((int)$due->format('Y'),1,1);$end = $due->setDate((int)$due->format('Y'),12,31);$label = 'année '.$due->format('Y');
        } elseif ($frequency === 'daily') {
            $start=$due;$end=$due;$label='journée du '.$due->format('d/m/Y');
        } else {
            $start=$due->modify('first day of this month');$end=$due->modify('last day of this month');
            $months=[1=>'janvier',2=>'février',3=>'mars',4=>'avril',5=>'mai',6=>'juin',7=>'juillet',8=>'août',9=>'septembre',10=>'octobre',11=>'novembre',12=>'décembre'];
            $label=($months[(int)$due->format('n')]??$due->format('m')).' '.$due->format('Y');
        }
        return ['start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'due_date'=>$due->format('Y-m-d'),'label'=>$label];
    }

    private function computeNextDueDate(array $need, DateTimeImmutable $current): DateTimeImmutable
    {
        $interval = max(1, (int)($need['frequency_interval'] ?? 1));

        return match ((string)($need['frequency_type'] ?? 'monthly')) {
            'daily' => $current->modify('+' . $interval . ' day'),
            'weekly' => $current->modify('+' . $interval . ' week'),
            'quarterly' => $current->modify('+' . (3 * $interval) . ' month'),
            'semesterly' => $current->modify('+' . (6 * $interval) . ' month'),
            'yearly' => $current->modify('+' . $interval . ' year'),
            default => $current->modify('+' . $interval . ' month'),
        };
    }

    private function mapFrequencyForFeb(string $frequency): string
    {
        return [
            'daily' => 'unique',
            'weekly' => 'hebdomadaire',
            'monthly' => 'mensuelle',
            'quarterly' => 'trimestrielle',
            'semesterly' => 'semestrielle',
            'yearly' => 'annuelle',
            'custom' => 'mensuelle',
        ][$frequency] ?? 'mensuelle';
    }

    private function frequencyFromFeb(string $frequency): string
    {
        return [
            'hebdomadaire' => 'weekly',
            'mensuelle' => 'monthly',
            'trimestrielle' => 'quarterly',
            'semestrielle' => 'semesterly',
            'annuelle' => 'yearly',
        ][$frequency] ?? 'monthly';
    }

    private function firstSupplierFromFebPayload(array $payload): array
    {
        foreach ((array)($payload['besoins'] ?? []) as $line) {
            $supplier = is_array($line['fournisseur'] ?? null) ? $line['fournisseur'] : [];
            $id = (int)($supplier['id'] ?? $line['fournisseur_id'] ?? 0);
            $name = trim((string)($supplier['nom'] ?? $line['fournisseur_nom'] ?? ''));
            if ($id > 0 || $name !== '') {
                return ['id' => $id > 0 ? $id : null, 'name' => $name];
            }
        }
        $invoice = is_array($payload['invoice_supplier'] ?? null) ? $payload['invoice_supplier'] : [];
        return [
            'id' => (int)($invoice['id'] ?? 0) ?: null,
            'name' => trim((string)($invoice['nom'] ?? '')),
        ];
    }

    private function notifyUser(int $userId, string $subKey, string $title, string $message, string $link, array $payload = []): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            new NotificationManager($this->pdo);
            $stmt = $this->pdo->prepare('
                INSERT INTO system_notifications
                    (created_at,event_key,sub_key,title,message,link_url,recipient_team,recipient_user_id,channels_json,payload_json)
                VALUES (NOW(),:event_key,:sub_key,:title,:message,:link_url,NULL,:user_id,:channels,:payload)
            ');
            $stmt->execute([
                'event_key' => 'recurring_need',
                'sub_key' => $subKey,
                'title' => $title,
                'message' => $message,
                'link_url' => $link,
                'user_id' => $userId,
                'channels' => json_encode(['system'], JSON_UNESCAPED_UNICODE),
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
        }
    }

    private function appLink(string $path): string
    {
        $baseUrl = rtrim((string)(getenv('APP_BASE_URL') ?: ''), '/');
        return $baseUrl !== '' ? $baseUrl . '/' . ltrim($path, '/') : $path;
    }


    private function resolveSupplierName(int $fournisseurId): string
    {
        if ($fournisseurId <= 0 || !class_exists('FournisseurRepository')) {
            return '';
        }

        try {
            $repo = new FournisseurRepository($this->pdo);
            $supplier = $repo->getById($fournisseurId);
            if (!$supplier) {
                return '';
            }

            $type = (string)($supplier['supplier_type'] ?? 'morale');
            if ($type === 'physique') {
                $fullName = trim((string)($supplier['person_first_name'] ?? '') . ' ' . (string)($supplier['person_last_name'] ?? ''));
                if ($fullName !== '') {
                    return $fullName;
                }
            }

            return trim((string)($supplier['nom_commercial'] ?? ''))
                ?: trim((string)($supplier['raison_sociale'] ?? ''))
                ?: ('Fournisseur #' . $fournisseurId);
        } catch (Throwable $e) {
            return '';
        }
    }

    private function validDate(string $value, string $message): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value ?: date('Y-m-d'));
        } catch (Throwable) {
            throw new InvalidArgumentException($message);
        }
    }

    private function in(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    private function money(string $value): float
    {
        $value = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim($value));
        return round((float)$value, 2);
    }

    private function audit(string $eventType, array $context = []): void
    {
        if (!class_exists('AuditTrail')) {
            return;
        }

        try {
            $context = array_replace_recursive([
                'module' => 'recurring_needs',
                'action' => $eventType,
                'entity_type' => 'recurring_need',
                'result' => 'success',
            ], $context);

            AuditTrail::log($eventType, $context, $this->pdo);
        } catch (Throwable) {
            // L'audit ne doit jamais bloquer la création/génération.
        }
    }
}
