# Moteur de paie FINEA CI

## Objet

Le module `modules/payroll` porte le moteur de paie reglementaire de FINEA pour la Cote d'Ivoire. Il a ete concu comme un moteur ERP versionne, auditable et parametrable, pas comme un simple calculateur.

Principes directeurs:

- aucune regle fiscale ou sociale codee en dur dans les services metier;
- toutes les regles dependent d'une version reglementaire;
- chaque bulletin conserve la version et une trace complete du calcul;
- une reforme se gere par creation d'une nouvelle version, jamais par modification destructive;
- les ecrans RH existants consomment progressivement le moteur via repository/service.

## Emplacement

```text
modules/payroll/
├── controllers/
├── services/
├── repositories/
├── models/
├── views/
├── routes/
├── config/
├── migrations/
├── helpers/
├── policies/
├── validators/
├── enums/
├── dto/
├── traits/
└── docs/
```

## Flux applicatif

```text
Controller -> Service -> Repository -> Database
```

Les vues ne portent pas de logique metier. Les controleurs orchestrent. Les services calculent. Les repositories lisent et ecrivent en base.

## Concepts de paie couverts

- salaire brut;
- Salaire Brut Imposable, `SBI`;
- Salaire Brut Social, `SBS`;
- base imposable apres abattement;
- cotisations salariales;
- cotisations patronales;
- impot progressif marginal;
- Reduction d'Impot pour Charges Familiales, `RICF`;
- net a payer;
- trace de calcul;
- audit;
- cloture de periode.

## Version active initiale

La migration installe la version:

```text
PAYROLL_CI_2024
```

Elle contient les rubriques, tranches fiscales, cotisations, plafonds, transports et parametres initiaux.

## Tables principales

- `payroll_rule_versions`
- `payroll_salary_items`
- `payroll_tax_brackets`
- `payroll_family_parts`
- `payroll_social_contributions`
- `payroll_transport_rates`
- `payroll_parameters`
- `payroll_slips`
- `payroll_calculation_snapshots`
- `payroll_period_locks`

## Services principaux

- `PayrollCalculationService`
- `SbiCalculationService`
- `SbsCalculationService`
- `TaxCalculationService`
- `RicfCalculationService`
- `CnpsCalculationService`
- `PayrollSlipGenerationService`
- `PayrollVersionResolverService`
- `PayrollAuditService`

## Regle d'or de maintenance

Toute evolution reglementaire doit suivre ce cycle:

1. Cloner la version active.
2. Donner un nouveau code, par exemple `PAYROLL_CI_2025`.
3. Modifier les parametres de cette nouvelle version.
4. Tester les bulletins sur une periode pilote.
5. Activer la nouvelle version quand elle devient applicable.

Ne jamais modifier directement une ancienne version deja utilisee par des bulletins valides.

## Documentation liee

- [Documentation technique](TECHNIQUE.md)
- [Guide utilisateur](UTILISATEUR.md)
- [README module](README.md)
