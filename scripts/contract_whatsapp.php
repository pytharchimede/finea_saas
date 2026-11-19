<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../services/ContractWhatsAppRunner.php';
exit(ContractWhatsAppRunner::execute(getopt('',['install','provision','enable','disable','status','run','dry-run','actor:','reconcile:'])));
