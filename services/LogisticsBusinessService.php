<?php

declare(strict_types=1);

final class LogisticsBusinessService
{
    public function __construct(private PDO $pdo) {}

    public function assertNoPlanningConflict(int $assetId, ?int $driverId, string $startAt, ?string $endAt, ?int $excludeId = null): void
    {
        $endAt = $endAt ?: $startAt;
        $sql = "SELECT COUNT(*) FROM logistics_assignments WHERE asset_id = :asset_id AND status NOT IN ('annulee','terminee') AND start_at < :end_at AND COALESCE(end_at, start_at) > :start_at";
        $params = ['asset_id'=>$assetId,'start_at'=>$startAt,'end_at'=>$endAt];
        if ($excludeId) { $sql .= ' AND id <> :id'; $params['id'] = $excludeId; }
        $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
        if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('Conflit de planning : cet engin/véhicule est déjà affecté sur cette période.');

        if ($driverId) {
            $sql = "SELECT COUNT(*) FROM logistics_assignments WHERE driver_id = :driver_id AND status NOT IN ('annulee','terminee') AND start_at < :end_at AND COALESCE(end_at, start_at) > :start_at";
            $params = ['driver_id'=>$driverId,'start_at'=>$startAt,'end_at'=>$endAt];
            if ($excludeId) { $sql .= ' AND id <> :id'; $params['id'] = $excludeId; }
            $stmt = $this->pdo->prepare($sql); $stmt->execute($params);
            if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('Conflit de planning : ce chauffeur est déjà affecté sur cette période.');
        }
    }

    public function detectFuelAnomaly(array $data): array
    {
        $qty = (float)($data['quantity'] ?? 0);
        $reason = [];
        if ($qty <= 0) $reason[] = 'Quantité carburant invalide';
        if (empty($data['asset_id'])) $reason[] = 'Aucun engin/véhicule associé';
        if (empty($data['site_id'])) $reason[] = 'Aucun chantier/service consommateur associé';
        if (empty($data['odometer_km']) && empty($data['hour_meter'])) $reason[] = 'Compteur kilométrique ou horaire absent';
        return ['is_anomaly' => $reason ? 1 : 0, 'anomaly_reason' => implode(' ; ', $reason)];
    }

    public function normalizeMoney(float $quantity, float $unitPrice): float
    {
        return round($quantity * $unitPrice, 2);
    }

    public function code(string $prefix): string
    {
        return $prefix . '-' . date('Ymd-His') . '-' . random_int(100, 999);
    }
}
