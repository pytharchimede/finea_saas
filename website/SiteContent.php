<?php
declare(strict_types=1);
require_once __DIR__.'/BtpDemo.php';
final class SiteContent {
 public static function defaults(array $brand):array {
 return ['headline'=>$brand['company_name'],'intro'=>'Découvrez notre entreprise, nos activités et notre savoir-faire.','about'=>'','services'=>[],'projects'=>[],'news'=>[],'seo_title'=>$brand['company_name'],'seo_description'=>'','show_contact'=>false,'hero_image'=>'','about_image'=>'','tagline'=>'','values'=>[],'figures'=>[],'slides'=>[],'partners'=>[],'careers'=>[],'contact_form'=>false,'demo'=>false];
 }
 public static function install(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS finea_website_content (id TINYINT UNSIGNED PRIMARY KEY,draft_json LONGTEXT NOT NULL,published_json LONGTEXT NULL,published_at DATETIME NULL,updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 }
 public static function installMedia(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS finea_website_media (id CHAR(32) PRIMARY KEY,mime VARCHAR(30) NOT NULL,content MEDIUMBLOB NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 }
 public static function upload(PDO $db,array $file):string {
 if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return '';
 if(($file['error']??-1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])||$file['size']>3*1024*1024)throw new InvalidArgumentException('Image : maximum 3 Mo.');
 $info=@getimagesize($file['tmp_name']);if(!$info||!in_array($info['mime'],['image/jpeg','image/png','image/webp'],true)||$info[0]*$info[1]>20000000)throw new InvalidArgumentException('Image JPEG, PNG ou WebP valide requise (20 mégapixels maximum).');
 $id=bin2hex(random_bytes(16));$q=$db->prepare('INSERT INTO finea_website_media(id,mime,content) VALUES(?,?,?)');$q->execute([$id,$info['mime'],file_get_contents($file['tmp_name'])]);return $id;
 }
 public static function read(PDO $db):array {
 return $db->query('SELECT * FROM finea_website_content WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];
 }
 public static function decode(?string $json,array $brand):array {
 $data=json_decode($json??'',true);return array_replace(self::defaults($brand),is_array($data)?$data:[]);
 }
 public static function fromPost(array $post):array {
 $out=[];foreach(['headline'=>190,'intro'=>1000,'about'=>10000,'seo_title'=>190,'seo_description'=>320,'tagline'=>120] as $key=>$max){$v=trim((string)($post[$key]??''));if(mb_strlen($v)>$max)throw new InvalidArgumentException('Texte trop long : '.$key);$out[$key]=$v;}
 if($out['headline']==='')throw new InvalidArgumentException('Le titre est obligatoire.');
 foreach(['values','figures'] as $key){$out[$key]=[];$raw=trim((string)($post[$key]??''));if(mb_strlen($raw)>20000)throw new InvalidArgumentException('Contenu trop long.');foreach(preg_split('/\R/u',$raw) as $line){if(trim($line)==='')continue;[$title,$body]=array_pad(explode('|',$line,2),2,'');if(trim($title)===''||mb_strlen(trim($title))>190||mb_strlen(trim($body))>2000)throw new InvalidArgumentException('Vérifiez les titres et descriptions.');$out[$key][]=['title'=>trim($title),'body'=>trim($body)];}if(count($out[$key])>50)throw new InvalidArgumentException('50 éléments maximum par rubrique.');}
 foreach(['hero_image','about_image'] as $key){$out[$key]=self::imageId((string)($post[$key]??''));}
 foreach(['services','projects','news','slides','partners','careers'] as $key){
 $out[$key]=[];$rows=$post['entries'][$key]??[];if(!is_array($rows)||count($rows)>50)throw new InvalidArgumentException('50 éléments maximum.');
 foreach($rows as $item){if(!is_array($item)||!empty($item['remove']))continue;$title=trim((string)($item['title']??''));if($title==='')continue;
 $entry=['title'=>$title,'body'=>trim((string)($item['body']??'')),'location'=>trim((string)($item['location']??'')),'category'=>trim((string)($item['category']??'')),'image'=>self::imageId((string)($item['image']??''))];
 foreach(['title'=>190,'body'=>15000,'location'=>190,'category'=>100] as $field=>$max)if(mb_strlen($entry[$field])>$max)throw new InvalidArgumentException('Contenu trop long.');
 $out[$key][]=$entry;
 }}
 $out['demo']=!empty($post['demo']);
 $out['contact_form']=!empty($post['contact_form']);
 $out['show_contact']=!empty($post['show_contact']);return $out;
 }
 public static function imageIds(array $data):array {
 $ids=[$data['hero_image']??'', $data['about_image']??''];
 foreach(['services','projects','news','slides','partners','careers'] as $key)foreach($data[$key]??[] as $item)$ids[]=$item['image']??'';
 return array_values(array_unique(array_filter($ids)));
 }
 public static function installInbox(PDO $db):void {
 $db->exec("CREATE TABLE IF NOT EXISTS finea_website_inbox (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(190) NOT NULL,email VARCHAR(190) NOT NULL,phone VARCHAR(80) NOT NULL,subject VARCHAR(190) NOT NULL,message TEXT NOT NULL,status ENUM('new','read','closed') DEFAULT 'new',created_at DATETIME DEFAULT CURRENT_TIMESTAMP,client_hash CHAR(64) NOT NULL,INDEX(client_hash,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 }
 public static function imageId(string $id):string {if($id!==''&&!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Image invalide.');return $id;}
 public static function save(PDO $db,array $data,bool $publish):void {
 $json=json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
 if($publish){$q=$db->prepare('INSERT INTO finea_website_content(id,draft_json,published_json,published_at) VALUES(1,?,?,NOW()) ON DUPLICATE KEY UPDATE draft_json=VALUES(draft_json),published_json=VALUES(published_json),published_at=NOW()');$q->execute([$json,$json]);}
 else{$q=$db->prepare('INSERT INTO finea_website_content(id,draft_json) VALUES(1,?) ON DUPLICATE KEY UPDATE draft_json=VALUES(draft_json)');$q->execute([$json]);}
 }
}
