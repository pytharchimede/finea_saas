<?php
require_once 'model/Database.php';

$db = new Database();
$pdo = $db->getConnection();

echo "<h2>Création manuelle de la table fiche_decaissement</h2>";

try {
    // Supprimer la table si elle existe (pour recommencer proprement)
    $pdo->exec("DROP TABLE IF EXISTS fiche_decaissement");
    echo "<p>✓ Table existante supprimée</p>";

    // Créer la nouvelle table
    $sql = "
    CREATE TABLE IF NOT EXISTS `fiche_decaissement` (
      `id_fiche` int(11) NOT NULL AUTO_INCREMENT,
      `num_fiche` varchar(50) NOT NULL,
      `beficiaire_fiche` varchar(255) NOT NULL,
      `montant_fiche` decimal(18,2) NOT NULL,
      `tel_beneficiaire_fiche` varchar(20) NOT NULL,
      `date_creat_fiche` datetime DEFAULT CURRENT_TIMESTAMP,
      
      `chantier_id` int(11) DEFAULT NULL COMMENT 'ID du chantier (table chantier_banamur)',
      `serv_bureau_banamur_id` int(11) DEFAULT NULL COMMENT 'ID du service bureau si applicable',
      `affectation_id` int(11) DEFAULT NULL COMMENT 'ID affectation (compatibilité ancien système)',
      
      `ligne_debourse_id` int(11) DEFAULT NULL COMMENT 'ID de la ligne de déboursé spécifique (table ligne_debourse_banamur)',
      
      `designation_fiche` text COMMENT 'Titre général du déboursé (vient de ligne_devis_banamur)',
      `precision_fiche` text COMMENT 'Précision de la demande (vient de ligne_debourse_banamur)',
      `details_demande` text COMMENT 'Détails supplémentaires saisis par l utilisateur',
      
      `mode_paiement` varchar(100) DEFAULT NULL COMMENT 'Mode de paiement choisi',
      `num_piece` varchar(100) DEFAULT NULL COMMENT 'Numéro de pièce justificative',
      
      `photo_beneficiaire` varchar(255) DEFAULT NULL COMMENT 'Nom du fichier photo',
      `cni_beneficiaire` varchar(255) DEFAULT NULL COMMENT 'Nom du fichier CNI',
      
      `entreprise` varchar(100) DEFAULT NULL COMMENT 'Entreprise (FIDEST, BANAMUR, etc.)',
      `code_autorisation_feb` varchar(100) DEFAULT NULL COMMENT 'Code d autorisation FEB si applicable',
      
      `approuve` tinyint(1) DEFAULT 0 COMMENT 'Approuvé par la hiérarchie',
      `conforme` tinyint(1) DEFAULT 0 COMMENT 'Certifié conforme',
      `decaisse` tinyint(1) DEFAULT 0 COMMENT 'Effectivement décaissé',
      `otp_valide` tinyint(1) DEFAULT 0 COMMENT 'OTP validé',
      `etat_fiche` tinyint(1) DEFAULT 0 COMMENT 'État général de la fiche',
      `sauvegarder` tinyint(1) DEFAULT 0 COMMENT 'Sauvegardé définitivement',
      
      `date_conforme` datetime DEFAULT NULL,
      `date_approbation` datetime DEFAULT NULL,
      `date_decaissement` datetime DEFAULT NULL,
      `date_decaissement_minimum` date DEFAULT NULL COMMENT 'Date minimum pour décaissement',
      
      `secur_conforme` varchar(100) DEFAULT NULL COMMENT 'Utilisateur qui a certifié conforme',
      `secur_valid` varchar(100) DEFAULT NULL COMMENT 'Utilisateur qui a validé',
      `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      
      PRIMARY KEY (`id_fiche`),
      UNIQUE KEY `num_fiche` (`num_fiche`),
      KEY `chantier_id` (`chantier_id`),
      KEY `ligne_debourse_id` (`ligne_debourse_id`),
      KEY `beficiaire_fiche` (`beficiaire_fiche`),
      KEY `date_creat_fiche` (`date_creat_fiche`),
      KEY `approuve` (`approuve`),
      KEY `conforme` (`conforme`),
      KEY `decaisse` (`decaisse`),
      KEY `entreprise` (`entreprise`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    $pdo->exec($sql);
    echo "<p style='color: green;'>✓ Table fiche_decaissement créée avec succès</p>";

    // Vérifier la structure
    $stmt = $pdo->query("DESCRIBE fiche_decaissement");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>Structure créée :</h3>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";

    foreach ($columns as $column) {
        $highlight = ($column['Field'] == 'ligne_debourse_id') ? 'style="background-color: yellow;"' : '';
        echo "<tr $highlight>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur: " . $e->getMessage() . "</p>";
}
