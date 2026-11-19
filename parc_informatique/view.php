<?php
$query = http_build_query(array_filter($filters, static fn($v) => $v !== ''));
$allAssets = $manager->assets();
$users = $manager->users();
$suppliers = $manager->suppliers();
$technicians = $manager->technicians();
$defaultTechnician = '';
foreach ($technicians as $technician) {
    if (preg_match('/CHARG[ÉE]\s+D.INFRASTRUCTURE(?:\s+ET)?\s+R[ÉE]SEAU/ui', (string) $technician['job_title'])) {
        $defaultTechnician = (string) $technician['name'];
        break;
    }
}
$editMedia = $edit ? $manager->assetMedia((int) $edit['id']) : [];
if ($edit && !$editMedia && !empty($edit['photo_path'])) {
    $editMedia[] = ['file_path' => $edit['photo_path'], 'media_type' => 'image', 'original_name' => 'Photo existante'];
}
$incidentMedia = [];
foreach ($incidents as $incident) {
    $incidentMedia[(int) $incident['id']] = $manager->incidentMedia((int) $incident['id']);
}
$input = static function (string $name, string $label, $value = '', $type = 'text', bool $wide = false) use ($h): void {
    echo '<label class="' . ($wide ? 'md:col-span-2 ' : '') . 'text-xs font-bold uppercase tracking-wide text-slate-500">' . $label . '<input type="' . $type . '" name="' . $name . '" value="' . $h($value) . '" class="mt-1.5 w-full rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-3 text-sm font-normal normal-case text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"></label>';
};
$pageTitle = 'Parc informatique';
$activeMenu = 'parc_informatique';
$layoutRootBase = '../';
$layoutModuleBase = '../decaissement/';
require __DIR__ . '/../decaissement/includes/header.php';
require __DIR__ . '/../decaissement/includes/navbar.php'; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<style>
    main>section.finea-grid:first-child {
        background-color: #0f172a !important;
        background-image: radial-gradient(circle at 88% 12%, rgba(34, 211, 238, .18), transparent 28%), linear-gradient(135deg, #020617 0%, #172554 55%, #312e81 100%) !important;
        color: #fff !important
    }
</style>
<style>
    .ts-wrapper {
        width: 100%;
        min-width: 0;
        font-weight: 400;
        text-transform: none
    }

    .ts-wrapper.single .ts-control {
        min-height: 46px;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #f8fafc;
        padding: 11px 40px 11px 14px;
        box-shadow: none;
        color: #0f172a
    }

    .ts-wrapper.focus .ts-control {
        border-color: #6366f1;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, .12)
    }

    .ts-wrapper.single .ts-control:after {
        right: 15px;
        border-color: #64748b transparent transparent
    }

    .ts-control input {
        min-width: 4rem !important;
        color: #0f172a !important;
        font-size: 14px !important;
        text-transform: none !important;
        letter-spacing: normal !important
    }

    .ts-dropdown {
        z-index: 100;
        border: 1px solid #dbe3ef;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 20px 45px rgba(15, 23, 42, .18);
        overflow: hidden
    }

    .ts-dropdown .option {
        padding: 11px 14px;
        color: #334155;
        white-space: normal;
        line-height: 1.35;
        text-transform: none;
        letter-spacing: normal
    }

    .ts-dropdown .active {
        background: #eef2ff;
        color: #3730a3
    }

    .ts-dropdown .no-results {
        padding: 14px;
        color: #64748b
    }

    .ts-wrapper.parc-filter-select .ts-control {
        min-height: 48px
    }

    .ts-hidden-accessible {
        position: absolute !important
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        if (!window.TomSelect) return;
        document.querySelectorAll('main select,[data-modal] select').forEach(select => {
            if (select.tomselect) return;
            const empty = Array.from(select.options).find(option => option.value === '');
            const instance = new TomSelect(select, {
                allowEmptyOption: true,
                create: false,
                maxOptions: null,
                closeAfterSelect: true,
                searchField: ['text'],
                placeholder: select.dataset.placeholder || empty?.textContent?.trim() || 'Saisir pour rechercher…',
                render: {
                    no_results: () => '<div class="no-results">Aucun résultat trouvé</div>'
                }
            });
            if (select.closest('main form') && !select.closest('[data-modal]')) instance.wrapper.classList.add('parc-filter-select')
        });
        document.addEventListener('click', event => {
            const assetButton = event.target.closest('[data-incident-asset]');
            if (assetButton) document.getElementById('incident_asset')?.tomselect?.setValue(assetButton.dataset.incidentAsset, true);
            const manageButton = event.target.closest('[data-manage-incident]');
            if (manageButton) document.getElementById('manageIncidentStatus')?.tomselect?.setValue(manageButton.dataset.incidentStatus, true)
        }, true)
    });
</script>
<style>
    [data-modal] {
        display: none
    }

    [data-modal].open {
        display: flex
    }

    body.modal-open {
        overflow: hidden
    }

    .finea-grid {
        background-image: linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px);
        background-size: 28px 28px
    }

    .asset-form-group {
        grid-column: 1/-1;
        border: 1px solid #e2e8f0;
        border-radius: 22px;
        padding: 18px;
        background: linear-gradient(145deg, #fff, #f8fafc);
        box-shadow: 0 8px 24px rgba(15, 23, 42, .04)
    }

    .asset-form-group-head {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
        padding-bottom: 13px;
        border-bottom: 1px solid #e2e8f0
    }

    .asset-form-group-icon {
        display: grid;
        width: 42px;
        height: 42px;
        place-items: center;
        border-radius: 13px;
        color: #fff;
        box-shadow: 0 8px 18px rgba(79, 70, 229, .2)
    }

    .asset-form-group-title {
        font-size: 14px;
        font-weight: 900;
        color: #0f172a
    }

    .asset-form-group-copy {
        font-size: 11px;
        color: #64748b;
        margin-top: 2px
    }

    .asset-form-group-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px
    }

    .asset-form-group-grid>* {
        min-width: 0
    }

    .asset-form-group-grid label {
        display: block;
        line-height: 1.35
    }

    @media(max-width:700px) {
        .asset-form-group-grid {
            grid-template-columns: 1fr
        }

        .asset-form-group-grid>* {
            grid-column: auto !important
        }
    }
</style>
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.querySelector('#assetModal form');
        if (!form) return;
        const groups = [
            ['Identification du matériel', 'Nature, marque, modèle et référence constructeur', 'fa-barcode', 'linear-gradient(135deg,#4f46e5,#2563eb)', ['category', 'brand', 'model', 'serial_number']],
            ['Affectation & site', 'Collaborateur, service et site issus de l’annuaire du personnel', 'fa-user-shield', 'linear-gradient(135deg,#0891b2,#0d9488)', ['assigned_user_id', 'department', 'location']],
            ['Configuration technique', 'Caractéristiques matérielles et environnement logiciel', 'fa-microchip', 'linear-gradient(135deg,#7c3aed,#9333ea)', ['cpu', 'ram_gb', 'storage_type', 'storage', 'gpu', 'operating_system']],
            ['Réseau & connectivité', 'Identifiants techniques du réseau interne', 'fa-network-wired', 'linear-gradient(135deg,#0284c7,#2563eb)', ['ip_address', 'mac_address']],
            ['Cycle de vie & acquisition', 'Achat, garantie, coût et état opérationnel', 'fa-arrows-rotate', 'linear-gradient(135deg,#d97706,#ea580c)', ['purchase_date', 'commissioned_at', 'warranty_end', 'acquisition_cost', 'supplier', 'condition_status']],
            ['Photo & observations', 'Éléments visuels et informations complémentaires', 'fa-camera', 'linear-gradient(135deg,#475569,#0f172a)', ['photo[]', 'notes']]
        ];
        const footer = form.querySelector(':scope > div:last-child');
        groups.forEach(([title, copy, icon, color, fields]) => {
            const section = document.createElement('section');
            section.className = 'asset-form-group';
            section.innerHTML = `<div class="asset-form-group-head"><span class="asset-form-group-icon" style="background:${color}"><i class="fas ${icon}"></i></span><div><div class="asset-form-group-title">${title}</div><div class="asset-form-group-copy">${copy}</div></div></div><div class="asset-form-group-grid"></div>`;
            const grid = section.querySelector('.asset-form-group-grid');
            fields.forEach(name => {
                const label = form.querySelector(`[name="${name}"]`)?.closest('label');
                if (label) grid.appendChild(label)
            });
            form.insertBefore(section, footer)
        })
    });
</script>
<style>.media-dropzone{grid-column:1/-1;border:2px dashed #cbd5e1;border-radius:18px;padding:18px;background:#f8fafc;text-align:center;cursor:pointer;transition:.2s}.media-dropzone.is-dragging{border-color:#6366f1;background:#eef2ff}.media-dropzone input[type=file]{position:absolute;width:1px;height:1px;opacity:0}.media-preview{display:grid;grid-template-columns:repeat(auto-fill,minmax(105px,1fr));gap:10px;margin-top:14px}.media-preview-item{position:relative;overflow:hidden;border:1px solid #e2e8f0;border-radius:13px;background:#fff;aspect-ratio:1}.media-preview-item img,.media-preview-item video{width:100%;height:100%;object-fit:cover}.media-preview-item span{position:absolute;inset:auto 5px 5px;padding:3px 6px;border-radius:7px;background:rgba(15,23,42,.78);color:#fff;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ip-mask{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));align-items:center;gap:7px;margin-top:6px}.ip-mask input{min-width:0;border:1px solid #e2e8f0;border-radius:11px;background:#f8fafc;padding:12px 5px;text-align:center;font-weight:700;outline:none}.ip-mask input:focus{border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12)}.incident-media-strip{display:flex;gap:7px;margin-top:10px}.incident-media-strip img,.incident-media-strip video{width:76px;height:58px;border-radius:9px;object-fit:cover;border:1px solid #e2e8f0}</style>
<script>document.addEventListener('DOMContentLoaded',()=>{const personnel=<?= json_encode(array_column($users,null,'id'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;const suppliers=<?= json_encode($suppliers,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;const assigned=document.querySelector('#assetModal [name="assigned_user_id"]');if(assigned?.tomselect){assigned.tomselect.on('change',value=>{const person=personnel[value]||{};const department=document.querySelector('#assetModal [name="department"]');const site=document.querySelector('#assetModal [name="location"]');if(department)department.value=person.department||'';if(site)site.value=person.site||''})}const supplier=document.querySelector('#assetModal [name="supplier"]');if(supplier&&window.TomSelect&&!supplier.tomselect)new TomSelect(supplier,{create:true,persist:false,maxOptions:null,options:suppliers.map(item=>({value:item.name,text:(item.code?item.code+' · ':'')+item.name})),items:supplier.value?[supplier.value]:[],valueField:'value',labelField:'text',searchField:['text'],placeholder:'Saisir ou rechercher un fournisseur…',render:{no_results:()=>'<div class="no-results">Aucun fournisseur — validez la saisie pour l’ajouter</div>'}});const ip=document.querySelector('#assetModal [name="ip_address"]');if(ip){const values=(ip.value||'').split('.');ip.type='hidden';const box=document.createElement('div');box.className='ip-mask';const parts=[0,1,2,3].map(index=>{const field=document.createElement('input');field.type='text';field.inputMode='numeric';field.maxLength=3;field.placeholder='000';field.value=values[index]||'';field.setAttribute('aria-label','Bloc '+(index+1)+' de l’adresse IP');box.appendChild(field);return field});const sync=()=>{ip.value=parts.every(field=>field.value!=='')?parts.map(field=>field.value).join('.'):''};parts.forEach((field,index)=>{field.addEventListener('input',()=>{field.value=field.value.replace(/\D/g,'').slice(0,3);if(Number(field.value)>255)field.value='255';sync();if(field.value.length===3&&parts[index+1])parts[index+1].focus()});field.addEventListener('keydown',event=>{if(event.key==='Backspace'&&!field.value&&parts[index-1])parts[index-1].focus()});field.addEventListener('paste',event=>{const pasted=event.clipboardData?.getData('text')||'';const found=pasted.match(/\d{1,3}/g);if(found?.length===4){event.preventDefault();found.forEach((value,i)=>parts[i].value=String(Math.min(255,Number(value))));sync()}})});ip.after(box)}const buildDropzone=(input,{video=false,existing=[]}={})=>{if(!input)return;const zone=document.createElement('div');zone.className='media-dropzone';zone.innerHTML=`<i class="fas fa-cloud-arrow-up text-3xl text-indigo-500"></i><strong class="mt-2 block text-sm text-slate-800">Glissez vos ${video?'photos ou vidéos':'photos'} ici</strong><span class="mt-1 block text-xs text-slate-500">ou cliquez pour parcourir · sélection multiple autorisée</span><div class="media-preview"></div>`;input.parentElement.insertAdjacentElement('afterend',zone);zone.appendChild(input);const preview=zone.querySelector('.media-preview');const addPreview=(url,name,type)=>{const item=document.createElement('div');item.className='media-preview-item';item.innerHTML=type==='video'?`<video src="${url}" controls preload="metadata"></video><span>${name}</span>`:`<img src="${url}" alt=""><span>${name}</span>`;preview.appendChild(item)};existing.forEach(item=>addPreview('../'+item.file_path,item.original_name||'Photo existante',item.media_type));const render=()=>{preview.querySelectorAll('[data-new]').forEach(el=>el.remove());Array.from(input.files||[]).forEach(file=>{const url=URL.createObjectURL(file);const before=preview.children.length;addPreview(url,file.name,file.type.startsWith('video/')?'video':'image');preview.children[before]?.setAttribute('data-new','')})};zone.addEventListener('click',event=>{if(!event.target.closest('video'))input.click()});['dragenter','dragover'].forEach(name=>zone.addEventListener(name,event=>{event.preventDefault();zone.classList.add('is-dragging')}));['dragleave','drop'].forEach(name=>zone.addEventListener(name,event=>{event.preventDefault();zone.classList.remove('is-dragging')}));zone.addEventListener('drop',event=>{if(event.dataTransfer?.files?.length){const transfer=new DataTransfer();Array.from(input.files||[]).forEach(file=>transfer.items.add(file));Array.from(event.dataTransfer.files).forEach(file=>transfer.items.add(file));input.files=transfer.files;render()}});input.addEventListener('change',render)};buildDropzone(document.querySelector('#assetModal [name="photo[]"]'),{existing:<?= json_encode($editMedia,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>});buildDropzone(document.querySelector('#incidentModal [name="incident_media[]"]'),{video:true});const incidentMedia=<?= json_encode($incidentMedia,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;document.querySelectorAll('[data-manage-incident]').forEach(button=>{const media=incidentMedia[button.dataset.manageIncident]||[];if(!media.length)return;const strip=document.createElement('div');strip.className='incident-media-strip';media.forEach(item=>{const node=document.createElement(item.media_type==='video'?'video':'img');node.src='../'+item.file_path;if(item.media_type==='video')node.controls=true;strip.appendChild(node)});button.closest('article')?.querySelector('p')?.insertAdjacentElement('afterend',strip)})});</script>
<script>document.addEventListener('DOMContentLoaded',()=>document.querySelectorAll('.media-dropzone input[type="file"]').forEach(input=>input.addEventListener('click',event=>event.stopPropagation())));</script>
<main class="mx-auto max-w-[1500px] space-y-6 px-5 py-6">
    <section class="finea-grid relative overflow-hidden rounded-[30px] bg-gradient-to-br from-slate-950 via-[#172554] to-indigo-900 p-7 text-white shadow-xl md:p-9">
        <div class="absolute -right-16 -top-20 h-64 w-64 rounded-full bg-cyan-400/15 blur-3xl"></div>
        <div class="relative flex flex-col justify-between gap-7 xl:flex-row xl:items-end">
            <div class="max-w-3xl">
                <div class="mb-4 inline-flex items-center gap-2 rounded-full border border-indigo-300/20 bg-white/10 px-3 py-1.5 text-xs font-bold uppercase tracking-[.22em] text-indigo-200"><i class="fas fa-shield-halved"></i>Patrimoine numérique maîtrisé</div>
                <h1 class="text-3xl font-black tracking-tight md:text-5xl">Parc informatique</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300 md:text-base">Inventoriez les équipements, surveillez leur état, traitez les incidents et conservez une traçabilité documentaire conforme aux exigences ISO 9001.</p>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:min-w-[720px] xl:grid-cols-5"><?php foreach ([['fa-plus', 'Nouveau matériel', 'assetModal', 'bg-indigo-500'], ['fa-file-import', 'Importer la base', 'importModal', 'bg-violet-500'], ['fa-bolt', 'Déclarer incident', 'incidentModal', 'bg-rose-500'], ['fa-qrcode', 'Étiquettes QR', 'labels.php', 'bg-cyan-500'], ['fa-file-export', 'Exports ISO', 'exportMenu', 'bg-emerald-500']] as [$icon, $label, $target, $color]): ?><?php if (str_ends_with($target, '.php')): ?><a target="_blank" href="<?= $target ?>" class="group rounded-2xl border border-white/10 bg-white/10 p-4 text-left backdrop-blur hover:bg-white/15"><?php else: ?><button type="button" data-open="<?= $target ?>" class="group rounded-2xl border border-white/10 bg-white/10 p-4 text-left backdrop-blur hover:bg-white/15"><?php endif; ?><span class="mb-3 grid h-10 w-10 place-items-center rounded-xl <?= $color ?> shadow-lg"><i class="fas <?= $icon ?>"></i></span><span class="text-sm font-black"><?= $label ?></span><?php if (str_ends_with($target, '.php')): ?></a><?php else: ?></button><?php endif; ?><?php endforeach; ?></div>
        </div>
    </section>
    <?php if ($flash): ?><div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800"><i class="fas fa-circle-check mr-2"></i><?= $h($flash) ?></div><?php endif; ?><?php if ($error): ?><div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-800"><i class="fas fa-triangle-exclamation mr-2"></i><?= $h($error) ?></div><?php endif; ?>
    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><?php foreach ([['fa-computer', 'Équipements', $stats['total'], 'indigo', 'Inventaire total'], ['fa-circle-check', 'En service', $stats['service'], 'emerald', 'Opérationnels'], ['fa-screwdriver-wrench', 'Maintenance', $stats['maintenance'], 'amber', 'À surveiller'], ['fa-triangle-exclamation', 'Incidents', $stats['incidents'], 'rose', 'Ouverts ou en cours']] as [$icon, $label, $value, $color, $sub]): ?><article class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="absolute right-0 top-0 h-20 w-20 rounded-bl-full bg-<?= $color ?>-50"></div>
                <div class="relative flex items-start justify-between"><span class="grid h-12 w-12 place-items-center rounded-2xl bg-<?= $color ?>-100 text-<?= $color ?>-700"><i class="fas <?= $icon ?>"></i></span><strong class="text-3xl tracking-tight"><?= $value ?></strong></div>
                <div class="mt-4">
                    <p class="font-black"><?= $label ?></p><small class="text-slate-500"><?= $sub ?></small>
                </div>
            </article><?php endforeach; ?></section>
    <section class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm">
        <div class="border-b border-slate-200 p-5 md:p-6">
            <div class="flex flex-col justify-between gap-4 xl:flex-row xl:items-center">
                <div>
                    <p class="text-xs font-black uppercase tracking-[.22em] text-indigo-600">Vue opérationnelle</p>
                    <h2 class="mt-1 text-2xl font-black">Matériel et état du parc</h2>
                    <p class="mt-1 text-sm text-slate-500"><?= count($assets) ?> équipement(s) affiché(s) sur <?= $stats['total'] ?></p>
                </div>
                <div class="flex flex-wrap gap-2"><button data-open="assetModal" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white"><i class="fas fa-plus mr-2"></i>Ajouter</button><button data-open="importModal" class="rounded-xl bg-violet-50 px-4 py-2.5 text-sm font-black text-violet-700"><i class="fas fa-file-import mr-2"></i>Importer</button><button data-open="incidentModal" class="rounded-xl bg-rose-50 px-4 py-2.5 text-sm font-black text-rose-700"><i class="fas fa-bolt mr-2"></i>Incident</button><a target="_blank" href="export.php?format=pdf&<?= $h($query) ?>" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black"><i class="fas fa-file-pdf mr-2 text-rose-600"></i>PDF ISO</a><a href="export.php?format=excel&<?= $h($query) ?>" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-black"><i class="fas fa-file-excel mr-2 text-emerald-600"></i>Excel ISO</a></div>
            </div>
            <form class="mt-5 grid gap-3 md:grid-cols-[1fr_220px_220px_auto]">
                <div class="relative"><i class="fas fa-search absolute left-4 top-3.5 text-slate-400"></i><input name="q" value="<?= $h($filters['q']) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 outline-none focus:border-indigo-500" placeholder="Code, série, utilisateur, marque, modèle..."></div><select name="category" class="rounded-xl border border-slate-200 bg-slate-50 px-4">
                    <option value="">Tous les types</option><?php foreach (ItAssetManager::categories() as $key => $label): ?><option value="<?= $key ?>" <?= $filters['category'] === $key ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?>
                </select><select name="status" class="rounded-xl border border-slate-200 bg-slate-50 px-4">
                    <option value="">Tous les états</option><?php foreach (ItAssetManager::conditions() as $key => $label): ?><option value="<?= $key ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?>
                </select><button class="rounded-xl bg-slate-950 px-5 py-3 font-black text-white">Appliquer</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1180px] text-left text-sm">
                <thead class="bg-[#172554] text-[11px] uppercase tracking-[.12em] text-white">
                    <tr>
                        <th class="px-5 py-4">Identification</th>
                        <th class="px-5 py-4">Affectation</th>
                        <th class="px-5 py-4">Configuration</th>
                        <th class="px-5 py-4">Cycle de vie</th>
                        <th class="px-5 py-4">Performance</th>
                        <th class="px-5 py-4">Incidents</th>
                        <th class="px-5 py-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100"><?php foreach ($assets as $a): $p = $perf[$a['performance_level']] ?? $perf['standard']; ?><tr class="group hover:bg-indigo-50/40">
                            <td class="px-5 py-4">
                                <div class="flex items-center gap-3"><?php if ($a['photo_path']): ?><img src="../<?= $h($a['photo_path']) ?>" class="h-14 w-14 rounded-2xl border object-cover"><?php else: ?><span class="grid h-14 w-14 place-items-center rounded-2xl bg-slate-100 text-slate-400"><i class="fas fa-laptop text-xl"></i></span><?php endif; ?><div><strong class="text-indigo-700"><?= $h($a['asset_code']) ?></strong>
                                        <p class="font-black"><?= $h(trim($a['brand'] . ' ' . $a['model'])) ?: 'Sans modèle' ?></p><small class="text-slate-500">N/S <?= $h($a['serial_number'] ?: '—') ?></small>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-4"><strong><?= $h($a['assigned_to'] ?: 'Non affecté') ?></strong><small class="mt-1 block text-slate-500"><?= $h(trim($a['department'] . ' · ' . $a['location'], ' ·') ?: 'Aucun service ou site') ?></small></td>
                            <td class="px-5 py-4"><strong><?= $h($a['cpu'] ?: 'Configuration non renseignée') ?></strong><small class="mt-1 block text-slate-500"><?= $h(($a['ram_gb'] ? (float)$a['ram_gb'] . ' Go RAM · ' : '') . trim($a['storage'] . ' ' . $a['storage_type'])) ?></small><small class="block text-slate-400"><?= $h($a['operating_system'] ?: 'OS non renseigné') ?></small></td>
                            <td class="px-5 py-4"><span class="rounded-full px-3 py-1.5 text-xs font-black <?= $statusColors[$a['condition_status']] ?? 'bg-slate-100' ?>"><?= $h(ItAssetManager::conditions()[$a['condition_status']] ?? $a['condition_status']) ?></span><small class="mt-2 block text-slate-500">Depuis <?= $a['commissioned_at'] ? date('d/m/Y', strtotime($a['commissioned_at'])) : '—' ?></small></td>
                            <td class="px-5 py-4"><span class="rounded-full px-3 py-1.5 text-xs font-black <?= $p[1] ?>"><?= $p[0] ?></span></td>
                            <td class="px-5 py-4"><strong class="<?= $a['open_incidents'] ? 'text-rose-600' : 'text-emerald-600' ?>"><i class="fas <?= $a['open_incidents'] ? 'fa-triangle-exclamation' : 'fa-circle-check' ?> mr-1"></i><?= (int)$a['open_incidents'] ?> actif(s)</strong></td>
                            <td class="px-5 py-4">
                                <div class="flex justify-end gap-2"><a href="?edit=<?= (int)$a['id'] ?>" class="grid h-10 w-10 place-items-center rounded-xl border bg-white text-indigo-700" title="Modifier"><i class="fas fa-pen"></i></a><a target="_blank" href="labels.php?ids=<?= (int)$a['id'] ?>" class="grid h-10 w-10 place-items-center rounded-xl border bg-white" title="Étiquette QR"><i class="fas fa-qrcode"></i></a><button data-incident-asset="<?= (int)$a['id'] ?>" class="grid h-10 w-10 place-items-center rounded-xl border bg-white text-rose-700" title="Déclarer un incident"><i class="fas fa-bolt"></i></button></div>
                            </td>
                        </tr><?php endforeach; ?><?php if (!$assets): ?><tr>
                            <td colspan="7" class="p-16 text-center text-slate-500"><i class="fas fa-box-open mb-3 block text-3xl text-slate-300"></i>Aucun matériel ne correspond aux filtres.</td>
                        </tr><?php endif; ?></tbody>
            </table>
        </div>
    </section>
    <section class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b p-5 md:p-6">
            <div>
                <p class="text-xs font-black uppercase tracking-[.22em] text-rose-600">Centre d’assistance</p>
                <h2 class="mt-1 text-xl font-black">Incidents récents</h2>
            </div><button data-open="incidentModal" class="rounded-xl bg-rose-600 px-4 py-2.5 text-sm font-black text-white"><i class="fas fa-plus mr-2"></i>Déclarer</button>
        </div>
        <div class="grid gap-3 p-5 md:grid-cols-2 xl:grid-cols-3"><?php foreach (array_slice($incidents, 0, 6) as $i): ?><article class="rounded-2xl border border-slate-200 p-4">
                    <div class="flex justify-between gap-3"><strong class="text-indigo-700"><?= $h($i['incident_code']) ?></strong><span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-black uppercase"><?= $h($i['status']) ?></span></div>
                    <h3 class="mt-2 font-black"><?= $h($i['title']) ?></h3>
                    <p class="mt-1 line-clamp-2 text-sm text-slate-500"><?= $h($i['description']) ?></p>
                    <div class="mt-3 flex items-center justify-between"><small class="font-bold text-slate-600"><?= $h($i['asset_code']) ?></small><button data-manage-incident="<?= (int)$i['id'] ?>" data-incident-code="<?= $h($i['incident_code']) ?>" data-incident-status="<?= $h($i['status']) ?>" data-incident-resolution="<?= $h($i['resolution']) ?>" class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-black text-slate-700"><i class="fas fa-list-check mr-1"></i>Traiter</button></div>
                </article><?php endforeach; ?><?php if (!$incidents): ?><p class="col-span-full p-8 text-center text-slate-500">Aucun incident enregistré.</p><?php endif; ?></div>
    </section>
</main>
<div id="assetModal" data-modal class="fixed inset-0 z-50 items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
    <div class="max-h-[94vh] w-full max-w-4xl overflow-y-auto rounded-[28px] bg-white shadow-2xl">
        <div class="sticky top-0 z-10 flex items-center justify-between border-b bg-white p-5">
            <div>
                <p class="text-xs font-black uppercase tracking-[.2em] text-indigo-600">Fiche d’inventaire</p>
                <h2 class="text-xl font-black"><?= $edit ? 'Modifier ' . $h($edit['asset_code']) : 'Nouveau matériel' ?></h2>
            </div><button data-close class="grid h-10 w-10 place-items-center rounded-xl bg-slate-100"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="post" enctype="multipart/form-data" class="grid gap-4 p-6 md:grid-cols-2"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_asset"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>"><label class="md:col-span-2 text-xs font-bold uppercase tracking-wide text-slate-500">Type de matériel<select name="category" class="mt-1.5 w-full rounded-xl border bg-slate-50 px-3.5 py-3 text-sm font-normal normal-case"><?php foreach (ItAssetManager::categories() as $key => $label): ?><option value="<?= $key ?>" <?= ($edit['category'] ?? 'PC') === $key ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label><?php $input('brand', 'Marque', $edit['brand'] ?? '');
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    $input('model', 'Modèle', $edit['model'] ?? '');
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    $input('serial_number', 'Numéro de série', $edit['serial_number'] ?? ''); ?><label class="md:col-span-2 text-xs font-bold uppercase tracking-wide text-slate-500">Utilisateur affecté<select name="assigned_user_id" class="mt-1.5 w-full rounded-xl border bg-slate-50 px-3.5 py-3 text-sm font-normal normal-case">
                    <option value="">Matériel non affecté</option><?php foreach ($users as $user): ?><option value="<?= (int)$user['id'] ?>" <?= ((int)($edit['assigned_user_id'] ?? 0) === (int)$user['id']) ? 'selected' : '' ?>><?= $h($user['name'] . ' · ' . $user['mail_pro']) ?></option><?php endforeach; ?>
                </select><span class="mt-1 block normal-case font-normal text-slate-400">L’affectation est reliée au compte actif de la base Finéa.</span></label><?php $input('department', 'Service', $edit['department'] ?? '');
                                                                                                                                                                    $input('location', 'Site', $edit['location'] ?? '');
                                                                                                                                                                    $input('cpu', 'Processeur', $edit['cpu'] ?? '', 'text', true);
                                                                                                                                                                    $input('ram_gb', 'RAM (Go)', $edit['ram_gb'] ?? '', 'number');
                                                                                                                                                                    $input('storage_type', 'Type de stockage', $edit['storage_type'] ?? '');
                                                                                                                                                                    $input('storage', 'Capacité / stockage', $edit['storage'] ?? '', 'text', true);
                                                                                                                                                                    $input('gpu', 'Carte graphique', $edit['gpu'] ?? '', 'text', true);
                                                                                                                                                                    $input('operating_system', 'Système d’exploitation', $edit['operating_system'] ?? '');
                                                                                                                                                                    $input('ip_address', 'Adresse IP', $edit['ip_address'] ?? '');
                                                                                                                                                                    $input('mac_address', 'Adresse MAC', $edit['mac_address'] ?? '');
                                                                                                                                                                    $input('purchase_date', 'Date d’achat', $edit['purchase_date'] ?? '', 'date');
                                                                                                                                                                    $input('commissioned_at', 'Mise en service', $edit['commissioned_at'] ?? '', 'date');
                                                                                                                                                                    $input('warranty_end', 'Fin de garantie', $edit['warranty_end'] ?? '', 'date');
                                                                                                                                                                    $input('acquisition_cost', 'Coût d’acquisition', $edit['acquisition_cost'] ?? '', 'number');
                                                                                                                                                                    ?><label class="text-xs font-bold uppercase tracking-wide text-slate-500">Fournisseur<input name="supplier" value="<?= $h($edit['supplier'] ?? '') ?>" class="mt-1.5 w-full rounded-xl border bg-slate-50 px-3.5 py-3 text-sm font-normal normal-case"></label><label class="text-xs font-bold uppercase tracking-wide text-slate-500">État<select name="condition_status" class="mt-1.5 w-full rounded-xl border bg-slate-50 px-3.5 py-3 text-sm font-normal normal-case"><?php foreach (ItAssetManager::conditions() as $key => $label): ?><option value="<?= $key ?>" <?= ($edit['condition_status'] ?? 'in_service') === $key ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label><label class="md:col-span-2 text-xs font-bold uppercase tracking-wide text-slate-500">Photos de l’équipement<input type="file" name="photo[]" multiple accept="image/jpeg,image/png,image/webp" class="mt-1.5 w-full rounded-xl border bg-slate-50 p-3 text-sm font-normal normal-case"></label><label class="md:col-span-2 text-xs font-bold uppercase tracking-wide text-slate-500">Observations<textarea name="notes" rows="3" class="mt-1.5 w-full rounded-xl border bg-slate-50 p-3 text-sm font-normal normal-case"><?= $h($edit['notes'] ?? '') ?></textarea></label>
            <div class="md:col-span-2 flex justify-end gap-3 border-t pt-5"><button type="button" data-close class="rounded-xl border px-5 py-3 font-bold">Annuler</button><button class="rounded-xl bg-indigo-600 px-6 py-3 font-black text-white"><i class="fas fa-floppy-disk mr-2"></i>Enregistrer</button></div>
        </form>
    </div>
</div>
<div id="incidentModal" data-modal class="fixed inset-0 z-50 items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
    <div class="max-h-[94vh] w-full max-w-xl overflow-y-auto rounded-[28px] bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b p-5">
            <div>
                <p class="text-xs font-black uppercase tracking-[.2em] text-rose-600">Assistance informatique</p>
                <h2 class="text-xl font-black">Déclarer un incident</h2>
            </div><button data-close class="grid h-10 w-10 place-items-center rounded-xl bg-slate-100"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="post" enctype="multipart/form-data" class="space-y-4 p-6"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="incident"><select id="incident_asset" name="asset_id" required class="w-full rounded-xl border bg-slate-50 p-3">
                <option value="">Choisir le matériel concerné</option><?php foreach ($allAssets as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $h($a['asset_code'] . ' · ' . $a['brand'] . ' ' . $a['model']) ?></option><?php endforeach; ?>
            </select><input name="title" required class="w-full rounded-xl border bg-slate-50 p-3" placeholder="Objet de l’incident"><textarea name="description" required rows="4" class="w-full rounded-xl border bg-slate-50 p-3" placeholder="Décrivez les symptômes et les circonstances"></textarea>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2"><select name="priority" class="rounded-xl border bg-slate-50 p-3">
                    <option value="low">Priorité faible</option>
                    <option value="medium" selected>Priorité moyenne</option>
                    <option value="high">Priorité haute</option>
                    <option value="critical">Priorité critique</option>
                </select><select name="assigned_to" class="rounded-xl border bg-slate-50 p-3" data-placeholder="Rechercher un technicien…"><option value="">Technicien non assigné</option><?php foreach ($technicians as $technician): ?><option value="<?= $h($technician['name']) ?>" <?= $technician['name'] === $defaultTechnician ? 'selected' : '' ?>><?= $h($technician['name'] . ' · ' . $technician['job_title']) ?></option><?php endforeach; ?></select></div><input name="reported_by" class="w-full rounded-xl border bg-slate-50 p-3" placeholder="Signalé par"><label class="block text-xs font-black uppercase tracking-wide text-slate-500">Photos et vidéos<input type="file" name="incident_media[]" multiple accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" class="mt-2 w-full rounded-xl border bg-slate-50 p-3 text-sm font-normal normal-case"></label>
            <div class="flex justify-end gap-3 border-t pt-5"><button type="button" data-close class="rounded-xl border px-5 py-3 font-bold">Annuler</button><button class="rounded-xl bg-rose-600 px-6 py-3 font-black text-white">Déclarer</button></div>
        </form>
    </div>
</div>
<div id="incidentManageModal" data-modal class="fixed inset-0 z-50 items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-[28px] bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b p-5">
            <div>
                <p class="text-xs font-black uppercase tracking-[.2em] text-indigo-600">Traitement technique</p>
                <h2 id="manageIncidentTitle" class="text-xl font-black">Mettre à jour l’incident</h2>
            </div><button data-close class="grid h-10 w-10 place-items-center rounded-xl bg-slate-100"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="post" class="space-y-4 p-6"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="incident_status"><input id="manageIncidentId" type="hidden" name="incident_id"><label class="block text-xs font-black uppercase tracking-wide text-slate-500">État du traitement<select id="manageIncidentStatus" name="status" class="mt-2 w-full rounded-xl border bg-slate-50 p-3 text-sm font-normal normal-case">
                    <option value="open">Ouvert</option>
                    <option value="in_progress">En cours</option>
                    <option value="resolved">Résolu</option>
                    <option value="closed">Clôturé</option>
                </select></label><label class="block text-xs font-black uppercase tracking-wide text-slate-500">Diagnostic et résolution<textarea id="manageIncidentResolution" name="resolution" rows="5" class="mt-2 w-full rounded-xl border bg-slate-50 p-3 text-sm font-normal normal-case" placeholder="Décrire les actions réalisées et la solution appliquée"></textarea></label>
            <div class="flex justify-end gap-3 border-t pt-5"><button type="button" data-close class="rounded-xl border px-5 py-3 font-bold">Annuler</button><button class="rounded-xl bg-indigo-600 px-6 py-3 font-black text-white"><i class="fas fa-check mr-2"></i>Mettre à jour</button></div>
        </form>
    </div>
</div>
<div id="importModal" data-modal class="fixed inset-0 z-50 items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
    <div class="w-full max-w-xl rounded-[28px] bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b p-5">
            <div>
                <p class="text-xs font-black uppercase tracking-[.2em] text-violet-600">Reprise de données</p>
                <h2 class="text-xl font-black">Importer la base du parc</h2>
            </div><button data-close class="grid h-10 w-10 place-items-center rounded-xl bg-slate-100"><i class="fas fa-xmark"></i></button>
        </div>
        <form method="post" enctype="multipart/form-data" class="space-y-5 p-6"><input type="hidden" name="_csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="import_assets">
            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 text-sm leading-6 text-indigo-900"><strong class="block"><i class="fas fa-circle-info mr-2"></i>Import Excel, XLSX ou CSV</strong>Les utilisateurs sont rapprochés automatiquement grâce à la colonne « Email utilisateur », puis liés à leur compte Finéa actif.</div><label class="block text-xs font-black uppercase tracking-wide text-slate-500">Fichier de données<input type="file" name="import_file" required accept=".xlsx,.xls,.csv" class="mt-2 w-full rounded-xl border bg-slate-50 p-3 text-sm font-normal normal-case"></label><a href="import_template.php" class="inline-flex items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-4 py-3 text-sm font-black text-violet-700"><i class="fas fa-download"></i>Télécharger le modèle d’import</a>
            <div class="flex justify-end gap-3 border-t pt-5"><button type="button" data-close class="rounded-xl border px-5 py-3 font-bold">Annuler</button><button class="rounded-xl bg-violet-600 px-6 py-3 font-black text-white"><i class="fas fa-file-import mr-2"></i>Lancer l’import</button></div>
        </form>
    </div>
</div>
<div id="exportMenu" data-modal class="fixed inset-0 z-50 items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-[28px] bg-white p-6 shadow-2xl">
        <div class="flex justify-between">
            <div>
                <p class="text-xs font-black uppercase tracking-[.2em] text-emerald-600">Documents maîtrisés</p>
                <h2 class="text-xl font-black">Exports ISO 9001</h2>
            </div><button data-close class="grid h-10 w-10 place-items-center rounded-xl bg-slate-100"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="mt-6 grid grid-cols-2 gap-3"><a target="_blank" href="export.php?format=pdf&<?= $h($query) ?>" class="rounded-2xl border p-5 hover:border-rose-300 hover:bg-rose-50"><i class="fas fa-file-pdf text-3xl text-rose-600"></i><strong class="mt-4 block">Registre PDF</strong><small class="text-slate-500">Gabarit ISO 9001</small></a><a href="export.php?format=excel&<?= $h($query) ?>" class="rounded-2xl border p-5 hover:border-emerald-300 hover:bg-emerald-50"><i class="fas fa-file-excel text-3xl text-emerald-600"></i><strong class="mt-4 block">Registre Excel</strong><small class="text-slate-500">Données filtrables</small></a></div>
    </div>
</div>
<script>
    const openModal = id => {
        const el = document.getElementById(id);
        if (el) {
            el.classList.add('open');
            document.body.classList.add('modal-open')
        }
    };
    const closeModal = el => {
        el.closest('[data-modal]')?.classList.remove('open');
        if (!document.querySelector('[data-modal].open')) document.body.classList.remove('modal-open')
    };
    document.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => openModal(b.dataset.open)));
    document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => closeModal(b)));
    document.querySelectorAll('[data-modal]').forEach(m => m.addEventListener('click', e => {
        if (e.target === m) closeModal(m)
    }));
    document.querySelectorAll('[data-incident-asset]').forEach(b => b.addEventListener('click', () => {
        document.getElementById('incident_asset').value = b.dataset.incidentAsset;
        openModal('incidentModal')
    }));
    document.querySelectorAll('[data-manage-incident]').forEach(b => b.addEventListener('click', () => {
        document.getElementById('manageIncidentId').value = b.dataset.manageIncident;
        document.getElementById('manageIncidentStatus').value = b.dataset.incidentStatus;
        document.getElementById('manageIncidentResolution').value = b.dataset.incidentResolution;
        document.getElementById('manageIncidentTitle').textContent = 'Traiter ' + b.dataset.incidentCode;
        openModal('incidentManageModal')
    }));
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') document.querySelectorAll('[data-modal].open').forEach(m => closeModal(m))
    });
    <?php if ($edit): ?>openModal('assetModal');
    <?php endif; ?>
</script><?php require __DIR__ . '/../decaissement/includes/footer.php'; ?>
