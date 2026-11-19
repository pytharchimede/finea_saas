<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';

function employeeLower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

$numFiche = trim((string) ($_GET['num_fiche'] ?? ''));
if ($numFiche === '') {
    http_response_code(400);
    echo 'Numéro de fiche manquant';
    exit;
}

$connectedName = trim((string) ($_SESSION['user_name'] ?? ''));
if ($connectedName === '') {
    $prenom = trim((string) ($_SESSION['prenom'] ?? ''));
    $nom = trim((string) ($_SESSION['nom'] ?? ''));
    $connectedName = trim($prenom . ' ' . $nom);
}

$pdo = \Database::getConnection();
$stmt = $pdo->prepare('SELECT beficiaire_fiche FROM fiche_decaissement WHERE num_fiche = :num LIMIT 1');
$stmt->execute(['num' => $numFiche]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo 'Fiche introuvable';
    exit;
}

$benef = employeeLower(trim((string) ($row['beficiaire_fiche'] ?? '')));
$userLower = employeeLower($connectedName);

$tokens = preg_split('/\s+/u', $userLower) ?: [];
$tokens = array_values(array_filter(array_unique(array_map('trim', $tokens)), static function (string $t): bool {
    return $t !== '' && (function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t)) >= 3;
}));

$authorized = true;
if ($tokens) {
    foreach ($tokens as $t) {
        if (strpos($benef, $t) === false) {
            $authorized = false;
            break;
        }
    }
} else {
    $authorized = $benef !== '' && $userLower !== '' && strpos($benef, $userLower) !== false;
}

if (!$authorized) {
    http_response_code(403);
    require_once __DIR__ . '/../acces_refuse.php';
    exit;
}

require_once __DIR__ . '/../decaissement/request/export_fiche_decaissement_pdf.php';
exit;
