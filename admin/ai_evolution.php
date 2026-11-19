<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FebAiSettings.php';
require_once __DIR__ . '/../repositories/AiRequestRepository.php';
require_once __DIR__ . '/../services/FebAiDetectionService.php';

$pdo = Database::getConnection();
$febAiSettingsModel = new FebAiSettings();
$febAiSettings = $febAiSettingsModel->load();
$febAiAdminFlash = '';
$febAiAdminFlashType = 'success';
$febAiTestField = in_array((string)($_POST['test_field'] ?? ''), ['feb_description', 'feb_impact'], true) ? (string)$_POST['test_field'] : 'feb_description';
$febAiTestQuery = trim((string)($_POST['test_query'] ?? ''));
$febAiTestExamples = [];
$febExistingDetection = [];
if (empty($_SESSION['feb_ai_admin_csrf'])) {
    $_SESSION['feb_ai_admin_csrf'] = bin2hex(random_bytes(32));
}
$febAiAdminCsrf = (string)$_SESSION['feb_ai_admin_csrf'];

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_POST['feb_ai_admin_action'])) {
    try {
        if (!hash_equals($febAiAdminCsrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Session expirée. Rechargez la page.');
        }
        $action = (string)$_POST['feb_ai_admin_action'];
        if ($action === 'save_settings') {
            $enabled = !empty($_POST['conversation_enabled']);
            $rejectUnedited = !empty($_POST['reject_unedited_generated_text']);
            if (!empty($_POST['eden_api_key'])) $febAiSettingsModel->saveEdenApiKey((string)$_POST['eden_api_key']);
            if (!$febAiSettingsModel->save($enabled, [
                'user_id' => (int)($_SESSION['user_id'] ?? 0),
                'user_name' => (string)($_SESSION['user_name'] ?? ''),
            ], $rejectUnedited, ['mode'=>$_POST['detection_mode']??'off','provider'=>$_POST['detection_provider']??'sapling','threshold'=>$_POST['detection_threshold']??100,'block'=>!empty($_POST['block_on_detection'])])) {
                throw new RuntimeException('Impossible d’enregistrer le paramètre FEB.');
            }
            $febAiSettings = $febAiSettingsModel->load();
            $febAiAdminFlash = $enabled ? 'Assistant FEB activé.' : 'Assistant FEB désactivé. L’apprentissage passif continue.';
        } elseif ($action === 'test_learning') {
            $examples = (new AiRequestRepository($pdo))->recentAcceptedFieldExamples($febAiTestField, 8);
            if ($febAiTestQuery !== '') {
                $needle = mb_strtolower($febAiTestQuery);
                $examples = array_values(array_filter($examples, static function (array $row) use ($needle): bool {
                    $text = (string)($row['final_text'] ?? $row['generated_text'] ?? '');
                    return str_contains(mb_strtolower($text), $needle);
                }));
            }
            $febAiTestExamples = $examples;
            $febAiAdminFlash = count($examples) . ' exemple(s) appris correspondant au test.';
        } elseif ($action === 'review_example') {
            $repository = new AiRequestRepository($pdo);
            $exampleId = max(0, (int)($_POST['example_id'] ?? 0));
            $decision = (string)($_POST['review_decision'] ?? '');
            $example = $repository->findById($exampleId);
            if (!$example || (string)($example['module'] ?? '') !== 'FEB' || !str_contains((string)($example['input_text'] ?? ''), 'Champ cible :')) {
                throw new RuntimeException('Exemple appris introuvable.');
            }
            $currentText = trim((string)($example['final_text'] ?? $example['generated_text'] ?? ''));
            if ($decision === 'correct') {
                $repository->updateFinalText($exampleId, $currentText, 'accepted');
                $febAiAdminFlash = 'Exemple #' . $exampleId . ' confirmé comme correct.';
            } elseif ($decision === 'reject') {
                $repository->updateFinalText($exampleId, $currentText, 'rejected_admin');
                $febAiAdminFlash = 'Exemple #' . $exampleId . ' rejeté et retiré des références.';
            } elseif ($decision === 'correct_text') {
                $correctedText = trim((string)($_POST['corrected_text'] ?? ''));
                if ($correctedText === '') {
                    throw new RuntimeException('Saisissez la correction proposée.');
                }
                $repository->updateFinalText($exampleId, mb_substr($correctedText, 0, 12000), 'corrected');
                $febAiAdminFlash = 'Correction de l’exemple #' . $exampleId . ' enregistrée.';
            } else {
                throw new RuntimeException('Décision de validation inconnue.');
            }
            \AuditTrail::log('feb_ai_example_reviewed', ['module' => 'admin', 'action' => 'review_ai_learning', 'entity_type' => 'ai_request', 'entity_id' => (string)$exampleId, 'result' => 'success', 'details' => ['decision' => $decision]], $pdo);
            $febAiTestExamples = $repository->recentAcceptedFieldExamples($febAiTestField, 8);
            if ($febAiTestQuery !== '') {
                $needle = mb_strtolower($febAiTestQuery);
                $febAiTestExamples = array_values(array_filter($febAiTestExamples, static fn(array $row): bool => str_contains(mb_strtolower((string)($row['final_text'] ?? $row['generated_text'] ?? '')), $needle)));
            }
        } elseif ($action === 'scan_existing_feb') {
            $febId=max(1,(int)($_POST['existing_feb_id']??0));$stmt=$pdo->prepare("SELECT id,payload_json FROM employee_legal_requests WHERE id=:id AND request_type='feb' LIMIT 1");$stmt->execute(['id'=>$febId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('FEB introuvable.');$payload=json_decode((string)$row['payload_json'],true);$payload=is_array($payload)?$payload:[];$detector=new FebAiDetectionService($pdo);
            foreach(['feb_description'=>'description','feb_impact'=>'impact'] as $field=>$key){$text=trim((string)($payload[$key]??''));$febExistingDetection[$field]=['text'=>$text,'result'=>$text!==''?$detector->detect($text,$field,$febId,(int)($_SESSION['user_id']??0)):['score'=>null,'human_score'=>null,'message'=>'Champ vide']];}
            $febAiAdminFlash='Analyse de la FEB #'.$febId.' terminée.';
        }
    } catch (Throwable $e) {
        $febAiAdminFlash = $e->getMessage();
        $febAiAdminFlashType = 'error';
    }
}

function aiDashTableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
        $stmt->execute(['table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function aiDashRows(PDO $pdo, string $sql, array $params = []): array
{
    try {
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function aiDashOne(PDO $pdo, string $sql, array $params = []): array
{
    $rows = aiDashRows($pdo, $sql, $params);
    return $rows[0] ?? [];
}

function aiDashMoney(float $amount): string
{
    return number_format($amount, 0, ',', ' ') . ' FCFA';
}

function aiDashPercent(float $value): string
{
    return number_format($value, 1, ',', ' ') . '%';
}

function aiDashShortText(string $text, int $max = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?: $text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max - 3) . '...';
}

$hasAiRequests = aiDashTableExists($pdo, 'ai_requests');
$hasAiFeedback = aiDashTableExists($pdo, 'ai_feedback');
$hasAiUsage = aiDashTableExists($pdo, 'ai_usage_logs');
$hasChantiers = aiDashTableExists($pdo, 'chantier_banamur');
$hasFiches = aiDashTableExists($pdo, 'fiche_decaissement');
$hasDevis = aiDashTableExists($pdo, 'devis_banamur');

$aiStats = $hasAiRequests ? aiDashOne($pdo, "
    SELECT
        COUNT(*) AS total_requests,
        SUM(CASE WHEN used_external_ai = 1 THEN 1 ELSE 0 END) AS external_requests,
        SUM(CASE WHEN status IN ('accepted', 'accepted_implicit') THEN 1 ELSE 0 END) AS accepted_requests,
        SUM(CASE WHEN status IN ('corrected', 'corrected_implicit') THEN 1 ELSE 0 END) AS corrected_requests,
        SUM(CASE WHEN status IN ('rejected_implicit') THEN 1 ELSE 0 END) AS rejected_requests,
        SUM(CASE WHEN final_text IS NOT NULL AND TRIM(final_text) <> '' THEN 1 ELSE 0 END) AS learned_examples,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS last_7_days
    FROM ai_requests
") : [];

$totalRequests = (int)($aiStats['total_requests'] ?? 0);
$acceptedRequests = (int)($aiStats['accepted_requests'] ?? 0);
$correctedRequests = (int)($aiStats['corrected_requests'] ?? 0);
$rejectedRequests = (int)($aiStats['rejected_requests'] ?? 0);
$learnedExamples = (int)($aiStats['learned_examples'] ?? 0);
$acceptanceRate = $totalRequests > 0 ? (($acceptedRequests + $correctedRequests) / $totalRequests) * 100 : 0.0;

$aiByStatus = $hasAiRequests ? aiDashRows($pdo, "
    SELECT COALESCE(NULLIF(status, ''), 'unknown') AS label, COUNT(*) AS total
    FROM ai_requests
    GROUP BY COALESCE(NULLIF(status, ''), 'unknown')
    ORDER BY total DESC
") : [];

$aiByModule = $hasAiRequests ? aiDashRows($pdo, "
    SELECT COALESCE(NULLIF(module, ''), 'General') AS label, COUNT(*) AS total
    FROM ai_requests
    GROUP BY COALESCE(NULLIF(module, ''), 'General')
    ORDER BY total DESC
    LIMIT 10
") : [];

$aiByDay = $hasAiRequests ? aiDashRows($pdo, "
    SELECT DATE(created_at) AS day, COUNT(*) AS total,
           SUM(CASE WHEN final_text IS NOT NULL AND TRIM(final_text) <> '' THEN 1 ELSE 0 END) AS learned
    FROM ai_requests
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
") : [];

$aiRecentExamples = $hasAiRequests ? aiDashRows($pdo, "
    SELECT id, module, task_type, provider, status, created_at,
           COALESCE(NULLIF(TRIM(final_text), ''), NULLIF(TRIM(generated_text), '')) AS text_value
    FROM ai_requests
    WHERE COALESCE(NULLIF(TRIM(final_text), ''), NULLIF(TRIM(generated_text), '')) IS NOT NULL
    ORDER BY created_at DESC
    LIMIT 8
") : [];

$usageRows = $hasAiUsage ? aiDashRows($pdo, "
    SELECT action, provider, COUNT(*) AS total
    FROM ai_usage_logs
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY action, provider
    ORDER BY total DESC
    LIMIT 8
") : [];

$financeStats = ($hasFiches) ? aiDashOne($pdo, "
    SELECT
        COUNT(*) AS total_fiches,
        SUM(montant_fiche) AS total_requested,
        SUM(CASE WHEN decaisse = 1 THEN montant_fiche ELSE 0 END) AS total_disbursed,
        SUM(CASE WHEN decaisse = 0 THEN montant_fiche ELSE 0 END) AS total_pending,
        SUM(CASE WHEN decaisse = 0 AND date_decaissement_minimum IS NOT NULL AND date_decaissement_minimum <= CURDATE() THEN montant_fiche ELSE 0 END) AS overdue_pending,
        COUNT(CASE WHEN decaisse = 0 AND date_decaissement_minimum IS NOT NULL AND date_decaissement_minimum <= CURDATE() THEN 1 END) AS overdue_count
    FROM fiche_decaissement
") : [];

$totalDisbursed = (float)($financeStats['total_disbursed'] ?? 0);
$totalPending = (float)($financeStats['total_pending'] ?? 0);
$overduePending = (float)($financeStats['overdue_pending'] ?? 0);

$last90Stats = $hasFiches ? aiDashOne($pdo, "
    SELECT SUM(montant_fiche) AS total_90,
           COUNT(DISTINCT DATE(COALESCE(date_decaisse, date_decaissement, date_valid, date_creat_fiche))) AS active_days
    FROM fiche_decaissement
    WHERE decaisse = 1
      AND COALESCE(date_decaisse, date_decaissement, date_valid, date_creat_fiche) >= DATE_SUB(NOW(), INTERVAL 90 DAY)
") : [];
$activeDays = max(1, (int)($last90Stats['active_days'] ?? 0));
$dailyAverage = ((float)($last90Stats['total_90'] ?? 0)) / $activeDays;

$next30Committed = $hasFiches ? aiDashOne($pdo, "
    SELECT SUM(montant_fiche) AS total_next_30, COUNT(*) AS count_next_30
    FROM fiche_decaissement
    WHERE decaisse = 0
      AND date_decaissement_minimum IS NOT NULL
      AND date_decaissement_minimum BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
") : [];
$next30Scheduled = (float)($next30Committed['total_next_30'] ?? 0);
$next30Forecast = $next30Scheduled + ($dailyAverage * 30);

$projectRows = ($hasChantiers && $hasFiches && $hasDevis) ? aiDashRows($pdo, "
    SELECT
        c.id,
        c.code,
        c.titre,
        COALESCE(c.montant_devis, d.total_ttc, 0) AS budget_amount,
        COALESCE(SUM(CASE WHEN f.decaisse = 1 THEN f.montant_fiche ELSE 0 END), 0) AS disbursed_amount,
        COALESCE(SUM(CASE WHEN f.decaisse = 0 THEN f.montant_fiche ELSE 0 END), 0) AS pending_amount,
        COALESCE(SUM(CASE WHEN f.decaisse = 0 AND f.date_decaissement_minimum IS NOT NULL AND f.date_decaissement_minimum <= CURDATE() THEN f.montant_fiche ELSE 0 END), 0) AS late_amount,
        COUNT(f.id_fiche) AS fiche_count,
        MAX(COALESCE(f.date_decaisse, f.date_decaissement, f.date_valid, f.date_creat_fiche)) AS last_activity_at
    FROM chantier_banamur c
    LEFT JOIN devis_banamur d ON d.id = c.devis_id
    LEFT JOIN fiche_decaissement f ON f.chantier_id = c.id
    GROUP BY c.id, c.code, c.titre, c.montant_devis, d.total_ttc
    ORDER BY late_amount DESC, pending_amount DESC, disbursed_amount DESC
    LIMIT 12
") : [];

$pendingRows = ($hasFiches && $hasChantiers) ? aiDashRows($pdo, "
    SELECT f.num_fiche, f.beficiaire_fiche, f.designation_fiche, f.precision_fiche, f.montant_fiche,
           f.date_decaissement_minimum, c.code AS chantier_code, c.titre AS chantier_titre
    FROM fiche_decaissement f
    LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
    WHERE f.decaisse = 0
    ORDER BY
        CASE WHEN f.date_decaissement_minimum IS NULL THEN 1 ELSE 0 END ASC,
        f.date_decaissement_minimum ASC,
        f.montant_fiche DESC
    LIMIT 10
") : [];

$pageTitle = 'Evolution IA';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-slate-50 pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="flex flex-col gap-4 border-b border-slate-200 pb-6 md:flex-row md:items-end md:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-indigo-600">Administration IA</p>
                <h1 class="mt-2 text-3xl font-bold text-slate-950">Evolution de l'assistant IA</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">Suivi des generations, apprentissage implicite, signaux chantiers et projection simple des prochains decaissements.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="dashboard_admin.php" class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard Admin
                </a>
                <a href="ai_insights_360.php" class="inline-flex items-center gap-2 rounded-lg bg-gradient-to-r from-cyan-500 to-indigo-500 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:from-cyan-600 hover:to-indigo-600"><i class="fas fa-chart-line"></i>Intelligence 360°</a>
                <a href="../selection_portail.php" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50">
                    <i class="fas fa-th-large"></i>
                    Portail
                </a>
            </div>
        </div>

        <?php if ($febAiAdminFlash !== ''): ?>
            <div class="mt-6 rounded-xl border px-4 py-3 text-sm font-semibold <?= $febAiAdminFlashType === 'error' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>"><?= htmlspecialchars($febAiAdminFlash, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="mt-6 grid gap-5 xl:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600">Pilotage FEB</p>
                        <h2 class="mt-2 text-xl font-bold text-slate-950">Assistant conversationnel et générateur</h2>
                    </div>
                    <span class="rounded-full px-3 py-1 text-xs font-bold <?= !empty($febAiSettings['conversation_enabled']) ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' ?>"><?= !empty($febAiSettings['conversation_enabled']) ? 'Activé' : 'Désactivé' ?></span>
                </div>
                <p class="mt-3 text-sm leading-6 text-slate-600">Ce réglage affiche ou masque l’assistant conversationnel et les boutons de génération dans la création des FEB. L’apprentissage passif des descriptions et impacts validés reste toujours actif.</p>
                <form method="post" class="mt-5">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($febAiAdminCsrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="feb_ai_admin_action" value="save_settings">
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <input type="checkbox" name="conversation_enabled" value="1" class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600" <?= !empty($febAiSettings['conversation_enabled']) ? 'checked' : '' ?>>
                        <span><strong class="block text-sm text-slate-900">Autoriser la génération de texte FEB</strong><span class="mt-1 block text-xs leading-5 text-slate-600">Réactive l’assistant question par question ainsi que la génération de description et d’impact.</span></span>
                    </label>
                    <label class="mt-3 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <input type="checkbox" name="reject_unedited_generated_text" value="1" class="mt-1 h-4 w-4 rounded border-amber-300 text-amber-600" <?= !empty($febAiSettings['reject_unedited_generated_text']) ? 'checked' : '' ?>>
                        <span><strong class="block text-sm text-amber-950">Refuser les textes générés à 100 % par l’assistant FEB</strong><span class="mt-1 block text-xs leading-5 text-amber-800">Bloque un texte généré par cet assistant lorsqu’il est soumis sans aucune modification. Un texte collé depuis une IA externe ne peut pas être identifié avec une certitude de 100 %.</span></span>
                    </label>
                    <div class="mt-3 grid gap-3 rounded-xl border border-cyan-200 bg-cyan-50 p-4 sm:grid-cols-2">
                        <label class="text-xs font-bold text-cyan-950">Mode de détection<select name="detection_mode" class="mt-1 w-full rounded-lg border border-cyan-200 bg-white px-3 py-2 text-sm"><option value="off" <?= ($febAiSettings['detection_mode']??'off')==='off'?'selected':'' ?>>Désactivé</option><option value="local" <?= ($febAiSettings['detection_mode']??'')==='local'?'selected':'' ?>>Local gratuit — recommandé</option><option value="eden" <?= ($febAiSettings['detection_mode']??'')==='eden'?'selected':'' ?>>Eden AI — payant</option></select></label>
                        <label class="text-xs font-bold text-cyan-950">Fournisseur Eden<select name="detection_provider" class="mt-1 w-full rounded-lg border border-cyan-200 bg-white px-3 py-2 text-sm"><option value="sapling" <?= ($febAiSettings['detection_provider']??'sapling')==='sapling'?'selected':'' ?>>Sapling</option><option value="winstonai" <?= ($febAiSettings['detection_provider']??'')==='winstonai'?'selected':'' ?>>Winston AI</option></select></label>
                        <label class="text-xs font-bold text-cyan-950">Seuil de blocage (%)<input type="number" min="50" max="100" name="detection_threshold" value="<?= (int)($febAiSettings['detection_threshold']??100) ?>" class="mt-1 w-full rounded-lg border border-cyan-200 bg-white px-3 py-2 text-sm"></label>
                        <label class="text-xs font-bold text-cyan-950">Nouvelle clé Eden AI <span class="font-normal text-cyan-700">(<?= $febAiSettingsModel->hasEdenApiKey()?'déjà configurée':'absente' ?>)</span><input type="password" autocomplete="new-password" name="eden_api_key" class="mt-1 w-full rounded-lg border border-cyan-200 bg-white px-3 py-2 text-sm" placeholder="Laisser vide pour conserver"></label>
                        <label class="sm:col-span-2 flex items-start gap-2 text-xs font-bold text-cyan-950"><input type="checkbox" name="block_on_detection" value="1" class="mt-0.5" <?= !empty($febAiSettings['block_on_detection'])?'checked':'' ?>>Bloquer « Suivant » et la soumission lorsque le score atteint le seuil configuré.</label>
                    </div>
                    <button class="mt-4 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-indigo-700" type="submit"><i class="fas fa-save mr-2"></i>Enregistrer le réglage</button>
                </form>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-600">Laboratoire en lecture seule</p>
                <h2 class="mt-2 text-xl font-bold text-slate-950">Tester ce que l’assistant a appris</h2>
                <p class="mt-3 text-sm leading-6 text-slate-600">Consultez les formulations que le moteur utiliserait comme références. Ce test ne génère aucune FEB et n’enregistre aucun nouvel apprentissage.</p>
                <form method="post" class="mt-5 grid gap-3 sm:grid-cols-[180px_1fr_auto]">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($febAiAdminCsrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="feb_ai_admin_action" value="test_learning">
                    <select name="test_field" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><option value="feb_description" <?= $febAiTestField === 'feb_description' ? 'selected' : '' ?>>Description FEB</option><option value="feb_impact" <?= $febAiTestField === 'feb_impact' ? 'selected' : '' ?>>Impact FEB</option></select>
                    <input name="test_query" value="<?= htmlspecialchars($febAiTestQuery, ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm" placeholder="Mot à tester : salaire, bureau…">
                    <button type="submit" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700">Tester</button>
                </form>
                <?php if (isset($_POST['feb_ai_admin_action']) && in_array((string)$_POST['feb_ai_admin_action'], ['test_learning', 'review_example'], true)): ?>
                    <div class="mt-4 max-h-72 space-y-2 overflow-y-auto">
                        <?php if (!$febAiTestExamples): ?><div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800">Aucun exemple appris ne correspond encore à ce test.</div><?php endif; ?>
                        <?php foreach ($febAiTestExamples as $example): $learnedText = trim((string)($example['final_text'] ?? $example['generated_text'] ?? '')); ?>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                <div class="text-xs font-semibold text-slate-500">Exemple #<?= (int)($example['id'] ?? 0) ?> · <?= htmlspecialchars((string)($example['status'] ?? ''), ENT_QUOTES, 'UTF-8') ?> · <?= trim((string)($example['generated_text'] ?? '')) !== '' ? 'issu d’une génération' : 'saisie humaine' ?></div>
                                <div class="mt-1 text-sm leading-6 text-slate-800"><?= nl2br(htmlspecialchars($learnedText, ENT_QUOTES, 'UTF-8')) ?></div>
                                <form method="post" class="mt-3 flex flex-wrap items-end gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($febAiAdminCsrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="feb_ai_admin_action" value="review_example"><input type="hidden" name="example_id" value="<?= (int)($example['id'] ?? 0) ?>"><input type="hidden" name="test_field" value="<?= htmlspecialchars($febAiTestField, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="test_query" value="<?= htmlspecialchars($febAiTestQuery, ENT_QUOTES, 'UTF-8') ?>">
                                    <button name="review_decision" value="correct" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white" type="submit">Correct</button>
                                    <button name="review_decision" value="reject" class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-bold text-white" type="submit">Incorrect</button>
                                    <input name="corrected_text" class="min-w-[220px] flex-1 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs" placeholder="Proposer une correction…">
                                    <button name="review_decision" value="correct_text" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-bold text-white" type="submit">Enregistrer la correction</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-violet-600">Audit rétroactif</p><h2 class="mt-2 text-xl font-bold text-slate-950">Vérifier une FEB existante</h2><p class="mt-2 text-sm text-slate-600">Analyse la description et l’impact déjà enregistrés, puis conserve les scores pour améliorer le corpus selon le niveau d’humanité observé.</p>
            <form method="post" class="mt-4 flex flex-wrap gap-3"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($febAiAdminCsrf,ENT_QUOTES,'UTF-8') ?>"><input type="hidden" name="feb_ai_admin_action" value="scan_existing_feb"><input type="number" min="1" required name="existing_feb_id" value="<?= htmlspecialchars((string)($_POST['existing_feb_id']??''),ENT_QUOTES,'UTF-8') ?>" class="rounded-xl border border-slate-200 px-4 py-2" placeholder="Numéro FEB"><button class="rounded-xl bg-violet-600 px-5 py-2 text-sm font-bold text-white">Analyser la FEB</button></form>
            <?php if($febExistingDetection): ?><div class="mt-4 grid gap-3 md:grid-cols-2"><?php foreach($febExistingDetection as $field=>$item):$result=(array)$item['result'];?><div class="rounded-xl border border-slate-200 bg-slate-50 p-4"><div class="flex justify-between gap-3"><strong><?= $field==='feb_impact'?'Impact':'Description' ?></strong><span class="text-sm font-bold <?= !empty($result['blocked'])?'text-rose-700':'text-emerald-700' ?>"><?= $result['score']===null?'Non mesuré':htmlspecialchars((string)$result['score']).' % IA · '.htmlspecialchars((string)$result['human_score']).' % humain' ?></span></div><p class="mt-2 text-sm text-slate-700"><?= nl2br(htmlspecialchars((string)$item['text'],ENT_QUOTES,'UTF-8')) ?></p></div><?php endforeach;?></div><?php endif; ?>
        </section>

        <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div class="rounded-lg border border-indigo-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Generations IA</p>
                <p class="mt-2 text-3xl font-bold text-slate-950"><?= number_format($totalRequests, 0, ',', ' ') ?></p>
                <p class="mt-1 text-xs text-slate-500"><?= number_format((int)($aiStats['last_7_days'] ?? 0), 0, ',', ' ') ?> sur 7 jours</p>
            </div>
            <div class="rounded-lg border border-emerald-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Exemples appris</p>
                <p class="mt-2 text-3xl font-bold text-emerald-700"><?= number_format($learnedExamples, 0, ',', ' ') ?></p>
                <p class="mt-1 text-xs text-slate-500">Textes acceptes ou corriges</p>
            </div>
            <div class="rounded-lg border border-sky-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Signal positif</p>
                <p class="mt-2 text-3xl font-bold text-sky-700"><?= aiDashPercent($acceptanceRate) ?></p>
                <p class="mt-1 text-xs text-slate-500">Acceptes + corriges</p>
            </div>
            <div class="rounded-lg border border-violet-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">IA externe</p>
                <p class="mt-2 text-3xl font-bold text-violet-700"><?= number_format((int)($aiStats['external_requests'] ?? 0), 0, ',', ' ') ?></p>
                <p class="mt-1 text-xs text-slate-500">Groq/autre fournisseur</p>
            </div>
            <div class="rounded-lg border border-amber-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">A decaisser</p>
                <p class="mt-2 text-2xl font-bold text-amber-700"><?= aiDashMoney($totalPending) ?></p>
                <p class="mt-1 text-xs text-slate-500">Toutes fiches non decaissees</p>
            </div>
            <div class="rounded-lg border border-rose-100 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Prevision 30 jours</p>
                <p class="mt-2 text-2xl font-bold text-rose-700"><?= aiDashMoney($next30Forecast) ?></p>
                <p class="mt-1 text-xs text-slate-500">Planifie + tendance recente</p>
            </div>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-slate-950">Courbe d'apprentissage</h2>
                        <p class="mt-1 text-sm text-slate-500">Generations et exemples utilisables sur les 30 derniers jours.</p>
                    </div>
                    <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">Apprentissage implicite</span>
                </div>
                <div class="mt-4 h-72">
                    <canvas id="aiLearningChart"></canvas>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-slate-950">Statuts IA</h2>
                <p class="mt-1 text-sm text-slate-500">Qualite observee apres usage.</p>
                <div class="mt-4 h-72">
                    <canvas id="aiStatusChart"></canvas>
                </div>
            </section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-bold text-slate-950">Analyse chantiers et finances</h2>
                        <p class="mt-1 text-sm text-slate-500">Vue chantier, budget devis, decaissements et risques de retard.</p>
                    </div>
                    <span class="rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700">Base prediction</span>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-4 py-3 text-left">Chantier</th>
                                <th class="px-4 py-3 text-right">Budget</th>
                                <th class="px-4 py-3 text-right">Decaisse</th>
                                <th class="px-4 py-3 text-right">Restant</th>
                                <th class="px-4 py-3 text-left">Signal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            <?php if (!$projectRows): ?>
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-500">Aucune donnee chantier exploitable pour l'instant.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($projectRows as $row): ?>
                                <?php
                                $budget = (float)($row['budget_amount'] ?? 0);
                                $disbursed = (float)($row['disbursed_amount'] ?? 0);
                                $pending = (float)($row['pending_amount'] ?? 0);
                                $late = (float)($row['late_amount'] ?? 0);
                                $ratio = $budget > 0 ? ($disbursed / $budget) * 100 : 0.0;
                                $signal = 'Normal';
                                $tone = 'emerald';
                                if ($late > 0) {
                                    $signal = 'Retard potentiel';
                                    $tone = 'rose';
                                } elseif ($budget > 0 && $ratio >= 90) {
                                    $signal = 'Budget sensible';
                                    $tone = 'amber';
                                } elseif ($pending > 0) {
                                    $signal = 'A suivre';
                                    $tone = 'sky';
                                }
                                ?>
                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars((string)($row['code'] ?? '')) ?></div>
                                        <div class="mt-1 max-w-sm text-xs text-slate-500"><?= htmlspecialchars(aiDashShortText((string)($row['titre'] ?? ''), 90)) ?></div>
                                    </td>
                                    <td class="px-4 py-3 text-right text-slate-700"><?= aiDashMoney($budget) ?></td>
                                    <td class="px-4 py-3 text-right text-slate-700"><?= aiDashMoney($disbursed) ?><div class="text-xs text-slate-400"><?= aiDashPercent($ratio) ?></div></td>
                                    <td class="px-4 py-3 text-right text-slate-700"><?= aiDashMoney($pending) ?></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= $tone === 'rose' ? 'border-rose-200 bg-rose-50 text-rose-700' : ($tone === 'amber' ? 'border-amber-200 bg-amber-50 text-amber-700' : ($tone === 'sky' ? 'border-sky-200 bg-sky-50 text-sky-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700')) ?>">
                                            <?= htmlspecialchars($signal) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-slate-950">Projection decaissement</h2>
                <div class="mt-4 space-y-4">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Deja decaisse</p>
                        <p class="mt-2 text-2xl font-bold text-slate-950"><?= aiDashMoney($totalDisbursed) ?></p>
                    </div>
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Planifie 30 jours</p>
                        <p class="mt-2 text-2xl font-bold text-amber-800"><?= aiDashMoney($next30Scheduled) ?></p>
                        <p class="mt-1 text-xs text-amber-700"><?= number_format((int)($next30Committed['count_next_30'] ?? 0), 0, ',', ' ') ?> fiche(s)</p>
                    </div>
                    <div class="rounded-lg border border-rose-200 bg-rose-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-rose-700">En retard potentiel</p>
                        <p class="mt-2 text-2xl font-bold text-rose-800"><?= aiDashMoney($overduePending) ?></p>
                        <p class="mt-1 text-xs text-rose-700"><?= number_format((int)($financeStats['overdue_count'] ?? 0), 0, ',', ' ') ?> fiche(s)</p>
                    </div>
                </div>
            </section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-slate-950">Fiches a surveiller</h2>
                <div class="mt-4 space-y-3">
                    <?php if (!$pendingRows): ?>
                        <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">Aucune fiche non decaissee detectee.</p>
                    <?php endif; ?>
                    <?php foreach ($pendingRows as $row): ?>
                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-slate-900"><?= htmlspecialchars((string)($row['num_fiche'] ?? '')) ?> - <?= htmlspecialchars((string)($row['chantier_code'] ?? 'Sans chantier')) ?></p>
                                    <p class="mt-1 text-sm text-slate-500"><?= htmlspecialchars(aiDashShortText((string)($row['designation_fiche'] ?? '') . ' ' . (string)($row['precision_fiche'] ?? ''), 120)) ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="font-bold text-slate-900"><?= aiDashMoney((float)($row['montant_fiche'] ?? 0)) ?></p>
                                    <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string)($row['date_decaissement_minimum'] ?? 'Non planifie')) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-slate-950">Derniers exemples IA</h2>
                <div class="mt-4 space-y-3">
                    <?php if (!$aiRecentExamples): ?>
                        <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">Aucun exemple IA disponible.</p>
                    <?php endif; ?>
                    <?php foreach ($aiRecentExamples as $row): ?>
                        <div class="rounded-lg border border-slate-200 bg-white p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700"><?= htmlspecialchars((string)($row['module'] ?? '')) ?></span>
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600"><?= htmlspecialchars((string)($row['status'] ?? '')) ?></span>
                                <span class="text-xs text-slate-400"><?= htmlspecialchars((string)($row['created_at'] ?? '')) ?></span>
                            </div>
                            <p class="mt-3 text-sm text-slate-700"><?= htmlspecialchars(aiDashShortText((string)($row['text_value'] ?? ''), 220)) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700"><i class="fas fa-brain"></i></span>
                    <h2 class="text-base font-bold text-slate-950">Memoire metier</h2>
                </div>
                <p class="mt-3 text-sm text-slate-600">L'IA commence par apprendre des textes acceptes et corriges. Les modules chantier, finance, devis et achat peuvent utiliser le meme mecanisme.</p>
            </section>
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700"><i class="fas fa-chart-line"></i></span>
                    <h2 class="text-base font-bold text-slate-950">Prediction finances</h2>
                </div>
                <p class="mt-3 text-sm text-slate-600">La projection actuelle combine fiches planifiees et moyenne recente. Elle servira de base pour un modele plus fin par chantier, nature de depense et saisonnalite.</p>
            </section>
            <section class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-sky-50 text-sky-700"><i class="fas fa-image"></i></span>
                    <h2 class="text-base font-bold text-slate-950">Images et justificatifs</h2>
                </div>
                <p class="mt-3 text-sm text-slate-600">La prochaine couche pourra analyser photos chantier, factures, devis et justificatifs avec OCR/vision, puis rattacher les signaux aux fiches et aux chantiers.</p>
            </section>
        </div>

        <?php if ($usageRows): ?>
            <section class="mt-6 rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-slate-950">Usage IA recent</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <?php foreach ($usageRows as $row): ?>
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)($row['action'] ?? '')) ?></p>
                            <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string)($row['provider'] ?? '')) ?></p>
                            <p class="mt-3 text-2xl font-bold text-slate-950"><?= number_format((int)($row['total'] ?? 0), 0, ',', ' ') ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<script>
    const aiByDay = <?= json_encode($aiByDay, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const aiByStatus = <?= json_encode($aiByStatus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const learningCanvas = document.getElementById('aiLearningChart');
    if (learningCanvas && window.Chart) {
        new Chart(learningCanvas, {
            type: 'line',
            data: {
                labels: aiByDay.map(row => row.day),
                datasets: [{
                    label: 'Generations',
                    data: aiByDay.map(row => Number(row.total || 0)),
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.12)',
                    tension: 0.35,
                    fill: true
                }, {
                    label: 'Exemples appris',
                    data: aiByDay.map(row => Number(row.learned || 0)),
                    borderColor: '#059669',
                    backgroundColor: 'rgba(5, 150, 105, 0.10)',
                    tension: 0.35,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    const statusCanvas = document.getElementById('aiStatusChart');
    if (statusCanvas && window.Chart) {
        new Chart(statusCanvas, {
            type: 'doughnut',
            data: {
                labels: aiByStatus.map(row => row.label),
                datasets: [{
                    data: aiByStatus.map(row => Number(row.total || 0)),
                    backgroundColor: ['#4f46e5', '#059669', '#f59e0b', '#e11d48', '#0ea5e9', '#64748b']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
    }
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
