<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/controllers/DocumentationController.php';

$pageTitle = 'Documentation';
$activeMenu = 'documentation';

$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$userId = (int)($_SESSION['user_id'] ?? 0);
$documentationBoot = (new \DocumentationController(\Database::getConnection()))->boot($userId, $permissions);
$manager = $documentationBoot['manager'];
$documentationUserContext = $documentationBoot['context'];

$canUpload = (bool)$documentationBoot['canUpload'];
$canEdit = (bool)$documentationBoot['canEdit'];
$canArchive = (bool)$documentationBoot['canArchive'];
$canDownload = (bool)$documentationBoot['canDownload'];
$canShare = !empty($permissions['document_share']) || $canEdit;
$documentationHomeUrl = rtrim(\AccessManager::getApplicationBasePath(), '/') . '/documentation/index.php';

$redirectToCurrentPage = static function () use ($documentationHomeUrl): void {
    $target = $documentationHomeUrl;
    $query = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
    if ($query !== '') {
        $target .= '?' . $query;
    }
    header('Location: ' . $target);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'create_folder') {
            if (!$canEdit) {
                throw new RuntimeException('Vous n\'avez pas le droit de créer des dossiers.');
            }
            $parentFolderId = trim((string)($_POST['parent_id'] ?? '')) === '' ? 0 : (int)$_POST['parent_id'];
            if ($parentFolderId > 0) {
                $manager->assertFolderAccess($parentFolderId, 'edit', $userId, $permissions);
            } elseif (empty($documentationUserContext['is_global_admin']) && empty($documentationUserContext['is_document_admin'])) {
                $targetServiceId = (int)($_POST['service_id'] ?? 0);
                $userServiceId = (int)($documentationUserContext['service_id'] ?? 0);
                if ($targetServiceId > 0 && $targetServiceId !== $userServiceId) {
                    throw new RuntimeException('Vous ne pouvez pas creer un dossier pour un autre service.');
                }
            }
            $manager->createFolder($_POST, $userId);
            $_SESSION['flash_success'] = 'Dossier créé avec succès.';
        } elseif ($action === 'rename_folder') {
            if (!$canEdit) {
                throw new RuntimeException('Vous n\'avez pas le droit de renommer des dossiers.');
            }
            $folder = $manager->assertFolderAccess((int)($_POST['folder_id'] ?? 0), 'edit', $userId, $permissions);
            $manager->renameFolder((int)($_POST['folder_id'] ?? 0), (string)($_POST['name'] ?? ''));
            $manager->logFolderAccess('edit', $folder, $userId);
            $_SESSION['flash_success'] = 'Dossier renommé.';
        } elseif ($action === 'rename_document') {
            if (!$canEdit) {
                throw new RuntimeException('Vous n\'avez pas le droit de renommer des documents.');
            }
            $document = $manager->assertDocumentAccess((int)($_POST['document_id'] ?? 0), 'edit', $userId, $permissions);
            $manager->renameDocument((int)($_POST['document_id'] ?? 0), (string)($_POST['title'] ?? ''));
            $manager->logDocumentAccess('edit', $document, $userId);
            $_SESSION['flash_success'] = 'Document renommé.';
        } elseif ($action === 'move_folder') {
            if (!$canEdit) {
                throw new RuntimeException('Vous n\'avez pas le droit de déplacer des dossiers.');
            }
            $manager->assertFolderAccess((int)($_POST['source_folder_id'] ?? $_POST['folder_id'] ?? 0), 'edit', $userId, $permissions);
            $manager->moveFolder((int)($_POST['source_folder_id'] ?? $_POST['folder_id'] ?? 0), trim((string)($_POST['parent_id'] ?? '')) === '' ? null : (int)$_POST['parent_id']);
            $_SESSION['flash_success'] = 'Dossier déplacé.';
        } elseif ($action === 'move_document') {
            if (!$canEdit) {
                throw new RuntimeException('Vous n\'avez pas le droit de déplacer des documents.');
            }
            $manager->assertDocumentAccess((int)($_POST['document_id'] ?? 0), 'edit', $userId, $permissions);
            $manager->moveDocument((int)($_POST['document_id'] ?? 0), trim((string)($_POST['folder_id'] ?? '')) === '' ? null : (int)$_POST['folder_id']);
            $_SESSION['flash_success'] = 'Document déplacé.';
        } elseif ($action === 'toggle_archive_document') {
            if (!$canArchive) {
                throw new RuntimeException('Vous n\'avez pas le droit d\'archiver des documents.');
            }
            $document = $manager->assertDocumentAccess((int)($_POST['document_id'] ?? 0), 'archive', $userId, $permissions);
            $manager->setDocumentArchived((int)($_POST['document_id'] ?? 0), !empty($_POST['archive']), $userId);
            $manager->logDocumentAccess('archive', $document, $userId, 'success', ['archive' => !empty($_POST['archive'])]);
            $_SESSION['flash_success'] = !empty($_POST['archive']) ? 'Document archivé.' : 'Document restauré.';
        } elseif ($action === 'toggle_archive_folder') {
            if (!$canArchive) {
                throw new RuntimeException('Vous n\'avez pas le droit d\'archiver des dossiers.');
            }
            $folder = $manager->assertFolderAccess((int)($_POST['folder_id'] ?? 0), 'archive', $userId, $permissions);
            $manager->setFolderArchived((int)($_POST['folder_id'] ?? 0), !empty($_POST['archive']), $userId);
            $manager->logFolderAccess('archive', $folder, $userId, 'success', ['archive' => !empty($_POST['archive'])]);
            $_SESSION['flash_success'] = !empty($_POST['archive']) ? 'Dossier archivé.' : 'Dossier restauré.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }

    $redirectToCurrentPage();
}

require_once dirname(__DIR__) . '/decaissement/includes/header.php';
require_once dirname(__DIR__) . '/decaissement/includes/navbar.php';

$spaceLabels = $manager->getSpaceLabels();
$directionOptions = $manager->getDirectionOptions();
$audienceOptions = $manager->getAudienceOptions();
$serviceOptions = $manager->listServiceOptions();
$chantierOptions = $manager->listChantiers(true);

$serviceLabels = [];
foreach ($serviceOptions as $serviceRow) {
    $serviceLabels[(int)($serviceRow['id_service'] ?? 0)] = (string)($serviceRow['lib_service'] ?? '');
}

$chantierLabels = [];
foreach ($chantierOptions as $chantierRow) {
    $chantierLabels[(int)($chantierRow['id'] ?? 0)] = trim((string)($chantierRow['code'] ?? '') . ' ' . (string)($chantierRow['titre'] ?? ''));
}

$internalShareRecipients = $manager->listInternalShareRecipients();
$archiveActorOptions = $internalShareRecipients;
$technicalExecutableExtensions = $manager->getTechnicalExecutableExtensions();
$shareableExecutableExtensions = $manager->getShareableExecutableExtensions();
$technicalShareBadgeLabel = 'Partage technique';
$documentationPreviewAssets = [
    'mammoth' => 'js/vendor/documentation/mammoth.browser.min.js',
    'xlsx' => 'js/vendor/documentation/xlsx.full.min.js',
    'pdfjs' => 'js/vendor/documentation/pdf.min.js',
    'pdfWorker' => 'js/vendor/documentation/pdf.worker.min.js',
];

$currentFilters = [
    'search' => trim((string)($_GET['search'] ?? '')),
    'space_type' => trim((string)($_GET['space_type'] ?? '')),
    'document_type' => trim((string)($_GET['document_type'] ?? '')),
    'include_archived' => !empty($_GET['archives']),
    'archive_scope' => trim((string)($_GET['archive_scope'] ?? 'all')),
    'archived_by' => (int)($_GET['archived_by'] ?? 0),
    'archived_from' => trim((string)($_GET['archived_from'] ?? '')),
    'archived_to' => trim((string)($_GET['archived_to'] ?? '')),
    'chantier_id' => (int)($_GET['chantier_id'] ?? 0),
    'service_id' => (int)($_GET['service_id'] ?? 0),
    'direction_code' => trim((string)($_GET['direction_code'] ?? '')),
    'folder_id' => (int)($_GET['folder_id'] ?? 0),
    'sort' => trim((string)($_GET['sort'] ?? 'date_desc')),
    'user_id' => $userId,
];

if (!in_array($currentFilters['archive_scope'], ['all', 'folders', 'documents'], true)) {
    $currentFilters['archive_scope'] = 'all';
}

$viewMode = trim((string)($_GET['view_mode'] ?? 'icons'));
if (!in_array($viewMode, ['icons', 'list', 'details'], true)) {
    $viewMode = 'icons';
}

$documentTypeLabels = [
    'document' => 'Document',
    'plan' => 'Plan / DWG',
    'image' => 'Image',
    'tableur' => 'Tableur',
    'presentation' => 'Présentation',
    'archive' => 'Archive ZIP',
    'texte' => 'Texte / CSV',
];

$viewModeLabels = [
    'icons' => 'Icônes',
    'list' => 'Liste',
    'details' => 'Détails',
];

$buildFilterLink = static function (array $base, array $overrides = []): string {
    $merged = array_merge($base, $overrides);
    $query = [];
    foreach ($merged as $key => $value) {
        if ($key === 'include_archived') {
            if ($value) {
                $query['archives'] = 1;
            }
            continue;
        }
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            continue;
        }
        $query[$key] = $value;
    }
    global $documentationHomeUrl;
    return $documentationHomeUrl . ($query ? '?' . http_build_query($query) : '');
};

$folderFilters = [
    'include_archived' => $currentFilters['include_archived'],
    'space_type' => $currentFilters['space_type'],
    'chantier_id' => $currentFilters['chantier_id'],
    'service_id' => $currentFilters['service_id'],
    'direction_code' => $currentFilters['direction_code'],
    'user_id' => $userId,
];

$allVisibleFolders = $manager->listFolders($folderFilters, $permissions);
$foldersById = [];
$childrenByParent = [];
foreach ($allVisibleFolders as $folderRow) {
    $folderId = (int)($folderRow['id'] ?? 0);
    $foldersById[$folderId] = $folderRow;
    $parentId = (int)($folderRow['parent_id'] ?? 0);
    $childrenByParent[$parentId][] = $folderRow;
}

$decorateFolderOptions = static function (int $parentId, int $depth, array $childrenByParent, array &$result) use (&$decorateFolderOptions): void {
    foreach ($childrenByParent[$parentId] ?? [] as $folderRow) {
        $folderRow['_depth'] = $depth;
        $result[] = $folderRow;
        $decorateFolderOptions((int)($folderRow['id'] ?? 0), $depth + 1, $childrenByParent, $result);
    }
};

$folderOptionsFlat = [];
$decorateFolderOptions(0, 0, $childrenByParent, $folderOptionsFlat);

$matchesArchiveDashboardFilters = static function (array $row, array $filters): bool {
    if (!empty($filters['archived_by']) && (int)($row['archived_by'] ?? 0) !== (int)$filters['archived_by']) {
        return false;
    }

    $archivedAt = trim((string)($row['archived_at'] ?? ''));
    $archivedDate = $archivedAt !== '' ? substr($archivedAt, 0, 10) : '';
    if ($filters['archived_from'] !== '' && ($archivedDate === '' || $archivedDate < $filters['archived_from'])) {
        return false;
    }
    if ($filters['archived_to'] !== '' && ($archivedDate === '' || $archivedDate > $filters['archived_to'])) {
        return false;
    }

    return true;
};

$formatArchiveDate = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return 'Non renseigné';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return 'Non renseigné';
    }
    return date('d/m/Y H:i', $timestamp);
};

$resolveArchiveActorLabel = static function (array $row): string {
    $name = trim((string)($row['archived_by_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }
    $archivedBy = (int)($row['archived_by'] ?? 0);
    if ($archivedBy > 0) {
        return 'Utilisateur #' . $archivedBy;
    }
    return 'Non renseigné';
};

$currentFolderId = (int)$currentFilters['folder_id'];
$archiveMode = !empty($currentFilters['include_archived']);
$currentFolder = $currentFolderId > 0 ? ($foldersById[$currentFolderId] ?? $manager->getFolderById($currentFolderId)) : null;
if ($currentFolder && !$manager->canAccessFolder($currentFolder, 'view', $userId, $permissions)) {
    $manager->logFolderAccess('view', $currentFolder, $userId, 'denied', ['source' => 'documentation_page']);
    $currentFolder = null;
    $currentFolderId = 0;
    $currentFilters['folder_id'] = 0;
}
$manager->logFolderAccess('view', $currentFolder ?: ['id' => 0, 'name' => 'Racine documentaire'], $userId, 'success', ['source' => 'documentation_page']);
$currentParentFolderId = (int)($currentFolder['parent_id'] ?? 0);
$defaultTargetServiceId = (int)($currentFolder['service_id'] ?? 0);
if ($defaultTargetServiceId <= 0) {
    $defaultTargetServiceId = (int)($currentFilters['service_id'] ?: ($documentationUserContext['service_id'] ?? 0));
}
$currentFolderChildren = array_values(array_filter(
    $childrenByParent[$currentFolderId] ?? [],
    static function (array $folderRow) use ($archiveMode, $currentFilters, $matchesArchiveDashboardFilters): bool {
        if ($archiveMode && $currentFilters['archive_scope'] === 'documents') {
            return false;
        }
        if (!$archiveMode && !empty($folderRow['is_archived'])) {
            return false;
        }
        if ($archiveMode && empty($folderRow['is_archived'])) {
            return false;
        }
        return !$archiveMode || $matchesArchiveDashboardFilters($folderRow, $currentFilters);
    }
));

$allFilteredDocuments = $manager->listDocuments($currentFilters, $permissions);
$documents = array_values(array_filter(
    $allFilteredDocuments,
    static function (array $documentRow) use ($currentFolderId, $archiveMode, $currentFilters, $matchesArchiveDashboardFilters): bool {
        if ($archiveMode && $currentFilters['archive_scope'] === 'folders') {
            return false;
        }
        if ($archiveMode && empty($documentRow['is_archived'])) {
            return false;
        }
        if (!$archiveMode && !empty($documentRow['is_archived'])) {
            return false;
        }
        $documentFolderId = isset($documentRow['folder_id']) ? (int)$documentRow['folder_id'] : 0;
        if ($currentFolderId <= 0) {
            return $documentFolderId <= 0 && (!$archiveMode || $matchesArchiveDashboardFilters($documentRow, $currentFilters));
        }
        return $documentFolderId === $currentFolderId && (!$archiveMode || $matchesArchiveDashboardFilters($documentRow, $currentFilters));
    }
));

$sortDocuments = static function (array &$rows, string $sort): void {
    usort($rows, static function (array $left, array $right) use ($sort): int {
        $leftName = mb_strtolower((string)($left['title'] ?? ''));
        $rightName = mb_strtolower((string)($right['title'] ?? ''));
        $leftSize = (int)($left['file_size'] ?? 0);
        $rightSize = (int)($right['file_size'] ?? 0);
        $leftType = mb_strtolower((string)($left['extension'] ?? $left['document_type'] ?? ''));
        $rightType = mb_strtolower((string)($right['extension'] ?? $right['document_type'] ?? ''));
        $leftDate = strtotime((string)($left['updated_at'] ?? $left['uploaded_at'] ?? '')) ?: 0;
        $rightDate = strtotime((string)($right['updated_at'] ?? $right['uploaded_at'] ?? '')) ?: 0;

        return match ($sort) {
            'date_asc' => $leftDate <=> $rightDate,
            'name_asc' => $leftName <=> $rightName,
            'name_desc' => $rightName <=> $leftName,
            'size_desc' => $rightSize <=> $leftSize,
            'size_asc' => $leftSize <=> $rightSize,
            'type_asc' => $leftType <=> $rightType,
            'type_desc' => $rightType <=> $leftType,
            default => $rightDate <=> $leftDate,
        };
    });
};
$sortDocuments($documents, $currentFilters['sort']);

$stats = $manager->getDashboardStats($permissions);
$spaceSummaries = [];
foreach ($spaceLabels as $spaceKey => $spaceLabel) {
    $spaceSummaries[$spaceKey] = [
        'label' => $spaceLabel,
        'documents' => 0,
    ];
}
foreach ($allFilteredDocuments as $documentRow) {
    $spaceKey = (string)($documentRow['folder_space_type'] ?? $currentFolder['space_type'] ?? 'general');
    if (!isset($spaceSummaries[$spaceKey])) {
        $spaceSummaries[$spaceKey] = ['label' => ucfirst($spaceKey), 'documents' => 0];
    }
    $spaceSummaries[$spaceKey]['documents']++;
}

$resolveDocumentVisual = static function (array $document): array {
    $extension = strtolower((string)($document['extension'] ?? ''));
    $documentType = strtolower((string)($document['document_type'] ?? 'document'));

    $map = [
        'pdf' => ['icon' => 'fa-file-pdf', 'wrap' => 'bg-rose-100 text-rose-700', 'label' => 'PDF'],
        'doc' => ['icon' => 'fa-file-word', 'wrap' => 'bg-blue-100 text-blue-700', 'label' => 'Word'],
        'docx' => ['icon' => 'fa-file-word', 'wrap' => 'bg-blue-100 text-blue-700', 'label' => 'Word'],
        'xls' => ['icon' => 'fa-file-excel', 'wrap' => 'bg-emerald-100 text-emerald-700', 'label' => 'Excel'],
        'xlsx' => ['icon' => 'fa-file-excel', 'wrap' => 'bg-emerald-100 text-emerald-700', 'label' => 'Excel'],
        'ppt' => ['icon' => 'fa-file-powerpoint', 'wrap' => 'bg-orange-100 text-orange-700', 'label' => 'PowerPoint'],
        'pptx' => ['icon' => 'fa-file-powerpoint', 'wrap' => 'bg-orange-100 text-orange-700', 'label' => 'PowerPoint'],
        'jpg' => ['icon' => 'fa-file-image', 'wrap' => 'bg-fuchsia-100 text-fuchsia-700', 'label' => 'Image'],
        'jpeg' => ['icon' => 'fa-file-image', 'wrap' => 'bg-fuchsia-100 text-fuchsia-700', 'label' => 'Image'],
        'png' => ['icon' => 'fa-file-image', 'wrap' => 'bg-fuchsia-100 text-fuchsia-700', 'label' => 'Image'],
        'webp' => ['icon' => 'fa-file-image', 'wrap' => 'bg-fuchsia-100 text-fuchsia-700', 'label' => 'Image'],
        'gif' => ['icon' => 'fa-file-image', 'wrap' => 'bg-fuchsia-100 text-fuchsia-700', 'label' => 'Image'],
        'bmp' => ['icon' => 'fa-file-image', 'wrap' => 'bg-fuchsia-100 text-fuchsia-700', 'label' => 'Image'],
        'svg' => ['icon' => 'fa-file-image', 'wrap' => 'bg-fuchsia-100 text-fuchsia-700', 'label' => 'Image'],
        'mp4' => ['icon' => 'fa-file-video', 'wrap' => 'bg-cyan-100 text-cyan-700', 'label' => 'Vidéo'],
        'webm' => ['icon' => 'fa-file-video', 'wrap' => 'bg-cyan-100 text-cyan-700', 'label' => 'Vidéo'],
        'mov' => ['icon' => 'fa-file-video', 'wrap' => 'bg-cyan-100 text-cyan-700', 'label' => 'Vidéo'],
        'avi' => ['icon' => 'fa-file-video', 'wrap' => 'bg-cyan-100 text-cyan-700', 'label' => 'Vidéo'],
        'mkv' => ['icon' => 'fa-file-video', 'wrap' => 'bg-cyan-100 text-cyan-700', 'label' => 'Vidéo'],
        'm4v' => ['icon' => 'fa-file-video', 'wrap' => 'bg-cyan-100 text-cyan-700', 'label' => 'Vidéo'],
        'ogv' => ['icon' => 'fa-file-video', 'wrap' => 'bg-cyan-100 text-cyan-700', 'label' => 'Vidéo'],
        'dwg' => ['icon' => 'fa-drafting-compass', 'wrap' => 'bg-amber-100 text-amber-700', 'label' => 'DWG'],
        'dxf' => ['icon' => 'fa-drafting-compass', 'wrap' => 'bg-amber-100 text-amber-700', 'label' => 'DXF'],
        'zip' => ['icon' => 'fa-file-zipper', 'wrap' => 'bg-slate-200 text-slate-700', 'label' => 'Archive'],
        'rar' => ['icon' => 'fa-file-zipper', 'wrap' => 'bg-slate-200 text-slate-700', 'label' => 'Archive'],
        '7z' => ['icon' => 'fa-file-zipper', 'wrap' => 'bg-slate-200 text-slate-700', 'label' => 'Archive'],
        'txt' => ['icon' => 'fa-file-lines', 'wrap' => 'bg-slate-100 text-slate-700', 'label' => 'Texte'],
        'csv' => ['icon' => 'fa-file-csv', 'wrap' => 'bg-emerald-100 text-emerald-700', 'label' => 'CSV'],
        'exe' => ['icon' => 'fa-gears', 'wrap' => 'bg-slate-900 text-white', 'label' => 'Exécutable'],
        'msi' => ['icon' => 'fa-gears', 'wrap' => 'bg-slate-900 text-white', 'label' => 'Exécutable'],
        'bat' => ['icon' => 'fa-terminal', 'wrap' => 'bg-slate-900 text-white', 'label' => 'Script'],
        'cmd' => ['icon' => 'fa-terminal', 'wrap' => 'bg-slate-900 text-white', 'label' => 'Script'],
        'ps1' => ['icon' => 'fa-terminal', 'wrap' => 'bg-slate-900 text-white', 'label' => 'Script'],
    ];

    if (isset($map[$extension])) {
        return $map[$extension];
    }
    if ($documentType === 'plan') {
        return ['icon' => 'fa-drafting-compass', 'wrap' => 'bg-amber-100 text-amber-700', 'label' => 'Plan'];
    }

    return ['icon' => 'fa-file-lines', 'wrap' => 'bg-slate-100 text-slate-700', 'label' => ucfirst($documentType !== '' ? $documentType : 'Document')];
};

$resolveDocumentPreview = static function (array $document): array {
    $versionId = (int)($document['version_id'] ?? 0);
    $extension = strtolower((string)($document['extension'] ?? ''));
    if ($versionId <= 0) {
        return ['kind' => '', 'url' => ''];
    }

    $inlineUrl = 'documentation_download.php?version_id=' . $versionId . '&inline=1';
    if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'], true)) {
        return ['kind' => 'image', 'url' => $inlineUrl];
    }
    if (in_array($extension, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v', 'ogv'], true)) {
        return ['kind' => 'video', 'url' => $inlineUrl];
    }
    if ($extension === 'pdf') {
        return ['kind' => 'pdf', 'url' => $inlineUrl];
    }
    if (in_array($extension, ['txt', 'csv', 'log', 'json', 'xml'], true)) {
        return ['kind' => 'text', 'url' => $inlineUrl];
    }
    if ($extension === 'docx') {
        return ['kind' => 'word', 'url' => $inlineUrl];
    }
    if (in_array($extension, ['xls', 'xlsx'], true)) {
        return ['kind' => 'excel', 'url' => $inlineUrl];
    }
    if ($extension === 'zip') {
        return ['kind' => 'archive_zip', 'url' => 'documentation_archive_api.php?version_id=' . $versionId . '&action=list'];
    }
    if (in_array($extension, ['rar', '7z'], true)) {
        return ['kind' => 'archive_download', 'url' => ''];
    }
    if (in_array($extension, ['exe', 'msi', 'bat', 'cmd', 'ps1'], true)) {
        return ['kind' => 'executable', 'url' => ''];
    }

    return ['kind' => '', 'url' => ''];
};

$archiveEntries = [];
if ($archiveMode) {
    foreach ($currentFolderChildren as $folderChild) {
        $archiveEntries[] = [
            'kind' => 'folder',
            'id' => (int)($folderChild['id'] ?? 0),
            'name' => (string)($folderChild['name'] ?? 'Dossier'),
            'description' => (string)($folderChild['description'] ?? ''),
            'location' => $currentFolder ? (string)($currentFolder['name'] ?? 'Dossier courant') : 'Racine documentaire',
            'type_label' => 'Dossier',
            'archived_at' => (string)($folderChild['archived_at'] ?? ''),
            'archived_by_label' => $resolveArchiveActorLabel($folderChild),
            'row' => $folderChild,
        ];
    }
    foreach ($documents as $document) {
        $visual = $resolveDocumentVisual($document);
        $archiveEntries[] = [
            'kind' => 'document',
            'id' => (int)($document['id'] ?? 0),
            'name' => (string)($document['title'] ?? 'Document'),
            'description' => (string)($document['original_name'] ?? ''),
            'location' => (string)($document['folder_name'] ?? 'Référentiel'),
            'type_label' => (string)($visual['label'] ?? 'Document'),
            'archived_at' => (string)($document['archived_at'] ?? ''),
            'archived_by_label' => $resolveArchiveActorLabel($document),
            'row' => $document,
        ];
    }

    usort($archiveEntries, static function (array $left, array $right): int {
        $leftDate = strtotime((string)($left['archived_at'] ?? '')) ?: 0;
        $rightDate = strtotime((string)($right['archived_at'] ?? '')) ?: 0;
        if ($leftDate === $rightDate) {
            return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
        }
        return $rightDate <=> $leftDate;
    });
}

$archiveSummary = [
    'folders' => count($currentFolderChildren),
    'documents' => count($documents),
    'entries' => count($archiveEntries),
];

$renderFolderBranch = function (int $parentId = 0) use (&$renderFolderBranch, $childrenByParent, $currentFilters, $spaceLabels, $serviceLabels, $directionOptions, $chantierLabels, $buildFilterLink): void {
    foreach ($childrenByParent[$parentId] ?? [] as $folderRow) {
        $folderId = (int)($folderRow['id'] ?? 0);
        $isSelected = $folderId === (int)($currentFilters['folder_id'] ?? 0);
        $link = $buildFilterLink($currentFilters, ['folder_id' => $folderId]);

        $meta = [];
        if (!empty($folderRow['chantier_id']) && isset($chantierLabels[(int)$folderRow['chantier_id']])) {
            $meta[] = $chantierLabels[(int)$folderRow['chantier_id']];
        }
        if (!empty($folderRow['service_id']) && isset($serviceLabels[(int)$folderRow['service_id']])) {
            $meta[] = $serviceLabels[(int)$folderRow['service_id']];
        }
        if (!empty($folderRow['direction_code']) && isset($directionOptions[(string)$folderRow['direction_code']])) {
            $meta[] = $directionOptions[(string)$folderRow['direction_code']];
        }
?>
        <div class="rounded-2xl <?= $isSelected ? 'bg-emerald-50/80' : 'bg-transparent' ?>">
            <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>" class="block rounded-2xl border <?= $isSelected ? 'border-emerald-200 bg-emerald-50' : 'border-transparent hover:border-slate-200 hover:bg-slate-50' ?> px-3 py-3 transition-colors">
                <div class="flex items-start gap-3">
                    <div class="mt-0.5 flex h-8 w-8 items-center justify-center rounded-xl <?= $isSelected ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700' ?> shadow-sm">
                        <i class="fas fa-folder text-xs"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)($folderRow['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($folderRow['is_archived'])): ?>
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">Archive</span>
                            <?php endif; ?>
                        </div>
                        <div class="mt-1 text-[11px] uppercase tracking-wide text-slate-400"><?= htmlspecialchars((string)($spaceLabels[$folderRow['space_type']] ?? ucfirst((string)$folderRow['space_type'])), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($meta): ?>
                            <div class="mt-1 text-xs text-slate-500 break-words"><?= htmlspecialchars(implode(' | ', $meta), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </a>
            <div class="mt-2 ml-4 border-l border-dashed border-slate-200 pl-3 space-y-2">
                <?php $renderFolderBranch($folderId); ?>
            </div>
        </div>
<?php
    }
};
?>

<div class="min-h-screen bg-slate-100">
    <div class="mx-auto max-w-[1800px] px-4 py-5">
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 shadow-sm">
                <i class="fas fa-circle-check mr-2"></i><?= htmlspecialchars((string)$_SESSION['flash_success'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 shadow-sm">
                <i class="fas fa-triangle-exclamation mr-2"></i><?= htmlspecialchars((string)$_SESSION['flash_error'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>

        <div class="rounded-[28px] border border-slate-200 bg-white shadow-[0_18px_60px_rgba(15,23,42,0.07)]">
            <div class="border-b border-slate-200 px-5 py-4">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <div>
                        <div class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-400">Documentation</div>
                        <h1 class="mt-1 text-2xl font-bold text-slate-900">Drive documentaire</h1>
                        <p class="mt-1 text-sm text-slate-500">Navigation rapide, actions concentrées en haut.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-600">
                            <span class="font-semibold text-slate-900"><?= number_format((int)$stats['documents_active'], 0, ',', ' ') ?></span> actifs
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-600">
                            <span class="font-semibold text-slate-900"><?= number_format((int)$stats['documents_archived'], 0, ',', ' ') ?></span> archives
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-600">
                            <span class="font-semibold text-slate-900"><?= htmlspecialchars($manager->formatBytes((int)$stats['total_size']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div id="documentationLayout" class="grid grid-cols-1 gap-0 xl:grid-cols-[300px_minmax(0,1fr)]">
                <aside class="border-b border-slate-200 bg-slate-50/80 p-4 xl:border-b-0 xl:border-r">
                    <div class="space-y-4">
                        <div class="rounded-2xl border border-slate-200 bg-white p-3">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Espaces</div>
                                <a href="<?= htmlspecialchars($documentationHomeUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-xs font-semibold text-emerald-700 hover:text-emerald-900">Tout</a>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($spaceSummaries as $spaceKey => $spaceSummary): ?>
                                    <?php $spaceLink = $documentationHomeUrl . '?' . http_build_query(array_filter([
                                        'space_type' => $spaceKey,
                                        'archives' => !empty($currentFilters['include_archived']) ? 1 : null,
                                        'view_mode' => $viewMode,
                                    ], static fn($value) => $value !== null && $value !== '')); ?>
                                    <a href="<?= htmlspecialchars($spaceLink, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center gap-2 rounded-full border px-3 py-2 text-xs font-semibold <?= $currentFilters['space_type'] === $spaceKey ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-slate-50 text-slate-600 hover:bg-slate-100' ?>">
                                        <span><?= htmlspecialchars((string)$spaceSummary['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="rounded-full bg-white px-2 py-0.5 text-[10px] text-slate-500"><?= number_format((int)$spaceSummary['documents'], 0, ',', ' ') ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-white p-3">
                            <div class="mb-3 text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Dossiers</div>
                            <div class="max-h-[46vh] space-y-2 overflow-y-auto pr-1">
                                <?php if (!empty($allVisibleFolders)): ?>
                                    <?php $renderFolderBranch(0); ?>
                                <?php else: ?>
                                    <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-5 text-sm text-slate-500">Aucun dossier.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </aside>

                <main class="border-b border-slate-200 p-4 xl:border-b-0 xl:border-r">
                    <div class="space-y-4">
                        <form method="get" class="rounded-2xl border border-slate-200 bg-white px-4 py-3">
                            <input type="hidden" name="folder_id" value="<?= $currentFolderId ?>">
                            <input type="hidden" name="chantier_id" value="<?= (int)$currentFilters['chantier_id'] ?>">
                            <input type="hidden" name="service_id" value="<?= (int)$currentFilters['service_id'] ?>">
                            <input type="hidden" name="direction_code" value="<?= htmlspecialchars((string)$currentFilters['direction_code'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="view_mode" value="<?= htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="flex flex-col gap-3 2xl:flex-row 2xl:items-center 2xl:justify-between">
                                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                    <a href="<?= htmlspecialchars($buildFilterLink($currentFilters, ['folder_id' => 0]), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 <?= $currentFolderId === 0 ? 'opacity-50 pointer-events-none' : '' ?>" title="Racine">
                                        <i class="fas fa-house"></i>
                                    </a>
                                    <a href="<?= htmlspecialchars($buildFilterLink($currentFilters, ['folder_id' => $currentParentFolderId]), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 <?= !$currentFolder ? 'opacity-50 pointer-events-none' : '' ?>" title="Parent">
                                        <i class="fas fa-arrow-up"></i>
                                    </a>
                                    <div class="relative min-w-[260px] flex-1">
                                        <i class="fas fa-magnifying-glass pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                        <input type="text" name="search" value="<?= htmlspecialchars((string)$currentFilters['search'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="<?= $archiveMode ? 'Retrouver une archive par nom, fichier ou mot-clé' : 'Retrouver un document par nom, fichier ou mot-clé' ?>">
                                    </div>
                                    <select name="document_type" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                        <option value="">Tous types</option>
                                        <?php foreach ($documentTypeLabels as $typeKey => $typeLabel): ?>
                                            <option value="<?= htmlspecialchars((string)$typeKey, ENT_QUOTES, 'UTF-8') ?>" <?= $currentFilters['document_type'] === $typeKey ? 'selected' : '' ?>><?= htmlspecialchars((string)$typeLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="space_type" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                        <option value="">Tous espaces</option>
                                        <?php foreach ($spaceLabels as $spaceKey => $spaceLabel): ?>
                                            <option value="<?= htmlspecialchars((string)$spaceKey, ENT_QUOTES, 'UTF-8') ?>" <?= $currentFilters['space_type'] === $spaceKey ? 'selected' : '' ?>><?= htmlspecialchars((string)$spaceLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="sort" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                        <option value="date_desc" <?= $currentFilters['sort'] === 'date_desc' ? 'selected' : '' ?>>Tri: Date récente</option>
                                        <option value="date_asc" <?= $currentFilters['sort'] === 'date_asc' ? 'selected' : '' ?>>Tri: Date ancienne</option>
                                        <option value="name_asc" <?= $currentFilters['sort'] === 'name_asc' ? 'selected' : '' ?>>Tri: Nom A-Z</option>
                                        <option value="name_desc" <?= $currentFilters['sort'] === 'name_desc' ? 'selected' : '' ?>>Tri: Nom Z-A</option>
                                        <option value="size_desc" <?= $currentFilters['sort'] === 'size_desc' ? 'selected' : '' ?>>Tri: Taille décroissante</option>
                                        <option value="size_asc" <?= $currentFilters['sort'] === 'size_asc' ? 'selected' : '' ?>>Tri: Taille croissante</option>
                                        <option value="type_asc" <?= $currentFilters['sort'] === 'type_asc' ? 'selected' : '' ?>>Tri: Type A-Z</option>
                                        <option value="type_desc" <?= $currentFilters['sort'] === 'type_desc' ? 'selected' : '' ?>>Tri: Type Z-A</option>
                                    </select>
                                    <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
                                        <input type="checkbox" name="archives" value="1" <?= !empty($currentFilters['include_archived']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400">
                                        Archives uniquement
                                    </label>
                                    <?php if ($archiveMode): ?>
                                        <select name="archive_scope" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300">
                                            <option value="all" <?= $currentFilters['archive_scope'] === 'all' ? 'selected' : '' ?>>Toutes les archives</option>
                                            <option value="documents" <?= $currentFilters['archive_scope'] === 'documents' ? 'selected' : '' ?>>Documents archivés</option>
                                            <option value="folders" <?= $currentFilters['archive_scope'] === 'folders' ? 'selected' : '' ?>>Dossiers archivés</option>
                                        </select>
                                        <select name="archived_by" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300">
                                            <option value="0">Tous les archivistes</option>
                                            <?php foreach ($archiveActorOptions as $actorOption): ?>
                                                <option value="<?= (int)$actorOption['id'] ?>" <?= (int)$currentFilters['archived_by'] === (int)$actorOption['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$actorOption['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input type="date" name="archived_from" value="<?= htmlspecialchars((string)$currentFilters['archived_from'], ENT_QUOTES, 'UTF-8') ?>" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300" title="Archivé à partir du">
                                        <input type="date" name="archived_to" value="<?= htmlspecialchars((string)$currentFilters['archived_to'], ENT_QUOTES, 'UTF-8') ?>" class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300" title="Archivé jusqu'au">
                                    <?php endif; ?>
                                    <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                                        <i class="fas fa-magnifying-glass mr-2"></i>Rechercher
                                    </button>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <?php if ($canEdit): ?>
                                        <button type="button" id="top-create-folder-button" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                                            <i class="fas fa-folder-plus mr-2"></i>Dossier
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($canUpload): ?>
                                        <button type="button" id="top-upload-button" class="inline-flex items-center rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">
                                            <i class="fas fa-cloud-arrow-up mr-2"></i>Importer
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" id="top-filters-button" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                                        <i class="fas fa-sliders mr-2"></i>Filtres
                                    </button>
                                    <?php if ($archiveMode): ?>
                                        <div class="inline-flex items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-semibold uppercase tracking-[0.18em] text-amber-900">
                                            <i class="fas fa-box-archive"></i>Tableau des archives
                                        </div>
                                    <?php else: ?>
                                        <div class="inline-flex items-center gap-1 rounded-2xl border border-slate-200 bg-white p-1 text-xs font-semibold text-slate-600">
                                            <?php foreach ($viewModeLabels as $modeKey => $modeLabel): ?>
                                                <a href="<?= htmlspecialchars($buildFilterLink($currentFilters, ['view_mode' => $modeKey]), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center rounded-xl px-3 py-2 <?= $viewMode === $modeKey ? 'bg-slate-950 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100' ?>">
                                                    <i class="fas <?= $modeKey === 'icons' ? 'fa-table-cells-large' : ($modeKey === 'list' ? 'fa-list' : 'fa-table') ?> mr-2"></i><?= htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8') ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>

                        <div class="rounded-2xl border border-slate-200 bg-white">
                            <div class="border-b border-slate-200 px-4 py-3">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <div class="text-sm font-semibold text-slate-900"><?= $archiveMode ? 'Archives' : 'Contenu' ?></div>
                                        <div class="mt-1 text-xs text-slate-500"><?= $archiveMode ? 'Consultez, filtrez, restaurez ou supprimez définitivement les éléments archivés.' : 'Double-clic pour ouvrir. Clic droit pour agir rapidement.' ?></div>
                                    </div>
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-700">
                                        <input type="checkbox" id="select-all-items" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400">
                                        Tout sélectionner
                                    </label>
                                </div>
                                <div id="bulk-selection-toolbar" class="mt-3 hidden flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                                    <div id="bulk-selection-count" class="text-sm font-semibold text-slate-900">0 élément sélectionné</div>
                                    <div class="ml-auto flex flex-wrap gap-2">
                                        <?php if ($canDownload): ?>
                                            <button type="button" id="bulk-download-button" class="inline-flex items-center rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Télécharger la sélection</button>
                                        <?php endif; ?>
                                        <?php if ($canArchive && !$archiveMode): ?>
                                            <button type="button" id="bulk-archive-button" class="inline-flex items-center rounded-xl border border-amber-200 bg-white px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-50">Archiver la sélection</button>
                                        <?php endif; ?>
                                        <?php if ($canArchive && $archiveMode): ?>
                                            <button type="button" id="bulk-restore-button" class="inline-flex items-center rounded-xl border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50">Restaurer la sélection</button>
                                            <button type="button" id="bulk-delete-button" class="inline-flex items-center rounded-xl border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Supprimer définitivement</button>
                                        <?php endif; ?>
                                        <div class="inline-flex items-center rounded-xl border border-cyan-200 bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-800">Glissez la sélection sur un dossier pour la déplacer</div>
                                    </div>
                                </div>
                            </div>
                            <div id="explorer-context-zone" class="p-4 min-h-[55vh]">
                                <div id="explorer-surface" class="min-h-full" data-current-folder-id="<?= $currentFolderId ?>">
                                    <?php if (!empty($currentFolderChildren) || !empty($documents)): ?>
                                        <?php if ($archiveMode): ?>
                                            <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                                                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4">
                                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-amber-700">Archives trouvées</div>
                                                    <div class="mt-2 text-2xl font-bold text-amber-950"><?= number_format((int)$archiveSummary['entries'], 0, ',', ' ') ?></div>
                                                </div>
                                                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Dossiers archivés</div>
                                                    <div class="mt-2 text-2xl font-bold text-slate-900"><?= number_format((int)$archiveSummary['folders'], 0, ',', ' ') ?></div>
                                                </div>
                                                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                                    <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Documents archivés</div>
                                                    <div class="mt-2 text-2xl font-bold text-slate-900"><?= number_format((int)$archiveSummary['documents'], 0, ',', ' ') ?></div>
                                                </div>
                                            </div>
                                            <div class="overflow-hidden rounded-[26px] border border-slate-200">
                                                <div class="grid grid-cols-[48px_minmax(0,2.1fr)_minmax(0,1.2fr)_160px_160px_170px_190px] gap-0 bg-slate-100 px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">
                                                    <div></div>
                                                    <div>Élément</div>
                                                    <div>Emplacement</div>
                                                    <div>Type</div>
                                                    <div>Date d'archivage</div>
                                                    <div>Archivé par</div>
                                                    <div>Actions</div>
                                                </div>
                                                <div class="divide-y divide-slate-200 bg-white">
                                                    <?php foreach ($archiveEntries as $archiveEntry): ?>
                                                        <?php $isArchiveFolder = $archiveEntry['kind'] === 'folder'; ?>
                                                        <?php $archiveRow = $archiveEntry['row']; ?>
                                                        <?php if ($isArchiveFolder): ?>
                                                            <div class="js-explorer-item grid grid-cols-[48px_minmax(0,2.1fr)_minmax(0,1.2fr)_160px_160px_170px_190px] items-center gap-0 px-4 py-4 hover:bg-amber-50/40 transition-colors cursor-default" tabindex="0" draggable="true" data-item-type="folder" data-item-id="<?= (int)$archiveRow['id'] ?>" data-item-name="<?= htmlspecialchars((string)$archiveEntry['name'], ENT_QUOTES, 'UTF-8') ?>" data-item-file-name="<?= htmlspecialchars((string)$archiveEntry['name'], ENT_QUOTES, 'UTF-8') ?>" data-open-url="<?= htmlspecialchars($buildFilterLink($currentFilters, ['folder_id' => (int)$archiveRow['id']]), ENT_QUOTES, 'UTF-8') ?>" data-parent-id="<?= (int)($archiveRow['parent_id'] ?? 0) ?>" data-service-id="<?= (int)($archiveRow['service_id'] ?? 0) ?>" data-item-archived="1">
                                                                <div class="pr-3"><input type="checkbox" class="js-item-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400" onclick="event.stopPropagation();"></div>
                                                                <div class="min-w-0 pr-4">
                                                                    <div class="flex items-center gap-3">
                                                                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 shadow-sm"><i class="fas fa-folder text-sm"></i></div>
                                                                        <div class="min-w-0">
                                                                            <div class="flex flex-wrap items-center gap-2">
                                                                                <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)$archiveEntry['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">Archive</span>
                                                                            </div>
                                                                            <div class="mt-1 truncate text-xs text-slate-500"><?= htmlspecialchars((string)($archiveEntry['description'] !== '' ? $archiveEntry['description'] : 'Dossier archivé'), ENT_QUOTES, 'UTF-8') ?></div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="truncate pr-4 text-xs text-slate-500"><?= htmlspecialchars((string)$archiveEntry['location'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="text-xs font-semibold text-slate-600">Dossier</div>
                                                                <div class="text-xs text-slate-500"><?= htmlspecialchars($formatArchiveDate((string)$archiveEntry['archived_at']), ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="truncate pr-4 text-xs text-slate-500"><?= htmlspecialchars((string)$archiveEntry['archived_by_label'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="flex flex-wrap gap-2">
                                                                    <button type="button" class="js-archive-entry-restore inline-flex items-center rounded-xl border border-emerald-200 bg-white px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 transition-colors">Restaurer</button>
                                                                    <button type="button" class="js-archive-entry-delete inline-flex items-center rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 transition-colors">Supprimer</button>
                                                                </div>
                                                            </div>
                                                        <?php else: ?>
                                                            <?php $archiveVisual = $resolveDocumentVisual($archiveRow); ?>
                                                            <?php $archivePreview = $resolveDocumentPreview($archiveRow); ?>
                                                            <?php $archiveVersionId = (int)($archiveRow['version_id'] ?? 0); ?>
                                                            <div class="js-explorer-item grid grid-cols-[48px_minmax(0,2.1fr)_minmax(0,1.2fr)_160px_160px_170px_190px] items-center gap-0 px-4 py-4 hover:bg-slate-50 transition-colors cursor-default" tabindex="0" draggable="true" data-item-type="document" data-item-id="<?= (int)$archiveRow['id'] ?>" data-item-name="<?= htmlspecialchars((string)$archiveEntry['name'], ENT_QUOTES, 'UTF-8') ?>" data-item-file-name="<?= htmlspecialchars((string)($archiveRow['original_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-open-url="<?= $archiveVersionId > 0 ? htmlspecialchars('documentation_download.php?version_id=' . $archiveVersionId, ENT_QUOTES, 'UTF-8') : '' ?>" data-item-inline-url="<?= $archiveVersionId > 0 ? htmlspecialchars('documentation_download.php?version_id=' . $archiveVersionId . '&inline=1', ENT_QUOTES, 'UTF-8') : '' ?>" data-folder-id="<?= (int)($archiveRow['folder_id'] ?? 0) ?>" data-service-id="<?= (int)($archiveRow['service_id'] ?? 0) ?>" data-item-icon="<?= htmlspecialchars((string)$archiveVisual['icon'], ENT_QUOTES, 'UTF-8') ?>" data-item-icon-wrap="<?= htmlspecialchars((string)$archiveVisual['wrap'], ENT_QUOTES, 'UTF-8') ?>" data-item-label="<?= htmlspecialchars((string)$archiveVisual['label'], ENT_QUOTES, 'UTF-8') ?>" data-item-archived="1" data-item-preview-kind="<?= htmlspecialchars((string)$archivePreview['kind'], ENT_QUOTES, 'UTF-8') ?>" data-item-preview-url="<?= htmlspecialchars((string)$archivePreview['url'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <div class="pr-3"><input type="checkbox" class="js-item-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400" onclick="event.stopPropagation();"></div>
                                                                <div class="min-w-0 pr-4">
                                                                    <div class="flex items-center gap-3">
                                                                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl <?= htmlspecialchars((string)$archiveVisual['wrap'], ENT_QUOTES, 'UTF-8') ?> shadow-sm"><i class="fas <?= htmlspecialchars((string)$archiveVisual['icon'], ENT_QUOTES, 'UTF-8') ?> text-sm"></i></div>
                                                                        <div class="min-w-0">
                                                                            <div class="flex flex-wrap items-center gap-2">
                                                                                <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)$archiveEntry['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">Archive</span>
                                                                            </div>
                                                                            <div class="mt-1 truncate text-xs text-slate-500"><?= htmlspecialchars((string)($archiveEntry['description'] !== '' ? $archiveEntry['description'] : 'Fichier archivé'), ENT_QUOTES, 'UTF-8') ?></div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="truncate pr-4 text-xs text-slate-500"><?= htmlspecialchars((string)$archiveEntry['location'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="text-xs font-semibold text-slate-600"><?= htmlspecialchars((string)$archiveEntry['type_label'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="text-xs text-slate-500"><?= htmlspecialchars($formatArchiveDate((string)$archiveEntry['archived_at']), ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="truncate pr-4 text-xs text-slate-500"><?= htmlspecialchars((string)$archiveEntry['archived_by_label'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="flex flex-wrap gap-2">
                                                                    <?php if ($archiveVersionId > 0): ?>
                                                                        <button type="button" class="js-preview-trigger inline-flex items-center rounded-xl bg-slate-950 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition-colors"><i class="fas fa-eye mr-1.5"></i>Prévisualiser</button>
                                                                    <?php endif; ?>
                                                                    <button type="button" class="js-archive-entry-restore inline-flex items-center rounded-xl border border-emerald-200 bg-white px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 transition-colors">Restaurer</button>
                                                                    <button type="button" class="js-archive-entry-delete inline-flex items-center rounded-xl border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 transition-colors">Supprimer</button>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php elseif ($viewMode === 'icons'): ?>
                                            <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                                                <?php foreach ($currentFolderChildren as $folderChild): ?>
                                                    <div class="js-explorer-item group relative rounded-[24px] border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-emerald-200 hover:shadow-lg cursor-default" tabindex="0" draggable="true" data-item-type="folder" data-item-id="<?= (int)$folderChild['id'] ?>" data-item-name="<?= htmlspecialchars((string)$folderChild['name'], ENT_QUOTES, 'UTF-8') ?>" data-item-file-name="<?= htmlspecialchars((string)$folderChild['name'], ENT_QUOTES, 'UTF-8') ?>" data-open-url="<?= htmlspecialchars($buildFilterLink($currentFilters, ['folder_id' => (int)$folderChild['id']]), ENT_QUOTES, 'UTF-8') ?>" data-parent-id="<?= (int)($folderChild['parent_id'] ?? 0) ?>" data-service-id="<?= (int)($folderChild['service_id'] ?? 0) ?>" data-item-archived="<?= !empty($folderChild['is_archived']) ? '1' : '0' ?>">
                                                        <label class="absolute right-3 top-3 inline-flex h-6 w-6 items-center justify-center rounded-lg bg-white/90 shadow-sm">
                                                            <input type="checkbox" class="js-item-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400">
                                                        </label>
                                                        <div class="flex h-16 w-16 items-center justify-center rounded-[22px] bg-amber-100 text-amber-700 shadow-sm">
                                                            <i class="fas fa-folder text-2xl"></i>
                                                        </div>
                                                        <div class="mt-4 text-sm font-semibold text-slate-900 break-words"><?= htmlspecialchars((string)$folderChild['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                        <div class="mt-1 text-xs text-slate-500 break-words">Dossier<?= !empty($folderChild['is_archived']) ? ' • Archive' : '' ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php foreach ($documents as $document): ?>
                                                    <?php $documentType = (string)($document['document_type'] ?? 'document'); ?>
                                                    <?php $visual = $resolveDocumentVisual($document); ?>
                                                    <?php $preview = $resolveDocumentPreview($document); ?>
                                                    <?php $versionId = (int)($document['version_id'] ?? 0); ?>
                                                    <div class="js-explorer-item group relative rounded-[24px] border border-slate-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-lg cursor-default" tabindex="0" draggable="true" data-item-type="document" data-item-id="<?= (int)$document['id'] ?>" data-item-name="<?= htmlspecialchars((string)($document['title'] ?? 'Document'), ENT_QUOTES, 'UTF-8') ?>" data-item-file-name="<?= htmlspecialchars((string)($document['original_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-open-url="<?= $versionId > 0 ? htmlspecialchars('documentation_download.php?version_id=' . $versionId, ENT_QUOTES, 'UTF-8') : '' ?>" data-item-inline-url="<?= $versionId > 0 ? htmlspecialchars('documentation_download.php?version_id=' . $versionId . '&inline=1', ENT_QUOTES, 'UTF-8') : '' ?>" data-folder-id="<?= (int)($document['folder_id'] ?? 0) ?>" data-service-id="<?= (int)($document['service_id'] ?? 0) ?>" data-item-icon="<?= htmlspecialchars((string)$visual['icon'], ENT_QUOTES, 'UTF-8') ?>" data-item-icon-wrap="<?= htmlspecialchars((string)$visual['wrap'], ENT_QUOTES, 'UTF-8') ?>" data-item-label="<?= htmlspecialchars((string)$visual['label'], ENT_QUOTES, 'UTF-8') ?>" data-item-archived="<?= !empty($document['is_archived']) ? '1' : '0' ?>" data-item-preview-kind="<?= htmlspecialchars((string)$preview['kind'], ENT_QUOTES, 'UTF-8') ?>" data-item-preview-url="<?= htmlspecialchars((string)$preview['url'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <label class="absolute right-3 top-3 inline-flex h-6 w-6 items-center justify-center rounded-lg bg-white/90 shadow-sm">
                                                            <input type="checkbox" class="js-item-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400">
                                                        </label>
                                                        <?php if ($preview['kind'] === 'image'): ?>
                                                            <div class="h-28 overflow-hidden rounded-[22px] border border-slate-200 bg-slate-100 shadow-sm">
                                                                <img src="<?= htmlspecialchars((string)$preview['url'], ENT_QUOTES, 'UTF-8') ?>" alt="Aperçu" class="h-full w-full object-cover">
                                                            </div>
                                                        <?php elseif ($preview['kind'] === 'video'): ?>
                                                            <div class="relative h-28 overflow-hidden rounded-[22px] border border-slate-200 bg-slate-950 shadow-sm">
                                                                <video src="<?= htmlspecialchars((string)$preview['url'], ENT_QUOTES, 'UTF-8') ?>" muted preload="metadata" playsinline class="h-full w-full object-cover"></video>
                                                                <div class="pointer-events-none absolute inset-0 flex items-center justify-center bg-slate-950/20">
                                                                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-white/90 text-slate-900 shadow-sm"><i class="fas fa-play text-sm"></i></span>
                                                                </div>
                                                            </div>
                                                        <?php elseif ($preview['kind'] === 'pdf'): ?>
                                                            <div class="flex h-28 flex-col items-center justify-center rounded-[22px] border border-slate-200 bg-white shadow-sm">
                                                                <i class="fas fa-file-pdf text-3xl text-rose-600"></i>
                                                                <div class="mt-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">PDF securise</div>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="flex h-16 w-16 items-center justify-center rounded-[22px] <?= htmlspecialchars((string)$visual['wrap'], ENT_QUOTES, 'UTF-8') ?> shadow-sm">
                                                                <i class="fas <?= htmlspecialchars((string)$visual['icon'], ENT_QUOTES, 'UTF-8') ?> text-2xl"></i>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div class="mt-4 text-sm font-semibold text-slate-900 break-words"><?= htmlspecialchars((string)($document['title'] ?? 'Document'), ENT_QUOTES, 'UTF-8') ?></div>
                                                        <?php if (in_array(strtolower((string)($document['extension'] ?? '')), $shareableExecutableExtensions, true)): ?>
                                                            <div class="mt-2"><span class="inline-flex items-center rounded-full bg-slate-900 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-white"><?= htmlspecialchars($technicalShareBadgeLabel, ENT_QUOTES, 'UTF-8') ?></span></div>
                                                        <?php endif; ?>
                                                        <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string)$visual['label'], ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars($manager->formatBytes((int)($document['file_size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($viewMode === 'list'): ?>
                                            <div class="overflow-hidden rounded-[26px] border border-slate-200 bg-white divide-y divide-slate-200">
                                                <?php foreach ($currentFolderChildren as $folderChild): ?>
                                                    <div class="js-explorer-item flex items-center gap-4 px-4 py-3 hover:bg-emerald-50/40 cursor-default" tabindex="0" draggable="true" data-item-type="folder" data-item-id="<?= (int)$folderChild['id'] ?>" data-item-name="<?= htmlspecialchars((string)$folderChild['name'], ENT_QUOTES, 'UTF-8') ?>" data-item-file-name="<?= htmlspecialchars((string)$folderChild['name'], ENT_QUOTES, 'UTF-8') ?>" data-open-url="<?= htmlspecialchars($buildFilterLink($currentFilters, ['folder_id' => (int)$folderChild['id']]), ENT_QUOTES, 'UTF-8') ?>" data-parent-id="<?= (int)($folderChild['parent_id'] ?? 0) ?>" data-service-id="<?= (int)($folderChild['service_id'] ?? 0) ?>" data-item-archived="<?= !empty($folderChild['is_archived']) ? '1' : '0' ?>">
                                                        <input type="checkbox" class="js-item-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400" onclick="event.stopPropagation();">
                                                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-100 text-amber-700"><i class="fas fa-folder"></i></div>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)$folderChild['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="truncate text-xs text-slate-500"><?= htmlspecialchars((string)($folderChild['description'] ?? 'Dossier'), ENT_QUOTES, 'UTF-8') ?><?= !empty($folderChild['is_archived']) ? ' • Archive' : '' ?></div>
                                                        </div>
                                                        <div class="text-xs font-semibold text-emerald-700">Ouvrir</div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php foreach ($documents as $document): ?>
                                                    <?php $visual = $resolveDocumentVisual($document); ?>
                                                    <?php $preview = $resolveDocumentPreview($document); ?>
                                                    <?php $versionId = (int)($document['version_id'] ?? 0); ?>
                                                    <div class="js-explorer-item flex items-center gap-4 px-4 py-3 hover:bg-slate-50 cursor-default" tabindex="0" draggable="true" data-item-type="document" data-item-id="<?= (int)$document['id'] ?>" data-item-name="<?= htmlspecialchars((string)($document['title'] ?? 'Document'), ENT_QUOTES, 'UTF-8') ?>" data-item-file-name="<?= htmlspecialchars((string)($document['original_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-open-url="<?= $versionId > 0 ? htmlspecialchars('documentation_download.php?version_id=' . $versionId, ENT_QUOTES, 'UTF-8') : '' ?>" data-item-inline-url="<?= $versionId > 0 ? htmlspecialchars('documentation_download.php?version_id=' . $versionId . '&inline=1', ENT_QUOTES, 'UTF-8') : '' ?>" data-folder-id="<?= (int)($document['folder_id'] ?? 0) ?>" data-service-id="<?= (int)($document['service_id'] ?? 0) ?>" data-item-icon="<?= htmlspecialchars((string)$visual['icon'], ENT_QUOTES, 'UTF-8') ?>" data-item-icon-wrap="<?= htmlspecialchars((string)$visual['wrap'], ENT_QUOTES, 'UTF-8') ?>" data-item-label="<?= htmlspecialchars((string)$visual['label'], ENT_QUOTES, 'UTF-8') ?>" data-item-archived="<?= !empty($document['is_archived']) ? '1' : '0' ?>" data-item-preview-kind="<?= htmlspecialchars((string)$preview['kind'], ENT_QUOTES, 'UTF-8') ?>" data-item-preview-url="<?= htmlspecialchars((string)$preview['url'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="checkbox" class="js-item-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400" onclick="event.stopPropagation();">
                                                        <?php if ($preview['kind'] === 'video'): ?>
                                                            <div class="relative h-10 w-14 overflow-hidden rounded-2xl border border-slate-200 bg-slate-950 shadow-sm">
                                                                <video src="<?= htmlspecialchars((string)$preview['url'], ENT_QUOTES, 'UTF-8') ?>" muted preload="metadata" playsinline class="h-full w-full object-cover"></video>
                                                                <div class="pointer-events-none absolute inset-0 flex items-center justify-center bg-slate-950/20"><i class="fas fa-play text-[10px] text-white"></i></div>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="flex h-10 w-10 items-center justify-center rounded-2xl <?= htmlspecialchars((string)$visual['wrap'], ENT_QUOTES, 'UTF-8') ?>"><i class="fas <?= htmlspecialchars((string)$visual['icon'], ENT_QUOTES, 'UTF-8') ?>"></i></div>
                                                        <?php endif; ?>
                                                        <div class="min-w-0 flex-1">
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)($document['title'] ?? 'Document'), ENT_QUOTES, 'UTF-8') ?></div>
                                                                <?php if (in_array(strtolower((string)($document['extension'] ?? '')), $shareableExecutableExtensions, true)): ?>
                                                                    <span class="inline-flex items-center rounded-full bg-slate-900 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-white"><?= htmlspecialchars($technicalShareBadgeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="truncate text-xs text-slate-500"><?= htmlspecialchars((string)$visual['label'], ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars((string)($document['original_name'] ?? 'Fichier'), ENT_QUOTES, 'UTF-8') ?></div>
                                                        </div>
                                                        <div class="text-xs text-slate-500"><?= htmlspecialchars($manager->formatBytes((int)($document['file_size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="overflow-hidden rounded-[26px] border border-slate-200">
                                                <div class="grid grid-cols-[48px_minmax(0,2.4fr)_minmax(0,1.2fr)_150px_150px_170px] gap-0 bg-slate-100 px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-500">
                                                    <div></div>
                                                    <div>Nom</div>
                                                    <div>Emplacement</div>
                                                    <div>Type</div>
                                                    <div>Taille</div>
                                                    <div>Actions</div>
                                                </div>
                                                <div class="divide-y divide-slate-200 bg-white">
                                                    <?php foreach ($currentFolderChildren as $folderChild): ?>
                                                        <div class="js-explorer-item grid grid-cols-[48px_minmax(0,2.4fr)_minmax(0,1.2fr)_150px_150px_170px] items-center gap-0 px-4 py-4 hover:bg-emerald-50/40 transition-colors cursor-default" tabindex="0" draggable="true" data-item-type="folder" data-item-id="<?= (int)$folderChild['id'] ?>" data-item-name="<?= htmlspecialchars((string)$folderChild['name'], ENT_QUOTES, 'UTF-8') ?>" data-item-file-name="<?= htmlspecialchars((string)$folderChild['name'], ENT_QUOTES, 'UTF-8') ?>" data-open-url="<?= htmlspecialchars($buildFilterLink($currentFilters, ['folder_id' => (int)$folderChild['id']]), ENT_QUOTES, 'UTF-8') ?>" data-parent-id="<?= (int)($folderChild['parent_id'] ?? 0) ?>" data-service-id="<?= (int)($folderChild['service_id'] ?? 0) ?>" data-item-archived="<?= !empty($folderChild['is_archived']) ? '1' : '0' ?>">
                                                            <div class="pr-3"><input type="checkbox" class="js-item-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400" onclick="event.stopPropagation();"></div>
                                                            <div class="min-w-0 pr-4">
                                                                <div class="flex items-center gap-3">
                                                                    <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 shadow-sm">
                                                                        <i class="fas fa-folder text-sm"></i>
                                                                    </div>
                                                                    <div class="min-w-0">
                                                                        <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)$folderChild['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                        <div class="mt-1 truncate text-xs text-slate-500"><?= htmlspecialchars((string)($folderChild['description'] ?? 'Dossier'), ENT_QUOTES, 'UTF-8') ?></div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="truncate pr-4 text-xs text-slate-500">Dossier courant</div>
                                                            <div class="text-xs font-semibold text-slate-600">Dossier<?= !empty($folderChild['is_archived']) ? ' • Archive' : '' ?></div>
                                                            <div class="text-xs text-slate-400">—</div>
                                                            <div class="text-xs text-emerald-700 font-semibold">Ouvrir</div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php foreach ($documents as $document): ?>
                                                        <?php $documentId = (int)($document['id'] ?? 0); ?>
                                                        <?php $versionId = (int)($document['version_id'] ?? 0); ?>
                                                        <?php $documentType = (string)($document['document_type'] ?? 'document'); ?>
                                                        <?php $visual = $resolveDocumentVisual($document); ?>
                                                        <?php $preview = $resolveDocumentPreview($document); ?>
                                                        <div class="js-explorer-item grid grid-cols-[48px_minmax(0,2.4fr)_minmax(0,1.2fr)_150px_150px_170px] items-center gap-0 px-4 py-4 hover:bg-slate-50 transition-colors cursor-default" tabindex="0" draggable="true" data-item-type="document" data-item-id="<?= $documentId ?>" data-item-name="<?= htmlspecialchars((string)($document['title'] ?? 'Document'), ENT_QUOTES, 'UTF-8') ?>" data-item-file-name="<?= htmlspecialchars((string)($document['original_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-open-url="<?= $versionId > 0 ? htmlspecialchars('documentation_download.php?version_id=' . $versionId, ENT_QUOTES, 'UTF-8') : '' ?>" data-item-inline-url="<?= $versionId > 0 ? htmlspecialchars('documentation_download.php?version_id=' . $versionId . '&inline=1', ENT_QUOTES, 'UTF-8') : '' ?>" data-folder-id="<?= (int)($document['folder_id'] ?? 0) ?>" data-service-id="<?= (int)($document['service_id'] ?? 0) ?>" data-item-icon="<?= htmlspecialchars((string)$visual['icon'], ENT_QUOTES, 'UTF-8') ?>" data-item-icon-wrap="<?= htmlspecialchars((string)$visual['wrap'], ENT_QUOTES, 'UTF-8') ?>" data-item-label="<?= htmlspecialchars((string)$visual['label'], ENT_QUOTES, 'UTF-8') ?>" data-item-archived="<?= !empty($document['is_archived']) ? '1' : '0' ?>" data-item-preview-kind="<?= htmlspecialchars((string)$preview['kind'], ENT_QUOTES, 'UTF-8') ?>" data-item-preview-url="<?= htmlspecialchars((string)$preview['url'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <div class="pr-3"><input type="checkbox" class="js-item-checkbox h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400" onclick="event.stopPropagation();"></div>
                                                            <div class="min-w-0 pr-4">
                                                                <div class="flex items-center gap-3">
                                                                    <?php if ($preview['kind'] === 'video'): ?>
                                                                        <div class="relative h-10 w-16 overflow-hidden rounded-2xl border border-slate-200 bg-slate-950 shadow-sm">
                                                                            <video src="<?= htmlspecialchars((string)$preview['url'], ENT_QUOTES, 'UTF-8') ?>" muted preload="metadata" playsinline class="h-full w-full object-cover"></video>
                                                                            <div class="pointer-events-none absolute inset-0 flex items-center justify-center bg-slate-950/20"><i class="fas fa-play text-[10px] text-white"></i></div>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl <?= htmlspecialchars((string)$visual['wrap'], ENT_QUOTES, 'UTF-8') ?> shadow-sm">
                                                                            <i class="fas <?= htmlspecialchars((string)$visual['icon'], ENT_QUOTES, 'UTF-8') ?> text-sm"></i>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                    <div class="min-w-0">
                                                                        <div class="flex flex-wrap items-center gap-2">
                                                                            <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars((string)($document['title'] ?? 'Document'), ENT_QUOTES, 'UTF-8') ?></div>
                                                                            <?php if (in_array(strtolower((string)($document['extension'] ?? '')), $shareableExecutableExtensions, true)): ?>
                                                                                <span class="inline-flex items-center rounded-full bg-slate-900 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.16em] text-white"><?= htmlspecialchars($technicalShareBadgeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($document['is_archived'])): ?>
                                                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">Archive</span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                        <div class="mt-1 truncate text-xs text-slate-500"><?= htmlspecialchars((string)$visual['label'], ENT_QUOTES, 'UTF-8') ?> • <?= htmlspecialchars((string)($document['original_name'] ?? 'Fichier'), ENT_QUOTES, 'UTF-8') ?></div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            <div class="truncate pr-4 text-xs text-slate-500"><?= htmlspecialchars((string)($document['folder_name'] ?? 'Référentiel'), ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-xs font-semibold text-slate-600"><?= htmlspecialchars((string)$visual['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="text-xs text-slate-500"><?= htmlspecialchars($manager->formatBytes((int)($document['file_size'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></div>
                                                            <div class="flex flex-wrap gap-2">
                                                                <?php if ($versionId > 0): ?>
                                                                    <button type="button" class="js-preview-trigger inline-flex items-center rounded-xl bg-slate-950 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800 transition-colors">
                                                                        <i class="fas fa-eye mr-1.5"></i>Prévisualiser
                                                                    </button>
                                                                <?php endif; ?>
                                                                <?php if ($canArchive): ?>
                                                                    <form method="post" class="js-inline-archive-form" data-archive-label="<?= !empty($document['is_archived']) ? 'restauration' : 'archivage' ?>">
                                                                        <input type="hidden" name="action" value="toggle_archive_document">
                                                                        <input type="hidden" name="document_id" value="<?= $documentId ?>">
                                                                        <input type="hidden" name="archive" value="<?= !empty($document['is_archived']) ? '0' : '1' ?>">
                                                                        <button type="submit" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
                                                                            <i class="fas <?= !empty($document['is_archived']) ? 'fa-box-open' : 'fa-box-archive' ?> mr-1.5"></i><?= !empty($document['is_archived']) ? 'Restaurer' : 'Archiver' ?>
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="rounded-[28px] border border-dashed border-slate-200 bg-slate-50 px-6 py-12 text-center">
                                            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-[24px] bg-white text-slate-400 shadow-sm">
                                                <i class="fas fa-folder-open text-2xl"></i>
                                            </div>
                                            <h3 class="mt-4 text-lg font-bold text-slate-900"><?= $archiveMode ? 'Aucune archive dans ce contexte' : 'Aucun document sur ce filtre' ?></h3>
                                            <p class="mt-2 text-sm text-slate-500"><?= $archiveMode ? 'Désactivez le mode archives ou archivez des éléments pour les retrouver ici.' : 'Créez un premier dossier, importez un document, ou élargissez vos filtres pour afficher le contenu disponible.' ?></p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>

                <aside id="rightPanel" class="hidden bg-slate-50/70 p-4 xl:border-l xl:border-slate-200">
                    <div class="space-y-4">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Panneau</div>
                                <button type="button" id="right-panel-close" class="inline-flex h-8 w-8 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50">
                                    <i class="fas fa-xmark"></i>
                                </button>
                            </div>
                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Sélection</div>
                            <div id="selection-empty-state" class="mt-3 text-sm text-slate-500">Aucune sélection.</div>
                            <div id="selection-panel" class="mt-3 hidden space-y-3">
                                <div class="flex items-center gap-3">
                                    <div id="selection-icon" class="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100 text-slate-700">
                                        <i class="fas fa-file"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <div id="selection-name" class="truncate text-sm font-semibold text-slate-900"></div>
                                        <div id="selection-type" class="text-xs text-slate-500"></div>
                                    </div>
                                </div>
                                <div id="selection-preview" class="hidden overflow-hidden rounded-2xl border border-slate-200 bg-slate-50"></div>
                                <div class="grid grid-cols-1 gap-2 text-xs text-slate-600">
                                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                                        <div class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Action</div>
                                        <div id="selection-open-label" class="mt-1 font-semibold text-slate-800">Prévisualiser</div>
                                    </div>
                                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                                        <div class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Identifiant</div>
                                        <div id="selection-id" class="mt-1 font-semibold text-slate-800">-</div>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 gap-2">
                                    <?php if ($canDownload): ?>
                                        <a id="selection-download-button" href="#" class="hidden inline-flex w-full items-center justify-center rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                                            <i class="fas fa-download mr-2"></i>Télécharger
                                        </a>
                                    <?php endif; ?>
                                    <button type="button" id="selection-open-button" class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Ouvrir</button>
                                    <?php if ($canShare): ?>
                                        <button type="button" id="selection-share-button" class="hidden inline-flex w-full items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">Partager</button>
                                    <?php endif; ?>
                                </div>
                                <div id="selection-history-block" class="hidden space-y-3">
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                                        <div class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Liens partagés</div>
                                        <div id="selection-share-history" class="mt-2 space-y-2 text-xs text-slate-600"></div>
                                    </div>
                                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-3 py-3">
                                        <div class="text-[10px] uppercase tracking-[0.18em] text-slate-400">Modifications via lien</div>
                                        <div id="selection-activity-history" class="mt-2 space-y-2 text-xs text-slate-600"></div>
                                    </div>
                                </div>
                            </div>
                            <div id="multi-selection-panel" class="mt-3 hidden space-y-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Sélection multiple</div>
                                <div id="multi-selection-title" class="text-sm font-semibold text-slate-900">0 élément sélectionné</div>
                                <p class="text-sm text-slate-600">Pour une sélection mixte, seules les actions communes de déplacement par glisser-déposer, suppression et téléchargement restent disponibles.</p>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </div>

    <div id="explorerContextMenu" class="hidden fixed z-[120] min-w-[220px] rounded-2xl border border-slate-200 bg-white p-2 shadow-[0_20px_60px_rgba(15,23,42,0.22)]">
        <button type="button" data-menu-action="open" class="js-menu-item js-menu-item-file js-menu-item-folder flex w-full items-center rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
            <i class="fas fa-folder-open mr-3 text-slate-500"></i><span class="js-menu-open-label">Ouvrir</span>
        </button>
        <?php if ($canShare): ?>
            <button type="button" data-menu-action="share" class="js-menu-item js-menu-item-file js-menu-item-folder hidden w-full items-center rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <i class="fas fa-link mr-3 text-slate-500"></i>Partager
            </button>
            <button type="button" data-menu-action="access-rights" class="js-menu-item js-menu-item-file js-menu-item-folder hidden w-full items-center rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <i class="fas fa-user-shield mr-3 text-slate-500"></i>Droits d&apos;acc&egrave;s
            </button>
        <?php endif; ?>
        <div class="js-menu-separator js-menu-item-file js-menu-item-folder my-2 border-t border-slate-200"></div>
        <?php if ($canUpload): ?>
            <button type="button" data-menu-action="import" class="js-menu-item js-menu-item-empty js-menu-item-folder flex w-full items-center rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <i class="fas fa-cloud-arrow-up mr-3 text-slate-500"></i>Importer ici
            </button>
        <?php endif; ?>
        <?php if ($canEdit): ?>
            <button type="button" data-menu-action="create-folder" class="js-menu-item js-menu-item-empty js-menu-item-folder flex w-full items-center rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <i class="fas fa-folder-plus mr-3 text-slate-500"></i>Cr&eacute;er un dossier ici
            </button>
            <div class="js-menu-separator js-menu-item-file js-menu-item-folder my-2 border-t border-slate-200"></div>
            <button type="button" data-menu-action="rename" class="js-menu-item js-menu-item-file js-menu-item-folder flex w-full items-center rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <i class="fas fa-i-cursor mr-3 text-slate-500"></i>Renommer
            </button>
            <button type="button" data-menu-action="move" class="js-menu-item js-menu-item-file js-menu-item-folder flex w-full items-center rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <i class="fas fa-arrows-up-down-left-right mr-3 text-slate-500"></i>Déplacer
            </button>
        <?php endif; ?>
        <?php if ($canArchive): ?>
            <button type="button" data-menu-action="archive" class="js-menu-item js-menu-item-file js-menu-item-folder hidden w-full items-center rounded-xl px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100">
                <i class="fas fa-box-archive mr-3 text-slate-500"></i><span class="js-menu-archive-label">Archiver</span>
            </button>
            <button type="button" data-menu-action="delete" class="js-menu-item js-menu-item-file js-menu-item-folder hidden w-full items-center rounded-xl px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-50">
                <i class="fas fa-trash mr-3 text-rose-500"></i>Supprimer définitivement
            </button>
        <?php endif; ?>
    </div>

    <form method="post" id="dragMoveForm" class="hidden">
        <input type="hidden" name="action" value="move_document">
        <input type="hidden" name="source_folder_id" value="">
        <input type="hidden" name="document_id" value="">
        <input type="hidden" name="folder_id" value="">
    </form>

    <form method="post" id="contextArchiveForm" class="hidden">
        <input type="hidden" name="action" value="toggle_archive_document">
        <input type="hidden" name="folder_id" value="">
        <input type="hidden" name="document_id" value="">
        <input type="hidden" name="archive" value="1">
    </form>

    <form method="post" id="bulkDownloadForm" action="documentation_bulk_download.php" class="hidden"></form>

    <div id="filtersModal" class="hidden fixed inset-0 z-[130] overflow-y-auto bg-slate-950/40 px-4 py-6">
        <div class="mx-auto mt-12 max-w-xl rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_24px_80px_rgba(15,23,42,0.25)]">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Recherche avancée</p>
                    <h3 class="mt-1 text-xl font-bold text-slate-900">Filtres</h3>
                </div>
                <button type="button" data-close-modal="filtersModal" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"><i class="fas fa-xmark"></i></button>
            </div>
            <form method="get" class="mt-5 space-y-3">
                <input type="hidden" name="folder_id" value="<?= $currentFolderId ?>">
                <input type="hidden" name="search" value="<?= htmlspecialchars((string)$currentFilters['search'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="space_type" value="<?= htmlspecialchars((string)$currentFilters['space_type'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="document_type" value="<?= htmlspecialchars((string)$currentFilters['document_type'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="sort" value="<?= htmlspecialchars((string)$currentFilters['sort'], ENT_QUOTES, 'UTF-8') ?>">
                <?php if (!$archiveMode): ?>
                    <input type="hidden" name="archive_scope" value="<?= htmlspecialchars((string)$currentFilters['archive_scope'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="archived_by" value="<?= (int)$currentFilters['archived_by'] ?>">
                    <input type="hidden" name="archived_from" value="<?= htmlspecialchars((string)$currentFilters['archived_from'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="archived_to" value="<?= htmlspecialchars((string)$currentFilters['archived_to'], ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <input type="hidden" name="view_mode" value="<?= htmlspecialchars($viewMode, ENT_QUOTES, 'UTF-8') ?>">
                <?php if (!empty($currentFilters['include_archived'])): ?>
                    <input type="hidden" name="archives" value="1">
                <?php endif; ?>
                <select name="chantier_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    <option value="">Tous chantiers</option>
                    <?php foreach ($chantierOptions as $chantierRow): ?>
                        <option value="<?= (int)$chantierRow['id'] ?>" <?= (int)$currentFilters['chantier_id'] === (int)$chantierRow['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)($chantierLabels[(int)$chantierRow['id']] ?? ('Chantier #' . (int)$chantierRow['id'])), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="service_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    <option value="">Tous services</option>
                    <?php foreach ($serviceOptions as $serviceRow): ?>
                        <option value="<?= (int)$serviceRow['id_service'] ?>" <?= (int)$currentFilters['service_id'] === (int)$serviceRow['id_service'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$serviceRow['lib_service'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="direction_code" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    <option value="">Toutes directions</option>
                    <?php foreach ($directionOptions as $directionKey => $directionLabel): ?>
                        <option value="<?= htmlspecialchars((string)$directionKey, ENT_QUOTES, 'UTF-8') ?>" <?= $currentFilters['direction_code'] === $directionKey ? 'selected' : '' ?>><?= htmlspecialchars((string)$directionLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($archiveMode): ?>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <select name="archive_scope" class="w-full rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300">
                            <option value="all" <?= $currentFilters['archive_scope'] === 'all' ? 'selected' : '' ?>>Toutes les archives</option>
                            <option value="documents" <?= $currentFilters['archive_scope'] === 'documents' ? 'selected' : '' ?>>Documents archivés</option>
                            <option value="folders" <?= $currentFilters['archive_scope'] === 'folders' ? 'selected' : '' ?>>Dossiers archivés</option>
                        </select>
                        <select name="archived_by" class="w-full rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300">
                            <option value="0">Tous les archivistes</option>
                            <?php foreach ($archiveActorOptions as $actorOption): ?>
                                <option value="<?= (int)$actorOption['id'] ?>" <?= (int)$currentFilters['archived_by'] === (int)$actorOption['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$actorOption['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="archived_from" value="<?= htmlspecialchars((string)$currentFilters['archived_from'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300">
                        <input type="date" name="archived_to" value="<?= htmlspecialchars((string)$currentFilters['archived_to'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 focus:outline-none focus:ring-2 focus:ring-amber-300">
                    </div>
                <?php endif; ?>
                <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Appliquer</button>
            </form>
        </div>
    </div>

    <?php if ($canEdit): ?>
        <div id="createFolderModal" class="hidden fixed inset-0 z-[130] overflow-y-auto bg-slate-950/40 px-4 py-6">
            <div class="mx-auto my-6 max-w-xl rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_24px_80px_rgba(15,23,42,0.25)]">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Nouveau dossier</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">Cr&eacute;er un dossier</h3>
                    </div>
                    <button type="button" data-close-modal="createFolderModal" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"><i class="fas fa-xmark"></i></button>
                </div>
                <form method="post" id="create-folder-form" class="mt-5 space-y-3">
                    <input type="hidden" name="action" value="create_folder">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Nom</label>
                        <input type="text" name="name" required class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Ex: Plans valid&eacute;s">
                    </div>
                    <div>
                        <input type="hidden" name="parent_id" id="create-folder-parent-hidden" value="<?= $currentFolderId > 0 ? (int)$currentFolderId : '' ?>">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                            Emplacement : <?= htmlspecialchars($currentFolder ? (string)($currentFolder['name'] ?? 'Dossier courant') : 'Racine documentaire', ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <label class="hidden mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Parent</label>
                        <select name="parent_id_legacy" id="create-folder-parent" class="hidden w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <option value="">Racine documentaire</option>
                            <?php foreach ($folderOptionsFlat as $folderOption): ?>
                                <option value="<?= (int)$folderOption['id'] ?>" <?= (int)$currentFilters['folder_id'] === (int)$folderOption['id'] ? 'selected' : '' ?>><?= htmlspecialchars(str_repeat('— ', (int)$folderOption['_depth']) . (string)$folderOption['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="required_permission" value="">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Espace de rangement</label>
                            <select name="space_type" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                <?php foreach ($spaceLabels as $spaceKey => $spaceLabel): ?>
                                    <option value="<?= htmlspecialchars((string)$spaceKey, ENT_QUOTES, 'UTF-8') ?>" <?= $currentFilters['space_type'] === $spaceKey ? 'selected' : '' ?>><?= htmlspecialchars((string)$spaceLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Service propri&eacute;taire</label>
                            <select name="service_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                <option value="">Mon service par d&eacute;faut</option>
                                <?php foreach ($serviceOptions as $serviceRow): ?>
                                    <option value="<?= (int)$serviceRow['id_service'] ?>" <?= $defaultTargetServiceId === (int)$serviceRow['id_service'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$serviceRow['lib_service'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Personnes qui auront acc&egrave;s</div>
                        <div id="create-folder-access-preview" class="mt-3 max-h-52 space-y-2 overflow-y-auto pr-1 text-sm text-slate-600">Calcul en cours...</div>
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white hover:bg-emerald-700">Cr&eacute;er le dossier</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canUpload): ?>
        <div id="uploadModal" class="hidden fixed inset-0 z-[130] overflow-y-auto bg-slate-950/40 px-4 py-6">
            <div class="mx-auto my-6 max-w-3xl rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_24px_80px_rgba(15,23,42,0.25)]">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Import s&eacute;curis&eacute;</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">Importer des fichiers ou un dossier</h3>
                    </div>
                    <button type="button" data-close-modal="uploadModal" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"><i class="fas fa-xmark"></i></button>
                </div>
                <form id="upload-form" class="mt-5 space-y-4">
                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        <div>
                            <input type="hidden" name="folder_id" id="upload-folder-select-hidden" value="<?= $currentFolderId > 0 ? (int)$currentFolderId : '' ?>">
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                Emplacement : <?= htmlspecialchars($currentFolder ? (string)($currentFolder['name'] ?? 'Dossier courant') : 'Racine documentaire', ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <label class="hidden mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Dossier cible</label>
                            <select name="folder_id_legacy" id="upload-folder-select" class="hidden w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                <option value="">Sans dossier</option>
                                <?php foreach ($folderOptionsFlat as $folderOption): ?>
                                    <option value="<?= (int)$folderOption['id'] ?>" <?= (int)$currentFilters['folder_id'] === (int)$folderOption['id'] ? 'selected' : '' ?>><?= htmlspecialchars(str_repeat('— ', (int)$folderOption['_depth']) . (string)$folderOption['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Type par d&eacute;faut</label>
                            <select name="document_type" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                <?php foreach ($documentTypeLabels as $typeKey => $typeLabel): ?>
                                    <option value="<?= htmlspecialchars((string)$typeKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$typeLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Espace de rangement</label>
                            <select name="space_type" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                <?php foreach ($spaceLabels as $spaceKey => $spaceLabel): ?>
                                    <option value="<?= htmlspecialchars((string)$spaceKey, ENT_QUOTES, 'UTF-8') ?>" <?= $currentFilters['space_type'] === $spaceKey ? 'selected' : '' ?>><?= htmlspecialchars((string)$spaceLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <input type="hidden" name="required_permission" value="">
                            <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Service propri&eacute;taire</label>
                            <select name="service_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                                <option value="">Mon service par d&eacute;faut</option>
                                <?php foreach ($serviceOptions as $serviceRow): ?>
                                    <option value="<?= (int)$serviceRow['id_service'] ?>" <?= $defaultTargetServiceId === (int)$serviceRow['id_service'] ? 'selected' : '' ?>><?= htmlspecialchars((string)$serviceRow['lib_service'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Personnes qui auront acc&egrave;s</div>
                        <div id="upload-access-preview" class="mt-3 max-h-52 space-y-2 overflow-y-auto pr-1 text-sm text-slate-600">Calcul en cours...</div>
                    </div>
                    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                        <label class="flex cursor-pointer items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            <input type="file" id="upload-files-input" class="hidden" multiple>
                            <i class="fas fa-file-arrow-up mr-2"></i>Choisir plusieurs fichiers
                        </label>
                        <label class="flex cursor-pointer items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm font-semibold text-slate-700 hover:bg-slate-100">
                            <input type="file" id="upload-folder-input" class="hidden" webkitdirectory directory multiple>
                            <i class="fas fa-folder-tree mr-2"></i>Choisir un dossier complet
                        </label>
                    </div>
                    <div id="upload-queue-empty" class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">Aucun fichier s&eacute;lectionn&eacute;.</div>
                    <div id="upload-queue" class="hidden max-h-[320px] space-y-3 overflow-y-auto"></div>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" id="upload-start-button" class="inline-flex items-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Lancer l'import</button>
                        <button type="button" id="upload-clear-button" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">Vider la liste</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canShare): ?>
        <div id="shareModal" class="hidden fixed inset-0 z-[130] overflow-y-auto bg-slate-950/40 px-4 py-6">
            <div class="mx-auto my-6 max-w-xl rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_24px_80px_rgba(15,23,42,0.25)]">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Partage externe</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">Créer un lien</h3>
                    </div>
                    <button type="button" data-close-modal="shareModal" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"><i class="fas fa-xmark"></i></button>
                </div>
                <form id="share-form" class="mt-5 space-y-4">
                    <input type="hidden" name="entity_type" value="document">
                    <input type="hidden" name="document_id" value="">
                    <input type="hidden" name="folder_id" value="">
                    <div>
                        <label id="share-target-label" class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Document</label>
                        <div id="share-document-name" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">Aucun document sélectionné</div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Expiration</label>
                        <input type="datetime-local" name="expires_at" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Destinataire interne</label>
                        <select name="recipient_user_id" id="share-recipient-select" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <option value="">Visiteur via lien public</option>
                            <?php foreach ($internalShareRecipients as $recipient): ?>
                                <option value="<?= (int)($recipient['id'] ?? 0) ?>"><?= htmlspecialchars((string)($recipient['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= !empty($recipient['email']) ? ' • ' . htmlspecialchars((string)$recipient['email'], ENT_QUOTES, 'UTF-8') : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-2 text-xs text-slate-500">Laissez vide pour un partage visiteur. Sélectionnez un collaborateur pour tracer le destinataire prévu du lien.</p>
                    </div>
                    <?php if ($canDownload): ?>
                        <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" name="allow_download" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400">
                            Autoriser le téléchargement depuis le lien
                        </label>
                    <?php else: ?>
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Vous pouvez partager ce document en prévisualisation uniquement. Le téléchargement n'est autorisé qu'aux profils habilités.
                        </div>
                    <?php endif; ?>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="can_reshare" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400">
                        Autoriser le repartage du lien
                    </label>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-600">
                        <input type="checkbox" name="can_edit" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400">
                        Marquer ce lien comme autorisé pour édition
                    </label>
                    <div id="share-folder-edit-note" class="hidden rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                        Le workflow d'édition par lien est disponible pour un document unique. Pour un dossier partagé, le lien est généré en consultation, téléchargement et repartage selon les droits cochés.
                    </div>
                    <div id="share-executable-note" class="hidden rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"></div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                        Sans coche supplémentaire, le lien donne un accès en visualisation uniquement.
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Générer le lien</button>
                    <div id="share-result" class="hidden rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Lien généré</div>
                        <input type="text" id="share-result-url" readonly class="mt-2 w-full rounded-2xl border border-emerald-200 bg-white px-4 py-3 text-sm text-slate-700">
                        <div id="share-result-permissions" class="mt-3 flex flex-wrap gap-2"></div>
                        <div id="share-result-recipient" class="mt-3 text-sm text-slate-700"></div>
                        <button type="button" id="share-copy-button" class="mt-3 inline-flex items-center rounded-2xl border border-emerald-200 bg-white px-4 py-3 text-sm font-semibold text-emerald-700 hover:bg-emerald-100">Copier le lien</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canShare): ?>
        <div id="accessRightsModal" class="hidden fixed inset-0 z-[130] overflow-y-auto bg-slate-950/40 px-4 py-6">
            <div class="mx-auto my-6 max-w-xl rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_24px_80px_rgba(15,23,42,0.25)]">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">ACL documentaire</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">Droits d&apos;acc&egrave;s</h3>
                    </div>
                    <button type="button" data-close-modal="accessRightsModal" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"><i class="fas fa-xmark"></i></button>
                </div>
                <form id="access-rights-form" class="mt-5 space-y-4">
                    <input type="hidden" name="entity_type" value="document">
                    <input type="hidden" name="entity_id" value="">
                    <div id="access-rights-target" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">Aucun &eacute;l&eacute;ment s&eacute;lectionn&eacute;</div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <select name="subject_type" id="access-rights-subject-type" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <option value="service">Service</option>
                            <option value="user">Utilisateur</option>
                        </select>
                        <input type="hidden" name="subject_id" id="access-rights-subject-id" value="">
                        <select id="access-rights-service-select" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <option value="">Choisir un service</option>
                            <?php foreach ($serviceOptions as $serviceRow): ?>
                                <option value="<?= (int)$serviceRow['id_service'] ?>"><?= htmlspecialchars((string)$serviceRow['lib_service'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="access-rights-user-select" class="hidden w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <option value="">Choisir un utilisateur</option>
                            <?php foreach ($internalShareRecipients as $recipient): ?>
                                <option value="<?= (int)($recipient['id'] ?? 0) ?>"><?= htmlspecialchars((string)($recipient['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?><?= !empty($recipient['email']) ? ' - ' . htmlspecialchars((string)$recipient['email'], ENT_QUOTES, 'UTF-8') : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Aper&ccedil;u de la port&eacute;e choisie</div>
                        <div id="access-rights-selected-users" class="mt-3 max-h-44 space-y-2 overflow-y-auto pr-1 text-sm text-slate-600">Choisissez un service.</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Personnes ayant acc&egrave;s actuellement</div>
                        <div id="access-rights-current-users" class="mt-3 max-h-56 space-y-2 overflow-y-auto pr-1 text-sm text-slate-600">S&eacute;lectionnez un &eacute;l&eacute;ment.</div>
                    </div>
                    <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                        Les droits ci-dessous s&apos;appliquent &agrave; toute la port&eacute;e choisie. Les propri&eacute;taires, admins et admins documentaires restent prot&eacute;g&eacute;s; les exceptions utilisateur gardent la priorit&eacute;.
                    </div>
                    <div class="grid grid-cols-2 gap-3 text-sm text-slate-700 sm:grid-cols-3">
                        <?php foreach (['can_view' => 'Voir', 'can_edit' => 'Modifier', 'can_download' => 'T&eacute;l&eacute;charger', 'can_delete' => 'Supprimer', 'can_share' => 'Partager', 'can_archive' => 'Archiver'] as $rightKey => $rightLabel): ?>
                            <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 px-3 py-2">
                                <input type="checkbox" name="<?= htmlspecialchars($rightKey, ENT_QUOTES, 'UTF-8') ?>" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-400" <?= $rightKey === 'can_view' ? 'checked' : '' ?>>
                                <?= $rightLabel ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Enregistrer les droits</button>
                    <div id="access-rights-result" class="hidden rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"></div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
        <div id="renameModal" class="hidden fixed inset-0 z-[130] overflow-y-auto bg-slate-950/40 px-4 py-6">
            <div class="mx-auto my-6 max-w-xl rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_24px_80px_rgba(15,23,42,0.25)]">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Renommage</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">Modifier le nom</h3>
                    </div>
                    <button type="button" data-close-modal="renameModal" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"><i class="fas fa-xmark"></i></button>
                </div>
                <form method="post" id="renameForm" class="mt-5 space-y-3">
                    <input type="hidden" name="action" value="rename_document">
                    <input type="hidden" name="folder_id" value="">
                    <input type="hidden" name="document_id" value="">
                    <div id="renameFolderField" class="hidden">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Nom du dossier</label>
                        <input type="text" id="renameNameInput" name="name" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    </div>
                    <div id="renameDocumentField" class="hidden">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Titre du document</label>
                        <input type="text" id="renameTitleInput" name="title" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Enregistrer</button>
                </form>
            </div>
        </div>

        <div id="moveModal" class="hidden fixed inset-0 z-[130] overflow-y-auto bg-slate-950/40 px-4 py-6">
            <div class="mx-auto my-6 max-w-xl rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_24px_80px_rgba(15,23,42,0.25)]">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Déplacement</p>
                        <h3 class="mt-1 text-xl font-bold text-slate-900">Choisir la destination</h3>
                    </div>
                    <button type="button" data-close-modal="moveModal" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"><i class="fas fa-xmark"></i></button>
                </div>
                <form method="post" id="moveForm" class="mt-5 space-y-3">
                    <input type="hidden" name="action" value="move_document">
                    <input type="hidden" name="source_folder_id" value="">
                    <input type="hidden" name="document_id" value="">
                    <div id="moveFolderField" class="hidden">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Dossier parent</label>
                        <select id="moveParentSelect" name="parent_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <option value="">Racine documentaire</option>
                            <?php foreach ($folderOptionsFlat as $folderOption): ?>
                                <option value="<?= (int)$folderOption['id'] ?>"><?= htmlspecialchars(str_repeat('— ', (int)$folderOption['_depth']) . (string)$folderOption['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="moveDocumentField" class="hidden">
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Dossier cible</label>
                        <select id="moveDocumentSelect" name="folder_id" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <option value="">Sans dossier</option>
                            <?php foreach ($folderOptionsFlat as $folderOption): ?>
                                <option value="<?= (int)$folderOption['id'] ?>"><?= htmlspecialchars(str_repeat('— ', (int)$folderOption['_depth']) . (string)$folderOption['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Déplacer</button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div id="decisionModal" class="hidden fixed inset-0 z-[140] bg-slate-950/50 px-4 py-8">
        <div class="mx-auto mt-20 max-w-lg rounded-[28px] border border-slate-200 bg-white p-6 shadow-[0_24px_80px_rgba(15,23,42,0.35)]">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p id="decision-modal-eyebrow" class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Confirmation</p>
                    <h3 id="decision-modal-title" class="mt-1 text-xl font-bold text-slate-900">Confirmer l'action</h3>
                </div>
                <button type="button" id="decision-modal-close" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"><i class="fas fa-xmark"></i></button>
            </div>
            <div id="decision-modal-message" class="mt-4 text-sm leading-6 text-slate-600"></div>
            <div id="decision-modal-actions" class="mt-6 flex flex-wrap justify-end gap-3">
                <button type="button" id="decision-modal-cancel" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-100">Annuler</button>
                <button type="button" id="decision-modal-confirm" class="inline-flex items-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800">Continuer</button>
            </div>
        </div>
    </div>

    <div id="previewModal" class="hidden fixed inset-0 z-[130] bg-slate-950/50 px-4 py-6">
        <div id="preview-modal-shell" class="mx-auto flex h-full w-full max-w-6xl flex-col overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_24px_80px_rgba(15,23,42,0.35)]">
            <div class="flex items-center justify-between gap-4 border-b border-slate-200 px-6 py-4">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">Prévisualisation</p>
                    <h3 id="preview-modal-title" class="mt-1 truncate text-lg font-bold text-slate-900">Document</h3>
                    <div id="preview-modal-permissions" class="mt-2 flex flex-wrap gap-2"></div>
                </div>
                <div class="flex items-center gap-2">
                    <a id="preview-download-button" href="#" class="hidden inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-download mr-2"></i>Télécharger
                    </a>
                    <button type="button" id="preview-default-button" class="hidden inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-compress mr-2"></i>Affichage par défaut
                    </button>
                    <button type="button" id="preview-fullscreen-button" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-expand mr-2"></i>Plein écran
                    </button>
                    <button type="button" data-close-modal="previewModal" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-500 hover:bg-slate-50"><i class="fas fa-xmark"></i></button>
                </div>
            </div>
            <div id="preview-modal-content-wrapper" class="flex-1 overflow-auto bg-slate-100 p-6">
                <div id="preview-modal-empty" class="hidden flex h-full min-h-[60vh] items-center justify-center rounded-[24px] border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-500">
                    Prévisualisation indisponible pour ce format. Le téléchargement direct est désactivé depuis cette interface.
                </div>
                <img id="preview-modal-image" alt="Aperçu" class="hidden mx-auto max-h-[75vh] rounded-[24px] border border-slate-200 bg-white shadow-sm">
                <iframe id="preview-modal-frame" class="hidden h-[75vh] w-full rounded-[24px] border border-slate-200 bg-white" referrerpolicy="same-origin"></iframe>
                <div id="preview-modal-html" class="hidden rounded-[24px] border border-slate-200 bg-white p-6 text-sm text-slate-700"></div>
                <pre id="preview-modal-text" class="hidden h-[75vh] overflow-auto whitespace-pre-wrap rounded-[24px] border border-slate-200 bg-white p-6 font-mono text-sm text-slate-700"></pre>
            </div>
        </div>
    </div>

    <script>
        window.BanamurPreviewDeps = window.BanamurPreviewDeps || {};
        document.addEventListener('DOMContentLoaded', function() {
            const previewAssetUrls = <?= json_encode($documentationPreviewAssets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            const menu = document.getElementById('explorerContextMenu');
            const surface = document.getElementById('explorer-surface');
            const contextZone = document.getElementById('explorer-context-zone');
            const itemNodes = Array.from(document.querySelectorAll('.js-explorer-item'));
            const rightPanel = document.getElementById('rightPanel');
            const documentationLayout = document.getElementById('documentationLayout');
            const rightPanelClose = document.getElementById('right-panel-close');
            const filtersModal = document.getElementById('filtersModal');
            const createFolderModal = document.getElementById('createFolderModal');
            const uploadModal = document.getElementById('uploadModal');
            const shareModal = document.getElementById('shareModal');
            const accessRightsModal = document.getElementById('accessRightsModal');
            const renameModal = document.getElementById('renameModal');
            const moveModal = document.getElementById('moveModal');
            const previewModal = document.getElementById('previewModal');
            const previewModalShell = document.getElementById('preview-modal-shell');
            const previewModalContentWrapper = document.getElementById('preview-modal-content-wrapper');
            const previewModalTitle = document.getElementById('preview-modal-title');
            const previewModalPermissions = document.getElementById('preview-modal-permissions');
            const previewModalEmpty = document.getElementById('preview-modal-empty');
            const previewModalImage = document.getElementById('preview-modal-image');
            const previewModalFrame = document.getElementById('preview-modal-frame');
            const previewModalHtml = document.getElementById('preview-modal-html');
            const previewModalText = document.getElementById('preview-modal-text');
            const previewFullscreenButton = document.getElementById('preview-fullscreen-button');
            const previewDefaultButton = document.getElementById('preview-default-button');
            const previewDownloadButton = document.getElementById('preview-download-button');
            const topCreateFolderButton = document.getElementById('top-create-folder-button');
            const topUploadButton = document.getElementById('top-upload-button');
            const topFiltersButton = document.getElementById('top-filters-button');
            const createFolderParent = document.getElementById('create-folder-parent');
            const createFolderParentHidden = document.getElementById('create-folder-parent-hidden');
            const uploadFolderSelect = document.getElementById('upload-folder-select');
            const uploadFolderSelectHidden = document.getElementById('upload-folder-select-hidden');
            const uploadForm = document.getElementById('upload-form');
            const uploadFilesInput = document.getElementById('upload-files-input');
            const uploadFolderInput = document.getElementById('upload-folder-input');
            const uploadQueueNode = document.getElementById('upload-queue');
            const uploadQueueEmpty = document.getElementById('upload-queue-empty');
            const uploadStartButton = document.getElementById('upload-start-button');
            const uploadClearButton = document.getElementById('upload-clear-button');
            const selectionPanel = document.getElementById('selection-panel');
            const selectionEmptyState = document.getElementById('selection-empty-state');
            const selectionName = document.getElementById('selection-name');
            const selectionType = document.getElementById('selection-type');
            const selectionId = document.getElementById('selection-id');
            const selectionIcon = document.getElementById('selection-icon');
            const selectionPreview = document.getElementById('selection-preview');
            const selectionDownloadButton = document.getElementById('selection-download-button');
            const selectionOpenButton = document.getElementById('selection-open-button');
            const selectionOpenLabel = document.getElementById('selection-open-label');
            const selectionShareButton = document.getElementById('selection-share-button');
            const selectionHistoryBlock = document.getElementById('selection-history-block');
            const selectionShareHistory = document.getElementById('selection-share-history');
            const selectionActivityHistory = document.getElementById('selection-activity-history');
            const multiSelectionPanel = document.getElementById('multi-selection-panel');
            const multiSelectionTitle = document.getElementById('multi-selection-title');
            const shareForm = document.getElementById('share-form');
            const shareTargetLabel = document.getElementById('share-target-label');
            const shareDocumentName = document.getElementById('share-document-name');
            const shareFolderEditNote = document.getElementById('share-folder-edit-note');
            const shareResult = document.getElementById('share-result');
            const shareResultUrl = document.getElementById('share-result-url');
            const shareResultPermissions = document.getElementById('share-result-permissions');
            const shareResultRecipient = document.getElementById('share-result-recipient');
            const shareCopyButton = document.getElementById('share-copy-button');
            const accessRightsForm = document.getElementById('access-rights-form');
            const accessRightsTarget = document.getElementById('access-rights-target');
            const accessRightsResult = document.getElementById('access-rights-result');
            const accessRightsSelectedUsers = document.getElementById('access-rights-selected-users');
            const accessRightsSubjectType = document.getElementById('access-rights-subject-type');
            const accessRightsSubjectId = document.getElementById('access-rights-subject-id');
            const accessRightsServiceSelect = document.getElementById('access-rights-service-select');
            const accessRightsUserSelect = document.getElementById('access-rights-user-select');
            const createFolderAccessPreview = document.getElementById('create-folder-access-preview');
            const uploadAccessPreview = document.getElementById('upload-access-preview');
            const accessRightsCurrentUsers = document.getElementById('access-rights-current-users');
            const defaultDocumentationServiceId = <?= (int)$defaultTargetServiceId ?>;
            const accessRightKeys = ['can_view', 'can_edit', 'can_download', 'can_delete', 'can_share', 'can_archive'];
            let accessRightsEntries = [];
            const renameForm = document.getElementById('renameForm');
            const renameFolderField = document.getElementById('renameFolderField');
            const renameDocumentField = document.getElementById('renameDocumentField');
            const renameNameInput = document.getElementById('renameNameInput');
            const renameTitleInput = document.getElementById('renameTitleInput');
            const moveForm = document.getElementById('moveForm');
            const moveFolderField = document.getElementById('moveFolderField');
            const moveDocumentField = document.getElementById('moveDocumentField');
            const moveParentSelect = document.getElementById('moveParentSelect');
            const moveDocumentSelect = document.getElementById('moveDocumentSelect');
            const dragMoveForm = document.getElementById('dragMoveForm');
            const contextArchiveForm = document.getElementById('contextArchiveForm');
            const selectAllItems = document.getElementById('select-all-items');
            const bulkSelectionToolbar = document.getElementById('bulk-selection-toolbar');
            const bulkSelectionCount = document.getElementById('bulk-selection-count');
            const bulkArchiveButton = document.getElementById('bulk-archive-button');
            const bulkRestoreButton = document.getElementById('bulk-restore-button');
            const bulkDownloadButton = document.getElementById('bulk-download-button');
            const bulkDeleteButton = document.getElementById('bulk-delete-button');
            const bulkDownloadForm = document.getElementById('bulkDownloadForm');
            const archiveMode = <?= $archiveMode ? 'true' : 'false' ?>;
            const decisionModal = document.getElementById('decisionModal');
            const decisionModalTitle = document.getElementById('decision-modal-title');
            const decisionModalEyebrow = document.getElementById('decision-modal-eyebrow');
            const decisionModalMessage = document.getElementById('decision-modal-message');
            const decisionModalConfirm = document.getElementById('decision-modal-confirm');
            const decisionModalCancel = document.getElementById('decision-modal-cancel');
            const decisionModalClose = document.getElementById('decision-modal-close');
            const menuOpenLabel = menu ? menu.querySelector('.js-menu-open-label') : null;
            const menuArchiveLabel = menu ? menu.querySelector('.js-menu-archive-label') : null;
            let activeItem = null;
            let draggedItem = null;
            let draggedItemsSnapshot = [];
            let moveActionRunning = false;
            let uploadQueue = [];
            let uploadRunning = false;
            let decisionModalResolver = null;

            function openModal(modal) {
                if (modal) {
                    modal.classList.remove('hidden');
                }
            }

            function closeModal(modal) {
                if (modal) {
                    modal.classList.add('hidden');
                }
            }

            function resolveDecisionModal(result) {
                closeModal(decisionModal);
                if (typeof decisionModalResolver === 'function') {
                    decisionModalResolver(result);
                }
                decisionModalResolver = null;
            }

            function askDecision(options) {
                if (!decisionModal) {
                    return window.Promise.resolve(window.confirm(options && options.message ? options.message : 'Confirmer ?'));
                }
                if (decisionModalEyebrow) decisionModalEyebrow.textContent = options && options.eyebrow ? options.eyebrow : 'Confirmation';
                if (decisionModalTitle) decisionModalTitle.textContent = options && options.title ? options.title : 'Confirmer l\'action';
                if (decisionModalMessage) decisionModalMessage.innerHTML = options && options.message ? options.message : '';
                if (decisionModalConfirm) {
                    decisionModalConfirm.textContent = options && options.confirmLabel ? options.confirmLabel : 'Continuer';
                    decisionModalConfirm.className = 'inline-flex items-center rounded-2xl px-4 py-3 text-sm font-semibold text-white ' + ((options && options.confirmTone === 'danger') ? 'bg-rose-600 hover:bg-rose-700' : 'bg-slate-950 hover:bg-slate-800');
                }
                if (decisionModalCancel) {
                    decisionModalCancel.textContent = options && options.cancelLabel ? options.cancelLabel : 'Annuler';
                    decisionModalCancel.classList.toggle('hidden', !!(options && options.hideCancel));
                }
                openModal(decisionModal);
                return new window.Promise(function(resolve) {
                    decisionModalResolver = resolve;
                });
            }

            function showNotice(title, message) {
                return askDecision({
                    eyebrow: 'Information',
                    title: title || 'Information',
                    message: message || '',
                    confirmLabel: 'Fermer',
                    hideCancel: true
                });
            }

            function getItemCheckbox(item) {
                return item ? item.querySelector('.js-item-checkbox') : null;
            }

            function updateItemCheckedVisual(item) {
                const checkbox = getItemCheckbox(item);
                const checked = !!(checkbox && checkbox.checked);
                item.classList.toggle('ring-2', checked);
                item.classList.toggle('ring-emerald-400', checked);
                item.classList.toggle('bg-emerald-50/50', checked);
            }

            function getCheckedItems() {
                return itemNodes.filter(function(item) {
                    const checkbox = getItemCheckbox(item);
                    return !!(checkbox && checkbox.checked);
                });
            }

            function getDraggedItems(draggedSourceItem) {
                const checkedItems = getCheckedItems();
                if (draggedItemsSnapshot.length) {
                    return draggedItemsSnapshot.slice();
                }
                if (checkedItems.length && draggedSourceItem && checkedItems.indexOf(draggedSourceItem) !== -1) {
                    return checkedItems;
                }
                return draggedSourceItem ? [draggedSourceItem] : checkedItems;
            }

            function clearCheckedItems(exceptItem) {
                itemNodes.forEach(function(item) {
                    if (exceptItem && item === exceptItem) {
                        return;
                    }
                    const checkbox = getItemCheckbox(item);
                    if (checkbox) {
                        checkbox.checked = false;
                    }
                    updateItemCheckedVisual(item);
                });
            }

            function buildSelectionPayload(items) {
                return items.reduce(function(payload, item) {
                    const itemId = Number(item.dataset.itemId || 0);
                    if (!itemId) {
                        return payload;
                    }
                    if (item.dataset.itemType === 'folder') {
                        payload.folderIds.push(String(itemId));
                    } else {
                        payload.documentIds.push(String(itemId));
                    }
                    return payload;
                }, {
                    documentIds: [],
                    folderIds: []
                });
            }

            function refreshSelectionUI() {
                const checkedItems = getCheckedItems();
                const count = checkedItems.length;

                if (selectAllItems) {
                    const allSelected = itemNodes.length > 0 && count === itemNodes.length;
                    selectAllItems.checked = allSelected;
                    selectAllItems.indeterminate = count > 0 && count < itemNodes.length;
                }

                if (bulkSelectionToolbar) {
                    bulkSelectionToolbar.classList.toggle('hidden', count === 0);
                }
                if (bulkSelectionCount) {
                    bulkSelectionCount.textContent = count <= 1 ? count + ' élément sélectionné' : count + ' éléments sélectionnés';
                }

                if (count === 1) {
                    activeItem = checkedItems[0];
                    setSelected(activeItem);
                    return;
                }

                if (count > 1) {
                    if (selectionPanel) selectionPanel.classList.add('hidden');
                    if (selectionEmptyState) selectionEmptyState.classList.add('hidden');
                    if (selectionHistoryBlock) selectionHistoryBlock.classList.add('hidden');
                    if (selectionPreview) {
                        selectionPreview.classList.add('hidden');
                        selectionPreview.innerHTML = '';
                    }
                    if (multiSelectionPanel) multiSelectionPanel.classList.remove('hidden');
                    if (multiSelectionTitle) {
                        const payload = buildSelectionPayload(checkedItems);
                        const folderCount = payload.folderIds.length;
                        const documentCount = payload.documentIds.length;
                        multiSelectionTitle.textContent = count + ' éléments sélectionnés' + (folderCount && documentCount ? ' (' + folderCount + ' dossiers, ' + documentCount + ' fichiers)' : '');
                    }
                    showRightPanel();
                    activeItem = null;
                    return;
                }

                if (multiSelectionPanel) multiSelectionPanel.classList.add('hidden');
                activeItem = null;
                setSelected(null);
            }

            function submitBulkDownload(items) {
                if (!bulkDownloadForm) {
                    return;
                }
                bulkDownloadForm.innerHTML = '';
                const payload = buildSelectionPayload(items);
                payload.documentIds.forEach(function(id) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'document_ids[]';
                    input.value = id;
                    bulkDownloadForm.appendChild(input);
                });
                payload.folderIds.forEach(function(id) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'folder_ids[]';
                    input.value = id;
                    bulkDownloadForm.appendChild(input);
                });
                bulkDownloadForm.submit();
            }

            function callSelectionAction(action, items, extraPayload) {
                const payload = buildSelectionPayload(items);
                const formData = new window.FormData();
                formData.append('action', action);
                payload.documentIds.forEach(function(id) {
                    formData.append('document_ids[]', id);
                });
                payload.folderIds.forEach(function(id) {
                    formData.append('folder_ids[]', id);
                });
                Object.keys(extraPayload || {}).forEach(function(key) {
                    formData.append(key, extraPayload[key]);
                });
                return fetch('documentation_action_api.php', {
                    method: 'POST',
                    body: formData
                }).then(function(response) {
                    return response.json().then(function(payloadResponse) {
                        if (!response.ok || !payloadResponse.success) {
                            const error = new Error(payloadResponse.message || 'Action impossible.');
                            error.payload = payloadResponse || {};
                            throw error;
                        }
                        return payloadResponse;
                    });
                });
            }

            function checkMoveConflicts(items, targetFolderId) {
                const payload = buildSelectionPayload(items);
                const formData = new window.FormData();
                formData.append('action', 'check_move_conflicts');
                formData.append('target_folder_id', targetFolderId || '');
                payload.documentIds.forEach(function(id) {
                    formData.append('document_ids[]', id);
                });
                payload.folderIds.forEach(function(id) {
                    formData.append('folder_ids[]', id);
                });
                return fetch('documentation_conflict_api.php', {
                    method: 'POST',
                    body: formData
                }).then(function(response) {
                    return response.json();
                });
            }

            function checkUploadConflict(entry, targetFolderId) {
                const formData = new window.FormData();
                formData.append('action', 'check_upload_conflict');
                formData.append('folder_id', targetFolderId || '');
                formData.append('original_name', entry.name || '');
                formData.append('title', entry.name ? entry.name.replace(/\.[^.]+$/, '') : '');
                return fetch('documentation_conflict_api.php', {
                    method: 'POST',
                    body: formData
                }).then(function(response) {
                    return response.json();
                });
            }

            function moveSelectionToFolder(targetFolderId, draggedSourceItem) {
                if (moveActionRunning) {
                    return;
                }
                const itemsToMove = getDraggedItems(draggedSourceItem);
                if (!itemsToMove.length) {
                    return;
                }
                moveActionRunning = true;
                checkMoveConflicts(itemsToMove, targetFolderId).then(function(checkResult) {
                    if (checkResult && checkResult.hasConflict) {
                        return askDecision({
                            eyebrow: 'Conflit détecté',
                            title: 'Écraser les éléments existants ?',
                            message: 'Un ou plusieurs éléments du même nom existent déjà dans le dossier cible. Voulez-vous les écraser ?',
                            confirmLabel: 'Écraser',
                            cancelLabel: 'Annuler',
                            confirmTone: 'danger'
                        }).then(function(confirmed) {
                            if (!confirmed) {
                                return null;
                            }
                            return callSelectionAction('move_selection', itemsToMove, {
                                target_folder_id: targetFolderId || '',
                                overwrite: '1'
                            });
                        });
                    }
                    return callSelectionAction('move_selection', itemsToMove, {
                        target_folder_id: targetFolderId || ''
                    });
                }).then(function(result) {
                    if (result) {
                        window.location.reload();
                    }
                }).catch(function(error) {
                    if (error.payload && error.payload.conflict) {
                        showNotice('Déplacement impossible', error.payload.message || 'Déplacement impossible à cause d\'un conflit de nom.');
                        return;
                    }
                    showNotice('Déplacement impossible', error.message || 'Déplacement impossible.');
                }).finally(function() {
                    moveActionRunning = false;
                    draggedItemsSnapshot = [];
                });
            }

            function deleteCurrentSelection(items) {
                if (!items.length) {
                    return;
                }
                askDecision({
                    eyebrow: 'Suppression définitive',
                    title: 'Supprimer les éléments sélectionnés ?',
                    message: 'Les éléments sélectionnés seront supprimés définitivement, y compris sur le disque du serveur.',
                    confirmLabel: 'Supprimer',
                    cancelLabel: 'Annuler',
                    confirmTone: 'danger'
                }).then(function(confirmed) {
                    if (!confirmed) {
                        return null;
                    }
                    return callSelectionAction('delete_selection', items, {});
                }).then(function(result) {
                    if (result) {
                        window.location.reload();
                    }
                }).catch(function(error) {
                    showNotice('Suppression impossible', error.message || 'Suppression impossible.');
                });
            }

            function archiveCurrentSelection(items, archived) {
                if (!items.length) {
                    return;
                }
                const archiveAction = !!archived;
                askDecision({
                    eyebrow: archiveAction ? 'Archivage' : 'Restauration',
                    title: archiveAction ? 'Archiver les éléments sélectionnés ?' : 'Restaurer les éléments sélectionnés ?',
                    message: archiveAction ?
                        'Les éléments sélectionnés seront retirés de l\'explorateur actif et déplacés dans les archives.' : 'Les éléments sélectionnés redeviendront visibles dans l\'explorateur actif.',
                    confirmLabel: archiveAction ? 'Archiver' : 'Restaurer',
                    cancelLabel: 'Annuler',
                    confirmTone: archiveAction ? undefined : undefined
                }).then(function(confirmed) {
                    if (!confirmed) {
                        return null;
                    }
                    return callSelectionAction('archive_selection', items, {
                        archive: archiveAction ? '1' : ''
                    });
                }).then(function(result) {
                    if (result) {
                        window.location.reload();
                    }
                }).catch(function(error) {
                    showNotice(archiveAction ? 'Archivage impossible' : 'Restauration impossible', error.message || (archiveAction ? 'Archivage impossible.' : 'Restauration impossible.'));
                });
            }

            function updatePreviewViewportState() {
                const isFullscreen = !!document.fullscreenElement;
                if (previewFullscreenButton) {
                    previewFullscreenButton.classList.toggle('hidden', isFullscreen);
                }
                if (previewDefaultButton) {
                    previewDefaultButton.classList.toggle('hidden', !isFullscreen);
                }
            }

            function renderPermissionBadges(container, permissions) {
                if (!container) {
                    return;
                }
                const badges = [];
                if (permissions.canView) badges.push('<span class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-800">Visualisation</span>');
                if (permissions.canDownload) badges.push('<span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Téléchargement</span>');
                if (permissions.canEdit) badges.push('<span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Édition</span>');
                if (permissions.canReshare) badges.push('<span class="inline-flex items-center rounded-full bg-fuchsia-100 px-3 py-1 text-xs font-semibold text-fuchsia-800">Repartage</span>');
                if (!badges.length) badges.push('<span class="inline-flex items-center rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Visualisation uniquement</span>');
                container.innerHTML = badges.join('');
            }

            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function(char) {
                    return ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    })[char] || char;
                });
            }

            function renderRecipientSummary(container, recipient) {
                if (!container) {
                    return;
                }
                if (recipient && (recipient.name || recipient.email)) {
                    const label = escapeHtml(recipient.name || recipient.email || '');
                    const emailPart = recipient.email && recipient.email !== recipient.name ? ' <span class="text-slate-500">(' + escapeHtml(recipient.email) + ')</span>' : '';
                    container.innerHTML = '<span class="font-semibold text-slate-900">Destinataire interne :</span> ' + label + emailPart;
                    return;
                }
                container.innerHTML = '<span class="font-semibold text-slate-900">Destinataire :</span> visiteur via lien public';
            }

            function loadScriptOnce(key, src) {
                if (window.BanamurPreviewDeps[key]) {
                    return window.BanamurPreviewDeps[key];
                }
                window.BanamurPreviewDeps[key] = new Promise(function(resolve, reject) {
                    const existing = document.querySelector('script[data-banamur-lib="' + key + '"]');
                    if (existing) {
                        existing.addEventListener('load', function() {
                            resolve();
                        }, {
                            once: true
                        });
                        existing.addEventListener('error', function() {
                            reject(new Error('Chargement impossible'));
                        }, {
                            once: true
                        });
                        return;
                    }
                    const script = document.createElement('script');
                    script.src = src;
                    script.async = true;
                    script.dataset.banamurLib = key;
                    script.onload = function() {
                        resolve();
                    };
                    script.onerror = function() {
                        reject(new Error('Chargement impossible'));
                    };
                    document.head.appendChild(script);
                });
                return window.BanamurPreviewDeps[key];
            }

            function formatHistoryDate(value) {
                if (!value) {
                    return '';
                }
                const parsed = new Date(String(value).replace(' ', 'T'));
                if (Number.isNaN(parsed.getTime())) {
                    return String(value);
                }
                return parsed.toLocaleString('fr-FR');
            }

            function formatFileSize(value) {
                const size = Number(value || 0);
                if (!Number.isFinite(size) || size <= 0) {
                    return '0 o';
                }
                const units = ['o', 'Ko', 'Mo', 'Go', 'To'];
                let current = size;
                let unitIndex = 0;
                while (current >= 1024 && unitIndex < units.length - 1) {
                    current /= 1024;
                    unitIndex += 1;
                }
                const precision = current >= 10 || unitIndex === 0 ? 0 : 1;
                return current.toFixed(precision).replace('.', ',') + ' ' + units[unitIndex];
            }

            function renderHistoryLists(payload) {
                if (!selectionShareHistory || !selectionActivityHistory || !selectionHistoryBlock) {
                    return;
                }

                const shares = Array.isArray(payload && payload.shares) ? payload.shares : [];
                const activity = Array.isArray(payload && payload.activity) ? payload.activity : [];

                selectionHistoryBlock.classList.remove('hidden');
                selectionShareHistory.innerHTML = shares.length ? shares.map(function(entry) {
                    const recipient = entry.recipient_name || entry.recipient_email || 'Visiteur';
                    const rights = ['Vue'];
                    if (entry.allow_download) rights.push('Telechargement');
                    if (entry.can_edit) rights.push('Edition');
                    if (entry.can_reshare) rights.push('Repartage');
                    return '<div class="rounded-xl bg-white px-3 py-2 border border-slate-200">' +
                        '<div class="font-semibold text-slate-800">' + escapeHtml(recipient) + '</div>' +
                        '<div class="mt-1 text-slate-500">Lien #' + escapeHtml(entry.token_preview || '') + ' • ' + escapeHtml(rights.join(', ')) + '</div>' +
                        '<div class="mt-1 text-slate-400">' + escapeHtml(formatHistoryDate(entry.created_at)) + ' • acces ' + escapeHtml(String(entry.access_count || 0)) + '</div>' +
                        '</div>';
                }).join('') : '<div class="rounded-xl bg-white px-3 py-2 border border-slate-200 text-slate-500">Aucun lien partage pour ce document.</div>';

                selectionActivityHistory.innerHTML = activity.length ? activity.map(function(entry) {
                    const actor = entry.user_name || entry.user_email || 'Systeme';
                    let label = 'Activite';
                    if (entry.event_type === 'documentation_share_edited') label = 'Modification via lien';
                    if (entry.event_type === 'documentation_share_created') label = 'Lien cree';
                    if (entry.event_type === 'documentation_share_download_denied') label = 'Telechargement refuse';
                    if (entry.event_type === 'documentation_share_edit_denied') label = 'Edition refusee';
                    return '<div class="rounded-xl bg-white px-3 py-2 border border-slate-200">' +
                        '<div class="font-semibold text-slate-800">' + escapeHtml(label) + '</div>' +
                        '<div class="mt-1 text-slate-500">' + escapeHtml(actor) + '</div>' +
                        '<div class="mt-1 text-slate-400">' + escapeHtml(formatHistoryDate(entry.created_at)) + '</div>' +
                        '</div>';
                }).join('') : '<div class="rounded-xl bg-white px-3 py-2 border border-slate-200 text-slate-500">Aucune modification via lien enregistree.</div>';
            }

            function loadSelectionHistory(documentId) {
                if (!selectionHistoryBlock || !selectionShareHistory || !selectionActivityHistory || !documentId) {
                    return;
                }
                selectionHistoryBlock.classList.remove('hidden');
                selectionShareHistory.innerHTML = '<div class="rounded-xl bg-white px-3 py-2 border border-slate-200 text-slate-500">Chargement...</div>';
                selectionActivityHistory.innerHTML = '<div class="rounded-xl bg-white px-3 py-2 border border-slate-200 text-slate-500">Chargement...</div>';
                fetch('documentation_history_api.php?document_id=' + encodeURIComponent(documentId), {
                    headers: {
                        'Accept': 'application/json'
                    }
                }).then(function(response) {
                    return response.json();
                }).then(function(payload) {
                    if (!payload.success) {
                        throw new Error(payload.message || 'Historique indisponible.');
                    }
                    renderHistoryLists(payload);
                }).catch(function(error) {
                    const message = escapeHtml(error.message || 'Historique indisponible.');
                    selectionShareHistory.innerHTML = '<div class="rounded-xl bg-white px-3 py-2 border border-rose-200 text-rose-700">' + message + '</div>';
                    selectionActivityHistory.innerHTML = '<div class="rounded-xl bg-white px-3 py-2 border border-rose-200 text-rose-700">' + message + '</div>';
                });
            }

            function renderPdfPreview(container, url, options) {
                if (!container || !url) {
                    return Promise.resolve();
                }
                const config = options || {};
                const maxPages = config.compact ? 1 : 6;
                container.innerHTML = '<div class="rounded-2xl bg-white px-4 py-6 text-sm text-slate-500">Chargement du PDF...</div>';
                container.classList.remove('hidden');

                return fetch(url).then(function(response) {
                    if (!response.ok) {
                        throw new Error('Previsualisation PDF indisponible.');
                    }
                    return response.arrayBuffer();
                }).then(function(buffer) {
                    return loadScriptOnce('pdfjs', previewAssetUrls.pdfjs).then(function() {
                        window.pdfjsLib.GlobalWorkerOptions.workerSrc = previewAssetUrls.pdfWorker;
                        return window.pdfjsLib.getDocument({
                            data: buffer
                        }).promise;
                    });
                }).then(function(pdf) {
                    container.innerHTML = '';
                    const pagesToRender = Math.min(pdf.numPages || 0, maxPages);
                    if (!pagesToRender) {
                        container.innerHTML = '<div class="rounded-2xl bg-white px-4 py-6 text-sm text-slate-500">PDF vide.</div>';
                        return;
                    }

                    let chain = Promise.resolve();
                    for (let pageNumber = 1; pageNumber <= pagesToRender; pageNumber += 1) {
                        chain = chain.then(function() {
                            return pdf.getPage(pageNumber).then(function(page) {
                                const viewport = page.getViewport({
                                    scale: config.compact ? 0.7 : 1.25
                                });
                                const wrapper = document.createElement('div');
                                wrapper.className = config.compact ? 'overflow-hidden rounded-2xl border border-slate-200 bg-white' : 'mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm';
                                const canvas = document.createElement('canvas');
                                const context = canvas.getContext('2d');
                                canvas.width = viewport.width;
                                canvas.height = viewport.height;
                                canvas.className = 'block w-full h-auto';
                                wrapper.appendChild(canvas);
                                container.appendChild(wrapper);
                                return page.render({
                                    canvasContext: context,
                                    viewport: viewport
                                }).promise;
                            });
                        });
                    }

                    return chain.then(function() {
                        if (pdf.numPages > pagesToRender) {
                            const note = document.createElement('div');
                            note.className = 'rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-3 text-xs text-slate-500';
                            note.textContent = 'Apercu limite a ' + pagesToRender + ' page(s) dans cette vue.';
                            container.appendChild(note);
                        }
                    });
                }).catch(function(error) {
                    container.innerHTML = '<div class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-6 text-sm text-slate-500">' + escapeHtml(error.message || 'Previsualisation PDF indisponible.') + '</div>';
                });
            }

            function renderArchivePreview(container, payload, downloadUrl) {
                if (!container) {
                    return;
                }
                const entries = Array.isArray(payload && payload.entries) ? payload.entries : [];
                const archiveName = payload && payload.archiveName ? payload.archiveName : 'Archive ZIP';
                const fileCount = entries.filter(function(entry) {
                    return !entry.isDirectory;
                }).length;
                const folderCount = entries.filter(function(entry) {
                    return !!entry.isDirectory;
                }).length;

                container.innerHTML =
                    '<div class="space-y-4">' +
                    '<div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4">' +
                    '<div class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">Archive ZIP</div>' +
                    '<div class="mt-2 text-lg font-bold text-amber-950">' + escapeHtml(archiveName) + '</div>' +
                    '<div class="mt-2 text-sm text-amber-900">' + escapeHtml(String(fileCount)) + ' fichier(s) et ' + escapeHtml(String(folderCount)) + ' dossier(s) dans cette archive.</div>' +
                    '<div class="mt-3 text-xs text-amber-800">Vous pouvez télécharger l’archive complète ou extraire un fichier individuellement depuis cette liste.</div>' +
                    (downloadUrl ? '<div class="mt-3"><a href="' + escapeHtml(downloadUrl) + '" class="inline-flex items-center rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100"><i class="fas fa-download mr-2"></i>Télécharger le ZIP complet</a></div>' : '') +
                    '</div>' +
                    '<div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">' +
                    '<div class="grid grid-cols-[minmax(0,2fr)_120px_150px] gap-0 border-b border-slate-200 bg-slate-100 px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">' +
                    '<div>Contenu</div>' +
                    '<div>Taille</div>' +
                    '<div>Action</div>' +
                    '</div>' +
                    (entries.length ? entries.map(function(entry) {
                        const icon = entry.isDirectory ? 'fa-folder' : 'fa-file';
                        const sizeLabel = entry.isDirectory ? '—' : formatFileSize(entry.size);
                        const action = entry.isDirectory ?
                            '<span class="text-xs text-slate-400">Dossier</span>' :
                            '<a href="' + escapeHtml(entry.downloadUrl || '#') + '" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"><i class="fas fa-file-export mr-1.5"></i>Extraire</a>';
                        return '<div class="grid grid-cols-[minmax(0,2fr)_120px_150px] gap-0 border-b border-slate-100 px-4 py-3 last:border-b-0">' +
                            '<div class="min-w-0 pr-4">' +
                            '<div class="flex items-center gap-2 truncate text-sm font-medium text-slate-800"><i class="fas ' + icon + ' text-slate-400"></i><span class="truncate">' + escapeHtml(entry.name || '') + '</span></div>' +
                            '</div>' +
                            '<div class="text-xs text-slate-500">' + escapeHtml(sizeLabel) + '</div>' +
                            '<div>' + action + '</div>' +
                            '</div>';
                    }).join('') : '<div class="px-4 py-6 text-sm text-slate-500">Cette archive ZIP est vide.</div>') +
                    '</div>' +
                    '</div>';
            }

            function hideMenu() {
                if (menu) {
                    menu.classList.add('hidden');
                }
            }

            function showRightPanel() {
                if (!rightPanel || !documentationLayout) {
                    return;
                }
                rightPanel.classList.remove('hidden');
                documentationLayout.classList.remove('xl:grid-cols-[300px_minmax(0,1fr)]');
                documentationLayout.classList.add('xl:grid-cols-[300px_minmax(0,1fr)_320px]');
            }

            function hideRightPanel() {
                if (!rightPanel || !documentationLayout) {
                    return;
                }
                rightPanel.classList.add('hidden');
                documentationLayout.classList.remove('xl:grid-cols-[300px_minmax(0,1fr)_320px]');
                documentationLayout.classList.add('xl:grid-cols-[300px_minmax(0,1fr)]');
            }

            function openItem(item) {
                if (!item || !item.dataset) {
                    return;
                }
                if (item.dataset.itemType === 'folder') {
                    const targetUrl = item.dataset.openUrl || '';
                    if (targetUrl) {
                        window.location.href = targetUrl;
                    }
                    return;
                }
                openPreviewModal(item);
            }

            function resetPreviewModal() {
                if (previewDownloadButton) {
                    previewDownloadButton.classList.add('hidden');
                    previewDownloadButton.setAttribute('href', '#');
                }
                if (previewModalPermissions) {
                    previewModalPermissions.innerHTML = '';
                }
                if (previewModalImage) {
                    previewModalImage.classList.add('hidden');
                    previewModalImage.removeAttribute('src');
                }
                if (previewModalFrame) {
                    previewModalFrame.classList.add('hidden');
                    previewModalFrame.setAttribute('src', 'about:blank');
                }
                if (previewModalText) {
                    previewModalText.classList.add('hidden');
                    previewModalText.textContent = '';
                }
                if (previewModalHtml) {
                    previewModalHtml.classList.add('hidden');
                    previewModalHtml.innerHTML = '';
                }
                if (previewModalEmpty) {
                    previewModalEmpty.classList.add('hidden');
                }
                if (previewModalContentWrapper) {
                    previewModalContentWrapper.scrollTop = 0;
                    previewModalContentWrapper.scrollLeft = 0;
                }
            }

            function showPreviewFallback(message) {
                resetPreviewModal();
                if (previewModalEmpty) {
                    previewModalEmpty.textContent = message || 'Prévisualisation indisponible pour ce format. Le téléchargement direct est désactivé depuis cette interface.';
                    previewModalEmpty.classList.remove('hidden');
                }
                openModal(previewModal);
            }

            function openPreviewModal(item) {
                if (!item || !item.dataset || item.dataset.itemType !== 'document') {
                    return;
                }

                resetPreviewModal();

                if (previewModalTitle) {
                    previewModalTitle.textContent = item.dataset.itemName || 'Document';
                }

                const previewKind = item.dataset.itemPreviewKind || '';
                const previewUrl = item.dataset.itemPreviewUrl || '';
                const inlineUrl = item.dataset.itemInlineUrl || previewUrl || '';
                const downloadUrl = item.dataset.openUrl || '';
                const archiveDownloadAllowed = previewKind === 'archive_zip' || previewKind === 'archive_download';
                const permissions = {
                    canView: true,
                    canDownload: <?= $canDownload ? 'true' : 'false' ?> || archiveDownloadAllowed,
                    canEdit: false,
                    canReshare: false
                };
                renderPermissionBadges(previewModalPermissions, permissions);

                if (previewDownloadButton && downloadUrl && permissions.canDownload) {
                    previewDownloadButton.href = downloadUrl;
                    previewDownloadButton.classList.remove('hidden');
                }

                if (previewKind === 'image' && previewUrl && previewModalImage) {
                    previewModalImage.src = previewUrl;
                    previewModalImage.classList.remove('hidden');
                    openModal(previewModal);
                    return;
                }

                if (previewKind === 'video' && inlineUrl && previewModalHtml) {
                    previewModalHtml.innerHTML = '<div class="rounded-2xl bg-slate-950 p-3"><video src="' + inlineUrl + '" controls preload="metadata" class="max-h-[72vh] w-full rounded-2xl bg-black">Votre navigateur ne peut pas lire cette vidéo.</video></div>';
                    previewModalHtml.classList.remove('hidden');
                    openModal(previewModal);
                    return;
                }

                if (previewKind === 'pdf' && inlineUrl && previewModalHtml) {
                    previewModalHtml.classList.remove('hidden');
                    openModal(previewModal);
                    renderPdfPreview(previewModalHtml, inlineUrl, {
                        compact: false
                    });
                    return;
                }

                if (previewKind === 'text' && inlineUrl && previewModalText) {
                    previewModalText.textContent = 'Chargement...';
                    previewModalText.classList.remove('hidden');
                    openModal(previewModal);
                    fetch(inlineUrl, {
                        headers: {
                            'Accept': 'text/plain, text/csv, application/json, application/xml, text/xml'
                        }
                    }).then(function(response) {
                        if (!response.ok) {
                            throw new Error('Prévisualisation indisponible.');
                        }
                        return response.text();
                    }).then(function(content) {
                        previewModalText.textContent = content || 'Fichier vide.';
                    }).catch(function() {
                        showPreviewFallback('Prévisualisation indisponible pour ce format. Le téléchargement direct est désactivé depuis cette interface.');
                    });
                    return;
                }

                if ((previewKind === 'word' || previewKind === 'excel') && inlineUrl && previewModalHtml) {
                    previewModalHtml.innerHTML = '<div class="text-sm text-slate-500">Chargement de la prévisualisation...</div>';
                    previewModalHtml.classList.remove('hidden');
                    openModal(previewModal);

                    fetch(inlineUrl).then(function(response) {
                        if (!response.ok) {
                            throw new Error('Prévisualisation indisponible.');
                        }
                        return response.arrayBuffer();
                    }).then(function(buffer) {
                        if (previewKind === 'word') {
                            return loadScriptOnce('mammoth', previewAssetUrls.mammoth).then(function() {
                                return window.mammoth.convertToHtml({
                                    arrayBuffer: buffer
                                });
                            }).then(function(result) {
                                previewModalHtml.innerHTML = '<article class="prose prose-slate max-w-none">' + (result.value || '<p>Document vide.</p>') + '</article>';
                            });
                        }
                        return loadScriptOnce('xlsx', previewAssetUrls.xlsx).then(function() {
                            const workbook = window.XLSX.read(buffer, {
                                type: 'array'
                            });
                            const firstSheetName = workbook.SheetNames[0];
                            const firstSheet = firstSheetName ? workbook.Sheets[firstSheetName] : null;
                            if (!firstSheet) {
                                previewModalHtml.innerHTML = '<div class="text-sm text-slate-500">Feuille vide.</div>';
                                return;
                            }
                            const html = window.XLSX.utils.sheet_to_html(firstSheet, {
                                editable: false,
                                header: '',
                                footer: ''
                            });
                            previewModalHtml.innerHTML = '<div class="overflow-auto">' + html + '</div>';
                        });
                    }).catch(function() {
                        showPreviewFallback('Prévisualisation indisponible pour ce document Word/Excel.');
                    });
                    return;
                }

                if (previewKind === 'archive_zip' && previewUrl && previewModalHtml) {
                    previewModalHtml.innerHTML = '<div class="text-sm text-slate-500">Chargement du contenu de l\'archive ZIP...</div>';
                    previewModalHtml.classList.remove('hidden');
                    openModal(previewModal);
                    fetch(previewUrl, {
                        headers: {
                            'Accept': 'application/json'
                        }
                    }).then(function(response) {
                        return response.json();
                    }).then(function(payload) {
                        if (!payload || !payload.success) {
                            throw new Error(payload && payload.message ? payload.message : 'Exploration ZIP indisponible.');
                        }
                        renderArchivePreview(previewModalHtml, payload, downloadUrl);
                    }).catch(function(error) {
                        showPreviewFallback(error.message || 'Impossible d\'explorer cette archive ZIP.');
                    });
                    return;
                }

                if (previewKind === 'archive_download') {
                    showPreviewFallback('La prévisualisation interne n\'est pas disponible pour les archives RAR ou 7Z sur ce serveur. Utilisez Télécharger pour récupérer le fichier.');
                    return;
                }

                if (previewKind === 'executable') {
                    showPreviewFallback('Les exécutables et scripts ne sont pas prévisualisés depuis l\'interface. Ils peuvent en revanche être partagés et téléchargés selon les droits accordés.');
                    return;
                }

                showPreviewFallback('Prévisualisation indisponible pour ce format. Le téléchargement direct est désactivé depuis cette interface.');
            }

            function setSelected(item) {
                itemNodes.forEach(function(node) {
                    updateItemCheckedVisual(node);
                });

                if (!item) {
                    if (selectionPanel) selectionPanel.classList.add('hidden');
                    if (selectionEmptyState) selectionEmptyState.classList.remove('hidden');
                    if (multiSelectionPanel) multiSelectionPanel.classList.add('hidden');
                    if (selectionPreview) {
                        selectionPreview.classList.add('hidden');
                        selectionPreview.innerHTML = '';
                    }
                    if (selectionHistoryBlock) {
                        selectionHistoryBlock.classList.add('hidden');
                    }
                    if (selectionDownloadButton) {
                        selectionDownloadButton.classList.add('hidden');
                        selectionDownloadButton.removeAttribute('href');
                        selectionDownloadButton.removeAttribute('download');
                    }
                    hideRightPanel();
                    return;
                }

                if (multiSelectionPanel) multiSelectionPanel.classList.add('hidden');
                showRightPanel();
                const isFolder = item.dataset.itemType === 'folder';
                if (selectionPanel) selectionPanel.classList.remove('hidden');
                if (selectionEmptyState) selectionEmptyState.classList.add('hidden');
                if (selectionName) selectionName.textContent = item.dataset.itemName || 'Élément';
                if (selectionType) selectionType.textContent = isFolder ? 'Dossier' : (item.dataset.itemLabel || 'Document');
                if (selectionId) selectionId.textContent = item.dataset.itemId || '-';
                if (selectionIcon) {
                    selectionIcon.className = 'flex h-11 w-11 items-center justify-center rounded-2xl ' + (isFolder ? 'bg-amber-100 text-amber-700' : (item.dataset.itemIconWrap || 'bg-slate-100 text-slate-700'));
                    selectionIcon.innerHTML = '<i class="fas ' + (isFolder ? 'fa-folder' : (item.dataset.itemIcon || 'fa-file-lines')) + '"></i>';
                }
                if (selectionOpenLabel) selectionOpenLabel.textContent = isFolder ? 'Ouvrir le dossier' : 'Prévisualiser';
                if (selectionDownloadButton) {
                    const downloadUrl = item.dataset.openUrl || '';
                    selectionDownloadButton.classList.toggle('hidden', isFolder || !downloadUrl);
                    if (!isFolder && downloadUrl) {
                        selectionDownloadButton.href = downloadUrl;
                        selectionDownloadButton.setAttribute('download', item.dataset.itemFileName || '');
                    } else {
                        selectionDownloadButton.removeAttribute('href');
                        selectionDownloadButton.removeAttribute('download');
                    }
                }
                if (selectionOpenButton) {
                    const previewKind = item.dataset.itemPreviewKind || '';
                    const documentOpenLabel = previewKind === 'archive_zip' ?
                        'Explorer l\'archive' :
                        (previewKind === 'archive_download' ? 'Ouvrir l\'archive' :
                            (previewKind === 'video' ? 'Lire la vidéo' :
                                (previewKind === 'executable' ? 'Voir les détails' : 'Prévisualiser')));
                    if (!isFolder && selectionOpenLabel) {
                        selectionOpenLabel.textContent = documentOpenLabel;
                    }
                    selectionOpenButton.textContent = isFolder ? 'Ouvrir le dossier' : documentOpenLabel;
                    selectionOpenButton.onclick = function() {
                        openItem(item);
                    };
                }
                if (selectionShareButton) {
                    selectionShareButton.classList.remove('hidden');
                    selectionShareButton.onclick = function() {
                        openShareModal(item);
                    };
                }

                if (selectionHistoryBlock) {
                    selectionHistoryBlock.classList.toggle('hidden', isFolder);
                }
                if (!isFolder) {
                    loadSelectionHistory(item.dataset.itemId || '');
                }

                if (selectionPreview) {
                    const previewKind = item.dataset.itemPreviewKind || '';
                    const previewUrl = item.dataset.itemPreviewUrl || '';
                    if (!isFolder && previewKind === 'image' && previewUrl) {
                        selectionPreview.innerHTML = '<img src="' + previewUrl + '" alt="Aperçu" class="h-56 w-full object-cover">';
                        selectionPreview.classList.remove('hidden');
                    } else if (!isFolder && previewKind === 'video' && previewUrl) {
                        selectionPreview.innerHTML = '<video src="' + previewUrl + '" controls preload="metadata" class="h-56 w-full rounded-2xl bg-black object-contain"></video>';
                        selectionPreview.classList.remove('hidden');
                    } else if (!isFolder && previewKind === 'pdf' && previewUrl) {
                        renderPdfPreview(selectionPreview, previewUrl, {
                            compact: true
                        });
                    } else {
                        selectionPreview.classList.add('hidden');
                        selectionPreview.innerHTML = '';
                    }
                }
            }

            function positionMenu(x, y) {
                if (!menu) {
                    return;
                }

                menu.classList.remove('hidden');
                menu.style.left = '0px';
                menu.style.top = '0px';

                const rect = menu.getBoundingClientRect();
                const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 0;
                const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
                const gap = 8;

                let nextLeft = Number(x) || 0;
                let nextTop = Number(y) || 0;

                if (nextLeft + rect.width + gap > viewportWidth) {
                    nextLeft = Math.max(gap, viewportWidth - rect.width - gap);
                }
                if (nextTop + rect.height + gap > viewportHeight) {
                    nextTop = Math.max(gap, viewportHeight - rect.height - gap);
                }

                menu.style.left = nextLeft + 'px';
                menu.style.top = nextTop + 'px';
            }

            function updateContextMenu() {
                if (!menu) {
                    return;
                }
                const scope = activeItem ? activeItem.dataset.itemType : 'empty';
                menu.querySelectorAll('.js-menu-item, .js-menu-separator').forEach(function(node) {
                    const showForEmpty = node.classList.contains('js-menu-item-empty');
                    const showForFolder = node.classList.contains('js-menu-item-folder');
                    const showForFile = node.classList.contains('js-menu-item-file');
                    const shouldShow = (scope === 'empty' && showForEmpty) || (scope === 'folder' && showForFolder) || (scope === 'document' && showForFile);
                    node.classList.toggle('hidden', !shouldShow);
                });
                if (menuOpenLabel) {
                    menuOpenLabel.textContent = scope === 'document' ? 'Prévisualiser' : 'Ouvrir';
                }
                if (menuArchiveLabel && activeItem && (scope === 'document' || scope === 'folder')) {
                    menuArchiveLabel.textContent = activeItem.dataset.itemArchived === '1' ? 'Restaurer' : 'Archiver';
                }
                const deleteButton = menu.querySelector('[data-menu-action="delete"]');
                if (deleteButton) {
                    const canDeleteDefinitely = !!(activeItem && activeItem.dataset && activeItem.dataset.itemArchived === '1');
                    deleteButton.classList.toggle('hidden', !canDeleteDefinitely);
                }
            }

            function handleEmptyAreaContextMenu(event) {
                if (!contextZone || !contextZone.contains(event.target)) {
                    return;
                }
                if (event.target.closest('.js-explorer-item') || event.target.closest('#explorerContextMenu')) {
                    return;
                }
                event.preventDefault();
                activeItem = null;
                setSelected(null);
                updateContextMenu();
                positionMenu(event.clientX, event.clientY);
            }

            function openCreateFolderModal(folderId) {
                if (createFolderParent) {
                    createFolderParent.value = folderId || '';
                }
                if (createFolderParentHidden) {
                    createFolderParentHidden.value = folderId || '';
                }
                refreshAccessPreview(createFolderAccessPreview, {
                    entity_type: 'folder',
                    service_id: selectedServiceId(document.getElementById('create-folder-form'))
                });
                openModal(createFolderModal);
            }

            function openUploadModal(folderId) {
                if (uploadFolderSelect) {
                    uploadFolderSelect.value = folderId || '';
                }
                if (uploadFolderSelectHidden) {
                    uploadFolderSelectHidden.value = folderId || '';
                }
                refreshAccessPreview(uploadAccessPreview, {
                    entity_type: 'document',
                    service_id: selectedServiceId(uploadForm)
                });
                openModal(uploadModal);
            }

            function openShareModal(item) {
                if (!shareModal || !shareForm || !item || !item.dataset || (item.dataset.itemType !== 'document' && item.dataset.itemType !== 'folder')) {
                    return;
                }
                const isFolder = item.dataset.itemType === 'folder';
                const technicalExecutableExtensions = <?= json_encode(array_values($technicalExecutableExtensions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                const shareableExecutableExtensions = <?= json_encode(array_values($shareableExecutableExtensions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
                shareForm.reset();
                shareForm.querySelector('input[name="entity_type"]').value = isFolder ? 'folder' : 'document';
                shareForm.querySelector('input[name="document_id"]').value = isFolder ? '' : (item.dataset.itemId || '');
                shareForm.querySelector('input[name="folder_id"]').value = isFolder ? (item.dataset.itemId || '') : '';
                if (shareTargetLabel) {
                    shareTargetLabel.textContent = isFolder ? 'Dossier' : 'Document';
                }
                if (shareDocumentName) {
                    shareDocumentName.textContent = item.dataset.itemName || (isFolder ? 'Dossier' : 'Document');
                }
                const editCheckbox = shareForm.querySelector('input[name="can_edit"]');
                const reshareCheckbox = shareForm.querySelector('input[name="can_reshare"]');
                const downloadCheckbox = shareForm.querySelector('input[name="allow_download"]');
                const submitButton = shareForm.querySelector('button[type="submit"]');
                const executableNote = document.getElementById('share-executable-note');
                const fileName = String(item.dataset.itemFileName || item.dataset.itemName || '');
                const extension = fileName.includes('.') ? fileName.split('.').pop().toLowerCase() : '';
                const isTechnicalExecutable = !isFolder && technicalExecutableExtensions.indexOf(extension) !== -1;
                const isShareableExecutable = isTechnicalExecutable && shareableExecutableExtensions.indexOf(extension) !== -1;
                if (editCheckbox) {
                    editCheckbox.checked = false;
                    editCheckbox.disabled = isFolder || isTechnicalExecutable;
                    editCheckbox.closest('label').classList.toggle('opacity-50', isFolder || isTechnicalExecutable);
                }
                if (downloadCheckbox) {
                    if (isShareableExecutable) {
                        downloadCheckbox.checked = true;
                        downloadCheckbox.disabled = true;
                        downloadCheckbox.closest('label').classList.add('opacity-70');
                    } else {
                        downloadCheckbox.disabled = false;
                        downloadCheckbox.closest('label').classList.remove('opacity-70');
                    }
                }
                if (reshareCheckbox) {
                    reshareCheckbox.disabled = false;
                    reshareCheckbox.closest('label').classList.remove('opacity-50');
                }
                if (shareFolderEditNote) {
                    shareFolderEditNote.classList.toggle('hidden', !isFolder);
                }
                if (executableNote) {
                    executableNote.classList.add('hidden');
                    executableNote.textContent = '';
                }
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.classList.remove('cursor-not-allowed', 'opacity-60');
                }
                if (isTechnicalExecutable) {
                    if (executableNote) {
                        executableNote.classList.remove('hidden');
                        executableNote.textContent = isShareableExecutable ?
                            'Partage technique sécurisé: seuls les fichiers EXE et MSI validés peuvent être partagés. Le téléchargement est requis et l\'édition publique est désactivée.' :
                            'Partage refusé pour ce type de fichier. Les scripts BAT, CMD et PS1 restent disponibles en interne mais ne peuvent pas être exposés par lien public.';
                    }
                    if (isShareableExecutable && !downloadCheckbox && executableNote) {
                        executableNote.textContent = 'Partage refusé: ce type d\'exécutable exige aussi le droit de téléchargement sur le lien, indisponible avec votre profil actuel.';
                    }
                    if ((!isShareableExecutable || !downloadCheckbox) && submitButton) {
                        submitButton.disabled = true;
                        submitButton.classList.add('cursor-not-allowed', 'opacity-60');
                    }
                } else if (isFolder && executableNote) {
                    executableNote.classList.remove('hidden');
                    executableNote.textContent = 'Rappel sécurité: le partage de dossier sera refusé si ce dossier contient un script BAT/CMD/PS1 ou un exécutable technique non approuvé. Si des EXE ou MSI approuvés sont présents, le téléchargement devra être activé.';
                }
                if (shareResult) {
                    shareResult.classList.add('hidden');
                }
                if (shareResultRecipient) {
                    shareResultRecipient.innerHTML = '';
                }
                openModal(shareModal);
            }

            function openAccessRightsModal(item) {
                if (!accessRightsModal || !accessRightsForm || !item || !item.dataset || (item.dataset.itemType !== 'document' && item.dataset.itemType !== 'folder')) {
                    return;
                }
                accessRightsEntries = [];
                accessRightsForm.reset();
                accessRightsForm.querySelector('input[name="entity_type"]').value = item.dataset.itemType;
                accessRightsForm.querySelector('input[name="entity_id"]').value = item.dataset.itemId || '';
                if (accessRightsSubjectType) accessRightsSubjectType.value = 'service';
                const itemServiceId = item.dataset.serviceId && item.dataset.serviceId !== '0' ? item.dataset.serviceId : '';
                if (accessRightsServiceSelect) accessRightsServiceSelect.value = itemServiceId || (defaultDocumentationServiceId ? String(defaultDocumentationServiceId) : '');
                syncAccessRightsSubject();
                setAccessRightsFormRights(defaultAclRightsForSubject());
                if (accessRightsTarget) {
                    accessRightsTarget.textContent = (item.dataset.itemType === 'folder' ? 'Dossier : ' : 'Document : ') + (item.dataset.itemName || '');
                }
                if (accessRightsResult) {
                    accessRightsResult.classList.add('hidden');
                    accessRightsResult.textContent = '';
                }
                loadAccessRightsEntries();
                refreshSelectedAclPreview();
                refreshAccessPreview(accessRightsCurrentUsers, {
                    entity_type: item.dataset.itemType,
                    entity_id: item.dataset.itemId || ''
                });
                openModal(accessRightsModal);
            }

            function normalizeAclBool(value) {
                return value === true || value === 1 || value === '1' || value === 'true';
            }

            function defaultAclRightsForSubject() {
                const isService = !accessRightsSubjectType || accessRightsSubjectType.value !== 'user';
                return {
                    can_view: true,
                    can_edit: isService,
                    can_download: isService,
                    can_delete: false,
                    can_share: isService,
                    can_archive: isService
                };
            }

            function rightsFromAclEntry(entry) {
                const rights = {};
                accessRightKeys.forEach(function(key) {
                    rights[key] = normalizeAclBool(entry && entry[key]);
                });
                return rights;
            }

            function setAccessRightsFormRights(rights) {
                if (!accessRightsForm) return;
                const nextRights = Object.assign(defaultAclRightsForSubject(), rights || {});
                if (!nextRights.can_view) {
                    accessRightKeys.forEach(function(key) {
                        nextRights[key] = false;
                    });
                }
                accessRightKeys.forEach(function(key) {
                    const input = accessRightsForm.querySelector('input[name="' + key + '"]');
                    if (input) input.checked = !!nextRights[key];
                });
            }

            function currentAclEntry() {
                syncAccessRightsSubject();
                const subjectType = accessRightsSubjectType ? accessRightsSubjectType.value : 'service';
                const subjectId = accessRightsSubjectId ? String(accessRightsSubjectId.value || '') : '';
                if (!subjectId) return null;
                return (accessRightsEntries || []).find(function(entry) {
                    return String(entry.subject_type || '') === subjectType && String(entry.subject_id || '') === subjectId;
                }) || null;
            }

            function applyCurrentSubjectAclRights() {
                const entry = currentAclEntry();
                setAccessRightsFormRights(entry ? rightsFromAclEntry(entry) : defaultAclRightsForSubject());
            }

            function collectAccessRightsFormRights() {
                const rights = {};
                accessRightKeys.forEach(function(key) {
                    const input = accessRightsForm ? accessRightsForm.querySelector('input[name="' + key + '"]') : null;
                    rights[key] = !!(input && input.checked);
                });
                if (!rights.can_view) {
                    accessRightKeys.forEach(function(key) {
                        rights[key] = false;
                    });
                }
                return rights;
            }

            function markAccessRightsSaveState(message, isError) {
                if (!accessRightsResult) return;
                accessRightsResult.classList.remove('hidden', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-800', 'border-rose-200', 'bg-rose-50', 'text-rose-800', 'border-sky-200', 'bg-sky-50', 'text-sky-800');
                if (isError) {
                    accessRightsResult.classList.add('border-rose-200', 'bg-rose-50', 'text-rose-800');
                } else {
                    accessRightsResult.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-800');
                }
                accessRightsResult.textContent = message || '';
            }

            function updateActiveItemServiceAfterAclSave(subjectType, subjectId, rights) {
                const entityType = accessRightsForm ? accessRightsForm.querySelector('input[name="entity_type"]').value : '';
                const entityId = accessRightsForm ? accessRightsForm.querySelector('input[name="entity_id"]').value : '';
                if (entityType !== 'folder' || subjectType !== 'service' || !rights.can_view || !subjectId) return;
                itemNodes.forEach(function(item) {
                    if (item.dataset && item.dataset.itemType === 'folder' && String(item.dataset.itemId || '') === String(entityId || '')) {
                        item.dataset.serviceId = String(subjectId);
                    }
                });
            }

            function saveCurrentScopeAcl(options) {
                if (!accessRightsForm) return window.Promise.resolve(null);
                options = options || {};
                syncAccessRightsSubject();
                const subjectType = accessRightsSubjectType ? accessRightsSubjectType.value : 'service';
                const subjectId = accessRightsSubjectId ? accessRightsSubjectId.value : '';
                if (!subjectId) {
                    showNotice('Port&eacute;e manquante', 'Choisissez un service ou un utilisateur.');
                    return window.Promise.resolve(null);
                }

                const rights = collectAccessRightsFormRights();
                const formData = new window.FormData();
                formData.append('action', 'save_scope_acl');
                formData.append('entity_type', accessRightsForm.querySelector('input[name="entity_type"]').value || 'document');
                formData.append('entity_id', accessRightsForm.querySelector('input[name="entity_id"]').value || '');
                formData.append('subject_type', subjectType);
                formData.append('subject_id', subjectId);
                accessRightKeys.forEach(function(key) {
                    formData.append(key, rights[key] ? '1' : '');
                });

                if (options.showSaving) {
                    markAccessRightsSaveState('Enregistrement des droits...', false);
                }

                return fetch('documentation_action_api.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json'
                    }
                }).then(function(response) {
                    return response.json().then(function(payload) {
                        if (!response.ok || !payload.success) {
                            throw new Error(payload.message || 'Enregistrement impossible.');
                        }
                        return payload;
                    });
                }).then(function(payload) {
                    if (payload.entries) {
                        accessRightsEntries = payload.entries;
                        applyCurrentSubjectAclRights();
                    }
                    updateActiveItemServiceAfterAclSave(subjectType, subjectId, rights);
                    if (!options.silent) {
                        markAccessRightsSaveState(payload.message || 'Droits enregistres.', false);
                    }
                    refreshSelectedAclPreview();
                    refreshAccessPreview(accessRightsCurrentUsers, {
                        entity_type: accessRightsForm.querySelector('input[name="entity_type"]').value || 'document',
                        entity_id: accessRightsForm.querySelector('input[name="entity_id"]').value || ''
                    });
                    return payload;
                }).catch(function(error) {
                    markAccessRightsSaveState(error.message || 'Enregistrement impossible.', true);
                    if (!options.silent) {
                        showNotice('Droits non enregistres', error.message || 'Enregistrement impossible.');
                    }
                    throw error;
                });
            }

            function loadAccessRightsEntries() {
                if (!accessRightsForm) return;
                const entityType = accessRightsForm.querySelector('input[name="entity_type"]').value || 'document';
                const entityId = accessRightsForm.querySelector('input[name="entity_id"]').value || '';
                if (!entityId) return;
                fetch('documentation_acl_api.php?' + new URLSearchParams({
                    entity_type: entityType,
                    entity_id: entityId
                }).toString(), {
                    headers: {
                        'Accept': 'application/json'
                    }
                }).then(function(response) {
                    return response.json();
                }).then(function(payload) {
                    if (!payload.success) {
                        throw new Error(payload.message || 'Lecture des droits impossible.');
                    }
                    accessRightsEntries = payload.entries || [];
                    applyCurrentSubjectAclRights();
                }).catch(function(error) {
                    showNotice('Droits indisponibles', error.message || 'Lecture des droits impossible.');
                });
            }

            function handleAccessRightsSubjectChange() {
                syncAccessRightsSubject();
                applyCurrentSubjectAclRights();
                refreshSelectedAclPreview();
            }

            function refreshSelectedAclPreview() {
                if (!accessRightsForm || !accessRightsSelectedUsers) return;
                syncAccessRightsSubject();
                const subjectType = accessRightsSubjectType ? accessRightsSubjectType.value : 'service';
                const subjectId = accessRightsSubjectId ? accessRightsSubjectId.value : '';
                if (!subjectId) {
                    accessRightsSelectedUsers.innerHTML = '<div class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-slate-500">Choisissez une port&eacute;e pour voir les personnes concern&eacute;es.</div>';
                    return;
                }
                const params = {
                    entity_type: accessRightsForm.querySelector('input[name="entity_type"]').value || 'document',
                    entity_id: accessRightsForm.querySelector('input[name="entity_id"]').value || '',
                };
                if (subjectType === 'user') {
                    params.user_id = subjectId;
                } else {
                    params.service_id = subjectId;
                }
                refreshAccessPreview(accessRightsSelectedUsers, params);
            }

            function syncAccessRightsSubject() {
                if (!accessRightsSubjectType || !accessRightsSubjectId) return;
                const isUser = accessRightsSubjectType.value === 'user';
                if (accessRightsServiceSelect) accessRightsServiceSelect.classList.toggle('hidden', isUser);
                if (accessRightsUserSelect) accessRightsUserSelect.classList.toggle('hidden', !isUser);
                accessRightsSubjectId.value = isUser ? (accessRightsUserSelect ? accessRightsUserSelect.value : '') : (accessRightsServiceSelect ? accessRightsServiceSelect.value : '');
            }

            function saveUserRights(userId, rights, options) {
                if (!accessRightsForm || !userId) return;
                options = options || {};
                const entityType = accessRightsForm.querySelector('input[name="entity_type"]').value || 'document';
                const entityId = accessRightsForm.querySelector('input[name="entity_id"]').value || '';
                if (!entityId) return;

                const formData = new FormData();
                formData.append('entity_type', entityType);
                formData.append('entity_id', entityId);
                formData.append('subject_type', 'user');
                formData.append('subject_id', userId);
                formData.append('can_view', rights.view ? '1' : '');
                formData.append('can_edit', rights.edit ? '1' : '');
                formData.append('can_download', rights.download ? '1' : '');
                formData.append('can_delete', rights.delete ? '1' : '');
                formData.append('can_share', rights.share ? '1' : '');
                formData.append('can_archive', rights.archive ? '1' : '');

                fetch('documentation_acl_api.php', {
                    method: 'POST',
                    body: formData
                }).then(function(response) {
                    return response.json();
                }).then(function(payload) {
                    if (!payload.success) {
                        throw new Error(payload.message || 'Retrait impossible.');
                    }
                    if (payload.entries) {
                        accessRightsEntries = payload.entries;
                        applyCurrentSubjectAclRights();
                    }
                    if (!options.skipRefresh) {
                        refreshAccessPreview(accessRightsCurrentUsers, {
                            entity_type: entityType,
                            entity_id: entityId
                        });
                    }
                }).catch(function(error) {
                    if (typeof options.onError === 'function') {
                        options.onError(error);
                        return;
                    }
                    showNotice('Acc&egrave;s non retir&eacute;', error.message || 'Retrait impossible.');
                });
            }

            function collectUserRights(container) {
                const rights = {
                    view: false,
                    edit: false,
                    download: false,
                    delete: false,
                    share: false,
                    archive: false
                };
                if (!container) return rights;
                Object.keys(rights).forEach(function(key) {
                    const button = container.querySelector('[data-toggle-access-right="' + key + '"]');
                    rights[key] = !!(button && button.getAttribute('data-toggle-access-active') === '1');
                });
                return rights;
            }

            function toggleUserRight(button) {
                if (!button) return;
                const userId = button.getAttribute('data-toggle-access-user') || '';
                const right = button.getAttribute('data-toggle-access-right') || '';
                const container = button.closest('.rounded-xl');
                const rights = collectUserRights(container);
                const nextActive = button.getAttribute('data-toggle-access-active') !== '1';
                const previousRights = Object.assign({}, rights);
                rights[right] = nextActive;
                if (right === 'view' && !nextActive) {
                    Object.keys(rights).forEach(function(key) {
                        rights[key] = false;
                    });
                }
                if (right !== 'view' && nextActive) {
                    rights.view = true;
                }
                applyRightsToUserRow(container, rights);
                saveUserRights(userId, rights, {
                    skipRefresh: true,
                    onError: function(error) {
                        applyRightsToUserRow(container, previousRights);
                        showNotice('Droit non enregistr&eacute;', error.message || 'Enregistrement impossible.');
                    }
                });
            }

            function removeAllUserRights(userId) {
                saveUserRights(userId, {
                    view: false,
                    edit: false,
                    download: false,
                    delete: false,
                    share: false,
                    archive: false
                });
            }

            function applyRightsToUserRow(container, rights) {
                if (!container) return;
                Object.keys(rights).forEach(function(key) {
                    const button = container.querySelector('[data-toggle-access-right="' + key + '"]');
                    if (!button) return;
                    const active = !!rights[key];
                    button.setAttribute('data-toggle-access-active', active ? '1' : '0');
                    button.classList.toggle('border-emerald-200', active);
                    button.classList.toggle('bg-emerald-50', active);
                    button.classList.toggle('text-emerald-800', active);
                    button.classList.toggle('hover:bg-emerald-100', active);
                    button.classList.toggle('border-slate-200', !active);
                    button.classList.toggle('bg-slate-50', !active);
                    button.classList.toggle('text-slate-400', !active);
                    button.classList.toggle('hover:bg-slate-100', !active);
                    const icon = button.querySelector('i');
                    if (icon) {
                        icon.classList.toggle('fa-check', active);
                        icon.classList.toggle('fa-xmark', !active);
                    }
                    button.title = active ? 'Droit actif' : 'Droit retire';
                });
            }

            function badgeToneClass(tone) {
                if (tone === 'rose') return 'bg-rose-100 text-rose-800 border-rose-200';
                if (tone === 'amber') return 'bg-amber-100 text-amber-800 border-amber-200';
                if (tone === 'sky') return 'bg-sky-100 text-sky-800 border-sky-200';
                if (tone === 'violet') return 'bg-violet-100 text-violet-800 border-violet-200';
                return 'bg-emerald-100 text-emerald-800 border-emerald-200';
            }

            function renderAccessUsers(node, users, options) {
                if (!node) return;
                options = options || {};
                if (!users || !users.length) {
                    node.innerHTML = '<div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">Aucun utilisateur standard ne sera couvert par cette port&eacute;e. Les admins restent habilit&eacute;s.</div>';
                    return;
                }
                node.innerHTML = users.map(function(user) {
                    const badges = (user.badges || []).map(function(badge) {
                        return '<span class="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold ' + badgeToneClass(badge.tone || '') + '">' + escapeHtml(badge.label || '') + '</span>';
                    }).join(' ');
                    const rightLabels = {
                        view: 'Voir',
                        edit: 'Modifier',
                        download: 'Telecharger',
                        delete: 'Supprimer',
                        share: 'Partager',
                        archive: 'Archiver'
                    };
                    const rights = user.rights || {};
                    const rightsHtml = Object.keys(rightLabels).map(function(key) {
                        const active = !!rights[key];
                        const toggleAttrs = options.removable && user.can_remove ? ' data-toggle-access-user="' + String(user.id || '') + '" data-toggle-access-right="' + key + '" data-toggle-access-active="' + (active ? '1' : '0') + '"' : '';
                        const tone = active ? 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' : 'border-slate-200 bg-slate-50 text-slate-400 hover:bg-slate-100';
                        const icon = active ? 'fa-check' : 'fa-xmark';
                        const cursor = options.removable && user.can_remove ? ' cursor-pointer' : ' cursor-default';
                        return '<button type="button"' + toggleAttrs + ' class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold ' + tone + cursor + '" title="' + (active ? 'Droit actif' : 'Droit retire') + '">' +
                            '<i class="fas ' + icon + ' text-[10px]"></i>' + rightLabels[key] +
                            '</button>';
                    }).join(' ');
                    const meta = [user.email || '', user.service_label || '', user.role_label ? 'R&ocirc;le: ' + user.role_label : ''].filter(Boolean).join(' &bull; ');
                    const removeButton = options.removable && user.can_remove ? '<button type="button" data-remove-access-user="' + String(user.id || '') + '" data-remove-access-right="all" class="inline-flex items-center rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-100" title="Retirer tous les droits">Retirer tout</button>' : '';
                    const protectedNote = user.is_protected ? '<span class="rounded-full border border-slate-200 bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-500">Protege</span>' : '';
                    return '<div class="rounded-xl border border-slate-200 bg-white px-3 py-2">' +
                        '<div class="flex flex-wrap items-center justify-between gap-2">' +
                        '<div class="font-semibold text-slate-800">' + escapeHtml(user.name || '') + '</div>' +
                        '<div class="flex flex-wrap items-center gap-1">' + badges + protectedNote + removeButton + '</div>' +
                        '</div>' +
                        '<div class="mt-1 text-xs text-slate-500">' + meta + '</div>' +
                        '<div class="mt-2 flex flex-wrap gap-1">' + (rightsHtml || '<span class="text-xs text-slate-400">Aucun droit actif</span>') + '</div>' +
                        '</div>';
                }).join('');
            }

            function refreshAccessPreview(node, params) {
                if (!node) return;
                node.textContent = 'Calcul en cours...';
                const query = new URLSearchParams(params || {});
                fetch('documentation_acl_preview_api.php?' + query.toString(), {
                    headers: {
                        'Accept': 'application/json'
                    }
                }).then(function(response) {
                    return response.json();
                }).then(function(payload) {
                    if (!payload.success) {
                        throw new Error(payload.message || 'Apercu indisponible.');
                    }
                    renderAccessUsers(node, payload.users || [], {
                        removable: node === accessRightsCurrentUsers
                    });
                }).catch(function(error) {
                    node.innerHTML = '<div class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-rose-800">' + escapeHtml(error.message || 'Apercu indisponible.') + '</div>';
                });
            }

            function selectedServiceId(form) {
                if (!form) return defaultDocumentationServiceId || '';
                const select = form.querySelector('select[name="service_id"]');
                return select && select.value ? select.value : (defaultDocumentationServiceId || '');
            }

            function refreshCreationAccessPreviews() {
                refreshAccessPreview(createFolderAccessPreview, {
                    entity_type: 'folder',
                    service_id: selectedServiceId(document.getElementById('create-folder-form'))
                });
                refreshAccessPreview(uploadAccessPreview, {
                    entity_type: 'document',
                    service_id: selectedServiceId(uploadForm)
                });
            }

            function renderUploadQueue() {
                if (!uploadQueueNode || !uploadQueueEmpty) {
                    return;
                }
                if (!uploadQueue.length) {
                    uploadQueueNode.classList.add('hidden');
                    uploadQueueNode.innerHTML = '';
                    uploadQueueEmpty.classList.remove('hidden');
                    return;
                }
                uploadQueueEmpty.classList.add('hidden');
                uploadQueueNode.classList.remove('hidden');
                uploadQueueNode.innerHTML = uploadQueue.map(function(entry) {
                    const color = entry.status === 'success' ? 'bg-emerald-500' : entry.status === 'error' ? 'bg-rose-500' : entry.status === 'uploading' ? 'bg-sky-500' : 'bg-rose-400';
                    const badge = entry.status === 'success' ? 'Importé' : entry.status === 'error' ? 'Erreur' : entry.status === 'uploading' ? 'En cours' : 'En attente';
                    return '<div class="rounded-2xl border border-slate-200 bg-white p-3">' +
                        '<div class="flex items-start justify-between gap-3">' +
                        '<div class="min-w-0">' +
                        '<div class="truncate text-sm font-semibold text-slate-900">' + entry.name + '</div>' +
                        '<div class="truncate text-xs text-slate-500">' + entry.relativePath + '</div>' +
                        '</div>' +
                        '<span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-semibold text-slate-700">' + badge + '</span>' +
                        '</div>' +
                        '<div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div class="h-full ' + color + '" style="width:' + entry.progress + '%"></div></div>' +
                        '<div class="mt-2 text-xs text-slate-600">' + entry.message + '</div>' +
                        '</div>';
                }).join('');
            }

            function addFilesToQueue(files) {
                Array.from(files || []).forEach(function(file) {
                    if (!file || !file.name) {
                        return;
                    }
                    uploadQueue.push({
                        file: file,
                        name: file.name,
                        relativePath: file.webkitRelativePath || file.name,
                        status: 'pending',
                        progress: 0,
                        message: 'En attente d\'import'
                    });
                });
                renderUploadQueue();
            }

            function clearUploadQueue() {
                if (uploadRunning) {
                    return;
                }
                uploadQueue = [];
                if (uploadFilesInput) uploadFilesInput.value = '';
                if (uploadFolderInput) uploadFolderInput.value = '';
                renderUploadQueue();
            }

            function uploadNext() {
                if (uploadRunning || !uploadForm) {
                    return;
                }
                const entry = uploadQueue.find(function(item) {
                    return item.status === 'pending';
                });
                if (!entry) {
                    if (uploadQueue.length && uploadQueue.every(function(item) {
                            return item.status !== 'pending' && item.status !== 'uploading';
                        })) {
                        const hasSuccess = uploadQueue.some(function(item) {
                            return item.status === 'success';
                        });
                        setTimeout(function() {
                            closeModal(uploadModal);
                            if (hasSuccess) {
                                window.location.reload();
                            }
                        }, 650);
                    }
                    return;
                }
                uploadRunning = true;
                entry.status = 'uploading';
                entry.message = 'Import en cours...';
                renderUploadQueue();

                const performUpload = function(forceOverwrite) {
                    const formData = new window.FormData();
                    formData.append('document_file', entry.file, entry.file.name);
                    formData.append('folder_id', uploadFolderSelectHidden ? uploadFolderSelectHidden.value : (uploadFolderSelect ? uploadFolderSelect.value : ''));
                    formData.append('document_type', uploadForm.querySelector('[name="document_type"]') ? uploadForm.querySelector('[name="document_type"]').value : 'document');
                    formData.append('space_type', uploadForm.querySelector('[name="space_type"]') ? uploadForm.querySelector('[name="space_type"]').value : 'general');
                    formData.append('service_id', uploadForm.querySelector('[name="service_id"]') ? uploadForm.querySelector('[name="service_id"]').value : '');
                    formData.append('required_permission', uploadForm.querySelector('[name="required_permission"]') ? uploadForm.querySelector('[name="required_permission"]').value : '');
                    formData.append('relative_path', entry.relativePath);
                    if (forceOverwrite) {
                        formData.append('overwrite', '1');
                    }

                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'documentation_upload.php', true);
                    xhr.upload.addEventListener('progress', function(event) {
                        if (event.lengthComputable) {
                            entry.progress = Math.round((event.loaded / event.total) * 100);
                            entry.message = entry.progress + '% transféré';
                            renderUploadQueue();
                        }
                    });
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState !== 4) {
                            return;
                        }
                        uploadRunning = false;
                        let payload = null;
                        try {
                            payload = JSON.parse(xhr.responseText || '{}');
                        } catch (error) {
                            payload = null;
                        }
                        if (xhr.status >= 200 && xhr.status < 300 && payload && payload.success) {
                            entry.status = 'success';
                            entry.progress = 100;
                            entry.message = payload.message || 'Importé';
                        } else {
                            entry.status = 'error';
                            entry.progress = 100;
                            entry.message = payload && payload.message ? payload.message : 'Échec de l\'import';
                        }
                        renderUploadQueue();
                        uploadNext();
                    };
                    xhr.send(formData);
                };

                checkUploadConflict(entry, uploadFolderSelectHidden ? uploadFolderSelectHidden.value : (uploadFolderSelect ? uploadFolderSelect.value : '')).then(function(checkResult) {
                    if (checkResult && checkResult.hasConflict) {
                        return askDecision({
                            eyebrow: 'Conflit détecté',
                            title: 'Écraser le fichier existant ?',
                            message: 'Un fichier du même nom existe déjà dans le dossier cible. Voulez-vous l\'écraser ?',
                            confirmLabel: 'Écraser',
                            cancelLabel: 'Annuler',
                            confirmTone: 'danger'
                        }).then(function(confirmed) {
                            if (!confirmed) {
                                uploadRunning = false;
                                entry.status = 'error';
                                entry.progress = 100;
                                entry.message = 'Import annulé : un fichier du même nom existe déjà.';
                                renderUploadQueue();
                                uploadNext();
                                return;
                            }
                            performUpload(true);
                        });
                    }
                    performUpload(false);
                }).catch(function() {
                    performUpload(false);
                });
            }

            itemNodes.forEach(function(item) {
                const checkbox = getItemCheckbox(item);
                if (checkbox) {
                    checkbox.addEventListener('click', function(event) {
                        event.stopPropagation();
                    });
                    checkbox.addEventListener('change', function() {
                        updateItemCheckedVisual(item);
                        refreshSelectionUI();
                    });
                }

                item.addEventListener('click', function(event) {
                    if (event.target.closest('.js-item-checkbox')) {
                        return;
                    }
                    if (event.ctrlKey || event.metaKey) {
                        if (checkbox) {
                            checkbox.checked = !checkbox.checked;
                        }
                        updateItemCheckedVisual(item);
                        refreshSelectionUI();
                        return;
                    }
                    clearCheckedItems(item);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                    updateItemCheckedVisual(item);
                    activeItem = item;
                    refreshSelectionUI();
                });
                item.addEventListener('dblclick', function() {
                    openItem(item);
                });
                item.addEventListener('contextmenu', function(event) {
                    event.preventDefault();
                    if (checkbox && !checkbox.checked) {
                        clearCheckedItems(item);
                        checkbox.checked = true;
                        updateItemCheckedVisual(item);
                    }
                    refreshSelectionUI();
                    activeItem = item;
                    setSelected(item);
                    updateContextMenu();
                    positionMenu(event.clientX, event.clientY);
                });
                item.addEventListener('dragstart', function(event) {
                    draggedItem = item;
                    draggedItemsSnapshot = getDraggedItems(item);
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.dropEffect = 'move';
                        event.dataTransfer.setData('text/plain', item.dataset.itemId || '');
                    }
                });
                item.addEventListener('dragend', function() {
                    draggedItem = null;
                    draggedItemsSnapshot = [];
                    itemNodes.forEach(function(node) {
                        node.classList.remove('ring-2', 'ring-cyan-400');
                    });
                });
                if (item.dataset.itemType === 'folder') {
                    item.addEventListener('dragover', function(event) {
                        if (!draggedItem || draggedItem === item) return;
                        event.preventDefault();
                        if (event.dataTransfer) {
                            event.dataTransfer.dropEffect = 'move';
                        }
                        item.classList.add('ring-2', 'ring-cyan-400');
                    });
                    item.addEventListener('dragleave', function() {
                        item.classList.remove('ring-2', 'ring-cyan-400');
                    });
                    item.addEventListener('drop', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        item.classList.remove('ring-2', 'ring-cyan-400');
                        if (!draggedItem || draggedItem === item) return;
                        const targetFolderId = item.dataset.itemId || '';
                        moveSelectionToFolder(targetFolderId, draggedItem);
                    });
                }
            });

            if (selectAllItems) {
                selectAllItems.addEventListener('change', function() {
                    itemNodes.forEach(function(item) {
                        const checkbox = getItemCheckbox(item);
                        if (checkbox) {
                            checkbox.checked = selectAllItems.checked;
                        }
                        updateItemCheckedVisual(item);
                    });
                    refreshSelectionUI();
                });
            }

            if (bulkDeleteButton) {
                bulkDeleteButton.addEventListener('click', function() {
                    deleteCurrentSelection(getCheckedItems());
                });
            }

            if (bulkArchiveButton) {
                bulkArchiveButton.addEventListener('click', function() {
                    archiveCurrentSelection(getCheckedItems(), true);
                });
            }

            if (bulkRestoreButton) {
                bulkRestoreButton.addEventListener('click', function() {
                    archiveCurrentSelection(getCheckedItems(), false);
                });
            }

            if (bulkDownloadButton) {
                bulkDownloadButton.addEventListener('click', function() {
                    submitBulkDownload(getCheckedItems());
                });
            }

            document.querySelectorAll('.js-preview-trigger').forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const item = button.closest('.js-explorer-item');
                    if (item) {
                        activeItem = item;
                        setSelected(item);
                        openPreviewModal(item);
                    }
                });
            });

            if (previewFullscreenButton) {
                previewFullscreenButton.addEventListener('click', function() {
                    if (previewModalShell && previewModalShell.requestFullscreen) {
                        previewModalShell.requestFullscreen().catch(function() {});
                    }
                });
            }

            if (previewDefaultButton) {
                previewDefaultButton.addEventListener('click', function() {
                    if (document.fullscreenElement && document.exitFullscreen) {
                        document.exitFullscreen().catch(function() {});
                    }
                    if (previewModalContentWrapper) {
                        previewModalContentWrapper.scrollTop = 0;
                        previewModalContentWrapper.scrollLeft = 0;
                    }
                });
            }

            if (previewModalContentWrapper) {
                previewModalContentWrapper.addEventListener('contextmenu', function(event) {
                    if (event.target.closest('button, a')) {
                        return;
                    }
                    event.preventDefault();
                });
            }

            document.addEventListener('fullscreenchange', updatePreviewViewportState);
            updatePreviewViewportState();

            if (contextZone) {
                contextZone.addEventListener('contextmenu', function(event) {
                    handleEmptyAreaContextMenu(event);
                });
            }

            document.addEventListener('contextmenu', function(event) {
                handleEmptyAreaContextMenu(event);
            }, true);

            if (topCreateFolderButton) {
                topCreateFolderButton.addEventListener('click', function() {
                    openCreateFolderModal(surface ? surface.dataset.currentFolderId : '');
                });
            }
            if (topUploadButton) {
                topUploadButton.addEventListener('click', function() {
                    openUploadModal(surface ? surface.dataset.currentFolderId : '');
                });
            }
            if (topFiltersButton) {
                topFiltersButton.addEventListener('click', function() {
                    openModal(filtersModal);
                });
            }
            if (rightPanelClose) {
                rightPanelClose.addEventListener('click', function() {
                    activeItem = null;
                    setSelected(null);
                });
            }

            if (uploadFilesInput) uploadFilesInput.addEventListener('change', function() {
                addFilesToQueue(uploadFilesInput.files);
                uploadFilesInput.value = '';
            });
            if (uploadFolderInput) uploadFolderInput.addEventListener('change', function() {
                addFilesToQueue(uploadFolderInput.files);
                uploadFolderInput.value = '';
            });
            if (uploadClearButton) uploadClearButton.addEventListener('click', clearUploadQueue);
            if (uploadStartButton) uploadStartButton.addEventListener('click', uploadNext);

            if (shareForm) {
                shareForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    fetch('documentation_share_api.php', {
                        method: 'POST',
                        body: new FormData(shareForm)
                    }).then(function(response) {
                        return response.json();
                    }).then(function(payload) {
                        if (!payload.success) {
                            throw new Error(payload.message || 'Création du lien impossible.');
                        }
                        if (shareResult && shareResultUrl) {
                            shareResult.classList.remove('hidden');
                            shareResultUrl.value = payload.shareUrl || '';
                        }
                        renderPermissionBadges(shareResultPermissions, payload.permissions || {});
                        renderRecipientSummary(shareResultRecipient, payload.recipient || null);
                        if (activeItem && activeItem.dataset && activeItem.dataset.itemType === 'document') {
                            loadSelectionHistory(activeItem.dataset.itemId || '');
                        }
                    }).catch(function(error) {
                        showNotice('Création du lien impossible', error.message || 'Création du lien impossible.');
                    });
                });
            }
            if (shareCopyButton && shareResultUrl) {
                shareCopyButton.addEventListener('click', function() {
                    if (navigator.clipboard && shareResultUrl.value) {
                        navigator.clipboard.writeText(shareResultUrl.value);
                        return;
                    }
                    shareResultUrl.select();
                    document.execCommand('copy');
                });
            }
            if (accessRightsForm) {
                if (accessRightsSubjectType) accessRightsSubjectType.addEventListener('change', handleAccessRightsSubjectChange);
                if (accessRightsServiceSelect) accessRightsServiceSelect.addEventListener('change', handleAccessRightsSubjectChange);
                if (accessRightsUserSelect) accessRightsUserSelect.addEventListener('change', handleAccessRightsSubjectChange);
                accessRightKeys.forEach(function(key) {
                    const input = accessRightsForm.querySelector('input[name="' + key + '"]');
                    if (!input) return;
                    input.addEventListener('change', function() {
                        if (key === 'can_view' && !input.checked) {
                            accessRightKeys.forEach(function(otherKey) {
                                const otherInput = accessRightsForm.querySelector('input[name="' + otherKey + '"]');
                                if (otherInput) otherInput.checked = false;
                            });
                        } else if (key !== 'can_view' && input.checked) {
                            const viewInput = accessRightsForm.querySelector('input[name="can_view"]');
                            if (viewInput) viewInput.checked = true;
                        }
                        saveCurrentScopeAcl({
                            silent: true,
                            showSaving: true
                        }).catch(function() {});
                    });
                });
                accessRightsForm.addEventListener('submit', function(event) {
                    event.preventDefault();
                    saveCurrentScopeAcl({
                        silent: false,
                        showSaving: true
                    }).catch(function() {});
                });
            }
            if (accessRightsCurrentUsers) {
                accessRightsCurrentUsers.addEventListener('click', function(event) {
                    const toggleButton = event.target.closest('[data-toggle-access-user]');
                    if (toggleButton) {
                        toggleUserRight(toggleButton);
                        return;
                    }
                    const button = event.target.closest('[data-remove-access-user]');
                    if (!button) return;
                    const userId = button.getAttribute('data-remove-access-user') || '';
                    askDecision({
                        eyebrow: 'Retrait d\'acc&egrave;s',
                        title: 'Retirer cette personne ?',
                        message: 'Cette personne ne verra plus cet &eacute;l&eacute;ment via son service. Les administrateurs gardent leur acc&egrave;s global.',
                        confirmLabel: 'Retirer tout',
                        cancelLabel: 'Annuler',
                        confirmTone: 'danger'
                    }).then(function(confirmed) {
                        if (confirmed) {
                            removeAllUserRights(userId);
                        }
                    });
                });
            }
            document.querySelectorAll('#create-folder-form select[name="service_id"], #upload-form select[name="service_id"]').forEach(function(select) {
                select.addEventListener('change', refreshCreationAccessPreviews);
            });
            refreshCreationAccessPreviews();

            document.querySelectorAll('.js-inline-archive-form').forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    const archiveInput = form.querySelector('input[name="archive"]');
                    const archiveAction = !!(archiveInput && archiveInput.value === '1');
                    askDecision({
                        eyebrow: archiveAction ? 'Archivage' : 'Restauration',
                        title: archiveAction ? 'Archiver ce document ?' : 'Restaurer ce document ?',
                        message: archiveAction ?
                            'Le document sera retiré de l\'explorateur actif et placé dans les archives.' : 'Le document sera restauré dans l\'explorateur actif.',
                        confirmLabel: archiveAction ? 'Archiver' : 'Restaurer',
                        cancelLabel: 'Annuler'
                    }).then(function(confirmed) {
                        if (confirmed) {
                            form.submit();
                        }
                    });
                });
            });

            document.querySelectorAll('.js-archive-entry-restore').forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const item = button.closest('.js-explorer-item');
                    if (item) {
                        archiveCurrentSelection([item], false);
                    }
                });
            });

            document.querySelectorAll('.js-archive-entry-delete').forEach(function(button) {
                button.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    const item = button.closest('.js-explorer-item');
                    if (item) {
                        deleteCurrentSelection([item]);
                    }
                });
            });

            document.querySelectorAll('[data-close-modal]').forEach(function(button) {
                button.addEventListener('click', function() {
                    closeModal(document.getElementById(button.getAttribute('data-close-modal')));
                });
            });

            if (decisionModalConfirm) {
                decisionModalConfirm.addEventListener('click', function() {
                    resolveDecisionModal(true);
                });
            }
            if (decisionModalCancel) {
                decisionModalCancel.addEventListener('click', function() {
                    resolveDecisionModal(false);
                });
            }
            if (decisionModalClose) {
                decisionModalClose.addEventListener('click', function() {
                    resolveDecisionModal(false);
                });
            }

            document.querySelectorAll('[data-menu-action]').forEach(function(button) {
                button.addEventListener('click', function() {
                    const action = button.getAttribute('data-menu-action');
                    const folderContextId = activeItem && activeItem.dataset && activeItem.dataset.itemType === 'folder' ?
                        activeItem.dataset.itemId :
                        ((activeItem && activeItem.dataset ? activeItem.dataset.folderId : '') || (surface ? surface.dataset.currentFolderId : '') || '');

                    if (action === 'open' && activeItem) openItem(activeItem);
                    if (action === 'create-folder') openCreateFolderModal(folderContextId);
                    if (action === 'import') openUploadModal(folderContextId);
                    if (action === 'share' && activeItem) openShareModal(activeItem);
                    if (action === 'access-rights' && activeItem) openAccessRightsModal(activeItem);

                    if (action === 'rename' && activeItem && renameForm) {
                        const isFolder = activeItem.dataset.itemType === 'folder';
                        renameForm.querySelector('input[name="action"]').value = isFolder ? 'rename_folder' : 'rename_document';
                        renameForm.querySelector('input[name="folder_id"]').value = isFolder ? activeItem.dataset.itemId : '';
                        renameForm.querySelector('input[name="document_id"]').value = isFolder ? '' : activeItem.dataset.itemId;
                        if (renameFolderField) renameFolderField.classList.toggle('hidden', !isFolder);
                        if (renameDocumentField) renameDocumentField.classList.toggle('hidden', isFolder);
                        if (isFolder && renameNameInput) renameNameInput.value = activeItem.dataset.itemName || '';
                        if (!isFolder && renameTitleInput) renameTitleInput.value = activeItem.dataset.itemName || '';
                        openModal(renameModal);
                    }

                    if (action === 'move' && activeItem && moveForm) {
                        const isFolder = activeItem.dataset.itemType === 'folder';
                        moveForm.querySelector('input[name="action"]').value = isFolder ? 'move_folder' : 'move_document';
                        moveForm.querySelector('input[name="source_folder_id"]').value = isFolder ? activeItem.dataset.itemId : '';
                        moveForm.querySelector('input[name="document_id"]').value = isFolder ? '' : activeItem.dataset.itemId;
                        if (moveFolderField) moveFolderField.classList.toggle('hidden', !isFolder);
                        if (moveDocumentField) moveDocumentField.classList.toggle('hidden', isFolder);
                        if (isFolder && moveParentSelect) moveParentSelect.value = activeItem.dataset.parentId || '';
                        if (!isFolder && moveDocumentSelect) moveDocumentSelect.value = activeItem.dataset.folderId || '';
                        openModal(moveModal);
                    }

                    if (action === 'archive' && activeItem) {
                        archiveCurrentSelection([activeItem], activeItem.dataset.itemArchived !== '1');
                    }

                    if (action === 'delete' && activeItem) {
                        deleteCurrentSelection([activeItem]);
                    }

                    hideMenu();
                });
            });

            document.addEventListener('click', function(event) {
                if (!event.target.closest('#explorerContextMenu')) {
                    hideMenu();
                }
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    hideMenu();
                    if (document.fullscreenElement && document.exitFullscreen) {
                        document.exitFullscreen().catch(function() {});
                    }
                    closeModal(filtersModal);
                    closeModal(createFolderModal);
                    closeModal(uploadModal);
                    closeModal(shareModal);
                    closeModal(renameModal);
                    closeModal(moveModal);
                    closeModal(previewModal);
                }
            });

            refreshSelectionUI();
            renderUploadQueue();
        });
    </script>

    <?php require_once dirname(__DIR__) . '/decaissement/includes/footer.php'; ?>
