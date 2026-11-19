<?php
declare(strict_types=1);

require_once __DIR__.'/../model/FebOcrSettings.php';
require_once __DIR__.'/../repositories/NotificationRepository.php';

final class FebHumanDocumentReviewService
{
    private static bool $schemaEnsured = false;

    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        try {
            $this->pdo->query('SELECT 1 FROM feb_human_document_reviews LIMIT 0');
            $this->pdo->query('SELECT 1 FROM feb_human_document_review_recipients LIMIT 0');
            self::$schemaEnsured = true;
            return;
        } catch (Throwable) {
            // Le déploiement peut précéder l'exécution manuelle des migrations.
            // On répare une seule fois, uniquement lorsque les tables manquent.
        }

        $migrationPath = dirname(__DIR__) . '/database/migrations/20260921_create_feb_human_document_reviews.sql';
        $sql = is_file($migrationPath) ? file_get_contents($migrationPath) : false;
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Migration de validation humaine FEB introuvable.');
        }

        try {
            $statements = preg_split('/;\s*(?=CREATE\s+TABLE)/i', trim($sql)) ?: [];
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $this->pdo->exec(rtrim($statement, ';'));
                }
            }
            $this->pdo->query('SELECT 1 FROM feb_human_document_reviews LIMIT 0');
            $this->pdo->query('SELECT 1 FROM feb_human_document_review_recipients LIMIT 0');
            self::$schemaEnsured = true;
        } catch (Throwable $e) {
            error_log('[feb_human_review_schema] ' . $e->getMessage());
            throw new RuntimeException('Le module de validation FEB doit être initialisé par un administrateur.', 0, $e);
        }
    }

    public function reviewers(): array
    {
        $ids=(new FebOcrSettings())->load()['human_reviewer_ids'] ?? [];
        $ids=array_values(array_filter(array_map('intval',(array)$ids)));
        if(!$ids)return [];
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $userColumns=array_flip(array_map('strval',$this->pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN,0)?:[]));
        $phone="''";foreach(['whatsapp','telephone','phone','contact'] as $column)if(isset($userColumns[$column])){$phone="COALESCE(NULLIF(u.`$column`,''),'')";break;}
        $join='';
        if($phone==="''"&&isset($userColumns['personnel_id']))try{$personnelColumns=array_flip(array_map('strval',$this->pdo->query('SHOW COLUMNS FROM personnel')->fetchAll(PDO::FETCH_COLUMN,0)?:[]));foreach(['whatsapp','telephone','phone','contact'] as $column)if(isset($personnelColumns[$column])){$phone="COALESCE(NULLIF(p.`$column`,''),'')";$join=' LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id';break;}}catch(Throwable){}
        $sql="SELECT u.id,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) name,u.mail_pro,$phone whatsapp FROM user_devis_banamur u$join WHERE u.active=1 AND u.id IN ($marks) ORDER BY u.prenom,u.nom";
        $s=$this->pdo->prepare($sql);$s->execute($ids);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function submit(int $requesterId,array $file,string $supplier,float $amount,string $lines): array
    {
        $reviewers=$this->reviewers();if(!$reviewers)throw new RuntimeException('Aucun validateur humain n’est configuré.');
        // Garantit que la notification système peut réellement être enregistrée.
        new NotificationRepository($this->pdo);
        if((int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Justificatif invalide.');
        $size=(int)($file['size']??0);if($size<=0||$size>12*1024*1024)throw new RuntimeException('Le justificatif doit faire moins de 12 Mo.');
        $ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));if(!in_array($ext,['pdf','jpg','jpeg','png','webp'],true))throw new RuntimeException('Format de justificatif non autorisé.');
        $dir=dirname(__DIR__).'/storage/feb/human_reviews/'.date('Ym');if(!is_dir($dir)&&!@mkdir($dir,0755,true))throw new RuntimeException('Stockage temporaire indisponible.');
        $token=bin2hex(random_bytes(32));$name=$token.'.'.$ext;$target=$dir.'/'.$name;
        if(!move_uploaded_file((string)$file['tmp_name'],$target))throw new RuntimeException('Impossible de stocker le justificatif.');
        $relative='feb/human_reviews/'.date('Ym').'/'.$name;
        $this->pdo->beginTransaction();
        try{
            $s=$this->pdo->prepare("INSERT INTO feb_human_document_reviews(public_token,requester_user_id,original_name,stored_path,mime_type,file_size,supplier_label,expected_amount,lines_json,status) VALUES(?,?,?,?,?,?,?,?,?,'pending')");
            $s->execute([$token,$requesterId,(string)$file['name'],$relative,(string)($file['type']??''),$size,$supplier,$amount,$lines]);$id=(int)$this->pdo->lastInsertId();
            $recipient=$this->pdo->prepare('INSERT INTO feb_human_document_review_recipients(review_id,reviewer_user_id,notified_at) VALUES(?,?,NOW())');
            $notify=$this->pdo->prepare("INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_user_id,channels_json,payload_json) VALUES(NOW(),'feb_document_human_review',:sub_key,'Justificatif FEB à valider',:message,:link,:user,'[\"system\",\"in_app\"]',:payload)");
            foreach($reviewers as $r){$uid=(int)$r['id'];$recipient->execute([$id,$uid]);$notify->execute(['sub_key'=>'review_'.$id,'message'=>'Une validation documentaire en temps réel vous attend. Ouvrez la demande pour valider ou refuser le justificatif.','link'=>'/validation_justificatifs_feb.php?review='.$id,'user'=>$uid,'payload'=>json_encode(['review_id'=>$id,'requester_user_id'=>$requesterId],JSON_UNESCAPED_UNICODE)]);}
            $this->pdo->commit();
            $this->sendChatReminders($id,$requesterId,$reviewers,$supplier);
            return ['token'=>$token,'status'=>'pending','reviewers'=>$reviewers];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();@unlink($target);throw $e;}
    }

    private function sendChatReminders(int $reviewId,int $requesterId,array $reviewers,string $supplier): void
    {
        // Le chat transforme les URL absolues en liens cliquables.
        $link='https://projet.fidest.ci/validation_justificatifs_feb.php?review='.$reviewId;
        $message='Justificatif FEB à valider'.($supplier!==''?' · Fournisseur : '.$supplier:'')."\nOuvrir la demande : ".$link;
        try{
            // La conversation existante reste utilisable, sans générer de groupe ni de compte technique.
            $insert=$this->pdo->prepare('INSERT INTO employee_chat_messages(sender_user_id,recipient_user_id,body,created_at) VALUES(?,?,?,NOW())');
            foreach($reviewers as $reviewer){
                $recipientId=(int)$reviewer['id'];
                if($recipientId!==$requesterId){$insert->execute([$requesterId,$recipientId,$message]);}
            }
        }catch(Throwable $e){
            // La notification système a été enregistrée : une panne du chat ne doit pas annuler la demande.
            error_log('[feb_human_review_chat] '.$e->getMessage());
        }
    }

    public function status(string $token,int $requesterId): array
    {$s=$this->pdo->prepare('SELECT id,status,decision_reason,decided_by_name,decided_at FROM feb_human_document_reviews WHERE public_token=? AND requester_user_id=?');$s->execute([$token,$requesterId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Validation introuvable.');return $r;}

    public function pendingFor(int $reviewerId): array
    {$s=$this->pdo->prepare("SELECT r.*,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) requester_name FROM feb_human_document_reviews r JOIN feb_human_document_review_recipients x ON x.review_id=r.id AND x.reviewer_user_id=? LEFT JOIN user_devis_banamur u ON u.id=r.requester_user_id WHERE r.status='pending' ORDER BY r.created_at");$s->execute([$reviewerId]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}

    public function decide(int $id,int $reviewerId,string $decision,string $reason,string $reviewerName): bool
    {
        if(!in_array($decision,['approved','rejected'],true))throw new RuntimeException('Décision invalide.');
        if($decision==='rejected'&&trim($reason)==='')throw new RuntimeException('La raison du refus est obligatoire.');
        $sql="UPDATE feb_human_document_reviews r SET status=:status,decision_reason=:reason,decided_by_user_id=:user,decided_by_name=:name,decided_at=NOW() WHERE r.id=:id AND r.status='pending' AND EXISTS(SELECT 1 FROM feb_human_document_review_recipients x WHERE x.review_id=r.id AND x.reviewer_user_id=:reviewer)";
        $s=$this->pdo->prepare($sql);$s->execute(['status'=>$decision,'reason'=>trim($reason)?:null,'user'=>$reviewerId,'name'=>$reviewerName,'id'=>$id,'reviewer'=>$reviewerId]);return $s->rowCount()>0;
    }

    public function fileFor(int $id,int $reviewerId): ?array
    {$s=$this->pdo->prepare("SELECT r.*,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) requester_name FROM feb_human_document_reviews r JOIN feb_human_document_review_recipients x ON x.review_id=r.id AND x.reviewer_user_id=? LEFT JOIN user_devis_banamur u ON u.id=r.requester_user_id WHERE r.id=?");$s->execute([$reviewerId,$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
}
