# Fournisseurs et circuit FEB → point journalier → paiement

Base : `develop`, commit `b75c3000e7b91deae00e802c3efba45f47a28a8d`.

## Identité du bénéficiaire

Une personne morale s’identifie par les informations et justificatifs de l’entreprise. Ni une CNI fournisseur ni la CNI du gérant ne sont obligatoires pour ce profil. Les contrôles existants NCC/FNE, SIRET ou identité internationale, activation et validation restent appliqués. Pour une personne physique, le numéro de pièce et le document restent contrôlés, ainsi que l’exclusion du personnel du référentiel fournisseurs.

La création historique de fiche imposait photo et CNI à tous les bénéficiaires, même lorsque la FEB validée désignait explicitement une entreprise. Le formulaire et ses deux traitements reprennent désormais les documents du fournisseur réellement désigné dans la FEB approuvée. Un fournisseur associé à l’achat ne suffit pas : si l’argent est remis à un employé ou à un tiers, ses contrôles personnels restent applicables. Le type fourni par le navigateur ne peut accorder la dispense. La route de compatibilité conserve aussi les références fournisseur et FEB ; deux colonnes nullable sont ajoutées à `fiche` si cette route est utilisée et qu’elles n’existent pas.

## Incohérences corrigées

- Contrôle fournisseur avant l’arrêt du point, pour éviter d’envoyer au DG un dossier qui sera refusé au paiement.
- Paiement d’une fiche réservée dans un point bloqué tant que le DG n’a pas signé. Les paiements total, partiel et groupé passent par le contrôle du modèle, y compris les appels directs des anciens endpoints.
- Montant payé identique au montant signé ; toute modification du montant initial, du bénéficiaire, des références fournisseur/personnel/FEB/contrat ou de l’affectation budgétaire depuis l’arrêt du point bloque le paiement.
- Signature DG et état certifié enregistrés dans une même transaction ; la révision et la signature verrouillent la même ligne de point. Une autorisation générique de signataire ne permet plus d’être enregistrée comme signature DG.
- Identité d’une fiche déjà payée ou réservée dans un point arrêté/certifié protégée contre la resynchronisation automatique depuis le référentiel.
- En cas d’échec d’une ligne d’un lot, les autres lignes réellement payées sont inscrites au journal du payeur avant de signaler l’échec. Le même point ne peut pas être traité par deux demandes simultanées de paiement depuis cette interface.
- Synchronisation automatique « payé » limitée aux points certifiés ayant une signature DG et dont chaque montant effectivement payé correspond au montant du point.

Les paiements manuels non réservés dans un point conservent leur circuit existant. Les contrôles de trésorerie, de bon de réception, de date minimale et de règlement RH signé sont conservés. Aucun envoi, signature ou versement réel n’est déclenché par ces tests.

## Validation

Tests PHP sous PHP 8.3 : `SupplierPaymentGuardTest`, `SupplierApprovalGuardTest`, `SupplierIdentityTest`, `SupplierFinancialIdentityPolicyTest`, `SupplierExceptionPolicyTest`, `FebWorkflowGuardTest`, `FebPaymentControlTest`, `FebSupplierBeneficiaryTest`, `DailyPointPaymentPolicyTest`, `FebBeneficiarySnapshotTest`, `LegacyFicheSupplierLinkTest`, `DailyPointBatchJournalTest`.

Test Node : `DisbursementSupplierDocumentsDomTest.cjs`. Contrôle de syntaxe PHP et JavaScript des fichiers modifiés.

Les tests combinent SQLite pour les écritures et des adaptateurs PDO pour les requêtes propres à MariaDB. Ils ne remplacent pas une recette sur la base de staging MariaDB. Avant déploiement, vérifier un fournisseur société sans CNI, une personne physique, un point signé avec paiement partiel, un lot dont une ligne est bloquée et le rapprochement du journal payeur. Les lignes déjà payées avant ce correctif ne sont pas réparées automatiquement. Une interruption de processus après validation d’un paiement et avant l’écriture du journal nécessite toujours un rapprochement ; cette modification ne rend pas le lot atomique.
