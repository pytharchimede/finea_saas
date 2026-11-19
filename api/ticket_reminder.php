<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../ticketing/autoload.php';

use Ticketing\Database\Migration;
use Ticketing\Repositories\TicketRepository;

header('Content-Type: application/json; charset=utf-8');
try {
    $userId=(int)($_SESSION['user_id']??0);
    if($userId<=0)throw new RuntimeException('Session expirée.');
    $pdo=Database::getConnection();
    Migration::run($pdo);
    $repo=new TicketRepository($pdo);
    $actor=$repo->actor($userId);
    $_SESSION['ticket_reminder_csrf']??=bin2hex(random_bytes(32));
    // Le popup est personnel : une affectation au service reste visible dans la
    // boîte de réception, mais ne doit pas interrompre tous ses membres.
    $tickets=array_values(array_filter(
        $repo->pending($userId,(string)$actor['service']),
        static fn(array $ticket): bool => (int)($ticket['assigned_user_id']??0)===$userId
    ));
    if($_SERVER['REQUEST_METHOD']==='POST'){
        if(!hash_equals((string)$_SESSION['ticket_reminder_csrf'],(string)($_POST['_csrf']??'')))throw new RuntimeException('Session expirée.');
        $stmt=$pdo->prepare("INSERT INTO finea_ticket_reminder_acknowledgements(user_id,last_acknowledged_at,acknowledged_ticket_count) VALUES(:user,NOW(),:count) ON DUPLICATE KEY UPDATE last_acknowledged_at=NOW(),acknowledged_ticket_count=VALUES(acknowledged_ticket_count)");
        $stmt->execute(['user'=>$userId,'count'=>count($tickets)]);
        echo json_encode(['ok'=>true]);exit;
    }
    if(!$tickets){echo json_encode(['ok'=>true,'due'=>false,'tickets'=>[],'csrf'=>$_SESSION['ticket_reminder_csrf']]);exit;}
    $ack=$pdo->prepare('SELECT last_acknowledged_at,last_notified_at FROM finea_ticket_reminder_acknowledgements WHERE user_id=:user');$ack->execute(['user'=>$userId]);$state=$ack->fetch(PDO::FETCH_ASSOC)?:[];
    $last=(string)($state['last_acknowledged_at']??'');$due=$last===''||strtotime($last)<=time()-36000;
    if($due){
        $lastNotified=(string)($state['last_notified_at']??'');
        if($lastNotified===''||strtotime($lastNotified)<=time()-36000){
            try{$insert=$pdo->prepare("INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_team,recipient_user_id,channels_json,payload_json) VALUES(NOW(),'ticketing_reminder',:sub,'Tickets en attente de traitement',:message,'/ticketing/index.php?action=received',NULL,:user,'[\"system\",\"in_app\"]',:payload)");$insert->execute(['sub'=>'user_'.$userId.'_'.date('YmdH'),'message'=>'Vos '.count($tickets).' ticket(s) restent en attente de traitement.','user'=>$userId,'payload'=>json_encode(['ticket_count'=>count($tickets)],JSON_UNESCAPED_UNICODE)]);}catch(Throwable $ignored){}
            $notify=$pdo->prepare("INSERT INTO finea_ticket_reminder_acknowledgements(user_id,last_notified_at) VALUES(:user,NOW()) ON DUPLICATE KEY UPDATE last_notified_at=NOW()");$notify->execute(['user'=>$userId]);
        }
    }
    foreach($tickets as &$ticket){$ticket['overdue']=!empty($ticket['due_at'])&&strtotime((string)$ticket['due_at'])<time();$ticket['url']='ticketing/index.php?action=show&id='.(int)$ticket['id'];}unset($ticket);
    echo json_encode(['ok'=>true,'due'=>$due,'tickets'=>$due?$tickets:[],'count'=>count($tickets),'csrf'=>$_SESSION['ticket_reminder_csrf']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(200);echo json_encode(['ok'=>false,'due'=>false,'error'=>$e->getMessage()]);}
