<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controller/PopupNotificationController.php';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('Notification invalide.');
}

try {
    $pdo = Database::getConnection();
    $controller = new PopupNotificationController($pdo);

    $report = $controller->report($id);

    $notification = $report['notification'];
    $reads = $report['reads'];
    $responses = $report['responses'];
    $actionStats = $report['actionStats'];
    $readValidations = $report['readValidations'] ?? [];
    $successfulReadValidations = array_filter($readValidations, static fn(array $row): bool => (int)($row['is_success'] ?? 0) === 1);
    $totalReads = $report['totalReads'];
    $totalResponses = $report['totalResponses'];
    $totalTargeted = $report['totalTargeted'];
    $readRate = $report['readRate'];
    $responseRate = $totalTargeted > 0 ? round(($totalResponses / $totalTargeted) * 100, 1) : null;
    $pendingResponses = max(0, $totalTargeted - $totalResponses);
    $respondedUserIds = [];
    foreach ($responses as $response) {
        $respondedUserIds[(int)($response['user_id'] ?? 0)] = true;
    }
} catch (Throwable $e) {
    die($e->getMessage());
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">

        <div class="mb-8 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
            <div>
                <div class="inline-flex items-center px-4 py-2 rounded-full bg-indigo-100 text-indigo-700 text-sm font-semibold mb-4">
                    <i class="fas fa-chart-pie mr-2"></i>
                    Rapport notification
                </div>

                <h1 class="text-4xl lg:text-5xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                    <?= h($notification['title']) ?>
                </h1>

                <p class="text-gray-600 mt-3 max-w-3xl">
                    Suivi des lectures, réponses utilisateurs et statistiques d’interaction.
                </p>
            </div>

            <a href="popup_notifications.php"
                class="inline-flex items-center px-5 py-3 bg-white text-slate-700 rounded-xl border border-slate-200 hover:bg-slate-50 transition-all shadow-sm">
                <i class="fas fa-arrow-left mr-2"></i>
                Retour
            </a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-indigo-100">
                <p class="text-sm text-gray-500 font-semibold">Lectures</p>
                <p class="text-4xl font-bold text-indigo-600 mt-2"><?= number_format($totalReads) ?></p>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-purple-100">
                <p class="text-sm text-gray-500 font-semibold">Réponses</p>
                <p class="text-4xl font-bold text-purple-600 mt-2"><?= number_format($totalResponses) ?></p>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-emerald-100">
                <p class="text-sm text-gray-500 font-semibold">Taux de lecture</p>
                <p class="text-4xl font-bold text-emerald-600 mt-2">
                    <?= $readRate !== null ? h($readRate . ' %') : '—' ?>
                </p>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-amber-100">
                <p class="text-sm text-gray-500 font-semibold">Sans réponse</p>
                <p class="text-4xl font-bold text-amber-600 mt-2"><?= number_format($pendingResponses) ?></p>
                <p class="text-xs text-gray-500 mt-2">
                    Engagement : <?= $responseRate !== null ? h($responseRate . ' %') : '—' ?>
                </p>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6 mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-5">
                <i class="fas fa-square-poll-vertical text-indigo-600 mr-2"></i>
                Statistiques des boutons / sondage
            </h2>

            <?php if (empty($actionStats)): ?>
                <p class="text-gray-500">Aucune action configurée pour cette notification.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($actionStats as $stat): ?>
                        <?php
                        $count = (int)$stat['total_responses'];
                        $percent = $totalResponses > 0 ? round(($count / $totalResponses) * 100, 1) : 0;
                        ?>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <div>
                                    <span class="font-semibold text-gray-800"><?= h($stat['label']) ?></span>
                                    <span class="text-xs text-gray-500 ml-2"><?= h($stat['action_type']) ?></span>
                                </div>
                                <span class="font-bold text-indigo-600"><?= $count ?> réponse(s) — <?= $percent ?>%</span>
                            </div>

                            <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                                <div class="bg-gradient-to-r from-indigo-600 to-purple-600 h-3 rounded-full"
                                    style="width: <?= h($percent) ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">

            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <h2 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-eye text-indigo-600 mr-2"></i>
                        Personnes ayant vu
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="p-4 text-left">Utilisateur</th>
                                <th class="p-4 text-left">Service</th>
                                <th class="p-4 text-left">Date lecture</th>
                                <th class="p-4 text-left">Engagement</th>
                                <th class="p-4 text-left">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($reads)): ?>
                                <tr>
                                    <td colspan="5" class="p-8 text-center text-gray-500">Aucune lecture enregistrée.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($reads as $read): ?>
                                    <tr>
                                        <td class="p-4">
                                            <div class="font-semibold text-gray-800">
                                                <?= h(trim(($read['prenom'] ?? '') . ' ' . ($read['nom'] ?? ''))) ?>
                                            </div>
                                            <div class="text-sm text-gray-500"><?= h($read['mail_pro'] ?? '') ?></div>
                                        </td>
                                        <td class="p-4 text-sm text-gray-600"><?= h($read['service_id'] ?? '—') ?></td>
                                        <td class="p-4 text-sm text-gray-600"><?= h($read['read_at'] ?? '') ?></td>
                                        <td class="p-4">
                                            <?php if (!empty($respondedUserIds[(int)($read['user_id'] ?? 0)])): ?>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-semibold">
                                                    <i class="fas fa-check mr-2"></i>Réponse enregistrée
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full bg-amber-50 text-amber-700 border border-amber-200 text-xs font-semibold">
                                                    <i class="fas fa-clock mr-2"></i>Choix attendu
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-4 text-sm text-gray-600"><?= h($read['ip'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ((int)($notification['requires_read_validation'] ?? 0) === 1): ?>
                <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h2 class="text-2xl font-bold text-gray-800">
                            <i class="fas fa-clipboard-check text-emerald-600 mr-2"></i>
                            Validations de lecture QCM
                        </h2>
                        <p class="text-sm text-gray-500 mt-1">
                            <?= count($successfulReadValidations) ?> validation(s) réussie(s) sur <?= count($readValidations) ?> tentative(s) enregistrée(s).
                        </p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                                <tr>
                                    <th class="p-4 text-left">Utilisateur</th>
                                    <th class="p-4 text-left">Résultat</th>
                                    <th class="p-4 text-left">Score</th>
                                    <th class="p-4 text-left">Tentatives</th>
                                    <th class="p-4 text-left">Date validation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (empty($readValidations)): ?>
                                    <tr>
                                        <td colspan="5" class="p-8 text-center text-gray-500">Aucune validation QCM enregistrée.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($readValidations as $validation): ?>
                                        <tr>
                                            <td class="p-4">
                                                <div class="font-semibold text-gray-800">
                                                    <?= h(trim(($validation['prenom'] ?? '') . ' ' . ($validation['nom'] ?? ''))) ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?= h($validation['mail_pro'] ?? $validation['mail_pro'] ?? '') ?>
                                                </div>
                                            </td>
                                            <td class="p-4">
                                                <?php if ((int)($validation['is_success'] ?? 0) === 1): ?>
                                                    <span class="inline-flex items-center px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-semibold">
                                                        <i class="fas fa-check mr-2"></i>Réussie
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-3 py-1 rounded-full bg-red-50 text-red-700 border border-red-200 text-xs font-semibold">
                                                        <i class="fas fa-times mr-2"></i>Échouée
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-4 text-sm text-gray-700">
                                                <?= (int)($validation['score'] ?? 0) ?> / <?= (int)($validation['total_questions'] ?? 0) ?>
                                            </td>
                                            <td class="p-4 text-sm text-gray-700"><?= (int)($validation['attempts_count'] ?? 0) ?></td>
                                            <td class="p-4 text-sm text-gray-600"><?= h($validation['validated_at'] ?? '—') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
                <div class="p-6 border-b border-gray-100">
                    <h2 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-comments text-purple-600 mr-2"></i>
                        Réponses utilisateurs
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-500">
                            <tr>
                                <th class="p-4 text-left">Utilisateur</th>
                                <th class="p-4 text-left">Réponse</th>
                                <th class="p-4 text-left">Date</th>
                                <th class="p-4 text-left">IP</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (empty($responses)): ?>
                                <tr>
                                    <td colspan="4" class="p-8 text-center text-gray-500">Aucune réponse enregistrée.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($responses as $response): ?>
                                    <tr>
                                        <td class="p-4">
                                            <div class="font-semibold text-gray-800">
                                                <?= h(trim(($response['prenom'] ?? '') . ' ' . ($response['nom'] ?? ''))) ?>
                                            </div>
                                            <div class="text-sm text-gray-500"><?= h($response['mail_pro'] ?? '') ?></div>
                                        </td>
                                        <td class="p-4">
                                            <div class="font-semibold text-purple-700">
                                                <?= h($response['action_label'] ?? 'Réponse') ?>
                                            </div>
                                            <?php if (!empty($response['response_value'])): ?>
                                                <div class="text-sm text-gray-500"><?= h($response['response_value']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-4 text-sm text-gray-600"><?= h($response['created_at'] ?? '') ?></td>
                                        <td class="p-4 text-sm text-gray-600"><?= h($response['ip'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>