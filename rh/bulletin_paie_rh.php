<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';

$pdo = \Database::getConnection();
$manager = (new \RhPayrollController($pdo))->service();

$recordId = (int) ($_GET['id'] ?? 0);
$record = $manager->getPayrollRecord($recordId);

function bulletinMoney($value): string
{
    return number_format((float) $value, 0, ',', ' ');
}

function bulletinPeriodLabel(string $period): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m', $period);
    if (!$date) {
        return $period;
    }

    $months = [1 => 'JANVIER', 'FEVRIER', 'MARS', 'AVRIL', 'MAI', 'JUIN', 'JUILLET', 'AOUT', 'SEPTEMBRE', 'OCTOBRE', 'NOVEMBRE', 'DECEMBRE'];
    return ($months[(int) $date->format('n')] ?? $period) . ' ' . $date->format('Y');
}

if ($record === null) {
    http_response_code(404);
    echo 'Bulletin introuvable.';
    exit();
}

$payload = $record['payload'] ?? [];
$employee = $payload['employee'] ?? [];
$calculation = $payload['calculation'] ?? [];
$attendance = $payload['attendance_summary'] ?? [];
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulletin de paie</title>
    <style>
        :root {
            color-scheme: light;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #eef2ff;
            color: #0f172a;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        .page {
            max-width: 1120px;
            margin: 24px auto;
            background: #ffffff;
            box-shadow: 0 18px 60px rgba(15, 23, 42, 0.14);
            border-radius: 28px;
            overflow: hidden;
        }

        .header {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 24px;
            padding: 32px 36px 24px;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 45%, #7c2d12 100%);
            color: #ffffff;
        }

        .badge {
            display: inline-flex;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.24em;
            text-transform: uppercase;
        }

        .header h1 {
            margin: 14px 0 10px;
            font-size: 38px;
            line-height: 1.05;
        }

        .header p {
            margin: 0;
            color: rgba(255, 255, 255, 0.82);
            line-height: 1.6;
            font-size: 14px;
        }

        .company-card,
        .identity-card,
        .summary-card,
        .totals-card {
            border: 1px solid #e2e8f0;
            border-radius: 22px;
            background: #ffffff;
        }

        .main {
            padding: 28px 36px 36px;
        }

        .identity-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .identity-card {
            padding: 18px;
            background: #f8fafc;
        }

        .identity-card .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            margin-bottom: 8px;
        }

        .identity-card .value {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1.4fr 0.6fr;
            gap: 24px;
            align-items: start;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 14px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 13px;
        }

        thead th {
            background: #f8fafc;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            font-size: 11px;
            text-align: left;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .amount {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .summary-card,
        .totals-card {
            padding: 22px;
        }

        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.22em;
            color: #be123c;
            margin-bottom: 14px;
        }

        .summary-row,
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        .summary-row:last-child,
        .total-row:last-child {
            border-bottom: none;
        }

        .summary-row strong,
        .total-row strong {
            font-size: 18px;
        }

        .net-pay {
            background: linear-gradient(135deg, #111827 0%, #7c2d12 100%);
            color: #ffffff;
            border: none;
        }

        .net-pay .section-title,
        .net-pay .summary-row,
        .net-pay .total-row {
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.18);
        }

        .footer-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 0 36px 24px;
        }

        .action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 18px;
            border-radius: 16px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #0f172a;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        @media print {
            body {
                background: #ffffff;
            }

            .page {
                margin: 0;
                max-width: none;
                border-radius: 0;
                box-shadow: none;
            }

            .footer-actions {
                display: none;
            }
        }

        @media (max-width: 960px) {

            .header,
            .content-grid,
            .identity-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <header class="header">
            <div>
                <span class="badge">Bulletin de paie</span>
                <h1>BANAMUR</h1>
                <p>Bulletin de paie professionnel genere depuis le module RH. Le document reprend les gains, retenues et charges employeur de la periode selectionnee.</p>
            </div>
            <div class="company-card" style="padding: 22px; color: #0f172a;">
                <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 0.18em; color: #64748b;">Periode de paie</div>
                <div style="margin-top: 10px; font-size: 30px; font-weight: 800;"><?= htmlspecialchars(bulletinPeriodLabel((string) $record['payroll_period'])) ?></div>
                <div style="margin-top: 18px; display: grid; gap: 10px; font-size: 14px;">
                    <div><strong>Reference:</strong> PAY-<?= (int) $record['id'] ?></div>
                    <div><strong>Date edition:</strong> <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $record['updated_at']))) ?></div>
                    <div><strong>Devise:</strong> <?= htmlspecialchars((string) $record['currency_code']) ?></div>
                </div>
            </div>
        </header>

        <main class="main">
            <section class="identity-grid">
                <div class="identity-card">
                    <div class="label">Nom du salarie</div>
                    <div class="value"><?= htmlspecialchars((string) ($employee['full_name'] ?? '')) ?></div>
                </div>
                <div class="identity-card">
                    <div class="label">Matricule</div>
                    <div class="value"><?= htmlspecialchars((string) ($employee['matricule'] ?? '')) ?></div>
                </div>
                <div class="identity-card">
                    <div class="label">Fonction</div>
                    <div class="value"><?= htmlspecialchars((string) ($employee['fonction_label'] ?? '')) ?></div>
                </div>
                <div class="identity-card">
                    <div class="label">Service</div>
                    <div class="value"><?= htmlspecialchars((string) ($employee['service_label'] ?? '')) ?></div>
                </div>
                <div class="identity-card">
                    <div class="label">Contrat</div>
                    <div class="value"><?= htmlspecialchars((string) ($calculation['contract_label'] ?? $record['contract_code'])) ?></div>
                </div>
                <div class="identity-card">
                    <div class="label">Categorie</div>
                    <div class="value"><?= htmlspecialchars((string) ($record['category_code'] ?? '')) ?></div>
                </div>
                <div class="identity-card">
                    <div class="label">CNPS</div>
                    <div class="value"><?= htmlspecialchars((string) ($employee['cnps_number'] ?? 'Non renseigne')) ?></div>
                </div>
                <div class="identity-card">
                    <div class="label">Jours / HS</div>
                    <div class="value"><?= htmlspecialchars(number_format((float) ($attendance['worked_days'] ?? $record['attendance_days']), 2, ',', ' ')) ?> j / <?= htmlspecialchars(number_format((float) ($attendance['overtime_hours'] ?? 0), 2, ',', ' ')) ?></div>
                </div>
            </section>

            <section class="content-grid">
                <div class="totals-card">
                    <div class="section-title">Detail de la paie</div>
                    <table>
                        <thead>
                            <tr>
                                <th>Designation</th>
                                <th class="amount">Nombre</th>
                                <th class="amount">Base</th>
                                <th class="amount">Taux</th>
                                <th class="amount">Gain</th>
                                <th class="amount">Retenue sal.</th>
                                <th class="amount">Charge pat.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($calculation['lines'] ?? []) as $line): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $line['designation']) ?></td>
                                    <td class="amount"><?= $line['number'] === null ? ' - ' : htmlspecialchars(number_format((float) $line['number'], 2, ',', ' ')) ?></td>
                                    <td class="amount"><?= htmlspecialchars(bulletinMoney($line['base'] ?? 0)) ?></td>
                                    <td class="amount"><?= $line['rate'] === null ? ' - ' : htmlspecialchars(number_format((float) $line['rate'], 2, ',', ' ')) . ' %' ?></td>
                                    <td class="amount"><?= htmlspecialchars(bulletinMoney($line['gain'] ?? 0)) ?></td>
                                    <td class="amount"><?= htmlspecialchars(bulletinMoney($line['employee_deduction'] ?? 0)) ?></td>
                                    <td class="amount"><?= htmlspecialchars(bulletinMoney($line['employer_contribution'] ?? 0)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="display: grid; gap: 24px;">
                    <section class="summary-card">
                        <div class="section-title">Synthese</div>
                        <div class="summary-row">
                            <span>Brut partiel</span>
                            <strong><?= htmlspecialchars(bulletinMoney($calculation['gross_partial'] ?? 0)) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Brut imposable</span>
                            <strong><?= htmlspecialchars(bulletinMoney($record['gross_taxable'])) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>SBI</span>
                            <strong><?= htmlspecialchars(bulletinMoney($calculation['sbi'] ?? $record['gross_taxable'])) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>SBS</span>
                            <strong><?= htmlspecialchars(bulletinMoney($calculation['sbs'] ?? $record['gross_taxable'])) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Base imposable</span>
                            <strong><?= htmlspecialchars(bulletinMoney($calculation['base_imposable'] ?? 0)) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Impot brut / RICF</span>
                            <strong><?= htmlspecialchars(bulletinMoney($calculation['impot_brut'] ?? 0)) ?> / <?= htmlspecialchars(bulletinMoney($calculation['ricf'] ?? 0)) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Retenues salariales</span>
                            <strong><?= htmlspecialchars(bulletinMoney($record['total_employee_deductions'])) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Charges employeur</span>
                            <strong><?= htmlspecialchars(bulletinMoney($record['total_employer_contributions'])) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Impact pointage</span>
                            <strong><?= htmlspecialchars(bulletinMoney($calculation['attendance_deduction'] ?? 0)) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Valorisation HS</span>
                            <strong><?= htmlspecialchars(bulletinMoney($calculation['overtime_amount'] ?? 0)) ?></strong>
                        </div>
                    </section>

                    <section class="summary-card net-pay">
                        <div class="section-title">Net a payer</div>
                        <div class="total-row">
                            <span>Montant final</span>
                            <strong><?= htmlspecialchars(bulletinMoney($record['net_salary'])) ?> <?= htmlspecialchars((string) $record['currency_code']) ?></strong>
                        </div>
                        <div class="total-row">
                            <span>IGR retenue</span>
                            <strong><?= htmlspecialchars(bulletinMoney($calculation['igr'] ?? 0)) ?></strong>
                        </div>
                        <div class="total-row">
                            <span>Prime transport</span>
                            <strong><?= htmlspecialchars(bulletinMoney($calculation['transport_bonus'] ?? 0)) ?></strong>
                        </div>
                    </section>

                    <section class="summary-card">
                        <div class="section-title">Observations</div>
                        <div style="font-size: 14px; line-height: 1.7; color: #334155; min-height: 72px;">
                            <?= nl2br(htmlspecialchars((string) ($record['observations'] ?? 'Aucune observation.'))) ?>
                        </div>
                    </section>

                    <section class="summary-card">
                        <div class="section-title">Lecture du pointage</div>
                        <div class="summary-row">
                            <span>Jours payes</span>
                            <strong><?= htmlspecialchars(number_format((float) ($calculation['attendance_days'] ?? ($attendance['worked_days'] ?? 0)), 2, ',', ' ')) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Jours non payes</span>
                            <strong><?= htmlspecialchars(number_format((float) ($calculation['attendance_breakdown']['unpaid_days'] ?? 0), 2, ',', ' ')) ?></strong>
                        </div>
                        <div class="summary-row">
                            <span>Heures supplementaires</span>
                            <strong><?= htmlspecialchars(number_format((float) ($calculation['overtime_hours'] ?? ($attendance['overtime_hours'] ?? 0)), 2, ',', ' ')) ?> h</strong>
                        </div>
                    </section>
                </div>
            </section>
        </main>

        <div class="footer-actions">
            <a href="export_bulletins_paie_pdf.php?period=<?= urlencode((string) $record['payroll_period']) ?>&personnel_id=<?= (int) $record['personnel_id'] ?>" class="action">Télécharger le PDF</a>
            <a href="paie_rh.php?personnel_id=<?= (int) $record['personnel_id'] ?>&period=<?= htmlspecialchars((string) $record['payroll_period']) ?>" class="action">Retour paie</a>
            <button type="button" class="action" onclick="window.print()">Imprimer</button>
        </div>
    </div>
</body>

</html>
