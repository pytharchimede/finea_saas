<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/ProjectPlanningImport.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

function project_set_flash(string $type, string $message): void
{
    $_SESSION['planning_import_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function project_redirect_to_chantier(int $chantierId): void
{
    header('Location: ../detail_travaux_chantier.php?chantier_id=' . urlencode((string)$chantierId));
    exit;
}

function project_parse_date_value($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        try {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$value);
            if ($date instanceof DateTimeInterface) {
                $date = DateTimeImmutable::createFromInterface($date);
                return $date->format('Y-m-d');
            }
        } catch (Throwable $e) {
        }
    }

    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    $text = preg_replace('/^(lun|mar|mer|jeu|ven|sam|dim)\.?\s+/iu', '', $text) ?? $text;
    $text = trim($text);

    $formats = ['d/m/Y', 'd/m/y', 'Y-m-d', 'd-m-Y', 'd-m-y'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $text);
        if ($date instanceof DateTimeImmutable) {
            return project_normalize_parsed_date($date, $text)->format('Y-m-d');
        }
    }

    $timestamp = strtotime($text);
    if ($timestamp !== false) {
        $date = (new DateTimeImmutable())->setTimestamp($timestamp);
        return project_normalize_parsed_date($date, $text)->format('Y-m-d');
    }

    return null;
}

function project_normalize_parsed_date(DateTimeImmutable $date, string $sourceText): DateTimeImmutable
{
    $trimmedSource = trim($sourceText);
    $hasTwoDigitYear = (bool)preg_match('/(?:^|[\/\-.\s])(\d{2})$/', $trimmedSource);
    $year = (int)$date->format('Y');

    if ($year >= 0 && $year < 1000) {
        $normalizedDate = $date->setDate(
            2000 + $year,
            (int)$date->format('m'),
            (int)$date->format('d')
        );
        if ($normalizedDate instanceof DateTimeImmutable) {
            return $normalizedDate;
        }
    }

    if ($hasTwoDigitYear && $year >= 1900 && $year < 2000) {
        $shiftedDate = $date->modify('+100 years');
        if ($shiftedDate instanceof DateTimeImmutable) {
            return $shiftedDate;
        }
    }

    return $date;
}
function project_clean_name(string $value): string
{
    $name = str_replace(["\t", "\r", "\n"], ' ', $value);
    $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
    return trim($name);
}

function project_parse_text_line(string $line): ?array
{
    $line = trim($line);
    if ($line === '') {
        return null;
    }

    $parts = preg_split('/\t+/u', $line) ?: [];
    if (count($parts) >= 4) {
        return [
            'task_name' => (string)($parts[0] ?? ''),
            'start_date' => (string)($parts[1] ?? ''),
            'end_date' => (string)($parts[2] ?? ''),
            'duration_label' => (string)($parts[3] ?? ''),
            'predecessors_label' => (string)($parts[4] ?? ''),
        ];
    }

    if (preg_match('/^(?<name>.+?)\s+(?<start>(?:Lun|Mar|Mer|Jeu|Ven|Sam|Dim)\s+\d{2}\/\d{2}\/\d{2,4})\s+(?<end>(?:Lun|Mar|Mer|Jeu|Ven|Sam|Dim)\s+\d{2}\/\d{2}\/\d{2,4})\s+(?<duration>\d+\s+jours?|\d+\s+jour)\s*(?<pred>.*)$/u', $line, $matches)) {
        return [
            'task_name' => (string)($matches['name'] ?? ''),
            'start_date' => (string)($matches['start'] ?? ''),
            'end_date' => (string)($matches['end'] ?? ''),
            'duration_label' => (string)($matches['duration'] ?? ''),
            'predecessors_label' => (string)($matches['pred'] ?? ''),
        ];
    }

    return null;
}

function project_parse_rows_from_text(string $text): array
{
    $rows = [];
    $lines = preg_split('/\r\n|\n|\r/u', $text) ?: [];
    foreach ($lines as $line) {
        $parsed = project_parse_text_line($line);
        if ($parsed === null) {
            continue;
        }

        $taskName = project_clean_name((string)($parsed['task_name'] ?? ''));
        if ($taskName === '') {
            continue;
        }

        $lowerName = function_exists('mb_strtolower') ? mb_strtolower($taskName, 'UTF-8') : strtolower($taskName);
        if (in_array($lowerName, ['nom de la tache', 'task name'], true)) {
            continue;
        }

        $rows[] = [
            'task_name' => $taskName,
            'start_date' => project_parse_date_value($parsed['start_date'] ?? null),
            'end_date' => project_parse_date_value($parsed['end_date'] ?? null),
            'duration_label' => trim((string)($parsed['duration_label'] ?? '')),
            'predecessors_label' => trim((string)($parsed['predecessors_label'] ?? '')),
        ];
    }

    return $rows;
}

function project_parse_rows_from_spreadsheet(string $filePath): array
{
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = [];
    $headerMap = null;

    foreach ($sheet->toArray(null, true, true, false) as $row) {
        $values = array_map(static function ($value) {
            return is_string($value) ? trim($value) : $value;
        }, $row);

        $nonEmptyValues = array_values(array_filter($values, static function ($value) {
            return $value !== null && $value !== '';
        }));

        if (empty($nonEmptyValues)) {
            continue;
        }

        if ($headerMap === null) {
            $normalizedHeaders = array_map(static function ($value) {
                $text = is_string($value) ? trim($value) : '';
                $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
                return preg_replace('/\s+/u', ' ', $text) ?? $text;
            }, $values);

            if (in_array('nom de la tache', $normalizedHeaders, true) || in_array('task name', $normalizedHeaders, true)) {
                $headerMap = [
                    'task_name' => array_search(in_array('nom de la tache', $normalizedHeaders, true) ? 'nom de la tache' : 'task name', $normalizedHeaders, true),
                    'start_date' => array_search(in_array('debut', $normalizedHeaders, true) ? 'debut' : 'start', $normalizedHeaders, true),
                    'end_date' => array_search(in_array('fin', $normalizedHeaders, true) ? 'fin' : 'finish', $normalizedHeaders, true),
                    'duration_label' => array_search(in_array('duree', $normalizedHeaders, true) ? 'duree' : 'duration', $normalizedHeaders, true),
                    'predecessors_label' => array_search(in_array('predecesseurs', $normalizedHeaders, true) ? 'predecesseurs' : 'predecessors', $normalizedHeaders, true),
                ];
                continue;
            }
        }

        if ($headerMap !== null) {
            $taskName = project_clean_name((string)($values[(int)$headerMap['task_name']] ?? ''));
            if ($taskName === '') {
                continue;
            }

            $rows[] = [
                'task_name' => $taskName,
                'start_date' => project_parse_date_value($values[(int)$headerMap['start_date']] ?? null),
                'end_date' => project_parse_date_value($values[(int)$headerMap['end_date']] ?? null),
                'duration_label' => trim((string)($values[(int)$headerMap['duration_label']] ?? '')),
                'predecessors_label' => trim((string)($values[(int)$headerMap['predecessors_label']] ?? '')),
            ];
            continue;
        }

        $taskName = project_clean_name((string)($values[0] ?? ''));
        if ($taskName === '') {
            continue;
        }

        $rows[] = [
            'task_name' => $taskName,
            'start_date' => project_parse_date_value($values[1] ?? null),
            'end_date' => project_parse_date_value($values[2] ?? null),
            'duration_label' => trim((string)($values[3] ?? '')),
            'predecessors_label' => trim((string)($values[4] ?? '')),
        ];
    }

    return $rows;
}

function project_is_uppercase_heading(string $name): bool
{
    $lettersOnly = preg_replace('/[^\p{L}]+/u', '', $name) ?? $name;
    if ($lettersOnly === '') {
        return false;
    }

    $upper = function_exists('mb_strtoupper') ? mb_strtoupper($lettersOnly, 'UTF-8') : strtoupper($lettersOnly);
    return $lettersOnly === $upper;
}

function project_build_tasks(array $rows): array
{
    $normalized = [];
    foreach ($rows as $index => $row) {
        $normalized[] = [
            'sort_index' => $index + 1,
            'task_uid' => 'project_task_' . ($index + 1),
            'task_name' => project_clean_name((string)($row['task_name'] ?? '')),
            'start_date' => $row['start_date'] ?? null,
            'end_date' => $row['end_date'] ?? null,
            'duration_label' => trim((string)($row['duration_label'] ?? '')),
            'predecessors_label' => trim((string)($row['predecessors_label'] ?? '')),
            'is_summary' => false,
            'outline_level' => 1,
            'parent_uid' => null,
            'group_label' => '',
        ];
    }

    $rowCount = count($normalized);
    for ($index = 0; $index < $rowCount; $index++) {
        $current = $normalized[$index];
        $next = $normalized[$index + 1] ?? null;

        $currentStart = $current['start_date'] ? strtotime((string)$current['start_date']) : false;
        $currentEnd = $current['end_date'] ? strtotime((string)$current['end_date']) : false;
        $nextStart = $next && !empty($next['start_date']) ? strtotime((string)$next['start_date']) : false;
        $nextEnd = $next && !empty($next['end_date']) ? strtotime((string)$next['end_date']) : false;

        $containsNext = $next !== null
            && $currentStart !== false
            && $currentEnd !== false
            && $nextStart !== false
            && $nextEnd !== false
            && $nextStart >= $currentStart
            && $nextEnd <= $currentEnd
            && ($currentStart !== $nextStart || $currentEnd !== $nextEnd || project_is_uppercase_heading((string)$current['task_name']));

        $normalized[$index]['is_summary'] = $containsNext || project_is_uppercase_heading((string)$current['task_name']);
    }

    $stack = [];
    foreach ($normalized as $index => $task) {
        $taskStart = $task['start_date'] ? strtotime((string)$task['start_date']) : false;
        $taskEnd = $task['end_date'] ? strtotime((string)$task['end_date']) : false;

        while (!empty($stack)) {
            $parent = $stack[count($stack) - 1];
            $parentStart = $parent['start_date'] ? strtotime((string)$parent['start_date']) : false;
            $parentEnd = $parent['end_date'] ? strtotime((string)$parent['end_date']) : false;
            $fitsParent = $taskStart !== false
                && $taskEnd !== false
                && $parentStart !== false
                && $parentEnd !== false
                && $taskStart >= $parentStart
                && $taskEnd <= $parentEnd
                && $task['sort_index'] > $parent['sort_index'];

            if ($fitsParent) {
                break;
            }

            array_pop($stack);
        }

        $normalized[$index]['outline_level'] = count($stack) + 1;
        $normalized[$index]['parent_uid'] = !empty($stack) ? (string)$stack[count($stack) - 1]['task_uid'] : null;

        $groupLabel = '';
        if (!empty($stack)) {
            $groupIndex = count($stack) >= 2 ? 1 : 0;
            $groupLabel = (string)($stack[$groupIndex]['task_name'] ?? '');
        }
        if ($groupLabel === '') {
            $groupLabel = (string)$task['task_name'];
        }
        $normalized[$index]['group_label'] = $groupLabel;

        if (!empty($task['is_summary'])) {
            $stack[] = [
                'sort_index' => $task['sort_index'],
                'task_uid' => $task['task_uid'],
                'task_name' => $task['task_name'],
                'start_date' => $task['start_date'],
                'end_date' => $task['end_date'],
            ];
        }
    }

    return $normalized;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    AuditTrail::log('project_planning_import_failed', [
        'module' => 'decaissement',
        'action' => 'import_project_planning',
        'entity_type' => 'project_planning_import',
        'entity_label' => 'import_project_planning',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_method'],
    ]);
    echo 'POST uniquement';
    exit;
}

$chantierId = (int)($_POST['chantier_id'] ?? 0);
if ($chantierId <= 0) {
    AuditTrail::log('project_planning_import_failed', [
        'module' => 'decaissement',
        'action' => 'import_project_planning',
        'entity_type' => 'project_planning_import',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_chantier_id', 'chantier_id' => $chantierId],
    ]);
    project_set_flash('error', 'Chantier invalide pour l import du planning.');
    project_redirect_to_chantier(max(0, $chantierId));
}

try {
    $sourceLabel = trim((string)($_POST['source_label'] ?? ''));
    $projectText = trim((string)($_POST['project_text'] ?? ''));
    $uploadedFile = $_FILES['project_file'] ?? null;

    $rows = [];
    $sourceType = 'text';
    $resolvedSourceLabel = $sourceLabel !== '' ? $sourceLabel : 'Import Project';
    $rawPayload = '';

    if ($projectText !== '') {
        $rows = project_parse_rows_from_text($projectText);
        $rawPayload = $projectText;
    } elseif (is_array($uploadedFile) && (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $originalName = (string)($uploadedFile['name'] ?? 'planning_project');
        $resolvedSourceLabel = $sourceLabel !== '' ? $sourceLabel : $originalName;
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $tmpPath = (string)($uploadedFile['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Fichier d import introuvable.');
        }

        if (in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            $rows = project_parse_rows_from_spreadsheet($tmpPath);
            $sourceType = $extension;
            $rawPayload = 'FILE:' . $originalName;
        } else {
            $content = file_get_contents($tmpPath);
            if ($content === false) {
                throw new RuntimeException('Impossible de lire le fichier d import.');
            }

            if ($extension === 'mpp') {
                $binaryProbe = substr($content, 0, 2048);
                if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $binaryProbe)) {
                    throw new RuntimeException('Le binaire .mpp natif n est pas lisible directement dans cette stack PHP. Exporte le planning depuis Microsoft Project en Excel/CSV/TXT ou colle la vue tableau dans la zone de texte.');
                }
            }

            $text = mb_convert_encoding($content, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
            $rows = project_parse_rows_from_text($text);
            $sourceType = $extension !== '' ? $extension : 'text';
            $rawPayload = $text;
        }
    } else {
        throw new RuntimeException('Ajoute un fichier Project/Excel/CSV/TXT ou colle le tableau du planning.');
    }

    if (empty($rows)) {
        throw new RuntimeException('Aucune tache n a pu etre detectee dans le contenu fourni.');
    }

    $tasks = project_build_tasks($rows);

    $userName = trim((string)($_SESSION['user_name'] ?? $_SESSION['username'] ?? ($_SESSION['prenom'] ?? '')));
    $nom = trim((string)($_SESSION['nom'] ?? ''));
    if ($userName === '' && $nom !== '') {
        $userName = $nom;
    }
    if ($userName === '') {
        $userName = 'SYSTEM';
    }

    $pdo = Database::getConnection();
    $model = new ProjectPlanningImport($pdo);
    $saved = $model->replaceImportForChantier($chantierId, [
        'source_label' => $resolvedSourceLabel,
        'source_type' => $sourceType,
        'imported_by' => $userName,
        'raw_payload' => $rawPayload,
    ], $tasks);

    AuditTrail::log('project_planning_imported', [
        'module' => 'decaissement',
        'action' => 'import_project_planning',
        'entity_type' => 'project_planning_import',
        'entity_id' => (string)$chantierId,
        'entity_label' => $resolvedSourceLabel,
        'user_name' => $userName,
        'details' => [
            'source_type' => $sourceType,
            'task_count' => (int)($saved['task_count'] ?? count($tasks)),
            'row_count' => count($rows),
        ],
    ], $pdo);

    project_set_flash('success', 'Planning Project importe en parallele avec ' . number_format((int)($saved['task_count'] ?? count($tasks)), 0, ',', ' ') . ' tache(s).');
} catch (Throwable $e) {
    AuditTrail::log('project_planning_import_failed', [
        'module' => 'decaissement',
        'action' => 'import_project_planning',
        'entity_type' => 'project_planning_import',
        'entity_id' => $chantierId > 0 ? (string)$chantierId : null,
        'entity_label' => isset($resolvedSourceLabel) ? $resolvedSourceLabel : 'import_project_planning',
        'user_name' => isset($userName) ? $userName : (string)($_SESSION['user_name'] ?? $_SESSION['username'] ?? 'SYSTEM'),
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    project_set_flash('error', $e->getMessage());
}

project_redirect_to_chantier($chantierId);
