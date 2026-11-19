<?php
require_once 'model/Database.php';
require_once 'model/AuditTrail.php';
require_once 'services/AuthDeviceSessionService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

AuditTrail::log('logout', [
    'module' => 'security',
    'action' => 'logout',
    'entity_type' => 'session',
    'entity_label' => 'erp_logout',
    'result' => 'success',
    'details' => ['reason' => 'user_requested_logout'],
]);

try {
    if(!empty($_SESSION['user_id'])&&!empty($_SESSION['device_session_token'])){
        $deviceSessions=new AuthDeviceSessionService(Database::getConnection());
        $deviceSessionId=$deviceSessions->idForToken((int)$_SESSION['user_id'],(string)$_SESSION['device_session_token']);
        if($deviceSessionId>0)$deviceSessions->revoke($deviceSessionId,(int)$_SESSION['user_id']);
    }
} catch(Throwable $e){error_log('[device_session_logout] '.$e->getMessage());}

// Vider toutes les variables de session
$_SESSION = [];

// Supprimer le cookie de session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Détruire la session
session_destroy();

// Redirection vers login
header("Location: login.php");
exit;
