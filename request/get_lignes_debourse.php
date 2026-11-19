<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Devis.php';
$devisId = (int)($_GET['devise_id'] ?? $_GET['devis_id'] ?? 0);
$ligneId = (int)($_GET['ligne_devis_id'] ?? 0);
$out = ['items' => []];
try {
    $pdo = Database::getConnection();
    $debourse = (new Devis($pdo))->getDeboursesByDevis($devisId)[$ligneId] ?? null;
    $debourseId = (int)($debourse['id'] ?? 0);
    if ($debourseId <= 0) {
        echo json_encode($out);
        exit;
    }
    $sql = 'SELECT l.id, l.categorie, l.designation, l.montant, l.date_debut, l.date_fin,
                   COUNT(fd.id_fiche) AS linked_fiche_count
            FROM ligne_debourse_banamur l
            LEFT JOIN fiche_decaissement fd ON fd.ligne_debourse_id = l.id
            WHERE l.debourse_id = :debourse
            GROUP BY l.id, l.categorie, l.designation, l.montant, l.date_debut, l.date_fin
            ORDER BY l.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute(['debourse' => $debourseId]);
    $out['items'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $out['error'] = $e->getMessage();
}
echo json_encode($out);
