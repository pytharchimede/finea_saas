<?php
include 'auth_check.php';
include 'header/header_gerer_debourse.php';
require_once 'services/ActiveChantierEditPermissionService.php';

$canEdit = function_exists('hasPermission') ? hasPermission('modifier_debourse') : (!empty($_SESSION['permissions']['modifier_debourse'] ?? 0));
$canEdit = $canEdit && ActiveChantierEditPermissionService::canEdit($pdo, $devisId);
$focusDebourseId = max(0, (int)($_GET['focus_debourse'] ?? 0));

function getCategorieOptions($selected = '')
{
    $cats = ['RH', 'Logistique', 'HSE', 'ACHAT', 'Bureau'];
    $html = '<option value="">--Catégorie--</option>';
    foreach ($cats as $cat) {
        $sel = ($cat === $selected) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($cat, ENT_QUOTES, 'UTF-8') . '" ' . $sel . '>' . htmlspecialchars(ucfirst($cat), ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html;
}

$lineCount = count($lignes ?? []);
$totalMontantDevis = 0.0;
$totalMontantDebourse = 0.0;
$depassementCount = 0;
$renseigneCount = 0;

foreach (($lignes ?? []) as $ligne) {
    $ligneId = (int)($ligne['id'] ?? 0);
    $debourse = $debourses[$ligneId] ?? [];
    $montantLigne = isset($ligne['prix']) && is_numeric($ligne['prix']) ? (float)$ligne['prix'] : 0.0;
    $quantiteLigne = isset($ligne['quantite']) && is_numeric($ligne['quantite']) ? (float)$ligne['quantite'] : 0.0;
    $totalLigne = $montantLigne * $quantiteLigne;
    if ($totalLigne <= 0) {
        $totalLigne = $montantLigne;
    }

    $montantDebourse = isset($debourse['montant_debourse']) && is_numeric($debourse['montant_debourse']) ? (float)$debourse['montant_debourse'] : 0.0;
    $totalMontantDevis += $totalLigne;
    $totalMontantDebourse += $montantDebourse;

    if ($montantDebourse > 0) {
        $renseigneCount++;
    }
    if ($montantDebourse > $totalLigne && $totalLigne > 0) {
        $depassementCount++;
    }
}

$totalReste = $totalMontantDevis - $totalMontantDebourse;
$coverageRate = $totalMontantDevis > 0 ? min(100, round(($totalMontantDebourse / $totalMontantDevis) * 100)) : 0;

$sousLignesByDebourseId = [];
if (!empty($debourses) && isset($pdo) && $pdo instanceof PDO) {
    $debourseIds = [];
    foreach ($debourses as $debourseRow) {
        $debourseId = (int)($debourseRow['id'] ?? 0);
        if ($debourseId > 0) {
            $debourseIds[] = $debourseId;
        }
    }

    $debourseIds = array_values(array_unique($debourseIds));
    if ($debourseIds !== []) {
        $placeholders = implode(',', array_fill(0, count($debourseIds), '?'));
        $stmtSousLignes = $pdo->prepare("
            SELECT l.id, l.debourse_id, l.categorie, l.designation, l.montant, l.date_debut, l.date_fin,
                   COUNT(fd.id_fiche) AS linked_fiche_count
            FROM ligne_debourse_banamur l
            LEFT JOIN fiche_decaissement fd ON fd.ligne_debourse_id = l.id
            WHERE l.debourse_id IN ($placeholders)
            GROUP BY l.id, l.debourse_id, l.categorie, l.designation, l.montant, l.date_debut, l.date_fin
            ORDER BY l.id ASC
        ");
        $stmtSousLignes->execute($debourseIds);
        foreach (($stmtSousLignes->fetchAll(PDO::FETCH_ASSOC) ?: []) as $sousLigneRow) {
            $parentDebourseId = (int)($sousLigneRow['debourse_id'] ?? 0);
            if ($parentDebourseId > 0) {
                $sousLignesByDebourseId[$parentDebourseId][] = $sousLigneRow;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Gérer le déboursé - Devis <?= htmlspecialchars($devis['numero_devis'] ?? '') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/custom_style_gerer_debourse.css">

    <style>
        /* Accordéon déboursé piloté sans Bootstrap Collapse pour éviter l'inversion ouverture/fermeture. */
        .line-panel.d-none {
            display: none !important;
        }

        .line-panel.is-open {
            display: block !important;
        }

        #planning-debourse { scroll-margin-top: 24px; }
        #planning-debourse:target { border-radius: 22px; box-shadow: 0 0 0 4px rgba(250,189,2,.28),0 18px 45px rgba(29,43,87,.14); }
        .planning-focus-note { display:flex;align-items:center;gap:12px;margin:0 0 18px;padding:14px 16px;border:1px solid #f6d777;border-radius:14px;background:#fff9e8;color:#594400; }
        .planning-focus-note i { display:grid;width:38px;height:38px;place-items:center;border-radius:11px;background:#fabd02;color:#172033; }
        .planning-focus-note strong { display:block; }
        .planning-focus-note small { color:#74652f; }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="https://fidest.ci/logo/new_logo_banamur.jpg" alt="Logo">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <?php include 'menu.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container py-4 debourse-page-shell">
        <section class="hero-panel mb-4">
            <div class="hero-main">
                <div class="hero-copy">
                    <span class="hero-kicker">Pilotage du déboursé</span>
                    <h1 class="hero-title">
                        Gérer le déboursé du devis
                        <span><?= htmlspecialchars($devis['numero_devis'] ?? '') ?></span>
                    </h1>
                    <p class="hero-text">Renseignez les postes de coût, ligne par ligne.</p>
                    <div class="hero-tags">
                        <span class="hero-tag"><i class="fas fa-user-tie"></i> Client : <?= htmlspecialchars($devis['destine_a'] ?? 'Non renseigné') ?></span>
                        <span class="hero-tag"><i class="fas fa-file-invoice-dollar"></i> Total TTC : <?= number_format($devis['total_ttc'] ?? 0, 0, ',', ' ') ?> FCFA</span>
                        <span class="hero-tag"><i class="fas fa-layer-group"></i> <?= (int)$lineCount ?> ligne(s) à piloter</span>
                    </div>
                </div>
                <div class="hero-actions">
                    <details class="export-menu"><summary><i class="fas fa-download"></i> Exports</summary><div class="export-stack">
                        <a href="request/export_debourse_pdf.php?devisId=<?= (int)$devisId ?>" target="_blank" rel="noopener" class="btn btn-primary btn-hero-action">
                            <i class="fas fa-file-pdf"></i> Export PDF
                        </a>
                        <a href="request/export_debourse_excel.php?devisId=<?= (int)$devisId ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-hero-action">
                            <i class="fas fa-file-excel"></i> Export Excel
                        </a>
                        <a href="request/export_planning_gantt.php?devisId=<?= (int)$devisId ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-hero-action">
                            <i class="fas fa-project-diagram"></i> Export Gantt
                        </a>
                    </div></details>
                    <div class="hero-side-actions">
                        <?php if ($canEdit): ?>
                            <button class="btn btn-success btn-hero-action" id="btnAddDebourse" data-bs-toggle="modal" data-bs-target="#modalAddDebourse">
                                <i class="fas fa-plus"></i> Nouveau déboursé
                            </button>
                        <?php endif; ?>
                        <a href="liste_devis.php" class="btn btn-outline-secondary btn-hero-action">
                            <i class="fas fa-arrow-left"></i> Retour à la liste
                        </a>
                    </div>
                </div>
            </div>
            <div class="hero-progress-block">
                <div>
                    <span class="hero-progress-label">Couverture du déboursé</span>
                    <strong id="coverageRateText"><?= (int)$coverageRate ?>%</strong>
                </div>
                <div class="hero-progress-track">
                    <div class="hero-progress-value" id="coverageRateBar" style="width: <?= (int)$coverageRate ?>%"></div>
                </div>
            </div>
        </section>

        <section class="stats-grid mb-4" id="debourseStats">
            <article class="stat-card">
                <span class="stat-icon"><i class="fas fa-scale-balanced"></i></span>
                <div>
                    <span class="stat-label">Budget devis</span>
                    <strong class="stat-value" id="statBudgetDevis"><?= number_format($totalMontantDevis, 0, ',', ' ') ?> FCFA</strong>
                </div>
            </article>
            <article class="stat-card stat-card-accent">
                <span class="stat-icon"><i class="fas fa-coins"></i></span>
                <div>
                    <span class="stat-label">Déboursé saisi</span>
                    <strong class="stat-value" id="statDebourse"><?= number_format($totalMontantDebourse, 0, ',', ' ') ?> FCFA</strong>
                </div>
            </article>
            <article class="stat-card <?= $totalReste < 0 ? 'stat-card-danger' : '' ?>">
                <span class="stat-icon"><i class="fas fa-wallet"></i></span>
                <div>
                    <span class="stat-label">Reste à couvrir</span>
                    <strong class="stat-value" id="statReste"><?= number_format($totalReste, 0, ',', ' ') ?> FCFA</strong>
                </div>
            </article>
            <article class="stat-card">
                <span class="stat-icon"><i class="fas fa-triangle-exclamation"></i></span>
                <div>
                    <span class="stat-label">Lignes en dépassement</span>
                    <strong class="stat-value" id="statDepassements"><?= (int)$depassementCount ?></strong>
                </div>
            </article>
        </section>

        <section class="toolbar-panel mb-4">
            <div class="toolbar-head">
                <div>
                    <h2 class="toolbar-title">Lignes du devis</h2>
                </div>
                <div class="toolbar-badges">
                    <span class="toolbar-badge"><i class="fas fa-eye"></i> <span id="visibleCount"><?= (int)$lineCount ?></span> ligne(s) visibles</span>
                    <span class="toolbar-badge"><i class="fas fa-pen-ruler"></i> <span id="editedCount"><?= (int)$renseigneCount ?></span> ligne(s) renseignée(s)</span>
                </div>
            </div>
            <div class="toolbar-grid">
                <div class="toolbar-field toolbar-field-search">
                    <label for="lineSearch" class="form-label">Rechercher une ligne</label>
                    <div class="search-input-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" id="lineSearch" class="form-control" placeholder="Désignation, zone, groupe, responsable…">
                    </div>
                </div>
                <div class="toolbar-field">
                    <label for="lineStatusFilter" class="form-label">Statut</label>
                    <select id="lineStatusFilter" class="form-select">
                        <option value="">Toutes les lignes</option>
                        <option value="depassement">En dépassement</option>
                        <option value="renseigne">Déjà renseignées</option>
                        <option value="a-saisir">À compléter</option>
                    </select>
                </div>
                <div class="toolbar-field">
                    <label for="lineResponsableFilter" class="form-label">Responsable</label>
                    <select id="lineResponsableFilter" class="form-select">
                        <option value="">Tous les responsables</option>
                        <?php foreach (($utilisateurs ?? []) as $user): ?>
                            <option value="<?= (int)($user['id'] ?? 0) ?>"><?= htmlspecialchars($user['nom'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <details class="toolbar-field toolbar-field-actions display-options">
                    <summary class="form-label">Options d’affichage <i class="fas fa-chevron-down"></i></summary>
                    <div class="toolbar-buttons">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="expandAllBtn"><i class="fas fa-arrows-up-down"></i> Tout ouvrir</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="collapseAllBtn"><i class="fas fa-compress"></i> Tout réduire</button>
                        <button type="button" class="btn btn-outline-dark btn-sm" id="resetFiltersBtn"><i class="fas fa-rotate-left"></i> Réinitialiser</button>
                    </div>
                </details>
            </div>
        </section>

        <section id="debourseIssues" class="debourse-issues d-none mb-4" role="alert" aria-live="assertive"></section>
        <form id="form-debourse" method="post">
            <input type="hidden" name="devis_id" value="<?= (int)$devisId ?>">
            <div class="line-list" id="planning-debourse">
                <div id="debourseLineList">
                <?php $index = 0; ?>
                <?php foreach (($lignes ?? []) as $ligne): ?>
                    <?php
                    $index++;
                    $ligneId = (int)($ligne['id'] ?? 0);
                    $debourse = $debourses[$ligneId] ?? [];
                    $montantLigne = isset($ligne['prix']) && is_numeric($ligne['prix']) ? (float)$ligne['prix'] : 0.0;
                    $quantiteLigne = isset($ligne['quantite']) && is_numeric($ligne['quantite']) ? (float)$ligne['quantite'] : 0.0;
                    $totalLigne = $montantLigne * $quantiteLigne;
                    if ($totalLigne <= 0) {
                        $totalLigne = $montantLigne;
                    }
                    $totalDebourseLigne = isset($debourse['montant_debourse']) && is_numeric($debourse['montant_debourse']) ? (float)$debourse['montant_debourse'] : 0.0;
                    $depassement = ($totalLigne > 0 && $totalDebourseLigne > $totalLigne);
                    $status = $depassement ? 'depassement' : ($totalDebourseLigne > 0 ? 'renseigne' : 'a-saisir');
                    $statusLabel = $depassement ? 'Dépassement' : ($totalDebourseLigne > 0 ? 'Renseigné' : 'À saisir');
                    $responsableId = (int)($debourse['responsable_id'] ?? 0);
                    $zone = trim((string)($ligne['zone'] ?? ''));
                    $groupe = trim((string)($ligne['groupe'] ?? ''));
                    $searchBlob = trim(implode(' ', [
                        (string)($ligne['designation'] ?? ''),
                        $zone,
                        $groupe,
                    ]));
                    $progress = $totalLigne > 0 ? max(0, min(100, round(($totalDebourseLigne / $totalLigne) * 100))) : 0;
                    $defaultExpanded = ($focusDebourseId > 0 && (int)($debourse['id'] ?? 0) === $focusDebourseId) || $depassement;
                    ?>
                    <article
                        class="line-card<?= $focusDebourseId > 0 && (int)($debourse['id'] ?? 0) === $focusDebourseId ? ' border border-primary shadow-lg' : '' ?>"
                        id="<?= (int)($debourse['id'] ?? 0) > 0 ? 'debourse-' . (int)$debourse['id'] : 'ligne-devis-' . $ligneId ?>"
                        data-line-card="<?= $ligneId ?>"
                        data-line-search="<?= htmlspecialchars(mb_strtolower($searchBlob, 'UTF-8')) ?>"
                        data-line-status="<?= htmlspecialchars($status) ?>"
                        data-line-responsable="<?= (int)$responsableId ?>"
                        data-line-budget="<?= htmlspecialchars((string)$totalLigne) ?>"
                        data-line-debourse="<?= htmlspecialchars((string)$totalDebourseLigne) ?>">
                        <div class="line-card-shell">
                            <header class="line-card-header">
                                <div class="line-card-index"><?= (int)$index ?></div>
                                <div class="line-card-head-main">
                                    <div class="line-card-title-row">
                                        <h3 class="line-card-title"><?= htmlspecialchars($ligne['designation'] ?? 'Ligne sans désignation') ?></h3>
                                        <span class="line-status-badge line-status-<?= htmlspecialchars($status) ?>" data-line-status-label="<?= $ligneId ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                    </div>
                                    <div class="line-card-meta">
                                        <?php if ($zone !== ''): ?><span><i class="fas fa-location-dot"></i> Zone : <?= htmlspecialchars($zone) ?></span><?php endif; ?>
                                        <?php if ($groupe !== ''): ?><span><i class="fas fa-layer-group"></i> Groupe : <?= htmlspecialchars($groupe) ?></span><?php endif; ?>
                                        <span><i class="fas fa-hashtag"></i> Ligne ID : <?= (int)$ligneId ?></span>
                                    </div>
                                </div>
                                <div class="line-card-head-side">
                                    <div class="line-amounts">
                                        <div>
                                            <span>Budget devis</span>
                                            <strong data-line-budget-text="<?= $ligneId ?>"><?= number_format($totalLigne, 0, ',', ' ') ?> FCFA</strong>
                                        </div>
                                        <div>
                                            <span>Déboursé</span>
                                            <strong data-line-debourse-text="<?= $ligneId ?>"><?= number_format($totalDebourseLigne, 0, ',', ' ') ?> FCFA</strong>
                                        </div>
                                        <div>
                                            <span>Écart</span>
                                            <strong data-line-gap-text="<?= $ligneId ?>"><?= number_format($totalLigne - $totalDebourseLigne, 0, ',', ' ') ?> FCFA</strong>
                                        </div>
                                    </div>
                                    <div class="line-header-actions">
                                        <?php if ($canEdit): ?>
                                            <button type="button" class="btn btn-outline-primary btn-sm" data-save-line="<?= $ligneId ?>">
                                                <i class="fas fa-save"></i> Enregistrer
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-dark btn-sm js-toggle-line-panel" data-line-toggle="<?= $ligneId ?>" aria-expanded="<?= $defaultExpanded ? 'true' : 'false' ?>" aria-controls="linePanel<?= $ligneId ?>">
                                            <i class="fas fa-sliders"></i> Détails
                                        </button>
                                    </div>
                                </div>
                            </header>

                            <div class="line-progress-wrap">
                                <div class="line-progress-track">
                                    <div class="line-progress-value <?= $depassement ? 'is-danger' : '' ?>" data-line-progress="<?= $ligneId ?>" style="width: <?= (int)$progress ?>%"></div>
                                </div>
                                <span class="line-progress-text" data-line-progress-text="<?= $ligneId ?>"><?= (int)$progress ?>%</span>
                            </div>

                            <div class="line-panel <?= $defaultExpanded ? 'is-open' : 'd-none' ?>" id="linePanel<?= $ligneId ?>" data-line-panel="<?= $ligneId ?>">
                                <div class="line-card-body">
                                    <div class="alert alert-depassement mb-3 <?= $depassement ? '' : 'd-none' ?>" data-line-alert="<?= $ligneId ?>">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        Attention : le montant déboursé dépasse actuellement le montant du devis.
                                    </div>

                                    <div class="line-form-grid">
                                        <div class="field-card">
                                            <label class="form-label">Montant déboursé</label>
                                            <input type="number" min="0" step="0.01" class="form-control montant-debourse text-end" name="montant_debourse[<?= $ligneId ?>]" data-ligne="<?= $ligneId ?>" value="<?= htmlspecialchars($debourse['montant_debourse'] ?? '') ?>" readonly>
                                            <small class="form-text text-muted">Calculé automatiquement à partir des sous-lignes.</small>
                                        </div>
                                        <div class="field-card">
                                            <label class="form-label">Responsable</label>
                                            <input type="search" class="form-control mb-2" data-responsable-search="<?= $ligneId ?>" placeholder="Rechercher un responsable…" aria-label="Rechercher un responsable pour la ligne <?= $ligneId ?>">
                                            <select class="form-select" name="responsable_id[<?= $ligneId ?>]" data-responsable-select="<?= $ligneId ?>">
                                                <option value="">-- Sélectionner --</option>
                                                <?php $iUser = 0; ?>
                                                <?php foreach (($utilisateurs ?? []) as $user): ?>
                                                    <?php
                                                    $selected = (!empty($debourse['responsable_id']) && (int)$debourse['responsable_id'] === (int)$user['id']) ? 'selected' : ((empty($debourse['responsable_id']) && $iUser === 2) ? 'selected' : '');
                                                    $iUser++;
                                                    ?>
                                                    <option value="<?= (int)$user['id'] ?>" <?= $selected ?>><?= htmlspecialchars($user['nom'] ?? '') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="field-card">
                                            <label class="form-label">Dates principales</label>
                                            <div class="input-group">
                                                <input type="date" class="form-control" name="date_debut[<?= $ligneId ?>]" value="<?= htmlspecialchars($debourse['date_debut'] ?? '') ?>">
                                                <span class="input-group-text">au</span>
                                                <input type="date" class="form-control" name="date_fin[<?= $ligneId ?>]" value="<?= htmlspecialchars($debourse['date_fin'] ?? '') ?>">
                                            </div>
                                        </div>
                                    </div>

                                    <?php
                                    $debourseId = (int)($debourse['id'] ?? 0);
                                    $sousLignes = $debourseId > 0 ? ($sousLignesByDebourseId[$debourseId] ?? []) : [];
                                    ?>
                                    <section class="subline-panel souslignes-container" id="sousLignes<?= $ligneId ?>" data-ligne="<?= $ligneId ?>" data-loaded="1">
                                        <div class="subline-head">
                                            <div>
                                                <h4><i class="fas fa-stream"></i> Sous-lignes de déboursé</h4>
                                                <p>Ajoute des postes détaillés par catégorie et montant. Ils suivent la période du déboursé ci-dessus.</p>
                                            </div>
                                            <div class="subline-actions">
                                                <span class="subline-count" data-subline-count="<?= $ligneId ?>"><?= count($sousLignes) ?> ligne<?= count($sousLignes) > 1 ? 's' : '' ?></span>
                                                <?php if ($canEdit): ?>
                                                    <button type="button" class="btn btn-success btn-sm" data-add-subline="<?= $ligneId ?>"><i class="fas fa-plus"></i> Ajouter une sous-ligne</button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="table-responsive">
                                            <table class="table table-bordered table-debourse align-middle mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Catégorie</th>
                                                        <th>Désignation</th>
                                                        <th>Montant</th>
                                                        <th class="text-center">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="tbody-sous-lignes-<?= $ligneId ?>">
                                                    <?php foreach ($sousLignes as $sousLigne): ?>
                                                        <?php
                                                        $sousLigneId = (int)($sousLigne['id'] ?? 0);
                                                        $linkedFicheCount = (int)($sousLigne['linked_fiche_count'] ?? 0);
                                                        $isProtected = $linkedFicheCount > 0;
                                                        ?>
                                                        <tr class="ligne-sous-debourse" data-subline-id="<?= $sousLigneId ?>" data-linked-fiche-count="<?= $linkedFicheCount ?>">
                                                            <td class="subline-category-cell">
                                                                <div class="subline-category-stack">
                                                                    <div class="subline-inline-status d-none" data-subline-inline-status><span class="subline-new-badge">NOUVELLE LIGNE</span></div>
                                                                    <div class="subline-category-main">
                                                                        <input type="hidden" name="sous_ligne_id[<?= $ligneId ?>][]" value="<?= $sousLigneId ?>">
                                                                        <select name="categorie[<?= $ligneId ?>][]" class="form-select" required>
                                                                            <?= getCategorieOptions((string)($sousLigne['categorie'] ?? '')) ?>
                                                                        </select>
                                                                    </div>
                                                                    <div class="subline-save-hint-wrap">
                                                                        <?php if ($isProtected): ?>
                                                                            <span class="subline-save-hint text-danger">Liée à <?= $linkedFicheCount ?> fiche<?= $linkedFicheCount > 1 ? 's' : '' ?> de décaissement.</span>
                                                                        <?php else: ?>
                                                                            <span class="subline-save-hint" data-subline-save-hint></span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                            <td><input type="text" name="designation[<?= $ligneId ?>][]" class="form-control" value="<?= htmlspecialchars((string)($sousLigne['designation'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></td>
                                                            <td><input type="number" name="montant_sous_ligne[<?= $ligneId ?>][]" class="form-control text-end" min="0" step="0.01" value="<?= htmlspecialchars((string)($sousLigne['montant'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required></td>
                                                            <td class="text-center">
                                                                <button type="button" class="btn btn-outline-danger btn-sm" data-remove-subline="<?= $ligneId ?>" <?= $isProtected ? 'disabled aria-disabled="true" title="Suppression impossible: cette sous-ligne est déjà liée à une fiche de décaissement."' : 'title="Supprimer cette sous-ligne"' ?>>
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="subline-empty <?= $sousLignes !== [] ? 'd-none' : '' ?>" data-subline-empty="<?= $ligneId ?>">
                                            <i class="fas fa-inbox"></i>
                                            <span>Aucune sous-ligne pour le moment. Commence par ajouter le premier poste.</span>
                                        </div>
                                        <?php if ($canEdit): ?>
                                            <div class="subline-footer-actions">
                                                <button type="button" class="btn btn-outline-success btn-sm" data-add-subline="<?= $ligneId ?>"><i class="fas fa-plus"></i> Ajouter une sous-ligne</button>
                                                <button type="button" class="btn btn-primary btn-sm" data-save-line="<?= $ligneId ?>"><i class="fas fa-save"></i> Enregistrer cette ligne</button>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="empty-filter-state d-none" id="emptyFilterState">
                <i class="fas fa-filter-circle-xmark"></i>
                <h3>Aucune ligne ne correspond aux filtres</h3>
                <p>Modifie ta recherche ou réinitialise les filtres pour retrouver les postes du devis.</p>
            </div>

            <div class="save-bar mt-4">
                <div>
                    <h3>Enregistrement global</h3>
                    <p>Utilise cette action pour consolider toutes les lignes, même celles qui n’ont pas encore été ouvertes à l’écran.</p>
                </div>
                <div class="save-bar-actions">
                    <span id="saveAllStatus" class="save-all-status"></span>
                    <?php if ($canEdit): ?>
                        <button type="submit" class="btn btn-lg btn-primary px-4" id="saveAllBtn">
                            <i class="fas fa-save"></i> Enregistrer tous les déboursés
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </form>

        <div id="alert-debourse" class="mt-3"></div>
    </div>

    <footer class="footer text-white text-center py-3 bg-dark">
        <div class="container">
            <p>&copy; <?= gmdate('Y'); ?> BANAMUR INDUSTRIES & TECH. Tous droits réservés.</p>
            <div class="social-icons">
                <a href="#" class="fab fa-facebook-f"></a>
                <a href="#" class="fab fa-twitter"></a>
                <a href="#" class="fab fa-linkedin-in"></a>
            </div>
        </div>
    </footer>

    <?php if ($canEdit): ?>
        <div class="modal fade" id="modalAddDebourse" tabindex="-1" aria-labelledby="modalAddDebourseLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form id="formAddDebourse" class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalAddDebourseLabel">Nouveau déboursé</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="addDesignation" class="form-label">Désignation</label>
                            <input type="text" class="form-control" name="designation" id="addDesignation" required>
                        </div>
                        <div class="mb-3">
                            <label for="addMontant" class="form-label">Montant</label>
                            <input type="number" class="form-control" name="montant" id="addMontant" required>
                        </div>
                        <div class="mb-3">
                            <label for="addDateDebut" class="form-label">Date début</label>
                            <input type="date" class="form-control" name="date_debut" id="addDateDebut" required>
                        </div>
                        <div class="mb-3">
                            <label for="addDateFin" class="form-label">Date fin</label>
                            <input type="date" class="form-control" name="date_fin" id="addDateFin" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                        <button type="submit" class="btn btn-primary">Ajouter</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            const canEdit = <?= $canEdit ? 'true' : 'false' ?>;
            const devisId = <?= (int)$devisId ?>;
            const categoryOptionsHtml = <?= json_encode(getCategorieOptions('')) ?>;
            const lineCards = Array.from(document.querySelectorAll('[data-line-card]'));
            const focusedDebourse = <?= json_encode($focusDebourseId > 0 ? 'debourse-' . $focusDebourseId : '') ?>;
            if (focusedDebourse) {
                window.setTimeout(function () {
                    document.getElementById(focusedDebourse)?.scrollIntoView({behavior:'smooth',block:'center'});
                }, 350);
            }
            const alertsWrap = document.getElementById('alert-debourse');
            const emptyFilterState = document.getElementById('emptyFilterState');
            const lineSearch = document.getElementById('lineSearch');
            const lineStatusFilter = document.getElementById('lineStatusFilter');
            const lineResponsableFilter = document.getElementById('lineResponsableFilter');
            const visibleCount = document.getElementById('visibleCount');
            const editedCount = document.getElementById('editedCount');
            const saveAllStatus = document.getElementById('saveAllStatus');
            const saveAllBtn = document.getElementById('saveAllBtn');
            const coverageRateText = document.getElementById('coverageRateText');
            const coverageRateBar = document.getElementById('coverageRateBar');
            const statBudgetDevis = document.getElementById('statBudgetDevis');
            const statDebourse = document.getElementById('statDebourse');
            const statReste = document.getElementById('statReste');
            const statDepassements = document.getElementById('statDepassements');
            const loadingPromises = {};

            function formatFcfa(value) {
                const number = Number(value || 0);
                const rounded = Number.isFinite(number) ? Math.round(number) : 0;
                return String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            }

            function setAlert(type, message) {
                if (!alertsWrap) return;
                if (!message) {
                    alertsWrap.innerHTML = '';
                    return;
                }
                alertsWrap.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' + escapeHtml(message) + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button></div>';
            }

            function showLineIssue(lineId, message, needsRefresh) {
                const box = document.getElementById('debourseIssues');
                const card = getLineCard(lineId);
                if (!box) return;
                document.querySelectorAll('.line-card.has-save-issue').forEach(function(node) { node.classList.remove('has-save-issue'); });
                if (card) {
                    card.classList.remove('d-none');
                    card.classList.add('has-save-issue');
                    setPanelState(document.getElementById('linePanel' + lineId), true);
                }
                const title = card ? card.querySelector('.line-card-title')?.textContent.trim() : '';
                box.classList.remove('d-none');
                box.innerHTML = '<div class="debourse-issues-head"><span class="debourse-issues-icon"><i class="fas fa-triangle-exclamation"></i></span><div><small>Correction ciblée · ligne de devis #' + lineId + '</small><h2>' + escapeHtml(title || 'Ligne à vérifier') + '</h2></div></div>' +
                    '<p>' + escapeHtml(message || 'L’enregistrement n’a pas abouti.') + '</p>' +
                    '<ol><li>Ouvrez la ligne indiquée ci-dessous et repérez le poste concerné dans « Sous-lignes de déboursé ».</li>' +
                    (needsRefresh ? '<li>Si la sous-ligne n’existe plus, rechargez ses postes depuis la base. Cette opération remplacera les modifications non enregistrées de cette ligne.</li>' : '<li>Complétez ou corrigez les champs signalés : catégorie, désignation, montant, responsable et dates.</li>') +
                    '<li>Vérifiez le total calculé, puis cliquez sur « Enregistrer cette ligne ».</li></ol>' +
                    '<div class="debourse-issues-actions"><button type="button" class="btn btn-dark" data-focus-issue="' + lineId + '">Aller au champ à corriger</button>' +
                    (needsRefresh ? '<button type="button" class="btn btn-outline-danger" data-refresh-issue="' + lineId + '">Recharger les postes de cette ligne</button>' : '') +
                    '<button type="button" class="btn btn-outline-primary" data-export-issue="' + lineId + '"><i class="fas fa-file-pdf me-1"></i> Télécharger les consignes PDF</button></div>';
                box.dataset.issue = message || '';
                box.dataset.needsRefresh = needsRefresh ? '1' : '0';
                box.scrollIntoView({behavior:'smooth',block:'start'});
            }

            function normalizeText(value) {
                return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
            }

            function getLineCard(ligneId) {
                return document.querySelector('[data-line-card="' + ligneId + '"]');
            }

            function getTbody(ligneId) {
                return document.getElementById('tbody-sous-lignes-' + ligneId);
            }

            function updateSublineEmptyState(ligneId) {
                const tbody = getTbody(ligneId);
                const empty = document.querySelector('[data-subline-empty="' + ligneId + '"]');
                const countNode = document.querySelector('[data-subline-count="' + ligneId + '"]');
                const count = tbody ? tbody.querySelectorAll('tr').length : 0;
                if (empty) empty.classList.toggle('d-none', count > 0);
                if (countNode) countNode.textContent = count + ' ligne' + (count > 1 ? 's' : '');
            }

            function escapeHtml(value) {
                return String(value || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
            }

            function buildCategoryOptions(selected) {
                return String(categoryOptionsHtml || '').replace(new RegExp('value="' + String(selected || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '"'), 'value="' + escapeHtml(selected || '') + '" selected');
            }

            function appendSublineRow(ligneId, item, options) {
                const tbody = getTbody(ligneId);
                if (!tbody) return;
                const insertAtTop = !!(options && options.insertAtTop);
                const focusField = !!(options && options.focusField);
                const markAsDraft = !!(options && options.markAsDraft);
                const sousLigneId = Number((item && item.id) || 0);
                const linkedFicheCount = Number((item && item.linked_fiche_count) || 0);
                const isProtected = linkedFicheCount > 0;
                const categorie = String((item && item.categorie) || '');
                const designation = String((item && item.designation) || '');
                const montant = String((item && item.montant) || '');
                const row = document.createElement('tr');
                row.className = 'ligne-sous-debourse';
                row.setAttribute('data-subline-id', sousLigneId > 0 ? String(sousLigneId) : '');
                row.setAttribute('data-linked-fiche-count', String(linkedFicheCount));
                if (markAsDraft) {
                    row.classList.add('is-draft-subline');
                    row.setAttribute('data-new-subline', '1');
                }
                const protectionHint = isProtected ? '<span class="subline-save-hint text-danger">Liée à ' + linkedFicheCount + ' fiche' + (linkedFicheCount > 1 ? 's' : '') + ' de décaissement.</span>' : '<span class="subline-save-hint" data-subline-save-hint></span>';
                const removeDisabledAttr = isProtected ? ' disabled aria-disabled="true"' : '';
                const removeTitle = isProtected ? ' title="Suppression impossible: cette sous-ligne est déjà liée à une fiche de décaissement."' : ' title="Supprimer cette sous-ligne"';
                row.innerHTML = '<td class="subline-category-cell"><div class="subline-category-stack"><div class="subline-inline-status d-none" data-subline-inline-status><span class="subline-new-badge">NOUVELLE LIGNE</span></div><div class="subline-category-main"><input type="hidden" name="sous_ligne_id[' + ligneId + '][]" value="' + escapeHtml(sousLigneId > 0 ? String(sousLigneId) : '') + '"><select name="categorie[' + ligneId + '][]" class="form-select" required>' + buildCategoryOptions(categorie) + '</select></div><div class="subline-save-hint-wrap">' + protectionHint + '</div></div></td>' +
                    '<td><input type="text" name="designation[' + ligneId + '][]" class="form-control" value="' + escapeHtml(designation) + '" required></td>' +
                    '<td><input type="number" name="montant_sous_ligne[' + ligneId + '][]" class="form-control text-end" min="0" step="0.01" value="' + escapeHtml(montant) + '" required></td>' +
                    '<td class="text-center"><button type="button" class="btn btn-outline-danger btn-sm" data-remove-subline="' + ligneId + '"' + removeDisabledAttr + removeTitle + '><i class="fas fa-trash"></i></button></td>';
                if (insertAtTop && tbody.firstChild) {
                    tbody.insertBefore(row, tbody.firstChild);
                } else {
                    tbody.appendChild(row);
                }
                syncSublineVisualState(row);
                updateSublineEmptyState(ligneId);

                if (focusField) {
                    const firstInput = row.querySelector('input[type="text"], select, input:not([type="hidden"])');
                    if (firstInput) firstInput.focus();
                    row.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
                    });
                }
            }

            function isSublineRequiredFieldsComplete(row) {
                if (!row) return false;
                const requiredFields = Array.from(row.querySelectorAll('select[required], input[required]'));
                return requiredFields.every(function(field) {
                    return String(field.value || '').trim() !== '';
                });
            }

            function syncSublineVisualState(row) {
                if (!row || row.getAttribute('data-new-subline') !== '1') return;
                const isComplete = isSublineRequiredFieldsComplete(row);
                const statusWrap = row.querySelector('[data-subline-inline-status]');
                const hint = row.querySelector('[data-subline-save-hint]');

                row.classList.toggle('is-draft-subline', !isComplete);
                row.classList.toggle('is-ready-subline', isComplete);

                if (statusWrap) statusWrap.classList.remove('d-none');
                if (hint) {
                    hint.textContent = isComplete ? 'Champs remplis. Clique sur "Enregistrer cette ligne" en bas du bloc pour valider.' : 'Complète les champs obligatoires puis clique sur "Enregistrer cette ligne" en bas du bloc.';
                }
            }

            function clearDraftSublineState(ligneId) {
                const tbody = getTbody(ligneId);
                if (!tbody) return;
                tbody.querySelectorAll('[data-new-subline="1"]').forEach(function(row) {
                    row.classList.remove('is-draft-subline');
                    row.classList.remove('is-ready-subline');
                    row.removeAttribute('data-new-subline');
                    const statusWrap = row.querySelector('[data-subline-inline-status]');
                    if (statusWrap) statusWrap.classList.add('d-none');
                });
            }

            function loadSousLignes(ligneId, forceReload) {
                const section = document.getElementById('sousLignes' + ligneId);
                const tbody = getTbody(ligneId);
                if (!section || !tbody) return Promise.resolve();
                if (!forceReload && section.getAttribute('data-loaded') === '1') {
                    updateSublineEmptyState(ligneId);
                    return Promise.resolve();
                }
                if (!forceReload && loadingPromises[ligneId]) {
                    return loadingPromises[ligneId];
                }

                section.classList.add('is-loading');
                const request = $.getJSON('request/get_lignes_debourse.php', {
                    devis_id: devisId,
                    ligne_devis_id: ligneId
                }).then(function(resp) {
                    if (!resp || !Array.isArray(resp.items) || resp.error) throw new Error(resp && resp.error ? resp.error : 'Réponse invalide du serveur.');
                    tbody.innerHTML = '';
                    resp.items.forEach(function(item) { appendSublineRow(ligneId, item); });
                    section.setAttribute('data-loaded', '1');
                    updateSublineEmptyState(ligneId);
                    recalcMontant(ligneId);
                    return true;
                }).catch(function(error) {
                    showLineIssue(ligneId, 'Impossible de charger les sous-lignes : ' + (error && error.message ? error.message : 'réessayez.'), false);
                    return false;
                }).always(function() {
                    section.classList.remove('is-loading');
                    delete loadingPromises[ligneId];
                });

                loadingPromises[ligneId] = request;
                return request;
            }

            function recalcMontant(ligneId) {
                const tbody = getTbody(ligneId);
                if (!tbody) return;
                let total = 0;
                tbody.querySelectorAll('input[name^="montant_sous_ligne[' + ligneId + ']"]').forEach(function(input) {
                    const value = parseFloat(input.value);
                    if (!Number.isNaN(value)) total += value;
                });

                const field = document.querySelector('input.montant-debourse[data-ligne="' + ligneId + '"]');
                if (field) field.value = total ? total.toFixed(2) : '';

                const card = getLineCard(ligneId);
                const budget = Number((card && card.getAttribute('data-line-budget')) || 0);
                const gap = budget - total;
                const progress = budget > 0 ? Math.max(0, Math.min(100, Math.round((total / budget) * 100))) : 0;
                const exceeded = budget > 0 && total > budget;
                const status = exceeded ? 'depassement' : (total > 0 ? 'renseigne' : 'a-saisir');
                const statusText = exceeded ? 'Dépassement' : (total > 0 ? 'Renseigné' : 'À saisir');

                if (card) {
                    card.setAttribute('data-line-debourse', String(total));
                    card.setAttribute('data-line-status', status);
                    const responsable = card.querySelector('[data-responsable-select="' + ligneId + '"]');
                    if (responsable) {
                        card.setAttribute('data-line-responsable', responsable.value || '');
                    }
                }

                const debourseText = document.querySelector('[data-line-debourse-text="' + ligneId + '"]');
                if (debourseText) debourseText.textContent = formatFcfa(total) + ' FCFA';

                const gapText = document.querySelector('[data-line-gap-text="' + ligneId + '"]');
                if (gapText) gapText.textContent = formatFcfa(gap) + ' FCFA';

                const progressBar = document.querySelector('[data-line-progress="' + ligneId + '"]');
                if (progressBar) {
                    progressBar.style.width = progress + '%';
                    progressBar.classList.toggle('is-danger', exceeded);
                }

                const progressText = document.querySelector('[data-line-progress-text="' + ligneId + '"]');
                if (progressText) progressText.textContent = progress + '%';

                const statusLabel = document.querySelector('[data-line-status-label="' + ligneId + '"]');
                if (statusLabel) {
                    statusLabel.textContent = statusText;
                    statusLabel.className = 'line-status-badge line-status-' + status;
                }

                const alert = document.querySelector('[data-line-alert="' + ligneId + '"]');
                if (alert) alert.classList.toggle('d-none', !exceeded);
                updateSublineEmptyState(ligneId);
                updateGlobalStats();
                applyFilters();
            }

            function updateGlobalStats() {
                let budgetTotal = 0;
                let debourseTotal = 0;
                let depassements = 0;
                let renseignees = 0;
                let visible = 0;

                lineCards.forEach(function(card) {
                    if (card.classList.contains('d-none')) return;
                    visible++;
                    const budget = Number(card.getAttribute('data-line-budget') || 0);
                    const debourse = Number(card.getAttribute('data-line-debourse') || 0);
                    const status = String(card.getAttribute('data-line-status') || '');
                    budgetTotal += budget;
                    debourseTotal += debourse;
                    if (status === 'depassement') depassements++;
                    if (status !== 'a-saisir' && debourse > 0) renseignees++;
                });

                const reste = budgetTotal - debourseTotal;
                const coverage = budgetTotal > 0 ? Math.max(0, Math.min(100, Math.round((debourseTotal / budgetTotal) * 100))) : 0;
                if (visibleCount) visibleCount.textContent = String(visible);
                if (editedCount) editedCount.textContent = String(renseignees);
                if (statBudgetDevis) statBudgetDevis.textContent = formatFcfa(budgetTotal) + ' FCFA';
                if (statDebourse) statDebourse.textContent = formatFcfa(debourseTotal) + ' FCFA';
                if (statReste) statReste.textContent = formatFcfa(reste) + ' FCFA';
                if (statDepassements) statDepassements.textContent = String(depassements);
                if (coverageRateText) coverageRateText.textContent = coverage + '%';
                if (coverageRateBar) coverageRateBar.style.width = coverage + '%';
            }

            function applyFilters() {
                const search = normalizeText(lineSearch ? lineSearch.value : '');
                const status = String(lineStatusFilter ? lineStatusFilter.value : '');
                const responsable = String(lineResponsableFilter ? lineResponsableFilter.value : '');
                let visible = 0;

                lineCards.forEach(function(card) {
                    const haystack = normalizeText(card.getAttribute('data-line-search') || '');
                    const cardStatus = String(card.getAttribute('data-line-status') || '');
                    const cardResponsable = String(card.getAttribute('data-line-responsable') || '');
                    const show = (!search || haystack.indexOf(search) !== -1) && (!status || cardStatus === status) && (!responsable || cardResponsable === responsable);
                    card.classList.toggle('d-none', !show);
                    if (show) visible++;
                });

                if (emptyFilterState) emptyFilterState.classList.toggle('d-none', visible > 0);
                updateGlobalStats();
            }

            function setPanelState(panel, show) {
                if (!panel) return;
                const lineId = Number(panel.getAttribute('data-line-panel') || 0);
                panel.classList.toggle('d-none', !show);
                panel.classList.toggle('is-open', show);
                document.querySelectorAll('[data-line-toggle="' + lineId + '"]').forEach(function(button) {
                    button.setAttribute('aria-expanded', show ? 'true' : 'false');
                });
                if (show && lineId > 0) {
                    loadSousLignes(lineId);
                }
            }

            function expandAllPanels(show) {
                document.querySelectorAll('[data-line-panel]').forEach(function(panel) {
                    setPanelState(panel, show);
                });
            }

            function collectSousLignesPayload(ligneId) {
                const payload = [];
                document.querySelectorAll('#tbody-sous-lignes-' + ligneId + ' tr').forEach(function(tr) {
                    const sousLigneId = tr.querySelector('input[name^="sous_ligne_id[' + ligneId + '][]"]');
                    const categorie = tr.querySelector('select[name^="categorie[' + ligneId + '][]"]');
                    const designation = tr.querySelector('input[name^="designation[' + ligneId + '][]"]');
                    const montant = tr.querySelector('input[name^="montant_sous_ligne[' + ligneId + '][]"]');
                    payload.push({
                        id: sousLigneId ? sousLigneId.value : '',
                        categorie: categorie ? categorie.value : '',
                        designation: designation ? designation.value : '',
                        montant: montant ? montant.value : ''
                    });
                });
                return payload;
            }

            function setLineSavingState(ligneId, isSaving) {
                document.querySelectorAll('[data-save-line="' + ligneId + '"]').forEach(function(button) {
                    button.disabled = isSaving;
                    button.innerHTML = isSaving ? '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Enregistrement…' : '<i class="fas fa-save"></i> Enregistrer';
                });
            }

            function enregistrerLigne(ligneId) {
                if (!canEdit) return;
                const fd = new FormData();
                fd.append('devis_id', String(devisId));
                fd.append('ligne_devis_id', String(ligneId));

                const montant = document.querySelector('input.montant-debourse[data-ligne="' + ligneId + '"]');
                const responsable = document.querySelector('[name="responsable_id[' + ligneId + ']"]');
                const dateDebut = document.querySelector('[name="date_debut[' + ligneId + ']"]');
                const dateFin = document.querySelector('[name="date_fin[' + ligneId + ']"]');

                fd.append('montant_debourse', montant ? (montant.value || '0') : '0');
                fd.append('responsable_id', responsable ? (responsable.value || '') : '');
                fd.append('date_debut', dateDebut ? (dateDebut.value || '') : '');
                fd.append('date_fin', dateFin ? (dateFin.value || '') : '');
                collectSousLignesPayload(ligneId).forEach(function(item) {
                    fd.append('sous_lignes[]', JSON.stringify(item));
                });

                setLineSavingState(ligneId, true);
                setAlert('', '');
                fetch('request/save_debourse_line.php', {
                    method: 'POST',
                    body: fd
                }).then(function(response) {
                    return response.json();
                }).then(function(resp) {
                    if (resp && resp.success) {
                        loadSousLignes(ligneId, true);
                        document.getElementById('debourseIssues')?.classList.add('d-none');
                        getLineCard(ligneId)?.classList.remove('has-save-issue');
                        setAlert('success', resp.message || 'Ligne enregistrée avec succès.');
                    } else {
                        showLineIssue(ligneId, (resp && resp.message) ? resp.message : 'Erreur lors de la sauvegarde de la ligne.', !!(resp && resp.needs_refresh));
                    }
                }).catch(function() {
                    setAlert('danger', 'Erreur lors de la sauvegarde de la ligne.');
                }).finally(function() {
                    setLineSavingState(ligneId, false);
                });
            }

            function ensureAllSousLignesLoaded() {
                return Promise.all(lineCards.map(function(card) {
                    const lineId = Number(card.getAttribute('data-line-card') || 0);
                    if (!lineId) return Promise.resolve();
                    return loadSousLignes(lineId);
                }));
            }

            document.addEventListener('input', function(event) {
                const target = event.target;
                if (target?.matches('[data-responsable-search]')) {
                    const select = document.querySelector('[data-responsable-select="' + target.getAttribute('data-responsable-search') + '"]');
                    const query = normalizeText(target.value);
                    Array.from(select?.options || []).forEach(function(option) { option.hidden = !!query && !!option.value && !option.selected && !normalizeText(option.text).includes(query); });
                    return;
                }
                if (!target || !target.name) return;
                const row = target.closest('tr');
                if (row && row.getAttribute('data-new-subline') === '1') {
                    syncSublineVisualState(row);
                }
                const match = target.name.match(/^montant_sous_ligne\[(\d+)\]\[\]$/);
                if (match) recalcMontant(Number(match[1]));
            });

            document.addEventListener('change', function(event) {
                const target = event.target;
                if (!target) return;
                const row = target.closest('tr');
                if (row && row.getAttribute('data-new-subline') === '1') {
                    syncSublineVisualState(row);
                }
                if (target.matches('[data-responsable-select]')) {
                    const lineId = Number(target.getAttribute('data-responsable-select') || 0);
                    const card = getLineCard(lineId);
                    if (card) {
                        card.setAttribute('data-line-responsable', target.value || '');
                        card.setAttribute('data-line-search', normalizeText((card.getAttribute('data-line-search') || '') + ' ' + target.options[target.selectedIndex].text));
                    }
                    applyFilters();
                }
            });

            document.addEventListener('click', function(event) {
                const exportIssue = event.target.closest('[data-export-issue]');
                if (exportIssue) {
                    const box = document.getElementById('debourseIssues');
                    const form = document.createElement('form');
                    form.method = 'post'; form.action = 'request/export_debourse_corrections_pdf.php'; form.target = '_blank'; form.style.display = 'none';
                    Object.entries({devis_id:devisId,ligne_id:exportIssue.getAttribute('data-export-issue'),issue:box?.dataset.issue || '',needs_refresh:box?.dataset.needsRefresh || '0'}).forEach(function(entry) { const field=document.createElement('input'); field.type='hidden'; field.name=entry[0]; field.value=String(entry[1]); form.appendChild(field); });
                    document.body.appendChild(form); form.submit(); form.remove(); return;
                }
                const focusIssue = event.target.closest('[data-focus-issue]');
                if (focusIssue) {
                    const lineId = Number(focusIssue.getAttribute('data-focus-issue') || 0);
                    const card = getLineCard(lineId);
                    setPanelState(document.getElementById('linePanel' + lineId), true);
                    card?.scrollIntoView({behavior:'smooth',block:'start'});
                    window.setTimeout(function() { card?.querySelector('.souslignes-container input:not([type="hidden"])')?.focus(); }, 350);
                    return;
                }
                const refreshIssue = event.target.closest('[data-refresh-issue]');
                if (refreshIssue) {
                    const lineId = Number(refreshIssue.getAttribute('data-refresh-issue') || 0);
                    if (!window.confirm('Recharger les postes de cette ligne depuis la base ? Les modifications non enregistrées de cette ligne seront perdues.')) return;
                    loadSousLignes(lineId, true).then(function(loaded) { if (loaded) { document.getElementById('debourseIssues')?.classList.add('d-none'); getLineCard(lineId)?.classList.remove('has-save-issue'); } });
                    return;
                }
                const toggleBtn = event.target.closest('[data-line-toggle]');
                if (toggleBtn) {
                    event.preventDefault();
                    event.stopPropagation();
                    const lineId = Number(toggleBtn.getAttribute('data-line-toggle') || 0);
                    const panel = document.getElementById('linePanel' + lineId);
                    if (panel) {
                        setPanelState(panel, panel.classList.contains('d-none'));
                    }
                    return;
                }

                const addBtn = event.target.closest('[data-add-subline]');
                if (addBtn) {
                    const lineId = Number(addBtn.getAttribute('data-add-subline') || 0);
                    if (lineId > 0) {
                        appendSublineRow(lineId, {}, {
                            insertAtTop: true,
                            focusField: true,
                            markAsDraft: true
                        });
                        recalcMontant(lineId);
                    }
                    return;
                }

                const removeBtn = event.target.closest('[data-remove-subline]');
                if (removeBtn) {
                    const lineId = Number(removeBtn.getAttribute('data-remove-subline') || 0);
                    const row = removeBtn.closest('tr');
                    const linkedFicheCount = Number((row && row.getAttribute('data-linked-fiche-count')) || 0);
                    if (linkedFicheCount > 0) {
                        setAlert('danger', 'Impossible de supprimer cette sous-ligne car elle est déjà liée à une fiche de décaissement.');
                        return;
                    }
                    if (row) row.remove();
                    if (lineId > 0) recalcMontant(lineId);
                    return;
                }

                const saveBtn = event.target.closest('[data-save-line]');
                if (saveBtn) {
                    const lineId = Number(saveBtn.getAttribute('data-save-line') || 0);
                    if (lineId > 0) enregistrerLigne(lineId);
                }
            });

            document.querySelectorAll('[data-line-panel]').forEach(function(panel) {
                const lineId = Number(panel.getAttribute('data-line-panel') || 0);
                const isOpen = !panel.classList.contains('d-none');
                document.querySelectorAll('[data-line-toggle="' + lineId + '"]').forEach(function(button) {
                    button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });
                if (isOpen && lineId > 0) loadSousLignes(lineId);
            });

            if (lineSearch) lineSearch.addEventListener('input', applyFilters);
            if (lineStatusFilter) lineStatusFilter.addEventListener('change', applyFilters);
            if (lineResponsableFilter) lineResponsableFilter.addEventListener('change', applyFilters);

            const expandAllBtn = document.getElementById('expandAllBtn');
            if (expandAllBtn) expandAllBtn.addEventListener('click', function() {
                expandAllPanels(true);
            });
            const collapseAllBtn = document.getElementById('collapseAllBtn');
            if (collapseAllBtn) collapseAllBtn.addEventListener('click', function() {
                expandAllPanels(false);
            });
            const resetFiltersBtn = document.getElementById('resetFiltersBtn');
            if (resetFiltersBtn) {
                resetFiltersBtn.addEventListener('click', function() {
                    if (lineSearch) lineSearch.value = '';
                    if (lineStatusFilter) lineStatusFilter.value = '';
                    if (lineResponsableFilter) lineResponsableFilter.value = '';
                    applyFilters();
                });
            }

            $('#form-debourse').on('submit', function(event) {
                event.preventDefault();
                if (!canEdit) return;
                const form = $(this);
                setAlert('', '');
                if (saveAllBtn) {
                    saveAllBtn.disabled = true;
                    saveAllBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Consolidation…';
                }
                if (saveAllStatus) saveAllStatus.textContent = 'Chargement des lignes non ouvertes avant sauvegarde globale…';

                ensureAllSousLignesLoaded().then(function() {
                    if (saveAllStatus) saveAllStatus.textContent = 'Enregistrement global en cours…';
                    $.ajax({
                        url: 'request/save_debourse.php',
                        type: 'POST',
                        data: form.serialize(),
                        dataType: 'json'
                    }).done(function(resp) {
                        if (resp && resp.success) {
                            setAlert('success', resp.message || 'Déboursé enregistré avec succès.');
                            document.getElementById('debourseIssues')?.classList.add('d-none');
                            lineCards.forEach(function(card) { const lineId = Number(card.getAttribute('data-line-card') || 0); if (lineId) loadSousLignes(lineId, true); card.classList.remove('has-save-issue'); });
                            if (saveAllStatus) saveAllStatus.textContent = 'Tout est enregistré.';
                        } else {
                            if (resp && resp.line_id) showLineIssue(Number(resp.line_id), resp.message, !!resp.needs_refresh);
                            else setAlert('danger', (resp && resp.message) ? resp.message : 'Erreur lors de la sauvegarde globale.');
                            if (saveAllStatus) saveAllStatus.textContent = 'Échec de la sauvegarde globale.';
                        }
                    }).fail(function() {
                        setAlert('danger', 'Erreur lors de la sauvegarde globale.');
                        if (saveAllStatus) saveAllStatus.textContent = 'Échec de la sauvegarde globale.';
                    }).always(function() {
                        if (saveAllBtn) {
                            saveAllBtn.disabled = false;
                            saveAllBtn.innerHTML = '<i class="fas fa-save"></i> Enregistrer tous les déboursés';
                        }
                    });
                }).catch(function() {
                    setAlert('danger', 'Impossible de charger toutes les lignes avant la sauvegarde globale.');
                    if (saveAllBtn) {
                        saveAllBtn.disabled = false;
                        saveAllBtn.innerHTML = '<i class="fas fa-save"></i> Enregistrer tous les déboursés';
                    }
                    if (saveAllStatus) saveAllStatus.textContent = 'Préparation impossible.';
                });
            });

            <?php if ($canEdit): ?>
                $('#formAddDebourse').on('submit', function(e) {
                    e.preventDefault();
                    $.post('request/add_debourse.php', $(this).serialize() + '&devis_id=' + devisId, function(resp) {
                        if (resp && resp.success) {
                            window.location.reload();
                        } else {
                            setAlert('danger', (resp && resp.message) ? resp.message : 'Erreur lors de l\'ajout.');
                        }
                    }, 'json').fail(function() {
                        setAlert('danger', 'Erreur lors de l\'ajout.');
                    });
                });
            <?php endif; ?>

            document.querySelectorAll('[data-line-panel]:not(.d-none)').forEach(function(panel) {
                const lineId = Number(panel.getAttribute('data-line-panel') || 0);
                if (lineId > 0) loadSousLignes(lineId);
            });

            updateGlobalStats();
            applyFilters();
        })();
    </script>
</body>

</html>
