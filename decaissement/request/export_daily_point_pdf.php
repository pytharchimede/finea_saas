<?php
$dailyPointCronExport = defined('DAILY_POINT_CRON_EXPORT') && DAILY_POINT_CRON_EXPORT;
if (!$dailyPointCronExport) require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../../services/DailyDisbursementPointService.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$pdo = isset($pdo) && $pdo instanceof PDO ? $pdo : Database::getConnection();
$service = new DailyDisbursementPointService($pdo);
$point = $service->find((int)($_GET['id'] ?? 0));
if (!$point) {
    http_response_code(404);
    exit('Point introuvable.');
}
$dailyByFiche = [];
foreach ((array)($point['daily_items'] ?? []) as $dailyItem) if (($dailyItem['source_type'] ?? '') === 'current_point') $dailyByFiche[(string)$dailyItem['fiche_number']] = $dailyItem;
$documentItems = [];
foreach ((array)($point['items'] ?? []) as $pointItem) {
    $number = (string)($pointItem['fiche_number'] ?? '');
    $documentItems[] = array_merge($pointItem, (array)($dailyByFiche[$number] ?? []), ['fiche_number' => $number, 'amount' => (float)($pointItem['amount'] ?? 0), 'beneficiary' => (string)($pointItem['beneficiary'] ?? ''), 'designation' => (string)($pointItem['designation'] ?? ''), 'chantier_code' => (string)($pointItem['chantier_code'] ?? ''), 'chantier_title' => (string)($pointItem['chantier_title'] ?? ''), 'precision_text' => (string)($pointItem['precision_text'] ?? ''), 'source_label' => 'Point V' . (int)$point['version_no'], 'payment_status' => (string)(($dailyByFiche[$number]['payment_status'] ?? 'to_pay'))]);
}
$ficheDetails = [];
$ficheNumbers = array_values(array_unique(array_filter(array_map(static fn(array $item): string => trim((string)($item['fiche_number'] ?? '')), $documentItems))));
if ($ficheNumbers) {
    $marks = implode(',', array_fill(0, count($ficheNumbers), '?'));
    $detailStmt = $pdo->prepare("SELECT f.num_fiche,COALESCE(NULLIF(TRIM(f.details_demande),''),NULLIF(TRIM(parent.details_demande),'')) details_demande,f.precision_fiche,f.designation_fiche,f.ligne_debourse_id,c.code chantier_code,c.titre chantier_titre,ldb.designation ligne_debourse_designation,ldb.categorie ligne_debourse_categorie,ldb.montant ligne_debourse_montant,db.id debourse_id,db.montant_debourse,ld.designation ligne_devis_designation FROM fiche_decaissement f LEFT JOIN fiche_decaissement parent ON parent.num_fiche=COALESCE(NULLIF(f.parent_num_fiche,''),NULLIF(f.code_parentale,'')) LEFT JOIN chantier_banamur c ON c.id=f.chantier_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id WHERE f.num_fiche IN ($marks)");
    $detailStmt->execute($ficheNumbers);
    foreach ($detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $detailRow) $ficheDetails[(string)$detailRow['num_fiche']] = $detailRow;
}
$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$dailyPointCronExport) {
    $all = AccessManager::hasPermission('acces_admin') || AccessManager::hasPermission('acces_finance') || AccessManager::hasPermission('acces_achat');
    if (!$service->canView($point, $uid, $all)) {
        http_response_code(403);
        exit('Accès refusé.');
    }
}
$directorName = 'Paul-Alex BRAUD';
try {
    $s = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(d.prenom,u.prenom,''),' ',COALESCE(d.nom,u.nom,''))) FROM daily_disbursement_point_authorizations a LEFT JOIN user_devis_banamur u ON u.id=a.user_id LEFT JOIN user_devis_banamur d ON d.id=a.delegator_user_id WHERE a.point_id=:p AND a.role_code='dg' ORDER BY (a.revoked_at IS NULL) DESC,a.id DESC LIMIT 1");
    $s->execute(['p' => $point['id']]);
    $configured = trim((string)$s->fetchColumn());
    if ($configured !== '') $directorName = $configured;
} catch (Throwable $e) {
}

class DailyPointIsoPdf extends TCPDF
{
    use CompanyPdfTemplate;
    public bool $provisional = false;
    public function Header(): void
    {
        $this->companyPdfType = $this->provisional ? 'Point provisoire' : 'Bon pour paiement';
        $this->drawCompanyPdfHeader();
        if ($this->provisional) {
            $this->SetAlpha(.10);
            $this->SetTextColor(220, 38, 38);
            $this->SetFont('dejavusans', 'B', 34);
            $pageWidth = $this->getPageWidth();
            $this->StartTransform();
            $this->Rotate(36, $pageWidth / 2, 145);
            $this->SetXY(20, 130);
            $this->Cell($pageWidth - 40, 18, 'POINT PROVISOIRE', 0, 0, 'C');
            $this->StopTransform();
            $this->SetAlpha(1);
            $this->SetTextColor(0);
        }
    }
    public function Footer(): void
    {
        $this->drawCompanyPdfFooter();
    }
}

$provisional = empty($point['export_allowed']);
$money = static fn($v) => number_format((float)$v, 0, ',', ' ') . ' FCFA';
$pdf = new DailyPointIsoPdf('L', 'mm', 'A4', true, 'UTF-8', false);
$pdf->provisional = $provisional;
$pdf->companyPdfTitle = 'Point quotidien de décaissement';
$pdf->companyPdfId = 'FIN-DEC-' . $point['point_date'] . '-V' . $point['version_no'];
$pdf->companyPdfCode = 'FIN-DEC-POINT';
$pdf->companyPdfQrPayload = json_encode(['point_id' => (int)$point['id'], 'date' => $point['point_date'], 'version' => (int)$point['version_no'], 'status' => $provisional ? 'provisional' : 'bon_pour_paiement', 'certified_at' => $point['certified_at']], JSON_UNESCAPED_UNICODE);
$pdf->SetCreator('FINEA');
$pdf->SetTitle('Point de décaissement ' . $point['point_date']);
$pdf->SetMargins(14, 38, 14);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();
$cardWidth = ($pdf->getPageWidth() - 28 - 9) / 4;

$drawCard = static function (TCPDF $pdf, float $x, string $icon, string $label, string $value, array $accent) use ($cardWidth): void {
    $y = 40;
    $w = $cardWidth;
    $h = 19;
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($x, $y, $w, $h, 3, '1111', 'DF');
    $pdf->SetFillColor(...$accent);
    $pdf->RoundedRect($x, $y, 2.4, $h, 1, '1001', 'F');

    // Zone visuelle large, centrée verticalement et horizontalement dans la colonne gauche.
    $cx = $x + 9.6;
    $cy = $y + ($h / 2);
    $iconBox = 11.5;
    $pdf->RoundedRect($cx - ($iconBox / 2), $cy - ($iconBox / 2), $iconBox, $iconBox, 2.8, '1111', 'F');
    $pdf->SetDrawColor(255, 255, 255);
    $pdf->SetLineWidth(.8);
    if ($icon === 'requested') {
        $pdf->RoundedRect($cx - 2.5, $cy - 3.45, 5, 6.9, .7, '1111', 'D');
        $pdf->Line($cx - 1.45, $cy - 1.55, $cx + 1.45, $cy - 1.55);
        $pdf->Line($cx - 1.45, $cy, $cx + 1.45, $cy);
        $pdf->Line($cx - 1.45, $cy + 1.55, $cx + .75, $cy + 1.55);
    } elseif ($icon === 'paid') {
        $pdf->SetLineWidth(1.15);
        $pdf->Line($cx - 3, $cy - .15, $cx - .75, $cy + 2.1);
        $pdf->Line($cx - .75, $cy + 2.1, $cx + 3.3, $cy - 2.7);
    } elseif ($icon === 'pending') {
        $clockSize = 5.8;
        $clockRadius = $clockSize / 2;
        $pdf->Ellipse($cx, $cy, $clockRadius, $clockRadius, 0, 0, 360, 'D');
        $pdf->Line($cx, $cy, $cx, $cy - 1.85);
        $pdf->Line($cx, $cy, $cx + 1.65, $cy + .9);
    } else {
        $pdf->RoundedRect($cx - 3, $cy - 3, 6, 6, .65, '1111', 'D');
        $pdf->Line($cx - 1.8, $cy - 1.35, $cx + 1.8, $cy - 1.35);
        $pdf->Line($cx - 1.8, $cy + .15, $cx + 1.8, $cy + .15);
        $pdf->Line($cx - 1.8, $cy + 1.65, $cx + .75, $cy + 1.65);
    }

    $textX = $x + 17.2;
    $textW = $w - 19.2;
    $pdf->SetXY($textX, $y + 3.2);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetFont('dejavusans', 'B', 6.3);
    $pdf->Cell($textW, 4, mb_strtoupper($label), 0, 1, 'L', false, '', 1);
    $pdf->SetX($textX);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('dejavusans', 'B', 8.4);
    $pdf->MultiCell($textW, 8, $value, 0, 'L', false, 1, '', '', true, 0, false, true, 8, 'M');
};
$drawCard($pdf, 14, 'requested', 'Montant du point', $money($point['total_amount'] ?? 0), [79, 70, 229]);
$drawCard($pdf, 14 + $cardWidth + 3, 'paid', 'Déjà payé sur ce point', $money($point['point_totals']['paid'] ?? 0), [16, 185, 129]);
$drawCard($pdf, 14 + 2 * ($cardWidth + 3), 'pending', 'À payer sur ce point', $money($point['point_totals']['to_pay'] ?? 0), [245, 158, 11]);
$drawCard($pdf, 14 + 3 * ($cardWidth + 3), 'point', 'Point consulté', 'V' . (int)$point['version_no'] . ' · ' . $money($point['total_amount']), $provisional ? [239, 68, 68] : [14, 165, 233]);
$pdf->SetY(64);
$status = $provisional ? 'POINT PROVISOIRE — NON PRÊT POUR DÉCAISSEMENT' : 'BON POUR PAIEMENT';
$statusColor = $provisional ? '#991b1b' : '#047857';
$statusBg = $provisional ? '#fef2f2' : '#ecfdf5';
$statusBorder = $provisional ? '#fecaca' : '#a7f3d0';
$html = '<table cellpadding="7" cellspacing="0" style="border:1px solid ' . $statusBorder . ';background-color:' . $statusBg . '"><tr><td width="68%" style="color:' . $statusColor . '"><b>' . $status . '</b><br><span style="font-size:8px">' . ($provisional ? 'Visa électronique du Directeur Général attendu.' : ((string)$point['status'] === 'paid' ? 'Visa acquis et décaissements de ce point exécutés.' : 'Visa acquis · décaissement manuel de ce point attendu.')) . '</span></td><td width="32%" align="right" style="color:' . $statusColor . '"><b>DG</b><br>' . htmlspecialchars($directorName) . '</td></tr></table>';
if ($provisional) $html .= '<div style="margin-top:7px;border:1px solid #fecaca;background-color:#fff7f7;color:#991b1b;padding:8px"><b>DOCUMENT PROVISOIRE.</b> Ce point n’autorise aucun paiement tant que le Directeur Général (' . htmlspecialchars($directorName) . ') ou son mandataire ne l’a pas signé.</div>';
$html .= '<br><h3 style="color:#172554">Fiches du point #' . (int)$point['id'] . ' soumises à la signature du Directeur Général</h3><table cellpadding="5" cellspacing="0" style="border:1px solid #cbd5e1;font-size:7.5px"><thead><tr style="background-color:#172554;color:#ffffff"><th width="14%"><b>FICHE / MONTANT</b></th><th width="15%"><b>BÉNÉFICIAIRE</b></th><th width="18%"><b>CHANTIER</b></th><th width="34%"><b>IMPUTATION BUDGÉTAIRE</b></th><th width="13%"><b>DÉTAIL</b></th><th width="6%" align="center"><b>ÉTAT</b></th></tr></thead><tbody>';
foreach ($documentItems as $index => $item) {
    $paid = ($item['payment_status'] ?? '') === 'paid';
    $ficheNumber = (string)$item['fiche_number'];
    $detail = (array)($ficheDetails[$ficheNumber] ?? []);
    $snapshot = json_decode((string)($item['snapshot_json'] ?? ''), true) ?: [];
    $value = static fn(string $key, string $fallback = '') => trim((string)($detail[$key] ?? $snapshot[$key] ?? $fallback));
    $chantier = trim($value('chantier_code', (string)($item['chantier_code'] ?? '')) . ' · ' . $value('chantier_titre', (string)($item['chantier_title'] ?? '')), ' ·');
    $ligneDevis = $value('ligne_devis_designation');
    $debourseAmount = (float)($detail['montant_debourse'] ?? $snapshot['debourse_montant'] ?? 0);
    $debourseLabel = $ligneDevis !== '' ? $ligneDevis : $value('designation_fiche', (string)($item['designation'] ?? ''));
    $ligneDebourse = $value('ligne_debourse_designation', $value('precision_fiche', (string)($item['precision_text'] ?? '')));
    $ligneCategory = $value('ligne_debourse_categorie');
    $budget = '<b>Ligne de devis :</b> ' . htmlspecialchars($ligneDevis !== '' ? $ligneDevis : 'Non renseignée') . '<br><b>Déboursé :</b> ' . htmlspecialchars($debourseLabel !== '' ? $debourseLabel . ($debourseAmount > 0 ? ' · ' . $money($debourseAmount) : '') : 'Non renseigné') . '<br><b>Ligne de déboursé :</b> ' . htmlspecialchars(trim(($ligneCategory !== '' ? $ligneCategory . ' · ' : '') . ($ligneDebourse !== '' ? $ligneDebourse : 'Non renseignée')));
    $requestDetail = $value('details_demande');
    $precision = $value('precision_fiche', (string)($item['precision_text'] ?? ''));
    $detailText = '<b>Objet :</b> ' . htmlspecialchars($value('designation_fiche', (string)($item['designation'] ?? ''))) . '<br><b>Détail saisi par le demandeur :</b> ' . htmlspecialchars($requestDetail !== '' ? $requestDetail : 'Non renseigné') . ($precision !== '' && $precision !== $ligneDebourse ? '<br><b>Précision :</b> ' . htmlspecialchars($precision) : '');
    if (!empty($item['next_disbursement_date'])) $detailText .= '<br><b>Reliquat :</b> ' . date('d/m/Y', strtotime((string)$item['next_disbursement_date']));
    $fill = $paid ? '#ecfdf5' : '#fffbeb';
    $state = $paid ? 'PAYÉ' : 'À PAYER';
    $stateColor = $paid ? '#047857' : '#b45309';
    $html .= '<tr style="background-color:' . $fill . ';color:#334155"><td width="14%" style="border-bottom:1px solid #e2e8f0"><b>' . htmlspecialchars($ficheNumber) . '</b><br>' . htmlspecialchars((string)$item['source_label']) . '<br><b>' . $money($item['amount']) . '</b></td><td width="15%" style="border-bottom:1px solid #e2e8f0">' . htmlspecialchars((string)$item['beneficiary']) . '</td><td width="18%" style="border-bottom:1px solid #e2e8f0">' . htmlspecialchars($chantier !== '' ? $chantier : 'Sans chantier') . '</td><td width="34%" style="border-bottom:1px solid #e2e8f0">' . $budget . '</td><td width="13%" style="border-bottom:1px solid #e2e8f0">' . $detailText . '</td><td width="6%" align="center" style="border-bottom:1px solid #e2e8f0;color:' . $stateColor . '"><b>' . $state . '</b></td></tr>';
}
$html .= '</tbody></table><br><h3 style="color:#172554">' . ($provisional ? 'Visa électronique attendu' : 'Visa électronique du Directeur Général') . '</h3>';
if ($provisional) {
    $html .= '<table cellpadding="7" style="border:1px dashed #fca5a5;background-color:#fff7f7"><tr><td align="center" style="color:#991b1b"><b>Signature de ' . htmlspecialchars($directorName) . ' en attente</b></td></tr></table>';
} else {
    $signatureTempFiles = [];
    $signatureDataFor = static function (int $userId) use ($pdo): string {
        if ($userId <= 0) return '';
        $s = $pdo->prepare('SELECT signature_data FROM user_devis_banamur WHERE id=:id LIMIT 1');
        $s->execute(['id' => $userId]);
        return trim((string)$s->fetchColumn());
    };
    $visaCell = static function (string $role, string $name, string $date, string $path, string $data = '') use (&$signatureTempFiles): string {
        $label = '<b>' . htmlspecialchars($role) . '</b><br>' . htmlspecialchars($name) . '<br><small>' . htmlspecialchars($date) . '</small>';
        $abs = $path !== '' ? realpath(dirname(__DIR__, 2) . '/' . ltrim($path, '/')) : false;
        if (!$abs || !is_readable($abs) || @getimagesize($abs) === false) {
            $raw = '';
            if (preg_match('#^data:image/(?:png|jpeg);base64,(.+)$#s', $data, $m)) $raw = (string)base64_decode($m[1], true);
            if ($raw !== '' && @getimagesizefromstring($raw) !== false) {
                $abs = tempnam(sys_get_temp_dir(), 'finea_sig_');
                if ($abs !== false && file_put_contents($abs, $raw, LOCK_EX) !== false) {
                    chmod($abs, 0600);
                    $signatureTempFiles[] = $abs;
                } else $abs = false;
            }
        }
        if ($abs && is_readable($abs) && @getimagesize($abs) !== false) $label .= '<br><img src="' . $abs . '" height="30">';
        return '<td width="33.33%" align="center">' . $label . '</td>';
    };
    $dg = (array)($point['signatures'][0] ?? []);
    $html .= '<table cellpadding="7" cellspacing="0" style="border:1px solid #a7f3d0;background-color:#f0fdf4"><tr>' . $visaCell('ARRÊT DU POINT', (string)$point['stopped_by_name'], date('d/m/Y H:i', strtotime((string)$point['stopped_at'])), (string)($point['stopped_by_signature_path'] ?? ''), $signatureDataFor((int)($point['stopped_by_user_id'] ?? 0))) . $visaCell('DIRECTEUR GÉNÉRAL' . (!empty($dg['delegator_user_id']) ? ' — PAR ORDRE' : ''), (string)($dg['signer_name'] ?? $directorName), !empty($dg['signed_at']) ? date('d/m/Y H:i', strtotime((string)$dg['signed_at'])) : 'En attente', (string)($dg['signature_path'] ?? ''), $signatureDataFor((int)($dg['user_id'] ?? 0))) . $visaCell('PAIEMENT EFFECTIF', (string)($point['paid_by_name'] ?? 'En attente'), !empty($point['paid_at']) ? date('d/m/Y H:i', strtotime((string)$point['paid_at'])) : 'En attente', (string)($point['paid_by_signature_path'] ?? ''), $signatureDataFor((int)($point['paid_by_user_id'] ?? 0))) . '</tr></table>';
}
$html .= '<p style="font-size:8px;color:#64748b">Intégrité : point #' . (int)$point['id'] . ' · arrêté le ' . htmlspecialchars((string)$point['stopped_at']) . ($provisional ? ' · signature du Directeur Général attendue.' : (' · signé le ' . htmlspecialchars((string)$point['certified_at']) . ((string)$point['status'] === 'paid' ? ' · décaissé le ' . htmlspecialchars((string)($point['paid_at'] ?? '')) : ' · paiement en attente') . '.')) . '</p>';
$pdf->writeHTML($html, true, false, true, false, '');
$dailyPointFileName = ($provisional ? 'point_provisoire_' : 'bon_pour_paiement_') . $point['point_date'] . '_v' . $point['version_no'] . '.pdf';
if ($dailyPointCronExport) {
    $beneficiaries = [];
    $chantiers = [];
    foreach ($documentItems as $item) {
        $beneficiary = trim((string)($item['beneficiary'] ?? ''));
        $chantier = trim((string)($item['chantier_code'] ?? ''));
        if ($beneficiary !== '') $beneficiaries[mb_strtolower($beneficiary)] = true;
        if ($chantier !== '') $chantiers[mb_strtolower($chantier)] = true;
    }
    $dailyPointPdfReport = ['content' => $pdf->Output('', 'S'), 'fileName' => $dailyPointFileName, 'rowCount' => count($documentItems), 'total' => (float)$point['total_amount'], 'paidTotal' => (float)($point['point_totals']['paid'] ?? 0), 'toPayTotal' => (float)($point['point_totals']['to_pay'] ?? 0), 'beneficiaryCount' => count($beneficiaries), 'chantierCount' => count($chantiers), 'statusMeta' => ['key' => (string)$point['status'], 'label' => (string)$point['status'] === 'paid' ? 'Point payé' : (!empty($point['export_allowed']) ? 'Bon pour paiement' : 'Point provisoire')], 'pointId' => (int)$point['id'], 'version' => (int)$point['version_no']];
    foreach ((array)($signatureTempFiles ?? []) as $temporarySignature) @unlink($temporarySignature);
    return;
}
$pdf->Output($dailyPointFileName, 'I');
foreach ((array)($signatureTempFiles ?? []) as $temporarySignature) @unlink($temporarySignature);
