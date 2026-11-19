<?php

declare(strict_types=1);

require_once __DIR__ . '/../devis/includes/module_bootstrap.php';
require_once __DIR__ . '/../services/ClientIdentityService.php';
require_once __DIR__ . '/../model/AuditTrail.php';

if (!devis_module_has_access('acces_liste_client')) AccessManager::denyAccess('acces_liste_client', 'clients/modifier.php');
$pdo = Database::getConnection();
$id = (int)($_GET['id'] ?? $_POST['id_client'] ?? 0);
$service = new ClientIdentityService($pdo);
$client = $service->find($id);
$error = '';
$uploadedLogo = null;
$_SESSION['client_edit_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['client_edit_csrf'], (string)($_POST['_csrf'] ?? ''))) throw new RuntimeException('Session expirée. Rechargez la fiche.');
        $file = $_FILES['logo_client'] ?? null;
        if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new DomainException('Le logo n’a pas pu être téléversé.');
            if ((int)($file['size'] ?? 0) > 3 * 1024 * 1024) throw new DomainException('Le logo ne doit pas dépasser 3 Mo.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) throw new DomainException('Le logo doit être au format JPG, PNG ou WebP.');
            $directory = __DIR__ . '/../uploads/logos_clients';
            $uploadsDirectory = dirname($directory);
            if (!is_dir($uploadsDirectory) && !mkdir($uploadsDirectory, 0777, true) && !is_dir($uploadsDirectory)) throw new RuntimeException('Impossible de créer le dossier uploads.');
            @chmod($uploadsDirectory, 0777);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) throw new RuntimeException('Impossible de créer le dossier des logos.');
            @chmod($directory, 0777);
            clearstatcache(true, $directory);
            if (!is_writable($directory)) throw new RuntimeException('Le dossier des logos existe mais PHP ne peut pas y écrire. Vérifiez les droits du serveur web.');
            $uploadedLogo = 'logo_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
            if (!move_uploaded_file((string)$file['tmp_name'], $directory . '/' . $uploadedLogo)) throw new RuntimeException('Impossible d’enregistrer le logo dans ' . $directory . '.');
            @chmod($directory . '/' . $uploadedLogo, 0666);
        }
        $saved = $service->save($_POST, $id, (int)($_SESSION['user_id'] ?? 0), AccessManager::hasPermission('acces_admin') || AccessManager::hasPermission('acces_compta'));
        if ($uploadedLogo !== null) {
            $pdo->prepare('UPDATE client_banamur SET logo_client=:logo WHERE id_client=:id')->execute(['logo' => $uploadedLogo, 'id' => $saved]);
            if (!empty($client['logo_client'])) @unlink(__DIR__ . '/../uploads/logos_clients/' . basename((string)$client['logo_client']));
        }
        AuditTrail::log('client_updated', ['module' => 'clients', 'action' => 'update', 'entity_type' => 'client', 'entity_id' => (string)$saved, 'entity_label' => (string)($_POST['nom_client'] ?? '')], $pdo);
        header('Location: '.devis_module_root_url('clients/dashboard.php?updated=1'));
        exit;
    } catch (Throwable $e) {
        if ($uploadedLogo !== null) @unlink(__DIR__ . '/../uploads/logos_clients/' . $uploadedLogo);
        $error = $e->getMessage();
        $client = array_merge($client, $_POST);
    }
}

$modules = $devisModuleController->modules();
$devisModuleNav = $modules;
$activeDevisModule = 'clients';
$moduleTitle = 'Mettre à jour la fiche client';
$moduleSubtitle = 'Contrôler l’identité selon le pays, puis enregistrer les coordonnées et le logo.';
$root = static fn(string $path): string => devis_module_root_url($path);
$logoUrl = !empty($client['logo_client']) ? $root('uploads/logos_clients/' . rawurlencode(basename((string)$client['logo_client']))) : '';
$formatStoredDate = static fn($value): string => $value ? date('d/m/Y', strtotime((string)$value)) : 'Non renseignée';
ob_start(); ?>
<link rel="stylesheet" href="<?= htmlspecialchars($root('assets/css/client-platform.css'), ENT_QUOTES, 'UTF-8') ?>">
<style>
    .cm-shell {
        max-width: 1060px
    }

    .cm-card {
        border: 1px solid #dce5ef;
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 18px 45px rgba(23, 35, 72, .08);
        overflow: hidden
    }

    .cm-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        padding: 25px 27px;
        background: linear-gradient(135deg, #102047, #183b5f);
        color: #fff
    }

    .cm-top h2 {
        margin: 0;
        font-size: 20px;
        font-weight: 900
    }

    .cm-top p {
        margin: 6px 0 0;
        color: #c8d7e8;
        font-size: 12px
    }

    .cm-top-icon {
        display: grid;
        width: 52px;
        height: 52px;
        place-items: center;
        border-radius: 15px;
        background: #fabd02;
        color: #172348;
        font-size: 20px
    }

    .cm-body {
        padding: 27px
    }

    .cm-section {
        margin-bottom: 26px
    }

    .cm-section:last-child {
        margin-bottom: 0
    }

    .cm-section-title {
        display: flex;
        align-items: center;
        gap: 9px;
        margin-bottom: 13px;
        color: #172348;
        font-size: 13px;
        font-weight: 900
    }

    .cm-section-title i {
        color: #0f766e
    }

    .cm-identity {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 10px;
        align-items: end
    }

    .cm-field label {
        display: block;
        margin-bottom: 6px;
        color: #34425a;
        font-size: 11px;
        font-weight: 900
    }

    .cm-field input {
        width: 100%;
        border: 1px solid #ccd7e5;
        border-radius: 10px;
        padding: 12px 13px;
        color: #26334d;
        outline: none;
        transition: border-color .18s, box-shadow .18s
    }

    .cm-field input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 4px #dbeafe
    }

    .cm-field input[readonly] {
        background: #f6f8fb;
        color: #59677b
    }

    .cm-ncc {
        font-family: monospace;
        font-size: 15px;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase
    }

    .cm-verify {
        border: 0;
        border-radius: 10px;
        background: #0f766e;
        color: #fff;
        padding: 12px 16px;
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
        cursor: pointer
    }

    .cm-verify:hover {
        background: #115e59
    }

    .cm-state {
        display: none;
        margin-top: 10px;
        border-radius: 10px;
        padding: 10px 12px;
        font-size: 12px;
        font-weight: 800
    }

    .cm-state.show {
        display: block
    }

    .cm-state.ok {
        background: #dcfce7;
        color: #166534
    }

    .cm-state.error {
        background: #fff1f2;
        color: #9f1239
    }

    .cm-fne-grid,
    .cm-contact-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-top: 15px
    }

    .cm-contact-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr))
    }

    .cm-field-wide {
        grid-column: 1/-1
    }

    .cm-upload {
        display: grid;
        grid-template-columns: 150px minmax(0, 1fr);
        gap: 17px;
        align-items: center
    }

    .cm-preview {
        display: grid;
        place-items: center;
        width: 150px;
        height: 125px;
        border: 1px solid #dce5ef;
        border-radius: 13px;
        background: #f7f9fc;
        overflow: hidden
    }

    .cm-preview img {
        width: 100%;
        height: 100%;
        object-fit: contain
    }

    .cm-preview-empty {
        color: #94a3b8;
        font-size: 11px;
        text-align: center;
        padding: 10px
    }

    .cm-drop {
        display: grid;
        place-items: center;
        min-height: 125px;
        border: 1.5px dashed #9fb2c8;
        border-radius: 13px;
        background: #fbfdff;
        color: #64748b;
        text-align: center;
        cursor: pointer;
        transition: .18s
    }

    .cm-drop:hover,
    .cm-drop.is-over {
        border-color: #0f766e;
        background: #ecfdf5;
        color: #115e59
    }

    .cm-drop strong {
        display: block;
        color: #26334d;
        font-size: 13px
    }

    .cm-drop small {
        display: block;
        margin-top: 5px;
        font-size: 11px
    }

    .cm-drop input {
        display: none
    }

    .cm-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-top: 27px;
        padding-top: 20px;
        border-top: 1px solid #e7edf4
    }

    .cm-actions a,
    .cm-save {
        border-radius: 10px;
        padding: 12px 17px;
        font-size: 12px;
        font-weight: 900;
        text-decoration: none
    }

    .cm-actions a {
        border: 1px solid #d7e0ec;
        color: #475569
    }

    .cm-save {
        border: 0;
        background: #fabd02;
        color: #172348;
        cursor: pointer
    }

    .cm-save:disabled {
        opacity: .45;
        cursor: not-allowed
    }

    .cm-error {
        margin-bottom: 16px;
        border: 1px solid #fecaca;
        border-radius: 10px;
        background: #fff1f2;
        padding: 12px;
        color: #9f1239;
        font-size: 12px
    }

    @media(max-width:760px) {
        .cm-top {
            align-items: flex-start;
            flex-direction: column
        }

        .cm-identity,
        .cm-fne-grid,
        .cm-contact-grid,
        .cm-upload {
            grid-template-columns: 1fr
        }

        .cm-preview {
            width: 100%;
            height: 150px
        }

        .cm-verify {
            width: 100%
        }

        .cm-actions {
            align-items: stretch;
            flex-direction: column-reverse
        }

        .cm-actions>* {
            text-align: center;
            width: 100%
        }
    }
</style>
<div class="cm-shell">
    <?php if ($error): ?><div class="cm-error"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <section class="cm-card" data-platform="fne">
        <header class="cm-top">
            <div>
                <h2>Fiche client #<?= $id ?></h2>
                <p id="platformDescription">Le NCC et les données fiscales sont contrôlés par le registre officiel FNE.</p>
            </div><span class="cm-top-icon"><i class="fa-solid fa-building-shield"></i></span>
        </header>
        <div class="cm-body">
            <form method="post" enctype="multipart/form-data" id="clientEditForm" autocomplete="off"><input type="hidden" name="id_client" value="<?= $id ?>"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['client_edit_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <?php $businessClientForm=true; $supplierFiscalIdentity=$client; require __DIR__.'/../partials/business_identity_entry.php';require __DIR__.'/../partials/supplier_french_identity.php'; ?>
<input type="hidden" name="tax_platform" value="fne">
                <section class="cm-section">
                    <div class="cm-section-title"><i class="fa-solid fa-fingerprint"></i> Identité fiscale</div>
                    <div class="cm-identity">
                        <div class="cm-field" data-ci-company-only <?= ($supplierFiscalIdentity['fiscal_country']??'')==='CI'&&($supplierFiscalIdentity['client_type']??'morale')==='morale'?'':'hidden' ?>><label for="ncc">NCC officiel *</label><input class="cm-ncc" id="ncc" name="ncc" required maxlength="20" value="<?= htmlspecialchars((string)($client['ncc'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex. 2158807C"></div><button class="cm-verify" id="verifyNcc" type="button"><i class="fa-solid fa-rotate client-load-reload" aria-hidden="true"></i><span class="client-load-label">Charger depuis la FNE</span><img class="client-load-logo" src="<?= htmlspecialchars($root('assets/fne_logo.jpeg'), ENT_QUOTES, 'UTF-8') ?>" alt=""></button>
                    </div>
                    <div id="fneState" class="cm-state"></div>
                    <div class="cm-fne-grid">
                        <div class="cm-field"><label>Raison sociale</label><input id="nom_client" name="nom_client" readonly required value="<?= htmlspecialchars((string)($client['nom_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label>Régime fiscal</label><input id="tax_regime" readonly value="<?= htmlspecialchars((string)($client['tax_regime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label>Statut d’activité</label><input id="activity_status" readonly value="<?= htmlspecialchars((string)($client['fiscal_activity_label'] ?? $client['fiscal_activity_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label>Enregistré le</label><input id="registered_at" readonly value="<?= htmlspecialchars($formatStoredDate($client['dgi_registered_at'] ?? null), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label>Première immatriculation</label><input id="first_registration_at" readonly value="<?= htmlspecialchars($formatStoredDate($client['dgi_first_registration_at'] ?? null), ENT_QUOTES, 'UTF-8') ?>"></div>
                    </div>
                    <div class="cm-fne-grid">
                        <div class="cm-field"><label for="activity_start_date">Date début d’activité (saisie interne)</label><input id="activity_start_date" type="date" name="activity_start_date" value="<?= htmlspecialchars((string)($client['activity_start_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label for="activity_description">Activité (saisie interne)</label><input id="activity_description" name="activity_description" maxlength="255" value="<?= htmlspecialchars((string)($client['activity_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label for="vat_liability_status">Statut d’assujettissement TVA (saisie interne)</label><input id="vat_liability_status" name="vat_liability_status" maxlength="100" value="<?= htmlspecialchars((string)($client['vat_liability_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label for="vat_last_period">Période dernière déclaration TVA (saisie interne)</label><input id="vat_last_period" name="vat_last_period" maxlength="150" value="<?= htmlspecialchars((string)($client['vat_last_period'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                    </div>
                </section>
                <section class="cm-section">
                    <div class="cm-section-title"><i class="fa-solid fa-address-card"></i> Coordonnées internes</div>
                    <div class="cm-contact-grid">
                        <div class="cm-field"><label for="telephone_client">Téléphone</label><input id="telephone_client" name="telephone_client" value="<?= htmlspecialchars((string)($client['telephone_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label for="email_client">Email</label><input id="email_client" type="email" name="email_client" value="<?= htmlspecialchars((string)($client['email_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label for="commune_client">Commune</label><input id="commune_client" name="commune_client" value="<?= htmlspecialchars((string)($client['commune_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label for="pays_client">Pays</label><input id="pays_client" name="pays_client" value="<?= htmlspecialchars((string)($client['pays_client'] ?? "Côte d’Ivoire"), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field cm-field-wide"><label for="localisation_client">Adresse / localisation</label><input id="localisation_client" name="localisation_client" value="<?= htmlspecialchars((string)($client['localisation_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                        <div class="cm-field"><label for="bp_client">Boîte postale</label><input id="bp_client" name="bp_client" value="<?= htmlspecialchars((string)($client['bp_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                    </div>
                </section>
                <section class="cm-section">
                    <div class="cm-section-title"><i class="fa-solid fa-image"></i> Logo de la fiche</div>
                    <div class="cm-upload">
                        <div class="cm-preview" id="logoPreview"><?php if ($logoUrl): ?><img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo actuel"><?php else: ?><span class="cm-preview-empty">Aucun logo</span><?php endif; ?></div><label class="cm-drop" id="logoDrop" for="logo_client"><span><i class="fa-solid fa-cloud-arrow-up"></i><strong>Déposez le logo ici</strong><small>ou cliquez pour choisir un JPG, PNG ou WebP (3 Mo maximum)</small></span><input id="logo_client" name="logo_client" type="file" accept="image/jpeg,image/png,image/webp"></label>
                    </div>
                </section>
                <div class="cm-actions"><a href="<?= $root('clients/dashboard.php') ?>"><i class="fa-solid fa-arrow-left"></i> Retour au référentiel</a><button class="cm-save" id="saveClient" type="submit" disabled><i class="fa-solid fa-floppy-disk"></i> Enregistrer la fiche client</button></div>
            </form>
        </div>
    </section>
</div>
<script src="<?= htmlspecialchars($root('assets/js/client-business-identity.js'),ENT_QUOTES,'UTF-8') ?>?v=<?= filemtime(__DIR__.'/../assets/js/client-business-identity.js') ?>" defer></script>
<script>(()=>{const file=document.querySelector("#logo_client"),drop=document.querySelector("#logoDrop"),preview=document.querySelector("#logoPreview");
        const showFile = selected => {
            const image = selected && selected.type.startsWith('image/');
            if (!image) return;
            const reader = new FileReader();
            reader.onload = event => {
                preview.innerHTML = '<img src="' + event.target.result + '" alt="Aperçu du logo">';
            };
            reader.readAsDataURL(selected);
        };
        file.addEventListener('change', () => showFile(file.files[0]));
        ['dragenter', 'dragover'].forEach(event => drop.addEventListener(event, e => {
            e.preventDefault();
            drop.classList.add('is-over');
        }));
        ['dragleave', 'drop'].forEach(event => drop.addEventListener(event, e => {
            e.preventDefault();
            drop.classList.remove('is-over');
        }));
        drop.addEventListener('drop', e => {
            const selected = e.dataTransfer.files[0];
            if (!selected) return;
            try {
                const transfer = new DataTransfer();
                transfer.items.add(selected);
                file.files = transfer.files;
            } catch (error) {}
            showFile(selected);
        });
})();</script>
<?php $content = ob_get_clean();
require __DIR__ . '/../devis/includes/module_layout.php';


