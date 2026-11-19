<?php
require_once __DIR__ . '/../model/AuditTrail.php';

$previewNumFiche = trim((string)($_GET['num_fiche'] ?? ''));
if ($previewNumFiche !== '') {
    AuditTrail::logVisualEvent('preview', [
        'module' => 'decaissement',
        'action' => 'fiche_detail_preview',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $previewNumFiche,
        'entity_label' => $previewNumFiche,
        'details' => [
            'view_mode' => 'browser_detail',
        ],
    ]);
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détails de la fiche</title>
    <link href="plugins/css/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="plugins/css/fontawesome/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
            color: #0f172a;
        }

        .fiche-header {
            background: linear-gradient(135deg, #1d4ed8 0%, #0f172a 100%);
            color: white;
            border-radius: 18px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.18);
        }

        .info-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 18px;
            padding: 22px;
            margin-bottom: 20px;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
        }

        .status-badge {
            font-size: 0.9em;
            padding: 8px 12px;
            border-radius: 999px;
        }

        .photo-container {
            text-align: center;
            margin: 20px 0;
        }

        .photo-container img {
            max-width: 300px;
            max-height: 300px;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }

        .montant-info {
            background: linear-gradient(135deg, #f0fdf4 0%, #eff6ff 100%);
            border: 1px solid #dbeafe;
            border-radius: 18px;
            padding: 18px;
            margin: 15px 0;
        }

        .section-kicker {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            font-weight: 700;
            color: #2563eb;
            margin-bottom: 0.35rem;
        }

        .supplier-card {
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .supplier-meta-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .supplier-meta-item {
            border: 1px solid #dbeafe;
            border-radius: 14px;
            background: #f8fbff;
            padding: 12px 14px;
        }

        .supplier-meta-item .label {
            display: block;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-weight: 700;
            color: #64748b;
            margin-bottom: 0.35rem;
        }

        .supplier-meta-item .value {
            color: #0f172a;
            font-weight: 600;
            word-break: break-word;
        }

        .supplier-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }

        .supplier-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            border: 1px solid #bfdbfe;
            background: #eff6ff;
            color: #1d4ed8;
            padding: 6px 12px;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .supplier-media-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
            margin-top: 16px;
        }

        .supplier-media-panel {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #f8fafc;
            padding: 12px;
        }

        .supplier-media-panel .media-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 10px;
        }

        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #007bff;
        }

        .timeline-item {
            position: relative;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -19px;
            top: 20px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #007bff;
        }
    </style>
</head>

<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <button class="btn btn-secondary" onclick="history.back()">
                <i class="fas fa-arrow-left"></i> Retour
            </button>
            <h1 class="h3 mb-0">Détails de la fiche</h1>
            <a id="btnExportPdf" href="#" target="_blank" class="btn btn-danger" style="display:none;">
                <i class="fas fa-file-pdf"></i> Exporter PDF
            </a>
        </div>

        <div id="loading" class="text-center">
            <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
            <p class="mt-2">Chargement des détails...</p>
        </div>

        <div id="fiche-content" style="display: none;">
            <!-- Le contenu sera généré par JavaScript -->
        </div>

        <div id="error-message" class="alert alert-danger" style="display: none;">
            <!-- Message d'erreur -->
        </div>
    </div>

    <script src="plugins/js/jquery/jquery-3.6.0.min.js"></script>
    <script src="plugins/js/bootstrap/bootstrap.min.js"></script>

    <script>
        $(document).ready(function() {
            // Récupérer le numéro de fiche depuis l'URL
            const urlParams = new URLSearchParams(window.location.search);
            const numFiche = urlParams.get('num_fiche');

            if (!numFiche) {
                showError('Numéro de fiche manquant dans l\'URL');
                return;
            }

            $('#btnExportPdf').attr('href', 'request/export_fiche_decaissement_pdf.php?num_fiche=' + encodeURIComponent(numFiche)).show();

            loadFicheDetails(numFiche);
        });

        function loadFicheDetails(numFiche) {
            $.get('request/get_fiche_details.php', {
                    num_fiche: numFiche
                })
                .done(function(response) {
                    if (response.success) {
                        displayFicheDetails(response);
                    } else {
                        showError(response.message);
                    }
                })
                .fail(function() {
                    showError('Erreur lors du chargement des détails');
                })
                .always(function() {
                    $('#loading').hide();
                });
        }

        function displayFicheDetails(data) {
            const fiche = data.fiche;
            const statusColor = getStatusColor(fiche);
            const statusText = getStatusText(fiche);
            const linkStatus = getLinkStatusConfig(fiche.link_status_code, Boolean(Number(fiche.link_status_uses_fallback || 0)));
            const renderSupplierMedia = (url, label) => {
                if (!url) {
                    return '';
                }
                const normalizedUrl = String(url).trim();
                const isPdf = /\.pdf(?:$|[?#])/i.test(normalizedUrl);
                if (isPdf) {
                    return `
                        <div class="rounded border border-slate-200 bg-white p-3 text-center">
                            <div class="mb-2 text-muted small">${escapeHtml(label)}</div>
                            <a href="${encodeURI(normalizedUrl)}" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-file-pdf"></i> Ouvrir le document
                            </a>
                        </div>`;
                }
                return `<img src="${encodeURI(normalizedUrl)}" alt="${escapeHtml(label)}" class="img-fluid rounded" style="max-height:180px; width:auto; display:block; margin:auto;" />`;
            };
            const signatureBlock = (data.signature_url || data.signature_path) ? `
                                <div class="info-card">
                                        <h5><i class="fas fa-signature text-primary"></i> Signature</h5>
                                        <div class="photo-container">
                                                <img src="${data.signature_url || data.signature_path}" alt="Signature" style="max-width:260px;max-height:160px;object-fit:contain;background:#fff;border:1px dashed #ccc;padding:6px;" />
                                        </div>
                                </div>` : '';

            const fichesLiees = Array.isArray(data.fiches_liees) ? data.fiches_liees : [];
            let ficheLieesTable = '';
            if (fichesLiees.length) {
                const totalAutres = fichesLiees.reduce((acc, f) => acc + (Number(f.montant_fiche) || 0), 0);
                const totalDecaisseAutres = fichesLiees.reduce((acc, f) => acc + (f.decaisse == 1 ? (Number(f.montant_fiche) || 0) : 0), 0);
                ficheLieesTable = `
                                    <div class="info-card">
                                        <h5><i class="fas fa-layer-group text-primary"></i> Fiches liées à la même ligne de déboursé</h5>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-bordered align-middle mb-2">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>N° Fiche</th>
                                                        <th>Bénéficiaire</th>
                                                        <th>Détail</th>
                                                        <th class="text-end">Montant</th>
                                                        <th>État</th>
                                                        <th>Date Création</th>
                                                        <th>Sig.</th>
                                                        <th>Voir</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    ${fichesLiees.map(f => {
                                                            const etat = f.decaisse==1 ? 'Décaissée' : (f.otp_valide==1?'OTP':'En cours');
                                                            const badgeClass = f.decaisse==1 ? 'success' : (f.otp_valide==1?'info':'secondary');
                                                            
                                                            /**
                                                             * Afficher en console les fiches liées avec tous leurs champs
                                                             */
                                                            return `<tr>
                                                                <td><span class="badge bg-dark">${f.num_fiche}</span></td>
                                                                <td>${escapeHtml(f.beficiaire_fiche||'')}</td>
                                                                <td>${escapeHtml(f.details_label||f.details_demande||f.precision_fiche||'—')}</td>
                                                                <td class="text-end">${Number(f.montant_fiche).toLocaleString()} FCFA</td>
                                                                <td><span class="badge bg-${badgeClass}">${etat}</span></td>
                                                                <td>${formatDate(f.date_creat_fiche)}</td>
                                                                <td class="text-center">${f.signature_fichier?'<i class="fas fa-check text-success"></i>':'<i class="fas fa-times text-muted"></i>'}</td>
                                                                <td class="text-center"><a href="voir_fiche_details.php?num_fiche=${encodeURIComponent(f.num_fiche)}" class="btn btn-outline-primary btn-sm" title="Voir"><i class="fas fa-external-link-alt"></i></a></td>
                                                            </tr>`;
                                                    }).join('')}
                                                </tbody>
                                                <tfoot class="table-light">
                                                    <tr>
                                                        <th colspan="2" class="text-end">Total autres</th>
                                                        <th class="text-end">${totalAutres.toLocaleString()} FCFA</th>
                                                        <th colspan="4" class="text-start" style="font-size:0.75rem;">
                                                            Dont décaissé: <strong style="color:#2e7d32;">${totalDecaisseAutres.toLocaleString()} FCFA</strong>
                                                        </th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>`;
            } else {
                ficheLieesTable = `
                                    <div class="info-card">
                                        <h5><i class="fas fa-layer-group text-primary"></i> Fiches liées à la même ligne de déboursé</h5>
                                        <p class="mb-0 text-muted">Aucune autre fiche liée à cette ligne.</p>
                                    </div>`;
            }

            const supplierContext = data.supplier_context || {};
            const beneficiaryContext = data.beneficiary_context || {};
            const supplierCategories = Array.isArray(supplierContext.supplier_categories) ?
                supplierContext.supplier_categories.filter(Boolean) : [];
            const supplierDocuments = Array.isArray(supplierContext.supplier_document_urls) ?
                supplierContext.supplier_document_urls.filter(doc => doc && doc.url) : [];
            const supplierVisualLabel = supplierContext.supplier_type === 'physique' ? 'Photo fournisseur' : 'Logo fournisseur';
            const supplierHtml = (supplierContext.supplier_name || supplierContext.supplier_contact || supplierContext.supplier_phone || supplierContext.supplier_email) ?
                `
                        <div class="info-card supplier-card">
                            <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
                                <div>
                                    <div class="section-kicker">Référentiel</div>
                                    <h5 class="mb-1"><i class="fas fa-truck text-primary"></i> Fournisseur</h5>
                                    <p class="mb-0 text-muted">Informations centralisées reprises depuis le dossier fournisseur.</p>
                                </div>
                                ${supplierContext.supplier_type_label ? `<span class="supplier-chip">${escapeHtml(supplierContext.supplier_type_label)}</span>` : ''}
                            </div>
                            <div class="supplier-meta-grid">
                                <div class="supplier-meta-item">
                                    <span class="label">Fournisseur</span>
                                    <span class="value">${escapeHtml(supplierContext.supplier_name || '—')}</span>
                                </div>
                                <div class="supplier-meta-item">
                                    <span class="label">Contact</span>
                                    <span class="value">${escapeHtml(supplierContext.supplier_contact || '—')}</span>
                                </div>
                                <div class="supplier-meta-item">
                                    <span class="label">Téléphone</span>
                                    <span class="value">${escapeHtml(supplierContext.supplier_phone || '—')}</span>
                                </div>
                                <div class="supplier-meta-item">
                                    <span class="label">Email</span>
                                    <span class="value">${escapeHtml(supplierContext.supplier_email || '—')}</span>
                                </div>
                                ${supplierContext.supplier_legal_form ? `
                                <div class="supplier-meta-item">
                                    <span class="label">Forme juridique</span>
                                    <span class="value">${escapeHtml(supplierContext.supplier_legal_form)}</span>
                                </div>` : ''}
                                ${supplierContext.supplier_address ? `
                                <div class="supplier-meta-item" style="grid-column: 1 / -1;">
                                    <span class="label">Adresse</span>
                                    <span class="value">${escapeHtml(supplierContext.supplier_address)}</span>
                                </div>` : ''}
                            </div>
                            ${supplierCategories.length ? `
                            <div class="supplier-chips">
                                ${supplierCategories.map(category => `<span class="supplier-chip">${escapeHtml(category)}</span>`).join('')}
                            </div>` : ''}
                            ${supplierDocuments.length ? `
                            <div class="supplier-chips">
                                ${supplierDocuments.map(doc => `<a class="supplier-chip" href="${escapeHtml(doc.url)}" target="_blank" rel="noopener noreferrer"><i class="fas fa-paperclip me-1"></i>${escapeHtml(doc.label)}</a>`).join('')}
                            </div>` : ''}
                            ${(supplierContext.supplier_visual_url || supplierContext.supplier_cni_url) ? `
                            <div class="supplier-media-grid">
                                ${supplierContext.supplier_visual_url ? `
                                    <div class="supplier-media-panel">
                                            <div class="media-label">${escapeHtml(supplierVisualLabel)}</div>
                                        ${renderSupplierMedia(supplierContext.supplier_visual_url, supplierVisualLabel)}
                                    </div>` : ''}
                                ${supplierContext.supplier_cni_url ? `
                                    <div class="supplier-media-panel">
                                            <div class="media-label">Pièce identité fournisseur</div>
                                        ${renderSupplierMedia(supplierContext.supplier_cni_url, 'Pièce identité fournisseur')}
                                    </div>` : ''}
                            </div>` : ''}
                        </div>` :
                '';
            const linkedFeb = data.linked_feb || null;
            const linkedFebJustifs = linkedFeb && Array.isArray(linkedFeb.justificatifs) ? linkedFeb.justificatifs.filter(Boolean) : [];
            const linkedInvoice = linkedFeb && linkedFeb.invoice_document ? linkedFeb.invoice_document : null;
            const linkedFebHtml = linkedFeb ? `
                        <div class="info-card">
                            <div class="section-kicker">FEB liee</div>
                            <h5 class="mb-2"><i class="fas fa-file-signature text-primary"></i> Documents de la FEB</h5>
                            ${linkedFeb.cashflow_need_label ? `<p><strong>Traitement financier :</strong> ${escapeHtml(linkedFeb.cashflow_need_label)}</p>` : ''}
                            ${linkedFeb.numero_bon_commande_interne ? `<p><strong>BCI :</strong> ${escapeHtml(linkedFeb.numero_bon_commande_interne)}</p>` : ''}
                            ${linkedInvoice && linkedInvoice.url ? `
                                <div class="rounded border border-info-subtle bg-light p-3 mb-3">
                                    <div class="fw-semibold mb-2">Facture jointe</div>
                                    <a href="${encodeURI(linkedInvoice.url)}" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-paperclip"></i> ${escapeHtml(linkedInvoice.name || 'Ouvrir la facture')}
                                    </a>
                                </div>` : ''}
                            ${linkedFebJustifs.length ? `
                                <div class="mt-3">
                                    <div class="fw-semibold mb-2">Justificatifs</div>
                                    <div class="d-flex flex-column gap-2">
                                        ${linkedFebJustifs.map((doc) => `
                                            <div class="d-flex justify-content-between align-items-center rounded border bg-white px-3 py-2">
                                                <div>
                                                    <div class="fw-semibold">${escapeHtml(doc.name || 'Justificatif')}</div>
                                                    <div class="small text-muted">${Number(doc.size || 0).toLocaleString('fr-FR')} octets</div>
                                                </div>
                                                ${doc.url ? `<a href="${encodeURI(doc.url)}" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">Ouvrir</a>` : '<span class="small text-muted">Indisponible</span>'}
                                            </div>`).join('')}
                                    </div>
                                </div>` : ''}
                        </div>` : '';

            const content = `
                <div class="fiche-header">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2><i class="fas fa-file-invoice"></i> Fiche N° ${fiche.num_fiche}</h2>
                            <p class="mb-0">Créée le ${formatDate(fiche.date_creat_fiche)}</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <span class="badge ${statusColor} status-badge">${statusText}</span>
                            <h3 class="mt-2 mb-0">${Number(fiche.montant_fiche).toLocaleString()} FCFA</h3>
                        </div>
                    </div>
                </div>
                ${Number(fiche.invoice_collection_deferred || 0) === 1 ? `
                <div class="alert alert-warning border-warning shadow-sm">
                    <i class="fas fa-clock me-2"></i><strong>Facture à collecter plus tard.</strong>
                    Cette fiche a été autorisée à poursuivre le circuit sans facture${fiche.invoice_collection_deferred_at ? ` le ${formatDate(fiche.invoice_collection_deferred_at)}` : ''}.
                </div>` : ''}
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-card">
                            <h5><i class="fas fa-user text-primary"></i> Informations du bénéficiaire</h5>
                            <p><strong>Type :</strong> ${escapeHtml(beneficiaryContext.type_label || 'Bénéficiaire')}</p>
                            <p><strong>Nom :</strong> ${escapeHtml(beneficiaryContext.name || fiche.beficiaire_fiche || '—')}</p>
                            <p><strong>Téléphone :</strong> ${escapeHtml(beneficiaryContext.phone || fiche.tel_beneficiaire_fiche || '—')}</p>
                            ${beneficiaryContext.email ? `<p><strong>Email :</strong> ${escapeHtml(beneficiaryContext.email)}</p>` : ''}
                            ${beneficiaryContext.matricule ? `<p><strong>Matricule :</strong> ${escapeHtml(beneficiaryContext.matricule)}</p>` : ''}
                            ${beneficiaryContext.fonction ? `<p><strong>Fonction :</strong> ${escapeHtml(beneficiaryContext.fonction)}</p>` : ''}
                            ${beneficiaryContext.service ? `<p><strong>Service :</strong> ${escapeHtml(beneficiaryContext.service)}</p>` : ''}
                            <p><strong>Mode de paiement :</strong> ${escapeHtml(fiche.mode_paiement || 'Non spécifié')}</p>
                            ${(beneficiaryContext.visual_url || beneficiaryContext.identity_url) ? `<div class="supplier-media-grid">
                                ${beneficiaryContext.visual_url ? `<div class="supplier-media-panel"><div class="media-label">${escapeHtml(beneficiaryContext.visual_label || 'Photo ou logo du bénéficiaire')}</div>${renderSupplierMedia(beneficiaryContext.visual_url, beneficiaryContext.visual_label || 'Photo ou logo du bénéficiaire')}</div>` : ''}
                                ${beneficiaryContext.identity_url ? `<div class="supplier-media-panel"><div class="media-label">Pièce d'identité du bénéficiaire</div>${renderSupplierMedia(beneficiaryContext.identity_url, "Pièce d'identité du bénéficiaire")}</div>` : ''}
                            </div>` : ''}
                        </div>
                        ${supplierHtml}
                        
                        <div class="info-card">
                            <h5><i class="fas fa-building text-primary"></i> Informations du chantier</h5>
                            <p><strong>Chantier :</strong> ${escapeHtml(((fiche.chantier_code || '—') + ' - ' + (fiche.chantier_titre || '—')))}</p>
                            <p><strong>Client :</strong> ${escapeHtml(fiche.nom_client || 'Non spécifié')}</p>
                            <p>
                                <strong>État du lien :</strong>
                                <span class="badge ${linkStatus.badgeClass}">${escapeHtml(fiche.link_status_label || linkStatus.label)}</span>
                            </p>
                            <p class="text-muted mb-2"><small>${escapeHtml(fiche.link_status_description || linkStatus.description)}</small></p>
                            <p><strong>Déboursé :</strong> ${escapeHtml(fiche.ligne_devis_label || '—')}</p>
                            <p><strong>Ligne précise :</strong> ${escapeHtml(fiche.ligne_debourse_label || '—')}</p>
                            ${(fiche.details_label || fiche.details_demande || fiche.precision_fiche) ? `
                            <p><strong>Détails :</strong> ${escapeHtml(fiche.details_label || fiche.details_demande || fiche.precision_fiche)}</p>` : ''}
                        </div>
                        ${linkedFebHtml}
                        
                        ${ (data.transport_spec_exists && data.transport_spec) ? `
                        <div class="info-card" style="border-left:5px solid #6a1b9a;">
                            <h5><i class="fas fa-truck-moving text-primary"></i> Spécifications Transport-Dépollution</h5>
                            <p class="mb-1"><strong>Chauffeur :</strong> ${escapeHtml(data.transport_spec.chauffeur_nom || '')}</p>
                            <p class="mb-1"><strong>Téléphone chauffeur :</strong> ${escapeHtml(data.transport_spec.chauffeur_tel || '')}</p>
                            <p class="mb-1"><strong>Matricule véhicule :</strong> ${escapeHtml(data.transport_spec.vehicule_matricule || '')}</p>
                            ${(data.transport_spec.volume_m3 !== null) ? `<p class="mb-1"><strong>Volume (m³) :</strong> ${Number(data.transport_spec.volume_m3).toLocaleString()}</p>` : ''}
                            ${(data.transport_spec.quantite_carburant_litre !== null) ? `<p class="mb-1"><strong>Quantité carburant (L) :</strong> ${Number(data.transport_spec.quantite_carburant_litre).toLocaleString()}</p>` : ''}
                            ${ (data.transport_chantier_stats) ? (()=>{
                                const s = data.transport_chantier_stats;
                                const tgt = (s.voyages_target ?? null);
                                const cur = Number(s.voyage_index||0);
                                const total = Number(s.voyages_total||0);
                                const remain = (tgt!=null) ? Math.max(0, tgt - total) : null;
                                const volF = Number(s.volume_fiche_m3||0);
                                const tonF = Number(s.tonnage_fiche_t||0);
                                const volT = Number(s.volume_total_m3||0);
                                const tonT = Number(s.tonnage_total_t||0);
                                const pct = (tgt!=null && tgt>0) ? Math.min(100, Math.round((total/tgt)*100)) : null;
                                return `
                                <hr class="my-2"/>
                                <div class="mb-2">
                                    <strong>Voyage :</strong> ${cur}${tgt?` <span class="text-muted">/ ${tgt}</span>`:''}
                                    ${pct!==null?`
                                    <div class="progress mt-1" style="height:8px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: ${pct}%;" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small class="text-muted">${total} sur ${tgt} (${pct}%)${remain!==null?`, reste ${remain}`:''}</small>`:''}
                                </div>
                                <div class="row">
                                    <div class="col-6">
                                        <div class="small text-muted">Tonnage (fiche)</div>
                                        <div><strong>${tonF.toLocaleString('fr-FR')} t</strong> <span class="text-muted">(≈ ${volF.toLocaleString('fr-FR')} m³)</span></div>
                                    </div>
                                    <div class="col-6">
                                        <div class="small text-muted">Cumul chantier</div>
                                        <div><strong>${tonT.toLocaleString('fr-FR')} t</strong> <span class="text-muted">(≈ ${volT.toLocaleString('fr-FR')} m³)</span></div>
                                    </div>
                                </div>`;
                            })() : ''}
                            <p class="text-muted mt-2" style="font-size:0.75rem;">Enregistré: ${formatDate(data.transport_spec.created_at)}</p>
                        </div>` : ''}

                        <div class="montant-info">
                            <h6><i class="fas fa-calculator text-success"></i> Informations budgétaires</h6>
                            <div class="row">
                                <div class="col-4">
                                    <strong style="color: #1976d2;">Prévu :</strong>
                                    <div>${Number(data.montant_prevu).toLocaleString()} FCFA</div>
                                </div>
                                <div class="col-4">
                                    <strong style="color: #f57c00;">Décaissé :</strong>
                                    <div>${Number(data.montant_deja_decaisse).toLocaleString()} FCFA</div>
                                </div>
                                <div class="col-4">
                                    <strong style="color: #2e7d32;">Restant :</strong>
                                    <div>${Number(data.montant_restant).toLocaleString()} FCFA</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        ${signatureBlock}
                    </div>
                </div>
                ${ficheLieesTable}
                
                ${data.historique && data.historique.length > 0 ? `
                    <div class="info-card">
                        <h5><i class="fas fa-history text-primary"></i> Historique des actions</h5>
                        <div class="timeline">
                            ${data.historique.map(item => `
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong>${item.action}</strong>
                                            <br>
                                            <small class="text-muted">Par ${item.utilisateur}</small>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted">${formatDate(item.date_action)}</small>
                                            ${item.adresse_ip ? `<br><small class="text-muted">IP: ${item.adresse_ip}</small>` : ''}
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                ` : ''}
            `;

            $('#fiche-content').html(content).show();
        }

        function getStatusColor(fiche) {
            if (fiche.decaisse == 1) return 'bg-success';
            if (fiche.otp_valide == 1) return 'bg-info';
            if (fiche.approuve == 1) return 'bg-warning';
            if (fiche.conforme == 1) return 'bg-primary';
            return 'bg-secondary';
        }

        function getStatusText(fiche) {
            if (fiche.decaisse == 1) return 'Décaissée';
            if (fiche.otp_valide == 1) return 'OTP Validé';
            if (fiche.approuve == 1) return 'Approuvée';
            if (fiche.conforme == 1) return 'Conforme';
            return 'En attente';
        }

        function getLinkStatusConfig(code, usesFallback) {
            if (code === 'valid') {
                return {
                    label: 'Lien réel valide',
                    description: 'La fiche est reliée à une vraie ligne de déboursé encore existante.',
                    badgeClass: 'bg-success'
                };
            }
            if (code === 'wrong_devis') {
                return {
                    label: 'Lien incohérent',
                    description: 'La ligne pointée existe, mais elle n’appartient pas au devis du chantier.',
                    badgeClass: 'bg-warning text-dark'
                };
            }
            if (code === 'absent') {
                return {
                    label: 'Aucun lien réel',
                    description: 'Aucune ligne de déboursé valide n’est enregistrée sur cette fiche.',
                    badgeClass: 'bg-secondary'
                };
            }
            return {
                label: 'Lien cassé',
                description: usesFallback ?
                    'Le lien réel est cassé. Les libellés affichés sont des libellés de secours reconstitués depuis la fiche.' : 'Le lien réel est cassé et aucun fallback fiable n’est disponible.',
                badgeClass: 'bg-danger'
            };
        }

        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('fr-FR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function showError(message) {
            $('#error-message').text(message).show();
            $('#loading').hide();
        }

        // Utilitaire simple pour éviter injection HTML lors de l'affichage des specs transport
        function escapeHtml(str) {
            if (typeof str !== 'string') return '';
            return str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
    </script>
</body>

</html>
