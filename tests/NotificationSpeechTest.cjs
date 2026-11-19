const fs=require('fs'),assert=require('assert'),{JSDOM}=require('jsdom');
const source=fs.readFileSync('assets/js/finea-notification-speech.js','utf8');
function create(supported=true,user=1){
 const dom=new JSDOM('<div data-speech-settings></div><button data-speech-text="Bonjour, 50 000 FCFA.">Écouter</button><div id="popup"></div>',{url:'https://projet.fidest.ci/notifications.php',runScripts:'outside-only'});
 const w=dom.window;w.FINEA_SPEECH_USER_ID=user;let hidden=false,focus=true;Object.defineProperty(w.document,'hidden',{get:()=>hidden});w.document.hasFocus=()=>focus;
 const calls=[];let cancelled=0;let changed;
 if(supported){w.SpeechSynthesisUtterance=function(text){this.text=text;};w.speechSynthesis={speak:u=>calls.push(u),cancel:()=>cancelled++,getVoices:()=>[{voiceURI:'french',lang:'fr-FR',name:'Français'}],addEventListener:(_,fn)=>changed=fn};}
 w.eval(source);w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
 return {dom,w,calls,setHidden:v=>{hidden=v;w.document.dispatchEvent(new w.Event('visibilitychange'));},setFocus:v=>focus=v,getCancelled:()=>cancelled,changed};
}
const t=create(),w=t.w,d=w.document,form=d.querySelector('form');
assert.equal(form.elements.automatic.checked,false);w.FineaSpeech.notify({id:1,title:'Initial',message:'Old'});assert.equal(t.calls.length,0);
d.querySelector('[data-speech-text]').click();assert.equal(t.calls.length,1);assert(t.calls[0].text.includes('francs CFA'));assert.equal(t.calls[0].voice.voiceURI,'french');
form.elements.automatic.checked=true;form.elements.rate.value=1.4;form.elements.volume.value=.6;form.dispatchEvent(new w.Event('input'));
w.FineaSpeech.stop();w.FineaSpeech.notify({id:2,title:'Avance',message:'Autorisée'});assert.equal(t.calls.length,2);assert.equal(t.calls[1].rate,1.4);assert.equal(t.calls[1].volume,.6);
w.FineaSpeech.notify({id:2,title:'Duplicate'});assert.equal(t.calls.length,2);
w.FineaSpeech.notify({id:3,title:'Prêt',message:'Validé'});assert.equal(t.calls.length,2);t.calls[1].onend();assert.equal(t.calls.length,3);assert(t.calls[2].text.includes('Prêt'));
t.setHidden(true);const count=t.calls.length;w.FineaSpeech.notify({id:4,title:'Hidden'});assert.equal(t.calls.length,count);t.setHidden(false);t.setFocus(false);w.FineaSpeech.notify({id:5,title:'Inactive'});assert.equal(t.calls.length,count);t.setFocus(true);w.FineaSpeech.notify({id:5,title:'Active'});assert.equal(t.calls.length,count+1);
w.FineaSpeech.speak('<script>secret</script><b>Bonjour</b> https://example.test/token');assert(!t.calls.at(-1).text.includes('secret'));assert(!t.calls.at(-1).text.includes('/token'));
const popup=d.getElementById('popup');w.FineaSpeech.mount(popup,{title:'Title',body:'Body'});w.FineaSpeech.mount(popup,{title:'Again'});assert.equal(popup.querySelectorAll('button').length,1);let bubbled=false;popup.onclick=()=>{bubbled=true;};popup.querySelector('button').click();assert.equal(bubbled,false);
assert(JSON.parse(w.localStorage.getItem('finea-speech-v1-1')).automatic);assert.equal(w.localStorage.getItem('finea-speech-v1-2'),null);
const end=t.calls.at(-1).onend;w.FineaSpeech.stop();end();assert(t.getCancelled()>0);
w.FineaSpeech.speak('Test');t.calls.at(-1).onerror({error:'not-allowed'});assert(d.querySelector('[data-status]').textContent.includes('autoriser'));
const unsupported=create(false);assert(unsupported.w.document.querySelector('[data-speech-text]').disabled);assert(unsupported.w.document.querySelector('[data-status]').textContent.includes('indisponible'));
const anonymous=create(true,0);assert.equal(anonymous.w.FineaSpeech,undefined);
[t,unsupported,anonymous].forEach(x=>x.dom.window.close());console.log('PASS defaults, manual reading, settings, voices, sequential queue, deduplication, visibility/focus, safe text, popup actions, user isolation, cancellation, blocked speech and unsupported browser');
