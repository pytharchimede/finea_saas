<?php
namespace Ticketing\Models;

class Ticket
{
    public const STATUSES = [
        'nouveau' => 'Nouveau',
        'pris_en_charge' => 'Pris en charge',
        'en_cours' => 'En cours',
        'en_attente' => 'En attente',
        'resolu' => 'Résolu',
        'cloture' => 'Clôturé',
        'rejete' => 'Rejeté',
    ];
}
