<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/point_financier_filters.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';
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

$listsOnly = !empty($_GET['lists_only']);

$chantier = $_GET['chantier'] ?? null; // id ou code
$debourse = $_GET['debourse'] ?? null; // id debourse_banamur
$from = $_GET['from'] ?? null;
$to = $_GET['to'] ?? null;
$statusMeta = pf_status_meta($_GET['etat_demande'] ?? null);
$filters = [
    'beneficiaire' => $_GET['beneficiaire'] ?? '',
    'exact_date' => $_GET['exact_date'] ?? null,
    'from' => $from,
    'to' => $to,
    'chantier' => $chantier,
    'debourse' => $debourse,
];

try {
    pf_try_repair_chantier_debourse_links($pdo, $tableChantier, $chantier);

    // 1. Liste chantiers light
    $chantiers = $pdo->query("SELECT id, code, titre FROM $tableChantier ORDER BY titre ASC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Si chantier fourni -> débourses
    $debourses = [];
    if ($chantier) {
        $chantierStr = trim((string)$chantier);
        $whereCh = '';
        $paramsCh = [];
        if (ctype_digit($chantierStr)) {
            $whereCh = 'c.id = :chantier_id';
            $paramsCh['chantier_id'] = (int)$chantierStr;
        } else {
            $whereCh = 'c.code = :chantier_code';
            $paramsCh['chantier_code'] = $chantierStr;
        }

        $sqlDeb = "SELECT DISTINCT db.id, ld.designation AS devis_designation, db.montant_debourse, db.date_debut, db.date_fin
                   FROM debourse_banamur db
                   LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
                   INNER JOIN $tableChantier c ON c.devis_id = db.devis_id
                   WHERE $whereCh
                   ORDER BY db.id DESC LIMIT 400";
        $st = $pdo->prepare($sqlDeb);
        $st->execute($paramsCh);
        $debourses = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // Mode léger: uniquement la liste des chantiers/débourses (pour alimenter les selects)
    if ($listsOnly) {
        echo json_encode([
            'ok' => true,
            'lists_only' => true,
            'chantiers' => $chantiers,
            'debourses' => $debourses,
        ]);
        exit;
    }

    // 3. Synthèse globale chantier (tous débourses) ou globale générale si aucun chantier
    $params = [];
    $where = [$statusMeta['condition']];
    pf_apply_common_filters($where, $params, $filters, $statusMeta['date_expr'], $tableChantier);
    $sqlBase = "FROM fiche_decaissement f
                LEFT JOIN $tableChantier c ON c.id=f.chantier_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id";

    $reqSynth = 'SELECT COUNT(*) nb_fiches, COALESCE(SUM(f.montant_fiche),0) total, MIN(' . $statusMeta['date_expr'] . ') debut, MAX(' . $statusMeta['date_expr'] . ') fin ' . $sqlBase . ' WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare($reqSynth);
    $st->execute($params);
    $synth = $st->fetch(PDO::FETCH_ASSOC);

    // 4. Groupes bénéficiaires
    $reqBenef = 'SELECT f.beficiaire_fiche benef, SUM(f.montant_fiche) montant, COUNT(*) nb ' . $sqlBase . ' WHERE ' . implode(' AND ', $where) . ' GROUP BY f.beficiaire_fiche ORDER BY montant DESC LIMIT 50';
    $st = $pdo->prepare($reqBenef);
    $st->execute($params);
    $beneficiaires = $st->fetchAll(PDO::FETCH_ASSOC);

    // 5. Trésorerie (validées achat, non décaissées) pour comparer le pipeline
    $paramsAtt = [];
    $whereAtt = ['f.decaisse=0', 'f.approuve=1', 'f.conforme=1', 'COALESCE(f.etat_fiche, 0) = 1'];
    pf_apply_common_filters($whereAtt, $paramsAtt, $filters, 'f.date_creat_fiche', $tableChantier);
    $reqAtt = 'SELECT COUNT(*) nb_attente, COALESCE(SUM(f.montant_fiche),0) montant_attente ' . $sqlBase . ' WHERE ' . implode(' AND ', $whereAtt);
    $st = $pdo->prepare($reqAtt);
    $st->execute($paramsAtt);
    $attente = $st->fetch(PDO::FETCH_ASSOC);

    // 6. Détail fiches (limité) pour export preview
    $reqFiches = 'SELECT f.num_fiche, f.beficiaire_fiche, f.montant_fiche, f.date_creat_fiche, f.date_decaisse, f.date_refuse, f.date_valid, f.designation_fiche, f.precision_fiche, f.details_demande, f.conforme, f.approuve, f.etat_fiche, f.decaisse, f.ligne_debourse_id, ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ld.designation AS devis_designation ' . $sqlBase . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $statusMeta['date_expr'] . ' DESC LIMIT 300';
    $st = $pdo->prepare($reqFiches);
    $st->execute($params);
    $fiches = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fiches as &$fiche) {
        $fiche = enrich_fiche_labels($fiche);
        $status = pf_format_status($fiche);
        $fiche['status'] = $status['label'];
        $fiche['status_key'] = $status['key'];
        $fiche['reference_label'] = $status['date_label'];
        $fiche['reference_date'] = pf_reference_date($fiche);
        $fiche['motif_refus'] = pf_extract_refus_reason($fiche);
    }
    unset($fiche);

    // 7. Regroupement par catégorie débourse (ligne_debourse)
    $reqCat = 'SELECT ldb.categorie, SUM(f.montant_fiche) montant ' . $sqlBase . ' WHERE ' . implode(' AND ', $where) . ' GROUP BY ldb.categorie ORDER BY montant DESC';
    $st = $pdo->prepare($reqCat);
    $st->execute($params);
    $parCategorie = $st->fetchAll(PDO::FETCH_ASSOC);

    // 8. Ratio vs montant_debourse agrégé (capacité prévisionnelle) si filtré par debourse
    $ratioDeb = null;
    $montantDebourseRef = null;
    if ($debourse) {
        $st = $pdo->prepare('SELECT montant_debourse FROM debourse_banamur WHERE id=?');
        $st->execute([$debourse]);
        $montantDebourseRef = (float)$st->fetchColumn();
        if ($montantDebourseRef > 0) {
            $ratioDeb = round((($synth['total'] ?? 0) / $montantDebourseRef) * 100, 2);
        }
    }

    // 9. Retour
    echo json_encode([
        'ok' => true,
        'filters' => ['chantier' => $chantier, 'debourse' => $debourse, 'exact_date' => $_GET['exact_date'] ?? null, 'from' => $from, 'to' => $to, 'etat_demande' => $statusMeta['key']],
        'status_filter' => $statusMeta['key'],
        'status_label' => $statusMeta['label'],
        'date_label' => $statusMeta['date_label'],
        'chantiers' => $chantiers,
        'debourses' => $debourses,
        'synthese' => $synth,
        'beneficiaires' => $beneficiaires,
        'attente' => $attente,
        'fiches' => $fiches,
        'par_categorie' => $parCategorie,
        'ratio_debourse' => $ratioDeb,
        'montant_debourse_ref' => $montantDebourseRef
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
