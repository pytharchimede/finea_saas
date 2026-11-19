# Enregistrement unique des modifications FEB

Dans le popup Finance, « Enregistrer les modifications » remplace les trois enregistrements séparés du montant, des dates et de l’affectation. Il reprend les champs éditables du popup, notamment le bénéficiaire/fournisseur. Les anciennes actions serveur restent compatibles pour les pages déjà ouvertes.

Le bouton enregistre sans approuver, intégrer le budget, réserver de nouveaux fonds ni générer une fiche ou un BCI. Le statut et le drapeau de génération existant sont conservés. Les points RH signés continuent à exiger une révision depuis le circuit RH.

Les droits de service et le CSRF sont conservés. Une transaction verrouille puis relit la FEB, refuse une décision concurrente, contrôle la cohérence des références budgétaires modifiées et résout l’identité du bénéficiaire depuis sa fiche réelle. Le montant et le planning réutilisent leurs synchronisations existantes. Une erreur annule les écritures. L’historique conserve les changements ; l’approbation et le paiement contrôlent toujours le fournisseur séparément.

La sélection explicite du fournisseur prime désormais sur les anciens identifiants du payload, même si son identifiant numérique est inférieur. L’ancien endpoint d’affectation accepte également une modification de bénéficiaire seule.

Vérification : `php tests/FebAssignmentBeneficiaryTest.php`, `php tests/FebUnifiedEditTest.php`, `NODE_PATH=/chemin/node_modules node tests/FebUnifiedEditDomTest.cjs` (linkedom), puis les tests existants `SupplierApprovalGuardTest.php` et `FebPaymentControlTest.php`.

Aucune migration SQL. Vérifier le rendu après déploiement dans une session authentifiée.
