<?php
declare(strict_types=1);

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/CompanyBranding.php';

$brand = CompanyBranding::get(Database::getConnection());
$color = static function (string $key, string $fallback) use ($brand): string {
    $value = strtolower(trim((string)($brand[$key] ?? $fallback)));
    return preg_match('/^#[0-9a-f]{6}$/', $value) ? $value : $fallback;
};
$hexRgb = static function (string $hex): string {
    return hexdec(substr($hex, 1, 2)) . ' ' . hexdec(substr($hex, 3, 2)) . ' ' . hexdec(substr($hex, 5, 2));
};
$contrast = static function (string $hex): string {
    $r = hexdec(substr($hex, 1, 2));
    $g = hexdec(substr($hex, 3, 2));
    $b = hexdec(substr($hex, 5, 2));
    return (($r * 299 + $g * 587 + $b * 114) / 1000) >= 150 ? '#050505' : '#ffffff';
};

$primary = $color('primary_color', '#050505');
$accent = $color('accent_color', '#ffcc20');
$surface = $color('surface_color', '#ffffff');
$text = $color('text_color', '#0f172a');
$muted = $color('muted_color', '#64748b');

header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: private, max-age=60, must-revalidate');
?>
:root {
    --brand-primary: <?= $primary ?>;
    --brand-primary-rgb: <?= $hexRgb($primary) ?>;
    --brand-on-primary: <?= $contrast($primary) ?>;
    --brand-accent: <?= $accent ?>;
    --brand-accent-rgb: <?= $hexRgb($accent) ?>;
    --brand-on-accent: <?= $contrast($accent) ?>;
    --brand-surface: <?= $surface ?>;
    --brand-text: <?= $text ?>;
    --brand-muted: <?= $muted ?>;
    --finea-brand: var(--brand-accent);
    --finea-brand-ink: var(--brand-primary);
    --finea-blue: var(--brand-primary);
    --finea-gold: var(--brand-accent);
    --bs-primary: <?= $primary ?>;
    --bs-primary-rgb: <?= str_replace(' ', ',', $hexRgb($primary)) ?>;
}

body { color: var(--brand-text); }

/* Compatibilité Tailwind : anciennes couleurs d'identité vers le branding. */
:where(.bg-indigo-500,.bg-indigo-600,.bg-indigo-700,.bg-indigo-800,.bg-blue-600,.bg-blue-700,.bg-violet-600,.bg-violet-700,.bg-purple-600,.bg-purple-700) {
    background-color: var(--brand-primary) !important;
    color: var(--brand-on-primary) !important;
}
:where(.bg-black,.bg-slate-950,.bg-slate-900) { background-color:var(--brand-primary) !important;color:var(--brand-on-primary) !important; }
.bg-\[\#ffcc20\] { background-color:var(--brand-accent) !important;color:var(--brand-on-accent) !important; }
.text-\[\#ffcc20\] { color:var(--brand-accent) !important; }
.border-\[\#ffcc20\] { border-color:var(--brand-accent) !important; }
:where(.bg-amber-400,.bg-amber-500,.bg-yellow-300,.bg-yellow-400,.bg-yellow-500) {
    background-color: var(--brand-accent) !important;
    color: var(--brand-on-accent) !important;
}
:where(.text-indigo-500,.text-indigo-600,.text-indigo-700,.text-indigo-800,.text-blue-600,.text-blue-700,.text-violet-600,.text-violet-700,.text-purple-600,.text-purple-700) {
    color: var(--brand-primary) !important;
}
:where(.text-amber-500,.text-amber-600,.text-amber-700,.text-yellow-500,.text-yellow-600,.text-yellow-700) {
    color: var(--brand-accent) !important;
}
:where(.border-indigo-200,.border-indigo-300,.border-indigo-500,.border-blue-200,.border-blue-300,.border-violet-200,.border-violet-300,.border-purple-200,.border-purple-300) {
    border-color: rgb(var(--brand-primary-rgb) / .28) !important;
}
:where(.border-amber-200,.border-amber-300,.border-yellow-200,.border-yellow-300) {
    border-color: rgb(var(--brand-accent-rgb) / .55) !important;
}
:where(.bg-indigo-50,.bg-indigo-100,.bg-blue-50,.bg-blue-100,.bg-violet-50,.bg-violet-100,.bg-purple-50,.bg-purple-100) {
    background-color: rgb(var(--brand-primary-rgb) / .08) !important;
}
:where(.bg-amber-50,.bg-amber-100,.bg-yellow-50,.bg-yellow-100) {
    background-color: rgb(var(--brand-accent-rgb) / .14) !important;
}
:where([class*="from-indigo-"],[class*="from-blue-"],[class*="from-violet-"],[class*="from-purple-"]) {
    --tw-gradient-from: var(--brand-primary) var(--tw-gradient-from-position) !important;
    --tw-gradient-to: rgb(var(--brand-primary-rgb) / 0) var(--tw-gradient-to-position) !important;
}
:where([class*="via-indigo-"],[class*="via-blue-"],[class*="via-violet-"],[class*="via-purple-"]) {
    --tw-gradient-to: rgb(var(--brand-primary-rgb) / 0) var(--tw-gradient-to-position) !important;
    --tw-gradient-stops: var(--tw-gradient-from),var(--brand-primary) var(--tw-gradient-via-position),var(--tw-gradient-to) !important;
}
:where([class*="to-indigo-"],[class*="to-blue-"],[class*="to-violet-"],[class*="to-purple-"]) {
    --tw-gradient-to: var(--brand-primary) var(--tw-gradient-to-position) !important;
}
:where([class*="from-amber-"],[class*="from-yellow-"]) {
    --tw-gradient-from: var(--brand-accent) var(--tw-gradient-from-position) !important;
    --tw-gradient-to: rgb(var(--brand-accent-rgb) / 0) var(--tw-gradient-to-position) !important;
}
:where([class*="to-amber-"],[class*="to-yellow-"]) {
    --tw-gradient-to: var(--brand-accent) var(--tw-gradient-to-position) !important;
}

/* Composants Bootstrap et composants communs Finéa. */
.btn-primary,.btn-info { --bs-btn-bg:var(--brand-primary);--bs-btn-border-color:var(--brand-primary);--bs-btn-color:var(--brand-on-primary);--bs-btn-hover-bg:var(--brand-primary);--bs-btn-hover-border-color:var(--brand-accent); }
.btn-warning { --bs-btn-bg:var(--brand-accent);--bs-btn-border-color:var(--brand-accent);--bs-btn-color:var(--brand-on-accent); }
.form-control:focus,.form-select:focus,input:focus,select:focus,textarea:focus { border-color:var(--brand-accent) !important;box-shadow:0 0 0 .2rem rgb(var(--brand-accent-rgb) / .18) !important;outline-color:var(--brand-accent); }
.modal-content,[role="dialog"] { border-color:rgb(var(--brand-accent-rgb) / .55); }
.modal-header { border-bottom-color:rgb(var(--brand-accent-rgb) / .45); }
.nav-link.active,.page-item.active .page-link { background-color:var(--brand-primary) !important;border-color:var(--brand-primary) !important;color:var(--brand-on-primary) !important; }
.page-link { color:var(--brand-primary); }
::selection { background:var(--brand-accent);color:var(--brand-on-accent); }
