<?php
declare(strict_types=1);
require_once __DIR__.'/DailyPointWhatsAppTemplateService.php';
require_once __DIR__.'/RhFinancialPointPolicy.php';

class RhFinancialWhatsAppTemplateService extends DailyPointWhatsAppTemplateService
{
    public const URL = 'https://projet.fidest.ci/rh/validation_point_financier.php?token={{7}}';

    public static function body(string $kind): string
    {
        $label = RhFinancialPointPolicy::KINDS[$kind] ?? throw new DomainException('Type de point invalide.');
        return 'Bonjour M. {{1}}, le point RH des '.mb_strtolower($label).' n° {{2}}, arrêté le {{3}}, attend votre signature. Il comprend {{4}} demande(s) pour un montant total de {{5}}. Consultez les lignes, ajustez vos décisions et signez le récapitulatif. Votre accès personnel est valable jusqu’au {{6}}. Merci.';
    }

    public function createForKind(string $kind, string $name, callable $onCreated): array
    {
        $definition = parent::definition($name, self::body($kind));
        $definition['types']['twilio/call-to-action']['actions'][0]['url'] = self::URL;
        $content = $this->request('POST','/Content',$definition);
        $sid = (string)($content['sid'] ?? '');
        if (!preg_match('/^HX[a-fA-F0-9]{32}$/D',$sid)) throw new RuntimeException('Twilio n’a pas retourné de SID valide.');
        $onCreated($sid); // Persist immediately: never recreate on an approval failure.
        $approval = $this->submitKind($kind,$sid,$name);
        return ['sid'=>$sid,'status'=>$approval['status'] ?? 'pending'];
    }

    public function submitKind(string $kind, string $sid, string $name): array
    {
        $this->assertContent($kind,$sid);
        parent::definition($name,self::body($kind));
        return $this->request('POST','/Content/'.$sid.'/ApprovalRequests/whatsapp',['name'=>$name,'category'=>'UTILITY']);
    }

    public function fetchKindApproval(string $kind, string $sid): array
    {
        $content=$this->assertContent($kind,$sid);
        return $this->fetchApproval($sid)+['name'=>(string)($content['friendly_name']??'')];
    }

    public function assertKindApproved(string $kind, string $sid): void
    {
        $approval=$this->fetchKindApproval($kind,$sid);
        if ($approval['status'] !== 'approved' || strtoupper($approval['category']) !== 'UTILITY') throw new DomainException('Le modèle doit être approuvé par Meta en catégorie Utility avant activation.');
    }

    private function assertContent(string $kind, string $sid): array
    {
        if (!preg_match('/^HX[a-fA-F0-9]{32}$/D',$sid)) throw new DomainException('Content SID invalide.');
        $content = $this->request('GET','/Content/'.$sid);
        $cta = $content['types']['twilio/call-to-action'] ?? [];
        if (($content['language'] ?? '') !== 'fr' || ($cta['body'] ?? '') !== self::body($kind)
            || count($cta['actions'] ?? [])!==1 || ($cta['actions'][0]['type'] ?? '')!=='URL'
            || ($cta['actions'][0]['title'] ?? '')!=='Consulter et signer' || ($cta['actions'][0]['url'] ?? '')!==self::URL) {
            throw new DomainException('Ce modèle ne correspond pas au point RH sélectionné.');
        }
        return $content;
    }
}
