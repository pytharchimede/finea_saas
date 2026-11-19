(function () {
    'use strict';
    const config = window.__FEB_ASSISTANT_CONFIG__ || {};
    const form = document.getElementById('febMainForm');
    const launcher = document.getElementById('startFebConversation');
    if (!form || !launcher) return;

    const storageKey = 'finea.feb.assistant.' + String(config.user_id || 'user');
    const expiryMs = 7 * 24 * 60 * 60 * 1000;
    const suppliers = Array.isArray(window.__FEB_SUPPLIERS__) ? window.__FEB_SUPPLIERS__ : [];
    const draftData = window.__FEB_DRAFT_DECAISSEMENT_DATA__ || {};
    const state = { step: 'service', data: {}, lines: [], current: {}, proofIndex: 0, attempts: {}, messages: [], restoredFiles: false, resumeStep: '', editingStep: '', currentQuestion: '', quotePage: 0 };
    let locked = false;

    const overlay = document.createElement('div');
    overlay.className = 'feb-ai-overlay';
    overlay.innerHTML = `
      <div class="feb-ai-shell">
        <header class="feb-ai-topbar">
          <div class="feb-ai-brand"><span class="feb-ai-avatar"><i class="fas fa-wand-magic-sparkles"></i></span><div><div class="feb-ai-title">Assistant FEB Finéa</div><div class="feb-ai-subtitle">Une question à la fois · tes réponses restent modifiables</div></div></div>
          <div class="feb-ai-top-actions">
            <button type="button" class="feb-ai-history-btn" id="febAiHistory"><i class="fas fa-clock-rotate-left mr-2"></i><span>Revoir une réponse</span></button>
            <button type="button" class="feb-ai-exit" id="febAiExit"><i class="fas fa-table-list mr-2"></i><span>Formulaire classique</span></button>
          </div>
        </header>
        <main class="feb-ai-thread" id="febAiThread" aria-live="polite"></main>
        <footer class="feb-ai-composer"><div class="feb-ai-live-check" id="febAiLiveCheck" aria-live="polite"></div><div class="feb-ai-controls" id="febAiControls"></div></footer>
      </div>`;
    document.body.appendChild(overlay);
    const thread = overlay.querySelector('#febAiThread');
    const controls = overlay.querySelector('#febAiControls');
    const liveCheck = overlay.querySelector('#febAiLiveCheck');
    const historyButton = overlay.querySelector('#febAiHistory');

    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    const money = value => Number(value || 0).toLocaleString('fr-FR') + ' FCFA';
    const total = () => state.lines.reduce((sum, line) => sum + Number(line.quantite || 0) * Number(line.fournisseur_prix || 0), 0);
    const field = name => form.querySelector(`[name="${name}"]`);
    const fields = name => Array.from(form.querySelectorAll(`[name="${name}"]`));

    function persist() {
        try {
            localStorage.setItem(storageKey, JSON.stringify({ savedAt: Date.now(), step: state.step, data: state.data, lines: state.lines, current: state.current, proofIndex: state.proofIndex, messages: state.messages.slice(-80) }));
        } catch (_) {}
    }
    function restore() {
        try {
            const raw = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (!raw || Date.now() - Number(raw.savedAt || 0) > expiryMs) return false;
            Object.assign(state, { step: raw.step || 'service', data: raw.data || {}, lines: raw.lines || [], current: raw.current || {}, proofIndex: Number(raw.proofIndex || 0), messages: raw.messages || [] });
            state.data.globalProofAttached=false;
            state.restoredFiles = state.lines.some(line => line.proofAttached && !line.proofToken);
            state.lines.forEach(line => { line.proofAttached = Boolean(line.proofToken); });
            syncAllLines();
            syncCommonFields();
            return true;
        } catch (_) { return false; }
    }
    function clearDraft() { try { localStorage.removeItem(storageKey); } catch (_) {} }
    function hasRestorableDraft() {
        try {
            const raw=JSON.parse(localStorage.getItem(storageKey)||'null');
            if(!raw) return false;
            if(Date.now()-Number(raw.savedAt||0)>expiryMs){clearDraft();return false;}
            return Boolean(raw.step || Object.keys(raw.data||{}).length || (raw.lines||[]).length);
        } catch (_) { clearDraft(); return false; }
    }
    function draftIdentity() {
        try {
            const raw=JSON.parse(localStorage.getItem(storageKey)||'null')||{};
            const data=raw.data||{}, lines=Array.isArray(raw.lines)?raw.lines:[];
            const title=String(data.title||'').trim();
            const service=String(data.serviceLabel||'').trim();
            const firstNeed=String(lines[0]?.libelle||'').trim();
            const draftTotal=lines.reduce((sum,line)=>sum+Number(line.quantite||0)*Number(line.fournisseur_prix||0),0);
            const subject=title||firstNeed||'une FEB encore sans titre';
            const details=[];
            if(service) details.push(`pour le service ${service}`);
            if(lines.length) details.push(`${lines.length} besoin${lines.length>1?'s':''}`);
            if(draftTotal>0) details.push(money(draftTotal));
            if(!title&&!firstNeed&&raw.step) details.push(`arrêtée à l’étape « ${questionForStep(raw.step)} »`);
            return `Il s’agit de la demande « ${subject} »${details.length?' — '+details.join(' · '):''}.`;
        } catch (_) {
            return 'Ce brouillon ne contient pas encore assez d’informations pour être nommé.';
        }
    }
    function startFresh() {
        clearDraft();
        const url=new URL(location.href);
        url.search='';
        url.searchParams.set('type','feb');
        url.searchParams.set('assistant','1');
        url.searchParams.set('fresh','1');
        location.replace(url.toString());
    }
    function showDraftChoice() {
        thread.innerHTML='';
        addMessageToDom('assistant',`J’ai trouvé un brouillon enregistré sur cet appareil.\n\n${draftIdentity()}\n\nQue veux-tu faire ?`);
        choose([
            {value:'resume',label:'Reprendre le brouillon'},
            {value:'fresh',label:'Recommencer à zéro'},
            {value:'delete',label:'Supprimer le brouillon de cet appareil'}
        ],value=>{
            if(value==='resume'){
                restore();
                replay();
                renderStep();
                if(state.restoredFiles) controls.insertAdjacentHTML('afterbegin','<div class="feb-ai-support"><strong>Brouillon repris.</strong> Pour ta sécurité, joins à nouveau les justificatifs avant l’envoi.</div>');
            } else if(value==='fresh') {
                startFresh();
            } else {
                clearDraft();
                state.messages=[];
                thread.innerHTML='';
                addMessageToDom('assistant','Le brouillon a bien été supprimé de cet appareil.');
                choose([
                    {value:'fresh',label:'Commencer une nouvelle FEB'},
                    {value:'classic',label:'Retourner au formulaire classique'}
                ],next=>next==='fresh'?startFresh():exitToClassic());
            }
        });
    }
    function addMessage(role, text) {
        state.messages.push({ role, text });
        if (role === 'assistant') {
            state.currentQuestion = text;
            thread.innerHTML = '';
        }
        const item = document.createElement('div');
        item.className = 'feb-ai-message ' + role;
        item.innerHTML = `<div class="feb-ai-bubble">${esc(text)}</div>`;
        thread.appendChild(item);
        thread.scrollTop = thread.scrollHeight;
    }
    function replay() {
        thread.innerHTML = '';
        const lastQuestion = [...state.messages].reverse().find(message => message.role === 'assistant');
        if (lastQuestion) addMessageToDom(lastQuestion.role, lastQuestion.text);
    }
    function addMessageToDom(role, text) {
        const item = document.createElement('div');
        item.className = 'feb-ai-message ' + role;
        item.innerHTML = `<div class="feb-ai-bubble">${esc(text)}</div>`;
        thread.appendChild(item);
    }
    function answer(text) {
        state.messages.push({ role: 'user', text });
        persist();
    }
    function ask(text) { addMessage('assistant', text); persist(); }
    function support(step) {
        state.attempts[step] = Number(state.attempts[step] || 0) + 1;
        if (state.attempts[step] < 3) return '';
        return `<div class="feb-ai-support"><strong>Ne t’inquiète pas, ce n’est rien. On va essayer autrement.</strong><br>Tu peux passer au formulaire classique déjà prérempli.<br><br>Support IT — Axel Jaurès : <a href="tel:+2250798754667">+225 07 9875 46 67</a><br>WhatsApp ou appel : <a href="tel:+2250748367710">07 48 36 7710</a></div>`;
    }
    function fail(message) {
        ask(message);
        const extra = support(state.step);
        if (extra) controls.insertAdjacentHTML('beforeend', extra);
    }
    function setStep(step) {
        if (state.editingAnswer && state.resumeStep) {
            state.editingAnswer = false;
            state.step = state.resumeStep;
            state.resumeStep = '';
            ask('Modification enregistrée. Nous sommes revenus là où tu étais : tu peux poursuivre.');
        } else state.step = step;
        persist();
        renderStep();
    }

    function febValidationErrors() {
        const d=state.data, errors=[];
        if (!(Number(d.serviceId)>0)) errors.push('service bénéficiaire');
        if (String(d.title||'').trim().length<3) errors.push('titre du devis');
        if (!state.lines.length) errors.push('besoin');
        state.lines.forEach((line,index)=>{
            if (!['Bien','Service'].includes(line.type)||!line.categorie||!line.libelle||!(Number(line.quantite)>0)||!Number.isInteger(Number(line.quantite))||!(Number(line.fournisseur_prix)>0)) errors.push(`ligne ${index+1}`);
        });
        if(!(Number(d.globalSupplierId)>0))errors.push('fournisseur global');
        if(!d.globalProofAttached&&!field('feb_global_justificatif')?.files?.length)errors.push('justificatif global');
        if (!['yes','no'].includes(d.budget)) errors.push('budget');
        if (!(Number(d.chantierId)>0)) errors.push('chantier');
        if (d.budget==='yes') {
            if (!(Number(d.lineDevisId)>0)) errors.push('ligne de devis');
            if (!(Number(d.debourseId)>0)) errors.push('déboursé');
            if (!(Number(d.ligneDebourseId)>0)) errors.push('ligne de déboursé');
            if (!d.payment) errors.push('mode de paiement');
        }
        if (d.budget==='no'&&!(Number(d.proposedLineId)>0)&&!String(d.proposedLineLabel||'').trim()) errors.push('ligne de devis proposée');
        if (d.budget==='no'&&!String(d.proposedTitle||'').trim()) errors.push('titre du déboursé');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d.start||'')) errors.push('début');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d.end||'')||(d.start&&d.end<d.start)) errors.push('fin');
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d.delivery||'')) errors.push('livraison');
        if (!['unique','hebdomadaire','mensuelle','trimestrielle','semestrielle','annuelle'].includes(d.frequency)) errors.push('fréquence');
        return [...new Set(errors)];
    }
    function refreshLiveCheck() {
        const errors=febValidationErrors();
        liveCheck.className='feb-ai-live-check '+(errors.length?'is-pending':'is-valid');
        liveCheck.innerHTML=errors.length
            ? `<i class="fas fa-shield-halved"></i><span>Vérification FEB en temps réel · ${errors.length} élément(s) restent à compléter</span>`
            : '<i class="fas fa-circle-check"></i><span>FEB complète : les réponses correspondent aux données qui seront soumises.</span>';
    }

    const historyDefinitions = [
        { key:'serviceId', label:'Service bénéficiaire', step:'service', value:d=>d.serviceLabel },
        { key:'title', label:'Titre du devis', step:'title', value:d=>d.title },
        { key:'globalSupplierId', label:'Fournisseur global', step:'global_supplier', value:d=>d.globalSupplierLabel },
        { key:'globalProofAttached', label:'Justificatif global', step:'global_proof', value:d=>d.globalProofAttached?'Ajouté':'Manquant' },
        { key:'lines', label:'Devis et besoins', step:'method', quote:true, value:()=>`${state.lines.length} ligne(s) · ${money(total())}` },
        { key:'budget', label:'Situation budgétaire', step:'budget', value:d=>d.budget==='yes'?'Prévue au déboursé':'Nouveau besoin' },
        { key:'chantierId', label:'Chantier', step:'chantier', value:d=>d.chantierLabel },
        { key:'lineDevisId', label:'Ligne de devis', step:'line_devis', value:d=>d.lineDevisLabel },
        { key:'debourseId', label:'Déboursé', step:'debourse', value:d=>d.debourseLabel },
        { key:'ligneDebourseId', label:'Ligne de déboursé', step:'ligne_debourse', value:d=>d.ligneDebourseLabel },
        { key:'payment', label:'Mode de paiement', step:'payment', value:d=>d.payment },
        { key:'proposedLine', label:'Ligne de devis proposée', step:'proposed_line', value:d=>d.proposedLineLabel||d.proposedLineId },
        { key:'proposedTitle', label:'Titre du déboursé proposé', step:'proposed_title', value:d=>d.proposedTitle },
        { key:'start', label:'Début prévu', step:'start', value:d=>d.start },
        { key:'end', label:'Fin prévue', step:'end', value:d=>d.end },
        { key:'delivery', label:'Livraison souhaitée', step:'delivery', value:d=>d.delivery },
        { key:'frequency', label:'Fréquence', step:'frequency', value:d=>d.frequency },
    ];
    function answeredHistory() {
        return historyDefinitions.filter(item => {
            if (item.key === 'lines') return state.lines.length;
            if (item.key === 'proposedLine') return String(state.data.proposedLineLabel || state.data.proposedLineId || '').trim();
            return String(state.data[item.key] || '').trim();
        });
    }
    function resumeConversation() {
        const target = state.resumeStep || state.step;
        state.resumeStep = '';
        setStep(target);
    }
    function quoteTable() {
        const pageSize=5; let page=0;
        const draw=()=>{
            const pages=Math.max(1,Math.ceil(state.lines.length/pageSize));
            page=Math.max(0,Math.min(page,pages-1));
            const rows=state.lines.slice(page*pageSize,(page+1)*pageSize);
            controls.innerHTML=`<div class="feb-ai-consult">
              <div class="feb-ai-quote-scroll"><table class="feb-ai-quote-table"><thead><tr><th>Besoin</th><th>Catégorie</th><th>Qté</th><th>PU</th><th>Total</th></tr></thead><tbody>${rows.map(line=>`<tr><td>${esc(line.libelle)}</td><td>${esc(line.categorie)}</td><td>${esc(line.quantite)}</td><td>${esc(money(line.fournisseur_prix))}</td><td>${esc(money(Number(line.quantite)*Number(line.fournisseur_prix)))}</td></tr>`).join('')}</tbody></table></div>
              <div class="feb-ai-quote-total">Total du devis <strong>${esc(money(total()))}</strong></div>
              <div class="feb-ai-pager"><button type="button" data-page="prev" ${page===0?'disabled':''}><i class="fas fa-arrow-left"></i> Précédent</button><span>${page+1} / ${pages}</span><button type="button" data-page="next" ${page>=pages-1?'disabled':''}>Suivant <i class="fas fa-arrow-right"></i></button></div>
              <button type="button" class="feb-ai-primary" id="febAiResume">Poursuivre là où j’étais</button>
            </div>`;
            controls.querySelector('[data-page="prev"]')?.addEventListener('click',()=>{page--;draw();});
            controls.querySelector('[data-page="next"]')?.addEventListener('click',()=>{page++;draw();});
            controls.querySelector('#febAiResume').onclick=resumeConversation;
        }; draw();
    }
    function consultAnswer(item) {
        ask(`Voici la réponse enregistrée pour « ${item.label} ».`);
        if(item.quote){quoteTable();return;}
        controls.innerHTML=`<div class="feb-ai-consult"><div class="feb-ai-answer-value">${esc(item.value(state.data))}</div><button type="button" class="feb-ai-primary" id="febAiResume">Poursuivre là où j’étais</button></div>`;
        controls.querySelector('#febAiResume').onclick=resumeConversation;
    }
    function historyAction(item) {
        ask(`Veux-tu consulter ou modifier la réponse « ${item.label} » ?`);
        choose([{value:'view',label:'Consulter la réponse'},{value:'edit',label:'Modifier la réponse'},{value:'cancel',label:'Poursuivre'}],value=>{
            if(value==='view') consultAnswer(item);
            else if(value==='edit') {
                if(item.quote) {
                    state.lines=[];
                    clearClassicLines();
                    state.data.method='manual';
                    state.editingQuote=true;
                    ask('Reprenons le devis. Ton premier besoin est-il un bien ou un service ?');
                    state.step='line_type';
                    persist();
                    renderStep();
                } else {
                    state.editingAnswer=true;
                    state.step=item.step;
                    ask(`Modifie maintenant la réponse « ${item.label} ».`);
                    persist();
                    renderStep();
                }
            } else resumeConversation();
        });
    }
    function openHistory() {
        const items=answeredHistory();
        if(!items.length){ask('Il n’y a pas encore de réponse à revoir.');inputControl('Poursuivre',()=>resumeConversation());return;}
        if(!state.resumeStep) state.resumeStep=state.step;
        ask('Quelle réponse veux-tu revoir ?');
        choose(items.map((item,index)=>({value:String(index),label:`${item.label} — ${item.value(state.data)}`})),value=>historyAction(items[Number(value)]));
    }
    function setValue(name, value) {
        const input = field(name);
        if (!input) return;
        input.value = value == null ? '' : String(value);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function setRadio(name, value) {
        const radio = form.querySelector(`[name="${name}"][value="${CSS.escape(String(value))}"]`);
        if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    function setSelect(select, value) {
        if (!select) return;
        select.value = String(value || '');
        select.dataset.selected = String(value || '');
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }
    function inputControl(placeholder, onSubmit, type = 'text') {
        controls.innerHTML = `<div class="feb-ai-input-row"><input class="feb-ai-input" id="febAiInput" type="${type}" placeholder="${esc(placeholder)}"><button class="feb-ai-send" type="button" aria-label="Envoyer"><i class="fas fa-arrow-up"></i></button></div><div class="feb-ai-hint">Appuie sur Entrée pour continuer</div>`;
        const input = controls.querySelector('#febAiInput');
        const send = () => onSubmit(input.value.trim());
        controls.querySelector('.feb-ai-send').onclick = send;
        input.onkeydown = event => { if (event.key === 'Enter') { event.preventDefault(); send(); } };
        setTimeout(() => input.focus(), 30);
    }
    function choices(items) {
        controls.innerHTML = `<div class="feb-ai-choices">${items.map(item => `<button type="button" class="feb-ai-choice" data-value="${esc(item.value)}">${esc(item.label)}</button>`).join('')}</div>`;
        controls.querySelectorAll('.feb-ai-choice').forEach(button => button.onclick = () => itemChoice(button.dataset.value, button.textContent.trim()));
    }
    let choiceHandler = null;
    function itemChoice(value, label) { if (choiceHandler) choiceHandler(value, label); }
    function choose(items, handler) { choiceHandler = handler; choices(items); }
    function paymentChoices(handler) {
        const base = '../decaissement/payment_icon/';
        const items = [
            {value:'Cash',label:'Cash',image:base+'logo_cash.png'},
            {value:'Orange Money',label:'Orange Money',image:base+'logo_om.png'},
            {value:'MTN Money',label:'MTN Money',image:base+'logo_momo.png'},
            {value:'Moov Money',label:'Moov Money',image:base+'logo_flooz.png'},
            {value:'Wave',label:'Wave',image:base+'logo_wave.png'},
            {value:'Djamo',label:'Djamo',image:base+'logo_djamo.png'},
            {value:'Chèque',label:'Chèque',image:base+'logo_cheque.png'},
            {value:'Virement bancaire',label:'Virement bancaire',image:base+'logo_virement.svg'},
            {value:'Carte bancaire',label:'Carte bancaire',image:base+'logo_carte.svg'}
        ];
        controls.innerHTML = `<div class="feb-ai-payment-grid" role="group" aria-label="Mode de paiement">${items.map(item=>`<button type="button" class="feb-ai-payment-choice" data-value="${esc(item.value)}" aria-label="${esc(item.label)}" title="${esc(item.label)}"><img src="${esc(item.image)}" alt=""><span class="sr-label">${esc(item.label)}</span></button>`).join('')}</div>`;
        controls.querySelectorAll('.feb-ai-payment-choice').forEach(button => button.onclick = () => handler(button.dataset.value, button.getAttribute('aria-label') || button.dataset.value));
    }
    function searchControl(options, placeholder, handler) {
        controls.innerHTML = `<div class="feb-ai-input-row"><input class="feb-ai-input" id="febAiInput" type="search" placeholder="${esc(placeholder)}" autocomplete="off"><button class="feb-ai-send" type="button"><i class="fas fa-arrow-up"></i></button></div><div class="feb-ai-suggestions" id="febAiSuggestions"></div><div class="feb-ai-hint">Commence à écrire puis choisis une proposition</div>`;
        const input = controls.querySelector('#febAiInput');
        const list = controls.querySelector('#febAiSuggestions');
        let filtered = [];
        function refresh() {
            const q = normalize(input.value);
            filtered = options.filter(option => !q || normalize(option.label).includes(q)).slice(0, 8);
            list.innerHTML = filtered.map((option, index) => `<button type="button" ${option.disabled?'disabled aria-disabled="true"':''} class="feb-ai-suggestion${index === 0&&!option.disabled ? ' is-focus' : ''}${option.disabled?' opacity-55 cursor-not-allowed':''}" data-id="${esc(option.id)}">${esc(option.label)}</button>`).join('');
            list.querySelectorAll('button:not([disabled])').forEach(button => button.onclick = () => handler(button.dataset.id, button.textContent.trim()));
        }
        input.oninput = refresh;
        input.onkeydown = event => { const available=filtered.find(option=>!option.disabled);if (event.key === 'Enter' && available) { event.preventDefault(); handler(String(available.id), available.label); } };
        controls.querySelector('.feb-ai-send').onclick = () => { const available=filtered.find(option=>!option.disabled);if(available) handler(String(available.id), available.label); else fail('Cette valeur est indisponible ou introuvable.'); };
        refresh(); setTimeout(() => input.focus(), 30);
    }
    function fileControl(accept, label, handler, template) {
        controls.innerHTML = `${template || ''}<label class="feb-ai-file"><i class="fas fa-cloud-arrow-up mr-2"></i>${esc(label)}<input id="febAiFile" class="hidden" type="file" accept="${esc(accept)}"></label>`;
        controls.querySelector('#febAiFile').onchange = event => handler(event.target.files && event.target.files[0]);
    }

    function addClassicLine(line) {
        // Le formulaire classique crée une ligne vide par défaut. Elle doit être
        // retirée avant la première ligne venant de l’assistant, sinon les valeurs
        // et le justificatif se retrouvent décalés sur « Besoin 2 ».
        Array.from(document.querySelectorAll('#febBesoinContainer > div')).forEach(wrap => {
            const label=String(wrap.querySelector('[name="besoin_libelle[]"]')?.value||'').trim();
            const quantity=Number(wrap.querySelector('[name="besoin_quantite[]"]')?.value||0);
            const price=Number(wrap.querySelector('[name="besoin_fournisseur_prix[]"]')?.value||0);
            const proof=wrap.querySelector('[name="besoin_justificatif[]"]');
            if(!label && !(quantity>0) && !(price>0) && !proof?.files?.length) wrap.querySelector('button .fa-trash')?.closest('button')?.click();
        });
        document.getElementById('febAddBesoin')?.click();
        const wraps = Array.from(document.querySelectorAll('#febBesoinContainer > div'));
        const wrap = wraps[wraps.length - 1];
        if (!wrap) return null;
        const set = (selector, value, event = 'input') => { const el = wrap.querySelector(selector); if (el) { el.value = String(value ?? ''); el.dispatchEvent(new Event(event, { bubbles: true })); } };
        set('[name="besoin_type[]"]', line.type, 'change');
        set('[name="besoin_categorie[]"]', line.categorie, 'change');
        set('[name="besoin_libelle[]"]', line.libelle);
        set('[name="besoin_quantite[]"]', line.quantite);
        set('.bf-supplier-select', line.fournisseur_id, 'change');
        set('[name="besoin_fournisseur_prix[]"]', line.fournisseur_prix);
        set('[name="besoin_justificatif_token[]"]', line.proofToken);
        if(line.proofToken) {
            const proof=wrap.querySelector('[name="besoin_justificatif[]"]');
            if(proof) {
                proof.required=false;
                const hint=proof.parentElement?.querySelector('p');
                if(hint) hint.textContent=`Justificatif déjà vérifié et conservé${line.proofName?' : '+line.proofName:''}`;
            }
        }
        if(typeof File!=='undefined' && line.proofFile instanceof File) {
            const proof=wrap.querySelector('[name="besoin_justificatif[]"]');
            if(proof) {
                const transfer=new DataTransfer();
                transfer.items.add(line.proofFile);
                proof.files=transfer.files;
            }
        }
        return wrap;
    }
    function clearClassicLines() {
        document.querySelectorAll('#febBesoinContainer > div').forEach(wrap => wrap.querySelector('button .fa-trash')?.closest('button')?.click());
    }
    function syncAllLines() {
        clearClassicLines();
        state.lines.forEach(line => addClassicLine(line));
        setValue('montant', total());
    }
    async function attachProof(index, file) {
        const line = state.lines[index];
        const wrap = line
            ? Array.from(document.querySelectorAll('#febBesoinContainer > div'))[index]
            : null;
        const input = wrap && wrap.querySelector('[name="besoin_justificatif[]"]');
        if (!input || !file) return false;
        const allowed = ['pdf','jpg','jpeg','png','webp'];
        const ext = String(file.name || '').split('.').pop().toLowerCase();
        if (!allowed.includes(ext) || file.size > 10 * 1024 * 1024) return false;
        const body=new FormData();
        body.append('proof',file);
        body.append('csrf_token',String(config.proof_upload_csrf||''));
        const response=await fetch(config.proof_upload_url,{method:'POST',body,headers:{Accept:'application/json'}});
        const json=await response.json().catch(()=>({ok:false,error:'Réponse du serveur illisible.'}));
        if(!response.ok||!json.ok) throw new Error(json.error||'Le justificatif n’a pas pu être conservé.');
        line.proofAttached = true; line.proofName = json.name||file.name; line.proofToken=json.token||''; line.proofFile=null;
        const tokenInput=wrap.querySelector('[name="besoin_justificatif_token[]"]');
        if(tokenInput) tokenInput.value=line.proofToken;
        input.required=false;
        input.value='';
        const hint=input.parentElement?.querySelector('p');
        if(hint) hint.textContent=`Justificatif vérifié et conservé : ${line.proofName}`;
        input.dispatchEvent(new Event('change',{bubbles:true}));
        return true;
    }
    function selectOptions(select, predicate) {
        return Array.from(select?.options || []).filter(option => option.value && !option.disabled && (!predicate || predicate(option))).map(option => ({ id: option.value, label: option.textContent.trim() }));
    }
    function syncCommonFields() {
        const d = state.data;
        if (d.serviceId) setValue('departement_id', d.serviceId);
        if (d.title) setValue('feb_quote_title', d.title);
        if (d.globalSupplierId) setSelect(document.getElementById('febGlobalSupplier'),d.globalSupplierId);
        if (d.budget) setRadio('budget_planned', d.budget);
        if (d.chantierId) setSelect(document.getElementById('draftFdChantier'), d.chantierId);
        if (d.lineDevisId) setSelect(document.getElementById('draftFdLigneDevis'), d.lineDevisId);
        if (d.debourseId) setSelect(document.getElementById('draftFdDebourse'), d.debourseId);
        if (d.ligneDebourseId) setSelect(document.getElementById('draftFdLigne'), d.ligneDebourseId);
        if (d.fdSupplierId) setSelect(document.getElementById('draftFdFournisseur'), d.fdSupplierId);
        if (d.payment) setSelect(document.getElementById('draftFdModePaiement'), d.payment);
        if (d.proposedLineId) setSelect(document.getElementById('proposedLigneDevis'), d.proposedLineId);
        if (d.proposedLineLabel) setValue('proposed_ligne_devis_label', d.proposedLineLabel);
        if (d.proposedTitle) setValue('proposed_debourse_title', d.proposedTitle);
        if (d.start) setValue('planned_start_date', d.start);
        if (d.end) setValue('planned_end_date', d.end);
        if (d.delivery) setValue('date_livraison', d.delivery);
        if (d.frequency) setValue('frequence', d.frequency);
        if (d.description) setValue('description', d.description);
        if (d.impact) setValue('impact', d.impact);
        if (d.budget === 'yes') {
            setValue('draft_fd_montant_fiche', total());
            setValue('draft_fd_details_demande', d.title);
        }
        if (d.budget === 'no' && d.start && d.end) prepareProposedLines();
    }

    function selectedText(element) {
        return element?.selectedOptions?.[0]?.textContent?.trim() || '';
    }
    function classicFormHasProgress() {
        const names=['departement_id','feb_quote_title','budget_planned','draft_fd_chantier_id','planned_start_date','planned_end_date','date_livraison','frequence','description','impact'];
        if(names.some(name=>fields(name).some(input=>input.type==='radio'?input.checked:String(input.value||'').trim()!==''))) return true;
        return Array.from(document.querySelectorAll('#febBesoinContainer > div')).some(wrap=>
            ['besoin_libelle[]','besoin_quantite[]','besoin_fournisseur_prix[]'].some(name=>String(wrap.querySelector(`[name="${name}"]`)?.value||'').trim()!=='')
        );
    }
    function questionForStep(step) {
        const questions={
            service:'Quel service va bénéficier de cette demande ?',
            title:'Quel est le titre du devis ?',
            method:'Comment veux-tu me donner les lignes du devis ?',
            line_type:'Ton prochain besoin est-il un bien ou un service ?',
            line_category:'Dans quelle catégorie se trouve ce besoin ?',
            line_label:'Décris précisément ce dont tu as besoin.',
            line_qty:'Quelle quantité faut-il ?',
            line_supplier:'Quel fournisseur approuvé proposes-tu ?',
            line_price:'Quel est son prix unitaire en FCFA ?',
            proof:'Joins le devis ou justificatif de cette ligne.',
            budget:'La dépense est-elle déjà prévue dans un déboursé ?',
            chantier:'Sur quel chantier porte cette demande ?',
            line_devis:'À quelle ligne de devis faut-il rattacher la demande ?',
            debourse:'Quel déboursé correspond à cette demande ?',
            ligne_debourse:'Et quelle ligne de déboursé ?',
            fd_supplier:'Quel fournisseur recevra le paiement ?',
            payment:'Quel mode de paiement faut-il prévoir ?',
            proposed_line:'Choisis une ligne de devis ou proposes-en une nouvelle.',
            proposed_line_label:'Quel libellé proposes-tu pour la nouvelle ligne de devis ?',
            proposed_title:'Quel titre proposes-tu pour le nouveau déboursé ?',
            start:'À quelle date l’action doit-elle commencer ?',
            end:'À quelle date doit-elle se terminer ?',
            delivery:'Quelle est la date de livraison souhaitée ?',
            frequency:'À quelle fréquence ce besoin reviendra-t-il ?',
            description:'Voici le récapitulatif avant l’envoi.',
            impact:'Voici le récapitulatif avant l’envoi.',
            review:'Voici le récapitulatif de la FEB mise à jour.'
        };
        return questions[step]||'Continuons ta FEB.';
    }
    function hydrateFromClassicForm() {
        if(!classicFormHasProgress()) return false;
        const d={};
        const service=field('departement_id');
        d.serviceId=service?.value||''; d.serviceLabel=selectedText(service);
        d.title=field('feb_quote_title')?.value?.trim()||'';
        const globalSupplier=document.getElementById('febGlobalSupplier');
        d.globalSupplierId=Number(globalSupplier?.value||0);d.globalSupplierLabel=selectedText(globalSupplier);
        d.globalProofAttached=Boolean(field('feb_global_justificatif')?.files?.length||!field('feb_global_justificatif')?.required);
        d.budget=form.querySelector('[name="budget_planned"]:checked')?.value||'';
        const chantier=document.getElementById('draftFdChantier');
        d.chantierId=chantier?.value||''; d.chantierLabel=selectedText(chantier);
        const lineDevis=document.getElementById('draftFdLigneDevis');
        d.lineDevisId=lineDevis?.value||''; d.lineDevisLabel=selectedText(lineDevis);
        const debourse=document.getElementById('draftFdDebourse');
        d.debourseId=debourse?.value||''; d.debourseLabel=selectedText(debourse);
        const ligne=document.getElementById('draftFdLigne');
        d.ligneDebourseId=ligne?.value||''; d.ligneDebourseLabel=selectedText(ligne);
        const fdSupplier=document.getElementById('draftFdFournisseur');
        d.fdSupplierId=fdSupplier?.value||''; d.fdSupplierLabel=selectedText(fdSupplier);
        d.payment=document.getElementById('draftFdModePaiement')?.value||'';
        const proposed=document.getElementById('proposedLigneDevis');
        d.proposedLineId=proposed?.value||''; d.proposedLineLabel=field('proposed_ligne_devis_label')?.value?.trim()||'';
        d.proposedTitle=field('proposed_debourse_title')?.value?.trim()||'';
        d.start=field('planned_start_date')?.value||'';
        d.end=field('planned_end_date')?.value||'';
        d.delivery=field('date_livraison')?.value||'';
        d.frequency=field('frequence')?.value||'';
        d.description=field('description')?.value?.trim()||'';
        d.impact=field('impact')?.value?.trim()||'';

        const completed=[], wraps=Array.from(document.querySelectorAll('#febBesoinContainer > div'));
        let partial=null, partialStep='';
        wraps.forEach(wrap=>{
            const supplierId=d.globalSupplierId||'';
            const supplier=suppliers.find(item=>String(item.id)===String(supplierId));
            const proof=wrap.querySelector('[name="besoin_justificatif[]"]');
            const proofToken=wrap.querySelector('[name="besoin_justificatif_token[]"]')?.value||'';
            const line={
                type:wrap.querySelector('[name="besoin_type[]"]')?.value||'',
                categorie:wrap.querySelector('[name="besoin_categorie[]"]')?.value||'',
                libelle:wrap.querySelector('[name="besoin_libelle[]"]')?.value?.trim()||'',
                quantite:Number(wrap.querySelector('[name="besoin_quantite[]"]')?.value||0),
                fournisseur_id:Number(supplierId||0),
                fournisseur_nom:supplier?.name||wrap.querySelector('.bf-supplier-display-name')?.value||'',
                fournisseur_tel:supplier?.phone||'',
                fournisseur_prix:Number(wrap.querySelector('[name="besoin_fournisseur_prix[]"]')?.value||0),
                proofAttached:d.globalProofAttached,
                proofName:proof?.files?.[0]?.name||'',
                proofFile:proof?.files?.[0]||null,
                proofToken
            };
            const touched=line.libelle||line.quantite||line.fournisseur_id||line.fournisseur_prix;
            if(!touched) return;
            if(line.type&&line.categorie&&line.libelle&&line.quantite>0&&line.fournisseur_prix>0) completed.push(line);
            else if(!partial){
                partial=line;
                partialStep=!line.type?'line_type':!line.categorie?'line_category':!line.libelle?'line_label':!(line.quantite>0)?'line_qty':'line_price';
            }
        });
        state.data=d; state.lines=completed; state.current=partial||{}; state.messages=[]; state.resumeStep=''; state.editingAnswer=false;
        state.proofIndex=completed.findIndex(line=>!line.proofAttached);
        if(!d.serviceId) state.step='service';
        else if(!d.title) state.step='title';
        else if(!d.globalSupplierId) state.step='global_supplier';
        else if(!d.globalProofAttached) state.step='global_proof';
        else if(partial) state.step=partialStep;
        else if(!completed.length) state.step='method';
        else if(!d.budget) state.step='budget';
        else if(!d.chantierId) state.step='chantier';
        else if(d.budget==='yes'&&!d.lineDevisId) state.step='line_devis';
        else if(d.budget==='yes'&&!d.debourseId) state.step='debourse';
        else if(d.budget==='yes'&&!d.ligneDebourseId) state.step='ligne_debourse';
        else if(d.budget==='yes'&&!d.payment) state.step='payment';
        else if(d.budget==='no'&&!d.proposedLineId&&!d.proposedLineLabel) state.step='proposed_line';
        else if(d.budget==='no'&&!d.proposedTitle) state.step='proposed_title';
        else if(!d.start) state.step='start';
        else if(!d.end) state.step='end';
        else if(!d.delivery) state.step='delivery';
        else if(!d.frequency) state.step='frequency';
        else state.step='review';
        ask(questionForStep(state.step));
        persist();
        return true;
    }
    function prepareProposedLines() {
        const container = document.getElementById('proposedDebourseLines');
        if (!container) return;
        container.querySelectorAll('button .fa-trash')?.forEach(icon => icon.closest('button')?.click());
        state.lines.forEach(line => {
            document.getElementById('addProposedDebourseLine')?.click();
            const row = container.lastElementChild;
            if (!row) return;
            const values = {
                'proposed_line_designation[]': line.libelle,
                'proposed_line_category[]': line.categorie,
                'proposed_line_amount[]': Number(line.quantite) * Number(line.fournisseur_prix),
            };
            Object.entries(values).forEach(([name, value]) => { const el = row.querySelector(`[name="${name}"]`); if (el) { el.value = value; el.dispatchEvent(new Event('input', { bubbles:true })); } });
        });
    }

    function continueAfterLines() {
        if(state.data.budget==='yes'){
            setValue('draft_fd_montant_fiche',total());
            setValue('draft_fd_details_demande',state.data.title);
        } else if(state.data.budget==='no'&&state.data.start&&state.data.end) {
            prepareProposedLines();
        }
        if(state.editingQuote&&state.resumeStep){
            state.editingQuote=false;
            ask('Le devis est modifié. Je te propose de poursuivre exactement là où nous étions.');
            resumeConversation();
            return;
        }
        setStep('budget');
    }
    function startProofs() {
        state.proofIndex = state.lines.findIndex(line => !line.proofAttached);
        if (state.proofIndex < 0) continueAfterLines(); else setStep('proof');
    }
    function nextManualLine() { state.current = {}; setStep('line_type'); }
    function afterPlanning() { setStep('delivery'); }
    function renderStep() {
        controls.innerHTML = '';
        const d = state.data;
        switch (state.step) {
            case 'service': {
                if (!state.messages.length) ask('Bonjour 👋 Je vais t’aider à préparer ta FEB tranquillement, une question à la fois. Je connais déjà ton identité, donc commençons directement.\n\nQuel service va bénéficier de cette demande ?');
                const options = selectOptions(field('departement_id'));
                searchControl(options, 'Écris le nom du service…', (id, label) => { d.serviceId=id; d.serviceLabel=label; setValue('departement_id', id); answer(label); ask('Très bien. Quel est le titre du devis ? Il est généralement écrit tout en haut du document.'); setStep('title'); });
                break;
            }
            case 'title': inputControl('Ex. Fourniture et pose du réseau électrique', value => { if (value.length < 3) return fail('Donne-moi un titre un peu plus précis, s’il te plaît.'); d.title=value; setValue('feb_quote_title', value); if(d.budget==='yes')setValue('draft_fd_details_demande',value); answer(value); ask('Quel est le fournisseur global de cette FEB ?'); setStep('global_supplier'); }); break;
            case 'global_supplier': searchControl(suppliers.map(s=>({id:s.id,disabled:Boolean(s.blocked),label:(s.name||'Fournisseur')+(s.code?' — '+s.code:'')+(s.blocked?' — INDISPONIBLE':'')})),'Écris le fournisseur…',(id,label)=>{const supplier=suppliers.find(s=>String(s.id)===String(id));if(!supplier||supplier.blocked)return fail('Sélectionne un fournisseur validé et disponible.');d.globalSupplierId=Number(id);d.globalSupplierLabel=supplier.name||label;setSelect(document.getElementById('febGlobalSupplier'),id);answer(label);ask('Ajoute maintenant le devis ou justificatif global de la FEB.');setStep('global_proof');}); break;
            case 'global_proof': fileControl('.pdf,.jpg,.jpeg,.png,.webp','Justificatif global',file=>{if(!file)return fail('Choisis une image ou un PDF.');const input=field('feb_global_justificatif');if(!input)return fail('Le champ du justificatif global est indisponible.');try{const transfer=new DataTransfer();transfer.items.add(file);input.files=transfer.files;d.globalProofAttached=true;answer(file.name);ask('Comment veux-tu me donner les lignes du devis ?');setStep('method');}catch(error){fail('Impossible de rattacher ce fichier. Vérifie-le dans le formulaire classique.');}}); break;
            case 'method': choose([{value:'csv',label:'Importer un CSV'},{value:'manual',label:'Me laisser guider'}], (value,label) => { d.method=value; answer(label); if(value==='csv'){ask('Remplis le modèle, enregistre-le en CSV puis donne-le-moi.');setStep('csv');}else{ask('D’accord. Ton premier besoin est-il un bien ou un service ?');nextManualLine();} }); break;
            case 'csv': fileControl('.csv','Choisir le fichier CSV', async file => {
                if (!file || !String(file.name).toLowerCase().endsWith('.csv')) return fail('Ton fichier n’est pas un CSV. Enregistre-le au format CSV puis donne-le-moi.');
                const body=new FormData(); body.append('quote_file',file); controls.innerHTML='<div class="feb-ai-hint"><i class="fas fa-spinner fa-spin mr-2"></i>Je vérifie ton fichier…</div>';
                try{const response=await fetch(config.csv_import_url,{method:'POST',body,headers:{Accept:'application/json'}});const json=await response.json();if(!json.ok)throw new Error(json.error||'Import impossible');state.lines=json.lines||[];state.lines.forEach(line=>{line.fournisseur_id=d.globalSupplierId;line.fournisseur_nom=d.globalSupplierLabel;});syncAllLines();answer(file.name);ask(`Parfait, j’ai trouvé ${state.lines.length} besoin(s), pour un total de ${money(total())}.`);continueAfterLines();}catch(error){fail(error.message||'Je n’ai pas pu lire ce CSV.');renderStep();}
            }, `<a class="feb-ai-choice inline-block mb-3 no-underline" href="${esc(config.csv_template_url)}"><i class="fas fa-download mr-2"></i>Télécharger le modèle CSV</a>`); break;
            case 'line_type': choose([{value:'Bien',label:'Un bien'},{value:'Service',label:'Un service'}],(value,label)=>{state.current.type=value;answer(label);ask('Dans quelle catégorie se trouve ce besoin ?');setStep('line_category');}); break;
            case 'line_category': {
                const categorySelect=form.querySelector('select[name="besoin_categorie[]"]');
                const categories=categorySelect
                    ? selectOptions(categorySelect).map(option=>({value:option.id,label:option.label}))
                    : ['RH','Logistique','HSE','ACHAT','Bureau'].map(value=>({value,label:value}));
                choose(categories,(value,label)=>{state.current.categorie=value;answer(label);ask('Décris précisément ce dont tu as besoin.');setStep('line_label');});
                break;
            }
            case 'line_label': inputControl('Ex. Bottes de sécurité en cuir',value=>{if(value.length<3)return fail('Décris le besoin avec quelques mots de plus.');state.current.libelle=value;answer(value);ask(`Quelle quantité faut-il pour « ${value} » ?`);setStep('line_qty');}); break;
            case 'line_qty': inputControl('Quantité',value=>{const qty=Number(String(value).replace(',','.'));if(!(qty>0)||!Number.isInteger(qty))return fail('La quantité doit être un nombre entier supérieur à zéro.');state.current.quantite=qty;state.current.fournisseur_id=d.globalSupplierId;state.current.fournisseur_nom=d.globalSupplierLabel;answer(String(qty));ask('Quel est le prix unitaire estimé en FCFA ?');setStep('line_price');},'number'); break;
            case 'line_supplier': searchControl(suppliers.map(s=>({id:s.id,disabled:Boolean(s.blocked),label:(s.name||'Fournisseur')+(s.code?' — '+s.code:'')+(s.blocked?' — INDISPONIBLE : '+(s.block_reason||'justificatifs à fournir')+(s.block_fiches?' ('+s.block_fiches+')':''): '')})),'Écris le fournisseur…',(id,label)=>{const supplier=suppliers.find(s=>String(s.id)===String(id));if(supplier?.blocked){fail('Ce fournisseur doit d’abord fournir ses justificatifs de paiement et sa facture/FNE.');return;}state.current.fournisseur_id=Number(id);state.current.fournisseur_nom=supplier?.name||label;state.current.fournisseur_tel=supplier?.phone||'';answer(label);ask('Quel est son prix unitaire en FCFA ?');setStep('line_price');}); break;
            case 'line_price': inputControl('Prix unitaire',value=>{const price=Number(String(value).replace(/\s/g,'').replace(',','.'));if(!(price>0))return fail('Le prix unitaire doit être supérieur à zéro.');state.current.fournisseur_prix=price;state.lines.push({...state.current});addClassicLine(state.lines[state.lines.length-1]);setValue('montant',total());if(d.budget==='yes')setValue('draft_fd_montant_fiche',total());answer(money(price));ask(`Le total de cette ligne est ${money(price*state.current.quantite)}. Veux-tu ajouter un autre besoin ?`);setStep('add_more');},'number'); break;
            case 'proof': {
                const line=state.lines[state.proofIndex]; if(!line){continueAfterLines();return;}
                fileControl('.pdf,.jpg,.jpeg,.png,.webp',`Justificatif pour « ${line.libelle} »`,async file=>{
                    if(!file)return fail('Choisis d’abord une image ou un PDF.');
                    controls.innerHTML='<div class="feb-ai-hint"><i class="fas fa-spinner fa-spin mr-2"></i>Je vérifie et je conserve le justificatif…</div>';
                    try{
                        if(!await attachProof(state.proofIndex,file))throw new Error('Ce fichier ne convient pas. Utilise un PDF, JPG, PNG ou WebP de moins de 10 Mo.');
                        answer(file.name);
                        const next=state.lines.findIndex((item,index)=>index>state.proofIndex&&!item.proofAttached);
                        if(next>=0){state.proofIndex=next;ask(`Merci. Le fichier est accepté et conservé. Donne-moi maintenant le justificatif de « ${state.lines[next].libelle} ».`);renderStep();}
                        else if(d.method==='manual'){ask('Le justificatif est accepté et conservé. Veux-tu ajouter un autre besoin ?');setStep('add_more');}
                        else{continueAfterLines();}
                    }catch(error){
                        ask(`Ton fichier n’est pas en cause si le serveur indique un problème de stockage.\n\n${error.message||'Le justificatif n’a pas pu être conservé.'}`);
                        renderStep();
                    }
                }); break;
            }
            case 'add_more': choose([{value:'yes',label:'Oui, ajouter'},{value:'no',label:'Non, continuer'}],(value,label)=>{answer(label);if(value==='yes'){ask('Très bien. Est-ce un bien ou un service ?');nextManualLine();}else continueAfterLines();}); break;
            case 'budget': askOnce('budget','La dépense est-elle déjà prévue dans un déboursé ?'); choose([{value:'yes',label:'Oui, elle est prévue'},{value:'no',label:'Non, nouveau besoin'}],(value,label)=>{d.budget=value;setRadio('budget_planned',value);answer(label);ask('Sur quel chantier porte cette demande ?');setStep('chantier');}); break;
            case 'chantier': searchControl(selectOptions(document.getElementById('draftFdChantier')),'Écris le code ou le nom du chantier…',(id,label)=>{d.chantierId=id;d.chantierLabel=label;setSelect(document.getElementById('draftFdChantier'),id);answer(label);if(d.budget==='yes'){ask('À quelle ligne de devis faut-il rattacher la demande ?');setStep('line_devis');}else{ask('Choisis une ligne de devis existante, ou indique que tu veux en proposer une nouvelle.');setStep('proposed_line');}}); break;
            case 'line_devis': searchControl(selectOptions(document.getElementById('draftFdLigneDevis')),'Écris la ligne de devis…',(id,label)=>{d.lineDevisId=id;d.lineDevisLabel=label;setSelect(document.getElementById('draftFdLigneDevis'),id);answer(label);ask('Quel déboursé correspond à cette demande ?');setStep('debourse');}); break;
            case 'debourse': searchControl(selectOptions(document.getElementById('draftFdDebourse')),'Écris le déboursé…',(id,label)=>{d.debourseId=id;d.debourseLabel=label;setSelect(document.getElementById('draftFdDebourse'),id);answer(label);ask('Et quelle ligne de déboursé ?');setStep('ligne_debourse');}); break;
            case 'ligne_debourse': searchControl(selectOptions(document.getElementById('draftFdLigne')),'Écris la ligne de déboursé…',(id,label)=>{d.ligneDebourseId=id;d.ligneDebourseLabel=label;setSelect(document.getElementById('draftFdLigne'),id);answer(label);ask('Quel mode de paiement faut-il prévoir ?');setStep('payment');}); break;
            case 'fd_supplier': searchControl(selectOptions(document.getElementById('draftFdFournisseur')),'Écris le fournisseur…',(id,label)=>{d.fdSupplierId=id;d.fdSupplierLabel=label;setSelect(document.getElementById('draftFdFournisseur'),id);setValue('draft_fd_montant_fiche',total());setValue('draft_fd_details_demande',d.title);answer(label);ask('Quel mode de paiement faut-il prévoir ?');setStep('payment');}); break;
            case 'payment': paymentChoices((value,label)=>{d.payment=value;setSelect(document.getElementById('draftFdModePaiement'),value);answer(label);ask('À quelle date l’action doit-elle commencer ?');setStep('start');}); break;
            case 'proposed_line': {
                const options=selectOptions(document.getElementById('proposedLigneDevis'));options.unshift({id:'new',label:'Proposer une nouvelle ligne de devis'});
                searchControl(options,'Écris ou choisis une ligne…',(id,label)=>{answer(label);if(id==='new'){ask('Quel libellé proposes-tu pour cette nouvelle ligne de devis ?');setStep('proposed_line_label');}else{d.proposedLineId=id;setSelect(document.getElementById('proposedLigneDevis'),id);ask('Quel titre proposes-tu pour le nouveau déboursé ?');setStep('proposed_title');}});
                break;
            }
            case 'proposed_line_label': inputControl('Ex. Travaux complémentaires',value=>{if(value.length<3)return fail('Donne-moi un libellé plus précis.');d.proposedLineLabel=value;setValue('proposed_ligne_devis_label',value);answer(value);ask('Quel titre proposes-tu pour le nouveau déboursé ?');setStep('proposed_title');}); break;
            case 'proposed_title': inputControl('Titre du déboursé proposé',value=>{if(value.length<3)return fail('Donne-moi un titre plus précis.');d.proposedTitle=value;setValue('proposed_debourse_title',value);answer(value);ask('À quelle date l’action doit-elle commencer ?');setStep('start');}); break;
            case 'start': dateQuestion('start','Date de début',value=>{d.start=value;setValue('planned_start_date',value);answer(value);ask('À quelle date doit-elle se terminer ?');setStep('end');}); break;
            case 'end': dateQuestion('end','Date de fin',value=>{if(d.start&&value<d.start)return fail('La fin ne peut pas être avant le début.');d.end=value;setValue('planned_end_date',value);if(d.budget==='no')prepareProposedLines();answer(value);afterPlanning();}); break;
            case 'delivery': askOnce('delivery','Quelle est la date de livraison souhaitée ?'); dateQuestion('delivery','Date de livraison',value=>{d.delivery=value;setValue('date_livraison',value);answer(value);ask('À quelle fréquence ce besoin reviendra-t-il ?');setStep('frequency');}); break;
            case 'frequency': choose([{value:'unique',label:'Une seule fois'},{value:'hebdomadaire',label:'Chaque semaine'},{value:'mensuelle',label:'Chaque mois'},{value:'trimestrielle',label:'Chaque trimestre'},{value:'semestrielle',label:'Chaque semestre'},{value:'annuelle',label:'Chaque année'}],(value,label)=>{d.frequency=value;setValue('frequence',value);answer(label);ask('Voici le récapitulatif avant l’envoi.');setStep('review');}); break;
            case 'description':
            case 'impact':
            case 'review': renderReview(); break;
        }
        refreshLiveCheck();
        persist();
        updateValidity();
    }
    function askOnce(key,text){if(!state.data['_asked_'+key]){state.data['_asked_'+key]=true;ask(text);}}
    function dateQuestion(key,placeholder,handler){inputControl(placeholder,value=>{if(!/^\d{4}-\d{2}-\d{2}$/.test(value))return fail('Choisis une date valide, s’il te plaît.');handler(value);},'date');}
    function renderReview() {
        const d=state.data;
        controls.innerHTML=`<div class="feb-ai-review">
          <div class="feb-ai-review-item"><strong>Service bénéficiaire</strong><span>${esc(d.serviceLabel)}</span></div>
          <div class="feb-ai-review-item"><strong>Devis</strong><span>${esc(d.title)} · ${state.lines.length} besoin(s) · ${esc(money(total()))}</span></div>
          <div class="feb-ai-review-item"><strong>Budget</strong><span>${d.budget==='yes'?'Prévue au déboursé':'Non prévue au déboursé'} · ${esc(d.chantierLabel)}</span></div>
          <div class="feb-ai-review-item"><strong>Planning</strong><span>${esc(d.start)} → ${esc(d.end)} · livraison ${esc(d.delivery)}</span></div>
          <div class="feb-ai-choices"><button type="button" class="feb-ai-choice" id="febAiSubmit"><i class="fas fa-paper-plane mr-2"></i>Soumettre ma FEB</button><button type="button" class="feb-ai-choice" id="febAiClassic">Vérifier dans le formulaire classique</button></div>
        </div>`;
        controls.querySelector('#febAiClassic').onclick=exitToClassic;
        controls.querySelector('#febAiSubmit').onclick=submit;
        updateValidity();
    }
    function validationErrors() {
        const d=state.data, errors=[];
        if(!Number(d.serviceId)) errors.push('Service bénéficiaire');
        if(String(d.title||'').trim().length<3) errors.push('Titre du devis');
        if(!state.lines.length) errors.push('Au moins un besoin');
        state.lines.forEach((line,index)=>{
            if(!['Bien','Service'].includes(line.type)) errors.push(`Type de la ligne ${index+1}`);
            if(!String(line.categorie||'').trim()) errors.push(`Catégorie de la ligne ${index+1}`);
            if(!String(line.libelle||'').trim() || !(Number(line.quantite)>0) || !Number.isInteger(Number(line.quantite))) errors.push(`Contenu de la ligne ${index+1}`);
            if(!(Number(line.fournisseur_prix)>0)) errors.push(`Prix de la ligne ${index+1}`);
        });
        if(!(Number(d.globalSupplierId)>0)||!suppliers.some(s=>Number(s.id)===Number(d.globalSupplierId)&&!s.blocked))errors.push('Fournisseur global validé et disponible');
        if(!d.globalProofAttached&&!field('feb_global_justificatif')?.files?.length)errors.push('Justificatif global');
        if(!['yes','no'].includes(d.budget)) errors.push('Situation budgétaire');
        if(!Number(d.chantierId)) errors.push('Chantier');
        if(d.budget==='yes') {
            if(!Number(d.lineDevisId)) errors.push('Ligne de devis');
            if(!Number(d.debourseId)) errors.push('Déboursé');
            if(!Number(d.ligneDebourseId)) errors.push('Ligne de déboursé');
            if(!d.payment) errors.push('Mode de paiement');
        }
        if(d.budget==='no' && !Number(d.proposedLineId) && !String(d.proposedLineLabel||'').trim()) errors.push('Ligne de devis proposée');
        if(d.budget==='no' && !String(d.proposedTitle||'').trim()) errors.push('Titre du déboursé proposé');
        if(!/^\d{4}-\d{2}-\d{2}$/.test(d.start||'')) errors.push('Début prévu');
        if(!/^\d{4}-\d{2}-\d{2}$/.test(d.end||'') || (d.start&&d.end<d.start)) errors.push('Fin prévue');
        if(!/^\d{4}-\d{2}-\d{2}$/.test(d.delivery||'')) errors.push('Date de livraison');
        if(!['unique','hebdomadaire','mensuelle','trimestrielle','semestrielle','annuelle'].includes(d.frequency)) errors.push('Fréquence');
        const expectedFields = {
            departement_id:d.serviceId,
            feb_quote_title:d.title,
            draft_fd_chantier_id:d.chantierId,
            planned_start_date:d.start,
            planned_end_date:d.end,
            date_livraison:d.delivery,
            frequence:d.frequency
        };
        Object.entries(expectedFields).forEach(([name,value])=>{
            if(String(field(name)?.value||'').trim()!==String(value||'').trim()) errors.push(`Synchronisation ${name}`);
        });
        const budgetValue=form.querySelector('[name="budget_planned"]:checked')?.value||'';
        if(budgetValue!==String(d.budget||'')) errors.push('Synchronisation budget');
        const exactSelects=d.budget==='yes'?{
            feb_global_supplier_id:d.globalSupplierId,
            draft_fd_ligne_devis_id:d.lineDevisId,
            draft_fd_debourse_id:d.debourseId,
            draft_fd_ligne_debourse_id:d.ligneDebourseId,
            draft_fd_mode_paiement:d.payment
        }:{
            proposed_ligne_devis_id:d.proposedLineId,
            proposed_ligne_devis_label:d.proposedLineLabel,
            proposed_debourse_title:d.proposedTitle
        };
        Object.entries(exactSelects).forEach(([name,value])=>{
            if(String(field(name)?.value||'').trim()!==String(value||'').trim()) errors.push(`Synchronisation ${name}`);
        });
        const lineWraps=Array.from(document.querySelectorAll('#febBesoinContainer > div'));
        if(lineWraps.length!==state.lines.length) errors.push('Nombre de lignes du devis');
        state.lines.forEach((line,index)=>{
            const wrap=lineWraps[index];
            if(!wrap){errors.push(`Ligne classique ${index+1}`);return;}
            const comparisons=[
                ['type',wrap.querySelector('[name="besoin_type[]"]')?.value,line.type],
                ['catégorie',wrap.querySelector('[name="besoin_categorie[]"]')?.value,line.categorie],
                ['libellé',wrap.querySelector('[name="besoin_libelle[]"]')?.value,line.libelle],
                ['quantité',Number(wrap.querySelector('[name="besoin_quantite[]"]')?.value||0),Number(line.quantite||0)],
                ['prix',Number(wrap.querySelector('[name="besoin_fournisseur_prix[]"]')?.value||0),Number(line.fournisseur_prix||0)]
            ];
            comparisons.forEach(([label,actual,expected])=>{
                if(String(actual).trim()!==String(expected).trim()) errors.push(`${label} de la ligne ${index+1}`);
            });
        });
        const submittedTotal=Number(String(field('montant')?.value||0).replace(',','.'));
        if(Math.abs(submittedTotal-total())>.01) errors.push('Total du devis');
        if(d.budget==='yes'){
            if(Math.abs(Number(field('draft_fd_montant_fiche')?.value||0)-total())>.01) errors.push('Montant de la fiche de décaissement');
            if(String(field('draft_fd_details_demande')?.value||'').trim()!==String(d.title||'').trim()) errors.push('Détails de la fiche de décaissement');
        }
        if(d.budget==='no'&&d.start&&d.end){
            const proposalRows=Array.from(document.querySelectorAll('#proposedDebourseLines > *'));
            if(proposalRows.length!==state.lines.length) errors.push('Lignes du déboursé proposé');
            state.lines.forEach((line,index)=>{
                const row=proposalRows[index];
                if(!row)return;
                const expectedAmount=Number(line.quantite||0)*Number(line.fournisseur_prix||0);
                if(String(row.querySelector('[name="proposed_line_designation[]"]')?.value||'').trim()!==String(line.libelle||'').trim()) errors.push(`Désignation proposée ${index+1}`);
                if(Math.abs(Number(row.querySelector('[name="proposed_line_amount[]"]')?.value||0)-expectedAmount)>.01) errors.push(`Montant proposé ${index+1}`);
            });
        }
        return [...new Set(errors)];
    }
    function updateValidity() {
        const errors=validationErrors();
        let badge=overlay.querySelector('#febAiValidity');
        if(!badge){
            badge=document.createElement('div');badge.id='febAiValidity';badge.className='feb-ai-validity';
            overlay.querySelector('.feb-ai-brand')?.appendChild(badge);
        }
        badge.classList.toggle('is-valid',!errors.length);
        badge.innerHTML=errors.length?`<i class="fas fa-shield-halved"></i> ${errors.length} élément(s) à compléter`:'<i class="fas fa-circle-check"></i> Données FEB conformes';
        badge.title=errors.length?'Manque : '+errors.join(', '):'Toutes les données attendues pour la soumission sont présentes.';
        return errors;
    }
    function correctionStep(error) {
        const text=normalize(error);
        if(text.includes('justificatif global')||text.includes('devis invalide')) return 'global_proof';
        if(text.includes('service beneficiaire')||text.includes('departement')) return 'service';
        if(text.includes('titre du devis')) return 'title';
        if(text.includes('fournisseur global')) return 'global_supplier';
        if(text.includes('besoin')||text.includes('ligne du devis')||text.includes('quantite')||text.includes('prix')) return 'method';
        if(text.includes('situation budgetaire')||text.includes('prevue au debourse')) return 'budget';
        if(text.includes('chantier')) return 'chantier';
        if(text.includes('ligne de devis')) return state.data.budget==='yes'?'line_devis':'proposed_line';
        if(text.includes('ligne de debourse')) return 'ligne_debourse';
        if(text.includes('debourse')) return state.data.budget==='yes'?'debourse':'proposed_title';
        if(text.includes('fournisseur du decaissement')||text.includes('fournisseur de la fiche')) return 'global_supplier';
        if(text.includes('mode de paiement')) return 'payment';
        if(text.includes('date de debut')||text.includes('debut prevu')) return 'start';
        if(text.includes('date de fin')||text.includes('fin prevue')) return 'end';
        if(text.includes('livraison')) return 'delivery';
        if(text.includes('frequence')) return 'frequency';
        if(text.includes('description')||text.includes('details')) return 'description';
        if(text.includes('impact')) return 'impact';
        return 'review';
    }
    function guideCorrection(errors, serverSide=false) {
        locked=false;
        const list=[...new Set((errors||[]).map(String).filter(Boolean))];
        if(!list.length){state.step='review';renderStep();return;}
        const first=list[0];
        const previousStep=state.step;
        const lineMatch=first.match(/ligne\s+(\d+)/i);
        if((normalize(first).includes('justificatif')||normalize(first).includes('devis'))&&lineMatch){
            state.proofIndex=Math.max(0,Number(lineMatch[1])-1);
        }
        state.step=correctionStep(first);
        if(previousStep==='review'&&!['method','line_type','line_category','line_label','line_qty','line_supplier','line_price'].includes(state.step)){
            state.resumeStep='review';
            state.editingAnswer=true;
        }
        ask(`${serverSide?'La soumission a été refusée':'La FEB a besoin d’une correction'}.\n\nJe reste avec toi dans l’assistant. Corrigeons d’abord ceci : ${first}`);
        renderStep();
        const remaining=list.slice(1);
        const panel=`<div class="feb-ai-correction"><strong>${serverSide?'Réponse du serveur':'Contrôle avant envoi'}</strong><div>${esc(first)}</div>${remaining.length?`<details><summary>Voir les ${remaining.length} autre(s) correction(s)</summary><ul>${remaining.map(item=>`<li>${esc(item)}</li>`).join('')}</ul></details>`:''}</div>`;
        controls.insertAdjacentHTML('afterbegin',panel);
        persist();
    }
    async function submit() {
        if(locked)return;locked=true;
        syncCommonFields();
        const invalid=updateValidity();
        if(invalid.length){guideCorrection(invalid);return;}
        document.getElementById('febAssistantMode').value='1';
        setValue('montant',total());
        controls.innerHTML='<div class="feb-ai-hint"><i class="fas fa-spinner fa-spin mr-2"></i>Je transmets ta FEB en toute sécurité…</div>';
        form.requestSubmit();
        setTimeout(()=>{locked=false;},8000);
    }
    function exitToClassic(){overlay.classList.remove('is-open');document.body.style.overflow='';document.getElementById('febAssistantMode').value='';syncCommonFields();window.scrollTo({top:form.offsetTop-100,behavior:'smooth'});}
    function open() {
        overlay.classList.add('is-open');document.body.style.overflow='hidden';
        const fresh=new URLSearchParams(location.search).get('fresh')==='1';
        const serverErrors=Array.isArray(config.server_errors)?config.server_errors.filter(Boolean):[];
        if(serverErrors.length){
            if(classicFormHasProgress()) hydrateFromClassicForm();
            state.data._serverErrorsShown=true;
            guideCorrection(serverErrors,true);
            return;
        }
        if(!fresh && classicFormHasProgress()){
            hydrateFromClassicForm();
            renderStep();
            return;
        }
        if(!state.messages.length && !fresh && hasRestorableDraft()){
            showDraftChoice();
            return;
        }
        if(fresh){
            const cleanUrl=new URL(location.href);
            cleanUrl.searchParams.delete('fresh');
            history.replaceState(null,'',cleanUrl.toString());
        }
        if(!state.messages.length) ask('Bonjour 👋 Je vais t’aider à préparer ta FEB tranquillement, une question à la fois. Je connais déjà ton identité, donc commençons directement.\n\nQuel service va bénéficier de cette demande ?');
        else replay();
        renderStep();
    }
    function showSuccess(id){
        overlay.classList.add('is-open');document.body.style.overflow='hidden';clearDraft();
        overlay.querySelector('.feb-ai-shell').innerHTML=`<div class="feb-ai-success"><div class="feb-ai-success-icon"><i class="fas fa-check"></i></div><p class="text-xs font-black uppercase tracking-[.18em] text-emerald-700">FEB envoyée</p><h1 class="mt-2 text-3xl font-black text-slate-950">FEB #${Number(id)}</h1><p class="mt-3 text-slate-600">Votre demande a été transmise pour validation.</p><div class="mt-6 flex flex-wrap justify-center gap-3"><a class="feb-ai-choice no-underline" target="_blank" href="${esc(config.pdf_url+id)}"><i class="fas fa-file-pdf mr-2"></i>Voir ma FEB</a><a class="feb-ai-choice no-underline" href="mes_demandes_employe.php">Retour à mes demandes</a></div></div>`;
    }
    launcher.addEventListener('click',open);
    overlay.querySelector('#febAiExit').addEventListener('click',exitToClassic);
    overlay.querySelector('#febAiHistory').addEventListener('click',openHistory);
    if(Number(config.success_id||0)>0)showSuccess(config.success_id);
    else if(new URLSearchParams(location.search).get('assistant')==='1'||config.auto_open)open();
})();
