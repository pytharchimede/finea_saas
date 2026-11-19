(function(){
  function youtubeEmbed(url){
    try{var u=new URL(url);var id='';if(u.hostname.includes('youtu.be')) id=u.pathname.slice(1);if(u.hostname.includes('youtube.com')) id=u.searchParams.get('v')||u.pathname.split('/embed/')[1]||'';id=(id||'').split(/[?&/]/)[0];return id?'https://www.youtube.com/embed/'+id:'';}catch(e){return ''}
    return '';
  }
  document.querySelectorAll('.academy-video-url').forEach(function(input){
    var preview=input.closest('form').querySelector('.academy-video-preview');
    function render(){var v=input.value.trim(); if(!preview) return; preview.innerHTML=''; if(!v) return; var embed=youtubeEmbed(v); if(embed){var iframe=document.createElement('iframe'); iframe.src=embed; iframe.allow='accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture'; iframe.allowFullscreen=true; preview.appendChild(iframe); return;} if(/\.(mp4|webm|ogg)(\?|$)/i.test(v)){var video=document.createElement('video'); video.src=v; video.controls=true; preview.appendChild(video); return;} var box=document.createElement('div'); box.textContent='Lien vidéo détecté : '+v; preview.appendChild(box);}
    input.addEventListener('input',render); input.addEventListener('change',render); render();
  });
  document.querySelectorAll('.academy-dropzone').forEach(function(zone){
    var input=zone.querySelector('input[type=file]'), img=zone.querySelector('img');
    function preview(file){ if(!file || !file.type.match(/^image\//)) return; var reader=new FileReader(); reader.onload=function(e){img.src=e.target.result; zone.classList.add('has-preview')}; reader.readAsDataURL(file); }
    input.addEventListener('change',function(){ preview(input.files[0]); });
    ['dragenter','dragover'].forEach(function(evt){zone.addEventListener(evt,function(e){e.preventDefault();zone.classList.add('is-dragover')})});
    ['dragleave','drop'].forEach(function(evt){zone.addEventListener(evt,function(e){e.preventDefault();zone.classList.remove('is-dragover')})});
    zone.addEventListener('drop',function(e){ if(e.dataTransfer.files.length){ input.files=e.dataTransfer.files; preview(input.files[0]); }});
  });
  document.querySelectorAll('select.academy-select-search').forEach(function(select){
    var wrapper=document.createElement('div'); wrapper.className='academy-combobox';
    var input=document.createElement('input'); input.className='finea-input'; input.value=select.options[select.selectedIndex]?select.options[select.selectedIndex].text:''; input.autocomplete='off';
    var panel=document.createElement('div'); panel.className='academy-combobox-panel';
    function fill(filter){ panel.innerHTML=''; Array.prototype.slice.call(select.options).filter(function(o){return o.text.toLowerCase().includes((filter||'').toLowerCase())}).forEach(function(o){var b=document.createElement('button'); b.type='button'; b.textContent=o.text; b.addEventListener('click',function(){select.value=o.value;input.value=o.text;wrapper.classList.remove('open');select.dispatchEvent(new Event('change'));}); panel.appendChild(b);}); }
    select.parentNode.insertBefore(wrapper,select); wrapper.appendChild(input); wrapper.appendChild(panel); wrapper.appendChild(select); select.style.display='none'; fill(''); input.addEventListener('focus',function(){fill(input.value);wrapper.classList.add('open')}); input.addEventListener('input',function(){fill(input.value);wrapper.classList.add('open')}); document.addEventListener('click',function(e){if(!wrapper.contains(e.target)) wrapper.classList.remove('open')});
  });
})();
