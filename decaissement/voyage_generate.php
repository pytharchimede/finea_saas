<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'voyage_generate_view',
    'entity_type' => 'transport_generation_test',
    'entity_label' => 'Generation voyage transport',
    'page_name' => 'voyage_generate.php',
    'details' => [
        'view_mode' => 'tool',
    ],
]);

// Page simple pour tester la génération des fiches transport et du bon essence
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Générer Voyage (Transport)</title>
    <link rel="stylesheet" href="../css/style.css" />
    <style>
        body {
            max-width: 960px;
            margin: 20px auto;
            font-family: system-ui, Arial, sans-serif;
        }

        .card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-top: 10px;
            font-weight: 600;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        button {
            margin-top: 12px;
            padding: 10px 16px;
            border: 0;
            background: #0d6efd;
            color: #fff;
            border-radius: 6px;
            cursor: pointer;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .muted {
            color: #666;
            font-size: 0.9em;
        }

        .result {
            white-space: pre-wrap;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
        }
    </style>
    <script>
        async function loadProviders() {
            const r = await fetch('api/transport_providers.php');
            const json = await r.json();
            const sel = document.getElementById('provider');
            sel.innerHTML = '';
            if (json.ok && Array.isArray(json.providers)) {
                for (const p of json.providers) {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = `${p.nom} ${p.actif==1?'(actif)':''}`;
                    sel.appendChild(opt);
                }
            }
        }
        async function submitForm(ev) {
            ev.preventDefault();
            const form = ev.target.closest('form');
            const data = Object.fromEntries(new FormData(form).entries());
            data.providerId = parseInt(data.providerId || data.provider);
            data.ligne_debourse_id = parseInt(data.ligne_debourse_id || 0);
            data.montant_total = parseFloat(data.montant_total || 0);
            const r = await fetch('api/transport_generate.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            });
            const json = await r.json();
            document.getElementById('result').textContent = JSON.stringify(json, null, 2);
            // Lien bon si dispo
            const bonLink = document.getElementById('bon-link');
            if (json.ok && json.bon && json.bon.code_bon) {
                const code = encodeURIComponent(json.bon.code_bon);
                bonLink.innerHTML = `<a href="bon/bon_essence.php?id_bon=${code}" target="_blank">Voir le bon ${json.bon.code_bon}</a>`;
            } else {
                bonLink.textContent = '';
            }
        }
        window.addEventListener('DOMContentLoaded', loadProviders);
    </script>
</head>

<body>
    <h1>Génération d'un voyage (transport)</h1>
    <div class="card">
        <form onsubmit="submitForm(event)">
            <div class="grid">
                <div>
                    <label>Fournisseur</label>
                    <select id="provider" name="providerId" required></select>
                </div>
                <div>
                    <label>Chantier (code)</label>
                    <input type="text" name="chantier_code" placeholder="ex: CH060" required />
                </div>
                <div>
                    <label>Bénéficiaire</label>
                    <input type="text" name="beneficiaire" required />
                </div>
                <div>
                    <label>Téléphone</label>
                    <input type="text" name="tel" />
                </div>
                <div>
                    <label>Détails du voyage</label>
                    <input type="text" name="details" placeholder="Itinéraire, référence..." />
                </div>
                <div>
                    <label>Id ligne déboursé</label>
                    <input type="number" name="ligne_debourse_id" required />
                </div>
                <div>
                    <label>Montant total</label>
                    <input type="number" name="montant_total" step="0.01" required />
                </div>
                <div>
                    <label>Véhicule</label>
                    <input type="text" name="vehicule" placeholder="immatriculation (optionnel)" />
                </div>
                <div>
                    <label>Quantité (litres)</label>
                    <input type="number" name="quantite" step="0.01" />
                </div>
            </div>
            <button type="submit">Générer</button>
        </form>
    </div>
    <div class="card">
        <div class="muted">Résultat</div>
        <div id="bon-link" style="margin: 6px 0"></div>
        <pre class="result" id="result"></pre>
    </div>
</body>

</html>