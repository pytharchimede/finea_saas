<?php
include 'auth_check.php';
include 'header/header_liste_client.php';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des Clients - BTP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/custom_style_liste_client.css">
    <link rel="stylesheet" href="assets/css/finea-master-lists.css">
</head>

<body>

    <!-- Menu -->
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
        <div class="client-page-heading">
            <div>
                <span class="client-kicker">Référentiel commercial</span>
                <h1>Vue cartes clients <span class="client-count" id="nbClients"><?php echo (int)($nb_clients ?? count($clients ?? [])); ?></span></h1>
                <p>Parcourez les fiches clients, consultez leur activité et ouvrez leur identité fiscale, française ou internationale.</p>
            </div>
            <div class="client-view-switcher">
                <a class="client-view-active" href="clients/liste.php"><i class="fas fa-grip"></i> Vue cartes</a>
                <a href="clients/dashboard.php"><i class="fas fa-list"></i> Vue liste</a>
            </div>
        </div>

        <!-- Actions -->
        <div class="d-flex justify-content-end mb-3 client-toolbar-actions">
            <a href="clients/ajouter.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Ajouter un client
            </a>
            <a href="exports/finea_module_export.php?module=clients&amp;format=pdf" data-finea-export data-filter-form="clientSearchForm" class="btn btn-outline-danger ms-2"><i class="fas fa-file-pdf"></i> PDF</a>
            <a href="exports/finea_module_export.php?module=clients&amp;format=xlsx" data-finea-export data-filter-form="clientSearchForm" class="btn btn-outline-success ms-2"><i class="fas fa-file-excel"></i> Excel</a>
        </div>

        <!-- Formulaire de recherche (temps réel) -->
        <form method="GET" action="liste_client.php" class="row g-3 mb-4" id="clientSearchForm" autocomplete="off">
            <div class="col-lg-5">
                <label for="q" class="form-label">Recherche</label>
                <input type="text" id="q" name="q" class="form-control" placeholder="Nom, code client, téléphone, localisation…" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            </div>

            <div class="col-lg-3">
                <label for="pays" class="form-label">Pays</label>
                <select id="pays" name="pays" class="form-select select-search" data-placeholder="Tous les pays">
                    <option value=""></option>
                    <?php foreach (($paysList ?? []) as $p): ?>
                        <option value="<?= htmlspecialchars($p) ?>" <?= ((string)($_GET['pays'] ?? '') === (string)$p) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-3">
                <label for="commune" class="form-label">Commune</label>
                <select id="commune" name="commune" class="form-select select-search" data-placeholder="Toutes les communes">
                    <option value=""></option>
                    <?php foreach (($communesList ?? []) as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= ((string)($_GET['commune'] ?? '') === (string)$c) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2">
                <label for="created_from" class="form-label">Créé du</label>
                <input type="date" id="created_from" name="created_from" class="form-control" value="<?= htmlspecialchars($_GET['created_from'] ?? '') ?>">
            </div>
            <div class="col-lg-2">
                <label for="created_to" class="form-label">Créé au</label>
                <input type="date" id="created_to" name="created_to" class="form-control" value="<?= htmlspecialchars($_GET['created_to'] ?? '') ?>">
            </div>

            <div class="col-12 d-flex justify-content-between align-items-center gap-3">
                <small class="text-muted" id="searchStatus"></small>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Rechercher</button>
                    <a href="liste_client.php" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
                </div>
            </div>
        </form>

        <!-- Grid displaying client cards -->
        <div class="card-grid mt-4" id="clientGrid">
            <?php foreach (($clients ?? []) as $client): ?>
                <?php include dirname(__DIR__) . '/partials/client_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Modal for adding client -->
    <div class="modal fade" id="addClientModal" tabindex="-1" aria-labelledby="addClientModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addClientModalLabel">Ajouter un Client</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form action="request/ajouter_client.php" method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="code_client" class="form-label">Code Client</label>
                            <input type="text" class="form-control" id="code_client" name="code_client" required>
                        </div>
                        <div class="mb-3">
                            <label for="nom_client" class="form-label">Nom Client</label>
                            <input type="text" class="form-control" id="nom_client" name="nom_client" required>
                        </div>
                        <div class="mb-3">
                            <label for="localisation_client" class="form-label">Localisation</label>
                            <input type="text" class="form-control" id="localisation_client" name="localisation_client" required>
                        </div>
                        <div class="mb-3">
                            <label for="commune_client" class="form-label">Commune</label>
                            <input type="text" class="form-control" id="commune_client" name="commune_client" required>
                        </div>
                        <div class="mb-3">
                            <label for="bp_client" class="form-label">BP</label>
                            <input type="text" class="form-control" id="bp_client" name="bp_client" required>
                        </div>
                        <div class="mb-3">
                            <label for="pays_client" class="form-label">Pays</label>
                            <input type="text" class="form-control" id="pays_client" name="pays_client" required>
                        </div>
                        <div class="mb-3">
                            <label for="date_creat_client" class="form-label">Date de Création</label>
                            <input type="date" class="form-control" id="date_creat_client" name="date_creat_client" required>
                        </div>
                        <div class="mb-3">
                            <label for="telephone_client" class="form-label">Téléphone</label>
                            <input type="text" class="form-control" id="telephone_client" name="telephone_client" required>
                        </div>
                        <div class="mb-3">
                            <label for="logo_client" class="form-label">Logo</label>
                            <input type="file" class="form-control" id="logo_client" name="logo_client" accept="image/*" onchange="previewLogo(event)">
                            <div class="mt-2">
                                <img id="logoPreview" src="#" alt="Prévisualisation logo" style="max-width:120px; display:none;" />
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Ajouter le Client</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container text-center py-3">
            <p>&copy; <?php echo gmdate('Y'); ?> BANAMUR INDUSTRIES & TECH. Tous droits réservés.</p>
            <div class="social-icons">
                <a href="#" class="fab fa-facebook-f"></a>
                <a href="#" class="fab fa-twitter"></a>
                <a href="#" class="fab fa-linkedin-in"></a>
            </div>
        </div>
    </footer>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/js/finea-filtered-export.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>
    <script>
        function previewLogo(event) {
            const [file] = event.target.files;
            if (file) {
                const reader = new FileReader();
                reader.onload = e => {
                    $('#logoPreview').attr('src', e.target.result).show();
                };
                reader.readAsDataURL(file);
            }
        }

        (function() {
            const $form = $('#clientSearchForm');
            const $grid = $('#clientGrid');
            const $nb = $('#nbClients');
            const $status = $('#searchStatus');

            function initSelectSearch() {
                try {
                    $('.select-search').select2({
                        width: '100%',
                        allowClear: true,
                        placeholder: function() {
                            return $(this).data('placeholder') || '';
                        }
                    });
                } catch (e) {
                    // ignore
                }
            }

            function getParams() {
                const params = {
                    q: String($('#q').val() || '').trim(),
                    pays: String($('#pays').val() || '').trim(),
                    commune: String($('#commune').val() || '').trim(),
                    created_from: String($('#created_from').val() || '').trim(),
                    created_to: String($('#created_to').val() || '').trim(),
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

            let pendingTimer = null;
            let currentXhr = null;

            function setBusy(isBusy) {
                $status.text(isBusy ? 'Recherche…' : '');
            }

            function renderEmpty() {
                $grid.html('<div class="text-center text-muted py-4">Aucun client correspondant</div>');
            }

            function doSearch() {
                const params = getParams();
                updateUrl(params);

                if (currentXhr && typeof currentXhr.abort === 'function') {
                    currentXhr.abort();
                }

                setBusy(true);
                currentXhr = $.ajax({
                    url: 'api/search_clients.php',
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

                    const nb = parseInt(resp.nb_clients || 0, 10);
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

            initSelectSearch();

            $form.on('submit', function(e) {
                e.preventDefault();
                doSearch();
            });
            $form.on('input', 'input[type="text"]', scheduleSearch);
            $form.on('change', 'input[type="date"], select', scheduleSearch);
        })();
    </script>
</body>

</html>

