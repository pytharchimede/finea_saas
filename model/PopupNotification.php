<?php

class PopupNotification
{
    public const TYPE_OPTIONS = [
        'nouveau_module' => 'Nouveau module',
        'nouvelle_fonctionnalite' => 'Nouvelle fonctionnalité',
        'correction' => 'Correction',
        'maintenance' => 'Maintenance',
        'information' => 'Information',
        'mise_a_jour' => 'Mise à jour',
        'sondage' => 'Sondage',
    ];

    public const PRIORITY_OPTIONS = [
        'low' => 'Faible',
        'normal' => 'Normale',
        'high' => 'Haute',
        'critical' => 'Critique',
    ];

    public const TARGET_SCOPE_OPTIONS = [
        'all' => 'Tous les utilisateurs',
        'users' => 'Utilisateurs spécifiques',
        'services' => 'Service',
        'module_access' => 'Accès module',
    ];

    public static function normalizeType(string $value): string
    {
        $value = strtolower(trim($value));
        return array_key_exists($value, self::TYPE_OPTIONS) ? $value : 'information';
    }

    public static function normalizePriority(string $value): string
    {
        $value = strtolower(trim($value));
        return array_key_exists($value, self::PRIORITY_OPTIONS) ? $value : 'normal';
    }

    public static function normalizeTargetScope(string $value): string
    {
        $value = strtolower(trim($value));
        return array_key_exists($value, self::TARGET_SCOPE_OPTIONS) ? $value : 'all';
    }
}
