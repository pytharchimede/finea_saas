<?php
session_start();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Dashboard.php';
require_once __DIR__ . '/../model/AuditTrail.php';

$pdo = Database::getConnection();
$dashboardModel = new Dashboard($pdo);

// Récupérer toutes les données
$donneesDashboard = $dashboardModel->getDonneesDashboard();
$alertes = $dashboardModel->getAlertes();

// Configuration pour l'export
$exportType = $_GET['type'] ?? 'pdf'; // pdf, excel, csv
$dateExport = date('Y-m-d_H-i-s');

switch ($exportType) {
    case 'excel':
        \AuditTrail::logFileEvent('export', [
            'module' => 'decaissement',
            'action' => 'dashboard_export',
            'entity_type' => 'dashboard',
            'entity_label' => 'Tableau de bord décaissement',
            'format' => 'excel',
            'details' => [
                'alert_count' => is_countable($alertes ?? null) ? count($alertes) : null,
            ],
        ], $pdo);
        exportExcel($donneesDashboard, $dateExport);
        break;
    case 'csv':
        \AuditTrail::logFileEvent('export', [
            'module' => 'decaissement',
            'action' => 'dashboard_export',
            'entity_type' => 'dashboard',
            'entity_label' => 'Tableau de bord décaissement',
            'format' => 'csv',
            'details' => [
                'alert_count' => is_countable($alertes ?? null) ? count($alertes) : null,
            ],
        ], $pdo);
        exportCSV($donneesDashboard, $dateExport);
        break;
    case 'pdf':
    default:
        \AuditTrail::logFileEvent('export', [
            'module' => 'decaissement',
            'action' => 'dashboard_export',
            'entity_type' => 'dashboard',
            'entity_label' => 'Tableau de bord décaissement',
            'format' => 'pdf',
            'details' => [
                'alert_count' => is_countable($alertes ?? null) ? count($alertes) : null,
            ],
        ], $pdo);
        exportPDF($donneesDashboard, $alertes, $dateExport);
        break;
}

function exportPDF($donnees, $alertes, $dateExport)
{
    // Configuration PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="tableau_de_bord_' . $dateExport . '.pdf"');

    // Ici, vous pourriez utiliser TCPDF ou FPDF pour générer un PDF
    // Pour l'instant, on génère un rapport HTML simple

    ob_start();
?>
    <!DOCTYPE html>
    <html>

    <head>
        <meta charset="UTF-8">
        <title>Rapport Dashboard BANAMUR</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
            }

            .header {
                text-align: center;
                margin-bottom: 30px;
            }

            .section {
                margin-bottom: 25px;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 15px;
            }

            .stat-card {
                border: 1px solid #ddd;
                padding: 15px;
                border-radius: 5px;
            }

            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }

            th,
            td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }

            th {
                background-color: #f5f5f5;
            }

            .alert {
                padding: 10px;
                margin: 5px 0;
                border-radius: 5px;
            }

            .alert-warning {
                background-color: #fff3cd;
                border: 1px solid #ffeaa7;
            }

            .alert-info {
                background-color: #d1ecf1;
                border: 1px solid #74c0fc;
            }
        </style>
    </head>

    <body>
        <div class="header">
            <h1>📊 RAPPORT TABLEAU DE BORD BANAMUR</h1>
            <p>Généré le <?= date('d/m/Y à H:i:s') ?></p>
        </div>

        <!-- Alertes -->
        <?php if (!empty($alertes)): ?>
            <div class="section">
                <h2>🚨 Alertes</h2>
                <?php foreach ($alertes as $alerte): ?>
                    <div class="alert alert-<?= $alerte['type'] ?>">
                        <strong><?= $alerte['titre'] ?></strong><br>
                        <?= $alerte['message'] ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Statistiques principales -->
        <div class="section">
            <h2>📈 Statistiques Principales</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Fiches</h3>
                    <p><strong><?= number_format($donnees['stats_fiches']['total_fiches']) ?></strong></p>
                    <small><?= number_format($donnees['stats_fiches']['fiches_approuvees']) ?> approuvées</small>
                </div>
                <div class="stat-card">
                    <h3>Chantiers Actifs</h3>
                    <p><strong><?= number_format($donnees['stats_chantiers']['total_chantiers']) ?></strong></p>
                    <small><?= number_format($donnees['stats_chantiers']['nouveaux_mois']) ?> ce mois</small>
                </div>
                <div class="stat-card">
                    <h3>Montant Total</h3>
                    <p><strong><?= number_format($donnees['stats_fiches']['montant_total'], 0, ',', ' ') ?> F</strong></p>
                    <small><?= number_format($donnees['stats_fiches']['montant_decaisse'], 0, ',', ' ') ?> F décaissé</small>
                </div>
                <div class="stat-card">
                    <h3>Devis Actifs</h3>
                    <p><strong><?= number_format($donnees['stats_devis']['devis_actifs']) ?></strong></p>
                    <small><?= number_format($donnees['stats_devis']['montant_total_ttc'], 0, ',', ' ') ?> F TTC</small>
                </div>
            </div>
        </div>

        <!-- Détail des chantiers -->
        <div class="section">
            <h2>🏗️ Détail des Chantiers</h2>
            <table>
                <thead>
                    <tr>
                        <th>Chantier</th>
                        <th>Budget</th>
                        <th>Dépensé</th>
                        <th>Progression</th>
                        <th>Fiches</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($donnees['chantiers_budgets'] as $chantier): ?>
                        <tr>
                            <td><?= htmlspecialchars($chantier['lib_chantier']) ?><br><small><?= htmlspecialchars($chantier['num_chantier']) ?></small></td>
                            <td><?= number_format($chantier['budget_total'], 0, ',', ' ') ?> F</td>
                            <td><?= number_format($chantier['total_depenses'], 0, ',', ' ') ?> F</td>
                            <td><?= number_format($chantier['pourcentage_consomme'], 1) ?>%</td>
                            <td><?= $chantier['nombre_fiches'] ?></td>
                            <td>
                                <?php if ($chantier['pourcentage_consomme'] > 90): ?>
                                    🚨 Critique
                                <?php elseif ($chantier['pourcentage_consomme'] > 70): ?>
                                    ⚠️ Attention
                                <?php else: ?>
                                    ✅ Normal
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Top bénéficiaires -->
        <div class="section">
            <h2>👥 Top 10 Bénéficiaires</h2>
            <table>
                <thead>
                    <tr>
                        <th>Bénéficiaire</th>
                        <th>Nombre de Fiches</th>
                        <th>Montant Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($donnees['top_beneficiaires'] as $beneficiaire): ?>
                        <tr>
                            <td><?= htmlspecialchars($beneficiaire['beficiaire_fiche']) ?></td>
                            <td><?= number_format($beneficiaire['nombre_fiches']) ?></td>
                            <td><?= number_format($beneficiaire['montant_total'], 0, ',', ' ') ?> F</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="section">
            <p><em>Rapport généré automatiquement par le système BANAMUR DécaissApp</em></p>
        </div>
    </body>

    </html>
<?php
    $html = ob_get_clean();

    // Pour une vraie implémentation PDF, vous devriez utiliser une librairie comme TCPDF
    // Pour l'instant, on retourne le HTML
    echo $html;
}

function exportExcel($donnees, $dateExport)
{
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="tableau_de_bord_' . $dateExport . '.xls"');

    echo "Rapport Dashboard BANAMUR\n";
    echo "Généré le " . date('d/m/Y H:i:s') . "\n\n";

    echo "STATISTIQUES PRINCIPALES\n";
    echo "Total Fiches\t" . $donnees['stats_fiches']['total_fiches'] . "\n";
    echo "Fiches Approuvées\t" . $donnees['stats_fiches']['fiches_approuvees'] . "\n";
    echo "Chantiers Actifs\t" . $donnees['stats_chantiers']['total_chantiers'] . "\n";
    echo "Montant Total\t" . $donnees['stats_fiches']['montant_total'] . "\n";
    echo "\n";

    echo "DÉTAIL CHANTIERS\n";
    echo "Chantier\tBudget\tDépensé\tProgression\tFiches\n";
    foreach ($donnees['chantiers_budgets'] as $chantier) {
        echo htmlspecialchars($chantier['lib_chantier']) . "\t";
        echo $chantier['budget_total'] . "\t";
        echo $chantier['total_depenses'] . "\t";
        echo $chantier['pourcentage_consomme'] . "%\t";
        echo $chantier['nombre_fiches'] . "\n";
    }
}

function exportCSV($donnees, $dateExport)
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="tableau_de_bord_' . $dateExport . '.csv"');

    $output = fopen('php://output', 'w');

    // En-têtes CSV pour les chantiers
    fputcsv($output, ['Chantier', 'Numéro', 'Budget', 'Dépensé', 'Progression (%)', 'Nombre Fiches', 'Statut']);

    foreach ($donnees['chantiers_budgets'] as $chantier) {
        $statut = $chantier['pourcentage_consomme'] > 90 ? 'Critique' : ($chantier['pourcentage_consomme'] > 70 ? 'Attention' : 'Normal');

        fputcsv($output, [
            $chantier['lib_chantier'],
            $chantier['num_chantier'],
            $chantier['budget_total'],
            $chantier['total_depenses'],
            $chantier['pourcentage_consomme'],
            $chantier['nombre_fiches'],
            $statut
        ]);
    }

    fclose($output);
}
?>