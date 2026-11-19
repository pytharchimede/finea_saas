<?php
header('Content-Type: application/json; charset=utf-8');

function randName()
{
    $first = ['Jean', 'Awa', 'Koffi', 'Mariam', 'David', 'Ange', 'Yao', 'Fatou', 'Paul', 'Nadia', 'Serge', 'Ines'];
    $last  = ['Kouassi', 'Traoré', 'Nguessan', 'Ouattara', 'Bamba', 'Koné', 'Soro', 'Diabaté', 'Koulibaly', 'Touré'];
    return $first[array_rand($first)] . ' ' . $last[array_rand($last)];
}

function randWord($n = 2)
{
    $w = ['Ciment', 'Ferraillage', 'Transport', 'Main d\'oeuvre', 'Approvisionnement', 'Peinture', 'Outillage', 'Carburant', 'Maintenance', 'Divers'];
    shuffle($w);
    return implode(' ', array_slice($w, 0, $n));
}

function makeDate($daysAgo)
{
    return date('Y-m-d H:i:s', time() - $daysAgo * 86400 + rand(0, 43200));
}

try {
    $type = $_GET['type'] ?? 'conformite';
    $count = max(1, min(24, intval($_GET['count'] ?? 8)));
    $items = [];

    for ($i = 0; $i < $count; $i++) {
        $num = str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $base = [
            'id_fiche' => 1000 + $i,
            'num_fiche' => $num,
            'beficiaire_fiche' => randName(),
            'montant_fiche' => (float)(rand(5, 200) * 1000),
            'date_creat_fiche' => makeDate(rand(0, 30)),
            'photo' => null,
            'precision' => randWord(2),
            'designation' => randWord(1),
        ];

        switch ($type) {
            case 'conformite':
                $debStates = ['under', 'equal', 'over', 'unknown'];
                $items[] = $base + ['debourse_state' => $debStates[array_rand($debStates)]];
                break;
            case 'achat':
                // Conforme + approuvée implicites pour la démo
                $items[] = $base;
                break;
            case 'historique':
                $statuses = [
                    ['status' => 'Approuvée', 'badge' => 'green'],
                    ['status' => 'Refusée', 'badge' => 'red'],
                    ['status' => 'Conforme', 'badge' => 'blue'],
                    ['status' => 'Validée achat', 'badge' => 'indigo'],
                    ['status' => 'En cours', 'badge' => 'gray'],
                ];
                $st = $statuses[array_rand($statuses)];
                $items[] = $base + $st;
                break;
            case 'compta':
                $statuses = [
                    ['status' => 'À imputer', 'badge' => 'gray'],
                    ['status' => 'Imputée', 'badge' => 'indigo'],
                    ['status' => 'Payée', 'badge' => 'green'],
                    ['status' => 'Rejetée', 'badge' => 'red'],
                ];
                $st = $statuses[array_rand($statuses)];
                $items[] = $base + $st + ['piece' => 'PJ-' . $num];
                break;
            case 'otp':
                $statuses = [
                    ['status' => 'En attente OTP', 'badge' => 'orange'],
                    ['status' => 'OTP validée', 'badge' => 'green'],
                    ['status' => 'Refusée', 'badge' => 'red'],
                ];
                $st = $statuses[array_rand($statuses)];
                $items[] = $base + $st + ['otp_required' => (bool)rand(0, 1), 'otp_code' => (string)rand(100000, 999999)];
                break;
            case 'decaissement':
                $statuses = [
                    ['status' => 'En attente décaissement', 'badge' => 'orange'],
                    ['status' => 'Décaissement effectué', 'badge' => 'green'],
                    ['status' => 'Partiel', 'badge' => 'blue'],
                    ['status' => 'Bloqué', 'badge' => 'red'],
                ];
                $modes = ['Espèces', 'Chèque', 'Virement', 'Mobile Money'];
                $st = $statuses[array_rand($statuses)];
                $items[] = $base + $st + ['mode' => $modes[array_rand($modes)]];
                break;
            default:
                // fallback
                $items[] = $base;
        }
    }

    echo json_encode(['ok' => true, 'count' => count($items), 'total' => array_sum(array_column($items, 'montant_fiche')), 'items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
