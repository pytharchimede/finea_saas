<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../services/ContractWhatsAppRunner.php';
// Entrée autonome déployée automatiquement ; ne dépend pas du dossier scripts protégé.
$options=getopt('',['status','dry-run','enable','disable','actor:']);
exit(ContractWhatsAppRunner::execute($options?:['run'=>false]));
