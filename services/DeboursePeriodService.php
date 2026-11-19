<?php
declare(strict_types=1);

final class DeboursePeriodService
{
    public static function period(PDO $pdo, int $debourseId): array
    {
        $query = $pdo->prepare('SELECT date_debut,date_fin FROM debourse_banamur WHERE id=:id LIMIT 1');
        $query->execute(['id' => $debourseId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        $start = (string) ($row['date_debut'] ?? '');
        $end = (string) ($row['date_fin'] ?? '');
        if (!$row || !self::validDate($start) || !self::validDate($end) || $end < $start) {
            throw new DomainException('Renseignez les dates de début et de fin du déboursé avant de poursuivre.');
        }
        return ['start' => $start, 'end' => $end];
    }

    public static function assertContains(PDO $pdo, int $debourseId, string $start, string $end): array
    {
        $period = self::period($pdo, $debourseId);
        if (!self::validDate($start) || !self::validDate($end) || $end < $start || $start < $period['start'] || $end > $period['end']) {
            $requested = self::validDate($start) && self::validDate($end)
                ? ' (période demandée : ' . date('d/m/Y', strtotime($start)) . ' au ' . date('d/m/Y', strtotime($end)) . ')'
                : '';
            throw new DomainException('La période des travaux de la FEB doit être comprise entre le ' . date('d/m/Y', strtotime($period['start'])) . ' et le ' . date('d/m/Y', strtotime($period['end'])) . ', dates du déboursé' . $requested . '. La date de décaissement est gérée séparément.');
        }
        return $period;
    }

    public static function synchronizeDetails(PDO $pdo, int $debourseId): void
    {
        $period = self::period($pdo, $debourseId);
        $pdo->prepare('UPDATE ligne_debourse_banamur SET date_debut=:start,date_fin=:end WHERE debourse_id=:id AND (date_debut IS NULL OR date_fin IS NULL OR date_debut<>:start_check OR date_fin<>:end_check)')
            ->execute(['start' => $period['start'], 'end' => $period['end'], 'id' => $debourseId, 'start_check' => $period['start'], 'end_check' => $period['end']]);
    }

    private static function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
