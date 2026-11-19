<?php
require_once 'model/Database.php';
$pdo = Database::getConnection();
$stmt = $pdo->query('DESCRIBE ligne_devis_banamur');
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo $col['Field'] . ' - ' . $col['Type'] . "\n";
}
