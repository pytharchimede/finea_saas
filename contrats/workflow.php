<?php
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/model/ContratWorkflowNotifier.php';
require_once __DIR__ . '/../services/PasskeySignatureGuard.php';
require_once __DIR__ . '/../services/ContractWhatsAppCatalog.php';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = $contractManager->get($id);
if (!$c) {
    http_response_code(404);
    exit('Contrat introuvable.');
}
$_SESSION['contract_workflow_csrf'] = $_SESSION['contract_workflow_csrf'] ?? bin2hex(random_bytes(24));
$error = '';
$success = '';
$stageLabels = ['rh' => 'Ressources humaines', 'juridique' => 'Juridique / clauses particulières', 'technique' => 'Département technique', 'logistique' => 'Logistique', 'qhse' => 'QHSE', 'comptabilite' => 'Comptabilité', 'direction' => 'Direction générale'];
$stagePermissions = ['rh' => ['acces_contrat_etape_rh', 'acces_rh'], 'juridique' => ['acces_contrat_etape_juridique', 'acces_rh'], 'technique' => ['acces_contrat_etape_technique', 'acces_liste_chantier', 'acces_planning', 'acces_planning_chantier'], 'qhse' => ['acces_contrat_etape_qhse', 'acces_hse'], 'comptabilite' => ['acces_contrat_etape_comptabilite', 'acces_compta'], 'logistique' => ['acces_contrat_etape_logistique', 'acces_logistique']];
$q = $pdo->prepare("SELECT u.personnel_id,u.signature,UPPER(COALESCE(f.lib_fonction,'')) fonction FROM user_devis_banamur u LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id WHERE u.id=?");
$q->execute([(int)($_SESSION['user_id'] ?? 0)]);
$currentIdentity = $q->fetch(PDO::FETCH_ASSOC) ?: [];
$currentPersonnelId = (int)($currentIdentity['personnel_id'] ?? 0);
$currentFunction = (string)($currentIdentity['fonction'] ?? '');
$profileSignature = '';
$profileSignatureRef = trim((string)($currentIdentity['signature'] ?? ''));
if (preg_match('~^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/=]+$~', $profileSignatureRef)) {
    $profileSignature = $profileSignatureRef;
} elseif ($profileSignatureRef !== '') {
    $projectRoot = dirname(__DIR__);
    foreach ([$projectRoot . '/' . ltrim($profileSignatureRef, '/'), $projectRoot . '/uploads/user_signatures/' . basename($profileSignatureRef)] as $candidate) {
        $real = realpath($candidate);
        if (!$real || !str_starts_with($real, $projectRoot . '/uploads/user_signatures/') || !is_file($real)) continue;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($real);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) continue;
        $profileSignature = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($real));
        break;
    }
}
$isAdmin = hasPermission('acces_admin');
$isDg = str_contains($currentFunction, 'DIRECTEUR GENERAL');
$isDrh = hasPermission('acces_rh') || str_contains($currentFunction, 'DIRECTEUR RESSOURCES HUMAINES') || str_contains($currentFunction, 'DIRECTEUR DES RESSOURCES HUMAINES');
$canSeeAll = $isAdmin || $isDg || $isDrh;
$canStage = static function (string $stage) use ($stagePermissions, $isAdmin, $isDg, $isDrh): bool {
    if ($isAdmin || $isDrh || ($isDg && $stage === 'comptabilite')) return true;
    foreach ($stagePermissions[$stage] ?? [] as $permission) if (hasPermission($permission)) return true;
    return false;
};
$personnel = $pdo->query("SELECT p.id_personnel_soignant id,p.nom_personnel_soignant nom,p.matricule_personnel_soignant matricule,COALESCE(NULLIF(f.lib_fonction,''),'Fonction non renseignée') fonction,MIN(u.id) user_id FROM personnel p JOIN user_devis_banamur u ON u.personnel_id=p.id_personnel_soignant AND u.active=1 LEFT JOIN fonction f ON f.id_fonction=p.fonction_id WHERE p.date_sortie IS NULL OR p.date_sortie='0000-00-00' GROUP BY p.id_personnel_soignant,p.nom_personnel_soignant,p.matricule_personnel_soignant,f.lib_fonction ORDER BY p.nom_personnel_soignant")->fetchAll(PDO::FETCH_ASSOC);
$allPersonnel = $personnel;
$hierarchySignerPersonnel = [];
try {
    $hierarchyRows = $pdo->query("SELECT UPPER(s.lib_service) service_label,u.personnel_id FROM rh_service_hierarchy h JOIN service s ON s.id_service=h.service_id JOIN user_devis_banamur u ON u.id=h.contract_signer_user_id AND u.active=1 WHERE h.contract_signer_user_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($hierarchyRows as $hierarchyRow) {
        $label = (string)$hierarchyRow['service_label'];
        $stage = str_contains($label, 'RESSOURCES HUMAINES') ? 'rh' : ((str_contains($label, 'JURIDIQUE') || str_contains($label, 'LEGAL')) ? 'juridique' : (str_contains($label, 'TECHNIQUE') ? 'technique' : (str_contains($label, 'LOGISTIQUE') ? 'logistique' : ((str_contains($label, 'HSE') || str_contains($label, 'QHSE')) ? 'qhse' : (str_contains($label, 'COMPTABILITE') ? 'comptabilite' : (str_contains($label, 'DIRECTION GENERAL') ? 'direction' : ''))))));
        if ($stage !== '') $hierarchySignerPersonnel[$stage] = (int)$hierarchyRow['personnel_id'];
    }
} catch (Throwable $e) {
}
$workflow = $contractManager->workflow($id);
$current = null;
foreach ($workflow as $row) if ($row['statut'] === 'en_cours') {
    $current = $row;
    break;
}
$visibleWorkflow = array_values(array_filter($workflow, fn($row) => $canSeeAll || $canStage($row['etape']) || (int)($row['signataire_personnel_id'] ?? 0) === $currentPersonnelId));
if ($canSeeAll) $visibleWorkflow[] = ['etape' => 'direction', 'ordre' => 7, 'statut' => $c['statut'] === 'actif' ? 'validee' : ($c['statut'] === 'a_signer' ? 'en_cours' : 'en_attente'), 'donnees' => [], 'signataire_nom' => '', 'signataire_fonction' => '', 'signataire_personnel_id' => null];
if (!$visibleWorkflow) {
    http_response_code(403);
    exit('Aucune partie de ce contrat ne vous est attribuée.');
}
$selectedStage = (string)($_GET['etape'] ?? $_POST['etape'] ?? ($current && ($canSeeAll || $canStage($current['etape']) || (int)($current['signataire_personnel_id'] ?? 0) === $currentPersonnelId) ? $current['etape'] : $visibleWorkflow[0]['etape']));
$stage = null;
foreach ($visibleWorkflow as $row) if ($row['etape'] === $selectedStage) {
    $stage = $row;
    break;
}
if (!$stage) { http_response_code(403); exit('Cette étape ne vous est pas attribuée.'); }
$stageSignatureHash = ContractWhatsAppCatalog::stageHash($stage);
// Quand l’étape n’a pas encore de signataire, proposer par défaut le membre du
// service actuellement connecté. La valeur reste modifiable avant l’enregistrement.
if ($selectedStage !== 'direction' && empty($stage['signataire_personnel_id']) && $currentPersonnelId > 0 && $canStage($selectedStage) && $stage['statut'] !== 'validee') {
    $stage['signataire_personnel_id'] = $currentPersonnelId;
}
$dgEditMode = $isDg && !empty($c['dg_edit_opened_at']);
$stageAccess = $canStage($selectedStage) || (int)($stage['signataire_personnel_id'] ?? 0) === $currentPersonnelId || $dgEditMode;
$previousExportStages = [];
if (($stageAccess || $canSeeAll) && (int)($stage['ordre'] ?? 0) > 1) {
    foreach ($workflow as $workflowRow) {
        if ((int)($workflowRow['ordre'] ?? 0) >= (int)$stage['ordre']) continue;
        $previousExportStages[] = [
            'etape' => (string)$workflowRow['etape'],
            'label' => $stageLabels[$workflowRow['etape']] ?? ucfirst((string)$workflowRow['etape']),
            'statut' => (string)($workflowRow['statut'] ?? 'en_attente'),
        ];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save') === 'save' && isset($_POST['data']) && is_array($_POST['data'])) foreach ($_POST['data'] as $postedKey => $postedValue) if (is_array($postedValue) && !in_array($postedKey, ['debourse_avancement', 'planning_poids', 'planning_avancement', 'planning_echeances'], true)) $_POST['data'][$postedKey] = implode("\n", array_values(array_filter(array_map(static fn($item) => trim((string)$item), $postedValue), static fn($item) => $item !== '')));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedStage === 'rh' && ($_POST['action'] ?? 'save') === 'assign_signers') {
    try {
        if (!hash_equals((string)$_SESSION['contract_workflow_csrf'], (string)($_POST['_csrf'] ?? ''))) throw new RuntimeException('Session expirée.');
        $workflowSigners = (array)($_POST['workflow_signers'] ?? []);
        if (!$isDrh && !$isAdmin) throw new RuntimeException('Réaffectation réservée aux RH et administrateurs.');
        $contractManager->assignWorkflowSigners($id, $workflowSigners, $isDrh || $isAdmin);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $_POST['action'] = 'assignment_error';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['contract_workflow_csrf'], (string)($_POST['_csrf'] ?? ''))) throw new RuntimeException('Session expirée.');
        if (!$stageAccess && !($isDg && (string)($_POST['action'] ?? '') === 'reopen_by_dg')) throw new RuntimeException('Vous ne disposez pas du droit requis pour cette étape.');
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'assign_signers') {
            if (!$isDrh && !$isAdmin) throw new RuntimeException('Cette réaffectation globale est réservée aux RH et administrateurs.');
            $success = 'Les validateurs du circuit ont été mis à jour.';
        } elseif ($action === 'restart_internal_workflow') {
            if (!$isAdmin && !$isDrh && !$isDg) throw new RuntimeException('Cette réouverture complète est réservée à l’administration, au RH ou à la Direction générale.');
            $contractManager->restartInternalWorkflow($id, (int)($_SESSION['user_id'] ?? 0));
            header('Location: workflow.php?id=' . $id . '&etape=rh');
            exit;
        } elseif ($action === 'reopen_by_dg') {
            if (!$isDg) throw new RuntimeException('Cette réouverture est réservée au Directeur général.');
            $contractManager->reopenForDgEdit($id, (int)($_SESSION['user_id'] ?? 0));
            $success = 'Mode édition DG activé : toutes les étapes peuvent être corrigées avant votre signature.';
        } elseif ($action === 'cancel_contract') {
            if (!$isDrh) throw new RuntimeException('L’annulation du contrat est réservée au DRH.');
            $contractManager->cancelByDrh($id, (int)($_SESSION['user_id'] ?? 0));
            $success = 'Contrat annulé. Les lignes et déboursés associés sont à nouveau disponibles.';
        } elseif ($action === 'delegate_signer') {
            if (!$stageAccess) throw new RuntimeException('Vous ne pouvez pas déléguer cette étape.');
            $contractManager->delegateWorkflowStageSigner($id, $selectedStage, (int)($_POST['signataire_personnel_id'] ?? 0), (int)($_SESSION['user_id'] ?? 0), $isAdmin || $isDrh);
            $success = 'Ordre de signature transmis au collaborateur sélectionné.';
        } elseif ($action === 'save') {
            $data = (array)($_POST['data'] ?? []);
            foreach ($data as $key => $value) $data[$key] = is_string($value) ? trim($value) : $value;
            // Les contrats historiques peuvent contenir une étape juridique
            // partiellement initialisée. On complète les champs structurels avant
            // la validation, sans écraser une clause déjà saisie.
            if ($selectedStage === 'juridique') {
                if (trim((string)($data['parties_donneur'] ?? '')) === '') $data['parties_donneur'] = "BANAMUR INDUSTRIES ET TECHNOLOGIES\nDonneur d’ordre\nReprésentée par : Directeur Général";
                if (trim((string)($data['parties_sous_traitant'] ?? '')) === '') $data['parties_sous_traitant'] = trim((string)($c['fournisseur_nom'] ?? '') . "\nSous-traitant\nTéléphone : " . ($c['telephone'] ?? '') . "\nE-mail : " . ($c['email'] ?? '') . "\nAdresse : " . ($c['adresse'] ?? ''));
                $articles = json_decode((string)($data['articles_juridiques'] ?? ''), true);
                if (!is_array($articles) || count($articles) < 12) $data['articles_juridiques'] = json_encode(ContratSousTraitance::legalArticleTemplate((string)($c['objet'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($selectedStage === 'technique' && !empty($_FILES['planning_file']['name'])) {
                $file = $_FILES['planning_file'];
                if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Le planning n’a pas pu être téléversé.');
                if ((int)$file['size'] > 15 * 1024 * 1024) throw new RuntimeException('Le planning doit faire moins de 15 Mo.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $ext = ['application/pdf' => 'pdf', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? null;
                if (!$ext) throw new RuntimeException('Planning PDF, XLSX, JPG ou PNG attendu.');
                $name = 'planning_contrat_' . $id . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
                if (!move_uploaded_file($file['tmp_name'], $contractUploadDirectory . '/' . $name)) throw new RuntimeException('Téléversement du planning impossible.');
                $data['planning_file'] = 'uploads/contracts/' . $name;
            }
            if ($selectedStage === 'qhse' && !empty($_FILES['qhse_file']['name'])) {
                $file = $_FILES['qhse_file'];
                if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Le registre QHSE n’a pas pu être téléversé.');
                if ((int)$file['size'] > 15 * 1024 * 1024) throw new RuntimeException('Le registre QHSE doit faire moins de 15 Mo.');
                $originalExtension = strtolower((string)pathinfo((string)$file['name'], PATHINFO_EXTENSION));
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $allowedMimes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel', 'application/octet-stream'];
                if (!in_array($originalExtension, ['xlsx', 'xls'], true) || !in_array($mime, $allowedMimes, true)) throw new RuntimeException('Un fichier Excel (.xlsx ou .xls) est attendu pour le registre QHSE.');
                $name = 'qhse_contrat_' . $id . '_' . bin2hex(random_bytes(5)) . '.' . $originalExtension;
                if (!move_uploaded_file($file['tmp_name'], $contractUploadDirectory . '/' . $name)) throw new RuntimeException('Téléversement du registre QHSE impossible.');
                $data['qhse_file'] = 'uploads/contracts/' . $name;
            }
            if ($selectedStage === 'qhse' && empty($data['qhse_file']) && !empty($stage['donnees']['qhse_file'])) $data['qhse_file'] = (string)$stage['donnees']['qhse_file'];
            $required = ['rh' => ['dossier_fournisseur'], 'juridique' => ['parties_donneur', 'parties_sous_traitant', 'articles_juridiques'], 'technique' => ['objet_contrat', 'description_travaux', 'planning_description', 'methodologie', 'date_debut', 'delai_jours'], 'qhse' => ['epis', 'exigences_qhse'], 'comptabilite' => ['condition_reglement_taches'], 'logistique' => ['vehicules_engins', 'materiaux', 'materiel_outillage']][$selectedStage] ?? [];
            if ($selectedStage === 'qhse' && !empty($data['qhse_file'])) $required = [];
            foreach ($required as $field) if (trim((string)($data[$field] ?? '')) === '') throw new RuntimeException('Tous les champs obligatoires de cette étape doivent être renseignés.');
            if ($selectedStage === 'comptabilite') {
                foreach (['taux_acompte', 'retenue_garantie'] as $percentageField) {
                    $percentageValue = $data[$percentageField] ?? null;
                    if (!is_numeric($percentageValue) || (float)$percentageValue < 0 || (float)$percentageValue > 100) throw new RuntimeException('Les pourcentages comptables doivent être compris entre 0 % et 100 %.');
                }
                if ((float)($data['taux_acompte'] ?? 0) > 0 && trim((string)($data['condition_reglement_acompte'] ?? '')) === '') throw new RuntimeException('Précisez la condition de règlement de l’acompte.');
                if ((float)($data['retenue_garantie'] ?? 0) > 0 && trim((string)($data['condition_reglement_retenue'] ?? '')) === '') throw new RuntimeException('Précisez la condition de restitution de la retenue de garantie.');
            }
            if (in_array($selectedStage, ['technique', 'comptabilite'], true) && isset($data['planning_avancement'])) {
                foreach ((array)$data['planning_avancement'] as $progress) if (!is_numeric($progress) || (float)$progress < 0 || (float)$progress > 100) throw new RuntimeException('Chaque taux d’avancement du planning doit être compris entre 0 % et 100 %.');
            }
            if ($selectedStage === 'comptabilite' && isset($data['planning_poids'])) {
                $planningStageData = [];
                foreach ($workflow as $workflowRow) if (($workflowRow['etape'] ?? '') === 'technique') $planningStageData = (array)($workflowRow['donnees'] ?? []);
                $planningItems = is_array($planningStageData['planning_description'] ?? null) ? $planningStageData['planning_description'] : preg_split('/\R/', (string)($planningStageData['planning_description'] ?? ''));
                $planningItems = array_values(array_filter(array_map('trim', array_map('strval', (array)$planningItems)), static fn(string $item): bool => $item !== ''));
                $weights = (array)$data['planning_poids'];
                $deadlines = (array)($data['planning_echeances'] ?? []);
                foreach ((array)$c['lignes'] as $contractLine) {
                    $debourseLines = array_values(array_filter((array)($contractLine['debourse_lignes'] ?? []), 'is_array'));
                    if (!$debourseLines) $debourseLines = [['debourse_id' => 'devis-' . $contractLine['ligne_devis_id']]];
                    foreach ($debourseLines as $debourseLine) {
                        $groupId = (string)($debourseLine['debourse_id'] ?? ('devis-' . $contractLine['ligne_devis_id']));
                        $weightTotal = 0.0;
                        foreach ($planningItems as $planningIndex => $_planningItem) {
                            $weightKey = 'planning_' . $groupId . '_' . $planningIndex;
                            $weightValue = $weights[$weightKey] ?? null;
                            if (!is_numeric($weightValue) || (float)$weightValue < 0 || (float)$weightValue > 100) throw new RuntimeException('Chaque fraction de paiement doit être comprise entre 0 % et 100 %.');
                            $weightTotal += (float)$weightValue;
                        }
                        if ($planningItems && abs($weightTotal - 100) > 0.01) throw new RuntimeException('Les fractions de paiement du déboursé « ' . $contractLine['designation'] . ' » doivent totaliser exactement 100 %.');
                        foreach ($planningItems as $planningIndex => $planningItem) {
                            $deadline = (array)($deadlines['planning_' . $groupId . '_' . $planningIndex] ?? []);
                            $type = (string)($deadline['type'] ?? 'immediate');
                            if (!in_array($type, ['immediate', 'after_delay', 'after_condition'], true)) throw new RuntimeException('Le type d’échéance de « ' . $planningItem . ' » est invalide.');
                            if ($type === 'after_delay' && (int)($deadline['delai'] ?? 0) < 1) throw new RuntimeException('Indiquez le délai de paiement de « ' . $planningItem . ' ».');
                            if ($type === 'after_condition' && trim((string)($deadline['condition'] ?? '')) === '') throw new RuntimeException('Indiquez la condition de paiement de « ' . $planningItem . ' ».');
                        }
                    }
                }
                if ((float)($data['retenue_garantie'] ?? 0) > 0) {
                    $retentionDeadline = (array)($deadlines['restitution_retenue'] ?? []);
                    $retentionDeadlineType = (string)($retentionDeadline['type'] ?? 'immediate');
                    if (!in_array($retentionDeadlineType, ['immediate', 'after_delay', 'after_condition'], true)) throw new RuntimeException('Le type d’échéance de restitution de la retenue est invalide.');
                    if ($retentionDeadlineType === 'after_delay' && (int)($retentionDeadline['delai'] ?? 0) < 1) throw new RuntimeException('Indiquez le délai de restitution de la retenue de garantie.');
                    if ($retentionDeadlineType === 'after_condition' && trim((string)($retentionDeadline['condition'] ?? '')) === '') throw new RuntimeException('Indiquez la condition de restitution de la retenue de garantie.');
                }
            }
            $signerId = (int)($_POST['signataire_personnel_id'] ?? 0);
            if ($isDrh && $signerId < 1) $signerId = $currentPersonnelId;
            // Valider les données techniques avant toute annulation de signature : une erreur
            // de date ou de délai ne doit jamais rouvrir le circuit par erreur.
            if ($selectedStage === 'technique') {
                $technicalStart = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($data['date_debut'] ?? ''));
                $technicalDuration = (int)($data['delai_jours'] ?? 0);
                if (!$technicalStart || $technicalStart->format('Y-m-d') !== ($data['date_debut'] ?? '') || $technicalDuration < 1) throw new RuntimeException('La date de début ou le délai technique est invalide.');
            }
            $saveResult = $contractManager->saveWorkflowStage($id, $selectedStage, $data, $signerId, $isDrh || $dgEditMode, (int)($_SESSION['user_id'] ?? 0));
            if (!empty($saveResult['signature_reset'])) {
                try {
                    (new ContratWorkflowNotifier($pdo))->notifyRevision($id, $selectedStage, (array)($saveResult['reset_stages'] ?? []), (int)($_SESSION['user_id'] ?? 0));
                } catch (Throwable $notificationError) {
                    error_log('[contrats] Notification de modification impossible : ' . $notificationError->getMessage());
                }
                $success = 'Modification enregistrée. Votre signature a été annulée : signez à nouveau cette étape. Les autres signataires ont été alertés.';
            }
            if ($success === '') $success = 'Étape enregistrée.';
        } elseif ($action === 'sign') {
            $signatureProof = PasskeySignatureGuard::requireVerified((int)($_SESSION['user_id'] ?? 0), 'contract:' . $id . ':' . $selectedStage . ':' . (string)($_POST['stage_hash'] ?? ''));
            $contractManager->signWorkflowStage($id, $selectedStage, (string)($_POST['signature'] ?? ''), (int)($_SESSION['user_id'] ?? 0), $currentPersonnelId, $isDrh || $isAdmin, $stage['statut'] === 'en_attente' && $canStage($selectedStage), (string)($_POST['stage_hash'] ?? ''), $signatureProof);
            header('Location: workflow.php?id=' . $id);
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $c = $contractManager->get($id);
    $dgEditMode = $isDg && !empty($c['dg_edit_opened_at']);
    $workflow = $contractManager->workflow($id);
    foreach ($workflow as $row) if ($row['etape'] === $selectedStage) {
        $stage = $row;
        $stageSignatureHash = ContractWhatsAppCatalog::stageHash($stage);
        break;
    }
    $stageAccess = $canStage($selectedStage) || (int)($stage['signataire_personnel_id'] ?? 0) === $currentPersonnelId || $dgEditMode;
}
$data = (array)($stage['donnees'] ?? []);
foreach ($data as $dataKey => $dataValue) if (is_array($dataValue) && !in_array($dataKey, ['debourse_avancement', 'planning_poids', 'planning_avancement', 'planning_echeances'], true)) $data[$dataKey] = implode("\n", array_map('strval', $dataValue));
$supplierIdentity = trim((string)($c['fournisseur_nom'] ?? ''));
if ($selectedStage === 'juridique') {
    if (trim((string)($data['parties_donneur'] ?? '')) === '') $data['parties_donneur'] = "BANAMUR INDUSTRIES ET TECHNOLOGIES\nDonneur d’ordre\nReprésentée par : Directeur Général";
    if (trim((string)($data['parties_sous_traitant'] ?? '')) === '') $data['parties_sous_traitant'] = trim($supplierIdentity . "\nSous-traitant\nTéléphone : " . ($c['telephone'] ?? '') . "\nE-mail : " . ($c['email'] ?? '') . "\nAdresse : " . ($c['adresse'] ?? ''));
    $existingArticles = json_decode((string)($data['articles_juridiques'] ?? ''), true);
    if (trim((string)($stage['donnees_json'] ?? '')) === '' || ($stage['donnees_json'] ?? '') === '{}' || !is_array($existingArticles) || count($existingArticles) < 12) $data['articles_juridiques'] = json_encode(ContratSousTraitance::legalArticleTemplate((string)($c['objet'] ?? '')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
if ($selectedStage === 'comptabilite') {
    // Valeurs d'aide pour une première saisie. Elles restent entièrement
    // modifiables et ne remplacent jamais un paramétrage déjà enregistré.
    foreach (['taux_acompte' => '20', 'retenue_garantie' => '5', 'condition_reglement_taches' => 'Paiement de chaque ligne de tâche après réception des travaux et dépôt de la facture correspondante.'] as $field => $defaultValue) {
        if (!array_key_exists($field, $data) || trim((string)$data[$field]) === '') $data[$field] = $defaultValue;
    }
}
$dgSigned = !empty($c['signature_banamur']);
$legacySignatureRegularization = ($isAdmin || $isDrh)
    && $stage['statut'] === 'validee'
    && empty($stage['signature']);
$editable = $stageAccess && $c['statut'] !== 'resilie' && !$dgSigned;
$fields = [
    'rh' => [['dossier_fournisseur', 'État du dossier fournisseur', 'select'], ['pieces_manquantes', 'Pièces manquantes ou observations', 'textarea'], ['ouvriers', 'Nombre d’ouvriers', 'number'], ['techniciens', 'Nombre de techniciens', 'number'], ['main_oeuvre', 'Autre main-d’œuvre sollicitée', 'number']],
    'juridique' => [['parties_donneur', 'Entre les soussignés — Donneur d’ordre', 'textarea'], ['parties_sous_traitant', 'Entre les soussignés — Sous-traitant', 'textarea'], ['articles_juridiques', 'Articles et clauses', 'textarea'], ['clauses_particulieres', 'Clauses particulières complémentaires', 'textarea'], ['annexes_juridiques', 'Annexes et documents contractuels', 'textarea']],
    'technique' => [['objet_contrat', 'Objet précis du contrat', 'text'], ['date_debut', 'Début prévu', 'date'], ['delai_jours', 'Délai d’exécution (jours)', 'number'], ['description_travaux', 'Description technique des travaux', 'textarea'], ['planning_description', 'Planning d’exécution proposé', 'textarea'], ['methodologie', 'Mode opératoire / méthodologie', 'textarea'], ['besoins_techniques', 'Besoins techniques et moyens nécessaires', 'textarea']],
    'qhse' => [['epis', 'EPI obligatoires', 'textarea'], ['exigences_qhse', 'Exigences QHSE et mesures de prévention', 'textarea'], ['formations_habilitations', 'Formations, habilitations et accueils sécurité requis', 'textarea']],
    'comptabilite' => [['taux_acompte', 'Acompte contractuel (%)', 'number'], ['retenue_garantie', 'Retenue de garantie (%)', 'number'], ['condition_reglement_acompte', '1 — Condition de règlement de l’acompte', 'textarea'], ['condition_reglement_taches', '2 — Condition de règlement des lignes de tâches (après dépôt de facture)', 'textarea'], ['condition_reglement_retenue', '3 — Condition de restitution de la retenue de garantie (si supérieure à 0 %)', 'textarea']],
    'logistique' => [['vehicules_engins', 'Véhicules et engins validés', 'textarea'], ['materiaux', 'Matériaux à acquérir ou mobiliser', 'textarea'], ['materiel_outillage', 'Matériel et outillage', 'textarea'], ['moyens_logistiques', 'Autres moyens logistiques et conditions de disponibilité', 'textarea']]
];
$contractFinanceLines = [];
$contractDebourseGroups = [];
foreach ((array)$c['lignes'] as $contractLine) {
    $debourseLines = array_values(array_filter((array)($contractLine['debourse_lignes'] ?? []), 'is_array'));
    if (!$debourseLines) $debourseLines = [['ligne_debourse_id' => 'devis-'.$contractLine['ligne_devis_id'], 'debourse_id' => 'devis-'.$contractLine['ligne_devis_id'], 'designation' => $contractLine['designation'], 'montant' => $contractLine['montant_contractuel'], 'categorie' => '']];
    foreach ($debourseLines as $debourseLine) {
        $groupId = (string)($debourseLine['debourse_id'] ?? ('devis-'.$contractLine['ligne_devis_id']));
        $contractDebourseGroups[$groupId]['label'] = $contractLine['designation'] . ' · Déboursé #' . $groupId;
        $contractDebourseGroups[$groupId]['lines'][] = ['id' => (string)($debourseLine['ligne_debourse_id'] ?? $groupId), 'designation' => (string)($debourseLine['designation'] ?? 'Poste de déboursé'), 'montant' => max(0, (float)($debourseLine['montant'] ?? 0)), 'categorie' => (string)($debourseLine['categorie'] ?? '')];
    }
}
$contractDebourseTotal = array_sum(array_map(static fn(array $group): float => array_sum(array_map(static fn(array $line): float => (float)$line['montant'], $group['lines'] ?? [])), $contractDebourseGroups));
foreach ($contractDebourseGroups as $groupId => $group) {
    $groupTotal = array_sum(array_map(static fn(array $line): float => (float)$line['montant'], $group['lines'] ?? []));
    foreach ($group['lines'] as $line) $contractFinanceLines[] = $line + ['group' => $group['label'], 'group_total' => $groupTotal, 'group_weight' => $contractDebourseTotal > 0 ? round($groupTotal * 100 / $contractDebourseTotal, 4) : 0, 'line_weight' => $groupTotal > 0 ? round((float)$line['montant'] * 100 / $groupTotal, 4) : 0];
}
$technicalWorkflowData = [];
foreach ($workflow as $workflowRow) if (($workflowRow['etape'] ?? '') === 'technique') $technicalWorkflowData = (array)($workflowRow['donnees'] ?? []);
$splitWorkflowItems = static fn($value): array => array_values(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : preg_split('/\R/', (string)$value)), static fn(string $item): bool => $item !== ''));
$planningTitles = $splitWorkflowItems($technicalWorkflowData['planning_description'] ?? '');
$planningStarts = $splitWorkflowItems($technicalWorkflowData['planning_start'] ?? '');
$planningEnds = $splitWorkflowItems($technicalWorkflowData['planning_end'] ?? '');
$planningProgress = is_array($technicalWorkflowData['planning_avancement'] ?? null) ? $technicalWorkflowData['planning_avancement'] : $splitWorkflowItems($technicalWorkflowData['planning_avancement'] ?? '');
$contractFinancePlanning = [];
foreach ($contractDebourseGroups as $groupId => $group) {
    $groupTotal = array_sum(array_map(static fn(array $line): float => (float)$line['montant'], $group['lines'] ?? []));
    foreach ($planningTitles as $planningIndex => $planningTitle) {
        $contractFinancePlanning[] = [
            'id' => 'planning_' . $groupId . '_' . $planningIndex,
            'group_id' => (string)$groupId,
            'group' => (string)$group['label'],
            'title' => (string)$planningTitle,
            'start' => (string)($planningStarts[$planningIndex] ?? ''),
            'end' => (string)($planningEnds[$planningIndex] ?? ''),
            'technical_progress' => max(0, min(100, (float)($planningProgress[$planningIndex] ?? 0))),
            'group_total' => $groupTotal,
            'group_weight' => $contractDebourseTotal > 0 ? round($groupTotal * 100 / $contractDebourseTotal, 4) : 0,
        ];
    }
}
$workflowAssignments = [];
foreach ($workflow as $assignment) $workflowAssignments[$assignment['etape']] = (int)($assignment['signataire_personnel_id'] ?? 0);
$workflowAssignments['direction'] = (int)($c['signataire_banamur_personnel_id'] ?? 0);
$pageTitle = 'Circuit contractuel ' . $c['reference'];
$activeMenu = 'contrats';
require __DIR__ . '/../decaissement/includes/header.php';
require __DIR__ . '/../decaissement/includes/navbar.php'; ?>
<script type="application/json" id="workflowAllPersonnel">
    <?= json_encode($allPersonnel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>
</script>
<script type="application/json" id="workflowSignerAssignments">
    <?= json_encode($workflowAssignments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>
</script>
<script type="application/json" id="workflowHierarchySigners">
    <?= json_encode($hierarchySignerPersonnel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>
</script>
<script type="application/json" id="workflowStageData">
    <?= json_encode($stage['donnees'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>
</script>
<script>
    window.contractWorkflowStage = <?= json_encode($selectedStage) ?>;
    window.contractWorkflowEditable = <?= $editable ? 'true' : 'false' ?>;
    window.contractWorkflowDrh = <?= ($isDrh || $isAdmin) ? 'true' : 'false' ?>;
    window.contractWorkflowDgSigned = <?= $dgSigned ? 'true' : 'false' ?>;
    window.contractWorkflowCurrentPersonnel = <?= $currentPersonnelId ?>;
    window.contractWorkflowProfileSignature = <?= json_encode($profileSignature, JSON_UNESCAPED_SLASHES) ?>;
    window.contractWorkflowStoredStageSignature = <?= json_encode($stage['statut'] === 'en_cours' ? (string)($stage['signature'] ?? '') : '', JSON_UNESCAPED_SLASHES) ?>;
    window.contractFinancePlanning = <?= json_encode($contractFinancePlanning, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="assets/workflow-repeatable.js?v=<?= filemtime(__DIR__ . '/assets/workflow-repeatable.js') ?>" defer></script>
<script src="assets/workflow-legal-articles.js?v=<?= filemtime(__DIR__ . '/assets/workflow-legal-articles.js') ?>" defer></script>
<script src="assets/workflow-planning-upload.js?v=<?= filemtime(__DIR__ . '/assets/workflow-planning-upload.js') ?>" defer></script>
<script src="assets/workflow-finance-schedule.js?v=<?= filemtime(__DIR__ . '/assets/workflow-finance-schedule.js') ?>" defer></script>
<script src="assets/workflow-signers.js?v=<?= filemtime(__DIR__ . '/assets/workflow-signers.js') ?>" defer></script>
<script src="assets/workflow-select-search.js?v=<?= filemtime(__DIR__ . '/assets/workflow-select-search.js') ?>" defer></script>
<script src="assets/workflow-direction-layout.js?v=<?= filemtime(__DIR__ . '/assets/workflow-direction-layout.js') ?>" defer></script>
<link rel="stylesheet" href="assets/workflow.css?v=<?= filemtime(__DIR__ . '/assets/workflow.css') ?>">
<main class="mx-auto max-w-6xl px-4 py-8"><?php if ($isAdmin || $isDrh): ?><a href="suivi_whatsapp.php" class="mb-4 inline-block rounded-xl border bg-white px-4 py-2 font-bold">Suivi des signatures WhatsApp</a><?php endif; ?>
    <header class="rounded-3xl bg-slate-950 p-7 text-white">
        <p class="text-xs font-black uppercase tracking-widest text-amber-300">Circuit interne du contrat</p>
        <h1 class="mt-2 text-3xl font-black"><?= $h($c['reference'] . ' · ' . $c['chantier_code']) ?></h1>
        <p class="mt-2 text-slate-300">RH → Juridique → Technique → Logistique → QHSE → Comptabilité. La dernière validation transmet le contrat complet à la Direction générale pour signature avec le prestataire.</p>
    </header>
    <nav class="mt-6 grid gap-3 md:grid-cols-5"><?php foreach ($visibleWorkflow as $item): ?><a href="?id=<?= $id ?>&etape=<?= $h($item['etape']) ?>" class="rounded-2xl border p-4 <?= $item['etape'] === $selectedStage ? 'border-amber-500 bg-amber-50' : 'bg-white' ?>"><small class="font-black uppercase text-slate-500">Étape <?= (int)$item['ordre'] ?></small><b class="mt-1 block"><?= $h($stageLabels[$item['etape']]) ?></b><span class="mt-2 inline-block rounded-full px-2 py-1 text-xs font-bold <?= $item['statut'] === 'validee' ? 'bg-emerald-50 text-emerald-700' : ($item['statut'] === 'en_cours' ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-500') ?>"><?= $h(['validee' => 'Validée', 'en_cours' => 'En cours', 'en_attente' => 'En attente'][$item['statut']]) ?></span></a><?php endforeach; ?></nav>
    <?php if ($error): ?><div class="mt-5 rounded-2xl bg-rose-50 p-4 font-bold text-rose-800"><?= $h($error) ?></div><?php endif; ?><?php if ($success): ?><div class="mt-5 rounded-2xl bg-emerald-50 p-4 font-bold text-emerald-800"><?= $h($success) ?></div><?php endif; ?>
    <?php if ($selectedStage === 'direction'): $internalStages = $contractManager->workflow($id);
        $technicalData = [];
        $financeData = [];
        foreach ($internalStages as $internalStage) {
            if ($internalStage['etape'] === 'technique') $technicalData = (array)$internalStage['donnees'];
            if ($internalStage['etape'] === 'comptabilite') $financeData = (array)$internalStage['donnees'];
        }
        $splitPlanning = static fn($value) => array_values(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : preg_split('/\R/', (string)$value)), static fn($value) => $value !== ''));
        $planningLabels = $splitPlanning($technicalData['planning_description'] ?? '');
        $planningStarts = $splitPlanning($technicalData['planning_start'] ?? '');
        $planningEnds = $splitPlanning($technicalData['planning_end'] ?? '');
        $contractStart = new DateTimeImmutable($c['date_debut'] ?: date('Y-m-d'));
        $contractEnd = new DateTimeImmutable($c['date_fin'] ?: $contractStart->format('Y-m-d'));
        $totalDays = max(1, (int)$contractStart->diff($contractEnd)->format('%a') + 1);
        $ganttTasks = [];
        $planningCount = max(1, count($planningLabels));
        foreach ($planningLabels as $taskIndex => $planningLabel) {
            $fallbackStart = $contractStart->modify('+' . (int)floor($taskIndex * $totalDays / $planningCount) . ' days');
            $fallbackEnd = $contractStart->modify('+' . max((int)floor(($taskIndex + 1) * $totalDays / $planningCount) - 1, (int)floor($taskIndex * $totalDays / $planningCount)) . ' days');
            $taskStart = !empty($planningStarts[$taskIndex]) ? DateTimeImmutable::createFromFormat('!Y-m-d', $planningStarts[$taskIndex]) : $fallbackStart;
            $taskEnd = !empty($planningEnds[$taskIndex]) ? DateTimeImmutable::createFromFormat('!Y-m-d', $planningEnds[$taskIndex]) : $fallbackEnd;
            if (!$taskStart) $taskStart = $fallbackStart;
            if (!$taskEnd || $taskEnd < $taskStart) $taskEnd = $fallbackEnd;
            $startOffset = max(0, min($totalDays - 1, (int)$contractStart->diff($taskStart)->format('%r%a')));
            $duration = max(1, (int)$taskStart->diff($taskEnd)->format('%a') + 1);
            $ganttTasks[] = ['label' => $planningLabel, 'start' => $taskStart, 'end' => $taskEnd, 'left' => $startOffset / $totalDays * 100, 'width' => min(100 - $startOffset / $totalDays * 100, $duration / $totalDays * 100)];
        } ?>
        <section class="mt-6 rounded-3xl border border-amber-200 bg-white p-6 shadow-xl">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p class="text-xs font-black uppercase tracking-[.22em] text-amber-700">Planning contractuel</p>
                    <h2 class="mt-1 text-2xl font-black">Diagramme de Gantt</h2>
                </div>
                <div class="rounded-2xl bg-black px-4 py-3 text-sm font-bold text-white"><?= date('d/m/Y', $contractStart->getTimestamp()) ?> <span class="px-2 text-amber-300">→</span> <?= date('d/m/Y', $contractEnd->getTimestamp()) ?></div>
            </div><?php if ($ganttTasks): ?><div class="mt-6 overflow-x-auto">
                    <div class="min-w-[780px]">
                        <div class="grid grid-cols-[280px_1fr] border-b border-slate-200 pb-2 text-xs font-black uppercase text-slate-500">
                            <div>Phase / tâche</div>
                            <div class="flex justify-between"><span>Début</span><span>Milieu du délai</span><span>Fin prévue</span></div>
                        </div><?php foreach ($ganttTasks as $ganttTask): ?><div class="grid grid-cols-[280px_1fr] items-center gap-4 border-b border-slate-100 py-3">
                                <div><b class="block text-sm"><?= $h($ganttTask['label']) ?></b><small class="text-slate-500"><?= date('d/m/Y', $ganttTask['start']->getTimestamp()) ?> — <?= date('d/m/Y', $ganttTask['end']->getTimestamp()) ?></small></div>
                                <div class="relative h-10 overflow-hidden rounded-xl border border-slate-200 bg-[repeating-linear-gradient(90deg,#f8fafc_0,#f8fafc_calc(10%_-_1px),#e2e8f0_calc(10%_-_1px),#e2e8f0_10%)]">
                                    <div class="absolute top-1/2 h-6 -translate-y-1/2 rounded-lg bg-gradient-to-r from-black to-amber-500 shadow-md" style="left:<?= number_format($ganttTask['left'], 3, '.', '') ?>%;width:<?= number_format(max(1, $ganttTask['width']), 3, '.', '') ?>%"></div>
                                </div>
                            </div><?php endforeach; ?>
                    </div>
                </div><?php else: ?><div class="mt-5 rounded-2xl bg-amber-50 p-5 font-bold text-amber-900">Le département Technique doit ajouter les phases du planning pour générer le diagramme de Gantt.</div><?php endif; ?>
        </section>
        <section class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-xl">
            <div class="bg-gradient-to-r from-black to-slate-800 p-7 text-white">
                <p class="text-xs font-black uppercase tracking-[.25em] text-amber-300">Étape 7 · Décision finale</p>
                <h2 class="mt-2 text-3xl font-black">Contrat consolidé pour la Direction générale</h2>
                <p class="mt-2 max-w-3xl text-slate-300">Cette vue réunit les travaux, les conditions financières et toutes les validations internes avant la signature du DG et du prestataire.</p>
            </div>
            <div class="grid gap-4 p-6 md:grid-cols-3">
                <div class="rounded-2xl bg-amber-50 p-5"><small class="font-black uppercase text-amber-800">Contrat</small><b class="mt-2 block text-xl"><?= $h($c['reference']) ?></b><span class="text-sm text-slate-600"><?= $h($c['objet']) ?></span></div>
                <div class="rounded-2xl bg-slate-50 p-5"><small class="font-black uppercase text-slate-500">Chantier</small><b class="mt-2 block"><?= $h($c['chantier_code'] . ' · ' . $c['chantier_titre']) ?></b><span class="text-sm text-slate-600"><?= $h($c['fournisseur_nom']) ?></span></div>
                <div class="rounded-2xl bg-emerald-50 p-5"><small class="font-black uppercase text-emerald-700">Montant des déboursés</small><b class="mt-2 block text-xl"><?= number_format((float)$c['montant_ht'], 0, ',', ' ') ?> FCFA</b><span class="text-sm text-emerald-800">Fin prévue : <?= $c['date_fin'] ? date('d/m/Y', strtotime($c['date_fin'])) : '—' ?></span></div>
            </div>
            <div class="px-6 pb-6">
                <h3 class="text-xl font-black">Validations internes</h3>
                <div class="mt-4 grid gap-3 md:grid-cols-3 xl:grid-cols-6"><?php foreach ($internalStages as $approval): ?><div class="rounded-2xl border p-4 <?= $approval['statut'] === 'validee' ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' ?>"><b class="block"><?= $h($stageLabels[$approval['etape']]) ?></b><span class="mt-2 block text-xs font-bold"><?= $approval['statut'] === 'validee' ? 'Validée' : 'En attente' ?></span><small class="mt-2 block text-slate-600"><?= $h($approval['signataire_nom'] ?: 'Signataire non désigné') ?></small><?php if ($approval['statut'] === 'validee' && !empty($approval['signe_at'])): ?><small class="mt-1 block text-slate-500"><?= date('d/m/Y H:i', strtotime($approval['signe_at'])) ?></small><?php endif; ?><?php if (!empty($approval['signature'])): ?><img src="<?= $h($approval['signature']) ?>" alt="Signature de <?= $h($approval['signataire_nom']) ?>" class="mt-3 h-12 w-full rounded-lg border border-emerald-200 bg-white object-contain p-1"><?php elseif ($approval['statut'] === 'validee'): ?><small class="mt-3 block text-amber-800">Signature historique non disponible</small><?php endif; ?></div><?php endforeach; ?></div>
                <?php if ($isDg && !$dgSigned && !$dgEditMode && $c['statut'] !== 'resilie'): ?><form method="post" class="mt-6 rounded-2xl border border-indigo-200 bg-indigo-50 p-5"><input type="hidden" name="_csrf" value="<?= $h($_SESSION['contract_workflow_csrf']) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="etape" value="direction"><input type="hidden" name="action" value="reopen_by_dg"><b class="block text-indigo-950">Réouvrir ce contrat en édition DG</b><p class="mt-1 text-sm text-indigo-800">Vous pourrez corriger toutes les étapes validées avant d’apposer votre signature. Cette action reste impossible après votre signature.</p><button class="mt-3 rounded-xl bg-indigo-700 px-5 py-3 font-black text-white" onclick="return confirm('Réouvrir le contrat afin de modifier les étapes internes ?')">Activer l’édition DG</button></form><?php elseif ($isDg && $dgEditMode): ?><div class="mt-6 rounded-2xl border border-indigo-200 bg-indigo-50 p-5 font-bold text-indigo-900">Mode édition DG actif : utilisez les onglets du circuit pour modifier chaque étape avant signature.</div><?php endif; ?>
                <?php if ($planningLabels && $contractDebourseGroups): ?><?php
                    $dgFinanceFilled = false; foreach (['taux_acompte', 'pourcentage_demarrage', 'retenue_garantie', 'conditions_financieres', 'penalites_retard', 'planning_poids', 'planning_echeances'] as $dgFinanceField) if (!empty($financeData[$dgFinanceField])) { $dgFinanceFilled = true; break; }
                    $dgFinanceWeights = (array)($financeData['planning_poids'] ?? []);
                    $dgFinanceDeadlines = (array)($financeData['planning_echeances'] ?? []);
                    $dgAdvanceRate = $dgFinanceFilled ? max(0, min(100, (float)($c['taux_acompte'] ?? 0))) : 20.0;
                    $dgRetentionRate = $dgFinanceFilled ? max(0, min(100, (float)($c['retenue_garantie_taux'] ?? 0))) : 5.0;
                    $dgDeadlineLabel = static function (array $deadline): string {
                        $type = (string)($deadline['type'] ?? 'immediate');
                        if ($type === 'after_delay') { $delay = max(1, (int)($deadline['delai'] ?? 1)); $unit = (string)($deadline['unite'] ?? 'jour') === 'semaine' ? 'semaine' : 'jour'; return 'Paiement ' . $delay . ' ' . ($delay > 1 ? $unit . 's' : $unit) . ' après l’exécution.'; }
                        if ($type === 'after_condition') return 'Sous condition : ' . (trim((string)($deadline['condition'] ?? '')) ?: 'à préciser') . '.';
                        return 'Paiement à l’exécution.';
                    };
                ?><section class="mt-7 overflow-hidden rounded-2xl border border-indigo-200 bg-indigo-50">
                    <div class="border-b border-indigo-200 bg-white px-5 py-4"><p class="text-xs font-black uppercase tracking-wide text-indigo-700">Contrôle Direction générale</p><h3 class="mt-1 text-xl font-black text-slate-950">Échéancier financier et conditions de décaissement</h3><p class="mt-1 text-sm text-slate-600">Même répartition que la partie Comptabilité, avec les échéances de chaque fraction et de la restitution finale.</p><?php if (!$dgFinanceFilled): ?><p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm font-bold text-amber-900">Prévision par défaut : la partie Comptabilité n’est pas encore renseignée. Acompte 20 %, démarrage 0 % et retenue de garantie 5 % sont affichés à titre indicatif.</p><?php endif; ?></div>
                    <div class="space-y-4 p-5"><?php foreach ($contractDebourseGroups as $dgGroupId => $dgGroup): ?><?php $dgGroupTotal = array_sum(array_map(static fn(array $line): float => (float)$line['montant'], $dgGroup['lines'] ?? [])); ?><article class="overflow-hidden rounded-xl border border-indigo-200 bg-white"><h4 class="bg-indigo-100 px-4 py-3 font-black text-indigo-950"><?= $h($dgGroup['label']) ?> <span class="float-right"><?= number_format($dgGroupTotal, 0, ',', ' ') ?> FCFA</span></h4><div class="divide-y divide-slate-100"><?php foreach ($planningLabels as $dgPlanningIndex => $dgPlanningLabel): ?><?php $dgWeightKey = 'planning_' . $dgGroupId . '_' . $dgPlanningIndex; $dgWeight = (float)($dgFinanceWeights[$dgWeightKey] ?? (100 / count($planningLabels))); $dgWeight = max(0, min(100, $dgWeight)); $dgGross = $dgGroupTotal * $dgWeight / 100; $dgRecovery = $dgGross * $dgAdvanceRate / 100; $dgRetention = $dgGross * $dgRetentionRate / 100; $dgNet = max(0, $dgGross - $dgRecovery - $dgRetention); $dgDeadline = $dgDeadlineLabel((array)($dgFinanceDeadlines[$dgWeightKey] ?? [])); ?><div class="grid gap-2 px-4 py-3 md:grid-cols-[minmax(0,1fr)_110px_150px]"><div><b class="block text-sm text-slate-950"><?= $h($dgPlanningLabel) ?></b><span class="mt-1 block text-xs font-semibold text-sky-800">Échéance : <?= $h($dgDeadline) ?></span></div><div class="text-sm font-black text-indigo-800"><?= number_format($dgWeight, 2, ',', ' ') ?> %</div><div class="text-right text-sm"><b><?= number_format($dgNet, 0, ',', ' ') ?> F</b><small class="block text-slate-500">Brut <?= number_format($dgGross, 0, ',', ' ') ?> F</small></div></div><?php endforeach; ?></div></article><?php endforeach; ?><?php if ($dgRetentionRate > 0): ?><?php $dgRetentionDeadline = $dgDeadlineLabel((array)($dgFinanceDeadlines['restitution_retenue'] ?? [])); ?><div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3"><b class="text-emerald-950">Dernière fraction — restitution de la retenue de garantie (<?= number_format($dgRetentionRate, 2, ',', ' ') ?> %)</b><span class="mt-1 block text-sm font-semibold text-emerald-800">Échéance : <?= $h($dgRetentionDeadline) ?></span></div><?php endif; ?></div>
                </section><?php endif; ?>
                <h3 class="mt-7 text-xl font-black">Prestations contractualisées</h3>
                <div class="mt-3 overflow-x-auto rounded-2xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-black text-white">
                            <tr>
                                <th class="p-3 text-left">Travaux</th>
                                <th class="p-3 text-center">Quantité</th>
                                <th class="p-3 text-left">Lignes de déboursé liées</th>
                                <th class="p-3 text-right">Déboursé contractuel</th>
                            </tr>
                        </thead>
                        <tbody><?php foreach ($c['lignes'] as $line): ?><tr class="border-b">
                                    <td class="p-3 font-bold"><?= $h($line['designation']) ?></td>
                                    <td class="p-3 text-center"><?= $h($line['quantite'] . ' ' . ($line['unite'] ?: '')) ?></td>
                                    <td class="p-3"><?php $debourseLines = (array)($line['debourse_lignes'] ?? []); $lineDebourseTotal = array_sum(array_map(static fn($item): float => (float)($item['montant'] ?? 0), $debourseLines)); if (!$debourseLines): ?><span class="text-slate-400">Aucune ligne de déboursé</span><?php else: ?><div class="space-y-1"><?php foreach ($debourseLines as $debourseLine): ?><div class="rounded-lg bg-indigo-50 px-2 py-1 text-xs"><b><?= $h($debourseLine['designation'] ?? 'Poste') ?></b><?php if (!empty($debourseLine['categorie'])): ?> · <?= $h($debourseLine['categorie']) ?><?php endif; ?><span class="float-right font-black text-indigo-800"><?= number_format((float)($debourseLine['montant'] ?? 0), 0, ',', ' ') ?> F</span></div><?php endforeach; ?></div><div class="mt-2 rounded-lg bg-slate-950 px-2 py-1 text-xs font-black text-white">Sous-total déboursé : <?= number_format($lineDebourseTotal, 0, ',', ' ') ?> F <span class="float-right">Poids contrat : <?= $contractDebourseTotal > 0 ? number_format($lineDebourseTotal * 100 / $contractDebourseTotal, 2, ',', ' ') : '0,00' ?> %</span></div><?php endif; ?></td>
                                    <td class="p-3 text-right font-black"><?= number_format((float)$line['montant_contractuel'], 0, ',', ' ') ?> FCFA</td>
                                </tr><?php endforeach; ?></tbody>
                    </table>
                </div>
                <div class="mt-7 flex flex-wrap gap-3"><a target="_blank" href="exporter.php?id=<?= $id ?>" class="rounded-xl bg-slate-950 px-5 py-3 font-black text-white"><i class="fas fa-file-pdf mr-2"></i>Exporter le dossier contractuel complet</a><details class="rounded-xl border border-slate-300 bg-white px-4 py-3"><summary class="cursor-pointer font-black text-slate-900">Exports par partie</summary><div class="mt-3 flex max-w-xl flex-wrap gap-2 text-sm"><?php foreach (['juridique' => 'Juridique', 'rh' => 'RH', 'technique' => 'Technique', 'logistique' => 'Logistique', 'qhse' => 'QHSE', 'comptabilite' => 'Comptabilité', 'resume' => 'Résumé'] as $exportPart => $exportLabel): ?><a target="_blank" class="rounded-lg border border-slate-300 px-3 py-2 font-bold hover:bg-slate-50" href="exporter.php?id=<?= $id ?>&amp;section=<?= $exportPart ?>"><?= $exportLabel ?></a><?php endforeach; ?></div></details><?php if (!$isDrh && $c['statut'] !== 'brouillon'): ?><a href="signer.php?id=<?= $id ?>" class="rounded-xl bg-amber-500 px-5 py-3 font-black text-black"><i class="fas fa-signature mr-2"></i>Ouvrir les signatures DG / prestataire</a><?php endif; ?></div>
            </div>
        </section>
</main><?php require __DIR__ . '/../decaissement/includes/footer.php';
        exit; ?>
<?php endif; ?>
<section class="mt-6 rounded-3xl border bg-white p-6">
    <div class="flex flex-wrap justify-between gap-3">
        <div>
            <p class="text-xs font-black uppercase text-amber-700">Étape <?= (int)$stage['ordre'] ?></p>
            <h2 class="text-2xl font-black"><?= $h($stageLabels[$selectedStage]) ?></h2>
        </div><?php if ($stage['statut'] === 'validee'): ?><?php $signedAt = trim((string)($stage['signe_at'] ?? '')); ?><div class="rounded-2xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><b>Validée par <?= $h($stage['signataire_nom'] ?: 'Signataire historique non renseigné') ?></b><br><?= $h($stage['signataire_fonction'] ?: 'Fonction non renseignée') ?> · <?= $signedAt !== '' && !str_starts_with($signedAt, '0000-00-00') ? date('d/m/Y H:i', strtotime($signedAt)) : 'Date de signature historique indisponible' ?><?php if (!empty($stage['signature'])): ?><img src="<?= $h($stage['signature']) ?>" alt="Signature de <?= $h($stage['signataire_nom']) ?>" class="mt-2 h-12 max-w-full rounded border border-emerald-200 bg-white object-contain p-1"><?php endif; ?></div><?php endif; ?>
    </div>
    <?php if ($selectedStage !== 'direction'): ?><div class="mt-4 flex flex-wrap items-center gap-3"><a target="_blank" href="exporter.php?id=<?= $id ?>&amp;section=<?= $h($selectedStage) ?>" class="rounded-xl border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-black text-indigo-800 hover:bg-indigo-100"><i class="fas fa-file-pdf mr-2"></i>Exporter cette étape<?php if ($stage['statut'] !== 'validee'): ?> <span class="font-medium">· brouillon</span><?php endif; ?></a><span class="text-xs text-slate-500">Export disponible même avant la validation finale.</span></div><?php endif; ?>
    <?php if ($previousExportStages): ?><section class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4"><div class="flex flex-wrap items-center justify-between gap-2"><div><h3 class="font-black text-slate-950">Documents des étapes précédentes</h3><p class="mt-1 text-sm text-slate-600">Les exports déjà préparés restent disponibles pour le signataire de cette étape.</p></div><i class="fas fa-folder-open text-2xl text-indigo-700"></i></div><div class="mt-3 flex flex-wrap gap-2"><?php foreach ($previousExportStages as $previousExport): ?><a target="_blank" href="exporter.php?id=<?= $id ?>&amp;section=<?= $h($previousExport['etape']) ?>" class="rounded-xl border border-indigo-200 bg-white px-3 py-2 text-sm font-bold text-indigo-800 hover:bg-indigo-50"><i class="fas fa-file-pdf mr-1"></i><?= $h($previousExport['label']) ?><?php if ($previousExport['statut'] !== 'validee'): ?> <small class="font-medium text-slate-500">· brouillon</small><?php endif; ?></a><?php endforeach; ?></div></section><?php endif; ?>
    <?php if ($selectedStage === 'juridique'): ?><aside class="mt-5 rounded-2xl border border-indigo-200 bg-indigo-50 p-5"><div class="flex flex-wrap items-start gap-4"><div class="flex h-14 w-14 items-center justify-center rounded-full bg-indigo-700 text-xl font-black text-white"><?= $h(strtoupper(substr($supplierIdentity ?: 'P', 0, 1))) ?></div><div class="min-w-0 flex-1"><p class="text-xs font-black uppercase tracking-wide text-indigo-700">Identité du prestataire sélectionné</p><h3 class="mt-1 text-lg font-black text-slate-950"><?= $h($supplierIdentity ?: 'Prestataire non renseigné') ?></h3><p class="mt-1 text-sm text-slate-700"><?= $h($c['telephone'] ?? 'Téléphone non renseigné') ?> · <?= $h($c['email'] ?? 'E-mail non renseigné') ?></p><p class="mt-1 text-sm text-slate-600"><?= $h($c['adresse'] ?? 'Adresse / lieu non renseigné') ?></p><?php if (!empty($c['adresse'])): ?><a class="mt-2 inline-block text-sm font-bold text-indigo-700 underline" target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&amp;query=<?= rawurlencode((string)$c['adresse']) ?>">Voir le lieu sur la carte</a><?php endif; ?></div></div><p class="mt-3 text-xs text-slate-500">Les informations affichées proviennent de la fiche fournisseur et restent complétables dans les soussignés.</p></aside><?php endif; ?>
    <?php if ($selectedStage === 'rh' && ($isAdmin || $isDrh || $isDg) && $c['statut'] !== 'resilie' && !$c['paiements']): ?><form method="post" class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-5"><input type="hidden" name="_csrf" value="<?= $h($_SESSION['contract_workflow_csrf']) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="etape" value="rh"><input type="hidden" name="action" value="restart_internal_workflow"><b class="block text-rose-950">Remettre le circuit interne en édition</b><p class="mt-1 text-sm text-rose-900">À utiliser pour un contrat historique : les informations déjà saisies sont conservées, mais les six signatures internes et les signatures finales devront être refaites.</p><button class="mt-3 rounded-xl bg-rose-700 px-5 py-3 font-black text-white" onclick="return confirm('Reprendre le circuit depuis le RH ? Les signatures internes et finales actuelles seront annulées.')">Reprendre depuis le RH</button></form><?php endif; ?>
    <?php if ($selectedStage === 'qhse'): ?><section class="mt-6 rounded-3xl border border-emerald-200 bg-emerald-50 p-5"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-wide text-emerald-700">Alternative aux listes manuelles</p><h3 class="mt-1 text-lg font-black text-slate-950">Registre QHSE importable</h3><p class="mt-1 max-w-2xl text-sm text-slate-700">Téléchargez le modèle, complétez les EPI, risques, mesures de prévention et habilitations, puis importez-le. Ce fichier Excel remplace les champs de listes de cette étape.</p></div><a href="qhse_template.php?id=<?= $id ?>" class="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-black text-white hover:bg-emerald-800"><i class="fas fa-file-excel mr-2"></i>Télécharger le modèle Excel</a></div></section><?php endif; ?>
    <form method="post" enctype="multipart/form-data" class="mt-6"><input type="hidden" name="_csrf" value="<?= $h($_SESSION['contract_workflow_csrf']) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="etape" value="<?= $h($selectedStage) ?>"><input type="hidden" name="action" value="save"><?php if ($selectedStage === 'qhse'): ?><section class="mb-5 rounded-2xl border-2 border-dashed border-emerald-300 bg-white p-5 text-center"><label class="block cursor-pointer"><i class="fas fa-cloud-arrow-up text-3xl text-emerald-700"></i><b class="mt-2 block text-slate-950">Importer le registre QHSE complété</b><span class="mt-1 block text-sm text-slate-600">Glissez-déposez ou cliquez ici · Excel .xlsx ou .xls · 15 Mo maximum</span><input type="file" name="qhse_file" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" <?= $editable ? '' : 'disabled' ?> class="sr-only"><span class="mt-3 block text-xs font-bold text-emerald-800" data-qhse-preview><?= !empty($data['qhse_file']) ? 'Un registre est déjà enregistré ; importez un nouveau fichier pour le remplacer.' : '' ?></span></label><?php if (!empty($data['qhse_file'])): ?><a target="_blank" href="../<?= $h($data['qhse_file']) ?>" class="mt-3 inline-block text-sm font-bold text-emerald-800 underline"><i class="fas fa-file-excel mr-1"></i>Consulter le registre QHSE enregistré</a><?php endif; ?></section><?php endif; ?>
        <div class="grid gap-4 md:grid-cols-2"><?php foreach ($fields[$selectedStage] as [$name, $label, $type]): ?><label class="font-bold <?= $type === 'textarea' ? 'md:col-span-2' : '' ?>"><?= $h($label) ?><?php if ($type === 'textarea'): ?><textarea name="data[<?= $h($name) ?>]" rows="4" <?= $editable ? '' : 'disabled' ?> class="mt-2 w-full rounded-2xl border p-3"><?= $h($data[$name] ?? '') ?></textarea><?php elseif ($type === 'select'): ?><select name="data[<?= $h($name) ?>]" <?= $editable ? '' : 'disabled' ?> class="mt-2 w-full rounded-2xl border p-3"><?php foreach (['complet' => 'Dossier complet', 'a_completer' => 'Dossier à compléter', 'bloquant' => 'Pièces bloquantes'] as $v => $l): ?><option value="<?= $v ?>" <?= ($data[$name] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><?php else: ?><input type="<?= $type ?>" min="0" <?= $selectedStage === 'comptabilite' && in_array($name, ['taux_acompte', 'pourcentage_demarrage', 'retenue_garantie'], true) ? 'max="100"' : '' ?> step="<?= $type === 'number' ? '0.01' : '1' ?>" name="data[<?= $h($name) ?>]" value="<?= $h($data[$name] ?? '') ?>" <?= $editable ? '' : 'disabled' ?> class="mt-2 w-full rounded-2xl border p-3"><?php endif; ?></label><?php endforeach; ?><?php if ($selectedStage === 'technique'): ?><label class="md:col-span-2 font-bold">Planning transmis par le prestataire<input type="file" name="planning_file" accept="application/pdf,.xlsx,image/jpeg,image/png" <?= $editable ? '' : 'disabled' ?> class="mt-2 w-full rounded-2xl border p-3"><?php if (!empty($data['planning_file'])): ?><a target="_blank" href="../<?= $h($data['planning_file']) ?>" class="mt-2 block text-indigo-700 underline">Consulter le planning enregistré</a><?php endif; ?></label><?php endif; ?></div><label class="mt-5 block font-black">Signataire de cette étape<select name="signataire_personnel_id" required <?= $editable ? '' : 'disabled' ?> class="mt-2 w-full rounded-2xl border border-amber-300 p-3">
                <option value="">Sélectionner dans le personnel</option><?php foreach ($personnel as $person): ?><option value="<?= (int)$person['id'] ?>" <?= ((int)($stage['signataire_personnel_id'] ?? 0) === (int)$person['id']) ? 'selected' : '' ?>><?= $h($person['nom'] . ' · ' . $person['fonction']) ?></option><?php endforeach; ?>
            </select></label><?php if ($selectedStage === 'comptabilite'): ?><section class="mt-7 overflow-hidden rounded-3xl border border-indigo-200 bg-indigo-50"><div class="border-b border-indigo-200 bg-white px-5 py-4"><h3 class="font-black text-slate-950">Échéancier financier prévisionnel</h3><p class="mt-1 text-sm text-slate-600">Calculé en direct par ligne de déboursé rattachée aux postes du devis. L’acompte est récupéré progressivement et la retenue est reversée intégralement à la dernière ligne.</p></div><div class="overflow-x-auto"><table class="w-full min-w-[850px] text-sm"><thead class="bg-slate-950 text-white"><tr><th class="p-3 text-left">Poste / paiement</th><th class="p-3 text-right">Brut</th><th class="p-3 text-right">Récup. acompte</th><th class="p-3 text-right">Retenue garantie</th><th class="p-3 text-right">Restitution</th><th class="p-3 text-right">Net à payer</th></tr></thead><tbody id="contractFinanceSchedule"></tbody></table></div></section><script type="application/json" id="contractFinanceLines"><?=json_encode($contractFinanceLines,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP)?></script><script>(()=>{const target=document.getElementById('contractFinanceSchedule'),source=document.getElementById('contractFinanceLines');if(!target||!source)return;let lines=[];try{lines=JSON.parse(source.textContent||'[]')}catch(_){return}const money=value=>Math.round(value||0).toLocaleString('fr-FR')+' FCFA',input=name=>document.querySelector(`[name="data[${name}]"]`),rate=name=>Math.max(0,Math.min(100,Number(input(name)?.value||0)));const render=()=>{const advance=rate('taux_acompte')/100,guarantee=rate('retenue_garantie')/100;let totalGross=0,totalAdvance=0,totalGuarantee=0,totalNet=0;const rows=[];if(advance>0)rows.push(`<tr class="border-b bg-amber-50"><td class="p-3 font-bold">Acompte initial (${(advance*100).toFixed(2)} %)</td><td class="p-3 text-right">${money(lines.reduce((sum,line)=>sum+Number(line.montant||0),0)*advance)}</td><td class="p-3 text-right">—</td><td class="p-3 text-right">—</td><td class="p-3 text-right">—</td><td class="p-3 text-right font-black">${money(lines.reduce((sum,line)=>sum+Number(line.montant||0),0)*advance)}</td></tr>`);lines.forEach(line=>{const gross=Number(line.montant||0),recovery=gross*advance,retention=gross*guarantee,net=Math.max(0,gross-recovery-retention);totalGross+=gross;totalAdvance+=recovery;totalGuarantee+=retention;totalNet+=net;rows.push(`<tr class="border-b"><td class="p-3 font-bold">${String(line.designation||'Poste').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}${line.categorie?`<small class="block font-medium text-slate-500">${String(line.categorie).replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]))}</small>`:''}</td><td class="p-3 text-right">${money(gross)}</td><td class="p-3 text-right text-rose-700">− ${money(recovery)}</td><td class="p-3 text-right text-rose-700">− ${money(retention)}</td><td class="p-3 text-right">—</td><td class="p-3 text-right font-black text-emerald-700">${money(net)}</td></tr>`)});if(totalGuarantee>0)rows.push(`<tr class="bg-emerald-50"><td class="p-3 font-black">Dernière ligne · restitution intégrale de retenue</td><td class="p-3 text-right">—</td><td class="p-3 text-right">—</td><td class="p-3 text-right">—</td><td class="p-3 text-right font-black text-emerald-700">+ ${money(totalGuarantee)}</td><td class="p-3 text-right font-black text-emerald-700">${money(totalGuarantee)}</td></tr>`);rows.push(`<tr class="bg-slate-950 font-black text-white"><td class="p-3">TOTAL DES PAIEMENTS</td><td class="p-3 text-right">${money(totalGross)}</td><td class="p-3 text-right">${money(totalAdvance)}</td><td class="p-3 text-right">${money(totalGuarantee)}</td><td class="p-3 text-right">${money(totalGuarantee)}</td><td class="p-3 text-right">${money(totalNet+totalGuarantee+(lines.reduce((sum,line)=>sum+Number(line.montant||0),0)*advance))}</td></tr>`);target.innerHTML=rows.join('')};['taux_acompte','retenue_garantie','pourcentage_demarrage'].forEach(name=>input(name)?.addEventListener('input',render));render()})();</script><?php endif; ?><?php if ($editable): ?><button class="mt-5 rounded-xl bg-slate-950 px-6 py-3 font-black text-white">Enregistrer cette étape</button><?php endif; ?>
    </form><?php if ($selectedStage === 'comptabilite'): ?><script>(()=>{const target=document.getElementById('contractFinanceSchedule'),source=document.getElementById('contractFinanceLines');if(!target||!source)return;let lines=[];try{lines=JSON.parse(source.textContent||'[]')}catch(_){return}const saved=<?= json_encode(is_array($data['debourse_avancement'] ?? null) ? $data['debourse_avancement'] : [], JSON_HEX_TAG|JSON_HEX_AMP) ?>,esc=v=>String(v??'').replace(/[&<>]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])),money=v=>Math.round(v||0).toLocaleString('fr-FR')+' FCFA',field=n=>document.querySelector(`[name="data[${n}]"]`),rate=n=>Math.max(0,Math.min(100,Number(field(n)?.value||0))),progress=line=>Math.max(0,Math.min(100,Number(saved[line.id]??0)));const render=()=>{const advance=rate('taux_acompte')/100,guarantee=rate('retenue_garantie')/100,groups=new Map;lines.forEach(line=>{if(!groups.has(line.group))groups.set(line.group,[]);groups.get(line.group).push(line)});let grossTotal=0,recoveryTotal=0,retentionTotal=0,netTotal=0,plannedTotal=0;const rows=[];if(advance>0){const total=lines.reduce((sum,line)=>sum+Number(line.montant||0),0),amount=total*advance;rows.push(`<tr class="border-b bg-amber-50"><td class="p-3 font-black">Acompte contractuel (${(advance*100).toFixed(2)} %)</td><td class="p-3 text-right">—</td><td class="p-3 text-right">—</td><td class="p-3 text-right">${money(amount)}</td><td class="p-3 text-right">—</td><td class="p-3 text-right">—</td><td class="p-3 text-right font-black">${money(amount)}</td></tr>`)}for(const [group,items] of groups){const groupGross=items.reduce((sum,line)=>sum+Number(line.montant||0)*progress(line)/100,0),groupTotal=Number(items[0].group_total||0);plannedTotal+=groupTotal;rows.push(`<tr class="bg-indigo-100 text-indigo-950"><td class="p-3 font-black" colspan="3">${esc(group)} <small class="ml-2 font-medium">Poids global : ${Number(items[0].group_weight||0).toLocaleString('fr-FR')} %</small></td><td class="p-3 text-right font-black">${money(groupTotal)}</td><td colspan="3" class="p-3 text-right font-black">Sous-total estimé : ${money(groupGross)}</td></tr>`);items.forEach(line=>{const p=progress(line),gross=Number(line.montant||0)*p/100,recovery=gross*advance,retention=gross*guarantee,net=Math.max(0,gross-recovery-retention);grossTotal+=gross;recoveryTotal+=recovery;retentionTotal+=retention;netTotal+=net;rows.push(`<tr class="border-b"><td class="p-3 font-bold">${esc(line.designation)}${line.categorie?`<small class="block font-medium text-slate-500">${esc(line.categorie)}</small>`:''}</td><td class="p-3 text-right">${Number(line.line_weight||0).toLocaleString('fr-FR')} %</td><td class="p-3"><input type="number" min="0" max="100" step="0.01" name="data[debourse_avancement][${esc(line.id)}]" value="${p}" class="w-24 rounded-lg border px-2 py-1 text-right"> %</td><td class="p-3 text-right">${money(line.montant)}</td><td class="p-3 text-right">− ${money(recovery)}</td><td class="p-3 text-right">− ${money(retention)}</td><td class="p-3 text-right font-black text-emerald-700">${money(net)}</td></tr>`)});rows.push(`<tr class="bg-slate-50"><td class="p-3 font-black" colspan="3">Sous-total ${esc(group)}</td><td class="p-3 text-right font-black">${money(groupTotal)}</td><td class="p-3 text-right" colspan="2">Avancement estimé inclus</td><td class="p-3 text-right font-black">${money(items.reduce((sum,line)=>sum+Number(line.montant||0)*progress(line)/100*(1-advance-guarantee),0))}</td></tr>`)}if(retentionTotal>0)rows.push(`<tr class="bg-emerald-50"><td class="p-3 font-black" colspan="6">Dernière ligne · restitution de la retenue de garantie</td><td class="p-3 text-right font-black text-emerald-700">+ ${money(retentionTotal)}</td></tr>`);rows.push(`<tr class="bg-slate-950 font-black text-white"><td class="p-3" colspan="3">TOTAL GÉNÉRAL · Déboursés : 100 %</td><td class="p-3 text-right">${money(plannedTotal)}</td><td class="p-3 text-right">${money(recoveryTotal)}</td><td class="p-3 text-right">${money(retentionTotal)}</td><td class="p-3 text-right">${money(netTotal+retentionTotal+(plannedTotal*advance))}</td></tr>`);target.innerHTML=rows.join('');target.querySelectorAll('input[name^="data[debourse_avancement]"]').forEach(input=>input.addEventListener('input',()=>{saved[input.name.match(/\[([^\]]+)\]$/)?.[1]||'']=input.value;render()}))};['taux_acompte','retenue_garantie'].forEach(n=>field(n)?.addEventListener('input',render));render()})();</script><?php endif; ?><?php if ($selectedStage === 'technique' && $editable): ?><script>(()=>{const input=document.querySelector('input[name="planning_file"]');if(!input||document.getElementById('planningDropZone'))return;const label=input.closest('label');if(!label)return;input.classList.add('sr-only');const zone=document.createElement('section');zone.id='planningDropZone';zone.className='mt-3 cursor-pointer rounded-2xl border-2 border-dashed border-indigo-300 bg-indigo-50 p-6 text-center transition hover:border-indigo-600 hover:bg-indigo-100';zone.innerHTML='<i class="fas fa-cloud-arrow-up text-3xl text-indigo-700"></i><h3 class="mt-2 font-black text-slate-950">Glissez-déposez le planning ici</h3><p class="mt-1 text-sm text-slate-600">PDF, XLSX, JPG ou PNG · ou cliquez pour choisir un fichier</p><div class="mt-4" data-preview></div>';label.insertAdjacentElement('afterend',zone);const preview=zone.querySelector('[data-preview]');let url='';const show=file=>{if(url)URL.revokeObjectURL(url);url=URL.createObjectURL(file);const title=`<p class="mb-3 rounded-xl bg-white p-3 text-sm font-bold">${file.name} · ${Math.round(file.size/1024).toLocaleString('fr-FR')} Ko</p>`;preview.innerHTML=file.type==='application/pdf'?title+`<iframe class="h-80 w-full rounded-xl border bg-white" src="${url}" title="Aperçu du planning"></iframe>`:file.type.startsWith('image/')?title+`<img class="mx-auto max-h-80 rounded-xl border bg-white object-contain" src="${url}" alt="Aperçu du planning">`:title+'<p class="rounded-xl bg-white p-3 text-sm text-slate-600">Le fichier Excel sera transmis avec le contrat.</p>';};const set=file=>{if(!file)return;const ext=(file.name.split('.').pop()||'').toLowerCase();if(!['pdf','xlsx','jpg','jpeg','png'].includes(ext)){alert('Planning PDF, XLSX, JPG ou PNG attendu.');return;}if(file.size>15*1024*1024){alert('Le planning doit faire moins de 15 Mo.');return;}const dt=new DataTransfer();dt.items.add(file);input.files=dt.files;show(file);};zone.onclick=()=>input.click();input.onchange=()=>set(input.files[0]);['dragenter','dragover'].forEach(n=>zone.addEventListener(n,e=>{e.preventDefault();zone.classList.add('border-indigo-600','bg-indigo-100');}));['dragleave','drop'].forEach(n=>zone.addEventListener(n,e=>{e.preventDefault();zone.classList.remove('border-indigo-600','bg-indigo-100');}));zone.addEventListener('drop',e=>set(e.dataTransfer.files[0]));})();</script><?php endif; ?><?php if ($selectedStage === 'comptabilite'): ?><script>(()=>{const schedule=document.getElementById('contractFinanceSchedule'),form=schedule?.closest('form');if(!schedule||!form)return;const panel=schedule.closest('section'),grid=form.querySelector(':scope > .grid'),conditions=form.querySelector('[name="data[conditions_financieres]"]')?.closest('label'),penalties=form.querySelector('[name="data[penalites_retard]"]')?.closest('label'),signer=form.querySelector('[name="signataire_personnel_id"]')?.closest('label');if(grid&&panel)grid.insertAdjacentElement('afterend',panel);if(panel&&conditions)panel.insertAdjacentElement('afterend',conditions);if(conditions&&penalties)conditions.insertAdjacentElement('afterend',penalties);if(penalties&&signer)penalties.insertAdjacentElement('afterend',signer);})();</script><?php endif; ?>
    <?php if ($selectedStage === 'comptabilite'): ?><script>(()=>{const schedule=document.getElementById('contractFinanceSchedule'),form=schedule?.closest('form'),header=schedule?.closest('table')?.querySelector('thead tr');if(header)header.innerHTML='<th class="p-3 text-left">Déboursé / ligne</th><th class="p-3 text-right">Poids ligne</th><th class="p-3 text-right">Avancement estimé</th><th class="p-3 text-right">Brut</th><th class="p-3 text-right">Récup. acompte</th><th class="p-3 text-right">Retenue garantie</th><th class="p-3 text-right">Net à payer</th>';if(!schedule||!form)return;const panel=schedule.closest('section'),grid=form.querySelector(':scope > .grid'),conditions=form.querySelector('[name="data[conditions_financieres]"]')?.closest('label'),penalties=form.querySelector('[name="data[penalites_retard]"]')?.closest('label'),signer=form.querySelector('[name="signataire_personnel_id"]')?.closest('label');if(grid&&panel)grid.insertAdjacentElement('afterend',panel);if(panel&&conditions)panel.insertAdjacentElement('afterend',conditions);if(conditions&&penalties)conditions.insertAdjacentElement('afterend',penalties);if(penalties&&signer)penalties.insertAdjacentElement('afterend',signer);})();</script><?php endif; ?>
    <?php if ($selectedStage === 'comptabilite'): ?><script>(() => {
        const schedule = document.getElementById('contractFinanceSchedule');
        const source = document.getElementById('contractFinanceLines');
        if (!schedule || !source) return;
        let lines = [];
        try { lines = JSON.parse(source.textContent || '[]'); } catch (_) { lines = []; }
        const form = schedule.closest('form');
        const value = name => Math.max(0, Math.min(100, Number(form?.querySelector(`[name="data[${name}]"]`)?.value || 0)));
        const saved = <?= json_encode(is_array($data['debourse_avancement'] ?? null) ? $data['debourse_avancement'] : [], JSON_HEX_TAG | JSON_HEX_AMP) ?>;
        const escapeHtml = item => String(item ?? '').replace(/[&<>]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[char]));
        const money = amount => Math.round(Number(amount) || 0).toLocaleString('fr-FR') + ' FCFA';
        const render = () => {
            const advance = value('taux_acompte') / 100;
            const guarantee = value('retenue_garantie') / 100;
            const defaultProgress = value('pourcentage_demarrage');
            const header = schedule.closest('table')?.querySelector('thead tr');
            if (header) header.innerHTML = '<th class="p-3 text-left">Déboursé / ligne</th><th class="p-3 text-right">Poids ligne</th><th class="p-3 text-right">Avancement estimé</th><th class="p-3 text-right">Brut</th><th class="p-3 text-right">Récup. acompte</th><th class="p-3 text-right">Retenue garantie</th><th class="p-3 text-right">Net à payer</th>';
            if (!lines.length) { schedule.innerHTML = '<tr><td colspan="7" class="p-5 text-center font-semibold text-slate-500">Aucune ligne de déboursé n’est encore rattachée à ce contrat.</td></tr>'; return; }
            const groups = new Map();
            lines.forEach(line => { const key = line.group || 'Déboursé'; if (!groups.has(key)) groups.set(key, []); groups.get(key).push(line); });
            const rows = []; let grossTotal = 0, recoveryTotal = 0, retentionTotal = 0, netTotal = 0;
            const advanceAmount = lines.reduce((sum, line) => sum + Number(line.montant || 0), 0) * advance;
            if (advanceAmount > 0) rows.push(`<tr class="border-b bg-amber-50"><td colspan="3" class="p-3 font-black">Acompte contractuel (${(advance * 100).toFixed(2)} %)</td><td class="p-3 text-right">${money(advanceAmount)}</td><td class="p-3 text-right">—</td><td class="p-3 text-right">—</td><td class="p-3 text-right font-black text-emerald-700">${money(advanceAmount)}</td></tr>`);
            for (const [group, groupLines] of groups) {
                rows.push(`<tr class="bg-indigo-100 text-indigo-950"><td colspan="7" class="p-3 font-black">${escapeHtml(group)}</td></tr>`);
                for (const line of groupLines) {
                    const progress = Math.max(0, Math.min(100, Number(saved[line.id] ?? defaultProgress)));
                    const gross = Number(line.montant || 0) * progress / 100;
                    const recovery = gross * advance;
                    const retention = gross * guarantee;
                    const net = Math.max(0, gross - recovery - retention);
                    grossTotal += gross; recoveryTotal += recovery; retentionTotal += retention; netTotal += net;
                    rows.push(`<tr class="border-b"><td class="p-3 font-bold">${escapeHtml(line.designation)}${line.categorie ? `<small class="block font-medium text-slate-500">${escapeHtml(line.categorie)}</small>` : ''}</td><td class="p-3 text-right">${Number(line.line_weight || 0).toLocaleString('fr-FR')} %</td><td class="p-3 text-right"><input type="number" min="0" max="100" step="0.01" name="data[debourse_avancement][${escapeHtml(line.id)}]" value="${progress}" class="w-24 rounded-lg border px-2 py-1 text-right"> %</td><td class="p-3 text-right">${money(gross)}</td><td class="p-3 text-right text-rose-700">− ${money(recovery)}</td><td class="p-3 text-right text-rose-700">− ${money(retention)}</td><td class="p-3 text-right font-black text-emerald-700">${money(net)}</td></tr>`);
                }
            }
            if (retentionTotal > 0) rows.push(`<tr class="bg-emerald-50"><td colspan="6" class="p-3 font-black">Dernière ligne · restitution de la retenue de garantie</td><td class="p-3 text-right font-black text-emerald-700">+ ${money(retentionTotal)}</td></tr>`);
            rows.push(`<tr class="bg-slate-950 text-white"><td colspan="3" class="p-3 font-black">TOTAL NET À PAYER</td><td class="p-3 text-right">${money(grossTotal + advanceAmount)}</td><td class="p-3 text-right">− ${money(recoveryTotal)}</td><td class="p-3 text-right">− ${money(retentionTotal)}</td><td class="p-3 text-right text-lg font-black text-emerald-300">${money(netTotal + retentionTotal + advanceAmount)}</td></tr>`);
            schedule.innerHTML = rows.join('');
            schedule.querySelectorAll('input[name^="data[debourse_avancement]"]').forEach(input => input.addEventListener('input', () => { saved[input.name.match(/\[([^\]]+)\]$/)?.[1] || ''] = input.value; render(); }));
        };
        ['taux_acompte', 'pourcentage_demarrage', 'retenue_garantie'].forEach(name => form?.querySelector(`[name="data[${name}]"]`)?.addEventListener('input', render));
        render();
    })();</script><?php endif; ?>
    <?php if ((!$dgSigned || $legacySignatureRegularization) && (in_array($stage['statut'], ['en_cours', 'en_attente'], true) || $legacySignatureRegularization) && $stageAccess): ?><form method="post" id="workflowSignForm" data-passkey-context="contract:<?= $id ?>:<?= $h($selectedStage) ?>:<?= $stageSignatureHash ?>" data-passkey-endpoint-base="../request" class="mt-6 rounded-2xl border border-amber-300 bg-amber-50 p-5"><input type="hidden" name="_csrf" value="<?= $h($_SESSION['contract_workflow_csrf']) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="etape" value="<?= $h($selectedStage) ?>"><input type="hidden" name="action" value="sign"><input type="hidden" name="stage_hash" value="<?= $stageSignatureHash ?>"><input type="hidden" name="signature" id="workflowSignature">
            <h3 class="font-black"><?= $legacySignatureRegularization ? 'Régulariser la signature historique' : ($stage['statut'] === 'en_attente' ? 'Pré-signature de votre étape' : 'Signature interne de validation') ?></h3>
            <p class="mt-1 text-sm text-amber-900"><?= $legacySignatureRegularization ? 'Cette étape est déjà validée mais sa signature est absente. Votre visa sera ajouté sans rouvrir le contrat ni modifier son circuit.' : ($stage['statut'] === 'en_attente' ? 'Vous pouvez signer maintenant. Votre signature sera conservée puis transmise lorsque l’étape précédente sera validée.' : (($isDrh || $isAdmin) ? 'Mode administrateur / DRH : votre signature peut valider cette étape interne.' : 'Signataire attendu : ' . $h($stage['signataire_nom'] . ' · ' . $stage['signataire_fonction']))) ?></p><canvas id="workflowCanvas" width="800" height="220" class="mt-4 w-full rounded-2xl border bg-white"></canvas>
            <div class="mt-3 flex gap-2"><button type="button" id="workflowClear" class="rounded-xl border bg-white px-4 py-2 font-bold">Effacer</button><button class="rounded-xl bg-amber-500 px-5 py-2 font-black text-black"><?= $legacySignatureRegularization ? 'Ajouter ma signature historique' : ($stage['statut'] === 'en_attente' ? 'Pré-signer cette étape' : 'Signer et transmettre à l’étape suivante') ?></button></div>
        </form><?php endif; ?><?php if ($isDrh && !$dgSigned && $c['statut'] !== 'resilie'): ?><form method="post" class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5"><input type="hidden" name="_csrf" value="<?= $h($_SESSION['contract_workflow_csrf']) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="etape" value="<?= $h($selectedStage) ?>"><input type="hidden" name="action" value="cancel_contract"><b class="block text-rose-900">Annuler ce contrat</b>
            <p class="mt-1 text-sm text-rose-800">Les lignes de devis et déboursés bloqués par ce contrat seront libérés.</p><button class="mt-3 rounded-xl bg-rose-700 px-5 py-3 font-black text-white" onclick="return confirm('Annuler ce contrat et libérer ses éléments bloqués ?')">Annuler le contrat</button>
        </form><?php endif; ?>
</section>
<?php if ($selectedStage === 'qhse' && $editable): ?><script>(() => {
    const input = document.querySelector('input[name="qhse_file"]');
    const zone = input?.closest('section');
    const preview = zone?.querySelector('[data-qhse-preview]');
    if (!input || !zone || !preview) return;
    const setFile = file => {
        if (!file) return;
        const extension = (file.name.split('.').pop() || '').toLowerCase();
        if (!['xlsx', 'xls'].includes(extension)) { alert('Veuillez importer un fichier Excel (.xlsx ou .xls).'); return; }
        if (file.size > 15 * 1024 * 1024) { alert('Le registre QHSE doit faire moins de 15 Mo.'); return; }
        const transfer = new DataTransfer(); transfer.items.add(file); input.files = transfer.files;
        preview.textContent = file.name + ' · ' + Math.round(file.size / 1024).toLocaleString('fr-FR') + ' Ko · prêt à être enregistré.';
    };
    input.addEventListener('change', () => setFile(input.files[0]));
    ['dragenter', 'dragover'].forEach(event => zone.addEventListener(event, e => { e.preventDefault(); zone.classList.add('border-emerald-600', 'bg-emerald-100'); }));
    ['dragleave', 'drop'].forEach(event => zone.addEventListener(event, e => { e.preventDefault(); zone.classList.remove('border-emerald-600', 'bg-emerald-100'); }));
    zone.addEventListener('drop', e => setFile(e.dataTransfer.files[0]));
})();</script><?php endif; ?>
<script>
    (() => {
        const dataForm = document.querySelector('form[enctype="multipart/form-data"]');
        const saveButton = dataForm?.querySelector('button[type="submit"], button:not([type])');
        const signer = dataForm?.querySelector('select[name="signataire_personnel_id"]');
        const action = dataForm?.querySelector('input[name="action"]');
        if (!dataForm || !signer || !action) return;
        dataForm.id = 'workflowDataForm';
        if (saveButton) saveButton.setAttribute('form', 'workflowDataForm');
        const delegate = document.createElement('button');
        delegate.id = 'workflowDelegateSigner';
        delegate.type = 'button';
        delegate.className = 'rounded-xl border border-indigo-300 bg-indigo-50 px-5 py-3 font-black text-indigo-900';
        delegate.innerHTML = '<i class="fas fa-share-from-square mr-2"></i>Transmettre l’ordre de signer';
        delegate.onclick = () => {
            if (!signer.value) { alert('Sélectionnez d’abord le collaborateur qui doit signer cette étape.'); signer.focus(); return; }
            if (!confirm('Transmettre l’ordre de signature à ce collaborateur ?')) return;
            action.value = 'delegate_signer';
            dataForm.requestSubmit();
        };
        const actions = document.createElement('div');
        actions.className = 'mt-6 flex flex-wrap gap-2';
        if (!window.contractWorkflowDgSigned) actions.append(delegate);
        if (saveButton) actions.append(saveButton);
        const signatureForm = document.getElementById('workflowSignForm');
        const cancelForm = document.querySelector('form input[name="action"][value="cancel_contract"]')?.closest('form');
        (cancelForm || signatureForm || dataForm).insertAdjacentElement('afterend', actions);
    })();
</script>
</main>
<script>
(() => {
    const dataForm = document.getElementById('workflowDataForm');
    const signForm = document.getElementById('workflowSignForm');
    if (!dataForm || !signForm) return;
    let dirty = false;
    dataForm.addEventListener('input', () => { dirty = true; });
    dataForm.addEventListener('change', () => { dirty = true; });
    dataForm.addEventListener('click', event => { if (event.target.closest('button[type="button"]')) dirty = true; });
    signForm.addEventListener('submit', event => {
        const pendingFile = [...dataForm.querySelectorAll('input[type="file"]')].some(input => input.files.length > 0);
        if (!dirty && !pendingFile) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        alert('Enregistrez vos ajustements avant de signer cette étape.');
    });
})();
</script>
<script>
    (() => {
        const c = document.getElementById('workflowCanvas');
        if (!c) return;
        const x = c.getContext('2d');
        let d = false,
            w = false,
            p = e => {
                const r = c.getBoundingClientRect(),
                    q = e.touches?.[0] || e;
                return {
                    x: (q.clientX - r.left) * c.width / r.width,
                    y: (q.clientY - r.top) * c.height / r.height
                }
            };
        c.onpointerdown = e => {
            d = w = true;
            const q = p(e);
            x.beginPath();
            x.moveTo(q.x, q.y)
        };
        c.onpointermove = e => {
            if (!d) return;
            const q = p(e);
            x.lineWidth = 3;
            x.lineCap = 'round';
            x.lineTo(q.x, q.y);
            x.stroke()
        };
        c.onpointerup = c.onpointerleave = () => d = false;
        document.getElementById('workflowClear').onclick = () => {
            x.clearRect(0, 0, c.width, c.height);
            w = false
        };
        const profileSignature = window.contractWorkflowProfileSignature || '';
        const storedStageSignature = window.contractWorkflowStoredStageSignature || '';
        if (storedStageSignature) {
            document.getElementById('workflowSignature').value = storedStageSignature;
            const notice = document.createElement('div');
            notice.className = 'mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900';
            notice.innerHTML = '<b>Signature déjà enregistrée.</b> Cliquez sur transmettre pour valider cette étape, ou dessinez une nouvelle signature pour la remplacer.';
            const image = document.createElement('img');
            image.src = storedStageSignature;
            image.alt = 'Signature enregistrée';
            image.className = 'mt-2 h-12 max-w-full rounded border bg-white object-contain';
            notice.appendChild(image);
            c.insertAdjacentElement('beforebegin', notice);
            const submitButton = document.querySelector('#workflowSignForm button[type="submit"]');
            if (submitButton) submitButton.textContent = 'Transmettre la signature enregistrée';
        }
        if (profileSignature) {
            const profileButton = document.createElement('button');
            profileButton.type = 'button';
            profileButton.className = 'rounded-xl border border-indigo-300 bg-white px-4 py-2 font-bold text-indigo-800';
            profileButton.textContent = 'Utiliser ma signature de profil';
            profileButton.onclick = () => {
                document.getElementById('workflowSignature').value = profileSignature;
                w = false;
                document.getElementById('workflowSignForm').requestSubmit();
            };
            document.getElementById('workflowClear').insertAdjacentElement('afterend', profileButton);
            const passkeyNotice = document.createElement('p');
            passkeyNotice.className = 'mt-3 w-full rounded-2xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-semibold leading-6 text-indigo-900';
            passkeyNotice.innerHTML = '<i class="fa-solid fa-shield-halved mr-2 text-indigo-600"></i><b>Confirmation Passkey obligatoire.</b> Votre téléphone, votre empreinte ou votre visage confirmera votre identité avant l’enregistrement de la signature.';
            profileButton.closest('.mt-3.flex')?.insertAdjacentElement('afterend', passkeyNotice);
        }
        document.getElementById('workflowSignForm').onsubmit = e => {
            if (!w && !document.getElementById('workflowSignature').value) {
                e.preventDefault();
                alert('Veuillez dessiner votre signature ou utiliser votre signature de profil.');
                return
            }
            if (w) document.getElementById('workflowSignature').value = c.toDataURL('image/png')
        }
    })();
</script><?php require __DIR__ . '/../decaissement/includes/footer.php'; ?>

