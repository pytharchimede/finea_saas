<?php

function budget_summary_new_bucket(string $label): array
{
    return [
        'label' => $label,
        'count' => 0,
        'budget_reel' => 0.0,
        'budget_devis' => 0.0,
        'depenses' => 0.0,
        'restant_reel' => 0.0,
        'marge_devis' => 0.0,
        'fiches' => 0,
        'progression_reel' => 0.0,
        'progression_devis' => 0.0,
        'depassement' => 0.0,
        'over_budget_count' => 0,
        'over_budget_total' => 0.0,
        'no_budget_with_depense_count' => 0,
        'no_budget_with_depense_total' => 0.0,
    ];
}

function budget_summary_add_row(array &$bucket, array $row): void
{
    $budgetReel = (float)($row['budget_interne_calc'] ?? $row['budget_total'] ?? 0);
    $budgetDevis = (float)($row['budget_devis_total'] ?? $row['budget_total'] ?? 0);
    $depenses = (float)($row['total_depenses'] ?? 0);
    $restantReel = $budgetReel - $depenses;
    $margeDevis = $budgetDevis - $depenses;

    $bucket['count']++;
    $bucket['budget_reel'] += $budgetReel;
    $bucket['budget_devis'] += $budgetDevis;
    $bucket['depenses'] += $depenses;
    $bucket['restant_reel'] += $restantReel;
    $bucket['marge_devis'] += $margeDevis;
    $bucket['fiches'] += (int)($row['nombre_fiches'] ?? 0);

    if ($budgetReel > 0 && $depenses > $budgetReel) {
        $bucket['over_budget_count']++;
        $bucket['over_budget_total'] += ($depenses - $budgetReel);
    }

    if ($budgetReel <= 0 && $depenses > 0) {
        $bucket['no_budget_with_depense_count']++;
        $bucket['no_budget_with_depense_total'] += $depenses;
    }
}

function budget_summary_finalize(array $bucket): array
{
    $bucket['progression_reel'] = $bucket['budget_reel'] > 0
        ? ($bucket['depenses'] / $bucket['budget_reel']) * 100
        : 0.0;
    $bucket['progression_devis'] = $bucket['budget_devis'] > 0
        ? ($bucket['depenses'] / $bucket['budget_devis']) * 100
        : 0.0;
    $bucket['depassement'] = max($bucket['depenses'] - $bucket['budget_reel'], 0);

    return $bucket;
}

function budget_summary_status_color(float $progression): string
{
    if ($progression > 90) {
        return 'red';
    }
    if ($progression > 70) {
        return 'yellow';
    }

    return 'green';
}

function budget_summary_compute(array $rows): array
{
    $global = budget_summary_new_bucket('Global');
    $active = budget_summary_new_bucket('Actifs');
    $archive = budget_summary_new_bucket('Archives');

    foreach ($rows as $row) {
        budget_summary_add_row($global, $row);

        if (!empty($row['archived_at'])) {
            budget_summary_add_row($archive, $row);
        } else {
            budget_summary_add_row($active, $row);
        }
    }

    $global = budget_summary_finalize($global);
    $active = budget_summary_finalize($active);
    $archive = budget_summary_finalize($archive);

    return [
        'global' => $global,
        'active' => $active,
        'archive' => $archive,
        'archived_count' => $archive['count'],
        'active_count' => $active['count'],
    ];
}
