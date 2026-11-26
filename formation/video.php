<?php
require_once dirname(__DIR__).'/auth_check.php';
require_once dirname(__DIR__).'/model/Database.php';
require_once __DIR__.'/controllers/FormationAcademyController.php';
require_once __DIR__.'/model/AcademyMedia.php';
$p=$_SESSION['permissions']??[];
$uid=(int)($_SESSION['user_id']??$_SESSION['id']??0);
$admin=!empty($p['formation_admin'])||!empty($p['acces_admin']);
if (!$uid || (!$admin && empty($p['acces_formation']))) {http_response_code(403);exit;}
$pdo=Database::getConnection();
$c=new \Formation\Controllers\FormationAcademyController($pdo);
$ids=array_map('intval',array_column($c->dashboard($uid,$p,$admin)['courses'],'id'));
$q=$pdo->prepare('SELECT l.video_url,l.course_id FROM formation_lessons l JOIN formation_courses c ON c.id=l.course_id WHERE l.id=? AND l.is_active=1 AND c.is_active=1');
$q->execute([(int)($_GET['lesson']??0)]);$row=$q->fetch(PDO::FETCH_ASSOC);
if (!$row || !in_array((int)$row['course_id'],$ids,true) || !preg_match('/^private-video:([a-f0-9]{48}\.(mp4|webm))$/D',$row['video_url']??'',$m)) {http_response_code(404);exit;}
$file=academyVideoDirectory().'/'.$m[1];if (!is_file($file)) {http_response_code(404);exit;}
session_write_close();
$size=filesize($file);$start=0;$end=$size-1;
header('Content-Type: video/'.$m[2]);header('Accept-Ranges: bytes');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
if (isset($_SERVER['HTTP_RANGE'])) {
 if (!preg_match('/^bytes=(\d*)-(\d*)$/D',$_SERVER['HTTP_RANGE'],$r)||($r[1]===''&&$r[2]==='')) {http_response_code(416);header("Content-Range: bytes */$size");exit;}
 if ($r[1]==='') $start=max(0,$size-(int)$r[2]);else {$start=(int)$r[1];if ($r[2]!=='') $end=min($end,(int)$r[2]);}
 if ($start>$end || $start>=$size) {http_response_code(416);header("Content-Range: bytes */$size");exit;}
 http_response_code(206);header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: '.($end-$start+1));if ($_SERVER['REQUEST_METHOD']==='HEAD') exit;
while(ob_get_level()) ob_end_clean();
$f=fopen($file,'rb');fseek($f,$start);$left=$end-$start+1;
while($left>0&&!feof($f)&&!connection_aborted()){$buf=fread($f,min(65536,$left));if($buf==='')break;echo $buf;$left-=strlen($buf);}fclose($f);
