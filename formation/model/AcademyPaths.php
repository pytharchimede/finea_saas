<?php
declare(strict_types=1);
function academyPaths(PDO $pdo,int $uid,array $allowed): array {
 $q=$pdo->query('SELECT * FROM formation_paths WHERE is_active=1 ORDER BY title');
 $paths=$q->fetchAll(PDO::FETCH_ASSOC);
 $s=$pdo->prepare('SELECT c.id,c.title,c.slug,c.is_active,(SELECT MAX(cert.score) FROM formation_certificates cert WHERE cert.course_id=c.id AND cert.user_id=? AND (cert.expires_at IS NULL OR cert.expires_at>NOW())) AS score FROM formation_path_courses pc JOIN formation_courses c ON c.id=pc.course_id WHERE pc.path_id=? ORDER BY c.sort_order,c.id');
 $result=[];
 foreach($paths as $path){$s->execute([$uid,$path['id']]);$courses=$s->fetchAll(PDO::FETCH_ASSOC);if(!$courses)continue;
 foreach($courses as $course)if(!$course['is_active']||!in_array((int)$course['id'],$allowed,true))continue 2;
 $path['courses']=$courses;$path['completed']=count(array_filter($courses,static fn($c)=>$c['score']!==null));$result[]=$path;}
 return $result;
}
function academyIssuePath(PDO $pdo,int $uid,int $id,array $allowed): array {
 foreach(academyPaths($pdo,$uid,$allowed) as $path){if((int)$path['id']!==$id)continue;
 if($path['completed']!==count($path['courses']))break;
 $score=round(array_sum(array_column($path['courses'],'score'))/count($path['courses']),2);
 $s=$pdo->prepare('INSERT INTO formation_path_certificates(path_id,user_id,title,score,level_label,certificate_ref,verification_code) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=id');
 $s->execute([$id,$uid,$path['title'],$score,\Formation\Repositories\FormationAcademyRepository::levelForScore($score),'FINEA-PATH-'.strtoupper(bin2hex(random_bytes(12))),bin2hex(random_bytes(32))]);
 $s=$pdo->prepare('SELECT * FROM formation_path_certificates WHERE path_id=? AND user_id=?');$s->execute([$id,$uid]);return $s->fetch(PDO::FETCH_ASSOC);}
 throw new RuntimeException('Parcours non validé ou inaccessible.');
}
