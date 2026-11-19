<?php
require_once __DIR__ . '/includes/module_bootstrap.php';
AccessManager::requirePageAccess('veille_ao_diffusion.php');
require_once dirname(__DIR__) . '/repositories/AoDigestRepository.php';
require_once dirname(__DIR__) . '/services/ContenuLocalAoAdapter.php';
$pdo = Database::getConnection();
$repository = new AoDigestRepository($pdo);
$canConfigure = AccessManager::hasPermission('ao_manage_diffusion') || AccessManager::hasPermission('ao_admin') || AccessManager::hasPermission('acces_admin');
$isAdmin = AccessManager::hasPermission('ao_admin') || AccessManager::hasPermission('acces_admin');
$userId = (int) ($_SESSION['user_id'] ?? 0);
$_SESSION['ao_diffusion_csrf'] ??= bin2hex(random_bytes(24));
$notice = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['ao_diffusion_csrf'], (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Session expirée.');
        if (($_POST['action'] ?? '') === 'admin_preference' && $isAdmin) {
            $repository->setAdminPreference($userId, !empty($_POST['enabled']));
            $notice = 'Votre préférence de réception du bilan est enregistrée.';
        } elseif (($_POST['action'] ?? '') === 'settings' && $canConfigure) {
            $repository->saveSettings($_POST);
            require_once dirname(__DIR__) . '/model/AuditTrail.php';
            AuditTrail::log('ao_digest_settings_changed', ['module' => 'devis', 'action' => 'ao_digest_settings_changed',
                'entity_type' => 'ao_digest_settings', 'entity_id' => '1', 'user_id' => $userId,
                'details' => ['settings' => $repository->settings()]], $pdo);
            $notice = 'Paramètres du bilan collectif enregistrés.';
        } else throw new RuntimeException('Action non autorisée.');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$settings = $repository->settings();
$recipients = $repository->recipients($settings);
$services = $repository->services();
$history = $repository->history();
$rows = $repository->opportunities((int) $settings['min_score']);
$primaryError = '';
try { $envelope = AoDigestPolicy::envelope($recipients); }
catch (Throwable $e) { $envelope = null; $primaryError = $e->getMessage(); }
$myRow = null;
foreach ($recipients as $recipient) if ($recipient['user_id'] === $userId) $myRow = $recipient;
$activeDevisModule = 'ao';
$moduleTitle = 'Diffusion de la veille appels d’offres';
$moduleSubtitle = 'Destinataires, départements, sources et historique du bilan collectif.';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$labels = ['included' => 'Reçoit le bilan', 'invalid_email' => 'Email absent ou invalide', 'disabled' => 'Désactivé par l’administrateur', 'duplicate' => 'Adresse déjà incluse'];
ob_start();
?>
<style>
.ao-diff-panel{margin:18px 0;padding:22px;background:#fff;border:1px solid #dce4ef;border-radius:12px}.ao-diff-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.ao-diff-panel h2{font-size:18px;font-weight:800;color:#1d2b57;margin:0 0 12px}.ao-diff-panel p{color:#526079;font-size:13px;line-height:1.6}.ao-diff-table{width:100%;border-collapse:collapse;font-size:13px}.ao-diff-table th{background:#1d2b57;color:#fff;text-align:left}.ao-diff-table th,.ao-diff-table td{padding:12px;border-bottom:1px solid #e2e8f0}.ao-diff-badge{display:inline-block;padding:6px 10px;border-radius:6px;background:#fff4d0;color:#654d00;font-weight:700}.ao-diff-field{display:block;margin:12px 0}.ao-diff-field select,.ao-diff-field input[type=number]{width:100%;padding:10px;border:1px solid #ccd6e4;border-radius:7px}.ao-diff-btn{padding:10px 16px;background:#fabd02;color:#1d2b57;border:0;border-radius:8px;font-weight:800}.ao-diff-scroll{overflow:auto}@media(max-width:760px){.ao-diff-grid{grid-template-columns:1fr}}
</style>
<a href="veille_ao.php" class="text-sm font-bold">← Retour à la veille</a>
<?php if ($notice): ?><div class="ao-diff-panel" role="status"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="ao-diff-panel text-red-700" role="alert"><?= $h($error) ?></div><?php endif; ?>
<section class="ao-diff-panel">
<h2>Bilan du lundi et du vendredi</h2>
<p><strong>8 h · heure d’Abidjan · score minimum <?= (int) $settings['min_score'] ?>/100.</strong> Un seul mail, PDF et Excel joints. Le bilan reprend toutes les offres encore ouvertes et pertinentes, y compris celles déjà signalées. Les offres écartées et celles sans date de dépôt sont exclues.</p>
<p>La diffusion départementale est obligatoire pour les équipes commerciale et technique ainsi que la direction. Elle est distincte des alertes personnelles du profil. Les administrateurs hors de ces équipes peuvent désactiver leur réception.</p>
<span class="ao-diff-badge"><?= $settings['enabled'] ? 'Diffusion activée' : 'Diffusion à activer après vérification' ?></span>
<span class="ao-diff-badge"><?= count($rows) ?> offre(s) sélectionnée(s)</span>
<p><a href="export_ao_bilan.php?format=pdf">Télécharger l’aperçu PDF</a> · <a href="export_ao_bilan.php?format=xlsx">Télécharger l’aperçu Excel</a></p>
<span class="ao-diff-badge"><?= $envelope ? 1 + count($envelope['cc']) : 0 ?> adresse(s) destinataire(s)</span>
<?php if ($primaryError): ?><p class="text-red-700"><strong><?= $h($primaryError) ?></strong></p><?php endif; ?>
</section>
<?php if ($canConfigure): ?>
<form method="post" class="ao-diff-panel">
<h2>Départements et responsable principal</h2>
<input type="hidden" name="csrf_token" value="<?= $h($_SESSION['ao_diffusion_csrf']) ?>"><input type="hidden" name="action" value="settings">
<div class="ao-diff-grid">
<?php foreach (['commercial_service_ids' => 'Département commercial', 'technical_service_ids' => 'Département technique'] as $key => $label): ?>
<label class="ao-diff-field"><strong><?= $h($label) ?></strong><select name="<?= $h($key) ?>[]" multiple size="6" required>
<?php foreach ($services as $service): ?><option value="<?= (int) $service['id'] ?>" <?= in_array((int) $service['id'], $settings[$key], true) ? 'selected' : '' ?>><?= $h($service['label']) ?></option><?php endforeach; ?>
</select></label>
<?php endforeach; ?>
<label class="ao-diff-field"><strong>Responsable commercial · destinataire principal</strong><select name="primary_user_id"><option value="0">Responsable défini dans la hiérarchie RH</option>
<?php foreach ($recipients as $recipient): if (!$recipient['commercial'] || !$recipient['user_id']) continue; ?><option value="<?= (int) $recipient['user_id'] ?>" <?= (int) $settings['primary_user_id'] === $recipient['user_id'] ? 'selected' : '' ?>><?= $h($recipient['name'] . ' · ' . $recipient['email']) ?></option><?php endforeach; ?>
</select></label>
<label class="ao-diff-field"><strong>Score minimum commun / 100</strong><input type="number" name="min_score" min="0" max="100" value="<?= (int) $settings['min_score'] ?>" required></label>
</div>
<label class="ao-diff-field"><input type="checkbox" name="enabled" value="1" <?= $settings['enabled'] ? 'checked' : '' ?>> Activer les deux bilans hebdomadaires</label>
<p>Si vous changez les départements, enregistrez d’abord la sélection sans activer, puis vérifiez la liste et choisissez le responsable. Un responsable absent ou un email invalide bloque l’envoi.</p>
<button class="ao-diff-btn">Enregistrer la diffusion</button>
</form>
<?php endif; ?>
<?php if ($isAdmin && $myRow && !$myRow['required']): ?>
<form method="post" class="ao-diff-panel"><h2>Ma réception en tant qu’administrateur</h2>
<input type="hidden" name="csrf_token" value="<?= $h($_SESSION['ao_diffusion_csrf']) ?>"><input type="hidden" name="action" value="admin_preference">
<label><input type="checkbox" name="enabled" value="1" <?= $myRow['status'] !== 'disabled' ? 'checked' : '' ?>> Recevoir le bilan en copie</label>
<button class="ao-diff-btn">Enregistrer ma préférence</button></form>
<?php endif; ?>
<section class="ao-diff-panel"><h2>Liste des destinataires</h2><p>Cette liste est recalculée à partir des collaborateurs actifs et de leur département RH. Une même adresse reçoit le mail une seule fois.</p>
<div class="ao-diff-scroll"><table class="ao-diff-table"><thead><tr><th>Personne</th><th>Département</th><th>Email</th><th>Diffusion</th><th>Motif d’inclusion</th><th>État</th></tr></thead><tbody>
<?php foreach ($recipients as $recipient): ?><tr><td><?= $h($recipient['name']) ?></td><td><?= $h($recipient['service']) ?></td><td><?= $h($recipient['email'] ?: 'Non renseigné') ?></td><td><?= $recipient['role'] === 'to' ? 'À · principal' : 'Copie' ?></td><td><?= $h($recipient['reason']) ?></td><td><?= $h($labels[$recipient['status']] ?? $recipient['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$recipients): ?><tr><td colspan="6">Aucun destinataire. Vérifiez les départements RH et les permissions de direction.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="ao-diff-panel"><h2>Sources surveillées</h2>
<table class="ao-diff-table"><thead><tr><th>Source</th><th>État</th><th>Accès</th></tr></thead><tbody>
<tr><td>DGMP · marchés publics</td><td>Source publique</td><td><a href="https://marchespublics.ci/appel_offre" target="_blank" rel="noopener">Voir la source</a></td></tr>
<tr><td>Contenu local DGH</td><td><?= ContenuLocalAoAdapter::isConfigured() ? 'Compte configuré · vérifier la dernière synchronisation' : 'Compte à configurer sur le serveur' ?></td><td><a href="https://intermediation.contenulocal.dgh.energie.gouv.ci/#/offers" target="_blank" rel="noopener">Publication → Offres</a></td></tr>
</tbody></table><p><a href="veille_ao_sync.php">Consulter l’historique des synchronisations</a></p></section>
<section class="ao-diff-panel"><h2>Historique des bilans</h2><div class="ao-diff-scroll"><table class="ao-diff-table"><thead><tr><th>Date</th><th>Statut</th><th>Offres</th><th>Destinataires à l’envoi</th><th>Journal email</th><th>Message</th></tr></thead><tbody>
<?php foreach ($history as $run): $snapshot = json_decode((string) $run['recipients_json'], true) ?: []; ?><tr><td><?= $h($run['run_date']) ?></td><td><?= $h(['sent'=>'Envoyé','sending'=>'Envoi en cours / à vérifier','uncertain'=>'Résultat à vérifier','failed'=>'Préparation échouée','preparing'=>'Préparation en cours'][$run['status']] ?? $run['status']) ?></td><td><?= (int) $run['opportunity_count'] ?></td><td><?= $h($snapshot['to']['email'] ?? '') ?><br><?= $h(implode(', ', array_column($snapshot['cc'] ?? [], 'email'))) ?></td><td><?= (int) ($run['email_log_id'] ?? 0) ?: '—' ?></td><td><?= $h($run['error_message']) ?></td></tr><?php endforeach; ?>
<?php if (!$history): ?><tr><td colspan="6">Aucun bilan exécuté.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php $content = ob_get_clean(); require __DIR__ . '/includes/module_layout.php';
