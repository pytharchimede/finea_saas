<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Droit d'accès aux informations financières (budget/montants fiches liées)
// Visible uniquement si l'utilisateur est connecté avec acces_point_financier == 1
$canSeeFinance = (isset($_SESSION['acces_point_financier']) && intval($_SESSION['acces_point_financier']) === 1);

require_once __DIR__ . '/../../model/Database.php';
require_once __DIR__ . '/../../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../../services/UrgentDisbursementService.php';
require_once __DIR__ . '/../model/Affectation.php';
require_once __DIR__ . '/../model/Service.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../../employe/includes/helpers.php';

$pdo = Database::getConnection();
$febManager = new EmployeeLegalRequestManager($pdo);
$urgentDisbursementService = new UrgentDisbursementService($pdo);

function decaissementGateFicheStatus(array $row, string $todayYmd): array
{
    $approuve = (int) ($row['approuve'] ?? 0);
    $etat = (int) ($row['etat_fiche'] ?? 0);
    $decaisse = (int) ($row['decaisse'] ?? 0);
    $conforme = (int) ($row['conforme'] ?? 0);
    $otp = (int) ($row['otp_valide'] ?? 0);

    $dateMin = trim((string) ($row['date_decaissement_minimum'] ?? ''));
    $dateReport = '';
    if ($dateMin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateMin)) {
        $dateReport = substr($dateMin, 0, 10);
    }

    if ($decaisse === 1) {
        return ['label' => 'Décaissée', 'class' => 'text-bg-success'];
    }
    if ($dateReport !== '' && $dateReport > $todayYmd) {
        return ['label' => 'Reportée au ' . employeeFormatDateFr($dateReport), 'class' => 'text-bg-warning'];
    }
    if ($approuve === 2 || $etat === 2) {
        return ['label' => 'Refusée', 'class' => 'text-bg-danger'];
    }
    if ($etat === 1) {
        return ['label' => 'Traitement comptable', 'class' => 'text-bg-secondary'];
    }
    if ($otp === 1) {
        return ['label' => 'OTP validé', 'class' => 'text-bg-info'];
    }
    if ($approuve === 1) {
        return ['label' => 'Validation achat', 'class' => 'text-bg-primary'];
    }
    if ($conforme === 1) {
        return ['label' => 'Approbation', 'class' => 'text-bg-info'];
    }
    return ['label' => 'Conformité', 'class' => 'text-bg-light'];
}

function decaissementGateLinkedFichesByCode(PDO $pdo, array $approvedFebs): array
{
    $codes = [];
    foreach ($approvedFebs as $row) {
        $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
        $code = trim((string) ($display['numero_bon_commande_interne'] ?? $display['code_autorisation_feb'] ?? ''));
        if ($code !== '') {
            $codes[strtolower($code)] = $code;
        }
    }

    if (!$codes) {
        return [];
    }

    $params = [];
    $placeholders = [];
    foreach (array_values($codes) as $idx => $code) {
        $key = ':code' . $idx;
        $placeholders[] = $key;
        $params[$key] = $code;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT
                id_fiche,
                num_fiche,
                beficiaire_fiche,
                montant_fiche,
                code_autorisation_feb,
                date_creat_fiche,
                approuve,
                conforme,
                decaisse,
                otp_valide,
                etat_fiche,
                date_decaissement_minimum
            FROM fiche_decaissement
            WHERE code_autorisation_feb IN (' . implode(',', $placeholders) . ')
            ORDER BY date_creat_fiche DESC, id_fiche DESC
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }

    $todayYmd = date('Y-m-d');
    $map = [];
    foreach ($rows as $row) {
        $code = strtolower(trim((string) ($row['code_autorisation_feb'] ?? '')));
        if ($code === '') {
            continue;
        }
        $row['_status'] = decaissementGateFicheStatus($row, $todayYmd);
        $map[$code][] = $row;
    }

    return $map;
}

function decaissementGateFindLinkedFicheByCode(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT
                id_fiche,
                num_fiche,
                beficiaire_fiche,
                montant_fiche,
                code_autorisation_feb,
                date_creat_fiche,
                approuve,
                conforme,
                decaisse,
                otp_valide,
                etat_fiche,
                date_decaissement_minimum
            FROM fiche_decaissement
            WHERE code_autorisation_feb = :code
            ORDER BY date_creat_fiche DESC, id_fiche DESC
            LIMIT 1
        ');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }
        $row['_status'] = decaissementGateFicheStatus($row, date('Y-m-d'));
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function decaissementRenderFebAuthorizationGate(
    EmployeeLegalRequestManager $febManager,
    PDO $pdo,
    string $error = '',
    int $preselectedFournisseurId = 0
): void {
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    $userEmail = (string) ($_SESSION['mail_pro'] ?? '');
    $personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
    $matricule = strtoupper(trim((string) ($personnelProfile['matricule'] ?? '')));
    $approvedFebs = $febManager->listFebRequestsForUserOrMatricule($userId, $matricule, ['approved'], 200);
    $approvedFebs = array_values(array_filter($approvedFebs, static function (array $row): bool {
        $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
        return trim((string) ($display['code_autorisation_feb'] ?? '')) !== '';
    }));
    $linkedFichesByCode = decaissementGateLinkedFichesByCode($pdo, $approvedFebs);
    ?>
    <!DOCTYPE html>
    <html lang="fr">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>N BCI requis</title>
        <link href="plugins/css/bootstrap/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="plugins/css/fontawesome/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    </head>

    <body class="bg-light">
        <main class="container py-5">
            <div class="mx-auto" style="max-width: 980px;">
                <div class="card border-0 shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
                            <div>
                                <div class="text-uppercase text-primary fw-semibold small">Décaissement</div>
                                <h1 class="h3 mt-2 mb-2">N bon de commande interne (BCI) requis</h1>
                                <p class="text-muted mb-0">Saisissez le N BCI d'une FEB validee ou ouvrez le formulaire depuis la liste ci-dessous.</p>
                            </div>
                            <a class="btn btn-outline-secondary" href="../employe/mes_demandes_employe.php?type=feb&status=approved">
                                <i class="fas fa-list"></i> Listing complet
                            </a>
                        </div>

                        <?php if ($error !== ''): ?>
                            <div class="alert alert-danger mt-4 mb-0"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>

                        <form method="get" action="formulaire_demande_decaissement.php" class="mt-4 rounded border bg-white p-3">
                            <?php if ($preselectedFournisseurId > 0): ?>
                                <input type="hidden" name="fournisseur_id" value="<?= (int) $preselectedFournisseurId ?>">
                            <?php endif; ?>
                            <label for="code_autorisation_feb" class="form-label fw-semibold">N bon de commande interne (BCI)</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="code_autorisation_feb" name="code_autorisation_feb" required placeholder="Ex: BCI-FEB-2605-00001" autocomplete="off">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fas fa-arrow-right"></i> Continuer
                                </button>
                            </div>
                        </form>

                        <div class="mt-4">
                            <h2 class="h5 mb-3">FEB validées disponibles</h2>
                            <?php if (!$approvedFebs): ?>
                                <div class="alert alert-warning mb-0">
                                    Aucune FEB validee avec bon de commande interne n'a ete trouvee pour votre profil.
                                    <a href="../employe/nouvelle_demande_employe.php?type=feb" class="alert-link">Créer une FEB</a>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle">
                                        <thead>
                                            <tr>
                                                <th>Réf.</th>
                                                <th>Description</th>
                                                <th>Montant</th>
                                                <th>N BCI</th>
                                                <th>Fiche liÃ©e</th>
                                                <th class="text-end"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($approvedFebs as $row): ?>
                                                <?php
                                                $display = (array) ($row['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($row));
                                                $code = trim((string) ($display['numero_bon_commande_interne'] ?? $display['code_autorisation_feb'] ?? ''));
                                                $linkedFiches = $code !== '' ? (($linkedFichesByCode[strtolower($code)] ?? []) ?: []) : [];
                                                $linkedFiche = $linkedFiches[0] ?? null;
                                                $linkedStatus = is_array($linkedFiche) ? (array) ($linkedFiche['_status'] ?? []) : [];
                                                $query = ['code_autorisation_feb' => $code];
                                                if ($preselectedFournisseurId > 0) {
                                                    $query['fournisseur_id'] = $preselectedFournisseurId;
                                                }
                                                ?>
                                                <tr>
                                                    <td class="fw-semibold"><?= htmlspecialchars((string) ($display['chantier_no'] ?? ('#' . (int) ($row['id'] ?? 0)))) ?></td>
                                                    <td>
                                                        <div class="fw-semibold"><?= htmlspecialchars((string) ($display['demandeur'] ?? ($row['requester_name'] ?? ''))) ?></div>
                                                        <div class="small text-muted"><?= htmlspecialchars((string) ($display['description'] ?? ($row['title'] ?? ''))) ?></div>
                                                    </td>
                                                    <td><?= htmlspecialchars((string) ($display['montant_label'] ?? '-')) ?></td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-success js-copy-feb-code" data-copy-feb-code="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>">
                                                            <i class="fas fa-copy"></i> <?= htmlspecialchars($code) ?>
                                                        </button>
                                                        <span class="small text-success ms-1 js-copy-feedback d-none">CopiÃ©</span>
                                                    </td>
                                                    <td>
                                                        <?php if (is_array($linkedFiche)): ?>
                                                            <div class="fw-semibold">
                                                                <a href="../employe/details_decaissement_employe.php?num_fiche=<?= urlencode((string) ($linkedFiche['num_fiche'] ?? '')) ?>">
                                                                    <?= htmlspecialchars((string) ($linkedFiche['num_fiche'] ?? '')) ?>
                                                                </a>
                                                                <?php if (count($linkedFiches) > 1): ?>
                                                                    <span class="small text-muted">+<?= count($linkedFiches) - 1 ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <span class="badge <?= htmlspecialchars((string) ($linkedStatus['class'] ?? 'text-bg-light')) ?>"><?= htmlspecialchars((string) ($linkedStatus['label'] ?? 'Statut inconnu')) ?></span>
                                                        <?php else: ?>
                                                            <span class="badge text-bg-light">Aucune fiche</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-end">
                                                        <?php if (is_array($linkedFiche) && !empty($linkedFiche['num_fiche'])): ?>
                                                            <a class="btn btn-sm btn-outline-primary" href="../employe/details_decaissement_employe.php?num_fiche=<?= urlencode((string) ($linkedFiche['num_fiche'] ?? '')) ?>">
                                                                Voir fiche
                                                            </a>
                                                        <?php else: ?>
                                                            <a class="btn btn-sm btn-success" href="formulaire_demande_decaissement.php?<?= htmlspecialchars(http_build_query($query), ENT_QUOTES, 'UTF-8') ?>">
                                                                Utiliser
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
        <script>
            (function() {
                async function copyText(value) {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(value);
                        return;
                    }

                    const textarea = document.createElement('textarea');
                    textarea.value = value;
                    textarea.setAttribute('readonly', 'readonly');
                    textarea.style.position = 'fixed';
                    textarea.style.left = '-9999px';
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    textarea.remove();
                }

                document.addEventListener('click', async function(event) {
                    const button = event.target.closest('[data-copy-feb-code]');
                    if (!button) return;

                    const code = String(button.getAttribute('data-copy-feb-code') || '').trim();
                    if (!code) return;

                    try {
                        await copyText(code);
                        const wrap = button.parentElement;
                        const feedback = wrap ? wrap.querySelector('.js-copy-feedback') : null;
                        if (feedback) {
                            feedback.classList.remove('d-none');
                            window.setTimeout(function() {
                                feedback.classList.add('d-none');
                            }, 1200);
                        }
                    } catch (e) {
                        // Le code reste affichÃ© si la copie navigateur Ã©choue.
                    }
                });
            })();
        </script>
    </body>

    </html>
    <?php
}

$preselectedFournisseurIdForGate = (int) ($_GET['fournisseur_id'] ?? 0);
$requestedFebCode = trim((string) ($_GET['code_autorisation_feb'] ?? ''));
$currentUrgentRegularizationId = (int)($_GET['urgent_id'] ?? $_POST['urgent_id'] ?? 0);
$urgentBlockedRows = array_values(array_filter(
    $urgentDisbursementService->listBlockedForUser((int)($_SESSION['user_id'] ?? 0)),
    static fn(array $row): bool => (int)($row['id'] ?? 0) !== $currentUrgentRegularizationId
));
if ($urgentBlockedRows) {
    $blocked = $urgentBlockedRows[0];
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Regularisation urgence requise</title>
        <link href="plugins/css/bootstrap/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <main class="container py-5">
            <div class="mx-auto card border-danger shadow-sm" style="max-width: 920px;">
                <div class="card-body p-4 p-md-5">
                    <div class="text-uppercase text-danger fw-bold small">Chantier bloque</div>
                    <h1 class="h3 mt-2">Fiche de <?= htmlspecialchars((string)($blocked['requester_name'] ?? 'demandeur')) ?> non regularisee</h1>
                    <p class="text-muted mt-3 mb-0">
                        Le decaissement urgent <?= htmlspecialchars((string)($blocked['request_code'] ?? '')) ?>
                        sur <?= htmlspecialchars((string)($blocked['chantier_label'] ?? 'ce chantier')) ?>
                        a depasse le delai de 3 jours sans FEB et fiche associees.
                    </p>
                    <div class="alert alert-warning mt-4">
                        Creez d'abord la FEB de regularisation, puis revenez creer la fiche de decaissement associee.
                    </div>
                    <a class="btn btn-danger" href="../employe/nouvelle_demande_employe.php?type=feb&urgent_id=<?= (int)($blocked['id'] ?? 0) ?>">
                        Creer la FEB de regularisation
                    </a>
                </div>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

if ($requestedFebCode !== '') {
    $approvedFeb = $febManager->findApprovedFebByAuthorizationCode($requestedFebCode);
    if (!$approvedFeb) {
        unset($_SESSION['code_autorisation_feb']);
        decaissementRenderFebAuthorizationGate($febManager, $pdo, 'Ce N BCI est introuvable ou la FEB n\'est pas encore validee.', $preselectedFournisseurIdForGate);
        exit;
    }
    $approvedFebDisplay = (array) ($approvedFeb['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($approvedFeb));
    $normalizedFebCode = trim((string) ($approvedFebDisplay['numero_bon_commande_interne'] ?? $approvedFebDisplay['code_autorisation_feb'] ?? $requestedFebCode));
    $linkedFiche = decaissementGateFindLinkedFicheByCode($pdo, $normalizedFebCode);
    if (is_array($linkedFiche)) {
        unset($_SESSION['code_autorisation_feb']);
        $linkedStatus = (array) ($linkedFiche['_status'] ?? []);
        $message = 'Ce N BCI a deja ete utilise pour la fiche ' . (string) ($linkedFiche['num_fiche'] ?? '');
        if (!empty($linkedStatus['label'])) {
            $message .= ' (' . (string) $linkedStatus['label'] . ')';
        }
        $message .= '. Consultez la fiche existante au lieu de creer une nouvelle fiche.';
        decaissementRenderFebAuthorizationGate($febManager, $pdo, $message, $preselectedFournisseurIdForGate);
        exit;
    }
    $_SESSION['code_autorisation_feb'] = $normalizedFebCode;
} else {
    unset($_SESSION['code_autorisation_feb']);
    decaissementRenderFebAuthorizationGate($febManager, $pdo, '', $preselectedFournisseurIdForGate);
    exit;
}

$affectationObj = new Affectation($pdo);
$serviceObj = new Service($pdo);
$ficheObj = new FicheDecaissement($pdo);
$fournisseurObj = new FournisseurRepository($pdo);

$listeAffectation = $affectationObj->getAllAffectations();
$listeService = $serviceObj->getAllServices();
$listeFournisseursValides = $fournisseurObj->listForSelect(true, true);
