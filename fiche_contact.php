<?php
require_once __DIR__ . '/auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/AddressBookDirectory.php';
require_once __DIR__ . '/model/AuditTrail.php';

$pageTitle = 'Fiche contact';
$activeMenu = '';
$pdo = \Database::getConnection();
$directory = new \AddressBookDirectory($pdo);
$contactId = trim((string) ($_GET['contact'] ?? $_POST['contact'] ?? ''));
$contact = $directory->getContactById($contactId);

if (!$contact) {
    http_response_code(404);
    require_once __DIR__ . '/decaissement/includes/header.php';
    require_once __DIR__ . '/decaissement/includes/navbar.php';
?>
    <div class="mx-auto max-w-3xl px-4 py-16">
        <div class="rounded-3xl border border-rose-200 bg-rose-50 px-6 py-10 text-center">
            <h1 class="text-2xl font-semibold text-rose-900">Contact introuvable</h1>
            <p class="mt-3 text-sm text-rose-700">Le contact demandé n’existe pas ou n’est plus disponible dans le carnet d’adresse.</p>
            <a href="carnet_adresse.php" class="mt-6 inline-flex items-center gap-2 rounded-2xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white">Retour au carnet</a>
        </div>
    </div>
<?php
    require_once __DIR__ . '/decaissement/includes/footer.php';
    exit;
}

$flashSuccess = null;
$flashError = null;
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$historyAvailable = $directory->hasHistoryStorage();

$storeInteractionAttachment = static function (?array $file): array {
    if (!is_array($file) || !isset($file['error'])) {
        return [];
    }

    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return [];
    }

    switch ($uploadError) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('La pièce jointe dépasse la taille autorisée par le serveur.');
        case UPLOAD_ERR_PARTIAL:
            throw new RuntimeException('La pièce jointe n’a été téléversée que partiellement.');
        default:
            throw new RuntimeException('Échec du téléversement de la pièce jointe.');
    }

    $baseRelativeDirectory = 'storage/address_book_interactions/' . date('Ym');
    $baseAbsoluteDirectory = __DIR__ . '/' . $baseRelativeDirectory;
    if (!is_dir($baseAbsoluteDirectory) && !@mkdir($baseAbsoluteDirectory, 0755, true)) {
        throw new RuntimeException('Impossible de créer le dossier des pièces jointes du carnet d’adresse.');
    }

    $originalName = trim((string) ($file['name'] ?? 'document'));
    $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
    $storedName = bin2hex(random_bytes(12)) . ($extension !== '' ? '.' . $extension : '');
    $absoluteTarget = $baseAbsoluteDirectory . '/' . $storedName;
    $relativeTarget = $baseRelativeDirectory . '/' . $storedName;
    $tmpName = (string) ($file['tmp_name'] ?? '');

    $stored = move_uploaded_file($tmpName, $absoluteTarget);
    if (!$stored && $tmpName !== '' && is_file($tmpName)) {
        $stored = @rename($tmpName, $absoluteTarget);
        if (!$stored) {
            $stored = @copy($tmpName, $absoluteTarget);
            if ($stored) {
                @unlink($tmpName);
            }
        }
    }

    if (!$stored) {
        throw new RuntimeException('Impossible de stocker la pièce jointe sur le serveur.');
    }

    return [
        'path' => $relativeTarget,
        'name' => $originalName !== '' ? $originalName : basename($relativeTarget),
        'mime' => trim((string) ($file['type'] ?? 'application/octet-stream')),
        'size' => max(0, (int) ($file['size'] ?? 0)),
    ];
};

$formatDateTimeDisplay = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return 'Non planifié';
    }
    try {
        return (new DateTimeImmutable($value))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
};

$formatDateTimeInput = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable $e) {
        return '';
    }
};

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    if (isset($_POST['interaction_form'])) {
        $interactionType = trim((string) ($_POST['interaction_type'] ?? 'note'));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $channel = trim((string) ($_POST['channel'] ?? 'manual'));
        $status = trim((string) ($_POST['status'] ?? 'open'));
        $reminderAt = trim((string) ($_POST['reminder_at'] ?? ''));

        try {
            $metadata = [
                'status' => $status,
                'reminder_at' => $reminderAt,
            ];

            $attachment = $storeInteractionAttachment($_FILES['attachment'] ?? null);
            if ($attachment) {
                $metadata['attachment'] = $attachment;
            }

            if ($directory->addInteraction($contactId, $currentUserId, $interactionType, $channel, $subject, $note, $metadata)) {
                $flashSuccess = 'Interaction enregistrée dans l’historique.';
            } else {
                $flashError = $historyAvailable
                    ? 'Impossible d’enregistrer l’interaction.'
                    : 'Le stockage de l’historique n’est pas disponible sur cet environnement.';
            }
        } catch (Throwable $e) {
            $flashError = $e->getMessage();
        }
    } elseif (isset($_POST['interaction_status_form'])) {
        $interactionId = (int) ($_POST['interaction_id'] ?? 0);
        $status = trim((string) ($_POST['status'] ?? 'done'));

        if ($directory->updateInteractionStatus($contactId, $interactionId, $status)) {
            $flashSuccess = $status === 'done'
                ? 'La tâche a été marquée comme terminée.'
                : 'Le statut de la relance a été mis à jour.';
        } else {
            $flashError = 'Impossible de mettre à jour le statut de cette relance.';
        }
    }
}

$interactions = $directory->listInteractions($contactId, 120);

\AuditTrail::logPageConsultation([
    'module' => 'carnet_adresse',
    'action' => 'contact_detail_view',
    'entity_type' => 'address_book_contact',
    'entity_id' => $contactId,
    'entity_label' => (string) ($contact['display_name'] ?? 'Contact'),
    'page_name' => 'fiche_contact.php',
    'details' => [
        'source' => (string) ($contact['source'] ?? ''),
        'view_mode' => 'detail',
    ],
], $pdo);

$normalizePhoneForHref = static function (string $phone): string {
    return preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';
};

$phone = trim((string) ($contact['phone'] ?? ''));
$email = trim((string) ($contact['email'] ?? ''));
$normalizedPhone = $normalizePhoneForHref($phone);
$canUseMailModule = \AccessManager::hasPermission('acces_mail') || \AccessManager::hasPermission('acces_admin');
$mailHref = $email !== ''
    ? ($canUseMailModule
        ? 'emails/compose_email.php?to=' . rawurlencode($email) . '&subject=' . rawurlencode('Prise de contact - ' . (string) ($contact['display_name'] ?? ''))
        : 'mailto:' . rawurlencode($email))
    : '';
$smsHref = $normalizedPhone !== '' ? 'sms:' . rawurlencode($normalizedPhone) : '';
$callHref = $normalizedPhone !== '' ? 'tel:' . rawurlencode($normalizedPhone) : '';

$typeMeta = [
    'note' => ['label' => 'Note', 'chip' => 'bg-slate-100 text-slate-700'],
    'email' => ['label' => 'Email', 'chip' => 'bg-sky-100 text-sky-700'],
    'sms' => ['label' => 'SMS', 'chip' => 'bg-violet-100 text-violet-700'],
    'call' => ['label' => 'Appel', 'chip' => 'bg-emerald-100 text-emerald-700'],
    'meeting' => ['label' => 'Rendez-vous', 'chip' => 'bg-amber-100 text-amber-700'],
];

$statusMeta = [
    'open' => ['label' => 'À traiter', 'chip' => 'bg-rose-100 text-rose-700'],
    'pending' => ['label' => 'En attente', 'chip' => 'bg-amber-100 text-amber-700'],
    'scheduled' => ['label' => 'Planifié', 'chip' => 'bg-sky-100 text-sky-700'],
    'done' => ['label' => 'Terminé', 'chip' => 'bg-emerald-100 text-emerald-700'],
];

$pendingItems = [];
$overdueCount = 0;
$dueTodayCount = 0;
$pendingCount = 0;
$now = new DateTimeImmutable('now');

foreach ($interactions as $interaction) {
    $metadata = (array) ($interaction['metadata'] ?? []);
    $status = (string) ($metadata['status'] ?? 'open');
    if ($status === 'done') {
        continue;
    }

    $pendingCount++;
    $reminderAt = trim((string) ($metadata['reminder_at'] ?? ''));
    $sortTimestamp = PHP_INT_MAX;
    if ($reminderAt !== '') {
        try {
            $reminderDate = new DateTimeImmutable($reminderAt);
            $sortTimestamp = $reminderDate->getTimestamp();
            if ($reminderDate < $now) {
                $overdueCount++;
            }
            if ($reminderDate->format('Y-m-d') === $now->format('Y-m-d')) {
                $dueTodayCount++;
            }
        } catch (Throwable $e) {
            $sortTimestamp = PHP_INT_MAX - 1;
        }
    }

    $pendingItems[] = [
        'interaction' => $interaction,
        'sort_timestamp' => $sortTimestamp,
    ];
}

usort($pendingItems, static function (array $left, array $right): int {
    if ($left['sort_timestamp'] !== $right['sort_timestamp']) {
        return $left['sort_timestamp'] <=> $right['sort_timestamp'];
    }

    return strcmp((string) (($right['interaction']['created_at'] ?? '')), (string) (($left['interaction']['created_at'] ?? '')));
});

$nextActions = array_slice($pendingItems, 0, 6);

require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/decaissement/includes/navbar.php';
?>

<link rel="stylesheet" href="assets/css/address-book-theme.css?v=<?= filemtime(__DIR__.'/assets/css/address-book-theme.css') ?>">
<script>document.body.classList.add('address-book-screen');</script>
<div class="ab-workspace min-h-screen">
    <div class="mx-auto max-w-7xl px-4 py-8 md:px-6 lg:px-8">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
                <a href="carnet_adresse.php" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-500 transition hover:text-slate-900"><i class="fas fa-arrow-left"></i> Retour au carnet</a>
                <h1 class="mt-3 text-3xl font-semibold text-slate-950 md:text-4xl"><?= htmlspecialchars((string) ($contact['display_name'] ?? 'Contact'), ENT_QUOTES, 'UTF-8') ?></h1>
                <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars((string) ($contact['source_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars((string) ($contact['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
            <div class="flex flex-wrap gap-3">
<button type="button" id="abThemeToggle" class="inline-flex items-center rounded-2xl border px-4 py-3 text-sm font-semibold">Mode sombre</button>
<script>(function(){const root=document.documentElement,b=document.getElementById('abThemeToggle');function sync(){const dark=root.classList.contains('finea-dark-mode');document.body.classList.toggle('finea-dark-mode',dark);b.textContent=dark?'☀ Mode clair':'☾ Mode sombre';b.setAttribute('aria-pressed',String(dark));}sync();new MutationObserver(sync).observe(root,{attributes:true,attributeFilter:['class']});b.addEventListener('click',()=>{const dark=!root.classList.contains('finea-dark-mode');root.classList.toggle('finea-dark-mode',dark);sync();try{['finea.app.theme','finea.portal.theme'].forEach(k=>localStorage.setItem(k,dark?'dark':'light'));}catch(e){}});window.addEventListener('storage',e=>{if(['finea.app.theme','finea.portal.theme'].includes(e.key))root.classList.toggle('finea-dark-mode',e.newValue==='dark');});})();</script>
                <?php if ($mailHref !== ''): ?>
                    <a href="<?= htmlspecialchars($mailHref, ENT_QUOTES, 'UTF-8') ?>" class="contact-track inline-flex items-center gap-2 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-700" data-contact-id="<?= htmlspecialchars($contactId, ENT_QUOTES, 'UTF-8') ?>" data-interaction-type="email" data-channel="email" data-subject="Email lancé depuis la fiche contact"><i class="fas fa-envelope"></i> Envoyer mail</a>
                <?php endif; ?>
                <?php if ($smsHref !== ''): ?>
                    <a href="<?= htmlspecialchars($smsHref, ENT_QUOTES, 'UTF-8') ?>" class="contact-track inline-flex items-center gap-2 rounded-2xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm font-semibold text-violet-700" data-contact-id="<?= htmlspecialchars($contactId, ENT_QUOTES, 'UTF-8') ?>" data-interaction-type="sms" data-channel="sms" data-subject="SMS lancé depuis la fiche contact"><i class="fas fa-comment-sms"></i> SMS</a>
                <?php endif; ?>
                <?php if ($callHref !== ''): ?>
                    <a href="<?= htmlspecialchars($callHref, ENT_QUOTES, 'UTF-8') ?>" class="contact-track inline-flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700" data-contact-id="<?= htmlspecialchars($contactId, ENT_QUOTES, 'UTF-8') ?>" data-interaction-type="call" data-channel="phone" data-subject="Appel lancé depuis la fiche contact"><i class="fas fa-phone"></i> Appeler</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($flashSuccess): ?>
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
            <section class="rounded-[30px] border border-slate-200 bg-white p-6 shadow-sm">
                <div class="grid gap-6 md:grid-cols-[auto_1fr] md:items-start">
                    <div class="ab-avatar flex h-24 w-24 items-center justify-center rounded-[28px] bg-gradient-to-br from-sky-600 to-indigo-600 text-3xl font-semibold text-white shadow-lg"><?= htmlspecialchars(mb_strtoupper(mb_substr((string) ($contact['display_name'] ?? 'NA'), 0, 2, 'UTF-8'), 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="space-y-4">
                        <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Coordonnées</div>
                            <div class="mt-3 grid gap-3 text-sm text-slate-700">
                                <div><span class="font-semibold text-slate-900">Nom :</span> <?= htmlspecialchars((string) ($contact['contact_name'] ?: $contact['display_name']), ENT_QUOTES, 'UTF-8') ?></div>
                                <div><span class="font-semibold text-slate-900">Structure :</span> <?= htmlspecialchars((string) ($contact['organization'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                                <div><span class="font-semibold text-slate-900">Téléphone :</span> <?= htmlspecialchars($phone !== '' ? $phone : '—', ENT_QUOTES, 'UTF-8') ?></div>
                                <div><span class="font-semibold text-slate-900">Email :</span> <?= htmlspecialchars($email !== '' ? $email : '—', ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                        <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Repères</div>
                            <p class="mt-3 text-sm leading-7 text-slate-700"><?= htmlspecialchars((string) ($contact['meta'] ?? 'Aucun repère complémentaire.'), ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>

                <form method="post" enctype="multipart/form-data" class="mt-6 rounded-[28px] border border-slate-200 bg-slate-50 p-5">
                    <input type="hidden" name="contact" value="<?= htmlspecialchars($contactId, ENT_QUOTES, 'UTF-8') ?>" />
                    <input type="hidden" name="interaction_form" value="1" />
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Ajouter un échange</div>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Consigner une interaction</h2>
                    </div>
                    <?php if (!$historyAvailable): ?>
                        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Le stockage de l’historique n’est pas disponible sur cet environnement. Les nouvelles interactions ne pourront pas être enregistrées tant que la table dédiée n’est pas accessible.
                        </div>
                    <?php endif; ?>
                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-700">Type</label>
                            <select name="interaction_type" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-sky-300 focus:ring-4 focus:ring-sky-100">
                                <option value="note">Note</option>
                                <option value="email">Email</option>
                                <option value="sms">SMS</option>
                                <option value="call">Appel</option>
                                <option value="meeting">Rendez-vous</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-700">Canal</label>
                            <select name="channel" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-sky-300 focus:ring-4 focus:ring-sky-100">
                                <option value="manual">Saisie manuelle</option>
                                <option value="email">Email</option>
                                <option value="sms">SMS</option>
                                <option value="phone">Téléphone</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="text-sm font-semibold text-slate-700">Statut de suivi</label>
                            <select name="status" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-sky-300 focus:ring-4 focus:ring-sky-100">
                                <option value="open">À traiter</option>
                                <option value="pending">En attente</option>
                                <option value="scheduled">Planifié</option>
                                <option value="done">Terminé</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-sm font-semibold text-slate-700">Rappel daté</label>
                            <input type="datetime-local" name="reminder_at" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-sky-300 focus:ring-4 focus:ring-sky-100" />
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="text-sm font-semibold text-slate-700">Objet</label>
                        <input type="text" name="subject" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-sky-300 focus:ring-4 focus:ring-sky-100" placeholder="Exemple: relance devis, suivi chantier, prise de contact..." />
                    </div>
                    <div class="mt-4">
                        <label class="text-sm font-semibold text-slate-700">Compte rendu</label>
                        <textarea name="note" rows="5" class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-sky-300 focus:ring-4 focus:ring-sky-100" placeholder="Conservez ici le résumé de l’échange, les décisions prises et les suites à donner."></textarea>
                    </div>
                    <div class="mt-4">
                        <label class="text-sm font-semibold text-slate-700">Pièce jointe</label>
                        <input type="file" name="attachment" class="mt-2 block w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 file:mr-4 file:rounded-xl file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:font-semibold file:text-slate-700" />
                        <p class="mt-2 text-xs text-slate-500">Ajoutez un document, un mail exporté, une capture ou tout élément utile au suivi de ce contact.</p>
                    </div>
                    <div class="mt-4 flex justify-end">
                        <button type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800"><i class="fas fa-plus"></i> Ajouter à l’historique</button>
                    </div>
                </form>
            </section>

            <section class="rounded-[30px] border border-slate-200 bg-white p-6 shadow-sm">
                <div class="rounded-[28px] border border-slate-200 bg-slate-50/80 p-5">
                    <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Prochaines relances / tâches à faire</div>
                            <h2 class="mt-2 text-xl font-semibold text-slate-950">Pilotage du suivi</h2>
                        </div>
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div class="rounded-2xl border border-white bg-white px-3 py-3">
                                <div class="text-xl font-semibold text-slate-950"><?= (int) $pendingCount ?></div>
                                <div class="mt-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Ouvertes</div>
                            </div>
                            <div class="rounded-2xl border border-white bg-white px-3 py-3">
                                <div class="text-xl font-semibold text-rose-700"><?= (int) $overdueCount ?></div>
                                <div class="mt-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">En retard</div>
                            </div>
                            <div class="rounded-2xl border border-white bg-white px-3 py-3">
                                <div class="text-xl font-semibold text-sky-700"><?= (int) $dueTodayCount ?></div>
                                <div class="mt-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Aujourd'hui</div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 space-y-3">
                        <?php if (!$nextActions): ?>
                            <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-sm text-slate-500">Aucune relance ouverte pour ce contact.</div>
                        <?php else: ?>
                            <?php foreach ($nextActions as $entry):
                                $interaction = $entry['interaction'];
                                $metadata = (array) ($interaction['metadata'] ?? []);
                                $statusKey = (string) ($metadata['status'] ?? 'open');
                                $status = $statusMeta[$statusKey] ?? $statusMeta['open'];
                                $reminderAt = trim((string) ($metadata['reminder_at'] ?? ''));
                            ?>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4">
                                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div>
                                            <div class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] <?= htmlspecialchars($status['chip'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="mt-3 text-sm font-semibold text-slate-950"><?= htmlspecialchars((string) (($interaction['subject'] ?? '') !== '' ? $interaction['subject'] : 'Suivi sans objet'), ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="mt-1 text-xs text-slate-500">
                                                <?= $reminderAt !== '' ? 'Rappel prévu le ' . htmlspecialchars($formatDateTimeDisplay($reminderAt), ENT_QUOTES, 'UTF-8') : 'Aucun rappel daté' ?>
                                            </div>
                                        </div>
                                        <form method="post" class="shrink-0">
                                            <input type="hidden" name="contact" value="<?= htmlspecialchars($contactId, ENT_QUOTES, 'UTF-8') ?>" />
                                            <input type="hidden" name="interaction_status_form" value="1" />
                                            <input type="hidden" name="interaction_id" value="<?= (int) ($interaction['id'] ?? 0) ?>" />
                                            <input type="hidden" name="status" value="done" />
                                            <button type="submit" class="inline-flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100">
                                                <i class="fas fa-check"></i>
                                                Terminer
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Historique d’échanges</div>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Timeline</h2>
                    </div>
                    <div class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600"><?= count($interactions) ?> entrée(s)</div>
                </div>

                <div class="mt-6 space-y-4">
                    <?php if (!$interactions): ?>
                        <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-5 py-10 text-center text-sm text-slate-500">Aucun échange enregistré pour ce contact.</div>
                    <?php else: ?>
                        <?php foreach ($interactions as $interaction):
                            $meta = $typeMeta[(string) ($interaction['interaction_type'] ?? 'note')] ?? $typeMeta['note'];
                            $metadata = (array) ($interaction['metadata'] ?? []);
                            $statusKey = (string) ($metadata['status'] ?? 'open');
                            $status = $statusMeta[$statusKey] ?? $statusMeta['open'];
                            $reminderAt = trim((string) ($metadata['reminder_at'] ?? ''));
                            $attachment = is_array($metadata['attachment'] ?? null) ? $metadata['attachment'] : null;
                        ?>
                            <article class="rounded-3xl border border-slate-200 bg-slate-50/80 p-5">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <div class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] <?= htmlspecialchars($meta['chip'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                            <div class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] <?= htmlspecialchars($status['chip'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($status['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                        </div>
                                        <h3 class="mt-3 text-lg font-semibold text-slate-950"><?= htmlspecialchars((string) (($interaction['subject'] ?? '') !== '' ? $interaction['subject'] : 'Interaction sans objet'), ENT_QUOTES, 'UTF-8') ?></h3>
                                        <p class="mt-1 text-xs font-medium uppercase tracking-[0.2em] text-slate-400">Par <?= htmlspecialchars((string) ($interaction['actor_display'] ?? 'Utilisateur'), ENT_QUOTES, 'UTF-8') ?> • canal <?= htmlspecialchars((string) ($interaction['channel'] ?? 'manual'), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="text-sm font-medium text-slate-500"><?= htmlspecialchars((string) ($interaction['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <?php if ($reminderAt !== '' || $attachment): ?>
                                    <div class="mt-4 flex flex-wrap gap-3">
                                        <?php if ($reminderAt !== ''): ?>
                                            <div class="inline-flex items-center gap-2 rounded-2xl border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-700">
                                                <i class="fas fa-bell"></i>
                                                Rappel: <?= htmlspecialchars($formatDateTimeDisplay($reminderAt), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($attachment): ?>
                                            <a href="<?= htmlspecialchars(ltrim((string) ($attachment['path'] ?? ''), '/'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-100">
                                                <i class="fas fa-paperclip"></i>
                                                <?= htmlspecialchars((string) ($attachment['name'] ?? 'Pièce jointe'), ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($interaction['note'])): ?>
                                    <div class="mt-4 rounded-2xl border border-white bg-white px-4 py-4 text-sm leading-7 text-slate-700"><?= nl2br(htmlspecialchars((string) $interaction['note'], ENT_QUOTES, 'UTF-8')) ?></div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
    document.addEventListener('click', function(event) {
        var link = event.target.closest('.contact-track');
        if (!link) {
            return;
        }

        try {
            fetch('api/address_book_interaction.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    contact_id: link.getAttribute('data-contact-id') || '',
                    interaction_type: link.getAttribute('data-interaction-type') || 'note',
                    channel: link.getAttribute('data-channel') || 'manual',
                    subject: link.getAttribute('data-subject') || '',
                    note: ''
                }),
                keepalive: true
            }).catch(function() {});
        } catch (e) {}
    });
</script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>