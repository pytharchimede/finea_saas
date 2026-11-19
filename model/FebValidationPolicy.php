<?php

final class FebValidationPolicy
{
    /**
     * Retourne la liste des services qu'un utilisateur peut valider pour les FEB.
     *
     * Format stocké: "1,2,3" ou "*" / "all".
     *
     * Comportement par défaut (compatibilité): si vide/non configuré, on autorise tous les services.
     */
    public static function getUserServicePermissions(PDO $pdo, int $userId): array
    {
        $raw = '';
        try {
            $stmt = $pdo->prepare('SELECT feb_validation_services FROM user_devis_banamur WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $raw = (string) ($stmt->fetchColumn() ?? '');
        } catch (Throwable $e) {
            $raw = '';
        }

        $raw = trim($raw);
        if ($raw === '') {
            return [
                'all' => false,
                'ids' => [],
                'configured' => false,
                'raw' => '',
            ];
        }

        $lower = strtolower($raw);
        if ($lower === '*' || $lower === 'all' || $lower === 'tous') {
            return [
                'all' => true,
                'ids' => [],
                'configured' => true,
                'raw' => $raw,
            ];
        }

        $parts = preg_split('/[^0-9]+/', $raw) ?: [];
        $ids = [];
        foreach ($parts as $p) {
            $v = (int) $p;
            if ($v > 0) {
                $ids[$v] = $v;
            }
        }

        return [
            'all' => false,
            'ids' => array_values($ids),
            'configured' => true,
            'raw' => $raw,
        ];
    }

    public static function canValidateService(array $perm, int $serviceId): bool
    {
        if (!empty($perm['all'])) {
            return true;
        }
        if ($serviceId <= 0) {
            return false;
        }
        $ids = $perm['ids'] ?? [];
        if (!is_array($ids) || !$ids) {
            return false;
        }
        return in_array($serviceId, $ids, true);
    }

    public static function userHasConfiguredValidationAccess(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $perm = self::getUserServicePermissions($pdo, $userId);
        if (empty($perm['configured'])) {
            return false;
        }

        if (!empty($perm['all'])) {
            return true;
        }

        return !empty($perm['ids']) && is_array($perm['ids']);
    }

    public static function requestServiceId(array $requestRow): int
    {
        $sid = (int) ($requestRow['meta_service_id'] ?? 0);
        if ($sid > 0) {
            return $sid;
        }

        $payload = $requestRow['payload'] ?? [];
        if (is_array($payload)) {
            $sid = (int) ($payload['departement_id'] ?? 0);
            if ($sid > 0) {
                return $sid;
            }
        }

        return 0;
    }
}
