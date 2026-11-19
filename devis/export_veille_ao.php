<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess('export_veille_ao.php');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../repositories/AoOpportunityRepository.php';
require_once __DIR__ . '/../services/AoReportBuilder.php';
$pdo = Database::getConnection();
$filters = ['q' => trim((string) ($_GET['q'] ?? '')), 'status' => trim((string) ($_GET['status'] ?? '')),
    'min_score' => trim((string) ($_GET['min_score'] ?? '')), 'deadline_scope' => trim((string) ($_GET['deadline_scope'] ?? 'active'))];
$rows = (new AoOpportunityRepository($pdo))->search($filters, 1000);
$format = in_array(strtolower((string) ($_GET['format'] ?? 'excel')), ['excel', 'xlsx'], true) ? 'xlsx' : 'pdf';
$author = trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''))) ?: 'Utilisateur FINEA';
$bytes = AoReportBuilder::build($rows, $format, $filters, $author, (int) ($_SESSION['user_id'] ?? 0));
AuditTrail::logFileEvent('export', ['module' => 'devis', 'entity_type' => 'ao_opportunity',
    'entity_label' => 'Récapitulatif veille appels d’offres', 'format' => $format, 'scope' => 'ao_search',
    'details' => ['row_count' => count($rows), 'filters' => $filters]], $pdo);
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . ($format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf'));
header('Content-Disposition: attachment; filename="recap_veille_ao_' . date('Ymd_His') . '.' . $format . '"');
header('Cache-Control: max-age=0');
echo $bytes;
