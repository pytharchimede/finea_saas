<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FacturationController.php';
require_once __DIR__ . '/../model/FneClient.php';
require_once __DIR__ . '/../model/FneValidator.php';
require_once __DIR__ . '/../model/FnePurchaseInvoiceImportManager.php';

$pageTitle = 'Facture d\'achat';
$activeMenu = 'facturation';

$pdo = Database::getConnection();
$controller = new FacturationController($pdo);
$factureModel = $controller->achats();
$fneImportManager=new FnePurchaseInvoiceImportManager($pdo);
$taxRates = require __DIR__ . '/../config/taxes.php';

$auditFactureAchat = static function (string $event, array $payload) use ($pdo): void {
    \AuditTrail::log($event, $payload, $pdo);
};

$id = (int)($_GET['id'] ?? 0);
$editView = $controller->editAchatView($id);
$facture = $editView['facture'];

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

function extractRemoteAmount($data): ?float
{
    if (!is_array($data)) {
        return null;
    }

    $tryKeys = ['amount', 'total', 'totalAmount', 'total_amount', 'totalTtc', 'total_ttc', 'montant', 'montantTtc', 'montant_ttc', 'invoiceAmount', 'invoiceTotal'];
    foreach ($tryKeys as $k) {
        if (isset($data[$k]) && is_numeric($data[$k])) {
            return (float)$data[$k];
        }
    }

    if (isset($data['data']) && is_array($data['data'])) {
        $v = extractRemoteAmount($data['data']);
        if ($v !== null) {
            return $v;
        }
    }

    return null;
}

function extractRemoteStatus($data): ?string
{
    if (!is_array($data)) {
        return null;
    }

    foreach (['status', 'state', 'result'] as $k) {
        if (isset($data[$k]) && is_string($data[$k])) {
            $s = trim($data[$k]);
            if ($s !== '') {
                return $s;
            }
        }
    }

    if (isset($data['valid']) && is_bool($data['valid'])) {
        return $data['valid'] ? 'VALID' : 'INVALID';
    }

    if (isset($data['data']) && is_array($data['data'])) {
        $v = extractRemoteStatus($data['data']);
        if ($v !== null) {
            return $v;
        }
    }

    return null;
}

function parseItemsFromPostAllowEmpty(array $post): array
{
    $descriptions = $post['items_description'] ?? null;
    $quantities = $post['items_quantity'] ?? null;
    $amounts = $post['items_amount'] ?? null;
    $taxes = $post['items_tax'] ?? null;
    $taxRatesInput = $post['items_tax_rate'] ?? null;

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
    if ($id <= 0 || !$facture) {
        $_SESSION['facturation_flash_error'] = 'Facture introuvable.';
        header('Location: factures_achat.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? 'save');

    if($action==='link_imported_fne'){
        try{$fneImportManager->linkToFinea((int)($_POST['fne_import_id']??0),$id);$_SESSION['facturation_flash_success']='Facture FNE rapprochée et données fiscales synchronisées.';}
        catch(Throwable$e){$_SESSION['facturation_flash_error']=$e->getMessage();}
        header('Location: edit_facture_achat.php?id='.$id);exit;
    }

    if ($action === 'verify') {
        $invoiceNumber = trim((string)($_POST['invoice_number'] ?? $facture['invoice_number'] ?? ''));
        $verificationCode = trim((string)($_POST['verification_code'] ?? $facture['verification_code'] ?? ''));

        $verifyPayload = [];
        if ($invoiceNumber !== '') {
            $verifyPayload['invoiceNumber'] = $invoiceNumber;
        }
        if ($verificationCode !== '') {
            $verifyPayload['verificationCode'] = $verificationCode;
        }

        // Optionnel: montant/date pour aider verify si supporté
        $amountTotal = $facture['amount_total'] ?? null;
        if ($amountTotal !== null && $amountTotal !== '' && is_numeric($amountTotal)) {
            $verifyPayload['amount'] = (float)$amountTotal;
        }
        $date = (string)($_POST['invoice_date'] ?? $facture['invoice_date'] ?? '');
        $date = trim($date);
        if ($date !== '') {
            $verifyPayload['date'] = $date;
        }

        $validation = FneValidator::validateVerifyPayload($verifyPayload);
        if (empty($validation['ok'])) {
            $auditFactureAchat('facture_achat_verify_failed', [
                'module' => 'facturation',
                'action' => 'edit_facture_achat_verify',
                'entity_type' => 'facture_achat',
                'entity_id' => (string)$id,
                'entity_label' => (string)($facture['invoice_number'] ?? 'Facture achat'),
                'result' => 'failed',
                'severity' => 'warning',
                'details' => [
                    'reason' => 'invalid_payload',
                ],
            ]);
            $_SESSION['facturation_flash_error'] = 'Verify: payload invalide.';
            header('Location: edit_facture_achat.php?id=' . $id);
            exit;
        }

        try {
            $client = FneClient::fromConfigFile();
            if (!$client->isConfigured()) {
                $auditFactureAchat('facture_achat_verify_failed', [
                    'module' => 'facturation',
                    'action' => 'edit_facture_achat_verify',
                    'entity_type' => 'facture_achat',
                    'entity_id' => (string)$id,
                    'entity_label' => (string)($facture['invoice_number'] ?? 'Facture achat'),
                    'result' => 'failed',
                    'severity' => 'warning',
                    'details' => [
                        'reason' => 'fne_not_configured',
                    ],
                ]);
                $_SESSION['facturation_flash_error'] = 'FNE non configuré (FNE_API_KEY/FNE_BASE_URL).';
                header('Location: edit_facture_achat.php?id=' . $id);
                exit;
            }

            $res = $client->verifyInvoice($verifyPayload);
            $remoteData = is_array($res['data'] ?? null) ? $res['data'] : null;
            $remoteAmount = $remoteData ? extractRemoteAmount($remoteData) : null;
            $remoteStatus = $remoteData ? extractRemoteStatus($remoteData) : null;

            $amountOk = null;
            if ($remoteAmount !== null && is_numeric($amountTotal)) {
                $amountOk = (abs($remoteAmount - (float)$amountTotal) <= 0.01);
            }

            $factureModel->saveVerifyResult($id, [
                'fne_last_verify_at' => date('Y-m-d H:i:s'),
                'fne_last_verify_ok' => !empty($res['ok']),
                'fne_last_verify_http_status' => (int)($res['status'] ?? 0),
                'fne_last_verify_status' => $remoteStatus,
                'fne_last_verify_amount' => $remoteAmount,
                'fne_last_verify_amount_ok' => $amountOk,
                'fne_last_verify_response_json' => json_encode($res, JSON_UNESCAPED_UNICODE),
            ]);

            $auditFactureAchat(!empty($res['ok']) ? 'facture_achat_verified' : 'facture_achat_verify_failed', [
                'module' => 'facturation',
                'action' => 'edit_facture_achat_verify',
                'entity_type' => 'facture_achat',
                'entity_id' => (string)$id,
                'entity_label' => (string)($facture['invoice_number'] ?? 'Facture achat'),
                'result' => !empty($res['ok']) ? 'success' : 'failed',
                'severity' => !empty($res['ok']) ? null : 'warning',
                'details' => [
                    'http_status' => (int)($res['status'] ?? 0),
                    'remote_status' => $remoteStatus,
                    'amount_ok' => $amountOk,
                    'error_message' => $res['error'] ?? null,
                ],
            ]);

            $_SESSION['facturation_flash_success'] = !empty($res['ok']) ? 'Vérification FNE effectuée.' : ('Vérification FNE en erreur: ' . (string)($res['error'] ?? ''));
            header('Location: edit_facture_achat.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $auditFactureAchat('facture_achat_verify_failed', [
                'module' => 'facturation',
                'action' => 'edit_facture_achat_verify',
                'entity_type' => 'facture_achat',
                'entity_id' => (string)$id,
                'entity_label' => (string)($facture['invoice_number'] ?? 'Facture achat'),
                'result' => 'failed',
                'severity' => 'warning',
                'details' => [
                    'error_message' => $e->getMessage(),
                ],
            ]);
            $_SESSION['facturation_flash_error'] = 'Erreur serveur (verify FNE).';
            header('Location: edit_facture_achat.php?id=' . $id);
            exit;
        }
    }

    // Save
    $itemsParsed = parseItemsFromPostAllowEmpty($_POST);
    if (empty($itemsParsed['ok'])) {
        $auditFactureAchat('facture_achat_update_failed', [
            'module' => 'facturation',
            'action' => 'edit_facture_achat_save',
            'entity_type' => 'facture_achat',
            'entity_id' => (string)$id,
            'entity_label' => (string)($facture['invoice_number'] ?? 'Facture achat'),
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'invalid_items',
                'error_message' => $itemsParsed['error'] ?? null,
            ],
        ]);
        $_SESSION['facturation_flash_error'] = (string)($itemsParsed['error'] ?? 'Items invalides.');
        header('Location: edit_facture_achat.php?id=' . $id);
        exit;
    }

    if (!empty($itemsParsed['items'])) {
        $totals = computeTotalsFromItems((array)$itemsParsed['items'], (array)$taxRates);
        $_POST['amount_total'] = (string)$totals['ttc'];
    }

    $itemsJson = $itemsParsed['json'] ?? null;

    $res = $factureModel->update($id, $_POST, is_string($itemsJson) ? $itemsJson : null);
    if (empty($res['ok'])) {
        $auditFactureAchat('facture_achat_update_failed', [
            'module' => 'facturation',
            'action' => 'edit_facture_achat_save',
            'entity_type' => 'facture_achat',
            'entity_id' => (string)$id,
            'entity_label' => (string)($facture['invoice_number'] ?? 'Facture achat'),
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'model_update_failed',
                'error_message' => $res['error'] ?? null,
            ],
        ]);
        $_SESSION['facturation_flash_error'] = (string)($res['error'] ?? 'Impossible de mettre à jour.');
        header('Location: edit_facture_achat.php?id=' . $id);
        exit;
    }

    $uploadedFile = false;
    if (isset($_FILES['piece'])) {
        $up = handleUpload($_FILES['piece'], $id);
        if (empty($up['ok'])) {
            $auditFactureAchat('facture_achat_attachment_upload_failed', [
                'module' => 'facturation',
                'action' => 'edit_facture_achat_save',
                'entity_type' => 'facture_achat',
                'entity_id' => (string)$id,
                'entity_label' => (string)($facture['invoice_number'] ?? 'Facture achat'),
                'result' => 'failed',
                'severity' => 'warning',
                'details' => [
                    'error_message' => $up['error'] ?? null,
                ],
            ]);
            $_SESSION['facturation_flash_error'] = (string)($up['error'] ?? 'Upload impossible.');
            header('Location: edit_facture_achat.php?id=' . $id);
            exit;
        }
        if (!empty($up['uploaded']) && !empty($up['meta'])) {
            $factureModel->attachFile($id, $up['meta']);
            $uploadedFile = true;
        }
    }

    if ($uploadedFile) {
        \AuditTrail::logFileEvent('upload', [
            'module' => 'facturation',
            'action' => 'facture_achat_attachment_upload',
            'entity_type' => 'facture_achat',
            'entity_id' => (string)$id,
            'entity_label' => (string)($facture['invoice_number'] ?? 'Facture achat'),
            'format' => pathinfo((string)($_FILES['piece']['name'] ?? ''), PATHINFO_EXTENSION),
            'details' => [
                'file_name' => $_FILES['piece']['name'] ?? null,
            ],
        ], $pdo);
    }

    $auditFactureAchat('facture_achat_updated', [
        'module' => 'facturation',
        'action' => 'edit_facture_achat_save',
        'entity_type' => 'facture_achat',
        'entity_id' => (string)$id,
        'entity_label' => (string)($res['reference_interne'] ?? $facture['invoice_number'] ?? 'Facture achat'),
        'result' => 'success',
        'details' => [
            'item_count' => count((array)($itemsParsed['items'] ?? [])),
            'uploaded_file' => $uploadedFile,
        ],
    ]);

    $ref = trim((string)($res['reference_interne'] ?? ''));
    $_SESSION['facturation_flash_success'] = $ref !== ''
        ? ('Facture mise à jour (Réf: ' . $ref . ').')
        : 'Facture mise à jour.';
    header('Location: edit_facture_achat.php?id=' . $id);
    exit;
}

$fournisseurs = $editView['fournisseurs'];

$itemsForForm = [];
if (is_array($facture) && array_key_exists('items_json', $facture)) {
    $raw = trim((string)($facture['items_json'] ?? ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
            $decoded = $decoded['items'];
        }
        if (is_array($decoded)) {
            $itemsForForm = $decoded;
        }
    }
}
if (!$itemsForForm) {
    $itemsForForm = [[
        'description' => '',
        'quantity' => '',
        'amount' => '',
        'taxes' => ['TVA'],
    ]];
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(238,242,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(233,213,255,0.75),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto py-8 px-4">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-6">
            <div>
                <div class="text-xs font-medium text-gray-500">Facturation • Achats</div>
                <h1 class="mt-1 text-2xl md:text-3xl font-semibold text-gray-900">Facture d'achat</h1>
                <p class="mt-2 text-sm text-gray-600">Édition + pièce jointe + vérification FNE.</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="factures_achat.php" class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">Retour</a>
                <a href="dashboard_facturation.php" class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">Dashboard</a>
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

        <?php if (!$facture): ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-6 text-sm text-gray-700">Facture introuvable.</div>
        <?php else: ?>
            <div class="bg-white border border-gray-200 rounded-2xl p-6 mb-6">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-gray-900">Détails</h2>
                    <div class="flex items-center gap-3">
                        <?php if (!empty($facture['file_path'])): ?>
                            <a href="download_facture_achat.php?id=<?= (int)$facture['id_facture_achat'] ?>" class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">Télécharger pièce</a>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="post" enctype="multipart/form-data" class="mt-4 space-y-5">
                    <div data-fne-import-linker data-kind="purchase" class="relative rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                        <input type="hidden" name="fne_import_id">
                        <label class="text-sm font-black text-emerald-950">Lier cette facture à un achat FNE importé</label>
                        <div class="mt-2 flex flex-wrap gap-2"><input data-fne-link-search type="search" autocomplete="off" placeholder="Référence, Token, fournisseur, montant ou date…" class="min-w-64 flex-1 rounded-xl border border-emerald-300 bg-white px-4 py-3"><button data-fne-link-submit name="action" value="link_imported_fne" formnovalidate disabled class="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-black text-white disabled:opacity-40"><i class="fas fa-link mr-2"></i>Lier et synchroniser</button></div>
                        <div data-fne-link-results class="absolute left-4 right-4 z-50 mt-2 hidden max-h-80 overflow-auto rounded-2xl border bg-white p-2 shadow-2xl"></div><p data-fne-link-status class="mt-2 text-xs text-emerald-800">Le fournisseur, le chantier et le statut payé restent sous contrôle Finéa.</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Références</h3>
                            <div class="text-xs text-gray-500">Facture #<?= (int)$facture['id_facture_achat'] ?></div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Fournisseur *</label>
                                <select name="fournisseur_id" required data-finea-search data-placeholder="Rechercher un fournisseur…" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    <option value="">— Choisir —</option>
                                    <?php foreach ($fournisseurs as $f): ?>
                                        <option value="<?= (int)$f['id_fournisseur'] ?>" <?= ((int)$facture['fournisseur_id'] === (int)$f['id_fournisseur']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars((string)$f['raison_sociale']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Référence interne</label>
                                <input name="reference_interne" readonly value="<?= htmlspecialchars((string)($facture['reference_interne'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-gray-100 px-3 py-2 text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                <div class="mt-1 text-xs text-gray-500">Champ non modifiable.</div>
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
                                <input name="invoice_number" value="<?= htmlspecialchars((string)($facture['invoice_number'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Verification Code (FNE)</label>
                                <input name="verification_code" value="<?= htmlspecialchars((string)($facture['verification_code'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
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
                                <input name="invoice_date" type="date" value="<?= htmlspecialchars((string)($facture['invoice_date'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Montant total *</label>
                                <input id="amountTotalInput" name="amount_total" type="number" step="0.01" required value="<?= htmlspecialchars((string)($facture['amount_total'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                <div class="mt-1 text-xs text-gray-500">Si des items sont renseignés, le total est calculé automatiquement (TTC).</div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Devise</label>
                                <input name="devise" value="<?= htmlspecialchars((string)($facture['devise'] ?? 'FCFA')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Statut</label>
                                <select name="statut" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    <?php
                                    $opts = ['a_verifier' => 'À vérifier', 'brouillon' => 'Brouillon', 'verifiee' => 'Vérifiée', 'rejetee' => 'Rejetée', 'payee' => 'Payée'];
                                    $cur = (string)($facture['statut'] ?? 'a_verifier');
                                    foreach ($opts as $k => $label) {
                                        $sel = ($cur === $k) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($k) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
                                    }
                                    ?>
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
                                        <?php foreach ($itemsForForm as $it):
                                            $desc = (string)($it['description'] ?? '');
                                            $qty = (string)($it['quantity'] ?? '');
                                            $amount = (string)($it['amount'] ?? '');
                                            $tax = 'TVA';
                                            if (!empty($it['taxes']) && is_array($it['taxes'])) {
                                                $taxCandidate = trim((string)($it['taxes'][0] ?? ''));
                                                if ($taxCandidate !== '') {
                                                    $tax = $taxCandidate;
                                                }
                                            }
                                            $taxRate = isset($it['tax_rate']) && is_numeric($it['tax_rate']) ? (string)$it['tax_rate'] : (string)($taxRates[$tax] ?? 0);
                                        ?>
                                            <tr>
                                                <td class="py-2 pr-3">
                                                    <input name="items_description[]" value="<?= htmlspecialchars($desc) ?>" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Ex: Achat matériel" />
                                                </td>
                                                <td class="py-2 pr-3">
                                                    <input name="items_quantity[]" type="number" step="0.01" min="0" value="<?= htmlspecialchars($qty) ?>" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                                </td>
                                                <td class="py-2 pr-3">
                                                    <input name="items_amount[]" type="number" step="0.01" min="0" value="<?= htmlspecialchars($amount) ?>" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                                </td>
                                                <td class="py-2 pr-3">
                                                    <select name="items_tax[]" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                                        <?php
                                                        $taxOptions = ['TVA', 'TVAB', 'TVAC', 'TVAD'];
                                                        foreach ($taxOptions as $opt) {
                                                            $sel = ($tax === $opt) ? 'selected' : '';
                                                            echo '<option value="' . htmlspecialchars($opt) . '" ' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                                                        }
                                                        ?>
                                                    </select>
                                                    <input name="items_tax_rate[]" type="number" step="0.000001" min="0" max="1" value="<?= htmlspecialchars($taxRate, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-200" title="Taux taxe modifiable" />
                                                </td>
                                                <td class="py-2 pr-3 text-right">
                                                    <div class="font-medium text-gray-900 jsLineTotal">—</div>
                                                    <div class="text-xs text-gray-500 jsLineBreakdown">HT — • Taxe —</div>
                                                </td>
                                                <td class="py-2">
                                                    <button type="button" class="jsRemoveItemRow px-3 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50" title="Supprimer">×</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
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
                            <label class="block text-sm font-medium text-gray-700">Remplacer / ajouter</label>
                            <input type="file" name="piece" accept="application/pdf,image/*" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2" />
                            <?php if (!empty($facture['file_original_name'])): ?>
                                <div class="mt-1 text-xs text-gray-500">Actuelle: <?= htmlspecialchars((string)$facture['file_original_name']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 rounded-2xl border border-gray-200 bg-white p-4">
                        <button name="action" value="save" class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700">Enregistrer</button>
                        <button name="action" value="verify" class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-800 hover:bg-gray-50">Vérifier FNE</button>
                    </div>
                </form>
            </div>

            <div class="bg-white border border-gray-200 rounded-2xl p-6">
                <h2 class="text-lg font-semibold text-gray-900">Dernier contrôle FNE</h2>
                <div class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <div class="text-xs text-gray-500">Date</div>
                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($facture['fne_last_verify_at'] ?? '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">OK</div>
                        <div class="font-medium text-gray-900"><?= ($facture['fne_last_verify_ok'] === null ? '—' : (!empty($facture['fne_last_verify_ok']) ? 'OK' : 'KO')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">HTTP</div>
                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($facture['fne_last_verify_http_status'] ?? '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Status extrait</div>
                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($facture['fne_last_verify_status'] ?? '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Montant extrait</div>
                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($facture['fne_last_verify_amount'] ?? '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Montant OK</div>
                        <div class="font-medium text-gray-900"><?= ($facture['fne_last_verify_amount_ok'] === null ? '—' : (!empty($facture['fne_last_verify_amount_ok']) ? 'OK' : 'KO')) ?></div>
                    </div>
                </div>

                <?php if (!empty($facture['fne_last_verify_response_json'])): ?>
                    <details class="mt-4">
                        <summary class="cursor-pointer text-sm text-gray-700">Voir réponse brute</summary>
                        <pre class="mt-2 overflow-x-auto rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-gray-800"><?= htmlspecialchars((string)$facture['fne_last_verify_response_json']) ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
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
<script src="assets/fne-import-linker.js"></script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
