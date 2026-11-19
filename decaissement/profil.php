<?php
require_once '../auth_check.php';
require_once '../model/Database.php';
require_once '../model/User.php';
require_once '../services/DailyDisbursementPointService.php';
require_once '../vendor/autoload.php';
require_once '../repositories/PasskeyRepository.php';
require_once '../repositories/MobilePasskeyLoginRepository.php';
require_once '../services/PasskeyService.php';
require_once '../services/PasskeyPolicyService.php';
require_once '../services/AuthDeviceSessionService.php';

$pdo = Database::getConnection();
$userModel = new User($pdo);

function profileEnsureStorage(PDO $pdo): void
{
    try {
        $columns = $pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('signature', $columns, true)) {
            $pdo->exec('ALTER TABLE user_devis_banamur ADD COLUMN signature VARCHAR(255) NULL AFTER photo');
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS user_notification_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                event_key VARCHAR(80) NOT NULL,
                channel_email TINYINT(1) NOT NULL DEFAULT 0,
                channel_system TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_event (user_id, event_key),
                KEY idx_user_notification_preferences_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('[profil] storage init failed: ' . $e->getMessage());
    }
}

function profileNotificationOptions(array $user): array
{
    $canFinance = !empty($user['acces_decaissement']) || !empty($user['acces_fiches_decaissement']) || !empty($user['acces_compta']) || !empty($user['acces_direction']);
    $canRh = !empty($user['acces_rh']) || !empty($user['acces_admin']);
    $canSupplier = !empty($user['acces_fournisseur']) || !empty($user['acces_facturation']) || !empty($user['acces_admin']);
    $canPlanning = !empty($user['acces_planning']) || !empty($user['acces_logistique']) || !empty($user['acces_hse']);
    $canLogistique = !empty($user['acces_logistique']) || !empty($user['acces_admin']);

    $options = [
        'employee_absences.absence_marked' => [
            'header' => 'Absences',
            'label' => 'Absence detectee',
            'description' => 'Email de justification envoye au salarie, avec copie RH uniquement si vous gardez cette ligne active.',
            'allowed' => $canRh,
            'default_email' => false,
        ],
        'employee_absences.absence_reminder_24h' => [
            'header' => 'Absences',
            'label' => 'Rappel justificatif apres 24 h',
            'description' => 'Rappel automatique quand aucun justificatif d absence n a ete soumis.',
            'allowed' => $canRh,
            'default_email' => false,
        ],
        'employee_absences.absence_threshold_3' => [
            'header' => 'Absences',
            'label' => 'Alerte RH seuil de 3 absences',
            'description' => 'Alerte envoyee aux RH volontaires quand le seuil mensuel est atteint.',
            'allowed' => $canRh,
            'default_email' => false,
        ],
        'legal_request_submitted.permission_absence' => [
            'header' => 'Demandes employes',
            'label' => 'Nouvelle permission d absence',
            'description' => 'Demande d absence ou permission transmise pour traitement.',
            'allowed' => $canRh,
        ],
        'legal_request_submitted.demande_explication' => [
            'header' => 'Demandes employes',
            'label' => 'Nouvelle demande d explication',
            'description' => 'Demande d explication ou suite disciplinaire a traiter.',
            'allowed' => $canRh,
        ],
        'legal_request_submitted.feb' => [
            'header' => 'FEB',
            'label' => 'Nouvelle FEB emise',
            'description' => 'Notification lors de la soumission d une nouvelle Fiche d Expression de Besoin.',
            'allowed' => $canFinance || !empty($user['acces_direction']) || !empty($user['acces_rh']),
            'default_email' => empty($user['acces_direction']),
            'default_system' => true,
        ],
        'legal_request_processed.approved' => [
            'header' => 'Mes demandes',
            'label' => 'Une de mes demandes est approuvee',
            'description' => 'Notification quand une demande que vous avez emise est validee.',
            'allowed' => true,
        ],
        'legal_request_processed.rejected' => [
            'header' => 'Mes demandes',
            'label' => 'Une de mes demandes est refusee',
            'description' => 'Notification quand une demande que vous avez emise est refusee.',
            'allowed' => true,
        ],
        'decaissement_submitted.default' => [
            'header' => 'Finance',
            'label' => 'Nouvelle fiche de decaissement',
            'description' => 'Fiche transmise aux equipes chargees du traitement.',
            'allowed' => $canFinance,
        ],
        'decaissement_submitted.epi' => [
            'header' => 'Finance',
            'label' => 'Nouvelle fiche EPI',
            'description' => 'Fiche EPI transmise pour validation ou suivi.',
            'allowed' => $canFinance || !empty($user['acces_hse']),
        ],
        'decaissement_processed.approved' => [
            'header' => 'Finance',
            'label' => 'Ma fiche est approuvee',
            'description' => 'Retour de validation sur une fiche que vous avez soumise.',
            'allowed' => true,
        ],
        'decaissement_processed.rejected' => [
            'header' => 'Finance',
            'label' => 'Ma fiche est refusee',
            'description' => 'Retour de refus sur une fiche que vous avez soumise.',
            'allowed' => true,
        ],
        'mailbox' => [
            'header' => 'Messagerie',
            'label' => 'Messages et documents affectes',
            'description' => 'Messages internes, taches de communication et documents partages.',
            'allowed' => !empty($user['acces_mail']),
        ],
        'planning_operations' => [
            'header' => 'Planning',
            'label' => 'Planning et operations',
            'description' => 'Taches, modifications de planning, logistique, HSE et operations terrain.',
            'allowed' => $canPlanning,
        ],
        'logistics_operations' => [
            'header' => 'Logistique',
            'label' => 'Operations logistiques',
            'description' => 'Demandes, validations, remises, recuperations, stock, parc, documents et incidents.',
            'allowed' => $canLogistique,
            'default_system' => true,
        ],
        'attendance_devices' => [
            'header' => 'Pointage',
            'label' => 'Supervision badgeuse',
            'description' => 'Alertes quand une badgeuse est deconnectee ou que son heure derive de GMT+0.',
            'allowed' => $canRh,
            'default_email' => true,
            'default_system' => true,
        ],
        'attendance_device_alert.api_unreachable' => [
            'header' => 'Pointage',
            'label' => 'API badgeuse inaccessible',
            'description' => 'Alerte quand FINEA ne parvient plus a joindre attendance.fidest.ci.',
            'allowed' => $canRh,
            'default_email' => true,
            'default_system' => true,
        ],
        'attendance_device_alert.offline' => [
            'header' => 'Pointage',
            'label' => 'Badgeuse deconnectee',
            'description' => 'Alerte quand une badgeuse ne communique plus dans le delai autorise.',
            'allowed' => $canRh,
            'default_email' => true,
            'default_system' => true,
        ],
        'attendance_device_alert.clock_drift' => [
            'header' => 'Pointage',
            'label' => 'Lecture directe horloge badgeuse',
            'description' => 'Alerte future uniquement si une lecture directe fiable de l horloge interne badgeuse est disponible.',
            'allowed' => $canRh,
            'default_email' => false,
            'default_system' => false,
        ],
        'attendance_device_alert.server_time_mismatch' => [
            'header' => 'Pointage',
            'label' => 'Decalage heure serveur/base',
            'description' => 'Alerte quand l heure PHP GMT+0 de l API pointage et l heure UTC MySQL presentent un ecart anormal.',
            'allowed' => $canRh,
            'default_email' => true,
            'default_system' => true,
        ],
        'rh_management' => [
            'header' => 'RH',
            'label' => 'Organisation RH',
            'description' => 'Affectation de responsables, dossiers employes et alertes RH generales.',
            'allowed' => $canRh,
        ],
        'supplier_portal' => [
            'header' => 'Fournisseurs',
            'label' => 'Portail fournisseurs',
            'description' => 'Demandes, factures et mouvements du portail fournisseur.',
            'allowed' => $canSupplier,
        ],

    ];

    return array_filter($options, static fn($item) => !empty($item['allowed']));
}

function profileFetchPreferences(PDO $pdo, int $userId): array
{
    try {
        $stmt = $pdo->prepare('SELECT event_key, channel_email, channel_system FROM user_notification_preferences WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $prefs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $prefs[(string) $row['event_key']] = [
                'email' => (int) ($row['channel_email'] ?? 0) === 1,
                'system' => (int) ($row['channel_system'] ?? 0) === 1,
            ];
        }
        return $prefs;
    } catch (Throwable $e) {
        return [];
    }
}

function profileFetchPersonnel(PDO $pdo, array $user): ?array
{
    try {
        $sql = "
            SELECT
                p.id_personnel_soignant AS id,
                p.matricule_personnel_soignant AS matricule,
                p.nom_personnel_soignant AS full_name,
                p.email_personnel_soignant AS email,
                p.date_recrutement,
                p.date_prise_service,
                s.lib_service AS service_label,
                f.lib_fonction AS fonction_label
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            WHERE p.id_personnel_soignant = :personnel_id
               OR LOWER(TRIM(p.email_personnel_soignant)) = LOWER(TRIM(:email))
            ORDER BY p.id_personnel_soignant DESC
            LIMIT 1
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'personnel_id' => (int) ($user['personnel_id'] ?? 0),
            'email' => (string) ($user['mail_pro'] ?? ''),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

profileEnsureStorage($pdo);

$user = $userModel->getUserById($_SESSION['user_id']);
if (!$user) {
    header('Location: ../deconnex.php');
    exit;
}

$passkeyService = new PasskeyService(require __DIR__ . '/../config/webauthn.php');
$passkeyRepository = new PasskeyRepository($pdo, $passkeyService->serializer());
$passkeys = $passkeyRepository->listForUser((int) $user['id']);
$passkeyPolicy = (new PasskeyPolicyService($pdo))->get((int) $user['id']);
$deviceSessions = (new AuthDeviceSessionService($pdo))->listForUser((int)$user['id']);
$currentDeviceSessionId = !empty($_SESSION['device_session_token']) ? (new AuthDeviceSessionService($pdo))->idForToken((int)$user['id'],(string)$_SESSION['device_session_token']) : 0;
$mobileScanners = (new MobilePasskeyLoginRepository($pdo))->listScannersForUser((int)$user['id']);
$_SESSION['passkey_csrf'] ??= bin2hex(random_bytes(32));

$personnel = profileFetchPersonnel($pdo, $user);
$notificationOptions = profileNotificationOptions($user);
$notificationPrefs = profileFetchPreferences($pdo, (int) $user['id']);
$dailyPointService=new DailyDisbursementPointService($pdo);
$dailyPointService->profileSignature((int)$user['id']);
$user = $userModel->getUserById((int)$user['id']) ?: $user;
if(trim((string)($user['signature_data']??''))===''){
    try{
        $snapshot=$pdo->prepare("SELECT signature_data FROM (SELECT drh_signature_data signature_data,drh_signed_at signed_at FROM rh_mission_orders WHERE drh_signed_by_user_id=:user1 AND COALESCE(drh_signature_data,'')<>'' UNION ALL SELECT employee_signature_data,employee_signed_at FROM rh_mission_orders WHERE employee_signed_by_user_id=:user2 AND COALESCE(employee_signature_data,'')<>'') signatures ORDER BY signed_at DESC LIMIT 1");
        $snapshot->execute(['user1'=>(int)$user['id'],'user2'=>(int)$user['id']]);$restored=trim((string)$snapshot->fetchColumn());
        if(preg_match('#^data:image/(?:png|jpeg);base64,#i',$restored)){$pdo->prepare('UPDATE user_devis_banamur SET signature_data=:signature WHERE id=:id')->execute(['signature'=>$restored,'id'=>(int)$user['id']]);$user=$userModel->getUserById((int)$user['id'])?:$user;}
    }catch(Throwable $e){}
}
$dailyPointEmailEnabled=$dailyPointService->emailEnabled((int)$user['id']);

$pageTitle = 'Profil utilisateur';
$activeMenu = '';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$avatarSrc = function_exists('resolveUserPhotoUrl')
    ? resolveUserPhotoUrl((string) ($user['photo'] ?? ''), (string) ($layoutRootBase ?? '../'))
    : (!empty($user['photo']) ? '../' . $user['photo'] : '../photo/ma_photo.jpg');
$signaturePath = trim((string) ($user['signature'] ?? ''));
$signatureData = trim((string) ($user['signature_data'] ?? ''));
$signatureSrc = preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,#i', $signatureData) ? $signatureData : '';
$signatureUnavailable = false;
if ($signatureSrc === '' && preg_match('#^data:image/(?:png|jpeg|jpg|webp);base64,#i', $signaturePath)) $signatureSrc = $signaturePath;
if ($signatureSrc === '' && $signaturePath !== '') {
    $projectRoot = realpath(dirname(__DIR__));
    $candidates = [dirname(__DIR__).'/'.ltrim($signaturePath,'/'),dirname(__DIR__).'/signatures/'.basename($signaturePath),dirname(__DIR__).'/uploads/user_signatures/'.basename($signaturePath)];
    foreach ($candidates as $candidate) {
        $resolved=realpath($candidate);$normalizedRoot=$projectRoot?rtrim(str_replace('\\','/',$projectRoot),'/').'/':'';$normalizedFile=$resolved?str_replace('\\','/',$resolved):'';
        if(!$resolved||!$projectRoot||!str_starts_with($normalizedFile,$normalizedRoot)||!is_file($resolved)||!is_readable($resolved)||filesize($resolved)>2*1024*1024)continue;
        $binary=@file_get_contents($resolved);$info=is_string($binary)?@getimagesizefromstring($binary):false;$mime=is_array($info)?(string)($info['mime']??''):'';
        if(!is_string($binary)||!in_array($mime,['image/png','image/jpeg','image/webp'],true))continue;
        $signatureSrc='data:'.$mime.';base64,'.base64_encode($binary);
        try{$pdo->prepare('UPDATE user_devis_banamur SET signature_data=:data WHERE id=:id')->execute(['data'=>$signatureSrc,'id'=>(int)$user['id']]);}catch(Throwable $e){}
        break;
    }
    $signatureUnavailable=$signatureSrc==='';
}
$displayName = trim((string) ($user['prenom'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$message = (string) ($_GET['message'] ?? '');
$permissionLabels = [];
foreach ($user as $key => $value) {
    if ((strpos((string) $key, 'acces_') === 0 || strpos((string) $key, 'modifier_') === 0) && (int) $value === 1) {
        $permissionLabels[] = ucfirst(str_replace('_', ' ', (string) $key));
    }
}
?>

<style>
    .signature-canvas {
        width: 100%;
        height: 180px;
        border: 1px dashed #cbd5e1;
        border-radius: 16px;
        background: #ffffff;
        touch-action: none;
    }

    .profile-switch input:checked+span {
        background: #4f46e5;
    }

    .profile-switch input:checked+span span {
        transform: translateX(22px);
    }
</style>

<main class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <?php if ($status !== ''): ?>
        <div class="mb-5 rounded-2xl border <?= $status === 'ok' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?> px-4 py-3 text-sm font-semibold">
            <?= htmlspecialchars($message !== '' ? $message : ($status === 'ok' ? 'Modification enregistree.' : 'Operation impossible.'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <section class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm">
        <div class="grid gap-6 border-b border-slate-100 p-5 md:grid-cols-[auto_1fr_auto] md:items-center md:p-7">
            <div class="relative h-28 w-28 overflow-hidden rounded-3xl border border-slate-200 bg-slate-100 shadow-sm">
                <img id="avatarPreview" src="<?= htmlspecialchars($avatarSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Photo de profil" class="h-full w-full object-cover">
            </div>
            <div class="min-w-0">
                <div class="text-xs font-bold uppercase tracking-wide text-indigo-600">Profil utilisateur</div>
                <h1 class="mt-1 truncate text-3xl font-black text-slate-950"><?= htmlspecialchars($displayName !== '' ? $displayName : 'Utilisateur', ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars((string) ($user['mail_pro'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                <div class="mt-4 flex flex-wrap gap-2 text-xs font-bold">
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-emerald-700">Compte actif</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-slate-700"><?= htmlspecialchars((string) ($_SESSION['role_raw'] ?? 'Utilisateur'), ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($personnel && !empty($personnel['matricule'])): ?>
                        <span class="rounded-full bg-indigo-50 px-3 py-1 text-indigo-700"><?= htmlspecialchars((string) $personnel['matricule'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" action="request/upload_photo.php" enctype="multipart/form-data" class="rounded-3xl border border-slate-200 bg-slate-50 p-4">
                <label class="block text-xs font-bold uppercase tracking-wide text-slate-500" for="photoInput">Photo de profil</label>
                <input id="photoInput" type="file" name="photo" accept="image/*" class="mt-3 block w-full text-sm text-slate-700 file:mr-3 file:rounded-xl file:border-0 file:bg-indigo-600 file:px-4 file:py-2 file:text-sm file:font-bold file:text-white">
                <button class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">
                    <i class="fa-solid fa-cloud-arrow-up"></i>
                    Enregistrer la photo
                </button>
            </form>
        </div>

        <div id="account-security-wide" class="border-b border-slate-100 p-5 md:p-7"></div>

        <div class="grid gap-0 divide-y divide-slate-100 lg:grid-cols-[1fr_380px] lg:divide-x lg:divide-y-0">
            <div class="p-5 md:p-7">
                <div class="grid gap-5 xl:grid-cols-2">
                    <section class="rounded-3xl border border-slate-200 bg-white p-5" id="identite">
                        <div class="mb-5 flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-black text-slate-950">Identite</h2>
                                <p class="mt-1 text-sm text-slate-500">Informations visibles sur votre compte.</p>
                            </div>
                            <span class="rounded-2xl bg-indigo-50 p-3 text-indigo-700"><i class="fa-regular fa-id-card"></i></span>
                        </div>

                        <form method="post" action="request/update_profile.php" class="grid gap-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Prenom</span>
                                    <input type="text" name="prenom" value="<?= htmlspecialchars((string) ($user['prenom'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                                </label>
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Nom</span>
                                    <input type="text" name="nom" value="<?= htmlspecialchars((string) ($user['nom'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                                </label>
                            </div>
                            <label class="block">
                                <span class="text-sm font-semibold text-slate-700">Email professionnel</span>
                                <input type="email" name="mail_pro" value="<?= htmlspecialchars((string) ($user['mail_pro'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                            </label>
                            <button class="inline-flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white hover:bg-indigo-700">
                                <i class="fa-solid fa-check"></i>
                                Mettre a jour
                            </button>
                        </form>
                    </section>

                    <section class="rounded-3xl border border-slate-200 bg-white p-5" id="signature">
                        <div class="mb-5 flex items-start justify-between gap-4">
                            <div>
                                <h2 class="text-lg font-black text-slate-950">Signature</h2>
                                <p class="mt-1 text-sm text-slate-500">Elle servira aux documents administratifs generes plus tard.</p>
                            </div>
                            <span class="rounded-2xl bg-amber-50 p-3 text-amber-700"><i class="fa-solid fa-signature"></i></span>
                        </div>

                        <form method="post" action="request/save_profile_signature.php" class="js-signature-pad grid gap-4">
                            <input type="hidden" name="signature_base64" class="js-signature-value">
                            <div class="relative">
                                <canvas class="js-signature-canvas signature-canvas"></canvas>
                                <div class="js-signature-hint pointer-events-none absolute inset-0 flex items-center justify-center text-sm font-semibold text-slate-400">Signez ici</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                                <div class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Apercu</div>
                                <div class="js-signature-preview flex min-h-[92px] items-center justify-center rounded-xl bg-white text-sm text-slate-400" data-empty-label="Aucune signature enregistree">
                                    <?php if ($signatureSrc !== ''): ?>
                                        <img src="<?= htmlspecialchars($signatureSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Signature actuelle" style="max-width:100%; max-height:92px; object-fit:contain;">
                                    <?php elseif ($signatureUnavailable): ?>
                                        <span class="px-3 text-center text-amber-700"><i class="fa-solid fa-triangle-exclamation mr-2"></i>L’ancien fichier de signature est indisponible. Veuillez signer à nouveau.</span>
                                    <?php else: ?>
                                        Aucune signature enregistree
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" class="js-signature-clear rounded-2xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Effacer</button>
                                <button type="button" class="js-signature-use rounded-2xl border border-indigo-200 px-4 py-2.5 text-sm font-bold text-indigo-700 hover:bg-indigo-50">Capturer</button>
                                <button class="ml-auto rounded-2xl bg-slate-950 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Enregistrer</button>
                            </div>
                            <div class="js-signature-status text-xs font-semibold text-slate-500"></div>
                        </form>
                    </section>
                </div>

                <section class="mt-5 rounded-3xl border border-slate-200 bg-white p-5" id="parametres">
                    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-black text-slate-950">Parametres mails et notifications</h2>
                            <p class="mt-1 text-sm text-slate-500">Les options affichees correspondent aux modules que votre compte peut consulter.</p>
                        </div>
                        <span class="rounded-2xl bg-cyan-50 p-3 text-cyan-700"><i class="fa-regular fa-bell"></i></span>
                    </div>

                    <form method="post" action="request/update_notification_preferences.php" class="grid gap-3">
                        <?php foreach ($notificationOptions as $eventKey => $option): ?>
                            <?php
                            $familyKey = strpos((string) $eventKey, '.') !== false ? strtok((string) $eventKey, '.') : (string) $eventKey;
                            $defaultPref = ['email' => (bool) ($option['default_email'] ?? true), 'system' => (bool) ($option['default_system'] ?? true)];
                            $pref = $notificationPrefs[$eventKey] ?? ($notificationPrefs[$familyKey] ?? $defaultPref);
                            ?>
                            <div class="grid gap-4 rounded-2xl border border-slate-200 p-4 md:grid-cols-[1fr_auto_auto] md:items-center">
                                <div>
                                    <div class="mb-2 inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-black uppercase tracking-wide text-slate-600"><?= htmlspecialchars((string) ($option['header'] ?? 'Notification'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="font-bold text-slate-900"><?= htmlspecialchars((string) $option['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-sm text-slate-500"><?= htmlspecialchars((string) $option['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <label class="profile-switch flex items-center justify-between gap-3 text-sm font-bold text-slate-700">
                                    Email
                                    <input type="checkbox" name="preferences[<?= htmlspecialchars((string) $eventKey, ENT_QUOTES, 'UTF-8') ?>][email]" value="1" class="sr-only" <?= !empty($pref['email']) ? 'checked' : '' ?>>
                                    <span class="relative h-7 w-12 rounded-full bg-slate-200 transition"><span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition"></span></span>
                                </label>
                                <label class="profile-switch flex items-center justify-between gap-3 text-sm font-bold text-slate-700">
                                    Systeme
                                    <input type="checkbox" name="preferences[<?= htmlspecialchars((string) $eventKey, ENT_QUOTES, 'UTF-8') ?>][system]" value="1" class="sr-only" <?= !empty($pref['system']) ? 'checked' : '' ?>>
                                    <span class="relative h-7 w-12 rounded-full bg-slate-200 transition"><span class="absolute left-1 top-1 h-5 w-5 rounded-full bg-white shadow transition"></span></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <button class="inline-flex w-fit items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white hover:bg-indigo-700">
                            <i class="fa-solid fa-sliders"></i>
                            Enregistrer les preferences
                        </button>
                    </form>
                </section>
            </div>

            <aside class="space-y-5 bg-slate-50 p-5 md:p-7">
                <section class="rounded-3xl border border-slate-200 bg-white p-5">
                    <h2 class="text-lg font-black text-slate-950">Profil employe</h2>
                    <dl class="mt-4 grid gap-3 text-sm">
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Fonction</dt>
                            <dd class="mt-1 font-bold text-slate-900"><?= htmlspecialchars((string) ($personnel['fonction_label'] ?? 'Non renseignee'), ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Service</dt>
                            <dd class="mt-1 font-bold text-slate-900"><?= htmlspecialchars((string) ($personnel['service_label'] ?? 'Non renseigne'), ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div class="rounded-2xl bg-slate-50 p-3">
                            <dt class="text-xs font-bold uppercase tracking-wide text-slate-500">Matricule</dt>
                            <dd class="mt-1 font-bold text-slate-900"><?= htmlspecialchars((string) ($personnel['matricule'] ?? 'Non renseigne'), ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    </dl>
                </section>

                <section class="rounded-3xl border border-cyan-200 bg-cyan-50 p-5"><h2 class="text-lg font-black text-cyan-950">E-mails du point quotidien</h2><p class="mt-1 text-sm text-cyan-800">Les notifications FINEA restent actives même si vous coupez les e-mails.</p><form method="post" action="request/save_daily_point_email_preference.php" class="mt-4"><label class="flex items-center justify-between rounded-2xl bg-white p-4"><span class="font-bold text-slate-800">Recevoir les demandes de signature par e-mail</span><input type="checkbox" name="email_enabled" value="1" <?= $dailyPointEmailEnabled?'checked':'' ?> class="h-5 w-5"></label><button class="mt-3 rounded-xl bg-cyan-700 px-4 py-2 text-sm font-bold text-white">Enregistrer</button></form></section>

                <section class="rounded-3xl border border-sky-200 bg-white p-5 shadow-sm" id="securite">
                    <div class="flex items-center gap-3">
                        <span class="grid h-10 w-10 place-items-center rounded-2xl bg-sky-100 text-sky-700"><i class="fa-solid fa-shield-halved"></i></span>
                        <h2 class="text-lg font-black text-sky-950">Sécurité du compte</h2>
                    </div>
                    <div id="passkey-security" data-endpoint-base="../request" data-csrf="<?= htmlspecialchars((string) $_SESSION['passkey_csrf'], ENT_QUOTES, 'UTF-8') ?>" class="mt-4 overflow-hidden rounded-3xl border border-sky-200 bg-sky-50/70">
                        <div class="border-b border-sky-100 bg-white p-4">
                        <div class="flex items-start gap-3">
                            <span class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-sky-500 text-xl text-white shadow-sm"><i class="fa-solid fa-fingerprint"></i></span>
                            <div class="min-w-0 flex-1">
                                <h3 class="font-black text-sky-950"><?= $passkeys ? 'Ajouter une autre Passkey' : 'Créer ma Passkey' ?></h3>
                                <p class="mt-1 text-sm leading-6 text-sky-800">Connectez-vous facilement avec votre téléphone, sans retenir un nouveau mot de passe.</p>
                            </div>
                        </div>
                        </div>
                        <div class="p-4">
                        <ol class="grid gap-3 text-sm font-semibold sm:grid-cols-3">
                            <li class="rounded-2xl border border-sky-200 bg-white p-3 text-sky-900"><span class="mb-2 grid h-8 w-8 place-items-center rounded-full bg-sky-100 text-sky-700"><i class="fa-solid fa-hand-pointer"></i></span><b class="block">Appuyez sur le bouton</b></li>
                            <li class="rounded-2xl border border-cyan-200 bg-white p-3 text-cyan-900"><span class="mb-2 grid h-8 w-8 place-items-center rounded-full bg-cyan-100 text-cyan-700"><i class="fa-solid fa-mobile-screen-button"></i></span><b class="block">Confirmez sur l’appareil</b></li>
                            <li class="rounded-2xl border border-emerald-200 bg-white p-3 text-emerald-900"><span class="mb-2 grid h-8 w-8 place-items-center rounded-full bg-emerald-100 text-emerald-700"><i class="fa-solid fa-circle-check"></i></span><b class="block">Votre Passkey est prête</b></li>
                        </ol>
                        <button type="button" id="passkey-register-button" class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-emerald-600 px-5 py-3.5 text-sm font-black text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus:ring-4 focus:ring-emerald-200">
                            <i class="fa-solid fa-mobile-screen-button"></i>Créer ma Passkey maintenant
                        </button>
                        <p class="mt-3 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800"><i class="fa-solid fa-lock mr-1"></i>Vos données de déverrouillage restent uniquement sur votre appareil.</p>
                        <div class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                            <b><i class="fa-solid fa-circle-info mr-1 text-amber-600"></i>Aucun compte Gmail n’est demandé par FINEA.</b>
                            Sur Android, le téléphone peut toutefois utiliser Google Password Manager pour conserver la Passkey. Si l’écran proposé ne convient pas, choisissez <b>Autres options</b>, puis <b>Cet appareil</b>, un autre gestionnaire disponible ou une clé de sécurité.
                        </div>
                        <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-2xl border border-violet-200 bg-violet-50 p-4">
                            <input id="passkey-only-toggle" type="checkbox" class="mt-1 h-5 w-5" <?= !empty($passkeyPolicy['passkey_only'])?'checked':'' ?> <?= !empty($passkeyPolicy['imposed_by_admin'])?'disabled':'' ?>>
                            <span><b class="block text-violet-950">Connexion uniquement via Passkey</b><small class="mt-1 block leading-5 text-violet-800">Désactive la connexion ordinaire par e-mail et mot de passe. Vous pourrez la réactiver ici, sauf si un administrateur l’impose.</small><?php if(!empty($passkeyPolicy['imposed_by_admin'])):?><small class="mt-2 block font-black text-amber-700"><i class="fa-solid fa-user-shield mr-1"></i>Imposée par un administrateur</small><?php endif;?></span>
                        </label>
                        <div id="passkey-message" class="d-none mt-3 rounded-2xl border px-4 py-3 text-sm font-semibold" role="status" aria-live="polite"></div>
                        <div class="mt-5 flex items-center gap-2 text-sm font-black text-sky-950"><i class="fa-solid fa-circle-check text-emerald-500"></i>Mes appareils approuvés</div>
                        <div id="passkey-list" class="mt-2 grid gap-2 text-sm">
                            <?php if (!$passkeys): ?><div data-passkey-empty class="rounded-xl border border-dashed border-sky-300 bg-white p-3 text-center text-sky-700"><i class="fa-solid fa-circle-info mr-1"></i>Aucun appareil enregistré pour le moment.</div><?php endif; ?>
                            <?php foreach ($passkeys as $passkey): ?>
                                <div data-passkey-id="<?= (int) $passkey['id'] ?>" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3">
                                    <div><strong class="text-slate-900"><i class="fa-solid fa-mobile-screen mr-2 text-indigo-600"></i><?= htmlspecialchars((string) $passkey['device_name'], ENT_QUOTES, 'UTF-8') ?></strong><div class="mt-1 text-xs leading-5 text-slate-500">Ajoutée le <?= htmlspecialchars(date('d/m/Y à H:i', strtotime((string) $passkey['created_at'])), ENT_QUOTES, 'UTF-8') ?><br>Dernière utilisation : <?= $passkey['last_used_at'] ? htmlspecialchars(date('d/m/Y à H:i', strtotime((string) $passkey['last_used_at'])), ENT_QUOTES, 'UTF-8') : 'jamais' ?><br>Identifiant : <code><?= htmlspecialchars(strtoupper(substr(bin2hex((string)$passkey['credential_id_hash']),0,16)),ENT_QUOTES,'UTF-8') ?></code><?php if(!empty($passkey['aaguid'])):?><br>AAGUID : <code><?=htmlspecialchars((string)$passkey['aaguid'],ENT_QUOTES,'UTF-8')?></code><?php endif;?></div></div>
                                    <button type="button" class="passkey-revoke-button rounded-xl border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50">Révoquer</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-6 flex items-center gap-2 text-sm font-black text-emerald-950"><i class="fa-solid fa-qrcode text-emerald-600"></i>Téléphones autorisés à scanner</div>
                        <p class="mt-1 text-xs leading-5 text-slate-600">Téléphones ayant déjà confirmé une connexion par QR code. Révoquer un téléphone bloque ses prochaines validations QR sans supprimer votre Passkey.</p>
                        <div id="mobile-scanner-list" class="mt-3 grid gap-2 text-sm">
                            <?php if(!$mobileScanners):?><div class="rounded-xl border border-dashed border-emerald-200 bg-white p-3 text-center text-slate-500">Aucun téléphone n’a encore validé de QR code.</div><?php endif;?>
                            <?php foreach($mobileScanners as $scanner):?><div data-mobile-scanner-id="<?=(int)$scanner['id']?>" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-100 bg-white p-3"><div class="min-w-0"><strong class="text-slate-900"><i class="fa-solid fa-mobile-screen-button mr-2 text-emerald-600"></i><?=htmlspecialchars((string)$scanner['device_label'],ENT_QUOTES,'UTF-8')?></strong><div class="mt-1 break-words text-xs leading-5 text-slate-500">Autorisé le <?=htmlspecialchars(date('d/m/Y à H:i',strtotime((string)$scanner['first_authorized_at'])),ENT_QUOTES,'UTF-8')?> · Dernier scan : <?=htmlspecialchars(date('d/m/Y à H:i',strtotime((string)$scanner['last_used_at'])),ENT_QUOTES,'UTF-8')?><br>Validations : <?=(int)$scanner['use_count']?> · Passkey utilisée : <?=htmlspecialchars((string)($scanner['passkey_name']?:('n° '.(int)$scanner['passkey_id'])),ENT_QUOTES,'UTF-8')?><?php if(!empty($scanner['last_ip_address'])):?><br>Dernière adresse IP : <?=htmlspecialchars((string)$scanner['last_ip_address'],ENT_QUOTES,'UTF-8')?><?php endif;?><?php if(!empty($scanner['first_ip_address'])&&$scanner['first_ip_address']!==$scanner['last_ip_address']):?><br>Première adresse IP : <?=htmlspecialchars((string)$scanner['first_ip_address'],ENT_QUOTES,'UTF-8')?><?php endif;?><?php if(!empty($scanner['user_agent'])):?><br>Navigateur : <?=htmlspecialchars((string)$scanner['user_agent'],ENT_QUOTES,'UTF-8')?><?php endif;?></div></div><button type="button" class="mobile-scanner-revoke rounded-xl border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50">Révoquer</button></div><?php endforeach;?>
                        </div>
                        <div class="mt-6 flex items-center gap-2 text-sm font-black text-violet-950"><i class="fa-solid fa-laptop-code text-violet-600"></i>Appareils actuellement connectés</div>
                        <p class="mt-1 text-xs leading-5 text-slate-600">Chaque connexion est affichée séparément, même lorsque la même Passkey est synchronisée sur plusieurs appareils.</p>
                        <?php if(count($deviceSessions)>1):?><button type="button" id="device-sessions-revoke-others" class="mt-3 inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-black text-rose-700 hover:bg-rose-50"><i class="fa-solid fa-right-from-bracket"></i>Déconnecter tous les autres appareils</button><?php endif;?>
                        <div id="device-session-list" class="mt-3 grid gap-2 text-sm">
                            <?php if(!$deviceSessions):?><div class="rounded-xl border border-dashed border-violet-200 bg-white p-3 text-center text-slate-500">Aucune session d’appareil enregistrée.</div><?php endif;?>
                            <?php foreach($deviceSessions as $deviceSession):?><div data-device-session-id="<?=(int)$deviceSession['id']?>" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-violet-100 bg-white p-3"><div><strong class="text-slate-900"><i class="fa-solid fa-display mr-2 text-violet-600"></i><?=htmlspecialchars((string)$deviceSession['device_label'],ENT_QUOTES,'UTF-8')?><?php if((int)$deviceSession['id']===$currentDeviceSessionId):?><span class="ml-2 rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-black uppercase text-emerald-700">Cet appareil</span><?php endif;?></strong><div class="mt-1 text-xs leading-5 text-slate-500">Connexion <?=($deviceSession['auth_method']??'')==='passkey'?'via Passkey':'classique'?> · <?=htmlspecialchars(date('d/m/Y à H:i',strtotime((string)$deviceSession['created_at'])),ENT_QUOTES,'UTF-8')?><br>Dernière activité : <?=htmlspecialchars(date('d/m/Y à H:i',strtotime((string)$deviceSession['last_seen_at'])),ENT_QUOTES,'UTF-8')?><?php if(!empty($deviceSession['ip_address'])):?><br>Adresse IP : <?=htmlspecialchars((string)$deviceSession['ip_address'],ENT_QUOTES,'UTF-8')?><?php endif;?></div></div><button type="button" class="device-session-revoke rounded-xl border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50">Déconnecter</button></div><?php endforeach;?>
                        </div>
                        </div>
                    </div>
                    <form method="post" action="request/change_password.php" class="mt-4 grid gap-3">
                        <input type="password" name="current_password" placeholder="Mot de passe actuel" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                        <input type="password" name="new_password" placeholder="Nouveau mot de passe" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                        <input type="password" name="confirm_password" placeholder="Confirmer" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100">
                        <button class="rounded-2xl bg-amber-500 px-4 py-3 text-sm font-bold text-white hover:bg-amber-600">Modifier le mot de passe</button>
                    </form>
                </section>

                <section class="rounded-3xl border border-slate-200 bg-white p-5">
                    <h2 class="text-lg font-black text-slate-950">Permissions actives</h2>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <?php if ($permissionLabels): ?>
                            <?php foreach ($permissionLabels as $label): ?>
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-sm text-slate-500">Aucune permission specifique active.</span>
                        <?php endif; ?>
                    </div>
                </section>
            </aside>
        </div>
    </section>
</main>

<script src="../js/rh_explanation_signature_pad.js"></script>
<script>
    const accountSecurity = document.getElementById('securite');
    const accountSecurityWide = document.getElementById('account-security-wide');
    if (accountSecurity && accountSecurityWide) accountSecurityWide.appendChild(accountSecurity);

    const photoInput = document.getElementById('photoInput');
    if (photoInput) {
        photoInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = function(ev) {
                document.getElementById('avatarPreview').src = ev.target.result;
            };
            reader.readAsDataURL(file);
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
