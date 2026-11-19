<?php
require_once __DIR__.'/../services/InternationalBusinessIdentity.php';
$entryData=is_array($supplierFiscalIdentity??null)?$supplierFiscalIdentity:[];
$entryClient=!empty($businessClientForm);
$entryType=(string)($entryData[$entryClient?'client_type':'supplier_type']??'morale');
$entryCountry=strtoupper((string)($entryData['fiscal_country']??'CI'));
$entryKnown=!empty($entryData['id_client'])||!empty($entryData['id_fournisseur'])||isset($entryData['fiscal_country'])||isset($entryData['business_profile']);
$entryProfile=$entryKnown?($entryType==='physique'?'person':($entryCountry==='CI'?'ci':($entryCountry==='FR'?'fr':($entryCountry==='US'?'us':(in_array($entryCountry,InternationalBusinessIdentity::EU,true)?'eu':'foreign'))))):'';
$entryUrl=function_exists('devis_module_root_url')?devis_module_root_url('assets/js/business-identity-entry.js'):($supplierCreateAssetHrefPrefix??'../').'assets/js/business-identity-entry.js';
$entryNames=['CI'=>'Côte d’Ivoire','FR'=>'France','DE'=>'Allemagne','US'=>'États-Unis','IE'=>'Irlande','GB'=>'Royaume-Uni','CA'=>'Canada','CH'=>'Suisse','BE'=>'Belgique','SN'=>'Sénégal','BF'=>'Burkina Faso','ML'=>'Mali','GN'=>'Guinée','TG'=>'Togo','BJ'=>'Bénin'];
?>
<style>[data-party-entry]{padding:22px;margin:0 0 20px;border:2px solid #bfdbfe;border-radius:18px;background:#eff6ff;color:#172554}[data-party-entry] .entry-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}[data-party-entry] label{font-size:14px;font-weight:650}[data-party-entry] select{display:block;width:100%;box-sizing:border-box;padding:12px;margin-top:7px;border:1px solid #93c5fd;border-radius:10px;background:white;color:#172554}[data-party-entry] p{margin:12px 0 0;font-size:13px}[data-party-entry][data-pending="1"]~:not(input[type=hidden]):not(script){display:none!important}[data-old-type-controls]{display:none!important}[hidden]{display:none!important}</style>
<fieldset data-party-entry data-pending="<?= $entryProfile===''?'1':'0' ?>">
<legend>1. Quel <?= $entryClient?'client':'fournisseur' ?> souhaitez-vous enregistrer ?</legend>
<div class="entry-grid">
<label>Profil du tiers *<select name="business_profile" data-business-profile required>
<option value="">Choisissez d’abord le profil</option>
<?php foreach(['ci'=>'Entreprise ivoirienne','fr'=>'Entreprise française','eu'=>'Entreprise de l’Union européenne','us'=>'Entreprise américaine','foreign'=>'Entreprise d’un autre pays','person'=>$entryClient?'Particulier / personne physique':'Personne physique / prestataire indépendant'] as $key=>$label): ?>
<option value="<?= $key ?>" <?= $entryProfile===$key?'selected':'' ?>><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?>
</select></label>
<label>Pays fiscal *<select name="fiscal_country" data-business-country required><option value="">Sélectionnez le pays</option>
<?php foreach(explode(' ',InternationalBusinessIdentity::COUNTRIES) as $code): ?><option value="<?= $code ?>" <?= $entryKnown&&$entryCountry===$code?'selected':'' ?>><?= htmlspecialchars($entryNames[$code]??$code,ENT_QUOTES,'UTF-8') ?></option><?php endforeach; ?>
</select></label>
</div>
<?php if($entryClient): ?><input type="hidden" name="client_type" value="<?= $entryType==='physique'?'physique':'morale' ?>"><?php endif; ?>
<p data-entry-help>Choisissez le profil : le formulaire et le registre à consulter seront adaptés automatiquement.</p>
</fieldset>
<script src="<?= htmlspecialchars($entryUrl,ENT_QUOTES,'UTF-8') ?>?v=<?= filemtime(__DIR__.'/../assets/js/business-identity-entry.js') ?>" defer></script>
