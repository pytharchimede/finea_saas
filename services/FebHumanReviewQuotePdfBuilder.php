<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';

final class FebHumanReviewQuotePdfDocument extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}

final class FebHumanReviewQuotePdfBuilder
{
    public static function build(array $review): FebHumanReviewQuotePdfDocument
    {
        $id = (int)($review['id'] ?? 0);
        $decoded = json_decode((string)($review['lines_json'] ?? '[]'), true);
        $lines = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        $pdf = new FebHumanReviewQuotePdfDocument('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->companyPdfTitle = 'DEVIS SAISI DANS LA FEB';
        $pdf->companyPdfType = 'Synthèse des besoins';
        $pdf->companyPdfId = 'FEB-REV-' . $id;
        $pdf->companyPdfCode = 'FIN-FEB-DEV-01';
        $pdf->companyPdfQrPayload = 'https://projet.fidest.ci/validation_justificatifs_feb.php?review=' . $id;
        $pdf->SetCreator('FINEA');
        $pdf->SetAuthor('BANAMUR Industries & Technologies');
        $pdf->SetTitle('Devis saisi dans la FEB - validation ' . $id);
        $pdf->SetSubject('Synthèse documentaire pour validation du justificatif FEB');
        $pdf->SetMargins(12, 39, 12);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect(12, 40, 186, 29, 3, '1111', 'DF');
        $pdf->SetFillColor(250, 189, 2);
        $pdf->Rect(12, 40, 3, 29, 'F');
        $pdf->SetXY(20, 44);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(100, 5, 'SYNTHÈSE DES BESOINS SAISIS DANS FINEA', 0, 1);
        $pdf->SetX(20);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(130, 8, 'DEVIS INTERNE · FEB-REV-' . $id, 0, 1);
        $pdf->SetX(20);
        $pdf->SetFont('dejavusans', '', 7.6);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell(170, 5, 'Document généré le ' . self::dateLabel('now') . ' · Référence qualité FIN-FEB-DEV-01', 0, 1);

        self::section($pdf, '01', 'Contexte de la demande', 75);
        self::infoCard($pdf, 12, 83, 90, 'DEMANDEUR', (string)($review['requester_name'] ?? ''), 18);
        self::infoCard($pdf, 108, 83, 90, 'FOURNISSEUR DÉCLARÉ', (string)($review['supplier_label'] ?? ''), 18);
        self::infoCard($pdf, 12, 105, 90, 'DÉPOSÉ LE', self::dateLabel($review['created_at'] ?? ''), 18);
        self::infoCard($pdf, 108, 105, 90, 'PIÈCE JUSTIFICATIVE ASSOCIÉE', (string)($review['original_name'] ?? ''), 18);

        self::section($pdf, '02', 'Détail du devis saisi', 129);
        $pdf->SetY(138);
        self::tableHeader($pdf);
        $calculated = 0.0;
        foreach ($lines as $index => $line) {
            $qty = max(0.0, (float)($line['quantite'] ?? 0));
            $price = max(0.0, (float)($line['prix_unitaire'] ?? 0));
            $amount = $qty * $price;
            $calculated += $amount;
            self::tableRow($pdf, $index + 1, (string)($line['libelle'] ?? 'Besoin non détaillé'), (string)($line['type'] ?? ''), $qty, $price, $amount, $index % 2 === 1);
        }
        if (!$lines) {
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->MultiCell(186, 14, 'Aucune ligne de besoin transmise.', 1, 'C', true, 1, 12, $pdf->GetY(), true, 0, false, true, 14, 'M');
        }

        self::ensureSpace($pdf, 47, false);
        $y = $pdf->GetY() + 5;
        $pdf->SetFillColor(15, 23, 42);
        $pdf->RoundedRect(12, $y, 186, 19, 3, '1111', 'F');
        $pdf->SetXY(19, $y + 5);
        $pdf->SetTextColor(203, 213, 225);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->Cell(80, 7, 'TOTAL DÉCLARÉ DANS LA DEMANDE', 0, 0);
        $pdf->SetTextColor(250, 189, 2);
        $pdf->SetFont('dejavusans', 'B', 15);
        $pdf->Cell(92, 8, self::money((float)($review['expected_amount'] ?? 0)), 0, 1, 'R');
        $pdf->SetY($y + 24);
        if ($lines && abs($calculated - (float)($review['expected_amount'] ?? 0)) > 1) {
            $pdf->SetTextColor(180, 83, 9);
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->MultiCell(186, 7, 'À vérifier : le total calculé des lignes est ' . self::money($calculated) . '.', 0, 'L');
        }

        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFont('dejavusans', '', 7.4);
        $pdf->MultiCell(186, 10, 'Ce document restitue les données saisies dans FINEA au dépôt du justificatif. Il ne remplace pas le devis original émis par le fournisseur ; celui-ci reste consultable séparément dans le dossier FEB.', 0, 'L', false, 1, 12, $pdf->GetY() + 2);
        return $pdf;
    }

    public static function section(FebHumanReviewQuotePdfDocument $pdf, string $number, string $title, float $y): void
    {
        $pdf->SetFillColor(250, 189, 2);
        $pdf->RoundedRect(12, $y, 10, 7, 1.5, '1111', 'F');
        $pdf->SetXY(12, $y + 1);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(10, 5, $number, 0, 0, 'C');
        $pdf->SetXY(26, $y);
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(172, 7, $title, 0, 1);
    }

    public static function infoCard(FebHumanReviewQuotePdfDocument $pdf, float $x, float $y, float $width, string $label, string $value, float $height): void
    {
        $pdf->SetFillColor(255, 255, 255);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($x, $y, $width, $height, 2, '1111', 'DF');
        $pdf->SetXY($x + 4, $y + 2.3);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->SetFont('dejavusans', 'B', 6.7);
        $pdf->Cell($width - 8, 4, $label, 0, 1);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->MultiCell($width - 8, 9, $value !== '' ? $value : 'Non renseigné', 0, 'L', false, 1, $x + 4, $y + 6.3, true, 0, false, true, 9, 'M', true);
    }

    public static function tableHeader(FebHumanReviewQuotePdfDocument $pdf): void
    {
        $y = $pdf->GetY();
        $pdf->SetFillColor(15, 23, 42);
        $pdf->Rect(12, $y, 186, 10, 'F');
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('dejavusans', 'B', 7.2);
        $x = 12;
        foreach ([['N°', 11, 'C'], ['DÉSIGNATION', 77, 'L'], ['QTÉ', 20, 'R'], ['PRIX UNITAIRE', 35, 'R'], ['MONTANT', 43, 'R']] as [$label, $width, $align]) {
            $pdf->SetXY($x + 2, $y + 2.3);
            $pdf->Cell($width - 4, 5, $label, 0, 0, $align);
            $x += $width;
        }
        $pdf->SetY($y + 10);
    }

    public static function tableRow(FebHumanReviewQuotePdfDocument $pdf, int $index, string $description, string $type, float $quantity, float $unitPrice, float $amount, bool $shade): void
    {
        $description = trim($description) ?: 'Besoin non détaillé';
        $pdf->SetFont('dejavusans', 'B', 8.3);
        $height = max(17.0, $pdf->getStringHeight(69, $description) + ($type !== '' ? 8 : 5));
        self::ensureSpace($pdf, $height + 3);
        $y = $pdf->GetY();
        $pdf->SetFillColor(...($shade ? [248, 250, 252] : [255, 255, 255]));
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->Rect(12, $y, 186, $height, 'DF');
        foreach ([23, 100, 120, 155] as $x) $pdf->Line($x, $y, $x, $y + $height);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetXY(14, $y + 4);
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->Cell(7, 6, (string)$index, 0, 0, 'C');
        $pdf->MultiCell(69, $height - 5, $description, 0, 'L', false, 0, 27, $y + 3, true, 0, false, true, $height - 5, 'T');
        if ($type !== '') {
            $pdf->SetFont('dejavusans', '', 6.7);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->SetXY(27, $y + $height - 5.5);
            $pdf->Cell(69, 3.5, mb_strtoupper($type), 0, 0);
        }
        $pdf->SetTextColor(15, 23, 42);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY(102, $y + 4);
        $pdf->Cell(16, 6, number_format($quantity, 2, ',', ' '), 0, 0, 'R');
        $pdf->SetXY(122, $y + 4);
        $pdf->Cell(31, 6, self::money($unitPrice), 0, 0, 'R');
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetXY(157, $y + 4);
        $pdf->Cell(39, 6, self::money($amount), 0, 0, 'R');
        $pdf->SetY($y + $height);
    }

    public static function ensureSpace(FebHumanReviewQuotePdfDocument $pdf, float $height, bool $repeatTableHeader = true): void
    {
        if ($pdf->GetY() + $height <= 266) return;
        $pdf->AddPage();
        $pdf->SetY(41);
        if ($repeatTableHeader) self::tableHeader($pdf);
    }

    public static function money(float $value): string { return number_format($value, 0, ',', ' ') . ' FCFA'; }
    public static function dateLabel($value): string
    {
        try { return (new DateTimeImmutable((string)$value))->format('d/m/Y à H:i'); }
        catch (Throwable) { return (string)$value; }
    }
}
