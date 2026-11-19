<?php
require_once __DIR__ . '/../services/AoReportBuilder.php';
$cache = new ReflectionProperty(CompanyBranding::class, 'cache');
$previous = $cache->getValue();
$cache->setValue(null, CompanyBranding::defaults());
$path = tempnam(sys_get_temp_dir(), 'ao-report-test-');
if ($path === false) throw new RuntimeException('Fichier temporaire impossible.');
try {
    $rows = [];
    for ($i = 0; $i < 35; $i++) $rows[] = ['numero_ao' => 'AO-' . $i, 'titre' => $i === 0 ? '=1+1' : 'Travaux de construction et réhabilitation · dossier ' . $i,
        'autorite_contractante' => 'Autorité de test', 'type_marche' => 'Travaux', 'date_publication' => '2026-10-01', 'date_limite' => '2026-10-30',
        'score_pertinence' => 75, 'statut_interne' => 'nouveau', 'source' => 'Contenu local DGH', 'url_source' => 'https://example.test/#/publish/details/' . $i];
    $filters = ['q' => '', 'status' => '', 'min_score' => '60', 'deadline_scope' => 'active'];
    $level = ob_get_level();
    $pdf = AoReportBuilder::build($rows, 'pdf', $filters);
    if (!str_starts_with($pdf, '%PDF-')) throw new RuntimeException('PDF invalide');
    $xlsx = AoReportBuilder::build($rows, 'xlsx', $filters);
    if (!str_starts_with($xlsx, 'PK')) throw new RuntimeException('XLSX invalide après PDF dans le même processus');
    file_put_contents($path, $xlsx);
    $reader = new PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $book = $reader->load($path);
    $sheet = $book->getActiveSheet();
    if ($sheet->getHighestRow() !== 46) throw new RuntimeException('Nombre de lignes Excel incorrect');
    if ($sheet->getCell('B9')->getValue() !== '=1+1' || $sheet->getCell('B9')->getDataType() !== 's') throw new RuntimeException('Texte externe interprété comme formule');
    $book->disconnectWorksheets();
    if (!str_starts_with(AoReportBuilder::build([], 'pdf', $filters), '%PDF-')) throw new RuntimeException('PDF vide invalide');
    file_put_contents($path, AoReportBuilder::build([], 'xlsx', $filters));
    $book = $reader->load($path);
    if (!str_contains($book->getActiveSheet()->getCell('A9')->getValue(), 'Aucun')) throw new RuntimeException('Message de sélection vide absent');
    $book->disconnectWorksheets();
    if (ob_get_level() !== $level) throw new RuntimeException('Le générateur a modifié les buffers de sortie');
    echo "7 vérifications des rapports PDF et Excel réussies.\n";
} finally {
    unlink($path);
    $cache->setValue(null, $previous);
}
