<?php
declare(strict_types=1);

require_once __DIR__.'/../devis/includes/module_bootstrap.php';
require_once __DIR__.'/../model/AuditTrail.php';
require_once __DIR__.'/../services/ClientIdentityService.php';

if (!devis_module_has_access('acces_liste_client')) AccessManager::denyAccess('acces_liste_client', 'clients/ajouter.php');
$pdo = Database::getConnection();
$error = '';
$_SESSION['client_create_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['client_create_csrf'], (string)($_POST['_csrf'] ?? ''))) throw new RuntimeException('Session expirée.');
        $service = new ClientIdentityService($pdo);
        $id = $service->save($_POST, null, (int)($_SESSION['user_id'] ?? 0), AccessManager::hasPermission('acces_admin') || AccessManager::hasPermission('acces_compta'));
        AuditTrail::log('client_created', ['module'=>'clients','action'=>'create','entity_type'=>'client','entity_id'=>(string)$id,'entity_label'=>(string)($_POST['nom_client'] ?? '')], $pdo);
        header('Location: '.devis_module_root_url('clients/dashboard.php?created=1'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$modules=$devisModuleController->modules();$devisModuleNav=$modules;$activeDevisModule='clients';$moduleTitle='Nouveau client';$moduleSubtitle='Vérifier l’identité fiscale avant de créer une fiche unique.';$root=static fn(string $path):string=>devis_module_root_url($path);
ob_start(); ?>
<link rel="stylesheet" href="<?=htmlspecialchars($root('assets/css/client-platform.css'),ENT_QUOTES,'UTF-8')?>">
<style>
.cf-card{max-width:980px;border:1px solid #dfe6f0;border-radius:16px;background:#fff;padding:24px;box-shadow:0 12px 30px rgba(23,35,72,.06)}.cf-head{display:flex;gap:14px;align-items:center}.cf-head h2{margin:0;color:#172348;font-size:18px}.cf-head p{margin:5px 0 0;color:#64748b;font-size:12px}.cf-icon{display:grid;width:48px;height:48px;place-items:center;border-radius:13px;background:#e6fffb;color:#0f766e;font-size:19px}.cf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px;margin-top:23px}.cf-field label{display:block;margin-bottom:6px;color:#34425a;font-size:11px;font-weight:900}.cf-field input{width:100%;border:1px solid #cfd8e6;border-radius:9px;padding:11px 12px;outline:none}.cf-field input:focus{border-color:#1d4ed8;box-shadow:0 0 0 4px #dbeafe}.cf-field input[readonly]{background:#f7f9fc;color:#475569}.cf-wide{grid-column:1/-1}.cf-ncc-row{display:flex;align-items:center;gap:10px}.cf-ncc-row input{min-width:0;flex:1}.cf-load{flex:none;border:0;border-radius:9px;background:#0f766e;color:#fff;padding:12px 16px;font-size:12px;font-weight:900;cursor:pointer}.cf-load:hover{background:#115e59}.cf-load:disabled{cursor:wait;opacity:.6}.cf-actions{display:flex;justify-content:space-between;gap:10px;margin-top:22px}.cf-actions a,.cf-actions button{border:0;border-radius:9px;padding:11px 15px;font-size:12px;font-weight:900;text-decoration:none}.cf-actions a{border:1px solid #d7e0ec;color:#475569}.cf-actions button{background:#172348;color:#fff}.cf-actions button:disabled{cursor:not-allowed;opacity:.45}.cf-state{display:none;margin-top:10px;border-radius:9px;padding:10px 12px;font-size:12px}.cf-state.show{display:block}.cf-state.ok{background:#dcfce7;color:#166534}.cf-state.error{background:#fff1f2;color:#9f1239}.cf-error{margin-bottom:15px;border:1px solid #fecaca;border-radius:9px;background:#fff1f2;padding:12px;color:#9f1239;font-size:12px}@media(max-width:700px){.cf-grid{grid-template-columns:1fr}.cf-wide{grid-column:auto}.cf-ncc-row{align-items:stretch;flex-direction:column}.cf-wide .cf-load{width:100%}.cf-actions{align-items:stretch;flex-direction:column}}

.cf-card{max-width:none;background:var(--finea-surface-raised,#fff);border-color:var(--finea-border,#dfe6f0);color:var(--finea-text,#172033);border-radius:24px;padding:clamp(16px,3vw,28px)}
.cf-head{padding-bottom:20px;margin-bottom:20px;border-bottom:1px solid var(--finea-border)}
.cf-head h2,.cf-field label{color:var(--finea-text)}
.cf-head p{color:var(--finea-muted)}
.cf-field label{font-size:13px;font-weight:700}
.cf-field input{background:var(--finea-input-surface,#f8fafc);color:var(--finea-text);border-color:var(--finea-input-border,#cbd5e1);min-height:44px;border-radius:12px}
.cf-field input[readonly]{background:var(--finea-surface-soft);color:var(--finea-muted)}
.cf-actions{border-top:1px solid var(--finea-border);padding-top:20px}
.cf-actions a{color:var(--finea-text);border-color:var(--finea-border)}
.cf-card .cf-actions button,.cf-card .cf-load{background:var(--brand-primary);color:var(--brand-on-primary);border:1px solid transparent}
.cf-card .cf-load:hover{background:var(--brand-primary);filter:brightness(.95)}
:is(html.finea-dark-mode,body.finea-dark-mode) .cf-card{--finea-text:#f5f8fc;--finea-muted:#c6d0de;--finea-border:#52647e;--finea-surface-raised:#172337;--finea-surface-soft:#202e43}
:is(html.finea-dark-mode,body.finea-dark-mode) .cf-card :is(.cf-load,.cf-actions button){border-color:#8199c0}
:is(html.finea-dark-mode,body.finea-dark-mode) .cf-card .cf-icon{background:#123c32;color:#a7f3d0}
:is(html.finea-dark-mode,body.finea-dark-mode) .cf-state.ok{background:#123c32;color:#a7f3d0}
:is(html.finea-dark-mode,body.finea-dark-mode) :is(.cf-state.error,.cf-error){background:#481f2b;color:#fecdd3;border-color:#a85a70}
</style>
<?php if ($error): ?><div class="cf-error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif; ?>
<section class="cf-card" data-platform="<?=($_POST['tax_platform']??'fne')==='impots'?'impots':'fne'?>">
  <div class="cf-head"><span class="cf-icon"><i class="fa-solid fa-building-shield"></i></span><div><h2>Identité fiscale du client</h2><p id="platformDescription">Choisissez le pays fiscal et contrôlez les identifiants adaptés.</p></div></div>
  <form method="post" id="clientForm" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?=htmlspecialchars((string)$_SESSION['client_create_csrf'],ENT_QUOTES,'UTF-8')?>">
    <?php $businessClientForm=true; $supplierFiscalIdentity=$_POST; require __DIR__.'/../partials/business_identity_entry.php';require __DIR__.'/../partials/supplier_french_identity.php'; ?>
<input type="hidden" name="tax_platform" value="fne">
    <div class="cf-grid">
      <div class="cf-field cf-wide" data-ci-company-only <?= ($supplierFiscalIdentity['fiscal_country']??'')==='CI'&&($supplierFiscalIdentity['client_type']??'morale')==='morale'?'':'hidden' ?>><label for="ncc">NCC officiel *</label><div class="cf-ncc-row"><input id="ncc" name="ncc" required maxlength="20" value="<?=htmlspecialchars((string)($_POST['ncc']??''),ENT_QUOTES,'UTF-8')?>" placeholder="Ex. 2158807C"><button id="loadClientFne" class="cf-load" type="button"><i class="fa-solid fa-rotate client-load-reload" aria-hidden="true"></i><span class="client-load-label">Charger depuis la FNE</span><img class="client-load-logo" src="<?=htmlspecialchars($root('assets/fne_logo.jpeg'),ENT_QUOTES,'UTF-8')?>" alt=""></button></div><div id="fneState" class="cf-state" role="status" aria-live="polite"></div></div>
      <div class="cf-field"><label for="nom_client">Raison sociale</label><input id="nom_client" name="nom_client" readonly required value="<?=htmlspecialchars((string)($_POST['nom_client']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="tax_regime">Régime fiscal</label><input id="tax_regime" readonly></div>
      <div class="cf-field"><label for="fiscal_activity_label">Activité fiscale</label><input id="fiscal_activity_label" readonly></div>
      <div class="cf-field"><label for="registered_at">Enregistré le</label><input id="registered_at" readonly></div>
      <div class="cf-field"><label for="first_registration_at">Première immatriculation</label><input id="first_registration_at" readonly></div>
      <div class="cf-field"><label for="activity_start_date">Date début d’activité (saisie interne)</label><input id="activity_start_date" type="date" name="activity_start_date" value="<?=htmlspecialchars((string)($_POST['activity_start_date']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="activity_description">Activité (saisie interne)</label><input id="activity_description" name="activity_description" maxlength="255" value="<?=htmlspecialchars((string)($_POST['activity_description']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="vat_liability_status">Statut d’assujettissement TVA (saisie interne)</label><input id="vat_liability_status" name="vat_liability_status" maxlength="100" value="<?=htmlspecialchars((string)($_POST['vat_liability_status']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="vat_last_period">Période dernière déclaration TVA (saisie interne)</label><input id="vat_last_period" name="vat_last_period" maxlength="150" value="<?=htmlspecialchars((string)($_POST['vat_last_period']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="telephone_client">Téléphone</label><input id="telephone_client" name="telephone_client" value="<?=htmlspecialchars((string)($_POST['telephone_client']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="email_client">Email</label><input id="email_client" type="email" name="email_client" value="<?=htmlspecialchars((string)($_POST['email_client']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="commune_client">Commune</label><input id="commune_client" name="commune_client" value="<?=htmlspecialchars((string)($_POST['commune_client']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="localisation_client">Adresse / localisation</label><input id="localisation_client" name="localisation_client" value="<?=htmlspecialchars((string)($_POST['localisation_client']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="bp_client">Boîte postale</label><input id="bp_client" name="bp_client" value="<?=htmlspecialchars((string)($_POST['bp_client']??''),ENT_QUOTES,'UTF-8')?>"></div>
      <div class="cf-field"><label for="pays_client">Pays</label><input id="pays_client" name="pays_client" value="<?=htmlspecialchars((string)($_POST['pays_client']??"Côte d’Ivoire"),ENT_QUOTES,'UTF-8')?>"></div>
    </div>
    <div class="cf-actions"><a href="<?=$root('clients/dashboard.php')?>">Annuler</a><button id="saveClient" type="submit" disabled><i class="fa-solid fa-lock"></i> Enregistrer après vérification</button></div>
  </form>
</section>
<script src="<?= htmlspecialchars($root('assets/js/client-business-identity.js'),ENT_QUOTES,'UTF-8') ?>?v=<?= filemtime(__DIR__.'/../assets/js/client-business-identity.js') ?>" defer></script>

<?php $content=ob_get_clean();require __DIR__.'/../devis/includes/module_layout.php';


