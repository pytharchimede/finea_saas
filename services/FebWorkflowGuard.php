<?php
declare(strict_types=1);

final class FebWorkflowGuard
{
    public function __construct(private PDO $pdo) {}

    public function acquire(int $id): void
    {
        if ($id <= 0) throw new DomainException('FEB invalide.');
        $stmt=$this->pdo->prepare('SELECT GET_LOCK(?, 3)');
        $stmt->execute(['finea_feb_decision_'.$id]);
        if ((int)$stmt->fetchColumn() !== 1) throw new DomainException('Cette FEB est en cours de traitement. Actualisez la liste dans quelques instants.');
    }

    public function release(int $id): void
    {
        $stmt=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(['finea_feb_decision_'.$id]);
    }

    public static function assertPending(array $request): void
    {
        if (($request['request_type'] ?? '') !== 'feb' || !in_array((string)($request['status'] ?? ''), ['submitted','details_requested'], true)) {
            throw new DomainException('Cette FEB est déjà traitée ou indisponible. Rechargez sa fiche avant de continuer.');
        }
    }

    public static function validDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) return false;
        [$year,$month,$day]=array_map('intval', explode('-', $date));
        return checkdate($month,$day,$year);
    }

    public static function supplierId(array $post, array $draft, array $payload): int
    {
        // An explicit selection is authoritative, including an invalid empty selection.
        if (array_key_exists('fd_fournisseur_id', $post)) return max(0, (int)$post['fd_fournisseur_id']);
        foreach ([$draft['fournisseur_id'] ?? 0, $payload['global_fournisseur_id'] ?? 0, $payload['fournisseur_id'] ?? 0, $payload['besoins'][0]['fournisseur']['id'] ?? 0] as $id) {
            if ((int)$id > 0) return (int)$id;
        }
        return 0;
    }
}
