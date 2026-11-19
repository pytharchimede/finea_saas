<?php
// Inclure les fichiers nécessaires
require_once '../model/Database.php';
require_once '../model/Devis.php';
require_once '../model/AuditTrail.php';

// Vérifier si l'ID du devis est passé dans l'URL
if (isset($_GET['devisId'])) {
    $pdo = Database::getConnection();
    // Récupérer l'ID du devis depuis l'URL
    $devisId = (int) $_GET['devisId'];

    // Créer une instance de la classe Devis
    $devis = new Devis($pdo);

    // Appeler la méthode validerDevis pour valider le devis
    $result = $devis->validerGenerale($devisId);

    // Si la validation a réussi, rediriger vers la page d'origine
    if ($result) {
        \AuditTrail::log('devis_general_validated', [
            'module' => 'request',
            'action' => 'valider_generale',
            'entity_type' => 'devis',
            'entity_id' => (string) $devisId,
            'entity_label' => 'devis_' . $devisId,
        ], $pdo);
        // Rediriger vers la page d'origine après la validation
        header('Location: ../liste_devis.php'); // Remplacez ce chemin par celui de la page vers laquelle vous voulez rediriger
        exit; // Assurez-vous de stopper l'exécution après la redirection
    } else {
        \AuditTrail::log('devis_general_validation_failed', [
            'module' => 'request',
            'action' => 'valider_generale',
            'entity_type' => 'devis',
            'entity_id' => (string) $devisId,
            'entity_label' => 'devis_' . $devisId,
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'model_validation_failed'],
        ], $pdo);
        // Gérer l'échec de la validation si nécessaire
        echo "Une erreur est survenue lors de la validation du devis.";
    }
} else {
    $pdo = Database::getConnection();
    \AuditTrail::log('devis_general_validation_failed', [
        'module' => 'request',
        'action' => 'valider_generale',
        'entity_type' => 'devis',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_devis_id'],
    ], $pdo);
    // Si aucun devisId n'est passé dans l'URL, afficher un message d'erreur
    echo "L'ID du devis est manquant.";
}
