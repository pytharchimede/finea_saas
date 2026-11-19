const {parseHTML}=require('linkedom'),vm=require('node:vm'),fs=require('node:fs'),assert=require('node:assert/strict');
const {window}=parseHTML('<html><body><form id="searchForm"></form><div id="cards-container"><div class="approval-card"><div data-approval-supplier-alert>À compléter</div><div class="actions-wrap"><button class="js-approve" data-num="TEST" data-supplier-blocked="1" disabled>Approuver</button><button class="js-reject">Refuser</button></div></div></div></body></html>');
let time=10000,error='Dossier incomplet';const document=window.document;
Object.defineProperty(window.HTMLButtonElement.prototype,'disabled',{get(){return this.hasAttribute('disabled')},set(v){if(v)this.setAttribute('disabled','');else this.removeAttribute('disabled')}});
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/js/supplier-approval-refresh.js','utf8'),{window,document,Date:{now:()=>time},URLSearchParams,FormData:class extends URLSearchParams{constructor(){super()}},setInterval:()=>1,fetch:async()=>({ok:true,json:async()=>({ok:true,items:[{num_fiche:'TEST',supplier_approval_error:error}]})})});
(async()=>{
 const approve=document.querySelector('.js-approve'),reject=document.querySelector('.js-reject');
 await window.FineaSupplierApprovalRefresh();assert.equal(reject.disabled,false,'Refusal disabled for incomplete supplier');assert.equal(approve.disabled,true);
 error='';time+=6000;await window.FineaSupplierApprovalRefresh();assert.equal(approve.disabled,false,'Approval not restored after regularisation');assert.equal(reject.disabled,false);
 approve.disabled=true;time+=6000;await window.FineaSupplierApprovalRefresh();assert.equal(approve.disabled,true,'An unrelated in-flight disable was overridden');
 console.log('SupplierApprovalRefreshDomTest: OK (refusal available, approval restored, active action preserved)');
})().catch(e=>{console.error(e);process.exitCode=1});
