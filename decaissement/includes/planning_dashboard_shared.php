<?php

if (!function_exists('planningDashboardFormatDate')) {
    function planningDashboardFormatDate(?string $value, bool $withTime = false): string
    {
        if (!$value) {
            return 'Non renseigne';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return 'Non renseigne';
        }

        return date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
    }
}

if (!function_exists('planningDashboardNormalizeFilters')) {
    function planningDashboardNormalizeFilters(array $source): array
    {
        $meetingDate = trim((string)($source['meeting_date'] ?? ''));
        $meetingDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $meetingDate);
        if (!$meetingDateObject instanceof DateTimeImmutable) {
            $meetingDate = '';
        } else {
            $meetingDate = $meetingDateObject->format('Y-m-d');
        }

        $conductorId = max(0, (int)($source['conductor_id'] ?? 0));

        return [
            'meeting_date' => $meetingDate,
            'conductor_id' => $conductorId,
        ];
    }
}

if (!function_exists('planningDashboardBuildQueryString')) {
    function planningDashboardBuildQueryString(array $filters): string
    {
        $query = [];

        if (!empty($filters['meeting_date'])) {
            $query['meeting_date'] = (string)$filters['meeting_date'];
        }
        if (!empty($filters['conductor_id'])) {
            $query['conductor_id'] = (int)$filters['conductor_id'];
        }

        return http_build_query($query);
    }
}

if (!function_exists('planningDashboardStatusConfig')) {
    function planningDashboardStatusConfig(): array
    {
        return [
            'sans_planning' => ['label' => 'Sans planning', 'badge' => 'bg-slate-100 text-slate-700', 'dot' => 'bg-slate-400'],
            'a_demarrer' => ['label' => 'A demarrer', 'badge' => 'bg-amber-100 text-amber-800', 'dot' => 'bg-amber-500'],
            'maitrise' => ['label' => 'Sous controle', 'badge' => 'bg-emerald-100 text-emerald-800', 'dot' => 'bg-emerald-500'],
            'retard' => ['label' => 'En retard', 'badge' => 'bg-rose-100 text-rose-800', 'dot' => 'bg-rose-500'],
            'bloque' => ['label' => 'Bloque', 'badge' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-600'],
            'termine' => ['label' => 'Presque termine', 'badge' => 'bg-sky-100 text-sky-800', 'dot' => 'bg-sky-500'],
        ];
    }
}

if (!function_exists('planningDashboardComputeOverview')) {
    function planningDashboardComputeOverview(array $planningRows): array
    {
        $overview = [
            'total_chantiers' => count($planningRows),
            'with_import_count' => 0,
            'without_import_count' => 0,
            'blocked_projects' => 0,
            'delayed_projects' => 0,
            'tracked_projects' => 0,
            'planned_progress_numerator' => 0.0,
            'actual_progress_numerator' => 0.0,
            'progress_weight' => 0,
            'budget_reference_total' => 0.0,
            'budget_depense_total' => 0.0,
            'state_counts' => [
                'sans_planning' => 0,
                'a_demarrer' => 0,
                'maitrise' => 0,
                'retard' => 0,
                'bloque' => 0,
                'termine' => 0,
            ],
        ];

        foreach ($planningRows as $row) {
            $healthStatus = (string)($row['health_status'] ?? 'sans_planning');
            if (!isset($overview['state_counts'][$healthStatus])) {
                $overview['state_counts'][$healthStatus] = 0;
            }
            $overview['state_counts'][$healthStatus]++;

            if (!empty($row['has_import'])) {
                $overview['with_import_count']++;
                $weight = max(1, (int)($row['leaf_count'] ?? 0));
                $overview['planned_progress_numerator'] += (float)($row['planned_progress'] ?? 0) * $weight;
                $overview['actual_progress_numerator'] += (float)($row['actual_progress'] ?? 0) * $weight;
                $overview['progress_weight'] += $weight;

                if ((int)($row['tracked_leaf_count'] ?? 0) > 0) {
                    $overview['tracked_projects']++;
                }
            } else {
                $overview['without_import_count']++;
            }

            if ((int)($row['blocked_count'] ?? 0) > 0 || $healthStatus === 'bloque') {
                $overview['blocked_projects']++;
            }
            if ((int)($row['delayed_count'] ?? 0) > 0 || $healthStatus === 'retard') {
                $overview['delayed_projects']++;
            }

            $overview['budget_reference_total'] += (float)($row['budget_reference'] ?? 0);
            $overview['budget_depense_total'] += (float)($row['total_depenses'] ?? 0);
        }

        $overview['global_planned_progress'] = $overview['progress_weight'] > 0
            ? round($overview['planned_progress_numerator'] / $overview['progress_weight'], 1)
            : 0.0;
        $overview['global_actual_progress'] = $overview['progress_weight'] > 0
            ? round($overview['actual_progress_numerator'] / $overview['progress_weight'], 1)
            : 0.0;
        $overview['global_budget_consumption'] = $overview['budget_reference_total'] > 0
            ? round(($overview['budget_depense_total'] / $overview['budget_reference_total']) * 100, 1)
            : 0.0;

        return $overview;
    }
}

if (!function_exists('planningDashboardBuildChartPayload')) {
    function planningDashboardBuildChartPayload(array $planningRows, array $overview): array
    {
        $chartRows = array_values(array_filter($planningRows, static function (array $row): bool {
            return !empty($row['has_import']);
        }));

        usort($chartRows, static function (array $left, array $right): int {
            $leftDelay = (float)($left['planned_progress'] ?? 0) - (float)($left['actual_progress'] ?? 0);
            $rightDelay = (float)($right['planned_progress'] ?? 0) - (float)($right['actual_progress'] ?? 0);
            return $rightDelay <=> $leftDelay;
        });
        $chartRows = array_slice($chartRows, 0, 10);

        $budgetRows = array_values(array_filter($planningRows, static function (array $row): bool {
            return ((float)($row['budget_reference'] ?? 0)) > 0;
        }));
        usort($budgetRows, static function (array $left, array $right): int {
            return ((float)($right['budget_reference'] ?? 0)) <=> ((float)($left['budget_reference'] ?? 0));
        });
        $budgetRows = array_slice($budgetRows, 0, 10);

        $buildLabel = static function (array $row): string {
            $code = trim((string)($row['code'] ?? ''));
            $libelle = trim((string)($row['libelle'] ?? ''));
            return $code !== '' ? ($code . ' - ' . $libelle) : $libelle;
        };

        return [
            'labels' => array_map($buildLabel, $chartRows),
            'planned' => array_map(static function (array $row): float {
                return round((float)($row['planned_progress'] ?? 0), 1);
            }, $chartRows),
            'actual' => array_map(static function (array $row): float {
                return round((float)($row['actual_progress'] ?? 0), 1);
            }, $chartRows),
            'states' => [
                'labels' => [
                    'Sans planning',
                    'A demarrer',
                    'Sous controle',
                    'En retard',
                    'Bloque',
                    'Presque termine',
                ],
                'values' => [
                    (int)($overview['state_counts']['sans_planning'] ?? 0),
                    (int)($overview['state_counts']['a_demarrer'] ?? 0),
                    (int)($overview['state_counts']['maitrise'] ?? 0),
                    (int)($overview['state_counts']['retard'] ?? 0),
                    (int)($overview['state_counts']['bloque'] ?? 0),
                    (int)($overview['state_counts']['termine'] ?? 0),
                ],
            ],
            'budget_link' => [
                'labels' => array_map($buildLabel, $budgetRows),
                'actual' => array_map(static function (array $row): float {
                    return round((float)($row['actual_progress'] ?? 0), 1);
                }, $budgetRows),
                'budget' => array_map(static function (array $row): float {
                    return round((float)($row['budget_consumption_percent'] ?? 0), 1);
                }, $budgetRows),
            ],
        ];
    }
}
