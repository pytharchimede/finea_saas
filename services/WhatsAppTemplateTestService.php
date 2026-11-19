<?php
declare(strict_types=1);
require_once __DIR__.'/DailyPointWhatsAppTemplateService.php';
class WhatsAppTemplateTestService extends DailyPointWhatsAppTemplateService
{
    public function inspect(string $sid):array
    {
        if(!preg_match('/^HX[a-fA-F0-9]{32}$/D',$sid))throw new DomainException('Modèle invalide.');
        $content=$this->request('GET','/Content/'.$sid);
        $type=$content['types']['twilio/call-to-action']??$content['types']['twilio/text']??null;
        if(!is_array($type))throw new DomainException('Ce type de modèle ne peut pas être testé depuis cet écran.');
        preg_match_all('/\{\{(\d+)\}\}/',json_encode($type,JSON_THROW_ON_ERROR),$matches);
        $keys=array_values(array_unique($matches[1]));sort($keys,SORT_NUMERIC);
        $approval=$this->fetchApproval($sid);
        return ['sid'=>$sid,'name'=>$content['friendly_name']??$sid,'body'=>$type['body']??'','actions'=>$type['actions']??[],'keys'=>$keys,'samples'=>$content['variables']??[],'status'=>$approval['status']];
    }
    public static function phone(string $value):string
    {
        $value=preg_replace('/[\s().-]+/','',trim($value));
        if(str_starts_with($value,'00'))$value='+'.substr($value,2);
        if(!preg_match('/^\+[1-9]\d{7,14}$/D',$value))throw new DomainException('Renseignez le numéro international, par exemple +2250700000000.');
        return $value;
    }
    public static function variables(array $model,array $input):array
    {
        $values=[];
        foreach($model['keys'] as $key){
            // All FINEA signature models reserve variable 7 for a personal token.
            if((string)$key==='7'){$values[(string)$key]=str_repeat('a',64);continue;}
            $raw=$input[$key]??'';
            if(!is_string($raw)||trim($raw)===''||mb_strlen($raw)>500||preg_match('/[\r\n\t]/',$raw))throw new DomainException('La variable '.$key.' doit contenir un texte de 1 à 500 caractères sur une ligne.');
            $values[(string)$key]=trim($raw);
        }
        return $values;
    }
}
