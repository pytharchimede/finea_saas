(()=>{
 'use strict';
 if(window.FineaSpeech)return;
 const user=Number(window.FINEA_SPEECH_USER_ID||0);
 if(!user)return;
 const synth=window.speechSynthesis,supported=!!synth&&typeof window.SpeechSynthesisUtterance==='function';
 const key=`finea-speech-v1-${user}`,seenKey=`${key}-seen`;
 const read=(name,fallback)=>{try{return JSON.parse(localStorage.getItem(name))??fallback;}catch(_){return fallback;}};
 const write=(name,value)=>{try{localStorage.setItem(name,JSON.stringify(value));}catch(_){}};
 const clamp=(v,min,max,base)=>Number.isFinite(Number(v))?Math.max(min,Math.min(max,Number(v))):base;
 let prefs=read(key,{});const normalize=p=>({automatic:p?.automatic===true,voice:typeof p?.voice==='string'?p.voice:'',rate:clamp(p?.rate??1,.5,2,1),volume:clamp(p?.volume??1,0,1,1)});prefs=normalize(prefs);
 let unlocked=false,current=null,queue=[],generation=0,statusNode=null,form=null,stopControl=null;
 const status=text=>{if(statusNode)statusNode.textContent=text;};
 const text=value=>{const doc=new DOMParser().parseFromString(String(value||''),'text/html');doc.querySelectorAll('script,style').forEach(n=>n.remove());return (doc.body.textContent||'').replace(/https?:\/\/\S+/g,' lien disponible dans FINEA ').replace(/\b(?:FCFA|XOF|CFA)\b/gi,'francs CFA').replace(/\s+/g,' ').trim().slice(0,2500);};
 function stop(){generation++;queue=[];current=null;if(stopControl)stopControl.hidden=true;if(supported)synth.cancel();status('Lecture arrêtée.');}
 function next(){
  if(!supported||current||!queue.length||document.hidden)return;
  const value=queue.shift(),utterance=new SpeechSynthesisUtterance(value),version=generation;
  utterance.lang='fr-FR';utterance.rate=prefs.rate;utterance.volume=prefs.volume;
  const voices=synth.getVoices();utterance.voice=voices.find(v=>v.voiceURI===prefs.voice)||voices.find(v=>/^fr\b/i.test(v.lang))||null;
  current=utterance;if(stopControl)stopControl.hidden=false;status('Lecture en cours…');
  utterance.onend=()=>{if(version!==generation)return;current=null;if(stopControl&&!queue.length)stopControl.hidden=true;status('Lecture terminée.');next();};
  utterance.onerror=e=>{if(version!==generation)return;current=null;queue=[];if(stopControl)stopControl.hidden=true;status(e.error==='not-allowed'?'Cliquez sur Écouter ou Tester la voix pour autoriser la lecture.':'Lecture indisponible. Vérifiez la voix et le son de votre appareil.');};
  try{synth.speak(utterance);}catch(_){current=null;queue=[];status('Lecture indisponible sur cet appareil.');}
 }
 function speak(value){if(!supported){status('La synthèse vocale est indisponible dans ce navigateur.');return;}unlocked=true;stop();const clean=text(value);if(clean){queue=[clean];next();}}
 function notify(notification,source='system'){
  if(!supported)return;
  const id=Number(notification.id||0);if(id<=0)return;
  const token=`${source}:${id}`,expectedGeneration=generation;
  const claim=()=>{if(expectedGeneration!==generation)return;if(!prefs.automatic||!unlocked||document.hidden||!document.hasFocus())return;let seen=read(seenKey,[]);if(!Array.isArray(seen))seen=[];if(seen.includes(token))return;write(seenKey,[...seen,token].slice(-200));
   const clean=text(`${notification.title||'Notification'}. ${notification.message||notification.body||''}`);if(clean&&queue.length<5){queue.push(clean);next();}
  };
  if(navigator.locks?.request)navigator.locks.request(key,{mode:'exclusive'},claim).catch(()=>{});else claim();
 }
 function button(value){const b=document.createElement('button');b.type='button';b.className='finea-speech-listen';b.textContent='Écouter';b.disabled=!supported;b.setAttribute('aria-label','Écouter cette notification');b.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();speak(value);});return b;}
 function mount(container,notification){if(!container||container.querySelector('.finea-speech-listen'))return;container.append(button(`${notification.title||''}. ${notification.message||notification.body||''}`));}
 function voices(){if(!form||!supported)return;const select=form.elements.voice;select.replaceChildren();const fallback=document.createElement('option');fallback.value='';fallback.textContent='Voix française automatique';select.append(fallback);synth.getVoices().filter(v=>/^fr\b/i.test(v.lang)).forEach(v=>{const o=document.createElement('option');o.value=v.voiceURI;o.textContent=`${v.name} (${v.lang})`;select.append(o);});select.value=prefs.voice;if(select.selectedIndex<0)select.value='';}
 function setup(){
  if(supported){stopControl=document.createElement('button');stopControl.type='button';stopControl.className='finea-speech-stop';stopControl.textContent='Arrêter la lecture';stopControl.hidden=true;stopControl.addEventListener('click',stop);document.body.append(stopControl);}
  const host=document.querySelector('[data-speech-settings]');
  if(host){host.innerHTML='<details class="finea-speech-settings"><summary>Lecture vocale des notifications</summary><form><label class="finea-speech-auto"><input type="checkbox" name="automatic"> Lire automatiquement les nouvelles notifications</label><label>Voix<select name="voice"></select></label><label>Vitesse<input type="range" name="rate" min="0.5" max="2" step="0.1"><output data-rate></output></label><label>Volume<input type="range" name="volume" min="0" max="1" step="0.1"><output data-volume></output></label><div><button type="button" data-test>Tester la voix</button><button type="button" data-stop>Arrêter la lecture</button></div><p>Préférences personnelles sur ce navigateur. La lecture automatique concerne les nouvelles notifications lorsque FINEA est visible et actif. Cliquez sur Tester la voix après ouverture de FINEA. Les voix disponibles dépendent de votre appareil.</p><p role="status" aria-live="polite" data-status></p></form></details>';
   form=host.querySelector('form');statusNode=host.querySelector('[data-status]');form.elements.automatic.checked=prefs.automatic;form.elements.rate.value=prefs.rate;form.elements.volume.value=prefs.volume;
   const outputs=()=>{host.querySelector('[data-rate]').textContent=`${prefs.rate} ×`;host.querySelector('[data-volume]').textContent=`${Math.round(prefs.volume*100)} %`;};outputs();voices();
   form.addEventListener('submit',e=>e.preventDefault());form.addEventListener('input',()=>{unlocked=true;prefs=normalize({automatic:form.elements.automatic.checked,voice:form.elements.voice.value,rate:form.elements.rate.value,volume:form.elements.volume.value});write(key,prefs);outputs();if(!prefs.automatic)stop();status('Préférences enregistrées sur ce navigateur.');});
   host.querySelector('[data-test]').addEventListener('click',()=>speak('Bonjour. FINEA peut maintenant lire vos notifications à voix haute.'));host.querySelector('[data-stop]').addEventListener('click',stop);
   if(!supported){form.querySelectorAll('input,select,button').forEach(el=>el.disabled=true);status('La synthèse vocale est indisponible dans ce navigateur.');}
  }
  document.querySelectorAll('[data-speech-text]').forEach(el=>{el.disabled=!supported;el.addEventListener('click',()=>speak(el.dataset.speechText));});
 }
 document.addEventListener('pointerdown',event=>{if(event.isTrusted)unlocked=true;},true);
 document.addEventListener('keydown',event=>{if(event.isTrusted)unlocked=true;},true);
 window.FineaSpeech={speak,notify,mount,stop};
 if(supported)synth.addEventListener?.('voiceschanged',voices);
 document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();});window.addEventListener('pagehide',stop);
 window.addEventListener('storage',event=>{if(event.key===key){prefs=normalize(read(key,{}));stop();}});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setup,{once:true});else setup();
})();
