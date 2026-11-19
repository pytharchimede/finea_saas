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
$pausedAt = $chantier['paused_at'] ?? null;

$isArchived = !empty($archivedAt);
?>
<div class="col">
    <div class="card chantier-card h-100">
        <div class="card-body">
            <div class="d-flex align-items-start gap-2 flex-wrap">
                <div class="min-w-0 flex-grow-1">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="chantier-icon">
                            <i class="fa-solid fa-helmet-safety"></i>
                        </span>
                        <h5 class="chantier-title mb-0" title="<?= htmlspecialchars($titre) ?>">
                            <?= htmlspecialchars($titre !== '' ? $titre : ('Chantier #' . $id)) ?>
                        </h5>
                    </div>
                    <div class="chantier-sub text-muted">
                        <span class="me-2"><i class="fa-solid fa-hashtag"></i> <?= htmlspecialchars($code !== '' ? $code : '-') ?></span>
                        <span><i class="fa-regular fa-calendar-days"></i> <?= htmlspecialchars($dateCreation !== '' ? $dateCreation : '-') ?></span>
                    </div>
                </div>

                <div class="text-nowrap flex-shrink-0 ms-auto chantier-status">
                    <?php if ($isArchived): ?>
                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-box-archive"></i> Archivé</span>
                    <?php elseif (!empty($pausedAt)): ?>
                        <span class="badge bg-secondary"><i class="fa-solid fa-pause"></i> En pause</span>
                    <?php else: ?>
                        <span class="badge bg-success"><i class="fa-solid fa-circle-check"></i> Actif</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="chantier-meta mt-3">
                <div class="chantier-meta-row">
                    <i class="fa-regular fa-user"></i>
                    <span class="text-truncate" title="<?= htmlspecialchars($clientNom) ?>"><?= htmlspecialchars($clientNom !== '' ? $clientNom : '-') ?></span>
                </div>
                <div class="chantier-meta-row">
                    <i class="fa-regular fa-file-lines"></i>
                    <span class="text-truncate">
                        <?= htmlspecialchars($devisNumero !== '' ? $devisNumero : '-') ?>
                        <?php if ($offreRef !== ''): ?>
                            <span class="text-muted"> · Projet <?= htmlspecialchars($offreRef) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="text-muted small">
                    <i class="fa-solid fa-id-badge"></i> #<?= htmlspecialchars((string)$id) ?>
                </div>
                <a href="details_chantier.php?id=<?= urlencode((string)$id) ?>" class="btn btn-sm btn-primary">
                    <i class="fa-regular fa-eye"></i> Voir
                </a>
            </div>
        </div>
    </div>
</div>
