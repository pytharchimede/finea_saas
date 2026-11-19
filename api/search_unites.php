<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/UniteMesure.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getConnection();
    $uniteModel = new UniteMesure($pdo);

    $filters = [
        'q' => isset($_GET['q']) ? trim((string)$_GET['q']) : null,
    ];
    if ($filters['q'] === '') {
        $filters['q'] = null;
    }

    $unites = $uniteModel->search($filters);

    ob_start();
    foreach ($unites as $unite) {
?>
        <tr>
            <td><strong><?= htmlspecialchars((string)($unite['symbole'] ?? '')) ?></strong></td>
            <td><?= htmlspecialchars((string)($unite['libelle'] ?? '')) ?></td>
        </tr>
<?php
    }
    $html = (string)ob_get_clean();

    echo json_encode([
        'ok' => true,
        'nb_unites' => count($unites),
        'html' => $html,
    ]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur serveur',
    ]);
}
