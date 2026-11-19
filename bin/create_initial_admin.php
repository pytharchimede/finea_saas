<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/model/Database.php';

$pdo = Database::getConnection();

if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'finea_saas_dev') {
    throw new RuntimeException('Base SaaS non autorisée.');
}

function ask(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

$email = ask('E-mail administrateur : ');
$nom = ask('Nom : ');
$prenom = ask('Prénom : ');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Adresse e-mail invalide.\n");
}

if ($nom === '' || $prenom === '') {
    exit("Nom et prénom obligatoires.\n");
}

echo 'Mot de passe (saisie masquée) : ';
system('stty -echo');
try {
    $password = rtrim((string) fgets(STDIN), "\r\n");
} finally {
    system('stty echo');
    echo PHP_EOL;
}

if (strlen($password) < 12) {
    exit("Mot de passe trop court : minimum 12 octets.\n");
}

echo 'Confirmer le mot de passe : ';
system('stty -echo');
try {
    $confirmation = rtrim((string) fgets(STDIN), "\r\n");
} finally {
    system('stty echo');
    echo PHP_EOL;
}

if (!hash_equals($password, $confirmation)) {
    exit("Les deux mots de passe ne correspondent pas.\n");
}

unset($confirmation);

$pdo->beginTransaction();

try {
    $check = $pdo->prepare(
        'SELECT id FROM user_devis_banamur WHERE mail_pro = ? LIMIT 1'
    );
    $check->execute([$email]);

    if ($check->fetchColumn()) {
        throw new RuntimeException('Ce compte existe déjà.');
    }

    $role = $pdo->prepare(
        'SELECT id_role_devis
         FROM role_devis_banamur
         WHERE lib_role_devis = ?
         LIMIT 1'
    );
    $role->execute(['Administrateur FINEA SaaS']);
    $roleId = $role->fetchColumn();

    if (!$roleId) {
        $insertRole = $pdo->prepare(
            'INSERT INTO role_devis_banamur (lib_role_devis) VALUES (?)'
        );
        $insertRole->execute(['Administrateur FINEA SaaS']);
        $roleId = $pdo->lastInsertId();
    }

    $sql = '
        INSERT INTO user_devis_banamur
        (
            mail_pro, password, nom, prenom,
            role_id, active, acces_admin,
            gestion_utilisateur, acces_gestion_acces,
            acces_dashboard, acces_profil,
            photo, signature
        )
        VALUES (?, ?, ?, ?, ?, 1, 1, 1, 1, 1, 1, ?, ?)
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $nom,
        $prenom,
        $roleId,
        '',
        ''
    ]);

    $id = $pdo->lastInsertId();

    $pdo->commit();

    echo "Administrateur créé. ID : {$id}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Échec : " . $e->getMessage() . PHP_EOL);
    exit(1);
}
