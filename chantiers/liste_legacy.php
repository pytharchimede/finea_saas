<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';

AuditTrail::logPageConsultation([
    'module' => 'core',
    'action' => 'liste_chantier_view',
    'entity_type' => 'chantier_list',
    'entity_label' => 'Liste des chantiers',
    'page_name' => 'liste_chantier.php',
    'details' => [
        'q' => $_GET['q'] ?? null,
        'client_id' => $_GET['client_id'] ?? null,
        'archives' => $_GET['archives'] ?? null,
        'view_mode' => 'list',
    ],
]);

require_once 'header/header_liste_chantiers.php';

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des chantiers</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/custom_style_liste_chantier.css">
    <link rel="stylesheet" href="assets/css/finea-master-lists.css">
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

    <div class="container">
        <?php
        $filtersQ = $_GET['q'] ?? '';
        $filtersClientId = $_GET['client_id'] ?? '';
        $filtersArchives = !empty($_GET['archives']);
        ?>

        <h1 class="text-center mb-4">
            Liste des chantiers (<span id="nbChantiers"><?php echo (int)($nb_chantiers ?? count($chantiers ?? [])); ?></span>)
        </h1>
        <div class="text-end mb-3"><a href="chantiers/regularisation.php" class="btn btn-warning"><i class="fas fa-shield-alt"></i> Régulariser les chantiers actifs</a><?php if (AccessManager::hasPermission('acces_admin') || AccessManager::hasPermission('acces_direction')): ?> <a href="chantiers/fusion_doublons.php" class="btn btn-outline-danger"><i class="fas fa-code-merge"></i> Fusionner / retirer un doublon</a><?php endif; ?></div>

        <form method="GET" action="liste_chantier.php" class="row g-3 mb-4" id="chantierSearchForm" autocomplete="off">
            <div class="col-lg-5">
                <label for="q" class="form-label">Recherche</label>
                <input type="text" id="q" name="q" class="form-control" placeholder="Titre, code, client, devis…" value="<?= htmlspecialchars((string)$filtersQ) ?>">
            </div>

            <div class="col-lg-4">
                <label for="client_id" class="form-label">Client</label>
                <select id="client_id" name="client_id" class="form-select">
                    <option value="">Tous</option>
                    <?php foreach (($clientsList ?? []) as $cl): ?>
                        <?php
                        $cid = (string)($cl['id_client'] ?? '');
                        $cname = (string)($cl['nom_client'] ?? '');
                        ?>
                        <option value="<?= htmlspecialchars($cid) ?>" <?= ((string)$filtersClientId === $cid) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-3">
                <label for="archives" class="form-label">État</label>
                <select id="archives" name="archives" class="form-select">
                    <option value="0" <?= !$filtersArchives ? 'selected' : '' ?>>Actifs</option>
                    <option value="1" <?= $filtersArchives ? 'selected' : '' ?>>Archives</option>
                </select>
            </div>

            <div class="col-12 d-flex justify-content-between align-items-center gap-3">
                <small class="text-muted" id="searchStatus"></small>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Rechercher</button>
                    <a href="liste_chantier.php" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
                </div>
            </div>
        </form>

        <div class="mb-3 text-end">
            <a href="choisir_devis_pour_chantier.php" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i> Nouveau chantier
            </a>
            <a href="exports/finea_module_export.php?module=chantiers&amp;format=pdf" data-finea-export data-filter-form="chantierSearchForm" class="btn btn-outline-danger ms-2"><i class="fa-solid fa-file-pdf"></i> PDF</a>
            <a href="exports/finea_module_export.php?module=chantiers&amp;format=xlsx" data-finea-export data-filter-form="chantierSearchForm" class="btn btn-outline-success ms-2"><i class="fa-solid fa-file-excel"></i> Excel</a>
        </div>

        <div class="chantier-grid-wrap mb-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="chantier-grid-icon"><i class="fa-solid fa-grid-2"></i></span>
                    <h2 class="h5 mb-0">Chantiers</h2>
                </div>
            </div>

            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3" id="chantiersGrid">
                <?php if (empty($chantiers)): ?>
                    <div class="col">
                        <div class="card chantier-empty">
                            <div class="card-body text-center py-5 text-muted">
                                <div class="mb-2"><i class="fa-regular fa-folder-open fa-2x"></i></div>
                                Aucun chantier trouvé.
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($chantiers as $chantier): ?>
                        <?php include dirname(__DIR__) . '/partials/chantier_card.php'; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer class="footer mt-5">
        <div class="container text-center">
            <p>&copy; <?= gmdate('Y') ?> BANAMUR INDUSTRIES & TECH. Tous droits réservés.</p>
        </div>
    </footer>

    <script src="assets/js/finea-filtered-export.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        (function() {
            $('#client_id').select2({placeholder:'Rechercher un client actif…',allowClear:true,width:'100%'});
            const $formSearch = $('#chantierSearchForm');
            const $grid = $('#chantiersGrid');
            const $nb = $('#nbChantiers');
            const $status = $('#searchStatus');

            let pendingTimer = null;
            let currentXhr = null;

            function getParams() {
                const params = {
                    q: String($('#q').val() || '').trim(),
                    client_id: String($('#client_id').val() || '').trim(),
                    archives: String($('#archives').val() || '0').trim(),
                };
                Object.keys(params).forEach(function(k) {
                    if (params[k] === '' || (k === 'archives' && params[k] === '0')) {
                        delete params[k];
                    }
                });
                return params;
            }

            function updateUrl(params) {
                try {
                    const qs = $.param(params || {});
                    const newUrl = window.location.pathname + (qs ? ('?' + qs) : '');
                    history.replaceState(null, '', newUrl);
                } catch (e) {
                    // ignore
                }
            }

            function setBusy(isBusy) {
                $status.text(isBusy ? 'Recherche…' : '');
            }

            function renderEmpty() {
                $grid.html('<div class="col"><div class="card chantier-empty"><div class="card-body text-center py-5 text-muted"><div class="mb-2"><i class="fa-regular fa-folder-open fa-2x"></i></div>Aucun chantier correspondant</div></div></div>');
            }

            function doSearch() {
                const params = getParams();
                updateUrl(params);

                if (currentXhr && typeof currentXhr.abort === 'function') {
                    currentXhr.abort();
                }

                setBusy(true);
                currentXhr = $.ajax({
                    url: 'api/search_chantiers.php',
                    method: 'GET',
                    dataType: 'json',
                    data: params,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).done(function(resp) {
                    if (resp && resp.auth_required && resp.redirect) {
                        window.location.href = String(resp.redirect);
                        return;
                    }
                    if (!resp || !resp.ok) {
                        return;
                    }

                    const nb = parseInt(resp.nb_chantiers || 0, 10);
                    $nb.text(isNaN(nb) ? '0' : String(nb));

                    const html = String(resp.html || '');
                    if (nb === 0) {
                        renderEmpty();
                    } else {
                        $grid.html(html);
                    }
                }).fail(function(xhr) {
                    if (xhr && xhr.statusText === 'abort') return;
                }).always(function() {
                    setBusy(false);
                });
            }

            function scheduleSearch() {
                if (pendingTimer) {
                    clearTimeout(pendingTimer);
                }
                pendingTimer = setTimeout(doSearch, 250);
            }

            $formSearch.on('submit', function(e) {
                e.preventDefault();
                doSearch();
            });
            $formSearch.on('input', 'input[type="text"]', scheduleSearch);
            $formSearch.on('change', 'select', scheduleSearch);
        })();
    </script>
</body>

</html>
