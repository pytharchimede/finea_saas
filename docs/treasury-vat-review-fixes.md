# Corrections de la revue TVA avant promotion vers main

## Restitution d'emprunt TVA

Le prêt soldé constitue l'écriture de restitution : montant remboursé, date vat_repaid_at et acteur vat_repaid_by. La liste des mouvements inclut désormais une sortie réelle vat_repayment à cette date. Le disponible, le contrôle du retour vers les entrées et le contrôle d'une nouvelle restitution incluent cette sortie. L'entrée historique de l'emprunt reste brute ; la déduire également des entrées provoquerait un double retrait.

Les prêts déjà soldés par l'ancienne version sont inclus sans migration ni duplication dans treasury_manual_movements. La réserve protégée remonte par la diminution de la dette. Une restitution est un transfert interne vers la réserve fiscale, pas un paiement fiscal au Trésor. Les prêts externes, actifs et annulés sont exclus de ces sorties. Le modèle actuel ne propose que des restitutions intégrales ; il ne reconstitue pas un échéancier de remboursements partiels.

Le verrou de la situation initiale sérialise les restitutions avec les transferts utilisant déjà ce verrou. Le verrou du prêt et son statut empêchent un double traitement du même prêt.

## Remises et anciens instantanés

La remise globale, stockée en montant, est répartie sur le HT et la TVA, puis le prorata encaissé est appliqué. Ce calcul suit les exports de ventes, y compris les factures à plusieurs taux.

Les nouveaux instantanés utilisent le calcul corrigé. Les anciens restent figés jusqu'à un contrôle explicite :

```bash
php scripts/repair_vat_snapshot_discounts.php --audit /home/fidestci/projet.fidest.ci
```

L'audit n'instancie pas le service et n'exécute aucun DDL. Il liste les corrections possibles et les factures nécessitant une vérification. Il exige les mêmes lignes source, la même date, le même encaissement et le même HT, ainsi que des taux figés dans les lignes ; seule une TVA correspondant précisément à l'ancien calcul peut être corrigée.

Après lecture du rapport et sauvegarde BDD :

```bash
php scripts/repair_vat_snapshot_discounts.php --apply /home/fidestci/projet.fidest.ci
```

Les lignes sont relues sous verrou, les valeurs avant correction sont sauvegardées sous /home/fidestci/finea-vat-repairs (hors site public), puis seule amount_vat est mise à jour en transaction. Une seconde exécution ne recalcule pas une ligne déjà corrigée. Les cas inexpliqués sont conservés et signalés. Le script est exclu du déploiement public et se lance depuis une copie Git propre à jour. Le second argument désigne la racine de l’application dont la configuration BDD est chargée ; il évite d’utiliser les identifiants de développement de la copie Git.

## Régularisation urgente : règle métier conservée

La règle demandée est le déblocage du demandeur et du chantier dès la soumission de la FEB liée, validée ou non. L'approbation ne constitue donc pas la condition de déblocage. Le lien et le statut regularization_pending restent conservés tant que la fiche finale n'existe pas. Le correctif ne réintroduit pas de blocage à la suite d'un rejet ; le suivi de la FEB reste nécessaire. Le test de régression vérifie cette distinction.

## Vérifications

```bash
php tests/manual_treasury_vat_test.php
php tests/urgent_regularization_submission_test.php
```

Les tests requièrent pdo_sqlite uniquement dans l'environnement de test. Le SQL financier est exécuté sur une base SQLite isolée avec adaptation de l'introspection et des clauses MySQL ; ils ne valident pas la concurrence réelle des verrous InnoDB. Ils couvrent les calculs, la baisse du disponible, les anciennes restitutions, le double clic, les périodes et le rollback. Aucun accès à la BDD de production n'est utilisé.
