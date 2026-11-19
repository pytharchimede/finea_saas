<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../decaissement/model/PointFinancierSchema.php';

$pageTitle = 'Maintenance - Index Point financier';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

$results = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = Database::getConnection();
        $results = PointFinancierSchema::ensureIndexes($pdo);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function pf_idx_badge(string $status): string
{
    $map = [
        'added' => 'bg-green-100 text-green-700 border-green-200',
        'exists' => 'bg-indigo-100 text-indigo-700 border-indigo-200',
        'skipped' => 'bg-amber-100 text-amber-700 border-amber-200',
        'failed' => 'bg-rose-100 text-rose-700 border-rose-200',
    ];
    $cls = $map[$status] ?? 'bg-gray-100 text-gray-700 border-gray-200';
    return '<span class="inline-flex items-center px-2 py-1 text-xs font-semibold rounded-lg border ' . $cls . '">' . htmlspecialchars($status) . '</span>';
}
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">
        <div class="max-w-3xl mx-auto">
            <div class="bg-white rounded-2xl shadow-xl border border-indigo-100 p-6">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold text-indigo-700">Maintenance - Index Point financier</h1>
                        <p class="text-sm text-gray-600 mt-1">Ajoute automatiquement les index manquants (best-effort) pour stabiliser/accélérer le module « Point financier ».</p>
                    </div>
                    <form method="post">
                        <button type="submit" class="inline-flex items-center px-5 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:from-indigo-700 hover:to-purple-700 transition font-semibold shadow">
                            Appliquer les index
                        </button>
                    </form>
                </div>

                <?php if ($error): ?>
                    <div class="mt-5 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-sm">
                        <strong>Erreur:</strong> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if (is_array($results)): ?>
                    <div class="mt-6">
                        <h2 class="text-lg font-semibold text-gray-800 mb-3">Résultat</h2>
                        <div class="space-y-3">
                            <?php foreach ($results as $row): ?>
                                <div class="p-4 rounded-xl border border-gray-200 bg-white">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="text-sm font-semibold text-gray-800">
                                            <?= htmlspecialchars($row['table'] ?? '') ?> / <?= htmlspecialchars($row['index'] ?? '') ?>
                                        </div>
                                        <div><?= pf_idx_badge((string)($row['status'] ?? '')) ?></div>
                                    </div>
                                    <div class="text-xs text-gray-600 mt-1">
                                        Colonnes: <?= htmlspecialchars(implode(', ', $row['columns'] ?? [])) ?>
                                    </div>
                                    <?php if (!empty($row['message'])): ?>
                                        <div class="text-xs text-gray-500 mt-2">
                                            <?= htmlspecialchars((string)$row['message']) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="mt-6 p-4 rounded-xl bg-indigo-50 border border-indigo-200 text-indigo-800 text-sm">
                        Cliquez sur <strong>Appliquer les index</strong> pour lancer l'automatisation.
                    </div>
                <?php endif; ?>

                <div class="mt-6 text-xs text-gray-500">
                    Note: sur un hébergement mutualisé, l'ajout d'index peut prendre du temps selon la taille des tables.
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>