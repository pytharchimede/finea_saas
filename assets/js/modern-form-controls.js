(() => {
  'use strict';
  let serial = 0;
  function enhance(select) {
    if (select.dataset.searchEnhanced || select.classList.contains('select2-hidden-accessible') || select.classList.contains('feb-select-search-native') || select.classList.contains('feb-native-select-hidden') || select.tomselect) return;
    select.dataset.searchEnhanced = '1';
    const wrapper = document.createElement('div'); wrapper.className = 'modern-select';
    const input = document.createElement('input'); input.type = 'text'; input.autocomplete = 'off';
    input.setAttribute('role','combobox'); input.setAttribute('aria-autocomplete','list'); input.setAttribute('aria-expanded','false');
    input.setAttribute('aria-label', select.getAttribute('aria-label') || select.labels?.[0]?.textContent.trim() || select.name || 'Choisir');
    input.placeholder = 'Rechercher et sélectionner…';
    const list = document.createElement('div'); list.className='modern-options'; list.id='modern-options-'+(++serial); list.setAttribute('role','listbox'); list.hidden=true;
    input.setAttribute('aria-controls',list.id);
    select.before(wrapper); wrapper.append(select,input,list); select.classList.add('modern-native'); select.tabIndex=-1;
    let active=-1, visible=[];
    const sync=()=>{ input.disabled=select.disabled; input.value=Array.from(select.selectedOptions).map(o=>o.textContent).join(', '); input.setAttribute('aria-required',String(select.required)); };
    const close=()=>{list.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');sync();};
    const choose=(option)=>{ if(!select.multiple) select.value=option.value; else option.selected=!option.selected; select.dispatchEvent(new Event('input',{bubbles:true}));select.dispatchEvent(new Event('change',{bubbles:true})); if(select.multiple){input.value='';render();}else close(); };
    function render() {
      const q=input.value.toLocaleLowerCase(); list.replaceChildren(); active=-1;
      visible=Array.from(select.options).filter(o=>!o.disabled && !o.parentElement.disabled && !o.hidden && o.textContent.toLocaleLowerCase().includes(q));
      visible.forEach((option,i)=>{const row=document.createElement('div');row.id=list.id+'-'+i;row.setAttribute('role','option');row.setAttribute('aria-selected',String(option.selected));row.textContent=(select.multiple && option.selected?'✓ ':'')+option.textContent;row.addEventListener('mousedown',e=>e.preventDefault());row.addEventListener('click',()=>choose(option));list.append(row);});
      if(!visible.length){const empty=document.createElement('div');empty.textContent='Aucun résultat';list.append(empty);}
      list.hidden=false;input.setAttribute('aria-expanded','true');
    }
    input.addEventListener('focus',()=>{input.value='';render();});input.addEventListener('input',render);
    input.addEventListener('keydown',e=>{if(e.key==='Escape'){close();return;}if(['ArrowDown','ArrowUp'].includes(e.key)){e.preventDefault();if(list.hidden){input.value='';render();}active=Math.max(0,Math.min(visible.length-1,active+(e.key==='ArrowDown'?1:-1)));Array.from(list.children).forEach((el,i)=>el.classList.toggle('active',i===active));if(visible[active]){input.setAttribute('aria-activedescendant',list.children[active].id);list.children[active].scrollIntoView({block:'nearest'});}}if(e.key==='Enter'&&!list.hidden){e.preventDefault();if(visible[active<0?0:active])choose(visible[active<0?0:active]);}});
    input.addEventListener('blur',close); select.addEventListener('change',sync);select.addEventListener('invalid',()=>input.focus());
    select.form?.addEventListener('reset',()=>setTimeout(sync));
    new MutationObserver(sync).observe(select,{attributes:true,childList:true,subtree:true,attributeFilter:['disabled','required','selected']});sync();
  }
  function drop(input) {
    if(input.dataset.dropEnhanced || input.hasAttribute('data-dropzone-input') || input.hasAttribute('data-no-dropzone') || input.classList.contains('hidden') || input.hidden)return;input.dataset.dropEnhanced='1';
    const zone=document.createElement('div');zone.className='modern-drop';input.before(zone);
    const title=document.createElement('span');title.textContent='Déposez vos fichiers ici ou cliquez pour parcourir';
    const status=document.createElement('span');status.className='modern-drop-status';status.setAttribute('aria-live','polite');
    zone.append(title,input,status);
    zone.addEventListener('click',e=>{if(e.target!==input && !input.disabled)input.click();});
    const update=()=>{status.textContent=Array.from(input.files||[]).map(f=>f.name+' · '+Math.ceil(f.size/1024)+' Ko').join(', ');};
    zone.addEventListener('dragover',e=>{if(input.disabled)return;e.preventDefault();zone.classList.add('dragging');});zone.addEventListener('dragleave',()=>zone.classList.remove('dragging'));
    zone.addEventListener('drop',e=>{e.preventDefault();zone.classList.remove('dragging');if(input.disabled)return;const transfer=new DataTransfer();Array.from(e.dataTransfer.files).slice(0,input.multiple?undefined:1).forEach(f=>transfer.items.add(f));input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));});input.addEventListener('change',update);
  }
  function scan(root=document){root.querySelectorAll('select').forEach(enhance);root.querySelectorAll('input[type=file]').forEach(drop);}
  function boot() { scan();new MutationObserver(records=>{for(const r of records)for(const n of r.addedNodes)if(n.nodeType===1){if(n.matches('select'))enhance(n);if(n.matches('input[type=file]'))drop(n);scan(n);}}).observe(document.body,{childList:true,subtree:true}); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',boot,{once:true}); else boot();
})();
