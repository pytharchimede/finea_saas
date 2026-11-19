<?php
include 'auth_check.php';
include 'header/header_liste_offre.php';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des projets - FINEA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/custom_style_liste_offre.css">
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
        <h1 class="text-center mb-4">Liste des projets (<span id="nbOffres"><?php echo (int)($nb_offres ?? count($offers ?? [])); ?></span>)</h1>

        <!-- Button to trigger modal -->
        <div class="d-flex justify-content-end mb-3">
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOfferModal">
                <i class="fas fa-plus-circle"></i> Ajouter un projet
            </button>
        </div>

        <!-- Formulaire de recherche (temps réel) -->
        <form method="GET" action="liste_offre.php" class="row g-3 mb-4" id="offreSearchForm" autocomplete="off">
            <div class="col-lg-5">
                <label for="q" class="form-label">Recherche</label>
                <input type="text" id="q" name="q" class="form-control" placeholder="N° projet, référence ou superviseur…" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            </div>

            <div class="col-lg-3">
                <label for="commercial_dedie" class="form-label">Superviseur interne du projet</label>
                <select id="commercial_dedie" name="commercial_dedie" class="form-select select-search" data-placeholder="Tous les superviseurs">
                    <option value=""></option>
                    <?php foreach (($commercialsList ?? []) as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= ((string)($_GET['commercial_dedie'] ?? '') === (string)$c) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2">
                <label for="date_offre_from" class="form-label">Projet du</label>
                <input type="date" id="date_offre_from" name="date_offre_from" class="form-control" value="<?= htmlspecialchars($_GET['date_offre_from'] ?? '') ?>">
            </div>

            <div class="col-lg-2">
                <label for="date_offre_to" class="form-label">Projet au</label>
                <input type="date" id="date_offre_to" name="date_offre_to" class="form-control" value="<?= htmlspecialchars($_GET['date_offre_to'] ?? '') ?>">
            </div>

            <div class="col-12 d-flex justify-content-between align-items-center gap-3">
                <small class="text-muted" id="searchStatus"></small>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Rechercher</button>
                    <a href="liste_offre.php" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
                </div>
            </div>
        </form>

        <!-- Cards displaying offers -->
        <div class="card-grid" id="offreGrid">
            <?php foreach (($offers ?? []) as $offer): ?>
                <?php include dirname(__DIR__) . '/partials/offre_card.php'; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Modal for adding offer -->
    <div class="modal fade" id="addOfferModal" tabindex="-1" aria-labelledby="addOfferModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addOfferModalLabel">Ajouter un projet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form action="request/ajouter_offre.php" enctype="multipart/form-data" method="POST">
                        <div class="mb-3">
                            <label for="num_offre" class="form-label">Numéro du projet</label>
                            <input type="text" class="form-control bg-light" id="num_offre" value="Généré automatiquement" readonly>
                            <div class="form-text">Attribué à l’enregistrement au format PRJ-AAAA-00001.</div>
                        </div>
                        <div class="mb-3">
                            <label for="date_offre" class="form-label">Date du projet</label>
                            <input type="date" class="form-control" id="date_offre" name="date_offre" required>
                        </div>
                        <div class="mb-3">
                            <label for="reference_offre" class="form-label">Référence</label>
                            <input type="text" class="form-control" id="reference_offre" name="reference_offre" required>
                        </div>
                        <div class="mb-3">
                            <label for="commercial_dedie" class="form-label">Superviseur interne du projet</label>
                            <input type="text" class="form-control" id="commercial_dedie" name="commercial_dedie" required>
                        </div>
                        <div class="mb-3">
                            <label for="date_creat_offre" class="form-label">Date de Création</label>
                            <input type="date" class="form-control" id="date_creat_offre" name="date_creat_offre" required>
                        </div>
                        <div class="mb-3">
                            <label for="offre_files" class="form-label">Fichiers relatifs au projet</label>
                            <input type="file" class="form-control" id="offre_files" name="offre_files[]" multiple style="display:none;">
                            <div id="drop_zone" style="border:2px dashed #fabd02; background:#fffbe6; color:#000; padding:30px; text-align:center; cursor:pointer;">
                                Glissez-déposez vos fichiers ici ou cliquez pour sélectionner
                            </div>
                            <div id="file_list"></div>
                        </div>
                        <button type="submit" class="btn btn-primary">Ajouter le projet</button>
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
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>
    <script src="js/script.js"></script>
    <!--Intégration de jquery/Ajax-->
    <script src="js/function.js"></script>
    <script>
        const dropZone = document.getElementById('drop_zone');
        const fileInput = document.getElementById('offre_files');
        const fileList = document.getElementById('file_list');
        let filesBuffer = [];

        dropZone.addEventListener('click', () => fileInput.click());
        dropZone.addEventListener('dragover', e => {
            e.preventDefault();
            dropZone.style.background = '#fdd96c';
        });
        dropZone.addEventListener('dragleave', e => {
            e.preventDefault();
            dropZone.style.background = '#fffbe6';
        });
        dropZone.addEventListener('drop', e => {
            e.preventDefault();
            dropZone.style.background = '#fffbe6';
            addFiles(e.dataTransfer.files);
        });
        fileInput.addEventListener('change', () => addFiles(fileInput.files));

        function addFiles(fileListInput) {
            for (let file of fileListInput) {
                filesBuffer.push(file);
            }
            updateFileList();
            updateInputFiles();
        }

        function removeFile(index) {
            filesBuffer.splice(index, 1);
            updateFileList();
            updateInputFiles();
        }

        function updateFileList() {
            fileList.innerHTML = '';
            filesBuffer.forEach((file, idx) => {
                fileList.innerHTML += `
                    <div style="display:flex;align-items:center;justify-content:space-between;margin:6px 0;padding:6px 10px;border-radius:5px;background:#fffbe6;border:1px solid #fdd96c;">
                        <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${file.name}</span>
                        <span style="cursor:pointer;color:#d9534f;font-size:1.1rem;margin-left:12px;" title="Supprimer" onclick="removeFile(${idx})">
                            <i class="fas fa-times-circle"></i>
                        </span>
                    </div>`;
            });
        }

        // Met à jour l'input file pour l'envoi du formulaire
        function updateInputFiles() {
            const dataTransfer = new DataTransfer();
            filesBuffer.forEach(file => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
        }

        (function() {
            const $form = $('#offreSearchForm');
            const $grid = $('#offreGrid');
            const $nb = $('#nbOffres');
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
                    commercial_dedie: String($('#commercial_dedie').val() || '').trim(),
                    date_offre_from: String($('#date_offre_from').val() || '').trim(),
                    date_offre_to: String($('#date_offre_to').val() || '').trim(),
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
                $grid.html('<div class="text-center text-muted py-4">Aucun projet correspondant</div>');
            }

            function doSearch() {
                const params = getParams();
                updateUrl(params);

                if (currentXhr && typeof currentXhr.abort === 'function') {
                    currentXhr.abort();
                }

                setBusy(true);
                currentXhr = $.ajax({
                    url: 'api/search_offres.php',
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

                    const nb = parseInt(resp.nb_offres || 0, 10);
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
