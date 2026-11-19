<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess('liste_decaissements_urgents.php');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/UrgentDisbursementRepository.php';

$pdo = \Database::getConnection();
$repo = new \UrgentDisbursementRepository($pdo);
$filter = trim((string)($_GET['etat'] ?? 'tous'));
$search = trim((string)($_GET['q'] ?? ''));
$chantier = trim((string)($_GET['chantier'] ?? ''));
$allowed = ['tous', 'a_regulariser', 'feb_soumise', 'termine'];
if (!in_array($filter, $allowed, true)) $filter = 'tous';
$allRows = $repo->list(['q' => $search, 'chantier' => $chantier]);
$rows = array_values(array_filter($allRows, static function(array $row) use ($filter): bool {
    $status = (string)($row['status'] ?? '');
    if (!in_array($status, ['decaissed', 'regularization_pending', 'regularized'], true)) return false;
    $feb = (int)($row['regularized_feb_request_id'] ?? 0);
    if ($filter === 'a_regulariser') return $feb === 0 && $status !== 'regularized';
    if ($filter === 'feb_soumise') return $feb > 0 && $status !== 'regularized';
    if ($filter === 'termine') return $status === 'regularized';
    return true;
}));
$counts = ['a_regulariser' => 0, 'feb_soumise' => 0, 'termine' => 0];
foreach ($allRows as $row) {
    if (!in_array((string)$row['status'], ['decaissed', 'regularization_pending', 'regularized'], true)) continue;
    if ((string)$row['status'] === 'regularized') ++$counts['termine'];
    elseif ((int)($row['regularized_feb_request_id'] ?? 0) > 0) ++$counts['feb_soumise'];
    else ++$counts['a_regulariser'];
}
$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pageTitle = 'Régularisations urgentes';
$activeMenu = 'urgences';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="mx-auto max-w-7xl px-4 py-8">
  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div><p class="text-xs font-bold uppercase tracking-widest text-indigo-600">Contrôle des décaissements</p>
      <h1 class="text-3xl font-extrabold text-slate-900">Régularisations urgentes</h1>
      <p class="mt-2 text-sm text-slate-600">Les montants ont déjà été décaissés : la FEB sert uniquement à justifier et valider la régularisation.</p></div>
    <a class="rounded-xl border px-4 py-3 text-sm font-semibold" href="liste_decaissements_urgents.php">Voir tous les décaissements</a>
  </div>
  <div class="mb-5 grid gap-3 sm:grid-cols-3">
    <?php foreach (['a_regulariser' => 'FEB à créer', 'feb_soumise' => 'FEB soumises', 'termine' => 'Régularisations clôturées'] as $key => $label): ?>
    <a class="rounded-2xl border bg-white p-5 shadow-sm" href="?etat=<?= $h($key) ?>"><div class="text-sm text-slate-600"><?= $h($label) ?></div><div class="mt-1 text-3xl font-extrabold"><?= (int)$counts[$key] ?></div></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="mb-6 grid gap-3 rounded-2xl border bg-white p-4 md:grid-cols-4">
    <label class="text-sm font-semibold">Demandeur, libellé ou code<input class="mt-1 w-full rounded-xl border p-3" name="q" value="<?= $h($search) ?>" placeholder="Rechercher"></label>
    <label class="text-sm font-semibold">Chantier<input class="mt-1 w-full rounded-xl border p-3" name="chantier" value="<?= $h($chantier) ?>" placeholder="Code ou nom"></label>
    <label class="text-sm font-semibold">État<select class="mt-1 w-full rounded-xl border p-3" name="etat"><?php foreach (['tous'=>'Tous','a_regulariser'=>'FEB à créer','feb_soumise'=>'FEB soumise','termine'=>'Clôturée'] as $value=>$label): ?><option value="<?= $h($value) ?>" <?= $filter === $value ? 'selected' : '' ?>><?= $h($label) ?></option><?php endforeach; ?></select></label>
    <button class="self-end rounded-xl bg-slate-900 p-3 font-bold text-white">Filtrer</button>
  </form>
  <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <?php foreach ($rows as $row):
      $feb = (int)($row['regularized_feb_request_id'] ?? 0);
      $done = (string)$row['status'] === 'regularized';
      $label = $done ? 'Clôturée' : ($feb ? 'FEB soumise' : 'En attente de FEB');
    ?>
    <article class="rounded-2xl border bg-white p-5 shadow-sm">
      <div class="flex items-start justify-between gap-3"><div class="text-xs font-bold text-slate-500"><?= $h($row['request_code'] ?? '') ?></div><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold"><?= $h($label) ?></span></div>
      <h2 class="mt-3 text-lg font-bold text-slate-900"><?= $h($row['libelle'] ?? '') ?></h2>
      <p class="mt-2 text-sm text-slate-600"><?= $h($row['requester_name'] ?? '') ?></p>
      <p class="mt-1 text-sm text-slate-500"><?= $h($row['chantier_label'] ?? '') ?></p>
      <p class="mt-3 text-2xl font-extrabold"><?= number_format((float)($row['amount'] ?? 0), 0, ',', ' ') ?> FCFA</p>
      <p class="mt-2 text-xs text-slate-500">Décaissement : <?= $h($row['decaissed_at'] ?? $row['submitted_at'] ?? '') ?></p>
      <div class="mt-4 border-t pt-4">
      <?php if ($feb): ?>
        <a class="inline-block rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white" href="../validation_feb_detail.php?tab=mine_pending&amp;id=<?= $feb ?>">Examiner la FEB #<?= $feb ?></a>
      <?php else: ?>
        <p class="text-sm font-semibold text-amber-700">Le demandeur doit soumettre sa FEB de régularisation.</p>
      <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?><p class="rounded-2xl border bg-white p-8 text-center text-slate-600">Aucune régularisation ne correspond à ces critères.</p><?php endif; ?>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
