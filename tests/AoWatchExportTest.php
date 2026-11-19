<?php
declare(strict_types=1);

function assertAoExport(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$source=file_get_contents(__DIR__.'/../devis/export_veille_ao.php');
assertAoExport(is_string($source),'L’export de veille AO doit être lisible.');
assertAoExport(str_contains($source,'new Spreadsheet()'),'L’export Excel doit produire un véritable classeur XLSX.');
assertAoExport(str_contains($source,'CompanyPdfTemplate'),'Le PDF doit employer le gabarit institutionnel BANAMUR.');
assertAoExport(str_contains($source,'DOCUMENT MAÎTRISÉ · FINEA · ISO 9001'),'Le classeur doit porter la mention qualité ISO 9001.');
assertAoExport(str_contains($source,'companyPdfQrPayload'),'Le PDF doit intégrer un QR code de traçabilité.');
assertAoExport(str_contains($source,"setName('Logo BANAMUR')"),'Le classeur doit intégrer le logo BANAMUR.');
assertAoExport(str_contains($source,"setRowsToRepeatAtTopByStartAndEnd(8,8)"),'Les titres de colonnes Excel doivent être répétés à l’impression.');
assertAoExport(str_contains($source,'getStringHeight'),'La hauteur des lignes PDF doit être calculée selon leur contenu.');
assertAoExport(!str_contains($source,"Content-Type: text/csv"),'Le faux export Excel CSV ne doit plus être utilisé.');
echo "AoWatchExportTest: OK\n";
