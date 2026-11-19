<?php
include 'auth_check.php';
include 'header/header_liste_devis.php';
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des Devis - BTP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/custom_style_liste_devis.css">
    <style>body{background:#f5f7fb!important;color:#172033}.container{max-width:1320px}#devisSearchForm{padding:22px;border:1px solid #dfe6f1;border-radius:18px;background:#fff;box-shadow:0 10px 30px rgba(29,43,87,.06)}#devisGrid .offer-project-group{padding:20px;border:1px solid #dfe6f1;border-radius:20px;background:#fff;box-shadow:0 10px 30px rgba(29,43,87,.05)}#devisGrid .card{overflow:hidden;border:1px solid #e2e8f0!important;border-radius:16px!important;box-shadow:0 8px 22px rgba(15,23,42,.06)!important;transition:.18s ease}#devisGrid .card:hover{transform:translateY(-3px);box-shadow:0 14px 30px rgba(15,23,42,.1)!important}#devisGrid .card.devis-focus{border:3px solid #10b981!important;box-shadow:0 0 0 7px rgba(16,185,129,.15),0 22px 55px rgba(15,23,42,.18)!important;animation:devisFocusPulse 1.2s ease-in-out 2}@keyframes devisFocusPulse{50%{transform:scale(1.015)}}.offer-project-header{padding-bottom:14px;border-bottom:1px solid #edf1f7}.offer-project-kicker{color:#4f46e5;font-size:.7rem;font-weight:900;letter-spacing:.18em}.form-control,.form-select,.select2-selection{border-radius:11px!important;min-height:42px}.btn{border-radius:10px}</style>
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

        <h1 class="text-center mb-4">Liste des Devis (<span id="nbDevis"><?php echo (int)$nb_devis; ?></span>)</h1>

        <!-- Formulaire de recherche (temps réel) -->
        <form method="GET" action="liste_devis.php" class="row g-3 mb-4" id="devisSearchForm" autocomplete="off">
            <div class="col-lg-4">
                <label for="q" class="form-label">Recherche</label>
                <input type="text" id="q" name="q" class="form-control" placeholder="N° devis, client, projet, correspondant…" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            </div>

            <div class="col-lg-3">
                <label for="client_id" class="form-label">Client</label>
                <select id="client_id" name="client_id" class="form-select select-search" data-placeholder="Tous les clients">
                    <option value=""></option>
                    <?php foreach (($clients ?? []) as $cl): ?>
                        <option value="<?= (int)($cl['id_client'] ?? 0) ?>" <?= ((string)($_GET['client_id'] ?? '') === (string)($cl['id_client'] ?? '')) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cl['nom_client'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-3">
                <label for="correspondant" class="form-label">Rédacteur</label>
                <select id="correspondant" name="correspondant" class="form-select select-search" data-placeholder="Tous les rédacteurs">
                    <option value=""></option>
                    <?php foreach (($correspondants ?? []) as $corr): ?>
                        <option value="<?= htmlspecialchars($corr) ?>" <?= ((string)($_GET['correspondant'] ?? '') === (string)$corr) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($corr) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-lg-2">
                <label for="validation" class="form-label">Validation</label>
                <select id="validation" name="validation" class="form-select">
                    <option value="" <?= (($_GET['validation'] ?? '') === '') ? 'selected' : '' ?>>Toutes</option>
                    <option value="non" <?= (($_GET['validation'] ?? '') === 'non') ? 'selected' : '' ?>>Non validé</option>
                    <option value="tech" <?= (($_GET['validation'] ?? '') === 'tech') ? 'selected' : '' ?>>Technique</option>
                    <option value="dg" <?= (($_GET['validation'] ?? '') === 'dg') ? 'selected' : '' ?>>DG</option>
                    <option value="valid" <?= (($_GET['validation'] ?? '') === 'valid') ? 'selected' : '' ?>>Validé</option>
                </select>
            </div>

            <div class="col-lg-2">
                <label for="chantier" class="form-label">Chantier</label>
                <select id="chantier" name="chantier" class="form-select">
                    <option value="" <?= (($_GET['chantier'] ?? '') === '') ? 'selected' : '' ?>>Tous</option>
                    <option value="1" <?= (($_GET['chantier'] ?? '') === '1') ? 'selected' : '' ?>>Débuté</option>
                    <option value="0" <?= (($_GET['chantier'] ?? '') === '0') ? 'selected' : '' ?>>Non débuté</option>
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
            <div class="col-lg-2">
                <label for="archive_status" class="form-label">Archives</label>
                <select id="archive_status" name="archive_status" class="form-select">
                    <option value="active" <?= (($_GET['archive_status'] ?? 'active') === 'active' && ($_GET['billing_status'] ?? '') !== 'pending') ? 'selected' : '' ?>>Actifs</option>
                    <option value="masked" <?= (($_GET['archive_status'] ?? '') === 'masked') ? 'selected' : '' ?>>Masqués</option>
                    <option value="archived" <?= (($_GET['archive_status'] ?? '') === 'archived') ? 'selected' : '' ?>>Archivés</option>
                    <option value="all" <?= (($_GET['archive_status'] ?? '') === 'all' || ($_GET['billing_status'] ?? '') === 'pending') ? 'selected' : '' ?>>Tous</option>
                </select>
            </div>
            <div class="col-lg-3">
                <label for="billing_status" class="form-label">Facturation</label>
                <select id="billing_status" name="billing_status" class="form-select">
                    <option value="" <?= (($_GET['billing_status'] ?? '') === '') ? 'selected' : '' ?>>Tous les devis</option>
                    <option value="pending" <?= (($_GET['billing_status'] ?? '') === 'pending') ? 'selected' : '' ?>>Facture en attente d’encaissement</option>
                </select>
            </div>
            <div class="col-lg-3">
                <label for="sort" class="form-label">Trier les devis</label>
                <select id="sort" name="sort" class="form-select select-search" data-placeholder="Ordre d’affichage">
                    <option value="recent" <?= (($_GET['sort'] ?? 'recent') === 'recent') ? 'selected' : '' ?>>Plus récents d’abord</option>
                    <option value="oldest" <?= (($_GET['sort'] ?? '') === 'oldest') ? 'selected' : '' ?>>Plus anciens d’abord</option>
                    <option value="amount_desc" <?= (($_GET['sort'] ?? '') === 'amount_desc') ? 'selected' : '' ?>>Montant le plus élevé</option>
                    <option value="amount_asc" <?= (($_GET['sort'] ?? '') === 'amount_asc') ? 'selected' : '' ?>>Montant le plus faible</option>
                    <option value="number_asc" <?= (($_GET['sort'] ?? '') === 'number_asc') ? 'selected' : '' ?>>Numéro A à Z</option>
                    <option value="number_desc" <?= (($_GET['sort'] ?? '') === 'number_desc') ? 'selected' : '' ?>>Numéro Z à A</option>
                </select>
            </div>

            <div class="col-12 d-flex justify-content-between align-items-center gap-3">
                <small class="text-muted" id="searchStatus"></small>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">Rechercher</button>
                    <a href="liste_devis.php" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
                </div>
            </div>
        </form>

        <!-- Display total amount -->
        <div class="mt-4">
            <h4 class="text-end">Montant Total TTC: <span class="text-success" id="totalTtc"><?php echo number_format($total_ttc, 0, ',', ' '); ?> FCFA</span></h4>
        </div>

        <!-- Button to export filtered quotes in PDF & Excel -->
        <div class="text-end mt-3">
            <a id="exportPdfBtn" target="_blank" href="request/export_resultat.php?<?php echo http_build_query($_GET); ?>" class="btn btn-primary">
                <i class="fas fa-file-pdf"></i> Exporter en PDF
            </a>
            <a target="_blank" href="generer_devis.php" class="btn btn-primary">
                <i class="fas fa-plus-circle"></i> Ajouter un devis
            </a>
        </div>

        <!-- Grid displaying quotes -->
        <?php if(!empty($_SESSION['devis_offer_flash_success'])):?><div class="alert alert-success mt-4"><?=htmlspecialchars((string)$_SESSION['devis_offer_flash_success'])?></div><?php unset($_SESSION['devis_offer_flash_success']);endif?>
        <?php if(!empty($_SESSION['devis_offer_flash_error'])):?><div class="alert alert-danger mt-4"><?=htmlspecialchars((string)$_SESSION['devis_offer_flash_error'])?></div><?php unset($_SESSION['devis_offer_flash_error']);endif?>
        <div class="mt-4" id="devisGrid">
            <?php include dirname(__DIR__) . '/partials/devis_offer_groups.php'; ?>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer text-white text-center py-3">
        <div class="container">
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

    <script>
        (function() {
            const $form = $('#devisSearchForm');
            const $grid = $('#devisGrid');
            const $nb = $('#nbDevis');
            const $total = $('#totalTtc');
            const $status = $('#searchStatus');
            const $exportPdf = $('#exportPdfBtn');

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
                    client_id: String($('#client_id').val() || '').trim(),
                    correspondant: String($('#correspondant').val() || '').trim(),
                    validation: String($('#validation').val() || '').trim(),
                    chantier: String($('#chantier').val() || '').trim(),
                    created_from: String($('#created_from').val() || '').trim(),
                    created_to: String($('#created_to').val() || '').trim(),
                    archive_status: String($('#archive_status').val() || 'active').trim(),
                    billing_status: String($('#billing_status').val() || '').trim(),
                    sort: String($('#sort').val() || 'recent').trim(),
                };

                // Nettoyage des vides
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

            function formatFcfa(value) {
                const n = Number(value || 0);
                const rounded = isNaN(n) ? 0 : Math.round(n);
                return String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            }

            function updateExportLink(params) {
                const qs = $.param(params || {});
                const href = 'request/export_resultat.php' + (qs ? ('?' + qs) : '');
                $exportPdf.attr('href', href);
            }

            let pendingTimer = null;
            let currentXhr = null;

            function setBusy(isBusy) {
                $status.text(isBusy ? 'Recherche…' : '');
            }

            function renderEmpty() {
                $grid.html('<div class="text-center text-muted py-4">Aucun devis correspondant</div>');
            }

            function doSearch() {
                const params = getParams();
                updateUrl(params);
                updateExportLink(params);

                if (currentXhr && typeof currentXhr.abort === 'function') {
                    currentXhr.abort();
                }

                setBusy(true);
                currentXhr = $.ajax({
                    url: 'api/search_devis.php',
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

                    const nb = parseInt(resp.nb_devis || 0, 10);
                    $nb.text(isNaN(nb) ? '0' : String(nb));

                    $total.text(formatFcfa(resp.total_ttc || 0) + ' FCFA');

                    const html = String(resp.html || '');
                    if (nb === 0) {
                        renderEmpty();
                    } else {
                        $grid.html(html);
                    }
                }).fail(function(xhr) {
                    // si abort, ignorer
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
            updateExportLink(getParams());

            const focusDevisId = new URLSearchParams(window.location.search).get('focus_devis');
            if (focusDevisId && /^\d+$/.test(focusDevisId)) {
                const focusedCard = document.querySelector('[data-devis-id="' + focusDevisId + '"]');
                if (focusedCard) {
                    focusedCard.classList.add('devis-focus');
                    window.setTimeout(function() {
                        focusedCard.scrollIntoView({behavior: 'smooth', block: 'center'});
                    }, 250);
                }
            }

            $form.on('submit', function(e) {
                e.preventDefault();
                doSearch();
            });
            $('#billing_status').on('change', function() {
                if ($(this).val() === 'pending') $('#archive_status').val('all').trigger('change.select2');
            });
            $form.on('input', 'input[type="text"]', scheduleSearch);
            $form.on('change', 'input[type="date"], select', scheduleSearch);
        })();
    </script>
</body>

</html>
