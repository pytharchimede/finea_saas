# Workflow RH — contrats, paie et Passkey

Cette évolution est développée sur `feature/rh-workflow-paie-passkey`.

## Principes

- une signature est une action explicite ;
- une Passkey/WebAuthn confirme l'identité du signataire avant le visa ;
- la signature visuelle reste distincte de la preuve WebAuthn ;
- la paie mensuelle suit `Brouillon -> RH signé -> DG signé -> Diffusé` ;
- aucune diffusion automatique de bulletin n'intervient avant le visa DG ;
- l'envoi est idempotent par période et salarié afin d'éviter les doublons ;
- les anomalies bloquantes doivent être corrigées avant le visa RH ;
- les documents RH utilisent une fiche entreprise plutôt que des données institutionnelles dupliquées autant que possible.

## Documents

Le PDF d'une demande RH est structuré comme un document administratif, avec titre, référence, sections métier et circuit de signatures. Les bulletins collectifs démarrent chaque salarié sur une nouvelle page. L'ordre de virement est un document distinct, récapitulatif, portant les visas RH et DG.

## Diffusion mensuelle

Après signature DG, FINEA prépare pour chaque salarié son bulletin individuel et son relevé de pointage, puis les envoie à son adresse professionnelle. `rh_payroll_distribution_log` garde le résultat de chaque tentative et empêche un second envoi d'un bulletin déjà transmis. Les échecs peuvent être relancés sans renvoyer les messages déjà délivrés.

## Reprise de l'existant

La migration `database/migrations/20260910_rh_workflow_paie_passkey.sql` remet dans le circuit les demandes de recrutement soumises sans étape d'approbation et les contrats actifs encore non finalisés, sans supprimer leurs données ou signatures existantes.
