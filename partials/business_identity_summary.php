<?php
if (($businessIdentity['fiscal_country']??'CI')!=='CI' || ($businessIdentity['identity_method']??'')==='exception'):
$identityLabels=['registry_verified'=>'Identité confirmée par registre','documentary_pending'=>'Justificatifs en attente de validation','documentary_verified'=>'Identité validée sur justificatifs','unverified'=>'À vérifier','exception_approved'=>'Dérogation interne autorisée — aucune vérification fiscale'];
?>
<div class="mb-4 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700">
<strong>Identité internationale</strong>
<?php foreach (['fiscal_country'=>'Pays fiscal','registration_type'=>'Type','registration_number'=>'Identifiant','vat_number'=>'TVA','identity_evidence'=>(($businessIdentity['identity_method']??'')==='exception'?'Motif de dérogation':'Justificatif'),'identity_checked_by'=>'Autorisé par (identifiant utilisateur)','identity_checked_at'=>'Dernier contrôle'] as $key=>$label): ?>
<div><?= htmlspecialchars($label) ?> : <?= htmlspecialchars((string)($businessIdentity[$key]??'—'),ENT_QUOTES,'UTF-8') ?></div>
<?php endforeach; ?>
<div><?= htmlspecialchars($identityLabels[$businessIdentity['identity_status']??'unverified']??'À vérifier') ?></div>
<div>TVA : <?= ($businessIdentity['vat_status']??'')==='valid'?'confirmée par VIES':'non vérifiée par VIES' ?></div>
</div>
<?php endif; ?>

