<?php

require_once __DIR__ . '/../services/ActiveChantierEditPermissionService.php';

/**
 * Partial: rendu d'une card devis.
 * Variables attendues:
 * - $de (array) : ligne devis_banamur
 * - $offreModel (Offre)
 * - $devisModel (Devis)
 * - $chantierModel (Chantier)
 * - $canModifierDebourse (bool)
 */
$canCancelDevisBilling=$canCancelDevisBilling??(
    AccessManager::hasPermission('acces_facturation')
    || AccessManager::hasPermission('acces_direction')
    || AccessManager::hasPermission('acces_admin')
);
$canEditCurrentQuote = ActiveChantierEditPermissionService::canEdit(Database::getConnection(), (int) $de['id']);
?>
<div class="card" id="devis-<?= (int)$de['id'] ?>" data-devis-id="<?= (int)$de['id'] ?>">
    <div class="card-header">
        <div class="card-header-left">
            <i class="fas fa-file-invoice"></i>
            <span class="card-header-title"><?= htmlspecialchars($de['numero_devis']) ?></span>
        </div>

        <div class="card-header-right">
            <?php if (!$de['validation_technique']) : ?>
                <a class="header-validate header-validate--tech" href="request/valider_technique.php?devisId=<?= $de['id'] ?>" title="Valider technique">
                    <i class="fas fa-circle-check"></i>
                    <span>Technique</span>
                </a>
            <?php elseif (!$de['validation_generale']) : ?>
                <a class="header-validate header-validate--dg" href="request/valider_generale.php?devisId=<?= $de['id'] ?>" title="Valider Direction générale">
                    <i class="fas fa-circle-check"></i>
                    <span>DG</span>
                </a>
            <?php else : ?>
                <span class="header-valid" title="Devis validé">
                    <i class="fas fa-check-double"></i>
                    <span>Validé</span>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php $projectType=(string)($de['project_devis_type']??'UNCLASSIFIED'); ?>
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <span class="badge rounded-pill <?= $projectType==='PRINCIPAL'?'bg-primary':($projectType==='TS'?'bg-warning text-dark':'bg-secondary') ?>">
                <?= $projectType==='PRINCIPAL'?'Devis principal':($projectType==='TS'?'Travaux supplémentaires':'À classer') ?>
            </span>
            <?php if(!empty($canClassifyDevis)&&!empty($de['offre_id'])&&$canEditCurrentQuote): ?>
                <form method="post" action="request/classer_devis_offre.php" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="_csrf" value="<?=htmlspecialchars((string)($_SESSION['devis_offer_csrf']??''))?>">
                    <input type="hidden" name="devis_id" value="<?=(int)$de['id']?>">
                    <select name="devis_type" class="form-select form-select-sm" aria-label="Type du devis">
                        <option value="PRINCIPAL" <?=$projectType==='PRINCIPAL'?'selected':''?>>Principal</option>
                        <option value="TS" <?=$projectType==='TS'?'selected':''?>>TS</option>
                    </select>
                    <button class="btn btn-sm btn-outline-dark">Classer</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php if (!empty($de['has_disbursement_sheet'])): ?>
                <span class="badge bg-success"><i class="fas fa-money-check-alt"></i> Lignes liées à des fiches de décaissement</span>
            <?php elseif (!empty($de['has_chantier'])): ?>
                <span class="badge bg-info text-dark">Chantier créé</span>
            <?php elseif (!empty($de['is_archivable'])): ?>
                <span class="badge bg-secondary">Sans dépendance — archivable</span>
            <?php endif; ?>
            <?php if (!empty($de['has_invoice'])): ?><span class="badge bg-primary">Facture émise</span><?php endif; ?>
            <?php if (!empty($de['masque'])): ?>
                <span class="badge bg-secondary"><i class="fas fa-eye-slash"></i> Masqué</span>
                <small class="text-muted" title="Date et heure du masquage">
                    <i class="far fa-clock"></i>
                    <?= !empty($de['masked_at']) ? htmlspecialchars(date('d/m/Y à H:i', strtotime((string)$de['masked_at']))) : 'Date historique indisponible' ?>
                </small>
            <?php endif; ?>
            <?php if (!empty($de['is_archived'])): ?><span class="badge bg-dark">Archivé — restaurable</span><?php endif; ?>
        </div>
        <div class="devis-title">
            <?= htmlspecialchars($offreModel->getOffreById($de['offre_id'])['reference_offre'] ?? '') ?>
        </div>
        <div class="info-grid">
            <p><strong>Total HT:</strong> <?= htmlspecialchars($de['total_ht']) ?> FCFA</p>
            <p><strong>Total TTC:</strong> <?= htmlspecialchars($de['total_ttc']) ?> FCFA</p>
            <p><strong>Date de Création:</strong> <?= htmlspecialchars($de['created_at']) ?></p>
        </div>
    </div>
    <div class="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <a class="btn btn-danger btn-sm" target="_blank"
            href="request/export_page_garde_pdf.php?devisId=<?= $de['id'] ?>">
            <i class="fas fa-file-pdf"></i> PDF Page de garde
        </a>

        <div class="d-flex gap-2">
            <?php if ((AccessManager::hasPermission('acces_admin') || AccessManager::hasPermission('acces_direction')) && !empty($de['has_chantier'])): ?>
                <a class="btn btn-sm btn-outline-danger" href="chantiers/fusion_doublons.php" title="Transférer les fiches vers un chantier conservé puis retirer ce doublon"><i class="fas fa-code-merge"></i> Fusionner / retirer</a>
            <?php elseif (AccessManager::hasPermission('transferer_historique_devis')): ?>
                <a class="btn btn-sm btn-outline-secondary" href="devis/transfert_financier.php?source_devis_id=<?= (int) $de['id'] ?>" title="Transférer les fiches et lignes vers un autre devis"><i class="fas fa-code-merge"></i> Transférer</a>
            <?php endif; ?>
            <?php if (!empty($canClassifyDevis) && !empty($de['masque'])): ?>
                <form method="post" action="request/restaurer_devis_masque.php" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($_SESSION['devis_offer_csrf'] ?? '')) ?>">
                    <input type="hidden" name="devis_id" value="<?= (int) $de['id'] ?>">
                    <button class="btn btn-sm btn-outline-success" title="Rendre de nouveau ce devis visible"><i class="fas fa-eye"></i> Restaurer l’affichage</button>
                </form>
            <?php endif; ?>
            <?php if (!empty($canClassifyDevis) && (!empty($de['is_archivable']) || !empty($de['is_archived']))): ?>
                <form method="post" action="request/archive_devis.php" class="d-inline">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($_SESSION['devis_offer_csrf'] ?? '')) ?>">
                    <input type="hidden" name="devis_id" value="<?= (int) $de['id'] ?>">
                    <input type="hidden" name="archive_action" value="<?= !empty($de['is_archived']) ? 'restore' : 'archive' ?>">
                    <button class="btn btn-sm <?= !empty($de['is_archived']) ? 'btn-outline-success' : 'btn-outline-secondary' ?>" <?= empty($de['is_archived']) ? 'onclick="return confirm(\'Archiver ce devis devenu inutile ? Il restera restaurable.\')"' : '' ?> title="<?= !empty($de['is_archived']) ? 'Restaurer ce devis' : 'Archiver ce devis restaurable' ?>">
                        <i class="fas <?= !empty($de['is_archived']) ? 'fa-undo' : 'fa-archive' ?>"></i>
                        <?= !empty($de['is_archived']) ? 'Restaurer' : 'Archiver' ?>
                    </button>
                </form>
            <?php endif; ?>
            <?php if (!empty($canCancelDevisBilling) && (int)($de['cancelable_invoice_count'] ?? 0) > 0): ?>
                <form method="post" action="request/annuler_facturation_devis.php" class="d-inline" onsubmit="return confirm('Annuler la facturation active de ce devis ? Les factures et leur historique seront conservés.');">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($_SESSION['devis_offer_csrf'] ?? '')) ?>">
                    <input type="hidden" name="devis_id" value="<?= (int) $de['id'] ?>">
                    <input type="hidden" name="archive_status" value="<?= htmlspecialchars((string)($_GET['archive_status'] ?? 'all')) ?>">
                    <input type="hidden" name="reason" value="Devis en doublon ou facturation abandonnée depuis la liste des devis">
                    <button class="btn btn-sm btn-outline-danger" title="Annuler les factures non encaissées liées à ce devis"><i class="fas fa-ban"></i> Annuler la facturation</button>
                </form>
            <?php elseif ((int)($de['paid_invoice_count'] ?? 0) > 0): ?>
                <span class="btn btn-sm btn-light text-muted" title="Une facture encaissée ne peut pas être annulée depuis cette liste"><i class="fas fa-lock"></i> Facturation encaissée</span>
            <?php endif; ?>
            <a class="btn btn-outline-primary btn-sm" target="_blank" title="Visualiser le devis détaillé"
                href="request/export_pdf.php?devisId=<?= $de['id'] ?>">
                <i class="fas fa-file-alt"></i> Détaillé
            </a>
            <a class="btn btn-outline-info btn-sm" target="_blank" title="Visualiser le devis groupé"
                href="request/export_pdf_groupe.php?devisId=<?= $de['id'] ?>">
                <i class="fas fa-layer-group"></i> Groupé
            </a>
            <a class="btn btn-success btn-sm" target="_blank" title="Exporter en Excel"
                href="request/export_excel.php?devisId=<?= $de['id'] ?>">
                <i class="fas fa-file-excel"></i> Exporter en Excel
            </a>
            <a class="btn btn-warning btn-sm" target="_blank" title="Exporter en Word"
                href="request/export_word.php?devisId=<?= $de['id'] ?>">
                <i class="fas fa-file-word"></i> Exporter en Word
            </a>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm" title="Masquer ce devis"
                href="request/masquer_devis.php?devisId=<?= $de['id'] ?>">
                <i class="fas fa-eye-slash"></i>
            </a>
            <?php if ($canEditCurrentQuote): ?><a class="btn btn-outline-warning btn-sm" title="Modifier ce devis"
                href="modifier_devis.php?devisId=<?= $de['id'] ?>">
                <i class="fas fa-edit"></i>
            </a><?php endif; ?>
            <a class="btn btn-outline-info btn-sm" title="Affecter les zones"
                href="decaissement/affecter_zones_kanban.php?devis_id=<?= $de['id'] ?>">
                <i class="fas fa-th-large"></i> Zones
            </a>
            <a class="btn btn-outline-primary btn-sm" target="_blank"
                href="request/export_sommaire.php?devisId=<?= $de['id'] ?>">
                <i class="fas fa-list-ol"></i> Sommaire PDF
            </a>
        </div>
    </div>
    <?php if ($de['validation_technique'] && $de['validation_generale']) : ?>
        <div class="footer-validation">
            <?php
            $hasDebourse = $devisModel->debourseExistePourDevis($de['id']);
            ?>
            <?php if ($hasDebourse): ?>
                <a class="btn btn-outline-dark btn-sm ms-2" title="Gérer le déboursé" href="gerer_debourse.php?devisId=<?= $de['id'] ?>">
                    <i class="fas fa-coins"></i> Gérer le déboursé
                </a>

                <?php if ($projectType === 'TS'): ?>
                    <?php /*
                        Un TS appartient déjà au projet/chantier du devis principal.
                        On ne doit JAMAIS proposer debuter_chantier.php pour un TS :
                        l'action ci-dessous crée/actualise uniquement devis_chantier_links.
                    */ ?>
                    <form method="post" action="request/activer_ts.php" class="d-inline ms-2">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)($_SESSION['devis_offer_csrf'] ?? '')) ?>">
                        <input type="hidden" name="devis_id" value="<?= (int)$de['id'] ?>">
                        <button class="btn btn-warning btn-sm" type="submit" title="Rattacher ce TS au chantier existant du devis principal">
                            <i class="fas fa-bolt"></i> Activer le TS
                        </button>
                    </form>
                    <span class="badge bg-light text-dark border ms-2">
                        <i class="fas fa-link"></i> Utilise le chantier principal
                    </span>
                <?php else: ?>
                    <?php
                    $chantierExiste = $chantierModel->existePourDevis($de['id']);
                    ?>
                    <?php if ($chantierExiste): ?>
                        <?php $chantier = $chantierModel->getByDevisId($de['id']); ?>
                        <a class="btn btn-outline-info btn-sm ms-2" title="Chantier en cours" href="details_chantier.php?id=<?= $chantier['id'] ?>">
                            <i class="fas fa-tools"></i> Chantier en cours
                        </a>
                    <?php else: ?>
                        <a class="btn btn-outline-success btn-sm ms-2" title="Débuter le chantier" href="debuter_chantier.php?devisId=<?= $de['id'] ?>">
                            <i class="fas fa-play"></i> Débuter le chantier
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!empty($canModifierDebourse)): ?>
                    <a class="btn btn-outline-dark btn-sm ms-2" title="Gérer le déboursé" href="gerer_debourse.php?devisId=<?= $de['id'] ?>">
                        <i class="fas fa-coins"></i> Gérer le déboursé
                    </a>
                <?php endif; ?>
                <?php if ($projectType === 'TS'): ?>
                    <span class="badge bg-light text-dark border ms-2" title="Le TS sera activé sur le chantier du devis principal, jamais comme nouveau chantier">
                        <i class="fas fa-link"></i> TS rattaché au chantier principal
                    </span>
                <?php endif; ?>
            <?php endif; ?>

            <a class="btn btn-outline-success btn-sm ms-2" title="Imprimer le dossier complet" href="request/imprimer_dossier_complet.php?devisId=<?= $de['id'] ?>" target="_blank">
                <i class="fas fa-print"></i> Imprimer dossier complet
            </a>
        </div>
    <?php endif; ?>
</div>
