<?php
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__).'/model/EnvConfig.php';
EnvConfig::loadFromDirectory(dirname(__DIR__));
require_once dirname(__DIR__).'/services/AoSharedCatalog.php';
try{$stats=AoSharedCatalog::publish();echo json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;exit($stats['errors']?2:0);}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
