<?php
session_start();
// Charge config (Twilio etc.)
@require_once __DIR__ . '/../config/app_config.php';
require_once '../model/Database.php';
require_once __DIR__ . '/../../services/NotificationWhatsAppChannelService.php';
require_once '../model/FicheDecaissement.php';
require_once '../../model/AuditTrail.php';
require_once __DIR__ . '/../../model/Chantier.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';
require_once __DIR__ . '/../../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../../services/UrgentDisbursementService.php';
require_once __DIR__ . '/../../services/FinancialPlanningSeparationService.php';
require_once __DIR__ . '/../../contrats/model/ContratSousTraitance.php';

// Helpers RH (résolution des médias côté serveur)
@require_once __DIR__ . '/../../rh/includes/personnel_helpers.php';

header('Content-Type: application/json');

function decaissementProjectRoot(): string
{
    return dirname(__DIR__, 2);
}

function decaissementEnsureUploadsSubdir(string $subdir): string
{
    $base = __DIR__ . '/../uploads';
    if (!is_dir($base)) {
        mkdir($base, 0775, true);
    }
    $target = $base . DIRECTORY_SEPARATOR . $subdir;
    if (!is_dir($target)) {
        mkdir($target, 0775, true);
    }
    return $target;
}

function decaissementWebPathToFs(?string $web, string $projectRoot): ?string
{
    if ($web === null) return null;
    $web = trim($web);
    if ($web === '') return null;

    if (preg_match('/^https?:\/\//i', $web)) {
        return null;
    }

    $path = parse_url($web, PHP_URL_PATH);
    if (!$path) {
        $path = $web;
    }
    $path = str_replace('\\', '/', $path);
    while (strpos($path, '../') === 0) {
        $path = substr($path, 3);
    }
    $path = ltrim($path, '/');
    if ($path === '') return null;
    $fs = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . $path;
    return is_file($fs) ? $fs : null;
}

function decaissementResolvePersonnelMediaFs(?string $storedValue, string $projectRoot, array $searchDirs): ?string
{
    if ($storedValue === null) return null;
    $storedValue = trim((string) $storedValue);
    if ($storedValue === '') return null;

    // URL distante => on n'importe pas automatiquement
    if (preg_match('/^https?:\/\//i', $storedValue)) {
        return null;
    }

    $normalized = str_replace('\\', '/', $storedValue);

    // Chemin absolu déjà présent
    if (is_file($normalized)) {
        return $normalized;
    }

    // Chemin relatif depuis la racine projet
    $rel = ltrim($normalized, '/');
    $cand = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . $rel;
    if (is_file($cand)) {
        return $cand;
    }

    // Tenter via helper RH si dispo (retourne un chemin web ../...)
    if (function_exists('rhResolveMediaUrl')) {
        $web = rhResolveMediaUrl($storedValue);
        $fs = decaissementWebPathToFs($web, $projectRoot);
        if ($fs !== null) {
            return $fs;
        }
    }

    // Fallback: recherche par basename dans des dossiers connus
    $base = basename($rel);
    if ($base !== '') {
        foreach ($searchDirs as $dir) {
            $dir = trim(str_replace('\\', '/', $dir), '/');
            if ($dir === '') continue;
            $cand2 = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . $dir . '/' . $base;
            if (is_file($cand2)) {
                return $cand2;
            }
        }
    }

    return null;
}

function decaissementLigneRequiresSupplier(array $ligneInfo): bool
{
    $hay = mb_strtolower(trim((string)(
        ($ligneInfo['ligne_designation'] ?? '') . ' ' .
        ($ligneInfo['devis_designation'] ?? '') . ' ' .
        ($ligneInfo['categorie'] ?? '')
    )), 'UTF-8');

    $patterns = [
        '/\blocation\b/u',
        '/\bfourniture\b/u',
        '/\bfournitures\b/u',
        '/\bfourniture\s+et\s+pose\b/u',
        '/\bfourniture\/?pose\b/u',
        '/\bachat\s+de\s+mat[ée]riel\b/u',
        '/\bachat\s+de\s+mat[ée]riaux\b/u',
        '/\bachat\s+de\s+consommables?\b/u',
        '/\bachat\s+des?\s+[ée]quipements?\b/u',
        '/\btransport\s+et\s+mobilisation\s+engin\b/u',
        '/\btransport\s+des\s+materiels?\b/u',
        '/\btransport\s+materiel\b/u',
        '/\bassurance\s+[ée]quipement\b/u',
        '/\bcertification\s+des?\s+engins?\b/u',
        '/\bfrais\s+de\s+location\b/u',
        '/\bavance\s+de\s+location\b/u',
        '/\bengins?\b/u',
        '/\bchargeuse\b/u',
        '/\bcamion\b/u',
        '/\bgrue\b/u',
        '/\bcompacteur\b/u',
        '/\b[ée]chafaudage\b/u',
        '/\bepi\b/u',
        '/\bmat[ée]riel\b/u',
        '/\bmat[ée]riaux\b/u',
        '/\bconsommables?\b/u',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $hay)) {
            return true;
        }
    }

    return false;
}

function decaissementImportExistingFile(string $srcFsPath, string $destDir, string $prefix): string
{
    $ext = strtolower(pathinfo($srcFsPath, PATHINFO_EXTENSION));
    if ($ext === '' || strlen($ext) > 10) {
        $ext = 'jpg';
    }
    $fileName = $prefix . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    $destPath = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

    if (!is_file($srcFsPath)) {
        throw new RuntimeException('Fichier source introuvable pour import: ' . $srcFsPath);
    }
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }
    if (!is_writable($destDir)) {
        throw new RuntimeException('Répertoire non inscriptible: ' . $destDir);
    }

    // IMPORTANT: on COPIE (pas de déplacement) pour ne pas casser les anciennes fiches / RH.
    if (!@copy($srcFsPath, $destPath)) {
        throw new RuntimeException('Echec copie fichier vers: ' . $destPath);
    }

    return $fileName;
}

function decaissementFindFicheByFebCode(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT id_fiche, num_fiche, code_autorisation_feb
            FROM fiche_decaissement
            WHERE code_autorisation_feb = :code
            ORDER BY date_creat_fiche DESC, id_fiche DESC
            LIMIT 1
        ');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function decaissementAcquireFebCodeLock(PDO $pdo, string $code): ?string
{
    $lockName = 'banamur_feb_code_' . sha1(trim($code));
    try {
        $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $stmt->execute(['lock_name' => $lockName]);
        return ((int) $stmt->fetchColumn() === 1) ? $lockName : null;
    } catch (Throwable $e) {
        return null;
    }
}

function decaissementReleaseFebCodeLock(PDO $pdo, ?string $lockName): void
{
    if ($lockName === null || $lockName === '') {
        return;
    }

    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $stmt->execute(['lock_name' => $lockName]);
    } catch (Throwable $e) {
        // ignore
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$febCodeLockName = null;

try {
    $pdo = \Database::getConnection();

    // Auto-migration colonnes d'archivage chantier

    try {
        \Chantier::ensureArchiveColumns($pdo);
    } catch (Throwable $e) {
        // ignore
    }

    // Auto-migration colonnes signature si absentes + compat created_by_user_id (nullable)
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_ASSOC);
        if ($columns) {
            $colNames = array_map(static fn($row) => (string) ($row['Field'] ?? ''), $columns);

            if (!in_array('created_by_user_id', $colNames, true)) {
                $pdo->exec("ALTER TABLE fiche_decaissement ADD created_by_user_id INT NULL AFTER date_creat_fiche");
                try {
                    $pdo->exec("CREATE INDEX idx_fiche_created_by_user_id ON fiche_decaissement (created_by_user_id)");
                } catch (Throwable $e) {
                    // ignore
                }
                error_log('[migrate] Colonne created_by_user_id ajoutée');
            } else {
                foreach ($columns as $col) {
                    if (($col['Field'] ?? null) === 'created_by_user_id') {
                        // Certains environnements ont été créés en NOT NULL; ce formulaire peut fonctionner sans session user.
                        if (strtoupper((string) ($col['Null'] ?? '')) === 'NO') {
                            $pdo->exec("ALTER TABLE fiche_decaissement MODIFY created_by_user_id INT NULL");
                            error_log('[migrate] Colonne created_by_user_id passée en NULLABLE');
                        }
                        break;
                    }
                }
            }

            if (!in_array('signature_fichier', $colNames, true)) {
                $pdo->exec("ALTER TABLE fiche_decaissement ADD signature_fichier VARCHAR(255) NULL AFTER cni_beneficiaire");
                error_log('[migrate] Colonne signature_fichier ajoutée');
            }
            if (!in_array('signature_beneficiaire', $colNames, true)) {
                $pdo->exec("ALTER TABLE fiche_decaissement ADD signature_beneficiaire VARCHAR(255) NULL AFTER signature_fichier");
                error_log('[migrate] Colonne signature_beneficiaire ajoutée');
            }

            // Liaison RH optionnelle: personnel_id (nullable)
            if (!in_array('personnel_id', $colNames, true)) {
                $pdo->exec("ALTER TABLE fiche_decaissement ADD personnel_id INT NULL AFTER tel_beneficiaire_fiche");
                try {
                    $pdo->exec("CREATE INDEX idx_fiche_personnel_id ON fiche_decaissement (personnel_id)");
                } catch (Throwable $e) {
                    // ignore
                }
                error_log('[migrate] Colonne personnel_id ajoutée');
            }

            if (!in_array('fournisseur_id', $colNames, true)) {
                $pdo->exec("ALTER TABLE fiche_decaissement ADD fournisseur_id INT NULL AFTER personnel_id");
            }
            if (!in_array('fournisseur_nom', $colNames, true)) {
                $pdo->exec("ALTER TABLE fiche_decaissement ADD fournisseur_nom VARCHAR(255) NULL AFTER fournisseur_id");
            }
            if (!in_array('fournisseur_contact_nom', $colNames, true)) {
                $pdo->exec("ALTER TABLE fiche_decaissement ADD fournisseur_contact_nom VARCHAR(190) NULL AFTER fournisseur_nom");
            }
            if (!in_array('fournisseur_telephone', $colNames, true)) {
                $pdo->exec("ALTER TABLE fiche_decaissement ADD fournisseur_telephone VARCHAR(60) NULL AFTER fournisseur_contact_nom");
            }
            if (!in_array('code_autorisation_feb', $colNames, true)) {
                $pdo->exec("ALTER TABLE fiche_decaissement ADD code_autorisation_feb VARCHAR(100) NULL AFTER signature_beneficiaire");
                try {
                    $pdo->exec("CREATE INDEX idx_fiche_code_autorisation_feb ON fiche_decaissement (code_autorisation_feb)");
                } catch (Throwable $e) {
                    // ignore
                }
            }
        }
    } catch (Exception $e) {
        error_log('[migrate] Echec inspection/ajout colonnes signature: ' . $e->getMessage());
    }

    $codeAutorisationFeb = trim((string) ($_SESSION['code_autorisation_feb'] ?? ''));
    if ($codeAutorisationFeb === '') {
        echo json_encode(['success' => false, 'message' => 'Le N de bon de commande interne (BCI) issu d\'une FEB validee est obligatoire pour creer une fiche de decaissement.']);
        exit;
    }

    $febRequest = (new \EmployeeLegalRequestManager($pdo))->findApprovedFebByAuthorizationCode($codeAutorisationFeb);
    if (!$febRequest) {
        echo json_encode(['success' => false, 'message' => 'N BCI invalide ou FEB non validee.']);
        exit;
    }

    $alreadyLinkedFiche = decaissementFindFicheByFebCode($pdo, $codeAutorisationFeb);
    if (is_array($alreadyLinkedFiche)) {
        echo json_encode([
            'success' => false,
            'message' => 'Ce N BCI a deja ete utilise pour la fiche ' . (string) ($alreadyLinkedFiche['num_fiche'] ?? '') . '.',
        ]);
        exit;
    }

    // Création auto table trace_fiche si manquante
    try {
        $pdo->query("SELECT 1 FROM trace_fiche LIMIT 1");
    } catch (Exception $e) {
        if (strpos($e->getMessage(), '42S02') !== false || stripos($e->getMessage(), 'doesn') !== false) {
            $createSql = "CREATE TABLE IF NOT EXISTS trace_fiche (
                id INT AUTO_INCREMENT PRIMARY KEY,
                num_fiche VARCHAR(50) NOT NULL,
                action VARCHAR(255) NOT NULL,
                date_action DATETIME NOT NULL,
                utilisateur VARCHAR(100) NOT NULL,
                adresse_ip VARCHAR(100) DEFAULT NULL,
                user_agent VARCHAR(255) DEFAULT NULL,
                INDEX idx_num_fiche (num_fiche),
                INDEX idx_date_action (date_action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $pdo->exec($createSql);
            error_log('[init] Table trace_fiche créée automatiquement');
        }
    }

    // Création auto table fiche_transport_spec si manquante (extensible pour autres chantiers)
    try {
        $pdo->query("SELECT 1 FROM fiche_transport_spec LIMIT 1");
    } catch (Exception $e) {
        if (strpos($e->getMessage(), '42S02') !== false || stripos($e->getMessage(), 'doesn') !== false) {
            $createSql = "CREATE TABLE IF NOT EXISTS fiche_transport_spec (\n                id INT AUTO_INCREMENT PRIMARY KEY,\n                fiche_id INT NOT NULL,\n                num_fiche VARCHAR(50) NOT NULL,\n                chantier_code VARCHAR(20) NOT NULL,\n                chauffeur_nom VARCHAR(150) NOT NULL,\n                chauffeur_tel VARCHAR(30) NOT NULL,\n                vehicule_matricule VARCHAR(60) NOT NULL,\n                volume_m3 DECIMAL(10,2) DEFAULT NULL,\n                quantite_carburant_litre DECIMAL(10,2) DEFAULT NULL,\n                contexte_detection VARCHAR(255) DEFAULT NULL,\n                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n                updated_at DATETIME NULL DEFAULT NULL,\n                INDEX idx_num_fiche (num_fiche),\n                CONSTRAINT fk_fiche_transport_spec_fiche FOREIGN KEY (fiche_id) REFERENCES fiche_decaissement(id_fiche) ON DELETE CASCADE\n            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            $pdo->exec($createSql);
            error_log('[init] Table fiche_transport_spec créée automatiquement');
        }
    }

    // On peut recevoir un "payload multi" même pour une seule ligne (table de saisie).
    // On distinguera ensuite le vrai multi-lignes (count(ligneIds) > 1).
    $hasMultiPayload = false;
    if (isset($_POST['ligne_debourse_ids']) && is_array($_POST['ligne_debourse_ids']) && count($_POST['ligne_debourse_ids']) > 0) {
        $hasMultiPayload = true;
    }
    if (isset($_POST['montants_by_ligne']) && is_array($_POST['montants_by_ligne']) && count($_POST['montants_by_ligne']) > 0) {
        $hasMultiPayload = true;
    }

    // Validation des données requises pour le nouveau système
    $required_fields = $hasMultiPayload
        ? ['beficiaire_fiche', 'tel_beneficiaire_fiche', 'chantier_id', 'mode_paiement']
        : ['beficiaire_fiche', 'tel_beneficiaire_fiche', 'chantier_id', 'precision_select', 'montant_fiche', 'mode_paiement'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '' || $_POST[$field] === null) {
            echo json_encode(['success' => false, 'message' => "Le champ $field est requis"]);
            exit;
        }
    }

    // Normaliser les lignes de déboursé + montants (multi)
    $ligneIds = [];
    $montantsByLigne = [];
    $detailsByLigne = [];
    if ($hasMultiPayload) {
        $rawIds = $_POST['ligne_debourse_ids'] ?? [];
        if (!is_array($rawIds)) $rawIds = [$rawIds];
        $ligneIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), static function ($v) {
            return $v > 0;
        })));
        if (!$ligneIds) {
            echo json_encode(['success' => false, 'message' => 'Veuillez sélectionner au moins une ligne de déboursé.']);
            exit;
        }
        if (count($ligneIds) > 1) {
            echo json_encode([
                'success' => false,
                'message' => 'Un N BCI ne peut generer qu\'une seule fiche de decaissement. Selectionnez une seule ligne de debourse.',
            ]);
            exit;
        }

        $rawMontants = $_POST['montants_by_ligne'] ?? [];
        if (!is_array($rawMontants)) $rawMontants = [];
        foreach ($ligneIds as $id) {
            $m = $rawMontants[$id] ?? $rawMontants[(string)$id] ?? null;
            $val = $m === null ? 0 : (float) $m;
            $montantsByLigne[$id] = $val;
        }

        // Détails par ligne (1 fiche = 1 détail)
        $rawDetails = $_POST['details_by_ligne'] ?? [];
        if (!is_array($rawDetails)) $rawDetails = [];
        foreach ($ligneIds as $id) {
            $d = $rawDetails[$id] ?? $rawDetails[(string)$id] ?? null;
            $detailsByLigne[$id] = trim((string) ($d ?? ''));
        }

        foreach ($montantsByLigne as $id => $m) {
            if (!is_numeric($m) || (float) $m <= 0) {
                echo json_encode(['success' => false, 'message' => 'Veuillez saisir un montant (> 0) pour chaque ligne sélectionnée.']);
                exit;
            }
        }

        // Exiger un détail non vide par fiche (per-line). Fallback possible sur details_demande si fourni.
        $fallbackDetails = trim((string) ($_POST['details_demande'] ?? ''));
        foreach ($ligneIds as $id) {
            if ($detailsByLigne[$id] === '' && $fallbackDetails !== '') {
                $detailsByLigne[$id] = $fallbackDetails;
            }
            if ($detailsByLigne[$id] === '') {
                echo json_encode(['success' => false, 'message' => 'Veuillez renseigner les détails pour chaque fiche.']);
                exit;
            }
        }
    } else {
        $precisionSelect = $_POST['precision_select'] ?? null;
        if (is_array($precisionSelect)) {
            $precisionSelect = $precisionSelect[0] ?? null;
        }
        $precisionSelect = (int) $precisionSelect;
        if ($precisionSelect <= 0) {
            echo json_encode(['success' => false, 'message' => 'Ligne de déboursé invalide']);
            exit;
        }
        $ligneIds = [$precisionSelect];
        $montantsByLigne[$precisionSelect] = (float) ($_POST['montant_fiche'] ?? 0);
        $detailsByLigne[$precisionSelect] = trim((string) ($_POST['details_demande'] ?? ''));
    }

    $isMultiLines = count($ligneIds) > 1;
    if ($isMultiLines) {
        echo json_encode([
            'success' => false,
            'message' => 'Un N BCI ne peut generer qu\'une seule fiche de decaissement. Selectionnez une seule ligne de debourse.',
        ]);
        exit;
    }

    // Bloquer toute création de fiche sur chantier terminé ou en pause.
    $chantierId = (int)($_POST['chantier_id'] ?? 0);
    if ($chantierId > 0) {
        require_once __DIR__ . '/../../model/Chantier.php';
        Chantier::ensureArchiveColumns($pdo);
        $st = $pdo->prepare('SELECT archived_at,paused_at FROM chantier_banamur WHERE id = :id LIMIT 1');
        $st->execute(['id' => $chantierId]);
        $siteState = $st->fetch(PDO::FETCH_ASSOC);
        if (!$siteState || !empty($siteState['archived_at']) || !empty($siteState['paused_at'])) {
            echo json_encode([
                'success' => false,
                'message' => !empty($siteState['paused_at']) ? 'Ce chantier est en pause. Reprenez-le avant de créer une fiche.' : 'Ce chantier est archivé/terminé. Création de nouvelle fiche impossible.'
            ]);
            exit;
        }
    }

    $urgentRegularizationId = (int)($_POST['urgent_id'] ?? $_GET['urgent_id'] ?? 0);
    try {
        (new \UrgentDisbursementService($pdo))->assertChantierAvailable($chantierId, $urgentRegularizationId);
    } catch (RuntimeException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }

    // Signature obligatoire
    if (empty($_POST['signature_base64'])) {
        echo json_encode(['success' => false, 'message' => 'Signature obligatoire (aucune donnée reçue).']);
        exit;
    }

    // Bénéficiaire = personnel (RH)
    $isPersonnel = ((int)($_POST['beneficiaire_is_personnel'] ?? 0)) === 1;
    $personnelId = (int)($_POST['personnel_id'] ?? 0);
    $projectRoot = decaissementProjectRoot();

    // Gestion des fichiers uploadés
    $photo_filename = '';
    $cni_filename = '';

    // Upload de la photo
    if (isset($_FILES['photo_beneficiaire']) && $_FILES['photo_beneficiaire']['error'] === UPLOAD_ERR_OK) {
        $photo_tmp = $_FILES['photo_beneficiaire']['tmp_name'];
        $photo_ext = strtolower(pathinfo($_FILES['photo_beneficiaire']['name'], PATHINFO_EXTENSION));
        $photo_filename = 'photo_' . time() . '_' . uniqid() . '.' . $photo_ext;
        $photo_dir = realpath(__DIR__ . '/../uploads');
        if ($photo_dir === false) {
            // créer le dossier uploads
            $baseUploads = __DIR__ . '/../uploads';
            if (!is_dir($baseUploads)) mkdir($baseUploads, 0775, true);
            $photo_dir = realpath($baseUploads);
        }
        $photo_subdir = $photo_dir . DIRECTORY_SEPARATOR . 'photo';
        if (!is_dir($photo_subdir)) mkdir($photo_subdir, 0775, true);
        $photo_path = $photo_subdir . DIRECTORY_SEPARATOR . $photo_filename;

        if (!is_writable($photo_subdir)) {
            error_log('[upload] Dossier non inscriptible: ' . $photo_subdir);
            echo json_encode(['success' => false, 'message' => 'Répertoire photo non inscriptible']);
            exit;
        }

        if (!move_uploaded_file($photo_tmp, $photo_path)) {
            error_log('[upload] Echec move_uploaded_file photo depuis ' . $photo_tmp . ' vers ' . $photo_path);
            echo json_encode(['success' => false, 'message' => 'Erreur lors du téléversement de la photo']);
            exit;
        }
    }

    // Upload de la CNI
    if (isset($_FILES['cni_beneficiaire']) && $_FILES['cni_beneficiaire']['error'] === UPLOAD_ERR_OK) {
        $cni_tmp = $_FILES['cni_beneficiaire']['tmp_name'];
        $cni_ext = strtolower(pathinfo($_FILES['cni_beneficiaire']['name'], PATHINFO_EXTENSION));
        $cni_filename = 'cni_' . time() . '_' . uniqid() . '.' . $cni_ext;
        $cni_dir = realpath(__DIR__ . '/../uploads');
        if ($cni_dir === false) {
            $baseUploads = __DIR__ . '/../uploads';
            if (!is_dir($baseUploads)) mkdir($baseUploads, 0775, true);
            $cni_dir = realpath($baseUploads);
        }
        $cni_subdir = $cni_dir . DIRECTORY_SEPARATOR . 'cni';
        if (!is_dir($cni_subdir)) mkdir($cni_subdir, 0775, true);
        $cni_path = $cni_subdir . DIRECTORY_SEPARATOR . $cni_filename;
        if (!is_writable($cni_subdir)) {
            error_log('[upload] Dossier non inscriptible: ' . $cni_subdir);
            echo json_encode(['success' => false, 'message' => 'Répertoire CNI non inscriptible']);
            exit;
        }
        if (!move_uploaded_file($cni_tmp, $cni_path)) {
            error_log('[upload] Echec move_uploaded_file cni depuis ' . $cni_tmp . ' vers ' . $cni_path);
            echo json_encode(['success' => false, 'message' => 'Erreur lors du téléversement de la CNI']);
            exit;
        }
    }

    // Import médias depuis RH si aucun upload fourni
    if ($isPersonnel && $personnelId > 0 && ($photo_filename === '' || $cni_filename === '')) {
        try {
            // Détecter colonnes disponibles
            $colRows = $pdo->query('SHOW COLUMNS FROM personnel')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $colSet = [];
            foreach ($colRows as $col) {
                $name = (string)($col['Field'] ?? '');
                if ($name !== '') $colSet[$name] = true;
            }

            $selectParts = [
                'p.id_personnel_soignant AS id',
                'p.nom_personnel_soignant AS full_name',
            ];
            if (isset($colSet['photo_personnel_soignant'])) {
                $selectParts[] = 'p.photo_personnel_soignant AS photo_path';
            } else {
                $selectParts[] = "'' AS photo_path";
            }
            if (isset($colSet['piece_identite_path'])) {
                $selectParts[] = 'p.piece_identite_path AS identity_path';
            } elseif (isset($colSet['identity_document_path'])) {
                $selectParts[] = 'p.identity_document_path AS identity_path';
            } else {
                $selectParts[] = "'' AS identity_path";
            }

            $sqlP = 'SELECT ' . implode(', ', $selectParts) . ' FROM personnel p WHERE p.id_personnel_soignant = :id LIMIT 1';
            $stP = $pdo->prepare($sqlP);
            $stP->execute([':id' => $personnelId]);
            $prow = $stP->fetch(PDO::FETCH_ASSOC) ?: null;

            $photoSrc = null;
            $cniSrc = null;

            $searchDirsPhoto = [
                'uploads/rh/photo',
                'uploads/photo',
                'uploads',
                'photo',
                'img',
                'storage',
            ];
            $searchDirsCni = [
                'uploads/rh/piece-identite',
                'uploads/rh/cni',
                'uploads/cni',
                'uploads',
                'photo',
                'img',
                'storage',
            ];

            if ($prow) {
                if ($photo_filename === '') {
                    $photoStored = (string)($prow['photo_path'] ?? '');
                    $photoSrc = decaissementResolvePersonnelMediaFs($photoStored, $projectRoot, $searchDirsPhoto);
                    if ($photoSrc === null) {
                        // fallback client (si fourni)
                        $photoUrl = (string)($_POST['personnel_photo_url'] ?? '');
                        $photoSrc = decaissementWebPathToFs($photoUrl, $projectRoot);
                    }
                    if ($photoSrc !== null) {
                        $dest = decaissementEnsureUploadsSubdir('photo');
                        $photo_filename = decaissementImportExistingFile($photoSrc, $dest, 'photo');
                    }
                }

                if ($cni_filename === '') {
                    $cniStored = (string)($prow['identity_path'] ?? '');
                    $cniSrc = decaissementResolvePersonnelMediaFs($cniStored, $projectRoot, $searchDirsCni);
                    if ($cniSrc === null) {
                        $cniUrl = (string)($_POST['personnel_cni_url'] ?? '');
                        $cniSrc = decaissementWebPathToFs($cniUrl, $projectRoot);
                    }
                    if ($cniSrc !== null) {
                        $dest = decaissementEnsureUploadsSubdir('cni');
                        $cni_filename = decaissementImportExistingFile($cniSrc, $dest, 'cni');
                    }
                }
            }
        } catch (Throwable $e) {
            // Ne pas bloquer tout de suite; on validera juste après.
            error_log('[RH import] ' . $e->getMessage());
        }
    }

    require_once __DIR__.'/../../services/FebSupplierBeneficiary.php';
    $febSupplier = FebSupplierBeneficiary::resolve($pdo, $febRequest);
    if ($febSupplier !== null) {
        // A supplier linked to a purchase is not necessarily its payment beneficiary.
        // This branch is granted by the approved FEB, never by a posted supplier type.
        $_POST['fournisseur_id'] = (int)$febSupplier['id_fournisseur'];
        $_POST['beficiaire_fiche'] = (string)$febSupplier['nom_commercial'];
        $_POST['tel_beneficiaire_fiche'] = (string)$febSupplier['telephone'];
        $isPersonnel = false;
        $personnelId = 0;
        $visual = ($febSupplier['supplier_type'] === 'morale' ? $febSupplier['logo_path'] : $febSupplier['photo_path']) ?? '';
        $photo_filename = decaissementImportExistingFile((string)decaissementWebPathToFs($visual, decaissementProjectRoot()), decaissementEnsureUploadsSubdir('photo'), 'photo');
        $cni_filename = FebSupplierBeneficiary::requiresIdentity($febSupplier)
            ? decaissementImportExistingFile((string)decaissementWebPathToFs((string)($febSupplier['cni_path'] ?? ''), decaissementProjectRoot()), decaissementEnsureUploadsSubdir('cni'), 'cni') : '';
    }
    $identityRequired = $febSupplier === null || FebSupplierBeneficiary::requiresIdentity($febSupplier);
    if ($photo_filename === '' || ($identityRequired && $cni_filename === '')) {
        $missing = [];
        if ($photo_filename === '') $missing[] = 'photo';
        if ($cni_filename === '') $missing[] = 'CNI';
        echo json_encode([
            'success' => false,
            'message' => 'Documents manquants: ' . implode(' et ', $missing) . '. Veuillez uploader le(s) document(s) ou sélectionner un personnel RH disposant des fichiers.',
        ]);
        exit;
    }

    // Préparer statements
    $stmtCheckNum = $pdo->prepare("SELECT COUNT(*) FROM fiche_decaissement WHERE num_fiche = ?");
    $officialDebourseSql = FinancialPlanningSeparationService::officialDeboursePredicate('db');
    $stmtLigneInfo = $pdo->prepare("
        SELECT 
            ldb.designation as ligne_designation,
            ldb.categorie,
            db.ligne_devis_id,
            ld.designation as devis_designation,
            ldb.montant as montant_prevu
        FROM ligne_debourse_banamur ldb
        JOIN debourse_banamur db ON db.id = ldb.debourse_id
        JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
        JOIN devis_banamur d ON d.id=db.devis_id
        JOIN chantier_banamur c ON c.id=? AND c.archived_at IS NULL
        JOIN devis_banamur base ON base.id=c.devis_id AND (base.id=d.id OR (base.offre_id IS NOT NULL AND base.offre_id=d.offre_id))
        WHERE ldb.id = ?
          AND $officialDebourseSql
    ");

    $ligneInfoById = [];
    $supplierRequired = false;
    foreach ($ligneIds as $ligneId) {
        $stmtLigneInfo->execute([$chantierId, $ligneId]);
        $ligneInfo = $stmtLigneInfo->fetch(PDO::FETCH_ASSOC);
        if (!$ligneInfo) {
            echo json_encode(['success' => false, 'message' => 'Ligne de déboursé non trouvée']);
            exit;
        }
        $ligneInfoById[$ligneId] = $ligneInfo;
        if (decaissementLigneRequiresSupplier($ligneInfo)) {
            $supplierRequired = true;
        }
    }

    $supplierId = (int)($_POST['fournisseur_id'] ?? 0);
    $supplierSnapshot = [
        'fournisseur_id' => null,
        'fournisseur_nom' => null,
        'fournisseur_contact_nom' => null,
        'fournisseur_telephone' => null,
    ];

    if ($supplierRequired || $supplierId > 0) {
        if ($supplierId <= 0) {
            echo json_encode(['success' => false, 'message' => 'La sélection d\'un fournisseur validé est obligatoire pour cette demande.']);
            exit;
        }

        try {
            $stSupplier = $pdo->prepare("SELECT id_fournisseur, raison_sociale, nom_commercial, contact_nom, telephone, actif, validation_status,disbursement_selection_blocked,disbursement_block_reason,disbursement_block_fiches FROM fournisseur_banamur WHERE id_fournisseur = :id LIMIT 1");
            $stSupplier->execute(['id' => $supplierId]);
            $supplierRow = $stSupplier->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            $supplierRow = null;
        }

        if (!$supplierRow) {
            echo json_encode(['success' => false, 'message' => 'Fournisseur introuvable dans la base de données.']);
            exit;
        }
        if ((int)($supplierRow['actif'] ?? 0) !== 1 || (string)($supplierRow['validation_status'] ?? '') !== 'approved') {
            echo json_encode(['success' => false, 'message' => 'Le fournisseur sélectionné n\'est pas encore validé pour les décaissements.']);
            exit;
        }
        if (!empty($supplierRow['disbursement_selection_blocked'])) {
            echo json_encode(['success'=>false,'message'=>'Fournisseur temporairement indisponible : '.(string)($supplierRow['disbursement_block_reason']??'justificatifs à fournir').' — '.(string)($supplierRow['disbursement_block_fiches']??'fiche concernée').'.']);
            exit;
        }

        $supplierSnapshot = [
            'fournisseur_id' => (int)$supplierRow['id_fournisseur'],
            'fournisseur_nom' => trim((string)($supplierRow['nom_commercial'] ?? '')) ?: (trim((string)($supplierRow['raison_sociale'] ?? '')) ?: null),
            'fournisseur_contact_nom' => trim((string)($supplierRow['contact_nom'] ?? '')) ?: null,
            'fournisseur_telephone' => trim((string)($supplierRow['telephone'] ?? '')) ?: null,
        ];
    }

    // Gestion signature (base64 -> fichier)
    $signatureFileName = '';
    if (!empty($_POST['signature_base64'])) {
        $raw = $_POST['signature_base64'];
        if (preg_match('/^data:image\/(png|jpg|jpeg);base64,/', $raw, $m)) {
            $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
            $raw = substr($raw, strpos($raw, ',') + 1);
            $bin = base64_decode($raw);
            if ($bin !== false) {
                $sigDir = realpath(__DIR__ . '/../uploads');
                if ($sigDir === false) {
                    $baseUploads = __DIR__ . '/../uploads';
                    if (!is_dir($baseUploads)) mkdir($baseUploads, 0775, true);
                    $sigDir = realpath($baseUploads);
                }
                $sigSub = $sigDir . DIRECTORY_SEPARATOR . 'signatures';
                if (!is_dir($sigSub)) mkdir($sigSub, 0775, true);
                if (is_writable($sigSub)) {
                    $signatureFileName = 'signature_' . time() . '_' . uniqid() . '.' . $ext;
                    file_put_contents($sigSub . DIRECTORY_SEPARATOR . $signatureFileName, $bin);
                } else {
                    error_log('[signature] Dossier non inscriptible: ' . $sigSub);
                }
            }
        }
    }

    // Insertion dans la base de données
    $sql = "INSERT INTO fiche_decaissement (
        beficiaire_fiche, montant_fiche, tel_beneficiaire_fiche, personnel_id, fournisseur_id, fournisseur_nom, fournisseur_contact_nom, fournisseur_telephone, num_fiche, 
        chantier_id, ligne_debourse_id, designation_fiche, precision_fiche,
        details_demande, mode_paiement, photo_beneficiaire, cni_beneficiaire,
        date_creat_fiche, created_by_user_id, approuve, conforme, decaisse, otp_valide, signature_fichier, signature_beneficiaire, code_autorisation_feb
    ) VALUES (
        :beficiaire_fiche, :montant_fiche, :tel_beneficiaire_fiche, :personnel_id, :fournisseur_id, :fournisseur_nom, :fournisseur_contact_nom, :fournisseur_telephone, :num_fiche,
        :chantier_id, :ligne_debourse_id, :designation_fiche, :precision_fiche,
        :details_demande, :mode_paiement, :photo_beneficiaire, :cni_beneficiaire,
        :date_creat_fiche, :created_by_user_id, :approuve, :conforme, :decaisse, :otp_valide, :signature_fichier, :signature_beneficiaire, :code_autorisation_feb
    )";
    $stmtInsertFiche = $pdo->prepare($sql);

    // Détection chantier code pour enrichissement + insertion transport
    $chantierCode = '';
    try {
        $stmtCh = $pdo->prepare("SELECT code FROM chantier_banamur WHERE id = ?");
        $stmtCh->execute([$chantierId]);
        $chantierCode = $stmtCh->fetchColumn() ?: '';
    } catch (Exception $e) {
    }

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Pré-initialiser le manager de notifications (évite un éventuel DDL pendant la transaction)
    $notificationManager = null;
    try {
        $notificationManager = new \NotificationController($pdo);
    } catch (Throwable $e) {
        $notificationManager = null;
    }

    $numFiches = [];
    $idFiches = [];
    $whatsappQueue = [];

    // Transaction DB: éviter demi-créations
    $febCodeLockName = decaissementAcquireFebCodeLock($pdo, $codeAutorisationFeb);
    if ($febCodeLockName === null) {
        throw new Exception('Impossible de verrouiller ce N BCI. Reessayez dans quelques secondes.');
    }
    $alreadyLinkedFiche = decaissementFindFicheByFebCode($pdo, $codeAutorisationFeb);
    if (is_array($alreadyLinkedFiche)) {
        throw new Exception('Ce N BCI a deja ete utilise pour la fiche ' . (string) ($alreadyLinkedFiche['num_fiche'] ?? '') . '.');
    }
    $pdo->beginTransaction();

    foreach ($ligneIds as $ligneId) {
        $contractLock = (new ContratSousTraitance($pdo))->lockedLine($ligneId);
        if ($contractLock) {
            throw new Exception('Cette ligne est sous contrat '.$contractLock['reference'].'. Utilisez exclusivement la FEB contractuelle depuis le module Contrats.');
        }
        $montantLigneDemande = (float) ($montantsByLigne[$ligneId] ?? 0);
        if ($montantLigneDemande <= 0) {
            throw new Exception('Montant invalide pour la ligne: ' . $ligneId);
        }

        $detailsDemandeForThisFiche = trim((string) ($detailsByLigne[$ligneId] ?? ($_POST['details_demande'] ?? '')));
        if ($detailsDemandeForThisFiche === '') {
            throw new Exception('Détails manquants pour la ligne: ' . $ligneId);
        }

        // Génération du numéro de fiche unique
        $num_fiche = 'FD-' . date('ym') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        $stmtCheckNum->execute([$num_fiche]);
        while ($stmtCheckNum->fetchColumn() > 0) {
            $num_fiche = 'FD-' . date('ym') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $stmtCheckNum->execute([$num_fiche]);
        }

        // Récupérer les informations de la ligne de déboursé
        $ligne_info = $ligneInfoById[$ligneId] ?? null;
        if (!$ligne_info) {
            throw new Exception('Ligne de déboursé non trouvée');
        }

        // Préparer les données pour l'insertion
        $data = [
            'beficiaire_fiche' => trim($_POST['beficiaire_fiche']),
            'montant_fiche' => $montantLigneDemande,
            'tel_beneficiaire_fiche' => trim($_POST['tel_beneficiaire_fiche']),
            'personnel_id' => ($isPersonnel && $personnelId > 0) ? $personnelId : null,
            'fournisseur_id' => $supplierSnapshot['fournisseur_id'],
            'fournisseur_nom' => $supplierSnapshot['fournisseur_nom'],
            'fournisseur_contact_nom' => $supplierSnapshot['fournisseur_contact_nom'],
            'fournisseur_telephone' => $supplierSnapshot['fournisseur_telephone'],
            'num_fiche' => $num_fiche,
            'chantier_id' => $chantierId,
            'ligne_debourse_id' => (int) $ligneId,
            'designation_fiche' => $ligne_info['devis_designation'],
            'precision_fiche' => $ligne_info['ligne_designation'],
            'details_demande' => $detailsDemandeForThisFiche,
            'mode_paiement' => trim($_POST['mode_paiement']),
            'photo_beneficiaire' => $photo_filename,
            'cni_beneficiaire' => $cni_filename,
            'date_creat_fiche' => date('Y-m-d H:i:s'),
            'created_by_user_id' => !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'approuve' => 0,
            'conforme' => 0,
            'decaisse' => 0,
            'otp_valide' => 0,
            'signature_fichier' => $signatureFileName,
            'signature_beneficiaire' => $signatureFileName,
            'code_autorisation_feb' => $codeAutorisationFeb,
        ];

        $result = $stmtInsertFiche->execute($data);

        if ($result) {
            $fiche_id = $pdo->lastInsertId();
            $numFiches[] = $num_fiche;
            $idFiches[] = $fiche_id;

            // Notifications système (Logistique / HSE si EPI)
            try {
                $hay = (string) (($ligne_info['ligne_designation'] ?? '') . ' ' . ($ligne_info['devis_designation'] ?? '') . ' ' . ($ligne_info['categorie'] ?? '') . ' ' . ($data['details_demande'] ?? ''));

                $isEpi = false;
                $hayLower = mb_strtolower($hay, 'UTF-8');

                // Heuristique EPI (à ajuster selon vos libellés réels)
                $epiPatterns = [
                    // "EPI" en tant que mot (évite les faux positifs type "épicerie")
                    '/\b(e\.?p\.?i\.?|épi)\b/u',
                    '/\b(équipement|equipement)s?\s+de\s+protection\b/u',
                    '/\b(ppe)\b/u',

                    // Exemples courants d'EPI
                    '/\bcasque\b/u',
                    '/\bgants?\b/u',
                    '/\blunettes?\b/u',
                    '/\bharnais\b/u',
                    '/\bmasque\b/u',
                    '/\brespirateur\b/u',
                    '/\bvis[iì]ère\b/u',
                    '/\bgilet\b/u',
                    '/\bcombinaison\b/u',
                    '/\btablier\b/u',

                    // Protection auditive (bouchons d'oreille, casque antibruit, etc.)
                    '/\b(bouchons?|protections?)\s+auditives?\b/u',
                    '/\bbouchons?\s*d[’\']?\s*oreilles?\b/u',
                    '/\b(casque|protections?)\s+antibruit\b/u',

                    // Chaussures / bottes / sécurité
                    '/\bchaussures?\s+(de\s+)?s[ée]curit[ée]\b/u',
                    '/\bbottes?\s+(de\s+)?s[ée]curit[ée]\b/u',
                    '/\bbottes?\b/u',

                    // Tenues / uniformes / visibilité
                    '/\btenues?\b/u',
                    '/\buniformes?\b/u',
                    '/\bchasubles?\b/u',

                    '/\b(genouill[eè]res?|coudi[eè]res?)\b/u',
                ];

                foreach ($epiPatterns as $p) {
                    if (preg_match($p, $hayLower)) {
                        $isEpi = true;
                        break;
                    }
                }

                if ($notificationManager) {
                    $notificationManager->notifyDecaissementSubmitted($data, $isEpi, [
                        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                        'name' => (string) ($_SESSION['user_name'] ?? ($_SESSION['secur_hop'] ?? '')),
                    ]);
                }
            } catch (Throwable $e) {
                // Ne pas bloquer la création si la notif échoue.
            }

            // Insertion éventuelle des spécifications transport-dépollution si fournies (uniquement mono)
            $precisionLower = mb_strtolower($data['precision_fiche'] ?? '', 'UTF-8');
            $isCarburantPrecision = (mb_strpos($precisionLower, 'carburant') !== false);
            $hasTransportSpecPayload = isset($_POST['chauffeur_nom'], $_POST['chauffeur_tel'], $_POST['vehicule_matricule']);
            if (!$isMultiLines && $chantierCode === 'CH060' && $isCarburantPrecision && $hasTransportSpecPayload) {
                $insertTrans = $pdo->prepare("INSERT INTO fiche_transport_spec (fiche_id, num_fiche, chantier_code, chauffeur_nom, chauffeur_tel, vehicule_matricule, volume_m3, quantite_carburant_litre, contexte_detection) VALUES (?,?,?,?,?,?,?,?,?)");
                $insertTrans->execute([
                    $fiche_id,
                    $data['num_fiche'],
                    $chantierCode,
                    trim($_POST['chauffeur_nom'] ?? ''),
                    trim($_POST['chauffeur_tel'] ?? ''),
                    trim($_POST['vehicule_matricule'] ?? ''),
                    isset($_POST['volume_m3']) ? (float)$_POST['volume_m3'] : null,
                    isset($_POST['quantite_carburant']) ? (float)$_POST['quantite_carburant'] : null,
                    'auto(CH060+carburant)'
                ]);
                // Trace insertion transport
                try {
                    $log_stmt = $pdo->prepare("INSERT INTO trace_fiche (num_fiche, action, date_action, utilisateur, adresse_ip, user_agent) VALUES (?,?,?,?,?,?)");
                    $log_stmt->execute([
                        $data['num_fiche'],
                        'Ajout spécifications transport (chauffeur=' . ($_POST['chauffeur_nom'] ?? '') . ', mat=' . ($_POST['vehicule_matricule'] ?? '') . ')',
                        date('Y-m-d H:i:s'),
                        $_SESSION['secur_hop'] ?? 'SYSTEM',
                        $ip,
                        $userAgent
                    ]);
                } catch (Exception $e) {
                }
            }

            // Auto-création des fiches liées si CARBURANT (Frais de route + Location/Voyage)
            // Désactivée par demande métier: ne plus créer de fiches filles, même si c'est carburant.
            try {
                // Détection "carburant" cohérente avec la section WhatsApp plus bas
                $texte_precision = $data['precision_fiche'] ?? '';
                $texte_designation = $data['designation_fiche'] ?? '';
                $carbKeywords = ['carburant', 'gasoil', 'gas-oil', 'diesel', 'essence', 'fuel'];
                $haystack = mb_strtolower($texte_precision . ' ' . $texte_designation, 'UTF-8');
                $isCarburantGlobal = false;
                foreach ($carbKeywords as $kw) {
                    if (mb_strpos($haystack, mb_strtolower($kw, 'UTF-8')) !== false) {
                        $isCarburantGlobal = true;
                        break;
                    }
                }

                $disableCarburantChildren = true;
                if (!$disableCarburantChildren && $isCarburantGlobal) {
                    $ficheModel = new \FicheDecaissement($pdo);
                    $children = $ficheModel->createChildrenForCarburant($data['num_fiche'], 20000.0, 146250.0, $_SESSION['secur_hop'] ?? 'SYSTEM');
                    // Trace complémentaire (sécurité)
                    $log_stmt = $pdo->prepare("INSERT INTO trace_fiche (num_fiche, action, date_action, utilisateur, adresse_ip, user_agent) VALUES (?,?,?,?,?,?)");
                    $log_stmt->execute([
                        $data['num_fiche'],
                        'Auto-création enfants FraisRoute/LocationVoyage: ' . json_encode($children),
                        date('Y-m-d H:i:s'),
                        $_SESSION['secur_hop'] ?? 'SYSTEM',
                        ($_SERVER['REMOTE_ADDR'] ?? ''),
                        ($_SERVER['HTTP_USER_AGENT'] ?? '')
                    ]);
                }
            } catch (Exception $e) {
                error_log('[auto-children] ' . $e->getMessage());
            }

            // Récupérations supplémentaires pour enrichir le log
            $chantierLib = '';
            $debourseLib = '';
            $lignePrecisionLib = $ligne_info['ligne_designation'] ?? '';
            $categorieLigne = $ligne_info['categorie'] ?? '';
            try {
                $stmtC = $pdo->prepare("SELECT code, titre FROM chantier_banamur WHERE id = ?");
                $stmtC->execute([$data['chantier_id']]);
                if ($rowC = $stmtC->fetch(PDO::FETCH_ASSOC)) {
                    $chantierLib = $rowC['code'] . ' - ' . $rowC['titre'];
                }
                $stmtD = $pdo->prepare("SELECT d.id, ld.designation AS debourse_designation FROM debourse_banamur d INNER JOIN ligne_devis_banamur ld ON d.ligne_devis_id = ld.id WHERE d.id = (SELECT debourse_id FROM ligne_debourse_banamur WHERE id = ?)");
                $stmtD->execute([$data['ligne_debourse_id']]);
                if ($rowD = $stmtD->fetch(PDO::FETCH_ASSOC)) {
                    $debourseLib = $rowD['debourse_designation'];
                }
            } catch (Exception $e) {
                error_log('[trace] Enrichissement partiel: ' . $e->getMessage());
            }

            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $host = gethostbyaddr($ip) ?: '';
            $navigateur = '';
            if (stripos($userAgent, 'Firefox') !== false) $navigateur = 'Firefox';
            elseif (stripos($userAgent, 'Chrome') !== false) $navigateur = 'Chrome';
            elseif (stripos($userAgent, 'Safari') !== false) $navigateur = 'Safari';
            elseif (stripos($userAgent, 'Edge') !== false) $navigateur = 'Edge';
            elseif (stripos($userAgent, 'Trident') !== false) $navigateur = 'IE';

            $os = '';
            if (stripos($userAgent, 'Windows') !== false) $os = 'Windows';
            elseif (stripos($userAgent, 'Linux') !== false) $os = 'Linux';
            elseif (stripos($userAgent, 'Mac OS') !== false) $os = 'MacOS';
            elseif (stripos($userAgent, 'Android') !== false) $os = 'Android';
            elseif (stripos($userAgent, 'iPhone') !== false) $os = 'iOS';

            $montantFmt = number_format($data['montant_fiche'], 0, ',', ' ');
            $actionDetail = sprintf(
                "Création de la fiche N° %s pour %s | Chantier: %s | Déboursé: %s | Ligne: %s | Catégorie: %s | Montant: %s FCFA | Détails: %s | Mode: %s | Photo: %s | CNI: %s | IP: %s (%s) | Navigateur: %s | OS: %s",
                $data['num_fiche'],
                $data['beficiaire_fiche'],
                $chantierLib,
                $debourseLib,
                $lignePrecisionLib,
                $categorieLigne,
                $montantFmt,
                ($data['details_demande'] ?: 'N/A'),
                $data['mode_paiement'],
                $data['photo_beneficiaire'] ?: 'aucune',
                $data['cni_beneficiaire'] ?: 'aucune',
                $ip,
                $host,
                $navigateur,
                $os
            );

            // Adapter la table trace_fiche si user_agent manquant (déjà prévu lors création auto plus haut avec colonne user_agent)
            $log_sql = "INSERT INTO trace_fiche (num_fiche, action, date_action, utilisateur, adresse_ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                $num_fiche,
                $actionDetail,
                date('Y-m-d H:i:s'),
                $_SESSION['secur_hop'] ?? 'SYSTEM',
                $ip,
                $userAgent
            ]);

            // ===== Notification WhatsApp via WhatsAppSMS si la fiche concerne le carburant =====
            // On met en file, et on enverra après commit (évite d'envoyer si rollback)
            try {
                $texte_precision = $data['precision_fiche'] ?? '';
                $texte_designation = $data['designation_fiche'] ?? '';
                $carbKeywords = ['carburant', 'gasoil', 'gas-oil', 'diesel', 'essence', 'fuel'];
                $haystack = mb_strtolower($texte_precision . ' ' . $texte_designation, 'UTF-8');
                $isCarburant = false;
                foreach ($carbKeywords as $kw) {
                    if (mb_strpos($haystack, mb_strtolower($kw, 'UTF-8')) !== false) {
                        $isCarburant = true;
                        break;
                    }
                }
                if ($isCarburant) {
                    $whatsappQueue[] = [
                        'num_fiche' => $data['num_fiche'],
                        'montant' => (float) $data['montant_fiche'],
                        'beneficiaire' => $data['beficiaire_fiche'],
                        'info' => ($texte_precision ?: $texte_designation)
                    ];
                }
            } catch (Exception $eTw) {
                // ignore
            }
            // ===== fin notification WhatsApp =====
        } else {
            throw new Exception('Erreur lors de la création de la fiche');
        }
    }

    $pdo->commit();
    decaissementReleaseFebCodeLock($pdo, $febCodeLockName);
    $febCodeLockName = null;

    \AuditTrail::log('decaissement_fiche_created', [
        'module' => 'decaissement',
        'action' => 'submit_new_fiche',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => !empty($idFiches[0]) ? (string) $idFiches[0] : null,
        'entity_label' => count($numFiches) > 1 ? ('Création multiple de fiches (' . count($numFiches) . ')') : ((string) ($numFiches[0] ?? 'Création fiche décaissement')),
        'result' => 'success',
        'details' => [
            'num_fiches' => $numFiches,
            'id_fiches' => $idFiches,
            'fiche_count' => count($numFiches),
            'chantier_id' => $chantierId,
            'beneficiaire' => trim((string) ($_POST['beficiaire_fiche'] ?? '')),
            'mode_paiement' => trim((string) ($_POST['mode_paiement'] ?? '')),
            'is_multi_lines' => $isMultiLines,
            'code_autorisation_feb' => $codeAutorisationFeb,
        ],
    ], $pdo);

    // Envoi WhatsApp après commit (si carburant)
    foreach ($whatsappQueue as $w) {
        try {
            $sid   = defined('TWILIO_SID') ? TWILIO_SID : (getenv('TWILIO_SID') ?: '');
            $token = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : (getenv('TWILIO_TOKEN') ?: '');
            $from  = defined('TWILIO_WHATSAPP_FROM') ? TWILIO_WHATSAPP_FROM : (getenv('TWILIO_WHATSAPP_FROM') ?: '');
            if (!($sid && $token && $from)) {
                continue;
            }
            $whatsapp = new \NotificationWhatsAppChannelService($sid, $token, $from);
            $dgLocal = defined('DG_PHONE') ? DG_PHONE : (getenv('DG_PHONE') ?: '0544577666');
            $dgDigits = preg_replace('/\D+/', '', $dgLocal);
            if (strpos($dgDigits, '225') === 0) {
                $dgDigits = substr($dgDigits, 3);
            }
            $whatsappNumberDG = '+225' . $dgDigits;

            $resp = $whatsapp->sendCarburantManageCallToAction(
                $whatsappNumberDG,
                $w['num_fiche'],
                (string) $w['montant'],
                $w['beneficiaire'],
                $w['info']
            );

            $succ = is_array($resp) && (($resp['status'] ?? '') === 'success');
            $traceMsg = sprintf(
                'Alerte CARBURANT %s au DG %s | Fiche %s | Montant %s FCFA | info=%s',
                $succ ? 'ENVOYÉE' : 'NON ENVOYÉE',
                $whatsappNumberDG,
                $w['num_fiche'],
                number_format((float) $w['montant'], 0, ',', ' '),
                is_array($resp) ? ($resp['message'] ?? 'ok') : 'service'
            );
            $log_stmt = $pdo->prepare("INSERT INTO trace_fiche (num_fiche, action, date_action, utilisateur, adresse_ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
            $log_stmt->execute([
                $w['num_fiche'],
                $traceMsg,
                date('Y-m-d H:i:s'),
                $_SESSION['secur_hop'] ?? 'SYSTEM',
                $ip,
                $userAgent
            ]);
        } catch (Exception $eTw) {
            // ignore
        }
    }

    echo json_encode([
        'success' => true,
        'message' => (count($numFiches) > 1) ? (count($numFiches) . ' fiches créées avec succès') : 'Fiche créée avec succès',
        'num_fiche' => $numFiches[0] ?? ($num_fiche ?? ''),
        'num_fiches' => $numFiches,
        'id_fiche' => $idFiches[0] ?? null,
        'id_fiches' => $idFiches,
        'transport_spec' => false,
        'auto_children' => false,
        'auto_children_disabled' => false
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e2) {
        }
        decaissementReleaseFebCodeLock($pdo, $febCodeLockName ?? null);
        $febCodeLockName = null;
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        \AuditTrail::log('decaissement_fiche_create_failed', [
            'module' => 'decaissement',
            'action' => 'submit_new_fiche',
            'entity_type' => 'fiche_decaissement',
            'entity_label' => 'Création fiche décaissement',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'chantier_id' => (int) ($_POST['chantier_id'] ?? 0),
                'beneficiaire' => trim((string) ($_POST['beficiaire_fiche'] ?? '')),
                'mode_paiement' => trim((string) ($_POST['mode_paiement'] ?? '')),
                'line_count' => isset($ligneIds) && is_array($ligneIds) ? count($ligneIds) : 0,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }

    echo json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]);
}

