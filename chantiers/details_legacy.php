<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';

AuditTrail::logPageConsultation([
    'module' => 'core',
    'action' => 'details_chantier_view',
    'entity_type' => 'chantier_detail',
    'entity_id' => $_GET['id'] ?? $_GET['chantier_id'] ?? null,
    'entity_label' => 'Details chantier',
    'page_name' => 'details_chantier.php',
    'details' => [
        'chantier_id' => $_GET['id'] ?? $_GET['chantier_id'] ?? null,
        'view_mode' => 'detail',
    ],
]);

require_once 'header/header_details_chantier.php';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Détails Chantier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="css/custom_style_dashboard.css">
    <link rel="stylesheet" href="css/custom_style_details_chantier.css">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img style="width:auto; height:50px;" src="https://fidest.ci/logo/new_logo_banamur.jpg" alt="Logo">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <?php include 'menu.php'; ?>
            </div>
        </div>
    </nav>
    <div class="container">
        <div class="section-title"><i class="fas fa-warehouse"></i> Chantier : <?= htmlspecialchars($chantier['titre']) ?> (<?= htmlspecialchars($chantier['code']) ?>)</div>

        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <?php $devisId = (int)($devis['id'] ?? 0); ?>

        <?php $isArchived = !empty($chantier['archived_at'] ?? null); ?>
        <?php $isPaused = !$isArchived && !empty($chantier['paused_at'] ?? null); ?>
        <?php if ($isPaused): ?>
            <div class="alert alert-warning"><strong><i class="fas fa-pause-circle"></i> Chantier en pause</strong> depuis <?= htmlspecialchars((string) $chantier['paused_at']) ?><?php if (!empty($chantier['pause_note'])): ?> — <?= htmlspecialchars((string) $chantier['pause_note']) ?><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($isArchived): ?>
            <div class="alert alert-warning d-flex align-items-center justify-content-between">
                <div>
                    <strong><i class="fas fa-box-archive"></i> Chantier archivé</strong>
                    <span class="ms-2">le <?= htmlspecialchars((string)($chantier['archived_at'] ?? '')) ?></span>
                    <?php if (!empty($chantier['closed_date'])): ?>
                        <span class="ms-2">(Fin réelle: <?= htmlspecialchars((string)$chantier['closed_date']) ?>)</span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($canArchiveChantier)): ?>
                    <form method="post" class="m-0">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $_SESSION['chantier_lifecycle_csrf']) ?>">
                        <input type="hidden" name="archive_action" value="restore">
                        <button type="submit" class="btn btn-sm btn-outline-dark" onclick="return confirm('Restaurer ce chantier (réactiver) ?');">
                            <i class="fas fa-rotate-left"></i> Restaurer
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">Informations générales</div>
                    <div class="card-body">
                        <p><b>Client :</b> <?= htmlspecialchars((string)($client['nom_client'] ?? '—')) ?></p>
                        <p><b>Projet :</b> <?= htmlspecialchars((string)($offre['num_offre'] ?? '—')) ?></p>
                        <p><b>Devis :</b> <?= htmlspecialchars((string)($devis['numero_devis'] ?? '—')) ?></p>
                        <p><b>Date création :</b> <?= htmlspecialchars((string)($chantier['date_creation'] ?? '')) ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">Fichiers attachés au projet</div>
                    <div class="card-body">
                        <?php if (empty($fichiersOffre)): ?>
                            <p class="text-muted">Aucun fichier attaché.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($fichiersOffre as $fichier): ?>
                                    <li>
                                        <a href="<?= htmlspecialchars($fichier['file_path']) ?>" target="_blank" class="text-dark">
                                            <i class="fas fa-paperclip"></i> <?= htmlspecialchars($fichier['file_name']) ?>
                                        </a>
                                    </li>
                                <?php endforeach ?>
                            </ul>
                        <?php endif ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4" id="dates-travaux">
            <div class="card-header"><i class="fas fa-calendar-days"></i> Dates des travaux</div>
            <div class="card-body">
                <?php if ($canEditWorkDates && !$isArchived && $devisId > 0): ?>
                    <form method="post" class="row g-3 align-items-end">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $_SESSION['chantier_lifecycle_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="work_period_action" value="save">
                        <div class="col-md-4">
                            <label for="workStart" class="form-label">Début des travaux</label>
                            <input id="workStart" type="date" class="form-control" name="project_start_date" min="<?= htmlspecialchars((string) ($devis['date_emission'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) ($devis['project_start_date'] ?? $chantier['date_debut'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="workEnd" class="form-label">Fin prévue des travaux</label>
                            <input id="workEnd" type="date" class="form-control" name="project_end_date" value="<?= htmlspecialchars((string) ($devis['project_end_date'] ?? $chantier['date_fin_prevue'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-4"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-check"></i> Enregistrer les dates</button></div>
                    </form>
                    <p class="text-muted small mt-3 mb-0">Les déboursés doivent ensuite être compris dans cette période. Leur changement de date repositionne automatiquement les FEB soumises.</p>
                    <?php if (!empty($_SESSION['permissions']['modifier_debourse']) && !empty($_SESSION['permissions']['modifier_devis_chantier_actif'])): ?>
                        <a class="btn btn-outline-primary btn-sm mt-2" href="debourse/gerer.php?devisId=<?= $devisId ?>">Ajuster les déboursés</a>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="mb-1"><b>Début :</b> <?= htmlspecialchars((string) ($devis['project_start_date'] ?? $chantier['date_debut'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mb-0"><b>Fin prévue :</b> <?= htmlspecialchars((string) ($devis['project_end_date'] ?? $chantier['date_fin_prevue'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
                    <?php if (!$isArchived && !$canEditWorkDates): ?><small class="text-muted">La permission « Modifier les dates de début et de fin des travaux » est nécessaire pour les modifier.</small><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="fas fa-flag-checkered"></i> Gérer le chantier</span>
                        <?php if ($isArchived): ?>
                            <span class="badge text-bg-warning">ARCHIVÉ</span>
                        <?php elseif ($isPaused): ?>
                            <span class="badge text-bg-warning">EN PAUSE</span>
                        <?php else: ?>
                            <span class="badge text-bg-success">ACTIF</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($canArchiveChantier)): ?>
                            <div class="text-muted">Vous n'avez pas le droit de terminer/archiver ce chantier.</div>
                        <?php else: ?>
                            <?php if (!$isArchived): ?>
                                <form method="post" class="row g-3">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $_SESSION['chantier_lifecycle_csrf']) ?>">
                                    <input type="hidden" name="archive_action" value="archive">
                                    <div class="col-md-3">
                                        <label class="form-label">Date de fin réelle (optionnel)</label>
                                        <input type="date" class="form-control" name="closed_date" value="">
                                    </div>
                                    <div class="col-md-9">
                                        <label class="form-label">Bilan de fin de chantier (optionnel)</label>
                                        <textarea class="form-control" name="archived_note" rows="2" placeholder="Ex: difficultés, points clés, remarques client, leçons apprises..."></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-danger" onclick="return confirm('Terminer et archiver ce chantier ? Il ne sera plus sélectionnable pour de nouvelles fiches.');">
                                            <i class="fas fa-box-archive"></i> Terminer & archiver
                                        </button>
                                    </div>
                                </form>
                                <?php if ($isPaused): ?>
                                    <form method="post" class="mt-3"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $_SESSION['chantier_lifecycle_csrf']) ?>"><input type="hidden" name="archive_action" value="resume"><button class="btn btn-outline-success"><i class="fas fa-play"></i> Reprendre le chantier</button></form>
                                <?php else: ?>
                                    <form method="post" class="mt-3"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $_SESSION['chantier_lifecycle_csrf']) ?>"><input type="hidden" name="archive_action" value="pause"><label class="form-label">Motif de la pause (optionnel)</label><textarea name="pause_note" class="form-control mb-2" rows="2"></textarea><button class="btn btn-outline-warning"><i class="fas fa-pause"></i> Mettre en pause</button></form>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-muted">Ce chantier est déjà archivé. Utilisez “Restaurer” pour le réactiver.</div>
                                <?php if (!empty($chantier['archived_note'])): ?>
                                    <div class="mt-2">
                                        <strong>Bilan :</strong>
                                        <div style="white-space:pre-wrap;"><?= htmlspecialchars((string)$chantier['archived_note']) ?></div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Section Fichiers générés -->
        <div class="section-title"><i class="fas fa-folder-open"></i> Fichiers générés</div>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card mb-3 shadow-sm">
                    <div class="card-body">
                        <div class="row g-3">
                            <!-- Devis groupé -->
                            <div class="col-md-4">
                                <div class="d-flex align-items-center p-3 rounded bg-light border">
                                    <i class="fas fa-file-pdf fa-2x text-danger me-3"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold">Devis groupé (PDF)</div>
                                        <?php if ($devisId > 0): ?>
                                            <a href="request/export_pdf_groupe.php?devisId=<?= $devisId ?>" target="_blank" class="btn btn-sm btn-dark mt-1">
                                                <i class="fas fa-download"></i> Télécharger
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary mt-1" disabled>Indisponible</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Devis simple -->
                            <div class="col-md-4">
                                <div class="d-flex align-items-center p-3 rounded bg-light border">
                                    <i class="fas fa-file-pdf fa-2x text-primary me-3"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold">Devis simple (PDF)</div>
                                        <?php if ($devisId > 0): ?>
                                            <a href="request/export_pdf.php?devisId=<?= $devisId ?>" target="_blank" class="btn btn-sm btn-dark mt-1">
                                                <i class="fas fa-download"></i> Télécharger
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary mt-1" disabled>Indisponible</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Devis Excel -->
                            <div class="col-md-4">
                                <div class="d-flex align-items-center p-3 rounded bg-light border">
                                    <i class="fas fa-file-excel fa-2x text-success me-3"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold">Devis (Excel)</div>
                                        <?php if ($devisId > 0): ?>
                                            <a href="request/export_excel.php?devisId=<?= $devisId ?>" target="_blank" class="btn btn-sm btn-dark mt-1">
                                                <i class="fas fa-download"></i> Télécharger
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary mt-1" disabled>Indisponible</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Dossier complet -->
                            <div class="col-md-4">
                                <div class="d-flex align-items-center p-3 rounded bg-light border mt-3">
                                    <i class="fas fa-folder-zipper fa-2x text-warning me-3"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold">Dossier complet (ZIP)</div>
                                        <?php if ($devisId > 0): ?>
                                            <a href="request/imprimer_dossier_complet.php?devisId=<?= $devisId ?>" target="_blank" class="btn btn-sm btn-dark mt-1">
                                                <i class="fas fa-download"></i> Télécharger
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary mt-1" disabled>Indisponible</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Déboursé -->
                            <div class="col-md-4">
                                <div class="d-flex align-items-center p-3 rounded bg-light border mt-3">
                                    <i class="fas fa-file-invoice-dollar fa-2x text-info me-3"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold">Déboursé (Excel/PDF)</div>
                                        <?php if ($devisId > 0): ?>
                                            <a href="request/export_debourse_excel.php?devisId=<?= $devisId ?>" target="_blank" class="btn btn-sm btn-dark mt-1 me-1">
                                                <i class="fas fa-file-excel"></i> Excel
                                            </a>
                                            <a href="request/export_debourse_pdf.php?devisId=<?= $devisId ?>" target="_blank" class="btn btn-sm btn-dark mt-1">
                                                <i class="fas fa-file-pdf"></i> PDF
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary mt-1" disabled>Indisponible</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Planning -->
                            <div class="col-md-4">
                                <div class="d-flex align-items-center p-3 rounded bg-light border mt-3">
                                    <i class="fas fa-calendar-alt fa-2x text-secondary me-3"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold">Planning (PDF)</div>
                                        <?php if ($devisId > 0): ?>
                                            <a href="request/export_planning_gantt.php?devisId=<?= $devisId ?>" target="_blank" class="btn btn-sm btn-dark mt-1">
                                                <i class="fas fa-download"></i> Télécharger
                                            </a>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-secondary mt-1" disabled>Indisponible</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div><!-- row -->
                    </div>
                </div>
            </div>
        </div>
        <div class="section-title"><i class="fas fa-list"></i> Opérations (Lignes Devis)</div>
        <div class="card mb-4">
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>Désignation</th>
                            <th>Qté</th>
                            <th>Unité</th>
                            <th>Prix Unitaire</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($operations as $op): ?>
                            <tr>
                                <td><?= htmlspecialchars($op['designation']) ?></td>
                                <td><?= htmlspecialchars($op['quantite']) ?></td>
                                <td><?= htmlspecialchars($op['unite_id']) ?></td>
                                <td><?= number_format($op['prix'], 0, ',', ' ') ?></td>
                                <td><?= number_format($op['total'], 0, ',', ' ') ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="section-title"><i class="fas fa-coins"></i> Désignations (Titres Déboursés)</div>
        <div class="card mb-4">
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>Désignation</th>
                            <th>Montant</th>
                            <th>Opération liée</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($designations as $des): ?>
                            <tr>
                                <td><?= htmlspecialchars($des['designation']) ?></td>
                                <td><?= number_format($des['montant'], 0, ',', ' ') ?></td>
                                <td>
                                    <?php
                                    $operation = $operationModel->getById($des['operation_id']);
                                    echo htmlspecialchars($operation ? $operation['designation'] : '');
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="section-title"><i class="fas fa-info-circle"></i> Précisions (Lignes déboursés)</div>
        <div class="card mb-4">
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead>
                        <tr>
                            <th>Libellé</th>
                            <th>Montant</th>
                            <th>Désignation liée</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($precisions as $prec): ?>
                            <tr>
                                <td><?= htmlspecialchars($prec['libelle']) ?></td>
                                <td><?= number_format($prec['montant'], 0, ',', ' ') ?></td>
                                <td><?= htmlspecialchars($prec['designation_id']) ?></td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</body>

</html>
