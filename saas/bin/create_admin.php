<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/src/Database/PlatformDatabase.php';

use Finea\Saas\Database\PlatformDatabase;

$email = 'amani_ulrich@outlook.fr';
$name = 'Administrateur Success\'Lab';

if (!stream_isatty(STDIN)) {
    exit("Erreur : exécuter ce fichier depuis un terminal interactif.\n");
}

echo "Configuration du superadministrateur FINEA SaaS\n";
echo "Email : {$email}\n\n";

echo "Mot de passe : ";

$terminal = trim((string) shell_exec('stty -g 2>/dev/null'));

if ($terminal === '') {
    exit("\nImpossible de sécuriser la saisie.\n");
}

try {
    system('stty -echo');
    $password = rtrim((string) fgets(STDIN), "\r\n");
} finally {
    system('stty ' . escapeshellarg($terminal));
    echo "\n";
}

if (strlen($password) < 8) {
    exit("Erreur : le mot de passe doit contenir au moins 8 caractères.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);
unset($password);

$pdo = PlatformDatabase::connection();

$stmt = $pdo->prepare(
    "INSERT INTO saas_admin_users
        (email, display_name, password_hash, role, active)
     VALUES
        (:email, :display_name, :password_hash, 'superadmin', 1)
     ON DUPLICATE KEY UPDATE
        display_name = VALUES(display_name),
        password_hash = VALUES(password_hash),
        role = 'superadmin',
        active = 1,
        updated_at = NOW()"
);

$stmt->execute([
    'email' => $email,
    'display_name' => $name,
    'password_hash' => $hash,
]);

echo "\nCompte superadministrateur configuré avec succès.\n";
