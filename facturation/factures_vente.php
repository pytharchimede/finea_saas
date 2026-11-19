<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FacturationController.php';
require_once __DIR__ . '/../model/FneClient.php';
require_once __DIR__ . '/../services/QuoteBillingAlertService.php';
require_once __DIR__ . '/../services/CurrencySettingsService.php';
require_once __DIR__ . '/../model/FneSalesInvoiceImportManager.php';

$pageTitle = 'Factures de vente';
$activeMenu = 'facturation';

$pdo = Database::getConnection();
if (empty($_SESSION['fne_lookup_csrf'])) {
    $_SESSION['fne_lookup_csrf'] = bin2hex(random_bytes(32));
}
$controller = new FacturationController($pdo);
$model = $controller->ventes();
$fneImportManager = new FneSalesInvoiceImportManager($pdo);
$currencyService=new CurrencySettingsService($pdo);$currencyRows=$currencyService->all(true);$defaultCurrency=$currencyService->default();$currencyRates=$currencyService->rates();
$billingAlerts = (new QuoteBillingAlertService($pdo))->pending();
$taxRates = require __DIR__ . '/../config/taxes.php';

$auditFactureVente = static function (string $event, array $payload) use ($pdo): void {
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

function renderVenteStatutBadge(string $statut): string
{
    $statut = trim($statut);
    switch ($statut) {
        case 'brouillon':
            return renderFactureBadge('Brouillon', 'border-slate-200 bg-slate-50 text-slate-700');
        case 'a_signer':
            return renderFactureBadge('À signer', 'border-amber-200 bg-amber-50 text-amber-900');
        case 'signe':
            return renderFactureBadge('Signée', 'border-emerald-200 bg-emerald-50 text-emerald-900');
        case 'erreur':
            return renderFactureBadge('Erreur', 'border-rose-200 bg-rose-50 text-rose-900');
        default:
            return renderFactureBadge($statut !== '' ? $statut : '—', 'border-slate-200 bg-white text-slate-700');
    }
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

        // ignorer les lignes totalement vides
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

function handleVenteUpload(array $file, int $idFacture = 0): array
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
    $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
    if ($mime !== '' && !in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Facture physique: PDF ou image uniquement.'];
    }
    $dir = (__DIR__ . '/../uploads/factures_vente');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['ok' => false, 'error' => 'Impossible de creer le dossier facture vente.'];
    }
    $orig = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string)($file['name'] ?? 'facture')) ?: 'facture';
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '' && $mime === 'application/pdf') {
        $ext = 'pdf';
    }
    $name = 'FV-' . ($idFacture > 0 ? $idFacture : 'NEW') . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
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

function extractVenteInvoiceId($data): ?string
{
    if (!is_array($data)) {
        return null;
    }
    foreach (['id', 'invoiceId', 'invoice_id', 'invoiceNumber', 'invoice_number', 'uid'] as $k) {
        if (isset($data[$k]) && trim((string)$data[$k]) !== '') {
            return trim((string)$data[$k]);
        }
    }
    return isset($data['data']) && is_array($data['data']) ? extractVenteInvoiceId($data['data']) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$model->isReady()) {
        $auditFactureVente('facture_vente_create_failed', [
            'module' => 'facturation',
            'action' => 'create_facture_vente',
            'entity_type' => 'facture_vente',
            'entity_label' => 'Nouvelle facture vente',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'module_not_ready',
                'init_error' => $model->getInitError() ?: null,
            ],
        ]);
        $_SESSION['facturation_flash_error'] = $model->getInitError() ?: 'Module indisponible.';
        header('Location: factures_vente.php');
        exit;
    }

    $parsed = parseItemsFromPost($_POST);
    if (empty($parsed['ok'])) {
        $auditFactureVente('facture_vente_create_failed', [
            'module' => 'facturation',
            'action' => 'create_facture_vente',
            'entity_type' => 'facture_vente',
            'entity_label' => 'Nouvelle facture vente',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'invalid_items',
                'error_message' => $parsed['error'] ?? null,
            ],
        ]);
        $_SESSION['facturation_flash_error'] = (string)($parsed['error'] ?? 'Veuillez compléter au moins une ligne d\'item.');
        header('Location: factures_vente.php');
        exit;
    }

    $hasFneCode = trim((string)($_POST['fne_invoice_number'] ?? '')) !== '' || trim((string)($_POST['fne_verification_code'] ?? '')) !== '';
    $upload = handleVenteUpload($_FILES['facture_file'] ?? []);
    if (empty($upload['ok'])) {
        $_SESSION['facturation_flash_error'] = (string)($upload['error'] ?? 'Facture physique invalide.');
        header('Location: factures_vente.php');
        exit;
    }
    $createData = $_POST;
    if (!empty($upload['uploaded']) && !empty($upload['meta'])) {
        $createData = array_merge($createData, (array)$upload['meta']);
    }

    $selectedFneImportId=(int)($_POST['fne_import_id']??0);
    if ($selectedFneImportId>0) {
        try {
            $selectedFne=$fneImportManager->getSelectable($selectedFneImportId);
            $createData['fne_invoice_id']=$selectedFne['token'] ?: null;
            $createData['fne_invoice_number']=$selectedFne['fne_reference'] ?: null;
        } catch(Throwable $e) {
            $_SESSION['facturation_flash_error']=$e->getMessage();
            header('Location: factures_vente.php');
            exit;
        }
    }
    try{$res = $model->create($createData, (string)$parsed['json'], (int)($_SESSION['user_id'] ?? 0));}catch(DomainException $e){$_SESSION['facturation_flash_error']=$e->getMessage();header('Location: factures_vente.php');exit;}
    if (!empty($res['ok'])) {
        if ($selectedFneImportId>0 && !empty($res['id'])) {
            try {
                $fneImportManager->linkToFinea($selectedFneImportId,(int)$res['id'],'creation_select_search');
            } catch(Throwable $e) {
                $_SESSION['facturation_flash_error']='Facture créée, mais liaison FNE à contrôler : '.$e->getMessage();
            }
        }
        if ($hasFneCode && !empty($res['id'])) {
            try {
                $fne = FneClient::fromConfigFile();
                if ($fne->isConfigured()) {
                    $verifyPayload = [
                        'invoiceNumber' => trim((string)($_POST['fne_invoice_number'] ?? '')),
                        'verificationCode' => trim((string)($_POST['fne_verification_code'] ?? '')),
                    ];
                    $verifyPayload = array_filter($verifyPayload, static fn($v): bool => trim((string)$v) !== '');
                    $verifyRes = $fne->verifyInvoice($verifyPayload);
                    $model->saveFneVerificationResult((int)$res['id'], [
                        'fne_verified_at' => date('Y-m-d H:i:s'),
                        'fne_http_status' => (int)($verifyRes['status'] ?? 0),
                        'fne_verify_ok' => !empty($verifyRes['ok']),
                        'fne_verify_error' => !empty($verifyRes['ok']) ? null : (string)($verifyRes['error'] ?? 'Verification FNE echouee'),
                        'fne_invoice_id' => !empty($verifyRes['data']) && is_array($verifyRes['data']) ? extractVenteInvoiceId($verifyRes['data']) : null,
                        'fne_response_json' => json_encode($verifyRes, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            } catch (Throwable $e) {
                // La facture reste creee; la verification pourra etre relancee plus tard.
            }
        }
        $auditFactureVente('facture_vente_created', [
            'module' => 'facturation',
            'action' => 'create_facture_vente',
            'entity_type' => 'facture_vente',
            'entity_id' => isset($res['id']) ? (string)$res['id'] : null,
            'entity_label' => (string)($res['reference_interne'] ?? 'Nouvelle facture vente'),
            'result' => 'success',
            'details' => [
                'item_count' => count((array)($parsed['items'] ?? [])),
            ],
        ]);
        $ref = trim((string)($res['reference_interne'] ?? ''));
        $_SESSION['facturation_flash_success'] = $ref !== ''
            ? ('Facture de vente créée (Réf: ' . $ref . ').')
            : 'Facture de vente créée.';
        header('Location: factures_vente.php');
        exit;
    }

    $auditFactureVente('facture_vente_create_failed', [
        'module' => 'facturation',
        'action' => 'create_facture_vente',
        'entity_type' => 'facture_vente',
        'entity_label' => 'Nouvelle facture vente',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'model_create_failed',
            'error_message' => $res['error'] ?? null,
        ],
    ]);

    $_SESSION['facturation_flash_error'] = (string)($res['error'] ?? 'Impossible de créer la facture de vente.');
    header('Location: factures_vente.php');
    exit;
}

$venteView = $controller->venteListView(200);
$factures = $venteView['factures'];
$chantiers = $venteView['chantiers'] ?? [];
$prefillChantierId=(int)($_GET['chantier_id']??0);
$chantierSummary = $venteView['chantierSummary'] ?? [];
$clients = $venteView['clients'] ?? [];
$currencyOptions=[];foreach($currencyRows as $currency)$currencyOptions[(string)$currency['code']]=(string)$currency['code'].' - '.(string)$currency['label'];
$salesStats=['total'=>count($factures),'emise'=>0,'payee'=>0,'retard'=>0,'fne'=>0,'receivable'=>0.0];
$todayIso=date('Y-m-d');
foreach($factures as $saleRow){
    $paid=(string)($saleRow['paiement_statut']??'emise')==='payee';
    $due=substr((string)($saleRow['expected_collection_date']??''),0,10);
    $salesStats[$paid?'payee':'emise']++;
    $salesStats['retard']+=(!$paid&&$due!==''&&$due<$todayIso)?1:0;
    $salesStats['fne']+=!empty($saleRow['fne_signed_at'])?1:0;
    if(!$paid)$salesStats['receivable']+=(float)($saleRow['total_ttc']??0);
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<style>
.fv-main-grid{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:1.5rem;align-items:start}.fv-main-column{display:flex;min-width:0;flex-direction:column}.fv-register{order:1}.fv-projects{order:2}.fv-sale-row[hidden]{display:none!important}.fv-modal{position:fixed;inset:0;z-index:10000;display:none;background:rgba(15,23,42,.72);padding:2rem;overflow-y:auto}.fv-modal.is-open{display:block}.fv-modal-panel{max-width:1120px;margin:0 auto;background:#fff;border-radius:30px;box-shadow:0 28px 90px rgba(15,23,42,.4)}body.fv-modal-open{overflow:hidden}@media(max-width:1023px){.fv-main-grid{grid-template-columns:1fr}.fv-aside{order:-1}.fv-modal{padding:.75rem}.fv-modal-panel{border-radius:20px}}
</style>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(255,247,237,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(219,234,254,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto py-8 px-4">
        <section class="relative mb-6 overflow-hidden rounded-[32px] border border-slate-900/10 bg-gradient-to-r from-slate-950 via-[#1d2b57] to-slate-900 px-8 py-9 text-white shadow-[0_24px_80px_rgba(15,23,42,.32)]">
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl"><p class="text-xs font-semibold uppercase tracking-[.3em] text-amber-200">Finéa · Facturation clients</p><h1 class="mt-3 text-3xl font-semibold md:text-5xl">Factures de vente</h1><p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">Émettre, suivre et encaisser les factures tout en conservant la conformité FNE et les documents normalisés ISO 9001.</p></div>
                <div class="flex flex-wrap gap-3"><a href="dashboard_facturation.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900"><i class="fas fa-chart-line mr-2 text-orange-600"></i>Vue globale</a><button type="button" data-open-invoice-form class="inline-flex items-center rounded-2xl bg-[#fabd02] px-5 py-3 text-sm font-bold text-slate-950"><i class="fas fa-plus mr-2"></i>Nouvelle facture</button></div>
            </div>
        </section>

        <section class="mb-7 grid grid-cols-2 gap-4 lg:grid-cols-5">
            <?php foreach([['Toutes',$salesStats['total'],'fa-file-invoice','text-indigo-700 bg-indigo-50',''],['À percevoir',$salesStats['emise'],'fa-clock','text-amber-700 bg-amber-50','emise'],['En retard',$salesStats['retard'],'fa-triangle-exclamation','text-rose-700 bg-rose-50','retard'],['Encaissées',$salesStats['payee'],'fa-circle-check','text-emerald-700 bg-emerald-50','payee'],['Signées FNE',$salesStats['fne'],'fa-shield-halved','text-sky-700 bg-sky-50','fne']] as $card): ?><button type="button" data-fv-card-filter="<?=htmlspecialchars($card[4])?>" class="group rounded-3xl border border-white/70 bg-white/95 p-5 text-left shadow-[0_16px_45px_rgba(15,23,42,.07)] transition hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-lg"><div class="flex items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase text-slate-500"><?=htmlspecialchars($card[0])?></p><strong class="mt-2 block text-3xl text-slate-950"><?=number_format((float)$card[1],0,',',' ')?></strong><span class="mt-2 block text-[11px] font-semibold text-indigo-600 opacity-0 transition group-hover:opacity-100">Voir le listing</span></div><span class="flex h-11 w-11 items-center justify-center rounded-2xl <?=$card[3]?>"><i class="fas <?=$card[2]?>"></i></span></div></button><?php endforeach; ?>
        </section>

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

        <?php if ($billingAlerts): ?>
            <section class="mb-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-950">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><div class="text-xs font-black uppercase tracking-wider">Alertes de facturation</div><h2 class="mt-1 text-lg font-black"><?= count($billingAlerts) ?> devis à facturer</h2></div><span class="rounded-full bg-white px-3 py-1 text-xs font-bold">Triés par échéance</span></div>
                <div class="mt-4 grid gap-3 md:grid-cols-2"><?php foreach(array_slice($billingAlerts,0,6) as $alert): ?>
                  <?php $alertTotal=(float)($alert['quote_total']??0);$alertCollected=(float)($alert['amount_collected']??0);$alertRemaining=(float)($alert['amount_remaining_to_invoice']??0);$alertPercent=(float)($alert['collection_percent']??0); ?>
                  <article class="rounded-2xl border border-amber-100 bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><strong class="block text-sm text-slate-950"><?=htmlspecialchars((string)($alert['numero_devis']?:$alert['reference_offre']))?></strong><h3 class="mt-1 line-clamp-2 text-sm font-bold text-indigo-800"><?=htmlspecialchars((string)($alert['project_title']??'Projet à confirmer'))?></h3></div><time class="shrink-0 rounded-lg bg-amber-50 px-2 py-1 text-[11px] font-bold text-amber-800"><?=date('d/m/Y',strtotime((string)$alert['billing_date']))?></time></div>
                    <p class="mt-2 truncate text-xs text-slate-500" title="<?=htmlspecialchars((string)($alert['nom_client']??''))?>"><i class="fas fa-building mr-1 text-slate-400"></i><?=htmlspecialchars((string)($alert['nom_client']?:'Client à confirmer'))?></p>
                    <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-slate-100"><span class="block h-full rounded-full bg-emerald-500" style="width:<?=max(0,min(100,$alertPercent))?>%"></span></div>
                    <div class="mt-3 grid grid-cols-3 gap-2 text-[11px]"><div><span class="block text-slate-500">Déjà perçu</span><strong class="mt-0.5 block text-emerald-700"><?=number_format($alertCollected,0,',',' ')?> F</strong></div><div><span class="block text-slate-500">À facturer</span><strong class="mt-0.5 block text-amber-700"><?=number_format($alertRemaining,0,',',' ')?> F</strong></div><div class="text-right"><span class="block text-slate-500">Total devis</span><strong class="mt-0.5 block text-slate-900"><?=number_format($alertTotal,0,',',' ')?> F</strong></div></div>
                  </article>
                <?php endforeach; ?></div>
            </section>
        <?php endif; ?>

        <?php if (!$model->isReady()): ?>
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <?= htmlspecialchars($model->getInitError() ?: 'Le module factures de vente n\'a pas pu initialiser ses tables.') ?>
            </div>
        <?php endif; ?>

        <div id="nouvelle-facture" class="fv-modal" role="dialog" aria-modal="true" aria-labelledby="invoiceFormTitle">
          <div class="fv-modal-panel p-5 md:p-7">
            <div class="flex items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-wider text-indigo-700">Finéa · Vente</p><h2 id="invoiceFormTitle" class="mt-1 text-2xl font-semibold text-gray-900">Nouvelle facture de vente</h2></div><button type="button" data-close-invoice-form class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 text-slate-700" aria-label="Fermer"><i class="fas fa-times"></i></button></div>
            <div class="mt-2 text-sm font-medium text-gray-600"><span class="font-black text-rose-600" aria-hidden="true">*</span> Champ obligatoire</div>
            <form method="post" enctype="multipart/form-data" class="mt-4 space-y-5">
                <input type="hidden" name="fne_import_id" value="">
                <input type="hidden" name="fne_invoice_id" value="">
                <div class="relative rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 to-indigo-50 p-4">
                    <div class="flex flex-wrap items-end gap-3"><label class="min-w-64 flex-1 text-sm font-bold text-slate-800">Préremplir depuis une facture importée de la FNE<input id="fneImportedInvoiceSearch" type="search" autocomplete="off" placeholder="Client, NCC, référence, token, montant, date…" class="mt-2 w-full rounded-xl border border-amber-300 bg-white px-4 py-3"></label><a href="import_factures_vente_fne.php" class="rounded-xl bg-slate-950 px-4 py-3 text-xs font-bold text-white">Importer un lot</a></div>
                    <div id="fneImportedInvoiceResults" class="absolute left-4 right-4 z-50 mt-2 hidden max-h-80 overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl"></div>
                    <p id="fneImportedInvoiceStatus" class="mt-2 text-xs text-slate-600">La recherche porte sur toutes les colonnes du fichier DGI.</p>
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
                                <option value="emise" selected>Émise / à percevoir</option>
                                <option value="payee">Payée / encaissée</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date d’encaissement prévue <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                            <input type="date" name="expected_collection_date" value="<?=date('Y-m-d')?>" required class="js-collection-date mt-1 w-full rounded-xl border border-indigo-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                            <p class="mt-1 text-xs text-slate-500">Elle devient la date d’encaissement lorsque la facture est marquée payée.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Chantier lié <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                            <div class="js-chantier-combobox relative mt-1">
                                <input type="search" class="js-chantier-input w-full rounded-xl border border-indigo-200 bg-white px-4 py-3 pr-12 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-4 focus:ring-indigo-100" placeholder="Saisir un code, un chantier ou un client…" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" required>
                                <button type="button" class="js-chantier-toggle absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-500" aria-label="Afficher les chantiers"><i class="fas fa-chevron-down text-xs"></i></button>
                                <select name="chantier_id" class="js-chantier-select absolute h-px w-px overflow-hidden opacity-0" tabindex="-1" aria-hidden="true">
                                    <option value="">Sélectionner un chantier</option>
                                    <?php foreach ($chantiers as $c): ?>
                                        <?php $internalBureau=!empty($c['is_internal_bureau']); ?>
                                        <option value="<?= (int)($c['id'] ?? 0) ?>" data-client-po-id="<?= (int)($c['client_purchase_order_id'] ?? 0) ?>" <?=$internalBureau?'disabled data-internal="1"':''?> <?=((int)($c['id']??0)===$prefillChantierId&&!$internalBureau)?'selected':''?>><?= htmlspecialchars(trim((string)($c['code'] ?? '') . ' - ' . (string)($c['titre'] ?? '') . ' - ' . (string)($c['nom_client'] ?? '') . ($internalBureau?' · ACTIVITÉ INTERNE NON FACTURABLE':'')), ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="js-chantier-results absolute z-50 mt-2 hidden max-h-72 w-full overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl"></div>
                            </div>
                            <input type="hidden" name="client_purchase_order_id" id="clientPurchaseOrderId" value="">
                            <p class="mt-1 text-xs text-slate-500">La facture sera automatiquement rattachée au BC client du chantier.</p>
                            <p class="mt-1 text-xs font-semibold text-amber-700">Le Bureau BANAMUR/FIDEST relève du suivi des charges internes et ne peut pas recevoir de facture de vente.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date de facturation <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                            <input type="date" name="invoice_date" value="<?=date('Y-m-d')?>" required class="js-invoice-date mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2">
                            <p class="mt-1 text-xs text-slate-500">Saisie libre : date passée, présente ou future.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Référence interne</label>
                            <input name="reference_interne" readonly placeholder="Auto (ex: FV-YYYYMM-000001)" class="mt-1 w-full rounded-xl border border-gray-200 bg-gray-100 px-3 py-2 text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            <div class="mt-1 text-xs text-gray-500">Générée automatiquement à la création.</div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Nature facture travaux <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                            <select name="facture_travaux_type" required data-placeholder="Rechercher une nature de facture…" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="avance">Avance / démarrage</option>
                                <option value="situation" selected>Situation / avancement</option>
                                <option value="finale">Facture finale</option>
                                <option value="complement">Complément</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Avancement facturé (%)</label>
                            <input name="avancement_percent" type="number" min="0" max="100" step="0.01" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Ex: 30" />
                        </div>
                    </div>
                </div>

                <details class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3">
                        <span>
                            <span class="block text-sm font-semibold text-gray-900">Paramètres FNE optionnels</span>
                            <span class="text-xs text-gray-500">Ouvrir uniquement pour renseigner les options fiscales DGI.</span>
                        </span>
                        <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-gray-600">Déplier</span>
                    </summary>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Mode de paiement</label>
                            <select name="payment_method" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="cash" selected>Espèces</option>
                                <option value="card">Carte bancaire</option>
                                <option value="check">Chèque</option>
                                <option value="mobile-money">Mobile money</option>
                                <option value="transfer">Virement bancaire</option>
                                <option value="deferred">Paiement différé / crédit client</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Profil fiscal FNE</label>
                            <select name="template" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="B2F" selected>B2F (client étranger)</option>
                                <option value="B2B">B2B (entreprise avec NCC)</option>
                                <option value="B2G">B2G (administration / marché public)</option>
                                <option value="B2C">B2C (particulier)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Client NCC (si B2B)</label>
                            <input name="client_ncc" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">RNE</label>
                            <input name="rne" class="js-rne-input mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-600">
                                <input type="checkbox" name="is_rne" value="1" class="js-is-rne rounded border-gray-300" />
                                Facture liée à un RNE
                            </label>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">N° facture FNE</label>
                            <input name="fne_invoice_number" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Code vérification FNE</label>
                            <div class="mt-1 flex gap-2"><input name="fne_verification_code" id="saleFneCode" class="min-w-0 flex-1 rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" /><button type="button" id="saleFneLookup" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white">Récupérer</button></div>
                        </div>
                        <div id="saleFneLookupMessage" class="hidden md:col-span-2 rounded-xl border px-4 py-3 text-sm"></div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700">Facture physique PDF/image</label>
                            <input type="file" name="facture_file" accept="application/pdf,image/*" class="mt-1 w-full rounded-xl border border-dashed border-gray-300 bg-white px-3 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            <div class="mt-1 text-xs text-gray-500">Facultatif — le fichier pourra être ajouté ultérieurement.</div>
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
                            <input name="client_company_name" list="clientOptions" data-finea-search  class="js-client-search mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Saisir le nom du client..." autocomplete="off" />
                            <datalist id="clientOptions">
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= htmlspecialchars((string)($client['nom_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Client téléphone <span class="font-black text-rose-600" aria-label="champ obligatoire">*</span></label>
                            <input name="client_phone"  class="js-client-phone mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Client email</label>
                            <input name="client_email" type="email" class="js-client-email mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Non renseigné dans la fiche client" />
                            <div class="mt-1 text-xs text-gray-500">Renseigné automatiquement si disponible sur la fiche client.</div>
                        </div>
                    </div>
                </div>

                <input type="hidden" name="point_of_sale" value="CHANTIER" />
                <input type="hidden" name="establishment" value="BANAMUR BTP" />

                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-gray-900">Remises & devises</h3>
                        <div class="text-xs text-gray-500">Discount + devise étrangère</div>
                    </div>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Remise globale</label>
                            <input name="discount" type="number" step="0.01" value="0" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <label class="rounded-xl border border-amber-200 bg-amber-50 p-3"><span class="flex items-center gap-2 font-semibold text-amber-900"><input type="checkbox" name="tax_not_invoiced" value="1" class="js-tax-not-invoiced"> TVA non facturée · opération exonérée</span><span class="mt-1 block text-xs text-amber-800">TVA informative uniquement : ni encaissée, ni reversée, ni déduite de la trésorerie.</span></label>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Devise</label>
                            <input name="foreign_currency" list="currencyOptions" data-finea-search value="<?=htmlspecialchars((string)$defaultCurrency['code'])?>" class="js-currency mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" autocomplete="off" />
                            <datalist id="currencyOptions">
                                <?php foreach ($currencyOptions as $code => $label): ?>
                                    <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="mt-1 text-xs text-gray-500">XOF / FCFA par défaut. Le taux est récupéré automatiquement si possible.</div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Taux devise étrangère</label>
                            <input name="foreign_currency_rate" type="number" step="0.000001" value="<?=htmlspecialchars((string)$defaultCurrency['rate_to_xof'])?>" readonly class="js-currency-rate mt-1 w-full rounded-xl border border-gray-200 bg-slate-100 px-3 py-2" />
                            <div class="js-currency-rate-status mt-1 text-xs text-gray-500">Taux paramétré : 1 devise = taux × XOF.</div>
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
                                    <tr>
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
                                            <input name="items_tax_rate[]" type="number" step="0.000001" min="0" max="1" value="<?= htmlspecialchars((string)($taxRates['TVA'] ?? 0.18), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs focus:outline-none focus:ring-2 focus:ring-indigo-200" title="Taux taxe modifiable" />
                                        </td>
                                        <td class="py-2 pr-3 text-right">
                                            <div class="font-medium text-gray-900 jsLineTotal">0,00</div>
                                            <div class="text-xs text-gray-500 jsLineBreakdown">HT 0,00 • Taxe 0,00</div>
                                        </td>
                                        <td class="py-2">
                                            <button type="button" class="jsRemoveItemRow px-3 py-2 rounded-xl border border-gray-200 bg-white text-gray-700 hover:bg-gray-50" title="Supprimer">×</button>
                                        </td>
                                    </tr>
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

                <input type="hidden" name="invoice_type" value="sale" />
                <div class="flex items-center justify-end rounded-2xl border border-gray-200 bg-white p-4">
                    <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700">Créer</button>
                </div>
            </form>
          </div>
        </div>

        <section class="fv-main-grid">
          <main class="fv-main-column">
        <div class="fv-projects bg-white border border-gray-200 rounded-[30px] p-6 mb-6 shadow-[0_18px_55px_rgba(15,23,42,.08)]">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">Suivi facturation par chantier</h2>
                <div class="text-xs text-gray-500">Devis - encaissé - décaissé - reste à percevoir</div>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="py-2 pr-4">Chantier</th>
                            <th class="py-2 pr-4">Client</th>
                            <th class="py-2 pr-4 text-right">Devis</th>
                            <th class="py-2 pr-4 text-right">Perçu</th>
                            <th class="py-2 pr-4 text-right">Décaissé</th>
                            <th class="py-2 pr-4 text-right">Reste à percevoir</th>
                            <th class="py-2 pr-4 text-right">Factures émises non payées</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach (array_slice($chantierSummary, 0, 20) as $row): ?>
                            <?php
                            $devisTotal = (float)($row['devis_total'] ?? 0);
                            $percu = (float)($row['montant_percu'] ?? 0);
                            $decaisse = (float)($row['montant_decaisse'] ?? 0);
                            $reste = max(0, $devisTotal - $percu);
                            ?>
                            <tr>
                                <td class="py-2 pr-4 font-medium"><?= htmlspecialchars(trim((string)($row['chantier_code'] ?? '') . ' - ' . (string)($row['chantier_titre'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-2 pr-4 text-gray-600"><?= htmlspecialchars((string)($row['nom_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-2 pr-4 text-right"><?= number_format($devisTotal, 0, ',', ' ') ?></td>
                                <td class="py-2 pr-4 text-right text-emerald-700 font-semibold"><?= number_format($percu, 0, ',', ' ') ?></td>
                                <td class="py-2 pr-4 text-right text-rose-700 font-semibold"><?= number_format($decaisse, 0, ',', ' ') ?></td>
                                <td class="py-2 pr-4 text-right"><?= number_format($reste, 0, ',', ' ') ?></td>
                                <td class="py-2 pr-4 text-right"><?= number_format((float)($row['montant_emis_non_paye'] ?? 0), 0, ',', ' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($chantierSummary)): ?>
                            <tr><td colspan="7" class="py-6 text-center text-gray-500">Aucun chantier à afficher.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="fv-register bg-white border border-gray-200 rounded-[30px] p-6 mb-6 shadow-[0_18px_55px_rgba(15,23,42,.08)]">
            <div class="flex items-center justify-between gap-3">
                <div><p class="text-xs font-semibold uppercase tracking-[.18em] text-indigo-700">Registre · <span id="fvCurrentView">Toutes les factures</span></p><h2 class="mt-1 text-2xl font-semibold text-gray-900">Dernières factures de vente</h2><p class="mt-1 text-sm text-slate-500">Client, chantier, encaissement et statut FNE en un coup d’œil.</p></div>
                <div id="fvVisibleCount" class="text-xs font-semibold text-gray-500"><?= count($factures) ?> résultat(s)</div>
            </div>

            <div class="mt-5 flex flex-col gap-3 rounded-2xl bg-slate-50 p-3 sm:flex-row">
                <label class="relative min-w-0 flex-1"><span class="sr-only">Rechercher une facture</span><i class="fas fa-magnifying-glass absolute left-3 top-3 text-slate-400"></i><input id="fvSearch" type="search" placeholder="Client, chantier ou référence…" class="w-full rounded-xl border border-slate-200 bg-white py-2.5 pl-10 pr-3 text-sm"></label>
                <select id="fvStatus" class="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm"><option value="">Tous les encaissements</option><option value="emise">À percevoir</option><option value="retard">En retard</option><option value="payee">Encaissées</option></select>
                <a data-fv-export="excel" href="request/export_sales_excel.php" class="inline-flex items-center justify-center rounded-xl border border-emerald-200 bg-white px-3 py-2.5 text-sm font-bold text-emerald-700" title="Exporter exactement les résultats affichés"><i class="fas fa-file-excel mr-2"></i>Excel</a>
                <a data-fv-export="pdf" href="request/export_sales_pdf.php" target="_blank" class="inline-flex items-center justify-center rounded-xl border border-rose-200 bg-white px-3 py-2.5 text-sm font-bold text-rose-700" title="Exporter exactement les résultats affichés"><i class="fas fa-file-pdf mr-2"></i>PDF</a>
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="py-2 pr-4">Client</th>
                            <th class="py-2 pr-4">Chantier</th>
                            <th class="py-2 pr-4">Nature</th>
                            <th class="py-2 pr-4">Réf interne</th>
                            <th class="py-2 pr-4">Montant</th>
                            <th class="py-2 pr-4">Template</th>
                            <th class="py-2 pr-4">Encaissement</th>
                            <th class="py-2 pr-4">FNE</th>
                            <th class="py-2">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php if (!$factures): ?>
                            <tr>
                                <td colspan="8" class="py-6 text-center text-gray-500">Aucune facture (ou table indisponible).</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($factures as $fv): ?>
                            <?php $fvPaid=(string)($fv['paiement_statut']??'emise')==='payee';$fvDue=substr((string)($fv['expected_collection_date']??''),0,10);$fvLate=!$fvPaid&&$fvDue!==''&&$fvDue<$todayIso;$fvSearch=mb_strtolower(trim((string)($fv['client_company_name']??'').' '.(string)($fv['chantier_code']??'').' '.(string)($fv['chantier_titre']??'').' '.(string)($fv['reference_interne']??'')),'UTF-8'); ?>
                            <tr class="fv-sale-row text-gray-800" data-search="<?=htmlspecialchars($fvSearch,ENT_QUOTES,'UTF-8')?>" data-status="<?=$fvPaid?'payee':($fvLate?'retard':'emise')?>" data-fne="<?=!empty($fv['fne_signed_at'])?'1':'0'?>">
                                <td class="py-2 pr-4 font-medium"><?= htmlspecialchars((string)($fv['client_company_name'] ?? '')) ?></td>
                                <td class="py-2 pr-4 text-gray-600"><?= htmlspecialchars(trim((string)($fv['chantier_code'] ?? '') . ' ' . (string)($fv['chantier_titre'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-2 pr-4 text-gray-600"><?= htmlspecialchars(ucfirst((string)($fv['facture_travaux_type'] ?? 'situation')), ENT_QUOTES, 'UTF-8') ?><?= isset($fv['avancement_percent']) && $fv['avancement_percent'] !== null ? ' (' . number_format((float)$fv['avancement_percent'], 2, ',', ' ') . '%)' : '' ?></td>
                                <td class="py-2 pr-4 text-gray-600"><?= htmlspecialchars((string)($fv['reference_interne'] ?? '—')) ?></td>
                                <td class="py-2 pr-4 text-gray-600"><?= number_format((float)($fv['total_ttc'] ?? 0), 0, ',', ' ') ?> CFA</td>
                                <td class="py-2 pr-4 text-gray-600"><?= htmlspecialchars((string)($fv['template'] ?? '')) ?></td>
                                <td class="py-2 pr-4"><?php if($fvPaid):?><span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-700">Encaissée</span><?php elseif($fvLate):?><span class="rounded-full bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700">En retard</span><?php else:?><span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700">À percevoir</span><?php endif;?></td>
                                <td class="py-2 pr-4 text-gray-600">
                                    <?php if (!empty($fv['fne_signed_at'])): ?>
                                        <div class="text-xs"><?= htmlspecialchars((string)$fv['fne_signed_at']) ?></div>
                                        <div class="text-xs"><?= htmlspecialchars((string)($fv['fne_invoice_id'] ?? '')) ?></div>
                                    <?php else: ?>
                                        <div class="text-xs text-gray-400">—</div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2">
                                    <div class="flex gap-3"><a class="font-semibold text-indigo-700 hover:underline" href="edit_facture_vente.php?id=<?= (int)$fv['id_facture_vente'] ?>">Éditer</a><a class="font-semibold text-emerald-700 hover:underline" href="visualiser_facture_vente.php?id=<?= (int)$fv['id_facture_vente'] ?>" target="_blank">Visualiser</a></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
          </main>
          <aside class="fv-aside flex flex-col gap-5" aria-label="Accès rapides de facturation">
            <button type="button" data-fv-card-filter="emise" class="order-first rounded-[30px] border border-amber-300 bg-gradient-to-br from-amber-50 to-white p-6 text-left shadow-[0_20px_55px_rgba(180,83,9,.15)] transition hover:-translate-y-0.5 hover:shadow-xl"><span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-500 text-xl text-white"><i class="fas fa-coins"></i></span><p class="mt-4 text-xs font-bold uppercase tracking-[.18em] text-amber-700">À encaisser</p><strong class="mt-2 block text-3xl text-slate-950"><?=number_format($salesStats['receivable'],0,',',' ')?> FCFA</strong><p class="mt-2 text-sm font-semibold text-slate-600"><?=$salesStats['emise']?> facture(s) à percevoir, dont <span class="text-rose-700"><?=$salesStats['retard']?> en retard</span>.</p><span class="mt-4 inline-flex items-center text-sm font-bold text-indigo-700">Afficher les factures <i class="fas fa-arrow-right ml-2"></i></span></button>
            <article class="rounded-[30px] bg-gradient-to-br from-slate-950 via-[#1d2b57] to-indigo-950 p-6 text-white shadow-[0_22px_60px_rgba(15,23,42,.24)]">
              <p class="text-xs font-semibold uppercase tracking-[.22em] text-amber-200">Action principale</p><h2 class="mt-2 text-xl font-semibold">Émettre une facture</h2><p class="mt-3 text-sm leading-6 text-slate-200">Créer une facture liée à son chantier, suivre son encaissement puis la signer via la FNE.</p><button type="button" data-open-invoice-form class="mt-5 inline-flex w-full items-center justify-center rounded-2xl bg-[#fabd02] px-4 py-3 text-sm font-bold text-slate-950"><i class="fas fa-plus mr-2"></i>Nouvelle facture</button>
            </article>
            <article class="rounded-[30px] border border-white/70 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,.08)]"><p class="text-xs font-semibold uppercase tracking-[.18em] text-slate-500">Accès rapides</p><nav class="mt-4 space-y-2">
              <a href="import_factures_vente_fne.php" class="flex items-center justify-between rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-slate-800"><span><i class="fas fa-cloud-arrow-down mr-2 text-amber-700"></i>Importer depuis FNE</span><i class="fas fa-chevron-right text-xs"></i></a>
              <a href="recherche_factures.php" class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800"><span><i class="fas fa-magnifying-glass mr-2 text-indigo-700"></i>Recherche avancée</span><i class="fas fa-chevron-right text-xs"></i></a>
              <a data-fv-export="excel" href="request/export_sales_excel.php" class="flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-slate-800"><span><i class="fas fa-file-excel mr-2 text-emerald-700"></i>Exporter Excel</span><i class="fas fa-download text-xs"></i></a>
              <a data-fv-export="pdf" href="request/export_sales_pdf.php" target="_blank" class="flex items-center justify-between rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-slate-800"><span><i class="fas fa-file-pdf mr-2 text-rose-700"></i>Exporter PDF</span><i class="fas fa-arrow-up-right-from-square text-xs"></i></a>
              <a href="factures_achat.php" class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-800"><span><i class="fas fa-file-invoice-dollar mr-2 text-rose-700"></i>Factures d’achat</span><i class="fas fa-chevron-right text-xs"></i></a>
              <a href="tresorerie_chantiers.php" class="flex items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-slate-800"><span><i class="fas fa-vault mr-2 text-emerald-700"></i>Trésorerie</span><i class="fas fa-arrow-up-right-from-square text-xs"></i></a>
            </nav></article>
          </aside>
        </section>

    </div>
</div>
<script>
(()=>{
 const search=document.getElementById('fvSearch'),status=document.getElementById('fvStatus'),rows=[...document.querySelectorAll('.fv-sale-row')],exportsExcel=[...document.querySelectorAll('[data-fv-export="excel"]')],exportsPdf=[...document.querySelectorAll('[data-fv-export="pdf"]')],register=document.querySelector('.fv-register'),view=document.getElementById('fvCurrentView'),count=document.getElementById('fvVisibleCount');
 if(!search||!status)return;let special='';
 const normalize=value=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
 const labels={'':'Toutes les factures',emise:'Factures à percevoir',retard:'Factures en retard',payee:'Factures encaissées',fne:'Factures signées FNE'};
 const filter=()=>{const q=normalize(search.value),state=special||status.value;let visible=0;rows.forEach(row=>{const stateOk=!state||(state==='emise'&&row.dataset.status!=='payee')||(state==='fne'&&row.dataset.fne==='1')||row.dataset.status===state;row.hidden=!!((q&&!normalize(row.dataset.search).includes(q))||!stateOk);if(!row.hidden)visible++});if(view)view.textContent=labels[state]||'Résultats filtrés';if(count)count.textContent=visible+' résultat(s)';const params=new URLSearchParams();if(search.value.trim())params.set('q',search.value.trim());if(state)params.set('status',state);const suffix=params.toString()?'?'+params.toString():'';exportsExcel.forEach(link=>link.href='request/export_sales_excel.php'+suffix);exportsPdf.forEach(link=>link.href='request/export_sales_pdf.php'+suffix)};
 search.addEventListener('input',filter);status.addEventListener('change',()=>{special='';filter()});
 document.querySelectorAll('[data-fv-card-filter]').forEach(card=>card.addEventListener('click',()=>{search.value='';special=card.dataset.fvCardFilter||'';if(special==='fne'){status.value=''}else status.value=special;document.querySelectorAll('[data-fv-card-filter]').forEach(item=>item.classList.toggle('ring-4',item===card));filter();register?.scrollIntoView({behavior:'smooth',block:'start'})}));
})();
(()=>{const modal=document.getElementById('nouvelle-facture');if(!modal)return;const open=()=>{modal.classList.add('is-open');document.body.classList.add('fv-modal-open')};const close=()=>{modal.classList.remove('is-open');document.body.classList.remove('fv-modal-open')};document.querySelectorAll('[data-open-invoice-form]').forEach(button=>button.addEventListener('click',open));modal.querySelectorAll('[data-close-invoice-form]').forEach(button=>button.addEventListener('click',close));modal.addEventListener('click',event=>{if(event.target===modal)close()});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&modal.classList.contains('is-open'))close()});if(location.hash==='#nouvelle-facture')open()})();
(()=>{const date=document.querySelector('[name="invoice_date"]'),collection=document.querySelector('[name="expected_collection_date"]'),file=document.querySelector('[name="facture_file"]');[date,collection].forEach(field=>{field?.removeAttribute('min');field?.removeAttribute('max')});file?.addEventListener('change',()=>{const name=file.files?.[0]?.name;if(name)file.setAttribute('title',name)});})();
(()=>{
    const input=document.getElementById('fneImportedInvoiceSearch');
    const results=document.getElementById('fneImportedInvoiceResults');
    const status=document.getElementById('fneImportedInvoiceStatus');
    const form=input?.closest('form');
    if(!input||!results||!status||!form)return;
    let timer=0,request=null;
    const money=value=>Number(value||0).toLocaleString('fr-FR',{maximumFractionDigits:2});
    const set=(name,value,allowEmpty=false)=>{
        const field=form.querySelector(`[name="${name}"]`);
        if(!field||(!allowEmpty&&(value===null||value===undefined||String(value)==='')))return;
        if(field.type==='checkbox')field.checked=!!Number(value);
        else field.value=String(value??'');
        field.dispatchEvent(new Event('input',{bubbles:true}));
        field.dispatchEvent(new Event('change',{bubbles:true}));
    };
    const payment=value=>{
        const normalized=String(value||'').toLowerCase().replace(/[_ ]/g,'-');
        return ({cash:'cash',card:'card',check:'check',cheque:'check','mobile-money':'mobile-money',mobilemoney:'mobile-money',transfer:'transfer',virement:'transfer',deferred:'deferred'})[normalized]||'cash';
    };
    const choose=row=>{
        if(row.finea_invoice_id){status.textContent=`Déjà liée à ${row.finea_reference||'une facture Finéa'}.`;return;}
        set('fne_import_id',row.id);set('fne_invoice_id',row.token);
        set('fne_invoice_number',row.fne_reference);
        set('fne_verification_code',row.fne_reference);
        set('invoice_date',String(row.invoice_date||'').slice(0,10));
        set('payment_method',payment(row.payment_method));
        set('template',['B2B','B2G','B2C','B2F'].includes(String(row.billing_type||'').toUpperCase())?String(row.billing_type).toUpperCase():'B2B');
        set('client_ncc',row.client_ncc);set('rne',row.rne);set('is_rne',row.is_rne);
        set('client_company_name',row.client_name);
        set('client_phone',row.client_phone);
        set('client_email',row.client_email,'');
        set('point_of_sale',row.point_of_sale);set('establishment',row.establishment);
        set('discount',Number(row.discount_amount||0));
        if(row.foreign_currency)set('foreign_currency',row.foreign_currency);
        const tbody=document.getElementById('itemsTbody'),line=tbody?.querySelector('tr');
        const ht=Number(row.total_ht||0),discount=Number(row.discount_amount||0),vat=Number(row.total_vat||0);
        set('tax_not_invoiced',vat<=0?1:0);
        if(line){
            const description=[row.invoice_subtype||row.invoice_type,'FNE '+(row.fne_reference||''),row.client_name].filter(Boolean).join(' · ');
            const values={
                'items_description[]':description,
                'items_quantity[]':1,
                'items_amount[]':Math.max(0,ht+discount),
                'items_tax[]':'TVA',
                'items_tax_rate[]':ht>0?vat/ht:0
            };
            Object.entries(values).forEach(([name,value])=>{const field=line.querySelector(`[name="${name}"]`);if(field){field.value=String(value);field.dispatchEvent(new Event('input',{bubbles:true}));}});
        }
        input.value=[row.fne_reference,row.client_name].filter(Boolean).join(' — ');
        results.classList.add('hidden');
        status.className='mt-2 text-xs font-bold text-emerald-700';
        status.textContent=`Facture FNE sélectionnée · ${money(row.net_payable)} FCFA · vérifiez le chantier, le libellé et l’état d’encaissement avant d’enregistrer.`;
    };
    const render=items=>{
        results.innerHTML='';
        if(!items.length){results.innerHTML='<div class="p-4 text-sm text-slate-500">Aucune facture FNE trouvée.</div>';}
        items.forEach(row=>{
            const button=document.createElement('button');
            button.type='button';button.disabled=!!row.finea_invoice_id;
            button.className='mb-1 flex w-full items-center justify-between gap-4 rounded-xl p-3 text-left hover:bg-indigo-50 disabled:cursor-not-allowed disabled:opacity-50';
            button.innerHTML=`<span><b class="block text-sm text-slate-900"></b><span class="block text-xs text-slate-500"></span></span><span class="text-right"><b class="block text-sm text-indigo-700"></b><span class="block text-[11px] text-slate-500"></span></span>`;
            button.children[0].children[0].textContent=row.client_name||'Client non renseigné';
            button.children[0].children[1].textContent=[row.client_ncc,row.fne_reference,String(row.invoice_date||'').slice(0,10)].filter(Boolean).join(' · ');
            button.children[1].children[0].textContent=money(row.net_payable)+' FCFA';
            button.children[1].children[1].textContent=row.finea_invoice_id?'Déjà liée · '+(row.finea_reference||'Finéa'):(row.payment_method||'Mode non renseigné');
            button.addEventListener('click',()=>choose(row));results.appendChild(button);
        });
        results.classList.remove('hidden');
    };
    input.addEventListener('input',()=>{
        clearTimeout(timer);request?.abort();
        const q=input.value.trim();
        if(q.length<2){results.classList.add('hidden');status.textContent='Saisissez au moins 2 caractères. La recherche porte sur toutes les colonnes du fichier DGI.';return;}
        timer=setTimeout(async()=>{
            request=new AbortController();status.textContent='Recherche dans le lot FNE…';
            try{
                const response=await fetch('api/fne_sales_import_search.php?q='+encodeURIComponent(q),{signal:request.signal});
                const json=await response.json();if(!json.ok)throw Error(json.error||'Recherche indisponible.');
                render(json.items||[]);status.textContent=`${(json.items||[]).length} résultat(s) · les lignes déjà liées sont protégées.`;
            }catch(error){if(error.name!=='AbortError')status.textContent=error.message;}
        },250);
    });
    document.addEventListener('click',event=>{if(!results.contains(event.target)&&event.target!==input)results.classList.add('hidden');});
})();
(()=>{const button=document.getElementById('saleFneLookup');if(!button)return;const form=button.closest('form'),message=document.getElementById('saleFneLookupMessage');const set=(name,value)=>{const field=form.querySelector(`[name="${name}"]`);if(field&&value!==null&&value!==undefined&&String(value)!=='')field.value=value};button.addEventListener('click',async()=>{message.className='md:col-span-2 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800';message.textContent='Interrogation de l’API FNE…';try{const code=document.getElementById('saleFneCode').value.trim();const response=await fetch('api/fne_lookup.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':<?= json_encode((string) $_SESSION['fne_lookup_csrf']) ?>},body:JSON.stringify({code,code_type:'verificationCode'})});const json=await response.json();if(!json.ok)throw Error(json.error||'Facture introuvable.');const invoice=json.data.invoice||{};set('fne_invoice_number',invoice.invoice_number);set('fne_verification_code',invoice.verification_code||code);set('client_company_name',invoice.client_name);set('client_phone',invoice.client_phone);set('client_email',invoice.client_email);set('client_ncc',invoice.client_ncc);set('payment_method',invoice.payment_method);set('foreign_currency',invoice.currency);message.className='md:col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800';message.textContent=`Facture FNE vérifiée et informations récupérées${invoice.total_amount!==null&&invoice.total_amount!==undefined?' · '+Number(invoice.total_amount).toLocaleString('fr-FR')+' '+(invoice.currency||'FCFA'):''}. Vérifiez les lignes avant enregistrement.`}catch(error){message.className='md:col-span-2 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800';message.textContent=error.message}})})();
</script>

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
                // garder au moins une ligne
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
                'country'=>(string)($client['fiscal_country']??'CI'), 'ncc'=>(string)($client['ncc']??''),
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
            const foreign=found.country!=='CI';
            const template=clientInput.form?.querySelector('[name=template]'), ncc=clientInput.form?.querySelector('[name=client_ncc]');
            if(template)template.value=foreign?'B2F':'B2B';
            if(ncc)ncc.value=foreign?'':found.ncc;
            if(phoneInput)phoneInput.required=!foreign;
            if(emailInput)emailInput.required=!foreign;
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
            if(rateStatus)rateStatus.textContent=rate>0?`1 ${code} = ${rate.toLocaleString('fr-FR',{maximumFractionDigits:6})} XOF`:'Devise inactive ou non paramétrée.';
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
    })();
</script>

<script src="assets/chantier-combobox.js"></script>
<script src="assets/finea-search-combobox.js"></script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>

