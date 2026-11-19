<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/FebDocumentComplianceService.php';

final class StubFidestIaClient extends FidestIaClient
{
    public function __construct(private array $analysis) {}
    public function analyze(string $absolutePath, string $clientReference, string $documentType = 'AUTO'): array { return $this->analysis; }
}

$accepted = new FebDocumentComplianceService(new StubFidestIaClient(['request_id'=>'req-ok','data'=>[
    'uuid'=>'doc-1','document_type'=>['code'=>'FACTURE'],'validation'=>['valid'=>true],'scores'=>['overall'=>.96],
    'raw_text'=>'Extrait partiel', 'ocr'=>['text'=>"FACTURE FAC-42\nACME SARL\nOrdinateur portable 125 000 FCFA", 'confidence'=>.91],
    'data'=>['supplier_name'=>['value'=>'ACME SARL'],'total_ttc'=>['value'=>'125 000 FCFA'],'document_date'=>'2026-09-01','document_number'=>'FAC-42','line_items'=>[['description'=>'Ordinateur portable','quantity'=>1,'unit_price'=>125000,'line_total'=>125000,'type'=>'Bien']]],
]]));
$result=$accepted->check(__FILE__,'FINEA-FEB-1','ACME',125000,[['libelle'=>'Ordinateur portable','type'=>'Bien','quantite'=>1,'prix_unitaire'=>125000]]);
if(!$result['accepted']||$result['document_uuid']!=='doc-1'||$result['extracted']['document_number']!=='FAC-42'||empty($result['line_checks'][0]['matched']))throw new RuntimeException('Le document conforme a été rejeté.');
if(!str_contains($result['ocr_text'], 'FACTURE FAC-42'))throw new RuntimeException('Le texte OCR du document a été perdu.');
if($result['ocr_confidence'] !== .91)throw new RuntimeException('La confiance OCR du document a été perdue.');

$rejected = new FebDocumentComplianceService(new StubFidestIaClient(['request_id'=>'req-ko','data'=>[
    'uuid'=>'doc-2','validation'=>['valid'=>true],'data'=>['supplier_name'=>'AUTRE FOURNISSEUR','total_amount'=>99000],
]]));
$result=$rejected->check(__FILE__,'FINEA-FEB-2','ACME',125000);
if($result['accepted']||count($result['reasons'])!==2)throw new RuntimeException('Les divergences fournisseur/montant ne sont pas bloquées.');

$invoiceOcr = "E-mail: assistance@tpecloud.com\nFacture #21274\nRenouvellement de domaine - hillemballage.ci 9.900,00 F CFA\nTotal 9.900,00 F CFA";
$flexible = new FebDocumentComplianceService(new StubFidestIaClient(['request_id'=>'req-ocr','data'=>[
    'uuid'=>'doc-21274','validation'=>['valid'=>false],'ocr'=>['text'=>$invoiceOcr],
    'data'=>['supplier_name'=>'JUST SARL','total_amount'=>99000],
]]));
$result = $flexible->check(__FILE__, 'FINEA-FEB-21274', 'TPEcloud', 9900, [['libelle'=>'Renouvellement de domaine']]);
if (!$result['accepted'] || !$result['extracted']['matched_supplier_in_ocr'] || $result['extracted']['matched_total_amount'] !== 9900.0 || empty($result['line_checks'][0]['matched'])) {
    throw new RuntimeException('La facture TPEcloud présente dans le texte OCR a été rejetée : ' . json_encode($result, JSON_UNESCAPED_UNICODE));
}

$wrongAmount = new FebDocumentComplianceService(new StubFidestIaClient(['request_id'=>'req-wrong','data'=>[
    'uuid'=>'doc-wrong','validation'=>['valid'=>false],'ocr'=>['text'=>"assistance@tpecloud.com\nTotal 19.900,00 F CFA"],
    'data'=>['supplier_name'=>'TPEcloud','total_amount'=>19900],
]]));
if ($wrongAmount->check(__FILE__, 'FINEA-FEB-WRONG', 'TPEcloud', 9900)['accepted']) throw new RuntimeException('Un montant différent a été accepté.');

$screenshot = new FebDocumentComplianceService(new StubFidestIaClient(['request_id'=>'req-screenshot','data'=>[
    'uuid'=>'doc-screenshot','validation'=>['valid'=>false],
    'ocr'=>['text'=>"@D HOT TPEcloud\nE-mail: assistance@tpecloud.com\nFacture #21274\nDate de facturation: 16/09/2026\nRenouvellement de domaine 9.900,00 F CFA\nTotal 9.900,00 F CFA"],
    'data'=>[],
]]));
$result = $screenshot->check(__FILE__, 'FINEA-FEB-SCREENSHOT', 'Server VPS TPECLOUD', 9900, [['libelle'=>'Server VPS TPECLOUD']]);
if ($result['extracted']['supplier_detected'] !== 'TPECLOUD' || $result['extracted']['matched_total_in_ocr'] !== 9900.0 || $result['extracted']['document_number'] !== '21274' || $result['extracted']['document_date'] !== '16/09/2026' || $result['accepted']) {
    throw new RuntimeException('Le fournisseur et le montant OCR doivent être trouvés, mais la ligne différente doit rester signalée.');
}
$result = $screenshot->check(__FILE__, 'FINEA-FEB-SPACES', 'TPE CLOUD', 9900);
if (!$result['accepted'] || $result['extracted']['supplier_detected'] !== 'TPE CLOUD') throw new RuntimeException('Les espaces du nom fournisseur ne sont pas tolérés.');
$ocrTypo = new FebDocumentComplianceService(new StubFidestIaClient(['request_id'=>'req-typo','data'=>[
    'ocr'=>['text'=>"Site web: www.tpecioud.ci\nTotal 9.900,00 F CFA"], 'data'=>[],
]]));
if (!$ocrTypo->check(__FILE__, 'FINEA-FEB-TYPO', 'Server VPS TPECLOUD', 9900)['accepted']) throw new RuntimeException('Une légère erreur OCR dans le nom du fournisseur est rejetée.');
$result = $screenshot->check(__FILE__, 'FINEA-FEB-AMOUNT-FALLBACK', 'Server VPS TPECLOUD', 0, [['libelle'=>'Renouvellement de domaine','quantite'=>1,'prix_unitaire'=>9900]]);
if (!$result['accepted'] || $result['extracted']['expected_amount'] !== 9900.0) throw new RuntimeException('Le total des lignes du devis n’est pas utilisé lorsque le total de la FEB manque.');
echo "FebDocumentComplianceServiceTest: OK\n";
