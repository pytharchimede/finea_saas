<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();
$manager = new \EmployeeLegalRequestManager($pdo);
$types = $manager->getRequestTypes();

$typeIcons = [
    'permission_absence' => 'fa-calendar-minus',
    'conge' => 'fa-umbrella-beach',
    'retard' => 'fa-clock-rotate-left',
    'pret' => 'fa-hand-holding-usd',
    'avance_salaire' => 'fa-wallet',
    'heures_supp' => 'fa-clock',
    'reclamation_salariale' => 'fa-file-invoice-dollar',
    'recrutement' => 'fa-user-plus',
    'feb' => 'fa-clipboard-list',
];

$pageTitle = 'Actes légaux';
$layoutRootBase = '../';
$activeEmployeeMenu = 'actes';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="employee-home employee-acts">
<header class="employee-welcome"><div><small>MES DÉMARCHES</small><h1>Créer une demande</h1><p>Choisissez la démarche correspondant à votre besoin.</p></div><a class="employee-primary" href="mes_demandes_employe.php">Suivre mes demandes <i class="fas fa-arrow-right"></i></a></header>
<div class="employee-home-grid">
<?php foreach(['rh'=>'Ressources humaines','finance'=>'Finances'] as $team=>$label):?>
<section class="employee-panel employee-acts-panel"><h2><?=htmlspecialchars($label)?></h2><div class="employee-rows">
<?php foreach($types as $typeKey=>$def):if((($def['team']??'')==='finance'?'finance':'rh')!==$team)continue;?>
<a href="nouvelle_demande_employe.php?type=<?=rawurlencode((string)$typeKey)?>"><span class="employee-act-name"><i class="fas <?=htmlspecialchars($typeIcons[$typeKey]??'fa-file-alt')?>" aria-hidden="true"></i><?=htmlspecialchars((string)($def['label']??$typeKey))?></span><i class="fas fa-chevron-right" aria-hidden="true"></i></a>
<?php endforeach;?>
<?php if($team==='finance'):?><a href="../decaissement/formulaire_demande_decaissement.php"><span class="employee-act-name"><i class="fas fa-wallet"></i>Fiche de décaissement</span><i class="fas fa-chevron-right"></i></a><a href="nouvelle_demande_decaissement_urgent.php"><span class="employee-act-name"><i class="fas fa-bolt"></i>Décaissement urgent</span><i class="fas fa-chevron-right"></i></a><?php endif;?>
</div></section><?php endforeach;?>
</div><p class="employee-acts-note">Les demandes sont transmises au service compétent. Les décaissements urgents doivent ensuite être régularisés par FEB.</p>
</main>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
