<?php

require_once __DIR__ . '/../model/FicheDecaissement.php';

function pf_detect_chantier_table(PDO $pdo): string
{
    $tableChantier = 'chantier_banamur';
    try {
        $pdo->query("SELECT 1 FROM chantier_banamur LIMIT 1");
    } catch (Throwable $e) {
        try {
            $pdo->query("SELECT 1 FROM chantioer_banamur LIMIT 1");
            $tableChantier = 'chantioer_banamur';
        } catch (Throwable $e2) {
            throw new RuntimeException('TABLE_CHANTIER_ABSENTE');
        }
    }

    return $tableChantier;
}

function pf_status_options(): array
{
    return [
        'decaissements' => 'Décaissements à traiter',
        'refus_demandes' => 'Refus demandes',
        'en_attente' => 'Conformité',
        'pre_achat' => 'Approbation',
        'valides_achat' => 'Validation Achat',
        'toutes' => 'Toutes les demandes',
    ];
}

function pf_hidden_status_options(): array
{
    return [
        'attente_globale' => 'En attente',
        'decaissees' => 'Décaissées',
    ];
}

function pf_sql_safe_date_expr(string $column): string
{
    return "(CASE
        WHEN $column IS NULL THEN NULL
        WHEN $column = '0000-00-00' THEN NULL
        WHEN $column = '0000-00-00 00:00:00' THEN NULL
        ELSE $column
    END)";
}

function pf_clean_date_value($value): ?string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return null;
    }

    return $value;
}

function pf_normalize_status(?string $status): string
{
    $status = trim((string)$status);
    $allowed = array_keys(array_merge(pf_status_options(), pf_hidden_status_options()));
    return in_array($status, $allowed, true) ? $status : 'decaissements';
}

function pf_status_meta(?string $status): array
{
    $status = pf_normalize_status($status);
    $dateCreateExpr = pf_sql_safe_date_expr('f.date_creat_fiche');
    $dateConformeExpr = pf_sql_safe_date_expr('f.date_conforme');
    $dateApprobExpr = pf_sql_safe_date_expr('f.date_approbation');
    $dateRefuseExpr = pf_sql_safe_date_expr('f.date_refuse');
    $dateValidExpr = pf_sql_safe_date_expr('f.date_valid');
    $dateDecaisseExpr = pf_sql_safe_date_expr('f.date_decaisse');

    switch ($status) {
        case 'attente_globale':
            return [
                'key' => $status,
                'label' => 'En attente',
                'condition' => '(f.decaisse = 0 AND COALESCE(f.approuve, 0) <> 2 AND COALESCE(f.etat_fiche, 0) <> 2)',
                'date_expr' => $dateCreateExpr,
                'date_label' => 'Date demande',
            ];
        case 'decaissees':
            return [
                'key' => $status,
                'label' => 'Décaissées',
                'condition' => 'f.decaisse = 1',
                'date_expr' => "COALESCE($dateDecaisseExpr, $dateCreateExpr)",
                'date_label' => 'Date décaissement',
            ];
        case 'refus_demandes':
            return [
                'key' => $status,
                'label' => 'Refus demandes',
                'condition' => '((COALESCE(f.approuve, 0) = 2) OR (COALESCE(f.etat_fiche, 0) = 2))',
                'date_expr' => "COALESCE($dateRefuseExpr, $dateCreateExpr)",
                'date_label' => 'Date refus',
            ];
        case 'en_attente':
            return [
                'key' => $status,
                'label' => 'Conformité',
                'condition' => '(f.decaisse = 0 AND COALESCE(f.conforme, 0) = 0 AND COALESCE(f.approuve, 0) = 0 AND COALESCE(f.etat_fiche, 0) <> 2)',
                'date_expr' => $dateCreateExpr,
                'date_label' => 'Date demande',
            ];
        case 'pre_achat':
            return [
                'key' => $status,
                'label' => 'Approbation',
                'condition' => '(f.decaisse = 0 AND COALESCE(f.conforme, 0) = 1 AND COALESCE(f.approuve, 0) = 0 AND COALESCE(f.etat_fiche, 0) <> 2)',
                'date_expr' => "COALESCE($dateConformeExpr, $dateCreateExpr)",
                'date_label' => 'Date conformité',
            ];
        case 'valides_achat':
            return [
                'key' => $status,
                'label' => 'Validation Achat',
                'condition' => '(f.decaisse = 0 AND COALESCE(f.conforme, 0) = 1 AND COALESCE(f.approuve, 0) = 1 AND COALESCE(f.etat_fiche, 0) = 1)',
                'date_expr' => "COALESCE($dateApprobExpr, $dateConformeExpr, $dateCreateExpr)",
                'date_label' => 'Date approbation',
            ];
        case 'toutes':
            return [
                'key' => $status,
                'label' => 'Toutes les demandes',
                'condition' => '1=1',
                'date_expr' => $dateCreateExpr,
                'date_label' => 'Date demande',
            ];
        case 'decaissements':
        default:
            return [
                'key' => 'decaissements',
                'label' => 'Décaissements à traiter',
                'condition' => '(f.decaisse = 0 AND COALESCE(f.conforme, 0) = 1 AND COALESCE(f.approuve, 0) = 1 AND COALESCE(f.etat_fiche, 0) = 1)',
                'date_expr' => "COALESCE($dateValidExpr, $dateApprobExpr, $dateConformeExpr, $dateCreateExpr)",
                'date_label' => 'Date validation',
            ];
    }
}

function pf_apply_common_filters(array &$where, array &$params, array $filters, string $dateExpr = 'f.date_creat_fiche', ?string $tableChantier = null): void
{
    if ($tableChantier === 'chantier_banamur') {
        $where[] = '(f.chantier_id IS NULL OR f.chantier_id = 0 OR EXISTS (SELECT 1 FROM chantier_banamur c_active WHERE c_active.id=f.chantier_id AND c_active.archived_at IS NULL))';
    }
    $beneficiaire = trim((string)($filters['beneficiaire'] ?? ''));
    $exactDate = trim((string)($filters['exact_date'] ?? ''));
    $from = trim((string)($filters['from'] ?? ''));
    $to = trim((string)($filters['to'] ?? ''));
    $chantier = trim((string)($filters['chantier'] ?? ''));
    $debourse = trim((string)($filters['debourse'] ?? ''));

    if ($beneficiaire !== '') {
        $where[] = 'f.beficiaire_fiche LIKE :benef';
        $params['benef'] = '%' . $beneficiaire . '%';
    }
    if ($exactDate !== '') {
        $where[] = $dateExpr . ' >= :dexact_from';
        $where[] = $dateExpr . ' <= :dexact_to';
        $params['dexact_from'] = $exactDate . ' 00:00:00';
        $params['dexact_to'] = $exactDate . ' 23:59:59';
    } elseif ($from !== '') {
        $where[] = $dateExpr . ' >= :dfrom';
        $params['dfrom'] = $from . ' 00:00:00';
    }
    if ($exactDate === '' && $to !== '') {
        $where[] = $dateExpr . ' <= :dto';
        $params['dto'] = $to . ' 23:59:59';
    }
    if ($chantier !== '') {
        if ($tableChantier !== null) {
            if (ctype_digit($chantier)) {
                $where[] = '(
                    f.chantier_id = :ch_id
                    OR (
                        (f.chantier_id IS NULL OR f.chantier_id = 0)
                        AND db.devis_id = (SELECT devis_id FROM ' . $tableChantier . ' WHERE id = :ch_id_scope LIMIT 1)
                    )
                )';
                $params['ch_id'] = (int)$chantier;
                $params['ch_id_scope'] = (int)$chantier;
            } else {
                $where[] = '(
                    c.code = :ch_code
                    OR (
                        (f.chantier_id IS NULL OR f.chantier_id = 0)
                        AND db.devis_id = (SELECT devis_id FROM ' . $tableChantier . ' WHERE code = :ch_code_scope LIMIT 1)
                    )
                )';
                $params['ch_code'] = $chantier;
                $params['ch_code_scope'] = $chantier;
            }
        } elseif (ctype_digit($chantier)) {
            $where[] = 'f.chantier_id = :ch_id';
            $params['ch_id'] = (int)$chantier;
        } else {
            $where[] = 'c.code = :ch_code';
            $params['ch_code'] = $chantier;
        }
    }
    if ($debourse !== '') {
        $where[] = 'db.id = :deb';
        $params['deb'] = $debourse;
    }
}

function pf_reference_date(array $row): ?string
{
    if ((int)($row['decaisse'] ?? 0) === 1) {
        return pf_clean_date_value($row['date_decaisse'] ?? null) ?? pf_clean_date_value($row['date_creat_fiche'] ?? null);
    }
    if ((int)($row['approuve'] ?? 0) === 2 || (int)($row['etat_fiche'] ?? 0) === 2) {
        return pf_clean_date_value($row['date_refuse'] ?? null)
            ?? pf_clean_date_value($row['date_approbation'] ?? null)
            ?? pf_clean_date_value($row['date_conforme'] ?? null)
            ?? pf_clean_date_value($row['date_creat_fiche'] ?? null);
    }
    if ((int)($row['conforme'] ?? 0) === 0) {
        return pf_clean_date_value($row['date_creat_fiche'] ?? null);
    }
    if ((int)($row['approuve'] ?? 0) === 0) {
        return pf_clean_date_value($row['date_conforme'] ?? null) ?? pf_clean_date_value($row['date_creat_fiche'] ?? null);
    }
    if ((int)($row['etat_fiche'] ?? 0) === 0) {
        return pf_clean_date_value($row['date_approbation'] ?? null) ?? pf_clean_date_value($row['date_conforme'] ?? null) ?? pf_clean_date_value($row['date_creat_fiche'] ?? null);
    }
    if ((int)($row['etat_fiche'] ?? 0) === 1) {
        return pf_clean_date_value($row['date_valid'] ?? null) ?? pf_clean_date_value($row['date_approbation'] ?? null) ?? pf_clean_date_value($row['date_creat_fiche'] ?? null);
    }
    return pf_clean_date_value($row['date_creat_fiche'] ?? null);
}

function pf_format_status(array $row): array
{
    $approuve = (int)($row['approuve'] ?? 0);
    $conforme = (int)($row['conforme'] ?? 0);
    $etatFiche = (int)($row['etat_fiche'] ?? 0);
    $decaisse = (int)($row['decaisse'] ?? 0);

    if ($decaisse === 1) {
        return ['key' => 'decaissee', 'label' => 'Décaissée', 'badge' => 'green', 'date_label' => 'Date décaissement'];
    }
    if ($approuve === 2 || $etatFiche === 2) {
        return ['key' => 'refusee', 'label' => 'Refusée', 'badge' => 'red', 'date_label' => 'Date refus'];
    }
    if ($conforme === 1 && $approuve === 1 && $etatFiche === 1) {
        return ['key' => 'tresorerie', 'label' => 'Décaissements à traiter', 'badge' => 'indigo', 'date_label' => 'Date validation'];
    }
    if ($conforme === 1 && $approuve === 1 && $etatFiche === 0) {
        return ['key' => 'validation_achat', 'label' => 'Validation Achat', 'badge' => 'sky', 'date_label' => 'Date approbation'];
    }
    if ($conforme === 1 && $approuve === 0) {
        return ['key' => 'approuvee', 'label' => 'Approbation', 'badge' => 'amber', 'date_label' => 'Date demande'];
    }
    if ($conforme === 1) {
        return ['key' => 'conforme', 'label' => 'Conformité', 'badge' => 'blue', 'date_label' => 'Date demande'];
    }

    return ['key' => 'attente', 'label' => 'En attente', 'badge' => 'gray', 'date_label' => 'Date demande'];
}

function pf_extract_refus_reason(array $row): string
{
    $details = (string)($row['details_demande'] ?? '');
    if ($details === '') {
        return '';
    }
    if (preg_match_all('/\[REFUS[^\]]*\]\s*(.+)/u', $details, $matches) && !empty($matches[1])) {
        return trim((string)end($matches[1]));
    }
    return '';
}

function pf_try_repair_chantier_debourse_links(PDO $pdo, string $tableChantier, $chantier): void
{
    $chantier = trim((string)$chantier);
    if ($chantier === '' || $tableChantier !== 'chantier_banamur') {
        return;
    }

    static $done = [];
    $cacheKey = $tableChantier . '|' . $chantier;
    if (isset($done[$cacheKey])) {
        return;
    }

    $chantierId = 0;
    if (ctype_digit($chantier)) {
        $chantierId = (int)$chantier;
    } else {
        $stmt = $pdo->prepare("SELECT id FROM $tableChantier WHERE code = :code LIMIT 1");
        $stmt->execute(['code' => $chantier]);
        $chantierId = (int)$stmt->fetchColumn();
    }

    if ($chantierId > 0) {
        $ficheModel = new FicheDecaissement($pdo);
        $ficheModel->repairMissingDebourseLinksForChantier($chantierId);
    }

    $done[$cacheKey] = true;
}
