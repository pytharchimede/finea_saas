'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(require('node:path').join(__dirname,'../js/passkeys.js'),'utf8');
function setup(forms=[]){
 const document={getElementById:()=>null,querySelectorAll:selector=>selector.startsWith('form[data-')?forms:[],addEventListener:()=>{}};
 const window={location:{pathname:'/contrats/workflow.php'},PublicKeyCredential:{},alert:()=>{}};
 const context={document,window,navigator:{credentials:{}},Set,Uint8Array};vm.runInNewContext(source,context);return context;
}
function form(id){return {id,dataset:{passkeyContext:'contract:1:rh:hash'},hasAttribute:()=>false,querySelector:()=>null,addEventListener(name,fn){this.handler=fn;},requestSubmit(){this.submissions=(this.submissions||0)+1;}};}
function event(prevented=false){return {defaultPrevented:prevented,preventDefault(){this.defaultPrevented=true;},submitter:{disabled:false}};}
(async()=>{
 const f=form('workflowSignForm'),context=setup([f]);let resolve,calls=0;
 context.window.FineaPasskey.confirmSignature=()=>{calls++;return new Promise(r=>resolve=r);};
 const first=f.handler(event());await f.handler(event());assert.equal(calls,1);resolve({});await first;assert.equal(f.submissions,1);assert.equal(f.dataset.passkeyPending,undefined);assert.equal(f.dataset.passkeyVerified,undefined);
 await f.handler(event(true));assert.equal(calls,1,'Prior client validation prevents ceremonies.');
 context.window.FineaPasskey.confirmSignature=async()=>{throw new Error('cancelled');};await f.handler(event());assert.equal(f.dataset.passkeyPending,undefined);assert.equal(f.dataset.passkeyVerified,undefined);
 const ctx=setup();ctx.navigator.credentials.get=async()=>{throw new Error('cancelled');};let optionsResolve;
 ctx.fetch=()=>new Promise(r=>optionsResolve=r);
 const pending=ctx.window.FineaPasskey.confirmSignature('../request','contract:1:rh');
 await assert.rejects(ctx.window.FineaPasskey.confirmSignature('../request','contract:2:rh'),/déjà en cours/);
 optionsResolve({ok:true,json:async()=>({challenge:'YQ',allowCredentials:[]})});ctx.atob=value=>Buffer.from(value,'base64').toString('binary');
 await assert.rejects(pending,/cancelled/);
 const retry=ctx.window.FineaPasskey.confirmSignature('../request','contract:1:rh');optionsResolve({ok:true,json:async()=>({challenge:'YQ',allowCredentials:[]})});await assert.rejects(retry,/cancelled/);
 console.log('Passkey client: concurrency, prior validation, cancellation and retry OK');
})().catch(error=>{console.error(error);process.exitCode=1;});
