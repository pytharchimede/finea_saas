<?php
require_once 'model/Database.php';

$db = new Database();
$pdo = $db->getConnection();

echo "<h2>Test direct de la requête problématique</h2>";

try {
    // Tester la requête exacte du fichier
    $ligne_debourse_id = 20;

    echo "<h3>1. Test de la première requête (ligne_debourse_banamur)</h3>";
    $stmt = $pdo->prepare("SELECT * FROM ligne_debourse_banamur WHERE id = ?");
    $stmt->execute([$ligne_debourse_id]);
    $ligne = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($ligne) {
        echo "<p style='color: green;'>✓ Ligne trouvée dans ligne_debourse_banamur</p>";
        echo "<pre>" . print_r($ligne, true) . "</pre>";
    } else {
        echo "<p style='color: red;'>✗ Aucune ligne trouvée avec ID 20</p>";
    }

    echo "<h3>2. Test de la deuxième requête (fiche_decaissement)</h3>";
    echo "<p>Vérification de l'existence de la table et du champ ligne_debourse_id :</p>";

    // Vérifier la structure
    $stmt = $pdo->query("DESCRIBE fiche_decaissement");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $has_ligne_debourse_id = false;
    foreach ($columns as $column) {
        if ($column['Field'] == 'ligne_debourse_id') {
            $has_ligne_debourse_id = true;
            echo "<p style='color: green;'>✓ Champ ligne_debourse_id trouvé : {$column['Type']}</p>";
            break;
        }
    }

    if (!$has_ligne_debourse_id) {
        echo "<p style='color: red;'>✗ Champ ligne_debourse_id non trouvé</p>";
        echo "<p>Champs disponibles :</p><ul>";
        foreach ($columns as $column) {
            echo "<li>{$column['Field']}</li>";
        }
        echo "</ul>";
    } else {
        // Tester la requête problématique
        echo "<h3>3. Test de la requête de fiche_decaissement</h3>";
        $stmt = $pdo->prepare("
            SELECT 
                f.id_fiche,
                f.beficiaire_fiche,
                f.montant_fiche,
                f.date_creat_fiche as date_creation
            FROM fiche_decaissement f
            WHERE f.ligne_debourse_id = ? AND f.decaisse = 1
            ORDER BY f.date_creat_fiche DESC
        ");
        $stmt->execute([$ligne_debourse_id]);
        $fiches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo "<p style='color: green;'>✓ Requête exécutée avec succès</p>";
        echo "<p>Nombre de fiches trouvées : " . count($fiches) . "</p>";
        if (!empty($fiches)) {
            echo "<pre>" . print_r($fiches, true) . "</pre>";
        }
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Erreur: " . $e->getMessage() . "</p>";
    echo "<p>Trace: " . $e->getTraceAsString() . "</p>";
}
