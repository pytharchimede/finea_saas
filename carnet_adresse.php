<?php
require_once __DIR__ . '/auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/AddressBookDirectory.php';

$pageTitle = 'Carnet d\'adresse';
$activeMenu = '';

$pdo = \Database::getConnection();
$directory = new \AddressBookDirectory($pdo);
$contacts = $directory->listContacts();

$export = trim((string)($_GET['export'] ?? ''));
$scope = trim((string)($_GET['scope'] ?? 'all'));

if (in_array($export, ['vcf', 'csv'], true)) {
    $contactsToExport = $directory->filterContactsForExport($contacts, $scope);

    if ($export === 'vcf') {
        $filename = 'carnet_adresses_' . $scope . '_banamur.vcf';
        $content = $directory->exportContactsAsVcard($contactsToExport);

        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    if ($export === 'csv') {
        $filename = 'carnet_adresses_' . $scope . '_banamur.csv';
        $content = $directory->exportContactsAsCsv($contactsToExport);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }
}

$canUseMailModule = \AccessManager::hasPermission('acces_mail') || \AccessManager::hasPermission('acces_admin');

$stats = [
    'all' => count($contacts),
    'internal' => 0,
    'supplier' => 0,
    'provider' => 0,
    'with_email' => 0,
    'with_phone' => 0,
];

foreach ($contacts as $contact) {
    $source = (string) ($contact['source'] ?? '');
    if (isset($stats[$source])) {
        $stats[$source]++;
    }
    if (!empty($contact['email'])) {
        $stats['with_email']++;
    }
    if (!empty($contact['phone'])) {
        $stats['with_phone']++;
    }
}

$sourcePalette = [
    'internal' => [
        'chip' => 'border-sky-200 bg-sky-50 text-sky-700',
        'glow' => 'from-sky-500/15 via-cyan-500/10 to-white',
        'accent' => 'from-sky-600 to-cyan-500',
    ],
    'provider' => [
        'chip' => 'border-violet-200 bg-violet-50 text-violet-700',
        'glow' => 'from-violet-500/15 via-fuchsia-500/10 to-white',
        'accent' => 'from-violet-600 to-fuchsia-500',
    ],
    'supplier' => [
        'chip' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
        'glow' => 'from-emerald-500/15 via-teal-500/10 to-white',
        'accent' => 'from-emerald-600 to-teal-500',
    ],
];

$buildInitials = static function (string $value): string {
    $value = trim($value);
    if ($value === '') {
        return 'NA';
    }
    $parts = preg_split('/\s+/u', $value) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
        if (mb_strlen($initials, 'UTF-8') >= 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'NA';
};

$normalizePhoneForHref = static function (string $phone): string {
    $phone = trim($phone);
    if ($phone === '') {
        return '';
    }
    $normalized = preg_replace('/[^0-9+]/', '', $phone) ?? '';
    return $normalized;
};

require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(14,165,233,0.18),_transparent_28%),radial-gradient(circle_at_top_right,_rgba(139,92,246,0.14),_transparent_26%),linear-gradient(180deg,_#f8fbff_0%,_#eef4ff_42%,_#f8fafc_100%)]">
    <div class="mx-auto max-w-7xl px-4 py-8 md:px-6 lg:px-8">
        <section class="relative overflow-hidden rounded-[32px] border border-white/70 bg-white/80 shadow-[0_30px_80px_rgba(15,23,42,0.10)] backdrop-blur">
            <div class="absolute inset-0 bg-[linear-gradient(135deg,rgba(15,23,42,0.96),rgba(30,64,175,0.92)_45%,rgba(14,165,233,0.82))]"></div>
            <div class="absolute -left-24 top-10 h-52 w-52 rounded-full bg-white/10 blur-3xl"></div>
            <div class="absolute right-0 top-0 h-44 w-44 translate-x-1/3 -translate-y-1/3 rounded-full bg-cyan-300/20 blur-3xl"></div>
            <div class="relative grid gap-8 px-6 py-8 md:px-8 lg:grid-cols-[1.1fr_0.9fr] lg:px-10 lg:py-10">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.28em] text-sky-100">
                        Répertoire opérationnel
                    </div>
                    <h1 class="mt-5 max-w-3xl text-3xl font-semibold leading-tight text-white md:text-5xl">Carnet d'adresse Banamur</h1>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-200 md:text-base">
                        Un annuaire unifié pour retrouver rapidement les contacts internes, fournisseurs et prestataires, avec actions immédiates pour email, SMS et appel direct.
                    </p>

                    <div class="mt-7 flex flex-wrap gap-3">
                        <a href="selection_portail.php" class="inline-flex items-center gap-2 rounded-2xl border border-white/20 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:bg-white/15">
                            <i class="fas fa-grid-2"></i>
                            Portail
                        </a>
                        <a href="carnet_adresse.php?export=vcf" class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-100">
                            <i class="fas fa-address-card"></i>
                            Export vCard
                        </a>

                        <a href="carnet_adresse.php?export=csv" class="inline-flex items-center gap-2 rounded-2xl border border-white/20 bg-white/10 px-4 py-3 text-sm font-semibold text-white transition hover:bg-white/15">
                            <i class="fas fa-file-csv"></i>
                            Export CSV
                        </a>
                        <a href="carnet_adresse.php?export=vcf&scope=internal" class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-100">
                            <i class="fas fa-users"></i>
                            Export Personnel
                        </a>

                        <a href="carnet_adresse.php?export=vcf&scope=supplier" class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-100">
                            <i class="fas fa-truck"></i>
                            Export Fournisseurs
                        </a>

                        <a href="carnet_adresse.php?export=vcf&scope=all" class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-100">
                            <i class="fas fa-address-book"></i>
                            Export Tous
                        </a>
                        <?php if ($canUseMailModule): ?>
                            <a href="emails/compose_email.php" class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-100">
                                <i class="fas fa-envelope"></i>
                                Nouveau mail
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <article class="rounded-[28px] border border-white/15 bg-white/10 p-5 text-white shadow-inner shadow-white/5 backdrop-blur-sm">
                        <div class="text-xs uppercase tracking-[0.22em] text-sky-100/80">Contacts visibles</div>
                        <div class="mt-3 text-4xl font-semibold"><?= (int) $stats['all'] ?></div>
                        <div class="mt-2 text-sm text-slate-200">Réunissant interne, fournisseurs et prestataires.</div>
                    </article>
                    <article class="rounded-[28px] border border-white/15 bg-white/10 p-5 text-white shadow-inner shadow-white/5 backdrop-blur-sm">
                        <div class="text-xs uppercase tracking-[0.22em] text-sky-100/80">Joignables par téléphone</div>
                        <div class="mt-3 text-4xl font-semibold"><?= (int) $stats['with_phone'] ?></div>
                        <div class="mt-2 text-sm text-slate-200">Appel et SMS en un clic quand le numéro est disponible.</div>
                    </article>
                    <article class="rounded-[28px] border border-white/15 bg-white/10 p-5 text-white shadow-inner shadow-white/5 backdrop-blur-sm">
                        <div class="text-xs uppercase tracking-[0.22em] text-sky-100/80">Interne</div>
                        <div class="mt-3 text-4xl font-semibold"><?= (int) $stats['internal'] ?></div>
                        <div class="mt-2 text-sm text-slate-200">Collaborateurs et services internes.</div>
                    </article>
                    <article class="rounded-[28px] border border-white/15 bg-white/10 p-5 text-white shadow-inner shadow-white/5 backdrop-blur-sm">
                        <div class="text-xs uppercase tracking-[0.22em] text-sky-100/80">Externe</div>
                        <div class="mt-3 text-4xl font-semibold"><?= (int) ($stats['supplier'] + $stats['provider']) ?></div>
                        <div class="mt-2 text-sm text-slate-200">Fournisseurs et prestataires validés dans le référentiel.</div>
                    </article>
                </div>
            </div>
        </section>

        <section class="mt-8 rounded-[30px] border border-slate-200/70 bg-white/90 p-5 shadow-sm backdrop-blur md:p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.26em] text-slate-500">Navigation rapide</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">Filtrer le répertoire</h2>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button type="button" data-filter="all" class="directory-filter inline-flex items-center gap-2 rounded-2xl border border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm">
                        <span>Tous</span>
                        <span class="rounded-full bg-white/15 px-2 py-0.5 text-xs"><?= (int) $stats['all'] ?></span>
                    </button>
                    <button type="button" data-filter="internal" class="directory-filter inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-sky-200 hover:bg-sky-50">
                        <span>Interne</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs"><?= (int) $stats['internal'] ?></span>
                    </button>
                    <button type="button" data-filter="supplier" class="directory-filter inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-emerald-200 hover:bg-emerald-50">
                        <span>Fournisseurs</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs"><?= (int) $stats['supplier'] ?></span>
                    </button>
                    <button type="button" data-filter="provider" class="directory-filter inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-violet-200 hover:bg-violet-50">
                        <span>Prestataires</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs"><?= (int) $stats['provider'] ?></span>
                    </button>
                </div>
            </div>

            <div class="mt-5 grid gap-4 lg:grid-cols-[1fr_auto_auto]">
                <label class="relative block">
                    <span class="pointer-events-none absolute inset-y-0 left-4 inline-flex items-center text-slate-400">
                        <i class="fas fa-search"></i>
                    </span>
                    <input id="directory-search" type="search" placeholder="Rechercher un nom, un service, un email ou un numéro..." class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-12 py-3.5 text-sm text-slate-800 outline-none transition focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100" />
                </label>
                <select id="directory-reachability" class="rounded-2xl border border-slate-200 bg-white px-4 py-3.5 text-sm text-slate-700 outline-none transition focus:border-sky-300 focus:ring-4 focus:ring-sky-100">
                    <option value="all">Tous les contacts</option>
                    <option value="email">Avec email</option>
                    <option value="phone">Avec téléphone</option>
                    <option value="both">Email + téléphone</option>
                </select>
                <div class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3.5 text-sm font-semibold text-slate-700">
                    <span id="directory-results-count"><?= (int) $stats['all'] ?></span>&nbsp;résultat(s)
                </div>
            </div>
        </section>

        <section class="mt-8">
            <div id="directory-grid" class="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
                <?php foreach ($contacts as $contact):
                    $source = (string) ($contact['source'] ?? 'supplier');
                    $palette = $sourcePalette[$source] ?? $sourcePalette['supplier'];
                    $email = trim((string) ($contact['email'] ?? ''));
                    $phone = trim((string) ($contact['phone'] ?? ''));
                    $normalizedPhone = $normalizePhoneForHref($phone);
                    $detailHref = 'fiche_contact.php?contact=' . rawurlencode((string) ($contact['id'] ?? ''));
                    $mailHref = $email !== ''
                        ? ($canUseMailModule
                            ? 'emails/compose_email.php?to=' . rawurlencode($email) . '&subject=' . rawurlencode('Prise de contact - ' . (string) ($contact['display_name'] ?? ''))
                            : 'mailto:' . rawurlencode($email))
                        : '';
                    $smsHref = $normalizedPhone !== '' ? 'sms:' . rawurlencode($normalizedPhone) : '';
                    $callHref = $normalizedPhone !== '' ? 'tel:' . rawurlencode($normalizedPhone) : '';
                    $displayName = (string) ($contact['display_name'] ?? 'Contact');
                    $organization = trim((string) ($contact['organization'] ?? ''));
                    $contactName = trim((string) ($contact['contact_name'] ?? ''));
                    $meta = trim((string) ($contact['meta'] ?? ''));
                    $searchBlob = (string) ($contact['search_blob'] ?? '');
                    $avatar = $buildInitials($displayName);
                    $photo = trim((string) ($contact['photo'] ?? ''));
                    $photoSrc = '';
                    if ($photo !== '') {
                        $photoSrc = preg_match('#^(https?:)?/#i', $photo) ? $photo : ltrim($photo, '/');
                    }
                ?>
                    <article
                        class="directory-card group relative overflow-hidden rounded-[30px] border border-slate-200/80 bg-white shadow-[0_18px_50px_rgba(15,23,42,0.08)] transition duration-300 hover:-translate-y-1 hover:shadow-[0_26px_60px_rgba(15,23,42,0.12)]"
                        data-source="<?= htmlspecialchars($source, ENT_QUOTES, 'UTF-8') ?>"
                        data-has-email="<?= $email !== '' ? '1' : '0' ?>"
                        data-has-phone="<?= $phone !== '' ? '1' : '0' ?>"
                        data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="absolute inset-x-0 top-0 h-28 bg-gradient-to-br <?= htmlspecialchars($palette['glow'], ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="relative p-6">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex min-w-0 items-center gap-4">
                                    <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br <?= htmlspecialchars($palette['accent'], ENT_QUOTES, 'UTF-8') ?> text-lg font-semibold text-white shadow-lg shadow-slate-900/10">
                                        <?php if ($photoSrc !== ''): ?>
                                            <img src="<?= htmlspecialchars($photoSrc, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>" class="h-full w-full object-cover" />
                                        <?php else: ?>
                                            <?= htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0">
                                        <div class="inline-flex items-center rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.24em] <?= htmlspecialchars($palette['chip'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string) ($contact['source_label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <h3 class="mt-3 truncate text-xl font-semibold text-slate-950"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></h3>
                                        <p class="mt-1 truncate text-sm text-slate-500"><?= htmlspecialchars((string) ($contact['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-6 space-y-3 text-sm">
                                <div class="grid gap-3 rounded-2xl border border-slate-100 bg-slate-50/80 p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <span class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Nom / prénom</span>
                                        <span class="text-right font-medium text-slate-700"><?= htmlspecialchars($contactName !== '' ? $contactName : $displayName, ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="flex items-start justify-between gap-4">
                                        <span class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400"><?= htmlspecialchars((string) ($contact['organization_label'] ?? 'Structure'), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="text-right font-medium text-slate-700"><?= htmlspecialchars($organization !== '' ? $organization : '—', ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="flex items-start justify-between gap-4">
                                        <span class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Téléphone</span>
                                        <span class="text-right font-medium text-slate-700"><?= htmlspecialchars($phone !== '' ? $phone : '—', ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="flex items-start justify-between gap-4">
                                        <span class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Email</span>
                                        <span class="break-all text-right font-medium text-slate-700"><?= htmlspecialchars($email !== '' ? $email : '—', ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>

                                <?php if ($meta !== ''): ?>
                                    <div class="rounded-2xl border border-slate-100 bg-white px-4 py-3 text-slate-600">
                                        <span class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Repère</span>
                                        <div class="mt-1 leading-6"><?= htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                                <a href="<?= htmlspecialchars($detailHref, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-slate-900 px-3 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                                    <i class="fas fa-id-card"></i>
                                    <span>Fiche</span>
                                </a>
                                <?php if ($mailHref !== ''): ?>
                                    <a href="<?= htmlspecialchars($mailHref, ENT_QUOTES, 'UTF-8') ?>" class="directory-track inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm font-semibold text-slate-700 transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-700" data-contact-id="<?= htmlspecialchars((string) ($contact['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-interaction-type="email" data-channel="email" data-subject="Email lancé depuis le carnet d'adresse">
                                        <i class="fas fa-envelope"></i>
                                        <span>Mail</span>
                                    </a>
                                <?php else: ?>
                                    <span class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-100 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-400">
                                        <i class="fas fa-envelope"></i>
                                        <span>Mail</span>
                                    </span>
                                <?php endif; ?>

                                <?php if ($smsHref !== ''): ?>
                                    <a href="<?= htmlspecialchars($smsHref, ENT_QUOTES, 'UTF-8') ?>" class="directory-track inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm font-semibold text-slate-700 transition hover:border-violet-200 hover:bg-violet-50 hover:text-violet-700" data-contact-id="<?= htmlspecialchars((string) ($contact['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-interaction-type="sms" data-channel="sms" data-subject="SMS lancé depuis le carnet d'adresse">
                                        <i class="fas fa-comment-sms"></i>
                                        <span>SMS</span>
                                    </a>
                                <?php else: ?>
                                    <span class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-100 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-400">
                                        <i class="fas fa-comment-sms"></i>
                                        <span>SMS</span>
                                    </span>
                                <?php endif; ?>

                                <?php if ($callHref !== ''): ?>
                                    <a href="<?= htmlspecialchars($callHref, ENT_QUOTES, 'UTF-8') ?>" class="directory-track inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm font-semibold text-slate-700 transition hover:border-emerald-200 hover:bg-emerald-50 hover:text-emerald-700" data-contact-id="<?= htmlspecialchars((string) ($contact['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-interaction-type="call" data-channel="phone" data-subject="Appel lancé depuis le carnet d'adresse">
                                        <i class="fas fa-phone"></i>
                                        <span>Appel</span>
                                    </a>
                                <?php else: ?>
                                    <span class="inline-flex items-center justify-center gap-2 rounded-2xl border border-slate-100 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-400">
                                        <i class="fas fa-phone"></i>
                                        <span>Appel</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div id="directory-empty" class="hidden rounded-[28px] border border-dashed border-slate-300 bg-white/80 px-6 py-14 text-center shadow-sm">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <i class="fas fa-address-book text-2xl"></i>
                </div>
                <h3 class="mt-5 text-xl font-semibold text-slate-900">Aucun contact ne correspond aux filtres</h3>
                <p class="mt-2 text-sm text-slate-500">Modifiez la recherche ou le type de contact pour élargir le répertoire affiché.</p>
            </div>
        </section>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var searchInput = document.getElementById('directory-search');
        var reachability = document.getElementById('directory-reachability');
        var resultCount = document.getElementById('directory-results-count');
        var emptyState = document.getElementById('directory-empty');
        var cards = Array.prototype.slice.call(document.querySelectorAll('.directory-card'));
        var filterButtons = Array.prototype.slice.call(document.querySelectorAll('.directory-filter'));
        var activeFilter = 'all';

        function applyFilters() {
            var search = String((searchInput && searchInput.value) || '').toLowerCase().trim();
            var reachabilityValue = String((reachability && reachability.value) || 'all');
            var visibleCount = 0;

            cards.forEach(function(card) {
                var source = card.getAttribute('data-source') || '';
                var searchBlob = (card.getAttribute('data-search') || '').toLowerCase();
                var hasEmail = card.getAttribute('data-has-email') === '1';
                var hasPhone = card.getAttribute('data-has-phone') === '1';

                var matchesFilter = activeFilter === 'all' || source === activeFilter;
                var matchesSearch = search === '' || searchBlob.indexOf(search) !== -1;
                var matchesReachability = true;

                if (reachabilityValue === 'email') {
                    matchesReachability = hasEmail;
                } else if (reachabilityValue === 'phone') {
                    matchesReachability = hasPhone;
                } else if (reachabilityValue === 'both') {
                    matchesReachability = hasEmail && hasPhone;
                }

                var visible = matchesFilter && matchesSearch && matchesReachability;
                card.classList.toggle('hidden', !visible);
                if (visible) {
                    visibleCount += 1;
                }
            });

            if (resultCount) {
                resultCount.textContent = String(visibleCount);
            }
            if (emptyState) {
                emptyState.classList.toggle('hidden', visibleCount > 0);
            }
        }

        filterButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                activeFilter = button.getAttribute('data-filter') || 'all';
                filterButtons.forEach(function(candidate) {
                    var selected = candidate === button;
                    candidate.classList.toggle('bg-slate-900', selected);
                    candidate.classList.toggle('text-white', selected);
                    candidate.classList.toggle('border-slate-900', selected);
                    candidate.classList.toggle('bg-white', !selected);
                    candidate.classList.toggle('text-slate-700', !selected);
                    candidate.classList.toggle('border-slate-200', !selected);
                });
                applyFilters();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
        }
        if (reachability) {
            reachability.addEventListener('change', applyFilters);
        }

        document.addEventListener('click', function(event) {
            var link = event.target.closest('.directory-track');
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

        applyFilters();
    });
</script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>