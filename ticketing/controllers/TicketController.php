<?php
namespace Ticketing\Controllers;

use Ticketing\Repositories\TicketRepository;
use Ticketing\Services\TicketService;
use Ticketing\Models\Ticket;

class TicketController
{
    public function __construct(private TicketRepository $repo, private TicketService $service, private array $user) {}

    public function handle(): void
    {
        $action = $_GET['action'] ?? 'dashboard';
        $_SESSION['ticket_reception_csrf'] ??= bin2hex(random_bytes(32));
        if ($action === 'pdf') { $this->pdf(); return; }
        if ($action === 'reception_download') { $this->downloadReception(); return; }
        if ($action === 'attachment_download') { $this->downloadAttachment(); return; }
        $mutations=['store','message','status','reassign','followup_watchers','relaunch','attachments','deadline','reception'];
        if ($_SERVER['REQUEST_METHOD']!=='POST' && in_array($action,$mutations,true)) { http_response_code(405); header('Allow: POST'); echo 'Méthode refusée.'; return; }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
            if (!in_array($action,$mutations,true)) throw new \DomainException('Action invalide.');
            if (!hash_equals($_SESSION['ticket_reception_csrf'],(string)($_POST['_csrf'] ?? ''))) throw new \DomainException('Session expirée. Rechargez la page.');
            if (in_array($action, ['deadline','reception'], true)) $this->receptionAction($action);
            if ($action === 'store') $this->store();
            if ($action === 'message') $this->message();
            if ($action === 'status') $this->status();
            if ($action === 'reassign') $this->reassign();
            if ($action === 'followup_watchers') $this->followupWatchers();
            if ($action === 'relaunch') $this->relaunch();
            if ($action === 'attachments') $this->attachments();
            } catch (\DomainException $e) {
                if($action==='store') $_SESSION['ticket_form_old']=$_POST;
                $_SESSION['ticket_workflow_error']=$e->getMessage();
                $target=$action==='store' ? 'index.php?action=create' : 'index.php?action=show&id='.(int)($_POST['ticket_id'] ?? 0);
                header('Location: '.$target); exit;
            } catch (\Throwable $e) {
                $diagnostic = sprintf(
                    '[Ticketing] %s: %s in %s:%d',
                    get_class($e),
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                );
                error_log($diagnostic);

                $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                $isLocal = $host === 'localhost'
                    || str_starts_with($host, 'localhost:')
                    || $host === '127.0.0.1'
                    || str_starts_with($host, '127.0.0.1:')
                    || $host === '[::1]';

                $_SESSION['ticket_workflow_error'] = $isLocal
                    ? 'Erreur ticketing : ' . $e->getMessage()
                    : 'L’enregistrement a échoué. Veuillez réessayer.';

                header('Location: '.($action==='store'?'index.php?action=create':'index.php?action=show&id='.(int)($_POST['ticket_id'] ?? 0)));
                exit;
            }
        }
        match ($action) {
            'create' => $this->view('create', [
                'categories'=>$this->repo->categories(),
                'priorities'=>$this->repo->priorities(),
                'formOptions'=>$this->repo->creationOptions((int)$this->user['id'],(string)$this->user['service'])
            ]),
            'show' => $this->show(),
            'mine' => $this->list('mine', 'Mes tickets'),
            'received' => $this->list('received', 'Tickets reçus'),
            'service' => $this->list('service', 'Tickets du service'),
            'admin' => $this->list('all', 'Tous mes tickets accessibles'),
            default => $this->dashboard(),
        };
    }

    private function pdf(): void
    {
        $id=(int)($_GET['id'] ?? 0); $this->requireVisible($id);
        $ticket=$this->repo->find($id);
        if (empty($ticket['requester_signature_data'])) $ticket['requester_signature_data']=$this->repo->profileSignature((int)$ticket['requester_id']);
        $print=($_GET['print'] ?? '')==='1';
        $pdf=(new \Ticketing\Services\TicketPdfService())->render($ticket,$this->repo->executors($ticket),$print);
        header('Content-Type: application/pdf');
        header('Cache-Control: private, no-store');
        header('Content-Disposition: '.($print?'inline':'attachment').'; filename="ticket-'.$id.'.pdf"');
        echo $pdf;
    }

    private function receptionAction(string $action): void
    {
        $id=(int)($_POST['ticket_id'] ?? 0); $this->requireVisible($id);
        if (!hash_equals($_SESSION['ticket_reception_csrf'],(string)($_POST['_csrf'] ?? ''))) throw new \DomainException('Session expirée. Rechargez le ticket.');
        if ($action==='deadline') {
            $this->service->setDeadline($id,(string)($_POST['due_at'] ?? ''),$this->user);
        } else {
            (new \Ticketing\Services\TicketReceptionService($this->repo))->upload($id,(array)($_FILES['reception_proof'] ?? []),$this->user);
        }
        $_SESSION['ticket_workflow_success']=$action==='deadline'?'Délai de rendu enregistré.':'Preuve de réception enregistrée.';
        header('Location: index.php?action=show&id='.$id.'#ticket-reception'); exit;
    }

    private function downloadAttachment(): void
    {
        $id=(int)($_GET['id'] ?? 0); $this->requireVisible($id);
        $attachment=null;
        foreach($this->repo->attachments($id) as $row) if((int)$row['id']===(int)($_GET['attachment_id'] ?? 0)) $attachment=$row;
        $path=$attachment?(new \Ticketing\Services\TicketAttachmentStorage())->resolve($attachment['stored_name']):null;
        if(!$path) { http_response_code(404); echo 'Pièce jointe introuvable.'; return; }
        header('Content-Type: application/octet-stream'); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="ticket-' . $id . '-document"; filename*=UTF-8\'\'' . rawurlencode($attachment['original_name']));
        header('Cache-Control: private, no-store'); readfile($path);
    }

    private function downloadReception(): void
    {
        $id=(int)($_GET['id'] ?? 0); $this->requireVisible($id);
        $proof=null;
        foreach($this->repo->receptionProofs($id) as $row) if ((int)$row['id']===(int)($_GET['proof_id'] ?? 0)) $proof=$row;
        $root=realpath(dirname(__DIR__).'/private/receptions');
        $path=$root && $proof ? realpath($root.'/'.$proof['stored_name']) : false;
        if (!$path || !str_starts_with($path,$root.'/') || !is_file($path)) { http_response_code(404); echo 'Document introuvable'; return; }
        header('Content-Type: '.$proof['mime_type']); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="reception-'.$id.'.'.pathinfo($path,PATHINFO_EXTENSION).'"');
        header('Cache-Control: private, no-store'); readfile($path);
    }

    private function dashboard(): void
    {
        $this->view('dashboard', [
            'stats'=>$this->repo->dashboardStats($this->user['id'], $this->user['service']),
            'tickets'=>$this->repo->all('received', $this->user['id'], $this->user['service'])
        ]);
    }

    private function list(string $scope, string $title): void
    {
        $this->view('list', ['title'=>$title, 'tickets'=>$this->repo->all($scope, $this->user['id'], $this->user['service'])]);
    }

    private function show(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $ticket = $this->repo->findVisible($id,(int)$this->user['id'],(string)$this->user['service']);
        if (!$ticket) { http_response_code(404); echo 'Ticket introuvable'; return; }
        $this->repo->markTicketViewed($id, (int)($this->user['id'] ?? 0));
        $config = require __DIR__ . '/../config/services.php';
        $this->view('show', [
            'ticket'=>$ticket,
            'receptionProofs'=>$this->repo->receptionProofs($id),
            'executors'=>$this->repo->executors($ticket),
            'canSetDeadline'=>$this->repo->canSetDeadline($ticket,(int)$this->user['id']),
            'messages'=>$this->repo->messages($id,$this->repo->canSetDeadline($ticket,(int)$this->user['id'])),
            'allowedStatuses'=>\Ticketing\Services\TicketPolicy::transitions($ticket,(int)$ticket['requester_id']===(int)$this->user['id'],$this->repo->canSetDeadline($ticket,(int)$this->user['id'])),
            'canReassign'=>(int)$ticket['requester_id']===(int)$this->user['id']||$this->repo->isServiceManager($ticket,(int)$this->user['id']),
            'attachments'=>$this->repo->attachments($id),
            'history'=>$this->repo->history($id),
            'watchers'=>$this->repo->watchers($id),
            'notificationLogs'=>$this->repo->notificationLogs($id, (string)($ticket['ticket_no'] ?? '')),
            'formOptions'=>$this->repo->creationOptions((int)$this->user['id'],(string)$this->user['service']),
            'statuses'=>Ticket::STATUSES,
            'services'=>$this->repo->serviceOptions($config['services'])
        ]);
    }

    private function store(): void
    {
        $id = $this->service->create($_POST, $this->user,(array)($_FILES['attachments'] ?? []));
        unset($_SESSION['ticket_form_old']);
        $_SESSION['ticket_workflow_success']='Ticket créé et enregistré.';
        header('Location: index.php?action=show&id=' . $id); exit;
    }

    private function message(): void
    {
        $id = (int)($_POST['ticket_id'] ?? 0);
        $this->requireVisible($id);
        $this->service->addMessage([
            'ticket_id'=>$id, 'author_id'=>$this->user['id'], 'author_name'=>$this->user['name'],
            'author_service'=>$this->user['service'], 'message'=>trim((string)($_POST['message'] ?? '')), 'is_internal'=>isset($_POST['is_internal']) ? 1 : 0
        ], $this->user);
        $_SESSION['ticket_workflow_success']='Réponse ajoutée.';
        header('Location: index.php?action=show&id=' . $id); exit;
    }

    private function status(): void
    {
        $this->requireVisible((int)($_POST['ticket_id'] ?? 0));
        $this->service->changeStatus((int)($_POST['ticket_id'] ?? 0), (string)($_POST['status'] ?? ''), $this->user);
        $_SESSION['ticket_workflow_success']='Modification enregistrée.';
        header('Location: index.php?action=show&id=' . (int)($_POST['ticket_id'] ?? 0)); exit;
    }

    private function reassign(): void
    {
        $this->requireVisible((int)($_POST['ticket_id'] ?? 0));
        $this->service->reassign((int)($_POST['ticket_id'] ?? 0), ($_POST['assigned_service'] ?? '') ?: null, (int)($_POST['assigned_user_id'] ?? 0) ?: null, null, $this->user);
        $_SESSION['ticket_workflow_success']='Modification enregistrée.';
        header('Location: index.php?action=show&id=' . (int)($_POST['ticket_id'] ?? 0)); exit;
    }

    private function followupWatchers(): void
    {
        $id = (int)($_POST['ticket_id'] ?? 0);
        $this->requireVisible($id);
        $this->service->addWatchersAndNotify($id, (array)($_POST['watcher_ids'] ?? []), $this->user, trim((string)($_POST['note'] ?? '')));
        $_SESSION['ticket_workflow_success']='Personnes ajoutées au suivi et alertées.';
        header('Location: index.php?action=show&id=' . $id . '#ticket-followup'); exit;
    }

    private function relaunch(): void
    {
        $id = (int)($_POST['ticket_id'] ?? 0);
        $this->requireVisible($id);
        $this->service->relaunch($id, $this->user, trim((string)($_POST['note'] ?? '')));
        $_SESSION['ticket_workflow_success']='Relance envoyée aux personnes concernées.';
        header('Location: index.php?action=show&id=' . $id . '#ticket-followup'); exit;
    }

    private function attachments(): void
    {
        $id = (int)($_POST['ticket_id'] ?? 0);
        $this->requireVisible($id);
        $this->service->attachFiles($id, $_FILES['attachments'] ?? [], $this->user);
        $_SESSION['ticket_workflow_success']='Pièces jointes enregistrées.';
        header('Location: index.php?action=show&id=' . $id . '#ticket-attachments'); exit;
    }

    private function view(string $name, array $data=[]): void
    {
        extract($data);
        $user = $this->user;
        require __DIR__ . '/../views/layout.php';
    }

    private function requireVisible(int $id):void
    {
        if(!$this->repo->findVisible($id,(int)$this->user['id'],(string)$this->user['service'])){
            http_response_code(403);exit('Ce ticket ne vous est pas adressé et ne fait pas partie de votre suivi.');
        }
    }
}
