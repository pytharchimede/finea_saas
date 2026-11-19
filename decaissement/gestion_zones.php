<?php
session_start();
require_once '../auth_check.php';
require_once __DIR__ . '/model/Database.php';
require_once  '../model/Devis.php';
require_once  '../model/Offre.php';
require_once __DIR__ . '/../services/ChantierPauseService.php';

$pdo = Database::getConnection();
$devisModel = new Devis($pdo);
$offreModel = new Offre($pdo);

// Récupérer tous les devis validés avec leurs informations
$devis = $devisModel->getDevisFiltres([]);
$devisWithChantier = array_fill_keys($pdo->query('SELECT DISTINCT devis_id FROM chantier_banamur WHERE devis_id IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN), true);
$activeDevis = array_fill_keys($pdo->query('SELECT DISTINCT devis_id FROM chantier_banamur WHERE devis_id IS NOT NULL AND archived_at IS NULL AND '.ChantierPauseService::activePredicate($pdo,'id'))->fetchAll(PDO::FETCH_COLUMN), true);
$devis = array_values(array_filter($devis, static fn(array $d): bool => !isset($devisWithChantier[$d['id']]) || isset($activeDevis[$d['id']])));

$pageTitle = 'Gestion des Zones - Regroupement des Lignes de Devis';
$activeMenu = 'zones';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">
        <!-- En-tête -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <h1 class="text-4xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                    🏗️ Gestion des Zones
                </h1>
                <p class="text-xl text-gray-600 mt-2">Regrouper les lignes de devis par zones pour une meilleure organisation</p>
            </div>
            <div class="flex gap-3">
                <button onclick="window.location.reload()" class="px-6 py-3 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors font-semibold">
                    <i class="fas fa-sync-alt mr-2"></i>
                    Actualiser
                </button>
            </div>
        </div>

        <!-- Informations générales -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-indigo-100">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-indigo-800">Total Devis</h3>
                        <p class="text-2xl font-bold text-indigo-600 mt-2"><?= count($devis) ?></p>
                    </div>
                    <div class="bg-indigo-100 p-3 rounded-xl">
                        <i class="fas fa-file-invoice text-indigo-600 text-2xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-green-100">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-green-800">Devis Validés</h3>
                        <p class="text-2xl font-bold text-green-600 mt-2">
                            <?= count(array_filter($devis, fn($d) => $d['validation_generale'] && $d['validation_technique'])) ?>
                        </p>
                    </div>
                    <div class="bg-green-100 p-3 rounded-xl">
                        <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-purple-100">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-semibold text-purple-800">Avec Chantiers</h3>
                        <p class="text-2xl font-bold text-purple-600 mt-2">
                            <?php
                            $withChantiers = 0;
                            foreach ($devis as $d) {
                                if ($d['validation_generale'] && $d['validation_technique']) {
                                    // Vérifier si chantier existe
                                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM chantier_banamur WHERE devis_id = ?");
                                    $stmt->execute([$d['id']]);
                                    if ($stmt->fetchColumn() > 0) $withChantiers++;
                                }
                            }
                            echo $withChantiers;
                            ?>
                        </p>
                    </div>
                    <div class="bg-purple-100 p-3 rounded-xl">
                        <i class="fas fa-tools text-purple-600 text-2xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Liste des devis -->
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100">
            <div class="px-6 py-4 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-t-2xl">
                <h2 class="text-xl font-bold text-white flex items-center">
                    <i class="fas fa-list-alt mr-3"></i>
                    Devis Disponibles pour Regroupement
                </h2>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">N° Devis</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Chantier</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"></th>Montant TTC</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lignes</th>
                            <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($devis as $d): ?>
                            <?php
                            // Récupérer le chantier associé
                            $chantiersStmt = $pdo->prepare("SELECT id, titre FROM chantier_banamur WHERE devis_id = ? LIMIT 1");
                            $chantiersStmt->execute([$d['id']]);
                            $chantier = $chantiersStmt->fetch(PDO::FETCH_ASSOC);

                            // Compter les lignes de devis
                            $lignesCount = $pdo->prepare("SELECT COUNT(*) FROM ligne_devis_banamur WHERE devis_id = ?");
                            $lignesCount->execute([$d['id']]);
                            $nbLignes = $lignesCount->fetchColumn();

                            // Compter les zones définies
                            $zonesCount = $pdo->prepare("SELECT COUNT(DISTINCT zone) FROM ligne_devis_banamur WHERE devis_id = ? AND zone IS NOT NULL AND zone != ''");
                            $zonesCount->execute([$d['id']]);
                            $nbZones = $zonesCount->fetchColumn();
                            ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($d['numero_devis']) ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        ID: <?= $d['id'] ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php if ($chantier): ?>
                                        <span class="inline-flex px-3 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                                            <?= htmlspecialchars($chantier['titre']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex px-3 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-600">
                                            Pas de chantier
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?= number_format($d['total_ttc'], 0, ',', ' ') ?> F
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($d['validation_generale'] && $d['validation_technique']): ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                            ✅ Validé
                                        </span>
                                    <?php elseif ($d['validation_technique']): ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                            🔄 En attente générale
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                            ⏳ En attente technique
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <div class="flex items-center space-x-2">
                                        <span><?= $nbLignes ?> ligne(s)</span>
                                        <?php if ($nbZones > 0): ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-indigo-100 text-indigo-800">
                                                <?= $nbZones ?> zone(s)
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                                Non regroupé
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <a href="affecter_zones_kanban.php?devis_id=<?= $d['id'] ?>"
                                            class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200 transition-colors">
                                            <i class="fas fa-th-large mr-2"></i>
                                            Gérer Zones
                                        </a>
                                        <?php if ($chantier && $nbZones > 0): ?>
                                            <a href="detail_budgetaire_chantier.php?chantier_id=<?= $chantier['id'] ?>"
                                                target="_blank"
                                                class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-green-700 bg-green-100 hover:bg-green-200 transition-colors">
                                                <i class="fas fa-eye mr-2"></i>
                                                Voir Résultat
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (empty($devis)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-inbox text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun devis trouvé</h3>
                    <p class="text-gray-500">Il n'y a pas encore de devis dans le système.</p>
                    <a href="generer_devis.php" class="mt-4 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-indigo-600 bg-indigo-100 hover:bg-indigo-200">
                        <i class="fas fa-plus mr-2"></i>
                        Créer un devis
                    </a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Section d'aide -->
        <div class="mt-8 bg-blue-50 rounded-2xl p-6 border border-blue-200">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-600 text-2xl"></i>
                </div>
                <div class="ml-4">
                    <h3 class="text-lg font-semibold text-blue-800 mb-2">Comment utiliser le système de zones ?</h3>
                    <div class="text-blue-700 space-y-2">
                        <p><strong>1. Accéder au kanban :</strong> Cliquez sur "Gérer Zones" pour un devis spécifique.</p>
                        <p><strong>2. Organiser les lignes :</strong> Glissez-déposez les lignes de devis dans les colonnes de zones appropriées.</p>
                        <p><strong>3. Sauvegarder :</strong> Cliquez sur "Enregistrer" pour sauvegarder vos regroupements.</p>
                        <p><strong>4. Visualiser :</strong> Utilisez "Voir Résultat" pour voir les zones dans le détail budgétaire du chantier.</p>
                        <p><strong>Note :</strong> Si aucune zone n'est définie, le système créera automatiquement "Zone 1", "Zone 2", etc.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
