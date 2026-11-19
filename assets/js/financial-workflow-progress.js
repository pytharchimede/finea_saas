(() => {
  if(window.FineaWorkflowProgress)return;
  const script=document.currentScript, endpoint=new URL('../../api/financial_workflow_progress.php',script.src);
  const nativeFetch=window.fetch.bind(window), nativeSubmit=HTMLFormElement.prototype.submit;
  const actionable=(url,method)=>{
    const u=new URL(url,location.href),p=u.pathname;
    return u.origin===location.origin && String(method).toUpperCase()==='POST' && !/\/(?:get|search|fetch|list|load|verify|check|analyse|export|download)[^/]*\.php$/.test(p) && (/\/(?:services\/)?validation_demandes_finances\.php$/.test(p)||/\/decaissement\/(?:request|api)\/[^/]+\.php$/.test(p)||/\/decaissement\/(?:bon_commande|bon_reception|point_decaissement_jour|paiement_points_decaissement)\.php$/.test(p)||/\/contrats\/[^/]+\.php$/.test(p));
  };
  let current=null;
  const visible=el=>!el.classList.contains('hidden')&&el.getAttribute('aria-hidden')!=='true'&&el.getClientRects().length>0;
  function begin(){
    if(current){clearInterval(current.timer);current.box.remove();current.overlay?.remove();}
    const token=Array.from(crypto.getRandomValues(new Uint8Array(24)),n=>n.toString(16).padStart(2,'0')).join('');
    const box=document.createElement('section');box.className='finea-workflow-progress';box.setAttribute('role','status');
    box.style.cssText='padding:20px;border:1px solid #cbd5e1;border-radius:16px;background:#fff;color:#0f172a;margin:12px 0;text-align:left;font:14px system-ui;min-width:260px';
    box.innerHTML='<strong>Suivi de l’opération</strong><p data-phase>Envoi de la demande…</p><progress aria-label="Progression de l’opération" style="width:100%;height:16px"></progress><small data-elapsed></small><p style="font-size:12px">Les étapes proviennent du serveur. La jauge reste animée quand la durée est inconnue.</p><ol data-log aria-live="polite" style="max-height:200px;overflow:auto;font-size:12px;padding-left:20px"></ol><button type="button" data-dismiss style="margin-top:12px">Masquer le suivi (le traitement continue)</button>';
    const popup=[...document.querySelectorAll('#febApprovalModal,.swal2-popup,dialog[open],[role="dialog"]')].find(visible);
    let overlay=null;
    if(popup)(popup.querySelector('[data-workflow-progress-host],[data-feb-modal-content]')||popup).appendChild(box);
    else{overlay=document.createElement('div');overlay.style.cssText='position:fixed;inset:0;z-index:2147483000;background:#0f172a88;display:flex;align-items:center;justify-content:center;padding:20px';box.style.maxWidth='620px';box.style.width='100%';overlay.appendChild(box);document.body.appendChild(overlay);}
    const op={token,box,overlay,started:Date.now(),seen:0,finished:false,polling:false,missing:0};current=op;
    op.log=message=>{const li=document.createElement('li');li.textContent=new Date().toLocaleTimeString('fr-FR')+' — '+message;box.querySelector('[data-log]').appendChild(li);li.parentNode.scrollTop=li.parentNode.scrollHeight;};
    box.querySelector('[data-dismiss]').onclick=()=>{box.hidden=true;if(overlay)overlay.style.display='none';};
    op.log('Demande envoyée. Aucune nouvelle tentative automatique ne sera effectuée.');
    op.timer=setInterval(()=>{box.querySelector('[data-elapsed]').textContent='Temps écoulé : '+Math.floor((Date.now()-op.started)/1000)+' s';poll(op);},1000);
    return op;
  }
  function finish(op,kind,message){
    if(!op||(op.finished&&kind==='neutral'))return;op.finished=true;clearInterval(op.timer);
    op.box.querySelector('[data-phase]').textContent=message;op.log(message);
    const bar=op.box.querySelector('progress');
    if(kind==='success'){bar.max=1;bar.value=1;}else bar.removeAttribute('value');
    op.box.style.borderColor=kind==='success'?'#059669':kind==='failed'?'#dc2626':'#d97706';
    op.box.querySelector('[data-dismiss]').textContent='Fermer le suivi';
    op.box.querySelector('[data-dismiss]').onclick=()=>{op.box.remove();op.overlay?.remove();};
  }
  async function poll(op){
    if(op.polling||op.finished)return;op.polling=true;
    try{
      const url=new URL(endpoint);url.searchParams.set('token',op.token);
      const response=await nativeFetch(url,{credentials:'same-origin',cache:'no-store'});
      if(response.ok){const data=await response.json();
        for(const event of data.events||[]){if(event.id<=op.seen)continue;op.seen=event.id;op.log(event.message);op.box.querySelector('[data-phase]').textContent=event.message;
          const bar=op.box.querySelector('progress');if(Number.isInteger(event.done)&&event.total>0){bar.max=event.total;bar.value=event.done;}else bar.removeAttribute('value');}
        if(data.state==='failed')finish(op,'failed','Le serveur a interrompu le traitement. Consultez le résultat.');
        else if(data.state==='finished')finish(op,'neutral','Réponse serveur prête. Consultez le résultat de l’opération.');
      }else if(++op.missing===8)op.log('En attente du suivi serveur ; la demande initiale continue.');
    }catch(e){if(++op.missing===8)op.log('Suivi momentanément indisponible. Ne relancez pas l’opération.');}
    finally{op.polling=false;}
  }
  async function result(op,response){
    if((response.headers.get('Content-Type')||'').includes('text/event-stream'))return;
    try{const json=await response.clone().json();const ok=json.ok??json.success;
      finish(op,response.ok&&ok===true?'success':'failed',response.ok&&ok===true?'Opération confirmée par le serveur.':String(json.error||json.message||'Opération non confirmée. Consultez le résultat.'));
    }catch(e){finish(op,response.ok?'neutral':'failed',response.ok?'Réponse reçue. Vérifiez le résultat affiché.':'Le serveur a refusé la demande.');}
  }
  window.fetch=function(input,options={}){
    const url=typeof input==='string'||input instanceof URL?input:input.url;
    const method=options.method||(input instanceof Request?input.method:'GET');
    if(!actionable(url,method))return nativeFetch(input,options);
    const op=begin(),headers=new Headers(options.headers||(input instanceof Request?input.headers:undefined));headers.set('X-Finea-Progress',op.token);
    return nativeFetch(input,{...options,headers}).then(response=>{result(op,response);return response;},error=>{finish(op,'failed','Connexion interrompue : résultat non confirmé. Vérifiez la fiche avant de réessayer.');throw error;});
  };
  function prepareForm(form){
    if(!actionable(form.getAttribute('action')||location.href,form.getAttribute('method')||'GET'))return;
    const op=begin();let field=form.querySelector('[name="_finea_progress"]');
    if(!field){field=document.createElement('input');field.type='hidden';field.name='_finea_progress';form.appendChild(field);}field.value=op.token;
  }
  // FEB posts return a rendered page after a redirect. Keep this document alive
  // while PHP works, then display that exact response (including its flash message).
  function submitFeb(form,submitter){
    if(!/\/(?:services\/)?validation_demandes_finances\.php$/.test(new URL(form.getAttribute('action')||location.href,location.href).pathname)||String(form.getAttribute('method')||'GET').toUpperCase()!=='POST')return false;
    prepareForm(form);const op=current,body=new FormData(form);
    if(submitter?.name)body.append(submitter.name,submitter.value);
    const headers=new Headers();headers.set('X-Finea-Progress',op.token);
    nativeFetch(form.getAttribute('action')||location.href,{method:'POST',body,headers,credentials:'same-origin'}).then(async response=>{
      const type=response.headers.get('Content-Type')||'';
      if(!type.includes('text/html')){await result(op,response);return;}
      const html=await response.text();
      if(new URL(response.url).origin!==location.origin)throw new Error('Unexpected redirect');
      history.replaceState(null,'',response.url);document.open();document.write(html);document.close();
    }).catch(()=>finish(op,'failed','Connexion interrompue : résultat non confirmé. Vérifiez la fiche avant de réessayer.'));
    return true;
  }
  HTMLFormElement.prototype.submit=function(){if(submitFeb(this))return;prepareForm(this);return nativeSubmit.call(this);};
  document.addEventListener('submit',event=>{if(event.defaultPrevented)return;if(submitFeb(event.target,event.submitter))event.preventDefault();else prepareForm(event.target);});
  const proto=XMLHttpRequest.prototype,open=proto.open,send=proto.send;
  proto.open=function(method,url,...rest){this._fineaAction=actionable(url,method);return open.call(this,method,url,...rest);};
  proto.send=function(body){
    if(this._fineaAction){const op=begin();this.setRequestHeader('X-Finea-Progress',op.token);
      this.addEventListener('loadend',()=>{try{const json=JSON.parse(this.responseText);const ok=json.ok??json.success;finish(op,this.status<400&&ok===true?'success':'failed',ok===true?'Opération confirmée par le serveur.':String(json.error||json.message||'Opération non confirmée.'));}catch(e){finish(op,'neutral','Réponse reçue : vérifiez le résultat de l’opération.');}},{once:true});}
    return send.call(this,body);
  };
  window.FineaWorkflowProgress={begin,finish,isBusy:()=>!!current&&!current.finished};
})();
