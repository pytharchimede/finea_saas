<?php

require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../decaissement/model/Database.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    exit('Connexion requise.');
}

$pdo = Database::getConnection();
$statement = $pdo->prepare(
    "SELECT p.id
       FROM daily_disbursement_points p
       JOIN daily_disbursement_point_authorizations a ON a.point_id = p.id
  LEFT JOIN daily_disbursement_point_signatures s
         ON s.point_id = p.id AND s.user_id = a.user_id
      WHERE a.user_id = :user_id
        AND a.role_code = 'dg'
        AND a.revoked_at IS NULL
        AND p.status = 'stopped'
        AND s.id IS NULL
   ORDER BY p.stopped_at DESC, p.id DESC
      LIMIT 1"
);
$statement->execute(['user_id' => $userId]);
$pointId = (int) $statement->fetchColumn();

if ($pointId > 0) {
    header('Location: ../../decaissement/point_decaissement_jour.php?id=' . $pointId, true, 302);
    exit;
}

// Le lien du modèle WhatsApp est volontairement permanent. Une fois le point
// signé, il mène encore au dernier point autorisé au DG au lieu d'aboutir sur
// une erreur, tandis qu'un nouveau point arrêté reprendra automatiquement la
// priorité lors du prochain accès.
$latestStatement = $pdo->prepare(
    "SELECT p.id
       FROM daily_disbursement_points p
       JOIN daily_disbursement_point_authorizations a ON a.point_id = p.id
      WHERE a.user_id = :user_id
        AND a.role_code = 'dg'
        AND a.revoked_at IS NULL
   ORDER BY COALESCE(p.stopped_at, p.created_at) DESC, p.id DESC
      LIMIT 1"
);
$latestStatement->execute(['user_id' => $userId]);
$latestPointId = (int) $latestStatement->fetchColumn();
if ($latestPointId > 0) {
    header('Location: ../../decaissement/point_decaissement_jour.php?id=' . $latestPointId, true, 302);
    exit;
}

http_response_code(404);
$pageTitle = 'Point journalier';
require_once __DIR__ . '/../../decaissement/includes/header.php';
require_once __DIR__ . '/../../decaissement/includes/navbar.php';
?>
<main class="mx-auto max-w-3xl px-4 py-12">
    <section class="rounded-lg border border-slate-200 bg-white p-8 text-center shadow-sm">
        <h1 class="text-2xl font-black text-slate-950">Aucun point en attente</h1>
        <p class="mt-3 text-slate-600">Vous n'avez actuellement aucun point journalier arrêté à signer.</p>
        <a href="../../decaissement/historique_points_decaissement.php" class="mt-6 inline-block rounded-md bg-slate-950 px-5 py-3 font-bold text-white">Consulter l'historique</a>
    </section>
</main>
<?php require_once __DIR__ . '/../../decaissement/includes/footer.php'; ?>
