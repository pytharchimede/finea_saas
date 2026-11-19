<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/services/ContenuLocalAoAdapter.php';
umask(0077);
try {
    $username = trim((string) fgets(STDIN));
    $password = rtrim((string) fgets(STDIN), "\r\n");
    if (!filter_var($username, FILTER_VALIDATE_EMAIL) || $password === '') throw new RuntimeException('Compte incomplet.');
    $config = ['username' => $username, 'password' => $password];
    $adapter = new ContenuLocalAoAdapter($config);
    $items = $adapter->fetchPublicOpportunities();
    $path = ContenuLocalAoAdapter::configPath();
    $dir = dirname($path);
    $root = realpath(dirname(__DIR__));
    // Le fichier contenant les identifiants reste en dehors de la racine web.
    if (str_starts_with($path, $root . '/') || is_link($dir) || is_link($path)) throw new RuntimeException('Le fichier privé doit être hors de la racine du site.');
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) throw new RuntimeException('Création du dossier privé impossible.');
    $realDir = realpath($dir);
    if ($realDir === $root || str_starts_with($realDir, $root . '/')) throw new RuntimeException('Dossier privé dans la racine web.');
    $tmp = tempnam($dir, '.contenu-');
    if ($tmp === false) throw new RuntimeException('Fichier temporaire impossible.');
    try {
        if (file_put_contents($tmp, '<?php return ' . var_export($config, true) . ';' . PHP_EOL) === false || !chmod($tmp, 0600) || !rename($tmp, $path)) throw new RuntimeException('Enregistrement privé impossible.');
    } finally { if (is_file($tmp)) unlink($tmp); }
    unset($config, $username, $password);
    echo 'Compte vérifié et configuré. ' . count($items) . ' offre(s) publiée(s) accessible(s).' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
