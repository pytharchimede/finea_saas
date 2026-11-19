# Guide utilisateur - Paie Cote d'Ivoire FINEA

## 1. A quoi sert le moteur

Le moteur de paie permet de generer des bulletins selon une version reglementaire precise. Il calcule automatiquement:

- salaire brut;
- SBI;
- SBS;
- base imposable;
- impot progressif;
- RICF;
- cotisations salariales;
- cotisations patronales;
- net a payer.

Chaque bulletin garde les regles utilisees au moment de sa generation.

## 2. Acces

Depuis les pages RH:

- `rh/paie_rh.php` pour generer un bulletin;
- `rh/parametrage_paie_rh.php` pour les regles de contrat RH;
- `modules/payroll/views/admin.php` pour consulter le moteur reglementaire.

Le bouton `Moteur de paie CI` est disponible depuis les pages de paie RH.

## 3. Generer un bulletin

1. Ouvrir `Paie RH`.
2. Choisir le salarie.
3. Choisir la periode.
4. Verifier le contrat et les jours de presence.
5. Saisir ou verifier les gains:
   - salaire categoriel;
   - sursalaire;
   - primes;
   - heures supplementaires;
   - transport;
   - autres elements.
6. Verifier les retenues:
   - avances;
   - assurance;
   - autres retenues.
7. Cliquer sur calculer pour voir l'apercu.
8. Cliquer sur enregistrer pour generer le bulletin.

Le bulletin sauvegarde automatiquement une trace complete du calcul.

## 4. Lire le bulletin

Le bulletin affiche:

- brut;
- SBI;
- SBS;
- base imposable;
- impot brut;
- RICF;
- impot net;
- cotisations salariales;
- cotisations patronales;
- net a payer.

Les lignes de paie indiquent les gains, retenues salariales et charges patronales.

## 5. Consulter l'administration du moteur

Dans `Moteur de paie CI`, vous pouvez consulter:

- la version reglementaire active;
- les rubriques;
- le bareme fiscal;
- les cotisations;
- les transports minimums;
- les bulletins recents;
- les evenements d'audit;
- les periodes cloturees.

## 6. Creer une nouvelle version reglementaire

Quand une reforme arrive:

1. Aller dans `Moteur de paie CI`.
2. Dans `Versions reglementaires`, choisir la version source.
3. Saisir un nouveau code, par exemple `PAYROLL_CI_2025`.
4. Saisir un libelle clair.
5. Saisir la date d'effet.
6. Cocher `Version active` seulement quand la version doit etre utilisee.
7. Cliquer sur `Creer une copie`.

Important: une ancienne version ne doit pas etre modifiee si elle a deja servi a generer des bulletins.

## 7. Modifier les parametres d'une version

Dans `Moteur de paie CI`, choisir d'abord la version a parametrer.

Les sections repliables permettent d'ajuster:

- rubriques;
- tranches d'impot;
- cotisations;
- transport;
- parametres et plafonds generaux.

Si la version a deja servi pour des bulletins, FINEA bloque l'enregistrement. Dans ce cas:

1. Cloner la version.
2. Charger la nouvelle version clonee.
3. Effectuer les modifications.
4. Tester un bulletin.
5. Activer la version quand elle est valide.

Chaque modification est tracee dans l'audit paie.

## 8. Cloturer une periode

La cloture evite les generations accidentelles apres validation de la paie.

1. Aller dans `Moteur de paie CI`.
2. Section `Cloture des periodes`.
3. Choisir le mois.
4. Cliquer sur `Cloturer`.

Apres cloture, le moteur refuse de generer un bulletin pour cette periode.

## 9. Controle avant validation

Avant d'enregistrer une paie, verifier:

- le salarie est le bon;
- la periode est correcte;
- le contrat est correct;
- les jours payes et heures supplementaires sont coherents;
- les primes sont justifiees;
- les retenues sont correctes;
- le net a payer est attendu;
- le bulletin n'est pas genere sur une periode deja cloturee.

## 10. Audit

Les actions importantes sont tracees:

- generation de bulletin;
- creation de version;
- modification de parametres;
- cloture de periode.

L'audit permet de savoir qui a effectue l'action, quand, et avec quels details.

## 11. Que faire en cas d'erreur

Si le bulletin est faux avant sauvegarde:

1. Corriger les donnees dans le wizard.
2. Relancer le calcul.

Si le bulletin est faux apres sauvegarde:

1. Ne pas modifier une ancienne version reglementaire.
2. Corriger la donnee source ou la version applicable si necessaire.
3. Regenerer selon la procedure interne de recalcul.
4. Conserver l'ancien bulletin comme trace.

## 12. Bonnes pratiques

- Cloturer chaque periode apres validation.
- Ne jamais reutiliser un code de version.
- Documenter toute nouvelle version.
- Comparer quelques bulletins pilotes avant activation d'une reforme.
- Garder les justificatifs RH des primes et retenues.
