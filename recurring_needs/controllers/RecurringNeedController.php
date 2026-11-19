<?php

require_once __DIR__ . '/../services/RecurringNeedService.php';

class RecurringNeedController
{
    private RecurringNeedService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new RecurringNeedService($pdo);
    }

    public function index(): array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $canSeeAll = !empty($_SESSION['permissions']['voir_tous_besoins_recurrents']) || !empty($_SESSION['permissions']['acces_admin']);
        return $this->service->dashboard($userId, $canSeeAll, [
            'q' => $_GET['q'] ?? '', 'q_mode'=>$_GET['q_mode']??'include',
            'archive_mode' => !empty($_GET['archives']),
            'requester' => $_GET['requester'] ?? [], 'requester_mode'=>$_GET['requester_mode']??'include',
            'beneficiary' => $_GET['beneficiary'] ?? [], 'beneficiary_mode'=>$_GET['beneficiary_mode']??'include',
            'assignment' => $_GET['assignment'] ?? [], 'assignment_mode'=>$_GET['assignment_mode']??'include',
            'status' => $_GET['status'] ?? '',
            'frequency_type' => $_GET['frequency_type'] ?? '',
            'priority' => $_GET['priority'] ?? '',
            'due_from' => $_GET['due_from'] ?? '',
            'due_to' => $_GET['due_to'] ?? '',
        ]);
    }

    public function store(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return ['ok' => false, 'message' => 'Méthode invalide.'];
        }
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $userName = (string)($_SESSION['nom'] ?? $_SESSION['username'] ?? ('Utilisateur #' . $userId));
        $userEmail = (string)($_SESSION['mail_pro'] ?? $_SESSION['email'] ?? '');
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $canSeeAll = !empty($_SESSION['permissions']['voir_tous_besoins_recurrents']) || !empty($_SESSION['permissions']['acces_admin']);
                $this->service->updateFromRequest($id, $_POST, $userId, $canSeeAll);
                return ['ok' => true, 'id' => $id, 'message' => 'Besoin récurrent mis à jour.'];
            }
            $id = $this->service->createFromRequest($_POST, $userId, $userName, $userEmail);
            return ['ok' => true, 'id' => $id, 'message' => 'Besoin récurrent enregistré.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function find(int $id): ?array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $canSeeAll = !empty($_SESSION['permissions']['voir_tous_besoins_recurrents']) || !empty($_SESSION['permissions']['acces_admin']);
        return $this->service->findForUser($id, $userId, $canSeeAll);
    }

    public function action(): array
    {
        $action = (string)($_POST['action'] ?? '');
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $canManageAll = !empty($_SESSION['permissions']['acces_admin']);
        $canValidate = !empty($_SESSION['permissions']['valider_besoin_recurrent']) || $canManageAll;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['selected_ids'] ?? [$_POST['id'] ?? 0])))));
        if (!$ids) return ['ok' => false, 'message' => 'Sélectionnez au moins un besoin récurrent.'];
        $apply = function(int $id) use ($action,$userId,$canManageAll,$canValidate): void {
            if ($action === 'restore') {
                $this->service->restore($id,$userId,$canManageAll);
                return;
            }
            if (in_array($action, ['approve', 'activate', 'resume'], true)) {
                if (!$canValidate && $action !== 'resume') {
                    throw new RuntimeException('Vous n’avez pas le droit d’activer ce besoin récurrent.');
                }
                if ($action==='resume' && !$this->service->findForUser($id, $userId, $canManageAll)) {
                    throw new RuntimeException('Besoin récurrent introuvable ou non autorisé.');
                }
                $this->service->approve($id, $userId);
                return;
            }
            if ($action === 'suspend') {
                if (!$this->service->findForUser($id, $userId, $canManageAll)) {
                    throw new RuntimeException('Besoin récurrent introuvable ou non autorisé.');
                }
                $this->service->suspend($id, $userId, (string)($_POST['reason'] ?? ''));
                return;
            }
            if ($action === 'cancel') {
                $this->service->cancel($id, $userId, $canManageAll);
                return;
            }
            throw new RuntimeException('Action inconnue.');
        };
        $done=0;$errors=[];
        foreach($ids as$id){try{$apply($id);$done++;}catch(Throwable$e){$errors[]='#'.$id.' : '.$e->getMessage();}}
        if($done===0)return ['ok'=>false,'message'=>implode(' ',array_slice($errors,0,5))?:'Aucun besoin traité.'];
        $labels=['approve'=>'activé','activate'=>'activé','resume'=>'réactivé','restore'=>'restauré','suspend'=>'suspendu','cancel'=>'annulé'];
        $message=$done.' besoin(s) récurrent(s) '.($labels[$action]??'traité(s)').'.';
        if($errors)$message.=' '.count($errors).' élément(s) ignoré(s) : '.implode(' ',array_slice($errors,0,3));
        return ['ok'=>true,'message'=>$message];
    }
}
