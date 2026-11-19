<?php
declare(strict_types=1);

final class ItAssetManager
{
    public function __construct(private PDO $pdo) { $this->ensureSchema(); }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS it_assets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            asset_code VARCHAR(40) NOT NULL UNIQUE,
            category VARCHAR(40) NOT NULL,
            brand VARCHAR(100) NULL, model VARCHAR(120) NULL, serial_number VARCHAR(150) NULL,
            assigned_to VARCHAR(190) NULL, department VARCHAR(150) NULL, location VARCHAR(190) NULL,
            cpu VARCHAR(190) NULL, ram_gb DECIMAL(8,2) NULL, storage VARCHAR(190) NULL,
            storage_type VARCHAR(20) NULL, gpu VARCHAR(190) NULL, operating_system VARCHAR(150) NULL,
            ip_address VARCHAR(45) NULL, mac_address VARCHAR(30) NULL,
            purchase_date DATE NULL, commissioned_at DATE NULL, warranty_end DATE NULL,
            acquisition_cost DECIMAL(15,2) NULL, supplier VARCHAR(190) NULL,
            condition_status VARCHAR(30) NOT NULL DEFAULT 'in_service', performance_level VARCHAR(30) NOT NULL DEFAULT 'standard',
            photo_path VARCHAR(500) NULL, notes TEXT NULL,
            created_by INT NULL, updated_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_it_asset_status (condition_status), KEY idx_it_asset_category (category),
            KEY idx_it_asset_serial (serial_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS it_incidents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, asset_id INT UNSIGNED NOT NULL,
            incident_code VARCHAR(40) NOT NULL UNIQUE, title VARCHAR(190) NOT NULL, description TEXT NOT NULL,
            priority VARCHAR(20) NOT NULL DEFAULT 'medium', status VARCHAR(25) NOT NULL DEFAULT 'open',
            reported_by VARCHAR(190) NULL, assigned_to VARCHAR(190) NULL, resolution TEXT NULL,
            reported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, resolved_at DATETIME NULL,
            created_by INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_it_incident_asset (asset_id), KEY idx_it_incident_status (status),
            CONSTRAINT fk_it_incident_asset FOREIGN KEY (asset_id) REFERENCES it_assets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try {$this->pdo->exec('ALTER TABLE it_assets ADD COLUMN assigned_user_id INT NULL AFTER serial_number');} catch (Throwable) {}
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS it_asset_media (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, asset_id INT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL, media_type VARCHAR(20) NOT NULL DEFAULT 'image',
            original_name VARCHAR(255) NULL, created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_it_asset_media_asset (asset_id),
            CONSTRAINT fk_it_asset_media_asset FOREIGN KEY (asset_id) REFERENCES it_assets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS it_incident_media (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, incident_id INT UNSIGNED NOT NULL,
            file_path VARCHAR(500) NOT NULL, media_type VARCHAR(20) NOT NULL,
            original_name VARCHAR(255) NULL, created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_it_incident_media_incident (incident_id),
            CONSTRAINT fk_it_incident_media_incident FOREIGN KEY (incident_id) REFERENCES it_incidents(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function categories(): array
    {
        return ['PC'=>'Poste fixe','LAP'=>'Ordinateur portable','SRV'=>'Serveur','PRN'=>'Imprimante','NET'=>'Équipement réseau','TEL'=>'Téléphone / tablette','UPS'=>'Onduleur','OTH'=>'Autre matériel'];
    }

    public static function conditions(): array
    {
        return ['in_service'=>'En service','stock'=>'En stock','maintenance'=>'En maintenance','retired'=>'Réformé','lost'=>'Perdu / volé'];
    }

    public static function performance(array $data): string
    {
        $ram=(float)($data['ram_gb']??0);$cpu=mb_strtolower((string)($data['cpu']??''));$storage=mb_strtolower((string)($data['storage_type']??'').' '.(string)($data['storage']??''));
        if ($ram > 0 && ($ram < 8 || str_contains($storage,'hdd') || preg_match('/core\s+i[235]-[1-7]\d{3}/',$cpu))) return 'replace';
        if ($ram >= 16 && (str_contains($storage,'ssd') || str_contains($storage,'nvme')) && preg_match('/(i[57]|ryzen\s+[57]|xeon)/',$cpu)) return 'performant';
        return 'standard';
    }

    public function nextCode(string $category): string
    {
        $category=array_key_exists($category,self::categories())?$category:'OTH';
        $stmt=$this->pdo->prepare('SELECT asset_code FROM it_assets WHERE asset_code LIKE :prefix ORDER BY id DESC LIMIT 1');
        $stmt->execute(['prefix'=>'BAN-IT-'.$category.'-%']);$last=(string)$stmt->fetchColumn();
        $number=preg_match('/(\d+)$/',$last,$m)?((int)$m[1]+1):1;
        return sprintf('BAN-IT-%s-%04d',$category,$number);
    }

    public function saveAsset(array $data, int $userId, ?string $photoPath=null): int
    {
        if(!empty($data['_media_upload_error']))throw new RuntimeException((string)$data['_media_upload_error']);
        $id=(int)($data['id']??0);$category=array_key_exists((string)($data['category']??''),self::categories())?(string)$data['category']:'OTH';
        $existing=$id>0?$this->findAsset($id):null;if($id>0&&!$existing)throw new DomainException('Matériel introuvable.');
        $code=trim((string)($data['asset_code']??''))?:($existing['asset_code']??$this->nextCode($category));$performance=self::performance($data);
        $assignedUserId=(int)($data['assigned_user_id']??0)?:null;$assignedName=trim((string)($data['assigned_to']??''))?:null;
        if($assignedUserId){$user=$this->findUser($assignedUserId);if(!$user)throw new DomainException('Utilisateur affecté introuvable.');$assignedName=$user['name'];$data['department']=$user['department']?:($data['department']??'');$data['location']=$user['site']?:($data['location']??'');}
        $ip=trim((string)($data['ip_address']??''));if($ip!==''&&filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)===false)throw new DomainException('Adresse IPv4 invalide.');
        $fields=['asset_code'=>$code,'category'=>$category,'brand'=>trim((string)($data['brand']??''))?:null,'model'=>trim((string)($data['model']??''))?:null,'serial_number'=>trim((string)($data['serial_number']??''))?:null,'assigned_user_id'=>$assignedUserId,'assigned_to'=>$assignedName,'department'=>trim((string)($data['department']??''))?:null,'location'=>trim((string)($data['location']??''))?:null,'cpu'=>trim((string)($data['cpu']??''))?:null,'ram_gb'=>(float)($data['ram_gb']??0)?:null,'storage'=>trim((string)($data['storage']??''))?:null,'storage_type'=>trim((string)($data['storage_type']??''))?:null,'gpu'=>trim((string)($data['gpu']??''))?:null,'operating_system'=>trim((string)($data['operating_system']??''))?:null,'ip_address'=>$ip?:null,'mac_address'=>trim((string)($data['mac_address']??''))?:null,'purchase_date'=>($data['purchase_date']??'')?:null,'commissioned_at'=>($data['commissioned_at']??'')?:null,'warranty_end'=>($data['warranty_end']??'')?:null,'acquisition_cost'=>(float)($data['acquisition_cost']??0)?:null,'supplier'=>trim((string)($data['supplier']??''))?:null,'condition_status'=>array_key_exists((string)($data['condition_status']??''),self::conditions())?(string)$data['condition_status']:'in_service','performance_level'=>$performance,'notes'=>trim((string)($data['notes']??''))?:null,'updated_by'=>$userId];
        if($photoPath!==null)$fields['photo_path']=$photoPath;
        if($id>0){$sets=[];foreach($fields as $key=>$value)$sets[]="$key=:$key";$fields['id']=$id;$this->pdo->prepare('UPDATE it_assets SET '.implode(',',$sets).' WHERE id=:id')->execute($fields);if(!empty($data['_asset_media']))$this->addAssetMedia($id,(array)$data['_asset_media'],$userId);return $id;}
        $fields['created_by']=$userId;$columns=array_keys($fields);$this->pdo->prepare('INSERT INTO it_assets ('.implode(',',$columns).') VALUES (:'.implode(',:',$columns).')')->execute($fields);$id=(int)$this->pdo->lastInsertId();if(!empty($data['_asset_media'])){$this->addAssetMedia($id,(array)$data['_asset_media'],$userId);$first=$data['_asset_media'][0]['path']??null;if($first)$this->pdo->prepare('UPDATE it_assets SET photo_path=:path WHERE id=:id')->execute(['path'=>$first,'id'=>$id]);}return $id;
    }

    public function saveIncident(array $data,int $userId): int
    {
        if(!empty($data['_media_upload_error']))throw new RuntimeException((string)$data['_media_upload_error']);
        $assetId=(int)($data['asset_id']??0);if(!$this->findAsset($assetId))throw new DomainException('Matériel introuvable.');
        if(trim((string)($data['title']??''))===''||trim((string)($data['description']??''))==='')throw new DomainException('L’objet et la description de l’incident sont obligatoires.');
        $code='BAN-INC-'.date('Ym').'-'.str_pad((string)(((int)$this->pdo->query('SELECT COUNT(*) FROM it_incidents')->fetchColumn())+1),4,'0',STR_PAD_LEFT);
        $stmt=$this->pdo->prepare('INSERT INTO it_incidents(asset_id,incident_code,title,description,priority,status,reported_by,assigned_to,created_by) VALUES(:asset,:code,:title,:description,:priority,:status,:reporter,:assigned,:user)');
        $stmt->execute(['asset'=>$assetId,'code'=>$code,'title'=>trim((string)$data['title']),'description'=>trim((string)$data['description']),'priority'=>in_array($data['priority']??'', ['low','medium','high','critical'],true)?$data['priority']:'medium','status'=>'open','reporter'=>trim((string)($data['reported_by']??''))?:null,'assigned'=>trim((string)($data['assigned_to']??''))?:null,'user'=>$userId]);$id=(int)$this->pdo->lastInsertId();if(!empty($data['_incident_media']))$this->addIncidentMedia($id,(array)$data['_incident_media'],$userId);return $id;
    }

    public function updateIncident(int $id,string $status,string $resolution): void
    {
        if(!in_array($status,['open','in_progress','resolved','closed'],true))throw new DomainException('Statut invalide.');
        $this->pdo->prepare('UPDATE it_incidents SET status=:status,resolution=:resolution,resolved_at=IF(:done=1,NOW(),NULL) WHERE id=:id')->execute(['status'=>$status,'resolution'=>trim($resolution)?:null,'done'=>(int)in_array($status,['resolved','closed'],true),'id'=>$id]);
    }

    public function assets(array $filters=[]): array
    {
        $where=[];$params=[];$q=trim((string)($filters['q']??''));if($q!==''){$where[]='CONCAT_WS(" ",a.asset_code,a.brand,a.model,a.serial_number,a.assigned_to,u.mail_pro,a.department,a.location,a.cpu,a.operating_system) LIKE :q';$params['q']='%'.$q.'%';}if(!empty($filters['status'])){$where[]='a.condition_status=:status';$params['status']=$filters['status'];}if(!empty($filters['category'])){$where[]='a.category=:category';$params['category']=$filters['category'];}$sql='SELECT a.*,u.mail_pro assigned_user_email,(SELECT COUNT(*) FROM it_incidents i WHERE i.asset_id=a.id AND i.status IN("open","in_progress")) open_incidents FROM it_assets a LEFT JOIN user_devis_banamur u ON u.id=a.assigned_user_id'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY a.id DESC';$stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    public function findAsset(int $id): ?array {$s=$this->pdo->prepare('SELECT * FROM it_assets WHERE id=:id');$s->execute(['id'=>$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
    public function users(): array {$sql="SELECT u.id,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) name,u.mail_pro,COALESCE(s.lib_service,'') department,COALESCE(rs.label,CASE WHEN p.site_id=1 THEN 'Abidjan' WHEN p.site_id IS NOT NULL THEN CONCAT('Site ',p.site_id) ELSE '' END) site FROM user_devis_banamur u LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id LEFT JOIN service s ON s.id_service=p.service_id LEFT JOIN rh_sites rs ON rs.code=p.site_id WHERE u.active=1 AND (u.personnel_id IS NULL OR p.date_sortie IS NULL OR p.date_sortie='0000-00-00') ORDER BY name";return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];}
    private function findUser(int $id): ?array {$s=$this->pdo->prepare("SELECT u.id,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) name,COALESCE(s.lib_service,'') department,COALESCE(rs.label,CASE WHEN p.site_id=1 THEN 'Abidjan' WHEN p.site_id IS NOT NULL THEN CONCAT('Site ',p.site_id) ELSE '' END) site FROM user_devis_banamur u LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id LEFT JOIN service s ON s.id_service=p.service_id LEFT JOIN rh_sites rs ON rs.code=p.site_id WHERE u.id=:id AND u.active=1");$s->execute(['id'=>$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
    public function suppliers(): array {$sql="SELECT id_fournisseur id,TRIM(COALESCE(NULLIF(nom_commercial,''),NULLIF(raison_sociale,''),'Fournisseur sans nom')) name,code_fournisseur code FROM fournisseur_banamur WHERE actif=1 ORDER BY name";try{return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return [];}}
    public function technicians(): array
    {
        $sql="SELECT u.id,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) name,
            COALESCE(s.lib_service,'') department,COALESCE(f.lib_fonction,'') job_title
            FROM user_devis_banamur u
            JOIN personnel p ON p.id_personnel_soignant=u.personnel_id
            LEFT JOIN service s ON s.id_service=p.service_id
            LEFT JOIN fonction f ON f.id_fonction=p.fonction_id
            WHERE u.active=1 AND (p.date_sortie IS NULL OR p.date_sortie='0000-00-00')
              AND LOWER(COALESCE(s.lib_service,'')) LIKE '%informatique%'
            ORDER BY CASE WHEN UPPER(REPLACE(REPLACE(COALESCE(f.lib_fonction,''),'É','E'),'È','E')) LIKE '%CHARGE D%INFRASTRUCTURE%RESEAU%' THEN 0 ELSE 1 END,name";
        try{return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return [];}
    }
    public function addAssetMedia(int $assetId,array $media,int $userId): void {$stmt=$this->pdo->prepare('INSERT INTO it_asset_media(asset_id,file_path,media_type,original_name,created_by) VALUES(:id,:path,:type,:name,:user)');foreach($media as $item)$stmt->execute(['id'=>$assetId,'path'=>$item['path'],'type'=>$item['type'],'name'=>$item['name']??null,'user'=>$userId]);}
    public function addIncidentMedia(int $incidentId,array $media,int $userId): void {$stmt=$this->pdo->prepare('INSERT INTO it_incident_media(incident_id,file_path,media_type,original_name,created_by) VALUES(:id,:path,:type,:name,:user)');foreach($media as $item)$stmt->execute(['id'=>$incidentId,'path'=>$item['path'],'type'=>$item['type'],'name'=>$item['name']??null,'user'=>$userId]);}
    public function assetMedia(int $assetId): array {$s=$this->pdo->prepare('SELECT * FROM it_asset_media WHERE asset_id=:id ORDER BY id');$s->execute(['id'=>$assetId]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    public function incidentMedia(int $incidentId): array {$s=$this->pdo->prepare('SELECT * FROM it_incident_media WHERE incident_id=:id ORDER BY id');$s->execute(['id'=>$incidentId]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    public function incidents(?int $assetId=null): array {$sql='SELECT i.*,a.asset_code,a.brand,a.model FROM it_incidents i JOIN it_assets a ON a.id=i.asset_id'.($assetId?' WHERE i.asset_id=:id':'').' ORDER BY FIELD(i.status,"open","in_progress","resolved","closed"),i.reported_at DESC';$s=$this->pdo->prepare($sql);$s->execute($assetId?['id'=>$assetId]:[]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    public function stats(): array {return ['total'=>(int)$this->pdo->query('SELECT COUNT(*) FROM it_assets')->fetchColumn(),'service'=>(int)$this->pdo->query("SELECT COUNT(*) FROM it_assets WHERE condition_status='in_service'")->fetchColumn(),'maintenance'=>(int)$this->pdo->query("SELECT COUNT(*) FROM it_assets WHERE condition_status='maintenance'")->fetchColumn(),'incidents'=>(int)$this->pdo->query("SELECT COUNT(*) FROM it_incidents WHERE status IN('open','in_progress')")->fetchColumn()];}
}
