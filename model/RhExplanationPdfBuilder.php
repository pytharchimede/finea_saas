<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!class_exists('RhExplanationPdfDocument')) {
    class RhExplanationPdfDocument extends \TCPDF
    {
        public string $companyName = 'BANAMUR';
        public string $documentTitle = 'Demande d\'explications';
        public string $logoPath = '';

        public function Header(): void
        {
            $this->SetFillColor(255, 255, 255);
            $this->Rect(0, 0, $this->getPageWidth(), 28, 'F');
            $this->SetDrawColor(203, 213, 225);
            $this->Line(12, 24.5, $this->getPageWidth() - 12, 24.5);
            $this->SetFillColor(181, 146, 74);
            $this->Rect(12, 24.5, 34, 1.1, 'F');

            $this->SetY(6.8);
            if ($this->logoPath !== '' && is_file($this->logoPath)) {
                $this->Image($this->logoPath, 12, 5.8, 15, 0, '', '', '', false, 300);
            }

            $this->SetXY(31, 7.4);
            $this->SetFont('dejavusans', 'B', 15.5);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(0, 5.8, $this->companyName, 0, 1, 'L');
            $this->SetX(31);
            $this->SetFont('dejavusans', '', 8.5);
            $this->SetTextColor(71, 85, 105);
            $this->Cell(0, 4.4, 'Direction des ressources humaines • Document officiel', 0, 1, 'L');

            $this->SetXY($this->getPageWidth() - 80, 8.4);
            $this->SetFont('dejavusans', 'B', 8.6);
            $this->SetTextColor(51, 65, 85);
            $this->Cell(68, 4.5, $this->documentTitle, 0, 1, 'R');
            $this->SetX($this->getPageWidth() - 80);
            $this->SetFont('dejavusans', '', 7.6);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(68, 4.3, 'Version consolidée du dossier', 0, 1, 'R');
        }

        public function Footer(): void
        {
            $this->SetY(-16);
            $this->SetDrawColor(214, 219, 228);
            $this->Line(12, $this->GetY(), $this->getPageWidth() - 12, $this->GetY());
            $this->Ln(2.5);
            $this->SetFont('dejavusans', '', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 5, 'BANAMUR • Dossier RH sécurisé • Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }
}

function rh_explanation_generate_pdf_document(array $request, array $messages, array $options = []): array
{
    $logoPath = __DIR__ . '/../logo/default_logo.jpg';
    $fileName = 'demande_explication_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($request['request_ref'] ?? ('req_' . (int) ($request['id'] ?? 0)))) . '.pdf';
    $statusLabel = trim((string) ($request['status_label'] ?? $request['computed_status'] ?? $request['status'] ?? ''));
    $requestDate = !empty($request['created_at']) ? date('d/m/Y H:i', strtotime((string) $request['created_at'])) : 'Non renseignée';
    $dueDate = !empty($request['due_at']) ? date('d/m/Y H:i', strtotime((string) $request['due_at'])) : 'Non renseignée';
    $employeeName = trim((string) ($request['target_name'] ?? 'Collaborateur concerné'));
    $employeeEmail = trim((string) ($request['target_email'] ?? ''));
    $serviceLabel = trim((string) ($request['target_service_label'] ?? ''));
    $functionLabel = trim((string) ($request['target_function_label'] ?? ''));
    $subject = trim((string) ($request['subject'] ?? 'Demande d\'explications'));
    $creatorName = trim((string) ($request['created_by_name'] ?? 'RH'));
    $rhSignaturePath = trim((string) ($request['rh_signature_abs_path'] ?? ''));
    $employeeSignaturePath = trim((string) ($request['employee_signature_abs_path'] ?? ''));
    $rhSignedAt = trim((string) ($request['rh_signed_at'] ?? ''));
    $employeeSignedAt = trim((string) ($request['employee_signed_at'] ?? ''));
    $dossierUrl = trim((string) ($request['pdf_url'] ?? $request['employee_view_url'] ?? ''));
    $qrPayload = implode("\n", array_filter([
        'BANAMUR - Dossier RH',
        'Reference: ' . (string) ($request['request_ref'] ?? ('#' . (int) ($request['id'] ?? 0))),
        'Collaborateur: ' . $employeeName,
        'Objet: ' . $subject,
        'Statut: ' . $statusLabel,
        $dossierUrl !== '' ? 'Lien: ' . $dossierUrl : '',
    ]));

    $pdf = new RhExplanationPdfDocument('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->companyName = 'BANAMUR';
    $pdf->documentTitle = 'Demande d\'explications professionnelle';
    $pdf->logoPath = $logoPath;
    $pdf->SetCreator('BANAMUR');
    $pdf->SetAuthor('BANAMUR RH');
    $pdf->SetTitle($subject);
    $pdf->SetSubject('Demande d\'explications');
    $pdf->SetMargins(12, 28, 12);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();

    $pageWidth = $pdf->getPageWidth() - 24;
    $usableBottomY = $pdf->getPageHeight() - 22;

    $ensureSpace = static function (\TCPDF $pdfDocument, float $neededHeight, float $bottomLimit): void {
        if (($pdfDocument->GetY() + $neededHeight) > $bottomLimit) {
            $pdfDocument->AddPage();
        }
    };

    $ensureSectionSpace = static function (\TCPDF $pdfDocument, float $titleHeight, float $firstBlockHeight, float $bottomLimit) use ($ensureSpace): void {
        $ensureSpace($pdfDocument, $titleHeight + $firstBlockHeight, $bottomLimit);
    };

    $writeSmartHtmlBlock = static function (\TCPDF $pdfDocument, string $html, float $minimumHeight, float $bottomLimit) use ($ensureSpace): void {
        $ensureSpace($pdfDocument, $minimumHeight, $bottomLimit);

        $startPage = $pdfDocument->getPage();
        $startY = $pdfDocument->GetY();
        $pdfDocument->startTransaction();
        $pdfDocument->writeHTML($html, true, false, true, false, '');
        $endPage = $pdfDocument->getPage();

        if ($endPage > $startPage && $startY > 45) {
            $pdfDocument->rollbackTransaction(true);
            $pdfDocument->AddPage();
            $pdfDocument->writeHTML($html, true, false, true, false, '');
            return;
        }

        $pdfDocument->commitTransaction();
    };

    $heroY = 33;
    $heroHeight = 28;
    $heroWidth = $pageWidth - 37;
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect(12, $heroY, $heroWidth, $heroHeight, 4, '1111', 'DF');
    $pdf->SetFillColor(15, 118, 110);
    $pdf->RoundedRect(12, $heroY, 4, $heroHeight, 4, '1101', 'F');
    $pdf->SetXY(19, 37.5);
    $pdf->SetFont('dejavusans', 'B', 15.2);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell($heroWidth - 16, 5.8, 'Demande d\'explications', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9.3);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetX(19);
    $pdf->MultiCell($heroWidth - 18, 4.8, $subject !== '' ? $subject : 'Document RH officiel', 0, 'L', false, 1);
    $pdf->SetX(19);
    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->Cell($heroWidth - 18, 4.4, 'Référence : ' . (string) ($request['request_ref'] ?? ('#' . (int) ($request['id'] ?? 0))) . '   |   Statut : ' . $statusLabel, 0, 1, 'L');

    $qrBoxX = 12 + $heroWidth + 5;
    $qrBoxY = $heroY;
    $qrBoxWidth = 32;
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($qrBoxX, $qrBoxY, $qrBoxWidth, $heroHeight, 4, '1111', 'DF');
    $pdf->SetXY($qrBoxX + 3, $qrBoxY + 2.4);
    $pdf->SetFont('dejavusans', 'B', 7.2);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell($qrBoxWidth - 6, 4, 'QR DOSSIER', 0, 1, 'C');
    if ($qrPayload !== '') {
        $pdf->write2DBarcode($qrPayload, 'QRCODE,H', $qrBoxX + 6.1, $qrBoxY + 7.2, 19, 19, [], 'N');
    }

    $kpiY = 66;
    $gap = 4;
    $boxWidth = ($pageWidth - ($gap * 3)) / 4;
    $cards = [
        ['label' => 'Collaborateur', 'value' => $employeeName],
        ['label' => 'Émis le', 'value' => $requestDate],
        ['label' => 'Réponse attendue', 'value' => $dueDate],
        ['label' => 'Émis par', 'value' => $creatorName !== '' ? $creatorName : 'RH'],
    ];

    foreach ($cards as $index => $card) {
        $x = 12 + (($boxWidth + $gap) * $index);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($x, $kpiY, $boxWidth, 16, 3, '1111', 'DF');
        $pdf->SetXY($x + 3.2, $kpiY + 2.2);
        $pdf->SetFont('dejavusans', '', 7);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($boxWidth - 6.4, 3.8, $card['label'], 0, 2, 'L');
        $pdf->SetX($x + 3.2);
        $pdf->SetFont('dejavusans', 'B', 8.8);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->MultiCell($boxWidth - 6.4, 4.2, $card['value'], 0, 'L', false, 0);
    }

    $pdf->SetY(88);
    $ensureSpace($pdf, 18, $usableBottomY);
    $detailHtml = '<table cellpadding="5" cellspacing="0" style="width:100%;border-collapse:collapse;">'
        . '<tr>'
        . '<td style="width:24%;background-color:#f8fafc;border:1px solid #e2e8f0;font-weight:bold;">Email employé</td>'
        . '<td style="width:26%;border:1px solid #e2e8f0;">' . htmlspecialchars($employeeEmail !== '' ? $employeeEmail : 'Non renseigné', ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="width:24%;background-color:#f8fafc;border:1px solid #e2e8f0;font-weight:bold;">Service / fonction</td>'
        . '<td style="width:26%;border:1px solid #e2e8f0;">' . htmlspecialchars(trim($serviceLabel . ($functionLabel !== '' ? ' • ' . $functionLabel : '')) ?: 'Non renseigné', ENT_QUOTES, 'UTF-8') . '</td>'
        . '</tr>'
        . '</table>';
    $pdf->writeHTML($detailHtml, true, false, true, false, '');

    $pdf->Ln(3);
    $ensureSectionSpace($pdf, 10, 42, $usableBottomY);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 6, 'Contenu de la demande', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9.4);
    $pdf->SetTextColor(30, 41, 59);
    $requestBodyHtml = '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">'
        . '<tr>'
        . '<td style="width:2%;background-color:#0f766e;border-radius:8px 0 0 8px;">&nbsp;</td>'
        . '<td style="width:2%;">&nbsp;</td>'
        . '<td style="width:96%;">'
        . '<table cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;">'
        . '<tr><td style="border:1px solid #dbe4f0;border-radius:0 12px 12px 0;background-color:#f8fafc;color:#0f172a;line-height:1.55;">' . (string) ($request['request_html'] ?? '') . '</td></tr>'
        . '</table>'
        . '</td>'
        . '</tr>'
        . '</table>';
    $writeSmartHtmlBlock(
        $pdf,
        $requestBodyHtml,
        42,
        $usableBottomY
    );

    if ($messages) {
        $pdf->Ln(3);
        $ensureSectionSpace($pdf, 10, 28, $usableBottomY);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 6, 'Historique du dossier', 0, 1, 'L');
        foreach ($messages as $message) {
            $role = (string) ($message['author_role'] ?? 'rh');
            $title = trim((string) ($message['author_name'] ?? ($role === 'employee' ? 'Employé' : 'RH')));
            $kind = trim((string) ($message['message_kind'] ?? 'message'));
            $createdAt = !empty($message['created_at']) ? date('d/m/Y H:i', strtotime((string) $message['created_at'])) : 'Date non renseignée';
            $fill = $role === 'employee' ? [248, 250, 252] : [248, 250, 252];
            $accent = $role === 'employee' ? '#475569' : '#0f766e';
            $html = '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;">'
                . '<tr>'
                . '<td style="width:2%;background-color:' . $accent . ';border-radius:8px 0 0 8px;">&nbsp;</td>'
                . '<td style="width:2%;">&nbsp;</td>'
                . '<td style="width:96%;">'
                . '<table cellpadding="7" cellspacing="0" style="width:100%;border-collapse:collapse;">'
                . '<tr><td style="border:1px solid #e2e8f0;border-radius:0 10px 10px 0;background-color:rgb(' . $fill[0] . ',' . $fill[1] . ',' . $fill[2] . ');">'
                . '<div style="font-weight:bold;color:' . $accent . ';font-size:10px;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' • ' . htmlspecialchars(strtoupper($kind), ENT_QUOTES, 'UTF-8') . '</div>'
                . '<div style="font-size:8.7px;color:#64748b;">' . htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') . '</div>'
                . '<div style="margin-top:6px;font-size:9px;color:#0f172a;line-height:1.5;">' . (string) ($message['body_html'] ?? '') . '</div>'
                . '</td></tr>'
                . '</table>'
                . '</td>'
                . '</tr>'
                . '</table>';
            $writeSmartHtmlBlock($pdf, $html, 28, $usableBottomY);
            $pdf->Ln(1);
        }
    }

    $ensureSpace($pdf, 86, $usableBottomY);

    $pdf->Ln(5);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(0, 6, 'Signatures et visas', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 8.8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->MultiCell(0, 4.6, 'Les signatures manuscrites enregistrées dans l’application sont intégrées ci-dessous. Les visas RH et DG matérialisent le circuit de validation.', 0, 'L', false, 1);
    $pdf->Ln(2);

    $boxY = $pdf->GetY();
    $signatureWidth = ($pageWidth - 8) / 2;
    $signatureBoxes = [
        [
            'x' => 12,
            'title' => 'Signature RH',
            'name' => $creatorName !== '' ? $creatorName : 'Responsable RH',
            'signed_at' => $rhSignedAt,
            'image_path' => $rhSignaturePath,
            'pending' => 'Signature RH à enregistrer dans l’application',
        ],
        [
            'x' => 12 + $signatureWidth + 8,
            'title' => 'Signature employé',
            'name' => $employeeName,
            'signed_at' => $employeeSignedAt,
            'image_path' => $employeeSignaturePath,
            'pending' => 'Signature employé à enregistrer dans l’application',
        ],
    ];

    foreach ($signatureBoxes as $signatureBox) {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->RoundedRect($signatureBox['x'], $boxY, $signatureWidth, 46, 3, '1111', 'DF');
        $pdf->SetXY($signatureBox['x'] + 4, $boxY + 4);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell($signatureWidth - 8, 5, $signatureBox['title'], 0, 1, 'L');
        $pdf->SetX($signatureBox['x'] + 4);
        $pdf->SetFont('dejavusans', '', 8.6);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell($signatureWidth - 8, 4.5, 'Nom : ' . $signatureBox['name'], 0, 1, 'L');
        $pdf->SetX($signatureBox['x'] + 4);
        $pdf->Cell($signatureWidth - 8, 4.5, 'Date : ' . ($signatureBox['signed_at'] !== '' ? date('d/m/Y H:i', strtotime($signatureBox['signed_at'])) : 'En attente'), 0, 1, 'L');

        $imagePath = (string) $signatureBox['image_path'];
        if ($imagePath !== '' && is_file($imagePath)) {
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->RoundedRect($signatureBox['x'] + 4, $boxY + 18, $signatureWidth - 8, 18, 2, '1111', 'D');
            $pdf->Image($imagePath, $signatureBox['x'] + 6, $boxY + 20, $signatureWidth - 12, 12, '', '', '', false, 300, '', false, false, 0, false, false, false);
            $pdf->SetXY($signatureBox['x'] + 4, $boxY + 37);
            $pdf->SetFont('dejavusans', '', 7.7);
            $pdf->SetTextColor(15, 118, 110);
            $pdf->Cell($signatureWidth - 8, 4, 'Signature manuscrite intégrée', 0, 1, 'C');
        } else {
            $pdf->SetXY($signatureBox['x'] + 5, $boxY + 22);
            $pdf->SetFont('dejavusans', 'I', 8.2);
            $pdf->SetTextColor(148, 163, 184);
            $pdf->MultiCell($signatureWidth - 10, 4.4, $signatureBox['pending'], 0, 'C', false, 1);
            $pdf->Line($signatureBox['x'] + 6, $boxY + 35, $signatureBox['x'] + $signatureWidth - 6, $boxY + 35);
        }
    }

    $visaY = $boxY + 52;
    $visaWidth = ($pageWidth - 8) / 2;
    $visaBoxes = [
        [
            'x' => 12,
            'title' => 'Visa RH',
            'status' => $rhSignedAt !== '' ? 'Visé le ' . date('d/m/Y H:i', strtotime($rhSignedAt)) : 'Visa RH en attente',
            'detail' => $creatorName !== '' ? $creatorName : 'Responsable RH',
            'fill' => [236, 253, 245],
            'text' => [4, 120, 87],
        ],
        [
            'x' => 12 + $visaWidth + 8,
            'title' => 'Visa DG',
            'status' => (int) ($request['dg_user_id'] ?? 0) > 0 ? 'Emplacement réservé à la Direction Générale' : 'Non requis pour ce dossier',
            'detail' => (int) ($request['dg_user_id'] ?? 0) > 0 ? 'Validation DG selon circuit hiérarchique' : 'Circuit RH standard',
            'fill' => [255, 247, 237],
            'text' => [180, 83, 9],
        ],
    ];

    foreach ($visaBoxes as $visaBox) {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($visaBox['x'], $visaY, $visaWidth, 20, 3, '1111', 'DF');
        $pdf->SetXY($visaBox['x'] + 4, $visaY + 3.5);
        $pdf->SetFont('dejavusans', 'B', 9.5);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell($visaWidth - 8, 4.2, $visaBox['title'], 0, 1, 'L');
        $pdf->SetX($visaBox['x'] + 4);
        $pdf->SetFont('dejavusans', 'B', 8.4);
        $pdf->SetTextColor($visaBox['text'][0], $visaBox['text'][1], $visaBox['text'][2]);
        $pdf->Cell($visaWidth - 8, 4.2, $visaBox['status'], 0, 1, 'L');
        $pdf->SetX($visaBox['x'] + 4);
        $pdf->SetFont('dejavusans', '', 7.8);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->Cell($visaWidth - 8, 4, $visaBox['detail'], 0, 1, 'L');
    }

    return [
        'fileName' => $fileName,
        'content' => $pdf->Output($fileName, 'S'),
    ];
}
