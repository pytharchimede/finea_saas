<?php
require __DIR__ . '/bootstrap.php';

$rows = $contractManager->all();

$drhCheck = $pdo->prepare(
	"SELECT UPPER(COALESCE(f.lib_fonction, ''))
	 FROM user_devis_banamur u
	 LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
	 LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
	 WHERE u.id = ?"
);
$drhCheck->execute([(int) ($_SESSION['user_id'] ?? 0)]);

$contractFunction = (string) $drhCheck->fetchColumn();
$contractIsDrh = str_contains($contractFunction, 'DIRECTEUR RESSOURCES HUMAINES')
	|| str_contains($contractFunction, 'DIRECTEUR DES RESSOURCES HUMAINES');

$pageTitle = 'Contrats de sous-traitance';
$activeMenu = 'contrats';

require __DIR__ . '/../decaissement/includes/header.php';
require __DIR__ . '/../decaissement/includes/navbar.php';
?>

<link rel="stylesheet" href="contract-theme.css?v=<?= filemtime(__DIR__ . '/contract-theme.css') ?>">
<main class="contract-brand mx-auto max-w-7xl px-4 py-8">
	<header class="contract-hero p-6">
		<p class="text-xs font-black uppercase tracking-[.25em] contract-eyebrow">
			Finéa · Commande &amp; sous-traitance
		</p>
		<div class="mt-2 flex flex-wrap items-center justify-between gap-4">
			<div>
				<h1 class="text-3xl font-black">Contrats de sous-traitance</h1>
				<p class="mt-2 text-slate-300">
					Postes verrouillés, avancement, récupération d’acompte et dossier de paiement.
				</p>
			</div>
			<a href="creer.php" class="rounded-xl contract-primary px-5 py-3 font-bold">
				<i class="fas fa-plus mr-2"></i>Nouveau contrat
			</a>
		</div>
	</header>

	<?php if ($contractIsDrh): ?>
		<div class="mt-5 rounded-xl border contract-soft p-4 text-sm font-bold contract-reference">
			<i class="fas fa-user-shield mr-2"></i>
			Mode DRH : vous pouvez affecter n’importe quel utilisateur actif — y compris vous-même — aux différents niveaux de validation.
		</div>
	<?php endif; ?>

	<section class="mt-6 overflow-hidden rounded-xl border bg-white shadow-sm">
		<div class="overflow-x-auto">
			<table class="w-full min-w-[950px] text-left text-sm">
				<thead class="contract-table-head">
					<tr>
						<th class="p-4">Référence</th>
						<th class="p-4">Chantier</th>
						<th class="p-4">Prestataire</th>
						<th class="p-4">Montant</th>
						<th class="p-4">Acompte</th>
						<th class="p-4">Payé</th>
						<th class="p-4">Statut</th>
						<th class="p-4"></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($rows as $row): ?>
						<tr class="border-b">
							<td class="p-4 font-black contract-reference">
								<?= $h($row['reference']) ?>
								<small class="block text-slate-400"><?= $h($row['document_id']) ?></small>
							</td>
							<td class="p-4">
								<b><?= $h($row['chantier_code']) ?></b>
								<small class="block text-slate-500"><?= $h($row['chantier_titre']) ?></small>
							</td>
							<td class="p-4 font-bold"><?= $h($row['fournisseur_nom']) ?></td>
							<td class="p-4 font-black">
								<?= number_format((float) $row['montant_ht'], 0, ',', ' ') ?> FCFA
							</td>
							<td class="p-4">
								<?= number_format((float) $row['taux_acompte'], 1, ',', ' ') ?> %
							</td>
							<td class="p-4">
								<?= number_format((float) $row['total_paye'], 0, ',', ' ') ?> FCFA
							</td>
							<td class="p-4">
								<span class="rounded-full contract-badge px-3 py-1 font-bold contract-reference">
									<?= $h($row['statut']) ?>
								</span>
							</td>
							<td class="p-4">
								<div class="flex flex-wrap gap-2">
									<a class="rounded-lg contract-primary px-3 py-2 font-bold" href="voir.php?id=<?= (int) $row['id'] ?>">
										Ouvrir
									</a>
									<?php if ($contractIsDrh): ?>
										<a class="rounded-lg contract-primary px-3 py-2 font-bold" href="workflow.php?id=<?= (int) $row['id'] ?>&amp;etape=rh">
											Validateurs
										</a>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>

					<?php if (!$rows): ?>
						<tr>
							<td colspan="8" class="p-12 text-center text-slate-500">
								Aucun contrat enregistré.
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</section>
</main>

<?php require __DIR__ . '/../decaissement/includes/footer.php'; ?>
