<?php
require_once __DIR__.'/../auth_check.php';
if(!AccessManager::hasPermission('acces_admin')){http_response_code(403);exit('Accès réservé à l’administration système.');}
header('Location: ../decaissement/test_whatsapp_point.php',true,302);
exit;
