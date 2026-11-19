<?php
require_once 'model/Database.php';
$pdo = Database::getConnection();
try {
    $pdo->exec('ALTER TABLE ligne_devis_banamur ADD COLUMN zone VARCHAR(255) DEFAULT NULL');
    echo 'Colonne zone ajoutée avec succès';
} catch (Exception $e) {
    echo 'Erreur: ' . $e->getMessage();
}
