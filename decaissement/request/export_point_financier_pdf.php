<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/point_financier_pdf_report.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

try {
    $pdo = \Database::getConnection();
    $report = pf_generate_point_financier_pdf_document($pdo, $_GET['etat_demande'] ?? null, [
        'beneficiaire' => $_GET['beneficiaire'] ?? '',
        'exact_date' => $_GET['exact_date'] ?? null,
        'from' => $_GET['from'] ?? null,
        'to' => $_GET['to'] ?? null,
        'chantier' => $_GET['chantier'] ?? null,
        'debourse' => $_GET['debourse'] ?? null,
        'num_fiche' => $_GET['num_fiche'] ?? null,
        'detail_fiche' => $_GET['detail_fiche'] ?? null,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur génération PDF';
    exit;
}

\AuditTrail::logFileEvent('export', [
    'module' => 'decaissement',
    'action' => 'point_financier_export',
    'entity_type' => 'point_financier',
    'entity_label' => (string)($report['statusMeta']['label'] ?? 'Point financier'),
    'format' => 'pdf',
    'details' => [
        'row_count' => $report['rowCount'] ?? null,
        'beneficiaire' => $report['filters']['beneficiaire'] ?? null,
        'chantier' => $report['filters']['chantier'] ?? null,
        'debourse' => $report['filters']['debourse'] ?? null,
        'num_fiche' => $report['filters']['num_fiche'] ?? null,
        'detail_fiche' => $report['filters']['detail_fiche'] ?? null,
        'total' => $report['total'] ?? null,
    ],
], $pdo);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode((string)$report['fileName']) . '"; filename*=UTF-8\'\'' . rawurlencode((string)$report['fileName']));
header('Content-Length: ' . strlen((string)$report['content']));
echo $report['content'];
exit;
