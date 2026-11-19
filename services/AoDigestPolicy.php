<?php

final class AoDigestPolicy
{
    public static function isDue(DateTimeImmutable $now): bool
    {
        $now = $now->setTimezone(new DateTimeZone('Africa/Abidjan'));
        return in_array((int) $now->format('N'), [1, 5], true) && (int) $now->format('G') >= 8;
    }

    public static function selectOpportunities(array $rows, int $minScore, DateTimeImmutable $now): array
    {
        $today = $now->setTimezone(new DateTimeZone('Africa/Abidjan'))->format('Y-m-d');
        return array_values(array_filter($rows, static function (array $row) use ($today, $minScore): bool {
            $deadline = (string) ($row['date_limite'] ?? '');
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $deadline) === 1
                && $deadline >= $today && (int) ($row['score_pertinence'] ?? 0) >= $minScore
                && empty($row['alerts_disabled'])
                && ($row['dg_decision'] ?? '') !== 'not_interested'
                && ($row['statut_interne'] ?? '') !== 'non_interessant';
        }));
    }

    public static function envelope(array $recipients): array
    {
        $to = null;
        foreach ($recipients as $recipient) {
            if (($recipient['role'] ?? '') === 'to' && ($recipient['status'] ?? '') === 'included') {
                if ($to !== null) {
                    throw new RuntimeException('Plusieurs destinataires principaux. Vérifiez le responsable commercial.');
                }
                $to = $recipient;
            }
        }
        if ($to === null) {
            throw new RuntimeException('Responsable commercial absent ou sans email valide. Aucun bilan envoyé.');
        }
        $seen = [strtolower(trim($to['email'])) => true];
        $cc = [];
        foreach ($recipients as $recipient) {
            if (($recipient['status'] ?? '') !== 'included' || ($recipient['role'] ?? '') === 'to') {
                continue;
            }
            $key = strtolower(trim($recipient['email']));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $cc[] = ['email' => $recipient['email'], 'name' => $recipient['name']];
            }
        }
        return ['to' => $to, 'cc' => $cc];
    }
}
