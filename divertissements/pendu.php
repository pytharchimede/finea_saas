<?php

require_once __DIR__ . '/../auth_check.php';

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendu</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <div class="min-h-screen py-10 px-6 bg-gradient-to-b from-rose-50 via-amber-50 to-sky-50">
        <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-gray-900">Pendu</h1>
                    <p class="text-sm text-gray-700 mt-1">Trouve le mot avant la fin des essais</p>
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
                        <div class="text-xs text-gray-600">Essais restants</div>
                        <div class="text-lg font-extrabold text-gray-900" id="triesLeft">—</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-600">Mots trouvés</div>
                        <div class="text-lg font-extrabold text-gray-900" id="wins">0</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="newBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-rose-600 text-white hover:bg-rose-700 transition inline-flex items-center gap-2">
                            <i class="fas fa-rotate"></i>
                            <span>Nouveau mot</span>
                        </button>
                        <button type="button" id="hintBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition inline-flex items-center gap-2">
                            <i class="fas fa-lightbulb"></i>
                            <span>Indice</span>
                        </button>
                    </div>
                </div>

                <div class="mt-6 rounded-[1.5rem] border-2 border-rose-100 bg-gradient-to-br from-white to-rose-50 p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-center">
                        <div class="md:col-span-2">
                            <div class="text-xs font-semibold text-gray-700">Mot</div>
                            <div class="mt-2 text-2xl md:text-3xl font-extrabold tracking-widest text-gray-900" id="masked">—</div>
                            <div class="mt-2 text-xs text-gray-700" id="category">—</div>
                            <div class="mt-4 text-xs" id="feedback"></div>
                        </div>
                        <div class="md:col-span-1">
                            <div class="text-xs font-semibold text-gray-700">Pendu</div>
                            <div class="mt-2 rounded-[1.25rem] border-2 border-rose-100 bg-white p-4">
                                <svg id="hangmanSvg" viewBox="0 0 160 160" class="w-full h-32 md:h-36" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Dessin du pendu">
                                    <!-- Potence (toujours visible) -->
                                    <path d="M20 145H120" stroke="#111827" stroke-width="6" stroke-linecap="round" />
                                    <path d="M45 145V20" stroke="#111827" stroke-width="6" stroke-linecap="round" />
                                    <path d="M42 20H105" stroke="#111827" stroke-width="6" stroke-linecap="round" />
                                    <path d="M105 20V40" stroke="#111827" stroke-width="6" stroke-linecap="round" />

                                    <!-- Pendu (apparition progressive) -->
                                    <g id="hangman_p1_head" opacity="0">
                                        <circle cx="105" cy="55" r="15" stroke="#be123c" stroke-width="6" />
                                    </g>
                                    <g id="hangman_p2_body" opacity="0">
                                        <path d="M105 70V105" stroke="#be123c" stroke-width="6" stroke-linecap="round" />
                                    </g>
                                    <g id="hangman_p3_arm_l" opacity="0">
                                        <path d="M105 82L88 95" stroke="#be123c" stroke-width="6" stroke-linecap="round" />
                                    </g>
                                    <g id="hangman_p4_arm_r" opacity="0">
                                        <path d="M105 82L122 95" stroke="#be123c" stroke-width="6" stroke-linecap="round" />
                                    </g>
                                    <g id="hangman_p5_leg_l" opacity="0">
                                        <path d="M105 105L92 130" stroke="#be123c" stroke-width="6" stroke-linecap="round" />
                                    </g>
                                    <g id="hangman_p6_leg_r" opacity="0">
                                        <path d="M105 105L118 130" stroke="#be123c" stroke-width="6" stroke-linecap="round" />
                                    </g>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <div class="text-xs font-semibold text-gray-700">Lettres</div>
                    <div class="mt-3 grid grid-cols-7 sm:grid-cols-9 md:grid-cols-13 gap-2" id="letters"></div>
                </div>

                <div class="mt-6 text-xs text-gray-700">Astuce : tu peux aussi taper au clavier (A–Z).</div>
            </div>
        </div>
    </div>

    <script src="../js/auth_guard.js"></script>
    <script src="../js/help_tour.js"></script>
    <script>
        (function() {
            const WORDS = [{
                    w: 'CHANTIER',
                    c: 'BTP'
                },
                {
                    w: 'DEVIS',
                    c: 'BTP'
                },
                {
                    w: 'FACTURE',
                    c: 'Gestion'
                },
                {
                    w: 'PAIEMENT',
                    c: 'Gestion'
                },
                {
                    w: 'SECURITE',
                    c: 'Prévention'
                },
                {
                    w: 'EQUIPE',
                    c: 'Vie pro'
                },
                {
                    w: 'VACANCES',
                    c: 'Détente'
                },
                {
                    w: 'MUSIQUE',
                    c: 'Loisir'
                },
                {
                    w: 'VOYAGE',
                    c: 'Loisir'
                },
                {
                    w: 'CUISINE',
                    c: 'Maison'
                },
                {
                    w: 'JARDIN',
                    c: 'Maison'
                },
                {
                    w: 'SPORT',
                    c: 'Forme'
                },
                {
                    w: 'METEO',
                    c: 'Quotidien'
                },
                {
                    w: 'SOURIRE',
                    c: 'Quotidien'
                },
            ];

            const triesLeftEl = document.getElementById('triesLeft');
            const winsEl = document.getElementById('wins');
            const maskedEl = document.getElementById('masked');
            const categoryEl = document.getElementById('category');
            const lettersEl = document.getElementById('letters');
            const feedbackEl = document.getElementById('feedback');
            const newBtn = document.getElementById('newBtn');
            const hintBtn = document.getElementById('hintBtn');
            const hangmanSvg = document.getElementById('hangmanSvg');

            const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');

            let word = '';
            let category = '';
            let triesLeft = 6;
            let wins = 0;
            let guessed = new Set();
            let solved = false;

            const MAX_TRIES = 6;
            const HANGMAN_PARTS = [
                'hangman_p1_head',
                'hangman_p2_body',
                'hangman_p3_arm_l',
                'hangman_p4_arm_r',
                'hangman_p5_leg_l',
                'hangman_p6_leg_r',
            ];

            function setFeedback(type, text) {
                const cls = type === 'ok' ? 'text-emerald-700' : (type === 'warn' ? 'text-amber-700' : 'text-rose-700');
                feedbackEl.className = 'mt-4 text-xs ' + cls;
                feedbackEl.textContent = text;
            }

            function pickWord() {
                const item = WORDS[Math.floor(Math.random() * WORDS.length)];
                word = item.w;
                category = item.c;
            }

            function maskWord() {
                return word.split('').map(ch => (guessed.has(ch) ? ch : '_')).join(' ');
            }

            function isWin() {
                for (const ch of word) {
                    if (!guessed.has(ch)) return false;
                }
                return true;
            }

            function renderLetters() {
                lettersEl.innerHTML = ALPHABET.map(ch => {
                    const used = guessed.has(ch);
                    const disabled = used || solved || triesLeft <= 0;
                    const base = 'rounded-2xl px-2 py-2 text-xs font-extrabold border-2 transition';
                    const cls = disabled ?
                        base + ' bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed' :
                        base + ' bg-white border-rose-100 text-gray-900 hover:bg-rose-50';

                    return `<button type="button" class="${cls}" data-letter="${ch}" ${disabled ? 'disabled' : ''}>${ch}</button>`;
                }).join('');
            }

            function renderHangman() {
                if (!hangmanSvg) return;

                const wrong = Math.max(0, Math.min(MAX_TRIES, MAX_TRIES - triesLeft));
                for (let i = 0; i < HANGMAN_PARTS.length; i++) {
                    const el = document.getElementById(HANGMAN_PARTS[i]);
                    if (!el) continue;
                    el.setAttribute('opacity', i < wrong ? '1' : '0');
                }
            }

            function render() {
                triesLeftEl.textContent = String(triesLeft);
                winsEl.textContent = String(wins);
                maskedEl.textContent = maskWord();
                categoryEl.textContent = 'Catégorie : ' + category;
                renderLetters();
                renderHangman();
            }

            function guess(ch) {
                if (solved || triesLeft <= 0) return;
                if (!/^[A-Z]$/.test(ch)) return;
                if (guessed.has(ch)) return;

                guessed.add(ch);

                if (!word.includes(ch)) {
                    triesLeft -= 1;
                    if (triesLeft <= 0) {
                        setFeedback('bad', `Perdu… Le mot était : ${word}.`);
                    } else {
                        setFeedback('warn', 'Raté… continue !');
                    }
                } else {
                    if (isWin()) {
                        solved = true;
                        wins += 1;
                        setFeedback('ok', 'Gagné ! Nouveau mot ?');
                    } else {
                        setFeedback('ok', 'Bien vu !');
                    }
                }

                render();
            }

            function newGame() {
                pickWord();
                triesLeft = MAX_TRIES;
                guessed = new Set();
                solved = false;
                setFeedback('warn', 'Choisis une lettre.');
                render();
            }

            function hint() {
                if (solved || triesLeft <= 0) return;

                const remaining = word.split('').filter(ch => !guessed.has(ch));
                if (remaining.length === 0) return;

                const ch = remaining[Math.floor(Math.random() * remaining.length)];
                guessed.add(ch);
                setFeedback('warn', `Indice : la lettre "${ch}".`);

                if (isWin()) {
                    solved = true;
                    wins += 1;
                    setFeedback('ok', 'Gagné ! Nouveau mot ?');
                }

                render();
            }

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('[data-letter]');
                if (!btn) return;
                const ch = btn.getAttribute('data-letter');
                guess(ch);
            });

            document.addEventListener('keydown', (ev) => {
                const ch = String(ev.key || '').toUpperCase();
                if (/^[A-Z]$/.test(ch)) {
                    ev.preventDefault();
                    guess(ch);
                }
            });

            newBtn.addEventListener('click', newGame);
            hintBtn.addEventListener('click', hint);

            newGame();
        })();
    </script>

    <script>
        (function() {
            if (!window.FineaHelpTour) return;

            var tour = window.FineaHelpTour.create({
                key: 'divertissements_pendu',
                version: '1.0.0',
                helpButtonId: 'helpBtn',
                auto: true,
                steps: [{
                        title: 'But du jeu',
                        text: 'Trouve le mot avant d’épuiser tes essais. Tu peux cliquer sur les lettres ou taper au clavier.'
                    },
                    {
                        title: 'Essais + dessin',
                        text: 'Chaque erreur réduit tes essais et complète le dessin du pendu.',
                        selector: '#hangmanSvg'
                    },
                    {
                        title: 'Le mot',
                        text: 'Les _ sont les lettres à trouver. La catégorie te donne un indice.',
                        selector: '#masked'
                    },
                    {
                        title: 'Clavier de lettres',
                        text: 'Clique une lettre (ou tape A–Z).',
                        selector: '#letters'
                    },
                    {
                        title: 'Indice',
                        text: '“Indice” révèle une lettre (utile si tu bloques).',
                        selector: '#hintBtn'
                    }
                ]
            });

            tour.bindHelpButton();
            tour.autoStartIfNeeded();
        })();
    </script>
</body>

</html>