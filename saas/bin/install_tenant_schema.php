<?php
declare(strict_types=1);
/** CLI-only, empty dedicated DB only. Never uses platform credentials implicitly. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$schemaFile = dirname(__DIR__) . '/database/tenant/schema.sql';
$manifestFile = dirname(__DIR__) . '/database/tenant/manifest.json';
$manifest = json_decode((string)file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
$sql = (string)file_get_contents($schemaFile);
if (!hash_equals($manifest['sha256'], hash('sha256', $sql))) { fwrite(STDERR, "ERREUR : empreinte du schéma incorrecte.\n"); exit(1); }
if (preg_match('/^\s*(DROP|INSERT|UPDATE|DELETE|TRUNCATE|ALTER|REPLACE)\s/m', preg_replace('/^--.*$/m', '', $sql))) { fwrite(STDERR, "ERREUR : instruction interdite dans le schéma.\n"); exit(1); }
$host = getenv('FINEA_INSTALL_DB_HOST') ?: '127.0.0.1';
$db = getenv('FINEA_INSTALL_DB_NAME') ?: '';
$user = getenv('FINEA_INSTALL_DB_USER') ?: '';
$pass = getenv('FINEA_INSTALL_DB_PASS');
$confirm = getenv('FINEA_INSTALL_CONFIRM') ?: '';
if (!preg_match('/^finea_tenant_[a-z0-9_]+$/', $db) || !$user || $pass === false || $confirm !== $db) {
 fwrite(STDERR, "Utilisation : FINEA_INSTALL_DB_HOST, FINEA_INSTALL_DB_NAME=finea_tenant_..., FINEA_INSTALL_DB_USER, FINEA_INSTALL_DB_PASS, FINEA_INSTALL_CONFIRM=<nom identique>.\nAucune base n'est créée automatiquement.\n"); exit(1);
}
try {
 $pdo = new PDO("mysql:host={$host};dbname={$db};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
 $existing=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")->fetchColumn();
 if ($existing !== 0) { throw new RuntimeException("La base cible n'est pas vide ({$existing} objets). Installation refusée."); }
 $statements=preg_split('/;\s*(?:\n|$)/', $sql);
 $create=array_values(array_filter(array_map('trim',$statements), static fn($s)=>str_starts_with($s,'CREATE TABLE')));
 // The header comment belongs to the first CREATE statement; remove SQL comments first.
 $normalized=preg_replace('/^--[^\n]*\n/m','',$sql);
 $create=array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\n|$)/',$normalized)),static fn($s)=>$s!==''));
 if (count($create)!==(int)$manifest['tables_count']) { throw new RuntimeException('Nombre de CREATE TABLE inattendu.'); }
 foreach ($create as $statement) if (!preg_match('/^CREATE TABLE `[^`]+`\s*\(/',$statement)) throw new RuntimeException('Instruction SQL inattendue.');
 $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
 $count=0;
 try { foreach ($create as $statement) { $pdo->exec($statement); $count++; if ($count%25===0) fwrite(STDOUT,"Tables créées : {$count}/{$manifest['tables_count']}\n"); } }
 finally { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); }
 $actual=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchColumn();
 if ($actual!==(int)$manifest['tables_count']) throw new RuntimeException("Installation incomplète : {$actual} tables. Ne pas activer le tenant.");
 fwrite(STDOUT,"SUCCÈS : {$actual} tables créées dans {$db}. Aucune donnée métier, aucun administrateur. Tenant NON ACTIVÉ.\n");
} catch(Throwable $e) { fwrite(STDERR,"ÉCHEC : ".$e->getMessage()."\nAttention : les DDL MariaDB ne sont pas transactionnels. Ne pas relancer sur une base partiellement remplie.\n"); exit(1); }
