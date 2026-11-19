<?php
require_once 'model/Database.php';

$db = new Database();
$pdo = $db->getConnection();

echo "<h2>Vérification des relations dans la base</h2>";

// 1. Chantiers existants
echo "<h3>1. Chantiers existants :</h3>";
$stmt = $pdo->query("SELECT id, titre, code, devis_id, entreprise FROM chantier_banamur LIMIT 5");
$chantiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($chantiers as $chantier) {
    echo "- Chantier #{$chantier['id']}: {$chantier['code']} - {$chantier['titre']} (devis_id: {$chantier['devis_id']}, entreprise: {$chantier['entreprise']})<br>";
}

// 2. Déboursés pour le premier chantier
if (!empty($chantiers)) {
    $premier_chantier = $chantiers[0];
    echo "<h3>2. Déboursés pour le chantier #{$premier_chantier['id']} (devis_id: {$premier_chantier['devis_id']}) :</h3>";

    $stmt = $pdo->prepare("
        SELECT d.id, d.ligne_devis_id, d.montant_debourse, ld.designation 
        FROM debourse_banamur d 
        INNER JOIN ligne_devis_banamur ld ON d.ligne_devis_id = ld.id 
        WHERE d.devis_id = ?
    ");
    $stmt->execute([$premier_chantier['devis_id']]);
    $debourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($debourses as $debourse) {
        echo "- Déboursé #{$debourse['id']}: {$debourse['designation']} (montant: {$debourse['montant_debourse']})<br>";
    }

    // 3. Lignes de déboursé pour le premier déboursé
    if (!empty($debourses)) {
        $premier_debourse = $debourses[0];
        echo "<h3>3. Lignes de déboursé pour le déboursé #{$premier_debourse['id']} :</h3>";

        $stmt = $pdo->prepare("SELECT * FROM ligne_debourse_banamur WHERE debourse_id = ?");
        $stmt->execute([$premier_debourse['id']]);
        $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($lignes) {
            foreach ($lignes as $ligne) {
                echo "- Ligne #{$ligne['id']}: {$ligne['designation']} - {$ligne['categorie']} (montant: {$ligne['montant']})<br>";
            }
        } else {
            echo "Aucune ligne de déboursé trouvée pour ce déboursé.<br>";
        }

        // 4. Fiches de décaissement pour la première ligne
        if (!empty($lignes)) {
            $premiere_ligne = $lignes[0];
            echo "<h3>4. Fiches de décaissement pour la ligne #{$premiere_ligne['id']} :</h3>";

            $stmt = $pdo->prepare("SELECT * FROM fiche_decaissement WHERE ligne_debourse_id = ?");
            $stmt->execute([$premiere_ligne['id']]);
            $fiches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($fiches) {
                foreach ($fiches as $fiche) {
                    echo "- Fiche #{$fiche['id_fiche']}: {$fiche['beficiaire_fiche']} - {$fiche['montant_fiche']} FCFA (décaissé: {$fiche['decaisse']})<br>";
                }
            } else {
                echo "Aucune fiche de décaissement trouvée pour cette ligne.<br>";
            }
        }
    }
}

// 5. Compter les enregistrements dans chaque table
echo "<h3>5. Statistiques des tables :</h3>";
$tables = ['chantier_banamur', 'devis_banamur', 'ligne_devis_banamur', 'debourse_banamur', 'ligne_debourse_banamur', 'fiche_decaissement'];
foreach ($tables as $table) {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
    $count = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "- $table: {$count['count']} enregistrements<br>";
}
