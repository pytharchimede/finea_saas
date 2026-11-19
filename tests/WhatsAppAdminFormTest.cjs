const fs=require('fs');const assert=require('assert');const {JSDOM}=require('jsdom');
const model={name:'finea_demo_20261003_150000_aabbcc',language:'fr',category:'UTILITY',definition:{variables:{1:'DG',2:'125',3:'jeton'},types:{'twilio/call-to-action':{body:'Bonjour {{1}}, point {{2}}. Merci.',actions:[{title:'Signer',url:'https://projet.fidest.ci/test/{{3}}'}]}}}};
const dom=new JSDOM(`<form id="wa-create-form"><input name="name"><select name="language"><option value="fr">FR</option></select><select name="category"><option value="UTILITY">Utility</option></select><textarea name="body">Bonjour {{1}}, merci.</textarea><input name="button_url"><input name="button_title"><div id="wa-samples"></div><p id="wa-create-preview"></p><button>Créer</button></form><script id="wa-saved-samples" type="application/json">{"1":"Ulrich"}</script><script id="wa-selected-data" type="application/json">${JSON.stringify(model)}</script><button id="wa-version">Version</button><section id="create"></section><input id="wa-search"><table><tbody id="wa-models"><tr><td>Salaires</td></tr><tr><td>Prêts</td></tr></tbody></table>`,{runScripts:'outside-only'});
dom.window.HTMLElement.prototype.scrollIntoView=()=>{};dom.window.confirm=()=>true;
dom.window.eval(fs.readFileSync('js/whatsapp-admin.js','utf8'));
const d=dom.window.document,f=d.getElementById('wa-create-form');assert.equal(d.querySelector('[data-wa-sample="1"]').value,'Ulrich');assert.equal(d.getElementById('wa-create-preview').textContent,'Bonjour Ulrich, merci.');
f.elements.body.value='Bonjour {{1}}, {{2}}. Merci.';f.elements.body.dispatchEvent(new dom.window.Event('input'));assert(d.querySelector('[data-wa-sample="2"]'));assert.equal(d.querySelector('[data-wa-sample="1"]').value,'Ulrich');
const input=d.querySelector('[data-wa-sample="2"]');input.value='<img src=x onerror=alert(1)>';input.dispatchEvent(new dom.window.Event('input'));assert.equal(d.getElementById('wa-create-preview').querySelector('img'),null);
d.getElementById('wa-version').click();assert.equal(f.elements.name.value,'finea_demo');assert.equal(d.querySelector('[data-wa-sample="1"]').value,'DG');assert.equal(d.querySelector('[data-wa-sample="2"]').value,'125');assert.equal(d.querySelector('[data-wa-sample="3"]').value,'jeton');
const search=d.getElementById('wa-search');search.value='salaires';search.dispatchEvent(new dom.window.Event('input'));assert.equal(d.querySelectorAll('#wa-models tr')[1].hidden,true);
console.log('PASS dynamic fields, value preservation, safe preview, new version prefilling, catalogue search');

dom.window.close();
