# Validation locale — flux commercial et trésorerie projets

Date : 12 juillet 2026  
Branche : `feature/projet-devis`  
Environnement : local uniquement

## Résultat

- Le passage Devis → Chantier exige désormais la saisie d’un BC client.
- L’écart entre le montant du BC et le TTC du devis est enregistré et signalé, jamais bloqué.
- Les factures de vente sont reliées au BC client du chantier et portent une date d’encaissement prévue.
- La trésorerie globale calcule : solde d’ouverture + encaissements réels − décaissements réels.
- La projection ajoute les factures émises attendues à 15, 30, 60 ou 90 jours.
- Les lignes FEB disposent de `planned_start_date` et `planned_end_date`; les écrans FEB legacy exposent aussi les dates par besoin.
- La liste Devis distingue chantier, facture, fiche de décaissement, brouillon archivable et archive restaurable.

## Migration automatique

`CommercialProjectFinanceMigrationService` est appelé par les contrôleurs Devis et le repository Facturation. Il crée ou complète automatiquement :

- `client_purchase_orders`;
- `chantier_client_purchase_orders`;
- `devis_archives`;
- `treasury_opening_balances`;
- les champs de liaison et de prévision dans `facture_vente_banamur`;
- les dates de planning dans `feb_request_lines`.

La migration est additive et idempotente. `chantier_banamur` demeure MyISAM et aucune table legacy n’a été convertie.

## Reprise historique

- 70 chantiers existants;
- 70 liaisons BC client de reprise;
- statuts `LEGACY_INCOMPLETE`, afin de ne pas prétendre qu’un document client absent existe;
- cinq chantiers dont le devis historique est introuvable sont conservés avec une note explicite de régularisation.

## Nettoyage des devis

Dry-run initial : 72 devis éligibles selon les critères cumulés :

- validation incomplète;
- aucun chantier;
- aucune facture;
- aucune fiche de décaissement.

Les 72 devis ont été archivés logiquement dans `devis_archives`. Aucune ligne de `devis_banamur` n’a été supprimée ou modifiée. Un nouveau dry-run retourne zéro candidat. Chaque archive est restaurable depuis la liste filtrée « Archivés ».

Preuve de restauration transactionnelle : 72 archives avant test, 71 pendant la restauration, puis 72 après rollback. Le service de migration effectue désormais un contrôle de schéma en lecture avant tout DDL, afin de ne pas provoquer de commit implicite lorsqu’un contrôleur est créé dans une transaction métier.

## Trésorerie locale observée

- solde d’ouverture : 0 FCFA;
- encaissements réels identifiables : 0 FCFA;
- décaissements historiques identifiables : 2 141 572 153 FCFA;
- solde calculé : −2 141 572 153 FCFA.

Ce solde négatif est cohérent avec l’absence actuelle de solde d’ouverture et d’encaissements de factures renseignés. L’écran permet désormais de saisir le solde d’ouverture sans requête SQL manuelle.

## Tests

- `php tests/CommercialProjectFinanceTest.php` : OK;
- `php tests/DevisOfferProjectClassificationTest.php` : OK;
- `php tools/devis_archive_candidates.php` après application : 0 candidat;
- syntaxe PHP : OK;
- `git diff --check` : OK;
- HTTP authentifié : Devis actifs, Devis archivés, Factures de vente, Trésorerie et démarrage chantier répondent en 200.

## Limite connue

Le code de l’assistant `gestion_budgetaire/feb/nouvelle.php` n’est plus présent sur cette branche depuis l’annulation antérieure, bien que les tables V2 subsistent en base. Les dates sont donc préparées dans le schéma V2 et exposées dans la FEB legacy; la restauration complète de l’assistant V2 devra faire l’objet d’un lot distinct afin de ne pas réintroduire silencieusement tout l’ancien module annulé.
