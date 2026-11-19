<?php

require_once __DIR__ . '/../auth_check.php';

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pierre · Feuille · Ciseaux (2 joueurs)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <div class="min-h-screen py-10 px-6 bg-gradient-to-b from-emerald-50 via-teal-50 to-sky-50">
        <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-gray-900">Pierre · Feuille · Ciseaux</h1>
                    <p class="text-sm text-gray-700 mt-1">2 joueurs · choix secret · révélation</p>
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
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="rounded-[1.5rem] border-2 border-emerald-100 p-5 bg-gradient-to-br from-white to-emerald-50">
                        <div class="text-sm font-extrabold text-gray-900">Joueur 1</div>
                        <div class="mt-1 text-xs text-gray-700">Choisis puis cache ton écran.</div>
                        <div class="mt-4 grid grid-cols-3 gap-2" id="p1Choices"></div>
                        <div class="mt-3 text-xs text-gray-600" id="p1Status">—</div>
                    </div>

                    <div class="rounded-[1.5rem] border-2 border-sky-100 p-5 bg-gradient-to-br from-white to-sky-50">
                        <div class="text-sm font-extrabold text-gray-900">Joueur 2</div>
                        <div class="mt-1 text-xs text-gray-700">Choisis puis révèle ensemble.</div>
                        <div class="mt-4 grid grid-cols-3 gap-2" id="p2Choices"></div>
                        <div class="mt-3 text-xs text-gray-600" id="p2Status">—</div>
                    </div>
                </div>

                <div class="mt-6 rounded-[1.5rem] border-2 border-gray-100 bg-gradient-to-br from-gray-50 to-white p-5">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <div class="text-sm font-extrabold text-gray-900" id="resultTitle">Manche</div>
                            <div class="text-xs text-gray-700 mt-1" id="resultText">Chaque joueur choisit, puis clique sur “Révéler”.</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="revealBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-emerald-600 text-white hover:bg-emerald-700 transition inline-flex items-center gap-2">
                                <i class="fas fa-eye"></i>
                                <span>Révéler</span>
                            </button>
                            <button type="button" id="resetBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition inline-flex items-center gap-2">
                                <i class="fas fa-rotate"></i>
                                <span>Nouvelle manche</span>
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="rounded-[1.25rem] border-2 border-emerald-100 bg-white p-5">
                            <div class="text-xs text-gray-600">Score Joueur 1</div>
                            <div class="mt-2 text-3xl font-extrabold text-gray-900" id="scoreP1">0</div>
                        </div>
                        <div class="rounded-[1.25rem] border-2 border-sky-100 bg-white p-5">
                            <div class="text-xs text-gray-600">Score Joueur 2</div>
                            <div class="mt-2 text-3xl font-extrabold text-gray-900" id="scoreP2">0</div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 text-xs text-gray-700">Fair-play : chacun choisit sans regarder l’écran de l’autre.</div>
            </div>
        </div>
    </div>

    <script src="../js/auth_guard.js"></script>
    <script src="../js/help_tour.js"></script>
    <script>
        (function() {
            const MOVES = [{
                    key: 'rock',
                    label: 'Pierre',
                    emoji: '🪨'
                },
                {
                    key: 'paper',
                    label: 'Feuille',
                    emoji: '📄'
                },
                {
                    key: 'scissors',
                    label: 'Ciseaux',
                    emoji: '✂️'
                },
            ];

            const p1Choices = document.getElementById('p1Choices');
            const p2Choices = document.getElementById('p2Choices');
            const p1Status = document.getElementById('p1Status');
            const p2Status = document.getElementById('p2Status');
            const revealBtn = document.getElementById('revealBtn');
            const resetBtn = document.getElementById('resetBtn');
            const resultTitle = document.getElementById('resultTitle');
            const resultText = document.getElementById('resultText');
            const scoreP1El = document.getElementById('scoreP1');
            const scoreP2El = document.getElementById('scoreP2');

            let p1 = null;
            let p2 = null;
            let scoreP1 = 0;
            let scoreP2 = 0;
            let revealed = false;

            function moveByKey(key) {
                return MOVES.find(m => m.key === key) || null;
            }

            function buttonHtml(player, move) {
                return `
                    <button type="button" class="rounded-2xl border-2 border-gray-200 bg-white hover:bg-gray-50 transition px-3 py-3 text-center" data-player="${player}" data-move="${move.key}">
                        <div class="text-3xl">${move.emoji}</div>
                        <div class="mt-1 text-xs font-extrabold text-gray-900">${move.label}</div>
                    </button>
                `;
            }

            function setResult(title, text, tone) {
                resultTitle.textContent = title;
                resultText.textContent = text;
                const cls = tone === 'ok' ? 'text-emerald-700' : (tone === 'bad' ? 'text-rose-700' : 'text-gray-700');
                resultText.className = 'text-xs mt-1 ' + cls;
            }

            function render() {
                p1Choices.innerHTML = MOVES.map(m => buttonHtml('p1', m)).join('');
                p2Choices.innerHTML = MOVES.map(m => buttonHtml('p2', m)).join('');

                scoreP1El.textContent = String(scoreP1);
                scoreP2El.textContent = String(scoreP2);

                p1Status.textContent = p1 ? `Choix enregistré.` : 'Choisis une option.';
                p2Status.textContent = p2 ? `Choix enregistré.` : 'Choisis une option.';

                revealBtn.disabled = revealed || !p1 || !p2;
            }

            function compare(a, b) {
                if (a === b) return 0;
                if (a === 'rock' && b === 'scissors') return 1;
                if (a === 'paper' && b === 'rock') return 1;
                if (a === 'scissors' && b === 'paper') return 1;
                return -1;
            }

            function reveal() {
                if (!p1 || !p2 || revealed) return;
                revealed = true;

                const a = p1;
                const b = p2;
                const res = compare(a, b);

                const ma = moveByKey(a);
                const mb = moveByKey(b);

                if (res === 0) {
                    setResult('Égalité', `J1=${ma.label} · J2=${mb.label}`, 'neutral');
                } else if (res === 1) {
                    scoreP1 += 1;
                    setResult('Joueur 1 gagne', `J1=${ma.label} bat J2=${mb.label}`, 'ok');
                } else {
                    scoreP2 += 1;
                    setResult('Joueur 2 gagne', `J2=${mb.label} bat J1=${ma.label}`, 'ok');
                }

                render();
            }

            function resetRound() {
                p1 = null;
                p2 = null;
                revealed = false;
                setResult('Manche', 'Chaque joueur choisit, puis clique sur “Révéler”.', 'neutral');
                render();
            }

            document.addEventListener('click', (ev) => {
                const btn = ev.target.closest('[data-player][data-move]');
                if (!btn || revealed) return;

                const player = btn.getAttribute('data-player');
                const move = btn.getAttribute('data-move');

                if (player === 'p1') p1 = move;
                if (player === 'p2') p2 = move;

                render();
            });

            revealBtn.addEventListener('click', reveal);
            resetBtn.addEventListener('click', resetRound);

            resetRound();
        })();
    </script>

    <script>
        (function() {
            if (!window.FineaHelpTour) return;

            var tour = window.FineaHelpTour.create({
                key: 'divertissements_pfc',
                version: '1.0.0',
                helpButtonId: 'helpBtn',
                auto: true,
                steps: [{
                        title: 'Principe',
                        text: '2 joueurs : chacun choisit en secret, puis vous révélez en même temps.'
                    },
                    {
                        title: 'Joueur 1',
                        text: 'J1 choisit Pierre / Feuille / Ciseaux.',
                        selector: '#p1Choices'
                    },
                    {
                        title: 'Joueur 2',
                        text: 'J2 choisit ensuite (sans regarder).',
                        selector: '#p2Choices'
                    },
                    {
                        title: 'Révéler',
                        text: 'Quand les deux choix sont faits, clique sur “Révéler”.',
                        selector: '#revealBtn'
                    },
                    {
                        title: 'Nouvelle manche',
                        text: 'Clique “Nouvelle manche” pour rejouer (le score est conservé).',
                        selector: '#resetBtn'
                    }
                ]
            });

            tour.bindHelpButton();
            tour.autoStartIfNeeded();
        })();
    </script>
</body>

</html>