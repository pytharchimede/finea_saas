<?php
declare(strict_types=1);
final class UnitDescriptionMigration {
    public const DEFAULTS = [
        'mm' => 'Mesure une distance ou une dimension linéaire. Longueur de câble, tuyauterie ou mur.',
        'cm' => 'Mesure une distance ou une dimension linéaire. Longueur de câble, tuyauterie ou mur.',
        'm' => 'Mesure une distance ou une dimension linéaire. Longueur de câble, tuyauterie ou mur.',
        'km' => 'Mesure une distance ou une dimension linéaire. Longueur de câble, tuyauterie ou mur.',
        'mm²' => 'Mesure une étendue plane. Peinture, carrelage et toiture.',
        'cm²' => 'Mesure une étendue plane. Peinture, carrelage et toiture.',
        'm²' => 'Mesure une étendue plane. Peinture, carrelage et toiture.',
        'ha' => 'Mesure une étendue plane. Peinture, carrelage et toiture.',
        'cm³' => 'Mesure un espace ou une capacité. Béton, terrassement et liquides.',
        'mL' => 'Mesure un espace ou une capacité. Béton, terrassement et liquides.',
        'L' => 'Mesure un espace ou une capacité. Béton, terrassement et liquides.',
        'm³' => 'Mesure un espace ou une capacité. Béton, terrassement et liquides.',
        'g' => 'Mesure la masse d\'un matériau. Ciment, acier et granulats.',
        'kg' => 'Mesure la masse d\'un matériau. Ciment, acier et granulats.',
        't' => 'Mesure la masse d\'un matériau. Ciment, acier et granulats.',
        's' => 'Mesure une durée écoulée. Main-d’œuvre et location de matériel.',
        'min' => 'Mesure une durée écoulée. Main-d’œuvre et location de matériel.',
        'h' => 'Mesure une durée écoulée. Main-d’œuvre et location de matériel.',
        'j' => 'Mesure une durée écoulée. Main-d’œuvre et location de matériel.',
        'sem' => 'Mesure une durée écoulée. Main-d’œuvre et location de matériel.',
        'W' => 'Mesure le débit de transfert d\'énergie. Dimensionnement des équipements électriques.',
        'kW' => 'Mesure le débit de transfert d\'énergie. Dimensionnement des équipements électriques.',
        'Pa' => 'Mesure une force par unité de surface. Réseaux hydrauliques et résistance des matériaux.',
        'bar' => 'Mesure une force par unité de surface. Réseaux hydrauliques et résistance des matériaux.',
        'MPa' => 'Mesure une force par unité de surface. Réseaux hydrauliques et résistance des matériaux.',
        'N' => 'Mesure une action mécanique. Charges et calculs de structure.',
        'kN' => 'Mesure une action mécanique. Charges et calculs de structure.',
        'mois' => 'Période dont la durée dépend du calendrier. Location mensuelle.',
        'u' => 'Comptage d\'un élément individuel. Équipement ou accessoire.',
        'pce' => 'Comptage d\'une pièce. Quincaillerie.',
        'ens' => 'Groupe d\'éléments constituant un ensemble. Installation complète.',
        'lot' => 'Groupe d\'articles ou de prestations défini au contrat. Lot de travaux.',
        'forf' => 'Prix global pour un périmètre convenu. Installation forfaitaire.',
        'paire' => 'Ensemble de deux éléments associés. Gants ou chaussures.',
        'sac' => 'Contenu d\'un sac selon le produit. Ciment.',
        'roul' => 'Produit conditionné en rouleau. Membrane ou câble.',
        'cart' => 'Contenu d\'un carton selon le fournisseur. Carrelage.',
        'pal' => 'Marchandise regroupée sur une palette. Blocs ou matériaux.',
        'barre' => 'Élément vendu sous forme de barre. Acier.',
        'bid' => 'Contenu d\'un bidon selon sa capacité. Peinture.',
        'kWh' => 'Énergie consommée par une puissance de 1 kW pendant une heure. Consommation électrique.',
        'V' => 'Différence de potentiel électrique. Alimentation électrique.',
        'A' => 'Intensité du courant électrique. Dimensionnement des circuits.',
        '°C' => 'Température exprimée sur l\'échelle Celsius. Conditions de mise en œuvre.',
        '%' => 'Rapport exprimé pour cent. Pente, remise ou avancement.',
    ];
    public static function install(PDO $pdo): void {
        $pdo->exec('ALTER TABLE unites_mesure ADD COLUMN IF NOT EXISTS description TEXT NULL');
        $stmt=$pdo->prepare('UPDATE unites_mesure SET description=? WHERE BINARY symbole=BINARY ? AND description IS NULL');
        foreach(self::DEFAULTS as $symbol=>$description) $stmt->execute([$description,$symbol]);
    }
}
