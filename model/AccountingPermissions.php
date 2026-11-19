<?php
declare(strict_types=1);

/** Accounting grants are explicit, independent from payment and direction roles. */
final class AccountingPermissions
{
    public const LABELS = [
        'acces_comptabilite'=>'Accéder au module Comptabilité',
        'compta_consulter_ecritures'=>'Consulter les écritures et leurs détails',
        'compta_consulter_livres'=>'Consulter les indicateurs, journaux, grand livre et balance',
        'compta_consulter_sources'=>'Consulter les points de décaissement payés',
        'compta_consulter_justificatifs'=>'Consulter et télécharger les justificatifs',
        'compta_saisir'=>'Créer et modifier les brouillons / joindre les pièces',
        'compta_valider'=>'Valider et passer les écritures',
        'compta_annuler'=>'Annuler les brouillons',
        'compta_contrepasser'=>'Contrepasser une écriture validée',
        'compta_plan'=>'Ajouter et importer des comptes',
        'compta_parametrer'=>'Créer les exercices et les journaux',
        'compta_cloturer'=>'Clôturer les périodes et les exercices (définitif)',
        'compta_exporter'=>'Exporter les données / imprimer les livres',
        'compta_audit'=>'Consulter l’historique des écritures',
    ];
    public const ACTIONS = [
        'prepare_source'=>['compta_saisir','compta_consulter_ecritures','compta_consulter_sources','compta_consulter_justificatifs'],
        'save'=>['compta_saisir','compta_consulter_ecritures','compta_consulter_justificatifs'],
        'post'=>['compta_valider','compta_consulter_ecritures','compta_consulter_justificatifs'],
        'cancel'=>['compta_annuler','compta_consulter_ecritures'],
        'reverse'=>['compta_contrepasser','compta_consulter_ecritures','compta_consulter_justificatifs'],
        'account'=>['compta_plan'], 'import_accounts'=>['compta_plan'],
        'exercise'=>['compta_parametrer'], 'journal'=>['compta_parametrer'],
        'close'=>['compta_cloturer'],
    ];
    public const VIEWS = [
        'dashboard'=>['compta_consulter_livres','compta_consulter_ecritures'],
        'entries'=>['compta_consulter_ecritures'], 'detail'=>['compta_consulter_ecritures'],
        'edit'=>['compta_saisir','compta_consulter_ecritures','compta_consulter_justificatifs'],
        'journal'=>['compta_consulter_livres'], 'ledger'=>['compta_consulter_livres'], 'balance'=>['compta_consulter_livres'],
        'disbursements'=>['compta_consulter_sources'], 'point'=>['compta_consulter_sources'],
        'source'=>['compta_consulter_sources','compta_consulter_justificatifs'],
        'accounts'=>[], 'settings'=>[],
    ];
    public static function allows(array $grants, array $required=[]): bool
    {
        if (!empty($grants['acces_admin'])) return true;
        if (empty($grants['acces_comptabilite'])) return false;
        foreach ($required as $key) if (empty($grants[$key])) return false;
        return true;
    }
    public static function action(array $grants,string $action): bool
    {
        return isset(self::ACTIONS[$action]) && self::allows($grants,self::ACTIONS[$action]);
    }
    public static function view(array $grants,string $view): bool
    {
        return isset(self::VIEWS[$view]) && self::allows($grants,self::VIEWS[$view]);
    }
}
