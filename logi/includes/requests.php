<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../model/Database.php';
$pdo = \Database::getConnection();

// Validation/refus
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $id = (int)$_POST['id'];
    $action = $_POST['action'];
    if ($id > 0 && in_array($action, ['valider', 'refuser', 'terminer'])) {
        $statut = $action === 'valider' ? 'validée' : ($action === 'refuser' ? 'refusée' : 'terminée');
        $stmt = $pdo->prepare("UPDATE logistics_requests SET statut=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$statut, $id]);
    }
}

$requests = $pdo->query("SELECT r.*, u.nom, u.prenom, a.designation FROM logistics_requests r LEFT JOIN utilisateurs u ON r.user_id=u.id LEFT JOIN logistics_assets a ON r.asset_id=a.id ORDER BY r.created_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <title>Demandes logistiques</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>

<body>
    <h1>Demandes logistiques</h1>
    <table border="1" cellpadding="6">
        <tr>
            <th>ID</th>
            <th>Demandeur</th>
            <th>Type</th>
            <th>Objet</th>
            <th>Début</th>
            <th>Fin</th>
            <th>Motif</th>
            <th>Statut</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($requests as $r): ?>
            <tr>
                <td><?= $r['id'] ?></td>
                <td><?= htmlspecialchars(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')) ?></td>
                <td><?= htmlspecialchars($r['type']) ?></td>
                <td><?= htmlspecialchars($r['designation'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['date_start']) ?></td>
                <td><?= htmlspecialchars($r['date_end']) ?></td>
                <td><?= htmlspecialchars($r['motif']) ?></td>
                <td><?= htmlspecialchars($r['statut']) ?></td>
                <td>
                    <?php if ($r['statut'] === 'en attente'): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button name="action" value="valider">Valider</button>
                            <button name="action" value="refuser">Refuser</button>
                        </form>
                    <?php elseif ($r['statut'] === 'validée'): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button name="action" value="terminer">Clôturer</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</body>

</html>