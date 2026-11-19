# Valeurs finales de la FEB, de la fiche et du BCI

L'approbation sans proposition contradictoire ne doit pas restaurer les prix ou le bénéficiaire initiaux du demandeur. Elle clôture les consultations non retenues en laissant intact le payload enregistré. Les propositions déjà retenues restent historiques et ne sont pas révoquées par cette action.

Le montant confirmé dans la popup est validé et enregistré dans la transaction d'approbation. La couverture financière et la fiche reprennent ensuite le montant final de la FEB relue, ainsi que son bénéficiaire enregistré. Le BCI généré à cette étape utilise ce même montant comme montant approuvé ; le montant d'origine demeure une information historique distincte. L'option et le message de confirmation décrivent désormais cette règle.

Les anciennes soumissions utilisant encore le champ retain_initial_prices sont acceptées comme une approbation des valeurs courantes, sans restauration. La méthode historique retainAllInitial reste disponible pour compatibilité mais le workflow d'approbation ne l'appelle plus.

Tests : FebCurrentApprovalValuesTest (45000 enregistrés au lieu des 30000 d'origine, 47000 confirmés en popup, fournisseur 122 au lieu de 41, instantané BCI à 47000, clôture de consultations et rollback), FebUnifiedEditTest, FebAssignmentBeneficiaryTest, FebBeneficiarySnapshotTest, tests DOM du formulaire.

Aucune migration ni modification directe des FEB de production. Après déploiement, recharger la page et vérifier le montant et le bénéficiaire avant de relancer l'approbation normale.
