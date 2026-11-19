(() => {
'use strict';

function bootFebDisbursementPolish() {
    const page = document.getElementById('febFormPage');
    const form = document.getElementById('febMainForm');
    if (!page || !form || page.dataset.disbursementPolishReady === '1') return;
    page.dataset.disbursementPolishReady = '1';

    const title = form.querySelector('[name="feb_quote_title"]');
    const details = form.querySelector('[name="draft_fd_details_demande"]');
    const amount = document.getElementById('draftFdMontant');
    const payment = document.getElementById('draftFdModePaiement');
    const review = document.getElementById('febReviewStep');

    // Le détail de la fiche de décaissement est une donnée dérivée du titre du devis.
    // On le synchronise systématiquement afin d'éviter deux saisies divergentes.
    const syncDetails = () => {
        if (!title || !details) return;
        details.value = title.value.trim();
        details.readOnly = true;
    };
    title?.addEventListener('input', syncDetails);
    title?.addEventListener('change', syncDetails);
    form.addEventListener('submit', syncDetails, true);
    syncDetails();

    // Le montant est déjà calculé depuis le devis : conserver le champ pour le POST,
    // mais ne plus l'afficher comme une saisie à l'étape mode de paiement.
    if (amount) {
        const amountLabel = amount.closest('label');
        if (amountLabel) amountLabel.classList.add('feb-derived-hidden');
    }

    // Le select natif reste la source de vérité et est soumis au backend. L'interface
    // visible est uniquement constituée des boutons de paiement designés.
    if (payment) {
        const paymentLabel = payment.closest('label');
        if (paymentLabel) paymentLabel.classList.add('feb-payment-source');
        payment.setAttribute('aria-hidden', 'true');
        payment.tabIndex = -1;
    }

    if (details) {
        const detailsLabel = details.closest('label');
        if (detailsLabel) detailsLabel.classList.add('feb-derived-detail');
    }

    const style = document.createElement('style');
    style.textContent = `
        #febFormPage .feb-derived-hidden{display:none!important}
        #febFormPage .feb-payment-source>span:first-child,#febFormPage .feb-payment-source>select{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;clip-path:inset(50%)!important;white-space:nowrap!important;opacity:0!important;pointer-events:none!important}
        #febFormPage .feb-payment-source{display:block!important;grid-column:1/-1!important}
        #febFormPage .feb-payment-source .feb-payment-choices{margin-top:0!important}
        #febFormPage .feb-derived-detail input{background:#f8fafc!important;color:#334155!important}
        #febFormPage .feb-iso-preview{position:relative;overflow:hidden;margin-top:18px;border:1px solid #d9dde3;border-radius:4px;background:#fff;box-shadow:0 12px 34px rgba(15,23,42,.09);color:#111827}
        #febFormPage .feb-iso-preview:before{content:'BANAMUR';position:absolute;left:50%;top:53%;z-index:0;transform:translate(-50%,-50%) rotate(-32deg);font-size:72px;font-weight:900;letter-spacing:.12em;color:rgba(15,23,42,.035);pointer-events:none}
        #febFormPage .feb-iso-head{position:relative;z-index:1;display:grid;grid-template-columns:130px 1fr 104px;align-items:stretch;background:#050505;color:#fff;border-bottom:5px solid #fabd02}
        #febFormPage .feb-iso-brand{display:flex;align-items:center;padding:12px;font-size:20px;font-weight:900;letter-spacing:.04em;color:#fabd02}
        #febFormPage .feb-iso-brand img{width:80px;height:80px;object-fit:contain}
        #febFormPage .feb-iso-title{display:flex;flex-direction:column;justify-content:center;padding:14px 18px;text-align:center;border-left:1px solid #292929;border-right:1px solid #292929}
        #febFormPage .feb-iso-title strong{font-size:18px;letter-spacing:.03em}.feb-iso-title small{margin-top:5px;color:#fabd02;font-size:10px;font-weight:800;letter-spacing:.13em}
        #febFormPage .feb-iso-qr{display:flex;align-items:center;justify-content:center;padding:8px;background:#fff}.feb-iso-qr img{width:82px;height:82px;object-fit:contain}
        #febFormPage .feb-iso-body{position:relative;z-index:1;padding:28px 30px 32px}.feb-iso-kicker{font-size:10px;font-weight:800;letter-spacing:.16em;color:#64748b;text-transform:uppercase}.feb-iso-object{margin:7px 0 8px;font-size:22px;line-height:1.25;font-weight:800;color:#0f172a}.feb-iso-amount{font-size:26px;font-weight:900;color:#0f172a}
        #febFormPage .feb-iso-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0;margin-top:24px;border:1px solid #e2e8f0}.feb-iso-meta>div{padding:12px 14px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0}.feb-iso-meta span{display:block;color:#64748b;font-size:9px;font-weight:800;letter-spacing:.11em;text-transform:uppercase}.feb-iso-meta strong{display:block;margin-top:4px;color:#111827;font-size:12px}
        #febFormPage .feb-iso-foot{display:flex;justify-content:space-between;gap:16px;margin-top:24px;padding-top:12px;border-top:2px solid #fabd02;color:#64748b;font-size:9px}.feb-iso-code{font-weight:800;color:#111827}
        #febFormPage .feb-iso-section{margin-top:20px;padding-bottom:6px;border-bottom:3px solid #fabd02;color:#111827;font-size:14px;font-weight:850}
        #febFormPage .feb-iso-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));margin-top:9px;border-top:1px solid #e2e8f0;border-left:1px solid #e2e8f0}
        #febFormPage .feb-iso-grid>div{min-width:0;padding:10px 12px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0}
        #febFormPage .feb-iso-grid span{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}
        #febFormPage .feb-iso-grid strong{display:block;margin-top:5px;color:#111827;font-size:12px;overflow-wrap:anywhere}
        #febFormPage .feb-iso-lines{width:100%;margin-top:9px;border-collapse:collapse;font-size:12px}
        #febFormPage .feb-iso-lines th{background:#fabd02;color:#111;padding:9px 7px;text-align:left}
        #febFormPage .feb-iso-lines td{padding:9px 7px;border:1px solid #e2e8f0;vertical-align:top;overflow-wrap:anywhere}
        #febFormPage .feb-iso-lines td small{display:block;color:#64748b;margin-top:3px}
        #febFormPage .feb-iso-note{margin-top:12px;padding:12px;border:1px solid #f4d35e;background:#fffbeb;color:#78350f;font-size:11px;line-height:1.6}
        #febFormPage .feb-iso-details{white-space:pre-wrap;color:#334155;font-size:12px;line-height:1.6}
        #febFormPage .feb-official-preview{margin-top:18px}
        #febFormPage .feb-official-preview iframe{display:block;width:100%;height:min(78vh,920px);min-height:540px;border:1px solid #d9dde3;border-radius:12px;background:#fff}
        #febFormPage .feb-official-preview a{display:inline-flex;margin-top:10px;color:#135da8;font-size:12px;font-weight:800}
        #febFormPage .feb-official-preview-status{margin-top:12px;color:#64748b;font-size:12px}
        #febFormPage .feb-official-preview [hidden],#febFormPage .feb-iso-preview[hidden]{display:none!important}
        #febFormPage .feb-review-list{display:none!important}
        @media(max-width:700px){#febFormPage .feb-iso-head{grid-template-columns:90px 1fr 78px}.feb-iso-qr img{width:62px!important;height:62px!important}#febFormPage .feb-iso-brand{padding:10px;font-size:14px}#febFormPage .feb-iso-title strong{font-size:13px}#febFormPage .feb-iso-body{padding:20px 16px}.feb-iso-object{font-size:18px}.feb-iso-grid{grid-template-columns:1fr 1fr}.feb-iso-lines{display:block;overflow-x:auto}}
    `;
    document.head.appendChild(style);

    const value = (selector, fallback = '—') => {
        const el = form.querySelector(selector);
        if (!el) return fallback;
        if (el.tagName === 'SELECT') return el.selectedOptions?.[0]?.textContent?.trim() || fallback;
        return String(el.value || '').trim() || fallback;
    };
    const money = () => {
        const n = Number(amount?.value || document.getElementById('feb_montant')?.value || 0);
        return `${n.toLocaleString('fr-FR')} FCFA`;
    };
    const escapeHtml = (v) => String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

    function renderIsoPreview() {
        if (!review) return;
        let preview = review.querySelector('.feb-iso-preview');
        if (!preview) {
            preview = document.createElement('article');
            preview.className = 'feb-iso-preview';
            review.appendChild(preview);
        }
        const object = title?.value?.trim() || 'Fiche d’expression de besoin';
        const pay = payment?.value || 'À sélectionner';
        const supplier = document.getElementById('febGlobalSupplier')?.selectedOptions?.[0]?.textContent?.trim() || '—';
        const needs = Array.from(form.querySelectorAll('#febBesoinContainer > *')).map((node, index) => ({
            number:index+1,
            name:node.querySelector('input[name="besoin_libelle[]"]')?.value?.trim() || 'Besoin',
            category:node.querySelector('[name="besoin_categorie[]"]')?.value || '',
            quantity:Number(node.querySelector('input[name="besoin_quantite[]"]')?.value || 0),
            unitPrice:Number(node.querySelector('input[name="besoin_fournisseur_prix[]"]')?.value || 0),
            supplier:node.querySelector('[name="besoin_fournisseur_nom[]"]')?.value || supplier,
            proof:!!node.querySelector('input[type="file"]')?.files?.length || !!document.getElementById('febQuoteDocument')?.files?.length
        }));
        const dateLabel = input => /^\d{4}-\d{2}-\d{2}$/.test(input) ? input.split('-').reverse().join('/') : '—';
        const plannedStart = document.getElementById('febPlannedStart')?.value || '';
        const plannedEnd = document.getElementById('febPlannedEnd')?.value || '';
        const delivery = document.getElementById('feb_date')?.value || '';
        const budgetPlanned = form.querySelector('input[name="budget_planned"]:checked')?.value === 'yes';
        const detail = details?.value?.trim() || object;
        const qrPayload = encodeURIComponent(`FINEA|FEB|${object}|${money()}`);
        const qrUrl = `../request/feb_preview_qr.php?data=${qrPayload}`;
        preview.innerHTML = `
            <div class="feb-iso-head">
                <div class="feb-iso-brand"><img src="${escapeHtml(window.FEB_BRAND_LOGO_URL || '../logo/default_logo.jpg')}" alt="Logo BANAMUR"></div>
                <div class="feb-iso-title"><strong>FICHE D’EXPRESSION DE BESOIN</strong><small>DOCUMENT MAÎTRISÉ · ISO 9001</small></div>
                <div class="feb-iso-qr"><img src="${qrUrl}" alt="QR code de contrôle FEB"></div>
            </div>
            <div class="feb-iso-body">
                <div class="feb-iso-kicker">FEB · Projet</div>
                <div class="feb-iso-object">${escapeHtml(object)}</div>
                <div class="feb-iso-amount">${escapeHtml(money())}</div>
                <div class="feb-iso-section">Informations générales</div>
                <div class="feb-iso-grid">
                    <div><span>Demandeur</span><strong>${escapeHtml(document.getElementById('febBeneficiaryPreview')?.dataset.requesterName || '—')}</strong></div>
                    <div><span>Service bénéficiaire</span><strong>${escapeHtml(value('[name="departement_id"]'))}</strong></div>
                    <div><span>Statut</span><strong>Avant soumission</strong></div>
                    <div><span>Position budgétaire</span><strong>${budgetPlanned?'Prévue au déboursé':'Non prévue au déboursé'}</strong></div>
                    <div><span>Mode de paiement</span><strong>${escapeHtml(pay)}</strong></div>
                    <div><span>Montant total</span><strong>${escapeHtml(money())}</strong></div>
                </div>
                <div class="feb-iso-section">Besoins exprimés</div>
                <table class="feb-iso-lines"><thead><tr><th>N°</th><th>Besoin</th><th>Qté</th><th>Fournisseur</th><th>PU</th><th>Total</th><th>Justif.</th></tr></thead><tbody>${needs.length ? needs.map(row=>`<tr><td>${row.number}</td><td>${escapeHtml(row.name)}<small>${escapeHtml(row.category)}</small></td><td>${row.quantity}</td><td>${escapeHtml(row.supplier)}</td><td>${escapeHtml(row.unitPrice.toLocaleString('fr-FR'))} FCFA</td><td>${escapeHtml((row.quantity*row.unitPrice).toLocaleString('fr-FR'))} FCFA</td><td>${row.proof?'Oui':'Non'}</td></tr>`).join('') : '<tr><td colspan="7">Aucun besoin renseigné.</td></tr>'}</tbody></table>
                <div class="feb-iso-section">Affectation et planning</div>
                <div class="feb-iso-grid"><div><span>Début prévu</span><strong>${dateLabel(plannedStart)}</strong></div><div><span>Fin prévue</span><strong>${dateLabel(plannedEnd)}</strong></div><div><span>Livraison souhaitée</span><strong>${dateLabel(delivery)}</strong></div></div>
                <div class="feb-iso-section">Détails fiche</div><p class="feb-iso-details">${escapeHtml(detail)}</p>
                <div class="feb-iso-note"><strong>Document maîtrisé.</strong> Export officiel FINEA conforme au gabarit documentaire BANAMUR. Le QR code permet d’identifier la FEB et son état au moment de l’édition.</div>
                <div class="feb-iso-foot"><span class="feb-iso-code">FIN-FEB · FINEA</span><span>Prévisualisation avant soumission</span></div>
            </div>`;
    }

    let officialUrl = '';
    let officialRequest = null;
    let officialTimer = null;
    const officialHost = document.createElement('div');
    officialHost.className = 'feb-official-preview';
    officialHost.innerHTML = '<div class="feb-official-preview-status" role="status">Préparation de l’aperçu officiel…</div><iframe title="Aperçu officiel de la FEB" hidden></iframe><a target="_blank" rel="noopener" hidden>Ouvrir la FEB en plein écran</a>';
    review?.appendChild(officialHost);
    const officialFrame = officialHost.querySelector('iframe');
    const officialLink = officialHost.querySelector('a');
    const officialStatus = officialHost.querySelector('[role="status"]');
    const draftPayload = () => {
        const supplier = document.getElementById('febGlobalSupplier')?.selectedOptions?.[0]?.textContent?.trim() || '';
        const needs = Array.from(form.querySelectorAll('#febBesoinContainer > *')).map(node => ({
            libelle:node.querySelector('[name="besoin_libelle[]"]')?.value || '',
            categorie:node.querySelector('[name="besoin_categorie[]"]')?.value || '',
            type:node.querySelector('[name="besoin_type[]"]')?.value || '',
            quantite:Number(node.querySelector('[name="besoin_quantite[]"]')?.value || 0),
            fournisseur:{nom:node.querySelector('[name="besoin_fournisseur_nom[]"]')?.value || supplier,prix_unitaire:Number(node.querySelector('[name="besoin_fournisseur_prix[]"]')?.value || 0)},
            justificatif_devis:node.querySelector('input[type="file"]')?.files?.length ? {name:node.querySelector('input[type="file"]').files[0].name} : (document.getElementById('febQuoteDocument')?.files?.length ? {name:document.getElementById('febQuoteDocument').files[0].name} : {})
        }));
        const budget = form.querySelector('input[name="budget_planned"]:checked')?.value || '';
        return {
            feb_quote_title:title?.value || '',description:details?.value || title?.value || '',montant:Number(document.getElementById('feb_montant')?.value || 0),
            departement_label:document.getElementById('departement_id')?.selectedOptions?.[0]?.textContent?.trim() || '',
            budget_planned:budget,budget_planned_label:budget==='yes'?'Prévue au déboursé':'Non prévue au déboursé',
            planned_start_date:document.getElementById('febPlannedStart')?.value || '',planned_end_date:document.getElementById('febPlannedEnd')?.value || '',date_livraison:document.getElementById('feb_date')?.value || '',
            provisional_decaissement:{mode_paiement:payment?.value || '',details_demande:details?.value || title?.value || ''},besoins:needs
        };
    };
    const renderOfficialPreview = async () => {
        if (!review || review.classList.contains('hidden') || !window.FEB_PREVIEW_CSRF) return;
        officialRequest?.abort();
        const controller = new AbortController(); officialRequest = controller;
        officialStatus.hidden = false; officialStatus.textContent = 'Préparation de la FEB officielle…';
        try {
            const body = new URLSearchParams({draft_preview:'1',_csrf:window.FEB_PREVIEW_CSRF,payload_json:JSON.stringify(draftPayload())});
            const response = await fetch('../request/export_feb_submission_pdf.php', {method:'POST',body,credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/pdf'}});
            if (!response.ok || !response.headers.get('content-type')?.includes('application/pdf')) throw new Error('Le PDF officiel n’a pas pu être généré.');
            const url = URL.createObjectURL(await response.blob());
            if (officialUrl) URL.revokeObjectURL(officialUrl);
            officialUrl = url; officialFrame.src = url; officialFrame.hidden = false; officialLink.href = url; officialLink.hidden = false;
            review.querySelector('.feb-iso-preview')?.setAttribute('hidden','');
            officialStatus.hidden = true;
        } catch (error) {
            if (error.name === 'AbortError') return;
            officialStatus.textContent = 'Aperçu PDF indisponible : le résumé ci-dessous reste consultable.';
            review.querySelector('.feb-iso-preview')?.removeAttribute('hidden');
        }
    };
    const scheduleOfficialPreview = () => { clearTimeout(officialTimer); officialTimer = setTimeout(renderOfficialPreview, 450); };
    document.getElementById('febFlowPrint')?.addEventListener('click', event => { if (!officialUrl) return; event.preventDefault(); event.stopImmediatePropagation(); window.open(officialUrl, '_blank', 'noopener'); }, true);

    const observer = new MutationObserver(() => {
        if (review && !review.classList.contains('hidden')) { renderIsoPreview(); scheduleOfficialPreview(); }
        syncDetails();
    });
    form.querySelectorAll('.feb-step').forEach(step => observer.observe(step, {attributes:true, attributeFilter:['class']}));
    form.addEventListener('change', () => { requestAnimationFrame(renderIsoPreview); scheduleOfficialPreview(); });
    form.addEventListener('input', () => { requestAnimationFrame(renderIsoPreview); scheduleOfficialPreview(); });
    renderIsoPreview();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bootFebDisbursementPolish, {once:true});
else bootFebDisbursementPolish();
})();
