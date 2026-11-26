<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/Dashboard.php';
require_once __DIR__ . '/model/Fiche.php';
require_once __DIR__ . '/model/Chantier.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'detail_budgetaire_chantier_view',
    'entity_type' => 'chantier_budget_detail',
    'entity_id' => isset($_GET['chantier_id']) ? (string) $_GET['chantier_id'] : (isset($_GET['id']) ? (string) $_GET['id'] : null),
    'entity_label' => 'Detail budgetaire chantier',
    'page_name' => 'detail_budgetaire_chantier.php',
    'details' => [
        'chantier_id' => $_GET['chantier_id'] ?? $_GET['id'] ?? null,
        'view_mode' => 'detail',
    ],
]);

$pdo = Database::getConnection();
$dashboardModel = new Dashboard($pdo);

// Récupérer l'ID du chantier
$chantierId = $_GET['chantier_id'] ?? $_GET['id'] ?? null;

if (!$chantierId) {
    header('Location: details_chantiers.php');
    exit;
}

// Récupérer les détails budgétaires
$detailsChantier = $dashboardModel->getDetailsBudgetaireChantier($chantierId);

if (!$detailsChantier) {
    header('Location: details_chantiers.php?error=chantier_not_found');
    exit;
}

$rawDetailsChantier = $detailsChantier;

$detailFilters = [
    'zone' => trim((string)($_GET['filter_zone'] ?? '')),
    'debourse' => trim((string)($_GET['filter_debourse'] ?? '')),
    'ligne_debourse' => trim((string)($_GET['filter_ligne_debourse'] ?? '')),
    'devis' => trim((string)($_GET['filter_devis'] ?? '')),
    'ligne_devis' => trim((string)($_GET['filter_ligne_devis'] ?? '')),
    'beneficiaire' => trim((string)($_GET['filter_beneficiaire'] ?? '')),
    'date_precise' => trim((string)($_GET['filter_date_precise'] ?? '')),
    'fiche' => trim((string)($_GET['filter_fiche'] ?? '')),
];

$hasActiveDetailFilters = false;
foreach ($detailFilters as $filterValue) {
    if ($filterValue !== '') {
        $hasActiveDetailFilters = true;
        break;
    }
}

$normalizeSearch = static function ($value): string {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }

    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
};

$containsSearch = static function (array $values, string $needle) use ($normalizeSearch): bool {
    if ($needle === '') {
        return true;
    }

    foreach ($values as $value) {
        $haystack = $normalizeSearch($value);
        if ($haystack === '') {
            continue;
        }

        if (function_exists('mb_strpos')) {
            if (mb_strpos($haystack, $needle, 0, 'UTF-8') !== false) {
                return true;
            }
        } elseif (strpos($haystack, $needle) !== false) {
            return true;
        }
    }

    return false;
};

$buildDebourseLabel = static function (array $ligneDebourse): string {
    $debourseId = (int)($ligneDebourse['debourse_id'] ?? 0);
    $parts = [];
    if ($debourseId > 0) {
        $parts[] = 'Déboursé #' . $debourseId;
    }

    $dateDebut = trim((string)($ligneDebourse['date_debut_debourse'] ?? $ligneDebourse['date_debut'] ?? ''));
    $dateFin = trim((string)($ligneDebourse['date_fin_debourse'] ?? $ligneDebourse['date_fin'] ?? ''));
    if ($dateDebut !== '' || $dateFin !== '') {
        $parts[] = trim(($dateDebut !== '' ? $dateDebut : '...') . ' -> ' . ($dateFin !== '' ? $dateFin : '...'));
    }

    $lineSource = trim((string)($ligneDebourse['designation_ligne_devis'] ?? ''));
    if ($lineSource !== '') {
        $parts[] = $lineSource;
    }

    return trim(implode(' | ', $parts));
};

$lineDevisByIdRaw = [];
foreach (($rawDetailsChantier['lignes_devis'] ?? []) as $ligneDevisRaw) {
    $lineDevisIdRaw = (int)($ligneDevisRaw['id'] ?? 0);
    if ($lineDevisIdRaw > 0) {
        $lineDevisByIdRaw[$lineDevisIdRaw] = $ligneDevisRaw;
    }
}

$lineDebourseByIdRaw = [];
$lineDebourseIdsByLineDevisRaw = [];
foreach (($rawDetailsChantier['lignes_debourse'] ?? []) as $ligneDebourseRaw) {
    $ligneDebourseIdRaw = (int)($ligneDebourseRaw['id'] ?? 0);
    if ($ligneDebourseIdRaw <= 0) {
        continue;
    }

    $lineDebourseByIdRaw[$ligneDebourseIdRaw] = $ligneDebourseRaw;
    $linkedLineDevisId = (int)($ligneDebourseRaw['ligne_devis_source_id'] ?? 0);
    if ($linkedLineDevisId > 0) {
        if (!isset($lineDebourseIdsByLineDevisRaw[$linkedLineDevisId])) {
            $lineDebourseIdsByLineDevisRaw[$linkedLineDevisId] = [];
        }
        $lineDebourseIdsByLineDevisRaw[$linkedLineDevisId][] = $ligneDebourseIdRaw;
    }
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (string)($_POST['action'] ?? '') === 'reassign_fiche_zone') {
    $redirectParams = $_POST['redirect_filters'] ?? [];
    if (!is_array($redirectParams)) {
        $redirectParams = [];
    }

    $redirectParams['chantier_id'] = $chantierId;
    $redirectUrl = 'detail_budgetaire_chantier.php?' . http_build_query($redirectParams);

    try {
        $ficheId = (int)($_POST['fiche_id'] ?? 0);
        $targetLigneDebourseId = (int)($_POST['target_ligne_debourse_id'] ?? 0);
        $chantierDevisId = (int)($rawDetailsChantier['chantier']['devis_id'] ?? 0);

        if ($ficheId <= 0 || $targetLigneDebourseId <= 0 || $chantierDevisId <= 0) {
            throw new RuntimeException('Paramètres de reclassement invalides.');
        }

        $targetStmt = $pdo->prepare(
            'SELECT ldb.id, ldb.designation, db.devis_id, db.id AS debourse_id
             FROM ligne_debourse_banamur ldb
             INNER JOIN debourse_banamur db ON db.id = ldb.debourse_id
             WHERE ldb.id = :id
             LIMIT 1'
        );
        $targetStmt->execute(['id' => $targetLigneDebourseId]);
        $targetLine = $targetStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$targetLine) {
            throw new RuntimeException('La ligne de déboursé cible est introuvable.');
        }

        if ((int)($targetLine['devis_id'] ?? 0) !== $chantierDevisId) {
            throw new RuntimeException('La ligne de déboursé cible ne correspond pas au devis de ce chantier.');
        }

        $ficheStmt = $pdo->prepare('SELECT id_fiche, num_fiche FROM fiche_decaissement WHERE id_fiche = :id LIMIT 1');
        $ficheStmt->execute(['id' => $ficheId]);
        $ficheRow = $ficheStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$ficheRow) {
            throw new RuntimeException('La fiche à reclasser est introuvable.');
        }

        $updateStmt = $pdo->prepare(
            'UPDATE fiche_decaissement
             SET ligne_debourse_id = :ligne_debourse_id,
                 chantier_id = CASE WHEN chantier_id IS NULL OR chantier_id = 0 THEN :chantier_id ELSE chantier_id END
             WHERE id_fiche = :id_fiche'
        );
        $updateStmt->execute([
            'ligne_debourse_id' => $targetLigneDebourseId,
            'chantier_id' => (int)$chantierId,
            'id_fiche' => $ficheId,
        ]);

        $_SESSION['flash_success'] = 'Fiche ' . (string)($ficheRow['num_fiche'] ?? ('#' . $ficheId)) . ' reclassée avec succès.';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Reclassement impossible: ' . $e->getMessage();
    }

    header('Location: ' . $redirectUrl);
    exit;
}

$annotatedFichesRaw = [];
$fichesByLineDebourseRaw = [];
$fichesByLineDevisRaw = [];
foreach (($rawDetailsChantier['fiches'] ?? []) as $rawFiche) {
    $ligneDebourseId = (int)($rawFiche['ligne_debourse_id'] ?? 0);
    $ligneDebourse = $ligneDebourseId > 0 ? ($lineDebourseByIdRaw[$ligneDebourseId] ?? null) : null;
    $ligneDevisId = (int)($rawFiche['ligne_devis_source_id'] ?? 0);
    if ($ligneDevisId <= 0 && $ligneDebourse) {
        $ligneDevisId = (int)($ligneDebourse['ligne_devis_source_id'] ?? 0);
    }
    $ligneDevis = $ligneDevisId > 0 ? ($lineDevisByIdRaw[$ligneDevisId] ?? null) : null;
    $zoneLabel = trim((string)($ligneDevis['zone'] ?? ($ligneDebourse['zone_ligne_devis'] ?? '')));
    $debourseLabel = trim((string)($rawFiche['ligne_debourse_designation'] ?? ($ligneDebourse['designation'] ?? '')));
    $debourseParentLabel = $ligneDebourse ? $buildDebourseLabel($ligneDebourse) : '';
    $ligneDevisLabel = trim((string)($rawFiche['ligne_devis_designation'] ?? ($ligneDevis['designation'] ?? '')));
    $ficheId = (int)($rawFiche['id_fiche'] ?? 0);

    $rawFiche['_placement'] = [
        'zone' => $zoneLabel,
        'debourse_label' => $debourseParentLabel,
        'debourse_id' => (int)($ligneDebourse['debourse_id'] ?? 0),
        'ligne_debourse_label' => $debourseLabel,
        'ligne_debourse_id' => $ligneDebourseId,
        'ligne_devis_label' => $ligneDevisLabel,
        'ligne_devis_id' => $ligneDevisId,
    ];

    $rawFiche['_date_only'] = '';
    if (!empty($rawFiche['date_creat_fiche'])) {
        $ficheTimestamp = strtotime((string)$rawFiche['date_creat_fiche']);
        if ($ficheTimestamp) {
            $rawFiche['_date_only'] = date('Y-m-d', $ficheTimestamp);
        }
    }

    $annotatedFichesRaw[] = $rawFiche;
    if ($ligneDebourseId > 0) {
        $fichesByLineDebourseRaw[$ligneDebourseId][] = $ficheId;
    }
    if ($ligneDevisId > 0) {
        $fichesByLineDevisRaw[$ligneDevisId][] = $ficheId;
    }
}

$devisSearchMatches = $containsSearch([
    (string)($rawDetailsChantier['devis']['numero_devis'] ?? ''),
    (string)($rawDetailsChantier['devis']['libelle_devis'] ?? ''),
], $normalizeSearch($detailFilters['devis']));

$matchedLineDevisIds = [];
foreach (($rawDetailsChantier['lignes_devis'] ?? []) as $ligneDevisRaw) {
    $lineId = (int)($ligneDevisRaw['id'] ?? 0);
    if ($lineId <= 0) {
        continue;
    }

    $matches = $devisSearchMatches
        && $containsSearch([(string)($ligneDevisRaw['zone'] ?? '')], $normalizeSearch($detailFilters['zone']))
        && $containsSearch([
            (string)($ligneDevisRaw['designation'] ?? ''),
            (string)($ligneDevisRaw['groupe'] ?? ''),
        ], $normalizeSearch($detailFilters['ligne_devis']));

    if ($matches) {
        $matchedLineDevisIds[$lineId] = true;
    }
}

$matchedLineDebourseIds = [];
foreach (($rawDetailsChantier['lignes_debourse'] ?? []) as $ligneDebourseRaw) {
    $lineDebId = (int)($ligneDebourseRaw['id'] ?? 0);
    if ($lineDebId <= 0) {
        continue;
    }

    $relatedLineDevis = (int)($ligneDebourseRaw['ligne_devis_source_id'] ?? 0);
    $relatedLineDevisRow = $relatedLineDevis > 0 ? ($lineDevisByIdRaw[$relatedLineDevis] ?? null) : null;

    $matches = $devisSearchMatches
        && $containsSearch([(string)($relatedLineDevisRow['zone'] ?? ($ligneDebourseRaw['zone_ligne_devis'] ?? ''))], $normalizeSearch($detailFilters['zone']))
        && $containsSearch([
            $buildDebourseLabel($ligneDebourseRaw),
            'Déboursé #' . (string)($ligneDebourseRaw['debourse_id'] ?? ''),
            (string)($ligneDebourseRaw['date_debut_debourse'] ?? ''),
            (string)($ligneDebourseRaw['date_fin_debourse'] ?? ''),
        ], $normalizeSearch($detailFilters['debourse']))
        && $containsSearch([
            (string)($ligneDebourseRaw['designation'] ?? ''),
            (string)($ligneDebourseRaw['categorie'] ?? ''),
            '#' . $lineDebId,
        ], $normalizeSearch($detailFilters['ligne_debourse']))
        && $containsSearch([
            (string)($relatedLineDevisRow['designation'] ?? ''),
            (string)($ligneDebourseRaw['designation_ligne_devis'] ?? ''),
        ], $normalizeSearch($detailFilters['ligne_devis']));

    if ($matches) {
        $matchedLineDebourseIds[$lineDebId] = true;
    }
}

$matchedFicheIds = [];
foreach ($annotatedFichesRaw as $ficheRaw) {
    $ficheId = (int)($ficheRaw['id_fiche'] ?? 0);
    if ($ficheId <= 0) {
        continue;
    }

    $placement = $ficheRaw['_placement'] ?? [];
    $matches = $devisSearchMatches
        && $containsSearch([(string)($placement['zone'] ?? '')], $normalizeSearch($detailFilters['zone']))
        && $containsSearch([
            (string)($placement['debourse_label'] ?? ''),
            'Déboursé #' . (string)($placement['debourse_id'] ?? ''),
        ], $normalizeSearch($detailFilters['debourse']))
        && $containsSearch([
            (string)($placement['ligne_debourse_label'] ?? ''),
            '#' . (string)($placement['ligne_debourse_id'] ?? ''),
            (string)($ficheRaw['ligne_debourse_categorie'] ?? ''),
        ], $normalizeSearch($detailFilters['ligne_debourse']))
        && $containsSearch([(string)($placement['ligne_devis_label'] ?? '')], $normalizeSearch($detailFilters['ligne_devis']))
        && $containsSearch([
            (string)($ficheRaw['num_fiche'] ?? ''),
            (string)($ficheRaw['designation_libelle'] ?? ''),
            (string)($ficheRaw['precision_libelle'] ?? ''),
            (string)($ficheRaw['details_demande'] ?? ''),
        ], $normalizeSearch($detailFilters['fiche']))
        && $containsSearch([
            (string)($ficheRaw['beficiaire_fiche'] ?? ''),
        ], $normalizeSearch($detailFilters['beneficiaire']))
        && ($detailFilters['date_precise'] === '' || (string)($ficheRaw['_date_only'] ?? '') === $detailFilters['date_precise']);

    if ($matches) {
        $matchedFicheIds[$ficheId] = true;
    }
}

$filteredLineDevisIds = [];
$filteredLineDebourseIds = [];
$filteredFicheIds = [];

if ($hasActiveDetailFilters) {
    $ficheFilterActive = $detailFilters['fiche'] !== '' || $detailFilters['beneficiaire'] !== '' || $detailFilters['date_precise'] !== '';
    $lineDevisScopeActive = $detailFilters['zone'] !== '' || $detailFilters['devis'] !== '' || $detailFilters['ligne_devis'] !== '';
    $lineDebourseScopeActive = $detailFilters['debourse'] !== '' || $detailFilters['ligne_debourse'] !== '';

    if ($ficheFilterActive) {
        $filteredFicheIds = $matchedFicheIds;
        foreach (array_keys($matchedFicheIds) as $matchedFicheId) {
            foreach ($annotatedFichesRaw as $annotatedFiche) {
                if ((int)($annotatedFiche['id_fiche'] ?? 0) !== $matchedFicheId) {
                    continue;
                }
                $placement = $annotatedFiche['_placement'] ?? [];
                if (!empty($placement['ligne_devis_id'])) {
                    $filteredLineDevisIds[(int)$placement['ligne_devis_id']] = true;
                }
                if (!empty($placement['ligne_debourse_id'])) {
                    $filteredLineDebourseIds[(int)$placement['ligne_debourse_id']] = true;
                }
                break;
            }
        }
    } else {
        $filteredLineDevisIds = $matchedLineDevisIds;
        $filteredLineDebourseIds = $matchedLineDebourseIds;

        if ($lineDevisScopeActive) {
            foreach (array_keys($filteredLineDevisIds) as $lineDevisId) {
                foreach (($lineDebourseIdsByLineDevisRaw[$lineDevisId] ?? []) as $lineDebourseId) {
                    $filteredLineDebourseIds[(int)$lineDebourseId] = true;
                }
                foreach (($fichesByLineDevisRaw[$lineDevisId] ?? []) as $ficheId) {
                    $filteredFicheIds[(int)$ficheId] = true;
                }
            }
        }

        if ($lineDebourseScopeActive) {
            foreach (array_keys($filteredLineDebourseIds) as $lineDebourseId) {
                $parentLineDevisId = (int)($lineDebourseByIdRaw[$lineDebourseId]['ligne_devis_source_id'] ?? 0);
                if ($parentLineDevisId > 0) {
                    $filteredLineDevisIds[$parentLineDevisId] = true;
                }
                foreach (($fichesByLineDebourseRaw[$lineDebourseId] ?? []) as $ficheId) {
                    $filteredFicheIds[(int)$ficheId] = true;
                }
            }
        }

        if (!$lineDevisScopeActive && !$lineDebourseScopeActive && $detailFilters['devis'] !== '') {
            foreach (($rawDetailsChantier['lignes_devis'] ?? []) as $ligneDevisRaw) {
                $lineId = (int)($ligneDevisRaw['id'] ?? 0);
                if ($lineId > 0) {
                    $filteredLineDevisIds[$lineId] = true;
                }
            }
            foreach (($rawDetailsChantier['lignes_debourse'] ?? []) as $ligneDebourseRaw) {
                $lineDebId = (int)($ligneDebourseRaw['id'] ?? 0);
                if ($lineDebId > 0) {
                    $filteredLineDebourseIds[$lineDebId] = true;
                }
            }
            foreach ($annotatedFichesRaw as $ficheRaw) {
                $ficheId = (int)($ficheRaw['id_fiche'] ?? 0);
                if ($ficheId > 0) {
                    $filteredFicheIds[$ficheId] = true;
                }
            }
        }
    }
}

if ($hasActiveDetailFilters) {
    $detailsChantier['lignes_devis'] = array_values(array_filter(
        $rawDetailsChantier['lignes_devis'] ?? [],
        static function ($ligne) use ($filteredLineDevisIds) {
            $lineId = (int)($ligne['id'] ?? 0);
            return $lineId > 0 && isset($filteredLineDevisIds[$lineId]);
        }
    ));

    $detailsChantier['lignes_debourse'] = array_values(array_filter(
        $rawDetailsChantier['lignes_debourse'] ?? [],
        static function ($ligne) use ($filteredLineDebourseIds) {
            $lineId = (int)($ligne['id'] ?? 0);
            return $lineId > 0 && isset($filteredLineDebourseIds[$lineId]);
        }
    ));

    $detailsChantier['fiches'] = array_values(array_filter(
        $annotatedFichesRaw,
        static function ($fiche) use ($filteredFicheIds) {
            $ficheId = (int)($fiche['id_fiche'] ?? 0);
            return $ficheId > 0 && isset($filteredFicheIds[$ficheId]);
        }
    ));

    $filteredLineIndexById = [];
    foreach ($detailsChantier['lignes_devis'] as $lineIndex => &$ligne) {
        $ligne['montant_paye'] = 0.0;
        $filteredLineId = (int)($ligne['id'] ?? 0);
        if ($filteredLineId > 0) {
            $filteredLineIndexById[$filteredLineId] = $lineIndex;
        }
    }
    unset($ligne);

    $filteredLineDevisIdByLineDebourseId = [];
    foreach ($detailsChantier['lignes_debourse'] as $ligneDebourse) {
        $filteredLineDebourseId = (int)($ligneDebourse['id'] ?? 0);
        $filteredLineDevisId = (int)($ligneDebourse['ligne_devis_source_id'] ?? 0);
        if ($filteredLineDebourseId > 0 && $filteredLineDevisId > 0) {
            $filteredLineDevisIdByLineDebourseId[$filteredLineDebourseId] = $filteredLineDevisId;
        }
    }

    $montantNonRattacheFiltre = 0.0;
    $nbFichesNonRattacheesFiltre = 0;
    foreach ($detailsChantier['fiches'] as $fiche) {
        if ((int)($fiche['decaisse'] ?? 0) !== 1) {
            continue;
        }

        $montantFiche = (float)($fiche['montant_fiche'] ?? 0);
        if ($montantFiche <= 0) {
            continue;
        }

        $filteredLineDevisId = (int)($fiche['ligne_devis_source_id'] ?? 0);
        if ($filteredLineDevisId <= 0) {
            $filteredLineDebourseId = (int)($fiche['ligne_debourse_id'] ?? 0);
            if ($filteredLineDebourseId > 0 && isset($filteredLineDevisIdByLineDebourseId[$filteredLineDebourseId])) {
                $filteredLineDevisId = $filteredLineDevisIdByLineDebourseId[$filteredLineDebourseId];
            }
        }

        if ($filteredLineDevisId > 0 && isset($filteredLineIndexById[$filteredLineDevisId])) {
            $detailsChantier['lignes_devis'][$filteredLineIndexById[$filteredLineDevisId]]['montant_paye'] += $montantFiche;
            continue;
        }

        $montantNonRattacheFiltre += $montantFiche;
        $nbFichesNonRattacheesFiltre++;
    }

    if ($montantNonRattacheFiltre > 0) {
        $detailsChantier['lignes_devis'][] = [
            'id' => 0,
            'devis_id' => $rawDetailsChantier['chantier']['devis_id'] ?? null,
            'designation' => 'Décaissements non rattachés',
            'groupe' => 'Ajustement',
            'zone' => 'Hors correspondance',
            'prix' => 0,
            'quantite' => 0,
            'total' => 0,
            'unite_libelle' => '',
            'montant_debourse_reel' => 0,
            'montant_paye' => $montantNonRattacheFiltre,
            'fiches_non_rattachees' => $nbFichesNonRattacheesFiltre,
            '__synthetic_unmatched' => true,
        ];
    }

    $detailsChantier['zones'] = [];
    foreach ($detailsChantier['lignes_devis'] as $ligne) {
        $zone = $ligne['zone'] ?? 'Sans zone';
        if (!isset($detailsChantier['zones'][$zone])) {
            $detailsChantier['zones'][$zone] = [];
        }
        $detailsChantier['zones'][$zone][] = $ligne;
    }

    $budgetFromFilteredDevis = 0.0;
    foreach ($detailsChantier['lignes_devis'] as $ligne) {
        $budgetFromFilteredDevis += is_numeric($ligne['total'] ?? null) ? (float)$ligne['total'] : 0.0;
    }

    $totalDebourseFiltered = 0.0;
    foreach ($detailsChantier['lignes_debourse'] as $ligneDebourse) {
        $totalDebourseFiltered += is_numeric($ligneDebourse['montant'] ?? null) ? (float)$ligneDebourse['montant'] : 0.0;
    }
    if ($totalDebourseFiltered <= 0) {
        foreach ($detailsChantier['lignes_devis'] as $ligne) {
            $totalDebourseFiltered += is_numeric($ligne['montant_debourse_reel'] ?? null) ? (float)$ligne['montant_debourse_reel'] : 0.0;
        }
    }

    $montantPayeFiltered = 0.0;
    $fichesDecaisseesFiltered = 0;
    foreach ($detailsChantier['fiches'] as $fiche) {
        if ((int)($fiche['decaisse'] ?? 0) === 1) {
            $montantPayeFiltered += is_numeric($fiche['montant_fiche'] ?? null) ? (float)$fiche['montant_fiche'] : 0.0;
            $fichesDecaisseesFiltered++;
        }
    }

    $budgetTotalFiltered = 0.0;
    if ($totalDebourseFiltered > 0) {
        $budgetTotalFiltered = $totalDebourseFiltered;
    } elseif ($budgetFromFilteredDevis > 0) {
        $budgetTotalFiltered = $budgetFromFilteredDevis;
    }

    $detailsChantier['totaux']['budget_total'] = $budgetTotalFiltered;
    $detailsChantier['totaux']['total_debourse'] = $totalDebourseFiltered;
    $detailsChantier['totaux']['montant_paye'] = $montantPayeFiltered;
    $detailsChantier['totaux']['montant_restant'] = max($budgetTotalFiltered - $montantPayeFiltered, 0);
    $detailsChantier['totaux']['pourcentage_realise'] = $budgetTotalFiltered > 0 ? ($montantPayeFiltered / $budgetTotalFiltered) * 100 : 0;
    $detailsChantier['stats_fiches']['total_fiches'] = count($detailsChantier['fiches']);
    $detailsChantier['stats_fiches']['fiches_decaissees'] = $fichesDecaisseesFiltered;
}

$zoneSuggestions = [];
foreach (($rawDetailsChantier['zones'] ?? []) as $zoneName => $_unused) {
    $zoneSuggestions[] = (string)$zoneName;
}
sort($zoneSuggestions);

$debourseSuggestions = [];
foreach (($rawDetailsChantier['lignes_debourse'] ?? []) as $ligneDebourseRaw) {
    $label = $buildDebourseLabel($ligneDebourseRaw);
    if ($label !== '') {
        $debourseSuggestions[$label] = true;
    }
}
$debourseSuggestions = array_keys($debourseSuggestions);
sort($debourseSuggestions);

$ligneDebourseSuggestions = [];
foreach (($rawDetailsChantier['lignes_debourse'] ?? []) as $ligneDebourseRaw) {
    $label = trim((string)($ligneDebourseRaw['designation'] ?? ''));
    if ($label !== '') {
        $ligneDebourseSuggestions[$label] = true;
    }
}
$ligneDebourseSuggestions = array_keys($ligneDebourseSuggestions);
sort($ligneDebourseSuggestions);

$ligneDevisSuggestions = [];
foreach (($rawDetailsChantier['lignes_devis'] ?? []) as $ligneDevisRaw) {
    $label = trim((string)($ligneDevisRaw['designation'] ?? ''));
    if ($label !== '') {
        $ligneDevisSuggestions[$label] = true;
    }
}
$ligneDevisSuggestions = array_keys($ligneDevisSuggestions);
sort($ligneDevisSuggestions);

$ficheSuggestions = [];
foreach ($annotatedFichesRaw as $ficheRaw) {
    $label = trim((string)($ficheRaw['num_fiche'] ?? ''));
    if ($label !== '') {
        $ficheSuggestions[$label] = true;
    }
}
$ficheSuggestions = array_keys($ficheSuggestions);
sort($ficheSuggestions);

$beneficiaireSuggestions = [];
foreach ($annotatedFichesRaw as $ficheRaw) {
    $label = trim((string)($ficheRaw['beficiaire_fiche'] ?? ''));
    if ($label !== '') {
        $beneficiaireSuggestions[$label] = true;
    }
}
$beneficiaireSuggestions = array_keys($beneficiaireSuggestions);
sort($beneficiaireSuggestions);

$reassignTargets = [];
foreach (($rawDetailsChantier['lignes_debourse'] ?? []) as $ligneDebourseRaw) {
    $lineId = (int)($ligneDebourseRaw['id'] ?? 0);
    if ($lineId <= 0) {
        continue;
    }

    $zoneLabel = trim((string)($ligneDebourseRaw['zone_ligne_devis'] ?? 'Sans zone'));
    $lineDevisLabel = trim((string)($ligneDebourseRaw['designation_ligne_devis'] ?? 'Ligne devis'));
    $lineDebourseLabel = trim((string)($ligneDebourseRaw['designation'] ?? ('Ligne #' . $lineId)));
    $reassignTargets[] = [
        'id' => $lineId,
        'zone' => $zoneLabel !== '' ? $zoneLabel : 'Sans zone',
        'debourse' => $buildDebourseLabel($ligneDebourseRaw),
        'ligne_devis' => $lineDevisLabel,
        'ligne_debourse' => $lineDebourseLabel,
    ];
}

$activeFilterSummaries = [];
$filterSummaryLabels = [
    'zone' => 'Zone',
    'debourse' => 'Déboursé',
    'ligne_debourse' => 'Ligne de déboursé',
    'devis' => 'Devis',
    'ligne_devis' => 'Ligne de devis',
    'beneficiaire' => 'Bénéficiaire',
    'date_precise' => 'Date précise',
    'fiche' => 'Fiche réelle',
];

$countMatchingFiches = static function (callable $predicate) use ($annotatedFichesRaw): int {
    $count = 0;
    foreach ($annotatedFichesRaw as $ficheSummary) {
        if ($predicate($ficheSummary)) {
            $count++;
        }
    }
    return $count;
};

foreach ($detailFilters as $filterKey => $filterValue) {
    if ($filterValue === '') {
        continue;
    }

    $summaryValue = $filterValue;
    if ($filterKey === 'date_precise') {
        $timestamp = strtotime($filterValue);
        if ($timestamp) {
            $summaryValue = date('d/m/Y', $timestamp);
        }
    }

    $matchCount = 0;
    switch ($filterKey) {
        case 'zone':
            $needle = $normalizeSearch($filterValue);
            $matchCount = $countMatchingFiches(static function (array $ficheSummary) use ($containsSearch, $needle) {
                $placement = $ficheSummary['_placement'] ?? [];
                return $containsSearch([(string)($placement['zone'] ?? '')], $needle);
            });
            break;

        case 'debourse':
            $needle = $normalizeSearch($filterValue);
            $matchCount = $countMatchingFiches(static function (array $ficheSummary) use ($containsSearch, $needle) {
                $placement = $ficheSummary['_placement'] ?? [];
                return $containsSearch([
                    (string)($placement['debourse_label'] ?? ''),
                    'Déboursé #' . (string)($placement['debourse_id'] ?? ''),
                ], $needle);
            });
            break;

        case 'ligne_debourse':
            $needle = $normalizeSearch($filterValue);
            $matchCount = $countMatchingFiches(static function (array $ficheSummary) use ($containsSearch, $needle) {
                $placement = $ficheSummary['_placement'] ?? [];
                return $containsSearch([
                    (string)($placement['ligne_debourse_label'] ?? ''),
                    '#' . (string)($placement['ligne_debourse_id'] ?? ''),
                    (string)($ficheSummary['ligne_debourse_categorie'] ?? ''),
                ], $needle);
            });
            break;

        case 'devis':
            $needle = $normalizeSearch($filterValue);
            $devisMatches = $containsSearch([
                (string)($rawDetailsChantier['devis']['numero_devis'] ?? ''),
                (string)($rawDetailsChantier['devis']['libelle_devis'] ?? ''),
            ], $needle);
            $matchCount = $devisMatches ? count($annotatedFichesRaw) : 0;
            break;

        case 'ligne_devis':
            $needle = $normalizeSearch($filterValue);
            $matchCount = $countMatchingFiches(static function (array $ficheSummary) use ($containsSearch, $needle) {
                $placement = $ficheSummary['_placement'] ?? [];
                return $containsSearch([(string)($placement['ligne_devis_label'] ?? '')], $needle);
            });
            break;

        case 'beneficiaire':
            $needle = $normalizeSearch($filterValue);
            $matchCount = $countMatchingFiches(static function (array $ficheSummary) use ($containsSearch, $needle) {
                return $containsSearch([(string)($ficheSummary['beficiaire_fiche'] ?? '')], $needle);
            });
            break;

        case 'date_precise':
            $matchCount = $countMatchingFiches(static function (array $ficheSummary) use ($filterValue) {
                return (string)($ficheSummary['_date_only'] ?? '') === $filterValue;
            });
            break;

        case 'fiche':
            $needle = $normalizeSearch($filterValue);
            $matchCount = $countMatchingFiches(static function (array $ficheSummary) use ($containsSearch, $needle) {
                return $containsSearch([
                    (string)($ficheSummary['num_fiche'] ?? ''),
                    (string)($ficheSummary['designation_libelle'] ?? ''),
                    (string)($ficheSummary['precision_libelle'] ?? ''),
                    (string)($ficheSummary['details_demande'] ?? ''),
                ], $needle);
            });
            break;
    }

    $activeFilterSummaries[] = [
        'label' => $filterSummaryLabels[$filterKey] ?? $filterKey,
        'value' => $summaryValue,
        'count' => $matchCount,
    ];
}

// Agrégats rapides pour les jauges: progression de décaissement par ligne de déboursé
$debourseLineProgress = [];
foreach (($detailsChantier['fiches'] ?? []) as $ficheForGauge) {
    $ligneDebourseId = (int)($ficheForGauge['ligne_debourse_id'] ?? 0);
    if ($ligneDebourseId <= 0) {
        continue;
    }

    if (!isset($debourseLineProgress[$ligneDebourseId])) {
        $debourseLineProgress[$ligneDebourseId] = [
            'total_decaisse' => 0.0,
            'total_attente' => 0.0,
            'count' => 0,
        ];
    }

    $amount = is_numeric($ficheForGauge['montant_fiche'] ?? null) ? (float)$ficheForGauge['montant_fiche'] : 0.0;
    if ((int)($ficheForGauge['decaisse'] ?? 0) === 1) {
        $debourseLineProgress[$ligneDebourseId]['total_decaisse'] += $amount;
    } else {
        $debourseLineProgress[$ligneDebourseId]['total_attente'] += $amount;
    }
    $debourseLineProgress[$ligneDebourseId]['count']++;
}

// Mapping pour rattacher proprement fiches <-> ligne de devis via les sous-lignes de déboursé
$debourseIdsByLigneDevis = [];
foreach (($detailsChantier['lignes_debourse'] ?? []) as $ligneDebMap) {
    $ligneDevisId = (int)($ligneDebMap['ligne_devis_source_id'] ?? 0);
    $ligneDebId = (int)($ligneDebMap['id'] ?? 0);
    if ($ligneDevisId <= 0 || $ligneDebId <= 0) {
        continue;
    }
    if (!isset($debourseIdsByLigneDevis[$ligneDevisId])) {
        $debourseIdsByLigneDevis[$ligneDevisId] = [];
    }
    $debourseIdsByLigneDevis[$ligneDevisId][] = $ligneDebId;
}

$toFloat = static function ($value): float {
    return is_numeric($value) ? (float)$value : 0.0;
};

$totalDebourseRaw = 0.0;
$budgetTotalRaw = 0.0;
$debourseDisplayValue = 0.0;

$shortenLabel = static function ($value, int $max = 40): string {
    $label = trim((string)$value);
    if ($label === '') {
        return 'Sans libelle';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($label, 'UTF-8') > $max
            ? mb_substr($label, 0, $max - 1, 'UTF-8') . '…'
            : $label;
    }

    return strlen($label) > $max
        ? substr($label, 0, $max - 3) . '...'
        : $label;
};

$libChantierSafe = '';
if (isset($detailsChantier['chantier']) && is_array($detailsChantier['chantier']) && isset($detailsChantier['chantier']['lib_chantier'])) {
    $libChantierSafe = (string)$detailsChantier['chantier']['lib_chantier'];
}

$globalDevis = $toFloat($detailsChantier['devis']['budget_devis'] ?? 0);
if ($globalDevis <= 0 && !empty($detailsChantier['lignes_devis'])) {
    foreach ($detailsChantier['lignes_devis'] as $ligneDevisGlobal) {
        $globalDevis += $toFloat($ligneDevisGlobal['total'] ?? 0);
    }
}
if ($globalDevis <= 0) {
    $globalDevis = $toFloat($detailsChantier['totaux']['budget_total'] ?? 0);
}

$totalDebourseRaw = $toFloat($detailsChantier['totaux']['total_debourse'] ?? 0);
$budgetTotalRaw = $toFloat($detailsChantier['totaux']['budget_total'] ?? 0);
$debourseDisplayValue = $totalDebourseRaw > 0 ? $totalDebourseRaw : $budgetTotalRaw;

$codeChantierSafe = '';
if (isset($detailsChantier['chantier']) && is_array($detailsChantier['chantier']) && isset($detailsChantier['chantier']['code'])) {
    $codeChantierSafe = (string)$detailsChantier['chantier']['code'];
}

$buildAnalyticsMetric = static function (string $id, string $label, float $devis, float $decaisse, float $debourse, string $scope, array $extra = []) use ($shortenLabel): array {
    $marge = max($devis - $decaisse, 0);
    $depassement = max($decaisse - $devis, 0);
    $margeActuelle = $devis - $decaisse;
    $margePrevisionnelle = $devis - $debourse;
    $ecartMarge = $margeActuelle - $margePrevisionnelle; // = debourse - decaisse
    $ecartMargePct = $devis > 0 ? ($ecartMarge / $devis) * 100 : 0;
    $progressBaseKind = $debourse > 0 ? 'debourse' : ($devis > 0 ? 'devis' : 'decaisse');
    $progressBase = $progressBaseKind === 'debourse'
        ? $debourse
        : ($progressBaseKind === 'devis' ? $devis : max($decaisse, 1));

    $ratioDecaisseSurDeboursePct = $debourse > 0 ? ($decaisse / $debourse) * 100 : null;
    $ratioDebourseSurDevisPct = $devis > 0 ? ($debourse / $devis) * 100 : null;
    $ratioDecaisseSurDevisPct = $devis > 0 ? ($decaisse / $devis) * 100 : null;

    $progress = $progressBase > 0 ? ($decaisse / $progressBase) * 100 : 0;
    $resteSurProgressBase = $progressBase - $decaisse;

    return array_merge([
        'id' => $id,
        'scope' => $scope,
        'label' => $shortenLabel($label, 38),
        'fullLabel' => trim($label) !== '' ? $label : 'Element',
        'devis' => round($devis, 2),
        'decaisse' => round($decaisse, 2),
        'debourse' => round($debourse, 2),
        'marge' => round($marge, 2),
        'depassement' => round($depassement, 2),
        'marge_actuelle' => round($margeActuelle, 2),
        'marge_previsionnelle' => round($margePrevisionnelle, 2),
        'ecart_marge' => round($ecartMarge, 2),
        'ecart_marge_pct' => round($ecartMargePct, 2),
        'ratio_decaisse_sur_debourse_pct' => $ratioDecaisseSurDeboursePct !== null ? round($ratioDecaisseSurDeboursePct, 1) : null,
        'ratio_debourse_sur_devis_pct' => $ratioDebourseSurDevisPct !== null ? round($ratioDebourseSurDevisPct, 1) : null,
        'ratio_decaisse_sur_devis_pct' => $ratioDecaisseSurDevisPct !== null ? round($ratioDecaisseSurDevisPct, 1) : null,
        'progress_base_kind' => $progressBaseKind,
        'progress_base' => round($progressBase, 2),
        'reste_sur_progress_base' => round($resteSurProgressBase, 2),
        'progress' => round(min(max($progress, 0), 999), 1),
    ], $extra);
};

$globalMetric = $buildAnalyticsMetric(
    'global',
    $libChantierSafe !== '' ? $libChantierSafe : 'Chantier',
    $globalDevis,
    $toFloat($detailsChantier['totaux']['montant_paye'] ?? 0),
    $toFloat($detailsChantier['totaux']['total_debourse'] ?? 0),
    'global',
    [
        'fiches_count' => count($detailsChantier['fiches'] ?? []),
        'zones_count' => count($detailsChantier['zones'] ?? []),
        'lignes_count' => count($detailsChantier['lignes_devis'] ?? []),
    ]
);

$zoneMetrics = [];
$zoneIndex = 0;
foreach (($detailsChantier['zones'] ?? []) as $zoneName => $lignesZone) {
    $zoneTotalDevis = 0.0;
    $zoneTotalDebourse = 0.0;
    $zoneTotalDecaisse = 0.0;

    foreach ($lignesZone as $ligneZone) {
        $zoneTotalDevis += $toFloat($ligneZone['total'] ?? 0);
        $zoneTotalDebourse += $toFloat($ligneZone['montant_debourse_reel'] ?? 0);
        $zoneTotalDecaisse += $toFloat($ligneZone['montant_paye'] ?? 0);
    }

    $zoneMetrics[] = $buildAnalyticsMetric(
        'zone_' . $zoneIndex,
        (string)$zoneName,
        $zoneTotalDevis,
        $zoneTotalDecaisse,
        $zoneTotalDebourse,
        'zone',
        ['lignes_count' => count($lignesZone)]
    );
    $zoneIndex++;
}

$lineMetrics = [];
foreach (($detailsChantier['lignes_devis'] ?? []) as $lineIndex => $ligneAnalytique) {
    $lineMetrics[] = $buildAnalyticsMetric(
        'line_' . $lineIndex,
        (string)($ligneAnalytique['designation'] ?? ('Ligne ' . ($lineIndex + 1))),
        $toFloat($ligneAnalytique['total'] ?? 0),
        $toFloat($ligneAnalytique['montant_paye'] ?? 0),
        $toFloat($ligneAnalytique['montant_debourse_reel'] ?? 0),
        'line',
        ['zone' => (string)($ligneAnalytique['zone'] ?? '')]
    );
}

$timelineMap = [];
foreach (($detailsChantier['fiches'] ?? []) as $ficheTimeline) {
    if (empty($ficheTimeline['date_creat_fiche'])) {
        continue;
    }

    $timestamp = strtotime((string)$ficheTimeline['date_creat_fiche']);
    if ($timestamp === false) {
        continue;
    }

    $dateKey = date('Y-m-d', $timestamp);
    if (!isset($timelineMap[$dateKey])) {
        $timelineMap[$dateKey] = [
            'label' => date('d/m', $timestamp),
            'decaisse' => 0.0,
            'fiches' => 0,
        ];
    }

    if ((int)($ficheTimeline['decaisse'] ?? 0) === 1) {
        $timelineMap[$dateKey]['decaisse'] += $toFloat($ficheTimeline['montant_fiche'] ?? 0);
    }
    $timelineMap[$dateKey]['fiches']++;
}

ksort($timelineMap);
$timelineLabels = [];
$timelineCumulative = [];
$timelineBudget = [];
$cumulativeDecaisse = 0.0;

foreach ($timelineMap as $timelineItem) {
    $cumulativeDecaisse += $toFloat($timelineItem['decaisse'] ?? 0);
    $timelineLabels[] = $timelineItem['label'];
    $timelineCumulative[] = round($cumulativeDecaisse, 2);
    $timelineBudget[] = round($globalDevis, 2);
}

if (empty($timelineLabels)) {
    $timelineLabels[] = 'Aucune fiche';
    $timelineCumulative[] = 0;
    $timelineBudget[] = round($globalDevis, 2);
}

$analyticsPayload = [
    'global' => $globalMetric,
    'zones' => $zoneMetrics,
    'lines' => $lineMetrics,
    'timeline' => [
        'labels' => $timelineLabels,
        'cumulative' => $timelineCumulative,
        'budget' => $timelineBudget,
    ],
];

$pageTitle = 'Détail Budgétaire - ' . $libChantierSafe;
$activeMenu = 'chantiers';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<link rel="stylesheet" href="../assets/css/budget-workspace.css?v=20261126a">
<div class="budget-workspace min-h-screen">
    <div class="container mx-auto py-10 px-4">
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-green-800 shadow-sm">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars((string)$_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-red-800 shadow-sm">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars((string)$_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <!-- En-tête avec navigation -->
        <div class="flex items-center justify-between mb-8">
            <div>
                <a href="selection_budgets_chantiers.php" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 mb-4">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Retour aux chantiers
                </a>
                <h1 class="budget-heading">
                    Détail Budgétaire
                </h1>
                <p class="text-xl text-gray-600 mt-2"><?= htmlspecialchars($libChantierSafe ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                <?php if ($codeChantierSafe !== ''): ?>
                    <p class="text-sm text-gray-500 mt-1">Chantier: <span class="font-semibold"><?= htmlspecialchars($codeChantierSafe, ENT_QUOTES, 'UTF-8') ?></span> (id=<?= htmlspecialchars((string)$chantierId, ENT_QUOTES, 'UTF-8') ?>)</p>
                <?php endif; ?>
            </div>
            <div class="flex gap-3">
                <button onclick="window.location.href='detail_travaux_chantier.php?chantier_id=<?= urlencode((string)$chantierId) ?>'" class="px-4 py-2 bg-cyan-600 text-white rounded-lg hover:bg-cyan-700 transition-colors">
                    <i class="fas fa-calendar-alt mr-2"></i>Travaux
                </button>
                <button onclick="exportDetailChantier()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                    <i class="fas fa-file-excel mr-2"></i>Excel
                </button>
                <button onclick="exportPDFChantier()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors">
                    <i class="fas fa-file-pdf mr-2"></i>PDF
                </button>
                <button onclick="printChantier()" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors">
                    <i class="fas fa-print mr-2"></i>Imprimer
                </button>
            </div>
        </div>

        <div class="bg-white/95 rounded-3xl shadow-xl border border-slate-200 p-6 mb-8">
            <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-4 mb-5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-400">Filtres détaillés</p>
                    <h2 class="text-2xl font-bold text-slate-900 mt-1">Recherche ciblée dans le chantier</h2>
                    <p class="text-sm text-slate-500 mt-1">Filtre toute la page par zone, déboursé, ligne de déboursé, devis, ligne de devis ou fiche réelle. Les zones, montants et tableaux se recalculent sur le sous-ensemble trouvé.</p>
                </div>
                <?php if ($hasActiveDetailFilters): ?>
                    <div class="rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                        <div class="font-semibold">Mode filtré actif</div>
                        <div class="mt-1 text-xs text-indigo-600">Les indicateurs ci-dessous reflètent uniquement le périmètre trouvé.</div>
                    </div>
                <?php endif; ?>
            </div>

            <form method="get" class="space-y-4">
                <input type="hidden" name="chantier_id" value="<?= htmlspecialchars((string)$chantierId, ENT_QUOTES, 'UTF-8') ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Zone</label>
                        <input type="text" name="filter_zone" list="filter-zone-list" value="<?= htmlspecialchars($detailFilters['zone'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" placeholder="Ex: Gros oeuvre">
                        <datalist id="filter-zone-list">
                            <?php foreach ($zoneSuggestions as $zoneSuggestion): ?>
                                <option value="<?= htmlspecialchars($zoneSuggestion, ENT_QUOTES, 'UTF-8') ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Déboursé</label>
                        <input type="text" name="filter_debourse" list="filter-debourse-list" value="<?= htmlspecialchars($detailFilters['debourse'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" placeholder="Ex: Déboursé #15">
                        <datalist id="filter-debourse-list">
                            <?php foreach ($debourseSuggestions as $debourseSuggestion): ?>
                                <option value="<?= htmlspecialchars($debourseSuggestion, ENT_QUOTES, 'UTF-8') ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Ligne de déboursé</label>
                        <input type="text" name="filter_ligne_debourse" list="filter-ligne-debourse-list" value="<?= htmlspecialchars($detailFilters['ligne_debourse'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" placeholder="Ex: Achat ciment">
                        <datalist id="filter-ligne-debourse-list">
                            <?php foreach ($ligneDebourseSuggestions as $ligneDebourseSuggestion): ?>
                                <option value="<?= htmlspecialchars($ligneDebourseSuggestion, ENT_QUOTES, 'UTF-8') ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Devis</label>
                        <input type="text" name="filter_devis" value="<?= htmlspecialchars($detailFilters['devis'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" placeholder="Ex: <?= htmlspecialchars((string)($rawDetailsChantier['devis']['numero_devis'] ?? 'DEV-001'), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Ligne de devis</label>
                        <input type="text" name="filter_ligne_devis" list="filter-ligne-devis-list" value="<?= htmlspecialchars($detailFilters['ligne_devis'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" placeholder="Ex: Réhabilitation toiture">
                        <datalist id="filter-ligne-devis-list">
                            <?php foreach ($ligneDevisSuggestions as $ligneDevisSuggestion): ?>
                                <option value="<?= htmlspecialchars($ligneDevisSuggestion, ENT_QUOTES, 'UTF-8') ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Bénéficiaire</label>
                        <input type="text" name="filter_beneficiaire" list="filter-beneficiaire-list" value="<?= htmlspecialchars($detailFilters['beneficiaire'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" placeholder="Ex: Jean Dupont">
                        <datalist id="filter-beneficiaire-list">
                            <?php foreach ($beneficiaireSuggestions as $beneficiaireSuggestion): ?>
                                <option value="<?= htmlspecialchars($beneficiaireSuggestion, ENT_QUOTES, 'UTF-8') ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Date précise</label>
                        <input type="date" name="filter_date_precise" value="<?= htmlspecialchars($detailFilters['date_precise'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Fiche réelle</label>
                        <input type="text" name="filter_fiche" list="filter-fiche-list" value="<?= htmlspecialchars($detailFilters['fiche'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400" placeholder="Ex: FCH-000123 ou libellé">
                        <datalist id="filter-fiche-list">
                            <?php foreach ($ficheSuggestions as $ficheSuggestion): ?>
                                <option value="<?= htmlspecialchars($ficheSuggestion, ENT_QUOTES, 'UTF-8') ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="inline-flex items-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition-colors">
                        <i class="fas fa-filter mr-2"></i>Actualiser la page
                    </button>
                    <a href="detail_budgetaire_chantier.php?chantier_id=<?= urlencode((string)$chantierId) ?>" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
                        <i class="fas fa-rotate-left mr-2"></i>Réinitialiser les filtres
                    </a>
                </div>
            </form>

            <?php if ($hasActiveDetailFilters): ?>
                <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-3">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Résumé des filtres actifs</p>
                            <p class="text-xs text-slate-500">Chaque tuile montre combien de fiches correspondent à ce critère, puis le total retenu par la combinaison complète.</p>
                        </div>
                        <div class="inline-flex items-center rounded-2xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 border border-slate-200">
                            <i class="fas fa-receipt mr-2 text-indigo-500"></i>
                            Total retenu: <?= number_format(count($detailsChantier['fiches'] ?? []), 0, ',', ' ') ?> fiche(s)
                        </div>
                    </div>

                    <?php if (!empty($activeFilterSummaries)): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                            <?php foreach ($activeFilterSummaries as $filterSummary): ?>
                                <div class="rounded-2xl border border-indigo-100 bg-white px-4 py-3 shadow-sm">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-indigo-500"><?= htmlspecialchars((string)$filterSummary['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-1 text-sm font-semibold text-slate-900 break-words"><?= htmlspecialchars((string)$filterSummary['value'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="mt-2 text-xs text-slate-500">
                                        <?= number_format((int)$filterSummary['count'], 0, ',', ' ') ?> fiche(s) trouvée(s) pour ce filtre
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Informations générales du chantier -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-amber-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Déboursé Total</p>
                        <p class="text-2xl font-bold text-amber-600">
                            <?= number_format($debourseDisplayValue, 0, ',', ' ') ?> F
                        </p>
                        <?php if (!empty($detailsChantier['devis'])): ?>
                            <p class="text-xs text-gray-500">
                                Déboursé saisi: <?= number_format($totalDebourseRaw, 0, ',', ' ') ?> F — Devis N° <?= htmlspecialchars($detailsChantier['devis']['numero_devis'] ?? '') ?>
                                <?php if (!empty($detailsChantier['devis']['libelle_devis'])): ?>
                                    <span class="text-gray-400">— <?= htmlspecialchars((string)$detailsChantier['devis']['libelle_devis']) ?></span>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-amber-100 p-3 rounded-xl">
                        <i class="fas fa-file-contract text-2xl text-amber-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-rose-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Montant Payé</p>
                        <p class="text-2xl font-bold text-rose-600">
                            <?= number_format($detailsChantier['totaux']['montant_paye'], 0, ',', ' ') ?> F
                        </p>
                        <p class="text-xs text-gray-500"><?= $detailsChantier['stats_fiches']['fiches_decaissees'] ?> fiches décaissées</p>
                    </div>
                    <div class="bg-rose-100 p-3 rounded-xl">
                        <i class="fas fa-money-bill-wave text-2xl text-rose-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-orange-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Reste à payer</p>
                        <p class="text-2xl font-bold text-orange-600">
                            <?= number_format($detailsChantier['totaux']['montant_restant'], 0, ',', ' ') ?> F
                        </p>
                        <p class="text-xs text-gray-500">À décaisser</p>
                    </div>
                    <div class="bg-orange-100 p-3 rounded-xl">
                        <i class="fas fa-hourglass-half text-2xl text-orange-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-indigo-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Progression</p>
                        <p class="text-2xl font-bold text-indigo-600">
                            <?= number_format($detailsChantier['totaux']['pourcentage_realise'], 1) ?>%
                        </p>
                        <p class="text-xs text-gray-500">Réalisé</p>
                    </div>
                    <div class="bg-indigo-100 p-3 rounded-xl">
                        <i class="fas fa-chart-pie text-2xl text-indigo-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
            <div class="xl:col-span-2 bg-white/95 rounded-3xl shadow-xl border border-slate-200 p-6">
                <div class="flex flex-col xl:flex-row xl:items-start xl:justify-between gap-4 mb-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-400">Pilotage visuel</p>
                        <h2 class="text-2xl font-bold text-slate-900 mt-1">Comparatif devis vs décaissé</h2>
                        <p class="text-sm text-slate-500 mt-1">Lecture globale du chantier ou zoom par zone et par ligne, avec bascule entre vue éclatée et lecture condensée de la marge.</p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                            <span class="font-semibold text-slate-500">Niveau</span>
                            <select id="analyticsScope" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                                <option value="global">Chantier global</option>
                                <option value="zones">Par zone</option>
                                <option value="lignes">Par ligne de devis</option>
                            </select>
                        </label>
                        <div class="inline-flex rounded-2xl border border-slate-200 bg-slate-50 p-1">
                            <button type="button" data-band-mode="eclatee" class="band-mode-btn rounded-xl px-3 py-2 text-sm font-semibold text-slate-700 transition-colors bg-white shadow-sm">Vue éclatée</button>
                            <button type="button" data-band-mode="condensee" class="band-mode-btn rounded-xl px-3 py-2 text-sm font-semibold text-slate-500 transition-colors">Vue condensée</button>
                        </div>
                    </div>
                </div>

                <div class="mb-3 flex flex-wrap items-center gap-2 text-sm text-slate-600">
                    <span class="font-semibold text-slate-500">Sélection :</span>
                    <select id="analyticsSelectedSelect" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-900 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        <option value="global">Vue globale du chantier</option>
                    </select>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                    <div class="rounded-2xl border border-indigo-100 bg-indigo-50/80 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-500">Devis</p>
                        <p id="analyticsDevisValue" class="mt-2 text-lg font-bold text-slate-900">0 F</p>
                        <p id="analyticsDevisDetails" class="mt-2 whitespace-pre-line text-xs leading-5 text-slate-500">—</p>
                    </div>
                    <div class="rounded-2xl border border-rose-100 bg-rose-50/80 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-rose-600">Décaissé</p>
                        <p id="analyticsDecaisseValue" class="mt-2 text-lg font-bold text-slate-900">0 F</p>
                        <p id="analyticsDecaisseDetails" class="mt-2 whitespace-pre-line text-xs leading-5 text-slate-500">—</p>
                    </div>
                    <div class="rounded-2xl border border-emerald-100 bg-emerald-50/80 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Marges</p>
                        <div class="mt-2 space-y-1 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-slate-500">Actuelle</span>
                                <span id="analyticsMargeActuelleValue" class="font-bold text-slate-900">0 F</span>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-slate-500">Prévisionnelle</span>
                                <span id="analyticsMargePrevisionnelleValue" class="font-bold text-slate-900">0 F</span>
                            </div>
                            <div class="mt-1 flex items-center justify-between gap-2 border-t border-emerald-100 pt-1">
                                <span class="text-slate-500">Écart</span>
                                <span id="analyticsEcartMargesValue" class="font-bold text-slate-900">0 F (0%)</span>
                            </div>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">Actuelle = devis - décaissé · Prévisionnelle = devis - déboursé · Écart = actuelle - prévisionnelle (en % du devis)</p>
                    </div>
                    <div class="rounded-2xl border border-sky-100 bg-sky-50/80 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-500">Taux réalisé</p>
                        <p id="analyticsProgressValue" class="mt-2 text-lg font-bold text-slate-900">0%</p>
                        <p id="analyticsProgressDetails" class="mt-2 whitespace-pre-line text-xs leading-5 text-slate-500">—</p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-3">
                    <div>
                        <h3 id="bandChartTitle" class="text-base font-semibold text-slate-900">Lecture globale du chantier</h3>
                        <p id="bandChartCaption" class="text-sm text-slate-500">Le comparatif juxtapose le devis prévu et le montant déjà décaissé.</p>
                    </div>
                    <div class="inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-500">
                        <i class="fas fa-layer-group text-indigo-500"></i>
                        <span id="bandChartHint">Vue compacte, pensée pour une lecture de direction.</span>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-100 budget-soft p-3">
                    <div id="bandChartViewport" style="max-height: 420px; overflow-y: auto; padding-right: 4px;">
                        <div id="bandChartCanvasWrap" class="relative" style="height: 220px; min-height: 220px;">
                            <canvas id="budgetBandChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white/95 rounded-3xl shadow-xl border border-slate-200 p-6">
                <div class="flex items-start justify-between gap-3 mb-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-400">Camembert</p>
                        <h2 class="text-2xl font-bold text-slate-900 mt-1">Lecture de marge</h2>
                        <p class="text-sm text-slate-500 mt-1">Visualise la part décaissée + la marge prévisionnelle (ou le dépassement prévu). La marge actuelle est affichée en repère séparé.</p>
                    </div>
                    <div class="rounded-2xl bg-emerald-50 p-3 text-emerald-600">
                        <i class="fas fa-chart-pie text-xl"></i>
                    </div>
                </div>

                <label class="block mb-4 text-sm text-slate-600">
                    <span class="mb-2 block font-semibold text-slate-500">Élément analysé</span>
                    <select id="analyticsFocus" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-400"></select>
                </label>

                <div class="rounded-3xl border border-slate-100 budget-soft p-4">
                    <div class="relative mx-auto" style="height: 250px; max-width: 280px;">
                        <canvas id="marginDonutChart"></canvas>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 mt-5">
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Décaissé effectif</p>
                        <p id="donutDecaisseValue" class="mt-1 text-sm font-semibold text-slate-900">0 F</p>
                    </div>
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-3">
                        <p id="donutDeltaLabel" class="text-xs uppercase tracking-wide text-slate-400">Marge prévisionnelle</p>
                        <p id="donutDeltaValue" class="mt-1 text-sm font-semibold text-slate-900">0 F</p>
                    </div>
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-3">
                        <p id="donutForecastLabel" class="text-xs uppercase tracking-wide text-slate-400">Marge actuelle</p>
                        <p id="donutForecastValue" class="mt-1 text-sm font-semibold text-slate-900">0 F</p>
                    </div>
                    <div class="rounded-2xl border border-slate-100 bg-slate-50 p-3">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Marge %</p>
                        <div class="mt-1 flex items-center justify-between gap-2 text-sm">
                            <span class="text-slate-500">Actuelle</span>
                            <span id="donutRatioCurrentValue" class="font-semibold text-slate-900">0%</span>
                        </div>
                        <div class="mt-1 flex items-center justify-between gap-2 text-sm">
                            <span class="text-slate-500">Prévue</span>
                            <span id="donutRatioForecastValue" class="font-semibold text-slate-900">0%</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white/95 rounded-3xl shadow-xl border border-slate-200 p-6 mb-8">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.25em] text-slate-400">Progression</p>
                    <h2 class="text-2xl font-bold text-slate-900 mt-1">Trajectoire de réalisation du chantier</h2>
                    <p class="text-sm text-slate-500 mt-1">Suit la montée du décaissé cumulé, avec repères devis, déboursé prévu, marge actuelle et marge prévisionnelle.</p>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 lg:w-auto">
                    <div class="rounded-2xl border border-violet-100 bg-violet-50 px-4 py-3">
                        <p class="text-xs uppercase tracking-wide text-violet-500">Fiches</p>
                        <p class="text-lg font-bold text-slate-900"><?= number_format(count($detailsChantier['fiches'] ?? []), 0, ',', ' ') ?></p>
                        <p class="mt-1 text-xs text-slate-500">Décaissées: <?= number_format((int)($detailsChantier['stats_fiches']['fiches_decaissees'] ?? 0), 0, ',', ' ') ?></p>
                    </div>
                    <div class="rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3">
                        <p class="text-xs uppercase tracking-wide text-indigo-500">Objectif devis</p>
                        <p class="text-lg font-bold text-slate-900"><?= number_format($globalDevis, 0, ',', ' ') ?> F</p>
                        <p class="mt-1 text-xs text-slate-500">Référence chantier</p>
                    </div>
                    <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3">
                        <p class="text-xs uppercase tracking-wide text-amber-600">Déboursé prévu</p>
                        <p class="text-lg font-bold text-slate-900"><?= number_format((float)($globalMetric['debourse'] ?? 0), 0, ',', ' ') ?> F</p>
                        <?php $ratioDebourseSurDevisGlobal = $globalDevis > 0 ? (((float)($globalMetric['debourse'] ?? 0) / (float)$globalDevis) * 100) : 0; ?>
                        <p class="mt-1 text-xs text-slate-500">Soit <?= number_format($ratioDebourseSurDevisGlobal, 1, ',', ' ') ?>% du devis</p>
                    </div>
                    <?php
                    $margeActuelleGlobal = (float)($globalMetric['marge_actuelle'] ?? 0);
                    $isOverActuelGlobal = $margeActuelleGlobal < 0;
                    $margeActuelleDisplay = abs($margeActuelleGlobal);
                    $ratioMargeActuelleGlobal = $globalDevis > 0 ? (($margeActuelleDisplay / (float)$globalDevis) * 100) : 0;
                    ?>
                    <div class="rounded-2xl border <?= $isOverActuelGlobal ? 'border-rose-100 bg-rose-50' : 'border-emerald-100 bg-emerald-50' ?> px-4 py-3">
                        <p class="text-xs uppercase tracking-wide <?= $isOverActuelGlobal ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $isOverActuelGlobal ? 'Dépassement actuel' : 'Marge actuelle' ?></p>
                        <p class="text-lg font-bold text-slate-900"><?= number_format($margeActuelleDisplay, 0, ',', ' ') ?> F</p>
                        <p class="mt-1 text-xs text-slate-500">Soit <?= number_format($ratioMargeActuelleGlobal, 1, ',', ' ') ?>% du devis</p>
                    </div>

                    <?php
                    $margePrevGlobal = (float)($globalMetric['marge_previsionnelle'] ?? 0);
                    $isOverPrevGlobal = $margePrevGlobal < 0;
                    $margePrevDisplay = abs($margePrevGlobal);
                    $ratioMargePrevGlobal = $globalDevis > 0 ? (($margePrevDisplay / (float)$globalDevis) * 100) : 0;
                    ?>
                    <div class="rounded-2xl border <?= $isOverPrevGlobal ? 'border-rose-100 bg-rose-50' : 'border-emerald-100 bg-emerald-50' ?> px-4 py-3">
                        <p class="text-xs uppercase tracking-wide <?= $isOverPrevGlobal ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $isOverPrevGlobal ? 'Dépassement prévu' : 'Marge prévisionnelle' ?></p>
                        <p class="text-lg font-bold text-slate-900"><?= number_format($margePrevDisplay, 0, ',', ' ') ?> F</p>
                        <p class="mt-1 text-xs text-slate-500">Soit <?= number_format($ratioMargePrevGlobal, 1, ',', ' ') ?>% du devis</p>
                    </div>
                </div>
            </div>

            <div class="rounded-3xl border border-slate-100 budget-soft p-4">
                <div class="relative" style="height: 300px;">
                    <canvas id="progressTimelineChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Détail des zones et lignes de devis -->
        <?php if (!empty($detailsChantier['zones'])): ?>
            <div class="budget-detail-table bg-white rounded-2xl shadow-xl mb-8 border border-gray-100">
                <div class="px-6 py-4 budget-primary">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-list-alt mr-3"></i>
                        Détail par Zone et Ligne de Devis
                    </h2>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-2 py-4 text-center text-xs font-medium text-gray-500 uppercase tracking-wider w-12"></th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Désignation</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Montant Devis</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Montant Déboursé</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Montant Payé</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Restant</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progression</th>
                                <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php $globalIndex = 0; ?>
                            <?php foreach ($detailsChantier['zones'] as $zoneName => $lignesZone): ?>
                                <?php
                                // Calculer les totaux pour la zone
                                $zoneTotalDevis = array_sum(array_column($lignesZone, 'total'));
                                $zoneTotalDebourse = array_sum(array_column($lignesZone, 'montant_debourse_reel'));
                                $zoneTotalPaye = array_sum(array_column($lignesZone, 'montant_paye'));
                                $zoneRestant = $zoneTotalDebourse - $zoneTotalPaye;
                                $zonePourcentage = $zoneTotalDebourse > 0 ? ($zoneTotalPaye / $zoneTotalDebourse) * 100 : 0;
                                $zoneIsOver = $zonePourcentage > 100;
                                $zoneOverPct = $zoneIsOver ? ($zonePourcentage - 100) : 0;
                                $zoneProgressClass = $zoneIsOver ? 'bg-gradient-to-r from-rose-600 to-red-700' : 'budget-primary';
                                ?>
                                <!-- Ligne de zone -->
                                <tr class="bg-indigo-50 hover:bg-indigo-100 transition-colors">
                                    <td class="px-2 py-4 text-center">
                                        <button type="button"
                                            onclick="toggleExpand(this, 'zone-expand-<?= $globalIndex ?>')"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded-full budget-primary text-white  transition-all transform hover:scale-110 shadow-md">
                                            <i class="fas fa-plus text-xs transition-transform duration-300"></i>
                                        </button>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-lg font-bold text-indigo-800">🏗️ <?= htmlspecialchars($zoneName) ?></div>
                                        <div class="text-sm text-indigo-600"><?= count($lignesZone) ?> ligne(s) de devis</div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-indigo-800">
                                        <?= number_format($zoneTotalDevis, 0, ',', ' ') ?> F
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-amber-600">
                                        <?= number_format($zoneTotalDebourse, 0, ',', ' ') ?> F
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-rose-600">
                                        <?= number_format($zoneTotalPaye, 0, ',', ' ') ?> F
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-orange-600">
                                        <?php if ($zoneRestant < 0): ?>
                                            <span class="text-red-700">Dépassement: <?= number_format(abs($zoneRestant), 0, ',', ' ') ?> F</span>
                                        <?php else: ?>
                                            <?= number_format($zoneRestant, 0, ',', ' ') ?> F
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-full bg-gray-200 rounded-full h-3 mr-3">
                                                <div class="<?= $zoneProgressClass ?> h-3 rounded-full"
                                                    style="width: <?= min($zonePourcentage, 100) ?>%"></div>
                                            </div>
                                            <span class="text-sm font-bold <?= $zoneIsOver ? 'text-red-700' : 'text-indigo-800' ?> min-w-max">
                                                <?= number_format($zonePourcentage, 1) ?>%
                                                <?php if ($zoneIsOver): ?>
                                                    <span class="ml-1 text-xs font-semibold">(+<?= number_format($zoneOverPct, 1) ?>%)</span>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($zoneIsOver): ?>
                                                <span class="ml-2 inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-700">DÉPASSEMENT</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-3 py-1 text-sm font-semibold rounded-full bg-indigo-100 text-indigo-800">
                                            Zone
                                        </span>
                                    </td>
                                </tr>

                                <!-- Lignes d'expansion de zone -->
                                <tr id="zone-expand-<?= $globalIndex ?>" class="hidden">
                                    <td colspan="8" class="px-0 py-0">
                                        <div class="budget-soft p-4">
                                            <table class="min-w-full">
                                                <tbody>
                                                    <?php foreach ($lignesZone as $indexLigne => $ligne): ?>
                                                        <?php
                                                        $montantRestant = $ligne['montant_debourse_reel'] - $ligne['montant_paye'];
                                                        $total = floatval($ligne['total'] ?? 0);
                                                        $debourse = floatval($ligne['montant_debourse_reel'] ?? 0);
                                                        $montantPaye = floatval($ligne['montant_paye'] ?? 0);
                                                        $pourcentageRealise = $debourse > 0 ? ($montantPaye / $debourse) * 100 : 0;

                                                        $ligneDevisId = (int)($ligne['id'] ?? 0);
                                                        $debourseIdsForLine = $ligneDevisId > 0 ? ($debourseIdsByLigneDevis[$ligneDevisId] ?? []) : [];
                                                        $debourseIdsLookup = $debourseIdsForLine ? array_flip(array_map('intval', $debourseIdsForLine)) : [];

                                                        $fichesRattachees = array_filter($detailsChantier['fiches'] ?? [], function ($fiche) use ($ligneDevisId, $debourseIdsLookup) {
                                                            if ($ligneDevisId > 0 && (int)($fiche['ligne_devis_source_id'] ?? 0) === $ligneDevisId) {
                                                                return true;
                                                            }
                                                            $ligneDebId = (int)($fiche['ligne_debourse_id'] ?? 0);
                                                            return $ligneDebId > 0 && isset($debourseIdsLookup[$ligneDebId]);
                                                        });

                                                        $fichesFallback = array_filter($detailsChantier['fiches'] ?? [], function ($fiche) use ($ligne) {
                                                            // Fallback pour les fiches sans lien resolu ou avec ligne de debourse supprimee
                                                            $hasResolvedLink = !empty($fiche['ligne_devis_source_id']) || !empty($fiche['ligne_debourse_designation']);
                                                            if ($hasResolvedLink) return false;
                                                            return stripos($fiche['designation_fiche'] ?? '', $ligne['designation']) !== false ||
                                                                stripos($ligne['designation'], $fiche['designation_fiche'] ?? '') !== false;
                                                        });

                                                        // Fusion sans doublons (par num_fiche)
                                                        $fichesFiltrees = [];
                                                        foreach (array_merge($fichesRattachees, $fichesFallback) as $ficheItem) {
                                                            $key = (string)($ficheItem['num_fiche'] ?? '');
                                                            if ($key === '') {
                                                                $fichesFiltrees[] = $ficheItem;
                                                                continue;
                                                            }
                                                            $fichesFiltrees[$key] = $ficheItem;
                                                        }
                                                        $fichesFiltrees = array_values($fichesFiltrees);

                                                        $isOverBudget = $pourcentageRealise > 100;
                                                        $progressBarClass = $isOverBudget
                                                            ? 'bg-red-700'
                                                            : ($pourcentageRealise >= 100 ? 'bg-green-600' : ($pourcentageRealise >= 50 ? 'bg-yellow-600' : 'bg-red-600'));

                                                        ?>
                                                        <tr class="hover:bg-white transition-colors border-l-4 border-indigo-300">
                                                            <td class="px-2 py-4 text-center w-12">
                                                                <button type="button"
                                                                    onclick="toggleExpand(this, 'ligne-expand-<?= $globalIndex ?>-<?= $indexLigne ?>')"
                                                                    class="inline-flex items-center justify-center w-6 h-6 rounded-full budget-primary text-white  transition-all transform hover:scale-110 shadow-sm">
                                                                    <i class="fas fa-plus text-xs transition-transform duration-300"></i>
                                                                </button>
                                                            </td>
                                                            <td class="px-6 py-4">
                                                                <div class="text-sm font-medium text-gray-900 pl-4 break-words whitespace-normal">└─ <?= htmlspecialchars($ligne['designation']) ?></div>
                                                                <?php if ($ligne['groupe']): ?>
                                                                    <div class="text-xs text-gray-500 pl-4">Groupe: <?= htmlspecialchars($ligne['groupe']) ?></div>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                                <?= number_format($ligne['prix'], 0, ',', ' ') ?> F
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <div class="text-sm font-semibold text-amber-600">
                                                                    <?= number_format($ligne['montant_debourse_reel'], 0, ',', ' ') ?> F
                                                                </div>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <div class="text-sm font-semibold text-rose-600">
                                                                    <?= number_format($ligne['montant_paye'], 0, ',', ' ') ?> F
                                                                </div>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <div class="text-sm font-semibold <?= $montantRestant < 0 ? 'text-red-700' : ($montantRestant > 0 ? 'text-orange-600' : 'text-gray-500') ?>">
                                                                    <?php if ($montantRestant < 0): ?>
                                                                        Dépassement: <?= number_format(abs($montantRestant), 0, ',', ' ') ?> F
                                                                    <?php else: ?>
                                                                        <?= number_format($montantRestant, 0, ',', ' ') ?> F
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <div class="flex items-center">
                                                                    <div class="w-full bg-gray-200 rounded-full h-2 mr-3">
                                                                        <div class="<?= $progressBarClass ?> h-2 rounded-full"
                                                                            style="width: <?= min($pourcentageRealise, 100) ?>%"></div>
                                                                    </div>
                                                                    <span class="text-sm font-medium <?= $isOverBudget ? 'text-red-700 font-bold' : 'text-gray-900' ?> min-w-max"><?= number_format($pourcentageRealise, 1) ?>%</span>
                                                                    <?php if ($isOverBudget): ?>
                                                                        <span class="ml-2 inline-flex px-2 py-0.5 text-xs font-semibold rounded-full bg-red-100 text-red-700">DÉPASSEMENT</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </td>
                                                            <td class="px-6 py-4 whitespace-nowrap">
                                                                <?php if ($pourcentageRealise > 100): ?>
                                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                                        ⚠️ Dépassement
                                                                    </span>
                                                                <?php elseif ($pourcentageRealise >= 100): ?>
                                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                                                        ✅ Terminé
                                                                    </span>
                                                                <?php elseif ($pourcentageRealise == 0): ?>
                                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                                        ⏳ En attente
                                                                    </span>
                                                                <?php elseif ($pourcentageRealise < 50): ?>
                                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                                                        🚀 En début
                                                                    </span>
                                                                <?php elseif ($pourcentageRealise < 100): ?>
                                                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                                        🔄 En finition
                                                                    </span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>

                                                        <!-- Ligne d'expansion détails de ligne -->
                                                        <tr id="ligne-expand-<?= $globalIndex ?>-<?= $indexLigne ?>" class="hidden">
                                                            <td colspan="8" class="px-0 py-0">
                                                                <div class="budget-soft p-6 ml-8 border-l-4 border-indigo-500">
                                                                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                                                        <!-- Détails Montant Devis -->
                                                                        <div class="bg-white rounded-xl shadow-sm p-4 border border-indigo-100">
                                                                            <h4 class="flex items-center text-sm font-bold text-indigo-700 mb-3">
                                                                                <i class="fas fa-file-contract mr-2 text-indigo-500"></i>
                                                                                Montant Devis
                                                                            </h4>
                                                                            <p class="mb-3 text-xs text-indigo-500 break-words whitespace-normal"><?= htmlspecialchars($ligne['designation'] ?? '') ?></p>
                                                                            <div class="space-y-2">
                                                                                <div class="flex justify-between text-sm">
                                                                                    <span class="text-gray-600">Prix unitaire:</span>
                                                                                    <span class="font-semibold text-gray-900"><?= number_format($ligne['prix'], 0, ',', ' ') ?> F</span>
                                                                                </div>
                                                                                <div class="flex justify-between text-sm">
                                                                                    <span class="text-gray-600">Quantité:</span>
                                                                                    <span class="font-semibold text-gray-900"><?= number_format($ligne['quantite'], 0, ',', ' ') ?></span>
                                                                                </div>
                                                                                <div class="flex justify-between text-sm">
                                                                                    <span class="text-gray-600">Unité:</span>
                                                                                    <span class="font-semibold text-gray-900"><?= htmlspecialchars($ligne['unite_libelle'] ?? '') ?></span>
                                                                                </div>
                                                                                <div class="border-t border-gray-200 pt-2 mt-2 flex justify-between text-sm font-bold">
                                                                                    <span class="text-indigo-600">Total Devis:</span>
                                                                                    <span class="text-indigo-700"><?= number_format($ligne['total'], 0, ',', ' ') ?> F</span>
                                                                                </div>
                                                                            </div>
                                                                        </div>

                                                                        <!-- Détails Montant Déboursé -->
                                                                        <div class="bg-white rounded-xl shadow-sm p-4 border border-amber-100">
                                                                            <h4 class="flex items-center text-sm font-bold text-amber-700 mb-1">
                                                                                <i class="fas fa-hand-holding-usd mr-2 text-amber-500"></i>
                                                                                <span class="block">Montant Déboursé</span>
                                                                            </h4>
                                                                            <p class="mb-3 text-xs font-semibold text-amber-700">(<?= number_format($ligne['montant_debourse_reel'], 0, ',', ' ') ?> F)</p>
                                                                            <div class="space-y-2 text-sm">
                                                                                <?php
                                                                                $deboursesFound = 0;
                                                                                // Chercher les lignes de déboursé pour cette ligne de devis
                                                                                if (!empty($detailsChantier['lignes_debourse'])) {
                                                                                    foreach ($detailsChantier['lignes_debourse'] as $ligneDeb) {
                                                                                        if ((int) ($ligneDeb['ligne_devis_source_id'] ?? 0) === (int) ($ligne['id'] ?? 0)) {
                                                                                            $deboursesFound++;
                                                                                            $ligneDebId = (int)($ligneDeb['id'] ?? 0);
                                                                                            $montantLigneDeb = is_numeric($ligneDeb['montant'] ?? null) ? (float)$ligneDeb['montant'] : 0.0;
                                                                                            $progressAgg = $ligneDebId > 0 && isset($debourseLineProgress[$ligneDebId]) ? $debourseLineProgress[$ligneDebId] : ['total_decaisse' => 0.0, 'total_attente' => 0.0, 'count' => 0];
                                                                                            $totalDecaisseLigne = (float)($progressAgg['total_decaisse'] ?? 0);
                                                                                            $totalAttenteLigne = (float)($progressAgg['total_attente'] ?? 0);
                                                                                            $resteAPayer = $montantLigneDeb - $totalDecaisseLigne;
                                                                                            $ratio = $montantLigneDeb > 0 ? ($totalDecaisseLigne / $montantLigneDeb) : 0.0;
                                                                                            $pct = max(0.0, $ratio * 100);
                                                                                            $isOver = $montantLigneDeb > 0 && $ratio > 1.0;
                                                                                            $isDone = $montantLigneDeb > 0 && $ratio >= 1.0;
                                                                                            $hasStarted = $totalDecaisseLigne > 0;
                                                                                            $barWidth = min($pct, 100);
                                                                                            $barClass = $isOver ? 'bg-red-700' : ($isDone ? 'bg-green-600' : ($hasStarted ? 'bg-yellow-600' : 'bg-slate-400'));

                                                                                            $dateDebut = (string)($ligneDeb['date_debut'] ?? '');
                                                                                            $dateFin = (string)($ligneDeb['date_fin'] ?? '');
                                                                                            $dateDebutOk = $dateDebut !== '' && $dateDebut !== '0000-00-00';
                                                                                            $dateFinOk = $dateFin !== '' && $dateFin !== '0000-00-00';

                                                                                            echo '<div class="p-3 bg-amber-50 rounded border border-amber-100">';
                                                                                            echo '<div class="flex items-start justify-between gap-2">';
                                                                                            echo '<div class="min-w-0">';
                                                                                            echo '<div class="font-semibold text-gray-800 mb-1 break-words whitespace-normal">' . htmlspecialchars($ligneDeb['designation']) . '</div>';
                                                                                            echo '<div class="text-xs text-gray-500 mb-2">' . htmlspecialchars($ligneDeb['categorie'] ?? '') . '</div>';
                                                                                            echo '</div>';
                                                                                            echo '<div class="text-right">';
                                                                                            echo '<div class="text-xs font-semibold ' . ($isOver ? 'text-red-700' : ($isDone ? 'text-green-700' : 'text-slate-600')) . '">' . ($isOver ? '⚠️ Dépassement' : ($isDone ? '✅ Décaissé' : ($hasStarted ? '🔄 En cours' : '⏳ Non décaissé'))) . '</div>';
                                                                                            echo '</div>';
                                                                                            echo '</div>';

                                                                                            echo '<div class="flex justify-between text-xs mb-2">';
                                                                                            echo '<span class="text-gray-600">Délai:</span>';
                                                                                            if ($dateDebutOk || $dateFinOk) {
                                                                                                echo '<span class="font-semibold text-gray-900">Du ' . htmlspecialchars($dateDebutOk ? $dateDebut : '—') . ' au ' . htmlspecialchars($dateFinOk ? $dateFin : '—') . '</span>';
                                                                                            } else {
                                                                                                echo '<span class="font-semibold text-gray-400">—</span>';
                                                                                            }
                                                                                            echo '</div>';

                                                                                            echo '<div class="flex justify-between text-xs font-bold">';
                                                                                            echo '<span class="text-amber-600">Montant:</span>';
                                                                                            echo '<span class="text-amber-700">' . number_format($montantLigneDeb, 0, ',', ' ') . ' F</span>';
                                                                                            echo '</div>';

                                                                                            // Jauge progression
                                                                                            echo '<div class="mt-3">';
                                                                                            echo '<div class="flex items-center justify-between text-[11px] text-slate-600 mb-1">';
                                                                                            echo '<span>Progression décaissement</span>';
                                                                                            echo '<span class="font-semibold ' . ($isOver ? 'text-red-700' : 'text-slate-700') . '">' . number_format($pct, 1, ',', ' ') . '%</span>';
                                                                                            echo '</div>';
                                                                                            echo '<div class="w-full bg-white rounded-full h-2 border border-amber-100">';
                                                                                            echo '<div class="' . $barClass . ' h-2 rounded-full" style="width: ' . number_format($barWidth, 2, '.', '') . '%"></div>';
                                                                                            echo '</div>';
                                                                                            echo '</div>';

                                                                                            echo '<div class="mt-2 grid grid-cols-2 gap-2 text-xs">';
                                                                                            echo '<div class="rounded-lg bg-white border border-amber-100 px-2 py-1">';
                                                                                            echo '<span class="text-slate-500">Décaissé:</span> <span class="font-semibold text-slate-900">' . number_format($totalDecaisseLigne, 0, ',', ' ') . ' F</span>';
                                                                                            echo '</div>';
                                                                                            echo '<div class="rounded-lg bg-white border border-amber-100 px-2 py-1">';
                                                                                            echo '<span class="text-slate-500">En attente:</span> <span class="font-semibold text-slate-900">' . number_format($totalAttenteLigne, 0, ',', ' ') . ' F</span>';
                                                                                            echo '</div>';
                                                                                            echo '</div>';

                                                                                            echo '<div class="mt-2 text-xs">';
                                                                                            if ($resteAPayer < 0) {
                                                                                                echo '<span class="font-semibold text-red-700">Dépassement: ' . number_format(abs($resteAPayer), 0, ',', ' ') . ' F</span>';
                                                                                            } else {
                                                                                                echo '<span class="text-slate-500">Reste à payer:</span> <span class="font-semibold text-slate-900">' . number_format($resteAPayer, 0, ',', ' ') . ' F</span>';
                                                                                            }
                                                                                            echo '</div>';

                                                                                            echo '</div>';
                                                                                        }
                                                                                    }
                                                                                }
                                                                                if ($deboursesFound === 0) {
                                                                                    echo '<div class="text-gray-400 italic">Aucune ligne de déboursé</div>';
                                                                                }
                                                                                ?>
                                                                            </div>
                                                                        </div>

                                                                        <!-- Fiches de Décaissement -->
                                                                        <div class="bg-white rounded-xl shadow-sm p-4 border border-rose-100">
                                                                            <h4 class="flex items-center text-sm font-bold text-rose-700 mb-3">
                                                                                <i class="fas fa-receipt mr-2 text-rose-500"></i>
                                                                                Fiches Décaissement (<?= count($fichesFiltrees) ?>)
                                                                            </h4>
                                                                            <div class="space-y-2 text-sm max-h-96 lg:max-h-[520px] overflow-y-auto pr-1">
                                                                                <?php if (!empty($fichesFiltrees)): ?>
                                                                                    <?php foreach ($fichesFiltrees as $fiche): ?>
                                                                                        <?php
                                                                                        $ficheId = (int)($fiche['id_fiche'] ?? 0);
                                                                                        $ficheNum = (string)($fiche['num_fiche'] ?? '');
                                                                                        $ficheUrl = $ficheNum !== '' ? ('voir_fiche_details.php?num_fiche=' . urlencode($ficheNum)) : '#';
                                                                                        $debourseLabel = trim((string)($fiche['ligne_debourse_designation'] ?? ''));
                                                                                        if ($debourseLabel === '') {
                                                                                            $debourseLabel = trim((string)($fiche['precision_libelle'] ?? ''));
                                                                                        }
                                                                                        if ($debourseLabel === '' && !empty($fiche['ligne_debourse_id'])) {
                                                                                            $debourseLabel = 'Réf. ligne supprimée #' . (string)$fiche['ligne_debourse_id'];
                                                                                        }
                                                                                        $hasDebourseLink = $debourseLabel !== '';
                                                                                        $placement = $fiche['_placement'] ?? [];
                                                                                        $ficheZoneLabel = trim((string)($placement['zone'] ?? ''));
                                                                                        $ficheDebourseParentLabel = trim((string)($placement['debourse_label'] ?? ''));
                                                                                        $ficheLigneDevisLabel = trim((string)($placement['ligne_devis_label'] ?? ''));
                                                                                        $ficheCurrentLigneDebourseId = (int)($placement['ligne_debourse_id'] ?? ($fiche['ligne_debourse_id'] ?? 0));
                                                                                        $dateFiche = '';
                                                                                        if (!empty($fiche['date_creat_fiche'])) {
                                                                                            $ts = strtotime((string)$fiche['date_creat_fiche']);
                                                                                            $dateFiche = $ts ? date('d/m/Y H:i', $ts) : (string)$fiche['date_creat_fiche'];
                                                                                        }
                                                                                        ?>
                                                                                        <div class="p-3 bg-rose-50 rounded hover:bg-rose-100 transition-colors">
                                                                                            <div class="flex justify-between items-start gap-3">
                                                                                                <div class="min-w-0">
                                                                                                    <div class="flex flex-wrap items-center gap-2">
                                                                                                        <div class="font-semibold text-gray-900 break-words whitespace-normal">
                                                                                                            Fiche <?= htmlspecialchars($ficheNum) ?>
                                                                                                        </div>
                                                                                                        <a href="<?= htmlspecialchars($ficheUrl, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center rounded-full border border-rose-200 bg-white px-2 py-0.5 text-[11px] font-semibold text-rose-700 hover:bg-rose-50" target="_blank">
                                                                                                            <i class="fas fa-arrow-up-right-from-square mr-1"></i>Ouvrir
                                                                                                        </a>
                                                                                                    </div>
                                                                                                    <div class="text-xs text-gray-600 break-words whitespace-normal">
                                                                                                        <?= htmlspecialchars((string)($fiche['beficiaire_fiche'] ?? '')) ?>
                                                                                                    </div>

                                                                                                    <?php if (!empty($fiche['designation_libelle'])): ?>
                                                                                                        <div class="mt-1 text-xs text-gray-700 break-words whitespace-normal">
                                                                                                            <?= htmlspecialchars((string)$fiche['designation_libelle']) ?>
                                                                                                        </div>
                                                                                                    <?php endif; ?>

                                                                                                    <?php if (!empty($fiche['precision_libelle'])): ?>
                                                                                                        <div class="mt-1 text-xs text-gray-500 break-words whitespace-normal">
                                                                                                            <?= htmlspecialchars((string)$fiche['precision_libelle']) ?>
                                                                                                        </div>
                                                                                                    <?php endif; ?>

                                                                                                    <div class="mt-2 flex flex-wrap gap-2">
                                                                                                        <?php if ($ficheZoneLabel !== ''): ?>
                                                                                                            <span class="inline-flex items-center px-2 py-0.5 text-[11px] font-semibold rounded-full bg-indigo-100 text-indigo-800">
                                                                                                                Zone: <?= htmlspecialchars($ficheZoneLabel) ?>
                                                                                                            </span>
                                                                                                        <?php endif; ?>

                                                                                                        <?php if ($ficheDebourseParentLabel !== ''): ?>
                                                                                                            <span class="inline-flex items-center px-2 py-0.5 text-[11px] font-semibold rounded-full bg-cyan-100 text-cyan-800">
                                                                                                                <?= htmlspecialchars($ficheDebourseParentLabel) ?>
                                                                                                            </span>
                                                                                                        <?php endif; ?>

                                                                                                        <?php if ($hasDebourseLink): ?>
                                                                                                            <span class="inline-flex items-center px-2 py-0.5 text-[11px] font-semibold rounded-full bg-amber-100 text-amber-800">
                                                                                                                Ligne déboursé: <?= htmlspecialchars($debourseLabel) ?>
                                                                                                            </span>
                                                                                                            <?php if (!empty($fiche['ligne_debourse_categorie'])): ?>
                                                                                                                <span class="inline-flex items-center px-2 py-0.5 text-[11px] font-semibold rounded-full bg-slate-100 text-slate-700">
                                                                                                                    <?= htmlspecialchars((string)$fiche['ligne_debourse_categorie']) ?>
                                                                                                                </span>
                                                                                                            <?php endif; ?>
                                                                                                        <?php else: ?>
                                                                                                            <span class="inline-flex items-center px-2 py-0.5 text-[11px] font-semibold rounded-full bg-red-100 text-red-700">
                                                                                                                Non rattachée (à vérifier)
                                                                                                            </span>
                                                                                                        <?php endif; ?>

                                                                                                        <?php if ($ficheLigneDevisLabel !== ''): ?>
                                                                                                            <span class="inline-flex items-center px-2 py-0.5 text-[11px] font-semibold rounded-full bg-violet-100 text-violet-800">
                                                                                                                Ligne devis: <?= htmlspecialchars($ficheLigneDevisLabel) ?>
                                                                                                            </span>
                                                                                                        <?php endif; ?>

                                                                                                        <?php if ($dateFiche !== ''): ?>
                                                                                                            <span class="inline-flex items-center px-2 py-0.5 text-[11px] font-medium rounded-full bg-white text-slate-500 border border-slate-200">
                                                                                                                <?= htmlspecialchars($dateFiche) ?>
                                                                                                            </span>
                                                                                                        <?php endif; ?>
                                                                                                    </div>
                                                                                                </div>

                                                                                                <div class="text-right">
                                                                                                    <div class="font-semibold text-rose-600"><?= number_format((float)($fiche['montant_fiche'] ?? 0), 0, ',', ' ') ?> F</div>
                                                                                                    <div class="text-xs mt-1">
                                                                                                        <?php if ((int)($fiche['decaisse'] ?? 0) === 1): ?>
                                                                                                            <span class="text-rose-700 font-semibold">✅ Décaissé</span>
                                                                                                        <?php else: ?>
                                                                                                            <span class="text-yellow-700 font-semibold">⏳ En attente</span>
                                                                                                        <?php endif; ?>
                                                                                                    </div>
                                                                                                </div>
                                                                                            </div>

                                                                                            <?php if ($detailFilters['fiche'] !== '' && $ficheId > 0 && !empty($reassignTargets)): ?>
                                                                                                <form method="post" class="mt-3 rounded-2xl border border-rose-200 bg-white px-3 py-3" onclick="event.stopPropagation();">
                                                                                                    <input type="hidden" name="action" value="reassign_fiche_zone">
                                                                                                    <input type="hidden" name="fiche_id" value="<?= $ficheId ?>">
                                                                                                    <?php foreach ($detailFilters as $filterKey => $filterValue): ?>
                                                                                                        <input type="hidden" name="redirect_filters[filter_<?= htmlspecialchars((string)$filterKey, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars((string)$filterValue, ENT_QUOTES, 'UTF-8') ?>">
                                                                                                    <?php endforeach; ?>
                                                                                                    <div class="flex flex-col lg:flex-row lg:items-end gap-3">
                                                                                                        <div class="flex-1">
                                                                                                            <label class="block text-xs font-semibold text-slate-700 mb-1">Changer la zone / ligne de déboursé</label>
                                                                                                            <select name="target_ligne_debourse_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-rose-400">
                                                                                                                <?php foreach ($reassignTargets as $targetOption): ?>
                                                                                                                    <option value="<?= (int)$targetOption['id'] ?>" <?= (int)$targetOption['id'] === $ficheCurrentLigneDebourseId ? 'selected' : '' ?>>
                                                                                                                        <?= htmlspecialchars($targetOption['zone'] . ' | ' . $targetOption['debourse'] . ' | ' . $targetOption['ligne_debourse'], ENT_QUOTES, 'UTF-8') ?>
                                                                                                                    </option>
                                                                                                                <?php endforeach; ?>
                                                                                                            </select>
                                                                                                        </div>
                                                                                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 transition-colors" onclick="return confirm('Reclasser cette fiche vers la ligne de déboursé sélectionnée ?');">
                                                                                                            <i class="fas fa-arrows-rotate mr-2"></i>Reclasser
                                                                                                        </button>
                                                                                                    </div>
                                                                                                </form>
                                                                                            <?php endif; ?>
                                                                                        </div>
                                                                                    <?php endforeach; ?>

                                                                                    <?php if (!empty($fichesFallback)): ?>
                                                                                        <div class="mt-3 text-xs text-gray-500">
                                                                                            Note: certaines fiches sont affichées via association par texte (non rattachées à une ligne de déboursé).
                                                                                        </div>
                                                                                    <?php endif; ?>
                                                                                <?php else: ?>
                                                                                    <div class="text-gray-400 italic">Aucune fiche associée</div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </td>
                                </tr>
                                <?php $globalIndex++; ?>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr class="font-semibold">
                                <td colspan="2" class="px-6 py-4 text-sm text-gray-900">TOTAL GÉNÉRAL</td>
                                <td class="px-6 py-4 text-sm text-indigo-600 font-bold">
                                    <?= number_format($detailsChantier['devis']['budget_devis'] ?? 0, 0, ',', ' ') ?> F
                                </td>
                                <td class="px-6 py-4 text-sm text-amber-600 font-bold">
                                    <?= number_format($debourseDisplayValue, 0, ',', ' ') ?> F
                                </td>
                                <td class="px-6 py-4 text-sm text-rose-600 font-bold">
                                    <?= number_format($detailsChantier['totaux']['montant_paye'], 0, ',', ' ') ?> F
                                </td>
                                <td class="px-6 py-4 text-sm text-orange-600 font-bold">
                                    <?= number_format($detailsChantier['totaux']['montant_restant'], 0, ',', ' ') ?> F
                                </td>
                                <td class="px-6 py-4 text-sm text-indigo-600 font-bold">
                                    <?= number_format($detailsChantier['totaux']['pourcentage_realise'], 1) ?>%
                                </td>
                                <td class="px-6 py-4"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 mb-8">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-yellow-600 text-xl mr-3"></i>
                    <div>
                        <h3 class="font-semibold text-yellow-800">Aucun devis associé</h3>
                        <p class="text-yellow-700">Ce chantier n'a pas de devis associé ou les lignes de devis ne sont pas disponibles.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<style>
.budget-workspace button.rotate-45 i{transform:rotate(45deg)}
.budget-workspace button i{transition:transform .2s}
</style>

<script>
    function exportDetailChantier() {
        // Export Excel du détail du chantier
        window.location.href = '../request/export_detail_chantier.php?chantier_id=<?= $chantierId ?>&format=excel';
    }

    function exportPDFChantier() {
        // Export PDF du détail du chantier
        window.location.href = '../request/export_detail_chantier.php?chantier_id=<?= $chantierId ?>&format=pdf';
    }

    function printChantier() {
        // Impression propre (même style que PDF/Excel)
        window.open('../request/export_detail_chantier.php?chantier_id=<?= $chantierId ?>&format=print', '_blank');
    }

    // Fonction pour basculer l'expansion des détails
    function toggleExpand(button, rowId) {
        const row = document.getElementById(rowId);
        const icon = button.querySelector('i');

        if (row.classList.contains('hidden')) {
            // Ouvrir
            row.classList.remove('hidden');
            button.classList.add('rotate-45');
            icon.style.transform = 'rotate(-45deg)';
        } else {
            // Fermer
            row.classList.add('hidden');
            button.classList.remove('rotate-45');
            icon.style.transform = 'rotate(0deg)';
        }
    }

    const analyticsData = <?= json_encode($analyticsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    document.addEventListener('DOMContentLoaded', function() {
        const scopeSelect = document.getElementById('analyticsScope');
        const focusSelect = document.getElementById('analyticsFocus');
        const bandChartTitle = document.getElementById('bandChartTitle');
        const bandChartCaption = document.getElementById('bandChartCaption');
        const bandChartHint = document.getElementById('bandChartHint');
        const bandChartCanvasWrap = document.getElementById('bandChartCanvasWrap');
        const analyticsDevisValue = document.getElementById('analyticsDevisValue');
        const analyticsDevisDetails = document.getElementById('analyticsDevisDetails');
        const analyticsDecaisseValue = document.getElementById('analyticsDecaisseValue');
        const analyticsDecaisseDetails = document.getElementById('analyticsDecaisseDetails');
        const analyticsMargeActuelleValue = document.getElementById('analyticsMargeActuelleValue');
        const analyticsMargePrevisionnelleValue = document.getElementById('analyticsMargePrevisionnelleValue');
        const analyticsEcartMargesValue = document.getElementById('analyticsEcartMargesValue');
        const analyticsProgressValue = document.getElementById('analyticsProgressValue');
        const analyticsProgressDetails = document.getElementById('analyticsProgressDetails');
        const analyticsSelectedSelect = document.getElementById('analyticsSelectedSelect');
        const donutDecaisseValue = document.getElementById('donutDecaisseValue');
        const donutDeltaLabel = document.getElementById('donutDeltaLabel');
        const donutDeltaValue = document.getElementById('donutDeltaValue');
        const donutForecastLabel = document.getElementById('donutForecastLabel');
        const donutForecastValue = document.getElementById('donutForecastValue');
        const donutRatioCurrentValue = document.getElementById('donutRatioCurrentValue');
        const donutRatioForecastValue = document.getElementById('donutRatioForecastValue');
        const bandModeButtons = Array.from(document.querySelectorAll('[data-band-mode]'));

        const centerTextPlugin = {
            id: 'centerTextPlugin',
            afterDraw(chart, args, pluginOptions) {
                if (chart.config.type !== 'doughnut' || !pluginOptions || !pluginOptions.lines) {
                    return;
                }

                const meta = chart.getDatasetMeta(0);
                if (!meta || !meta.data || !meta.data.length) {
                    return;
                }

                const ctx = chart.ctx;
                const x = meta.data[0].x;
                const y = meta.data[0].y;

                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                pluginOptions.lines.forEach((line, index) => {
                    ctx.fillStyle = line.color || '#0f172a';
                    ctx.font = line.font || '600 13px sans-serif';
                    ctx.fillText(line.text, x, y + (index * 18) - ((pluginOptions.lines.length - 1) * 9));
                });
                ctx.restore();
            }
        };

        Chart.register(centerTextPlugin);

        const numberFormatter = new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        });

        const percentFormatter = new Intl.NumberFormat('fr-FR', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1
        });

        let bandChart;
        let donutChart;
        let progressTimelineChart;
        let currentBandMode = 'eclatee';

        function formatCurrency(value) {
            const numericValue = Number(value) || 0;
            const absValue = Math.round(Math.abs(numericValue));
            const formatted = numberFormatter.format(absValue) + ' F';
            return numericValue < 0 ? '-' + formatted : formatted;
        }

        function formatPercent(value) {
            return percentFormatter.format(Number(value) || 0) + '%';
        }

        function buildRatioStatusTag(ratio01) {
            const ratio = Number(ratio01);
            if (!Number.isFinite(ratio) || ratio <= 0) {
                return '';
            }

            if (ratio > 1) {
                return 'PROBLÈME · HB';
            }
            if (ratio >= 0.95) {
                return 'ATTENTION · LIM';
            }
            return '';
        }

        function safeArray(items) {
            return Array.isArray(items) ? items : [];
        }

        function sortAnalyticsItems(items) {
            return safeArray(items)
                .slice()
                .sort((first, second) => {
                    const scoreA = Math.max(Number(first.devis) || 0, Number(first.decaisse) || 0, Number(first.debourse) || 0);
                    const scoreB = Math.max(Number(second.devis) || 0, Number(second.decaisse) || 0, Number(second.debourse) || 0);
                    return scoreB - scoreA;
                });
        }

        function getScopeItems(scope) {
            if (scope === 'zones') {
                return sortAnalyticsItems(analyticsData.zones);
            }
            if (scope === 'lignes') {
                return sortAnalyticsItems(analyticsData.lines);
            }
            return analyticsData.global ? [analyticsData.global] : [];
        }

        function getFocusItems(scope) {
            const items = [{
                id: 'global',
                label: 'Vue globale du chantier',
                fullLabel: analyticsData.global ? analyticsData.global.fullLabel : 'Vue globale'
            }];

            if (scope === 'zones') {
                return items.concat(sortAnalyticsItems(analyticsData.zones));
            }
            if (scope === 'lignes') {
                return items.concat(sortAnalyticsItems(analyticsData.lines));
            }
            return items;
        }

        function getSelectedScope() {
            return scopeSelect ? scopeSelect.value : 'global';
        }

        function getFocusedMetric(scope) {
            const focusValue = focusSelect ? focusSelect.value : 'global';
            if (focusValue === 'global') {
                return analyticsData.global;
            }

            return getScopeItems(scope).find(item => item.id === focusValue) || analyticsData.global;
        }

        function fillFocusSelectOptions(targetSelect, options, previousValue) {
            if (!targetSelect) {
                return;
            }

            targetSelect.innerHTML = '';
            options.forEach(item => {
                const option = document.createElement('option');
                option.value = item.id;
                option.textContent = item.id === 'global' ?
                    item.label :
                    (item.fullLabel || item.label || 'Element');
                targetSelect.appendChild(option);
            });

            const allowedValues = options.map(item => item.id);
            targetSelect.value = allowedValues.includes(previousValue) ? previousValue : 'global';
        }

        function updateFocusOptions(scope) {
            if (!focusSelect) {
                return;
            }

            const previousValue = focusSelect.value;
            const options = getFocusItems(scope);

            fillFocusSelectOptions(focusSelect, options, previousValue);
            fillFocusSelectOptions(analyticsSelectedSelect, options, focusSelect.value);
        }

        function setBandMode(mode) {
            currentBandMode = mode;
            bandModeButtons.forEach(button => {
                const isActive = button.dataset.bandMode === mode;
                button.classList.toggle('is-active', isActive);
                button.classList.toggle('bg-white', isActive);
                button.classList.toggle('shadow-sm', isActive);
                button.classList.toggle('text-slate-500', !isActive);
            });
        }

        function updateMetricCards(metric) {
            if (!metric) {
                return;
            }

            const devis = Number(metric.devis) || 0;
            const debourse = Number(metric.debourse) || 0;
            const decaisse = Number(metric.decaisse) || 0;
            const margePrevisionnelle = Number(metric.marge_previsionnelle) || 0;
            const margePrevisionnellePositive = Math.max(margePrevisionnelle, 0);
            const depassementPrevisionnel = Math.max(-margePrevisionnelle, 0);
            const isOverBudgetPrevisionnel = depassementPrevisionnel > 0;

            analyticsDevisValue.textContent = formatCurrency(devis);
            analyticsDecaisseValue.textContent = formatCurrency(decaisse);

            if (analyticsMargeActuelleValue) {
                analyticsMargeActuelleValue.textContent = formatCurrency(metric.marge_actuelle);
            }
            if (analyticsMargePrevisionnelleValue) {
                analyticsMargePrevisionnelleValue.textContent = formatCurrency(metric.marge_previsionnelle);
            }
            if (analyticsEcartMargesValue) {
                const ecart = Number(metric.ecart_marge) || 0;
                const ecartPct = Number(metric.ecart_marge_pct) || 0;
                analyticsEcartMargesValue.textContent = `${formatCurrency(ecart)} (${formatPercent(ecartPct)})`;
            }

            const progressBaseKind = (metric.progress_base_kind || (debourse > 0 ? 'debourse' : 'devis'));
            const progressBaseLabel = progressBaseKind === 'debourse' ? 'Déboursé' : (progressBaseKind === 'devis' ? 'Devis' : 'Décaissé');
            const progressBase = Number(metric.progress_base) || (progressBaseKind === 'debourse' ? debourse : (progressBaseKind === 'devis' ? devis : Math.max(decaisse, 1)));
            const resteSurBase = Number(metric.reste_sur_progress_base);
            const resteDisplay = Number.isFinite(resteSurBase) ? resteSurBase : (progressBase - decaisse);
            const statusTag = (progressBaseKind === 'debourse' || progressBaseKind === 'devis') ?
                buildRatioStatusTag(progressBase > 0 ? (decaisse / progressBase) : 0) :
                '';

            analyticsProgressValue.textContent = formatPercent(metric.progress);
            donutDecaisseValue.textContent = formatCurrency(decaisse);
            const margeActuelle = Number(metric.marge_actuelle) || 0;
            const margeActuellePositive = Math.max(margeActuelle, 0);
            const depassementActuel = Math.max(-margeActuelle, 0);
            const isOverBudgetActuel = depassementActuel > 0;

            const margePrev = Number(metric.marge_previsionnelle) || 0;
            const margePrevPositive = Math.max(margePrev, 0);
            const depassementPrev = Math.max(-margePrev, 0);
            const isOverBudgetPrev = depassementPrev > 0;

            // Donut = marge prévisionnelle, repère = marge actuelle
            donutDeltaLabel.textContent = isOverBudgetPrev ? 'Dépassement prévu' : 'Marge prévisionnelle';
            donutDeltaValue.textContent = formatCurrency(isOverBudgetPrev ? depassementPrev : margePrevPositive);

            if (donutForecastLabel && donutForecastValue) {
                donutForecastLabel.textContent = isOverBudgetActuel ? 'Dépassement actuel' : 'Marge actuelle';
                donutForecastValue.textContent = formatCurrency(isOverBudgetActuel ? depassementActuel : margeActuellePositive);
            }

            const ratioCurrent = devis > 0 ?
                (isOverBudgetActuel ?
                    (depassementActuel / devis) * 100 :
                    (margeActuellePositive / devis) * 100) :
                0;

            const ratioForecast = devis > 0 ?
                (isOverBudgetPrev ?
                    (depassementPrev / devis) * 100 :
                    (margePrevPositive / devis) * 100) :
                0;

            if (donutRatioCurrentValue) {
                donutRatioCurrentValue.textContent = formatPercent(ratioCurrent);
            }
            if (donutRatioForecastValue) {
                donutRatioForecastValue.textContent = formatPercent(ratioForecast);
            }

            if (analyticsDevisDetails) {
                const ratioDebourseSurDevis = Number(metric.ratio_debourse_sur_devis_pct);
                const ratioDebourseText = Number.isFinite(ratioDebourseSurDevis) ? ` (${formatPercent(ratioDebourseSurDevis)})` : '';

                const lines = [`Déboursé prévu: ${formatCurrency(debourse)}${ratioDebourseText}`];

                if (metric.scope === 'global') {
                    const zonesCount = Number(metric.zones_count);
                    const lignesCount = Number(metric.lignes_count);
                    const fichesCount = Number(metric.fiches_count);
                    const stats = [];

                    if (Number.isFinite(zonesCount) && zonesCount > 0) {
                        stats.push(`Zones: ${zonesCount}`);
                    }
                    if (Number.isFinite(lignesCount) && lignesCount > 0) {
                        stats.push(`Lignes: ${lignesCount}`);
                    }
                    if (Number.isFinite(fichesCount) && fichesCount > 0) {
                        stats.push(`Fiches: ${fichesCount}`);
                    }

                    if (stats.length) {
                        lines.push(stats.join(' · '));
                    }
                } else if (metric.scope === 'zone') {
                    const lignesCount = Number(metric.lignes_count);
                    if (Number.isFinite(lignesCount) && lignesCount > 0) {
                        lines.push(`Lignes: ${lignesCount}`);
                    }
                } else if (metric.scope === 'line' && metric.zone) {
                    lines.push(`Zone: ${metric.zone}`);
                }

                analyticsDevisDetails.textContent = lines.join('\n');
            }

            if (analyticsDecaisseDetails) {
                if (debourse > 0) {
                    const ratioDecaisseDebourse = Number(metric.ratio_decaisse_sur_debourse_pct);
                    const ratioText = Number.isFinite(ratioDecaisseDebourse) ? formatPercent(ratioDecaisseDebourse) : formatPercent((decaisse / debourse) * 100);
                    const reste = debourse - decaisse;
                    const tag = buildRatioStatusTag(debourse > 0 ? (decaisse / debourse) : 0);
                    const lines = [
                        `Sur déboursé: ${ratioText}`,
                        `Décaissé: ${formatCurrency(decaisse)}`,
                        `Déboursé: ${formatCurrency(debourse)}`,
                        `Reste: ${formatCurrency(reste)}`
                    ];
                    if (tag) {
                        lines.push(tag);
                    }
                    analyticsDecaisseDetails.textContent = lines.join('\n');
                } else if (devis > 0) {
                    const ratioDecaisseDevis = Number(metric.ratio_decaisse_sur_devis_pct);
                    const ratioText = Number.isFinite(ratioDecaisseDevis) ? formatPercent(ratioDecaisseDevis) : formatPercent((decaisse / devis) * 100);
                    const reste = devis - decaisse;
                    const tag = buildRatioStatusTag(devis > 0 ? (decaisse / devis) : 0);
                    const lines = [
                        `Sur devis: ${ratioText}`,
                        `Décaissé: ${formatCurrency(decaisse)}`,
                        `Devis: ${formatCurrency(devis)}`,
                        `Reste: ${formatCurrency(reste)}`
                    ];
                    if (tag) {
                        lines.push(tag);
                    }
                    analyticsDecaisseDetails.textContent = lines.join('\n');
                } else {
                    analyticsDecaisseDetails.textContent = 'Prévision indisponible';
                }
            }

            if (analyticsProgressDetails) {
                const lines = [
                    `Base: ${progressBaseLabel} ${formatCurrency(progressBase)}`,
                    `Décaissé: ${formatCurrency(decaisse)}`,
                    `Reste: ${formatCurrency(resteDisplay)}`
                ];
                if (statusTag) {
                    lines.push(statusTag);
                }
                analyticsProgressDetails.textContent = lines.join('\n');
            }
        }

        function createBandDatasets(items, mode) {
            if (mode === 'condensee') {
                const devisRef = items.map(item => Number(item.devis) || 0);
                const decaisseDansBudget = items.map(item => {
                    const devis = Number(item.devis) || 0;
                    const decaisse = Number(item.decaisse) || 0;
                    return devis > 0 ? Math.min(decaisse, devis) : decaisse;
                });
                const debourse = items.map(item => Number(item.debourse) || 0);
                const marge = items.map(item => Number(item.marge) || 0);
                const depassement = items.map(item => Number(item.depassement) || 0);

                const datasets = [{
                    label: 'Devis (référence)',
                    data: devisRef,
                    backgroundColor: 'rgba(79, 70, 229, 0.22)',
                    borderColor: '#4f46e5',
                    borderWidth: 2,
                    borderRadius: 10,
                    borderSkipped: false,
                    stack: 'reference'
                }, {
                    label: 'Décaissé effectif',
                    data: decaisseDansBudget,
                    backgroundColor: '#ef4444',
                    borderRadius: 10,
                    borderSkipped: false,
                    stack: 'lecture'
                }, {
                    label: 'Marge actuelle',
                    data: marge,
                    backgroundColor: '#10b981',
                    borderRadius: 10,
                    borderSkipped: false,
                    stack: 'lecture'
                }];

                if (depassement.some(value => value > 0)) {
                    datasets.push({
                        label: 'Dépassement actuel',
                        data: depassement,
                        backgroundColor: '#b91c1c',
                        borderRadius: 10,
                        borderSkipped: false,
                        stack: 'lecture'
                    });
                }

                if (debourse.some(value => value > 0)) {
                    datasets.push({
                        label: 'Déboursé (prévision)',
                        data: debourse,
                        backgroundColor: '#f59e0b',
                        borderRadius: 10,
                        borderSkipped: false,
                        stack: 'prevision'
                    });
                }

                return datasets;
            }

            return [{
                label: 'Montant devis',
                data: items.map(item => Number(item.devis) || 0),
                backgroundColor: '#4f46e5',
                borderRadius: 10,
                borderSkipped: false,
                maxBarThickness: 24
            }, {
                label: 'Montant déboursé (prévision)',
                data: items.map(item => Number(item.debourse) || 0),
                backgroundColor: '#f59e0b',
                borderRadius: 10,
                borderSkipped: false,
                maxBarThickness: 24
            }, {
                label: 'Montant décaissé',
                data: items.map(item => Number(item.decaisse) || 0),
                backgroundColor: '#ef4444',
                borderRadius: 10,
                borderSkipped: false,
                maxBarThickness: 24
            }];
        }

        function renderBandChart() {
            const scope = getSelectedScope();
            const items = getScopeItems(scope);
            const labels = items.map(item => item.label || item.fullLabel || 'Element');

            bandChartCanvasWrap.style.height = `${Math.max(220, items.length * 54)}px`;

            if (bandChart) {
                bandChart.destroy();
            }

            bandChartTitle.textContent = scope === 'global' ?
                'Lecture globale du chantier' :
                (scope === 'zones' ? 'Comparatif détaillé par zone' : 'Comparatif détaillé par ligne de devis');

            bandChartCaption.textContent = currentBandMode === 'condensee' ?
                'La bande recompose le décaissé, la marge et, si besoin, le dépassement, avec devis et déboursé prévus en repères.' :
                'La lecture éclatée place devis, déboursé (prévision) et décaissé côte à côte pour rendre la marge immédiatement visible.';

            bandChartHint.textContent = currentBandMode === 'condensee' ?
                'Mode synthèse, utile pour condenser beaucoup de lignes. Cliquez sur une barre pour mettre à jour les 4 indicateurs.' :
                'Mode comparatif, utile pour arbitrer rapidement les écarts. Cliquez sur une barre pour mettre à jour les 4 indicateurs.';

            bandChart = new Chart(document.getElementById('budgetBandChart'), {
                type: 'bar',
                data: {
                    labels,
                    datasets: createBandDatasets(items, currentBandMode)
                },
                options: {
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    responsive: true,
                    onClick(event, elements) {
                        if (!elements || !elements.length || !focusSelect) {
                            return;
                        }

                        const index = typeof elements[0].index === 'number' ? elements[0].index : elements[0].dataIndex;
                        const clickedItem = items[index];
                        if (!clickedItem) {
                            return;
                        }

                        const targetId = clickedItem.id || 'global';
                        const hasOption = Array.from(focusSelect.options).some(option => option.value === targetId);
                        if (!hasOption) {
                            return;
                        }

                        focusSelect.value = targetId;
                        if (analyticsSelectedSelect) {
                            analyticsSelectedSelect.value = targetId;
                        }
                        const metric = getFocusedMetric(scope);
                        updateMetricCards(metric);
                        renderDonutChart(metric);
                    },
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            stacked: currentBandMode === 'condensee',
                            grid: {
                                color: 'rgba(148, 163, 184, 0.14)'
                            },
                            ticks: {
                                color: '#64748b',
                                callback(value) {
                                    return formatCurrency(value);
                                }
                            }
                        },
                        y: {
                            stacked: currentBandMode === 'condensee',
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#334155',
                                font: {
                                    size: 11,
                                    weight: '600'
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                usePointStyle: true,
                                color: '#334155'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label(context) {
                                    return `${context.dataset.label}: ${formatCurrency(context.raw)}`;
                                },
                                afterBody(contexts) {
                                    const index = contexts[0] ? contexts[0].dataIndex : -1;
                                    const item = items[index];
                                    if (!item) {
                                        return '';
                                    }

                                    const lines = [
                                        `Déboursé planifié: ${formatCurrency(item.debourse)}`,
                                        `Marge actuelle: ${formatCurrency(item.marge)}`
                                    ];

                                    if (Number(item.depassement) > 0) {
                                        lines.push(`Dépassement actuel: ${formatCurrency(item.depassement)}`);
                                    }

                                    return lines;
                                }
                            }
                        }
                    }
                }
            });
        }

        function renderDonutChart(metric) {
            const decaisseDansBudget = Number(metric.devis) > 0 ?
                Math.min(Number(metric.decaisse) || 0, Number(metric.devis) || 0) :
                Number(metric.decaisse) || 0;
            const margePrevisionnelle = Number(metric.marge_previsionnelle) || 0;
            const marge = Math.max(margePrevisionnelle, 0);
            const depassement = Math.max(-margePrevisionnelle, 0);

            let labels = ['Décaissé', 'Marge prévisionnelle'];
            let values = [decaisseDansBudget, marge];
            let colors = ['#ef4444', '#10b981'];

            if (depassement > 0) {
                labels = ['Décaissé', 'Dépassement prévu'];
                values = [decaisseDansBudget, depassement];
                colors = ['#ef4444', '#b91c1c'];
            }

            if (!values.some(value => value > 0)) {
                labels = ['Aucun flux'];
                values = [1];
                colors = ['#cbd5e1'];
            }

            if (donutChart) {
                donutChart.destroy();
            }

            const devis = Number(metric.devis) || 0;
            const ratio = devis > 0 ?
                (depassement > 0 ?
                    (depassement / devis) * 100 :
                    (marge / devis) * 100) :
                0;

            donutChart = new Chart(document.getElementById('marginDonutChart'), {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        backgroundColor: colors,
                        borderWidth: 0,
                        hoverOffset: 8
                    }]
                },
                options: {
                    maintainAspectRatio: false,
                    cutout: '72%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                usePointStyle: true,
                                color: '#334155'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label(context) {
                                    return `${context.label}: ${formatCurrency(context.raw)}`;
                                }
                            }
                        },
                        centerTextPlugin: {
                            lines: [{
                                text: depassement > 0 ? formatPercent(ratio) : formatPercent(ratio),
                                font: '700 22px sans-serif',
                                color: depassement > 0 ? '#dc2626' : '#16a34a'
                            }, {
                                text: depassement > 0 ? 'dépassement (prévu)' : 'marge (prévue)',
                                font: '600 12px sans-serif',
                                color: '#64748b'
                            }]
                        }
                    }
                }
            });
        }

        function renderProgressTimeline() {
            if (progressTimelineChart) {
                progressTimelineChart.destroy();
            }

            const timeline = analyticsData.timeline || {
                labels: [],
                cumulative: [],
                budget: []
            };

            const labels = timeline.labels || [];
            const globalMetric = analyticsData.global || {};
            const debourseGlobal = Number(globalMetric.debourse) || 0;
            const devisGlobal = Number(globalMetric.devis) || 0;

            const margePrevGlobal = Number(globalMetric.marge_previsionnelle) || 0;
            const margePrevPositive = Math.max(margePrevGlobal, 0);
            const depassementPrev = Math.max(-margePrevGlobal, 0);

            const cumulative = Array.isArray(timeline.cumulative) ? timeline.cumulative : [];
            const margeActuelleLine = cumulative.map(value => Math.max(devisGlobal - (Number(value) || 0), 0));
            const depassementActuelLine = cumulative.map(value => Math.max((Number(value) || 0) - devisGlobal, 0));
            const hasDepassementActuel = depassementActuelLine.some(value => value > 0);

            const debourseLine = labels.map(() => debourseGlobal);
            const margePrevLine = labels.map(() => margePrevPositive);
            const depassementPrevLine = labels.map(() => depassementPrev);

            const timelineDatasets = [{
                label: 'Décaissé cumulé',
                data: timeline.cumulative || [],
                borderColor: '#ef4444',
                backgroundColor: 'rgba(239, 68, 68, 0.14)',
                pointBackgroundColor: '#ef4444',
                pointRadius: 4,
                pointHoverRadius: 5,
                fill: true,
                tension: 0.32
            }, {
                label: 'Objectif devis',
                data: timeline.budget || [],
                borderColor: '#4f46e5',
                backgroundColor: 'transparent',
                borderDash: [8, 6],
                pointRadius: 0,
                tension: 0
            }, {
                label: 'Déboursé prévu (repère)',
                data: debourseLine,
                borderColor: '#f59e0b',
                backgroundColor: 'transparent',
                borderDash: [6, 6],
                pointRadius: 0,
                tension: 0
            }];

            if (hasDepassementActuel) {
                timelineDatasets.push({
                    label: 'Dépassement actuel',
                    data: depassementActuelLine,
                    borderColor: '#b91c1c',
                    backgroundColor: 'transparent',
                    borderDash: [],
                    borderWidth: 3,
                    pointRadius: 0,
                    tension: 0
                });
            } else {
                timelineDatasets.push({
                    label: 'Marge actuelle',
                    data: margeActuelleLine,
                    borderColor: '#10b981',
                    backgroundColor: 'transparent',
                    borderDash: [],
                    borderWidth: 3,
                    pointRadius: 0,
                    tension: 0
                });
            }

            if (depassementPrev > 0) {
                timelineDatasets.push({
                    label: 'Dépassement prévu (repère)',
                    data: depassementPrevLine,
                    borderColor: 'rgba(185, 28, 28, 0.55)',
                    backgroundColor: 'transparent',
                    borderDash: [10, 4],
                    pointRadius: 0,
                    tension: 0
                });
            } else {
                timelineDatasets.push({
                    label: 'Marge prévisionnelle (repère)',
                    data: margePrevLine,
                    borderColor: 'rgba(16, 185, 129, 0.55)',
                    backgroundColor: 'transparent',
                    borderDash: [10, 4],
                    pointRadius: 0,
                    tension: 0
                });
            }

            progressTimelineChart = new Chart(document.getElementById('progressTimelineChart'), {
                type: 'line',
                data: {
                    labels,
                    datasets: timelineDatasets
                },
                options: {
                    maintainAspectRatio: false,
                    responsive: true,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                color: '#64748b'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(148, 163, 184, 0.14)'
                            },
                            ticks: {
                                color: '#64748b',
                                callback(value) {
                                    return formatCurrency(value);
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                boxWidth: 12,
                                usePointStyle: true,
                                color: '#334155'
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label(context) {
                                    return `${context.dataset.label}: ${formatCurrency(context.raw)}`;
                                }
                            }
                        }
                    }
                }
            });
        }

        function refreshAnalytics() {
            const scope = getSelectedScope();
            updateFocusOptions(scope);

            const metric = getFocusedMetric(scope);
            updateMetricCards(metric);
            renderBandChart();
            renderDonutChart(metric);
        }

        function onFocusChanged() {
            const scope = getSelectedScope();
            const metric = getFocusedMetric(scope);
            updateMetricCards(metric);
            renderDonutChart(metric);
        }

        scopeSelect.addEventListener('change', refreshAnalytics);

        focusSelect.addEventListener('change', function() {
            if (analyticsSelectedSelect) {
                analyticsSelectedSelect.value = focusSelect.value;
            }
            onFocusChanged();
        });

        if (analyticsSelectedSelect) {
            analyticsSelectedSelect.addEventListener('change', function() {
                if (focusSelect) {
                    focusSelect.value = analyticsSelectedSelect.value;
                }
                onFocusChanged();
            });
        }

        bandModeButtons.forEach(button => {
            button.addEventListener('click', function() {
                setBandMode(button.dataset.bandMode);
                renderBandChart();
            });
        });

        setBandMode('eclatee');
        renderProgressTimeline();
        refreshAnalytics();

        // Animation des barres de progression
        const progressBars = document.querySelectorAll('.bg-green-600, .bg-yellow-600, .bg-red-600');
        progressBars.forEach(bar => {
            const width = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => {
                bar.style.transition = 'width 1s ease-in-out';
                bar.style.width = width;
            }, 100);
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
