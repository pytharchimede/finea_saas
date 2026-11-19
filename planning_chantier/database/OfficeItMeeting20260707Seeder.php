<?php
declare(strict_types=1);

final class OfficeItMeeting20260707Seeder
{
    private const KEY='office_it_meeting_2026_07_07_v1';

    public function __construct(private PDO $pdo) {}

    public function seed():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_seed_history (
            seed_key VARCHAR(190) PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            details_json LONGTEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $check=$this->pdo->prepare("SELECT COUNT(*) FROM planning_chantier_seed_history WHERE seed_key=:seed");
        $check->execute(['seed'=>self::KEY]);
        if((int)$check->fetchColumn()>0)return;

        $service=$this->pdo->query("SELECT c.id chantier_id,c.devis_id,ld.id ligne_id
            FROM chantier_banamur c
            JOIN ligne_devis_banamur ld ON ld.devis_id=c.devis_id
            WHERE c.planning_is_office_hub=1 AND c.archived_at IS NULL AND c.closed_date IS NULL
              AND UPPER(ld.designation) LIKE '%INFORMATIQUE%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if(!$service)return;

        $tasks=$this->tasks();
        $this->pdo->beginTransaction();
        try{
            $people=$this->people();
            $participantRows=[];
            foreach($people as $name=>$id)if($id>0)$participantRows[$id]=[
                'user_id'=>$id,'name'=>$name,'email'=>'','attendance_status'=>'present','notify_email'=>0,
            ];
            $insertPreparation=$this->pdo->prepare("INSERT INTO planning_chantier_meeting_preparations
                (meeting_date,titre,agenda,chantier_ids_json,participants_json,status,created_by,ready_at,started_at,
                 used_at,office_chantier_id,office_ligne_devis_id,created_at,updated_at)
                VALUES('2026-07-07','Réunion hebdomadaire - Service Informatique',
                :agenda,:chantiers,:participants,'used',:creator,'2026-07-07 12:00:00','2026-07-07 12:00:00',
                '2026-07-07 14:00:00',:chantier,:line,'2026-07-07 12:00:00',NOW())");
            $creator=$people['Ulrich']??null;
            $insertPreparation->execute([
                'agenda'=>"Suivi du site web, de la gestion des données, des formations Finéa, des appels d'offres, du parc informatique, du support et des réunions récurrentes.",
                'chantiers'=>json_encode([(int)$service['chantier_id']]),
                'participants'=>json_encode(array_values($participantRows),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'creator'=>$creator,'chantier'=>(int)$service['chantier_id'],'line'=>(int)$service['ligne_id'],
            ]);
            $preparationId=(int)$this->pdo->lastInsertId();

            $insertMeeting=$this->pdo->prepare("INSERT INTO planning_chantier_meetings
                (meeting_date,titre,resume,decisions,created_by,created_at,status,closed_at,closed_by,participants_text,preparation_id,updated_at)
                VALUES('2026-07-07','Réunion hebdomadaire - Service Informatique',:resume,:decisions,:creator,
                '2026-07-07 12:00:00','closed','2026-07-07 14:00:00',:closer,:participants,:preparation,NOW())");
            $insertMeeting->execute([
                'resume'=>"Compte rendu réel du Service Informatique intégré depuis le document « compte rendue 07-07-2026 ».",
                'decisions'=>"Poursuivre le suivi hebdomadaire des actions, responsables, échéances et checklists dans FINEA.",
                'creator'=>$creator,'closer'=>$creator,'participants'=>implode(', ',array_keys($people)),'preparation'=>$preparationId,
            ]);
            $meetingId=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare("UPDATE planning_chantier_meeting_preparations SET meeting_id=:meeting WHERE id=:id")
                ->execute(['meeting'=>$meetingId,'id'=>$preparationId]);

            $insertParticipant=$this->pdo->prepare("INSERT INTO planning_chantier_meeting_participants
                (meeting_id,user_id,participant_name,participant_email,attendance_status,notify_email,created_at)
                SELECT :meeting,u.id,CONCAT_WS(' ',u.prenom,u.nom),u.mail_pro,'present',0,'2026-07-07 12:00:00'
                FROM user_devis_banamur u WHERE u.id=:user");
            foreach(array_keys($participantRows) as $userId)$insertParticipant->execute(['meeting'=>$meetingId,'user'=>$userId]);

            $structures=[];
            foreach($tasks as $task){
                $key=$task['category'].'|'.$task['subcategory'];
                if(!isset($structures[$key]))$structures[$key]=$this->structure(
                    $preparationId,(int)$service['chantier_id'],(int)$service['ligne_id'],
                    $task['category'],$task['subcategory'],$creator
                );
                $meetingSectionId=(int)$structures[$key];
                $pilotId=$this->personId((string)$task['pilot'],$people);
                $copilotId=$this->personId((string)$task['copilot'],$people);
                $progress=(float)$task['progress'];
                $status=$progress>=100?'done':($progress>0?'in_progress':'open');
                $insertPoint=$this->pdo->prepare("INSERT INTO planning_chantier_meeting_action_points
                    (preparation_id,chantier_id,ligne_devis_id,meeting_section_id,section_title,task_title,
                     pilot_user_id,pilot_name,copilot_user_id,copilot_name,old_deadline,new_deadline,progress,status,
                     sort_order,created_by,created_at)
                    VALUES(:preparation,:chantier,:line,:meeting_section,:section,:task,:pilot_id,:pilot_name,
                    :copilot_id,:copilot_name,:old_deadline,:new_deadline,:progress,:status,:sort_order,:creator,'2026-07-07 12:00:00')");
                $insertPoint->execute([
                    'preparation'=>$preparationId,'chantier'=>(int)$service['chantier_id'],'line'=>(int)$service['ligne_id'],
                    'meeting_section'=>$meetingSectionId,'section'=>$task['subcategory'],'task'=>$task['title'],
                    'pilot_id'=>$pilotId?:null,'pilot_name'=>$task['pilot']?:null,'copilot_id'=>$copilotId?:null,
                    'copilot_name'=>$task['copilot']?:null,'old_deadline'=>$task['old']?:null,'new_deadline'=>$task['new']?:null,
                    'progress'=>$progress,'status'=>$status,'sort_order'=>$task['order'],'creator'=>$creator,
                ]);
                $pointId=(int)$this->pdo->lastInsertId();
                $insertChecklist=$this->pdo->prepare("INSERT INTO planning_chantier_meeting_action_point_checklist
                    (action_point_id,label,completed_at,completed_by,sort_order,created_by,created_at)
                    VALUES(:point,:label,:completed_at,:completed_by,:sort_order,:creator,'2026-07-07 12:00:00')");
                foreach($task['checklist'] as $index=>$label)$insertChecklist->execute([
                    'point'=>$pointId,'label'=>$label,'completed_at'=>$progress>=100?'2026-07-07 14:00:00':null,
                    'completed_by'=>$progress>=100?$creator:null,'sort_order'=>$index,'creator'=>$creator,
                ]);
            }
            $this->pdo->prepare("INSERT INTO planning_chantier_seed_history(seed_key,details_json) VALUES(:seed,:details)")
                ->execute(['seed'=>self::KEY,'details'=>json_encode([
                    'meeting_id'=>$meetingId,'preparation_id'=>$preparationId,'tasks'=>count($tasks),
                    'source'=>'compte rendue 07-07-2026 (1).docx',
                ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function structure(
        int $preparationId,
        int $chantierId,
        int $lineId,
        string $category,
        string $subcategory,
        ?int $creator
    ):int
    {
        $find=$this->pdo->prepare("SELECT id FROM planning_chantier_meeting_custom_sections
            WHERE preparation_id=:preparation AND title=:title AND archived_at IS NULL LIMIT 1");
        $title=trim($category).' — '.trim($subcategory);
        $find->execute(['preparation'=>$preparationId,'title'=>$title]);
        $sectionId=(int)$find->fetchColumn();
        if($sectionId>0)return $sectionId;

        $order=$this->pdo->prepare("SELECT COALESCE(MAX(sort_order),-1)+1
            FROM planning_chantier_meeting_custom_sections
            WHERE preparation_id=:preparation AND archived_at IS NULL");
        $order->execute(['preparation'=>$preparationId]);
        $this->pdo->prepare("INSERT INTO planning_chantier_meeting_custom_sections
            (preparation_id,chantier_id,ligne_devis_id,title,payload_json,sort_order,created_by,created_at)
            VALUES(:preparation,:chantier,:line,:title,:payload,:sort_order,:creator,'2026-07-07 12:00:00')")
            ->execute([
                'preparation'=>$preparationId,
                'chantier'=>$chantierId,
                'line'=>$lineId,
                'title'=>$title,
                'payload'=>json_encode([
                    'origin'=>'office_it_seed_autonomous',
                    'category'=>$category,
                    'subcategory'=>$subcategory,
                ],JSON_UNESCAPED_UNICODE),
                'sort_order'=>(int)$order->fetchColumn(),
                'creator'=>$creator,
            ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function people():array
    {
        $definitions=[
            'Ulrich'=>['ulrich','amani'],'Agénor'=>['agénor','kouakou luc'],'Fatou'=>['fatou','camara'],
            'Inès'=>['ines','nguettia'],'Degri'=>['degri'],'Mélissa'=>['melissa'],
            'M. Braud'=>['braud'],'M. Kafando'=>['kafando'],'Élodie'=>['elodie','zian'],
        ];
        $users=$this->pdo->query("SELECT id,LOWER(CONCAT_WS(' ',prenom,nom)) full_name FROM user_devis_banamur WHERE active=1")
            ->fetchAll(PDO::FETCH_ASSOC)?:[];
        $result=[];
        foreach($definitions as $label=>$needles){
            $result[$label]=0;
            foreach($users as $user)foreach($needles as $needle)if(str_contains($this->fold((string)$user['full_name']),$this->fold($needle))){
                $result[$label]=(int)$user['id'];break 2;
            }
        }
        return $result;
    }

    private function personId(string $value,array $people):int
    {
        $fold=$this->fold($value);
        foreach($people as $label=>$id)if($id>0&&str_contains($fold,$this->fold($label)))return $id;
        return 0;
    }

    private function fold(string $value):string
    {
        $value=mb_strtolower(trim($value));
        return strtr($value,['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','ö'=>'o','ù'=>'u','û'=>'u','ç'=>'c']);
    }

    private function tasks():array
    {
        $rows=[
            ['Site web','Évolutions du site','Correction du contenu textuel','Jaurès','Inès','2026-05-11','2026-07-13',90,['Correction locale','Validation par Inès','Se rapprocher de M. Amichia et de la Direction Technique']],
            ['Site web','Évolutions du site','Module de traduction multilingue','Ulrich','Agénor','2026-06-03','2026-07-20',50,['Formation en JSON','Consacrer 30 minutes par jour']],
            ['Site web','Évolutions du site','Ajouter davantage de photos au carrousel','Jaurès','Degri','2026-05-08','2026-05-29',100,['Préparer la présentation','Faire valider la présentation']],
            ['Gestion des données','Archivage et inventaire','Former le personnel à l’archivage','Agénor','Fatou','2026-07-06','2026-07-13',0,['Suivre la création et l’utilisation des sous-dossiers','Reproduire textuellement la plateforme']],
            ['Gestion des données','Archivage et inventaire','Renseigner le tableau d’inventaire des ordinateurs et applications','Agénor','Ulrich','2026-05-05','2026-05-18',100,['Identifier chaque ordinateur','Actualiser le tableau','Définir un moyen d’archiver tout le matériel']],
            ['Formation Finéa','Sessions et adoption','Former les utilisateurs à Finéa','Jaurès','Fatou','2026-06-15','',50,['Présenter le système de ticketing','Faire un compte rendu par session','Collecter le retour des utilisateurs']],
            ['AO - Finéa','Sources, statistiques et mots-clés','Faire évoluer le module AO de Finéa','Jaurès','Fatou','2026-07-07','2026-07-13',0,['Voir Inès pour intégrer les autres sources et les sources privées','Présenter les statistiques','Travailler les mots-clés Fourniture et Travaux']],
            ['Gestion du parc informatique','Infrastructure, équipements et réseau','Faire intervenir Drissa pour diagnostiquer les retours en vue d’un troc ou d’une réparation','Agénor','Ulrich','2026-07-06','2026-07-09',0,['Faire un prédiagnostic']],
            ['Gestion du parc informatique','Infrastructure, équipements et réseau','Commander les équipements informatiques nécessaires','Agénor','Ulrich','2026-07-06','',50,['Commander un écran TV 32 pouces pour KEKELI — refusé par la comptabilité','Commander des ordinateurs performants pour les développeurs avec une fiche technique précise']],
            ['Gestion du parc informatique','Infrastructure, équipements et réseau','Former Agénor à Windows Server et récupérer les accès au serveur','Agénor + M. Bah','M. Braud','2026-05-13','2026-06-20',50,['Finaliser le câblage','Finaliser la configuration']],
            ['Gestion du parc informatique','Infrastructure, équipements et réseau','Ranger les câbles informatiques','Mélissa','M. Braud','2026-05-04','2026-06-20',50,['Obtenir le décaissement attendu']],
            ['Gestion du parc informatique','Infrastructure, équipements et réseau','Connecter les sessions à Windows Server','Agénor','M. Bah','2026-06-07','',0,[]],
            ['Gestion du parc informatique','Infrastructure, équipements et réseau','Proposer une solution de déploiement de Finéa en réseau local','Ulrich','','2026-06-07','2026-06-13',90,[]],
            ['Gestion du parc informatique','Infrastructure, équipements et réseau','Alimenter le serveur en permanence en raccordant le service informatique à l’onduleur','Fatou','M. Kafando et Noël','2026-05-15','2026-06-15',0,['Obtenir le devis de M. Kafando']],
            ['Gestion du parc informatique','Infrastructure, équipements et réseau','Mettre en place deux numéros fixes officiels BANAMUR et FIDEST avec redirection mobile','Fatou & M. Kafando','M. Braud','2026-05-22','2026-06-15',30,[]],
            ['Support, suivi & formations','Assistance et documentation','Paramétrer Outlook et les signatures mail sur les téléphones','Jaurès','Ulrich','2026-05-15','2026-06-15',80,['Suivre la checklist de paramétrage','Faire sortir de notre système d’information le personnel ayant quitté l’entreprise']],
            ['Support, suivi & formations','Assistance et documentation','Assainir les accès du personnel','', '', '', '',0,['Débriefer le personnel qui ne travaille plus chez BANAMUR','Filtrer le personnel BANAMUR dans la badgeuse']],
            ['Support, suivi & formations','Assistance et documentation','Rédiger la documentation de tous les outils informatiques','Jaurès','Ulrich','2026-05-22','',100,['Partager la documentation selon la matrice d’accès']],
            ['Support, suivi & formations','Assistance et documentation','Faire intervenir Drissa pour un contrat d’assistance et de maintenance','Ulrich','Agénor','2026-05-22','2026-06-13',0,['Préparer le programme avec Mélissa']],
            ['Support, suivi & formations','Assistance et documentation','Mettre en place le Content Manager — en attente de la direction','Élodie','','','',0,[]],
            ['Réunions à prévoir','Réunions récurrentes','Planifier les prochaines réunions informatiques','','','','',0,['Site web et présentation BANAMUR/FIDEST : tous les jeudis','Développement ERP Finéa']],
        ];
        return array_map(static fn(array $row,int $index):array=>[
            'category'=>$row[0],'subcategory'=>$row[1],'title'=>$row[2],'pilot'=>$row[3],'copilot'=>$row[4],
            'old'=>$row[5],'new'=>$row[6],'progress'=>$row[7],'checklist'=>$row[8],'order'=>$index,
        ],$rows,array_keys($rows));
    }
}
