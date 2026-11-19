<?php

/**
 * Validation des payloads FNE basée sur la procédure API (Mai 2025).
 *
 * Objectif: bloquer côté ERP les envois non conformes (champs requis/valeurs autorisées)
 * avant appel à l'API FNE.
 */
class FneValidator
{
    public const INVOICE_TYPES = ['sale', 'purchase'];
    public const PAYMENT_METHODS = ['cash', 'card', 'check', 'mobile-money', 'transfer', 'deferred'];
    public const TEMPLATES = ['B2B', 'B2F', 'B2G', 'B2C'];
    public const TAXES = ['TVA', 'TVAB', 'TVAC', 'TVAD'];

    // Annexe (PDF): foreignCurrency (codes)
    public const FOREIGN_CURRENCIES = ['XOF', 'USD', 'EUR', 'JPY', 'CAD', 'GBP', 'AUD', 'CNH', 'CHF', 'HKD', 'NZD'];

    /**
     * @return array{ok:bool, errors:string[]}
     */
    public static function validateSignInvoicePayload(array $payload): array
    {
        $errors = [];

        $invoiceType = self::asString($payload['invoiceType'] ?? null);
        if ($invoiceType === '' || !in_array($invoiceType, self::INVOICE_TYPES, true)) {
            $errors[] = 'invoiceType requis (sale | purchase).';
        }

        $paymentMethod = self::asString($payload['paymentMethod'] ?? null);
        if ($paymentMethod === '' || !in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            $errors[] = 'paymentMethod requis (cash | card | check | mobile-money | transfer | deferred).';
        }

        $template = self::asString($payload['template'] ?? null);
        if ($template === '' || !in_array($template, self::TEMPLATES, true)) {
            $errors[] = 'template requis (B2B | B2F | B2G | B2C).';
        }

        $isRne = $payload['isRne'] ?? null;
        if (!is_bool($isRne)) {
            $errors[] = 'isRne requis (boolean true/false).';
        } elseif ($isRne === true) {
            $rne = self::asString($payload['rne'] ?? null);
            if ($rne === '') {
                $errors[] = 'rne requis si isRne=true.';
            }
        }

        if ($template === 'B2B') {
            $clientNcc = self::asString($payload['clientNcc'] ?? null);
            if ($clientNcc === '') {
                $errors[] = 'clientNcc requis si template=B2B.';
            }
        }

        $clientCompanyName = self::asString($payload['clientCompanyName'] ?? null);
        if ($clientCompanyName === '') {
            $errors[] = 'clientCompanyName requis.';
        }

        $clientPhone = $payload['clientPhone'] ?? null;
        if (($template!=='B2F' || self::asString($clientPhone)!=='') && !self::isNonEmptyPhone($clientPhone)) {
            $errors[] = 'clientPhone requis (numéro de téléphone).';
        }

        $clientEmail = self::asString($payload['clientEmail'] ?? null);
        if (($template!=='B2F' || $clientEmail!=='') && !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'clientEmail requis (email valide).';
        }

        $pointOfSale = self::asString($payload['pointOfSale'] ?? null);
        if ($pointOfSale === '') {
            $errors[] = 'pointOfSale requis.';
        }

        $establishment = self::asString($payload['establishment'] ?? null);
        if ($establishment === '') {
            $errors[] = 'establishment requis.';
        }

        // Devises: obligatoires pour B2F avec devise différente
        $foreignCurrency = self::asString($payload['foreignCurrency'] ?? null);
        $foreignCurrencyRate = $payload['foreignCurrencyRate'] ?? null;
        if ($foreignCurrency !== '') {
            if (!in_array($foreignCurrency, self::FOREIGN_CURRENCIES, true)) {
                $errors[] = 'foreignCurrency invalide (voir annexe devises).';
            }

            if (!is_numeric($foreignCurrencyRate)) {
                $errors[] = 'foreignCurrencyRate requis si foreignCurrency est renseigné.';
            } else {
                $rate = (float)$foreignCurrencyRate;
                if ($rate <= 0) {
                    $errors[] = 'foreignCurrencyRate doit être > 0 si foreignCurrency est renseigné.';
                }
            }
        } else {
            // si vide: accepter 0 ou null
            if ($foreignCurrencyRate !== null && $foreignCurrencyRate !== '' && is_numeric($foreignCurrencyRate)) {
                // ok
            }
        }

        // Items
        $items = $payload['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            $errors[] = 'items requis (liste non vide).';
        } else {
            foreach ($items as $idx => $item) {
                if (!is_array($item)) {
                    $errors[] = 'items[' . $idx . '] doit être un objet.';
                    continue;
                }

                $desc = self::asString($item['description'] ?? null);
                if ($desc === '') {
                    $errors[] = 'items[' . $idx . '].description requis.';
                }

                if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (float)$item['quantity'] <= 0) {
                    $errors[] = 'items[' . $idx . '].quantity requis (>0).';
                }

                if (!isset($item['amount']) || !is_numeric($item['amount']) || (float)$item['amount'] < 0) {
                    $errors[] = 'items[' . $idx . '].amount requis (prix unitaire HT >=0).';
                }

                if (isset($item['discount']) && $item['discount'] !== '' && (!is_numeric($item['discount']) || (float)$item['discount'] < 0)) {
                    $errors[] = 'items[' . $idx . '].discount doit être >=0.';
                }

                // TVA: obligatoire pour sale d'après doc (le PDF mentionne taxes obligatoire)
                if ($invoiceType === 'sale') {
                    $taxes = $item['taxes'] ?? null;
                    $taxList = [];
                    if (is_string($taxes)) {
                        $taxList = [$taxes];
                    } elseif (is_array($taxes)) {
                        $taxList = $taxes;
                    }

                    $taxList = array_values(array_filter(array_map('strval', $taxList), static function ($v) {
                        return trim($v) !== '';
                    }));

                    if (!$taxList) {
                        $errors[] = 'items[' . $idx . '].taxes requis pour invoiceType=sale.';
                    } else {
                        foreach ($taxList as $t) {
                            if (!in_array($t, self::TAXES, true)) {
                                $errors[] = 'items[' . $idx . '].taxes contient une valeur invalide (' . $t . ').';
                            }
                        }
                    }
                }

                // Custom taxes (optionnel)
                $customTaxes = $item['customTaxes'] ?? null;
                if (is_array($customTaxes)) {
                    foreach ($customTaxes as $tIdx => $tx) {
                        if (!is_array($tx)) {
                            $errors[] = 'items[' . $idx . '].customTaxes[' . $tIdx . '] doit être un objet.';
                            continue;
                        }
                        $name = self::asString($tx['name'] ?? null);
                        if ($name === '') {
                            $errors[] = 'items[' . $idx . '].customTaxes[' . $tIdx . '].name requis.';
                        }
                        if (!isset($tx['amount']) || !is_numeric($tx['amount'])) {
                            $errors[] = 'items[' . $idx . '].customTaxes[' . $tIdx . '].amount requis (nombre).';
                        }
                    }
                }
            }
        }

        // Custom taxes au niveau facture (optionnel)
        $customTaxes = $payload['customTaxes'] ?? null;
        if (is_array($customTaxes)) {
            foreach ($customTaxes as $idx => $tx) {
                if (!is_array($tx)) {
                    $errors[] = 'customTaxes[' . $idx . '] doit être un objet.';
                    continue;
                }
                $name = self::asString($tx['name'] ?? null);
                if ($name === '') {
                    $errors[] = 'customTaxes[' . $idx . '].name requis.';
                }
                if (!isset($tx['amount']) || !is_numeric($tx['amount'])) {
                    $errors[] = 'customTaxes[' . $idx . '].amount requis (nombre).';
                }
            }
        }

        // Remise globale (optionnelle)
        if (isset($payload['discount']) && $payload['discount'] !== '' && (!is_numeric($payload['discount']) || (float)$payload['discount'] < 0)) {
            $errors[] = 'discount (global) doit être >=0.';
        }

        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{ok:bool, errors:string[]}
     */
    public static function validateRefundPayload(array $payload): array
    {
        $errors = [];

        $items = $payload['items'] ?? null;
        if (!is_array($items) || count($items) === 0) {
            $errors[] = 'items requis (liste non vide).';
        } else {
            foreach ($items as $idx => $item) {
                if (!is_array($item)) {
                    $errors[] = 'items[' . $idx . '] doit être un objet.';
                    continue;
                }
                $id = self::asString($item['id'] ?? null);
                if ($id === '') {
                    $errors[] = 'items[' . $idx . '].id requis.';
                }
                if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (float)$item['quantity'] <= 0) {
                    $errors[] = 'items[' . $idx . '].quantity requis (>0).';
                }
            }
        }

        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
        ];
    }

    /**
     * Vérification de facture (achat) — payload souple.
     *
     * On exige au moins un identifiant : invoiceNumber OU verificationCode.
     * Le reste (amount/date/...) dépend souvent de l'implémentation FNE.
     *
     * @return array{ok:bool, errors:string[]}
     */
    public static function validateVerifyPayload(array $payload): array
    {
        $errors = [];

        $invoiceNumber = self::asString($payload['invoiceNumber'] ?? $payload['invoice_number'] ?? null);
        $verificationCode = self::asString($payload['verificationCode'] ?? $payload['verification_code'] ?? null);

        if ($invoiceNumber === '' && $verificationCode === '') {
            $errors[] = 'invoiceNumber ou verificationCode requis.';
        }

        if (array_key_exists('amount', $payload) && $payload['amount'] !== null && $payload['amount'] !== '') {
            if (!is_numeric($payload['amount']) || (float)$payload['amount'] < 0) {
                $errors[] = 'amount doit être un nombre >= 0.';
            }
        }

        $date = self::asString($payload['date'] ?? null);
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $errors[] = 'date doit être au format YYYY-MM-DD.';
        }

        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
        ];
    }

    private static function asString($value): string
    {
        if ($value === null) {
            return '';
        }
        return trim((string)$value);
    }

    private static function isNonEmptyPhone($value): bool
    {
        if ($value === null) {
            return false;
        }

        $s = is_string($value) ? trim($value) : (is_numeric($value) ? (string)$value : '');
        if ($s === '') {
            return false;
        }

        // tolère + et espaces, mais exige au moins 6 chiffres
        $digits = preg_replace('/\D+/', '', $s);
        return is_string($digits) && strlen($digits) >= 6;
    }
}

