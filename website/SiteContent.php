<?php
declare(strict_types=1);
final class SiteContent {
 public static function defaults(array $brand):array {
 return ['headline'=>$brand['company_name'],'intro'=>'Découvrez notre entreprise, nos activités et notre savoir-faire.','about'=>'','services'=>[],'projects'=>[],'news'=>[],'seo_title'=>$brand['company_name'],'seo_description'=>'','show_contact'=>false];
 }
 public static function install(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS finea_website_content (id TINYINT UNSIGNED PRIMARY KEY,draft_json LONGTEXT NOT NULL,published_json LONGTEXT NULL,published_at DATETIME NULL,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 }
 public static function read(PDO $db):array {
 return $db->query('SELECT * FROM finea_website_content WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];
 }
 public static function decode(?string $json,array $brand):array {
 $data=json_decode($json??'',true);return array_replace(self::defaults($brand),is_array($data)?$data:[]);
 }
 public static function fromPost(array $post):array {
 $out=[];foreach(['headline'=>190,'intro'=>1000,'about'=>10000,'seo_title'=>190,'seo_description'=>320] as $key=>$max){$v=trim((string)($post[$key]??''));if(mb_strlen($v)>$max)throw new InvalidArgumentException('Texte trop long : '.$key);$out[$key]=$v;}
 if($out['headline']==='')throw new InvalidArgumentException('Le titre est obligatoire.');
 foreach(['services','projects','news'] as $key){$out[$key]=[];$raw=trim((string)($post[$key]??''));if(mb_strlen($raw)>20000)throw new InvalidArgumentException('Contenu trop long.');foreach(preg_split('/\R/u',$raw) as $line){if(trim($line)==='')continue;[$title,$body]=array_pad(explode('|',$line,2),2,'');if(trim($title)===''||mb_strlen(trim($title))>190||mb_strlen(trim($body))>2000)throw new InvalidArgumentException('Vérifiez les titres et descriptions.');$out[$key][]=['title'=>trim($title),'body'=>trim($body)];}if(count($out[$key])>50)throw new InvalidArgumentException('50 éléments maximum par rubrique.');}
 $out['show_contact']=!empty($post['show_contact']);return $out;
 }
 public static function save(PDO $db,array $data,bool $publish):void {
 $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
 if($publish){$q=$db->prepare('INSERT INTO finea_website_content(id,draft_json,published_json,published_at) VALUES(1,?,?,NOW()) ON DUPLICATE KEY UPDATE draft_json=VALUES(draft_json),published_json=VALUES(published_json),published_at=NOW()');$q->execute([$json,$json]);}
 else{$q=$db->prepare('INSERT INTO finea_website_content(id,draft_json) VALUES(1,?) ON DUPLICATE KEY UPDATE draft_json=VALUES(draft_json)');$q->execute([$json]);}
 }
}
