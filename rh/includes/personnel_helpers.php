<?php

require_once __DIR__ . '/../../model/AuditTrail.php';

if (!function_exists('rhFormatDate')) {
    function rhFormatDate(?string $date): string
    {
        if (empty($date) || $date === '0000-00-00') {
            return 'Non renseignee';
        }

        $timestamp = strtotime($date);
        return $timestamp ? date('d/m/Y', $timestamp) : 'Non renseignee';
    }
}

if (!function_exists('rhFormatDateTime')) {
    function rhFormatDateTime(?string $dateTime): string
    {
        if (empty($dateTime) || $dateTime === '0000-00-00 00:00:00') {
            return 'Non renseignee';
        }

        $timestamp = strtotime($dateTime);
        return $timestamp ? date('d/m/Y H:i', $timestamp) : 'Non renseignee';
    }
}

if (!function_exists('rhInitials')) {
    function rhInitials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials .= strtoupper(substr($part, 0, 1));
            if (strlen($initials) >= 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'RH';
    }
}

if (!function_exists('rhResolveMediaUrl')) {
    function rhResolveMediaUrl(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);
        if ($path === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $projectRoot = dirname(__DIR__, 2);
        $normalized = ltrim(str_replace('\\', '/', $path), '/');

        if (strpos($normalized, 'uploads/') === 0 || strpos($normalized, 'photo/') === 0 || strpos($normalized, 'img/') === 0 || strpos($normalized, 'storage/') === 0) {
            $absoluteCandidate = $projectRoot . '/' . $normalized;
            if (is_file($absoluteCandidate)) {
                return '../' . $normalized;
            }
        }

        $candidates = [
            ['fs' => $projectRoot . '/' . $normalized, 'web' => '../' . $normalized],
            ['fs' => $projectRoot . '/photo/' . $normalized, 'web' => '../photo/' . $normalized],
            ['fs' => $projectRoot . '/uploads/' . $normalized, 'web' => '../uploads/' . $normalized],
            ['fs' => $projectRoot . '/uploads/rh/' . $normalized, 'web' => '../uploads/rh/' . $normalized],
            ['fs' => $projectRoot . '/uploads/photo/' . $normalized, 'web' => '../uploads/photo/' . $normalized],
            ['fs' => $projectRoot . '/uploads/rh/photo/' . $normalized, 'web' => '../uploads/rh/photo/' . $normalized],
            ['fs' => $projectRoot . '/uploads/rh/extrait-naissance/' . $normalized, 'web' => '../uploads/rh/extrait-naissance/' . $normalized],
            ['fs' => $projectRoot . '/uploads/rh/piece-identite/' . $normalized, 'web' => '../uploads/rh/piece-identite/' . $normalized],
            ['fs' => $projectRoot . '/uploads/rh/diplome/' . $normalized, 'web' => '../uploads/rh/diplome/' . $normalized],
            ['fs' => $projectRoot . '/uploads/rh/contrat-embauche/' . $normalized, 'web' => '../uploads/rh/contrat-embauche/' . $normalized],
            ['fs' => $projectRoot . '/uploads/cni/' . $normalized, 'web' => '../uploads/cni/' . $normalized],
            ['fs' => $projectRoot . '/uploads/documents/' . $normalized, 'web' => '../uploads/documents/' . $normalized],
            ['fs' => $projectRoot . '/img/' . $normalized, 'web' => '../img/' . $normalized],
            ['fs' => $projectRoot . '/storage/' . $normalized, 'web' => '../storage/' . $normalized],
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate['fs'])) {
                return $candidate['web'];
            }
        }

        $discovered = rhFindMediaByStoredName($normalized, $projectRoot);
        if ($discovered !== null) {
            return $discovered;
        }

        return null;
    }
}

if (!function_exists('rhFindMediaByStoredName')) {
    function rhFindMediaByStoredName(string $storedName, string $projectRoot): ?string
    {
        static $indexedFiles = null;

        if ($indexedFiles === null) {
            $indexedFiles = [];
            $searchRoots = [
                $projectRoot . '/uploads/rh',
                $projectRoot . '/uploads',
                $projectRoot . '/photo',
                $projectRoot . '/img',
                $projectRoot . '/storage',
            ];

            foreach ($searchRoots as $root) {
                if (!is_dir($root)) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }

                    $absolutePath = str_replace('\\', '/', $fileInfo->getPathname());
                    $relativePath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen(str_replace('\\', '/', $projectRoot)))), '/');
                    $fileName = strtolower($fileInfo->getFilename());
                    $baseName = strtolower(pathinfo($fileName, PATHINFO_FILENAME));

                    $indexedFiles['file:' . $fileName] = '../' . $relativePath;
                    if (!isset($indexedFiles['base:' . $baseName])) {
                        $indexedFiles['base:' . $baseName] = '../' . $relativePath;
                    }
                }
            }
        }

        $storedName = strtolower(trim(basename(str_replace('\\', '/', $storedName))));
        if ($storedName === '') {
            return null;
        }

        if (isset($indexedFiles['file:' . $storedName])) {
            return $indexedFiles['file:' . $storedName];
        }

        $storedBase = strtolower(pathinfo($storedName, PATHINFO_FILENAME));
        if (isset($indexedFiles['base:' . $storedBase])) {
            return $indexedFiles['base:' . $storedBase];
        }

        foreach ($indexedFiles as $key => $resolvedPath) {
            if (!str_starts_with($key, 'base:')) {
                continue;
            }

            $indexedBase = substr($key, 5);
            if ($indexedBase === $storedName || str_starts_with($indexedBase, $storedName) || str_starts_with($storedName, $indexedBase)) {
                return $resolvedPath;
            }
        }

        return null;
    }
}

if (!function_exists('rhIsPreviewableMedia')) {
    function rhIsPreviewableMedia(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
    }
}

if (!function_exists('rhIsPreviewableDocument')) {
    function rhIsPreviewableDocument(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $extension === 'pdf';
    }
}

if (!function_exists('rhHumanFileName')) {
    function rhHumanFileName(?string $path): string
    {
        if ($path === null || trim($path) === '') {
            return 'Fichier indisponible';
        }

        return basename(str_replace('\\', '/', $path));
    }
}

if (!function_exists('rhHasUploadedFile')) {
    function rhHasUploadedFile(string $fieldName): bool
    {
        return isset($_FILES[$fieldName])
            && is_array($_FILES[$fieldName])
            && (int) ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
            && !empty($_FILES[$fieldName]['tmp_name']);
    }
}

if (!function_exists('rhStoreUploadedDocument')) {
    function rhStoreUploadedDocument(array $file, string $documentKey, int $personnelId): string
    {
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            \AuditTrail::log('rh_document_upload_failed', [
                'module' => 'rh',
                'action' => 'store_uploaded_document',
                'entity_type' => 'personnel_document',
                'entity_id' => (string) $personnelId,
                'entity_label' => $documentKey,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'no_file'],
            ], null);
            throw new RuntimeException('Aucun fichier n\'a ete transmis.');
        }

        if ($errorCode !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'Le fichier depasse la taille maximale autorisee par le serveur.',
                UPLOAD_ERR_FORM_SIZE => 'Le fichier depasse la taille maximale autorisee par le formulaire.',
                UPLOAD_ERR_PARTIAL => 'Le televersement a ete interrompu avant la fin.',
                UPLOAD_ERR_NO_TMP_DIR => 'Le dossier temporaire PHP est introuvable.',
                UPLOAD_ERR_CANT_WRITE => 'PHP ne parvient pas a ecrire le fichier temporaire.',
                UPLOAD_ERR_EXTENSION => 'Une extension PHP a interrompu le televersement.',
            ];

            \AuditTrail::log('rh_document_upload_failed', [
                'module' => 'rh',
                'action' => 'store_uploaded_document',
                'entity_type' => 'personnel_document',
                'entity_id' => (string) $personnelId,
                'entity_label' => $documentKey,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'upload_error', 'upload_error' => $errorCode],
            ], null);
            throw new RuntimeException($uploadErrors[$errorCode] ?? 'Echec du televersement du document RH.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            \AuditTrail::log('rh_document_upload_failed', [
                'module' => 'rh',
                'action' => 'store_uploaded_document',
                'entity_type' => 'personnel_document',
                'entity_id' => (string) $personnelId,
                'entity_label' => $documentKey,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'invalid_tmp_file'],
            ], null);
            throw new RuntimeException('Le fichier recu est invalide.');
        }

        $isUploadedFile = is_uploaded_file($tmpPath);

        $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp'];
        $originalName = (string) ($file['name'] ?? 'document');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            \AuditTrail::log('rh_document_upload_failed', [
                'module' => 'rh',
                'action' => 'store_uploaded_document',
                'entity_type' => 'personnel_document',
                'entity_id' => (string) $personnelId,
                'entity_label' => $documentKey,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'unsupported_extension', 'extension' => $extension, 'original_name' => $originalName],
            ], null);
            throw new RuntimeException('Format de fichier non supporte. Utilisez PDF, DOC, DOCX, JPG, JPEG, PNG ou WEBP.');
        }

        $projectRoot = dirname(__DIR__, 2);
        $normalizedDocumentKey = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($documentKey));
        $targetDirectory = $projectRoot . '/uploads/rh/' . $normalizedDocumentKey;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            \AuditTrail::log('rh_document_upload_failed', [
                'module' => 'rh',
                'action' => 'store_uploaded_document',
                'entity_type' => 'personnel_document',
                'entity_id' => (string) $personnelId,
                'entity_label' => $documentKey,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'storage_directory_unavailable', 'document_key' => $normalizedDocumentKey],
            ], null);
            throw new RuntimeException('Impossible de preparer le dossier de stockage RH.');
        }

        if ($normalizedDocumentKey === 'photo') {
            $fileName = sprintf(
                'p%d_%s.%s',
                $personnelId,
                date('ymdHis'),
                $extension
            );
        } else {
            $fileName = sprintf(
                'personnel_%d_%s_%s.%s',
                $personnelId,
                $normalizedDocumentKey,
                date('YmdHis') . '_' . bin2hex(random_bytes(4)),
                $extension
            );
        }

        $targetPath = $targetDirectory . '/' . $fileName;
        $stored = false;
        if ($isUploadedFile) {
            $stored = move_uploaded_file($tmpPath, $targetPath);
        }

        if (!$stored) {
            $stored = @rename($tmpPath, $targetPath);
        }

        if (!$stored) {
            $stored = @copy($tmpPath, $targetPath);
            if ($stored && is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }

        if (!$stored) {
            \AuditTrail::log('rh_document_upload_failed', [
                'module' => 'rh',
                'action' => 'store_uploaded_document',
                'entity_type' => 'personnel_document',
                'entity_id' => (string) $personnelId,
                'entity_label' => $documentKey,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'store_failed', 'original_name' => $originalName],
            ], null);
            throw new RuntimeException('Impossible d\'enregistrer le document televerse.');
        }

        if ($normalizedDocumentKey === 'photo') {
            \AuditTrail::log('rh_document_uploaded', [
                'module' => 'rh',
                'action' => 'store_uploaded_document',
                'entity_type' => 'personnel_document',
                'entity_id' => (string) $personnelId,
                'entity_label' => $documentKey,
                'details' => ['stored_path' => $fileName, 'original_name' => $originalName, 'extension' => $extension],
            ], null);
            return $fileName;
        }

        $storedPath = 'uploads/rh/' . $normalizedDocumentKey . '/' . $fileName;
        \AuditTrail::log('rh_document_uploaded', [
            'module' => 'rh',
            'action' => 'store_uploaded_document',
            'entity_type' => 'personnel_document',
            'entity_id' => (string) $personnelId,
            'entity_label' => $documentKey,
            'details' => ['stored_path' => $storedPath, 'original_name' => $originalName, 'extension' => $extension],
        ], null);

        return $storedPath;
    }
}
