<?php

require_once __DIR__ . '/../controller/PopupNotificationController.php';

class FineaReleaseService
{
    public const VERSION = '1.3.1';
    public const TITLE = 'FINEA v1.3.1 - Gouvernance & pilotage';
    private const LEGACY_TITLES = [
        'FINEA v1.2.1 - Gouvernance & pilotage',
    ];

    public static function ensureCurrentReleasePopup(PDO $pdo, int $actorUserId = 0): ?int
    {
        try {
            $controller = new PopupNotificationController($pdo);
            $service = $controller->service();
            $service->autoCreateTablesIfMissing();
            $candidateTitles = array_merge([self::TITLE], self::LEGACY_TITLES);
            $placeholders = implode(',', array_fill(0, count($candidateTitles), '?'));
            $stmt = $pdo->prepare('SELECT id FROM finea_popup_notifications WHERE title IN (' . $placeholders . ') ORDER BY id LIMIT 1');
            $stmt->execute($candidateTitles);
            $existing = (int) ($stmt->fetchColumn() ?: 0);
            if ($existing > 0) {
                $pdo->prepare("UPDATE finea_popup_notifications SET title=:title, type='mise_a_jour', priority='high', is_active=1, updated_at=NOW() WHERE id=:id")
                    ->execute(['title' => self::TITLE, 'id' => $existing]);
                return $existing;
            }

            return $service->saveFromPost([
                'title' => self::TITLE,
                'type' => 'mise_a_jour',
                'short_message' => 'Nouvelle mise a jour axee sur les droits, le design, la tracabilite et le tableau de bord Direction Generale.',
                'body' => 'Cette version renforce la gouvernance FINEA : audit des droits, composants visuels communs, journalisation enrichie et nouveau pilotage global de la Direction Generale.',
                'module_key' => 'module_direction',
                'priority' => 'high',
                'is_active' => '1',
                'target_scope' => 'module_access',
                'target_modules' => ['module_admin', 'module_direction'],
                'actions' => [[
                    'label' => 'Voir la version',
                    'action_type' => 'link',
                    'action_value' => 'admin/version_finea.php',
                    'is_primary' => 1,
                ]],
            ], $actorUserId);
        } catch (Throwable $e) {
            error_log('FINEA release popup error: ' . $e->getMessage());
            return null;
        }
    }
}
