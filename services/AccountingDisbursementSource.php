<?php
declare(strict_types=1);

/** Read-only bridge to signed and paid daily disbursement points. */
final class AccountingDisbursementSource
{
    public function __construct(private PDO $pdo, private ?string $root = null)
    {
        $this->root ??= dirname(__DIR__);
    }

    private function rows(string $sql, array $params=[]): array
    {
        $stmt=$this->pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function available(): bool
    {
        $count=$this->rows("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('daily_disbursement_points','daily_disbursement_point_items','daily_disbursement_point_signatures','daily_disbursement_point_payments','daily_disbursement_point_payment_items','fiche_decaissement')")[0]['n'];
        return (int)$count===6;
    }

    public function points(int $page=1): array
    {
        $offset=(max(1,$page)-1)*30;
        return $this->rows("SELECT p.*,
            (SELECT COUNT(*) FROM daily_disbursement_point_signatures s WHERE s.point_id=p.id AND s.role_code='dg' AND COALESCE(s.signature_path,'')<>'' AND s.signed_at IS NOT NULL) AS dg_signatures,
            (SELECT COUNT(*) FROM accounting_entry_sources s JOIN accounting_entries e ON e.id=s.entry_id JOIN daily_disbursement_point_items i ON i.id=s.point_item_id WHERE i.point_id=p.id AND s.active_point_item_id IS NOT NULL AND e.status='posted') AS posted_count,
            (SELECT COUNT(*) FROM accounting_entry_sources s JOIN accounting_entries e ON e.id=s.entry_id JOIN daily_disbursement_point_items i ON i.id=s.point_item_id WHERE i.point_id=p.id AND s.active_point_item_id IS NOT NULL AND e.status='draft') AS draft_count
            FROM daily_disbursement_points p WHERE p.status='paid' ORDER BY p.point_date DESC,p.id DESC LIMIT 31 OFFSET {$offset}");
    }

    public function point(int $id): array
    {
        $point=$this->rows('SELECT * FROM daily_disbursement_points WHERE id=?',[$id])[0]??null;
        if (!$point) throw new DomainException('Point introuvable.');
        $point['signatures']=$this->rows('SELECT * FROM daily_disbursement_point_signatures WHERE point_id=? ORDER BY signed_at',[$id]);
        $point['items']=$this->rows("SELECT i.*,f.decaisse,f.date_decaisse,s.entry_id,e.status AS accounting_status,e.entry_number
            FROM daily_disbursement_point_items i
            LEFT JOIN fiche_decaissement f ON CONVERT(f.num_fiche USING utf8mb4) COLLATE utf8mb4_unicode_ci=CONVERT(i.fiche_number USING utf8mb4) COLLATE utf8mb4_unicode_ci
            LEFT JOIN accounting_entry_sources s ON s.active_point_item_id=i.id
            LEFT JOIN accounting_entries e ON e.id=s.entry_id WHERE i.point_id=? ORDER BY i.id",[$id]);
        return $point;
    }

    public function item(int $id): array
    {
        $item=$this->rows('SELECT * FROM daily_disbursement_point_items WHERE id=?',[$id])[0]??null;
        if (!$item) throw new DomainException('Ligne de décaissement introuvable.');
        $point=$this->point((int)$item['point_id']);
        $fiche=$this->rows('SELECT * FROM fiche_decaissement WHERE num_fiche=? LIMIT 1',[$item['fiche_number']])[0]??null;
        $payments=$this->rows('SELECT p.*,i.amount AS item_amount FROM daily_disbursement_point_payment_items i JOIN daily_disbursement_point_payments p ON p.id=i.payment_id WHERE i.point_item_id=? AND p.point_id=? ORDER BY p.id',[$id,$point['id']]);
        $signatures=array_values(array_filter($point['signatures'],static fn($s)=>$s['role_code']==='dg' && !empty($s['signed_at']) && !empty($s['signature_path'])));
        $errors=[];
        $pointAmount=0;
        foreach ($point['items'] as $pointItem) {
            $pointAmount+=AccountingService::amount((string)$pointItem['amount']);
            if ((int)($pointItem['decaisse']??0)!==1 || empty($pointItem['date_decaisse'])) $errors[]='Le point contient encore une fiche non payée.';
        }
        if (!$point['items'] || $pointAmount!==AccountingService::amount((string)$point['total_amount'])) $errors[]='Le total du point ne correspond pas à ses fiches.';
        if ($point['status']!=='paid' || empty($point['paid_at'])) $errors[]='Le point n’est pas effectivement payé.';
        if (empty($point['certified_at']) || !$signatures) $errors[]='La signature du Directeur Général est absente.';
        if (!$fiche || (int)($fiche['decaisse']??0)!==1 || empty($fiche['date_decaisse'])) $errors[]='Le paiement effectif de la fiche n’est pas confirmé.';
        if (($fiche['document_completion_status']??'')==='returned') $errors[]='Les pièces de la fiche ont été retournées pour complément.';
        $amount=AccountingService::amount((string)$item['amount']);
        if ($amount<=0) $errors[]='Le montant décaissé doit être positif.';
        if ($payments) {
            $sum=0;
            foreach ($payments as $payment) {
                $sum+=AccountingService::amount((string)$payment['item_amount']);
                if (empty($payment['paid_at']) || empty($payment['signature_path'])) $errors[]='La signature ou la date de l’agent payeur manque.';
            }
            if ($sum!==$amount) $errors[]='Le montant des paiements diffère du montant de la ligne du point.';
        }
        $source=['point_item_id'=>$id,'point_id'=>(int)$point['id'],'point_date'=>$point['point_date'],'version_no'=>(int)$point['version_no'],
            'fiche_number'=>$item['fiche_number'],'amount'=>$amount,'beneficiary'=>$item['beneficiary']??'', 'designation'=>$item['designation']??'',
            'chantier_code'=>$item['chantier_code']??'','certified_at'=>$point['certified_at'],'paid_at'=>$point['paid_at'],
            'fiche_paid_at'=>$fiche['date_decaisse']??null,'bci_reference'=>$fiche['code_autorisation_feb']??'',
            'invoice_reference'=>$fiche['facture_reference']??'','reception_reference'=>$fiche['pv_reception_reference']??'',
            'signatures'=>$signatures,'payments'=>$payments,'errors'=>array_values(array_unique($errors))];
        $paths=[];
        foreach ($payments as $payment) {
            if (!empty($payment['proof_path'])) $paths[]=['category'=>'payment','path'=>$payment['proof_path']];
            if (!empty($payment['signature_path'])) $paths[]=['category'=>'payer_signature','path'=>$payment['signature_path']];
        }
        foreach ($signatures as $signature) $paths[]=['category'=>'dg_signature','path'=>$signature['signature_path']];
        if ($this->rows("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='disbursement_regularizations'")) {
            $regularization=$this->rows('SELECT payment_proof_path FROM disbursement_regularizations WHERE num_fiche=? AND cancelled_at IS NULL LIMIT 1',[$item['fiche_number']])[0]??[];
            if (!empty($regularization['payment_proof_path'])) $paths[]=['category'=>'payment','path'=>$regularization['payment_proof_path']];
        }
        foreach (['invoice_reference'=>'expense','reception_reference'=>'reception'] as $field=>$category) {
            foreach (preg_split('/[\r\n,;]+/',(string)$source[$field])?:[] as $path) if (trim($path)!=='') $paths[]=['category'=>$category,'path'=>$path];
        }
        $source['files']=[];
        foreach ($paths as $path) {
            try {
                $doc=$this->readFile($path['path']);
                unset($doc['content']);
                $source['files'][]=$doc+['category'=>$path['category'],'path'=>$path['path']];
            } catch (DomainException $e) {
                // A textual reference is displayed as such; it is not treated as an attached document.
            }
        }
        // Stable fingerprint: only the selected disbursement and its immutable supporting data.
        $source['fingerprint']=hash('sha256',json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $source['active_entry']=$this->rows('SELECT e.id,e.status,e.entry_number FROM accounting_entry_sources s JOIN accounting_entries e ON e.id=s.entry_id WHERE s.active_point_item_id=? OR s.active_fiche_number=? LIMIT 1',[$id,$item['fiche_number']])[0]??null;
        return $source;
    }

    public function assertEligible(array $source): void
    {
        if ($source['errors']) throw new DomainException(implode(' ',$source['errors']));
    }

    public function file(int $item, int $index): array
    {
        $source=$this->item($item); $file=$source['files'][$index]??null;
        if (!$file) throw new DomainException('Pièce source introuvable.');
        return $this->readFile($file['path']);
    }

    private function readFile(string $path): array
    {
        if (str_starts_with($path,'data:image/')) {
            if (!preg_match('#^data:image/(?:png|jpeg);base64,([A-Za-z0-9+/=\r\n]+)$#D',$path,$matches)) throw new DomainException('Signature invalide.');
            $bytes=base64_decode($matches[1],true);
            if ($bytes===false) throw new DomainException('Signature invalide.');
            return AccountingService::validateDocument('signature.png',$bytes);
        }
        $root=realpath((string)$this->root);
        $relative=ltrim((string)preg_replace('~^(?:\.\./)+~','',trim($path)),'/');
        $file=$root?realpath($root.'/'.$relative):false;
        $allowed=false;
        foreach (['uploads','signatures','decaissement/uploads'] as $directory) {
            $safeRoot=$root?realpath($root.'/'.$directory):false;
            if ($safeRoot && $file && str_starts_with($file,$safeRoot.'/')) $allowed=true;
        }
        if (!$allowed || !$file || !is_file($file) || !is_readable($file) || filesize($file)>8*1024*1024) throw new DomainException('Pièce non disponible.');
        return AccountingService::validateDocument(basename($file),(string)file_get_contents($file));
    }
}
