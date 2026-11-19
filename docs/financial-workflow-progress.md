# Suivi du workflow financier

Le composant commun affiche une jauge et le journal du serveur dans le popup actif, ou dans une fenêtre dédiée. Il suit les POST financiers des FEB, fiches, bons de commande/BCI, contrats et points journaliers. Les flux SSE existants gardent leur réponse en streaming.

Les étapes décrivent les contrôles, transactions, préparations de documents et notifications effectivement atteints. Une durée inconnue produit une jauge indéterminée ; les lots affichent les nombres réels de fiches traitées. Une étape terminée ne signifie pas une décision favorable : seul le résultat métier confirme la réussite. Le suivi ne rejoue jamais une demande.

Les formulaires FEB restent ouverts pendant leur POST, puis affichent la page HTML exacte renvoyée après redirection, avec ses messages. Les corps de requête, pièces et jetons CSRF sont conservés. Les autres actions AJAX gardent leurs gestionnaires de résultat.

Le journal temporaire est privé, limité à 120 événements, et expire après deux heures. Sa lecture exige un jeton aléatoire propre à la demande et le cookie exact de la session propriétaire. L’API de consultation n’ouvre pas de session PHP afin de ne pas attendre son verrou. Aucun numéro de pièce, cookie ou détail RH n’est retourné. La disponibilité du suivi ne conditionne pas la transaction métier.

La liste d’approbation des fiches renouvelle le contrôle fournisseur au retour dans l’onglet et toutes les 45 secondes. Seul le bouton d’approbation bloqué par le fournisseur est réactivé après régularisation et validation de sa fiche. Le refus reste disponible. Chaque décision et paiement conservent leurs contrôles serveur.

Aucune migration SQL ni réconciliation de données n’est nécessaire pour cette livraison.

## Vérification

- `php tests/FinancialWorkflowProgressTest.php` : isolation du journal, comptage et transactions.
- `PHP_BIN=php python3 tests/FinancialWorkflowProgressHttpTest.py` : lecture pendant le verrou de session (PHP CLI multi-workers).
- `NODE_PATH=/chemin/node_modules node tests/FinancialWorkflowProgressDomTest.cjs` : popup, étapes, transports, conservation CSRF et absence de rejeu (linkedom requis).
- `NODE_PATH=/chemin/node_modules node tests/SupplierApprovalRefreshDomTest.cjs` : refus disponible et réactivation ciblée.
- `php tests/SupplierApprovalGuardTest.php` et `php tests/FebPaymentControlTest.php` : contrôles métier et annulation.

Ces tests utilisent des fixtures ; ils n’effectuent aucun paiement de production. Vérifier le rendu dans la session authentifiée après déploiement.
