<?php

declare(strict_types=1);

require_once __DIR__ . '/FidestIaClient.php';

final class FebDocumentComplianceService
{
    public function __construct(private ?FidestIaClient $client = null) { $this->client ??= new FidestIaClient(); }
    public function isConfigured(): bool { return $this->client->isConfigured(); }

    public function check(string $path, string $reference, string $expectedSupplier, float $expectedAmount, array $expectedLines = []): array
    {
        $response = $this->client->analyze($path, $reference);
        $analysis = $response['data']; $values = (array)($analysis['data'] ?? []);
        $supplier = $this->find($values, ['supplier_name','supplier','fournisseur','vendor_name','raison_sociale','nom_fournisseur']);
        $amountRaw = $this->find($values, ['total_ttc','total_amount','montant_total','amount_due','grand_total','total']);
        $number = $this->find($values, ['document_number','invoice_number','numero_document','numero_facture','number']);
        $date = $this->find($values, ['document_date','invoice_date','date_document','date_facture','date']);
        $ocrText = $this->responseOcrText($analysis);
        if ($ocrText === '') $ocrText = $this->localOcrText($path);
        if (($number === null || trim((string) $number) === '') && preg_match('/\bfacture\s*(?:n[°o.]*)?\s*[#:]?\s*([A-Z0-9][A-Z0-9\/-]{2,})/iu', $ocrText, $numberMatch)) {
            $number = $numberMatch[1];
        }
        if (($date === null || trim((string) $date) === '') && preg_match('/(?:date\s+(?:de\s+)?facturation|factur[eé]\s+le)\s*[:\-]?\s*(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})/iu', $ocrText, $dateMatch)) {
            $date = $dateMatch[1];
        }
        $amount = $this->number($amountRaw); $reasons = [];
        $supplierOcrName = $this->supplierInOcr($expectedSupplier, $ocrText);
        $supplierFromOcr = $supplierOcrName !== null;
        $supplierFromFields = $supplier !== null && $this->similar((string)$supplier, $expectedSupplier);
        $supplierMatches = $supplierFromFields || $supplierFromOcr;
        if (!$supplierMatches) {
            $reasons[] = $supplier === null || trim((string)$supplier) === ''
                ? 'Le fournisseur n’a pas pu être identifié sur le document.'
                : 'Le fournisseur lu (' . (string)$supplier . ') ne correspond pas au fournisseur sélectionné (' . $expectedSupplier . ').';
        }
        if ($expectedAmount <= 0) {
            $expectedAmount = array_reduce($expectedLines, function (float $sum, array $line): float {
                $quantity = $this->number($line['quantite'] ?? null) ?? 0;
                $price = $this->number($line['prix_unitaire'] ?? null) ?? 0;
                return $sum + $quantity * $price;
            }, 0.0);
        }
        $tolerance = max(100.0, $expectedAmount * .05);
        $amountFromOcr = $this->matchingOcrAmount($ocrText, $expectedAmount, $tolerance);
        $amountFromFields = $amount !== null && abs($amount - $expectedAmount) <= $tolerance;
        $matchedAmount = $amountFromFields ? $amount : $amountFromOcr;
        if ($expectedAmount <= 0) {
            $reasons[] = 'Le montant de la FEB n’est pas renseigné. Complétez la quantité et le prix de chaque besoin.';
        } elseif ($matchedAmount === null) {
            $reasons[] = $amount === null
                ? 'Le montant total n’a pas pu être identifié sur le document.'
                : 'Le montant lu (' . number_format($amount, 0, ',', ' ') . ' FCFA) ne correspond pas au total de la FEB (' . number_format($expectedAmount, 0, ',', ' ') . ' FCFA).';
        }
        $validationOverridden = isset($analysis['validation']['valid']) && $analysis['validation']['valid'] !== true && $supplierFromOcr && $amountFromOcr !== null;
        if (isset($analysis['validation']['valid']) && $analysis['validation']['valid'] !== true && !$validationOverridden) {
            $reasons[] = 'Le document ne satisfait pas les règles métier configurées dans FIDEST IA.';
        }
        $extractedLines = $this->extractLines($values);
        $lineChecks = $this->compareLines($expectedLines, $extractedLines, $reasons, $ocrText);
        return [
            'accepted' => $reasons === [], 'reasons' => $reasons,
            'summary' => $reasons === [] ? 'Document recevable : fournisseur et montant concordent.' : 'Document non recevable : ' . count($reasons) . ' anomalie(s) détectée(s).',
            'document_uuid' => (string)($analysis['uuid'] ?? ''), 'request_id' => $response['request_id'],
            'document_type' => (string)($analysis['document_type']['code'] ?? ''), 'business_valid' => $analysis['validation']['valid'] ?? null,
            'confidence' => $analysis['scores']['overall'] ?? null,
            'ocr_confidence' => $analysis['ocr']['confidence'] ?? null,
            'evidence' => [
                'supplier' => $supplierFromFields ? 'Champs analysés' : ($supplierFromOcr ? 'Texte OCR' : 'Non confirmé'),
                'amount' => $amountFromFields ? 'Champs analysés' : ($amountFromOcr !== null ? 'Texte OCR' : 'Non confirmé'),
                'validation_overridden' => $validationOverridden,
            ],
            'extracted' => ['supplier' => $supplier, 'supplier_detected' => $supplierFromFields ? $supplier : $supplierOcrName, 'total_amount' => $amount, 'expected_amount' => $expectedAmount, 'matched_supplier_in_ocr' => $supplierFromOcr, 'matched_total_amount' => $matchedAmount, 'matched_total_in_ocr' => $amountFromOcr, 'document_date' => $date, 'document_number' => $number, 'lines' => $extractedLines],
            'ocr_text' => $ocrText,
            'line_checks' => $lineChecks,
            'checked_at' => date('c'),
        ];
    }

    private function responseOcrText(array $analysis): string
    {
        $documentOcr = $analysis['ocr']['text'] ?? null;
        if (is_string($documentOcr) && trim($documentOcr) !== '') return trim($documentOcr);
        $sources = [$analysis, $analysis['ocr'] ?? null, $analysis['extraction'] ?? null, $analysis['data'] ?? null];
        foreach ($sources as $source) {
            if (!is_array($source)) continue;
            foreach (['ocr_text', 'raw_text', 'full_text', 'extracted_text', 'text'] as $key) {
                if (isset($source[$key]) && is_string($source[$key]) && trim($source[$key]) !== '') {
                    return trim($source[$key]);
                }
            }
        }
        return '';
    }

    private function localOcrText(string $path): string
    {
        if (!function_exists('proc_open')) return '';
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if ($mime === 'application/pdf') {
            $text = $this->runTextCommand(['/usr/bin/pdftotext', '-layout', '-enc', 'UTF-8', '-f', '1', '-l', '12', $path, '-']);
            if (trim($text) !== '') return trim($text);
            if (!is_executable('/usr/bin/pdftoppm')) return '';
            $directory = sys_get_temp_dir() . '/feb_ocr_' . bin2hex(random_bytes(8));
            if (!mkdir($directory, 0700)) return '';
            try {
                $this->runTextCommand(['/usr/bin/pdftoppm', '-f', '1', '-l', '5', '-scale-to', '1800', '-png', $path, $directory . '/page']);
                $pages = glob($directory . '/page-*.png') ?: [];
                sort($pages, SORT_NATURAL);
                $parts = [];
                foreach ($pages as $page) {
                    $part = $this->runTextCommand(['/usr/bin/tesseract', $page, 'stdout', '-l', 'fra+eng']);
                    if (trim($part) === '') $part = $this->runTextCommand(['/usr/bin/tesseract', $page, 'stdout', '-l', 'eng']);
                    if (trim($part) !== '') $parts[] = trim($part);
                }
                return implode("\n\n", $parts);
            } finally {
                foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
                @rmdir($directory);
            }
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return '';
        $text = $this->runTextCommand(['/usr/bin/tesseract', $path, 'stdout', '-l', 'fra+eng']);
        return trim($text) !== '' ? trim($text) : trim($this->runTextCommand(['/usr/bin/tesseract', $path, 'stdout', '-l', 'eng']));
    }

    private function runTextCommand(array $command): string
    {
        if (!is_executable($command[0])) return '';
        if (is_executable('/usr/bin/timeout')) array_unshift($command, '/usr/bin/timeout', '30');
        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) return '';
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1], 120000) ?: '';
        fclose($pipes[1]);
        stream_get_contents($pipes[2], 4096);
        fclose($pipes[2]);
        proc_close($process);
        return trim($output);
    }

    private function find(array $data, array $aliases): mixed
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string)$key), $aliases, true)) {
                if (!is_array($value)) return $value;
                foreach (['value','raw_value','text','normalized_value'] as $valueKey) if (isset($value[$valueKey]) && !is_array($value[$valueKey])) return $value[$valueKey];
            }
            if (is_array($value)) { $found = $this->find($value, $aliases); if ($found !== null && $found !== '') return $found; }
        }
        return null;
    }
    private function extractLines(array $data): array
    {
        $items=$this->findArray($data,['line_items','items','lignes','products','produits']);$out=[];
        foreach($items as $item){if(!is_array($item))continue;$name=$this->find($item,['designation','description','product_name','item_name','libelle','name']);if(trim((string)$name)==='')continue;$out[]=['designation'=>(string)$name,'type'=>(string)($this->find($item,['type','product_type','categorie','category'])??''),'quantity'=>$this->number($this->find($item,['quantity','quantite','qty'])),'unit_price'=>$this->number($this->find($item,['unit_price','prix_unitaire','price'])),'total'=>$this->number($this->find($item,['line_total','total','montant']))];}return $out;
    }
    private function findArray(array $data,array $aliases): array
    {
        foreach($data as $key=>$value){if(in_array(strtolower((string)$key),$aliases,true)&&is_array($value)&&array_is_list($value))return $value;if(is_array($value)){$found=$this->findArray($value,$aliases);if($found)return $found;}}return [];
    }
    private function supplierInOcr(string $expectedSupplier, string $ocrText): ?string
    {
        if ($ocrText === '') return null;
        $normalize = static fn(string $text): string => strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
        $tokens = preg_split('/[^a-z0-9]+/', $normalize($expectedSupplier)) ?: [];
        $generic = ['server', 'serveur', 'vps', 'service', 'services', 'societe', 'fournisseur', 'entreprise', 'sarl', 'eurl', 'ivoire'];
        $tokens = array_values(array_filter($tokens, static fn(string $token): bool => strlen($token) >= 3 && !in_array($token, $generic, true)));
        if (!$tokens) return null;
        $document = $normalize($ocrText);
        $compact = preg_replace('/[^a-z0-9]/', '', $document) ?? '';
        $brand = implode('', $tokens);
        if (strlen($brand) >= 5 && str_contains($compact, $brand)) return strtoupper(implode(' ', $tokens));
        usort($tokens, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($tokens as $token) {
            if (strlen($token) >= 5 && str_contains($compact, $token)) return strtoupper($token);
        }
        $ocrTokens = preg_split('/[^a-z0-9]+/', $document) ?: [];
        foreach ($tokens as $token) {
            if (strlen($token) < 6) continue;
            foreach ($ocrTokens as $word) {
                if (abs(strlen($word) - strlen($token)) > 1) continue;
                if (levenshtein($word, $token) <= max(1, (int) floor(strlen($token) * .15))) return strtoupper($token);
            }
        }
        return null;
    }

    private function matchingOcrAmount(string $ocrText, float $expectedAmount, float $tolerance): ?float
    {
        $best = null;
        foreach (preg_split('/\R/u', $ocrText) ?: [] as $line) {
            if (!preg_match('/(?:f\s*cfa|xof|montant|total|solde|prix)/iu', $line)) continue;
            preg_match_all('/(?<!\d)(?:\d{1,3}(?:[ .\x{00A0}\x{202F}]\d{3})+|\d{3,})(?:[,.]\d{1,2})?(?!\d)/u', $line, $matches);
            foreach ($matches[0] as $raw) {
                $candidate = $this->number($raw);
                if ($candidate === null || abs($candidate - $expectedAmount) > $tolerance) continue;
                if ($best === null || abs($candidate - $expectedAmount) < abs($best - $expectedAmount)) $best = $candidate;
            }
        }
        return $best;
    }

    private function lineInOcr(string $name, string $ocrText): bool
    {
        if ($ocrText === '') return false;
        $normalize = static fn(string $text): string => strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
        $tokens = preg_split('/[^a-z0-9]+/', $normalize($name)) ?: [];
        $tokens = array_values(array_unique(array_filter($tokens, static fn(string $token): bool => strlen($token) >= 5)));
        if (!$tokens) return false;
        $document = $normalize($ocrText);
        $found = count(array_filter($tokens, static fn(string $token): bool => str_contains($document, $token)));
        return $found >= max(1, (int) ceil(count($tokens) * .7));
    }

    private function compareLines(array $expected, array $actual, array &$reasons, string $ocrText = ''): array
    {
        if (!$expected) return [];
        $checks = []; $used = [];
        foreach ($expected as $line) {
            $name = trim((string) ($line['libelle'] ?? $line['designation'] ?? ''));
            if ($name === '') continue;
            $matchIndex = null;
            foreach ($actual as $i => $candidate) {
                if (!isset($used[$i]) && $this->similar((string) ($candidate['designation'] ?? ''), $name)) { $matchIndex = $i; break; }
            }
            if ($matchIndex === null) {
                $matchedInOcr = $this->lineInOcr($name, $ocrText);
                if (!$matchedInOcr) $reasons[] = 'La ligne « ' . $name . ' » du devis n’a pas été retrouvée sur le justificatif.';
                $checks[] = ['expected' => $name, 'matched' => $matchedInOcr, 'actual' => $matchedInOcr ? 'Texte OCR' : null, 'issues' => []];
                continue;
            }
            $used[$matchIndex] = true;
            $candidate = $actual[$matchIndex]; $issues = [];
            $qty = $this->number($line['quantite'] ?? null);
            if ($qty !== null && $candidate['quantity'] !== null && abs($qty - $candidate['quantity']) > .001) $issues[] = 'quantité différente';
            $expectedType = mb_strtolower(trim((string) ($line['type'] ?? '')));
            $actualType = mb_strtolower(trim((string) ($candidate['type'] ?? '')));
            if ($expectedType !== '' && $actualType !== '' && !$this->similar($actualType, $expectedType)) $issues[] = 'type de produit/service différent';
            $expectedLineTotal = $this->number($line['montant'] ?? null);
            if ($expectedLineTotal === null) {
                $unit = $this->number($line['prix_unitaire'] ?? ($line['fournisseur']['prix_unitaire'] ?? null));
                $expectedLineTotal = $unit !== null && $qty !== null ? $unit * $qty : null;
            }
            if ($expectedLineTotal !== null && $candidate['total'] !== null && abs($expectedLineTotal - $candidate['total']) > max(100, $expectedLineTotal * .05)) $issues[] = 'montant de ligne différent';
            if ($issues) $reasons[] = 'Ligne « ' . $name . ' » : ' . implode(' et ', $issues) . '.';
            $checks[] = ['expected' => $name, 'matched' => true, 'actual' => $candidate['designation'], 'issues' => $issues];
        }
        return $checks;
    }
    private function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) return (float)$value;
        if (!is_string($value)) return null;
        $clean = preg_replace('/[^0-9,.-]/', '', str_replace(["\xc2\xa0", ' '], '', $value));
        if ($clean === null || $clean === '') return null;
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = strrpos($clean, ',') > strrpos($clean, '.')
                ? str_replace(',', '.', str_replace('.', '', $clean))
                : str_replace(',', '', $clean);
        } elseif (str_contains($clean, ',')) {
            $clean = preg_match('/,\d{1,2}$/', $clean) ? str_replace(',', '.', $clean) : str_replace(',', '', $clean);
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $clean)) {
            $clean = str_replace('.', '', $clean);
        }
        return is_numeric($clean) ? (float)$clean : null;
    }
    private function similar(string $actual, string $expected): bool
    {
        $normalize = static function(string $v): string { $v = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtolower($v)) ?: mb_strtolower($v); return trim(preg_replace('/\b(sarl|sa|sas|eurl|ci|cote d ivoire)\b|[^a-z0-9]/', '', $v) ?? ''); };
        $a=$normalize($actual); $e=$normalize($expected); if ($a===''||$e==='') return false;
        if (str_contains($a,$e)||str_contains($e,$a)) return true;
        similar_text($a,$e,$percent); return $percent >= 78;
    }
}
