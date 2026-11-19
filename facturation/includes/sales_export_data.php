<?php
declare(strict_types=1);

require_once __DIR__ . '/../../model/Database.php';
require_once __DIR__ . '/../../controllers/FacturationController.php';

function salesExportData(): array
{
    $rows = (new FacturationController(Database::getConnection()))->venteListView(5000)['factures'] ?? [];
    $query = mb_strtolower(trim((string)($_GET['q'] ?? '')), 'UTF-8');
    $status = trim((string)($_GET['status'] ?? ''));
    $today = date('Y-m-d');
    return array_values(array_filter($rows, static function (array $row) use ($query, $status, $today): bool {
        $paid = (string)($row['paiement_statut'] ?? 'emise') === 'payee';
        $due = substr((string)($row['expected_collection_date'] ?? ''), 0, 10);
        $state = $paid ? 'payee' : (($due !== '' && $due < $today) ? 'retard' : 'emise');
        $haystack = mb_strtolower(implode(' ', [
            (string)($row['reference_interne'] ?? ''), (string)($row['client_company_name'] ?? ''),
            (string)($row['chantier_code'] ?? ''), (string)($row['chantier_titre'] ?? ''),
            (string)($row['fne_invoice_id'] ?? ''),
        ]), 'UTF-8');
        $statusMatches = $status === '' || $status === $state
            || ($status === 'emise' && !$paid)
            || ($status === 'fne' && !empty($row['fne_signed_at']));
        return ($query === '' || str_contains($haystack, $query)) && $statusMatches;
    }));
}

function salesExportItems(array $row): array
{
    $decoded = json_decode((string)($row['items_json'] ?? '[]'), true);
    if (!is_array($decoded)) return [];
    if (isset($decoded['items']) && is_array($decoded['items'])) return $decoded['items'];
    return array_is_list($decoded) ? $decoded : [];
}

function salesExportState(array $row): string
{
    if ((string)($row['paiement_statut'] ?? 'emise') === 'payee') return 'Encaissée';
    $due = substr((string)($row['expected_collection_date'] ?? ''), 0, 10);
    return $due !== '' && $due < date('Y-m-d') ? 'En retard' : 'À percevoir';
}

function salesExportTotals(array $row): array
{
    $ht = 0.0; $tax = 0.0;
    foreach (salesExportItems($row) as $item) {
        $lineHt = (float)($item['quantity'] ?? 1) * (float)($item['amount'] ?? 0);
        $ht += $lineHt;
        $tax += $lineHt * (float)($item['tax_rate'] ?? 0);
    }
    $discount = min($ht, max(0.0, (float)($row['discount'] ?? 0)));
    $factor = $ht > 0 ? (($ht - $discount) / $ht) : 1.0;
    $netHt = $ht * $factor;
    $netTax = !empty($row['tax_not_invoiced']) ? 0.0 : $tax * $factor;
    return ['ht' => $netHt, 'tax' => $netTax, 'ttc' => (float)($row['total_ttc'] ?? ($netHt + $netTax))];
}
