<?php
declare(strict_types=1);

require_once __DIR__ . '/ActiveChantierEditPermissionService.php';

final class ChantierWorkPeriodService
{
    public const PERMISSION = 'modifier_dates_travaux';

    public static function save(PDO $pdo, int $devisId, string $start, string $end): bool
    {
        foreach ([$start, $end] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new DomainException('Les dates de début et de fin des travaux sont obligatoires.');
        }
        if ($end < $start) throw new DomainException('La fin des travaux doit suivre leur début.');
        $query = $pdo->prepare('SELECT date_emission,project_start_date,project_end_date FROM devis_banamur WHERE id=:id LIMIT 1 FOR UPDATE');
        $query->execute(['id' => $devisId]);
        $old = $query->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new DomainException('Devis introuvable.');
        if (!empty($old['date_emission']) && $start < $old['date_emission']) throw new DomainException('Les travaux ne peuvent pas commencer avant l’émission du devis.');
        $changed = $start !== (string) $old['project_start_date'] || $end !== (string) $old['project_end_date'];
        if (!$changed) return false;
        if (ActiveChantierEditPermissionService::hasActiveChantier($pdo, $devisId) && empty($_SESSION['permissions'][self::PERMISSION])) {
            throw new DomainException('Le droit « Modifier les dates de début et de fin des travaux » est requis.');
        }
        $pdo->prepare('UPDATE devis_banamur SET project_start_date=:start,project_end_date=:end WHERE id=:id')->execute(['start' => $start, 'end' => $end, 'id' => $devisId]);
        $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chantier_banamur' AND COLUMN_NAME IN ('date_debut','date_fin_prevue')")->fetchAll(PDO::FETCH_COLUMN);
        if (count($columns) === 2) {
            $pdo->prepare('UPDATE chantier_banamur SET date_debut=:start,date_fin_prevue=:end WHERE devis_id=:devis AND archived_at IS NULL')->execute(['start' => $start, 'end' => $end, 'devis' => $devisId]);
        }
        $projectTable = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='planning_chantier_projects'")->fetchColumn();
        if ($projectTable) {
            $pdo->prepare('UPDATE planning_chantier_projects p JOIN chantier_banamur c ON c.id=p.chantier_id AND c.archived_at IS NULL SET p.date_debut=:start,p.date_fin_prevue=:end WHERE c.devis_id=:devis')->execute(['start' => $start, 'end' => $end, 'devis' => $devisId]);
        }
        return true;
    }
}
