# PROCÉDURE DE DÉCAISSEMENT BANAMUR

**Version :** 10 août 2026  
**Objet :** expliquer simplement le parcours d'une dépense, depuis l'expression du besoin jusqu'à la régularisation après paiement.

---

## 1. Fiche des acteurs à compléter

Avant d'utiliser cette procédure, renseigner les personnes désignées par BANAMUR.

| Rôle dans la procédure | Nom et prénoms | Fonction / service | Remplaçant en cas d'absence |
|---|---|---|---|
| Demandeur du besoin | ........................................ | ........................................ | ........................................ |
| Responsable du besoin récurrent | ........................................ | ........................................ | ........................................ |
| Validateur du besoin récurrent | ........................................ | ........................................ | ........................................ |
| Validateur FEB du service concerné | ........................................ | ........................................ | ........................................ |
| Contrôleur de conformité | ........................................ | ........................................ | ........................................ |
| Approbateur | ........................................ | ........................................ | ........................................ |
| Validateur Achat | ........................................ | ........................................ | ........................................ |
| Responsable Trésorerie / paiement | ........................................ | ........................................ | ........................................ |
| Bénéficiaire chargé de la régularisation | ........................................ | ........................................ | ........................................ |
| Contrôleur final de la régularisation | ........................................ | ........................................ | ........................................ |

> Une même personne ne doit pas intervenir à plusieurs étapes sensibles uniquement par commodité. BANAMUR désigne les acteurs selon son organisation et veille, autant que possible, à séparer la demande, l'approbation et le paiement.

---

## 2. Les mots utilisés dans cette procédure

- **FEB :** fiche d'expression de besoin. Elle explique ce qui est demandé, pourquoi, pour quel montant et à quelle date.
- **Besoin récurrent :** dépense qui revient selon une fréquence connue, par exemple chaque semaine ou chaque mois.
- **Fiche de décaissement :** document qui autorise le traitement concret d'un paiement après validation du besoin.
- **Conformité :** contrôle des informations et des pièces avant la poursuite du paiement.
- **Approbation :** accord donné sur l'opportunité et le montant de la dépense.
- **Validation Achat :** confirmation que les contrôles d'achat sont terminés et que la fiche peut être transmise à la Trésorerie.
- **Décaissement :** sortie effective de l'argent.
- **Régularisation :** transmission, après paiement, du procès-verbal de réception ou d'exécution et de la preuve de paiement.
- **BCI :** bon de commande interne produit après le traitement de la FEB, lorsqu'il est applicable.

---

# PARTIE A — DU BESOIN INITIAL AU DÉCAISSEMENT

## 3. Point de départ n° 1 : une FEB créée par un employé

### Étape A1 — Décrire le besoin

**Acteur :** le demandeur.  
**Nom :** ........................................................................

Le demandeur ouvre son espace employé et crée une FEB. Il décrit le besoin avec des mots précis et compréhensibles par une personne qui ne connaît pas le dossier.

### NÉCESSAIRE AVANT DE SOUMETTRE

- le nom et le matricule du demandeur ;
- le service bénéficiaire ;
- la liste détaillée des articles, travaux ou prestations demandés ;
- la catégorie et le type de chaque besoin ;
- les quantités ;
- le fournisseur envisagé et son prix, lorsqu'ils sont connus ;
- une description claire du besoin ;
- le montant estimé en FCFA ;
- la date de livraison souhaitée ;
- les conséquences si le besoin n'est pas satisfait ;
- la fréquence : unique, hebdomadaire, mensuelle, trimestrielle, semestrielle ou annuelle ;
- au moins un justificatif valable : photo d'un document, devis, facture, capture d'un prix ou autre preuve compréhensible.

**Droit nécessaire :** accès à l'espace Employé.  
**Intitulé du droit dans l'application :** `Accès Employé`.

**Résultat :** la FEB est envoyée au contrôle financier. Elle n'autorise pas encore un paiement.

### Si le dossier est incomplet

Le validateur peut demander des précisions ou retourner la FEB pour complément. Le demandeur corrige alors les informations ou ajoute les pièces demandées, puis renvoie le dossier.

---

## 4. Point de départ n° 2 : un besoin récurrent

### Étape A2 — Créer le modèle du besoin récurrent

**Acteur :** le responsable du besoin récurrent.  
**Nom :** ........................................................................

Le responsable crée une seule fois le modèle de la dépense qui reviendra régulièrement.

### NÉCESSAIRE AVANT DE SOUMETTRE

- le libellé du besoin ;
- un montant estimatif supérieur à zéro ;
- la priorité ;
- le type de besoin : interne ou externe ;
- le mode prévu : avance provisoire, décaissement unique, assignation logistique ou sans décaissement ;
- la fréquence et l'intervalle ;
- la date du premier paiement ;
- la première échéance ;
- le nombre de jours d'anticipation pour créer la FEB ;
- le fournisseur, lorsqu'il est déjà connu ;
- une description ou justification suffisamment claire.

**Droit nécessaire :** accès à l'espace des besoins récurrents. Le créateur peut suivre ses propres besoins.  
**Droit supplémentaire pour voir les besoins de tout le monde :** `Voir tous les besoins récurrents`.

**Résultat :** le modèle est placé « En attente de validation ». Aucune FEB automatique ne doit être produite tant qu'il n'est pas activé.

### Étape A3 — Valider et activer le besoin récurrent

**Acteur :** le validateur des besoins récurrents.  
**Nom :** ........................................................................

Le validateur contrôle la fréquence, les dates, le montant, le bénéficiaire, le fournisseur et la justification. S'il donne son accord, il active le modèle.

### NÉCESSAIRE POUR ACTIVER

- un modèle complet et cohérent ;
- un montant réaliste ;
- une fréquence et une première échéance correctes ;
- un fournisseur utilisable, si un fournisseur est renseigné ;
- l'accord du validateur désigné.

**Droit nécessaire :** `Valider un besoin récurrent` ou accès Administrateur.

**Résultat :** avant chaque échéance, une FEB est créée automatiquement selon le délai d'anticipation prévu. Cette FEB rejoint ensuite le même contrôle que toute autre FEB. L'automatisation ne vaut jamais autorisation automatique de payer.

---

## 5. Contrôle et validation de la FEB

### Étape A4 — Étudier la FEB

**Acteur :** le validateur FEB du service bénéficiaire.  
**Nom :** ........................................................................

Le validateur vérifie la réalité du besoin, le prix, les justificatifs, le fournisseur, le délai demandé et l'effet sur le budget. Il peut comparer la proposition du demandeur avec une autre proposition et retenir celle qui convient à BANAMUR.

### NÉCESSAIRE POUR TRAITER LA FEB

- la FEB et ses pièces justificatives ;
- l'accès aux FEB ;
- l'autorisation de valider les FEB du service concerné ;
- une note claire lorsque le dossier est retourné, reporté ou refusé ;
- la vérification du budget existant ou la décision d'intégrer un nouveau budget ;
- la vérification du disponible de trésorerie lorsque le paiement est prévu immédiatement.

**Droits nécessaires, à réunir tous les deux :**

1. `Accès FEB` ;
2. autorisation de validation pour le service auquel appartient la FEB.

L'accès général aux FEB ne suffit donc pas si la personne n'est pas désignée pour le service concerné.

### Décisions possibles

- **Demander des précisions :** le dossier attend les informations complémentaires.
- **Retourner pour complément :** le demandeur doit corriger puis renvoyer.
- **Reporter :** la FEB reste en attente jusqu'à une date future indiquée.
- **Refuser :** le paiement ne peut pas être préparé sur cette FEB. Le motif doit être écrit clairement.
- **Approuver :** le besoin et son traitement budgétaire sont acceptés.

### Étape A5 — Préparer le budget et la fiche de décaissement

Cette opération est réalisée par le validateur FEB au moment de l'approbation, lorsqu'une sortie d'argent est nécessaire.

### NÉCESSAIRE POUR GÉNÉRER LA FICHE

- le chantier ou le bureau réellement concerné ;
- le devis principal ou le devis de travaux supplémentaires concerné ;
- la ligne budgétaire existante, ou la création clairement justifiée d'une nouvelle ligne ;
- le déboursé existant, ou les lignes du nouveau déboursé à créer ;
- le bénéficiaire réel ou le fournisseur approuvé ;
- le montant exact de la fiche ;
- le mode de paiement ;
- la date minimale à laquelle le paiement peut être fait ;
- le détail de la demande ;
- les références et justificatifs nécessaires.

**Cas du paiement complet et unique :** la facture ou sa référence est indispensable avant la certification de conformité.  
**Cas de l'avance provisoire :** les justificatifs autorisant l'avance doivent être présents ; la réception réelle sera détaillée après paiement lors de la régularisation.

**Résultat :** une fiche de décaissement est créée. Elle commence alors le circuit de contrôle du décaissement décrit dans la partie B.

---

# PARTIE B — DE LA FICHE VALIDÉE AU PAIEMENT ET À LA RÉGULARISATION

## 6. Contrôle de conformité

### Étape B1 — Certifier la fiche conforme

**Acteur :** le contrôleur de conformité.  
**Nom :** ........................................................................

Il vérifie que la fiche correspond à la FEB approuvée, que les informations sont cohérentes et que les pièces attendues sont présentes.

### NÉCESSAIRE POUR CERTIFIER CONFORME

- une fiche lisible et rattachée au bon besoin ;
- le bon chantier, bureau, budget et bénéficiaire ;
- un montant cohérent avec la demande et le budget ;
- les justificatifs de la FEB ;
- la référence de facture ou le justificatif exigé pour un paiement complet et unique ;
- l'absence d'anomalie évidente ou de doublon.

**Droit nécessaire :** `Accès Conformité`.

**Résultat :** la fiche devient « Conforme ». En cas de pièce manquante, elle ne doit pas avancer.

---

## 7. Approbation de la dépense

### Étape B2 — Approuver la fiche

**Acteur :** l'approbateur désigné.  
**Nom :** ........................................................................

L'approbateur confirme que BANAMUR accepte la dépense, son objet, son bénéficiaire et son montant.

### NÉCESSAIRE POUR APPROUVER

- la fiche déjà examinée ;
- le montant et le bénéficiaire contrôlés ;
- le budget identifié ;
- les informations permettant de comprendre l'objet de la dépense ;
- l'absence de paiement déjà effectué sur la même fiche.

**Droit nécessaire :** `Accès Approbation`.

**Résultat :** la fiche devient « Approuvée ». Un refus doit être motivé et arrête le circuit jusqu'à correction ou nouvelle décision.

---

## 8. Validation Achat

### Étape B3 — Confirmer que le dossier est prêt pour paiement

**Acteur :** le validateur Achat.  
**Nom :** ........................................................................

Le service Achat réalise le dernier contrôle opérationnel avant l'envoi à la Trésorerie. Il peut aussi reporter la fiche ou la refuser si les conditions d'achat ne sont pas réunies.

### NÉCESSAIRE POUR LA VALIDATION ACHAT

- la conformité obtenue ;
- l'approbation obtenue ;
- la date minimale de paiement atteinte ;
- le bénéficiaire ou fournisseur confirmé ;
- le montant confirmé ;
- les documents d'achat disponibles lorsque le dossier les exige ;
- la vérification qu'aucune régularisation urgente ne bloque le chantier concerné.

**Droit nécessaire :** `Accès Achat`.

**Résultat :** la fiche devient « Validée Achat » et peut apparaître dans la liste de la Trésorerie.

---

## 9. Décaissement final

### Étape B4 — Effectuer et enregistrer le paiement

**Acteur :** le responsable Trésorerie.  
**Nom :** ........................................................................

La Trésorerie contrôle une dernière fois le dossier, effectue le paiement selon le mode prévu et enregistre la sortie d'argent. Le paiement peut être total ou partiel selon la décision autorisée.

### NÉCESSAIRE AVANT DE DÉCAISSER

- la fiche conforme ;
- la fiche approuvée ;
- la validation Achat ;
- la date minimale de paiement atteinte ;
- le disponible réel suffisant ;
- l'identité du bénéficiaire ou les coordonnées du fournisseur ;
- le montant à payer ;
- le mode de paiement ;
- le contrôle qu'aucun paiement identique n'a déjà été enregistré ;
- la preuve du paiement à conserver : reçu, bordereau, capture de transfert, chèque, décharge ou autre document recevable.

**Droit nécessaire :** `Accès Trésorerie`.

**Résultat :** la fiche devient « Décaissée ». Le paiement ouvre ensuite la régularisation lorsque la fiche est concernée par ce circuit.

### En cas de paiement partiel

- inscrire le montant réellement payé ;
- indiquer la date prévue pour le reliquat ;
- vérifier la fiche créée pour le solde restant ;
- ne jamais déclarer comme payé un montant qui n'a pas réellement quitté la trésorerie.

---

## 10. Régularisation après décaissement

### Étape B5 — Constater la réception ou l'exécution

**Acteur principal :** le bénéficiaire inscrit sur la fiche.  
**Nom :** ........................................................................

Après le paiement, le bénéficiaire confirme ce qui a réellement été reçu ou exécuté. Il complète le procès-verbal de réception et joint la preuve de paiement si elle n'a pas déjà été enregistrée par la Trésorerie.

### NÉCESSAIRE POUR TRANSMETTRE LA RÉGULARISATION

- la nature de la réception : travaux, fourniture, prestation ou autre ;
- le projet ou chantier concerné ;
- le lieu ;
- la date de réception ou du constat ;
- chaque ligne de la FEB marquée comme terminée ou restant à exécuter ;
- pour chaque ligne non terminée, la date prévue de démarrage ou de poursuite ;
- les observations, réserves et actions restantes, si nécessaire ;
- le montant concerné ;
- le nom du signataire ;
- la signature du bénéficiaire ;
- la preuve de paiement obligatoire : reçu, capture, bordereau, chèque, décharge ou document équivalent.

**Droit nécessaire pour le bénéficiaire :** accès à l'espace Employé et être la personne bénéficiaire de la fiche. Une autre personne ne peut pas signer à sa place, sauf intervention « Pour ordre » du service comptable prévue par l'organisation.

**Résultat :** le procès-verbal signé et la preuve de paiement sont transmis au service comptable.

### Responsabilité du bénéficiaire

Le bénéficiaire reste responsable de la sincérité des informations sur ce qui a été reçu ou exécuté. Si certaines lignes ne sont pas terminées, il doit indiquer leur situation réelle et leur date prévue. Il ne doit pas déclarer une réception complète lorsqu'elle ne l'est pas.

---

## 11. Contrôle final de la régularisation

### Étape B6 — Valider ou retourner la régularisation

**Acteur :** le contrôleur final du service comptable ou de la Trésorerie.  
**Nom :** ........................................................................

Il compare le procès-verbal, la FEB, la fiche de décaissement et la preuve de paiement.

### NÉCESSAIRE POUR VALIDER LA RÉGULARISATION

- un procès-verbal signé ;
- une preuve de paiement lisible ;
- un montant conforme au paiement enregistré ;
- une description cohérente de la réception ou de l'exécution ;
- les dates prévues pour les éléments non terminés ;
- une note claire si une correction est demandée.

**Droits permettant le traitement comptable :** `Accès Comptabilité`, `Accès Trésorerie` ou accès Administrateur.  
**Accès de consultation possible :** Direction, selon les droits qui lui ont été attribués.

### Décisions possibles

- **Valider la complétion :** le dossier est régularisé.
- **Demander une correction :** le bénéficiaire corrige le procès-verbal ou remplace la preuve, puis renvoie le dossier.
- **Signer pour ordre :** la Comptabilité peut préparer ou compléter le dossier et signer « Pour ordre » lorsque cette intervention est autorisée. Le nom, la fonction et l'identité du signataire comptable restent visibles.

**Résultat final :** le décaissement est payé, justifié et régularisé.

---

## 12. Résumé des droits par acteur

| Acteur | Ce qu'il peut faire | Droit nécessaire |
|---|---|---|
| Demandeur | Créer une FEB, répondre aux demandes de complément, suivre ses demandes | Accès Employé |
| Créateur d'un besoin récurrent | Créer et suivre son modèle | Accès à l'espace des besoins récurrents |
| Superviseur des besoins récurrents | Voir l'ensemble des modèles | Voir tous les besoins récurrents |
| Validateur d'un besoin récurrent | Activer le modèle avant les générations automatiques | Valider un besoin récurrent |
| Validateur FEB | Contrôler, retourner, reporter, refuser ou approuver une FEB | Accès FEB + autorisation pour le service concerné |
| Contrôleur de conformité | Certifier la présence et la cohérence des pièces | Accès Conformité |
| Approbateur | Donner l'accord sur la dépense | Accès Approbation |
| Validateur Achat | Confirmer que le dossier peut partir au paiement | Accès Achat |
| Responsable Trésorerie | Effectuer et enregistrer le décaissement | Accès Trésorerie |
| Bénéficiaire | Signer le PV et transmettre la preuve après paiement | Accès Employé + être le bénéficiaire de la fiche |
| Comptabilité / Trésorerie | Contrôler, corriger ou valider la régularisation | Accès Comptabilité, Accès Trésorerie ou Administrateur |

> L'accès Administrateur permet techniquement de nombreuses opérations, mais il ne remplace pas une désignation formelle par BANAMUR. Les responsabilités doivent toujours rester identifiables par le nom de la personne qui agit.

---

## 13. Règles simples à retenir

1. **Pas de besoin vague :** la demande doit expliquer quoi, pourquoi, pour qui, combien et quand.
2. **Pas de validation sans pièce :** les justificatifs doivent être lisibles et correspondre au besoin.
3. **Pas de paiement sans parcours complet :** conformité, approbation et validation Achat doivent être acquises.
4. **Pas de paiement avant la date autorisée.**
5. **Pas de faux bénéficiaire :** le bénéficiaire inscrit doit être celui qui reçoit les fonds ou répond de leur utilisation.
6. **Pas de régularisation sans preuve de paiement.**
7. **Pas de réception fictive :** les travaux, fournitures ou prestations non terminés doivent être signalés comme tels.
8. **Tout refus, retour ou report doit avoir un motif clair.**
9. **Chaque action doit permettre d'identifier la personne qui l'a réalisée.**
10. **En cas de doute, le dossier est arrêté et retourné pour clarification avant toute sortie d'argent.**

---

# PARTIE C — PROMPTS POUR PRODUIRE DES SCHÉMAS

## 14. Prompt n° 1 : schéma complet destiné à tout le personnel

```text
Crée un schéma de procédure professionnel, très simple et entièrement en français, destiné à l'ensemble du personnel de BANAMUR. Le schéma doit être compréhensible sans connaissance comptable ou informatique.

Titre : « Du besoin au paiement — Procédure BANAMUR ».

Présente deux points de départ qui se rejoignent :
1. FEB créée par un employé ;
2. Besoin récurrent créé, validé et activé, puis génération automatique d'une FEB avant chaque échéance.

Après leur jonction, affiche les étapes dans cet ordre :
1. Création de la FEB par le demandeur.
2. Contrôle de la FEB par le validateur autorisé pour le service.
3. Décision : demander un complément, reporter, refuser ou approuver.
4. En cas d'approbation, rattacher le budget et générer la fiche de décaissement.
5. Contrôle de conformité.
6. Approbation de la dépense.
7. Validation Achat.
8. Contrôle et paiement par la Trésorerie.
9. Après paiement, procès-verbal de réception ou d'exécution signé par le bénéficiaire.
10. Ajout obligatoire de la preuve de paiement.
11. Contrôle final par la Comptabilité ou la Trésorerie.
12. Fin : décaissement régularisé.

Pour chaque étape, affiche dans un petit encadré :
- Acteur : [NOM À RENSEIGNER]
- Nécessaire : les 2 à 5 éléments indispensables
- Droit : le droit d'accès nécessaire
- Résultat : le document ou le statut obtenu

Utilise ces couleurs :
- bleu pour la création et l'information ;
- orange pour les contrôles et les attentes ;
- vert pour les validations ;
- rouge pour les refus, blocages ou pièces manquantes ;
- violet pour le paiement et la régularisation.

Ajoute clairement les boucles suivantes :
- dossier incomplet → retour au demandeur ;
- dossier refusé → arrêt ;
- régularisation à corriger → retour au bénéficiaire ;
- paiement partiel → création et suivi du reliquat.

Évite tout mot informatique, toute référence à des tables, du code ou des écrans techniques. Utilise de grandes flèches, peu de texte, des phrases naturelles et des icônes simples de document, contrôle, accord, panier d'achat, paiement, reçu et signature.
```

## 15. Prompt n° 2 : schéma par responsabilités, avec les noms

Remplacer d'abord les éléments entre crochets par les noms retenus par BANAMUR.

```text
Crée un schéma horizontal par couloirs de responsabilités pour BANAMUR, en français simple.

Les couloirs sont :
1. Demandeur — [NOM DU DEMANDEUR OU DU RESPONSABLE]
2. Validateur FEB — [NOM DU VALIDATEUR FEB]
3. Conformité — [NOM DU CONTRÔLEUR DE CONFORMITÉ]
4. Approbation — [NOM DE L'APPROBATEUR]
5. Achat — [NOM DU VALIDATEUR ACHAT]
6. Trésorerie — [NOM DU RESPONSABLE TRÉSORERIE]
7. Bénéficiaire — [NOM DU BÉNÉFICIAIRE]
8. Comptabilité — [NOM DU CONTRÔLEUR FINAL]

Place les actions dans le couloir de la personne responsable :
- le demandeur décrit le besoin et joint les justificatifs ;
- le validateur FEB contrôle le prix, les pièces, le budget et approuve ou retourne ;
- la fiche de décaissement est créée après approbation de la FEB ;
- la Conformité contrôle les informations et la facture lorsqu'elle est obligatoire ;
- l'Approbateur autorise la dépense ;
- l'Achat confirme que le dossier est prêt ;
- la Trésorerie vérifie le disponible, effectue le paiement et conserve la preuve ;
- le bénéficiaire constate la réception réelle, signe le procès-verbal et joint la preuve de paiement ;
- la Comptabilité valide la régularisation ou demande une correction.

À chaque passage entre deux personnes, indique le document transmis. Fais ressortir en rouge les trois interdictions : « aucune pièce = pas de validation », « parcours incomplet = pas de paiement », « aucune preuve = pas de régularisation ».

Le rendu doit tenir sur une page A3 paysage, avec une police lisible, des phrases courtes, des flèches non croisées et une légende très discrète.
```

---

## 16. Validation interne du présent document

| Validation | Nom et prénoms | Date | Signature |
|---|---|---|---|
| Direction Générale | ........................................ | .... / .... / ........ | ........................................ |
| Direction Administrative et Financière | ........................................ | .... / .... / ........ | ........................................ |
| Responsable Achat | ........................................ | .... / .... / ........ | ........................................ |
| Responsable Trésorerie | ........................................ | .... / .... / ........ | ........................................ |

