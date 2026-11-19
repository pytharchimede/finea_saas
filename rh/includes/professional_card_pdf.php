<?php

final class RhProfessionalCardPdf extends TCPDF
{
    public const CARD_WIDTH = 85.0;
    public const CARD_HEIGHT = 55.0;

    private string $logoPath;
    private string $profileUrl;
    private bool $cutMarks;

    public function __construct(string $logoPath, string $profileUrl, bool $cutMarks = true)
    {
        parent::__construct('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->logoPath = $logoPath;
        $this->profileUrl = $profileUrl;
        $this->cutMarks = $cutMarks;
        $this->SetCreator('FINEA - Module RH');
        $this->SetAuthor('BANAMUR Industries & Technologies');
        $this->SetTitle('Cartes professionnelles du personnel');
        $this->setPrintHeader(false);
        $this->setPrintFooter(false);
        $this->SetMargins(0, 0, 0);
        $this->SetAutoPageBreak(false, 0);
    }

    public function render(array $employees, string $photoMode = 'auto'): void
    {
        foreach (array_chunk($employees, 10) as $sheet) {
            $this->AddPage();
            foreach ($sheet as $index => $employee) {
                [$x, $y] = $this->position($index, false);
                $this->drawCutMarks($x, $y, $index === 0);
                $this->drawFront($employee, $x, $y, $photoMode);
            }
            $this->AddPage();
            foreach ($sheet as $index => $employee) {
                [$x, $y] = $this->position($index, true);
                $this->drawCutMarks($x, $y, $index === 0);
                $this->drawBack($employee, $x, $y);
            }
        }
    }

    private function position(int $index, bool $mirror): array
    {
        $column = $index % 2;
        if ($mirror) $column = 1 - $column;
        return [20.0 + ($column * self::CARD_WIDTH), 11.0 + (intdiv($index, 2) * self::CARD_HEIGHT)];
    }

    private function drawFront(array $employee, float $x, float $y, string $photoMode): void
    {
        $w = self::CARD_WIDTH; $h = self::CARD_HEIGHT; $yellow = [255, 204, 32];
        $this->SetFillColor(255, 255, 255); $this->SetDrawColor(0, 0, 0);
        $this->RoundedRect($x, $y, $w, $h, 2.5, '1111', 'DF');
        $this->SetAlpha(.028); $this->Image($this->logoPath, $x + 61, $y + 13, 31, 31, '', '', '', false, 300); $this->SetAlpha(1);
        $this->SetFillColor(...$yellow); $this->Polygon([$x, $y + 42, $x + 5, $y + 50, $x + 36, $y + 50, $x + 42, $y + 55, $x, $y + 55], 'F');
        $this->SetFillColor(0, 0, 0); $this->Polygon([$x, $y + 46, $x + 5, $y + 52, $x + 35, $y + 52, $x + 38, $y + 55, $x, $y + 55], 'F');
        $this->SetFillColor(...$yellow); $this->Polygon([$x + 35, $y + 50, $x + 42, $y + 55, $x + 46, $y + 55, $x + 39, $y + 50], 'F');
        $separatorX = $x + 36.5;
        $this->SetDrawColor(0, 0, 0); $this->SetLineWidth(0.6); $this->Line($separatorX, $y + 6, $separatorX, $y + 46);
        $this->SetFillColor(...$yellow); $this->Polygon([$separatorX - 1, $y + 21, $separatorX, $y + 19.8, $separatorX + 1, $y + 21, $separatorX + 1, $y + 26, $separatorX, $y + 27.2, $separatorX - 1, $y + 26], 'F');

        $photoPath = $photoMode !== 'without' ? (string) ($employee['_photo_path'] ?? '') : '';
        if ($photoPath !== '' && is_file($photoPath)) {
            $this->Image($photoPath, $x + 7.3, $y + 4.5, 22, 28, '', '', '', false, 300, '', false, false, 0, 'CM');
            $this->SetDrawColor(...$yellow); $this->SetLineWidth(0.75); $this->RoundedRect($x + 6.8, $y + 4, 23, 29, 2, '1111', 'D');
            $this->Image($this->logoPath, $x + 12.4, $y + 34, 11.5, 11.5, '', '', '', false, 300);
        } else {
            $this->Image($this->logoPath, $x + 3.2, $y + 7.2, 30, 30, '', '', '', false, 300);
        }

        $name = mb_strtoupper(trim((string) ($employee['full_name'] ?? 'COLLABORATEUR')));
        $role = mb_strtoupper(trim((string) ($employee['fonction_label'] ?? 'Fonction non renseignée')));
        $contentX = $x + 41;
        $this->SetTextColor(0, 0, 0); $this->SetFont('dejavusans', 'B', 11.2); $this->SetXY($contentX, $y + 5.5);
        $this->MultiCell(40, 8.5, $name, 0, 'L', false, 1, '', '', true, 0, false, true, 8.5, 'M', true);
        $this->SetTextColor(...$yellow); $this->SetFont('dejavusans', 'B', 6.8); $this->SetXY($contentX, $y + 15.2);
        $this->MultiCell(40, 5.5, $role, 0, 'L', false, 1, '', '', true, 0, false, true, 5.5, 'M', true);
        $this->SetDrawColor(0, 0, 0); $this->SetLineWidth(0.55); $this->Line($contentX, $y + 22.3, $contentX + 8, $y + 22.3);
        $this->SetDrawColor(...$yellow); $this->SetLineWidth(0.45); $this->Line($contentX + 10, $y + 22.3, $x + 80.5, $y + 22.3);

        $details = [
            ['☎', trim((string) ($employee['phone'] ?? '')) ?: 'Téléphone non renseigné'],
            ['@', trim((string) ($employee['email'] ?? '')) ?: 'E-mail non renseigné'],
            ['●', trim((string) ($employee['site_label'] ?? '')) ?: 'Abidjan, Côte d’Ivoire'],
            ['◎', 'www.banamur.ci'],
            ['in', 'linkedin.com/company/banamur'],
        ];
        $lineY = $y + 25;
        foreach ($details as [$icon, $value]) {
            $this->SetFillColor(...$yellow); $this->SetTextColor(0, 0, 0); $this->SetFont('dejavusans', 'B', $icon === 'in' ? 5 : 6.1);
            $this->Polygon([$contentX, $lineY + 2.15, $contentX + 1.2, $lineY, $contentX + 4, $lineY, $contentX + 5.2, $lineY + 2.15, $contentX + 4, $lineY + 4.3, $contentX + 1.2, $lineY + 4.3], 'F');
            $this->SetXY($contentX + .1, $lineY + .05); $this->Cell(5, 3.7, $icon, 0, 0, 'C');
            $this->SetFont('dejavusans', '', 5.8); $this->SetXY($contentX + 6.5, $lineY); $this->Cell(33, 4, $value, 0, 0, 'L', false, '', 1);
            $lineY += 4.65;
        }
    }

    private function drawBack(array $employee, float $x, float $y): void
    {
        $w = self::CARD_WIDTH; $h = self::CARD_HEIGHT; $yellow = [255, 204, 32];
        $this->SetFillColor(255, 255, 255); $this->SetDrawColor(0, 0, 0); $this->RoundedRect($x, $y, $w, $h, 2.5, '1111', 'DF');
        $leftWidth = 56.1;
        $this->SetFillColor(0, 0, 0); $this->Rect($x, $y, $leftWidth, $h, 'F');
        $this->SetDrawColor(255, 255, 255); $this->SetAlpha(.04); $this->SetLineWidth(.18);
        for ($offset = -15; $offset < 60; $offset += 7) $this->Line($x + $offset, $y + 49, $x + $offset + 38, $y + 7);
        $this->Image($this->logoPath, $x + 13, $y + 17, 38, 38, '', '', '', false, 300); $this->SetAlpha(1);
        $this->SetFillColor(...$yellow); $this->Polygon([$x + 50, $y, $x + 57, $y, $x + 51, $y + 27.5, $x + 57, $y + 55, $x + 50, $y + 55, $x + 44, $y + 27.5], 'F');
        $this->SetTextColor(255, 255, 255); $this->SetFont('dejavusans', 'B', 8.4); $this->SetXY($x + 5, $y + 6); $this->Cell(41, 5, 'CONSTRUIRE L’AVENIR', 0, 1);
        $this->SetTextColor(...$yellow); $this->SetX($x + 5); $this->Cell(41, 5, 'AVEC INNOVATION', 0, 1);
        $this->SetTextColor(255, 255, 255); $this->SetX($x + 5); $this->Cell(41, 5, 'ET INTÉGRITÉ', 0, 1);
        $this->SetDrawColor(...$yellow); $this->SetLineWidth(.7); $this->Line($x + 5, $y + 23, $x + 14, $y + 23);
        $categories = [['BTP', "BTP &\nCONSTRUCTION"], ['⚙', "INDUSTRIES &\nMAINTENANCE"], ['▣', "TECHNOLOGIES &\nINNOVATION"], ['◇', "SERVICES &\nINGÉNIERIE"]];
        foreach ($categories as $index => [$short, $label]) {
            $cx = $x + 3.3 + ($index * 10.4); $cy = $y + 28;
            $this->SetDrawColor(...$yellow); $this->SetLineWidth(.35); $this->Polygon([$cx, $cy + 3.4, $cx + 2, $cy, $cx + 6, $cy, $cx + 8, $cy + 3.4, $cx + 6, $cy + 6.8, $cx + 2, $cy + 6.8], 'D');
            $this->SetTextColor(255, 255, 255); $this->SetFont('dejavusans', 'B', 4); $this->SetXY($cx, $cy + 1); $this->Cell(8, 3.5, $short, 0, 0, 'C');
            $this->SetFont('dejavusanscondensed', '', 3.4); $this->SetXY($cx - 1.4, $cy + 8); $this->MultiCell(10.8, 6, $label, 0, 'C', false, 1);
        }

        $this->SetFillColor(...$yellow); $this->Rect($x, $y + 48, 50, 7, 'F');
        $this->SetTextColor(0, 0, 0); $this->SetFont('dejavusans', '', 5.1); $this->SetXY($x + 4.5, $y + 49.5);
        $this->Cell(43, 4, 'EXCELLENCE  •  FIABILITÉ  •  ENGAGEMENT', 0, 0, 'C', false, '', 1);

        $rightX = $x + 55.5;
        $this->SetTextColor(0, 0, 0); $this->SetFont('dejavusans', 'B', 6.5); $this->SetXY($rightX, $y + 4.5); $this->Cell(28, 4, 'ACCÉDEZ À MON', 0, 1, 'C');
        $this->SetTextColor(...$yellow); $this->SetX($rightX); $this->Cell(28, 4, 'ESPACE PERSONNEL', 0, 1, 'C');
        $qrX = $x + 59; $qrY = $y + 13.2; $qrSize = 22;
        $style = ['border' => 0, 'padding' => 0, 'fgcolor' => [0, 0, 0], 'bgcolor' => [255, 255, 255]];
        $this->write2DBarcode($this->profileUrl, 'QRCODE,H', $qrX, $qrY, $qrSize, $qrSize, $style, 'N');
        $this->SetFillColor(255, 255, 255); $this->Rect($qrX + 7.2, $qrY + 7.2, 7.6, 7.6, 'F');
        $this->Image($this->logoPath, $qrX + 8.1, $qrY + 8.1, 5.8, 5.8, '', '', '', false, 300);
        $this->SetFillColor(0, 0, 0); $this->RoundedRect($x + 57.2, $y + 38, 26, 8, 1.4, '1111', 'F');
        $this->SetTextColor(...$yellow); $this->SetFont('dejavusans', 'B', 8); $this->SetXY($x + 58, $y + 39); $this->Cell(4, 5, '▯', 0, 0, 'C');
        $this->SetTextColor(255, 255, 255); $this->SetFont('dejavusans', '', 4.5); $this->SetXY($x + 62, $y + 39);
        $this->MultiCell(20, 5.5, 'Scannez pour accéder à mon espace personnel', 0, 'C', false, 1);
        $this->SetFont('dejavusans', '', 4.2); $this->SetTextColor(90, 90, 90); $this->SetXY($rightX, $y + 48);
        $this->Cell(28, 3, (string) ($employee['matricule'] ?? ''), 0, 0, 'C');
    }

    private function drawCutMarks(float $x, float $y, bool $showScissors): void
    {
        if (!$this->cutMarks) return;
        $w = self::CARD_WIDTH; $h = self::CARD_HEIGHT; $length = 3.2;
        $this->SetLineStyle(['width' => .12, 'dash' => '1,1', 'color' => [100, 100, 100]]);
        foreach ([[$x, $y], [$x + $w, $y], [$x, $y + $h], [$x + $w, $y + $h]] as [$cx, $cy]) {
            $directionX = abs($cx - $x) < .01 ? -1 : 1; $directionY = abs($cy - $y) < .01 ? -1 : 1;
            $this->Line($cx, $cy, $cx + ($directionX * $length), $cy); $this->Line($cx, $cy, $cx, $cy + ($directionY * $length));
        }
        if ($showScissors) { $this->SetFont('dejavusans', '', 7); $this->SetTextColor(80, 80, 80); $this->Text($x - 7, $y - 2.8, '✂'); }
        $this->SetLineStyle(['width' => .2, 'dash' => 0, 'color' => [0, 0, 0]]);
    }
}
