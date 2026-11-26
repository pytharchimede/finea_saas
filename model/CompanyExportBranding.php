<?php
declare(strict_types=1);
require_once __DIR__.'/CompanyBranding.php';

/** Shared export identity; no tenant-specific constants. */
final class CompanyExportBranding
{
    public static function creator(): string
    {
        return (string)CompanyBranding::get()['company_name'].' — FINEA';
    }
    public static function rgb(string $role, bool $foreground=false): string
    {
        $palette=CompanyBranding::palette();
        if (!isset($palette[$role])) throw new InvalidArgumentException('Unknown palette role');
        $hex=$foreground?CompanyBranding::contrastColor($palette[$role]):$palette[$role];
        return strtoupper(substr($hex,1));
    }
}
