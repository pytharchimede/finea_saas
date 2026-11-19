<?php
declare(strict_types=1);
require_once __DIR__.'/../services/SupplierLocationSearch.php';
function geoCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['CI','FR','DE','US'] as $country){
    parse_str((string)parse_url(SupplierLocationSearch::url('7 place de la Gare Sarreguemines',5,$country),PHP_URL_QUERY),$params);
    geoCheck($params['countrycode']===$country,'Country filter missing');
    geoCheck(!str_contains($params['q'],'Abidjan'),'Foreign address altered');
}
parse_str((string)parse_url(SupplierLocationSearch::url('Berlin',5,'all'),PHP_URL_QUERY),$params);
geoCheck(!isset($params['countrycode']),'World search restricted');
try{SupplierLocationSearch::country('ZZ');throw new LogicException('Invalid country accepted');}catch(DomainException $expected){}
$feature=['geometry'=>['type'=>'Point','coordinates'=>[7.0701,49.1081]],'properties'=>['name'=>'IONOS','street'=>'place de la Gare','housenumber'=>'7','postcode'=>'57200','city'=>'Sarreguemines','country'=>'France','countrycode'=>'FR']];
$item=SupplierLocationSearch::normalize($feature);
geoCheck($item['lat']===49.1081&&$item['lon']===7.0701,'Longitude/latitude reversed');
geoCheck($item['address']['country_code']==='fr'&&str_contains($item['display_name'],'Sarreguemines'),'Label or country missing');
geoCheck(SupplierLocationSearch::normalize(array_replace($feature,['geometry'=>['type'=>'Point','coordinates'=>[999,999]]]))===null,'Invalid coordinates accepted');
$transport=static fn()=>['features'=>[$feature]];
geoCheck(count(SupplierLocationSearch::search('IONOS',5,'FR',$transport))===1,'French result lost');
geoCheck(SupplierLocationSearch::search('IONOS',5,'DE',$transport)===[],'Result from wrong country accepted');
geoCheck(count(SupplierLocationSearch::search('IONOS',5,'all',$transport))===1,'Worldwide result lost');
echo "SupplierLocationSearchTest: OK (CI, FR, DE, US, worldwide, labels, coordinates and country filtering)\n";
