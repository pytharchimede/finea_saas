<?php

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AccessRequestManager.php';
require_once __DIR__ . '/../model/AccessManager.php';

header('Content-Type: application/json; charset=utf-8');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
    exit;
}

function accessRequestNormalizePermissions($permission): array
{
    if (is_array($permission)) {
        return array_values(array_filter($permission, static function ($item): bool {
            return is_string($item) && trim($item) !== '';
        }));
    }

    return is_string($permission) && trim($permission) !== '' ? [trim($permission)] : [];
}

function accessRequestPermissionLabel(string $permission): string
{
    $map = [
        'acces_admin' => 'Accès Administration',
        'acces_dashboard' => 'Accès Tableau de bord',
        'acces_devis' => 'Accès Devis',
        'acces_generer_devis' => 'Accès Génération devis',
        'acces_gestion_acces' => 'Accès Gestion des accès',
        'acces_decaissement' => 'Accès Décaissement',
        'acces_conformite' => 'Accès Conformité',
        'acces_approbation' => 'Accès Approbation',
        'acces_achat' => 'Accès Achat',
        'acces_compta' => 'Accès Comptabilité',
        'acces_point_financier' => 'Accès Point financier',
        'acces_rh' => 'Accès Ressources humaines',
        'acces_logistique' => 'Accès Logistique',
        'acces_fournisseur' => 'Accès Fournisseurs',
        'acces_facturation' => 'Accès Facturation',
        'acces_mail' => 'Accès Emails / Mailing',
        'acces_communication' => 'Accès Communication',
        'acces_documentation' => 'Accès Documentation',
        'acces_formation' => 'Accès Formation',
        'acces_crm' => 'Accès CRM',
        'acces_direction' => 'Accès Direction',
        'acces_planning' => 'Accès Planning',
        'acces_emploi_du_temps' => 'Accès Emploi du temps',
        'acces_employe' => 'Accès Espace employé',
    ];

    return $map[$permission] ?? ucfirst(str_replace('_', ' ', $permission));
}

try {
    $appBasePath = \AccessManager::getApplicationBasePath();
    $requestedPage = trim((string)($_POST['requested_page'] ?? ''));
    $requestedPageUrl = trim((string)($_POST['requested_page_url'] ?? ''));
    $originUrl = trim((string)($_POST['origin_url'] ?? ''));
    if ($requestedPage === '') {
        throw new RuntimeException('Page demandée manquante');
    }

    $pageKey = basename((string)(parse_url($requestedPage, PHP_URL_PATH) ?: $requestedPage));
    $permissions = accessRequestNormalizePermissions(\AccessManager::PAGE_PERMISSIONS[$pageKey] ?? []);
    if ($permissions === [] && str_contains(str_replace('\\', '/', $requestedPage), '/decaissement/')) {
        $permissions = ['acces_decaissement'];
    }
    $primaryPermission = (string)($permissions[0] ?? '');
    if ($primaryPermission === '') {
        throw new RuntimeException('Impossible de déterminer le droit requis pour cette page');
    }

    $manager = \AccessRequestManager::connect();
    $request = $manager->createRequest([
        'requester_user_id' => (int)($_SESSION['user_id'] ?? 0),
        'requested_page' => $pageKey !== '' ? $pageKey : $requestedPage,
        'requested_page_url' => $requestedPageUrl,
        'origin_url' => $originUrl,
        'primary_permission' => $primaryPermission,
        'primary_permission_label' => accessRequestPermissionLabel($primaryPermission),
        'alternative_permissions' => array_slice($permissions, 1),
        'access_type_label' => (string)(\AccessManager::ACCESS_TYPES[$primaryPermission] ?? 'general'),
        'module_label' => $pageKey,
    ]);

    if (!$manager->sendSupportEmail($request)) {
        throw new RuntimeException('Impossible d\'envoyer le mail au support');
    }

    \AuditTrail::log('access_request_created', [
        'module' => 'security',
        'action' => 'access_request_created',
        'entity_type' => 'access_request',
        'entity_id' => (int)$request['id'],
        'entity_label' => (string)$request['requested_page'],
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'user_name' => (string)($_SESSION['user_name'] ?? ''),
        'result' => 'success',
        'details' => [
            'primary_permission' => $primaryPermission,
            'alternative_permissions' => array_slice($permissions, 1),
        ],
    ]);

    echo json_encode([
        'ok' => true,
        'message' => 'Demande envoyée au support.',
        'wait_url' => $appBasePath . '/access_request_wait.php?id=' . (int)$request['id'],
        'request_id' => (int)$request['id'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
