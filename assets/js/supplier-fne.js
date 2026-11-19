(() => {
  const base = new URL('../../api/', document.currentScript.src);
  const eu = new Set('AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE'.split(' '));
  const defaultMethod = country => country === 'CI' ? 'fne' : country === 'FR' ? 'fr' : eu.has(country) ? 'vies' : 'manual';
  const spec = (country, method) => {
    if (method === 'exception') return {field:null,label:'Dérogation autorisée',placeholder:''};
    if (country === 'CI') return {field:'ncc',label:'NCC — recherche FNE',placeholder:'Ex. 2158807C'};
    if (method === 'fr') return {field:'siret',label:'SIRET ou SIREN — registre français',placeholder:'14 chiffres (SIRET) ou 9 chiffres (SIREN)'};
    if (method === 'vies') return {field:'vat_number',label:'TVA européenne — recherche VIES',placeholder:'Préfixe '+(country==='GR'?'EL':country)+' suivi du numéro de TVA'};
    if (method === 'sec') return {field:'registration_number',label:'CIK — registre SEC américain',placeholder:'CIK de 1 à 10 chiffres (pas le numéro EIN)'};
    return {field:'registration_number',label:country==='US'?'Identifiant américain — contrôle documentaire':'Immatriculation locale — contrôle documentaire',placeholder:country==='US'?'Ex. EIN : 12-3456789':'Numéro du registre commercial ou fiscal local'};
  };
  window.fineaSupplierIdentitySpec = spec;
  function boot() {
    document.querySelectorAll('form').forEach(form => {
      const ncc=form.querySelector('[name=ncc]'), name=form.querySelector('[name=nom_commercial]');
      if (!ncc || !name || form.dataset.fneBound==='1') return;
      form.dataset.fneBound='1';
      const country=form.querySelector('[name=fiscal_country]'), method=form.querySelector('[name=identity_method]');
      const search=form.querySelector('[name=identity_search]'), reason=form.querySelector('[name=exception_reason]');
      const countryCode=()=>country?.value.trim().toUpperCase()||'CI';
      const mode=()=>method?.value||defaultMethod(countryCode());
      const company=()=>(form.querySelector('[name=supplier_type]:checked')?.value||form.querySelector('[name=supplier_type]')?.value||'morale')==='morale';
      const personNumber=form.querySelector('[name=identity_document_number]');
      const personMessage=form.querySelector('[data-person-identity-message]');
      let personRequest=0, personTimer, personChecked='';
      const checkPerson=async()=>{
        if(!personNumber||company())return true;
        const value=personNumber.value.trim(), request=++personRequest;
        personNumber.setCustomValidity('');
        if(!value)return false;
        try{
          const response=await fetch(new URL('verify_person_identity.php',base),{method:'POST',body:new URLSearchParams({number:value}),credentials:'same-origin'});
          const result=await response.json();
          if(request!==personRequest||personNumber.value.trim()!==value)return false;
          if(!response.ok||!result.ok)throw Error(result.error||'Contrôle des pièces indisponible.');
          personNumber.setCustomValidity(result.blocked?result.message:'');
          if(personMessage)personMessage.textContent=result.blocked?result.message:'Pièce contrôlée : aucun rattachement au personnel trouvé.';
          personChecked=result.blocked?'':value;
          return !result.blocked;
        }catch(error){if(request===personRequest){personNumber.setCustomValidity(error.message);if(personMessage)personMessage.textContent=error.message;}return false;}
      };
      personNumber?.addEventListener('input',()=>{personChecked='';personNumber.setCustomValidity('');clearTimeout(personTimer);personTimer=setTimeout(checkPerson,400);});
      form.addEventListener('submit',async event=>{
        if(company()||!personNumber||personChecked===personNumber.value.trim())return;
        event.preventDefault();const submitter=event.submitter;
        if(await checkPerson()){if(submitter)form.requestSubmit(submitter);else form.requestSubmit();}else personNumber.reportValidity();
      });
      const fields=[...form.querySelectorAll('input,select,textarea')];
      const originallyRequired=new Map(fields.map(el=>[el,el.required]));
      let box=form.querySelector('[data-supplier-fne]');
      if (!box) {
        box=document.createElement('div');box.className='supplier-fne-box';box.dataset.supplierFne='';
        box.innerHTML='<strong></strong><br><button type="button" data-fne-verify></button><p data-fne-status aria-live="polite"></p>';
        (form.querySelector('[data-business-identity]')||ncc.closest('div')).after(box);
      }
      const button=box.querySelector('[data-fne-verify]'), status=box.querySelector('[data-fne-status]');
      if(!button||!status)return;
      box.querySelector('[data-fne-loading]')?.remove();
      let version=0, activeField=null;
      function sync() {
        version++;button.disabled=false;delete box.dataset.state;
        const c=countryCode();
        if(method && mode()!=='exception' && ((c==='CI'&&mode()!=='fne') || (c!=='CI'&&mode()==='fne') || (mode()==='fr'&&c!=='FR') || (mode()==='vies'&&!eu.has(c)) || (mode()==='sec'&&c!=='US')))method.value=defaultMethod(c);
        const m=mode(), exception=m==='exception', settings=spec(c,m), international=c!=='CI';
        if(company()&&personNumber){personRequest++;personChecked='';personNumber.setCustomValidity('');}
        if (method) [...method.options].forEach(option=>{option.disabled=option.value==='exception'?false:option.value==='fne'?international:option.value==='fr'?c!=='FR':option.value==='vies'?!eu.has(c):option.value==='sec'?c!=='US':!international;});
        fields.forEach(el=>{
          const types=el.getAttribute('data-required-on');
          el.required=exception?(!company()&&(el.name==='identity_document_number'||(el.name==='supplier_cni'&&!el.dataset.existingFile))):(types?types.split(',').includes(company()?'morale':'physique'):originallyRequired.get(el));
        });
        if(country)country.required=true;
        name.readOnly=!exception&&company()&&(!international||m==='fr'||m==='sec');
        name.required=exception||company();
        const nameGroup=name.closest('[data-supplier-type-section]');if(nameGroup)nameGroup.style.display=exception||company()?'':'none';
        if(reason)reason.required=exception;
        form.querySelectorAll('[data-exception-only]').forEach(el=>el.hidden=!exception);
        form.querySelectorAll('[data-international-field]').forEach(el=>el.hidden=!company()||!international||exception);
        form.querySelectorAll('[data-fr-field]').forEach(el=>el.hidden=!!search||m!=='fr'||exception);
        form.querySelectorAll('[data-registration-field]').forEach(el=>el.hidden=!company()||!international||m==='fr'||m==='vies'||exception||(!!search&&el.contains(form.querySelector('[name=registration_number]'))));
        form.querySelectorAll('[data-primary-identity]').forEach(el=>el.hidden=exception||!company());
        if(search) {
          const target=settings.field?form.querySelector(`[name=${settings.field}]`):null;
          if(activeField!==settings.field){if(activeField!==null||!search.value)search.value=target?.value||'';activeField=settings.field;}
          form.querySelector('[data-primary-identity-label]').textContent=settings.label;
          search.placeholder=settings.placeholder;search.required=company()&&!exception;
          const vat=form.querySelector('[name=vat_number]');if(vat)vat.closest('label').hidden=exception||!international||m==='vies'||!company();
        }
        [ncc,form.querySelector('[name=rne]'),form.querySelector('[name=is_rne]')].filter(Boolean).forEach(el=>{
          el.disabled=international||exception;el.required=el===ncc&&company()&&!international&&!exception&&!search;
          el.closest('div').hidden=exception||international||!!search||!company();
        });
        const siret=form.querySelector('[name=siret]');if(siret)siret.required=!search&&company()&&m==='fr'&&!exception;
        box.hidden=exception||!company();
        const details=form.querySelector('[data-identity-details]');
        if(details){
          details.querySelectorAll('[data-business-fields] > label').forEach(label=>{if(!company()&&!label.hasAttribute('data-supplier-mode')&&!label.hasAttribute('data-exception-only'))label.hidden=true;});
          details.querySelectorAll('[data-international-field], [data-fr-field], [data-registration-field], [data-primary-identity]').forEach(el=>{if(!company())el.hidden=true;});
          details.querySelectorAll('[data-identity-summary]').forEach(el=>el.hidden=!company());
          if(method)for(const option of method.options){option.dataset.fullLabel=option.dataset.fullLabel||option.textContent;if(!company()&&option.value!=='exception'){option.hidden=option.value!==method.value;option.disabled=option.value!==method.value;option.textContent=option.value===method.value?'Dossier de personne physique':option.dataset.fullLabel;}else{option.hidden=false;option.textContent=option.dataset.fullLabel;}}
        }

        const title=box.querySelector('strong');if(title)title.textContent=international?'Identification internationale':'Identité officielle — FNE / DGI';
        button.textContent=m==='manual'?'Contrôler la saisie documentaire':'Rechercher et remplir la fiche';
        status.textContent=international?'Le contrôle sera renouvelé à l’enregistrement. VIES confirme la TVA ; les justificatifs doivent être validés séparément.':'La FNE fournit la dénomination officielle à partir du NCC.';
        form.querySelectorAll('[data-required-indicator]').forEach(el=>{if(exception)el.classList.add('hidden');});
      }
      function mirror() {const field=spec(countryCode(),mode()).field;if(search&&field){const target=form.querySelector(`[name=${field}]`);if(target)target.value=search.value;}}
      if(method && ![...method.options].some(o=>o.value===method.value&&!o.disabled))method.value=defaultMethod(countryCode());
      if(method && mode()==='fne'&&countryCode()!=='CI')method.value=defaultMethod(countryCode());
      country?.addEventListener('change',()=>{
        if(method&&mode()!=='exception')method.value=defaultMethod(countryCode());
        const type=form.querySelector('[name=registration_type]');if(type&&countryCode()==='US'&&mode()==='manual'&&(!type.value||type.value==='LOCAL'))type.value='EIN';
        sync();
      });
      method?.addEventListener('change',sync);
      form.addEventListener('finea:identity-profile-change',sync);
      form.querySelectorAll('[name=supplier_type]').forEach(el=>el.addEventListener('change',sync));
      form.addEventListener('input',()=>{version++;button.disabled=false;delete box.dataset.state;});
      search?.addEventListener('input',mirror);
      form.addEventListener('submit',mirror);
      button.addEventListener('click',async()=>{
        if(mode()==='exception')return;
        mirror();const request=++version,c=countryCode(),m=mode();button.disabled=true;status.textContent='Consultation du registre…';
        try {
          let response;
          if(c==='CI'||m==='fr') {
            const url=new URL(c==='CI'?'verify_supplier_ncc.php':'verify_supplier_siret.php',base);
            url.searchParams.set(c==='CI'?'ncc':'siret',form.querySelector(c==='CI'?'[name=ncc]':'[name=siret]').value);
            response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'}});
          } else {
            const data=new URLSearchParams();for(const [k,v] of new FormData(form))if(typeof v==='string')data.append(k,v);
            response=await fetch(new URL('verify_business_identity.php',base),{method:'POST',body:data,credentials:'same-origin'});
          }
          const data=await response.json();if(request!==version)return;
          if(!response.ok||!data.ok)throw new Error(data.error||'Vérification impossible.');
          const identity=data.supplier||data.identity;name.value=identity.name;
          name.dispatchEvent(new Event('input',{bubbles:true}));
          if(c!=='CI')for(const [field,value] of Object.entries({adresse:identity.address,ville:identity.city,pays:c})){const el=form.querySelector(`[name=${field}]`);if(el){el.value=value||'';if(field==='adresse')el.dispatchEvent(new Event('change',{bubbles:true}));}}
          if(m==='fr'){form.querySelector('[name=siret]').value=identity.siret;if(search)search.value=identity.siret;}
          if(c==='CI'){ncc.value=identity.ncc;if(search)search.value=identity.ncc;}
          box.dataset.state=identity.identity_status==='documentary_pending'?'pending':'verified';
          status.textContent=identity.name+' — '+(c==='CI'?'NCC confirmé par FNE':m==='fr'?'SIRET confirmé par le registre français':m==='sec'?'CIK confirmé par la SEC':m==='vies'?'TVA confirmée ; identité à valider sur justificatifs':'Saisie documentaire à valider');
        }catch(e){if(request===version){box.dataset.state='error';status.textContent=e instanceof SyntaxError?'Session expirée ou réponse inattendue. Rechargez la page.':e.message+(m==='vies'?' Si le service reste indisponible, choisissez « Validation sur justificatifs » pour faire contrôler le dossier sans confirmer la TVA.':'');}}
        finally{button.disabled=false;}
      });
      sync();
      if(document.readyState!=='complete')document.addEventListener('DOMContentLoaded',sync,{once:true});
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
