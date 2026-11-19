<?php
require_once __DIR__.'/../model/FebAiSettings.php';
class FebAiDetectionService
{
    public function __construct(private PDO $pdo, private ?FebAiSettings $settingsModel=null){$this->settingsModel??=new FebAiSettings();$this->ensureSchema();}
    public function detect(string $text, string $field='', ?int $febId=null, ?int $userId=null): array
    {
        $text=trim($text);$settings=$this->settingsModel->load();$mode=(string)$settings['detection_mode'];
        if($mode==='off')return ['enabled'=>false,'score'=>null,'human_score'=>null,'blocked'=>false,'mode'=>'off'];
        if(mb_strlen($text)<40)return ['enabled'=>true,'score'=>null,'human_score'=>null,'blocked'=>false,'mode'=>$mode,'message'=>'Texte trop court pour une estimation utile.'];
        $effectiveMode=$mode;$fallbackMessage='';
        if($mode==='eden'){
            try{$score=$this->eden($text,(string)$settings['detection_provider']);}
            catch(Throwable $e){
                if(str_contains($e->getMessage(),'HTTP 402')){$score=$this->local($text);$effectiveMode='local';$fallbackMessage='Crédit Eden AI insuffisant : estimation locale gratuite utilisée.';}
                else throw $e;
            }
        }else{$score=$this->local($text);}
        $percent=max(0,min(100,round($score*100,1)));$blocked=!empty($settings['block_on_detection'])&&$percent>=(int)$settings['detection_threshold'];
        try{$stmt=$this->pdo->prepare('INSERT INTO feb_ai_text_detections(feb_request_id,user_id,field_name,detection_mode,provider,text_hash,text_length,ai_score,human_score,is_blocked) VALUES(:feb,:user,:field,:mode,:provider,:hash,:length,:ai,:human,:blocked)');$stmt->execute(['feb'=>$febId,'user'=>$userId,'field'=>$field,'mode'=>$effectiveMode,'provider'=>$effectiveMode==='eden'?$settings['detection_provider']:'local','hash'=>hash('sha256',$text),'length'=>mb_strlen($text),'ai'=>$percent,'human'=>100-$percent,'blocked'=>$blocked?1:0]);}catch(Throwable $e){}
        return ['enabled'=>true,'score'=>$percent,'human_score'=>round(100-$percent,1),'blocked'=>$blocked,'mode'=>$effectiveMode,'provider'=>$effectiveMode==='eden'?$settings['detection_provider']:'local','threshold'=>(int)$settings['detection_threshold'],'message'=>$fallbackMessage!==''?$fallbackMessage:($effectiveMode==='local'?'Estimation locale gratuite et indicative.':'Analyse Eden AI.')];
    }
    private function eden(string $text,string $provider): float
    {
        $key=$this->settingsModel->edenApiKey();if($key==='')throw new RuntimeException('Clé Eden AI non configurée.');
        $primary='text/ai_detection/'.$provider;
        $fallback=$provider==='sapling'?'text/ai_detection/winstonai':'text/ai_detection/sapling';
        $ch=curl_init('https://api.edenai.run/v3/universal-ai');$body=json_encode(['model'=>$primary,'fallbacks'=>[$fallback],'input'=>['text'=>$text]],JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$body]);$raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
        if(!is_string($raw)||$code<200||$code>=300){$message='';if(is_string($raw)&&$raw!==''){$problem=json_decode($raw,true);if(is_array($problem)){$candidate=$problem['detail']??$problem['message']??$problem['error']??'';$message=is_string($candidate)?trim($candidate):'';}}$message=mb_substr(preg_replace('/sk-[A-Za-z0-9_-]+/','[clé masquée]',$message)?:$message,0,500);throw new RuntimeException('Eden AI HTTP '.($code?:'réseau').($message!==''?' : '.$message:($error!==''?' : '.$error:'.')));}
        $json=json_decode($raw,true);$score=$json['output']['ai_score']??$json['ai_score']??null;if(!is_numeric($score))throw new RuntimeException('Réponse Eden AI invalide.');return (float)$score;
    }
    private function local(string $text): float
    {
        $sentences=preg_split('/[.!?]+/u',$text,-1,PREG_SPLIT_NO_EMPTY)?:[];$words=preg_split('/\s+/u',mb_strtolower($text),-1,PREG_SPLIT_NO_EMPTY)?:[];if(count($words)<12)return .5;
        $unique=count(array_unique($words))/max(1,count($words));$avg=count($sentences)?array_sum(array_map(fn($s)=>count(preg_split('/\s+/u',trim($s))?:[]),$sentences))/count($sentences):15;
        $markers=preg_match_all('/\b(en outre|par conséquent|il convient de|dans le cadre de|ainsi|cependant|optimiser)\b/ui',$text);
        return max(.05,min(.95,.35+(1-$unique)*.35+min(.2,$markers*.04)+(abs($avg-18)<5?.08:0)));
    }
    private function ensureSchema():void{try{$this->pdo->exec("CREATE TABLE IF NOT EXISTS feb_ai_text_detections(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,feb_request_id INT NULL,user_id INT NULL,field_name VARCHAR(80) NOT NULL,detection_mode VARCHAR(20) NOT NULL,provider VARCHAR(40) NULL,text_hash CHAR(64) NOT NULL,text_length INT NOT NULL,ai_score DECIMAL(5,2) NOT NULL,human_score DECIMAL(5,2) NOT NULL,is_blocked TINYINT(1) NOT NULL DEFAULT 0,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX idx_feb(feb_request_id),INDEX idx_human(human_score)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");}catch(Throwable $e){}}
}
