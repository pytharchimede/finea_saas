<?php
declare(strict_types=1);

function pc_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function pc_number(float|int|string|null $number, int $decimals = 0): string
{
    return number_format((float)$number, $decimals, ',', ' ');
}

function pc_percent(float|int|string|null $number, int $decimals = 1): string
{
    return pc_number((float)$number, $decimals) . '%';
}

function pc_date(?string $date, string $fallback = 'Non renseigné'): string
{
    if (!$date) {
        return $fallback;
    }
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $fallback;
}

function pc_status_label(string $status): string
{
    return [
        'actif' => 'Actif',
        'suspendu' => 'Suspendu',
        'termine' => 'Terminé',
        'archive' => 'Archivé',
        'a_faire' => 'À faire',
        'en_cours' => 'En cours',
        'bloque' => 'Bloqué',
        'annule' => 'Annulé',
        'ouvert' => 'Ouvert',
        'en_traitement' => 'En traitement',
        'resolu' => 'Résolu',
        'abandonne' => 'Abandonné',
        'draft' => 'Brouillon',
        'closed' => 'Clôturée',
        'traite' => 'Traité',
        'present' => 'Présent',
        'absent' => 'Absent',
        'copie' => 'En copie',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
}

function pc_status_class(string $status): string
{
    return [
        'actif' => 'pc-badge-green',
        'termine' => 'pc-badge-blue',
        'archive' => 'pc-badge-slate',
        'suspendu' => 'pc-badge-amber',
        'a_faire' => 'pc-badge-slate',
        'en_cours' => 'pc-badge-blue',
        'bloque' => 'pc-badge-red',
        'annule' => 'pc-badge-slate',
        'ouvert' => 'pc-badge-red',
        'en_traitement' => 'pc-badge-amber',
        'resolu' => 'pc-badge-green',
        'abandonne' => 'pc-badge-slate',
        'draft' => 'pc-badge-amber',
        'closed' => 'pc-badge-green',
        'traite' => 'pc-badge-green',
        'present' => 'pc-badge-green',
        'absent' => 'pc-badge-red',
        'copie' => 'pc-badge-blue',
        'basse' => 'pc-badge-slate',
        'normale' => 'pc-badge-blue',
        'haute' => 'pc-badge-amber',
        'critique' => 'pc-badge-red',
    ][$status] ?? 'pc-badge-slate';
}

function pc_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function pc_flash_set(string $type, string $message): void
{
    $_SESSION['planning_chantier_flash'] = ['type' => $type, 'message' => $message];
}

function pc_flash_get(): ?array
{
    $flash = $_SESSION['planning_chantier_flash'] ?? null;
    unset($_SESSION['planning_chantier_flash']);
    return is_array($flash) ? $flash : null;
}
