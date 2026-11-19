<?php

/**
 * Partial: rendu d'une card déboursé (liste_debourse).
 * Variables attendues:
 * - $deb (array)
 */
?>

<div class="col-md-6 col-lg-4">
    <div class="card debourse-card h-100">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between mb-3">
                <span class="badge bg-primary">
                    Devis <?= htmlspecialchars((string)($deb['numero_devis'] ?? '')) ?>
                </span>

                <span class="text-muted small">
                    #<?= htmlspecialchars((string)($deb['id'] ?? '')) ?>
                </span>
            </div>

            <div class="debourse-title mb-1">
                <?= htmlspecialchars((string)($deb['designation'] ?? '')) ?>
            </div>

            <div class="debourse-meta mb-3">
                <i class="fas fa-building me-1"></i>
                <?= htmlspecialchars((string)($deb['titre'] ?? '')) ?>
            </div>

            <div class="debourse-meta mb-3">
                Client :
                <strong><?= htmlspecialchars((string)($deb['nom_client'] ?? '')) ?></strong>
            </div>

            <div class="row small text-muted mb-3">
                <div class="col-6">
                    <i class="fas fa-calendar text-primary me-1"></i>
                    <?= htmlspecialchars((string)($deb['date_debut'] ?? '-')) ?>
                </div>

                <div class="col-6 text-end">
                    <i class="fas fa-calendar-check text-success me-1"></i>
                    <?= htmlspecialchars((string)($deb['date_fin'] ?? '-')) ?>
                </div>
            </div>

            <div class="debourse-price">
                <?= number_format((float)($deb['montant_debourse'] ?? 0), 0, ',', ' ') ?> FCFA
            </div>
        </div>

        <div class="card-footer d-flex justify-content-between">
            <a href="debourse/gerer.php?devisId=<?= urlencode((string)($deb['devis_id'] ?? '')) ?>" class="btn btn-light btn-action" title="gerer">
                <i class="fas fa-eye text-primary"></i>
            </a>

            <a href="request/export_debourse_pdf.php?devis_id=<?= urlencode((string)($deb['devis_id'] ?? '')) ?>" class="btn btn-light btn-action" title="PDF">
                <i class="fas fa-file-pdf text-danger"></i>
            </a>

            <a href="request/export_debourse_excel.php?devis_id=<?= urlencode((string)($deb['devis_id'] ?? '')) ?>" class="btn btn-light btn-action" title="Excel">
                <i class="fas fa-file-excel text-success"></i>
            </a>
        </div>
    </div>
</div>
