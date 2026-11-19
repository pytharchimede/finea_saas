# Moteur de paie FINEA - Cote d'Ivoire

Le module `modules/payroll` introduit un moteur reglementaire versionne pour la paie CI. Les services ne portent pas les taux, plafonds ou baremes en dur: ils les chargent depuis les tables `payroll_*` liees a `payroll_rule_versions`.

## Guides

- [Payroll.md](Payroll.md): point d'entree et principes de gouvernance.
- [TECHNIQUE.md](TECHNIQUE.md): documentation developpeur et administrateur ERP.
- [UTILISATEUR.md](UTILISATEUR.md): guide RH/paie.

## Installation

1. Executer `modules/payroll/migrations/2026_05_05_create_payroll_engine_ci.sql`.
2. Verifier la version active `PAYROLL_CI_2024`.
3. Consulter `modules/payroll/views/admin.php` pour controler rubriques, tranches, cotisations et transports.

## Architecture

Flux respecte:

`Controller -> Service -> Repository -> Database`

Services metier:

- `PayrollCalculationService`
- `SbiCalculationService`
- `SbsCalculationService`
- `TaxCalculationService`
- `RicfCalculationService`
- `CnpsCalculationService`
- `PayrollSlipGenerationService`
- `PayrollVersionResolverService`
- `PayrollAuditService`

## Versionnement

Une reforme ne modifie pas une version existante. Creer une nouvelle ligne dans `payroll_rule_versions`, puis dupliquer et adapter les rubriques, tranches, cotisations, parametres, transports et parts familiales.

## Recalcul

Un bulletin conserve `rule_version_id` et une trace complete dans `payroll_calculation_snapshots`. Un recalcul doit creer un nouveau bulletin ou une nouvelle revision auditee, afin de preserver l'historique.

## Audit

La generation appelle `AuditTrail` avec l'evenement `payroll_slip_generated`. Les changements de parametrage doivent etre journalises avec le meme service d'audit.

## Exemple de calcul

Pour un SBI de 450 000 FCFA avec abattement parametre a 20%, la base imposable est 360 000 FCFA. L'impot est calcule marginalement tranche par tranche, puis la RICF est deduite sans rendre l'impot negatif.
