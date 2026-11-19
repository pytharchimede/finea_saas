<?php
declare(strict_types=1);
require_once __DIR__.'/InternationalBusinessIdentity.php';

/** Photon supports search-as-you-type; every coordinate still needs user selection. */
final class SupplierLocationSearch
{
    public static function country(string $country): string
    {
        return $country==='all' || $country==='' ? '' : InternationalBusinessIdentity::country($country);
    }

    public static function url(string $query,int $limit,string $country):string
    {
        $country=self::country($country);
        return 'https://photon.komoot.io/api/?'.http_build_query(['q'=>trim($query),'limit'=>max(1,min(10,$limit)),'lang'=>'fr']+($country!==''?['countrycode'=>$country]:[]),'','&',PHP_QUERY_RFC3986);
    }

    public static function normalize(array $feature):?array
    {
        $p=$feature['properties']??[];$coordinates=$feature['geometry']['coordinates']??[];
        if(($feature['geometry']['type']??'')!=='Point'||!isset($coordinates[0],$coordinates[1])||!is_numeric($coordinates[0])||!is_numeric($coordinates[1]))return null;
        $lon=(float)$coordinates[0];$lat=(float)$coordinates[1];
        if(!is_finite($lat)||!is_finite($lon)||abs($lat)>90||abs($lon)>180)return null;
        $street=trim(($p['housenumber']??'').' '.($p['street']??''));
        $city=(string)($p['city']??$p['town']??$p['village']??$p['county']??'');
        $parts=array_values(array_unique(array_filter([(string)($p['name']??''),$street,trim(($p['postcode']??'').' '.$city),(string)($p['state']??''),(string)($p['country']??'')],static fn($v)=>$v!=='')));
        if(!$parts)return null;
        return ['display_name'=>implode(', ',$parts),'lat'=>$lat,'lon'=>$lon,'address'=>['city'=>$city,'country_code'=>strtolower((string)($p['countrycode']??'')),'country'=>(string)($p['country']??'')],'source'=>'remote'];
    }

    public static function search(string $query,int $limit,string $country,callable $transport):array
    {
        $country=self::country($country);$payload=$transport(self::url($query,$limit,$country),6);$items=[];
        foreach(($payload['features']??[]) as $feature){
            if(!is_array($feature))continue;$item=self::normalize($feature);
            if(!$item||($country!==''&&strtoupper($item['address']['country_code'])!==$country))continue;
            $items[]=$item;if(count($items)>=$limit)break;
        }
        return $items;
    }
}
