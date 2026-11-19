# FNE — socle d’intégration (ERP Banamur)

Ce document résume les champs à collecter et les règles de validation côté application avant appel à l’API FNE (DGI Côte d’Ivoire) d’après la procédure API (Mai 2025).

## Configuration

Variables d’environnement :
- `FNE_BASE_URL` : URL base, inclure `/ws` (test: `http://54.247.95.108/ws`)
- `FNE_API_KEY` : clé API (Bearer token)
- `FNE_CONNECT_TIMEOUT` : timeout connexion (secondes)
- `FNE_TIMEOUT` : timeout total (secondes)

Fichier : `config/fne.php`

## Endpoints utilisés

- Signature facture / vente / bordereau : `POST {BASE}/external/invoices/sign`
- Avoir (refund) : `POST {BASE}/external/invoices/{id}/refund`
- Vérification facture (achat) : `POST {BASE}/external/invoices/verify`

## Données à collecter (Facturation)

Champs attendus pour `sign` (obligatoires et règles conditionnelles) :

- `invoiceType` : `sale` ou `purchase`
- `paymentMethod` : `cash | card | check | mobile-money | transfer | deferred`
- `template` : `B2B | B2F | B2G | B2C`
  - si `template=B2B` alors `clientNcc` obligatoire
- `isRne` : booléen
  - si `isRne=true` alors `rne` obligatoire
- Client
  - `clientCompanyName` (obligatoire)
  - `clientPhone` (obligatoire)
  - `clientEmail` (obligatoire)
- Vente / établissement
  - `pointOfSale` (obligatoire)
  - `establishment` (obligatoire)
- Devise (si applicable)
  - `foreignCurrency` (optionnel, mais si renseigné doit être dans l’annexe devises)
  - `foreignCurrencyRate` (obligatoire si `foreignCurrency` renseigné)
- Remises
  - `discount` (optionnel, >= 0)
- Lignes
  - `items[]` non vide
  - par item : `description`, `quantity` (>0), `amount` (>=0)
  - taxes : pour `invoiceType=sale` au moins une taxe dans `TVA | TVAB | TVAC | TVAD` (selon annexe)
  - `customTaxes` (optionnel) au niveau item et/ou facture : `{name, amount}`

## Données à collecter (Avoir / Refund)

Champs attendus pour `refund` :
- `items[]` non vide
- par item : `id` (id de ligne FNE), `quantity` (>0)

## Implémentation dans le code

- Client HTTP : `model/FneClient.php`
- Validations : `model/FneValidator.php`

## Endpoints internes (pour l’UI ERP)

Ces endpoints sont protégés par `auth_check.php` et nécessitent `acces_facturation` (ou admin).

- `POST /api/fne_sign_invoice.php` : body JSON = payload `sign` FNE
- `POST /api/fne_refund_invoice.php` : body JSON = `{ invoice_id, items: [...] }`
- `POST /api/fne_verify_invoice.php` : body JSON = `{ invoiceNumber, amount?, date? }` ou `{ verificationCode }`
- `POST /api/fne_verify_decaissement.php` : body JSON = `{ num_fiche, verify: {...}, amount_tolerance?, label_match_threshold?, block_on_label_mismatch? }`

Notes d’usage :
- Valider avec `FneValidator::validateSignInvoicePayload($payload)` avant `FneClient::signInvoice($payload)`.
- Valider avec `FneValidator::validateRefundPayload($payload)` avant `FneClient::refundInvoice($id, $payload)`.
- Valider avec `FneValidator::validateVerifyPayload($payload)` avant `FneClient::verifyInvoice($payload)`.

### Exemples verify

Vérifier une facture (achat):

```json
{
  "invoiceNumber": "FNE123456789",
  "amount": 30000,
  "date": "2026-03-23"
}
```

Ou bien:

```json
{ "verificationCode": "..." }
```

Vérifier une fiche de décaissement (généalogie):

```json
{
  "num_fiche": "FD-2508-06332",
  "verify": {
    "invoiceNumber": "FNE123456789",
    "amount": 30000,
    "date": "2026-03-23"
  },
  "amount_tolerance": 0.01,
  "label_match_threshold": 0.55,
  "block_on_label_mismatch": false
}
```
