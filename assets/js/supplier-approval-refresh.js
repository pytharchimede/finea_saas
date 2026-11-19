(() => {
  const container=document.getElementById('cards-container'),form=document.getElementById('searchForm');
  if(!container||!form)return;
  let busy=false,last=0;
  async function refresh(){
    if(busy||document.hidden||Date.now()-last<5000||window.FineaWorkflowProgress?.isBusy())return;
    busy=true;last=Date.now();
    try{
      const query=new URLSearchParams(new FormData(form));
      const response=await fetch('request/fiches_approbation_list.php?'+query,{credentials:'same-origin',cache:'no-store'});
      if(!response.ok)return;const result=await response.json();if(!result.ok)return;
      const items=new Map((result.items||[]).map(row=>[String(row.num_fiche),row]));
      container.querySelectorAll('.approval-card').forEach(card=>{
        const button=card.querySelector('.js-approve');if(!button)return;
        const row=items.get(String(button.dataset.num));if(!row)return;
        const error=row.supplier_approval_error||'';let alert=card.querySelector('[data-approval-supplier-alert]');
        if(!alert){alert=document.createElement('div');alert.dataset.approvalSupplierAlert='';alert.className='mb-3 rounded-xl border border-red-300 bg-red-50 p-3 text-sm text-red-900';alert.setAttribute('role','alert');button.closest('.actions-wrap').before(alert);}
        alert.textContent=error;alert.hidden=!error;
        if(error){button.dataset.supplierBlocked='1';button.disabled=true;button.title='Fournisseur à régulariser';}
        else if(button.dataset.supplierBlocked==='1'){delete button.dataset.supplierBlocked;button.disabled=false;button.removeAttribute('title');}
      });
    }catch(e){/* Keep the previous state; the decision endpoint always rechecks. */}
    finally{busy=false;}
  }
  window.addEventListener('focus',refresh);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});
  setInterval(refresh,45000);
  window.FineaSupplierApprovalRefresh=refresh;
})();
