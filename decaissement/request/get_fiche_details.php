<?php
session_start();
require_once __DIR__ . '/../../auth_check.php';
require_once '../model/Database.php';
require_once '../model/FicheDecaissement.php';
require_once '../../repositories/FournisseurRepository.php';
require_once '../../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../../services/FebBeneficiarySyncService.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';

function resolve_public_asset_from_relative_path(?string $relativePath): array
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return [null, null];
    }

    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    $absolutePath = realpath(__DIR__ . '/../../' . $normalized);
    if (!$absolutePath || !is_file($absolutePath)) {
        return [null, null];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePublic = $scheme . '://' . $host . rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME']))), '/\\');

    return [$basePublic . '/' . str_replace('%2F', '/', rawurlencode($normalized)), $absolutePath];
}

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!isset($_GET['num_fiche'])) {
    echo json_encode(['success' => false, 'message' => 'Numéro de fiche manquant']);
    exit;
}

$num_fiche = $_GET['num_fiche'];

try {
    $pdo = Database::getConnection();
    $ficheModel = new FicheDecaissement($pdo);
    $fournisseurModel = new FournisseurRepository($pdo);
    $legalRequestManager = new EmployeeLegalRequestManager($pdo);
    $fiche = $ficheModel->getDetailsByNum($num_fiche);
    $canAdjustBeneficiary = AccessManager::hasPermission('ajuster_beneficiaire_fournisseur_fiche');
    if (!$canAdjustBeneficiary && (int)($_SESSION['user_id'] ?? 0) > 0) {
        $permissionStmt = $pdo->prepare('SELECT acces_admin, ajuster_beneficiaire_fournisseur_fiche FROM user_devis_banamur WHERE id=:id AND active=1 LIMIT 1');
        $permissionStmt->execute(['id' => (int)$_SESSION['user_id']]);
        $permissionRow = $permissionStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $canAdjustBeneficiary = !empty($permissionRow['acces_admin']) || !empty($permissionRow['ajuster_beneficiaire_fournisseur_fiche']);
        if ($canAdjustBeneficiary) $_SESSION['permissions']['ajuster_beneficiaire_fournisseur_fiche'] = true;
    }
    $adjustmentSuppliers = $canAdjustBeneficiary ? $fournisseurModel->listForSelect(true, true) : [];

    if (!$fiche) {
        $existsSimple = $ficheModel->getByNum($num_fiche) ? true : false;
        echo json_encode(['success' => false, 'message' => 'Fiche non trouvée', 'debug' => ['num_fiche' => $num_fiche, 'exists_simple' => $existsSimple]]);
        exit;
    }

    $fiche = enrich_fiche_labels($fiche);
    $supplierContext = $fournisseurModel->getSupplierDisplayContext(
        isset($fiche['fournisseur_id']) ? (int) $fiche['fournisseur_id'] : null,
        $fiche
    );
    [$supplierVisualUrl, $supplierVisualPath] = resolve_public_asset_from_relative_path($supplierContext['supplier_visual_path'] ?? null);
    [$supplierCniUrl, $supplierCniPath] = resolve_public_asset_from_relative_path($supplierContext['supplier_cni_path'] ?? null);
    $supplierContext['supplier_visual_url'] = $supplierVisualUrl;
    $supplierContext['supplier_visual_fs_path'] = $supplierVisualPath;
    $supplierContext['supplier_cni_url'] = $supplierCniUrl;
    $supplierContext['supplier_cni_fs_path'] = $supplierCniPath;
    $supplierContext['has_supplier_visual'] = (bool) $supplierVisualPath;
    $supplierContext['has_supplier_cni'] = (bool) $supplierCniPath;
    $supplierContext['supplier_document_urls'] = [];
    foreach (($supplierContext['supplier_documents'] ?? []) as $document) {
        [$documentUrl, $documentPath] = resolve_public_asset_from_relative_path($document['path'] ?? null);
        if ($documentUrl === null || $documentPath === null) {
            continue;
        }
        $supplierContext['supplier_document_urls'][] = [
            'label' => (string) ($document['label'] ?? 'Document'),
            'url' => $documentUrl,
            'fs_path' => $documentPath,
        ];
    }
    $beneficiaryMode = (int) ($fiche['personnel_id'] ?? 0) > 0 ? 'personnel' : 'external';
    if ((int) ($fiche['feb_request_id'] ?? 0) > 0) {
        $febStmt = $pdo->prepare('SELECT payload_json FROM employee_legal_requests WHERE id=:id LIMIT 1');
        $febStmt->execute(['id' => (int) $fiche['feb_request_id']]);
        $febPayload = json_decode((string) $febStmt->fetchColumn(), true);
        $selection = is_array($febPayload) ? FebBeneficiarySyncService::selected($pdo, $febPayload) : null;
        if ($selection) $beneficiaryMode = $selection['mode'];
    }
    $beneficiaryContext = [
        'type_label' => ['personnel' => 'Membre du personnel', 'supplier' => 'Fournisseur bénéficiaire', 'external' => 'Bénéficiaire externe'][$beneficiaryMode] ?? 'Bénéficiaire',
        'name' => (string) ($fiche['beficiaire_fiche'] ?? ''),
        'phone' => (string) ($fiche['tel_beneficiaire_fiche'] ?? ''),
        'personnel_id' => (int) ($fiche['personnel_id'] ?? 0),
        'email' => '', 'matricule' => '', 'fonction' => '', 'service' => '',
    ];
    if ($beneficiaryContext['personnel_id'] > 0) {
        $personStmt = $pdo->prepare('SELECT p.email_personnel_soignant,p.matricule_personnel_soignant,f.lib_fonction,s.lib_service FROM personnel p LEFT JOIN fonction f ON f.id_fonction=p.fonction_id LEFT JOIN service s ON s.id_service=p.service_id WHERE p.id_personnel_soignant=:id LIMIT 1');
        $personStmt->execute(['id' => $beneficiaryContext['personnel_id']]);
        $person = $personStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $beneficiaryContext['email'] = (string) ($person['email_personnel_soignant'] ?? '');
        $beneficiaryContext['matricule'] = (string) ($person['matricule_personnel_soignant'] ?? '');
        $beneficiaryContext['fonction'] = (string) ($person['lib_fonction'] ?? '');
        $beneficiaryContext['service'] = (string) ($person['lib_service'] ?? '');
    }

    // Calculer les montants pour cette ligne de déboursé
    $ligne_debourse_id = $fiche['ligne_debourse_id'];
    $montant_prevu = $fiche['ligne_montant'];
    // Requête unique détails fiche

    // Calculer le montant déjà décaissé pour cette ligne
    $stSum = $pdo->prepare("SELECT SUM(montant_fiche) as total_decaisse FROM fiche_decaissement WHERE ligne_debourse_id=:ligne AND decaisse=1 AND num_fiche<>:num");
    $stSum->execute(['ligne' => $ligne_debourse_id, 'num' => $num_fiche]);
    $result = $stSum->fetch(PDO::FETCH_ASSOC);
    $montant_deja_decaisse = $result['total_decaisse'] ?: 0;

    // Montant restant (sans compter cette fiche)
    $montant_restant = $montant_prevu - $montant_deja_decaisse;

    // Vérifier les photos
    $photo_path = null;
    $cni_path = null;

    $photo_path = null;
    $cni_path = null;
    $photo_url = null;
    $cni_url = null;
    $signature_path = null;
    $signature_url = null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Base publique (dossier decaissement niveau au-dessus => racine appli)
    $basePublic = $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    if ($fiche['photo_beneficiaire']) {
        $photoFile = $fiche['photo_beneficiaire'];
        if (file_exists(__DIR__ . '/../uploads/photo/' . $photoFile)) {
            $photo_path = '../uploads/photo/' . $photoFile; // relatif pour compat acteul
            $photo_url = $basePublic . '/uploads/photo/' . rawurlencode($photoFile);
        }
    }
    if ($fiche['cni_beneficiaire']) {
        $cniFile = $fiche['cni_beneficiaire'];
        if (file_exists(__DIR__ . '/../uploads/cni/' . $cniFile)) {
            $cni_path = '../uploads/cni/' . $cniFile;
            $cni_url = $basePublic . '/uploads/cni/' . rawurlencode($cniFile);
        }
    }
    $beneficiaryContext['visual_url'] = $photo_url ?: ($beneficiaryMode === 'supplier' ? $supplierVisualUrl : null);
    $beneficiaryContext['visual_label'] = $photo_url ? 'Photo du bénéficiaire' : ($beneficiaryMode === 'supplier' ? 'Photo ou logo du bénéficiaire' : 'Photo du bénéficiaire');
    $beneficiaryContext['identity_url'] = $cni_url ?: ($beneficiaryMode === 'supplier' ? $supplierCniUrl : null);
    // Signature si disponible
    if (!empty($fiche['signature_fichier'])) {
        $sigFile = $fiche['signature_fichier'];
        if (file_exists(__DIR__ . '/../uploads/signatures/' . $sigFile)) {
            $signature_path = '../uploads/signatures/' . $sigFile;
            $signature_url = $basePublic . '/uploads/signatures/' . rawurlencode($sigFile);
        }
    }

    // Fiches liées même ligne_debourse
    $fiches_liees = [];
    if (!empty($fiche['ligne_debourse_id'])) {
        $stLinked = $pdo->prepare("SELECT f.num_fiche, f.beficiaire_fiche, f.montant_fiche, f.date_creat_fiche, f.details_demande, f.precision_fiche, p.details_demande AS parent_details_demande, f.approuve, f.conforme, f.otp_valide, f.decaisse, f.signature_fichier
                                   FROM fiche_decaissement f
                                   LEFT JOIN fiche_decaissement p ON p.num_fiche = COALESCE(NULLIF(f.parent_num_fiche, ''), NULLIF(f.code_parentale, ''))
                                   WHERE f.ligne_debourse_id=:ligne AND f.num_fiche<>:num
                                   ORDER BY f.date_creat_fiche DESC");
        $stLinked->execute(['ligne' => $fiche['ligne_debourse_id'], 'num' => $fiche['num_fiche']]);
        $fiches_liees = array_map('enrich_fiche_labels', $stLinked->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
    $fiches_liees_stats = [
        'count' => count($fiches_liees),
        'total_decaisse' => 0.0,
        'total_refuse' => 0.0,
    ];
    foreach ($fiches_liees as $linked) {
        $montantLinked = (float)($linked['montant_fiche'] ?? 0);
        if ((int)($linked['decaisse'] ?? 0) === 1) {
            $fiches_liees_stats['total_decaisse'] += $montantLinked;
        }
        if ((int)($linked['approuve'] ?? 0) === 2 || (int)($linked['etat_fiche'] ?? 0) === 2) {
            $fiches_liees_stats['total_refuse'] += $montantLinked;
        }
    }

    // Historique des actions sur cette fiche
    $stmt = $pdo->prepare("
        SELECT * FROM trace_fiche 
        WHERE num_fiche = ? 
        ORDER BY date_action DESC
    ");
    $stHist = $pdo->prepare("SELECT * FROM trace_fiche WHERE num_fiche=:num ORDER BY date_action DESC");
    $stHist->execute(['num' => $num_fiche]);
    $historique = $stHist->fetchAll(PDO::FETCH_ASSOC);

    // ================== Spécifications transport-dépollution (CH060 + carburant) ==================
    $transport_spec = null;
    $transport_spec_exists = false;
    $transport_stats = null; // récap chantier (voyage index, total, tonnage estimé)
    try {
        // On interroge par num_fiche (index créé lors de l'auto-migration) – limite 1 car 1:1 attendu
        $stTrans = $pdo->prepare("SELECT id, fiche_id, num_fiche, chantier_code, chauffeur_nom, chauffeur_tel, vehicule_matricule, volume_m3, quantite_carburant_litre, contexte_detection, created_at, updated_at FROM fiche_transport_spec WHERE num_fiche = :num LIMIT 1");
        $stTrans->execute(['num' => $num_fiche]);
        $rowTrans = $stTrans->fetch(PDO::FETCH_ASSOC);
        if ($rowTrans) {
            $transport_spec = $rowTrans;
            $transport_spec_exists = true;

            // Statistiques chantier spécifiques (ex: CH060 objectif 218 voyages)
            $chantierCode = $rowTrans['chantier_code'] ?? '';
            $voyagesTarget = ($chantierCode === 'CH060') ? 218 : null; // objectif propre au chantier
            // Densité estimée terre (t/m3) – ajustable si besoin
            $densityTPerM3 = 1.6;

            // Totaux pour le chantier
            $stAgg = $pdo->prepare("SELECT COUNT(*) AS voyages_total, COALESCE(SUM(volume_m3),0) AS volume_total FROM fiche_transport_spec WHERE chantier_code = :code");
            $stAgg->execute(['code' => $chantierCode]);
            $agg = $stAgg->fetch(PDO::FETCH_ASSOC) ?: ['voyages_total' => 0, 'volume_total' => 0];

            // Indice du voyage pour cette fiche: rang par id croissant dans ce chantier
            $stRank = $pdo->prepare("SELECT COUNT(*)+1 AS rang FROM fiche_transport_spec WHERE chantier_code = :code AND id < :curId");
            $stRank->execute(['code' => $chantierCode, 'curId' => (int)$rowTrans['id']]);
            $rankRow = $stRank->fetch(PDO::FETCH_ASSOC);
            $voyageIndex = ($rankRow && isset($rankRow['rang'])) ? (int)$rankRow['rang'] : 1;

            // Estimation tonnages
            $volumeFiche = isset($rowTrans['volume_m3']) ? (float)$rowTrans['volume_m3'] : 0.0;
            $tonnageFiche = round($volumeFiche * $densityTPerM3, 2);
            $tonnageTotal = round(((float)$agg['volume_total']) * $densityTPerM3, 2);

            $transport_stats = [
                'chantier_code' => $chantierCode,
                'voyage_index' => $voyageIndex,
                'voyages_total' => (int)($agg['voyages_total'] ?? 0),
                'voyages_target' => $voyagesTarget,
                'voyages_remaining' => ($voyagesTarget !== null) ? max(0, $voyagesTarget - (int)($agg['voyages_total'] ?? 0)) : null,
                'volume_total_m3' => (float)($agg['volume_total'] ?? 0),
                'volume_fiche_m3' => $volumeFiche,
                'density_t_per_m3' => $densityTPerM3,
                'tonnage_fiche_t' => $tonnageFiche,
                'tonnage_total_t' => $tonnageTotal
            ];
        }
    } catch (Exception $e) {
        // Table absente (ancienne version) ou autre erreur : on ignore silencieusement pour compatibilité
        // error_log('[transport_spec] non disponible: ' . $e->getMessage()); // décommenter pour debug si besoin
    }
    // ===============================================================================================

    // Hiérarchie parent/enfants (pour lier carburant ↔ frais de route ↔ location/voyage)
    $hierarchie = [];
    try {
        if (isset($ficheModel) && method_exists($ficheModel, 'getHierarchy')) {
            $hierarchie = $ficheModel->getHierarchy($num_fiche) ?: [];
        }
    } catch (Exception $e) {
        $hierarchie = [];
    }

    $linkedFeb = null;
    $advanceAmortization = $ficheModel->getAdvanceAmortizationSuggestion($num_fiche);
    $febCode = trim((string) ($fiche['code_autorisation_feb'] ?? ''));
    if ($febCode !== '') {
        $linkedRequest = $legalRequestManager->findApprovedFebByAuthorizationCode($febCode);
        if (is_array($linkedRequest)) {
            $linkedPayload = (array) ($linkedRequest['payload'] ?? []);
            $linkedDisplay = (array) ($linkedRequest['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($linkedRequest));
            $linkedFeb = [
                'request_id' => (int) ($linkedRequest['id'] ?? 0),
                'title' => (string) ($linkedRequest['title'] ?? ''),
                'cashflow_need_label' => (string) ($linkedDisplay['cashflow_need_label'] ?? ''),
                'invoice_document' => is_array($linkedPayload['invoice_document'] ?? null) ? (array) $linkedPayload['invoice_document'] : [],
                'justificatifs' => array_values(array_filter((array) ($linkedPayload['justificatifs'] ?? []), static fn($item): bool => is_array($item))),
                'numero_bon_commande_interne' => (string) ($linkedDisplay['numero_bon_commande_interne'] ?? ''),
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'fiche' => $fiche,
        'montant_prevu' => $montant_prevu,
        'montant_deja_decaisse' => $montant_deja_decaisse,
        'montant_restant' => $montant_restant,
        'photo_path' => $photo_path,
        'cni_path' => $cni_path,
        'photo_url' => $photo_url,
        'cni_url' => $cni_url,
        'photo_exists' => (bool)$photo_path,
        'cni_exists' => (bool)$cni_path,
        'supplier_context' => $supplierContext,
        'beneficiary_context' => $beneficiaryContext,
        'historique' => $historique,
        'signature_path' => $signature_path,
        'signature_url' => $signature_url,
        'signature_exists' => (bool)$signature_path,
        'fiches_liees' => $fiches_liees,
        'fiches_liees_stats' => $fiches_liees_stats,
        'transport_spec_exists' => $transport_spec_exists,
        'transport_spec' => $transport_spec,
        'transport_chantier_stats' => $transport_stats,
        'hierarchie' => $hierarchie,
        'linked_feb' => $linkedFeb,
        'advance_amortization' => $advanceAmortization,
        'can_adjust_beneficiary_supplier' => $canAdjustBeneficiary,
        'adjustment_suppliers' => $adjustmentSuppliers,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
