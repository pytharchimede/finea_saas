<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../services/ManualTreasuryService.php';

/** Refuse de modifier un instantané si sa source a changé ou si l'ancien bug ne l'explique pas. */
function fineaVatDiscountCorrection(array $row): ?array
{
    if ((float)($row['discount']??0)<=0 || !empty($row['tax_not_invoiced'])) return null;
    if ((string)$row['source_items_json'] !== (string)$row['items_json']) return null;
    if ((int)$row['snapshot_tax_not_invoiced'] !== (int)$row['tax_not_invoiced']) return null;
    if ((string)$row['snapshot_date'] !== substr((string)$row['paid_at'],0,10)) return null;
    $items=json_decode((string)$row['items_json'],true);
    $items=is_array($items)&&isset($items['items'])?$items['items']:$items;
    if (!is_array($items)||!$items) return null;
    // Les taux historiques doivent être figés dans les lignes, pas relus dans la configuration actuelle.
    foreach ($items as $item) if (!is_array($item)||!is_numeric($item['tax_rate']??null)) return null;
    $expected=ManualTreasuryService::vatSnapshotAmounts($row);
    $gross=ManualTreasuryService::vatSnapshotAmounts(array_replace($row,['discount'=>0]));
    foreach (['amount_ht'=>$expected['ht'],'amount_ttc'=>$expected['collected'],
        'snapshot_collected_amount'=>$expected['collected'],'amount_vat'=>$gross['vat']] as $key=>$value) {
        if (abs((float)$row[$key]-$value)>0.005) return null;
    }
    if ($expected['vat']>=(float)$row['amount_vat']-0.005) return null;
    return ['id'=>(int)$row['id'],'invoice_id'=>(int)$row['invoice_id'],
        'before_vat'=>(float)$row['amount_vat'],'after_vat'=>$expected['vat']];
}

if (realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__) {
    umask(0077);
    $pdo=null;
    try {
        $mode=$argv[1]??'--audit';
        if (!in_array($mode,['--audit','--apply'],true)) throw new RuntimeException('Usage : php scripts/repair_vat_snapshot_discounts.php --audit|--apply [racine_application]');
        $appRoot=realpath($argv[2]??dirname(__DIR__));
        if (!$appRoot||!is_file($appRoot.'/decaissement/model/Database.php')) throw new RuntimeException('Racine de configuration FINEA invalide.');
        require_once $appRoot.'/decaissement/model/Database.php';
        $pdo=Database::getConnection();
        $apply=$mode==='--apply';
        if ($apply) $pdo->beginTransaction();
        $sql="SELECT s.*,s.collected_amount snapshot_collected_amount,
            s.tax_not_invoiced snapshot_tax_not_invoiced,
            f.items_json,f.discount,f.total_ttc,f.collected_amount,f.tax_not_invoiced,f.paid_at
            FROM treasury_vat_invoice_snapshots s
            JOIN facture_vente_banamur f ON f.id_facture_vente=s.invoice_id
            WHERE f.discount>0 AND s.amount_vat>0 AND f.paiement_statut='payee'
            ORDER BY s.id".($apply?' FOR UPDATE':'');
        $rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $changes=[];$unresolved=[];
        foreach ($rows as $row) {
            $change=fineaVatDiscountCorrection($row);
            if ($change) $changes[]=$change;
            elseif (abs((float)$row['amount_vat']-ManualTreasuryService::vatSnapshotAmounts($row)['vat'])>0.005) $unresolved[]=(int)$row['invoice_id'];
        }
        echo json_encode(['mode'=>$mode,'corrections'=>$changes,'factures_a_verifier'=>$unresolved],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
        if ($apply && $changes) {
            // Sauvegarde préalable hors du site public ; aucune modification de schéma.
            $directory='/home/fidestci/finea-vat-repairs';
            if (is_link($directory)||(!is_dir($directory)&&!mkdir($directory,0700))) throw new RuntimeException('Dossier de sauvegarde indisponible.');
            chmod($directory,0700);
            $backup=$directory.'/'.gmdate('Ymd_His').'-'.bin2hex(random_bytes(4)).'.json';
            $json=json_encode(['created_at'=>gmdate('c'),'changes'=>$changes,'snapshots'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            if (file_put_contents($backup,$json,LOCK_EX)!==strlen($json)) throw new RuntimeException('Sauvegarde incomplète.');
            echo 'Sauvegarde : '.$backup.PHP_EOL;
            $update=$pdo->prepare('UPDATE treasury_vat_invoice_snapshots SET amount_vat=:after WHERE id=:id AND amount_vat=:before');
            foreach ($changes as $change) {
                $update->execute(['after'=>$change['after_vat'],'id'=>$change['id'],'before'=>$change['before_vat']]);
                if ($update->rowCount()!==1) throw new RuntimeException('Instantané modifié pendant la correction.');
            }
        }
        if ($apply) { $pdo->commit(); echo 'Corrections validées : '.count($changes).PHP_EOL; }
    } catch (Throwable $e) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);
    }
}
