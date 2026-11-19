<?php
declare(strict_types=1);
/** Read-only readiness checks; installation is explicit and CLI-only. */
final class RhFinancialSchema
{
    public const FINANCIAL_TABLES=['rh_financial_settings','rh_financial_points','rh_financial_point_versions','rh_financial_source_claims','rh_financial_events','rh_financial_delivery','rh_financial_source_deferrals','finea_fiche_beneficiary_alerts'];
    public const MONITOR_TABLES=['rh_financial_template_monitor','rh_financial_template_events'];
    public static function missing(PDO $pdo,bool $withMonitor=true):array
    {
        $tables=$withMonitor?array_merge(self::FINANCIAL_TABLES,self::MONITOR_TABLES):self::FINANCIAL_TABLES;
        $q=$pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'?')).')');$q->execute($tables);
        return array_values(array_diff($tables,$q->fetchAll(PDO::FETCH_COLUMN)));
    }
    public static function assertReady(PDO $pdo,bool $withMonitor=true):void
    {
        if(self::missing($pdo,$withMonitor))throw new DomainException('Installation RH financière à terminer. Exécutez sur le serveur : php scripts/install_rh_financial.php');
    }
    public static function statements(string $sql):array
    {
        // These versioned migrations contain no procedures or embedded semicolons.
        return array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\R|$)/',$sql)),static fn($s)=>$s!==''));
    }
}
