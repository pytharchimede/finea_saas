<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
require_once dirname(__DIR__, 2).'/model/Database.php';
require_once dirname(__DIR__, 2).'/model/CompanyBranding.php';
$pdo=Database::getConnection();
$db=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if ($db !== 'finea_saas_dev' || ($argv[1] ?? '') !== '--confirm=finea_saas_dev') {
    fwrite(STDERR,"Commande réservée à la démonstration locale.\n"); exit(1);
}
$logo='logo/ibtp-demo.png';
if (!is_file(dirname(__DIR__,2).'/'.$logo)) {
    fwrite(STDERR,"Installer le logo IBTP dans logo/ibtp-demo.png avant de continuer.\n"); exit(1);
}
CompanyBranding::initializeStorage($pdo);
$pdo->prepare("UPDATE finea_company_branding SET company_name=?,legal_name=NULL,capital=NULL,address=NULL,phone=NULL,email=NULL,rccm=NULL,tax_number=NULL,logo_path=?,icon_path=?,primary_color=?,accent_color=?,surface_color=?,text_color=?,muted_color=? WHERE id=1")->execute(['IBTP',$logo,$logo,'#081c65','#d71920','#ffffff','#17243d','#64748b']);
echo "Branding IBTP de démonstration appliqué.\n";
