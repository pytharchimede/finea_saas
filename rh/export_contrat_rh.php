<?php
/**
 * Point d'entrée historique conservé pour rétrocompatibilité.
 *
 * La génération est désormais centralisée dans l'export salarié moderne afin
 * que les anciens liens FINEA bénéficient aussi du snapshot entreprise et du
 * circuit de signatures RH -> DG -> Employé.
 */
require __DIR__ . '/export_contrat_employe_pdf.php';
