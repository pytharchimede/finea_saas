<?php
require_once __DIR__ . '/_logistics_common.php';

$assets = logi_options_assets($pdo);
$drivers = logi_options_drivers($pdo);
$personnel = logi_rh_personnel($pdo);
$chantiers = logi_chantiers($pdo);
$debourseOptions = logi_debourse_options($pdo);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assetIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['asset_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
    $assetId = (int)($assetIds[0] ?? 0);
    $driverId = (int)($_POST['driver_id'] ?? 0);
    $executorPersonnelId = (int)($_POST['executor_personnel_id'] ?? 0);
    $assistantPersonnelId = (int)($_POST['assistant_personnel_id'] ?? 0);
    $ligneDebourseId = (int)($_POST['ligne_debourse_id'] ?? 0);
    $mission = trim((string)($_POST['mission_title'] ?? ''));
    $siteLabel = trim((string)($_POST['site_label'] ?? ''));
    $startAt = str_replace('T', ' ', trim((string)($_POST['start_at'] ?? '')));
    $endAt = str_replace('T', ' ', trim((string)($_POST['end_at'] ?? '')));
    $status = trim((string)($_POST['status'] ?? 'planned')) ?: 'planned';
    $notes = trim((string)($_POST['notes'] ?? ''));
    $selectedChantierId = 0;
    $selectedDebourse = null;
    $executorName = '';
    $assistantName = '';

    foreach ($chantiers as $chantier) {
        if (trim((string)($chantier['label'] ?? '')) === $siteLabel) {
            $selectedChantierId = (int)($chantier['id'] ?? 0);
            break;
        }
    }
    foreach ($debourseOptions as $option) {
        if ((int)($option['value'] ?? 0) === $ligneDebourseId) {
            $selectedDebourse = $option;
            break;
        }
    }
    foreach ($personnel as $person) {
        if ((int)($person['id'] ?? 0) === $executorPersonnelId) {
            $executorName = trim((string)($person['full_name'] ?? ''));
        }
        if ((int)($person['id'] ?? 0) === $assistantPersonnelId) {
            $assistantName = trim((string)($person['full_name'] ?? ''));
        }
    }
    if ($selectedDebourse) {
        if ($siteLabel === '') {
            $siteLabel = (string)($selectedDebourse['chantier_label'] ?? '');
            $selectedChantierId = (int)($selectedDebourse['chantier_id'] ?? 0);
        } elseif ($selectedChantierId > 0 && (int)($selectedDebourse['chantier_id'] ?? 0) !== $selectedChantierId) {
            $errors[] = 'Le debourse selectionne ne correspond pas au chantier choisi.';
        }
    }

    if ($mission === '') $errors[] = 'La mission est obligatoire.';
    if ($startAt === '') $errors[] = 'La date de debut est obligatoire.';
    if ($endAt !== '' && $startAt !== '' && strtotime($endAt) <= strtotime($startAt)) $errors[] = 'La date de fin doit etre posterieure au debut.';
    if ($executorPersonnelId > 0 && $assistantPersonnelId > 0 && $executorPersonnelId === $assistantPersonnelId) {
        $errors[] = 'Le suiveur / executant et son adjoint doivent etre deux personnels differents.';
    }
    if ($executorPersonnelId > 0 && $executorName === '') $errors[] = 'Le suiveur / executant selectionne est introuvable.';
    if ($assistantPersonnelId > 0 && $assistantName === '') $errors[] = 'L adjoint selectionne est introuvable.';

    if (!$errors && $startAt !== '') {
        $checkEnd = $endAt !== '' ? $endAt : $startAt;
        foreach ($assetIds as $checkedAssetId) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM logistics_assignments WHERE asset_id = ? AND status NOT IN ('cancelled','annulee','completed','terminee') AND start_at < ? AND COALESCE(end_at, start_at) > ?");
            $stmt->execute([$checkedAssetId, $checkEnd, $startAt]);
            if ((int)$stmt->fetchColumn() > 0) {
                $errors[] = 'Une des ressources selectionnees est deja occupee sur cette periode.';
                break;
            }
        }
        if ($driverId > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM logistics_assignments WHERE driver_id = ? AND status NOT IN ('cancelled','annulee','completed','terminee') AND start_at < ? AND COALESCE(end_at, start_at) > ?");
            $stmt->execute([$driverId, $checkEnd, $startAt]);
            if ((int)$stmt->fetchColumn() > 0) $errors[] = 'Ce chauffeur est deja affecte sur cette periode.';
        }
    }

    if (!$errors) {
        $requestNotifications = [];
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO logistics_assignments(asset_id, driver_id, site_id, site_label, debourse_id, ligne_debourse_id, debourse_label, executor_personnel_id, executor_name, assistant_personnel_id, assistant_name, mission_title, start_at, end_at, status, notes, created_at)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")->execute([
                $assetId ?: 0,
                $driverId ?: null,
                $selectedChantierId > 0 ? $selectedChantierId : null,
                $siteLabel !== '' ? $siteLabel : null,
                $selectedDebourse ? (int)($selectedDebourse['debourse_id'] ?? 0) : null,
                $ligneDebourseId > 0 ? $ligneDebourseId : null,
                $selectedDebourse ? (string)($selectedDebourse['label'] ?? '') : null,
                $executorPersonnelId > 0 ? $executorPersonnelId : null,
                $executorName !== '' ? $executorName : null,
                $assistantPersonnelId > 0 ? $assistantPersonnelId : null,
                $assistantName !== '' ? $assistantName : null,
                $mission,
                str_replace('T', ' ', $startAt),
                $endAt !== '' ? str_replace('T', ' ', $endAt) : null,
                $status,
                $notes !== '' ? $notes : null,
            ]);
            $assignmentId = (int)$pdo->lastInsertId();
            foreach ($assetIds as $requestAssetId) {
                $code = 'REQ-LOG-PLAN-' . date('ymd-His') . '-' . $requestAssetId;
                $pdo->prepare("INSERT INTO logistics_requests(request_code,user_id,type,need_type,asset_id,date_start,date_end,desired_at,motif,description,requester_name,holder_type,holder_label,chantier_id,site_label,debourse_id,ligne_debourse_id,debourse_label,priority,statut,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute([
                        $code,
                        (int)($_SESSION['user_id'] ?? 0) ?: null,
                        'affectation',
                        'ressource',
                        $requestAssetId,
                        str_replace('T', ' ', $startAt),
                        $endAt !== '' ? str_replace('T', ' ', $endAt) : null,
                        str_replace('T', ' ', $startAt),
                        $notes,
                        'Assignation planning : ' . $mission,
                        $executorName ?: $assistantName ?: 'Planning logistique',
                        $executorName !== '' ? 'personnel' : 'service',
                        $executorName ?: ($siteLabel ?: 'Planning logistique'),
                        $selectedChantierId > 0 ? $selectedChantierId : null,
                        $siteLabel !== '' ? $siteLabel : null,
                        $selectedDebourse ? (int)($selectedDebourse['debourse_id'] ?? 0) : null,
                        $ligneDebourseId > 0 ? $ligneDebourseId : null,
                        $selectedDebourse ? (string)($selectedDebourse['label'] ?? '') : null,
                        'normal',
                        'en attente',
                        'submitted',
                    ]);
                $requestId = (int)$pdo->lastInsertId();
                $requestNotifications[] = [
                    'id' => $requestId,
                    'request_code' => $code,
                    'user_id' => (int)($_SESSION['user_id'] ?? 0),
                    'need_type' => 'ressource',
                    'asset_id' => $requestAssetId,
                    'asset_label' => $mission,
                    'requester_name' => $executorName ?: 'Planning logistique',
                    'status' => 'submitted',
                ];
            }
            $attachments = logi_store_uploaded_files('attachments', 'planning');
            if ($attachments) {
                $stmtAttach = $pdo->prepare("INSERT INTO logistics_assignment_attachments(assignment_id, original_name, file_path, file_size, mime_type, created_at) VALUES(?,?,?,?,?,NOW())");
                foreach ($attachments as $file) {
                    $stmtAttach->execute([
                        $assignmentId,
                        $file['original_name'] ?? null,
                        $file['file_path'] ?? '',
                        (int)($file['file_size'] ?? 0),
                        $file['mime_type'] ?? null,
                    ]);
                }
            }
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            foreach ($requestNotifications as $notificationPayload) {
                logi_notify_request($pdo, 'submitted', $notificationPayload, logi_actor_context());
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'Impossible d enregistrer le planning : ' . $e->getMessage();
        }
    }

    if (!$errors && isset($assignmentId)) {
        logi_audit($pdo, 'create_assignment', 'logistics_assignment', $assignmentId, $mission, [
            'asset_ids' => $assetIds,
            'driver_id' => $driverId,
            'site_label' => $siteLabel,
            'chantier_id' => $selectedChantierId,
            'ligne_debourse_id' => $ligneDebourseId,
            'executor_personnel_id' => $executorPersonnelId,
            'assistant_personnel_id' => $assistantPersonnelId,
            'attachments' => count($attachments ?? []),
        ]);
        logi_redirect('planning.php?success=1');
    }
}

$statusOptions = [
    'planned' => 'Planifiee',
    'in_progress' => 'En cours',
    'completed' => 'Terminee',
    'cancelled' => 'Annulee',
];

logi_page_start('Ajouter une affectation', 'Formulaire dedie au planning des engins, vehicules et chauffeurs.');
$personnelOptions = array_map(static fn(array $person): array => [
    'value' => (string)($person['id'] ?? ''),
    'label' => trim((string)($person['full_name'] ?? '') . (!empty($person['service_label']) ? ' - ' . $person['service_label'] : '') . (!empty($person['phone']) ? ' - ' . $person['phone'] : '')),
], $personnel);
$selectedAssetIds = array_map('strval', (array)($_POST['asset_ids'] ?? []));
?>
<?php if ($errors): ?>
    <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 font-semibold text-rose-800">
        <?= logi_h(implode(' ', $errors)) ?>
    </div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data">
    <?php logi_card_open('Planning', 'Nouvelle affectation ou tache terrain'); ?>
    <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
        <?= logi_input('Mission', 'mission_title', $_POST['mission_title'] ?? '', 'text', 'required placeholder="Ex: Transport materiaux chantier"') ?>
        <?= logi_chantier_search_input('Chantier / zone', 'site_label', $chantiers, (string)($_POST['site_label'] ?? '')) ?>
        <?= logi_search_select('Debourse lie', 'ligne_debourse_id', [], (string)($_POST['ligne_debourse_id'] ?? ''), '', 'Choisir un chantier d abord') ?>
        <div></div>
        <?= logi_search_select('Suiveur / executant', 'executor_personnel_id', array_merge([['value' => '', 'label' => 'Non renseigne']], $personnelOptions), (string)($_POST['executor_personnel_id'] ?? ''), '', 'Rechercher un membre du personnel') ?>
        <?= logi_search_select('Adjoint', 'assistant_personnel_id', array_merge([['value' => '', 'label' => 'Non renseigne']], $personnelOptions), (string)($_POST['assistant_personnel_id'] ?? ''), '', 'Rechercher un adjoint') ?>
        <?= logi_multi_search_select('Ressources a reserver', 'asset_ids[]', logi_search_options($assets, 'id', 'label'), $selectedAssetIds, 'Rechercher et ajouter plusieurs engins ou vehicules') ?>
        <?= logi_search_select('Chauffeur', 'driver_id', logi_search_options($drivers, 'id', 'label', 'Aucun chauffeur'), (string)($_POST['driver_id'] ?? ''), '', 'Rechercher un chauffeur') ?>
        <?= logi_input('Debut', 'start_at', $_POST['start_at'] ?? '', 'datetime-local', 'required') ?>
        <?= logi_input('Fin prevue', 'end_at', $_POST['end_at'] ?? '', 'datetime-local') ?>
        <?= logi_select('Statut', 'status', $statusOptions, $_POST['status'] ?? 'planned') ?>
        <div></div>
        <div class="md:col-span-2"><?= logi_file_dropzone_multiple('Pieces jointes', 'attachments', 'image/*,.pdf,.doc,.docx,.xls,.xlsx', 'Glisser les pieces ici ou cliquer pour ajouter') ?></div>
        <div class="md:col-span-2"><?= logi_textarea('Notes', 'notes', $_POST['notes'] ?? '', 'placeholder="Contraintes, consignes, lieu exact..."') ?></div>
    </div>
    <div class="mt-6 flex justify-end gap-3">
        <a href="planning.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 font-semibold text-slate-700 hover:shadow-sm">Annuler</a>
        <button class="rounded-2xl bg-orange-600 px-6 py-3 font-semibold text-white shadow-lg shadow-orange-600/20 hover:bg-orange-700">Enregistrer</button>
    </div>
    <?php logi_card_close(); ?>
</form>
<script>
document.addEventListener('DOMContentLoaded', function() {
    window.setTimeout(function() {
    const allDebourses = <?= json_encode($debourseOptions, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?: '[]' ?>;
    const allChantiers = <?= json_encode(array_map(function($chantier) {
        $label = trim((string)($chantier['label'] ?? ''));
        return ['value' => $label, 'label' => $label, 'chantier_id' => (int)($chantier['id'] ?? 0)];
    }, $chantiers), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?: '[]' ?>;
    const chantierField = document.querySelector('input[name="site_label"][data-search-value]');
    const debourseField = document.querySelector('input[name="ligne_debourse_id"][data-search-value]');
    const initialDebourseValue = debourseField ? String(debourseField.value || '') : '';
    if (!chantierField || !debourseField) return;
    const chantierRoot = chantierField.closest('.logi-search-select');
    const debourseRoot = debourseField.closest('.logi-search-select');
    const debourseInput = debourseRoot ? debourseRoot.querySelector('[data-search-input]') : null;

    function chantierIdFromValue() {
        const selected = allChantiers.find(function(chantier) {
            return chantier.value === chantierField.value || chantier.label === chantierField.value;
        });
        return selected ? String(selected.chantier_id) : '';
    }
    function refreshDebourses(chantierId) {
        const options = chantierId ? allDebourses.filter(function(option) {
            return String(option.chantier_id) === chantierId;
        }) : allDebourses;
        const finalOptions = [{value: '', label: chantierId ? 'Aucun debourse' : 'Tous les debourses'}].concat(options);
        debourseRoot.dispatchEvent(new CustomEvent('logi:update-options', { detail: { options: finalOptions } }));
        if (initialDebourseValue) {
            const selected = finalOptions.find(function(option) { return String(option.value) === initialDebourseValue; });
            if (selected) {
                debourseField.value = selected.value;
                if (debourseInput) debourseInput.value = selected.label;
            }
        }
        debourseRoot.classList.toggle('opacity-60', !chantierId && !allDebourses.length);
        if (debourseInput) debourseInput.placeholder = chantierId ? 'Rechercher le debourse' : 'Rechercher un debourse optionnel';
    }
    if (chantierRoot) {
        chantierRoot.addEventListener('logi:selected', function(event) {
            refreshDebourses(String((event.detail && event.detail.chantier_id) || chantierIdFromValue()));
        });
    }
    refreshDebourses(chantierIdFromValue());
    }, 0);
});
</script>
<?php logi_page_end(); ?>
