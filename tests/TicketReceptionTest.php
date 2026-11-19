<?php
declare(strict_types=1);
require_once __DIR__.'/../ticketing/autoload.php';
use Ticketing\Services\TicketReceptionService;
use Ticketing\Services\TicketPdfService;
function ticketCheck(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
foreach(['2026-02-30T12:00','not-a-date','2000-01-01T00:00','2099-01-01T25:00'] as $date) {
    try { TicketReceptionService::parseDeadline($date); throw new LogicException('Invalid deadline accepted'); }
    catch(DomainException $e) {}
}
ticketCheck(TicketReceptionService::parseDeadline('2099-12-12T12:34')==='2099-12-12 12:34:00','Future deadline rejected');
$ticket=['id'=>42,'ticket_no'=>'TK-DEMO-0042','title'=>'Préparer l’offre commerciale — Résidence Les Palmiers','description'=>"Établir une proposition commerciale détaillée pour le client.\nVérifier les quantités, les prix et les conditions de règlement.\nLivrable attendu : devis validé et planning de remise.",'status'=>'nouveau','requester_name'=>'Émetteur de démonstration','requester_service'=>'Direction','assigned_service'=>'Service commercial','category_name'=>'Commercial','priority_name'=>'Haute','created_at'=>'2026-09-07 09:00:00','due_at'=>'2026-09-10 16:00:00','deadline_set_at'=>'2026-09-07 10:30:00','deadline_set_by_name'=>'Responsable de démonstration','chantier'=>'Résidence Les Palmiers','related_reference'=>'DEV-DEMO-0042'];
$executors=[];
for($i=1;$i<=18;$i++) $executors[]=['id'=>$i,'name'=>'Exécutant de démonstration '.$i];
$pdf=(new TicketPdfService())->render($ticket,$executors);
ticketCheck(str_starts_with($pdf,'%PDF-'),'Invalid PDF');
file_put_contents('/tmp/ticket-reception-demo.pdf',$pdf);
$pdf=(new TicketPdfService())->render($ticket,[],true);
ticketCheck(str_contains($pdf,'/JavaScript'),'Print action missing');
$ticket['description']=str_repeat("Ligne détaillée avec accents et caractères spéciaux : <script> & devis.\n",150);
$pdf=(new TicketPdfService())->render($ticket,$executors);
file_put_contents('/tmp/ticket-reception-long.pdf',$pdf);
echo "TicketReceptionTest: OK (dates, PDF, 18 executors, print, long description)\n";

class TicketPolicyRepository extends \Ticketing\Repositories\TicketRepository
{
    public function __construct() {}
    public function isServiceManager(array $ticket,int $userId): bool { return $userId===20; }
    public function executors(array $ticket): array { return [['id'=>30,'name'=>'Exécutant']]; }
}
$repo=new TicketPolicyRepository();
ticketCheck($repo->canSetDeadline($ticket,20),'Responsible denied');
ticketCheck($repo->canSetDeadline($ticket,30),'Executor denied');
ticketCheck(!$repo->canSetDeadline($ticket,40),'Watcher or requester may not set deadline');
ticketCheck(!$repo->canSetDeadline($ticket,0),'Anonymous deadline allowed');
try {(new TicketReceptionService($repo))->upload(42,[],['id'=>20,'name'=>'Demo']); throw new LogicException('Missing file accepted');}catch(DomainException $e){}
$im=imagecreatetruecolor(200,70); $white=imagecolorallocate($im,255,255,255); imagefill($im,0,0,$white);
imagestring($im,5,20,20,'SPECIMEN',imagecolorallocate($im,29,43,87));
ob_start(); imagepng($im); $png=ob_get_clean(); imagedestroy($im);
$ticket['description']='Ticket de démonstration avec signature de test.';
$ticket['requester_signature_data']='data:image/png;base64,'.base64_encode($png);
$ticket['requester_signed_at']='2026-09-07 09:00:00';
$pdf=(new TicketPdfService())->render($ticket,array_slice($executors,0,3));
file_put_contents('/tmp/ticket-reception-signed.pdf',$pdf);
ticketCheck(str_starts_with($pdf,'%PDF-'),'Signature rendering failed');
echo "TicketReceptionTest: OK (responsible/executor permissions, denied outsider, missing proof, signature image)\n";

use Ticketing\Services\TicketPolicy;
$baseTicket=['status'=>'nouveau','deadline_set_at'=>null];
ticketCheck(TicketPolicy::transitions($baseTicket,false,true)===['pris_en_charge','rejete'],'Executor transitions incorrect');
try { TicketPolicy::validateTransition($baseTicket,'cloture',false,true); throw new LogicException('Illegal transition accepted'); }
catch(DomainException $e) {}
try { TicketPolicy::validateTransition($baseTicket,'pris_en_charge',false,true); throw new LogicException('Missing deadline accepted'); }
catch(DomainException $e) {}
$baseTicket['deadline_set_at']='2099-01-01 00:00:00';
TicketPolicy::validateTransition($baseTicket,'pris_en_charge',false,true);
$baseTicket['status']='resolu';
ticketCheck(TicketPolicy::transitions($baseTicket,true,false)===['cloture','en_cours'],'Requester resolution transitions incorrect');
echo "TicketWorkflowPolicyTest: OK (roles, transitions, mandatory deadline)\n";
