<?php
require_once 'model/Database.php';
require_once 'model/User.php';
require_once 'model/AuditTrail.php';
require_once 'repositories/AoAlertPreferenceRepository.php';
require_once 'vendor/autoload.php';
require_once 'repositories/PasskeyRepository.php';
require_once 'services/PasskeyService.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pdo = Database::getConnection();
$userModel = new User($pdo);

$userId = $_SESSION['user_id'];

// Récupérer les données de l'utilisateur via la classe
$user = $userModel->getUserById($userId);
$aoPreferenceRepository = new AoAlertPreferenceRepository($pdo);
$aoPreferences = $aoPreferenceRepository->getForUser((int) $userId);
$passkeyService = new PasskeyService(require __DIR__ . '/../config/webauthn.php');
$passkeyRepository = new PasskeyRepository($pdo, $passkeyService->serializer());
$passkeys = $passkeyRepository->listForUser((int) $userId);
$_SESSION['passkey_csrf'] ??= bin2hex(random_bytes(32));

if (!$user) {
    echo "Utilisateur non trouvé.";
    exit;
}

// Traitement du formulaire de mise à jour du profil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom = $_POST['nom'];
    $prenom = $_POST['prenom'];
    $password = $_POST['password'];
    $hashedPassword = !empty($password) ? hash("sha512", $password) : $user['password'];

    // Traitement de la photo de profil
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $photo = $_FILES['photo'];
        $targetDir = "photo/";
        $targetFile = $targetDir . basename($photo['name']);
        move_uploaded_file($photo['tmp_name'], $targetFile);
    } else {
        $targetFile = $user['photo'] ?: '';
    }

    // Mise à jour via la classe
    $userModel->modifierUtilisateur($userId, [
        'nom' => $nom,
        'prenom' => $prenom,
        'password' => $hashedPassword,
        'photo' => $targetFile
    ]);

    if (\AccessManager::hasPermission('acces_veille_ao') || \AccessManager::hasPermission('ao_manage_alerts') || \AccessManager::hasPermission('ao_admin')) {
        $aoPreferenceRepository->save((int) $userId, [
            'alerts_system_enabled' => isset($_POST['ao_alerts_system_enabled']) ? 1 : 0,
            'alerts_email_enabled' => isset($_POST['ao_alerts_email_enabled']) ? 1 : 0,
            'min_score' => (int) ($aoPreferences['min_score'] ?? 60),
            'keywords' => (string) ($aoPreferences['keywords'] ?? ''),
            'excluded_keywords' => (string) ($aoPreferences['excluded_keywords'] ?? ''),
            'frequency' => (string) ($aoPreferences['frequency'] ?? 'daily_digest'),
        ]);
    }

    // Synchroniser la session pour que la navbar affiche la photo immédiatement.
    $_SESSION['photo'] = $targetFile;
    $_SESSION['nom'] = $nom;
    $_SESSION['prenom'] = $prenom;
    $_SESSION['user_name'] = trim($prenom . ' ' . $nom);

    header('Location: profil.php');
    exit;
}


$page = "profil";
