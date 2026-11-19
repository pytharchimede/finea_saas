<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Devis.php';
require_once __DIR__ . '/../model/Offre.php';
require_once __DIR__ . '/../model/Chantier.php';
require_once __DIR__ . '/../controllers/DevisOfferProjectController.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getConnection();

    $devisModel = new Devis($pdo);
    $offreModel = new Offre($pdo);
    $chantierModel = new Chantier($pdo);

    $canModifierDebourse = function_exists('canModifyDebourse') ? canModifyDebourse() : (!empty($_SESSION['can_modify_debourse']));

    $filters = [
        'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : '',
        'client_id' => isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int)$_GET['client_id'] : null,
        'correspondant' => isset($_GET['correspondant']) ? (string)$_GET['correspondant'] : null,
        'validation' => isset($_GET['validation']) ? (string)$_GET['validation'] : null,
        'chantier' => isset($_GET['chantier']) ? (string)$_GET['chantier'] : null,
        'created_from' => isset($_GET['created_from']) ? (string)$_GET['created_from'] : null,
        'created_to' => isset($_GET['created_to']) ? (string)$_GET['created_to'] : null,
        'archive_status' => isset($_GET['archive_status']) ? (string)$_GET['archive_status'] : 'active',
        'billing_status' => isset($_GET['billing_status']) ? (string)$_GET['billing_status'] : null,
        'sort' => isset($_GET['sort']) ? (string)$_GET['sort'] : 'recent',
    ];

    // Normalisation: si vide, passer à null (évite des clauses inutiles)
    foreach (['q', 'correspondant', 'validation', 'chantier', 'created_from', 'created_to', 'archive_status', 'billing_status', 'sort'] as $k) {
        if (isset($filters[$k]) && $filters[$k] !== null) {
            $filters[$k] = trim((string)$filters[$k]);
            if ($filters[$k] === '') {
                $filters[$k] = null;
            }
        }
    }

    $devis = $devisModel->searchDevis($filters);
    $devisGroups=(new DevisOfferProjectController($pdo))->group($devis);
    $canClassifyDevis=AccessManager::hasPermission('modifier_classement_devis');
    $canCancelDevisBilling=AccessManager::hasPermission('acces_facturation')
        || AccessManager::hasPermission('acces_direction')
        || AccessManager::hasPermission('acces_admin');
    if(empty($_SESSION['devis_offer_csrf']))$_SESSION['devis_offer_csrf']=bin2hex(random_bytes(32));

    $totalTtc = 0.0;
    foreach ($devis as $de) {
        $totalTtc += (float)($de['total_ttc'] ?? 0);
    }

    ob_start();
    include __DIR__ . '/../partials/devis_offer_groups.php';
    $html = (string)ob_get_clean();

    echo json_encode([
        'ok' => true,
        'nb_devis' => count($devis),
        'total_ttc' => $totalTtc,
        'html' => $html,
    ]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur serveur',
    ]);
}
