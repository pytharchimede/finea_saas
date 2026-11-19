<?php
session_start();
@require_once __DIR__ . '/../config/app_config.php';
require_once '../model/Fiche.php';
require_once '../model/EmailManager.php';
require_once '../model/Database.php';
require_once __DIR__ . '/../../services/NotificationWhatsAppChannelService.php';
require_once '../../model/AuditTrail.php';
require_once '../../model/EmployeeLegalRequestManager.php';

$pdo = Database::getConnection();

$codeAutorisationFeb = trim((string) ($_SESSION['code_autorisation_feb'] ?? ''));
if ($codeAutorisationFeb === '') {
    echo json_encode(["status" => "error", "message" => "Le N de bon de commande interne (BCI) issu d'une FEB validee est obligatoire pour creer une fiche de decaissement."]);
    exit;
}

$febRequest = (new EmployeeLegalRequestManager($pdo))->findApprovedFebByAuthorizationCode($codeAutorisationFeb);
if (!$febRequest) {
    echo json_encode(["status" => "error", "message" => "N BCI invalide ou FEB non validee."]);
    exit;
}

$alreadyUsedFebCode = insertFicheFindUsedFebCode($pdo, $codeAutorisationFeb);
if (is_array($alreadyUsedFebCode)) {
    echo json_encode([
        "status" => "error",
        "message" => "Ce N BCI a deja ete utilise pour la fiche " . (string) ($alreadyUsedFebCode['num_fiche'] ?? '') . "."
    ]);
    exit;
}

// Initialisation
$ficheObj = new Fiche($pdo);
$emailManagerObj = new EmailManager();

// Envoi notification whatsapp (config centralisée)
$sid = defined('TWILIO_SID') ? TWILIO_SID : (getenv('TWILIO_SID') ?: '');
$token = defined('TWILIO_TOKEN') ? TWILIO_TOKEN : (getenv('TWILIO_TOKEN') ?: '');
$from = defined('TWILIO_WHATSAPP_FROM') ? TWILIO_WHATSAPP_FROM : (getenv('TWILIO_WHATSAPP_FROM') ?: '');
$whatsapp = new NotificationWhatsAppChannelService($sid, $token, $from);


// Dossiers pour les fichiers
$photoDir = '../../img_demande/';
$cniDir = '../../logi/img/';

// Helpers RH (résolution des médias)
@require_once __DIR__ . '/../../rh/includes/personnel_helpers.php';

function insertFicheProjectRoot(): string
{
    return dirname(__DIR__, 2);
}

function insertFicheWebPathToFs(?string $web, string $projectRoot): ?string
{
    if ($web === null) return null;
    $web = trim($web);
    if ($web === '') return null;
    if (preg_match('/^https?:\/\//i', $web)) return null;

    $path = parse_url($web, PHP_URL_PATH);
    if (!$path) $path = $web;
    $path = str_replace('\\', '/', $path);
    while (strpos($path, '../') === 0) {
        $path = substr($path, 3);
    }
    $path = ltrim($path, '/');
    if ($path === '') return null;
    $fs = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . $path;
    return is_file($fs) ? $fs : null;
}

function insertFicheResolveMediaFs(?string $storedValue, string $projectRoot, array $searchDirs): ?string
{
    if ($storedValue === null) return null;
    $storedValue = trim((string) $storedValue);
    if ($storedValue === '') return null;
    if (preg_match('/^https?:\/\//i', $storedValue)) return null;

    $normalized = str_replace('\\', '/', $storedValue);
    if (is_file($normalized)) return $normalized;

    $rel = ltrim($normalized, '/');
    $cand = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . $rel;
    if (is_file($cand)) return $cand;

    if (function_exists('rhResolveMediaUrl')) {
        $web = rhResolveMediaUrl($storedValue);
        $fs = insertFicheWebPathToFs($web, $projectRoot);
        if ($fs !== null) return $fs;
    }

    $base = basename($rel);
    if ($base !== '') {
        foreach ($searchDirs as $dir) {
            $dir = trim(str_replace('\\', '/', $dir), '/');
            if ($dir === '') continue;
            $cand2 = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/' . $dir . '/' . $base;
            if (is_file($cand2)) return $cand2;
        }
    }
    return null;
}

function insertFicheCopyFile(string $srcFsPath, string $destDir, string $prefix): string
{
    $ext = strtolower(pathinfo($srcFsPath, PATHINFO_EXTENSION));
    if ($ext === '' || strlen($ext) > 10) $ext = 'jpg';
    $name = $prefix . '_' . date('Ymd_His') . '_' . uniqid() . '.' . $ext;
    $destPath = rtrim($destDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
    if (!is_file($srcFsPath)) {
        throw new RuntimeException('Fichier source introuvable: ' . $srcFsPath);
    }
    if (!is_dir($destDir)) {
        mkdir($destDir, 0777, true);
    }
    if (!@copy($srcFsPath, $destPath)) {
        throw new RuntimeException('Echec copie vers: ' . $destPath);
    }
    return $name;
}

function insertFicheFindUsedFebCode(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    foreach (['fiche_decaissement', 'fiche'] as $table) {
        try {
            $stmt = $pdo->prepare("SELECT num_fiche, code_autorisation_feb FROM {$table} WHERE code_autorisation_feb = :code ORDER BY num_fiche DESC LIMIT 1");
            $stmt->execute(['code' => $code]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['_table'] = $table;
                return $row;
            }
        } catch (Throwable $e) {
            continue;
        }
    }

    return null;
}

// Création des dossiers s'ils n'existent pas
if (!is_dir($photoDir)) {
    mkdir($photoDir, 0777, true);
}
if (!is_dir($cniDir)) {
    mkdir($cniDir, 0777, true);
}

$photoNewName = '';

// Vérification et gestion de la photo du bénéficiaire
if (isset($_FILES['photo_demandeur']['name']) && $_FILES['photo_demandeur']['error'] == UPLOAD_ERR_OK) {
    $photoExtension = pathinfo($_FILES['photo_demandeur']['name'], PATHINFO_EXTENSION);
    $photoNewName = "photo_" . time() . "_" . uniqid() . "." . $photoExtension;
    $photoPath = $photoDir . $photoNewName;

    if (move_uploaded_file($_FILES['photo_demandeur']['tmp_name'], $photoPath)) {
        // ok
    } else {
        $photoNewName = '';
    }
}

// Ajoutez cette ligne avant la gestion de la CNI
$cniNewName = '';

// Vérification et gestion de la CNI du bénéficiaire
if (isset($_FILES['cni_demandeur']['name']) && $_FILES['cni_demandeur']['error'] == UPLOAD_ERR_OK) {
    $cniExtension = pathinfo($_FILES['cni_demandeur']['name'], PATHINFO_EXTENSION);
    $cniNewName = "cni_" . time() . "_" . uniqid() . "." . $cniExtension;
    $cniPath = $cniDir . $cniNewName;

    if (move_uploaded_file($_FILES['cni_demandeur']['tmp_name'], $cniPath)) {
        // ok
    } else {
        $cniNewName = '';
    }
}

// Import médias depuis RH si aucun upload fourni
try {
    $isPersonnel = isset($_POST['beneficiaire_is_personnel']) && (string)$_POST['beneficiaire_is_personnel'] !== '0';
    $personnelId = (int)($_POST['personnel_id'] ?? 0);
    if ($isPersonnel && $personnelId > 0 && ($photoNewName === '' || $cniNewName === '')) {
        $projectRoot = insertFicheProjectRoot();

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
            if ($photoNewName === '') {
                $photoStored = (string)($prow['photo_path'] ?? '');
                $src = insertFicheResolveMediaFs($photoStored, $projectRoot, $searchDirsPhoto);
                if ($src === null) {
                    $src = insertFicheWebPathToFs((string)($_POST['personnel_photo_url'] ?? ''), $projectRoot);
                }
                if ($src !== null) {
                    $photoNewName = insertFicheCopyFile($src, $photoDir, 'photo');
                }
            }

            if ($cniNewName === '') {
                $cniStored = (string)($prow['identity_path'] ?? '');
                $src = insertFicheResolveMediaFs($cniStored, $projectRoot, $searchDirsCni);
                if ($src === null) {
                    $src = insertFicheWebPathToFs((string)($_POST['personnel_cni_url'] ?? ''), $projectRoot);
                }
                if ($src !== null) {
                    $cniNewName = insertFicheCopyFile($src, $cniDir, 'cni');
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[RH import insert_fiche] ' . $e->getMessage());
}

// Données de la fiche
$data = [
    'beficiaire_fiche' => $_POST['nom_prenom'],
    'montant_fiche' => $_POST['montant'],
    'tel_beneficiaire_fiche' => $_POST['telephone'],
    'num_fiche' => $ficheObj->generateNumFiche(),
    'affectation_id' => $_POST['affectation'],
    'num_piece' => $_POST['mode_paiement'],
    'chantier_id' => $_POST['chantier'],
    'precision_fiche' => $_POST['details_demande'],
    'serv_bureau_banamur_id' => $_POST['service'],
    'code_autorisation_feb' => $codeAutorisationFeb,
    'entreprise' => isset($_SESSION['companyName']) ? $_SESSION['companyName'] : '',
];

if ((int)($data['chantier_id'] ?? 0) > 0) {
    $activeChantierStmt = $pdo->prepare('SELECT 1 FROM chantier_banamur WHERE id=:id AND archived_at IS NULL LIMIT 1');
    $activeChantierStmt->execute(['id' => (int)$data['chantier_id']]);
    if (!$activeChantierStmt->fetchColumn()) {
        echo json_encode(['status' => 'error', 'message' => 'Ce chantier est terminé ou archivé. Aucune fiche ne peut y être créée.']);
        exit;
    }
}

error_log(json_encode($data));

if ($data['code_autorisation_feb'] == 'Pas autorise' || !isset($data['code_autorisation_feb']) || $_SESSION['companyName'] == '' || !isset($_SESSION['companyName'])) {
    AuditTrail::log('decaissement_fiche_insert_failed', [
        'module' => 'decaissement',
        'action' => 'insert_fiche',
        'entity_type' => 'fiche_decaissement',
        'entity_label' => 'Insertion fiche décaissement',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'session_expired',
            'company_name' => $_SESSION['companyName'] ?? null,
        ],
    ], $pdo);
    // Retourner un message de succès
    echo json_encode(["status" => "error", "message" => "Session expirée ou inexistante ! Veuillez recommencer svp."]);
    exit; // Arrête l'exécution du script
}


//Définir une variable de session
$_SESSION['num_fiche'] = $data['num_fiche'];

// Vérification des fichiers
$data['photo_beneficiaire'] = $photoNewName ? $photoNewName : '';

$data['cni_beneficiaire'] = $cniNewName ? $cniNewName : '';

require_once __DIR__.'/../../services/FebSupplierBeneficiary.php';
$febSupplier = FebSupplierBeneficiary::resolve($pdo, $febRequest);
if ($febSupplier !== null) {
    $data['beficiaire_fiche'] = (string)$febSupplier['nom_commercial'];
    $data['tel_beneficiaire_fiche'] = (string)$febSupplier['telephone'];
    $data['personnel_id'] = null;
    $data['fournisseur_id'] = (int)$febSupplier['id_fournisseur'];
    $data['feb_request_id'] = (int)($febRequest['id'] ?? 0);
    $visual = ($febSupplier['supplier_type'] === 'morale' ? $febSupplier['logo_path'] : $febSupplier['photo_path']) ?? '';
    $data['photo_beneficiaire'] = insertFicheCopyFile((string)insertFicheWebPathToFs($visual, insertFicheProjectRoot()), $photoDir, 'photo');
    $data['cni_beneficiaire'] = FebSupplierBeneficiary::requiresIdentity($febSupplier)
        ? insertFicheCopyFile((string)insertFicheWebPathToFs((string)($febSupplier['cni_path'] ?? ''), insertFicheProjectRoot()), $cniDir, 'cni') : '';
}
$identityRequired = $febSupplier === null || FebSupplierBeneficiary::requiresIdentity($febSupplier);
if ($data['photo_beneficiaire'] === '' || ($identityRequired && $data['cni_beneficiaire'] === '')) {
    $missing = [];
    if ($data['photo_beneficiaire'] === '') $missing[] = 'photo';
    if ($data['cni_beneficiaire'] === '') $missing[] = 'CNI';
    AuditTrail::log('decaissement_fiche_insert_failed', [
        'module' => 'decaissement',
        'action' => 'insert_fiche',
        'entity_type' => 'fiche_decaissement',
        'entity_label' => 'Insertion fiche décaissement',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_documents',
            'missing' => $missing,
            'beneficiaire' => $data['beficiaire_fiche'] ?? null,
        ],
    ], $pdo);
    echo json_encode(["status" => "error", "message" => "Documents manquants: " . implode(' et ', $missing) . ". Veuillez uploader le(s) document(s) ou sélectionner un personnel RH disposant des fichiers."]);
    exit;
}

// Nettoyage des données
$data = array_map(function ($value) {
    return is_string($value) ? trim($value) : $value;
}, $data);


// Vérifiez si l'affectation est un chantier (ID = 1)
if ($_POST['affectation'] == "1") {
    $data['chantier_id'] = $_POST['chantier'];
    // Le motif peut être saisi librement
    $data['designation_fiche'] = $_POST['motif_select'];
}

// Vérifiez si l'affectation est un bureau (ID = 19)
if ($_POST['affectation'] == "19") {
    // Utiliser un motif prédéfini ou imposer une validation différente
    if (empty($_POST['motif_input'])) {
        $data['designation_fiche'] = "Aucun motif";
    } else {
        $data['designation_fiche'] = $_POST['motif_input'];
    }

    // Ajoutez les données spécifiques au bureau
    $data['serv_bureau_banamur_id'] = $_POST['service'];
    $data['code_autorisation_feb'] = $codeAutorisationFeb;
}

// Insérer la fiche
if ($ficheObj->insertFiche($data)) {
    AuditTrail::log('decaissement_fiche_inserted', [
        'module' => 'decaissement',
        'action' => 'insert_fiche',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => (string) ($data['num_fiche'] ?? ''),
        'entity_label' => 'Insertion fiche décaissement ' . ($data['num_fiche'] ?? ''),
        'result' => 'success',
        'details' => [
            'num_fiche' => $data['num_fiche'] ?? null,
            'beneficiaire' => $data['beficiaire_fiche'] ?? null,
            'montant_fiche' => isset($data['montant_fiche']) ? (float) $data['montant_fiche'] : null,
            'mode_paiement' => $data['num_piece'] ?? null,
            'code_autorisation_feb' => $codeAutorisationFeb,
        ],
    ], $pdo);
    $subject = "Nouvelle fiche créée";
    $body = "<b>{$data['beficiaire_fiche']}</b> a émis une demande de <b>{$data['montant_fiche']}</b> pour <b>{$data['precision_fiche']}</b>";
    $recipients = [
        "braud@fidest.org" => "Alex BRAUD",
        "amani_ulrich@outlook.fr" => "Ulrich AMANI"
    ];

    // Envoyer l'email
    $emailManagerObj->sendEmail($subject, $body, $recipients);

    //Envoyer message de confirmation de soumission au demandeur
    $whatsappNumber = "+225" . $data['tel_beneficiaire_fiche'];
    $num_fiche = $data['num_fiche'];

    // $whatsapp->sendConfirmationSoumissionFicheDecaissement($whatsappNumber, $data['beficiaire_fiche'], $data['num_fiche']);

    // Vérifier si la demande concerne le carburant dans precision_fiche ou designation_fiche
    $texte_precision = isset($data['precision_fiche']) ? $data['precision_fiche'] : '';
    $texte_designation = isset($data['designation_fiche']) ? $data['designation_fiche'] : '';

    if (
        stripos($texte_precision, 'carburant') !== false ||
        stripos($texte_designation, 'carburant') !== false
    ) {
        // Numéro du DG (à adapter si besoin)
        // Numéro DG par défaut harmonisé (10 chiffres, sans indicatif). Le format E.164 sera appliqué (+225...)
        $num_dg = defined('DG_PHONE') ? DG_PHONE : (getenv('DG_PHONE') ?: '0544577666');
        $whatsappNumberDG = "+225" . preg_replace('/\D+/', '', $num_dg);

        // Appel à la méthode d'envoi de l'alerte au DG avec call-to-action
        $responseApproval = $whatsapp->sendCarburantManageCallToAction(
            $whatsappNumberDG,
            $data['num_fiche'],
            $data['montant_fiche'],
            $data['beficiaire_fiche'],
            $texte_precision ?: $texte_designation
        );

        // Débogage : Vérifier la réponse de Twilio
        if ($responseApproval['status'] !== 'success') {
            error_log("Erreur lors de l'envoi du message WhatsApp : " . $responseApproval['message']);
        }
    }

    // Retourner un message de succès
    echo json_encode(["status" => "success", "message" => "Fiche inseree avec succes."]);
} else {
    AuditTrail::log('decaissement_fiche_insert_failed', [
        'module' => 'decaissement',
        'action' => 'insert_fiche',
        'entity_type' => 'fiche_decaissement',
        'entity_label' => 'Insertion fiche décaissement',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'insert_returned_false',
            'num_fiche' => $data['num_fiche'] ?? null,
            'beneficiaire' => $data['beficiaire_fiche'] ?? null,
        ],
    ], $pdo);
    // Retourner un message d'erreur si l'insertion échoue
    echo json_encode(["status" => "error", "message" => "Une erreur est survenue lors de l'insertion."]);
}

