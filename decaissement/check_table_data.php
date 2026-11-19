<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once 'model/Database.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'check_table_data_view',
    'entity_type' => 'database_diagnostic',
    'entity_label' => 'Controle donnees fiche_decaissement',
    'page_name' => 'check_table_data.php',
    'details' => [
        'view_mode' => 'diagnostic',
    ],
]);

$db = new Database();
$pdo = $db->getConnection();

echo "<h2>Données dans fiche_decaissement</h2>";

try {
    $stmt = $pdo->query("SELECT * FROM fiche_decaissement LIMIT 10");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($data)) {
        echo "Aucune donnée trouvée dans fiche_decaissement";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";

        // Headers
        echo "<tr>";
        foreach (array_keys($data[0]) as $header) {
            echo "<th>$header</th>";
        }
        echo "</tr>";

        // Data
        foreach ($data as $row) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td>$value</td>";
            }
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "Erreur: " . $e->getMessage();
}
