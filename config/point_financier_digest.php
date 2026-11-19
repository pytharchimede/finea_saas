<?php

return [
    'enabled' => true,
    'timezone' => 'Africa/Abidjan',
    'job_key' => 'point_financier_daily_digest',
    'status_key' => 'decaissements',
    'subject_prefix' => 'BANAMUR - Point quotidien de décaissement de la veille',
    'from_name' => 'Direction Financière BANAMUR',

    // Le premier email sera en destinataire principal (TO).
    // Les autres emails de "to" + ceux de "cc" seront ajoutés en copie (CC).
    'to' => [
        ['email' => 'braud@banamur.com', 'name' => 'Boss'],
        ['email' => 'ulrich@banamur.com', 'name' => 'Ulrich AMANI'],
        ['email' => 'baby@banamur.com', 'name' => 'Melissa Baby'],
    ],

    'cc' => [
        // ['email' => 'autre@domaine.com', 'name' => 'Autre destinataire'],
    ],

    // Sans point quotidien arrêté pour la date, aucun faux document vide n’est envoyé.
    'send_when_empty' => false,
];
