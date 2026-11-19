# Processus des contrats de sous-traitance

## Principe général

Un contrat est construit progressivement par cinq services internes. Chaque service ne complète que sa partie et la fait signer par le responsable habilité. L’administrateur et le Directeur général disposent d’une vue globale.

Le document contractuel externe est ouvert uniquement après la dernière validation interne. Il porte seulement les signatures du Directeur général de BANAMUR et du prestataire.

Les informations multiples — pièces manquantes, planning, méthodologie, moyens, véhicules, matériaux, EPI, exigences et habilitations — sont saisies élément par élément avec le bouton **Ajouter un élément**. Chaque élément peut être modifié ou retiré indépendamment.

## Alertes progressives

Après chaque signature interne, Finéa ouvre l’étape suivante et avertit automatiquement son responsable par notification système et par e-mail :

`RH → Technique → Logistique → QHSE → Comptabilité → Direction générale`

Tous les autres responsables désignés, le créateur du contrat et l’auteur de la validation sont ajoutés en copie pour assurer le suivi complet. Une panne temporaire de messagerie ne remet pas en cause une signature déjà enregistrée ; elle est journalisée par l’application.

## Étapes

### 1. Ressources humaines

- Sélection du chantier, du prestataire et des lignes de devis.
- Vérification du dossier fournisseur.
- Définition des clauses légales et particulières.
- Saisie des effectifs sollicités : ouvriers, techniciens et autre main-d’œuvre.
- Désignation des responsables RH, Technique, Logistique, QHSE, Comptabilité et Direction générale pour tout le circuit.
- Signature obligatoire : Directeur des Ressources humaines.

Le montant du contrat est calculé automatiquement à partir des déboursés des lignes sélectionnées, et non à partir du prix de vente du devis.

### 2. Département technique

- Objet précis du contrat.
- Description technique des travaux.
- Date de début et délai en jours.
- Planning d’exécution transmis par le prestataire.
- Méthodologie et besoins techniques.
- Signature obligatoire : Directeur technique.

La date de fin prévue est calculée automatiquement à partir de la date de début et du délai.

### 3. Logistique

- Validation des véhicules et engins.
- Matériaux à mobiliser ou acquérir.
- Matériel et outillage.
- Conditions de disponibilité des moyens.
- Signature obligatoire : Directeur logistique.

### 4. QHSE

- EPI obligatoires.
- Mesures de prévention et exigences QHSE.
- Formations, habilitations et accueil sécurité.
- Signature obligatoire : Responsable HSE ou Responsable QHSE.

### 5. Comptabilité

- Acompte contractuel et pourcentage de démarrage.
- Retenue de garantie.
- Pénalités de retard.
- Échéancier et conditions de paiement.
- Signature : Comptable/Responsable comptabilité ou Directeur général.

La validation comptable clôt le circuit interne et place le contrat dans l’état `a_signer`.

### 6. Direction générale et prestataire

La rubrique **Direction générale** présente le contrat consolidé, les cinq validations internes, les travaux, le montant issu des déboursés et la date de fin prévue.

Le DG peut ensuite :

1. examiner le PDF contractuel ;
2. sélectionner le signataire de la Direction générale ;
3. apposer sa signature électronique ;
4. faire apposer la signature du prestataire.

Après les deux signatures, le contrat passe à l’état `actif`. Les FEB contractuelles et les PV de réception deviennent alors disponibles.

## Paiements et FEB contractuelles

Une demande de paiement suit obligatoirement les étapes suivantes :

1. choisir la nature du paiement (acompte, avancement ou solde) et indiquer l’avancement cumulé ;
2. contrôler le calcul automatique du brut, de la reprise progressive de l’acompte, de la retenue et du net exigible ;
3. joindre la facture FNE ;
4. établir le PV interne par ligne de devis, décrire les travaux réellement constatés et signaler séparément chaque non-conformité (réserve) ;
5. faire signer le PV par le responsable Banamur désigné et le sous-traitant ;
6. soumettre la FEB, qui rejoint alors le circuit ordinaire : conformité, approbation, validation puis décaissement.

Le pourcentage d’avancement mesure les travaux exécutés. Il ne crée pas automatiquement une réserve : la décision « avec réserves » dépend uniquement des non-conformités explicitement cochées et décrites.

Une seule demande contractuelle peut être ouverte à la fois. Tant que son PV n’est pas signé et sa FEB soumise, ou tant que sa FEB demeure dans le circuit, aucune nouvelle demande n’est calculée.

Le contrat retient uniquement les montants réellement décaissés. Une FEB refusée ne consomme donc aucun montant. En cas de décaissement partiel, seule la somme effectivement payée est prise en compte et le reliquat reste disponible dans le calcul de la prochaine demande.

## Droits et visibilité

- RH, Technique, Logistique, QHSE et Comptabilité : accès à leur propre partie pendant la préparation.
- Administrateur : accès à toutes les parties et aux opérations d’administration.
- Directeur général : lecture du contrat complet et accès à la phase finale ; il peut également signer la partie comptable lorsqu’il est désigné.
- Le serveur vérifie la fonction du signataire et son compte utilisateur actif. Modifier manuellement le formulaire ne permet pas de contourner cette règle.

## Contrat local de démonstration

Le contrat dont l’objet commence par `[DÉMONSTRATION LOCALE]` est réservé aux essais dans cet environnement. Il est préparé jusqu’à la phase Direction générale, sans fausse signature externe. Il ne doit pas être exporté vers une base de production.
