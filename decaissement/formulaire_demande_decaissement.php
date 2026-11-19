<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'formulaire_demande_decaissement_view',
    'entity_type' => 'decaissement_form',
    'entity_label' => 'Formulaire demande decaissement',
    'page_name' => 'formulaire_demande_decaissement.php',
    'details' => [
        'view_mode' => 'form',
        'step' => $_GET['step'] ?? null,
    ],
]);

include 'headers/header_formulaire_demande_decaissement.php';

$preselectedFournisseurId = (int) ($_GET['fournisseur_id'] ?? 0);
require_once __DIR__.'/../services/FebSupplierBeneficiary.php';
$approvedSupplierBeneficiary = FebSupplierBeneficiary::resolve($pdo, $approvedFeb ?? []);
if ($approvedSupplierBeneficiary !== null) $preselectedFournisseurId = (int)$approvedSupplierBeneficiary['id_fournisseur'];


/**
 * @var array $listeAffectation
 * @var array $listeService
 * @var array $listeMotifDeboursement
 * @var array $listeChantierTechnique
 * @var array $listeLigneDeboursement
 * @var array $listeLigneDeboursementFournisseur
 * @var array $fournisseurInfo
 * @var array $ficheInfo
 * @var array $ficheInfoLigneDeboursement
 * @var array $listeFournisseursValides
 */
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulaire Fiche</title>
    <!-- Lien vers Bootstrap CSS -->
    <link href="plugins/css/bootstrap/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome CSS -->
    <link rel="stylesheet" href="plugins/css/fontawesome/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="css_form/style.css" rel="stylesheet">
    <style>
        .fiche-existante {
            background: linear-gradient(135deg, #e3f2fd 0%, #f8f9fa 100%);
            border: 1px solid #2196f3;
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .fiche-existante:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(33, 150, 243, 0.2);
        }

        .fiche-date {
            font-size: 0.85em;
            color: #666;
        }

        .fiche-montant {
            font-weight: bold;
            color: #28a745;
        }

        .fiche-beneficiaire {
            font-weight: 600;
            color: #333;
        }

        .alert-depassement {
            background: linear-gradient(135deg, #ffebee 0%, #fce4ec 100%);
            border: 1px solid #f44336;
            color: #c62828;
        }

        /* Curseur stylo pour la signature */
        #signature-pad.custom-cursor {
            cursor: url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABgAAAAYCAYAAADgdz34AAAByUlEQVRIS7WVv2sTURTHP/fOJo0gJhaBLKJgExK1aCFBqx9CFoWkUhC6CFhY2IlpJZ2Jj4FKr0IghkRLEQsQbCws7Y2NhYWCg2Jk0kQ2fmvnPPmTtnQtlNzvnPuOffee+65J8n3fBf4T8v1VZIkuU6SJGmJk+nkSnah5LFYLFgMBsvLy3/ak/d3d3+j0ajUdXV19g8HicTqdQKBQwGAy6urrYGwWCwA0zTtdlsNsFg0Ov1er8qlcr4fD5PJBLxeL0qlQhoMBtVqtdDodQK/Xg/P5DIFCqFQqFIpFIuFwuCwWC1tbWnjAYDHqdDqlUqgXg8Hufz+cvl8pl8u12g2EYjEYqqqqcDodQJIkcDqdYrEYDAYhz/P5zGazAY7jdbi6Xy2az2SyKoiiWl5f5fD6vVCqVQkqS8fl8wGIx8Pl8BoOBfD7/U6/X0+l0sFgs7e3tQVVVFWg0GgBVVVX4/X4NDodGo1HpdJpKpQJVVcXl5SW63S6NRiMfjgYGBHk8nlUpndHpdIpFIrFarVarfb7fbDYDAYDAYDqdfr9drpdJmXy6XRaDR6vV6bm5v8wWCy2az2czMzO3o9XocDgcGg0GZTCbRaJTT6fT09PC4/H0+n0+n0qlYJk8nk8lkMvl8Ph8OBwO5XL5+trW1tbq6Oi4UCgUav12u12u1+s1qtVqtVoMBikUikcvl0qlUpqtRqNRoPBoNFqtVqt1ut9vtdvt9ns9lsvlqtVoMBiGeywWCxAIpFAIBAKpVKFRCIRqPRaDTqdDgcDsdlsNo1Go4HA6XSaDRaIBgMplMplCqVSMRiMVqtFqtfrxWLBZDL5YgmUwGg0qlgr6+Pvy+Xy6TTqdfrlcvl8rlQqFQqlYpEIhEIplMplEr9cbk3lq4A1smF28uU5cAAAAASUVORK5CYII=') 4 20, crosshair;
        }

        /* Masquage forcé des infos budgétaires pour les utilisateurs non habilités */
        #montant-info-section[data-hidden-forbidden="1"] {
            display: none !important;
        }

        /* UI moderne de sélection multi-lignes (badges) */
        .precision-badge-ui {
            background: var(--bs-body-bg);
            border: 1px solid var(--bs-border-color);
            border-radius: .75rem;
            padding: .75rem;
        }

        #precision-options-list .list-group-item {
            cursor: pointer;
        }

        #precision-selected-badges .badge {
            line-height: 1.15;
        }

        #precision-selected-badges .btn-close {
            font-size: .55rem;
            opacity: .9;
        }

        .supplier-identity-card img {
            width: 84px;
            height: 84px;
            object-fit: cover;
            border-radius: 18px;
            border: 1px solid rgba(15, 23, 42, 0.08);
        }

        .supplier-step-layout {
            max-width: 980px;
            margin: 0 auto;
        }

        .supplier-picker-ui,
        .supplier-summary-panel,
        .supplier-alt-panel {
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.05);
            border-radius: 18px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.03);
        }

        .supplier-picker-ui {
            padding: 1rem;
        }

        .supplier-summary-panel,
        .supplier-alt-panel {
            padding: 1.1rem 1.15rem;
        }

        .supplier-picker-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            margin-bottom: .85rem;
        }

        .supplier-picker-trigger {
            min-width: 240px;
            border-radius: 999px;
            padding-inline: 1rem;
        }

        .supplier-picker-menu {
            min-width: min(420px, 92vw);
            border: 1px solid rgba(15, 23, 42, 0.06);
            border-radius: 18px;
            padding: .75rem;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
        }

        .supplier-picker-menu .form-control {
            border-radius: 12px;
        }

        .supplier-picker-empty {
            color: #6c757d;
            font-size: .875rem;
            padding: .5rem .25rem;
        }

        .supplier-current-selection {
            border: 1px solid rgba(13, 110, 253, 0.14);
            border-radius: 14px;
            padding: .85rem 1rem;
            background: #fcfdff;
        }

        .supplier-current-selection.is-empty {
            border-color: rgba(108, 117, 125, 0.16);
        }

        .supplier-current-selection .supplier-current-name {
            font-weight: 600;
            color: #0f172a;
        }

        .supplier-current-selection .supplier-current-meta {
            color: #64748b;
            font-size: .9rem;
        }

        .supplier-summary-panel {
            min-height: 100%;
            padding: 0;
            border: 0;
            box-shadow: none;
            background: transparent;
        }

        .supplier-summary-title,
        .supplier-alt-title {
            font-size: .95rem;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: .9rem;
        }

        .supplier-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: .75rem 1rem;
        }

        .supplier-summary-inline {
            padding: .2rem 0 .35rem;
        }

        .supplier-summary-header {
            display: flex;
            align-items: baseline;
            gap: .75rem;
            flex-wrap: wrap;
            margin-bottom: .65rem;
        }

        .supplier-summary-name {
            font-size: 1.05rem;
            font-weight: 600;
            color: #0f172a;
        }

        .supplier-summary-type {
            color: #64748b;
            font-size: .92rem;
        }

        .supplier-meta-item {
            min-width: 0;
        }

        .supplier-meta-label {
            display: block;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #64748b;
            margin-bottom: .2rem;
        }

        .supplier-meta-value {
            color: #0f172a;
            word-break: break-word;
        }

        .supplier-alt-panel {
            margin-top: 1rem;
            background: #fff;
            padding: 1rem 1.05rem;
        }

        .supplier-alt-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(132px, 1fr));
            gap: .85rem;
        }

        .supplier-alt-choice {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .55rem;
            width: 100%;
            padding: .85rem .75rem;
            background: #fff;
            border: 1px solid rgba(15, 23, 42, 0.06);
            border-radius: 18px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, 0.03);
            text-align: center;
            transition: transform .16s ease, box-shadow .16s ease, border-color .16s ease;
        }

        .supplier-alt-choice:hover,
        .supplier-alt-choice:focus {
            transform: translateY(-2px);
            border-color: rgba(13, 110, 253, 0.2);
            box-shadow: 0 8px 22px rgba(13, 110, 253, 0.08);
        }

        .supplier-alt-choice-visual {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            overflow: hidden;
            background: #f8fafc;
            border: 1px solid rgba(15, 23, 42, 0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
        }

        .supplier-alt-choice-visual img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .supplier-alt-choice-name {
            font-size: .86rem;
            line-height: 1.2;
            color: #334155;
            word-break: break-word;
        }

        .supplier-alt-choice-type {
            font-size: .74rem;
            color: #94a3b8;
        }

        .recap-fixed-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(160px, .6fr);
            gap: 1.5rem;
            align-items: start;
        }

        .recap-budget-section {
            margin-top: 1.5rem;
            border: 1px solid #dbe3f0;
            border-radius: 16px;
            background: #fff;
            overflow: hidden;
        }

        .recap-budget-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            padding: .95rem 1rem;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(180deg, #f8fbff 0%, #f1f5f9 100%);
        }

        .recap-budget-title {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .recap-budget-subtitle {
            color: #64748b;
            font-size: .85rem;
        }

        .recap-budget-table {
            margin-bottom: 0;
        }

        .recap-budget-table thead th {
            white-space: nowrap;
            font-size: .8rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #475569;
            background: #f8fafc;
        }

        .recap-line-main {
            font-weight: 600;
            color: #0f172a;
        }

        .recap-line-sub {
            margin-top: .2rem;
            font-size: .8rem;
            color: #64748b;
        }

        .recap-budget-totals {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: .85rem;
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .recap-total-card {
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #fff;
            padding: .85rem .95rem;
        }

        .recap-total-label {
            display: block;
            font-size: .76rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #64748b;
            margin-bottom: .3rem;
        }

        .recap-total-value {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        .recap-empty-state {
            padding: 1rem;
            color: #64748b;
            font-size: .9rem;
        }

        @media (max-width: 767.98px) {
            .supplier-picker-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .supplier-picker-trigger {
                width: 100%;
            }

            .supplier-picker-menu {
                min-width: 100%;
            }

            .recap-fixed-grid {
                grid-template-columns: 1fr;
            }

            .recap-budget-totals {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>

<body>
    <div class="container mt-5">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <a href="<?= htmlspecialchars((string) ($_SERVER['HTTP_REFERER'] ?? '../selection_portail.php')) ?>" class="btn btn-outline-secondary" onclick="if (window.history.length > 1) { event.preventDefault(); window.history.back(); }">
                <i class="fas fa-arrow-left"></i> Retour à la page précédente
            </a>
        </div>
        <h1 class="text-center mb-4">Formulaire de décaissement</h1>

        <div class="progress mb-4">
            <div class="progress-bar" role="progressbar" style="width: 14.3%;" id="progress-bar">Étape 1 sur 7</div>
        </div>

        <form id="form_fiche" enctype="multipart/form-data" method="post">
            <?php if (!empty($_GET['urgent_id'])): ?>
                <input type="hidden" name="urgent_id" value="<?= (int) $_GET['urgent_id'] ?>">
            <?php endif; ?>
            <!-- Étape 0 : Sélection de l'entreprise -->
            <div class="setup-content active" id="step-0">

                <div class="enterprise-selection text-center my-5">
                    <div class="icon-container mb-3">
                        <i class="fa fa-building fa-3x"></i>
                    </div>
                    <h2 class="enterprise-title text-dark fw-bold">Choisissez une entreprise</h2>
                    <p class="enterprise-description text-muted mt-2">
                        Sélectionnez une entreprise dans la liste pour continuer.
                    </p>
                </div>

                <div class="selection-container">
                    <!-- Première carte : FIDEST -->
                    <div class="company-card" data-target="#step-1" data-company="FIDEST">
                        <div class="company-card-inner">
                            <img src="https://app.fidest.ci/logi/img/logo_connex.png" alt="Logo FIDEST" class="company-logo">
                            <div class="company-name">CHANTIER FIDEST</div>
                        </div>
                    </div>

                    <!-- Deuxième carte : BANAMUR -->
                    <div class="company-card" data-target="#step-1" data-company="BANAMUR">
                        <div class="company-card-inner">
                            <img src="https://assets.codeur.com/uli89xy5439jz7s5ud67n92qi9g8" alt="Logo BANAMUR" class="company-logo">
                            <div class="company-name">CHANTIER BANAMUR</div>
                        </div>
                    </div>

                    <!-- Troisième carte : BUREAU FIDEST BANAMUR -->
                    <div class="company-card" data-target="#step-1" data-company="BUREAU FIDEST BANAMUR">
                        <div class="company-card-inner">
                            <div class="logos-combined">
                                <img src="https://app.fidest.ci/logi/img/logo_connex.png" alt="Logo FIDEST" class="company-logo small-logo">
                                <img src="https://assets.codeur.com/uli89xy5439jz7s5ud67n92qi9g8" alt="Logo BANAMUR" class="company-logo small-logo">
                            </div>
                            <div class="company-name">BUREAU FIDEST BANAMUR</div>
                        </div>
                    </div>
                </div>


            </div>

            <!-- Étape 1 : Informations personnelles -->
            <div class="setup-content" id="step-1">

                <div class="enterprise-selection text-center my-5">
                    <div class="icon-container mb-3">
                        <i class="fas fa-user fa-3x"></i>
                    </div>
                    <h2 class="enterprise-title text-dark fw-bold">Informations Personnelles</h2>
                    <p class="enterprise-description text-muted mt-2">
                        Fournissez vos coordonnées complètes.
                    </p>
                </div>

                <!-- Étape additionnelle (dans l'étape 1) : bénéficiaire membre du personnel ? -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div>
                                <div class="fw-semibold">
                                    <i class="fas fa-user-tie"></i> Le bénéficiaire fait partie du personnel ?
                                </div>
                                <div class="text-muted small">
                                    Si oui, recherchez la personne pour pré-remplir <strong>Nom</strong>, <strong>Téléphone</strong> et, si disponible, la <strong>Photo</strong> / <strong>CNI</strong>.
                                </div>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="beneficiaire_is_personnel" name="beneficiaire_is_personnel" value="1">
                                <label class="form-check-label" for="beneficiaire_is_personnel">Oui</label>
                            </div>
                        </div>

                        <!-- Champs cachés pour intégration RH (influencent la validation + soumission) -->
                        <input type="hidden" id="personnel_id" name="personnel_id" value="">
                        <input type="hidden" id="personnel_has_photo" name="personnel_has_photo" value="0">
                        <input type="hidden" id="personnel_has_cni" name="personnel_has_cni" value="0">
                        <input type="hidden" id="personnel_photo_url" name="personnel_photo_url" value="">
                        <input type="hidden" id="personnel_cni_url" name="personnel_cni_url" value="">

                        <div id="personnel-search-container" class="mt-3" style="display:none;">
                            <label for="personnel_search" class="form-label">Rechercher un employé</label>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="personnel_search" placeholder="Tapez le nom de la personne..." autocomplete="off">
                                <div id="personnel-search-results" class="list-group position-absolute w-100 shadow-sm" style="z-index:1050; max-height:240px; overflow:auto; display:none;"></div>
                            </div>
                            <div id="personnel-required-hint" class="text-danger small mt-2" style="display:none;">Sélectionnez une personne avant de continuer.</div>

                            <div id="personnel-selected" class="mt-3" style="display:none;">
                                <div class="card border-primary">
                                    <div class="card-body p-3">
                                        <div class="d-flex gap-3 align-items-start flex-wrap">
                                            <div style="min-width:92px;">
                                                <img id="personnel-photo-preview" src="" alt="Photo personnel" class="img-thumbnail" style="width:92px; height:92px; object-fit:cover; display:none;">
                                                <div id="personnel-photo-missing" class="text-muted small" style="display:none;">Photo indisponible</div>
                                            </div>
                                            <div style="min-width:220px;">
                                                <div class="fw-semibold" id="personnel-fullname-preview"></div>
                                                <div class="text-muted small">Téléphone : <span id="personnel-phone-preview">—</span></div>
                                                <div class="text-muted small">ID : <span id="personnel-id-preview">—</span></div>
                                            </div>
                                            <div style="min-width:140px;">
                                                <div class="text-muted small mb-1">Pièce d'identité</div>
                                                <img id="personnel-cni-preview" src="" alt="CNI personnel" class="img-thumbnail" style="width:140px; height:92px; object-fit:cover; display:none;">
                                                <div id="personnel-cni-missing" class="text-muted small" style="display:none;">CNI indisponible</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="nom_prenom" class="form-label control-label">Nom et Prénom(s)</label>
                    <input type="text" class="form-control" id="nom_prenom" name="nom_prenom" placeholder="Nom et Prénom(s)" oninput="validateName()" onchange="validateName()" title="Veuillez saisir le nom." required>
                    <div id="name-error-message" style="color: red; display: none; margin : 4px;">Veuillez saisir le nom.</div>
                    <div id="name-succes-message" style="color: green; display: none; margin : 4px;"><i class="fa fa-check-circle"></i> Parfaitement renseigné !</div>
                </div>

                <div class="mb-3">
                    <label for="telephone" class="form-label control-label">Numéro de téléphone</label>
                    <input type="text" class="form-control" id="telephone" name="telephone" placeholder="Numéro de téléphone" required oninput="validatePhoneNumber()" pattern="^\d{10}$" title="Le numéro doit contenir exactement 10 chiffres">
                    <div id="tel-error-message" style="color: red; display: none; margin : 4px;">Veuillez entrer un numéro de téléphone valide (exactement 10 chiffres).</div>
                    <div id="tel-succes-message" style="color: green; display: none; margin : 4px;"><i class="fa fa-check-circle"></i> Super ! Continuons.</div>
                </div>
                <div class="d-flex justify-content-between mt-4">
                    <button type="button" class="btn btn-primary prevBtn" data-target="#step-0">
                        <i class="fas fa-arrow-left"></i> Précédent
                    </button>
                    <button id="first_next_button" type="button" class="btn btn-primary nextBtn" data-target="#step-2">
                        Suivant <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>

            <!-- Étape 2 : A propos de la demande -->
            <div class="setup-content" id="step-2">

                <div class="enterprise-selection text-center my-5">
                    <div class="icon-container mb-3">
                        <i class="fas fa-question-circle fa-3x"></i>
                    </div>
                    <h2 class="enterprise-title text-dark fw-bold">A Propos de la demande</h2>
                    <p class="enterprise-description text-muted mt-2">
                        Décrivez brièvement votre demande.
                    </p>
                </div>

                <div class="mb-3">
                    <label for="affectation" class="form-label control-label">Affectation</label>
                    <select class="form-control" id="affectation" name="affectation" required>
                        <option value="">--Choisir Affectation--</option>
                        <?php foreach ($listeAffectation as $affectation) { ?>
                            <option value="<?php echo $affectation['id_affectation'] ?>"><?php echo $affectation['lib_affectation'] ?></option>
                        <?php } ?>
                    </select>
                    <div id="affectation-error-message" style="color: red; display: none; margin : 4px;">Veuillez sélectionner au moins une affectation.</div>
                    <div id="affectation-succes-message" style="color: green; display: none; margin : 4px;"><i class="fa fa-check-circle"></i> Affectation validée !</div>
                    <div id="bureau-chantier-info" class="alert alert-info small mt-2" style="display:none;">
                        <strong>INFO</strong> — Pour l’affectation <strong>Bureau</strong>, la demande est automatiquement rattachée au chantier technique <strong>CH0066</strong>
                        (chantier à <strong>devis</strong> et <strong>déboursé</strong> nuls). Vous n’avez rien à modifier.
                    </div>
                </div>
                <div class="mb-3" id="chantier-select" style="display:none;">
                    <label for="chantier" id="chantier-label" class="form-label control-label">Code Chantier</label>
                    <select class="form-control" id="chantier" name="chantier" required>
                        <option value="">--Choisir Chantier--</option>
                    </select>
                    <div id="chantier-loading" class="text-center mt-2" style="display: none;">
                        <i class="fas fa-spinner fa-spin"></i> Chargement des chantiers...
                    </div>
                    <div id="chantier-block-reason" class="alert alert-danger small mt-2" style="display:none;"></div>
                </div>
                <div class="mb-3" id="bureau-select" style="display:none;">
                    <label for="service" class="form-label control-label">Service Concerné</label>
                    <select class="form-control" id="service" name="service" required>
                        <option value="">--Choisir Service--</option>
                        <?php foreach ($listeService as $service) { ?>
                            <option value="<?php echo $service['id_serv_bureau_banamur'] ?>"><?php echo $service['lib_serv_bureau_banamur'] ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="mb-3" id="motif-select" style="display:none;">
                    <label for="motif_select" class="form-label control-label">Motif de la demande (Déboursé)</label>
                    <select class="form-control" id="motif_select" name="motif_select">
                        <option value="">--Choisir un déboursé--</option>
                    </select>
                    <div id="motif-loading" class="text-center mt-2" style="display: none;">
                        <i class="fas fa-spinner fa-spin"></i> Chargement des déboursés...
                    </div>
                </div>
                <!-- Champ motif libre (pour affectation bureau) -->
                <div class="mb-3" id="motif-input" style="display:none;">
                    <label for="motif_input" class="form-label control-label">Motif de la demande</label>
                    <input type="text" class="form-control" id="motif_input" name="motif_input" placeholder="Motif de la demande" disabled>
                </div>
                <!-- Champ caché unifié pour envoi -->
                <input type="hidden" id="motif_final" name="motif_final" value="">
                <div class="mb-3" id="precision-select" style="display:none;">
                    <label for="precision_select" class="form-label control-label">Demande précise</label>
                    <!-- Select conservé pour compat (JS existant + submit) mais masqué au profit d'une UI moderne -->
                    <select class="form-control d-none" id="precision_select" name="precision_select" multiple size="6" aria-hidden="true" tabindex="-1">
                        <option value="" disabled selected>--Choisir une demande précise--</option>
                    </select>

                    <!-- UI moderne: badges + dropdown -->
                    <div id="precision-badge-ui" class="precision-badge-ui">
                        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
                            <div class="text-muted small">
                                <i class="fas fa-list-check"></i> Ligne sélectionnée
                                <span id="precision-selected-count" class="ms-1 badge bg-secondary">0</span>
                            </div>

                            <div class="dropdown" data-bs-auto-close="outside">
                                <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" id="precisionBadgeDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    Choisir la ligne
                                </button>
                                <div class="dropdown-menu dropdown-menu-end p-2 shadow-sm" aria-labelledby="precisionBadgeDropdown" style="min-width: 360px; max-height: 340px; overflow:auto;">
                                    <div class="mb-2">
                                        <input type="text" class="form-control form-control-sm" id="precision-search" placeholder="Rechercher une ligne...">
                                    </div>
                                    <div id="precision-options-list" class="list-group list-group-flush"></div>
                                </div>
                            </div>
                        </div>

                        <div id="precision-selected-badges" class="d-flex flex-wrap gap-2"></div>
                        <div id="precision-selected-empty" class="text-muted small">Aucune ligne sélectionnée.</div>
                        <div class="form-text text-muted mt-2">
                            Choisissez une seule ligne de déboursé pour ce code FEB.
                        </div>
                    </div>

                    <div id="precision-multi-info" class="alert alert-info mt-2" style="display:none;">
                        <i class="fas fa-info-circle"></i> Un code FEB ne peut produire qu'une seule fiche de décaissement.
                    </div>
                    <div id="precision-loading" class="text-center mt-2" style="display: none;">
                        <i class="fas fa-spinner fa-spin"></i> Chargement des lignes...
                    </div>
                </div>

                <!-- Bloc conditionnel Spécifications Transport-Dépollution (CH060 + carburant) -->
                <div class="mb-3" id="spec-transport-box" style="display:none;">
                    <div class="card border-success shadow-sm">
                        <div class="card-header bg-success text-white py-2">
                            <strong><i class="fas fa-truck-moving"></i> Spécifications Transport-Dépollution</strong>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label for="chauffeur_nom" class="form-label control-label">Nom du chauffeur</label>
                                    <input type="text" class="form-control" id="chauffeur_nom" name="chauffeur_nom" placeholder="Nom complet" autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label for="chauffeur_tel" class="form-label control-label">Téléphone chauffeur</label>
                                    <input type="text" class="form-control" id="chauffeur_tel" name="chauffeur_tel" placeholder="Ex: 0701020304" pattern="^\d{8,10}$" autocomplete="off">
                                </div>
                                <div class="col-md-4">
                                    <label for="vehicule_matricule" class="form-label control-label">Matricule véhicule</label>
                                    <input type="text" class="form-control" id="vehicule_matricule" name="vehicule_matricule" placeholder="Ex: 1234AB01" autocomplete="off">
                                </div>
                                <div class="col-md-3">
                                    <label for="volume_m3" class="form-label control-label">Volume (m³)</label>
                                    <input type="number" step="0.01" class="form-control" id="volume_m3" name="volume_m3" value="19.2" placeholder="m³" autocomplete="off">
                                </div>
                                <div class="col-md-3">
                                    <label for="quantite_carburant" class="form-label control-label">Quantité carburant (L)</label>
                                    <input type="number" step="0.01" class="form-control" id="quantite_carburant" name="quantite_carburant" value="50" placeholder="Litres" autocomplete="off" readonly>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="alert alert-info py-2 px-3 w-100 mb-0" style="font-size:0.85rem;">
                                        <i class="fas fa-info-circle"></i> Ces champs sont <strong>obligatoires</strong> pour le chantier <code>CH060</code> avec une demande précise contenant le mot <em>carburant</em>.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Informations sur les montants -->
                <?php if (!isset($canSeeFinance) || !$canSeeFinance): ?>
                    <div class="mb-3" id="montant-info-section" style="display:none;" data-hidden-forbidden="1">
                    <?php else: ?>
                        <div class="mb-3" id="montant-info-section" style="display:none;">
                        <?php endif; ?>
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="fas fa-calculator"></i> Informations budgétaires</h6>
                            </div>
                            <div class="card-body">
                                <!-- Mode mono-ligne (conserve les IDs pour le JS existant) -->
                                <div id="montant-info-single" class="row">
                                    <div class="col-md-4">
                                        <strong class="text-primary">Montant prévu :</strong>
                                        <div id="montant-prevu-display" class="h5 text-primary">0 FCFA</div>
                                    </div>
                                    <div class="col-md-4">
                                        <strong class="text-warning">Déjà décaissé :</strong>
                                        <div id="montant-decaisse-display" class="h5 text-warning">0 FCFA</div>
                                    </div>
                                    <div class="col-md-4">
                                        <strong class="text-success">Montant restant :</strong>
                                        <div id="montant-restant-display" class="h5 text-success">0 FCFA</div>
                                    </div>
                                </div>

                                <!-- Mode multi-lignes -->
                                <div id="montant-info-multi" style="display:none;">
                                    <div class="row g-3" id="montant-multi-cards"></div>
                                </div>
                            </div>
                        </div>
                        </div>

                        <!-- Fiches existantes -->
                        <div class="mb-3" id="fiches-existantes-section" style="display:none;">
                            <div class="card border-info">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="fas fa-layer-group"></i> Fiches liées à cette demande précise</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered align-middle" id="table-fiches-liees">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>N°</th>
                                                    <th>Bénéficiaire</th>
                                                    <th class="text-end">Montant</th>
                                                    <th>Etat</th>
                                                    <th>Date</th>
                                                    <th>Sig.</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                            <tfoot class="table-light" id="tfoot-fiches-liees"></tfoot>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- Modal fiche liée -->
                        <div class="modal fade" id="modalFicheLiee" tabindex="-1">
                            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-file-invoice"></i> Détails fiche <span id="modal-fiche-num"></span></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body" id="modal-fiche-body">
                                        <div class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Chargement...</div>
                                    </div>
                                    <div class="modal-footer">
                                        <a id="modal-open-page" href="#" target="_blank" class="btn btn-outline-primary"><i class="fas fa-external-link-alt"></i> Ouvrir page</a>
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="details_demande" class="form-label control-label">Détails sur la demande</label>
                            <textarea class="form-control" id="details_demande" name="details_demande" rows="3" placeholder="Détails supplémentaires" required></textarea>
                            <div class="form-text text-muted">
                                Ces détails décrivent la fiche de décaissement liée au code FEB.
                            </div>
                        </div>
                        <div class="mb-3 alert alert-light border" id="supplier-step-hint" style="display:none;">
                            <strong><i class="fas fa-circle-info"></i> Étape fournisseur :</strong>
                            une étape dédiée s'affichera ensuite pour choisir un fournisseur validé, voir ses catégories et basculer rapidement vers une alternative de même catégorie.
                            <div class="mt-2 text-muted small">La sélection sera appliquée à la fiche générée avec ce code FEB.</div>
                        </div>
                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-primary prevBtn" data-target="#step-1">
                                <i class="fas fa-arrow-left"></i> Précédent
                            </button>
                            <button id="first_next_button_2" type="button" class="btn btn-primary nextBtn" data-target="#step-3">
                                Suivant <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <div class="setup-content" id="step-supplier">
                        <div class="enterprise-selection text-center my-5">
                            <div class="icon-container mb-3">
                                <i class="fas fa-truck-field fa-3x"></i>
                            </div>
                            <h2 class="enterprise-title text-dark fw-bold">Choix du fournisseur</h2>
                            <p class="enterprise-description text-muted mt-2">
                                Cette étape est obligatoire pour toutes les fiches de décaissement.
                            </p>
                        </div>

                        <div class="mb-3" id="fournisseur-selection-block" style="display:none;">
                            <div class="supplier-step-layout">
                                <div class="row g-4 align-items-start">
                                    <div class="col-lg-5">
                                        <label for="fournisseur_search" class="form-label control-label">Fournisseur validé</label>
                                        <div class="supplier-picker-ui">
                                            <div class="supplier-picker-toolbar">
                                                <div class="text-muted small">
                                                    <i class="fas fa-search"></i> Recherche rapide par nom, catégorie ou contact
                                                </div>
                                                <div class="dropdown" data-bs-auto-close="outside">
                                                    <button class="btn btn-outline-primary btn-sm dropdown-toggle supplier-picker-trigger" type="button" id="supplierPickerDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                                        Choisir un fournisseur
                                                    </button>
                                                    <div class="dropdown-menu dropdown-menu-end supplier-picker-menu" aria-labelledby="supplierPickerDropdown">
                                                        <div class="mb-2">
                                                            <input type="text" class="form-control form-control-sm" id="fournisseur_search" placeholder="Rechercher un fournisseur...">
                                                        </div>
                                                        <div id="fournisseur-options-list" class="list-group list-group-flush"></div>
                                                    </div>
                                                </div>
                                            </div>

                                            <select class="form-control d-none" id="fournisseur_id" name="fournisseur_id" aria-hidden="true" tabindex="-1">
                                                <option value="">-- Sélectionner un fournisseur validé --</option>
                                                <?php foreach ($listeFournisseursValides as $fournisseurOption): ?>
                                                    <?php $visualPath = (string) ($fournisseurOption['visual_path'] ?? ''); ?>
                                                    <option
                                                        value="<?= (int)$fournisseurOption['id_fournisseur'] ?>"
                                                        <?= !empty($fournisseurOption['disbursement_selection_blocked']) ? 'disabled' : '' ?>
                                                        <?= $preselectedFournisseurId === (int) $fournisseurOption['id_fournisseur'] ? 'selected' : '' ?>
                                                        data-display-name="<?= htmlspecialchars((string)($fournisseurOption['supplier_display_name'] ?? $fournisseurOption['nom_commercial'] ?? $fournisseurOption['raison_sociale']), ENT_QUOTES) ?>"
                                                        data-legal-name="<?= htmlspecialchars((string)($fournisseurOption['supplier_legal_name'] ?? $fournisseurOption['raison_sociale'] ?? ''), ENT_QUOTES) ?>"
                                                        data-legal-form="<?= htmlspecialchars((string)($fournisseurOption['raison_sociale'] ?? ''), ENT_QUOTES) ?>"
                                                        data-person-last-name="<?= htmlspecialchars((string)($fournisseurOption['person_last_name'] ?? ''), ENT_QUOTES) ?>"
                                                        data-person-first-name="<?= htmlspecialchars((string)($fournisseurOption['person_first_name'] ?? ''), ENT_QUOTES) ?>"
                                                        data-contact="<?= htmlspecialchars((string)($fournisseurOption['contact_nom'] ?? ''), ENT_QUOTES) ?>"
                                                        data-telephone="<?= htmlspecialchars((string)($fournisseurOption['telephone'] ?? ''), ENT_QUOTES) ?>"
                                                        data-email="<?= htmlspecialchars((string)($fournisseurOption['email'] ?? ''), ENT_QUOTES) ?>"
                                                        data-address="<?= htmlspecialchars((string)($fournisseurOption['adresse'] ?? ''), ENT_QUOTES) ?>"
                                                        data-type="<?= htmlspecialchars((string)($fournisseurOption['supplier_type'] ?? 'morale'), ENT_QUOTES) ?>"
                                                        data-type-label="<?= htmlspecialchars((string)($fournisseurOption['supplier_type_label'] ?? 'Personne morale'), ENT_QUOTES) ?>"
                                                        data-categories="<?= htmlspecialchars((string)($fournisseurOption['category_names'] ?? ''), ENT_QUOTES) ?>"
                                                        data-documents="<?= htmlspecialchars((string)($fournisseurOption['document_names'] ?? ''), ENT_QUOTES) ?>"
                                                        data-visual="<?= htmlspecialchars($visualPath !== '' ? ('../' . $visualPath) : '', ENT_QUOTES) ?>"
                                                        data-cni="<?= htmlspecialchars(!empty($fournisseurOption['cni_path']) ? ('../' . (string) $fournisseurOption['cni_path']) : '', ENT_QUOTES) ?>">
                                                        <?= htmlspecialchars((string)($fournisseurOption['supplier_display_name'] ?? $fournisseurOption['nom_commercial'] ?? $fournisseurOption['raison_sociale'])) ?><?= !empty($fournisseurOption['raison_sociale']) && !empty($fournisseurOption['supplier_display_name']) && $fournisseurOption['supplier_display_name'] !== $fournisseurOption['raison_sociale'] ? ' — ' . htmlspecialchars((string) $fournisseurOption['raison_sociale']) : '' ?><?= !empty($fournisseurOption['category_names']) ? ' · ' . htmlspecialchars((string) $fournisseurOption['category_names']) : '' ?><?= !empty($fournisseurOption['disbursement_selection_blocked']) ? ' — INDISPONIBLE : '.htmlspecialchars((string)($fournisseurOption['disbursement_block_reason'] ?? 'justificatifs à fournir')).' · '.htmlspecialchars((string)($fournisseurOption['disbursement_block_fiches'] ?? '')) : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <div id="fournisseur-current-selection" class="supplier-current-selection is-empty mt-3">
                                                <div class="supplier-current-name" id="fournisseur-current-name">Aucun fournisseur sélectionné</div>
                                                <div class="supplier-current-meta" id="fournisseur-current-meta">La sélection apparaîtra ici avant validation de l'étape.</div>
                                            </div>
                                            <div id="fournisseur-required-hint" class="text-danger small mt-2" style="display:none;">Sélection obligatoire pour toute fiche de décaissement.</div>
                                        </div>
                                    </div>
                                    <div class="col-lg-7">
                                        <div id="fournisseur-selected-summary" class="supplier-summary-panel" style="display:none;">
                                            <div class="supplier-summary-title"><i class="fas fa-circle-check"></i> Fournisseur retenu</div>
                                            <div class="supplier-summary-inline">
                                                <div class="supplier-summary-header">
                                                    <div class="supplier-summary-name" id="fournisseur-name-display">—</div>
                                                    <div class="supplier-summary-type" id="fournisseur-type-display">—</div>
                                                </div>
                                                <div class="supplier-meta-grid">
                                                    <div class="supplier-meta-item">
                                                        <span class="supplier-meta-label">Contact</span>
                                                        <span class="supplier-meta-value" id="fournisseur-contact-display">—</span>
                                                    </div>
                                                    <div class="supplier-meta-item">
                                                        <span class="supplier-meta-label">Téléphone</span>
                                                        <span class="supplier-meta-value" id="fournisseur-telephone-display">—</span>
                                                    </div>
                                                    <div class="supplier-meta-item">
                                                        <span class="supplier-meta-label">Email</span>
                                                        <span class="supplier-meta-value" id="fournisseur-email-display">—</span>
                                                    </div>
                                                    <div class="supplier-meta-item">
                                                        <span class="supplier-meta-label">Catégories</span>
                                                        <span class="supplier-meta-value" id="fournisseur-categories-display">—</span>
                                                    </div>
                                                    <div class="supplier-meta-item">
                                                        <span class="supplier-meta-label">Adresse</span>
                                                        <span class="supplier-meta-value" id="fournisseur-address-display">—</span>
                                                    </div>
                                                    <div class="supplier-meta-item">
                                                        <span class="supplier-meta-label">Pièces enregistrées</span>
                                                        <span class="supplier-meta-value" id="fournisseur-documents-display">—</span>
                                                    </div>
                                                </div>
                                                <div id="fournisseur-cni-display-wrapper" class="mt-3" style="display:none;">
                                                    <a id="fournisseur-cni-display" href="#" target="_blank" class="btn btn-outline-secondary btn-sm">
                                                        <i class="fas fa-id-card"></i> Voir la CNI
                                                    </a>
                                                </div>
                                            </div>
                                            <div class="mt-3 small text-muted border-top pt-2">
                                                Ce fournisseur sera enregistré sur la fiche créée avec ce code FEB.
                                            </div>
                                        </div>
                                        <div class="supplier-alt-panel">
                                            <div class="supplier-alt-title"><i class="fas fa-tags"></i> Alternatives de même catégorie</div>
                                            <div id="fournisseur-same-category-list" class="supplier-alt-grid">
                                                <span class="text-muted small">Sélectionnez d'abord un fournisseur pour voir les alternatives.</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-primary prevBtn" data-target="#step-2">
                                <i class="fas fa-arrow-left"></i> Précédent
                            </button>
                            <button id="first_next_button_supplier" type="button" class="btn btn-primary nextBtn" data-target="#step-3">
                                Suivant <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Étape 3 : Montant et Mode de paiement -->
                    <div class="setup-content" id="step-3">

                        <div class="enterprise-selection text-center my-5">
                            <div class="icon-container mb-3">
                                <i class="fas fa-dollar-sign fa-3x"></i>
                            </div>
                            <h2 class="enterprise-title text-dark fw-bold">Données de décaissement</h2>
                            <p class="enterprise-description text-muted mt-2">
                                Indiquez les détails financiers.
                            </p>
                        </div>

                        <!-- Montant (ancien système / bureau) -->
                        <div id="montant-single-block">
                            <div class="mb-3">
                                <label for="montant" class="form-label control-label">Montant</label>
                                <input type="number" class="form-control" id="montant" name="montant" placeholder="Montant" oninput="validateMontant()" pattern="^[1-9]\d*$" title="Veuillez saisir un montant en FCFA." required>
                                <div id="montant-error-message" style="color: red; display: none; margin : 4px;">Veuillez saisir un montant en FCFA.</div>
                                <div id="montant-succes-message" style="color: green; display: none; margin : 4px;"><i class="fa fa-check-circle"></i> Excellent, c'est un montant valide !</div>
                            </div>
                            <!-- Alerte dépassement montant (ancien système) -->
                            <div id="montant-depassement-alert" class="alert alert-danger mt-2" style="display:none; font-weight:bold;">
                                <i class="fas fa-exclamation-triangle"></i> Dépassement du montant prévu pour cette tâche (ou opération) !
                            </div>
                        </div>

                        <!-- Montants par ligne (nouveau système multi-lignes) -->
                        <div id="montant-multi-block" style="display:none;">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h6 class="mb-0"><i class="fas fa-table"></i> Répartition des montants par ligne de déboursé</h6>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered align-middle" id="table-montants-lignes">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Ligne de déboursé</th>
                                                    <th class="text-end">Prévu</th>
                                                    <th class="text-end">Déjà décaissé</th>
                                                    <th class="text-end">Restant</th>
                                                    <th class="text-end">Montant demandé</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody></tbody>
                                        </table>
                                    </div>
                                    <div id="multi-depassement-alert" class="alert alert-danger mt-2" style="display:none; font-weight:bold;">
                                        <i class="fas fa-exclamation-triangle"></i> Dépassement budgétaire détecté sur une ou plusieurs lignes.
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="mode_paiement" class="control-label">Mode de paiement</label>
                            <div class="payment-options">
                                <div class="payment-card">
                                    <input type="radio" id="orange_money" name="mode_paiement" value="Orange Money" required />
                                    <label for="orange_money">
                                        <img src="payment_icon/logo_om.png" alt="Orange Money" />
                                        <span>OM</span>
                                    </label>
                                </div>

                                <div class="payment-card">
                                    <input type="radio" id="mtn_money" name="mode_paiement" value="MTN Money" required />
                                    <label for="mtn_money">
                                        <img src="payment_icon/logo_momo.png" alt="MTN Money" />
                                        <span>Momo</span>
                                    </label>
                                </div>

                                <div class="payment-card">
                                    <input type="radio" id="moov_money" name="mode_paiement" value="Moov Money" required />
                                    <label for="moov_money">
                                        <img src="payment_icon/logo_flooz.png" alt="Moov Money" />
                                        <span>Flooz</span>
                                    </label>
                                </div>

                                <div class="payment-card">
                                    <input type="radio" id="wave" name="mode_paiement" value="Wave" required />
                                    <label for="wave">
                                        <img src="payment_icon/logo_wave.png" alt="Wave" />
                                        <span>Wave</span>
                                    </label>
                                </div>

                                <div class="payment-card">
                                    <input type="radio" id="cash" name="mode_paiement" value="Cash" required />
                                    <label for="cash">
                                        <img src="payment_icon/logo_cash.png" alt="Cash" />
                                        <span>Cash</span>
                                    </label>
                                </div>

                                <div class="payment-card">
                                    <input type="radio" id="djamo" name="mode_paiement" value="Djamo" required />
                                    <label for="djamo">
                                        <img src="payment_icon/logo_djamo.png" alt="Djamo" />
                                        <span>Djamo</span>
                                    </label>
                                </div>

                                <div class="payment-card">
                                    <input type="radio" id="cheque" name="mode_paiement" value="Chèque" required />
                                    <label for="cheque">
                                        <img src="payment_icon/logo_cheque.png" alt="Chèque" />
                                        <span>Chèque</span>
                                    </label>
                                </div>
                            </div>

                        </div>
                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-primary prevBtn" data-target="#step-2">
                                <i class="fas fa-arrow-left"></i> Précédent
                            </button>
                            <button id="first_next_button_3" type="button" class="btn btn-primary nextBtn" data-target="#step-4">
                                Suivant <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <div id="approved-supplier-documents"
                         data-approved="<?= $approvedSupplierBeneficiary !== null ? '1' : '0' ?>"
                         data-company="<?= $approvedSupplierBeneficiary !== null && !FebSupplierBeneficiary::requiresIdentity($approvedSupplierBeneficiary) ? '1' : '0' ?>"
                         data-name="<?= htmlspecialchars((string)($approvedSupplierBeneficiary['nom_commercial'] ?? ''), ENT_QUOTES) ?>"
                         data-phone="<?= htmlspecialchars((string)($approvedSupplierBeneficiary['telephone'] ?? ''), ENT_QUOTES) ?>" hidden></div>
                    <?php if ($approvedSupplierBeneficiary !== null): ?>
                    <p class="alert alert-info">Le bénéficiaire est le fournisseur désigné dans la FEB validée. Ses documents sont repris de sa fiche. Pour une personne morale, aucune CNI n’est exigée.</p>
                    <?php endif; ?>
                    <!-- Étape 4 : Photo et CNI -->
                    <div class="setup-content" id="step-4">

                        <div class="enterprise-selection text-center my-5">
                            <div class="icon-container mb-3">
                                <i class="fas fa-id-badge fa-3x"></i>
                            </div>
                            <h2 class="enterprise-title text-dark fw-bold">Photo et Pièce d'identité</h2>
                            <p class="enterprise-description text-muted mt-2">Justifiez vos données personnelles</p>
                        </div>

                        <div class="row">
                            <!-- Photo du demandeur -->
                            <div class="col-md-6 mb-3">
                                <label for="photo_demandeur" class="form-label control-label">Photo du demandeur</label>
                                <div class="file-drop-area" id="photo-drop-area">
                                    <input type="file" id="photo_demandeur" name="photo_demandeur" accept="image/*" style="display:none;">
                                    <div class="file-preview" id="photo-preview"></div>
                                    <p>Faites glisser ou sélectionnez une photo</p>
                                    <p>Formats autorisés : jpg, png, jpeg</p>
                                </div>
                                <div id="photo_demandeur-error-message" style="color: red; display: none; margin : 4px;">Veuillez uploader votre photo.</div>
                                <div id="photo_demandeur-succes-message" style="color: green; display: none; margin : 4px;"><i class="fa fa-check-circle"></i> Photo téléchargée avec succès !</div>
                            </div>

                            <!-- CNI du demandeur -->
                            <div class="col-md-6 mb-3">
                                <label for="cni_demandeur" class="form-label control-label">CNI du demandeur</label>
                                <div class="file-drop-area" id="cni-drop-area">
                                    <input type="file" id="cni_demandeur" name="cni_demandeur" accept="image/*" style="display:none;">
                                    <div class="file-preview" id="cni-preview"></div>
                                    <p>Faites glisser ou sélectionnez une image de votre CNI</p>
                                    <p>Formats autorisés : jpg, png, jpeg</p>
                                </div>
                                <div id="cni_demandeur-error-message" style="color: red; display: none; margin : 4px;">Veuillez uploader votre CNI.</div>
                                <div id="cni_demandeur-succes-message" style="color: green; display: none; margin : 4px;"><i class="fa fa-check-circle"></i> Parfait !</div>
                            </div>

                            <!-- Signature manuscrite -->
                            <div class="col-12 mb-3">
                                <label class="form-label control-label">Signature du demandeur (au doigt / souris)</label>
                                <div class="card border-secondary">
                                    <div class="card-body" style="background:#fafafa;">
                                        <div id="signature-wrapper" style="position:relative; border:1px dashed #bbb; border-radius:6px; background:white;">
                                            <canvas id="signature-pad" class="custom-cursor" style="width:100%; height:230px; touch-action:none; display:block;"></canvas>
                                            <div id="signature-hint" style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); color:#888; pointer-events:none; font-size:14px;">Dessinez votre signature ici</div>
                                        </div>
                                        <div class="mt-2 d-flex gap-2 flex-wrap">
                                            <button type="button" id="btn-effacer-signature" class="btn btn-outline-secondary btn-sm"><i class="fas fa-eraser"></i> Effacer</button>
                                            <button type="button" id="btn-valider-signature" class="btn btn-outline-primary btn-sm"><i class="fas fa-check"></i> Utiliser la signature</button>
                                            <button type="button" id="btn-annuler-signature" class="btn btn-outline-danger btn-sm"><i class="fas fa-times"></i> Annuler signature</button>
                                            <span id="signature-status" class="ms-2 text-muted" style="font-size:13px;">Aucune signature capturée</span>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" id="signature_base64" name="signature_base64" value="" />
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-primary prevBtn" data-target="#step-3">
                                <i class="fas fa-arrow-left"></i> Précédent
                            </button>
                            <button id="first_next_button_4" type="button" class="btn btn-primary nextBtn" data-target="#step-5">
                                Suivant <i class="fas fa-arrow-right"></i>
                            </button>
                        </div>

                    </div>


                    <!-- Étape 6 : Recapitulatif de la demande -->
                    <div class="setup-content" id="step-5">
                        <div class="enterprise-selection text-center my-5">
                            <div class="icon-container mb-3">
                                <i class="fas fa-file fa-3x"></i>
                            </div>
                            <h2 class="enterprise-title text-dark fw-bold">Récapitulatif de la demande</h2>
                            <p class="enterprise-description text-muted mt-2">
                                Vérifiez les informations avant soumission.
                            </p>
                        </div>

                        <div id="recapitulatif-container" class="recapitulatif">
                            <div class="recap-header d-flex align-items-center justify-content-between">
                                <img id="company-logo" src="https://app.fidest.ci/logi/img/logo_connex.png" alt="Logo de l'entreprise" class="company-logo">
                                <h3 class="fw-bold">RÉCAPITULATIF DE LA DEMANDE</h3>
                            </div>

                            <div class="recap-body">
                                <div class="recap-fixed-grid">
                                    <div class="info-container">
                                        <h4>Informations personnelles</h4>
                                        <p><strong>Nom et Prénom :</strong> <span id="recap-nom-prenom">John Doe</span></p>
                                        <p><strong>Téléphone :</strong> <span id="recap-telephone">+225 01 23 45 67 89</span></p>
                                        <p><strong>Affectation :</strong> <span id="recap-affectation">Chantier</span></p>
                                        <p><strong>Motif de la demande :</strong> <span id="recap-motif">Avance sur salaire</span></p>
                                        <p><strong>Détails de la demande :</strong> <span id="recap-details">Achat urgent</span></p>
                                        <p id="recap-fournisseur-row" style="display:none;"><strong>Fournisseur :</strong> <span id="recap-fournisseur">—</span></p>
                                        <p id="recap-fournisseur-type-row" style="display:none;"><strong>Type fournisseur :</strong> <span id="recap-fournisseur-type">—</span></p>
                                        <p id="recap-fournisseur-categories-row" style="display:none;"><strong>Catégories fournisseur :</strong> <span id="recap-fournisseur-categories">—</span></p>
                                        <p id="recap-fournisseur-contact-row" style="display:none;"><strong>Contact fournisseur :</strong> <span id="recap-fournisseur-contact">—</span></p>
                                        <p id="recap-fournisseur-telephone-row" style="display:none;"><strong>Téléphone fournisseur :</strong> <span id="recap-fournisseur-telephone">—</span></p>
                                        <p id="recap-fournisseur-email-row" style="display:none;"><strong>Email fournisseur :</strong> <span id="recap-fournisseur-email">—</span></p>
                                        <p id="recap-fournisseur-address-row" style="display:none;"><strong>Adresse fournisseur :</strong> <span id="recap-fournisseur-address">—</span></p>
                                        <p id="recap-fournisseur-documents-row" style="display:none;"><strong>Pièces fournisseur :</strong> <span id="recap-fournisseur-documents">—</span></p>
                                        <p><strong>Mode de paiement :</strong> <span id="recap-mode">Orange Money</span></p>
                                    </div>

                                    <div>
                                        <div class="photo-container mt-0">
                                            <h4>Photo</h4>
                                            <img id="photo-identite" src="https://assets.codeur.com/uli89xy5439jz7s5ud67n92qi9g8" alt="Photo d'identité" class="photo-identite">
                                        </div>

                                        <div class="cni-container mt-3">
                                            <h4>Pièce d'identité</h4>
                                            <img id="cni-image" src="https://app.fidest.ci/logi/img/logo_connex.png" alt="Image de la CNI" class="cni-image">
                                        </div>
                                    </div>
                                </div>

                                <div id="recap-budget-section" class="recap-budget-section">
                                    <div class="recap-budget-header">
                                        <div>
                                            <h4 class="recap-budget-title">Synthèse financière par ligne</h4>
                                            <div class="recap-budget-subtitle">Prévu, déjà décaissé, demandé et statut pour chaque libellé.</div>
                                        </div>
                                    </div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle recap-budget-table">
                                            <thead>
                                                <tr>
                                                    <th>Libellé</th>
                                                    <th class="text-end">Prévu</th>
                                                    <th class="text-end">Déjà décaissé</th>
                                                    <th class="text-end">Demandé</th>
                                                    <th>Statut</th>
                                                </tr>
                                            </thead>
                                            <tbody id="recap-budget-table-body">
                                                <tr>
                                                    <td colspan="5" class="recap-empty-state">Les lignes budgétaires apparaîtront ici.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="recap-budget-totals">
                                        <div class="recap-total-card">
                                            <span class="recap-total-label">Total prévu</span>
                                            <span id="recap-total-prevu" class="recap-total-value">0 FCFA</span>
                                        </div>
                                        <div class="recap-total-card">
                                            <span class="recap-total-label">Total déjà décaissé</span>
                                            <span id="recap-total-decaisse" class="recap-total-value">0 FCFA</span>
                                        </div>
                                        <div class="recap-total-card">
                                            <span class="recap-total-label">Total demandé</span>
                                            <span id="recap-total-demande" class="recap-total-value">0 FCFA</span>
                                        </div>
                                        <div class="recap-total-card">
                                            <span class="recap-total-label">Reste estimé</span>
                                            <span id="recap-total-restant" class="recap-total-value">0 FCFA</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <h4>Signature</h4>
                                    <div id="recap-signature" style="min-height:120px; border:1px dashed #ccc; display:flex; align-items:center; justify-content:center; font-size:13px; color:#888; background:#fff;">
                                        Aucune signature
                                    </div>
                                    <div class="mt-2">
                                        <button type="button" id="btn-annuler-signature-recap" class="btn btn-outline-danger btn-sm"><i class="fas fa-times"></i> Annuler signature</button>
                                    </div>
                                </div>
                            </div>

                            <!-- <div class="recap-footer text-center mt-4">
                        <h5>Signatures</h5>
                        <div class="signatures d-flex justify-content-around mt-3">
                            <div>
                                <p class="text-muted">Demandeur</p>
                                <div class="signature"></div>
                            </div>
                            <div>
                                <p class="text-muted">Validateur</p>
                                <div class="signature"></div>
                            </div>
                            <div>
                                <p class="text-muted">Responsable</p>
                                <div class="signature"></div>
                            </div>
                        </div>
                    </div> -->
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-primary prevBtn" data-target="#step-4">
                                <i class="fas fa-arrow-left"></i> Précédent
                            </button>
                            <button type="submit" id="submit-button" class="btn btn-success">
                                Soumettre <i class="fas fa-check"></i>
                            </button>
                        </div>
                    </div>
        </form>
    </div>

    <!-- Scripts Bootstrap -->
    <script src="plugins/js/popper/popper.min.js"></script>
    <script src="plugins/js/bootstrap/bootstrap.min.js"></script>
    <script src="plugins/js/jquery/jquery-3.6.0.min.js"></script>
    <script src="plugins/js/fontawesome/all.min.js"></script>
    <script src="js_form/form_control.js"></script>
    <script>
        window.approvedFournisseurs = <?= json_encode($listeFournisseursValides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="js_form/script.js"></script>
    <script>
        // Expose le droit d'accès financier côté JS
        window.canSeeFinance = <?php echo isset($canSeeFinance) && $canSeeFinance ? 'true' : 'false'; ?>;
    </script>
</body>

</html>
<script>
    // Extension: affichage fiches liées détaillées
    document.addEventListener('DOMContentLoaded', () => {
        const tableBody = document.querySelector('#table-fiches-liees tbody');
        const tableFoot = document.getElementById('tfoot-fiches-liees');
        let currentLigneId = null;

        // Hook sur changement ligne (precision_select) déjà existant: on observe fetch via override
        const origFetch = window.fetch;
        window.fetch = function(url, opts) {
            const p = origFetch.apply(this, arguments);
            if (url && /get_ligne_debourse_details\.php$/.test(url)) {
                p.then(r => r.clone().json().then(d => {
                    if (d.success) {
                        // Si multi-sélection, ne pas tenter d'afficher la zone "fiches liées" (sinon ça clignote et c'est incohérent)
                        try {
                            const sel = document.getElementById('precision_select');
                            const selectedCount = sel ? (sel.selectedOptions || []).length : 0;
                            if (selectedCount !== 1) {
                                const section = document.getElementById('fiches-existantes-section');
                                if (section) section.style.display = 'none';
                                return;
                            }
                        } catch (e) {}
                        try {
                            const body = (opts && opts.body) ? String(opts.body) : '';
                            const m = body.match(/(?:^|&)ligne_debourse_id=([^&]+)/);
                            currentLigneId = m ? decodeURIComponent(m[1]) : null;
                        } catch (e) {
                            currentLigneId = null;
                        }
                        renderFichesLiees(d);
                    }
                }).catch(() => {}));
            }
            return p;
        };

        function renderFichesLiees(data) {
            const section = document.getElementById('fiches-existantes-section');
            tableBody.innerHTML = '';
            tableFoot.innerHTML = '';
            const fiches = data.fiches_existantes || [];
            if (!fiches.length) {
                section.style.display = 'none';
                return;
            }
            fiches.forEach(f => {
                const tr = document.createElement('tr');
                const etat = f.decaisse == 1 ? 'Décaissée' : (f.otp_valide == 1 ? 'OTP' : (f.approuve == 1 ? 'Approuvée' : (f.conforme == 1 ? 'Conforme' : 'En attente')));
                let badgeClass = 'secondary';
                if (f.decaisse == 1) badgeClass = 'success';
                else if (f.otp_valide == 1) badgeClass = 'info';
                else if (f.approuve == 1) badgeClass = 'warning';
                else if (f.conforme == 1) badgeClass = 'primary';
                const montantAff = (window.canSeeFinance ? Number(f.montant_fiche).toLocaleString('fr-FR') + ' FCFA' : '****');
                tr.innerHTML = `
                <td><span class="badge bg-dark">${f.num_fiche||''}</span></td>
                <td>${f.beficiaire_fiche||''}</td>
                <td class="text-end">${montantAff}</td>
                <td><span class="badge bg-${badgeClass}">${etat}</span></td>
                <td>${formatDate(f.date_creation)}</td>
                <td class="text-center">${f.signature_fichier?'<i class="fas fa-check text-success"></i>':'<i class="fas fa-minus text-muted"></i>'}</td>
                <td class="text-center"><button type="button" class="btn btn-sm btn-outline-primary" data-fiche="${f.num_fiche}"><i class="fas fa-eye"></i></button></td>`;
                tableBody.appendChild(tr);
            });
            // Totaux
            if (window.canSeeFinance) {
                const totalAll = fiches.reduce((a, f) => a + Number(f.montant_fiche || 0), 0);
                const totalDec = fiches.filter(f => f.decaisse == 1).reduce((a, f) => a + Number(f.montant_fiche || 0), 0);
                const totalAtt = totalAll - totalDec;
                tableFoot.innerHTML = `<tr><th colspan="2" class="text-end">Totaux</th><th class="text-end">${totalAll.toLocaleString('fr-FR')} FCFA</th><th colspan="3" style="font-size:0.75rem;">Décaissé: <strong class="text-success">${totalDec.toLocaleString('fr-FR')} FCFA</strong> • En attente: <strong class="text-danger">${totalAtt.toLocaleString('fr-FR')} FCFA</strong></th><th></th></tr>`;
            } else {
                tableFoot.innerHTML = `<tr><th colspan="2" class="text-end">Totaux</th><th class="text-end">****</th><th colspan="3" style="font-size:0.75rem;">Décaissé: <strong class="text-success">****</strong> • En attente: <strong class="text-danger">****</strong></th><th></th></tr>`;
            }
            section.style.display = 'block';
        }

        function formatDate(d) {
            try {
                const dt = new Date(d);
                return dt.toLocaleString('fr-FR');
            } catch (e) {
                return d;
            }
        }

        // Délégation clic vue fiche
        document.addEventListener('click', e => {
            const btn = e.target.closest('button[data-fiche]');
            if (!btn) return;
            const num = btn.getAttribute('data-fiche');
            openFicheModal(num);
        });

        function openFicheModal(num) {
            const modalNum = document.getElementById('modal-fiche-num');
            const modalBody = document.getElementById('modal-fiche-body');
            const link = document.getElementById('modal-open-page');
            modalNum.textContent = num;
            link.href = 'voir_fiche_details.php?num_fiche=' + encodeURIComponent(num);
            modalBody.innerHTML = '<div class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Chargement...</div>';
            fetch('request/get_fiche_details.php?num_fiche=' + encodeURIComponent(num))
                .then(r => r.json())
                .then(d => {
                    if (!d.success) {
                        modalBody.innerHTML = '<div class="alert alert-danger">' + (d.message || 'Erreur de chargement') + '</div>';
                        return;
                    }
                    const f = d.fiche;
                    modalBody.innerHTML = `
                    <div class="row g-3">
                        <div class="col-md-6">
                            <h6 class="text-primary">Bénéficiaire</h6>
                            <p class="mb-1"><strong>${f.beficiaire_fiche||''}</strong></p>
                            <p class="mb-1">Téléphone: ${f.tel_beneficiaire_fiche||'-'}</p>
                            <p class="mb-1">Montant: <strong>${(window.canSeeFinance? Number(f.montant_fiche).toLocaleString('fr-FR') + ' FCFA' : '****')}</strong></p>
                            <p class="mb-1">Etat: ${(f.decaisse==1?'Décaissée':(f.otp_valide==1?'OTP':(f.approuve==1?'Approuvée':(f.conforme==1?'Conforme':'En attente'))))}</p>
                            <p class="mb-1">Créée: ${formatDate(f.date_creat_fiche)}</p>
                        </div>
                        <div class="col-md-6">
                            <h6 class="text-primary">Contexte</h6>
                            <p class="mb-1">Chantier: ${(f.chantier_code||'')+' '+(f.chantier_titre||'')}</p>
                            <p class="mb-1">Déboursé: ${f.ligne_devis_label||f.devis_designation||'-'}</p>
                            <p class="mb-1">Ligne: ${f.ligne_debourse_label||f.ligne_designation||'-'}</p>
                            <p class="mb-1">Mode: ${f.mode_paiement||'-'}</p>
                        </div>
                        <div class="col-12">
                            <h6 class="text-primary">Détails</h6>
                            <div style="white-space:pre-wrap; font-size:0.85rem;">${(f.details_demande||'').replace(/[<>]/g,'')}</div>
                        </div>
                        ${(d.signature_url||d.signature_path)?`<div class="col-12"><h6 class="text-primary">Signature</h6><img src="${d.signature_url||d.signature_path}" style="max-width:280px;max-height:140px;border:1px dashed #ccc;padding:4px;background:#fff;" /></div>`:''}
                    </div>`;
                })
                .catch(() => {
                    modalBody.innerHTML = '<div class="alert alert-danger">Erreur réseau</div>';
                });
            const modal = new bootstrap.Modal(document.getElementById('modalFicheLiee'));
            modal.show();
        }
    });
</script>

