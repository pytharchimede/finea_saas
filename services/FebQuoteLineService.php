<?php
require_once __DIR__ . '/FebCategoryMigrationService.php';

final class FebQuoteLineService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function sync(int $requestId, array $payload): void
    {
        $lines = (array) ($payload['besoins'] ?? []);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM feb_quote_lines WHERE feb_request_id = :request_id')
                ->execute(['request_id' => $requestId]);
            $insert = $this->pdo->prepare('INSERT INTO feb_quote_lines
                (feb_request_id, line_index, category, designation, item_type, quantity, unit_price, supplier_id, supplier_name_snapshot)
                VALUES (:request_id, :line_index, :category, :designation, :item_type, :quantity, :unit_price, :supplier_id, :supplier_name)');
            foreach ($lines as $index => $line) {
                $supplier = is_array($line['fournisseur'] ?? null) ? $line['fournisseur'] : [];
                $supplierId = (int) ($supplier['id'] ?? 0);
                if ($supplierId <= 0) {
                    throw new RuntimeException('Fournisseur approuve manquant sur la ligne FEB ' . ((int) $index + 1) . '.');
                }
                $insert->execute([
                    'request_id' => $requestId,
                    'line_index' => (int) $index,
                    'category' => FebCategoryMigrationService::normalize((string) ($line['categorie'] ?? '')),
                    'designation' => (string) ($line['libelle'] ?? ''),
                    'item_type' => in_array(($line['type'] ?? ''), ['Bien', 'Service'], true) ? $line['type'] : 'Bien',
                    'quantity' => (float) ($line['quantite'] ?? 0),
                    'unit_price' => (float) ($supplier['prix_unitaire'] ?? 0),
                    'supplier_id' => $supplierId,
                    'supplier_name' => (string) ($supplier['nom'] ?? ''),
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function ensureSchema(): void
    {
        $sql = file_get_contents(__DIR__ . '/../Database/add_feb_quote_lines.sql');
        if ($sql === false) throw new RuntimeException('Migration des lignes de devis FEB introuvable.');
        $this->pdo->exec($sql);
        FebCategoryMigrationService::ensure($this->pdo);
    }
}
