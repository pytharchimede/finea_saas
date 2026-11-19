<?php
require_once __DIR__ . '/passkey_bootstrap.php';
passkeyRequirePost();
$options = $passkeyService->authenticationOptions();
$_SESSION['webauthn_challenge'] = ['purpose'=>'login','options'=>$passkeyService->optionsJson($options),'expires_at'=>time()+$passkeyConfig['challenge_ttl']];
echo $passkeyService->optionsJson($options);
