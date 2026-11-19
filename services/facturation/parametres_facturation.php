<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/TaxRates.php';
require_once __DIR__ . '/../services/CurrencySettingsService.php';

$pdo = Database::getConnection();
$currencyService=new CurrencySettingsService($pdo);
$pageTitle = 'Parametres facturation';
$activeMenu = 'facturation';

$defaultTaxRates = [
    'TVA' => 0.18,
    'TVAB' => 0.00,
    'TVAC' => 0.00,
    'TVAD' => 0.00,
];

$flashSuccess = '';
$flashError = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $settingsAction=(string)($_POST['settings_action']??'taxes');
    if($settingsAction==='currencies'){
        try{
            $rows=is_array($_POST['currencies']??null)?$_POST['currencies']:[];
            $currencyService->save($rows,(string)($_POST['default_currency']??'XOF'),(int)($_SESSION['user_id']??0));
            $flashSuccess='Paramètres des devises enregistrés.';
            AuditTrail::log('facturation_currencies_saved',['module'=>'facturation','action'=>'save_currencies','entity_type'=>'currency_settings','details'=>['default_currency'=>$_POST['default_currency']??'XOF']],$pdo);
        }catch(Throwable $e){$flashError=$e->getMessage();}
    } else {
    $incoming = $_POST['tax_rates'] ?? [];
    if (!is_array($incoming)) {
        $flashError = 'Taux invalides.';
    } else {
        $rates = [];
        foreach ($defaultTaxRates as $code => $default) {
            $raw = str_replace(',', '.', trim((string)($incoming[$code] ?? $default)));
            if ($raw === '' || !is_numeric($raw)) {
                $flashError = 'Chaque taux doit etre numerique. Exemple: 0.18 pour 18%.';
                break;
            }
            $rate = (float)$raw;
            if ($rate < 0 || $rate > 1) {
                $flashError = 'Chaque taux doit etre compris entre 0 et 1.';
                break;
            }
            $rates[$code] = $rate;
        }

        if ($flashError === '') {
            try {
                TaxRates::setRates($pdo, $rates);
                \AuditTrail::log('facturation_tax_rates_saved', [
                    'module' => 'facturation',
                    'action' => 'save_tax_rates',
                    'entity_type' => 'tax_rates',
                    'entity_label' => 'parametres_facturation',
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'details' => ['rates' => $rates],
                ], $pdo);
                $flashSuccess = 'Parametres de taxes enregistres.';
            } catch (Throwable $e) {
                $flashError = 'Impossible d enregistrer les taux.';
            }
        }
    }
    }
}

try {
    $taxRates = TaxRates::getRates($pdo, $defaultTaxRates);
} catch (Throwable $e) {
    $taxRates = $defaultTaxRates;
}
$currencies=$currencyService->all();$defaultCurrency=(string)($currencyService->default()['code']??'XOF');

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-slate-50 pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-indigo-600">Facturation</p>
                <h1 class="mt-2 text-3xl font-semibold text-slate-950">Parametres de facturation</h1>
                <p class="mt-2 max-w-2xl text-sm text-slate-600">Configurez les taxes et les devises. Tous les taux de change indiquent la valeur d’une unité de devise en XOF.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="dashboard_facturation.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Dashboard</a>
                <a href="factures_vente.php" class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Factures</a>
            </div>
        </div>

        <?php if ($flashSuccess): ?>
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"><?= htmlspecialchars($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900"><?= htmlspecialchars($flashError) ?></div>
        <?php endif; ?>

        <form method="post" class="mt-8 rounded-[28px] border border-indigo-100 bg-white p-6 shadow-sm">
            <input type="hidden" name="settings_action" value="currencies">
            <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-black uppercase tracking-[.18em] text-indigo-600">Référentiel monétaire</p><h2 class="mt-1 text-xl font-black text-slate-950">Devises et taux vers XOF</h2><p class="mt-2 text-sm text-slate-500">Exemple : 1 EUR = 655,957 XOF. Le taux XOF est verrouillé à 1.</p></div><label class="min-w-64"><span class="text-sm font-bold text-slate-700">Devise par défaut</span><select name="default_currency" data-finea-search data-placeholder="Rechercher une devise…" class="mt-2 w-full rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 font-bold"><?php foreach($currencies as $currency):if(empty($currency['is_active']))continue;?><option value="<?=htmlspecialchars((string)$currency['code'])?>" <?=((string)$currency['code']===$defaultCurrency)?'selected':''?>><?=htmlspecialchars((string)$currency['code'].' — '.(string)$currency['label'])?></option><?php endforeach;?></select></label></div>
            <div id="currencyRows" class="mt-6 space-y-3"><?php foreach($currencies as $index=>$currency):?><div class="currency-row grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-[100px_1fr_110px_180px_100px]"><input name="currencies[<?=$index?>][code]" maxlength="3" required value="<?=htmlspecialchars((string)$currency['code'])?>" class="rounded-xl border px-3 py-2 font-black uppercase" <?=((string)$currency['code']==='XOF')?'readonly':''?>><input name="currencies[<?=$index?>][label]" required value="<?=htmlspecialchars((string)$currency['label'])?>" class="rounded-xl border px-3 py-2" placeholder="Libellé"><input name="currencies[<?=$index?>][symbol]" value="<?=htmlspecialchars((string)$currency['symbol'])?>" class="rounded-xl border px-3 py-2" placeholder="Symbole"><label><span class="block text-[11px] font-bold text-slate-500">1 <?=htmlspecialchars((string)$currency['code'])?> en XOF</span><input name="currencies[<?=$index?>][rate_to_xof]" type="number" min="0.000001" step="0.000001" required value="<?=htmlspecialchars((string)$currency['rate_to_xof'])?>" class="mt-1 w-full rounded-xl border px-3 py-2" <?=((string)$currency['code']==='XOF')?'readonly':''?>></label><label class="flex items-center justify-center gap-2 rounded-xl bg-white px-3"><input type="checkbox" name="currencies[<?=$index?>][is_active]" value="1" <?=!empty($currency['is_active'])?'checked':''?> <?=((string)$currency['code']==='XOF')?'disabled checked':''?>> Active<?php if((string)$currency['code']==='XOF'):?><input type="hidden" name="currencies[<?=$index?>][is_active]" value="1"><?php endif;?></label></div><?php endforeach;?></div>
            <div class="mt-5 flex flex-wrap justify-between gap-3"><button type="button" id="addCurrency" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-bold text-indigo-700">+ Ajouter une devise</button><button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white">Enregistrer les devises</button></div>
        </form>

        <form method="post" class="mt-8 rounded-[28px] border border-white bg-white p-6 shadow-sm">
            <input type="hidden" name="settings_action" value="taxes">
            <div class="mb-5"><p class="text-xs font-black uppercase tracking-[.18em] text-indigo-600">Fiscalité</p><h2 class="mt-1 text-xl font-black">Taux de taxes</h2></div>
            <div class="grid gap-4 md:grid-cols-2">
                <?php foreach ($defaultTaxRates as $code => $default): ?>
                    <?php $value = (float)($taxRates[$code] ?? $default); ?>
                    <label class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <span class="block text-sm font-semibold text-slate-900"><?= htmlspecialchars($code) ?></span>
                        <span class="mt-1 block text-xs text-slate-500">Saisir en decimal: 0.18 = 18%, 0 = exonere.</span>
                        <input name="tax_rates[<?= htmlspecialchars($code) ?>]" value="<?= htmlspecialchars((string)$value) ?>" class="mt-3 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-indigo-400" />
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="mt-6 flex justify-end">
                <button class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">Enregistrer les parametres</button>
            </div>
        </form>
    </div>
</div>

<script>(()=>{let index=<?=count($currencies)?>;document.getElementById('addCurrency')?.addEventListener('click',()=>{const row=document.createElement('div');row.className='currency-row grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-[100px_1fr_110px_180px_100px]';row.innerHTML=`<input name="currencies[${index}][code]" maxlength="3" required class="rounded-xl border px-3 py-2 font-black uppercase" placeholder="USD"><input name="currencies[${index}][label]" required class="rounded-xl border px-3 py-2" placeholder="Nom de la devise"><input name="currencies[${index}][symbol]" class="rounded-xl border px-3 py-2" placeholder="Symbole"><input name="currencies[${index}][rate_to_xof]" type="number" min="0.000001" step="0.000001" required class="rounded-xl border px-3 py-2" placeholder="Taux vers XOF"><label class="flex items-center justify-center gap-2 rounded-xl bg-white"><input type="checkbox" name="currencies[${index}][is_active]" value="1" checked> Active</label>`;document.getElementById('currencyRows').appendChild(row);index++;});})();</script>

<script src="assets/finea-search-combobox.js"></script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
