<?php
require_once __DIR__ . '/passkey_bootstrap.php';
require_once __DIR__ . '/../auth_check.php';
passkeyRequirePost();
try {
    if (empty($_SESSION['user_id'])) passkeyJson(['status'=>'error','message'=>'Authentification requise.'], 401);
    $entry = passkeyConsumeChallenge('register');
    if ((int)$entry['user_id'] !== (int)$_SESSION['user_id']) throw new RuntimeException('Session invalide.');

    $userId = (int) $_SESSION['user_id'];
    if (!empty($entry['authorization_required'])) {
        $authorization = $_SESSION['passkey_enrollment_authorization'] ?? null;
        $validAuthorization = is_array($authorization)
            && (int) ($authorization['user_id'] ?? 0) === $userId
            && (int) ($authorization['expires_at'] ?? 0) >= time()
            && !empty($authorization['nonce'])
            && !empty($entry['authorization_nonce'])
            && hash_equals((string) $authorization['nonce'], (string) $entry['authorization_nonce']);
        if (!$validAuthorization) {
            unset($_SESSION['passkey_enrollment_authorization']);
            throw new RuntimeException('Autorisation d’enrôlement expirée ou invalide.');
        }
    }

    $payload = json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals((string)($_SESSION['passkey_csrf'] ?? ''), (string)($payload['csrf_token'] ?? ''))) throw new RuntimeException('Session expirée.');
    $credential = $passkeyService->parseCredential(json_encode($payload['credential'], JSON_THROW_ON_ERROR));
    if ($passkeyRepository->findActiveByCredentialId($credential->rawId)) throw new RuntimeException('Cette Passkey est déjà enregistrée.');
    $record = $passkeyService->verifyRegistration($credential, $passkeyService->restoreRegistrationOptions($entry['options']));
    $passkeyRepository->save($userId, $record, (string)($payload['device_name'] ?? 'Passkey'));

    // La preuve de possession ayant servi à autoriser l'ajout est à usage unique.
    unset($_SESSION['passkey_enrollment_authorization']);

    $logoutRequired = !empty($_SESSION['passkey_setup_required']);
    unset($_SESSION['passkey_setup_required']);
    AuditTrail::log('PASSKEY_REGISTERED', ['module'=>'security','action'=>'passkey_registered','user_id'=>$userId,'entity_type'=>'passkey','result'=>'success','details'=>['device_name'=>(string)($payload['device_name'] ?? 'Passkey'),'step_up_verified'=>!empty($entry['authorization_required'])]]);
    if ($logoutRequired) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        passkeyJson(['status'=>'success','message'=>'Passkey enregistrée. Reconnectez-vous maintenant avec celle-ci.','logout_required'=>true,'redirect'=>'../login.php']);
    }
    passkeyJson(['status'=>'success','message'=>'Nouvelle Passkey enregistrée en toute sécurité.']);
} catch (Throwable $e) {
    error_log('[passkey_register] '.$e->getMessage());
    passkeyJson(['status'=>'error','message'=>'Impossible de vérifier cette Passkey. Veuillez recommencer.'], 400);
}
