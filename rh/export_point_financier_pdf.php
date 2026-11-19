<?php
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__.'/../services/RhFinancialPointService.php';
$pdo=Database::getConnection();$service=new RhFinancialPointService($pdo);
try {
    $p=$service->get((int)($_GET['id'] ?? 0));
    $revision=(int)($_GET['revision'] ?? $p['revision']);
    if($revision!==(int)$p['revision']) {
        $q=$pdo->prepare('SELECT * FROM rh_financial_point_versions WHERE point_id=? AND revision=?');$q->execute([$p['id'],$revision]);$version=$q->fetch(PDO::FETCH_ASSOC);
        if(!$version)throw new DomainException('Version introuvable.');
        $p=array_replace($p,$version);$p['snapshot']=json_decode($version['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
    }
    $signed=!empty($p['dg_signed_at']);
    if($signed && !hash_equals((string)$p['snapshot_hash'],hash('sha256',$p['snapshot_json'])))throw new DomainException('Le récapitulatif ne correspond plus à son empreinte signée.');
    $original=($_GET['original'] ?? '')==='1';
    if($original && (!$signed || empty($p['pdf_path'])))throw new DomainException('Aucun original signé archivé pour cette version.');
    if($signed && $original && !empty($p['pdf_path'])){$file=realpath(__DIR__.'/../'.$p['pdf_path']);$base=realpath(__DIR__.'/../uploads/rh_financial_points');if(!$file||!$base||!str_starts_with($file,$base.DIRECTORY_SEPARATOR))throw new DomainException('Justificatif introuvable.');$bytes=file_get_contents($file);}
    else {$p['presentation_copy']=$signed;if($signed)$p['supplemental_signatures']=$service->supplementalSignatures((int)$p['id'],$revision);$bytes=(new RhFinancialPointPdfBuilder())->bytes($p,$p['snapshot'],$signed);}
    $disposition=($_GET['download'] ?? '')==='1'?'attachment':'inline';
    header('Content-Type: application/pdf');header('Cache-Control: private, no-store');header('Content-Disposition: '.$disposition.'; filename="point-rh-'.(int)$p['id'].'-v'.$revision.($original?'-original':'').'.pdf"');echo $bytes;
}catch(Throwable $e){http_response_code(403);echo htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');}


