<?php
declare(strict_types=1);
require_once __DIR__.'/../model/FebValidationPolicy.php';

/** Read an exact signed version using FEB service permissions, never a public upload link. */
final class RhFinancialFebDocumentService
{
    public function __construct(private PDO $pdo) {}

    public function file(int $id,int $revision,int $actor,bool $original=false): string
    {
        $q=$this->pdo->prepare('SELECT id,active,acces_admin,acces_rh,acces_feb FROM user_devis_banamur WHERE id=?');
        $q->execute([$actor]);$user=$q->fetch(PDO::FETCH_ASSOC);
        if ($actor<=0 || !$user || empty($user['active'])) throw new DomainException('Compte actif requis.');
        $q=$this->pdo->prepare('SELECT p.dg_user_id,v.* FROM rh_financial_points p JOIN rh_financial_point_versions v ON v.point_id=p.id WHERE p.id=? AND v.revision=?');
        $q->execute([$id,$revision]);$version=$q->fetch(PDO::FETCH_ASSOC);
        if (!$version || empty($version['dg_signed_at']) || empty($version['rh_signed_at']) || empty($version['feb_request_id'])) throw new DomainException('Point signé introuvable.');
        $q=$this->pdo->prepare("SELECT id,requester_user_id,payload_json,meta_service_id FROM employee_legal_requests WHERE id=? AND request_type='feb'");
        $q->execute([$version['feb_request_id']]);$feb=$q->fetch(PDO::FETCH_ASSOC);
        if (!$feb) throw new DomainException('FEB introuvable.');
        $payload=json_decode($feb['payload_json'],true,512,JSON_THROW_ON_ERROR);$feb['payload']=$payload;
        $canFinance=!empty($user['acces_feb']) && FebValidationPolicy::canValidateService(FebValidationPolicy::getUserServicePermissions($this->pdo,$actor),FebValidationPolicy::requestServiceId($feb));
        if (empty($user['acces_admin']) && empty($user['acces_rh']) && $actor!==(int)$version['dg_user_id'] && $actor!==(int)$feb['requester_user_id'] && !$canFinance) throw new DomainException('Accès refusé.');
        if ((int)($payload['rh_financial_point_id'] ?? 0)!==$id || (int)($payload['rh_financial_revision'] ?? 0)!==$revision
            || !hash_equals((string)$version['snapshot_hash'],hash('sha256',$version['snapshot_json']))
            || !hash_equals((string)$version['snapshot_hash'],(string)($payload['rh_financial_snapshot_hash'] ?? ''))) throw new DomainException('Justificatif non conforme au point signé.');
        $document=$payload['justificatifs'][0] ?? [];
        if (($document['original_path'] ?? $document['path'] ?? '')!==$version['pdf_path']) throw new DomainException('Rattachement du justificatif incohérent.');
        $path=$original?$version['pdf_path']:($document['path'] ?? $version['pdf_path']);
        $file=realpath(__DIR__.'/../'.$path);$base=realpath(__DIR__.'/../uploads/rh_financial_points');
        if (!$file || !$base || !str_starts_with($file,$base.DIRECTORY_SEPARATOR) || !is_file($file) || !is_readable($file)) throw new DomainException('Justificatif archivé introuvable.');
        return $file;
    }
}
