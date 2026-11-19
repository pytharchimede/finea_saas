<?php
declare(strict_types=1);
require_once __DIR__.'/SupplierPaymentGuard.php';
require_once __DIR__.'/FebWorkflowGuard.php';

final class SupplierApprovalGuard
{
    public static function error(PDO $pdo,array $fiche,?string $root=null):?string
    {
        try{SupplierPaymentGuard::assertFiche($pdo,$fiche,$root);return null;}
        catch(DomainException $e){return str_replace('Décaissement bloqué','Approbation bloquée',$e->getMessage());}
        catch(Throwable $e){error_log('Supplier approval identity check unavailable');return 'Approbation bloquée : contrôle fournisseur indisponible. Réessayez ou contactez la comptabilité.';}
    }
    public static function assertFiche(PDO $pdo,array $fiche,?string $root=null):void
    {
        if($error=self::error($pdo,$fiche,$root))throw new DomainException($error);
    }
    public static function payloadError(PDO $pdo,array $payload,array $post=[],?string $root=null):?string
    {
        // These requests use the separate signed RH circuit, which validates its snapshot.
        if(!empty($payload['rh_financial_point_id']))return null;
        $draft=(array)($payload['provisional_decaissement']??[]);
        $id=FebWorkflowGuard::supplierId($post,$draft,$payload);
        return self::error($pdo,['fournisseur_id'=>$id,'code_autorisation_feb'=>'FEB'],$root);
    }
    public static function assertPayload(PDO $pdo,array $payload,array $post=[],?string $root=null):void
    {
        if($error=self::payloadError($pdo,$payload,$post,$root))throw new DomainException($error);
    }
}
