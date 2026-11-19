<?php

require_once __DIR__ . '/../auth_check.php';

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Duel réflexe (2 joueurs)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <div class="min-h-screen py-10 px-6 bg-gradient-to-b from-sky-50 via-indigo-50 to-emerald-50">
        <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-gray-900">Duel réflexe</h1>
                    <p class="text-sm text-gray-700 mt-1">2 joueurs · faux départ interdit · le plus rapide gagne</p>
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
                        <div class="text-sm font-extrabold text-gray-900">Joueur 1</div>
                        <div class="mt-1 text-xs text-gray-700">Appuie : <span class="font-mono">A</span></div>
                        <div class="mt-4 text-2xl font-extrabold text-gray-900" id="rtP1">—</div>
                        <div class="mt-1 text-xs text-gray-600" id="msgP1"></div>
                    </div>
                    <div class="rounded-[1.5rem] border-2 border-sky-100 p-5 bg-gradient-to-br from-white to-sky-50">
                        <div class="text-sm font-extrabold text-gray-900">Joueur 2</div>
                        <div class="mt-1 text-xs text-gray-700">Appuie : <span class="font-mono">L</span></div>
                        <div class="mt-4 text-2xl font-extrabold text-gray-900" id="rtP2">—</div>
                        <div class="mt-1 text-xs text-gray-600" id="msgP2"></div>
                    </div>
                </div>

                <div class="mt-6 rounded-[1.5rem] border-2 border-gray-100 bg-gradient-to-br from-gray-50 to-white p-5">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div>
                            <div class="text-sm font-extrabold text-gray-900" id="phaseTitle">Prêt ?</div>
                            <div class="text-xs text-gray-700 mt-1" id="phaseText">Clique sur “Démarrer”, attends le “GO”, puis appuie sur ta touche.</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" id="startBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-sky-600 text-white hover:bg-sky-700 transition inline-flex items-center gap-2">
                                <i class="fas fa-play"></i>
                                <span>Démarrer</span>
                            </button>
                            <button type="button" id="resetBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition inline-flex items-center gap-2">
                                <i class="fas fa-rotate"></i>
                                <span>Réinitialiser</span>
                            </button>
                        </div>
                    </div>

                    <div class="mt-5 rounded-[1.25rem] border-2 border-indigo-100 bg-white p-5 text-center">
                        <div class="text-xs font-semibold text-gray-600">Signal</div>
                        <div class="mt-2 text-4xl md:text-5xl font-extrabold tracking-tight" id="signalText">—</div>
                        <div class="mt-2 text-xs text-gray-600" id="signalHelp">Ne touche pas au clavier avant “GO”.</div>
                    </div>

                    <div class="mt-5 rounded-2xl border border-gray-200 p-5" id="resultBox" hidden>
                        <div class="text-sm font-extrabold text-gray-900">Résultat</div>
                        <div class="text-xs text-gray-700 mt-1" id="resultText"></div>
                    </div>
                </div>

                <div class="mt-6 text-xs text-gray-700">Astuce : si tu cliques dans un champ, le navigateur peut intercepter les touches.</div>
            </div>
        </div>
    </div>

    <script src="../js/auth_guard.js"></script>
    <script>
        (function() {
            const KEY_P1 = 'a';
            const KEY_P2 = 'l';

            const rtP1 = document.getElementById('rtP1');
            const rtP2 = document.getElementById('rtP2');
            const msgP1 = document.getElementById('msgP1');
            const msgP2 = document.getElementById('msgP2');
            const phaseTitle = document.getElementById('phaseTitle');
            const phaseText = document.getElementById('phaseText');
            const signalText = document.getElementById('signalText');
            const signalHelp = document.getElementById('signalHelp');
            const resultBox = document.getElementById('resultBox');
            const resultText = document.getElementById('resultText');
            const startBtn = document.getElementById('startBtn');
            const resetBtn = document.getElementById('resetBtn');

            let phase = 'idle'; // idle | waiting | go | done
            let goAt = 0;
            let waitTimer = null;
            let p1Time = null;
            let p2Time = null;
            let loser = null;

            function setPhase(title, text) {
                phaseTitle.textContent = title;
                phaseText.textContent = text;
            }

            function clearTimers() {
                if (waitTimer) {
                    window.clearTimeout(waitTimer);
                    waitTimer = null;
                }
            }

            function normalizeKey(ev) {
                return String(ev.key || '').toLowerCase();
            }

            function typingInField() {
                const activeTag = String(document.activeElement?.tagName || '').toLowerCase();
                return activeTag === 'input' || activeTag === 'textarea' || document.activeElement?.isContentEditable;
            }

            function render() {
                rtP1.textContent = p1Time === null ? '—' : `${p1Time} ms`;
                rtP2.textContent = p2Time === null ? '—' : `${p2Time} ms`;

                if (phase === 'idle') {
                    signalText.textContent = '—';
                    signalText.className = 'mt-2 text-4xl md:text-5xl font-extrabold tracking-tight text-gray-900';
                    signalHelp.textContent = 'Ne touche pas au clavier avant “GO”.';
                    startBtn.disabled = false;
                    return;
                }

                if (phase === 'waiting') {
                    signalText.textContent = 'ATTENDS…';
                    signalText.className = 'mt-2 text-4xl md:text-5xl font-extrabold tracking-tight text-indigo-600';
                    signalHelp.textContent = 'Faux départ = perdu.';
                    startBtn.disabled = true;
                    return;
                }

                if (phase === 'go') {
                    signalText.textContent = 'GO !';
                    signalText.className = 'mt-2 text-4xl md:text-5xl font-extrabold tracking-tight text-emerald-600';
                    signalHelp.textContent = `J1=${KEY_P1.toUpperCase()} · J2=${KEY_P2.toUpperCase()}`;
                    startBtn.disabled = true;
                    return;
                }

                if (phase === 'done') {
                    startBtn.disabled = false;
                }
            }

            function finish(verdict) {
                phase = 'done';
                clearTimers();
                setPhase('Terminé', 'Tu peux relancer une manche ou réinitialiser.');
                resultBox.hidden = false;
                resultText.textContent = verdict;
                render();
            }

            function start() {
                if (phase === 'waiting' || phase === 'go') {
                    return;
                }

                resultBox.hidden = true;
                msgP1.textContent = '';
                msgP2.textContent = '';
                p1Time = null;
                p2Time = null;
                loser = null;

                phase = 'waiting';
                setPhase('Attention', 'Attends le “GO” puis appuie sur ta touche.');
                render();

                const delay = 1000 + Math.floor(Math.random() * 2500);
                waitTimer = window.setTimeout(() => {
                    phase = 'go';
                    goAt = Date.now();
                    render();
                }, delay);
            }

            function reset() {
                clearTimers();
                phase = 'idle';
                p1Time = null;
                p2Time = null;
                loser = null;
                msgP1.textContent = '';
                msgP2.textContent = '';
                resultBox.hidden = true;
                setPhase('Prêt ?', 'Clique sur “Démarrer”, attends le “GO”, puis appuie sur ta touche.');
                render();
            }

            document.addEventListener('keydown', (ev) => {
                if (typingInField()) {
                    return;
                }

                const key = normalizeKey(ev);
                const isP1 = key === KEY_P1;
                const isP2 = key === KEY_P2;
                if (!isP1 && !isP2) {
                    return;
                }

                if (phase === 'waiting') {
                    loser = isP1 ? 'Joueur 1' : 'Joueur 2';
                    if (isP1) {
                        msgP1.textContent = 'Faux départ.';
                    } else {
                        msgP2.textContent = 'Faux départ.';
                    }
                    finish(`${loser} a fait un faux départ. Victoire de l’autre joueur.`);
                    return;
                }

                if (phase !== 'go') {
                    return;
                }

                const ms = Date.now() - goAt;
                if (isP1 && p1Time === null) {
                    p1Time = ms;
                }
                if (isP2 && p2Time === null) {
                    p2Time = ms;
                }
                render();

                if (p1Time !== null && p2Time !== null) {
                    if (p1Time < p2Time) {
                        finish(`Joueur 1 gagne : ${p1Time} ms (J2=${p2Time} ms)`);
                    } else if (p2Time < p1Time) {
                        finish(`Joueur 2 gagne : ${p2Time} ms (J1=${p1Time} ms)`);
                    } else {
                        finish(`Égalité parfaite : ${p1Time} ms`);
                    }
                }
            });

            startBtn.addEventListener('click', start);
            resetBtn.addEventListener('click', reset);

            reset();
        })();
    </script>
</body>

</html>