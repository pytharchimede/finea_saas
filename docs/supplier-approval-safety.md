# Contrôle fournisseur avant approbation

La même éligibilité fournisseur est contrôlée avant l’approbation et avant le paiement. `SupplierApprovalGuard` restitue un message d’approbation distinct et bloque si le contrôle technique est indisponible. Les anomalies ne révèlent aucune donnée personnelle RH.

La liste d’approbation affiche les motifs sur chaque carte et désactive le bouton en cas d’anomalie. L’API de liste expose également `supplier_approval_error`. L’endpoint de décision relit la fiche entière verrouillée et contrôle le fournisseur avant l’UPDATE. Une fiche refusée n’est plus interprétée comme une approbation déjà effectuée.

La FEB affiche une alerte dans le détail, contrôle la sélection lors de la préparation et renouvelle le contrôle dans les deux transactions d’approbation métier. Une sélection explicitement vide ne réutilise pas un ancien fournisseur. Les points RH signés restent dans leur circuit distinct, avec contrôle de l’instantané signé.

L’ancienne route d’approbation de la table `fiche` utilise le même garde-fou dans une transaction ; une erreur lève une exception plutôt que renvoyer une chaîne non vide interprétée comme un succès. Les anciens dossiers sans rattachement vérifiable sont à régulariser.

L’approbation d’une fiche fournisseur elle-même contrôle aussi son dossier complet. Un dossier documentaire en attente peut devenir vérifié uniquement via la décision habilitée existante, avec justificatif ; les contrôles de pièce et de personnel restent obligatoires. Les règles financières, notifications après succès et contrôles au paiement sont conservés. Aucune migration SQL.

Tests : `SupplierApprovalGuardTest.php` (SQL réel SQLite, sélection explicite, changement depuis aperçu, route historique et rollback), `SupplierPaymentGuardTest.php`, `SupplierFinancialIdentityPolicyTest.php`, `SupplierExceptionPolicyTest.php`, plus syntaxe PHP des points d’entrée et vues. Les points RH de production et les notifications ne sont pas envoyés par les tests.
