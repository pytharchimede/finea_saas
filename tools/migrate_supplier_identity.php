<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {http_response_code(403);exit;}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../repositories/FournisseurRepository.php';
$pdo=Database::getConnection();
$repo=new FournisseurRepository($pdo);
if(!$repo->isReady())throw new RuntimeException($repo->getInitError() ?? 'Migration impossible.');
// Historical identities stay unverified until an operator normalizes them with FNE.
$rows=$pdo->query("SELECT id_fournisseur FROM fournisseur_banamur WHERE code_fournisseur IS NULL OR TRIM(code_fournisseur)='' ORDER BY id_fournisseur")->fetchAll(PDO::FETCH_COLUMN);
$update=$pdo->prepare("UPDATE fournisseur_banamur SET code_fournisseur=? WHERE id_fournisseur=? AND (code_fournisseur IS NULL OR TRIM(code_fournisseur)='')");
$pdo->beginTransaction();
try {
    foreach($rows as $id)$update->execute(['FOU-HIST-'.str_pad((string)$id,8,'0',STR_PAD_LEFT),$id]);
    $pdo->commit();
} catch(Throwable $e){$pdo->rollBack();throw $e;}
echo count($rows)." références historiques attribuées.\n";
$duplicates=$pdo->query("SELECT UPPER(REPLACE(REPLACE(TRIM(ncc),' ',''),'-','')) AS ncc, COUNT(*) AS total FROM fournisseur_banamur WHERE TRIM(COALESCE(ncc,''))<>'' GROUP BY UPPER(REPLACE(REPLACE(TRIM(ncc),' ',''),'-','')) HAVING COUNT(*)>1")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['duplicate_ncc'=>$duplicates],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
