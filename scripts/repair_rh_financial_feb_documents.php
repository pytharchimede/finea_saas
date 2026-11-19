<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/RhFinancialPointService.php';
$options=getopt('',['actor:','admin-email:','list-admins','point:','apply']);$actor=(int)($options['actor'] ?? 0);$apply=array_key_exists('apply',$options);
try {
    $pdo=Database::getConnection();
    if (array_key_exists('list-admins',$options)) {
        foreach($pdo->query('SELECT id,prenom,nom,mail_pro FROM user_devis_banamur WHERE active=1 AND acces_admin=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $admin) echo json_encode($admin,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
        exit(0);
    }
    if ($actor<=0 && !empty($options['admin-email'])) {
        $q=$pdo->prepare('SELECT id FROM user_devis_banamur WHERE active=1 AND acces_admin=1 AND mail_pro=?');$q->execute([trim((string)$options['admin-email'])]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids)!==1) throw new DomainException('Compte administrateur introuvable ou ambigu. Utilisez --list-admins puis --actor=ID.');
        $actor=(int)$ids[0];
    }
    if ($actor<=0) throw new DomainException('Indiquez --actor=ID ou --admin-email=EMAIL ; --list-admins liste les comptes admissibles.');
    $service=new RhFinancialPointService($pdo);$service->actor($actor,'admin');
    $point=(int)($options['point'] ?? 0);
    $ids=$point>0?[$point]:$pdo->query("SELECT id FROM rh_financial_points WHERE status='dg_signed' AND feb_request_id IS NOT NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    echo $apply?"Rattrapage documentaire : application\n":"Rattrapage documentaire : simulation (aucune écriture)\n";$errors=0;
    foreach($ids as $id) {
        try {echo json_encode($service->repairFebDocuments((int)$id,$actor,$apply),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;}
        catch(Throwable $e){$errors++;fwrite(STDERR,'Point '.$id.' : '.$e->getMessage().PHP_EOL);}
    }
    exit($errors?1:0);
} catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
