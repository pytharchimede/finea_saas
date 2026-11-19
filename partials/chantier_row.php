<?php

/** @var array $chantier */

$id = (int)($chantier['id'] ?? 0);
$titre = (string)($chantier['titre'] ?? '');
$code = (string)($chantier['code'] ?? '');
$clientNom = (string)($chantier['client_nom'] ?? '');
$devisNumero = (string)($chantier['devis_numero'] ?? ($chantier['numero_devis'] ?? ''));
$offreRef = (string)($chantier['offre_ref'] ?? '');
$dateCreation = (string)($chantier['date_creation'] ?? '');
$archivedAt = $chantier['archived_at'] ?? null;

?>
<tr>
    <td><i class="fas fa-hard-hat text-primary"></i> <?= htmlspecialchars((string)$id) ?></td>
    <td><?= htmlspecialchars($titre) ?></td>
    <td><?= htmlspecialchars($code) ?></td>
    <td><?= htmlspecialchars($clientNom !== '' ? $clientNom : '-') ?></td>
    <td>
        <?= htmlspecialchars($devisNumero !== '' ? $devisNumero : '-') ?>
        <?php if ($offreRef !== ''): ?>
            <br><small class="text-muted">Projet : <?= htmlspecialchars($offreRef) ?></small>
        <?php endif; ?>
    </td>
    <td><?= htmlspecialchars($dateCreation) ?></td>
    <td>
        <?php if (!empty($archivedAt)): ?>
            <span class="badge bg-warning text-dark"><i class="fas fa-box-archive"></i> Archivé</span>
        <?php else: ?>
            <span class="badge bg-success"><i class="fas fa-circle"></i> Actif</span>
        <?php endif; ?>
    </td>
    <td>
        <a href="details_chantier.php?id=<?= urlencode((string)$id) ?>" class="btn btn-sm btn-primary">
            <i class="fas fa-eye"></i> Voir
        </a>
    </td>
</tr>
