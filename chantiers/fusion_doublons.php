<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
if (!AccessManager::hasPermission('acces_admin') && !AccessManager::hasPermission('acces_direction')) {
    http_response_code(403);
    exit('Accès réservé à la Direction et aux administrateurs.');
}
require_once __DIR__ . '/../services/ChantierDuplicateMergeService.php';

$pdo = Database::getConnection();
$service = new ChantierDuplicateMergeService($pdo);
$_SESSION['chantier_merge_csrf'] ??= bin2hex(random_bytes(32));
$sourceId = (int) ($_REQUEST['source_id'] ?? 0);
$targetId = (int) ($_REQUEST['target_id'] ?? 0);
$error = $success = '';
$preview = null;
$sites = $pdo->query("SELECT c.id,c.code,c.titre,c.devis_id,d.numero_devis,d.offre_id,d.client_id
    FROM chantier_banamur c JOIN devis_banamur d ON d.id=c.devis_id
    WHERE c.archived_at IS NULL AND COALESCE(d.masque,0)=0 ORDER BY c.code")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byId = [];
foreach ($sites as $site) $byId[(int) $site['id']] = $site;

try {
    if ($sourceId > 0 && $targetId > 0) {
        if (!isset($byId[$sourceId], $byId[$targetId])) throw new DomainException('Sélectionnez deux chantiers actifs.');
        $source = $byId[$sourceId];
        $target = $byId[$targetId];
        $preview = $service->preview((string) $source['code'], (string) $target['code'], (string) $source['numero_devis'], (string) $target['numero_devis']);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals((string) $_SESSION['chantier_merge_csrf'], (string) ($_POST['_csrf'] ?? ''))) throw new DomainException('Session expirée. Rechargez la page.');
            $expected = 'FUSIONNER ' . $source['code'] . ' VERS ' . $target['code'];
            if (trim((string) ($_POST['confirmation'] ?? '')) !== $expected) throw new DomainException('La phrase de confirmation est incorrecte.');
            $postedMap = (array) ($_POST['line_map'] ?? []);
            foreach ($preview['source_lines'] as $line) {
                if (!array_key_exists((int) $line['id'], $postedMap)) throw new DomainException('Choisissez une destination pour chaque ligne du devis source.');
            }
            $lineMap = array_map('intval', $postedMap);
            $service->executePair((string) $source['code'], (string) $target['code'], (string) $source['numero_devis'], (string) $target['numero_devis'], (int) ($_SESSION['user_id'] ?? 0), $lineMap);
            $success = 'Fusion terminée. Les fiches et le chantier conservé ont été contrôlés. Le devis source est vidé, archivé et masqué.';
            $preview = null;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$h = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pageTitle = 'Fusion des doublons de chantier';
$activeMenu = 'module_devis_list';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<main class="min-h-screen bg-slate-50 px-4 py-8"><div class="mx-auto max-w-5xl">
<header class="rounded-3xl bg-slate-950 p-7 text-white"><h1 class="text-2xl font-black">Fusionner deux chantiers et leurs devis</h1><p class="mt-2 text-slate-200">Le chantier cible conserve les fiches et leur historique. Le chantier source est supprimé après contrôle ; son devis vidé est archivé et masqué.</p></header>
<?php if ($error): ?><div class="mt-5 rounded-xl bg-rose-100 p-4 text-rose-900"><?= $h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="mt-5 rounded-xl bg-emerald-100 p-4 text-emerald-900"><?= $h($success) ?></div><?php endif; ?>
<form method="get" class="mt-6 grid gap-4 rounded-2xl bg-white p-6 shadow-sm md:grid-cols-2">
<?php foreach (['source' => 'Chantier et devis à retirer', 'target' => 'Chantier et devis à conserver'] as $key => $label): $selected = $key === 'source' ? $sourceId : $targetId; ?>
<label class="font-bold"><?= $h($label) ?><select name="<?= $key ?>_id" required class="mt-2 w-full rounded-lg border p-3"><option value="">Sélectionner…</option><?php foreach ($sites as $site): ?><option value="<?= (int) $site['id'] ?>" <?= $selected === (int) $site['id'] ? 'selected' : '' ?>><?= $h($site['code'] . ' · ' . $site['numero_devis'] . ' · ' . $site['titre']) ?></option><?php endforeach; ?></select></label>
<?php endforeach; ?>
<button class="rounded-lg bg-slate-950 px-5 py-3 font-bold text-white md:col-span-2">Vérifier la fusion</button>
</form>
<?php if ($preview): $phrase = 'FUSIONNER ' . $preview['source']['code'] . ' VERS ' . $preview['target']['code']; ?>
<section class="mt-6 rounded-2xl bg-white p-6 shadow-sm"><h2 class="text-xl font-black">Aperçu</h2><div class="mt-4 grid gap-4 md:grid-cols-2"><div class="rounded-xl border border-rose-300 p-4"><strong>À retirer</strong><p><?= $h($preview['source']['code'] . ' · ' . $preview['source']['numero_devis']) ?></p><p><?= (int) $preview['quote_lines'] ?> lignes · <?= (int) $preview['debourses'] ?> déboursés · <?= (int) $preview['linked_fiches'] ?> fiches liées</p></div><div class="rounded-xl border border-emerald-300 p-4"><strong>À conserver</strong><p><?= $h($preview['target']['code'] . ' · ' . $preview['target']['numero_devis']) ?></p></div></div>
<?php if ($preview['counts']): ?><p class="mt-4 text-sm">Éléments du chantier source à déplacer : <?php foreach ($preview['counts'] as $table => $count): ?><?= $h($table) ?> (<?= (int) $count ?>) <?php endforeach; ?></p><?php endif; ?>
<form method="post" class="mt-5 rounded-xl bg-amber-50 p-4"><input type="hidden" name="_csrf" value="<?= $h($_SESSION['chantier_merge_csrf']) ?>"><input type="hidden" name="source_id" value="<?= $sourceId ?>"><input type="hidden" name="target_id" value="<?= $targetId ?>">
<?php if (!$preview['financialTransferDone']): ?><h3 class="font-black">Correspondance des lignes</h3><p class="mb-3 text-sm">Choisissez une ligne existante si c’est le même poste. « Ajouter » augmente le montant du devis conservé.</p>
<?php foreach ($preview['source_lines'] as $line): ?><label class="mb-3 block rounded-lg bg-white p-3"><strong><?= $h($line['designation']) ?> · <?= number_format((float) $line['total'], 0, ',', ' ') ?> FCFA</strong><select name="line_map[<?= (int) $line['id'] ?>]" required class="mt-2 w-full rounded-lg border p-2"><option value="0">Ajouter comme nouvelle ligne</option><?php foreach ($preview['target_lines'] as $targetLine): ?><option value="<?= (int) $targetLine['id'] ?>"><?= $h($targetLine['designation']) ?> · <?= number_format((float) $targetLine['total'], 0, ',', ' ') ?> FCFA</option><?php endforeach; ?></select></label><?php endforeach; ?>
<?php endif; ?>
<label class="block font-bold">Saisissez exactement : <?= $h($phrase) ?><input name="confirmation" required autocomplete="off" class="mt-2 w-full rounded-lg border p-3"></label><button class="mt-4 rounded-lg bg-rose-700 px-5 py-3 font-bold text-white">Fusionner et retirer le doublon</button></form></section>
<?php endif; ?>
</div></main>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
