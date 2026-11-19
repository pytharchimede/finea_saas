# Archivage des fournisseurs

Dans Fournisseurs, utiliser **Archiver** sur une ligne ou sur la fiche. Les gestionnaires du module, la comptabilité et les administrateurs peuvent archiver et réactiver. Chaque action utilise POST, une confirmation et un jeton CSRF de session.

Le filtre propose Actifs (non archivés), Archivés et Tous. Les archives disparaissent du répertoire courant, des alertes de complétude, des compteurs courants et des sélections. La date et l’utilisateur ayant archivé sont visibles dans la fiche.

Aucune suppression de fournisseur ni de document financier. Les opérations existantes gardent leur lien. L’édition d’une facture d’achat conserve son fournisseur actuel même archivé ; une nouvelle affectation à un fournisseur inactif est refusée. Les nouvelles FEB utilisent déjà le contrôle serveur actif/approuvé. L’archivage positionne actif à 0 ; la restauration rétablit sa valeur antérieure sans changer la validation comptable.

Les colonnes archived_at, archived_by_user_id et archive_previous_active sont ajoutées automatiquement par les initialiseurs existants du référentiel (FournisseurRepository et FournisseurMaster). Le compte SQL doit disposer du droit ALTER, comme pour les autres évolutions de ce module. Aucun import SQL manuel requis.

Vérification reproductible : `python3 tests/supplier_archive_sql_test.py`. Ce test exécute les requêtes d’archivage/restauration réelles sur SQLite, vérifie l’idempotence, l’auteur initial, le maintien de l’état inactif antérieur et des liens de facturation. Il ne remplace pas une recette PHP/MariaDB.

## Actions depuis le tableau de bord

Sur dashboard_fournisseur.php : Archiver et Activer/Désactiver directement sur chaque carte ; Restaurer pour les archives. Les cases permettent une action groupée sur la page visible (24 fiches maximum). Le compteur, Tout sélectionner et la confirmation indiquent le nombre exact. Changer de page ou de filtre réinitialise la sélection. Le filtre Non archivés / Archivés / Tous est conservé dans la pagination.

Les actions groupées vérifient les mêmes permissions et le jeton CSRF côté serveur. Chaque traitement est une seule requête UPDATE atomique ; les fournisseurs déjà dans l’état demandé ou non concernés sont ignorés et le nombre réellement modifié est affiché. Activer ne restaure jamais une archive implicitement.

Test SQL complémentaire : `python3 tests/supplier_bulk_state_test.py` (sélection mixte, archives préexistantes, idempotence, restauration de l’état antérieur, liens financiers).
