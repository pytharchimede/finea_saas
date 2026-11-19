<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';
require_once __DIR__ . '/includes/personnel_helpers.php';

$personnelId = max(0, (int) ($_GET['id'] ?? 0));

$pdo = \Database::getConnection();
$directory = (new \RhPersonnelController($pdo))->service();

$actor = [
    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    'user_name' => isset($_SESSION['user_name']) ? trim((string) $_SESSION['user_name']) : null,
];

$auditRhDossier = static function (string $eventType, array $context = []) use ($actor, $personnelId): void {
    \AuditTrail::log($eventType, array_merge([
        'module' => 'rh',
        'entity_type' => 'personnel',
        'entity_id' => $personnelId > 0 ? (string) $personnelId : null,
        'user_id' => $actor['user_id'],
        'user_name' => $actor['user_name'],
    ], $context));
};

$statusType = null;
$statusMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $personnelId > 0) {
    try {
        $existingEmployee = $directory->getPersonnelDetail($personnelId);
        if ($existingEmployee === null) {
            throw new RuntimeException('Le dossier RH cible est introuvable.');
        }

        $action = trim((string) ($_POST['form_action'] ?? 'save_dossier'));

        if ($action === 'save_dossier') {
            $payload = [
                'full_name' => trim((string) ($_POST['full_name'] ?? '')),
                'gender_code' => (string) ($_POST['gender_code'] ?? ''),
                'birth_date' => (string) ($_POST['birth_date'] ?? ''),
                'phone' => trim((string) ($_POST['phone'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? '')),
                'service_id' => (string) ($_POST['service_id'] ?? ''),
                'fonction_id' => (string) ($_POST['fonction_id'] ?? ''),
                'status_code' => (string) ($_POST['status_code'] ?? ''),
                'site_id' => (string) ($_POST['site_id'] ?? ''),
                'hire_date' => (string) ($_POST['hire_date'] ?? ''),
                'start_date' => (string) ($_POST['start_date'] ?? ''),
                'end_date' => (string) ($_POST['end_date'] ?? ''),
                'exit_reason_id' => (string) ($_POST['exit_reason_id'] ?? ''),
                'marital_status' => trim((string) ($_POST['marital_status'] ?? '')),
                'father_name' => trim((string) ($_POST['father_name'] ?? '')),
                'father_phone' => trim((string) ($_POST['father_phone'] ?? '')),
                'mother_name' => trim((string) ($_POST['mother_name'] ?? '')),
                'mother_phone' => trim((string) ($_POST['mother_phone'] ?? '')),
                'cni_number' => trim((string) ($_POST['cni_number'] ?? '')),
                'cnps_number' => trim((string) ($_POST['cnps_number'] ?? '')),
                'emergency_contact_name' => trim((string) ($_POST['emergency_contact_name'] ?? '')),
                'emergency_contact_phone' => trim((string) ($_POST['emergency_contact_phone'] ?? '')),
                'contract_duration_months' => (string) ($_POST['contract_duration_months'] ?? ''),
                'has_children' => (string) ($_POST['has_children'] ?? ''),
                'children_count' => (string) ($_POST['children_count'] ?? ''),
            ];

            if ($payload['full_name'] === '') {
                throw new RuntimeException('Le nom complet est obligatoire pour enregistrer le dossier RH.');
            }

            if ($payload['has_children'] === '' && $payload['children_count'] !== '') {
                $payload['has_children'] = ((int) $payload['children_count'] > 0) ? '1' : '0';
            }

            $directory->updatePersonnel($personnelId, $payload, $actor);

            $uploadMap = [
                'photo_file' => ['alias' => 'photo', 'folder' => 'photo'],
                'birth_certificate_file' => ['alias' => 'birth_certificate_path', 'folder' => 'extrait-naissance'],
                'identity_document_file' => ['alias' => 'identity_document_path', 'folder' => 'piece-identite'],
                'last_degree_file' => ['alias' => 'last_degree_path', 'folder' => 'diplome'],
                'employment_contract_file' => ['alias' => 'employment_contract_path', 'folder' => 'contrat-embauche'],
            ];

            $documentUpdates = [];
            foreach ($uploadMap as $fieldName => $config) {
                if (!rhHasUploadedFile($fieldName)) {
                    continue;
                }

                $documentUpdates[$config['alias']] = rhStoreUploadedDocument($_FILES[$fieldName], $config['folder'], $personnelId);
            }

            if (!empty($documentUpdates)) {
                $directory->updatePersonnelDocuments($personnelId, $documentUpdates, $actor);
                $auditRhDossier('rh_personnel_documents_uploaded', [
                    'action' => 'documents_upload',
                    'entity_label' => (string) ($payload['full_name'] ?? $existingEmployee['full_name'] ?? 'dossier_rh'),
                    'details' => [
                        'document_count' => count($documentUpdates),
                        'document_keys' => array_keys($documentUpdates),
                    ],
                ]);
            }

            $auditRhDossier('rh_personnel_dossier_updated', [
                'action' => 'save_dossier',
                'entity_label' => (string) ($payload['full_name'] ?? $existingEmployee['full_name'] ?? 'dossier_rh'),
                'details' => [
                    'documents_updated' => count($documentUpdates),
                    'service_id' => $payload['service_id'] !== '' ? $payload['service_id'] : null,
                    'fonction_id' => $payload['fonction_id'] !== '' ? $payload['fonction_id'] : null,
                    'site_id' => $payload['site_id'] !== '' ? $payload['site_id'] : null,
                    'status_code' => $payload['status_code'] !== '' ? $payload['status_code'] : null,
                ],
            ]);

            header('Location: dossier_personnel_rh.php?id=' . $personnelId . '&saved=1#edit-form');
            exit;
        }

        if ($action === 'add_history_event') {
            $eventTitle = trim((string) ($_POST['event_title'] ?? ''));
            $directory->addPersonnelHistoryEvent($personnelId, [
                'event_type' => (string) ($_POST['event_type'] ?? ''),
                'event_date' => (string) ($_POST['event_date'] ?? ''),
                'title' => $eventTitle,
                'description' => trim((string) ($_POST['event_description'] ?? '')),
                'metadata' => [
                    'entered_from' => 'dossier_personnel_rh',
                ],
            ], $actor);

            $auditRhDossier('rh_personnel_history_added', [
                'action' => 'add_history_event',
                'entity_label' => $eventTitle !== '' ? $eventTitle : 'evenement_rh',
                'details' => [
                    'event_type' => (string) ($_POST['event_type'] ?? ''),
                    'event_date' => (string) ($_POST['event_date'] ?? ''),
                ],
            ]);

            header('Location: dossier_personnel_rh.php?id=' . $personnelId . '&history_saved=1#history-section');
            exit;
        }

        if ($action === 'apply_mutation') {
            $mutationPayload = [
                'service_id' => (string) ($_POST['mutation_service_id'] ?? ''),
                'fonction_id' => (string) ($_POST['mutation_fonction_id'] ?? ''),
                'site_id' => (string) ($_POST['mutation_site_id'] ?? ''),
                'status_code' => (string) ($_POST['mutation_status_code'] ?? ''),
                'start_date' => (string) ($_POST['mutation_start_date'] ?? ''),
                'event_date' => (string) ($_POST['mutation_effective_date'] ?? ''),
                'title' => trim((string) ($_POST['mutation_title'] ?? '')),
                'reason' => trim((string) ($_POST['mutation_reason'] ?? '')),
            ];

            $directory->applyPersonnelMutation($personnelId, $mutationPayload, $actor);

            $auditRhDossier('rh_personnel_mutation_applied', [
                'action' => 'apply_mutation',
                'entity_label' => (string) ($existingEmployee['full_name'] ?? 'mutation_rh'),
                'details' => [
                    'service_id' => $mutationPayload['service_id'] !== '' ? $mutationPayload['service_id'] : null,
                    'fonction_id' => $mutationPayload['fonction_id'] !== '' ? $mutationPayload['fonction_id'] : null,
                    'site_id' => $mutationPayload['site_id'] !== '' ? $mutationPayload['site_id'] : null,
                    'status_code' => $mutationPayload['status_code'] !== '' ? $mutationPayload['status_code'] : null,
                    'effective_date' => $mutationPayload['event_date'],
                ],
            ]);

            header('Location: dossier_personnel_rh.php?id=' . $personnelId . '&mutation_saved=1#mutation-section');
            exit;
        }

        if ($action === 'archive_document' || $action === 'delete_document') {
            $documentKey = trim((string) ($_POST['document_key'] ?? ''));
            if ($documentKey === '') {
                throw new RuntimeException('Aucune piece RH n\'a ete selectionnee.');
            }

            if ($action === 'archive_document') {
                $directory->archivePersonnelDocument($personnelId, $documentKey, $actor);
                $auditRhDossier('rh_personnel_document_archived', [
                    'action' => 'archive_document',
                    'entity_label' => $documentKey,
                    'details' => ['document_key' => $documentKey],
                ]);
                header('Location: dossier_personnel_rh.php?id=' . $personnelId . '&document_action=archived#documents-section');
            } else {
                $directory->deletePersonnelDocument($personnelId, $documentKey, $actor);
                $auditRhDossier('rh_personnel_document_deleted', [
                    'action' => 'delete_document',
                    'entity_label' => $documentKey,
                    'details' => ['document_key' => $documentKey],
                ]);
                header('Location: dossier_personnel_rh.php?id=' . $personnelId . '&document_action=deleted#documents-section');
            }
            exit;
        }

        if ($action === 'restore_document_archive') {
            $archiveId = max(0, (int) ($_POST['archive_id'] ?? 0));
            if ($archiveId <= 0) {
                throw new RuntimeException('Aucune archive RH valide n\'a ete selectionnee.');
            }

            $directory->restorePersonnelDocumentArchive($personnelId, $archiveId, $actor);
            $auditRhDossier('rh_personnel_document_restored', [
                'action' => 'restore_document_archive',
                'entity_label' => 'archive_' . $archiveId,
                'details' => ['archive_id' => $archiveId],
            ]);
            header('Location: dossier_personnel_rh.php?id=' . $personnelId . '&document_action=restored#documents-section');
            exit;
        }

        throw new RuntimeException('Action RH non reconnue.');
    } catch (Throwable $e) {
        $auditRhDossier('rh_personnel_dossier_failed', [
            'action' => (string) ($_POST['form_action'] ?? 'save_dossier'),
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['error' => $e->getMessage()],
        ]);
        $statusType = 'error';
        $statusMessage = $e->getMessage();
    }
}

$employee = $directory->getPersonnelDetail($personnelId);
$jobDescription=null;if($employee&& !empty($employee['fonction_id'])){$q=$pdo->prepare("SELECT fp.*,f.lib_fonction fonction_label FROM rh_fiches_poste fp JOIN fonction f ON f.id_fonction=fp.fonction_id WHERE fp.fonction_id=? AND fp.statut='active' ORDER BY fp.date_effet DESC,fp.id DESC LIMIT 1");$q->execute([(int)$employee['fonction_id']]);$jobDescription=$q->fetch(PDO::FETCH_ASSOC)?:null;}
$filterOptions = $directory->getFilters();
$history = $employee !== null ? $directory->getPersonnelHistory($personnelId) : [];
$assignments = $employee !== null ? $directory->getPersonnelAssignments($personnelId) : [];
$changeLog = $employee !== null ? $directory->getPersonnelChangeLog($personnelId) : [];
$documentArchives = $employee !== null ? $directory->getPersonnelDocumentArchives($personnelId) : [];

if ($statusMessage === null && $employee !== null) {
    if (isset($_GET['saved'])) {
        $statusType = 'success';
        $statusMessage = 'Le dossier RH, les pieces et le journal des modifications ont ete mis a jour.';
    } elseif (isset($_GET['mutation_saved'])) {
        $statusType = 'success';
        $statusMessage = 'La mutation / affectation RH a ete enregistree et historisee.';
    } elseif (isset($_GET['history_saved'])) {
        $statusType = 'success';
        $statusMessage = 'L\'evenement RH reel a ete ajoute au dossier.';
    } elseif (isset($_GET['document_action'])) {
        $statusType = 'success';
        if ($_GET['document_action'] === 'archived') {
            $statusMessage = 'La piece a ete archivee et retiree du dossier actif.';
        } elseif ($_GET['document_action'] === 'restored') {
            $statusMessage = 'La piece archivee a ete restauree dans le dossier actif.';
        } else {
            $statusMessage = 'La piece a ete supprimee du dossier actif.';
        }
    }
}

function rhHistoryTheme(string $type): array
{
    $themes = [
        'recrutement' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'line' => 'border-emerald-200'],
        'prise_service' => ['bg' => 'bg-cyan-100', 'text' => 'text-cyan-700', 'line' => 'border-cyan-200'],
        'affectation' => ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-700', 'line' => 'border-indigo-200'],
        'mutation' => ['bg' => 'bg-fuchsia-100', 'text' => 'text-fuchsia-700', 'line' => 'border-fuchsia-200'],
        'affectation_operationnelle' => ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-700', 'line' => 'border-indigo-200'],
        'sortie' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'line' => 'border-amber-200'],
        'renouvellement' => ['bg' => 'bg-violet-100', 'text' => 'text-violet-700', 'line' => 'border-violet-200'],
        'echeance_calculee' => ['bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'line' => 'border-slate-200'],
        'promotion' => ['bg' => 'bg-fuchsia-100', 'text' => 'text-fuchsia-700', 'line' => 'border-fuchsia-200'],
        'sanction' => ['bg' => 'bg-rose-100', 'text' => 'text-rose-700', 'line' => 'border-rose-200'],
        'note' => ['bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'line' => 'border-slate-200'],
    ];

    return $themes[$type] ?? ['bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'line' => 'border-slate-200'];
}

function rhSourceTheme(string $sourceKind): string
{
    $themes = [
        'real' => 'bg-emerald-100 text-emerald-700',
        'computed' => 'bg-amber-100 text-amber-700',
        'record' => 'bg-slate-100 text-slate-700',
        'operation' => 'bg-cyan-100 text-cyan-700',
    ];

    return $themes[$sourceKind] ?? 'bg-slate-100 text-slate-700';
}

$pageTitle = 'Dossier complet RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

if ($employee === null):
?>
    <div class="min-h-screen bg-slate-50 py-12">
        <div class="container mx-auto px-4">
            <div class="mx-auto max-w-3xl rounded-[30px] border border-rose-100 bg-white p-10 text-center shadow-[0_18px_60px_rgba(15,23,42,0.07)]">
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-rose-100 text-rose-600">
                    <i class="fas fa-folder-open text-3xl"></i>
                </div>
                <h1 class="mt-6 text-3xl font-semibold text-slate-950">Dossier introuvable</h1>
                <p class="mt-3 text-slate-500">Le collaborateur demande n'existe pas ou n'est plus accessible.</p>
                <div class="mt-8 flex justify-center gap-3">
                    <a href="liste_personnel_rh.php" class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">Retour a la liste</a>
                    <a href="dashboard_rh.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Tableau de bord RH</a>
                </div>
            </div>
        </div>
    </div>
<?php
    require_once __DIR__ . '/../decaissement/includes/footer.php';
    return;
endif;

$photoUrl = rhResolveMediaUrl($employee['photo'] ?? null);
$childrenCount = $employee['children_count'] ?? $employee['children_count_legacy'] ?? null;
$exitReasonsAll = $directory->getExitReasons(true);
$exitReasonsById = [];
$exitReasonsActive = [];
foreach ($exitReasonsAll as $reason) {
    $id = (int) ($reason['id'] ?? 0);
    if ($id <= 0) {
        continue;
    }

    $exitReasonsById[$id] = $reason;
    if ((int) ($reason['is_active'] ?? 0) === 1) {
        $exitReasonsActive[] = $reason;
    }
}

$currentExitReasonId = (int) ($employee['exit_reason_id'] ?? 0);
$currentExitReasonLabel = $employee['exit_reason_label'] ?? null;
if (($currentExitReasonLabel === null || trim((string) $currentExitReasonLabel) === '') && $currentExitReasonId > 0) {
    $currentExitReasonLabel = $exitReasonsById[$currentExitReasonId]['label'] ?? null;
}
$currentExitReasonLabel = $currentExitReasonLabel !== null ? trim((string) $currentExitReasonLabel) : '';
$exitReasonDisplay = null;
if ($currentExitReasonLabel !== '') {
    $exitReasonDisplay = $currentExitReasonLabel;
    if ($currentExitReasonId > 0) {
        $exitReasonDisplay .= ' (#' . $currentExitReasonId . ')';
    }
} elseif ($currentExitReasonId > 0) {
    $exitReasonDisplay = '#' . $currentExitReasonId;
}
$historyRealCount = count(array_filter($history, static fn(array $event): bool => ($event['source_kind'] ?? '') === 'real'));
$historyComputedCount = count(array_filter($history, static fn(array $event): bool => ($event['source_kind'] ?? '') === 'computed'));
$restorableArchivesCount = count(array_filter($documentArchives, static fn(array $archive): bool => in_array((string) ($archive['action_type'] ?? ''), ['archived', 'replaced'], true) && !empty($archive['archived_path'])));
$documentLabels = [
    'photo' => 'Photo de profil',
    'birth_certificate_path' => 'Extrait de naissance',
    'identity_document_path' => 'Piece d\'identite / CNI',
    'last_degree_path' => 'Dernier diplome',
    'employment_contract_path' => 'Contrat d\'embauche',
];
$documentActionLabels = [
    'replaced' => 'Remplacement',
    'archived' => 'Archivage',
    'deleted' => 'Suppression',
    'restored' => 'Restauration',
];
$historyTypeOptions = [
    'renouvellement' => 'Renouvellement reel',
    'affectation' => 'Affectation RH',
    'sortie' => 'Sortie RH',
    'promotion' => 'Promotion',
    'sanction' => 'Mesure disciplinaire',
    'note' => 'Note RH',
];

$documentCards = [
    [
        'title' => 'Photo de profil',
        'path' => $employee['photo'] ?? null,
        'icon' => 'fa-camera-retro',
        'input_name' => 'photo_file',
        'document_key' => 'photo',
    ],
    [
        'title' => 'Extrait de naissance',
        'path' => $employee['birth_certificate_path'] ?? null,
        'icon' => 'fa-file-lines',
        'input_name' => 'birth_certificate_file',
        'document_key' => 'birth_certificate_path',
    ],
    [
        'title' => 'Piece d\'identite / CNI',
        'path' => $employee['identity_document_path'] ?? null,
        'icon' => 'fa-id-card',
        'input_name' => 'identity_document_file',
        'document_key' => 'identity_document_path',
    ],
    [
        'title' => 'Dernier diplome',
        'path' => $employee['last_degree_path'] ?? null,
        'icon' => 'fa-graduation-cap',
        'input_name' => 'last_degree_file',
        'document_key' => 'last_degree_path',
    ],
    [
        'title' => 'Contrat d\'embauche',
        'path' => $employee['employment_contract_path'] ?? null,
        'icon' => 'fa-file-signature',
        'input_name' => 'employment_contract_file',
        'document_key' => 'employment_contract_path',
    ],
];

$infoBlocks = [
    'Identite' => [
        'Matricule' => $employee['matricule'] ?? null,
        'Nom complet' => $employee['full_name'] ?? null,
        'Sexe' => $employee['gender_label'] ?? null,
        'Date de naissance' => rhFormatDate($employee['birth_date'] ?? null),
        'Situation matrimoniale' => $employee['marital_status'] ?? null,
        'Site' => $employee['site_label'] ?? null,
    ],
    'Administratif' => [
        'Service' => $employee['service_label'] ?? null,
        'Fonction' => $employee['fonction_label'] ?? null,
        'Statut' => $employee['statut_label'] ?? null,
        'Date de recrutement' => rhFormatDate($employee['hire_date'] ?? null),
        'Date de prise de service' => rhFormatDate($employee['start_date'] ?? null),
        'Duree contrat (mois)' => $employee['contract_duration_months'] ?? null,
        'CNPS' => $employee['cnps_number'] ?? null,
        'CNI' => $employee['cni_number'] ?? null,
    ],
    'Contacts' => [
        'Telephone' => $employee['phone'] ?? null,
        'E-mail' => $employee['email'] ?? null,
        'Contact urgence' => $employee['emergency_contact_name'] ?? null,
        'Telephone urgence' => $employee['emergency_contact_phone'] ?? null,
        'Enfants' => $childrenCount,
        'Presence enfants' => isset($employee['has_children']) ? ((int) $employee['has_children'] === 1 ? 'Oui' : 'Non') : null,
    ],
    'Famille' => [
        'Nom du pere' => $employee['father_name'] ?? null,
        'Telephone du pere' => $employee['father_phone'] ?? null,
        'Nom de la mere' => $employee['mother_name'] ?? null,
        'Telephone de la mere' => $employee['mother_phone'] ?? null,
        'Date de sortie' => rhFormatDate($employee['end_date'] ?? null),
        'Motif sortie' => $exitReasonDisplay,
    ],
];
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(236,253,245,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(191,219,254,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <?php if ($statusMessage !== null): ?>
            <div class="mb-6 rounded-2xl border px-5 py-4 text-sm font-medium <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700' ?>">
                <?= htmlspecialchars($statusMessage) ?>
            </div>
        <?php endif; ?>

        <div class="overflow-hidden rounded-[34px] border border-slate-200 bg-white/90 shadow-[0_24px_80px_rgba(15,23,42,0.08)] backdrop-blur">
            <div class="relative bg-gradient-to-r from-slate-950 via-slate-900 to-cyan-900 px-8 py-10 text-white">
                <div class="absolute inset-y-0 right-0 w-1/3 bg-[radial-gradient(circle_at_center,_rgba(45,212,191,0.24),_transparent_58%)]"></div>
                <div class="relative flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
                        <?php if ($photoUrl !== null): ?>
                            <img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars((string) $employee['full_name']) ?>" class="h-28 w-28 rounded-[28px] border-4 border-white/90 object-cover shadow-2xl">
                        <?php else: ?>
                            <div class="flex h-28 w-28 items-center justify-center rounded-[28px] border-4 border-white/90 bg-white/10 text-4xl font-semibold text-white shadow-2xl">
                                <?= htmlspecialchars(rhInitials((string) $employee['full_name'])) ?>
                            </div>
                        <?php endif; ?>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.32em] text-cyan-200">Dossier complet</p>
                            <h1 class="mt-2 text-3xl font-semibold tracking-tight md:text-5xl"><?= htmlspecialchars((string) $employee['full_name']) ?></h1>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <span class="rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white/90"><?= htmlspecialchars((string) ($employee['matricule'] ?: 'Matricule non renseigne')) ?></span>
                                <span class="rounded-full bg-emerald-400/20 px-4 py-2 text-sm font-semibold text-emerald-100"><?= htmlspecialchars((string) $employee['service_label']) ?></span>
                                <span class="rounded-full bg-cyan-400/20 px-4 py-2 text-sm font-semibold text-cyan-100"><?= htmlspecialchars((string) $employee['fonction_label']) ?></span>
                                <span class="rounded-full px-4 py-2 text-sm font-semibold <?= (int) $employee['is_active'] === 1 ? 'bg-emerald-500/20 text-emerald-100' : 'bg-amber-500/20 text-amber-100' ?>">
                                    <?= (int) $employee['is_active'] === 1 ? 'En poste' : 'Sorti' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a href="modifier_dossier_personnel_rh.php?id=<?= (int) $employee['id'] ?>" class="rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-cyan-50">Modifier le dossier</a>
                        <a href="mutation_personnel_rh.php?id=<?= (int) $employee['id'] ?>" class="rounded-2xl border border-fuchsia-300/40 bg-fuchsia-400/10 px-5 py-3 text-sm font-semibold text-fuchsia-100 hover:bg-fuchsia-400/15">Mutation</a>
                        <a href="sortie_personnel_rh.php?id=<?= (int) $employee['id'] ?>" class="rounded-2xl border border-amber-300/40 bg-amber-400/10 px-5 py-3 text-sm font-semibold text-amber-100 hover:bg-amber-400/15">Sortie / reintegration</a>
                        <a href="export_contrat_rh.php?personnel_id=<?= (int) $employee['id'] ?>&download=1&signature_mode=prepositioned&document_state=draft&save_to_dossier=1" class="rounded-2xl border border-white/15 bg-white/10 px-5 py-3 text-sm font-semibold text-white hover:bg-white/15">Contrat brouillon</a>
                        <a data-passkey-signature-link href="export_contrat_rh.php?personnel_id=<?= (int) $employee['id'] ?>&download=1&signature_mode=prepositioned&document_state=signed&save_to_dossier=1" class="rounded-2xl border border-emerald-300/40 bg-emerald-400/10 px-5 py-3 text-sm font-semibold text-emerald-100 hover:bg-emerald-400/15">Original signe</a>
                        <a href="#history-section" class="rounded-2xl border border-white/15 bg-white/10 px-5 py-3 text-sm font-semibold text-white hover:bg-white/15">Historique RH</a>
                        <a href="#documents-section" class="rounded-2xl border border-white/15 bg-white/10 px-5 py-3 text-sm font-semibold text-white hover:bg-white/15">Archives restaurables<?= $restorableArchivesCount > 0 ? ' (' . $restorableArchivesCount . ')' : '' ?></a>
                        <a href="liste_personnel_rh.php" class="rounded-2xl border border-white/15 bg-white/10 px-5 py-3 text-sm font-semibold text-white hover:bg-white/15">Retour a la liste</a>
                        <?php if (!empty($employee['email'])): ?>
                            <a href="mailto:<?= htmlspecialchars((string) $employee['email']) ?>" class="rounded-2xl border border-white/15 bg-white/10 px-5 py-3 text-sm font-semibold text-white hover:bg-white/15">Contacter</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="grid gap-6 px-6 py-6 xl:grid-cols-[1.1fr_0.9fr] xl:px-8 xl:py-8">
                <div class="space-y-6">
                    <?php foreach ($infoBlocks as $sectionTitle => $items): ?>
                        <section class="rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
                            <h2 class="text-xl font-semibold text-slate-950"><?= htmlspecialchars($sectionTitle) ?></h2>
                            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                <?php foreach ($items as $label => $value): ?>
                                    <div class="rounded-2xl bg-slate-50 px-4 py-4">
                                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400"><?= htmlspecialchars($label) ?></div>
                                        <div class="mt-2 break-words text-sm font-medium text-slate-800"><?= htmlspecialchars((string) (($value !== null && $value !== '') ? $value : 'Non renseigne')) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                    <section class="rounded-[28px] border border-amber-200 bg-amber-50/50 p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[.22em] text-amber-700">Référentiel du poste</p><h2 class="mt-2 text-xl font-semibold text-slate-950"><?=$jobDescription?htmlspecialchars((string)$jobDescription['intitule']):'Fiche de poste non définie'?></h2></div><a href="fiches_poste_rh.php<?=$jobDescription?'?edit='.(int)$jobDescription['id']:''?>" class="rounded-xl bg-black px-4 py-2 text-sm font-bold text-white"><?=$jobDescription?'Consulter / modifier':'Créer la fiche'?></a></div><?php if($jobDescription):?><p class="mt-4 text-sm leading-6 text-slate-700"><?=nl2br(htmlspecialchars((string)($jobDescription['mission_principale']?:'Mission principale non renseignée.')))?></p><div class="mt-4 grid gap-3 sm:grid-cols-2"><div class="rounded-2xl bg-white p-4"><b class="text-sm">Responsabilités</b><p class="mt-2 whitespace-pre-line text-sm text-slate-600"><?=htmlspecialchars((string)($jobDescription['responsabilites']?:'Non renseignées'))?></p></div><div class="rounded-2xl bg-white p-4"><b class="text-sm">Compétences</b><p class="mt-2 whitespace-pre-line text-sm text-slate-600"><?=htmlspecialchars((string)($jobDescription['competences']?:'Non renseignées'))?></p></div></div><p class="mt-3 text-xs font-bold text-amber-800">Version <?=htmlspecialchars((string)$jobDescription['version'])?> · applicable depuis <?=htmlspecialchars((string)($jobDescription['date_effet']?:'date non renseignée'))?></p><?php else:?><p class="mt-3 text-sm text-amber-900">Aucune fiche active n’est encore rattachée à la fonction <?=htmlspecialchars((string)($employee['fonction_label']??''))?>.</p><?php endif;?></section>
                </div>

                <div class="space-y-6">
                    <section id="documents-section" class="rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-xl font-semibold text-slate-950">Pieces scannees</h2>
                            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">Actions directes</span>
                        </div>
                        <div class="mt-5 grid gap-4">
                            <?php foreach ($documentCards as $document): ?>
                                <?php $documentUrl = rhResolveMediaUrl($document['path'] ?? null); ?>
                                <div class="overflow-hidden rounded-[24px] border border-slate-100 bg-slate-50">
                                    <div class="flex flex-col gap-4 px-5 py-4">
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="flex items-start gap-4">
                                                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-slate-700 shadow-sm">
                                                    <i class="fas <?= htmlspecialchars($document['icon']) ?>"></i>
                                                </div>
                                                <div>
                                                    <h3 class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($document['title']) ?></h3>
                                                    <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars(rhHumanFileName($document['path'] ?? null)) ?></p>
                                                </div>
                                            </div>
                                            <?php if ($documentUrl !== null): ?>
                                                <a href="<?= htmlspecialchars($documentUrl) ?>" target="_blank" rel="noopener noreferrer" class="rounded-xl bg-slate-950 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">Ouvrir</a>
                                            <?php else: ?>
                                                <span class="rounded-xl border border-dashed border-slate-300 px-4 py-2 text-xs font-semibold text-slate-400">Manquant</span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($documentUrl !== null): ?>
                                            <div class="flex flex-wrap gap-2">
                                                <form method="post" class="inline-flex">
                                                    <input type="hidden" name="form_action" value="archive_document">
                                                    <input type="hidden" name="document_key" value="<?= htmlspecialchars($document['document_key']) ?>">
                                                    <button type="submit" onclick="return confirm('Archiver cette piece et la retirer du dossier actif ?');" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-100">Archiver</button>
                                                </form>
                                                <form method="post" class="inline-flex">
                                                    <input type="hidden" name="form_action" value="delete_document">
                                                    <input type="hidden" name="document_key" value="<?= htmlspecialchars($document['document_key']) ?>">
                                                    <button type="submit" onclick="return confirm('Supprimer definitivement cette piece du dossier actif ?');" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-100">Supprimer</button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($documentUrl !== null && rhIsPreviewableMedia($documentUrl)): ?>
                                        <div class="border-t border-slate-100 bg-white px-5 py-4">
                                            <img src="<?= htmlspecialchars($documentUrl) ?>" alt="<?= htmlspecialchars($document['title']) ?>" class="max-h-56 w-full rounded-2xl object-contain bg-slate-50">
                                        </div>
                                    <?php elseif ($documentUrl !== null && rhIsPreviewableDocument($documentUrl)): ?>
                                        <div class="border-t border-slate-100 bg-white px-5 py-4">
                                            <iframe src="<?= htmlspecialchars($documentUrl) ?>" title="<?= htmlspecialchars($document['title']) ?>" class="h-72 w-full rounded-2xl border border-slate-200 bg-slate-50"></iframe>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
                        <h2 class="text-xl font-semibold text-slate-950">Synthese RH</h2>
                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl bg-emerald-50 px-4 py-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700">Historique reel</div>
                                <div class="mt-2 text-sm font-semibold text-slate-900"><?= $historyRealCount ?></div>
                            </div>
                            <div class="rounded-2xl bg-slate-50 px-4 py-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-600">Echeances calculees</div>
                                <div class="mt-2 text-sm font-semibold text-slate-900"><?= $historyComputedCount ?></div>
                            </div>
                            <div class="rounded-2xl bg-cyan-50 px-4 py-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-cyan-700">Date d'entree</div>
                                <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars(rhFormatDate($employee['start_date'] ?? null)) ?></div>
                            </div>
                            <div class="rounded-2xl bg-amber-50 px-4 py-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.22em] text-amber-700">Derniere modification</div>
                                <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars(rhFormatDateTime($changeLog[0]['changed_at'] ?? null)) ?></div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-xl font-semibold text-slate-950">Journal des pieces</h2>
                                <p class="mt-1 text-xs text-slate-500">La restauration se fait ici, pas dans le journal d'audit du dossier.</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?= count($documentArchives) ?> entree(s)</span>
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700"><?= $restorableArchivesCount ?> restaurable(s)</span>
                            </div>
                        </div>
                        <div class="mt-5 space-y-3">
                            <?php if (empty($documentArchives)): ?>
                                <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-6 text-sm text-slate-500">Aucune suppression, archivage ou remplacement de piece n'a encore ete journalise.</div>
                            <?php else: ?>
                                <?php foreach ($documentArchives as $archive): ?>
                                    <?php $archiveUrl = rhResolveMediaUrl($archive['archived_path'] ?? null); ?>
                                    <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($documentLabels[$archive['document_key']] ?? (string) $archive['document_key']) ?></div>
                                                <p class="mt-1 text-xs text-slate-500">Ancien fichier: <?= htmlspecialchars(rhHumanFileName($archive['original_path'] ?? null)) ?></p>
                                                <p class="mt-2 text-xs text-slate-500">Auteur: <?= htmlspecialchars((string) (($archive['acted_by_name'] ?? '') !== '' ? $archive['acted_by_name'] : 'Systeme')) ?></p>
                                                <?php if ($archiveUrl !== null && in_array((string) ($archive['action_type'] ?? ''), ['archived', 'replaced'], true)): ?>
                                                    <p class="mt-2 text-xs font-semibold text-emerald-700">Cette archive peut etre restauree dans le dossier actif.</p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600"><?= htmlspecialchars($documentActionLabels[$archive['action_type']] ?? (string) $archive['action_type']) ?></span>
                                                <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600"><?= htmlspecialchars(rhFormatDateTime($archive['acted_at'] ?? null)) ?></span>
                                                <?php if ($archiveUrl !== null): ?>
                                                    <a href="<?= htmlspecialchars($archiveUrl) ?>" target="_blank" rel="noopener noreferrer" class="rounded-full bg-cyan-50 px-3 py-1 text-xs font-semibold text-cyan-700 hover:bg-cyan-100">Ouvrir archive</a>
                                                <?php endif; ?>
                                                <?php if ($archiveUrl !== null && in_array((string) ($archive['action_type'] ?? ''), ['archived', 'replaced'], true)): ?>
                                                    <form method="post" class="inline-flex">
                                                        <input type="hidden" name="form_action" value="restore_document_archive">
                                                        <input type="hidden" name="archive_id" value="<?= (int) ($archive['id'] ?? 0) ?>">
                                                        <button type="submit" onclick="return confirm('Restaurer cette piece archivee dans le dossier actif ?');" class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100">Restaurer</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>

            <div class="grid gap-6 border-t border-slate-100 px-6 py-6 xl:grid-cols-[1.05fr_0.95fr] xl:px-8 xl:py-8">
                <section id="history-section" class="rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.26em] text-indigo-600">Historique RH</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Chronologie du dossier</h2>
                            <p class="mt-2 text-sm text-slate-500">Les renouvellements reels saisis en RH sont distingues des simples echeances calculees.</p>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?= count($history) ?> evenement(s)</span>
                    </div>

                    <form method="post" class="mt-6 rounded-[24px] border border-slate-100 bg-slate-50 p-5">
                        <input type="hidden" name="form_action" value="add_history_event">
                        <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-4">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Type d'evenement</label>
                                <select name="event_type" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400">
                                    <?php foreach ($historyTypeOptions as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Date</label>
                                <input type="date" name="event_date" required class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400">
                            </div>
                            <div class="xl:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-slate-700">Intitule</label>
                                <input type="text" name="event_title" placeholder="Ex: Renouvellement signe pour 12 mois" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400">
                            </div>
                            <div class="lg:col-span-2 xl:col-span-4">
                                <label class="mb-2 block text-sm font-medium text-slate-700">Description</label>
                                <textarea name="event_description" rows="3" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-indigo-400" placeholder="Details sur l'evenement RH reel, decision, reference ou commentaire."></textarea>
                            </div>
                        </div>
                        <div class="mt-4 flex justify-end">
                            <button type="submit" class="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-500">Ajouter a l'historique reel</button>
                        </div>
                    </form>

                    <div class="mt-6 space-y-4">
                        <?php if (empty($history)): ?>
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-6 text-sm text-slate-500">Aucun historique RH disponible pour ce dossier.</div>
                        <?php else: ?>
                            <?php foreach ($history as $event): ?>
                                <?php $theme = rhHistoryTheme((string) ($event['type'] ?? '')); ?>
                                <div class="rounded-2xl border px-5 py-4 <?= htmlspecialchars($theme['line']) ?>">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <div class="flex flex-wrap gap-2">
                                                <div class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= htmlspecialchars($theme['bg']) ?> <?= htmlspecialchars($theme['text']) ?>">
                                                    <?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) ($event['type'] ?? 'evenement')))) ?>
                                                </div>
                                                <div class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= htmlspecialchars(rhSourceTheme((string) ($event['source_kind'] ?? 'record'))) ?>">
                                                    <?= htmlspecialchars((string) ($event['source_label'] ?? 'Historique')) ?>
                                                </div>
                                            </div>
                                            <h3 class="mt-3 text-lg font-semibold text-slate-900"><?= htmlspecialchars((string) ($event['title'] ?? 'Evenement RH')) ?></h3>
                                            <p class="mt-2 text-sm text-slate-600"><?= htmlspecialchars((string) (($event['description'] ?? '') !== '' ? $event['description'] : 'Detail non renseigne')) ?></p>
                                            <?php if (!empty($event['author_name']) || !empty($event['created_at'])): ?>
                                                <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                                                    <?php if (!empty($event['author_name'])): ?>
                                                        <span class="rounded-full bg-slate-100 px-3 py-1">Auteur <?= htmlspecialchars((string) $event['author_name']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($event['created_at'])): ?>
                                                        <span class="rounded-full bg-slate-100 px-3 py-1">Saisi <?= htmlspecialchars(rhFormatDateTime($event['created_at'])) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-sm font-semibold text-slate-500"><?= htmlspecialchars(rhFormatDate($event['date'] ?? null)) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.26em] text-cyan-600">Affectations</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Affectations et terrain</h2>
                    </div>
                    <div class="mt-6 space-y-4">
                        <?php if (empty($assignments)): ?>
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-6 text-sm text-slate-500">Aucune affectation operationnelle trouvee pour ce collaborateur.</div>
                        <?php else: ?>
                            <?php foreach ($assignments as $assignment): ?>
                                <div class="rounded-2xl border border-slate-100 bg-slate-50 px-5 py-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) (($assignment['service_label'] ?? '') !== '' ? $assignment['service_label'] : 'Service non renseigne')) ?></h3>
                                            <p class="mt-1 text-sm text-slate-600">
                                                <?= htmlspecialchars((string) (($assignment['chantier_title'] ?? '') !== '' ? $assignment['chantier_title'] : 'Chantier #' . ($assignment['chantier_id'] ?? 'N/A'))) ?>
                                            </p>
                                            <p class="mt-1 text-xs text-slate-500">
                                                <?= htmlspecialchars((string) (($assignment['task_designation'] ?? '') !== '' ? $assignment['task_designation'] : 'Ligne de debourse #' . ($assignment['ligne_debourse_id'] ?? 'N/A'))) ?>
                                            </p>
                                        </div>
                                        <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-600 shadow-sm"><?= htmlspecialchars(rhFormatDate($assignment['updated_at'] ?? $assignment['created_at'] ?? null)) ?></span>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                                        <?php if (!empty($assignment['chantier_code'])): ?>
                                            <span class="rounded-full bg-white px-3 py-1">Code <?= htmlspecialchars((string) $assignment['chantier_code']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($assignment['task_category'])): ?>
                                            <span class="rounded-full bg-white px-3 py-1"><?= htmlspecialchars((string) $assignment['task_category']) ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($assignment['updated_by'])): ?>
                                            <span class="rounded-full bg-white px-3 py-1">Maj <?= htmlspecialchars((string) $assignment['updated_by']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <div class="grid gap-6 border-t border-slate-100 px-6 py-6 xl:grid-cols-[1fr_1fr] xl:px-8 xl:py-8">
                <section id="audit-section" class="rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.26em] text-emerald-600">Audit dossier</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Journal des modifications</h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?= count($changeLog) ?> entree(s)</span>
                    </div>
                    <div class="mt-6 space-y-3">
                        <?php if (empty($changeLog)): ?>
                            <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-5 py-6 text-sm text-slate-500">Aucune modification journalisee pour le moment.</div>
                        <?php else: ?>
                            <?php foreach ($changeLog as $log): ?>
                                <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) $log['field_label']) ?></div>
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                <div class="rounded-xl bg-white px-3 py-3">
                                                    <div class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400">Ancienne valeur</div>
                                                    <div class="mt-1 text-sm text-slate-700"><?= htmlspecialchars((string) (($log['old_value'] ?? null) !== null && $log['old_value'] !== '' ? $log['old_value'] : 'Vide')) ?></div>
                                                </div>
                                                <div class="rounded-xl bg-white px-3 py-3">
                                                    <div class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400">Nouvelle valeur</div>
                                                    <div class="mt-1 text-sm text-slate-700"><?= htmlspecialchars((string) (($log['new_value'] ?? null) !== null && $log['new_value'] !== '' ? $log['new_value'] : 'Vide')) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap gap-2 text-xs text-slate-500 sm:max-w-[220px] sm:justify-end">
                                            <span class="rounded-full bg-white px-3 py-1">Auteur <?= htmlspecialchars((string) (($log['changed_by_name'] ?? '') !== '' ? $log['changed_by_name'] : 'Systeme')) ?></span>
                                            <span class="rounded-full bg-white px-3 py-1"><?= htmlspecialchars(rhFormatDateTime($log['changed_at'] ?? null)) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.26em] text-sky-600">Actions RH</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Edition et mouvements</h2>
                        </div>
                    </div>
                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        <a href="modifier_dossier_personnel_rh.php?id=<?= (int) $employee['id'] ?>" class="rounded-2xl border border-emerald-100 bg-emerald-50 px-5 py-5 transition hover:border-emerald-200 hover:bg-emerald-100">
                            <span class="block text-sm font-semibold text-emerald-800">Modifier le dossier</span>
                            <span class="mt-1 block text-sm text-emerald-700">Informations, documents, enfants et pieces.</span>
                        </a>
                        <a href="mutation_personnel_rh.php?id=<?= (int) $employee['id'] ?>" class="rounded-2xl border border-fuchsia-100 bg-fuchsia-50 px-5 py-5 transition hover:border-fuchsia-200 hover:bg-fuchsia-100">
                            <span class="block text-sm font-semibold text-fuchsia-800">Enregistrer une mutation</span>
                            <span class="mt-1 block text-sm text-fuchsia-700">Affectation, statut, site et historique RH.</span>
                        </a>
                    </div>
                </section>

                <section id="mutation-section" class="hidden rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)]">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.26em] text-fuchsia-600">Mouvement RH</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Mutation et affectation</h2>
                        </div>
                        <span class="rounded-full bg-fuchsia-50 px-4 py-2 text-xs font-semibold text-fuchsia-700">Action métier historisée</span>
                    </div>

                    <form method="post" class="mt-6 space-y-6">
                        <input type="hidden" name="form_action" value="apply_mutation">

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Date de prise d'effet</label>
                                <input type="date" name="mutation_effective_date" value="<?= htmlspecialchars((string) date('Y-m-d')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-fuchsia-400">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Libellé</label>
                                <input type="text" name="mutation_title" value="Mutation / affectation RH" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-fuchsia-400">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Service cible</label>
                                <select name="mutation_service_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-fuchsia-400">
                                    <option value="">Conserver le service actuel</option>
                                    <?php foreach ($filterOptions['services'] as $service): ?>
                                        <option value="<?= (int) $service['id'] ?>" <?= (string) ($employee['service_id'] ?? '') === (string) $service['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $service['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Fonction cible</label>
                                <select name="mutation_fonction_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-fuchsia-400">
                                    <option value="">Conserver la fonction actuelle</option>
                                    <?php foreach ($filterOptions['fonctions'] as $fonction): ?>
                                        <option value="<?= (int) $fonction['id'] ?>" <?= (string) ($employee['fonction_id'] ?? '') === (string) $fonction['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $fonction['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Lieu de travail</label>
                                <select name="mutation_site_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-fuchsia-400">
                                    <option value="">Conserver le lieu actuel</option>
                                    <?php foreach ($filterOptions['sites'] as $site): ?>
                                        <option value="<?= (int) $site['id'] ?>" <?= (string) ($employee['site_id'] ?? '') === (string) $site['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $site['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Statut</label>
                                <select name="mutation_status_code" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-fuchsia-400">
                                    <option value="">Conserver le statut actuel</option>
                                    <?php foreach ($filterOptions['statuts'] as $statut): ?>
                                        <option value="<?= (int) $statut['id'] ?>" <?= (string) ($employee['status_code'] ?? '') === (string) $statut['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $statut['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-slate-700">Date de prise de service / début sur le nouveau poste</label>
                                <input type="date" name="mutation_start_date" value="<?= htmlspecialchars((string) ($employee['start_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-fuchsia-400">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="mb-2 block text-sm font-medium text-slate-700">Motif ou commentaire</label>
                                <textarea name="mutation_reason" rows="4" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-fuchsia-400" placeholder="Ex: réaffectation sur un autre site, changement de poste, réorganisation de service..."></textarea>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <button type="submit" class="inline-flex items-center rounded-2xl bg-gradient-to-r from-fuchsia-600 to-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-fuchsia-900/20 transition hover:-translate-y-0.5 hover:from-fuchsia-700 hover:to-indigo-700"><i class="fas fa-people-arrows mr-2"></i>Enregistrer la mutation</button>
                        </div>
                    </form>
                </section>

                <section id="edit-form" class="hidden rounded-[28px] border border-slate-100 bg-white p-6 shadow-[0_14px_40px_rgba(15,23,42,0.05)] xl:col-span-2">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.26em] text-emerald-600">Edition RH</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Modifier le dossier et remplacer les pieces</h2>
                        </div>
                        <span class="rounded-full bg-slate-100 px-4 py-2 text-xs font-semibold text-slate-600">Edition directe</span>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="mt-6 space-y-8">
                        <input type="hidden" name="form_action" value="save_dossier">

                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-100 bg-slate-50/70 p-5">
                                <h3 class="text-lg font-semibold text-slate-900">Informations generales</h3>
                                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Matricule</label>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800">
                                            <span class="font-semibold"><?= htmlspecialchars((string) ($employee['matricule'] ?: 'En attente')) ?></span>
                                            <span class="mt-1 block text-xs text-slate-500">Genere automatiquement par la regle RH.</span>
                                        </div>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom complet</label>
                                        <input type="text" name="full_name" required value="<?= htmlspecialchars((string) ($employee['full_name'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Sexe</label>
                                        <select name="gender_code" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                            <option value="">Non renseigne</option>
                                            <?php foreach ($filterOptions['sexes'] as $sexe): ?>
                                                <option value="<?= (int) $sexe['id'] ?>" <?= (string) ($employee['gender_code'] ?? '') === (string) $sexe['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $sexe['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Date de naissance</label>
                                        <input type="date" name="birth_date" value="<?= htmlspecialchars((string) ($employee['birth_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Telephone</label>
                                        <input type="text" name="phone" value="<?= htmlspecialchars((string) ($employee['phone'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">E-mail</label>
                                        <input type="email" name="email" value="<?= htmlspecialchars((string) ($employee['email'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Situation matrimoniale</label>
                                        <input type="text" name="marital_status" value="<?= htmlspecialchars((string) ($employee['marital_status'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Site</label>
                                        <select name="site_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                            <option value="">Non renseigne</option>
                                            <?php foreach ($filterOptions['sites'] as $site): ?>
                                                <option value="<?= (int) $site['id'] ?>" <?= (string) ($employee['site_id'] ?? '') === (string) $site['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $site['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-[26px] border border-slate-100 bg-slate-50/70 p-5">
                                <h3 class="text-lg font-semibold text-slate-900">Informations administratives</h3>
                                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Service</label>
                                        <select name="service_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                            <option value="">Non renseigne</option>
                                            <?php foreach ($filterOptions['services'] as $service): ?>
                                                <option value="<?= (int) $service['id'] ?>" <?= (string) ($employee['service_id'] ?? '') === (string) $service['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $service['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Fonction</label>
                                        <select name="fonction_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                            <option value="">Non renseignee</option>
                                            <?php foreach ($filterOptions['fonctions'] as $fonction): ?>
                                                <option value="<?= (int) $fonction['id'] ?>" <?= (string) ($employee['fonction_id'] ?? '') === (string) $fonction['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $fonction['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Statut</label>
                                        <select name="status_code" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                            <option value="">Non renseigne</option>
                                            <?php foreach ($filterOptions['statuts'] as $statut): ?>
                                                <option value="<?= (int) $statut['id'] ?>" <?= (string) ($employee['status_code'] ?? '') === (string) $statut['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $statut['label']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">CNI</label>
                                        <input type="text" name="cni_number" value="<?= htmlspecialchars((string) ($employee['cni_number'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">CNPS</label>
                                        <input type="text" name="cnps_number" value="<?= htmlspecialchars((string) ($employee['cnps_number'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Duree contrat (mois)</label>
                                        <input type="number" min="0" name="contract_duration_months" value="<?= htmlspecialchars((string) ($employee['contract_duration_months'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Date de recrutement</label>
                                        <input type="date" name="hire_date" value="<?= htmlspecialchars((string) ($employee['hire_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Date de prise de service</label>
                                        <input type="date" name="start_date" value="<?= htmlspecialchars((string) ($employee['start_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Date de sortie</label>
                                        <input type="date" name="end_date" value="<?= htmlspecialchars((string) ($employee['end_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <div class="mb-2 flex items-center justify-between gap-3">
                                            <label class="block text-sm font-medium text-slate-700">Motif de sortie</label>
                                            <a href="parametrage_motifs_sortie_rh.php" class="text-xs font-semibold text-emerald-700 hover:underline">Parametrer</a>
                                        </div>
                                        <input type="text" id="exit_reason_search_dossier" placeholder="Rechercher un motif..." class="mb-3 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                        <select name="exit_reason_id" id="exit_reason_id_dossier" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                            <option value="">Non renseigne</option>
                                            <?php $selectedExitReason = $currentExitReasonId > 0 ? ($exitReasonsById[$currentExitReasonId] ?? null) : null; ?>
                                            <?php if ($selectedExitReason !== null && (int) ($selectedExitReason['is_active'] ?? 0) !== 1): ?>
                                                <option value="<?= (int) $currentExitReasonId ?>" selected>
                                                    <?= htmlspecialchars((string) ($selectedExitReason['label'] ?? 'Motif inactif')) ?> (inactif)
                                                </option>
                                            <?php endif; ?>
                                            <?php foreach ($exitReasonsActive as $reason): ?>
                                                <option value="<?= (int) $reason['id'] ?>" <?= (string) ($employee['exit_reason_id'] ?? '') === (string) $reason['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars((string) ($reason['label'] ?? '')) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="mt-2 text-xs text-slate-500">Tapez dans la recherche pour filtrer la liste.</div>
                                    </div>
                                </div>
                            </section>
                        </div>

                        <div class="grid gap-6 xl:grid-cols-2">
                            <section class="rounded-[26px] border border-slate-100 bg-slate-50/70 p-5">
                                <h3 class="text-lg font-semibold text-slate-900">Contacts et famille</h3>
                                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom du pere</label>
                                        <input type="text" name="father_name" value="<?= htmlspecialchars((string) ($employee['father_name'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Telephone du pere</label>
                                        <input type="text" name="father_phone" value="<?= htmlspecialchars((string) ($employee['father_phone'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom de la mere</label>
                                        <input type="text" name="mother_name" value="<?= htmlspecialchars((string) ($employee['mother_name'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Telephone de la mere</label>
                                        <input type="text" name="mother_phone" value="<?= htmlspecialchars((string) ($employee['mother_phone'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Contact urgence</label>
                                        <input type="text" name="emergency_contact_name" value="<?= htmlspecialchars((string) ($employee['emergency_contact_name'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Telephone urgence</label>
                                        <input type="text" name="emergency_contact_phone" value="<?= htmlspecialchars((string) ($employee['emergency_contact_phone'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">A des enfants</label>
                                        <select name="has_children" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                            <option value="">Non renseigne</option>
                                            <option value="1" <?= (string) ($employee['has_children'] ?? '') === '1' ? 'selected' : '' ?>>Oui</option>
                                            <option value="0" <?= (string) ($employee['has_children'] ?? '') === '0' ? 'selected' : '' ?>>Non</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Nombre d'enfants</label>
                                        <input type="number" min="0" name="children_count" value="<?= htmlspecialchars((string) ($childrenCount ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-emerald-400">
                                    </div>
                                </div>
                            </section>

                            <section class="rounded-[26px] border border-slate-100 bg-slate-50/70 p-5">
                                <h3 class="text-lg font-semibold text-slate-900">Upload et remplacement des pieces</h3>
                                <p class="mt-2 text-sm text-slate-500">Chaque remplacement archive l'ancienne piece et journalise l'auteur ainsi que l'ancienne valeur.</p>
                                <div class="mt-5 space-y-4">
                                    <?php foreach ($documentCards as $document): ?>
                                        <?php $documentUrl = rhResolveMediaUrl($document['path'] ?? null); ?>
                                        <div class="rounded-2xl border border-slate-100 bg-white px-4 py-4">
                                            <div class="flex items-center justify-between gap-3">
                                                <div>
                                                    <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($document['title']) ?></div>
                                                    <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars(rhHumanFileName($document['path'] ?? null)) ?></div>
                                                </div>
                                                <?php if ($documentUrl !== null): ?>
                                                    <a href="<?= htmlspecialchars($documentUrl) ?>" target="_blank" rel="noopener noreferrer" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">Voir</a>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-3">
                                                <input type="file" name="<?= htmlspecialchars($document['input_name']) ?>" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp" class="block w-full rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 file:mr-4 file:rounded-xl file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-slate-800">
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </section>
                        </div>

                        <div class="flex flex-wrap items-center justify-end gap-3">
                            <a href="liste_personnel_rh.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Annuler</a>
                            <button type="submit" class="rounded-2xl bg-slate-950 px-6 py-3 text-sm font-semibold text-white hover:bg-slate-800">Enregistrer le dossier RH</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        const searchInput = document.getElementById('exit_reason_search_dossier');
        const select = document.getElementById('exit_reason_id_dossier');

        if (!searchInput || !select) {
            return;
        }

        const normalize = (value) => (value || '').toString().toLowerCase();

        const filterOptions = () => {
            const query = normalize(searchInput.value).trim();
            const options = Array.from(select.options);

            options.forEach((option) => {
                if (option.value === '' || option.selected) {
                    option.hidden = false;
                    return;
                }

                if (query === '') {
                    option.hidden = false;
                    return;
                }

                option.hidden = normalize(option.text).indexOf(query) === -1;
            });
        };

        searchInput.addEventListener('input', filterOptions);
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
