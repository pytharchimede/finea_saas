<?php

require_once __DIR__.'/Life.php';
Life::gameGuard();

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>4 indices 1 mot</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <div class="min-h-screen py-10 px-6 bg-gradient-to-b from-indigo-50 via-violet-50 to-sky-50">
        <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-gray-900">4 indices · 1 mot</h1>
                    <p class="text-sm text-gray-700 mt-1">Devine le mot à partir de 4 “images” (SVG)</p>
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

            <div class="mt-8 bg-white/90 backdrop-blur border border-white/70 rounded-[2rem] p-6 shadow-sm">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <div class="text-xs text-gray-600">Niveau</div>
                        <div class="text-lg font-extrabold text-gray-900" id="levelLabel">—</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Score</div>
                        <div class="text-lg font-extrabold text-gray-900" id="scoreLabel">0</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="nextBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-indigo-600 text-white hover:bg-indigo-700 transition inline-flex items-center gap-2">
                            <i class="fas fa-forward"></i>
                            <span>Suivant</span>
                        </button>
                        <button type="button" id="revealBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition inline-flex items-center gap-2">
                            <i class="fas fa-eye"></i>
                            <span>Révéler</span>
                        </button>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-2 gap-4" id="tiles"></div>

                <div class="mt-6 rounded-[1.5rem] border-2 border-indigo-100 bg-gradient-to-br from-white to-indigo-50 p-5">
                    <div class="text-xs font-semibold text-gray-700">Mot</div>
                    <div class="mt-2 text-2xl md:text-3xl font-extrabold tracking-widest text-gray-900" id="wordMask">—</div>
                    <div class="mt-4 flex flex-col md:flex-row gap-3 md:items-center">
                        <input id="answerInput" type="text" class="flex-1 px-4 py-3 rounded-2xl border-2 border-indigo-100 focus:outline-none focus:ring-4 focus:ring-indigo-200" placeholder="Ta réponse…" autocomplete="off" />
                        <button type="button" id="checkBtn" class="px-5 py-3 text-sm font-extrabold rounded-2xl bg-gray-900 text-white hover:bg-gray-800 transition inline-flex items-center justify-center gap-2">
                            <i class="fas fa-check"></i>
                            <span>Valider</span>
                        </button>
                    </div>
                    <div class="mt-3 text-xs" id="feedback"></div>
                </div>

                <div class="mt-6 text-xs text-gray-700">Pas d’images externes : les “images” sont des SVG inline (donc safe/offline).</div>
            </div>
        </div>
    </div>

    <script src="../js/auth_guard.js"></script>
    <script src="../js/help_tour.js"></script>
    <script>
        (function() {
            const ICONS = {
                cloud_rain: {
                    label: 'Nuage + pluie',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-indigo-600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M22 40h23a10 10 0 0 0 0-20 14 14 0 0 0-27-3 9 9 0 0 0 4 23z" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M24 46l-4 8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M34 46l-4 8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M44 46l-4 8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                umbrella: {
                    label: 'Parapluie',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-indigo-600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M12 30c5-12 35-12 40 0" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M12 30c3 8 9 8 10 0c1 8 9 8 10 0c1 8 9 8 10 0c1 8 9 8 10 0" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M32 30v18" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M32 48c0 5-7 5-7 0" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                droplet: {
                    label: 'Goutte',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-indigo-600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M32 10c10 13 16 22 16 31a16 16 0 1 1-32 0c0-9 6-18 16-31z" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M24 42c1 5 5 8 10 8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                sun_cloud: {
                    label: 'Soleil + nuage',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-indigo-600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="22" cy="22" r="8" stroke="currentColor" stroke-width="3"/>
                            <path d="M22 6v6M22 32v6M6 22h6M32 22h6M11 11l4 4M29 29l4 4M11 33l4-4M29 15l4-4" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M26 44h20a9 9 0 0 0 0-18 12 12 0 0 0-23-2 8 8 0 0 0 3 20z" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `.trim()
                },
                flame: {
                    label: 'Flamme',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-rose-600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M32 10c6 10 2 14 9 20c6 5 7 18-9 24C16 48 17 35 23 28c4-5 4-10 9-18z" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M30 30c2 4 0 6 3 9c2 2 2 7-3 9c-6-2-6-7-4-10c1-2 2-4 4-8z" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `.trim()
                },
                extinguisher: {
                    label: 'Extincteur',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-rose-600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M28 10h12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <rect x="24" y="14" width="16" height="34" rx="8" stroke="currentColor" stroke-width="3"/>
                            <path d="M40 20h8c4 0 6 2 6 6" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M46 26h10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M32 48v6" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                volcano: {
                    label: 'Volcan',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-rose-600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M14 52l14-30h8l14 30H14z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M28 22l4 6l4-6" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M24 18c2-4 6-6 8-10c2 4 6 6 8 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `.trim()
                },
                heat: {
                    label: 'Chaleur',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-rose-600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M18 16c6 6-6 10 0 16s-6 10 0 16" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M32 16c6 6-6 10 0 16s-6 10 0 16" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M46 16c6 6-6 10 0 16s-6 10 0 16" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `.trim()
                },
                coffee: {
                    label: 'Café',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-amber-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M18 26h24v16a10 10 0 0 1-10 10H28a10 10 0 0 1-10-10V26z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M42 30h6a6 6 0 0 1 0 12h-6" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M24 16c3 3-1 4 2 7" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M32 16c3 3-1 4 2 7" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                croissant: {
                    label: 'Viennoiserie',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-amber-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M18 38c2-12 10-18 14-18s12 6 14 18c-6 6-10 8-14 8s-8-2-14-8z" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M26 28c2 6 6 10 6 18" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M38 28c-2 6-6 10-6 18" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                clock: {
                    label: 'Horloge',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-amber-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="32" cy="32" r="18" stroke="currentColor" stroke-width="3"/>
                            <path d="M32 22v12l8 4" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `.trim()
                },
                briefcase: {
                    label: 'Malette',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-amber-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="16" y="24" width="32" height="26" rx="4" stroke="currentColor" stroke-width="3"/>
                            <path d="M24 24v-4a4 4 0 0 1 4-4h8a4 4 0 0 1 4 4v4" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M16 36h32" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                helmet: {
                    label: 'Casque',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-orange-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M16 36a16 16 0 0 1 32 0v6H16v-6z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M24 24v12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M40 24v12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M16 42h32" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                crane: {
                    label: 'Grue',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-orange-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M18 52h10V16h18" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M28 18l8 6" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M46 16v18" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M46 34l-6 6" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M40 40h6" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                brick: {
                    label: 'Brique',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-orange-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="14" y="22" width="36" height="22" rx="4" stroke="currentColor" stroke-width="3"/>
                            <path d="M14 33h36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M26 22v11" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M38 33v11" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                vest: {
                    label: 'Gilet',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-orange-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M22 14l10 10l10-10l6 10v28H16V24l6-10z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M28 28h8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M24 36h16" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                invoice: {
                    label: 'Facture',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-emerald-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M20 12h24v40l-4-3l-4 3l-4-3l-4 3l-4-3l-4 3V12z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M26 22h12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M26 30h16" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M26 38h10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                card: {
                    label: 'Carte',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-emerald-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="14" y="20" width="36" height="24" rx="6" stroke="currentColor" stroke-width="3"/>
                            <path d="M14 28h36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <rect x="20" y="33" width="10" height="7" rx="2" stroke="currentColor" stroke-width="3"/>
                        </svg>
                    `.trim()
                },
                checkmark: {
                    label: 'Validé',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-emerald-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="32" cy="32" r="18" stroke="currentColor" stroke-width="3"/>
                            <path d="M24 32l6 6l12-14" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `.trim()
                },
                calendar: {
                    label: 'Calendrier',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-emerald-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="16" y="18" width="32" height="30" rx="6" stroke="currentColor" stroke-width="3"/>
                            <path d="M16 26h32" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M24 14v8M40 14v8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M24 32h6M34 32h6M24 38h6M34 38h6" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                warning: {
                    label: 'Attention',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-amber-600" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M32 12l22 40H10l22-40z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M32 26v12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M32 44h.01" stroke="currentColor" stroke-width="5" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                plane: {
                    label: 'Avion',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-sky-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M10 34l44-14-16 16 16 8-10 6-12-10-10 6-2-8-10-4z" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `.trim()
                },
                suitcase: {
                    label: 'Valise',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-sky-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="20" y="20" width="24" height="32" rx="6" stroke="currentColor" stroke-width="3"/>
                            <path d="M26 20v-4a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v4" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M26 30h12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                map: {
                    label: 'Carte',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-sky-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M16 18l12-4l12 4l12-4v34l-12 4l-12-4l-12 4V18z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M28 14v34" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M40 18v34" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                camera: {
                    label: 'Photo',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-sky-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="14" y="22" width="36" height="26" rx="6" stroke="currentColor" stroke-width="3"/>
                            <path d="M24 22l4-6h8l4 6" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="32" cy="35" r="7" stroke="currentColor" stroke-width="3"/>
                        </svg>
                    `.trim()
                },
                note: {
                    label: 'Note',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-violet-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M38 14v28a8 8 0 1 1-4-7V18l18-4v16" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="22" cy="48" r="6" stroke="currentColor" stroke-width="3"/>
                        </svg>
                    `.trim()
                },
                headphones: {
                    label: 'Casque audio',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-violet-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M16 36v10a6 6 0 0 0 12 0V36a6 6 0 0 0-12 0z" stroke="currentColor" stroke-width="3"/>
                            <path d="M36 36v10a6 6 0 0 0 12 0V36a6 6 0 0 0-12 0z" stroke="currentColor" stroke-width="3"/>
                            <path d="M16 36a16 16 0 0 1 32 0" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                mic: {
                    label: 'Micro',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-violet-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <rect x="26" y="14" width="12" height="26" rx="6" stroke="currentColor" stroke-width="3"/>
                            <path d="M20 30v2a12 12 0 0 0 24 0v-2" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M32 44v8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M24 52h16" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                guitar: {
                    label: 'Guitare',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-violet-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M38 14l12 12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M26 28c-6 0-10 4-10 10s4 10 10 10c4 0 8-2 10-6l10-10c4-4 4-8 1-11s-7-3-11 1l-10 10c-2 2-4 4-4 6z" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="26" cy="38" r="3" stroke="currentColor" stroke-width="3"/>
                        </svg>
                    `.trim()
                },
                run: {
                    label: 'Course',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-teal-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="40" cy="16" r="5" stroke="currentColor" stroke-width="3"/>
                            <path d="M28 34l10-10l8 4" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M28 34l-6 10" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M32 30l-8-6" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M34 38l10 12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                ball: {
                    label: 'Ballon',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-teal-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="32" cy="32" r="18" stroke="currentColor" stroke-width="3"/>
                            <path d="M32 14v36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M14 32h36" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M20 20l24 24" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M44 20L20 44" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                dumbbell: {
                    label: 'Haltère',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-teal-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M18 26v12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M46 26v12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <rect x="14" y="24" width="6" height="16" rx="2" stroke="currentColor" stroke-width="3"/>
                            <rect x="44" y="24" width="6" height="16" rx="2" stroke="currentColor" stroke-width="3"/>
                            <path d="M20 32h24" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                stopwatch: {
                    label: 'Chrono',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-teal-700" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="32" cy="34" r="16" stroke="currentColor" stroke-width="3"/>
                            <path d="M26 10h12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M32 18v4" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M32 34l8-6" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `.trim()
                },
                handshake: {
                    label: 'Poignée de main',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-gray-900" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M14 34l10-10l10 10l-10 10L14 34z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M30 24l10-10l10 10l-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M24 44l10 10l16-16" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    `.trim()
                },
                group: {
                    label: 'Groupe',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-gray-900" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <circle cx="24" cy="26" r="6" stroke="currentColor" stroke-width="3"/>
                            <circle cx="40" cy="26" r="6" stroke="currentColor" stroke-width="3"/>
                            <path d="M14 50c2-8 8-12 14-12s12 4 14 12" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M30 50c2-6 6-10 10-10s8 4 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" opacity="0.6"/>
                        </svg>
                    `.trim()
                },
                trophy: {
                    label: 'Trophée',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-gray-900" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M24 14h16v10a8 8 0 0 1-16 0V14z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M20 18h-6v6a10 10 0 0 0 10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M44 18h6v6a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M32 34v8" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M24 50h16" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
                megaphone: {
                    label: 'Mégaphone',
                    svg: `
                        <svg viewBox="0 0 64 64" class="w-20 h-20 text-gray-900" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M14 30l28-10v24L14 34v-4z" stroke="currentColor" stroke-width="3" stroke-linejoin="round"/>
                            <path d="M14 30h-6v4h6" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M22 34l4 14" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                            <path d="M46 24c4 4 4 12 0 16" stroke="currentColor" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                    `.trim()
                },
            };

            const LEVELS = [{
                    id: 1,
                    answer: 'PLUIE',
                    tiles: ['cloud_rain', 'umbrella', 'droplet', 'sun_cloud']
                },
                {
                    id: 2,
                    answer: 'FEU',
                    tiles: ['flame', 'extinguisher', 'volcano', 'heat']
                },
                {
                    id: 3,
                    answer: 'CAFE',
                    tiles: ['coffee', 'croissant', 'clock', 'briefcase']
                },
                {
                    id: 4,
                    answer: 'CHANTIER',
                    tiles: ['helmet', 'crane', 'brick', 'vest']
                },
                {
                    id: 5,
                    answer: 'FACTURE',
                    tiles: ['invoice', 'card', 'checkmark', 'calendar']
                },
                {
                    id: 6,
                    answer: 'SECURITE',
                    tiles: ['vest', 'helmet', 'warning', 'extinguisher']
                },
                {
                    id: 7,
                    answer: 'VOYAGE',
                    tiles: ['plane', 'suitcase', 'map', 'camera']
                },
                {
                    id: 8,
                    answer: 'MUSIQUE',
                    tiles: ['note', 'headphones', 'mic', 'guitar']
                },
                {
                    id: 9,
                    answer: 'SPORT',
                    tiles: ['run', 'ball', 'dumbbell', 'stopwatch']
                },
                {
                    id: 10,
                    answer: 'EQUIPE',
                    tiles: ['handshake', 'group', 'trophy', 'megaphone']
                },
            ];

            const tilesEl = document.getElementById('tiles');
            const levelLabel = document.getElementById('levelLabel');
            const scoreLabel = document.getElementById('scoreLabel');
            const wordMask = document.getElementById('wordMask');
            const answerInput = document.getElementById('answerInput');
            const feedback = document.getElementById('feedback');
            const checkBtn = document.getElementById('checkBtn');
            const nextBtn = document.getElementById('nextBtn');
            const revealBtn = document.getElementById('revealBtn');

            let index = 0;
            let score = 0;
            let revealed = false;

            function normalize(value) {
                return String(value || '')
                    .trim()
                    .toUpperCase()
                    .normalize('NFD')
                    .replace(/[\u0300-\u036f]/g, '')
                    .replace(/[^A-Z0-9]/g, '');
            }

            function mask(answer) {
                return answer.split('').map(ch => (ch === ' ' ? ' ' : '_')).join(' ');
            }

            function setFeedback(type, text) {
                const cls = type === 'ok' ? 'text-emerald-700' : (type === 'warn' ? 'text-amber-700' : 'text-rose-700');
                feedback.className = 'mt-3 text-xs ' + cls;
                feedback.textContent = text;
            }

            function renderLevel() {
                const level = LEVELS[index];
                revealed = false;

                levelLabel.textContent = `#${level.id} / ${LEVELS.length}`;
                scoreLabel.textContent = String(score);
                wordMask.textContent = mask(level.answer);

                tilesEl.innerHTML = level.tiles.map(tile => {
                    const meta = ICONS[tile];
                    const svg = meta ? meta.svg : '';
                    const label = meta ? meta.label : '';
                    return `
                        <div class="rounded-[1.5rem] border-2 border-indigo-100 bg-white p-6 flex items-center justify-center shadow-sm">
                            <div class="flex items-center justify-center">${svg}</div>
                            <span class="sr-only">${label}</span>
                        </div>
                    `;
                }).join('');

                answerInput.value = '';
                answerInput.focus();
                setFeedback('warn', 'Tape ta réponse puis valide.');
            }

            function next() {
                index = (index + 1) % LEVELS.length;
                renderLevel();
            }

            function reveal() {
                const level = LEVELS[index];
                revealed = true;
                wordMask.textContent = level.answer.split('').join(' ');
                setFeedback('warn', `Réponse : ${level.answer}. (Score inchangé)`);
            }

            function check() {
                const level = LEVELS[index];
                const expected = normalize(level.answer);
                const got = normalize(answerInput.value);

                if (got === '') {
                    setFeedback('warn', 'Entre une réponse.');
                    return;
                }

                if (got === expected) {
                    const bonus = revealed ? 1 : 3;
                    score += bonus;
                    scoreLabel.textContent = String(score);
                    wordMask.textContent = level.answer.split('').join(' ');
                    setFeedback('ok', `Bien joué ! +${bonus} point(s).`);
                    return;
                }

                setFeedback('bad', 'Pas encore. Essaie encore !');
            }

            checkBtn.addEventListener('click', check);
            nextBtn.addEventListener('click', next);
            revealBtn.addEventListener('click', reveal);
            answerInput.addEventListener('keydown', (ev) => {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    check();
                }
            });

            renderLevel();
        })();
    </script>

    <script>
        (function() {
            if (!window.FineaHelpTour) return;

            var tour = window.FineaHelpTour.create({
                key: 'divertissements_4_indices',
                version: '1.0.0',
                helpButtonId: 'helpBtn',
                auto: true,
                steps: [{
                        title: 'But du jeu',
                        text: 'Devine le mot à partir de 4 indices (SVG). Écris ta réponse puis valide.'
                    },
                    {
                        title: 'Les 4 indices',
                        text: 'Ces 4 tuiles représentent le mot à trouver.',
                        selector: '#tiles'
                    },
                    {
                        title: 'Répondre',
                        text: 'Tape ta réponse ici puis appuie sur Entrée ou “Valider”.',
                        selector: '#answerInput'
                    },
                    {
                        title: 'Révéler / Suivant',
                        text: '“Révéler” montre la réponse (moins de points). “Suivant” passe au niveau suivant.',
                        selector: '#revealBtn'
                    }
                ]
            });

            tour.bindHelpButton();
            tour.autoStartIfNeeded();
        })();
    </script>
</body>

</html>