<?php

declare(strict_types=1);

/**
 * Centralise la frontière entre le catalogue financier officiel et les
 * anciennes structures de réunion qui ont été enregistrées, par erreur, dans
 * les tables de déboursé.
 *
 * Aucun enregistrement n'est supprimé ni modifié. Le prédicat masque seulement
 * les structures techniques de planning dans les écrans et contrôles finance.
 */
final class FinancialPlanningSeparationService
{
    public static function officialDeboursePredicate(string $debourseAlias = 'db'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $debourseAlias)) {
            throw new InvalidArgumentException('Alias SQL de déboursé invalide.');
        }

        $db = $debourseAlias;

        return "NOT (
            {$db}.feb_request_id IS NULL
            AND COALESCE({$db}.montant_debourse, 0) = 0
            AND NULLIF(TRIM({$db}.motif_modif), '') IS NOT NULL
            AND {$db}.created_at >= '2026-07-01 00:00:00'
            AND EXISTS (
                SELECT 1
                FROM chantier_banamur fps_chantier
                WHERE fps_chantier.devis_id = {$db}.devis_id
                  AND fps_chantier.planning_is_office_hub = 1
            )
            AND EXISTS (
                SELECT 1
                FROM ligne_debourse_banamur fps_child
                WHERE fps_child.debourse_id = {$db}.id
            )
            AND NOT EXISTS (
                SELECT 1
                FROM ligne_debourse_banamur fps_financial_child
                WHERE fps_financial_child.debourse_id = {$db}.id
                  AND (
                      fps_financial_child.categorie <> 'Bureau'
                      OR COALESCE(fps_financial_child.montant, 0) <> 0
                  )
            )
        )";
    }

    public static function isOfficialDebourse(PDO $pdo, int $debourseId): bool
    {
        if ($debourseId <= 0) {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM debourse_banamur db
            WHERE db.id = :id
              AND ' . self::officialDeboursePredicate('db');
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $debourseId]);

        return (int)$stmt->fetchColumn() === 1;
    }
}
