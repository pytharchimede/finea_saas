# Recherche cartographique des fournisseurs internationaux

Le sélecteur partagé des formulaires d’ajout et de modification propose une recherche dans le pays fiscal choisi ou dans le monde entier. Le pays d’une adresse sélectionnée renseigne le champ géographique `pays`, sans changer l’identité fiscale.

La recherche et le géocodage inverse utilisent Photon (https://github.com/komoot/photon), adapté à la recherche pendant la saisie. Une seule recherche est envoyée après 650 ms de pause, à partir de trois caractères. Le serveur applique le filtre ISO choisi et vérifie le pays des résultats. Les anciennes variantes limitées à Abidjan ne sont plus utilisées. Les suggestions locales de secours sont également filtrées.

Le service public Photon est autorisé pour une utilisation raisonnable, sans garantie de disponibilité. Une hausse importante de trafic nécessitera un service hébergé dédié. Les recherches transmettent l’adresse saisie au géocodeur ; aucun identifiant fiscal ni pièce d’identité n’est envoyé.

La sélection d’une suggestion remplit l’adresse, la ville, le pays et les coordonnées. Afficher des suggestions ne confirme aucune position. Les réponses périmées sont ignorées et les coordonnées sont effacées après changement d’adresse ou de pays fiscal. Les coordonnées initiales restent conservées à l’ouverture d’une fiche inchangée.

La géolocalisation ne valide aucune identité fournisseur et ne change aucune règle FEB, paiement ou trésorerie. Aucune migration SQL.

Tests : `SupplierLocationSearchTest.php`, `SupplierLocationPickerDomTest.cjs` (linkedom), `BusinessIdentityEntryDomTest.cjs`. Une requête réelle sur Sarreguemines a répondu HTTP 200 avec des résultats français au moment de la préparation ; ce contrôle ne garantit pas la disponibilité future.
