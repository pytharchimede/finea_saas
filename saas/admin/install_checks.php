<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$pdo = require_admin();
$t = tenant($pdo);
$id = (int) $t['id'];

// These checks describe the LOCAL PHP runtime only, never a remote server.
$checks = [];
$add = static function (string $name, bool $ok, string $detail, bool $required = true) use (&$checks): void {
    $checks[] = ['name'=>$name, 'ok'=>$ok, 'detail'=>$detail, 'required'=>$required];
};
$add('Version PHP', PHP_VERSION_ID >= 80200, PHP_VERSION . ' (minimum provisoire : 8.2)');
foreach (['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'json', 'fileinfo', 'curl', 'intl', 'zip'] as $ext) {
    $add('Extension ' . $ext, extension_loaded($ext), extension_loaded($ext) ? 'Disponible' : 'Absente', !in_array($ext, ['curl','intl','zip'], true));
}
$root = dirname(__DIR__, 2);
$add('Autoload Composer', is_file($root . '/vendor/autoload.php'), 'vendor/autoload.php');
$add('Configuration privée', is_file($root . '/.env'), '.env détecté, contenu non affiché');
$add('Bibliothèque PDO MariaDB', extension_loaded('pdo_mysql'), 'Pilote PDO MySQL');
$add('Connexion plateforme', true, 'Connexion à la base plateforme établie (ne valide pas la base métier)');
$add('Schéma métier versionné', is_dir($root . '/database/migrations') || is_dir($root . '/migrations'), 'À confirmer depuis le dépôt ERP; les migrations SaaS ne constituent pas le schéma métier', false);
$add('Exécution sur serveur distant', false, 'Non testée : nécessite un agent ou une connexion SSH sécurisée', false);
$requiredOk = !array_filter($checks, static fn(array $c): bool => $c['required'] && !$c['ok']);

shell_top('Diagnostic d’installation', 'Contrôles en lecture seule sur le serveur PHP local pour ' . (string) $t['company_name']);
echo '<div class="actions" style="margin-bottom:20px"><a class="btn" href="install.php?id='.$id.'">← Assistant</a><a class="btn" href="company.php?id='.$id.'">Fiche entreprise</a></div>';
echo '<div class="notice '.($requiredOk?'success':'error').'">'.($requiredOk?'Prérequis locaux de base satisfaits.':'Certains prérequis locaux obligatoires sont absents.').' Aucun schéma métier n’a été créé, aucune base tenant n’a été modifiée.</div>';
echo '<section class="panel"><h2>Vérifications de l’environnement local</h2><div class="keyval"><strong>Contrôle</strong><strong>Résultat</strong></div>';
foreach ($checks as $check) {
    $status = $check['ok'] ? 'OK' : ($check['required'] ? 'ÉCHEC' : 'À vérifier');
    echo '<div class="keyval"><span><strong>'.esc($check['name']).'</strong><br><small>'.esc($check['detail']).'</small></span><strong>'.esc($status).'</strong></div>';
}
echo '</section><div class="notice">Ce diagnostic ne teste pas la connexion MariaDB de l’entreprise ni le serveur distant. Pour le faire sans exposer de secrets, il faudra exécuter les vérifications sur la cible et fournir les migrations métier validées. L’activation de FINEA reste bloquée.</div>';
shell_bottom();
