<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/model/Database.php';

$pdo = Database::getConnection();
$userId = (int)($_SESSION['user_id'] ?? 0);
$stmt = $pdo->prepare('SELECT prenom,nom,signature,signature_data FROM user_devis_banamur WHERE id=:id LIMIT 1');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$hasSignature = trim((string)($user['signature'] ?? '')) !== '' || preg_match('#^data:image/png;base64,#', trim((string)($user['signature_data'] ?? ''))) === 1;
if ($hasSignature) {
    $_SESSION['signature_requirement_checked'] = 'mandatory_profile_signature_v1';
    $returnTo = (string)($_SESSION['signature_setup_return'] ?? (AccessManager::getApplicationBasePath() . '/selection_portail.php'));
    unset($_SESSION['signature_setup_return']);
    header('Location: ' . $returnTo);
    exit;
}

$pageTitle = 'Configurer ma signature professionnelle';
$layoutRootBase = '';
$layoutModuleBase = 'decaissement/';
require_once __DIR__ . '/decaissement/includes/header.php';
$displayName = trim((string)($user['prenom'] ?? '') . ' ' . (string)($user['nom'] ?? '')) ?: 'Utilisateur Finéa';
$status = (string)($_GET['status'] ?? '');
$message = (string)($_GET['message'] ?? '');
?>
<style>
body{background:radial-gradient(circle at top left,#eef2ff 0,#f8fafc 42%,#ecfeff 100%);min-height:100vh}.mandatory-signature-canvas{width:100%;height:300px;touch-action:none;cursor:crosshair;border-radius:24px;background:#fff}
</style>
<main class="mx-auto flex min-h-screen max-w-6xl items-center px-4 py-10 sm:px-6">
    <section class="grid w-full overflow-hidden rounded-[36px] border border-white/80 bg-white shadow-2xl lg:grid-cols-[390px_1fr]">
        <aside class="relative overflow-hidden bg-slate-950 p-8 text-white lg:p-10">
            <div class="absolute -right-24 -top-24 h-72 w-72 rounded-full bg-indigo-500/20 blur-3xl"></div>
            <img src="branding_asset.php?type=logo" alt="Logo entreprise" class="relative h-16 max-w-[230px] object-contain object-left brightness-0 invert">
            <div class="relative mt-16 inline-flex h-16 w-16 items-center justify-center rounded-2xl bg-indigo-500 text-2xl shadow-xl"><i class="fa-solid fa-signature"></i></div>
            <p class="relative mt-8 text-xs font-black uppercase tracking-[.22em] text-indigo-300">Identité documentaire</p>
            <h1 class="relative mt-3 text-3xl font-black leading-tight">Votre signature professionnelle</h1>
            <p class="relative mt-5 leading-7 text-slate-300">Elle sera utilisée sur les BCI, validations, rapports et autres documents administratifs produits par Finéa.</p>
            <div class="relative mt-8 rounded-2xl border border-white/10 bg-white/5 p-4 text-sm text-slate-300"><i class="fa-solid fa-shield-halved mr-2 text-emerald-400"></i>La configuration est demandée une seule fois. Vous pourrez ensuite remplacer votre signature depuis votre profil.</div>
        </aside>
        <div class="p-6 sm:p-9 lg:p-12">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div><p class="text-sm font-black text-indigo-600"><?=htmlspecialchars($displayName,ENT_QUOTES,'UTF-8')?></p><h2 class="mt-1 text-3xl font-black text-slate-950">Signez dans le cadre</h2><p class="mt-2 text-slate-500">Utilisez la souris, le doigt ou un stylet. Le tracé est optimisé pour rester fluide sur mobile et ordinateur.</p></div>
                <span class="rounded-full bg-amber-100 px-4 py-2 text-xs font-black uppercase tracking-wide text-amber-800">Configuration requise</span>
            </div>
            <?php if($status==='error'):?><div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-800"><?=htmlspecialchars($message,ENT_QUOTES,'UTF-8')?></div><?php endif;?>
            <form method="post" action="decaissement/request/save_profile_signature.php" class="js-signature-pad mt-7">
                <input type="hidden" name="mandatory_setup" value="1">
                <input type="hidden" name="signature_base64" class="js-signature-value">
                <div class="relative rounded-[30px] border-2 border-dashed border-indigo-200 bg-indigo-50/50 p-3 shadow-inner focus-within:border-indigo-500">
                    <canvas class="js-signature-canvas mandatory-signature-canvas"></canvas>
                    <div class="js-signature-hint pointer-events-none absolute inset-0 flex items-center justify-center text-lg font-bold text-slate-300"><i class="fa-solid fa-pen-nib mr-3"></i>Commencez votre signature ici</div>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-[1fr_auto] sm:items-center">
                    <div><div class="js-signature-status font-bold text-slate-600">Aucune signature capturée</div><p class="mt-1 text-xs text-slate-400">Votre signature sera enregistrée au format PNG sécurisé, compatible avec les documents PDF.</p></div>
                    <div class="flex gap-2"><button type="button" class="js-signature-clear rounded-2xl border border-slate-200 bg-white px-5 py-3 font-bold text-slate-700 hover:bg-slate-50"><i class="fa-solid fa-eraser mr-2"></i>Effacer</button><button class="rounded-2xl bg-indigo-600 px-6 py-3 font-black text-white shadow-lg shadow-indigo-200 hover:bg-indigo-700"><i class="fa-solid fa-check mr-2"></i>Enregistrer et continuer</button></div>
                </div>
                <div class="js-signature-preview hidden" data-empty-label=""></div>
            </form>
        </div>
    </section>
</main>
<script src="js/rh_explanation_signature_pad.js"></script>
</body></html>
