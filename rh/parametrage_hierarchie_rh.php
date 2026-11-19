<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhExplanationController.php';

$pdo = \Database::getConnection();
$rhExplanationController = new \RhExplanationController($pdo);
$manager = $rhExplanationController->service();

$statusType = null;
$statusMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postResult = $rhExplanationController->handleHierarchyPost($_POST);
    if (!empty($postResult['redirect'])) {
        header('Location: ' . $postResult['redirect']);
        exit;
    }
    $statusType = $postResult['statusType'];
    $statusMessage = $postResult['statusMessage'];
}
if ($statusMessage === null) {
    if (isset($_GET['saved'])) {
        $statusType = 'success';
        $statusMessage = 'Paramètres du workflow enregistrés.';
    } elseif (isset($_GET['updated'])) {
        $statusType = 'success';
        $statusMessage = 'Hiérarchie de service mise à jour.';
    }
}

$settings = $manager->getSettings();
$users = $manager->getActiveUsers();
$rows = $manager->getServiceHierarchyRows();

$pageTitle = 'Hiérarchie RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<style>
    .hierarchy-scroll-zone {
        max-height: 980px;
        overflow-y: auto;
        padding-right: 0.35rem;
    }

    .hierarchy-scroll-zone::-webkit-scrollbar {
        width: 10px;
    }

    .hierarchy-scroll-zone::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, #94a3b8 0%, #64748b 100%);
        border-radius: 9999px;
    }

    .hierarchy-service-card {
        border: 1px solid rgba(148, 163, 184, 0.22);
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.98) 0%, rgba(248, 250, 252, 0.94) 100%);
        box-shadow: 0 18px 40px -30px rgba(15, 23, 42, 0.5);
    }

    .hierarchy-service-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        border-radius: 9999px;
        border: 1px solid rgba(99, 102, 241, 0.18);
        background: rgba(238, 242, 255, 0.9);
        padding: 0.45rem 0.9rem;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: #4338ca;
    }

    .hierarchy-section-title {
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: #64748b;
    }

    .hierarchy-select+.select2-container {
        width: 100% !important;
    }

    .hierarchy-select+.select2-container .select2-selection--single {
        height: 50px;
        border-radius: 1rem;
        border: 1px solid #cbd5e1;
        background: #fff;
        padding: 0.55rem 0.9rem;
        display: flex;
        align-items: center;
        box-shadow: inset 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .hierarchy-select+.select2-container .select2-selection__rendered {
        color: #0f172a !important;
        line-height: 1.4 !important;
        padding-left: 0 !important;
    }

    .hierarchy-select+.select2-container .select2-selection__arrow {
        height: 48px !important;
        right: 10px !important;
    }

    .select2-dropdown.hierarchy-select-dropdown {
        border-radius: 1rem;
        border-color: #cbd5e1;
        overflow: hidden;
        box-shadow: 0 22px 45px -30px rgba(15, 23, 42, 0.45);
    }

    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background: #4338ca;
    }

    @media (max-width: 767.98px) {
        .hierarchy-scroll-zone {
            max-height: 72vh;
            padding-right: 0.1rem;
        }

        .hierarchy-service-card {
            border-radius: 1.25rem;
        }

        .hierarchy-service-chip {
            padding: 0.32rem 0.7rem;
            font-size: 0.64rem;
            letter-spacing: 0.12em;
        }

        .hierarchy-section-title {
            font-size: 0.68rem;
            letter-spacing: 0.12em;
        }

        .hierarchy-select+.select2-container .select2-selection--single {
            height: 44px;
            border-radius: 0.85rem;
            padding: 0.42rem 0.78rem;
        }

        .hierarchy-select+.select2-container .select2-selection__arrow {
            height: 42px !important;
        }
    }
</style>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(241,245,249,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(199,210,254,0.75),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="relative overflow-hidden rounded-[32px] border border-indigo-100 bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-indigo-200">Ressources humaines</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Hiérarchie et modèle RH</h1>
                    <p class="mt-4 max-w-3xl text-sm text-slate-200 md:text-base">Paramétrez le délai, les copies hiérarchiques et le modèle généré automatiquement dans le formulaire de demande d’explications.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="organigramme_rh.php" class="inline-flex items-center rounded-2xl bg-emerald-400 px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-emerald-300"><i class="fas fa-sitemap mr-2"></i>Voir l'organigramme</a>
                    <a href="demandes_explication_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 hover:bg-indigo-50"><i class="fas fa-comments mr-2 text-indigo-600"></i>Demandes d’explications</a>
                    <a href="parametrage_rh.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 hover:bg-white/10"><i class="fas fa-cog mr-2"></i>Paramétrage RH</a>
                </div>
            </div>
        </div>

        <?php if ($statusType !== null && $statusMessage !== null): ?>
            <div class="mt-6 rounded-2xl border px-5 py-4 text-sm font-medium <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                <?= htmlspecialchars((string) $statusMessage) ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1fr_1fr]">
            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Workflow global</p>
                <h2 class="mt-2 text-2xl font-semibold text-slate-950">Paramètres centraux</h2>
                <form method="post" class="mt-6 space-y-5">
                    <input type="hidden" name="action" value="save_settings">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Directeur général</label>
                        <select name="dg_user_id" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white">
                            <option value="0">Sélectionner</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= (int) $user['id'] ?>" <?= (int) ($settings['dg_user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $user['name']) ?><?= !empty($user['email']) ? ' • ' . htmlspecialchars((string) $user['email']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Délai maximum de réponse</label>
                        <input type="number" min="1" max="15" name="response_due_days" value="<?= (int) ($settings['response_due_days'] ?? 3) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white">
                        <p class="mt-2 text-xs text-slate-500">En jours ouvrés. Valeur par défaut du formulaire RH.</p>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Texte d’introduction</label>
                        <textarea name="template_intro" rows="4" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white"><?= htmlspecialchars((string) ($settings['template_intro'] ?? '')) ?></textarea>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Libellé contexte</label>
                            <input type="text" name="template_context_label" value="<?= htmlspecialchars((string) ($settings['template_context_label'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Libellé faits</label>
                            <input type="text" name="template_facts_label" value="<?= htmlspecialchars((string) ($settings['template_facts_label'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Libellé explications attendues</label>
                            <input type="text" name="template_expectations_label" value="<?= htmlspecialchars((string) ($settings['template_expectations_label'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Libellé éléments complémentaires</label>
                            <input type="text" name="template_supporting_label" value="<?= htmlspecialchars((string) ($settings['template_supporting_label'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white">
                        </div>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Texte de clôture du modèle</label>
                        <textarea name="template_closing" rows="3" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white"><?= htmlspecialchars((string) ($settings['template_closing'] ?? '')) ?></textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Aide pour la réponse employé</label>
                        <textarea name="employee_response_hint" rows="3" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:bg-white"><?= htmlspecialchars((string) ($settings['employee_response_hint'] ?? '')) ?></textarea>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600">
                        Variables disponibles dans les textes : {collaborateur}, {service}, {fonction}, {delai_jours}. Les acteurs RH en copie restent déduits automatiquement des accès RH actifs.
                    </div>
                    <button type="submit" class="inline-flex items-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700">Enregistrer</button>
                </form>
            </section>

            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Hiérarchie</p>
                <h2 class="mt-2 text-2xl font-semibold text-slate-950">Responsables par service</h2>
                <p class="mt-3 text-sm text-slate-600">Chaque service est présenté sur trois niveaux: identité du service, champs de sélection, puis action de validation. La liste reste dans un conteneur à hauteur fixe pour préserver la lisibilité de la page.</p>
                <div class="mt-5 hierarchy-scroll-zone space-y-3 sm:space-y-4">
                    <?php foreach ($rows as $row): ?>
                        <form method="post" class="hierarchy-service-card rounded-[22px] p-4 sm:rounded-[24px] sm:p-5 lg:rounded-[28px] lg:p-6">
                            <input type="hidden" name="action" value="save_service">
                            <input type="hidden" name="service_id" value="<?= (int) $row['service_id'] ?>">
                            <div class="flex flex-col gap-4 sm:gap-5">
                                <div class="rounded-[22px] border border-indigo-100 bg-gradient-to-r from-indigo-50 via-white to-slate-50 px-4 py-3 sm:rounded-3xl sm:px-5 sm:py-4">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-3">
                                        <div>
                                            <div class="hierarchy-service-chip"><i class="fas fa-sitemap"></i> Service</div>
                                            <div class="mt-2 text-lg font-semibold text-slate-950 sm:mt-3 sm:text-xl"><?= htmlspecialchars((string) $row['service_label']) ?></div>
                                        </div>
                                        <div class="hidden rounded-2xl border border-slate-200 bg-white/90 px-4 py-3 text-sm text-slate-600 shadow-sm sm:block">
                                            <div class="hierarchy-section-title">Nom du service</div>
                                            <div class="mt-1 font-semibold text-slate-900"><?= htmlspecialchars((string) $row['service_label']) ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="grid gap-3 sm:gap-4 xl:grid-cols-3">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Responsable direct</label>
                                        <select name="direct_manager_user_id" class="hierarchy-select w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" data-placeholder="Rechercher un responsable direct">
                                            <option value="0">Non défini</option>
                                            <?php foreach ($users as $user): ?>
                                                <option value="<?= (int) $user['id'] ?>" <?= (int) ($row['direct_manager_user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $user['name']) ?><?= !empty($user['email']) ? ' • ' . htmlspecialchars((string) $user['email']) : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Chef de département</label>
                                        <select name="department_head_user_id" class="hierarchy-select w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" data-placeholder="Rechercher un directeur de département">
                                            <option value="0">Non défini</option>
                                            <?php foreach ($users as $user): ?>
                                                <option value="<?= (int) $user['id'] ?>" <?= (int) ($row['department_head_user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $user['name']) ?><?= !empty($user['email']) ? ' • ' . htmlspecialchars((string) $user['email']) : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Signataire contractuel du service</label>
                                        <select name="contract_signer_user_id" class="hierarchy-select w-full rounded-2xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm outline-none focus:border-amber-500" data-placeholder="Rechercher le signataire des contrats">
                                            <option value="0">Non défini</option>
                                            <?php foreach ($users as $user): ?>
                                                <option value="<?= (int) $user['id'] ?>" <?= (int) ($row['contract_signer_user_id'] ?? 0) === (int) $user['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $user['name']) ?><?= !empty($user['email']) ? ' • ' . htmlspecialchars((string) $user['email']) : '' ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="mt-2 text-xs text-slate-500">Personne habilitée à signer la partie de ce service dans les contrats.</p>
                                    </div>
                                </div>

                                <div class="flex justify-end pt-0.5 sm:pt-1">
                                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 sm:w-auto sm:px-5 sm:py-3"><i class="fas fa-check mr-2"></i>Sauvegarder la hiérarchie</button>
                                </div>
                            </div>
                        </form>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) {
            return;
        }

        window.jQuery('.hierarchy-select').each(function() {
            const placeholder = this.getAttribute('data-placeholder') || 'Rechercher';
            window.jQuery(this).select2({
                width: '100%',
                placeholder: placeholder,
                allowClear: false,
                dropdownCssClass: 'hierarchy-select-dropdown'
            });
        });
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
