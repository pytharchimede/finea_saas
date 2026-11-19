<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/Database/PlatformDatabase.php';
use Finea\Saas\Database\PlatformDatabase;
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('FINEA_SAAS_ADMIN');
session_set_cookie_params(['lifetime'=>0,'path'=>'/finea_saas/saas/admin/','secure'=>$https,'httponly'=>true,'samesite'=>'Strict']);
session_start();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
function esc(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function check_csrf(): bool { return is_string($_POST['csrf'] ?? null) && hash_equals(csrf_token(), $_POST['csrf']); }
function platform(): PDO { return PlatformDatabase::connection(); }
function require_admin(): PDO {
 if (empty($_SESSION['admin_id'])) { header('Location: index.php', true, 303); exit; }
 try {
  $pdo=platform();
  $s=$pdo->prepare("SELECT id FROM saas_admin_users WHERE id=? AND active=1 AND role='superadmin'");
  $s->execute([(int)$_SESSION['admin_id']]);
  if (!$s->fetch()) { unset($_SESSION['admin_id']); header('Location: index.php',true,303); exit; }
  return $pdo;
 } catch (Throwable $e) { error_log('FINEA SaaS admin: '.$e->getMessage()); http_response_code(503); exit('Service temporairement indisponible.'); }
}
function tenant(PDO $pdo): array {
 $id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
 if (!$id || $id<1) { http_response_code(400); exit('Identifiant entreprise invalide.'); }
 $s=$pdo->prepare('SELECT t.*, b.company_name AS branding_company, b.legal_name AS branding_legal, b.capital, b.logo_path, b.icon_path, b.surface_color, b.text_color, b.muted_color, b.address, b.phone, b.email, b.rccm, b.tax_number, b.primary_color, b.accent_color FROM saas_tenants t LEFT JOIN saas_tenant_branding b ON b.tenant_id=t.id WHERE t.id=?');
 $s->execute([$id]); $row=$s->fetch(PDO::FETCH_ASSOC);
 if (!$row) { http_response_code(404); exit('Entreprise introuvable.'); }
 return $row;
}
function shell_top(string $title, string $subtitle, string $active='companies'): void {
 $items=['dashboard'=>['index.php','layout-dashboard','Vue globale'],'companies'=>['index.php#companies','building-2','Entreprises'],'new'=>['create.php','plus','Nouvelle entreprise']];
 echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc($title).' · FINEA Cloud</title><link rel="stylesheet" href="assets/control.css"></head><body><div class="app"><aside class="sidebar"><div class="brand"><div class="brand-mark">F</div><div><strong>FINEA <em>Cloud</em></strong><small>SUCCESS\'LAB CONTROL CENTER</small></div></div><div class="side-label">ESPACE DE PILOTAGE</div><nav>';
 foreach ($items as $key=>$item) echo '<a class="nav-link '.($active===$key?'active':'').'" href="'.esc($item[0]).'"><span class="nav-dot"></span>'.esc($item[2]).'</a>';
 echo '</nav><div class="side-bottom"><div class="avatar">S</div><div><strong>Success\'Lab</strong><small>Superadministration</small></div></div></aside><div class="workspace"><header class="top"><div class="crumb">SUCCESS\'LAB <span>/</span> '.esc($title).'</div><div class="top-right"><span class="online"></span> Plateforme locale</div></header><main class="content"><div class="page-head"><div><div class="eyebrow">FINEA SAAS · CONTROL CENTER</div><h1>'.esc($title).'</h1><p>'.esc($subtitle).'</p></div></div>';
}
function shell_bottom(): void { echo '</main></div></div></body></html>'; }
