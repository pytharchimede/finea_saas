<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once 'model/Database.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'verify_table_creation_view',
    'entity_type' => 'database_diagnostic',
    'entity_label' => 'Verification table fiche_decaissement',
    'page_name' => 'verify_table_creation.php',
    'details' => [
        'view_mode' => 'diagnostic',
    ],
]);

$db = new Database();
$pdo = $db->getConnection();

echo "<h2>Vérification de l'existence de la table fiche_decaissement</h2>";

try {
    // Vérifier si la table existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'fiche_decaissement'");
    $table_exists = $stmt->fetch();

    if ($table_exists) {
        echo "<p style='color: green;'>✓ La table fiche_decaissement existe</p>";

        // Afficher la structure
        echo "<h3>Structure de la table :</h3>";
        $stmt = $pdo->query("DESCRIBE fiche_decaissement");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";

        foreach ($columns as $column) {
            $highlight = ($column['Field'] == 'ligne_debourse_id') ? 'style="background-color: yellow;"' : '';
            echo "<tr $highlight>";
            echo "<td>{$column['Field']}</td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Null']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "<td>{$column['Default']}</td>";
            echo "<td>{$column['Extra']}</td>";
            echo "</tr>";
        }
        echo "</table>";

        // Compter les enregistrements
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM fiche_decaissement");
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Nombre d'enregistrements : {$count['total']}</p>";
    } else {
        echo "<p style='color: red;'>✗ La table fiche_decaissement n'existe pas</p>";
        echo "<p>Création de la table en cours...</p>";

        // Lire et exécuter le script SQL
        $sql_content = file_get_contents(__DIR__ . '/database/create_fiche_decaissement.sql');
        $pdo->exec($sql_content);

        echo "<p style='color: green;'>✓ Table créée avec succès</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur: " . $e->getMessage() . "</p>";
}
