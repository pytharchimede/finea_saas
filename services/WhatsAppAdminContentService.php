<?php
declare(strict_types=1);
require_once __DIR__.'/WhatsAppTemplateTestService.php';
class WhatsAppAdminContentService extends WhatsAppTemplateTestService
{
    public function __construct(private string $accountSid,private string $authToken){parent::__construct($accountSid,$authToken);}
    public function messageStatus(string $sid):array
    {
        if(!preg_match('/^(SM|MM)[a-fA-F0-9]{32}$/D',$sid))throw new DomainException('SID message invalide.');
        return $this->messageRequest($sid);
    }
    protected function messageRequest(string $messageSid):array
    {
        if(!preg_match('/^AC[a-fA-F0-9]{32}$/D',$this->accountSid)||$this->authToken==='')throw new DomainException('Configuration Twilio incomplète.');
        if(!function_exists('curl_init'))throw new RuntimeException('Extension cURL requise.');
        $curl=curl_init('https://api.twilio.com/2010-04-01/Accounts/'.$this->accountSid.'/Messages/'.$messageSid.'.json');
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_USERPWD=>$this->accountSid.':'.$this->authToken,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30]);
        $raw=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$r=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($r)||$http<200||$http>=300)throw new RuntimeException('Lecture du statut Twilio impossible (HTTP '.$http.').');
        return ['status'=>(string)($r['status']??'unknown'),'error_code'=>(string)($r['error_code']??''),'error'=>self::safeDiagnostic(str_replace($this->authToken,'[masqué]',(string)($r['error_message']??'')))];
    }
    public function content(string $sid):array
    {
        if(!preg_match('/^HX[a-fA-F0-9]{32}$/D',$sid))throw new DomainException('Content SID invalide.');
        return $this->request('GET','/Content/'.$sid);
    }
    public function inventory(string $token=''):array
    {
        if(strlen($token)>2048)throw new DomainException('Pagination invalide.');
        $result=$this->request('GET','/Content?PageSize=50'.($token!==''?'&PageToken='.rawurlencode($token):''));
        $next='';$url=$result['meta']['next_page_url']??'';
        if($url){$parts=parse_url($url);if(($parts['host']??'')!=='content.twilio.com'||($parts['path']??'')!=='/v1/Content')throw new RuntimeException('Pagination Twilio inattendue.');parse_str($parts['query']??'',$query);$next=(string)($query['PageToken']??'');}
        return ['contents'=>$result['contents']??[],'next'=>$next];
    }
    public function createContent(array $definition):array{return $this->request('POST','/Content',$definition);}
    public function submitContent(string $sid,string $name,string $category):array
    {
        if(!preg_match('/^HX[a-fA-F0-9]{32}$/D',$sid))throw new DomainException('Content SID invalide.');
        return $this->request('POST','/Content/'.$sid.'/ApprovalRequests/whatsapp',['name'=>$name,'category'=>$category]);
    }
    public static function definitionFromInput(array $input):array
    {
        $name=trim((string)($input['name']??''));$language=(string)($input['language']??'fr');$category=(string)($input['category']??'UTILITY');$body=trim((string)($input['body']??''));
        if(!preg_match('/^[a-z][a-z0-9_]{0,99}$/D',$name))throw new DomainException('Nom : 1 à 100 caractères, minuscules, chiffres et underscores.');
        if(!in_array($language,['fr','en','pt','es'],true)||!in_array($category,['UTILITY','MARKETING'],true))throw new DomainException('Langue ou catégorie invalide.');
        if($body===''||mb_strlen($body)>1024)throw new DomainException('Le message doit contenir de 1 à 1 024 caractères.');
        $type=['body'=>$body];$title=trim((string)($input['button_title']??''));$url=trim((string)($input['button_url']??''));
        if($title!==''||$url!==''){
            if($title===''||mb_strlen($title)>20)throw new DomainException('Le bouton doit avoir un libellé de 1 à 20 caractères.');
            $safe=preg_replace('/\{\{\d+\}\}/','exemple',$url);$parts=parse_url($safe);
            if(!filter_var($safe,FILTER_VALIDATE_URL)||($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass']))throw new DomainException('Le bouton doit utiliser une URL HTTPS valide.');
            preg_match_all('/\{\{\d+\}\}/',$url,$urlVars);
            if(count($urlVars[0])>1||($urlVars[0]&&!str_ends_with($url,end($urlVars[0]))))throw new DomainException('L’URL peut contenir une seule variable, à la fin.');
            $type['actions']=[['type'=>'URL','title'=>$title,'url'=>$url]];
        }
        $scan=$body.' '.$url;preg_match_all('/\{\{([1-9]\d*)\}\}/',$scan,$matches);$keys=array_values(array_unique($matches[1]));sort($keys,SORT_NUMERIC);
        $stripped=preg_replace('/\{\{[1-9]\d*\}\}/','',$scan);
        if(str_contains($stripped,'{{')||str_contains($stripped,'}}')||count($keys)>100||($keys&&$keys!==array_map('strval',range(1,count($keys)))))throw new DomainException('Utilisez des variables consécutives : {{1}}, {{2}}, etc.');
        if(preg_match('/^\s*\{\{|\}\}\s*$|\}\}\s*\{\{/',$body))throw new DomainException('Ajoutez du texte avant, après et entre les variables du message.');
        $samples=[];$inputSamples=$input['samples']??[];if(!is_array($inputSamples))throw new DomainException('Exemples de variables invalides.');
        foreach($keys as $key){$value=$inputSamples[$key]??'';if(!is_string($value)||trim($value)===''||mb_strlen($value)>500||preg_match('/[\r\n\t]/',$value))throw new DomainException('Renseignez un exemple sur une ligne pour la variable '.$key.'.');$samples[$key]=trim($value);}
        $name.='_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
        return ['category'=>$category,'definition'=>['friendly_name'=>$name,'language'=>$language,'variables'=>(object)$samples,'types'=>[isset($type['actions'])?'twilio/call-to-action':'twilio/text'=>$type]]];
    }
}
