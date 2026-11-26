<?php
$activeMenu='espace_employe';
require_once __DIR__.'/../../decaissement/includes/navbar.php';
$employeeBase=$layoutRootBase.'employe/';
$employeeTabs=['dashboard'=>['Accueil','dashboard_employe.php','fa-house'],'pointage'=>['Pointage','pointage_employe.php','fa-clock'],'actes'=>['Nouvelle demande','actes_legaux_employe.php','fa-file-signature'],'demandes'=>['Mes demandes','mes_demandes_employe.php','fa-clipboard-list'],'decaissement'=>['Décaissements','mes_decaissements_employe.php','fa-wallet'],'fournisseurs'=>['Fournisseurs','mes_fournisseurs_employe.php','fa-industry'],'explications'=>['Explications','mes_explications_employe.php','fa-comments'],'contestations'=>['Contestations','contestations_prix_feb.php','fa-scale-balanced'],'regularisation_decaissements'=>['Régularisations','../decaissement/regularisations_decaissements.php','fa-file-circle-check'],'procedures'=>['Procédures','../procedures/index.php','fa-diagram-project']];
?><link rel="stylesheet" href="<?=htmlspecialchars($employeeBase.'includes/employee.css',ENT_QUOTES,'UTF-8')?>">
<link rel="stylesheet" href="<?=htmlspecialchars($employeeBase.'includes/employee-dark.css',ENT_QUOTES,'UTF-8')?>">
<script>document.body.classList.add('employee-space','employee-redesign');</script>
<div class="employee-module-nav"><div class="employee-module-label"><i class="fas fa-user-circle"></i><span>Espace employé</span></div><nav aria-label="Navigation espace employé"><?php foreach($employeeTabs as $key=>$item):?><a href="<?=htmlspecialchars($employeeBase.$item[1],ENT_QUOTES,'UTF-8')?>" <?=($activeEmployeeMenu??'')===$key?'aria-current="page"':''?>><i class="fas <?=$item[2]?>" aria-hidden="true"></i><?=htmlspecialchars($item[0])?></a><?php endforeach;?></nav></div>
