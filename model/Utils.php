<?php
class Utils
{
    public static function dateEnToutesLettres($date)
    {
        $mois = [
            1 => 'janvier',
            2 => 'février',
            3 => 'mars',
            4 => 'avril',
            5 => 'mai',
            6 => 'juin',
            7 => 'juillet',
            8 => 'août',
            9 => 'septembre',
            10 => 'octobre',
            11 => 'novembre',
            12 => 'décembre'
        ];

        $annee = date('Y', strtotime($date));
        $mois_num = date('n', strtotime($date));
        $jour = date('j', strtotime($date));

        return $jour . ' ' . $mois[$mois_num] . ' ' . $annee;
    }

    // Conversion UTF-8 vers ISO-8859-1 avec mb_convert_encoding (recommandé)
    public static function toMbConvertEncoding($string)
    {
        return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8');
    }

    // Conversion UTF-8 vers ISO-8859-1 avec utf8_decode (fallback)
    public static function toUtf8Decode($string)
    {
        // utf8_decode() est déprécié depuis PHP 8.2
        return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8');
    }

    public static function montantEnLettre($nombre)
    {
        // Normaliser nombre
        $nombre = (int)round($nombre);
        // Utilise NumberFormatter si disponible (intl)
        if (class_exists('NumberFormatter')) {
            $fmt = new \NumberFormatter('fr_FR', \NumberFormatter::SPELLOUT);
            $lettres = $fmt->format($nombre);
            return ucfirst($lettres) . ' francs CFA';
        }
        // Fallback pur-PHP en toutes lettres
        $lettres = self::nombreEnLettresFR($nombre);
        return ucfirst($lettres) . ' francs CFA';
    }

    // Fallback: conversion d'un entier en lettres (FR) sans intl
    private static function nombreEnLettresFR(int $n): string
    {
        if ($n === 0) return 'zéro';
        $neg = $n < 0;
        if ($neg) $n = -$n;
        $parts = [];
        $milliards = intdiv($n, 1000000000);
        $n %= 1000000000;
        $millions = intdiv($n, 1000000);
        $n %= 1000000;
        $milliers = intdiv($n, 1000);
        $n %= 1000;

        if ($milliards) {
            $parts[] = ($milliards === 1 ? 'un milliard' : self::convertBelowThousand($milliards) . ' milliards');
        }
        if ($millions) {
            $parts[] = ($millions === 1 ? 'un million' : self::convertBelowThousand($millions) . ' millions');
        }
        if ($milliers) {
            if ($milliers === 1) $parts[] = 'mille';
            else $parts[] = self::convertBelowThousand($milliers) . ' mille';
        }
        if ($n) {
            $parts[] = self::convertBelowThousand($n);
        }
        $txt = trim(implode(' ', $parts));
        return $neg ? 'moins ' . $txt : $txt;
    }

    private static function convertBelowThousand(int $n): string
    {
        $units = [
            0 => 'zéro',
            1 => 'un',
            2 => 'deux',
            3 => 'trois',
            4 => 'quatre',
            5 => 'cinq',
            6 => 'six',
            7 => 'sept',
            8 => 'huit',
            9 => 'neuf',
            10 => 'dix',
            11 => 'onze',
            12 => 'douze',
            13 => 'treize',
            14 => 'quatorze',
            15 => 'quinze',
            16 => 'seize'
        ];
        $tens = [
            20 => 'vingt',
            30 => 'trente',
            40 => 'quarante',
            50 => 'cinquante',
            60 => 'soixante',
            80 => 'quatre-vingt'
        ];
        $out = [];

        $centaines = intdiv($n, 100);
        $reste = $n % 100;
        if ($centaines) {
            if ($centaines === 1) {
                $out[] = 'cent' . ($reste === 0 ? '' : '');
            } else {
                // pluriel de "cent" seulement si pas de reste
                $out[] = $units[$centaines] . ' cent' . ($reste === 0 ? 's' : '');
            }
        }

        if ($reste) {
            if ($reste <= 16) {
                $out[] = $units[$reste];
            } elseif ($reste < 20) { // 17-19
                $out[] = 'dix-' . $units[$reste - 10];
            } elseif ($reste < 70) {
                $d = intdiv($reste, 10) * 10; // 20,30,...,60
                $u = $reste % 10;
                if ($u === 1 && $d !== 80) {
                    $out[] = $tens[$d] . ' et un';
                } elseif ($u > 0) {
                    $out[] = $tens[$d] . '-' . $units[$u];
                } else {
                    $out[] = $tens[$d];
                }
            } elseif ($reste < 80) { // 70-79 => 60 + 10..19
                $u = $reste - 60;
                if ($u === 11) { // 71 => soixante et onze
                    $out[] = 'soixante et onze';
                } else {
                    $out[] = 'soixante-' . self::convertBelowHundred($u);
                }
            } else { // 80-99 => 80 + 0..19
                $u = $reste - 80;
                if ($u === 0) {
                    $out[] = 'quatre-vingts';
                } elseif ($u === 1) {
                    $out[] = 'quatre-vingt-un';
                } else {
                    $out[] = 'quatre-vingt-' . self::convertBelowHundred($u);
                }
            }
        }
        return trim(implode(' ', $out));
    }

    private static function convertBelowHundred(int $n): string
    {
        $units = [
            0 => 'zéro',
            1 => 'un',
            2 => 'deux',
            3 => 'trois',
            4 => 'quatre',
            5 => 'cinq',
            6 => 'six',
            7 => 'sept',
            8 => 'huit',
            9 => 'neuf',
            10 => 'dix',
            11 => 'onze',
            12 => 'douze',
            13 => 'treize',
            14 => 'quatorze',
            15 => 'quinze',
            16 => 'seize'
        ];
        if ($n <= 16) return $units[$n];
        if ($n < 20) return 'dix-' . $units[$n - 10];
        if ($n < 70) {
            $tens = [20 => 'vingt', 30 => 'trente', 40 => 'quarante', 50 => 'cinquante', 60 => 'soixante'];
            $d = intdiv($n, 10) * 10;
            $u = $n % 10;
            if ($u === 1) return $tens[$d] . ' et un';
            return $u ? ($tens[$d] . '-' . $units[$u]) : $tens[$d];
        }
        if ($n < 80) {
            $u = $n - 60;
            if ($u === 11) return 'soixante et onze';
            return 'soixante-' . self::convertBelowHundred($u);
        }
        // 80-99
        $u = $n - 80;
        if ($u === 0) return 'quatre-vingts';
        if ($u === 1) return 'quatre-vingt-un';
        return 'quatre-vingt-' . self::convertBelowHundred($u);
    }

    // Conversion UTF-8 vers windows-1252 pour FPDF (gère mieux les apostrophes et accents)
    public static function toPdfEncoding($string)
    {
        return mb_convert_encoding($string, 'windows-1252', 'UTF-8');
    }

    public static function dateJourCourtFr($date)
    {
        $jours = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
        $timestamp = strtotime($date);
        $jour = $jours[(int)date('w', $timestamp)];
        $dateCourte = date('d/m/y', $timestamp);
        return $jour . ' ' . $dateCourte;
    }
}
