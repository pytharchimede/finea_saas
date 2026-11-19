<?php
require_once dirname(__DIR__) . '/auth_check.php';
require_once dirname(__DIR__) . '/model/Database.php';
require_once dirname(__DIR__) . '/model/AuditTrail.php';
require_once dirname(__DIR__) . '/model/DocumentManager.php';
require_once dirname(__DIR__) . '/controllers/NotificationController.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Méthode non autorisée.');
    }

    $permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
    $canShare = !empty($permissions['document_share']) || !empty($permissions['document_edit']) || !empty($permissions['document_admin']) || !empty($permissions['acces_admin']);
    $canDownload = !empty($permissions['document_download']) || !empty($permissions['document_export']) || !empty($permissions['document_admin']) || !empty($permissions['acces_admin']);
    if (!$canShare) {
        throw new RuntimeException('Vous n\'avez pas le droit de partager des documents.');
    }

    $entityType = trim((string)($_POST['entity_type'] ?? 'document'));
    $documentId = (int)($_POST['document_id'] ?? 0);
    $folderId = (int)($_POST['folder_id'] ?? 0);
    if ($entityType !== 'folder' && $entityType !== 'document') {
        throw new RuntimeException('Type de partage invalide.');
    }
    if ($entityType === 'document' && $documentId <= 0) {
        throw new RuntimeException('Document introuvable.');
    }
    if ($entityType === 'folder' && $folderId <= 0) {
        throw new RuntimeException('Dossier introuvable.');
    }

    $recipientUserId = (int)($_POST['recipient_user_id'] ?? 0);

    $sharePermissions = [
        'can_view' => true,
        'allow_download' => $canDownload && !empty($_POST['allow_download']),
        'can_download' => $canDownload && !empty($_POST['allow_download']),
        'can_edit' => $entityType === 'document' && !empty($_POST['can_edit']),
        'can_share' => !empty($_POST['can_reshare']),
        'can_reshare' => !empty($_POST['can_reshare']),
    ];
    $expiresAt = trim((string)($_POST['expires_at'] ?? ''));

    $pdo = \Database::getConnection();
    $manager = new \DocumentManager($pdo);
    $manager->ensureTables();
    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($entityType === 'folder') {
        $manager->assertFolderAccess($folderId, 'share', $userId, $permissions);
    } else {
        $manager->assertDocumentAccess($documentId, 'share', $userId, $permissions);
    }

    if ($entityType === 'document') {
        $document = $manager->getDocumentById($documentId);
        if (!$document) {
            throw new RuntimeException('Document introuvable.');
        }
        $currentVersion = $manager->getDownloadableVersion((int)($document['current_version_id'] ?? 0), $permissions, $userId, 'view');
        $extension = strtolower((string)($currentVersion['extension'] ?? pathinfo((string)($currentVersion['original_name'] ?? ''), PATHINFO_EXTENSION)));
        $isTechnicalExecutable = in_array($extension, $manager->getTechnicalExecutableExtensions(), true);
        if ($isTechnicalExecutable && !in_array($extension, $manager->getShareableExecutableExtensions(), true)) {
            throw new RuntimeException('Partage refusé: seuls les exécutables EXE et MSI validés sont autorisés.');
        }
        if ($isTechnicalExecutable && !$canDownload) {
            throw new RuntimeException('Partage refusé: un exécutable validé doit être partagé avec téléchargement autorisé.');
        }
    }

    $recipient = null;
    if ($recipientUserId > 0) {
        $recipient = $manager->getInternalShareRecipientById($recipientUserId);
        if (!$recipient) {
            throw new RuntimeException('Le destinataire interne sélectionné est introuvable ou inactif.');
        }
    }

    if ($entityType === 'folder') {
        $share = $manager->createFolderShare($folderId, $userId, $sharePermissions, $expiresAt !== '' ? $expiresAt : null, $recipient);
        $target = $manager->getFolderById($folderId);
        if ($target) {
            $manager->logFolderAccess('share', $target, $userId, 'success', $sharePermissions);
        }
    } else {
        $share = $manager->createShare($documentId, $userId, $sharePermissions, $expiresAt !== '' ? $expiresAt : null, $recipient);
        $target = $manager->getDocumentById($documentId);
        if ($target) {
            $manager->logDocumentAccess('share', $target, $userId, 'success', $sharePermissions);
        }
    }

    \AuditTrail::log('documentation_share_created', [
        'module' => 'documentation',
        'action' => 'create_share',
        'entity_type' => $entityType === 'folder' ? 'doc_folder' : 'doc_document',
        'entity_id' => (string)($entityType === 'folder' ? $folderId : $documentId),
        'details' => [
            'entity_type' => $entityType,
            'allow_download' => $share['allow_download'] ?? false,
            'can_view' => $share['can_view'] ?? true,
            'can_edit' => $share['can_edit'] ?? false,
            'can_reshare' => $share['can_reshare'] ?? false,
            'recipient_user_id' => $share['recipient_user_id'] ?? null,
            'recipient_name' => $share['recipient_name'] ?? null,
            'recipient_email' => $share['recipient_email'] ?? null,
            'expires_at' => $share['expires_at'] ?? null,
        ],
    ]);

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $sharePage = $entityType === 'folder' ? '/documentation_folder_share.php' : '/documentation_share.php';
    $shareUrl = $scheme . '://' . $host . ($basePath !== '' ? $basePath : '') . $sharePage . '?t=' . rawurlencode((string)$share['token']);

    if (!empty($recipient['id'])) {
        try {
            $notificationManager = new \NotificationController($pdo);
            $notificationManager->notifyDocumentShareCreated((int)$recipient['id'], [
                'document_id' => $entityType === 'folder' ? $folderId : $documentId,
                'document_title' => (string)($entityType === 'folder' ? ($target['name'] ?? 'Dossier') : ($target['title'] ?? 'Document')),
                'token' => (string)$share['token'],
                'share_url' => $shareUrl,
                'creator_name' => (string)($_SESSION['user_name'] ?? ''),
                'permissions' => $sharePermissions,
            ]);
        } catch (Throwable $notificationError) {
            error_log('Documentation share notification error: ' . $notificationError->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'shareUrl' => $shareUrl,
        'entityType' => $entityType,
        'expiresAt' => $share['expires_at'] ?? null,
        'permissions' => [
            'canView' => !empty($share['can_view']),
            'canDownload' => !empty($share['allow_download']),
            'canEdit' => !empty($share['can_edit']),
            'canReshare' => !empty($share['can_reshare']),
        ],
        'recipient' => [
            'id' => $share['recipient_user_id'] ?? null,
            'name' => $share['recipient_name'] ?? null,
            'email' => $share['recipient_email'] ?? null,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
