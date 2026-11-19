<?php if(basename((string)($_SERVER['SCRIPT_NAME']??''))==='fiches_poste_rh.php'):?><script src="assets/fiches_poste.js?v=<?=filemtime(__DIR__.'/../../rh/assets/fiches_poste.js')?>"></script><?php endif;?>
<?php if(basename((string)($_SERVER['SCRIPT_NAME']??''))==='paie_rh.php'):?><script src="assets/payroll-contract-readonly.js?v=<?=filemtime(__DIR__.'/../../rh/assets/payroll-contract-readonly.js')?>"></script><?php endif;?>
<?php if(basename((string)($_SERVER['SCRIPT_NAME']??''))==='contrats_employes_rh.php'):?><script src="assets/employee-contract-default-clauses.js?v=<?=filemtime(__DIR__.'/../../rh/assets/employee-contract-default-clauses.js')?>"></script><?php endif;?>
<?php
if (!function_exists('resolveLayoutRelativeRoot')) {
    function resolveLayoutRelativeRoot(): string
    {
        static $cached = null;
        if ($cached !== null) return $cached;
        $projectRoot = realpath(dirname(__DIR__, 2));
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (!$projectRoot || !$scriptFilename) return $cached = '../';
        $scriptDirectory = realpath(dirname($scriptFilename));
        if (!$scriptDirectory) return $cached = '../';
        $projectRoot = str_replace('\\', '/', $projectRoot);
        $scriptDirectory = str_replace('\\', '/', $scriptDirectory);
        if (strpos($scriptDirectory, $projectRoot) !== 0) return $cached = '../';
        $relativeDirectory = trim(substr($scriptDirectory, strlen($projectRoot)), '/');
        if ($relativeDirectory === '') return $cached = '';
        $depth = substr_count($relativeDirectory, '/') + 1;
        return $cached = str_repeat('../', $depth);
    }
}
if (!function_exists('parsePhpIniSizeToBytes')) {
    function parsePhpIniSizeToBytes(string $value): int
    {
        $value = trim($value); if ($value === '') return 0;
        $unit = strtolower(substr($value, -1)); $number = (float) $value;
        switch ($unit) {
            case 'g': return (int) ($number * 1024 * 1024 * 1024);
            case 'm': return (int) ($number * 1024 * 1024);
            case 'k': return (int) ($number * 1024);
            default: return (int) $number;
        }
    }
}
$layoutRootBase = $layoutRootBase ?? resolveLayoutRelativeRoot();
$layoutModuleBase = $layoutModuleBase ?? $layoutRootBase . 'decaissement/';
$layoutAssetBase = $layoutAssetBase ?? $layoutModuleBase . 'assets/';
$layoutIsPublicAuthPage = $layoutIsPublicAuthPage ?? in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['login.php', 'reset_password.php'], true);
if ($layoutIsPublicAuthPage) {
?>
<script>window.FINEA_PASSKEY_ENDPOINT_BASE=<?= json_encode($layoutRootBase.'request', JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>js/passkeys.js?v=<?= filemtime(__DIR__.'/../../js/passkeys.js') ?>"></script>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/modern-form-controls.css?v=<?= filemtime(__DIR__.'/../../assets/css/modern-form-controls.css') ?>">
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/modern-form-controls.js?v=<?= filemtime(__DIR__.'/../../assets/js/modern-form-controls.js') ?>"></script>
</body>
</html>
<?php
    return;
}

$chatUploadMaxBytes = parsePhpIniSizeToBytes((string) ini_get('upload_max_filesize'));
$chatPostMaxBytes = parsePhpIniSizeToBytes((string) ini_get('post_max_size'));
require_once dirname(__DIR__, 2) . '/model/RemoteAssistanceSettings.php';
$remoteAssistanceSettingsModel = new \RemoteAssistanceSettings();
$remoteAssistanceSettings = $remoteAssistanceSettingsModel->load();
$globalChatConfig = [
    'enabled' => session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']),
    'rootBase' => $layoutRootBase,
    'bootstrapUrl' => $layoutRootBase . 'api/employee_chat_bootstrap.php',
    'messagesUrl' => $layoutRootBase . 'api/employee_chat_messages.php',
    'sendUrl' => $layoutRootBase . 'api/employee_chat_send.php',
    'createGroupUrl' => $layoutRootBase . 'api/employee_chat_group_create.php',
    'groupMembersUrl' => $layoutRootBase . 'api/employee_chat_group_members.php',
    'typingUrl' => $layoutRootBase . 'api/employee_chat_typing.php',
    'downloadUrl' => $layoutRootBase . 'api/employee_chat_download.php',
    'uploadMaxBytes' => $chatUploadMaxBytes,
    'postMaxBytes' => $chatPostMaxBytes,
    'uploadMaxLabel' => (string) ini_get('upload_max_filesize'),
    'postMaxLabel' => (string) ini_get('post_max_size'),
];
$globalRemoteAssistanceConfig = [
    'enabled' => session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['user_id']) && (!empty($_SESSION['acces_assistance_distance']) || !empty($_SESSION['acces_admin'])),
    'domain' => (string) ($remoteAssistanceSettings['jitsi_domain'] ?? 'meet.jit.si'),
    'roomPrefix' => (string) ($remoteAssistanceSettings['room_prefix'] ?? 'FINAEA'),
    'displayName' => trim((string) ($_SESSION['user_name'] ?? trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''))))),
    'scriptUrl' => $remoteAssistanceSettingsModel->getPublicScriptUrl((string) ($remoteAssistanceSettings['jitsi_domain'] ?? 'meet.jit.si')),
    'startAudioMuted' => !empty($remoteAssistanceSettings['start_audio_muted']),
    'startVideoMuted' => !empty($remoteAssistanceSettings['start_video_muted']),
];
require_once dirname(__DIR__, 2) . '/services/ChantierPauseService.php';
$chantierPauseNotices = !empty($_SESSION['user_id']) ? (new ChantierPauseService(\Database::getConnection()))->recentNotices() : [];
?>
<?php if ($chantierPauseNotices): ?>
<script>
(() => {
  const notices = <?= json_encode($chantierPauseNotices, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const pending = notices.filter(n => { try { return !localStorage.getItem('finea-pause-notice-' + n.id + '-' + n.paused_at); } catch (_) { return true; } });
  if (!pending.length) return;
  const overlay = document.createElement('div');
  overlay.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.65);display:flex;align-items:center;justify-content:center;padding:20px';
  const panel = document.createElement('div');
  panel.style.cssText = 'width:min(100%,520px);max-height:85vh;overflow:auto;background:white;border-radius:22px;padding:28px;box-shadow:0 25px 80px rgba(0,0,0,.25);font-family:inherit';
  const title = document.createElement('h2'); title.textContent = 'Chantiers en pause'; title.style.cssText = 'font-size:22px;font-weight:800;color:#172554;margin:0 0 12px'; panel.append(title);
  const info = document.createElement('p'); info.textContent = 'Les sorties et demandes opérationnelles de ces chantiers sont temporairement suspendues. Les factures à encaisser restent disponibles.'; info.style.cssText = 'color:#475569;margin-bottom:16px'; panel.append(info);
  const list = document.createElement('ul'); list.style.cssText = 'padding:0;list-style:none;display:grid;gap:8px;margin:0 0 20px';
  notices.forEach(n => { const item = document.createElement('li'); item.textContent = (n.code ? n.code + ' · ' : '') + n.titre + (n.resume_at ? ' — réactivation le ' + new Date(n.resume_at.replace(' ', 'T')).toLocaleDateString('fr-FR') : ' — réactivation manuelle'); item.style.cssText='background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px;color:#92400e'; list.append(item); }); panel.append(list);
  const button = document.createElement('button'); button.type='button'; button.textContent="J'ai compris"; button.style.cssText='width:100%;background:#4f46e5;color:white;border:0;border-radius:10px;padding:13px;font-weight:700;cursor:pointer'; button.onclick=()=>{ notices.forEach(n=>{try{localStorage.setItem('finea-pause-notice-'+n.id+'-'+n.paused_at,'1')}catch(_){}});overlay.remove() }; panel.append(button); overlay.append(panel); document.body.append(overlay);
})();
</script>
<?php endif; ?>
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/finea-popup-notifications.js?v=<?= time() ?>"></script>
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/ticket-reminder.js?v=<?= time() ?>"></script>
<script>window.BanamurAppConfig = Object.assign({}, window.BanamurAppConfig || {}, {employeeChat: <?= json_encode($globalChatConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>, remoteAssistance: <?= json_encode($globalRemoteAssistanceConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>});</script>
<script src="<?= htmlspecialchars($layoutAssetBase, ENT_QUOTES, 'UTF-8') ?>js/app.js"></script>
<script>window.FINEA_PASSKEY_ENDPOINT_BASE=<?= json_encode($layoutRootBase.'request', JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>js/passkeys.js?v=<?= filemtime(__DIR__.'/../../js/passkeys.js') ?>"></script>
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>js/passkey-secure-enrollment.js?v=<?= filemtime(__DIR__.'/../../js/passkey-secure-enrollment.js') ?>"></script>
<?php if(!empty($_SESSION['user_id'])): ?><script>(()=>{const url=<?=json_encode($layoutRootBase.'request/auth_session_status.php',JSON_UNESCAPED_SLASHES)?>;let checking=false;const check=async()=>{if(checking)return;checking=true;try{const response=await fetch(url,{headers:{Accept:'application/json'},cache:'no-store'}),data=await response.json();if(!data.ok&&data.auth_required)location.replace(data.redirect||<?=json_encode($layoutRootBase.'login.php',JSON_UNESCAPED_SLASHES)?>);}catch(error){}finally{checking=false;}};setInterval(check,15000);document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')check()});})();</script><?php endif; ?>
<?php if (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/contrats/')): ?><link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>contrats/assets/branding.css?v=<?= time() ?>"><?php endif; ?>
<?php if (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/contrats/reception.php')): ?><script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>contrats/assets/signer-inject.js?v=<?= time() ?>"></script><?php endif; ?>
<?php if (str_contains(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/contrats/creer.php')): ?><script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>contrats/assets/contract-form.js?v=<?= time() ?>"></script><?php endif; ?>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/modern-form-controls.css?v=<?= filemtime(__DIR__.'/../../assets/css/modern-form-controls.css') ?>">
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/modern-form-controls.js?v=<?= filemtime(__DIR__.'/../../assets/js/modern-form-controls.js') ?>"></script>
<?php if (str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), 'fournisseur')): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/supplier-workspace.css?v=<?= filemtime(__DIR__.'/../../assets/css/supplier-workspace.css') ?>">
<script>document.body.classList.add('supplier-workspace');</script>
<?php if (empty($supplierFneScriptLoaded)): ?><script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/supplier-fne.js?v=<?= filemtime(__DIR__.'/../../assets/js/supplier-fne.js') ?>"></script><?php endif; ?>
<?php endif; ?>
<?php if (in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['validation_demandes_finances.php','validation_feb_detail.php','nouvelle_demande_employe.php','details_demande_employe.php'], true)): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/feb-workspace.css?v=<?= htmlspecialchars($febAssetVersion ?? (string) filemtime(__DIR__.'/../../assets/css/feb-workspace.css'), ENT_QUOTES, 'UTF-8') ?>">
<script>document.body.classList.add('feb-workspace');</script>
<?php endif; ?>
<?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'validation_demandes_finances.php'): ?>
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/feb-finance-list-tools.js?v=<?= filemtime(__DIR__.'/../../assets/js/feb-finance-list-tools.js') ?>"></script>
<?php endif; ?>
<?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'nouvelle_demande_employe.php' && ($type ?? $_GET['type'] ?? $_POST['type'] ?? '') === 'feb'): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/feb-form-experience.css?v=<?= htmlspecialchars($febAssetVersion ?? (string) filemtime(__DIR__.'/../../assets/css/feb-form-experience.css'), ENT_QUOTES, 'UTF-8') ?>">
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/feb-form-experience.js?v=<?= htmlspecialchars($febAssetVersion ?? (string) filemtime(__DIR__.'/../../assets/js/feb-form-experience.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/feb-ocr-availability.js?v=<?= filemtime(__DIR__.'/../../assets/js/feb-ocr-availability.js') ?>"></script>
<script src="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/js/feb-disbursement-polish.js?v=<?= filemtime(__DIR__.'/../../assets/js/feb-disbursement-polish.js') ?>"></script>
<?php endif; ?>
</body>
</html>
