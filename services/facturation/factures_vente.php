<?php
// Routes historiques : conserver les paramètres et le corps POST sur la route commune.
$script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??''));
$position=strrpos($script,'/services/facturation/');
$base=$position===false?'':substr($script,0,$position);
$query=str_replace(["\r","\n"],'',(string)($_SERVER['QUERY_STRING']??''));
header('Location: '.$base.'/facturation/factures_vente.php'.($query!==''?'?'.$query:''),true,($_SERVER['REQUEST_METHOD']??'GET')==='POST'?307:302);
exit;
