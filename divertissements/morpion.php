<?php

require_once __DIR__ . '/../auth_check.php';

?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Morpion (2 joueurs)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<body>
    <div class="min-h-screen py-10 px-6 bg-gradient-to-b from-amber-50 via-rose-50 to-yellow-50">
        <div class="max-w-4xl mx-auto">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl md:text-3xl font-extrabold tracking-tight text-gray-900">Morpion</h1>
                    <p class="text-sm text-gray-700 mt-1">2 joueurs · local · 3×3</p>
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
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <div class="text-sm font-extrabold text-gray-900" id="statusTitle">À toi de jouer</div>
                        <div class="text-xs text-gray-700 mt-1" id="statusText">Joueur <span class="font-extrabold" id="currentPlayer">X</span> : clique sur une case.</div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="resetBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-amber-600 text-white hover:bg-amber-700 transition inline-flex items-center gap-2">
                            <i class="fas fa-rotate"></i>
                            <span>Nouvelle partie</span>
                        </button>
                        <button type="button" id="clearScoreBtn" class="px-4 py-2 text-sm font-extrabold rounded-2xl bg-white border border-gray-200 hover:bg-gray-100 transition inline-flex items-center gap-2">
                            <i class="fas fa-eraser"></i>
                            <span>Score à zéro</span>
                        </button>
                    </div>
                </div>

                <div class="mt-6 grid grid-cols-3 gap-3" id="grid" aria-label="grille morpion">
                    <!-- cells injected -->
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="rounded-[1.5rem] border-2 border-rose-100 p-5 bg-gradient-to-br from-white to-rose-50">
                        <div class="text-xs text-gray-600">Joueur X</div>
                        <div class="mt-2 text-3xl font-extrabold text-gray-900" id="scoreX">0</div>
                    </div>
                    <div class="rounded-[1.5rem] border-2 border-indigo-100 p-5 bg-gradient-to-br from-white to-indigo-50">
                        <div class="text-xs text-gray-600">Joueur O</div>
                        <div class="mt-2 text-3xl font-extrabold text-gray-900" id="scoreO">0</div>
                    </div>
                </div>

                <div class="mt-6 text-xs text-gray-700">Règle : premier alignement (ligne/colonne/diagonale) gagne.</div>
            </div>
        </div>
    </div>

    <script src="../js/auth_guard.js"></script>
    <script>
        (function() {
            const grid = document.getElementById('grid');
            const resetBtn = document.getElementById('resetBtn');
            const clearScoreBtn = document.getElementById('clearScoreBtn');
            const statusTitle = document.getElementById('statusTitle');
            const statusText = document.getElementById('statusText');
            const currentPlayerEl = document.getElementById('currentPlayer');
            const scoreXEl = document.getElementById('scoreX');
            const scoreOEl = document.getElementById('scoreO');

            const WIN_LINES = [
                [0, 1, 2],
                [3, 4, 5],
                [6, 7, 8],
                [0, 3, 6],
                [1, 4, 7],
                [2, 5, 8],
                [0, 4, 8],
                [2, 4, 6]
            ];

            let board = Array(9).fill('');
            let current = 'X';
            let locked = false;
            let scoreX = 0;
            let scoreO = 0;

            function renderScores() {
                scoreXEl.textContent = String(scoreX);
                scoreOEl.textContent = String(scoreO);
            }

            function setStatus(title, text) {
                statusTitle.textContent = title;
                statusText.innerHTML = text;
            }

            function renderCurrent() {
                currentPlayerEl.textContent = current;
            }

            function cellClasses(value) {
                const base = 'h-24 md:h-28 rounded-[1.25rem] border-2 border-gray-200 bg-white hover:bg-gray-50 transition flex items-center justify-center text-4xl md:text-5xl font-extrabold';
                if (value === 'X') return base + ' text-rose-600';
                if (value === 'O') return base + ' text-indigo-600';
                return base + ' text-gray-300';
            }

            function buildGrid() {
                grid.innerHTML = '';
                board.forEach((value, idx) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = cellClasses(value);
                    btn.setAttribute('data-cell', String(idx));
                    btn.textContent = value || '·';
                    btn.addEventListener('click', () => play(idx));
                    grid.appendChild(btn);
                });
            }

            function getWinner() {
                for (const line of WIN_LINES) {
                    const [a, b, c] = line;
                    if (board[a] && board[a] === board[b] && board[b] === board[c]) {
                        return {
                            player: board[a],
                            line
                        };
                    }
                }
                return null;
            }

            function highlightLine(line) {
                line.forEach(i => {
                    const btn = grid.querySelector(`[data-cell="${i}"]`);
                    if (btn) {
                        btn.classList.add('ring-4', 'ring-amber-300');
                    }
                });
            }

            function isDraw() {
                return board.every(v => v !== '') && !getWinner();
            }

            function play(idx) {
                if (locked) return;
                if (board[idx] !== '') return;

                board[idx] = current;
                buildGrid();

                const winner = getWinner();
                if (winner) {
                    locked = true;
                    highlightLine(winner.line);
                    if (winner.player === 'X') scoreX += 1;
                    if (winner.player === 'O') scoreO += 1;
                    renderScores();
                    setStatus('Gagné !', `Le joueur <span class="font-extrabold">${winner.player}</span> remporte la manche.`);
                    return;
                }

                if (isDraw()) {
                    locked = true;
                    setStatus('Égalité', 'Aucun alignement. Relance une partie.');
                    return;
                }

                current = current === 'X' ? 'O' : 'X';
                renderCurrent();
                setStatus('À toi de jouer', `Joueur <span class="font-extrabold">${current}</span> : clique sur une case.`);
            }

            function resetBoard() {
                board = Array(9).fill('');
                current = 'X';
                locked = false;
                renderCurrent();
                buildGrid();
                setStatus('À toi de jouer', 'Joueur <span class="font-extrabold">X</span> : clique sur une case.');
            }

            function clearScore() {
                scoreX = 0;
                scoreO = 0;
                renderScores();
                resetBoard();
            }

            resetBtn.addEventListener('click', resetBoard);
            clearScoreBtn.addEventListener('click', clearScore);

            renderScores();
            resetBoard();
        })();
    </script>
</body>

</html>