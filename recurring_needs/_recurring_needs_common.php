<?php

if (!function_exists('rn_h')) {
    function rn_h($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('logi_h')) {
    function logi_h($v): string
    {
        return rn_h($v);
    }
}

if (!function_exists('rn_number')) {
    function rn_number($value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, ',', ' ');
    }
}

if (!function_exists('rn_money')) {
    function rn_money($value): string
    {
        return number_format((float) $value, 0, ',', ' ') . ' XOF';
    }
}

if (!function_exists('rn_build_stats')) {

    function rn_build_stats(array $items): array
    {
        $stats = [
            'total' => 0,
            'active' => 0,
            'pending' => 0,
            'suspended' => 0,
            'activeAmount' => 0,
            'monthlyExposure' => 0,
            'upcoming' => 0,
            'monthly_amount' => 0,
        ];

        $today = new DateTimeImmutable('today');
        $limit = $today->modify('+7 days');

        foreach ($items as $item) {
            $stats['total']++;

            $status = strtolower((string)($item['status'] ?? ''));
            $amount = (float)($item['amount'] ?? $item['estimated_amount'] ?? 0);

            if (in_array($status, ['active', 'actif'], true)) {
                $stats['active']++;
                $stats['activeAmount'] += $amount;
                $interval = max(1, (int)($item['frequency_interval'] ?? 1));
                $stats['monthlyExposure'] += match ((string)($item['frequency_type'] ?? 'monthly')) {
                    'daily' => ($amount * 30) / $interval,
                    'weekly' => ($amount * 52 / 12) / $interval,
                    'quarterly' => $amount / (3 * $interval),
                    'semesterly' => $amount / (6 * $interval),
                    'yearly' => $amount / (12 * $interval),
                    default => $amount / $interval,
                };
            }

            if (in_array($status, ['pending', 'submitted', 'pending_validation', 'a_valider', 'à valider'], true)) {
                $stats['pending']++;
            }

            if (in_array($status, ['suspended', 'suspendu'], true)) {
                $stats['suspended']++;
            }

            $dueDate = $item['next_due_date'] ?? null;

            if ($dueDate) {
                try {
                    $due = new DateTimeImmutable($dueDate);

                    if ($due >= $today && $due <= $limit) {
                        $stats['upcoming']++;
                    }
                } catch (Throwable $e) {
                    // date invalide ignorée
                }
            }

            $stats['monthly_amount'] += $amount;
        }

        return $stats;
    }
}

if (!function_exists('rn_dashboard_tabs')) {
    function rn_dashboard_tabs(string $active = 'dashboard'): string
    {
        $tabs = [
            ['key' => 'dashboard', 'label' => 'Vue générale', 'href' => 'index.php', 'icon' => 'fa-chart-line'],
            ['key' => 'new', 'label' => 'Nouveau besoin', 'href' => 'add_form.php', 'icon' => 'fa-plus'],
            ['key' => 'pending', 'label' => 'À valider', 'href' => 'index.php?status=pending_validation', 'icon' => 'fa-user-check'],
            ['key' => 'active', 'label' => 'Actifs', 'href' => 'index.php?status=active', 'icon' => 'fa-arrows-rotate'],
            ['key' => 'history', 'label' => 'Historique', 'href' => 'index.php?view=history#historique-generations', 'icon' => 'fa-clock-rotate-left'],
        ];

        $html = '<nav class="mt-6 flex gap-3 overflow-x-auto rounded-[26px] border border-white/80 bg-white/80 p-2 shadow-sm backdrop-blur">';

        foreach ($tabs as $tab) {
            $class = $active === $tab['key']
                ? 'bg-[#1d2b57] text-white shadow-lg shadow-slate-900/15'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950';

            $html .= '<a href="' . rn_h($tab['href']) . '" class="inline-flex shrink-0 items-center rounded-2xl px-4 py-3 text-sm font-semibold transition ' . $class . '">';
            $html .= '<i class="fas ' . rn_h($tab['icon']) . ' mr-2"></i>' . rn_h($tab['label']);
            $html .= '</a>';
        }

        $html .= '</nav>';
        return $html;
    }
}


if (!function_exists('rn_days_until')) {
    function rn_days_until(?string $date): int
    {
        if (!$date) {
            return 0;
        }

        try {
            $today = new DateTimeImmutable('today');
            $target = new DateTimeImmutable($date);
            return (int) $today->diff($target)->format('%r%a');
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('rn_short_date')) {
    function rn_short_date(?string $date): string
    {
        if (!$date) {
            return 'Non définie';
        }

        try {
            return (new DateTimeImmutable($date))->format('d/m/Y');
        } catch (Throwable $e) {
            return 'Date invalide';
        }
    }
}

if (!function_exists('rn_frequency_label')) {
    function rn_frequency_label(?string $frequency): string
    {
        return match ($frequency) {
            'daily' => 'Quotidien',
            'weekly' => 'Hebdomadaire',
            'monthly' => 'Mensuel',
            'quarterly' => 'Trimestriel',
            'semesterly' => 'Semestriel',
            'yearly' => 'Annuel',
            default => 'Non définie',
        };
    }
}

if (!function_exists('rn_priority_label')) {
    function rn_priority_label(?string $priority): string
    {
        return match ($priority) {
            'low', 'basse' => 'Basse',
            'normal', 'normale' => 'Normale',
            'high', 'haute' => 'Haute',
            'critical', 'critique' => 'Critique',
            default => 'Normale',
        };
    }
}

if (!function_exists('rn_priority_badge_class')) {
    function rn_priority_badge_class(?string $priority): string
    {
        return match ($priority) {
            'low', 'basse' => 'bg-slate-100 text-slate-700',
            'normal', 'normale' => 'bg-blue-100 text-blue-700',
            'high', 'haute' => 'bg-orange-100 text-orange-700',
            'critical', 'critique' => 'bg-red-100 text-red-700',
            default => 'bg-slate-100 text-slate-700',
        };
    }
}

if (!function_exists('rn_status_label')) {
    function rn_status_label(?string $status): string
    {
        return match ($status) {
            'draft' => 'Brouillon',
            'pending_validation', 'pending', 'submitted' => 'À valider',
            'active' => 'Actif',
            'suspended' => 'Suspendu',
            'expired' => 'Expiré',
            'cancelled' => 'Annulé',
            default => 'Inconnu',
        };
    }
}

if (!function_exists('rn_status_badge_class')) {
    function rn_status_badge_class(?string $status): string
    {
        return match ($status) {
            'active' => 'bg-emerald-100 text-emerald-700',
            'pending_validation', 'pending', 'submitted' => 'bg-orange-100 text-orange-700',
            'suspended' => 'bg-slate-100 text-slate-700',
            'expired' => 'bg-red-100 text-red-700',
            'cancelled' => 'bg-red-100 text-red-700',
            default => 'bg-slate-100 text-slate-700',
        };
    }
}
