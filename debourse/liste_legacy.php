<?php
include 'auth_check.php';
require_once 'model/Database.php';
require_once 'model/Devis.php';

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);

// filtres
$chantierId = $_GET['chantier_id'] ?? null;
$search = $_GET['search'] ?? null;
$client = $_GET['client'] ?? null;

// récupérer les déboursés filtrés
$debourses = $devisModel->getDeboursesFiltres($chantierId, $search, $client);
$totalMontant = array_sum(array_column($debourses, 'montant_debourse'));

// récupérer les chantiers pour le filtre
$hasArchivedAt = false;
try {
    // Best-effort: ajoute les colonnes d'archivage si possible.
    if (class_exists('Chantier') && method_exists('Chantier', 'ensureArchiveColumns')) {
        Chantier::ensureArchiveColumns($pdo);
    }

    $archivedAtStmt = $pdo->query("SHOW COLUMNS FROM chantier_banamur LIKE 'archived_at'");
    $hasArchivedAt = (bool) ($archivedAtStmt && $archivedAtStmt->fetch(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    $hasArchivedAt = false;
}

$chantiersSql = "SELECT id, titre FROM chantier_banamur";
if ($hasArchivedAt) {
    $chantiersSql .= " WHERE archived_at IS NULL";
}
$chantiersSql .= " ORDER BY titre";

$chantiers = $pdo->query($chantiersSql)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Liste des Déboursés</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/custom_style_liste_debourse.css">

</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <img src="https://fidest.ci/logo/new_logo_banamur.jpg" alt="Logo">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <?php $page = "liste_debourse";
                include 'menu.php'; ?>
            </div>
        </div>
    </nav>

    <div class="container">

        <h1 class="text-center mb-4">Déboursés (<span id="nbDebourses"><?= (int)count($debourses) ?></span>)</h1>

        <!-- FILTRES -->

        <div class="card shadow-sm border-0 mb-4">

            <div class="card-body">

                <form method="GET" class="row g-3 align-items-end" id="debourseSearchForm" autocomplete="off">

                    <div class="col-md-4">

                        <label class="form-label small text-muted">
                            Chantier
                        </label>

                        <select name="chantier_id" id="chantier_id" class="form-select select-search" data-placeholder="Tous les chantiers">

                            <option value="">Tous les chantiers</option>

                            <?php foreach ($chantiers as $c): ?>

                                <option value="<?= $c['id'] ?>"
                                    <?= ($chantierId == $c['id']) ? 'selected' : '' ?>>

                                    <?= htmlspecialchars($c['titre']) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="col-md-4">

                        <label class="form-label small text-muted">
                            Recherche
                        </label>

                        <input type="text" name="search" id="search" class="form-control" placeholder="Ex : béton, main d'oeuvre..." value="<?= htmlspecialchars($search ?? '') ?>">

                    </div>

                    <div class="col-md-4">

                        <label class="form-label small text-muted">
                            Client
                        </label>

                        <input type="text" name="client" id="client" class="form-control" placeholder="Nom du client" value="<?= htmlspecialchars($client ?? '') ?>">

                    </div>


                    <div class="col-12 d-flex justify-content-between align-items-center gap-3">

                        <small class="text-muted" id="searchStatus"></small>

                        <div class="d-flex gap-2">

                            <button class="btn btn-primary btn-sm">
                                <i class="fas fa-search me-1"></i>
                                Filtrer
                            </button>

                            <a href="liste_debourse.php" class="btn btn-outline-secondary btn-sm">
                                Reset
                            </a>

                        </div>

                    </div>

                </form>

            </div>

        </div>



        <!-- ACTIONS -->

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

            <div class="text-muted small">

                <span id="nbDeboursesText"><?= count($debourses) ?></span> déboursé(s) •
                Total : <strong class="text-success" id="totalDebourse"><?= number_format($totalMontant, 0, ',', ' ') ?> FCFA</strong>

            </div>

            <div class="d-flex gap-2">

                <a id="exportPdfBtn" href="request/export_debourses_pdf.php?chantier_id=<?= urlencode((string)$chantierId) ?>&search=<?= urlencode((string)$search) ?>&client=<?= urlencode((string)$client) ?>"
                    class="btn btn-outline-danger btn-sm">

                    <i class="fas fa-file-pdf"></i>
                    Export PDF

                </a>

                <a id="exportExcelBtn" href="request/export_debourses_excel.php?chantier_id=<?= urlencode((string)$chantierId) ?>&search=<?= urlencode((string)$search) ?>&client=<?= urlencode((string)$client) ?>"
                    class="btn btn-outline-success btn-sm">

                    <i class="fas fa-file-excel"></i>
                    Export Excel

                </a>

            </div>

        </div>



        <!-- LISTE -->

        <div class="row g-4" id="debourseGrid">

            <?php if (empty($debourses)): ?>

                <div class="col-12">

                    <div class="alert alert-light border text-center">

                        Aucun déboursé trouvé.

                    </div>

                </div>

            <?php else: ?>


                <?php foreach ($debourses as $deb): ?>
                    <?php include dirname(__DIR__) . '/partials/debourse_card.php'; ?>
                <?php endforeach; ?>

            <?php endif; ?>

        </div>

    </div>


    <footer class="footer text-white text-center py-3 mt-5">
        <div class="container">
            <p>&copy; <?= gmdate('Y'); ?> BANAMUR INDUSTRIES & TECH. Tous droits réservés.</p>
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
            const $form = $('#debourseSearchForm');
            const $grid = $('#debourseGrid');
            const $nb = $('#nbDebourses');
            const $nbTxt = $('#nbDeboursesText');
            const $total = $('#totalDebourse');
            const $status = $('#searchStatus');
            const $exportPdf = $('#exportPdfBtn');
            const $exportExcel = $('#exportExcelBtn');

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
                    chantier_id: String($('#chantier_id').val() || '').trim(),
                    search: String($('#search').val() || '').trim(),
                    client: String($('#client').val() || '').trim(),
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

            function updateExports(params) {
                try {
                    const qs = $.param(params || {});
                    $exportPdf.attr('href', 'request/export_debourses_pdf.php' + (qs ? ('?' + qs) : ''));
                    $exportExcel.attr('href', 'request/export_debourses_excel.php' + (qs ? ('?' + qs) : ''));
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
                $grid.html('<div class="col-12"><div class="alert alert-light border text-center">Aucun déboursé trouvé.</div></div>');
            }

            function doSearch() {
                const params = getParams();
                updateUrl(params);
                updateExports(params);

                if (currentXhr && typeof currentXhr.abort === 'function') {
                    currentXhr.abort();
                }

                setBusy(true);
                currentXhr = $.ajax({
                    url: 'api/search_debourses.php',
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

                    const nb = parseInt(resp.nb_debourses || 0, 10);
                    const total = parseFloat(resp.total_montant || 0);

                    $nb.text(isNaN(nb) ? '0' : String(nb));
                    $nbTxt.text(isNaN(nb) ? '0' : String(nb));

                    if (!isNaN(total)) {
                        try {
                            $total.text(Math.round(total).toLocaleString('fr-FR') + ' FCFA');
                        } catch (e) {
                            $total.text(String(Math.round(total)) + ' FCFA');
                        }
                    }

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
            $form.on('change', 'select', scheduleSearch);
        })();
    </script>
</body>

</html>
