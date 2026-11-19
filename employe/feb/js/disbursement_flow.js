(function () {
    'use strict';
    const form=document.getElementById('febMainForm');
    const page=document.getElementById('febFormPage');
    const review=document.getElementById('febReviewStep');
    const amountStep=review?.previousElementSibling;
    const budget=document.getElementById('prepareDecaissement')?.closest('section');
    if (!form || !review || !amountStep || !budget) return;
    const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const money=value=>Number(value||0).toLocaleString('fr-FR')+' FCFA';
    const label=select=>select?.selectedOptions?.[0]?.textContent?.trim()||'—';
    const value=id=>document.getElementById(id)?.value||'';
    const date=value=>/^\d{4}-\d{2}-\d{2}$/.test(value)?value.split('-').reverse().join('/'):'—';
    const create=(index,title,subtitle)=>{
        const step=document.createElement('div');step.className='feb-step hidden feb-flow-step';step.dataset.step=String(index);
        step.innerHTML=`<header class="feb-flow-heading"><span>Décaissement · ${index-1} / 6</span><h2>${title}</h2><p>${subtitle}</p></header>`;
        review.parentNode.insertBefore(step,review);return step;
    };
    const beneficiaryStep=create(3,'À qui verser le montant ?','Confirmez le bénéficiaire final de la fiche de décaissement.');
    const allocationStep=create(4,'Affecter la dépense','Sélectionnez le chantier, puis le poste budgétaire qui financera la demande.');
    const paymentStep=create(5,'Comment recevoir le montant ?','Choisissez un mode de paiement. Le montant reste celui du devis.');
    const planningStep=create(6,'Quand la FEB sera-t-elle traitée ?','La période est synchronisée avec la ligne de déboursé choisie.');
    amountStep.insertAdjacentHTML('afterbegin','<div class="feb-flow-hero"><span>01 · Vérification du montant</span><h2>Vérifiez le montant de votre demande</h2><strong id="febFlowAmount">0 FCFA</strong><p id="febFlowAmountSummary"></p><ul id="febFlowNeeds" class="feb-flow-needs"></ul></div>');
    const beneficiary=document.getElementById('febBeneficiaryPreview')?.parentElement;
    if (beneficiary) beneficiaryStep.appendChild(beneficiary);
    const quote=document.createElement('blockquote');quote.className='feb-flow-quote';quote.innerHTML='<span>Objet de la dépense</span><p id="febFlowQuote">—</p>';allocationStep.appendChild(quote);
    allocationStep.appendChild(budget);
    const paymentSelect=document.getElementById('draftFdModePaiement');
    const paymentRow=paymentSelect?.closest('.grid');
    if (paymentRow) { paymentRow.classList.add('feb-payment-source-row'); paymentStep.appendChild(paymentRow); }
    paymentStep.insertAdjacentHTML('afterbegin','<div class="feb-flow-hero feb-flow-hero--payment"><span>04 · Mode de paiement</span><h2>Vous recevrez</h2><strong id="febFlowPaymentAmount">0 FCFA</strong></div>');
    const modes=[
        ['Cash','Cash','logo_cash.png'],['Orange Money','Orange Money','logo_om.png'],
        ['MTN Money','MTN Money','logo_momo.png'],['Moov Money','Moov Money','logo_flooz.png'],
        ['Wave','Wave','logo_wave.png'],['Djamo','Djamo','logo_djamo.png'],
        ['Chèque','Chèque','logo_cheque.png'],['Virement bancaire','Virement bancaire','virement_bancaire.png'],
        ['Carte bancaire','Carte bancaire','carte_credit.png']
    ];
    const paymentGrid=document.createElement('div');paymentGrid.className='feb-payment-grid';paymentGrid.setAttribute('role','radiogroup');paymentGrid.setAttribute('aria-label','Mode de paiement');
    paymentGrid.innerHTML=modes.map(([mode,name,icon])=>`<label class="feb-payment-option" title="${esc(name)}"><input type="radio" name="feb_payment_visual" value="${esc(mode)}" aria-label="${esc(name)}" ${paymentSelect?.value===mode?'checked':''}><span class="feb-payment-art"><img src="../decaissement/payment_icon/${icon}" alt="" loading="lazy"></span><span class="feb-payment-name">${esc(name)}</span><span class="feb-payment-check" aria-hidden="true">✓</span></label>`).join('');
    paymentStep.appendChild(paymentGrid);
    const legacy={'Espèces':'Cash','Mobile Money':'MTN Money','Virement':'Virement bancaire'};
    if (paymentSelect && legacy[paymentSelect.value]) {
        paymentGrid.querySelector(`input[value="${legacy[paymentSelect.value]}"]`).checked=true;
        paymentSelect.value=legacy[paymentSelect.value];
    }
    paymentGrid.addEventListener('change',event=>{if(event.target?.name==='feb_payment_visual'&&paymentSelect){paymentSelect.value=event.target.value;paymentSelect.dispatchEvent(new Event('change',{bubbles:true}));}});
    paymentSelect?.addEventListener('change',()=>{const selected=legacy[paymentSelect.value]||paymentSelect.value;const radio=Array.from(paymentGrid.querySelectorAll('input[name="feb_payment_visual"]')).find(input=>input.value===selected);if(radio)radio.checked=true;});
    const planning=document.getElementById('febScheduleStatus')?.parentElement;
    if(planning)planningStep.appendChild(planning);
    planningStep.insertAdjacentHTML('beforeend','<div class="feb-flow-date-note" id="febFlowEarliest"></div>');
    const nav=document.querySelector('.feb-step-navigation .grid');
    const captions=['Votre demande','Devis et besoins','Montant','Bénéficiaire','Affectation','Paiement','Planning','Aperçu final'];
    if(nav){nav.className='feb-flow-nav';nav.innerHTML=captions.map((caption,i)=>`<div id="febStepChip${i}" class="feb-flow-chip" aria-current="false"><span>${String(i+1).padStart(2,'0')}</span>${caption}</div>`).join('');}
    review.dataset.step='7';review.classList.add('feb-flow-review');
    review.insertAdjacentHTML('afterbegin','<div class="feb-flow-review-actions"><button type="button" id="febFlowPrint">Imprimer ou enregistrer en PDF</button><span>Vérifiez la FEB avant de soumettre.</span></div>');
    document.getElementById('febFlowPrint')?.addEventListener('click',()=>window.print());
    const supplier=()=>{const id=value('febGlobalSupplier');return (window.__FEB_SUPPLIERS__||[]).find(row=>String(row.id)===String(id))||{};};
    const renderBeneficiary=()=>{
        const card=document.getElementById('febBeneficiaryPreview');if(!card)return;
        const isSupplier=form.querySelector('input[name="draft_fd_beneficiary_choice"]:checked')?.value==='supplier';
        const row=supplier();const name=isSupplier?(row.name||'Fournisseur à sélectionner'):(card.dataset.requesterName||'Demandeur');
        card.className='feb-beneficiary-card';
        const photo=String(row.photo||'');const photoUrl=photo.startsWith('uploads/')?'../'+photo:photo;
        card.innerHTML=`<div class="feb-beneficiary-avatar">${isSupplier&&photoUrl?`<img src="${esc(photoUrl)}" alt="Photo ou logo de ${esc(name)}">`:`<span>${esc(name.charAt(0).toUpperCase())}</span>`}</div><div><small>${isSupplier?'Fournisseur bénéficiaire':'Demandeur bénéficiaire'}</small><strong>${esc(name)}</strong><div class="feb-beneficiary-meta">${[row.categories||row.legal_name,row.phone,row.email,row.address].filter(Boolean).map(item=>`<span>${esc(item)}</span>`).join('')}</div></div>`;
    };
    const selectorIds=['draftFdChantier','draftFdLigneDevis','draftFdDebourse','draftFdLigne'];
    const syncAllocation=()=>{
        const [chantier,line,deboursed,poste]=selectorIds.map(id=>document.getElementById(id));
        [line,deboursed,poste].forEach((control,i)=>{
            const preceding=[chantier,line,deboursed][i];const wrapper=control?.closest('label');
            if(wrapper)wrapper.hidden=!preceding?.value;
        });
        selectorIds.forEach(id=>{
            const control=document.getElementById(id),wrapper=control?.closest('label');if(!wrapper)return;
            wrapper.dataset.febValid=control.value?'true':'false';
        });
    };
    const render=index=>{
        page?.classList.toggle('feb-payment-only',index===5);
        const amount=value('feb_montant');
        if(index===2){document.getElementById('febFlowAmount').textContent=money(amount);const needs=Array.from(form.querySelectorAll('#febBesoinContainer > *'));document.getElementById('febFlowAmountSummary').textContent=`${value('febQuoteTitle')||'Votre devis'} · ${needs.length} besoin(s)`;document.getElementById('febFlowNeeds').innerHTML=needs.map(node=>{const name=node.querySelector('input[name="besoin_libelle[]"]')?.value||'Besoin';const qty=Number(node.querySelector('input[name="besoin_quantite[]"]')?.value||0);const price=Number(node.querySelector('input[name="besoin_fournisseur_prix[]"]')?.value||0);return `<li><span>${esc(name)} · ${qty} × ${esc(money(price))}</span><strong>${esc(money(qty*price))}</strong></li>`;}).join('');}
        if(index===3)renderBeneficiary();
        if(index===4){document.getElementById('febFlowQuote').textContent=value('febDraftDetails')||value('febQuoteTitle')||'—';syncAllocation();}
        if(index===5)document.getElementById('febFlowPaymentAmount').textContent=money(amount);
        if(index===6){const start=value('febPlannedStart'),node=document.getElementById('febFlowEarliest');if(node){if(/^\d{4}-\d{2}-\d{2}$/.test(start)){const earliest=new Date(start+'T12:00:00');earliest.setDate(earliest.getDate()-7);const elapsed=earliest.getTime()<=Date.now();node.dataset.dateLabel=elapsed?'Dès maintenant (seuil initial : '+earliest.toLocaleDateString('fr-FR')+')':earliest.toLocaleDateString('fr-FR');node.innerHTML=`<strong>${elapsed?'Traitement possible dès maintenant':'Traitement possible dès le '+esc(node.dataset.dateLabel)}</strong><span>${elapsed?'Le seuil initial était le '+esc(earliest.toLocaleDateString('fr-FR'))+'. Les travaux passés restent présentés pour validation.':'Soit sept jours avant le début prévu des travaux.'} Le planning se met à jour avec le poste de déboursé sélectionné.</span>`;}else{node.dataset.dateLabel='';node.textContent='Sélectionnez un poste de déboursé pour connaître la date minimale de traitement.';}}}
        if(index===7)render(6);
        const active=form.querySelectorAll('.feb-step:not(.hidden) label');
        active.forEach(wrapper=>{const field=wrapper.querySelector('input:not([type=hidden]):not([type=radio]):not([type=checkbox]),select,textarea');if(!field||!field.name||field.classList.contains('feb-payment-native')||field.closest('[hidden]'))return;wrapper.dataset.febValid=field.checkValidity()&&String(field.value||'').trim()?'true':'false';});
    };
    const validate=index=>{
        if(index===3&&form.querySelector('input[name="draft_fd_beneficiary_choice"]:checked')?.value==='supplier'&&!value('febGlobalSupplier')){window.alert('Sélectionnez d’abord le fournisseur bénéficiaire dans le devis.');return false;}
        if(index===4){syncAllocation();const missing=selectorIds.find(id=>!value(id));if(missing){document.getElementById(missing)?.focus();window.alert('Complétez le rattachement au chantier, à la ligne de devis, au déboursé et au poste.');return false;}}
        if(index===5&&!paymentSelect?.value){window.alert('Choisissez un mode de paiement.');return false;}
        return true;
    };
    form.addEventListener('change',event=>{if(selectorIds.includes(event.target.id))requestAnimationFrame(syncAllocation);if(event.target.name==='draft_fd_beneficiary_choice'||event.target.id==='febGlobalSupplier')renderBeneficiary();const field=event.target,wrapper=field.closest?.('label');if(wrapper&&field.name&&!wrapper.classList.contains('feb-payment-option'))wrapper.dataset.febValid=field.checkValidity()&&String(field.value||'').trim()?'true':'false';});
    form.addEventListener('input',event=>{const field=event.target;if(!field.matches('input:not([type=hidden]),select,textarea'))return;const wrapper=field.closest('label');if(wrapper && !wrapper.classList.contains('feb-payment-option') && field.name)wrapper.dataset.febValid=field.checkValidity()&&String(field.value||'').trim()?'true':'false';});
    ['febPlannedStart','febPlannedEnd'].forEach(id=>document.getElementById(id)?.addEventListener('change',()=>{if(form.dataset.currentStep==='6')render(6);}));
    window.FebDisbursementFlow={render,validate};
})();
