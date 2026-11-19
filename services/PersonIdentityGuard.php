<?php
declare(strict_types=1);

/** Shared canonical identity numbers. Employee matches never disclose HR data to suppliers. */
final class PersonIdentityGuard
{
    public const EMPLOYEE_MESSAGE='Cette pièce correspond à un membre du personnel. Les membres du personnel ne peuvent pas être enregistrés comme fournisseurs. Utilisez le circuit RH.';
    public static function normalize(string $value): string
    {
        $value=strtr(trim($value),['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','È'=>'E','Ê'=>'E','à'=>'a','À'=>'A','ô'=>'o','Ô'=>'O','î'=>'i','ï'=>'i','ù'=>'u','ç'=>'c']);
        $value=strtoupper(preg_replace('/[^a-zA-Z0-9]/','',$value));
        if (strlen($value)<3 || strlen($value)>80 || preg_match('/^0+$/D',$value) || in_array($value,['NEANT','NA','INCONNU','ENCOURS','SANS','NONRENSEIGNE'],true)) {
            throw new DomainException('Renseignez un numéro de pièce d’identité valide (3 à 80 lettres ou chiffres).');
        }
        return $value;
    }
    public static function ensureSchema(PDO $pdo): void
    {
        $columns=$pdo->query('SHOW COLUMNS FROM personnel')->fetchAll(PDO::FETCH_ASSOC);
        $byName=array_column($columns,null,'Field');
        if(!isset($byName['num_cni']))$pdo->exec('ALTER TABLE personnel ADD num_cni VARCHAR(80) NULL');
        elseif(preg_match('/^varchar\((\d+)\)$/i',(string)$byName['num_cni']['Type'],$m)&&(int)$m[1]<80)$pdo->exec('ALTER TABLE personnel MODIFY num_cni VARCHAR(80) NULL');
        if(!isset($byName['piece_identite_path']))$pdo->exec('ALTER TABLE personnel ADD piece_identite_path VARCHAR(255) NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS personnel_identity_registry (
            identity_number VARCHAR(80) NOT NULL, personnel_id INT NOT NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(identity_number,personnel_id), KEY idx_identity_personnel(personnel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
    public static function employeeExists(PDO $pdo,string $number,bool $lock=false): bool
    {
        $number=self::normalize($number);
        $suffix=$lock&&$pdo->inTransaction()?' FOR UPDATE':'';
        $q=$pdo->prepare("SELECT 1 FROM personnel WHERE REGEXP_REPLACE(UPPER(TRIM(COALESCE(num_cni,''))),'[^A-Z0-9]','')=? LIMIT 1".$suffix);
        $q->execute([$number]);
        if ($q->fetchColumn()) return true;
        $q=$pdo->prepare('SELECT 1 FROM personnel_identity_registry WHERE identity_number=? LIMIT 1'.$suffix);$q->execute([$number]);
        return (bool)$q->fetchColumn();
    }
    public static function verifySupplier(PDO $pdo,array $data,array $old=[]): array
    {
        $number=self::normalize((string)($data['identity_document_number']??$old['identity_document_number']??''));
        if (self::employeeExists($pdo,$number)) throw new DomainException(self::EMPLOYEE_MESSAGE);
        $q=$pdo->prepare('SELECT id_fournisseur FROM fournisseur_banamur WHERE identity_document_number=? AND id_fournisseur<>? LIMIT 1');
        $q->execute([$number,(int)($old['id_fournisseur']??0)]);
        if ($q->fetchColumn()) throw new DomainException('Cette pièce est déjà rattachée à une fiche fournisseur. Ouvrez la fiche existante.');
        $data['identity_document_number']=$number;
        return $data;
    }
    public static function locked(PDO $pdo,array $numbers,callable $action): mixed
    {
        $numbers=array_values(array_unique(array_map([self::class,'normalize'],$numbers)));sort($numbers,SORT_STRING);
        $locks=[];
        try {
            foreach ($numbers as $number) {
                $key='finea_identity_'.substr(hash('sha256',$number),0,40);
                $q=$pdo->prepare('SELECT GET_LOCK(?,10)');$q->execute([$key]);
                if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Cette identité est en cours de mise à jour. Réessayez.');
                $locks[]=$key;
            }
            return $action();
        } finally {foreach(array_reverse($locks) as $key){try{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$key]);}catch(Throwable $e){error_log('FINEA identity lock release failed');}}}
    }
    public static function supplierWrite(PDO $pdo,array $data,array $old,callable $action): array
    {
        try {
            if (($data['supplier_type']??$old['supplier_type']??'morale')!=='physique') return $action();
            return self::locked($pdo,[(string)($data['identity_document_number']??$old['identity_document_number']??'')],$action);
        } catch (DomainException $e) {return ['ok'=>false,'error'=>$e->getMessage()];}
        catch (RuntimeException $e) {return ['ok'=>false,'error'=>'Contrôle des pièces indisponible. Réessayez ou contactez les RH.'];}
    }
    public static function requirePersonnelIdentity(array $data,array $old=[],?string $root=null): array
    {
        $data['cni_number']=self::normalize((string)($data['cni_number']??$old['cni_number']??''));
        $path=trim((string)($data['identity_document_path']??$old['identity_document_path']??''));
        $root=realpath($root??dirname(__DIR__));
        $real=$path!==''&&$root?realpath($root.'/'.ltrim(str_replace('\\','/',$path),'/')):false;
        if (!$real || !$root || !str_starts_with($real,$root.'/') || !is_file($real) || !preg_match('/\.(pdf|docx?|jpe?g|png|webp)$/i',$real)) throw new DomainException('La pièce d’identité jointe est obligatoire. Téléversez une copie lisible dans le dossier RH.');
        if (!empty($old['cni_number'])) {
            try {$oldNumber=self::normalize((string)$old['cni_number']);} catch (DomainException $e) {$oldNumber='';}
            if ($oldNumber!==$data['cni_number'] && $path===($old['identity_document_path']??'')) throw new DomainException('Le numéro de pièce a changé : joignez la pièce correspondante avant d’enregistrer.');
        }
        $data['identity_document_path']=$path;
        return $data;
    }
    public static function recordPersonnel(PDO $pdo,int $id,array $numbers): void
    {
        $q=$pdo->prepare('INSERT IGNORE INTO personnel_identity_registry(identity_number,personnel_id) VALUES(?,?)');
        foreach($numbers as $number){try{$number=self::normalize((string)$number);}catch(DomainException $e){continue;}$q->execute([$number,$id]);self::suspendSuppliers($pdo,$number);}
    }
    private static function suspendSuppliers(PDO $pdo,string $number): void
    {
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fournisseur_banamur' AND COLUMN_NAME='identity_document_number'");
        $q->execute();if(!(int)$q->fetchColumn())return;
        $q=$pdo->prepare("UPDATE fournisseur_banamur SET actif=0,validation_status='rejected',validation_note='Pièce rattachée au personnel : fournisseur interdit. Utiliser le circuit RH.' WHERE supplier_type='physique' AND identity_document_number=?");$q->execute([$number]);
    }
    public static function incompletePersonnel(PDO $pdo,?string $root=null): array
    {
        $rows=$pdo->query('SELECT id_personnel_soignant,nom_personnel_soignant,num_cni AS cni_number,piece_identite_path AS identity_document_path FROM personnel ORDER BY nom_personnel_soignant')->fetchAll(PDO::FETCH_ASSOC);
        $missing=[];
        foreach($rows as $row){try{self::requirePersonnelIdentity($row,[],$root);}catch(DomainException $e){unset($row['cni_number'],$row['identity_document_path']);$row['identity_missing_reason']=$e->getMessage();$missing[]=$row;}}
        return $missing;
    }
    public static function backfill(PDO $pdo): int
    {
        self::ensureSchema($pdo);$count=0;
        foreach($pdo->query("SELECT id_personnel_soignant,num_cni FROM personnel WHERE num_cni IS NOT NULL AND TRIM(num_cni)<>''")->fetchAll(PDO::FETCH_ASSOC) as $row){
            try{$number=self::normalize((string)$row['num_cni']);}catch(DomainException $e){continue;}
            self::locked($pdo,[$number],function()use($pdo,$row,$number){self::recordPersonnel($pdo,(int)$row['id_personnel_soignant'],[$number]);});$count++;
        }
        return $count;
    }
}
