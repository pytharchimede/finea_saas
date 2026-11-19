<?php
declare(strict_types=1);

require_once __DIR__ . '/CompanyBranding.php';

/** Gabarit institutionnel commun aux PDF métier. */
trait CompanyPdfTemplate
{
    public string $companyPdfTitle = '';
    public string $companyPdfType = '';
    public string $companyPdfId = '';
    public string $companyPdfCode = '';
    public string $companyPdfQrPayload = '';
    public bool $companyPdfShowQr = true;
    public bool $companyPdfQrFirstPageOnly = false;

    /** One validated palette for document chrome and report content. */
    public function companyPdfColors(): array
    {
        $brand=CompanyBranding::get();$defaults=CompanyBranding::defaults();$palette=[];
        foreach(['primary','accent','surface','text','muted'] as $key){
            $value=(string)($brand[$key.'_color'] ?? '');
            $palette[$key]=preg_match('/^#[0-9a-f]{6}$/iD',$value)?strtolower($value):$defaults[$key.'_color'];
        }
        return $palette;
    }

    public function companyPdfRgb(string $hex): array
    {
        return [hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2))];
    }

    public function companyPdfContrast(string $hex): array
    {
        [$r,$g,$b]=$this->companyPdfRgb($hex);
        return (0.299*$r+0.587*$g+0.114*$b)>150?[0,0,0]:[255,255,255];
    }

    public function companyPdfUrl(string $path): string
    {
        $base=rtrim((string)(getenv('APP_BASE_URL') ?: 'https://projet.fidest.ci'),'/');
        if(!preg_match('#^https?://[^\s]+$#D',$base))$base='https://projet.fidest.ci';
        return $base.'/'.ltrim($path,'/');
    }

    public function drawCompanyPdfHeader(): void
    {
        $this->tcpdflink=false;
        $colors=$this->companyPdfColors();
        $pageWidth = $this->getPageWidth();
        $left = 11.0;
        $right = 11.0;
        $headerHeight = 34.0;
        $qrSize = 20.0;
        $logoWidth = 22.0;
        $qrX = $pageWidth - $right - $qrSize;
        $boxWidth = $pageWidth > 230 ? 48.0 : 41.0;
        $boxX = $qrX - $boxWidth - 4.0;
        $titleX = $left + $logoWidth + 5.0;
        $titleWidth = max(55.0, $boxX - $titleX - 4.0);

        // Branding configured in FINEA; default is black and gold.
        $this->SetFillColor(...$this->companyPdfRgb($colors['primary']));
        $this->Rect(0, 0, $pageWidth, $headerHeight, 'F');
        $this->SetFillColor(...$this->companyPdfRgb($colors['accent']));
        $this->Rect(0, $headerHeight - 2, $pageWidth, 2, 'F');

        $logo = CompanyBranding::logoFile();
        if ($logo !== '' && is_file($logo)) {
            // Le logo source est carre : une seule dimension forcee evite toute deformation.
            if(strtolower(pathinfo($logo,PATHINFO_EXTENSION))==='svg')$this->ImageSVG($logo,$left,5.5,$logoWidth,0);
            else $this->Image($logo, $left, 5.5, $logoWidth, 0, '', '', '', false, 300, '', false, false, 0, true);
        }

        $this->SetXY($titleX, 7.5);
        $this->SetTextColor(...$this->companyPdfContrast($colors['primary']));
        $this->SetFont('dejavusans', 'B', $pageWidth > 230 ? 14 : 11.5);
        $this->MultiCell($titleWidth, 10, mb_strtoupper($this->companyPdfTitle), 0, 'C', false, 1, '', '', true, 0, false, true, 10, 'M');
        $this->SetX($titleX);
        $this->SetFont('dejavusans', '', 6.5);
        $this->SetTextColor(...$this->companyPdfRgb($colors['accent']));
        $this->Cell($titleWidth, 4, 'DOCUMENT MAÎTRISÉ · ISO 9001', 0, 1, 'C');

        $this->SetDrawColor(...$this->companyPdfRgb($colors['accent']));
        $this->SetFillColor(...$this->companyPdfRgb($colors['primary']));
        $this->RoundedRect($boxX, 5, $boxWidth, 23, 2, '1111', 'DF');
        $this->SetXY($boxX + 2.5, 7);
        $this->SetFont('dejavusans', 'B', 6);
        $this->SetTextColor(...$this->companyPdfRgb($colors['accent']));
        $this->Cell($boxWidth - 5, 3.5, 'TYPE DE DOCUMENT', 0, 1);
        $this->SetX($boxX + 2.5);
        $this->SetTextColor(...$this->companyPdfContrast($colors['primary']));
        $this->SetFont('dejavusans', 'B', 7.2);
        $this->MultiCell($boxWidth - 5, 6, mb_strtoupper($this->companyPdfType), 0, 'L', false, 1, '', '', true, 0, false, true, 6, 'M');
        $this->SetX($boxX + 2.5);
        $this->SetFont('dejavusans', '', 5.8);
        $this->SetTextColor(...$this->companyPdfContrast($colors['primary']));
        $this->Cell($boxWidth - 5, 3.5, $this->companyPdfId, 0, 1, 'L', false, '', 1);

        $payload = $this->companyPdfQrPayload !== '' ? $this->companyPdfQrPayload : $this->companyPdfId;
        $mayDrawQr = !$this->companyPdfQrFirstPageOnly || $this->getPage() === 1;
        if ($this->companyPdfShowQr && $mayDrawQr && $payload !== '') {
            $style = ['border' => 0, 'vpadding' => 'auto', 'hpadding' => 'auto', 'fgcolor' => [0, 0, 0], 'bgcolor' => [255, 255, 255], 'module_width' => 1, 'module_height' => 1];
            $this->write2DBarcode($payload, 'QRCODE,H', $qrX, 6.5, $qrSize, $qrSize, $style, 'N');
        }
        $this->SetTextColor(...$this->companyPdfRgb($colors['text']));
    }

    public function drawCompanyPdfFooter(): void
    {
        $brand = CompanyBranding::get();
        $colors=$this->companyPdfColors();
        $pageWidth = $this->getPageWidth();
        $this->SetY(-22);
        $this->SetDrawColor(...$this->companyPdfRgb($colors['accent']));
        $this->Line(11, $this->GetY(), $pageWidth - 11, $this->GetY());
        $this->Ln(1.3);
        $this->SetTextColor(...$this->companyPdfRgb($colors['text']));
        $this->SetFont('dejavusans', 'B', 6.5);
        $companyName = trim((string)($brand['company_name'] ?? ''));
        $legalName = trim((string)($brand['legal_name'] ?? ''));
        $legalSuffix = $companyName !== '' && str_starts_with($legalName, $companyName) ? trim(substr($legalName, strlen($companyName))) : $legalName;
        $identity = trim(implode(' · ', array_filter([$companyName, $legalSuffix, trim((string)($brand['capital'] ?? '')) !== '' ? 'Capital : ' . trim((string)$brand['capital']) : ''])), " ·");
        $this->Cell(0, 3.4, $identity, 0, 1, 'C', false, '', 1);
        $this->SetFont('dejavusans', '', 5.9);
        $this->SetTextColor(...$this->companyPdfRgb($colors['muted']));
        $this->Cell(0, 3.2, trim((string)($brand['address'] ?? '')), 0, 1, 'C', false, '', 1);
        $contact = implode(' · ', array_filter([trim((string)($brand['phone'] ?? '')), trim((string)($brand['email'] ?? ''))]));
        $this->Cell(0, 3.2, $contact, 0, 1, 'C', false, '', 1);
        $legal = implode(' · ', array_filter([
            trim((string)($brand['rccm'] ?? '')) !== '' ? 'RCCM : ' . trim((string)$brand['rccm']) : '',
            trim((string)($brand['tax_number'] ?? '')) !== '' ? 'N° CC : ' . trim((string)$brand['tax_number']) : '',
        ]));
        $this->Cell(0, 3.2, $legal, 0, 1, 'C', false, '', 1);
        $this->SetFont('dejavusans', '', 5.8);
        $leftText = trim(implode(' · ', array_filter(['Document maîtrisé', $this->companyPdfCode, $this->companyPdfId])));
        $this->Cell(($pageWidth - 22) * .7, 3.2, $leftText, 0, 0, 'L', false, '', 1);
        $this->Cell(($pageWidth - 22) * .3, 3.2, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'R');
    }
}

