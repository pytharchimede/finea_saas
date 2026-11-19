<?php
declare(strict_types=1);

require_once __DIR__.'/InternationalBusinessIdentity.php';

final class ClientIdentityMigration
{
    private const VERSION=5;
    public function __construct(private PDO $pdo) {}
    public function install(): void
    {
        try {if ((int)$this->pdo->query('SELECT version FROM client_identity_schema WHERE id=1')->fetchColumn()>=self::VERSION)return;}
        catch (PDOException $e) {if ($e->getCode()!=='42S02')throw $e;}
        $name='client_identity_'.substr(hash('sha256',(string)$this->pdo->query('SELECT DATABASE()')->fetchColumn()),0,30);
        $q=$this->pdo->prepare('SELECT GET_LOCK(?,30)');$q->execute([$name]);
        if (!(int)$q->fetchColumn())throw new RuntimeException('La mise à jour clients est en cours. Réessayez.');
        try {
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS client_identity_schema(id TINYINT PRIMARY KEY,version INT NOT NULL DEFAULT 0)');
            $this->pdo->exec('INSERT IGNORE INTO client_identity_schema(id,version) VALUES(1,0)');
            if ((int)$this->pdo->query('SELECT version FROM client_identity_schema WHERE id=1')->fetchColumn()>=self::VERSION)return;
            $columns=$this->pdo->query('SHOW COLUMNS FROM client_banamur')->fetchAll(PDO::FETCH_COLUMN);
            $defs=['client_type'=>"VARCHAR(20) NOT NULL DEFAULT 'morale'",'ncc'=>'VARCHAR(80) NULL','email_client'=>'VARCHAR(190) NULL','rccm'=>'VARCHAR(190) NULL','rne'=>'VARCHAR(120) NULL',
                'tax_regime'=>'VARCHAR(100) NULL','fiscal_activity_status'=>'VARCHAR(100) NULL','fiscal_activity_label'=>'VARCHAR(100) NULL',
                'dgi_status'=>'TINYINT NOT NULL DEFAULT 0','dgi_registered_at'=>'DATETIME(3) NULL','dgi_first_registration_at'=>'DATETIME(3) NULL',
                'dgi_verified_at'=>'DATETIME NULL','dgi_response_json'=>'LONGTEXT NULL',
                'activity_start_date'=>'DATE NULL','activity_description'=>'VARCHAR(255) NULL',
                'vat_liability_status'=>'VARCHAR(100) NULL','vat_last_period'=>'VARCHAR(150) NULL'];
            $defs += InternationalBusinessIdentity::COLUMNS + ['fiscal_country'=>"VARCHAR(2) NOT NULL DEFAULT 'CI'",'siret'=>'VARCHAR(14) NULL','siren'=>'VARCHAR(9) NULL','vat_number'=>'VARCHAR(20) NULL','registry_verified_at'=>'DATETIME NULL','registry_response_json'=>'LONGTEXT NULL'];
            foreach ($defs as $key=>$def)if(!in_array($key,$columns,true))$this->pdo->exec("ALTER TABLE client_banamur ADD COLUMN $key $def");
            $duplicates=(int)$this->pdo->query("SELECT COUNT(*) FROM (SELECT NULLIF(UPPER(REGEXP_REPLACE(ncc,'[^a-zA-Z0-9]','')),'') canonical FROM client_banamur GROUP BY canonical HAVING canonical IS NOT NULL AND COUNT(*)>1)d")->fetchColumn();
            if($duplicates)throw new RuntimeException('Des NCC existants sont en doublon. Corrigez leurs rattachements avant d’activer l’unicité ; aucune fiche n’a été supprimée.');
            $engine=$this->pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='client_banamur'")->fetchColumn();
            if(strtolower((string)$engine)!=='innodb')$this->pdo->exec('ALTER TABLE client_banamur ENGINE=InnoDB');
            if(!in_array('ncc_identity',$columns,true))$this->pdo->exec("ALTER TABLE client_banamur ADD COLUMN ncc_identity VARCHAR(80) GENERATED ALWAYS AS (NULLIF(UPPER(REGEXP_REPLACE(ncc,'[^a-zA-Z0-9]','')),'')) STORED");
            $indexes=$this->pdo->query('SHOW INDEX FROM client_banamur')->fetchAll(PDO::FETCH_COLUMN,2);
            if(!in_array('uq_client_ncc_identity',$indexes,true))$this->pdo->exec('ALTER TABLE client_banamur ADD UNIQUE KEY uq_client_ncc_identity(ncc_identity)');
            if(!in_array('uq_client_identity_key',$indexes,true))$this->pdo->exec('ALTER TABLE client_banamur ADD UNIQUE KEY uq_client_identity_key(identity_key)');
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS client_identity_audit(id BIGINT AUTO_INCREMENT PRIMARY KEY,client_id INT NOT NULL,user_id INT NOT NULL DEFAULT 0,old_identity LONGTEXT NULL,new_identity LONGTEXT NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY(client_id)) ENGINE=InnoDB');
            foreach(['INSERT','UPDATE'] as $event){
                $trigger='client_identity_'.strtolower($event);
                $this->pdo->exec("DROP TRIGGER IF EXISTS $trigger");
                $this->pdo->exec("CREATE TRIGGER $trigger BEFORE $event ON client_banamur FOR EACH ROW BEGIN
                    SET NEW.ncc=NULLIF(UPPER(REGEXP_REPLACE(NEW.ncc,'[^a-zA-Z0-9]','')),'');
                    IF NEW.client_type NOT IN ('morale','physique') OR (NEW.client_type='physique' AND (NEW.ncc IS NOT NULL OR NULLIF(TRIM(NEW.identity_key),'') IS NULL OR COALESCE(NEW.identity_status,'') NOT IN ('documentary_verified','documentary_pending'))) OR (NEW.client_type='morale' AND ((NEW.fiscal_country='CI' AND (NEW.ncc IS NULL OR NEW.dgi_status<>1 OR NEW.dgi_verified_at IS NULL)) OR (NEW.fiscal_country<>'CI' AND (NULLIF(TRIM(NEW.identity_key),'') IS NULL OR COALESCE(NEW.identity_status,'') NOT IN ('registry_verified','documentary_verified','documentary_pending'))))) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CLIENT_IDENTITY_REQUIRED: renseignez une identite fiscale valable pour ce pays'; END IF;
                END");
            }
            $this->installProjectGuards();
            $this->pdo->exec('UPDATE client_identity_schema SET version='.self::VERSION.' WHERE id=1');
        } finally {$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}
    }
    private function installProjectGuards(): void
    {
        $tables=[];
        foreach($this->pdo->query('SELECT c.TABLE_NAME,c.COLUMN_NAME FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE=\'BASE TABLE\'') as $r)$tables[$r['TABLE_NAME']][]=$r['COLUMN_NAME'];
        if(!isset($tables['chantier_banamur']))return;
        $bad="((cl.client_type='morale' AND cl.fiscal_country='CI' AND (cl.ncc_identity IS NULL OR cl.dgi_status<>1 OR cl.dgi_verified_at IS NULL)) OR ((cl.fiscal_country<>'CI' OR cl.client_type='physique') AND (NULLIF(TRIM(cl.identity_key),'') IS NULL OR COALESCE(cl.identity_status,'') NOT IN ('registry_verified','documentary_verified'))))";
        foreach($tables as $table=>$columns){
            if(!preg_match('/^[a-zA-Z0-9_]+$/D',$table) || str_starts_with($table,'client_identity_'))continue;
            $tests=[];
            if(in_array('client_id',$columns,true) && in_array($table,['chantier_banamur','devis_banamur','offre_banamur','projects','project_chantiers','client_purchase_orders','facture_vente_banamur'],true))
                $tests[]="EXISTS(SELECT 1 FROM client_banamur cl WHERE cl.id_client=@ROW@.client_id AND $bad)";
            foreach(['chantier_id','id_chantier'] as $key)if(in_array($key,$columns,true))
                $tests[]="EXISTS(SELECT 1 FROM chantier_banamur c JOIN client_banamur cl ON cl.id_client=c.client_id WHERE c.id=@ROW@.$key AND $bad)";
            if(in_array('code_chantier',$columns,true))
                $tests[]="EXISTS(SELECT 1 FROM chantier_banamur c JOIN client_banamur cl ON cl.id_client=c.client_id WHERE c.code=@ROW@.code_chantier AND $bad)";
            if(isset($tables['devis_banamur']) && in_array('devis_id',$columns,true))
                $tests[]="EXISTS(SELECT 1 FROM devis_banamur d JOIN client_banamur cl ON cl.id_client=d.client_id WHERE d.id=@ROW@.devis_id AND $bad)";
            foreach(['debourse_id','id_debourse'] as $key)if(isset($tables['debourse_banamur']) && in_array($key,$columns,true))
                $tests[]="EXISTS(SELECT 1 FROM debourse_banamur b JOIN devis_banamur d ON d.id=b.devis_id JOIN client_banamur cl ON cl.id_client=d.client_id WHERE b.id=@ROW@.$key AND $bad)";
            if(isset($tables['fiche_decaissement']) && in_array('num_fiche',$columns,true) && $table!=='fiche_decaissement')
                $tests[]="EXISTS(SELECT 1 FROM fiche_decaissement f JOIN chantier_banamur c ON c.id=f.chantier_id JOIN client_banamur cl ON cl.id_client=c.client_id WHERE f.num_fiche=@ROW@.num_fiche AND $bad)";
            if(!$tests)continue;
            foreach(['INSERT'=>['NEW'],'UPDATE'=>['OLD','NEW'],'DELETE'=>['OLD']] as $event=>$versions){
                $conditions=[];
                foreach($versions as $row)foreach($tests as $test)$conditions[]=str_replace('@ROW@',$row,$test);
                $name='ncc_guard_'.substr(hash('sha256',$table.'_'.$event),0,24);
                $this->pdo->exec("DROP TRIGGER IF EXISTS $name");
                $condition=implode(' OR ',$conditions);
                $this->pdo->exec("CREATE TRIGGER $name BEFORE $event ON `$table` FOR EACH ROW BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CLIENT_IDENTITY_REQUIRED: regularisez l identite du client avant toute action sur son chantier'; END IF; END");
            }
        }
    }
}


