<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();

function employeeLower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function format_fcfa(float $value): string
{
    if (!is_finite($value) || $value <= 0) {
        return '0 FCFA';
    }
    return number_format($value, 0, ',', ' ') . ' FCFA';
}

function decaissementStatus(array $row, string $todayYmd): array
{
    $approuve = (int) ($row['approuve'] ?? 0);
    $etat = (int) ($row['etat_fiche'] ?? 0);
    $decaisse = (int) ($row['decaisse'] ?? 0);
    $otp = (int) ($row['otp_valide'] ?? 0);
    $conforme = (int) ($row['conforme'] ?? 0);

    $dateMin = trim((string) ($row['date_decaissement_minimum'] ?? ''));
    $dateReport = '';
    if ($dateMin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateMin)) {
        $dateReport = substr($dateMin, 0, 10);
    }

    if ($decaisse === 1) {
        return ['key' => 'decaissee', 'label' => 'Décaissée', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'date_report' => ''];
    }

    if ($dateReport !== '' && $dateReport > $todayYmd) {
        return ['key' => 'reportee', 'label' => 'Reportée', 'class' => 'bg-amber-100 text-amber-800 border-amber-200', 'date_report' => $dateReport];
    }

    if ($approuve === 2 || $etat === 2) {
        return ['key' => 'refusee', 'label' => 'Refusée', 'class' => 'bg-rose-100 text-rose-800 border-rose-200', 'date_report' => ''];
    }

    if ($otp === 1) {
        return ['key' => 'otp', 'label' => 'OTP validé', 'class' => 'bg-sky-100 text-sky-800 border-sky-200', 'date_report' => ''];
    }

    if ($approuve === 1) {
        return ['key' => 'acceptee', 'label' => 'Acceptée', 'class' => 'bg-indigo-100 text-indigo-800 border-indigo-200', 'date_report' => ''];
    }

    if ($conforme === 1) {
        return ['key' => 'conforme', 'label' => 'Conforme', 'class' => 'bg-blue-100 text-blue-800 border-blue-200', 'date_report' => ''];
    }

    return ['key' => 'attente', 'label' => 'En attente', 'class' => 'bg-slate-100 text-slate-700 border-slate-200', 'date_report' => ''];
}

$numFiche = trim((string) ($_GET['num_fiche'] ?? ''));
if ($numFiche === '') {
    header('Location: mes_decaissements_employe.php');
    exit;
}

$connectedName = employeeCurrentUserName();
$connectedTokens = preg_split('/\s+/u', employeeLower($connectedName)) ?: [];
$connectedTokens = array_values(array_filter(array_unique(array_map('trim', $connectedTokens)), static function (string $t): bool {
    return $t !== '' && (function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t)) >= 3;
}));

$stmt = $pdo->prepare('
    SELECT
        f.*, 
        c.titre AS chantier_titre, c.code AS chantier_code, c.entreprise AS chantier_entreprise,
        cl.nom_client,
        ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ldb.montant AS ligne_montant,
        db.montant_debourse AS debourse_montant,
        ld.designation AS devis_designation
    FROM fiche_decaissement f
    LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
    LEFT JOIN client_banamur cl ON cl.id_client = c.client_id
    LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
    LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
    LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
    WHERE f.num_fiche = :num
    LIMIT 1
');
$stmt->execute(['num' => $numFiche]);
$fiche = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$fiche) {
    http_response_code(404);
    echo 'Fiche introuvable';
    exit;
}

$benef = employeeLower(trim((string) ($fiche['beficiaire_fiche'] ?? '')));
$authorized = true;
if ($connectedTokens) {
    foreach ($connectedTokens as $t) {
        if (strpos($benef, $t) === false) {
            $authorized = false;
            break;
        }
    }
} else {
    $authorized = $benef !== '' && strpos($benef, employeeLower($connectedName)) !== false;
}

if (!$authorized) {
    $linkedFebCode = trim((string) ($fiche['code_autorisation_feb'] ?? ''));
    if ($linkedFebCode !== '') {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $userEmail = (string) ($_SESSION['mail_pro'] ?? '');
        $personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
        $actorMatricule = strtoupper(trim((string) ($personnelProfile['matricule'] ?? '')));
        $manager = new \EmployeeLegalRequestManager($pdo);
        $febRows = $manager->listFebRequestsForUserOrMatricule($userId, $actorMatricule, ['approved'], 500);

        foreach ($febRows as $febRow) {
            $display = (array) ($febRow['feb_display'] ?? \EmployeeLegalRequestManager::extractFebDisplay($febRow));
            if (strcasecmp($linkedFebCode, trim((string) ($display['code_autorisation_feb'] ?? ''))) === 0) {
                $authorized = true;
                break;
            }
        }
    }

    if (!$authorized) {
        http_response_code(403);
        require_once __DIR__ . '/../acces_refuse.php';
        exit;
    }
}

$todayYmd = date('Y-m-d');
$status = decaissementStatus($fiche, $todayYmd);
$dateReport = (string) ($status['date_report'] ?? '');

$pageTitle = 'Détails décaissement';
$layoutRootBase = '../';
$activeEmployeeMenu = 'decaissement';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(240,249,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="mx-auto max-w-[1400px] px-4 py-6 2xl:px-8">
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-600 via-slate-900 to-emerald-500"></div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Décaissement</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">Fiche <?= htmlspecialchars((string) ($fiche['num_fiche'] ?? '')) ?></h1>
                    <div class="mt-3">
                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= htmlspecialchars((string) ($status['class'] ?? 'bg-slate-100 text-slate-700 border-slate-200')) ?>">
                            <?= htmlspecialchars((string) ($status['label'] ?? '—')) ?>
                        </span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="mes_decaissements_employe.php" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">
                        <i class="fas fa-arrow-left mr-2 text-xs text-slate-500"></i>
                        Retour
                    </a>
                    <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>request/export_fiche_decaissement_pdf_employe.php?num_fiche=<?= rawurlencode((string) ($fiche['num_fiche'] ?? '')) ?>" target="_blank" rel="noopener" class="inline-flex items-center rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        <i class="fas fa-file-pdf mr-2"></i>
                        Export PDF
                    </a>
                    <?php if ((int)($fiche['decaisse'] ?? 0) === 1): ?>
                    <a href="../decaissement/regularisation_decaissement.php?num_fiche=<?= rawurlencode((string)$fiche['num_fiche']) ?>" class="inline-flex items-center rounded-2xl bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600">
                        <i class="fas fa-file-signature mr-2"></i>PV et preuve de paiement
                    </a>
                    <?php endif ?>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4 lg:col-span-2">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Informations</div>

                    <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                            <div class="text-xs font-semibold text-slate-500">Bénéficiaire</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($fiche['beficiaire_fiche'] ?? '—')) ?></div>
                            <?php if (!empty($fiche['tel_beneficiaire_fiche'])): ?>
                                <div class="mt-1 text-xs text-slate-600"><?= htmlspecialchars((string) $fiche['tel_beneficiaire_fiche']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                            <div class="text-xs font-semibold text-slate-500">Montant</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars(format_fcfa((float) ($fiche['montant_fiche'] ?? 0))) ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                            <div class="text-xs font-semibold text-slate-500">Chantier</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) (($fiche['chantier_code'] ?? '') ?: '—')) ?></div>
                            <?php if (!empty($fiche['chantier_titre'])): ?>
                                <div class="mt-1 text-xs text-slate-600"><?= htmlspecialchars((string) $fiche['chantier_titre']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50/60 px-4 py-3">
                            <div class="text-xs font-semibold text-slate-500">Créée le</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars(employeeFormatDateFr((string) ($fiche['date_creat_fiche'] ?? ''))) ?></div>
                            <?php if ($dateReport): ?>
                                <div class="mt-1 text-xs text-amber-700"><span class="font-semibold">Date report :</span> <?= htmlspecialchars(employeeFormatDateFr($dateReport)) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Désignation</div>
                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($fiche['designation_fiche'] ?? ($fiche['ligne_designation'] ?? '—'))) ?></div>
                        <?php if (!empty($fiche['precision_fiche']) || !empty($fiche['ligne_categorie'])): ?>
                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Précision :</span> <?= htmlspecialchars((string) (($fiche['precision_fiche'] ?? '') ?: ($fiche['ligne_categorie'] ?? '—'))) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($fiche['details_demande'])): ?>
                            <div class="mt-2 text-xs text-slate-600"><span class="font-semibold">Détails :</span> <?= htmlspecialchars((string) $fiche['details_demande']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Paiement</div>

                    <div class="mt-4 space-y-3">
                        <div>
                            <div class="text-xs font-semibold text-slate-500">Mode</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) (($fiche['mode_paiement'] ?? '') ?: '—')) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-slate-500">N° pièce</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) (($fiche['num_piece'] ?? '') ?: '—')) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-slate-500">Entreprise / Chantier</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) (($fiche['entreprise'] ?? '') ?: ($fiche['chantier_entreprise'] ?? '—'))) ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-slate-500">Code autorisation FEB</div>
                            <div class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) (($fiche['code_autorisation_feb'] ?? '') ?: '—')) ?></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
