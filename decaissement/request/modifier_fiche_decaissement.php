<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Méthode invalide');
    $required = ['num_fiche', 'beficiaire_fiche', 'montant_fiche'];
    foreach ($required as $r) {
        if (empty($_POST[$r])) throw new Exception('Champ manquant: ' . $r);
    }
    $num = trim($_POST['num_fiche']);
    $benef = trim($_POST['beficiaire_fiche']);
    $montant = (float)$_POST['montant_fiche'];
    $detailsUser = isset($_POST['details_fiche']) ? trim((string)$_POST['details_fiche']) : '';
    $strlenFn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
    $substrFn = function_exists('mb_substr') ? 'mb_substr' : 'substr';
    if ($strlenFn($detailsUser) > 5000) {
        $detailsUser = $substrFn($detailsUser, 0, 5000);
    }
    // Champs liés externes: on ignore toute tentative de modification
    $precision = null; // ignoré
    $designation = null; // ignoré
    $pdo = Database::getConnection();
    $canAdjustAfterValidation = AccessManager::hasPermission('ajuster_beneficiaire_fournisseur_fiche');
    if (!$canAdjustAfterValidation && (int)($_SESSION['user_id'] ?? 0) > 0) {
        $permissionStmt = $pdo->prepare('SELECT acces_admin, ajuster_beneficiaire_fournisseur_fiche FROM user_devis_banamur WHERE id=:id AND active=1 LIMIT 1');
        $permissionStmt->execute(['id' => (int)$_SESSION['user_id']]);
        $permissionRow = $permissionStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $canAdjustAfterValidation = !empty($permissionRow['acces_admin']) || !empty($permissionRow['ajuster_beneficiaire_fournisseur_fiche']);
        if ($canAdjustAfterValidation) $_SESSION['permissions']['ajuster_beneficiaire_fournisseur_fiche'] = true;
    }
    $newSupplierId = (int) ($_POST['fournisseur_id'] ?? 0);

    $st = $pdo->prepare("SELECT f.approuve, f.decaisse, f.details_demande, f.montant_fiche, f.fournisseur_id, f.feb_request_id, f.code_autorisation_feb, c.archived_at FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE f.num_fiche=?");
    $st->execute([$num]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Fiche introuvable');
    if (($_POST['adjustment_source'] ?? '') === 'employee_self') {
        $requestId = (int)($_POST['feb_request_id'] ?? 0);
        $ownerStmt = $pdo->prepare('SELECT id,requester_user_id,payload_json FROM employee_legal_requests WHERE id=:id AND request_type="feb" LIMIT 1');
        $ownerStmt->execute(['id' => $requestId]);
        $ownerRequest = $ownerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$ownerRequest || (int)($ownerRequest['requester_user_id'] ?? 0) !== (int)($_SESSION['user_id'] ?? 0)) throw new Exception('Cette FEB ne vous appartient pas.');
        $ownerPayload = json_decode((string)($ownerRequest['payload_json'] ?? ''), true) ?: [];
        $ownerCode = trim((string)($ownerPayload['numero_bon_commande_interne'] ?? $ownerPayload['code_autorisation_feb'] ?? ''));
        $directLink = (int)($row['feb_request_id'] ?? 0) === $requestId;
        $codeLink = $ownerCode !== '' && strcasecmp($ownerCode, trim((string)($row['code_autorisation_feb'] ?? ''))) === 0;
        if (!$directLink && !$codeLink) throw new Exception('La fiche ne correspond pas à cette FEB.');
    }
    if (!empty($row['archived_at']) && (empty($row['decaisse']) || !$canAdjustAfterValidation)) throw new Exception('Le chantier est archivé : seule une correction d’identité autorisée sur une fiche déjà décaissée reste possible.');
    if (!empty($row['decaisse']) && !$canAdjustAfterValidation) throw new Exception('Déjà décaissée : autorisation spéciale requise pour ajuster uniquement le bénéficiaire ou le fournisseur.');
    if ((int)$row['approuve'] === 1 && !$canAdjustAfterValidation) throw new Exception('Déjà approuvée : autorisation spéciale requise pour modifier le bénéficiaire ou le fournisseur.');
    if ((int)$row['approuve'] === 1 || !empty($row['decaisse'])) {
        // Le droit spécial ne déverrouille ni le montant ni le contenu financier.
        $montant = (float) ($row['montant_fiche'] ?? 0);
        $detailsUser = (string) ($row['details_demande'] ?? '');
    }

    $supplierUpdate = [];
    if ($newSupplierId > 0) {
        if (!$canAdjustAfterValidation) throw new Exception('Vous n’êtes pas autorisé à changer le fournisseur de cette fiche.');
        $supplierStmt = $pdo->prepare('SELECT id_fournisseur,raison_sociale,nom_commercial,contact_nom,telephone,email,adresse,photo_path,logo_path,cni_path,actif,validation_status FROM fournisseur_banamur WHERE id_fournisseur=:id LIMIT 1');
        $supplierStmt->execute(['id' => $newSupplierId]);
        $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$supplier || (int)($supplier['actif'] ?? 0) !== 1 || (string)($supplier['validation_status'] ?? '') !== 'approved') throw new Exception('Le fournisseur choisi est introuvable, inactif ou non validé.');
        $supplierName = trim((string)($supplier['nom_commercial'] ?? '')) ?: trim((string)($supplier['raison_sociale'] ?? ''));
        $supplierPhone = trim((string)($supplier['telephone'] ?? ''));
        $supplierVisual = trim((string)($supplier['photo_path'] ?? '')) ?: trim((string)($supplier['logo_path'] ?? ''));
        $missing = [];
        if ($supplierName === '') $missing[] = 'nom commercial ou raison sociale';
        if ($supplierPhone === '') $missing[] = 'téléphone';
        if (trim((string)($supplier['email'] ?? '')) === '') $missing[] = 'e-mail';
        if (trim((string)($supplier['adresse'] ?? '')) === '') $missing[] = 'adresse';
        if ($supplierVisual === '') $missing[] = 'photo ou logo';
        if ($missing) throw new Exception('Fiche fournisseur incomplète. Renseignez : ' . implode(', ', $missing) . '.');

        $copySupplierFile = static function (string $stored, string $folder, string $prefix): string {
            $stored = ltrim(str_replace('\\', '/', trim($stored)), '/');
            if ($stored === '') return '';
            $source = realpath(__DIR__ . '/../../' . $stored);
            if (!$source || !is_file($source)) return '';
            $extension = strtolower((string)pathinfo($source, PATHINFO_EXTENSION));
            $name = $prefix . '_supplier_' . bin2hex(random_bytes(8)) . ($extension !== '' ? '.' . $extension : '');
            $targetDir = __DIR__ . '/../uploads/' . $folder;
            if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) throw new Exception('Dossier média bénéficiaire indisponible.');
            if (!copy($source, $targetDir . '/' . $name)) throw new Exception('Impossible de copier le média du fournisseur.');
            return $name;
        };
        $benef = $supplierName;
        $supplierUpdate = [
            'supplier_id' => $newSupplierId,
            'supplier_name' => $supplierName,
            'supplier_contact' => trim((string)($supplier['contact_nom'] ?? '')) ?: null,
            'supplier_phone' => $supplierPhone,
            'benef_phone' => $supplierPhone,
            'photo' => $copySupplierFile($supplierVisual, 'photo', 'photo'),
            'cni' => $copySupplierFile((string)($supplier['cni_path'] ?? ''), 'cni', 'cni'),
        ];
    }

    // Préserver les tags d'historique présents dans details_demande (REFUS/RESET/REFUS_ACHAT)
    $prevDetails = (string)($row['details_demande'] ?? '');
    $preserved = [];
    if ($prevDetails !== '') {
        foreach (preg_split('/\r?\n/', $prevDetails) as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            if (preg_match('/^\[(REFUS(_ACHAT)?|RESET)\b/i', $line)) {
                $preserved[] = $line;
            }
        }
    }

    // Éviter de dupliquer des tags si l'utilisateur les a copiés/collés
    $detailsUserClean = $detailsUser;
    if ($detailsUserClean !== '') {
        $detailsUserClean = preg_replace('/^\[(REFUS(_ACHAT)?|RESET)\b.*$/mi', '', $detailsUserClean);
        $detailsUserClean = trim($detailsUserClean);
    }

    $newDetails = $detailsUserClean;
    if (!empty($preserved)) {
        $tagsBlock = implode("\n", $preserved);
        $newDetails = $newDetails !== '' ? trim($newDetails . "\n" . $tagsBlock) : $tagsBlock;
    }

    // Retrait de precision_fiche & designation_fiche de l'UPDATE (figés)
    $supplierSql = $supplierUpdate ? ', fournisseur_id=:supplier_id,fournisseur_nom=:supplier_name,fournisseur_contact_nom=:supplier_contact,fournisseur_telephone=:supplier_phone,tel_beneficiaire_fiche=:benef_phone,personnel_id=NULL,photo_beneficiaire=:photo,cni_beneficiaire=:cni' : '';
    $up = $pdo->prepare("UPDATE fiche_decaissement SET beficiaire_fiche=:b, montant_fiche=:m, details_demande=:d, date_last_modif=NOW(), secur_last_modif=:s{$supplierSql} WHERE num_fiche=:n");
    $secur = !empty($_SESSION['secur_projet']) ? $_SESSION['secur_projet'] : 'SYSTEM';
    $ok = $up->execute(array_merge(['b' => $benef, 'm' => $montant, 'd' => $newDetails, 's' => $secur, 'n' => $num], $supplierUpdate));
    if (!$ok) throw new Exception('Échec mise à jour');

    \AuditTrail::log('decaissement_fiche_updated', [
        'module' => 'decaissement',
        'action' => 'update_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => 'Modification fiche de décaissement ' . $num,
        'result' => 'success',
        'details' => [
            'num_fiche' => $num,
            'beneficiaire' => $benef,
            'montant_fiche' => $montant,
            'preserved_tags_count' => count($preserved),
            'details_length' => $strlenFn($newDetails),
            'new_supplier_id' => $newSupplierId ?: null,
            'old_supplier_id' => (int)($row['fournisseur_id'] ?? 0) ?: null,
            'after_validation_override' => (int)$row['approuve'] === 1,
            'disbursed_identity_override' => !empty($row['decaisse']),
        ],
    ], $pdo);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    if (!empty($pdo) && $pdo instanceof PDO) {
        \AuditTrail::log('decaissement_fiche_update_failed', [
            'module' => 'decaissement',
            'action' => 'update_fiche_decaissement',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => isset($num) ? (string) $num : null,
            'entity_label' => 'Modification fiche de décaissement',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'num_fiche' => isset($num) ? (string) $num : null,
                'beneficiaire' => isset($benef) ? (string) $benef : null,
                'montant_fiche' => isset($montant) ? (float) $montant : null,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
