<?php
namespace Ticketing\Services;

use Ticketing\Repositories\TicketRepository;
use Throwable;

class TicketService
{
    public function __construct(private TicketRepository $repo) {}

    private function actor(array $actor): array
    {
        return $this->repo->actor((int)($actor['id'] ?? 0));
    }

    private function visible(int $id,array $actor): array
    {
        $ticket=$this->repo->findVisible($id,(int)$actor['id'],(string)$actor['service']);
        if(!$ticket) throw new \DomainException('Ce ticket ne vous est pas accessible.');
        return $ticket;
    }

    private function assignment(?string $service, ?int $userId): array
    {
        if($userId) {
            $actor=$this->repo->actor($userId);
            return ['assigned_user_id'=>$userId,'assigned_user_name'=>$actor['name'],'assigned_service'=>$actor['service'] ?: null];
        }
        $service=trim((string)$service);
        foreach($this->repo->serviceOptions() as $option) {
            if(mb_strtolower($option['label'])===mb_strtolower($service) && $this->repo->findUsersByService($option['label'])) {
                return ['assigned_user_id'=>null,'assigned_user_name'=>null,'assigned_service'=>$option['label']];
            }
        }
        throw new \DomainException('Choisissez un utilisateur actif ou un service disposant d’exécutants actifs.');
    }

    public function create(array $input, array $user, array $files = []): int
    {
        $user=$this->actor($user);
        $nonce=trim((string)($input['request_nonce'] ?? ''));
        if($nonce!=='' && !preg_match('/^[a-f0-9]{64}$/D',$nonce)) throw new \DomainException('Formulaire de création invalide. Rechargez la page.');
        $requestKey=$nonce!==''?hash('sha256',$user['id'].':'.$nonce):null;
        if($requestKey && ($existing=$this->repo->findRequest($requestKey,(int)$user['id']))) return $existing;
        $title=trim((string)($input['title'] ?? '')); $description=trim((string)($input['description'] ?? ''));
        if($title==='' || mb_strlen($title)>220 || $description==='' || strlen($description)>60000) throw new \DomainException('Renseignez un objet (220 caractères maximum) et une description (60 Ko maximum).');
        $priority=$this->getPriority((int)($input['priority_id'] ?? 0));
        if(!$priority || !in_array((int)($input['category_id'] ?? 0),array_map('intval',array_column($this->repo->categories(),'id')),true)) throw new \DomainException('Catégorie ou priorité invalide.');
        $assignment=$this->assignment($input['assigned_service'] ?? null,(int)($input['assigned_user_id'] ?? 0) ?: null);
        $watcherIds=array_values(array_unique(array_map('intval',(array)($input['watcher_ids'] ?? []))));
        if(count($watcherIds)>100 || count($this->repo->findUsersByIds($watcherIds))!==count($watcherIds)) throw new \DomainException('La sélection des personnes en suivi contient un utilisateur inactif ou invalide.');
        $signature=$this->repo->profileSignature((int)$user['id']);
        if($signature==='') throw new \DomainException('Configurez votre signature professionnelle avant de créer le ticket.');
        [$referenceType,$referenceId]=$this->splitReferenceValue((string)($input['related_reference_value'] ?? ''));
        $referenceLabel=trim((string)($input['related_reference'] ?? $input['related_reference_label'] ?? ''));
        foreach(['related_module'=>80,'chantier'=>160] as $field=>$max) if(mb_strlen((string)($input[$field] ?? ''))>$max) throw new \DomainException('Le champ '.$field.' est trop long.');
        if(mb_strlen($referenceLabel)>120 || strlen($referenceType)>40 || strlen($referenceId)>80) throw new \DomainException('Référence trop longue.');
        $storage=new TicketAttachmentStorage(); $validated=$storage->validate($files); $saved=[];
        try {
            $id=$this->repo->transaction(function() use($input,$user,$title,$description,$priority,$assignment,$watcherIds,$signature,$referenceType,$referenceId,$referenceLabel,$storage,$validated,&$saved,$requestKey) {
                $id=$this->repo->create($assignment+[
                    'ticket_no'=>'TK-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(6))),
                    'title'=>$title,'description'=>$description,'category_id'=>(int)$input['category_id'],'priority_id'=>(int)$input['priority_id'],
                    'requester_id'=>(int)$user['id'],'requester_name'=>$user['name'],'requester_service'=>$user['service'],
                    'related_module'=>trim((string)($input['related_module'] ?? '')) ?: null,'related_reference'=>$referenceLabel ?: null,
                    'related_reference_type'=>$referenceType ?: null,'related_reference_id'=>$referenceId ?: null,
                    'chantier'=>trim((string)($input['chantier'] ?? '')) ?: null,'chantier_id'=>(int)($input['chantier_id'] ?? 0) ?: null,
                    'due_at'=>date('Y-m-d H:i:s',time()+max(1,(int)$priority['sla_hours'])*3600),
                    'requester_signature_data'=>$signature,'request_key'=>$requestKey,
                ]);
                $this->repo->replaceWatchers($id,$watcherIds);
                $saved=$storage->store($validated);
                foreach($saved as $file) $this->repo->addAttachment($file+['ticket_id'=>$id,'uploaded_by'=>$user['id']]);
                $this->repo->addHistory($id,null,'nouveau',(int)$user['id'],$user['name'],'Création du ticket');
                return $id;
            });
        } catch(Throwable $e) {
            $storage->cleanup($saved);
            if($e instanceof \PDOException && (int)($e->errorInfo[1] ?? 0)===1062 && $requestKey && ($existing=$this->repo->findRequest($requestKey,(int)$user['id']))) return $existing;
            throw $e;
        }
        $this->notify('created',$this->repo->find($id),$user);
        return $id;
    }

    public function addWatchersAndNotify(int $ticketId,array $watcherIds,array $actor,string $note=''): void
    {
        $actor=$this->actor($actor);
        $added=$this->repo->transaction(function() use($ticketId,$watcherIds,$actor) {
            $ticket=$this->repo->lock($ticketId);
            if(!$this->canRequesterAct($ticket,$actor)) throw new \DomainException('Seul le demandeur peut ajouter des personnes en suivi.');
            $ids=array_values(array_unique(array_map('intval',$watcherIds)));
            if(!$ids || count($ids)>100 || count($this->repo->findUsersByIds($ids))!==count($ids)) throw new \DomainException('Sélectionnez des utilisateurs actifs pour le suivi.');
            $added=$this->repo->addWatchers($ticketId,$ids);
            if($added) $this->repo->addHistory($ticketId,null,'watchers_added',(int)$actor['id'],$actor['name'],'Ajout de '.count($added).' personne(s) en suivi');
            return $added;
        });
        if($added) $this->sendFollowupToRecipients($this->repo->find($ticketId),$added,$actor,$note ?: 'Vous avez été ajouté au suivi de ce ticket.',null,'watchers_added');
    }

    public function relaunch(int $ticketId,array $actor,string $note=''): void
    {
        $actor=$this->actor($actor); $ticket=$this->visible($ticketId,$actor);
        if(!$this->canRequesterAct($ticket,$actor)) throw new \DomainException('Seul le demandeur peut relancer le ticket.');
        if(in_array($ticket['status'],['resolu','cloture','rejete'],true)) throw new \DomainException('Ce ticket est terminé. Rouvrez-le avant de relancer.');
        $this->repo->addHistory($ticketId,null,'relaunch',(int)$actor['id'],$actor['name'],'Relance du ticket');
        $this->sendFollowupToRecipients($ticket,$this->repo->notificationRecipients($ticket),$actor,$note ?: 'Merci de faire un retour sur ce ticket.',null,'relaunch');
    }

    public function attachFiles(int $ticketId,array $files,array $actor): void
    {
        $actor=$this->actor($actor); $this->visible($ticketId,$actor);
        $storage=new TicketAttachmentStorage(); $validated=$storage->validate($files); $saved=[];
        if(!$validated) throw new \DomainException('Sélectionnez au moins un fichier.');
        try {
            $this->repo->transaction(function() use($ticketId,$actor,$storage,$validated,&$saved) {
                $this->repo->lock($ticketId); $this->visible($ticketId,$actor);
                $saved=$storage->store($validated);
                foreach($saved as $file) $this->repo->addAttachment($file+['ticket_id'=>$ticketId,'uploaded_by'=>$actor['id']]);
                $this->repo->addHistory($ticketId,null,'attachments_added',(int)$actor['id'],$actor['name'],'Ajout de '.count($saved).' pièce(s) jointe(s)');
            });
        } catch(Throwable $e) { $storage->cleanup($saved); throw $e; }
        $this->notify('attachments_added',$this->repo->find($ticketId),$actor);
    }

    private function canRequesterAct(?array $ticket,array $actor): bool
    {
        return $ticket && (int)$ticket['requester_id']===(int)$actor['id'] && (int)$actor['id']>0;
    }

    private function splitReferenceValue(string $value): array
    {
        if(!str_contains($value,':')) return ['',''];
        return array_map('trim',explode(':',$value,2));
    }

    protected function notify(string $event,array $ticket,array $actor): void
    {
        $labels=['created'=>'Nouveau ticket','assigned'=>'Nouvelle assignation','status_changed'=>'Statut modifié','commented'=>'Nouvelle réponse','attachments_added'=>'Pièces jointes ajoutées','deadline_set'=>'Délai de rendu confirmé'];
        $this->sendFollowupToRecipients($ticket,$this->repo->notificationRecipients($ticket),$actor,
            ($labels[$event] ?? 'Mise à jour').' : '.$ticket['title'].' — '.(\Ticketing\Models\Ticket::STATUSES[$ticket['status']] ?? $ticket['status']),
            ($labels[$event] ?? 'Suivi ticket').' '.$ticket['ticket_no'],$event);
    }

    protected function sendFollowupToRecipients(array $ticket,array $recipients,array $actor,string $messageText,?string $subject=null,string $event='relaunch'): void
    {
        // Le ticket est déjà validé : une panne de notification ne doit jamais provoquer une seconde création.
        try { $this->ensureNotificationTables(); }
        catch(Throwable $e) { error_log('Ticket notification bootstrap: '.$e->getMessage()); }
        $ticketId=(int)$ticket['id']; $ticketNo=(string)$ticket['ticket_no'];
        $subject=$subject ?: 'Suivi ticket '.$ticketNo;
        $base=class_exists('AccessManager') ? rtrim(\AccessManager::getApplicationBasePath(),'/') : '';
        $link=$base.'/ticketing/index.php?action=show&id='.$ticketId;
        $payload=['ticket_id'=>$ticketId,'ticket_no'=>$ticketNo,'actor_user_id'=>(int)$actor['id'],'event'=>$event];
        $seen=[];
        foreach($recipients as $recipient) {
            $uid=(int)($recipient['id'] ?? 0);
            if($uid<=0 || $uid===(int)$actor['id'] || isset($seen[$uid])) continue;
            $seen[$uid]=true;
            $body=$actor['name'].' — '.$ticketNo.' : '.$messageText;
            try {
                $notificationId=$this->repo->insertUserNotification($uid,$subject,$body,$link,$payload);
                $this->repo->upsertNotificationLog($ticketId,$recipient,'system',$notificationId,(int)$actor['id']);
            } catch(Throwable $e) {
                error_log('Ticket notification system: '.$e->getMessage());
                try { $this->repo->upsertNotificationLog($ticketId,$recipient,'system',null,(int)$actor['id'],null,'failed','Échec de la notification interne.'); } catch(Throwable $ignored) {}
            }
            $email=$this->sendTicketEmail($recipient,$subject,$body,$link,$event);
            try { $this->repo->upsertNotificationLog($ticketId,$recipient,'email',null,(int)$actor['id'],$email['email_log_id'],$email['sent']?'sent':'failed',$email['error']); }
            catch(Throwable $e) { error_log('Ticket notification email log: '.$e->getMessage()); }
        }
    }

    private function ensureNotificationTables(): void
    {
        $managerFile = dirname(__DIR__, 2) . '/model/NotificationManager.php';
        if (is_file($managerFile)) {
            require_once $managerFile;
            if (class_exists('Database') && class_exists('NotificationManager')) {
                new \NotificationManager(\Database::getConnection());
            }
        }
    }

    protected function sendTicketEmail(array $recipient, string $subject, string $message, string $link, string $event): array
    {
        $email = trim((string)($recipient['email'] ?? ''));
        if ($email === '') {
            return ['sent' => false, 'email_log_id' => null, 'error' => 'Adresse email absente.'];
        }
        try {
            $emailServiceFile = dirname(__DIR__, 2) . '/services/NotificationEmailChannelService.php';
            if (!is_file($emailServiceFile)) {
                return ['sent' => false, 'email_log_id' => null, 'error' => 'Service email indisponible.'];
            }
            require_once $emailServiceFile;
            if (!class_exists('NotificationEmailChannelService')) {
                return ['sent' => false, 'email_log_id' => null, 'error' => 'Canal email indisponible.'];
            }
            $emailChannel = new \NotificationEmailChannelService();
            $html = method_exists($emailChannel, 'buildFineaEmail')
                ? $emailChannel->buildFineaEmail($subject, '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>', $link)
                : '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p>' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</p>';
            $sent = (bool)$emailChannel->send($email, (string)($recipient['name'] ?? $email), $subject, $html, $message . "\n" . $link, [
                'notification_user_id' => (int)($recipient['id'] ?? 0),
                'notification_preference_keys' => ['ticketing.'.$event, 'ticketing'],
            ]);
            $emailLogId = method_exists($emailChannel, 'lastEmailLogId') ? $emailChannel->lastEmailLogId() : null;
            $error = '';
            if (!$sent && $emailLogId) {
                try {
                    $pdo = \Database::getConnection();
                    $stmt = $pdo->prepare('SELECT error_message FROM email_logs WHERE id = :id');
                    $stmt->execute(['id' => $emailLogId]);
                    $error = trim((string)($stmt->fetchColumn() ?: ''));
                } catch (Throwable $e) {
                }
            }
            return ['sent' => $sent, 'email_log_id' => $emailLogId, 'error' => $error ?: ($sent ? '' : 'Echec de l envoi email.')];
        } catch (Throwable $e) {
            return ['sent' => false, 'email_log_id' => null, 'error' => $e->getMessage()];
        }
    }

    private function getPriority(int $id): ?array
    {
        foreach ($this->repo->priorities() as $p) {
            if ((int)$p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    public function changeStatus(int $id,string $status,array $user): void
    {
        $user=$this->actor($user);
        $changed=$this->repo->transaction(function() use($id,$status,$user) {
            $ticket=$this->repo->lock($id); $this->visible($id,$user);
            if($ticket['status']===$status) return false;
            TicketPolicy::validateTransition($ticket,$status,$this->canRequesterAct($ticket,$user),$this->repo->canSetDeadline($ticket,(int)$user['id']));
            $this->repo->updateStatus($id,$status);
            $this->repo->addHistory($id,$ticket['status'],$status,(int)$user['id'],$user['name'],'Changement de statut');
            return true;
        });
        if($changed) $this->notify('status_changed',$this->repo->find($id),$user);
    }

    public function addMessage(array $data,array $user): int
    {
        $user=$this->actor($user); $ticketId=(int)($data['ticket_id'] ?? 0);
        $text=trim((string)($data['message'] ?? ''));
        if($text==='' || strlen($text)>60000) throw new \DomainException('Saisissez un message (60 Ko maximum).');
        $internal=!empty($data['is_internal']);
        $id=$this->repo->transaction(function() use($ticketId,$user,$text,$internal) {
            $ticket=$this->repo->lock($ticketId); $this->visible($ticketId,$user);
            if($internal && !$this->repo->canSetDeadline($ticket,(int)$user['id'])) throw new \DomainException('Les notes internes sont réservées aux exécutants et responsables.');
            return $this->repo->addMessage(['ticket_id'=>$ticketId,'author_id'=>$user['id'],'author_name'=>$user['name'],'author_service'=>$user['service'],'message'=>$text,'is_internal'=>(int)$internal]);
        });
        $ticket=$this->repo->find($ticketId);
        if(!$internal) $this->notify('commented',$ticket,$user);
        return $id;
    }

    public function reassign(int $id,?string $service,?int $userId,?string $userName,array $user): void
    {
        $user=$this->actor($user); $assignment=$this->assignment($service,$userId);
        $changed=$this->repo->transaction(function() use($id,$assignment,$user) {
            $ticket=$this->repo->lock($id); $this->visible($id,$user);
            if(!$this->canRequesterAct($ticket,$user) && !$this->repo->isServiceManager($ticket,(int)$user['id'])) throw new \DomainException('Seuls le demandeur et le responsable peuvent réaffecter le ticket.');
            if(in_array($ticket['status'],['resolu','cloture','rejete'],true)) throw new \DomainException('Rouvrez le ticket avant de le réaffecter.');
            if((int)$ticket['assigned_user_id']===(int)$assignment['assigned_user_id'] && (string)$ticket['assigned_service']===(string)$assignment['assigned_service']) return false;
            $this->repo->reassign($id,$assignment['assigned_service'],$assignment['assigned_user_id'],$assignment['assigned_user_name']);
            $this->repo->addHistory($id,$ticket['status'],'reassign',(int)$user['id'],$user['name'],mb_substr('De '.($ticket['assigned_user_name'] ?: $ticket['assigned_service']).' vers '.($assignment['assigned_user_name'] ?: $assignment['assigned_service']),0,255));
            return true;
        });
        if($changed) $this->notify('assigned',$this->repo->find($id),$user);
    }

    public function setDeadline(int $id,string $input,array $user): void
    {
        $user=$this->actor($user); $this->visible($id,$user);
        $this->repo->setDeadline($id,TicketReceptionService::parseDeadline($input),$user);
        $this->notify('deadline_set',$this->repo->find($id),$user);
    }
}
