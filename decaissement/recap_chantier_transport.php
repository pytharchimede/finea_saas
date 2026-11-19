<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/AuditTrail.php';

AuditTrail::logPageConsultation([
    'module' => 'decaissement',
    'action' => 'recap_chantier_transport_view',
    'entity_type' => 'transport_recap',
    'entity_label' => 'Recap chantier transport',
    'page_name' => 'recap_chantier_transport.php',
    'details' => [
        'chantier_code' => $_GET['chantier_code'] ?? null,
        'view_mode' => 'dashboard',
    ],
]);
?>
<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Récapitulatif Transport Chantier</title>
    <link href="plugins/css/bootstrap/bootstrap.min.css" rel="stylesheet" />
    <link href="plugins/css/fontawesome/all.min.css" rel="stylesheet" />
    <style>
        .header-card {
            background: linear-gradient(135deg, #1565c0, #1e88e5);
            color: #fff;
            border-radius: 12px;
            padding: 18px;
        }

        .info-card {
            background: #fff;
            border-radius: 10px;
            padding: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
        }

        .table thead th {
            position: sticky;
            top: 0;
            background: #f8f9fa;
            z-index: 2;
        }
    </style>
    <script src="plugins/js/jquery/jquery-3.6.0.min.js"></script>
    <script src="plugins/js/bootstrap/bootstrap.min.js"></script>
</head>

<body>
    <div class="container my-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <a class="btn btn-outline-secondary" href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Retour</a>
            <h2 class="mb-0">Récapitulatif Transport Chantier</h2>
            <div></div>
        </div>

        <div class="header-card mb-3">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label mb-1">Code Chantier</label>
                    <input id="chantierCode" class="form-control" value="CH060" placeholder="Ex: CH060" />
                </div>
                <div class="col-md-3">
                    <button id="btnLoad" class="btn btn-light w-100"><i class="fas fa-sync"></i> Charger</button>
                </div>
                <div class="col-md-5 text-end">
                    <button id="btnExportCsv" class="btn btn-outline-light"><i class="fas fa-file-csv"></i> Export CSV</button>
                    <a id="btnOpenStatsApi" class="btn btn-outline-light" target="_blank"><i class="fas fa-external-link-alt"></i> API</a>
                </div>
            </div>
        </div>

        <div id="statsContainer" class="info-card mb-3" style="display:none;"></div>

        <div class="info-card">
            <div class="table-responsive" style="max-height:65vh;">
                <table class="table table-sm table-bordered align-middle" id="tableVoyages">
                    <thead>
                        <tr>
                            <th style="width:80px;">Voyage</th>
                            <th>N° Fiche</th>
                            <th>Chauffeur</th>
                            <th>Contact</th>
                            <th>Matricule</th>
                            <th class="text-end">Volume (m³)</th>
                            <th class="text-end">Tonnage (t)</th>
                            <th>Date</th>
                            <th>Voir</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot></tfoot>
                </table>
            </div>
        </div>
    </div>

    <script>
        const $tBody = $('#tableVoyages tbody');
        const $tFoot = $('#tableVoyages tfoot');
        const $stats = $('#statsContainer');

        function numberFr(n) {
            return Number(n || 0).toLocaleString('fr-FR');
        }

        function renderStats(d) {
            const tgt = d.voyages_target;
            const tot = Number(d.voyages_total || 0);
            const vol = Number(d.volume_total_m3 || 0);
            const ton = Number(d.tonnage_total_t || 0);
            const pct = (tgt ? Math.min(100, Math.round((tot / tgt) * 100)) : null);
            const remain = (tgt ? Math.max(0, tgt - tot) : null);
            const bar = (pct !== null ? `<div class="progress" style="height:10px;">
          <div class="progress-bar bg-success" role="progressbar" style="width:${pct}%;" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="small text-muted">${tot} / ${tgt} (${pct}%)${remain!==null?`, reste ${remain}`:''}</div>` : '');
            $stats.html(`
        <div class="row g-3 align-items-center">
          <div class="col-md-4">
            <div class="text-muted small">Chantier</div>
            <div class="h5 mb-0">${d.chantier_code}</div>
          </div>
          <div class="col-md-4">
            <div class="text-muted small">Objectif voyages</div>
            <div class="h5 mb-1">${tgt||'—'}</div>
            ${bar}
          </div>
          <div class="col-md-4 text-md-end">
            <div class="text-muted small">Total</div>
            <div class="h5 mb-0">${numberFr(ton)} t <span class="text-muted">(≈ ${numberFr(vol)} m³)</span></div>
          </div>
        </div>`).show();
        }

        function renderTable(items) {
            $tBody.empty();
            let sumV = 0,
                sumT = 0;
            (items || []).forEach(row => {
                sumV += Number(row.volume_m3 || 0);
                sumT += Number(row.tonnage_t || 0);
                const tr = `
          <tr>
            <td class="text-center"><span class="badge bg-dark">${row.voyage_index}</span></td>
            <td>${row.num_fiche||''}</td>
            <td>${row.chauffeur_nom||''}</td>
            <td>${row.chauffeur_tel||''}</td>
            <td>${row.vehicule_matricule||''}</td>
            <td class="text-end">${numberFr(row.volume_m3)}</td>
            <td class="text-end">${numberFr(row.tonnage_t)}</td>
            <td>${new Date(row.created_at).toLocaleString('fr-FR')}</td>
            <td class="text-center"><a class="btn btn-outline-primary btn-sm" href="voir_fiche_details.php?num_fiche=${encodeURIComponent(row.num_fiche)}" target="_blank"><i class="fas fa-external-link-alt"></i></a></td>
          </tr>`;
                $tBody.append(tr);
            });
            $tFoot.html(`<tr class="table-light">
        <th colspan="5" class="text-end">Totaux</th>
        <th class="text-end">${numberFr(sumV)} m³</th>
        <th class="text-end">${numberFr(sumT)} t</th>
        <th colspan="2"></th>
      </tr>`);
        }

        function exportCSV(data) {
            const items = data.items || [];
            const rows = [];
            rows.push(['Chantier', data.chantier_code || '', 'Objectif', data.voyages_target || '', 'Voyages', data.voyages_total || ''].join(';'));
            rows.push(['Volume total (m3)', data.volume_total_m3 || 0, 'Tonnage total (t)', data.tonnage_total_t || 0].join(';'));
            rows.push([]);
            rows.push(['Voyage', 'Num fiche', 'Chauffeur', 'Téléphone', 'Matricule', 'Volume (m3)', 'Tonnage (t)', 'Date'].join(';'));
            items.forEach(r => {
                rows.push([
                    r.voyage_index,
                    r.num_fiche,
                    r.chauffeur_nom || '',
                    r.chauffeur_tel || '',
                    r.vehicule_matricule || '',
                    r.volume_m3 || 0,
                    r.tonnage_t || 0,
                    new Date(r.created_at).toLocaleString('fr-FR')
                ].join(';'))
            });
            const blob = new Blob([rows.join('\n')], {
                type: 'text/csv;charset=utf-8;'
            });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `recap_transport_${(data.chantier_code||'chantier')}.csv`;
            document.body.appendChild(a);
            a.click();
            a.remove();
        }

        function load() {
            const code = ($('#chantierCode').val() || '').trim();
            if (!code) {
                alert('Veuillez saisir un code chantier');
                return;
            }
            const url = `request/get_chantier_transport_stats.php?chantier_code=${encodeURIComponent(code)}`;
            $('#btnOpenStatsApi').attr('href', url);
            $tBody.html('<tr><td colspan="9" class="text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Chargement…</td></tr>');
            $.get(url).done(d => {
                if (!d || d.success !== true) {
                    $tBody.html(`<tr><td colspan="9" class="text-center text-danger">${(d && d.message)||'Erreur de chargement'}</td></tr>`);
                    return;
                }
                renderStats(d);
                renderTable(d.items || []);
                $('#btnExportCsv').off('click').on('click', () => exportCSV(d));
            }).fail(() => {
                $tBody.html('<tr><td colspan="9" class="text-center text-danger">Erreur réseau</td></tr>');
            });
        }

        $('#btnLoad').on('click', load);
        $(document).ready(load);
    </script>
</body>

</html>