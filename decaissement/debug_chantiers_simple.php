<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Chantiers Simple</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }

        .test-section {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
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
            width: 300px;
        }

        .log {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            max-height: 300px;
            overflow-y: auto;
        }
    </style>
</head>

<body>
    <h1>Debug Simple - Chargement Chantiers</h1>

    <div class="test-section">
        <h3>1. Test API Direct</h3>
        <button onclick="testerAPI()">Tester API get_chantiers</button>
        <div id="api-result"></div>
    </div>

    <div class="test-section">
        <h3>2. Test avec chargerChantiers()</h3>
        <button onclick="definirEntreprise()">1. Définir entreprise BANAMUR</button>
        <button onclick="appellerChargerChantiers()">2. Appeler chargerChantiers()</button>

        <div>
            <label>Chantiers :</label>
            <select id="chantier">
                <option value="">--Choisir Chantier--</option>
            </select>
            <div id="chantier-loading" style="display: none;">Chargement...</div>
        </div>
    </div>

    <div class="test-section">
        <h3>3. Variables d'état</h3>
        <button onclick="afficherEtat()">Vérifier l'état</button>
        <div id="etat-variables"></div>
    </div>

    <div class="test-section">
        <h3>4. Logs</h3>
        <div id="debug-log" class="log"></div>
    </div>

    <script>
        function log(message) {
            const timestamp = new Date().toLocaleTimeString();
            const logElement = document.getElementById('debug-log');
            logElement.innerHTML += `[${timestamp}] ${message}<br>`;
            logElement.scrollTop = logElement.scrollHeight;
            console.log(message);
        }

        function testerAPI() {
            log("🔍 Test API direct...");

            fetch('request/get_chantiers.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'entreprise=BANAMUR'
                })
                .then(response => {
                    log(`📡 Status: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    log(`✅ Réponse reçue: ${JSON.stringify(data, null, 2)}`);
                    document.getElementById('api-result').innerHTML = `<pre>${JSON.stringify(data, null, 2)}</pre>`;
                })
                .catch(error => {
                    log(`❌ Erreur: ${error.message}`);
                    document.getElementById('api-result').innerHTML = `<span style="color: red;">Erreur: ${error.message}</span>`;
                });
        }

        function definirEntreprise() {
            window.entrepriseSelectionnee = 'BANAMUR';
            log(`🏢 Entreprise définie: ${window.entrepriseSelectionnee}`);
            afficherEtat();
        }

        function appellerChargerChantiers() {
            log("🔧 Appel de chargerChantiers()...");
            if (typeof chargerChantiers === 'function') {
                chargerChantiers();
            } else {
                log("❌ Fonction chargerChantiers non trouvée");
                // Implémentation directe
                chargerChantiersLocal();
            }
        }

        function chargerChantiersLocal() {
            log("🔧 Implémentation locale de chargerChantiers...");

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

            if (window.entrepriseSelectionnee) {
                requestOptions = {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: "entreprise=" + encodeURIComponent(window.entrepriseSelectionnee),
                };
                log(`📤 Requête POST avec entreprise: ${window.entrepriseSelectionnee}`);
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

        function afficherEtat() {
            const etat = `
                <strong>window.entrepriseSelectionnee:</strong> ${window.entrepriseSelectionnee || 'undefined'}<br>
                <strong>chantier select options:</strong> ${document.getElementById('chantier').options.length}<br>
                <strong>loading visible:</strong> ${document.getElementById('chantier-loading').style.display !== 'none'}<br>
                <strong>chargerChantiers exists:</strong> ${typeof chargerChantiers !== 'undefined'}
            `;
            document.getElementById('etat-variables').innerHTML = etat;
        }

        // Initialisation
        log("🚀 Debug simple initialisé");
        afficherEtat();
    </script>
</body>

</html>