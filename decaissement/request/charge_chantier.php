<?php
session_start();

include '../model/Database.php';
include '../model/Chantier.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../services/UrgentDisbursementService.php';

// Initialisation de la connexion à la base de données
$pdo = Database::getConnection();
$chantierObj = new Chantier($pdo);

// Vérification si le paramètre 'entreprise' est passé via POST
if (isset($_POST['entreprise']) && !empty($_POST['entreprise'])) {
    $entreprise = $_POST['entreprise'];

    // Récupération des chantiers associés à l'entreprise
    $listeChantier = $chantierObj->getAllChantiersByEntreprise($entreprise);
    $urgentService = new \UrgentDisbursementService($pdo);
    foreach ($listeChantier as &$chantier) {
        $blocking = $urgentService->repository()->findBlockingForChantier((int)($chantier['id'] ?? 0));
        if (is_array($blocking)) {
            $chantier['blocked_urgent_regularization'] = true;
            $chantier['blocked_urgent_message'] = 'Bloque - fiche de ' . (string)($blocking['requester_name'] ?? 'demandeur') . ' non regularisee';
            $chantier['titre'] = trim((string)($chantier['titre'] ?? '') . ' - BLOQUE: fiche de ' . (string)($blocking['requester_name'] ?? 'demandeur') . ' non regularisee');
        }
    }
    unset($chantier);

    AuditTrail::log('decaissement_chantiers_loaded', [
        'module' => 'decaissement',
        'action' => 'charge_chantier',
        'entity_type' => 'entreprise',
        'entity_label' => (string)$entreprise,
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
        'details' => ['chantier_count' => is_array($listeChantier) ? count($listeChantier) : 0],
    ], $pdo);

    // Retourner les chantiers au format JSON
    echo json_encode($listeChantier);
} else {
    AuditTrail::log('decaissement_chantiers_load_failed', [
        'module' => 'decaissement',
        'action' => 'charge_chantier',
        'entity_type' => 'entreprise',
        'result' => 'failure',
        'severity' => 'warning',
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
        'details' => ['reason' => 'missing_entreprise'],
    ], $pdo);
    // Retourner une réponse vide si le paramètre 'entreprise' est manquant
    echo json_encode([]);
}
