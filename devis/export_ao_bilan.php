<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess('veille_ao_diffusion.php');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/AoDigestRepository.php';
require_once __DIR__ . '/../services/AoReportBuilder.php';
$repository = new AoDigestRepository(Database::getConnection());
$settings = $repository->settings();
$rows = $repository->opportunities((int) $settings['min_score']);
$format = ($_GET['format'] ?? '') === 'xlsx' ? 'xlsx' : 'pdf';
$filters = ['q' => '', 'status' => '', 'min_score' => (string) $settings['min_score'], 'deadline_scope' => 'active'];
$bytes = AoReportBuilder::build($rows, $format, $filters, 'FINEA · Aperçu du bilan collectif', (int) ($_SESSION['user_id'] ?? 0));
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: ' . ($format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf'));
header('Content-Disposition: attachment; filename="bilan_appels_offres_' . date('Y-m-d') . '.' . $format . '"');
echo $bytes;
