<?php

require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../model/EntityPositioningManager.php';
require_once __DIR__ . '/../services/SupplierLocationSearch.php';

header('Content-Type: application/json; charset=utf-8');

$action = trim((string) ($_GET['action'] ?? 'search'));
$pdo = \Database::getConnection();
$supplierModel = new \FournisseurRepository($pdo);
$positioningManager = new \EntityPositioningManager($pdo);
$settings = $positioningManager->getSettings('supplier');

if (empty($settings['api_enabled'])) {
    http_response_code(403);
    echo json_encode([
        'ok' => false,
        'error' => 'Le module de géolocalisation est désactivé.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'search') {
    $query = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($query) < 3) {
        echo json_encode([
            'ok' => true,
            'items' => [],
            'fallback_used' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    try {$country=SupplierLocationSearch::country((string)($_GET['country']??'CI'));}
    catch(DomainException $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Pays de recherche invalide.']);exit;}
    if(mb_strlen($query)>160){http_response_code(400);echo json_encode(['ok'=>false,'error'=>'Adresse trop longue (160 caractères maximum).']);exit;}
    $remoteItems = locationLookupRemoteSearch($query, 5, $country);
    $localItems = [];
    $fallbackUsed = false;

    if (!$remoteItems) {
        $fallbackUsed = true;
        $localItems = locationLookupLocalSearch($supplierModel, $query, 5, $country);
    } else {
        $localItems = locationLookupLocalSearch($supplierModel, $query, 3, $country);
    }

    $items = locationLookupMergeItems($remoteItems, $localItems, 6);

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'fallback_used' => $fallbackUsed,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'reverse') {
    $latitude = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float) $_GET['lat'] : null;
    $longitude = isset($_GET['lon']) && is_numeric($_GET['lon']) ? (float) $_GET['lon'] : null;

    if ($latitude === null || $longitude === null) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'Coordonnées invalides.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $item = locationLookupRemoteReverse($latitude, $longitude);
    if (!$item) {
        echo json_encode([
            'ok' => true,
            'item' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'item' => $item,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode([
    'ok' => false,
    'error' => 'Action non supportée.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

function locationLookupRemoteSearch(string $query, int $limit, string $country='CI'): array
{
    return SupplierLocationSearch::search($query,$limit,$country,'locationLookupHttpJson');
}

function locationLookupRemoteReverse(float $latitude, float $longitude): ?array
{
    if(abs($latitude)>90||abs($longitude)>180)return null;
    $url='https://photon.komoot.io/reverse?'.http_build_query(['lat'=>$latitude,'lon'=>$longitude,'lang'=>'fr']);
    $payload=locationLookupHttpJson($url,6);
    return isset($payload['features'][0])?SupplierLocationSearch::normalize($payload['features'][0]):null;
}

function locationLookupLocalSearch(\FournisseurRepository $supplierModel, string $query, int $limit, string $country='CI'): array
{
    $rows = $supplierModel->listLatest(200, false);
    if (!$rows) {
        return [];
    }

    $query = mb_strtolower(trim($query));
    $matches = [];
    foreach ($rows as $row) {
        $rowCountry=strtoupper(trim((string)(($row['pays']??'')?:($row['fiscal_country']??''))));
        if($country!=='' && $rowCountry!==$country)continue;
        $haystackParts = [
            (string) ($row['supplier_display_name'] ?? ''),
            (string) ($row['adresse'] ?? ''),
            (string) ($row['ville'] ?? ''),
            (string) ($row['pays'] ?? ''),
        ];
        $haystack = mb_strtolower(implode(' | ', array_filter($haystackParts, static fn(string $value): bool => trim($value) !== '')));
        if ($haystack === '' || !locationLookupMatchesLocally($query, $haystack)) {
            continue;
        }

        $displayName = trim(implode(', ', array_filter([
            (string) ($row['adresse'] ?? ''),
            (string) ($row['ville'] ?? ''),
            (string) ($row['pays'] ?? ''),
        ], static fn(string $value): bool => trim($value) !== '')));
        if ($displayName === '') {
            continue;
        }

        $matches[] = [
            'display_name' => $displayName,
            'lat' => null,
            'lon' => null,
            'address' => [
                'city' => (string) ($row['ville'] ?? ''),
                'country_code' => strtolower((string) ($row['pays'] ?? 'CI')),
                'country' => (string) ($row['pays'] ?? 'CI'),
            ],
            'source' => 'local',
        ];

        if (count($matches) >= $limit) {
            break;
        }
    }

    return $matches;
}

function locationLookupMatchesLocally(string $query, string $haystack): bool
{
    if (mb_strpos($haystack, $query) !== false) {
        return true;
    }

    $queryCompact = str_replace([' ', '-', ',', '.'], '', locationLookupNormalizeText($query));
    $haystackCompact = locationLookupNormalizeText($haystack);
    if ($queryCompact !== '' && mb_strpos(str_replace([' ', '-', ',', '.'], '', $haystackCompact), $queryCompact) !== false) {
        return true;
    }

    foreach (preg_split('/[^a-z0-9]+/i', $haystackCompact) as $token) {
        if ($token === '') {
            continue;
        }
        if (str_starts_with($token, $queryCompact) || levenshtein($queryCompact, $token) <= 2) {
            return true;
        }
    }

    return false;
}

function locationLookupNormalizeText(string $value): string
{
    $value = trim(mb_strtolower($value));
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($transliterated) && $transliterated !== '') {
        $value = $transliterated;
    }
    return preg_replace('/\s+/', ' ', $value) ?? $value;
}

function locationLookupMergeItems(array $primary, array $secondary, int $limit): array
{
    $merged = [];
    $seen = [];
    foreach (array_merge($primary, $secondary) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $key = mb_strtolower(trim((string) ($item['display_name'] ?? '')));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $item;
        if (count($merged) >= $limit) {
            break;
        }
    }

    return $merged;
}

function locationLookupNormalizeRemoteItem(array $row, ?float $fallbackLatitude = null, ?float $fallbackLongitude = null): ?array
{
    $latitude = isset($row['lat']) && is_numeric($row['lat']) ? (float) $row['lat'] : $fallbackLatitude;
    $longitude = isset($row['lon']) && is_numeric($row['lon']) ? (float) $row['lon'] : $fallbackLongitude;
    $displayName = trim((string) ($row['display_name'] ?? ''));
    if ($displayName === '') {
        return null;
    }

    return [
        'display_name' => $displayName,
        'lat' => $latitude,
        'lon' => $longitude,
        'address' => is_array($row['address'] ?? null) ? $row['address'] : [],
        'source' => 'remote',
    ];
}

function locationLookupHttpJson(string $url, int $timeoutSeconds): ?array
{
    $timeoutSeconds = max(2, min(10, $timeoutSeconds));
    $headers = [
        'User-Agent: BanamurLocationLookup/1.0',
        'Accept: application/json',
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if (!is_string($body) || $body === '' || $code < 200 || $code >= 300) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    $contexts = [
        stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'header' => implode("\r\n", $headers) . "\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]),
    ];

    $body = null;
    foreach ($contexts as $context) {
        $body = @file_get_contents($url, false, $context);
        if (is_string($body) && trim($body) !== '') {
            break;
        }
    }

    if (!is_string($body) || trim($body) === '') {
        return null;
    }

    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

