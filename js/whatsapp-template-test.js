(()=>{
 const form=document.getElementById('whatsapp-test');
 if(!form)return;
 const body=JSON.parse(document.getElementById('whatsapp-body').textContent);
 const preview=document.getElementById('whatsapp-preview');
 const render=()=>{const values={};form.querySelectorAll('[data-variable]').forEach(input=>{values[input.dataset.variable]=input.value;});preview.textContent=body.replace(/\{\{(\d+)\}\}/g,(match,key)=>values[key]??match);};
 form.addEventListener('input',render);
 form.addEventListener('submit',event=>{const phone=form.elements.test_phone.value;if(!window.confirm('Envoyer réellement ce test WhatsApp au '+phone+' ?'))event.preventDefault();else form.querySelector('button').disabled=true;});
 render();
})();
