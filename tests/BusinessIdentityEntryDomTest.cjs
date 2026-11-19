// Dev dependency: linkedom. Run with PHP_BIN and NODE_PATH when PHP/modules are installed separately.
const {parseHTML}=require('linkedom');
const {execFileSync}=require('node:child_process'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
function setup(kind){
 const html=execFileSync(process.env.PHP_BIN||'php',['-n',path.join(__dirname,'fixtures/business_identity_form.php'),kind],{encoding:'utf8'});
 const {window}=parseHTML('<html><body>'+html+'</body></html>'),document=window.document;
 Object.defineProperty(document,'readyState',{value:'complete'});
 for(const el of document.querySelectorAll('input,select,textarea')){
  for(const field of ['required','disabled','checked'])Object.defineProperty(el,field,{configurable:true,get(){return this.hasAttribute(field)},set(v){if(v)this.setAttribute(field,'');else this.removeAttribute(field)}});
  el.setCustomValidity=function(v){this.validationMessage=v};el.reportValidity=()=>true;
 }
 for(const el of document.querySelectorAll('select'))Object.defineProperty(el,'value',{configurable:true,get(){return this.querySelector('option[selected]')?.value||this.querySelector('option')?.value||''},set(value){for(const o of this.querySelectorAll('option')){if(o.value===value)o.setAttribute('selected','');else o.removeAttribute('selected')}}});
 class FormDataMock{constructor(form){this.data=new Map([...form.querySelectorAll('[name]')].filter(x=>!x.disabled&&(!['radio','checkbox'].includes(x.type)||x.checked)).map(x=>[x.name||x.getAttribute('name'),x.value]));}get(key){return this.data.get(key)}append(k,v){this.data.set(k,v)}}
 const requests=[];let answer={};
 const context={window,document,Event:window.Event,URL,URLSearchParams,FormData:FormDataMock,setTimeout,clearTimeout,Intl,fetch:async(url,options)=>{requests.push({url:String(url),body:options?.body});return {ok:true,json:async()=>answer};}};
 Object.defineProperty(document,'currentScript',{configurable:true,value:{src:'https://finea.test/assets/js/business-identity-entry.js'}});
 vm.runInNewContext(fs.readFileSync(path.join(root,'assets/js/business-identity-entry.js'),'utf8'),context);
 const script=kind==='client'?'client-business-identity.js':'supplier-fne.js';Object.defineProperty(document,'currentScript',{configurable:true,value:{src:'https://finea.test/assets/js/'+script}});
 vm.runInNewContext(fs.readFileSync(path.join(root,'assets/js/'+script),'utf8'),context);
 const get=n=>document.querySelector(`[name="${n}"]`),change=el=>el.dispatchEvent(new window.Event('change',{bubbles:true}));
 const choose=p=>{get('business_profile').value=p;change(get('business_profile'))};
 return {document,window,get,change,choose,requests,answer:value=>{answer=value;}};
}
(async()=>{
 const c=setup('client');
 assert.equal(c.document.querySelector('[data-party-entry]').dataset.pending,'1');assert.equal(c.get('ncc').disabled,true);
 c.choose('fr');assert.equal(c.get('fiscal_country').value,'FR');assert.equal(c.get('identity_method').value,'fr');assert.equal(c.get('siret').required,true);assert.equal(c.get('ncc').disabled,true);assert.equal(c.get('ncc').closest('[data-ci-company-only]').hidden,true);
 c.get('siret').value='35038196800089';c.answer({ok:true,identity:{name:'ADOBE SYSTEMS FRANCE SAS',address:'Paris',city:'Paris',siret:'35038196800089',identity_status:'registry_verified'}});
 c.document.querySelector('#loadClientFne').dispatchEvent(new c.window.Event('click'));await new Promise(setImmediate);
 assert.equal(c.get('nom_client').value,'ADOBE SYSTEMS FRANCE SAS');assert.equal(c.get('localisation_client').value,'Paris');assert.equal(c.requests[0].body.get('fiscal_country'),'FR');assert.equal(c.requests[0].body.get('ncc'),undefined);
 c.choose('eu');assert.equal(c.get('fiscal_country').value,'DE');assert.equal(c.get('identity_method').value,'vies');assert.equal(c.get('nom_client').value,'');assert.equal(c.get('vat_number').required,true);
 c.choose('us');assert.equal(c.get('identity_method').value,'manual');assert.equal(c.get('registration_type').value,'EIN');c.get('identity_method').value='sec';c.change(c.get('identity_method'));assert.equal(c.get('registration_number').required,true);assert.equal(c.get('registration_type').value,'CIK');assert.equal(c.get('registration_type').readOnly,true);assert.ok(c.document.querySelector('#loadClientFne').textContent.includes('CIK'));
 c.choose('person');assert.equal(c.get('client_type').value,'physique');assert.equal(c.get('ncc').disabled,true);assert.equal(c.document.querySelector('[data-business-fields]').hidden,true);assert.equal(c.document.querySelector('[data-personal-client-proof]').hidden,false);assert.equal(c.get('nom_client').readOnly,false);
 c.choose('ci');assert.equal(c.get('client_type').value,'morale');assert.equal(c.get('ncc').required,true);assert.equal(c.get('ncc').disabled,false);assert.ok(c.document.querySelector('#loadClientFne').textContent.includes('NCC'));
 const s=setup('supplier');s.choose('fr');assert.equal(s.get('identity_search').placeholder,'14 chiffres (SIRET) ou 9 chiffres (SIREN)');
 s.choose('eu');assert.equal(s.get('identity_method').value,'vies');assert.ok(s.document.querySelector('[data-primary-identity-label]').textContent.includes('TVA'));
 s.choose('person');assert.equal(s.get('identity_document_number').required,true);assert.equal(s.get('supplier_cni').required,true);assert.equal(s.document.querySelector('[data-primary-identity]').hidden,true);assert.equal(s.document.querySelector('[data-supplier-fne]').hidden,true);
 console.log('BusinessIdentityEntryDomTest: OK (first choice, CI/FR/EU/US/person, correct fields, API autofill and stale field reset)');
})().catch(error=>{console.error(error);process.exitCode=1});
