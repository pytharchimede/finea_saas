# Référentiel fournisseurs et alertes quotidiennes

Le répertoire fournisseurs reste la page d’entrée. La création et la modification ont leurs pages dédiées. Les accès au GPS, aux statistiques, aux incidents et aux décaissements rattachés partent du répertoire. Le filtre « Fiches à normaliser » utilise les pièces obligatoires, les coordonnées et la conformité FNE.

Pour les personnes morales, le NCC est obligatoire même avec un RNE. À chaque enregistrement, le serveur consulte le registre FNE existant : le nom officiel remplace `nom_commercial`, le NCC normalisé et la réponse fiscale sont conservés avec leur date de vérification. `raison_sociale` reste la forme juridique historique. Une panne FNE bloque l’enregistrement ; une valeur envoyée manuellement ne permet pas de forger une identité vérifiée. Les personnes physiques conservent leur parcours nominatif.

La référence `code_fournisseur` existante est conservée. Exécuter `php tools/migrate_supplier_identity.php` lors du déploiement : installation des colonnes FNE, index unique des NCC vérifiés, attribution des références manquantes et rapport des NCC historiques dupliqués. Les doublons historiques ne sont ni fusionnés ni supprimés automatiquement. Ils doivent être corrigés avant normalisation. Le placeholder historique « NEANT » ne constitue pas une vérification FNE.

Les alertes sont regroupées une fois par jour pour les utilisateurs actifs disposant de `acces_compta`, `acces_direction` ou `acces_admin`, selon les permissions présentes dans la base. Un verrou MySQL empêche les traitements concurrents. Notifications système, popup et journal quotidien sont enregistrés dans une transaction. Le popup expire à la fin de la journée ; la notification système reste dans l’historique. Les polls existants déclenchent automatiquement le contrôle : aucune installation cron n’est nécessaire pour afficher le récapitulatif à la connexion. Le listing complet est recalculé à l’ouverture de `fournisseur/alertes_fiches.php`.

Pour une génération à heure fixe, le point d’entrée CLI est `php cron/daily_completeness_alerts.php`. Configurer `APP_BASE_URL` ou `APP_BASE_PATH` pour les liens lorsque l’application est servie dans un sous-répertoire. Aucun email n’est envoyé par ce traitement. Un jour sans anomalie est également journalisé ; les anomalies apparues ensuite seront incluses le lendemain et sont immédiatement visibles dans le listing.

Les listes recherchables et zones de dépôt sont chargées par le footer partagé. Le select HTML d’origine garde sa valeur, sa validation et ses événements. Les éléments dynamiques sont également pris en charge. Les limites de format et de taille de fichiers restent contrôlées par les traitements serveur existants.

Validation : `php tests/SupplierIdentityTest.php`, `php tests/CompletenessDispatchTest.php`, lint PHP. Contrôle Chrome sur formulaire isolé avec réponse FNE simulée : recherche, sélection clavier, dépôt de fichier, verrouillage et remplissage FNE, select ajouté dynamiquement. Les tests ne prouvent pas la disponibilité du service DGI en production.

## Interface commune

`fournisseur/dashboard_fournisseur.php` est l’entrée du répertoire en cards avec recherche et pagination. `partials/supplier_workspace.php` porte l’en-tête, la navigation commune et les actions de fiche. `assets/css/supplier-directory.css` et `assets/css/supplier-module.css` définissent le thème commun aux pages fournisseurs et à leurs équivalents employé.

Les statistiques restent disponibles dans `fournisseur/statistiques.php`. Les catégories et formes juridiques affichent leur liste par défaut ; la création/modification passe par `categorie_form.php` et `forme_juridique_form.php`. Les anciens liens d’édition redirigent vers ces formulaires. Les autorisations des pages parentes restent appliquées.

## FEB simplifiée

Les modes classique et conversationnel demandent identification, besoins, puis confirmation. L’étape narrative « Détails » et la question sur l’impact sont retirées. Les anciens liens `employe/feb/formulaire_expression_besoin.php` redirigent vers le parcours maintenu.

`FebSubmissionContent` reconstitue une description à partir du titre et des lignes pour les traitements aval ; les descriptions et impacts historiques sont préservés lors des modifications. Le montant envoyé par le navigateur ne fait plus autorité : le serveur recalcule le total à partir des quantités et prix des lignes. Le rattachement fournisseur, le budget, la livraison, le planning et les références de régularisation demeurent dans le payload.

Le PDF FEB est disponible pour toutes les FEB accessibles, même avant approbation, dans les listes/fiches employé et dans la validation. Son endpoint conserve les contrôles de propriétaire et de service autorisé. L’export BCI conserve son cycle de génération existant.

Validation complémentaire : `php tests/FebSimplifiedWorkflowTest.php` teste la description sans champs retirés, le calcul serveur et la conservation des références pendant la synchronisation FEB/décaissement. L’export PDF a été vérifié avec 45 lignes sur des données fictives. Les traitements de paiement et de régularisation réels ne sont pas déclenchés par ces tests.
