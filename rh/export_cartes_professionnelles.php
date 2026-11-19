<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';
require_once __DIR__ . '/includes/personnel_helpers.php';
require_once __DIR__ . '/includes/professional_card_pdf.php';

$directory = (new \RhPersonnelController(\Database::getConnection()))->model();
$photoMode = (string) ($_GET['photo_mode'] ?? 'auto');
if (!in_array($photoMode, ['auto', 'without'], true)) $photoMode = 'auto';
$ids = isset($_GET['personnel_id']) ? [(int) $_GET['personnel_id']] : [];
foreach (explode(',', (string) ($_GET['ids'] ?? '')) as $id) if ((int) $id > 0) $ids[] = (int) $id;
$ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));

if ($ids !== []) {
    $employees = [];
    foreach ($ids as $id) { $employee = $directory->getPersonnelDetail($id); if ($employee !== null) $employees[] = $employee; }
} else {
    $filters = [
        'q' => trim((string) ($_GET['q'] ?? '')), 'service_id' => trim((string) ($_GET['service_id'] ?? '')),
        'fonction_id' => trim((string) ($_GET['fonction_id'] ?? '')), 'status_code' => trim((string) ($_GET['status_code'] ?? '')),
        'gender_code' => trim((string) ($_GET['gender_code'] ?? '')), 'site_id' => trim((string) ($_GET['site_id'] ?? '')),
        'scope' => in_array((string) ($_GET['scope'] ?? 'all'), ['active', 'inactive', 'all'], true) ? (string) ($_GET['scope'] ?? 'all') : 'all',
        'search_mode' => in_array((string) ($_GET['search_mode'] ?? 'include'), ['include', 'exclude'], true) ? (string) ($_GET['search_mode'] ?? 'include') : 'include',
    ];
    $employees = $directory->getPersonnelRowsForExport($filters, 10000);
}
if ($employees === []) { http_response_code(404); exit('Aucun collaborateur à exporter.'); }

$projectRoot = dirname(__DIR__);
foreach ($employees as &$employee) {
    $resolved = rhResolveMediaUrl($employee['photo'] ?? null);
    $employee['_photo_path'] = $resolved !== null && str_starts_with($resolved, '../') ? $projectRoot . '/' . substr($resolved, 3) : '';
}
unset($employee);
$pdf = new RhProfessionalCardPdf($projectRoot . '/logo/default_logo.jpg', 'https://personnel.fidest.ci/ceo/', ($_GET['cut_marks'] ?? '1') !== '0');
$pdf->render($employees, $photoMode);
$pdf->Output('cartes_professionnelles_banamur_' . date('Ymd_His') . '.pdf', ($_GET['download'] ?? '0') === '1' ? 'D' : 'I');
exit;
