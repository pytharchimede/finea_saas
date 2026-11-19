<?php
// Contrôle d'authentification + gestion des accès granulaires
// Certains hébergements interdisent l'allocation de mémoire exécutable requise
// par PCRE JIT. Le désactiver pour FINEA évite les warnings sans changer le
// résultat des expressions régulières.
if ((string) ini_get('pcre.jit') !== '0') {
    @ini_set('pcre.jit', '0');
}
require_once __DIR__ . '/model/EnvConfig.php';
\EnvConfig::loadFromDirectory(__DIR__);

require_once __DIR__ . '/model/Auth.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/PageAccess.php';
require_once __DIR__ . '/model/AccessManager.php';
require_once __DIR__ . '/model/AuditTrail.php';
require_once __DIR__ . '/model/MailPasswordStore.php';
require_once __DIR__ . '/services/UrgentDisbursementService.php';
require_once __DIR__ . '/services/PasskeyPolicyService.php';
require_once __DIR__ . '/services/AuthDeviceSessionService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!\Auth::isLoggedIn()) {
    // Détecter requêtes AJAX pour renvoyer JSON au lieu de redirection HTML
    $isAjax = (
        // X-Requested-With header (XMLHttpRequest classique)
        (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        // Content-Type JSON
        || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
        // Accept JSON
        || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
        // Fetch API (détection par absence de certains headers typiques des navigateurs)
        || (empty($_SERVER['HTTP_UPGRADE_INSECURE_REQUESTS']) && !empty($_SERVER['HTTP_ACCEPT']))
        // Script dans /request/ généralement appelé en AJAX
        || (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/request/') !== false)
    );

    if ($isAjax) {
        \AuditTrail::log('auth_required', [
            'module' => 'security',
            'action' => 'auth_required',
            'result' => 'denied',
            'severity' => 'warning',
            'details' => [
                'script' => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
                'reason' => 'session_expired_or_missing',
            ],
        ]);
        header('Content-Type: application/json; charset=utf-8');
        // Utiliser 200 au lieu de 401 pour éviter que le navigateur suive des redirections automatiques
        http_response_code(200);
        echo json_encode([
            'ok' => false,
            'error' => 'Session expirée',
            'redirect' => \AccessManager::getApplicationBasePath() . '/login.php',
            'auth_required' => true,
        ]);
        exit();
    }
    \AuditTrail::log('auth_required', [
        'module' => 'security',
        'action' => 'auth_required',
        'result' => 'denied',
        'severity' => 'warning',
        'details' => [
            'script' => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
            'reason' => 'session_expired_or_missing',
        ],
    ]);
    header('Location: ' . \AccessManager::getApplicationBasePath() . '/login.php');
    exit();
}

// Toute activation Passkey-only et toute révocation de l’appareil ayant ouvert
// la session prennent effet à la requête suivante, sur toutes les pages.
try {
    $authenticatedUserId=(int)($_SESSION['user_id']??0);
    $authPolicyService=new \PasskeyPolicyService(\Database::getConnection());
    $authPolicy=$authPolicyService->get($authenticatedUserId);
    $sessionInvalid=(int)($_SESSION['auth_policy_version']??0)!==(int)($authPolicy['session_version']??0);
    $deviceSessionToken=(string)($_SESSION['device_session_token']??'');
    $trackedSessionInvalid=$deviceSessionToken!==''&&!(new \AuthDeviceSessionService(\Database::getConnection()))->validate($authenticatedUserId,$deviceSessionToken);
    $passwordForbidden=!empty($authPolicy['passkey_only'])&&$authPolicyService->hasPasskey($authenticatedUserId)&&($_SESSION['auth_method']??'password')!=='passkey';
    $revokedDevice=false;
    if(($_SESSION['auth_method']??'')==='passkey'&&!empty($_SESSION['passkey_id'])){
        $credentialCheck=\Database::getConnection()->prepare('SELECT COUNT(*) FROM user_passkeys WHERE id=:id AND user_id=:user_id AND revoked_at IS NULL');
        $credentialCheck->execute(['id'=>(int)$_SESSION['passkey_id'],'user_id'=>$authenticatedUserId]);
        $revokedDevice=(int)$credentialCheck->fetchColumn()!==1;
    }
    if($sessionInvalid||$passwordForbidden||$revokedDevice||$trackedSessionInvalid){
        $_SESSION=[];
        if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);}
        session_destroy();
        $expectsJson=str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')||str_contains((string)($_SERVER['CONTENT_TYPE']??''),'application/json')||str_contains((string)($_SERVER['SCRIPT_NAME']??''),'/request/');
        if($expectsJson){header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>false,'auth_required'=>true,'error'=>'Votre session a été déconnectée après une modification de sécurité.','redirect'=>\AccessManager::getApplicationBasePath().'/login.php']);exit;}
        header('Location: '.\AccessManager::getApplicationBasePath().'/login.php?security_updated=1');exit;
    }
    if($deviceSessionToken===''){
        $_SESSION['device_session_token']=(new \AuthDeviceSessionService(\Database::getConnection()))->register($authenticatedUserId,(string)($_SESSION['auth_method']??'password'));
    }
} catch (Throwable $e) {
    error_log('[passkey_session_policy] '.$e->getMessage());
}

// Accès initial exceptionnel : un compte Passkey-only sans appareil ne peut
// consulter que son profil de sécurité, les endpoints Passkey et la déconnexion.
if (!empty($_SESSION['passkey_setup_required'])) {
    $setupScript = str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??''));
    $setupAllowed = str_ends_with($setupScript,'/decaissement/profil.php')
        || str_contains($setupScript,'/request/passkey_')
        || str_ends_with($setupScript,'/deconnex.php');
    if (!$setupAllowed) {
        header('Location: '.\AccessManager::getApplicationBasePath().'/decaissement/profil.php#passkey-security');
        exit();
    }
}

// Migration applicative automatique des signatures existantes après déploiement.
// La version en session garantit une exécution unique par session, sans commande SQL ni action serveur.
$profileSignatureMigrationVersion = 'profile_signature_secure_v1';
if (($_SESSION['profile_signature_migration_version'] ?? '') !== $profileSignatureMigrationVersion) {
    try {
        require_once __DIR__ . '/services/DailyDisbursementPointService.php';
        new \DailyDisbursementPointService(\Database::getConnection());
        $_SESSION['profile_signature_migration_version'] = $profileSignatureMigrationVersion;
    } catch (Throwable $e) {
        error_log('[profile_signature_migration] ' . $e->getMessage());
    }
}

\MailPasswordStore::hydrateSessionFromDb((int) ($_SESSION['user_id'] ?? 0));

try {
    $pdoExitCheck = \Database::getConnection();
    $stmtExitCheck = $pdoExitCheck->prepare("
        SELECT p.date_sortie
        FROM user_devis_banamur u
        INNER JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
        WHERE u.id = :user_id
            AND p.date_sortie IS NOT NULL
            AND p.date_sortie <> '0000-00-00'
        LIMIT 1
    ");
    $stmtExitCheck->execute(['user_id' => (int) ($_SESSION['user_id'] ?? 0)]);
    if ($stmtExitCheck->fetchColumn()) {
        \AuditTrail::log('auth_blocked_personnel_exited', [
            'module' => 'security',
            'action' => 'personnel_exited_session_revoked',
            'result' => 'denied',
            'severity' => 'warning',
            'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        ]);
        session_unset();
        session_destroy();
        header('Location: ' . \AccessManager::getApplicationBasePath() . '/login.php?sortie=1');
        exit();
    }
} catch (Throwable) {
    // En cas d'indisponibilite ponctuelle, on laisse le controle classique continuer.
}

// Une signature de profil est désormais un prérequis documentaire global.
// Le contrôle est automatique après déploiement et ne concerne que les comptes
// qui n'ont encore aucune signature exploitable.
$signatureRequirementVersion = 'mandatory_profile_signature_v1';
$currentScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$signatureRequirementExempt = str_ends_with($currentScript, '/signature_setup.php')
    || str_ends_with($currentScript, '/deconnex.php')
    || str_ends_with($currentScript, '/decaissement/request/save_profile_signature.php');
if (!$signatureRequirementExempt && ($_SESSION['signature_requirement_checked'] ?? '') !== $signatureRequirementVersion) {
    try {
        $signatureCheck = \Database::getConnection()->prepare('SELECT signature,signature_data FROM user_devis_banamur WHERE id=:id LIMIT 1');
        $signatureCheck->execute(['id' => (int)($_SESSION['user_id'] ?? 0)]);
        $signatureProfile = $signatureCheck->fetch(PDO::FETCH_ASSOC) ?: [];
        $hasProfileSignature = trim((string)($signatureProfile['signature'] ?? '')) !== ''
            || preg_match('#^data:image/png;base64,#', trim((string)($signatureProfile['signature_data'] ?? ''))) === 1;
        if ($hasProfileSignature) {
            $_SESSION['signature_requirement_checked'] = $signatureRequirementVersion;
        } else {
            $_SESSION['signature_setup_return'] = $_SERVER['REQUEST_URI'] ?? (\AccessManager::getApplicationBasePath() . '/selection_portail.php');
            header('Location: ' . \AccessManager::getApplicationBasePath() . '/signature_setup.php');
            exit();
        }
    } catch (Throwable $e) {
        error_log('[mandatory_profile_signature] ' . $e->getMessage());
    }
}

// Charger rôle utilisateur une seule fois
if (!isset($_SESSION['role_raw']) || !isset($_SESSION['role_norm'])) {
    try {
        $pdo = \Database::getConnection();
        $stmt = $pdo->prepare("SELECT COALESCE(LOWER(TRIM(r.lib_role_devis)), '') AS lib FROM user_devis_banamur u LEFT JOIN role_devis_banamur r ON u.role_id = r.id_role_devis WHERE u.id = :id LIMIT 1");
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $lib = (string)$stmt->fetchColumn();
        $_SESSION['role_raw'] = $lib ?: '';
        // Normalisation (regroupe les rôles techniques sous 'technicien deviseur')
        $norm = $lib;
        if (in_array($lib, ['commercial', 'directeur technique', 'informaticien'], true)) {
            $norm = 'technicien deviseur';
        }
        if ($norm === '') {
            $norm = 'technicien deviseur'; // défaut si rôle manquant
        }
        $_SESSION['role_norm'] = $norm;
        // Charger permissions utilisateur & pages autorisées si pas déjà fait
        if (!isset($_SESSION['allowed_pages'])) {
            $pa = new \PageAccess($pdo);
            $pages = $pa->getUserPages((int)$_SESSION['user_id']);
            if (!$pages) { // si aucune entrée, appliquer défaut du rôle
                $pages = \PageAccess::defaultPagesForRole($norm);
            }
            $_SESSION['allowed_pages'] = array_unique($pages);
        }
        // Charger permissions dans un objet en session
        if (
            !isset($_SESSION['permissions'])
            || !is_array($_SESSION['permissions'])
            || !array_key_exists('acces_rh', $_SESSION['permissions'])
            || !array_key_exists('acces_logistique', $_SESSION['permissions'])
            || !array_key_exists('acces_hse', $_SESSION['permissions'])
            || !array_key_exists('acces_direction', $_SESSION['permissions'])
            || !array_key_exists('acces_planning', $_SESSION['permissions'])
            || !array_key_exists('acces_emploi_du_temps', $_SESSION['permissions'])
            || !array_key_exists('acces_dashboard_stat_taches', $_SESSION['permissions'])
            || !array_key_exists('acces_mail', $_SESSION['permissions'])
            || !array_key_exists('acces_formation', $_SESSION['permissions'])
            || !array_key_exists('acces_assistance_distance', $_SESSION['permissions'])
            || !array_key_exists('acces_parc_informatique', $_SESSION['permissions'])
            || !array_key_exists('acces_feb', $_SESSION['permissions'])
            || !array_key_exists('acces_depenses_planifiees', $_SESSION['permissions'])
            || !array_key_exists('acces_tresorerie', $_SESSION['permissions'])
            || !array_key_exists('acces_crm', $_SESSION['permissions'])
            || !array_key_exists('acces_veille_ao', $_SESSION['permissions'])
            || !array_key_exists('acces_crm_dashboard', $_SESSION['permissions'])
            || !array_key_exists('acces_crm_prospects', $_SESSION['permissions'])
            || !array_key_exists('acces_crm_clients', $_SESSION['permissions'])
            || !array_key_exists('acces_crm_opportunites', $_SESSION['permissions'])
            || !array_key_exists('acces_crm_rdv', $_SESSION['permissions'])
            || !array_key_exists('formation_admin', $_SESSION['permissions'])
        ) {
            $_SESSION['permissions'] = \AccessManager::loadUserPermissions((int)$_SESSION['user_id']);

            $_SESSION['can_modify_debourse'] = !empty($_SESSION['permissions']['modifier_debourse']);
        }
    } catch (Throwable $e) {
        // En cas d'erreur BD on restreint par défaut
        $_SESSION['role_raw'] = '';
        $_SESSION['role_norm'] = 'technicien deviseur';
        $_SESSION['allowed_pages'] = \PageAccess::defaultPagesForRole('technicien deviseur');
        $_SESSION['permissions'] = [];
        $_SESSION['can_modify_debourse'] = false;
    }
}

try {
    $ensureAclSchema = empty($_SESSION['acl_schema_checked']);
    $_SESSION['permissions'] = \AccessManager::loadUserPermissions((int) $_SESSION['user_id'], $ensureAclSchema);
    $_SESSION['acl_schema_checked'] = 1;
    $_SESSION['can_modify_debourse'] = !empty($_SESSION['permissions']['modifier_debourse']);
} catch (Throwable $e) {
    // Conserver les permissions déjà en session si la base est momentanément indisponible.
}

// Blocage fonctionnel de régularisation : le compte reste connecté afin que
// le bénéficiaire puisse transmettre les pièces qui lèvent automatiquement le blocage.
try {
    $regularizationGatePdo = \Database::getConnection();
    $regularizationGateStmt = $regularizationGatePdo->prepare("SELECT num_fiche,access_block_reason,access_blocked_at
        FROM disbursement_regularizations
        WHERE beneficiary_user_id=:uid AND access_blocked_at IS NOT NULL AND submitted_at IS NULL
        ORDER BY access_blocked_at ASC LIMIT 1");
    $regularizationGateStmt->execute(['uid'=>(int)($_SESSION['user_id'] ?? 0)]);
    $activeRegularizationBlock = $regularizationGateStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($activeRegularizationBlock) {
        $blockedNum = trim((string)$activeRegularizationBlock['num_fiche']);
        $currentBase = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $requestedNum = trim((string)($_REQUEST['num_fiche'] ?? ''));
        $allowedBlockedPages = [
            'regularisation_decaissement.php',
            'export_disbursement_reception_pv.php',
            'view_disbursement_payment_proof.php',
            'deconnex.php',
        ];
        $onAllowedPage = in_array($currentBase, $allowedBlockedPages, true)
            && ($currentBase === 'deconnex.php' || $requestedNum === $blockedNum);
        if (!$onAllowedPage) {
            header('Location: ' . \AccessManager::getApplicationBasePath()
                . '/decaissement/regularisation_decaissement.php?num_fiche=' . rawurlencode($blockedNum)
                . '&access_limited=1');
            exit();
        }
    }
} catch (Throwable $e) {
    // Une indisponibilité de la table de régularisation ne doit pas bloquer toute l'application.
}

$role = $_SESSION['role_norm'];
$script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$base = basename($script);
$isDecaissement = strpos($script, '/decaissement/') !== false;

$detectAuditModule = static function (string $scriptPath, bool $isDecaissementRequest): string {
    if ($isDecaissementRequest) {
        return 'decaissement';
    }

    $segments = array_values(array_filter(explode('/', trim($scriptPath, '/'))));
    $knownModules = ['admin', 'api', 'crm', 'decaissement', 'devis', 'debourse', 'clients', 'offres', 'unites', 'chantiers', 'documentation', 'emploi_du_temps', 'employe', 'facturation', 'formation', 'fournisseur', 'rh', 'request', 'tools'];
    foreach ($segments as $segment) {
        if (in_array($segment, $knownModules, true)) {
            return $segment;
        }
    }

    return 'core';
};

$auditModule = $detectAuditModule($script, $isDecaissement);

// Pages toujours autorisées (après login) – portail, logout, page d'accès refusé
$publicAfterLogin = ['selection_portail.php', 'moteur.php', 'deconnex.php', 'acces_refuse.php'];

$isAjaxRequest = (
    (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (!empty($_SERVER['CONTENT_TYPE']) && strpos((string)$_SERVER['CONTENT_TYPE'], 'application/json') !== false)
    || (!empty($_SERVER['HTTP_ACCEPT']) && strpos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/request/') !== false)
    || (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false)
);

if ($base !== 'deconnex.php') {
    try {
        $urgentService = new \UrgentDisbursementService(\Database::getConnection());
        $redirectUrl = $urgentService->forcedRegularizationRedirectForUser(
            (int)($_SESSION['user_id'] ?? 0),
            \AccessManager::getApplicationBasePath()
        );

        if ($redirectUrl !== '') {
            $targetPath = parse_url($redirectUrl, PHP_URL_PATH) ?: '';
            $targetQuery = [];
            parse_str((string)(parse_url($redirectUrl, PHP_URL_QUERY) ?: ''), $targetQuery);
            $regularizationPages = [
                'nouvelle_demande_employe.php',
                'details_demande_employe.php',
                'formulaire_demande_decaissement.php',
                'export_feb_pdf.php',
            ];
            $isOnRegularizationPage = in_array($base, $regularizationPages, true);
            $targetBase = basename($targetPath);
            $isAlreadyOnTarget = $isOnRegularizationPage && $targetBase === $base;
            if ($isAlreadyOnTarget && $base === 'nouvelle_demande_employe.php') {
                $isAlreadyOnTarget = (int)($_GET['urgent_id'] ?? 0) === (int)($targetQuery['urgent_id'] ?? 0)
                    && strtolower(trim((string)($_GET['type'] ?? ''))) === 'feb';
            } elseif ($isAlreadyOnTarget && $base === 'details_demande_employe.php') {
                $isAlreadyOnTarget = (int)($_GET['id'] ?? 0) === (int)($targetQuery['id'] ?? 0);
            } elseif ($isAlreadyOnTarget && $base === 'formulaire_demande_decaissement.php') {
                $isAlreadyOnTarget = trim((string)($_GET['code_autorisation_feb'] ?? '')) === trim((string)($targetQuery['code_autorisation_feb'] ?? ''))
                    && (int)($_GET['urgent_id'] ?? 0) === (int)($targetQuery['urgent_id'] ?? 0);
            }

            if (!$isAlreadyOnTarget) {
                \AuditTrail::log('urgent_disbursement_regularization_redirect', [
                    'module' => 'decaissement',
                    'action' => 'urgent_regularization_forced_redirect',
                    'entity_type' => 'user',
                    'entity_id' => (string)((int)($_SESSION['user_id'] ?? 0)),
                    'entity_label' => $base,
                    'result' => 'redirected',
                    'severity' => 'warning',
                    'details' => [
                        'script' => $script,
                        'target' => $redirectUrl,
                    ],
                ]);

                if ($isAjaxRequest) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(200);
                    echo json_encode([
                        'ok' => false,
                        'error' => 'Regularisation urgente requise',
                        'redirect' => $redirectUrl,
                        'urgent_regularization_required' => true,
                    ]);
                    exit();
                }

                header('Location: ' . $redirectUrl);
                exit();
            }
        }
    } catch (Throwable $e) {
        // Si la verification ponctuelle echoue, on laisse le controle d'acces standard continuer.
    }
}

// Vérification d'accès granulaire
if (!in_array($base, $publicAfterLogin, true)) {
    // Utiliser AccessManager pour vérifier les droits
    if (!\AccessManager::hasPageAccess($base)) {
        // Détecter requête AJAX pour retour JSON approprié
        $isAjax = (
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
            || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
            || (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/request/') !== false)
        );

        $permission = \AccessManager::getPrimaryPermissionForPage($base);
        \AccessManager::denyAccess($permission, $base, $isAjax);
    }
}

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($isAjaxRequest) {
    \AuditTrail::log('request_access', [
        'module' => $auditModule,
        'action' => 'request_access',
        'entity_type' => 'page',
        'entity_label' => $base,
        'result' => 'success',
        'details' => [
            'request_method' => $requestMethod,
            'is_ajax' => true,
            'role' => (string)($_SESSION['role_norm'] ?? ''),
            'script' => $script,
        ],
    ]);
} else {
    \AuditTrail::logPageConsultation([
        'module' => $auditModule,
        'action' => 'page_view',
        'entity_type' => 'page',
        'entity_label' => $base,
        'request_path' => $script,
        'page_name' => $base,
        'details' => [
            'request_method' => $requestMethod,
            'is_ajax' => false,
            'role' => (string)($_SESSION['role_norm'] ?? ''),
            'script' => $script,
        ],
    ]);
}

// Optionnel: fonction utilitaire pour autres scripts
if (!function_exists('currentUserRole')) {
    function currentUserRole()
    {
        return $_SESSION['role_norm'] ?? 'technicien deviseur';
    }
}
if (!function_exists('canModifyDebourse')) {
    function canModifyDebourse()
    {
        if (isset($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
            return !empty($_SESSION['permissions']['modifier_debourse']);
        }
        return !empty($_SESSION['can_modify_debourse']);
    }
}

// Helper générique: vérifie un droit booléen dans $_SESSION['permissions']
if (!function_exists('hasPermission')) {
    function hasPermission(string $key): bool
    {
        return !empty($_SESSION['permissions'][$key] ?? 0);
    }
}
