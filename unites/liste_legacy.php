<?php

require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';

AuditTrail::logPageConsultation([
    'module' => 'core',
    'action' => 'liste_unite_view',
    'entity_type' => 'unite_list',
    'entity_label' => 'Liste des unites',
    'page_name' => 'liste_unite.php',
    'details' => [
        'q' => $_GET['q'] ?? null,
        'view_mode' => 'list',
    ],
]);

require_once 'header/header_liste_unite.php';

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des Unités de Mesure</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/custom_style_liste_unite.css">
    <link rel="stylesheet" href="assets/css/finea-master-lists.css">
</head>

<body>


    <div class="container finea-units-list">
        <h1 class="text-center mb-4">Liste des Unités de Mesure (<span id="nbUnites"><?php echo (int)($nb_unites ?? count($unites ?? [])); ?></span>)</h1>

        <!-- Formulaire de recherche (temps réel) -->
        <form method="GET" action="liste_unite.php" class="row g-3 mb-4" id="uniteSearchForm" autocomplete="off">
            <div class="col-lg-6">
                <label for="q" class="form-label">Recherche</label>
                <input type="text" id="q" name="q" class="form-control" placeholder="Symbole ou libellé…" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            </div>

            <div class="col-12 d-flex justify-content-between align-items-center gap-3">
                <small class="text-muted" id="searchStatus"></small>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Rechercher</button>
                    <a href="liste_unite.php" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
                </div>
            </div>
        </form>

        <!-- Bouton Ajouter -->
        <div class="mb-3 text-end">
            <a href="unites/ajouter.php" class="btn finea-unit-create">
                <i class="fas fa-plus"></i> Ajouter une unité
            </a>
            <a href="<?= htmlspecialchars(devis_module_root_url('exports/finea_module_export.php'), ENT_QUOTES, 'UTF-8') ?>?module=unites&amp;format=pdf" data-finea-export data-filter-form="uniteSearchForm" class="btn btn-outline-danger ms-2"><i class="fas fa-file-pdf"></i> PDF</a>
            <a href="<?= htmlspecialchars(devis_module_root_url('exports/finea_module_export.php'), ENT_QUOTES, 'UTF-8') ?>?module=unites&amp;format=xlsx" data-finea-export data-filter-form="uniteSearchForm" class="btn btn-outline-success ms-2"><i class="fas fa-file-excel"></i> Excel</a>
        </div>
        <div class="card">
            <div class="card-header">
                <i class="fas fa-ruler"></i> Unités disponibles
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped align-middle text-center" id="unitesTable">
                        <thead>
                            <tr>
                                <th>Symbole</th>
                                <th>Libellé</th>
                            </tr>
                        </thead>
                        <tbody id="unitesTbody">
                            <?php foreach ($unites as $unite): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($unite['symbole']) ?></strong></td>
                                    <td><?= htmlspecialchars($unite['libelle']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal d'ajout d'une unité -->
    <div class="modal fade" id="addUniteModal" tabindex="-1" aria-labelledby="addUniteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <form class="modal-content" id="formAddUnite" autocomplete="off">
                <div class="modal-header">
                    <h5 class="modal-title" id="addUniteModalLabel">Ajouter une unité de mesure</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="symbole" class="form-label">Symbole</label>
                        <input type="text" class="form-control" id="symbole" name="symbole" required>
                    </div>
                    <div class="mb-3">
                        <label for="libelle" class="form-label">Libellé</label>
                        <input type="text" class="form-control" id="libelle" name="libelle" required>
                    </div>
                    <div id="unite-error" class="text-danger"></div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-success">Ajouter</button>
                </div>
            </form>
        </div>
    </div>


    <script src="assets/js/finea-filtered-export.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        (function() {
            const $formSearch = $('#uniteSearchForm');
            const $tbody = $('#unitesTbody');
            const $nb = $('#nbUnites');
            const $status = $('#searchStatus');

            let pendingTimer = null;
            let currentXhr = null;

            function getParams() {
                const params = {
                    q: String($('#q').val() || '').trim(),
                };
                Object.keys(params).forEach(function(k) {
                    if (params[k] === '') {
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
                $tbody.html('<tr><td colspan="2" class="text-center text-muted py-4">Aucune unité correspondante</td></tr>');
            }

            function doSearch() {
                const params = getParams();
                updateUrl(params);

                if (currentXhr && typeof currentXhr.abort === 'function') {
                    currentXhr.abort();
                }

                setBusy(true);
                currentXhr = $.ajax({
                    url: 'api/search_unites.php',
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

                    const nb = parseInt(resp.nb_unites || 0, 10);
                    $nb.text(isNaN(nb) ? '0' : String(nb));

                    const html = String(resp.html || '');
                    if (nb === 0) {
                        renderEmpty();
                    } else {
                        $tbody.html(html);
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

            // Form ajout unité
            $('#formAddUnite').on('submit', function(e) {
                e.preventDefault();
                $('#unite-error').text('');
                $.ajax({
                    url: 'request/ajouter_unite.php',
                    type: 'POST',
                    data: $(this).serialize(),
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#addUniteModal').modal('hide');
                            $('#formAddUnite')[0].reset();
                            // Rafraîchir la liste (prend en compte un éventuel filtre)
                            doSearch();
                        } else {
                            $('#unite-error').text(response.error || "Erreur lors de l'ajout.");
                        }
                    },
                    error: function() {
                        $('#unite-error').text("Erreur serveur.");
                    }
                });
            });
        })();
    </script>
</body>

</html>
