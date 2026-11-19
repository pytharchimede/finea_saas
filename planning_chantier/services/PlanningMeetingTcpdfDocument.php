<?php
declare(strict_types=1);

if (!class_exists('TCPDF')) {
    throw new RuntimeException('TCPDF est introuvable. Vérifier vendor/autoload.php ou la bibliothèque TCPDF.');
}

if (!class_exists('PlanningMeetingTcpdfDocument')) {
    class PlanningMeetingTcpdfDocument extends TCPDF
    {
        public string $companyName = 'BANAMUR';
        public string $documentTitle = 'Point de réunion chantier';
        public string $moduleLabel = 'Module Planning Chantier FINEA';
        public string $logoPath = '';

        public function Header(): void
        {
            $this->SetFillColor(255, 255, 255);
            $this->Rect(0, 0, $this->getPageWidth(), 28, 'F');
            $this->SetDrawColor(203, 213, 225);
            $this->Line(12, 24.5, $this->getPageWidth() - 12, 24.5);
            $this->SetFillColor(250, 189, 2);
            $this->Rect(12, 24.5, 34, 1.1, 'F');

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
            $this->Cell(0, 4.4, 'Direction exploitation - Document officiel', 0, 1, 'L');

            $this->SetXY($this->getPageWidth() - 88, 8.4);
            $this->SetFont('dejavusans', 'B', 8.6);
            $this->SetTextColor(51, 65, 85);
            $this->Cell(76, 4.5, $this->documentTitle, 0, 1, 'R');

            $this->SetX($this->getPageWidth() - 88);
            $this->SetFont('dejavusans', '', 7.6);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(76, 4.3, $this->moduleLabel, 0, 1, 'R');
        }

        public function Footer(): void
        {
            $this->SetY(-16);
            $this->SetDrawColor(214, 219, 228);
            $this->Line(12, $this->GetY(), $this->getPageWidth() - 12, $this->GetY());
            $this->Ln(2.5);
            $this->SetFont('dejavusans', '', 8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(0, 5, 'BANAMUR - '.$this->documentTitle.' sécurisé - Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }
}
