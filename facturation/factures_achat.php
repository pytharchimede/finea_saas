<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FacturationController.php';
require_once __DIR__ . '/../model/FnePurchaseInvoiceImportManager.php';

$pageTitle = 'Factures d\'achat';
$activeMenu = 'facturation';

$pdo = Database::getConnection();
$controller = new FacturationController($pdo);
$factureModel = $controller->achats();
$fnePurchaseManager=new FnePurchaseInvoiceImportManager($pdo);
$taxRates = require __DIR__ . '/../config/taxes.php';

$auditFactureAchat = static function (string $event, array $payload) use ($pdo): void {
    \AuditTrail::log($event, $payload, $pdo);
};

$flashSuccess = '';
$flashError = '';
if (!empty($_SESSION['facturation_flash_success'])) {
    $flashSuccess = (string)$_SESSION['facturation_flash_success'];
    unset($_SESSION['facturation_flash_success']);
}
if (!empty($_SESSION['facturation_flash_error'])) {
    $flashError = (string)$_SESSION['facturation_flash_error'];
    unset($_SESSION['facturation_flash_error']);
}

function renderFactureBadge(string $label, string $classes): string
{
    $base = 'inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold whitespace-nowrap';
    return '<span class="' . $base . ' ' . $classes . '">' . htmlspecialchars($label) . '</span>';
}

function renderAchatStatutBadge(string $statut): string
{
    $statut = trim($statut);
    switch ($statut) {
        case 'brouillon':
            return renderFactureBadge('Brouillon', 'border-slate-200 bg-slate-50 text-slate-700');
        case 'a_verifier':
            return renderFactureBadge('À vérifier', 'border-amber-200 bg-amber-50 text-amber-900');
        case 'verifiee':
            return renderFactureBadge('Vérifiée', 'border-emerald-200 bg-emerald-50 text-emerald-900');
        case 'rejetee':
            return renderFactureBadge('Rejetée', 'border-rose-200 bg-rose-50 text-rose-900');
        case 'payee':
            return renderFactureBadge('Payée', 'border-indigo-200 bg-indigo-50 text-indigo-900');
        default:
            return renderFactureBadge($statut !== '' ? $statut : '—', 'border-slate-200 bg-white text-slate-700');
    }
}

function renderAchatFneBadge($okValue): string
{
    if ($okValue === null || $okValue === '') {
        return renderFactureBadge('—', 'border-slate-200 bg-white text-slate-500');
    }
    return !empty($okValue)
        ? renderFactureBadge('OK', 'border-emerald-200 bg-emerald-50 text-emerald-900')
        : renderFactureBadge('KO', 'border-rose-200 bg-rose-50 text-rose-900');
}

function ensureUploadDir(string $absDir): bool
{
    if (is_dir($absDir)) {
        return true;
    }
    return @mkdir($absDir, 0775, true);
}

function safeOriginalName(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'piece';
    }
    $name = str_replace(["\0", "\r", "\n"], '', $name);
    $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name);
    $name = trim((string)$name, '._-');
    return $name !== '' ? $name : 'piece';
}

function handleUpload(array $file, int $idFacture): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'uploaded' => false];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload invalide.'];
    }

    $maxSize = 10 * 1024 * 1024;
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxSize) {
        return ['ok' => false, 'error' => 'Fichier trop volumineux (max 10MB).'];
    }

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Fichier temporaire invalide.'];
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $detected = @finfo_file($fi, $tmp);
            @finfo_close($fi);
            if (is_string($detected)) {
                $mime = trim($detected);
            }
        }
    }
    if ($mime === '') {
        $mime = trim((string)($file['type'] ?? ''));
    }
    $allowed = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    if ($mime !== '' && !in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Type de fichier non autorisé (PDF ou image).'];
    }

    $uploadDirRel = 'uploads/factures_achat';
    $uploadDirAbs = realpath(__DIR__ . '/../uploads') ?: (__DIR__ . '/../uploads');
    $targetAbsDir = $uploadDirAbs . DIRECTORY_SEPARATOR . 'factures_achat';

    if (!ensureUploadDir($targetAbsDir)) {
        return ['ok' => false, 'error' => 'Impossible de créer le dossier d\'upload.'];
    }

    $origName = safeOriginalName((string)($file['name'] ?? 'piece'));
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    $ext = $ext ? strtolower($ext) : '';
    if ($ext === '' && $mime === 'application/pdf') {
        $ext = 'pdf';
    }

    try {
        $rand = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        $rand = (string)mt_rand(100000, 999999);
    }

    $filename = 'FA-' . $idFacture . '-' . date('YmdHis') . '-' . $rand . ($ext !== '' ? ('.' . $ext) : '');
    $targetAbs = $targetAbsDir . DIRECTORY_SEPARATOR . $filename;

    if (!@move_uploaded_file($tmp, $targetAbs)) {
        return ['ok' => false, 'error' => 'Impossible d\'enregistrer le fichier.'];
    }

    return [
        'ok' => true,
        'uploaded' => true,
        'meta' => [
            'file_path' => $uploadDirRel . '/' . $filename,
            'file_original_name' => (string)($file['name'] ?? $origName),
            'file_mime' => $mime !== '' ? $mime : null,
            'file_size' => $size,
        ],
    ];
}

function parseItemsFromPostAllowEmpty(array $post): array
{
    $descriptions = $post['items_description'] ?? null;
    $quantities = $post['items_quantity'] ?? null;
    $amounts = $post['items_amount'] ?? null;
    $taxes = $post['items_tax'] ?? null;
    $taxRatesInput = $post['items_tax_rate'] ?? null;

    // Si le formulaire n'envoie pas d'items (compat), on n'impose rien.
    if (!is_array($descriptions) || !is_array($quantities) || !is_array($amounts) || !is_array($taxes) || !is_array($taxRatesInput)) {
        return ['ok' => true, 'items' => [], 'json' => null];
    }

    $items = [];
    $max = max(count($descriptions), count($quantities), count($amounts), count($taxes));
    for ($i = 0; $i < $max; $i++) {
        $desc = trim((string)($descriptions[$i] ?? ''));
        $qtyRaw = (string)($quantities[$i] ?? '');
        $amountRaw = (string)($amounts[$i] ?? '');
        $tax = trim((string)($taxes[$i] ?? ''));
        $taxRateRaw = str_replace(',', '.', trim((string)($taxRatesInput[$i] ?? '')));

        // ignorer les lignes totalement vides
        if ($desc === '' && trim($qtyRaw) === '' && trim($amountRaw) === '') {
            continue;
        }

        $qtyRaw = str_replace(',', '.', $qtyRaw);
        $amountRaw = str_replace(',', '.', $amountRaw);
        $qty = is_numeric($qtyRaw) ? (float)$qtyRaw : 0.0;
        $amount = is_numeric($amountRaw) ? (float)$amountRaw : -1.0;

        if ($desc === '') {
            return ['ok' => false, 'error' => 'Chaque ligne d\'item doit avoir une description.'];
        }
        if (trim($qtyRaw) === '' || $qty <= 0) {
            return ['ok' => false, 'error' => 'La quantité doit être > 0 sur chaque ligne remplie.'];
        }
        if (trim($amountRaw) === '' || $amount < 0) {
            return ['ok' => false, 'error' => 'Le prix unitaire doit être >= 0 sur chaque ligne remplie.'];
        }
        if ($tax === '') {
            return ['ok' => false, 'error' => 'La taxe est obligatoire sur chaque ligne remplie.'];
        }
        if ($taxRateRaw === '' || !is_numeric($taxRateRaw)) {
            return ['ok' => false, 'error' => 'Le taux de taxe doit etre numerique sur chaque ligne remplie.'];
        }
        $taxRate = (float)$taxRateRaw;
        if ($taxRate < 0 || $taxRate > 1) {
            return ['ok' => false, 'error' => 'Le taux de taxe doit etre compris entre 0 et 1.'];
        }

        $items[] = [
            'description' => $desc,
            'quantity' => $qty,
            'amount' => round($amount, 2),
            'taxes' => [$tax],
            'tax_rate' => round($taxRate, 6),
        ];
    }

    if (!$items) {
        return ['ok' => true, 'items' => [], 'json' => null];
    }

    return [
        'ok' => true,
        'items' => $items,
        'json' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function computeTotalsFromItems(array $items, array $taxRates): array
{
    $totalHT = 0.0;
    $totalTax = 0.0;

    foreach ($items as $it) {
        $qty = (float)($it['quantity'] ?? 0);
        $amount = (float)($it['amount'] ?? 0);
        if ($qty <= 0) {
            continue;
        }

        $lineHT = round($qty * $amount, 2);

        if (isset($it['tax_rate']) && is_numeric($it['tax_rate'])) {
            $rate = (float)$it['tax_rate'];
        } else {
            $rate = 0.0;
            $taxes = $it['taxes'] ?? [];
            if (is_array($taxes)) {
            foreach ($taxes as $taxCode) {
                $taxCode = trim((string)$taxCode);
                if ($taxCode === '') {
                    continue;
                }
                if (array_key_exists($taxCode, $taxRates)) {
                    $rate += (float)$taxRates[$taxCode];
                }
            }
            }
        }

        $lineTax = round($lineHT * $rate, 2);
        $totalHT = round($totalHT + $lineHT, 2);
        $totalTax = round($totalTax + $lineTax, 2);
    }

    return [
        'ht' => $totalHT,
        'tax' => $totalTax,
        'ttc' => round($totalHT + $totalTax, 2),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$factureModel->isReady()) {
        $auditFactureAchat('facture_achat_create_failed', [
            'module' => 'facturation',
            'action' => 'create_facture_achat',
            'entity_type' => 'facture_achat',
            'entity_label' => 'Nouvelle facture achat',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'module_not_ready',
                'init_error' => $factureModel->getInitError() ?: null,
            ],
        ]);
        $_SESSION['facturation_flash_error'] = $factureModel->getInitError() ?: 'Module indisponible.';
        header('Location: factures_achat.php');
        exit;
    }

    $itemsParsed = parseItemsFromPostAllowEmpty($_POST);
    if (empty($itemsParsed['ok'])) {
        $auditFactureAchat('facture_achat_create_failed', [
            'module' => 'facturation',
            'action' => 'create_facture_achat',
            'entity_type' => 'facture_achat',
            'entity_label' => 'Nouvelle facture achat',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'invalid_items',
                'error_message' => $itemsParsed['error'] ?? null,
            ],
        ]);
        $_SESSION['facturation_flash_error'] = (string)($itemsParsed['error'] ?? 'Items invalides.');
        header('Location: factures_achat.php');
        exit;
    }

    if (!empty($itemsParsed['items'])) {
        $totals = computeTotalsFromItems((array)$itemsParsed['items'], (array)$taxRates);
        $_POST['amount_total'] = (string)$totals['ttc'];
    }

    $itemsJson = $itemsParsed['json'] ?? null;

    $selectedFneImportId=(int)($_POST['fne_import_id']??0);
    if($selectedFneImportId>0){
        try{$selectedFne=$fnePurchaseManager->getSelectable($selectedFneImportId);$_POST['fne_token']=$selectedFne['token']?:null;$_POST['invoice_number']=$selectedFne['fne_reference']?:null;$_POST['total_ht']=$selectedFne['total_ht'];$_POST['total_vat']=$selectedFne['total_vat'];$_POST['payment_method']=$selectedFne['payment_method'];}
        catch(Throwable $e){$_SESSION['facturation_flash_error']=$e->getMessage();header('Location: factures_achat.php');exit;}
    }
    $res = $factureModel->create($_POST, (int)($_SESSION['user_id'] ?? 0), is_string($itemsJson) ? $itemsJson : null);
    if (empty($res['ok'])) {
        $auditFactureAchat('facture_achat_create_failed', [
            'module' => 'facturation',
            'action' => 'create_facture_achat',
            'entity_type' => 'facture_achat',
            'entity_label' => 'Nouvelle facture achat',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'model_create_failed',
                'error_message' => $res['error'] ?? null,
            ],
        ]);
        $_SESSION['facturation_flash_error'] = (string)($res['error'] ?? 'Impossible de créer la facture.');
        header('Location: factures_achat.php');
        exit;
    }

    $idFacture = (int)($res['id'] ?? 0);
    if($selectedFneImportId>0&&$idFacture>0){try{$fnePurchaseManager->linkToFinea($selectedFneImportId,$idFacture);}catch(Throwable $e){$_SESSION['facturation_flash_error']='Facture créée, mais rapprochement FNE à contrôler : '.$e->getMessage();}}
    $auditFactureAchat('facture_achat_created', [
        'module' => 'facturation',
        'action' => 'create_facture_achat',
        'entity_type' => 'facture_achat',
        'entity_id' => $idFacture > 0 ? (string)$idFacture : null,
        'entity_label' => (string)($res['reference_interne'] ?? 'Nouvelle facture achat'),
        'result' => 'success',
        'details' => [
            'item_count' => count((array)($itemsParsed['items'] ?? [])),
        ],
    ]);

    if ($idFacture > 0 && isset($_FILES['piece'])) {
        $up = handleUpload($_FILES['piece'], $idFacture);
        if (empty($up['ok'])) {
            $auditFactureAchat('facture_achat_attachment_upload_failed', [
                'module' => 'facturation',
                'action' => 'create_facture_achat',
                'entity_type' => 'facture_achat',
                'entity_id' => (string)$idFacture,
                'entity_label' => (string)($res['reference_interne'] ?? 'Nouvelle facture achat'),
                'result' => 'failed',
                'severity' => 'warning',
                'details' => [
                    'error_message' => $up['error'] ?? null,
                ],
            ]);
            $_SESSION['facturation_flash_error'] = (string)($up['error'] ?? 'Upload impossible.');
            header('Location: edit_facture_achat.php?id=' . $idFacture);
            exit;
        }
        if (!empty($up['uploaded']) && !empty($up['meta'])) {
            $factureModel->attachFile($idFacture, $up['meta']);
            \AuditTrail::logFileEvent('upload', [
                'module' => 'facturation',
                'action' => 'facture_achat_attachment_upload',
                'entity_type' => 'facture_achat',
                'entity_id' => (string)$idFacture,
                'entity_label' => (string)($res['reference_interne'] ?? 'Nouvelle facture achat'),
                'format' => pathinfo((string)($_FILES['piece']['name'] ?? ''), PATHINFO_EXTENSION),
                'details' => [
                    'file_name' => $_FILES['piece']['name'] ?? null,
                ],
            ], $pdo);
        }
    }

    $ref = trim((string)($res['reference_interne'] ?? ''));
    $_SESSION['facturation_flash_success'] = $ref !== ''
        ? ('Facture d\'achat créée (Réf: ' . $ref . ').')
        : 'Facture d\'achat créée.';
    header('Location: factures_achat.php');
    exit;
}

$achatView = $controller->achatListView(200);
$factures = $achatView['factures'];
$fournisseurs = $achatView['fournisseurs'];
$chantiers=$pdo->query("SELECT id,code,titre FROM chantier_banamur WHERE archived_at IS NULL ORDER BY code,titre")->fetchAll(PDO::FETCH_ASSOC)?:[];

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<link rel="stylesheet" href="../assets/css/billing-workspace.css?v=20261126a">
<div class="billing-workspace min-h-screen pb-12">
    <div class="container mx-auto py-8 px-4">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-6">
            <div>
                <div class="text-xs font-medium text-gray-500">Facturation • Achats</div>
                <h1 class="mt-1 text-2xl md:text-3xl font-semibold text-gray-900">Factures d'achat</h1>
                <p class="mt-2 text-sm text-gray-600">Collecte + stockage des pièces + vérification FNE (invoiceNumber/verificationCode).</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="dashboard_facturation.php" class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">Dashboard</a>
                <a href="../selection_portail.php" class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">Portail</a>
            </div>
        </div>

        <?php if ($flashSuccess): ?>
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                <?= htmlspecialchars($flashSuccess) ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($flashError) ?>
            </div>
        <?php endif; ?>

        <?php if (!$factureModel->isReady()): ?>
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <?= htmlspecialchars($factureModel->getInitError() ?: 'Le module factures d\'achat n\'a pas pu initialiser ses tables.') ?>
            </div>
        <?php endif; ?>

        <div id="nouvelle-facture-achat" class="bg-white border border-gray-200 rounded-2xl p-6 mb-6 scroll-mt-6">
            <h2 class="text-lg font-semibold text-gray-900">Nouvelle facture d'achat</h2>
            <div class="mt-2 text-xs text-gray-500">Les champs marqués * sont obligatoires.</div>
            <form method="post" enctype="multipart/form-data" class="mt-4 space-y-5">
                <input type="hidden" name="fne_import_id">
                <input type="hidden" name="fne_token">
                <input type="hidden" name="total_ht">
                <input type="hidden" name="total_vat">
                <input type="hidden" name="payment_method">
                <div class="relative rounded-2xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-indigo-50 p-4">
                    <div class="flex flex-wrap items-end gap-3"><label class="min-w-64 flex-1 text-sm font-bold text-slate-800">Préremplir depuis les achats FNE importés<input id="fnePurchaseSearch" type="search" autocomplete="off" placeholder="Fournisseur, NCC, référence, Token, montant ou date…" class="mt-2 w-full rounded-xl border border-emerald-300 bg-white px-4 py-3"></label><a href="import_factures_achat_fne.php" class="rounded-xl bg-slate-950 px-4 py-3 text-xs font-bold text-white"><i class="fas fa-file-import mr-2"></i>Importer un lot</a></div>
                    <div id="fnePurchaseResults" class="absolute left-4 right-4 z-50 mt-2 hidden max-h-80 overflow-auto rounded-2xl border bg-white p-2 shadow-2xl"></div>
                    <p id="fnePurchaseStatus" class="mt-2 text-xs text-slate-600">Recherche universelle dans toutes les colonnes du récapitulatif.</p>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900">Références</h3>
                        <div class="text-xs text-gray-500">Fournisseur + référence interne</div>
                    </div>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Fournisseur *</label>
                            <select name="fournisseur_id" required data-finea-search data-placeholder="Rechercher un fournisseur…" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="">— Choisir —</option>
                                <?php foreach ($fournisseurs as $f): ?>
                                    <option value="<?= (int)$f['id_fournisseur'] ?>"><?= htmlspecialchars((string)$f['raison_sociale']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="mt-1 text-xs text-gray-500">Si la liste est vide : créer un fournisseur d'abord.</div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Chantier concerné</label>
                            <select name="chantier_id" data-finea-search data-placeholder="Rechercher un chantier…" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2"><option value="">Non rattachée à un chantier</option><?php foreach($chantiers as$c):?><option value="<?=(int)$c['id']?>"><?=htmlspecialchars(trim((string)$c['code'].' · '.(string)$c['titre']))?></option><?php endforeach?></select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Référence interne</label>
                            <input name="reference_interne" readonly placeholder="Auto (ex: FA-YYYYMM-000001)" class="mt-1 w-full rounded-xl border border-gray-200 bg-gray-100 px-3 py-2 text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            <div class="mt-1 text-xs text-gray-500">Générée automatiquement à la création.</div>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900">Identifiants FNE</h3>
                        <div class="text-xs text-gray-500">InvoiceNumber / VerificationCode</div>
                    </div>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Invoice Number (FNE)</label>
                            <input name="invoice_number" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Verification Code (FNE)</label>
                            <input name="verification_code" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900">Montant</h3>
                        <div class="text-xs text-gray-500">Date + total</div>
                    </div>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date facture</label>
                            <input name="invoice_date" type="date" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Montant total *</label>
                            <input id="amountTotalInput" name="amount_total" type="number" step="0.01" required class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            <div class="mt-1 text-xs text-gray-500">Si des items sont renseignés, le total est calculé automatiquement (TTC).</div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Devise</label>
                            <input name="devise" value="FCFA" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Statut</label>
                            <select name="statut" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="a_verifier" selected>À vérifier</option>
                                <option value="brouillon">Brouillon</option>
                                <option value="verifiee">Vérifiée</option>
                                <option value="rejetee">Rejetée</option>
                                <option value="payee">Payée</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900">Items</h3>
                        <div class="text-xs text-gray-500">Optionnel</div>
                    </div>
                    <div class="mt-4">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="text-left text-gray-500 border-b">
                                        <th class="py-2 pr-3">Description</th>
                                        <th class="py-2 pr-3 w-28">Qté</th>
                                        <th class="py-2 pr-3 w-36">Prix</th>
                                        <th class="py-2 pr-3 w-36">Taxe</th>
                                        <th class="py-2 pr-3 w-40 text-right">Total</th>
                                        <th class="py-2 w-12"></th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTbody" class="divide-y">
                                    <tr>
                                        <td class="py-2 pr-3">
                                            <input name="items_description[]" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Ex: Achat matériel" />
                                        </td>
                                        <td class="py-2 pr-3">
                                            <input name="items_quantity[]" type="number" step="0.01" min="0" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                        </td>
                                        <td class="py-2 pr-3">
                                            <input name="items_amount[]" type="number" step="0.01" min="0" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                        </td>
                                        <td class="py-2 pr-3">
                                            <select name="items_tax[]" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                                <option value="TVA" selected>TVA</option>
                                                <option value="TVAB">TVAB</option>
                                                <option value="TVAC">TVAC</option>
                                                <option value="TVAD">TVAD</option>
                                            </select>
                                            <input name="items_tax_rate[]" type="number" step="0.000001" min="0" max="1" value="<?= htmlspecialchars((string)($taxRates['TVA'] ?? 0.18), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-200" title="Taux taxe modifiable" />
                                        </td>
                                        <td class="py-2 pr-3 text-right">
                                            <div class="font-medium text-gray-900 jsLineTotal">—</div>
                                            <div class="text-xs text-gray-500 jsLineBreakdown">HT — • Taxe —</div>
                                        </td>
                                        <td class="py-2">
                                            <button type="button" class="jsRemoveItemRow px-3 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50" title="Supprimer">×</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-3">
                            <div class="text-xs text-gray-500">Si vous ne souhaitez pas détailler, laissez vide.</div>
                            <button id="addItemRowBtn" type="button" class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">+ Ajouter une ligne</button>
                        </div>

                        <div class="mt-4 flex justify-end">
                            <div class="w-full md:w-96 rounded-2xl border border-gray-200 bg-white p-4 text-sm">
                                <div class="flex items-center justify-between">
                                    <div class="text-gray-600">Sous-total HT</div>
                                    <div class="font-semibold text-gray-900" id="itemsSubtotalHT">0,00</div>
                                </div>
                                <div class="mt-2 flex items-center justify-between">
                                    <div class="text-gray-600">Total taxes</div>
                                    <div class="font-semibold text-gray-900" id="itemsTotalTax">0,00</div>
                                </div>
                                <div class="mt-2 flex items-center justify-between border-t border-gray-200 pt-2">
                                    <div class="text-gray-700">Total TTC</div>
                                    <div class="font-semibold text-gray-900" id="itemsTotalTTC">0,00</div>
                                </div>
                                <div class="mt-2 text-xs text-gray-500">Taux paramétrés via <code>config/taxes.php</code>.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900">Pièce jointe <span class="text-xs font-normal text-gray-500">(facultative)</span></h3>
                        <div class="text-xs text-gray-500">PDF / image</div>
                    </div>
                    <div class="mt-4">
                        <label class="block text-sm font-medium text-gray-700">Fichier</label>
                        <input type="file" name="piece" accept="application/pdf,image/*" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2" />
                        <div class="mt-1 text-xs text-gray-500">Max 10MB. Le lien de téléchargement est protégé par accès.</div>
                    </div>
                </div>

                <div class="flex items-center justify-end rounded-2xl border border-gray-200 bg-white p-4">
                    <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700">Créer</button>
                </div>
            </form>
        </div>

        <div class="bg-white border border-gray-200 rounded-2xl p-6">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">Dernières factures d'achat</h2>
                <div class="text-xs text-gray-500"><?= count($factures) ?> affichées</div>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="py-2 pr-4">Fournisseur</th>
                            <th class="py-2 pr-4">Réf interne</th>
                            <th class="py-2 pr-4">Montant</th>
                            <th class="py-2 pr-4">Invoice / Code</th>
                            <th class="py-2 pr-4">Statut</th>
                            <th class="py-2 pr-4">FNE</th>
                            <th class="py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php if (!$factures): ?>
                            <tr>
                                <td colspan="7" class="py-6 text-center text-gray-500">Aucune facture (ou table indisponible).</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($factures as $fa): ?>
                            <tr class="text-gray-800">
                                <td class="py-2 pr-4 font-medium"><?= htmlspecialchars((string)($fa['fournisseur_raison_sociale'] ?? '')) ?></td>
                                <td class="py-2 pr-4 text-gray-600"><?= htmlspecialchars((string)($fa['reference_interne'] ?? '—')) ?></td>
                                <td class="py-2 pr-4 text-gray-600"><?= htmlspecialchars((string)$fa['amount_total']) ?> <?= htmlspecialchars((string)$fa['devise']) ?></td>
                                <td class="py-2 pr-4 text-gray-600">
                                    <div class="text-xs">#<?= htmlspecialchars((string)($fa['invoice_number'] ?? '')) ?></div>
                                    <div class="text-xs">Code: <?= htmlspecialchars((string)($fa['verification_code'] ?? '')) ?></div>
                                </td>
                                <td class="py-2 pr-4"><?= renderAchatStatutBadge((string)($fa['statut'] ?? '')) ?></td>
                                <td class="py-2 pr-4 text-gray-600">
                                    <?php if (!empty($fa['fne_last_verify_at'])): ?>
                                        <div class="text-xs"><?= htmlspecialchars((string)$fa['fne_last_verify_at']) ?></div>
                                        <div class="mt-1"><?= renderAchatFneBadge($fa['fne_last_verify_ok'] ?? null) ?></div>
                                    <?php else: ?>
                                        <div class="text-xs text-gray-400">—</div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2">
                                    <a class="text-indigo-700 hover:underline" href="edit_facture_achat.php?id=<?= (int)$fa['id_facture_achat'] ?>">Ouvrir</a>
                                    <?php if (!empty($fa['file_path'])): ?>
                                        <span class="text-gray-300">•</span>
                                        <a class="text-gray-700 hover:underline" href="download_facture_achat.php?id=<?= (int)$fa['id_facture_achat'] ?>">Pièce</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
    (function(){
        const input=document.getElementById('fnePurchaseSearch'),results=document.getElementById('fnePurchaseResults'),status=document.getElementById('fnePurchaseStatus'),form=input?.closest('form');
        if(!input||!results||!status||!form)return;let timer=0,controller=null;
        const set=(name,value)=>{const field=form.querySelector(`[name="${name}"]`);if(field&&value!==null&&value!==undefined&&String(value)!==''){field.value=String(value);field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}))}};
        const choose=row=>{
            if(row.finea_invoice_id)return;
            set('fne_import_id',row.id);set('fne_token',row.token);set('invoice_number',row.fne_reference);set('verification_code',row.fne_reference);
            set('invoice_date',String(row.invoice_date||'').slice(0,10));set('amount_total',row.net_payable||row.total_ttc);
            set('total_ht',row.total_ht);set('total_vat',row.total_vat);set('payment_method',row.payment_method);
            set('devise',row.foreign_currency||'FCFA');
            const supplier=form.querySelector('[name="fournisseur_id"]');
            if(supplier){
                const candidates=[row.client_name,row.establishment].filter(Boolean).map(v=>String(v).trim().toLocaleLowerCase('fr'));
                const option=[...supplier.options].find(o=>candidates.includes(o.textContent.trim().toLocaleLowerCase('fr')));
                if(option){supplier.value=option.value;supplier.dispatchEvent(new Event('change',{bubbles:true}))}
            }
            const line=document.getElementById('itemsTbody')?.querySelector('tr'),ht=Number(row.total_ht||0),vat=Number(row.total_vat||0);
            if(line){
                const values={'items_description[]':[row.invoice_subtype||row.invoice_type,'FNE '+(row.fne_reference||''),row.client_name||row.establishment].filter(Boolean).join(' · '),'items_quantity[]':1,'items_amount[]':ht,'items_tax[]':'TVA','items_tax_rate[]':ht>0?vat/ht:0};
                Object.entries(values).forEach(([name,value])=>{const field=line.querySelector(`[name="${name}"]`);if(field){field.value=String(value);field.dispatchEvent(new Event('input',{bubbles:true}));field.dispatchEvent(new Event('change',{bubbles:true}))}});
            }
            input.value=[row.fne_reference,row.client_name||row.establishment].filter(Boolean).join(' — ');results.classList.add('hidden');status.className='mt-2 text-xs font-bold text-emerald-700';status.textContent=`Achat FNE sélectionné · TVA ${vat>0?'applicable ('+vat.toLocaleString('fr-FR')+' FCFA)':'non applicable'} · contrôlez le fournisseur, le chantier et le statut payé/non payé.`;
        };
        const render=items=>{results.innerHTML='';if(!items.length)results.innerHTML='<div class="p-4 text-sm text-slate-500">Aucune facture d’achat FNE trouvée.</div>';items.forEach(row=>{const button=document.createElement('button');button.type='button';button.disabled=!!row.finea_invoice_id;button.className='mb-1 flex w-full justify-between gap-3 rounded-xl p-3 text-left hover:bg-emerald-50 disabled:opacity-50';const left=document.createElement('span'),title=document.createElement('b'),sub=document.createElement('small'),right=document.createElement('span');title.className='block text-sm';sub.className='block text-xs text-slate-500';right.className='text-right text-sm font-black text-emerald-700';title.textContent=row.client_name||row.establishment||'Fournisseur à identifier';sub.textContent=[row.client_ncc,row.fne_reference,String(row.invoice_date||'').slice(0,10),row.finea_invoice_id?'Déjà liée à '+row.finea_reference:''].filter(Boolean).join(' · ');right.textContent=Number(row.net_payable||0).toLocaleString('fr-FR')+' F';left.append(title,sub);button.append(left,right);button.onclick=()=>choose(row);results.append(button)});results.classList.remove('hidden')};
        input.addEventListener('input',()=>{clearTimeout(timer);controller?.abort();const q=input.value.trim();if(q.length<2){results.classList.add('hidden');return}timer=setTimeout(async()=>{controller=new AbortController();status.textContent='Recherche dans les achats FNE…';try{const response=await fetch('api/fne_purchase_import_search.php?q='+encodeURIComponent(q),{signal:controller.signal});const json=await response.json();if(!json.ok)throw Error(json.error);render(json.items||[]);status.textContent=(json.items||[]).length+' résultat(s) · les lignes liées sont protégées.'}catch(e){if(e.name!=='AbortError')status.textContent=e.message}},250)});
        document.addEventListener('click',e=>{if(e.target!==input&&!results.contains(e.target))results.classList.add('hidden')});
    })();
    (function() {
        const taxRates = <?php echo json_encode($taxRates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const tbody = document.getElementById('itemsTbody');
        const addBtn = document.getElementById('addItemRowBtn');
        const amountTotalInput = document.getElementById('amountTotalInput');
        const subtotalEl = document.getElementById('itemsSubtotalHT');
        const totalTaxEl = document.getElementById('itemsTotalTax');
        const totalTtcEl = document.getElementById('itemsTotalTTC');
        if (!tbody || !addBtn) {
            return;
        }

        const nf = new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

        function toNumber(v) {
            if (v === null || v === undefined) return 0;
            const s = String(v).replace(',', '.').trim();
            const n = parseFloat(s);
            return Number.isFinite(n) ? n : 0;
        }

        function round2(n) {
            return Math.round((n + Number.EPSILON) * 100) / 100;
        }

        function fmt(n) {
            return nf.format(round2(n));
        }

        function recalcTotals() {
            let totalHT = 0;
            let totalTax = 0;
            let hasAnyLine = false;

            tbody.querySelectorAll('tr').forEach(function(tr) {
                const desc = tr.querySelector('input[name="items_description[]"]');
                const qtyInput = tr.querySelector('input[name="items_quantity[]"]');
                const amountInput = tr.querySelector('input[name="items_amount[]"]');
                const taxSelect = tr.querySelector('select[name="items_tax[]"]');
                const taxRateInput = tr.querySelector('input[name="items_tax_rate[]"]');
                const lineTotalEl = tr.querySelector('.jsLineTotal');
                const lineBreakEl = tr.querySelector('.jsLineBreakdown');

                const descVal = desc ? String(desc.value || '').trim() : '';
                const qtyRaw = qtyInput ? String(qtyInput.value || '').trim() : '';
                const amountRaw = amountInput ? String(amountInput.value || '').trim() : '';

                if (descVal === '' && qtyRaw === '' && amountRaw === '') {
                    if (lineTotalEl) lineTotalEl.textContent = '—';
                    if (lineBreakEl) lineBreakEl.textContent = 'HT — • Taxe —';
                    return;
                }

                if (descVal !== '' && qtyRaw !== '' && amountRaw !== '') {
                    hasAnyLine = true;
                }
                const qty = toNumber(qtyRaw);
                const amount = toNumber(amountRaw);
                const taxCode = taxSelect ? String(taxSelect.value || '').trim() : '';
                const rate = taxRateInput ? toNumber(taxRateInput.value || 0) : ((taxCode && Object.prototype.hasOwnProperty.call(taxRates, taxCode)) ? toNumber(taxRates[taxCode]) : 0);

                const lineHT = round2(qty * amount);
                const lineTax = round2(lineHT * rate);
                const lineTTC = round2(lineHT + lineTax);

                totalHT = round2(totalHT + lineHT);
                totalTax = round2(totalTax + lineTax);

                if (lineTotalEl) lineTotalEl.textContent = fmt(lineTTC);
                if (lineBreakEl) lineBreakEl.textContent = 'HT ' + fmt(lineHT) + ' • Taxe ' + fmt(lineTax);
            });

            const totalTTC = round2(totalHT + totalTax);
            if (subtotalEl) subtotalEl.textContent = fmt(totalHT);
            if (totalTaxEl) totalTaxEl.textContent = fmt(totalTax);
            if (totalTtcEl) totalTtcEl.textContent = fmt(totalTTC);

            if (amountTotalInput) {
                if (hasAnyLine) {
                    amountTotalInput.value = totalTTC.toFixed(2);
                    amountTotalInput.readOnly = true;
                    amountTotalInput.classList.add('bg-gray-100');
                } else {
                    amountTotalInput.readOnly = false;
                    amountTotalInput.classList.remove('bg-gray-100');
                }
            }
        }

        function makeRow() {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="py-2 pr-3">
                    <input name="items_description[]" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Ex: Achat matériel" />
                </td>
                <td class="py-2 pr-3">
                    <input name="items_quantity[]" type="number" step="0.01" min="0" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                </td>
                <td class="py-2 pr-3">
                    <input name="items_amount[]" type="number" step="0.01" min="0" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                </td>
                <td class="py-2 pr-3">
                    <select name="items_tax[]" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        <option value="TVA" selected>TVA</option>
                        <option value="TVAB">TVAB</option>
                        <option value="TVAC">TVAC</option>
                        <option value="TVAD">TVAD</option>
                    </select>
                    <input name="items_tax_rate[]" type="number" step="0.000001" min="0" max="1" value="${taxRates.TVA || 0}" class="mt-2 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-200" title="Taux taxe modifiable" />
                </td>
                <td class="py-2 pr-3 text-right">
                    <div class="font-medium text-gray-900 jsLineTotal">—</div>
                    <div class="text-xs text-gray-500 jsLineBreakdown">HT — • Taxe —</div>
                </td>
                <td class="py-2">
                    <button type="button" class="jsRemoveItemRow px-3 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50" title="Supprimer">×</button>
                </td>
            `;
            return tr;
        }

        addBtn.addEventListener('click', function() {
            tbody.appendChild(makeRow());
            recalcTotals();
        });

        tbody.addEventListener('input', function(e) {
            const t = e.target;
            if (!t) return;
            if (t.matches('input[name="items_description[]"], input[name="items_quantity[]"], input[name="items_amount[]"], input[name="items_tax_rate[]"]')) {
                recalcTotals();
            }
        });

        tbody.addEventListener('change', function(e) {
            const t = e.target;
            if (!t) return;
            if (t.matches('select[name="items_tax[]"]')) {
                const tr = t.closest('tr');
                const rateInput = tr ? tr.querySelector('input[name="items_tax_rate[]"]') : null;
                if (rateInput) rateInput.value = taxRates[t.value] ?? 0;
                recalcTotals();
            }
        });

        tbody.addEventListener('click', function(e) {
            const btn = e.target && e.target.closest ? e.target.closest('.jsRemoveItemRow') : null;
            if (!btn) {
                return;
            }
            const tr = btn.closest('tr');
            if (!tr) {
                return;
            }
            if (tbody.querySelectorAll('tr').length <= 1) {
                const inputs = tr.querySelectorAll('input, select');
                inputs.forEach(function(el) {
                    if (el.tagName === 'SELECT') {
                        el.value = 'TVA';
                    } else {
                        el.value = '';
                    }
                });
                recalcTotals();
                return;
            }
            tr.remove();
            recalcTotals();
        });

        recalcTotals();
    })();
</script>

<script src="assets/finea-search-combobox.js"></script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
