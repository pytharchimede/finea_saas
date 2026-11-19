<?php

include 'auth_check.php';
include 'header/header_dashboard.php';
require_once 'model/Database.php';
require_once 'services/CommercialProjectFinanceMigrationService.php';
$dashboardPdo = Database::getConnection();
(new CommercialProjectFinanceMigrationService($dashboardPdo))->ensureSchema();
$quoteStats = $dashboardPdo->query("SELECT COUNT(*) total, SUM(NOT EXISTS(SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL) AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL))) active_quotes, SUM(EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL)) active_sites, SUM(NOT EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL) AND NOT EXISTS(SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL)) without_site, SUM(EXISTS(SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL)) archived, COALESCE(SUM(CASE WHEN NOT EXISTS(SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL) THEN d.total_ttc ELSE 0 END),0) active_value FROM devis_banamur d WHERE COALESCE(d.masque,0)=0")->fetch(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rédaction de Devis - BTP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        body {
            background: radial-gradient(1200px 600px at 0% 0%, #eef2ff 0%, transparent 70%),
                radial-gradient(1200px 600px at 100% 0%, #ecfeff 0%, transparent 70%),
                linear-gradient(180deg, #ffffff 0%, #fafafa 100%);
        }
    </style>
</head>

<body>

    <div class="min-h-screen py-12 px-6 relative overflow-hidden">
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute -top-32 -left-32 w-96 h-96 bg-indigo-200 rounded-full blur-3xl opacity-20"></div>
            <div class="absolute bottom-0 right-0 w-[28rem] h-[28rem] bg-purple-200 rounded-full blur-3xl opacity-20"></div>
        </div>

        <div class="max-w-6xl w-full mx-auto">
            <!-- Topbar -->
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl overflow-hidden shadow-sm border border-gray-200 bg-white">
                        <img src="https://fidest.ci/logo/new_logo_banamur.jpg" alt="Logo" class="w-full h-full object-cover" />
                    </div>
                    <div>
                        <h1 class="text-2xl md:text-3xl font-semibold tracking-tight text-gray-800">Module Devis</h1>
                        <p class="text-sm text-gray-600">Rédiger, suivre et piloter vos devis</p>
                        <div class="mt-2 h-1 w-24 rounded-full bg-gradient-to-r from-indigo-500 to-purple-500"></div>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <a href="chantiers/regularisation.php" class="px-3 py-2 text-sm font-semibold rounded-2xl bg-amber-400 text-slate-950 hover:bg-amber-300 transition inline-flex items-center gap-2">
                        <i class="fas fa-shield-alt"></i>
                        <span>Régulariser les chantiers</span>
                    </a>
                    <a href="selection_portail.php" class="px-3 py-2 text-sm font-semibold rounded-2xl bg-white/70 backdrop-blur border border-gray-200 hover:bg-indigo-50 hover:border-indigo-200 transition inline-flex items-center gap-2">
                        <i class="fas fa-th-large"></i>
                        <span>Portail</span>
                    </a>
                    <a href="deconnex.php" class="px-3 py-2 text-sm font-semibold rounded-2xl bg-red-600 text-white hover:bg-red-700 transition inline-flex items-center gap-2">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>

            <!-- Menu (même logique, design portail) -->
            <div class="mt-6 bg-white/70 backdrop-blur border border-gray-200 rounded-3xl p-4 shadow-[0_8px_30px_rgb(0,0,0,0.05)]">
                <?php
                $menuVariant = 'portal';
                $menuShowLogout = false;
                include 'menu.php';
                ?>
            </div>

            <section class="mt-8 grid grid-cols-2 gap-4 lg:grid-cols-5">
                <?php foreach ([['Devis actifs',(int)($quoteStats['active_quotes']??0),'fa-file-circle-check','text-indigo-700'],['Avec chantier actif',(int)($quoteStats['active_sites']??0),'fa-helmet-safety','text-emerald-700'],['Sans chantier',(int)($quoteStats['without_site']??0),'fa-hourglass-half','text-amber-700'],['Archivés',(int)($quoteStats['archived']??0),'fa-box-archive','text-slate-700'],['Valeur active',number_format((float)($quoteStats['active_value']??0),0,',',' ').' FCFA','fa-chart-line','text-violet-700']] as $metric): ?>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><i class="fas <?=$metric[2]?> <?=$metric[3]?>"></i><p class="mt-3 text-xs font-bold uppercase tracking-wide text-slate-500"><?=$metric[0]?></p><strong class="mt-1 block text-xl text-slate-950"><?=$metric[1]?></strong></article>
                <?php endforeach; ?>
            </section>

            <!-- Cartes d'accès rapides (design portail) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mt-10">

                <a href="index.php" class="relative bg-white/80 backdrop-blur-md rounded-3xl p-8 border border-gray-200 shadow-[0_8px_30px_rgb(0,0,0,0.05)] hover:shadow-[0_15px_40px_rgb(0,0,0,0.08)] transition-all duration-300 hover:-translate-y-2 group">
                    <div class="absolute inset-0 rounded-2xl bg-gradient-to-br from-white to-gray-50 group-hover:from-indigo-50 group-hover:to-purple-50 transition"></div>
                    <div class="relative">
                        <div class="w-16 h-16 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-md group-hover:scale-110 transition-transform duration-300" style="background: linear-gradient(135deg, #2563eb, #111827);">
                            <i class="fas fa-home"></i>
                        </div>
                        <h3 class="font-semibold text-gray-800 text-base leading-tight">Accueil</h3>
                        <p class="mt-2 text-xs text-gray-500 h-10">Accéder à l'espace principal</p>
                        <div class="mt-6 w-full py-3 text-sm font-semibold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition-all duration-300 flex items-center justify-center gap-2">
                            <span>Entrer</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="generer_devis.php" class="relative bg-white/80 backdrop-blur-md rounded-3xl p-8 border border-gray-200 shadow-[0_8px_30px_rgb(0,0,0,0.05)] hover:shadow-[0_15px_40px_rgb(0,0,0,0.08)] transition-all duration-300 hover:-translate-y-2 group">
                    <div class="absolute inset-0 rounded-2xl bg-gradient-to-br from-white to-gray-50 group-hover:from-indigo-50 group-hover:to-purple-50 transition"></div>
                    <div class="relative">
                        <div class="w-16 h-16 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-md group-hover:scale-110 transition-transform duration-300" style="background: linear-gradient(135deg, #2563eb, #111827);">
                            <i class="fas fa-file-signature"></i>
                        </div>
                        <h3 class="font-semibold text-gray-800 text-base leading-tight">Générer un devis</h3>
                        <p class="mt-2 text-xs text-gray-500 h-10">Créer un devis et ses lignes</p>
                        <div class="mt-6 w-full py-3 text-sm font-semibold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition-all duration-300 flex items-center justify-center gap-2">
                            <span>Entrer</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="liste_devis.php" class="relative bg-white/80 backdrop-blur-md rounded-3xl p-8 border border-gray-200 shadow-[0_8px_30px_rgb(0,0,0,0.05)] hover:shadow-[0_15px_40px_rgb(0,0,0,0.08)] transition-all duration-300 hover:-translate-y-2 group">
                    <div class="absolute inset-0 rounded-2xl bg-gradient-to-br from-white to-gray-50 group-hover:from-indigo-50 group-hover:to-purple-50 transition"></div>
                    <div class="relative">
                        <div class="w-16 h-16 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-md group-hover:scale-110 transition-transform duration-300" style="background: linear-gradient(135deg, #16a34a, #111827);">
                            <i class="fas fa-file-contract"></i>
                        </div>
                        <h3 class="font-semibold text-gray-800 text-base leading-tight">Liste des devis</h3>
                        <p class="mt-2 text-xs text-gray-500 h-10">Consulter et gérer les devis existants</p>
                        <div class="mt-6 w-full py-3 text-sm font-semibold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition-all duration-300 flex items-center justify-center gap-2">
                            <span>Entrer</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="liste_facture.php" class="relative bg-white/80 backdrop-blur-md rounded-3xl p-8 border border-gray-200 shadow-[0_8px_30px_rgb(0,0,0,0.05)] hover:shadow-[0_15px_40px_rgb(0,0,0,0.08)] transition-all duration-300 hover:-translate-y-2 group">
                    <div class="absolute inset-0 rounded-2xl bg-gradient-to-br from-white to-gray-50 group-hover:from-indigo-50 group-hover:to-purple-50 transition"></div>
                    <div class="relative">
                        <div class="w-16 h-16 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-md group-hover:scale-110 transition-transform duration-300" style="background: linear-gradient(135deg, #0f766e, #111827);">
                            <i class="fas fa-file-invoice"></i>
                        </div>
                        <h3 class="font-semibold text-gray-800 text-base leading-tight">Liste des factures</h3>
                        <p class="mt-2 text-xs text-gray-500 h-10">Suivre les factures liées aux devis</p>
                        <div class="mt-6 w-full py-3 text-sm font-semibold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition-all duration-300 flex items-center justify-center gap-2">
                            <span>Entrer</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="liste_client.php" class="relative bg-white/80 backdrop-blur-md rounded-3xl p-8 border border-gray-200 shadow-[0_8px_30px_rgb(0,0,0,0.05)] hover:shadow-[0_15px_40px_rgb(0,0,0,0.08)] transition-all duration-300 hover:-translate-y-2 group">
                    <div class="absolute inset-0 rounded-2xl bg-gradient-to-br from-white to-gray-50 group-hover:from-indigo-50 group-hover:to-purple-50 transition"></div>
                    <div class="relative">
                        <div class="w-16 h-16 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-md group-hover:scale-110 transition-transform duration-300" style="background: linear-gradient(135deg, #7e22ce, #111827);">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="font-semibold text-gray-800 text-base leading-tight">Liste des clients</h3>
                        <p class="mt-2 text-xs text-gray-500 h-10">Gérer le répertoire clients</p>
                        <div class="mt-6 w-full py-3 text-sm font-semibold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition-all duration-300 flex items-center justify-center gap-2">
                            <span>Entrer</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

                <a href="liste_offre.php" class="relative bg-white/80 backdrop-blur-md rounded-3xl p-8 border border-gray-200 shadow-[0_8px_30px_rgb(0,0,0,0.05)] hover:shadow-[0_15px_40px_rgb(0,0,0,0.08)] transition-all duration-300 hover:-translate-y-2 group">
                    <div class="absolute inset-0 rounded-2xl bg-gradient-to-br from-white to-gray-50 group-hover:from-indigo-50 group-hover:to-purple-50 transition"></div>
                    <div class="relative">
                        <div class="w-16 h-16 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-md group-hover:scale-110 transition-transform duration-300" style="background: linear-gradient(135deg, #f59e0b, #111827);">
                            <i class="fas fa-gift"></i>
                        </div>
                        <h3 class="font-semibold text-gray-800 text-base leading-tight">Liste des projets</h3>
                        <p class="mt-2 text-xs text-gray-500 h-10">Consulter les projets et leurs références</p>
                        <div class="mt-6 w-full py-3 text-sm font-semibold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition-all duration-300 flex items-center justify-center gap-2">
                            <span>Entrer</span>
                            <i class="fas fa-arrow-right text-xs"></i>
                        </div>
                    </div>
                </a>

            </div>

            <!-- Graphique (carte cohérente) -->
            <div class="mt-10 bg-white/80 backdrop-blur-md rounded-3xl p-8 border border-gray-200 shadow-[0_8px_30px_rgb(0,0,0,0.05)]">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-800">Nombre de devis édités par jour</h2>
                        <p class="text-xs text-gray-500">Données issues de l'historique des devis</p>
                    </div>
                    <div class="text-xs text-gray-500 inline-flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-indigo-500"></span>
                        <span>Devis édités</span>
                    </div>
                </div>
                <div id="chart" class="mt-6"></div>
            </div>

            <!-- Footer minimal, cohérent -->
            <footer class="mt-12 text-center text-xs text-gray-500">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-white/60 backdrop-blur border border-gray-200">
                    <span>&copy; <?php echo gmdate('Y'); ?> BANAMUR INDUSTRIES & TECH.</span>
                    <span class="text-gray-300">•</span>
                    <span>Tous droits réservés.</span>
                </div>
            </footer>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            fetch('request/get_devis_data.php') // Remplacez par le chemin réel
                .then(response => response.json())
                .then(data => {
                    var options = {
                        chart: {
                            type: 'line',
                            height: 350
                        },
                        series: [{
                            name: 'Devis Édités',
                            data: data.data
                        }],
                        xaxis: {
                            categories: data.labels
                        },
                        colors: ['#4f46e5'],
                        stroke: {
                            curve: 'smooth'
                        },
                        title: {
                            text: ''
                        },
                        grid: {
                            borderColor: '#e0e0e0'
                        }
                    };

                    var chart = new ApexCharts(document.querySelector("#chart"), options);
                    chart.render();
                });
        });
    </script>

</body>

</html>
