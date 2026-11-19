<?php

/**
 * Lecteur ZIP de secours pour les hébergements mutualisés sans ext-zip.
 * PhpSpreadsheet attend la classe globale ZipArchive pour lire un XLSX.
 * PHPWord fournit déjà PclZip en pur PHP : cet adaptateur expose l'API minimale
 * attendue sans modifier les bibliothèques installées dans vendor/.
 */
if (!class_exists('ZipArchive', false)) {
    if (!class_exists(\PhpOffice\PhpWord\Settings::class)) {
        require_once __DIR__.'/../vendor/autoload.php';
    }

    \PhpOffice\PhpWord\Settings::setZipClass(\PhpOffice\PhpWord\Settings::PCLZIP);

    class FineaPclZipArchive extends \PhpOffice\PhpWord\Shared\ZipArchive
    {
        public const FL_NOCASE = 1;
    }

    class_alias(FineaPclZipArchive::class, 'ZipArchive');
}
