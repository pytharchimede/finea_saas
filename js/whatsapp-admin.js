(()=>{
 let editing=false;
 document.querySelectorAll('#wa-create-form,#wa-test-form').forEach(form=>form.addEventListener('input',()=>{editing=true;}));
 setTimeout(()=>{if(!document.hidden&&!editing)window.location.reload();},300000);
 const create=document.getElementById('wa-create-form');
 const values=JSON.parse(document.getElementById('wa-saved-samples')?.textContent||'{}');
 const fill=(body,data)=>body.replace(/\{\{(\d+)\}\}/g,(match,key)=>data[key]||match);
 function samples(){
  document.querySelectorAll('[data-wa-sample]').forEach(input=>{values[input.dataset.waSample]=input.value;});
  const body=create.elements.body.value,url=create.elements.button_url.value;
  const keys=[...new Set([...`${body} ${url}`.matchAll(/\{\{([1-9]\d*)\}\}/g)].map(m=>m[1]))].sort((a,b)=>Number(a)-Number(b)).slice(0,100);
  const container=document.getElementById('wa-samples');container.replaceChildren();
  keys.forEach(key=>{const label=document.createElement('label');label.textContent=`Exemple de la variable {{${key}}}`;const input=document.createElement('input');input.type='text';input.name=`samples[${key}]`;input.dataset.waSample=key;input.maxLength=500;input.required=true;input.value=values[key]||'';input.addEventListener('input',()=>{values[key]=input.value;preview();});label.append(input);container.append(label);});preview();
 }
 function preview(){document.getElementById('wa-create-preview').textContent=fill(create.elements.body.value,values)+(create.elements.button_title.value?'\n\nBouton : '+create.elements.button_title.value+'\n'+fill(create.elements.button_url.value,values):'');}
 if(create){create.elements.body.addEventListener('input',samples);create.elements.button_url.addEventListener('input',samples);create.elements.button_title.addEventListener('input',preview);samples();create.addEventListener('submit',e=>{if(!confirm('Créer ce nouveau modèle et le soumettre à Meta ?'))e.preventDefault();else create.querySelector('button').disabled=true;});}
 const selected=JSON.parse(document.getElementById('wa-selected-data')?.textContent||'null');
 const test=document.getElementById('wa-test-form');
 if(test&&selected){const type=selected.definition.types['twilio/call-to-action']||selected.definition.types['twilio/text'];const render=()=>{const data={};test.querySelectorAll('[data-wa-variable]').forEach(input=>{data[input.dataset.waVariable]=input.value;});document.getElementById('wa-test-preview').textContent=fill(type.body||'',data);};test.addEventListener('input',render);render();test.addEventListener('submit',e=>{if(!confirm('Envoyer réellement ce test WhatsApp au '+test.elements.phone.value+' ?'))e.preventDefault();else test.querySelector('button').disabled=true;});}
 document.getElementById('wa-version')?.addEventListener('click',()=>{const type=selected.definition.types['twilio/call-to-action']||selected.definition.types['twilio/text'];if(!type)return;create.elements.name.value=selected.name.replace(/_\d{8}_\d{6}_[a-f0-9]{6}$/,'').slice(0,100);create.elements.language.value=selected.language;create.elements.category.value=['UTILITY','MARKETING'].includes(selected.category)?selected.category:'UTILITY';create.elements.body.value=type.body||'';create.elements.button_title.value=type.actions?.[0]?.title||'';create.elements.button_url.value=type.actions?.[0]?.url||'';document.getElementById('wa-samples').replaceChildren();Object.assign(values,selected.definition.variables||{});samples();document.getElementById('create').scrollIntoView({behavior:'smooth'});});
 document.getElementById('wa-search')?.addEventListener('input',e=>{const query=e.target.value.toLocaleLowerCase();document.querySelectorAll('#wa-models tr').forEach(row=>{row.hidden=!row.textContent.toLocaleLowerCase().includes(query);});});
})();
