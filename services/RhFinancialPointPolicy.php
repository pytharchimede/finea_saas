<?php
declare(strict_types=1);

final class RhFinancialPointPolicy
{
    public const KINDS = ['advance'=>'Avances sur salaire', 'loan'=>'Prêts', 'salary'=>'Salaires'];
    public const REQUEST_TYPES = ['advance'=>'avance_salaire', 'loan'=>'pret'];

    public static function amount(mixed $value): int
    {
        if (is_string($value)) $value = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim($value));
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 999999999999
            || floor((float)$value) !== (float)$value) throw new DomainException('Le montant doit être un nombre entier positif en FCFA.');
        return (int)$value;
    }

    public static function decisions(array $lines, array $changes): array
    {
        $known = array_column($lines, 'key');
        foreach (array_keys($changes) as $key) if (!in_array((string)$key, $known, true)) throw new DomainException('Ligne étrangère au point.');
        foreach ($lines as &$line) {
            $change = $changes[$line['key']] ?? [];
            $decision = (string)($change['decision'] ?? $line['decision']);
            if (!in_array($decision, ['approved','rejected','cancelled','deferred'], true)) throw new DomainException('Décision invalide.');
            $amount = self::amount($change['amount'] ?? $line['amount']);
            if ($decision === 'approved' && $amount === 0) throw new DomainException('Une ligne autorisée doit avoir un montant supérieur à zéro.');
            $date = trim((string)($change['deferred_until'] ?? $line['deferred_until'] ?? ''));
            if ($decision === 'deferred') {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date || $date <= date('Y-m-d')) throw new DomainException('Le report doit porter une date future valide.');
            } else $date = '';
            $line['amount'] = $amount; $line['decision'] = $decision; $line['deferred_until'] = $date;
            $line['note'] = mb_substr(trim((string)($change['note'] ?? $line['note'] ?? '')), 0, 1000);
        }
        unset($line);
        return $lines;
    }

    public static function total(array $lines): int
    {
        return array_sum(array_map(static fn(array $line): int => $line['decision'] === 'approved' ? self::amount($line['amount']) : 0, $lines));
    }

    public static function details(string $label, array $lines): string
    {
        $details = [$label];
        foreach ($lines as $line) if ($line['decision'] === 'approved') $details[] = $line['name'].' — '.$line['label'].' — '.number_format(self::amount($line['amount']),0,',',' ').' FCFA';
        return implode("\n", $details);
    }
}
