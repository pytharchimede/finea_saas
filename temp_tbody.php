                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php $globalIndex = 0; ?>
                            <?php foreach ($detailsChantier['zones'] as $zoneName => $lignesZone): ?>
                                <?php
                                // Calculer les totaux pour la zone
                                $zoneTotalDevis = array_sum(array_column($lignesZone, 'total'));
                                $zoneTotalDebourse = array_sum(array_column($lignesZone, 'montant_debourse_reel'));
                                $zoneTotalPaye = array_sum(array_column($lignesZone, 'montant_paye'));
                                $zoneRestant = $zoneTotalDebourse - $zoneTotalPaye;
                                $zonePourcentage = $zoneTotalDebourse > 0 ? ($zoneTotalPaye / $zoneTotalDebourse) * 100 : 0;
                                ?>
                                <!-- Ligne de zone -->
                                <tr class="bg-indigo-50 hover:bg-indigo-100 transition-colors">
                                    <td class="px-2 py-4 text-center">
                                        <button type="button"
                                            onclick="toggleExpand(this, 'zone-expand-<?= $globalIndex ?>')"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gradient-to-br from-purple-500 to-pink-600 text-white hover:from-purple-600 hover:to-pink-700 transition-all transform hover:scale-110 shadow-md">
                                            <i class="fas fa-plus text-xs transition-transform duration-300"></i>
                                        </button>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-lg font-bold text-indigo-800">🏗️ <?= htmlspecialchars($zoneName) ?></div>
                                        <div class="text-sm text-indigo-600"><?= count($lignesZone) ?> ligne(s) de devis</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-800">
                                        <?= number_format($zoneTotalDevis, 0, ',', ' ') ?> F
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-purple-600">
                                        <?= number_format($zoneTotalDebourse, 0, ',', ' ') ?> F
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-green-600">
                                        <?= number_format($zoneTotalPaye, 0, ',', ' ') ?> F
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-orange-600">
                                        <?= number_format($zoneRestant, 0, ',', ' ') ?> F
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-full bg-gray-200 rounded-full h-3 mr-3">
                                                <div class="bg-gradient-to-r from-purple-500 to-pink-600 h-3 rounded-full"
                                                    style="width: <?= min($zonePourcentage, 100) ?>%"></div>
                                            </div>
                                            <span class="text-sm font-bold text-indigo-800 min-w-max"><?= number_format($zonePourcentage, 1) ?>%</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full bg-indigo-100 text-indigo-800">
                                            Zone
                                        </span>
                                    </td>
                                </tr>

                                <!-- Lignes d'expansion de zone -->
                                <tr id="zone-expand-<?= $globalIndex ?>" class="hidden">
                                    <td colspan="8" class="px-0 py-0">
                                        <div class="bg-gradient-to-br from-indigo-50 via-purple-50 to-pink-50 p-4">
                                            <table class="min-w-full">
                                                <tbody>
                                                    <?php foreach ($lignesZone as $indexLigne => $ligne): ?>
                                                        <?php
                                                        $montantRestant = $ligne['montant_debourse_reel'] - $ligne['montant_paye'];
                                                        $total = floatval($ligne['total'] ?? 0);
                                                        $debourse = floatval($ligne['montant_debourse_reel'] ?? 0);
                                                        $montantPaye = floatval($ligne['montant_paye'] ?? 0);
                                                        $pourcentageRealise = $debourse > 0 ? ($montantPaye / $debourse) * 100 : 0;

                                                        // Filtrer les fiches correspondantes à cette ligne
                                                        $fichesFiltrees = array_filter($detailsChantier['fiches'], function ($fiche) use ($ligne) {
                                                            return stripos($fiche['designation_fiche'] ?? '', $ligne['designation']) !== false ||
                                                                stripos($ligne['designation'], $fiche['designation_fiche'] ?? '') !== false;
                                                        });
                                                        ?>
                                                        <tr class="hover:bg-white transition-colors border-l-4 border-indigo-300">
                                                            <td class="px-2 py-4 text-center w-12">
                                                                <button type="button"
                                                                    onclick="toggleExpand(this, 'ligne-expand-<?= $globalIndex ?>-<?= $indexLigne ?>')"
                                                                    class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gradient-to-br from-indigo-500 to-purple-600 text-white hover:from-indigo-600 hover:to-purple-700 transition-all transform hover:scale-110 shadow-sm">
                                                                    <i class="fas fa-plus text-xs transition-transform duration-300"></i>
                                                                </button>
                                                            </td>
                                                            <td class="px-6 py-4">
                                                                <div class="text-sm font-medium text-gray-900 pl-4">└─ <?= htmlspecialchars($ligne['designation']) ?></div>
                                                                <?php if ($ligne['groupe']): ?>
                                                                    <div class="text-xs text-gray-500 pl-4">Groupe: <?= htmlspecialchars($ligne['groupe']) ?></div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                                <?= number_format($ligne['prix'], 0, ',', ' ') ?> F
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <div class="text-sm font-semibold text-indigo-600">
                                                                    <?= number_format($ligne['montant_debourse_reel'], 0, ',', ' ') ?> F
                                                                </div>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <div class="text-sm font-semibold text-green-600">
                                                                    <?= number_format($ligne['montant_paye'], 0, ',', ' ') ?> F
                                                                </div>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <div class="text-sm font-semibold <?= $montantRestant > 0 ? 'text-orange-600' : 'text-gray-500' ?>">
                                                                    <?= number_format($montantRestant, 0, ',', ' ') ?> F
                                                                </div>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <div class="flex items-center">
                                                                    <div class="w-full bg-gray-200 rounded-full h-2 mr-3">
                                                                        <div class="bg-<?= $pourcentageRealise >= 100 ? 'green' : ($pourcentageRealise >= 50 ? 'yellow' : 'red') ?>-600 h-2 rounded-full"
                                                                            style="width: <?= min($pourcentageRealise, 100) ?>%"></div>
                                                                    </div>
                                                                    <span class="text-sm font-medium text-gray-900 min-w-max"><?= number_format($pourcentageRealise, 1) ?>%</span>
                                                                </div>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <?php if ($pourcentageRealise >= 100): ?>
                                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                                                        ✅ Terminé
                                                                    </span>
                                                                <?php elseif ($pourcentageRealise == 0): ?>
                                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                                        ⏳ En attente
                                                                    </span>
                                                                <?php elseif ($pourcentageRealise < 50): ?>
                                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                                                        🚀 En début
                                                                    </span>
                                                                <?php elseif ($pourcentageRealise < 100): ?>
                                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                                        🔄 En finition
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>

                                                        <!-- Ligne d'expansion détails de ligne -->
                                                        <tr id="ligne-expand-<?= $globalIndex ?>-<?= $indexLigne ?>" class="hidden">
                                                            <td colspan="8" class="px-0 py-0">
                                                                <div class="bg-gradient-to-br from-slate-50 via-blue-50 to-indigo-50 p-6 ml-8 border-l-4 border-indigo-500">
                                                                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                                                        <!-- Détails Montant Devis -->
                                                                        <div class="bg-white rounded-xl shadow-sm p-4 border border-purple-100">
                                                                            <h4 class="flex items-center text-sm font-bold text-purple-700 mb-3">
                                                                                <i class="fas fa-file-contract mr-2 text-purple-500"></i>
                                                                                Montant Devis
                                                                            </h4>
                                                                            <div class="space-y-2">
                                                                                <div class="flex justify-between text-sm">
                                                                                    <span class="text-gray-600">Prix unitaire:</span>
                                                                                    <span class="font-semibold text-gray-900"><?= number_format($ligne['prix'], 0, ',', ' ') ?> F</span>
                                                                                </div>
                                                                                <div class="flex justify-between text-sm">
                                                                                    <span class="text-gray-600">Quantité:</span>
                                                                                    <span class="font-semibold text-gray-900"><?= number_format($ligne['quantite'], 0, ',', ' ') ?></span>
                                                                                </div>
                                                                                <div class="flex justify-between text-sm">
                                                                                    <span class="text-gray-600">Unité:</span>
                                                                                    <span class="font-semibold text-gray-900"><?= htmlspecialchars($ligne['unite_libelle'] ?? '') ?></span>
                                                                                </div>
                                                                                <div class="border-t border-gray-200 pt-2 mt-2 flex justify-between text-sm font-bold">
                                                                                    <span class="text-purple-600">Total Devis:</span>
                                                                                    <span class="text-purple-700"><?= number_format($ligne['total'], 0, ',', ' ') ?> F</span>
                                                                                </div>
                                                                            </div>
                                                                        </div>

                                                                        <!-- Détails Montant Déboursé -->
                                                                        <div class="bg-white rounded-xl shadow-sm p-4 border border-indigo-100">
                                                                            <h4 class="flex items-center text-sm font-bold text-indigo-700 mb-3">
                                                                                <i class="fas fa-hand-holding-usd mr-2 text-indigo-500"></i>
                                                                                Montant Déboursé (<?= number_format($ligne['montant_debourse_reel'], 0, ',', ' ') ?> F)
                                                                            </h4>
                                                                            <div class="space-y-2 text-sm">
                                                                                <?php
                                                                                $deboursesFound = 0;
                                                                                // Chercher les lignes de déboursé pour cette ligne de devis
                                                                                if (!empty($detailsChantier['lignes_debourse'])) {
                                                                                    foreach ($detailsChantier['lignes_debourse'] as $ligneDeb) {
                                                                                        if (
                                                                                            stripos($ligneDeb['designation_ligne_devis'] ?? '', $ligne['designation']) !== false ||
                                                                                            stripos($ligne['designation'], $ligneDeb['designation_ligne_devis'] ?? '') !== false
                                                                                        ) {
                                                                                            $deboursesFound++;
                                                                                            echo '<div class="p-2 bg-indigo-50 rounded">';
                                                                                            echo '<div class="font-semibold text-gray-800 mb-1">' . htmlspecialchars($ligneDeb['designation']) . '</div>';
                                                                                            echo '<div class="text-xs text-gray-400 mb-2">' . htmlspecialchars($ligneDeb['categorie'] ?? '') . '</div>';
                                                                                            echo '<div class="flex justify-between text-xs">';
                                                                                            echo '<span class="text-gray-600">Délai:</span>';
                                                                                            echo '<span class="font-semibold text-gray-900"> Du ' . $ligneDeb['date_debut']  . '  au ' . $ligneDeb['date_fin'] . '</span>';
                                                                                            echo '</div>';
                                                                                            echo '<div class="flex justify-between text-xs font-bold">';
                                                                                            echo '<span class="text-indigo-600">Montant:</span>';
                                                                                            echo '<span class="text-indigo-700">' . number_format($ligneDeb['montant'] ?? 0, 0, ',', ' ') . ' F</span>';
                                                                                            echo '</div>';
                                                                                            echo '</div>';
                                                                                        }
                                                                                    }
                                                                                }
                                                                                if ($deboursesFound === 0) {
                                                                                    echo '<div class="text-gray-400 italic">Aucune ligne de déboursé</div>';
                                                                                }
                                                                                ?>
                                                                            </div>
                                                                        </div>

                                                                        <!-- Fiches de Décaissement -->
                                                                        <div class="bg-white rounded-xl shadow-sm p-4 border border-green-100">
                                                                            <h4 class="flex items-center text-sm font-bold text-green-700 mb-3">
                                                                                <i class="fas fa-receipt mr-2 text-green-500"></i>
                                                                                Fiches Décaissement (<?= count($fichesFiltrees) ?>)
                                                                            </h4>
                                                                            <div class="space-y-2 text-sm max-h-40 overflow-y-auto">
                                                                                <?php if (!empty($fichesFiltrees)): ?>
                                                                                    <?php foreach ($fichesFiltrees as $fiche): ?>
                                                                                        <div class="flex justify-between items-start p-2 bg-green-50 rounded">
                                                                                            <div>
                                                                                                <div class="font-semibold text-gray-900"><?= htmlspecialchars($fiche['num_fiche'] ?? '') ?></div>
                                                                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($fiche['beficiaire_fiche'] ?? '') ?></div>
                                                                                            </div>
                                                                                            <div class="text-right">
                                                                                                <div class="font-semibold text-green-600"><?= number_format($fiche['montant_fiche'], 0, ',', ' ') ?> F</div>
                                                                                                <div class="text-xs">
                                                                                                    <?php if ($fiche['decaisse'] == 1): ?>
                                                                                                        <span class="text-green-600">✅ Décaissé</span>
                                                                                                    <?php else: ?>
                                                                                                        <span class="text-yellow-600">⏳ Attente</span>
                                                                                                    <?php endif; ?>
                                                                                                </div>
                                                                                            </div>
                                                                                        </div>
                                                                                    <?php endforeach; ?>
                                                                                <?php else: ?>
                                                                                    <div class="text-gray-400 italic">Aucune fiche associée</div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                                <?php $globalIndex++; ?>
                            <?php endforeach; ?>
                        </tbody>