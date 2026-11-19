<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
$pdo = null;
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
$pageTitle = 'Traitement comptable des décaissements - Finéa';
$activeMenu = 'compta';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$pdo = Database::getConnection();
AuditTrail::log('page_view', [
    'module' => 'decaissement',
    'action' => 'liste_fiches_compta_view',
    'entity_type' => 'fiche_compta_list',
    'entity_label' => 'Liste fiches compta',
    'page_name' => 'liste_fiches_compta.php',
    'result' => 'success',
    'details' => [
        'mode' => $_GET['mode'] ?? null,
        'status_filter' => $_GET['status_filter'] ?? null,
        'view_mode' => 'browser_dashboard',
    ],
], $pdo);
?>
<style>
    /* Pagination & bottom sheet & tabs */
    #paginationBar button:disabled {
        opacity: .35;
        cursor: not-allowed
    }

    .bottom-sheet {
        animation: sheetUp .25s ease-out;
    }

    @keyframes sheetUp {
        from {
            transform: translateY(35px);
            opacity: 0
        }

        to {
            transform: translateY(0);
            opacity: 1
        }
    }

    .tab-pill {
        background: #f1f5f9;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: 500;
        transition: .2s;
        font-size: 11px;
    }

    .tab-pill.active {
        background: #4f46e5;
        color: #fff;
    }

    .tab-pill:not(.active):hover {
        background: #e2e8f0
    }

    .badge-system {
        background: #0f766e;
        color: #fff;
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        gap: 4px
    }

    .badge-system svg {
        width: 12px;
        height: 12px
    }

    .card {
        border-radius: 1rem
    }

    /* --- Modals overlay custom (sans Bootstrap JS) --- */
    .modal {
        position: fixed;
        inset: 0;
        display: none;
        z-index: 1050;
        overflow-y: auto;
        overflow-x: hidden;
        background: rgba(0, 0, 0, .45);
        padding: 20px 10px;
    }

    .modal.show {
        display: block;
    }

    .modal-dialog {
        position: relative;
        margin: 40px auto;
        max-width: 1200px;
        pointer-events: none;
        transform: translateY(-6px);
        opacity: .92;
        transition: all .18s ease-out;
    }

    .modal.show .modal-dialog {
        transform: translateY(0);
        opacity: 1;
    }

    .modal-dialog.modal-sm {
        max-width: 430px;
    }

    .modal-dialog.modal-md {
        max-width: 680px;
    }

    .modal-dialog.modal-xl {
        max-width: 1280px;
    }

    .modal-content {
        pointer-events: auto;
        background: #fff;
        border-radius: 18px;
        box-shadow: 0 12px 32px -6px rgba(0, 0, 0, .35);
        border: 1px solid #e2e8f0;
    }

    .modal-header {
        padding: 14px 20px;
        border-bottom: 1px solid #e2e8f0;
    }

    .modal-body {
        padding: 18px 22px;
    }

    .modal-footer {
        padding: 12px 18px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        border-bottom-left-radius: 18px;
        border-bottom-right-radius: 18px;
    }

    body.overflow-hidden {
        overflow: hidden;
    }
</style>
<div class="container mx-auto py-10 px-4">
    <div class="flex items-center justify-between mb-6">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-indigo-500">Circuit de décaissement</p>
            <h1 class="text-3xl font-bold text-indigo-700">Traitement comptable</h1>
            <p class="mt-1 text-sm text-slate-500">Fiches prêtes à être contrôlées puis décaissées. Les PV et preuves de paiement sont demandés uniquement après le paiement, dans la file de régularisation.</p>
            <a href="regularisations_decaissements.php" class="mt-3 inline-flex items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-4 py-2 text-xs font-bold text-violet-800 hover:bg-violet-100">
                <i class="fa-solid fa-arrow-right"></i> Ouvrir les régularisations post-décaissement
            </a>
        </div>
        <button id="btnAideGlobal" class="btn btn-soft-info text-sm flex items-center gap-2"><span>🛈</span> Aide</button>
    </div>

    <form id="searchForm" class="bg-white rounded-xl shadow p-6 mb-8 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 items-end text-xs">
        <div>
            <label class="font-semibold text-gray-600 mb-1 block">Num fiche</label>
            <input name="num_fiche" class="form-input border-gray-300" placeholder="FD-..." />
        </div>
        <div>
            <label class="font-semibold text-gray-600 mb-1 block">Bénéficiaire</label>
            <input name="beneficiaire" class="form-input border-gray-300" placeholder="Nom..." />
        </div>
        <div>
            <label class="font-semibold text-gray-600 mb-1 block">Désignation</label>
            <input name="designation" class="form-input border-gray-300" placeholder="Texte..." />
        </div>
        <div>
            <label class="font-semibold text-gray-600 mb-1 block">Précision</label>
            <input name="precision" class="form-input border-gray-300" placeholder="Précision" />
        </div>
        <div>
            <label class="font-semibold text-gray-600 mb-1 block">Chantier / Code</label>
            <input name="chantier" class="form-input border-gray-300" placeholder="Chantier" />
        </div>
        <div>
            <label class="font-semibold text-gray-600 mb-1 block">Entreprise</label>
            <input name="entreprise" class="form-input border-gray-300" placeholder="BANAMUR" />
        </div>
        <div class="flex gap-2">
            <div class="flex-1">
                <label class="font-semibold text-gray-600 mb-1 block">Montant min</label>
                <input type="number" step="0.01" name="montant_min" class="form-input border-gray-300" />
            </div>
            <div class="flex-1">
                <label class="font-semibold text-gray-600 mb-1 block">Montant max</label>
                <input type="number" step="0.01" name="montant_max" class="form-input border-gray-300" />
            </div>
        </div>
        <div class="flex gap-2">
            <div class="flex-1">
                <label class="font-semibold text-gray-600 mb-1 block">Créée min</label>
                <input type="date" name="date_min" class="form-input border-gray-300" />
            </div>
            <div class="flex-1">
                <label class="font-semibold text-gray-600 mb-1 block">Créée max</label>
                <input type="date" name="date_max" class="form-input border-gray-300" />
            </div>
        </div>
        <div class="flex gap-2">
            <div class="flex-1">
                <label class="font-semibold text-gray-600 mb-1 block">Validée min</label>
                <input type="date" name="date_valid_min" class="form-input border-gray-300" />
            </div>
            <div class="flex-1">
                <label class="font-semibold text-gray-600 mb-1 block">Validée max</label>
                <input type="date" name="date_valid_max" class="form-input border-gray-300" />
            </div>
        </div>
        <div>
            <label class="font-semibold text-gray-600 mb-1 block">Affectation</label>
            <select name="affectation" class="form-select border-gray-300">
                <option value="">Toutes</option>
                <option value="chantier">Chantier</option>
                <option value="bureau">Bureau</option>
                <option value="autre">Autre</option>
            </select>
        </div>
        <div>
            <label class="font-semibold text-gray-600 mb-1 block">Filtre statut</label>
            <select name="status_filter" class="form-select border-gray-300">
                <option value="">Décais. immédiats</option>
                <option value="reporte">Reportées</option>
                <option value="partiels_parents">Parents partiels</option>
                <option value="decaissees">Décaissées</option>
            </select>
        </div>
        <div class="col-span-full flex gap-2 pt-2">
            <button type="submit" class="btn btn-primary btn-sm">Rechercher</button>
            <button type="button" id="resetBtn" class="btn btn-ghost btn-sm">Réinitialiser</button>
            <button type="button" id="exportCsv" class="btn btn-soft-secondary btn-sm">Export CSV</button>
            <button type="button" id="exportPdf" class="btn btn-soft-primary btn-sm"><i class="fa-solid fa-file-pdf mr-1"></i>Export PDF Finéa</button>
        </div>
    </form>

    <div class="bg-white rounded-xl shadow p-4 mb-6 text-xs" id="bulkDecaissementWrap">
        <div class="flex items-center justify-between gap-3 mb-3">
            <div class="font-semibold text-gray-700">Décaissement de masse</div>
            <div class="text-[11px] text-gray-500">Décaisse uniquement les fiches prêtes (conformes + approuvées, date mini atteinte)</div>
        </div>
        <form id="bulkDecaisseForm" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
            <div>
                <label class="font-semibold text-gray-600 mb-1 block">Par</label>
                <select id="bulk_mode" name="mode" class="form-select border-gray-300">
                    <option value="chantier">Chantier / Code</option>
                    <option value="beneficiaire">Individu (Bénéficiaire)</option>
                    <option value="date">Date (création)</option>
                </select>
            </div>
            <div id="bulk_field_chantier">
                <label class="font-semibold text-gray-600 mb-1 block">Chantier</label>
                <div class="relative">
                    <input id="bulk_chantier" name="chantier" class="form-input border-gray-300" placeholder="Code ou nom chantier..." autocomplete="off" />
                    <div id="bulk_chantier_suggestions" class="hidden absolute z-50 left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow max-h-56 overflow-auto"></div>
                </div>
                <div id="bulk_chantier_selected" class="mt-1 text-[11px] text-gray-500 hidden"></div>
            </div>
            <div id="bulk_field_beneficiaire" class="hidden">
                <label class="font-semibold text-gray-600 mb-1 block">Bénéficiaire</label>
                <input id="bulk_beneficiaire" name="beneficiaire" class="form-input border-gray-300" placeholder="Nom..." />
            </div>
            <div id="bulk_field_date" class="hidden">
                <label class="font-semibold text-gray-600 mb-1 block">Date</label>
                <input type="date" id="bulk_date" name="date" class="form-input border-gray-300" />
            </div>
            <div>
                <label class="font-semibold text-gray-600 mb-1 block">Limite</label>
                <input type="number" id="bulk_max" name="max" class="form-input border-gray-300" value="200" min="1" max="500" />
                <div class="mt-2 flex items-start gap-2">
                    <input type="checkbox" id="bulk_definitif" class="h-4 w-4 mt-0.5" />
                    <label for="bulk_definitif" class="text-[11px] text-gray-600 leading-snug">
                        Décaissement définitif (annulation impossible)
                    </label>
                </div>
            </div>
            <div class="flex gap-2 justify-end">
                <button type="submit" id="bulk_submit" class="btn btn-primary btn-sm">Décaisser en masse</button>
            </div>
        </form>
        <div id="bulkDecaisseResult" class="mt-3 hidden rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px]">
            <div class="flex items-center justify-between gap-2">
                <div class="font-semibold text-slate-800" id="bulk_status">En attente</div>
                <div class="text-slate-600" id="bulk_counts">0 / 0</div>
            </div>
            <div class="mt-2 w-full h-2 rounded bg-gray-200 overflow-hidden">
                <div id="bulk_bar" class="h-full bg-indigo-600" style="width:0%"></div>
            </div>
            <div id="bulk_logs" class="mt-2 max-h-48 overflow-auto text-[10px] leading-snug font-mono bg-white border border-slate-200 rounded p-2"></div>
            <div class="mt-2 text-[10px] text-slate-500" id="bulk_note"></div>
        </div>
    </div>

    <div class="flex flex-wrap items-center justify-between mb-4">
        <div>
            <span class="font-bold text-indigo-700" id="nbFiches">0</span> résultat(s)
            &nbsp;|&nbsp;
            Montant total : <span class="font-bold text-green-600" id="totalMontant">0</span> FCFA
        </div>
        <div class="flex items-center gap-2 text-[11px]" id="paginationBar" style="display:none">
            <button type="button" class="btn btn-ghost btn-xs" id="prevPage">◀</button>
            <span id="pageInfo"></span>
            <button type="button" class="btn btn-ghost btn-xs" id="nextPage">▶</button>
            <select id="perPage" class="form-select border-gray-300 text-[11px] h-7">
                <option value="12">12</option>
                <option value="24" selected>24</option>
                <option value="48">48</option>
                <option value="96">96</option>
            </select>
        </div>
    </div>

    <div id="cards-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8"></div>

    <div id="modalCompta" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content bottom-sheet">
                <div class="modal-header flex justify-between items-start gap-4 mb-0 pb-0 border-b">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold text-indigo-700 m-0">Fiche & Actions</h2>
                        <div id="modalWorkflowBreadcrumb" class="mt-2 text-[11px] text-slate-500"></div>
                    </div>
                    <button type="button" class="btn btn-danger-light text-xs" data-close aria-label="Fermer">✕</button>
                </div>
                <div class="modal-body">
                    <div id="modalBodyCompta" class="text-sm"></div>
                    <div class="mt-4">
                        <div class="flex gap-2 flex-wrap" id="tabsActions">
                            <button class="tab-pill active" data-tab="resume">Résumé</button>
                            <button class="tab-pill" data-tab="analyse">Analyse</button>
                            <button class="tab-pill" data-tab="famille">Famille</button>
                            <button class="tab-pill" data-tab="traces">Historique</button>
                            <button class="tab-pill" data-tab="hierarchie">Parent/Enfants</button>
                        </div>
                        <div id="tabContent" class="mt-3 space-y-4 text-[12px]"></div>
                    </div>
                </div>
                <div class="modal-footer py-2 flex flex-col gap-2">
                    <div id="modalActionsFooter" class="flex flex-wrap gap-2 justify-end w-full"></div>
                    <div class="w-full flex justify-between items-center">
                        <button type="button" class="btn btn-soft-secondary btn-xs" data-close>Fermer</button>
                        <span class="text-[10px] text-gray-400 pr-1">Actions fiche</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="modalPartiel" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header flex justify-between items-center mb-0">
                    <h2 class="text-lg font-semibold text-indigo-700 m-0">Décaissement partiel</h2>
                    <button type="button" class="btn btn-danger-light text-xs" data-close aria-label="Fermer">✕</button>
                </div>
                <div class="modal-body">
                    <form id="formPartiel" class="space-y-4">
                        <input type="hidden" name="num_fiche" id="partiel_num_fiche" />
                        <input type="hidden" id="partiel_total_raw" />
                        <div>
                            <label class="text-xs font-semibold text-gray-600">Montant total fiche</label>
                            <div id="partiel_total" class="font-bold"></div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Montant à décaisser maintenant</label>
                            <input type="number" step="0.01" min="0" name="montant" id="partiel_montant" class="form-input w-full" required />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Date prochaine (report)</label>
                            <input type="date" name="date_suivante" id="partiel_date" class="form-input w-full" required />
                        </div>
                        <div class="rounded-lg border border-amber-100 bg-amber-50/70 p-3 text-xs text-slate-700">
                            <div class="font-semibold text-amber-800">PV de reception partielle</div>
                            <p class="mt-1">A partir du deuxieme decaissement, indiquez l'avancement et deux validateurs distincts ayant atteste la reception.</p>
                            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Avancement (%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="progress_rate" id="partiel_progress_rate" class="form-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Reference PV / observation</label>
                                    <input type="text" name="pv_reception_reference" id="partiel_pv_reference" class="form-input w-full" placeholder="PV, livraison, travaux..." />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Validateur 1</label>
                                    <input type="text" name="pv_validator_one" id="partiel_validator_one" class="form-input w-full" />
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-600 mb-1">Validateur 2</label>
                                    <input type="text" name="pv_validator_two" id="partiel_validator_two" class="form-input w-full" />
                                </div>
                            </div>
                        </div>
                        <div id="partiel_advance_suggestion" class="hidden rounded-xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-indigo-50 p-4 text-xs text-slate-700">
                            <div class="flex items-start justify-between gap-3">
                                <div><div class="font-bold text-emerald-800">Suggestion d’amortissement de l’avance</div><p class="mt-1 text-[11px] text-slate-600">Aide non bloquante destinée à la Direction et à la comptabilité.</p></div>
                                <button type="button" id="partiel_apply_suggestion" class="rounded-lg bg-emerald-600 px-3 py-2 text-[10px] font-bold text-white">Utiliser la suggestion</button>
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <div><span class="text-[10px] uppercase text-slate-500">Avance</span><b id="partiel_advance_paid" class="block"></b></div>
                                <div><span class="text-[10px] uppercase text-slate-500">Déjà payé</span><b id="partiel_already_paid" class="block"></b></div>
                                <div><span class="text-[10px] uppercase text-slate-500">Cumul cible</span><b id="partiel_target_paid" class="block"></b></div>
                                <div><span class="text-[10px] uppercase text-slate-500">Montant suggéré</span><b id="partiel_suggested_payment" class="block text-lg text-emerald-700"></b></div>
                            </div>
                            <p id="partiel_suggestion_formula" class="mt-3 rounded-lg bg-white/80 p-2 text-[10px] text-slate-600"></p>
                        </div>
                        <div id="partiel_preview" class="rounded-lg border border-indigo-100 bg-indigo-50/70 p-3 text-[11px] text-slate-700">
                            <div class="font-semibold text-indigo-700">Aperçu du reliquat enfant</div>
                            <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <div>
                                    <div class="text-[10px] uppercase tracking-[0.08em] text-slate-500">Numéro prévu</div>
                                    <div id="partiel_child_num" class="font-semibold text-slate-800">-</div>
                                </div>
                                <div>
                                    <div class="text-[10px] uppercase tracking-[0.08em] text-slate-500">Montant reliquat</div>
                                    <div id="partiel_child_amount" class="font-semibold text-slate-800">-</div>
                                </div>
                            </div>
                            <p id="partiel_child_note" class="mt-2 text-[11px] text-slate-600">Saisissez un montant partiel et une date pour prévisualiser la fiche enfant qui sera créée.</p>
                        </div>
                        <p id="partiel_error" class="text-red-600 text-xs hidden"></p>
                        <div class="text-right pt-2 flex gap-2 justify-end">
                            <button type="button" class="btn btn-ghost" data-close>Annuler</button>
                            <button type="submit" class="btn btn-primary">Valider</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal décaissement total -->
    <div id="modalDecaisserTotal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header flex justify-between items-center mb-0">
                    <h2 class="text-lg font-semibold text-indigo-700 m-0">Décaisser</h2>
                    <button type="button" class="btn btn-danger-light btn-xs" data-close aria-label="Fermer">✕</button>
                </div>
                <div class="modal-body">
                    <form id="formDecaisserTotal" class="space-y-4">
                        <input type="hidden" name="num_fiche" id="decaisser_num_fiche" />
                        <div class='text-xs text-gray-600'>Confirmer le décaissement total de:</div>
                        <div class='p-2 rounded bg-indigo-50 text-center font-semibold text-indigo-700 text-sm' id='decaisser_montant'></div>
                        <div class='text-[11px] text-gray-500' id='decaisser_infos'></div>
                        <p id="decaisser_error" class="text-red-600 text-xs hidden"></p>
                        <div class='flex justify-end gap-2 pt-1'>
                            <button type='button' class='btn btn-ghost btn-xs' data-close>Annuler</button>
                            <button type='submit' class='btn btn-primary btn-xs'>Confirmer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal reporter -->
    <div id="modalReporter" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header flex justify-between items-center mb-0">
                    <h2 class="text-lg font-semibold text-indigo-700 m-0">Reporter</h2>
                    <button type="button" class="btn btn-danger-light btn-xs" data-close aria-label="Fermer">✕</button>
                </div>
                <div class="modal-body">
                    <form id="formReporter" class="space-y-4">
                        <input type="hidden" name="num_fiche" id="reporter_num_fiche" />
                        <div>
                            <label class='text-xs font-semibold text-gray-600 mb-1 block'>Nouvelle date minimale</label>
                            <input type='date' name='date_min' id='reporter_date_min' class='form-input w-full text-xs' required />
                        </div>
                        <p class='text-[11px] text-gray-500'>La fiche restera bloquée jusqu'à cette date.</p>
                        <p id='reporter_error' class='text-red-600 text-xs hidden'></p>
                        <div class='flex justify-end gap-2 pt-1'>
                            <button type='button' class='btn btn-ghost btn-xs' data-close>Annuler</button>
                            <button type='submit' class='btn btn-secondary btn-xs'>Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal validation achat -->
    <div id="modalValiderAchat" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header flex justify-between items-center mb-0">
                    <h2 class="text-lg font-semibold text-emerald-700 m-0">Valider Achat</h2>
                    <button type="button" class="btn btn-danger-light btn-xs" data-close aria-label="Fermer">✕</button>
                </div>
                <div class="modal-body">
                    <form id="formValiderAchat" class='space-y-3'>
                        <input type='hidden' name='num_fiche' id='valachat_num_fiche'>
                        <div class='text-xs text-gray-600'>Validation de la fiche suivante :</div>
                        <div id='valachat_resume' class='p-2 rounded bg-emerald-50 border border-emerald-200 text-[11px] leading-snug'></div>
                        <p class='text-[11px] text-gray-500'>Cette action confirme l'étape d'achat et prépare la fiche pour décaissement.</p>
                        <p id='valachat_error' class='text-red-600 text-xs hidden'></p>
                        <div class='flex justify-end gap-2 pt-1'>
                            <button type='button' class='btn btn-ghost btn-xs' data-close>Annuler</button>
                            <button type='submit' class='btn btn-soft-emerald btn-xs'>Valider</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const isDemo = (typeof getQueryParam === 'function' && getQueryParam('demo') === '1');
    window.currencyFormatCFA = window.currencyFormatCFA || function(n) {
        try {
            return (Number(n) || 0).toLocaleString('fr-FR') + ' CFA';
        } catch {
            return n + ' CFA';
        }
    };

    function workflowBaseNum(f) {
        return (f && (f.workflow_base_num_fiche || f.parent_code || f.num_fiche)) || '';
    }

    function hasBonCommande(f) {
        if (!f) return false;
        const idOk = !!(f.bc_id && parseInt(f.bc_id, 10) > 0);
        const numOk = !!(f.bc_number && String(f.bc_number).trim() !== '');
        return idOk || numOk;
    }

    function hasBonReception(f) {
        return !!(f && f.br_id && parseInt(f.br_id, 10) > 0);
    }

    function isBlockedByMissingBR(f) {
        return hasBonCommande(f) && !hasBonReception(f);
    }

    function bonReceptionUrlForFiche(f) {
        const base = workflowBaseNum(f) || (f ? (f.num_fiche || '') : '');
        return 'bon_reception.php?num_fiche=' + encodeURIComponent(base);
    }

    function setLoading(state) {
        const wrap = document.getElementById('cards-container');
        if (!state) {
            wrap.querySelectorAll('.skeleton').forEach(el => el.remove());
            return;
        }
        wrap.innerHTML = '';
        for (let i = 0; i < 8; i++) {
            const sk = document.createElement('div');
            sk.className = 'card p-6 bg-white rounded-2xl shadow skeleton';
            sk.style.height = '180px';
            wrap.appendChild(sk);
        }
    }

    let fichesData = {};
    let currentPage = 1;
    let totalPages = 1;
    let perPageValue = 24;
    async function fetchAndRender(resetPage = false) {
        if (resetPage) currentPage = 1;
        const form = document.getElementById('searchForm');
        const params = new URLSearchParams(new FormData(form));
        params.set('page', currentPage.toString());
        params.set('per_page', perPageValue.toString());
        const sf = params.get('status_filter');
        if (sf) {
            params.set('mode', 'all');
        }
        const endpoint = isDemo ? ('request/demo_fiches.php?type=compta&' + params.toString()) : ('request/fiches_compta_list.php?' + params.toString());
        setLoading(true);
        try {
            const res = await fetch(endpoint);

            // Vérifier si la réponse est OK (status 200-299)
            if (!res.ok) {
                if (res.status === 401 || res.status === 403) {
                    try {
                        const json = await res.json();
                        if (json.redirect) {
                            window.location.href = json.redirect;
                            return;
                        }
                    } catch (e) {
                        // Si ce n'est pas du JSON, rediriger vers login
                        window.location.href = '/login.php';
                        return;
                    }
                }
                throw new Error(`Erreur HTTP ${res.status}: ${res.statusText}`);
            }

            const json = await res.json();

            // Vérifier si c'est une erreur d'authentification même avec status 200
            if (json.auth_required || (!json.ok && json.redirect)) {
                if (json.redirect) {
                    window.location.href = json.redirect;
                    return;
                }
            }

            if (!json.ok) {
                alert('Erreur: ' + (json.error || 'Erreur inconnue'));
                return;
            }
            document.getElementById('nbFiches').textContent = json.count || (json.items?.length || 0);
            document.getElementById('totalMontant').textContent = (json.total || 0).toLocaleString('fr-FR');
            if (json.page) {
                currentPage = json.page;
                totalPages = json.pages || 1;
                perPageValue = json.per_page || perPageValue;
                updatePaginationBar();
            } else {
                totalPages = 1;
                updatePaginationBar();
            }
            const wrap = document.getElementById('cards-container');
            if (!json.items || json.items.length === 0) {
                wrap.innerHTML = `<div class='col-span-full text-center text-sm text-gray-500 py-10'>Aucune fiche trouvée.<br/><span class='text-[11px] text-gray-400'>Ajustez vos filtres.</span></div>`;
            } else {
                fichesData = {};
                json.items.forEach(it => fichesData[it.num_fiche] = it);
                wrap.innerHTML = json.items.map(cardTemplate).join('');
            }
            bindEvents(wrap);
            initPhotos(wrap);
            loadAnalysesForCards();
        } catch (e) {
            console.error('Erreur lors du chargement:', e);
            if (e.message && e.message.includes('HTTP 401')) {
                // Session expirée
                window.location.href = '/login.php';
            } else if (e.message && e.message.includes('HTTP 403')) {
                // Accès refusé
                alert('Accès refusé. Vous serez redirigé vers le portail.');
                window.location.href = '/selection_portail.php';
            } else {
                alert('Erreur réseau chargement: ' + (e.message || 'Erreur inconnue'));
            }
        } finally {
            setLoading(false);
        }
    }

    function updatePaginationBar() {
        const bar = document.getElementById('paginationBar');
        if (!bar) return;
        bar.style.display = totalPages > 1 ? 'flex' : 'none';
        const pi = document.getElementById('pageInfo');
        if (pi) pi.textContent = `Page ${currentPage} / ${totalPages}`;
        const prev = document.getElementById('prevPage');
        if (prev) prev.disabled = currentPage <= 1;
        const next = document.getElementById('nextPage');
        if (next) next.disabled = currentPage >= totalPages;
        const pp = document.getElementById('perPage');
        if (pp) pp.value = perPageValue;
    }

    function cardTemplate(f) {
        const futureDate = f.date_decaissement_minimum && new Date(f.date_decaissement_minimum) > new Date();
        const notValidated = parseInt(f.etat_fiche || 0) === 0;
        const decaisse = parseInt(f.decaisse || 0) === 1;
        const definitif = parseInt(f.decaisse_definitif || 0) === 1;
        const blockedByBR = isBlockedByMissingBR(f);
        const badges = [];
        if (futureDate) badges.push(`<span class='px-2 py-0.5 rounded bg-amber-100 text-amber-700 text-[10px]'>Reporté</span>`);
        if (notValidated) badges.push(`<span class='px-2 py-0.5 rounded bg-sky-100 text-sky-700 text-[10px]'>Pré-achat</span>`);
        if (decaisse) {
            if (parseFloat(f.montant_initial_fiche || 0) > parseFloat(f.montant_fiche || 0)) {
                badges.push(`<span class='px-2 py-0.5 rounded bg-lime-100 text-lime-700 text-[10px]'>Partiel</span>`);
            } else {
                badges.push(`<span class='px-2 py-0.5 rounded bg-green-100 text-green-700 text-[10px]'>Décaissée</span>`);
            }
        }
        if (decaisse && definitif) badges.push(`<span class='px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[10px]'>Définitif</span>`);
        if (parseInt(f.conforme || 0) === 1) badges.push(`<span class='px-2 py-0.5 rounded bg-emerald-50 text-emerald-600 text-[10px]'>Conforme</span>`);
        if (parseInt(f.approuve || 0) === 1) badges.push(`<span class='px-2 py-0.5 rounded bg-indigo-50 text-indigo-600 text-[10px]'>Approuvée</span>`);
        if (!notValidated && !decaisse) badges.push(`<span class='px-2 py-0.5 rounded bg-purple-50 text-purple-600 text-[10px]'>Validée achat</span>`);
        if (blockedByBR) badges.push(`<span class='px-2 py-0.5 rounded bg-rose-50 text-rose-700 text-[10px]'>BR manquant</span>`);
        const cashOverLimit = /cash|esp[eè]ce/i.test(String(f.mode_paiement||'')) && parseFloat(f.montant_fiche||0) > 250000;
        if (cashOverLimit) badges.push(`<span class='px-2 py-0.5 rounded bg-amber-100 text-amber-800 text-[10px]' title='Suggestion non bloquante'>Cash &gt; 250 000 : ajuster le mode</span>`);
        const regularizationMeta = {
            not_applicable: ['Justificatifs après paiement', 'bg-slate-100 text-slate-600'],
            not_submitted: ['Justificatifs à transmettre', 'bg-amber-100 text-amber-800'],
            pending: ['Justificatifs à valider', 'bg-indigo-100 text-indigo-800'],
            approved: ['Justificatifs régularisés', 'bg-emerald-100 text-emerald-800'],
            rejected: ['Justificatifs à corriger', 'bg-rose-100 text-rose-800']
        }[f.regularization_status || 'not_applicable'];
        badges.push(`<span class='px-2 py-0.5 rounded ${regularizationMeta[1]} text-[10px]'>${regularizationMeta[0]}</span>`);
        const initials = (f.beficiaire_fiche || '?').split(/\s+/).slice(0, 2).map(p => p[0] || '').join('').toUpperCase();
        const rawPhoto = f.photo_beneficiaire || f.photo || '';
        const brBtn = blockedByBR ? `<a href="${bonReceptionUrlForFiche(f)}" target="_blank" rel="noopener" onclick="event.stopPropagation();" class="mt-3 inline-flex items-center justify-center gap-2 w-full px-3 py-2 rounded-lg bg-sky-600 hover:bg-sky-700 text-white text-[11px] font-semibold shadow" title="Joindre le bon de réception signé">Uploader BR signé</a>` : '';
        return `
        <div class="card p-4 relative group border hover:shadow cursor-pointer transition" data-open-fiche="${escapeHtml(f.num_fiche||'')}">
            <a href="voir_fiche_details.php?num_fiche=${encodeURIComponent(f.num_fiche||'')}" target="_blank" class="absolute top-2 right-2 bg-white border border-indigo-200 rounded-full p-1 shadow hover:bg-indigo-50 transition" title="Ouvrir la fiche détails">
                <svg class="w-4 h-4 text-indigo-700" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m0 0l-6-6m6 6l6-6" /></svg>
            </a>
            <div class="flex flex-col items-center text-center">
                <div class="relative mb-2" data-photo-wrapper data-initials="${initials}" data-photo-name="${escapeHtml(rawPhoto)}">
                    <div class="w-14 h-14 rounded-full bg-indigo-600 text-white flex items-center justify-center text-sm font-semibold js-avatar-initials">${initials||'?'} </div>
                    <img class="w-14 h-14 rounded-full object-cover border shadow-sm hidden js-avatar-img" alt="avatar" />
                    <span class="card-badge-analyse absolute -top-1 -right-1 inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 text-[10px] font-bold" title="Analyse" data-num="${escapeHtml(f.num_fiche||'')}">…</span>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-semibold text-indigo-700 truncate mb-0.5">#${escapeHtml(f.num_fiche||'')}</h3>
                    <div class="text-[11px] text-gray-600 truncate">${escapeHtml(f.beficiaire_fiche||'')}</div>
                    <div class="text-[11px] text-gray-500 truncate">${escapeHtml(f.designation_fiche||'')}</div>
                </div>
            </div>
            <div class="mt-3 flex items-center justify-between text-[11px]">
                <span class="font-semibold text-green-600 text-sm">${currencyFormatCFA(f.montant_fiche)}</span>
                <span class="text-gray-400">${formatDateFR(f.date_creat_fiche)}</span>
            </div>
            <div class="mt-2 rounded-lg border border-slate-100 bg-slate-50 px-2 py-1.5 text-[10px] text-slate-600" data-card-ratio="${escapeHtml(f.num_fiche||'')}">
                ${f.budget_ratio == null ? 'Ratio ligne de devis : non disponible' : `Ratio ligne de devis : <strong>${Number(f.budget_ratio).toLocaleString('fr-FR', {maximumFractionDigits: 2})}%</strong>`}
            </div>
            <div class="mt-2 flex flex-wrap gap-1 justify-center">${badges.join('')}</div>
            <a href="request/export_fiches_compta_pdf.php?num_fiche=${encodeURIComponent(f.num_fiche||'')}" target="_blank" onclick="event.stopPropagation();" class="mt-2 inline-flex w-full items-center justify-center gap-1 rounded-lg border border-indigo-200 bg-white px-2 py-1.5 text-[10px] font-semibold text-indigo-700 hover:bg-indigo-50"><i class="fa-solid fa-file-pdf"></i> PDF Finéa de la fiche</a>
            ${brBtn}
        </div>`;
    }

    // Resolution multi-chemins inspirée des pages approbation / conformité
    function buildPhotoCandidates(photoName, fiche) {
        const cands = [];
        if (!photoName) return cands;
        const hasSlash = photoName.includes('/');
        const hasExt = /\.(jpg|jpeg|png|gif|webp)$/i.test(photoName);
        const bases = [];
        if (hasSlash) {
            const cleaned = photoName.replace(/^\.\//, '').replace(/^\/+/, '');
            bases.push('../' + cleaned); // remonter car nous sommes dans /decaissement/
            bases.push(cleaned); // tel quel (au cas où déjà relatif correct)
        } else {
            bases.push('../uploads/photo/' + photoName);
            bases.push('uploads/photo/' + photoName);
        }
        bases.forEach(b => {
            if (hasExt) cands.push(b);
            else ['.jpg', '.jpeg', '.png', '.webp'].forEach(ext => cands.push(b + ext));
        });
        return [...new Set(cands)];
    }

    function tryLoadPhoto(wrapper) {
        const photoName = wrapper.getAttribute('data-photo-name');
        if (!photoName) return; // rien à tenter
        const img = wrapper.querySelector('.js-avatar-img');
        const initialsDiv = wrapper.querySelector('.js-avatar-initials');
        const candidates = buildPhotoCandidates(photoName, {});
        if (!candidates.length) return;
        let idx = 0;

        function attempt() {
            if (idx >= candidates.length) return; // échec total : on garde les initiales
            const url = candidates[idx++];
            img.onload = () => {
                initialsDiv.classList.add('hidden');
                img.classList.remove('hidden');
            };
            img.onerror = () => attempt();
            img.src = url;
        }
        attempt();
    }

    function initPhotos(scope = document) {
        scope.querySelectorAll('[data-photo-wrapper]').forEach(w => tryLoadPhoto(w));
    }

    function bindEvents(scope = document) {
        scope.querySelectorAll('[data-open-fiche]').forEach(card => card.addEventListener('click', () => openFicheModal(card.getAttribute('data-open-fiche'))));
    }

    function openFicheModal(num) {
        const f = fichesData[num];
        if (!f) {
            ouvrirAide(num);
            return;
        }
        const blockedByBR = isBlockedByMissingBR(f);
        const hasBC = hasBonCommande(f);
        const brQuickLink = hasBC ? `
            <div class='mt-3 p-3 rounded-lg border border-slate-200 bg-slate-50 text-slate-700 text-[12px]'>
                <div class='font-semibold text-slate-800'>Bon de réception signé (BR)</div>
                <div class='mt-1'>Joindre / remplacer le BR signé lié au bon de commande.</div>
                <div class='mt-2'>
                    <a href='${bonReceptionUrlForFiche(f)}' target='_blank' rel='noopener' class='inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-700 text-white text-[11px] font-semibold shadow'>
                        Ouvrir upload BR
                    </a>
                </div>
            </div>
        ` : '';
        const brInfoBlock = blockedByBR ? `
            <div class='mt-3 p-3 rounded-lg border border-rose-200 bg-rose-50 text-rose-800 text-[12px]'>
                <div class='font-semibold'>Bon de réception signé requis</div>
                <div class='mt-1'>Un bon de commande existe pour cette fiche. Vous devez joindre le BR signé avant tout décaissement.</div>
                <div class='mt-2'>
                    <a href='${bonReceptionUrlForFiche(f)}' target='_blank' rel='noopener' class='inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-700 text-white text-[11px] font-semibold shadow'>
                        Uploader BR signé
                    </a>
                </div>
            </div>
        ` : '';
        const futureDate = f.date_decaissement_minimum && new Date(f.date_decaissement_minimum) > new Date();
        const notValidated = parseInt(f.etat_fiche || 0) === 0;
        const decaisse = parseInt(f.decaisse || 0) === 1;
        const cashOverLimit = /cash|esp[eè]ce/i.test(String(f.mode_paiement||'')) && parseFloat(f.montant_fiche||0) > 250000;
        const definitif = parseInt(f.decaisse_definitif || 0) === 1;
        const badges = [];
        if (parseInt(f.conforme || 0) === 1) badges.push('<span class="px-2 py-0.5 rounded bg-emerald-50 text-emerald-600 text-[10px]">Conforme</span>');
        if (parseInt(f.approuve || 0) === 1) badges.push('<span class="px-2 py-0.5 rounded bg-indigo-50 text-indigo-600 text-[10px]">Approuvée</span>');
        if (!notValidated && !decaisse) badges.push('<span class="px-2 py-0.5 rounded bg-purple-50 text-purple-600 text-[10px]">Validée achat</span>');
        if (notValidated) badges.push('<span class="px-2 py-0.5 rounded bg-sky-50 text-sky-600 text-[10px]">Pré-achat</span>');
        if (futureDate) badges.push('<span class="px-2 py-0.5 rounded bg-amber-50 text-amber-600 text-[10px]">Reporté</span>');
        if (decaisse) {
            if (parseFloat(f.montant_initial_fiche || 0) > parseFloat(f.montant_fiche || 0)) {
                badges.push('<span class="px-2 py-0.5 rounded bg-lime-50 text-lime-700 text-[10px]">Partiel</span>');
            } else {
                badges.push('<span class="px-2 py-0.5 rounded bg-green-100 text-green-700 text-[10px]">Décaissée</span>');
            }
        }
        if (decaisse && definitif) badges.push('<span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[10px]">Définitif</span>');
        const initials = (f.beficiaire_fiche || '?').split(/\s+/).slice(0, 2).map(p => p[0] || '').join('').toUpperCase();
        const zone = document.getElementById('modalBodyCompta');
        zone.innerHTML = `
            <div class='flex items-start gap-4 mb-3'>
                <div class='relative' data-photo-wrapper data-photo-name='${escapeHtml(f.photo_beneficiaire||f.photo||'')}' data-initials='${initials}'>
                    <div class='w-20 h-20 rounded-full bg-indigo-600 text-white flex items-center justify-center text-lg font-semibold shadow js-avatar-initials border-4 border-indigo-400'>${initials}</div>
                    <img class='w-20 h-20 rounded-full object-cover border-4 border-indigo-400 shadow js-avatar-img hidden' alt='avatar'>
                </div>
                <div class='flex-1 min-w-0'>
                    <h2 class='text-lg font-semibold text-indigo-700 mb-1'>Fiche #${escapeHtml(f.num_fiche)}</h2>
                    <div class='flex flex-wrap gap-1 mb-2'>${badges.join('')}</div>
                    <div class='text-[12px] text-gray-600 space-y-1'>
                        <div><strong>Bénéficiaire:</strong> ${escapeHtml(f.beficiaire_fiche||'')}</div>
                        <div><strong>Désignation:</strong> ${escapeHtml(f.designation_fiche||'')}</div>
                        <div><strong>Précision:</strong> ${escapeHtml(f.precision_fiche||'')}</div>
                        <div><strong>Montant:</strong> ${currencyFormatCFA(f.montant_fiche)}</div>
                        ${f.date_decaissement_minimum?`<div><strong>Date mini:</strong> ${escapeHtml(f.date_decaissement_minimum)}</div>`:''}
                    </div>
                </div>
            </div>
            <div class='mt-2 text-[10px] text-gray-400 flex items-center gap-2'>
                <span class="badge-system"><svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'><path d='M12 2l7 4v6c0 5-3.5 9-7 10-3.5-1-7-5-7-10V6l7-4z'/><path d='M9 12l2 2 4-4'/></svg> Système</span>
                Vérification: ${(parseInt(f.conforme||0)&&parseInt(f.approuve||0))?'<span class="text-emerald-600 font-semibold">OK</span>':'<span class="text-amber-600 font-semibold">Incomplète</span>'}
            </div>
            ${brInfoBlock}
            ${brQuickLink}
            ${cashOverLimit?`<div class='mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-[12px] text-amber-900'><b>Suggestion de paiement :</b> le mode cash déclaré dépasse 250 000 FCFA. Il est recommandé d’utiliser un virement, un chèque ou un paiement électronique. Cette alerte reste non bloquante.</div>`:''}
        `;
        // Actions en footer
        const actionsFooter = document.getElementById('modalActionsFooter');
        if (actionsFooter) {
            const btns = [];
            btns.push(`<button class='px-3 py-1.5 rounded-lg text-[11px] font-semibold shadow bg-amber-500 hover:bg-amber-600 text-white' data-act='help' data-num='${escapeHtml(f.num_fiche)}'>Aide</button>`);

            // Accès BR : toujours proposé (la page BR refusera l'upload si aucun BC n'existe)
            btns.push(`<a class='px-3 py-1.5 rounded-lg text-[11px] font-semibold shadow bg-sky-600 hover:bg-sky-700 text-white' href='${bonReceptionUrlForFiche(f)}' target='_blank' rel='noopener'>BR signé</a>`);

            if (!decaisse) {
                if (!blockedByBR) {
                    btns.push(`<button class='px-3 py-1.5 rounded-lg text-[11px] font-semibold shadow bg-green-600 hover:bg-green-700 text-white' data-act='decaisser' data-num='${escapeHtml(f.num_fiche)}' ${futureDate?'disabled':''}>Décaisser</button>`);
                    btns.push(`<button class='px-3 py-1.5 rounded-lg text-[11px] font-semibold shadow bg-indigo-600 hover:bg-indigo-700 text-white' data-act='partiel' data-num='${escapeHtml(f.num_fiche)}' ${futureDate?'disabled':''}>Décaisser partiellement</button>`);
                } else {
                    btns.push(`<a class='px-3 py-1.5 rounded-lg text-[11px] font-semibold shadow bg-sky-600 hover:bg-sky-700 text-white' href='${bonReceptionUrlForFiche(f)}' target='_blank' rel='noopener'>Uploader BR signé</a>`);
                }
                btns.push(`<button class='px-3 py-1.5 rounded-lg text-[11px] font-semibold shadow bg-orange-600 hover:bg-orange-700 text-white' data-act='reporter' data-num='${escapeHtml(f.num_fiche)}'>Reporter</button>`);
            }
            if (decaisse) {
                if (!definitif) {
                    btns.push(`<button class='px-3 py-1.5 rounded-lg text-[11px] font-semibold shadow bg-red-600 hover:bg-red-700 text-white' data-act='annuler-decaissement' data-num='${escapeHtml(f.num_fiche)}'>Annuler décaissement</button>`);
                } else {
                    btns.push(`<button class='px-3 py-1.5 rounded-lg text-[11px] font-semibold shadow bg-slate-200 text-slate-600 cursor-not-allowed' disabled title='Décaissement définitif: annulation impossible'>Annulation impossible</button>`);
                }
            }
            if (notValidated) {
                btns.push(`<button class='px-3 py-1.5 rounded-lg text-[11px] font-semibold shadow bg-emerald-600 hover:bg-emerald-700 text-white' data-act='valider-achat' data-num='${escapeHtml(f.num_fiche)}'>Valider Achat</button>`);
            }
            actionsFooter.innerHTML = btns.join('');
        }
        initPhotos(zone);
        updateModalWorkflowBreadcrumb(f.num_fiche);
        openModal('modalCompta');
        (actionsFooter || zone).querySelectorAll('[data-act]').forEach(b => {
            b.addEventListener('click', () => handleActionClick(b.getAttribute('data-act'), b.getAttribute('data-num'), b));
        });
        activateTab('resume', f.num_fiche);
    }

    function handleActionClick(act, num, btn) {
        if (act === 'help') {
            activateTab('resume', num);
            return;
        }
        if (act === 'decaisser') {
            ouvrirDecaisserTotal(num);
            return;
        }
        if (act === 'annuler-decaissement') {
            ouvrirAnnulation(num);
            return;
        }
        if (act === 'partiel') {
            ouvrirPartiel({
                dataset: {
                    num,
                    montant: (fichesData[num] || {}).montant_fiche
                }
            });
            return;
        }
        if (act === 'reporter') {
            ouvrirReporter(num);
            return;
        }
        if (act === 'valider-achat') {
            ouvrirValiderAchat(num);
            return;
        }
        if (['analyse', 'traces', 'hierarchie', 'famille'].includes(act)) {
            // Active l'onglet puis scroll comme un popup interne
            const map = {
                analyse: 'analyse',
                traces: 'traces',
                hierarchie: 'hierarchie',
                famille: 'famille'
            };
            activateTab(map[act], num);
            const tc = document.getElementById('tabContent');
            if (tc) {
                tc.classList.add('ring', 'ring-indigo-300', 'ring-offset-2');
                setTimeout(() => tc.classList.remove('ring', 'ring-indigo-300', 'ring-offset-2'), 900);
                tc.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }
    }

    // Gestion des onglets dans le modal
    document.addEventListener('click', e => {
        if (e.target.classList && e.target.classList.contains('tab-pill')) {
            const h2 = document.querySelector('#modalBodyCompta h2');
            const num = h2 ? (h2.textContent.match(/Fiche #(.*)/) || [])[1] : null;
            activateTab(e.target.getAttribute('data-tab'), num);
        }
    });

    function activateTab(tab, num) {
        document.querySelectorAll('#tabsActions .tab-pill').forEach(t => t.classList.remove('active'));
        const b = document.querySelector(`#tabsActions .tab-pill[data-tab="${tab}"]`);
        if (b) b.classList.add('active');
        const content = document.getElementById('tabContent');
        if (!num) {
            content.innerHTML = '<div class="text-[11px] text-gray-500">Sélectionnez une fiche.</div>';
            return;
        }
        if (tab === 'resume') {
            const f = fichesData[num];
            content.innerHTML = `<div class='grid sm:grid-cols-2 gap-3'>
                <div class='border rounded p-2'><div class='font-semibold mb-1 text-indigo-600'>Montant</div>${currencyFormatCFA(f.montant_fiche)}</div>
                <div class='border rounded p-2'><div class='font-semibold mb-1 text-indigo-600'>Bénéficiaire</div>${escapeHtml(f.beficiaire_fiche||'')}</div>
                <div class='border rounded p-2 sm:col-span-2'><div class='font-semibold mb-1 text-indigo-600'>Désignation</div>${escapeHtml(f.designation_fiche||'')}</div>
                <div class='border rounded p-2 sm:col-span-2'><div class='font-semibold mb-1 text-indigo-600'>Précision</div>${escapeHtml(f.precision_fiche||'')}</div>
            </div>`;
        } else if (tab === 'analyse') {
            chargerAnalyseDansZone(num, content);
        } else if (tab === 'traces') {
            chargerTracesDansZone(num, content);
        } else if (tab === 'hierarchie') {
            chargerHierarchyDansZone(num, content);
        } else if (tab === 'famille') {
            chargerFamilleDansZone(num, content);
        }
        // Focus visuel pour simuler popup
        content.style.position = 'relative';
        content.animate([{
                transform: 'scale(.97)',
                opacity: .6
            },
            {
                transform: 'scale(1)',
                opacity: 1
            }
        ], {
            duration: 180,
            easing: 'ease-out'
        });
    }

    function getEtapeBadgeLabel(f) {
        if (!f) return '';
        if (parseInt(f.decaisse || 0) === 1) return 'Décaissée';
        if (parseInt(f.etat_fiche || 0) === 1) return 'Validée achat';
        if (parseInt(f.etat_fiche || 0) === 2) return 'Refusée';
        return 'Pré-achat';
    }

    function getEtapeBadgeHtml(f) {
        const label = getEtapeBadgeLabel(f);
        if (label === 'Décaissée') {
            return `<span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-semibold text-emerald-700">Décaissée</span>`;
        }
        if (label === 'Validée achat') {
            return `<span class="inline-flex items-center rounded-full border border-violet-200 bg-violet-50 px-2.5 py-1 text-[10px] font-semibold text-violet-700">Validée achat</span>`;
        }
        if (label === 'Refusée') {
            return `<span class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[10px] font-semibold text-rose-700">Refusée</span>`;
        }
        return `<span class="inline-flex items-center rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-[10px] font-semibold text-sky-700">Pré-achat</span>`;
    }

    function getActionType(action) {
        const raw = String(action || '').toUpperCase();
        if (!raw) return 'autres';
        if (raw.includes('CREATION') || raw.includes('AUTO')) return 'creation';
        if (raw.includes('VALID') || raw.includes('APPROUVE') || raw.includes('CONFORME')) return 'validation';
        if (raw.includes('DECAISSE')) return 'decaissement';
        if (raw.includes('REPORT')) return 'report';
        if (raw.includes('REFUS') || raw.includes('ANNUL')) return 'refus';
        return 'autres';
    }

    function getActionTypeMeta(type) {
        const meta = {
            all: {
                label: 'Tout',
                className: 'border-slate-200 bg-white text-slate-700'
            },
            creation: {
                label: 'Création',
                className: 'border-sky-200 bg-sky-50 text-sky-700'
            },
            validation: {
                label: 'Validation',
                className: 'border-violet-200 bg-violet-50 text-violet-700'
            },
            decaissement: {
                label: 'Décaissement',
                className: 'border-emerald-200 bg-emerald-50 text-emerald-700'
            },
            report: {
                label: 'Report',
                className: 'border-amber-200 bg-amber-50 text-amber-700'
            },
            refus: {
                label: 'Refus/Annulation',
                className: 'border-rose-200 bg-rose-50 text-rose-700'
            },
            autres: {
                label: 'Autres',
                className: 'border-slate-200 bg-slate-50 text-slate-700'
            }
        };
        return meta[type] || meta.autres;
    }

    function formatDateDayKey(dateAction) {
        const raw = String(dateAction || '').trim();
        if (!raw) return 'Sans date';
        try {
            const d = new Date(raw);
            return d.toLocaleDateString('fr-FR', {
                weekday: 'long',
                day: '2-digit',
                month: 'long',
                year: 'numeric'
            });
        } catch {
            return raw;
        }
    }

    function renderHierarchyLegend() {
        return `<div class="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-slate-600"><span class="font-semibold text-slate-700">Légende :</span>${getEtapeBadgeHtml({ etat_fiche: 0, decaisse: 0 })}${getEtapeBadgeHtml({ etat_fiche: 1, decaisse: 0 })}${getEtapeBadgeHtml({ etat_fiche: 0, decaisse: 1 })}</div>`;
    }

    function sortWorkflowFirst(rootItems, fiches) {
        const workflowOrder = new Map((rootItems || []).map((item, index) => [item.num_fiche, index]));
        const related = [];
        const unrelated = [];
        (fiches || []).forEach(item => {
            if (workflowOrder.has(item.num_fiche)) {
                related.push(item);
            } else {
                unrelated.push(item);
            }
        });
        related.sort((left, right) => workflowOrder.get(left.num_fiche) - workflowOrder.get(right.num_fiche));
        unrelated.sort((left, right) => String(left.date_creation || '').localeCompare(String(right.date_creation || '')) || String(left.num_fiche || '').localeCompare(String(right.num_fiche || '')));
        return [...related, ...unrelated];
    }

    function getTraceSourceBadge(trace) {
        if (trace && trace.is_synthetic) {
            return '<span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Synthèse</span>';
        }
        return '<span class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">Trace réelle</span>';
    }

    function buildWorkflowChain(items, currentNum) {
        const byNum = new Map((items || []).map(item => [item.num_fiche, item]));
        const chain = [];
        let cursor = currentNum;
        let guard = 0;
        while (cursor && byNum.has(cursor) && guard < 50) {
            const node = byNum.get(cursor);
            chain.unshift(node);
            cursor = node.parent_num_fiche || '';
            guard += 1;
        }
        return chain;
    }

    async function updateModalWorkflowBreadcrumb(num) {
        const target = document.getElementById('modalWorkflowBreadcrumb');
        if (!target) return;
        target.innerHTML = '<span class="text-slate-400">Chargement du workflow...</span>';
        const j = await fetchJson('request/get_hierarchy.php?num_fiche=' + encodeURIComponent(num));
        if (!j || !j.ok || !j.data || !j.data.items || !j.data.items.length) {
            target.innerHTML = `<span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] font-medium text-slate-600">${escapeHtml(num)}</span>`;
            return;
        }
        const chain = buildWorkflowChain(j.data.items, num);
        if (!chain.length) {
            target.innerHTML = `<span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] font-medium text-slate-600">${escapeHtml(num)}</span>`;
            return;
        }
        target.innerHTML = `<div class="flex flex-wrap items-center gap-1.5">${chain.map((item, index) => {
            const isLast = index === chain.length - 1;
            const badge = getEtapeBadgeHtml(item);
            return `${index > 0 ? '<span class="text-slate-300">→</span>' : ''}<button type="button" class="js-breadcrumb-link inline-flex items-center gap-1.5 rounded-full border ${isLast ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600'} px-2.5 py-1 hover:border-indigo-300 hover:bg-indigo-50/70" data-num="${escapeHtml(item.num_fiche)}"><span class="font-semibold">${escapeHtml(item.num_fiche)}</span>${badge}</button>`;
        }).join('')}</div>`;
        target.querySelectorAll('.js-breadcrumb-link').forEach(button => {
            button.addEventListener('click', () => {
                const nextNum = button.getAttribute('data-num') || '';
                if (nextNum) {
                    openFicheModal(nextNum);
                }
            });
        });
    }

    function normalizeAnalysePayload(payload) {
        if (!payload || !payload.ok || !payload.analyse) {
            return null;
        }
        const analyse = payload.analyse || {};
        const classificationMap = {
            large_marge: 'large_marge',
            marge: 'bonne_marge',
            limite: 'marge_limite',
            depasse_legere: 'depasse_legere',
            depasse_forte: 'depasse_forte',
            invalide: 'inconnu'
        };
        return {
            ...analyse,
            classification: classificationMap[analyse.niveau] || analyse.classification || 'inconnu',
            ratio: Number(analyse.ratio || 0) * 100
        };
    }

    async function chargerFamilleDansZone(num, target) {
        target.innerHTML = '<div class="text-[11px] text-gray-500">Chargement famille...</div>';
        const hierR = await fetchJson('request/get_hierarchy.php?num_fiche=' + encodeURIComponent(num));
        const ligneR = await fetchJson('request/get_ligne_debourse_details.php?num_fiche=' + encodeURIComponent(num));
        if (!hierR && !ligneR) {
            target.innerHTML = '<div class="text-[11px] text-red-600">Impossible de charger (réseau)</div>';
            return;
        }
        const rootItems = (hierR && hierR.data && hierR.data.items) ? hierR.data.items : [];
        const rootSet = new Set(rootItems.map(i => i.num_fiche));
        const fiches = (ligneR && (ligneR.fiches || ligneR.fiches_existantes)) ? sortWorkflowFirst(rootItems, (ligneR.fiches || ligneR.fiches_existantes)) : [];
        if (!fiches.length) {
            target.innerHTML = '<div class="text-[11px] text-gray-500">Aucune fiche liée</div>';
            return;
        }
        const rows = fiches.map(f => {
            const isFam = rootSet.has(f.num_fiche);
            const step = getEtapeBadgeHtml(f);
            const current = f.num_fiche === num ? ' ring-2 ring-indigo-300' : '';
            return `<tr class='text-[11px] ${isFam?'bg-indigo-50':''}${current}'><td class='px-2 py-1 border font-semibold'>${f.num_fiche}</td><td class='px-2 py-1 border'>${currencyFormatCFA(f.montant_fiche||0)}</td><td class='px-2 py-1 border'>${step}</td><td class='px-2 py-1 border'>${f.decaisse==1?'Oui':'Non'}</td><td class='px-2 py-1 border'>${f.parent_num_fiche||''}</td><td class='px-2 py-1 border'>${f.approuve==1?'Approuvée ':''}${f.conforme==1?'Conforme':''}</td></tr>`;
        }).join('');
        target.innerHTML = `<div class='text-[11px] mb-2'>Toutes les fiches de la même ligne de déboursé. La famille parent/enfant de la fiche courante est affichée du premier au dernier, puis les autres fiches de la ligne apparaissent ensuite.</div><div class='overflow-auto max-h-72'><table class='w-full table-auto border text-[11px]'><thead><tr class='bg-gray-100'><th class='px-2 py-1 border'>Num</th><th class='px-2 py-1 border'>Montant</th><th class='px-2 py-1 border'>Étape</th><th class='px-2 py-1 border'>Décaissé</th><th class='px-2 py-1 border'>Parent direct</th><th class='px-2 py-1 border'>Statuts</th></tr></thead><tbody>${rows}</tbody></table></div>`;
    }

    async function chargerAnalyseDansZone(num, target) {
        target.innerHTML = '<div class="text-[11px] text-gray-500">Analyse...</div>';
        const a = await fetchAnalyse(num);
        if (!a) {
            target.innerHTML = '<div class="text-[11px] text-red-600">Analyse indisponible</div>';
            return;
        }
        const ratio = parseFloat(a.ratio || 0);
        const gaugeWidth = Math.min(Math.max(ratio, 0), 100);
        const totalLigne = Number(a.montant_ligne || a.total_fiche_ligne || 0);
        target.innerHTML = `<div class='border rounded p-3'>
            <div class='flex items-center gap-2 mb-2'>
                <span class='text-xl'>${analyseIconMapping[a.classification]||'❓'}</span>
                <div class='text-[11px]'>Ratio: ${ratio.toFixed(2)}%</div>
            </div>
            <div class='w-full h-2 rounded bg-gray-200 mb-2 overflow-hidden'><div class='h-full ${ratio>100?'bg-red-500':ratio>85?'bg-orange-500':ratio>70?'bg-yellow-400':'bg-green-500'}' style='width:${gaugeWidth}%'></div></div>
            <div class='text-[11px] text-gray-600'>Montant fiche: ${currencyFormatCFA(a.montant_fiche||0)} / Total ligne: ${currencyFormatCFA(totalLigne)}</div>
            <div class='text-[11px] mt-1 italic text-gray-500'>${escapeHtml(a.conseil||'')}</div>
        </div>`;
    }

    async function chargerTracesDansZone(num, target) {
        target.innerHTML = '<div class="text-[11px] text-gray-500">Traces...</div>';
        const j = await fetchJson('request/get_traces.php?num_fiche=' + encodeURIComponent(num));
        if (!j || !j.ok) {
            target.innerHTML = '<div class="text-[11px] text-red-600">Erreur traces (réseau ou format)</div>';
            return;
        }
        if (!j.items || !j.items.length) {
            target.innerHTML = '<div class="text-[11px] text-gray-500">Aucune trace</div>';
            return;
        }
        const groupedItems = j.items.map(item => ({
            ...item,
            actionType: getActionType(item.action)
        }));
        const counts = groupedItems.reduce((acc, item) => {
            acc[item.actionType] = (acc[item.actionType] || 0) + 1;
            return acc;
        }, {});
        const groupedByDay = groupedItems.reduce((acc, item) => {
            const dayKey = formatDateDayKey(item.date_action);
            if (!acc[dayKey]) acc[dayKey] = [];
            acc[dayKey].push(item);
            return acc;
        }, {});
        const groupedMarkup = Object.entries(groupedByDay).map(([day, items]) => {
            return `<li class="js-trace-group" data-group-day="${escapeHtml(day)}"><div class="sticky top-0 z-10 -mx-2 mb-2 flex items-center justify-between gap-3 border-y border-slate-200 bg-slate-50 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500"><span>${escapeHtml(day)}</span><span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-600">${items.length} action(s)</span></div><ul class="space-y-2">${items.map(t => {
                const meta = getActionTypeMeta(t.actionType);
                return `<li class='js-trace-item border-b last:border-b-0 pb-2' data-type='${t.actionType}'><div class='flex flex-wrap items-center gap-2'><span class='font-semibold text-indigo-700'>${escapeHtml(t.num_fiche||'')}</span>${getTraceSourceBadge(t)}<span class='inline-flex items-center rounded-full border px-2 py-0.5 text-[10px] font-semibold ${meta.className}'>${meta.label}</span><span class='font-semibold'>${escapeHtml(t.action)}</span><span class='text-gray-500'>${escapeHtml(t.date_action||'')}</span></div><div class='mt-1 text-gray-600'>${escapeHtml(t.details||'')}</div></li>`;
            }).join('')}</ul></li>`;
        }).join('');
        target.innerHTML = `<div class="space-y-3"><div class="flex flex-wrap gap-2">${['all', 'creation', 'validation', 'decaissement', 'report', 'refus', 'autres'].map(type => {
            const meta = getActionTypeMeta(type);
            const count = type === 'all' ? groupedItems.length : (counts[type] || 0);
            return `<button type="button" class="js-trace-filter inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-[11px] font-semibold ${meta.className}" data-type="${type}"><span>${meta.label}</span><span class="rounded-full bg-white/70 px-1.5 py-0.5 text-[10px]">${count}</span></button>`;
        }).join('')}</div><div class="border rounded p-2 max-h-72 overflow-auto"><ul class="space-y-4 text-[11px] js-trace-list">${groupedMarkup}</ul></div></div>`;
        target.querySelectorAll('.js-trace-filter').forEach(button => {
            button.addEventListener('click', () => {
                const type = button.getAttribute('data-type') || 'all';
                target.querySelectorAll('.js-trace-filter').forEach(btn => btn.classList.remove('ring', 'ring-offset-1', 'ring-slate-300'));
                button.classList.add('ring', 'ring-offset-1', 'ring-slate-300');
                target.querySelectorAll('.js-trace-item').forEach(item => {
                    item.classList.toggle('hidden', type !== 'all' && item.getAttribute('data-type') !== type);
                });
                target.querySelectorAll('.js-trace-group').forEach(group => {
                    const visibleItems = group.querySelectorAll('.js-trace-item:not(.hidden)');
                    group.classList.toggle('hidden', visibleItems.length === 0);
                });
            });
        });
        const defaultFilter = target.querySelector('.js-trace-filter[data-type="all"]');
        if (defaultFilter) defaultFilter.click();
    }

    async function chargerHierarchyDansZone(num, target) {
        target.innerHTML = '<div class="text-[11px] text-gray-500">Hiérarchie...</div>';
        const j = await fetchJson('request/get_hierarchy.php?num_fiche=' + encodeURIComponent(num));
        if (!j || !j.ok) {
            target.innerHTML = '<div class="text-[11px] text-red-600">Erreur hiérarchie (réseau)</div>';
            return;
        }
        const d = j.data || {};
        if (!d.items) {
            target.innerHTML = '<div class="text-[11px] text-gray-500">Pas de données</div>';
            return;
        }
        const rows = d.items.map(i => `<tr class='js-hierarchy-row text-[11px] cursor-pointer ${i.num_fiche===d.fiche_courante?'bg-indigo-50':''} hover:bg-indigo-50/70' data-num='${escapeHtml(i.num_fiche)}'><td class='px-2 py-1 border font-semibold'>${i.num_fiche}</td><td class='px-2 py-1 border'>${currencyFormatCFA(i.montant_fiche||0)}</td><td class='px-2 py-1 border'>${i.parent_num_fiche||''}</td><td class='px-2 py-1 border'>${getEtapeBadgeHtml(i)}</td><td class='px-2 py-1 border'>${i.decaisse==1?'Oui':'Non'}</td><td class='px-2 py-1 border'>${i.date_decaisse?formatDateFR(i.date_decaisse):''}</td><td class='px-2 py-1 border'>${i.date_min?formatDateFR(i.date_min):''}</td></tr>`).join('');
        target.innerHTML = `<div class='border rounded p-2 text-[11px] mb-2'>Racine: <span class='font-semibold text-indigo-700'>${escapeHtml(d.root||'')}</span> | Total: ${currencyFormatCFA(d.total_montant||0)} | Décaissé: ${currencyFormatCFA(d.total_decaissé||0)} | Reste: ${currencyFormatCFA(d.total_restant||0)}</div><div class='overflow-auto max-h-72'><table class='w-full table-auto border text-[11px]'><thead><tr class='bg-gray-100'><th class='px-2 py-1 border'>Num</th><th class='px-2 py-1 border'>Montant</th><th class='px-2 py-1 border'>Parent direct</th><th class='px-2 py-1 border'>Étape</th><th class='px-2 py-1 border'>Décaissé</th><th class='px-2 py-1 border'>Date décaisse</th><th class='px-2 py-1 border'>Date min</th></tr></thead><tbody>${rows}</tbody></table></div>${renderHierarchyLegend()}`;
        target.querySelectorAll('.js-hierarchy-row').forEach(row => {
            row.addEventListener('click', () => {
                const nextNum = row.getAttribute('data-num') || '';
                if (nextNum) {
                    openFicheModal(nextNum);
                }
            });
        });
    }

    function ouvrirValiderAchat(num) {
        const f = fichesData[num];
        if (!f) return;
        document.getElementById('valachat_num_fiche').value = num;
        document.getElementById('valachat_resume').innerHTML = `<strong>#${escapeHtml(num)}</strong><br>${escapeHtml(f.beficiaire_fiche||'')}<br><span class='text-indigo-700 font-semibold'>${currencyFormatCFA(f.montant_fiche)}</span>`;
        document.getElementById('valachat_error').classList.add('hidden');
        openModal('modalValiderAchat');
    }

    function ouvrirDecaisserTotal(num) {
        const f = fichesData[num];
        if (!f) return;
        if (isBlockedByMissingBR(f)) {
            const url = bonReceptionUrlForFiche(f);
            if (typeof showToast === 'function') {
                showToast('Bon de réception signé requis avant décaissement', 'warning');
            } else {
                alert('Bon de réception signé requis avant décaissement.');
            }
            window.open(url, '_blank', 'noopener');
            return;
        }
        document.getElementById('decaisser_num_fiche').value = num;
        document.getElementById('decaisser_montant').textContent = currencyFormatCFA(f.montant_fiche);
        document.getElementById('decaisser_infos').innerHTML = `<span class='font-semibold'>Fiche #${escapeHtml(f.num_fiche)}</span><br><span class='text-gray-600'>${escapeHtml(f.beficiaire_fiche||'')}</span>`;
        document.getElementById('decaisser_error').classList.add('hidden');
        openModal('modalDecaisserTotal');
    }

    document.addEventListener('submit', async e => {
        if (e.target && e.target.id === 'formDecaisserTotal') {
            e.preventDefault();
            const fd = new FormData(e.target);
            const btns = e.target.querySelectorAll('button');
            btns.forEach(b => b.disabled = true);
            try {
                const r = await fetch('request/decaisser_total.php', {
                    method: 'POST',
                    body: fd
                });
                const j = await r.json();
                if (!j.ok) {
                    const err = document.getElementById('decaisser_error');
                    err.textContent = j.error || 'Erreur décaissement';
                    err.classList.remove('hidden');
                } else {
                    closeDecaissementModals('modalDecaisserTotal');
                    if (typeof showToast === 'function') showToast('Décaissement total effectué', 'success');
                    fetchAndRender();
                }
            } catch {
                const err = document.getElementById('decaisser_error');
                err.textContent = 'Erreur réseau';
                err.classList.remove('hidden');
            }
            btns.forEach(b => b.disabled = false);
        }
    });

    function ouvrirPartiel(btn) {
        const num = btn.dataset.num;
        const f = fichesData[num];
        if (f && isBlockedByMissingBR(f)) {
            const url = bonReceptionUrlForFiche(f);
            if (typeof showToast === 'function') {
                showToast('Bon de réception signé requis avant décaissement', 'warning');
            } else {
                alert('Bon de réception signé requis avant décaissement.');
            }
            window.open(url, '_blank', 'noopener');
            return;
        }
        document.getElementById('partiel_num_fiche').value = num;
        document.getElementById('partiel_total').textContent = currencyFormatCFA(btn.dataset.montant);
        document.getElementById('partiel_total_raw').value = btn.dataset.montant;
        document.getElementById('partiel_montant').value = '';
        document.getElementById('partiel_date').value = '';
        document.getElementById('partiel_progress_rate').value = '';
        document.getElementById('partiel_pv_reference').value = '';
        document.getElementById('partiel_validator_one').value = '';
        document.getElementById('partiel_validator_two').value = '';
        document.getElementById('partiel_error').classList.add('hidden');
        const form = document.getElementById('formPartiel');
        form.dataset.childSeq = '1';
        form.dataset.childSeqLoaded = '0';
        const advance=f && f.advance_amortization ? f.advance_amortization : {};
        form.dataset.advanceEnabled=advance.is_provisional?'1':'0';
        form.dataset.contractTotal=String(advance.contract_total||0);
        form.dataset.advancePaid=String(advance.advance_paid||0);
        form.dataset.alreadyPaid=String(advance.already_paid||0);
        updatePartielPreview();
        updateAdvanceSuggestion();
        hydratePartielPreviewContext(num);
        openModal('modalPartiel');
    }

    async function hydratePartielPreviewContext(num) {
        const form = document.getElementById('formPartiel');
        if (!form || !num) {
            return;
        }
        try {
            const result = await fetchJson('request/get_hierarchy.php?num_fiche=' + encodeURIComponent(num));
            const items = result && result.ok && result.data && Array.isArray(result.data.items) ? result.data.items : [];
            const directChildrenCount = items.filter(item => String(item.parent_num_fiche || '') === String(num)).length;
            form.dataset.childSeq = String(directChildrenCount + 1);
            form.dataset.childSeqLoaded = '1';
        } catch {
            form.dataset.childSeq = '1';
            form.dataset.childSeqLoaded = '0';
        }
        updatePartielPreview();
    }

    function getPredictedPartielChildNum() {
        const num = document.getElementById('partiel_num_fiche')?.value || '';
        const form = document.getElementById('formPartiel');
        const seq = parseInt(form?.dataset.childSeq || '1', 10);
        const safeSeq = Number.isFinite(seq) && seq > 0 ? seq : 1;
        return num ? `${num}-P${safeSeq}` : '-';
    }

    function updatePartielPreview() {
        const total = parseFloat(document.getElementById('partiel_total_raw')?.value || '0');
        const montant = parseFloat(document.getElementById('partiel_montant')?.value || '0');
        const date = document.getElementById('partiel_date')?.value || '';
        const childNum = getPredictedPartielChildNum();
        const childNumEl = document.getElementById('partiel_child_num');
        const childAmountEl = document.getElementById('partiel_child_amount');
        const noteEl = document.getElementById('partiel_child_note');
        const form = document.getElementById('formPartiel');
        const seqLoaded = form?.dataset.childSeqLoaded === '1';

        if (!childNumEl || !childAmountEl || !noteEl) {
            return;
        }

        childNumEl.textContent = childNum;

        if (!(montant > 0) || !(total > 0)) {
            childAmountEl.textContent = '-';
            noteEl.textContent = 'Saisissez un montant partiel et une date pour prévisualiser la fiche enfant qui sera créée.';
            noteEl.className = 'mt-2 text-[11px] text-slate-600';
            return;
        }

        if (montant >= total) {
            childAmountEl.textContent = '0 CFA';
            noteEl.textContent = 'Le montant à décaisser doit rester strictement inférieur au montant total pour qu’un reliquat enfant soit créé.';
            noteEl.className = 'mt-2 text-[11px] text-rose-600';
            return;
        }

        const reliquat = Math.max(total - montant, 0);
        childAmountEl.textContent = currencyFormatCFA(reliquat);

        const dateLabel = date ? formatDateFR(date) : 'la date choisie';
        const prefix = seqLoaded ? 'La fiche enfant prévue ' : 'La fiche enfant probable ';
        noteEl.textContent = date ?
            `${prefix}${childNum} sera créée pour ${currencyFormatCFA(reliquat)} et réapparaîtra dans le circuit validation achat à partir du ${dateLabel}.` :
            `${prefix}${childNum} sera créée pour ${currencyFormatCFA(reliquat)}. Choisissez la date de report pour indiquer quand elle réapparaîtra dans le circuit validation achat.`;
        noteEl.className = 'mt-2 text-[11px] text-slate-600';
    }

    function updateAdvanceSuggestion() {
        const form=document.getElementById('formPartiel');
        const panel=document.getElementById('partiel_advance_suggestion');
        if(!form||!panel||form.dataset.advanceEnabled!=='1'){panel?.classList.add('hidden');return}
        const total=Number(form.dataset.contractTotal||0);
        const advance=Number(form.dataset.advancePaid||0);
        const already=Number(form.dataset.alreadyPaid||0);
        const progress=Math.min(100,Math.max(0,Number(document.getElementById('partiel_progress_rate')?.value||0)));
        const target=advance+Math.max(0,total-advance)*(progress/100);
        const suggested=Math.max(0,target-already);
        panel.classList.remove('hidden');
        document.getElementById('partiel_advance_paid').textContent=currencyFormatCFA(advance);
        document.getElementById('partiel_already_paid').textContent=currencyFormatCFA(already);
        document.getElementById('partiel_target_paid').textContent=currencyFormatCFA(target);
        document.getElementById('partiel_suggested_payment').textContent=currencyFormatCFA(suggested);
        document.getElementById('partiel_suggestion_formula').textContent=`À ${progress.toLocaleString('fr-FR')} % : ${currencyFormatCFA(advance)} d’avance + (${currencyFormatCFA(total)} − ${currencyFormatCFA(advance)}) × ${progress.toLocaleString('fr-FR')} %, puis déduction des ${currencyFormatCFA(already)} déjà payés.`;
        form.dataset.suggestedPayment=String(suggested);
    }

    document.getElementById('partiel_montant').addEventListener('input', updatePartielPreview);
    document.getElementById('partiel_date').addEventListener('input', updatePartielPreview);
    document.getElementById('partiel_progress_rate').addEventListener('input', updateAdvanceSuggestion);
    document.getElementById('partiel_apply_suggestion').addEventListener('click',()=>{
        const form=document.getElementById('formPartiel');
        const amount=Number(form?.dataset.suggestedPayment||0);
        if(amount>0){document.getElementById('partiel_montant').value=String(Math.round(amount*100)/100);updatePartielPreview()}
    });

    let partielSubmitInFlight = false;
    document.getElementById('formPartiel').addEventListener('submit', async e => {
        e.preventDefault();
        if (partielSubmitInFlight) {
            return;
        }
        const form = e.target;
        const fd = new FormData(form);
        const montant = parseFloat(fd.get('montant')) || 0;
        const total = parseFloat(document.getElementById('partiel_total_raw').value || '0');
        if (montant <= 0 || montant >= total) {
            showPartielError('Montant partiel invalide (doit être >0 et < total)');
            return;
        }

        const btns = form.querySelectorAll('button');
        partielSubmitInFlight = true;
        btns.forEach(b => b.disabled = true);
        document.getElementById('partiel_error').classList.add('hidden');

        try {
            const r = await fetch('request/decaisser_partiel.php', {
                method: 'POST',
                body: fd
            });
            const j = await r.json();
            if (!r.ok || !j.ok) {
                showPartielError(j.error || 'Erreur serveur');
                return;
            }
            closeDecaissementModals('modalPartiel');
            if (typeof showToast === 'function') showToast('Décaissement partiel enregistré', 'success');
            fetchAndRender();
        } catch {
            showPartielError('Erreur réseau');
        } finally {
            partielSubmitInFlight = false;
            btns.forEach(b => b.disabled = false);
        }
    });

    function showPartielError(msg) {
        const el = document.getElementById('partiel_error');
        el.textContent = msg;
        el.classList.remove('hidden');
    }

    function ouvrirReporter(num) {
        document.getElementById('reporter_num_fiche').value = num;
        const d = document.getElementById('reporter_date_min');
        const today = new Date();
        const iso = today.toISOString().slice(0, 10);
        d.min = iso;
        d.value = iso;
        document.getElementById('reporter_error').classList.add('hidden');
        openModal('modalReporter');
    }

    document.addEventListener('submit', async e => {
        if (e.target && e.target.id === 'formReporter') {
            e.preventDefault();
            const fd = new FormData(e.target);
            const err = document.getElementById('reporter_error');
            err.classList.add('hidden');
            const btns = e.target.querySelectorAll('button');
            btns.forEach(b => b.disabled = true);
            try {
                const r = await fetch('request/reporter_compta.php', {
                    method: 'POST',
                    body: fd
                });
                const j = await r.json();
                if (!j.ok) {
                    err.textContent = j.error || 'Erreur report';
                    err.classList.remove('hidden');
                } else {
                    closeModal('modalReporter');
                    if (typeof showToast === 'function') showToast('Report enregistré', 'success');
                    fetchAndRender();
                }
            } catch {
                err.textContent = 'Erreur réseau';
                err.classList.remove('hidden');
            }
            btns.forEach(b => b.disabled = false);
        }
    });

    function ouvrirAide(num) { // compat rétro
        openFicheModal(num || Object.keys(fichesData)[0]);
    }

    // --------- Analyse budget (réutilisation logique achat) ----------
    const analyseIconMapping = {
        large_marge: '✅',
        bonne_marge: '🟢',
        marge_limite: '🟡',
        depasse_legere: '🟠',
        depasse_forte: '🔴',
        inconnu: '❓'
    };

    // Helper générique pour récupérer du JSON avec fallback ../
    async function fetchJson(path) {
        const essais = [path];
        if (!path.startsWith('../')) essais.push('../' + path);
        for (const p of essais) {
            try {
                const r = await fetch(p, {
                    cache: 'no-store'
                });
                if (!r.ok) continue;
                const ct = r.headers.get('content-type') || '';
                if (!ct.includes('application/json')) continue;
                const j = await r.json();
                return j; // même si j.ok absent
            } catch (e) {}
        }
        return null;
    }

    async function loadAnalysesForCards() {
        const badges = [...document.querySelectorAll('.card-badge-analyse')];
        await Promise.all(badges.map(async b => {
            const num = b.dataset.num;
            const a = await fetchAnalyse(num);
            b.textContent = (analyseIconMapping[a?.classification] || '❓');
            b.title = 'Ratio ' + (a?.ratio || '?') + '%';
            b.classList.add('cursor-pointer');
            b.addEventListener('click', () => ouvrirAide(num));
        }));
    }

    async function fetchAnalyse(num) {
        const j = await fetchJson('request/analyse_fiche_budget.php?num_fiche=' + encodeURIComponent(num));
        return normalizeAnalysePayload(j);
    }

    async function chargerAnalyseDansModal(num) { // conservé pour rétro (non utilisé après refonte)
        const zone = document.getElementById('zoneAnalyseSelection');
        zone.innerHTML = '<div class="text-xs text-gray-500">Chargement analyse...</div>';
        zone.classList.remove('hidden');
        const a = await fetchAnalyse(num);
        if (!a) {
            zone.innerHTML = '<div class="text-red-600 text-xs">Analyse indisponible</div>';
            return;
        }
        const ratio = parseFloat(a.ratio || 0);
        zone.innerHTML = `<div class='space-y-3'>
            <div class='flex items-center gap-2'>
                <span class='text-2xl'>${analyseIconMapping[a.classification]||'❓'}</span>
                <div>
                    <div class='font-semibold text-sm'>Fiche #${num}</div>
                    <div class='text-[11px] text-gray-500'>Montant: ${currencyFormatCFA(a.montant_fiche||0)} | Total ligne: ${currencyFormatCFA(a.total_fiche_ligne||0)}</div>
                </div>
            </div>
            <div>
                <div class='flex justify-between text-[10px] text-gray-500 mb-1'><span>Utilisation</span><span>${ratio.toFixed(2)}%</span></div>
                <div class='w-full h-2 rounded bg-gray-200 overflow-hidden'>
                    <div class='h-full transition-all duration-300 ${ratio>100?'bg-red-500':ratio>85?'bg-orange-500':ratio>70?'bg-yellow-400':'bg-green-500'}' style='width:${Math.min(ratio,120)}%'></div>
                </div>
            </div>
            <div class='mt-4'>
                <button class='btn btn-soft-secondary btn-xxs' data-num='${num}' id='btnVoirLignee'>Voir fiches associées</button>
                <button class='btn btn-soft-primary btn-xxs' data-num='${num}' id='btnVoirTraces'>Traces</button>
                <button class='btn btn-soft-success btn-xxs' data-num='${num}' id='btnVoirHierarchy'>Somme parent/enfants</button>
                <div id='zoneLignee' class='mt-3 hidden'></div>
                <div id='zoneTraces' class='mt-3 hidden'></div>
                <div id='zoneHierarchy' class='mt-3 hidden'></div>
            </div>
        </div>`;
        const btn = zone.querySelector('#btnVoirLignee');
        btn.addEventListener('click', async () => {
            const cont = zone.querySelector('#zoneLignee');
            cont.classList.remove('hidden');
            cont.innerHTML = '<div class="text-[11px] text-gray-500">Chargement...</div>';
            const lr = await fetch('request/get_ligne_debourse_details.php?num_fiche=' + encodeURIComponent(num));
            const lj = await lr.json();
            if (!lj.ok) {
                cont.innerHTML = '<div class="text-[11px] text-red-600">Erreur chargement</div>';
                return;
            }
            const lignes = (lj.fiches || []).map(f => `<tr class='text-[11px]'>
                <td class='px-2 py-1 border'>${f.num_fiche}</td>
                <td class='px-2 py-1 border'>${currencyFormatCFA(f.montant_fiche||0)}</td>
                <td class='px-2 py-1 border'>${f.decaisse==1?'Oui':'Non'}</td>
                <td class='px-2 py-1 border'>${f.approuve==1?'Approuvée':''} ${f.conforme==1?'Conforme':''} ${f.otp_valide==1?'Validée':''}</td>
                <td class='px-2 py-1 border'>${f.date_creat_fiche?formatDateFR(f.date_creat_fiche):''}</td>
            </tr>`).join('');
            cont.innerHTML = `<table class='w-full table-auto border text-[11px]'><thead><tr class='bg-gray-100'>
                <th class='px-2 py-1 border'>Num</th><th class='px-2 py-1 border'>Montant</th><th class='px-2 py-1 border'>Décaissé</th><th class='px-2 py-1 border'>Statuts</th><th class='px-2 py-1 border'>Date</th>
            </tr></thead><tbody>${lignes}</tbody></table>`;
        });
        const btnTraces = zone.querySelector('#btnVoirTraces');
        btnTraces.addEventListener('click', async () => {
            const cont = zone.querySelector('#zoneTraces');
            cont.classList.remove('hidden');
            cont.innerHTML = '<div class="text-[11px] text-gray-500">Chargement traces...</div>';
            const r = await fetch('request/get_traces.php?num_fiche=' + encodeURIComponent(num));
            const j = await r.json();
            if (!j.ok) {
                cont.innerHTML = '<div class="text-[11px] text-red-600">Erreur traces</div>';
                return;
            }
            if (!j.items.length) {
                cont.innerHTML = '<div class="text-[11px] text-gray-500">Aucune trace</div>';
                return;
            }
            cont.innerHTML = '<ul class="text-[11px] space-y-1 max-h-48 overflow-auto pr-2">' + j.items.map(t => `<li class='border-b pb-1'><span class='font-semibold'>${t.action}</span> <span class='text-gray-500'>${t.date_action||''}</span><br/><span class='text-gray-600'>${(t.details||'').replace(/</g,'&lt;')}</span></li>`).join('') + '</ul>';
        });
        const btnHierarchy = zone.querySelector('#btnVoirHierarchy');
        btnHierarchy.addEventListener('click', async () => {
            const cont = zone.querySelector('#zoneHierarchy');
            cont.classList.remove('hidden');
            cont.innerHTML = '<div class="text-[11px] text-gray-500">Calcul...</div>';
            const r = await fetch('request/get_hierarchy.php?num_fiche=' + encodeURIComponent(num));
            const j = await r.json();
            if (!j.ok) {
                cont.innerHTML = '<div class="text-[11px] text-red-600">Erreur hiérarchie</div>';
                return;
            }
            const d = j.data || {};
            if (!d.items) {
                cont.innerHTML = '<div class="text-[11px] text-gray-500">Pas de données</div>';
                return;
            }
            const rows = d.items.map(i => `<tr class='text-[11px] ${i.num_fiche===d.fiche_courante?'bg-indigo-50':''}'><td class='px-2 py-1 border'>${i.num_fiche}</td><td class='px-2 py-1 border'>${currencyFormatCFA(i.montant_fiche||0)}</td><td class='px-2 py-1 border'>${i.decaisse==1?'Oui':'Non'}</td><td class='px-2 py-1 border'>${i.date_decaisse?formatDateFR(i.date_decaisse):''}</td><td class='px-2 py-1 border'>${i.date_min?formatDateFR(i.date_min):''}</td></tr>`).join('');
            cont.innerHTML = `<div class='text-[11px] mb-1 font-semibold'>Total: ${currencyFormatCFA(d.total_montant||0)} | Décaissé: ${currencyFormatCFA(d.total_decaissé||0)} | Reste: ${currencyFormatCFA(d.total_restant||0)}</div><table class='w-full table-auto border text-[11px]'><thead><tr class='bg-gray-100'><th class='px-2 py-1 border'>Num</th><th class='px-2 py-1 border'>Montant</th><th class='px-2 py-1 border'>Décaissé</th><th class='px-2 py-1 border'>Date décaisse</th><th class='px-2 py-1 border'>Date min</th></tr></thead><tbody>${rows}</tbody></table>`;
        });
    }

    // --------- Modal helpers ---------
    // Gestion modale inspirée Bootstrap (sans dépendance JS bootstrap)
    function openModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.add('show');
        document.body.classList.add('overflow-hidden');
    }

    function closeModal(id) {
        const m = document.getElementById(id);
        if (!m) return;
        m.classList.remove('show');
        if (!document.querySelector('.modal.show')) {
            document.body.classList.remove('overflow-hidden');
        }
    }

    function closeDecaissementModals(actionModalId) {
        closeModal(actionModalId);
        closeModal('modalCompta');
    }
    document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => closeModal(b.closest('.modal').id)));
    document.getElementById('btnAideGlobal').addEventListener('click', () => ouvrirAide());

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            const opened = document.querySelectorAll('.modal.show');
            opened.forEach(m => closeModal(m.id));
        }
    });

    function escapeHtml(str) {
        return (str || '').replace(/[&<>"']/g, s => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            '\'': '&#39;'
        } [s]));
    }

    function formatDateFR(iso) {
        try {
            const d = new Date(iso);
            return d.toLocaleDateString('fr-FR', {
                day: '2-digit',
                month: 'short',
                year: 'numeric'
            });
        } catch {
            return iso;
        }
    }

    document.getElementById('searchForm').addEventListener('submit', e => {
        e.preventDefault();
        fetchAndRender(true);
    });
    document.getElementById('resetBtn').onclick = () => {
        document.getElementById('searchForm').reset();
        fetchAndRender(true);
    };
    // Pagination events
    const prevBtn = document.getElementById('prevPage');
    if (prevBtn) prevBtn.onclick = () => {
        if (currentPage > 1) {
            currentPage--;
            fetchAndRender();
        }
    };
    const nextBtn = document.getElementById('nextPage');
    if (nextBtn) nextBtn.onclick = () => {
        if (currentPage < totalPages) {
            currentPage++;
            fetchAndRender();
        }
    };
    const perSel = document.getElementById('perPage');
    if (perSel) perSel.onchange = e => {
        perPageValue = parseInt(e.target.value) || 24;
        fetchAndRender(true);
    };
    document.getElementById('exportCsv').onclick = () => {
        const params = new URLSearchParams(new FormData(document.getElementById('searchForm')));
        params.append('export', 'csv');
        window.open('request/fiches_compta_list.php?' + params.toString(), '_blank');
    };
    document.getElementById('exportPdf').onclick = () => {
        const params = new URLSearchParams(new FormData(document.getElementById('searchForm')));
        const mode = new URLSearchParams(window.location.search).get('mode');
        if (mode) params.set('mode', mode);
        window.open('request/export_fiches_compta_pdf.php?' + params.toString(), '_blank');
    };
    document.addEventListener('DOMContentLoaded', () => {
        fetchAndRender();
        initPhotos(document);
        if (isDemo && typeof showToast === 'function') showToast('Mode démo : traitement comptable', 'info');
    });

    // ---- Décaissement de masse ----
    (function initBulkDecaissement() {
        const form = document.getElementById('bulkDecaisseForm');
        const modeSel = document.getElementById('bulk_mode');
        const fieldChantier = document.getElementById('bulk_field_chantier');
        const fieldBenef = document.getElementById('bulk_field_beneficiaire');
        const fieldDate = document.getElementById('bulk_field_date');
        const inChantier = document.getElementById('bulk_chantier');
        const chantierSuggestions = document.getElementById('bulk_chantier_suggestions');
        const chantierSelected = document.getElementById('bulk_chantier_selected');
        const inBenef = document.getElementById('bulk_beneficiaire');
        const inDate = document.getElementById('bulk_date');
        const inMax = document.getElementById('bulk_max');
        const inDefinitif = document.getElementById('bulk_definitif');
        const submitBtn = document.getElementById('bulk_submit');
        const resultBox = document.getElementById('bulkDecaisseResult');

        const bulkStatus = document.getElementById('bulk_status');
        const bulkCounts = document.getElementById('bulk_counts');
        const bulkBar = document.getElementById('bulk_bar');
        const bulkLogs = document.getElementById('bulk_logs');
        const bulkNote = document.getElementById('bulk_note');

        if (!form || !modeSel) return;

        let chantiersCache = null;
        let chantiersCacheEntreprise = null;
        let chantiersLoading = false;
        let bulkStream = null;
        let bulkDone = false;

        function setModeUI(mode) {
            fieldChantier.classList.toggle('hidden', mode !== 'chantier');
            fieldBenef.classList.toggle('hidden', mode !== 'beneficiaire');
            fieldDate.classList.toggle('hidden', mode !== 'date');
        }

        function prefillFromSearch(mode) {
            const sf = document.getElementById('searchForm');
            if (!sf) return;
            const fd = new FormData(sf);
            if (mode === 'chantier') {
                inChantier.value = String(fd.get('chantier') || '').trim();
            } else if (mode === 'beneficiaire') {
                inBenef.value = String(fd.get('beneficiaire') || '').trim();
            } else if (mode === 'date') {
                const dmin = String(fd.get('date_min') || '').trim();
                const dmax = String(fd.get('date_max') || '').trim();
                inDate.value = (dmin && dmin === dmax) ? dmin : '';
            }
        }

        function getEntrepriseFromSearch() {
            const sf = document.getElementById('searchForm');
            if (!sf) return '';
            const fd = new FormData(sf);
            return String(fd.get('entreprise') || '').trim();
        }

        function applyChantierToSearch(value) {
            const sf = document.getElementById('searchForm');
            if (!sf) return;
            const chantierInput = sf.querySelector('input[name="chantier"]');
            if (!chantierInput) return;
            chantierInput.value = value;
            fetchAndRender(true);
        }

        async function loadChantiersForEntreprise() {
            if (!chantierSuggestions) return;
            const ent = getEntrepriseFromSearch();
            if (chantiersCache && chantiersCacheEntreprise === ent) return;
            if (chantiersLoading) return;
            chantiersLoading = true;
            try {
                const params = new URLSearchParams();
                if (ent) params.set('entreprise', ent);
                const r = await fetch('request/get_chantiers.php' + (params.toString() ? ('?' + params.toString()) : ''), {
                    cache: 'no-store'
                });
                const j = await r.json();
                if (j && j.success && Array.isArray(j.chantiers)) {
                    chantiersCache = j.chantiers;
                    chantiersCacheEntreprise = ent;
                } else {
                    chantiersCache = [];
                    chantiersCacheEntreprise = ent;
                }
            } catch (e) {
                chantiersCache = [];
                chantiersCacheEntreprise = getEntrepriseFromSearch();
            } finally {
                chantiersLoading = false;
            }
        }

        function chantierLabel(c) {
            const id = c && (c.id !== undefined && c.id !== null) ? String(c.id) : '';
            const code = String((c && c.code) || '').trim();
            const titre = String((c && c.titre) || '').trim();
            const client = String((c && c.nom_client) || '').trim();
            const parts = [];
            if (id) parts.push('#' + id);
            if (code) parts.push(code);
            if (titre) parts.push(titre);
            if (client) parts.push('(' + client + ')');
            return parts.join(' - ');
        }

        function filterChantiers(q) {
            const query = String(q || '').trim().toLowerCase();
            if (!chantiersCache) return [];
            if (!query) return chantiersCache.slice(0, 12);
            const scored = [];
            for (const c of chantiersCache) {
                const code = String(c.code || '').toLowerCase();
                const titre = String(c.titre || '').toLowerCase();
                const client = String(c.nom_client || '').toLowerCase();
                const id = String(c.id || '').toLowerCase();
                const hay = (code + ' ' + titre + ' ' + client + ' ' + id).trim();
                if (!hay.includes(query)) continue;
                let score = 0;
                if (code.startsWith(query)) score += 50;
                if (titre.startsWith(query)) score += 25;
                if (client.startsWith(query)) score += 10;
                scored.push({
                    c,
                    score
                });
            }
            scored.sort((a, b) => b.score - a.score);
            return scored.slice(0, 12).map(s => s.c);
        }

        function hideChantierSuggestions() {
            if (!chantierSuggestions) return;
            chantierSuggestions.classList.add('hidden');
            chantierSuggestions.innerHTML = '';
        }

        function renderChantierSuggestions(q) {
            if (!chantierSuggestions) return;
            const items = filterChantiers(q);
            if (!items.length) {
                hideChantierSuggestions();
                return;
            }
            chantierSuggestions.classList.remove('hidden');
            chantierSuggestions.innerHTML = items.map(c => {
                const label = chantierLabel(c);
                const code = String(c.code || '').trim();
                return `<button type="button" class="w-full text-left px-3 py-2 text-[11px] hover:bg-indigo-50" data-code="${escapeHtml(code)}" data-label="${escapeHtml(label)}">${escapeHtml(label)}</button>`;
            }).join('');

            chantierSuggestions.querySelectorAll('button[data-code]').forEach(b => {
                b.addEventListener('click', () => {
                    const code = b.getAttribute('data-code') || '';
                    const label = b.getAttribute('data-label') || '';
                    inChantier.value = code;
                    if (chantierSelected) {
                        chantierSelected.textContent = label;
                        chantierSelected.classList.remove('hidden');
                    }
                    hideChantierSuggestions();
                    // Appliquer aussi au filtre principal + recharger la liste
                    applyChantierToSearch(code);
                });
            });
        }

        async function handleChantierTyping() {
            await loadChantiersForEntreprise();
            renderChantierSuggestions(inChantier.value);
            if (chantierSelected) chantierSelected.classList.add('hidden');
        }

        if (inChantier && chantierSuggestions) {
            inChantier.addEventListener('focus', handleChantierTyping);
            inChantier.addEventListener('input', handleChantierTyping);
            document.addEventListener('click', (e) => {
                if (!chantierSuggestions) return;
                const target = e.target;
                if (target === inChantier) return;
                if (chantierSuggestions.contains(target)) return;
                hideChantierSuggestions();
            });
        }

        modeSel.addEventListener('change', () => {
            const mode = modeSel.value;
            setModeUI(mode);
            prefillFromSearch(mode);
        });

        // init
        setModeUI(modeSel.value);
        prefillFromSearch(modeSel.value);

        function resetBulkUi() {
            bulkDone = false;
            if (resultBox) resultBox.classList.remove('hidden');
            if (bulkStatus) bulkStatus.textContent = 'Traitement...';
            if (bulkCounts) bulkCounts.textContent = '0 / 0';
            if (bulkBar) bulkBar.style.width = '0%';
            if (bulkLogs) bulkLogs.textContent = '';
            if (bulkNote) bulkNote.textContent = '';
        }

        function appendBulkLog(line, level) {
            if (!bulkLogs) return;
            const maxLines = 600;
            const color = level === 'success' ? 'text-emerald-700' : (level === 'error' ? 'text-rose-700' : 'text-slate-700');
            const div = document.createElement('div');
            div.className = color;
            div.textContent = line;
            bulkLogs.appendChild(div);
            // Limite de lignes (supprime en haut)
            while (bulkLogs.childNodes.length > maxLines) {
                bulkLogs.removeChild(bulkLogs.firstChild);
            }
            bulkLogs.scrollTop = bulkLogs.scrollHeight;
        }

        function closeBulkStream() {
            if (bulkStream) {
                try {
                    bulkStream.close();
                } catch (e) {}
            }
            bulkStream = null;
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (isDemo) {
                if (typeof showToast === 'function') showToast('Mode démo: action désactivée', 'warning');
                return;
            }

            const mode = modeSel.value;
            const chantier = String(inChantier.value || '').trim();
            const beneficiaire = String(inBenef.value || '').trim();
            const date = String(inDate.value || '').trim();
            const max = parseInt(String(inMax.value || '200'), 10) || 200;

            if (mode === 'chantier' && !chantier) {
                alert('Veuillez saisir un chantier / code.');
                return;
            }
            if (mode === 'beneficiaire' && !beneficiaire) {
                alert('Veuillez saisir un bénéficiaire.');
                return;
            }
            if (mode === 'date' && !date) {
                alert('Veuillez choisir une date.');
                return;
            }

            const label = mode === 'chantier' ? chantier : (mode === 'beneficiaire' ? beneficiaire : date);
            const okConfirm = confirm(`Confirmer le décaissement de masse pour: ${label}\n\nLimite: ${Math.min(Math.max(max, 1), 500)} fiche(s).`);
            if (!okConfirm) return;

            const entreprise = getEntrepriseFromSearch();

            // Appliquer automatiquement le filtre chantier côté liste (si mode chantier)
            if (mode === 'chantier') {
                applyChantierToSearch(chantier);
            }

            submitBtn.disabled = true;
            resetBulkUi();
            closeBulkStream();

            const params = new URLSearchParams();
            params.set('mode', mode);
            params.set('chantier', chantier);
            params.set('beneficiaire', beneficiaire);
            params.set('date', date);
            params.set('max', String(Math.min(Math.max(max, 1), 500)));
            if (entreprise) params.set('entreprise', entreprise);
            if (inDefinitif && inDefinitif.checked) params.set('definitif', '1');

            try {
                bulkStream = new EventSource('request/decaisser_bulk_sse.php?' + params.toString());

                bulkStream.addEventListener('start', (ev) => {
                    try {
                        const d = JSON.parse(ev.data || '{}');
                        const total = parseInt(d.total || 0);
                        const truncated = !!d.truncated;
                        if (bulkStatus) bulkStatus.textContent = 'Traitement en cours...';
                        if (bulkCounts) bulkCounts.textContent = `0 / ${total}`;
                        if (bulkBar) bulkBar.style.width = '0%';
                        if (bulkNote) {
                            const def = d.query && parseInt(d.query.definitif || 0) === 1;
                            const parts = [];
                            if (truncated) parts.push('Limite appliquée: certaines fiches ne seront pas traitées.');
                            if (def) parts.push('Mode définitif: annulation impossible.');
                            bulkNote.textContent = parts.join(' ');
                        }
                        appendBulkLog(`Démarrage: ${total} fiche(s) à traiter`, 'info');
                    } catch (e) {
                        appendBulkLog('Démarrage...', 'info');
                    }
                });

                bulkStream.addEventListener('log', (ev) => {
                    try {
                        const d = JSON.parse(ev.data || '{}');
                        const num = d.num_fiche ? String(d.num_fiche) : '';
                        const msg = d.message ? String(d.message) : '';
                        const level = d.level ? String(d.level) : 'info';
                        appendBulkLog((num ? (num + ' - ') : '') + msg, level);
                    } catch (e) {
                        appendBulkLog(String(ev.data || ''), 'info');
                    }
                });

                bulkStream.addEventListener('progress', (ev) => {
                    try {
                        const d = JSON.parse(ev.data || '{}');
                        const processed = parseInt(d.processed || 0);
                        const success = parseInt(d.success || 0);
                        const failed = parseInt(d.failed || 0);
                        const total = parseInt(d.total || 0);
                        const pct = total > 0 ? Math.round((processed / total) * 100) : 0;
                        if (bulkCounts) bulkCounts.textContent = `${processed} / ${total} | OK ${success} | Échecs ${failed}`;
                        if (bulkBar) bulkBar.style.width = Math.min(Math.max(pct, 0), 100) + '%';
                    } catch (e) {}
                });

                bulkStream.addEventListener('done', (ev) => {
                    bulkDone = true;
                    try {
                        const d = JSON.parse(ev.data || '{}');
                        if (!d.ok) {
                            const msg = d.error || 'Erreur serveur';
                            if (bulkStatus) bulkStatus.textContent = 'Erreur';
                            appendBulkLog(String(msg), 'error');
                            if (typeof showToast === 'function') showToast(String(msg), 'error');
                            else alert(String(msg));
                        } else {
                            const success = parseInt(d.success || 0);
                            const failed = parseInt(d.failed || 0);
                            const processed = parseInt(d.processed || 0);
                            const total = parseInt(d.total || processed);
                            if (bulkStatus) bulkStatus.textContent = `Terminé: ${success} OK / ${failed} échec(s)`;
                            if (bulkCounts) bulkCounts.textContent = `${total} traité(s) | OK ${success} | Échecs ${failed}`;
                            if (bulkBar) bulkBar.style.width = '100%';
                            if (typeof showToast === 'function') showToast(`Décaissement masse: ${success} OK / ${failed} échec(s)`, failed > 0 ? 'warning' : 'success');
                            appendBulkLog('Terminé.', failed > 0 ? 'error' : 'success');
                            fetchAndRender();
                        }
                    } catch (e) {
                        if (bulkStatus) bulkStatus.textContent = 'Terminé';
                        appendBulkLog('Terminé.', 'info');
                        fetchAndRender();
                    } finally {
                        closeBulkStream();
                        submitBtn.disabled = false;
                    }
                });

                bulkStream.onerror = () => {
                    if (bulkDone) return;
                    if (bulkStatus) bulkStatus.textContent = 'Erreur (connexion interrompue)';
                    appendBulkLog('Connexion interrompue (session expirée ou erreur réseau).', 'error');
                    closeBulkStream();
                    submitBtn.disabled = false;
                };
            } catch (err) {
                if (bulkStatus) bulkStatus.textContent = 'Erreur';
                appendBulkLog('Impossible de démarrer le flux.', 'error');
                closeBulkStream();
                submitBtn.disabled = false;
            }
        });
    })();
    // ---- Modal annulation décaissement ----
    const modalAnnulation = document.createElement('div');
    modalAnnulation.className = 'modal fade';
    modalAnnulation.id = 'modalAnnulation';
    modalAnnulation.innerHTML = `<div class="modal-dialog modal-md modal-dialog-centered"><div class="modal-content"><div class="modal-header flex justify-between items-center"><h2 class="text-lg font-semibold text-red-600 m-0">Annuler décaissement</h2><button class="btn btn-danger-light btn-xs" data-close>&times;</button></div><div class='modal-body'><form id='formAnnulation' class='space-y-3'><input type='hidden' name='num_fiche' id='annul_num'><p class='text-xs text-gray-600'>Cette action remet la fiche en <strong>approbation</strong>. Les validations achat et le statut décaissement seront réinitialisés.</p><textarea name='motif' rows='3' class='form-input w-full text-xs' placeholder='Motif (optionnel)'></textarea><p id='annul_error' class='text-red-600 text-xs hidden'></p><div class='flex justify-end gap-2 pt-2'><button type='button' class='btn btn-ghost btn-xs' data-close>Fermer</button><button type='submit' class='btn btn-danger btn-xs'>Confirmer</button></div></form></div></div></div>`;
    document.body.appendChild(modalAnnulation);
    modalAnnulation.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => closeModal('modalAnnulation')));

    function ouvrirAnnulation(num) {
        document.getElementById('annul_num').value = num;
        openModal('modalAnnulation');
    }
    document.addEventListener('submit', async e => {
        if (e.target && e.target.id === 'formAnnulation') {
            e.preventDefault();
            const fd = new FormData(e.target);
            const r = await fetch('request/annuler_decaissement.php', {
                method: 'POST',
                body: fd
            });
            const j = await r.json();
            if (!j.ok) {
                const err = document.getElementById('annul_error');
                err.textContent = j.error || 'Erreur annulation';
                err.classList.remove('hidden');
                return;
            }
            closeModal('modalAnnulation');
            if (typeof showToast === 'function') showToast('Décaissement annulé', 'warning');
            fetchAndRender();
        }
        if (e.target && e.target.id === 'formValiderAchat') {
            e.preventDefault();
            const fd = new FormData(e.target);
            const err = document.getElementById('valachat_error');
            err.classList.add('hidden');
            const btns = e.target.querySelectorAll('button');
            btns.forEach(b => b.disabled = true);
            try {
                const r = await fetch('request/achat_validate.php', {
                    method: 'POST',
                    body: fd
                });
                const j = await r.json();
                if (!j.ok) {
                    err.textContent = j.error || 'Erreur validation';
                    err.classList.remove('hidden');
                } else {
                    closeModal('modalValiderAchat');
                    if (typeof showToast === 'function') showToast('Fiche validée achat', 'success');
                    else miniToast('Fiche validée achat');
                    fetchAndRender();
                }
            } catch {
                err.textContent = 'Erreur réseau';
                err.classList.remove('hidden');
            }
            btns.forEach(b => b.disabled = false);
        }
    });

    // Fallback mini toast si showToast absent
    function miniToast(msg) {
        if (typeof showToast === 'function') {
            showToast(msg, 'info');
            return;
        }
        let box = document.getElementById('mini-toast-box');
        if (!box) {
            box = document.createElement('div');
            box.id = 'mini-toast-box';
            box.style.position = 'fixed';
            box.style.top = '12px';
            box.style.right = '12px';
            box.style.zIndex = '9999';
            document.body.appendChild(box);
        }
        const t = document.createElement('div');
        t.textContent = msg;
        t.style.background = '#334155';
        t.style.color = '#fff';
        t.style.padding = '8px 14px';
        t.style.marginTop = '6px';
        t.style.borderRadius = '6px';
        t.style.fontSize = '12px';
        t.style.boxShadow = '0 2px 6px rgba(0,0,0,.25)';
        box.appendChild(t);
        setTimeout(() => {
            t.style.opacity = '0';
            t.style.transition = 'opacity .4s';
            setTimeout(() => t.remove(), 450);
        }, 2200);
    }
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
