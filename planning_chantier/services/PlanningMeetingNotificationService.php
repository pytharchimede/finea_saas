<?php
declare(strict_types=1);

final class PlanningMeetingNotificationService
{
    public function __construct(private PDO $pdo, private PlanningMeetingRepository $meetings) {}

    public function notifyClosedMeeting(int $meetingId, ?string $pdfPath = null): array
    {
        $meeting = $this->meetings->find($meetingId);
        if (!$meeting) return ['email_sent' => 0, 'system_sent' => 0];

        $participants = $this->meetings->participants($meetingId);
        $items = $this->meetings->items($meetingId);
        $actionPoints=$this->meetings->actionPoints($meetingId);
        $documentTitle=$this->documentTitle($meeting);
        $subject = $documentTitle.' - '.date('d/m/Y', strtotime((string)$meeting['meeting_date']));
        $html = $this->buildHtml($meeting, $items, $actionPoints);
        $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
        $emailSent = 0;
        $systemSent = 0;
        $cc = [];

        foreach ($participants as $participant) {
            $email = trim((string)($participant['participant_email'] ?? ''));
            $name = trim((string)($participant['participant_name'] ?? ''));
            $userId = (int)($participant['user_id'] ?? 0);

            if ($userId > 0) {
                $systemSent += $this->insertSystemNotification($userId, $subject, 'Le '.$documentTitle.' est disponible.', $meetingId);
            }

            if ($email !== '' && (int)($participant['notify_email'] ?? 1) === 1) $cc[]=['email'=>$email,'name'=>$name];
        }

        $editor=$this->editor((int)($meeting['created_by']??0));
        if(!$editor && $cc)$editor=array_shift($cc);
        if($editor){
            $cc=array_values(array_filter($cc,static fn($r)=>strcasecmp($r['email'],$editor['email'])!==0));
            if($this->sendEmail($editor['email'],$editor['name'],$subject,$html,$plain,$pdfPath,(int)($meeting['created_by']??0),$cc))$emailSent=1;
        }

        return ['email_sent' => $emailSent, 'system_sent' => $systemSent];
    }

    private function sendEmail(string $email, string $name, string $subject, string $html, string $plain, ?string $pdfPath, int $userId = 0,array $cc=[]): bool
    {
        $candidates = [
            dirname(__DIR__, 2) . '/services/NotificationEmailChannelService.php',
            dirname(__DIR__) . '/services/NotificationEmailChannelService.php',
            dirname(__DIR__, 2) . '/decaissement/services/NotificationEmailChannelService.php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                require_once $path;
                break;
            }
        }

        if (!class_exists('NotificationEmailChannelService')) {
            return false;
        }

        $options = [
            'notification_preference_keys' => ['planning_chantier_reunion'],
            'notification_user_id' => $userId,
            'cc' => $cc,
        ];

        if ($pdfPath && is_file($pdfPath)) {
            $options['attachments'] = [['path'=>$pdfPath,'name'=>basename($pdfPath),'type'=>'application/pdf']];
        }

        try {
            return (bool)(new NotificationEmailChannelService())->send($email, $name, $subject, $html, $plain, $options);
        } catch (Throwable) {
            return false;
        }
    }

    private function editor(int $userId):?array
    {
        if($userId<=0)return null;
        try{
            $stmt=$this->pdo->prepare("SELECT * FROM user_devis_banamur WHERE id=:id LIMIT 1");
            $stmt->execute(['id'=>$userId]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if($row){
                $email=trim((string)($row['mail_pro']??$row['email']??''));
                $name=trim((string)($row['name']??$row['nom']??$row['username']??('Utilisateur #'.$userId)));
                if(filter_var($email,FILTER_VALIDATE_EMAIL))return ['email'=>$email,'name'=>$name];
            }
        }catch(Throwable){}
        return null;
    }

    private function insertSystemNotification(int $userId, string $title, string $body, int $meetingId): int
    {
        $tables = ['notifications', 'user_notifications', 'finea_notifications'];
        foreach ($tables as $table) {
            if (!$this->tableExists($table)) continue;
            $cols = $this->columns($table);
            try {
                if (in_array('user_id', $cols, true) && in_array('title', $cols, true)) {
                    $fields = ['user_id','title'];
                    $params = ['user_id'=>$userId,'title'=>$title];
                    if (in_array('message', $cols, true)) { $fields[]='message'; $params['message']=$body; }
                    if (in_array('body', $cols, true)) { $fields[]='body'; $params['body']=$body; }
                    if (in_array('url', $cols, true)) { $fields[]='url'; $params['url']='planning_chantier/exports/meeting_pdf.php?id='.$meetingId; }
                    if (in_array('created_at', $cols, true)) { $fields[]='created_at'; $params['created_at']=date('Y-m-d H:i:s'); }
                    $sql = "INSERT INTO `$table` (`".implode('`,`',$fields)."`) VALUES (:".implode(',:',$fields).")";
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($params);
                    return 1;
                }
            } catch (Throwable) {}
        }
        return 0;
    }

    private function buildHtml(array $meeting, array $items,array $actionPoints=[]): string
    {
        $documentTitle=$this->documentTitle($meeting);
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<tr><td style="padding:10px;border-bottom:1px solid #e5e7eb"><strong>' . htmlspecialchars((string)$item['sujet'], ENT_QUOTES, 'UTF-8') . '</strong><br><small>' . htmlspecialchars((string)($item['project_label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</small></td><td style="padding:10px;border-bottom:1px solid #e5e7eb">' . nl2br(htmlspecialchars((string)($item['constat'] ?? ''), ENT_QUOTES, 'UTF-8')) . '</td><td style="padding:10px;border-bottom:1px solid #e5e7eb">' . nl2br(htmlspecialchars((string)($item['decision_text'] ?? ''), ENT_QUOTES, 'UTF-8')) . '</td></tr>';
        }

        $officeRows='';
        foreach($actionPoints as $point)$officeRows.='<tr><td style="padding:8px;border-bottom:1px solid #e5e7eb"><strong>'.htmlspecialchars((string)$point['service_title'],ENT_QUOTES,'UTF-8').'</strong><br><small>'.htmlspecialchars((string)$point['meeting_section_title'],ENT_QUOTES,'UTF-8').'</small></td><td style="padding:8px;border-bottom:1px solid #e5e7eb">'.nl2br(htmlspecialchars((string)$point['task_title'],ENT_QUOTES,'UTF-8')).(!empty($point['checklist_text'])?'<br><small style="color:#64748b">'.nl2br(htmlspecialchars((string)$point['checklist_text'],ENT_QUOTES,'UTF-8')).'</small>':'').'</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">'.htmlspecialchars((string)($point['pilot_name']??''),ENT_QUOTES,'UTF-8').'</td><td style="padding:8px;border-bottom:1px solid #e5e7eb">'.number_format((float)$point['progress'],0,',',' ').'%</td></tr>';
        $officeTable=$officeRows!==''?'<h3>Suivi opérationnel du Bureau</h3><table style="width:100%;border-collapse:collapse"><thead><tr style="background:#1d2b57;color:#fff"><th style="padding:8px">Section</th><th style="padding:8px">Tâche</th><th style="padding:8px">Pilote</th><th style="padding:8px">AV</th></tr></thead><tbody>'.$officeRows.'</tbody></table>':'';

        return '<div style="font-family:Arial,sans-serif;color:#0f172a">
            <div style="background:#1d2b57;color:white;padding:18px;border-radius:16px">
                <h2 style="margin:0">'.htmlspecialchars($documentTitle,ENT_QUOTES,'UTF-8').'</h2>
                <p style="margin:6px 0 0">BANAMUR - ' . htmlspecialchars((string)$meeting['titre'], ENT_QUOTES, 'UTF-8') . '</p>
            </div>
            <p><strong>Date :</strong> ' . date('d/m/Y', strtotime((string)$meeting['meeting_date'])) . '</p>
            <table style="width:100%;border-collapse:collapse;margin-top:14px"><thead><tr style="background:#f8fafc"><th style="padding:10px;text-align:left">Sujet</th><th style="padding:10px;text-align:left">Constat</th><th style="padding:10px;text-align:left">Décision</th></tr></thead><tbody>' . $rows . '</tbody></table>
            '.$officeTable.'
            <p><strong>Résumé de réunion :</strong><br>' . nl2br(htmlspecialchars((string)($meeting['resume'] ?? ''), ENT_QUOTES, 'UTF-8')) . '</p>
            <p><strong>Décisions générales :</strong><br>' . nl2br(htmlspecialchars((string)($meeting['decisions'] ?? ''), ENT_QUOTES, 'UTF-8')) . '</p>
        </div>';
    }

    private function documentTitle(array $meeting):string
    {
        if((int)($meeting['office_service_line_id']??0)<=0)return 'Point de réunion chantier';
        $service=trim((string)($meeting['project_label']??''));
        $subject=preg_replace('/^service\s+/iu','',$service)?:$service;
        return 'Point de réunion '.mb_strtolower($subject!==''?$subject:'service','UTF-8');
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE :table");
            $stmt->execute(['table' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function columns(string $table): array
    {
        try {
            return $this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
