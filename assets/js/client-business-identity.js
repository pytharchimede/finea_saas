(() => {
  const base=new URL('../../api/',document.currentScript.src);
  const eu=new Set('AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE'.split(' '));
  const defaultMethod=c=>c==='CI'?'fne':c==='FR'?'fr':eu.has(c)?'vies':'manual';
  const boot=()=>{
    const form=document.querySelector('#clientForm, #clientEditForm');if(!form)return;
    const country=form.querySelector('[name=fiscal_country]'),method=form.querySelector('[name=identity_method]'),type=form.querySelector('[name=client_type]');
    const ncc=form.querySelector('[name=ncc]'),name=form.querySelector('[name=nom_client]'),button=form.querySelector('#loadClientFne, #verifyNcc'),save=form.querySelector('#saveClient');
    const details=form.querySelector('[data-identity-details]'),host=form.querySelector('[data-business-lookup-host]'),fields=form.querySelector('[data-business-fields]'),personalProof=form.querySelector('[data-personal-client-proof]');
    let state=form.querySelector('#fneState');if(!state){state=document.createElement('p');state.id='fneState';host.append(state);}else host.append(state);
    host.prepend(button);let version=0;
    const set=(field,value)=>{const el=form.querySelector(`[name="${field}"]`);if(el&&value!==undefined&&value!==null&&String(value)!=='')el.value=value;};
    const sync=()=>{
      version++;const c=country.value.toUpperCase(),person=type?.value==='physique',ci=c==='CI'&&!person;
      if(!person && (method.value==='fne'&&c!=='CI'||method.value==='fr'&&c!=='FR'||method.value==='vies'&&!eu.has(c)||c==='CI'&&method.value!=='fne'))method.value=defaultMethod(c);
      for(const option of method.options){option.disabled=option.value==='fne'?c!=='CI':option.value==='fr'?c!=='FR':option.value==='vies'?!eu.has(c):option.value==='sec'?c!=='US':c==='CI';}
      const fr=!person&&method.value==='fr';
      form.querySelectorAll('[data-ci-company-only]').forEach(el=>el.hidden=!ci);
      ncc.disabled=!ci;ncc.required=ci;
      name.readOnly=!person&&(ci||fr||method.value==='sec');
      const nameLabel=name.closest('.cf-field, .cm-field')?.querySelector('label');if(nameLabel)nameLabel.textContent=person?'Nom et prénom *':'Raison sociale *';
      const proof=form.querySelector('[name=identity_evidence]')?.closest('label'),approval=form.querySelector('[name=documentary_approve]')?.closest('label');
      if(person){if(proof)personalProof.append(proof);if(approval)personalProof.append(approval);}else{if(proof)fields.append(proof);if(approval)details.append(approval);}
      fields.hidden=person;personalProof.hidden=!person;host.hidden=person;button.hidden=person;
      form.querySelectorAll('[data-international-field]').forEach(el=>el.hidden=person?!(el===proof||el===approval):ci);
      form.querySelectorAll('[data-fr-field]').forEach(el=>el.hidden=!fr);
      form.querySelectorAll('[data-registration-field]').forEach(el=>el.hidden=person||ci||fr||method.value==='vies');
      form.querySelectorAll('[data-identity-summary]').forEach(el=>el.hidden=person);
      const evidence=form.querySelector('[name=identity_evidence]');if(evidence)evidence.required=!person&&!ci&&['manual','vies'].includes(method.value);
      const siret=form.querySelector('[name=siret]');if(siret){siret.required=fr;siret.placeholder='14 chiffres (SIRET) ou 9 chiffres (SIREN)';}
      const regType=form.querySelector('[name=registration_type]');if(regType){regType.readOnly=!person&&method.value==='sec';if(method.value==='sec')regType.value='CIK';else if(c==='US'&&method.value==='manual'&&['','LOCAL','CIK'].includes(regType.value))regType.value='EIN';}
      const reg=form.querySelector('[name=registration_number]');if(reg){reg.required=!person&&!ci&&!fr&&method.value!=='vies';reg.placeholder=method.value==='sec'?'CIK de 1 à 10 chiffres':c==='US'?'Ex. EIN : 12-3456789':'Numéro du registre local';}
      const vat=form.querySelector('[name=vat_number]');if(vat){vat.required=!person&&method.value==='vies';vat.placeholder=(c==='GR'?'EL':c)+' suivi du numéro de TVA';}
      ['tax_regime','activity_status','fiscal_activity_label','registered_at','first_registration_at'].forEach(id=>{const el=form.querySelector('#'+id);if(el)el.closest('.cf-field, .cm-field').hidden=!ci;});
      button.textContent=ci?'Rechercher par NCC (FNE)':fr?'Rechercher par SIRET / SIREN':method.value==='vies'?'Vérifier la TVA (VIES)':method.value==='sec'?'Rechercher par CIK (SEC)':'Contrôler les justificatifs';
      button.disabled=false;save.disabled=false;save.textContent='Enregistrer la fiche';
      state.textContent=ci?'Saisissez le NCC puis lancez la recherche pour récupérer la raison sociale.':fr?'Saisissez le SIRET ou SIREN puis lancez la recherche pour récupérer le nom et l’adresse.':method.value==='vies'?'Saisissez la TVA avec son préfixe. Si le registre ne fournit pas le nom et l’adresse, complétez-les et ajoutez le justificatif.':method.value==='sec'?'Saisissez le CIK exact de l’entité. Un EIN ne peut pas être recherché dans la SEC.':'Saisissez la raison sociale, l’adresse, l’immatriculation et la référence du justificatif.';
      state.className='cf-state show cm-state';
    };
    country.addEventListener('change',()=>{method.value=defaultMethod(country.value.toUpperCase());sync();});
    method.addEventListener('change',sync);
    form.addEventListener('finea:identity-profile-change',()=>{const approval=form.querySelector('[name=documentary_approve]');if(approval)approval.checked=false;sync();});
    form.addEventListener('input',()=>{version++;button.disabled=false;});
    form.querySelector('[name=documentary_approve]')?.addEventListener('change',event=>{const evidence=form.querySelector('[name=identity_evidence]');if(evidence)evidence.required=event.target.checked||(type?.value!=='physique'&&country.value!=='CI'&&['manual','vies'].includes(method.value));});
    button.addEventListener('click',async()=>{
      const request=++version;button.disabled=true;
      try{
        const ci=country.value==='CI'&&type?.value!=='physique';let response;
        if(ci){const url=new URL('verify_client_ncc.php',base);url.searchParams.set('ncc',ncc.value);url.searchParams.set('except',form.querySelector('[name=id_client]')?.value||'0');response=await fetch(url,{credentials:'same-origin'});}
        else response=await fetch(new URL('verify_business_identity.php',base),{method:'POST',body:new FormData(form),credentials:'same-origin'});
        const data=await response.json();if(request!==version)return;if(!response.ok||!data.ok)throw Error(data.error||'Contrôle impossible.');
        const identity=ci?data.client:data.identity;if(ci&&identity.existing_client_id)throw Error('Identifiant déjà rattaché au client #'+identity.existing_client_id+'.');
        set('nom_client',identity.name);set('localisation_client',identity.address);set('commune_client',identity.city);set('telephone_client',identity.phone);set('email_client',identity.email);set('pays_client',country.value);set('siret',identity.siret);
        ['registered_at','first_registration_at'].forEach((id,i)=>{const el=form.querySelector('#'+id),value=identity[i?'firstRegistrationDate':'registeredAt'];if(el)el.value=value?new Date(value).toLocaleDateString('fr-FR'):'';});
        if(ci){const tax=form.querySelector('#tax_regime');if(tax)tax.value=identity.taxRegime||'';const activity=form.querySelector('#activity_status,#fiscal_activity_label');if(activity)activity.value=identity.statusActivityLabel||'';}
        state.textContent=identity.name+' — '+(ci?'identité confirmée par la FNE':identity.identity_status==='registry_verified'?'identité confirmée par le registre':'contrôle effectué ; validation documentaire nécessaire');state.className='cf-state show ok cm-state';
      }catch(error){if(request===version){state.textContent=error.message;state.className='cf-state show error cm-state';}}
      finally{if(request===version)button.disabled=false;}
    });
    sync();
  };
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
