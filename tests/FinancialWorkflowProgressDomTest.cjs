const {parseHTML}=require('linkedom'),vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict'),{webcrypto}=require('node:crypto');
const {window}=parseHTML('<html><body><div id="febApprovalModal"><div data-workflow-progress-host></div></div><form method="post" action="https://finea.test/validation_demandes_finances.php"><input name="csrf" value="preserved"></form></body></html>');
const document=window.document;document.currentScript={src:'https://finea.test/assets/js/financial-workflow-progress.js'};
window.HTMLElement.prototype.getClientRects=function(){return this.hidden?[]:[{}]};
const form=document.querySelector('form'),formProto=Object.getPrototypeOf(form);let submissions=0;
form.setAttribute('method','post');form.setAttribute('action','https://finea.test/decaissement/bon_commande.php');
formProto.submit=function(){submissions++};
const intervals=new Map();let counter=0;const calls=[],pending=[];
const response=json=>new Response(JSON.stringify(json),{headers:{'Content-Type':'application/json'}});
window.fetch=(url,options)=>{calls.push({url:String(url),options});if(String(url).includes('financial_workflow_progress.php'))return Promise.resolve(response({ok:true,state:'running',events:[{id:1,message:'Contrôle fournisseur confirmé.',done:null,total:null}]}));return new Promise((resolve,reject)=>pending.push({resolve,reject}));};
class Xhr{open(){}send(){this.sent=(this.sent||0)+1}setRequestHeader(k,v){this.header=[k,v]}addEventListener(k,fn){this[k]=fn}}
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/js/financial-workflow-progress.js','utf8'),{window,document,HTMLFormElement:{prototype:formProto},XMLHttpRequest:Xhr,location:new URL('https://finea.test/validation_demandes_finances.php'),URL,Request,Headers,FormData:class {constructor(form){this.form=form}append(){}},crypto:webcrypto,setInterval:fn=>{intervals.set(++counter,fn);return counter},clearInterval:id=>intervals.delete(id),console});
const flush=()=>new Promise(r=>setImmediate(r));
(async()=>{
 const promise=window.fetch('/decaissement/request/approuver_fiche_decaissement.php',{method:'POST',body:'csrf=preserved'});
 assert.equal(calls.length,1);assert.equal(calls[0].options.body,'csrf=preserved');assert.match(calls[0].options.headers.get('X-Finea-Progress'),/^[a-f0-9]{48}$/);
 assert.ok(document.querySelector('#febApprovalModal .finea-workflow-progress'),'Progress not embedded in current popup');
 assert.equal(document.querySelector('progress').hasAttribute('value'),false,'Invented percentage');
 [...intervals.values()][0]();await flush();await flush();assert.match(document.querySelector('[data-log]').textContent,/Contrôle fournisseur confirmé/);
 pending.shift().resolve(response({ok:true}));await promise;await flush();await flush();
 assert.match(document.querySelector('[data-phase]').textContent,/confirmée/);
 form.submit();assert.equal(submissions,1);assert.match(form.querySelector('[name="_finea_progress"]').value,/^[a-f0-9]{48}$/);assert.equal(form.querySelector('[name="csrf"]').value,'preserved');
 const xhr=new Xhr();xhr.open('POST','/decaissement/request/certifier_fiche_decaissement.php');xhr.send('csrf=unchanged');assert.equal(xhr.sent,1);assert.equal(xhr.header[0],'X-Finea-Progress');xhr.status=200;xhr.responseText='{"ok":false,"error":"Fournisseur à régulariser"}';xhr.loadend();assert.match(document.querySelector('[data-phase]').textContent,/Fournisseur à régulariser/);
 const failed=window.fetch('/decaissement/request/decaisser_total.php',{method:'POST'});pending.shift().reject(new Error('network'));await assert.rejects(failed);assert.match(document.querySelector('[data-phase]').textContent,/résultat non confirmé/);
 form.setAttribute('action','https://finea.test/validation_demandes_finances.php');form.submit();assert.equal(submissions,1,'FEB navigated away before receiving progress');assert.equal(pending.length,1,'FEB submitted more than once');pending.shift().resolve(response({ok:true}));await flush();await flush();
 const writes=calls.filter(c=>!c.url.includes('financial_workflow_progress.php'));assert.equal(writes.length,3,'Business action retried');
 console.log('FinancialWorkflowProgressDomTest: OK (popup, server log, no fake percent, fetch/form/XHR, CSRF, failure and no replay)');
})().catch(e=>{console.error(e);process.exitCode=1});
