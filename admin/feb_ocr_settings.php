<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/FebOcrSettings.php';
require_once __DIR__ . '/../model/Database.php';

$settings = new \FebOcrSettings();
$current = $settings->load();
$reviewerOptions = Database::getConnection()->query("SELECT id,TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) name,mail_pro FROM user_devis_banamur WHERE active=1 ORDER BY prenom,nom")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$flash = null;
$flashType = 'success';
$actor = [
    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    'user_name' => isset($_SESSION['user_name']) ? (string) $_SESSION['user_name'] : null,
];

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    try {
        $enabled = !empty($_POST['feb_ocr_enabled']);
        $mode = (string)($_POST['verification_mode'] ?? 'ai');
        if(!in_array($mode,['ai','human','allow_all'],true))throw new RuntimeException('Mode de vérification invalide.');
        $reviewerIds = array_values(array_filter(array_map('intval',(array)($_POST['human_reviewer_ids']??[]))));
        if($mode==='human'&&!$reviewerIds)throw new RuntimeException('Sélectionnez au moins un validateur humain.');
        $autoEnabled=!empty($_POST['auto_accept_enabled']);
        $days=array_values(array_unique(array_map('intval',(array)($_POST['auto_accept_days']??[]))));
        $start=(string)($_POST['auto_accept_start']??'');$end=(string)($_POST['auto_accept_end']??'');
        if($autoEnabled&&(!$days||array_filter($days,static fn($day)=>$day<1||$day>7)||!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$start)||!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/',$end)||$start>=$end))throw new RuntimeException('Choisissez des jours et un créneau valide, avec une fin après le début.');
        $ok = $settings->save(['enabled' => $enabled, 'verification_mode'=>$mode, 'human_reviewer_ids'=>$reviewerIds,'auto_accept_enabled'=>$autoEnabled,'auto_accept_days'=>$days,'auto_accept_start'=>$start,'auto_accept_end'=>$end], $actor);
        if (!$ok) {
            throw new RuntimeException('Impossible d\'enregistrer le paramétrage (dossier storage non accessible).');
        }
        $current = $settings->load();
        $flash = 'Paramétrage enregistré.';
        $flashType = 'success';
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$pageTitle = 'Paramétrage OCR FEB';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

$enabledNow = !empty($current['enabled']);
$verificationMode = (string)($current['verification_mode'] ?? 'ai');
$selectedReviewerIds = array_map('intval',(array)($current['human_reviewer_ids']??[]));
$autoAcceptEnabled=!empty($current['auto_accept_enabled']);
$autoAcceptDays=array_map('intval',(array)($current['auto_accept_days']??[]));
$autoAcceptStart=(string)($current['auto_accept_start']??'08:00');
$autoAcceptEnd=(string)($current['auto_accept_end']??'18:00');
$updatedAt = trim((string) ($current['updated_at'] ?? ''));
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(219,234,254,0.95),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.82),_transparent_34%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <section class="overflow-hidden rounded-[32px] border border-slate-200/80 bg-white/90 shadow-[0_24px_80px_rgba(15,23,42,0.08)] backdrop-blur">
            <div class="bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-900 px-8 py-8 text-white">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.34em] text-cyan-200">Administration</p>
                        <h1 class="mt-3 text-3xl font-semibold tracking-tight md:text-4xl">Validation des justificatifs FEB</h1>
                        <p class="mt-4 text-sm text-slate-200 md:text-base">Choisissez la vérification IA, humaine ou l’acceptation automatique, avec des créneaux programmables.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="dashboard_admin.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-cyan-50"><i class="fas fa-gauge-high mr-2 text-cyan-600"></i>Dashboard admin</a>
                    </div>
                </div>
            </div>

            <div class="px-6 py-6 md:px-8 md:py-8">
                <?php if ($flash !== null): ?>
                    <div class="mb-6 rounded-2xl border px-4 py-4 text-sm font-semibold <?= $flashType === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>">
                        <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <div class="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
                    <section class="rounded-[28px] border border-slate-200 bg-slate-50/70 p-6">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-indigo-600">Réglage</p>
                                <h2 class="mt-2 text-2xl font-semibold text-slate-950">Mode de vérification</h2>
                            </div>
                            <span class="rounded-full px-4 py-2 text-xs font-semibold shadow-sm <?= $verificationMode === 'ai' ? 'bg-cyan-50 text-cyan-700' : 'bg-indigo-50 text-indigo-700' ?>">
                                <?= $verificationMode === 'ai' ? 'IA active' : ($verificationMode === 'human' ? 'Validation humaine' : 'Acceptation automatique') ?>
                            </span>
                        </div>

                        <div class="mt-4 rounded-2xl border border-slate-200 bg-white px-5 py-5 text-sm text-slate-700">
                            <div class="font-semibold text-slate-900">Impact</div>
                            <ul class="mt-2 list-disc pl-5 space-y-1">
                                <li>IA : le justificatif est analysé automatiquement dès son dépôt.</li>
                                <li>Humain : les validateurs sélectionnés sont prévenus et une seule approbation suffit.</li>
                                <li>Acceptation automatique : les justificatifs passent sans analyse OCR ni validation humaine.</li>
                            </ul>
                            <?php if ($updatedAt !== ''): ?>
                                <div class="mt-3 text-xs text-slate-500">Dernière mise à jour : <?= htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>

                        <form method="post" id="febVerificationSettingsForm" class="mt-6 space-y-5">
                            <input type="hidden" name="feb_ocr_enabled" value="1"><input type="hidden" id="verificationModeInput" name="verification_mode" value="<?=htmlspecialchars($verificationMode)?>">
                            <div class="rounded-[24px] border border-slate-200 bg-white p-5 shadow-sm"><div class="flex flex-wrap items-center justify-between gap-5"><div><div class="text-sm font-black text-slate-950">Mode de vérification</div><p id="verificationModeHelp" class="mt-1 text-sm text-slate-500"></p></div><label class="feb-mode-switch"><input id="verificationModeSwitch" type="checkbox" role="switch" aria-label="Basculer entre validation humaine et vérification IA" <?=$verificationMode==='ai'?'checked':''?>><span class="feb-mode-track"><span class="feb-mode-label human">HUMAIN</span><span class="feb-mode-thumb"></span><span class="feb-mode-label ai">IA</span></span></label></div></div>
                            <label class="feb-allow-card flex cursor-pointer items-center justify-between gap-4 rounded-[24px] border border-emerald-200 bg-emerald-50 p-5"><span class="flex min-w-0 items-center gap-3"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-emerald-100 text-lg text-emerald-700"><i class="fas fa-circle-check"></i></span><span><strong class="block text-sm text-emerald-950">Laisser passer tous les justificatifs</strong><span class="mt-1 block text-xs text-emerald-800">Aucun contrôle IA ou humain lorsque cette option est active.</span></span></span><span class="feb-allow-switch"><input id="allowAllSwitch" type="checkbox" role="switch" aria-label="Accepter tous les justificatifs" <?=$verificationMode==='allow_all'?'checked':''?>><span class="feb-allow-track"><span class="feb-allow-knob"></span></span></span></label>
                            <section id="humanReviewerPanel" class="rounded-[24px] border border-indigo-200 bg-indigo-50/60 p-5"><label for="reviewerSearch" class="block text-sm font-black text-slate-950">Validateurs humains</label><p class="mt-1 text-xs text-slate-500">Recherchez puis ajoutez les personnes. Une seule approbation suffira.</p><div class="feb-reviewer-combobox mt-3"><div id="selectedReviewers" class="flex flex-wrap gap-2"><span id="reviewerEmptyHint" class="px-2 py-1 text-sm text-slate-400">Aucun validateur sélectionné.</span></div><div class="relative"><i class="fas fa-search pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i><input id="reviewerSearch" type="search" autocomplete="off" placeholder="Rechercher par nom ou e-mail…" class="w-full border-0 bg-transparent py-2.5 pl-10 pr-3 text-sm outline-none focus:ring-0"><div id="reviewerResults" class="feb-reviewer-results" hidden></div></div></div></section>
                            <section class="rounded-[24px] border border-amber-200 bg-amber-50/60 p-5"><label class="feb-schedule-option flex cursor-pointer items-center gap-3 text-sm font-black text-slate-950"><input type="checkbox" name="auto_accept_enabled" value="1" <?=$autoAcceptEnabled?'checked':''?>><span class="feb-custom-check"><i class="fas fa-check"></i></span><span>Accepter automatiquement à certains jours et horaires</span></label><p class="mt-2 text-xs text-slate-600">Pendant ce créneau, l’acceptation automatique prend priorité sur le mode choisi ci-dessus. Heure d’Abidjan (UTC).</p><div class="mt-4 flex flex-wrap gap-2"><?php foreach([1=>'Lun',2=>'Mar',3=>'Mer',4=>'Jeu',5=>'Ven',6=>'Sam',7=>'Dim'] as $day=>$label):?><label class="feb-day-option"><input type="checkbox" name="auto_accept_days[]" value="<?=$day?>" <?=in_array($day,$autoAcceptDays,true)?'checked':''?>><span><?=$label?></span></label><?php endforeach;?></div><div class="mt-4 grid gap-3 sm:grid-cols-2"><label class="text-xs font-bold text-slate-700">Début <input type="time" name="auto_accept_start" value="<?=htmlspecialchars($autoAcceptStart,ENT_QUOTES,'UTF-8')?>" class="mt-1 block w-full rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm"></label><label class="text-xs font-bold text-slate-700">Fin <input type="time" name="auto_accept_end" value="<?=htmlspecialchars($autoAcceptEnd,ENT_QUOTES,'UTF-8')?>" class="mt-1 block w-full rounded-xl border border-amber-200 bg-white px-3 py-2 text-sm"></label></div></section>

                            <div class="flex flex-wrap gap-3">
                                <button type="submit" class="inline-flex items-center rounded-2xl bg-gradient-to-r from-cyan-600 to-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-cyan-900/20 transition hover:-translate-y-0.5 hover:from-cyan-700 hover:to-indigo-700"><i class="fas fa-save mr-2"></i>Enregistrer</button>
                            </div>
                        </form>
                    </section>

                    <section class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_16px_50px_rgba(15,23,42,0.05)]">
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Conseil</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Quel mode choisir ?</h2>
                        <div class="mt-5 space-y-3 text-sm text-slate-600">
                            <div class="rounded-2xl bg-slate-50 px-4 py-4"><strong class="text-slate-900">IA :</strong> recommandée pour une réponse immédiate lorsque le service d'analyse est disponible.</div>
                            <div class="rounded-2xl bg-slate-50 px-4 py-4"><strong class="text-slate-900">Humain :</strong> utile lorsqu'un contrôle manuel est nécessaire. Les personnes choisies reçoivent la demande en temps réel.</div>
                        </div>
                    </section>
                </div>
            </div>
        </section>
    </div>
</div>
<style>
.feb-mode-switch{cursor:pointer;user-select:none}.feb-mode-switch>input{position:absolute;opacity:0}.feb-mode-track{position:relative;display:grid;grid-template-columns:78px 42px 78px;align-items:center;height:48px;border-radius:999px;background:#0f172a;padding:4px;color:#fff}.feb-mode-thumb{position:absolute;left:5px;width:104px;height:38px;border-radius:999px;background:linear-gradient(135deg,#0891b2,#4f46e5);transition:transform .25s ease;box-shadow:0 7px 18px rgba(15,23,42,.28)}.feb-mode-switch input:checked+.feb-mode-track .feb-mode-thumb{transform:translateX(88px)}.feb-mode-switch input:focus-visible+.feb-mode-track{outline:3px solid #a5b4fc;outline-offset:3px}.feb-mode-label{z-index:1;text-align:center;font-size:11px;font-weight:900;letter-spacing:.08em}.feb-mode-label.ai{grid-column:3}.feb-mode-label.human{grid-column:1}.feb-reviewer-combobox{position:relative;border:1px solid #cbd5e1;border-radius:16px;background:#fff;padding:8px;transition:border-color .15s,box-shadow .15s}.feb-reviewer-combobox:focus-within{border-color:#818cf8;box-shadow:0 0 0 4px #e0e7ff}.feb-reviewer-results{position:absolute;z-index:30;top:calc(100% + 7px);left:-8px;right:-8px;max-height:260px;overflow:auto;border:1px solid #cbd5e1;border-radius:16px;background:#fff;padding:6px;box-shadow:0 20px 50px rgba(15,23,42,.16)}.feb-reviewer-result{display:block;width:100%;border:0;border-radius:12px;background:#fff;padding:11px 12px;text-align:left}.feb-reviewer-result:hover{background:#eef2ff}.feb-reviewer-result strong,.feb-reviewer-result small{display:block}.feb-reviewer-result small{margin-top:2px;color:#64748b}.feb-reviewer-chip{display:inline-flex;align-items:center;gap:8px;border-radius:999px;background:#e0e7ff;padding:7px 10px;color:#312e81;font-size:12px;font-weight:800}.feb-reviewer-chip button{display:grid;width:20px;height:20px;place-items:center;border:0;border-radius:999px;background:#c7d2fe;color:#3730a3}
.feb-mode-switch,.feb-allow-switch,.feb-schedule-option,.feb-day-option{position:relative}
.feb-mode-switch>input,.feb-allow-switch>input,.feb-schedule-option>input,.feb-day-option>input{position:absolute!important;left:0!important;top:0!important;width:1px!important;height:1px!important;margin:0!important;padding:0!important;border:0!important;opacity:0!important;appearance:none!important;pointer-events:none!important;clip-path:inset(50%)!important}
.feb-mode-track{width:206px}.feb-mode-switch:focus-within .feb-mode-track,.feb-allow-switch:focus-within .feb-allow-track,.feb-schedule-option:focus-within .feb-custom-check,.feb-day-option:focus-within span{outline:3px solid #818cf8;outline-offset:3px}
.feb-allow-card{transition:background .2s,border-color .2s,box-shadow .2s}.feb-allow-card:has(input:checked){border-color:#34d399;background:#ecfdf5;box-shadow:0 8px 28px rgba(5,150,105,.12)}
.feb-allow-track{display:block;position:relative;width:72px;height:40px;flex:none;border-radius:999px;background:#cbd5e1;box-shadow:inset 0 2px 5px rgba(15,23,42,.13);transition:background .2s}.feb-allow-knob{position:absolute;top:5px;left:5px;width:30px;height:30px;border-radius:50%;background:#fff;box-shadow:0 3px 9px rgba(15,23,42,.18);transition:transform .2s}.feb-allow-switch input:checked+.feb-allow-track{background:#059669}.feb-allow-switch input:checked+.feb-allow-track .feb-allow-knob{transform:translateX(32px)}
.feb-custom-check{display:grid;flex:none;place-items:center;width:28px;height:28px;border:2px solid #d97706;border-radius:9px;background:#fff;color:transparent;transition:background .2s,color .2s}.feb-schedule-option input:checked+.feb-custom-check{background:#d97706;color:#fff}
.feb-day-option{display:inline-flex;cursor:pointer}.feb-day-option span{display:grid;min-width:55px;min-height:46px;place-items:center;border:1px solid #fcd34d;border-radius:14px;background:#fff;padding:8px 12px;color:#92400e;font-size:13px;font-weight:800;transition:background .2s,color .2s,transform .2s}.feb-day-option:hover span{transform:translateY(-2px)}.feb-day-option input:checked+span{border-color:#d97706;background:#d97706;color:#fff;box-shadow:0 7px 16px rgba(180,83,9,.18)}
</style>
<script>
(()=>{const people=<?=json_encode(array_map(static fn($r)=>['id'=>(int)$r['id'],'name'=>(string)$r['name'],'email'=>(string)$r['mail_pro']],$reviewerOptions),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,initial=<?=json_encode($selectedReviewerIds)?>.map(Number),selected=new Map(),switcher=document.getElementById('verificationModeSwitch'),mode=document.getElementById('verificationModeInput'),help=document.getElementById('verificationModeHelp'),panel=document.getElementById('humanReviewerPanel'),search=document.getElementById('reviewerSearch'),results=document.getElementById('reviewerResults'),chips=document.getElementById('selectedReviewers'),empty=document.getElementById('reviewerEmptyHint'),form=document.getElementById('febVerificationSettingsForm'),esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));people.forEach(p=>{if(initial.includes(Number(p.id)))selected.set(Number(p.id),p)});
function render(){chips.querySelectorAll('.feb-reviewer-chip,input').forEach(n=>n.remove());empty.hidden=selected.size>0;selected.forEach(p=>{const chip=document.createElement('span');chip.className='feb-reviewer-chip';chip.innerHTML=`${esc(p.name)}<button type="button" aria-label="Retirer ${esc(p.name)}" data-remove="${Number(p.id)}">×</button><input type="hidden" name="human_reviewer_ids[]" value="${Number(p.id)}">`;chips.appendChild(chip)})}
const allowAll=document.getElementById('allowAllSwitch');function syncMode(){const ai=switcher.checked,all=allowAll.checked;mode.value=all?'allow_all':(ai?'ai':'human');panel.hidden=all||ai;help.textContent=all?'Acceptation automatique : aucun contrôle OCR ou humain.':(ai?'ON · Vérification automatique par FIDEST IA.':'OFF · Vérification en temps réel par les humains sélectionnés.');switcher.style.opacity=all?'.45':'1'}
function show(){const q=search.value.trim().toLocaleLowerCase('fr'),list=people.filter(p=>!selected.has(Number(p.id))&&(!q||(p.name+' '+p.email).toLocaleLowerCase('fr').includes(q))).slice(0,12);results.innerHTML=list.length?list.map(p=>`<button type="button" class="feb-reviewer-result" data-id="${Number(p.id)}"><strong>${esc(p.name)}</strong><small>${esc(p.email)}</small></button>`).join(''):'<div class="p-3 text-sm text-slate-400">Aucun résultat.</div>';results.hidden=false}
switcher.addEventListener('change',syncMode);allowAll.addEventListener('change',syncMode);search.addEventListener('focus',show);search.addEventListener('input',show);results.addEventListener('click',e=>{const b=e.target.closest('[data-id]');if(!b)return;const p=people.find(x=>Number(x.id)===Number(b.dataset.id));if(p)selected.set(Number(p.id),p);search.value='';results.hidden=true;render();search.focus()});chips.addEventListener('click',e=>{const b=e.target.closest('[data-remove]');if(!b)return;selected.delete(Number(b.dataset.remove));render()});document.addEventListener('click',e=>{if(!search.contains(e.target)&&!results.contains(e.target))results.hidden=true});form.addEventListener('submit',e=>{if(mode.value==='human'&&!selected.size){e.preventDefault();search.focus();alert('Sélectionnez au moins un validateur humain.')}});render();syncMode()})();
</script>
