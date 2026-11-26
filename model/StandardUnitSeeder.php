<?php
declare(strict_types=1);
final class StandardUnitSeeder
{
    public const UNITS = [
        ['m','Mètre'], ['m²','Mètre carré'], ['m³','Mètre cube'],
        ['mm','Millimètre'], ['cm','Centimètre'], ['km','Kilomètre'],
        ['mm²','Millimètre carré'], ['cm²','Centimètre carré'], ['cm³','Centimètre cube'],
        ['kg','Kilogramme'], ['g','Gramme'], ['t','Tonne'],
        ['L','Litre'], ['mL','Millilitre'], ['h','Heure'], ['min','Minute'],
        ['s','Seconde'], ['j','Jour'], ['sem','Semaine'], ['mois','Mois'],
        ['u','Unité'], ['pce','Pièce'], ['ens','Ensemble'], ['lot','Lot'],
        ['forf','Forfait'], ['paire','Paire'], ['sac','Sac'], ['roul','Rouleau'],
        ['cart','Carton'], ['pal','Palette'], ['barre','Barre'], ['bid','Bidon'],
        ['kWh','Kilowattheure'], ['kW','Kilowatt'], ['W','Watt'],
        ['V','Volt'], ['A','Ampère'], ['bar','Bar'], ['Pa','Pascal'],
        ['MPa','Mégapascal'], ['N','Newton'], ['kN','Kilonewton'],
        ['°C','Degré Celsius'], ['%','Pourcentage'], ['ha','Hectare'],
    ];
    public static function seed(PDO $pdo): array
    {
        if (!$pdo->inTransaction()) throw new LogicException('Transaction requise.');
        $find=$pdo->prepare('SELECT id FROM unites_mesure WHERE symbole=? OR libelle=? LIMIT 1');
        $insert=$pdo->prepare('INSERT INTO unites_mesure (symbole,libelle) VALUES (?,?)');
        $added=0;$existing=0;
        foreach(self::UNITS as [$symbol,$label]){
            $find->execute([$symbol,$label]);
            if($find->fetchColumn()!==false){++$existing;continue;}
            $insert->execute([$symbol,$label]);++$added;
        }
        return ['added'=>$added,'existing'=>$existing];
    }
}
