<?php

declare(strict_types=1);

require_once __DIR__ . '/LogisticsBaseRepository.php';

final class LogisticsDashboardRepository extends LogisticsBaseRepository
{
    public function kpis(): array
    {
        return [
            'assets_total' => (int)$this->scalar('SELECT COUNT(*) FROM logistics_assets'),
            'assets_available' => (int)$this->scalar("SELECT COUNT(*) FROM logistics_assets WHERE status='disponible'"),
            'assets_breakdown' => (int)$this->scalar("SELECT COUNT(*) FROM logistics_assets WHERE status IN ('panne','hors_service')"),
            'drivers_available' => (int)$this->scalar("SELECT COUNT(*) FROM logistics_drivers WHERE availability_status='disponible' AND status='actif'"),
            'requests_pending' => (int)$this->scalar("SELECT COUNT(*) FROM logistics_requests WHERE status IN ('soumise','en_etude','acceptee')"),
            'maintenance_pending' => (int)$this->scalar("SELECT COUNT(*) FROM logistics_maintenances WHERE status IN ('planifiee','en_cours')"),
            'incidents_open' => (int)$this->scalar("SELECT COUNT(*) FROM logistics_incidents WHERE status IN ('ouvert','en_cours')"),
            'fuel_month_cost' => (float)$this->scalar("SELECT COALESCE(SUM(total_cost),0) FROM logistics_fuel_logs WHERE fuel_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
            'documents_expiring' => (int)$this->scalar("SELECT COUNT(*) FROM logistics_documents WHERE expires_at IS NOT NULL AND expires_at <= DATE_ADD(CURDATE(), INTERVAL alert_days_before DAY)")
        ];
    }

    public function alerts(): array
    {
        $alerts = [];
        foreach ($this->fetchAll("SELECT designation, status FROM logistics_assets WHERE status IN ('panne','hors_service') ORDER BY id DESC LIMIT 5") as $row) {
            $alerts[] = ['level'=>'danger','title'=>'Engin indisponible','message'=>$row['designation'].' — '.$row['status']];
        }
        foreach ($this->fetchAll("SELECT document_type, reference, expires_at FROM logistics_documents WHERE expires_at IS NOT NULL AND expires_at <= DATE_ADD(CURDATE(), INTERVAL alert_days_before DAY) ORDER BY expires_at ASC LIMIT 5") as $row) {
            $alerts[] = ['level'=>'warning','title'=>'Document à renouveler','message'=>$row['document_type'].' '.$row['reference'].' expire le '.$row['expires_at']];
        }
        foreach ($this->fetchAll("SELECT title, planned_at FROM logistics_maintenances WHERE status='planifiee' AND planned_at <= DATE_ADD(NOW(), INTERVAL 7 DAY) ORDER BY planned_at ASC LIMIT 5") as $row) {
            $alerts[] = ['level'=>'info','title'=>'Maintenance proche','message'=>$row['title'].' prévue le '.$row['planned_at']];
        }
        return $alerts;
    }

    public function recent(string $table, int $limit = 8): array
    {
        $allowed = ['logistics_requests','logistics_assignments','logistics_fuel_logs','logistics_maintenances','logistics_incidents','logistics_trip_batches'];
        if (!in_array($table, $allowed, true)) return [];
        return $this->fetchAll("SELECT * FROM $table ORDER BY id DESC LIMIT $limit");
    }
}
