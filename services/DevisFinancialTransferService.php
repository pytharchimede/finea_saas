<?php
declare(strict_types=1);

require_once __DIR__ . '/DevisFinancialTransferMigrationService.php';
require_once __DIR__ . '/CommercialProjectFinanceMigrationService.php';
require_once __DIR__ . '/ActiveChantierEditPermissionService.php';

final class DevisFinancialTransferService
{
    private array $columns = [];

    public function __construct(private PDO $pdo)
    {
        (new CommercialProjectFinanceMigrationService($pdo))->ensureSchema();
        (new DevisFinancialTransferMigrationService($pdo))->ensureSchema();
    }

    public function quotes(): array
    {
        return $this->pdo->query("SELECT d.id,d.numero_devis,d.client_id,d.offre_id,d.total_ttc,
                COALESCE(NULLIF(TRIM(o.reference_offre),''),CONCAT('Projet #',d.offre_id)) projet,
                COALESCE(NULLIF(TRIM(c.nom_client),''),CONCAT('Client #',d.client_id)) client
            FROM devis_banamur d
            LEFT JOIN offre_banamur o ON o.id_offre=d.offre_id
            LEFT JOIN client_banamur c ON c.id_client=d.client_id
            WHERE COALESCE(d.masque,0)=0
              AND NOT EXISTS(SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL)
            ORDER BY d.created_at DESC,d.id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function describe(int $id): array
    {
        $q=$this->pdo->prepare("SELECT * FROM devis_banamur WHERE id=? LIMIT 1");$q->execute([$id]);
        $quote=$q->fetch(PDO::FETCH_ASSOC);
        if (!$quote) throw new DomainException('Devis introuvable.');
        $q=$this->pdo->prepare("SELECT l.*,COUNT(d.id) debourse_count,COALESCE(SUM(d.montant_debourse),0) debourse_total
            FROM ligne_devis_banamur l LEFT JOIN debourse_banamur d ON d.ligne_devis_id=l.id
            WHERE l.devis_id=? GROUP BY l.id ORDER BY l.id");$q->execute([$id]);
        $lines=$q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $q=$this->pdo->prepare("SELECT d.*,COUNT(ld.id) line_count FROM debourse_banamur d
            LEFT JOIN ligne_debourse_banamur ld ON ld.debourse_id=d.id WHERE d.devis_id=?
            GROUP BY d.id ORDER BY d.id");$q->execute([$id]);
        return ['quote'=>$quote,'lines'=>$lines,'debourses'=>$q->fetchAll(PDO::FETCH_ASSOC) ?: []];
    }

    public function history(int $limit=20): array
    {
        $limit=max(1,min(100,$limit));
        return $this->pdo->query("SELECT t.*,s.numero_devis source_numero,c.numero_devis target_numero,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.prenom,u.nom)),''),CONCAT('Utilisateur #',t.created_by)) auteur
            FROM devis_financial_transfers t
            LEFT JOIN devis_banamur s ON s.id=t.source_devis_id
            LEFT JOIN devis_banamur c ON c.id=t.target_devis_id
            LEFT JOIN user_devis_banamur u ON u.id=t.created_by
            ORDER BY t.id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function transfer(int $sourceId,int $targetId,array $lineMap,array $debourseMap,bool $moveSites,string $reason,int $userId): array
    {
        if ($sourceId<=0 || $targetId<=0 || $sourceId===$targetId) throw new DomainException('Sélectionnez deux devis différents.');
        if (mb_strlen(trim($reason))<5) throw new DomainException('Précisez la raison de la fusion.');
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        $transferId=0;
        try {
            $source=$this->lockedQuote($sourceId);$target=$this->lockedQuote($targetId);
            if (!$source || !$target) throw new DomainException('Le devis source ou cible est introuvable.');
            ActiveChantierEditPermissionService::assertCanEdit($this->pdo, $sourceId);
            ActiveChantierEditPermissionService::assertCanEdit($this->pdo, $targetId);
            if ((int)$source['client_id'] !== (int)$target['client_id'] || (int)$source['offre_id'] !== (int)$target['offre_id']) {
                throw new DomainException('Sécurité : la source et la cible doivent appartenir au même client et au même projet.');
            }
            $snapshot=$this->describe($sourceId);
            $ins=$this->pdo->prepare("INSERT INTO devis_financial_transfers(source_devis_id,target_devis_id,reason,mapping_json,snapshot_json,created_by)
                VALUES(?,?,?,?,?,?)");
            $ins->execute([$sourceId,$targetId,trim($reason),json_encode(['lines'=>$lineMap,'debourses'=>$debourseMap,'sites'=>$moveSites],JSON_UNESCAPED_UNICODE),json_encode($snapshot,JSON_UNESCAPED_UNICODE),$userId?:null]);
            $transferId=(int)$this->pdo->lastInsertId();

            foreach ($snapshot['lines'] as $line) {
                $sourceLine=(int)$line['id'];$targetLine=(int)($lineMap[$sourceLine]??0);
                if ($targetLine>0 && !$this->belongs('ligne_devis_banamur',$targetLine,'devis_id',$targetId)) throw new DomainException("Correspondance invalide pour la ligne #$sourceLine.");
                if ($targetLine<=0) {
                    $this->exec("UPDATE ligne_devis_banamur SET devis_id=? WHERE id=?",[$targetId,$sourceLine]);
                    $targetLine=$sourceLine;
                }
                $debourses=array_filter($snapshot['debourses'],fn($d)=>(int)$d['ligne_devis_id']===$sourceLine);
                foreach($debourses as $d) {
                    $sourceDeb=(int)$d['id'];$targetDeb=(int)($debourseMap[$sourceDeb]??0);
                    if ($targetDeb>0) {
                        if (!$this->belongs('debourse_banamur',$targetDeb,'devis_id',$targetId)) throw new DomainException("Déboursé cible #$targetDeb invalide.");
                        if (!$this->belongs('debourse_banamur',$targetDeb,'ligne_devis_id',$targetLine)) {
                            throw new DomainException("Le déboursé cible #$targetDeb n’appartient pas à la ligne de devis choisie. Corrigez l’une des deux correspondances.");
                        }
                        $this->exec("UPDATE ligne_debourse_banamur SET debourse_id=? WHERE debourse_id=?",[$targetDeb,$sourceDeb]);
                        $this->updateFebLinks($sourceId,$targetId,$sourceLine,$targetLine,$sourceDeb,$targetDeb);
                        $this->replaceReferences('debourse_id',$sourceDeb,$targetDeb,['debourse_banamur','ligne_debourse_banamur']);
                        $this->exec("DELETE FROM debourse_banamur WHERE id=?",[$sourceDeb]);
                        $this->recalculateDebourse($targetDeb);
                    } else {
                        $this->exec("UPDATE debourse_banamur SET devis_id=?,ligne_devis_id=? WHERE id=?",[$targetId,$targetLine,$sourceDeb]);
                        $this->updateFebLinks($sourceId,$targetId,$sourceLine,$targetLine,$sourceDeb,$sourceDeb);
                    }
                }
                $this->replaceReferences('ligne_devis_id',$sourceLine,$targetLine,['ligne_devis_banamur','debourse_banamur']);
                if ($targetLine!==$sourceLine) $this->exec("DELETE FROM ligne_devis_banamur WHERE id=? AND NOT EXISTS(SELECT 1 FROM debourse_banamur WHERE ligne_devis_id=?)",[$sourceLine,$sourceLine]);
            }
            if ($moveSites) $this->exec("UPDATE chantier_banamur SET devis_id=? WHERE devis_id=?",[$targetId,$sourceId]);
            elseif ($this->scalar("SELECT COUNT(*) FROM chantier_banamur WHERE devis_id=?",[$sourceId])>0) throw new DomainException('Le devis source possède un chantier : activez son rattachement au devis cible.');
            foreach(['client_purchase_orders','devis_billing_schedule'] as $table) $this->updateIfColumn($table,'devis_id',$sourceId,$targetId);
            $this->mergeBillingAlert($sourceId,$targetId);
            $this->recalculateQuote($targetId);
            $this->exec("UPDATE devis_banamur SET total_ht=0,total_ttc=0 WHERE id=?",[$sourceId]);
            if ($this->scalar("SELECT COUNT(*) FROM ligne_devis_banamur WHERE devis_id=?",[$sourceId])>0 || $this->scalar("SELECT COUNT(*) FROM debourse_banamur WHERE devis_id=?",[$sourceId])>0)
                throw new RuntimeException('Contrôle final refusé : des éléments financiers restent attachés au devis source.');
            $result=['transfer_id'=>$transferId,'source'=>$sourceId,'target'=>$targetId,'archivable'=>true,'moved_lines'=>count($snapshot['lines']),'moved_debourses'=>count($snapshot['debourses'])];
            $this->exec("UPDATE devis_financial_transfers SET status='completed',result_json=?,completed_at=NOW() WHERE id=?",[json_encode($result,JSON_UNESCAPED_UNICODE),$transferId]);
            if($ownsTransaction)$this->pdo->commit();
            return $result;
        } catch(Throwable $e) {
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            if(!$ownsTransaction)throw $e;
            try {
                $failed=$this->pdo->prepare("INSERT INTO devis_financial_transfers
                    (source_devis_id,target_devis_id,status,reason,mapping_json,result_json,created_by,completed_at)
                    VALUES(?,?,'failed',?,?,?, ?,NOW())");
                $failed->execute([$sourceId,$targetId,trim($reason)?:'Tentative de fusion',
                    json_encode(['lines'=>$lineMap,'debourses'=>$debourseMap,'sites'=>$moveSites],JSON_UNESCAPED_UNICODE),
                    json_encode(['error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE),$userId?:null]);
            } catch(Throwable) {}
            throw $e;
        }
    }

    private function lockedQuote(int $id): array|false {$q=$this->pdo->prepare("SELECT * FROM devis_banamur WHERE id=? FOR UPDATE");$q->execute([$id]);return $q->fetch(PDO::FETCH_ASSOC);}
    private function belongs(string $table,int $id,string $fk,int $parent): bool {return $this->scalar("SELECT COUNT(*) FROM `$table` WHERE id=? AND `$fk`=?",[$id,$parent])>0;}
    private function exec(string $sql,array $p=[]): void {$q=$this->pdo->prepare($sql);$q->execute($p);}
    private function scalar(string $sql,array $p=[]): int {$q=$this->pdo->prepare($sql);$q->execute($p);return (int)$q->fetchColumn();}
    private function tableExists(string $table): bool {return $this->scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$table])>0;}
    private function hasColumn(string $table,string $column): bool {$k=$table.'.'.$column;if(!array_key_exists($k,$this->columns))$this->columns[$k]=$this->scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",[$table,$column])>0;return $this->columns[$k];}
    private function updateIfColumn(string $table,string $column,int $old,int $new): void {if($this->tableExists($table)&&$this->hasColumn($table,$column))$this->exec("UPDATE `$table` SET `$column`=? WHERE `$column`=?",[$new,$old]);}
    private function replaceReferences(string $column,int $old,int $new,array $except=[]): void {
        if($old===$new)return;$q=$this->pdo->prepare("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=?");$q->execute([$column]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN) as $t)if(!in_array($t,$except,true)&&preg_match('/^[a-z0-9_]+$/i',$t))$this->exec("UPDATE `$t` SET `$column`=? WHERE `$column`=?",[$new,$old]);
    }
    private function updateFebLinks(int $sd,int $td,int $sl,int $tl,int $sb,int $tb): void {
        if(!$this->tableExists('feb_disbursement_links'))return;
        $sets=[];$params=[];foreach(['devis_id'=>$td,'ligne_devis_id'=>$tl,'debourse_id'=>$tb] as $c=>$v)if($this->hasColumn('feb_disbursement_links',$c)){ $sets[]="`$c`=?";$params[]=$v;}
        if($sets){$params[]=$sb;$this->exec("UPDATE feb_disbursement_links SET ".implode(',',$sets)." WHERE debourse_id=?",$params);}
    }
    private function recalculateDebourse(int $id): void {$this->exec("UPDATE debourse_banamur SET montant_debourse=(SELECT COALESCE(SUM(montant),0) FROM ligne_debourse_banamur WHERE debourse_id=?) WHERE id=?",[$id,$id]);}
    private function recalculateQuote(int $id): void {
        $ht=(float)$this->pdo->query("SELECT COALESCE(SUM(total),0) FROM ligne_devis_banamur WHERE devis_id=".(int)$id)->fetchColumn();
        $q=$this->pdo->prepare("SELECT tva_facturable,tva,remise FROM devis_banamur WHERE id=?");$q->execute([$id]);$d=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $discount=max(0,(float)($d['remise']??0));$base=max(0,$ht-($discount<=100?$ht*$discount/100:$discount));
        $ttc=$base+(!empty($d['tva_facturable'])?$base*(float)($d['tva']??0)/100:0);
        $this->exec("UPDATE devis_banamur SET total_ht=?,total_ttc=? WHERE id=?",[$ht,$ttc,$id]);
    }
    private function mergeBillingAlert(int $source,int $target): void {
        if(!$this->tableExists('devis_billing_alerts'))return;
        if($this->scalar("SELECT COUNT(*) FROM devis_billing_alerts WHERE devis_id=?",[$target])>0)$this->exec("DELETE FROM devis_billing_alerts WHERE devis_id=?",[$source]);
        else $this->exec("UPDATE devis_billing_alerts SET devis_id=? WHERE devis_id=?",[$target,$source]);
    }
}
