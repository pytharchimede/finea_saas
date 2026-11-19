<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/FebValidationPolicy.php';
require_once __DIR__ . '/../services/FebBeneficiarySyncService.php';
require_once __DIR__ . '/../employe/includes/helpers.php';

$pdo = \Database::getConnection();
$manager = new EmployeeLegalRequestManager($pdo);

$requestId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
if ($requestId <= 0) {
    http_response_code(400);
    echo 'ID manquant.';
    exit;
}

$request = $manager->getRequestById($requestId);
if (!$request || ($request['request_type'] ?? '') !== 'feb') {
    http_response_code(404);
    echo 'FEB introuvable.';
    exit;
}

$actorUserId = (int) ($_SESSION['user_id'] ?? 0);
$isRequester = $actorUserId > 0 && (int) ($request['requester_user_id'] ?? 0) === $actorUserId;
$userEmail = (string) ($_SESSION['mail_pro'] ?? '');
$personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
$actorMatricule = strtoupper(trim((string) ($personnelProfile['matricule'] ?? '')));
$payloadMatricule = strtoupper(trim((string) (($request['payload']['matricule'] ?? ''))));
$isRequesterByMatricule = ($actorMatricule !== '' && $payloadMatricule !== '' && $actorMatricule === $payloadMatricule);

$hasFinanceAccess = hasPermission('acces_feb')
    || hasPermission('acces_admin');

$canFinanceExport = false;
if ($hasFinanceAccess && $actorUserId > 0) {
    $perm = FebValidationPolicy::getUserServicePermissions($pdo, $actorUserId);
    $canFinanceExport = FebValidationPolicy::canValidateService($perm, FebValidationPolicy::requestServiceId($request));
}

if (!$isRequester && !$isRequesterByMatricule && !$canFinanceExport) {
    http_response_code(403);
    echo 'Acces refuse.';
    exit;
}

function bci_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bci_money($value): string
{
    return number_format((float) $value, 0, ',', ' ') . ' FCFA';
}

function bci_date($value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '-';
    }
    try {
        return (new DateTime($value))->format('d/m/Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function bci_supplier_from_needs(array $needs): array
{
    foreach ($needs as $need) {
        if (!is_array($need)) {
            continue;
        }
        $supplier = !empty($need['fournisseur']) && is_array($need['fournisseur']) ? (array) $need['fournisseur'] : [];
        $name = trim((string) ($supplier['nom'] ?? ''));
        if ($name !== '') {
            return [
                'nom' => $name,
                'telephone' => trim((string) ($supplier['telephone'] ?? '')),
                'email' => trim((string) ($supplier['email'] ?? '')),
                'adresse' => trim((string) ($supplier['adresse'] ?? '')),
            ];
        }
    }

    return ['nom' => 'Fournisseur a renseigner', 'telephone' => '', 'email' => '', 'adresse' => ''];
}

$payload = (array) ($request['payload'] ?? []);
FebBeneficiarySyncService::syncFiche($pdo, $requestId);
$selectedFebBeneficiary = FebBeneficiarySyncService::selected($pdo, $payload);
$display = (array) ($request['feb_display'] ?? EmployeeLegalRequestManager::extractFebDisplay($request));
$needs = (array) ($payload['besoins'] ?? []);
$files = (array) ($payload['justificatifs'] ?? []);
$invoiceDocument = is_array($payload['invoice_document'] ?? null) ? (array) $payload['invoice_document'] : [];
$supplier = bci_supplier_from_needs($needs);
$linkedFiche = [];
try {
    $linkedFicheStmt = $pdo->prepare("SELECT num_fiche,beficiaire_fiche,tel_beneficiaire_fiche,personnel_id,fournisseur_id,code_autorisation_feb
        FROM fiche_decaissement
        WHERE feb_request_id=:feb_id
        ORDER BY COALESCE(decaisse,0) DESC,id_fiche DESC LIMIT 1");
    $linkedFicheStmt->execute(['feb_id' => $requestId]);
    $linkedFiche = $linkedFicheStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $linkedFiche = [];
}

// Le bénéficiaire choisi dans la FEB prime sur le demandeur et le fournisseur d'achat.
$provisionalDecaissement = is_array($payload['provisional_decaissement'] ?? null) ? (array)$payload['provisional_decaissement'] : [];
$beneficiaryChoice = strtolower(trim((string)($payload['beneficiary_choice'] ?? $provisionalDecaissement['beneficiary_choice'] ?? 'requester')));
if (!in_array($beneficiaryChoice, ['requester', 'supplier'], true)) $beneficiaryChoice = 'requester';
if (!$selectedFebBeneficiary && (int)($linkedFiche['personnel_id'] ?? 0) > 0) {
    $beneficiaryChoice = 'requester';
} elseif (!$selectedFebBeneficiary && (int)($linkedFiche['fournisseur_id'] ?? 0) > 0) {
    $beneficiaryChoice = 'supplier';
}
$recipient = [
    'type' => 'Demandeur',
    'nom' => trim((string) ($display['demandeur'] ?? ($request['requester_name'] ?? $payload['nom'] ?? ''))),
    'telephone' => '',
    'email' => trim((string) ($request['requester_email'] ?? '')),
    'adresse' => '',
];
$beneficiaryPersonnelId = 0;

if ($selectedFebBeneficiary) {
    $beneficiaryChoice = $selectedFebBeneficiary['mode'] === 'supplier' ? 'supplier' : 'requester';
    $beneficiaryPersonnelId = (int) ($selectedFebBeneficiary['personnel_id'] ?? 0);
    $recipient = [
        'type' => $selectedFebBeneficiary['mode'] === 'supplier' ? 'Fournisseur bénéficiaire' : ($selectedFebBeneficiary['mode'] === 'external' ? 'Bénéficiaire externe' : 'Personnel bénéficiaire'),
        'nom' => $selectedFebBeneficiary['name'],
        'telephone' => $selectedFebBeneficiary['phone'],
        'email' => '',
        'adresse' => '',
    ];
} elseif ($beneficiaryChoice === 'supplier') {
    $selectedSupplierId = max((int)($payload['fournisseur_id'] ?? 0), (int)($provisionalDecaissement['fournisseur_id'] ?? 0));
    if ($selectedSupplierId > 0) {
        $selectedSupplierStmt = $pdo->prepare('SELECT raison_sociale,nom_commercial,contact_nom,telephone,email,adresse FROM fournisseur_banamur WHERE id_fournisseur=:id LIMIT 1');
        $selectedSupplierStmt->execute(['id' => $selectedSupplierId]);
        $selectedSupplier = $selectedSupplierStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($selectedSupplier) {
            $supplier = [
                'nom' => trim((string)($selectedSupplier['nom_commercial'] ?? '')) ?: trim((string)($selectedSupplier['raison_sociale'] ?? '')),
                'telephone' => trim((string)($selectedSupplier['telephone'] ?? '')),
                'email' => trim((string)($selectedSupplier['email'] ?? '')),
                'adresse' => trim((string)($selectedSupplier['adresse'] ?? '')),
            ];
        }
    }
    $recipient = array_merge(['type' => 'Fournisseur'], $supplier);
    $actualPaymentBeneficiary = trim((string)($linkedFiche['beficiaire_fiche'] ?? ''));
    if ($actualPaymentBeneficiary !== '') {
        $recipient['nom'] = $actualPaymentBeneficiary;
        $recipient['telephone'] = trim((string)($linkedFiche['tel_beneficiaire_fiche'] ?? '')) ?: (string)($recipient['telephone'] ?? '');
        $recipient['type'] = 'Fournisseur · bénéficiaire réel du paiement';
    }
} else {
    $requesterPersonnelId = max((int)($linkedFiche['personnel_id'] ?? 0), (int)($payload['personnel_id'] ?? 0), (int)($provisionalDecaissement['personnel_id'] ?? 0));
    $beneficiaryPersonnelId = $requesterPersonnelId;
    if ($requesterPersonnelId > 0) {
        $personnelStmt = $pdo->prepare('SELECT nom_personnel_soignant,tel_personnel_soignant,email_personnel_soignant FROM personnel WHERE id_personnel_soignant=:id LIMIT 1');
        $personnelStmt->execute(['id' => $requesterPersonnelId]);
        $personnel = $personnelStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($personnel) {
            $recipient['nom'] = trim((string)($personnel['nom_personnel_soignant'] ?? '')) ?: $recipient['nom'];
            $recipient['telephone'] = trim((string)($personnel['tel_personnel_soignant'] ?? ''));
            $recipient['email'] = trim((string)($personnel['email_personnel_soignant'] ?? '')) ?: $recipient['email'];
        }
    }
    $actualPersonnelBeneficiary = trim((string)($linkedFiche['beficiaire_fiche'] ?? ''));
    if ($actualPersonnelBeneficiary !== '') {
        $recipient['nom'] = $actualPersonnelBeneficiary;
        $recipient['telephone'] = trim((string)($linkedFiche['tel_beneficiaire_fiche'] ?? '')) ?: (string)($recipient['telephone'] ?? '');
        $recipient['type'] = 'Personnel · bénéficiaire réel du paiement';
    }
}

$loadBciSigner = static function (int $userId, string $fallbackName = '') use ($pdo): array {
    if ($userId <= 0) {
        return ['name' => trim($fallbackName), 'image' => ''];
    }
    try {
        $stmt = $pdo->prepare('SELECT prenom,nom,signature,signature_data FROM user_devis_banamur WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $name = trim((string)($user['prenom'] ?? '') . ' ' . (string)($user['nom'] ?? '')) ?: trim($fallbackName);
        $binary = '';
        $path = trim((string)($user['signature'] ?? ''));
        if ($path !== '') {
            $absolute = realpath(dirname(__DIR__) . '/' . ltrim($path, '/'));
            $root = realpath(dirname(__DIR__));
            if ($absolute && $root && str_starts_with($absolute, $root . DIRECTORY_SEPARATOR) && is_file($absolute) && is_readable($absolute)) {
                $binary = (string)file_get_contents($absolute);
            }
        }
        if ($binary === '') {
            $data = trim((string)($user['signature_data'] ?? ''));
            if (preg_match('#^data:image/png;base64,#', $data)) {
                $decoded = base64_decode(substr($data, strpos($data, ',') + 1), true);
                if (is_string($decoded)) $binary = $decoded;
            }
        }
        return ['name' => $name, 'image' => $binary !== '' ? '@' . $binary : ''];
    } catch (Throwable $e) {
        return ['name' => trim($fallbackName), 'image' => ''];
    }
};

$requesterSigner = $loadBciSigner((int)($request['requester_user_id'] ?? 0), (string)($request['requester_name'] ?? $display['demandeur'] ?? ''));
$validatorSigner = $loadBciSigner((int)($request['decided_by_user_id'] ?? 0), (string)($request['decided_by_name'] ?? 'Validateur FEB'));
if ($beneficiaryChoice === 'supplier') {
    $beneficiaryVisa = [
        'label' => 'SIGNATURE P.O. · FOURNISSEUR',
        'name' => ($requesterSigner['name'] ?: 'Émetteur de la FEB') . ' · P.O.',
        'image' => $requesterSigner['image'],
        'note' => 'Signe P.O. pour le bénéficiaire réel : ' . ($recipient['nom'] ?: 'Fournisseur bénéficiaire'),
    ];
} else {
    $beneficiaryUserId = 0;
    if ($beneficiaryPersonnelId > 0) {
        try {
            $beneficiaryUserStmt = $pdo->prepare('SELECT id FROM user_devis_banamur WHERE personnel_id=:personnel_id ORDER BY active DESC,id DESC LIMIT 1');
            $beneficiaryUserStmt->execute(['personnel_id' => $beneficiaryPersonnelId]);
            $beneficiaryUserId = (int)$beneficiaryUserStmt->fetchColumn();
        } catch (Throwable $e) {
            $beneficiaryUserId = 0;
        }
    }
    if ($beneficiaryUserId <= 0 && strcasecmp(trim((string)$recipient['nom']), trim((string)$requesterSigner['name'])) === 0) {
        $beneficiaryUserId = (int)($request['requester_user_id'] ?? 0);
    }
    $personnelSigner = $loadBciSigner($beneficiaryUserId, (string)($recipient['nom'] ?: 'Bénéficiaire membre du personnel'));
    $beneficiaryVisa = [
        'label' => 'BÉNÉFICIAIRE MEMBRE DU PERSONNEL',
        'name' => $personnelSigner['name'] ?: ($recipient['nom'] ?: 'Bénéficiaire membre du personnel'),
        'image' => $personnelSigner['image'],
        'note' => 'Signature directe et personnelle du bénéficiaire · sans P.O.',
    ];
}

$bciNumber = trim((string) ($display['numero_bon_commande_interne'] ?? ''));
if ($bciNumber === '') {
    http_response_code(409);
    echo 'Le BCI sera disponible après le positionnement de la fiche dans le planning des décaissements.';
    exit;
}
$bciNumber = preg_replace('/^AUTH-FEB/i', 'BCI-FEB', $bciNumber) ?: $bciNumber;

$status = strtolower(trim((string) ($request['status'] ?? 'submitted')));
$statusLabel = [
    'submitted' => 'En attente',
    'approved' => 'Valide',
    'rejected' => 'Refuse',
    'cancelled' => 'Annule',
][$status] ?? $status;

$rowsHtml = '';
$orderRows = [];
$subtotal = 0.0;
$index = 0;
foreach ($needs as $need) {
    if (!is_array($need)) {
        continue;
    }
    $index++;
    $supplierRow = !empty($need['fournisseur']) && is_array($need['fournisseur']) ? (array) $need['fournisseur'] : [];
    $quantity = (float) ($need['quantite'] ?? 0);
    $unitPrice = (float) ($supplierRow['prix_unitaire'] ?? 0);
    $lineTotal = ($quantity > 0 && $unitPrice > 0) ? $quantity * $unitPrice : 0.0;
    $subtotal += $lineTotal;
    $orderRows[] = [
        'index' => $index,
        'category' => trim((string) ($need['categorie'] ?? '')),
        'label' => trim((string) ($need['libelle'] ?? '')),
        'type' => trim((string) ($need['type'] ?? '')),
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'total' => $lineTotal,
    ];
    $rowsHtml .= '<tr>
        <td class="center">' . $index . '</td>
        <td>' . bci_h($need['categorie'] ?? '') . '</td>
        <td>' . bci_h($need['libelle'] ?? '') . '</td>
        <td>' . bci_h($need['type'] ?? '') . '</td>
        <td class="center">' . bci_h((string) $quantity) . '</td>
        <td class="right">' . ($unitPrice > 0 ? bci_h(number_format($unitPrice, 0, ',', ' ')) : '-') . '</td>
        <td class="right">' . ($lineTotal > 0 ? bci_h(number_format($lineTotal, 0, ',', ' ')) : '-') . '</td>
    </tr>';
}
if ($rowsHtml === '') {
    $rowsHtml = '<tr><td colspan="7" class="center muted">Aucune ligne de commande renseignee.</td></tr>';
}

$vat = 0.0;
$bciDetails = is_array($payload['bci_details'] ?? null) ? (array) $payload['bci_details'] : [];
$initialAmount = (float) ($bciDetails['initial_amount'] ?? $payload['initial_requested_amount'] ?? $payload['montant'] ?? $subtotal);
$approvedAmount = (float) ($bciDetails['approved_amount'] ?? $payload['montant'] ?? $subtotal);
$grandTotal = $approvedAmount > 0 ? $approvedAmount : ($subtotal + $vat);
$validationAdjustment = $grandTotal - ($subtotal + $vat);

class FebProfessionalBciPdf extends TCPDF
{
    use CompanyPdfTemplate;

    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}

$pdf = new FebProfessionalBciPdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->companyPdfTitle = 'Bon de commande interne';
$pdf->companyPdfType = 'BCI issu de FEB';
$pdf->companyPdfId = $bciNumber;
$pdf->companyPdfCode = 'FIN-FEB-BCI';
$pdf->companyPdfQrPayload = json_encode(['type' => 'bci', 'number' => $bciNumber, 'feb_id' => $requestId, 'status' => $statusLabel], JSON_UNESCAPED_UNICODE);
$pdf->SetCreator('FINEA');
$pdf->SetAuthor('BANAMUR / FIDESTCI');
$pdf->SetTitle('Bon de commande interne ' . $bciNumber);
$pdf->SetMargins(10, 38, 10);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

$contentWidth = $pdf->getPageWidth() - 20;
$navy = [15, 23, 42];
$slate = [71, 85, 105];
$line = [226, 232, 240];
$soft = [248, 250, 252];
$green = [16, 185, 129];

$valueOrDash = static fn($value): string => trim((string) $value) !== '' ? trim((string) $value) : '—';
$moneyShort = static fn($value): string => number_format((float) $value, 0, ',', ' ');
$checkPage = static function (float $height) use ($pdf): void {
    if ($pdf->GetY() + $height > $pdf->getPageHeight() - 27) {
        $pdf->AddPage();
        $pdf->SetY(39);
    }
};
$sectionTitle = static function (string $title, string $subtitle = '') use ($pdf, $navy, $slate): void {
    $pdf->SetTextColor(...$navy);
    $pdf->SetFont('dejavusans', 'B', 9.5);
    $pdf->Cell(0, 5, $title, 0, 1, 'L');
    if ($subtitle !== '') {
        $pdf->SetTextColor(...$slate);
        $pdf->SetFont('dejavusans', '', 6.8);
        $pdf->Cell(0, 4, $subtitle, 0, 1, 'L');
    }
};
$infoCard = static function (float $x, float $y, float $width, float $height, string $title, array $items, array $accent) use ($pdf, $navy, $slate, $line, $soft): void {
    $pdf->SetFillColor(...$soft);
    $pdf->SetDrawColor(...$line);
    $pdf->RoundedRect($x, $y, $width, $height, 3, '1111', 'DF');
    $pdf->SetFillColor(...$accent);
    $pdf->RoundedRect($x, $y, 2.6, $height, 1.2, '1001', 'F');
    $pdf->SetXY($x + 7, $y + 4);
    $pdf->SetTextColor(...$navy);
    $pdf->SetFont('dejavusans', 'B', 8.2);
    $pdf->Cell($width - 11, 4, mb_strtoupper($title), 0, 1, 'L', false, '', 1);
    $cursorY = $y + 10;
    foreach ($items as [$label, $value]) {
        if ($cursorY > $y + $height - 5) break;
        $pdf->SetXY($x + 7, $cursorY);
        $pdf->SetTextColor(...$slate);
        $pdf->SetFont('dejavusans', 'B', 6.5);
        $pdf->Cell(25, 4, $label, 0, 0, 'L', false, '', 1);
        $pdf->SetTextColor(...$navy);
        $pdf->SetFont('dejavusans', '', 7.2);
        $pdf->Cell($width - 36, 4, $value, 0, 1, 'L', false, '', 1);
        $cursorY += 4.4;
    }
};

// Cartouche d'identification : une lecture immédiate comme sur un véritable BCI.
$heroY = 39;
$pdf->SetFillColor(...$navy);
$pdf->RoundedRect(10, $heroY, $contentWidth, 28, 4, '1111', 'F');
$pdf->SetXY(16, $heroY + 5);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('dejavusans', 'B', 15);
$pdf->Cell(116, 7, 'BON DE COMMANDE INTERNE', 0, 1, 'L');
$pdf->SetX(16);
$pdf->SetTextColor(203, 213, 225);
$pdf->SetFont('dejavusans', '', 7.8);
$pdf->Cell(116, 5, 'Émis à partir de la fiche d’expression de besoin FEB #' . $requestId, 0, 1, 'L');
$pdf->SetXY(142, $heroY + 5);
$pdf->SetTextColor(148, 163, 184);
$pdf->SetFont('dejavusans', 'B', 6.5);
$pdf->Cell(51, 4, 'RÉFÉRENCE DU DOCUMENT', 0, 1, 'R');
$pdf->SetX(142);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->Cell(51, 6, $bciNumber, 0, 1, 'R', false, '', 1);
$pdf->SetX(142);
$pdf->SetFont('dejavusans', '', 7);
$pdf->SetTextColor(203, 213, 225);
$pdf->Cell(51, 5, 'Émis le ' . date('d/m/Y') . '  ·  ' . $statusLabel, 0, 1, 'R');

$pdf->SetY(72);
$gap = 4;
$cardWidth = ($contentWidth - $gap) / 2;
$cardHeight = 39;
$infoCard(10, 72, $cardWidth, $cardHeight, 'Donneur d’ordre', [
    ['Société', 'BANAMUR / FIDESTCI'],
    ['Demandeur', $valueOrDash($display['demandeur'] ?? ($request['requester_name'] ?? ''))],
    ['Service', $valueOrDash($payload['departement_label'] ?? '')],
    ['Livraison', bci_date($payload['date_livraison'] ?? '')],
    ['Référence', 'FEB #' . $requestId],
], [59, 130, 246]);
$infoCard(10 + $cardWidth + $gap, 72, $cardWidth, $cardHeight, 'Destinataire de la commande', [
    ['Qualité', $valueOrDash($recipient['type'])],
    ['Nom', $valueOrDash($recipient['nom'])],
    ['Téléphone', $valueOrDash($recipient['telephone'])],
    ['E-mail', $valueOrDash($recipient['email'])],
    ['Adresse', $valueOrDash($recipient['adresse'])],
], $green);

$pdf->SetY(116);
$sectionTitle('OBJET DE LA COMMANDE', 'Description validée dans la fiche d’expression de besoin');
$object = trim((string) ($display['description'] ?? ($payload['description'] ?? '')));
$objectHeight = max(15, min(28, $pdf->getStringHeight($contentWidth - 12, $object) + 9));
$pdf->SetFillColor(...$soft);
$pdf->SetDrawColor(...$line);
$pdf->RoundedRect(10, $pdf->GetY() + 1, $contentWidth, $objectHeight, 3, '1111', 'DF');
$pdf->SetXY(16, $pdf->GetY() + 5);
$pdf->SetTextColor(...$navy);
$pdf->SetFont('dejavusans', '', 8.3);
$pdf->MultiCell($contentWidth - 12, $objectHeight - 7, $valueOrDash($object), 0, 'L', false, 1, null, null, true, 0, false, true, $objectHeight - 7, 'M');
$pdf->SetY(116 + 9 + $objectHeight + 3);

$tableWidths = [10, 27, 62, 21, 15, 26, 29];
$drawTableHeader = static function () use ($pdf, $tableWidths, $navy): void {
    $labels = ['N°', 'CATÉGORIE', 'DÉSIGNATION', 'TYPE', 'QTÉ', 'PRIX UNIT.', 'MONTANT'];
    $pdf->SetFillColor(...$navy);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 6.5);
    foreach ($labels as $i => $label) {
        $align = $i >= 4 ? 'R' : ($i === 0 ? 'C' : 'L');
        $pdf->Cell($tableWidths[$i], 9, $label, 0, 0, $align, true);
    }
    $pdf->Ln();
};
$checkPage(30);
$sectionTitle('DÉTAIL DE LA COMMANDE', count($orderRows) . ' ligne(s) validée(s)');
$pdf->Ln(1);
$drawTableHeader();
if (!$orderRows) {
    $pdf->SetFillColor(...$soft);
    $pdf->SetTextColor(...$slate);
    $pdf->SetFont('dejavusans', 'I', 8);
    $pdf->Cell(array_sum($tableWidths), 13, 'Aucune ligne de commande renseignée.', 0, 1, 'C', true);
} else {
    foreach ($orderRows as $rowIndex => $row) {
        $designation = $valueOrDash($row['label']);
        $rowHeight = max(10, $pdf->getStringHeight($tableWidths[2] - 4, $designation) + 4);
        if ($pdf->GetY() + $rowHeight > $pdf->getPageHeight() - 36) {
            $pdf->AddPage();
            $pdf->SetY(39);
            $sectionTitle('DÉTAIL DE LA COMMANDE — SUITE');
            $pdf->Ln(1);
            $drawTableHeader();
        }
        $fill = $rowIndex % 2 === 0;
        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
        $pdf->SetDrawColor(...$line);
        $pdf->SetTextColor(...$navy);
        $pdf->SetFont('dejavusans', '', 7.3);
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        $values = [
            (string) $row['index'], $valueOrDash($row['category']), $designation,
            $valueOrDash($row['type']), number_format((float) $row['quantity'], 2, ',', ' '),
            (float) $row['unit_price'] > 0 ? $moneyShort($row['unit_price']) : '—',
            (float) $row['total'] > 0 ? $moneyShort($row['total']) : '—',
        ];
        foreach ($values as $i => $value) {
            $align = $i >= 4 ? 'R' : ($i === 0 ? 'C' : 'L');
            $pdf->MultiCell($tableWidths[$i], $rowHeight, $value, ['B' => ['width' => .2, 'color' => $line]], $align, $fill, 0, $x, $y, true, 0, false, true, $rowHeight, 'M');
            $x += $tableWidths[$i];
        }
        $pdf->SetXY(10, $y + $rowHeight);
    }
}

$totalsHeight = abs($validationAdjustment) >= .01 ? 31 : 24;
$checkPage($totalsHeight + 8);
$pdf->Ln(3);
$totalsWidth = 82;
$totalsX = 10 + $contentWidth - $totalsWidth;
$totalsY = $pdf->GetY();
$pdf->SetFillColor(...$soft);
$pdf->SetDrawColor(...$line);
$pdf->RoundedRect($totalsX, $totalsY, $totalsWidth, $totalsHeight, 3, '1111', 'DF');
$totalLines = [['Sous-total', $subtotal], ['TVA / taxes', $vat]];
if (abs($validationAdjustment) >= .01) $totalLines[] = ['Ajustement validé', $validationAdjustment];
$lineY = $totalsY + 4;
foreach ($totalLines as [$label, $amount]) {
    $pdf->SetXY($totalsX + 5, $lineY);
    $pdf->SetTextColor(...$slate);
    $pdf->SetFont('dejavusans', '', 7);
    $pdf->Cell(38, 4, $label, 0, 0, 'L');
    $pdf->SetTextColor(...$navy);
    $pdf->SetFont('dejavusans', 'B', 7.2);
    $pdf->Cell(34, 4, $moneyShort($amount), 0, 1, 'R');
    $lineY += 5;
}
$pdf->SetFillColor(236, 253, 245);
$pdf->RoundedRect(10, $totalsY, $contentWidth - $totalsWidth - 4, $totalsHeight, 3, '1111', 'F');
$pdf->SetXY(16, $totalsY + 5);
$pdf->SetTextColor(5, 150, 105);
$pdf->SetFont('dejavusans', 'B', 7);
$pdf->Cell(90, 5, 'MONTANT TOTAL DE LA COMMANDE', 0, 1, 'L');
$pdf->SetX(16);
$pdf->SetTextColor(6, 95, 70);
$pdf->SetFont('dejavusans', 'B', 15);
$pdf->Cell(90, 9, bci_money($grandTotal), 0, 1, 'L');
$pdf->SetY($totalsY + $totalsHeight + 5);

$checkPage(48);
$sectionTitle('CONDITIONS ET TRAÇABILITÉ', 'Informations financières arrêtées lors de la validation FEB');
$conditions = [
    ['Traitement financier', $valueOrDash($display['cashflow_need_label'] ?? 'Non précisé')],
    ['Montant initial', bci_money($initialAmount)],
    ['Montant retenu', bci_money($grandTotal)],
    ['Justificatifs', count($files) . ' pièce(s)' . ($invoiceDocument ? ' · Facture : ' . $valueOrDash($invoiceDocument['name'] ?? 'jointe') : '')],
];
$conditionY = $pdf->GetY() + 1;
$infoCard(10, $conditionY, $contentWidth, 29, 'Contrôle interne', $conditions, [139, 92, 246]);
$pdf->SetY($conditionY + 34);

$checkPage(46);
$sectionTitle('VISAS ET RÉCEPTION');
$signatureY = $pdf->GetY() + 1;
$signatureGap = 4;
$signatureWidth = ($contentWidth - 2 * $signatureGap) / 3;
foreach ([
    ['DEMANDEUR / ÉMETTEUR FEB', $valueOrDash($requesterSigner['name']), $requesterSigner['image'], 'Auteur de la demande FEB'],
    ['VALIDATEUR FINAL FEB', $valueOrDash($validatorSigner['name']), $validatorSigner['image'], 'Décision et validation internes'],
    [$beneficiaryVisa['label'], $valueOrDash($beneficiaryVisa['name']), $beneficiaryVisa['image'], $beneficiaryVisa['note']],
] as $i => [$label, $name, $signatureImage, $signatureNote]) {
    $x = 10 + $i * ($signatureWidth + $signatureGap);
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(...$line);
    $pdf->RoundedRect($x, $signatureY, $signatureWidth, 38, 3, '1111', 'DF');
    $pdf->SetXY($x + 4, $signatureY + 4);
    $pdf->SetTextColor(...$slate);
    $pdf->SetFont('dejavusans', 'B', 6.5);
    $pdf->Cell($signatureWidth - 8, 4, $label, 0, 1, 'C', false, '', 1);
    if ($signatureImage !== '') {
        try {
            $pdf->Image($signatureImage, $x + ($signatureWidth - 30) / 2, $signatureY + 9, 30, 13, 'PNG', '', '', false, 300, '', false, false, 0, true);
        } catch (Throwable $e) {
            $signatureImage = '';
        }
    }
    if ($signatureImage === '') {
        $pdf->SetXY($x + 4, $signatureY + 13);
        $pdf->SetTextColor(180, 83, 9);
        $pdf->SetFont('dejavusans', 'I', 6.2);
        $pdf->Cell($signatureWidth - 8, 4, 'Signature de profil non configurée', 0, 1, 'C', false, '', 1);
    }
    $pdf->SetXY($x + 4, $signatureY + 24);
    $pdf->SetTextColor(...$navy);
    $pdf->SetFont('dejavusans', 'B', 7.2);
    $pdf->Cell($signatureWidth - 8, 4, $name, 0, 1, 'C', false, '', 1);
    $pdf->SetXY($x + 3, $signatureY + 28);
    $pdf->SetTextColor(...$slate);
    $pdf->SetFont('dejavusans', '', 5.3);
    $pdf->MultiCell($signatureWidth - 6, 7, $signatureNote, 0, 'C', false, 1, '', '', true, 0, false, true, 7, 'M');
}

$fileName = 'BCI_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $bciNumber) . '.pdf';
$pdf->Output($fileName, 'I');
exit;

$html = '
<style>
    body { font-family: dejavusans; color:#111827; font-size:9px; }
    .top { width:100%; border-bottom:2px solid #111827; padding-bottom:8px; }
    .brand-title { font-size:13px; font-weight:bold; color:#111827; }
    .brand-small { font-size:8px; color:#475569; line-height:1.35; }
    .doc-title { font-size:20px; font-weight:bold; text-align:right; color:#111827; letter-spacing:0.5px; }
    .doc-meta { font-size:9px; text-align:right; color:#334155; line-height:1.5; }
    .badge { background-color:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:4px 8px; font-weight:bold; }
    .section { margin-top:12px; }
    .box { border:1px solid #cbd5e1; padding:8px; }
    .box-title { font-size:9px; font-weight:bold; color:#334155; text-transform:uppercase; border-bottom:1px solid #e2e8f0; padding-bottom:4px; margin-bottom:5px; }
    .muted { color:#64748b; }
    table { border-collapse:collapse; width:100%; }
    th { background-color:#fabd02; border:1px solid #000000; font-weight:bold; color:#000000; padding:6px; }
    td { border:1px solid #cbd5e1; padding:6px; vertical-align:top; }
    .no-border td { border:none; padding:2px; }
    .center { text-align:center; }
    .right { text-align:right; }
    .total-label { background-color:#f8fafc; font-weight:bold; text-align:right; }
    .total-value { font-weight:bold; text-align:right; }
    .sign td { height:44px; text-align:center; vertical-align:bottom; font-weight:bold; }
</style>

<div class="section box" style="border:2px solid #4f46e5; background-color:#eef2ff;">
    <div class="box-title" style="color:#3730a3;">Bon de commande adressé au bénéficiaire sélectionné</div>
    <table class="no-border">
        <tr><td style="width:20%;"><strong>Qualité</strong></td><td>' . bci_h($recipient['type']) . '</td></tr>
        <tr><td><strong>Destinataire</strong></td><td><strong>' . bci_h($recipient['nom'] ?: '-') . '</strong></td></tr>
        <tr><td><strong>Téléphone</strong></td><td>' . bci_h($recipient['telephone'] ?: '-') . '</td></tr>
        <tr><td><strong>E-mail</strong></td><td>' . bci_h($recipient['email'] ?: '-') . '</td></tr>
        ' . (!empty($recipient['adresse']) ? '<tr><td><strong>Adresse</strong></td><td>' . bci_h($recipient['adresse']) . '</td></tr>' : '') . '
    </table>
</div>

<table class="section">
    <tr>
        <td style="width:50%; border:none; padding-right:5px;">
            <div class="box">
                <div class="box-title">Acheteur / donneur d ordre</div>
                <table class="no-border">
                    <tr><td style="width:32%;"><strong>Societe</strong></td><td>BANAMUR / FIDESTCI</td></tr>
                    <tr><td><strong>Demandeur</strong></td><td>' . bci_h($display['demandeur'] ?? ($request['requester_name'] ?? '')) . '</td></tr>
                    <tr><td><strong>Service</strong></td><td>' . bci_h($payload['departement_label'] ?? '') . '</td></tr>
                    <tr><td><strong>Livraison</strong></td><td>' . bci_h(bci_date($payload['date_livraison'] ?? '')) . '</td></tr>
                </table>
            </div>
        </td>
        <td style="width:50%; border:none; padding-left:5px;">
            <div class="box">
                <div class="box-title">Fournisseur commercial renseigné</div>
                <table class="no-border">
                    <tr><td style="width:32%;"><strong>Nom</strong></td><td>' . bci_h($supplier['nom']) . '</td></tr>
                    <tr><td><strong>Telephone</strong></td><td>' . bci_h($supplier['telephone'] ?: '-') . '</td></tr>
                    <tr><td><strong>Email</strong></td><td>' . bci_h($supplier['email'] ?: '-') . '</td></tr>
                    <tr><td><strong>Adresse</strong></td><td>' . bci_h($supplier['adresse'] ?: '-') . '</td></tr>
                </table>
            </div>
        </td>
    </tr>
</table>

<div class="section box">
    <div class="box-title">' . bci_h($payload['feb_quote_title'] ?? 'Objet de la commande') . '</div>
    ' . nl2br(bci_h($display['description'] ?? ($payload['description'] ?? ''))) . '
</div>

<div class="section">
    <table>
        <thead>
            <tr>
                <th style="width:7%;" class="center">N</th>
                <th style="width:14%;">Catégorie</th>
                <th style="width:27%;">Designation</th>
                <th style="width:12%;">Type</th>
                <th style="width:10%;" class="center">Qte</th>
                <th style="width:14%;" class="right">Prix unit.</th>
                <th style="width:15%;" class="right">Total</th>
            </tr>
        </thead>
        <tbody>' . $rowsHtml . '</tbody>
        <tfoot>
            <tr><td colspan="6" class="total-label">Sous-total</td><td class="total-value">' . bci_h(number_format($subtotal, 0, ',', ' ')) . '</td></tr>
            <tr><td colspan="6" class="total-label">TVA / taxes</td><td class="total-value">' . bci_h(number_format($vat, 0, ',', ' ')) . '</td></tr>
            ' . (abs($validationAdjustment) >= 0.01 ? '<tr><td colspan="6" class="total-label">Ajustement validation FEB</td><td class="total-value">' . bci_h(number_format($validationAdjustment, 0, ',', ' ')) . '</td></tr>' : '') . '
            <tr><td colspan="6" class="total-label">TOTAL COMMANDE</td><td class="total-value">' . bci_h(number_format($grandTotal, 0, ',', ' ')) . ' FCFA</td></tr>
        </tfoot>
    </table>
</div>

<div class="section box">
    <div class="box-title">Conditions / observations</div>
    <strong>Traitement financier :</strong> ' . bci_h($display['cashflow_need_label'] ?? 'Non precise') . '<br>
    <strong>Montant demandé initialement :</strong> ' . bci_h(number_format($initialAmount, 0, ',', ' ')) . ' FCFA<br>
    <strong>Montant retenu par les validateurs FEB :</strong> ' . bci_h(number_format($grandTotal, 0, ',', ' ')) . ' FCFA<br>
    <strong>Avance provisoire demandee :</strong> ' . bci_h(!empty($display['provisional_disbursement_amount']) ? number_format((float) $display['provisional_disbursement_amount'], 0, ',', ' ') . ' FCFA' : 'Non applicable') . '<br>
    ' . (!empty($display['cashflow_comment'] ?? '') ? ('<strong>Precision comptable :</strong> ' . bci_h($display['cashflow_comment']) . '<br>') : '') . '
    ' . ($invoiceDocument ? ('<strong>Facture jointe :</strong> ' . bci_h((string) ($invoiceDocument['name'] ?? 'Facture')) . '<br>') : '') . '
    Document interne genere apres validation de la FEB. Les lignes et montants proviennent des besoins et fournisseurs renseignes dans la demande. Nombre de justificatifs joints : ' . count($files) . '.
</div>

<table class="section sign">
    <tr>
        <td style="width:33%;">Etabli par</td>
        <td style="width:34%;">Validation interne</td>
        <td style="width:33%;">Réception bénéficiaire : ' . bci_h($recipient['nom'] ?: '-') . '</td>
    </tr>
</table>';

class FebInternalOrderPdf extends TCPDF {
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}
$pdf = new FebInternalOrderPdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->companyPdfTitle = 'Bon de commande interne';
$pdf->companyPdfType = 'BCI issu de FEB';
$pdf->companyPdfId = $bciNumber;
$pdf->companyPdfCode = 'FIN-FEB-BCI';
$pdf->companyPdfQrPayload = json_encode(['type' => 'bci', 'number' => $bciNumber, 'feb_id' => $requestId, 'status' => $statusLabel], JSON_UNESCAPED_UNICODE);
$pdf->SetCreator('FINEA');
$pdf->SetAuthor('BANAMUR / FIDESTCI');
$pdf->SetTitle('Bon de commande interne ' . $bciNumber);
$pdf->SetMargins(10, 38, 10);
$pdf->SetAutoPageBreak(true, 25);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->AddPage();
$pdf->writeHTML($html, true, false, true, false, '');

$fileName = 'BCI_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $bciNumber) . '.pdf';
$pdf->Output($fileName, 'I');
exit;
