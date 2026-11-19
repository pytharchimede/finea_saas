<?php
require_once __DIR__.'/../services/InternationalBusinessIdentity.php';
require_once __DIR__.'/../services/SupplierExceptionPolicy.php';
$canSupplierException=empty($businessClientForm) && SupplierExceptionPolicy::allowed();
if ($canSupplierException) $_SESSION['supplier_exception_csrf']??=bin2hex(random_bytes(32));
$v = static fn(string $k, string $default=''): string => htmlspecialchars((string)($supplierFiscalIdentity[$k] ?? $default),ENT_QUOTES,'UTF-8');
$statusLabels=['registry_verified'=>'Confirmée par registre','documentary_pending'=>'Justificatifs en attente de validation','documentary_verified'=>'Validée sur justificatifs','unverified'=>'À vérifier','exception_approved'=>'Dérogation autorisée — contrôles non effectués'];
$country = (string)($supplierFiscalIdentity['fiscal_country'] ?? 'CI');
$method = (string)($supplierFiscalIdentity['identity_method'] ?? ($country==='FR'?'fr':'manual'));
?>
<style>[data-business-identity]{padding:18px;margin:16px 0;border:1px solid #dce5ef;border-radius:14px;background:#f8fafc;color:#334155}[data-business-identity]>.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:14px}[data-business-identity] label{display:block;font-size:13px}[data-business-identity] input:not([type=checkbox]),[data-business-identity] select{display:block;box-sizing:border-box;width:100%;margin-top:6px;padding:10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}[data-business-identity] [hidden]{display:none!important}[data-business-identity] p{font-size:12px;line-height:1.5}</style>
<fieldset data-identity-details class="mt-4 rounded-xl border border-gray-200 bg-white p-4" data-business-identity>
<legend>2. Vérification et récupération des informations</legend>
<div class="grid grid-cols-1 gap-4 md:grid-cols-2" data-business-fields>

<label <?= empty($businessClientForm)?'data-supplier-mode':'data-international-field' ?>>Mode d’identification<select name="identity_method" class="mt-1 w-full rounded-xl border px-3 py-2">
<?php foreach (['fne'=>'FNE / NCC — Côte d’Ivoire','fr'=>'Registre français (SIRET/SIREN)','vies'=>'TVA européenne — VIES','sec'=>'Registre américain SEC (CIK)','manual'=>'Validation sur justificatifs'] as $key=>$label): ?>
<option value="<?= $key ?>" <?= $method===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?>
<?php if ($canSupplierException): ?><option value="exception" <?= $method==='exception'?'selected':'' ?>>Fournisseur dérogatoire — autorisation interne</option><?php endif; ?>
</select></label>
<?php if (empty($businessClientForm)): ?>
<label data-primary-identity hidden><span data-primary-identity-label>Identifiant à rechercher</span><input name="identity_search" value="<?= $v('identity_search') ?>" autocomplete="off" class="mt-1 w-full rounded-xl border px-3 py-2"></label>
<?php endif; ?>
<?php if ($canSupplierException): ?>
<input type="hidden" name="_supplier_exception_csrf" value="<?= htmlspecialchars($_SESSION['supplier_exception_csrf'],ENT_QUOTES,'UTF-8') ?>">
<label data-exception-only hidden>Motif de la dérogation (obligatoire)<textarea name="exception_reason" maxlength="500" rows="3" placeholder="Expliquez pourquoi ce fournisseur est intégré sans les contrôles et pièces habituels."><?= $method==='exception'?$v('identity_evidence'):'' ?></textarea></label>
<p data-exception-only hidden>Nom et motif obligatoires. Pour une personne physique, le numéro et la pièce d’identité restent obligatoires. L’autorisation est enregistrée avec votre compte et sa date. Elle ne constitue pas une vérification fiscale.</p>
<?php endif; ?>
<label data-fr-field hidden>SIRET ou SIREN<input name="siret" value="<?= $v('siret') ?>" inputmode="numeric" class="mt-1 w-full rounded-xl border px-3 py-2"></label>
<label data-registration-field hidden>Type d’identifiant<input name="registration_type" list="business-id-types" value="<?= $v('registration_type','LOCAL') ?>" maxlength="30" class="mt-1 w-full rounded-xl border px-3 py-2"></label>
<datalist id="business-id-types"><option>EIN</option><option>CIK</option><option>HRB</option><option>HRA</option><option>LOCAL</option></datalist>
<label data-registration-field hidden>Numéro d’immatriculation / fiscal<input name="registration_number" maxlength="100" value="<?= $v('registration_number') ?>" class="mt-1 w-full rounded-xl border px-3 py-2"></label>
<label data-international-field hidden>TVA (avec préfixe du pays)<input name="vat_number" maxlength="20" value="<?= $v('vat_number') ?>" class="mt-1 w-full rounded-xl border px-3 py-2"></label>
<label data-international-field hidden>Justificatif — référence et emplacement<input name="identity_evidence" maxlength="500" value="<?= $v('identity_evidence') ?>" placeholder="Extrait du registre, facture, dossier documentaire…" class="mt-1 w-full rounded-xl border px-3 py-2"></label>
</div>
<p data-international-field class="mt-3 text-sm text-gray-600">VIES vérifie la TVA ; la validation de l’identité reste documentaire. La SEC couvre uniquement ses déclarants : utilisez le CIK exact de l’entité facturante. Pour les autres entreprises, renseignez l’identifiant local et le justificatif.</p>
<p data-international-field class="mt-3 text-sm text-gray-600">Si VIES est indisponible, choisissez « Validation sur justificatifs », puis renseignez le type et le numéro du registre local (ex. HRB pour une société allemande), l’adresse légale et une référence permettant de retrouver la facture ou l’extrait du registre. Ce mode ne confirme pas la TVA.</p>
<?php if(empty($businessClientForm)): ?><p data-international-field class="mt-3 text-sm text-gray-600">Enregistrez d’abord la fiche, puis faites valider le justificatif par un utilisateur habilité avec le bouton « Valider ». Les attestations fiscales et sociales et la pièce du représentant de la société sont facultatives ; elles ne remplacent pas la référence du justificatif d’identité.</p><?php endif; ?>
<p class="mt-3 text-sm" data-identity-summary>État du contrôle : <?= htmlspecialchars($statusLabels[$supplierFiscalIdentity['identity_status']??'unverified']??'À vérifier') ?> · TVA : <?= ($supplierFiscalIdentity['vat_status']??'')==='valid'?'confirmée par VIES':'non vérifiée' ?></p>
<?php if (!empty($businessClientForm) && (AccessManager::hasPermission('acces_admin') || AccessManager::hasPermission('acces_compta'))): ?>
<label data-international-field hidden><input type="checkbox" name="documentary_approve" value="1"> J’ai contrôlé le justificatif et j’approuve l’identité de ce client.</label>
<?php endif; ?>
<?php if(!empty($businessClientForm)): ?>
<div data-personal-client-proof hidden><p>Particulier : saisissez le nom complet, l’adresse et un contact. Aucun registre d’entreprise n’est interrogé. Une validation documentaire est nécessaire avant utilisation dans les opérations.</p></div>
<div data-business-lookup-host></div>
<?php endif; ?>
</fieldset>

