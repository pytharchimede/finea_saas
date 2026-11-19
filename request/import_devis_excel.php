<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/ZipArchiveCompatibility.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');

function json_error(string $message, int $statusCode = 400, array $extra = []): void
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra));
    exit;
}

function normalize_string(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    // Supprime les accents
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    $value = strtolower($value);
    // Normalise espaces et ponctuation
    $value = preg_replace('/[^a-z0-9]+/i', '', $value);
    return $value ?? '';
}

function parse_number($value): float
{
    if ($value === null) {
        return 0.0;
    }
    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }
    $v = trim((string)$value);
    if ($v === '') {
        return 0.0;
    }

    // Retire espaces (y compris NBSP)
    $v = str_replace(["\xc2\xa0", ' '], '', $v);

    // Retire les libellés de devise et caractères non numériques (ex: "16 000 CFA")
    // On conserve chiffres, séparateurs décimaux/milliers, et le signe '-'.
    $v = preg_replace('/[^0-9,\.\-]/', '', $v) ?? '';
    if ($v === '' || $v === '-' || $v === '.' || $v === ',') {
        return 0.0;
    }

    // Détecte les formats type 1.234,56 ou 1,234.56
    if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $v)) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } elseif (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $v)) {
        $v = str_replace(',', '', $v);
    } else {
        // Le plus courant en FR: virgule décimale
        $v = str_replace(',', '.', $v);
    }

    if (!is_numeric($v)) {
        return 0.0;
    }
    return (float)$v;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    \AuditTrail::log('devis_excel_import_failed', [
        'module' => 'request',
        'action' => 'import_devis_excel',
        'entity_type' => 'devis_import',
        'entity_label' => 'import_devis_excel',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ], null);
    json_error('Méthode non autorisée', 405);
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    \AuditTrail::log('devis_excel_import_failed', [
        'module' => 'request',
        'action' => 'import_devis_excel',
        'entity_type' => 'devis_import',
        'entity_label' => 'import_devis_excel',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_file'],
    ], null);
    json_error('Fichier manquant (champ file).');
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    \AuditTrail::log('devis_excel_import_failed', [
        'module' => 'request',
        'action' => 'import_devis_excel',
        'entity_type' => 'devis_import',
        'entity_label' => 'import_devis_excel',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'upload_error', 'upload_error' => $file['error'] ?? null],
    ], null);
    json_error('Erreur upload fichier.', 400, ['upload_error' => $file['error'] ?? null]);
}

$tmpPath = $file['tmp_name'] ?? '';
$originalName = (string)($file['name'] ?? '');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

$allowed = ['xlsx', 'xls', 'csv'];
if (!in_array($ext, $allowed, true)) {
    \AuditTrail::log('devis_excel_import_failed', [
        'module' => 'request',
        'action' => 'import_devis_excel',
        'entity_type' => 'devis_import',
        'entity_label' => $originalName !== '' ? $originalName : 'import_devis_excel',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'unsupported_extension', 'extension' => $ext],
    ], null);
    json_error('Format non supporté. Formats acceptés: .xlsx, .xls, .csv');
}

// Charge la liste des unités pour mapper unite -> id
$pdo = Database::getConnection();
$units = $pdo->query('SELECT id, symbole, libelle FROM unites_mesure')->fetchAll(PDO::FETCH_ASSOC);
$unitById = [];
$unitLookup = [];
foreach ($units as $u) {
    $id = (int)$u['id'];
    $unitById[$id] = true;

    $sym = (string)($u['symbole'] ?? '');
    $lib = (string)($u['libelle'] ?? '');

    $ns = normalize_string($sym);
    $nl = normalize_string($lib);

    if ($ns !== '') {
        $unitLookup[$ns] = $id;
    }
    if ($nl !== '') {
        $unitLookup[$nl] = $id;
    }
    // "libelle (symbole)" comme dans l'UI
    if ($nl !== '' && $ns !== '') {
        $unitLookup[$nl . $ns] = $id;
        $unitLookup[$ns . $nl] = $id;
    }
}

try {
    $inputFileType = IOFactory::identify($tmpPath);
    $reader = IOFactory::createReader($inputFileType);
    $reader->setReadDataOnly(true);

    if (method_exists($reader, 'setDelimiter') && $ext === 'csv') {
        // Tentative simple, PhpSpreadsheet auto-détecte souvent
        // On laisse par défaut
    }

    $spreadsheet = $reader->load($tmpPath);
    $sheet = $spreadsheet->getSheet(0);

    $rows = $sheet->toArray(null, true, true, true);
    if (!$rows || count($rows) === 0) {
        json_error('Fichier vide ou illisible.');
    }

    // Trouver la ligne d'en-tête (première ligne avec au moins 2 cellules non vides)
    $headerRowIndex = null;
    foreach ($rows as $i => $row) {
        $nonEmpty = 0;
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') {
                $nonEmpty++;
            }
        }
        if ($nonEmpty >= 2) {
            $headerRowIndex = $i;
            break;
        }
    }

    if ($headerRowIndex === null) {
        json_error('Impossible de détecter l\'en-tête.');
    }

    $header = $rows[$headerRowIndex];
    $colMap = [];
    $detectedHeaders = [];
    $detectedNormalized = [];
    foreach ($header as $col => $label) {
        $labelStr = trim((string)$label);
        if ($labelStr !== '') {
            $detectedHeaders[] = $labelStr;
        }

        $key = normalize_string((string)$label);
        if ($key === '') {
            continue;
        }

        $detectedNormalized[] = $key;

        // Plusieurs synonymes possibles
        if (in_array($key, ['designation', 'desi', 'libelle', 'article', 'libelleduprix', 'libelleprix', 'libelleduproduit', 'libelleproduit'], true)) {
            $colMap['designation'] = $col;
        } elseif (in_array($key, ['unite', 'unit', 'u'], true)) {
            $colMap['unite'] = $col;
        } elseif (in_array($key, ['quantite', 'qte', 'qty'], true)) {
            $colMap['quantite'] = $col;
        } elseif (in_array($key, ['prixunitaire', 'prix', 'pu', 'unitprice'], true)) {
            $colMap['prix'] = $col;
        } elseif (in_array($key, ['groupe', 'group', 'lot', 'categorie'], true)) {
            $colMap['groupe'] = $col;
        } elseif (in_array($key, ['total', 'prixtotal', 'montant'], true)) {
            $colMap['total'] = $col;
        }
    }

    $required = ['designation', 'quantite', 'prix'];
    $missing = [];
    foreach ($required as $req) {
        if (!isset($colMap[$req])) {
            $missing[] = $req;
        }
    }

    if ($missing) {
        $friendly = [
            'designation' => 'Désignation',
            'quantite' => 'Quantité',
            'prix' => 'Prix Unitaire',
        ];

        $missingLabels = array_map(static fn($k) => $friendly[$k] ?? $k, $missing);
        json_error(
            'Colonnes requises manquantes. Manquantes: ' . implode(', ', $missingLabels) . '. Attendu au minimum: Désignation, Quantité, Prix Unitaire.',
            400,
            [
                'missing' => $missing,
                'detected_headers' => array_values(array_unique($detectedHeaders)),
                'detected_headers_normalized' => array_values(array_unique($detectedNormalized)),
                'accepted_examples' => [
                    'Désignation' => ['Désignation', 'Libellé', 'Article', 'Libellé du prix'],
                    'Quantité' => ['Quantité', 'QTE'],
                    'Prix Unitaire' => ['Prix Unitaire', 'PU'],
                    'Unité (optionnel)' => ['Unité', 'UNITE'],
                ],
            ]
        );
    }

    $lignes = [];
    $warnings = [];
    $seenLines = [];

    for ($i = $headerRowIndex + 1; $i <= count($rows); $i++) {
        if (!isset($rows[$i])) {
            continue;
        }

        $row = $rows[$i];
        $designation = trim((string)($row[$colMap['designation']] ?? ''));
        $quantiteRaw = $row[$colMap['quantite']] ?? null;
        $prixRaw = $row[$colMap['prix']] ?? null;

        $hasAny = ($designation !== '')
            || trim((string)$quantiteRaw) !== ''
            || trim((string)$prixRaw) !== '';

        if (!$hasAny) {
            continue;
        }

        if ($designation === '') {
            $warnings[] = "Ligne {$i}: désignation vide (ignorée).";
            continue;
        }

        $quantite = parse_number($quantiteRaw);
        $prix = parse_number($prixRaw);

        if ($quantite <= 0) {
            $warnings[] = "Ligne {$i}: quantité invalide (" . (string)$quantiteRaw . ") (ignorée).";
            continue;
        }

        $uniteId = '';
        $uniteRaw = '';
        if (isset($colMap['unite'])) {
            $uniteRaw = trim((string)($row[$colMap['unite']] ?? ''));
            if ($uniteRaw !== '') {
                if (ctype_digit($uniteRaw) && isset($unitById[(int)$uniteRaw])) {
                    $uniteId = (string)((int)$uniteRaw);
                } else {
                    $nu = normalize_string($uniteRaw);
                    if (isset($unitLookup[$nu])) {
                        $uniteId = (string)$unitLookup[$nu];
                    } else {
                        // Tentative "libelle (symbole)"
                        $nu2 = normalize_string(str_replace(['(', ')'], '', $uniteRaw));
                        if ($nu2 !== '' && isset($unitLookup[$nu2])) {
                            $uniteId = (string)$unitLookup[$nu2];
                        } else {
                            $warnings[] = "Ligne {$i}: unité inconnue ({$uniteRaw}). À sélectionner manuellement.";
                        }
                    }
                }
            }
        }

        $groupe = '';
        if (isset($colMap['groupe'])) {
            $groupe = trim((string)($row[$colMap['groupe']] ?? ''));
        }

        $total = null;
        if (isset($colMap['total'])) {
            $totalVal = $row[$colMap['total']] ?? null;
            $totalParsed = parse_number($totalVal);
            if ($totalParsed > 0) {
                $total = $totalParsed;
            }
        }
        if ($total === null) {
            $total = $prix * $quantite;
        }

        $dedupeKey=hash('sha256',normalize_string($designation).'|'.$uniteId.'|'.number_format($quantite,6,'.','').'|'.number_format($prix,2,'.','').'|'.normalize_string($groupe));
        if(isset($seenLines[$dedupeKey])){$warnings[]="Ligne {$i}: doublon exact de la ligne {$seenLines[$dedupeKey]} (ignorée).";continue;}
        $seenLines[$dedupeKey]=$i;
        $lignes[] = [
            'designation' => $designation,
            'unite_id' => $uniteId,
            'unite_raw' => $uniteRaw,
            'quantite' => $quantite,
            'prix' => $prix,
            'total' => $total,
            'groupe' => $groupe,
        ];
    }

    \AuditTrail::log('devis_excel_import_parsed', [
        'module' => 'request',
        'action' => 'import_devis_excel',
        'entity_type' => 'devis_import',
        'entity_label' => $originalName !== '' ? $originalName : 'import_devis_excel',
        'details' => [
            'extension' => $ext,
            'line_count' => count($lignes),
            'warning_count' => count($warnings),
            'detected_header_row' => $headerRowIndex,
        ],
    ], $pdo);

    echo json_encode([
        'ok' => true,
        'count' => count($lignes),
        'warnings' => $warnings,
        'lignes' => $lignes,
    ]);
} catch (Throwable $e) {
    $msg = (string)$e->getMessage();

    \AuditTrail::log('devis_excel_import_failed', [
        'module' => 'request',
        'action' => 'import_devis_excel',
        'entity_type' => 'devis_import',
        'entity_label' => isset($originalName) && $originalName !== '' ? $originalName : 'import_devis_excel',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'parser_exception', 'error' => $msg, 'extension' => $ext ?? null],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);

    json_error('Erreur lors de la lecture du fichier: ' . $msg, 500);
}
