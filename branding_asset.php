<?php
declare(strict_types=1);
require_once __DIR__.'/model/Database.php';require_once __DIR__.'/model/CompanyBranding.php';$file=($_GET['type']??'logo')==='icon'?CompanyBranding::iconFile(Database::getConnection()):CompanyBranding::logoFile(Database::getConnection());if(!$file||!is_file($file)){http_response_code(404);exit;}header('Content-Type: '.(mime_content_type($file)?:'image/png'));header('Cache-Control: public, max-age=300');header('Content-Length: '.filesize($file));readfile($file);
