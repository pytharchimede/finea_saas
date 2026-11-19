<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhContractController.php';
require_once __DIR__ . '/../services/DailyDisbursementPointService.php';

$pdo = \Database::getConnection();
$controller = new \RhContractController($pdo);
$manager = $controller->service();

$message = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if(!empty($_FILES['employer_logo'])&&(int)$_FILES['employer_logo']['error']!==UPLOAD_ERR_NO_FILE){try{$file=$_FILES['employer_logo'];if((int)$file['error']!==UPLOAD_ERR_OK||(int)$file['size']>5*1024*1024)throw new RuntimeException('Logo invalide ou supérieur à 5 Mo.');$mime=(new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);$ext=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'][$mime]??null;if(!$ext)throw new RuntimeException('Le logo doit être au format PNG, JPEG ou WebP.');$dir=dirname(__DIR__).'/uploads/rh/company';if(!is_dir($dir)&&!mkdir($dir,0775,true))throw new RuntimeException('Dossier du logo indisponible.');$name='logo_employeur_'.date('YmdHis').'.'.$ext;if(!move_uploaded_file((string)$file['tmp_name'],$dir.'/'.$name))throw new RuntimeException('Enregistrement du logo impossible.');$_POST['settings']['employer_logo_path']='uploads/rh/company/'.$name;}catch(Throwable $uploadError){$errorMessage=$uploadError->getMessage();}}
    if(empty($_POST['settings']['employer_logo_path']))$_POST['settings']['employer_logo_path']=$manager->getSettings()['employer_logo_path']??'logo/logo_banamur.jpg';
    if($errorMessage===null){
    $postResult = $controller->handleSettingsPost($_POST, [
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
    ]);
    $message = $postResult['message'];
    $errorMessage = $postResult['errorMessage'];
    if ($errorMessage === null) {
        new \DailyDisbursementPointService($pdo);
    }
    }
}

$settings = $manager->getSettings();

function contractSettingValue($value): string
{
    return trim((string) $value);
}

$pageTitle = 'Parametrage contrat RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(224,231,255,0.92),_transparent_35%),radial-gradient(circle_at_top_right,_rgba(254,249,195,0.88),_transparent_32%),linear-gradient(180deg,_#f8fafc_0%,_#eef6ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-sky-100 bg-gradient-to-r from-slate-950 via-slate-900 to-sky-900 px-8 py-8 text-white shadow-[0_24px_80px_rgba(15,23,42,0.28)]">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.35em] text-sky-200">Parametrage central RH</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight md:text-5xl">Signataires et identite des contrats</h1>
                    <p class="mt-4 text-sm text-sky-50 md:text-base">
                        Cet ecran centralise la presentation de l employeur, les signataires DG et RH, ainsi que les informations reutilisees dans les contrats PDF avec numerotation et tracabilite RH.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="dashboard_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-sky-50">
                        <i class="fas fa-chart-line mr-2 text-sky-600"></i>
                        Tableau de bord RH
                    </a>
                    <a href="parametrage_paie_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-sliders-h mr-2"></i>
                        Regles de paie
                    </a>
                </div>
            </div>
        </div>

        <?php if ($message !== null): ?>
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-700">
                <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 rounded-[28px] border border-white/80 bg-white/92 px-6 py-5 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-sky-600">Documentaire</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Ce que pilote cet ecran</h2>
                </div>
                <div class="rounded-2xl border border-sky-100 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                    Le contrat PDF affiche un filigrane Brouillon ou Original signe, un numero unique CTRH annuel et les signataires DG / RH pre-remplis.
                </div>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="mt-8 space-y-6">
            <section class="rounded-[30px] border border-white/80 bg-white/92 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Employeur</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Identite legale sur le contrat</h2>
                </div>
                <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom de l employeur</label>
                        <input type="text" name="settings[employer_name]" value="<?= htmlspecialchars(contractSettingValue($settings['employer_name'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div><label class="mb-2 block text-sm font-medium text-slate-700">Logo de l’entreprise</label><input type="file" name="employer_logo" accept="image/png,image/jpeg,image/webp" class="w-full rounded-2xl border border-dashed border-amber-400 bg-amber-50 p-3"><input type="hidden" name="settings[employer_logo_path]" value="<?=htmlspecialchars(contractSettingValue($settings['employer_logo_path']??''))?>"><?php if(!empty($settings['employer_logo_path'])):?><img src="../<?=htmlspecialchars((string)$settings['employer_logo_path'])?>" class="mt-3 h-16 max-w-48 rounded-xl border bg-white object-contain p-2"><?php endif;?></div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Forme juridique</label>
                        <input type="text" name="settings[employer_legal_form]" value="<?= htmlspecialchars(contractSettingValue($settings['employer_legal_form'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Mention capital</label>
                        <input type="text" name="settings[capital_label]" value="<?= htmlspecialchars(contractSettingValue($settings['capital_label'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-slate-700">Siege social</label>
                        <input type="text" name="settings[headquarters_text]" value="<?= htmlspecialchars(contractSettingValue($settings['headquarters_text'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">RCCM</label>
                        <input type="text" name="settings[rccm_text]" value="<?= htmlspecialchars(contractSettingValue($settings['rccm_text'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div class="md:col-span-3">
                        <label class="mb-2 block text-sm font-medium text-slate-700">Texte de representation</label>
                        <textarea name="settings[represented_by_text]" rows="3" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white"><?= htmlspecialchars(contractSettingValue($settings['represented_by_text'])) ?></textarea>
                    </div>
                </div>
            </section>

            <section class="rounded-[30px] border border-white/80 bg-white/92 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Signataires</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Direction generale et RH</h2>
                </div>
                <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Ville de signature</label>
                        <input type="text" name="settings[signature_city]" value="<?= htmlspecialchars(contractSettingValue($settings['signature_city'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom signataire DG</label>
                        <input type="text" name="settings[dg_signatory_name]" value="<?= htmlspecialchars(contractSettingValue($settings['dg_signatory_name'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Qualite DG</label>
                        <input type="text" name="settings[dg_signatory_title]" value="<?= htmlspecialchars(contractSettingValue($settings['dg_signatory_title'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom signataire RH</label>
                        <input type="text" name="settings[rh_signatory_name]" value="<?= htmlspecialchars(contractSettingValue($settings['rh_signatory_name'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Qualite RH</label>
                        <input type="text" name="settings[rh_signatory_title]" value="<?= htmlspecialchars(contractSettingValue($settings['rh_signatory_title'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white">
                    </div>
                    <div class="md:col-span-2 xl:col-span-3 rounded-2xl border border-sky-100 bg-sky-50 px-4 py-4 text-sm text-sky-800">
                        Le PDF utilisera automatiquement ces noms et qualites dans le bloc final des signatures. Le mode Brouillon affichera un filigrane BROUILLON, et le mode Original signe affichera ORIGINAL SIGNE avec un numero CTRH unique.
                    </div>
                </div>
            </section>

            <section class="rounded-[30px] border border-white/80 bg-white/92 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Pied de page</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Mentions imprimees</h2>
                </div>
                <div class="mt-6 grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Ligne 1</label>
                        <textarea name="settings[footer_line_1]" rows="3" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white"><?= htmlspecialchars(contractSettingValue($settings['footer_line_1'])) ?></textarea>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Ligne 2</label>
                        <textarea name="settings[footer_line_2]" rows="3" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-sky-400 focus:bg-white"><?= htmlspecialchars(contractSettingValue($settings['footer_line_2'])) ?></textarea>
                    </div>
                </div>
            </section>

            <div class="sticky bottom-4 z-10 flex flex-col gap-3 rounded-[28px] border border-slate-200 bg-white/95 px-6 py-5 shadow-[0_18px_50px_rgba(15,23,42,0.10)] backdrop-blur md:flex-row md:items-center md:justify-between">
                <p class="text-sm text-slate-500">
                    Les prochains contrats RH reutiliseront immediatement ces informations dans le PDF et le registre de tracabilite.
                </p>
                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-6 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-slate-900">
                    <i class="fas fa-save mr-2"></i>
                    Enregistrer le parametrage contrats
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
