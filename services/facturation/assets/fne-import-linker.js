document.querySelectorAll('[data-fne-import-linker]').forEach(function(root){
    const kind=root.dataset.kind==='purchase'?'purchase':'sales';
    const input=root.querySelector('[data-fne-link-search]');
    const results=root.querySelector('[data-fne-link-results]');
    const hidden=root.querySelector('[name="fne_import_id"]');
    const button=root.querySelector('[data-fne-link-submit]');
    const status=root.querySelector('[data-fne-link-status]');
    let timer=0,controller=null;
    const endpoint=kind==='purchase'?'api/fne_purchase_import_search.php':'api/fne_sales_import_search.php';
    const render=items=>{
        results.innerHTML='';
        if(!items.length)results.innerHTML='<div class="p-3 text-sm text-slate-500">Aucune facture FNE disponible.</div>';
        items.forEach(row=>{
            const option=document.createElement('button');option.type='button';option.disabled=!!row.finea_invoice_id;
            option.className='flex w-full items-center justify-between gap-3 rounded-xl p-3 text-left hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50';
            const left=document.createElement('span'),title=document.createElement('b'),sub=document.createElement('small'),amount=document.createElement('b');
            title.className='block text-sm text-slate-900';sub.className='block text-xs text-slate-500';amount.className='text-sm text-indigo-700';
            title.textContent=row.client_name||row.establishment||'Tiers non renseigné';
            sub.textContent=[row.fne_reference,String(row.invoice_date||'').slice(0,10),row.finea_invoice_id?'Déjà liée à '+(row.finea_reference||'Finéa'):''].filter(Boolean).join(' · ');
            amount.textContent=Number(row.net_payable||0).toLocaleString('fr-FR')+' F';left.append(title,sub);option.append(left,amount);
            option.onclick=()=>{hidden.value=row.id;input.value=[row.fne_reference,row.client_name||row.establishment].filter(Boolean).join(' — ');button.disabled=false;results.classList.add('hidden');status.textContent='Correspondance sélectionnée. Cliquez sur « Lier et synchroniser ».'};
            results.append(option);
        });
        results.classList.remove('hidden');
    };
    input.addEventListener('input',()=>{hidden.value='';button.disabled=true;clearTimeout(timer);controller?.abort();const q=input.value.trim();if(q.length<2){results.classList.add('hidden');return}timer=setTimeout(async()=>{controller=new AbortController();status.textContent='Recherche FNE…';try{const response=await fetch(endpoint+'?q='+encodeURIComponent(q),{signal:controller.signal});const json=await response.json();if(!json.ok)throw Error(json.error||'Recherche indisponible.');render(json.items||[]);status.textContent=(json.items||[]).length+' résultat(s), lignes déjà liées protégées.'}catch(error){if(error.name!=='AbortError')status.textContent=error.message}},250)});
    document.addEventListener('click',event=>{if(event.target!==input&&!results.contains(event.target))results.classList.add('hidden')});
});
