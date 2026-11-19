<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';

AuditTrail::logPageConsultation([
    'module' => 'core',
    'action' => 'generer_devis_view',
    'entity_type' => 'devis_builder',
    'entity_label' => 'Generer devis',
    'page_name' => 'generer_devis.php',
    'details' => [
        'client_id' => $_GET['client_id'] ?? null,
        'devis_id' => $_GET['devis_id'] ?? null,
        'view_mode' => 'form',
    ],
]);

include('header/header_generer_devis.php');

?>

<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rédaction de Devis - BTP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/custom_style_generer_devis.css">
    <style>
        .quote-builder {
            width: 100%;
            color: #25324a;
        }

        .quote-builder-card {
            overflow: hidden;
            border: 1px solid #dfe6f0;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 18px 45px rgba(23, 35, 72, .08);
        }

        .quote-builder-intro {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 22px 26px;
            border-bottom: 1px solid #e5ebf3;
            background: #f8fafc;
        }

        .quote-builder-intro h2,
        .quote-section-title h3 {
            margin: 0;
            color: #172348;
            font-weight: 850;
        }

        .quote-builder-intro h2 {
            font-size: 20px;
        }

        .quote-builder-intro p,
        .quote-section-title p {
            margin: 5px 0 0;
            color: #69778e;
            font-size: 12px;
        }

        .quote-progress {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #172348;
            font-size: 11px;
            font-weight: 800;
        }

        .quote-progress span {
            display: inline-flex;
            width: 29px;
            height: 29px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #fabd02;
        }

        .quote-builder-content {
            padding: 26px;
        }

        .quote-section {
            padding: 22px 0;
            border-bottom: 1px solid #e7edf4;
        }

        .quote-section:first-child {
            padding-top: 0;
        }

        .quote-section:last-child {
            padding-bottom: 0;
            border-bottom: 0;
        }

        .quote-section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .quote-section-title > i {
            display: inline-flex;
            width: 40px;
            height: 40px;
            align-items: center;
            justify-content: center;
            border-radius: 9px;
            background: #edf2fb;
            color: #244b91;
        }

        .quote-section-title h3 {
            font-size: 15px;
        }

        .quote-builder .form-label {
            margin-bottom: 7px;
            color: #44516a;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        .quote-builder .form-control,
        .quote-builder .form-select,
        .quote-builder .select2-selection {
            min-height: 45px;
            border: 1px solid #cdd7e5 !important;
            border-radius: 8px !important;
            background: #fff !important;
            color: #25324a;
            box-shadow: none !important;
        }

        .quote-builder .select2-container {
            width: 100% !important;
        }

        .quote-builder .select2-selection--single {
            display: flex !important;
            align-items: center;
        }

        .quote-builder .select2-selection__rendered {
            width: 100%;
            padding: 0 40px 0 13px !important;
            color: #25324a !important;
            line-height: 43px !important;
        }

        .quote-builder .select2-selection__arrow {
            top: 9px !important;
            right: 9px !important;
        }

        .quote-select-dropdown {
            overflow: hidden;
            border: 1px solid #bfcadd !important;
            border-radius: 9px !important;
            box-shadow: 0 18px 40px rgba(23, 35, 72, .16);
        }

        .quote-select-dropdown .select2-search {
            position: relative;
            padding: 10px !important;
            background: #f7f9fc;
        }

        .quote-select-dropdown .select2-search::before {
            content: "\f002";
            position: absolute;
            z-index: 2;
            top: 22px;
            left: 23px;
            color: #718096;
            font-family: "Font Awesome 6 Free";
            font-size: 12px;
            font-weight: 900;
            pointer-events: none;
        }

        .quote-select-dropdown .select2-search__field {
            min-height: 42px;
            padding: 8px 12px 8px 35px !important;
            border: 1px solid #cbd5e1 !important;
            border-radius: 7px !important;
            outline: none;
        }

        .quote-select-dropdown .select2-results__option {
            padding: 10px 13px;
            color: #34425a;
            font-size: 12px;
        }

        .quote-select-dropdown .select2-results__option--highlighted {
            background: #172348 !important;
            color: #fff !important;
        }

        .quote-builder .form-control:focus,
        .quote-builder .form-select:focus {
            border-color: #31589c !important;
            box-shadow: 0 0 0 3px rgba(49, 88, 156, .12) !important;
        }

        .quote-builder textarea.form-control {
            min-height: 104px;
            resize: vertical;
        }

        .quote-options {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin: 18px 0 0;
        }

        .quote-option {
            display: flex;
            min-width: 210px;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 12px 14px;
            border: 1px solid #dce4ef;
            border-radius: 9px;
            background: #f8fafc;
        }

        .quote-option strong,
        .quote-option small {
            display: block;
        }

        .quote-option strong {
            color: #25324a;
            font-size: 12px;
        }

        .quote-option small {
            margin-top: 2px;
            color: #7a8699;
            font-size: 10px;
        }

        .quote-identity-grid {
            display: grid;
            grid-template-columns: 190px minmax(0, 1fr);
            gap: 22px;
            align-items: stretch;
        }

        .quote-builder #logoUploadContainer {
            position: relative;
            display: flex;
            width: 190px;
            max-width: none;
            height: 178px;
            align-items: center;
            justify-content: center;
            padding: 16px;
            border: 2px dashed #bdc9da !important;
            border-radius: 11px;
            background: #f8fafc !important;
            cursor: pointer;
            transition: border-color .18s ease, background .18s ease, transform .18s ease;
        }

        .quote-builder #logoUploadContainer:hover {
            border-color: #31589c !important;
            background: #f2f6fc !important;
        }

        .quote-builder #logoUploadContainer.drag-over {
            transform: translateY(-2px);
            border-color: #fabd02 !important;
            background: #fff9df !important;
        }

        .quote-builder #logoPreview {
            display: none;
            width: 100%;
            height: 100%;
            max-height: none !important;
            margin: 0 !important;
            object-fit: contain;
        }

        .quote-builder #logoUploadContainer.has-image #logoPreview {
            display: block;
        }

        .quote-logo-empty {
            display: grid;
            place-items: center;
            gap: 7px;
            color: #66758d;
            text-align: center;
            pointer-events: none;
        }

        .quote-logo-empty i {
            display: inline-flex;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #e9eff8;
            color: #31589c;
            font-size: 17px;
        }

        .quote-logo-empty strong,
        .quote-logo-empty small {
            display: block;
        }

        .quote-logo-empty strong {
            color: #34425a;
            font-size: 11px;
        }

        .quote-logo-empty small {
            font-size: 9px;
        }

        .quote-builder #logoUploadContainer.has-image .quote-logo-empty {
            display: none;
        }

        .quote-logo-controls {
            position: absolute;
            inset: auto 8px 8px;
            display: none;
            justify-content: flex-end;
            gap: 6px;
        }

        .quote-builder #logoUploadContainer.has-image .quote-logo-controls {
            display: flex;
        }

        .quote-logo-control {
            display: inline-flex;
            width: 32px;
            height: 32px;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, .45);
            border-radius: 7px;
            background: rgba(23, 35, 72, .9);
            color: #fff;
            box-shadow: 0 6px 14px rgba(23, 35, 72, .18);
        }

        .quote-logo-control.is-remove {
            background: rgba(185, 28, 28, .92);
        }

        .quote-builder .info-container {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            align-items: start;
        }

        .quote-builder .info-container .form-group {
            width: 100%;
            margin: 0;
            align-items: stretch;
        }

        .quote-builder .spacer {
            display: none;
        }

        .quote-table-shell {
            overflow: hidden;
            border: 1px solid #dce4ef;
            border-radius: 11px;
        }

        .quote-builder .table-responsive {
            margin: 0;
        }

        .quote-builder #devisTable {
            margin: 0;
        }

        .quote-builder #devisTable thead th {
            padding: 13px 11px;
            border-color: #dce4ef !important;
            background: #172348 !important;
            color: #fff !important;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .quote-builder #devisTable td {
            padding: 9px;
            border-color: #e5ebf3;
            background: #fff;
        }

        .quote-builder #devisTable tbody tr:hover td {
            background: #f8fafc;
        }

        .quote-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-bottom: 14px;
        }

        .quote-builder .btn {
            display: inline-flex;
            min-height: 41px;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 9px 13px;
            border-radius: 8px !important;
            font-size: 12px;
            font-weight: 800;
        }

        .quote-builder .btn .inline-flex {
            width: auto !important;
            height: auto !important;
            margin: 0 !important;
            background: transparent !important;
        }

        .quote-builder .btn-info {
            border-color: #31589c;
            background: #31589c;
            color: #fff;
        }

        .quote-builder .btn-success {
            border-color: #16765b;
            background: #16765b;
            color: #fff;
        }

        .quote-builder .btn-outline-primary {
            border-color: #31589c;
            color: #31589c;
        }

        .quote-builder .btn-template {
            border-color: #d7a900;
            background: #fff8d9;
            color: #6d5100;
            text-decoration: none;
        }

        .quote-import-help {
            display: flex;
            align-items: flex-start;
            gap: 9px;
            margin-top: 12px;
            padding: 11px 13px;
            border-radius: 8px;
            background: #f6f8fc;
            color: #69778e;
            font-size: 11px;
            line-height: 1.55;
        }

        .quote-import-help i {
            margin-top: 2px;
            color: #31589c;
        }

        .quote-summary {
            padding: 18px;
            border: 1px solid #dce4ef;
            border-radius: 11px;
            background: #f8fafc;
        }

        .quote-summary #totalTTC {
            border-color: #172348 !important;
            background: #172348 !important;
            color: #fff;
            font-size: 18px;
            font-weight: 850;
        }

        .quote-builder .btn-group {
            width: 100%;
        }

        .quote-builder #saveBtn {
            border-color: #fabd02 !important;
            background: #fabd02 !important;
            color: #172348 !important;
        }

        .quote-builder #exportPdfBtn {
            border-color: #d7dee9;
            background: #fff;
            color: #34425a;
        }

        .quote-builder .switch {
            flex: 0 0 auto;
            width: 46px;
            height: 25px;
            margin: 0;
        }

        .quote-builder .slider::before {
            width: 19px;
            height: 19px;
            left: 3px;
            bottom: 3px;
        }

        .quote-builder input:checked + .slider {
            background: #172348;
        }

        .quote-builder input:checked + .slider::before {
            transform: translateX(21px);
        }

        body.finea-dark-mode .quote-builder-card,
        body.finea-dark-mode .quote-builder #devisTable td {
            border-color: #2a3550;
            background: #172036;
        }

        body.finea-dark-mode .quote-builder-intro,
        body.finea-dark-mode .quote-option,
        body.finea-dark-mode .quote-summary,
        body.finea-dark-mode .quote-import-help {
            border-color: #303b56;
            background: #111a2e;
        }

        body.finea-dark-mode .quote-builder-intro h2,
        body.finea-dark-mode .quote-section-title h3,
        body.finea-dark-mode .quote-option strong {
            color: #fff;
        }

        @media (max-width: 900px) {
            .quote-identity-grid {
                grid-template-columns: 1fr;
            }

            .quote-builder #logoUploadContainer {
                width: 100%;
            }

            .quote-builder .info-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 620px) {
            .quote-builder-intro,
            .quote-builder-content {
                padding: 18px;
            }

            .quote-builder-intro {
                align-items: flex-start;
                flex-direction: column;
            }

            .quote-toolbar .btn,
            .quote-toolbar .btn-template {
                width: 100%;
            }
        }
    </style>
</head>

<body>

    <div class="quote-builder">
        <div class="quote-builder-card">
            <div class="quote-builder-intro">
                <div>
                    <h2>Composer le devis</h2>
                    <p>Renseignez les informations générales, puis construisez les lignes et groupes du document.</p>
                </div>
                <div class="quote-progress"><span>1</span> Saisie du devis</div>
            </div>
            <div class="quote-builder-content">
                <section class="quote-section">
                    <div class="quote-section-title">
                        <i class="fas fa-address-card"></i>
                        <div><h3>Contexte commercial</h3><p>Client, projet et conditions principales du devis.</p></div>
                    </div>
                <div class="row mb-3">
                    <div class="col-12 mb-3">
                        <label class="form-label d-block">Nature du devis</label>
                        <div class="d-flex flex-wrap gap-3">
                            <label class="border rounded-3 p-3 flex-grow-1"><input type="radio" name="devis_project_mode" value="NEW_PROJECT" checked class="me-2">Nouveau projet — devis principal</label>
                            <label class="border rounded-3 p-3 flex-grow-1"><input type="radio" name="devis_project_mode" value="TS" class="me-2">Travaux supplémentaires d'un projet existant</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="clientSelect" class="form-label">Client du projet</label>
                        <select class="form-control" id="clientSelectDisplay" disabled aria-label="Client automatiquement lié au projet">
                            <option value="" selected>Sélectionnez d’abord un projet</option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?php echo $client['id_client']; ?>">
                                    <?php echo $client['nom_client']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" id="clientSelect" name="client_id" value="">
                        <small class="text-muted">Renseigné automatiquement depuis le projet et non modifiable.</small>
                    </div>
                    <div class="col-md-6">
                        <label for="offreSelect" class="form-label">Projet existant <span id="offreRequiredHint" class="text-muted">(pour un TS)</span></label>
                        <select class="form-control builder-search-select" id="offreSelect" name="offre_id">
                            <option value="" selected>Choisissez un projet</option>
                            <?php foreach ($offres as $offre): ?>
                                <option value="<?php echo $offre['id_offre']; ?>" data-client-id="<?php echo (int)($offre['client_id'] ?? 0); ?>">
                                    <?php echo $offre['num_offre'] . ' - ' . $offre['reference_offre']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="newProjectFields" class="col-12 row g-3 mt-1">
                        <div class="col-md-4">
                            <label class="form-label" for="newOfferNumber">N° du nouveau projet</label>
                            <input class="form-control bg-light" id="newOfferNumber" name="new_offer_number" value="Généré automatiquement" readonly aria-describedby="newOfferNumberHelp">
                            <small id="newOfferNumberHelp" class="text-muted">Attribué à l’enregistrement au format PRJ-AAAA-00001.</small>
                        </div>
                        <div class="col-md-8"><label class="form-label" for="newOfferReference">Intitulé du Projet</label><input class="form-control" id="newOfferReference" name="new_offer_reference" placeholder="Construction d'un entrepôt SIR"></div>
                    </div>
                    <div class="col-md-8">
                        <label for="delaiLivraison" class="form-label">Délai de livraison</label>
                        <input type="text" class="form-control" id="delaiLivraison" name="delaiLivraison" placeholder="Délai de livraison">
                    </div>
                    <div class="col-md-4">
                        <label for="correspondant" class="form-label">Correspondant</label>
                        <input type="text" class="form-control" id="correspondant" name="correspondant" placeholder="Correspondant">
                    </div>
                </div>

                <div class="quote-options">
                    <div class="quote-option">
                        <span><strong>TVA facturable</strong><small>Appliquer la TVA de 18 %</small></span>
                        <label class="switch">
                            <input type="checkbox" id="tvaFacturable" name="tvaFacturable" value="1">
                            <span class="slider round"></span>
                        </label>
                    </div>

                    <div class="quote-option">
                        <span><strong>Publier le devis</strong><small>Rendre le document disponible après saisie</small></span>
                        <label class="switch">
                            <input type="checkbox" id="publierDevis" name="publierDevis" value="1">
                            <span class="slider round"></span>
                        </label>
                    </div>
                </div>
                </section>

                <section class="quote-section">
                    <div class="quote-section-title">
                        <i class="fas fa-file-signature"></i>
                        <div><h3>Identité du document</h3><p>Logo et références officielles du devis.</p></div>
                    </div>
                    <div class="quote-identity-grid">
                    <div id="logoUploadContainer" class="has-image" role="button" tabindex="0" aria-label="Ajouter ou remplacer le logo de l'entreprise emettrice">
                        <img id="logoPreview" src="logo/default_logo.jpg" alt="Logo de l'entreprise emettrice">
                        <input type="file" id="logoUpload" name="logoUpload" accept="image/*" style="display: none;">
                        <div id="logoMessage" class="quote-logo-empty">
                            <i class="fas fa-cloud-arrow-up"></i>
                            <span><strong>Déposer le logo ici</strong><small>PNG, JPG ou WEBP</small></span>
                        </div>
                        <div class="quote-logo-controls">
                            <button type="button" class="quote-logo-control" id="replaceLogoBtn" title="Remplacer le logo" aria-label="Remplacer le logo"><i class="fas fa-pen"></i></button>
                            <button type="button" class="quote-logo-control is-remove" id="removeLogoBtn" title="Supprimer le logo" aria-label="Supprimer le logo"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>

                    <div class="info-container">
                        <div class="form-group">
                            <label for="numeroDevis" class="form-label">N° Devis</label>
                            <input type="text" class="form-control" id="numeroDevis" name="numeroDevis" placeholder="N° Devis" value="<?php echo $code_devis; ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label for="dateEmission" class="form-label">Date d'émission</label>
                            <input type="date" class="form-control" id="dateEmission" name="dateEmission" value="<?php echo gmdate('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                        <label for="dateExpiration" class="form-label">Date d'expiration</label>
                        <input type="date" class="form-control" id="dateExpiration" name="dateExpiration">
                    </div>
                    <div class="col-md-4">
                        <label for="billingDate" class="form-label">Date de facturation *</label>
                        <input type="date" class="form-control" id="billingDate" name="billingDate" required>
                        <small class="text-muted">Pilote les alertes CRM et Facturation.</small>
                    </div>
                    </div>
                </div>
                </section>

                <form id="devisForm" method="POST" enctype="multipart/form-data">
                    <section class="quote-section">
                        <div class="quote-section-title">
                            <i class="fas fa-building"></i>
                            <div><h3>Émetteur et destinataire</h3><p>Coordonnées qui apparaîtront sur le document final.</p></div>
                        </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="emisPar" class="form-label">Émetteur</label>
                            <textarea class="form-control" id="emisPar" name="emisPar" placeholder="Informations sur l'émetteur" rows="3"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="destineA" class="form-label">Destinataire</label>
                            <textarea class="form-control" id="destineA" name="destineA" placeholder="Informations sur le destinataire" rows="3"></textarea>
                        </div>
                    </div>
                    </section>

                    <section class="quote-section">
                        <div class="quote-section-title">
                            <i class="fas fa-list-check"></i>
                            <div><h3>Lignes du devis</h3><p>Ajoutez manuellement les prestations ou importez un fichier structuré.</p></div>
                        </div>
                    <div class="quote-toolbar">
                        <button type="button" class="btn btn-info" id="addGroupBtn">
                            <span class="inline-flex"><i class="fas fa-layer-group"></i></span>
                            Ajouter un groupe
                        </button>
                        <button type="button" class="btn btn-success" id="addRow">
                            <span class="inline-flex"><i class="fas fa-plus"></i></span>
                            Ajouter une ligne
                        </button>
                        <input type="file" id="devisExcelFile" accept=".xlsx,.xls,.csv" style="display:none;" />
                        <button type="button" class="btn btn-outline-primary" id="importExcelBtn">
                            <span class="inline-flex"><i class="fas fa-file-import"></i></span>
                            Importer Excel
                        </button>
                        <a class="btn btn-template" href="request/export_modele_import_devis.php">
                            <i class="fas fa-file-arrow-down"></i>
                            Télécharger le modèle Excel
                        </a>
                    </div>
                    <div class="quote-table-shell">
                    <div class="table-responsive">
                        <table class="table table-bordered" id="devisTable">
                            <thead>
                                <tr>
                                    <th style="width: 50px;">N°</th>
                                    <th>Désignation</th>
                                    <th>Unité</th>
                                    <th>Quantité</th>
                                    <th>Prix Unitaire</th>
                                    <th>Prix Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Ajout des lignes du devis ici -->
                            </tbody>
                        </table>
                    </div>
                    </div>
                    <div class="quote-import-help">
                        <i class="fas fa-circle-info"></i>
                        <span>Ligne 1 : <strong>Désignation | Unité | Quantité | Prix Unitaire | Groupe</strong>. Le groupe est optionnel et l’unité accepte un identifiant, un symbole ou un libellé.</span>
                    </div>
                    </section>
                </form>

                <section class="quote-section">
                    <div class="quote-section-title">
                        <i class="fas fa-calculator"></i>
                        <div><h3>Conditions et totalisation</h3><p>Finalisez les mentions et contrôlez les montants calculés.</p></div>
                    </div>
                <div class="row footer-info">
                    <div class="col-md-8">
                        <div class="form-group">
                            <label for="termesConditions" class="form-label">Termes et conditions</label>
                            <textarea class="form-control" id="termesConditions" name="termesConditions" rows="5" placeholder="Termes et conditions"></textarea>
                        </div>
                        <div class="form-group mt-3">
                            <label for="piedDePage" class="form-label">Pied de page</label>
                            <textarea class="form-control" id="piedDePage" name="piedDePage" rows="5" placeholder="Pied de page"></textarea>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="quote-summary">
                        <div class="form-group mt-3">
                            <label for="totalHT" class="form-label">Total HT</label>
                            <input type="text" class="form-control" id="totalHT" name="totalHT" readonly>
                        </div>
                        <div class="form-group mt-3 tvaZone">
                            <label for="tva" class="form-label">TVA 18%</label>
                            <input type="text" class="form-control" id="tva" name="tva" readonly>
                        </div>
                        <div class="form-group mt-3"><label for="commercialDiscount" class="form-label">Remise commerciale</label><input type="number" min="0" step="0.01" value="0" class="form-control" id="commercialDiscount" name="commercialDiscount"><small class="text-muted">Déduite du total du devis.</small></div>
                        <div class="form-group mt-3">
                            <label for="totalTTC" class="form-label">Total TTC</label>
                            <input type="text" class="form-control" id="totalTTC" name="totalTTC" readonly>
                        </div>

                        <div id="resultat"></div>
                        <!-- Buttons -->
                        <div class="btn-group d-flex flex-column mt-3">
                            <button type="button" class="btn btn-primary mt-2" id="saveBtn" style="margin-bottom:2px;">
                                <span class="inline-flex">
                                    <i class="fas fa-save"></i>
                                </span>
                                Enregistrer le Devis
                            </button>
                            <button type="button" class="btn btn-secondary" id="exportPdfBtn">
                                <span class="inline-flex">
                                    <i class="fas fa-file-pdf"></i>
                                </span>
                                Exporter PDF
                            </button>
                        </div>
                        </div>
                    </div>
                </div>
                </section>
            </div>
        </div>
    </div>

    <script src="decaissement/plugins/js/jquery/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let uniteOptions = `
        <option value="" disabled selected>Choisir...</option>
        <?php foreach ($unites as $unite): ?>
            <option value="<?= (int)$unite['id'] ?>">
                <?= htmlspecialchars($unite['libelle']) ?> (<?= htmlspecialchars($unite['symbole']) ?>)
            </option>
        <?php endforeach; ?>
        `;
    </script>
    <script src="js/script.js"></script>
    <!--Intégration de jquery/Ajax-->
    <script src="js/function_devis.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        function initSelect2() {
            $('.unite-select').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) return;
                $(this).select2({
                    dropdownParent: $(document.body),
                    width: '100%',
                    placeholder: "Saisir pour rechercher une unité...",
                    minimumResultsForSearch: 0,
                    dropdownCssClass: 'quote-select-dropdown',
                    language: {
                        noResults: function() { return 'Aucun résultat'; },
                        searching: function() { return 'Recherche...'; }
                    }
                });
            });
            $('.builder-search-select').each(function() {
                if ($(this).hasClass('select2-hidden-accessible')) return;
                $(this).select2({
                    dropdownParent: $(document.body),
                    width: '100%',
                    placeholder: 'Saisir pour rechercher...',
                    allowClear: true,
                    minimumResultsForSearch: 0,
                    dropdownCssClass: 'quote-select-dropdown',
                    language: {
                        noResults: function() { return 'Aucun résultat'; },
                        searching: function() { return 'Recherche...'; }
                    }
                });
            });
        }
        $(document).ready(function() {
            initSelect2();
            const projectSelect=document.getElementById('offreSelect');
            const clientDisplay=document.getElementById('clientSelectDisplay');
            const clientHidden=document.getElementById('clientSelect');
            const syncProjectClient=()=>{
                const mode=document.querySelector('input[name="devis_project_mode"]:checked')?.value||'NEW_PROJECT';
                const option=projectSelect?.selectedOptions?.[0];
                if(mode==='TS'){
                    const clientId=option?.dataset?.clientId||'';
                    if(clientDisplay){clientDisplay.disabled=true;clientDisplay.value=clientId;}
                    if(clientHidden)clientHidden.value=clientId;
                }else{
                    if(clientDisplay)clientDisplay.disabled=false;
                    if(clientHidden)clientHidden.value=clientDisplay?.value||'';
                }
            };
            projectSelect?.addEventListener('change',syncProjectClient);
            clientDisplay?.addEventListener('change',()=>{if(clientHidden)clientHidden.value=clientDisplay.value;});
            document.querySelectorAll('input[name="devis_project_mode"]').forEach(el=>el.addEventListener('change',syncProjectClient));
            syncProjectClient();
            $(document).on('select2:open', function() {
                window.setTimeout(function() {
                    const search = document.querySelector('.select2-container--open .select2-search__field');
                    if (search) {
                        search.placeholder = 'Saisir pour filtrer la liste...';
                        search.focus();
                    }
                }, 0);
            });

            const logoContainer = document.getElementById('logoUploadContainer');
            const logoInput = document.getElementById('logoUpload');
            const logoPreview = document.getElementById('logoPreview');
            const removeLogoBtn = document.getElementById('removeLogoBtn');
            const replaceLogoBtn = document.getElementById('replaceLogoBtn');

            function syncLogoState() {
                const hasPreview = logoPreview && logoPreview.getAttribute('src');
                logoContainer?.classList.toggle('has-image', Boolean(hasPreview));
                const emptyState = document.getElementById('logoMessage');
                if (emptyState && !hasPreview) {
                    emptyState.style.display = '';
                }
            }

            logoContainer?.addEventListener('click', function(event) {
                if (event.target.closest('#removeLogoBtn')) return;
                logoInput?.click();
            });
            logoContainer?.addEventListener('keydown', function(event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    logoInput?.click();
                }
            });
            replaceLogoBtn?.addEventListener('click', function(event) {
                event.stopPropagation();
                logoInput?.click();
            });
            removeLogoBtn?.addEventListener('click', function(event) {
                event.stopPropagation();
                if (logoInput) logoInput.value = '';
                if (logoPreview) {
                    logoPreview.removeAttribute('src');
                    logoPreview.style.display = '';
                }
                syncLogoState();
            });
            logoInput?.addEventListener('change', function() {
                window.setTimeout(syncLogoState, 0);
            });
            logoContainer?.addEventListener('drop', function() {
                window.setTimeout(syncLogoState, 0);
            });
            syncLogoState();
        });

        // Import Excel/CSV -> remplit le tableau des lignes
        (function() {
            const importBtn = document.getElementById('importExcelBtn');
            const fileInput = document.getElementById('devisExcelFile');
            const resultat = document.getElementById('resultat');
            const tbody = document.querySelector('#devisTable tbody');

            if (!importBtn || !fileInput || !tbody) return;

            function setResult(html, type) {
                if (!resultat) return;
                const cls = type === 'error' ? 'alert alert-danger' : (type === 'warn' ? 'alert alert-warning' : 'alert alert-success');
                resultat.innerHTML = `<div class="${cls}" style="margin-top:10px;">${html}</div>`;
            }

            function escapeHtml(value) {
                return String(value)
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }

            function groupRowHtml(title) {
                return `
                  <tr class="groupe-row">
                    <td colspan="7">
                      <input type="text" class="form-control groupe-titre" placeholder="Titre du groupe" required value="${escapeHtml(title)}">
                      <button type="button" class="btn btn-warning btn-sm close-group">Fermer le groupe</button>
                      <button type="button" class="btn btn-danger btn-sm remove-group">Supprimer le groupe</button>
                    </td>
                  </tr>
                `;
            }

            function lineRowHtml(groupTitle) {
                return `
                  <tr>
                    <td class="index"></td>
                    <td>
                      <input type="text" class="form-control" name="designation[]" placeholder="Désignation">
                      <input type="hidden" class="groupe-value" name="groupe[]" value="${escapeHtml(groupTitle || '')}">
                    </td>
                    <td>
                      <select class="form-select unite-select" name="unite[]" required>
                        ${uniteOptions}
                      </select>
                    </td>
                    <td><input type="number" class="form-control quantite" name="quantite[]" placeholder="Quantité"></td>
                    <td><input type="number" class="form-control prix" name="prix[]" placeholder="Prix Unitaire"></td>
                    <td><input type="number" class="form-control total" name="total[]" placeholder="Prix Total" readonly></td>
                    <td><button type="button" class="btn btn-danger remove-row">-</button></td>
                  </tr>
                `;
            }

            function updateIndex() {
                let idx = 1;
                tbody.querySelectorAll('tr').forEach((tr) => {
                    if (tr.classList.contains('groupe-row') || tr.classList.contains('sous-total-row')) return;
                    const cell = tr.querySelector('.index');
                    if (cell) cell.textContent = String(idx++);
                });
            }

            importBtn.addEventListener('click', () => fileInput.click());

            fileInput.addEventListener('change', async () => {
                const file = fileInput.files && fileInput.files[0];
                if (!file) return;

                const existingRows = tbody.querySelectorAll('tr').length;
                if (existingRows > 0) {
                    const ok = window.confirm('Remplacer les lignes déjà présentes dans le devis ?');
                    if (!ok) {
                        fileInput.value = '';
                        return;
                    }
                }

                setResult('Import en cours...', 'warn');

                try {
                    const fd = new FormData();
                    fd.append('file', file);

                    const res = await fetch('request/import_devis_excel.php', {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin'
                    });

                    const data = await res.json().catch(() => null);
                    if (!data || data.ok !== true) {
                        const msg = (data && (data.error || data.message)) ? (data.error || data.message) : 'Import impossible.';

                        let details = '';
                        const looksLikeZipMissing = /ziparchive|\bphp[_-]?zip\b|extension php\s*"?zip"?/i.test(String(msg));
                        if (looksLikeZipMissing) {
                            details += '<br><small><b>Astuce :</b> l\'import <b>.xlsx</b> nécessite l\'extension PHP <b>zip</b> (ZipArchive). ' +
                                'Si ton hébergeur ne permet pas de l\'activer, exporte ton fichier en <b>.csv</b> (UTF-8) ou en <b>.xls</b>, puis réessaie.</small>';
                        }
                        if (data && Array.isArray(data.detected_headers) && data.detected_headers.length) {
                            details += '<br><small><b>En-têtes détectés :</b> ' + data.detected_headers.map(escapeHtml).join(' | ') + '</small>';
                        }
                        if (data && data.accepted_examples && typeof data.accepted_examples === 'object') {
                            const parts = [];
                            for (const [k, v] of Object.entries(data.accepted_examples)) {
                                if (Array.isArray(v) && v.length) {
                                    parts.push(`${escapeHtml(k)}: ${v.map(escapeHtml).join(', ')}`);
                                }
                            }
                            if (parts.length) {
                                details += '<br><small><b>Exemples acceptés :</b> ' + parts.join(' — ') + '</small>';
                            }
                        }

                        setResult(escapeHtml(msg) + details, 'error');
                        return;
                    }

                    tbody.innerHTML = '';

                    let currentGroup = '';
                    for (const ligne of (data.lignes || [])) {
                        const groupe = (ligne.groupe || '').trim();
                        if (groupe && groupe !== currentGroup) {
                            tbody.insertAdjacentHTML('beforeend', groupRowHtml(groupe));
                            currentGroup = groupe;
                        }

                        tbody.insertAdjacentHTML('beforeend', lineRowHtml(currentGroup));
                        const tr = tbody.lastElementChild;
                        if (!tr) continue;

                        const designationInput = tr.querySelector('input[name="designation[]"]');
                        const quantiteInput = tr.querySelector('input[name="quantite[]"]');
                        const prixInput = tr.querySelector('input[name="prix[]"]');
                        const totalInput = tr.querySelector('input[name="total[]"]');
                        const uniteSelect = tr.querySelector('select[name="unite[]"]');

                        if (designationInput) designationInput.value = ligne.designation || '';
                        if (quantiteInput) quantiteInput.value = (ligne.quantite ?? '');
                        if (prixInput) prixInput.value = (ligne.prix ?? '');
                        if (totalInput) totalInput.value = (ligne.total ?? '');

                        if (uniteSelect && ligne.unite_id) {
                            uniteSelect.value = String(ligne.unite_id);
                            uniteSelect.dispatchEvent(new Event('change', {
                                bubbles: true
                            }));
                        }

                        // Déclenche recalcul (géré par js/script.js)
                        if (prixInput) prixInput.dispatchEvent(new Event('input', {
                            bubbles: true
                        }));
                        if (quantiteInput) quantiteInput.dispatchEvent(new Event('input', {
                            bubbles: true
                        }));
                    }

                    updateIndex();
                    if (typeof initSelect2 === 'function') {
                        initSelect2();
                    }

                    const warnings = Array.isArray(data.warnings) ? data.warnings : [];
                    let html = `<b>${data.count || 0} ligne(s) importée(s) dans le formulaire uniquement.</b><br>` +
                        `Le devis n’est pas encore enregistré. Vérifiez les informations puis cliquez sur <b>Enregistrer le devis</b>.`;
                    if (warnings.length) {
                        html += '<br><b>Avertissements :</b><br>' + warnings.map(escapeHtml).join('<br>');
                        setResult(html, 'warn');
                    } else {
                        setResult(html, 'warn');
                    }
                    document.getElementById('saveBtn')?.classList.add('shadow-lg');
                    document.getElementById('saveBtn')?.scrollIntoView({behavior: 'smooth', block: 'center'});
                } catch (e) {
                    setResult('Erreur réseau ou serveur pendant l\'import.', 'error');
                } finally {
                    fileInput.value = '';
                }
            });
        })();
    </script>
</body>

</html>
