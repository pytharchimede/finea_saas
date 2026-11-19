<?php
declare(strict_types=1);
require_once __DIR__.'/../model/AccessManager.php';
require_once __DIR__.'/../model/PortalModuleManager.php';
foreach (['comptabilite/index.php','comptabilite/justificatif.php','comptabilite/source_document.php'] as $path) {
    $_SERVER['SCRIPT_NAME']='/banamur_planification/'.$path;
    foreach ([['acces_comptabilite'=>1],['acces_admin'=>1]] as $permissions) {
        $_SESSION=['permissions'=>$permissions];
        if (!AccessManager::hasPageAccess(basename($path))) throw new RuntimeException('Authorized accounting route blocked: '.$path);
    }
    $_SESSION=['permissions'=>['acces_tresorerie'=>1]];
    if (AccessManager::hasPageAccess(basename($path))) throw new RuntimeException('Unrelated permission granted accounting route');
}
$modules=array_column(PortalModuleManager::defaultModules(),null,'module_key');
if ($modules['module_traitement_comptable']['label']!=='Traitement des paiements') throw new RuntimeException('Payment label');
if ($modules['module_gestion_comptable']['target']!=='comptabilite/index.php') throw new RuntimeException('Accounting portal target');
require_once __DIR__.'/../model/AccountingPermissions.php';
foreach ([[],['acces_compta'=>1],['acces_direction'=>1],['acces_tresorerie'=>1]] as $grants) {
    if (AccountingPermissions::allows($grants)) throw new RuntimeException('Legacy role must not grant accounting');
}
$base=['acces_comptabilite'=>1];
foreach (AccountingPermissions::ACTIONS as $action=>$required) {
    if (AccountingPermissions::action($base,$action)) throw new RuntimeException('Base access grants mutation');
    $grants=$base+array_fill_keys($required,1);
    if (!AccountingPermissions::action($grants,$action)) throw new RuntimeException('Explicit grants rejected: '.$action);
    foreach (array_keys($grants) as $missing) {
        $partial=$grants;unset($partial[$missing]);
        if (AccountingPermissions::action($partial,$action)) throw new RuntimeException('Missing dependency allowed: '.$action.'/'.$missing);
    }
    if (!AccountingPermissions::action(['acces_admin'=>1],$action)) throw new RuntimeException('Admin blocked');
}
foreach (AccountingPermissions::VIEWS as $view=>$required) {
    $grants=$base+array_fill_keys($required,1);
    if (!AccountingPermissions::view($grants,$view)) throw new RuntimeException('View blocked: '.$view);
    foreach (array_keys($grants) as $missing) {
        $partial=$grants;unset($partial[$missing]);
        if (AccountingPermissions::view($partial,$view)) throw new RuntimeException('View leaked: '.$view);
    }
}
foreach (array_keys(AccountingPermissions::LABELS) as $key) {
    if (!AccessManager::isPermissionColumn($key)) throw new RuntimeException('Permission not persisted: '.$key);
}
if (AccountingPermissions::action(['acces_admin'=>1],'unknown')) throw new RuntimeException('Unknown action allowed');
echo "AccountingAccessTest: route permissions and portal definitions passed.\n";
