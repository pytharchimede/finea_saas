<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FacturationController.php';
require_once __DIR__ . '/../model/FneClient.php';
require_once __DIR__ . '/../model/FneValidator.php';
require_once __DIR__ . '/../services/CurrencySettingsService.php';
require_once __DIR__ . '/../model/FneSalesInvoiceImportManager.php';

$pageTitle = 'Facture de vente';
$activeMenu = 'facturation';

$pdo = Database::getConnection();
$controller = new FacturationController($pdo);
$model = $controller->ventes();
$fneImportManager=new FneSalesInvoiceImportManager($pdo);
$currencyService=new CurrencySettingsService($pdo);$currencyRows=$currencyService->all(true);$defaultCurrency=$currencyService->default();$currencyRates=$currencyService->rates();
$taxRates = require __DIR__ . '/../config/taxes.php';

$auditFactureVente = static function (string $event, array $payload) use ($pdo): void {
    \AuditTrail::log($event, $payload, $pdo);
};

$id = (int)($_GET['id'] ?? 0);
$editView = $controller->editVenteView($id);
$facture = $editView['facture'];
$chantiers = $editView['chantiers'] ?? [];
$clients = $editView['clients'] ?? [];
$currencyOptions=[];foreach($currencyRows as $currency)$currencyOptions[(string)$currency['code']]=(string)$currency['code'].' - '.(string)$currency['label'];

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

function parseItemsJson(string $itemsJson): array
{
    $itemsJson = trim($itemsJson);
    if ($itemsJson === '') {
        return ['ok' => false, 'error' => 'Ajoutez au moins une ligne dans le tableau des items.'];
    }

    $decoded = json_decode($itemsJson, true);
    if (!is_array($decoded) || count($decoded) === 0) {
        return ['ok' => false, 'error' => 'Ajoutez au moins une ligne dans le tableau des items.'];
    }

    // Compat: certaines versions peuvent stocker {items:[...]}
    if (isset($decoded['items']) && is_array($decoded['items'])) {
        $decoded = $decoded['items'];
    }

    if (!is_array($decoded) || count($decoded) === 0) {
        return ['ok' => false, 'error' => 'Ajoutez au moins une ligne dans le tableau des items.'];
    }

    return ['ok' => true, 'items' => $decoded, 'json' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
}

function parseItemsFromPost(array $post): array
{
    $descriptions = $post['items_description'] ?? [];
    $quantities = $post['items_quantity'] ?? [];
    $amounts = $post['items_amount'] ?? [];
    $taxes = $post['items_tax'] ?? [];
    $taxRatesInput = $post['items_tax_rate'] ?? [];

    if (!is_array($descriptions) || !is_array($quantities) || !is_array($amounts) || !is_array($taxes) || !is_array($taxRatesInput)) {
        return ['ok' => false, 'error' => 'Ajoutez au moins une ligne dans le tableau des items.'];
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
            return ['ok' => false, 'error' => 'Chaque ligne doit avoir une description.'];
        }
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'La quantité doit être > 0.'];
        }
        if ($amount < 0) {
            return ['ok' => false, 'error' => 'Le prix unitaire doit être >= 0.'];
        }
        if ($tax === '') {
            return ['ok' => false, 'error' => 'La taxe est obligatoire sur chaque ligne.'];
        }
        if ($taxRateRaw === '' || !is_numeric($taxRateRaw)) {
            return ['ok' => false, 'error' => 'Le taux de taxe doit etre numerique sur chaque ligne.'];
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
        return ['ok' => false, 'error' => 'Ajoutez au moins une ligne dans le tableau des items.'];
    }

    return [
        'ok' => true,
        'items' => $items,
        'json' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function extractInvoiceId($data): ?string
{
    if (!is_array($data)) {
        return null;
    }

    foreach (['id', 'invoiceId', 'invoice_id', 'invoiceNumber', 'invoice_number', 'uid'] as $k) {
        if (isset($data[$k])) {
            $s = trim((string)$data[$k]);
            if ($s !== '') {
                return $s;
            }
        }
    }

    if (isset($data['data']) && is_array($data['data'])) {
        return extractInvoiceId($data['data']);
    }

    return null;
}

function handleVenteUploadEdit(array $file, int $idFacture): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'uploaded' => false];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Upload facture invalide.'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Facture trop volumineuse (max 10MB).'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Fichier facture temporaire invalide.'];
    }
    $mime = '';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $mime = (string)@finfo_file($fi, $tmp);
            @finfo_close($fi);
        }
    }
    if ($mime !== '' && !in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) {
        return ['ok' => false, 'error' => 'Facture physique: PDF ou image uniquement.'];
    }
    $dir = __DIR__ . '/../uploads/factures_vente';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'error' => 'Impossible de creer le dossier facture vente.'];
    }
    $orig = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)($file['name'] ?? 'facture')) ?: 'facture';
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' && $mime === 'application/pdf') {
        $ext = 'pdf';
    }
    $name = 'FV-' . $idFacture . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
    if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Impossible d enregistrer la facture physique.'];
    }
    return ['ok' => true, 'uploaded' => true, 'meta' => [
        'facture_file_path' => 'uploads/factures_vente/' . $name,
        'facture_file_original_name' => (string)($file['name'] ?? $orig),
        'facture_file_mime' => $mime ?: null,
        'facture_file_size' => $size,
    ]];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($id <= 0 || !$facture) {
        $auditFactureVente('facture_vente_update_failed', [
            'module' => 'facturation',
            'action' => 'edit_facture_vente',
            'entity_type' => 'facture_vente',
            'entity_label' => 'Modification facture vente',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'invoice_not_found',
                'invoice_id' => $id > 0 ? $id : null,
            ],
        ]);
        $_SESSION['facturation_flash_error'] = 'Facture introuvable.';
        header('Location: factures_vente.php');
        exit;
    }

    $action = (string)($_POST['action'] ?? 'save');

    if($action==='link_imported_fne'){
        try{$fneImportManager->linkToFinea((int)($_POST['fne_import_id']??0),$id,'edit_select_search');$_SESSION['facturation_flash_success']='Facture FNE rapprochée et données fiscales synchronisées.';}
        catch(Throwable$e){$_SESSION['facturation_flash_error']=$e->getMessage();}
        header('Location: edit_facture_vente.php?id='.$id);exit;
    }

    $parsed = parseItemsFromPost($_POST);
    if (empty($parsed['ok'])) {
        $auditFactureVente('facture_vente_update_failed', [
            'module' => 'facturation',
            'action' => 'edit_facture_vente',
            'entity_type' => 'facture_vente',
            'entity_id' => (string)$id,
            'entity_label' => (string)($facture['reference_interne'] ?? 'Facture vente'),
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'invalid_items',
                'error_message' => $parsed['error'] ?? null,
            ],
        ]);
        $_SESSION['facturation_flash_error'] = (string)($parsed['error'] ?? 'Veuillez compléter au moins une ligne d\'item.');
        header('Location: edit_facture_vente.php?id=' . $id);
        exit;
    }

    if ($action === 'sign') {
        // S'assurer que la facture enregistrée correspond à ce qui va être signé
        $saveData = $_POST;
        $upload = handleVenteUploadEdit($_FILES['facture_file'] ?? [], $id);
        if (empty($upload['ok'])) {
            $_SESSION['facturation_flash_error'] = (string)($upload['error'] ?? 'Facture physique invalide.');
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        }
        if (!empty($upload['uploaded']) && !empty($upload['meta'])) {
            $saveData = array_merge($saveData, (array)$upload['meta']);
        }
        $hasFneCodeForSign = trim((string)($saveData['fne_invoice_number'] ?? '')) !== '' || trim((string)($saveData['fne_verification_code'] ?? '')) !== '' || trim((string)($facture['fne_invoice_id'] ?? '')) !== '';
        $hasFileForSign = !empty($saveData['facture_file_path']) || !empty($facture['facture_file_path']);
        if (!$hasFneCodeForSign && !$hasFileForSign) {
            $_SESSION['facturation_flash_error'] = 'Ajoutez la facture physique ou renseignez au moins un code FNE.';
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        }

        $saveBeforeSign = $model->update($id, $saveData, (string)$parsed['json']);
        if (empty($saveBeforeSign['ok'])) {
            $auditFactureVente('facture_vente_sign_failed', [
                'module' => 'facturation',
                'action' => 'edit_facture_vente_sign',
                'entity_type' => 'facture_vente',
                'entity_id' => (string)$id,
                'entity_label' => (string)($facture['reference_interne'] ?? 'Facture vente'),
                'result' => 'failed',
                'severity' => 'warning',
                'details' => [
                    'reason' => 'save_before_sign_failed',
                ],
            ]);
            $_SESSION['facturation_flash_error'] = 'Impossible d\'enregistrer la facture avant la signature.';
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        }

        $facture = $model->getById($id);
        $payload = [
            'invoiceType' => 'sale',
            'paymentMethod' => (string)($saveData['payment_method'] ?? $facture['payment_method'] ?? 'cash'),
            'template' => (string)($facture['template'] ?? ''),
            'isRne' => !empty($facture['is_rne']),
            'rne' => (string)($facture['rne'] ?? ''),
            'clientNcc' => (string)($facture['client_ncc'] ?? ''),
            'clientCompanyName' => (string)($facture['client_company_name'] ?? ''),
            'clientPhone' => (string)($facture['client_phone'] ?? ''),
            'clientEmail' => (string)($facture['client_email'] ?? ''),
            'pointOfSale' => (string)($_POST['point_of_sale'] ?? $facture['point_of_sale'] ?? ''),
            'establishment' => (string)($_POST['establishment'] ?? $facture['establishment'] ?? ''),
            'discount' => isset($_POST['discount']) && $_POST['discount'] !== '' ? (float)$_POST['discount'] : (float)($facture['discount'] ?? 0),
            'foreignCurrency' => (string)($_POST['foreign_currency'] ?? $facture['foreign_currency'] ?? ''),
            'foreignCurrencyRate' => isset($_POST['foreign_currency_rate']) && $_POST['foreign_currency_rate'] !== '' ? (float)$_POST['foreign_currency_rate'] : ($facture['foreign_currency_rate'] ?? null),
            'items' => $parsed['items'],
        ];

        // Nettoyage champs vides pour éviter des surprises côté FNE
        if (trim((string)$payload['foreignCurrency']) === '') {
            unset($payload['foreignCurrency']);
        }
        if ($payload['foreignCurrencyRate'] === null || $payload['foreignCurrencyRate'] === '') {
            unset($payload['foreignCurrencyRate']);
        }
        if (trim((string)$payload['rne']) === '') {
            unset($payload['rne']);
        }
        if (trim((string)$payload['clientNcc']) === '') {
            unset($payload['clientNcc']);
        }

        $validation = FneValidator::validateSignInvoicePayload($payload);
        if (empty($validation['ok'])) {
            $auditFactureVente('facture_vente_sign_failed', [
                'module' => 'facturation',
                'action' => 'edit_facture_vente_sign',
                'entity_type' => 'facture_vente',
                'entity_id' => (string)$id,
                'entity_label' => (string)($facture['reference_interne'] ?? 'Facture vente'),
                'result' => 'failed',
                'severity' => 'warning',
                'details' => [
                    'reason' => 'invalid_payload',
                ],
            ]);
            $_SESSION['facturation_flash_error'] = 'Signature: payload invalide: ' . implode(' ', (array)($validation['errors'] ?? []));
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        }

        try {
            $client = FneClient::fromConfigFile();
            if (!$client->isConfigured()) {
                $auditFactureVente('facture_vente_sign_failed', [
                    'module' => 'facturation',
                    'action' => 'edit_facture_vente_sign',
                    'entity_type' => 'facture_vente',
                    'entity_id' => (string)$id,
                    'entity_label' => (string)($facture['reference_interne'] ?? 'Facture vente'),
                    'result' => 'failed',
                    'severity' => 'warning',
                    'details' => [
                        'reason' => 'fne_not_configured',
                    ],
                ]);
                $_SESSION['facturation_flash_error'] = 'FNE non configuré (FNE_API_KEY/FNE_BASE_URL).';
                header('Location: edit_facture_vente.php?id=' . $id);
                exit;
            }

            $res = $client->signInvoice($payload);
            $invoiceId = null;
            if (!empty($res['data']) && is_array($res['data'])) {
                $invoiceId = extractInvoiceId($res['data']);
            }

            $model->saveSignResult($id, [
                'fne_signed_at' => date('Y-m-d H:i:s'),
                'fne_http_status' => (int)($res['status'] ?? 0),
                'fne_invoice_id' => $invoiceId,
                'fne_response_json' => json_encode($res, JSON_UNESCAPED_UNICODE),
                'statut' => !empty($res['ok']) ? 'signe' : 'erreur',
            ]);

            $auditFactureVente(!empty($res['ok']) ? 'facture_vente_signed' : 'facture_vente_sign_failed', [
                'module' => 'facturation',
                'action' => 'edit_facture_vente_sign',
                'entity_type' => 'facture_vente',
                'entity_id' => (string)$id,
                'entity_label' => (string)($saveBeforeSign['reference_interne'] ?? $facture['reference_interne'] ?? 'Facture vente'),
                'result' => !empty($res['ok']) ? 'success' : 'failed',
                'severity' => !empty($res['ok']) ? null : 'warning',
                'details' => [
                    'http_status' => (int)($res['status'] ?? 0),
                    'invoice_id' => $invoiceId,
                    'error_message' => $res['error'] ?? null,
                ],
            ]);

            $_SESSION['facturation_flash_success'] = !empty($res['ok']) ? 'Facture signée via FNE.' : ('Signature en erreur: ' . (string)($res['error'] ?? ''));
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $auditFactureVente('facture_vente_sign_failed', [
                'module' => 'facturation',
                'action' => 'edit_facture_vente_sign',
                'entity_type' => 'facture_vente',
                'entity_id' => (string)$id,
                'entity_label' => (string)($facture['reference_interne'] ?? 'Facture vente'),
                'result' => 'failed',
                'severity' => 'warning',
                'details' => [
                    'error_message' => $e->getMessage(),
                ],
            ]);
            $_SESSION['facturation_flash_error'] = 'Erreur serveur (signature FNE).';
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        }
    }

    if ($action === 'verify_fne') {
        $saveData = $_POST;
        $upload = handleVenteUploadEdit($_FILES['facture_file'] ?? [], $id);
        if (empty($upload['ok'])) {
            $_SESSION['facturation_flash_error'] = (string)($upload['error'] ?? 'Facture physique invalide.');
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        }
        if (!empty($upload['uploaded']) && !empty($upload['meta'])) {
            $saveData = array_merge($saveData, (array)$upload['meta']);
        }

        $saved = $model->update($id, $saveData, (string)$parsed['json']);
        if (empty($saved['ok'])) {
            $_SESSION['facturation_flash_error'] = 'Impossible d enregistrer la facture avant verification FNE.';
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        }

        $verifyPayload = [
            'invoiceNumber' => trim((string)($saveData['fne_invoice_number'] ?? '')),
            'verificationCode' => trim((string)($saveData['fne_verification_code'] ?? '')),
            'amount' => isset($saveData['total_ttc']) ? (float)$saveData['total_ttc'] : null,
        ];
        $verifyPayload = array_filter($verifyPayload, static fn($value): bool => $value !== null && trim((string)$value) !== '');

        $validation = FneValidator::validateVerifyPayload($verifyPayload);
        if (empty($validation['ok'])) {
            $_SESSION['facturation_flash_error'] = 'Verification FNE: ' . implode(' ', (array)($validation['errors'] ?? []));
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        }

        try {
            $client = FneClient::fromConfigFile();
            if (!$client->isConfigured()) {
                $_SESSION['facturation_flash_error'] = 'FNE non configure (FNE_API_KEY/FNE_BASE_URL).';
                header('Location: edit_facture_vente.php?id=' . $id);
                exit;
            }

            $res = $client->verifyInvoice($verifyPayload);
            $invoiceId = !empty($res['data']) && is_array($res['data']) ? extractInvoiceId($res['data']) : null;
            $model->saveFneVerificationResult($id, [
                'fne_verified_at' => date('Y-m-d H:i:s'),
                'fne_http_status' => (int)($res['status'] ?? 0),
                'fne_invoice_id' => $invoiceId,
                'fne_response_json' => json_encode($res, JSON_UNESCAPED_UNICODE),
                'fne_verify_ok' => !empty($res['ok']),
                'fne_verify_error' => !empty($res['ok']) ? null : (string)($res['error'] ?? 'Verification FNE echouee'),
            ]);

            $auditFactureVente(!empty($res['ok']) ? 'facture_vente_fne_verified' : 'facture_vente_fne_verify_failed', [
                'module' => 'facturation',
                'action' => 'edit_facture_vente_verify_fne',
                'entity_type' => 'facture_vente',
                'entity_id' => (string)$id,
                'entity_label' => (string)($facture['reference_interne'] ?? 'Facture vente'),
                'result' => !empty($res['ok']) ? 'success' : 'failed',
                'severity' => !empty($res['ok']) ? null : 'warning',
                'details' => [
                    'http_status' => (int)($res['status'] ?? 0),
                    'invoice_id' => $invoiceId,
                    'error_message' => $res['error'] ?? null,
                ],
            ]);

            $_SESSION['facturation_flash_success'] = !empty($res['ok']) ? 'Facture verifiee via FNE.' : '';
            if (empty($res['ok'])) {
                $_SESSION['facturation_flash_error'] = 'Verification FNE en erreur: ' . (string)($res['error'] ?? '');
            }
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        } catch (Throwable $e) {
            $_SESSION['facturation_flash_error'] = 'Erreur serveur (verification FNE).';
            header('Location: edit_facture_vente.php?id=' . $id);
            exit;
        }
    }

    // save
    $saveData = $_POST;
    if(!empty($_POST['remove_facture_file'])){$saveData['facture_file_path']='';$saveData['facture_file_original_name']='';$saveData['facture_file_mime']='';$saveData['facture_file_size']='';}
    $upload = handleVenteUploadEdit($_FILES['facture_file'] ?? [], $id);
    if (empty($upload['ok'])) {
        $_SESSION['facturation_flash_error'] = (string)($upload['error'] ?? 'Facture physique invalide.');
        header('Location: edit_facture_vente.php?id=' . $id);
        exit;
    }
    if (!empty($upload['uploaded']) && !empty($upload['meta'])) {
        $saveData = array_merge($saveData, (array)$upload['meta']);
    }
    try{$res = $model->update($id, $saveData, (string)$parsed['json']);}catch(DomainException $e){$_SESSION['facturation_flash_error']=$e->getMessage();header('Location: edit_facture_vente.php?id='.$id);exit;}
    if (!empty($res['ok'])) {
        $auditFactureVente('facture_vente_updated', [
            'module' => 'facturation',
            'action' => 'edit_facture_vente_save',
            'entity_type' => 'facture_vente',
            'entity_id' => (string)$id,
            'entity_label' => (string)($res['reference_interne'] ?? $facture['reference_interne'] ?? 'Facture vente'),
            'result' => 'success',
            'details' => [
                'item_count' => count((array)($parsed['items'] ?? [])),
            ],
        ]);
        $ref = trim((string)($res['reference_interne'] ?? ''));
        $_SESSION['facturation_flash_success'] = $ref !== ''
            ? ('Facture mise à jour (Réf: ' . $ref . ').')
            : 'Facture mise à jour.';
        header('Location: edit_facture_vente.php?id=' . $id);
        exit;
    }

    $auditFactureVente('facture_vente_update_failed', [
        'module' => 'facturation',
        'action' => 'edit_facture_vente_save',
        'entity_type' => 'facture_vente',
        'entity_id' => (string)$id,
        'entity_label' => (string)($facture['reference_interne'] ?? 'Facture vente'),
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'model_update_failed',
            'error_message' => $res['error'] ?? null,
        ],
    ]);

    $_SESSION['facturation_flash_error'] = (string)($res['error'] ?? 'Impossible de mettre à jour.');
    header('Location: edit_facture_vente.php?id=' . $id);
    exit;
}

$itemsForForm = [];
if (is_array($facture) && isset($facture['items_json'])) {
    $parsedExisting = parseItemsJson((string)$facture['items_json']);
    if (!empty($parsedExisting['ok']) && !empty($parsedExisting['items']) && is_array($parsedExisting['items'])) {
        $itemsForForm = $parsedExisting['items'];
    }
}
if (!$itemsForForm) {
    $itemsForForm = [[
        'description' => '',
        'quantity' => 1,
        'amount' => 0,
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
                <div class="text-xs font-medium text-gray-500">Facturation • Ventes</div>
                <h1 class="mt-1 text-2xl md:text-3xl font-semibold text-gray-900">Facture de vente</h1>
                <p class="mt-2 text-sm text-gray-600">Édition + signature FNE.</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="factures_vente.php" class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-700 hover:bg-gray-50">Retour</a>
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
                <h2 class="text-lg font-semibold text-gray-900">Détails</h2>
                <div class="mt-2 text-sm font-medium text-gray-600"><span class="font-black text-rose-600" aria-hidden="true">*</span> Champ obligatoire</div>
                <form method="post" enctype="multipart/form-data" class="mt-4 space-y-5">
                    <div data-fne-import-linker data-kind="sales" class="relative rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                        <input type="hidden" name="fne_import_id">
                        <label class="text-sm font-black text-emerald-950">Lier cette facture à une facture FNE importée</label>
                        <div class="mt-2 flex flex-wrap gap-2"><input data-fne-link-search type="search" autocomplete="off" placeholder="Numéro FNE, Token, client, montant ou date…" class="min-w-64 flex-1 rounded-xl border border-emerald-300 bg-white px-4 py-3"><button data-fne-link-submit name="action" value="link_imported_fne" formnovalidate disabled class="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-black text-white disabled:opacity-40"><i class="fas fa-link mr-2"></i>Lier et synchroniser</button></div>
                        <div data-fne-link-results class="absolute left-4 right-4 z-50 mt-2 hidden max-h-80 overflow-auto rounded-2xl border bg-white p-2 shadow-2xl"></div><p data-fne-link-status class="mt-2 text-xs text-emerald-800">La liaison conserve le chantier, l’avancement, l’encaissement et les libellés Finéa.</p>
                    </div>
                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Références</h3>
                            <div class="text-xs text-gray-500">Chantier + avancement + encaissement</div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">État d’encaissement <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                                <select name="paiement_statut" required class="mt-1 w-full rounded-xl border border-indigo-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    <?php $pay = (string)($facture['paiement_statut'] ?? 'emise'); ?>
                                    <option value="emise" <?= $pay === 'emise' ? 'selected' : '' ?>>Émise / à percevoir</option>
                                    <option value="payee" <?= $pay === 'payee' ? 'selected' : '' ?>>Payée / encaissée</option>
                                </select>
                            </div>
                            <div><label class="block text-sm font-medium text-gray-700">Date d’encaissement <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label><input type="date" name="expected_collection_date" required value="<?=htmlspecialchars((string)($facture['expected_collection_date']??date('Y-m-d')))?>" class="js-collection-date mt-1 w-full rounded-xl border border-indigo-200 bg-white px-3 py-2"><p class="mt-1 text-xs text-slate-500">Date réelle ou prévisionnelle selon l’état choisi.</p></div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Chantier lié <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                                <div class="js-chantier-combobox relative mt-1">
                                    <input type="search" class="js-chantier-input w-full rounded-xl border border-indigo-200 bg-white px-4 py-3 pr-12 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100" placeholder="Saisir un code, un chantier ou un client…" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" required>
                                    <button type="button" class="js-chantier-toggle absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-500" aria-label="Afficher les chantiers"><i class="fas fa-chevron-down text-xs"></i></button>
                                    <select name="chantier_id" class="js-chantier-select absolute h-px w-px overflow-hidden opacity-0" tabindex="-1" aria-hidden="true">
                                        <option value="">Sélectionner un chantier</option>
                                        <?php $curChantier = (int)($facture['chantier_id'] ?? 0); ?>
                                        <?php foreach ($chantiers as $c): ?>
                                            <?php $cid = (int)($c['id'] ?? 0); ?>
                                            <option value="<?= $cid ?>" <?= $curChantier === $cid ? 'selected' : '' ?>><?= htmlspecialchars(trim((string)($c['code'] ?? '') . ' - ' . (string)($c['titre'] ?? '') . ' - ' . (string)($c['nom_client'] ?? '')), ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="js-chantier-results absolute z-50 mt-2 hidden max-h-72 w-full overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl"></div>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Référence interne</label>
                                <input name="reference_interne" readonly value="<?= htmlspecialchars((string)($facture['reference_interne'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-gray-100 px-3 py-2 text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                <div class="mt-1 text-xs text-gray-500">Champ non modifiable.</div>
                            </div>
                            <div><label class="block text-sm font-medium text-gray-700">Date de facturation <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label><input type="date" name="invoice_date" required value="<?=htmlspecialchars((string)($facture['invoice_date']??date('Y-m-d')))?>" class="js-invoice-date mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2"><p class="mt-1 text-xs text-slate-500">Saisie libre : date passée, présente ou future.</p></div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Nature facture travaux <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                                <select name="facture_travaux_type" required data-placeholder="Rechercher une nature de facture…" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    <?php
                                    $typesTravaux = ['avance' => 'Avance / démarrage', 'situation' => 'Situation / avancement', 'finale' => 'Facture finale', 'complement' => 'Complément'];
                                    $curTypeTravaux = (string)($facture['facture_travaux_type'] ?? 'situation');
                                    foreach ($typesTravaux as $k => $label) {
                                        echo '<option value="' . htmlspecialchars($k) . '"' . ($curTypeTravaux === $k ? ' selected' : '') . '>' . htmlspecialchars($label) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Avancement facturé (%)</label>
                                <input name="avancement_percent" type="number" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string)($facture['avancement_percent'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                        </div>
                    </div>

                    <details class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                            <span>
                                <span class="block text-sm font-semibold text-gray-900">Parametres FNE optionnels</span>
                                <span class="text-xs text-gray-500">Ouvrir uniquement pour renseigner les options fiscales DGI.</span>
                            </span>
                            <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-gray-600">Deplier</span>
                        </summary>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Mode de paiement</label>
                                <select name="payment_method" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    <?php
                                    $methods = [
                                        'cash' => 'Especes',
                                        'card' => 'Carte bancaire',
                                        'check' => 'Cheque',
                                        'mobile-money' => 'Mobile money',
                                        'transfer' => 'Virement bancaire',
                                        'deferred' => 'Paiement differe / credit client',
                                    ];
                                    $cur = (string)($facture['payment_method'] ?? 'cash');
                                    foreach ($methods as $m => $label) {
                                        $sel = ($cur === $m) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($m) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Profil fiscal FNE</label>
                                <select name="template" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                    <?php
                                    $templates = [
                                        'B2F' => 'B2F (client étranger)',
                                        'B2B' => 'B2B (entreprise avec NCC)',
                                        'B2G' => 'B2G (administration / marche public)',
                                        'B2C' => 'B2C (particulier)',
                                    ];
                                    $cur = (string)($facture['template'] ?? 'B2F');
                                    foreach ($templates as $t => $label) {
                                        $sel = ($cur === $t) ? 'selected' : '';
                                        echo '<option value="' . htmlspecialchars($t) . '" ' . $sel . '>' . htmlspecialchars($label) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Client NCC (si B2B)</label>
                                <input name="client_ncc" value="<?= htmlspecialchars((string)($facture['client_ncc'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">RNE</label>
                                <input name="rne" value="<?= htmlspecialchars((string)($facture['rne'] ?? '')) ?>" class="js-rne-input mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-600">
                                    <input type="checkbox" name="is_rne" value="1" <?= !empty($facture['is_rne']) ? 'checked' : '' ?> class="js-is-rne rounded border-gray-300" />
                                    Facture liee a un RNE
                                </label>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">N° facture FNE</label>
                                <input name="fne_invoice_number" value="<?= htmlspecialchars((string)($facture['fne_invoice_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Code vérification FNE</label>
                                <input name="fne_verification_code" value="<?= htmlspecialchars((string)($facture['fne_verification_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Facture physique PDF/image</label>
                                <div class="js-file-drop mt-1 rounded-2xl border-2 border-dashed border-indigo-200 bg-indigo-50/50 p-5 text-center"><input type="file" name="facture_file" accept="application/pdf,image/*" class="js-file-input w-full text-sm" /><div class="js-file-preview mt-3 text-sm font-semibold text-indigo-800"></div></div>
                                <div class="mt-1 text-xs text-gray-500">
                                    <?= !empty($facture['facture_file_path']) ? 'Fichier actuel : ' . htmlspecialchars((string)($facture['facture_file_original_name'] ?? basename((string)$facture['facture_file_path'])), ENT_QUOTES, 'UTF-8') : 'Facultatif — le fichier pourra être ajouté ultérieurement.' ?>
                                </div>
                                <?php if(!empty($facture['facture_file_path'])): ?><div class="mt-2 flex flex-wrap gap-2"><a target="_blank" href="../<?=htmlspecialchars((string)$facture['facture_file_path'])?>" class="inline-flex rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white">Visualiser le fichier actuel</a><label class="inline-flex cursor-pointer items-center rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700"><input type="checkbox" name="remove_facture_file" value="1" class="mr-2"> × Supprimer</label></div><?php endif; ?>
                            </div>
                        </div>
                    </details>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Client</h3>
                            <div class="text-xs text-gray-500">Nom + contact</div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Client (nom) <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                                <input name="client_company_name" list="clientOptions" data-finea-search  value="<?= htmlspecialchars((string)($facture['client_company_name'] ?? '')) ?>" class="js-client-search mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Saisir le nom du client..." autocomplete="off" />
                                <datalist id="clientOptions">
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= htmlspecialchars((string)($client['nom_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Client téléphone <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                                <input name="client_phone"  value="<?= htmlspecialchars((string)($facture['client_phone'] ?? '')) ?>" class="js-client-phone mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Client email</label>
                                <input name="client_email" type="email" value="<?= htmlspecialchars((string)($facture['client_email'] ?? '')) ?>" class="js-client-email mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Non renseigne dans la fiche client" />
                                <div class="mt-1 text-xs text-gray-500">Renseigne automatiquement si disponible sur la fiche client.</div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="point_of_sale" value="<?= htmlspecialchars((string)($facture['point_of_sale'] ?? 'CHANTIER'), ENT_QUOTES, 'UTF-8') ?>" />
                    <input type="hidden" name="establishment" value="<?= htmlspecialchars((string)($facture['establishment'] ?? 'BANAMUR BTP'), ENT_QUOTES, 'UTF-8') ?>" />

                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Remises & devises</h3>
                            <div class="text-xs text-gray-500">Discount + devise étrangère</div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Remise globale</label>
                                <input name="discount" type="number" step="0.01" value="<?= htmlspecialchars((string)($facture['discount'] ?? 0)) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <label class="rounded-xl border border-amber-200 bg-amber-50 p-3"><span class="flex items-center gap-2 font-semibold text-amber-900"><input type="checkbox" name="tax_not_invoiced" value="1" class="js-tax-not-invoiced" <?=!empty($facture['tax_not_invoiced'])?'checked':''?>> TVA non facturée · opération exonérée</span><span class="mt-1 block text-xs text-amber-800">TVA informative uniquement : ni encaissée, ni reversée, ni déduite de la trésorerie.</span></label>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Devise</label>
                                <input name="foreign_currency" list="currencyOptions" data-finea-search value="<?= htmlspecialchars((string)($facture['foreign_currency'] ?? $defaultCurrency['code'])) ?>" class="js-currency mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" autocomplete="off" />
                                <datalist id="currencyOptions">
                                    <?php foreach ($currencyOptions as $code => $label): ?>
                                        <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <div class="mt-1 text-xs text-gray-500">XOF / FCFA par defaut. Le taux est recupere automatiquement si possible.</div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Taux devise étrangère</label>
                                <input name="foreign_currency_rate" type="number" step="0.000001" value="<?= htmlspecialchars((string)($facture['foreign_currency_rate'] ?? $defaultCurrency['rate_to_xof'])) ?>" readonly class="js-currency-rate mt-1 w-full rounded-xl border border-gray-200 bg-slate-100 px-3 py-2" />
                                <div class="js-currency-rate-status mt-1 text-xs text-gray-500"></div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Items</h3>
                            <div class="text-xs text-gray-500">Lignes de facture</div>
                        </div>
                        <div class="mt-4">
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-gray-500 border-b">
                                            <th class="py-2 pr-3">Description <span class="font-black text-rose-600">*</span></th>
                                            <th class="py-2 pr-3 w-28">Qté <span class="font-black text-rose-600">*</span></th>
                                            <th class="py-2 pr-3 w-36">Prix <span class="font-black text-rose-600">*</span></th>
                                            <th class="py-2 pr-3 w-36">Taxe <span class="font-black text-rose-600">*</span></th>
                                            <th class="py-2 pr-3 w-40 text-right">Total</th>
                                            <th class="py-2 w-12"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="itemsTbody" class="divide-y">
                                        <?php foreach ($itemsForForm as $it):
                                            $desc = (string)($it['description'] ?? '');
                                            $qty = (string)($it['quantity'] ?? '1');
                                            $amount = (string)($it['amount'] ?? '0');
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
                                                    <input name="items_description[]" required value="<?= htmlspecialchars($desc) ?>" class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Ex: Prestation" />
                                                </td>
                                                <td class="py-2 pr-3">
                                                    <input name="items_quantity[]" type="number" step="0.01" min="0.01" value="<?= htmlspecialchars($qty) ?>" required class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                                </td>
                                                <td class="py-2 pr-3">
                                                    <input name="items_amount[]" type="number" step="0.01" min="0" value="<?= htmlspecialchars($amount) ?>" required class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                                </td>
                                                <td class="py-2 pr-3">
                                                    <select name="items_tax[]" required class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
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
                                                    <div class="font-medium text-gray-900 jsLineTotal">0,00</div>
                                                    <div class="text-xs text-gray-500 jsLineBreakdown">HT 0,00 • Taxe 0,00</div>
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
                                <div class="text-xs text-gray-500">Taxes requises sur chaque ligne (TVA/TVAB/TVAC/TVAD).</div>
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
                                    <div class="mt-2 flex items-center justify-between">
                                        <div class="text-gray-600">Total TTC</div>
                                        <div class="font-semibold text-gray-900" id="itemsTotalTTC">0,00</div>
                                    </div>
                                    <div class="mt-2 flex items-center justify-between">
                                        <div class="text-gray-600">Remise</div>
                                        <div class="font-semibold text-gray-900" id="itemsDiscount">0,00</div>
                                    </div>
                                    <div class="mt-2 flex items-center justify-between border-t border-gray-200 pt-2">
                                        <div class="text-gray-700" id="itemsNetLabel">Net à percevoir</div>
                                        <div class="font-semibold text-gray-900" id="itemsTotalNet">0,00</div>
                                    </div>
                                    <div class="mt-2 text-xs text-gray-500">Taux paramétrés via <code>config/taxes.php</code>.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 rounded-2xl border border-gray-200 bg-white p-4">
                        <button name="action" value="save" class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700">Enregistrer</button>
                        <button name="action" value="verify_fne" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700">Vérifier FNE</button>
                        <button name="action" value="sign" class="px-4 py-2 rounded-xl bg-white border border-gray-200 text-gray-800 hover:bg-gray-50">Signer via FNE</button>
                    </div>
                </form>
            </div>

            <div class="bg-white border border-gray-200 rounded-2xl p-6">
                <h2 class="text-lg font-semibold text-gray-900">FNE</h2>
                <div class="mt-3 grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                    <div>
                        <div class="text-xs text-gray-500">Vérification</div>
                        <div class="font-medium <?= !empty($facture['fne_verify_ok']) ? 'text-emerald-700' : (($facture['fne_verify_ok'] ?? null) === null ? 'text-gray-900' : 'text-rose-700') ?>">
                            <?php
                            if (($facture['fne_verify_ok'] ?? null) === null) {
                                echo '—';
                            } else {
                                echo !empty($facture['fne_verify_ok']) ? 'OK' : 'KO';
                            }
                            ?>
                        </div>
                        <div class="mt-1 text-xs text-gray-500"><?= htmlspecialchars((string)($facture['fne_verified_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Signature</div>
                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($facture['fne_signed_at'] ?? '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">HTTP</div>
                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($facture['fne_http_status'] ?? '—')) ?></div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Invoice ID</div>
                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)($facture['fne_invoice_id'] ?? '—')) ?></div>
                    </div>
                </div>
                <?php if (!empty($facture['fne_verify_error'])): ?>
                    <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        <?= htmlspecialchars((string)$facture['fne_verify_error'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($facture['fne_response_json'])): ?>
                    <details class="mt-4">
                        <summary class="cursor-pointer text-sm text-gray-700">Voir réponse brute</summary>
                        <pre class="mt-2 overflow-x-auto rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-gray-800"><?= htmlspecialchars((string)$facture['fne_response_json']) ?></pre>
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
        const subtotalEl = document.getElementById('itemsSubtotalHT');
        const totalTaxEl = document.getElementById('itemsTotalTax');
        const totalTtcEl = document.getElementById('itemsTotalTTC');
        const discountEl = document.getElementById('itemsDiscount');
        const totalNetEl = document.getElementById('itemsTotalNet');
        const discountInput = document.querySelector('input[name="discount"]');
        const taxNotInvoicedInput = document.querySelector('input[name="tax_not_invoiced"]');
        const netLabelEl = document.getElementById('itemsNetLabel');
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

            let discount = discountInput ? toNumber(discountInput.value || 0) : 0;
            if (discount < 0) discount = 0;
            if (discount > totalHT) discount = totalHT;

            const factor = totalHT > 0 ? ((totalHT - discount) / totalHT) : 1;
            const netHT = round2(totalHT * factor);
            const netTax = round2(totalTax * factor);
            const taxExcluded = !!taxNotInvoicedInput?.checked;
            const netTTC = round2(netHT + (taxExcluded ? 0 : netTax));

            if (subtotalEl) subtotalEl.textContent = fmt(totalHT);
            if (totalTaxEl) totalTaxEl.textContent = fmt(totalTax);
            if (totalTtcEl) totalTtcEl.textContent = fmt(totalTTC);
            if (discountEl) discountEl.textContent = fmt(discount);
            if (totalNetEl) totalNetEl.textContent = fmt(netTTC);
            if (netLabelEl) netLabelEl.textContent = taxExcluded ? 'Net à percevoir (TVA exclue)' : 'Net TTC à percevoir';
        }

        function makeRow() {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="py-2 pr-3">
                    <input name="items_description[]" required class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Ex: Prestation" />
                </td>
                <td class="py-2 pr-3">
                    <input name="items_quantity[]" type="number" step="0.01" min="0.01" value="1" required class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                </td>
                <td class="py-2 pr-3">
                    <input name="items_amount[]" type="number" step="0.01" min="0" value="0" required class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                </td>
                <td class="py-2 pr-3">
                    <select name="items_tax[]" required class="w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                        <option value="TVA" selected>TVA</option>
                        <option value="TVAB">TVAB</option>
                        <option value="TVAC">TVAC</option>
                        <option value="TVAD">TVAD</option>
                    </select>
                    <input name="items_tax_rate[]" type="number" step="0.000001" min="0" max="1" value="${taxRates.TVA || 0}" class="mt-2 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-200" title="Taux taxe modifiable" />
                </td>
                <td class="py-2 pr-3 text-right">
                    <div class="font-medium text-gray-900 jsLineTotal">0,00</div>
                    <div class="text-xs text-gray-500 jsLineBreakdown">HT 0,00 • Taxe 0,00</div>
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

        if (discountInput) {
            discountInput.addEventListener('input', recalcTotals);
            discountInput.addEventListener('change', recalcTotals);
        }
        taxNotInvoicedInput?.addEventListener('change', recalcTotals);

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
                    } else if (el.name === 'items_quantity[]') {
                        el.value = '1';
                    } else if (el.name === 'items_amount[]') {
                        el.value = '0';
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

    (function() {
        const clients = <?php echo json_encode(array_map(static function (array $client): array {
            return [
                'name' => (string)($client['nom_client'] ?? ''),
                'phone' => (string)($client['telephone_client'] ?? ''),
                'email' => (string)($client['email_client'] ?? ''),
            ];
        }, $clients), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const clientInput = document.querySelector('.js-client-search');
        const phoneInput = document.querySelector('.js-client-phone');
        const emailInput = document.querySelector('.js-client-email');
        const rneInput = document.querySelector('.js-rne-input');
        const isRneInput = document.querySelector('.js-is-rne');
        const currencyInput = document.querySelector('.js-currency');
        const rateInput = document.querySelector('.js-currency-rate');
        const rateStatus = document.querySelector('.js-currency-rate-status');

        function normalize(value) {
            return String(value || '').trim().toLowerCase();
        }

        function applyClient() {
            if (!clientInput) return;
            const found = clients.find(function(client) {
                return normalize(client.name) === normalize(clientInput.value);
            });
            if (!found) return;
            if (phoneInput) phoneInput.value = found.phone || '';
            if (emailInput) emailInput.value = found.email || '';
        }

        function syncRne() {
            if (!rneInput || !isRneInput) return;
            isRneInput.checked = String(rneInput.value || '').trim() !== '';
        }

        function updateRate() {
            if (!currencyInput || !rateInput) return;
            const rates=<?=json_encode($currencyRates,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
            const code=String(currencyInput.value||<?=json_encode((string)$defaultCurrency['code'])?>).trim().toUpperCase();currencyInput.value=code;
            const rate=Number(rates[code]||0);rateInput.value=rate>0?String(rate):'';rateInput.readOnly=true;
            if(rateStatus)rateStatus.textContent=rate>0?`1 ${code} = ${rate.toLocaleString('fr-FR',{maximumFractionDigits:6})} XOF`:'Devise inactive ou non parametree.';
        }

        if (clientInput) {
            clientInput.addEventListener('change', applyClient);
            clientInput.addEventListener('blur', applyClient);
        }
        if (rneInput) {
            rneInput.addEventListener('input', syncRne);
            syncRne();
        }
        if (currencyInput) {
            currencyInput.addEventListener('change', updateRate);
            currencyInput.addEventListener('blur', updateRate);
            updateRate();
        }
        const invoiceDate=document.querySelector('[name="invoice_date"]');
        const collectionDate=document.querySelector('[name="expected_collection_date"]');
        [invoiceDate,collectionDate].forEach(field=>{field?.removeAttribute('min');field?.removeAttribute('max')});
        const fileInput=document.querySelector('.js-file-input'),preview=document.querySelector('.js-file-preview'),drop=document.querySelector('.js-file-drop');
        const showFile=()=>{const file=fileInput?.files?.[0];if(preview)preview.innerHTML=file?`${file.name} <button type="button" class="ml-2 text-rose-600" data-remove-file>× Supprimer</button>`:'';};
        fileInput?.addEventListener('change',showFile);drop?.addEventListener('dragover',event=>{event.preventDefault();drop.classList.add('border-indigo-500')});drop?.addEventListener('dragleave',()=>drop.classList.remove('border-indigo-500'));drop?.addEventListener('drop',event=>{event.preventDefault();drop.classList.remove('border-indigo-500');if(event.dataTransfer?.files?.length){fileInput.files=event.dataTransfer.files;showFile();}});preview?.addEventListener('click',event=>{if(event.target.closest('[data-remove-file]')){fileInput.value='';showFile();}});
    })();
</script>

<script src="assets/chantier-combobox.js"></script>
<script src="assets/finea-search-combobox.js"></script>
<script src="assets/fne-import-linker.js"></script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>

