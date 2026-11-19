# Checklist d'implémentation RH

- [x] Refonte professionnelle du PDF des demandes RH.
- [x] Zones Demandeur / RH / DG et DRH lorsque le circuit le requiert.
- [x] Infrastructure Passkey de signature avec preuve à usage unique.
- [x] Contexte de signature pris en charge côté serveur.
- [x] Demandes RH liées au contexte Passkey `rh_request:{id}:{role}`.
- [x] Contrats salariés liés au contexte Passkey `employee_contract:{id}:{role}`.
- [x] Paie liée au contexte Passkey `rh_payroll:{period}:{role}`.
- [x] Contexte dérivé conservé pour les anciens formulaires de signature.
- [x] Protection CSRF des actions de validation et diffusion de la paie.
- [x] Pré-contrôle de paie avant visa RH.
- [x] Contrat actif obligatoire avant validation définitive de la paie.
- [x] Coordonnées bancaires salarié normalisées et obligatoires avant visa RH.
- [x] Prestataires et consultants exclus du contrat salarié, de la paie et des soldes de congés.
- [x] Workflow mensuel RH -> DG -> diffusion.
- [x] Journal d'envoi anti-doublon et relance des échecs.
- [x] Export collectif des bulletins, un salarié par page.
- [x] Ordre de virement séparé avec banque, compte/IBAN, montants, totaux et visas RH/DG.
- [x] Fiches entreprises multi-entités.
- [x] Sélecteur d'entreprise dans l'éditeur de contrat salarié.
- [x] Snapshot immuable de l'entreprise rattaché au contrat.
- [x] Clauses par défaut adaptées au type CDI / CDD / Stage / Vacataire, puis librement modifiables avant signature.
- [x] PDF contrat salarié utilisant le snapshot entreprise et affichant RH / DG / Employé.
- [x] Signatures professionnelles visuelles intégrées au PDF lorsque disponibles, l'autorisation restant assurée par Passkey.
- [x] Archivage du PDF généré dans le dossier RH et mise à jour du chemin du contrat.
- [x] Migration de reprise des demandes de recrutement en attente.
- [x] Migration de reprise des contrats actifs non finalisés.
- [x] Export pointage individuel par période déjà présent et conservé.
- [x] Export hebdomadaire jours en colonnes / heures en lignes déjà présent et conservé.
- [x] Panneau « Échéances des fractions de paiement » retiré du workflow des contrats prestataires sans supprimer le tableau financier principal.
- [x] Tests statiques RH étendus aux nouveaux invariants de sécurité et de périmètre métier.
- [ ] Exécuter les tests fonctionnels complets avec une base de staging contenant de vrais pointages, bulletins, comptes bancaires, utilisateurs Passkey et serveur SMTP.

Le dernier point est volontairement un test d'intégration environnemental : il ne peut être déclaré terminé sur la seule base d'une modification du dépôt Git.
