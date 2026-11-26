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
    public static function rgbArray(string $role, bool $foreground=false): array
    {
        return array_map('hexdec',str_split(self::rgb($role,$foreground),2));
    }
    public static function identityLine(): string
    {
        $b=CompanyBranding::get();
        return implode(' · ',array_filter([$b['legal_name']?:$b['company_name'],$b['capital']?'Capital : '.$b['capital']:'',$b['address']]));
    }
    public static function contactLine(): string
    {
        $b=CompanyBranding::get();
        return implode(' · ',array_filter([$b['phone'],$b['email'],$b['rccm']?'RCCM : '.$b['rccm']:'',$b['tax_number']?'N° CC : '.$b['tax_number']:'']));
    }
    public static function documentUrl(string $path): string
    {
        require_once __DIR__.'/EnvConfig.php';
        EnvConfig::loadFromDirectory(__DIR__);
        $base=rtrim((string)EnvConfig::get('APP_BASE_URL',''),'/');
        if (!filter_var($base,FILTER_VALIDATE_URL)||!in_array(parse_url($base,PHP_URL_SCHEME),['http','https'],true)) throw new RuntimeException('APP_BASE_URL non configurée');
        return $base.'/'.ltrim($path,'/');
    }
}
