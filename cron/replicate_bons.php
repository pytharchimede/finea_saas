<?php
// Cron/Worker de rÃ©plication bidirectionnelle des bons d'essence entre PROJET (nouveau) et APP (legacy)
// Objectif: les deux tables doivent rester identiques. Toute validation (approved) ou reÃ§u (servi) doit se propager des deux cÃ´tÃ©s.
// Idempotent: s'appuie sur upsertByCodeBon, updateRecuByCodeBon et setApprovedByCode.

require_once __DIR__ . '/../controllers/FuelVoucherReplicationController.php';
require_once __DIR__ . '/../decaissement/model/FicheDecaissement.php';
require_once __DIR__ . '/../decaissement/model/Fiche.php';

function logLine($msg)
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

$replicationController = new FuelVoucherReplicationController();
$pdoProj = $replicationController->projectConnection();
$pdoApp = $replicationController->appConnection();
$proj = $replicationController->projectDemandes();
$app = $replicationController->appDemandes();

// Helpers
function resolveAppChantierIdFromProjetNum(PDO $pdoProj, PDO $pdoApp, string $numFiche): ?int
{
    $repository = new FuelVoucherReplicationRepository($pdoProj, $pdoApp);
    return $repository->resolveAppChantierIdFromProjetNum($numFiche);
}

function ensureLegacyFicheForDemande(array $dem, PDO $pdoProj, PDO $pdoApp, string $secur = 'SYNC_CRON'): array
{
    $result = ['created' => false, 'approved' => false, 'num' => null];
    $num = trim((string)($dem['num_fiche'] ?? ''));
    if ($num === '') return $result;

    $ficheLegacy = new Fiche($pdoApp);
    $exists = $ficheLegacy->getByNumFiche($num);
    if ($exists) {
        $result['num'] = $num;
        return $result; // dÃ©jÃ  prÃ©sent
    }

    // RÃ©cup infos cÃ´tÃ© PROJET fiche_decaissement pour complÃ©ter
    $fp = new FicheDecaissement($pdoProj);
    $ficheP = $fp->getByNum($num);

    $benef = $dem['nom_beneficiaire'] ?? ($ficheP['beficiaire_fiche'] ?? '');
    $montant = (float)($dem['montant'] ?? ($ficheP['montant_fiche'] ?? 0));
    $tel = $ficheP['tel_beneficiaire_fiche'] ?? '';
    $dateCreat = ($dem['date_demande'] ?? null) ? ($dem['date_demande'] . ' 00:00:00') : ($ficheP['date_creat_fiche'] ?? date('Y-m-d H:i:s'));
    $designation = $dem['motif'] ?? ($ficheP['designation_fiche'] ?? 'Carburant');
    $precision = $ficheP['precision_fiche'] ?? 'carburant';
    $chantierIdApp = resolveAppChantierIdFromProjetNum($pdoProj, $pdoApp, $num) ?? null;

    $data = [
        'beficiaire_fiche' => $benef,
        'montant_fiche' => $montant,
        'tel_beneficiaire_fiche' => $tel,
        'date_creat_fiche' => $dateCreat,
        'num_fiche' => $num,
        'affectation_id' => 1, // 1 = chantier
        'designation_fiche' => $designation,
        'num_piece' => '',
        'chantier_id' => $chantierIdApp,
        'precision_fiche' => $precision,
        'serv_bureau_banamur_id' => null,
        'code_autorisation_feb' => null,
        'photo_beneficiaire' => $ficheP['photo_beneficiaire'] ?? null,
        'cni_beneficiaire' => $ficheP['cni_beneficiaire'] ?? null,
        'entreprise' => $ficheP['entreprise'] ?? null,
    ];

    try {
        if ($ficheLegacy->insertFiche($data)) {
            $result['created'] = true;
            $result['num'] = $num;
            // Approbation immÃ©diate
            $ficheLegacy->approveFicheByNum($num, $secur);
            $result['approved'] = true;
        }
    } catch (\Throwable $e) {
        // Ã©chec silencieux
    }
    return $result;
}

// Charger les datasets et construire des index par code_bon
try {
    $projRows = $proj->getAll();
    $appRows  = $app->getAll();
} catch (\Throwable $e) {
    logLine('Erreur de lecture initiale: ' . $e->getMessage());
    $projRows = $projRows ?? [];
    $appRows = $appRows ?? [];
}

$projMap = [];
foreach ($projRows as $r) {
    $code = $r['code_bon'] ?? null;
    if ($code) $projMap[$code] = $r;
}
$appMap = [];
foreach ($appRows as $r) {
    $code = $r['code_bon'] ?? null;
    if ($code) $appMap[$code] = $r;
}

// 1) Upsert PROJET -> APP (core fields)
$pushProjToApp = 0;
try {
    foreach ($projMap as $code => $r) {
        if ($app->upsertByCodeBon($r)) $pushProjToApp++;
    }
    logLine("Push PROJET -> APP: $pushProjToApp enregistrements upsert");
} catch (\Throwable $e) {
    logLine('Erreur push PROJET->APP: ' . $e->getMessage());
}

// 2) Upsert APP -> PROJET pour les enregistrements absents cÃ´tÃ© PROJET (core fields)
$pushAppToProj = 0;
try {
    foreach ($appMap as $code => $r) {
        if (!isset($projMap[$code])) {
            if ($proj->upsertByCodeBon($r)) $pushAppToProj++;
        }
    }
    logLine("Push APP -> PROJET: $pushAppToProj enregistrements upsert");
} catch (\Throwable $e) {
    logLine('Erreur push APP->PROJET: ' . $e->getMessage());
}

// 2bis) Pour chaque demande APP, crÃ©er la fiche legacy (table fiche) si manquante, approuver d'avance
$legacyCreated = 0;
$legacyApproved = 0;
try {
    foreach ($appRows as $dem) {
        $res = ensureLegacyFicheForDemande($dem, $pdoProj, $pdoApp, 'SYNC_CRON');
        if ($res['created']) $legacyCreated++;
        if ($res['approved']) $legacyApproved++;
    }
    logLine("Fiches legacy crÃ©Ã©es: $legacyCreated, approuvÃ©es: $legacyApproved");
} catch (\Throwable $e) {
    logLine('Erreur crÃ©ation fiches legacy: ' . $e->getMessage());
}

// 3) Synchroniser les reÃ§us (num_recu_station/img_recu_station) dans les deux sens
$recuAppToProj = 0;
$recuProjToApp = 0;
try {
    // Union des codes
    $allCodes = array_unique(array_merge(array_keys($projMap), array_keys($appMap)));
    foreach ($allCodes as $code) {
        $p = $projMap[$code] ?? null;
        $a = $appMap[$code] ?? null;
        $aHasRecu = $a && (!empty($a['num_recu_station']) || !empty($a['img_recu_station']));
        $pHasRecu = $p && (!empty($p['num_recu_station']) || !empty($p['img_recu_station']));

        // APP => PROJET
        if ($aHasRecu && (!$pHasRecu)) {
            $proj->updateRecuByCodeBon($code, (string)($a['num_recu_station'] ?? ''), $a['img_recu_station'] ?? null);
            $recuAppToProj++;
        }
        // PROJET => APP (si PROJET a l'info mais pas APP)
        if ($pHasRecu && (!$aHasRecu)) {
            $app->updateRecuByCodeBon($code, (string)($p['num_recu_station'] ?? ''), $p['img_recu_station'] ?? null);
            $recuProjToApp++;
        }
    }
    logLine("ReÃ§us synchronisÃ©s APP=>PROJET: $recuAppToProj, PROJET=>APP: $recuProjToApp");
} catch (\Throwable $e) {
    logLine('Erreur sync reÃ§us: ' . $e->getMessage());
}

// 4) Synchroniser les validations (approved) dans les deux sens
$apprAppToProj = 0;
$apprProjToApp = 0;
try {
    $allCodes = array_unique(array_merge(array_keys($projMap), array_keys($appMap)));
    foreach ($allCodes as $code) {
        $p = $projMap[$code] ?? null;
        $a = $appMap[$code] ?? null;
        $aApproved = $a && (($a['validation_status'] ?? '') === 'approved');
        $pApproved = $p && (($p['validation_status'] ?? '') === 'approved');

        // APP => PROJET
        if ($aApproved && !$pApproved) {
            $proj->setApprovedByCode($code, $a['validated_by'] ?? 'APP_SYNC', $a['approver_phone'] ?? null);
            $apprAppToProj++;
        }
        // PROJET => APP
        if ($pApproved && !$aApproved) {
            $app->setApprovedByCode($code, $p['validated_by'] ?? 'PROJET_SYNC', $p['approver_phone'] ?? null);
            $apprProjToApp++;
        }
    }
    logLine("Approvals synchronisÃ©es APP=>PROJET: $apprAppToProj, PROJET=>APP: $apprProjToApp");
} catch (\Throwable $e) {
    logLine('Erreur sync approvals: ' . $e->getMessage());
}

logLine('TerminÃ©.');

