<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
final class Life {
 const TYPES=['news'=>'Actualités internes','event'=>'Événements','birthday'=>'Anniversaires','welcome'=>'Nouveaux collaborateurs','award'=>'Distinctions','gallery'=>'Galerie','poll'=>'Sondages'];
 public static function db():PDO{return Database::getConnection();}
 public static function admin():bool{return hasPermission('acces_admin');}
 public static function uid():int{$id=(int)($_SESSION['user_id']??0);if(!$id){http_response_code(403);exit;}return $id;}
 public static function install(PDO $db):void{
 $db->exec("CREATE TABLE IF NOT EXISTS finea_life_settings (id INT PRIMARY KEY,games TINYINT NOT NULL DEFAULT 0,disabled_json TEXT NULL) ENGINE=InnoDB");
 $db->exec("CREATE TABLE IF NOT EXISTS finea_life_posts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,kind VARCHAR(20) NOT NULL,title VARCHAR(190) NOT NULL,body TEXT NOT NULL,event_date DATE NULL,image MEDIUMBLOB NULL,mime VARCHAR(30) NULL,options_json TEXT NULL,published TINYINT NOT NULL DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $db->exec("CREATE TABLE IF NOT EXISTS finea_life_ideas (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id INT NOT NULL,body TEXT NOT NULL,status VARCHAR(20) DEFAULT 'new',created_at DATETIME DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 $db->exec("CREATE TABLE IF NOT EXISTS finea_life_votes (post_id BIGINT UNSIGNED NOT NULL,user_id INT NOT NULL,choice INT NOT NULL,PRIMARY KEY(post_id,user_id),FOREIGN KEY(post_id) REFERENCES finea_life_posts(id) ON DELETE CASCADE) ENGINE=InnoDB");
 }
 public static function settings(PDO $db):array{$r=$db->query('SELECT * FROM finea_life_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[];return ['games'=>!empty($r['games']),'disabled'=>json_decode($r['disabled_json']??'[]',true)?:[]];}
 public static function gameGuard():void{self::uid();try{if(self::settings(self::db())['games'])return;}catch(Throwable $e){}http_response_code(403);exit('Espace détente désactivé.');}
 public static function csrf():string{return $_SESSION['life_csrf']??=bin2hex(random_bytes(32));}
 public static function check():void{if(!hash_equals(self::csrf(),(string)($_POST['csrf']??''))){http_response_code(403);exit;}}
 public static function h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
}
