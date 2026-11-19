<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/TaxRates.php';

$pdo = Database::getConnection();

$flashSuccess = '';
$flashError = '';

$defaultTaxRates = [
    'TVA' => 0.18,
    'TVAB' => 0.00,
    'TVAC' => 0.00,
    'TVAD' => 0.00,
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_tax_rates') {
        $incoming = $_POST['tax_rates'] ?? [];
        if (!is_array($incoming)) {
            $flashError = 'Taux invalides.';
        } else {
            $ratesToSave = [];
            foreach ($defaultTaxRates as $code => $_) {
                $raw = $incoming[$code] ?? null;
                $raw = is_string($raw) ? trim($raw) : (is_numeric($raw) ? (string)$raw : '');
                $raw = str_replace(',', '.', $raw);

                if ($raw === '' || !is_numeric($raw)) {
                    $flashError = 'Chaque taux doit être numérique (ex: 0.18).';
                    break;
                }

                $rate = (float)$raw;
                if ($rate < 0 || $rate > 1.0) {
                    $flashError = 'Chaque taux doit être compris entre 0 et 1 (ex: 0.18).';
                    break;
                }

                $ratesToSave[$code] = $rate;
            }

            if ($flashError === '') {
                try {
                    TaxRates::setRates($pdo, $ratesToSave);
                    \AuditTrail::log('admin_fne_tax_rates_saved', [
                        'module' => 'admin',
                        'action' => 'save_tax_rates',
                        'entity_type' => 'tax_rates',
                        'entity_label' => 'fne_tools_tax_rates',
                        'user_id' => $_SESSION['user_id'] ?? null,
                        'user_name' => $_SESSION['user_name'] ?? null,
                        'details' => [
                            'codes' => array_keys($ratesToSave),
                            'rates' => $ratesToSave,
                        ],
                    ], $pdo);
                    $flashSuccess = 'Taux de taxes enregistrés.';
                } catch (Throwable $e) {
                    \AuditTrail::log('admin_fne_tax_rates_save_failed', [
                        'module' => 'admin',
                        'action' => 'save_tax_rates',
                        'entity_type' => 'tax_rates',
                        'entity_label' => 'fne_tools_tax_rates',
                        'user_id' => $_SESSION['user_id'] ?? null,
                        'user_name' => $_SESSION['user_name'] ?? null,
                        'result' => 'failure',
                        'severity' => 'warning',
                        'details' => ['error' => $e->getMessage()],
                    ], $pdo);
                    $flashError = 'Impossible d\'enregistrer les taux (erreur SQL).';
                }
            }
        }
    }
}

$taxRates = [];
try {
    $taxRates = TaxRates::getRates($pdo, $defaultTaxRates);
} catch (Throwable $e) {
    $taxRates = $defaultTaxRates;
}

$testFactureVenteId = (int)($_GET['test_facture_vente_id'] ?? 0);
$testFactureVenteRow = null;
$testFactureVenteError = '';
$testFactureVenteItemsCount = null;
$testFactureVenteJsonPretty = '';

if ($testFactureVenteId > 0) {
    try {
        $st = $pdo->prepare('SELECT id_facture_vente, client_company_name, statut, items_json, updated_at FROM facture_vente_banamur WHERE id_facture_vente = :id LIMIT 1');
        $st->execute(['id' => $testFactureVenteId]);
        $testFactureVenteRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$testFactureVenteRow) {
            $testFactureVenteError = 'Aucune facture de vente trouvée pour cet ID.';
        } else {
            $raw = (string)($testFactureVenteRow['items_json'] ?? '');
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
                $decoded = $decoded['items'];
            }
            if (!is_array($decoded)) {
                $testFactureVenteError = 'items_json invalide (JSON non décodable).';
            } else {
                $testFactureVenteItemsCount = count($decoded);
                $testFactureVenteJsonPretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
            }
        }
    } catch (Throwable $e) {
        $testFactureVenteError = 'Impossible de charger la facture (erreur SQL).';
        $testFactureVenteRow = null;
    }
}

$fneConfigPath = __DIR__ . '/../config/fne.php';
$fneConfig = [];
if (is_file($fneConfigPath)) {
    $loaded = require $fneConfigPath;
    if (is_array($loaded)) {
        $fneConfig = $loaded;
    }
}

$fneBaseUrl = (string)($fneConfig['base_url'] ?? '');
$fneConfigured = !empty($fneConfig['api_key'] ?? '');

$exampleSignPayload = [
    'invoiceType' => 'sale',
    'paymentMethod' => 'cash',
    'template' => 'B2F',
    'isRne' => false,
    'clientCompanyName' => 'CLIENT TEST',
    'clientPhone' => '+22500000000',
    'clientEmail' => 'client@example.com',
    'pointOfSale' => 'POS-1',
    'establishment' => 'ETAB-1',
    'discount' => 0,
    'items' => [
        [
            'description' => 'Prestation test',
            'quantity' => 1,
            'amount' => 1000,
            'taxes' => ['TVA'],
        ],
    ],
];

$exampleRefundPayload = [
    'invoice_id' => 'INVOICE_ID_FNE',
    'items' => [
        [
            'id' => 'ITEM_ID_FNE',
            'quantity' => 1,
        ],
    ],
];

$exampleVerifyPayload = [
    'invoiceNumber' => 'FNE123456789',
    'amount' => 10000,
    'date' => date('Y-m-d'),
];

$exampleDecaissementVerify = [
    'num_fiche' => 'FD-XXXX-XXXXX',
    'verify' => $exampleVerifyPayload,
    'label_match_threshold' => 0.55,
    'block_on_label_mismatch' => false,
];

$exampleSignJson = json_encode($exampleSignPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "{}";
$exampleRefundJson = json_encode($exampleRefundPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "{}";
$exampleVerifyJson = json_encode($exampleVerifyPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "{}";
$exampleDecVerifyJson = json_encode($exampleDecaissementVerify, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "{}";

$pageTitle = 'Outils FNE (Admin)';
$activeMenu = '';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">
        <div class="text-center mb-10">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent drop-shadow-lg">
                Outils FNE (Admin)
            </h1>
            <p class="text-gray-600 mt-3">Tests techniques de l’intégration FNE (signature, avoir, vérification).</p>

            <div class="mt-6 flex flex-wrap justify-center gap-3">
                <a href="dashboard_admin.php" class="inline-flex items-center px-5 py-3 bg-gray-800 text-white rounded-xl hover:bg-gray-900 transition shadow-lg">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Admin
                </a>
                <a href="../selection_portail.php" class="inline-flex items-center px-5 py-3 bg-white border border-gray-200 text-gray-800 rounded-xl hover:bg-gray-50 transition shadow">
                    <i class="fas fa-th-large mr-2"></i>
                    Portail
                </a>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Configuration</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">État FNE</h2>
                </div>
                <div class="text-sm font-semibold text-slate-700">
                    <span class="mr-2">Base:</span>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"><?php echo htmlspecialchars($fneBaseUrl !== '' ? $fneBaseUrl : 'non renseignée'); ?></span>
                    <span class="ml-3 rounded-full px-3 py-1 text-xs font-semibold <?php echo $fneConfigured ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800'; ?>">
                        <?php echo $fneConfigured ? 'Configuré' : 'Clé API manquante (FNE_API_KEY)'; ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if ($flashSuccess): ?>
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <?php echo htmlspecialchars($flashSuccess); ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <?php echo htmlspecialchars($flashError); ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 grid gap-6 xl:grid-cols-2">
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Paramétrage</p>
                        <h3 class="mt-2 text-lg font-semibold text-slate-900">Taux de taxes (calcul Facturation)</h3>
                    </div>
                </div>

                <p class="mt-3 text-xs text-slate-500">Valeurs en décimal : <span class="font-semibold">0.18 = 18%</span>. Enregistré en BDD et lu via <code>config/taxes.php</code>.</p>

                <form method="post" class="mt-4 space-y-4">
                    <input type="hidden" name="action" value="save_tax_rates" />

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <?php foreach ($defaultTaxRates as $code => $_):
                            $val = $taxRates[$code] ?? $_;
                        ?>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700"><?php echo htmlspecialchars($code); ?></label>
                                <input name="tax_rates[<?php echo htmlspecialchars($code); ?>]" value="<?php echo htmlspecialchars((string)$val); ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-indigo-300 focus:outline-none" />
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="flex items-center justify-end">
                        <button class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/10 transition hover:bg-slate-900">
                            <i class="fas fa-floppy-disk mr-2"></i>
                            Enregistrer
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Diagnostic</p>
                        <h3 class="mt-2 text-lg font-semibold text-slate-900">Test JSON factures (vente)</h3>
                    </div>
                </div>

                <p class="mt-3 text-xs text-slate-500">Visualiser les <span class="font-semibold">items</span> générés sans exposer le JSON côté facturation.</p>

                <form method="get" class="mt-4 flex flex-col sm:flex-row sm:items-end gap-4">
                    <div class="w-full sm:w-64">
                        <label class="block text-sm font-semibold text-slate-700">ID facture vente</label>
                        <input type="number" min="1" name="test_facture_vente_id" value="<?php echo $testFactureVenteId > 0 ? (int)$testFactureVenteId : ''; ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-900 shadow-sm focus:border-indigo-300 focus:outline-none" placeholder="Ex: 123" />
                    </div>
                    <div>
                        <button class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50">
                            <i class="fas fa-eye mr-2"></i>
                            Visualiser
                        </button>
                    </div>
                </form>

                <?php if ($testFactureVenteError): ?>
                    <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                        <?php echo htmlspecialchars($testFactureVenteError); ?>
                    </div>
                <?php endif; ?>

                <?php if ($testFactureVenteRow && !$testFactureVenteError): ?>
                    <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs text-slate-500">Client</div>
                            <div class="mt-1 font-semibold text-slate-950"><?php echo htmlspecialchars((string)($testFactureVenteRow['client_company_name'] ?? '')); ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs text-slate-500">Statut</div>
                            <div class="mt-1 font-semibold text-slate-950"><?php echo htmlspecialchars((string)($testFactureVenteRow['statut'] ?? '')); ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs text-slate-500">Lignes</div>
                            <div class="mt-1 font-semibold text-slate-950"><?php echo $testFactureVenteItemsCount !== null ? (int)$testFactureVenteItemsCount : '—'; ?></div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="text-xs text-slate-500">Mis à jour</div>
                            <div class="mt-1 font-semibold text-slate-950"><?php echo htmlspecialchars((string)($testFactureVenteRow['updated_at'] ?? '')); ?></div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-semibold text-slate-700">items_json (lecture seule)</label>
                        <textarea rows="10" readonly class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-xs text-slate-800 shadow-sm focus:outline-none"><?php echo htmlspecialchars($testFactureVenteJsonPretty); ?></textarea>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-8 grid gap-6 xl:grid-cols-2">
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Signature</p>
                        <h3 class="mt-2 text-lg font-semibold text-slate-900">POST /api/fne_sign_invoice.php</h3>
                    </div>
                    <button id="fneSignBtn" type="button" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/10 transition hover:bg-slate-900">
                        <i class="fas fa-pen-nib mr-2"></i>
                        Signer
                    </button>
                </div>
                <label for="fneSignPayload" class="mt-4 block text-sm font-semibold text-slate-700">Payload JSON</label>
                <textarea id="fneSignPayload" rows="14" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-xs text-slate-800 shadow-sm focus:border-indigo-300 focus:outline-none"><?php echo htmlspecialchars($exampleSignJson, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Avoir</p>
                        <h3 class="mt-2 text-lg font-semibold text-slate-900">POST /api/fne_refund_invoice.php</h3>
                    </div>
                    <button id="fneRefundBtn" type="button" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-800 transition hover:bg-slate-50">
                        <i class="fas fa-rotate-left mr-2"></i>
                        Refund
                    </button>
                </div>
                <label for="fneRefundPayload" class="mt-4 block text-sm font-semibold text-slate-700">Payload JSON</label>
                <textarea id="fneRefundPayload" rows="14" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-xs text-slate-800 shadow-sm focus:border-indigo-300 focus:outline-none"><?php echo htmlspecialchars($exampleRefundJson, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Vérification facture (achat)</p>
                        <h3 class="mt-2 text-lg font-semibold text-slate-900">POST /api/fne_verify_invoice.php</h3>
                    </div>
                    <button id="fneVerifyBtn" type="button" class="inline-flex items-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-indigo-600/20 transition hover:bg-indigo-700">
                        <i class="fas fa-magnifying-glass mr-2"></i>
                        Vérifier
                    </button>
                </div>
                <label for="fneVerifyPayload" class="mt-4 block text-sm font-semibold text-slate-700">Payload JSON</label>
                <textarea id="fneVerifyPayload" rows="10" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-xs text-slate-800 shadow-sm focus:border-indigo-300 focus:outline-none"><?php echo htmlspecialchars($exampleVerifyJson, ENT_QUOTES, 'UTF-8'); ?></textarea>
                <p class="mt-2 text-xs text-slate-500">Tu peux aussi utiliser {"verificationCode": "..."} si tu as un code.</p>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Vérif décaissement (généalogie)</p>
                        <h3 class="mt-2 text-lg font-semibold text-slate-900">POST /api/fne_verify_decaissement.php</h3>
                    </div>
                    <button id="fneDecVerifyBtn" type="button" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-950/10 transition hover:bg-slate-900">
                        <i class="fas fa-shield-halved mr-2"></i>
                        Vérifier
                    </button>
                </div>
                <label for="fneDecVerifyPayload" class="mt-4 block text-sm font-semibold text-slate-700">Payload JSON</label>
                <textarea id="fneDecVerifyPayload" rows="10" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-xs text-slate-800 shadow-sm focus:border-indigo-300 focus:outline-none"><?php echo htmlspecialchars($exampleDecVerifyJson, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </div>

        <div class="mt-6 bg-slate-950 rounded-2xl shadow-xl p-6 border border-slate-900 text-white">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-200">Résultat</p>
                    <h3 class="mt-2 text-lg font-semibold">Réponse endpoint</h3>
                </div>
                <button id="fneResetBtn" type="button" class="inline-flex items-center rounded-2xl border border-white/15 px-4 py-2 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                    <i class="fas fa-rotate mr-2"></i>
                    Recharger exemples
                </button>
            </div>
            <pre id="fneResult" class="mt-4 max-h-[520px] overflow-auto rounded-2xl bg-black/25 p-4 text-xs leading-relaxed text-slate-100">Prêt.</pre>
        </div>
    </div>
</div>

<script>
    (function() {
        const resultEl = document.getElementById('fneResult');
        const signPayloadEl = document.getElementById('fneSignPayload');
        const refundPayloadEl = document.getElementById('fneRefundPayload');
        const verifyPayloadEl = document.getElementById('fneVerifyPayload');
        const decVerifyPayloadEl = document.getElementById('fneDecVerifyPayload');

        const signBtn = document.getElementById('fneSignBtn');
        const refundBtn = document.getElementById('fneRefundBtn');
        const verifyBtn = document.getElementById('fneVerifyBtn');
        const decVerifyBtn = document.getElementById('fneDecVerifyBtn');
        const resetBtn = document.getElementById('fneResetBtn');

        const DEFAULT_SIGN = <?php echo json_encode($exampleSignJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const DEFAULT_REFUND = <?php echo json_encode($exampleRefundJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const DEFAULT_VERIFY = <?php echo json_encode($exampleVerifyJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const DEFAULT_DEC_VERIFY = <?php echo json_encode($exampleDecVerifyJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        function show(obj) {
            if (!resultEl) return;
            if (typeof obj === 'string') {
                resultEl.textContent = obj;
                return;
            }
            try {
                resultEl.textContent = JSON.stringify(obj, null, 2);
            } catch (e) {
                resultEl.textContent = String(obj);
            }
        }

        function parseJson(el) {
            const raw = (el && el.value) ? el.value.trim() : '';
            if (!raw) throw new Error('JSON vide.');
            return JSON.parse(raw);
        }

        async function postJson(url, payload) {
            const resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            const text = await resp.text();
            let data = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch (e) {
                data = {
                    ok: false,
                    error: 'Réponse non-JSON',
                    raw: text
                };
            }
            return {
                status: resp.status,
                data
            };
        }

        function maybeRedirect(data) {
            if (data && data.redirect) {
                window.location.href = data.redirect;
                return true;
            }
            return false;
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function() {
                if (signPayloadEl) signPayloadEl.value = DEFAULT_SIGN || '';
                if (refundPayloadEl) refundPayloadEl.value = DEFAULT_REFUND || '';
                if (verifyPayloadEl) verifyPayloadEl.value = DEFAULT_VERIFY || '';
                if (decVerifyPayloadEl) decVerifyPayloadEl.value = DEFAULT_DEC_VERIFY || '';
                show('Exemples rechargés.');
            });
        }

        if (signBtn) {
            signBtn.addEventListener('click', async function() {
                try {
                    show('Envoi signature…');
                    const payload = parseJson(signPayloadEl);
                    const res = await postJson('../api/fne_sign_invoice.php', payload);
                    if (maybeRedirect(res.data)) return;
                    show({
                        endpoint: 'fne_sign_invoice',
                        httpStatus: res.status,
                        response: res.data
                    });
                } catch (e) {
                    show({
                        ok: false,
                        error: e && e.message ? e.message : String(e)
                    });
                }
            });
        }

        if (refundBtn) {
            refundBtn.addEventListener('click', async function() {
                try {
                    show('Envoi refund…');
                    const payload = parseJson(refundPayloadEl);
                    const res = await postJson('../api/fne_refund_invoice.php', payload);
                    if (maybeRedirect(res.data)) return;
                    show({
                        endpoint: 'fne_refund_invoice',
                        httpStatus: res.status,
                        response: res.data
                    });
                } catch (e) {
                    show({
                        ok: false,
                        error: e && e.message ? e.message : String(e)
                    });
                }
            });
        }

        if (verifyBtn) {
            verifyBtn.addEventListener('click', async function() {
                try {
                    show('Vérification facture…');
                    const payload = parseJson(verifyPayloadEl);
                    const res = await postJson('../api/fne_verify_invoice.php', payload);
                    if (maybeRedirect(res.data)) return;
                    show({
                        endpoint: 'fne_verify_invoice',
                        httpStatus: res.status,
                        response: res.data
                    });
                } catch (e) {
                    show({
                        ok: false,
                        error: e && e.message ? e.message : String(e)
                    });
                }
            });
        }

        if (decVerifyBtn) {
            decVerifyBtn.addEventListener('click', async function() {
                try {
                    show('Vérification décaissement…');
                    const payload = parseJson(decVerifyPayloadEl);
                    const res = await postJson('../api/fne_verify_decaissement.php', payload);
                    if (maybeRedirect(res.data)) return;
                    show({
                        endpoint: 'fne_verify_decaissement',
                        httpStatus: res.status,
                        response: res.data
                    });
                } catch (e) {
                    show({
                        ok: false,
                        error: e && e.message ? e.message : String(e)
                    });
                }
            });
        }
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>