<?php
declare(strict_types=1);

final class ActiveChantierEditPermissionService
{
    public const PERMISSION = 'modifier_devis_chantier_actif';

    public static function hasActiveChantier(PDO $pdo, int $devisId): bool
    {
        if ($devisId <= 0) return false;
        static $cache = [];
        if (array_key_exists($devisId, $cache)) return $cache[$devisId];
        $direct = $pdo->prepare('SELECT 1 FROM chantier_banamur WHERE devis_id=:devis AND archived_at IS NULL LIMIT 1');
        $direct->execute(['devis' => $devisId]);
        if ($direct->fetchColumn()) return $cache[$devisId] = true;

        static $linksExist = null;
        if ($linksExist === null) {
            $table = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='devis_chantier_links'");
            $linksExist = (bool) $table->fetchColumn();
        }
        if (!$linksExist) return $cache[$devisId] = false;
        $linked = $pdo->prepare('SELECT 1 FROM devis_chantier_links link JOIN chantier_banamur c ON c.id=link.chantier_id AND c.archived_at IS NULL WHERE link.devis_id=:devis LIMIT 1');
        $linked->execute(['devis' => $devisId]);
        return $cache[$devisId] = (bool) $linked->fetchColumn();
    }

    public static function canEdit(PDO $pdo, int $devisId): bool
    {
        return !empty($_SESSION['permissions'][self::PERMISSION])
            || !self::hasActiveChantier($pdo, $devisId);
    }

    public static function assertCanEdit(PDO $pdo, int $devisId): void
    {
        if (!self::canEdit($pdo, $devisId)) {
            throw new DomainException('Ce devis appartient à un chantier en cours. Le droit spécial « Modifier devis et déboursés d’un chantier en cours » est nécessaire.');
        }
    }

    public static function devisIdForDebourse(PDO $pdo, int $debourseId): int
    {
        $query = $pdo->prepare('SELECT devis_id FROM debourse_banamur WHERE id=:id LIMIT 1');
        $query->execute(['id' => $debourseId]);
        return (int) $query->fetchColumn();
    }

    public static function devisIdForDetail(PDO $pdo, int $detailId): int
    {
        $query = $pdo->prepare('SELECT d.devis_id FROM ligne_debourse_banamur detail JOIN debourse_banamur d ON d.id=detail.debourse_id WHERE detail.id=:id LIMIT 1');
        $query->execute(['id' => $detailId]);
        return (int) $query->fetchColumn();
    }
}
