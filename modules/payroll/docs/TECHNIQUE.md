# Documentation technique - Moteur de paie FINEA CI

## 1. Installation

Executer la migration:

```sql
modules/payroll/migrations/2026_05_05_create_payroll_engine_ci.sql
```

La migration est additive:

- `CREATE TABLE IF NOT EXISTS`;
- `INSERT IGNORE`;
- index d'unicite sur les tranches fiscales par version et minimum de tranche.

Apres installation, verifier:

```sql
SELECT code, effective_date, active
FROM payroll_rule_versions
ORDER BY effective_date DESC;
```

La version `PAYROLL_CI_2024` doit etre presente.

## 2. Architecture

Le module respecte le flux:

```text
Controller -> Service -> Repository -> Database
```

Responsabilites:

- `PayrollController`: point d'entree applicatif du module.
- `PayrollCalculationService`: orchestration du calcul complet.
- `PayrollSlipGenerationService`: generation et persistance d'un bulletin.
- `PayrollRuleRepository`: lecture et clonage des regles.
- `PayrollSlipRepository`: bulletins, traces de calcul, audits et clotures.
- `PayrollInputDto`: structure d'entree normalisee.
- `PayrollValidator`: validations de base avant calcul.

## 3. Resolution de version

Le service `PayrollVersionResolverService` cherche une version par:

- pays, actuellement `CI`;
- periode de paie;
- code force optionnel;
- date d'effet et date de fin;
- statut actif.

Une version est applicable si:

```text
effective_date <= premier jour de periode
AND (end_date IS NULL OR end_date >= premier jour de periode)
AND active = 1
```

## 4. Calcul des assiettes

### SBI

`SbiCalculationService` parcourt les lignes de salaire et lit la rubrique correspondante dans `payroll_salary_items`.

Une ligne entre dans le SBI si:

```text
taxable_sbi = 1
```

L'exoneration est ensuite appliquee selon `exemption_type`.

### SBS

`SbsCalculationService` applique la meme logique avec:

```text
taxable_sbs = 1
```

Cela permet d'avoir des traitements differents, par exemple une allocation exoneree SBI mais imposable SBS.

## 5. Exonerations

Les exonerations sont parametrees par rubrique:

- `NONE`: aucune exoneration;
- `FIXED_AMOUNT`: plafond fixe;
- `HOURLY_SMIG_MULTIPLE`: plafond en multiple du `SMIG_HOURLY`;
- `STAGE_ALLOWANCE`: plafond si duree inferieure ou egale a 6 mois;
- `APPRENTICESHIP_ALLOWANCE`: plafond si duree inferieure ou egale a 24 mois;
- `TOTAL`: exoneration totale;
- `TOTAL_REMUNERATION_PERCENT`: plafond en pourcentage d'une remuneration de reference.

Le SMIG horaire est dans:

```text
payroll_parameters.param_key = SMIG_HOURLY
```

## 6. Impot progressif

`TaxCalculationService` applique:

```text
base imposable = SBI - abattement
```

Le taux d'abattement est parametre par:

```text
TAX_ABATEMENT_RATE
```

Le calcul est marginal:

```text
impot tranche = montant de tranche consomme * taux de tranche
```

Les tranches sont dans `payroll_tax_brackets`.

## 7. RICF

`RicfCalculationService` calcule:

```text
RICF = ((parts - 1) / 0.5) * RICF_UNIT_AMOUNT
```

Parametres:

- `RICF_MAX_PARTS`;
- `RICF_UNIT_AMOUNT`.

Le nombre de parts est borne par le plafond de version.

## 8. Cotisations sociales

`CnpsCalculationService` lit `payroll_social_contributions`.

Chaque cotisation definit:

- `employee_rate`;
- `employer_rate`;
- `ceiling`;
- `calculation_base`, par exemple `SBS` ou `SBI`;
- `active`.

Le plafond s'applique avant le taux:

```text
base appliquee = min(base brute, plafond)
```

## 9. Net a payer

Formule generale:

```text
net = brut - cotisations salariales - impot net - retenues externes
```

L'impot net est:

```text
max(0, impot brut - RICF)
```

Les retenues externes permettent de faire coexister le moteur avec les retenues RH existantes, par exemple avances ou assurance.

## 10. Snapshot

Chaque generation de bulletin cree:

- une ligne dans `payroll_slips`;
- une ligne dans `payroll_calculation_snapshots`.

La trace de calcul contient notamment:

- entree de calcul;
- version;
- rubriques;
- details SBI;
- details SBS;
- tranches d'impot consommees;
- RICF;
- cotisations;
- plafonds;
- net.

Le bulletin reste donc lisible meme si les regles changent plus tard.

## 11. Audit

Le module utilise `AuditTrail`.

Evenements couverts:

- `payroll_slip_generated`;
- `payroll_rule_version_created`;
- `payroll_period_locked`.

Les futurs ecrans d'edition de parametres doivent journaliser:

- creation;
- modification;
- desactivation;
- changement de version;
- recalcul.

## 12. Cloture de periode

Une periode cloturee est stockee dans:

```text
payroll_period_locks
```

`PayrollSlipGenerationService` refuse la generation si la periode est verrouillee.

## 13. Integration RH existante

Les pages RH actuelles passent par:

- `RhPayrollService`;
- `RhPayrollRepository`;
- `PayrollController`.

L'adaptateur `RhPayrollRepository` transforme les champs historiques du wizard RH en lignes de rubriques moteur:

- `salary_category` -> `SAL_BASE`;
- `over_salary` -> `SURSALAIRE`;
- `seniority_bonus` -> `PRIME_ANCIENNETE`;
- heures supplementaires -> `HEURES_SUP`;
- `transport_bonus` -> `PRIME_TRANSPORT`;
- etc.

## 14. Ajouter une reforme

Workflow recommande:

1. Ouvrir `modules/payroll/views/admin.php`.
2. Cloner la version active.
3. Modifier les parametres de la nouvelle version en base ou via les futurs ecrans d'edition.
4. Tester un bulletin pilote.
5. Activer la nouvelle version.
6. Optionnellement fixer `end_date` sur l'ancienne version.

## 15. Edition fine des parametres

L'ecran `modules/payroll/views/admin.php` permet maintenant d'editer, par version:

- rubriques de paie;
- tranches fiscales;
- cotisations sociales;
- transports;
- parametres generaux et plafonds.

Chaque sauvegarde passe par:

```text
PayrollController::saveSetting()
PayrollRuleRepository::save*
PayrollAuditService::log()
```

Le repository refuse toute modification si la version a deja servi a generer au moins un bulletin:

```text
payroll_slips.rule_version_id = version modifiee
```

Dans ce cas, il faut cloner la version et modifier le clone. Cette regle preserve l'auditabilite des bulletins historiques.

Chaque modification auditee conserve:

- `version_id`;
- etat avant;
- etat apres;
- utilisateur;
- date;
- type de parametre modifie.

## 16. Points d'extension

Extensions prevues:

- multi-pays via `country_code`;
- multi-societes via ajout futur de `company_id`;
- conventions collectives via dimensions supplementaires sur les rubriques;
- revisions de bulletins;
- workflows de validation;
- exports PDF avances.
