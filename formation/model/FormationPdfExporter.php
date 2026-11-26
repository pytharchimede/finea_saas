<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../model/CompanyExportBranding.php';

if (!class_exists('FormationPdfDocument')) {
    class FormationPdfDocument extends \TCPDF
    {
        public string $appName = 'Finéa';
        public string $slogan = '';
        public string $moduleLabel = '';
        public string $audienceLabel = '';
        public string $logoPath = '';
        public string $iconPath = '';
        public string $exportedAtLabel = '';
        public string $accentLabel = '';

        public function Header(): void
        {
            $this->SetFillColor(255, 255, 255);
            $this->Rect(0, 0, $this->getPageWidth(), 30, 'F');
            $this->SetDrawColor(226, 232, 240);
            $this->Line(12, 25, $this->getPageWidth() - 12, 25);
            $this->SetFillColor(...\CompanyExportBranding::rgbArray('primary'));
            $this->Rect(12, 25, 40, 1.1, 'F');

            if ($this->logoPath !== '' && is_file($this->logoPath)) {
                $this->Image($this->logoPath, 12, 6.5, 28, 0, '', '', '', false, 300, '', false, false, 0, false, false, false);
                $titleX = 44;
            } elseif ($this->iconPath !== '' && is_file($this->iconPath)) {
                $this->Image($this->iconPath, 12, 6.5, 14, 14, '', '', '', false, 300, '', false, false, 0, false, false, false);
                $titleX = 30;
            } else {
                $titleX = 12;
            }

            $this->SetXY($titleX, 7.5);
            $this->SetFont('dejavusans', 'B', 15);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(76, 5.8, $this->appName, 0, 1, 'L', false, '', 1);

            $this->SetXY($titleX, 13.8);
            $this->SetFont('dejavusans', '', 8.4);
            $this->SetTextColor(71, 85, 105);
            $this->Cell(76, 4.4, 'Centre de guides applicatifs', 0, 1, 'L', false, '', 1);

            $this->SetXY($this->getPageWidth() - 88, 7.8);
            $this->SetFont('dejavusans', 'B', 9);
            $this->SetTextColor(15, 23, 42);
            $this->Cell(76, 4.5, $this->moduleLabel, 0, 1, 'R');

            $this->SetX($this->getPageWidth() - 88);
            $this->SetFont('dejavusans', '', 7.8);
            $this->SetTextColor(100, 116, 139);
            $this->Cell(76, 4.2, $this->audienceLabel, 0, 1, 'R');

            if ($this->accentLabel !== '') {
                $this->SetFillColor(...\CompanyExportBranding::rgbArray('accent'));
                $this->SetTextColor(...\CompanyExportBranding::rgbArray('accent', true));
                $this->SetFont('dejavusans', 'B', 7.2);
                $this->RoundedRect($this->getPageWidth() - 68, 18.5, 56, 6.8, 2.5, '1111', 'F');
                $this->SetXY($this->getPageWidth() - 66, 20.1);
                $this->Cell(52, 3.4, $this->accentLabel, 0, 0, 'C');
            }
        }

        public function Footer(): void
        {
            $this->SetY(-16);
            $this->SetDrawColor(226, 232, 240);
            $this->Line(12, $this->GetY(), $this->getPageWidth() - 12, $this->GetY());
            $this->Ln(2.2);
            $this->SetFont('dejavusans', '', 7.8);
            $this->SetTextColor(100, 116, 139);
            $footerLeft = $this->exportedAtLabel !== '' ? $this->exportedAtLabel : 'Export PDF Formation';
            $footerRight = 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages();
            $this->Cell(90, 4.5, $footerLeft, 0, 0, 'L');
            $this->Cell(0, 4.5, $footerRight, 0, 0, 'R');
        }
    }
}

if (!class_exists('FormationPdfExporter')) {
    class FormationPdfExporter
    {
        private array $context;

        public function __construct(array $context)
        {
            $this->context = $context;
        }

        public function outputCurrentDocument(array $document): void
        {
            $pdf = $this->createPdf($document['title'] ?? 'Guide');
            $pdf->AddPage();
            $this->renderDocumentIntro($pdf, $document['title'] ?? 'Guide', true);
            $this->renderDocumentBlocks($pdf, $document);
            $this->emit($pdf, $this->buildFileName(($this->context['module_slug'] ?? 'formation') . '_' . ($document['slug'] ?? 'guide')));
        }

        public function outputModuleBundle(array $documents): void
        {
            $pdf = $this->createPdf(($this->context['module_label'] ?? 'Formation') . ' - dossier complet');
            $pdf->AddPage();
            $this->renderBundleCover($pdf, $documents);

            foreach ($documents as $index => $document) {
                $pdf->AddPage();
                $this->renderDocumentIntro($pdf, $document['title'] ?? ('Document ' . ($index + 1)), false, $index + 1, count($documents));
                $this->renderDocumentBlocks($pdf, $document);
            }

            $this->emit($pdf, $this->buildFileName(($this->context['module_slug'] ?? 'formation') . '_' . ($this->context['audience_slug'] ?? 'docs') . '_complet'));
        }

        private function createPdf(string $title): FormationPdfDocument
        {
            $pdf = new FormationPdfDocument('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->appName = (string) ($this->context['app_name'] ?? 'Finéa');
            $pdf->slogan = (string) ($this->context['brand_slogan'] ?? '');
            $pdf->moduleLabel = (string) ($this->context['module_label'] ?? 'Formation');
            $pdf->audienceLabel = (string) ($this->context['audience_label'] ?? 'Guide');
            $pdf->logoPath = (string) ($this->context['logo_path'] ?? '');
            $pdf->iconPath = (string) ($this->context['icon_path'] ?? '');
            $pdf->exportedAtLabel = 'Export généré le ' . date('d/m/Y à H:i');
            $pdf->accentLabel = 'PDF Formation Pro';
            $pdf->SetCreator(\CompanyExportBranding::creator());
            $pdf->SetAuthor((string) ($this->context['app_name'] ?? 'Finéa'));
            $pdf->SetTitle($title);
            $pdf->SetSubject((string) ($this->context['module_label'] ?? 'Formation'));
            $pdf->SetMargins(14, 32, 14);
            $pdf->SetAutoPageBreak(true, 20);
            $pdf->SetFont('dejavusans', '', 10);
            return $pdf;
        }

        private function renderBundleCover(FormationPdfDocument $pdf, array $documents): void
        {
            $pageWidth = $pdf->getPageWidth() - 28;
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->RoundedRect(14, 38, $pageWidth, 64, 8, '1111', 'DF');
            $pdf->SetFillColor(...\CompanyExportBranding::rgbArray('primary'));
            $pdf->RoundedRect(14, 38, 7, 64, 8, '1101', 'F');
            $pdf->SetFillColor(...\CompanyExportBranding::rgbArray('accent'));
            $pdf->RoundedRect(21, 45, $pageWidth - 18, 16, 4, '1111', 'F');

            if (($this->context['logo_path'] ?? '') !== '' && is_file((string) $this->context['logo_path'])) {
                $pdf->Image((string) $this->context['logo_path'], 28, 68, 48, 0, '', '', '', false, 300, '', false, false, 0, false, false, false);
            }

            $pdf->SetXY(27, 49.5);
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetTextColor(...\CompanyExportBranding::rgbArray('accent', true));
            $pdf->Cell(0, 4, 'DOSSIER FORMATION CONSOLIDÉ', 0, 1, 'L');

            $pdf->SetXY(27, 83);
            $pdf->SetFont('dejavusans', 'B', 18);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(0, 7, (string) ($this->context['module_label'] ?? 'Formation'), 0, 1, 'L');

            $pdf->SetX(27);
            $pdf->SetFont('dejavusans', '', 10.2);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->MultiCell(0, 5.4, (string) ($this->context['audience_label'] ?? 'Guide') . ' • Dossier PDF consolidé avec pagination, entête, pied de page et rendu prêt au partage.', 0, 'L', false, 1);

            $chipY = 110;
            $chips = [
                'Documents : ' . count($documents),
                'Généré : ' . date('d/m/Y'),
                'Marque : ' . (string) ($this->context['app_name'] ?? 'Finéa'),
            ];
            $chipX = 14;
            foreach ($chips as $chip) {
                $width = max(44.0, min(58.0, $pdf->GetStringWidth($chip) + 10.0));
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetDrawColor(226, 232, 240);
                $pdf->RoundedRect($chipX, $chipY, $width, 8.5, 3, '1111', 'DF');
                $pdf->SetXY($chipX + 5, $chipY + 2.2);
                $pdf->SetFont('dejavusans', 'B', 7.8);
                $pdf->SetTextColor(51, 65, 85);
                $pdf->Cell($width - 10, 3.6, $chip, 0, 0, 'C');
                $chipX += $width + 4;
            }

            $pdf->Ln(12);
            $pdf->SetFont('dejavusans', 'B', 11.5);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(0, 6, 'Documents inclus', 0, 1, 'L');
            $pdf->SetFont('dejavusans', '', 9.4);
            $pdf->SetTextColor(51, 65, 85);

            foreach ($documents as $index => $document) {
                $pdf->SetFillColor(255, 255, 255);
                $pdf->SetDrawColor(226, 232, 240);
                $pdf->RoundedRect(14, $pdf->GetY(), $pageWidth, 12, 3, '1111', 'DF');
                $pdf->SetXY(18, $pdf->GetY() + 2.4);
                $pdf->SetFont('dejavusans', 'B', 9.5);
                $pdf->Cell(12, 4.6, sprintf('%02d', $index + 1), 0, 0, 'L');
                $pdf->SetFont('dejavusans', '', 9.4);
                $pdf->Cell(0, 4.6, (string) ($document['title'] ?? 'Document'), 0, 1, 'L');
                $pdf->Ln(2);
            }

            $captureName = trim((string) ($this->context['capture_collaborator'] ?? ''));
            $captureEmail = trim((string) ($this->context['capture_email'] ?? ''));
            if ($captureName !== '' || $captureEmail !== '') {
                $pdf->Ln(4);
                $pdf->SetFillColor(255, 251, 235);
                $pdf->SetDrawColor(253, 230, 138);
                $pdf->RoundedRect(14, $pdf->GetY(), $pageWidth, 16, 3, '1111', 'DF');
                $pdf->SetXY(18, $pdf->GetY() + 2.8);
                $pdf->SetFont('dejavusans', 'B', 8.8);
                $pdf->SetTextColor(146, 64, 14);
                $pdf->Cell(0, 4.4, 'Contexte capture', 0, 1, 'L');
                $pdf->SetX(18);
                $pdf->SetFont('dejavusans', '', 8.6);
                $pdf->MultiCell(0, 4.4, trim($captureName . ($captureEmail !== '' ? ' • ' . $captureEmail : '')), 0, 'L', false, 1);
            }
        }

        private function renderDocumentIntro(FormationPdfDocument $pdf, string $title, bool $singleDocument, int $index = 1, int $count = 1): void
        {
            $pageWidth = $pdf->getPageWidth() - 28;
            $pdf->SetFillColor(248, 250, 252);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->RoundedRect(14, 37, $pageWidth, 30, 5, '1111', 'DF');
            $pdf->SetFillColor(...\CompanyExportBranding::rgbArray('primary'));
            $pdf->RoundedRect(14, 37, 4.5, 30, 5, '1101', 'F');
            $pdf->SetFillColor(...\CompanyExportBranding::rgbArray('accent'));
            $pdf->RoundedRect(22, 42, 42, 7, 3, '1111', 'F');
            $pdf->SetXY(24, 44);
            $pdf->SetFont('dejavusans', 'B', 7.4);
            $pdf->SetTextColor(...\CompanyExportBranding::rgbArray('accent', true));
            $pdf->Cell(38, 2.8, $singleDocument ? 'GUIDE INDIVIDUEL' : 'DOCUMENT DU DOSSIER', 0, 0, 'C');

            $pdf->SetXY(22, 51.3);
            $pdf->SetFont('dejavusans', 'B', 15);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->Cell(0, 6, $title, 0, 1, 'L');

            $pdf->SetX(22);
            $pdf->SetFont('dejavusans', '', 9.2);
            $pdf->SetTextColor(71, 85, 105);
            $subtitle = $singleDocument
                ? 'Document exporté individuellement au format PDF professionnel.'
                : 'Document ' . $index . ' sur ' . $count . ' dans le dossier consolidé.';
            $pdf->MultiCell(0, 4.8, $subtitle, 0, 'L', false, 1);

            $pdf->SetY(75);
        }

        private function renderDocumentBlocks(FormationPdfDocument $pdf, array $document): void
        {
            $markdown = (string) ($document['content'] ?? '');
            $documentPath = (string) ($document['path'] ?? '');
            $blocks = $this->parseMarkdown($markdown, $documentPath !== '' ? dirname($documentPath) : (string) ($this->context['project_root'] ?? ''));

            foreach ($blocks as $index => $block) {
                if (($block['type'] ?? '') === 'heading') {
                    $this->ensureHeadingSpace($pdf, $blocks, $index);
                    $this->renderHeading($pdf, $block);
                    continue;
                }

                if (($block['type'] ?? '') === 'paragraph') {
                    $this->renderParagraph($pdf, (string) ($block['text'] ?? ''));
                    continue;
                }

                if (($block['type'] ?? '') === 'list') {
                    $this->renderList($pdf, $block);
                    continue;
                }

                if (($block['type'] ?? '') === 'code') {
                    $this->renderCode($pdf, (string) ($block['text'] ?? ''));
                    continue;
                }

                if (($block['type'] ?? '') === 'image') {
                    $this->renderImage($pdf, $block);
                }
            }
        }

        private function renderHeading(FormationPdfDocument $pdf, array $block): void
        {
            $level = (int) ($block['level'] ?? 2);
            $text = trim((string) ($block['text'] ?? ''));
            if ($text === '') {
                return;
            }

            $size = $level <= 1 ? 14 : ($level === 2 ? 12.4 : 11.2);
            $accent = $level <= 2 ? [37, 99, 235] : [99, 102, 241];
            $pdf->Ln(1.5);
            $pdf->SetDrawColor($accent[0], $accent[1], $accent[2]);
            $pdf->Line(14, $pdf->GetY(), 22, $pdf->GetY());
            $pdf->Ln(2.4);
            $pdf->SetFont('dejavusans', 'B', $size);
            $pdf->SetTextColor(15, 23, 42);
            $pdf->MultiCell(0, 5.8, $text, 0, 'L', false, 1);
            $pdf->Ln(0.8);
        }

        private function renderParagraph(FormationPdfDocument $pdf, string $text): void
        {
            $text = trim($text);
            if ($text === '') {
                return;
            }

            $height = max(8.0, $pdf->getStringHeight(178, $this->plainText($text)) + 2.5);
            $this->ensureSpace($pdf, $height);
            $pdf->SetFont('dejavusans', '', 10.2);
            $html = '<div style="font-size:10.2px; line-height:1.72; color:#334155;">' . $this->inlineHtml($text) . '</div>';
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Ln(0.6);
        }

        private function renderList(FormationPdfDocument $pdf, array $block): void
        {
            $items = $block['items'] ?? [];
            if (!is_array($items) || $items === []) {
                return;
            }

            $plainText = implode("\n", array_map(fn($item): string => '- ' . $this->plainText((string) $item), $items));
            $height = max(10.0, $pdf->getStringHeight(172, $plainText) + 4.0);
            $this->ensureSpace($pdf, $height);
            $pdf->SetFont('dejavusans', '', 10);

            $tag = (($block['ordered'] ?? false) === true) ? 'ol' : 'ul';
            $htmlItems = '';
            foreach ($items as $item) {
                $htmlItems .= '<li style="margin-bottom:4px;">' . $this->inlineHtml((string) $item) . '</li>';
            }
            $html = '<' . $tag . ' style="font-size:10px; line-height:1.68; color:#334155; padding-left:18px; margin:0 0 8px 0;">' . $htmlItems . '</' . $tag . '>';
            $pdf->writeHTML($html, true, false, true, false, '');
        }

        private function renderCode(FormationPdfDocument $pdf, string $text): void
        {
            $text = rtrim($text, "\n");
            if ($text === '') {
                return;
            }

            $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
            $height = max(16.0, (count($lines) * 4.4) + 8.0);
            $this->ensureSpace($pdf, $height);

            $x = 14;
            $y = $pdf->GetY();
            $width = $pdf->getPageWidth() - 28;
            $pdf->SetFillColor(15, 23, 42);
            $pdf->SetDrawColor(15, 23, 42);
            $pdf->RoundedRect($x, $y, $width, $height, 3, '1111', 'DF');
            $pdf->SetXY($x + 4, $y + 4);
            $pdf->SetFont('courier', '', 8.8);
            $pdf->SetTextColor(241, 245, 249);
            $pdf->MultiCell($width - 8, 4.2, $text, 0, 'L', false, 1);
            $pdf->Ln(2.2);
        }

        private function renderImage(FormationPdfDocument $pdf, array $block): void
        {
            $source = trim((string) ($block['src'] ?? ''));
            if ($source === '') {
                return;
            }

            $resolved = $this->resolveImagePath($source, (string) ($block['base_dir'] ?? ''));
            if ($resolved === '' || (!preg_match('~^https?://~i', $resolved) && !is_file($resolved))) {
                $this->renderParagraph($pdf, 'Capture non trouvée : ' . $source);
                return;
            }

            $imageSize = @getimagesize($resolved);
            if ($imageSize === false) {
                $this->renderParagraph($pdf, 'Capture non lisible : ' . $source);
                return;
            }

            [$pixelWidth, $pixelHeight] = $imageSize;
            if ($pixelWidth <= 0 || $pixelHeight <= 0) {
                return;
            }

            $maxWidth = $pdf->getPageWidth() - 36;
            $maxHeight = 92.0;
            $ratio = $pixelWidth / $pixelHeight;
            $renderWidth = $maxWidth;
            $renderHeight = $renderWidth / $ratio;
            if ($renderHeight > $maxHeight) {
                $renderHeight = $maxHeight;
                $renderWidth = $renderHeight * $ratio;
            }

            $caption = trim((string) ($block['title'] ?? ''));
            if ($caption === '') {
                $caption = trim((string) ($block['alt'] ?? ''));
            }

            $needed = $renderHeight + ($caption !== '' ? 16.0 : 8.0);
            $this->ensureSpace($pdf, $needed);

            $x = ($pdf->getPageWidth() - $renderWidth) / 2;
            $y = $pdf->GetY();
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->RoundedRect($x - 3.5, $y - 3.5, $renderWidth + 7, $renderHeight + 7, 4, '1111', 'DF');
            $pdf->SetFillColor(248, 250, 252);
            $pdf->RoundedRect($x - 3.5, $y - 3.5, $renderWidth + 7, 8.5, 4, '1111', 'F');
            $pdf->SetXY($x + 4, $y - 1.1);
            $pdf->SetFont('dejavusans', 'B', 7.5);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->Cell($renderWidth - 8, 3.4, 'CAPTURE', 0, 0, 'L');
            $pdf->Image($resolved, $x, $y, $renderWidth, $renderHeight, '', '', '', false, 300, '', false, false, 0, false, false, false);
            $pdf->SetY($y + $renderHeight + 4);

            if ($caption !== '') {
                $pdf->SetFont('dejavusans', '', 8.6);
                $pdf->SetTextColor(71, 85, 105);
                $pdf->MultiCell(0, 4.4, $caption, 0, 'C', false, 1);
            }

            $pdf->Ln(1.4);
        }

        private function parseMarkdown(string $markdown, string $baseDir): array
        {
            $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
            $blocks = [];
            $paragraph = [];
            $listItems = [];
            $listOrdered = false;
            $inCode = false;
            $codeLines = [];

            $flushParagraph = static function () use (&$paragraph, &$blocks): void {
                if ($paragraph === []) {
                    return;
                }
                $text = trim(implode(' ', $paragraph));
                if ($text !== '') {
                    $blocks[] = ['type' => 'paragraph', 'text' => $text];
                }
                $paragraph = [];
            };

            $flushList = static function () use (&$listItems, &$listOrdered, &$blocks): void {
                if ($listItems === []) {
                    return;
                }
                $blocks[] = ['type' => 'list', 'items' => $listItems, 'ordered' => $listOrdered];
                $listItems = [];
                $listOrdered = false;
            };

            foreach ($lines as $line) {
                $trimmed = trim($line);

                if (preg_match('/^```/', $trimmed)) {
                    $flushParagraph();
                    $flushList();
                    if (!$inCode) {
                        $inCode = true;
                        $codeLines = [];
                    } else {
                        $blocks[] = ['type' => 'code', 'text' => implode("\n", $codeLines)];
                        $codeLines = [];
                        $inCode = false;
                    }
                    continue;
                }

                if ($inCode) {
                    $codeLines[] = rtrim($line, "\r");
                    continue;
                }

                if ($trimmed === '') {
                    $flushParagraph();
                    $flushList();
                    continue;
                }

                if (preg_match('/^(#{1,3})\s+(.*)$/', $trimmed, $matches)) {
                    $flushParagraph();
                    $flushList();
                    $blocks[] = ['type' => 'heading', 'level' => strlen($matches[1]), 'text' => trim($matches[2])];
                    continue;
                }

                $imageData = $this->parseMarkdownImage($trimmed);
                if ($imageData !== null) {
                    $flushParagraph();
                    $flushList();
                    $blocks[] = [
                        'type' => 'image',
                        'alt' => trim((string) $imageData['alt']),
                        'src' => trim((string) $imageData['src']),
                        'title' => trim((string) $imageData['title']),
                        'base_dir' => $baseDir,
                    ];
                    continue;
                }

                if (preg_match('/^[-*]\s+(.*)$/', $trimmed, $matches)) {
                    $flushParagraph();
                    if ($listItems !== [] && $listOrdered) {
                        $flushList();
                    }
                    $listOrdered = false;
                    $listItems[] = trim($matches[1]);
                    continue;
                }

                if (preg_match('/^\d+\.\s+(.*)$/', $trimmed, $matches)) {
                    $flushParagraph();
                    if ($listItems !== [] && !$listOrdered) {
                        $flushList();
                    }
                    $listOrdered = true;
                    $listItems[] = trim($matches[1]);
                    continue;
                }

                $paragraph[] = $trimmed;
            }

            $flushParagraph();
            $flushList();
            if ($inCode && $codeLines !== []) {
                $blocks[] = ['type' => 'code', 'text' => implode("\n", $codeLines)];
            }

            return $blocks;
        }

        private function inlineHtml(string $text): string
        {
            $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $escaped = preg_replace('/`([^`]+)`/', '<span style="background-color:#dbeafe; color:#1d4ed8; font-weight:600;">&nbsp;$1&nbsp;</span>', $escaped) ?? $escaped;
            $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
            $escaped = preg_replace('/\*([^*]+)\*/', '<em>$1</em>', $escaped) ?? $escaped;
            return $escaped;
        }

        private function parseMarkdownImage(string $line): ?array
        {
            $line = trim($line);
            if ($line === '' || !preg_match('/^!\[(.*?)\]\((.+)\)$/', $line, $matches)) {
                return null;
            }

            $alt = trim((string) $matches[1]);
            $inner = trim((string) $matches[2]);
            $source = '';
            $title = '';

            if ($inner !== '' && $inner[0] === '<') {
                $closingPos = strrpos($inner, '>');
                if ($closingPos === false) {
                    return null;
                }

                $source = substr($inner, 1, $closingPos - 1);
                $rest = trim(substr($inner, $closingPos + 1));
                if ($rest !== '') {
                    if (!preg_match('/^"([^"]*)"$/', $rest, $titleMatches)) {
                        return null;
                    }
                    $title = trim((string) $titleMatches[1]);
                }
            } else {
                if (!preg_match('/^(.*?)(?:\s+"([^"]*)")?$/', $inner, $innerMatches)) {
                    return null;
                }

                $source = trim((string) ($innerMatches[1] ?? ''));
                $title = trim((string) ($innerMatches[2] ?? ''));
            }

            $source = trim($source, " \t\n\r\0\x0B<>");
            if ($source === '') {
                return null;
            }

            return [
                'alt' => $alt,
                'src' => $source,
                'title' => $title,
            ];
        }

        private function plainText(string $text): string
        {
            $text = preg_replace('/!\[(.*?)\]\((.+?)\)/', '$1', $text) ?? $text;
            $text = preg_replace('/\[(.*?)\]\((.+?)\)/', '$1', $text) ?? $text;
            $text = str_replace(['**', '*', '`'], '', $text);
            return html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        }

        private function ensureHeadingSpace(FormationPdfDocument $pdf, array $blocks, int $index): void
        {
            $lookAhead = 18.0;
            if (isset($blocks[$index + 1])) {
                $lookAhead += min(20.0, $this->estimateBlockHeight($pdf, $blocks[$index + 1]));
            }
            $this->ensureSpace($pdf, $lookAhead);
        }

        private function estimateBlockHeight(FormationPdfDocument $pdf, array $block): float
        {
            $type = (string) ($block['type'] ?? 'paragraph');
            if ($type === 'heading') {
                return 16.0;
            }
            if ($type === 'list') {
                $plain = implode("\n", array_map(fn($item): string => '- ' . $this->plainText((string) $item), (array) ($block['items'] ?? [])));
                return max(12.0, $pdf->getStringHeight(172, $plain) + 5.0);
            }
            if ($type === 'code') {
                $lines = preg_split('/\r\n|\r|\n/', (string) ($block['text'] ?? '')) ?: [];
                return max(16.0, (count($lines) * 4.4) + 8.0);
            }
            if ($type === 'image') {
                return 72.0;
            }
            return max(8.0, $pdf->getStringHeight(178, $this->plainText((string) ($block['text'] ?? ''))) + 2.5);
        }

        private function ensureSpace(FormationPdfDocument $pdf, float $neededHeight): void
        {
            $bottomLimit = $pdf->getPageHeight() - 20;
            if (($pdf->GetY() + $neededHeight) > $bottomLimit) {
                $pdf->AddPage();
            }
        }

        private function resolveImagePath(string $source, string $baseDir): string
        {
            $source = trim($source, " \t\n\r\0\x0B\"'");
            if ($source === '') {
                return '';
            }
            if (preg_match('~^https?://~i', $source)) {
                return $source;
            }

            $appBasePath = trim((string) ($this->context['app_base_path'] ?? ''), '/');
            $webSource = str_replace('\\', '/', $source);
            if ($appBasePath !== '' && strpos(ltrim($webSource, '/'), $appBasePath . '/') === 0) {
                $source = substr(ltrim($webSource, '/'), strlen($appBasePath) + 1);
            }

            $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $source);
            $projectRoot = (string) ($this->context['project_root'] ?? '');
            $candidates = [];

            if (preg_match('~^[A-Za-z]:[\\/]~', $source)) {
                $candidates[] = $source;
            } else {
                if ($baseDir !== '') {
                    $candidates[] = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
                }
                if ($projectRoot !== '') {
                    $candidates[] = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
                    $candidates[] = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, preg_replace('~^/+~', '', $source) ?? $source), DIRECTORY_SEPARATOR);
                }
            }

            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    return $candidate;
                }
            }

            return '';
        }

        private function buildFileName(string $stem): string
        {
            $safeStem = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem) ?: 'formation';
            return $safeStem . '.pdf';
        }

        private function emit(FormationPdfDocument $pdf, string $fileName): void
        {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $pdf->Output($fileName, 'I');
        }
    }
}
