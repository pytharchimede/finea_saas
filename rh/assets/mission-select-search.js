(() => {
  const norm = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const enhance = select => {
    if (select.dataset.modernReady) return;
    select.dataset.modernReady = '1'; select.classList.add('sr-only');
    const wrap=document.createElement('div');wrap.className='relative';select.after(wrap);wrap.appendChild(select);
    const box=document.createElement('div');box.className='flex min-h-[50px] flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2 focus-within:border-amber-400 focus-within:ring-4 focus-within:ring-amber-100';
    const chips=document.createElement('div');chips.className='contents';const input=document.createElement('input');input.type='search';input.placeholder=select.dataset.placeholder||'Rechercher…';input.className='min-w-[150px] flex-1 border-0 bg-transparent px-1 py-1 text-sm font-semibold outline-none';
    const menu=document.createElement('div');menu.className='absolute z-50 mt-2 hidden max-h-72 w-full overflow-y-auto rounded-2xl border bg-white p-2 shadow-2xl';box.append(chips,input);wrap.append(box,menu);
    const refresh=()=>{chips.replaceChildren();const selected=[...select.selectedOptions].filter(option=>option.value!=='');if(!select.multiple){input.value=selected[0]?.textContent.trim()||'';input.classList.toggle('font-black',selected.length>0);return;}selected.forEach(option=>{const chip=document.createElement('button');chip.type='button';chip.className='rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-950';chip.textContent=option.textContent+' ×';chip.onclick=()=>{option.selected=false;select.dispatchEvent(new Event('change',{bubbles:true}));refresh();render(input.value)};chips.appendChild(chip)});};
    const render=query=>{menu.replaceChildren();const needle=norm(query);const options=[...select.options].filter(o=>!needle||norm(o.textContent).includes(needle));options.forEach(option=>{const b=document.createElement('button');b.type='button';b.className='flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm hover:bg-amber-50';b.innerHTML=`<span>${option.textContent}</span><i class="fas ${option.selected?'fa-check text-amber-600':'fa-plus text-slate-300'}"></i>`;b.onclick=()=>{if(select.multiple)option.selected=!option.selected;else select.value=option.value;select.dispatchEvent(new Event('change',{bubbles:true}));refresh();if(select.multiple){render('');input.focus()}else menu.classList.add('hidden')};menu.appendChild(b)});if(!options.length)menu.innerHTML='<p class="p-4 text-center text-sm text-slate-500">Aucun résultat</p>';menu.classList.remove('hidden')};
    input.onfocus=()=>{input.select();render('')};input.oninput=()=>render(input.value);select.addEventListener('change',refresh);select.addEventListener('optionsupdated',()=>{refresh();menu.classList.add('hidden')});document.addEventListener('mousedown',e=>{if(!wrap.contains(e.target))menu.classList.add('hidden')});refresh();
  };
  document.querySelectorAll('select[data-modern-select]').forEach(enhance);
  window.FineaSelectSearch={enhance};
})();
