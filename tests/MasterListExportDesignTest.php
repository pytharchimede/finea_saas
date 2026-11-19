<?php
declare(strict_types=1);
function assertMasterExport(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$export=file_get_contents(__DIR__.'/../exports/finea_module_export.php');
assertMasterExport(str_contains($export,'CompanyPdfTemplate'),'Le PDF doit utiliser le gabarit institutionnel partagé.');
assertMasterExport(str_contains($export,'companyPdfQrPayload'),'Le PDF doit intégrer un QR de traçabilité.');
assertMasterExport(str_contains($export,"new Drawing()"),'Le classeur doit intégrer le logo et le QR sous forme d’images.');
assertMasterExport(str_contains($export,'PAPERSIZE_A3'),'Le classeur doit être calibré pour une impression A3 paysage.');
assertMasterExport(str_contains($export,'fineaExportColumnWidths'),'Les largeurs doivent être adaptées au type de registre.');
assertMasterExport(str_contains($export,"'clients'=>")&&str_contains($export,"'fournisseurs'=>")&&str_contains($export,"'chantiers','projets'=>"),'Clients, fournisseurs et projets doivent avoir leurs propres largeurs.');
assertMasterExport(str_contains($export,"'offres'=>")&&str_contains($export,"if(\$module==='offres')"),'Le registre des offres doit avoir ses données et ses largeurs dédiées.');
assertMasterExport(str_contains($export,'getStringHeight'),'La hauteur des lignes PDF doit suivre leur contenu.');
assertMasterExport(str_contains($export,'RÉCAPITULATIF'),'Chaque export doit comporter une synthèse.');
assertMasterExport(!str_contains($export,'<table border="1"'),'Le PDF ne doit plus dépendre du tableau HTML générique historique.');
echo "MasterListExportDesignTest: OK\n";
