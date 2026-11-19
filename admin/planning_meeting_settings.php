<?php
declare(strict_types=1);

require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess('admin/dashboard_admin.php');
require_once __DIR__.'/../model/Database.php';

$pdo=Database::getConnection();
$column=$pdo->query("SHOW COLUMNS FROM chantier_banamur LIKE 'planning_is_office_hub'")->fetch();
if(!$column)$pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN planning_is_office_hub TINYINT(1) NOT NULL DEFAULT 0");

$_SESSION['planning_meeting_settings_csrf']=$_SESSION['planning_meeting_settings_csrf']??bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['planning_meeting_settings_csrf'];
$message='';
$messageType='success';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf_token']??'')))throw new RuntimeException('Session expirée. Rechargez la page.');
        $chantierId=(int)($_POST['office_chantier_id']??0);
        $check=$pdo->prepare("SELECT COUNT(*) FROM chantier_banamur WHERE id=:id AND archived_at IS NULL AND closed_date IS NULL");
        $check->execute(['id'=>$chantierId]);
        if(!$check->fetchColumn())throw new DomainException('Choisissez un chantier actif valide.');

        $currentId=(int)$pdo->query("SELECT id FROM chantier_banamur WHERE planning_is_office_hub=1 LIMIT 1")->fetchColumn();
        if($currentId>0&&$currentId!==$chantierId){
            $open=$pdo->query("SELECT COUNT(*) FROM planning_chantier_meeting_preparations
                WHERE office_chantier_id IS NOT NULL AND status IN ('draft','ready') AND archived_at IS NULL")->fetchColumn();
            if((int)$open>0)throw new DomainException('Archivez ou clôturez d’abord les réunions Bureau en cours avant de changer ce chantier.');
        }

        $pdo->beginTransaction();
        $pdo->exec("UPDATE chantier_banamur SET planning_is_office_hub=0 WHERE planning_is_office_hub<>0");
        $save=$pdo->prepare("UPDATE chantier_banamur SET planning_is_office_hub=1 WHERE id=:id");
        $save->execute(['id'=>$chantierId]);
        $pdo->commit();
        $message='Le chantier Bureau a été mis à jour. Les prochaines préparations proposeront automatiquement ses services.';
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        $message=$e->getMessage();
        $messageType='error';
    }
}

$chantiers=$pdo->query("SELECT id,code,titre,planning_is_office_hub
    FROM chantier_banamur
    WHERE archived_at IS NULL AND closed_date IS NULL
    ORDER BY planning_is_office_hub DESC,code,titre")->fetchAll(PDO::FETCH_ASSOC)?:[];
$configured=null;
foreach($chantiers as $chantier)if((int)$chantier['planning_is_office_hub']===1){$configured=$chantier;break;}

$pageTitle='Paramètres des réunions';
$activeMenu='admin';
$page='admin';
require_once __DIR__.'/../decaissement/includes/header.php';
require_once __DIR__.'/../decaissement/includes/navbar.php';
$h=static fn($value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<main class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
            <p class="text-sm font-black uppercase tracking-widest text-indigo-600">Administration système</p>
            <h1 class="mt-2 text-3xl font-black text-slate-950">Paramètres des réunions</h1>
            <p class="mt-2 text-slate-600">Définissez le chantier technique qui porte les services des réunions Bureau.</p>
        </div>
        <a href="dashboard_admin.php" class="rounded-xl border border-slate-300 bg-white px-4 py-3 font-bold text-slate-700"><i class="fas fa-arrow-left mr-2"></i>Administration</a>
    </div>

    <?php if($message):?>
        <div class="mb-6 rounded-2xl border p-4 font-bold <?=$messageType==='success'?'border-emerald-200 bg-emerald-50 text-emerald-800':'border-rose-200 bg-rose-50 text-rose-800'?>"><?=$h($message)?></div>
    <?php endif;?>

    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl">
        <div class="border-b border-slate-200 bg-slate-950 p-6 text-white">
            <p class="text-xs font-black uppercase tracking-widest text-amber-300">Configuration globale</p>
            <h2 class="mt-2 text-2xl font-black">Chantier Bureau</h2>
            <p class="mt-2 text-slate-300">Une réunion Bureau utilisera les lignes de devis de ce chantier comme liste des services.</p>
        </div>
        <form method="post" class="p-6">
            <input type="hidden" name="csrf_token" value="<?=$h($csrf)?>">
            <?php if($configured):?>
                <div class="mb-6 rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                    <small class="font-black uppercase tracking-wider text-indigo-600">Actuellement configuré</small>
                    <strong class="mt-1 block text-lg text-slate-950"><?=$h($configured['code'].' - '.$configured['titre'])?></strong>
                </div>
            <?php endif;?>
            <label for="office_chantier_id" class="mb-2 block font-black text-slate-800">Chantier à utiliser pour le Bureau</label>
            <select id="office_chantier_id" name="office_chantier_id" required class="w-full rounded-xl border border-slate-300 bg-white px-4 py-3">
                <option value="">Choisir un chantier actif…</option>
                <?php foreach($chantiers as $chantier):?>
                    <option value="<?=(int)$chantier['id']?>" <?=((int)$chantier['planning_is_office_hub']===1)?'selected':''?>><?=$h($chantier['code'].' - '.$chantier['titre'])?></option>
                <?php endforeach;?>
            </select>
            <p class="mt-3 text-sm text-slate-500">Le réglage est conservé lors des mises en ligne. Il n’est jamais modifié depuis la préparation d’une réunion.</p>
            <div class="mt-6 flex justify-end">
                <button class="rounded-xl bg-indigo-600 px-5 py-3 font-black text-white shadow-lg hover:bg-indigo-700"><i class="fas fa-save mr-2"></i>Enregistrer la configuration</button>
            </div>
        </form>
    </section>
</main>
<?php require_once __DIR__.'/../decaissement/includes/footer.php';?>
