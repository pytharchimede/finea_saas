<?php

class AiAnonymizerService
{
    public function anonymize(string $text): array
    {
        $replacements = [];
        $anonymized = $text;

        $patterns = [
            'EMAIL' => '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
            'TELEPHONE' => '/(?<!\d)(?:\+?\d[\d\s().-]{7,}\d)(?!\d)/',
            'REFERENCE' => '/\b(?:FEB|BC|FAC|REF)[\s\-_:]*[A-Z0-9\-\/]{3,}\b/i',
            'MONTANT' => '/\b\d{1,3}(?:[ .]\d{3})*(?:,\d+)?\s*(?:FCFA|XOF|EUR|USD|F|francs?)\b/i',
            'ENTREPRISE' => '/\b[A-Z][A-Za-zÀ-ÿ0-9&.\' -]{2,}\s+(?:SARL|SA|SAS|SCI|SOCIETE|ENTREPRISE|GROUPE|CI)\b/u',
            'PERSONNE' => '/\b[A-ZÀ-Ý][a-zà-ÿ]{2,}\s+[A-ZÀ-Ý][a-zà-ÿ]{2,}\b/u',
        ];

        foreach ($patterns as $label => $pattern) {
            $anonymized = preg_replace_callback($pattern, function (array $match) use (&$replacements, $label): string {
                $placeholder = '[' . $label . ']';
                $replacements[] = [
                    'placeholder' => $placeholder,
                    'original_value' => $match[0],
                ];
                return $placeholder;
            }, $anonymized) ?? $anonymized;
        }

        return [
            'text' => $anonymized,
            'replacements' => $replacements,
        ];
    }

    public function restore(string $text, array $replacements): string
    {
        $restored = $text;
        foreach ($replacements as $replacement) {
            $placeholder = (string)($replacement['placeholder'] ?? '');
            $original = (string)($replacement['original_value'] ?? '');
            if ($placeholder !== '' && $original !== '' && strpos($restored, $placeholder) !== false) {
                $restored = preg_replace('/' . preg_quote($placeholder, '/') . '/', $original, $restored, 1) ?? $restored;
            }
        }
        return $restored;
    }
}
