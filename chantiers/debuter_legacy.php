<?php
session_start();
require_once 'auth_check.php';
require_once 'model/Database.php';
require_once 'model/Devis.php';
require_once 'model/Offre.php';
require_once 'model/Client.php';
require_once 'model/UniteMesure.php';
require_once 'model/Chantier.php';
require_once 'model/Operation.php';
require_once 'model/Designation.php';
require_once 'model/Precision.php';
require_once 'model/Utils.php';
require_once 'services/ChantierCreationGuardService.php';

$databaseObj = new Database();
$pdo = $databaseObj->getConnection();
$creationGuard = new ChantierCreationGuardService($pdo);

$devisId = isset($_GET['devisId']) ? intval($_GET['devisId']) : 0;
$devisModel = new Devis($pdo);
$devis = $devisModel->getDevisById($devisId);
if (!$devis) die("Devis introuvable.");

$offreModel = new Offre($pdo);
$offre = $offreModel->getOffreById($devis['offre_id']);
if (!$offre) die("Projet introuvable.");

$clientModel = new Client($pdo);
$client = $clientModel->getClientById($devis['client_id']);
if (!$client) die("Client introuvable.");

$uniteModel = new UniteMesure($pdo);
$unites = $uniteModel->getAll();
if (!$unites) die("Aucune unité de mesure trouvée.");

$debourses = $devisModel->getDeboursesByDevisId($devisId);
$invalidDebourseStatement = $pdo->prepare('SELECT d.id, GROUP_CONCAT(NULLIF(TRIM(detail.designation), "") ORDER BY detail.id SEPARATOR " / ") AS title FROM debourse_banamur d LEFT JOIN ligne_devis_banamur ld ON ld.id=d.ligne_devis_id AND ld.devis_id=d.devis_id LEFT JOIN ligne_debourse_banamur detail ON detail.debourse_id=d.id WHERE d.devis_id=:id AND ld.id IS NULL GROUP BY d.id ORDER BY d.id');
$invalidDebourseStatement->execute(['id' => $devisId]);
$invalidDebourses = $invalidDebourseStatement->fetchAll(PDO::FETCH_ASSOC);
if (!$debourses && !$invalidDebourses) die("Aucun déboursé trouvé.");
$billingSchedule = $creationGuard->schedule($devisId);
$debourseTotal = array_sum(array_map(static fn(array $row): float => (float) ($row['montant_debourse'] ?? 0), $debourses));
$quoteTotal = (float) ($devis['total_ttc'] ?? 0);
$debourseRate = $quoteTotal > 0 ? ($debourseTotal / $quoteTotal * 100) : 0;

$chantierModel = new Chantier($pdo);
$lastIndex = $chantierModel->getLastIndex();
$codeChantier = 'CH' . str_pad($lastIndex + 1, 4, '0', STR_PAD_LEFT);

// Récupération des lignes du devis
$lignesDevis = $devisModel->getLignesDevis($devisId);

// Récupérer toutes les lignes de déboursé liées aux déboursés de ce devis
$precisionModel = new PrecisionFiche($pdo);
$precisions = $precisionModel->getByDevis($devisId);

// Pour la démo JS, on prépare tout côté client
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Débuter un chantier</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: #fffbe6;
        }

        .navbar {
            background: #111;
        }

        .navbar-brand,
        .navbar-nav .nav-link {
            color: #ffc107 !important;
        }

        .wizard-nav {
            margin-bottom: 40px;
        }

        .wizard-nav .step {
            display: inline-block;
            min-width: 120px;
            text-align: center;
            color: #888;
            font-weight: bold;
            border-bottom: 3px solid #ffe066;
            padding-bottom: 8px;
            margin-right: 10px;
        }

        .wizard-nav .step.active {
            color: #111;
            border-bottom: 3px solid #ffc107;
            background: #ffe066;
            border-radius: 8px 8px 0 0;
        }

        .form-section {
            background: #fffde7;
            border-radius: 8px;
            box-shadow: 0 2px 8px #0001;
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid #ffe066;
        }

        .btn-primary,
        .btn-success {
            background: #ffc107;
            border: none;
            color: #111;
            font-weight: bold;
        }

        .btn-primary:hover,
        .btn-success:hover {
            background: #ffe066;
            color: #111;
        }

        .btn-outline-dark {
            border-color: #ffc107;
            color: #111;
        }

        .btn-outline-dark:hover {
            background: #ffc107;
            color: #111;
        }

        .table thead {
            background: #ffe066;
        }

        .stepper-btns {
            display: flex;
            justify-content: space-between;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-hard-hat"></i> Banamur</a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="liste_devis.php">Devis</a></li>
                    <li class="nav-item"><a class="nav-link" href="liste_chantiers.php">Chantiers</a></li>
                    <li class="nav-item"><a class="nav-link" href="logout.php">Déconnexion</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <div class="wizard-nav text-center mb-4">
            <span class="step" id="step-nav-1">1. Chantier</span>
            <span class="step" id="step-nav-2">2. Opérations</span>
            <span class="step" id="step-nav-3">3. Déboursés</span>
            <span class="step" id="step-nav-4">4. Précisions</span>
        </div>

        <form id="wizardForm">
            <!-- Etape 1 -->
            <div class="form-section" id="step-1">
                <h3 class="mb-4"><i class="fas fa-warehouse"></i> Étape 1 : Créer un chantier (Devis)</h3>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Titre du chantier</label>
                        <input type="text" name="titre" class="form-control" value="<?= htmlspecialchars($offre['reference_offre']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Code chantier</label>
                        <input type="text" name="code" class="form-control" value="<?= htmlspecialchars($codeChantier) ?>" required>
                    </div>
                </div>
                <div class="rounded border border-warning bg-light p-3 mb-3">
                    <h5><i class="fas fa-file-signature"></i> Bon de commande du client</h5>
                    <p class="small text-muted">Obligatoire pour ouvrir le chantier. Un écart avec le TTC du devis sera signalé, sans bloquer la saisie.</p>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Numéro BC client</label><input name="client_po_number" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Montant BC</label><input name="client_po_amount" type="number" min="0.01" step="0.01" value="<?= htmlspecialchars((string) ($devis['total_ttc'] ?? '')) ?>" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Date du BC</label><input name="client_po_date" type="date" class="form-control" required></div>
                        <div class="col-md-4"><label class="form-label">Début prévisionnel</label><input name="expected_start_date" type="date" class="form-control"></div>
                    </div>
                </div>
                <div class="rounded border border-primary bg-light p-3 mb-3">
                    <h5><i class="fas fa-calendar-days"></i> Planning obligatoire du devis</h5>
                    <p class="small text-muted">Ces dates encadrent l’exécution du chantier et seront enregistrées directement sur le devis.</p>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Début du chantier</label><input name="project_start_date" type="date" min="<?= htmlspecialchars((string) ($devis['date_emission'] ?? '')) ?>" value="<?= htmlspecialchars((string) ($devis['project_start_date'] ?? '')) ?>" class="form-control" required></div>
                        <div class="col-md-6"><label class="form-label">Fin prévue du chantier</label><input name="project_end_date" type="date" min="<?= htmlspecialchars((string) ($devis['project_start_date'] ?? $devis['date_emission'] ?? '')) ?>" value="<?= htmlspecialchars((string) ($devis['project_end_date'] ?? '')) ?>" class="form-control" required></div>
                    </div>
                </div>
                <div class="rounded border border-success bg-light p-3 mb-3">
                    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center"><div><h5 class="mb-1"><i class="fas fa-file-invoice-dollar"></i> Échéancier de facturation et d’encaissement</h5><p class="small text-muted mb-0">Les échéances doivent représenter exactement 100 % du total du devis.</p></div><button type="button" id="addBillingRow" class="btn btn-sm btn-outline-success"><i class="fas fa-plus"></i> Ajouter une échéance</button></div>
                    <div class="table-responsive mt-3"><table class="table table-sm align-middle"><thead><tr><th>Libellé</th><th>Date prévue</th><th>Pourcentage</th><th>Montant calculé</th><th></th></tr></thead><tbody id="billingScheduleRows"></tbody><tfoot><tr><th colspan="2">Total</th><th id="billingPercentTotal">0 %</th><th id="billingAmountTotal">0 FCFA</th><th></th></tr></tfoot></table></div>
                    <div id="billingScheduleError" class="alert alert-danger py-2 d-none">L’échéancier doit contenir au moins une ligne et totaliser exactement 100 %.</div>
                    <input type="hidden" name="billing_schedule_json" id="billingScheduleJson" value="[]">
                </div>
                <div class="alert <?= $debourseRate <= 65 ? 'alert-success' : 'alert-danger' ?> mb-3">
                    <strong>Contrôle du déboursé :</strong> <?= number_format($debourseTotal, 0, ',', ' ') ?> FCFA, soit <?= number_format($debourseRate, 2, ',', ' ') ?> % du devis.
                    La création est autorisée uniquement si un déboursé existe, s’il est lié aux lignes du devis, planifié et inférieur ou égal à 65 %.
                </div>
                <?php if ($invalidDebourses): ?>
                    <div class="alert alert-warning mb-3">
                        <strong>Déboursés exclus :</strong> ces déboursés correspondent à des lignes qui ne figurent plus dans le devis. Ils ne seront ni pris en compte dans les contrôles ni copiés dans le chantier.
                        <ul class="mb-0 mt-2">
                            <?php foreach ($invalidDebourses as $invalidDebourse): ?>
                                <li>Déboursé #<?= (int) $invalidDebourse['id'] ?> — <?= htmlspecialchars(trim((string) ($invalidDebourse['title'] ?? '')) ?: 'Sans titre renseigné', ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Client</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($client['nom_client']) ?>" disabled>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Projet</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($offre['num_offre']) ?>" disabled>
                    </div>
                </div>
            </div>
            <!-- Etape 2 -->
            <div class="form-section d-none" id="step-2">
                <h3 class="mb-4"><i class="fas fa-list"></i> Étape 2 : Intégration des opérations (Lignes devis)</h3>
                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>Désignation</th>
                            <th>Qté</th>
                            <th>Unité</th>
                            <th>Prix Unitaire</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody id="table-operations">
                    </tbody>
                </table>
            </div>
            <!-- Etape 3 -->
            <div class="form-section d-none" id="step-3">
                <h3 class="mb-4"><i class="fas fa-coins"></i> Étape 3 : Intégration des désignations (Titres déboursés)</h3>
                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>Désignation</th>
                            <th>Montant</th>
                            <th>Opération liée</th>
                        </tr>
                    </thead>
                    <tbody id="table-debourses">
                    </tbody>
                </table>
            </div>
            <!-- Etape 4 -->
            <div class="form-section d-none" id="step-4">
                <h3 class="mb-4"><i class="fas fa-info-circle"></i> Étape 4 : Intégration des précisions (Lignes déboursés)</h3>
                <table class="table table-bordered align-middle">
                    <thead>
                        <tr>
                            <th>Catégorie</th>
                            <th>Précision</th>
                            <th>Montant</th>
                            <th>Date début</th>
                            <th>Date fin</th>
                            <th>Ligne devis ID</th>
                            <th>Désignation ligne devis</th>
                            <th>Déboursé ID</th>
                        </tr>
                    </thead>
                    <tbody id="table-precisions">
                    </tbody>
                </table>
            </div>

            <!-- Champs cachés pour l'envoi POST -->
            <input type="hidden" name="devis_id" value="<?= htmlspecialchars($devisId) ?>">
            <input type="hidden" name="client_id" value="<?= htmlspecialchars($client['id_client']) ?>">

            <div class="stepper-btns mt-3">
                <button type="button" class="btn btn-outline-dark" id="prevBtn" disabled><i class="fas fa-arrow-left"></i> Précédent</button>
                <button type="button" class="btn btn-primary" id="nextBtn">Suivant <i class="fas fa-arrow-right"></i></button>
            </div>
        </form>
    </div>
    <script>
        (function() {
            try {
                console.log('Wizard script loaded');
                const lignesDevis = <?= json_encode($lignesDevis ?? []) ?>;
                const debourses = <?= json_encode($debourses ?? []) ?>;
                const precisions = <?= json_encode($precisions ?? []) ?>;
                const quoteTotal = <?= json_encode($quoteTotal) ?>;
                const quoteEmissionDate = <?= json_encode((string)($devis['date_emission'] ?? '')) ?>;
                const initialBillingSchedule = <?= json_encode($billingSchedule, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                const _unites_json = '<?= addslashes(json_encode($unites ?? [])) ?>';
                const unites = _unites_json ? JSON.parse(_unites_json) : [];

                const billingBody = document.getElementById('billingScheduleRows');
                const billingJson = document.getElementById('billingScheduleJson');
                const billingError = document.getElementById('billingScheduleError');
                const money = value => new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0}).format(value) + ' FCFA';
                function addBillingRow(row = {}) {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `<td><input class="form-control form-control-sm billing-label" required value="${String(row.label || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;')}"></td><td><input type="date" min="${quoteEmissionDate}" class="form-control form-control-sm billing-date" required value="${row.expected_date || ''}"></td><td><div class="input-group input-group-sm"><input type="number" min="0.01" max="100" step="0.01" class="form-control billing-percentage" required value="${row.percentage || ''}"><span class="input-group-text">%</span></div></td><td class="billing-amount fw-bold">0 FCFA</td><td><button type="button" class="btn btn-sm btn-outline-danger billing-remove" title="Supprimer"><i class="fas fa-times"></i></button></td>`;
                    tr.querySelector('.billing-remove').addEventListener('click', () => { tr.remove(); refreshBilling(); });
                    tr.querySelectorAll('input').forEach(input => input.addEventListener('input', refreshBilling));
                    billingBody.appendChild(tr);
                    refreshBilling();
                }
                function refreshBilling() {
                    const rows = Array.from(billingBody.querySelectorAll('tr')).map(tr => {
                        const percentage = Number(tr.querySelector('.billing-percentage').value) || 0;
                        tr.querySelector('.billing-amount').textContent = money(quoteTotal * percentage / 100);
                        return {label: tr.querySelector('.billing-label').value.trim(), expected_date: tr.querySelector('.billing-date').value, percentage};
                    });
                    const totalPercent = rows.reduce((sum, row) => sum + row.percentage, 0);
                    document.getElementById('billingPercentTotal').textContent = totalPercent.toLocaleString('fr-FR', {maximumFractionDigits: 2}) + ' %';
                    document.getElementById('billingAmountTotal').textContent = money(quoteTotal * totalPercent / 100);
                    billingJson.value = JSON.stringify(rows);
                    const valid = rows.length > 0 && Math.abs(totalPercent - 100) <= 0.01 && rows.every(row => row.label && row.expected_date && row.percentage > 0);
                    billingError.classList.toggle('d-none', valid);
                    return valid;
                }
                document.getElementById('addBillingRow').addEventListener('click', () => addBillingRow());
                const projectStartInput = document.querySelector('[name="project_start_date"]');
                const projectEndInput = document.querySelector('[name="project_end_date"]');
                projectStartInput.addEventListener('change', () => { projectEndInput.min = projectStartInput.value; if (projectEndInput.value && projectEndInput.value < projectStartInput.value) projectEndInput.value = ''; });
                (initialBillingSchedule.length ? initialBillingSchedule : [{label: 'Acompte', percentage: 50}, {label: 'Solde', percentage: 50}]).forEach(addBillingRow);

                function getUniteSymbole(id) {
                    const u = Array.isArray(unites) ? unites.find(u => u.id == id) : null;
                    return u ? u.symbole : '';
                }

                function fillOperations() {
                    let html = '';
                    console.log('Debourses (from fillOperations):', debourses);

                    (lignesDevis || []).forEach(ligne => {
                        html += `<tr>
                    <td>${ligne.designation}</td>
                    <td>${ligne.quantite}</td>
                    <td>${getUniteSymbole(ligne.unite_id)}</td>
                    <td>${Number(ligne.prix).toLocaleString()}</td>
                    <td>${Number(ligne.total).toLocaleString()}</td>
                </tr>`;
                    });
                    const opsEl = document.getElementById('table-operations');
                    if (opsEl) opsEl.innerHTML = html;
                }

                let currentStep = 1;
                const totalSteps = 4;
                const stepNavs = Array.from(document.querySelectorAll('.wizard-nav .step'));

                function showStep(step) {
                    for (let i = 1; i <= totalSteps; i++) {
                        const sec = document.getElementById('step-' + i);
                        if (sec) sec.classList.toggle('d-none', i !== step);
                        if (stepNavs[i - 1]) stepNavs[i - 1].classList.toggle('active', i === step);
                    }
                    const prevBtn = document.getElementById('prevBtn');
                    const nextBtn = document.getElementById('nextBtn');
                    if (prevBtn) prevBtn.disabled = (step === 1);
                    if (nextBtn) nextBtn.innerHTML = (step === totalSteps) ? 'Valider <i class="fas fa-check"></i>' : 'Suivant <i class="fas fa-arrow-right"></i>';
                }
                showStep(currentStep);

                function fillDebourses() {
                    const table = document.getElementById('table-debourses');
                    if (!table) return;
                    table.innerHTML = (debourses || []).map(d =>
                        `<tr>
                    <td>${d.designation_ligne}</td>
                    <td>${Number(d.montant_debourse).toLocaleString()}</td>
                    <td>${d.ligne_devis_id}</td>
                </tr>`
                    ).join('');
                }

                function fillPrecisions() {
                    console.log('Précisions :', precisions);
                    const table = document.getElementById('table-precisions');
                    if (!table) return;
                    table.innerHTML = (precisions || []).map(p =>
                        `<tr>
            <td>${p.categorie}</td>
            <td>${p.designation}</td>
            <td>${Number(p.montant).toLocaleString()}</td>
            <td>${p.date_debut ?? ''}</td>
            <td>${p.date_fin ?? ''}</td>
            <td>${p.ligne_devis_id}</td>
            <td>${p.designation_ligne}</td>
            <td>${p.debourse_id}</td>
        </tr>`
                    ).join('');
                }

                const nextBtn = document.getElementById('nextBtn');
                const prevBtn = document.getElementById('prevBtn');

                if (!nextBtn) {
                    console.error('nextBtn element not found');
                    return;
                }

                nextBtn.addEventListener('click', function() {
                    try {
                        if (currentStep === 1) {
                            const titreEl = document.querySelector('[name="titre"]');
                            const codeEl = document.querySelector('[name="code"]');
                            const titre = titreEl ? titreEl.value.trim() : '';
                            const code = codeEl ? codeEl.value.trim() : '';
                            const start = document.querySelector('[name="project_start_date"]');
                            const end = document.querySelector('[name="project_end_date"]');
                            if (!titre || !code || !start.value || !end.value || end.value < start.value) {
                                alert('Renseignez le chantier et un planning valide avec une date de début et une date de fin.');
                                return;
                            }
                            if (!refreshBilling()) {
                                alert('L’échéancier de facturation doit être complet et totaliser exactement 100 %.');
                                return;
                            }
                        }
                        if (currentStep === 2) fillDebourses();
                        if (currentStep === 3) fillPrecisions();

                        if (currentStep < totalSteps) {
                            currentStep++;
                            showStep(currentStep);
                            if (currentStep === 2) fillOperations();
                        } else {
                            const formData = new FormData(document.getElementById('wizardForm'));
                            const submitButton = nextBtn;
                            submitButton.disabled = true;
                            fetch('request/creer_chantier.php', {
                                method: 'POST',
                                body: formData
                            }).then(async r => {
                                const body = await r.text();
                                let res;
                                try {
                                    res = JSON.parse(body);
                                } catch (e) {
                                    throw new Error('Réponse du serveur illisible (HTTP ' + r.status + '). ' + (body.trim().slice(0, 300) || 'Réponse vide.'));
                                }
                                if (!res || typeof res !== 'object') {
                                    throw new Error('Réponse du serveur invalide (HTTP ' + r.status + ').');
                                }
                                return res;
                            }).then(res => {
                                if (res.success) {
                                    if (res.amountWarning) {
                                        alert(res.amountWarning);
                                    }
                                    window.location.href = 'details_chantier.php?id=' + res.chantierId;
                                } else {
                                    alert(res.message || 'Erreur lors de la validation.');
                                }
                            }).catch(e => {
                                console.error('Erreur création chantier:', e);
                                alert(e.message || 'Erreur lors de la création du chantier.');
                            }).finally(() => {
                                submitButton.disabled = false;
                            });
                        }
                    } catch (e) {
                        console.error('Error in nextBtn handler:', e);
                    }
                });

                if (prevBtn) {
                    prevBtn.addEventListener('click', function() {
                        if (currentStep > 1) {
                            currentStep--;
                            showStep(currentStep);
                        }
                    });
                }

            } catch (e) {
                console.error('Wizard init error', e);
            }
        })();
    </script>

</body>

</html>
