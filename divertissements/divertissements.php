<?php

require_once __DIR__ . '/../auth_check.php';

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Divertissements</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <div class="min-h-screen py-10 px-6 bg-gradient-to-b from-yellow-50 via-emerald-50 to-sky-50">
        <div class="max-w-5xl mx-auto">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-gray-900">Divertissements</h1>
                    <p class="text-sm text-gray-700 mt-1">Mini-jeux internes · 2 joueurs · ultra rapide</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="helpBtn" class="px-3 py-2 text-sm font-semibold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition inline-flex items-center gap-2">
                        <i class="fas fa-circle-question"></i>
                        <span>Aide</span>
                    </button>
                    <a href="../selection_portail.php" class="px-3 py-2 text-sm font-semibold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition inline-flex items-center gap-2">
                        <i class="fas fa-th-large"></i>
                        <span>Portail</span>
                    </a>
                    <a href="../deconnex.php" class="px-3 py-2 text-sm font-semibold rounded-2xl bg-red-600 text-white hover:bg-red-700 transition inline-flex items-center gap-2">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Déconnexion</span>
                    </a>
                </div>
            </div>

            <div class="mt-8 bg-white/80 backdrop-blur border border-white/70 rounded-[2rem] p-6 shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-500 text-white flex items-center justify-center">
                        <i class="fas fa-gamepad"></i>
                    </div>
                    <div>
                        <div class="font-bold text-gray-900">Choisis un jeu</div>
                        <div class="text-xs text-gray-700">Astuce : sur clavier, J1 = A · J2 = L (quand c’est indiqué).</div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mt-8">
                <a href="duel_clavier.php" class="relative bg-white rounded-[2rem] p-7 border-2 border-emerald-100 shadow-sm hover:shadow-md transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-sm bg-gradient-to-br from-emerald-500 to-sky-500 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-keyboard"></i>
                    </div>
                    <h3 class="font-extrabold text-gray-900 text-base leading-tight">Duel clavier</h3>
                    <p class="mt-2 text-xs text-gray-700">10 secondes. J1 tape <span class="font-mono">A</span>, J2 tape <span class="font-mono">L</span>. Le meilleur score gagne.</p>
                    <div class="mt-6 w-full py-3 text-sm font-extrabold rounded-2xl bg-emerald-600 text-white hover:bg-emerald-700 transition-all duration-300 flex items-center justify-center gap-2">
                        <span>Jouer</span>
                        <i class="fas fa-bolt text-xs"></i>
                    </div>
                </a>

                <a href="reflex_duel.php" class="relative bg-white rounded-[2rem] p-7 border-2 border-sky-100 shadow-sm hover:shadow-md transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-sm bg-gradient-to-br from-sky-500 to-indigo-500 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-stopwatch"></i>
                    </div>
                    <h3 class="font-extrabold text-gray-900 text-base leading-tight">Duel réflexe</h3>
                    <p class="mt-2 text-xs text-gray-700">Attends le “GO” puis appuie : J1 <span class="font-mono">A</span>, J2 <span class="font-mono">L</span>. Faux départ = perdu.</p>
                    <div class="mt-6 w-full py-3 text-sm font-extrabold rounded-2xl bg-sky-600 text-white hover:bg-sky-700 transition-all duration-300 flex items-center justify-center gap-2">
                        <span>Jouer</span>
                        <i class="fas fa-flag-checkered text-xs"></i>
                    </div>
                </a>

                <a href="morpion.php" class="relative bg-white rounded-[2rem] p-7 border-2 border-amber-100 shadow-sm hover:shadow-md transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-sm bg-gradient-to-br from-amber-500 to-rose-500 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-hashtag"></i>
                    </div>
                    <h3 class="font-extrabold text-gray-900 text-base leading-tight">Morpion</h3>
                    <p class="mt-2 text-xs text-gray-700">Classique 3×3 en local. Tour par tour, premier alignement gagne.</p>
                    <div class="mt-6 w-full py-3 text-sm font-extrabold rounded-2xl bg-amber-600 text-white hover:bg-amber-700 transition-all duration-300 flex items-center justify-center gap-2">
                        <span>Jouer</span>
                        <i class="fas fa-table-cells text-xs"></i>
                    </div>
                </a>

                <a href="quatre_indices_un_mot.php" class="relative bg-white rounded-[2rem] p-7 border-2 border-indigo-100 shadow-sm hover:shadow-md transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-sm bg-gradient-to-br from-indigo-500 to-violet-500 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-images"></i>
                    </div>
                    <h3 class="font-extrabold text-gray-900 text-base leading-tight">4 indices 1 mot</h3>
                    <p class="mt-2 text-xs text-gray-700">Devine le mot à partir de 4 “images” (emoji/icônes). Rapide, addictif, et 100% offline.</p>
                    <div class="mt-6 w-full py-3 text-sm font-extrabold rounded-2xl bg-indigo-600 text-white hover:bg-indigo-700 transition-all duration-300 flex items-center justify-center gap-2">
                        <span>Jouer</span>
                        <i class="fas fa-wand-magic-sparkles text-xs"></i>
                    </div>
                </a>

                <a href="pierre_feuille_ciseaux.php" class="relative bg-white rounded-[2rem] p-7 border-2 border-emerald-100 shadow-sm hover:shadow-md transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-sm bg-gradient-to-br from-emerald-500 to-teal-500 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-hand-fist"></i>
                    </div>
                    <h3 class="font-extrabold text-gray-900 text-base leading-tight">Pierre · Feuille · Ciseaux</h3>
                    <p class="mt-2 text-xs text-gray-700">2 joueurs, manche rapide. Choisis en secret puis révèle en même temps.</p>
                    <div class="mt-6 w-full py-3 text-sm font-extrabold rounded-2xl bg-emerald-600 text-white hover:bg-emerald-700 transition-all duration-300 flex items-center justify-center gap-2">
                        <span>Jouer</span>
                        <i class="fas fa-hand-scissors text-xs"></i>
                    </div>
                </a>

                <a href="pendu.php" class="relative bg-white rounded-[2rem] p-7 border-2 border-rose-100 shadow-sm hover:shadow-md transition-all duration-300 hover:-translate-y-1 group">
                    <div class="w-14 h-14 mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-sm bg-gradient-to-br from-rose-500 to-amber-500 group-hover:scale-110 transition-transform duration-300">
                        <i class="fas fa-spell-check"></i>
                    </div>
                    <h3 class="font-extrabold text-gray-900 text-base leading-tight">Pendu</h3>
                    <p class="mt-2 text-xs text-gray-700">Devine le mot lettre par lettre. Idéal en pause café.</p>
                    <div class="mt-6 w-full py-3 text-sm font-extrabold rounded-2xl bg-rose-600 text-white hover:bg-rose-700 transition-all duration-300 flex items-center justify-center gap-2">
                        <span>Jouer</span>
                        <i class="fas fa-keyboard text-xs"></i>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <script src="../js/auth_guard.js"></script>
    <script src="../js/help_tour.js"></script>
    <script>
        (function() {
            if (!window.FineaHelpTour) return;

            var tour = window.FineaHelpTour.create({
                key: 'divertissements_home',
                version: '1.0.0',
                helpButtonId: 'helpBtn',
                auto: true,
                steps: [{
                        title: 'Bienvenue 👋',
                        text: 'Ici tu trouves des mini-jeux internes. Le guide ne s’affiche qu’une fois (ou quand il est mis à jour).'
                    },
                    {
                        title: 'Choisir un jeu',
                        text: 'Clique sur une carte pour démarrer. Chaque jeu a ses propres règles et son bouton “Aide”.',
                        selector: '.grid'
                    },
                    {
                        title: 'Raccourci',
                        text: 'Astuce : certains jeux utilisent le clavier (J1=A · J2=L).',
                        selector: '.mt-8.bg-white\\/80'
                    },
                    {
                        title: 'Retour',
                        text: 'Tu peux revenir au Portail ou te déconnecter à tout moment.',
                        selector: 'a[href="../selection_portail.php"]'
                    }
                ]
            });

            tour.bindHelpButton();
            tour.autoStartIfNeeded();
        })();
    </script>
</body>

</html>