<?php

require_once __DIR__ . '/model/Database.php';

$token = trim((string)($_GET['token'] ?? ''));

$title = 'Déblocage de compte - BANAMUR';
$message = '';
$isSuccess = false;

try {
    if ($token === '') {
        throw new RuntimeException('Lien de déblocage invalide.');
    }

    $pdo = \Database::getConnection();

    // Table de sécurité login (au cas où)
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_security (
        user_id INT PRIMARY KEY,
        failed_attempts INT NOT NULL DEFAULT 0,
        locked TINYINT(1) NOT NULL DEFAULT 0,
        unlock_token_hash CHAR(64) NULL,
        unlock_expires_at DATETIME NULL,
        unlock_email_sent_at DATETIME NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_locked (locked)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("SELECT * FROM login_security WHERE unlock_token_hash = :h LIMIT 1");
    $stmt->execute(['h' => $tokenHash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Ce lien est invalide ou a déjà été utilisé.');
    }

    if (empty($row['locked'])) {
        throw new RuntimeException('Votre compte est déjà débloqué.');
    }

    if (!empty($row['unlock_expires_at'])) {
        $now = new DateTimeImmutable('now');
        $exp = new DateTimeImmutable((string)$row['unlock_expires_at']);
        if ($exp < $now) {
            throw new RuntimeException('Ce lien a expiré. Merci de refaire une tentative de connexion pour recevoir un nouveau mail.');
        }
    }

    $pdo->prepare("UPDATE login_security
        SET failed_attempts = 0,
            locked = 0,
            unlock_token_hash = NULL,
            unlock_expires_at = NULL
        WHERE user_id = :user_id")
        ->execute(['user_id' => (int)$row['user_id']]);

    $isSuccess = true;
    $message = 'Votre compte a été débloqué. Vous pouvez vous connecter.';
} catch (Throwable $e) {
    $isSuccess = false;
    $message = $e->getMessage();
}

// Layout selection_portail
$pageTitle = $title;
$activeMenu = '';
require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/partials/auth_finea_mark.php';

$messageClass = 'mt-5 rounded-2xl border px-4 py-3 text-sm';
if ($isSuccess) {
    $messageClass .= ' border-green-200 bg-green-50 text-green-800';
} else {
    $messageClass .= ' border-red-200 bg-red-50 text-red-800';
}
?>

<div class="min-h-screen bg-[#f5f7fb] py-20 px-6 relative overflow-hidden">
    <div class="absolute inset-0 pointer-events-none">
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-indigo-200 rounded-full blur-3xl opacity-20"></div>
        <div class="absolute bottom-0 right-0 w-[28rem] h-[28rem] bg-purple-200 rounded-full blur-3xl opacity-20"></div>
    </div>
    <div class="min-h-[calc(100vh-10rem)] flex items-center justify-center">
        <div class="w-full max-w-md">
            <div class="relative p-8" style="background:#fff;border:1px solid #eef;border-radius:18px;box-shadow:0 4px 12px -2px rgba(0,0,0,.07);">
                <div class="flex items-center justify-center gap-4 mb-6">
                    <img src="https://fidest.ci/logo/logo_banamur_transparent.png" alt="BANAMUR" class="h-10 sm:h-12 w-auto object-contain" loading="eager" />
                    <div class="h-8 w-px bg-gray-200"></div>
                    <img src="https://fidest.ci/logo/logo_fidest.png" alt="FIDEST" class="h-10 sm:h-12 w-auto object-contain" loading="eager" />
                </div>
                <?php renderAuthFineaMark(['subtitle' => 'Déblocage de compte orchestré dans Finéa']); ?>
                <div class="w-16 h-16 mx-auto mb-5 rounded-2xl flex items-center justify-center text-white text-xl shadow-md"
                    style="background: linear-gradient(135deg, #4f46e5, #7e22ce);">
                    <i class="fa-solid <?php echo $isSuccess ? 'fa-unlock-keyhole' : 'fa-circle-exclamation'; ?>"></i>
                </div>

                <div class="text-center">
                    <h2 class="text-2xl font-semibold tracking-tight text-gray-800">Déblocage de compte</h2>
                    <div class="mt-3 h-1 w-24 mx-auto rounded-full bg-gradient-to-r from-indigo-500 to-purple-500"></div>
                </div>

                <div class="<?php echo $messageClass; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>

                <div class="mt-6 text-center">
                    <a href="login.php" class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700 hover:text-indigo-600 transition">
                        <i class="fa-solid fa-arrow-left"></i>
                        Retour à la connexion
                    </a>
                </div>

                <div class="mt-8 text-center text-xs text-gray-500">© 2024 Logiciel de Devis - BANAMUR INDUSTRIES & TECH</div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>