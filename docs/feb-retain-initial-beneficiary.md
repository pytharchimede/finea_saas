# Bénéficiaire conservé à l'approbation des prix initiaux

Une FEB peut conserver le fournisseur historique dans les lignes de besoin tout en ayant un bénéficiaire explicitement modifié par Finance dans `provisional_decaissement`. Le prix initial ne constitue pas une nouvelle instruction de paiement.

`FebPriceChallengeService::retainAllInitial` rétablissait systématiquement le demandeur comme bénéficiaire et le fournisseur initial comme fournisseur de la future fiche. Dans le cas reproduit, l'écran montrait le fournisseur 122, mais l'approbation réintroduisait le fournisseur 41 et déclenchait ses contrôles personnels.

La conservation des prix initiaux préserve désormais les champs du bénéficiaire lorsque son mode explicite est fournisseur, personnel ou tiers externe. Le montant reste recalculé à partir des prix initiaux ; les fournisseurs de ligne, justificatifs et instantanés de prix restent historiques. Le comportement de repli vers le demandeur est conservé pour les anciennes FEB dépourvues de mode explicite. Une sélection explicite invalide reste invalide.

Les contrôles fournisseur à l'approbation et au paiement ne changent pas. Aucune écriture corrective de production, aucune migration, aucune approbation automatique. Pour une FEB dont l'approbation a échoué et a été annulée transactionnellement, recharger la page après déploiement et relancer le circuit normal.

Validation : `php tests/FebRetainInitialBeneficiaryTest.php` exerce la vraie méthode dans une transaction SQLite, avec adaptation des seules syntaxes MySQL. Il reproduit les références 122/41, vérifie les trois modes de bénéficiaire, la sélection invalide, le montant initial, le maintien des fournisseurs historiques, le rollback et le repli historique. Le test échoue avec l'ancienne méthode.
