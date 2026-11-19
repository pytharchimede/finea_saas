<?php
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/model/ContratSousTraitance.php';
$pdo=Database::getConnection(); $contractManager=new ContratSousTraitance($pdo);
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');

// Répertoire partagé par Apache/PHP pour les factures FNE et PV contractuels.
// La vérification est répétée à chaque ouverture du module afin de réparer un
// dossier supprimé ou recréé avec des droits insuffisants après déploiement.
$contractUploadDirectory=dirname(__DIR__).'/uploads/contracts';
if(!is_dir($contractUploadDirectory)){
    @mkdir($contractUploadDirectory,0777,true);
}
if(is_dir($contractUploadDirectory)){
    @chmod($contractUploadDirectory,0777);
}
$contractEvidenceDirectory=$contractUploadDirectory.'/reception_evidence';
if(!is_dir($contractEvidenceDirectory)){
    @mkdir($contractEvidenceDirectory,0777,true);
}
if(is_dir($contractEvidenceDirectory)){
    @chmod($contractEvidenceDirectory,0777);
}
