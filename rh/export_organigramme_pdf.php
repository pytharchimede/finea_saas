<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/RhOrganizationChartLayoutManager.php';
require_once __DIR__ . '/../services/RhOrganizationChartService.php';

final class RhOrganizationChartPdf extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}

$pdo = \Database::getConnection();
$snapshot = (new \RhOrganizationChartService($pdo))->getSnapshot();
$savedLayout = (new \RhOrganizationChartLayoutManager($pdo))->get();

function orgPdfH($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function orgPdfInitials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'RH';
}

function orgPdfNodeCard(array $node, string $label, string $accent): string
{
    $title = trim((string) ($node['title'] ?? '')) ?: 'Non defini';
    $subtitle = trim((string) ($node['subtitle'] ?? ''));
    $meta = trim((string) ($node['meta'] ?? ''));
    $initials = trim((string) ($node['initials'] ?? '')) ?: orgPdfInitials($title);

    return '<table cellpadding="6" style="border:1px solid ' . $accent . ';background-color:#ffffff;">
        <tr>
            <td width="46" style="background-color:' . $accent . ';color:#ffffff;text-align:center;font-size:10px;font-weight:bold;">'
                . orgPdfH($initials) . '</td>
            <td>
                <div style="font-size:7px;font-weight:bold;color:' . $accent . ';">' . orgPdfH($label) . '</div>
                <div style="font-size:10px;font-weight:bold;color:#0f172a;">' . orgPdfH($title) . '</div>'
                . ($subtitle !== '' ? '<div style="font-size:8px;color:#475569;">' . orgPdfH($subtitle) . '</div>' : '')
                . ($meta !== '' ? '<div style="font-size:7px;color:#64748b;">' . orgPdfH($meta) . '</div>' : '') . '
            </td>
        </tr>
    </table>';
}

function orgPdfCompactUnitCard(array $node, string $label, string $accent): string
{
    return '<table cellpadding="4" style="border:1px solid ' . $accent . ';background-color:#ffffff;">
        <tr><td style="font-size:6px;font-weight:bold;color:' . $accent . ';text-align:center;">' . orgPdfH($label) . '</td></tr>
        <tr><td style="font-size:8px;font-weight:bold;color:#0f172a;text-align:center;">' . orgPdfH($node['title'] ?? 'Unite') . '</td></tr>
        <tr><td style="font-size:6px;color:#64748b;text-align:center;">' . orgPdfH($node['meta'] ?? '') . '</td></tr>
    </table>';
}

function orgPdfConnector(): string
{
    return '<div style="text-align:center;color:#64748b;font-size:11px;line-height:10px;">|<br>v</div>';
}

function orgPdfEmployeeGrid(array $employees): string
{
    if ($employees === []) {
        return '<div style="border:1px solid #cbd5e1;background-color:#f8fafc;color:#64748b;padding:10px;text-align:center;">
            Aucun collaborateur actif rattache.
        </div>';
    }

    $rows = '';
    foreach (array_chunk($employees, 4) as $employeeRow) {
        while (count($employeeRow) < 4) {
            $employeeRow[] = null;
        }

        $rows .= '<tr>';
        foreach ($employeeRow as $employee) {
            if ($employee === null) {
                $rows .= '<td width="25%"></td>';
                continue;
            }

            $title = trim((string) ($employee['title'] ?? 'Collaborateur'));
            $role = trim((string) ($employee['subtitle'] ?? ''));
            $meta = trim((string) ($employee['meta'] ?? ''));
            $initials = trim((string) ($employee['initials'] ?? '')) ?: orgPdfInitials($title);
            $rows .= '<td width="25%" style="padding:4px;">
                <table cellpadding="5" style="border:1px solid #cbd5e1;background-color:#ffffff;">
                    <tr>
                        <td width="38" style="background-color:#e2e8f0;color:#334155;text-align:center;font-size:8px;font-weight:bold;">'
                            . orgPdfH($initials) . '</td>
                        <td>
                            <div style="font-size:8px;font-weight:bold;color:#0f172a;">' . orgPdfH($title) . '</div>'
                            . ($role !== '' ? '<div style="font-size:7px;color:#475569;">' . orgPdfH($role) . '</div>' : '')
                            . ($meta !== '' ? '<div style="font-size:6px;color:#64748b;">' . orgPdfH($meta) . '</div>' : '') . '
                        </td>
                    </tr>
                </table>
            </td>';
        }
        $rows .= '</tr>';
    }

    return '<table cellpadding="2">' . $rows . '</table>';
}

function orgPdfPageHeader(string $title, string $subtitle): string
{
    return '<div style="background-color:#0f172a;color:#ffffff;padding:11px;">
        <div style="font-size:7px;color:#a7f3d0;font-weight:bold;">FINEA - RESSOURCES HUMAINES</div>
        <div style="font-size:17px;font-weight:bold;">' . orgPdfH($title) . '</div>
        <div style="font-size:8px;color:#cbd5e1;">' . orgPdfH($subtitle) . '</div>
    </div>';
}

function orgPdfSortNodesByLayout(array $nodes, array $layoutNodes): array
{
    $indexed = [];
    foreach ($nodes as $index => $node) {
        $indexed[] = ['index' => $index, 'node' => $node];
    }

    usort($indexed, static function (array $left, array $right) use ($layoutNodes): int {
        $leftPosition = $layoutNodes[$left['node']['id'] ?? ''] ?? null;
        $rightPosition = $layoutNodes[$right['node']['id'] ?? ''] ?? null;
        if (!$leftPosition && !$rightPosition) {
            return $left['index'] <=> $right['index'];
        }
        if (!$leftPosition) {
            return 1;
        }
        if (!$rightPosition) {
            return -1;
        }
        $rowComparison = ((float) ($leftPosition['y'] ?? 0)) <=> ((float) ($rightPosition['y'] ?? 0));
        return $rowComparison !== 0
            ? $rowComparison
            : ((float) ($leftPosition['x'] ?? 0)) <=> ((float) ($rightPosition['x'] ?? 0));
    });

    return array_column($indexed, 'node');
}

\AuditTrail::logFileEvent('export', [
    'module' => 'rh',
    'action' => 'rh_organization_chart_export_pdf',
    'entity_type' => 'rh_organization_chart',
    'entity_label' => 'Organigramme general BANAMUR',
    'format' => 'pdf',
    'scope' => 'active_personnel',
    'details' => [
        'personnel_count' => (int) ($snapshot['stats']['personnel'] ?? 0),
        'department_count' => (int) ($snapshot['stats']['departments'] ?? 0),
        'service_count' => (int) ($snapshot['stats']['services'] ?? 0),
    ],
], $pdo);

$pdf = new RhOrganizationChartPdf('L', 'mm', 'A3', true, 'UTF-8', false);
$pdf->SetCreator('FINEA');
$pdf->SetAuthor('BANAMUR INDUSTRIES ET TECHNOLOGIES');
$pdf->SetTitle('Organigramme general BANAMUR');
$pdf->SetSubject('Arbre hierarchique complet de l entreprise');
$pdf->companyPdfTitle = 'Organigramme general BANAMUR';
$pdf->companyPdfType = 'Organisation RH';
$pdf->companyPdfId = 'RH-ORG-' . date('Ymd-His');
$pdf->companyPdfCode = 'RH-ORG-01';
$pdf->companyPdfQrPayload = $pdf->companyPdfId;
$pdf->SetMargins(10, 38, 10);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(8);
$pdf->SetAutoPageBreak(true, 25);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);

$tree = $snapshot['tree'] ?? [];
$dgNode = $tree['children'][0] ?? [
    'type' => 'dg-empty',
    'title' => 'Directeur general',
    'subtitle' => 'Non defini dans le parametrage RH',
    'meta' => '',
    'initials' => 'DG',
    'children' => [],
];
$layoutNodes = $savedLayout['nodes'] ?? [];
$units = orgPdfSortNodesByLayout($dgNode['children'] ?? [], $layoutNodes);

$pdf->AddPage();
$overviewRows = '<tr>';
$unitWidth = $units !== [] ? (100 / count($units)) : 100;
foreach ($units as $unit) {
    $isDirection = ($unit['type'] ?? '') === 'direction';
    $overviewRows .= '<td width="' . number_format($unitWidth, 3, '.', '') . '%" style="padding:2px;">'
        . orgPdfCompactUnitCard($unit, $isDirection ? 'DIRECTION' : 'SERVICE', $isDirection ? '#d49f00' : '#171717')
        . '</td>';
}
$overviewRows .= '</tr>';

$companyNode = [
    'title' => $snapshot['company_name'] ?? 'BANAMUR',
    'subtitle' => 'Entreprise',
    'meta' => 'Structure organisationnelle generale',
    'initials' => 'B',
];

$coverHtml = '
<style>
    body { font-family: dejavusans; color:#0f172a; }
    .kpi { border:1px solid #cbd5e1; background-color:#f8fafc; padding:7px; }
    .kpi-value { font-size:15px; font-weight:bold; color:#0f172a; }
    .kpi-label { font-size:7px; color:#64748b; }
</style>'
    . orgPdfPageHeader(
        'Organigramme general BANAMUR',
        'Structure hierarchique des collaborateurs en poste - Edite le ' . date('d/m/Y H:i')
    ) . '
<table cellpadding="5">
    <tr>
        <td class="kpi"><span class="kpi-label">COLLABORATEURS</span><br><span class="kpi-value">'
            . (int) ($snapshot['stats']['personnel'] ?? 0) . '</span></td>
        <td class="kpi"><span class="kpi-label">DIRECTIONS</span><br><span class="kpi-value">'
            . (int) ($snapshot['stats']['departments'] ?? 0) . '</span></td>
        <td class="kpi"><span class="kpi-label">SERVICES</span><br><span class="kpi-value">'
            . (int) ($snapshot['stats']['services'] ?? 0) . '</span></td>
        <td class="kpi"><span class="kpi-label">SERVICES CONFIGURES</span><br><span class="kpi-value">'
            . (int) ($snapshot['stats']['configured_services'] ?? 0) . '</span></td>
    </tr>
</table>
<br>
<table align="center" width="34%"><tr><td>' . orgPdfNodeCard($companyNode, 'ENTREPRISE', '#0f172a') . '</td></tr></table>'
    . orgPdfConnector() . '
<table align="center" width="38%"><tr><td>'
    . orgPdfNodeCard($dgNode, 'DIRECTION GENERALE', ($dgNode['type'] ?? '') === 'dg-empty' ? '#d97706' : '#7c3aed')
    . '</td></tr></table>'
    . orgPdfConnector() . '
<div style="border-top:2px solid #94a3b8;height:4px;"></div>
<table cellpadding="2">' . $overviewRows . '</table>
<br>
<div style="font-size:8px;color:#64748b;text-align:center;">
    Les directions et services sont places au meme niveau sous la Direction generale.
    Les pages suivantes developpent chaque branche de l arbre.
</div>';

$pdf->writeHTML($coverHtml, true, false, true, false, '');

foreach ($units as $unit) {
    $isDirection = ($unit['type'] ?? '') === 'direction';
    $unitAccent = $isDirection ? '#d49f00' : '#171717';
    $unitLabel = $isDirection ? 'DIRECTION' : 'SERVICE';
    $unitTitle = trim((string) ($unit['title'] ?? 'Unite'));

    $children = $unit['children'] ?? [];
    $manager = null;
    $employees = [];
    if (!$isDirection) {
        if (count($children) === 1 && ($children[0]['type'] ?? '') === 'manager') {
            $manager = $children[0];
            $employees = orgPdfSortNodesByLayout($manager['children'] ?? [], $layoutNodes);
        } else {
            $employees = orgPdfSortNodesByLayout(array_values(array_filter(
                $children,
                static fn(array $node): bool => ($node['type'] ?? '') === 'employee'
            )), $layoutNodes);
        }
    }

    $pdf->AddPage();
    $html = '<style>body { font-family: dejavusans; color:#0f172a; }</style>'
        . orgPdfPageHeader(
            $unitTitle,
            $isDirection
                ? 'Branche hierarchique de direction'
                : count($employees) . ' collaborateur(s) rattache(s)'
        ) . '
        <br>
        <table align="center" width="28%"><tr><td>'
            . orgPdfNodeCard($companyNode, 'ENTREPRISE', '#0f172a')
            . '</td></tr></table>'
        . orgPdfConnector() . '
        <table align="center" width="32%"><tr><td>'
            . orgPdfNodeCard($dgNode, 'DIRECTION GENERALE', ($dgNode['type'] ?? '') === 'dg-empty' ? '#d97706' : '#7c3aed')
            . '</td></tr></table>'
        . orgPdfConnector() . '
        <table align="center" width="38%"><tr><td>'
            . orgPdfNodeCard($unit, $unitLabel, $unitAccent)
            . '</td></tr></table>';

    if (!$isDirection) {
        if ($manager !== null) {
            $html .= orgPdfConnector()
                . '<table align="center" width="34%"><tr><td>'
                . orgPdfNodeCard($manager, 'RESPONSABLE DU SERVICE', '#0d9488')
                . '</td></tr></table>';
        }

        if ($employees !== []) {
            $html .= orgPdfConnector()
                . '<div style="border-top:2px solid #94a3b8;height:4px;"></div>
                <div style="font-size:7px;color:#64748b;text-align:center;font-weight:bold;">COLLABORATEURS</div>'
                . orgPdfEmployeeGrid($employees);
        } else {
            $html .= orgPdfConnector() . orgPdfEmployeeGrid([]);
        }
    } else {
        $html .= '<br><div style="border:1px solid #fabd02;background-color:#fff8d6;color:#000000;padding:9px;text-align:center;font-size:8px;">
            Cette direction est rattachee directement a la Direction generale.
        </div>';
    }

    $pdf->writeHTML($html, true, false, true, false, '');
}

$pdf->Output('organigramme_banamur_' . date('Ymd_His') . '.pdf', 'I');
exit;
