<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug JavaScript</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }

        .debug-panel {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .log {
            background: #fff;
            padding: 10px;
            border: 1px solid #ddd;
            margin: 10px 0;
            border-radius: 4px;
            font-family: monospace;
        }

        button {
            padding: 10px 20px;
            margin: 5px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }

        select {
            padding: 8px;
            margin: 5px;
            width: 200px;
        }
    </style>
</head>

<body>
    <h1>Debug JavaScript - Chargement Chantiers</h1>

    <div class="debug-panel">
        <h3>Variables d'état</h3>
        <div id="variables-state"></div>

        <h3>Test de l'API</h3>
        <button onclick="testerAPI()">Tester API Directement</button>
        <button onclick="simulerSelectionEntreprise()">Simuler Sélection Entreprise</button>
        <button onclick="testerChargerChantiers()">Tester chargerChantiers()</button>

        <h3>Éléments DOM</h3>
        <select id="chantier">
            <option value="">--Choisir Chantier--</option>
        </select>
        <div id="chantier-loading" style="display: none;">Chargement...</div>
    </div>

    <div class="debug-panel">
        <h3>Logs</h3>
        <div id="debug-log" class="log"></div>
    </div>

    <script>
        // Variable globale pour simuler le script principal
        let entrepriseSelectionnee = null;

        function log(message) {
            const timestamp = new Date().toLocaleTimeString();
            const logElement = document.getElementById('debug-log');
            logElement.innerHTML += `[${timestamp}] ${message}<br>`;
            logElement.scrollTop = logElement.scrollHeight;
            console.log(message);
            updateVariablesState();
        }

        function updateVariablesState() {
            document.getElementById('variables-state').innerHTML = `
                <strong>entrepriseSelectionnee:</strong> ${entrepriseSelectionnee || 'null'}<br>
                <strong>chantier select options:</strong> ${document.getElementById('chantier').options.length}<br>
                <strong>loading visible:</strong> ${document.getElementById('chantier-loading').style.display !== 'none'}
            `;
        }

        function testerAPI() {
            log("🔍 Test direct de l'API...");

            fetch('request/get_chantiers.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'entreprise=BANAMUR'
                })
                .then(response => {
                    log(`📡 Réponse API: status ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    log(`✅ Données reçues: ${JSON.stringify(data)}`);
                    if (data.success && data.chantiers) {
                        log(`📊 ${data.chantiers.length} chantiers trouvés`);
                    }
                })
                .catch(error => {
                    log(`❌ Erreur API: ${error.message}`);
                });
        }

        function simulerSelectionEntreprise() {
            log("🏢 Simulation sélection entreprise BANAMUR...");
            entrepriseSelectionnee = 'BANAMUR';
            log(`✅ Entreprise définie: ${entrepriseSelectionnee}`);
        }

        function testerChargerChantiers() {
            log("🔧 Test de la fonction chargerChantiers...");
            chargerChantiers();
        }

        // Copie de la fonction chargerChantiers du script principal
        function chargerChantiers() {
            log(`📞 chargerChantiers() appelée, entreprise: ${entrepriseSelectionnee}`);

            const chantierSelect = document.getElementById("chantier");
            const chantierLoading = document.getElementById("chantier-loading");

            // Vider le select et afficher le loader
            chantierSelect.innerHTML = '<option value="">--Choisir Chantier--</option>';
            chantierLoading.style.display = "block";
            log("🔄 Select vidé, loader affiché");

            // Préparer l'URL avec le paramètre entreprise si défini
            let url = "request/get_chantiers.php";
            let requestOptions = {
                method: "GET",
            };

            if (entrepriseSelectionnee) {
                requestOptions = {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: "entreprise=" + encodeURIComponent(entrepriseSelectionnee),
                };
                log(`📤 Requête POST avec entreprise: ${entrepriseSelectionnee}`);
            } else {
                log("⚠️ Aucune entreprise sélectionnée, requête GET");
            }

            fetch(url, requestOptions)
                .then((response) => {
                    log(`📡 Réponse reçue: ${response.status}`);
                    if (!response.ok) {
                        throw new Error("Erreur réseau");
                    }
                    return response.json();
                })
                .then((data) => {
                    chantierLoading.style.display = "none";
                    log("🔄 Loader masqué");
                    log(`📊 Données: ${JSON.stringify(data)}`);

                    if (data.success && Array.isArray(data.chantiers)) {
                        log(`✅ ${data.chantiers.length} chantiers à ajouter`);
                        data.chantiers.forEach((chantier) => {
                            const option = document.createElement("option");
                            option.value = chantier.id;
                            option.text = `${chantier.code} - ${chantier.titre}`;
                            chantierSelect.appendChild(option);
                            log(`➕ Ajouté: ${option.text}`);
                        });
                        log(`🎯 Total options maintenant: ${chantierSelect.options.length}`);
                    } else {
                        log(`❌ Problème données: success=${data.success}, chantiers=${data.chantiers}`);
                    }
                })
                .catch((error) => {
                    chantierLoading.style.display = "none";
                    log(`❌ Erreur: ${error.message}`);
                });
        }

        // Initialisation
        log("🚀 Debug JavaScript initialisé");
        updateVariablesState();
    </script>
</body>

</html>