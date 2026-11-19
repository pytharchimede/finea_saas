<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess();
$id=max(0,(int)($_GET['id']??$_GET['id_client']??0));
header('Location: '.($id>0?'../clients/modifier.php?id='.$id:'../clients/ajouter.php'));
exit;

