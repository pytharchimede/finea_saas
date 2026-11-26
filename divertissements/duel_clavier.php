<?php

require_once __DIR__.'/Life.php';
Life::gameGuard();

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duel clavier (2 joueurs)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <div class="min-h-screen py-10 px-6 bg-gradient-to-b from-emerald-50 via-sky-50 to-indigo-50">
        <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-gray-900">Duel clavier</h1>
                    <p class="text-sm text-gray-700 mt-1">2 joueurs · rapidité · 10 secondes</p>
                </div>
                <div class="flex items-center gap-2">
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
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-extrabold text-gray-900">Joueur 1</div>
                                <div class="mt-1 text-xs text-gray-700">Touche : <span class="font-mono">A</span></div>
                            </div>
                            <div class="w-10 h-10 rounded-2xl bg-emerald-600 text-white flex items-center justify-center font-extrabold">A</div>
                        </div>
                        <div class="mt-4 text-5xl font-extrabold text-gray-900" id="scoreP1">0</div>
                    </div>
                    <div class="rounded-[1.5rem] border-2 border-sky-100 p-5 bg-gradient-to-br from-white to-sky-50">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="text-sm font-extrabold text-gray-900">Joueur 2</div>
                                <div class="mt-1 text-xs text-gray-700">Touche : <span class="font-mono">L</span></div>
                            </div>
                            <div class="w-10 h-10 rounded-2xl bg-sky-600 text-white flex items-center justify-center font-extrabold">L</div>
                        </div>
                        <div class="mt-4 text-5xl font-extrabold text-gray-900" id="scoreP2">0</div>
                    </div>
                </div>

                <div class="mt-6 rounded-[1.5rem] bg-gradient-to-br from-gray-50 to-white border-2 border-gray-100 p-5">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <div class="text-sm font-extrabold text-gray-900" id="statusTitle">Prêt ?</div>
                            <div class="text-xs text-gray-700 mt-1" id="statusText">Clique sur “Démarrer”, puis tape le plus vite possible pendant 10 secondes.</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="startBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-emerald-600 text-white hover:bg-emerald-700 transition inline-flex items-center gap-2">
                                <i class="fas fa-play"></i>
                                <span>Démarrer</span>
                            </button>
                            <button type="button" id="resetBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition inline-flex items-center gap-2">
                                <i class="fas fa-rotate"></i>
                                <span>Réinitialiser</span>
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-between">
                        <div class="text-xs text-gray-600">Temps restant</div>
                        <div class="text-lg font-semibold text-gray-900" id="timeLeft">—</div>
                    </div>
                    <div class="mt-2 h-2 rounded-full bg-gray-200 overflow-hidden">
                        <div class="h-2 bg-emerald-600" id="timeBar" style="width: 0%"></div>
                    </div>
                </div>

                <div class="mt-6 rounded-2xl border border-gray-200 p-5" id="resultBox" hidden>
                    <div class="text-sm font-semibold text-gray-800" id="resultTitle">Résultat</div>
                    <div class="text-xs text-gray-600 mt-1" id="resultText"></div>
                </div>

                <div class="mt-6 text-xs text-gray-600">
                    Conseil : évite de cliquer dans un champ pendant la partie (sinon les touches peuvent être capturées par le navigateur).
                </div>
            </div>
        </div>
    </div>

    <script src="../js/auth_guard.js"></script>
    <script>
        (function() {
            const KEY_P1 = 'a';
            const KEY_P2 = 'l';
            const DURATION_MS = 10_000;

            const scoreP1El = document.getElementById('scoreP1');
            const scoreP2El = document.getElementById('scoreP2');
            const statusTitleEl = document.getElementById('statusTitle');
            const statusTextEl = document.getElementById('statusText');
            const startBtn = document.getElementById('startBtn');
            const resetBtn = document.getElementById('resetBtn');
            const timeLeftEl = document.getElementById('timeLeft');
            const timeBarEl = document.getElementById('timeBar');
            const resultBox = document.getElementById('resultBox');
            const resultTitleEl = document.getElementById('resultTitle');
            const resultTextEl = document.getElementById('resultText');

            let running = false;
            let startedAt = 0;
            let timerId = null;
            let scoreP1 = 0;
            let scoreP2 = 0;

            function setStatus(title, text) {
                statusTitleEl.textContent = title;
                statusTextEl.textContent = text;
            }

            function renderScores() {
                scoreP1El.textContent = String(scoreP1);
                scoreP2El.textContent = String(scoreP2);
            }

            function renderTime(remainingMs) {
                const remaining = Math.max(0, remainingMs);
                const seconds = Math.ceil(remaining / 1000);
                timeLeftEl.textContent = running ? `${seconds}s` : '—';

                const elapsed = Math.min(DURATION_MS, Math.max(0, DURATION_MS - remaining));
                const pct = (elapsed / DURATION_MS) * 100;
                timeBarEl.style.width = `${pct}%`;
            }

            function stopGame() {
                running = false;
                if (timerId) {
                    window.clearInterval(timerId);
                    timerId = null;
                }

                renderTime(0);
                startBtn.disabled = false;

                let verdict = '';
                if (scoreP1 > scoreP2) verdict = 'Joueur 1 gagne.';
                else if (scoreP2 > scoreP1) verdict = 'Joueur 2 gagne.';
                else verdict = 'Égalité.';

                resultBox.hidden = false;
                resultTitleEl.textContent = 'Résultat';
                resultTextEl.textContent = `${verdict} (J1=${scoreP1} · J2=${scoreP2})`;

                setStatus('Terminé', 'Tu peux relancer une partie ou réinitialiser.');
            }

            function startGame() {
                if (running) return;

                resultBox.hidden = true;
                scoreP1 = 0;
                scoreP2 = 0;
                renderScores();
                startBtn.disabled = true;

                const countdown = [3, 2, 1];
                let idx = 0;

                setStatus('Préparez-vous', 'La partie démarre dans…');
                timeLeftEl.textContent = '—';
                timeBarEl.style.width = '0%';

                function tickCountdown() {
                    if (idx >= countdown.length) {
                        setStatus('GO !', `J1 tape "A" · J2 tape "L"`);
                        running = true;
                        startedAt = Date.now();

                        timerId = window.setInterval(() => {
                            const elapsed = Date.now() - startedAt;
                            const remaining = DURATION_MS - elapsed;
                            renderTime(remaining);
                            if (remaining <= 0) {
                                stopGame();
                            }
                        }, 50);

                        return;
                    }

                    setStatus(`Départ dans ${countdown[idx]}…`, `J1: "A" · J2: "L"`);
                    idx += 1;
                    window.setTimeout(tickCountdown, 700);
                }

                tickCountdown();
            }

            function resetGame() {
                running = false;
                if (timerId) {
                    window.clearInterval(timerId);
                    timerId = null;
                }

                scoreP1 = 0;
                scoreP2 = 0;
                renderScores();
                renderTime(DURATION_MS);
                timeLeftEl.textContent = '—';
                timeBarEl.style.width = '0%';
                startBtn.disabled = false;

                resultBox.hidden = true;
                setStatus('Prêt ?', 'Clique sur “Démarrer”, puis tape le plus vite possible pendant 10 secondes.');
            }

            document.addEventListener('keydown', (event) => {
                if (!running) return;

                const activeTag = String(document.activeElement?.tagName || '').toLowerCase();
                const typingInField = activeTag === 'input' || activeTag === 'textarea' || document.activeElement?.isContentEditable;
                if (typingInField) return;

                const key = String(event.key || '').toLowerCase();
                if (key === KEY_P1) {
                    scoreP1 += 1;
                    renderScores();
                    return;
                }
                if (key === KEY_P2) {
                    scoreP2 += 1;
                    renderScores();
                    return;
                }
            });

            startBtn.addEventListener('click', startGame);
            resetBtn.addEventListener('click', resetGame);

            resetGame();
        })();
    </script>
</body>

</html>