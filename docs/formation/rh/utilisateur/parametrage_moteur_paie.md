# Parametrage et utilisation du moteur de paie

## Objectif

Ce guide permet a un gestionnaire RH de prendre en main la paie sans explication technique.

Le moteur de paie sert a :

- calculer les bulletins selon les regles de paie en vigueur ;
- conserver les versions de regles utilisees ;
- eviter de modifier une ancienne regle qui a deja servi ;
- tracer les actions importantes ;
- cloturer une periode de paie apres validation.

## Les 3 espaces a connaitre

### Assistant de paie

Chemin : RH > Paie RH.

Il sert a preparer et generer un bulletin pour un salarie.

Vous y choisissez :

- le salarie ;
- la periode ;
- le contrat ;
- les gains ;
- les retenues ;
- les observations.

Le pointage du mois est repris automatiquement quand il existe.

### Regles de paie par contrat

Chemin : RH > Regles contrats.

Cet ecran sert a creer et regler les types de contrats utilises par l'assistant de paie.

Un gestionnaire RH peut :

- modifier le nom d'un type de contrat existant ;
- ajuster ses coefficients ;
- creer un nouveau type de contrat ;
- le retrouver ensuite dans l'assistant de paie.

L'application cree la reference interne automatiquement. Le RH renseigne le nom et les regles metier.

Les coefficients disponibles sont :

- jours de reference ;
- heures par jour ;
- majoration des heures supplementaires ;
- prime de precarite ;
- taux paye pour mission, conge, demi-journee, absence, maladie et repos.

Ces valeurs sont appliquees automatiquement dans l'assistant de paie.

### Interaction entre contrats et moteur de paie

Les types de contrats ne sont pas isoles du moteur de paie.

Le fonctionnement est le suivant :

1. Le RH cree ou ajuste un type de contrat dans **Regles contrats**.
2. L'assistant de paie reprend ce type de contrat dans l'etape **Contrat**.
3. Les jours de reference, les heures par jour, les heures supplementaires, la prime de precarite et les taux payes sont appliques au bulletin en preparation.
4. Le moteur de paie CI calcule ensuite le bulletin avec la version de regles active : rubriques, exonerations, cotisations, impots, transports et plafonds.
5. Le bulletin conserve le type de contrat et la version de paie utilises.

Dans **Moteur de paie CI**, la zone **Types de contrats relies au moteur** permet de controler rapidement les profils contrats utilises par l'assistant et d'ouvrir leur parametrage.

### Moteur de paie CI

Chemin : RH > Moteur de paie CI.

Cet ecran sert a controler les regles legales et sociales :

- versions de paie ;
- rubriques ;
- tranches d'impot ;
- cotisations sociales ;
- transport minimum ;
- parametres et plafonds ;
- historique des bulletins ;
- audit ;
- cloture des periodes.

Dans les rubriques, l'ecran affiche un resume metier :

- **Impact fiscal** : indique si la rubrique entre dans le Salaire Brut Imposable ;
- **Impact social** : indique si la rubrique entre dans le Salaire Brut Social, la CNPS ou la CMU ;
- **Mode d'exoneration** : explique la limite appliquee avec des mots lisibles, par exemple "Exonere jusqu a 10 % du salaire".

Les points d'interrogation affiches dans l'ecran donnent une aide rapide aux RH sans qu'ils aient besoin de connaitre les noms techniques.

La CMU est traitee comme une cotisation forfaitaire mensuelle. Pour le salarie prive, le parametrage standard retient 500 FCFA part salarie et 500 FCFA part employeur, soit 1 000 FCFA par personne et par mois.

Pour ajouter une cotisation, le RH renseigne le nom, le taux ou le montant fixe, la base de calcul et l'etat **Utilisee**. La reference interne est creee automatiquement.

Pour ajouter un transport minimum, le RH renseigne la localite et le montant mensuel. La reference interne est creee automatiquement.

## Ordre de travail recommande chaque mois

1. Verifier que les dossiers du personnel sont a jour.
2. Verifier le pointage journalier et mensuel.
3. Controler les absences, missions, conges et heures supplementaires.
4. Ouvrir l'assistant de paie.
5. Choisir le salarie et la periode.
6. Controler le contrat et les coefficients affiches.
7. Verifier les gains.
8. Verifier les retenues.
9. Calculer le bulletin.
10. Relire le net a payer.
11. Enregistrer la fiche quand tout est correct.
12. Cloturer la periode lorsque toute la paie du mois est validee.

## Comment generer un bulletin

Dans RH > Paie RH :

1. Selectionnez le salarie.
2. Selectionnez le mois.
3. Verifiez le pointage charge automatiquement.
4. Allez a l'etape Contrat.
5. Choisissez le type de contrat.
6. Verifiez les regles affichees a droite.
7. Allez a l'etape Gains.
8. Controlez le salaire de base, les primes et les heures supplementaires.
9. Allez a l'etape Retenues.
10. Controlez les cotisations, les retenues, les avances et les observations.
11. Cliquez sur Calculer le bulletin.
12. Relisez l'apercu.
13. Cliquez sur Enregistrer la fiche si le bulletin est correct.

## Que verifier avant d'enregistrer

Avant d'enregistrer une fiche de paie, controlez :

- le bon salarie ;
- le bon mois ;
- le bon type de contrat ;
- les jours payes ;
- les heures supplementaires ;
- les primes ;
- les avances et prets ;
- les autres retenues ;
- le net a payer ;
- les observations.

Si une information est incorrecte, corrigez-la avant d'enregistrer.

## Comprendre la gestion des versions

Une version de paie est un ensemble de regles applicable a une periode.

Exemple :

- une version 2024 contient les regles appliquees en 2024 ;
- une nouvelle reforme peut necessiter une version 2025 ;
- les bulletins produits avec la version 2024 doivent rester lisibles avec leurs anciennes regles.

Regle simple :

Une version qui a deja servi a generer des bulletins ne doit plus etre modifiee directement.

Dans ce cas, il faut creer une copie, modifier la copie, tester, puis activer la nouvelle version.

## Creer une nouvelle version de paie

Dans RH > Moteur de paie CI :

1. Aller dans Versions de paie.
2. Choisir la version source.
3. Saisir un nom clair.
4. Saisir la date de debut.
5. Cocher Version active uniquement si elle doit etre utilisee tout de suite.
6. Cliquer sur Creer une copie.
7. Charger la nouvelle version.
8. Ajuster les rubriques, tranches, cotisations, transports ou plafonds.
9. Tester un bulletin pilote.
10. Activer la version quand elle est validee par les RH.

La reference interne de la version est creee automatiquement.

## Modifier les rubriques

Les rubriques indiquent comment chaque element de paie est traite.

Exemples :

- salaire de base ;
- sursalaire ;
- prime d'anciennete ;
- heures supplementaires ;
- transport ;
- retenues.

Pour chaque rubrique, verifiez :

- son nom ;
- son type ;
- si elle entre dans le SBI ;
- si elle entre dans le SBS ;
- si elle est soumise a cotisation ;
- si elle est utilisee.

Ne creez pas de doublon si une rubrique existe deja.

Quand une nouvelle rubrique active est creee dans le moteur, elle devient disponible dans l'assistant de paie, dans la zone **Rubriques ajoutees**.

Le gestionnaire RH peut alors saisir un montant sur le bulletin du salarie. Ce montant est envoye au moteur de paie et impacte le calcul selon les reglages de la rubrique :

- inclusion ou non dans le SBI ;
- inclusion ou non dans le SBS ;
- soumission ou non aux cotisations ;
- exoneration eventuelle ;
- plafond eventuel.

Les references internes sont generees automatiquement par l'application. Le RH renseigne surtout le nom, le type, les assiettes et les plafonds.

## Modifier les tranches d'impot

Les tranches d'impot definissent les seuils et les taux.

Pour chaque tranche, verifiez :

- le minimum ;
- le maximum ;
- le taux.

La derniere tranche peut etre sans limite.

Apres une modification, testez toujours un bulletin avec un salaire faible, un salaire moyen et un salaire eleve.

## Modifier les cotisations sociales

Les cotisations definissent les retenues salariales et les charges employeur.

Dans l'assistant de paie, les champs de cotisations sensibles affichent aussi une aide rapide. Par exemple, **Prestations familiales employeur** designe une charge patronale et non une allocation versee directement au salarie.

Pour chaque cotisation, verifiez :

- le nom ;
- le taux salarie ;
- le taux patronal ;
- le montant fixe salarie si la cotisation est forfaitaire ;
- le montant fixe employeur si la cotisation est forfaitaire ;
- le plafond ;
- la base de calcul ;
- si elle est utilisee.

## Modifier le transport

La partie transport permet de definir les montants minimums par localite.

Pour chaque ligne, verifiez :

- le nom de la localite ;
- le montant mensuel ;
- si la ligne est utilisee.

## Modifier les parametres et plafonds

Cette zone contient les valeurs generales du moteur.

Elle peut contenir par exemple :

- plafond de parts fiscales ;
- montant de reduction ;
- abattement ;
- SMIG horaire ;
- autres plafonds.

Avant de modifier une valeur, notez la valeur actuelle et la raison du changement.

## Cloturer une periode

La cloture sert a eviter qu'un bulletin soit genere par erreur apres validation de la paie.

Dans RH > Moteur de paie CI :

1. Aller dans Cloture des periodes.
2. Choisir le mois.
3. Cliquer sur Cloturer.

Une fois la periode cloturee, le moteur bloque la creation de nouveaux bulletins pour ce mois.

## Audit

L'audit permet de savoir :

- qui a fait l'action ;
- quand l'action a ete faite ;
- quel element a ete concerne ;
- si l'action a reussi ;
- quels changements ont ete enregistres.

Consultez l'audit apres une modification de parametrage importante.

## Erreurs courantes a eviter

- modifier une ancienne version qui a deja produit des bulletins ;
- activer une nouvelle version sans test ;
- oublier de verifier le pointage avant la paie ;
- saisir une prime sans justificatif ;
- cloturer une periode avant la fin des controles ;
- creer deux rubriques pour le meme usage ;
- modifier un plafond sans conserver la raison du changement.

## Reflexe en cas de doute

Si un montant parait anormal :

1. Ne sauvegardez pas le bulletin.
2. Verifiez le pointage.
3. Verifiez le contrat.
4. Verifiez les gains et retenues.
5. Verifiez la version de paie utilisee.
6. Relancez le calcul.
7. Demandez validation avant d'enregistrer si l'ecart reste inexplique.

## Resultat attendu

Un RH doit pouvoir :

- preparer une paie mensuelle ;
- comprendre quelle version de regles est appliquee ;
- creer une nouvelle version sans casser l'historique ;
- modifier les parametres courants ;
- verifier l'audit ;
- cloturer une periode proprement.
