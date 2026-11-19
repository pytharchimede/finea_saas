<?php
declare(strict_types=1);

require_once __DIR__ . '/ChantierCreationGuardService.php';
require_once __DIR__ . '/ChantierReliabilityAlertService.php';

final class ChantierFinancialComplianceService
{
    private ChantierCreationGuardService $guard;

    public function __construct(private PDO $pdo)
    {
        $this->guard = new ChantierCreationGuardService($pdo);
        foreach (['financial_data_status' => "ENUM('unchecked','compliant','incomplete') NOT NULL DEFAULT 'unchecked'", 'financial_data_checked_at' => 'DATETIME NULL', 'financial_data_issue' => 'VARCHAR(500) NULL'] as $column => $definition) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="chantier_banamur" AND COLUMN_NAME=:column');
            $statement->execute(['column' => $column]);
            if (!(int) $statement->fetchColumn()) $pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN `$column` $definition");
        }
    }

    public function auditActive(): array
    {
        $rows = $this->pdo->query('SELECT id,devis_id FROM chantier_banamur WHERE archived_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = ['total' => count($rows), 'compliant' => 0, 'incomplete' => 0, 'issues' => []];
        foreach ($rows as $row) {
            $assessment = $this->auditOne((int) $row['id'], (int) $row['devis_id']);
            $result[$assessment['status']]++;
            if ($assessment['status'] === 'incomplete') $result['issues'][$assessment['issue']] = ($result['issues'][$assessment['issue']] ?? 0) + 1;
        }
        (new ChantierReliabilityAlertService($this->pdo))->evaluate($result);
        return $result;
    }

    public function auditOne(int $chantierId, ?int $devisId = null): array
    {
        if ($devisId === null) {
            $statement = $this->pdo->prepare('SELECT devis_id FROM chantier_banamur WHERE id=:id AND archived_at IS NULL');
            $statement->execute(['id' => $chantierId]);
            $devisId = (int) $statement->fetchColumn();
        }
        $issues = $this->diagnose((int)$devisId);
        try { $details = $this->guard->validate((int) $devisId); }
        catch (Throwable $e) { $details = []; if($issues===[])$issues[]=$e->getMessage(); }
        $status = $issues === [] ? 'compliant' : 'incomplete';
        $issue = $issues === [] ? null : mb_substr(implode(' | ', $issues), 0, 500);
        $update = $this->pdo->prepare('UPDATE chantier_banamur SET financial_data_status=:status,financial_data_checked_at=NOW(),financial_data_issue=:issue WHERE id=:id AND archived_at IS NULL');
        $update->execute(['status' => $status, 'issue' => $issue, 'id' => $chantierId]);
        return ['status' => $status, 'issue' => $issue, 'issues'=>$issues, 'details' => $details];
    }

    public function diagnose(int $devisId): array
    {
        $issues=[];$q=$this->pdo->prepare('SELECT id,total_ttc,project_start_date,project_end_date FROM devis_banamur WHERE id=:id');$q->execute(['id'=>$devisId]);$quote=$q->fetch(PDO::FETCH_ASSOC);
        if(!$quote)return ['Devis principal introuvable ou non rattaché.'];
        $total=(float)$quote['total_ttc'];if($total<=0)$issues[]='Montant TTC du devis nul ou non renseigné.';
        if(empty($quote['project_start_date'])||empty($quote['project_end_date'])||$quote['project_end_date']<$quote['project_start_date'])$issues[]='Planning général du devis incomplet ou incohérent.';
        $lines=$this->pdo->prepare('SELECT COUNT(*) FROM ligne_devis_banamur WHERE devis_id=:id');$lines->execute(['id'=>$devisId]);if((int)$lines->fetchColumn()===0)$issues[]='Aucune ligne dans le devis.';
        $debit=$this->pdo->prepare('SELECT COUNT(*) n,COALESCE(SUM(d.montant_debourse),0) total,SUM(d.date_debut IS NULL OR d.date_fin IS NULL OR d.date_fin<d.date_debut) bad_dates FROM debourse_banamur d JOIN ligne_devis_banamur ld ON ld.id=d.ligne_devis_id AND ld.devis_id=d.devis_id WHERE d.devis_id=:id');$debit->execute(['id'=>$devisId]);$d=$debit->fetch(PDO::FETCH_ASSOC)?:[];
        if((int)($d['n']??0)===0||(float)($d['total']??0)<=0)$issues[]='Déboursé absent ou montant total nul.';
        if($total>0&&(float)($d['total']??0)>$total*.65+.01)$issues[]='Déboursé supérieur à la limite de 65 % du devis.';
        if((int)($d['bad_dates']??0)>0)$issues[]='Dates manquantes ou inversées sur un ou plusieurs déboursés.';
        $billing=$this->pdo->prepare('SELECT COUNT(*) n,COALESCE(SUM(percentage),0) pct,SUM(expected_date IS NULL) bad_dates FROM devis_billing_schedule WHERE devis_id=:id');$billing->execute(['id'=>$devisId]);$b=$billing->fetch(PDO::FETCH_ASSOC)?:[];
        if((int)($b['n']??0)===0)$issues[]='Échéancier de facturation et d’encaissement absent.';
        elseif(abs((float)($b['pct']??0)-100)>.01)$issues[]='Échéancier d’encaissement différent de 100 %.';
        if((int)($b['bad_dates']??0)>0)$issues[]='Date manquante dans l’échéancier d’encaissement.';
        return array_values(array_unique($issues));
    }

    public function summary(): array
    {
        $row = $this->pdo->query("SELECT COUNT(*) total,SUM(financial_data_status='compliant') compliant,SUM(financial_data_status='incomplete') incomplete,SUM(financial_data_status='unchecked') unchecked FROM chantier_banamur WHERE archived_at IS NULL")->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0); $compliant = (int) ($row['compliant'] ?? 0);
        return ['total' => $total, 'compliant' => $compliant, 'incomplete' => (int) ($row['incomplete'] ?? 0), 'unchecked' => (int) ($row['unchecked'] ?? 0), 'reliability_rate' => $total > 0 ? round($compliant / $total * 100, 1) : 100.0];
    }
}
