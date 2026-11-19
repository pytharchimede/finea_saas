<?php
class AccessManager{public static function hasPermission(string $p):bool{return false;}}
$_SESSION=[];$_POST=[];$_SERVER['REQUEST_METHOD']='GET';
$root=dirname(__DIR__,2);
$businessClientForm=($argv[1]??'client')==='client';$supplierFiscalIdentity=[];
if($businessClientForm){echo '<form id="clientForm">';require $root.'/partials/business_identity_entry.php';require $root.'/partials/supplier_french_identity.php';echo '<div class="cf-field" data-ci-company-only><label for="ncc">NCC</label><input name="ncc" id="ncc"><button id="loadClientFne" type="button">FNE</button><p id="fneState"></p></div><div class="cf-field"><label for="nom_client">Raison sociale</label><input name="nom_client"></div><input name="telephone_client"><input name="email_client"><input name="localisation_client"><input name="commune_client"><input name="pays_client"><button id="saveClient">Enregistrer</button></form>';}
else{$supplierCreateOld=[];$categoryOptions=$legalFormOptions=[];require $root.'/partials/supplier_create_form.php';}
