<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/point_financier_filters.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';
require_once __DIR__ . '/../../services/FebBeneficiarySyncService.php';

function pf_list_base_public_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
}

function pf_list_resolve_photo_url(array $row): ?string
{
    // La source visuelle enrichie est prioritaire : fournisseur (photo/logo),
    // puis personnel, puis seulement la valeur historique de la fiche.
    $visual = trim((string)($row['beneficiary_visual_path'] ?? ''));
    if ($visual !== '') {
        $normalized = ltrim(str_replace('\\', '/', $visual), '/');
        $absolute = dirname(__DIR__, 2) . '/' . $normalized;
        if (is_file($absolute)) {
            return pf_list_base_public_url() . '/../' . str_replace('%2F', '/', rawurlencode($normalized));
        }

        $basename = basename($normalized);
        $visualCandidates = [
            __DIR__ . '/../uploads/photo/' . $basename => pf_list_base_public_url() . '/uploads/photo/' . rawurlencode($basename),
            dirname(__DIR__, 2) . '/uploads/personnel/' . $basename => pf_list_base_public_url() . '/../uploads/personnel/' . rawurlencode($basename),
        ];
        foreach ($visualCandidates as $absolutePath => $publicUrl) {
            if (is_file($absolutePath)) return $publicUrl;
        }
    }

    $photoFile = trim((string)($row['beneficiary_photo'] ?? $row['photo_beneficiaire'] ?? ''));
    if ($photoFile === '') return null;
    $photoFile = basename(str_replace('\\', '/', $photoFile));
    $candidates = [
        __DIR__ . '/../uploads/photo/' . $photoFile => pf_list_base_public_url() . '/uploads/photo/' . rawurlencode($photoFile),
        dirname(__DIR__, 2) . '/uploads/personnel/' . $photoFile => pf_list_base_public_url() . '/../uploads/personnel/' . rawurlencode($photoFile),
    ];
    foreach ($candidates as $absolutePath => $publicUrl) {
        if (is_file($absolutePath)) return $publicUrl;
    }
    return null;
}

function pf_list_build_workflow_timeline(array $row): array
{
    $timeline = [];
    $approuve = (int)($row['approuve'] ?? 0);
    $conforme = (int)($row['conforme'] ?? 0);
    $etatFiche = (int)($row['etat_fiche'] ?? 0);
    $decaisse = (int)($row['decaisse'] ?? 0);
    $isRefused = ($approuve === 2 || $etatFiche === 2);
    $refusedDate = pf_clean_date_value($row['date_refuse'] ?? null)
        ?? ($isRefused ? pf_clean_date_value($row['date_approbation'] ?? null) : null);
    $dateMap = [
        ['label'=>'Créée','date'=>$row['date_creat_fiche'] ?? null,'tone'=>'slate','show'=>true],
        ['label'=>'Certifiée conforme','date'=>$row['date_conforme'] ?? null,'tone'=>'blue','show'=>$conforme === 1],
        ['label'=>'Approuvée','date'=>$row['date_approbation'] ?? null,'tone'=>'amber','show'=>$approuve === 1],
        ['label'=>'Validée achat','date'=>$row['date_valid'] ?? null,'tone'=>'indigo','show'=>!$isRefused && ($etatFiche === 1 || $decaisse === 1)],
        ['label'=>'Décaissée','date'=>$row['date_decaisse'] ?? null,'tone'=>'green','show'=>$decaisse === 1],
        ['label'=>'Refusée','date'=>$refusedDate,'tone'=>'red','show'=>$isRefused],
    ];
    foreach ($dateMap as $entry) {
        if (empty($entry['show'])) continue;
        $value = pf_clean_date_value($entry['date'] ?? null);
        if ($value === null) continue;
        $timeline[] = ['label'=>$entry['label'],'date'=>$value,'tone'=>$entry['tone']];
    }
    return $timeline;
}

try {
    $pdo = Database::getConnection();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'DB']);
    exit;
}
try { FebBeneficiarySyncService::syncApproved($pdo); } catch (Throwable $e) { error_log('FEB beneficiary synchronization: '.$e->getMessage()); }

try {
    $tableChantier = pf_detect_chantier_table($pdo);
} catch (Throwable $e2) {
    echo json_encode(['ok'=>false,'error'=>'TABLE_CHANTIER_ABSENTE']);
    exit;
}

$filters = [
    'beneficiaire'=>$_GET['beneficiaire'] ?? '',
    'exact_date'=>$_GET['exact_date'] ?? null,
    'from'=>$_GET['from'] ?? null,
    'to'=>$_GET['to'] ?? null,
    'chantier'=>$_GET['chantier'] ?? null,
    'debourse'=>$_GET['debourse'] ?? null,
    'num_fiche'=>$_GET['num_fiche'] ?? null,
    'detail_fiche'=>$_GET['detail_fiche'] ?? null,
];
$statusMeta = pf_status_meta($_GET['etat_demande'] ?? null);
pf_try_repair_chantier_debourse_links($pdo, $tableChantier, $filters['chantier'] ?? null);

$where = [];
$params = [];
$sqlBase = "FROM fiche_decaissement f
LEFT JOIN $tableChantier c ON c.id=f.chantier_id
LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id
LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id
LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
LEFT JOIN personnel p ON p.id_personnel_soignant=f.personnel_id
LEFT JOIN fournisseur_banamur fb ON fb.id_fournisseur=f.fournisseur_id";
$where[] = $statusMeta['condition'];
pf_apply_common_filters($where, $params, $filters, $statusMeta['date_expr'], $tableChantier);

$numFiche = trim((string)($filters['num_fiche'] ?? ''));
if ($numFiche !== '') {
    $where[] = 'LOWER(COALESCE(f.num_fiche, "")) LIKE LOWER(:num_fiche_search)';
    $params['num_fiche_search'] = '%' . $numFiche . '%';
}
$detailFiche = trim((string)($filters['detail_fiche'] ?? ''));
if ($detailFiche !== '') {
    $where[] = '(
        LOWER(COALESCE(f.designation_fiche, "")) LIKE LOWER(:detail_fiche_search)
        OR LOWER(COALESCE(f.precision_fiche, "")) LIKE LOWER(:detail_fiche_search)
        OR LOWER(COALESCE(f.details_demande, "")) LIKE LOWER(:detail_fiche_search)
        OR LOWER(COALESCE(ldb.designation, "")) LIKE LOWER(:detail_fiche_search)
        OR LOWER(COALESCE(ld.designation, "")) LIKE LOWER(:detail_fiche_search)
    )';
    $params['detail_fiche_search'] = '%' . $detailFiche . '%';
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

try {
    // Un fournisseur explicitement rattaché à la fiche est prioritaire sur toute autre identité.
    $beneficiaryNameSql = "CASE WHEN f.fournisseur_id IS NOT NULL AND f.fournisseur_id > 0 THEN COALESCE(NULLIF(TRIM(fb.nom_commercial),''), NULLIF(TRIM(fb.raison_sociale),''), NULLIF(TRIM(CONCAT_WS(' ',fb.person_first_name,fb.person_last_name)),''), NULLIF(TRIM(f.beficiaire_fiche),''), '—') WHEN f.personnel_id IS NOT NULL AND f.personnel_id > 0 THEN COALESCE(NULLIF(TRIM(p.nom_personnel_soignant),''), NULLIF(TRIM(f.beficiaire_fiche),''), '—') ELSE COALESCE(NULLIF(TRIM(f.beficiaire_fiche),''), '—') END";
    $beneficiaryPhoneSql = "CASE WHEN f.fournisseur_id IS NOT NULL AND f.fournisseur_id > 0 THEN COALESCE(NULLIF(TRIM(fb.telephone),''), NULLIF(TRIM(f.tel_beneficiaire_fiche),'')) WHEN f.personnel_id IS NOT NULL AND f.personnel_id > 0 THEN COALESCE(NULLIF(TRIM(p.tel_personnel_soignant),''), NULLIF(TRIM(f.tel_beneficiaire_fiche),'')) ELSE NULLIF(TRIM(f.tel_beneficiaire_fiche),'') END";
    $beneficiaryPhotoSql = "CASE WHEN f.personnel_id IS NOT NULL AND f.personnel_id > 0 THEN COALESCE(NULLIF(TRIM(p.photo_personnel_soignant),''), NULLIF(TRIM(f.photo_beneficiaire),'')) ELSE NULLIF(TRIM(f.photo_beneficiaire),'') END";
    $beneficiaryVisualSql = "CASE WHEN f.fournisseur_id IS NOT NULL AND f.fournisseur_id > 0 THEN COALESCE(NULLIF(TRIM(fb.photo_path),''), NULLIF(TRIM(fb.logo_path),'')) WHEN f.personnel_id IS NOT NULL AND f.personnel_id > 0 THEN NULLIF(TRIM(p.photo_personnel_soignant),'') ELSE NULLIF(TRIM(f.photo_beneficiaire),'') END";

    $sqlData = 'SELECT f.id_fiche AS id_fiche, f.num_fiche, f.fournisseur_id, f.personnel_id, '
        . $beneficiaryNameSql . ' AS beficiaire_fiche, '
        . $beneficiaryPhoneSql . ' AS tel_beneficiaire_fiche, '
        . $beneficiaryPhotoSql . ' AS beneficiary_photo, '
        . $beneficiaryVisualSql . ' AS beneficiary_visual_path, '
        . 'f.montant_fiche, f.date_creat_fiche, f.date_conforme, f.date_approbation, f.date_decaisse, f.date_refuse, f.date_valid, f.designation_fiche AS designation, f.precision_fiche AS `precision`, f.details_demande, f.photo_beneficiaire, f.conforme, f.approuve, f.etat_fiche, f.decaisse, f.ligne_debourse_id, c.code AS chantier_code, c.titre AS chantier_titre, db.id AS debourse_id, ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ld.designation AS devis_designation ' . $sqlBase . $whereSql . ' ORDER BY ' . $statusMeta['date_expr'] . ' DESC LIMIT 200';
    $st = $pdo->prepare($sqlData);
    $st->execute($params);
    $items = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        // Même résolution canonique que la liste d'approbation : fournisseur > personnel > historique.
        $item = enrich_fiche_labels($item);
        $status = pf_format_status($item);
        $item['status'] = $status['label'];
        $item['badge'] = $status['badge'];
        $item['status_key'] = $status['key'];
        $item['reference_label'] = $status['date_label'];
        $item['reference_date'] = pf_reference_date($item);
        $item['motif_refus'] = pf_extract_refus_reason($item);
        $item['mode'] = !empty($item['chantier_code']) ? $item['chantier_code'] : (!empty($item['debourse_id']) ? ('Déboursé #' . $item['debourse_id']) : 'Hors chantier');
        $item['photo_url'] = pf_list_resolve_photo_url($item);
        $item['details_display'] = trim((string)($item['details_demande'] ?? ''));
        $item['workflow_timeline'] = pf_list_build_workflow_timeline($item);
    }
    unset($item);
    $sqlTotal = 'SELECT COUNT(*) nb, COALESCE(SUM(f.montant_fiche),0) mt ' . $sqlBase . $whereSql;
    $st2 = $pdo->prepare($sqlTotal);
    $st2->execute($params);
    $agg = $st2->fetch(PDO::FETCH_ASSOC);
    echo json_encode([
        'ok'=>true,
        'count'=>(int)$agg['nb'],
        'total'=>(float)$agg['mt'],
        'status_filter'=>$statusMeta['key'],
        'status_label'=>$statusMeta['label'],
        'items'=>$items,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
