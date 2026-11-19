<?php
namespace Ticketing\Services;

final class TicketPolicy
{
    public static function transitions(array $ticket, bool $requester, bool $executor): array
    {
        $status=(string)$ticket['status'];
        $next=[];
        if ($executor) $next=match($status) {
            'nouveau'=>['pris_en_charge','rejete'],
            'pris_en_charge'=>['en_cours','en_attente','resolu','rejete'],
            'en_cours'=>['en_attente','resolu'],
            'en_attente'=>['en_cours','resolu','rejete'],
            'resolu'=>['en_cours'],
            default=>[],
        };
        if ($requester && $status==='resolu') $next=array_merge($next,['cloture','en_cours']);
        if ($requester && in_array($status,['cloture','rejete'],true)) $next[]='nouveau';
        return array_values(array_unique($next));
    }

    public static function validateTransition(array $ticket, string $next, bool $requester, bool $executor): void
    {
        if (!in_array($next,self::transitions($ticket,$requester,$executor),true)) throw new \DomainException('Ce changement de statut n’est pas autorisé.');
        if (in_array($next,['pris_en_charge','en_cours','resolu','cloture'],true) && empty($ticket['deadline_set_at'])) throw new \DomainException('Un exécutant ou le responsable doit confirmer le délai de rendu avant la prise en charge.');
    }
}
