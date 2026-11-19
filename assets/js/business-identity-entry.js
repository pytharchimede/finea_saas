(() => {
  const eu=new Set('AT BE BG HR CY CZ DK EE FI FR DE GR HU IE IT LV LT LU MT NL PL PT RO SK SI ES SE'.split(' '));
  const profileFor=(country,person=false)=>person?'person':country==='CI'?'ci':country==='FR'?'fr':country==='US'?'us':eu.has(country)?'eu':'foreign';
  const boot=()=>document.querySelectorAll('[data-party-entry]').forEach(entry=>{
    if(entry.dataset.bound)return;entry.dataset.bound='1';
    const form=entry.closest('form'),profile=entry.querySelector('[data-business-profile]'),country=entry.querySelector('[data-business-country]'),help=entry.querySelector('[data-entry-help]');
    try{const names=new Intl.DisplayNames(['fr'],{type:'region'});for(const option of country.options)if(option.value)option.textContent=names.of(option.value)+' ('+option.value+')';}catch{}
    let lastContext=country.value+'|'+(form.querySelector('[name=client_type]')?.value||form.querySelector('[name=supplier_type]:checked')?.value||'morale');
    const sync=()=>{
      const person=profile.value==='person';
      const context=country.value+'|'+(person?'physique':'morale');
      if(context!==lastContext){const regType=form.querySelector('[name=registration_type]');if(regType)regType.value='LOCAL';for(const key of ['identity_search','ncc','siret','siren','vat_number','registration_number','identity_evidence']){const el=form.querySelector(`[name="${key}"]`);if(el)el.value='';}if(!person||lastContext.endsWith('|morale'))for(const key of ['nom_client','nom_commercial','localisation_client','commune_client','adresse','ville']){const el=form.querySelector(`[name="${key}"]`);if(el)el.value='';}lastContext=context;}
      const type=form.querySelector('[name=client_type]');if(type)type.value=person?'physique':'morale';
      form.querySelectorAll('[name=supplier_type]').forEach(radio=>{const selected=radio.value===(person?'physique':'morale');if(radio.checked!==selected){radio.checked=selected;radio.dispatchEvent(new Event('change',{bubbles:true}));}const controls=radio.closest('div')?.parentElement;if(controls)controls.dataset.oldTypeControls='1';});
      entry.dataset.pending=profile.value&&country.value?'0':'1';
      help.textContent=!profile.value?'Choisissez d’abord le profil du tiers.':person?'Aucun NCC, SIRET ou numéro de TVA n’est requis pour une personne physique. Les justificatifs sont adaptés à ce profil.':profile.value==='ci'?'Recherche et récupération des informations officielles par NCC via la FNE.':profile.value==='fr'?'Recherche et récupération de la raison sociale et de l’adresse par SIRET ou SIREN.':profile.value==='eu'?'Contrôle de TVA par VIES et récupération du nom et de l’adresse lorsque le registre les fournit.':profile.value==='us'?'Choisissez le CIK pour consulter la SEC, ou un EIN avec validation documentaire.':'Immatriculation locale et validation documentaire ; aucun NCC ivoirien n’est demandé.';
      form.dispatchEvent(new Event('finea:identity-profile-change',{bubbles:true}));
    };
    profile.addEventListener('change',()=>{
      const p=profile.value,c=country.value;
      if(p==='ci')country.value='CI';else if(p==='fr')country.value='FR';else if(p==='us')country.value='US';else if(p==='eu'&&(!eu.has(c)||c==='FR'))country.value='DE';else if(p==='foreign'&&(!c||c==='CI'||c==='FR'||c==='US'||eu.has(c)))country.value='';else if(p==='person'&&!c)country.value='CI';
      country.dispatchEvent(new Event('change',{bubbles:true}));sync();
    });
    country.addEventListener('change',()=>{if(country.value)profile.value=profileFor(country.value,profile.value==='person');sync();});
    sync();
  });
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
