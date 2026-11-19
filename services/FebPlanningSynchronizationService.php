<?php
declare(strict_types=1);

require_once __DIR__.'/FebWorkflowGuard.php';
require_once __DIR__.'/DeboursePeriodService.php';

final class FebPlanningSynchronizationService
{
    public function __construct(private PDO $pdo) {}

    /** Change la date de paiement sans déplacer la période de travaux du déboursé existant. */
    public function synchronizePaymentDate(int $febId, string $scheduledDate, string $reason, int $userId = 0, string $userName = ''): array
    {
        $this->assertDate($scheduledDate);
        $stmt = $this->pdo->prepare("SELECT payload_json FROM employee_legal_requests WHERE id=:id AND request_type='feb' LIMIT 1 FOR UPDATE");
        $stmt->execute(['id' => $febId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) throw new RuntimeException('La FEB à replanifier est introuvable.');
        $payload = json_decode((string) $raw, true);
        if (!is_array($payload)) $payload = [];
        $draft = is_array($payload['provisional_decaissement'] ?? null) ? $payload['provisional_decaissement'] : [];
        $start = trim((string) ($payload['planned_start_date'] ?? $draft['planned_start_date'] ?? ''));
        $end = trim((string) ($payload['planned_end_date'] ?? $draft['planned_end_date'] ?? $start));
        if (!$this->validDate($start) || !$this->validDate($end) || $end < $start) {
            throw new DomainException('La période de travaux de la FEB doit être renseignée avant de fixer son décaissement.');
        }
        $draft['date_decaissement_minimum'] = $scheduledDate;
        $draft['active'] = true;
        $payload['provisional_decaissement'] = $draft;
        $payload['scheduled_disbursement_date'] = $scheduledDate;
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) throw new RuntimeException('Impossible de sauvegarder la date de décaissement de la FEB.');
        if ((string) $raw !== $encoded) {
            $this->pdo->prepare('UPDATE employee_legal_requests SET payload_json=:payload,updated_at=NOW() WHERE id=:id')->execute(['payload' => $encoded, 'id' => $febId]);
            $this->pdo->prepare('INSERT INTO feb_payload_revision_history(feb_request_id,old_payload_json,new_payload_json,change_summary,changed_by_user_id,changed_by_name) VALUES(:feb,:old,:new,:reason,:user,:name)')
                ->execute(['feb' => $febId, 'old' => (string) $raw, 'new' => $encoded, 'reason' => $reason, 'user' => $userId ?: null, 'name' => $userName ?: null]);
        }
        $this->synchronizeLinkedPlanning($febId, $start, $end, $scheduledDate, $reason);
        return ['scheduled_date' => $scheduledDate, 'start' => $start, 'end' => $end, 'payload' => $payload];
    }

    public function synchronizeDate(int $febId, string $targetDate, string $reason, int $userId = 0, string $userName = ''): array
    {
        $this->assertDate($targetDate);
        $stmt=$this->pdo->prepare("SELECT payload_json FROM employee_legal_requests WHERE id=:id AND request_type='feb' LIMIT 1 FOR UPDATE");
        $stmt->execute(['id'=>$febId]);
        $raw=$stmt->fetchColumn();
        if($raw===false)throw new RuntimeException('La FEB à replanifier est introuvable.');
        $payload=json_decode((string)$raw,true);
        if(!is_array($payload))$payload=[];
        $draft=is_array($payload['provisional_decaissement']??null)?(array)$payload['provisional_decaissement']:[];
        $oldDate=trim((string)($draft['date_decaissement_minimum']??$payload['feb_position_date']??$payload['planned_start_date']??''));
        $oldStart=trim((string)($payload['planned_start_date']??$draft['planned_start_date']??$oldDate));
        $oldEnd=trim((string)($payload['planned_end_date']??$draft['planned_end_date']??$oldStart));
        $anchor=$this->validDate($oldDate)?$oldDate:($this->validDate($oldStart)?$oldStart:$targetDate);
        $days=(int)(new DateTimeImmutable($anchor))->diff(new DateTimeImmutable($targetDate))->format('%r%a');
        $start=$this->validDate($oldStart)?(new DateTimeImmutable($oldStart))->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d'):$targetDate;
        $end=$this->validDate($oldEnd)?(new DateTimeImmutable($oldEnd))->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d'):$start;
        if($end<$start)$end=$start;
        $draft['date_decaissement_minimum']=$targetDate;$draft['planned_start_date']=$start;$draft['planned_end_date']=$end;$draft['active']=true;
        $payload['provisional_decaissement']=$draft;$payload['planned_start_date']=$start;$payload['planned_end_date']=$end;$payload['date_livraison']=$start;$payload['feb_position_date']=$targetDate;$payload['disbursement_window_start']=$targetDate;$payload['disbursement_window_end']=$start;
        $shift = function (array &$row) use ($days): void {
            foreach (['planned_start_date','planned_end_date'] as $key) {
                $value = (string) ($row[$key] ?? '');
                if (!$this->validDate($value)) continue;
                $row[$key] = (new DateTimeImmutable($value))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
            }
        };
        foreach ((array) ($payload['besoins'] ?? []) as $index => $line) {
            if (is_array($line)) $shift($payload['besoins'][$index]);
        }
        if (is_array($payload['proposed_debourse'] ?? null)) {
            $shift($payload['proposed_debourse']);
            foreach ((array) ($payload['proposed_debourse']['lines'] ?? []) as $index => $line) {
                if (is_array($line)) $shift($payload['proposed_debourse']['lines'][$index]);
            }
        }
        $encoded=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->pdo->prepare('UPDATE employee_legal_requests SET payload_json=:payload,updated_at=NOW() WHERE id=:id')->execute(['payload'=>$encoded,'id'=>$febId]);
        if((string)$raw!==$encoded){
            $this->pdo->prepare('INSERT INTO feb_payload_revision_history(feb_request_id,old_payload_json,new_payload_json,change_summary,changed_by_user_id,changed_by_name) VALUES(:feb,:old,:new,:reason,:user,:name)')->execute(['feb'=>$febId,'old'=>(string)$raw,'new'=>$encoded,'reason'=>$reason,'user'=>$userId?:null,'name'=>$userName?:null]);
        }
        $this->synchronizeLinkedPlanning($febId,$start,$end,$targetDate,$reason);
        return ['scheduled_date'=>$targetDate,'start'=>$start,'end'=>$end,'payload'=>$payload];
    }

    public function synchronizePeriod(int $febId, string $start, string $end, string $scheduledDate, string $reason): void
    {
        $this->assertDate($start);$this->assertDate($end);$this->assertDate($scheduledDate);
        if($end<$start)throw new DomainException('La fin du planning doit suivre son début.');
        $this->synchronizeLinkedPlanning($febId,$start,$end,$scheduledDate,$reason);
    }

    private function synchronizeLinkedPlanning(int $febId,string $start,string $end,string $scheduledDate,string $reason): void
    {
        $link=$this->pdo->prepare('SELECT debourse_id,ligne_debourse_id FROM feb_disbursement_links WHERE feb_request_id=:feb LIMIT 1');$link->execute(['feb'=>$febId]);$row=$link->fetch(PDO::FETCH_ASSOC)?:[];
        if(!$row){$fallback=$this->pdo->prepare('SELECT ligne_debourse_id FROM fiche_decaissement WHERE feb_request_id=:feb AND ligne_debourse_id IS NOT NULL ORDER BY id_fiche DESC LIMIT 1');$fallback->execute(['feb'=>$febId]);$lineId=(int)$fallback->fetchColumn();if($lineId>0){$q=$this->pdo->prepare('SELECT debourse_id FROM ligne_debourse_banamur WHERE id=:id');$q->execute(['id'=>$lineId]);$row=['ligne_debourse_id'=>$lineId,'debourse_id'=>(int)$q->fetchColumn()];}}
        $lineId=(int)($row['ligne_debourse_id']??0);$debourseId=(int)($row['debourse_id']??0);
        if($debourseId>0) DeboursePeriodService::assertContains($this->pdo,$debourseId,$start,$end);
        $this->pdo->prepare('UPDATE fiche_decaissement SET planned_start_date=:start,planned_end_date=:end,date_decaissement_minimum=:scheduled WHERE feb_request_id=:feb AND COALESCE(decaisse,0)=0')->execute(['start'=>$start,'end'=>$end,'scheduled'=>$scheduledDate,'feb'=>$febId]);
        if($debourseId>0) DeboursePeriodService::synchronizeDetails($this->pdo,$debourseId);
    }

    private function validDate(string $date): bool { return FebWorkflowGuard::validDate($date); }
    private function assertDate(string $date): void { if(!$this->validDate($date))throw new DomainException('La date de replanification est invalide.'); }
}
