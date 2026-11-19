<?php

require_once __DIR__ . '/../model/FactureAchat.php';
require_once __DIR__ . '/../model/FactureVente.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';

class FacturationService
{
    private FactureAchat $achatModel;
    private FactureVente $venteModel;
    private FournisseurRepository $fournisseurRepository;

    public function __construct(FactureAchat $achatModel, FactureVente $venteModel, FournisseurRepository $fournisseurRepository)
    {
        $this->achatModel = $achatModel;
        $this->venteModel = $venteModel;
        $this->fournisseurRepository = $fournisseurRepository;
    }

    public function achats(): FactureAchat
    {
        return $this->achatModel;
    }

    public function ventes(): FactureVente
    {
        return $this->venteModel;
    }

    public function fournisseurs(): FournisseurRepository
    {
        return $this->fournisseurRepository;
    }

    public function achatListView(int $limit = 200): array
    {
        return [
            'factures' => $this->achatModel->listLatest($limit),
            'fournisseurs' => $this->fournisseurRepository->listForSelect(true),
        ];
    }

    public function venteListView(int $limit = 200): array
    {
        return [
            'factures' => $this->venteModel->listLatest($limit),
            'chantiers' => $this->venteModel->repository()->listChantiersForSelect(),
            'chantierSummary' => $this->venteModel->repository()->getChantierBillingSummary(200),
            'clients' => $this->venteModel->repository()->listClientsForSelect(),
        ];
    }

    public function editAchatView(int $id): array
    {
        $facture = $id > 0 ? $this->achatModel->getById($id) : null;
        $suppliers = $this->fournisseurRepository->listForSelect(true);
        $currentId = (int) ($facture['fournisseur_id'] ?? 0);
        if ($currentId > 0 && !in_array($currentId, array_map(static fn(array $row): int => (int) $row['id_fournisseur'], $suppliers), true)) {
            $current = $this->fournisseurRepository->getById($currentId);
            if ($current) $suppliers[] = $current;
        }
        return ['facture' => $facture, 'fournisseurs' => $suppliers];
    }

    public function editVenteView(int $id): array
    {
        return [
            'facture' => $id > 0 ? $this->venteModel->getById($id) : null,
            'chantiers' => $this->venteModel->repository()->listChantiersForSelect(),
            'clients' => $this->venteModel->repository()->listClientsForSelect(),
        ];
    }

    public function dashboardView(bool $fneConfigured): array
    {
        $achatStats = $this->achatModel->repository()->getOperationalDashboardStats();
        $venteStats = $this->venteModel->repository()->getDashboardStats();
        $venteAmounts = $this->buildVenteAmounts($this->venteModel->repository()->listDashboardAmountRows());

        $alerts = $this->buildAlerts($fneConfigured, $achatStats, $venteStats);

        return [
            'achatStats' => $achatStats,
            'venteStats' => $venteStats,
            'achatDevises' => $this->achatModel->repository()->getCurrencySummary(4),
            'achatLatest' => $this->achatModel->repository()->listOperationalLatest(6),
            'venteLatest' => $this->venteModel->listLatest(6),
            'venteAmounts' => $venteAmounts,
            'chantierBillingSummary' => $this->venteModel->repository()->getChantierBillingSummary(8),
            'alerts' => $alerts,
            'chartAchatStatus' => [
                'labels' => ['Brouillon', 'À vérifier', 'Vérifiée', 'Rejetée', 'Payée'],
                'data' => [
                    (int)$achatStats['brouillon'],
                    (int)$achatStats['a_verifier'],
                    (int)$achatStats['verifiee'],
                    (int)$achatStats['rejetee'],
                    (int)$achatStats['payee'],
                ],
            ],
            'chartVenteStatus' => [
                'labels' => ['Payées / encaissées', 'Émises / à percevoir'],
                'data' => [
                    (int)($venteStats['payee']??0),
                    (int)($venteStats['emise']??0),
                ],
            ],
        ];
    }

    private function buildAlerts(bool $fneConfigured, array $achatStats, array $venteStats): array
    {
        $alerts = [];
        if (!$fneConfigured) {
            $alerts[] = ['label' => 'FNE non configure (cle/API).', 'count' => null, 'type' => 'danger'];
        }
        if ((int)$achatStats['missing_piece'] > 0) {
            $alerts[] = ['label' => 'Achats sans piece FEB ou bon de commande rattache.', 'count' => (int)$achatStats['missing_piece'], 'type' => 'warn'];
        }
        if ((int)$achatStats['rejetee'] > 0) {
            $alerts[] = ['label' => 'Achats rejetes dans le circuit FEB/decaissement.', 'count' => (int)$achatStats['rejetee'], 'type' => 'danger'];
        }
        if ((int)$achatStats['a_verifier'] > 0) {
            $alerts[] = ['label' => 'FEB approuvees en attente de fiche de decaissement.', 'count' => (int)$achatStats['a_verifier'], 'type' => 'info'];
        }
        if ((int)($venteStats['emise']??0) > 0) {
            $alerts[] = ['label' => 'Factures de vente émises restant à encaisser.', 'count' => (int)$venteStats['emise'], 'type' => 'info'];
        }
        return $alerts;
    }

    private function buildVenteAmounts(array $rows): array
    {
        $venteAmounts = ['labels' => [], 'data' => [], 'sum' => 0.0, 'converted_count' => 0, 'missing_rate_count' => 0];
        $venteMonths = [];
        $minMonth = null;
        $maxMonth = null;

        foreach ($rows as $row) {
            try {
                $dt = new DateTimeImmutable((string)($row['created_at'] ?? 'now'));
            } catch (Throwable $e) {
                continue;
            }

            $monthKey = $dt->format('Y-m');
            $monthDt = $dt->modify('first day of this month');
            $minMonth = $minMonth ? ($monthDt < $minMonth ? $monthDt : $minMonth) : $monthDt;
            $maxMonth = $maxMonth ? ($monthDt > $maxMonth ? $monthDt : $maxMonth) : $monthDt;

            $subtotal = $this->computeInvoiceItemsSubtotal((string)($row['items_json'] ?? ''));
            $discount = isset($row['discount']) && is_numeric($row['discount']) ? (float)$row['discount'] : 0.0;
            $totalXof = max(0.0, $subtotal - $discount);

            $foreignCurrency = trim((string)($row['foreign_currency'] ?? ''));
            $rate = isset($row['foreign_currency_rate']) && is_numeric($row['foreign_currency_rate']) ? (float)$row['foreign_currency_rate'] : null;
            if ($foreignCurrency !== '' && $foreignCurrency !== 'XOF') {
                if ($rate !== null && $rate > 0) {
                    $totalXof *= $rate;
                    $venteAmounts['converted_count']++;
                } else {
                    $venteAmounts['missing_rate_count']++;
                }
            }

            $venteMonths[$monthKey] = ($venteMonths[$monthKey] ?? 0.0) + $totalXof;
            $venteAmounts['sum'] += $totalXof;
        }

        if ($minMonth && $maxMonth) {
            $monthsFr = ['Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'];
            $cursor = $minMonth;
            while ($cursor <= $maxMonth) {
                $key = $cursor->format('Y-m');
                $month = (int)$cursor->format('n');
                $venteAmounts['labels'][] = ($monthsFr[$month - 1] ?? $key) . ' ' . $cursor->format('Y');
                $venteAmounts['data'][] = round((float)($venteMonths[$key] ?? 0.0), 2);
                $cursor = $cursor->modify('+1 month');
            }
        }

        return $venteAmounts;
    }

    private function computeInvoiceItemsSubtotal(string $itemsJson): float
    {
        $decoded = json_decode(trim($itemsJson), true);
        if (!is_array($decoded)) {
            return 0.0;
        }

        $items = isset($decoded['items']) && is_array($decoded['items']) ? $decoded['items'] : $decoded;
        $subtotal = 0.0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = isset($item['quantity']) && is_numeric($item['quantity']) ? (float)$item['quantity'] : 1.0;
            $amount = null;
            foreach (['amount', 'unit_price', 'unitPrice'] as $key) {
                if (isset($item[$key]) && is_numeric($item[$key])) {
                    $amount = (float)$item[$key];
                    break;
                }
            }
            if ($qty <= 0 || $amount === null || $amount < 0) {
                continue;
            }
            $line = $qty * $amount;
            if (isset($item['discount']) && is_numeric($item['discount'])) {
                $line = max(0.0, $line - (float)$item['discount']);
            }
            $subtotal += $line;
        }

        return round($subtotal, 2);
    }
}

