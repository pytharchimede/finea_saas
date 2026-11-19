<?php
session_start();
require_once '../model/Database.php';

header('Content-Type: application/json');

$chantier_code = isset($_GET['chantier_code']) ? trim($_GET['chantier_code']) : '';
if ($chantier_code === '') {
    echo json_encode(['success' => false, 'message' => 'Paramètre chantier_code manquant']);
    exit;
}

try {
    $pdo = Database::getConnection();

    // Objectif par défaut: uniquement pour CH060 pour l’instant
    $target = ($chantier_code === 'CH060') ? 218 : null;
    // Densité estimée de la terre (t/m3) – paramétrable si besoin
    $density = 1.6;

    $sql = "SELECT s.*, f.beficiaire_fiche
            FROM fiche_transport_spec s
            LEFT JOIN fiche_decaissement f ON f.id_fiche = s.fiche_id
            WHERE s.chantier_code = :code
            ORDER BY s.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute(['code' => $chantier_code]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    $i = 0;
    $volumeTotal = 0.0;
    foreach ($rows as $r) {
        $i++;
        $vol = isset($r['volume_m3']) ? (float)$r['volume_m3'] : 0.0;
        $ton = round($vol * $density, 2);
        $volumeTotal += $vol;
        $items[] = [
            'voyage_index' => $i,
            'num_fiche' => $r['num_fiche'],
            'created_at' => $r['created_at'],
            'chauffeur_nom' => $r['chauffeur_nom'],
            'chauffeur_tel' => $r['chauffeur_tel'],
            'vehicule_matricule' => $r['vehicule_matricule'],
            'volume_m3' => $vol,
            'tonnage_t' => $ton,
            'beneficiaire' => $r['beficiaire_fiche']
        ];
    }
    $voyagesTotal = count($items);
    $tonnageTotal = round($volumeTotal * $density, 2);

    echo json_encode([
        'success' => true,
        'chantier_code' => $chantier_code,
        'voyages_target' => $target,
        'density_t_per_m3' => $density,
        'voyages_total' => $voyagesTotal,
        'volume_total_m3' => $volumeTotal,
        'tonnage_total_t' => $tonnageTotal,
        'items' => $items
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
