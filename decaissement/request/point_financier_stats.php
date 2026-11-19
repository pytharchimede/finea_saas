<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/point_financier_filters.php';
try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB']);
    exit;
}
try {
    $tableChantier = pf_detect_chantier_table($pdo);
} catch (Throwable $e2) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'TABLE_CHANTIER_ABSENTE']);
    exit;
}

$from = $_GET['from'] ?? null;
$to   = $_GET['to'] ?? null;
$chantier = $_GET['chantier'] ?? null; // id ou code
$debourse = $_GET['debourse'] ?? null;
$statusMeta = pf_status_meta($_GET['etat_demande'] ?? null);
$filters = [
    'beneficiaire' => $_GET['beneficiaire'] ?? '',
    'exact_date' => $_GET['exact_date'] ?? null,
    'from' => $from,
    'to' => $to,
    'chantier' => $chantier,
    'debourse' => $debourse,
    'num_fiche' => $_GET['num_fiche'] ?? null,
    'detail_fiche' => $_GET['detail_fiche'] ?? null,
];

function pf_apply_fiche_search_filters(array &$where, array &$params, array $filters, string $suffix = ''): void
{
    $suffix = preg_replace('/[^a-zA-Z0-9_]/', '', $suffix);
    $numKey = 'num_fiche_search' . $suffix;
    $detailKey = 'detail_fiche_search' . $suffix;

    $numFiche = trim((string)($filters['num_fiche'] ?? ''));
    if ($numFiche !== '') {
        $where[] = 'LOWER(COALESCE(f.num_fiche, "")) LIKE LOWER(:' . $numKey . ')';
        $params[$numKey] = '%' . $numFiche . '%';
    }

    $detailFiche = trim((string)($filters['detail_fiche'] ?? ''));
    if ($detailFiche !== '') {
        $where[] = '(
            LOWER(COALESCE(f.designation_fiche, "")) LIKE LOWER(:' . $detailKey . ')
            OR LOWER(COALESCE(f.precision_fiche, "")) LIKE LOWER(:' . $detailKey . ')
            OR LOWER(COALESCE(f.details_demande, "")) LIKE LOWER(:' . $detailKey . ')
            OR LOWER(COALESCE(ldb.designation, "")) LIKE LOWER(:' . $detailKey . ')
        )';
        $params[$detailKey] = '%' . $detailFiche . '%';
    }
}

try {
    pf_try_repair_chantier_debourse_links($pdo, $tableChantier, $chantier);

    $dateCreateExpr = pf_sql_safe_date_expr('f.date_creat_fiche');
    $dateDecaisseExpr = pf_sql_safe_date_expr('f.date_decaisse');

    $params = [];
    $wherePrimary = [$statusMeta['condition']];
    pf_apply_common_filters($wherePrimary, $params, $filters, $statusMeta['date_expr'], $tableChantier);
    pf_apply_fiche_search_filters($wherePrimary, $params, $filters, '_primary');

    // Prévisions entrées (devis) – total des déboursés filtrés par chantier si spécifié
    $wherePrev = [];
    $paramsPrev = [];
    $chantierStr = trim((string)$chantier);
    if ($chantierStr !== '') {
        if (ctype_digit($chantierStr)) {
            $wherePrev[] = 'c.id = :chantier_id_prev';
            $paramsPrev['chantier_id_prev'] = (int)$chantierStr;
        } else {
            $wherePrev[] = 'c.code = :chantier_code_prev';
            $paramsPrev['chantier_code_prev'] = $chantierStr;
        }
    }
    if (!empty($debourse)) {
        $wherePrev[] = 'd.id = :debourse_prev';
        $paramsPrev['debourse_prev'] = $debourse;
    }

    $sqlDevis = 'SELECT SUM(d.montant_debourse) total_prevu FROM debourse_banamur d';
    if ($chantierStr !== '' || !empty($debourse)) {
        $sqlDevis .= " LEFT JOIN devis_banamur dv ON dv.id = d.devis_id LEFT JOIN $tableChantier c ON c.devis_id = dv.id WHERE " . implode(' AND ', $wherePrev);
    }
    $stPrev = $pdo->prepare($sqlDevis);
    $stPrev->execute($paramsPrev);
    $prevu = (float)$stPrev->fetchColumn();

    // Volume principal selon le filtre d'état choisi pour les graphiques
    $sqlSorties = "SELECT COUNT(*) nb_fiches_principales, COALESCE(SUM(f.montant_fiche),0) total_principal FROM fiche_decaissement f LEFT JOIN $tableChantier c ON c.id = f.chantier_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id WHERE " . implode(' AND ', $wherePrimary);
    $st = $pdo->prepare($sqlSorties);
    $st->execute($params);
    $rowSort = $st->fetch(PDO::FETCH_ASSOC);
    $totalPrincipal = (float)($rowSort['total_principal'] ?? 0);

    // Décaissements réels pour les KPI de synthèse
    $paramsDec = [];
    $whereDec = ['f.decaisse = 1'];
    pf_apply_common_filters($whereDec, $paramsDec, $filters, "COALESCE($dateDecaisseExpr, $dateCreateExpr)", $tableChantier);
    pf_apply_fiche_search_filters($whereDec, $paramsDec, $filters, '_dec');
    $sqlDec = "SELECT COUNT(*) nb_fiches_decaissees, COALESCE(SUM(f.montant_fiche),0) montant_decaissements FROM fiche_decaissement f LEFT JOIN $tableChantier c ON c.id=f.chantier_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id WHERE " . implode(' AND ', $whereDec);
    $stDec = $pdo->prepare($sqlDec);
    $stDec->execute($paramsDec);
    $rowDec = $stDec->fetch(PDO::FETCH_ASSOC) ?: [];
    $montantDecaissements = (float)($rowDec['montant_decaissements'] ?? 0);
    $nbFichesDecaissees = (int)($rowDec['nb_fiches_decaissees'] ?? 0);

    // En attente globale pour la synthèse haute, alignée sur la carte du bas
    $paramsAtt = [];
    $whereAtt = ['f.decaisse = 0', 'COALESCE(f.approuve, 0) <> 2', 'COALESCE(f.etat_fiche, 0) <> 2'];
    pf_apply_common_filters($whereAtt, $paramsAtt, $filters, 'f.date_creat_fiche', $tableChantier);
    pf_apply_fiche_search_filters($whereAtt, $paramsAtt, $filters, '_att');
    $sqlAtt = "SELECT COUNT(*) nb_fiches_attente, COALESCE(SUM(f.montant_fiche),0) montant_attente FROM fiche_decaissement f LEFT JOIN $tableChantier c ON c.id=f.chantier_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id WHERE " . implode(' AND ', $whereAtt);
    $stA = $pdo->prepare($sqlAtt);
    $stA->execute($paramsAtt);
    $rowAtt = $stA->fetch(PDO::FETCH_ASSOC);
    $montantAttente = (float)($rowAtt['montant_attente'] ?? 0);
    $nbFichesAtt = (int)($rowAtt['nb_fiches_attente'] ?? 0);

    $paramsRef = [];
    $whereRef = ['((COALESCE(f.approuve, 0) = 2) OR (COALESCE(f.etat_fiche, 0) = 2))'];
    pf_apply_common_filters($whereRef, $paramsRef, $filters, 'f.date_creat_fiche', $tableChantier);
    pf_apply_fiche_search_filters($whereRef, $paramsRef, $filters, '_ref');
    $sqlRef = "SELECT COUNT(*) nb_fiches_refusees, COALESCE(SUM(f.montant_fiche),0) montant_refuse FROM fiche_decaissement f LEFT JOIN $tableChantier c ON c.id=f.chantier_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id WHERE " . implode(' AND ', $whereRef);
    $stRef = $pdo->prepare($sqlRef);
    $stRef->execute($paramsRef);
    $rowRef = $stRef->fetch(PDO::FETCH_ASSOC);
    $montantRefuse = (float)($rowRef['montant_refuse'] ?? 0);
    $nbFichesRef = (int)($rowRef['nb_fiches_refusees'] ?? 0);

    $paramsOverview = [];
    $whereOverview = [];
    pf_apply_common_filters($whereOverview, $paramsOverview, $filters, 'f.date_creat_fiche', $tableChantier);
    pf_apply_fiche_search_filters($whereOverview, $paramsOverview, $filters, '_overview');
    $sqlOverview = "SELECT
            COUNT(*) total_fiches,
            COUNT(*) nb_creees,
            COALESCE(SUM(f.montant_fiche),0) montant_creees,
            SUM(CASE WHEN (f.decaisse = 0 AND COALESCE(f.approuve, 0) <> 2 AND COALESCE(f.etat_fiche, 0) <> 2) THEN 1 ELSE 0 END) nb_en_attente,
            SUM(CASE WHEN (f.decaisse = 0 AND COALESCE(f.conforme, 0) = 0 AND COALESCE(f.approuve, 0) = 0 AND COALESCE(f.etat_fiche, 0) <> 2) THEN 1 ELSE 0 END) nb_conformite,
            SUM(CASE WHEN (f.decaisse = 0 AND COALESCE(f.conforme, 0) = 1 AND COALESCE(f.approuve, 0) = 0 AND COALESCE(f.etat_fiche, 0) <> 2) THEN 1 ELSE 0 END) nb_approbation,
            SUM(CASE WHEN (f.decaisse = 0 AND COALESCE(f.conforme, 0) = 1 AND COALESCE(f.approuve, 0) = 1 AND COALESCE(f.etat_fiche, 0) = 0) THEN 1 ELSE 0 END) nb_validation_achat,
            SUM(CASE WHEN (f.decaisse = 0 AND COALESCE(f.conforme, 0) = 1 AND COALESCE(f.approuve, 0) = 1 AND COALESCE(f.etat_fiche, 0) = 1) THEN 1 ELSE 0 END) nb_tresorerie,
            SUM(CASE WHEN ((COALESCE(f.approuve, 0) = 2) OR (COALESCE(f.etat_fiche, 0) = 2)) THEN 1 ELSE 0 END) nb_refusees,
            SUM(CASE WHEN f.decaisse = 1 THEN 1 ELSE 0 END) nb_decaissees
        FROM fiche_decaissement f
        LEFT JOIN $tableChantier c ON c.id = f.chantier_id
        LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
        LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id";
    if (!empty($whereOverview)) {
        $sqlOverview .= ' WHERE ' . implode(' AND ', $whereOverview);
    }
    $stOverview = $pdo->prepare($sqlOverview);
    $stOverview->execute($paramsOverview);
    $overview = $stOverview->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalFiches = (int)($overview['total_fiches'] ?? 0);
    $nbCreees = (int)($overview['nb_creees'] ?? 0);
    $montantCreees = (float)($overview['montant_creees'] ?? 0);
    $nbEnAttenteOverview = (int)($overview['nb_en_attente'] ?? 0);
    $nbRefusees = (int)($overview['nb_refusees'] ?? 0);
    $nbConformiteOverview = (int)($overview['nb_conformite'] ?? 0);
    $nbApprobationOverview = (int)($overview['nb_approbation'] ?? 0);
    $nbValidationAchatOverview = (int)($overview['nb_validation_achat'] ?? 0);
    $nbTresorerieOverview = (int)($overview['nb_tresorerie'] ?? 0);
    $nbDecaissees = (int)($overview['nb_decaissees'] ?? 0);

    $percent = static function (int $count, int $total): float {
        if ($total <= 0) {
            return 0.0;
        }

        return round(($count / $total) * 100, 1);
    };

    // Groupes par jour selon l'état demandé
    $sqlDay = "SELECT DATE(" . $statusMeta['date_expr'] . ") d, SUM(f.montant_fiche) montant FROM fiche_decaissement f LEFT JOIN $tableChantier c ON c.id=f.chantier_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id WHERE " . implode(' AND ', $wherePrimary);
    $sqlDay .= ' GROUP BY DATE(' . $statusMeta['date_expr'] . ') ORDER BY d ASC';
    $st = $pdo->prepare($sqlDay);
    $st->execute($params);
    $byDay = $st->fetchAll(PDO::FETCH_ASSOC);

    // Par chantier selon l'état demandé
    $sqlChant = "SELECT c.code code, c.titre titre, SUM(f.montant_fiche) montant FROM fiche_decaissement f LEFT JOIN $tableChantier c ON c.id=f.chantier_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id WHERE " . implode(' AND ', $wherePrimary);
    $sqlChant .= ' GROUP BY c.id ORDER BY montant DESC LIMIT 20';
    $st = $pdo->prepare($sqlChant);
    $st->execute($params);
    $byChant = $st->fetchAll(PDO::FETCH_ASSOC);

    // Ratio utilisation
    $ratio = $prevu > 0 ? round(($montantDecaissements / $prevu) * 100, 2) : null;

    echo json_encode([
        'ok' => true,
        'interval' => ['exact_date' => $_GET['exact_date'] ?? null, 'from' => $from, 'to' => $to],
        'status_filter' => $statusMeta['key'],
        'primary_label' => $statusMeta['label'],
        'date_label' => $statusMeta['date_label'],
        'prevu_total' => $prevu,
        'sorties_total' => $totalPrincipal,
        'montant_decaissements' => $montantDecaissements,
        'montant_demandes_total' => $montantCreees,
        'montant_attente' => $montantAttente,
        'montant_refuse' => $montantRefuse,
        'total_fiches' => $totalFiches,
        'nb_fiches_dec' => $nbFichesDecaissees,
        'nb_fiches_demandes' => $nbCreees,
        'nb_fiches_attente' => $nbFichesAtt,
        'nb_fiches_refusees' => $nbFichesRef,
        'ratio_utilisation' => $ratio,
        'empty' => ($totalPrincipal == 0 && $montantAttente == 0 && $montantDecaissements == 0),
        'workflow_overview' => [
            'scope_label' => 'Fiches créées',
            'total_fiches' => $totalFiches,
            'nb_creees' => $nbCreees,
            'montant_creees' => $montantCreees,
            'nb_en_attente' => $nbEnAttenteOverview,
            'nb_conformite' => $nbConformiteOverview,
            'nb_approbation' => $nbApprobationOverview,
            'nb_validation_achat' => $nbValidationAchatOverview,
            'nb_tresorerie' => $nbTresorerieOverview,
            'nb_refusees' => $nbRefusees,
            'nb_decaissees' => $nbDecaissees,
            'pct_creees' => $percent($nbCreees, $totalFiches),
            'pct_en_attente' => $percent($nbEnAttenteOverview, $totalFiches),
            'pct_conformite' => $percent($nbConformiteOverview, $totalFiches),
            'pct_approbation' => $percent($nbApprobationOverview, $totalFiches),
            'pct_validation_achat' => $percent($nbValidationAchatOverview, $totalFiches),
            'pct_tresorerie' => $percent($nbTresorerieOverview, $totalFiches),
            'pct_refusees' => $percent($nbRefusees, $totalFiches),
            'pct_decaissees' => $percent($nbDecaissees, $totalFiches),
        ],
        'par_jour' => $byDay,
        'par_chantier' => $byChant
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
